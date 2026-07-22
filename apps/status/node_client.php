<?php
/**
 * Blood Kings Status Monitoring - Independent Monitoring Node
 * 
 * Tento skript funguje jako samostatný monitorovací uzel.
 * Nahrajte ho na jakýkoliv jiný hosting a nastavte spouštění přes Cron.
 * Skript si stáhne seznam serverů z hlavní status stránky, otestuje je z této lokace
 * a pošle výsledky zpět přes API.
 */

// --- KONFIGURACE UZLU ---
$central_api_url = 'https://bloodkings.eu/status/node_api.php';
$node_key        = 'BloodKingsNodeDefaultKey123!'; // Musí se shodovat s cron_key v nastavení hlavního statusu
$node_location   = 'AUTO';                         // Nastavte na název lokace (např. 'Praha, CZ') nebo 'AUTO' pro autodetekci s vlaječkou
$timeout_seconds = 5;                              // Výchozí timeout pro testy (sekundy)

// --- KONEC KONFIGURACE ---

$is_cli = (php_sapi_name() === 'cli');
// Chyby na obrazovku jen z CLI (ruční ladění) - tenhle skript se dá spustit i
// přes web (viz větev níže s <pre>) a display_errors=1 by tam komukoliv, kdo
// trefí URL, ukázal PHP warningy/cesty na disku.
ini_set('display_errors', $is_cli ? 1 : 0);
error_reporting(E_ALL);

if (!$is_cli) {
    echo "<pre>";
}

echo "=== Blood Kings Status - Spouštím monitorovací uzel [$node_location] ===\n\n";

// 1. Stažení seznamu monitorů z centrálního serveru
echo "Stahuji seznam monitorů z hlavní status stránky...\n";
$get_url = $central_api_url . '?action=get_monitors&key=' . urlencode($node_key);

$monitors_response = http_request($get_url);
if (!$monitors_response) {
    exit("CHYBA: Nepodařilo se kontaktovat hlavní status API. Zkontrolujte URL a připojení k internetu.\n");
}

$monitors_data = json_decode($monitors_response, true);
if (!$monitors_data || isset($monitors_data['error'])) {
    $err = isset($monitors_data['error']) ? $monitors_data['error'] : 'Neplatná odpověď API';
    exit("CHYBA: " . $err . "\n");
}

$monitors = isset($monitors_data['monitors']) ? $monitors_data['monitors'] : [];
echo "Načteno " . count($monitors) . " monitorů k otestování.\n\n";

$results = [];

// 2. Provedení kontrol
foreach ($monitors as $m) {
    $mid = $m['id'];
    $name = $m['name'];
    $type = $m['type'];
    $target = $m['target'];
    $port = $m['port'];
    
    echo "Testuji [$type] $name ($target)... ";
    
    $check_result = [
        'id' => $mid,
        'status' => 'down',
        'response_time' => 0,
        'error' => null,
        'details' => null
    ];
    
    switch ($type) {
        case 'web':
            $res = check_http($target, $timeout_seconds);
            $check_result['status'] = $res['status'];
            $check_result['response_time'] = $res['response_time'];
            $check_result['error'] = $res['error'];
            break;
            
        case 'ping':
        case 'port':
            $res = check_socket($target, $port ?: 80, $timeout_seconds);
            $check_result['status'] = $res['status'];
            $check_result['response_time'] = $res['response_time'];
            $check_result['error'] = $res['error'];
            break;
            
        case 'minecraft':
            $res = check_minecraft($target, $port ?: 25565, $timeout_seconds);
            $check_result['status'] = $res['status'];
            $check_result['response_time'] = $res['response_time'];
            $check_result['error'] = $res['error'];
            if ($res['status'] === 'up') {
                $check_result['details'] = [
                    'players_online' => $res['players_online'],
                    'players_max' => $res['players_max'],
                    'version' => $res['version'],
                    'players_list' => $res['players_list'],
                    'motd' => $res['motd']
                ];
            }
            break;
            
        case 'teamspeak':
            $res = check_teamspeak($target, $port ?: 10011, $timeout_seconds);
            $check_result['status'] = $res['status'];
            $check_result['response_time'] = $res['response_time'];
            $check_result['error'] = $res['error'];
            if ($res['status'] === 'up') {
                $check_result['details'] = [
                    'clients_online' => $res['clients_online'],
                    'clients_max' => $res['clients_max'],
                    'name' => $res['name'],
                    'version' => $res['version']
                ];
            }
            break;
            
        case 'discord':
            $res = check_discord($target, $timeout_seconds);
            $check_result['status'] = $res['status'];
            $check_result['response_time'] = $res['response_time'];
            $check_result['error'] = $res['error'];
            if ($res['status'] === 'up') {
                $check_result['details'] = [
                    'presence_count' => $res['presence_count'],
                    'name' => $res['name'],
                    'instant_invite' => $res['instant_invite'],
                    'voice_channels' => $res['voice_channels'],
                    'members' => $res['members']
                ];
            }
            break;
            
        case 'vps':
            // VPS je kontrolován lokálním python agentem, tento uzel ho přeskočí
            echo "Přeskočeno (VPS)\n";
            continue 2;
    }
    
    echo "Stav: " . strtoupper($check_result['status']) . " (" . $check_result['response_time'] . " ms)\n";
    if ($check_result['error']) {
        echo "   Chyba: " . $check_result['error'] . "\n";
    }
    
    $results[] = $check_result;
}

