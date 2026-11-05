<?php
/**
 * cron.php's check of the monitors (bk_cron_check_all, bk_cron_check_monitor)
 * and the active checks it runs. A file of its own so the suites can run it on
 * a real database, and so functions.php does not grow.
 */

// Included only (cron.php and the suites); requested directly it has nothing to show.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

/**
 * Every monitor's turn in one run. A failure stays that monitor's: an exception
 * from one check used to end the whole script, so no monitor after it was
 * checked, no later step ran and the run looked like a dead cron. The monitor
 * that failed keeps its old last_checked, and bk_get_collection_issues says so
 * as checks_stalled within 15 minutes.
 *
 * @param ?callable(array, int): ?array $check
 * @param ?callable(PDO, array, string, ?string): mixed $notify
 * @return list<int> ids of the monitors whose check failed
 */
function bk_cron_check_all(PDO $pdo, array $monitors, ?callable $check = null, ?callable $notify = null): array {
    $failed = [];
    foreach ($monitors as $monitor) {
        try {
            bk_cron_check_monitor($pdo, $monitor, $check, $notify);
        } catch (Throwable $e) {
            // The next monitor must not write into what this one left open.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $failed[] = (int)$monitor['id'];
            error_log(sprintf('[cron] Kontrola monitoru %d selhala: %s: %s', $monitor['id'], get_class($e), $e->getMessage()));
            echo "CHYBA: kontrola monitoru #{$monitor['id']} selhala, pokračuji dalším.\n";
        }
    }
    return $failed;
}

/**
 * Does this run count as one that ran? `last_cron_run` tells the watchdog and
 * the pages that data is being collected, so a run in which not one monitor
 * got through must not write it: a bad deploy that fails every check would
 * otherwise look like a healthy cron. Part of the monitors failing is the
 * opposite case, the collector works and each of those says so on its own.
 */
function bk_cron_run_collected(int $monitors, int $failed): bool {
    return $monitors === 0 || $failed < $monitors;
}

/**
 * One monitor's turn in cron.php's check loop: the agent-silence alert, the
 * maintenance window, the agent-side and heartbeat types, and for the rest
 * the active check with its retry, the agent and cPanel fallbacks, the
 * details, the log row, the confirmation, the state and the latency alert.
 *
 * last_details is shared with agent_api.php, which rewrites it on every agent
 * report. cron reads the monitor list once and checks one monitor after
 * another, so without a fresh read a slow run merges and writes back a copy
 * that is minutes old - undoing what the agent wrote in between. It is read
 * again at the start, after the check and after the latency alert is sent.
 *
 * $check runs the active check (bk_cron_active_check), $notify sends
 * (trigger_notifications); both are parameters so the suites can run this
 * on a real database without a network or a channel.
 *
 * @param ?callable(array, int): ?array $check
 * @param ?callable(PDO, array, string, ?string): mixed $notify
 */
