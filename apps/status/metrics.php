<?php
/**
 * Prometheus exporter pro Blood Kings Status Monitoring
 *
 * Vystavuje stav monitorů a metriky VPS agentů ve formátu Prometheus text
 * exposition (verze 0.0.4) pro scrapování externím Prometheus serverem.
 *
 * Zabezpečení: endpoint je aktivní pouze pokud je v nastavení (nebo config.php /
 * proměnné prostředí METRICS_TOKEN) vyplněn tajný token. Scraper jej předává
 * buď jako ?token=... nebo hlavičkou "Authorization: Bearer ...".
 *
 * Ukázka konfigurace Prometheus:
 *   scrape_configs:
 *     - job_name: bloodkings
 *       metrics_path: /status/metrics.php
 *       params:
 *         token: ['VAS_TAJNY_TOKEN']
 *       static_configs:
 *         - targets: ['status.example.com']
 */

require_once __DIR__ . '/db.php';

$configured_token = trim((string)get_setting('metrics_token'));

if ($configured_token === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Metrics endpoint neni aktivni. Nastavte 'metrics_token' v administraci.\n";
    exit;
}

$provided_token = trim((string)($_GET['token'] ?? ''));
if ($provided_token === '') {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
        $provided_token = $m[1];
    }
}

if (!hash_equals($configured_token, $provided_token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Pristup odepren.\n";
    exit;
}

/**
 * Escapování hodnoty labelu podle Prometheus exposition formátu
 */
function prom_escape_label(string $value): string {
    return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
}

/**
 * Sestaví řetězec labelů: {name="...", type="..."}
 */
function prom_labels(array $labels): string {
    $parts = [];
    foreach ($labels as $k => $v) {
        $parts[] = $k . '="' . prom_escape_label((string)$v) . '"';
    }
    return '{' . implode(',', $parts) . '}';
}

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

$lines = [];

try {
    $stmt = $pdo->query("SELECT id, name, type, category, status, last_details, last_status_change FROM monitors ORDER BY id");
    $monitors = $stmt->fetchAll();

    // Poslední odezvy z monitor_logs (jeden nejnovější záznam pro každý monitor)
    $response_times = [];
    $stmt_rt = $pdo->query("
        SELECT l.monitor_id, l.response_time
        FROM monitor_logs l
        INNER JOIN (
            SELECT monitor_id, MAX(id) AS max_id
            FROM monitor_logs
            GROUP BY monitor_id
        ) latest ON latest.max_id = l.id
    ");
    while ($row = $stmt_rt->fetch()) {
        $response_times[$row['monitor_id']] = $row['response_time'];
    }

    $lines[] = '# HELP bloodkings_monitor_up Stav monitoru (1 = up, 0 = down). Monitor v udrzbe ma hodnotu 1.';
    $lines[] = '# TYPE bloodkings_monitor_up gauge';
    foreach ($monitors as $m) {
        $labels = prom_labels(['name' => $m['name'], 'type' => $m['type'], 'category' => $m['category']]);
        $up = in_array($m['status'], ['up', 'maintenance'], true) ? 1 : 0;
        $lines[] = 'bloodkings_monitor_up' . $labels . ' ' . $up;
    }

    $lines[] = '# HELP bloodkings_monitor_maintenance Monitor je v rezimu planovane udrzby (1 = ano).';
    $lines[] = '# TYPE bloodkings_monitor_maintenance gauge';
    foreach ($monitors as $m) {
        $labels = prom_labels(['name' => $m['name'], 'type' => $m['type']]);
        $lines[] = 'bloodkings_monitor_maintenance' . $labels . ' ' . ($m['status'] === 'maintenance' ? 1 : 0);
    }

    $lines[] = '# HELP bloodkings_monitor_response_time_ms Posledni namerena odezva v milisekundach.';
    $lines[] = '# TYPE bloodkings_monitor_response_time_ms gauge';
    foreach ($monitors as $m) {
        if (!isset($response_times[$m['id']]) || $response_times[$m['id']] === null) continue;
        $labels = prom_labels(['name' => $m['name'], 'type' => $m['type']]);
        $lines[] = 'bloodkings_monitor_response_time_ms' . $labels . ' ' . (int)$response_times[$m['id']];
    }

    // Metriky VPS agentů z last_details (aktuální hodnoty hlášené agenty)
    $vps_metric_defs = [
        'cpu' => ['bloodkings_vps_cpu_percent', 'Aktualni vytizeni CPU v procentech (hlaseno agentem).'],
        'ram' => ['bloodkings_vps_ram_percent', 'Aktualni vytizeni RAM v procentech (hlaseno agentem).'],
        'hdd' => ['bloodkings_vps_hdd_percent', 'Aktualni zaplneni disku v procentech (hlaseno agentem).'],
        'uptime' => ['bloodkings_vps_uptime_seconds', 'Uptime serveru v sekundach (hlaseno agentem).'],
        'agent_last_seen' => ['bloodkings_vps_agent_last_seen_timestamp', 'Unix cas posledniho hlaseni agenta.'],
    ];
    $vps_values = [];
    foreach ($monitors as $m) {
        $details = json_decode((string)$m['last_details'], true);
        if (!is_array($details)) continue;
        foreach ($vps_metric_defs as $key => $def) {
            if (isset($details[$key]) && is_numeric($details[$key])) {
                $vps_values[$key][] = [$m['name'], (float)$details[$key]];
            }
        }
    }
    foreach ($vps_metric_defs as $key => [$metric_name, $help]) {
        if (empty($vps_values[$key])) continue;
        $lines[] = '# HELP ' . $metric_name . ' ' . $help;
        $lines[] = '# TYPE ' . $metric_name . ' gauge';
        foreach ($vps_values[$key] as [$name, $value]) {
            // Celočíselné hodnoty bez desetinné části kvůli čitelnosti
            $formatted = ($value == (int)$value) ? (string)(int)$value : (string)$value;
            $lines[] = $metric_name . prom_labels(['name' => $name]) . ' ' . $formatted;
        }
    }

    $lines[] = '# HELP bloodkings_monitors_total Celkovy pocet monitoru.';
    $lines[] = '# TYPE bloodkings_monitors_total gauge';
    $lines[] = 'bloodkings_monitors_total ' . count($monitors);
} catch (Exception $e) {
    http_response_code(500);
    echo "# Chyba pri generovani metrik\n";
    exit;
}

echo implode("\n", $lines) . "\n";
