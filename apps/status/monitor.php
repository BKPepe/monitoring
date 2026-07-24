<?php
/**
 * Asset Overview — Operations Center (Level 2)
 * Philosophy: Status → Insights → Timeline → Charts (charts LAST).
 * Access: monitor.php?id=X
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

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

// Cross-link: Automaticky propojí detaily z VPS/OpenWrt agenta (ts3_process, discovered_services, top_cpu atd.)
bk_enrich_monitor_details($pdo, $monitor, $details);

// Latest metrics row
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

// Response time availability
$stmt_rt = $pdo->prepare("SELECT COUNT(*) FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$stmt_rt->execute([$monitor_id]);
$has_response_data = ((int)$stmt_rt->fetchColumn()) > 0;

// === Asset Overview data ===
$health_score = bk_compute_asset_health_score($pdo, $monitor, $details, $latest_metrics);
$health_dots = bk_get_30day_health_dots($pdo, $monitor_id);
$card_profile = bk_get_type_card_profile($monitor['type']);
$timeline = bk_get_monitor_timeline($pdo, $monitor_id, 30);
$iface_traffic_stats = bk_get_interface_traffic_stats($pdo, $monitor_id);

// Asset siblings
$asset_siblings = [];
if (!empty($monitor['asset_id'])) {
    $stmt_sib = $pdo->prepare("SELECT id, name, type, status FROM monitors WHERE asset_id = ? AND id != ?");
    $stmt_sib->execute([$monitor['asset_id'], $monitor_id]);
    $asset_siblings = $stmt_sib->fetchAll();
}

// Insights
$monitor_insights = array_merge(
    bk_get_forecast_insights($pdo, $monitor),
    bk_get_anomaly_insights($pdo, $monitor),
    bk_get_network_insights($pdo, $monitor, $details)
);

// Executive summary
$exec_summary = bk_build_executive_summary($monitor, ['score' => $health_score], [], $monitor_insights, array_slice($timeline, 0, 3));

// Status helpers
$status_map = ['up' => 'ao_healthy', 'down' => 'ao_critical', 'maintenance' => 'ao_maintenance', 'unknown' => 'ao_offline'];
$status_label = t($status_map[$monitor['status']] ?? 'ao_offline');
$status_color = ['up' => 'var(--color-green)', 'down' => 'var(--color-red)', 'maintenance' => 'var(--color-yellow)'][$monitor['status']] ?? 'var(--text-muted)';
$status_bg = ['up' => 'rgba(30,199,115,0.12)', 'down' => 'rgba(193,18,31,0.12)', 'maintenance' => 'rgba(243,156,18,0.12)'][$monitor['status']] ?? 'rgba(148,163,184,0.1)';

// Score ring color
$ring_color = $health_score >= 80 ? 'var(--color-green)' : ($health_score >= 50 ? 'var(--color-yellow)' : 'var(--color-red)');

// Type icon
$type_icons = ['web' => 'fa-globe', 'port' => 'fa-network-wired', 'vps' => 'fa-server', 'minecraft' => 'fa-cube', 'teamspeak' => 'fa-headset', 'discord' => 'fa-brands fa-discord', 'openwrt' => 'fa-wifi'];
$type_icon = $type_icons[$monitor['type']] ?? 'fa-circle';

// Last seen
$last_seen_str = '';
$agent_last_seen = $details['agent_last_seen'] ?? null;
if ($agent_last_seen) {
    $last_seen_str = bk_relative_time_label((int)$agent_last_seen);
} elseif ($monitor['last_checked']) {
    $last_seen_str = bk_relative_time_label(strtotime($monitor['last_checked']));
}

// Uptime string
$uptime_str = '';
if (!empty($details['uptime'])) {
    $uptime_str = format_uptime_cz($details['uptime']);
} elseif ($monitor['last_status_change'] && $monitor['status'] === 'up') {
    $uptime_str = format_uptime_cz(time() - strtotime($monitor['last_status_change']));
}

// Resolve card values from details
function ao_resolve_value($source, $details, $latest_metrics, $monitor, $pdo, $monitor_id) {
    if (str_starts_with($source, 'details.')) {
        $path = explode('.', substr($source, 8));
        $val = $details;
        foreach ($path as $p) { $val = $val[$p] ?? null; if ($val === null) break; }
        return $val;
    }
    if (str_starts_with($source, 'special.')) {
        $key = substr($source, 8);
        return match($key) {
            'wan' => isset($details['wan_up']) ? ($details['wan_up'] ? t('ao_online') : t('ao_offline')) : null,
            'wireguard' => isset($details['wireguard_peers']) && is_array($details['wireguard_peers']) ? count($details['wireguard_peers']) . ' ' . t('ao_peers') : (isset($details['wireguard_peers']) && is_numeric($details['wireguard_peers']) ? $details['wireguard_peers'] . ' ' . t('ao_peers') : null),
            'wifi' => isset($details['wifi_clients_count']) ? $details['wifi_clients_count'] . ' ' . t('ao_clients') : (isset($details['wifi_clients_total']) ? $details['wifi_clients_total'] . ' ' . t('ao_clients') : null),
            'ts_clients' => isset($details['clients_online']) ? $details['clients_online'] . '/' . ($details['max_clients'] ?? '?') : null,
            'ts_voice' => $details['voice_quality'] ?? null,
            'ts_ping' => $details['ping'] ?? null,
            'mc_players' => isset($details['players_online']) ? ($details['players_online'] . (!empty($details['max_players']) ? '/' . $details['max_players'] : '')) : null,
            'response_time' => (function() use ($pdo, $monitor_id) {
                try {
                    $s = $pdo->prepare("SELECT AVG(response_time) FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'up'");
                    $s->execute([$monitor_id]);
                    $v = $s->fetchColumn();
                    return $v !== null ? round((float)$v) : null;
                } catch (PDOException $e) { return null; }
            })(),
            'ssl_days' => $details['ssl_days_remaining'] ?? null,
            'uptime_pct' => (function() use ($pdo, $monitor_id) {
                try {
                    $s = $pdo->prepare("SELECT SUM(status='up')*100.0/COUNT(*) FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
                    $s->execute([$monitor_id]);
                    $v = $s->fetchColumn();
                    return $v !== null ? round((float)$v, 1) : null;
                } catch (PDOException $e) { return null; }
            })(),
            default => null,
        };
    }
    return null;
}

// Build card data with context
$cards_data = [];
foreach ($card_profile as $key => $cfg) {
    $val = ao_resolve_value($cfg['source'], $details, $latest_metrics, $monitor, $pdo, $monitor_id);
    if ($val === null) continue;
    $ctx = null;
    // Get context for numeric metrics from vps_metrics
    $metric_col_map = ['cpu' => 'cpu_usage', 'ram' => 'ram_usage', 'hdd' => 'hdd_usage', 'temperature' => 'temperature_c', 'load' => 'load_avg_1', 'net' => 'net_usage', 'swap' => 'swap_usage', 'conntrack' => 'conntrack_pct'];
    if (isset($metric_col_map[$key]) && is_numeric($val)) {
        $ctx = bk_metric_context($pdo, $monitor_id, $metric_col_map[$key], (float)$val);
    }
    $cards_data[] = ['key' => $key, 'cfg' => $cfg, 'value' => $val, 'ctx' => $ctx];
}

// Chart configs
$chart_configs = [];
$metric_groups = [
    'cpu' => ['metrics' => ['cpu', 'cpu_steal'], 'title' => 'CPU'],
    'ram' => ['metrics' => ['ram', 'swap'], 'title' => 'RAM / Swap'],
    'hdd' => ['metrics' => ['hdd', 'inode_usage'], 'title' => t('metric_label_hdd') . ' / Inode'],
    'net' => ['metrics' => ['net'], 'title' => t('metric_label_net')],
    'net_ip' => ['metrics' => ['net_ipv4', 'net_ipv6'], 'title' => 'IPv4 vs IPv6 Provoz'],
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
if ($has_response_data) {
    $chart_configs[] = ['id' => 'mpc_response', 'metrics' => ['__response__'], 'title' => t('response_time')];
}

// Group timeline by day
$timeline_grouped = [];
foreach ($timeline as $ev) {
    $day = date('Y-m-d', strtotime($ev['ts']));
    $timeline_grouped[$day][] = $ev;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($GLOBALS['BK_LANG']); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <title><?php echo htmlspecialchars($monitor['name'] . ' — ' . $site_title); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BK_CDN_FONTAWESOME; ?>" integrity="<?php echo BK_CDN_FONTAWESOME_SRI; ?>" crossorigin="anonymous">
    <script src="<?php echo BK_CDN_ECHARTS; ?>" integrity="<?php echo BK_CDN_ECHARTS_SRI; ?>" crossorigin="anonymous"></script>
    <script>if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-theme'); }</script>
    <style>
        .mp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 1rem; }
        .mp-chart-card { background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 10px; padding: 0.75rem; }
        .mp-chart-card h3 { font-size: 0.78rem; color: var(--text-secondary); margin: 0 0 0.5rem 0; font-weight: 600; }
        .mp-chart-box { height: 280px; width: 100%; }
        @media (max-width: 900px) { .mp-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<!-- Sticky Health Bar -->
<div class="ao-sticky-bar">
    <span style="font-size: 0.72rem; color: var(--text-muted);"><i class="fas fa-server" style="color: var(--color-red); margin-right: 0.2rem;"></i><?php echo htmlspecialchars($site_title); ?> monitoring</span>
    <span style="color: rgba(255,255,255,0.15);">|</span>
    <span class="ao-name"><?php echo htmlspecialchars($monitor['name']); ?></span>
    <span class="ao-pill" style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>;"><?php echo htmlspecialchars($status_label); ?></span>
    <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $health_score; ?>/100</span>
    <?php if ($last_seen_str): ?><span style="font-size: 0.72rem; color: var(--text-muted); margin-left: auto;"><?php echo htmlspecialchars(t('ao_last_seen')); ?>: <?php echo htmlspecialchars($last_seen_str); ?></span><?php endif; ?>
</div>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 1.5rem 1rem;">

    <!-- Breadcrumb + Branding -->
    <nav style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.4rem;">
        <a href="index.php" style="color: var(--text-muted); text-decoration: none;"><i class="fas fa-server" style="color: var(--color-red); margin-right: 0.2rem;"></i><?php echo htmlspecialchars($site_title); ?></a>
        <span>/</span>
        <a href="index.php" style="color: var(--text-muted); text-decoration: none;"><?php echo htmlspecialchars(t('breadcrumb_dashboard')); ?></a>
        <span>/</span>
        <span style="color: var(--text-primary); font-weight: 600;"><?php echo htmlspecialchars($monitor['name']); ?></span>
    </nav>

    <!-- HERO -->
    <div class="ao-hero">
        <div style="display: flex; align-items: flex-start; gap: 1.5rem; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 250px;">
                <h1 class="ao-hero-title"><?php echo htmlspecialchars($monitor['name']); ?></h1>
                <div class="ao-hero-sub" style="display: flex; align-items: center; gap: 0.6rem; flex-wrap: wrap; margin-top: 0.4rem;">
                    <span class="ao-pill" style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase;"><i class="fas <?php echo $type_icon; ?>" style="margin-right: 0.3rem;"></i><?php echo htmlspecialchars($status_label); ?></span>
                    <span style="font-size: 0.72rem; background: rgba(255,255,255,0.05); padding: 0.15rem 0.5rem; border-radius: 4px; text-transform: uppercase;"><?php echo htmlspecialchars($monitor['type']); ?></span>
                    <?php if (!empty($monitor['asset_name'])): ?><span style="font-size: 0.75rem; color: var(--text-muted);"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($monitor['asset_name']); ?></span><?php endif; ?>
                    <?php if ($uptime_str): ?><span style="font-size: 0.75rem; color: var(--text-muted);"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($uptime_str); ?></span><?php endif; ?>
                </div>

                <!-- Quick indicators -->
                <div class="ao-indicators">
                    <?php if (isset($details['cpu'])): ?>
                        <div class="ao-indicator"><div class="val" style="color: <?php echo $details['cpu'] > 80 ? 'var(--color-red)' : ($details['cpu'] > 50 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>;"><?php echo htmlspecialchars($details['cpu']); ?>%</div><div class="lbl">CPU</div></div>
                    <?php endif; ?>
                    <?php if (isset($details['ram'])): ?>
                        <div class="ao-indicator"><div class="val" style="color: <?php echo $details['ram'] > 85 ? 'var(--color-red)' : ($details['ram'] > 60 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>;"><?php echo htmlspecialchars($details['ram']); ?>%</div><div class="lbl">RAM</div></div>
                    <?php endif; ?>
                    <?php if (isset($details['hdd'])): ?>
                        <div class="ao-indicator"><div class="val" style="color: <?php echo $details['hdd'] > 90 ? 'var(--color-red)' : ($details['hdd'] > 70 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>;"><?php echo htmlspecialchars($details['hdd']); ?>%</div><div class="lbl">Disk</div></div>
                    <?php endif; ?>
                    <?php if (isset($details['temperature'])): ?>
                        <div class="ao-indicator"><div class="val" style="color: <?php echo $details['temperature'] > 80 ? 'var(--color-red)' : 'var(--text-primary)'; ?>;"><?php echo $details['temperature']; ?>°C</div><div class="lbl">Temp</div></div>
                    <?php endif; ?>
                    <?php if ($has_response_data && $monitor['type'] !== 'vps'): ?>
                        <div class="ao-indicator"><div class="val"><?php
                            $stmt_avg_rt = $pdo->prepare("SELECT AVG(response_time) FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND status = 'up'");
                            $stmt_avg_rt->execute([$monitor_id]);
                            $avg_rt = $stmt_avg_rt->fetchColumn();
                            echo $avg_rt !== null ? round((float)$avg_rt) . ' ms' : '—';
                        ?></div><div class="lbl"><?php echo htmlspecialchars(t('response_time')); ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($details['version'])): ?>
                        <div class="ao-indicator"><div class="val" style="font-size: 0.9rem;"><?php echo htmlspecialchars($details['version']); ?></div><div class="lbl"><?php echo htmlspecialchars(t('ao_version')); ?></div></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Health Score Ring -->
            <div style="text-align: center;">
                <div class="ao-score-ring" style="--ao-score: <?php echo $health_score; ?>; --ao-ring-color: <?php echo $ring_color; ?>;"><?php echo $health_score; ?></div>
                <div style="font-size: 0.65rem; color: var(--text-muted); margin-top: 0.4rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('ao_health_score')); ?></div>
            </div>
        </div>

        <!-- 30-day health dots -->
        <?php if (!empty($health_dots)): ?>
        <div style="margin-top: 1.25rem;">
            <div style="font-size: 0.68rem; color: var(--text-muted); margin-bottom: 0.4rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('ao_30day_health')); ?></div>
            <div class="ao-health-dots">
                <?php foreach ($health_dots as $dot):
                    $st_map = ['up' => t('history_tooltip_up'), 'down' => 'Detekován výpadek', 'maintenance' => t('history_tooltip_maintenance'), 'none' => t('history_tooltip_nodata')];
                    $st_lbl = $st_map[$dot['status']] ?? $dot['status'];
                    $dot_tooltip = $dot['label'] . ': ' . $st_lbl;
                ?>
                    <div class="dot <?php echo $dot['status']; ?>" data-tooltip="<?php echo htmlspecialchars($dot_tooltip); ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- EXECUTIVE SUMMARY -->
    <?php if ($exec_summary): ?>
    <div class="ao-summary-card">
        <i class="fas fa-lightbulb" style="color: var(--color-yellow); margin-right: 0.4rem;"></i><?php echo htmlspecialchars($exec_summary); ?>
    </div>
    <?php endif; ?>

    <!-- LAYOUT: Main + Sidebar -->
    <div class="ao-layout">
        <div class="ao-main">

            <!-- HEALTH CARDS -->
            <?php if (!empty($cards_data)): ?>
            <div class="ao-section">
                <div class="ao-cards-grid">
                    <?php foreach ($cards_data as $card):
                        $trend_class = 'stable';
                        $trend_label = t('ao_trend_stable');
                        $ctx_line = '';
                        if ($card['ctx']) {
                            $trend_class = $card['ctx']['trend'];
                            $trend_label = t('ao_trend_' . $card['ctx']['trend']);
                            if ($card['ctx']['avg'] !== null && $card['ctx']['min'] !== null) {
                                $ctx_line = sprintf(t('ao_normal_range'), $card['ctx']['min'] . ($card['cfg']['unit'] ?? ''), $card['ctx']['max'] . ($card['cfg']['unit'] ?? ''));
                            }
                            if ($card['ctx']['trend'] === 'up') $ctx_line = t('ao_higher_than_usual') . ($ctx_line ? ' · ' . $ctx_line : '');
                            elseif ($card['ctx']['trend'] === 'down') $ctx_line = t('ao_lower_than_usual') . ($ctx_line ? ' · ' . $ctx_line : '');
                        }
                        // Format display value
                        $display_val = $card['value'];
                        if ($card['cfg']['unit'] === 's' && is_numeric($display_val)) {
                            $display_val = format_uptime_cz((int)$display_val);
                        } elseif (is_numeric($display_val) && $card['cfg']['unit'] !== '') {
                            $display_val = $display_val . $card['cfg']['unit'];
                        }
                    ?>
                    <div class="ao-card" onclick="location.href='index.php?view=metric&monitor=<?php echo (int)$monitor_id; ?>&metric=<?php echo htmlspecialchars($card['key']); ?>'">
                        <div class="ao-card-icon"><i class="fas <?php echo $card['cfg']['icon']; ?>"></i></div>
                        <div class="ao-card-val"><?php echo htmlspecialchars((string)$display_val); ?></div>
                        <div class="ao-card-label"><?php echo htmlspecialchars($card['cfg']['label']); ?></div>
                        <?php if ($card['ctx']): ?>
                            <div class="ao-card-trend <?php echo $trend_class; ?>"><i class="fas fa-arrow-<?php echo $trend_class === 'up' ? 'up' : ($trend_class === 'down' ? 'down' : 'right'); ?>"></i> <?php echo htmlspecialchars($trend_label); ?></div>
                            <?php if ($ctx_line): ?><div class="ao-card-ctx"><?php echo htmlspecialchars($ctx_line); ?></div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- TIMELINE -->
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-timeline"></i> <?php echo htmlspecialchars(t('ao_timeline')); ?></div>
                <div style="background: rgba(88, 166, 255, 0.05); border: 1px solid rgba(88, 166, 255, 0.15); border-radius: 6px; padding: 0.6rem 0.75rem; margin-bottom: 1rem; font-size: 0.76rem; color: var(--text-secondary); display: flex; align-items: center; gap: 0.5rem;">
                    <i class="fas fa-info-circle" style="color: var(--color-blue, #58a6ff); font-size: 0.9rem; flex-shrink: 0;"></i>
                    <div>
                        <strong>Informace k časové ose:</strong> Zobrazují se zde významné události (výpadky, restarty, změny stavu). Pokud systém funguje bez výpadků, časová osa zůstává beze změn. Poslední měření: <strong><?php echo !empty($monitor['last_checked']) ? htmlspecialchars(date('j.n.Y H:i:s', strtotime($monitor['last_checked']))) : '—'; ?></strong>.
                    </div>
                </div>
                <?php if (empty($timeline_grouped)): ?>
                    <p style="font-size: 0.82rem; color: var(--text-muted);"><?php echo htmlspecialchars(t('ao_no_events')); ?></p>
                <?php else: ?>
                    <?php
                    $today = date('Y-m-d');
                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                    foreach (array_slice($timeline_grouped, 0, 7, true) as $day => $events):
                        if ($day === $today) $day_label = t('ao_today');
                        elseif ($day === $yesterday) $day_label = t('ao_yesterday');
                        else $day_label = sprintf(t('ao_days_ago'), (int)((strtotime($today) - strtotime($day)) / 86400));
                    ?>
                    <div class="ao-timeline-group">
                        <div class="ao-timeline-date"><?php echo htmlspecialchars($day_label); ?> <span style="font-weight: 400; opacity: 0.6;">(<?php echo date('j.n.', strtotime($day)); ?>)</span></div>
                        <?php foreach ($events as $ev):
                            $ev_label_key = 'timeline_event_' . $ev['event_type'];
                            $ev_label = t($ev_label_key);
                            if ($ev_label === $ev_label_key) $ev_label = $ev['description'] ?: $ev['event_type'];
                            $ev_icons = ['status_up' => 'fa-circle-check', 'status_down' => 'fa-circle-xmark', 'agent_connected' => 'fa-plug-circle-check', 'agent_disconnected' => 'fa-plug-circle-xmark', 'scheme_upgraded' => 'fa-arrow-up', 'cert_renewed' => 'fa-certificate', 'service_discovered' => 'fa-cube', 'service_lost' => 'fa-cube', 'maintenance_start' => 'fa-tools', 'maintenance_end' => 'fa-check-double', 'cpu_spike' => 'fa-fire', 'wan_reconnect' => 'fa-rotate'];
                            $ev_icon = $ev_icons[$ev['event_type']] ?? 'fa-circle-info';
                            $ev_color = str_contains($ev['event_type'], 'down') || str_contains($ev['event_type'], 'lost') || str_contains($ev['event_type'], 'disconnected') || str_contains($ev['event_type'], 'spike') ? 'var(--color-red)' : (str_contains($ev['event_type'], 'up') || str_contains($ev['event_type'], 'connected') || str_contains($ev['event_type'], 'discovered') ? 'var(--color-green)' : 'var(--text-muted)');
                        ?>
                        <div class="ao-timeline-item">
                            <span class="time"><?php echo date('H:i', strtotime($ev['ts'])); ?></span>
                            <i class="fas <?php echo $ev_icon; ?>" style="color: <?php echo $ev_color; ?>; margin-top: 0.15rem;"></i>
                            <span class="desc"><?php echo htmlspecialchars($ev_label); ?><?php if (!empty($ev['description']) && $ev_label !== $ev['description']): ?> <span style="color: var(--text-muted); font-size: 0.72rem;">— <?php echo htmlspecialchars($ev['description']); ?></span><?php endif; ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- TRENDS (Charts) -->
            <?php if (!empty($chart_configs)): ?>
            <div class="ao-section">
                <div class="ao-section-title" style="justify-content: space-between;">
                    <span><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars(t('ao_trends')); ?></span>
                    <div style="display: flex; gap: 0.25rem;" id="mpPeriodSwitch">
                        <?php foreach (['24h', '7d', '30d'] as $p): ?>
                            <button type="button" data-period="<?php echo $p; ?>" class="btn btn-secondary btn-sm <?php echo $p === '24h' ? 'active' : ''; ?>" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><?php echo htmlspecialchars(t('period_' . $p)); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mp-grid" id="mpChartsGrid">
                    <?php foreach ($chart_configs as $cc): ?>
                        <div class="mp-chart-card">
                            <h3><?php echo htmlspecialchars($cc['title']); ?></h3>
                            <div class="mp-chart-box" id="<?php echo $cc['id']; ?>"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- DIAGNOSTICS / INSIGHTS -->
            <?php if (!empty($monitor_insights)): ?>
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-stethoscope"></i> <?php echo htmlspecialchars(t('ao_diagnostics')); ?></div>
                <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 1rem 1.25rem;">
                    <?php foreach ($monitor_insights as $ins):
                        $sev = $ins['severity'] ?? 'info';
                        $icon_cls = $sev === 'critical' ? 'icon-crit' : ($sev === 'warn' ? 'icon-warn' : 'icon-ok');
                        $icon_fa = $sev === 'critical' ? 'fa-circle-exclamation' : ($sev === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-check');
                    ?>
                    <div class="ao-insight-item">
                        <i class="fas <?php echo $icon_fa; ?> <?php echo $icon_cls; ?>"></i>
                        <span><?php echo htmlspecialchars($ins['text'] ?? ''); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- OPENWRT WIFI & NETWORK SECTION -->
            <?php if (!empty($details['wifi_radios']) || !empty($details['interfaces']) || !empty($details['wan_ipv4']) || isset($details['net_ipv4_kbps'])): ?>
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-wifi"></i> Wi-Fi &amp; Síťová rozhraní (LAN / WAN)</div>
                
                <?php if (!empty($details['wifi_radios'])): ?>
                    <div style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Wi-Fi sítě (<?php echo count($details['wifi_radios']); ?> radios, celkem <?php echo (int)($details['wifi_clients_count'] ?? 0); ?> klientů)</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
                        <?php foreach ($details['wifi_radios'] as $radio): ?>
                            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                                <div style="font-weight: 600; margin-bottom: 0.4rem; display: flex; align-items: center; justify-content: space-between;">
                                    <span><i class="fas fa-broadcast-tower" style="color: var(--color-green);"></i> <?php echo htmlspecialchars($radio['ssid'] ?? $radio['radio']); ?></span>
                                    <span style="background: rgba(255,255,255,0.06); padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.68rem; color: var(--color-blue, #58a6ff);"><?php echo htmlspecialchars($radio['band'] ?? '2.4GHz'); ?></span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--text-secondary); display: flex; flex-direction: column; gap: 0.2rem;">
                                    <span><strong>Radio / Kanál:</strong> <?php echo htmlspecialchars($radio['radio']); ?> (Ch <?php echo htmlspecialchars($radio['channel'] ?? '0'); ?>)</span>
                                    <span><strong>Připojení klienti:</strong> <strong style="color: #fff;"><?php echo htmlspecialchars($radio['clients'] ?? 0); ?></strong></span>
                                    <?php if (!empty($radio['noise'])): ?><span><strong>Šum (Noise):</strong> <?php echo htmlspecialchars($radio['noise']); ?> dBm</span><?php endif; ?>
                                    <?php if (!empty($radio['tx_power'])): ?><span><strong>Vysílací výkon:</strong> <?php echo htmlspecialchars($radio['tx_power']); ?> dBm</span><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($details['net_ipv4_kbps']) || isset($details['net_ipv6_kbps'])): ?>
                    <div style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">IPv4 vs IPv6 Provoz (aktuální rychlost)</div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem; text-align: center;">
                            <div style="font-size: 1.2rem; font-weight: 700; color: var(--color-blue, #58a6ff);"><?php echo (float)($details['net_ipv4_kbps'] ?? 0); ?> KB/s</div>
                            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; margin-top: 0.2rem;"><i class="fas fa-network-wired"></i> IPv4 Provoz</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem; text-align: center;">
                            <div style="font-size: 1.2rem; font-weight: 700; color: #8b5cf6;"><?php echo (float)($details['net_ipv6_kbps'] ?? 0); ?> KB/s</div>
                            <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase; margin-top: 0.2rem;"><i class="fas fa-globe"></i> IPv6 Provoz</div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($details['interfaces'])): ?>
                    <div style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Síťová rozhraní (LAN / WAN / Wi-Fi)</div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.78rem; text-align: left;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted);">
                                    <th style="padding: 0.4rem;">Rozhraní</th>
                                    <th style="padding: 0.4rem;">Přijato (RX)</th>
                                    <th style="padding: 0.4rem;">Odesláno (TX)</th>
                                    <th style="padding: 0.4rem;">Chyby (RX/TX)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($details['interfaces'] as $iface): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.4rem; font-family: monospace; font-weight: 600; color: var(--text-primary);"><?php echo htmlspecialchars($iface['iface']); ?></td>
                                        <td style="padding: 0.4rem;"><?php echo round(($iface['rx_bytes'] ?? 0) / 1048576, 1); ?> MB</td>
                                        <td style="padding: 0.4rem;"><?php echo round(($iface['tx_bytes'] ?? 0) / 1048576, 1); ?> MB</td>
                                        <td style="padding: 0.4rem; color: <?php echo (($iface['rx_errors'] ?? 0) + ($iface['tx_errors'] ?? 0)) > 0 ? 'var(--color-red)' : 'var(--text-muted)'; ?>;"><?php echo ($iface['rx_errors'] ?? 0) . ' / ' . ($iface['tx_errors'] ?? 0); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (!empty($iface_traffic_stats)): ?>
                    <div style="font-size: 0.78rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; margin-top: 1.5rem; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Kumulativní přenesená data rozhraní (Archivováno v čase)</div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.78rem; text-align: left;">
                            <thead>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted);">
                                    <th style="padding: 0.4rem;">Rozhraní</th>
                                    <th style="padding: 0.4rem;">Dnes (RX / TX)</th>
                                    <th style="padding: 0.4rem;">7 dní (RX / TX)</th>
                                    <th style="padding: 0.4rem;">30 dní (RX / TX)</th>
                                    <th style="padding: 0.4rem;">Celkově (RX / TX)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($iface_traffic_stats as $ifname => $st): ?>
                                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.04);">
                                        <td style="padding: 0.4rem; font-family: monospace; font-weight: 700; color: var(--color-blue, #58a6ff);"><?php echo htmlspecialchars($ifname); ?></td>
                                        <td style="padding: 0.4rem;">
                                            <div><strong style="color:var(--color-green);"><?php echo bk_format_bytes_cz($st['today']['rx_bytes']); ?></strong> / <strong style="color:#8b5cf6;"><?php echo bk_format_bytes_cz($st['today']['tx_bytes']); ?></strong></div>
                                            <div style="font-size: 0.68rem; color: var(--text-muted);"><?php echo bk_format_packets_cz($st['today']['rx_pkts']); ?> / <?php echo bk_format_packets_cz($st['today']['tx_pkts']); ?></div>
                                        </td>
                                        <td style="padding: 0.4rem;">
                                            <div><strong style="color:var(--color-green);"><?php echo bk_format_bytes_cz($st['7d']['rx_bytes']); ?></strong> / <strong style="color:#8b5cf6;"><?php echo bk_format_bytes_cz($st['7d']['tx_bytes']); ?></strong></div>
                                            <div style="font-size: 0.68rem; color: var(--text-muted);"><?php echo bk_format_packets_cz($st['7d']['rx_pkts']); ?> / <?php echo bk_format_packets_cz($st['7d']['tx_pkts']); ?></div>
                                        </td>
                                        <td style="padding: 0.4rem;">
                                            <div><strong style="color:var(--color-green);"><?php echo bk_format_bytes_cz($st['30d']['rx_bytes']); ?></strong> / <strong style="color:#8b5cf6;"><?php echo bk_format_bytes_cz($st['30d']['tx_bytes']); ?></strong></div>
                                            <div style="font-size: 0.68rem; color: var(--text-muted);"><?php echo bk_format_packets_cz($st['30d']['rx_pkts']); ?> / <?php echo bk_format_packets_cz($st['30d']['tx_pkts']); ?></div>
                                        </td>
                                        <td style="padding: 0.4rem;">
                                            <div><strong style="color:var(--color-green);"><?php echo bk_format_bytes_cz($st['all']['rx_bytes']); ?></strong> / <strong style="color:#8b5cf6;"><?php echo bk_format_bytes_cz($st['all']['tx_bytes']); ?></strong></div>
                                            <div style="font-size: 0.68rem; color: var(--text-muted);"><?php echo bk_format_packets_cz($st['all']['rx_pkts']); ?> / <?php echo bk_format_packets_cz($st['all']['tx_pkts']); ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- TOP PROCESSES SECTION -->
            <?php if (!empty($details['top_cpu_processes']) || !empty($details['top_ram_processes'])): ?>
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-microchip"></i> Nejvytíženější procesy (CPU &amp; RAM)</div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                    <?php if (!empty($details['top_cpu_processes'])): ?>
                        <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 0.75rem;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem;"><i class="fas fa-fire" style="color: var(--color-red);"></i> Top CPU Procesy</div>
                            <div style="display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.78rem;">
                                <?php foreach ($details['top_cpu_processes'] as $tp): ?>
                                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.04); padding-bottom: 0.2rem;">
                                        <span style="font-family: monospace; color: var(--text-primary);"><?php echo htmlspecialchars($tp['name']); ?></span>
                                        <strong style="color: var(--color-red);"><?php echo $tp['cpu']; ?>%</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($details['top_ram_processes'])): ?>
                        <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; padding: 0.75rem;">
                            <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.5rem;"><i class="fas fa-memory" style="color: var(--color-green);"></i> Top RAM Procesy</div>
                            <div style="display: flex; flex-direction: column; gap: 0.35rem; font-size: 0.78rem;">
                                <?php foreach ($details['top_ram_processes'] as $rp): ?>
                                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid rgba(255,255,255,0.04); padding-bottom: 0.2rem;">
                                        <span style="font-family: monospace; color: var(--text-primary);"><?php echo htmlspecialchars($rp['name']); ?></span>
                                        <strong style="color: var(--color-green);"><?php echo $rp['ram_mb']; ?> MB</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- CONFIGURATION -->
            <?php if (!empty($details['os']) || !empty($details['hostname']) || !empty($details['kernel']) || !empty($details['ram_total_mb'])): ?>
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-gear"></i> <?php echo htmlspecialchars(t('ao_configuration')); ?></div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0 2rem; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 1rem 1.25rem;">
                    <?php if (!empty($details['hostname'])): ?><div class="ao-sidebar-row"><span class="k">Hostname</span><span class="v"><?php echo htmlspecialchars($details['hostname']); ?></span></div><?php endif; ?>
                    <?php if (!empty($details['os'])): ?><div class="ao-sidebar-row"><span class="k">OS</span><span class="v"><?php echo htmlspecialchars($details['os']); ?></span></div><?php endif; ?>
                    <?php if (!empty($details['kernel'])): ?><div class="ao-sidebar-row"><span class="k">Kernel</span><span class="v"><?php echo htmlspecialchars($details['kernel']); ?></span></div><?php endif; ?>
                    <?php if (!empty($details['model'])): ?><div class="ao-sidebar-row"><span class="k">Model</span><span class="v"><?php echo htmlspecialchars($details['model']); ?></span></div><?php endif; ?>
                    <?php if (!empty($details['architecture'])): ?><div class="ao-sidebar-row"><span class="k">Arch</span><span class="v"><?php echo htmlspecialchars($details['architecture']); ?></span></div><?php endif; ?>
                    <?php if (!empty($details['ram_total_mb'])): ?>
                        <?php
                        $r_tot = (int)$details['ram_total_mb'];
                        $r_used = (int)($details['ram_used_mb'] ?? 0);
                        $r_avail = (int)($details['ram_available_mb'] ?? max(0, $r_tot - $r_used));
                        $r_str = ($r_tot >= 1024)
                            ? round($r_used / 1024, 1) . ' GB / ' . round($r_tot / 1024, 1) . ' GB (volné: ' . round($r_avail / 1024, 1) . ' GB)'
                            : $r_used . ' MB / ' . $r_tot . ' MB (volné: ' . $r_avail . ' MB)';
                        ?>
                        <div class="ao-sidebar-row"><span class="k">RAM Paměť</span><span class="v"><?php echo htmlspecialchars($r_str); ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($details['cloud_provider']) || !empty($details['virtualization'])): ?><div class="ao-sidebar-row"><span class="k">Provider</span><span class="v"><?php echo htmlspecialchars(($details['cloud_provider'] ?? '') . (!empty($details['virtualization']) ? ' (' . $details['virtualization'] . ')' : '')); ?></span></div><?php endif; ?>
                    <?php if (isset($details['upgradable_packages']) || !empty($details['installed_packages'])): ?>
                        <?php $ho_h = (int)($details['heavy_op_interval_hours'] ?? 24); ?>
                        <div class="ao-sidebar-row">
                            <span class="k">Balíčky</span>
                            <span class="v" data-tooltip="Kontrola balíčků se spouští 1× za <?php echo $ho_h; ?>h pro úsporu CPU a diskového I/O.">
                                <?php if (!empty($details['installed_packages'])): ?><?php echo (int)$details['installed_packages']; ?> nainstalováno<?php endif; ?>
                                <?php if (isset($details['upgradable_packages'])): ?> (<?php echo (int)$details['upgradable_packages']; ?> k aktualizaci)<?php endif; ?>
                                <i class="fas fa-clock" style="color: var(--color-blue, #58a6ff); font-size: 0.72rem; margin-left: 0.25rem;"></i>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($details['timezone'])): ?><div class="ao-sidebar-row"><span class="k">TZ</span><span class="v"><?php echo htmlspecialchars($details['timezone']); ?></span></div><?php endif; ?>
                    <?php if (isset($details['temperature'])): ?><div class="ao-sidebar-row"><span class="k">Temp</span><span class="v" style="color: <?php echo $details['temperature'] > 80 ? 'var(--color-red)' : 'var(--text-primary)'; ?>;"><?php echo $details['temperature']; ?>°C</span></div><?php endif; ?>
                    <?php if (!empty($details['smart']) && strpos($details['smart'], 'chybí') === false && $details['smart'] !== 'N/A'): ?><div class="ao-sidebar-row"><span class="k">SMART</span><span class="v" style="color: <?php echo strpos($details['smart'], 'WARNING') !== false ? 'var(--color-red)' : 'var(--color-green)'; ?>;"><?php echo htmlspecialchars($details['smart']); ?></span></div><?php endif; ?>
                    <?php if (!empty($details['reboot_required'])): ?><div class="ao-sidebar-row"><span class="k">Reboot</span><span class="v" style="color: var(--color-yellow);"><i class="fas fa-power-off"></i> Required</span></div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- DNS & RESOLVER SECTION -->
            <?php if (!empty($details['dns_engine']) || !empty($details['dns_servers'])): ?>
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-server"></i> DNS Resolver &amp; Šifrování (DoT / DoH)</div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 0.75rem;">
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                        <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">DNS Engine / Resolver</div>
                        <div style="font-weight: 700; color: var(--text-primary); font-size: 0.95rem; margin-top: 0.2rem;"><i class="fas fa-network-wired" style="color: var(--color-blue, #58a6ff);"></i> <?php echo htmlspecialchars($details['dns_engine'] ?? 'Dnsmasq'); ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                        <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">Protokol / Šifrování</div>
                        <div style="font-weight: 700; color: <?php echo strpos(($details['dns_encryption'] ?? ''), 'DoT') !== false ? 'var(--color-green)' : 'var(--text-primary)'; ?>; font-size: 0.95rem; margin-top: 0.2rem;"><i class="fas fa-shield-alt"></i> <?php echo htmlspecialchars($details['dns_encryption'] ?? 'UDP/53'); ?></div>
                    </div>
                    <?php if (!empty($details['dns_servers'])): ?>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                        <div style="font-size: 0.72rem; color: var(--text-muted); text-transform: uppercase;">Upstream DNS Servery</div>
                        <div style="font-weight: 600; color: var(--text-primary); font-size: 0.8rem; margin-top: 0.2rem; font-family: monospace; word-break: break-all;"><?php echo htmlspecialchars($details['dns_servers']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- TEAMSPEAK PROCESS & VOICE QUALITY SECTION -->
            <?php if (!empty($details['ts3_process']) || $monitor['type'] === 'teamspeak'): ?>
            <?php $ts3_vq = bk_ts3_voice_quality($pdo, $monitor_id); ?>
            <div class="ao-section">
                <div class="ao-section-title"><i class="fas fa-headset"></i> TeamSpeak 3 Služba &amp; Kvalita Hlasu</div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem;">
                    <?php if (!empty($details['ts3_process'])): ?>
                    <?php $p = $details['ts3_process']; ?>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--color-green); text-transform: uppercase; margin-bottom: 0.4rem;"><i class="fas fa-check-circle"></i> Proces ts3server běží</div>
                        <div style="font-size: 0.78rem; display: flex; flex-direction: column; gap: 0.25rem;">
                            <span><strong>PID:</strong> <?php echo (int)($p['pid'] ?? 0); ?></span>
                            <span><strong>CPU:</strong> <?php echo (float)($p['cpu'] ?? 0); ?>%</span>
                            <span><strong>RAM:</strong> <?php echo (float)($p['ram_mb'] ?? 0); ?> MB</span>
                            <span><strong>Vlákna:</strong> <?php echo (int)($p['threads'] ?? 0); ?></span>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.4rem;"><i class="fas fa-info-circle"></i> Proces ts3server nepropojen</div>
                        <div style="font-size: 0.75rem; color: var(--text-muted); line-height: 1.4;"><?php echo htmlspecialchars(t('ts3_process_not_found')); ?></div>
                    </div>
                    <?php endif; ?>

                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem;">
                        <div style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.4rem;"><i class="fas fa-wave-square"></i> Odhad kvality hlasu (Jitter)</div>
                        <div style="font-size: 0.78rem; display: flex; flex-direction: column; gap: 0.25rem;">
                            <?php if ($ts3_vq['band'] !== null): ?>
                                <span><strong>Kvalita:</strong> <strong style="color: var(--color-green);"><?php echo htmlspecialchars($ts3_vq['band']); ?></strong></span>
                                <span><strong>Jitter:</strong> <?php echo $ts3_vq['jitter_ms']; ?> ms</span>
                                <span><strong>Vzorků:</strong> <?php echo $ts3_vq['sample_count']; ?> měření za hodinu</span>
                            <?php else: ?>
                                <span style="color: var(--text-muted);"><i class="fas fa-info-circle"></i> Nedostatek dat pro výpočet jitteru (nyní <?php echo $ts3_vq['sample_count']; ?>/3 měření za poslední hodinu)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- RELATED SERVICES -->
            <?php if (!empty($details['discovered_services']) && is_array($details['discovered_services'])): ?>
            <?php $heavy_op_hours = (int)($details['heavy_op_interval_hours'] ?? 24); ?>
            <div class="ao-section">
                <div class="ao-section-title" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                    <span><i class="fas fa-cubes"></i> <?php echo htmlspecialchars(t('ao_related_services')); ?></span>
                    <span style="font-size: 0.68rem; font-weight: 500; text-transform: none; color: var(--text-muted); background: rgba(255,255,255,0.05); padding: 0.2rem 0.5rem; border-radius: 4px; border: 1px solid rgba(255,255,255,0.08);" data-tooltip="Detekce služeb se spouští 1× za <?php echo $heavy_op_hours; ?>h pro úsporu CPU a paměti.">
                        <i class="fas fa-clock" style="color: var(--color-blue, #58a6ff); margin-right: 0.2rem;"></i> Kontrola 1× za <?php echo $heavy_op_hours; ?>h
                    </span>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 0.65rem;">
                    <?php foreach ($details['discovered_services'] as $svc):
                        $conf = (int)($svc['confidence'] ?? 0);
                        $sc = $conf >= 70 ? 'var(--color-green)' : ($conf >= 40 ? 'var(--color-yellow)' : 'var(--text-secondary)');
                        $svc_desc = $svc['description'] ?? '';
                    ?>
                    <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.07); padding: 0.6rem 0.8rem; border-radius: 8px; font-size: 0.78rem; display: flex; flex-direction: column; gap: 0.3rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <span><i class="fas fa-cube" style="color: <?php echo $sc; ?>;"></i> <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($svc['name'] ?? '?'); ?></strong></span>
                            <span style="color: <?php echo $sc; ?>; font-weight: bold; font-size: 0.7rem; background: rgba(255,255,255,0.04); padding: 0.1rem 0.4rem; border-radius: 4px;"><?php echo $conf; ?>%</span>
                        </div>
                        <?php if (!empty($svc['port'])): ?><div style="font-family: monospace; color: var(--text-muted); font-size: 0.7rem;">Port: :<?php echo (int)$svc['port']; ?></div><?php endif; ?>
                        <?php if ($svc_desc): ?>
                            <div style="font-size: 0.72rem; color: var(--text-secondary); line-height: 1.35; margin-top: 0.1rem;"><?php echo htmlspecialchars($svc_desc); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.ao-main -->

        <!-- SIDEBAR -->
        <div class="ao-sidebar">
            <!-- Quick Actions -->
            <div class="ao-sidebar-title"><?php echo htmlspecialchars(t('ao_quick_actions')); ?></div>
            <?php if ($is_admin): ?>
                <a href="admin.php?edit=<?php echo (int)$monitor['id']; ?>" class="ao-sidebar-btn"><i class="fas fa-pen"></i> <?php echo htmlspecialchars(t('btn_edit')); ?></a>
                <a href="admin.php?restart=<?php echo (int)$monitor['id']; ?>" class="ao-sidebar-btn" onclick="return confirm('Restart monitor?')"><i class="fas fa-rotate"></i> <?php echo htmlspecialchars(t('ao_restart_monitor')); ?></a>
            <?php endif; ?>
            <a href="api.php?action=export_csv&monitor_id=<?php echo (int)$monitor_id; ?>" class="ao-sidebar-btn"><i class="fas fa-download"></i> <?php echo htmlspecialchars(t('ao_export_csv')); ?></a>
            <button type="button" class="ao-sidebar-btn" onclick="navigator.clipboard.writeText(location.href).then(function(){var b=event.target.closest('button');b.innerHTML='<i class=\'fas fa-check\'></i> Zkopírováno!';setTimeout(function(){b.innerHTML='<i class=\'fas fa-link\'></i> Sdílet odkaz'},1500)})"><i class="fas fa-link"></i> Sdílet odkaz</button>
            <a href="index.php" class="ao-sidebar-btn"><i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars(t('breadcrumb_dashboard')); ?></a>

            <!-- Agent info -->
            <?php if (!empty($details['version']) || $last_seen_str): ?>
            <div class="ao-sidebar-title" style="margin-top: 1.25rem;"><?php echo htmlspecialchars(t('ao_agent_info')); ?></div>
            <?php if (!empty($details['version'])): ?>
                <?php $latest_v = bk_get_agent_latest_version($details['agent_type'] ?? ''); $has_update = $latest_v !== null && version_compare($details['version'], $latest_v, '<'); ?>
                <div class="ao-sidebar-row"><span class="k"><?php echo htmlspecialchars(t('ao_version')); ?></span><span class="v"><?php echo htmlspecialchars($details['version']); ?><?php if ($has_update): ?> <span style="color: var(--color-yellow); font-size: 0.65rem;">→ <?php echo htmlspecialchars($latest_v); ?></span><?php endif; ?></span></div>
            <?php endif; ?>
            <?php if ($last_seen_str): ?><div class="ao-sidebar-row"><span class="k"><?php echo htmlspecialchars(t('ao_last_seen')); ?></span><span class="v"><?php echo htmlspecialchars($last_seen_str); ?></span></div><?php endif; ?>
            <?php if (!empty($details['agent_type'])): ?><div class="ao-sidebar-row"><span class="k">Type</span><span class="v"><?php echo htmlspecialchars($details['agent_type']); ?></span></div><?php endif; ?>
            <?php endif; ?>

            <!-- Asset siblings -->
            <?php if (!empty($asset_siblings)): ?>
            <div class="ao-sidebar-title" style="margin-top: 1.25rem;"><?php echo htmlspecialchars(t('mp_asset_siblings')); ?></div>
            <?php foreach ($asset_siblings as $sib):
                $sib_color = ['up' => 'var(--color-green)', 'down' => 'var(--color-red)', 'maintenance' => 'var(--color-yellow)'][$sib['status']] ?? 'var(--text-muted)';
            ?>
                <a href="monitor.php?id=<?php echo (int)$sib['id']; ?>" class="ao-sidebar-btn" style="text-align: left; display: flex; align-items: center; gap: 0.4rem;">
                    <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $sib_color; ?>;flex-shrink:0;"></span>
                    <?php echo htmlspecialchars($sib['name']); ?>
                </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div><!-- /.ao-layout -->

</div>

<script>
(function () {
    var monitorId = <?php echo (int)$monitor_id; ?>;
    var chartConfigs = <?php echo json_encode($chart_configs); ?>;
    var charts = {};
    var currentPeriod = '24h';
    var isDark = localStorage.getItem('theme') !== 'light';
    var colors = ['#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de', '#3ba272', '#fc8452', '#9a60b4'];

    function initCharts() {
        chartConfigs.forEach(function (cfg) {
            var el = document.getElementById(cfg.id);
            if (el) charts[cfg.id] = echarts.init(el, isDark ? 'dark' : null);
        });
        // Synchronized crosshairs via tooltip events (NOT echarts.connect,
        // which also syncs dataZoom and makes independent zoom impossible).
        var chartIds = Object.keys(charts);
        if (chartIds.length > 1) {
            chartIds.forEach(function (id) {
                charts[id].on('updateAxisPointer', function (event) {
                    var xAxisInfo = event.axesInfo && event.axesInfo[0];
                    if (!xAxisInfo) return;
                    var time = xAxisInfo.value;
                    chartIds.forEach(function (otherId) {
                        if (otherId === id) return;
                        charts[otherId].dispatchAction({ type: 'showTip', xAxisIndex: 0, dataIndex: 0, position: [xAxisInfo.value, 0] });
                    });
                });
            });
        }
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
                        tooltip: { trigger: 'axis', axisPointer: { type: 'cross', label: { backgroundColor: '#333' } }, valueFormatter: function (v) { return v !== null ? v + ' ms' : '—'; } },
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
                tooltip: { trigger: 'axis', axisPointer: { type: 'cross', label: { backgroundColor: '#333' } }, valueFormatter: function (v) { return v !== null && v !== undefined ? v + ' ' + unit : '—'; } },
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

    // Auto-refresh every 60s
    setInterval(function () { loadAllCharts(currentPeriod); }, 60000);
})();
</script>
</body>
</html>
