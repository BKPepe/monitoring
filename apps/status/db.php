<?php
/**
 * Databázové připojení a načtení nastavení
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

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Verze schématu - při změně migrací níže zvyšte hodnotu (a v schema.sql).
    // Migrace se díky tomu spouští jen jednou, ne při každém requestu.
    define('BK_SCHEMA_VERSION', '20260730');

    $bk_current_schema = false;
    try {
        $stmt_ver = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'");
        $bk_current_schema = $stmt_ver->fetchColumn();
    } catch (PDOException $e) {
        // Tabulka settings ještě neexistuje - migrace se pokusí doběhnout níže
    }

    if ($bk_current_schema !== BK_SCHEMA_VERSION) {

    // Automatická migrace - přidání sloupce checked_from do tabulky monitor_logs
    try {
        $pdo->exec("ALTER TABLE monitor_logs ADD COLUMN checked_from VARCHAR(50) DEFAULT 'Main Server'");
    } catch (PDOException $e) {
        // Sloupec již existuje nebo tabulka neexistuje (např. před importem), ignorujeme
    }
    
    // Automatická migrace - přidání sloupce role do tabulky users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user'");
    } catch (PDOException $e) {
        // Sloupec již existuje, ignorujeme
    }
    
    // Zajištění, že první registrovaný uživatel (hlavní administrátor) má roli admin
    try {
        $pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    
    // Vytvoření vazební tabulky pro odběry notifikací uživatelů
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
    
    // Automatická migrace - přidání sloupce notes do tabulky monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN notes TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatická migrace - přidání sloupce maintenance do tabulky monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatická migrace - přidání sloupce monitored_processes do tabulky monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN monitored_processes TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }
    
    // Automatická migrace - přidání sloupce whatsapp_apikey do tabulky users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_apikey VARCHAR(100) DEFAULT NULL");
    } catch (PDOException $e) {
        // Sloupec již existuje, ignorujeme
    }

    // Automatická migrace - přidání sloupců pro OAuth v tabulce users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(50) DEFAULT NULL");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN oauth_id VARCHAR(100) DEFAULT NULL");
    } catch (PDOException $e) {}
    
    // Automatická migrace - přidání sloupce sms_notifications do tabulky users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN sms_notifications TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Sloupec již existuje, ignorujeme
    }

    // Automatická migrace - přidání sloupců pro plánovanou údržbu do tabulky monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance_description TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme, pokud sloupec již existuje
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance_start DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme, pokud sloupec již existuje
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN maintenance_end DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme, pokud sloupec již existuje
    }

    // Automatická migrace - zajištění délky sloupce status v monitors a monitor_logs
    try {
        $pdo->exec("ALTER TABLE monitors MODIFY COLUMN status VARCHAR(20) DEFAULT 'unknown'");
    } catch (PDOException $e) {}
    try {
        $pdo->exec("ALTER TABLE monitor_logs MODIFY COLUMN status VARCHAR(20) NOT NULL");
    } catch (PDOException $e) {}

    // Automatická migrace - přidání sloupce cpanel_stats_url do tabulky monitors
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN cpanel_stats_url VARCHAR(255) DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme, pokud sloupec již existuje
    }

    // Automatická migrace - převod starých cpanel monitorů na web monitory s cpanel_stats_url
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

    // Automatická migrace - přidání sloupce whatsapp_notifications do tabulky users
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp_notifications TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatická migrace - přidání sloupce whatsapp_notifications do tabulky user_subscriptions
    try {
        $pdo->exec("ALTER TABLE user_subscriptions ADD COLUMN whatsapp_notifications TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatická migrace - vygenerování agent_key pro všechny existující monitory bez klíče
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

    // Automatická migrace - zvětšení sloupce status na VARCHAR(20) pro podporu 'maintenance' (11 znaků)
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

    // Automatická migrace - přidání prahových hodnot pro VPS agenta
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

    // Automatická migrace - propustnost sítě (KB/s) hlášená agenty; NULL u starších
    // řádků a u agentů, kteří síť ještě nehlásí (chybí předchozí vzorek pro výpočet).
    try {
        $pdo->exec("ALTER TABLE vps_metrics ADD COLUMN net_usage FLOAT DEFAULT NULL");
    } catch (PDOException $e) {
        // Ignorujeme
    }

    // Automatická migrace - check pipeline (DNS/TCP/TLS/HTTP/body fáze u 'web' monitorů)
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

    // Automatická migrace - infrastructure report digest (config change tracking + event log)
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

    // Automatická migrace - hloubkový TeamSpeak monitoring + Host/VPS vrstva (load average,
    // CPU steal, swap, disk I/O, síťové chyby) a TeamSpeak proces/klienti pro grafy historie.
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

    // Automatická migrace - dokončení Level 2 Host vrstvy (IO wait, inode usage,
    // zombie procesy, fork rate, teplota). Vše volitelné/NULL, starší agenti tato
    // pole neposílají vůbec.
    foreach ([
        "ALTER TABLE vps_metrics ADD COLUMN iowait_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN inode_usage_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN zombie_count INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN fork_rate INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN temperature_c FLOAT DEFAULT NULL",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }

    // Automatická migrace - Service Profiles: uživatel si zapíná/vypíná, které
    // sekce dashboardu se pro daný monitor zobrazují (viz get_service_profiles()).
    // NULL = žádný explicitní výběr, dashboard použije "recommended" výchozí
    // hodnoty profilu, které přesně odpovídají tomu, co se zobrazovalo dosud.
    foreach ([
        "ALTER TABLE monitors ADD COLUMN enabled_metrics TEXT DEFAULT NULL",
    ] as $migration_sql) {
        try {
            $pdo->exec($migration_sql);
        } catch (PDOException $e) {
            // Ignorujeme
        }
    }

    // Automatická migrace - RCON přihlášení pro Minecraft (TPS přes Paper/Spigot
    // příkaz "tps"). Volitelné - bez vyplnění se používá jen SLP jako dosud.
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

    // Automatická migrace - Remote Actions: chybějící per-monitor souhlas.
    // Předchozí implementace (ed31853) měla HMAC podpis a časové okno správně,
    // ale žádnou serverovou kontrolu, jestli daný router vzdálené akce vůbec
    // povolil - kdokoliv admin mohl zařadit reboot pro libovolný monitor.
    // Výchozí hodnota 0/NULL = žádný monitor nemá nic povoleno, dokud si to
    // admin v jeho nastavení výslovně nezapne.
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

    // Automatická migrace - Assets: fyzické/logické zařízení, které může
    // sdružovat víc monitorů (dřív tuhle vazbu nešlo vyjádřit vůbec - každý
    // monitor byl nezávislý). Každý existující monitor bez asset_id dostane
    // svůj vlastní nový 1:1 asset - žádné hádání, které monitory spolu
    // "opravdu" patří (pro to není spolehlivý signál - agent_key je vždy
    // unikátní, category je jen popisek). Slučování víc monitorů do jednoho
    // assetu je od teď výhradně ruční akce administrátora (viz admin.php).
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `assets` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(150) NOT NULL, `icon` VARCHAR(30) DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN asset_id INT DEFAULT NULL");
    } catch (PDOException $e) {
        // Sloupec už existuje, ignorujeme
    }
    try {
        $pdo->exec("ALTER TABLE monitors ADD CONSTRAINT fk_monitors_asset_id FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL");
    } catch (PDOException $e) {
        // Constraint už existuje (nebo hosting nepodporuje pojmenované FK přes ALTER) - bez tvrdého selhání
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
        // Tabulka monitors/assets ještě neexistuje (čerstvá instalace před importem schema.sql) - ignorujeme
    }

    // Uložení aktuální verze schématu - migrace se příště přeskočí
    try {
        $stmt_ver = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt_ver->execute([BK_SCHEMA_VERSION]);
    } catch (PDOException $e) {
        // Tabulka settings neexistuje (před importem schematu) - migrace proběhnou znovu
    }

    } // konec bloku migrací (schema_version)
} catch (PDOException $e) {
    // Pokud se nepodaří připojit, zobrazíme srozumitelné chybové hlášení
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

// Funkce pro načtení dynamických nastavení z databáze
function get_settings($pdo) {
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT key_name, key_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }
    } catch (PDOException $e) {
        // Tabulka ještě neexistuje (např. před importem) - ignorujeme
    }
    return $settings;
}

$system_settings = get_settings($pdo);

// Pomocná funkce pro ověření, zda je nastavení definováno bezpečně v config.php nebo v prostředí serveru
function is_setting_env_defined($key) {
    $const_name = strtoupper($key);
    return defined($const_name) || getenv($const_name) !== false || isset($_SERVER[$const_name]);
}

// Pomocná funkce pro získání konkrétního nastavení s výchozí hodnotou (s prioritou pro config.php/prostředí)
// Nikdy nevrací null (kvůli PHP 8.1+ deprecacím při předání do htmlspecialchars apod.)
function get_setting($key, $default = '') {
    global $system_settings;

    $const_name = strtoupper($key);

    // 1. Priorita: Konstanta definovaná v config.php
    if (defined($const_name) && constant($const_name) !== null) {
        return constant($const_name);
    }

    // 2. Priorita: Proměnná prostředí (getenv)
    $env_val = getenv($const_name);
    if ($env_val !== false) {
        return $env_val;
    }

    // 3. Priorita: Serverová proměnná (např. z .htaccess)
    if (isset($_SERVER[$const_name])) {
        return $_SERVER[$const_name];
    }

    // 4. Fallback: Hodnota uložená v databázi
    $val = $system_settings[$key] ?? $default;
    return $val === null ? $default : $val;
}
