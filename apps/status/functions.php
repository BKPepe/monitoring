<?php
/**
 * Monitorovací funkce a odesílání notifikací
 */

require_once __DIR__ . '/db.php';

/**
 * Zformátuje sekundy uptimu do české gramatiky
 */
function format_uptime_cz($seconds) {
    if (!$seconds || $seconds <= 0) return 'N/A';
    
    $days = floor($seconds / 86400);
    $seconds %= 86400;
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);
    
    $parts = [];
    if ($days > 0) {
        if ($days == 1) $parts[] = '1 den';
        elseif ($days >= 2 && $days <= 4) $parts[] = $days . ' dny';
        else $parts[] = $days . ' dní';
    }
    if ($hours > 0) {
        if ($hours == 1) $parts[] = '1 hodina';
        elseif ($hours >= 2 && $hours <= 4) $parts[] = $hours . ' hodiny';
        else $parts[] = $hours . ' hodin';
    }
    if ($minutes > 0) {
        if ($minutes == 1) $parts[] = '1 minuta';
        elseif ($minutes >= 2 && $minutes <= 4) $parts[] = $minutes . ' minuty';
        else $parts[] = $minutes . ' minut';
    }
    
    if (empty($parts)) {
        return 'méně než minuta';
    }
    
    return implode(', ', $parts);
}

/**
 * Vykreslí grid a detaily z VPS agenta (CPU, RAM, Disk, Uptime, SMART, Porty)
 */
