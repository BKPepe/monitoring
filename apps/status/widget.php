<?php
/**
 * Deprecated alias - the embeddable status lives in api.php?action=badge,
 * as badge.php's does (owner decision D4).
 *
 * This file printed an HTML card with a status vocabulary of its own: an
 * unknown or degraded monitor read as green "V PROVOZU" (R9). /status sends
 * X-Frame-Options SAMEORIGIN, so a foreign site's iframe of it stayed empty
 * anyway (R90); an <img> of the badge shows. Old addresses
 * (`widget.php?id=12`) get the badge of the card's headline figure, the
 * 30-day availability, through a 302. A monitor that is missing or off the
 * public page answers 404 there, as it does to the badge.
 */
$q = ['action' => 'badge'];
if (isset($_GET['id'])) {
    $q['monitor_id'] = (int)$_GET['id'];
}
$q['type'] = 'uptime';
header('Location: api.php?' . http_build_query($q), true, 302);
exit;
