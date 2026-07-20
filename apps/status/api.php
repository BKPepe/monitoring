<?php
/**
 * Blood Kings - Public Status API
 * 
 * Poskytuje stav herních serverů a webů v JSON formátu pro hlavní portál (app.js).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Historie metrik pro grafy vytížení (přepínač 24h / 7d / 30d na dashboardu).
// Delší období se agregují po hodinách/dnech, aby odpověď nepřenášela tisíce řádků.
if (($_GET['action'] ?? '') === 'metrics_history') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $period = $_GET['period'] ?? '24h';

    $result = [
        'labels' => [], 'cpu' => [], 'ram' => [],
        'cpu_avg' => 0, 'cpu_max' => 0, 'ram_avg' => 0, 'ram_max' => 0,
    ];

    try {
        if ($period === '7d') {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(checked_at, '%d.%m. %H:00') AS label,
                       AVG(cpu_usage) AS cpu, MAX(cpu_usage) AS cpu_peak,
                       AVG(ram_usage) AS ram, MAX(ram_usage) AS ram_peak
                FROM vps_metrics
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE_FORMAT(checked_at, '%Y-%m-%d %H')
                ORDER BY MIN(checked_at) ASC
            ");
        } elseif ($period === '30d') {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(checked_at, '%d.%m.') AS label,
                       AVG(cpu_usage) AS cpu, MAX(cpu_usage) AS cpu_peak,
                       AVG(ram_usage) AS ram, MAX(ram_usage) AS ram_peak
                FROM vps_metrics
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(checked_at)
                ORDER BY MIN(checked_at) ASC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(checked_at, '%H:%i') AS label,
                       cpu_usage AS cpu, cpu_usage AS cpu_peak,
                       ram_usage AS ram, ram_usage AS ram_peak
                FROM vps_metrics
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY checked_at ASC
            ");
        }
        $stmt->execute([$monitor_id]);
        $rows = $stmt->fetchAll();

        $cpu_sum = $ram_sum = 0;
        foreach ($rows as $r) {
            $result['labels'][] = $r['label'];
            $result['cpu'][] = round((float)$r['cpu'], 1);
            $result['ram'][] = round((float)$r['ram'], 1);
            $cpu_sum += (float)$r['cpu'];
            $ram_sum += (float)$r['ram'];
            $result['cpu_max'] = max($result['cpu_max'], round((float)$r['cpu_peak'], 1));
            $result['ram_max'] = max($result['ram_max'], round((float)$r['ram_peak'], 1));
        }
        if (count($rows) > 0) {
            $result['cpu_avg'] = round($cpu_sum / count($rows), 1);
            $result['ram_avg'] = round($ram_sum / count($rows), 1);
        }
    } catch (Exception $e) {
        // Vracíme prázdná data
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

$response = [
    'teamspeak' => [
        'online' => false,
        'clients_online' => 0,
        'clients_max' => 0,
        'name' => 'TeamSpeak Server'
    ],
    'minecraft' => [
        'online' => false,
        'players_online' => 0,
        'players_max' => 0,
        'version' => ''
    ]
];

try {
    // 1. Načtení stavu TeamSpeaku
    $stmt = $pdo->prepare("SELECT status, last_details, name FROM monitors WHERE type = 'teamspeak' LIMIT 1");
    $stmt->execute();
    $ts = $stmt->fetch();
    
    if ($ts) {
        $response['teamspeak']['online'] = ($ts['status'] === 'up');
        $response['teamspeak']['name'] = $ts['name'];
        $details = json_decode($ts['last_details'], true);
        if ($details) {
            $response['teamspeak']['clients_online'] = (int)($details['clients_online'] ?? 0);
            $response['teamspeak']['clients_max'] = (int)($details['clients_max'] ?? 0);
        }
    }
    
    // 2. Načtení stavu Minecraftu
    $stmt = $pdo->prepare("SELECT status, last_details FROM monitors WHERE type = 'minecraft' LIMIT 1");
    $stmt->execute();
    $mc = $stmt->fetch();
    
    if ($mc) {
        $response['minecraft']['online'] = ($mc['status'] === 'up');
        $details = json_decode($mc['last_details'], true);
        if ($details) {
            $response['minecraft']['players_online'] = (int)($details['players_online'] ?? 0);
            $response['minecraft']['players_max'] = (int)($details['players_max'] ?? 0);
            $response['minecraft']['version'] = $details['version'] ?? '';
        }
    }
} catch (Exception $e) {
    // V případě chyby vrátíme výchozí prázdné struktury
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
