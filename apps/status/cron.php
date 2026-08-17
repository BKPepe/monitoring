<?php
/**
 * Cron script running the periodic checks of monitored services
 * Recommended interval: every 1 to 5 minutes.
 * Invocation: php cron.php or curl https://status.bloodkings.eu/cron.php
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Runs only from the CLI or with the correct security key in the URL
$is_cli = (php_sapi_name() === 'cli' || !isset($_SERVER['HTTP_HOST']));
$cron_key = get_setting('cron_key', '');

if (!$is_cli && !empty($cron_key)) {
    if (!isset($_GET['key']) || $_GET['key'] !== $cron_key) {
        http_response_code(403);
        exit("Neoprávněný přístup ke cronu. Zadejte správný klíč '?key=...'");
    }
}

echo "Spouštím kontrolu monitorů... \n";

// Remember the run start for the write at the end (see `last_cron_run`).
$bk_cron_started = microtime(true);

// --- Schema self-test (once a day) --- catches missing columns before an agent hits the error
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
        // Database clock versus PHP clock.
        //
        // Several places compare a database-written time (NOW()) against a
        // PHP time (time()) - e.g. for agents that stopped reporting.
        // As long as both sides share a zone it works; once they drift apart
        // monitoring starts reporting outages that never happened and nobody
        // can tell why. Here it is only reported: changing the zone at runtime
        // would put a jump into already-stored data.
        $db_now = $pdo->query("SELECT NOW()")->fetchColumn();
        $skew = abs(strtotime((string)$db_now) - time());
        if ($skew > 120) {
            $warn_tz = sprintf(
                'ČAS: hodiny databáze a PHP se liší o %d s (DB: %s, PHP: %s). Vyhodnocení stáří dat na tom stojí.',
                $skew, $db_now, date('Y-m-d H:i:s')
            );
            error_log('[cron] ' . $warn_tz);
            echo "VAROVÁNÍ: $warn_tz\n";
        }

        $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('last_schema_check', NOW()) ON DUPLICATE KEY UPDATE key_value = NOW()")->execute();
    } catch (PDOException $e) {
        error_log('[cron] Schema check failed: ' . $e->getMessage());
    }
}

// Load all monitors
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
    // Timeout 0 = agent inactivity detection is fully disabled
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
            
            // Send the maintenance-start notification
            trigger_notifications($pdo, $monitor, 'maintenance', $log_msg);
        } else {
            $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW() WHERE id = ?");
            $stmt_up->execute([$id]);
        }
        continue;
    }
    
    // Agent-side check (LAN services unreachable from the hosting): the state
    // is written by agent_api on the agent's report. Cron only watches
    // freshness here - when results stop arriving (the agent is silent), the
    // monitor must not keep its last colour forever. 'unknown' does not count into SLA.
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

    // Heartbeat: the job reports itself to heartbeat.php, this only watches the
    // clock. Evaluation lives in bk_heartbeat_evaluate() so it can be tested
    // without a database and without waiting for a real delay.
    if ($type === 'heartbeat') {
        $hb = bk_heartbeat_evaluate($monitor);
        $new_status = $hb['status'];

        $hb_details = json_decode($monitor['last_details'] ?? '{}', true);
        if (!is_array($hb_details)) {
            $hb_details = [];
        }
        // Heartbeats measure no response time - at best one could measure signal
        // delay, which is something else. So response_time gets nothing (NULL),
        // definitely not a zero that would look like a lightning-fast server in charts.
        $hb_details['heartbeat'] = [
            'lastSignalAt' => $monitor['last_heartbeat'] ?? null,
            'ageSecs' => $hb['age_secs'],
            'deadlineSecs' => $hb['deadline_secs'],
            'overdueSecs' => $hb['overdue_secs'],
            'lastResult' => $monitor['heartbeat_last_result'] ?? null,
            'lastMessage' => $monitor['heartbeat_last_message'] ?? null,
        ];

        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, ?, NULL, ?, ?)");
        $stmt_log->execute([$id, $new_status, $hb['error'], 'Heartbeat']);

        $hb_details_json = json_encode($hb_details, JSON_UNESCAPED_UNICODE);

        if ($old_status !== $new_status) {
            $stmt_up = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
            $stmt_up->execute([$new_status, $hb_details_json, $id]);

            // 'unknown' is not an event worth waking a human for - it only means
            // the job has not reported yet. Notifications go out for up/down.
            if ($new_status === 'up' || $new_status === 'down') {
                trigger_notifications($pdo, $monitor, $new_status, $hb['error']);
            }
            echo "ZMĚNA STAVU -> " . strtoupper($new_status) . " (" . ($hb['error'] ?? 'signál dorazil včas') . ")\n";
        } else {
            $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
            $stmt_up->execute([$hb_details_json, $id]);
            echo strtoupper($new_status) . " (" . ($hb['error'] ?? 'signál dorazil včas') . ")\n";
        }
        continue;
    }

    $check_result = [
        'status' => 'unknown',
        'response_time' => 0,
        'error' => null
    ];
    
    // Passive VPS/OpenWrt load check (the agent) - both wait for the agent's
    // push, no active network check happens here.
    if ($type === 'vps' || $type === 'openwrt') {
        // Timeout 0 = inactivity detection is off - the monitor stays in its last reported state
        if ($offline_timeout_mins === 0) {
            echo "OK (Detekce neaktivity vypnuta)\n";
        } elseif ($old_status !== 'down') {
            $details_arr = json_decode($monitor['last_details'] ?? '{}', true);
            // If the agent has never reported, the timeout counts from the
            // monitor's creation (not from the 1970 epoch) - a new monitor gets
            // the same grace period (offline_timeout_mins) before its first
            // DOWN as a monitor whose agent went silent after its first report.
            $last_report = $details_arr['agent_last_seen']
                ?? ($monitor['last_checked'] ? strtotime($monitor['last_checked']) : null)
                ?? (!empty($monitor['created_at']) ? strtotime($monitor['created_at']) : 0);

            $offline_timeout_secs = $offline_timeout_mins * 60;
            $timeout_threshold = time() - $offline_timeout_secs;

            if ($last_report < $timeout_threshold) {
                // The agent is not responding
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
                
                // Send the notification
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
    
    // Active checks from the hosting, by type
    switch ($type) {
        case 'web':
            $check_result = check_http($target, $timeout, $monitor['body_keyword'] ?? null);
            detect_config_changes($pdo, $monitor, $check_result);
            
            // SSL certificate expiry check against the ssl_alert_days setting
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
            // A type with no active check (e.g. an older Service Discovery
            // import typed 'dns'). The default 'unknown' result used to fall
            // through here into monitor_logs every minute and the SLA report
            // then showed the monitor at 0 % uptime even though nobody ever
            // checked it. No measurement, no write.
            $stmt_skip = $pdo->prepare("UPDATE monitors SET last_checked = NOW() WHERE id = ?");
            $stmt_skip->execute([$id]);
            echo "SKIP (typ '{$type}' nemá aktivní kontrolu)\n";
            continue 2;
    }

    // Immediate retry on failure - a single transient 5s timeout (network
    // hiccup, momentary shared-hosting overload) used to flip the state
    // to down instantly and fire the notifications (WhatsApp/SMS/e-mail),
    // only for everything to come back a minute later with a second wave. A real
    // outage fails the retry too; a blip disappears entirely and nothing is sent.
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

    // cPanel fallback for web monitors: when the HTTP check fails twice but
    // the cPanel exporter on the same hosting responds normally, the server
    // is alive - the problem is in the HTTP path (Cloudflare, PHP-FPM, ...),
    // not the whole machine. A down is written with the extra context, but the
    // agent-fallback logic below deliberately does NOT apply - a web that
    // serves no pages IS down for visitors, we just want a more precise notification.
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
    
    // BACKUP FALLBACK: if the active check fails, ask the locally running VPS agent for data
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
    
    // Build the type-specific detail info
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

            // Pocet hracu se dosud ukladal jen jako aktualni snimek, takze
            // Minecraft nemel graf navstevnosti - stejny problem jako mel
            // Discord. Uklada se do sloupce pro "lidi online".
            if (isset($check_result['players_online']) && $check_result['players_online'] !== null) {
                try {
                    $stmt_mc = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, ts_clients_online, ts_clients_max) VALUES (?, ?, ?)");
                    $stmt_mc->execute([
                        $id,
                        (int)$check_result['players_online'],
                        isset($check_result['players_max']) ? (int)$check_result['players_max'] : null,
                    ]);
                } catch (Throwable $e) {
                    error_log('[cron] Minecraft player metric skipped: ' . $e->getMessage());
                }
            }
        } elseif ($type === 'teamspeak') {
            $details = json_encode([
                // An undetermined player count is not zero - an empty server and
                // a failed query are two different things.
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
            // Without agent metrics it stays NULL - fictional zeros would make
            // the overview look like a completely idle machine.
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
                // An unknown online count is NOT zero - a failed query and an
                // empty server are two different things.
                'presence_count' => $check_result['presence_count'] ?? null,
                'name' => $check_result['name'] ?? null,
                'instant_invite' => $check_result['instant_invite'] ?? null,
                'voice_channels' => $check_result['voice_channels'] ?? [],
                'members' => $check_result['members'] ?? [],
                'api_fallback' => false
            ], JSON_UNESCAPED_UNICODE);

            // The online count used to be stored only as a snapshot in details,
            // so Discord had no chart except latency - data was collected every
            // minute and thrown away. It goes into the same column TeamSpeak
            // uses (how many people are online).
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

                    // Store into vps_metrics for chart history. A missing metric is
                    // stored as NULL, not 0.0 - zero is a real value and an invented
                    // zero would read as "the server is slacking" in charts
                    // (StatsBar without CloudLinux returns no cpuusage at all).
                    $cpu_val = isset($cp_res['cpu']['percent']) ? floatval($cp_res['cpu']['percent']) : null;
                    $ram_val = isset($cp_res['memory']['percent']) ? floatval($cp_res['memory']['percent']) : null;
                    $hdd_val = isset($cp_res['disk']['percent']) ? floatval($cp_res['disk']['percent']) : null;
                    $stmt_metrics = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage) VALUES (?, ?, ?, ?)");
                    $stmt_metrics->execute([$id, $cpu_val, $ram_val, $hdd_val]);
                } else {
                    // A failed cPanel stats collection used to skip the write silently -
                    // data vanished without a trace (exactly how collection died on
                    // 21 Jul, when a deploy overwrote the hand-edited STATS_KEY).
                    // The error now goes into details so the UI/API can show it;
                    // `since` keeps the outage start across cron runs and `hint`
                    // tells the admin how to fix THIS particular failure.
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
                // NULL for missing metrics - same reason as for web monitors above.
                $cpu_val = isset($check_result['cpu']['percent']) ? floatval($check_result['cpu']['percent']) : null;
                $ram_val = isset($check_result['memory']['percent']) ? floatval($check_result['memory']['percent']) : null;
                $hdd_val = isset($check_result['disk']['percent']) ? floatval($check_result['disk']['percent']) : null;
                $stmt_metrics = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage) VALUES (?, ?, ?, ?)");
                $stmt_metrics->execute([$id, $cpu_val, $ram_val, $hdd_val]);
            }
        }
    }
    
    // Merge old details (e.g. from the VPS agent) with the fresh active-check ones
    if ($details !== null) {
        $old_details = json_decode($monitor['last_details'] ?? '{}', true);
        if (!is_array($old_details)) {
            $old_details = [];
        }
        $new_details_arr = json_decode($details, true);
        if (is_array($new_details_arr)) {
            $merged_details_arr = array_merge($old_details, $new_details_arr);
            // Deployment version stamp in details - diagnoses WHICH cron.php
            // file actually runs. The FTP deploy compares only against its own
            // state file, so a manually replaced file on the server (or a cron
            // job pointing at an old copy outside public_html/status/) is
            // invisible from git except through this trace in the data.
            @include_once __DIR__ . '/version.php';
            $merged_details_arr['cron_version'] = defined('APP_VERSION_HASH') ? APP_VERSION_HASH : 'dev';
            $details = json_encode($merged_details_arr, JSON_UNESCAPED_UNICODE);
        }
    } else {
        $details = $monitor['last_details'];
    }
    
    // Write the result into the log history
    $loc = get_setting('cron_location', '');
    // If set to AUTO, empty, or still the default Prague fallback -> use the auto-detected location
    $loc_is_auto = empty($loc) || $loc === 'AUTO' || $loc === '🇨🇿 Praha, CZ';
    if ($loc_is_auto) {
        $loc = get_setting('ip_loc_local', '');
        if (empty($loc)) {
            $loc = detect_server_location();
            // Store into the settings cache
            $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
            $stmt_set->execute(['ip_loc_local', $loc, $loc]);
        }
    }
    // check_stages (DNS/TCP/TLS/HTTP/body breakdown for 'web', ServerQuery/service/
    // ports/license for 'teamspeak') exists only for these two types - for the
    // rest it is always null, no behaviour change for them.
    $check_stages_json = (in_array($type, ['web', 'teamspeak'], true) && isset($check_result['check_stages']))
        ? json_encode($check_result['check_stages'], JSON_UNESCAPED_UNICODE)
        : null;
    $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from, check_stages) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_log->execute([$id, $new_status, $response_time, $error_msg, $loc, $check_stages_json]);
    
    // Detect a status change
    if ($old_status !== $new_status) {
        // Store the new status and the change time
        $stmt_up = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
        $stmt_up->execute([$new_status, $details, $id]);
        
        // Send the status-change notifications
        trigger_notifications($pdo, $monitor, $new_status, $error_msg);
        echo "ZMĚNA STAVU -> " . strtoupper($new_status) . " (Odezva: {$response_time}ms)\n";
    } else {
        // Only update the last-check time
        $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
        $stmt_up->execute([$details, $id]);
        echo strtoupper($new_status) . " (Odezva: {$response_time}ms)\n";
    }

    // --- Persistently degraded latency -----------------------------------
    //
    // Runs after the log write so the just-finished check falls into the window.
    // Evaluated only for running services - for those that are down, a
    // "slow response" is nonsense and the outage alert already went out.
    if ($new_status === 'up') {
        $lat_details = json_decode($details ?: '{}', true);
        if (!is_array($lat_details)) {
            $lat_details = [];
        }
        $lat_alert_sent = !empty($lat_details['latency_alert_sent']);
        $lat = bk_evaluate_latency($pdo, $monitor, $lat_alert_sent);

        if ($lat['state'] === 'degraded' || $lat['state'] === 'recovered') {
            $lat_details['latency_alert_sent'] = ($lat['state'] === 'degraded');
            // Sem se dostaneme jen s nastavenym prahem (bk_evaluate_latency
            // vraci 'ok', kdyz je NULL), takze zadny fallback nema smysl.
            $threshold_ms = (int)$monitor['latency_threshold_ms'];
            $window_mins = (int)($monitor['latency_threshold_mins'] ?? 5);

            if ($lat['state'] === 'degraded') {
                $lat_msg = sprintf(
                    "Odezva '%s' je %s ms a drží se nad limitem %d ms už %d minut (%d kontrol). Služba odpovídá, ale výrazně pomaleji než obvykle.",
                    $name, $lat['avg_ms'], $threshold_ms, $window_mins, $lat['checks']
                );
                log_monitor_event($pdo, $id, $name, $type, 'latency_degraded', $lat_msg);
            } else {
                $lat_msg = sprintf(
                    "Odezva '%s' se vrátila pod limit %d ms (aktuálně průměr %s ms).",
                    $name, $threshold_ms, $lat['avg_ms']
                );
                log_monitor_event($pdo, $id, $name, $type, 'latency_recovered', $lat_msg);
            }

            trigger_notifications($pdo, $monitor, 'latency_' . $lat['state'], $lat_msg);

            $stmt_lat = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
            $stmt_lat->execute([json_encode($lat_details, JSON_UNESCAPED_UNICODE), $id]);
            echo "  ODEZVA -> " . strtoupper($lat['state']) . " ({$lat['avg_ms']} ms)\n";
        }
    }
}

// Daily rollups must be recomputed BEFORE pruning - otherwise data deleted
// a moment later would never make it into the long-term history.
//
// The first pass walks the whole retained range (31 days) so history already
// in the DB is not thrown away; afterwards the last 5 days suffice, which
// also covers a few days of cron downtime. The flag lives in settings, not in
// db.php - the function is not loaded there yet (functions.php comes after it).
$backfill_done = get_setting('uptime_daily_backfilled', '');
$rollup_days = $backfill_done === '1' ? 5 : 31;
$rolled = bk_rollup_daily_uptime($pdo, $rollup_days);

// Daily aggregation of agent metrics. Not every minute: it is 26 queries
// (one per metric) over the same window, so a half-hour interval is
// plenty - the window is five days, so even a few days of cron downtime
// loses nothing.
try {
    $last_metrics_rollup = get_setting('last_metrics_rollup', '');
    $metrics_due = $last_metrics_rollup === '' || strtotime($last_metrics_rollup) < time() - 1800;
    if ($metrics_due) {
        // Its own flag, not the availability one. The uptime backfill ran long
        // ago, so $rollup_days is 5 - and the metric aggregation, which only
        // starts now, would process just the last five of the existing 30 days.
        // Retention would silently delete the rest before anyone aggregated it.
        $metrics_backfilled = get_setting('metrics_daily_backfilled', '');
        $metric_days = $metrics_backfilled === '1' ? 5 : 31;

        $metric_rows = bk_rollup_daily_metrics($pdo, $metric_days);

        if ($metrics_backfilled !== '1') {
            $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('metrics_daily_backfilled', '1') ON DUPLICATE KEY UPDATE key_value = '1'")
                ->execute();
        }
        $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('last_metrics_rollup', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)")
            ->execute([date('Y-m-d H:i:s')]);
        echo "Denní agregace metrik: {$metric_rows} řádků.\n";
    }
} catch (Throwable $e) {
    error_log('[cron] Agregace metrik selhala: ' . $e->getMessage());
}
if ($backfill_done !== '1') {
    try {
        $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('uptime_daily_backfilled', '1') ON DUPLICATE KEY UPDATE key_value = '1'")
            ->execute();
    } catch (Throwable $e) {
        // Bez priznaku se backfill priste zopakuje - je idempotentni.
    }
}
echo "Denní souhrny dostupnosti: {$rolled} zápisů (okno {$rollup_days} dní).\n";

// Prune old logs (older than 30 days) to save DB space
try {
    $pdo->exec("DELETE FROM monitor_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $pdo->exec("DELETE FROM vps_metrics WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    // Audit log: longer retention (90 days) - security records
    $pdo->exec("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    echo "Vyčištění starých dat dokončeno.\n";

    // --- Process history --------------------------------------------------
    //
    // Retention is configurable because this table grows fastest of all
    // (ten rows per monitor per minute). The default 30 days comes to roughly
    // 1.7 million rows and 250 MB with four agents - measured, not estimated.
    //
    // Optional thinning keeps only peak samples after the configured days.
    // Those carry kept_reason='peak', so "nothing really happened here" can
    // be told apart from "we deleted this".
    $proc = bk_prune_process_samples(
        $pdo,
        max(0, (int)get_setting('process_history_days', '30')),
        max(0, (int)get_setting('process_history_peak_after_days', '0')),
        (float)max(0, (int)get_setting('process_history_peak_pct', '50'))
    );
    if ($proc['disabled']) {
        echo "Historie procesů: vypnutá, smazáno {$proc['deleted']} řádků.\n";
    } else {
        echo "Historie procesů: smazáno {$proc['deleted']} po retenci, prořezáno {$proc['pruned']}"
            . " (ponecháno {$proc['marked']} špiček).\n";
    }
} catch (PDOException $e) {
    echo "Chyba při čištění starých logů: " . $e->getMessage() . "\n";
}

// Invalidate the dashboard cache - after the checks, aggregations recompute with fresh data
@unlink(__DIR__ . '/cache/dashboard_agg.json');

// Check and send the scheduled digests (weekly on Monday, monthly on the 1st)
try {
    $today_day = date('w'); // 0 (Sun) - 6 (Sat)
    $today_date = date('j'); // 1 - 31
    $current_hour = (int)date('G');
    
    // Weekly digest - every Monday (day 1) between 08:00 and 12:00
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
    
    // Monthly digest - 1st day of the month between 08:00 and 12:00
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

// --- Escalation of unacknowledged incidents -------------------------------
//
// The outage notification used to go out once and that was it. If nobody saw
// it, the outage kept running and monitoring considered its job done. This is
// the safety net: whatever nobody acknowledged in time is announced again, elsewhere.
try {
    $esc = bk_process_escalations($pdo);
    if ($esc['escalated'] > 0) {
        echo "Eskalováno nepřevzatých incidentů: {$esc['escalated']}\n";
    }
    if ($esc['skipped_no_channel'] > 0) {
        echo "VAROVÁNÍ: {$esc['skipped_no_channel']} incidentů čeká na eskalaci, ale kanál není nastavený.\n";
    }
} catch (Throwable $e) {
    error_log('[cron] Eskalace selhala: ' . $e->getMessage());
}

// --- Stamp of this run ----------------------------------------------------
//
// Without this record there is no way to tell that data collection stopped.
// The app would keep showing the last known states and pretend all is calm -
// the worst possible way for monitoring to fail: silently and reliably.
//
// Written at the very end, so `last_cron_run` means "the run finished whole",
// not "the run started". An external watchdog (apps/worker) tracks it and
// speaks up when the value grows stale.
try {
    $bk_cron_duration_ms = (int)round((microtime(true) - $bk_cron_started) * 1000);
    $stmt_run = $pdo->prepare(
        "INSERT INTO settings (key_name, key_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)"
    );
    $stmt_run->execute(['last_cron_run', date('Y-m-d H:i:s')]);
    $stmt_run->execute(['last_cron_duration_ms', (string)$bk_cron_duration_ms]);
    $stmt_run->execute(['last_cron_monitors', (string)count($monitors)]);
} catch (PDOException $e) {
    error_log('[cron] Zápis otisku běhu selhal: ' . $e->getMessage());
}

echo "Kontrola dokončena.\n";
