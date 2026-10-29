<?php
/**
 * Blood Kings Status Monitoring - Distributed Node API
 * 
 * Endpoint for talking to remote monitoring nodes.
 * Lets them download the monitor list to test and store measurement results.
 */

// Show errors for API debugging
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// db.php loads config.php itself, after it has set the session cookie flags.
// Requiring config.php first let its session start before them, and the
// admin cookie went out without HttpOnly and SameSite.
require_once __DIR__ . '/db.php';
// is_in_maintenance(), as cron applies it.
require_once __DIR__ . '/functions.php';

// monitor_logs.checked_from comes from schema.sql and db.php's migrations;
// this file no longer adds it again on every request.

// API key security check - no hardcoded fallback key.
// An empty cron_key means the endpoint is switched off entirely (fail closed),
// not that some publicly known default applies (a real hole, because the same
// literal is published in node_client.php as the copy-paste default).
$node_key = trim((string)get_setting('cron_key', ''));
$client_key = isset($_GET['key']) ? (string)$_GET['key'] : (isset($_SERVER['HTTP_X_NODE_KEY']) ? (string)$_SERVER['HTTP_X_NODE_KEY'] : '');

if ($node_key === '' || $client_key === '' || !hash_equals($node_key, $client_key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Neautorizovaný přístup. Neplatný, chybějící nebo nenastavený API klíč (key).']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// --- ACTION: fetch the list of monitors to test ---
if ($action === 'get_monitors') {
    try {
        $stmt = $pdo->query("SELECT id, name, type, target, port FROM monitors WHERE archived_at IS NULL");
        $monitors = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['monitors' => $monitors]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Chyba databáze při načítání monitorů: ' . $e->getMessage()]);
    }
    exit;
}

// --- ACTION: store measurement results from a node ---
if ($action === 'post_results') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['results']) || !is_array($data['results'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatná data. Očekáván JSON formát s polem "results".']);
        exit;
    }
    
    $node_location = isset($data['location']) ? trim($data['location']) : 'AUTO';
    
    if ($node_location === 'AUTO' || empty($node_location)) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $cache_key = 'ip_loc_' . str_replace('.', '_', $ip);
        
        // Try the settings cache for the location
        $stmt_cache = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = ?");
        $stmt_cache->execute([$cache_key]);
        $node_location = $stmt_cache->fetchColumn();
        
        if (empty($node_location)) {
            // When missing from the cache, ask the GeoIP API with a 2 s timeout
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://ipapi.co/{$ip}/json/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
            $res = curl_exec($ch);
            curl_close($ch);
            
            if ($res) {
                $geo = json_decode($res, true);
                if ($geo && isset($geo['country_code'])) {
                    $cc = $geo['country_code'];
                    $city = $geo['city'] ?? '';
                    
                    // ISO country code to an emoji flag
                    $c1 = ord($cc[0]) - 65 + 127462;
                    $c2 = ord($cc[1]) - 65 + 127462;
                    $flag = html_entity_decode("&#$c1;&#$c2;", ENT_NOQUOTES, 'UTF-8');
                    
                    $node_location = $flag . ' ' . ($city ? $city . ', ' : '') . $cc;
                    
                    // Store into settings as a cache
                    bk_setting_set($pdo, $cache_key, $node_location);
                }
            }
            
            if (empty($node_location)) {
                $node_location = '📍 Vzdálený uzel';
            }
        }
    }
    $results = $data['results'];
    $success_count = 0;
    
    foreach ($results as $res) {
        $mid = isset($res['id']) ? (int)$res['id'] : 0;
        $status = isset($res['status']) && is_string($res['status']) ? $res['status'] : '';
        // No value is NULL, not 0 ms, and so is a 0 on a failed check (what
        // node_client.php sent there). The live senders, the Cloudflare Worker
        // and the GitHub Actions runners, time an 'up' result only after the
        // answer arrived, so a 0 there is an answer under 1 ms: 1 ms, as a
        // measured 0.4 ms is (bk_response_ms).
        $response_time = bk_response_ms($res['response_time'] ?? null, $status === 'up');
        // Store NULL (not empty string) so incidents query works correctly
        $raw_error = isset($res['error']) ? trim((string)$res['error']) : '';
        $error_message = !empty($raw_error) ? $raw_error : null;

        // A result without a status is not a measurement.
        if ($mid <= 0 || $status === '') continue;

        try {
            $stmt_old = $pdo->prepare("SELECT archived_at, maintenance, maintenance_start, maintenance_end FROM monitors WHERE id = ?");
            $stmt_old->execute([$mid]);
            $old_row = $stmt_old->fetch();
            // An unknown or archived monitor gets no measurement from a probe.
            if (!$old_row || !empty($old_row['archived_at'])) {
                continue;
            }
            // Nor does one in maintenance: cron logs the window as
            // 'maintenance' rows and checks nothing. A probe's 'down' there
            // (the planned work's 503) was an outage in availability and in
            // sla_report that no alert or incident ever had.
            if (is_in_maintenance($old_row)) {
                continue;
            }

            // Write the measurement log including the node's location. That
            // is all a probe writes: the monitor's status, last_checked and
            // last_details stay with cron.php, which applies maintenance,
            // alert_confirm_failures, notifications and the incident
            // lifecycle. A probe writing them skipped all of that - a 'down'
            // nobody was alerted about, maintenance-start re-sent after every
            // post - and its NULL details wiped cron's latches (last_ssl_warn,
            // latency_alert_sent), so the SSL warning went out every 5 min.
            //
            // The row still counts wherever cron reads monitor_logs, which
            // does not record who wrote a row: a probe's 'down' adds to the
            // alert_confirm_failures streak and its 'up' ends one, and its
            // 'up' response times enter the latency alert's window. Like any
            // measurement it counts in availability, the history and regions.
            // It bounds sla_report's last outage too: a probe's 'up' ends or
            // splits it while cron keeps the monitor down and the incident
            // open, and its 'down' starts one. action=events takes the row
            // before a status change as the state it came from (fromStatus),
            // and that can be a probe's 'up' just before cron's recovery.
            // A status's reason (findings, the admin diagnostics, the daily
            // reminder) is the newest row in the monitor's current state, so
            // a probe's 'up' never stands in for an outage's reason.
            $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, ?, ?, ?, ?)");
            $stmt_log->execute([$mid, $status, $response_time, $error_message, $node_location]);

            $success_count++;
        } catch (PDOException $e) {
            // Log per-monitor errors and continue
            continue;
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => "Úspěšně zpracováno $success_count z " . count($results) . " výsledků.",
        'location' => $node_location
    ]);
    exit;
}

// Invalid action
http_response_code(400);
echo json_encode(['error' => 'Neplatná akce. Použijte action=get_monitors nebo action=post_results.']);