function render_vps_agent_details($details, $monitor = null) {
    if (!isset($details['cpu'])) return '';
    
    $cpu = floatval($details['cpu']);
    $ram = floatval($details['ram']);
    $hdd = floatval($details['hdd']);
    
    $cpu_color = ($cpu > 80) ? 'red' : (($cpu > 50) ? 'yellow' : 'green');
    $ram_color = ($ram > 85) ? 'red' : (($ram > 60) ? 'yellow' : 'green');
    $hdd_color = ($hdd > 90) ? 'red' : (($hdd > 70) ? 'yellow' : 'green');
    
    $is_admin = (session_status() === PHP_SESSION_ACTIVE) && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    
    ob_start();
    ?>
    <div style="display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.5rem;">
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Zatížení CPU</span>
                <strong style="color: #fff;" class="stat-val"><?php echo $cpu; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $cpu_color; ?>" style="width: <?php echo $cpu; ?>%"></div>
            </div>
        </div>
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Physical Memory Usage</span>
                <strong style="color: #fff;" class="stat-val"><?php echo $ram; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $ram_color; ?>" style="width: <?php echo $ram; ?>%"></div>
            </div>
        </div>
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Disk (HDD Usage)</span>
                <strong style="color: #fff;" class="stat-val"><?php echo $hdd; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $hdd_color; ?>" style="width: <?php echo $hdd; ?>%"></div>
            </div>
        </div>
    </div>
    
    <?php if (isset($details['uptime']) || isset($details['smart']) || isset($details['ports']) || isset($details['version']) || isset($details['os'])): ?>
        <div style="margin-top: 0.85rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.85rem; font-size: 0.78rem; display: flex; flex-direction: column; gap: 0.45rem;">
            <?php if (isset($details['version'])): 
                $v_reported = trim($details['version']);
                $latest_v = "1.2.0";
                $has_update = version_compare($v_reported, $latest_v, '<');
            ?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: var(--text-muted);">Verze agenta:</span>
                    <div>
                        <strong style="color: #fff;"><?php echo htmlspecialchars($v_reported); ?></strong>
                        <?php if ($has_update && $is_admin): ?>
                            <span style="background: rgba(243, 156, 18, 0.15); border: 1px solid rgba(243, 156, 18, 0.25); color: #f39c12; padding: 0.05rem 0.35rem; border-radius: 4px; font-size: 0.65rem; margin-left: 0.35rem;" title="Nová verze <?php echo $latest_v; ?> je k dispozici. Stáhněte nový agent skript ze sekce návodu níže."><i class="fas fa-arrow-up"></i> Aktualizace</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($details['os'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Operační systém:</span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($details['os']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['uptime'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Uptime serveru:</span>
                    <strong style="color: #fff;"><?php echo format_uptime_cz($details['uptime']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['smart'])): 
                $smart_val = $details['smart'];
                $smart_missing = (empty($smart_val) || strpos($smart_val, 'chybí') !== false || strpos($smart_val, 'missing') !== false || $smart_val === 'N/A');
                if (!$smart_missing):
                    $smart_color = (strpos($smart_val, 'WARNING') !== false) ? 'var(--color-red)' : 'var(--color-green)';
            ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Stav disků (SMART):</span>
                    <strong style="color: <?php echo $smart_color; ?>;"><?php echo htmlspecialchars($smart_val); ?></strong>
                </div>
            <?php elseif ($is_admin): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Stav disků (SMART):</span>
                    <strong style="color: var(--color-red);" title="Doporučujeme nainstalovat balíček 'smartmontools' (smartctl) na VPS pro monitorování zdraví disků.">N/A (smartctl chybí)</strong>
                </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php 
            if ($monitor):
                $monitored_str = $monitor['monitored_processes'] ?? '';
                if (!empty($monitored_str)):
                    $monitored_arr = array_filter(array_map('trim', explode(',', $monitored_str)));
                    $missing_arr = $details['missing_processes'] ?? [];
            ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem;">
                    <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Sledované procesy:</span>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                        <?php foreach ($monitored_arr as $proc): 
                            $is_missing = in_array($proc, $missing_arr);
                            $badge_bg = $is_missing ? 'rgba(193,18,31,0.1)' : 'rgba(30,199,115,0.1)';
                            $badge_border = $is_missing ? 'rgba(193,18,31,0.2)' : 'rgba(30,199,115,0.2)';
                            $badge_color = $is_missing ? 'var(--color-red)' : 'var(--color-green)';
                            $badge_icon = $is_missing ? 'fa-times-circle' : 'fa-check-circle';
                        ?>
                            <span style="background: <?php echo $badge_bg; ?>; border: 1px solid <?php echo $badge_border; ?>; color: <?php echo $badge_color; ?>; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.68rem; display: inline-flex; align-items: center; gap: 0.25rem; font-weight: bold;" title="<?php echo $is_missing ? 'Proces neběží!' : 'Proces je aktivní'; ?>">
                                <i class="fas <?php echo $badge_icon; ?>"></i> <?php echo htmlspecialchars($proc); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($monitor && $monitor['type'] === 'vps' && !empty($details['ports'])): 
                // Porty mohou přijít jako pole nebo čárkou oddělený řetězec
                $ports_arr = is_array($details['ports']) ? $details['ports'] : array_filter(array_map('trim', explode(',', $details['ports'])));
            ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem;">
                    <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Aktivní porty serveru:</span>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                        <?php foreach ($ports_arr as $p): ?>
                            <span style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.68rem; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($p); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}


/**
 * Vrátí informace o aktuální verzi aplikace.
 * Ve produkci čte soubor version.php vygenerovaný GitHub Actions deployem.
 * Lokálně (dev) se jako záloha pokusí o git log.
 * @return array ['hash' => '...', 'date' => '...', 'label' => '...']
 */
function get_app_version() {
    static $version = null;
    if ($version !== null) return $version;

    $version_file = __DIR__ . '/version.php';

    // Produkce: version.php vygeneroval GitHub Actions deploy
    if (file_exists($version_file)) {
        require_once $version_file;
        $version = [
            'hash'  => defined('APP_VERSION_HASH')  ? APP_VERSION_HASH  : '?',
            'date'  => defined('APP_VERSION_DATE')  ? APP_VERSION_DATE  : '?',
            'label' => defined('APP_VERSION_LABEL') ? APP_VERSION_LABEL : 'unknown',
        ];
        return $version;
    }

    // Lokální vývoj: záloha přes git log (nevolá se v produkci)
    $hash = '';
    $date = '';
    $git_dir = dirname(__DIR__);
    if (is_dir($git_dir . '/.git')) {
        $hash = @shell_exec("git -C " . escapeshellarg($git_dir) . " log --pretty=format:'%h' -1 2>/dev/null");
        $date = @shell_exec("git -C " . escapeshellarg($git_dir) . " log --pretty=format:'%ci' -1 2>/dev/null");
        $hash = $hash ? trim(str_replace("'", '', $hash)) : '';
        $date = $date ? trim(str_replace("'", '', $date)) : '';
        if ($date) {
            try {
                $dt = new DateTime($date);
                $date = $dt->format('Y-m-d H:i') . ' (local)';
            } catch (Exception $e) {
                $date = substr($date, 0, 16);
            }
        }
    }

    $version = [
        'hash'  => $hash ?: 'dev',
        'date'  => $date ?: date('Y-m-d'),
        'label' => ($hash && $date) ? $date . ' · ' . $hash : 'dev (no version.php)',
    ];
    return $version;
}

/**
 * Kontrola HTTP/HTTPS webu
 */
function check_http($url, $timeout = 5) {
    $start = microtime(true);
    
    $host = parse_url($url, PHP_URL_HOST);
    $has_ipv4 = false;
    $has_ipv6 = false;
    if ($host) {
        $dns_a = @dns_get_record($host, DNS_A);
        $has_ipv4 = !empty($dns_a);
        
        $dns_aaaa = @dns_get_record($host, DNS_AAAA);
        $has_ipv6 = !empty($dns_aaaa);
    }
    
    // Zjistíme zda je k dispozici cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        $primary_ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $scheme = curl_getinfo($ch, CURLINFO_SCHEME);
        $http_version_raw = curl_getinfo($ch, CURLINFO_HTTP_VERSION);
        curl_close($ch);
        
        $duration = round((microtime(true) - $start) * 1000);
        
        $http_version = 'HTTP/1.1';
        if ($http_version_raw === 3) {
            $http_version = 'HTTP/2';
        } elseif ($http_version_raw === 4) {
            $http_version = 'HTTP/3';
        } elseif ($http_version_raw === 1) {
            $http_version = 'HTTP/1.0';
        }
        
        $conn_details = [
            'has_ipv4' => $has_ipv4,
            'has_ipv6' => $has_ipv6,
            'primary_ip' => $primary_ip ?: '',
            'scheme' => $scheme ?: 'HTTP',
            'http_version' => $http_version
        ];
        
        if ($response === false) {
            return array_merge([
                'status' => 'down',
                'response_time' => 0,
                'error' => "cURL chyba: " . $error
            ], $conn_details);
        }
        
        if ($http_code >= 200 && $http_code < 400) {
            return array_merge([
                'status' => 'up',
                'response_time' => $duration,
                'error' => null
            ], $conn_details);
        } else {
            return array_merge([
                'status' => 'down',
                'response_time' => $duration,
                'error' => "HTTP status kód: " . $http_code
            ], $conn_details);
        }
    } else {
        // Fallback na file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        $duration = round((microtime(true) - $start) * 1000);
        
        $conn_details = [
            'has_ipv4' => $has_ipv4,
            'has_ipv6' => $has_ipv6,
            'primary_ip' => $host ? @gethostbyname($host) : '',
            'scheme' => strpos($url, 'https://') === 0 ? 'HTTPS' : 'HTTP',
            'http_version' => 'HTTP/1.1'
        ];
        
        if ($response === false) {
            return array_merge([
                'status' => 'down',
                'response_time' => 0,
                'error' => "Spojení selhalo"
            ], $conn_details);
        }
        
        // Získání HTTP kódu z hlaviček
        $http_code = 200;
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $http_response_header[0], $matches);
            if (isset($matches[1])) {
                $http_code = (int)$matches[1];
            }
        }
        
        if ($http_code >= 200 && $http_code < 400) {
            return array_merge([
                'status' => 'up',
                'response_time' => $duration,
                'error' => null
            ], $conn_details);
        } else {
            return array_merge([
                'status' => 'down',
                'response_time' => $duration,
                'error' => "HTTP status kód: " . $http_code
            ], $conn_details);
        }
    }
}

/**
 * Kontrola přes TCP Socket (port check / TCP ping)
 */
function check_socket($host, $port, $timeout = 5) {
    $start = microtime(true);
    // Odstranění protokolu z hostitele, pokud byl zadán
    $host = preg_replace('~^https?://~', '', $host);
    
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $duration = round((microtime(true) - $start) * 1000);
    
    if ($socket) {
        @fclose($socket);
        return [
            'status' => 'up',
            'response_time' => $duration,
            'error' => null
        ];
    } else {
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => "Port $port je zavřený nebo nedostupný: $errstr ($errno)"
        ];
    }
}

/**
 * Minecraft Server Query přes Server List Ping (SLP)
 */
/**
 * Záložní dotaz na Minecraft server přes veřejné API mcsrvstat.us
 */