echo "\nZpracováno " . count($results) . " výsledků.\n";

// 3. Odeslání výsledků zpět na hlavní server
echo "Odesílám výsledky na hlavní server...\n";
$post_url = $central_api_url . '?action=post_results&key=' . urlencode($node_key);
$payload = json_encode([
    'location' => $node_location,
    'results' => $results
], JSON_UNESCAPED_UNICODE);

$post_response = http_request($post_url, 'POST', $payload);
if ($post_response) {
    $post_data = json_decode($post_response, true);
    if (isset($post_data['status']) && $post_data['status'] === 'success') {
        echo "OK: Výsledky byly úspěšně uloženy do databáze.\n";
    } else {
        echo "CHYBA: Centrální server vrátil chybu: " . (isset($post_data['error']) ? $post_data['error'] : 'Neznámá chyba') . "\n";
    }
} else {
    echo "CHYBA: Nepodařilo se poslat výsledky zpět na hlavní server.\n";
}

echo "\nUzel úspěšně dokončil práci.\n";

if (!$is_cli) {
    echo "</pre>";
}

// --- POMOCNÉ DOTAZOVACÍ FUNKCE ---

function http_request($url, $method = 'GET', $post_data = null) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    } else {
        $opts = [
            'http' => [
                'method'  => $method,
                'timeout' => 15,
                'header'  => "User-Agent: BloodKingsNodeClient/1.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        if ($method === 'POST') {
            $opts['http']['header'] .= "Content-Type: application/json\r\n";
            $opts['http']['content'] = $post_data;
        }
        $context = stream_context_create($opts);
        return @file_get_contents($url, false, $context);
    }
}

function check_http($url, $timeout) {
    $start = microtime(true);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        $duration = round((microtime(true) - $start) * 1000);
        if ($response === false) {
            return ['status' => 'down', 'response_time' => 0, 'error' => "cURL chyba: " . $error];
        }
        if ($http_code >= 200 && $http_code < 400) {
            return ['status' => 'up', 'response_time' => $duration, 'error' => null];
        } else {
            return ['status' => 'down', 'response_time' => $duration, 'error' => "HTTP kód: " . $http_code];
        }
    } else {
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        $duration = round((microtime(true) - $start) * 1000);
        if ($response === false) {
            return ['status' => 'down', 'response_time' => 0, 'error' => "Nelze načíst URL"];
        }
        $http_code = 200;
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $http_response_header[0], $matches);
            if (isset($matches[1])) $http_code = (int)$matches[1];
        }
        if ($http_code >= 200 && $http_code < 400) {
            return ['status' => 'up', 'response_time' => $duration, 'error' => null];
        } else {
            return ['status' => 'down', 'response_time' => $duration, 'error' => "HTTP kód: " . $http_code];
        }
    }
}

