<?php
/**
 * Dynamic SVG Status Badge Generator (Blood Kings Status Monitoring)
 * Generuje vektorové SVG odznáčky (Shields.io style) pro vložení do GitHub README nebo na web.
 * 
 * Volání: badge.php?id=12[&type=status|uptime][&style=flat|glow]
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: max-age=60, s-maxage=60, public');

$mid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) && $_GET['type'] === 'uptime' ? 'uptime' : 'status';

$monitor = null;
if ($mid > 0) {
    $stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ? LIMIT 1");
    $stmt->execute([$mid]);
    $monitor = $stmt->fetch();
}

if (!$monitor) {
    // Vykreslení chybové výchozí značky
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="130" height="20">
        <rect width="70" height="20" fill="#333"/>
        <rect x="70" width="60" height="20" fill="#e61e2a"/>
        <text x="35" y="14" fill="#fff" font-family="sans-serif" font-size="11" text-anchor="middle">status</text>
        <text x="100" y="14" fill="#fff" font-family="sans-serif" font-size="11" text-anchor="middle">unknown</text>
    </svg>';
    exit;
}

$status = $monitor['status'];
$status_text = 'ONLINE';
$bg_color = '#2ec4b6'; // Zelená

if ($status === 'down') {
    $status_text = 'OFFLINE';
    $bg_color = '#e61e2a'; // Červená
} elseif ($status === 'maintenance') {
    $status_text = 'ÚDRŽBA';
    $bg_color = '#ffb703'; // Žlutá
} elseif ($status === 'unknown') {
    $status_text = 'NEZNÁMÝ';
    $bg_color = '#475569'; // Šedá
}

if ($type === 'uptime') {
    $uptime = calculate_uptime($pdo, $mid, 30);
    $status_text = number_format($uptime, 2, '.', '') . '%';
    if ($uptime < 95.0) {
        $bg_color = '#e61e2a';
    } elseif ($uptime < 99.0) {
        $bg_color = '#ffb703';
    }
}

$label = htmlspecialchars($monitor['name']);
$label_width = max(80, (int)(strlen($label) * 7.5) + 16);
$val_width = max(65, (int)(strlen($status_text) * 7.5) + 16);
$total_width = $label_width + $val_width;

echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $total_width . '" height="20" role="img" aria-label="' . $label . ': ' . $status_text . '">
    <linearGradient id="b" x2="0" y2="100%">
        <stop offset="0" stop-color="#bbb" stop-opacity=".1"/>
        <stop offset="1" stop-opacity=".1"/>
    </linearGradient>
    <clipPath id="r">
        <rect width="' . $total_width . '" height="20" rx="3" fill="#fff"/>
    </clipPath>
    <g clip-path="url(#r)">
        <rect width="' . $label_width . '" height="20" fill="#1e222d"/>
        <rect x="' . $label_width . '" width="' . $val_width . '" height="20" fill="' . $bg_color . '"/>
        <rect width="' . $total_width . '" height="20" fill="url(#b)"/>
    </g>
    <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" text-rendering="geometricPrecision" font-size="11">
        <text x="' . ($label_width / 2) . '" y="14" fill="#010101" fill-opacity=".3">' . $label . '</text>
        <text x="' . ($label_width / 2) . '" y="14">' . $label . '</text>
        <text x="' . ($label_width + ($val_width / 2)) . '" y="14" fill="#010101" fill-opacity=".3">' . $status_text . '</text>
        <text x="' . ($label_width + ($val_width / 2)) . '" y="14">' . $status_text . '</text>
    </g>
</svg>';