function check_minecraft_api_fallback($host, $start, $timeout = 3) {
    $url = "https://api.mcsrvstat.us/2/" . urlencode($host);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['online'])) {
            if ($data['online'] === true) {
                $players_online = isset($data['players']['online']) ? (int)$data['players']['online'] : 0;
                $players_max = isset($data['players']['max']) ? (int)$data['players']['max'] : 20;
                $version = isset($data['version']) ? $data['version'] : '';
                if (isset($data['software'])) {
                    $version = $data['software'] . ' ' . $version;
                }
                $players_list = isset($data['players']['list']) ? $data['players']['list'] : [];
                $motd = '';
                if (isset($data['motd']['clean']) && is_array($data['motd']['clean'])) {
                    $motd = implode("\n", $data['motd']['clean']);
                }
                return [
                    'status' => 'up',
                    'response_time' => round((microtime(true) - $start) * 1000),
                    'error' => null,
                    'players_online' => $players_online,
                    'players_max' => $players_max,
                    'version' => $version,
                    'players_list' => $players_list,
                    'motd' => $motd,
                    'api_fallback' => true
                ];
            } else {
                return [
                    'status' => 'down',
                    'response_time' => 0,
                    'error' => 'Minecraft server je podle API vypnutý.',
                    'players_online' => 0,
                    'players_max' => 0
                ];
            }
        }
    }
    return null;
}

/**
 * Blood Kings Status - Minecraft SLP kontrola s jedním rychlým opakováním
 *
 * Krátký timeout a čtení odpovědi po jednotlivých bytech dělá jednorázový
 * pokus náchylný na běžné síťové zádrhele (server odpoví o zlomek sekundy
 * později, než limit dovolí) - proto se před přechodem na fallback API a
 * případným nahlášením výpadku zkusí spojení ještě jednou.
 */
function check_minecraft($host, $port = 25565, $timeout = 3) {
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);

    // Rozdělení hostitele a portu, pokud je zadáno jako host:port
    $parts = explode(':', $host);
    if (count($parts) === 2) {
        $host = $parts[0];
        $port = intval($parts[1]);
    }

    $result = check_minecraft_slp_attempt($host, $port, $timeout, $start);
    if ($result === null) {
        // Krátká prodleva a druhý pokus - odchytí přechodné výpadky/zpoždění
        usleep(300000); // 0.3 s
        $result = check_minecraft_slp_attempt($host, $port, $timeout, $start);
    }
    if ($result !== null) {
        return $result;
    }

    $fb = check_minecraft_api_fallback($host, $start, $timeout);
    if ($fb) return $fb;

    return [
        'status' => 'down',
        'response_time' => 0,
        'error' => 'Prázdná odpověď od MC serveru (timeout nebo nepodporovaný protokol), i po opakovaném pokusu.',
        'players_online' => 0,
        'players_max' => 0
    ];
}

/**
 * Jeden pokus o SLP handshake. Vrací null při selhání spojení/čtení
 * (volající pak zkusí znovu nebo přejde na fallback API), jinak vrací
 * hotové pole výsledku (status up i down - down se vrací jen v případech,
 * kde je odpověď jednoznačně platná, ale zjevně chybná, např. neplatné ID paketu).
 */
function check_minecraft_slp_attempt($host, $port, $timeout, $start) {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return null;
    }

    stream_set_timeout($socket, $timeout);

    // Minecraft SLP handshake protocol (1.7+)
    $packVarInt = function($value) {
        $string = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value > 0) {
                $byte |= 0x80;
            }
            $string .= chr($byte);
        } while ($value > 0);
        return $string;
    };

    // Handshake Packet
    $handshakePayload = $packVarInt(47) // Protocol version
                      . $packVarInt(strlen($host)) . $host
                      . pack('n', $port) // port
                      . $packVarInt(1); // Next state (1 = status)
    $handshakePacket = $packVarInt(strlen($handshakePayload) + 1)
                     . $packVarInt(0x00) // Packet ID (0)
                     . $handshakePayload;

    // Request Packet
    $requestPacket = $packVarInt(1) . $packVarInt(0x00);

    @fwrite($socket, $handshakePacket);
    @fwrite($socket, $requestPacket);

    // Read response length (VarInt)
    $readVarInt = function($socket) {
        $value = 0;
        $i = 0;
        do {
            $byte = @fread($socket, 1);
            if ($byte === false || strlen($byte) === 0) return false;
            $byteVal = ord($byte);
            $value |= ($byteVal & 0x7F) << ($i * 7);
            $i++;
            if ($i > 5) return false;
        } while (($byteVal & 0x80) != 0);
        return $value;
    };

    $packetLength = $readVarInt($socket);
    if ($packetLength === false) {
        // Nejde odlišit "server neběží" od "byte dorazil o zlomek sekundy později" -
        // necháváme volajícího zkusit znovu, než se sáhne po fallback API.
        @fclose($socket);
        return null;
    }

    $packetId = $readVarInt($socket);
    if ($packetId !== 0x00) {
        // Tady server reálně odpověděl, jen jiným ID paketu - opakování by
        // nepomohlo, jde o skutečný nesoulad protokolu/portu.
        @fclose($socket);
        $fb = check_minecraft_api_fallback($host, $start, $timeout);
        if ($fb) return $fb;

        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => 'Neočekávané ID paketu od MC serveru',
            'players_online' => 0,
            'players_max' => 0
        ];
    }

    $stringLength = $readVarInt($socket);
    if ($stringLength === false || $stringLength <= 0) {
        @fclose($socket);
        return null;
    }

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

    if (!$data) {
        $fb = check_minecraft_api_fallback($host, $start, $timeout);
        if ($fb) return $fb;
        
        return [
            'status' => 'up',
            'response_time' => $duration,
            'error' => 'Nelze dekódovat JSON stav Minecraft serveru',
            'players_online' => 0,
            'players_max' => 0
        ];
    }

    $playersOnline = isset($data['players']['online']) ? (int)$data['players']['online'] : 0;
    $playersMax = isset($data['players']['max']) ? (int)$data['players']['max'] : 0;
    $version = isset($data['version']['name']) ? $data['version']['name'] : 'Neznámá';
    
    // Získání seznamu hráčů
    $playersList = [];
    if (isset($data['players']['sample']) && is_array($data['players']['sample'])) {
        foreach ($data['players']['sample'] as $p) {
            if (isset($p['name'])) {
                $playersList[] = $p['name'];
            }
        }
    }
    
    // Získání a očistění MOTD
    $motd = '';
    if (isset($data['description'])) {
        if (is_string($data['description'])) {
            $motd = $data['description'];
        } elseif (isset($data['description']['text'])) {
            $motd = $data['description']['text'];
        } elseif (isset($data['description']['extra']) && is_array($data['description']['extra'])) {
            foreach ($data['description']['extra'] as $el) {
                if (isset($el['text'])) {
                    $motd .= $el['text'];
                }
            }
        }
        $motd = preg_replace('/§[0-9a-fk-orx]/i', '', $motd);
        $motd = trim($motd);
    }

    return [
        'status' => 'up',
        'response_time' => $duration,
        'error' => null,
        'players_online' => $playersOnline,
        'players_max' => $playersMax,
        'version' => $version,
        'players_list' => $playersList,
        'motd' => $motd,
        'api_fallback' => false
    ];
}

