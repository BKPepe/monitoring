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
    add_check($checks, $all_ok, "Table: $t", $exists, $exists ? '' : 'MISSING - import schema.sql');
}

// 3. Critical columns in vps_metrics (ty co agent_api.php INSERTuje)
$required_metrics_cols = [
    'monitor_id', 'cpu_usage', 'ram_usage', 'hdd_usage', 'net_usage',
    'load_avg_1', 'load_avg_5', 'load_avg_15', 'cpu_steal', 'swap_usage',
    'disk_io_read_kbps', 'disk_io_write_kbps', 'net_errors',
    'ts_clients_online', 'ts_clients_max', 'ts_process_cpu', 'ts_process_ram',
    'iowait_pct', 'inode_usage_pct', 'zombie_count', 'fork_rate', 'temperature_c',
    'wifi_clients_total', 'conntrack_pct', 'net_ipv4_kbps', 'net_ipv6_kbps',
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
    add_check($checks, $all_ok, 'schema_version', !empty($ver), $ver ?: 'NOT SET - import schema.sql');
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
    'checks_total' => count($checks),
    'checks_passed' => count(array_filter($checks, fn($c) => $c['ok'])),
    'checks_failed' => count(array_filter($checks, fn($c) => !$c['ok'])),
];

if ($is_cli) {
    echo "=== BK Monitoring Health Check ===\n";
    foreach ($checks as $c) {
        $icon = $c['ok'] ? '✓' : '✗';
        echo "  $icon {$c['name']}" . ($c['detail'] ? " — {$c['detail']}" : '') . "\n";
    }
    echo "\n" . ($all_ok ? 'ALL OK' : 'ISSUES FOUND - import schema.sql') . "\n";
    exit;
}

// JSON API mode (?format=json)
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// HTML rendering for browser
$site_title = 'Blood Kings';
try {
    $stmt_st = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'site_title'");
    $stmt_st->execute();
    $st = $stmt_st->fetchColumn();
    if ($st) $site_title = $st;
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <title>Health Check — <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo @filemtime(__DIR__ . '/assets/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BK_CDN_FONTAWESOME; ?>" integrity="<?php echo BK_CDN_FONTAWESOME_SRI; ?>" crossorigin="anonymous">
    <script>if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-theme'); }</script>
</head>
<body>
<div class="container" style="max-width: 800px; margin: 0 auto; padding: 2rem 1rem;">

    <!-- Verdict banner -->
    <div style="background: <?php echo $all_ok ? 'rgba(30,199,115,0.08)' : 'rgba(193,18,31,0.08)'; ?>; border: 1px solid <?php echo $all_ok ? 'rgba(30,199,115,0.25)' : 'rgba(193,18,31,0.25)'; ?>; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem;">
        <i class="fas <?php echo $all_ok ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>" style="font-size: 2rem; color: <?php echo $all_ok ? 'var(--color-green)' : 'var(--color-red)'; ?>;"></i>
        <div>
            <div style="font-size: 1.2rem; font-weight: 700; color: var(--text-primary);">
                <?php echo $all_ok ? 'Vše v pořádku' : 'Nalezeny problémy'; ?>
            </div>
            <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.2rem;">
                <?php echo $result['checks_passed']; ?>/<?php echo $result['checks_total']; ?> kontrol prošlo &middot; <?php echo date('j.n.Y H:i'); ?>
                <?php if (!$all_ok): ?> &middot; <strong style="color: var(--color-red);">Zkontrolujte databázové schéma</strong><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Checks list -->
    <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.07); border-radius: 12px; overflow: hidden;">
        <?php foreach ($checks as $i => $c): ?>
        <div style="display: flex; align-items: center; gap: 0.75rem; padding: 0.7rem 1.25rem; <?php echo $i > 0 ? 'border-top: 1px solid rgba(255,255,255,0.04);' : ''; ?>">
            <i class="fas <?php echo $c['ok'] ? 'fa-check' : 'fa-xmark'; ?>" style="width: 18px; text-align: center; color: <?php echo $c['ok'] ? 'var(--color-green)' : 'var(--color-red)'; ?>;"></i>
            <span style="flex: 1; font-size: 0.85rem; color: var(--text-primary); font-weight: 500;"><?php echo htmlspecialchars($c['name']); ?></span>
            <?php if ($c['detail']): ?>
                <span style="font-size: 0.75rem; color: <?php echo $c['ok'] ? 'var(--text-muted)' : 'var(--color-red)'; ?>; font-family: monospace;"><?php echo htmlspecialchars($c['detail']); ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Actions -->
    <div style="margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
        <a href="health.php" class="btn btn-secondary btn-sm"><i class="fas fa-rotate"></i> Zkontrolovat znovu</a>
        <a href="health.php?format=json" class="btn btn-secondary btn-sm"><i class="fas fa-code"></i> JSON výstup</a>
        <a href="admin.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Zpět do adminu</a>
    </div>

</div>
</body>
</html>
