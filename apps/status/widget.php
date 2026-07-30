<?php
/**
 * Compact iFrame Embed Widget (Blood Kings Status Monitoring)
 * Volání: widget.php?id=12
 */

require_once __DIR__ . '/functions.php';

$mid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$monitor = null;
if ($mid > 0) {
    $stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ? LIMIT 1");
    $stmt->execute([$mid]);
    $monitor = $stmt->fetch();
}

if (!$monitor) {
    echo '<div style="color:#fff;background:#0b0c10;padding:1rem;font-family:sans-serif;">Monitor nenalezen.</div>';
    exit;
}

$status = $monitor['status'];
$uptime = calculate_uptime($pdo, $mid, 30);
$details = !empty($monitor['last_details']) ? json_decode($monitor['last_details'], true) : [];
$resp = $details['response_time'] ?? 0;

$dot_color = '#2ec4b6';
$status_label = 'V PROVOZU';
if ($status === 'down') {
    $dot_color = '#e61e2a';
    $status_label = 'VÝPADEK';
} elseif ($status === 'maintenance') {
    $dot_color = '#ffb703';
    $status_label = 'ÚDRŽBA';
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($monitor['name']); ?> - Status</title>
    <style>
        body { margin: 0; padding: 0; background: transparent; font-family: 'Inter', system-ui, sans-serif; }
        .widget-card { background: rgba(16, 18, 22, 0.95); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 0.75rem 1rem; color: #fff; width: 100%; box-sizing: border-box; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .info { display: flex; align-items: center; gap: 0.6rem; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: <?php echo $dot_color; ?>; box-shadow: 0 0 8px <?php echo $dot_color; ?>; flex-shrink: 0; }
        .title { font-size: 0.88rem; font-weight: 600; color: #fff; text-decoration: none; }
        .subtitle { font-size: 0.7rem; color: #888; margin-top: 0.1rem; }
        .stat { text-align: right; }
        .val { font-size: 0.95rem; font-weight: bold; font-family: monospace; color: <?php echo $dot_color; ?>; }
        .label { font-size: 0.65rem; color: #666; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="widget-card">
        <div class="info">
            <div class="dot"></div>
            <div>
                <div class="title"><?php echo htmlspecialchars($monitor['name']); ?></div>
                <div class="subtitle"><?php echo $status_label; ?> • <?php echo $resp; ?> ms</div>
            </div>
        </div>
        <div class="stat">
            <div class="val"><?php echo number_format($uptime, 2, ',', ' '); ?>%</div>
            <div class="label">30d Uptime</div>
        </div>
    </div>
</body>
</html>
