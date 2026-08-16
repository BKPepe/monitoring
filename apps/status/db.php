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

// Čísla do JSON v nejkratším zápisu, který se zpětně načte na tutéž hodnotu.
//
// Bez tohohle vypisuje json_encode() plný desetinný rozvoj doublu, takže se
// z hodnoty 35,2 (sloupec FLOAT) stane 35.20000000000000284217094304040074348
// a z 0,4 padesátiznakový řetězec. Na status stránce se každých 24 h vkládá
// 1 728 bodů na čtyřech řadách u každého monitoru - tímhle narostla stránka
// na 1,6 MB a server ji tak dlouho skládal.
//
// PHP má -1 jako výchozí od verze 7.1, tenhle hosting to má přenastavené.
// Nastavuje se tady, protože přes db.php prochází každá stránka i API.
ini_set('serialize_precision', '-1');

try {
    $db_driver = defined('DB_DRIVER') ? strtolower(DB_DRIVER) : (defined('BK_DATABASE_URL') && strpos(BK_DATABASE_URL, 'postgres') !== false ? 'pgsql' : 'mysql');
    if ($db_driver === 'pgsql' || $db_driver === 'postgres') {
        $db_port = defined('DB_PORT') ? DB_PORT : 5432;
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . $db_port . ";dbname=" . DB_NAME;
    } else {
        // DB_PORT se dřív používal jen u Postgresu, takže MySQL na jiném
        // než výchozím portu se nepřipojila a uživatel viděl jen obecnou
        // hlášku "Chyba připojení k databázi".
        $db_port = defined('DB_PORT') ? (int)DB_PORT : 3306;
        $dsn = "mysql:host=" . DB_HOST . ";port=" . $db_port . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    }
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // Verze schématu - při změně migrací níže zvyšte hodnotu (a v schema.sql).
    // Migrace se díky tomu spouští jen jednou, ne při každém requestu.
    define('BK_SCHEMA_VERSION', '20260817a');

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

    // Per-user jazyk e-mailů: NULL = řídí se globálním nastavením email_lang
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email_lang VARCHAR(5) DEFAULT NULL");
    } catch (PDOException $e) {
        // Sloupec už existuje, ignorujeme
    }

    // Metriky ve vps_metrics smí být NULL - když zdroj (StatsBar bez CloudLinux,
    // agent bez čidla) hodnotu nevrací, ukládá se NULL místo vymyšlené nuly
    try {
        $pdo->exec("ALTER TABLE vps_metrics MODIFY COLUMN cpu_usage FLOAT NULL, MODIFY COLUMN ram_usage FLOAT NULL, MODIFY COLUMN hdd_usage FLOAT NULL");
    } catch (PDOException $e) {
        // Tabulka ještě neexistuje (čerstvá instalace) - ignorujeme
    }

    // restart_service potřebuje vědět KTEROU službu restartovat - bez sloupce
    // se jméno nikdy nepřeneslo a akce u všech agentů končila "failed".
    try {
        $pdo->exec("ALTER TABLE agent_actions ADD COLUMN service_name VARCHAR(64) DEFAULT NULL");
    } catch (PDOException $e) {
        // Sloupec už existuje, ignorujeme
    }

    // Přečtená upozornění se drží na uživateli, ne v localStorage prohlížeče -
    // jinak "označit vše jako přečtené" platí jen na jednom počítači.
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN alerts_read_log_id INT DEFAULT 0");
    } catch (PDOException $e) {
        // Sloupec už existuje, ignorujeme
    }

    // Výkon: seznam monitorů se ptá na POSLEDNÍ řádek podle id pro každý
    // monitor (odezva z logů, metriky z agenta). Bez indexu (monitor_id, id)
    // to znamenalo scan - endpoint monitors trval ~0,7 s a brzdil celou appku.
    foreach ([
        // Incidenty jako plnohodnotné objekty: vazba na monitor, převzetí
        // (acknowledge) a postmortem. NULLable - ručně založené incidenty
        // vazbu na monitor nemají.
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
        // Eskalace: incident, který nikdo nepřevzal, se po nastavené době
        // ohlásí ještě jednou a jinam. Razítko brání tomu, aby se to
        // opakovalo při každém běhu cronu.
        "ALTER TABLE incidents ADD COLUMN escalated_at DATETIME NULL",
        // Audit log dosud nezaznamenával, čím se kdo přihlásil. U neúspěšných
        // pokusů je to často jediné vodítko, jestli šlo o člověka, nebo o bota.
        "ALTER TABLE audit_log ADD COLUMN user_agent VARCHAR(255) NULL",

        // Výsledky měření rychlosti z routeru - trvale, protože router
        // si je drží jen v /tmp.
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

        // Volby zobrazení status stránky - které sekce veřejná stránka ukáže.
        // NULL = všechno, aby se stránky založené dřív nezměnily.
        "ALTER TABLE status_pages ADD COLUMN display_options TEXT DEFAULT NULL",

        // Historie procesů - kdo v danou chvíli žral CPU a paměť.
        //
        // Agenti tyhle žebříčky posílají každou minutu už dlouho, ale ukládal
        // se jen poslední snímek do last_details, který další hlášení přepsalo.
        // Z grafu tedy šlo vidět, že v 19:40 vyskočilo CPU, ale ne čím.
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

        // Metriky, které agenti posílali, ale ukládal se jen poslední snímek.
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

        // Denní agregace metrik - jediný způsob, jak mít řadu delší než
        // retence syrových dat (30 dní).
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
        // Okenní SLA agregace (websites_overview) filtruje rok logů podle času.
        "CREATE INDEX idx_logs_checked_at ON monitor_logs (checked_at)",
        "CREATE INDEX idx_vpsm_monitor_id_desc ON vps_metrics (monitor_id, id)",

        // Anomalie CPU/RAM pocitaji AVG a STDDEV nad 30 dny. Bez krycího indexu
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
            // Index už existuje (nebo tabulka ještě ne) - ignorujeme
        }
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
        'whatsapp_api_endpoint', 'whatsapp_token', 'whatsapp_phone_number',
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
        'smtp_pass', 'twilio_token', 'smsbrana_password', 'whatsapp_token',
        'oauth_github_client_secret', 'oauth_google_client_secret',
        'oauth_discord_client_secret', 'oauth_gitlab_client_secret',
        'pushover_api_token', 'pagerduty_routing_key', 'metrics_token',
        'agent_registration_token',
    ];
}