function bk_cron_check_monitor(PDO $pdo, array $monitor, ?callable $check = null, ?callable $notify = null): void {
    $check ??= 'bk_cron_active_check';
    $notify ??= 'trigger_notifications';
    $stmt_fresh_details = $pdo->prepare("SELECT last_details FROM monitors WHERE id = ?");

    $id = $monitor['id'];
    $name = $monitor['name'];
    $type = $monitor['type'];
    $target = $monitor['target'];
    $timeout = $monitor['timeout'] ?: 5;
    $old_status = $monitor['status'];

    $stmt_fresh_details->execute([$id]);
    $fresh_details = $stmt_fresh_details->fetchColumn();
    if ($fresh_details === false) {
        // Deleted since the run started.
        return;
    }
    $monitor['last_details'] = $fresh_details;
    
    // Kontrola neaktivity VPS agenta (pokud je propojen)
    // null = timeout 0, agent inactivity detection is fully disabled
    $offline_secs = bk_agent_offline_secs();
    $offline_timeout_mins = $offline_secs !== null ? intdiv($offline_secs, 60) : 0;
    // A silent agent in a maintenance window is the point of the window. The latch
    // stays off, so one still silent when the window ends is announced then.
    if (!empty($monitor['agent_key']) && $offline_secs !== null && !is_in_maintenance($monitor)) {
        $details_arr = bk_monitor_details($monitor);
        $agent_last_seen = $details_arr['agent_last_seen'] ?? 0;

        if ($agent_last_seen > 0) {
            $seconds_since_report = time() - $agent_last_seen;
            $agent_alert_sent = $details_arr['agent_alert_sent'] ?? false;

            if (bk_agent_is_silent($details_arr) === true && !$agent_alert_sent) {
                $details_arr['agent_alert_sent'] = true;
                $new_details = json_encode($details_arr, JSON_UNESCAPED_UNICODE);

                $stmt_up_agent = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
                $stmt_up_agent->execute([$new_details, $id]);
                // Everything below builds on $monitor['last_details'] and writes
                // it back. Left stale, it wrote the latch away again and every
                // following run re-sent this alert to every channel, SMS included.
                $monitor['last_details'] = $new_details;

                $mins_since = round($seconds_since_report / 60);
                $last_seen_str = date('d.m.Y H:i', intval($agent_last_seen));
                $error_msg_agent = bk_agent_label((string)$type) . " monitoru '{$name}' nehlásí žádná data déle než {$offline_timeout_mins} minut. "
                    . "Poslední hlášení: {$last_seen_str} (před {$mins_since} min). "
                    . bk_agent_silence_hint((string)$type);
                // A router or server is its agent: unless its monitor is down
                // already, the same silence takes it down further below, and
                // that 'down' is the one notice and the one incident (R211).
                // For any other type the agent is an extra, and this is the
                // only word that it went quiet.
                if (($type !== 'vps' && $type !== 'openwrt') || $old_status === 'down') {
                    $notify($pdo, $monitor, 'agent_offline', $error_msg_agent);
                }
                log_monitor_event($pdo, $id, $name, $type, 'agent_disconnected', "Agent přestal hlásit data (poslední hlášení před {$mins_since} min)");
            }
        }
    }
    
    echo "Kontroluji [$type] $name ($target)... ";

    // A window whose end has passed switches itself off. The flag used to
    // stay on forever: the list said "maintenance", the public page reasoned
    // about a window in the past, and the admin had to remember to untick it.
    if (bk_maintenance_window_expired($monitor, time())) {
        // The window goes with the flag (the same way admin.php clears it):
        // a leftover start/end would make the NEXT maintenance expire the
        // moment it is switched on, because the form prefills the old dates.
        $m_end_text = date('d.m.Y H:i', strtotime((string)$monitor['maintenance_end']));
        $stmt_mend = $pdo->prepare("UPDATE monitors SET maintenance = 0, maintenance_start = NULL, maintenance_end = NULL, maintenance_description = NULL WHERE id = ?");
        $stmt_mend->execute([$id]);
        $monitor['maintenance'] = 0;
        $monitor['maintenance_start'] = null;
        $monitor['maintenance_end'] = null;
        $monitor['maintenance_description'] = null;
        log_monitor_event($pdo, $id, $name, $type, 'maintenance_ended', 'Plánovaná údržba skončila (' . $m_end_text . ')');
        echo "údržba skončila, příznak zrušen... ";
    }

    if (is_in_maintenance($monitor)) {
        echo "Údržba (přeskakuji)\n";
        $loc = get_setting('ip_loc_local', '');
        if (empty($loc)) {
            $loc = 'Main Server';
        }
        // The window's row, and on entering it the switch and its one notice.
        // last_details stays: entering a window used to set it to NULL, which
        // dropped every alert latch, so wan_lost went out again (R212).
        $state = bk_apply_check_result($pdo, $monitor, 'maintenance', null, null, 'cron', ['checked_from' => $loc]);
        foreach ($state['notify'] as [$n_status, $n_message]) {
            $notify($pdo, $monitor, $n_status, $n_message);
        }
        return;
    }
    
    // Agent-side check (LAN services unreachable from the hosting): the state
    // is written by agent_api on the agent's report. Cron only watches
    // freshness here - when results stop arriving (the agent is silent), the
    // monitor must not keep its last colour forever. 'unknown' does not count into SLA.
    if ($type === 'agent_service') {
        $last_checked_ts = $monitor['last_checked'] ? strtotime($monitor['last_checked']) : 0;
        if ($offline_secs !== null && (time() - $last_checked_ts) > $offline_secs && $monitor['status'] !== 'unknown') {
            // Nothing was measured: last_checked keeps the agent's last result.
            bk_apply_check_result($pdo, $monitor, 'unknown', null,
                'Zdrojový agent přestal posílat výsledky kontroly - stav služby není známý.', 'cron',
                ['checked_from' => 'Agent', 'measured' => false]);
            echo "UNKNOWN (agent-side kontrola bez čerstvých výsledků)\n";
        } else {
            echo "OK (kontrolu provádí agent)\n";
        }
        return;
    }

    // Heartbeat: the job reports itself to heartbeat.php, this only watches the
    // clock. Evaluation lives in bk_heartbeat_evaluate() so it can be tested
    // without a database and without waiting for a real delay.
    if ($type === 'heartbeat') {
        $hb = bk_heartbeat_evaluate($monitor);
        $new_status = $hb['status'];

        $hb_details = bk_monitor_details($monitor);
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

        // The grace is the heartbeat's tolerance: no confirmation on top.
        $state = bk_apply_check_result($pdo, $monitor, $new_status, null, $hb['error'], 'heartbeat',
            ['checked_from' => 'Heartbeat', 'details' => json_encode($hb_details, JSON_UNESCAPED_UNICODE)]);
        foreach ($state['notify'] as [$n_status, $n_message]) {
            $notify($pdo, $monitor, $n_status, $n_message);
        }
        echo ($state['changed'] ? "ZMĚNA STAVU -> " : '') . strtoupper($new_status)
            . " (" . ($hb['error'] ?? 'signál dorazil včas') . ")\n";
        return;
    }

    // A failed check measured no response time either: the check_* functions
    // return null then, and the agent fallback below keeps that null when it
    // turns the result up.
    $check_result = [
        'status' => 'unknown',
        'response_time' => null,
        'error' => null
    ];
    
    // Passive VPS/OpenWrt load check (the agent) - both wait for the agent's
    // push, no active network check happens here.
    if ($type === 'vps' || $type === 'openwrt') {
        // Timeout 0 = inactivity detection is off - the monitor stays in its last reported state
        if ($offline_secs === null) {
            echo "OK (Detekce neaktivity vypnuta)\n";
        } elseif ($old_status !== 'down') {
            $details_arr = bk_monitor_details($monitor);
            // If the agent has never reported, the timeout counts from the
            // monitor's creation (not from the 1970 epoch) - a new monitor gets
            // the same grace period (offline_timeout_mins) before its first
            // DOWN as a monitor whose agent went silent after its first report.
            $last_report = $details_arr['agent_last_seen']
                ?? ($monitor['last_checked'] ? strtotime($monitor['last_checked']) : null)
                ?? (!empty($monitor['created_at']) ? strtotime($monitor['created_at']) : 0);

            $timeout_threshold = time() - $offline_secs;

            if ($last_report < $timeout_threshold) {
                // The agent is not responding
                $new_status = 'down';
                $last_report_str = $last_report > 0 ? date('d.m.Y H:i', intval($last_report)) : 'nikdy';
                $error_msg = bk_agent_label((string)$type) . " neodpovídá déle než {$offline_timeout_mins} minut (poslední hlášení: {$last_report_str}). "
                    . bk_agent_silence_hint((string)$type);
                
                // Its own timeout is the tolerance: no confirmation on top, and
                // nothing was measured, so last_checked keeps the last report.
                $state = bk_apply_check_result($pdo, $monitor, $new_status, null, $error_msg, 'cron', ['measured' => false]);
                foreach ($state['notify'] as [$n_status, $n_message]) {
                    $notify($pdo, $monitor, $n_status, $n_message);
                }
                echo "DOWN (Agent neodpovídá)\n";
            } else {
                echo "OK (Agent je aktivní)\n";
            }
        } else {
            echo "DOWN (Čeká na hlášení agenta)\n";
        }
        return;
    }
    
    // Active checks from the hosting, by type (bk_cron_active_check).
    $check_result = $check($monitor, 1);
    if ($check_result === null) {
        // A type with no active check (e.g. an older Service Discovery
        // import typed 'dns'). The default 'unknown' result used to fall
        // through here into monitor_logs every minute and the SLA report
        // then showed the monitor at 0 % uptime even though nobody ever
        // checked it. No measurement, no write.
        $stmt_skip = $pdo->prepare("UPDATE monitors SET last_checked = NOW() WHERE id = ?");
        $stmt_skip->execute([$id]);
        echo "SKIP (typ '{$type}' nemá aktivní kontrolu)\n";
        return;
    }
    if ($type === 'web') {
        detect_config_changes($pdo, $monitor, $check_result);
        // The SSL-expiry warning lives in the details block below (latched
        // to once a day, honouring ssl_alert_days). A second copy used to
        // sit here, unlatched and labelled "up": every run inside the
        // window sent a "back online" notification to every channel.
    }

    // Immediate retry on failure - a single transient 5s timeout (network
    // hiccup, momentary shared-hosting overload) used to flip the state
    // to down instantly and fire the notifications (WhatsApp/SMS/e-mail),
    // only for everything to come back a minute later with a second wave. A real
    // outage fails the retry too; a blip disappears entirely and nothing is sent.
    if (($check_result['status'] ?? '') === 'down') {
        $retry_result = $check($monitor, 2);
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
    // (bk_agent_backup_says_up() enforces that: it never vouches for a web.)
    if (($check_result['status'] ?? '') === 'down' && $type === 'web' && !empty($monitor['cpanel_stats_url'])) {
        $cp_probe = check_cpanel($monitor['cpanel_stats_url'], min($timeout, 5));
        if (($cp_probe['status'] ?? '') === 'up') {
            $check_result['error'] = ($check_result['error'] ?? 'Web nedostupný')
                . ' | Server samotný běží (cPanel exporter odpovídá) - problém je pravděpodobně v HTTP vrstvě/CDN, ne ve stroji.';
        }
    }

    $new_status = $check_result['status'];
    // The value monitor_logs gets: whole ms, at least 1 when something was
    // measured, NULL when not (bk_response_ms).
    $response_time = bk_response_ms($check_result['response_time']);
    $error_msg = $check_result['error'];
    $details = null;
    
    // BACKUP FALLBACK: a game server the hosting cannot reach directly (an
    // outbound firewall) still counts as up when fresh data from the agent on
    // the same machine shows it listening or running. The rules live in
    // bk_agent_backup_says_up(): never for web, only a report a few minutes
    // old, and only the service's own port or process.
    if ($new_status === 'down') {
        $details_decoded = bk_monitor_details($monitor);

        if (bk_agent_backup_says_up((string)$type, $details_decoded, $monitor, time())) {
            $new_status = 'up';
            $error_msg = 'Používá se záložní API (přímé TCP spojení selhalo)';
            // Only what the fallback adds - the rest of last_details is merged
            // in below from a fresh read, not from this copy.
            $fallback_details = [
                'api_fallback' => true,
                'last_error' => $check_result['error'],
            ];
            if ($type === 'teamspeak' && isset($details_decoded['ts3_clients_online'])) {
                $fallback_details['clients_online'] = $details_decoded['ts3_clients_online'];
                $fallback_details['clients_max'] = $details_decoded['ts3_clients_max'] ?? null;
                $fallback_details['name'] = !empty($details_decoded['ts3_name']) ? $details_decoded['ts3_name'] : ($monitor['name'] ?: 'TeamSpeak Server');
            }
            $details = json_encode($fallback_details, JSON_UNESCAPED_UNICODE);
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

            $ts3_agent_details = bk_monitor_details($monitor);
            // Cron has no session and reads every monitor.
            bk_enrich_monitor_details($pdo, $monitor, $ts3_agent_details, true);
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
                // Read the previous warn timestamp from the monitor's last stored
                // details, not from $details_arr (which is rebuilt fresh every run
                // and would otherwise always read 0, spamming this alert hourly).
                // $details_decoded is only populated on the down-path above, so
                // decode last_details fresh here rather than relying on it.
                $prev_details = bk_monitor_details($monitor);
                $last_ssl_warn = (int)($prev_details['last_ssl_warn'] ?? 0);
                // The threshold is the ssl_alert_days setting (it used to be a
                // hardcoded 14 here, so a 30-day setting did nothing).
                $ssl_threshold = (int)get_setting('ssl_alert_days', '14');
                if (bk_ssl_alert_due($days, $ssl_threshold, $last_ssl_warn, time())) {
                    $details_arr['last_ssl_warn'] = time();
                    $notify($pdo, $monitor, 'ssl_expiring', "SSL certifikát pro '{$name}' vyprší za {$days} dní!");
                    log_monitor_event($pdo, $id, $name, $type, 'ssl_warning', "SSL certifikát vyprší za {$days} dní");
                } elseif ($last_ssl_warn > 0) {
                    $details_arr['last_ssl_warn'] = $last_ssl_warn;
                }
            }
            
            if (!empty($monitor['cpanel_stats_url'])) {
                $cp_res = check_cpanel($monitor['cpanel_stats_url'], $timeout);
                if ($cp_res['status'] === 'up') {
                    $details_arr['cpanel_stats'] = bk_cpanel_stats_details($cp_res);
                    $details_arr['cpanel_stats_error'] = null;
                    // Into vps_metrics for chart history.
                    bk_cpanel_metrics_insert($pdo, (int)$id, $cp_res);
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
                    $prev_cp_details = bk_monitor_details($monitor);
                    $prev_cp_since = $prev_cp_details['cpanel_stats_error']['since'] ?? null;
                    $details_arr['cpanel_stats_error'] = [
                        'error' => $cp_res['error'] ?? 'Neznámá chyba',
                        'hint' => $cp_hint,
                        'since' => $prev_cp_since ?? date('c'),
                    ];
                }
            }
            
            $details = json_encode($details_arr, JSON_UNESCAPED_UNICODE);
        }
    }
    
    // The check above can take seconds (timeouts, the retry, the cPanel probe)
    // and an agent report may have landed meanwhile: merge into what is stored now.
    $stmt_fresh_details->execute([$id]);
    $fresh_details = $stmt_fresh_details->fetchColumn();
    if ($fresh_details !== false) {
        $monitor['last_details'] = $fresh_details;
    }

    // Merge old details (e.g. from the VPS agent) with the fresh active-check ones
    if ($details !== null) {
        $old_details = bk_monitor_details($monitor);
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
            bk_setting_set($pdo, 'ip_loc_local', $loc);
        }
    }
    // check_stages (DNS/TCP/TLS/HTTP/body breakdown for 'web', ServerQuery/service/
    // ports/license for 'teamspeak') exists only for these two types - for the
    // rest it is always null, no behaviour change for them.
    $check_stages_json = (in_array($type, ['web', 'teamspeak'], true) && isset($check_result['check_stages']))
        ? json_encode($check_result['check_stages'], JSON_UNESCAPED_UNICODE)
        : null;
    $state = bk_apply_check_result($pdo, $monitor, $new_status, $response_time, $error_msg, 'cron',
        ['checked_from' => $loc, 'check_stages' => $check_stages_json, 'details' => $details, 'confirm' => true]);
    if ($state['unconfirmed'] !== null) {
        echo "výpadek nepotvrzen ({$state['unconfirmed'][0]}/{$state['unconfirmed'][1]})... ";
    }
    $new_status = $state['status'];
    $rt_text = $response_time === null ? 'neměřeno' : "{$response_time}ms";
    foreach ($state['notify'] as [$n_status, $n_message]) {
        $notify($pdo, $monitor, $n_status, $n_message);
    }
    if ($state['notify'] !== []) {
        echo "ZMĚNA STAVU -> " . strtoupper($new_status) . " (Odezva: {$rt_text})\n";
    } elseif ($state['changed']) {
        // Up after a planned window or after 'unknown', with no incident open.
        echo "ZMĚNA STAVU -> " . strtoupper($new_status) . " (bez notifikace, Odezva: {$rt_text})\n";
    } else {
        echo strtoupper($new_status) . " (Odezva: {$rt_text})\n";
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

            $notify($pdo, $monitor, 'latency_' . $lat['state'], $lat_msg);

            // Sending the alert takes seconds (SMS and WhatsApp gateways), and an
            // agent report landing meanwhile stores its own latches in the same
            // column. Only the latency latch is this block's to change, so it goes
            // onto a fresh read instead of writing back the copy from before.
            $stmt_fresh_details->execute([$id]);
            $lat_fresh = json_decode((string)($stmt_fresh_details->fetchColumn() ?: '{}'), true);
            if (!is_array($lat_fresh)) {
                $lat_fresh = [];
            }
            $lat_fresh['latency_alert_sent'] = $lat_details['latency_alert_sent'];
            $stmt_lat = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
            $stmt_lat->execute([json_encode($lat_fresh, JSON_UNESCAPED_UNICODE), $id]);
            echo "  ODEZVA -> " . strtoupper($lat['state']) . " ({$lat['avg_ms']} ms)\n";
        }
    }
}

