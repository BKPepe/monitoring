<?php
/**
 * Prometheus exporter pro Blood Kings Status Monitoring
 *
 * Exposes monitor states and VPS agent metrics in the Prometheus text
 * exposition format (version 0.0.4) for scraping by an external Prometheus server.
 *
 * Security: the endpoint is active only when a secret token is set in settings
 * (or config.php / the METRICS_TOKEN environment variable). The scraper passes it
 * either as ?token=... or via the "Authorization: Bearer ..." header.
 *
 * Prometheus configuration example:
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
 * Escapes a label value per the Prometheus exposition format
 */
function prom_escape_label(string $value): string {
    return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
}

/**
 * Builds the label string: {name="...", type="..."}
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

    // Latest responses from monitor_logs (one newest row per monitor)
    $response_times = [];
    // The latest response per monitor: looked up via the (monitor_id, id) index,
    // not by aggregating MAX(id) over the whole log table - that grew with
    // history and would eventually slow Prometheus scrapes to seconds.
    $stmt_rt = $pdo->query("
        SELECT m.id AS monitor_id,
               (SELECT l.response_time FROM monitor_logs l
                WHERE l.monitor_id = m.id
                ORDER BY l.id DESC LIMIT 1) AS response_time
        FROM monitors m
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

    // VPS agent metrics from last_details (current values reported by agents)
    $vps_metric_defs = [
        'cpu' => ['bloodkings_vps_cpu_percent', 'Aktualni vytizeni CPU v procentech (hlaseno agentem).'],
        'ram' => ['bloodkings_vps_ram_percent', 'Aktualni vytizeni RAM v procentech (hlaseno agentem).'],
        'hdd' => ['bloodkings_vps_hdd_percent', 'Aktualni zaplneni disku v procentech (hlaseno agentem).'],
        'net' => ['bloodkings_vps_net_kbps', 'Prumerna propustnost site (RX+TX) v KB/s od posledniho behu agenta.'],
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
            // Integer values without a decimal part for readability
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
