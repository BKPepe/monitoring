<?php
/**
 * Database connection and settings bootstrap
 */

if (!file_exists(__DIR__ . '/config.php') && file_exists(__DIR__ . '/config.sample.php')) {
    @copy(__DIR__ . '/config.sample.php', __DIR__ . '/config.php');
}

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    http_response_code(500);
    die('<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Konfigurace nenalezena | Blood Kings</title><style>body{background:#0b0c10;color:#fff;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.card{background:#14161d;border:1px solid rgba(176,0,32,0.4);border-radius:12px;padding:2.5rem;max-width:480px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.5);}h2{color:#b00020;margin-top:0;}p{color:#aaa;line-height:1.6;font-size:0.95rem;}code{background:rgba(255,255,255,0.08);padding:0.2rem 0.4rem;border-radius:4px;color:#fff;}</style></head><body><div class="card"><h2>Blood Kings Monitoring</h2><p>Konfigurační soubor <code>config.php</code> nebyl na serveru nalezen.</p><p>Zkopírujte na serveru soubor <code>config.sample.php</code> na <code>config.php</code> a vyplňte vaše přihlašovací údaje k MySQL databázi, nebo spusťte deploy z GitHubu s vyplněným secretem <code>STATUS_CONFIG_PHP</code>.</p></div></body></html>');
}

// Numbers in JSON use the shortest representation that round-trips to the same value.
//
// Without this json_encode() prints the double's full decimal expansion, so
// z hodnoty 35,2 (sloupec FLOAT) stane 35.20000000000000284217094304040074348
// 0.4 becomes a fifty-character string. The status page embeds 1,728 points
// on four series per monitor every 24 h - this alone grew the page
// to 1.6 MB and made the server spend that long assembling it.
//
// PHP defaults to -1 since 7.1; this hosting has it overridden.
// Set here because every page and API request passes through db.php.
ini_set('serialize_precision', '-1');

/**
 * Whether the caller of this request is a program that reads JSON.
 *
 * The API and the agent/node/heartbeat endpoints are called by the app and by
 * agents, which parse the body; a browser opening a page wants a page.
 * Decided by the script first (those endpoints answer JSON whatever the caller
 * sends), then by a JSON content type the script already declared, then by an
 * Accept header that asks for JSON and not for HTML.
 */
function bk_request_wants_json(): bool {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $json_scripts = ['api.php', 'agent_api.php', 'node_api.php', 'heartbeat.php', 'health.php', 'cron.php', 'metrics.php'];
    if (in_array($script, $json_scripts, true)) {
        return true;
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
            return true;
        }
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false;
}

/**
 * The database cannot be reached: 503 for everyone, and nothing about why.
 *
 * This used to be a 500 page with the PDO message printed into it - the
 * database host, the account name and "config.php" for anyone who happened to
 * load a page during an outage - and the API answered that HTML page to the
 * app and to the agents, which cannot parse it. Now: 503 with Retry-After (an
 * outage of the database is temporary, and a client or crawler should come
 * back rather than drop the page), a JSON code for programs, the branded error
 * page for people, and the detail only in the server's error log.
 */
function bk_database_unavailable(Throwable $e): never {
    error_log('[db] database unavailable: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        // A cron run by hand: the operator is the reader, and nothing here is
        // served to anyone.
        fwrite(STDERR, 'Databáze je nedostupná: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
    }
    if (bk_request_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'database_unavailable']);
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $bk_error_code = 503;
    require __DIR__ . '/error.php';
    exit;
}

