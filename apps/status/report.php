<?php
/**
 * SLA Report Generator (Blood Kings Status Monitoring)
 * Vygeneruje tiskový SLA report nebo stáhnutelný CSV výkaz za vybraný měsíc.
 */

require_once __DIR__ . '/functions.php';

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$format = isset($_GET['format']) && $_GET['format'] === 'csv' ? 'csv' : 'html';
$monitor_id = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : 0;

$start_date = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$end_date = date('Y-m-t 23:59:59', strtotime($start_date));
$sla_goal = (float)get_setting('sla_goal_pct', '99.95');

// Načtení monitorů
if ($monitor_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ?");
    $stmt->execute([$monitor_id]);
    $monitors = $stmt->fetchAll();
} else {
    $stmt = $pdo->query("SELECT * FROM monitors ORDER BY name ASC");
    $monitors = $stmt->fetchAll();
}

$report_data = [];
foreach ($monitors as $m) {
    $mid = $m['id'];
    
    // Výpočet uptime za dané období z monitor_logs
    $stmt_logs = $pdo->prepare("
        SELECT status, response_time, checked_at 
        FROM monitor_logs 
        WHERE monitor_id = ? AND checked_at BETWEEN ? AND ?
    ");
    $stmt_logs->execute([$mid, $start_date, $end_date]);
    $logs = $stmt_logs->fetchAll();
    
    $total_checks = count($logs);
    $up_checks = 0;
    $down_checks = 0;
    $total_resp = 0;
    
    foreach ($logs as $l) {
        if ($l['status'] === 'up') {
            $up_checks++;
            $total_resp += (float)$l['response_time'];
        } elseif ($l['status'] === 'down') {
            $down_checks++;
        }
    }
    
    $uptime_pct = $total_checks > 0 ? ($up_checks / $total_checks) * 100 : 100.0;
    $avg_resp = $up_checks > 0 ? round($total_resp / $up_checks, 1) : 0;
    
    $report_data[] = [
        'id' => $mid,
        'name' => $m['name'],
        'type' => $m['type'],
        'target' => $m['target'],
        'total_checks' => $total_checks,
        'up_checks' => $up_checks,
        'down_checks' => $down_checks,
        'uptime_pct' => $uptime_pct,
        'avg_resp_ms' => $avg_resp,
        'sla_met' => $uptime_pct >= $sla_goal
    ];
}

// --- CSV EXPORT ---
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sla_report_' . sprintf('%04d_%02d', $year, $month) . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Monitor ID', 'Název', 'Typ', 'Cíl', 'Celkem testů', 'Online testy', 'Výpadky', 'Uptime SLA (%)', 'Průměrná odezva (ms)', 'SLA Splněno']);
    
    foreach ($report_data as $row) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['type'],
            $row['target'],
            $row['total_checks'],
            $row['up_checks'],
            $row['down_checks'],
            number_format($row['uptime_pct'], 2, '.', ''),
            $row['avg_resp_ms'],
            $row['sla_met'] ? 'ANO' : 'NE'
        ]);
    }
    fclose($output);
    exit;
}

// --- HTML TISKOVÝ REPORT ---
$month_names = [1 => 'Leden', 2 => 'Únor', 3 => 'Březen', 4 => 'Duben', 5 => 'Květen', 6 => 'Červen', 7 => 'Červenec', 8 => 'Srpen', 9 => 'Září', 10 => 'Říjen', 11 => 'Listopad', 12 => 'Prosinec'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>SLA Report - <?php echo $month_names[$month] . ' ' . $year; ?></title>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #0b0c10; color: #e0e0e0; margin: 0; padding: 2rem; line-height: 1.5; }
        .container { max-width: 1000px; margin: 0 auto; background: #13151b; padding: 2rem; border-radius: 10px; border: 1px solid rgba(255,255,255,0.08); }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #b00020; padding-bottom: 1rem; margin-bottom: 1.5rem; }
        h1 { margin: 0; font-size: 1.5rem; color: #fff; }
        .meta { color: #888; font-size: 0.85rem; }
        .actions { margin-bottom: 1.5rem; display: flex; gap: 0.5rem; }
        .btn { background: #b00020; color: #fff; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem; }
        .btn-sec { background: rgba(255,255,255,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { text-align: left; padding: 0.75rem; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 0.85rem; }
        th { background: rgba(255,255,255,0.03); color: #aaa; text-transform: uppercase; font-size: 0.75rem; }
        .badge-success { color: #2ec4b6; font-weight: bold; }
        .badge-danger { color: #e61e2a; font-weight: bold; }
        @media print {
            body { background: #fff; color: #000; padding: 0; }
            .container { border: none; padding: 0; }
            .actions { display: none; }
            th, td { border-bottom: 1px solid #ccc; color: #000; }
            h1 { color: #000; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>SLA Availability Report</h1>
                <div class="meta">Období: <?php echo $month_names[$month] . ' ' . $year; ?> | Cílové SLA: <strong><?php echo number_format($sla_goal, 2); ?>%</strong></div>
            </div>
            <div style="text-align: right;">
                <strong style="color: #b00020; font-size: 1.1rem;"><?php echo htmlspecialchars(get_setting('site_title', 'Blood Kings')); ?></strong>
            </div>
        </div>

        <div class="actions">
            <button onclick="window.print()" class="btn">Vytisknout / Uložit PDF</button>
            <a href="report.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>&monitor_id=<?php echo $monitor_id; ?>&format=csv" class="btn btn-sec">Stáhnout CSV</a>
            <a href="index.php" class="btn btn-sec">← Zpět na status page</a>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Monitor</th>
                    <th>Typ</th>
                    <th>Cíl</th>
                    <th>Testy</th>
                    <th>Výpadky</th>
                    <th>Odezva</th>
                    <th>Uptime SLA</th>
                    <th>Stav SLA</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                        <td><code><?php echo htmlspecialchars($row['type']); ?></code></td>
                        <td><?php echo htmlspecialchars($row['target']); ?></td>
                        <td><?php echo number_format($row['total_checks']); ?></td>
                        <td><?php echo $row['down_checks']; ?></td>
                        <td><?php echo $row['avg_resp_ms']; ?> ms</td>
                        <td style="font-family: monospace; font-size: 0.95rem; font-weight: bold;">
                            <?php echo number_format($row['uptime_pct'], 2, ',', ' '); ?>%
                        </td>
                        <td>
                            <?php if ($row['sla_met']): ?>
                                <span class="badge-success">✓ SPLNĚNO</span>
                            <?php else: ?>
                                <span class="badge-danger">✗ PORUŠENO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
