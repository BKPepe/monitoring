<?php
/**
 * RSS kanál se stavem služeb.
 *
 * Status stránka dosud počítala s tím, že si ji někdo otevře. Kdo se o
 * výpadku měl dozvědět, musel na ni sám přijít - a přesně tomu se má status
 * stránka vyhýbat. Odběr přes RSS to obrací: čtečka se ptá sama.
 *
 * Volání:
 *   rss.php                 všechny monitory
 *   rss.php?page=herni      jen monitory zvolené status stránky
 *
 * Skrytá stránka se chová stejně jako v index.php - anonymní návštěvník
 * dostane 404 jako u neexistujícího slugu, aby se její existence nedala
 * zjistit zkoušením adres.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

/** Kolik posledních událostí kanál nese. Čtečky si stejně pamatují jen nové. */
const BK_RSS_MAX_ITEMS = 40;

$is_admin = !empty($_SESSION['admin_logged_in']);

/** Escapování do XML - `<` v názvu monitoru jinak rozbije celý kanál. */
function bk_xml(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Absolutní adresa téhle instance (RSS musí odkazovat naplno, ne relativně). */
function bk_rss_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/status/rss.php'), '/');
    return $scheme . '://' . $host . $dir;
}

function bk_rss_not_found(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo t('rss_not_found');
    exit;
}

$slug = trim((string)($_GET['page'] ?? ''));
$page_title = null;
$page_description = null;
$monitor_ids = [];

if ($slug !== '') {
    try {
        $stmt_page = $pdo->prepare("SELECT title, description, is_public, monitor_ids FROM status_pages WHERE slug = ? LIMIT 1");
        $stmt_page->execute([$slug]);
        $page = $stmt_page->fetch();

        if (!$page || ((int)$page['is_public'] !== 1 && !$is_admin)) {
            bk_rss_not_found();
        }

        $page_title = $page['title'];
        $page_description = $page['description'];
        $ids = json_decode($page['monitor_ids'] ?? '', true);
        if (is_array($ids) && !empty($ids)) {
            $monitor_ids = array_map('intval', $ids);
        }
    } catch (PDOException $e) {
        error_log('[rss] Načtení status stránky selhalo: ' . $e->getMessage());
        bk_rss_not_found();
    }
}

// --- Události ------------------------------------------------------------
//
// Jedna položka na jednu skutečnou událost, ne na incident. Čtečky poznají
// položku podle guid a jednou zobrazenou už znovu neukážou - kdyby se
// vyřešení jen připsalo k původní položce, odběratel by se o něm nedozvěděl.
// Otevření a vyřešení jsou proto dvě samostatné položky.
$items = [];

