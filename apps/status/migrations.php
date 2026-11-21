<?php
/**
 * The schema migrations: one ordered list and the one loop that runs it.
 *
 * db.php requires this file only when the stored schema_version differs from
 * BK_SCHEMA_VERSION, so a request on an up-to-date database never reads it.
 *
 * The migrations used to be 51 try/catch blocks and seven separate lists in
 * db.php, and every catch swallowed any error. The version was stored either
 * way, so a statement that failed for real was not tried again until the
 * next bump. Now an error that only says "already there" counts as done,
 * by the same rule as the installer's schema.sql import
 * (bk_db_already_applied in db.php). Any other error is logged and keeps the
 * version where it was: the list runs again 10 minutes later, until the cause
 * is fixed, and health.php fails its schema_version check meanwhile.
 */

// Included only (db.php); requested directly it has nothing to show.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

/**
 * The schema_version this list brings a database to; BK_SCHEMA_VERSION in
 * db.php must say the same (tests/run_schema_lint.php).
 *
 * A deploy uploads db.php and this file one after the other. A new db.php
 * next to the old list would otherwise run the old steps, find them all
 * applied and store the new version, and the new steps would never run.
 * db.php runs the list only when the two versions agree. It requires this
 * file before it compares them, though: a function declared here needs a
 * name no db.php ever declared, or an older db.php that still declares it
 * fails every request that is due to migrate on the redeclaration.
 */
function bk_schema_migrations_version(): string {
    return '20261111r215';
}

/**
 * Every migration in the order it runs: an SQL statement, or a function of
 * the connection (keyed by what it does) for a change SQL alone cannot make.
 *
 * Every step is safe to repeat: the whole list runs on every new
 * BK_SCHEMA_VERSION and again after a run with a real error. An ALTER of an
 * existing column or index fails as already applied, CREATE is IF NOT
 * EXISTS, an UPDATE matches nothing the second time. A rewrite of stored
 * data that is needed once is a data fix (bk_data_fixes()), not a step: the
 * last step runs those the database has not had yet.
 *
 * A new step goes before that last one, 'one-time data fixes', which runs
 * on the finished schema, and after the table it alters. It needs a new
 * version, the same in bk_schema_migrations_version() below and in
 * BK_SCHEMA_VERSION in db.php: a database that already stores the current
 * version never reads this list again. Every column and table added here
 * belongs in schema.sql too (tests/run_schema_lint.php).
 *
 * @return array<int|string, string|Closure(PDO): void>
 */
