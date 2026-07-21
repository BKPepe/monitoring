<?php
/**
 * Veřejný status dashboard (Blood Kings Status)
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

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
    $cat = $m['category'] ?: t('default_category');
    $categories[$cat][] = $m;
}

// Počet aktivně hlásících VPS/host agentů (stejná logika jako detekce v cron.php).
// agent_key se automaticky generuje pro VŠECHNY monitory (viz migrace v db.php),
// i ty bez jakéhokoli nainstalovaného agenta - takže "má agent_key" neznamená
// "má nasazeného agenta". Počítáme proto jen monitory, u kterých se agent někdy
// reálně ozval (agent_last_seen je vyplněné), ne jen ty s (nevyužitým) klíčem.
$agent_offline_timeout_secs = max(0, (int)get_setting('agent_offline_timeout', '50')) * 60;
$online_agents_count = 0;
$total_agents_count = 0;
foreach ($monitors as $m) {
    if (empty($m['agent_key'])) continue;
    $det = json_decode($m['last_details'] ?? '', true);
    $last_seen = $det['agent_last_seen'] ?? 0;
    if ($last_seen <= 0) continue;
    $total_agents_count++;
    // Časový limit 0 = detekce neaktivity vypnuta - agent, který se kdy ozval, počítá jako online
    if ($agent_offline_timeout_secs === 0 || (time() - (int)$last_seen) < $agent_offline_timeout_secs) {
        $online_agents_count++;
    }
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
        LIMIT 200
    ");
    $incidents = $stmt_inc->fetchAll();

    // Distribuovaní agenti/uzly, kteří v posledních 24h hlásili měření (veřejná
    // "Global Agent Map" - stejná logika jako admin diagnostika, bez citlivých detailů).
    // Hlavní server (lokální cron.php) zapisuje své vlastní kontroly do stejného
    // sloupce checked_from jako vzdálení agenti - bez vyloučení by se "hub" tvářil
    // jako distribuovaný uzel, což zkresluje počet i smysl téhle sekce.
    $hub_location = trim(get_setting('cron_location', ''));
    if ($hub_location === '' || $hub_location === 'AUTO' || $hub_location === '🇨🇿 Praha, CZ') {
        $hub_location = trim(get_setting('ip_loc_local', ''));
    }
    $stmt_regions = $pdo->prepare("
        SELECT checked_from, COUNT(*) as cnt, MAX(checked_at) as last_seen,
               ROUND(AVG(response_time)) as avg_latency
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND checked_from IS NOT NULL
              AND checked_from != 'Main Server'" . ($hub_location !== '' ? " AND checked_from != ?" : "") . "
        GROUP BY checked_from
        ORDER BY last_seen DESC
        LIMIT 24
    ");
    $stmt_regions->execute($hub_location !== '' ? [$hub_location] : []);
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
// Odkaz na nadřazený portál (např. hlavní web provozovatele) - prázdné = skrýt.
// Bez tohoto nastavení by referenční self-hosted nasazení mělo natvrdo odkaz
// na cizí "../index.html", který u samostatné instalace nikam nevede.
$portal_url = trim(get_setting('portal_url'));

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
        case 'openwrt':
            return '<i class="fas fa-wifi" style="color:#f39c12;font-size:'.$size.';" title="OpenWrt"></i>';
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
<html lang="<?php echo htmlspecialchars($GLOBALS['BK_LANG']); ?>">
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
            <a href="<?php echo $portal_url !== '' ? htmlspecialchars($portal_url) : 'index.php'; ?>" class="logo">
                <?php if ($custom_logo_url !== ''): ?>
                    <img src="<?php echo htmlspecialchars($custom_logo_url); ?>" alt="<?php echo htmlspecialchars($site_title); ?>" style="height: 28px; vertical-align: middle;">
                    <span><?php echo htmlspecialchars($site_title); ?></span>
                <?php else: ?>
                    <i class="fas fa-server" style="color: var(--color-red);"></i> <?php echo htmlspecialchars($site_title); ?>
                <?php endif; ?>
            </a>
            <div class="nav-links">
                <?php if ($portal_url !== ''): ?>
                    <a href="<?php echo htmlspecialchars($portal_url); ?>"><i class="fas fa-home"></i> <?php echo htmlspecialchars(t('nav_portal')); ?></a>
                <?php endif; ?>
                <a href="index.php" class="active"><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars(t('nav_monitoring')); ?></a>
                <?php foreach ($custom_nav_links as $nav_link):
                    $nl_name = trim((string)($nav_link['name'] ?? ''));
                    $nl_url = trim((string)($nav_link['url'] ?? ''));
                    if ($nl_name === '' || !preg_match('#^https?://#i', $nl_url)) continue;
                ?>
                    <a href="<?php echo htmlspecialchars($nl_url); ?>" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> <?php echo htmlspecialchars($nl_name); ?></a>
                <?php endforeach; ?>
                <?php if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true): ?>
                    <a href="admin.php" class="btn btn-secondary btn-sm" style="background: rgba(30, 199, 115, 0.1); border: 1px solid rgba(30, 199, 115, 0.3); color: var(--color-green);"><i class="fas fa-user-shield"></i> <?php echo htmlspecialchars(t('nav_admin_prefix')); ?> (<?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>)</a>
                    <a href="admin.php?action=logout" class="btn btn-secondary btn-sm" style="background: rgba(193, 18, 31, 0.1); border: 1px solid rgba(193, 18, 31, 0.3); color: var(--color-red);" onclick="return confirm('<?php echo htmlspecialchars(t('nav_logout_confirm')); ?>')"><i class="fas fa-sign-out-alt"></i> <?php echo htmlspecialchars(t('nav_logout')); ?></a>
                <?php else: ?>
                    <a href="admin.php" class="btn btn-secondary btn-sm"><i class="fas fa-lock"></i> <?php echo htmlspecialchars(t('nav_admin')); ?></a>
                <?php endif; ?>
                <a href="?lang=<?php echo $GLOBALS['BK_LANG'] === 'cs' ? 'en' : 'cs'; ?>" class="btn btn-secondary btn-sm" style="padding: 0.4rem 0.6rem; margin-left: 0.25rem; border-radius: 4px; font-weight: 700; font-size: 0.75rem;" title="<?php echo htmlspecialchars(t('lang_toggle_title')); ?>"><?php echo $GLOBALS['BK_LANG'] === 'cs' ? 'EN' : 'CS'; ?></a>
                <button id="theme-toggle" class="btn btn-secondary btn-sm" style="padding: 0.4rem 0.6rem; margin-left: 0.25rem; border-radius: 4px;" title="<?php echo htmlspecialchars(t('theme_toggle_title')); ?>"><i class="fas fa-sun"></i></button>
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
                    <h2><i class="fas fa-exclamation-triangle" style="color: var(--color-red);"></i> <?php echo htmlspecialchars(t('hero_down_title')); ?></h2>
                    <p><?php echo htmlspecialchars(sprintf(t('hero_down_desc'), $down_monitors, $total_monitors)); ?></p>
                <?php elseif ($maintenance_monitors_count > 0): ?>
                    <h2><i class="fas fa-tools" style="color: var(--color-yellow, #f39c12);"></i> <?php echo htmlspecialchars(t('hero_maintenance_title')); ?></h2>
                    <p><?php echo htmlspecialchars(sprintf(t('hero_maintenance_desc'), $maintenance_monitors_count)); ?></p>
                <?php else: ?>
                    <h2><i class="fas fa-check-circle" style="color: var(--color-green);"></i> <?php echo htmlspecialchars(t('hero_ok_title')); ?></h2>
                    <p><?php echo htmlspecialchars(sprintf(t('hero_ok_desc'), $total_monitors)); ?></p>
                <?php endif; ?>
                <?php if ($last_checked_global): ?>
                    <p class="last-update-line"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(t('hero_last_check')); ?> <?php echo date('d.m.Y H:i:s', strtotime($last_checked_global)); ?></p>
                <?php endif; ?>
            </div>

            <div class="overall-stats">
                <div class="stat-item">
                    <div class="stat-value up"><?php echo $up_monitors; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_online')); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-value down"><?php echo $down_monitors; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_down')); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-value <?php echo $maintenance_monitors_count > 0 ? 'warn' : 'total'; ?>"><?php echo $maintenance_monitors_count; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_maintenance')); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-value <?php echo $avg_uptime >= 99 ? 'up' : ($avg_uptime >= 95 ? 'warn' : 'down'); ?>"><?php echo number_format($avg_uptime, 2, ',', ' '); ?>%</div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_uptime_30d')); ?></div>
                </div>
                <div class="stat-item">
                    <div class="stat-value total"><?php echo $total_monitors; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_total')); ?></div>
                </div>
                <?php if ($total_agents_count > 0): ?>
                <div class="stat-item">
                    <div class="stat-value <?php echo $online_agents_count === $total_agents_count ? 'up' : ($online_agents_count > 0 ? 'warn' : 'down'); ?>"><?php echo $online_agents_count; ?>/<?php echo $total_agents_count; ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_agents_online')); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($regions)): ?>
                <div class="stat-item">
                    <div class="stat-value total"><?php echo count($regions); ?></div>
                    <div class="stat-label"><?php echo htmlspecialchars(t('stat_regions')); ?></div>
                </div>
                <?php endif; ?>
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
                    <i class="fas fa-tools"></i> <?php echo htmlspecialchars(t('maintenance_banner_title')); ?>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <?php foreach ($maintenance_monitors as $mm):
                        $desc = $mm['maintenance_description'] ?: t('maintenance_default_desc');
                        $time_str = '';
                        if (!empty($mm['maintenance_start']) && !empty($mm['maintenance_end'])) {
                            $time_str = ' (' . sprintf(t('maintenance_duration_range'), date('d.m.Y H:i', strtotime($mm['maintenance_start'])), date('d.m.Y H:i', strtotime($mm['maintenance_end']))) . ')';
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
                <h2 class="category-title"><i class="fas fa-satellite-dish"></i> <?php echo htmlspecialchars(t('regions_title')); ?></h2>
                <div class="regions-grid">
                    <?php foreach ($regions as $rg):
                        $r_diff_min = round((time() - strtotime($rg['last_seen'])) / 60);
                        if ($r_diff_min < 15) {
                            $r_state = 'online'; $r_label = t('region_state_online');
                        } elseif ($r_diff_min < 60) {
                            $r_state = 'warn'; $r_label = t('region_state_warn');
                        } else {
                            $r_state = 'offline'; $r_label = t('region_state_offline');
                        }
                        $r_ago = $r_diff_min < 2 ? t('time_just_now') : ($r_diff_min < 60 ? sprintf(t('time_ago_min'), $r_diff_min) : sprintf(t('time_ago_hours'), round($r_diff_min / 60)));
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
                <h2><?php echo htmlspecialchars(t('empty_title')); ?></h2>
                <p><?php echo htmlspecialchars(t('empty_desc')); ?></p>
                <a href="admin.php" class="btn" style="margin-top: 1.5rem;"><i class="fas fa-plus"></i> <?php echo htmlspecialchars(t('empty_cta')); ?></a>
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
                            // Service Profiles - null = typ bez checklistu, dashboard zobrazí vše jako dřív
                            $enabled_metrics = bk_get_enabled_metrics($monitor);

                            // Má tento konkrétní monitor aktivně hlásícího VPS-metrics agenta? Stejná
                            // freshness logika jako souhrnný "X/Y agentů online" stat výše, jen per-monitor -
                            // aby bylo veřejně vidět, KTERÝ monitor tu statistiku tvoří (agent_key samotný
                            // zůstává admin-only, viz linked_agent_heading sekce níže).
                            $has_live_agent = false;
                            if (!empty($monitor['agent_key'])) {
                                $agent_last_seen = $details['agent_last_seen'] ?? 0;
                                if ($agent_last_seen && ($agent_offline_timeout_secs === 0 || (time() - (int)$agent_last_seen) < $agent_offline_timeout_secs)) {
                                    $has_live_agent = true;
                                }
                            }

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
                                                    <span style="background: rgba(243, 156, 18, 0.15); border: 1px solid rgba(243, 156, 18, 0.25); color: #f39c12; font-size: 0.65rem; padding: 0.15rem 0.4rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem; font-weight: bold; text-transform: uppercase;" title="<?php echo htmlspecialchars(t('maintenance_badge_title')); ?>"><i class="fas fa-tools"></i> <?php echo htmlspecialchars(t('maintenance_badge')); ?></span>
                                                <?php endif; ?>
                                                <?php if ($has_live_agent): ?>
                                                    <span style="background: rgba(30, 199, 115, 0.1); border: 1px solid rgba(30, 199, 115, 0.2); color: var(--color-green); font-size: 0.65rem; padding: 0.15rem 0.4rem; border-radius: 4px; display: inline-flex; align-items: center; gap: 0.25rem; font-weight: bold; text-transform: uppercase;" title="<?php echo htmlspecialchars(t('agent_badge_title')); ?>"><i class="fas fa-microchip"></i> <?php echo htmlspecialchars(t('agent_badge')); ?></span>
                                                <?php endif; ?>
                                            </h3>
                                            <span>
                                                <?php 
                                                if ($m_type === 'discord') {
                                                    echo htmlspecialchars(t('type_discord_server'));
                                                } elseif ($m_type === 'teamspeak') {
                                                    $ts_host = $monitor['target'];
                                                    $ts_voice_port = 9987;
                                                    $parts = explode(':', $ts_host);
                                                    if (count($parts) === 2) {
                                                        $ts_host = $parts[0];
                                                        $ts_voice_port = intval($parts[1]);
                                                    }
                                                    echo '<a href="ts3server://' . htmlspecialchars($ts_host) . '?port=' . htmlspecialchars($ts_voice_port) . '" class="ts-connect-link" title="' . htmlspecialchars(t('ts_connect_title')) . '"><i class="fas fa-external-link-alt" style="font-size: 0.75rem; margin-right: 0.25rem;"></i> ' . htmlspecialchars($monitor['target']) . '</a>';
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
                                                    <span title="<?php echo htmlspecialchars(sprintf(t('minecraft_version_title'), $details['version'] ?? '')); ?>">
                                                        <i class="fas fa-users"></i> <?php echo (int)($details['players_online'] ?? 0); ?> / <?php echo (int)($details['players_max'] ?? 0); ?>
                                                    </span>
                                                <?php elseif ($m_type === 'teamspeak'): ?>
                                                    <span title="<?php echo htmlspecialchars(t('ts_clients_title') . (!empty($details['ip_version']) ? sprintf(t('measured_via_suffix'), $details['ip_version']) : '')); ?>">
                                                        <i class="fas fa-headset"></i> <?php echo (int)($details['clients_online'] ?? 0); ?> / <?php echo (int)($details['clients_max'] ?? 0); ?>
                                                        <?php if (!empty($details['ip_version'])): ?>
                                                            <small style="font-size: 0.65rem; color: var(--text-muted); margin-left: 0.25rem;">(<?php echo htmlspecialchars($details['ip_version']); ?>)</small>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php elseif ($m_type === 'discord'): ?>
                                                    <span>
                                                        <i class="fab fa-discord"></i> <?php echo (int)($details['presence_count'] ?? 0); ?> <?php echo htmlspecialchars(t('discord_online_suffix')); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Sloupec 3: Historie a grafy (HetrixTools styl nebo VPS grafy) -->
                                    <div>
                                        <?php if (($m_type === 'vps' || $m_type === 'openwrt' || $m_type === 'cpanel') && $status === 'up' && (isset($details['cpu']) || isset($details['disk']))): ?>
                                            <!-- Pokud jde o VPS, OpenWrt nebo cPanel, ukážeme rychlé grafy vytížení -->
                                            <div class="metrics-charts">
                                                <?php if ($m_type === 'vps' || $m_type === 'openwrt'): ?>
                                                    <div class="mini-chart">
                                                        <div class="chart-title"><?php echo htmlspecialchars(t('chart_cpu')); ?></div>
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
                                                        <div class="chart-title"><?php echo htmlspecialchars(t('chart_processes')); ?></div>
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
                                                    <div class="chart-title"><?php echo htmlspecialchars(t('chart_ram')); ?></div>
                                                    <div class="chart-bar-container">
                                                        <?php 
                                                        $ram_val = ($m_type === 'vps' || $m_type === 'openwrt') ? $details['ram'] : ($details['memory']['percent'] ?? 0);
                                                        $ram_color = ($ram_val > 85) ? 'red' : (($ram_val > 60) ? 'yellow' : 'green');
                                                        ?>
                                                        <div class="chart-bar-fill <?php echo $ram_color; ?>" style="width: <?php echo $ram_val; ?>%"></div>
                                                    </div>
                                                    <div class="chart-value"><?php echo $ram_val; ?>%</div>
                                                </div>
                                                
                                                <div class="mini-chart">
                                                    <div class="chart-title"><?php echo htmlspecialchars(t('chart_disk')); ?></div>
                                                    <div class="chart-bar-container">
                                                        <?php 
                                                        $hdd_val = ($m_type === 'vps' || $m_type === 'openwrt') ? $details['hdd'] : ($details['disk']['percent'] ?? 0);
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
                                                             $day_text = t('history_tooltip_up');
                                                         } elseif ($day_status === 'down') {
                                                             $day_class = 'down';
                                                             $day_uptime = $history_uptime[$mid][$day] ?? 100.00;
                                                             $day_text = sprintf(t('history_tooltip_down'), number_format($day_uptime, 2, ',', ' '));
                                                         } elseif ($day_status === 'maintenance') {
                                                             $day_class = 'maintenance';
                                                             $day_text = t('history_tooltip_maintenance');
                                                         } else {
                                                             $day_class = 'nodata';
                                                             $day_text = t('history_tooltip_nodata');
                                                         }
                                                         
                                                         $tooltip = "$date_formatted: $day_text";
                                                     ?>
                                                         <div class="history-day <?php echo $day_class; ?>" data-tooltip="<?php echo $tooltip; ?>"></div>
                                                     <?php endforeach; ?>
                                                </div>
                                                <div class="history-labels">
                                                    <span><?php echo htmlspecialchars(t('history_30_days_ago')); ?></span>
                                                    <span><?php echo htmlspecialchars(t('history_today')); ?></span>
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
                                                    echo '<span style="color: var(--color-red);">' . htmlspecialchars(t('resp_unavailable')) . '</span>';
                                                } elseif ($status === 'maintenance') {
                                                    echo '<span style="color: var(--color-yellow);">' . htmlspecialchars(t('resp_maintenance')) . '</span>';
                                                } elseif ($m_type === 'vps') {
                                                    echo '<span style="color: var(--color-green);">' . htmlspecialchars(t('resp_online')) . '</span>';
                                                } else {
                                                    // Zobrazíme odezvu z posledního logu
                                                    $stmt_last_log = $pdo->prepare("SELECT response_time FROM monitor_logs WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 1");
                                                    $stmt_last_log->execute([$mid]);
                                                    $last_log = $stmt_last_log->fetch();
                                                    if ($last_log && $last_log['response_time'] > 0) {
                                                        echo $last_log['response_time'] . ' ms';
                                                    } else {
                                                        echo htmlspecialchars(t('na'));
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
                                            $stmt_last_logs = $pdo->prepare("SELECT checked_at, status, response_time, error_message, checked_from, check_stages FROM monitor_logs WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 5");
                                            $stmt_last_logs->execute([$mid]);
                                            $last_logs = $stmt_last_logs->fetchAll();

                                            // Knowledge layer - vysvětlení aktuálně překročených prahů (viz
                                            // bk_get_knowledge_tips() ve functions.php). Vlastní decode check_stages,
                                            // nezávislý na $pipeline/$ts3_check_stages níže, aby fungoval pro
                                            // jakýkoli typ bez ohledu na to, kde se v šabloně zrovna nacházíme.
                                            $check_stages_shared = null;
                                            if (!empty($last_logs[0]['check_stages'])) {
                                                $decoded_check_stages_shared = json_decode($last_logs[0]['check_stages'], true);
                                                if (is_array($decoded_check_stages_shared)) {
                                                    $check_stages_shared = $decoded_check_stages_shared;
                                                }
                                            }
                                            $knowledge_tips = bk_get_knowledge_tips($monitor, $details, $check_stages_shared, $status, $enabled_metrics);

                                            $stmt_outages = $pdo->prepare("SELECT checked_at, error_message, checked_from FROM monitor_logs WHERE monitor_id = ? AND status = 'down' AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY checked_at DESC LIMIT 5");
                                            $stmt_outages->execute([$mid]);
                                            $monitor_outages = $stmt_outages->fetchAll();

                                            // Distributed View - nejnovější měření z každé lokace zvlášť (posledních 24h)
                                            $stmt_dist = $pdo->prepare("
                                                SELECT l.checked_from, l.response_time, l.status
                                                FROM monitor_logs l
                                                INNER JOIN (
                                                    SELECT checked_from, MAX(checked_at) as max_checked_at
                                                    FROM monitor_logs
                                                    WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND checked_from IS NOT NULL
                                                    GROUP BY checked_from
                                                ) latest ON latest.checked_from = l.checked_from AND latest.max_checked_at = l.checked_at
                                                WHERE l.monitor_id = ?
                                                ORDER BY l.response_time ASC
                                            ");
                                            $stmt_dist->execute([$mid, $mid]);
                                            $distributed_view = $stmt_dist->fetchAll();

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
                                            // Check pipeline (DNS/TCP/TLS/HTTP/body fáze) - jen u typu 'web', z posledního logu.
                                            // Confidence Score kombinuje podíl úspěšných fází s konsensem mezi regiony
                                            // (Distributed View výše) - nikdy nenahrazuje status-dot, jen ho doplňuje.
                                            $pipeline = null;
                                            $confidence_score = null;
                                            if ($m_type === 'web' && !empty($last_logs[0]['check_stages'])) {
                                                $decoded_pipeline = json_decode($last_logs[0]['check_stages'], true);
                                                if (is_array($decoded_pipeline)) {
                                                    $pipeline = $decoded_pipeline;
                                                }
                                            }
                                            $pipeline_stage_order = ['dns', 'tcp', 'tls', 'http', 'body'];
                                            $stage_labels = [
                                                'dns' => t('pipeline_stage_dns'),
                                                'tcp' => t('pipeline_stage_tcp'),
                                                'tls' => t('pipeline_stage_tls'),
                                                'http' => t('pipeline_stage_http'),
                                                'body' => t('pipeline_stage_body'),
                                            ];
                                            if ($pipeline !== null) {
                                                $stages_present = 0;
                                                $stages_ok = 0;
                                                foreach ($pipeline_stage_order as $sk) {
                                                    if (isset($pipeline[$sk])) {
                                                        $stages_present++;
                                                        if (!empty($pipeline[$sk]['ok'])) $stages_ok++;
                                                    }
                                                }
                                                $stage_health = $stages_present > 0 ? ($stages_ok / $stages_present) * 100 : null;

                                                $region_consensus = null;
                                                if (!empty($distributed_view)) {
                                                    $region_up = 0;
                                                    foreach ($distributed_view as $dv) {
                                                        if ($dv['status'] === 'up') $region_up++;
                                                    }
                                                    $region_consensus = ($region_up / count($distributed_view)) * 100;
                                                }

                                                if ($stage_health !== null && $region_consensus !== null) {
                                                    $confidence_score = round(($stage_health * 0.6) + ($region_consensus * 0.4), 2);
                                                } elseif ($stage_health !== null) {
                                                    $confidence_score = round($stage_health, 2);
                                                } elseif ($region_consensus !== null) {
                                                    $confidence_score = round($region_consensus, 2);
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
                                                        <i class="fas fa-tools"></i> <?php echo htmlspecialchars(t('maintenance_heading')); ?>
                                                    </strong>
                                                    <div style="color: #e1e1e6; margin-bottom: 0.35rem;">
                                                        <strong><?php echo htmlspecialchars(t('maintenance_duration')); ?></strong> <?php echo htmlspecialchars(sprintf(t('maintenance_duration_range'), date('d.m.Y H:i', strtotime($monitor['maintenance_start'])), date('d.m.Y H:i', strtotime($monitor['maintenance_end'])))); ?>
                                                    </div>
                                                    <?php if (!empty($monitor['maintenance_description'])): ?>
                                                        <div style="color: var(--text-secondary); line-height: 1.4;"><?php echo htmlspecialchars($monitor['maintenance_description']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (count($distributed_view) > 1): ?>
                                                <div class="distributed-view" style="margin-bottom: 1.25rem;">
                                                    <div class="detail-section-title"><i class="fas fa-globe-europe"></i> Distributed View</div>
                                                    <div class="distributed-view-chips">
                                                        <?php foreach ($distributed_view as $dv): ?>
                                                            <div class="dv-chip dv-<?php echo $dv['status'] === 'up' ? 'up' : 'down'; ?>">
                                                                <span class="dv-dot"></span>
                                                                <span class="dv-location"><?php echo htmlspecialchars($dv['checked_from']); ?></span>
                                                                <?php if ($dv['response_time'] !== null): ?>
                                                                    <span class="dv-latency"><?php echo (int)$dv['response_time']; ?>&nbsp;ms</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($pipeline !== null): ?>
                                                <?php if ($enabled_metrics === null || in_array('check_pipeline', $enabled_metrics)): ?>
                                                <div class="check-pipeline-section" style="margin-bottom: 1.25rem;">
                                                    <div class="detail-section-title">
                                                        <i class="fas fa-route"></i> <?php echo htmlspecialchars(t('pipeline_heading')); ?>
                                                        <?php if ($confidence_score !== null): ?>
                                                            <span style="font-weight: normal; font-size: 0.78rem; color: var(--text-muted); margin-left: 0.5rem;">
                                                                &middot; <?php echo htmlspecialchars(t('confidence_score_label')); ?>:
                                                                <strong style="color: <?php echo $confidence_score >= 90 ? 'var(--color-green)' : ($confidence_score >= 70 ? 'var(--color-yellow)' : 'var(--color-red)'); ?>;"><?php echo number_format($confidence_score, 2, ',', ' '); ?>%</strong>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.6rem;">
                                                        <?php $pipeline_first = true; ?>
                                                        <?php foreach ($pipeline_stage_order as $sk): ?>
                                                            <?php if (!isset($pipeline[$sk])) continue; ?>
                                                            <?php
                                                                $stage_ok = !empty($pipeline[$sk]['ok']);
                                                                $chip_title = '';
                                                                if ($sk === 'http' && isset($pipeline[$sk]['status_code'])) {
                                                                    $chip_title = 'HTTP ' . $pipeline[$sk]['status_code'];
                                                                } elseif ($sk === 'tls' && isset($pipeline[$sk]['cert']['days_remaining'])) {
                                                                    $chip_title = $pipeline[$sk]['cert']['days_remaining'] . 'd';
                                                                }
                                                            ?>
                                                            <?php if (!$pipeline_first): ?><i class="fas fa-arrow-right" style="color: var(--text-muted); font-size: 0.65rem;"></i><?php endif; ?>
                                                            <div style="display: flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.65rem; border-radius: 6px; font-size: 0.78rem; background: <?php echo $stage_ok ? 'rgba(30, 199, 115, 0.08)' : 'rgba(239, 35, 60, 0.08)'; ?>; border: 1px solid <?php echo $stage_ok ? 'rgba(30, 199, 115, 0.2)' : 'rgba(239, 35, 60, 0.2)'; ?>;" <?php if ($chip_title !== ''): ?>title="<?php echo htmlspecialchars($chip_title); ?>"<?php endif; ?>>
                                                                <i class="fas <?php echo $stage_ok ? 'fa-check-circle' : 'fa-times-circle'; ?>" style="color: <?php echo $stage_ok ? 'var(--color-green)' : 'var(--color-red)'; ?>;"></i>
                                                                <span><?php echo htmlspecialchars($stage_labels[$sk]); ?></span>
                                                            </div>
                                                            <?php $pipeline_first = false; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <?php if ($enabled_metrics === null || in_array('response_breakdown', $enabled_metrics)): ?>
                                                <div class="response-breakdown-section" style="margin-bottom: 1.25rem;">
                                                    <div class="detail-section-title"><i class="fas fa-stopwatch"></i> <?php echo htmlspecialchars(t('response_breakdown_heading')); ?></div>
                                                    <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                        <?php foreach ($pipeline_stage_order as $sk): ?>
                                                            <?php if (!isset($pipeline[$sk]['time_ms'])) continue; ?>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                <span style="color: var(--text-muted);"><?php echo htmlspecialchars($stage_labels[$sk]); ?>:</span>
                                                                <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)$pipeline[$sk]['time_ms']; ?>&nbsp;ms</strong>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php if (isset($pipeline['total_time_ms'])): ?>
                                                            <div style="background: rgba(193, 18, 31, 0.06); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(193, 18, 31, 0.15);">
                                                                <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('response_total')); ?>:</span>
                                                                <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)$pipeline['total_time_ms']; ?>&nbsp;ms</strong>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php endif; ?>

                                                <?php if (!empty($pipeline['tls']['cert']) && ($enabled_metrics === null || in_array('ssl_card', $enabled_metrics))): $cert = $pipeline['tls']['cert']; ?>
                                                    <div class="ssl-card-section" style="margin-bottom: 1.25rem;">
                                                        <div class="detail-section-title"><i class="fas fa-lock"></i> <?php echo htmlspecialchars(t('ssl_card_heading')); ?></div>
                                                        <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                            <?php if (!empty($cert['issuer'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ssl_issuer')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($cert['issuer']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($cert['algo'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ssl_algo')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($cert['algo']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($cert['valid_to'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ssl_valid_until')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars(date('d.m.Y', strtotime($cert['valid_to']))); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (isset($cert['days_remaining'])): ?>
                                                                <?php $days_color = $cert['days_remaining'] < 14 ? 'var(--color-red)' : ($cert['days_remaining'] < 30 ? 'var(--color-yellow)' : 'var(--color-green)'); ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ssl_days_remaining')); ?>:</span>
                                                                    <strong style="color: <?php echo $days_color; ?>; margin-left: 0.25rem;"><?php echo (int)$cert['days_remaining']; ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (isset($pipeline['http']) && ($enabled_metrics === null || in_array('headers', $enabled_metrics))): ?>
                                                    <details class="headers-section" style="margin-bottom: 1.25rem;">
                                                        <summary class="detail-section-title" style="cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem;"><i class="fas fa-list"></i> <?php echo htmlspecialchars(t('headers_heading')); ?></summary>
                                                        <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                            <?php $ph = $pipeline['http']['headers'] ?? []; ?>
                                                            <?php if (!empty($ph['server'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('header_server')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ph['server']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($ph['cache_control'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('header_cache_control')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ph['cache_control']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($ph['content_encoding'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('header_content_encoding')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ph['content_encoding']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($details['http_version'])): ?>
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('header_http_version')); ?>:</span>
                                                                    <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($details['http_version']); ?></strong>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </details>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($m_type === 'minecraft'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('server_info_heading')); ?></div>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_version')); ?></strong> <span style="color: #fff;"><?php echo htmlspecialchars($details['version'] ?? t('unknown')); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_motd')); ?></strong> <span style="color: #fff; font-style: italic; font-weight: 500;"><?php echo htmlspecialchars($details['motd'] ?? t('no_description')); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_check_frequency')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_check')); ?></strong> <span class="stat-val"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : t('never'); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_status_change')); ?></strong> <span class="stat-val"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                        <p><strong><?php echo htmlspecialchars(t('field_uptime_30d')); ?></strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight:bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                        <?php 
                                                        $mc_ll = $last_logs[0] ?? null;
                                                        if ($mc_ll && $mc_ll['checked_from']): 
                                                        ?>
                                                        <p style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-muted);"><i class="fas fa-map-marker-alt" style="color:var(--color-red);font-size:0.7rem;"></i> <?php echo htmlspecialchars(t('measured_from')); ?> <strong><?php echo htmlspecialchars($mc_ll['checked_from']); ?></strong></p>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (isset($details['cpu'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_load_heading')); ?></div>
                                                            <?php echo render_vps_agent_details($details, $monitor); ?>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('linked_agent_heading')); ?></div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;"><?php echo htmlspecialchars(t('agent_key_label')); ?></div>
                                                                <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                
                                                                <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a></div>
                                                        <?php endif; ?>

                                                    </div>
                                                    <div>
                                                        <div class="detail-section-title">
                                                            <span><i class="fas fa-users"></i> <?php echo htmlspecialchars(t('online_players_heading')); ?></span>
                                                            <span class="category-badge"><?php echo count($details['players_list'] ?? []); ?> <?php echo htmlspecialchars(t('discord_online_suffix')); ?></span>
                                                        </div>
                                                        <?php if (empty($details['players_list'])): ?>
                                                            <p style="color: var(--text-muted); font-style: italic;"><?php echo htmlspecialchars(t('no_players_online')); ?></p>
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
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('server_info_heading')); ?></div>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_ts_server_name')); ?></strong> <span style="color: #fff; font-weight: 600;"><?php echo htmlspecialchars($details['name'] ?? t('default_ts_server_name')); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong>Verze serveru:</strong> <span style="color: #fff;"><?php echo htmlspecialchars($details['version'] ?? t('unknown')); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_check_frequency')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_check')); ?></strong> <span class="stat-val"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : t('never'); ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_status_change')); ?></strong> <span class="stat-val"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                        <p><strong><?php echo htmlspecialchars(t('field_uptime_30d')); ?></strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight:bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                        <?php 
                                                        $ts_ll = $last_logs[0] ?? null;
                                                        if ($ts_ll): 
                                                        ?>
                                                        <p style="margin-top:0.5rem;font-size:0.78rem;color:var(--text-muted);">
                                                             <i class="fas fa-stopwatch" style="color:var(--color-green);font-size:0.7rem;"></i>
                                                             <?php echo htmlspecialchars(t('ping_prefix')); ?> <?php echo (int)($ts_ll['response_time']); ?> ms
                                                             <?php if ($ts_ll['checked_from']): ?>
                                                                 &nbsp;<i class="fas fa-map-marker-alt" style="color:var(--color-red);font-size:0.7rem;"></i>
                                                                 <?php echo htmlspecialchars(t('ping_from')); ?> <strong><?php echo htmlspecialchars($ts_ll['checked_from']); ?></strong>
                                                             <?php endif; ?>
                                                         </p>
                                                         <p style="font-size:0.73rem;color:var(--text-muted);margin-top:0.2rem;">
                                                             <i class="fas fa-info-circle"></i>
                                                             <?php echo htmlspecialchars(t('ping_explanation')); ?>
                                                             <?php if ($ts_ll['response_time'] > 500): ?>
                                                             <?php echo htmlspecialchars(t('ping_high_warning')); ?>
                                                             <?php endif; ?>
                                                         </p>
                                                         <?php endif; ?>
                                                         
                                                         <?php if (isset($details['cpu'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_load_heading')); ?></div>
                                                             <?php echo render_vps_agent_details($details, $monitor); ?>
                                                         <?php endif; ?>
                                                         
                                                         <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('linked_agent_heading')); ?></div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                 <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;"><?php echo htmlspecialchars(t('agent_key_label')); ?></div>
                                                                 <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                 
                                                                 <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a></div>
                                                         <?php endif; ?>

                                                     </div>
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-users"></i> <?php echo htmlspecialchars(t('connected_clients_heading')); ?></div>
                                                         <p style="font-size: 1.2rem; font-weight: bold; color: #fff; font-family: var(--font-header);">
                                                             <?php echo (int)($details['clients_online'] ?? 0); ?> / <?php echo (int)($details['clients_max'] ?? 0); ?>
                                                         </p>
                                                         <p style="color: var(--text-muted); margin-top: 0.25rem;">
                                                              <?php echo htmlspecialchars(t('ts_clients_desc')); ?>
                                                              <?php if (!empty($details['ip_version'])): ?>
                                                                  <br><span style="font-size: 0.72rem; color: var(--color-green);"><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars(t('measured_via')); ?> <?php echo htmlspecialchars($details['ip_version']); ?><?php echo ($is_admin && !empty($details['checked_ip'])) ? ' (' . htmlspecialchars($details['checked_ip']) . ')' : ''; ?></span>
                                                              <?php endif; ?>
                                                          </p>
                                                     </div>
                                                 </div>

                                                 <?php
                                                 // --- TeamSpeak Health Score + hloubkový monitoring (Service Profile) ---
                                                 $ts3_check_stages = null;
                                                 if (!empty($last_logs[0]['check_stages'])) {
                                                     $decoded_ts3_stages = json_decode($last_logs[0]['check_stages'], true);
                                                     if (is_array($decoded_ts3_stages)) {
                                                         $ts3_check_stages = $decoded_ts3_stages;
                                                     }
                                                 }
                                                 $ts3_health_areas = build_teamspeak_health_areas($monitor, $status, $ts3_check_stages, $details);
                                                 $ts3_health = bk_compute_health_score($ts3_health_areas);
                                                 $ts3_voice_quality = bk_ts3_voice_quality($pdo, $mid);
                                                 $ts3_status_labels = [
                                                     'ok' => t('ts3_health_status_ok'),
                                                     'warn' => t('ts3_health_status_warn'),
                                                     'fail' => t('ts3_health_status_fail'),
                                                     'na' => t('ts3_health_status_na'),
                                                 ];
                                                 $ts3_status_colors = [
                                                     'ok' => 'var(--color-green)',
                                                     'warn' => 'var(--color-yellow)',
                                                     'fail' => 'var(--color-red)',
                                                     'na' => 'var(--text-muted)',
                                                 ];
                                                 $ts3_score_color = $ts3_health['score'] >= 90 ? 'var(--color-green)' : ($ts3_health['score'] >= 70 ? 'var(--color-yellow)' : 'var(--color-red)');
                                                 ?>

                                                 <?php if ($enabled_metrics === null || in_array('health_score', $enabled_metrics)): ?>
                                                 <div class="ts3-health-score-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                     <div class="detail-section-title">
                                                         <i class="fas fa-heartbeat"></i> <?php echo htmlspecialchars(t('ts3_health_score_heading')); ?>
                                                         <span style="font-weight: normal; font-size: 0.9rem; margin-left: 0.5rem; color: <?php echo $ts3_score_color; ?>;"><strong><?php echo $ts3_health['score']; ?></strong> / 100</span>
                                                     </div>
                                                     <div style="overflow-x: auto; margin-top: 0.6rem;">
                                                         <table class="report-table" style="width: 100%; border-collapse: collapse; font-size: 0.8rem;">
                                                             <thead>
                                                                 <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted);">
                                                                     <th style="padding: 0.4rem 0.5rem; text-align: left;"><?php echo htmlspecialchars(t('ts3_health_area_column')); ?></th>
                                                                     <th style="padding: 0.4rem 0.5rem; text-align: right;"><?php echo htmlspecialchars(t('ts3_health_weight_column')); ?></th>
                                                                     <th style="padding: 0.4rem 0.5rem; text-align: right;"><?php echo htmlspecialchars(t('ts3_health_status_column')); ?></th>
                                                                 </tr>
                                                             </thead>
                                                             <tbody>
                                                                 <?php
                                                                 $ts3_area_label_keys = [
                                                                     'availability' => 'ts3_health_area_availability',
                                                                     'process' => 'ts3_health_area_process',
                                                                     'serverquery' => 'ts3_health_area_serverquery',
                                                                     'ports' => 'ts3_health_area_ports',
                                                                     'vps' => 'ts3_health_area_vps',
                                                                     'clients' => 'ts3_health_area_clients',
                                                                     'version' => 'ts3_health_area_version',
                                                                 ];
                                                                 ?>
                                                                 <?php foreach ($ts3_health_areas as $area): ?>
                                                                     <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                                                         <td style="padding: 0.4rem 0.5rem;"><?php echo htmlspecialchars(t($ts3_area_label_keys[$area['key']] ?? $area['label'])); ?></td>
                                                                         <td style="padding: 0.4rem 0.5rem; text-align: right; color: var(--text-muted);"><?php echo (int)$area['weight_pct']; ?>%</td>
                                                                         <td style="padding: 0.4rem 0.5rem; text-align: right; color: <?php echo $ts3_status_colors[$area['status']] ?? '#fff'; ?>;"><?php echo htmlspecialchars($ts3_status_labels[$area['status']] ?? $area['status']); ?></td>
                                                                     </tr>
                                                                 <?php endforeach; ?>
                                                             </tbody>
                                                         </table>
                                                     </div>
                                                 </div>
                                                 <?php endif; ?>

                                                 <?php if ($ts3_check_stages !== null): ?>
                                                     <?php if (is_array($details['ts3_process'] ?? null) && ($enabled_metrics === null || in_array('process', $enabled_metrics))): $tsp = $details['ts3_process']; ?>
                                                         <div class="ts3-process-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                             <div class="detail-section-title"><i class="fas fa-microchip"></i> <?php echo htmlspecialchars(t('ts3_process_heading')); ?></div>
                                                             <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_process_status')); ?>:</span> <strong style="color: var(--color-green); margin-left: 0.25rem;"><?php echo htmlspecialchars(t('ts3_process_running')); ?></strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_process_uptime')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars(format_uptime_cz((int)($tsp['uptime_sec'] ?? 0))); ?></strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_process_cpu')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars((string)($tsp['cpu'] ?? 0)); ?>%</strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_process_ram')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars((string)($tsp['ram_mb'] ?? 0)); ?> MB</strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_process_threads')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)($tsp['threads'] ?? 0); ?></strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_process_fds')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)($tsp['open_fds'] ?? 0); ?></strong></div>
                                                             </div>
                                                         </div>
                                                     <?php endif; ?>

                                                     <?php if ($enabled_metrics === null || in_array('service', $enabled_metrics)): ?>
                                                     <div class="ts3-service-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                         <div class="detail-section-title"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('ts3_service_heading')); ?></div>
                                                         <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                             <?php $ts3_svc = $ts3_check_stages['service'] ?? []; ?>
                                                             <?php if (isset($ts3_svc['slot_usage_pct'])): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_service_slots')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo $ts3_svc['slot_usage_pct']; ?>%</strong></div>
                                                             <?php endif; ?>
                                                             <?php if ($ts3_svc['channel_count'] !== null): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_service_channels')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)$ts3_svc['channel_count']; ?></strong></div>
                                                             <?php endif; ?>
                                                             <?php if ($ts3_svc['active_channel_count'] !== null): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_service_active_channels')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)$ts3_svc['active_channel_count']; ?></strong></div>
                                                             <?php endif; ?>
                                                             <?php if ($ts3_svc['query_client_count'] !== null): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_service_query_clients')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)$ts3_svc['query_client_count']; ?></strong></div>
                                                             <?php endif; ?>
                                                             <?php if ($ts3_svc['server_group_count'] !== null): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_service_groups')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)$ts3_svc['server_group_count']; ?></strong></div>
                                                             <?php endif; ?>
                                                         </div>
                                                         <?php if (!empty($ts3_svc['voice_activity'])): $va = $ts3_svc['voice_activity']; ?>
                                                             <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><i class="fas fa-comment" style="color: var(--color-green);"></i> <?php echo htmlspecialchars(t('ts3_voice_talking')); ?>: <strong style="color: #fff;"><?php echo (int)$va['talking']; ?></strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><i class="fas fa-moon" style="color: var(--text-muted);"></i> <?php echo htmlspecialchars(t('ts3_voice_away')); ?>: <strong style="color: #fff;"><?php echo (int)$va['away']; ?></strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><i class="fas fa-microphone-slash" style="color: var(--color-yellow);"></i> <?php echo htmlspecialchars(t('ts3_voice_muted')); ?>: <strong style="color: #fff;"><?php echo (int)$va['muted']; ?></strong></div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><i class="fas fa-circle" style="color: var(--color-red);"></i> <?php echo htmlspecialchars(t('ts3_voice_recording')); ?>: <strong style="color: #fff;"><?php echo (int)$va['recording']; ?></strong></div>
                                                             </div>
                                                         <?php endif; ?>
                                                     </div>
                                                     <?php endif; ?>

                                                     <?php if ($enabled_metrics === null || in_array('quality', $enabled_metrics)): ?>
                                                     <div class="ts3-quality-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                         <div class="detail-section-title"><i class="fas fa-wave-square"></i> <?php echo htmlspecialchars(t('ts3_quality_heading')); ?></div>
                                                         <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_quality_latency')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo (int)($ts3_check_stages['query']['time_ms'] ?? 0); ?> ms</strong></div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                 <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_quality_voice')); ?>:</span>
                                                                 <?php if ($ts3_voice_quality['band'] !== null): ?>
                                                                     <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ts3_voice_quality['band']); ?></strong>
                                                                 <?php else: ?>
                                                                     <strong style="color: var(--text-muted); margin-left: 0.25rem;"><?php echo htmlspecialchars(t('ts3_quality_insufficient_data')); ?></strong>
                                                                 <?php endif; ?>
                                                             </div>
                                                         </div>
                                                         <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.4rem;"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('ts3_quality_estimate_note')); ?></p>
                                                     </div>
                                                     <?php endif; ?>

                                                     <?php if ($enabled_metrics === null || in_array('ports', $enabled_metrics)): ?>
                                                     <div class="ts3-ports-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                         <div class="detail-section-title"><i class="fas fa-plug"></i> <?php echo htmlspecialchars(t('ts3_ports_heading')); ?></div>
                                                         <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.6rem;">
                                                             <?php $ts3_ports = $ts3_check_stages['ports'] ?? []; ?>
                                                             <?php foreach (['query' => 'ts3_port_query', 'filetransfer' => 'ts3_port_filetransfer', 'voice' => 'ts3_port_voice'] as $pk => $pl): ?>
                                                                 <?php $pinfo = $ts3_ports[$pk] ?? null; if ($pinfo === null) continue; $pok = $pinfo['ok']; ?>
                                                                 <div style="display: flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.65rem; border-radius: 6px; font-size: 0.78rem; background: <?php echo $pok === true ? 'rgba(30, 199, 115, 0.08)' : ($pok === false ? 'rgba(239, 35, 60, 0.08)' : 'rgba(255,255,255,0.03)'); ?>; border: 1px solid <?php echo $pok === true ? 'rgba(30, 199, 115, 0.2)' : ($pok === false ? 'rgba(239, 35, 60, 0.2)' : 'rgba(255,255,255,0.08)'); ?>;">
                                                                     <i class="fas <?php echo $pok === true ? 'fa-check-circle' : ($pok === false ? 'fa-times-circle' : 'fa-question-circle'); ?>" style="color: <?php echo $pok === true ? 'var(--color-green)' : ($pok === false ? 'var(--color-red)' : 'var(--text-muted)'); ?>;"></i>
                                                                     <span><?php echo htmlspecialchars(t($pl)); ?></span>
                                                                 </div>
                                                             <?php endforeach; ?>
                                                         </div>
                                                     </div>
                                                     <?php endif; ?>

                                                     <?php if ($enabled_metrics === null || in_array('license_version', $enabled_metrics)): ?>
                                                     <div class="ts3-license-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                         <div class="detail-section-title"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars(t('ts3_license_heading')); ?></div>
                                                         <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 0.6rem; font-size: 0.78rem;">
                                                             <?php if (!empty($ts3_check_stages['license'])): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_license_label')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ts3_check_stages['license']); ?></strong></div>
                                                             <?php endif; ?>
                                                             <?php
                                                             $ts3_current_version = $ts3_check_stages['version'] ?? '';
                                                             $ts3_latest_version = trim((string)get_setting('ts3_latest_version', ''));
                                                             ?>
                                                             <?php if ($ts3_current_version !== ''): ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_version_current')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ts3_current_version); ?></strong></div>
                                                             <?php endif; ?>
                                                             <?php if ($ts3_latest_version !== '' && $ts3_current_version !== ''): ?>
                                                                 <?php $ts3_up_to_date = version_compare($ts3_current_version, $ts3_latest_version, '>='); ?>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                     <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ts3_version_latest')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars($ts3_latest_version); ?></strong>
                                                                     <?php if (!$ts3_up_to_date): ?>
                                                                         <span style="color: var(--color-yellow); margin-left: 0.4rem;"><i class="fas fa-arrow-up"></i> <?php echo htmlspecialchars(t('ts3_version_update_available')); ?></span>
                                                                     <?php else: ?>
                                                                         <span style="color: var(--color-green); margin-left: 0.4rem;"><i class="fas fa-check"></i> <?php echo htmlspecialchars(t('ts3_version_up_to_date')); ?></span>
                                                                     <?php endif; ?>
                                                                 </div>
                                                             <?php endif; ?>
                                                         </div>
                                                     </div>
                                                     <?php endif; ?>

                                                     <?php
                                                     // --- Graf historie klientů (24 hodin) - jednoduchý statický graf bez
                                                     // přepínače období (na rozdíl od hlavního metrics-history grafu výše).
                                                     $stmt_ts3_clients_hist = $pdo->prepare("
                                                         SELECT checked_at, ts_clients_online
                                                         FROM vps_metrics
                                                         WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND ts_clients_online IS NOT NULL
                                                         ORDER BY checked_at ASC
                                                     ");
                                                     $stmt_ts3_clients_hist->execute([$mid]);
                                                     $ts3_clients_hist = $stmt_ts3_clients_hist->fetchAll();
                                                     $ts3_clients_labels = [];
                                                     $ts3_clients_data = [];
                                                     foreach ($ts3_clients_hist as $ch) {
                                                         $ts3_clients_labels[] = date('H:i', strtotime($ch['checked_at']));
                                                         $ts3_clients_data[] = (int)$ch['ts_clients_online'];
                                                     }
                                                     ?>
                                                     <?php if (count($ts3_clients_data) > 1 && ($enabled_metrics === null || in_array('clients_chart', $enabled_metrics))): ?>
                                                         <div class="ts3-clients-chart-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                             <div class="detail-section-title"><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars(t('ts3_clients_chart_heading')); ?></div>
                                                             <div style="position: relative; height: 180px; width: 100%; margin-top: 0.6rem;">
                                                                 <canvas id="ts3ClientsChart-<?php echo $mid; ?>"></canvas>
                                                             </div>
                                                         </div>
                                                         <script>
                                                         document.addEventListener("DOMContentLoaded", function() {
                                                             const ctx = document.getElementById('ts3ClientsChart-<?php echo $mid; ?>');
                                                             if (!ctx) return;
                                                             new Chart(ctx, {
                                                                 type: 'line',
                                                                 data: {
                                                                     labels: <?php echo json_encode($ts3_clients_labels); ?>,
                                                                     datasets: [{
                                                                         label: 'Clients',
                                                                         data: <?php echo json_encode($ts3_clients_data); ?>,
                                                                         borderColor: '#1ec773',
                                                                         backgroundColor: 'rgba(30, 199, 115, 0.08)',
                                                                         borderWidth: 2,
                                                                         pointRadius: 0,
                                                                         tension: 0.3,
                                                                         fill: true
                                                                     }]
                                                                 },
                                                                 options: {
                                                                     responsive: true,
                                                                     maintainAspectRatio: false,
                                                                     plugins: { legend: { display: false } },
                                                                     scales: {
                                                                         x: { grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#8b8ba0', maxTicksLimit: 8, font: { size: 10 } } },
                                                                         y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.03)' }, ticks: { color: '#8b8ba0', font: { size: 10 }, precision: 0 } }
                                                                     }
                                                                 }
                                                             });
                                                         });
                                                         </script>
                                                     <?php endif; ?>
                                                 <?php endif; ?>
                                            <?php elseif ($m_type === 'discord'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-volume-up"></i> <?php echo htmlspecialchars(t('voice_channels_heading')); ?></div>
                                                        <?php if (empty($details['voice_channels'])): ?>
                                                            <p style="color: var(--text-muted); font-style: italic;"><?php echo htmlspecialchars(t('no_voice_activity')); ?></p>
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
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('linked_agent_heading')); ?></div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;"><?php echo htmlspecialchars(t('agent_key_label')); ?></div>
                                                                <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                
                                                                <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a></div>
                                                        <?php endif; ?>

                                                    </div>
                                                    <div>
                                                        <div class="detail-section-title">
                                                            <span><i class="fab fa-discord"></i> <?php echo htmlspecialchars(t('online_users_heading')); ?></span>
                                                            <?php if (!empty($details['instant_invite'])): ?>
                                                                <a href="<?php echo htmlspecialchars($details['instant_invite']); ?>" target="_blank" class="category-badge" style="color: var(--color-green); border-color: rgba(30,199,115,0.3); background: rgba(30,199,115,0.05); font-size: 0.7rem;"><i class="fas fa-external-link-alt"></i> <?php echo htmlspecialchars(t('join_invite')); ?></a>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if (empty($details['members'])): ?>
                                                            <p style="color: var(--text-muted); font-style: italic;"><?php echo htmlspecialchars(t('no_members_online')); ?></p>
                                                        <?php else: ?>
                                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; max-height: 180px; overflow-y: auto; padding-right: 0.5rem;">
                                                                <?php foreach ($details['members'] as $m): 
                                                                    $status_class = $m['status'] ?? 'online';
                                                                    $game_text = !empty($m['game']) ? sprintf(t('playing_suffix'), htmlspecialchars($m['game'])) : '';
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
                                                         <div class="detail-section-title"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('hosting_info_heading')); ?></div>
                                                          <?php 
                                                          $parsed_url = parse_url($monitor['target']);
                                                          $display_target = ($parsed_url['host'] ?? $monitor['target']);
                                                          ?>
                                                          <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_target')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo htmlspecialchars($display_target); ?></span></p>
                                                         <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_check_frequency')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                         <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_check')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : t('never'); ?></span></p>
                                                         <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_status_change')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                         <p><strong><?php echo htmlspecialchars(t('field_uptime_30d')); ?></strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight: bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                         
                                                         <?php if ($m_type === 'web' && $status === 'up' && $details): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars(t('web_network_params_heading')); ?></div>
                                                             <div class="network-params-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.8rem;">
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                     <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('field_protocol_version')); ?></div>
                                                                     <strong style="color: #fff;"><?php echo htmlspecialchars(($details['scheme'] ?? 'HTTP') . '/' . ($details['http_version'] ?? '1.1')); ?></strong>
                                                                 </div>
                                                                 <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                     <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('field_website_ip')); ?></div>
                                                                     <strong style="color: #fff;"><?php echo htmlspecialchars($details['primary_ip'] ?? 'N/A'); ?></strong>
                                                                 </div>
                                                             </div>
                                                         <?php endif; ?>

                                                         <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('linked_agent_heading')); ?></div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                 <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;"><?php echo htmlspecialchars(t('agent_key_label')); ?></div>
                                                                 <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>

                                                                 <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a></div>
                                                         <?php endif; ?>

                                                     </div>
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-chart-pie"></i> <?php echo htmlspecialchars(t('hosting_limits_heading')); ?></div>
                                                         <?php if ($status === 'up' && $cp_details): ?>
                                                             <div style="display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.5rem;">
                                                                 <?php 
                                                                 $resources = [
                                                                     t('resource_cpu') => $cp_details['cpu'] ?? null,
                                                                     t('resource_memory') => $cp_details['memory'] ?? null,
                                                                     t('resource_disk') => $cp_details['disk'] ?? null,
                                                                     t('resource_processes') => $cp_details['processes'] ?? null,
                                                                     t('resource_bandwidth') => $cp_details['bandwidth'] ?? null,
                                                                     t('resource_mysql') => $cp_details['database'] ?? null,
                                                                     t('resource_postgresql') => $cp_details['postgresql'] ?? null,
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
                                                             <p style="color: var(--text-muted); font-style: italic;"><?php echo htmlspecialchars(t('hosting_unavailable_during_outage')); ?></p>
                                                         <?php endif; ?>
                                                         
                                                         <?php if (!empty($last_logs)): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-history"></i> <?php echo htmlspecialchars(t('last_5_measurements_heading')); ?></div>
                                                             <div class="mini-logs-list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                                 <?php foreach ($last_logs as $ll): 
                                                                     $ll_status = $ll['status'];
                                                                     $ll_time = date('H:i:s (d.m.)', strtotime($ll['checked_at']));
                                                                     $ll_badge_class = ($ll_status === 'up') ? 'log-status up' : 'log-status down';
                                                                     $ll_badge_text = ($ll_status === 'up') ? t('resp_online') : t('stat_down');
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
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('agent_info_heading')); ?></div>
                                                         <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_check_frequency')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_update')); ?></strong> <span style="color: #fff;"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : t('never'); ?></span></p>
                                                        <p><strong><?php echo htmlspecialchars(t('field_last_status_change')); ?></strong> <span style="color: #fff;"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                    </div>
                                                    <div>
                                                        <?php if (isset($details['cpu'])): ?>
                                                            <div class="detail-section-title"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_load_heading')); ?></div>
                                                            <?php echo render_vps_agent_details($details, $monitor); ?>
                                                        <?php else: ?>
                                                            <div class="detail-section-title"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_agent_heading')); ?></div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem; color: var(--text-muted); font-style: italic;">
                                                                <?php echo htmlspecialchars(t('vps_waiting_for_data')); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($is_admin): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-key"></i> <?php echo htmlspecialchars(t('agent_unique_key_heading')); ?></div>
                                                            <code style="background: rgba(0,0,0,0.5); padding: 0.35rem 0.6rem; border-radius: 6px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.75rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                            
                                                            <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php elseif ($m_type === 'openwrt'): ?>
                                                <div class="game-details-grid">
                                                    <div>
                                                        <div class="detail-section-title"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('agent_info_heading')); ?></div>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_check_frequency')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                        <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_update')); ?></strong> <span style="color: #fff;"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : t('never'); ?></span></p>
                                                        <p><strong><?php echo htmlspecialchars(t('field_last_status_change')); ?></strong> <span style="color: #fff;"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>

                                                        <?php if (!empty($details['model']) || !empty($details['board_name'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-microchip"></i> <?php echo htmlspecialchars(t('openwrt_router_heading')); ?></div>
                                                            <?php if (!empty($details['model'])): ?>
                                                                <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('openwrt_model')); ?></strong> <span style="color: #fff;"><?php echo htmlspecialchars($details['model']); ?></span></p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($details['board_name'])): ?>
                                                                <p><strong><?php echo htmlspecialchars(t('openwrt_board')); ?></strong> <span style="color: #fff;"><?php echo htmlspecialchars($details['board_name']); ?></span></p>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <?php if (isset($details['wan_up'])): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-globe-europe"></i> <?php echo htmlspecialchars(t('openwrt_wan_heading')); ?></div>
                                                            <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; font-size: 0.78rem;">
                                                                <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_status')); ?>:</span>
                                                                    <strong style="color: <?php echo $details['wan_up'] ? 'var(--color-green)' : 'var(--color-red)'; ?>; margin-left: 0.25rem;"><?php echo $details['wan_up'] ? htmlspecialchars(t('openwrt_wan_up')) : htmlspecialchars(t('openwrt_wan_down')); ?></strong>
                                                                </div>
                                                                <?php if (!empty($details['wan_proto'])): ?>
                                                                    <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_proto')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars(strtoupper($details['wan_proto'])); ?></strong></div>
                                                                <?php endif; ?>
                                                                <?php // IP adresy/brána/DNS jsou identifikující údaje o síti uživatele -
                                                                // zobrazují se jen přihlášenému adminovi, ne veřejně. ?>
                                                                <?php if ($is_admin && !empty($details['wan_ipv4'])): ?>
                                                                    <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);" title="<?php echo htmlspecialchars(t('openwrt_wan_ipv4_hint')); ?>"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_ipv4')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem; font-family: monospace;"><?php echo htmlspecialchars($details['wan_ipv4']); ?></strong></div>
                                                                <?php endif; ?>
                                                                <?php if ($is_admin && !empty($details['wan_ipv6'])): ?>
                                                                    <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_ipv6')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem; font-family: monospace;"><?php echo htmlspecialchars($details['wan_ipv6']); ?></strong></div>
                                                                <?php endif; ?>
                                                                <?php if ($is_admin && !empty($details['wan_gateway'])): ?>
                                                                    <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_gateway')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem; font-family: monospace;"><?php echo htmlspecialchars($details['wan_gateway']); ?></strong></div>
                                                                <?php endif; ?>
                                                                <?php if ($is_admin && !empty($details['wan_dns'])): ?>
                                                                    <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_dns')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem; font-family: monospace;"><?php echo htmlspecialchars($details['wan_dns']); ?></strong></div>
                                                                <?php endif; ?>
                                                                <?php if (!empty($details['wan_uptime'])): ?>
                                                                    <div style="background: rgba(255,255,255,0.03); padding: 0.4rem 0.65rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);"><span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('openwrt_wan_uptime')); ?>:</span> <strong style="color: #fff; margin-left: 0.25rem;"><?php echo htmlspecialchars(format_uptime_cz((int)$details['wan_uptime'])); ?></strong></div>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <?php if (isset($details['cpu'])): ?>
                                                            <div class="detail-section-title"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_load_heading')); ?></div>
                                                            <?php echo render_vps_agent_details($details, $monitor); ?>
                                                        <?php else: ?>
                                                            <div class="detail-section-title"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_agent_heading')); ?></div>
                                                            <div style="background: rgba(255,255,255,0.03); padding: 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem; color: var(--text-muted); font-style: italic;">
                                                                <?php echo htmlspecialchars(t('vps_waiting_for_data')); ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if ($is_admin): ?>
                                                            <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-key"></i> <?php echo htmlspecialchars(t('agent_unique_key_heading')); ?></div>
                                                            <code style="background: rgba(0,0,0,0.5); padding: 0.35rem 0.6rem; border-radius: 6px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.75rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>

                                                            <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="game-details-grid">
                                                     <div>
                                                          <div class="detail-section-title"><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars(t('monitor_stats_heading')); ?></div>
                                                          <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_target')); ?></strong> <span style="color: #fff;"><?php echo htmlspecialchars($monitor['target']) . ($monitor['type'] !== 'teamspeak' && $monitor['port'] ? ':'.$monitor['port'] : ''); ?></span></p>
                                                          <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_check_frequency')); ?></strong> <span style="color: #fff;" class="stat-val"><?php echo $freq_text; ?></span></p>
                                                          <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_check')); ?></strong> <span style="color: #fff;"><?php echo $monitor['last_checked'] ? date('d.m.Y H:i:s', strtotime($monitor['last_checked'])) : t('never'); ?></span></p>
                                                          <p style="margin-bottom: 0.5rem;"><strong><?php echo htmlspecialchars(t('field_last_status_change')); ?></strong> <span style="color: #fff;"><?php echo $monitor['last_status_change'] ? date('d.m.Y H:i:s', strtotime($monitor['last_status_change'])) : 'N/A'; ?></span></p>
                                                          <p><strong><?php echo htmlspecialchars(t('field_uptime_30d')); ?></strong> <span class="uptime-pct <?php echo $uptime_class; ?>" style="font-weight: bold;"><?php echo number_format($uptime, 2, ',', ' '); ?>%</span></p>
                                                          
                                                          <?php 
                                                          if ($monitor['type'] === 'web'): 
                                                              $web_det = json_decode($monitor['last_details'] ?? '', true);
                                                              if (!empty($web_det)):
                                                          ?>
                                                              <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars(t('web_network_params_heading')); ?></div>
                                                              <div class="network-params-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.8rem;">
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('field_protocol')); ?></div>
                                                                      <strong style="color: var(--color-green);"><?php echo htmlspecialchars($web_det['scheme'] ?? 'HTTP'); ?> (<?php echo htmlspecialchars($web_det['http_version'] ?? 'HTTP/1.1'); ?>)</strong>
                                                                  </div>
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('field_primary_ip')); ?></div>
                                                                      <strong style="color: #fff; font-size: 0.7rem; word-break: break-all;"><?php echo htmlspecialchars($web_det['primary_ip'] ?? 'N/A'); ?></strong>
                                                                  </div>
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('field_ipv4')); ?></div>
                                                                      <strong style="color: <?php echo (!empty($web_det['has_ipv4']) ? 'var(--color-green)' : 'var(--text-muted)'); ?>;">
                                                                          <?php echo (!empty($web_det['has_ipv4']) ? '<i class="fas fa-check-circle" style="font-size: 0.75rem;"></i> ' . htmlspecialchars(t('yes')) : '<i class="fas fa-times-circle" style="font-size: 0.75rem;"></i> ' . htmlspecialchars(t('no'))); ?>
                                                                      </strong>
                                                                  </div>
                                                                  <div style="background: rgba(255,255,255,0.03); padding: 0.4rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                                      <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('field_ipv6')); ?></div>
                                                                      <strong style="color: <?php echo (!empty($web_det['has_ipv6']) ? 'var(--color-green)' : 'var(--color-yellow)'); ?>;">
                                                                          <?php echo (!empty($web_det['has_ipv6']) ? '<i class="fas fa-check-circle" style="font-size: 0.75rem;"></i> ' . htmlspecialchars(t('yes')) : '<i class="fas fa-exclamation-triangle" style="font-size: 0.75rem;"></i> ' . htmlspecialchars(t('missing'))); ?>
                                                                      </strong>
                                                                  </div>
                                                              </div>
                                                          <?php 
                                                              endif;
                                                          endif; 
                                                          ?>
                                                          
                                                          <?php if (isset($details['cpu'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-server"></i> <?php echo htmlspecialchars(t('vps_load_heading')); ?></div>
                                                             <?php echo render_vps_agent_details($details, $monitor); ?>
                                                         <?php endif; ?>
                                                          
                                                          <?php if ($is_admin && !empty($monitor['agent_key'])): ?>
                                                             <div class="detail-section-title" style="margin-top: 1.25rem;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('linked_agent_heading')); ?></div>
                                                             <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); font-size: 0.8rem;">
                                                                 <div style="color: var(--text-muted); font-size: 0.65rem; text-transform: uppercase; margin-bottom: 0.25rem;"><?php echo htmlspecialchars(t('agent_key_label')); ?></div>
                                                                 <code style="background: rgba(0,0,0,0.5); padding: 0.2rem 0.4rem; border-radius: 4px; border: 1px solid var(--border-color); color: var(--color-green); font-size: 0.75rem; display: block; word-break: break-all; font-family: monospace; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($monitor['agent_key']); ?></code>
                                                                 
                                                                 <a href="admin.php?show_agent=<?php echo $mid; ?>" class="btn-install-agent" style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.4rem 0.75rem; border-radius: 6px; font-size: 0.75rem; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; width: 100%; box-sizing: border-box;"><i class="fas fa-terminal"></i> <?php echo htmlspecialchars(t('agent_install_guide')); ?></a></div>
                                                         <?php endif; ?>

                                                     </div>
                                                     <div>
                                                         <div class="detail-section-title"><i class="fas fa-history"></i> <?php echo htmlspecialchars(t('last_5_measurements_heading')); ?></div>
                                                         <?php if (empty($last_logs)): ?>
                                                             <p style="color: var(--text-muted); font-style: italic;"><?php echo htmlspecialchars(t('no_measurements_yet')); ?></p>
                                                         <?php else: ?>
                                                             <div class="mini-logs-list" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                                                 <?php foreach ($last_logs as $ll): 
                                                                     $ll_status = $ll['status'];
                                                                     $ll_time = date('H:i:s (d.m.)', strtotime($ll['checked_at']));
                                                                     $ll_badge_class = ($ll_status === 'up') ? 'log-status up' : 'log-status down';
                                                                     $ll_badge_text = ($ll_status === 'up') ? t('resp_online') : t('stat_down');
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
                                            <?php echo render_knowledge_panel($knowledge_tips); ?>
                                            <?php echo render_insights_panel(array_merge(bk_get_forecast_insights($pdo, $monitor), bk_get_anomaly_insights($pdo, $monitor))); ?>
                                            <?php
                                            // Query metrics history for the charts
                                            $show_charts = false;
                                            $cpu_avg = $cpu_max = $ram_avg = $ram_max = $hdd_avg = $hdd_max = $net_avg = $net_max = 0;
                                            $labels = [];
                                            $cpu_data = [];
                                            $ram_data = [];
                                            $hdd_data = [];
                                            $net_data = [];

                                            if ($m_type === 'vps' || $m_type === 'cpanel' || ($m_type === 'web' && isset($details['cpanel_stats'])) || isset($details['cpu'])) {
                                                $stmt_metrics_history = $pdo->prepare("
                                                    SELECT checked_at, cpu_usage, ram_usage, hdd_usage, net_usage
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
                                                    $hdd_sum = 0;
                                                    $hdd_max = 0;
                                                    $net_sum = 0;
                                                    $net_count = 0;
                                                    $count_mh = count($metrics_history);

                                                    foreach ($metrics_history as $mh) {
                                                        $cpu_sum += $mh['cpu_usage'];
                                                        if ($mh['cpu_usage'] > $cpu_max) $cpu_max = $mh['cpu_usage'];

                                                        $ram_sum += $mh['ram_usage'];
                                                        if ($mh['ram_usage'] > $ram_max) $ram_max = $mh['ram_usage'];

                                                        $hdd_sum += $mh['hdd_usage'];
                                                        if ($mh['hdd_usage'] > $hdd_max) $hdd_max = $mh['hdd_usage'];

                                                        if ($mh['net_usage'] !== null) {
                                                            $net_sum += $mh['net_usage'];
                                                            $net_count++;
                                                            if ($mh['net_usage'] > $net_max) $net_max = $mh['net_usage'];
                                                        }

                                                        $labels[] = date('H:i', strtotime($mh['checked_at']));
                                                        $cpu_data[] = $mh['cpu_usage'];
                                                        $ram_data[] = $mh['ram_usage'];
                                                        $hdd_data[] = $mh['hdd_usage'];
                                                        $net_data[] = $mh['net_usage'] !== null ? (float)$mh['net_usage'] : null;
                                                    }

                                                    $cpu_avg = round($cpu_sum / $count_mh, 1);
                                                    $ram_avg = round($ram_sum / $count_mh, 1);
                                                    $hdd_avg = round($hdd_sum / $count_mh, 1);
                                                    $net_avg = $net_count > 0 ? round($net_sum / $net_count, 1) : 0;
                                                }
                                            }
                                            ?>
                                            <?php if ($show_charts): ?>
                                                <div class="metrics-history-charts" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;">
                                                        <div class="detail-section-title" style="margin-bottom: 0;"><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars(t('load_history_heading')); ?></div>
                                                        <div class="chart-period-switch" data-monitor="<?php echo $mid; ?>" style="display: flex; gap: 0.25rem;">
                                                            <button type="button" data-period="24h" class="btn btn-secondary btn-sm active" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><?php echo htmlspecialchars(t('period_24h')); ?></button>
                                                            <button type="button" data-period="7d" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><?php echo htmlspecialchars(t('period_7d')); ?></button>
                                                            <button type="button" data-period="30d" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><?php echo htmlspecialchars(t('period_30d')); ?></button>
                                                        </div>
                                                    </div>
                                                    <div style="display: flex; gap: 1rem; margin: 0.75rem 0 1rem 0; font-size: 0.8rem;">
                                                        <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                            <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('cpu_avg_max')); ?></span>
                                                            <strong style="color: #fff; margin-left: 0.25rem;" id="cpuStats-<?php echo $mid; ?>"><?php echo $cpu_avg; ?>% / <?php echo $cpu_max; ?>%</strong>
                                                        </div>
                                                        <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                            <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('ram_avg_max')); ?></span>
                                                            <strong style="color: #fff; margin-left: 0.25rem;" id="ramStats-<?php echo $mid; ?>"><?php echo $ram_avg; ?>% / <?php echo $ram_max; ?>%</strong>
                                                        </div>
                                                        <div style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05);">
                                                            <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('hdd_avg_max')); ?></span>
                                                            <strong style="color: #fff; margin-left: 0.25rem;" id="hddStats-<?php echo $mid; ?>"><?php echo $hdd_avg; ?>% / <?php echo $hdd_max; ?>%</strong>
                                                        </div>
                                                        <div class="net-stats-box" id="netStatsBox-<?php echo $mid; ?>" style="background: rgba(255,255,255,0.03); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid rgba(255,255,255,0.05); <?php echo $net_max > 0 ? '' : 'display: none;'; ?>">
                                                            <span style="color: var(--text-muted);"><?php echo htmlspecialchars(t('net_avg_max')); ?></span>
                                                            <strong style="color: #fff; margin-left: 0.25rem;" id="netStats-<?php echo $mid; ?>"><?php echo $net_avg; ?> / <?php echo $net_max; ?> KB/s</strong>
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
                                                                    borderColor: '#c1121f',
                                                                    backgroundColor: 'rgba(193, 18, 31, 0.05)',
                                                                    borderWidth: 2,
                                                                    pointRadius: 0,
                                                                    tension: 0.3,
                                                                    fill: true
                                                                },
                                                                {
                                                                    label: 'RAM (%)',
                                                                    data: <?php echo json_encode($ram_data); ?>,
                                                                    borderColor: '#1ec773',
                                                                    backgroundColor: 'rgba(30, 199, 115, 0.05)',
                                                                    borderWidth: 2,
                                                                    pointRadius: 0,
                                                                    tension: 0.3,
                                                                    fill: true
                                                                },
                                                                {
                                                                    label: 'Disk (%)',
                                                                    data: <?php echo json_encode($hdd_data); ?>,
                                                                    borderColor: '#ffb703',
                                                                    backgroundColor: 'rgba(255, 183, 3, 0.05)',
                                                                    borderWidth: 2,
                                                                    pointRadius: 0,
                                                                    tension: 0.3,
                                                                    fill: true
                                                                },
                                                                {
                                                                    label: 'Síť (KB/s)',
                                                                    data: <?php echo json_encode($net_data); ?>,
                                                                    borderColor: '#8b5cf6',
                                                                    backgroundColor: 'rgba(139, 92, 246, 0.05)',
                                                                    borderWidth: 2,
                                                                    pointRadius: 0,
                                                                    tension: 0.3,
                                                                    fill: false,
                                                                    spanGaps: true,
                                                                    yAxisID: 'y1'
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
                                                                },
                                                                y1: {
                                                                    position: 'right',
                                                                    min: 0,
                                                                    grid: { display: false },
                                                                    ticks: { color: '#8b5cf6', font: { size: 10 } }
                                                                }
                                                            }
                                                        }
                                                    });
                                                });
                                                </script>
                                             <?php endif; ?>
                                             
                                             <?php if (!empty($monitor_outages)): ?>
                                                 <div class="monitor-outages-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
                                                     <div class="detail-section-title" style="color: var(--color-red); margin-bottom: 0.75rem;"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars(t('recent_outages_heading')); ?></div>
                                                     <div style="overflow-x: auto;">
                                                         <table style="width: 100%; border-collapse: collapse; font-size: 0.8rem; text-align: left;">
                                                             <thead>
                                                                 <tr style="border-bottom: 1px solid rgba(255,255,255,0.1); color: var(--text-muted);">
                                                                     <th style="padding: 0.5rem 0.25rem;"><?php echo htmlspecialchars(t('th_time')); ?></th>
                                                                     <th style="padding: 0.5rem 0.25rem;"><?php echo htmlspecialchars(t('th_error_reason')); ?></th>
                                                                     <th style="padding: 0.5rem 0.25rem; text-align: right;"><?php echo htmlspecialchars(t('th_measured_from')); ?></th>
                                                                 </tr>
                                                             </thead>
                                                             <tbody>
                                                                 <?php foreach ($monitor_outages as $mo): 
                                                                     $mo_time = date('d.m.Y H:i:s', strtotime($mo['checked_at']));
                                                                 ?>
                                                                     <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); color: var(--text-secondary);">
                                                                         <td style="padding: 0.5rem 0.25rem; font-weight: 500; color: #fff; white-space: nowrap;"><?php echo $mo_time; ?></td>
                                                                         <td style="padding: 0.5rem 0.25rem; color: var(--color-red); font-style: italic; word-break: break-all;"><?php echo htmlspecialchars($mo['error_message'] ?: t('unspecified_connection_error')); ?></td>
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
                <h2><i class="fas fa-history" style="color: var(--color-red); margin-right: 0.5rem;"></i> <?php echo htmlspecialchars(t('incidents_heading')); ?></h2>
                <div style="overflow-x: auto;">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('th_time')); ?></th>
                                <th><?php echo htmlspecialchars(t('th_monitor')); ?></th>
                                <th><?php echo htmlspecialchars(t('th_type')); ?></th>
                                <th><?php echo htmlspecialchars(t('th_location')); ?></th>
                                <th><?php echo htmlspecialchars(t('th_status')); ?></th>
                                <th><?php echo htmlspecialchars(t('th_error_info')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="incidents-tbody">
                            <?php foreach ($incidents as $inc_idx => $inc): ?>
                                <tr class="incident-row" data-row-index="<?php echo $inc_idx; ?>">
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
                                            <?php echo ($inc['status'] === 'up') ? t('resp_online') : t('stat_down'); ?>
                                        </span>
                                    </td>
                                    <td style="color: var(--text-secondary);">
                                        <?php 
                                        if ($inc['status'] === 'down') {
                                            echo htmlspecialchars($inc['error_message'] ?: t('unspecified_connection_error'));
                                        } else {
                                            echo htmlspecialchars($inc['error_message'] ?: t('service_recovered'));
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($incidents) > 10): ?>
                    <div class="incidents-pagination" id="incidents-pagination" style="display: flex; align-items: center; justify-content: center; gap: 1rem; margin-top: 1rem;">
                        <button type="button" id="incidents-prev" class="btn btn-secondary btn-sm" style="padding: 0.3rem 0.8rem; font-size: 0.78rem;"><i class="fas fa-chevron-left"></i> <?php echo htmlspecialchars(t('pagination_prev')); ?></button>
                        <span id="incidents-page-label" style="font-size: 0.8rem; color: var(--text-muted);"></span>
                        <button type="button" id="incidents-next" class="btn btn-secondary btn-sm" style="padding: 0.3rem 0.8rem; font-size: 0.78rem;"><?php echo htmlspecialchars(t('pagination_next')); ?> <i class="fas fa-chevron-right"></i></button>
                    </div>
                    <script>
                    (function() {
                        const PAGE_SIZE = 10;
                        const rows = Array.from(document.querySelectorAll('#incidents-tbody .incident-row'));
                        const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
                        const label = document.getElementById('incidents-page-label');
                        const prevBtn = document.getElementById('incidents-prev');
                        const nextBtn = document.getElementById('incidents-next');
                        const pageLabelTpl = <?php echo json_encode(t('pagination_page_of')); ?>;
                        let page = 0;

                        function render() {
                            rows.forEach((row, i) => {
                                row.style.display = (i >= page * PAGE_SIZE && i < (page + 1) * PAGE_SIZE) ? '' : 'none';
                            });
                            label.textContent = pageLabelTpl.replace('%d', page + 1).replace('%d', totalPages);
                            prevBtn.disabled = page === 0;
                            nextBtn.disabled = page >= totalPages - 1;
                        }

                        prevBtn.addEventListener('click', () => { if (page > 0) { page--; render(); } });
                        nextBtn.addEventListener('click', () => { if (page < totalPages - 1) { page++; render(); } });
                        render();
                    })();
                    </script>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php if ($portal_url !== ''): ?><a href="<?php echo htmlspecialchars($portal_url); ?>"><?php echo htmlspecialchars($site_title); ?></a><?php else: ?><?php echo htmlspecialchars($site_title); ?><?php endif; ?>. <?php echo htmlspecialchars(t('footer_rights')); ?></p>
            <?php $ver = get_app_version(); ?>
            <p style="font-size: 0.75rem; opacity: 0.5; margin-top: 0.25rem;">
                <i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($ver['label']); ?>
                &middot; <?php echo htmlspecialchars(t('footer_powered_by')); ?> <a href="https://monitoring.bloodkings.eu" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">Blood Kings Monitoring</a>
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
                    if (chart.data.datasets[2]) chart.data.datasets[2].data = data.hdd;
                    if (chart.data.datasets[3]) chart.data.datasets[3].data = data.net;
                    chart.update();

                    const cpuStats = document.getElementById('cpuStats-' + monitorId);
                    const ramStats = document.getElementById('ramStats-' + monitorId);
                    const hddStats = document.getElementById('hddStats-' + monitorId);
                    const netStats = document.getElementById('netStats-' + monitorId);
                    const netStatsBox = document.getElementById('netStatsBox-' + monitorId);
                    if (cpuStats) cpuStats.textContent = data.cpu_avg + '% / ' + data.cpu_max + '%';
                    if (ramStats) ramStats.textContent = data.ram_avg + '% / ' + data.ram_max + '%';
                    if (hddStats) hddStats.textContent = data.hdd_avg + '% / ' + data.hdd_max + '%';
                    if (netStats) netStats.textContent = data.net_avg + ' / ' + data.net_max + ' KB/s';
                    if (netStatsBox) netStatsBox.style.display = data.net_max > 0 ? '' : 'none';
                } catch (e) {
                    console.error(<?php echo json_encode(t('js_metrics_load_error')); ?>, e);
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