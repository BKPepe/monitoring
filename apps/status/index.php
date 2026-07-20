<?php
/**
 * Veřejný status dashboard (Blood Kings Status)
 */

require_once __DIR__ . '/functions.php';

$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Získání celkových statistik
$stmt_stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
        SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_count,
        MAX(last_checked) as last_checked
    FROM monitors
");
$stats = $stmt_stats->fetch();

$total_monitors = (int)($stats['total'] ?? 0);
$up_monitors = (int)($stats['up_count'] ?? 0);
$down_monitors = (int)($stats['down_count'] ?? 0);
$maintenance_monitors_count = (int)($stats['maintenance_count'] ?? 0);
$last_checked_global = $stats['last_checked'] ?? null;

// Načtení všech monitorů
$stmt_monitors = $pdo->query("SELECT * FROM monitors ORDER BY category, name");
$monitors = $stmt_monitors->fetchAll();

// Seskupení monitorů podle kategorií
$categories = [];
foreach ($monitors as $m) {
    $cat = $m['category'] ?: 'Ostatní';
    $categories[$cat][] = $m;
}

// Načtení 30denní historie pro vizualizaci sloupců (HetrixTools styl)
$past_30_days = [];
for ($i = 29; $i >= 0; $i--) {
    $past_30_days[] = date('Y-m-d', strtotime("-$i days"));
}

// Agregace nad monitor_logs jsou nejdražší dotazy na stránce a data se mění
// jen s během cronu - výsledek se proto drží v souborové cache (TTL 60 s),
// aby nápor návštěvníků nespouštěl 30denní GROUP BY při každém requestu.
$bk_cache_dir = __DIR__ . '/cache';
$bk_cache_file = $bk_cache_dir . '/dashboard_agg.json';
$bk_agg = null;
if (is_readable($bk_cache_file) && (time() - (int)@filemtime($bk_cache_file)) < 60) {
    $bk_agg = json_decode((string)@file_get_contents($bk_cache_file), true);
}