function bk_schema_migrations(): array {
    return [
        "ALTER TABLE monitor_logs ADD COLUMN checked_from VARCHAR(50) DEFAULT 'Main Server'",
        "ALTER TABLE users ADD COLUMN role VARCHAR(20) DEFAULT 'user'",
        // Account 1 becomes admin only when the install has no admin at all - a
        // recovery for an install that lost its admin role. It used to run on
        // every schema bump and silently gave full rights back to account 1 after
        // an admin had deliberately demoted it.
        "UPDATE users SET role = 'admin' WHERE id = 1 AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM users WHERE role = 'admin') AS existing_admins)",
        // The join table for user notification subscriptions.
        "CREATE TABLE IF NOT EXISTS `user_subscriptions` (
            `user_id` INT NOT NULL,
            `monitor_id` INT NOT NULL,
            `email_notifications` TINYINT(1) DEFAULT 1,
            `sms_notifications` TINYINT(1) DEFAULT 0,
            PRIMARY KEY (`user_id`, `monitor_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE monitors ADD COLUMN notes TEXT DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN maintenance TINYINT(1) DEFAULT 0",
        "ALTER TABLE monitors ADD COLUMN monitored_processes TEXT DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN whatsapp_apikey VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(50) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN oauth_id VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN sms_notifications TINYINT(1) DEFAULT 0",
        // Planned maintenance.
        "ALTER TABLE monitors ADD COLUMN maintenance_description TEXT DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN maintenance_start DATETIME DEFAULT NULL",
        "ALTER TABLE monitors ADD COLUMN maintenance_end DATETIME DEFAULT NULL",
        // The status column is VARCHAR(20), wide enough for 'maintenance' (11
        // chars). This pair used to run twice in every migration run.
        "ALTER TABLE monitors MODIFY COLUMN status VARCHAR(20) DEFAULT 'unknown'",
        "ALTER TABLE monitor_logs MODIFY COLUMN status VARCHAR(20) NOT NULL",
        "ALTER TABLE monitors ADD COLUMN cpanel_stats_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE users ADD COLUMN whatsapp_notifications TINYINT(1) DEFAULT 0",
        "ALTER TABLE user_subscriptions ADD COLUMN whatsapp_notifications TINYINT(1) DEFAULT 0",
        // Threshold values for the VPS agent.
        "ALTER TABLE monitors ADD COLUMN cpu_threshold INT DEFAULT 90",
        "ALTER TABLE monitors ADD COLUMN ram_threshold INT DEFAULT 95",
        "ALTER TABLE monitors ADD COLUMN hdd_threshold INT DEFAULT 90",
        // Network throughput (KB/s) reported by agents; NULL on older rows and
        // for agents that do not report network yet (no previous sample to diff against).
        "ALTER TABLE vps_metrics ADD COLUMN net_usage FLOAT DEFAULT NULL",
        // Check pipeline (DNS/TCP/TLS/HTTP/body stages for 'web' monitors).
        "ALTER TABLE monitors ADD COLUMN body_keyword VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE monitor_logs ADD COLUMN check_stages TEXT DEFAULT NULL",
        // Infrastructure report digest (config change tracking + event log).
        "ALTER TABLE monitors ADD COLUMN config_snapshot TEXT DEFAULT NULL",
        "CREATE TABLE IF NOT EXISTS monitor_events (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Deep TeamSpeak monitoring + Host/VPS layer (load average, CPU steal,
        // swap, disk I/O, network errors) and the TeamSpeak process/clients for history charts.
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

        // Finishing the Level 2 Host layer (IO wait, inode usage, zombie
        // processes, fork rate, temperature). All optional/NULL, older agents
        // do not send these fields at all.
        "ALTER TABLE vps_metrics ADD COLUMN iowait_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN inode_usage_pct FLOAT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN zombie_count INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN fork_rate INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN temperature_c FLOAT DEFAULT NULL",
        // LTE signal strength over time: it shows whether the link is getting
        // worse (a moved antenna, a busy cell, the weather). It used to be a
        // snapshot only.
        "ALTER TABLE vps_metrics ADD COLUMN lte_rsrp FLOAT DEFAULT NULL",

        // Daily availability summary: one row per monitor and day.
        //
        // monitor_logs is pruned after 30 days (~3 million rows a year would
        // be too much for shared hosting), so an SLA over a longer period had
        // nothing to be computed from - the "year" column showed the same as
        // "30 days". The aggregate survives the pruning and carries exactly
        // what the SLA needs: the share of successful checks. The details of
        // outages stay in monitor_events, which is never pruned.
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
        // Traffic over the LTE backup over time: with the wan_lost/wan_restored
        // events it tells which bytes went over the primary line and which
        // over the backup.
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

        "CREATE TABLE IF NOT EXISTS `monitor_interface_traffic` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Service Profiles: the user toggles which dashboard sections show for
        // a given monitor (see get_service_profiles()). NULL = no explicit
        // selection, the dashboard uses the profile's "recommended" defaults,
        // which match exactly what used to be displayed before.
        "ALTER TABLE monitors ADD COLUMN enabled_metrics TEXT DEFAULT NULL",

        // RCON login for Minecraft (TPS via the Paper/Spigot "tps" command).
        // Optional - left empty, plain SLP is used as before.
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

        // Remote Actions: the missing per-monitor consent. The previous
        // implementation (ed31853) had the HMAC signature and time window
        // right but no server-side check that the router had actually allowed
        // remote actions - any admin could queue a reboot for any monitor.
        // Default 0/NULL = no monitor has anything allowed until the admin
        // explicitly turns it on in its settings.
        "ALTER TABLE monitors ADD COLUMN remote_actions_enabled TINYINT(1) DEFAULT 0",
        "ALTER TABLE monitors ADD COLUMN allowed_actions VARCHAR(255) DEFAULT NULL",

        // Assets: a physical/logical device that can group several monitors
        // (this relationship could not be expressed at all before - every
        // monitor was independent). Every existing monitor without an asset_id
        // gets its own new 1:1 asset - no guessing which monitors "really"
        // belong together (there is no reliable signal for that - agent_key is
        // always unique, category is just a label). Merging monitors into one
        // asset is strictly a manual admin action (see admin.php). The
        // monitors that predate the table get theirs from a data fix.
        "CREATE TABLE IF NOT EXISTS `assets` (`id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(150) NOT NULL, `icon` VARCHAR(30) DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "ALTER TABLE monitors ADD COLUMN asset_id INT DEFAULT NULL",
        // A step with its own rule, as is the Cloudflare data fix. MySQL
        // answers a second run with 1826 (already applied); other servers
        // name the same duplicate with codes of their own, and some hosts
        // refuse a named key added by ALTER at all. Whatever it answers is
        // logged and never holds the version back: the key only guards the
        // link, and the steps below do not need it.
        'asset_id foreign key' => static function (PDO $pdo): void {
            try {
                $pdo->exec("ALTER TABLE monitors ADD CONSTRAINT fk_monitors_asset_id FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE SET NULL");
            } catch (PDOException $e) {
                if (!bk_db_already_applied($e)) {
                    error_log('[db] migration: the named foreign key on monitors.asset_id was not added: ' . $e->getMessage());
                }
            }
        },

        // Per-user e-mail language: NULL = follow the global email_lang setting.
        "ALTER TABLE users ADD COLUMN email_lang VARCHAR(5) DEFAULT NULL",
        // Metrics in vps_metrics may be NULL - when the source (StatsBar without
        // CloudLinux, an agent without the sensor) returns nothing, NULL is
        // stored instead of an invented zero.
        "ALTER TABLE vps_metrics MODIFY COLUMN cpu_usage FLOAT NULL, MODIFY COLUMN ram_usage FLOAT NULL, MODIFY COLUMN hdd_usage FLOAT NULL",
        // restart_service needs to know WHICH service to restart - without the
        // column the name never travelled and the action ended "failed" on every agent.
        "ALTER TABLE agent_actions ADD COLUMN service_name VARCHAR(64) DEFAULT NULL",
        // Read-alert state lives on the user, not in the browser's localStorage -
        // otherwise "mark all as read" only holds on a single computer.
        "ALTER TABLE users ADD COLUMN alerts_read_log_id INT DEFAULT 0",

        // Editable presets: a named set of shown metrics and thresholds that
        // can be assigned to several monitors at once. Before, only each
        // monitor's own thresholds could be changed and the set of metrics was
        // hard-wired in get_service_profiles().
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
        // Public status pages: their own choice of monitors, a slug and
        // visibility. Before there was one hard-wired page with nothing to
        // choose - a link to /status/ and nothing more.
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

        // Alerts on degraded latency: until then only an outage could be
        // told, not that a service crawls. NULL = off (the default), so
        // existing monitors do not start alerting on their own.
        "ALTER TABLE monitors ADD COLUMN latency_threshold_ms INT NULL",
        // How many minutes the degradation must last before an alert goes
        // out - one slow check is noise, not a problem.
        "ALTER TABLE monitors ADD COLUMN latency_threshold_mins INT NOT NULL DEFAULT 5",
        // Heartbeat monitors: the service reports by itself, nobody asks it.
        // That covers what an active check cannot reach - backups, cron jobs,
        // batches. Every column is NULL until a monitor of type 'heartbeat' exists.
        "ALTER TABLE monitors ADD COLUMN heartbeat_token VARCHAR(64) NULL",
        // How often the job has to report (in seconds).
        "ALTER TABLE monitors ADD COLUMN heartbeat_interval INT NULL",
        // Extra tolerance before silence is declared an outage. A backup that
        // cron starts at 03:00 does not finish at the same second every time.
        "ALTER TABLE monitors ADD COLUMN heartbeat_grace INT NULL",
        "ALTER TABLE monitors ADD COLUMN last_heartbeat DATETIME NULL",
        // A job can report its own failure too (?status=fail), not only that
        // it finished. Without it a backup failing quietly would look healthy.
        "ALTER TABLE monitors ADD COLUMN heartbeat_last_result VARCHAR(10) NULL",
        "ALTER TABLE monitors ADD COLUMN heartbeat_last_message VARCHAR(255) NULL",
        "CREATE UNIQUE INDEX idx_monitors_heartbeat_token ON monitors (heartbeat_token)",
        // Incidents as first-class objects: link to a monitor, acknowledge
        // and postmortem. NULLable - manually created incidents have
        // no monitor link.
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
        // Performance: the monitor list asks for the LATEST row by id for every
        // monitor (response time from logs, metrics from the agent). Without the
        // (monitor_id, id) index that was a scan - the monitors endpoint took
        // ~0.7 s and held up the whole app.
        "CREATE INDEX idx_logs_monitor_id_desc ON monitor_logs (monitor_id, id)",
        // The windowed SLA aggregation (websites_overview) filters a year of logs by time.
        "CREATE INDEX idx_logs_checked_at ON monitor_logs (checked_at)",
        "CREATE INDEX idx_vpsm_monitor_id_desc ON vps_metrics (monitor_id, id)",

        // CPU/RAM anomalies compute AVG and STDDEV over 30 days. Without a
        // covering index that was a full scan of the whole table - and with
        // the added metrics it has over 60 columns, so many times more data
        // was read from disk than the query needs.
        "CREATE INDEX idx_vpsm_cover_cpuram ON vps_metrics (monitor_id, checked_at, cpu_usage, ram_usage)",
        // The same for the latency baseline: the query filters by status and
        // computes its statistics from response_time, so the index alone
        // answers it and no row is read.
        "CREATE INDEX idx_logs_cover_latency ON monitor_logs (monitor_id, status, checked_at, response_time)",

        // --- Router release 20260920: Wi-Fi profile, disk health, WAN path -------
        //
        // `speedtest_results` is created only further up, so its new columns
        // come after it - on an install that predates the table they would
        // fail first and the CREATE would then add the old shape.
        //
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
        // The public status page shows a curated set (W1-G3, owner decision
        // 5.3). NULL = never chosen: the type decides, so no row needs a
        // backfill and every way of creating a monitor gets the same default.
        "ALTER TABLE monitors ADD COLUMN is_public TINYINT(1) DEFAULT NULL",
        // The router's masked error lines (W1-C3, owner decision 5.7): on by
        // default, switchable per monitor; the agent hears it in the answer.
        "ALTER TABLE monitors ADD COLUMN log_lines_enabled TINYINT(1) NOT NULL DEFAULT 1",
        // Agent 0.1.9 reports what its previous run cost in CPU. Stored, not
        // only in last_details: per router and release it is the measured cost
        // of the agent. NULL = not measured, which is every row of an older agent.
        "ALTER TABLE vps_metrics ADD COLUMN agent_prev_cpu_ms INT DEFAULT NULL",
        // Which line carried a speed test (agent 0.1.11). The Turris runs its
        // nightly test unbound, so during a WAN outage it measures the LTE
        // backup; without this the LTE figure was shown as the line speed.
        // NULL = not measured: every older row stays NULL and is NOT
        // backfilled from outages - that guess is made at read time
        // (bk_speedtest_attribute) so it can never pass for a measurement.
        "ALTER TABLE speedtest_results ADD COLUMN uplink VARCHAR(8) DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN uplink_source VARCHAR(12) DEFAULT NULL",
        "ALTER TABLE speedtest_results ADD COLUMN proto VARCHAR(5) DEFAULT NULL",
        // What the provider said, not only whether the sender returned true
        // (20260927r021). ok = 1 used to cover a CallMeBot refusal answered
        // with a 2xx and a mail() hand-off nobody confirmed. delivery: sent =
        // the provider confirmed it took the message, failed, unknown = nobody
        // confirmed, skipped. NULL = a row from before this: never backfilled,
        // because nothing confirmed those rows then either.
        "ALTER TABLE notification_log ADD COLUMN delivery VARCHAR(8) DEFAULT NULL",
        "ALTER TABLE notification_log ADD COLUMN provider_reply VARCHAR(190) DEFAULT NULL",
        // Chart notes (20260927r021). schema.sql has had this table since its
        // first version, but no migration created it, so a database set up
        // before that never got it: production answered every annotations
        // request with error 1146 until the table was made by hand on 27 Sep
        // 2026. The same definition as schema.sql.
        "CREATE TABLE IF NOT EXISTS `metric_annotations` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `monitor_id` INT NOT NULL,
          `metric_key` VARCHAR(30) NOT NULL,
          `timestamp` DATETIME NOT NULL,
          `note` TEXT NOT NULL,
          `created_by` INT DEFAULT NULL,
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
          INDEX (`monitor_id`, `metric_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // The WAN port's own counters (agent 0.1.13), hourly STEPS from the
        // same `ethtool -S` read as wan_ring_drops. They tell a damaged frame
        // from the line from a frame the router could not take in. NULL = not
        // measured: every older row stays NULL and is never backfilled.
        "ALTER TABLE vps_metrics ADD COLUMN wan_rx_overrun INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_rx_discard INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_crc_errors INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_mac_rx_errors INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_refill_errors INT DEFAULT NULL",
        "ALTER TABLE vps_metrics ADD COLUMN wan_oversize INT DEFAULT NULL",
        // Was the router's own speed test running (or its result waiting) in
        // that minute: 1 / 0, NULL = the agent did not say. The agent has
        // sent the flag since 0.1.7; only last_details kept it until now.
        "ALTER TABLE vps_metrics ADD COLUMN speedtest_active TINYINT DEFAULT NULL",

        // Who wrote a check row (20261101r024): 'cron', 'heartbeat', 'agent'
        // or 'probe'. Older rows stay NULL and count everywhere as before; a
        // probe's row (node_api.php) counts in availability, the history and
        // the regions, but never confirms an outage, splits one or gives it
        // its reason.
        "ALTER TABLE monitor_logs ADD COLUMN source VARCHAR(16) DEFAULT NULL",

        // A fresh install got monitor_logs.checked_at indexed twice and the
        // foreign key on monitors.asset_id twice (20261111r215): schema.sql
        // named them otherwise than the steps above, so to MySQL they were
        // not already there. Only a database holding the named copy loses the
        // other one; whatever the server answers is logged, as for the key.
        'duplicate checked_at index and asset_id key' => static function (PDO $pdo): void {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return;
            }
            try {
                $single = $pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monitor_logs'
                    GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = 'checked_at'")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('idx_logs_checked_at', $single, true) && in_array('checked_at', $single, true)) {
                    $pdo->exec("ALTER TABLE monitor_logs DROP INDEX `checked_at`");
                }
                $keys = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monitors' AND COLUMN_NAME = 'asset_id'
                    AND REFERENCED_TABLE_NAME = 'assets'")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('fk_monitors_asset_id', $keys, true)) {
                    foreach (array_diff($keys, ['fk_monitors_asset_id']) as $key) {
                        $pdo->exec('ALTER TABLE monitors DROP FOREIGN KEY `' . str_replace('`', '', (string)$key) . '`');
                    }
                }
            } catch (PDOException $e) {
                error_log('[db] migration: a duplicate checked_at index or asset_id key was not dropped: ' . $e->getMessage());
            }
        },

        // Last, on the finished schema: the data fixes this database has not
        // had yet (bk_data_fixes_run).
        'one-time data fixes' => static function (PDO $pdo): void {
            bk_data_fixes_run($pdo);
        },
    ];
}