/**
 * The active check of one monitor from the hosting, by its type; null for a
 * type that has none. $attempt 2 is the immediate retry after a failure, two
 * seconds after the first.
 */
function bk_cron_active_check(array $monitor, int $attempt = 1): ?array {
    $target = $monitor['target'];
    $port = $monitor['port'];
    $timeout = $monitor['timeout'] ?: 5;
    if ($attempt > 1) {
        sleep(2);
    }
    switch ($monitor['type']) {
        case 'web':
            return check_http($target, $timeout, $monitor['body_keyword'] ?? null);
        case 'port':
            return check_socket($target, $port ?: 80, $timeout);
        case 'minecraft':
            return check_minecraft(
                $target,
                $port ?: 25565,
                $timeout,
                $monitor['rcon_port'] ?? null,
                $monitor['rcon_password'] ?? null
            );
        case 'teamspeak':
            return check_teamspeak(
                $target,
                $port ?: 10011,
                $timeout,
                $monitor['sq_username'] ?? null,
                $monitor['sq_password'] ?? null,
                $monitor['ts3_filetransfer_port'] ?? null
            );
        case 'discord':
            return check_discord($target, $timeout);
    }
    return null;
}

/**
 * The cPanel exporter's figures as a web monitor's details keep them under
 * cpanel_stats, read from its cpanel_stats_url.
 */
