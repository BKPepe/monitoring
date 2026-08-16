<?php
/**
 * Příjem heartbeatů - opačný směr než zbytek monitoringu.
 *
 * Aktivní kontrola umí jen to, na co dosáhne ze sítě. Záloha, která se spustí
 * ve tři ráno a tiše selže, nebo cron, který přestal běžet, jsou pro ni
 * neviditelné: není co pingnout. Proto se sem hlásí úloha sama a monitoring
 * hlídá, že se ozvala včas.
 *
 * Použití na konci úlohy:
 *   curl -fsS -m 10 https://bloodkings.eu/status/heartbeat.php?token=TOKEN
 *
 * Ohlášení selhání (nenulový návratový kód nebo chyba v logu):
 *   curl -fsS -m 10 "https://bloodkings.eu/status/heartbeat.php?token=TOKEN&status=fail&msg=Zaloha%20selhala"
 *
 * Endpoint jen zapíše signál. Stav vyhodnotí cron při nejbližším běhu -
 * jedno místo, kde se rozhoduje o stavu a odesílají notifikace, místo dvou.
 */

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
// Odpověď je stavová a nikdy se nesmí kešovat - ani u nás, ani na cestě.
header('Cache-Control: no-store');

/** Token může přijít v ?token= i v cestě (/heartbeat.php/TOKEN) kvůli hezčím URL. */
$token = (string)($_GET['token'] ?? '');
if ($token === '' && !empty($_SERVER['PATH_INFO'])) {
    $token = trim((string)$_SERVER['PATH_INFO'], '/');
}

// Tvarová kontrola dřív, než se sáhne do databáze: token je hex z
// bk_heartbeat_generate_token(), takže cokoli jiného je překlep nebo skenování.
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
    // Stejná odpověď jako u špatného tvaru - platnost tokenu se odsud nedá
    // zjistit zkoušením.
    http_response_code(404);
    echo json_encode(['error' => 'Neznámý token.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 'fail' musí úloha říct výslovně; cokoli jiného (i překlep) je úspěch, aby
// se z chyby v parametru nestal falešný výpadek.
$result = (($_GET['status'] ?? $_POST['status'] ?? 'ok') === 'fail') ? 'fail' : 'ok';

$message = trim((string)($_GET['msg'] ?? $_POST['msg'] ?? ''));
if ($message !== '') {
    // Sloupec je VARCHAR(255); ořezáváme po znacích, ne po bajtech, aby se
    // diakritika nerozsekla uprostřed.
    $message = mb_substr($message, 0, 255);
} else {
    $message = null;
}

try {
    // Čas zapisuje PHP, ne databáze přes NOW().
    //
    // Vyhodnocení v cronu porovnává tenhle údaj s time(), tedy s hodinami PHP.
    // Kdyby ho zapsala databáze ve své časové zóně, stačil by rozdíl mezi
    // zónami a monitor by hlásil výpadek u úlohy, která se ozvala včas -
    // v testovacím prostředí (databáze v UTC, PHP v Praze) přesně tak vypadal.
    // Zapisuje i čte tytéž hodiny, takže na nastavení databáze nezáleží.
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
