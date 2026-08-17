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

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// 1. Ensure the checked_from column exists in monitor_logs
try {
    $pdo->exec("ALTER TABLE monitor_logs ADD COLUMN checked_from VARCHAR(50) DEFAULT 'Main Server'");
} catch (PDOException $e) {
    // Column already exists, ignore the error
}

// 2. API key security check - no hardcoded fallback key.
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
        $stmt = $pdo->query("SELECT id, name, type, target, port FROM monitors");
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
                    $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
                    $stmt_set->execute([$cache_key, $node_location, $node_location]);
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
        $status = isset($res['status']) ? $res['status'] : 'unknown';
        $response_time = isset($res['response_time']) ? (int)$res['response_time'] : 0;
        // Store NULL (not empty string) so incidents query works correctly
        $raw_error = isset($res['error']) ? trim((string)$res['error']) : '';
        $error_message = !empty($raw_error) ? $raw_error : null;
        $details = isset($res['details']) ? json_encode($res['details'], JSON_UNESCAPED_UNICODE) : null;
        
        if ($mid <= 0) continue;
        
        try {
            // Find the monitor's previous state
            $stmt_old = $pdo->prepare("SELECT status FROM monitors WHERE id = ?");
            $stmt_old->execute([$mid]);
            $old_status = $stmt_old->fetchColumn();
            
            // Write the measurement log including the node's location
            $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, ?, ?, ?, ?)");
            $stmt_log->execute([$mid, $status, $response_time, $error_message, $node_location]);
            
            // When the state changed, or this is the first measurement
            if ($old_status !== $status || empty($old_status)) {
                $stmt_up = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
                $stmt_up->execute([$status, $details, $mid]);
            } else {
                // State unchanged - only update the last-check time and any details
                $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
                $stmt_up->execute([$details, $mid]);
            }
            
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
