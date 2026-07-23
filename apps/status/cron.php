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
                'players_online' => $check_result['players_online'] ?? 0,
                'players_max' => $check_result['players_max'] ?? 0,
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
                'clients_online' => $check_result['clients_online'] ?? 0,
                'clients_max' => $check_result['clients_max'] ?? 0,
                'name' => $check_result['name'] ?? 'TeamSpeak Server',
                'version' => $check_result['version'] ?? '',
                'checked_ip' => $check_result['checked_ip'] ?? '',
                'ip_version' => $check_result['ip_version'] ?? 'IPv4',
                'api_fallback' => false
            ], JSON_UNESCAPED_UNICODE);

            // Uložit klienty (a proces/host zátěž, pokud je na stejném VPS propojený
            // agent) do vps_metrics - podklad pro graf historie klientů/procesu.
            $ts3_agent_details = json_decode($monitor['last_details'] ?? '', true);
            $ts3_process_cpu = null;
            $ts3_process_ram = null;
            $ts3_host_cpu = 0;
            $ts3_host_ram = 0;
            $ts3_host_hdd = 0;
            if (is_array($ts3_agent_details)) {
                $ts3_host_cpu = $ts3_agent_details['cpu'] ?? 0;
                $ts3_host_ram = $ts3_agent_details['ram'] ?? 0;
                $ts3_host_hdd = $ts3_agent_details['hdd'] ?? 0;
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
                'presence_count' => $check_result['presence_count'] ?? 0,
                'name' => $check_result['name'] ?? 'Discord Server',
                'instant_invite' => $check_result['instant_invite'] ?? null,
                'voice_channels' => $check_result['voice_channels'] ?? [],
                'members' => $check_result['members'] ?? [],
                'api_fallback' => false
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($type === 'web') {
            $details_arr = [
                'has_ipv4' => $check_result['has_ipv4'] ?? false,
                'has_ipv6' => $check_result['has_ipv6'] ?? false,
                'primary_ip' => $check_result['primary_ip'] ?? '',
                'scheme' => $check_result['scheme'] ?? 'HTTP',
                'http_version' => $check_result['http_version'] ?? 'HTTP/1.1',
                'api_fallback' => false
            ];
            
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
                    
                    // Uložit do vps_metrics pro historii grafů
                    $cpu_val = isset($cp_res['cpu']['percent']) ? floatval($cp_res['cpu']['percent']) : 0.0;
                    $ram_val = isset($cp_res['memory']['percent']) ? floatval($cp_res['memory']['percent']) : 0.0;
                    $hdd_val = isset($cp_res['disk']['percent']) ? floatval($cp_res['disk']['percent']) : 0.0;
                    $stmt_metrics = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage) VALUES (?, ?, ?, ?)");
                    $stmt_metrics->execute([$id, $cpu_val, $ram_val, $hdd_val]);
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
                $cpu_val = isset($check_result['cpu']['percent']) ? floatval($check_result['cpu']['percent']) : 0.0;
                $ram_val = isset($check_result['memory']['percent']) ? floatval($check_result['memory']['percent']) : 0.0;
                $hdd_val = isset($check_result['disk']['percent']) ? floatval($check_result['disk']['percent']) : 0.0;
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
