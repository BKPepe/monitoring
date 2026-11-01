<?php
/**
 * API endpoint receiving VPS agent reports
 */

header('Content-Type: application/json');
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lang.php';

// Allow POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda není povolena. Použijte POST.']);
    exit;
}

// Read the JSON body of the request. One stray byte - a Latin-1 SSID, an
// operator name - used to void the whole minute with a 400: invalid UTF-8
// becomes U+FFFD instead.
$raw_data = (string)file_get_contents('php://input');
$data = json_decode($raw_data, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

if (!$data || !is_array($data)) {
    $json_err = json_last_error_msg();
    // Still a 400, but said out loud: logged, and shown on the monitor as a
    // collection issue rather than as an agent gone quiet.
    bk_agent_report_unreadable($pdo, $raw_data, $json_err);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Neplatný JSON formát: ' . $json_err]);
    exit;
}

// --- Standalone Remote Action result acknowledgement ---
// Every agent sends this as a lightweight follow-up POST right after
// executing an action, separately from the main telemetry (already sent
// for this cycle). It has no cpu/ram/hdd, so it must be handled before the
// usual required-telemetry validation below. This is the only place an
// action result is stored: a full report never carries one.
if (isset($data['action_result']) && is_array($data['action_result']) && !isset($data['cpu'])) {
    $ar_monitor_id = bk_agent_mini_request_monitor($pdo, $data)['id'];

    $act_res = $data['action_result'];
    $act_id = intval($act_res['action_id'] ?? 0);
    $act_status = in_array($act_res['status'] ?? '', ['executed', 'failed'], true) ? $act_res['status'] : 'failed';
    $act_msg = trim((string)($act_res['message'] ?? ''));

    if ($act_id > 0) {
        $stmt_act = $pdo->prepare("UPDATE agent_actions SET status = ?, result_message = ?, executed_at = NOW() WHERE id = ? AND monitor_id = ?");
        $stmt_act->execute([$act_status, $act_msg, $act_id, $ar_monitor_id]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// --- Agent-side service check results (OpenWrt v1.5.3+) ---
// A standalone mini-request just like action_result: after the main report
// the agent locally verifies the services from the service_checks list in
// the response and sends the results. A result may only be written to
// 'agent_service' monitors of the SAME asset the agent_key belongs to.
if (isset($data['service_check_results']) && is_array($data['service_check_results']) && !isset($data['cpu'])) {
    $sc_agent = bk_agent_mini_request_monitor($pdo, $data);
    $sc_applied = 0;
    foreach (array_slice($data['service_check_results'], 0, 50) as $res) {
        if (!is_array($res)) continue;
        $sc_id = (int)($res['monitor_id'] ?? 0);
        if ($sc_id <= 0 || $sc_agent['asset_id'] === null) continue;
        $stmt_svc = $pdo->prepare("SELECT * FROM monitors WHERE id = ? AND asset_id = ? AND type = 'agent_service' AND archived_at IS NULL LIMIT 1");
        $stmt_svc->execute([$sc_id, $sc_agent['asset_id']]);
        $svc_row = $stmt_svc->fetch();
        if (!$svc_row) continue;
        $sc_running = !empty($res['running']);
        $sc_detail = mb_substr(trim((string)($res['detail'] ?? '')), 0, 255);
        // A result dropped during maintenance is not counted as applied.
        if (bk_apply_agent_service_result($pdo, $svc_row, $sc_running, $sc_detail)) {
            $sc_applied++;
        }
    }
    echo json_encode(['success' => true, 'applied' => $sc_applied]);
    exit;
}

// --- Automatic agent registration ---
if (isset($data['action']) && $data['action'] === 'register') {
    $token = bk_agent_str($data, 'token', 255) ?? '';
    $reg_token = get_setting('agent_registration_token');
    
    // When no registration token is configured, the cron_key acts as fallback
    if (empty($reg_token)) {
        $reg_token = get_setting('cron_key');
    }
    
    if (empty($reg_token) || !hash_equals((string)$reg_token, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Neplatný registrační token.']);
        exit;
    }
    
    $name = bk_agent_str($data, 'hostname', 100) ?? ('VPS Agent ' . date('Y-m-d H:i'));
    $type = (isset($data['agent_type']) && $data['agent_type'] === 'openwrt') ? 'openwrt' : 'vps';
    $agent_key = bin2hex(random_bytes(16));

    // Its own 1:1 asset, as save_monitor gives every new monitor. Without one
    // the agent stayed asset-less until the next schema migration, and the
    // agent-side checks of a service had no device to hang under.
    // One transaction: a failed monitors INSERT left the asset behind, and
    // every retry of --register added another empty device.
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO assets (name) VALUES (?)")->execute([$name]);
        $asset_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("
            INSERT INTO monitors (name, type, target, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold, asset_id)
            VALUES (?, ?, 'Local VPS Agent', 'unknown', ?, ?, ?, ?, ?)
        ");
        // RAM and disk were swapped here (90/95) against the alerts below.
        $stmt->execute([$name, $type, $agent_key, BK_DEFAULT_THRESHOLDS['cpu'], BK_DEFAULT_THRESHOLDS['ram'], BK_DEFAULT_THRESHOLDS['hdd'], $asset_id]);
        $new_id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try {
                $pdo->rollBack();
            } catch (Throwable $re) {}
        }
        error_log('[agent_api] Agent registration failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Registrace agenta se nezdařila, nic nebylo uloženo.']);
        exit;
    }

    log_monitor_event($pdo, $new_id, $name, $type, 'monitor_added', "Automatická registrace agenta ({$type})");
    
    echo json_encode([
        'success' => true,
        'agent_key' => $agent_key,
        'monitor_id' => $new_id,
        'name' => $name,
        'message' => 'Agent úspěšně zaregistrován.'
    ]);
    exit;
}

// The agent_key names the monitor; the report itself is bk_agent_report().
$agent_key = bk_agent_str($data, 'agent_key', 128) ?? '';

// CPU, RAM and disk may be null: agents send null on their first run and after a
// reboot, because a load needs two readings, and a host that cannot read one
// sends null for good. Refusing those reports turned an honest "not measured
// yet" into a 400 and a new router's first report into an error.
if (empty($agent_key)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Chybí agent_key.']);
    exit;
}

// Look the monitor up by agent_key (any type - an agent can be attached to any monitor)
$stmt = $pdo->prepare("SELECT * FROM monitors WHERE agent_key = ? LIMIT 1");
$stmt->execute([$agent_key]);
$monitor = $stmt->fetch();

if (!$monitor) {
    error_log('[agent_api] Auth failed: invalid agent_key from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' (key prefix: ' . substr($agent_key, 0, 8) . '...)');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Neplatný klíč agenta nebo monitor neexistuje.']);
    exit;
}

if (!empty($monitor['archived_at'])) {
    bk_agent_refuse_archived();
}

require_once __DIR__ . '/agent_report.php';

$base_url = bk_link_origin() . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$bk_report = bk_agent_report($pdo, $data, $monitor, $base_url);
if ($bk_report['code'] !== 200) {
    http_response_code($bk_report['code']);
    echo json_encode($bk_report['body']);
} else {
    echo json_encode($bk_report['body'], JSON_UNESCAPED_SLASHES);
}
