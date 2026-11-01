<?php
/**
 * Cron script running the periodic checks of monitored services
 * Recommended interval: every 1 to 5 minutes.
 * Invocation: php cron.php or curl https://status.bloodkings.eu/cron.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/cron_check.php';

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
        $required_cols = ['iowait_pct','inode_usage_pct','zombie_count','fork_rate','temperature_c','wifi_clients_total','wifi_clients_24g','wifi_6e_known_24g','conntrack_pct',
            // Router release 20260920: one early, one late Wi-Fi column and the LAST column of the migration block.
            'wifi_busy_5g','wifi_6e_unserved','clock_skew_s',
            // Agent 0.1.9: without it every report's metrics INSERT fails (ingest_dropped).
            'agent_prev_cpu_ms',
            // 20260927r022: the first and the last column of that block, same reason.
            'wan_rx_overrun','speedtest_active'];
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
// Archived monitors are never checked and never alert.
$stmt = $pdo->query("SELECT * FROM monitors WHERE archived_at IS NULL");
$monitors = $stmt->fetchAll();

foreach ($monitors as $monitor) {
    bk_cron_check_monitor($pdo, $monitor);
}

// How many of the last 24 h of minute reports really arrived (G42). Hourly,
// one indexed COUNT per OpenWrt router (a server agent's cron has no minute
// cadence to hold it to, bk_reports_24h_applies): the banner must say that
// 142 of 1440 minutes are missing, and until now a partial loss was invisible
// (the agent goes quiet for a minute, the old check waits 3000 s of silence).
try {
    $bk_reports_counted = bk_update_reports_24h($pdo);
    if ($bk_reports_counted > 0) {
        echo "Hlášení agentů za 24 h: přepočítáno u {$bk_reports_counted} monitorů.\n";
    }
} catch (Throwable $e) {
    error_log('[cron] Přepočet hlášení za 24 h selhal: ' . $e->getMessage());
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
            bk_setting_set($pdo, 'metrics_daily_backfilled', '1');
        }
        bk_setting_set($pdo, 'last_metrics_rollup', date('Y-m-d H:i:s'));
        echo "Denní agregace metrik: {$metric_rows} řádků.\n";
    }
} catch (Throwable $e) {
    error_log('[cron] Agregace metrik selhala: ' . $e->getMessage());
}
if ($backfill_done !== '1') {
    try {
        bk_setting_set($pdo, 'uptime_daily_backfilled', '1');
    } catch (Throwable $e) {
        // Bez priznaku se backfill priste zopakuje - je idempotentni.
    }
}
echo "Denní souhrny dostupnosti: {$rolled} zápisů (okno {$rollup_days} dní).\n";

// The same days in time (W1-B1): what the percentage is computed from, now
// that a silent agent's blackout counts. Every ten minutes, not every run -
// it reads each log row of the window in PHP. The first run after the deploy
// refills every day whose logs are still complete (29 days) with the new
// definition. The 30th day back is already half pruned: recomputed, it would
// trade its full-day check counts for a fragment, so it keeps the counts.
try {
    $time_backfilled = get_setting('uptime_daily_time_backfilled', '') === '1';
    $last_time_rollup = get_setting('last_uptime_time_rollup', '');
    if (!$time_backfilled || $last_time_rollup === '' || strtotime($last_time_rollup) < time() - 600) {
        $time_days = $time_backfilled ? 5 : 29;
        $time_rows = bk_rollup_daily_uptime_time($pdo, $time_days);
        if ($time_rows >= 0) {
            bk_setting_set($pdo, 'last_uptime_time_rollup', date('Y-m-d H:i:s'));
            if (!$time_backfilled) {
                bk_setting_set($pdo, 'uptime_daily_time_backfilled', '1');
            }
            echo "Denní dostupnost v čase: {$time_rows} zápisů (okno {$time_days} dní).\n";
        } else {
            echo "Denní dostupnost v čase se nepřepočítala (viz error_log).\n";
        }
    }
} catch (Throwable $e) {
    error_log('[cron] Denní dostupnost v čase selhala: ' . $e->getMessage());
}

// Prune old logs (older than 30 days) to save DB space
try {
    $pdo->exec("DELETE FROM monitor_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $pdo->exec("DELETE FROM vps_metrics WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    // Audit log: longer retention (90 days) - security records
    $pdo->exec("DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    // Event timeline: a year. It was never pruned at all - every threshold,
    // agent reconnect and remote action since the install, forever.
    $pdo->exec("DELETE FROM monitor_events WHERE occurred_at < DATE_SUB(NOW(), INTERVAL 365 DAY)");
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

    // --- Router health ----------------------------------------------------
    //
    // Disk history two years, recommendation state 90 days after the item
    // last fired (mutes never), speed tests 400 days with the per-test
    // diagnostics nulled after 90. Both are functions so the API suite can
    // call them with boundary rows; nothing in a suite ever runs this file.
    $health = bk_prune_router_health($pdo);
    echo "Zdraví routeru: smazáno {$health['disk_daily']} denních řádků disků, {$health['disks']} disků,"
        . " {$health['rec_state']} stavů doporučení.\n";
    $wan = bk_prune_wan_data($pdo);
    echo "Měření WAN: smazáno {$wan['speedtests']} testů rychlosti, vymazána diagnostika u {$wan['diagnostics']}.\n";

    // --- Outgoing messages -------------------------------------------------
    //
    // 180 days, moved out of the inline DELETE above: the log stopped being an
    // alert history when send_email() started writing every kind of message
    // into it, and "did that invitation ever arrive?" gets asked months later.
    $notif = bk_prune_notification_log($pdo);
    echo "Protokol odchozích zpráv: smazáno {$notif['deleted']} řádků starších 180 dnů.\n";
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
                bk_setting_set($pdo, 'last_weekly_digest_sent', $current_week);
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
                bk_setting_set($pdo, 'last_monthly_digest_sent', $current_month);
                echo "Měsíční digest odeslán.\n";
            }
        }
    }
} catch (Exception $e) {
    echo "Chyba při automatickém odesílání digestů: " . $e->getMessage() . "\n";
}

// --- Daily reminder of what is still broken -------------------------------
//
// An alert fires on a CHANGE of state, so a monitor that went down on a
// Wednesday said nothing for the rest of the week - which is how a four-day
// outage stayed invisible. This is the recurring half: once a day, from the
// configured hour, and ONLY while something really is wrong.
//
// The date stamp is written whichever way it ends, including "there was
// nothing to send". This cron runs every minute on a router: without the
// stamp the healthy case would write a skipped row every minute, and a
// refused channel would be retried until midnight and bury its own failure
// under hundreds of rows. One decision a day - and the outgoing message log
// says how that decision ended.
try {
    if (get_setting('daily_reminder_enabled', '1') === '1'
        && bk_daily_reminder_due((string)get_setting('last_daily_reminder_sent', ''),
            (int)get_setting('daily_reminder_hour', '8'))) {
        $reminder = bk_send_daily_reminder($pdo);
        bk_setting_set($pdo, 'last_daily_reminder_sent', date('Y-m-d'));
        if ($reminder['problems'] === 0) {
            echo "Denní připomínka: nic rozbitého, neodesílá se.\n";
        } elseif ($reminder['sent']) {
            echo "Denní připomínka odeslána: {$reminder['problems']} problémů,"
                . " {$reminder['emails']} e-mailů, {$reminder['channels']} dalších kanálů.\n";
        } else {
            echo "VAROVÁNÍ: denní připomínka ({$reminder['problems']} problémů) neodešla nikam"
                . " - důvod je v protokolu odchozích zpráv.\n";
        }
    }
} catch (Throwable $e) {
    // Never takes the run down with it: the reminder is a report about
    // monitoring, and monitoring itself has to keep running.
    error_log('[cron] Denní připomínka selhala: ' . $e->getMessage());
    echo "Chyba při odesílání denní připomínky: " . $e->getMessage() . "\n";
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
    if ($esc['failed'] > 0) {
        echo "VAROVÁNÍ: eskalační webhook zprávu nepřijal (" . ($esc['error'] ?? 'bez důvodu') . "), příští běh to zkusí znovu.\n";
    }
    if ($esc['unknown'] > 0) {
        echo "VAROVÁNÍ: eskalační webhook po odeslání zprávy neodpověděl; možná odešla, znovu se posílat nebude a ostatní incidenty počkají na příští běh (důvod je v protokolu odchozích zpráv).\n";
    }
} catch (Throwable $e) {
    error_log('[cron] Eskalace selhala: ' . $e->getMessage());
}

// --- Self-check of the site's own public API -------------------------------
//
// On 27 Sep 2026 every PHP answer began with two stray bytes for an hour: /app
// could not read anything, and this cron, watching everything else, did not
// notice its own site. It now reads the public API over HTTP the way /app
// does, and tells the administrators when that fails and when it recovers.
// Stray output of config.php fails it too: it no longer reaches the API.
try {
    $sc = bk_self_check_run($pdo, bk_self_check_url((string)get_setting('site_url', '')), time(), bk_config_output());
    if ($sc['state'] === 'unconfigured') {
        echo "VAROVÁNÍ: kontrola vlastního API neběží, v nastavení chybí adresa webu (site_url).\n";
    } elseif ($sc['ran'] && $sc['state'] === 'failed') {
        echo "VAROVÁNÍ: veřejné API webu neodpovídá správně ({$sc['failures']}× za sebou): {$sc['reason']}\n";
    }
} catch (Throwable $e) {
    // Never takes the run down; the error log says why the check did not happen.
    error_log('[cron] Kontrola vlastního API selhala: ' . $e->getMessage());
    echo "Chyba při kontrole vlastního API: " . $e->getMessage() . "\n";
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
    bk_setting_set($pdo, 'last_cron_run', date('Y-m-d H:i:s'));
    bk_setting_set($pdo, 'last_cron_duration_ms', (string)$bk_cron_duration_ms);
    bk_setting_set($pdo, 'last_cron_monitors', (string)count($monitors));
} catch (PDOException $e) {
    error_log('[cron] Zápis otisku běhu selhal: ' . $e->getMessage());
}

echo "Kontrola dokončena.\n";