/**
 * TeamSpeak 3 Query Port Check
 */
function check_teamspeak($host, $port = 10011, $timeout = 3) {
    // Rozdělení voice portu a query portu (např. host:voice_port)
    $voice_port = 9987;
    $parts = explode(':', $host);
    if (count($parts) === 2) {
        $host = $parts[0];
        $voice_port = intval($parts[1]);
    }
    
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);
    
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $duration = round((microtime(true) - $start) * 1000);
    
    $connected_ip = '';
    $ip_version = 'IPv4';
    if ($socket) {
        $remote_name = @stream_socket_get_name($socket, true);
        if ($remote_name) {
            $last_colon = strrpos($remote_name, ':');
            if ($last_colon !== false) {
                $connected_ip = substr($remote_name, 0, $last_colon);
                $connected_ip = trim($connected_ip, '[]');
            }
            if (strpos($connected_ip, ':') !== false) {
                $ip_version = 'IPv6';
            }
        }
    }
    if (!$socket) {
        $server_ip = $_SERVER['SERVER_ADDR'] ?? null;
        if (!$server_ip && function_exists('gethostname')) {
            $server_ip = @gethostbyname(@gethostname());
        }
        if (!$server_ip || $server_ip === '127.0.0.1') {
            $server_ip = 'IP vašeho webhostingu';
        }
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => "TS3 Query port ($port) nedostupný: $errstr ($errno). Tip: Ujistěte se, že váš VPS neblokuje IP adresu webhostingu ($server_ip) ve svém firewallu nebo v souboru query_ip_whitelist.txt."
        ];
    }
    
    stream_set_timeout($socket, $timeout);
    
    // Čtení úvodního pozdravu ze ServerQuery (přesně 2 řádky: TS3 a Welcome zpráva)
    $greeting = '';
    $line1 = @fgets($socket, 256);
    $line2 = @fgets($socket, 256);
    if ($line1 !== false) $greeting .= $line1;
    if ($line2 !== false) $greeting .= $line2;
    
    if (strpos($greeting, 'TS3') === false && strpos($greeting, 'Welcome') === false) {
        @fclose($socket);
        $visible_greeting = !empty(trim($greeting)) ? '"' . trim(substr($greeting, 0, 50)) . '"' : 'žádná odezva (prázdná)';
        return [
            'status' => 'down',
            'response_time' => $duration,
            'error' => "Chyba komunikace s TS3 ServerQuery (přijatá data: $visible_greeting). Ujistěte se, že IP adresa webhostingu je přidána v query_ip_whitelist.txt na VPS."
        ];
    }
    
    // Zvolíme virtuální server na hlasovém portu
    @fwrite($socket, "use port=$voice_port\n");
    @fgets($socket, 256); // přečíst odpověď (error id=0 msg=ok)
    
    // Dotaz na info o serveru
    @fwrite($socket, "serverinfo\n");
    $info = @fgets($socket, 4096);
    
    // Odhlášení ze ServerQuery
    @fwrite($socket, "quit\n");
    @fclose($socket);
    
    if ($info && strpos($info, 'virtualserver_clientsonline') !== false) {
        $parts = explode(' ', $info);
        $details = [];
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) == 2) {
                // Dekódování TS3 escapovaných znaků (\s -> mezera, \/ -> lomítko atd.)
                $details[$kv[0]] = str_replace(['\s', '\/', '\p'], [' ', '/', '|'], $kv[1]);
            }
        }
        
        $clients_online = isset($details['virtualserver_clientsonline']) ? (int)$details['virtualserver_clientsonline'] : 0;
        $query_clients = isset($details['virtualserver_queryclientsonline']) ? (int)$details['virtualserver_queryclientsonline'] : 0;
        $clients_max = isset($details['virtualserver_maxclients']) ? (int)$details['virtualserver_maxclients'] : 0;
        
        return [
            'status' => 'up',
            'response_time' => $duration,
            'error' => null,
            'clients_online' => max(0, $clients_online - $query_clients),
            'clients_max' => $clients_max,
            'name' => $details['virtualserver_name'] ?? 'TeamSpeak Server',
            'version' => $details['virtualserver_version'] ?? '',
            'checked_ip' => $connected_ip,
            'ip_version' => $ip_version
        ];
    }
    
    $error_detail = 'Spojení navázáno, ale nepodařilo se načíst detaily z Query portu';
    if ($info) {
        $error_detail .= ' (Odpověď serveru: ' . trim($info) . ')';
    }
    $server_ip = $_SERVER['SERVER_ADDR'] ?? null;
    if (!$server_ip && function_exists('gethostname')) {
        $server_ip = @gethostbyname(@gethostname());
    }
    if (!$server_ip || $server_ip === '127.0.0.1') {
        $server_ip = 'IP vašeho webhostingu';
    }
    $error_detail .= ". Tip: Pokud vidíte chybu 'flooding', přidejte IP webhostingu ($server_ip) do souboru query_ip_whitelist.txt na vašem TS3 VPS.";
    return [
        'status' => 'up',
        'response_time' => $duration,
        'error' => $error_detail
    ];
}

/**
 * Discord Guild Widget API Check
 */
