<?php
/**
 * A fake Minecraft server for the check_minecraft() tests in
 * run_pipeline_tests.php: it answers a Server List Ping with the framing the
 * real one uses (length, packet id 0, length-prefixed JSON).
 *
 * Usage:  php fixtures/mc_slp_server.php full|hidden|garbage
 * It listens on a free loopback port, prints that port on stdout and serves
 * connections one after another until the test terminates it (or nobody
 * connects for 30 s).
 *
 * full answers with counts, hidden with no "players" at all (what a proxy that
 * hides them may send), garbage with text that is not JSON.
 */
$mode = $argv[1] ?? 'full';
$bodies = [
    'full' => json_encode(['version' => ['name' => 'Paper 1.0'], 'players' => ['online' => 3, 'max' => 32, 'sample' => [['name' => 'Anna']]], 'description' => 'Test']),
    'hidden' => json_encode(['version' => ['name' => 'Paper 1.0'], 'description' => 'Test']),
    'garbage' => 'this is not json',
];
$varint = function (int $value): string {
    $out = '';
    do {
        $byte = $value & 0x7F;
        $value >>= 7;
        $out .= chr($value > 0 ? $byte | 0x80 : $byte);
    } while ($value > 0);
    return $out;
};
$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}
echo (int)substr(strrchr((string)stream_socket_get_name($server, false), ':'), 1), "\n";
while ($client = @stream_socket_accept($server, 30)) {
    stream_set_timeout($client, 2);
    fread($client, 512);
    $body = $bodies[$mode];
    $payload = $varint(0) . $varint(strlen($body)) . $body;
    fwrite($client, $varint(strlen($payload)) . $payload);
    fclose($client);
}