try {
    $db_driver = defined('DB_DRIVER') ? strtolower(DB_DRIVER) : (defined('BK_DATABASE_URL') && strpos(BK_DATABASE_URL, 'postgres') !== false ? 'pgsql' : 'mysql');
    if ($db_driver === 'pgsql' || $db_driver === 'postgres') {
        $db_port = defined('DB_PORT') ? DB_PORT : 5432;
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . $db_port . ";dbname=" . DB_NAME;
    } else {
        // DB_PORT used to apply only to Postgres, so MySQL on a non-default
        // port never connected and the user only saw the generic
        // "Database connection error" message.
        $db_port = defined('DB_PORT') ? (int)DB_PORT : 3306;
        $dsn = "mysql:host=" . DB_HOST . ";port=" . $db_port . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // The session runs in PHP's time zone, because the application constantly
    // compares the two sides: SQL NOW() / CURDATE() against timestamps
    // formatted in PHP (last_checked, checked_at, the day of a daily row).
    // Without this every such comparison silently depends on the database
    // server happening to run in the same zone as PHP - a fresh checkout
    // pointed at the local MySQL container (UTC) with TIMEZONE = 'Europe/Prague'
    // reported "checks have not run for 120 minutes" for EVERY monitor: a
    // two-hour shift and not one error anywhere.
    //
    // Sent as the CURRENT UTC offset (date('P'), e.g. "+02:00"), not as the
    // zone name: the MySQL time-zone tables are usually not loaded on shared
    // hosting and 'Europe/Prague' would be refused there. date('P') follows
    // DST and a connection lives for a single request, so the offset cannot go
    // stale. Where both sides already agree - the production case - this
    // changes nothing.
    //
    // MySQL only: Postgres reads a bare offset with the opposite sign, and
    // everything below is MySQL syntax anyway. A refused statement is logged
    // and the request continues - a clock read an hour wrong is bad, being
    // unable to open the site at all is worse.
    if ($db_driver !== 'pgsql' && $db_driver !== 'postgres') {
        try {
            $stmt_tz = $pdo->prepare("SET time_zone = ?");
            $stmt_tz->execute([date('P')]);
        } catch (PDOException $e) {
            error_log('[db] the database refused the session time zone ' . date('P') . ': ' . $e->getMessage());
        }
    }

    // Schema version - bump when changing the migrations below (and schema.sql).
    // Thanks to this, migrations run only once, not on every request.
    define('BK_SCHEMA_VERSION', '20260923b');

    $bk_current_schema = false;
    try {
        $stmt_ver = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'");
        $bk_current_schema = $stmt_ver->fetchColumn();
    } catch (PDOException $e) {
        // The settings table does not exist yet - migrations below will try to finish
    }

    if ($bk_current_schema !== BK_SCHEMA_VERSION) {

    // Automatic migration - add the checked_from column to monitor_logs
    try {
        $pdo->exec("ALTER TABLE monitor_logs ADD COLUMN checked_from VARCHAR(50) DEFAULT 'Main Server'");
    } catch (PDOException $e) {
        // Column already exists or the table is missing (e.g. before import) - ignore
    }
    
    // Automatic migration - add the role column to users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user'");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }
    
    // Account 1 becomes admin only when the install has no admin at all - a
    // recovery for an install that lost its admin role. It used to run on
    // every schema bump and silently gave full rights back to account 1 after
    // an admin had deliberately demoted it.
    try {
        $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1 AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE role = 'admin') AS existing_admins)");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    
    // Create the join table for user notification subscriptions
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_subscriptions` (
            `user_id` INT NOT NULL,
            `monitor_id` INT NOT NULL,
            `email_notifications` TINYINT(1) DEFAULT 1,
            `sms_notifications` TINYINT(1) DEFAULT 0,
            PRIMARY KEY (`user_id`, `monitor_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    
    // Automatic migration - add the notes column to monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN notes TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - add the maintenance column to monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - add the monitored_processes column to monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN monitored_processes TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    
    // Automatic migration - add the whatsapp_apikey column to users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_apikey VARCHAR(100) DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }

    // Automatic migration - add the OAuth columns to users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(50) DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN oauth_id VARCHAR(100) DEFAULT NULL");
    } catch (PDOException $e) {}
    
    // Automatic migration - add the sms_notifications column to users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN sms_notifications TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }

    // Automatic migration - add the planned-maintenance columns to monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance_description TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignore if the column already exists
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance_start DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignore if the column already exists
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance_end DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignore if the column already exists
    }

    // Automatic migration - ensure the status column length in monitors and monitor_logs
    try {
        $pdo->exec("ALTER TABLE monitors MODIFY COLUMN status VARCHAR(20) DEFAULT 'unknown'");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE monitor_logs MODIFY COLUMN status VARCHAR(20) NOT NULL");
    } catch (PDOException $e) {}

    // Automatic migration - add the cpanel_stats_url column to monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN cpanel_stats_url VARCHAR(255) DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignore if the column already exists
    }

    // Automatic migration - convert old cpanel monitors to web monitors with
    try {
        $stmt_check_cpanel = $pdo->query("SELECT * FROM monitors WHERE type = 'cpanel'");
        $cpanel_monitors = $stmt_check_cpanel->fetchAll();
        foreach ($cpanel_monitors as $m) {
            $parsed = parse_url($m['target']);
            $base_target = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'localhost');
            
            $stmt_update = $pdo->prepare("UPDATE monitors SET type = 'web', target = ?, cpanel_stats_url = ? WHERE id = ?");
            $stmt_update->execute([$base_target, $m['target'], $m['id']]);
        }
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - add the whatsapp_notifications column to users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_notifications TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - add the whatsapp_notifications column to user_subscriptions
    try {
        $pdo->exec("ALTER TABLE user_subscriptions ADD COLUMN whatsapp_notifications TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - generate an agent_key for every existing monitor without one
    try {
        $stmt_null_keys = $pdo->query("SELECT id FROM monitors WHERE agent_key IS NULL OR agent_key = ''");
        $null_monitors = $stmt_null_keys->fetchAll();
        if (!empty($null_monitors)) {
            $stmt_set_key = $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = ?");
            foreach ($null_monitors as $m) {
                $stmt_set_key->execute([bin2hex(random_bytes(16)), $m['id']]);
            }
        }
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - widen the status column to VARCHAR(20) to fit 'maintenance' (11 chars)
    try {
        $pdo->exec("ALTER TABLE monitors MODIFY COLUMN status VARCHAR(20) DEFAULT 'unknown'");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    try {
        $pdo->exec("ALTER TABLE monitor_logs MODIFY COLUMN status VARCHAR(20) NOT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - threshold values for the VPS agent
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN cpu_threshold INT DEFAULT 90");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN ram_threshold INT DEFAULT 95");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN hdd_threshold INT DEFAULT 90");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - network throughput (KB/s) reported by agents; NULL on older
    // rows and for agents that do not report network yet (no previous sample to diff against).
    try {
        $pdo->exec("ALTER TABLE vps_metrics ADD COLUMN net_usage FLOAT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - check pipeline (DNS/TCP/TLS/HTTP/body stages for 'web' monitors)
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN body_keyword VARCHAR(255) DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    try {
        $pdo->exec("ALTER TABLE monitor_logs ADD COLUMN check_stages TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - infrastructure report digest (config change tracking + event log)
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN config_snapshot TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS monitor_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                monitor_id INT DEFAULT NULL,
                monitor_name VARCHAR(100) NOT NULL,
                monitor_type VARCHAR(20) DEFAULT NULL,
                event_type VARCHAR(50) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (monitor_id) REFERENCES monitors(id) ON DELETE SET NULL,
                INDEX (occurred_at),
                INDEX (monitor_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    try {
        $stmt_sla = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('sla_goal_pct', '99.95') ON DUPLICATE KEY UPDATE key_value = key_value");
        $stmt_sla->execute();
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - deep TeamSpeak monitoring + Host/VPS layer (load average,
    // CPU steal, swap, disk I/O, network errors) and the TeamSpeak process/clients for history charts.
    foreach ([
        "ALTER TABLE vps_metrics ADD COLUMN load_avg_1 FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN load_avg_5 FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN load_avg_15 FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN cpu_steal FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN swap_usage FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN disk_io_read_kbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN disk_io_write_kbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN net_errors INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ts_clients_online INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ts_clients_max INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ts_process_cpu FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ts_process_ram FLOAT DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN sq_username VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN sq_password VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN ts3_filetransfer_port INT DEFAULT NULL",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }
    try {
        $stmt_ts3v = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('ts3_latest_version', '') ON DUPLICATE KEY UPDATE key_value = key_value");
        $stmt_ts3v->execute();
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatic migration - finishing the Level 2 Host layer (IO wait, inode usage,
    // zombie processes, fork rate, temperature). All optional/NULL, older agents
    // do not send these fields at all.
    foreach ([
        "ALTER TABLE vps_metrics ADD COLUMN iowait_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN inode_usage_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN zombie_count INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN fork_rate INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN temperature_c FLOAT DEFAULT NULL",
        // Sila LTE signalu v case: ukazuje, jestli se spojeni zhorsuje
        // (posunuta antena, pretizena bunka, pocasi). Driv byl jen snimek.
        "ALTER TABLE vps_metrics ADD COLUMN lte_rsrp FLOAT DEFAULT NULL",

        // Denni souhrn dostupnosti: jeden radek na monitor a den.
        //
        // monitor_logs se maze po 30 dnech (~3 miliony radku za rok by na
        // sdilenem hostingu neunesla), takze SLA za delsi obdobi nemelo z ceho
        // pocitat - sloupec "rok" ukazoval totez co "30 dni". Agregace prezije
        // mazani a pro SLA nese presne to, co je potreba: pomer uspesnych
        // kontrol. Podrobnosti o vypadcich zustavaji v monitor_events, ktere se
        // nemazou vubec.
        "CREATE TABLE IF NOT EXISTS `uptime_daily` (
            `monitor_id` INT NOT NULL,
            `day` DATE NOT NULL,
            `checks_total` INT NOT NULL DEFAULT 0,
            `checks_up` INT NOT NULL DEFAULT 0,
            `checks_down` INT NOT NULL DEFAULT 0,
            `checks_warning` INT NOT NULL DEFAULT 0,
            `avg_response_ms` FLOAT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`monitor_id`, `day`),
            KEY `idx_uptime_daily_day` (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_clients_total INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN conntrack_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN net_ipv4_kbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN net_ipv6_kbps FLOAT DEFAULT NULL",
        // Provoz pres LTE zalohu v case: spolu s udalostmi wan_lost/wan_restored
        // rika, ktere bajty sly po primarni lince a ktere po zaloze.
        "ALTER TABLE vps_metrics ADD COLUMN net_lte_kbps FLOAT DEFAULT NULL",
        // Wi-Fi clients per band and their Wi-Fi 6E support (bk_wifi_band_totals).
        "ALTER TABLE vps_metrics ADD COLUMN wifi_clients_24g INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_clients_5g INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_clients_6g INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_6e_capable_24g INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_6e_known_24g INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_6e_capable_5g INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_6e_known_5g INT DEFAULT NULL",
        // Archive: a monitor gone for good keeps its history and leaves everything live.
        "ALTER TABLE monitors ADD COLUMN archived_at DATETIME DEFAULT NULL",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `monitor_interface_traffic` (
              `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
              `monitor_id` INT NOT NULL,
              `iface` VARCHAR(64) NOT NULL,
              `date` DATE NOT NULL,
              `rx_bytes_total` DOUBLE DEFAULT 0,
              `tx_bytes_total` DOUBLE DEFAULT 0,
              `rx_packets_total` BIGINT DEFAULT 0,
              `tx_packets_total` BIGINT DEFAULT 0,
              `last_rx_bytes` DOUBLE DEFAULT 0,
              `last_tx_bytes` DOUBLE DEFAULT 0,
              `last_rx_packets` BIGINT DEFAULT 0,
              `last_tx_packets` BIGINT DEFAULT 0,
              `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
              UNIQUE INDEX `idx_monitor_iface_date` (`monitor_id`, `iface`, `date`),
              INDEX `idx_date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (PDOException $e) {}

    // Automatic migration - Service Profiles: the user toggles which dashboard
    // sections show for a given monitor (see get_service_profiles()).
    // NULL = no explicit selection, the dashboard uses the profile's "recommended"
    // defaults, which match exactly what used to be displayed before.
    foreach ([
        "ALTER TABLE monitors ADD COLUMN enabled_metrics TEXT DEFAULT NULL",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }

    // Automatic migration - RCON login for Minecraft (TPS via the Paper/Spigot
    // "tps" command). Optional - left empty, plain SLP is used as before.
    foreach ([
        "ALTER TABLE monitors ADD COLUMN rcon_port INT DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN rcon_password VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN discord_webhook_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN telegram_bot_token VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN slack_webhook_url VARCHAR(255) DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS `incidents` (`id` INT AUTO_INCREMENT PRIMARY KEY, `title` VARCHAR(255) NOT NULL, `impact` VARCHAR(20) DEFAULT 'minor', `status` VARCHAR(20) DEFAULT 'investigating', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `resolved_at` DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `incident_updates` (`id` INT AUTO_INCREMENT PRIMARY KEY, `incident_id` INT NOT NULL, `status` VARCHAR(20) NOT NULL, `message` TEXT NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE users ADD COLUMN totp_secret VARCHAR(32) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN totp_enabled TINYINT(1) DEFAULT 0",
        "ALTER TABLE users ADD COLUMN password_reset_token_hash VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN password_reset_expires DATETIME DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS `audit_log` (`id` INT AUTO_INCREMENT PRIMARY KEY, `actor_user_id` INT DEFAULT NULL, `actor_username` VARCHAR(50) DEFAULT NULL, `action` VARCHAR(50) NOT NULL, `target_type` VARCHAR(30) DEFAULT NULL, `target_id` INT DEFAULT NULL, `description` TEXT DEFAULT NULL, `ip_address` VARCHAR(45) DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (`created_at`), INDEX (`actor_user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `agent_actions` (`id` INT AUTO_INCREMENT PRIMARY KEY, `monitor_id` INT NOT NULL, `action_type` VARCHAR(50) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pending', `result_message` TEXT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `executed_at` DATETIME DEFAULT NULL, FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }

    // Automatic migration - Remote Actions: the missing per-monitor consent.
    // The previous implementation (ed31853) had the HMAC signature and time
    // window right but no server-side check that the router had actually
    // allowed remote actions - any admin could queue a reboot for any monitor.
    // Default 0/NULL = no monitor has anything allowed until the admin
    // explicitly turns it on in its settings.
    foreach ([
        "ALTER TABLE monitors ADD COLUMN remote_actions_enabled TINYINT(1) DEFAULT 0",
        "ALTER TABLE monitors ADD COLUMN allowed_actions VARCHAR(255) DEFAULT NULL",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }

    // Automatic migration - Assets: a physical/logical device that can
    // group several monitors (this relationship could not be expressed at
    // all before - every monitor was independent). Every existing monitor
    // without an asset_id gets its own new 1:1 asset - no guessing which
    // monitors "really" belong together (there is no reliable signal for
    // that - agent_key is always unique, category is just a label). Merging
    // monitors into one asset is strictly a manual admin action (see admin.php).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `assets` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(150) NOT NULL, `icon` VARCHAR(30) DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN asset_id INT DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD CONSTRAINT fk_monitors_asset_id FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Constraint already exists (or the hosting cannot do named FKs via ALTER) - no hard failure
    }
    try {
        $stmt_unassigned = $pdo->query("SELECT id, name FROM monitors WHERE asset_id IS NULL");
        foreach ($stmt_unassigned->fetchAll() as $um) {
            $stmt_new_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
            $stmt_new_asset->execute([$um['name']]);
            $stmt_assign = $pdo->prepare("UPDATE monitors SET asset_id = ? WHERE id = ?");
            $stmt_assign->execute([(int)$pdo->lastInsertId(), $um['id']]);
        }
    } catch (PDOException $e) {
        // The monitors/assets table does not exist yet (fresh install before schema.sql import) - ignore
    }

    // Per-user e-mail language: NULL = follow the global email_lang setting
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_lang VARCHAR(5) DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }

    // Metrics in vps_metrics may be NULL - when the source (StatsBar without CloudLinux,
    // an agent without the sensor) returns nothing, NULL is stored instead of an invented zero
    try {
        $pdo->exec("ALTER TABLE vps_metrics MODIFY COLUMN cpu_usage FLOAT NULL, MODIFY COLUMN ram_usage FLOAT NULL, MODIFY COLUMN hdd_usage FLOAT NULL");
    } catch (PDOException $e) {
        // Table does not exist yet (fresh install) - ignore
    }

    // restart_service needs to know WHICH service to restart - without the column
    // the name never travelled and the action ended "failed" on every agent.
    try {
        $pdo->exec("ALTER TABLE agent_actions ADD COLUMN service_name VARCHAR(64) DEFAULT NULL");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }

    // Read-alert state lives on the user, not in the browser's localStorage -
    // otherwise "mark all as read" only holds on a single computer.
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN alerts_read_log_id INT DEFAULT 0");
    } catch (PDOException $e) {
        // Column already exists - ignore
    }

    // Performance: the monitor list asks for the LATEST row by id for every
    // monitor (response time from logs, metrics from the agent). Without the (monitor_id, id) index
    // to znamenalo scan - endpoint monitors trval ~0,7 s a brzdil celou appku.
    foreach ([
        // Incidents as first-class objects: link to a monitor, acknowledge
        // and postmortem. NULLable - manually created incidents have
        // no monitor link.
        // Editovatelne presety: pojmenovana sada zobrazenych metrik a prahu,
        // kterou lze priradit vice monitorum najednou. Nahrazuje situaci, kdy
        // sly menit jen prahy jednotlivych monitoru a sada metrik byla
        // natvrdo v get_service_profiles().
        "CREATE TABLE IF NOT EXISTS `metric_presets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(80) NOT NULL,
            `description` VARCHAR(255) DEFAULT NULL,
            `service_type` VARCHAR(32) DEFAULT NULL,
            `metrics` TEXT DEFAULT NULL,
            `cpu_threshold` INT DEFAULT NULL,
            `ram_threshold` INT DEFAULT NULL,
            `hdd_threshold` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_preset_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Verejne status stranky: vlastni vyber monitoru, slug a viditelnost.
        // Drive existovala jedna natvrdo slozena stranka bez moznosti neco
        // vybrat - odkaz na /status/ a nic vic.
        "CREATE TABLE IF NOT EXISTS `status_pages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(120) NOT NULL,
            `slug` VARCHAR(60) NOT NULL,
            `description` VARCHAR(255) DEFAULT NULL,
            `is_public` TINYINT(1) NOT NULL DEFAULT 1,
            `monitor_ids` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_status_page_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE monitors ADD COLUMN preset_id INT NULL",

        // Upozorneni na zhorsenou odezvu: dosud slo poznat jen vypadek, ne
        // to, ze se sluzba vlece. NULL = vypnuto (vychozi), aby se stavajici
        // monitory nezacaly hlasit samy od sebe.
        "ALTER TABLE monitors ADD COLUMN latency_threshold_ms INT NULL",
        // Kolik minut musi zhorseni trvat, nez se posle upozorneni - jedna
        // pomala kontrola je sum, ne problem.
        "ALTER TABLE monitors ADD COLUMN latency_threshold_mins INT NOT NULL DEFAULT 5",
        // Heartbeat monitory: sluzba se hlasi sama, my se neptame. Pokryva to,
        // na co aktivni kontrola nedosahne - zalohy, cronjoby, davky. Vsechny
        // sloupce jsou NULL, dokud monitor typu 'heartbeat' nevznikne.
        "ALTER TABLE monitors ADD COLUMN heartbeat_token VARCHAR(64) NULL",
        // Za jak dlouho po sobe se ma uloha ozvat (v sekundach).
        "ALTER TABLE monitors ADD COLUMN heartbeat_interval INT NULL",
        // Tolerance navic, nez se mlceni prohlasi za vypadek. Zaloha spustena
        // cronem v 03:00 nedobehne vzdy na sekundu stejne.
        "ALTER TABLE monitors ADD COLUMN heartbeat_grace INT NULL",
        "ALTER TABLE monitors ADD COLUMN last_heartbeat DATETIME NULL",
        // Uloha muze ohlasit i vlastni selhani (?status=fail), ne jen to, ze
        // dobehla. Bez toho by tise selhavajici zaloha vypadala zdrave.
        "ALTER TABLE monitors ADD COLUMN heartbeat_last_result VARCHAR(10) NULL",
        "ALTER TABLE monitors ADD COLUMN heartbeat_last_message VARCHAR(255) NULL",
        "CREATE UNIQUE INDEX idx_monitors_heartbeat_token ON monitors (heartbeat_token)",
        "ALTER TABLE incidents ADD COLUMN monitor_id INT NULL",
        "ALTER TABLE incidents ADD COLUMN acknowledged_by VARCHAR(64) NULL",
        "ALTER TABLE incidents ADD COLUMN acknowledged_at DATETIME NULL",
        "ALTER TABLE incidents ADD COLUMN postmortem TEXT NULL",
        // Escalation: an incident nobody acknowledged is announced once more,
        // elsewhere, after the configured time. The timestamp prevents it
        // from repeating on every cron run.
        "ALTER TABLE incidents ADD COLUMN escalated_at DATETIME NULL",
        // The audit log never recorded what people signed in with. For failed
        // attempts it is often the only clue whether it was a human or a bot.
        "ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(255) NULL",

        // Speed test results from the router - kept permanently, because the router
        // only holds them in /tmp.
        "CREATE TABLE IF NOT EXISTS `speedtest_results` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `monitor_id` INT NOT NULL,
          `measured_at` DATETIME NOT NULL,
          `download_mbps` FLOAT DEFAULT NULL,
          `upload_mbps` FLOAT DEFAULT NULL,
          `ping_ms` FLOAT DEFAULT NULL,
          `jitter_ms` FLOAT DEFAULT NULL,
          `server_name` VARCHAR(120) DEFAULT NULL,
          `source` VARCHAR(30) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
          UNIQUE KEY `uniq_speedtest_measurement` (`monitor_id`, `measured_at`),
          KEY `idx_speedtest_measured` (`measured_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // The regions query (availability by vantage point) aggregated 30 days of
        // monitor_logs without a usable index - 3.7 s in production while the
        // other public page queries stay under a second. A covering index gives
        // MySQL everything the query reads without touching table rows.
        "CREATE INDEX idx_logs_regions ON monitor_logs (checked_at, checked_from, status, response_time, monitor_id)",

        // Status page display options - which sections the public page shows.
        // NULL = everything, so pages created earlier do not change.
        "ALTER TABLE status_pages ADD COLUMN display_options TEXT DEFAULT NULL",

        // 2FA recovery codes: one-time codes for when the phone is gone -
        // without them, losing the authenticator meant a locked account.
        // Only sha256 hashes are stored (same rule as password reset tokens),
        // so a DB dump cannot be used to sign in.
        "CREATE TABLE IF NOT EXISTS `totp_recovery_codes` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `user_id` INT NOT NULL,
          `code_hash` CHAR(64) NOT NULL,
          `used_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_trc_user` (`user_id`),
          CONSTRAINT `fk_trc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Public e-mail subscriptions: visitors without accounts get outage
        // and recovery mails. Double opt-in - anyone can type any address, so
        // nothing is sent until the owner clicks the confirmation link. Both
        // tokens are stored as sha256 hashes only (same rule as password
        // reset tokens); created_ip exists solely for the sign-up rate limit.
        "CREATE TABLE IF NOT EXISTS `public_subscribers` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `email` VARCHAR(190) NOT NULL,
          `lang` VARCHAR(5) NOT NULL DEFAULT 'cs',
          `confirm_token_hash` CHAR(64) DEFAULT NULL,
          `confirmed_at` DATETIME DEFAULT NULL,
          `unsubscribe_token` CHAR(48) NOT NULL,
          `created_ip` VARCHAR(45) DEFAULT NULL,
          `confirm_sent_at` DATETIME DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY `uniq_pub_sub_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Process history - who was eating CPU and memory at a given moment.
        //
        // Agents have been sending these rankings every minute for a long time,
        // but only the last snapshot was stored in last_details and overwritten
        // by the next report. The chart showed CPU jumping at 19:40, but not why.
        "CREATE TABLE IF NOT EXISTS `process_samples` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `monitor_id` INT NOT NULL,
          `sampled_at` DATETIME NOT NULL,
          `kind` ENUM('cpu','ram') NOT NULL,
          `name` VARCHAR(64) NOT NULL,
          `pid` INT DEFAULT NULL,
          `cpu_pct` FLOAT DEFAULT NULL,
          `ram_mb` FLOAT DEFAULT NULL,
          `kept_reason` ENUM('raw','peak') NOT NULL DEFAULT 'raw',
          PRIMARY KEY (`id`),
          KEY `idx_procsamples_lookup` (`monitor_id`, `sampled_at`, `kind`, `cpu_pct`, `ram_mb`),
          KEY `idx_procsamples_prune` (`sampled_at`, `kept_reason`),
          CONSTRAINT `fk_procsamples_monitor` FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Metrics the agents kept sending while only the last snapshot was stored.
        "ALTER TABLE vps_metrics ADD COLUMN wan_latency_ms FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN dns_latency_ms FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN entropy_avail INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN lte_rsrq FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN lte_rssi FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN lte_sinr FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN lte_uptime_secs INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ups_battery_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN conntrack_count INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN dhcp_leases_count INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN dhcp_reservations_count INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN tailscale_peers INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wireguard_peers INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN openvpn_tunnels INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ram_used_mb FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ram_free_mb FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ram_available_mb FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN ram_total_mb FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_link_mbit FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_uptime_secs INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN log_errors_24h INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN log_warnings_24h INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN btrfs_errors INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN sqm_download_kbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN sqm_upload_kbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN fw_accepted BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN fw_dropped BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN fw_rejected BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN dns_queries BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN dns_cache_hits BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN dns_cache_misses BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN tcp_retrans BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN oom_kills BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN sqm_dropped BIGINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_reconnect_count BIGINT DEFAULT NULL",

        // What was actually sent, to whom, on which channel, and whether it
        // went. Nothing recorded any of that: after an outage nobody could
        // answer "did the alert reach me?", and a channel that had been
        // failing for weeks looked exactly like a quiet one.
        "CREATE TABLE IF NOT EXISTS `notification_log` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `monitor_id` INT DEFAULT NULL,
          `status` VARCHAR(32) NOT NULL,
          `channel` VARCHAR(24) NOT NULL,
          `recipient` VARCHAR(190) DEFAULT NULL,
          `ok` TINYINT(1) NOT NULL DEFAULT 0,
          `error_message` VARCHAR(255) DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY `idx_notif_monitor` (`monitor_id`, `id`),
          KEY `idx_notif_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Daily metric aggregation - the only way to have a series longer than
        // the raw-data retention (30 days).
        "CREATE TABLE IF NOT EXISTS `metrics_daily` (
          `monitor_id` INT NOT NULL,
          `day` DATE NOT NULL,
          `metric_key` VARCHAR(30) NOT NULL,
          `min_val` FLOAT DEFAULT NULL,
          `avg_val` FLOAT DEFAULT NULL,
          `max_val` FLOAT DEFAULT NULL,
          `samples` INT NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`monitor_id`, `day`, `metric_key`),
          KEY `idx_metrics_daily_day` (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // Per-monitor access: which users may see which monitors (several per
        // monitor). Without a row a user sees nothing - the app fails closed.
        "CREATE TABLE IF NOT EXISTS `monitor_users` (
    `monitor_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`monitor_id`, `user_id`),
    KEY `idx_monitor_users_user` (`user_id`),
    FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE INDEX idx_logs_monitor_id_desc ON monitor_logs (monitor_id, id)",
        // The windowed SLA aggregation (websites_overview) filters a year of logs by time.
        "CREATE INDEX idx_logs_checked_at ON monitor_logs (checked_at)",
        "CREATE INDEX idx_vpsm_monitor_id_desc ON vps_metrics (monitor_id, id)",

        // CPU/RAM anomalies compute AVG and STDDEV over 30 days. Without a covering index
        // to byl full scan pres celou tabulku - a ta ma po rozsireni o dalsi
        // metriky pres 60 sloupcu, takze se z disku cetlo mnohonasobne vic dat,
        // nez ten dotaz potrebuje.
        "CREATE INDEX idx_vpsm_cover_cpuram ON vps_metrics (monitor_id, checked_at, cpu_usage, ram_usage)",
        // Totez pro zakladnu odezvy: dotaz filtruje status a pocita statistiku
        // z response_time, takze si vystaci s indexem a k radkum vubec nesahne.
        "CREATE INDEX idx_logs_cover_latency ON monitor_logs (monitor_id, status, checked_at, response_time)",
    ] as $idx_sql) {
        try {
            $pdo->exec($idx_sql);
        } catch (PDOException $e) {
            // Index already exists (or the table does not yet) - ignore
        }
    }

    // --- Router release 20260920: Wi-Fi profile, disk health, WAN path -------
    //
    // One block at the END of the migrations, not spread over the lists above:
    // `speedtest_results` is only created further up in this file, so its new
    // columns cannot sit in the early ALTER list - on an install that predates
    // the table they would fail first and the CREATE would then add the old
    // shape. Every statement is safe to repeat: an ALTER of an existing column
    // fails and is ignored, CREATE is IF NOT EXISTS, the UPDATEs match nothing
    // the second time. DDL added after the first deploy of this version needs
    // a new BK_SCHEMA_VERSION, because the block above runs only when the
    // stored version differs.
    foreach ([
        // Wi-Fi per band (worst AP radio of the band; NULL = no AP radio of
        // that band measured it). Raw 30 days, then metrics_daily.
        "ALTER TABLE vps_metrics ADD COLUMN wifi_noise_24g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_noise_5g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_noise_6g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_busy_24g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_busy_5g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_busy_6g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_busy_other_24g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_busy_other_5g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_busy_other_6g FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_weak_clients SMALLINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_wpa2_clients SMALLINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_6e_unserved TINYINT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wifi_5g_capable_24g SMALLINT DEFAULT NULL",
        // WAN path. The five counters are STEPS (new events since the previous
        // report, NULL across a reboot or a device change), not cumulative
        // values - a day is their sum, never an average.
        "ALTER TABLE vps_metrics ADD COLUMN cpu_core_max FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN cpu_core_max_softirq FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_rx_mbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_tx_mbps FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_errors INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_drops INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_ring_drops INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_link_flaps INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN conntrack_drops INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN agent_run_ms INT DEFAULT NULL",
        // Absolute value: a weekly mean of a signed skew would cancel out.
        "ALTER TABLE vps_metrics ADD COLUMN clock_skew_s INT DEFAULT NULL",

        // The owner's line plan; NULL = not entered, so nothing is ever called
        // "below the plan". wan_plan_ok_pct NULL means the default of 85 %.
        "ALTER TABLE monitors ADD COLUMN wan_plan_down_mbit INT DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN wan_plan_up_mbit INT DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN wan_plan_ok_pct TINYINT UNSIGNED DEFAULT NULL",
        // Consent to the router's own speed test. OFF by default: it moves
        // gigabytes over the owner's line.
        "ALTER TABLE monitors ADD COLUMN wan_probe_enabled TINYINT NOT NULL DEFAULT 0",

        // Speed test context. Who started the test lives in the existing
        // `source` column ('turris' / 'agent'), so there is no started_by column.
        "ALTER TABLE speedtest_results ADD COLUMN iface VARCHAR(32) DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN tool VARCHAR(24) DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN link_mbit INT DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN bytes_received BIGINT DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN bytes_sent BIGINT DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN diagnostics TEXT DEFAULT NULL",
        // 'librespeed' was a constant no query read; every such row was started
        // by the Turris scheduler.
        "UPDATE speedtest_results SET source = 'turris' WHERE source = 'librespeed'",
        // Agents before 0.1.7 divided results of 1000 Mbit/s and more once too
        // often and stored 0.01-0.08. No real line measures that, so the value
        // is unmeasured, not slow. Per column; a file still on the router
        // repairs the row when 0.1.7 sends it again. Only rows without
        // bytes_received (= written by an older agent) are touched, so a later
        // schema bump can never erase a value a 0.1.7 agent really measured.
        "UPDATE speedtest_results SET download_mbps = NULL WHERE download_mbps < 0.1 AND bytes_received IS NULL",
        "UPDATE speedtest_results SET upload_mbps = NULL WHERE upload_mbps < 0.1 AND bytes_received IS NULL",

        // One row per physical disk of a router. disk_key is a hash of
        // transport, port, sysfs model and size - never a serial number or a
        // WWN, neither of which may leave the router. Retention: 730 days after
        // last_seen (column comments are in schema.sql).
        "CREATE TABLE IF NOT EXISTS `storage_disks` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `monitor_id` INT NOT NULL,
          `disk_key` CHAR(16) NOT NULL,
          `name` VARCHAR(16) NOT NULL,
          `transport` VARCHAR(8) NOT NULL,
          `port` VARCHAR(32) DEFAULT NULL,
          `model` VARCHAR(64) DEFAULT NULL,
          `smart_model` VARCHAR(64) DEFAULT NULL,
          `size_bytes` BIGINT UNSIGNED DEFAULT NULL,
          `rotational` TINYINT(1) DEFAULT NULL,
          `first_seen` DATETIME NOT NULL,
          `last_seen` DATETIME NOT NULL,
          `replaced_at` DATETIME DEFAULT NULL,
          `last_sample_at` DATETIME DEFAULT NULL,
          `last_checked_at` INT UNSIGNED DEFAULT NULL,
          `last_power_on_hours` INT UNSIGNED DEFAULT NULL,
          `last_write_sectors` BIGINT UNSIGNED DEFAULT NULL,
          `last_uptime` INT UNSIGNED DEFAULT NULL,
          `alert_state` TEXT DEFAULT NULL,
          UNIQUE KEY `uniq_storage_disk` (`monitor_id`, `disk_key`),
          KEY `idx_storage_disks_seen` (`last_seen`),
          CONSTRAINT `fk_storage_disks_monitor` FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // The disk's day: the only history longer than last_details, which the
        // next report overwrites. At most one upsert per disk per hour.
        // Retention: 730 days, then the row goes (a drive's life is judged in
        // years, and 1,000 routers x 2 disks are about 730,000 rows a year).
        "CREATE TABLE IF NOT EXISTS `storage_disk_daily` (
          `disk_id` INT NOT NULL,
          `day` DATE NOT NULL,
          `samples` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          `smart_passed` TINYINT(1) DEFAULT NULL,
          `temp_min` SMALLINT DEFAULT NULL,
          `temp_max` SMALLINT DEFAULT NULL,
          `temp_sum` INT DEFAULT NULL,
          `temp_n` SMALLINT UNSIGNED DEFAULT NULL,
          `power_on_hours` INT UNSIGNED DEFAULT NULL,
          `power_cycles` INT UNSIGNED DEFAULT NULL,
          `unsafe_shutdowns` INT UNSIGNED DEFAULT NULL,
          `reallocated_sectors` BIGINT UNSIGNED DEFAULT NULL,
          `pending_sectors` BIGINT UNSIGNED DEFAULT NULL,
          `offline_uncorrectable` BIGINT UNSIGNED DEFAULT NULL,
          `reported_uncorrect` BIGINT UNSIGNED DEFAULT NULL,
          `crc_errors` BIGINT UNSIGNED DEFAULT NULL,
          `runtime_bad_blocks` BIGINT UNSIGNED DEFAULT NULL,
          `media_errors` BIGINT UNSIGNED DEFAULT NULL,
          `error_log_count` INT UNSIGNED DEFAULT NULL,
          `wear_pct` SMALLINT UNSIGNED DEFAULT NULL,
          `emmc_life` TINYINT UNSIGNED DEFAULT NULL,
          `written_bytes` BIGINT UNSIGNED DEFAULT NULL,
          `host_written_bytes` BIGINT UNSIGNED DEFAULT NULL,
          `host_written_partial` TINYINT(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (`disk_id`, `day`),
          KEY `idx_sdd_day` (`day`),
          CONSTRAINT `fk_sdd_disk` FOREIGN KEY (`disk_id`) REFERENCES `storage_disks`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // What the recommendation engine remembers between evaluations: the
        // hysteresis state, the digest week an item first went out in, and the
        // mutes. Retention: an unmuted row 90 days after last_seen; a mute is
        // the owner's decision and goes only with the monitor.
        "CREATE TABLE IF NOT EXISTS `router_rec_state` (
          `monitor_id` INT NOT NULL,
          `rec_key` VARCHAR(80) NOT NULL,
          `rule_id` VARCHAR(40) NOT NULL,
          `active` TINYINT(1) NOT NULL DEFAULT 0,
          `severity` VARCHAR(10) DEFAULT NULL,
          `first_seen` DATETIME DEFAULT NULL,
          `last_seen` DATETIME DEFAULT NULL,
          `first_digest_week` CHAR(8) DEFAULT NULL,
          `raised_digest_week` CHAR(8) DEFAULT NULL,
          `muted_at` DATETIME DEFAULT NULL,
          `muted_by` VARCHAR(50) DEFAULT NULL,
          `muted_severity` VARCHAR(10) DEFAULT NULL,
          `mute_reason` VARCHAR(255) DEFAULT NULL,
          PRIMARY KEY (`monitor_id`, `rec_key`),
          KEY `idx_rec_state_seen` (`last_seen`),
          CONSTRAINT `fk_rec_state_monitor` FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // The message log used to record only alerts, so "did that invitation
        // e-mail go out?" had no answer at all. Logging moved inside
        // send_email(), which means every kind of message now lands here and
        // the row has to say WHICH kind it was.
        // `kind` groups them (alert, digest, invitation, password_reset, ...),
        // `subject` is the only part of the message ever stored - never the
        // body, which would turn the log into a copy of people's mail.
        // `method` is how it left: 'smtp' = an authenticated server confirmed
        // it, 'fallback' = only handed to the local mail(), NULL = no route
        // confirmed anything (a failed attempt).
        "ALTER TABLE notification_log ADD COLUMN kind VARCHAR(32) NOT NULL DEFAULT 'other'",
        "ALTER TABLE notification_log ADD COLUMN subject VARCHAR(190) DEFAULT NULL",
        "ALTER TABLE notification_log ADD COLUMN method VARCHAR(16) DEFAULT NULL",
        // The admin page filters by kind and pages by id - without this the
        // "show me every failed invitation" query is a full table scan.
        "CREATE INDEX idx_notif_kind ON notification_log (kind, id)",
        // Availability in TIME per day (W1-B1): the row counts let a silent
        // agent's blackout vanish. NULL = a day rolled up before this existed;
        // cron refills the retained 31 days once (uptime_daily_time_backfilled).
        "ALTER TABLE uptime_daily ADD COLUMN secs_up INT DEFAULT NULL",
        "ALTER TABLE uptime_daily ADD COLUMN secs_down INT DEFAULT NULL",
        "ALTER TABLE uptime_daily ADD COLUMN secs_warning INT DEFAULT NULL",
        "ALTER TABLE uptime_daily ADD COLUMN secs_silent INT DEFAULT NULL",
        "ALTER TABLE uptime_daily ADD COLUMN secs_maintenance INT DEFAULT NULL",
        "ALTER TABLE uptime_daily ADD COLUMN secs_unmeasured INT DEFAULT NULL",
        // The router's masked error lines (W1-C3, owner decision 5.7): on by
        // default, switchable per monitor; the agent hears it in the answer.
        "ALTER TABLE monitors ADD COLUMN log_lines_enabled TINYINT(1) NOT NULL DEFAULT 1",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Column or table already exists - ignore
        }
    }

    // Store the current schema version - migrations get skipped next time
    try {
        $stmt_ver = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt_ver->execute([BK_SCHEMA_VERSION]);
    } catch (PDOException $e) {
        // The settings table does not exist (before schema import) - migrations will run again
    }

    } // konec bloku migrací (schema_version)
} catch (PDOException $e) {
    bk_database_unavailable($e);
}

// Loads dynamic settings from the database
function get_settings($pdo) {
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT key_name, key_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }
    } catch (PDOException $e) {
        // Table does not exist yet (e.g. before import) - ignore
    }
    return $settings;
}

$system_settings = get_settings($pdo);

// Helper: checks whether a setting is defined safely in config.php or the server environment
function is_setting_env_defined($key) {
    $const_name = strtoupper($key);
    return defined($const_name) || getenv($const_name) !== false || isset($_SERVER[$const_name]);
}

// Helper: fetch one setting with a default (config.php/environment take precedence)
// Never returns null (PHP 8.1+ deprecations when passed to htmlspecialchars etc.)
function get_setting($key, $default = '') {
    global $system_settings;

    $const_name = strtoupper($key);

    // Priority 1: a constant defined in config.php
    if (defined($const_name) && constant($const_name) !== null) {
        return constant($const_name);
    }

    // Priority 2: an environment variable (getenv)
    $env_val = getenv($const_name);
    if ($env_val !== false) {
        return $env_val;
    }

    // Priority 3: a server variable (e.g. from .htaccess)
    if (isset($_SERVER[$const_name])) {
        return $_SERVER[$const_name];
    }

    // Priority 4: the value stored in the database
    $val = $system_settings[$key] ?? $default;
    return $val === null ? $default : $val;
}

/**
 * The single list of system settings keys.
 *
 * It used to exist three times: in admin.php for writing, and in api.php
 * separately for reading and for writing. They drifted apart - `whatsapp_*`
 * could be saved, but `get_settings` never returned them, so React showed
 * empty fields and the next "Save all" overwrote them with empty values.
 * Settings disappeared without anyone making a mistake. Hence one source of
 * truth; parity is additionally guarded by a test
 * (tests/run_settings_parity_lint.php).
 */
function bk_settings_keys(): array {
    return [
        'site_title', 'site_url', 'email_lang', 'cron_key', 'cron_location',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'sms_gateway_type', 'twilio_sid', 'twilio_token', 'twilio_from',
        'smsbrana_user', 'smsbrana_password',
        'agent_offline_timeout', 'agent_notifications_enabled', 'agent_notify_admin_only',
        // How many consecutive failures declare an outage. 1 = alert on the first.
        'alert_confirm_failures',
        'discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id', 'slack_webhook_url',
        'oauth_github_client_id', 'oauth_github_client_secret',
        'oauth_google_client_id', 'oauth_google_client_secret',
        'oauth_discord_client_id', 'oauth_discord_client_secret',
        'oauth_gitlab_client_id', 'oauth_gitlab_client_secret',
        'custom_logo_url', 'custom_color_theme', 'custom_nav_links', 'portal_url',
        'metrics_token', 'sla_goal_pct', 'ts3_latest_version',
        'pushover_user_key', 'pushover_api_token', 'pagerduty_routing_key',
        'ssl_alert_days', 'agent_registration_token',
        'escalation_enabled', 'escalation_after_mins', 'escalation_webhook_url',
        // The daily reminder of what is still broken. On by default: an alert
        // fires only on a CHANGE of state, so without it a running outage is
        // announced once and then never mentioned again.
        'daily_reminder_enabled', 'daily_reminder_hour',
        'collection_max_age_secs', 'trusted_proxies',
        'process_history_days', 'process_history_peak_after_days', 'process_history_peak_pct',
    ];
}

/**
 * Keys whose value is masked when read, and whose masked value is ignored on
 * write (otherwise "Save all" would store the literal `••••••1234`).
 */
function bk_settings_secret_keys(): array {
    return [
        'smtp_pass', 'twilio_token', 'smsbrana_password',
        'oauth_github_client_secret', 'oauth_google_client_secret',
        'oauth_discord_client_secret', 'oauth_gitlab_client_secret',
        'pushover_api_token', 'pagerduty_routing_key', 'metrics_token',
        'agent_registration_token',
    ];
}
