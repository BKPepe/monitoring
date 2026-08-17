<?php
/**
 * Heartbeat intake - the opposite direction from the rest of monitoring.
 *
 * An active check can only do what it reaches over the network. A backup that
 * runs at three in the morning and silently fails, or a cron that stopped,
 * are invisible to it: there is nothing to ping. So the job reports itself
 * and monitoring watches that it reported in time.
 *
 * Usage at the end of a job:
 *   curl -fsS -m 10 https://bloodkings.eu/status/heartbeat.php?token=TOKEN
 *
 * Reporting a failure (non-zero exit code or an error in the log):
 *   curl -fsS -m 10 "https://bloodkings.eu/status/heartbeat.php?token=TOKEN&status=fail&msg=Zaloha%20selhala"
 *
 * The endpoint only records the signal. Cron evaluates the state on its next
 * run - one place deciding state and sending notifications, not two.
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
// The response is stateful and must never be cached - neither by us nor on the way.
header('Cache-Control: no-store');

/** The token may come as ?token= or in the path (/heartbeat.php/TOKEN) for nicer URLs. */
$token = (string)($_GET['token'] ?? '');
if ($token === '' && !empty($_SERVER['PATH_INFO'])) {
    $token = trim((string)$_SERVER['PATH_INFO'], '/');
}

// Shape check before touching the database: the token is hex from
// bk_heartbeat_generate_token(), so anything else is a typo or scanning.
if ($token === '' || !preg_match('/^[0-9a-f]{16,64}$/', $token)) {
    http_response_code(404);
    echo json_encode(['error' => 'Neznámý token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, type FROM monitors WHERE heartbeat_token = ? AND type = 'heartbeat' LIMIT 1");
    $stmt->execute([$token]);
    $monitor = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[heartbeat] Dotaz na token selhal: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Databáze není dostupná.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$monitor) {
    // The same response as for a malformed token - validity cannot be probed
    // from here by trying.
    http_response_code(404);
    echo json_encode(['error' => 'Neznámý token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 'fail' must be said explicitly by the job; anything else (a typo too) is a success,
// so a parameter mistake cannot become a false outage.
$result = (($_GET['status'] ?? $_POST['status'] ?? 'ok') === 'fail') ? 'fail' : 'ok';

$message = trim((string)($_GET['msg'] ?? $_POST['msg'] ?? ''));
if ($message !== '') {
    // The column is VARCHAR(255); trimmed by characters, not bytes, so
    // diacritics are not cut in half.
    $message = mb_substr($message, 0, 255);
} else {
    $message = null;
}

try {
    // The time is written by PHP, not by the database via NOW().
    //
    // Cron's evaluation compares this value with time(), i.e. PHP's clock.
    // Were it written by the database in its own zone, a mere zone difference
    // would make the monitor report an outage for a job that reported on time -
    // exactly how it looked in the test environment (DB in UTC, PHP in Prague).
    // The same clock writes and reads, so the database setting does not matter.
    $stmt_up = $pdo->prepare(
        "UPDATE monitors
            SET last_heartbeat = ?,
                heartbeat_last_result = ?,
                heartbeat_last_message = ?
          WHERE id = ?"
    );
    $stmt_up->execute([date('Y-m-d H:i:s'), $result, $message, $monitor['id']]);
} catch (PDOException $e) {
    error_log('[heartbeat] Zápis signálu selhal: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Signál se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'monitor' => $monitor['name'],
    'result' => $result,
    'receivedAt' => date('c'),
], JSON_UNESCAPED_UNICODE);