/**
 * The one-time data fixes in the order they run, each under an id that
 * never changes.
 *
 * A data fix rewrites stored rows, not the schema: a value an older version
 * stored wrongly, a monitor type that is gone. These were steps of the list
 * above and ran again on every schema bump; the cpanel conversion then
 * rewrote any cpanel monitor to web on whichever deploy bumped the version
 * next. Now a later run skips a fix that ran: bk_data_fixes_run() keeps the
 * ids that ran in the settings row data_fixes_applied. A fix can still run
 * twice: a run cut short records nothing (bk_data_fixes_run() writes the
 * record once, after the loop), and nothing serialises the list, so two
 * requests that start it at the same moment both run what neither has
 * recorded yet, as they always ran every step. A fix has to be safe to
 * repeat, also beside a request that runs it at the same time.
 *
 * A new fix goes at the end under a new id and needs a version bump, as a
 * new step does: the list runs only then. An id is never reused or renamed,
 * or a database that ran the old fix would skip the new one. A fix can go
 * once every database has run it; its id may stay in the record.
 *
 * @return array<string, Closure(PDO): void>
 */
function bk_data_fixes(): array {
    return [
        // The monitor type cpanel is retired (owner decision D7): a cpanel
        // monitor becomes a web check of its host, and the address it had,
        // the exporter's cpanel_stats.php, moves to cpanel_stats_url, where
        // cron reads the hosting's figures for a web monitor.
        'cpanel-monitors-to-web' => static function (PDO $pdo): void {
            $stmt_update = $pdo->prepare("UPDATE monitors SET type = 'web', target = ?, cpanel_stats_url = ? WHERE id = ?");
            foreach ($pdo->query("SELECT id, target FROM monitors WHERE type = 'cpanel'")->fetchAll() as $m) {
                $parsed = parse_url((string)$m['target']);
                $base_target = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? 'localhost');
                $stmt_update->execute([$base_target, $m['target'], $m['id']]);
            }
        },
        // Every monitor created before agent keys gets one. Every path that
        // creates a monitor sets it since.
        'agent-keys' => static function (PDO $pdo): void {
            $stmt_set_key = $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = ?");
            foreach ($pdo->query("SELECT id FROM monitors WHERE agent_key IS NULL OR agent_key = ''")->fetchAll() as $m) {
                $stmt_set_key->execute([bin2hex(random_bytes(16)), $m['id']]);
            }
        },
        // Every monitor created before assets gets its own 1:1 asset. Every
        // path that creates a monitor gives it one since; a monitor whose
        // asset is deleted later stays without one (assetId null). A monitor
        // that got its asset from a request running the list at the same
        // time keeps that one, and the asset made here is deleted again.
        'one-asset-per-monitor' => static function (PDO $pdo): void {
            $stmt_new_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
            $stmt_assign = $pdo->prepare("UPDATE monitors SET asset_id = ? WHERE id = ? AND asset_id IS NULL");
            $stmt_drop_asset = $pdo->prepare("DELETE FROM assets WHERE id = ?");
            foreach ($pdo->query("SELECT id, name FROM monitors WHERE asset_id IS NULL")->fetchAll() as $um) {
                $stmt_new_asset->execute([$um['name']]);
                $asset_id = (int)$pdo->lastInsertId();
                $stmt_assign->execute([$asset_id, $um['id']]);
                if ($stmt_assign->rowCount() === 0) {
                    $stmt_drop_asset->execute([$asset_id]);
                }
            }
        },
        // 'librespeed' was a constant no query read; every such row was started
        // by the Turris scheduler.
        'speedtest-source-turris' => static function (PDO $pdo): void {
            $pdo->exec("UPDATE speedtest_results SET source = 'turris' WHERE source = 'librespeed'");
        },
        // Agents before 0.1.7 divided results of 1000 Mbit/s and more once too
        // often and stored 0.01-0.08. No real line measures that, so the value
        // is unmeasured, not slow. Per column; a file still on the router
        // repairs the row when 0.1.7 sends it again. Only rows without
        // bytes_received (= written by an older agent) are touched, so the fix
        // never erases a value a 0.1.7 agent really measured.
        'speedtest-below-0.1-mbps' => static function (PDO $pdo): void {
            $pdo->exec("UPDATE speedtest_results SET download_mbps = NULL WHERE download_mbps < 0.1 AND bytes_received IS NULL");
            $pdo->exec("UPDATE speedtest_results SET upload_mbps = NULL WHERE upload_mbps < 0.1 AND bytes_received IS NULL");
        },
        // Cloudflare Worker checks posted before agents e13a2d4 carry a US flag
        // on every colo (20260924cf). Like the asset_id foreign key step, an
        // error is logged and fails nothing: no schema step needs these
        // labels, and a column that is not utf8mb4 (an older self-hosted
        // database) refuses the COLLATE every time.
        'cloudflare-location-history' => static function (PDO $pdo): void {
            try {
                bk_cf_relabel_location_history($pdo);
            } catch (PDOException $e) {
                error_log('[db] data fix: the Cloudflare location history was not rewritten: ' . $e->getMessage());
            }
        },
    ];
}

