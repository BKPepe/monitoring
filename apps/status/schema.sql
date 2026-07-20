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
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (`agent_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitor_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `monitor_id` INT NOT NULL,
  `status` VARCHAR(20) NOT NULL, -- 'up', 'down', 'maintenance'
  `response_time` INT DEFAULT NULL, -- v milisekundách
  `checked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `error_message` TEXT DEFAULT NULL,
  `checked_from` VARCHAR(50) DEFAULT 'Main Server',
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

-- Výchozí nastavení a uživatel (heslo bude po instalaci / prvním spuštění hashováno nebo nastaveno)
-- Default uživatel: admin / heslo: BloodKingsAdmin123!
INSERT INTO `users` (`username`, `password_hash`, `email`, `role`)
VALUES ('admin', '$2y$10$wK10b5JgOq3qg7g3qg7qg.2V2WpXy9B1e5D6F7G8H9I0J1K2L3M4N', 'admin@bloodkings.eu', 'admin')
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
-- Verze schématu - musí odpovídat BK_SCHEMA_VERSION v db.php
('schema_version', '20260719')
ON DUPLICATE KEY UPDATE `key_name`=`key_name`;