function bk_cpanel_stats_details(array $res): array {
    return [
        'disk' => $res['disk'] ?? null,
        'memory' => $res['memory'] ?? null,
        'processes' => $res['processes'] ?? null,
        'database' => $res['database'] ?? null,
        'bandwidth' => $res['bandwidth'] ?? null,
        'postgresql' => $res['postgresql'] ?? null,
        'cpu' => $res['cpu'] ?? null,
    ];
}

/**
 * One vps_metrics row from the cPanel exporter's answer. A missing metric is
 * stored as NULL, not 0.0 - zero is a real value and an invented zero would
 * read as "the server is slacking" in charts (StatsBar without CloudLinux
 * returns no cpuusage at all).
 */
function bk_cpanel_metrics_insert(PDO $pdo, int $monitor_id, array $res): void {
    $cpu_val = isset($res['cpu']['percent']) ? floatval($res['cpu']['percent']) : null;
    $ram_val = isset($res['memory']['percent']) ? floatval($res['memory']['percent']) : null;
    $hdd_val = isset($res['disk']['percent']) ? floatval($res['disk']['percent']) : null;
    $stmt_metrics = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage) VALUES (?, ?, ?, ?)");
    $stmt_metrics->execute([$monitor_id, $cpu_val, $ram_val, $hdd_val]);
}