function check_discord($guild_id, $timeout = 3) {
    $start = microtime(true);
    $url = "https://discord.com/api/guilds/" . urlencode($guild_id) . "/widget.json";
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n",
                'ignore_errors' => true
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        $http_code = 200;
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $http_response_header[0], $matches);
            if (isset($matches[1])) {
                $http_code = (int)$matches[1];
            }
        }
    }
    
    $duration = round((microtime(true) - $start) * 1000);
    
    if ($http_code !== 200 || !$response) {
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => "Discord API neodpovídá nebo server neexistuje (kód $http_code). Ujistěte se, že máte v nastavení Discord serveru zapnutý Widget.",
            'presence_count' => 0
        ];
    }
    
    $data = json_decode($response, true);
    if (!$data || isset($data['code'])) {
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => isset($data['message']) ? $data['message'] : 'Chyba parsování Discord API',
            'presence_count' => 0
        ];
    }
    
    $presence_count = isset($data['presence_count']) ? (int)$data['presence_count'] : 0;
    
    // Projdeme členy a seskupíme je do hlasových kanálů
    $channels_with_users = [];
    $members_list = [];
    if (isset($data['members']) && is_array($data['members'])) {
        foreach ($data['members'] as $m) {
            $username = $m['username'] ?? '';
            $status = $m['status'] ?? 'online';
            $game = isset($m['game']['name']) ? $m['game']['name'] : null;
            
            $members_list[] = [
                'username' => $username,
                'status' => $status,
                'game' => $game
            ];
            
            if (isset($m['channel_id']) && $m['channel_id'] !== null) {
                $chan_id = $m['channel_id'];
                $channels_with_users[$chan_id][] = $username;
            }
        }
    }
    
    // Doplníme názvy kanálů
    $voice_channels = [];
    if (isset($data['channels']) && is_array($data['channels'])) {
        foreach ($data['channels'] as $ch) {
            $ch_id = $ch['id'];
            if (isset($channels_with_users[$ch_id])) {
                $voice_channels[] = [
                    'name' => $ch['name'],
                    'users' => $channels_with_users[$ch_id]
                ];
            }
        }
    }

    return [
        'status' => 'up',
        'response_time' => $duration,
        'error' => null,
        'presence_count' => $presence_count,
        'name' => $data['name'] ?? 'Discord Server',
        'instant_invite' => $data['instant_invite'] ?? null,
        'voice_channels' => $voice_channels,
        'members' => array_slice($members_list, 0, 15) // Zobrazit max 15 členů pro úsporu místa
    ];
}

/**
 * Odeslání e-mailu přes PHPMailer (SMTP autentizace) nebo PHP mail() jako záloha
 */
function send_email($to, $subject, $html_body) {
    $GLOBALS['last_mail_error'] = '';
    
    $smtp_host = get_setting('smtp_host', '');
    $smtp_port = (int) get_setting('smtp_port', 587);
    $smtp_user = get_setting('smtp_user', '');
    $smtp_pass = get_setting('smtp_pass', '');
    $smtp_secure = get_setting('smtp_secure', 'tls'); // 'tls' = STARTTLS, 'ssl' = SSL
    $site_title = get_setting('site_title', 'Blood Kings Status');
    
    $lib_path = __DIR__ . '/lib/';
    
    // Pokud jsou SMTP přihlašovací údaje nastaveny a PHPMailer je dostupný, použijeme ho
    if (!empty($smtp_host) && !empty($smtp_user) && !empty($smtp_pass) && file_exists($lib_path . 'PHPMailer.php')) {
        require_once $lib_path . 'Exception.php';
        require_once $lib_path . 'SMTP.php';
        require_once $lib_path . 'PHPMailer.php';
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_user;
            $mail->Password   = $smtp_pass;
            $mail->SMTPSecure = ($smtp_secure === 'ssl')
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp_port;
            $mail->CharSet    = 'UTF-8';
            
            $mail->setFrom($smtp_user, $site_title);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject    = $subject;
            $mail->Body       = $html_body;
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            $GLOBALS['last_mail_error'] = $mail->ErrorInfo ?? $e->getMessage();
            return false;
        }
    }
    
    // Záloha: PHP mail() bez SMTP autentizace (funguje jen pokud webhosting povoluje)
    $from = !empty($smtp_user) ? $smtp_user : 'status@bloodkings.eu';
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . $site_title . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . phpversion()
    ];
    set_error_handler(function($errno, $errstr) {
        $GLOBALS['last_mail_error'] = $errstr;
    });
    $result = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html_body, implode("\r\n", $headers));
    restore_error_handler();
    if (!$result && empty($GLOBALS['last_mail_error'])) {
        $GLOBALS['last_mail_error'] = 'mail() vrátilo false – SMTP host/heslo nejsou nastaveny, zkuste nakonfigurovat SMTP v nastavení systému.';
    }
    return $result;
}

/**
 * Odeslání SMS přes Twilio nebo SMSbrana.cz
 */
function send_sms($phone, $message, $user_whatsapp_apikey = '', $force_gateway = '') {
    $gateway = !empty($force_gateway) ? $force_gateway : get_setting('sms_gateway_type', '');
    
    if ($gateway === 'whatsapp') {
        // CallMeBot klíč je vázaný na konkrétní telefonní číslo, takže existuje
        // jen jako osobní klíč každého uživatele - žádný globální fallback.
        $apikey = $user_whatsapp_apikey;
        if (empty($apikey) || empty($phone)) {
            return false;
        }
        
        // Vyčistit telefonní číslo pro CallMeBot (pouze číslice)
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        // Pokud číslo nezačíná mezinárodní předvolbou (má 9 číslic), doplníme české +420
        if (strlen($clean_phone) === 9) {
            $clean_phone = '420' . $clean_phone;
        }
        
        $url = "https://api.callmebot.com/whatsapp.php?phone=" . urlencode($clean_phone) . "&text=" . urlencode($message) . "&apikey=" . urlencode($apikey);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code >= 200 && $code < 300);
    }
    
    if ($gateway === 'twilio') {
        $sid = get_setting('twilio_sid');
        $token = get_setting('twilio_token');
        $from = get_setting('twilio_from');
        
        if (empty($sid) || empty($token) || empty($from)) {
            return false;
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        
        $data = [
            'From' => $from,
            'To' => $phone,
            'Body' => $message
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code >= 200 && $code < 300);
    } 
    elseif ($gateway === 'smsbrana') {
        $user = get_setting('smsbrana_user');
        $password = get_setting('smsbrana_password');
        
        if (empty($user) || empty($password)) {
            return false;
        }
        
        // SMS Brána API odeslání SMS (HTTP GET/POST)
        $url = "https://api.smsbrana.cz/sms/apixml.xml";
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <apirequest>
            <user>' . htmlspecialchars($user) . '</user>
            <password>' . htmlspecialchars($password) . '</password>
            <action>send_sms</action>
            <params>
                <sms>
                    <sender>txt</sender>
                    <number>' . htmlspecialchars($phone) . '</number>
                    <message>' . htmlspecialchars($message) . '</message>
                </sms>
            </params>
        </apirequest>';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code === 200 && strpos($response, '<err>0</err>') !== false);
    }
    
    return false;
}

/**
 * Pomocná funkce pro zjištění, zda je monitor v plánované údržbě
 */
function is_in_maintenance($monitor) {
    if ((int)($monitor['maintenance'] ?? 0) !== 1) {
        return false;
    }
    if (!empty($monitor['maintenance_start']) && !empty($monitor['maintenance_end'])) {
        $now = time();
        $start = strtotime($monitor['maintenance_start']);
        $end = strtotime($monitor['maintenance_end']);
        if ($now >= $start && $now <= $end) {
            return true;
        }
        return false;
    }
    return true;
}