try {
    $params = [];
    $where = '';
    if (!empty($monitor_ids)) {
        // Incidenty bez monitoru (ruční, obecné) patří na stránku s výběrem taky:
        // typicky se týkají celé infrastruktury.
        $ph = implode(',', array_fill(0, count($monitor_ids), '?'));
        $where = "WHERE (i.monitor_id IS NULL OR i.monitor_id IN ({$ph}))";
        $params = $monitor_ids;
    }

    $stmt = $pdo->prepare("
        SELECT i.id, i.title, i.impact, i.status, i.created_at, i.resolved_at, i.postmortem,
               i.monitor_id, m.name AS monitor_name
        FROM incidents i
        LEFT JOIN monitors m ON m.id = i.monitor_id
        {$where}
        ORDER BY i.id DESC
        LIMIT " . BK_RSS_MAX_ITEMS
    );
    $stmt->execute($params);

    // Popisky dopadu se skládají výčtem, ne přes t('rss_impact_' . $impact):
    // dynamický klíč nejde staticky ověřit, takže chybějící překlad by prošel
    // testem i revizí a projevil se až odběrateli. Schéma zná právě tyhle tři.
    $impact_labels = [
        'minor' => t('rss_impact_minor'),
        'major' => t('rss_impact_major'),
        'critical' => t('rss_impact_critical'),
    ];

    foreach ($stmt->fetchAll() as $inc) {
        $monitor_name = $inc['monitor_name'] ?? null;
        $impact = (string)($inc['impact'] ?? '');

        // --- Vznik incidentu ---
        if (!empty($inc['created_at'])) {
            // Neznámý dopad se vypíše, jak přišel z databáze - přejmenovat ho
            // na „malý" by tvrdilo něco, co nikdo nenastavil.
            $body = t('rss_impact_label') . ': ' . ($impact_labels[$impact] ?? $impact);
            if ($monitor_name !== null) {
                $body .= ' · ' . t('rss_service_label') . ': ' . $monitor_name;
            }
            $items[] = [
                'guid' => 'incident-' . (int)$inc['id'] . '-opened',
                'title' => t('rss_prefix_opened') . ' ' . $inc['title'],
                'description' => $body,
                'timestamp' => strtotime((string)$inc['created_at']),
            ];
        }

        // --- Vyřešení ---
        // Jen když je opravdu vyplněné. Dopočítat čas vyřešení z "teď" u
        // incidentu, který nikdo neuzavřel, by byl vymyšlený údaj.
        if (!empty($inc['resolved_at'])) {
            $body = t('rss_resolved_body');
            if ($monitor_name !== null) {
                $body .= ' ' . t('rss_service_label') . ': ' . $monitor_name . '.';
            }
            $opened_ts = !empty($inc['created_at']) ? strtotime((string)$inc['created_at']) : false;
            $resolved_ts = strtotime((string)$inc['resolved_at']);
            if ($opened_ts !== false && $resolved_ts !== false && $resolved_ts >= $opened_ts) {
                $body .= ' ' . t('rss_duration_label') . ': ' . bk_format_duration_secs($resolved_ts - $opened_ts) . '.';
            }
            if (!empty($inc['postmortem'])) {
                $body .= "\n\n" . $inc['postmortem'];
            }
            $items[] = [
                'guid' => 'incident-' . (int)$inc['id'] . '-resolved',
                'title' => t('rss_prefix_resolved') . ' ' . $inc['title'],
                'description' => $body,
                'timestamp' => $resolved_ts !== false ? $resolved_ts : null,
            ];
        }
    }
} catch (PDOException $e) {
    error_log('[rss] Načtení incidentů selhalo: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo t('rss_unavailable');
    exit;
}

// Bez použitelného času by položka v čtečce skončila nahoře nebo dole náhodně;
// takové záznamy do kanálu nepatří.
$items = array_values(array_filter($items, fn($i) => $i['timestamp'] !== null && $i['timestamp'] !== false));
usort($items, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
$items = array_slice($items, 0, BK_RSS_MAX_ITEMS);

// --- Výstup --------------------------------------------------------------
header('Content-Type: application/rss+xml; charset=utf-8');
// Čtečky se ptají často; pět minut je kompromis mezi čerstvostí a zátěží.
header('Cache-Control: public, max-age=300');

$base = bk_rss_base_url();
// Klíč je 'site_title', ne 'site_name' - pod 'site_name' nikdo nikdy nic
// neuložil, takže se kanál vždycky jmenoval obecným náhradním názvem.
$site_name = get_setting('site_title', '');
$channel_title = ($site_name !== '' ? $site_name : t('rss_default_site')) . ' - ' . ($page_title ?? t('rss_channel_suffix'));
$channel_link = $base . '/' . ($slug !== '' ? '?page=' . rawurlencode($slug) : '');
$self_link = $base . '/rss.php' . ($slug !== '' ? '?page=' . rawurlencode($slug) : '');
$channel_desc = $page_description ?: t('rss_channel_description');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title><?php echo bk_xml($channel_title); ?></title>
    <link><?php echo bk_xml($channel_link); ?></link>
    <description><?php echo bk_xml($channel_desc); ?></description>
    <language><?php echo bk_xml($GLOBALS['BK_LANG'] ?? 'cs'); ?></language>
    <atom:link href="<?php echo bk_xml($self_link); ?>" rel="self" type="application/rss+xml" />
<?php if (!empty($items)): ?>
    <lastBuildDate><?php echo date(DATE_RSS, $items[0]['timestamp']); ?></lastBuildDate>
<?php endif; ?>
<?php foreach ($items as $item): ?>
    <item>
      <title><?php echo bk_xml($item['title']); ?></title>
      <link><?php echo bk_xml($channel_link); ?></link>
      <guid isPermaLink="false"><?php echo bk_xml($item['guid']); ?></guid>
      <pubDate><?php echo date(DATE_RSS, $item['timestamp']); ?></pubDate>
      <description><?php echo bk_xml($item['description']); ?></description>
    </item>
<?php endforeach; ?>
  </channel>
</rss>