/**
 * Gives the Cloudflare Worker's past checks the country of the colo they ran in.
 *
 * Until agents e13a2d4 the Worker put its egress IP's country (US) next to the
 * right city, and every page shows the stored label: "🇺🇸 Mumbai, US". Each
 * wrong label is rebuilt from the Worker's map (cloudflare_colos.php); other
 * providers, unknown cities and correct labels are left alone.
 *
 * Cost on a quarter million rows: one pass over idx_logs_regions (it holds
 * checked_from and the id, so no row is read) finds the distinct labels, then
 * one UPDATE per wrong label, walking only the id range that label was posted
 * in. The labels are compared in utf8mb4_bin: the table's unicode_ci weighs
 * every emoji the same, so "🇺🇸 X" and "🇩🇪 X" would be one value there.
 * A second run finds nothing to change. Returns the number of rows rewritten.
 *
 * Until 20261101r023 db.php declared it as bk_cf_fix_location_history(), a
 * name this file must not take (bk_schema_migrations_version()).
 */
function bk_cf_relabel_location_history(PDO $pdo): int {
    require_once __DIR__ . '/cloudflare_colos.php';
    $labels = $pdo->query("
        SELECT checked_from COLLATE utf8mb4_bin AS label, MIN(id) AS first_id, MAX(id) AS last_id
        FROM monitor_logs
        WHERE checked_from LIKE '%(AS13335 Cloudflare)'
        GROUP BY checked_from COLLATE utf8mb4_bin
    ")->fetchAll();
    $update = $pdo->prepare("
        UPDATE monitor_logs SET checked_from = ?
        WHERE id BETWEEN ? AND ? AND checked_from COLLATE utf8mb4_bin = ?
    ");
    $rows = 0;
    foreach ($labels as $l) {
        $fixed = bk_cf_corrected_label((string)$l['label']);
        if ($fixed !== null) {
            $update->execute([$fixed, (int)$l['first_id'], (int)$l['last_id'], $l['label']]);
            $rows += $update->rowCount();
        }
    }
    if ($rows > 0) {
        // The regions answer is cached for ten minutes with the old labels in it.
        bk_kv_cache_clear($pdo, ['regions_cache_']);
        error_log("[db] Cloudflare Worker locations: {$rows} checks relabelled with their colo's country");
    }
    // The digest compares each region's latency with the last digest's by
    // label; renamed there as well, the trend of a relabelled colo carries on.
    // Where the right label is already in the snapshot, its value stays.
    // Checked on every run, not only when rows changed: a run cut short after
    // the UPDATEs would otherwise never get here again.
    $read = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = ?");
    $write = $pdo->prepare("UPDATE settings SET key_value = ? WHERE key_name = ?");
    foreach (['digest_snapshot_weekly', 'digest_snapshot_monthly'] as $key) {
        $read->execute([$key]);
        $snapshot = json_decode((string)$read->fetchColumn(), true);
        if (!is_array($snapshot) || !is_array($snapshot['regions'] ?? null)) {
            continue;
        }
        $regions = [];
        foreach ($snapshot['regions'] as $label => $latency) {
            $fixed = bk_cf_corrected_label((string)$label);
            if ($fixed === null) {
                $regions[$label] = $latency;
            } elseif (!array_key_exists($fixed, $snapshot['regions']) && !array_key_exists($fixed, $regions)) {
                $regions[$fixed] = $latency;
            }
        }
        if ($regions !== $snapshot['regions']) {
            $snapshot['regions'] = $regions;
            $write->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE), $key]);
        }
    }
    return $rows;
}

