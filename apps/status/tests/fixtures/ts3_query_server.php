<?php
/**
 * A fake TeamSpeak 3 ServerQuery for the check_teamspeak() tests in
 * run_pipeline_tests.php.
 *
 * Usage:  php fixtures/ts3_query_server.php [foreign]
 * It listens on a free loopback port, prints that port on stdout and serves
 * connections one after another until the test terminates it (or nobody
 * connects for 30 s).
 *
 * "foreign" plays a different service behind the port instead: its banner
 * says "Welcome", so it passes the greeting check, but it does not speak
 * ServerQuery - every line gets "500 Unknown command" and no answer ever ends
 * with "error id=". In both modes, when a connection ends it prints the
 * commands it received on one line, comma-separated.
 *
 * The framing is the real one, because the defect it guards was in reading it:
 * every line ends with "\n\r", a command answers with its data line (if any)
 * and then a separate "error id=N msg=..." line, and use/login/logout answer
 * with the error line only. Four virtual servers: 9987 is the normal one;
 * 9988 is stopped and use port= answers it with 1033 "server is not
 * running", the id TeamSpeak's error table has for that; 9991 is listed
 * offline too, but use port= answers it with 1024 "invalid serverID", like a
 * port with no virtual server (which of the two a real server gives for a
 * stopped one picked by port is not verified here); 9989 has so many
 * channels that channellist outgrows the check's 64 KB read cap. use
 * port=9990 is refused for a missing permission, any other port gets 1024.
 * The query login is serveradmin / example-password; the password
 * slow-password gets no answer at all. All names are made up.
 *
 * No functions on purpose: find_dead_code.php does not read this directory.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$listen = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$listen) {
    fwrite(STDERR, "ts3_query_server: $errstr ($errno)\n");
    exit(1);
}
$name = (string)stream_socket_get_name($listen, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");
$foreign = ($argv[1] ?? '') === 'foreign';

$ok = "error id=0 msg=ok\n\r";
$no_server = "error id=1024 msg=invalid\\sserverID\n\r";
$no_permission = "error id=2568 msg=insufficient\\sclient\\spermissions failed_permid=4\n\r";
$not_running = "error id=1033 msg=server\\sis\\snot\\srunning\n\r";
$vservers = [
    9987 => ['id' => 1, 'name' => 'Example\\sServer', 'status' => 'online', 'online' => 4, 'max' => 32],
    9988 => ['id' => 2, 'name' => 'Stopped\\sServer', 'status' => 'offline', 'online' => null, 'max' => 16],
    9989 => ['id' => 3, 'name' => 'Large\\sServer', 'status' => 'online', 'online' => 2, 'max' => 512],
    9991 => ['id' => 4, 'name' => 'Unselectable\\sServer', 'status' => 'offline', 'online' => null, 'max' => 8],
];
$counts = fn (array $vs): string => $vs['online'] === null ? ''
    : ' virtualserver_clientsonline=' . $vs['online'] . ' virtualserver_queryclientsonline=1';
$serverlist = implode('|', array_map(fn (int $port, array $vs): string => 'virtualserver_id=' . $vs['id']
    . " virtualserver_port=$port virtualserver_status=" . $vs['status'] . $counts($vs)
    . ' virtualserver_maxclients=' . $vs['max'] . ' virtualserver_name=' . $vs['name'], array_keys($vservers), $vservers)) . "\n\r";
$serverinfo = fn (array $vs): string => 'virtualserver_unique_identifier=ZXhhbXBsZQ== virtualserver_name=' . $vs['name']
    . ' virtualserver_platform=Linux virtualserver_version=3.13.7\\s[Build:\\s1655727713]'
    . ' virtualserver_maxclients=' . $vs['max'] . $counts($vs) . ' virtualserver_status=' . $vs['status'] . "\n\r";
$channellist = 'cid=1 pid=0 channel_order=0 channel_name=Lobby total_clients=3'
    . '|cid=2 pid=0 channel_order=1 channel_name=Games total_clients=1'
    . '|cid=3 pid=0 channel_order=2 channel_name=AFK total_clients=0' . "\n\r";
// About 80 KB on one line, followed by its error line.
$large_channellist = implode('|', array_map(fn (int $cid): string => "cid=$cid pid=0 channel_order=$cid"
    . " channel_name=Channel\\s$cid total_clients=0", range(1, 1200))) . "\n\r";
// Three voice clients in channels 1 and 2, the query client itself in channel 1.
$clients = [
    ['clid=1 cid=1 client_database_id=2 client_nickname=Alice client_type=0', 'client_away=0 client_flag_talking=1 client_input_muted=0 client_output_muted=0 client_is_recording=0'],
    ['clid=2 cid=1 client_database_id=3 client_nickname=Bob client_type=0', 'client_away=1 client_flag_talking=0 client_input_muted=0 client_output_muted=1 client_is_recording=0'],
    ['clid=3 cid=2 client_database_id=4 client_nickname=Carol client_type=0', 'client_away=0 client_flag_talking=0 client_input_muted=1 client_output_muted=0 client_is_recording=1'],
    ['clid=4 cid=1 client_database_id=1 client_nickname=serveradmin client_type=1', 'client_away=0 client_flag_talking=0 client_input_muted=0 client_output_muted=0 client_is_recording=0'],
];
$servergrouplist = 'sgid=6 name=Server\\sAdmin type=1|sgid=7 name=Normal type=1|sgid=8 name=Guest type=1' . "\n\r";

while ($conn = @stream_socket_accept($listen, 30)) {
    stream_set_timeout($conn, 10);
    if ($foreign) {
        fwrite($conn, "220---------- Welcome to Example FTP ----------\r\n220-This is a private system.\r\n"
            . "220 You will be disconnected after 15 minutes of inactivity.\r\n");
        $seen = [];
        while (($line = fgets($conn)) !== false) {
            $seen[] = trim($line);
            if (trim($line) === 'quit') {
                break;
            }
            @fwrite($conn, "500 Unknown command\r\n");
        }
        fwrite(STDOUT, implode(',', $seen) . "\n");
        @fclose($conn);
        continue;
    }
    fwrite($conn, "TS3\n\rWelcome to the TeamSpeak 3 ServerQuery interface, type \"help\" for a list of commands"
        . " and \"help <command>\" for information on a specific command.\n\r");
    $selected = null;
    $logged_in = false;
    $seen = [];
    while (($line = fgets($conn)) !== false) {
        $cmd = trim($line);
        $seen[] = $cmd;
        if ($cmd === 'quit') {
            break;
        }
        if (preg_match('/^use port=(\d+)$/', $cmd, $m)) {
            $selected = ($vservers[(int)$m[1]]['status'] ?? '') === 'online' ? (int)$m[1] : null;
            $reply = $selected !== null ? $ok : match ((int)$m[1]) {
                9988 => $not_running,
                9990 => $no_permission,
                default => $no_server,
            };
        } elseif ($cmd === 'serverlist') {
            $reply = $serverlist . $ok;
        } elseif ($cmd === 'login client_login_name=serveradmin client_login_password=slow-password') {
            continue;
        } elseif (str_starts_with($cmd, 'login ')) {
            $logged_in = ($cmd === 'login client_login_name=serveradmin client_login_password=example-password');
            $reply = $logged_in ? $ok : "error id=520 msg=invalid\\sloginname\\sor\\spassword\n\r";
        } elseif ($cmd === 'logout') {
            $logged_in = false;
            $reply = $ok;
        } elseif (!in_array($cmd, ['serverinfo', 'channellist', 'clientlist', 'clientlist -voice -away', 'servergrouplist'], true)) {
            $reply = "error id=256 msg=command\\snot\\sfound\n\r";
        } elseif ($selected === null) {
            $reply = $no_server;
        } elseif ($cmd === 'serverinfo') {
            $reply = $serverinfo($vservers[$selected]) . $ok;
        } elseif ($cmd === 'channellist') {
            $reply = ($selected === 9989 ? $large_channellist : $channellist) . $ok;
        } elseif ($cmd === 'servergrouplist') {
            $reply = $logged_in ? $servergrouplist . $ok : $no_permission;
        } else {
            $flags = $cmd === 'clientlist -voice -away';
            $reply = implode('|', array_map(fn($c) => $flags ? $c[0] . ' ' . $c[1] : $c[0], $clients)) . "\n\r" . $ok;
        }
        @fwrite($conn, $reply);
    }
    fwrite(STDOUT, implode(',', $seen) . "\n");
    @fclose($conn);
}
