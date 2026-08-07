<?php
/**
 * Cron skript pro pravidelnou kontrolu monitorovaných služeb
 * Doporučený interval spouštění: každé 1 až 5 minut.
 * Příklad volání: php cron.php nebo curl https://status.bloodkings.eu/cron.php
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Spouštění pouze z CLI (příkazová řádka) nebo se správným bezpečnostním klíčem v URL
$is_cli = (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST']));
$cron_key = get_setting('cron_key', '');

if (!$is_cli && !empty($cron_key)) {
    if (!isset($_GET['key']) || $_GET['key'] !== $cron_key) {
        http_response_code(403);
        exit("Neoprávněný přístup ke cronu. Zadejte správný klíč '?key=...'");
    }
}

echo "Spouštím kontrolu monitorů... \n";

// --- Schema self-test (1x denně) --- detekuje chybějící sloupce dřív než agent narazí na chybu
$last_schema_check = get_setting('last_schema_check', '');
if ($last_schema_check === '' || strtotime($last_schema_check) < strtotime('-24 hours')) {
    try {
        $required_cols = ['iowait_pct','inode_usage_pct','zombie_count','fork_rate','temperature_c','wifi_clients_total','conntrack_pct'];
        $stmt_cols = $pdo->query("DESCRIBE vps_metrics");
        $existing = array_column($stmt_cols->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $missing = array_diff($required_cols, $existing);
        if (!empty($missing)) {
            $warn = 'SCHEMA DRIFT: vps_metrics missing columns: ' . implode(', ', $missing) . '. Please update schema.sql.';
            error_log('[cron] ' . $warn);
            echo "VAROVÁNÍ: $warn\n";
        }
        $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('last_schema_check', NOW()) ON DUPLICATE KEY UPDATE key_value = NOW()")->execute();
    } catch (PDOException $e) {
        error_log('[cron] Schema check failed: ' . $e->getMessage());
    }
}

// Načtení všech monitorů
$stmt = $pdo->query("SELECT * FROM monitors");
$monitors = $stmt->fetchAll();

foreach ($monitors as $monitor) {
    $id = $monitor['id'];
    $name = $monitor['name'];
    $type = $monitor['type'];
    $target = $monitor['target'];
    $port = $monitor['port'];
    $timeout = $monitor['timeout'] ?: 5;
    $old_status = $monitor['status'];
    
    // Kontrola neaktivity VPS agenta (pokud je propojen)
    // Časový limit 0 = detekce neaktivity agenta je zcela vypnutá
    $offline_timeout_mins = intval(get_setting('agent_offline_timeout', '50'));
    if (!empty($monitor['agent_key']) && $offline_timeout_mins > 0) {
        $details_arr = json_decode($monitor['last_details'] ?? '{}', true);
        $agent_last_seen = $details_arr['agent_last_seen'] ?? 0;

        if ($agent_last_seen > 0) {
            $seconds_since_report = time() - $agent_last_seen;
            $agent_alert_sent = $details_arr['agent_alert_sent'] ?? false;

            $offline_timeout_secs = $offline_timeout_mins * 60;

            if ($seconds_since_report > $offline_timeout_secs && !$agent_alert_sent) {
                $details_arr['agent_alert_sent'] = true;
                $new_details = json_encode($details_arr, JSON_UNESCAPED_UNICODE);

                $stmt_up_agent = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
                $stmt_up_agent->execute([$new_details, $id]);

                $mins_since = round($seconds_since_report / 60);
                $last_seen_str = date('d.m.Y H:i', intval($agent_last_seen));
                $error_msg_agent = "Agent monitoru '{$name}' nehlásí žádná data déle než {$offline_timeout_mins} minut. "
                    . "Poslední hlášení: {$last_seen_str} (před {$mins_since} min). "
                    . "Možné příčiny: cron úloha agenta na VPS neběží, VPS je vypnuté/restartuje se, nebo je nedostupná síť/firewall blokuje spojení.";
                trigger_notifications($pdo, $monitor, 'agent_offline', $error_msg_agent);
                log_monitor_event($pdo, $id, $name, $type, 'agent_disconnected', "Agent přestal hlásit data (poslední hlášení před {$mins_since} min)");
            }
        }
    }
    
    echo "Kontroluji [$type] $name ($target)... ";
    
    if (is_in_maintenance($monitor)) {
        echo "Údržba (přeskakuji)\n";
        $new_status = 'maintenance';
        
        $loc = get_setting('ip_loc_local', '');
        if (empty($loc)) {
            $loc = 'Main Server';
        }
        
        $m_desc = $monitor['maintenance_description'] ?: 'Plánovaná údržba';
        $m_end = $monitor['maintenance_end'] ? ' (do ' . date('d.m.Y H:i', strtotime($monitor['maintenance_end'])) . ')' : '';
        $log_msg = $m_desc . $m_end;
        
        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, 'maintenance', 0, ?, ?)");
        $stmt_log->execute([$id, $log_msg, $loc]);
        
        if ($old_status !== 'maintenance') {
            $stmt_up = $pdo->prepare("UPDATE monitors SET status = 'maintenance', last_checked = NOW(), last_status_change = NOW(), last_details = NULL WHERE id = ?");
            $stmt_up->execute([$id]);
            
            // Odeslat upozornění o zahájení údržby
            trigger_notifications($pdo, $monitor, 'maintenance', $log_msg);
        } else {
            $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW() WHERE id = ?");
            $stmt_up->execute([$id]);
        }
        continue;
    }
    
    // Agent-side kontrola (služby na LAN, z hostingu nedosažitelné): stav
    // zapisuje agent_api při reportu agenta. Cron tu hlídá jen čerstvost -
    // když výsledky přestanou chodit (agent mlčí), monitor nesmí věčně
    // svítit poslední známou barvou. 'unknown' se do SLA nepočítá.
    if ($type === 'agent_service') {
        $offline_secs = intval(get_setting('agent_offline_timeout', '50')) * 60;
        $last_checked_ts = $monitor['last_checked'] ? strtotime($monitor['last_checked']) : 0;
        if ($offline_secs > 0 && (time() - $last_checked_ts) > $offline_secs && $monitor['status'] !== 'unknown') {
            $stmt_up = $pdo->prepare("UPDATE monitors SET status = 'unknown', last_status_change = NOW() WHERE id = ?");
            $stmt_up->execute([$id]);
            $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, 'unknown', NULL, ?, 'Agent')");
            $stmt_log->execute([$id, 'Zdrojový agent přestal posílat výsledky kontroly - stav služby není známý.']);
            echo "UNKNOWN (agent-side kontrola bez čerstvých výsledků)\n";
        } else {
            echo "OK (kontrolu provádí agent)\n";
        }
        continue;
    }

    $check_result = [
        'status' => 'unknown',
        'response_time' => 0,
        'error' => null
    ];
    
    // Pasivní kontrola VPS/OpenWrt zátěže (agenta) - obojí čeká na push z
    // agenta, žádná aktivní síťová kontrola tu neprobíhá.
    if ($type === 'vps' || $type === 'openwrt') {
        // Časový limit 0 = detekce neaktivity je vypnutá - monitor zůstává v posledním nahlášeném stavu
        if ($offline_timeout_mins === 0) {
            echo "OK (Detekce neaktivity vypnuta)\n";
        } elseif ($old_status !== 'down') {
            $details_arr = json_decode($monitor['last_details'] ?? '{}', true);
            // Pokud agent ještě nikdy nehlásil, počítáme timeout od vytvoření
            // monitoru (ne od epochy 1970) - nový monitor tak dostane stejnou
            // "grace" dobu (offline_timeout_mins), než ho poprvé označíme jako
            // DOWN, jako monitor, kterému agent přestal hlásit po prvním hlášení.
            $last_report = $details_arr['agent_last_seen']
                ?? ($monitor['last_checked'] ? strtotime($monitor['last_checked']) : null)
                ?? (!empty($monitor['created_at']) ? strtotime($monitor['created_at']) : 0);

            $offline_timeout_secs = $offline_timeout_mins * 60;
            $timeout_threshold = time() - $offline_timeout_secs;

            if ($last_report < $timeout_threshold) {
                // Agent neodpovídá
                $new_status = 'down';
                $last_report_str = $last_report > 0 ? date('d.m.Y H:i', intval($last_report)) : 'nikdy';
                $error_msg = "VPS Agent neodpovídá déle než {$offline_timeout_mins} minut (poslední hlášení: {$last_report_str}). "
                    . "Zkontrolujte, zda na VPS běží cron úloha agenta a zda je server dostupný.";
                
                // Update monitoru
                $stmt_up = $pdo->prepare("UPDATE monitors SET status = ?, last_status_change = NOW() WHERE id = ?");
                $stmt_up->execute([$new_status, $id]);
                
                // Log
                $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, error_message) VALUES (?, ?, ?)");
                $stmt_log->execute([$id, $new_status, $error_msg]);
                
                // Odeslání notifikace
                trigger_notifications($pdo, $monitor, $new_status, $error_msg);
                echo "DOWN (Agent neodpovídá)\n";
            } else {
                echo "OK (Agent je aktivní)\n";
            }
        } else {
            echo "DOWN (Čeká na hlášení agenta)\n";
        }
        continue;
    }
    
    // Aktivní kontroly z hostingu podle typu
    switch ($type) {
        case 'web':
            $check_result = check_http($target, $timeout, $monitor['body_keyword'] ?? null);
            detect_config_changes($pdo, $monitor, $check_result);
            
            // Kontrola expirace SSL certifikátu oproti konfiguraci ssl_alert_days
            if (isset($check_result['check_stages']['tls']['cert']['days_remaining'])) {
                $days_rem = (int)$check_result['check_stages']['tls']['cert']['days_remaining'];
                $ssl_threshold = (int)get_setting('ssl_alert_days', '14');
                if ($days_rem <= $ssl_threshold && $days_rem >= 0) {
                    $ssl_msg = "SSL certifikát pro {$target} vyprší za {$days_rem} dní! Obnovte certifikát včas.";
                    trigger_notifications($pdo, $monitor, 'up', $ssl_msg);
                }
            }
            break;
            
        case 'cpanel':
            $check_result = check_cpanel($target, $timeout);
            break;
            
        case 'port':
            $check_result = check_socket($target, $port ?: 80, $timeout);
            break;
            
        case 'minecraft':
            $check_result = check_minecraft(
                $target,
                $port ?: 25565,
                $timeout,
                $monitor['rcon_port'] ?? null,
                $monitor['rcon_password'] ?? null
            );
            break;
            
        case 'teamspeak':
            $check_result = check_teamspeak(
                $target,
                $port ?: 10011,
                $timeout,
                $monitor['sq_username'] ?? null,
                $monitor['sq_password'] ?? null,
                $monitor['ts3_filetransfer_port'] ?? null
            );
            break;
            
        case 'discord':
            $check_result = check_discord($target, $timeout);
            break;

        default:
            // Typ bez aktivní kontroly (např. starší import z Service
            // Discovery s typem 'dns'). Dřív tudy každou minutu propadl
            // výchozí 'unknown' výsledek až do monitor_logs a SLA report
            // pak monitoru ukazoval 0 % uptime, přestože ho nikdy nikdo
            // nekontroloval. Bez měření žádný zápis.
            $stmt_skip = $pdo->prepare("UPDATE monitors SET last_checked = NOW() WHERE id = ?");
            $stmt_skip->execute([$id]);
            echo "SKIP (typ '{$type}' nemá aktivní kontrolu)\n";
            continue 2;
    }

    // Okamžitý druhý pokus při selhání - jediný přechodný 5s timeout (síťový
    // hiccup, chvilkové přetížení sdíleného hostingu) dřív okamžitě překlopil
    // stav na down a vystřelil notifikace (WhatsApp/SMS/e-mail), aby se za
    // minutu vše vrátilo s druhou notifikací. Skutečný výpadek selže i
    // napodruhé; blip tímhle zmizí úplně a žádná notifikace neodejde.
    if (($check_result['status'] ?? '') === 'down') {
        sleep(2);
        $retry_result = null;
        switch ($type) {
            case 'web':
                $retry_result = check_http($target, $timeout, $monitor['body_keyword'] ?? null);
                break;
            case 'cpanel':
                $retry_result = check_cpanel($target, $timeout);
                break;
            case 'port':
                $retry_result = check_socket($target, $port ?: 80, $timeout);
                break;
            case 'minecraft':
                $retry_result = check_minecraft($target, $port ?: 25565, $timeout, $monitor['rcon_port'] ?? null, $monitor['rcon_password'] ?? null);
                break;
            case 'teamspeak':
                $retry_result = check_teamspeak($target, $port ?: 10011, $timeout, $monitor['sq_username'] ?? null, $monitor['sq_password'] ?? null, $monitor['ts3_filetransfer_port'] ?? null);
                break;
            case 'discord':
                $retry_result = check_discord($target, $timeout);
                break;
        }
        if ($retry_result !== null && ($retry_result['status'] ?? '') === 'up') {
            echo "RETRY OK (první pokus selhal: " . ($check_result['error'] ?? '?') . ")\n";
            $check_result = $retry_result;
        }
    }

    // cPanel fallback pro web monitory: když HTTP kontrola selže i napodruhé,
    // ale cPanel exporter na stejném hostingu normálně odpovídá, server žije -
    // problém je v HTTP cestě (Cloudflare, PHP-FPM apod.), ne v celém stroji.
    // Zapíše se down s doplněnou informací, ale stejná logika jako agent
    // fallback níže se tady záměrně NEaplikuje - web, který nevrací stránky,
    // JE nedostupný pro návštěvníky, jen chceme do notifikace přesnější kontext.
    if (($check_result['status'] ?? '') === 'down' && $type === 'web' && !empty($monitor['cpanel_stats_url'])) {
        $cp_probe = check_cpanel($monitor['cpanel_stats_url'], min($timeout, 5));
        if (($cp_probe['status'] ?? '') === 'up') {
            $check_result['error'] = ($check_result['error'] ?? 'Web nedostupný')
                . ' | Server samotný běží (cPanel exporter odpovídá) - problém je pravděpodobně v HTTP vrstvě/CDN, ne ve stroji.';
        }
    }

    $new_status = $check_result['status'];
    $response_time = $check_result['response_time'];
    $error_msg = $check_result['error'];
    $details = null;
    
    // ZÁLOŽNÍ FALLBACK: Pokud aktivní kontrola selže, zkusíme se dotázat na data z lokálně běžícího VPS agenta
    if ($new_status === 'down') {
        $details_decoded = json_decode($monitor['last_details'] ?? '{}', true);
        $agent_last_seen = $details_decoded['agent_last_seen'] ?? 0;
        $offline_timeout_mins = intval(get_setting('agent_offline_timeout', '50'));
        $offline_timeout_secs = $offline_timeout_mins * 60;
        
        if ($agent_last_seen > 0 && (time() - $agent_last_seen) < $offline_timeout_secs) {
            $fallback_success = false;
            
            if ($type === 'teamspeak') {
                $ports = $details_decoded['ports'] ?? [];
                $voice_port = 9987;
                $parts = explode(':', $target);
                if (count($parts) === 2) {
                    $voice_port = intval($parts[1]);
                }
                $query_port = $port ?: 10011;
                
                $ts_process_ok = true;
                if (!empty($monitor['monitored_processes'])) {
                    $missing = $details_decoded['missing_processes'] ?? [];
                    foreach ($missing as $m_proc) {
                        if (stripos($m_proc, 'ts3server') !== false || stripos($m_proc, 'ts3') !== false) {
                            $ts_process_ok = false;
                        }
                    }
                }
                
                if (in_array($voice_port, $ports) || in_array($query_port, $ports) || ($ts_process_ok && !empty($monitor['monitored_processes']))) {
                    $fallback_success = true;
                }
            } elseif ($type === 'minecraft') {
                $ports = $details_decoded['ports'] ?? [];
                $mc_port = $port ?: 25565;
                
                $mc_process_ok = true;
                if (!empty($monitor['monitored_processes'])) {
                    $missing = $details_decoded['missing_processes'] ?? [];
                    foreach ($missing as $m_proc) {
                        if (stripos($m_proc, 'minecraft') !== false || stripos($m_proc, 'java') !== false) {
                            $mc_process_ok = false;
                        }
                    }
                }
                
                if (in_array($mc_port, $ports) || ($mc_process_ok && !empty($monitor['monitored_processes']))) {
                    $fallback_success = true;
                }
            } elseif ($type === 'web') {
                $ports = $details_decoded['ports'] ?? [];
                if (in_array(80, $ports) || in_array(443, $ports)) {
                    $fallback_success = true;
                }
            }
            
            if ($fallback_success) {
                $new_status = 'up';
                $error_msg = 'Používá se záložní API (přímé TCP spojení selhalo)';
                $details_decoded['api_fallback'] = true;
                $details_decoded['last_error'] = $check_result['error'];
                
                if ($type === 'teamspeak' && isset($details_decoded['ts3_clients_online'])) {
                    $details_decoded['clients_online'] = $details_decoded['ts3_clients_online'];
                    $details_decoded['clients_max'] = $details_decoded['ts3_clients_max'];
                    $details_decoded['name'] = !empty($details_decoded['ts3_name']) ? $details_decoded['ts3_name'] : ($monitor['name'] ?: 'TeamSpeak Server');
                }
                
                $details = json_encode($details_decoded, JSON_UNESCAPED_UNICODE);
            }
        }
    }
    
    // Sestavení detailních informací pro specifické typy
    if ($details === null && $new_status === 'up') {
        if ($type === 'minecraft') {
            $details = json_encode([
                // Nezjisteny pocet hracu neni nula (stejne jako u Discordu).
                'players_online' => $check_result['players_online'] ?? null,
                'players_max' => $check_result['players_max'] ?? null,
                'version' => $check_result['version'] ?? '',
                'players_list' => $check_result['players_list'] ?? [],
                'motd' => $check_result['motd'] ?? '',
                'api_fallback' => $check_result['api_fallback'] ?? false,
                'tps_1m' => $check_result['tps_1m'] ?? null,
                'tps_5m' => $check_result['tps_5m'] ?? null,
                'tps_15m' => $check_result['tps_15m'] ?? null
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'teamspeak') {
            $details = json_encode([
                // Nezjištěný počet hráčů není nula - prázdný server a
                // neúspěšný dotaz jsou dvě různé věci.
                'clients_online' => $check_result['clients_online'] ?? null,
                'clients_max' => $check_result['clients_max'] ?? null,
                // Nezjistene jmeno serveru zustava null - UI pak ukaze nazev monitoru,
                // misto aby tvrdilo genericke "TeamSpeak Server".
                'name' => $check_result['name'] ?? null,
                'version' => $check_result['version'] ?? '',
                'checked_ip' => $check_result['checked_ip'] ?? '',
                'ip_version' => $check_result['ip_version'] ?? 'IPv4',
                'api_fallback' => false
            ], JSON_UNESCAPED_UNICODE);

            $ts3_agent_details = json_decode($monitor['last_details'] ?? '', true);
            if (!is_array($ts3_agent_details)) $ts3_agent_details = [];
            bk_enrich_monitor_details($pdo, $monitor, $ts3_agent_details);
            $ts3_process_cpu = null;
            $ts3_process_ram = null;
            // Bez metrik od agenta zůstává NULL - fiktivní nuly by v přehledu
            // vypadaly jako naprosto nezatížený stroj.
            $ts3_host_cpu = null;
            $ts3_host_ram = null;
            $ts3_host_hdd = null;
            if (is_array($ts3_agent_details)) {
                $ts3_host_cpu = $ts3_agent_details['cpu'] ?? null;
                $ts3_host_ram = $ts3_agent_details['ram'] ?? null;
                $ts3_host_hdd = $ts3_agent_details['hdd'] ?? null;
                if (isset($ts3_agent_details['ts3_process']) && is_array($ts3_agent_details['ts3_process'])) {
                    $ts3_process_cpu = $ts3_agent_details['ts3_process']['cpu'] ?? null;
                    $ts3_process_ram = $ts3_agent_details['ts3_process']['ram_mb'] ?? null;
                }
            }
            $stmt_ts3_metrics = $pdo->prepare("
                INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, ts_clients_online, ts_clients_max, ts_process_cpu, ts_process_ram)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_ts3_metrics->execute([
                $id, $ts3_host_cpu, $ts3_host_ram, $ts3_host_hdd,
                $check_result['clients_online'] ?? null, $check_result['clients_max'] ?? null,
                $ts3_process_cpu, $ts3_process_ram,
            ]);
        } elseif ($type === 'discord') {
            $details = json_encode([
                // Nezjištěný počet online NENÍ nula - selhaný dotaz a prázdný
                // server jsou dvě různé věci.
                'presence_count' => $check_result['presence_count'] ?? null,
                'name' => $check_result['name'] ?? null,
                'instant_invite' => $check_result['instant_invite'] ?? null,
                'voice_channels' => $check_result['voice_channels'] ?? [],
                'members' => $check_result['members'] ?? [],
                'api_fallback' => false
            ], JSON_UNESCAPED_UNICODE);

            // Počet online se dosud ukládal jen jako aktuální snímek v details,
            // takže Discord neměl žádný graf kromě odezvy - data se sbírala
            // každou minutu a hned zahazovala. Ukládá se do stejného sloupce,
            // jaký používá TeamSpeak (kolik lidí je online).
            if (isset($check_result['presence_count']) && $check_result['presence_count'] !== null) {
                try {
                    $stmt_dc = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, ts_clients_online) VALUES (?, ?)");
                    $stmt_dc->execute([$id, (int)$check_result['presence_count']]);
                } catch (Throwable $e) {
                    error_log('[cron] Discord presence metric skipped: ' . $e->getMessage());
                }
            }
        } elseif ($type === 'web') {
            $details_arr = [
                'has_ipv4' => $check_result['has_ipv4'] ?? false,
                'has_ipv6' => $check_result['has_ipv6'] ?? false,
                'primary_ip' => $check_result['primary_ip'] ?? '',
                'scheme' => $check_result['scheme'] ?? 'HTTP',
                'http_version' => $check_result['http_version'] ?? 'HTTP/1.1',
                'api_fallback' => false
            ];

            // The cert details live in check_stages.tls.cert (built by check_http()),
            // not at the top level of $check_result - reading ssl_days_remaining
            // directly here never matched anything, so this block never ran.
            $ssl_cert_info = $check_result['check_stages']['tls']['cert'] ?? null;
            if (is_array($ssl_cert_info) && isset($ssl_cert_info['days_remaining'])) {
                $days = (int)$ssl_cert_info['days_remaining'];
                $details_arr['ssl_days_remaining'] = $days;
                $details_arr['ssl_issuer'] = $ssl_cert_info['issuer'] ?? null;
                $details_arr['ssl_valid_to'] = $ssl_cert_info['valid_to'] ?? null;
                if ($days <= 14) {
                    // Read the previous warn timestamp from the monitor's last stored
                    // details, not from $details_arr (which is rebuilt fresh every run
                    // and would otherwise always read 0, spamming this alert hourly).
                    // $details_decoded is only populated on the down-path above, so
                    // decode last_details fresh here rather than relying on it.
                    $prev_details = json_decode($monitor['last_details'] ?? '{}', true);
                    $last_ssl_warn = (is_array($prev_details) ? $prev_details['last_ssl_warn'] ?? 0 : 0);
                    if (time() - $last_ssl_warn > 86400) {
                        $details_arr['last_ssl_warn'] = time();
                        trigger_notifications($pdo, $monitor, 'ssl_expiring', "SSL certifikát pro '{$name}' vyprší za {$days} dní!");
                        log_monitor_event($pdo, $id, $name, $type, 'ssl_warning', "SSL certifikát vyprší za {$days} dní");
                    } else {
                        $details_arr['last_ssl_warn'] = $last_ssl_warn;
                    }
                }
            }
            
            if (!empty($monitor['cpanel_stats_url'])) {
                $cp_res = check_cpanel($monitor['cpanel_stats_url'], $timeout);
                if ($cp_res['status'] === 'up') {
                    $details_arr['cpanel_stats'] = [
                        'disk' => $cp_res['disk'] ?? null,
                        'memory' => $cp_res['memory'] ?? null,
                        'processes' => $cp_res['processes'] ?? null,
                        'database' => $cp_res['database'] ?? null,
                        'bandwidth' => $cp_res['bandwidth'] ?? null,
                        'postgresql' => $cp_res['postgresql'] ?? null,
                        'cpu' => $cp_res['cpu'] ?? null
                    ];
                    $details_arr['cpanel_stats_error'] = null;

                    // Uložit do vps_metrics pro historii grafů. Chybějící metrika
                    // se ukládá jako NULL, ne 0.0 - nula je reálná hodnota a
                    // vymyšlená nula by v grafech vypadala jako "server se fláká"
                    // (StatsBar bez CloudLinux např. cpuusage vůbec nevrací).
                    $cpu_val = isset($cp_res['cpu']['percent']) ? floatval($cp_res['cpu']['percent']) : null;
                    $ram_val = isset($cp_res['memory']['percent']) ? floatval($cp_res['memory']['percent']) : null;
                    $hdd_val = isset($cp_res['disk']['percent']) ? floatval($cp_res['disk']['percent']) : null;
                    $stmt_metrics = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage) VALUES (?, ?, ?, ?)");
                    $stmt_metrics->execute([$id, $cpu_val, $ram_val, $hdd_val]);
                } else {
                    // Selhání sběru cPanel statistik dřív jen tiše přeskočilo zápis -
                    // data zmizela bez jediné stopy (přesně tak umřel sběr 21.7.,
                    // když deploy přepsal na serveru ručně vložený STATS_KEY).
                    // Chyba se teď ukládá do details, aby byla vidět v UI/API;
                    // `since` drží začátek výpadku napříč běhy cronu a `hint`
                    // říká adminovi, jak KONKRÉTNĚ tenhle druh selhání opravit.
                    $cp_http = $cp_res['http_code'] ?? null;
                    if ($cp_http === 404) {
                        $cp_hint = t('cpanel_hint_404');
                    } elseif ($cp_http === 403) {
                        $cp_hint = t('cpanel_hint_403');
                    } elseif ($cp_http === 0) {
                        $cp_hint = t('cpanel_hint_conn');
                    } elseif ($cp_http === 200) {
                        $cp_hint = t('cpanel_hint_json');
                    } else {
                        $cp_hint = null;
                    }
                    $prev_cp_details = json_decode($monitor['last_details'] ?? '{}', true);
                    $prev_cp_since = is_array($prev_cp_details) ? ($prev_cp_details['cpanel_stats_error']['since'] ?? null) : null;
                    $details_arr['cpanel_stats_error'] = [
                        'error' => $cp_res['error'] ?? 'Neznámá chyba',
                        'hint' => $cp_hint,
                        'since' => $prev_cp_since ?? date('c'),
                    ];
                }
            }
            
            $details = json_encode($details_arr, JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'cpanel') {
            $details = json_encode([
                'disk' => $check_result['disk'] ?? null,
                'memory' => $check_result['memory'] ?? null,
                'processes' => $check_result['processes'] ?? null,
                'database' => $check_result['database'] ?? null,
                'bandwidth' => $check_result['bandwidth'] ?? null,
                'postgresql' => $check_result['postgresql'] ?? null,
                'cpu' => $check_result['cpu'] ?? null
            ], JSON_UNESCAPED_UNICODE);
            
            if ($new_status === 'up') {
                // NULL pro chybějící metriky - stejný důvod jako u web monitorů výše.
                $cpu_val = isset($check_result['cpu']['percent']) ? floatval($check_result['cpu']['percent']) : null;
                $ram_val = isset($check_result['memory']['percent']) ? floatval($check_result['memory']['percent']) : null;
                $hdd_val = isset($check_result['disk']['percent']) ? floatval($check_result['disk']['percent']) : null;
                $stmt_metrics = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage) VALUES (?, ?, ?, ?)");
                $stmt_metrics->execute([$id, $cpu_val, $ram_val, $hdd_val]);
            }
        }
    }
    
    // Sjednotit staré detaily (např. z VPS agenta) s novými z aktivní kontroly
    if ($details !== null) {
        $old_details = json_decode($monitor['last_details'] ?? '{}', true);
        if (!is_array($old_details)) {
            $old_details = [];
        }
        $new_details_arr = json_decode($details, true);
        if (is_array($new_details_arr)) {
            $merged_details_arr = array_merge($old_details, $new_details_arr);
            // Otisk verze nasazení do details - diagnostika, KTERÝ soubor cron.php
            // reálně běží. FTP deploy porovnává jen proti vlastnímu stavovému
            // souboru, takže ručně přepsaný soubor na serveru (nebo cron job
            // mířící na starou kopii mimo public_html/status/) z gitu nepoznáme
            // jinak než touhle stopou v datech.
            @include_once __DIR__ . '/version.php';
            $merged_details_arr['cron_version'] = defined('APP_VERSION_HASH') ? APP_VERSION_HASH : 'dev';
            $details = json_encode($merged_details_arr, JSON_UNESCAPED_UNICODE);
        }
    } else {
        $details = $monitor['last_details'];
    }
    
    // Zapsat výsledek do historie logů
    $loc = get_setting('cron_location', '');
    // Pokud je nastaveno AUTO, prázdné nebo zbývá výchozí Praha fallback → použít auto-detekovanou lokaci
    $loc_is_auto = empty($loc) || $loc === 'AUTO' || $loc === '🇨🇿 Praha, CZ';
    if ($loc_is_auto) {
        $loc = get_setting('ip_loc_local', '');
        if (empty($loc)) {
            $loc = detect_server_location();
            // Uložíme do settings cache
            $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
            $stmt_set->execute(['ip_loc_local', $loc, $loc]);
        }
    }
    // check_stages (rozpad DNS/TCP/TLS/HTTP/body fází u 'web', ServerQuery/service/
    // ports/license u 'teamspeak') existuje jen u těchto dvou typů - u ostatních je
    // vždy null, žádná změna chování pro ně.
    $check_stages_json = (in_array($type, ['web', 'teamspeak'], true) && isset($check_result['check_stages']))
        ? json_encode($check_result['check_stages'], JSON_UNESCAPED_UNICODE)
        : null;
    $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from, check_stages) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_log->execute([$id, $new_status, $response_time, $error_msg, $loc, $check_stages_json]);
    
    // Zjistit změnu stavu
    if ($old_status !== $new_status) {
        // Uložit nový stav a čas změny
        $stmt_up = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
        $stmt_up->execute([$new_status, $details, $id]);
        
        // Odeslat notifikace o změně stavu
        trigger_notifications($pdo, $monitor, $new_status, $error_msg);
        echo "ZMĚNA STAVU -> " . strtoupper($new_status) . " (Odezva: {$response_time}ms)\n";
    } else {
        // Pouze aktualizovat čas poslední kontroly
        $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
        $stmt_up->execute([$details, $id]);
        echo strtoupper($new_status) . " (Odezva: {$response_time}ms)\n";
    }
}

// Vyčištění starých logů (starších než 30 dní) kvůli úspoře místa v DB
try {
    $pdo->exec("DELETE FROM monitor_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $pdo->exec("DELETE FROM vps_metrics WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    // Audit log: delší retence (90 dní) - bezpečnostní záznamy
    $pdo->exec("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    echo "Vyčištění starých dat dokončeno.\n";
} catch (PDOException $e) {
    echo "Chyba při čištění starých logů: " . $e->getMessage() . "\n";
}

// Invalidace cache dashboardu - po doběhu kontrol se agregace přepočítají s čerstvými daty
@unlink(__DIR__ . '/cache/dashboard_agg.json');

// Kontrola a odeslání pravidelných digestů (týdenní v pondělí, měsíční 1. v měsíci)
try {
    $today_day = date('w'); // 0 (Sun) - 6 (Sat)
    $today_date = date('j'); // 1 - 31
    $current_hour = (int)date('G');
    
    // Týdenní digest – každé pondělí (day 1) mezi 08:00 a 12:00
    if ($today_day == 1 && $current_hour >= 8 && $current_hour < 12) {
        $last_weekly = get_setting('last_weekly_digest_sent', '');
        $current_week = date('Y-W');
        if ($last_weekly !== $current_week) {
            echo "Odesílám týdenní digest...\n";
            if (send_digest_report($pdo, 'weekly')) {
                $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('last_weekly_digest_sent', ?) ON DUPLICATE KEY UPDATE key_value = ?");
                $stmt_set->execute([$current_week, $current_week]);
                echo "Týdenní digest odeslán.\n";
            }
        }
    }
    
    // Měsíční digest – 1. den v měsíci mezi 08:00 a 12:00
    if ($today_date == 1 && $current_hour >= 8 && $current_hour < 12) {
        $last_monthly = get_setting('last_monthly_digest_sent', '');
        $current_month = date('Y-m');
        if ($last_monthly !== $current_month) {
            echo "Odesílám měsíční digest...\n";
            if (send_digest_report($pdo, 'monthly')) {
                $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('last_monthly_digest_sent', ?) ON DUPLICATE KEY UPDATE key_value = ?");
                $stmt_set->execute([$current_month, $current_month]);
                echo "Měsíční digest odeslán.\n";
            }
        }
    }
} catch (Exception $e) {
    echo "Chyba při automatickém odesílání digestů: " . $e->getMessage() . "\n";
}

echo "Kontrola dokončena.\n";
