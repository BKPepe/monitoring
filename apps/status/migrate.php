<?php
/**
 * Blood Kings Monitoring - Database Migration Script
 * Spouští se přes CLI (php migrate.php) nebo přes web (admin-only).
 * Aplikuje incrementální migrace podle schema_version v settings tabulce.
 * Idempotentní - bezpečné spustit kdykoliv znovu.
 */

require_once __DIR__ . '/db.php';

// CLI nebo admin-only web přístup
$is_cli = php_sapi_name() === 'cli';
if (!$is_cli) {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

function migrate_log($msg) {
    if (php_sapi_name() === 'cli') {
        echo date('Y-m-d H:i:s') . " - $msg\n";
    }
}

/**
 * Migrace - každá má verzi (datum) a seznam SQL příkazů.
 * Přidávej nové migrace na konec pole, nikdy neměň existující.
 */
function bk_get_migrations(): array {
    return [
        '20260720' => [
            // Phase 1: Executive Summary + Timeline event types
            "CREATE TABLE IF NOT EXISTS `monitor_events` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `monitor_id` INT NOT NULL,
                `event_type` VARCHAR(64) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `occurred_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
                INDEX (`monitor_id`, `occurred_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        '20260722' => [
            // Phase 3: Metric annotations
            "CREATE TABLE IF NOT EXISTS `metric_annotations` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `monitor_id` INT NOT NULL,
                `metric_key` VARCHAR(32) NOT NULL,
                `timestamp` DATETIME NOT NULL,
                `note` TEXT NOT NULL,
                `created_by` VARCHAR(64) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
                INDEX (`monitor_id`, `metric_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],

        '20260723' => [
            // Phase 4: Assets model
            "CREATE TABLE IF NOT EXISTS `assets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(128) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            // Přidat asset_id do monitors (pokud chybí)
            "ALTER TABLE `monitors` ADD COLUMN `asset_id` INT DEFAULT NULL",
            "ALTER TABLE `monitors` ADD CONSTRAINT `fk_monitors_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets`(`id`) ON DELETE SET NULL",
        ],

        '20260725' => [
            // Remote Actions
            "CREATE TABLE IF NOT EXISTS `agent_actions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `monitor_id` INT NOT NULL,
                `action_type` VARCHAR(64) NOT NULL,
                `status` ENUM('pending','sent','completed','failed') DEFAULT 'pending',
                `result_message` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `completed_at` TIMESTAMP NULL DEFAULT NULL,
                FOREIGN KEY (`monitor_id`) REFERENCES `monitors`(`id`) ON DELETE CASCADE,
                INDEX (`monitor_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE `monitors` ADD COLUMN `remote_actions_enabled` TINYINT(1) DEFAULT 0",
            "ALTER TABLE `monitors` ADD COLUMN `allowed_actions` VARCHAR(255) DEFAULT NULL",
        ],

        '20260727' => [
            // vps_metrics - nové sloupce pro rozšířenou telemetrii
            "ALTER TABLE `vps_metrics` ADD COLUMN `iowait_pct` FLOAT DEFAULT NULL",
            "ALTER TABLE `vps_metrics` ADD COLUMN `inode_usage_pct` FLOAT DEFAULT NULL",
            "ALTER TABLE `vps_metrics` ADD COLUMN `zombie_count` INT DEFAULT NULL",
            "ALTER TABLE `vps_metrics` ADD COLUMN `fork_rate` INT DEFAULT NULL",
            "ALTER TABLE `vps_metrics` ADD COLUMN `temperature_c` FLOAT DEFAULT NULL",
            "ALTER TABLE `vps_metrics` ADD COLUMN `wifi_clients_total` INT DEFAULT NULL",
            "ALTER TABLE `vps_metrics` ADD COLUMN `conntrack_pct` FLOAT DEFAULT NULL",
        ],

        '20260730' => [
            // Service Discovery change detection - event types already covered by monitor_events
            // Maintenance description
            "ALTER TABLE `monitors` ADD COLUMN `maintenance_description` TEXT DEFAULT NULL",
            "ALTER TABLE `monitors` ADD COLUMN `maintenance_start` DATETIME DEFAULT NULL",
            "ALTER TABLE `monitors` ADD COLUMN `maintenance_end` DATETIME DEFAULT NULL",
        ],
    ];
}

// --- Spuštění migrací ---
try {
    // Zjistit aktuální verzi
    $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'schema_version'");
    $stmt->execute();
    $current_version = $stmt->fetchColumn() ?: '0';

    migrate_log("Aktuální schema_version: $current_version");

    $migrations = bk_get_migrations();
    $applied = 0;
    $errors = 0;

    foreach ($migrations as $version => $sqls) {
        if (version_compare($version, $current_version, '<=')) {
            continue; // Už aplikováno
        }

        migrate_log("Aplikuji migraci $version...");

        foreach ($sqls as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // "Duplicate column name" / "already exists" = neškodné (sloupec/tabulka už je)
                $msg = $e->getMessage();
                if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'already exists') || str_contains($msg, 'Duplicate key name')) {
                    migrate_log("  SKIP (už existuje): " . substr($sql, 0, 60) . "...");
                } else {
                    migrate_log("  CHYBA: $msg");
                    error_log("[migrate] Error in migration $version: $msg | SQL: " . substr($sql, 0, 100));
                    $errors++;
                }
            }
        }

        // Aktualizovat verzi
        $pdo->prepare("UPDATE settings SET key_value = ? WHERE key_name = 'schema_version'")->execute([$version]);
        $applied++;
        migrate_log("  Hotovo: $version");
    }

    $result_msg = $applied > 0
        ? "Aplikováno $applied migrací (schema_version: " . array_key_last($migrations) . ")."
        : "Žádné nové migrace (schema_version: $current_version).";

    if ($errors > 0) {
        $result_msg .= " Varování: $errors chyb (viz error_log).";
    }

    migrate_log($result_msg);

    if (!$is_cli) {
        echo json_encode(['success' => true, 'message' => $result_msg, 'applied' => $applied, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    $msg = 'Migration failed: ' . $e->getMessage();
    migrate_log("FATAL: $msg");
    if (!$is_cli) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    }
    exit(1);
}
