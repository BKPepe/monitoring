-- SQL Schema pro Blood Kings Status Monitoring
-- Vytvořte databázi a naimportujte tento soubor
--
-- POZOR: Hodnota 'schema_version' na konci souboru musí odpovídat konstantě
-- BK_SCHEMA_VERSION v db.php. Při změně schématu aktualizujte obě místa.

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `whatsapp_apikey` VARCHAR(100) DEFAULT NULL,
  `sms_notifications` TINYINT(1) DEFAULT 0,
  `whatsapp_notifications` TINYINT(1) DEFAULT 0,
  `role` VARCHAR(20) DEFAULT 'user',
  `oauth_provider` VARCHAR(50) DEFAULT NULL,
  `oauth_id` VARCHAR(100) DEFAULT NULL,
  `totp_secret` VARCHAR(32) DEFAULT NULL,
  `totp_enabled` TINYINT(1) DEFAULT 0,
  `password_reset_token_hash` VARCHAR(64) DEFAULT NULL, -- sha256 raw tokenu, ne sam token (viz set_password v admin.php)
  `password_reset_expires` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `actor_user_id` INT DEFAULT NULL, -- NULL u neúspěšného loginu neznámým/neexistujícím uživatelem
  `actor_username` VARCHAR(50) DEFAULT NULL, -- kopie jména v čase akce, přežije i smazání uživatele
  `action` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(30) DEFAULT NULL,
  `target_id` INT DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`created_at`),
  INDEX (`actor_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL, -- Fyzické/logické zařízení (server, router, host) - může sdružovat víc monitorů
  `icon` VARCHAR(30) DEFAULT NULL, -- Volitelná FontAwesome ikona, jinak se odvodí od typu prvního monitoru
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `type` VARCHAR(20) NOT NULL, -- 'web', 'port', 'minecraft', 'teamspeak', 'discord', 'vps'
  `target` VARCHAR(255) NOT NULL, -- URL, IP, hostname nebo discord guild_id
  `port` INT DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT 'Ostatní', -- 'VPS', 'Weby', 'Herní servery', atd.
  `status` VARCHAR(20) DEFAULT 'unknown', -- 'up', 'down', 'unknown', 'maintenance'
  `last_checked` DATETIME DEFAULT NULL,
  `last_status_change` DATETIME DEFAULT NULL,
  `email_notifications` TINYINT(1) DEFAULT 1,
  `sms_notifications` TINYINT(1) DEFAULT 0,
  `agent_key` VARCHAR(64) DEFAULT NULL, -- Unikátní klíč pro VPS agenta
  `timeout` INT DEFAULT 5, -- v sekundách
  `last_details` TEXT DEFAULT NULL, -- Ukládá JSON s dodatečnými informacemi (hráči, RAM, CPU atd.)
  `notes` TEXT DEFAULT NULL,
  `maintenance` TINYINT(1) DEFAULT 0,
  `maintenance_description` TEXT DEFAULT NULL,
  `maintenance_start` DATETIME DEFAULT NULL,
  `maintenance_end` DATETIME DEFAULT NULL,
  `monitored_processes` TEXT DEFAULT NULL, -- Čárkou oddělený seznam hlídaných procesů (VPS agent)
  `cpanel_stats_url` VARCHAR(255) DEFAULT NULL,
  `cpu_threshold` INT DEFAULT 90, -- Práh varování v %
  `ram_threshold` INT DEFAULT 95,
  `hdd_threshold` INT DEFAULT 90,
  `body_keyword` VARCHAR(255) DEFAULT NULL, -- Volitelný řetězec, který musí obsahovat tělo odpovědi (check pipeline)
  `config_snapshot` TEXT DEFAULT NULL, -- JSON s posledním stavem (scheme/dns_ok/cert_valid_to/agent_connected) pro detekci změn config
  `sq_username` VARCHAR(100) DEFAULT NULL, -- Volitelné TeamSpeak ServerQuery přihlášení (hlubší data - server groups, plný clientlist)
  `sq_password` VARCHAR(255) DEFAULT NULL,
  `ts3_filetransfer_port` INT DEFAULT NULL, -- Výchozí 30033, pokud nevyplněno
  `enabled_metrics` TEXT DEFAULT NULL, -- JSON pole klíčů zapnutých metrik (Service Profiles). NULL = použít recommended výchozí hodnoty profilu.
  `rcon_port` INT DEFAULT NULL, -- Minecraft RCON port (výchozí 25575) - volitelné, umožní TPS přes Paper/Spigot
  `rcon_password` VARCHAR(255) DEFAULT NULL,
  `discord_webhook_url` VARCHAR(255) DEFAULT NULL, -- Per-monitor Discord webhook (přepíše globální nastavení)
  `telegram_bot_token` VARCHAR(255) DEFAULT NULL, -- Per-monitor Telegram bot token
  `telegram_chat_id` VARCHAR(100) DEFAULT NULL, -- Per-monitor Telegram chat ID
  `slack_webhook_url` VARCHAR(255) DEFAULT NULL, -- Per-monitor Slack webhook
  `remote_actions_enabled` TINYINT(1) DEFAULT 0, -- Souhlas s Remote Actions pro tento konkrétní monitor - výchozí VYPNUTO
  `allowed_actions` VARCHAR(255) DEFAULT NULL, -- Čárkou oddělený seznam povolených akcí (podmnožina restart_wan,restart_wireguard,reboot_router,renew_dhcp,restart_service,reconnect_pppoe)
  `asset_id` INT DEFAULT NULL, -- Fyzické/logické zařízení, ke kterému monitor patří (viz `assets`) - NULL = zatím nepřiřazeno
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`agent_key`),
  FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitor_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monitor_id` INT DEFAULT NULL,
  `monitor_name` VARCHAR(100) NOT NULL,
  `monitor_type` VARCHAR(20) DEFAULT NULL,
  `event_type` VARCHAR(50) NOT NULL, -- monitor_added, monitor_removed, scheme_upgraded, dns_lost, dns_recovered, cert_renewed, agent_connected, agent_disconnected
  `description` VARCHAR(255) DEFAULT NULL,
  `occurred_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE SET NULL,
  INDEX (`occurred_at`),
  INDEX (`monitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitor_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monitor_id` INT NOT NULL,
  `status` VARCHAR(20) NOT NULL, -- 'up', 'down', 'maintenance'
  `response_time` INT DEFAULT NULL, -- v milisekundách
  `checked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `error_message` TEXT DEFAULT NULL,
  `checked_from` VARCHAR(50) DEFAULT 'Main Server',
  `check_stages` TEXT DEFAULT NULL, -- JSON s rozpadem DNS/TCP/TLS/HTTP/body fází (jen u typu 'web'), viz check_http()
  FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
  INDEX (`checked_at`),
  INDEX (`monitor_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vps_metrics` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monitor_id` INT NOT NULL,
  `cpu_usage` FLOAT NOT NULL, -- v %
  `ram_usage` FLOAT NOT NULL, -- v %
  `hdd_usage` FLOAT NOT NULL, -- v %
  `net_usage` FLOAT DEFAULT NULL, -- v KB/s (RX+TX), NULL pokud agent síť nehlásí
  `load_avg_1` FLOAT DEFAULT NULL, -- Load average (1 min)
  `load_avg_5` FLOAT DEFAULT NULL, -- Load average (5 min)
  `load_avg_15` FLOAT DEFAULT NULL, -- Load average (15 min)
  `cpu_steal` FLOAT DEFAULT NULL, -- CPU steal time v % (virtualizace)
  `swap_usage` FLOAT DEFAULT NULL, -- Využití swapu v %
  `disk_io_read_kbps` FLOAT DEFAULT NULL,
  `disk_io_write_kbps` FLOAT DEFAULT NULL,
  `net_errors` INT DEFAULT NULL, -- Součet rx/tx chyb a zahozených paketů od posledního běhu
  `ts_clients_online` INT DEFAULT NULL, -- TeamSpeak - počet klientů (pro graf historie)
  `ts_clients_max` INT DEFAULT NULL,
  `ts_process_cpu` FLOAT DEFAULT NULL, -- CPU využité přímo procesem ts3server (ne celým hostem)
  `ts_process_ram` FLOAT DEFAULT NULL, -- RAM v MB využitá procesem ts3server
  `iowait_pct` FLOAT DEFAULT NULL, -- CPU čas čekání na I/O v %
  `inode_usage_pct` FLOAT DEFAULT NULL, -- Zaplnění inodů kořenového disku v %
  `zombie_count` INT DEFAULT NULL, -- Počet procesů ve stavu zombie (Z)
  `fork_rate` INT DEFAULT NULL, -- Nové procesy (fork) od posledního běhu agenta
  `temperature_c` FLOAT DEFAULT NULL, -- Teplota CPU/desky ve °C (NULL, pokud hostitel/VM nevystavuje thermal zóny)
  `wifi_clients_total` INT DEFAULT NULL, -- Celkový počet Wi-Fi klientů (OpenWrt)
  `conntrack_pct` FLOAT DEFAULT NULL, -- Využití conntrack tabulky v % (OpenWrt/firewall)
  `net_ipv4_kbps` FLOAT DEFAULT NULL, -- Rychlost IPv4 provozu v KB/s
  `net_ipv6_kbps` FLOAT DEFAULT NULL, -- Rychlost IPv6 provozu v KB/s
  `checked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
  INDEX (`checked_at`),
  INDEX (`monitor_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `user_id` INT NOT NULL,
  `monitor_id` INT NOT NULL,
  `email_notifications` TINYINT(1) DEFAULT 1,
  `sms_notifications` TINYINT(1) DEFAULT 0,
  `whatsapp_notifications` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`user_id`, `monitor_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(50) PRIMARY KEY,
  `key_value` TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Výchozí nastavení a uživatel - heslo změňte hned po prvním přihlášení.
-- Default uživatel: admin / heslo: BloodKingsAdmin123!
-- Hash níže je skutečný bcrypt hash tohoto hesla (password_hash('BloodKingsAdmin123!', PASSWORD_BCRYPT)),
-- ne placeholder - přihlášení proto jde přes běžný password_verify(), žádný speciální obchvat v admin.php.
INSERT INTO `users` (`username`, `password_hash`, `email`, `role`)
VALUES ('admin', '$2y$12$rRP/Lm2dxcJQmC2xwkhnE.1q.EypQOSl33iBR.t/5HPStN4MPPxme', 'admin@bloodkings.eu', 'admin')
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `settings` (`key_name`, `key_value`) VALUES
('smtp_host', 'smtp.bloodkings.eu'),
('smtp_port', '587'),
('smtp_user', 'status@bloodkings.eu'),
('smtp_pass', ''),
('smtp_secure', 'tls'),
('sms_gateway_type', 'twilio'), -- 'twilio' nebo 'smsbrana'
('twilio_sid', ''),
('twilio_token', ''),
('twilio_from', ''),
('smsbrana_user', ''),
('smsbrana_password', ''),
('site_title', 'Blood Kings | Status Monitoring'),
-- Webhooky pro notifikace (Discord / Slack / Telegram)
('discord_webhook_url', ''),
('slack_webhook_url', ''),
('telegram_bot_token', ''),
('telegram_chat_id', ''),
-- GitHub SSO (OAuth)
('oauth_github_client_id', ''),
('oauth_github_client_secret', ''),
-- Vlastní branding veřejné stránky
('custom_logo_url', ''),
('custom_color_theme', ''),
('custom_nav_links', ''),
('portal_url', ''),
-- Prometheus exporter (metrics.php) - prázdný token = endpoint vypnutý
('metrics_token', ''),
-- Cílová dostupnost pro měsíční digest (SLA vs Goal)
('sla_goal_pct', '99.95'),
-- Ručně nastavená poslední známá verze TeamSpeak serveru (pro "Update Available"); prázdné = kontrola se přeskočí
('ts3_latest_version', ''),
-- Token pro automatickou registraci nových agentů (agent_api.php?action=register)
('agent_registration_token', ''),
-- Pushover & PagerDuty notifikace
('pushover_user_key', ''),
('pushover_api_token', ''),
('pagerduty_routing_key', ''),
-- Hranice varování pro vypršení SSL certifikátu (ve dnech)
('ssl_alert_days', '14'),
-- Verze schématu - musí odpovídat BK_SCHEMA_VERSION v db.php
('schema_version', '20260730')
ON DUPLICATE KEY UPDATE `key_name`=`key_name`;

CREATE TABLE IF NOT EXISTS `metric_annotations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monitor_id` INT NOT NULL,
  `metric_key` VARCHAR(30) NOT NULL,
  `timestamp` DATETIME NOT NULL,
  `note` TEXT NOT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
  INDEX (`monitor_id`, `metric_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `impact` VARCHAR(20) DEFAULT 'minor', -- 'minor', 'major', 'critical'
  `status` VARCHAR(20) DEFAULT 'investigating', -- 'investigating', 'identified', 'monitoring', 'resolved'
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `incident_updates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `incident_id` INT NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `message` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `agent_actions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monitor_id` INT NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending', 'sent', 'executed', 'failed'
  `result_message` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `executed_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