/**
 * The last migration step: runs the data fixes this database has not had,
 * then stores the record of those that ran.
 *
 * A fix that failed fails the step, after the record is stored. The version
 * then stays and the list, this step with it, runs again 10 minutes later;
 * the fixes that did run are not repeated.
 */
function bk_data_fixes_run(PDO $pdo): void {
    $record = json_decode((string)$pdo->query("SELECT key_value FROM settings WHERE key_name = 'data_fixes_applied'")->fetchColumn(), true);
    $applied = is_array($record) ? array_values(array_filter($record, 'is_string')) : [];
    $result = bk_data_fixes_apply($pdo, bk_data_fixes(), $applied);
    if ($result['applied'] !== $applied) {
        bk_setting_set($pdo, 'data_fixes_applied', (string)json_encode($result['applied']));
    }
    if ($result['error'] !== null) {
        throw $result['error'];
    }
}

/**
 * Runs the fixes whose id $applied does not hold, in order, and returns
 * $applied with the ids of those that ran, and the first error.
 *
 * A fix that fails is logged with its id and left out of the record, so the
 * next run tries it again; the fixes after it still run. Only a PDOException
 * is caught, as in bk_schema_migrate().
 *
 * @param array<string, Closure(PDO): void> $fixes
 * @param list<string> $applied
 * @return array{applied: list<string>, error: ?PDOException}
 */
