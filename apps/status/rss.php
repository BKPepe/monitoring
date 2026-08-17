<?php
/**
 * RSS feed with the service status.
 *
 * The status page assumed someone would open it. Whoever was supposed to learn
 * about an outage had to come by themselves - exactly what a status page
 * should avoid. An RSS subscription flips it: the reader asks by itself.
 *
 * Invocation:
 *   rss.php                 all monitors
 *   rss.php?page=herni      only the monitors of the chosen status page
 *
 * A hidden page behaves like in index.php - an anonymous visitor gets a 404
 * as with a nonexistent slug, so its existence cannot be discovered by
 * probing addresses.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

/** How many recent events the feed carries. Readers remember only new ones anyway. */
const BK_RSS_MAX_ITEMS = 40;

$is_admin = !empty($_SESSION['admin_logged_in']);

/** XML escaping - a `<` in a monitor name would break the whole feed otherwise. */
function bk_xml(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** The absolute address of this instance (RSS must link fully, not relatively). */
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

// --- Events ---------------------------------------------------------------
//
// One item per actual event, not per incident. Readers identify an item by
// its guid and never show a seen one again - if the resolution were merely
// appended to the original item, the subscriber would never learn about it.
// Opening and resolution are therefore two separate items.
$items = [];

try {
    $params = [];
    $where = '';
    if (!empty($monitor_ids)) {
        // Incidents without a monitor (manual, global) belong on a filtered page
        // too: they typically concern the whole infrastructure.
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

    // Impact labels are enumerated, not t('rss_impact_' . $impact):
    // a dynamic key cannot be verified statically, so a missing translation would
    // pass tests and review and surface only to a subscriber. The schema knows exactly these three.
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
            // An unknown impact is printed as it came from the database - renaming
            // it to "minor" would claim something nobody set.
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

        // --- Resolution ---
        // Only when actually filled. Deriving a resolution time from "now" for
        // an incident nobody closed would be an invented value.
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

// Without a usable time the item would land at a reader's top or bottom at random;
// such records do not belong in the feed.
$items = array_values(array_filter($items, fn($i) => $i['timestamp'] !== null && $i['timestamp'] !== false));
usort($items, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);
$items = array_slice($items, 0, BK_RSS_MAX_ITEMS);

// --- Output ---------------------------------------------------------------
header('Content-Type: application/rss+xml; charset=utf-8');
// Readers poll often; five minutes is the compromise between freshness and load.
header('Cache-Control: public, max-age=300');

$base = bk_rss_base_url();
// The key is 'site_title', not 'site_name' - nobody ever stored anything under
// 'site_name', so the feed was always named by the generic fallback.
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
