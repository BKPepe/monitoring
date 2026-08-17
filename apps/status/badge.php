<?php
/**
 * Deprecated alias - the badge lives in api.php?action=badge now (one
 * implementation instead of two: the API action added the fleet summary,
 * cs/en wording and the maintenance state; this file predated it and only
 * knew per-monitor status/uptime).
 *
 * Old embeds (`badge.php?id=12&type=uptime`) keep working through a 302 -
 * <img> loaders and GitHub's camo proxy follow redirects. A deleted monitor
 * returns 404 from the action instead of this file's old grey "unknown"
 * badge: a broken image is an honest answer, an unknown-but-rendered badge
 * pretended the monitor still existed.
 */
$q = ['action' => 'badge'];
if (isset($_GET['id'])) {
    $q['monitor_id'] = (int)$_GET['id'];
}
if (($_GET['type'] ?? '') === 'uptime') {
    $q['type'] = 'uptime';
}
if (($_GET['lang'] ?? '') === 'en') {
    $q['lang'] = 'en';
}
header('Location: api.php?' . http_build_query($q), true, 302);
exit;