function check_socket($host, $port, $timeout) {
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $duration = round((microtime(true) - $start) * 1000);
    if ($socket) {
        @fclose($socket);
        return ['status' => 'up', 'response_time' => $duration, 'error' => null];
    } else {
        return ['status' => 'down', 'response_time' => 0, 'error' => "Zavřeno: $errstr ($errno)"];
    }
}

function check_minecraft($host, $port, $timeout) {
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['status' => 'down', 'response_time' => 0, 'error' => $errstr, 'players_online' => 0, 'players_max' => 0];
    }
    stream_set_timeout($socket, $timeout);
    $packVarInt = function($value) {
        $string = '';
        do {
            $byte = $value & 0x7F; $value >>= 7;
            if ($value > 0) $byte |= 0x80;
            $string .= chr($byte);
        } while ($value > 0);
        return $string;
    };
    $handshakePayload = $packVarInt(47) . $packVarInt(strlen($host)) . $host . pack('n', $port) . $packVarInt(1);
    $handshakePacket = $packVarInt(strlen($handshakePayload) + 1) . $packVarInt(0x00) . $handshakePayload;
    $requestPacket = $packVarInt(1) . $packVarInt(0x00);
    @fwrite($socket, $handshakePacket);
    @fwrite($socket, $requestPacket);
    $readVarInt = function($socket) {
        $value = 0; $i = 0;
        do {
            $byte = @fread($socket, 1);
            if ($byte === false || strlen($byte) === 0) return false;
            $byteVal = ord($byte);
            $value |= ($byteVal & 0x7F) << ($i * 7); $i++;
            if ($i > 5) return false;
        } while (($byteVal & 0x80) != 0);
        return $value;
    };
    $packetLength = $readVarInt($socket);
    if ($packetLength === false) { @fclose($socket); return ['status' => 'down', 'response_time' => 0, 'error' => 'No response', 'players_online' => 0, 'players_max' => 0]; }
    $packetId = $readVarInt($socket);
    $stringLength = $readVarInt($socket);
    if ($stringLength === false || $stringLength <= 0) { @fclose($socket); return ['status' => 'down', 'response_time' => 0, 'error' => 'Invalid response', 'players_online' => 0, 'players_max' => 0]; }
    $jsonData = '';
    $bytesRemaining = $stringLength;
    while ($bytesRemaining > 0 && !feof($socket)) {
        $chunk = @fread($socket, min($bytesRemaining, 4096));
        if ($chunk === false) break;
        $jsonData .= $chunk;
        $bytesRemaining -= strlen($chunk);
    }
    @fclose($socket);
    $data = json_decode($jsonData, true);
    $duration = round((microtime(true) - $start) * 1000);
    if (!$data) return ['status' => 'up', 'response_time' => $duration, 'error' => 'JSON error', 'players_online' => 0, 'players_max' => 0];
    $playersOnline = isset($data['players']['online']) ? (int)$data['players']['online'] : 0;
    $playersMax = isset($data['players']['max']) ? (int)$data['players']['max'] : 0;
    $version = isset($data['version']['name']) ? $data['version']['name'] : 'Neznámá';
    $playersList = [];
    if (isset($data['players']['sample']) && is_array($data['players']['sample'])) {
        foreach ($data['players']['sample'] as $p) {
            if (isset($p['name'])) $playersList[] = $p['name'];
        }
    }
    $motd = '';
    if (isset($data['description'])) {
        if (is_string($data['description'])) $motd = $data['description'];
        elseif (isset($data['description']['text'])) $motd = $data['description']['text'];
        elseif (isset($data['description']['extra']) && is_array($data['description']['extra'])) {
            foreach ($data['description']['extra'] as $el) { if (isset($el['text'])) $motd .= $el['text']; }
        }
        $motd = preg_replace('/§[0-9a-fk-orx]/i', '', $motd);
        $motd = trim($motd);
    }
    return [
        'status' => 'up', 'response_time' => $duration, 'error' => null,
        'players_online' => $playersOnline, 'players_max' => $playersMax,
        'version' => $version, 'players_list' => $playersList, 'motd' => $motd
    ];
}