if (!is_array($bk_agg) || !isset($bk_agg['uptime_pct'], $bk_agg['history_data'], $bk_agg['history_uptime'], $bk_agg['incidents'], $bk_agg['regions'])) {
    // Výpočet 30-denního uptime procenta pro každý monitor
    $stmt_upt = $pdo->query("
        SELECT monitor_id,
               SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN status != 'maintenance' THEN 1 ELSE 0 END) as total_count
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY monitor_id
    ");
    $uptime_pct = [];
    while ($row = $stmt_upt->fetch()) {
        $uptime_pct[$row['monitor_id']] = $row['total_count'] > 0
            ? round(($row['up_count'] / $row['total_count']) * 100, 2)
            : 100.00;
    }

    $stmt_hist = $pdo->query("
        SELECT monitor_id, DATE(checked_at) as log_date,
               SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count,
               SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_count,
               SUM(CASE WHEN status != 'maintenance' THEN 1 ELSE 0 END) as total_count
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY monitor_id, DATE(checked_at)
    ");
    $history_data = [];
    $history_uptime = [];
    while ($row = $stmt_hist->fetch()) {
        $mid = $row['monitor_id'];
        $date = $row['log_date'];
        if ($row['down_count'] > 0) {
            $history_data[$mid][$date] = 'down';
        } elseif ($row['maintenance_count'] == $row['total_count'] && $row['total_count'] > 0) {
            $history_data[$mid][$date] = 'maintenance';
        } elseif ($row['total_count'] > 0) {
            $history_data[$mid][$date] = 'up';
        } else {
            $history_data[$mid][$date] = 'nodata';
        }

        $history_uptime[$mid][$date] = $row['total_count'] > 0
            ? round(($row['up_count'] / $row['total_count']) * 100, 2)
            : 100.00;
    }

    // Načtení posledních incidentů – pouze výpadky a zprávy při návratu (ne každý úspěšný ping)
    $stmt_inc = $pdo->query("
        SELECT l.*, m.name, m.type, m.target
        FROM monitor_logs l
        JOIN monitors m ON l.monitor_id = m.id
        WHERE l.status = 'down'
           OR (l.status = 'up' AND l.error_message IS NOT NULL AND l.error_message != '')
        ORDER BY l.checked_at DESC
        LIMIT 20
    ");
    $incidents = $stmt_inc->fetchAll();

    // Distribuovaní agenti/uzly, kteří v posledních 24h hlásili měření (veřejná
    // "Global Agent Map" - stejná logika jako admin diagnostika, bez citlivých detailů)
    $stmt_regions = $pdo->query("
        SELECT checked_from, COUNT(*) as cnt, MAX(checked_at) as last_seen,
               ROUND(AVG(response_time)) as avg_latency
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND checked_from IS NOT NULL
        GROUP BY checked_from
        ORDER BY last_seen DESC
        LIMIT 24
    ");
    $regions = $stmt_regions->fetchAll();

    $bk_agg = [
        'uptime_pct' => $uptime_pct,
        'history_data' => $history_data,
        'history_uptime' => $history_uptime,
        'incidents' => $incidents,
        'regions' => $regions,
    ];

    if (!is_dir($bk_cache_dir)) {
        @mkdir($bk_cache_dir, 0775, true);
        @file_put_contents($bk_cache_dir . '/.htaccess', "Require all denied\n");
    }
    // Atomický zápis, aby souběžné requesty nečetly rozepsaný soubor
    $bk_tmp = $bk_cache_file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($bk_tmp, json_encode($bk_agg, JSON_UNESCAPED_UNICODE)) !== false) {
        @rename($bk_tmp, $bk_cache_file);
    }
} else {
    $uptime_pct = $bk_agg['uptime_pct'];
    $history_data = $bk_agg['history_data'];
    $history_uptime = $bk_agg['history_uptime'];
    $incidents = $bk_agg['incidents'];
    $regions = $bk_agg['regions'];
}

// Celková průměrná 30denní dostupnost napříč všemi monitory
$avg_uptime = !empty($uptime_pct) ? round(array_sum($uptime_pct) / count($uptime_pct), 2) : 100.00;

$site_title = get_setting('site_title', 'Blood Kings');

// Vlastní branding z nastavení administrace
$custom_logo_url = trim(get_setting('custom_logo_url'));
$custom_color = trim(get_setting('custom_color_theme'));
// Barvu vkládáme do <style>, proto povolíme jen validní hex zápis
if (!preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $custom_color)) {
    $custom_color = '';
}
$custom_nav_links = json_decode(get_setting('custom_nav_links'), true);
if (!is_array($custom_nav_links)) {
    $custom_nav_links = [];
}

/**
 * Returns HTML icon for a given monitor type and target URL.
 * For web monitors, tries to load favicon via Google's favicon API.
 */
function monitor_type_icon(string $type, string $target = '', string $size = '1.1rem'): string {
    switch ($type) {
        case 'discord':
            return '<i class="fab fa-discord" style="color:#5865f2;font-size:'.$size.';" title="Discord"></i>';
        case 'minecraft':
            return '<img src="https://www.google.com/s2/favicons?sz=32&domain=minecraft.net"
                        width="16" height="16" style="border-radius:3px;vertical-align:middle;"
                        onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\'"
                        title="Minecraft">
                    <i class="fas fa-cubes" style="display:none;color:#5e8b4d;font-size:'.$size.';" title="Minecraft"></i>';
        case 'teamspeak':
            return '<i class="fas fa-headset" style="color:#5bb5e5;font-size:'.$size.';" title="TeamSpeak"></i>';
        case 'vps':
            return '<i class="fas fa-server" style="color:#a78bfa;font-size:'.$size.';" title="VPS"></i>';
        case 'cpanel':
            return '<i class="fas fa-server" style="color:#0f9f90;font-size:'.$size.';" title="cPanel Hosting"></i>';
        case 'port':
            return '<i class="fas fa-network-wired" style="color:#60a5fa;font-size:'.$size.';" title="Port"></i>';
        case 'web':
        default:
            // Extract domain for favicon lookup
            $domain = '';
            if ($target) {
                $parsed = parse_url($target);
                $domain = $parsed['host'] ?? $target;
            }
            if ($domain) {
                return '<img src="https://www.google.com/s2/favicons?sz=32&domain='.htmlspecialchars($domain).'"
                            width="16" height="16" style="border-radius:3px;vertical-align:middle;"
                            onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\'"
                            title="'.htmlspecialchars($domain).'">
                        <i class="fas fa-globe" style="display:none;color:#34d399;font-size:'.$size.';" title="Web"></i>';
            }
            return '<i class="fas fa-globe" style="color:#34d399;font-size:'.$size.';" title="Web"></i>';
    }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <title><?php echo htmlspecialchars($site_title); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime('assets/style.css'); ?>">
    <?php if ($custom_color !== ''): ?>
    <style>:root { --color-red: <?php echo $custom_color; ?>; }</style>
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js"></script>
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-theme');
        }
    </script>
</head>
<body>

    <!-- Header -->
    <header>
        <div class="container header-wrapper">
            <a href="../index.html" class="logo">
                <?php if ($custom_logo_url !== ''): ?>
                    <img src="<?php echo htmlspecialchars($custom_logo_url); ?>" alt="<?php echo htmlspecialchars($site_title); ?>" style="height: 28px; vertical-align: middle;">
                    <span><?php echo htmlspecialchars($site_title); ?></span>
                <?php else: ?>
                    <i class="fas fa-gamepad" style="color: var(--color-red);"></i> Blood Kings <span>Status</span>
                <?php endif; ?>
            </a>
            <div class="nav-links">
                <a href="../index.html"><i class="fas fa-home"></i> Portál</a>
                <a href="index.php" class="active"><i class="fas fa-chart-line"></i> Monitoring</a>
                <?php foreach ($custom_nav_links as $nav_link):
                    $nl_name = trim((string)($nav_link['name'] ?? ''));
                    $nl_url = trim((string)($nav_link['url'] ?? ''));
                    if ($nl_name === '' || !preg_match('#^https?://#i', $nl_url)) continue;
                ?>
                    <a href="<?php echo htmlspecialchars($nl_url); ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> <?php echo htmlspecialchars($nl_name); ?></a>
                <?php endforeach; ?>
                <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                    <a href="admin.php" class="btn btn-secondary btn-sm" style="background: rgba(30, 199, 115, 0.1); border: 1px solid rgba(30, 199, 115, 0.3); color: var(--color-green);"><i class="fas fa-user-shield"></i> Administrace (<?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>)</a>
                    <a href="admin.php?action=logout" class="btn btn-secondary btn-sm" style="background: rgba(230, 57, 70, 0.1); border: 1px solid rgba(230, 57, 70, 0.3); color: var(--color-red);" onclick="return confirm('Opravdu se chcete odhlásit?')"><i class="fas fa-sign-out-alt"></i> Odhlásit</a>
                <?php else: ?>
                    <a href="admin.php" class="btn btn-secondary btn-sm"><i class="fas fa-lock"></i> Admin</a>
                <?php endif; ?>
                <button id="theme-toggle" class="btn btn-secondary btn-sm" style="padding: 0.4rem 0.6rem; margin-left: 0.25rem; clip-path: none; border-radius: 4px;" title="Přepnout tmavý/světlý motiv"><i class="fas fa-sun"></i></button>
            </div>
        </div>
    </header>

    <div class="container">
        
        <!-- Celkový stav systému -->
        <?php
        $hero_class = 'all-ok';
        if ($down_monitors > 0) {
            $hero_class = '';
        } elseif ($maintenance_monitors_count > 0) {
            $hero_class = 'has-maintenance';
        }
        ?>
        <div class="overall-status <?php echo $hero_class; ?>">
            <div class="overall-info">
                <?php if ($down_monitors > 0): ?>
                    <h2><i class="fas fa-exclamation-triangle" style="color: var(--color-red);"></i> Některé systémy vykazují výpadek</h2>
                    <p>Detekovali jsme potíže u <?php echo $down_monitors; ?> z <?php echo $total_monitors; ?> sledovaných služeb.</p>
                <?php elseif ($maintenance_monitors_count > 0): ?>
                    <h2><i class="fas fa-tools" style="color: var(--color-yellow, #f39c12);"></i> Systémy běží, probíhá plánovaná údržba</h2>
                    <p>U <?php echo $maintenance_monitors_count; ?> služeb právě probíhá plánovaná údržba, ostatní běží bez problémů.</p>
                <?php else: ?>
                    <h2><i class="fas fa-check-circle" style="color: var(--color-green);"></i> Všechny systémy jsou online</h2>
                    <p>Všech <?php echo $total_monitors; ?> sledovaných služeb a serverů běží bez jakýchkoliv problémů.</p>
                <?php endif; ?>
                <?php if ($last_checked_global): ?>
                    <p class="last-update-line"><i class="fas fa-clock"></i> Poslední kontrola: <?php echo date('d.m.Y H:i:s', strtotime($last_checked_global)); ?></p>
                <?php endif; ?>
            </div>

            <div class="overall-stats">
                <div class="stat-item">
                    <div class="stat-value up"><?php echo $up_monitors; ?></div>
                    <div class="stat-label">Online</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value down"><?php echo $down_monitors; ?></div>
                    <div class="stat-label">Výpadek</div>
                </div>
                <?php if ($maintenance_monitors_count > 0): ?>
                <div class="stat-item">
                    <div class="stat-value warn"><?php echo $maintenance_monitors_count; ?></div>
                    <div class="stat-label">Údržba</div>
                </div>
                <?php endif; ?>
                <div class="stat-item">
                    <div class="stat-value <?php echo $avg_uptime >= 99 ? 'up' : ($avg_uptime >= 95 ? 'warn' : 'down'); ?>"><?php echo number_format($avg_uptime, 2, ',', ' '); ?>%</div>
                    <div class="stat-label">Dostupnost 30 dní</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value total"><?php echo $total_monitors; ?></div>
                    <div class="stat-label">Celkem</div>
                </div>
            </div>
        </div>

        <?php 
        $maintenance_monitors = [];
        foreach ($monitors as $m) {
            if ($m['status'] === 'maintenance' && is_in_maintenance($m)) {
                $maintenance_monitors[] = $m;
            }
        }
        if (!empty($maintenance_monitors)):
        ?>
            <div class="maintenance-banner" style="background: rgba(243, 156, 18, 0.1); border: 1px solid rgba(243, 156, 18, 0.2); border-left: 4px solid #f39c12; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <div style="font-weight: bold; color: #f39c12; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem; font-size: 0.95rem;">
                    <i class="fas fa-tools"></i> Probíhá plánovaná údržba
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($maintenance_monitors as $mm): 
                        $desc = $mm['maintenance_description'] ?: 'Údržba a optimalizace systému';
                        $time_str = '';
                        if (!empty($mm['maintenance_start']) && !empty($mm['maintenance_end'])) {
                            $time_str = ' (od ' . date('d.m.Y H:i', strtotime($mm['maintenance_start'])) . ' do ' . date('d.m.Y H:i', strtotime($mm['maintenance_end'])) . ')';
                        }
                    ?>
                        <div style="font-size: 0.85rem; color: #e1e1e6;">
                            <strong><?php echo htmlspecialchars($mm['name']); ?></strong>: <?php echo htmlspecialchars($desc); ?><span style="color: #a0a0b0; font-size: 0.82rem; margin-left: 0.35rem; font-weight: 500;"><?php echo $time_str; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Global Agent Map - přehled distribuovaných měřicích uzlů/agentů -->
        <?php if (!empty($regions)): ?>
            <section class="regions-section">
                <h2 class="category-title"><i class="fas fa-satellite-dish"></i> Distribuovaní agenti</h2>
                <div class="regions-grid">
                    <?php foreach ($regions as $rg):
                        $r_diff_min = round((time() - strtotime($rg['last_seen'])) / 60);
                        if ($r_diff_min < 15) {
                            $r_state = 'online'; $r_label = 'Online';
                        } elseif ($r_diff_min < 60) {
                            $r_state = 'warn'; $r_label = 'Zpožděno';
                        } else {
                            $r_state = 'offline'; $r_label = 'Neaktivní';
                        }
                        $r_ago = $r_diff_min < 2 ? 'právě teď' : ($r_diff_min < 60 ? "před {$r_diff_min} min" : 'před ' . round($r_diff_min / 60) . ' hod');
                    ?>
                        <div class="region-card region-<?php echo $r_state; ?>">
                            <div class="region-dot"></div>
                            <div class="region-info">
                                <div class="region-name"><?php echo htmlspecialchars($rg['checked_from']); ?></div>
                                <div class="region-meta">
                                    <?php echo $r_label; ?> · <?php echo $r_ago; ?>
                                    <?php if ($rg['avg_latency'] !== null): ?>
                                        · <?php echo (int)$rg['avg_latency']; ?>&nbsp;ms
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Sekce s kategoriemi a monitory -->
        <?php if (empty($categories)): ?>
            <div class="admin-card" style="text-align: center; padding: 3rem;">
                <i class="fas fa-info-circle" style="font-size: 3rem; color: var(--text-muted); margin-bottom: 1rem;"></i>
                <h2>Zatím nebyly přidány žádné servery</h2>
                <p>Přihlaste se do administrace a přidejte své první servery k monitorování.</p>
                <a href="admin.php" class="btn" style="margin-top: 1.5rem;"><i class="fas fa-plus"></i> Přejít do administrace</a>
            </div>
        <?php else: ?>
            
            <?php foreach ($categories as $category_name => $monitor_list): ?>
                <section class="category-section">
                    <h2 class="category-title">
                        <?php
                        // Zobrazení odpovídající ikony podle názvu kategorie
                        $icon = 'fa-server';
                        $low_cat = mb_strtolower($category_name);
                        if (strpos($low_cat, 'web') !== false) $icon = 'fa-globe';
                        elseif (strpos($low_cat, 'vps') !== false || strpos($low_cat, 'server') !== false) $icon = 'fa-hdd';
                        elseif (strpos($low_cat, 'hra') !== false || strpos($low_cat, 'game') !== false) $icon = 'fa-gamepad';
                        elseif (strpos($low_cat, 'komunit') !== false || strpos($low_cat, 'social') !== false) $icon = 'fa-users';
                        ?>
                        <i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars($category_name); ?>
                    </h2>
                    
                    <div class="monitors-grid">
                        <?php foreach ($monitor_list as $monitor): 
                            $mid = $monitor['id'];
                            $status = $monitor['status'];
                            if ($status === 'maintenance' && !is_in_maintenance($monitor)) {
                                $status = 'unknown';
                            }
                            $m_type = $monitor['type'];
                            $uptime = $uptime_pct[$mid] ?? 100.00;
                            $details = $monitor['last_details'] ? json_decode($monitor['last_details'], true) : null;
                            $is_expandable = true;
                            
                            // Třída pro barvu uptime textu
                            $uptime_class = 'up';
                            if ($uptime < 95) $uptime_class = 'down';
                            elseif ($uptime < 99) $uptime_class = 'warn';
                        ?>
                            <div class="monitor-item" id="monitor-item-<?php echo $mid; ?>">
                                <div class="monitor-card" <?php if ($is_expandable): ?>onclick="toggleDetails(<?php echo $mid; ?>)"<?php endif; ?>>
                                    
                                    <!-- Sloupec 1: Název a typ -->
                                    <div class="monitor-info">
                                        <div class="status-dot <?php echo $status; ?>"></div>
                                        <div class="monitor-details">
                                            <h3 style="display:flex;align-items:center;gap:0.45rem;flex-wrap:wrap;">
                                                <?php echo monitor_type_icon($m_type, $monitor['target']); ?>
                                                <?php echo htmlspecialchars($monitor['name']); ?>
                                                <?php if ($status === 'maintenance'): ?>
                                                    <span style="background: rgba(243, 156, 18, 0.15); border: 1px solid rgba(243, 156, 18, 0.25); color: #f39c12; font-size: 0.65rem; padding: 0.15rem 0.4rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem; font-weight: bold; text-transform: uppercase;" title="Na tomto serveru právě probíhá plánovaná údržba."><i class="fas fa-tools"></i> Údržba</span>
                                                <?php endif; ?>
                                            </h3>
                                            <span>
                                                <?php 
                                                if ($m_type === 'discord') {
                                                    echo 'Discord Server';
                                                } elseif ($m_type === 'teamspeak') {
                                                    $ts_host = $monitor['target'];
                                                    $ts_voice_port = 9987;
                                                    $parts = explode(':', $ts_host);
                                                    if (count($parts) === 2) {
                                                        $ts_host = $parts[0];
                                                        $ts_voice_port = intval($parts[1]);
                                                    }
                                                    echo '<a href="ts3server://' . htmlspecialchars($ts_host) . '?port=' . htmlspecialchars($ts_voice_port) . '" class="ts-connect-link" title="Kliknutím se připojíte přímo na TeamSpeak server"><i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-right: 0.25rem;"></i> ' . htmlspecialchars($monitor['target']) . '</a>';
                                                } else {
                                                    echo htmlspecialchars($monitor['target']) . ($monitor['port'] ? ':'.$monitor['port'] : ''); 
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Sloupec 2: Typ badge -->
                                    <div>
                                        <div class="monitor-type-badge"><?php echo htmlspecialchars($m_type); ?></div>
                                        
                                        <!-- Speciální doplňkové texty pod typem (hráči u her, zátěž u VPS) -->
                                        <?php if ($status === 'up' && $details): ?>
                                            <div class="game-info" style="margin-top: 0.25rem;">
                                                <?php if ($m_type === 'minecraft'): ?>
                                                    <span title="Minecraft verze: <?php echo htmlspecialchars($details['version'] ?? ''); ?>">
                                                        <i class="fas fa-users"></i> <?php echo (int)($details['players_online'] ?? 0); ?> / <?php echo (int)($details['players_max'] ?? 0); ?>
                                                    </span>
                                                <?php elseif ($m_type === 'teamspeak'): ?>
                                                    <span title="Klienti online (mimo boty)<?php echo !empty($details['ip_version']) ? ' - Měřeno přes ' . $details['ip_version'] : ''; ?>">
                                                        <i class="fas fa-headset"></i> <?php echo (int)($details['clients_online'] ?? 0); ?> / <?php echo (int)($details['clients_max'] ?? 0); ?>
                                                        <?php if (!empty($details['ip_version'])): ?>
                                                            <small style="font-size: 0.65rem; color: var(--text-muted); margin-left: 0.25rem;">(<?php echo htmlspecialchars($details['ip_version']); ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php elseif ($m_type === 'discord'): ?>
                                                    <span>
                                                        <i class="fab fa-discord"></i> <?php echo (int)($details['presence_count'] ?? 0); ?> online
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Sloupec 3: Historie a grafy (HetrixTools styl nebo VPS grafy) -->
                                    <div>
                                        <?php if (($m_type === 'vps' || $m_type === 'cpanel') && $status === 'up' && (isset($details['cpu']) || isset($details['disk']))): ?>
                                            <!-- Pokud jde o VPS nebo cPanel, ukážeme rychlé grafy vytížení -->
                                            <div class="metrics-charts">
                                                <?php if ($m_type === 'vps'): ?>
                                                    <div class="mini-chart">
                                                        <div class="chart-title">CPU</div>
                                                        <div class="chart-bar-container">
                                                            <?php 
                                                            $cpu_val = $details['cpu'];
                                                            $cpu_color = ($cpu_val > 80) ? 'red' : (($cpu_val > 50) ? 'yellow' : 'green');
                                                            ?>
                                                            <div class="chart-bar-fill <?php echo $cpu_color; ?>" style="width: <?php echo $cpu_val; ?>%"></div>
                                                        </div>
                                                        <div class="chart-value"><?php echo $cpu_val; ?>%</div>
                                                    </div>
                                                <?php else: // cpanel processes/CPU ?>
                                                    <div class="mini-chart">
                                                        <div class="chart-title">Procesy</div>
                                                        <div class="chart-bar-container">
                                                            <?php 
                                                            $proc_val = $details['processes']['percent'] ?? 0;
                                                            $proc_color = ($proc_val > 80) ? 'red' : (($proc_val > 50) ? 'yellow' : 'green');
                                                            ?>
                                                            <div class="chart-bar-fill <?php echo $proc_color; ?>" style="width: <?php echo $proc_val; ?>%"></div>
                                                        </div>
                                                        <div class="chart-value"><?php echo $proc_val; ?>%</div>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="mini-chart">
                                                    <div class="chart-title">RAM</div>
                                                    <div class="chart-bar-container">
                                                        <?php 
                                                        $ram_val = $m_type === 'vps' ? $details['ram'] : ($details['memory']['percent'] ?? 0);
                                                        $ram_color = ($ram_val > 85) ? 'red' : (($ram_val > 60) ? 'yellow' : 'green');
                                                        ?>
                                                        <div class="chart-bar-fill <?php echo $ram_color; ?>" style="width: <?php echo $ram_val; ?>%"></div>
                                                    </div>
                                                    <div class="chart-value"><?php echo $ram_val; ?>%</div>
                                                </div>
                                                
                                                <div class="mini-chart">
                                                    <div class="chart-title">DISK</div>
                                                    <div class="chart-bar-container">
                                                        <?php 
                                                        $hdd_val = $m_type === 'vps' ? $details['hdd'] : ($details['disk']['percent'] ?? 0);
                                                        $hdd_color = ($hdd_val > 90) ? 'red' : (($hdd_val > 70) ? 'yellow' : 'green');
                                                        ?>
                                                        <div class="chart-bar-fill <?php echo $hdd_color; ?>" style="width: <?php echo $hdd_val; ?>%"></div>
                                                    </div>
                                                    <div class="chart-value"><?php echo $hdd_val; ?>%</div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <!-- Standardní 30denní historie sloupců -->
                                            <div class="uptime-history">
                                                <div class="history-bar">
                                                    <?php foreach ($past_30_days as $day): 
                                                         $day_status = $history_data[$mid][$day] ?? 'nodata';
                                                         $date_formatted = date('d.m.Y', strtotime($day));
                                                         
                                                         if ($day_status === 'up') {
                                                             $day_class = 'up';
                                                             $day_text = 'Bez výpadků';
                                                         } elseif ($day_status === 'down') {
                                                             $day_class = 'down';
                                                             $day_uptime = $history_uptime[$mid][$day] ?? 100.00;
                                                             $day_text = 'Detekován výpadek (dostupnost ' . number_format($day_uptime, 2, ',', ' ') . ' %)';
                                                         } elseif ($day_status === 'maintenance') {
                                                             $day_class = 'maintenance';
                                                             $day_text = 'Plánovaná údržba';
                                                         } else {
                                                             $day_class = 'nodata';
                                                             $day_text = 'Žádná data';
                                                         }
                                                         
                                                         $tooltip = "$date_formatted: $day_text";
                                                     ?>
                                                         <div class="history-day <?php echo $day_class; ?>" data-tooltip="<?php echo $tooltip; ?>"></div>
                                                     <?php endforeach; ?>
                                                </div>
                                                <div class="history-labels">
                                                    <span>před 30 dny</span>
                                                    <span>dnes</span>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Sloupec 4: Uptime, Odezva a Chevron -->
                                    <div class="monitor-meta" style="display: flex; align-items: center; justify-content: flex-end; gap: 1rem;">
                                        <div style="text-align: right;">
                                            <div class="uptime-pct <?php echo $uptime_class; ?>"><?php echo number_format($uptime, 2, ',', ' '); ?>%</div>
                                            <div class="resp-time">
                                                <?php 
                                                if ($status === 'down') {
                                                    echo '<span style="color: var(--color-red);">Nedostupný</span>';
                                                } elseif ($status === 'maintenance') {
                                                    echo '<span style="color: var(--color-yellow);">Údržba</span>';
                                                } elseif ($m_type === 'vps') {
                                                    echo '<span style="color: var(--color-green);">Online</span>';
                                                } else {
                                                    // Zobrazíme odezvu z posledního logu
                                                    $stmt_last_log = $pdo->prepare("SELECT response_time FROM monitor_logs WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 1");
                                                    $stmt_last_log->execute([$mid]);
                                                    $last_log = $stmt_last_log->fetch();
                                                    if ($last_log && $last_log['response_time'] > 0) {
                                                        echo $last_log['response_time'] . ' ms';
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <?php if ($is_expandable): ?>
                                            <i class="fas fa-chevron-down expand-indicator"></i>
                                        <?php endif; ?>
                                    </div>

                                </div>

                                <!-- Collapsible panel pro detaily -->
                                <?php if ($is_expandable): ?>
                                    <div class="monitor-details-panel" id="details-panel-<?php echo $mid; ?>">
                                        <div class="details-content-inner">
                                            <?php
                                            // Načtení posledních logů pro historii a výpočet frekvence (zabraňuje duplicitním SQL dotazům)
                                            $stmt_last_logs = $pdo->prepare("SELECT checked_at, status, response_time, error_message, checked_from FROM monitor_logs WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 5");
                                            $stmt_last_logs->execute([$mid]);
                                            $last_logs = $stmt_last_logs->fetchAll();
                                            
                                            $stmt_outages = $pdo->prepare("SELECT checked_at, error_message, checked_from FROM monitor_logs WHERE monitor_id = ? AND status = 'down' AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY checked_at DESC LIMIT 5");
                                            $stmt_outages->execute([$mid]);
                                            $monitor_outages = $stmt_outages->fetchAll();
                                            
                                            $freq_text = 'N/A';
                                            if (count($last_logs) >= 2) {
                                                $t1 = strtotime($last_logs[0]['checked_at']);
                                                $t2 = strtotime($last_logs[1]['checked_at']);
                                                $diff_sec = abs($t1 - $t2);
                                                if ($diff_sec > 0) {
                                                    $mins = round($diff_sec / 60);
                                                    if ($mins < 1) {
                                                        $freq_text = $diff_sec . ' s';
                                                    } else {
                                                        $freq_text = $mins . ' min';
                                                    }
                                                }
                                            }
                                            $agent_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['SCRIPT_NAME']) . "/agent.py";
                                            $agent_url = str_replace('\\', '/', $agent_url); // Normalize paths for Windows/Mac
                                            $agent_sh_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['SCRIPT_NAME']) . "/agent.sh";
                                            $agent_sh_url = str_replace('\\', '/', $agent_sh_url); // Normalize paths for Windows/Mac
                                            ?>
                                            
                                            <?php if (!empty($monitor['maintenance_start']) && !empty($monitor['maintenance_end']) && strtotime($monitor['maintenance_end']) > time()): ?>
                                                <div style="background: rgba(243, 156, 18, 0.1); border-left: 4px solid #f39c12; padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1.25rem; font-size: 0.82rem; border: 1px solid rgba(243, 156, 18, 0.15);">
                                                    <strong style="color: #f39c12; display: flex; align-items: center; gap: 0.4rem; font-size: 0.88rem; margin-bottom: 0.35rem;">
                                                        <i class="fas fa-tools"></i> Plánovaná údržba (Maintenance)
                                                    </strong>
                                                    <div style="color: #e1e1e6; margin-bottom: 0.35rem;">
                                                        <strong>Doba trvání:</strong> od <?php echo date('d.m.Y H:i', strtotime($monitor['maintenance_start'])); ?> do <?php echo date('d.m.Y H:i', strtotime($monitor['maintenance_end'])); ?>
                                                    </div>
                                                    <?php if (!empty($monitor['maintenance_description'])): ?>
                                                        <div style="color: var(--text-secondary); line-height: 1.4;"><?php echo htmlspecialchars($monitor['maintenance_description']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($m_type === 'minecraft'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> Informace o serveru</div>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Verze:</strong> <span style="color: #fff;"><?php echo htmlspecialchars($details['version'] ?? 'Neznámá'); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Popis (MOTD):</strong> <span style="color: #fff; font-style: italic; font-weight: 500;"><?php echo htmlspecialchars($details['motd'] ?? 'Žádný popis'); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Frekvence měření:</strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Poslední kontrola:</strong> <span class="stat-val"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : 'Nikdy'; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Poslední změna stavu:</strong> <span class="stat-val"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                        <p><strong>Uptime (30 dní):</strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight:bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                        <?php 
                                                        $mc_ll = $last_logs[0] ?? null;
                                                        if ($mc_ll && $mc_ll['checked_from']): 
                                                        ?>
                                                        <p style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-muted);"><i class="fas fa-map-marker-alt" style="color:var(--color-red);font-size:0.7rem;"></i> Měřeno z: <strong><?php echo htmlspecialchars($mc_ll['checked_from']); ?></strong></p>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (isset($details['cpu'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-server"></i> Zátěž serveru (VPS Agent)</div>
                                                            <?php echo render_vps_agent_details($details, $monitor); ?>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> Propojený VPS Agent</div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;">Klíč agenta</div>
                                                                <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                
                                                                <button class="btn-install-agent" onclick="toggleAgentInstructions(<?php echo $mid; ?>)" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.35rem 0.6rem; border-radius: 4px; font-size: 0.7rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s; width: 100%; justify-content: center;">
                                                                    <i class="fas fa-terminal"></i> Návod k instalaci agenta
                                                                </button>
                                                                <div id="agent-instructions-<?php echo $mid; ?>" style="display: none; margin-top: 0.75rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 0.85rem; border-radius: 6px; font-size: 0.72rem; line-height: 1.45; max-width: 650px;">
                                                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                                                        <div>
                                                                            <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fab fa-python"></i> Python 3 Agent</strong>
                                                                            <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.py <?php echo htmlspecialchars($agent_url); ?></code></li>
                                                                                <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                <li>Povolte: <code>chmod +x agent.py</code></li>
                                                                                <li>Cron: <code>*/5 * * * * /cesta/agent.py</code></li>
                                                                            </ol>
                                                                        </div>
                                                                        <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 1.25rem;">
                                                                            <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fas fa-terminal"></i> Shell Agent (BASH)</strong>
                                                                            <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.sh <?php echo htmlspecialchars($agent_sh_url); ?></code></li>
                                                                                <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                <li>Povolte: <code>chmod +x agent.sh</code></li>
                                                                                <li>Cron: <code>*/5 * * * * /cesta/agent.sh</code></li>
                                                                            </ol>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>

                                                    </div>
                                                    <div>
                                                        <div class="detail-section-title">
                                                            <span><i class="fas fa-users"></i> Online hráči</span>
                                                            <span class="category-badge"><?php echo count($details['players_list'] ?? []); ?> online</span>
                                                        </div>
                                                        <?php if (empty($details['players_list'])): ?>
                                                            <p style="color: var(--text-muted); font-style: italic;">Právě zde nejsou žádní online hráči.</p>
                                                        <?php else: ?>
                                                            <div class="players-badge-grid">
                                                                <?php foreach ($details['players_list'] as $player): ?>
                                                                    <span class="player-badge"><i class="fas fa-user" style="font-size: 0.7rem; color: var(--color-red);"></i> <?php echo htmlspecialchars($player); ?></span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php elseif ($m_type === 'teamspeak'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> Informace o serveru</div>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Název TS serveru:</strong> <span style="color: #fff; font-weight: 600;"><?php echo htmlspecialchars($details['name'] ?? 'TeamSpeak Server'); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Verze serveru:</strong> <span style="color: #fff;"><?php echo htmlspecialchars($details['version'] ?? 'Neznámá'); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Frekvence měření:</strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Poslední kontrola:</strong> <span class="stat-val"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : 'Nikdy'; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Poslední změna stavu:</strong> <span class="stat-val"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                        <p><strong>Uptime (30 dní):</strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight:bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                        <?php 
                                                        $ts_ll = $last_logs[0] ?? null;
                                                        if ($ts_ll): 
                                                        ?>
                                                        <p style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-muted);">
                                                             <i class="fas fa-stopwatch" style="color:var(--color-green);font-size:0.7rem;"></i>
                                                             Ping <?php echo (int)($ts_ll['response_time']); ?> ms
                                                             <?php if ($ts_ll['checked_from']): ?>
                                                                 &nbsp;<i class="fas fa-map-marker-alt" style="color:var(--color-red);font-size:0.7rem;"></i>
                                                                 z: <strong><?php echo htmlspecialchars($ts_ll['checked_from']); ?></strong>
                                                             <?php endif; ?>
                                                         </p>
                                                         <p style="font-size:0.73rem;color:var(--text-muted);margin-top:0.2rem;">
                                                             <i class="fas fa-info-circle"></i>
                                                             Ping ≈ čas TCP dotazu na TS query port (10011).
                                                             <?php if ($ts_ll['response_time'] > 500): ?>
                                                             Vysoká hodnota = vzdálenost agenta od serveru nebo pomalá DNS/TCP odezva.
                                                             <?php endif; ?>
                                                         </p>
                                                         <?php endif; ?>
                                                         
                                                         <?php if (isset($details['cpu'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-server"></i> Zátěž serveru (VPS Agent)</div>
                                                             <?php echo render_vps_agent_details($details, $monitor); ?>
                                                         <?php endif; ?>
                                                         
                                                         <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> Propojený VPS Agent</div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                 <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;">Klíč agenta</div>
                                                                 <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                 
                                                                 <button class="btn-install-agent" onclick="toggleAgentInstructions(<?php echo $mid; ?>)" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.35rem 0.6rem; border-radius: 4px; font-size: 0.7rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s; width: 100%; justify-content: center;">
                                                                     <i class="fas fa-terminal"></i> Návod k instalaci agenta
                                                                 </button>
                                                                 <div id="agent-instructions-<?php echo $mid; ?>" style="display: none; margin-top: 0.75rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 0.85rem; border-radius: 6px; font-size: 0.72rem; line-height: 1.45; max-width: 650px;">
                                                                     <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                                                         <div>
                                                                             <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fab fa-python"></i> Python 3 Agent</strong>
                                                                             <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                 <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.py <?php echo htmlspecialchars($agent_url); ?></code></li>
                                                                                 <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                 <li>Povolte: <code>chmod +x agent.py</code></li>
                                                                                 <li>Cron: <code>*/5 * * * * /cesta/agent.py</code></li>
                                                                             </ol>
                                                                         </div>
                                                                         <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 1.25rem;">
                                                                             <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fas fa-terminal"></i> Shell Agent (BASH)</strong>
                                                                             <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                 <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.sh <?php echo htmlspecialchars($agent_sh_url); ?></code></li>
                                                                                 <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                 <li>Povolte: <code>chmod +x agent.sh</code></li>
                                                                                 <li>Cron: <code>*/5 * * * * /cesta/agent.sh</code></li>
                                                                             </ol>
                                                                         </div>
                                                                     </div>
                                                                 </div>
                                                             </div>
                                                         <?php endif; ?>

                                                     </div>
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-users"></i> Připojení klienti</div>
                                                         <p style="font-size: 1.2rem; font-weight: bold; color: #fff; font-family: var(--font-header);">
                                                             <?php echo (int)($details['clients_online'] ?? 0); ?> / <?php echo (int)($details['clients_max'] ?? 0); ?>
                                                         </p>
                                                         <p style="color: var(--text-muted); margin-top: 0.25rem;">
                                                              Počet klientů na serveru (mimo Query/Query boty).
                                                              <?php if (!empty($details['ip_version'])): ?>
                                                                  <br><span style="font-size: 0.72rem; color: var(--color-green);"><i class="fas fa-network-wired"></i> Měřeno přes: <?php echo htmlspecialchars($details['ip_version']); ?><?php echo ($is_admin && !empty($details['checked_ip'])) ? ' (' . htmlspecialchars($details['checked_ip']) . ')' : ''; ?></span>
                                                              <?php endif; ?>
                                                          </p>
                                                     </div>
                                                 </div>
                                            <?php elseif ($m_type === 'discord'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-volume-up"></i> Aktivní hlasové kanály</div>
                                                        <?php if (empty($details['voice_channels'])): ?>
                                                            <p style="color: var(--text-muted); font-style: italic;">V hlasových kanálech právě nikdo není.</p>
                                                        <?php else: ?>
                                                            <?php foreach ($details['voice_channels'] as $chan): ?>
                                                                <div class="voice-channel-item">
                                                                    <div class="voice-channel-name"><?php echo htmlspecialchars($chan['name']); ?></div>
                                                                    <div class="voice-channel-users">
                                                                        <?php foreach ($chan['users'] as $user): ?>
                                                                            <span class="player-badge" style="background: rgba(30,199,115,0.1); border-color: rgba(30,199,115,0.15);"><i class="fas fa-microphone" style="font-size: 0.7rem; color: var(--color-green);"></i> <?php echo htmlspecialchars($user); ?></span>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> Propojený VPS Agent</div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;">Klíč agenta</div>
                                                                <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                
                                                                <button class="btn-install-agent" onclick="toggleAgentInstructions(<?php echo $mid; ?>)" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.35rem 0.6rem; border-radius: 4px; font-size: 0.7rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s; width: 100%; justify-content: center;">
                                                                    <i class="fas fa-terminal"></i> Návod k instalaci agenta
                                                                </button>
                                                                <div id="agent-instructions-<?php echo $mid; ?>" style="display: none; margin-top: 0.75rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 0.85rem; border-radius: 6px; font-size: 0.72rem; line-height: 1.45; max-width: 650px;">
                                                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                                                        <div>
                                                                            <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fab fa-python"></i> Python 3 Agent</strong>
                                                                            <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.py <?php echo htmlspecialchars($agent_url); ?></code></li>
                                                                                <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                <li>Povolte: <code>chmod +x agent.py</code></li>
                                                                                <li>Cron: <code>*/5 * * * * /cesta/agent.py</code></li>
                                                                            </ol>
                                                                        </div>
                                                                        <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 1.25rem;">
                                                                            <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fas fa-terminal"></i> Shell Agent (BASH)</strong>
                                                                            <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.sh <?php echo htmlspecialchars($agent_sh_url); ?></code></li>
                                                                                <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                <li>Povolte: <code>chmod +x agent.sh</code></li>
                                                                                <li>Cron: <code>*/5 * * * * /cesta/agent.sh</code></li>
                                                                            </ol>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>

                                                    </div>
                                                    <div>
                                                        <div class="detail-section-title">
                                                            <span><i class="fab fa-discord"></i> Online uživatelé</span>
                                                            <?php if (!empty($details['instant_invite'])): ?>
                                                                <a href="<?php echo htmlspecialchars($details['instant_invite']); ?>" target="_blank" class="category-badge" style="color: var(--color-green); border-color: rgba(30,199,115,0.3); background: rgba(30,199,115,0.05); font-size: 0.7rem;"><i class="fas fa-external-link-alt"></i> Vstoupit</a>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (empty($details['members'])): ?>
                                                            <p style="color: var(--text-muted); font-style: italic;">Žádní členové nejsou online (Widget).</p>
                                                        <?php else: ?>
                                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; max-height: 180px; overflow-y: auto; padding-right: 0.5rem;">
                                                                <?php foreach ($details['members'] as $m): 
                                                                    $status_class = $m['status'] ?? 'online';
                                                                    $game_text = !empty($m['game']) ? ' - hraje ' . htmlspecialchars($m['game']) : '';
                                                                ?>
                                                                    <div class="discord-member-item">
                                                                        <span class="discord-member-status <?php echo htmlspecialchars($status_class); ?>"></span>
                                                                        <span style="color: #fff; font-weight: 500; font-size: 0.8rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($m['username']) . $game_text; ?>">
                                                                            <?php echo htmlspecialchars($m['username']); ?>
                                                                            <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: normal;"><?php echo $game_text; ?></span>
                                                                        </span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php elseif ($m_type === 'cpanel' || ($m_type === 'web' && isset($details['cpanel_stats']))): ?>
                                                 <?php 
                                                 $cp_details = ($m_type === 'web') ? ($details['cpanel_stats'] ?? null) : $details;
                                                 ?>
                                                 <div class="game-details-grid" style="grid-template-columns: 1fr 1fr;">
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-info-circle"></i> Informace o hostingu</div>
                                                          <?php 
                                                          $parsed_url = parse_url($monitor['target']);
                                                          $display_target = ($parsed_url['host'] ?? $monitor['target']);
                                                          ?>
                                                          <p style="margin-bottom: 0.5rem;"><strong>Cíl měření:</strong> <span style="color: #fff;" class="stat-val"><?php echo htmlspecialchars($display_target); ?></span></p>
                                                         <p style="margin-bottom: 0.5rem;"><strong>Frekvence měření:</strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                         <p style="margin-bottom: 0.5rem;"><strong>Poslední kontrola:</strong> <span style="color: #fff;" class="stat-val"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : 'Nikdy'; ?></span></p>
                                                         <p style="margin-bottom: 0.5rem;"><strong>Poslední změna stavu:</strong> <span style="color: #fff;" class="stat-val"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                         <p><strong>Uptime (30 dní):</strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight: bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                         
                                                         <?php if ($m_type === 'web' && $status === 'up' && $details): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-network-wired"></i> Síťové parametry webu</div>
                                                             <div class="network-params-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.8rem;">
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                     <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;">Protokol / Verze</div>
                                                                     <strong style="color: #fff;"><?php echo htmlspecialchars(($details['scheme'] ?? 'HTTP') . '/' . ($details['http_version'] ?? '1.1')); ?></strong>
                                                                 </div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                     <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;">IP Adresa webu</div>
                                                                     <strong style="color: #fff;"><?php echo htmlspecialchars($details['primary_ip'] ?? 'N/A'); ?></strong>
                                                                 </div>
                                                             </div>
                                                         <?php endif; ?>

                                                         <?php 
                                                         // Průměrný ping dle lokací
                                                         $stmt_locs = $pdo->prepare("
                                                             SELECT checked_from, ROUND(AVG(response_time)) as avg_time, MAX(checked_at) as last_checked 
                                                             FROM monitor_logs 
                                                             WHERE monitor_id = ? AND response_time IS NOT NULL AND response_time > 0
                                                             GROUP BY checked_from 
                                                             ORDER BY last_checked DESC
                                                         ");
                                                         $stmt_locs->execute([$mid]);
                                                         $loc_stats = $stmt_locs->fetchAll();
                                                         if (!empty($loc_stats)):
                                                         ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-map-marker-alt"></i> Průměrný ping dle lokací</div>
                                                             <div class="location-stats-list" style="display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.5rem;">
                                                                 <?php foreach ($loc_stats as $ls): 
                                                                     $loc_name = $ls['checked_from'] ?: 'Hlavní systém';
                                                                     $avg_time = intval($ls['avg_time']);
                                                                 ?>
                                                                     <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.03); padding: 0.35rem 0.6rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                         <span style="color: var(--text-secondary);"><i class="fas fa-server" style="color: var(--color-green); margin-right: 0.5rem; font-size: 0.75rem;"></i> <?php echo htmlspecialchars($loc_name); ?></span>
                                                                         <strong style="color: #fff;"><?php echo $avg_time; ?> ms</strong>
                                                                     </div>
                                                                 <?php endforeach; ?>
                                                             </div>
                                                         <?php endif; ?>
                                                         
                                                         <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> Propojený VPS Agent</div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                 <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;">Klíč agenta</div>
                                                                 <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                 
                                                                 <button class="btn-install-agent" onclick="toggleAgentInstructions(<?php echo $mid; ?>)" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.35rem 0.6rem; border-radius: 4px; font-size: 0.7rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s; width: 100%; justify-content: center;">
                                                                     <i class="fas fa-terminal"></i> Návod k instalaci agenta
                                                                 </button>
                                                                 <div id="agent-instructions-<?php echo $mid; ?>" style="display: none; margin-top: 0.75rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 0.85rem; border-radius: 6px; font-size: 0.72rem; line-height: 1.45; max-width: 650px;">
                                                                     <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                                                         <div>
                                                                             <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fab fa-python"></i> Python 3 Agent</strong>
                                                                             <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                 <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.py <?php echo htmlspecialchars($agent_url); ?></code></li>
                                                                                 <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                 <li>Povolte: <code>chmod +x agent.py</code></li>
                                                                                 <li>Cron: <code>*/5 * * * * /cesta/agent.py</code></li>
                                                                             </ol>
                                                                         </div>
                                                                         <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 1.25rem;">
                                                                             <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fas fa-terminal"></i> Shell Agent (BASH)</strong>
                                                                             <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                 <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.sh <?php echo htmlspecialchars($agent_sh_url); ?></code></li>
                                                                                 <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                 <li>Povolte: <code>chmod +x agent.sh</code></li>
                                                                                 <li>Cron: <code>*/5 * * * * /cesta/agent.sh</code></li>
                                                                             </ol>
                                                                         </div>
                                                                     </div>
                                                                 </div>
                                                             </div>
                                                         <?php endif; ?>

                                                     </div>
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-chart-pie"></i> Čerpání limitů hostingu</div>
                                                         <?php if ($status === 'up' && $cp_details): ?>
                                                             <div style="display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.5rem;">
                                                                 <?php 
                                                                 $resources = [
                                                                     'Zatížení CPU' => $cp_details['cpu'] ?? null,
                                                                     'Physical Memory Usage' => $cp_details['memory'] ?? null,
                                                                     'Disk (HDD Usage)' => $cp_details['disk'] ?? null,
                                                                     'Běžící procesy' => $cp_details['processes'] ?? null,
                                                                     'Měsíční přenos (Bandwidth)' => $cp_details['bandwidth'] ?? null,
                                                                     'MySQL Databáze' => $cp_details['database'] ?? null,
                                                                     'PostgreSQL Databáze' => $cp_details['postgresql'] ?? null,
                                                                 ];
                                                                 foreach ($resources as $res_label => $res_data): 
                                                                     if (!$res_data) continue;
                                                                     $val = $res_data['percent'] ?? 0;
                                                                     $color = ($val > 85) ? 'red' : (($val > 60) ? 'yellow' : 'green');
                                                                 ?>
                                                                     <div>
                                                                         <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                                                                             <span style="color: var(--text-secondary);"><?php echo htmlspecialchars($res_label); ?></span>
                                                                             <strong style="color: #fff;" class="stat-val"><?php echo htmlspecialchars($res_data['formatted'] ?? ($val . '%')); ?> (<?php echo $val; ?>%)</strong>
                                                                         </div>
                                                                         <div class="chart-bar-container" style="height: 6px;">
                                                                             <div class="chart-bar-fill <?php echo $color; ?>" style="width: <?php echo $val; ?>%"></div>
                                                                         </div>
                                                                     </div>
                                                                 <?php endforeach; ?>
                                                             </div>
                                                         <?php else: ?>
                                                             <p style="color: var(--text-muted); font-style: italic;">Detaily hostingu nejsou při výpadku dostupné.</p>
                                                         <?php endif; ?>
                                                         
                                                         <?php if (!empty($last_logs)): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-history"></i> Historie posledních 5 měření</div>
                                                             <div class="mini-logs-list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                                 <?php foreach ($last_logs as $ll): 
                                                                     $ll_status = $ll['status'];
                                                                     $ll_time = date('H:i:s (d.m.)', strtotime($ll['checked_at']));
                                                                     $ll_badge_class = ($ll_status === 'up') ? 'log-status up' : 'log-status down';
                                                                     $ll_badge_text = ($ll_status === 'up') ? 'Online' : 'Výpadek';
                                                                 ?>
                                                                     <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.25rem;">
                                                                         <div style="display: flex; flex-direction: column;">
                                                                             <span style="color: var(--text-secondary); font-size: 0.8rem;"><?php echo $ll_time; ?></span>
                                                                             <?php if (!empty($ll['checked_from'])): ?>
                                                                                 <span style="font-size: 0.65rem; color: var(--text-muted);"><i class="fas fa-map-marker-alt" style="font-size: 0.6rem; color: var(--color-red); margin-right: 0.15rem;"></i><?php echo htmlspecialchars($ll['checked_from']); ?></span>
                                                                             <?php endif; ?>
                                                                         </div>
                                                                         <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                                             <span class="<?php echo $ll_badge_class; ?>" style="font-size: 0.7rem; padding: 0.15rem 0.35rem; border-radius: 4px;"><?php echo $ll_badge_text; ?></span>
                                                                             <span style="color: #fff; font-size: 0.8rem; font-weight: 500; min-width: 50px; text-align: right;">
                                                                                 <?php echo ($ll_status === 'up' && $ll['response_time'] > 0) ? $ll['response_time'] . ' ms' : 'N/A'; ?>
                                                                             </span>
                                                                         </div>
                                                                     </div>
                                                                 <?php endforeach; ?>
                                                             </div>
                                                         <?php endif; ?>
                                                     </div>
                                                 </div>
                                            <?php elseif ($m_type === 'vps'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> Informace o agentu</div>
                                                         <p style="margin-bottom: 0.5rem;"><strong>Frekvence měření:</strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Poslední aktualizace:</strong> <span style="color: #fff;"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : 'Nikdy'; ?></span></p>
                                                        <p><strong>Poslední změna stavu:</strong> <span style="color: #fff;"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                    </div>
                                                    <div>
                                                        <?php if (isset($details['cpu'])): ?>
                                                            <div class="detail-section-title"><i class="fas fa-server"></i> Zátěž serveru (VPS Agent)</div>
                                                            <?php echo render_vps_agent_details($details, $monitor); ?>
                                                        <?php else: ?>
                                                            <div class="detail-section-title"><i class="fas fa-server"></i> VPS Agent</div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem; color: var(--text-muted); font-style: italic;">
                                                                Čeká se na první data z VPS agenta...
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($is_admin): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-key"></i> Unikátní klíč agenta</div>
                                                            <code style="background: rgba(0,0,0,0.5); padding: 0.35rem 0.6rem; border-radius: 6px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.75rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                            
                                                            <button class="btn-install-agent" onclick="toggleAgentInstructions(<?php echo $mid; ?>)" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: all 0.2s; width: 100%; justify-content: center;">
                                                                <i class="fas fa-terminal"></i> Návod k instalaci agenta
                                                            </button>
                                                            <div id="agent-instructions-<?php echo $mid; ?>" style="display: none; margin-top: 0.75rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 0.85rem; border-radius: 6px; font-size: 0.72rem; line-height: 1.45; max-width: 650px;">
                                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                                                    <div>
                                                                        <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fab fa-python"></i> Python 3 Agent</strong>
                                                                        <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                            <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.py <?php echo htmlspecialchars($agent_url); ?></code></li>
                                                                            <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                                                                                <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                                                                                    API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                                                                                    AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                                                                                </div>
                                                                            </li>
                                                                            <li>Povolte: <code>chmod +x agent.py</code></li>
                                                                            <li>Cron: <code>*/5 * * * * /cesta/agent.py</code></li>
                                                                        </ol>
                                                                    </div>
                                                                    <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 1.25rem;">
                                                                        <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fas fa-terminal"></i> Shell Agent (BASH)</strong>
                                                                        <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                            <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.sh <?php echo htmlspecialchars($agent_sh_url); ?></code></li>
                                                                            <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                                                                                <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                                                                                    API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                                                                                    AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                                                                                </div>
                                                                            </li>
                                                                            <li>Povolte: <code>chmod +x agent.sh</code></li>
                                                                            <li>Cron: <code>*/5 * * * * /cesta/agent.sh</code></li>
                                                                        </ol>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="game-details-grid">
                                                     <div>
                                                          <div class="detail-section-title"><i class="fas fa-info-circle"></i> Statistiky monitoru</div>
                                                          <p style="margin-bottom: 0.5rem;"><strong>Cíl měření:</strong> <span style="color: #fff;"><?php echo htmlspecialchars($monitor['target']) . ($monitor['type'] !== 'teamspeak' && $monitor['port'] ? ':'.$monitor['port'] : ''); ?></span></p>
                                                          <p style="margin-bottom: 0.5rem;"><strong>Frekvence měření:</strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                          <p style="margin-bottom: 0.5rem;"><strong>Poslední kontrola:</strong> <span style="color: #fff;"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : 'Nikdy'; ?></span></p>
                                                          <p style="margin-bottom: 0.5rem;"><strong>Poslední změna stavu:</strong> <span style="color: #fff;"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                          <p><strong>Uptime (30 dní):</strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight: bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                          
                                                          <?php 
                                                          if ($monitor['type'] === 'web'): 
                                                              $web_det = json_decode($monitor['last_details'] ?? '', true);
                                                              if (!empty($web_det)):
                                                          ?>
                                                              <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-network-wired"></i> Síťové parametry webu</div>
                                                              <div class="network-params-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.8rem;">
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;">Protokol</div>
                                                                      <strong style="color: var(--color-green);"><?php echo htmlspecialchars($web_det['scheme'] ?? 'HTTP'); ?> (<?php echo htmlspecialchars($web_det['http_version'] ?? 'HTTP/1.1'); ?>)</strong>
                                                                  </div>
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;">Primární IP</div>
                                                                      <strong style="color: #fff; font-size: 0.7rem; word-break: break-all;"><?php echo htmlspecialchars($web_det['primary_ip'] ?? 'N/A'); ?></strong>
                                                                  </div>
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;">IPv4 připojení</div>
                                                                      <strong style="color: <?php echo (!empty($web_det['has_ipv4']) ? 'var(--color-green)' : 'var(--text-muted)'); ?>;">
                                                                          <?php echo (!empty($web_det['has_ipv4']) ? '<i class="fas fa-check-circle" style="font-size: 0.75rem;"></i> Ano' : '<i class="fas fa-times-circle" style="font-size: 0.75rem;"></i> Ne'); ?>
                                                                      </strong>
                                                                  </div>
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;">IPv6 připojení</div>
                                                                      <strong style="color: <?php echo (!empty($web_det['has_ipv6']) ? 'var(--color-green)' : 'var(--color-yellow)'); ?>;">
                                                                          <?php echo (!empty($web_det['has_ipv6']) ? '<i class="fas fa-check-circle" style="font-size: 0.75rem;"></i> Ano' : '<i class="fas fa-exclamation-triangle" style="font-size: 0.75rem;"></i> Chybí'); ?>
                                                                      </strong>
                                                                  </div>
                                                              </div>
                                                          <?php 
                                                              endif;
                                                          endif; 
                                                          ?>
                                                          
                                                          <?php if (isset($details['cpu'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-server"></i> Zátěž serveru (VPS Agent)</div>
                                                             <?php echo render_vps_agent_details($details, $monitor); ?>
                                                         <?php endif; ?>
                                                          
                                                          <?php
                                                          $stmt_locs = $pdo->prepare("
                                                              SELECT checked_from, ROUND(AVG(response_time)) as avg_time, MAX(checked_at) as last_checked 
                                                              FROM monitor_logs 
                                                              WHERE monitor_id = ? AND response_time IS NOT NULL AND response_time > 0
                                                              GROUP BY checked_from 
                                                              ORDER BY last_checked DESC
                                                          ");
                                                          $stmt_locs->execute([$mid]);
                                                          $loc_stats = $stmt_locs->fetchAll();
                                                          if (!empty($loc_stats)):
                                                          ?>
                                                              <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-map-marker-alt"></i> Průměrný ping dle lokací</div>
                                                              <div class="location-stats-list" style="display: flex; flex-direction: column; gap: 0.4rem; margin-top: 0.5rem;">
                                                                  <?php foreach ($loc_stats as $ls): 
                                                                      $loc_name = $ls['checked_from'] ?: 'Hlavní systém';
                                                                      $avg_time = intval($ls['avg_time']);
                                                                  ?>
                                                                      <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.03); padding: 0.35rem 0.6rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                          <span style="color: var(--text-secondary);"><i class="fas fa-server" style="color: var(--color-green); margin-right: 0.5rem; font-size: 0.75rem;"></i> <?php echo htmlspecialchars($loc_name); ?></span>
                                                                          <strong style="color: #fff;"><?php echo $avg_time; ?> ms</strong>
                                                                      </div>
                                                                  <?php endforeach; ?>
                                                              </div>
                                                          <?php endif; ?>
                                                          
                                                          <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> Propojený VPS Agent</div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                 <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;">Klíč agenta</div>
                                                                 <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                 
                                                                 <button class="btn-install-agent" onclick="toggleAgentInstructions(<?php echo $mid; ?>)" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.35rem 0.6rem; border-radius: 4px; font-size: 0.7rem; cursor: pointer; display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s; width: 100%; justify-content: center;">
                                                                     <i class="fas fa-terminal"></i> Návod k instalaci agenta
                                                                 </button>
                                                                 <div id="agent-instructions-<?php echo $mid; ?>" style="display: none; margin-top: 0.75rem; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.05); padding: 0.85rem; border-radius: 6px; font-size: 0.72rem; line-height: 1.45; max-width: 650px;">
                                                                     <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                                                                         <div>
                                                                             <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fab fa-python"></i> Python 3 Agent</strong>
                                                                             <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                 <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.py <?php echo htmlspecialchars($agent_url); ?></code></li>
                                                                                 <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                 <li>Povolte: <code>chmod +x agent.py</code></li>
                                                                                 <li>Cron: <code>*/5 * * * * /cesta/agent.py</code></li>
                                                                             </ol>
                                                                         </div>
                                                                         <div style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 1.25rem;">
                                                                             <strong style="color: var(--color-green); display: block; margin-bottom: 0.35rem; font-size: 0.75rem;"><i class="fas fa-terminal"></i> Shell Agent (BASH)</strong>
                                                                             <ol style="margin: 0; padding-left: 1.1rem; display: flex; flex-direction: column; gap: 0.35rem; color: var(--text-secondary);">
                                                                                 <li>Stáhněte agenta:<br><code style="background: rgba(0,0,0,0.4); padding: 0.1rem 0.25rem; border-radius: 3px; font-size: 0.62rem; word-break: break-all;">wget -O agent.sh <?php echo htmlspecialchars($agent_sh_url); ?></code></li>
                                                                                 <li>Nastavte konfiguraci (vytvořte soubor <code>agent.cfg</code>):<br>
                    <div style="background: rgba(0,0,0,0.3); padding: 0.25rem; border-radius: 4px; font-size: 0.6rem; margin-top: 0.15rem; font-family: monospace; line-height: 1.3;">
                        API_URL = "<?php echo htmlspecialchars(str_replace('index.php', 'api.php', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]")); ?>"<br>
                        AGENT_KEY = "<?php echo htmlspecialchars($monitor['agent_key']); ?>"
                    </div>
                </li>
                                                                                 <li>Povolte: <code>chmod +x agent.sh</code></li>
                                                                                 <li>Cron: <code>*/5 * * * * /cesta/agent.sh</code></li>
                                                                             </ol>
                                                                         </div>
                                                                     </div>
                                                                 </div>
                                                             </div>
                                                         <?php endif; ?>

                                                     </div>
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-history"></i> Historie posledních 5 měření</div>
                                                         <?php if (empty($last_logs)): ?>
                                                             <p style="color: var(--text-muted); font-style: italic;">Zatím nejsou k dispozici žádná data o měření.</p>
                                                         <?php else: ?>
                                                             <div class="mini-logs-list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                                 <?php foreach ($last_logs as $ll): 
                                                                     $ll_status = $ll['status'];
                                                                     $ll_time = date('H:i:s (d.m.)', strtotime($ll['checked_at']));
                                                                     $ll_badge_class = ($ll_status === 'up') ? 'log-status up' : 'log-status down';
                                                                     $ll_badge_text = ($ll_status === 'up') ? 'Online' : 'Výpadek';
                                                                 ?>
                                                                     <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 0.25rem;">
                                                                         <div style="display: flex; flex-direction: column;">
                                                                             <span style="color: var(--text-secondary); font-size: 0.8rem;"><?php echo $ll_time; ?></span>
                                                                             <?php if (!empty($ll['checked_from'])): ?>
                                                                                 <span style="font-size: 0.65rem; color: var(--text-muted);"><i class="fas fa-map-marker-alt" style="font-size: 0.6rem; color: var(--color-red); margin-right: 0.15rem;"></i><?php echo htmlspecialchars($ll['checked_from']); ?></span>
                                                                             <?php endif; ?>
                                                                         </div>
                                                                         <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                                             <span class="<?php echo $ll_badge_class; ?>" style="font-size: 0.7rem; padding: 0.15rem 0.35rem; border-radius: 4px;"><?php echo $ll_badge_text; ?></span>
                                                                             <span style="color: #fff; font-size: 0.8rem; font-weight: 500; min-width: 50px; text-align: right;">
                                                                                 <?php echo ($ll_status === 'up' && $ll['response_time'] > 0) ? $ll['response_time'] . ' ms' : 'N/A'; ?>
                                                                             </span>
                                                                         </div>
                                                                     </div>
                                                                     <?php if ($ll_status === 'down' && $ll['error_message']): ?>
                                                                         <div style="color: var(--color-red); font-size: 0.75rem; margin-top: -0.2rem; margin-bottom: 0.2rem; padding-left: 0.5rem; border-left: 2px solid var(--color-red); font-style: italic;">
                                                                             <?php echo htmlspecialchars($ll['error_message']); ?>
                                                                         </div>
                                                                     <?php endif; ?>
                                                                 <?php endforeach; ?>
                                                             </div>
                                                         <?php endif; ?>
                                                     </div>
                                                 </div>
                                            <?php endif; ?>
                                            <?php
                                            // Query metrics history for the charts
                                            $show_charts = false;
                                            $cpu_avg = $cpu_max = $ram_avg = $ram_max = 0;
                                            $labels = [];
                                            $cpu_data = [];
                                            $ram_data = [];

                                            if ($m_type === 'vps' || $m_type === 'cpanel' || ($m_type === 'web' && isset($details['cpanel_stats'])) || isset($details['cpu'])) {
                                                $stmt_metrics_history = $pdo->prepare("
                                                    SELECT checked_at, cpu_usage, ram_usage, hdd_usage 
                                                    FROM vps_metrics 
                                                    WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                                    ORDER BY checked_at ASC
                                                ");
                                                $stmt_metrics_history->execute([$mid]);
                                                $metrics_history = $stmt_metrics_history->fetchAll();
                                                
                                                if (!empty($metrics_history)) {
                                                    $show_charts = true;
                                                    $cpu_sum = 0;
                                                    $cpu_max = 0;
                                                    $ram_sum = 0;
                                                    $ram_max = 0;
                                                    $count_mh = count($metrics_history);
                                                    
                                                    foreach ($metrics_history as $mh) {
                                                        $cpu_sum += $mh['cpu_usage'];
                                                        if ($mh['cpu_usage'] > $cpu_max) $cpu_max = $mh['cpu_usage'];
                                                        
                                                        $ram_sum += $mh['ram_usage'];
                                                        if ($mh['ram_usage'] > $ram_max) $ram_max = $mh['ram_usage'];
                                                        
                                                        $labels[] = date('H:i', strtotime($mh['checked_at']));
                                                        $cpu_data[] = $mh['cpu_usage'];
                                                        $ram_data[] = $mh['ram_usage'];
                                                    }
                                                    
                                                    $cpu_avg = round($cpu_sum / $count_mh, 1);
                                                    $ram_avg = round($ram_sum / $count_mh, 1);
                                                }
                                            }
                                            ?>
                                            <?php if ($show_charts): ?>
                                                <div class="metrics-history-charts" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                                                        <div class="detail-section-title" style="margin-bottom: 0;"><i class="fas fa-chart-line"></i> Historie vytížení</div>
                                                        <div class="chart-period-switch" data-monitor="<?php echo $mid; ?>" style="display: flex; gap: 0.25rem;">
                                                            <button type="button" data-period="24h" class="btn btn-secondary btn-sm active" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;">24 hodin</button>
                                                            <button type="button" data-period="7d" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;">7 dní</button>
                                                            <button type="button" data-period="30d" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;">30 dní</button>
                                                        </div>
                                                    </div>
                                                    <div style="display: flex; gap: 1rem; margin: 0.75rem 0 1rem 0; font-size: 0.8rem;">
                                                        <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                            <span style="color: var(--text-muted);">CPU Průměr / Max:</span>
                                                            <strong style="color: #fff; margin-left: 0.25rem;" id="cpuStats-<?php echo $mid; ?>"><?php echo $cpu_avg; ?>% / <?php echo $cpu_max; ?>%</strong>
                                                        </div>
                                                        <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                            <span style="color: var(--text-muted);">RAM Průměr / Max:</span>
                                                            <strong style="color: #fff; margin-left: 0.25rem;" id="ramStats-<?php echo $mid; ?>"><?php echo $ram_avg; ?>% / <?php echo $ram_max; ?>%</strong>
                                                        </div>
                                                    </div>
                                                    <div style="position: relative; height: 220px; width: 100%;">
                                                        <canvas id="metricsChart-<?php echo $mid; ?>"></canvas>
                                                    </div>
                                                </div>
                                                
                                                <script>
                                                document.addEventListener("DOMContentLoaded", function() {
                                                    const ctx = document.getElementById('metricsChart-<?php echo $mid; ?>');
                                                    if (!ctx) return;
                                                    window.bkMetricsCharts = window.bkMetricsCharts || {};
                                                    window.bkMetricsCharts[<?php echo $mid; ?>] = new Chart(ctx, {
                                                        type: 'line',
                                                        data: {
                                                            labels: <?php echo json_encode($labels); ?>,
                                                            datasets: [
                                                                {
                                                                    label: 'CPU (%)',
                                                                    data: <?php echo json_encode($cpu_data); ?>,
                                                                    borderColor: '#e63946',
                                                                    backgroundColor: 'rgba(230, 57, 70, 0.05)',
                                                                    borderWidth: 2,
                                                                    pointRadius: 0,
                                                                    tension: 0.3,
                                                                    fill: true
                                                                },
                                                                {
                                                                    label: 'RAM (%)',
                                                                    data: <?php echo json_encode($ram_data); ?>,
                                                                    borderColor: '#2ec4b6',
                                                                    backgroundColor: 'rgba(30, 199, 115, 0.05)',
                                                                    borderWidth: 2,
                                                                    pointRadius: 0,
                                                                    tension: 0.3,
                                                                    fill: true
                                                                }
                                                            ]
                                                        },
                                                        options: {
                                                            responsive: true,
                                                            maintainAspectRatio: false,
                                                            plugins: {
                                                                legend: {
                                                                    labels: { color: '#e1e1e6', boxWidth: 12, font: { size: 11 } }
                                                                }
                                                            },
                                                            scales: {
                                                                x: {
                                                                    grid: { color: 'rgba(255,255,255,0.03)' },
                                                                    ticks: { color: '#8b8ba0', maxTicksLimit: 12, font: { size: 10 } }
                                                                },
                                                                y: {
                                                                    min: 0,
                                                                    max: 100,
                                                                    grid: { color: 'rgba(255,255,255,0.03)' },
                                                                    ticks: { color: '#8b8ba0', font: { size: 10 } }
                                                                }
                                                            }
                                                        }
                                                    });
                                                });
                                                </script>
                                             <?php endif; ?>
                                             
                                             <?php if (!empty($monitor_outages)): ?>
                                                 <div class="monitor-outages-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                     <div class="detail-section-title" style="color: var(--color-red); margin-bottom: 0.75rem;"><i class="fas fa-exclamation-circle"></i> Nedávné výpadky (posledních 30 dní)</div>
                                                     <div style="overflow-x: auto;">
                                                         <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left;">
                                                             <thead>
                                                                 <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted);">
                                                                     <th style="padding: 0.5rem 0.25rem;">Čas</th>
                                                                     <th style="padding: 0.5rem 0.25rem;">Chyba / Důvod výpadku</th>
                                                                     <th style="padding: 0.5rem 0.25rem; text-align: right;">Měřeno z</th>
                                                                 </tr>
                                                             </thead>
                                                             <tbody>
                                                                 <?php foreach ($monitor_outages as $mo): 
                                                                     $mo_time = date('d.m.Y H:i:s', strtotime($mo['checked_at']));
                                                                 ?>
                                                                     <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-secondary);">
                                                                         <td style="padding: 0.5rem 0.25rem; font-weight: 500; color: #fff; white-space: nowrap;"><?php echo $mo_time; ?></td>
                                                                         <td style="padding: 0.5rem 0.25rem; color: var(--color-red); font-style: italic; word-break: break-all;"><?php echo htmlspecialchars($mo['error_message'] ?: 'Nespecifikovaná chyba spojení'); ?></td>
                                                                         <td style="padding: 0.5rem 0.25rem; text-align: right; white-space: nowrap;"><i class="fas fa-map-marker-alt" style="font-size: 0.65rem; color: var(--color-red); margin-right: 0.15rem;"></i><?php echo htmlspecialchars($mo['checked_from'] ?: 'Main Server'); ?></td>
                                                                     </tr>
                                                                 <?php endforeach; ?>
                                                             </tbody>
                                                         </table>
                                                     </div>
                                                 </div>
                                             <?php endif; ?>
                                         </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
            
        <?php endif; ?>

        <!-- Sekce s incidenty -->
        <?php if (!empty($incidents)): ?>
            <div class="incident-card">
                <h2><i class="fas fa-history" style="color: var(--color-red); margin-right: 0.5rem;"></i> Historie posledních událostí</h2>
                <div style="overflow-x: auto;">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Čas</th>
                                <th>Monitor</th>
                                <th>Typ</th>
                                <th>Lokace</th>
                                <th>Stav</th>
                                <th>Chyba / Informace</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incidents as $inc): ?>
                                <tr>
                                    <td><?php echo date('d.m.Y H:i:s', strtotime($inc['checked_at'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($inc['name']); ?></strong>
                                        <span style="display: block; font-size: 0.75rem; color: var(--text-muted); font-family: monospace;"><?php echo htmlspecialchars($inc['target'] ?? ''); ?></span>
                                    </td>
                                    <td><span style="font-size: 0.8rem; text-transform: uppercase; color: var(--text-muted);"><?php echo htmlspecialchars($inc['type']); ?></span></td>
                                    <td>
                                        <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                            <i class="fas fa-map-marker-alt" style="font-size: 0.7rem; color: var(--color-red); margin-right: 0.15rem;"></i><?php echo htmlspecialchars($inc['checked_from'] ?: 'Main Server'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="log-status <?php echo $inc['status']; ?>">
                                            <?php echo ($inc['status'] === 'up') ? 'Online' : 'Výpadek'; ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--text-secondary);">
                                        <?php 
                                        if ($inc['status'] === 'down') {
                                            echo htmlspecialchars($inc['error_message'] ?: 'Nespecifikovaná chyba spojení');
                                        } else {
                                            echo htmlspecialchars($inc['error_message'] ?: 'Služba se vrátila do normálního provozu');
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <a href="../index.html">Blood Kings</a>. Všechna práva vyhrazena.</p>
            <?php $ver = get_app_version(); ?>
            <p style="font-size: 0.75rem; opacity: 0.5; margin-top: 0.25rem;">
                <i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($ver['label']); ?>
            </p>
            <div class="social-links" style="margin-top: 0.75rem; display: flex; justify-content: center; gap: 1.25rem; font-size: 1.2rem;">
                <a href="https://www.facebook.com/bloodkings" target="_blank" style="color: var(--text-muted); transition: color 0.15s ease;" onmouseover="this.style.color='#1877f2'" onmouseout="this.style.color='var(--text-muted)'" title="Facebook Page"><i class="fab fa-facebook"></i></a>
                <a href="https://discord.gg/bloodkings" target="_blank" style="color: var(--text-muted); transition: color 0.15s ease;" onmouseover="this.style.color='#5865f2'" onmouseout="this.style.color='var(--text-muted)'" title="Discord Server"><i class="fab fa-discord"></i></a>
            </div>
        </div>
    </footer>

    <script>
    function toggleDetails(id) {
        const item = document.getElementById('monitor-item-' + id);
        const panel = document.getElementById('details-panel-' + id);
        
        if (!item || !panel) return;
        
        const isOpen = item.classList.contains('open');
        
        if (isOpen) {
            panel.style.maxHeight = null;
            item.classList.remove('open');
        } else {
            panel.style.maxHeight = panel.scrollHeight + "px";
            item.classList.add('open');
        }
    }

    function toggleAgentInstructions(id) {
        const block = document.getElementById('agent-instructions-' + id);
        const panel = document.getElementById('details-panel-' + id);
        if (!block) return;
        
        const isHidden = block.style.display === 'none';
        block.style.display = isHidden ? 'block' : 'none';
        
        if (panel) {
            panel.style.maxHeight = panel.scrollHeight + "px";
        }
    }

    // Přepínač období grafů vytížení (24h / 7d / 30d)
    document.querySelectorAll('.chart-period-switch').forEach((sw) => {
        const monitorId = sw.dataset.monitor;
        sw.querySelectorAll('button[data-period]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                if (btn.classList.contains('active')) return;
                const chart = window.bkMetricsCharts && window.bkMetricsCharts[monitorId];
                if (!chart) return;

                sw.querySelectorAll('button').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                btn.disabled = true;
                try {
                    const res = await fetch('api.php?action=metrics_history&monitor_id=' + encodeURIComponent(monitorId) + '&period=' + encodeURIComponent(btn.dataset.period));
                    const data = await res.json();
                    chart.data.labels = data.labels;
                    chart.data.datasets[0].data = data.cpu;
                    chart.data.datasets[1].data = data.ram;
                    chart.update();

                    const cpuStats = document.getElementById('cpuStats-' + monitorId);
                    const ramStats = document.getElementById('ramStats-' + monitorId);
                    if (cpuStats) cpuStats.textContent = data.cpu_avg + '% / ' + data.cpu_max + '%';
                    if (ramStats) ramStats.textContent = data.ram_avg + '% / ' + data.ram_max + '%';
                } catch (e) {
                    console.error('Nepodařilo se načíst historii metrik:', e);
                } finally {
                    btn.disabled = false;
                }
            });
        });
    });

    // Theme toggle logic
    const themeToggle = document.getElementById('theme-toggle');
    if (themeToggle) {
        const updateIcon = () => {
            const isLight = document.documentElement.classList.contains('light-theme');
            themeToggle.innerHTML = isLight ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        };
        updateIcon();
        
        themeToggle.addEventListener('click', () => {
            const isLight = document.documentElement.classList.toggle('light-theme');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
            updateIcon();
        });
    }
    </script>
</body>
</html>