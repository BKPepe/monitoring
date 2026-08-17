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

    // Schema version - bump when changing the migrations below (and schema.sql).
    // Thanks to this, migrations run only once, not on every request.
    define('BK_SCHEMA_VERSION', '20260817b');

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
    
    // Make sure the first registered user (the main administrator) has the admin role
    try {
        $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1");
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

    // Store the current schema version - migrations get skipped next time
    try {
        $stmt_ver = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt_ver->execute([BK_SCHEMA_VERSION]);
    } catch (PDOException $e) {
        // The settings table does not exist (before schema import) - migrations will run again
    }

    } // konec bloku migrací (schema_version)
} catch (PDOException $e) {
    // If the connection fails, show an intelligible error message
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="cs">
    <head>
        <meta charset="UTF-8">
        <title>Chyba připojení k databázi</title>
        <style>
            body { background: #0f0f13; color: #fff; font-family: sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .error-card { background: #1a1a24; padding: 2rem; border-radius: 12px; border-top: 4px solid #ff4444; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
            h1 { font-size: 1.5rem; margin-top: 0; color: #ff4444; }
            code { background: #0c0c0f; padding: 0.2rem 0.4rem; border-radius: 4px; color: #e5c07b; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <h1>Chyba databáze</h1>
            <p>Nepodařilo se připojit k databázi. Zkontrolujte prosím nastavení v souboru <code>status/config.php</code>.</p>
            <p style="font-size: 0.85rem; color: #888;">Podrobnosti: <?php echo htmlspecialchars($e->getMessage()); ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
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