/**
 * Spuštění notifikačního procesu při změně stavu monitoru
 */
function trigger_notifications($pdo, $monitor, $new_status, $error_msg = '') {
    $name = $monitor['name'];
    $type = $monitor['type'];
    $target = $monitor['target'];
    $port = $monitor['port'];

    // Notifikace o neaktivitě agenta se řídí přímo časovým limitem (0 = zcela vypnuto, viz cron.php)
    // - žádný samostatný přepínač pro ně proto není potřeba.
    // Upozornění na překročení limitů CPU/RAM/HDD lze v nastavení vypnout samostatně.
    $is_agent_event = in_array($new_status, ['agent_offline', 'vps_warning'], true);
    if ($new_status === 'vps_warning' && get_setting('agent_notifications_enabled', '1') !== '1') {
        return;
    }

    $status_text = 'DOWN (Výpadek)';
    $emoji = '🔴';
    if ($new_status === 'up') {
        $status_text = 'ONLINE (Zpět v provozu)';
        $emoji = '🟢';
    } elseif ($new_status === 'maintenance') {
        $status_text = 'ÚDRŽBA (Plánovaná odstávka)';
        $emoji = '⚠️';
    } elseif ($new_status === 'agent_offline') {
        $status_text = 'VPS AGENT NEAKTIVNÍ';
        $emoji = '🔴';
    } elseif ($new_status === 'vps_warning') {
        $status_text = 'VPS METRIKY - VAROVÁNÍ';
        $emoji = '⚠️';
    }
    $subject = "$emoji $status_text: $name";
    
    // Načtení všech příjemců notifikací (odběratelé + administrátoři bez přímého nastavení odběru)
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.phone, u.role, u.whatsapp_apikey,
               COALESCE(s.email_notifications, m.email_notifications) as email_notifications, 
               COALESCE(s.sms_notifications, m.sms_notifications, u.sms_notifications) as sms_notifications,
               COALESCE(s.whatsapp_notifications, u.whatsapp_notifications) as whatsapp_notifications
        FROM users u
        CROSS JOIN (SELECT * FROM monitors WHERE id = ?) m
        LEFT JOIN user_subscriptions s ON u.id = s.user_id AND s.monitor_id = m.id
        WHERE u.role = 'admin' OR s.user_id IS NOT NULL
    ");
    $stmt->execute([$monitor['id']]);
    $recipients = $stmt->fetchAll();

    // Události VPS agenta jsou ve výchozím stavu interní - chodí pouze administrátorům, ne běžným odběratelům
    if ($is_agent_event && get_setting('agent_notify_admin_only', '1') === '1') {
        $recipients = array_values(array_filter($recipients, function ($r) {
            return ($r['role'] ?? '') === 'admin';
        }));
    }

    $time = date('d.m.Y H:i:s');
    
    // HTML Šablona pro E-mail v barvách Blood Kings (červeno-černá)
    $color_theme = '#c1121f'; // red
    if ($new_status === 'up') {
        $color_theme = '#1ec773'; // teal
    } elseif ($new_status === 'maintenance' || $new_status === 'vps_warning') {
        $color_theme = '#f39c12'; // orange
    }
    
    $html_body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; background-color: #0f0f13; color: #e1e1e6; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background-color: #1a1a24; border-radius: 8px; border-top: 5px solid ' . $color_theme . '; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
            .header { padding: 25px; text-align: center; background-color: #12121a; }
            .header h1 { margin: 0; font-size: 22px; color: #ffffff; }
            .content { padding: 30px; line-height: 1.6; }
            .status-badge { display: inline-block; padding: 6px 12px; border-radius: 4px; font-weight: bold; color: #ffffff; background-color: ' . $color_theme . '; margin-bottom: 20px; text-transform: uppercase; }
            .details { background-color: #12121a; border-left: 3px solid #ff4444; padding: 15px; margin: 20px 0; border-radius: 0 4px 4px 0; }
            .footer { padding: 15px 30px; text-align: center; font-size: 12px; color: #888896; border-top: 1px solid #22222f; background-color: #12121a; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Blood Kings Status</h1>
            </div>
            <div class="content">
                <div class="status-badge">' . $status_text . '</div>
                <p>Upozorňujeme na změnu stavu vašeho monitorovaného serveru/služby.</p>
                <div class="details">
                    <strong>Název:</strong> ' . htmlspecialchars($name) . '<br>
                    <strong>Typ:</strong> ' . htmlspecialchars(strtoupper($type)) . '<br>
                    <strong>Cíl:</strong> ' . htmlspecialchars($target) . ($port ? ':'.$port : '') . '<br>
                    <strong>Čas změny:</strong> ' . $time . '<br>
                    ' . (!empty($error_msg) ? '<strong>Popis/Chyba:</strong> ' . htmlspecialchars($error_msg) . '<br>' : '') . '
                </div>
                <p>Systém bude nadále monitorovat tuto službu a obdržíte další upozornění, jakmile se její stav změní.</p>
            </div>
            <div class="footer">
                Tento e-mail byl automaticky generován systémem Blood Kings.
            </div>
        </div>
    </body>
    </html>';
    
    // SMS / WhatsApp Zpráva
    $sms_body = "$emoji Monitor $name je $status_text. Čas: $time.";
    if ($new_status === 'maintenance') {
        $sms_body = "$emoji Monitor $name byl přepnut do režimu plánované údržby. Důvod: $error_msg";
    } elseif ($new_status === 'down' && !empty($error_msg)) {
        $sms_body .= " Chyba: " . substr($error_msg, 0, 100);
    } elseif ($is_agent_event && !empty($error_msg)) {
        // U událostí agenta (neaktivní agent, překročené limity) vždy uvést důvod, co se stalo
        $sms_body .= " Důvod: " . substr($error_msg, 0, 220);
    }
    
    foreach ($recipients as $rec) {
        // E-mailové notifikace
        if ($rec['email_notifications'] && !empty($rec['email'])) {
            send_email($rec['email'], $subject, $html_body);
        }
        
        // SMS notifikace (Twilio / SMSbrana) - nezávislé na WhatsApp
        $gateway_type = get_setting('sms_gateway_type', '');
        if ($rec['sms_notifications'] && !empty($rec['phone'])) {
            if ($gateway_type === 'twilio' || $gateway_type === 'smsbrana') {
                send_sms($rec['phone'], $sms_body);
            }
        }

        // WhatsApp notifikace (CallMeBot) - nezávislé na SMS bráně, vlastní kanál.
        // Klíč je vázaný na konkrétní telefonní číslo, takže existuje jen per-user.
        if (($rec['whatsapp_notifications'] ?? 0) && !empty($rec['phone']) && !empty($rec['whatsapp_apikey'])) {
            send_sms($rec['phone'], $sms_body, $rec['whatsapp_apikey'], 'whatsapp');
        }
    }

    // Odeslání systémových webhooků (Discord, Slack, Telegram) - spouští se pouze 1x na událost
    $discord_webhook = get_setting('discord_webhook_url');
    $telegram_token = get_setting('telegram_bot_token');
    $telegram_chat = get_setting('telegram_chat_id');
    $slack_webhook = get_setting('slack_webhook_url');

    if (!empty($discord_webhook)) {
        $color = ($new_status === 'up') ? 3066993 : 15073280; // Zelená / Červená
        $payload = [
            "embeds" => [[
                "title" => "Blood Kings Status Alert",
                "description" => "**Monitor:** " . htmlspecialchars($name) . "\n**Status:** " . strtoupper($status_text) . "\n**Čas:** " . $time . (!empty($error_msg) ? "\n**Detaily:** " . htmlspecialchars($error_msg) : ""),
                "color" => $color
            ]]
        ];
        send_webhook_post($discord_webhook, json_encode($payload));
    }

    if (!empty($slack_webhook)) {
        $slack_msg = "$emoji *Blood Kings Alert*:\n*Monitor:* $name\n*Status:* " . strtoupper($status_text) . "\n*Čas:* $time" . (!empty($error_msg) ? "\n*Detaily:* $error_msg" : "");
        send_webhook_post($slack_webhook, json_encode(["text" => $slack_msg]));
    }

    if (!empty($telegram_token) && !empty($telegram_chat)) {
        $tg_msg = "$emoji *Blood Kings Alert*:\n*Monitor:* $name\n*Status:* " . strtoupper($status_text) . "\n*Čas:* $time" . (!empty($error_msg) ? "\n*Detaily:* $error_msg" : "");
        $tg_url = "https://api.telegram.org/bot" . $telegram_token . "/sendMessage";
        $payload = [
            "chat_id" => $telegram_chat,
            "text" => $tg_msg,
            "parse_mode" => "Markdown"
        ];
        send_webhook_post($tg_url, json_encode($payload));
    }
}

/**
 * Pomocná funkce pro odeslání HTTP POST požadavků (webhooků)
 */
function send_webhook_post($url, $payload_json) {
    $ch = curl_init($url);
    if ($ch === false) return;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: BloodKingsStatus/1.3.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Sestaví a odešle pravidelný souhrnný report (týdenní/měsíční) na e-maily
 * všech administrátorů. Volá se z cron.php (automaticky, s ochranou proti
 * duplicitnímu odeslání) a z admin.php (ruční okamžité odeslání).
 *
 * @param PDO $pdo
 * @param string $period 'weekly' nebo 'monthly'
 * @return bool True, pokud se report podařilo odeslat alespoň jednomu administrátorovi.
 */
function send_digest_report($pdo, $period = 'weekly') {
    $GLOBALS['last_mail_error'] = '';
    try {
        return send_digest_report_inner($pdo, $period);
    } catch (Exception $e) {
        $GLOBALS['last_mail_error'] = $e->getMessage();
        return false;
    }
}

function send_digest_report_inner($pdo, $period = 'weekly') {
    $days = ($period === 'monthly') ? 30 : 7;
    $period_label = ($period === 'monthly') ? 'Měsíční' : 'Týdenní';
    $site_title = get_setting('site_title', 'Blood Kings Status');

    // Celkový přehled - stejná logika jako výpočet uptime na veřejném dashboardu
    $stmt_overall = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
            SUM(CASE WHEN status != 'maintenance' THEN 1 ELSE 0 END) as total_count,
            SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt_overall->execute([$days]);
    $overall = $stmt_overall->fetch();
    $total_checks = (int)($overall['total_count'] ?? 0);
    $overall_uptime = $total_checks > 0 ? round(($overall['up_count'] / $total_checks) * 100, 2) : 100.00;
    $incident_count = (int)($overall['down_count'] ?? 0);

    $stmt_total_monitors = $pdo->query("SELECT COUNT(*) FROM monitors");
    $total_monitors = (int)$stmt_total_monitors->fetchColumn();

    // Nejméně spolehlivé monitory v období (top 5 dle nejnižší dostupnosti).
    // Řazení podle poměru up_count/total_count se schválně dopočítává v PHP,
    // ne v ORDER BY - použití SELECT aliasu agregační funkce uvnitř výrazu
    // v ORDER BY selhává na řadě MySQL/MariaDB verzí s chybou 1247.
    $stmt_worst = $pdo->prepare("
        SELECT m.name, m.type,
               SUM(CASE WHEN l.status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN l.status = 'down' THEN 1 ELSE 0 END) as down_count,
               SUM(CASE WHEN l.status != 'maintenance' THEN 1 ELSE 0 END) as total_count
        FROM monitor_logs l
        JOIN monitors m ON m.id = l.monitor_id
        WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY l.monitor_id, m.name, m.type
        HAVING down_count > 0
        ORDER BY down_count DESC
        LIMIT 20
    ");
    $stmt_worst->execute([$days]);
    $worst_monitors = $stmt_worst->fetchAll();

    usort($worst_monitors, function ($a, $b) {
        $ratio_a = $a['total_count'] > 0 ? $a['up_count'] / $a['total_count'] : 1;
        $ratio_b = $b['total_count'] > 0 ? $b['up_count'] / $b['total_count'] : 1;
        return $ratio_a <=> $ratio_b;
    });
    $worst_monitors = array_slice($worst_monitors, 0, 5);

    // Příjemci - všichni administrátoři se zadaným e-mailem
    $stmt_admins = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
    $admin_emails = $stmt_admins->fetchAll(PDO::FETCH_COLUMN);
    if (empty($admin_emails)) {
        $GLOBALS['last_mail_error'] = 'Žádný administrátor nemá vyplněný e-mail.';
        return false;
    }

    $range_from = date('d.m.Y', strtotime("-$days days"));
    $range_to = date('d.m.Y');
    $subject = "📊 $period_label report – $site_title ($range_from – $range_to)";

    $rows_html = '';
    if (empty($worst_monitors)) {
        $rows_html = '<tr><td colspan="3" style="padding: 10px; color: #94a3b8;">Žádné výpadky v tomto období - všechny služby běžely bez problémů.</td></tr>';
    } else {
        foreach ($worst_monitors as $wm) {
            $wm_uptime = $wm['total_count'] > 0 ? round(($wm['up_count'] / $wm['total_count']) * 100, 2) : 100.00;
            $rows_html .= '<tr>'
                . '<td style="padding: 8px 10px; border-top: 1px solid #22222f;">' . htmlspecialchars($wm['name']) . '</td>'
                . '<td style="padding: 8px 10px; border-top: 1px solid #22222f; text-align: center;">' . htmlspecialchars($wm['down_count']) . '</td>'
                . '<td style="padding: 8px 10px; border-top: 1px solid #22222f; text-align: right;">' . $wm_uptime . '%</td>'
                . '</tr>';
        }
    }

    $html_body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; background-color: #0f0f13; color: #e1e1e6; margin: 0; padding: 20px; }
            .container { max-width: 640px; margin: 0 auto; background-color: #1a1a24; border-radius: 8px; border-top: 5px solid #c1121f; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
            .header { padding: 25px; text-align: center; background-color: #12121a; }
            .header h1 { margin: 0; font-size: 22px; color: #ffffff; }
            .header p { margin: 6px 0 0 0; color: #888896; font-size: 13px; }
            .content { padding: 30px; line-height: 1.6; }
            .stat-grid { display: table; width: 100%; margin-bottom: 20px; }
            .stat-box { display: table-cell; text-align: center; padding: 12px; background-color: #12121a; border-radius: 6px; }
            .stat-box .value { font-size: 22px; font-weight: bold; color: #ffffff; }
            .stat-box .label { font-size: 11px; color: #888896; text-transform: uppercase; margin-top: 4px; }
            table.report-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
            table.report-table th { text-align: left; padding: 8px 10px; color: #888896; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #22222f; }
            .footer { padding: 15px 30px; text-align: center; font-size: 12px; color: #888896; border-top: 1px solid #22222f; background-color: #12121a; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>📊 ' . $period_label . ' report</h1>
                <p>' . htmlspecialchars($site_title) . ' &middot; ' . $range_from . ' – ' . $range_to . '</p>
            </div>
            <div class="content">
                <div class="stat-grid">
                    <div class="stat-box"><div class="value">' . $overall_uptime . '%</div><div class="label">Dostupnost</div></div>
                    <div class="stat-box"><div class="value">' . $incident_count . '</div><div class="label">Výpadky</div></div>
                    <div class="stat-box"><div class="value">' . $total_monitors . '</div><div class="label">Monitorů</div></div>
                </div>
                <p><strong>Nejméně spolehlivé služby v tomto období:</strong></p>
                <table class="report-table">
                    <thead><tr><th>Název</th><th style="text-align:center;">Výpadků</th><th style="text-align:right;">Dostupnost</th></tr></thead>
                    <tbody>' . $rows_html . '</tbody>
                </table>
                <p style="margin-top: 24px; font-size: 12px; color: #888896;">Tento report byl automaticky vygenerován systémem Blood Kings Status a odeslán všem administrátorům.</p>
            </div>
            <div class="footer">
                ' . htmlspecialchars($site_title) . ' &mdash; ' . date('d.m.Y H:i') . '
            </div>
        </div>
    </body>
    </html>';

    $any_success = false;
    foreach ($admin_emails as $email) {
        if (send_email($email, $subject, $html_body)) {
            $any_success = true;
        }
    }

    return $any_success;
}

/**
 * Převod dvoumístného kódu země (např. CZ, DE) na emoji vlaječku
 */
function get_country_emoji($country_code) {
    $code = strtoupper($country_code);
    if (strlen($code) !== 2) return '🌐';
    $first = ord($code[0]) - 65 + 127462;
    $second = ord($code[1]) - 65 + 127462;
    return mb_convert_encoding('&#' . $first . ';&#' . $second . ';', 'UTF-8', 'HTML-ENTITIES');
}

/**
 * Automatická detekce geografické lokace a ASN serveru přes veřejné API
 */
function detect_server_location() {
    if (!function_exists('curl_init')) {
        return '🇨🇿 Praha, CZ';
    }
    
    $ch = curl_init("http://ip-api.com/json/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $resp = curl_exec($ch);
    curl_close($ch);
    
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['status']) && $data['status'] === 'success') {
            $flag = get_country_emoji($data['countryCode'] ?? 'CZ');
            $city = $data['city'] ?? 'Praha';
            $country = $data['countryCode'] ?? 'CZ';
            
            $org = $data['org'] ?? $data['isp'] ?? '';
            $org_clean = '';
            if (!empty($org)) {
                $org_parts = explode(' ', $org);
                $org_clean = implode(' ', array_slice($org_parts, 0, 3));
            }
            
            return $flag . ' ' . $city . ', ' . $country . ($org_clean ? ' (' . $org_clean . ')' : '');
        }
    }
    return '🇨🇿 Praha, CZ'; // Výchozí fallback
}

/**
 * Kontrola cPanel status endpointu a načtení statistik
 */
function check_cpanel($url, $timeout = 5) {
    $start = microtime(true);
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $duration = round((microtime(true) - $start) * 1000);
        
        if ($response === false) {
            return [
                'status' => 'down',
                'response_time' => 0,
                'error' => "cURL chyba: " . $error
            ];
        }
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            if ($data && isset($data['status']) && $data['status'] === 'ok') {
                return [
                    'status' => 'up',
                    'response_time' => $duration,
                    'error' => null,
                    'disk' => $data['disk'] ?? null,
                    'memory' => $data['memory'] ?? null,
                    'processes' => $data['processes'] ?? null,
                    'database' => $data['database'] ?? null,
                    'bandwidth' => $data['bandwidth'] ?? null,
                    'postgresql' => $data['postgresql'] ?? null
                ];
            } else {
                return [
                    'status' => 'down',
                    'response_time' => $duration,
                    'error' => 'Neplatný JSON formát nebo chybný bezpečnostní klíč.'
                ];
            }
        } else {
            return [
                'status' => 'down',
                'response_time' => $duration,
                'error' => "HTTP status kód: " . $http_code
            ];
        }
    } else {
        // Fallback bez cURL
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        $duration = round((microtime(true) - $start) * 1000);
        
        if ($response === false) {
            return [
                'status' => 'down',
                'response_time' => 0,
                'error' => 'Chyba při stahování dat přes stream.'
            ];
        }
        
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            return [
                'status' => 'up',
                'response_time' => $duration,
                'error' => null,
                'disk' => $data['disk'] ?? null,
                'memory' => $data['memory'] ?? null,
                'processes' => $data['processes'] ?? null,
                'database' => $data['database'] ?? null,
                'bandwidth' => $data['bandwidth'] ?? null,
                'postgresql' => $data['postgresql'] ?? null
            ];
        } else {
            return [
                'status' => 'down',
                'response_time' => $duration,
                'error' => 'Neplatná struktura dat.'
            ];
        }
    }
}

