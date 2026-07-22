<?php
/**
 * Level 2 - Detailní stránka monitoru (server/služba)
 * Přístup: monitor.php?id=X
 * Zobrazuje bohaté grafy, agent info, procesy, porty, timeline, insights.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$site_title = get_setting('site_title', 'Blood Kings');
$monitor_id = (int)($_GET['id'] ?? 0);

if ($monitor_id <= 0) {
    http_response_code(404);
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT m.*, a.name AS asset_name FROM monitors m LEFT JOIN assets a ON a.id = m.asset_id WHERE m.id = ?");
$stmt->execute([$monitor_id]);
$monitor = $stmt->fetch();

if (!$monitor) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($site_title) . '</title><link rel="stylesheet" href="assets/style.css"></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;"><p style="color:var(--text-secondary,#94a3b8);">' . htmlspecialchars(t('metric_not_found')) . ' <a href="index.php">' . htmlspecialchars(t('breadcrumb_dashboard')) . '</a></p></body></html>';
    exit;
}

$details = $monitor['last_details'] ? json_decode($monitor['last_details'], true) : null;
if (!is_array($details)) $details = [];

// Available metrics for this monitor (check latest vps_metrics row)
$registry = bk_get_metric_registry();
$stmt_latest = $pdo->prepare("SELECT * FROM vps_metrics WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 1");
$stmt_latest->execute([$monitor_id]);
$latest_metrics = $stmt_latest->fetch();

$available_metrics = [];
if ($latest_metrics) {
    foreach ($registry as $key => $meta) {
        if (isset($latest_metrics[$meta['column']]) && $latest_metrics[$meta['column']] !== null) {
            $available_metrics[$key] = $meta;
        }
    }
}

// Response time data availability
$stmt_rt = $pdo->prepare("SELECT COUNT(*) FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt_rt->execute([$monitor_id]);
$has_response_data = ((int)$stmt_rt->fetchColumn()) > 0;

// Timeline events
$timeline = bk_get_monitor_timeline($pdo, $monitor_id, 30);

// Insights
$monitor_insights = array_merge(
    bk_get_forecast_insights($pdo, $monitor),
    bk_get_anomaly_insights($pdo, $monitor),
    bk_get_network_insights($pdo, $monitor, $details)
);

// Status color
$status_color = ['up' => 'var(--color-green)', 'down' => 'var(--color-red)', 'maintenance' => 'var(--color-yellow)'][$monitor['status']] ?? 'var(--text-muted)';
$status_label = t('status_' . $monitor['status']);

// Type icon
$type_icons = ['web' => 'fa-globe', 'port' => 'fa-network-wired', 'vps' => 'fa-server', 'minecraft' => 'fa-cube', 'teamspeak' => 'fa-headset', 'discord' => 'fa-brands fa-discord'];
$type_icon = $type_icons[$monitor['type']] ?? 'fa-circle';

// Uptime duration
$uptime_str = '';
if ($monitor['last_status_change'] && $monitor['status'] === 'up') {
    $uptime_str = format_uptime_cz(time() - strtotime($monitor['last_status_change']));
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($GLOBALS['BK_LANG']); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <title><?php echo htmlspecialchars($monitor['name'] . ' - ' . $site_title); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BK_CDN_FONTAWESOME; ?>">
    <script src="<?php echo BK_CDN_ECHARTS; ?>"></script>
    <script>if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-theme'); }</script>
    <style>
        .mp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 1rem; }
        .mp-chart-card { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 0.75rem; }
        .mp-chart-card h3 { font-size: 0.78rem; color: var(--text-secondary); margin: 0 0 0.5rem 0; font-weight: 600; }
        .mp-chart-box { height: 220px; width: 100%; }
        .mp-stat-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem 1rem; text-align: center; min-width: 100px; }
        .mp-stat-card .val { font-size: 1.3rem; font-weight: 700; color: var(--text-primary); }
        .mp-stat-card .lbl { font-size: 0.68rem; color: var(--text-muted); text-transform: uppercase; margin-top: 0.15rem; }
        .mp-section { margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem; }
        .mp-section-title { font-size: 0.85rem; font-weight: 600; color: var(--text-primary); margin-bottom: 0.75rem; }
        .mp-section-title i { color: var(--text-muted); margin-right: 0.4rem; }
        .mp-info-row { display: flex; justify-content: space-between; padding: 0.3rem 0; font-size: 0.78rem; }
        .mp-info-row .k { color: var(--text-muted); }
        .mp-info-row .v { color: var(--text-primary); font-weight: 500; }
        .mp-uptime-bar { display: flex; gap: 2px; height: 32px; align-items: stretch; }
        .mp-uptime-bar .day { flex: 1; border-radius: 3px; min-width: 4px; cursor: pointer; transition: transform 0.1s; }
        .mp-uptime-bar .day:hover { transform: scaleY(1.2); }
        @media (max-width: 900px) { .mp-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container" style="max-width: 1100px; margin: 0 auto; padding: 1.5rem 1rem;">

    <!-- Breadcrumb -->
    <nav style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem;">
        <a href="index.php" style="color: var(--text-muted); text-decoration: none;"><?php echo htmlspecialchars(t('breadcrumb_dashboard')); ?></a>
        <span style="margin: 0 0.4rem;">/</span>
        <span style="color: var(--text-primary);"><?php echo htmlspecialchars($monitor['name']); ?></span>
    </nav>

    <!-- Header -->
    <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center;">
            <i class="fas <?php echo $type_icon; ?>" style="font-size: 1.2rem; color: <?php echo $status_color; ?>;"></i>
        </div>
        <div style="flex: 1;">
            <h1 style="font-size: 1.4rem; margin: 0; color: var(--text-primary);"><?php echo htmlspecialchars($monitor['name']); ?></h1>
            <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 0.2rem;">
                <span style="display: inline-flex; align-items: center; gap: 0.3rem;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo $status_color; ?>;"></span>
                    <?php echo htmlspecialchars($status_label); ?>
                </span>
                <?php if ($uptime_str): ?> &middot; <i class="fas fa-clock"></i> <?php echo htmlspecialchars($uptime_str); ?><?php endif; ?>
                <?php if ($monitor['type']): ?> &middot; <span style="text-transform: uppercase; font-size: 0.68rem; background: rgba(255,255,255,0.05); padding: 0.1rem 0.4rem; border-radius: 4px;"><?php echo htmlspecialchars($monitor['type']); ?></span><?php endif; ?>
                <?php if (!empty($monitor['asset_name'])): ?> &middot; <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($monitor['asset_name']); ?><?php endif; ?>
            </div>
        </div>
        <?php if ($is_admin): ?>
            <a href="admin.php?edit=<?php echo (int)$monitor['id']; ?>" class="btn btn-secondary btn-sm" style="font-size: 0.75rem;"><i class="fas fa-pen"></i> <?php echo htmlspecialchars(t('btn_edit')); ?></a>
        <?php endif; ?>
    </div>

    <!-- Overview stat cards -->
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.5rem;">
        <?php if (isset($details['cpu'])): ?>
            <div class="mp-stat-card"><div class="val" style="color: <?php echo $details['cpu'] > 80 ? 'var(--color-red)' : ($details['cpu'] > 50 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>;"><?php echo htmlspecialchars($details['cpu']); ?>%</div><div class="lbl">CPU</div></div>
        <?php endif; ?>
        <?php if (isset($details['ram'])): ?>
            <div class="mp-stat-card"><div class="val" style="color: <?php echo $details['ram'] > 85 ? 'var(--color-red)' : ($details['ram'] > 60 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>;"><?php echo htmlspecialchars($details['ram']); ?>%</div><div class="lbl">RAM</div></div>
        <?php endif; ?>
        <?php if (isset($details['hdd'])): ?>
            <div class="mp-stat-card"><div class="val" style="color: <?php echo $details['hdd'] > 90 ? 'var(--color-red)' : ($details['hdd'] > 70 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>;"><?php echo htmlspecialchars($details['hdd']); ?>%</div><div class="lbl"><?php echo htmlspecialchars(t('metric_label_hdd')); ?></div></div>
        <?php endif; ?>
        <?php if (isset($details['net'])): ?>
            <div class="mp-stat-card"><div class="val"><?php echo htmlspecialchars($details['net']); ?></div><div class="lbl">KB/s</div></div>
        <?php endif; ?>
        <?php if ($has_response_data && $monitor['type'] !== 'vps'): ?>
            <div class="mp-stat-card"><div class="val"><?php
                $stmt_avg_rt = $pdo->prepare("SELECT AVG(response_time) FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'up'");
                $stmt_avg_rt->execute([$monitor_id]);
                $avg_rt = $stmt_avg_rt->fetchColumn();
                echo $avg_rt !== null ? round((float)$avg_rt) . ' ms' : '—';
            ?></div><div class="lbl"><?php echo htmlspecialchars(t('response_time')); ?></div></div>
        <?php endif; ?>
        <?php if (isset($details['uptime'])): ?>
            <div class="mp-stat-card"><div class="val" style="font-size: 1rem;"><?php echo htmlspecialchars(format_uptime_cz($details['uptime'])); ?></div><div class="lbl">Uptime</div></div>
        <?php endif; ?>
    </div>

    <!-- Period switcher -->
    <?php if (!empty($available_metrics) || $has_response_data): ?>
    <div style="display: flex; justify-content: flex-end; margin-bottom: 0.75rem;">
        <div style="display: flex; gap: 0.25rem;" id="mpPeriodSwitch">
            <?php foreach (['24h', '7d', '30d'] as $p): ?>
                <button type="button" data-period="<?php echo $p; ?>" class="btn btn-secondary btn-sm <?php echo $p === '24h' ? 'active' : ''; ?>" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><?php echo htmlspecialchars(t('period_' . $p)); ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Charts grid -->
    <div class="mp-grid" id="mpChartsGrid">
        <?php
        // Build chart configs based on available metrics
        $chart_configs = [];
        $metric_groups = [
            'cpu' => ['metrics' => ['cpu', 'cpu_steal'], 'title' => 'CPU'],
            'ram' => ['metrics' => ['ram', 'swap'], 'title' => 'RAM / Swap'],
            'hdd' => ['metrics' => ['hdd', 'inode_usage'], 'title' => t('metric_label_hdd') . ' / Inode'],
            'net' => ['metrics' => ['net'], 'title' => t('metric_label_net')],
            'load' => ['metrics' => ['load1', 'load5', 'load15'], 'title' => 'Load Average'],
            'diskio' => ['metrics' => ['disk_io_read', 'disk_io_write'], 'title' => 'Disk I/O'],
            'iowait' => ['metrics' => ['iowait'], 'title' => 'IO Wait'],
            'ts_clients' => ['metrics' => ['ts_clients'], 'title' => t('metric_label_ts_clients')],
            'ts_proc' => ['metrics' => ['ts_process_cpu', 'ts_process_ram'], 'title' => 'TS3 Process'],
        ];
        foreach ($metric_groups as $gid => $group) {
            $has_data = false;
            foreach ($group['metrics'] as $mk) {
                if (isset($available_metrics[$mk])) { $has_data = true; break; }
            }
            if ($has_data) {
                $chart_configs[] = ['id' => 'mpc_' . $gid, 'metrics' => array_values(array_intersect($group['metrics'], array_keys($available_metrics))), 'title' => $group['title']];
            }
        }
        // Response time chart for non-VPS monitors
        if ($has_response_data) {
            $chart_configs[] = ['id' => 'mpc_response', 'metrics' => ['__response__'], 'title' => t('response_time')];
        }
        foreach ($chart_configs as $cc):
        ?>
            <div class="mp-chart-card">
                <h3><?php echo htmlspecialchars($cc['title']); ?></h3>
                <div class="mp-chart-box" id="<?php echo $cc['id']; ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Uptime bar (30 days) -->
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-chart-simple"></i> <?php echo htmlspecialchars(t('mp_uptime_30d')); ?></div>
        <div class="mp-uptime-bar" id="mpUptimeBar"></div>
        <div style="display: flex; justify-content: space-between; font-size: 0.65rem; color: var(--text-muted); margin-top: 0.3rem;">
            <span><?php echo date('d.m.', strtotime('-30 days')); ?></span>
            <span><?php echo date('d.m.'); ?></span>
        </div>
    </div>

    <!-- Agent info -->
    <?php if (!empty($details['os']) || !empty($details['hostname']) || !empty($details['version'])): ?>
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-microchip"></i> <?php echo htmlspecialchars(t('mp_agent_info')); ?></div>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0 2rem;">
            <?php if (!empty($details['version'])): ?>
                <?php $latest_v = bk_get_agent_latest_version($details['agent_type'] ?? ''); $has_update = $latest_v !== null && version_compare($details['version'], $latest_v, '<'); ?>
                <div class="mp-info-row"><span class="k"><?php echo htmlspecialchars(t('mp_agent_version')); ?></span><span class="v"><?php echo htmlspecialchars($details['version']); ?><?php if ($has_update): ?> <span style="color: var(--color-yellow); font-size: 0.68rem;">(<i class="fas fa-arrow-up"></i> <?php echo htmlspecialchars($latest_v); ?>)</span><?php endif; ?></span></div>
            <?php endif; ?>
            <?php if (!empty($details['os'])): ?><div class="mp-info-row"><span class="k">OS</span><span class="v"><?php echo htmlspecialchars($details['os']); ?></span></div><?php endif; ?>
            <?php if (!empty($details['hostname'])): ?><div class="mp-info-row"><span class="k">Hostname</span><span class="v"><?php echo htmlspecialchars($details['hostname']); ?></span></div><?php endif; ?>
            <?php if (!empty($details['kernel'])): ?><div class="mp-info-row"><span class="k">Kernel</span><span class="v"><?php echo htmlspecialchars($details['kernel']); ?></span></div><?php endif; ?>
            <?php if (!empty($details['timezone'])): ?><div class="mp-info-row"><span class="k"><?php echo htmlspecialchars(t('mp_timezone')); ?></span><span class="v"><?php echo htmlspecialchars($details['timezone']); ?></span></div><?php endif; ?>
            <?php if (!empty($details['cloud_provider']) || !empty($details['virtualization'])): ?><div class="mp-info-row"><span class="k"><?php echo htmlspecialchars(t('mp_provider')); ?></span><span class="v"><?php echo htmlspecialchars($details['cloud_provider'] ?? '?'); ?><?php if (!empty($details['virtualization'])): ?> (<?php echo htmlspecialchars($details['virtualization']); ?>)<?php endif; ?></span></div><?php endif; ?>
            <?php if (!empty($details['smart']) && strpos($details['smart'], 'chybí') === false && $details['smart'] !== 'N/A'): ?><div class="mp-info-row"><span class="k">SMART</span><span class="v" style="color: <?php echo strpos($details['smart'], 'WARNING') !== false ? 'var(--color-red)' : 'var(--color-green)'; ?>;"><?php echo htmlspecialchars($details['smart']); ?></span></div><?php endif; ?>
            <?php if (!empty($details['reboot_required'])): ?><div class="mp-info-row"><span class="k"><?php echo htmlspecialchars(t('mp_reboot')); ?></span><span class="v" style="color: var(--color-yellow);"><i class="fas fa-power-off"></i> <?php echo htmlspecialchars(t('mp_reboot_needed')); ?></span></div><?php endif; ?>
            <?php if (isset($details['temperature']) && $details['temperature'] !== null): ?><div class="mp-info-row"><span class="k"><?php echo htmlspecialchars(t('mp_temperature')); ?></span><span class="v" style="color: <?php echo $details['temperature'] > 80 ? 'var(--color-red)' : 'var(--text-primary)'; ?>;"><?php echo $details['temperature']; ?>°C</span></div><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Discovered services -->
    <?php if (!empty($details['discovered_services']) && is_array($details['discovered_services'])): ?>
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-cubes"></i> <?php echo htmlspecialchars(t('agent_discovered_services')); ?></div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <?php foreach ($details['discovered_services'] as $svc):
                $conf = (int)($svc['confidence'] ?? 0);
                $sc = $conf >= 70 ? 'var(--color-green)' : ($conf >= 40 ? 'var(--color-yellow)' : 'var(--text-secondary)');
                $sbg = $conf >= 70 ? 'rgba(30,199,115,0.1)' : ($conf >= 40 ? 'rgba(243,156,18,0.1)' : 'rgba(148,163,184,0.08)');
                $sbd = $conf >= 70 ? 'rgba(30,199,115,0.2)' : ($conf >= 40 ? 'rgba(243,156,18,0.2)' : 'rgba(148,163,184,0.15)');
            ?>
                <div style="background: <?php echo $sbg; ?>; border: 1px solid <?php echo $sbd; ?>; padding: 0.4rem 0.7rem; border-radius: 6px; font-size: 0.75rem; display: flex; align-items: center; gap: 0.4rem;" title="<?php echo htmlspecialchars(implode(', ', $svc['evidence'] ?? [])); ?>">
                    <i class="fas fa-cube" style="color: <?php echo $sc; ?>;"></i>
                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($svc['name'] ?? '?'); ?></strong>
                    <?php if (!empty($svc['port'])): ?><span style="font-family: monospace; color: var(--text-muted); font-size: 0.68rem;">:<?php echo (int)$svc['port']; ?></span><?php endif; ?>
                    <span style="color: <?php echo $sc; ?>; font-weight: bold; font-size: 0.68rem;"><?php echo $conf; ?>%</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Processes -->
    <?php
    $monitored_str = $monitor['monitored_processes'] ?? '';
    $monitored_arr = !empty($monitored_str) ? array_filter(array_map('trim', explode(',', $monitored_str))) : [];
    $missing_arr = $details['missing_processes'] ?? [];
    $has_top_procs = !empty($details['top_cpu_processes']) || !empty($details['top_ram_processes']);
    if (!empty($monitored_arr) || $has_top_procs):
    ?>
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-list-check"></i> <?php echo htmlspecialchars(t('mp_processes')); ?></div>
        <?php if (!empty($monitored_arr)): ?>
            <div style="display: flex; flex-wrap: wrap; gap: 0.35rem; margin-bottom: 0.75rem;">
                <?php foreach ($monitored_arr as $proc):
                    $is_missing = in_array($proc, $missing_arr);
                ?>
                    <span style="background: <?php echo $is_missing ? 'rgba(193,18,31,0.1)' : 'rgba(30,199,115,0.1)'; ?>; border: 1px solid <?php echo $is_missing ? 'rgba(193,18,31,0.2)' : 'rgba(30,199,115,0.2)'; ?>; color: <?php echo $is_missing ? 'var(--color-red)' : 'var(--color-green)'; ?>; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.72rem; font-weight: bold; display: inline-flex; align-items: center; gap: 0.3rem;">
                        <i class="fas <?php echo $is_missing ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i> <?php echo htmlspecialchars($proc); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($has_top_procs): ?>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <?php if (!empty($details['top_cpu_processes'])): ?>
                    <div>
                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-bottom: 0.3rem;">TOP CPU</div>
                        <?php foreach ($details['top_cpu_processes'] as $tp): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.72rem; padding: 0.15rem 0;"><span style="color: var(--text-secondary);"><?php echo htmlspecialchars($tp['name'] ?? '?'); ?></span><strong style="color: var(--text-primary);"><?php echo htmlspecialchars((string)($tp['cpu'] ?? 0)); ?>%</strong></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($details['top_ram_processes'])): ?>
                    <div>
                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-bottom: 0.3rem;">TOP RAM</div>
                        <?php foreach ($details['top_ram_processes'] as $tp): ?>
                            <div style="display: flex; justify-content: space-between; font-size: 0.72rem; padding: 0.15rem 0;"><span style="color: var(--text-secondary);"><?php echo htmlspecialchars($tp['name'] ?? '?'); ?></span><strong style="color: var(--text-primary);"><?php echo htmlspecialchars((string)($tp['ram_mb'] ?? 0)); ?> MB</strong></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Active ports -->
    <?php if (!empty($details['ports'])):
        $ports_arr = is_array($details['ports']) ? $details['ports'] : array_filter(array_map('trim', explode(',', $details['ports'])));
    ?>
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-plug"></i> <?php echo htmlspecialchars(t('mp_ports')); ?></div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
            <?php foreach ($ports_arr as $p): ?>
                <span style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.15rem 0.45rem; border-radius: 4px; font-size: 0.72rem; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($p); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Events timeline -->
    <?php if (!empty($timeline)): ?>
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-timeline"></i> <?php echo htmlspecialchars(t('mp_timeline')); ?></div>
        <div style="display: flex; flex-direction: column; gap: 0.5rem;">
            <?php foreach (array_slice($timeline, 0, 15) as $ev):
                $ev_label_key = 'timeline_event_' . $ev['event_type'];
                $ev_label = t($ev_label_key);
                if ($ev_label === $ev_label_key) $ev_label = $ev['description'] ?: $ev['event_type'];
                $ev_icons = ['status_up' => 'fa-circle-check', 'status_down' => 'fa-circle-xmark', 'agent_connected' => 'fa-plug-circle-check', 'agent_disconnected' => 'fa-plug-circle-xmark', 'scheme_upgraded' => 'fa-arrow-up', 'cert_renewed' => 'fa-certificate', 'service_discovered' => 'fa-cube', 'service_lost' => 'fa-cube'];
                $ev_icon = $ev_icons[$ev['event_type']] ?? 'fa-circle-info';
                $ev_color = str_contains($ev['event_type'], 'down') || str_contains($ev['event_type'], 'lost') || str_contains($ev['event_type'], 'disconnected') ? 'var(--color-red)' : (str_contains($ev['event_type'], 'up') || str_contains($ev['event_type'], 'connected') || str_contains($ev['event_type'], 'discovered') ? 'var(--color-green)' : 'var(--text-muted)');
            ?>
                <div style="display: flex; align-items: center; gap: 0.6rem; font-size: 0.78rem;">
                    <i class="fas <?php echo $ev_icon; ?>" style="color: <?php echo $ev_color; ?>; width: 16px; text-align: center;"></i>
                    <span style="color: var(--text-secondary); flex: 1;"><?php echo htmlspecialchars($ev_label); ?></span>
                    <span style="color: var(--text-muted); font-size: 0.7rem; white-space: nowrap;"><?php echo htmlspecialchars(bk_relative_time_label($ev['ts'])); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Insights -->
    <?php if (!empty($monitor_insights)): ?>
    <div class="mp-section">
        <?php echo render_insights_panel($monitor_insights); ?>
    </div>
    <?php endif; ?>

    <!-- Related metrics (links to Level 3) -->
    <?php if (!empty($available_metrics)): ?>
    <div class="mp-section">
        <div class="mp-section-title"><i class="fas fa-diagram-project"></i> <?php echo htmlspecialchars(t('related_metrics_heading')); ?></div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <?php foreach ($available_metrics as $rkey => $rmeta):
                $rval = $latest_metrics[$rmeta['column']] ?? null;
            ?>
                <a href="index.php?view=metric&monitor=<?php echo (int)$monitor['id']; ?>&metric=<?php echo htmlspecialchars($rkey); ?>" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 0.35rem 0.65rem; font-size: 0.75rem; color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.35rem;">
                    <?php echo htmlspecialchars(t($rmeta['label_key'])); ?>
                    <?php if ($rval !== null): ?><strong style="color: var(--text-primary); font-size: 0.72rem;"><?php echo round((float)$rval, 1); ?><?php echo htmlspecialchars($rmeta['unit']); ?></strong><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
(function () {
    var monitorId = <?php echo (int)$monitor_id; ?>;
    var chartConfigs = <?php echo json_encode($chart_configs ?? []); ?>;
    var charts = {};
    var currentPeriod = '24h';
    var isDark = localStorage.getItem('theme') !== 'light';
    var colors = ['#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de', '#3ba272', '#fc8452', '#9a60b4'];

    function initCharts() {
        chartConfigs.forEach(function (cfg) {
            var el = document.getElementById(cfg.id);
            if (el) charts[cfg.id] = echarts.init(el, isDark ? 'dark' : null);
        });
    }

    function loadChart(cfg, period) {
        var chart = charts[cfg.id];
        if (!chart) return;

        if (cfg.metrics[0] === '__response__') {
            fetch('api.php?action=response_history&monitor_id=' + monitorId + '&period=' + period)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    chart.setOption({
                        backgroundColor: 'transparent',
                        grid: { left: 50, right: 15, top: 20, bottom: 35 },
                        tooltip: { trigger: 'axis', valueFormatter: function (v) { return v !== null ? v + ' ms' : '—'; } },
                        xAxis: { type: 'time' },
                        yAxis: { type: 'value', axisLabel: { formatter: '{value} ms' } },
                        dataZoom: [{ type: 'inside' }],
                        series: [{ type: 'line', showSymbol: false, data: (data.points || []).map(function (p) { return [p[0] * 1000, p[1]]; }), areaStyle: { opacity: 0.08 }, lineStyle: { width: 2 }, itemStyle: { color: '#73c0de' } }]
                    }, true);
                }).catch(function () {});
            return;
        }

        var seriesPromises = cfg.metrics.map(function (mk) {
            return fetch('api.php?action=metric_series&monitor_id=' + monitorId + '&metric=' + mk + '&period=' + period)
                .then(function (r) { return r.json(); });
        });

        Promise.all(seriesPromises).then(function (results) {
            var series = [];
            var legend = [];
            results.forEach(function (payload, i) {
                if (!payload.points || payload.points.length === 0) return;
                var label = payload.label || cfg.metrics[i];
                legend.push(label);
                series.push({
                    type: 'line', name: label, showSymbol: false,
                    data: payload.points.map(function (p) { return [p[0] * 1000, p[1]]; }),
                    areaStyle: { opacity: 0.06 }, lineStyle: { width: 2 },
                    itemStyle: { color: colors[i % colors.length] }
                });
            });
            if (series.length === 0) return;
            var unit = results[0].unit || '';
            chart.setOption({
                backgroundColor: 'transparent',
                grid: { left: 50, right: 15, top: legend.length > 1 ? 28 : 15, bottom: 35 },
                legend: legend.length > 1 ? { top: 0, textStyle: { fontSize: 10 } } : undefined,
                tooltip: { trigger: 'axis', valueFormatter: function (v) { return v !== null && v !== undefined ? v + ' ' + unit : '—'; } },
                xAxis: { type: 'time' },
                yAxis: { type: 'value', axisLabel: { formatter: '{value}' + (unit ? ' ' + unit : '') } },
                dataZoom: [{ type: 'inside' }],
                series: series
            }, true);
        }).catch(function () {});
    }

    function loadAllCharts(period) {
        currentPeriod = period;
        chartConfigs.forEach(function (cfg) { loadChart(cfg, period); });
    }

    function loadUptimeBar() {
        fetch('api.php?action=status_history&monitor_id=' + monitorId + '&days=30')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var bar = document.getElementById('mpUptimeBar');
                if (!bar || !data.days) return;
                bar.innerHTML = '';
                data.days.forEach(function (d) {
                    var el = document.createElement('div');
                    el.className = 'day';
                    var pct = d.uptime_pct;
                    el.style.background = pct >= 99 ? 'var(--color-green)' : (pct >= 90 ? 'var(--color-yellow)' : 'var(--color-red)');
                    el.title = d.date + ' — ' + pct.toFixed(1) + '% uptime' + (d.avg_response ? ' — ' + d.avg_response + ' ms' : '');
                    bar.appendChild(el);
                });
            }).catch(function () {});
    }

    // Period switcher
    var switcher = document.getElementById('mpPeriodSwitch');
    if (switcher) {
        switcher.querySelectorAll('button[data-period]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switcher.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                loadAllCharts(btn.dataset.period);
            });
        });
    }

    window.addEventListener('resize', function () { Object.values(charts).forEach(function (c) { c.resize(); }); });

    initCharts();
    loadAllCharts('24h');
    loadUptimeBar();

    // Auto-refresh every 60s
    setInterval(function () { loadAllCharts(currentPeriod); }, 60000);
})();
</script>
</body>
</html>
