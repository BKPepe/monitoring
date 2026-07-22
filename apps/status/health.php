<?php
/**
 * Blood Kings Monitoring - Health Check / Schema Validator
 * Ověří, že DB schéma je kompletní a agent API bude fungovat.
 * Přístup: admin-only (web) nebo CLI.
 */

require_once __DIR__ . '/db.php';

$is_cli = php_sapi_name() === 'cli';
if (!$is_cli) {
    session_start();
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$checks = [];
$all_ok = true;

function add_check(&$checks, &$all_ok, $name, $ok, $detail = '') {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $all_ok = false;
}

// 1. DB připojení
add_check($checks, $all_ok, 'DB connection', true, 'PDO connected');

// 2. Required tables
$required_tables = ['monitors', 'monitor_logs', 'vps_metrics', 'settings', 'users', 'monitor_events', 'agent_actions'];
$stmt = $pdo->query("SHOW TABLES");
$existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($required_tables as $t) {
    $exists = in_array($t, $existing_tables, true);
    add_check($checks, $all_ok, "Table: $t", $exists, $exists ? '' : 'MISSING - run migrate.php');
}

// 3. Critical columns in vps_metrics (ty co agent_api.php INSERTuje)
$required_metrics_cols = [
    'monitor_id', 'cpu_usage', 'ram_usage', 'hdd_usage', 'net_usage',
    'load_avg_1', 'load_avg_5', 'load_avg_15', 'cpu_steal', 'swap_usage',
    'disk_io_read_kbps', 'disk_io_write_kbps', 'net_errors',
    'ts_clients_online', 'ts_clients_max', 'ts_process_cpu', 'ts_process_ram',
    'iowait_pct', 'inode_usage_pct', 'zombie_count', 'fork_rate', 'temperature_c',
    'wifi_clients_total', 'conntrack_pct',
];

try {
    $stmt = $pdo->query("DESCRIBE vps_metrics");
    $existing_cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $missing_cols = array_diff($required_metrics_cols, $existing_cols);
    add_check($checks, $all_ok, 'vps_metrics columns', empty($missing_cols),
        empty($missing_cols) ? count($existing_cols) . ' columns OK' : 'Missing: ' . implode(', ', $missing_cols));
} catch (PDOException $e) {
    add_check($checks, $all_ok, 'vps_metrics columns', false, 'Cannot describe table: ' . $e->getMessage());
}

// 4. Critical columns in monitors
$required_monitor_cols = ['agent_key', 'remote_actions_enabled', 'allowed_actions', 'asset_id', 'maintenance_description'];
try {
    $stmt = $pdo->query("DESCRIBE monitors");
    $existing_cols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $missing_cols = array_diff($required_monitor_cols, $existing_cols);
    add_check($checks, $all_ok, 'monitors columns', empty($missing_cols),
        empty($missing_cols) ? 'OK' : 'Missing: ' . implode(', ', $missing_cols));
} catch (PDOException $e) {
    add_check($checks, $all_ok, 'monitors columns', false, $e->getMessage());
}

// 5. schema_version
try {
    $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'schema_version'");
    $stmt->execute();
    $ver = $stmt->fetchColumn();
    add_check($checks, $all_ok, 'schema_version', !empty($ver), $ver ?: 'NOT SET - run migrate.php');
} catch (PDOException $e) {
    add_check($checks, $all_ok, 'schema_version', false, $e->getMessage());
}

// 6. Agent files exist
$agent_files = ['agent.sh', 'agent.py', 'agent.ps1', 'agent_openwrt.sh'];
foreach ($agent_files as $af) {
    $exists = file_exists(__DIR__ . '/' . $af);
    add_check($checks, $all_ok, "Agent file: $af", $exists, $exists ? '' : 'MISSING');
}

// 7. Writable check (error_log)
$log_ok = is_writable(ini_get('error_log') ?: '/tmp');
add_check($checks, $all_ok, 'Error log writable', $log_ok, $log_ok ? ini_get('error_log') : 'Cannot write to error_log');

// Output
$result = [
    'success' => $all_ok,
    'status' => $all_ok ? 'healthy' : 'degraded',
    'timestamp' => date('c'),
    'checks' => $checks,
];

if ($is_cli) {
    echo "=== BK Monitoring Health Check ===\n";
    foreach ($checks as $c) {
        $icon = $c['ok'] ? '✓' : '✗';
        echo "  $icon {$c['name']}" . ($c['detail'] ? " — {$c['detail']}" : '') . "\n";
    }
    echo "\n" . ($all_ok ? 'ALL OK' : 'ISSUES FOUND - run: php migrate.php') . "\n";
} else {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
