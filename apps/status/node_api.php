<?php
/**
 * Blood Kings Status Monitoring - Distributed Node API
 * 
 * Endpoint pro komunikaci se vzdálenými monitorovacími uzly (nodes).
 * Umožňuje stahovat seznam monitorů k testování a ukládat výsledky měření.
 */

// Zapneme zobrazování chyb pro případné ladění API
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// 1. Zajištění existence sloupce checked_from v tabulce monitor_logs
try {
    $pdo->exec("ALTER TABLE monitor_logs ADD COLUMN checked_from VARCHAR(50) DEFAULT 'Main Server'");
} catch (PDOException $e) {
    // Sloupec již existuje, ignorujeme chybu
}

// 2. Bezpečnostní kontrola API klíče
$node_key = get_setting('cron_key', 'BloodKingsNodeDefaultKey123!');
$client_key = isset($_GET['key']) ? $_GET['key'] : (isset($_SERVER['HTTP_X_NODE_KEY']) ? $_SERVER['HTTP_X_NODE_KEY'] : '');

if (empty($client_key) || $client_key !== $node_key) {
    http_response_code(403);
    echo json_encode(['error' => 'Neautorizovaný přístup. Neplatný nebo chybějící API klíč (key).']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// --- AKCE: Stáhnutí seznamu monitorů k otestování ---
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

// --- AKCE: Uložení výsledků měření z uzlu ---
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
        
        // Zkusíme najít lokaci v cache nastavení
        $stmt_cache = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = ?");
        $stmt_cache->execute([$cache_key]);
        $node_location = $stmt_cache->fetchColumn();
        
        if (empty($node_location)) {
            // Pokud chybí v cache, dotážeme se GeoIP API s timeoutem 2s
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
                    
                    // Převod ISO kódu země na emoji vlaječku
                    $c1 = ord($cc[0]) - 65 + 127462;
                    $c2 = ord($cc[1]) - 65 + 127462;
                    $flag = html_entity_decode("&#$c1;&#$c2;", ENT_NOQUOTES, 'UTF-8');
                    
                    $node_location = $flag . ' ' . ($city ? $city . ', ' : '') . $cc;
                    
                    // Uložíme do settings jako cache
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
            // Zjistíme předchozí stav monitoru
            $stmt_old = $pdo->prepare("SELECT status FROM monitors WHERE id = ?");
            $stmt_old->execute([$mid]);
            $old_status = $stmt_old->fetchColumn();
            
            // Zápis do logu měření i s lokací uzlu
            $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, ?, ?, ?, ?)");
            $stmt_log->execute([$mid, $status, $response_time, $error_message, $node_location]);
            
            // Pokud se změnil stav, nebo se jedná o první měření
            if ($old_status !== $status || empty($old_status)) {
                $stmt_up = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
                $stmt_up->execute([$status, $details, $mid]);
            } else {
                // Pokud stav zůstal stejný, aktualizujeme pouze čas poslední kontroly a případné detaily
                $stmt_up = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
                $stmt_up->execute([$details, $mid]);
            }
            
            $success_count++;
        } catch (PDOException $e) {
            // Logování chyb u konkrétního monitoru, pokračujeme dál
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

// Neplatná akce
http_response_code(400);
echo json_encode(['error' => 'Neplatná akce. Použijte action=get_monitors nebo action=post_results.']);