function bk_data_fixes_apply(PDO $pdo, array $fixes, array $applied): array {
    $error = null;
    foreach ($fixes as $id => $fix) {
        if (in_array($id, $applied, true)) {
            continue;
        }
        try {
            $fix($pdo);
            $applied[] = $id;
        } catch (PDOException $e) {
            error_log("[db] data fix {$id} failed: " . $e->getMessage());
            $error ??= $e;
        }
    }
    return ['applied' => $applied, 'error' => $error];
}

/**
 * Runs the steps in order and returns the numbers (1-based) of those that
 * failed for real; an empty list means the schema is what the steps describe.
 *
 * An error that only says the change is already there counts as done
 * (bk_db_already_applied). A duplicate row (1062) does not: no step here
 * adds a row that may already be there, so only CREATE UNIQUE INDEX over
 * rows that break it can raise one, and that index is then missing for real.
 * Any other error is logged with the step and the run goes on with the next
 * one, as it always did: one broken statement does not keep the rest from
 * the database. Only a PDOException is caught; any other exception is a bug
 * and ends the request, as before.
 *
 * @param array<int|string, string|Closure(PDO): void> $steps
 * @return list<int>
 */
function bk_schema_migrate(PDO $pdo, array $steps): array {
    $failed = [];
    $number = 0;
    foreach ($steps as $name => $step) {
        $number++;
        try {
            if (is_string($step)) {
                $pdo->exec($step);
            } else {
                $step($pdo);
            }
        } catch (PDOException $e) {
            if (bk_db_already_applied($e)) {
                continue;
            }
            $failed[] = $number;
            // The step's name, or the start of its statement on one line.
            $label = is_string($name) ? $name
                : (is_string($step) ? substr((string)preg_replace('/\s+/', ' ', trim($step)), 0, 80) : 'a PHP step');
            error_log("[db] migration {$number} of " . count($steps) . " failed ({$label}): " . $e->getMessage());
        }
    }
    return $failed;
}