function check_teamspeak($host, $port, $timeout) {
    $voice_port = 9987;
    $parts = explode(':', $host);
    if (count($parts) === 2) { $host = $parts[0]; $voice_port = intval($parts[1]); }
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) return ['status' => 'down', 'response_time' => 0, 'error' => "Port $port closed"];
    stream_set_timeout($socket, $timeout);
    $greeting = '';
    for ($i = 0; $i < 4; $i++) {
        $line = @fgets($socket, 128); if ($line === false) break;
        $greeting .= $line;
        if (strpos($line, 'TS3') !== false || strpos($line, 'Welcome') !== false) break;
    }
    if (strpos($greeting, 'TS3') === false && strpos($greeting, 'Welcome') === false) {
        @fclose($socket); return ['status' => 'up', 'response_time' => round((microtime(true) - $start) * 1000), 'error' => 'No TS3 greeting'];
    }
    @fwrite($socket, "use port=$voice_port\n"); @fgets($socket, 256);
    @fwrite($socket, "serverinfo\n"); $info = @fgets($socket, 4096);
    @fwrite($socket, "quit\n"); @fclose($socket);
    $duration = round((microtime(true) - $start) * 1000);
    if ($info && strpos($info, 'virtualserver_clientsonline') !== false) {
        $parts = explode(' ', $info); $details = [];
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) == 2) $details[$kv[0]] = str_replace(['\s', '\/', '\p'], [' ', '/', '|'], $kv[1]);
        }
        $clients_online = isset($details['virtualserver_clientsonline']) ? (int)$details['virtualserver_clientsonline'] : 0;
        $query_clients = isset($details['virtualserver_queryclientsonline']) ? (int)$details['virtualserver_queryclientsonline'] : 0;
        $clients_max = isset($details['virtualserver_maxclients']) ? (int)$details['virtualserver_maxclients'] : 0;
        return [
            'status' => 'up', 'response_time' => $duration, 'error' => null,
            'clients_online' => max(0, $clients_online - $query_clients),
            'clients_max' => $clients_max,
            'name' => $details['virtualserver_name'] ?? 'TeamSpeak Server',
            'version' => $details['virtualserver_version'] ?? ''
        ];
    }
    return ['status' => 'up', 'response_time' => $duration, 'error' => 'Failed to parse info'];
}

function check_discord($guild_id, $timeout) {
    $start = microtime(true);
    $url = "https://discord.com/api/guilds/" . urlencode($guild_id) . "/widget.json";
    if (function_exists('curl_init')) {
        $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } else {
        $context = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n", 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context); $http_code = 200;
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $http_response_header[0], $matches);
            if (isset($matches[1])) $http_code = (int)$matches[1];
        }
    }
    $duration = round((microtime(true) - $start) * 1000);
    if ($http_code !== 200 || !$response) return ['status' => 'down', 'response_time' => 0, 'error' => 'API error', 'presence_count' => 0];
    $data = json_decode($response, true);
    if (!$data || isset($data['code'])) return ['status' => 'down', 'response_time' => 0, 'error' => $data['message'] ?? 'JSON error', 'presence_count' => 0];
    $presence_count = isset($data['presence_count']) ? (int)$data['presence_count'] : 0;
    $channels_with_users = []; $members_list = [];
    if (isset($data['members']) && is_array($data['members'])) {
        foreach ($data['members'] as $m) {
            $username = $m['username'] ?? ''; $status = $m['status'] ?? 'online';
            $game = $m['game']['name'] ?? null;
            $members_list[] = ['username' => $username, 'status' => $status, 'game' => $game];
            if (isset($m['channel_id'])) $channels_with_users[$m['channel_id']][] = $username;
        }
    }
    $voice_channels = [];
    if (isset($data['channels']) && is_array($data['channels'])) {
        foreach ($data['channels'] as $ch) {
            if (isset($channels_with_users[$ch['id']])) {
                $voice_channels[] = ['name' => $ch['name'], 'users' => $channels_with_users[$ch['id']]];
            }
        }
    }
    return [
        'status' => 'up', 'response_time' => $duration, 'error' => null, 'presence_count' => $presence_count,
        'name' => $data['name'] ?? 'Discord', 'instant_invite' => $data['instant_invite'] ?? null,
        'voice_channels' => $voice_channels, 'members' => array_slice($members_list, 0, 15)
    ];
}
