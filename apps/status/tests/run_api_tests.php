<?php
/**
 * Integration tests of api.php against a real database and real HTTP.
 *
 * Running (requires MySQL/MariaDB and the PHP CLI):
 *   BK_TEST_DB_NAME=bk_test BK_TEST_DB_USER=root BK_TEST_DB_PASS=root \
 *     php apps/status/tests/run_api_tests.php
 *
 * Why this way: api.php is a monolith that loads its own config, opens
 * PDO and prints JSON directly. Extracting functions from it like the
 * other suites do is not possible. At the same time it is exactly the
 * queries and column names where this project kept tripping (the Prometheus
 * token wrote setting_key/setting_value instead of key_name/key_value and
 * the endpoint returned 500; daily_uptime referenced a variable that no
 * longer existed). Only really calling the endpoint reveals such bugs.
 *
 * The test therefore builds its own database from schema.sql, fills it with
 * known data, starts `php -S` over apps/status and talks to it via HTTP.
 */

require_once __DIR__ . '/assert_helpers.php';

$db_host = getenv('BK_TEST_DB_HOST') ?: '127.0.0.1';
$db_port = (int)(getenv('BK_TEST_DB_PORT') ?: 3306);
// An empty BK_TEST_DB_NAME is a typo in the caller's environment, never a wish
// to run against the default database: falling back to 'bk_test' would drop a
// database the caller did not name, and several suites in parallel would then
// share one and destroy each other. It ends the run instead.
$db_name_env = getenv('BK_TEST_DB_NAME');
if ($db_name_env !== false && trim($db_name_env) === '') {
    fwrite(STDERR, "BK_TEST_DB_NAME je prázdné - jméno testovací databáze musí být uvedené. Testy neproběhly.\n");
    exit(1);
}
$db_name = $db_name_env !== false ? trim($db_name_env) : 'bk_test';
$db_user = getenv('BK_TEST_DB_USER') ?: 'root';
$db_pass = getenv('BK_TEST_DB_PASS') ?: '';
$port = (int)(getenv('BK_TEST_PORT') ?: 8123);
$root = realpath(__DIR__ . '/..');

// The suite writes fixture rows that the application then reads, so it has to
// keep the application's time: config.php below defines TIMEZONE =
// 'Europe/Prague' and db.php puts the application's SESSION into that offset.
// Without the same two settings here the fixtures would be written with the
// database server's zone (UTC in the container) and every "three minutes ago"
// row would land two hours away from where the application looks for it.
date_default_timezone_set('Europe/Prague');

// --- 1. Database ----------------------------------------------------------
try {
    $pdo = new PDO("mysql:host={$db_host};port={$db_port};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // Not a skip: this suite IS the gate over api.php, and a run that never
    // reached the database verified nothing. Exiting 0 here made CI green on
    // zero checks - louder and red is the only honest answer.
    fwrite(STDERR, "MySQL není dostupná ({$e->getMessage()}) - integrační testy NEPROBĚHLY.\n");
    fwrite(STDERR, "Spusť databázi (kontejner bk-test-mysql) nebo oprav BK_TEST_DB_* a zkus to znovu.\n");
    exit(1);
}
$pdo_tz = $pdo->prepare("SET time_zone = ?");
$pdo_tz->execute([date('P')]);

$pdo->exec("DROP DATABASE IF EXISTS `{$db_name}`");
$pdo->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$db_name}`");

// schema.sql runs statement by statement - PDO::exec handles multiple
// queries at once only sometimes and an error would stay invisible.
$schema = file_get_contents($root . '/schema.sql');
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
foreach (array_filter(array_map('trim', explode(";\n", $schema))) as $sql) {
    if ($sql === '') {
        continue;
    }
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // The INSERT sequences with sample data do not matter for the tests.
        if (!str_contains($e->getMessage(), 'Duplicate')) {
            fwrite(STDERR, "schema.sql: " . substr($e->getMessage(), 0, 120) . "\n");
        }
    }
}

// --- 2. Known test data ---------------------------------------------------
// A website with a measured latency and SSL, an agent WITHOUT measured
// metrics. The latter is here on purpose: most regressions in this project
// were about an unmeasured value showing up as zero.
$pdo->exec("INSERT INTO monitors (id, name, type, target, port, status, category)
            VALUES (1, 'Testovací web', 'web', 'https://example.com', 443, 'up', 'Weby')");
$pdo->exec("INSERT INTO monitors (id, name, type, target, port, status, category)
            VALUES (2, 'Router bez metrik', 'openwrt', '10.0.0.1', NULL, 'up', 'Síť')");
$pdo->exec("UPDATE monitors SET last_details = '" . json_encode([
    'ssl_days_remaining' => 42,
    'ssl_issuer' => 'Test CA',
]) . "' WHERE id = 1");

for ($i = 0; $i < 10; $i++) {
    $pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at)
                VALUES (1, 'up', 120, DATE_SUB(NOW(), INTERVAL {$i} MINUTE))");
}
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at)
            VALUES (1, 'down', NULL, DATE_SUB(NOW(), INTERVAL 30 MINUTE))");
// The agent reports it is alive but measured no metrics - the response must hold null.
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, checked_at)
            VALUES (2, NULL, NULL, NULL, NOW())");

// --- 3. config.php for the test instance ----------------------------------
$config_path = $root . '/config.php';
$config_backup = file_exists($config_path) ? file_get_contents($config_path) : null;
// The X-BK-Test-DB-Down header points one request at a closed port on
// 127.0.0.1: the database-down path is tested without stopping the shared
// MySQL container other suites may be using. It exists only in this generated
// file, never in a real config.php.
file_put_contents($config_path, "<?php\n"
    . "ini_set('display_errors', 1);\n"
    . "error_reporting(E_ALL);\n"
    . "if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }\n"
    . "\$bk_test_db_down = isset(\$_SERVER['HTTP_X_BK_TEST_DB_DOWN']);\n"
    . "define('DB_DRIVER', 'mysql');\n"
    . "define('DB_HOST', \$bk_test_db_down ? '127.0.0.1' : " . var_export($db_host, true) . ");\n"
    . "define('DB_PORT', \$bk_test_db_down ? 1 : {$db_port});\n"
    . "define('DB_NAME', " . var_export($db_name, true) . ");\n"
    . "define('DB_USER', " . var_export($db_user, true) . ");\n"
    . "define('DB_PASS', " . var_export($db_pass, true) . ");\n"
    . "define('TIMEZONE', 'Europe/Prague');\n"
    . "date_default_timezone_set(TIMEZONE);\n");

// --- 4. The built-in PHP server -------------------------------------------
$server = proc_open(
    sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($root)),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);

$cleanup = function () use ($server, $config_path, $config_backup) {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    // config.php belongs to the developer, not the test - restored to its original state.
    if ($config_backup !== null) {
        file_put_contents($config_path, $config_backup);
    } elseif (file_exists($config_path)) {
        unlink($config_path);
    }
};
register_shutdown_function($cleanup);

// The server takes a moment to start; wait for the first successful connection.
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
        break;
    }
    usleep(100000);
}

/** Calls an endpoint and returns [status code, decoded JSON, raw body]. */
function api_get(string $base, string $query): array {
    $ch = curl_init($base . '/api.php?' . $query);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$body, true), (string)$body];
}

/** The cookie jar keeps the PHP session between requests (admin login). */
$cookie_jar = tempnam(sys_get_temp_dir(), 'bk_test_cookies');
register_shutdown_function(function () use ($cookie_jar) {
    if ($cookie_jar && file_exists($cookie_jar)) {
        unlink($cookie_jar);
    }
});

/** POST with a JSON body; shares the session via the cookie jar. */
function api_post(string $base, string $query, array $payload, string $jar, ?string $csrf = null): array {
    // Since 2026-08-17 the server enforces a CSRF token on all writes - the
    // tests send it in the header just like the real client. Captured at
    // login (see $GLOBALS['bk_test_csrf'] at action=login). An explicit '' =
    // do not send (tests verifying the server refuses without a token).
    $headers = ['Content-Type: application/json'];
    $token = $csrf ?? ($GLOBALS['bk_test_csrf'] ?? '');
    if ($token !== '') {
        $headers[] = 'X-CSRF-Token: ' . $token;
    }
    $ch = curl_init($base . '/api.php?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$body, true), (string)$body];
}

/**
 * A signal to heartbeat.php - a different script from api.php, hence its own
 * helper. No login: the token is all the endpoint authorises.
 */
function hb_ping(string $base, string $token, string $extra = ''): array {
    $url = $base . '/heartbeat.php?token=' . rawurlencode($token) . ($extra !== '' ? '&' . $extra : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$body, true), (string)$body];
}

/** GET with a session - for endpoints returning more to the logged-in. */
function api_get_auth(string $base, string $query, string $jar): array {
    $ch = curl_init($base . '/api.php?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$body, true), (string)$body];
}

// No known default password. schema.sql used to create "admin" with a
// password printed in the public repository; now a fresh install has no
// account at all and the first administrator comes from the setup step, with
// a password only the installer knows. The suite installs itself the same way,
// with a password generated for this run.
check('schema.sql nezakládá žádný účet (žádné výchozí heslo)', (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(), 0);
[, $fresh_session] = api_get($base, 'action=session');
check('čerstvá instalace hlásí installed=false', $fresh_session['installed'] ?? 'chybí', false);
$bk_test_admin_password = 'Test-' . bin2hex(random_bytes(8));
$setup_jar = tempnam(sys_get_temp_dir(), 'bk_setup0');
[$code, $fresh_setup] = api_post($base, 'action=setup', [
    'username' => 'admin', 'email' => 'admin@bloodkings.eu', 'password' => $bk_test_admin_password,
], $setup_jar, '');
check('průvodce instalací založí prvního admina', $code, 200);
check('a je to účet 1', $fresh_setup['id'] ?? null, 1);
// The wizard signs the new admin in; that session must be able to write. It
// had no CSRF token, so the first save in the app ended on 403.
[, $setup_session] = api_get_auth($base, 'action=session', $setup_jar);
check_true('po instalaci má relace CSRF token a setup ho vrací',
    ($setup_session['csrfToken'] ?? '') !== '' && ($setup_session['csrfToken'] ?? null) === ($fresh_setup['csrfToken'] ?? false));
// A write that passes the CSRF gate and then refuses its input (400, nothing
// stored) - 403 would be the gate.
[$code] = api_post($base, 'action=save_settings', ['settings' => 'x'], $setup_jar, (string)($fresh_setup['csrfToken'] ?? ''));
check('a první zápis nového admina projde branou CSRF (400 za vstup, ne 403)', $code, 400);
@unlink($setup_jar);

// Monitor data belongs to the accounts assigned to it, so almost every read
// below needs a session. The admin logs in first; the tests that check what an
// anonymous caller or a plain user gets call without this jar on purpose.
[$code, $login] = api_post($base, 'action=login', [
    'username' => 'admin',
    'password' => $bk_test_admin_password,
], $cookie_jar);
$logged_in = $code === 200 && !empty($login['success']);
check_true('přihlášení admina projde', $logged_in);
// The CSRF token for all further writes - the same source as the real client.
$GLOBALS['bk_test_csrf'] = (string)($login['csrfToken'] ?? '');
check_true('login vrací CSRF token', $GLOBALS['bk_test_csrf'] !== '');

// =======================================================================
// 1. monitors - the backbone endpoint, called by every app page
// =======================================================================
[$code, $data, $raw] = api_get_auth($base, 'action=monitors', $cookie_jar);
check('monitors vrací HTTP 200', $code, 200);
check_true('monitors vrací pole monitorů', isset($data['monitors']) && is_array($data['monitors']));

$by_id = [];
foreach (($data['monitors'] ?? []) as $m) {
    $by_id[$m['id']] = $m;
}
check_true('web monitor je v odpovědi', isset($by_id[1]));
check_true('agent monitor je v odpovědi', isset($by_id[2]));

// The heart of the honesty rule: unmeasured metrics are null, not zero.
// array_key_exists, not ?? - the ?? operator treats NULL as missing and
// would trip up a test for null.
$agent = $by_id[2] ?? [];
foreach (['cpu' => 'CPU', 'ram' => 'RAM', 'hdd' => 'disk'] as $key => $label) {
    check_true("klíč {$key} je v odpovědi přítomen", array_key_exists($key, $agent));
    check("nezměřené {$label} je null, ne 0", $agent[$key] ?? null, null);
    check_false("nezměřené {$label} není nula", ($agent[$key] ?? null) === 0 || ($agent[$key] ?? null) === 0.0);
}
check('naměřená odezva se vrací', (int)($by_id[1]['responseMs'] ?? 0), 120);

// Anonymous access must not see the network topology.
check_true(
    'anonymní odpověď neobsahuje wan_ipv4',
    !str_contains($raw, 'wan_ipv4') && !str_contains($raw, 'wireguard_peers')
);

// Announced maintenance is public (the legacy page prints it in a banner), but
// the description may leave ONLY while the flag is on - a stale description of
// a past window is an internal note, not a public announcement.
check_true('monitor nese klíč maintenance', array_key_exists('maintenance', $by_id[1] ?? []));
check_false('bez údržby je maintenance false', ($by_id[1]['maintenance'] ?? null) === true);
check('bez údržby se popis nevrací', $by_id[1]['maintenanceDescription'] ?? null, null);

$pdo->exec("UPDATE monitors SET maintenance = 1, maintenance_description = 'Výměna disku', maintenance_end = '2030-01-02 03:00:00' WHERE id = 1");
[, $mnt_data] = api_get($base, 'action=monitors');
$mnt_by_id = [];
foreach (($mnt_data['monitors'] ?? []) as $m) {
    $mnt_by_id[$m['id']] = $m;
}
check_true('zapnutá údržba je v anonymní odpovědi', ($mnt_by_id[1]['maintenance'] ?? null) === true);
check('popis údržby je veřejný, dokud běží', $mnt_by_id[1]['maintenanceDescription'] ?? null, 'Výměna disku');
check('konec údržby je veřejný, dokud běží', $mnt_by_id[1]['maintenanceEnd'] ?? null, '2030-01-02 03:00:00');

// Switching the flag off hides the description again even though it stayed in the DB.
$pdo->exec("UPDATE monitors SET maintenance = 0 WHERE id = 1");
[, $mnt_data2] = api_get($base, 'action=monitors');
$mnt_row2 = null;
foreach (($mnt_data2['monitors'] ?? []) as $m) {
    if ((int)$m['id'] === 1) $mnt_row2 = $m;
}
check('po vypnutí údržby je popis zase skrytý', $mnt_row2['maintenanceDescription'] ?? null, null);
$pdo->exec("UPDATE monitors SET maintenance_description = NULL, maintenance_end = NULL WHERE id = 1");

// A monitor that is down has no uptime - it has an outage duration.
// uptimeSeconds used to be 0 for a down monitor ("uptime 0 s"), which the
// dashboard printed as if the service had just come back.
// PHP-side timestamps: api.php reads the column with strtotime() in PHP's
// timezone, and the container's NOW() is not necessarily in the same zone.
$dn_stmt = $pdo->prepare("UPDATE monitors SET status = 'down', last_status_change = ? WHERE id = 1");
$dn_stmt->execute([date('Y-m-d H:i:s', time() - 7200)]);
[, $dn_data] = api_get_auth($base, 'action=monitors', $cookie_jar);
$dn_row = null;
foreach (($dn_data['monitors'] ?? []) as $m) {
    if ((int)$m['id'] === 1) $dn_row = $m;
}
check_true('výpadek: klíč uptimeSeconds je přítomen', is_array($dn_row) && array_key_exists('uptimeSeconds', $dn_row));
// array_key_exists, not ?? - ?? would turn the null under test into the fallback.
check_true('výpadek: uptimeSeconds je null, ne 0', is_array($dn_row) && array_key_exists('uptimeSeconds', $dn_row) && $dn_row['uptimeSeconds'] === null);
// The magnitude is checked against the API's own lastStatusChange (ISO with
// offset, so strtotime() is timezone-safe) - the test's DB session and the
// app's PHP do not share a timezone, so "2 hours ago" is not comparable.
$dn_since = $dn_row['sinceStatusChangeSeconds'] ?? null;
$dn_expected = !empty($dn_row['lastStatusChange']) ? time() - strtotime((string)$dn_row['lastStatusChange']) : null;
check_true('výpadek: sinceStatusChangeSeconds je celé číslo', is_int($dn_since) && $dn_since > 0);
check_true('výpadek: sinceStatusChangeSeconds sedí na lastStatusChange', $dn_expected !== null && $dn_since !== null && abs($dn_since - $dn_expected) <= 5);
check_true('bez agenta je agentSilent null (nikdy nehlásil)', array_key_exists('agentSilent', $dn_row) && $dn_row['agentSilent'] === null);

// agent_offline_timeout = 0 means "detection off" (cron honours it), so there
// is no verdict to hand out - an agent that reported a second ago must not be
// reported as silent.
$ag_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('agent_offline_timeout', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
$pdo->exec("UPDATE monitors SET last_details = '" . json_encode(['agent_last_seen' => time() - 60]) . "' WHERE id = 2");
$ag_set->execute(['0']);
[, $ag_data] = api_get_auth($base, 'action=monitors', $cookie_jar);
$ag_row = null;
foreach (($ag_data['monitors'] ?? []) as $m) {
    if ((int)$m['id'] === 2) $ag_row = $m;
}
check_true('vypnutá detekce: agentSilent je null, ne true', array_key_exists('agentSilent', $ag_row ?? []) && $ag_row['agentSilent'] === null);
$ag_set->execute(['50']);
[, $ag_data] = api_get_auth($base, 'action=monitors', $cookie_jar);
foreach (($ag_data['monitors'] ?? []) as $m) {
    if ((int)$m['id'] === 2) $ag_row = $m;
}
check('čerstvý agent mlčící není', $ag_row['agentSilent'] ?? 'chybí', false);
$pdo->exec("UPDATE monitors SET last_details = '" . json_encode(['agent_last_seen' => time() - 7200]) . "' WHERE id = 2");
[, $ag_data] = api_get_auth($base, 'action=monitors', $cookie_jar);
foreach (($ag_data['monitors'] ?? []) as $m) {
    if ((int)$m['id'] === 2) $ag_row = $m;
}
check('agent po dvou hodinách mlčí', $ag_row['agentSilent'] ?? 'chybí', true);
$pdo->exec("UPDATE monitors SET last_details = NULL WHERE id = 2");

// A recovery is an OK check whose immediately preceding check failed. The
// events list mixes older outages in, so the frontend cannot tell from row
// order - the server marks the row it can actually prove.
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at)
            VALUES (2, 'down', NULL, DATE_SUB(NOW(), INTERVAL 4 MINUTE))");
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at)
            VALUES (2, 'up', 5, DATE_SUB(NOW(), INTERVAL 3 MINUTE))");
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at)
            VALUES (2, 'up', 6, DATE_SUB(NOW(), INTERVAL 2 MINUTE))");
[$ev_code, $ev_data] = api_get_auth($base, 'action=events&monitor_id=2&limit=200', $cookie_jar);
check('events vrací 200', $ev_code, 200);
$ev_rows = $ev_data['events'] ?? [];
check_true('events vrací tři řádky monitoru', count($ev_rows) === 3);

// Řádek "co se změnilo" se nedá uhodnout ze seznamu: ten drží nejnovější
// kontroly plus nejnovější výpadky, takže samotný přechod v něm často není.
// Server proto vrací ten řádek přímo, přišpendlený na last_status_change.
$pdo->exec("UPDATE monitors SET status = 'up', last_status_change = DATE_SUB(NOW(), INTERVAL 3 MINUTE) WHERE id = 2");
[, $sc_data] = api_get_auth($base, 'action=events&monitor_id=2&limit=200', $cookie_jar);
check_true('events vrací statusChange', array_key_exists('statusChange', $sc_data));
check('a je to ta kontrola, která stav změnila', $sc_data['statusChange']['status'] ?? null, 'up');
check_true(
    'zná i stav, ze kterého se přešlo',
    ($sc_data['statusChange']['fromStatus'] ?? null) === 'down'
);
// Bez zaznamenané změny se nic nevymýšlí.
$pdo->exec("UPDATE monitors SET last_status_change = DATE_SUB(NOW(), INTERVAL 40 DAY) WHERE id = 2");
[, $sc_none] = api_get_auth($base, 'action=events&monitor_id=2&limit=200', $cookie_jar);
check_true(
    'bez odpovídajícího řádku zůstává statusChange null',
    array_key_exists('statusChange', $sc_none) && $sc_none['statusChange'] === null
);
// Newest first: [0] běžná OK kontrola, [1] obnovení, [2] výpadek.
check('nejnovější OK kontrola není obnovení', $ev_rows[0]['isRecovery'] ?? 'chybí', false);
check('OK kontrola hned po výpadku je obnovení', $ev_rows[1]['isRecovery'] ?? 'chybí', true);
check('výpadek sám obnovením není', $ev_rows[2]['isRecovery'] ?? 'chybí', false);
$pdo->exec("DELETE FROM monitor_logs WHERE monitor_id = 2");
$dn_stmt = $pdo->prepare("UPDATE monitors SET status = 'up', last_status_change = ? WHERE id = 1");
$dn_stmt->execute([date('Y-m-d H:i:s', time() - 1800)]);
[, $up_data] = api_get_auth($base, 'action=monitors', $cookie_jar);
foreach (($up_data['monitors'] ?? []) as $m) {
    if ((int)$m['id'] === 1) $dn_row = $m;
}
check_true('běží: uptimeSeconds je celé číslo', is_int($dn_row['uptimeSeconds'] ?? null) && $dn_row['uptimeSeconds'] > 0);
check('běží: uptimeSeconds = doba od změny stavu', $dn_row['uptimeSeconds'] ?? null, $dn_row['sinceStatusChangeSeconds'] ?? 'chybí');

// ui_config carries portalUrl for the public page footer - the key must exist
// even when empty, so the frontend can tell "unset" from "old server without the field".
[$code, $uicfg] = api_get($base, 'action=ui_config');
check('ui_config vrací HTTP 200', $code, 200);
check_true('ui_config nese portalUrl', array_key_exists('portalUrl', $uicfg ?? []));

// uptime_windows: four windows in one query. Monitor 1 has seeded up and down
// measurements (must land between 0 and 100), monitor 2 has not a single log -
// it must not appear in the response at all (the frontend turns absence into a dash, not 100 %).
[$code, $uw] = api_get_auth($base, 'action=uptime_windows', $cookie_jar);
check('uptime_windows vrací HTTP 200', $code, 200);
$uw_m1 = $uw['windows']['1'] ?? ($uw['windows'][1] ?? null);
check_true('uptime_windows zná monitor 1', is_array($uw_m1));
foreach (['d1', 'd7', 'd30', 'd90'] as $uw_key) {
    check_true("okno {$uw_key} existuje", array_key_exists($uw_key, $uw_m1 ?? []));
}
check_true('d1 je mezi 0 a 100 (up i down v seedu)', is_numeric($uw_m1['d1'] ?? null) && $uw_m1['d1'] > 0 && $uw_m1['d1'] < 100);
check_true('monitor bez logů v oknech není', !isset($uw['windows']['2']) && !isset($uw['windows'][2]));

// daily_uptime carries avgMs for the latency sparkline - an average of real
// answers only (the down row with a NULL latency must not drag it down).
[, $du] = api_get_auth($base, 'action=daily_uptime&days=7', $cookie_jar);
$du_days = $du['series']['1'] ?? ($du['series'][1] ?? []);
$du_has_120 = false;
$du_has_key = false;
foreach ($du_days as $du_day) {
    if (array_key_exists('avgMs', $du_day)) $du_has_key = true;
    if (($du_day['avgMs'] ?? null) === 120) $du_has_120 = true;
}
check_true('daily_uptime dny nesou klíč avgMs', $du_has_key);
check_true('den s měřeními má avgMs 120', $du_has_120);

// badge: embeddable SVG. An unknown monitor is 404, not an invented green badge.
[$code, , $bdg_raw] = api_get($base, 'action=badge');
check('badge (flotila) vrací HTTP 200', $code, 200);
check_true('badge je SVG', str_contains($bdg_raw, '<svg'));
$bdg_name = $pdo->query("SELECT name FROM monitors WHERE id = 1")->fetchColumn();
[$code, , $bdg_raw1] = api_get($base, 'action=badge&monitor_id=1');
check('badge monitoru vrací HTTP 200', $code, 200);
check_true('badge nese jméno monitoru', str_contains($bdg_raw1, htmlspecialchars((string)$bdg_name, ENT_QUOTES)));
[$code] = api_get($base, 'action=badge&monitor_id=99999');
check('badge neexistujícího monitoru je 404', $code, 404);

// type=uptime: monitor 1 has measurements (must return a percentage), monitor 2
// has not a single log - "no data", never an invented percentage.
[$code, , $bdg_up] = api_get($base, 'action=badge&monitor_id=1&type=uptime');
check('uptime badge vrací HTTP 200', $code, 200);
check_true('uptime badge nese procenta', (bool)preg_match('/\d+\.\d{2} %/', $bdg_up));
// Monitor 2 is the home router, off the public page (W1-G3): the badge is
// asked for with the admin's session, the anonymous 404 is checked later.
[, , $bdg_nodata] = api_get_auth($base, 'action=badge&monitor_id=2&type=uptime', $cookie_jar);
check_true('bez měření říká "bez dat"', str_contains($bdg_nodata, 'bez dat'));

// badge.php is a deprecated alias - old README embeds must keep working
// via a 302 to action=badge with the parameters preserved.
$ch_alias = curl_init($base . '/badge.php?id=1&type=uptime');
curl_setopt_array($ch_alias, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($ch_alias);
$alias_code = (int)curl_getinfo($ch_alias, CURLINFO_RESPONSE_CODE);
$alias_loc = (string)curl_getinfo($ch_alias, CURLINFO_REDIRECT_URL);
curl_close($ch_alias);
check('badge.php přesměrovává', $alias_code, 302);
check_true('alias míří na action=badge s parametry', str_contains($alias_loc, 'action=badge') && str_contains($alias_loc, 'monitor_id=1') && str_contains($alias_loc, 'type=uptime'));

// =======================================================================
// 2. public_status - the basis of the public page and the widget
// =======================================================================
[$code, $data] = api_get($base, 'action=public_status');
check('public_status vrací HTTP 200', $code, 200);
check_true('public_status zná počet monitorů', isset($data['totalMonitors']));
// The web is on the public page, the home router is not (W1-G3).
check('veřejný public_status počítá jen web, router ne', (int)($data['totalMonitors'] ?? 0), 1);
[, $data_admin] = api_get_auth($base, 'action=public_status', $cookie_jar);
check('v aplikaci admin počítá oba monitory', (int)($data_admin['totalMonitors'] ?? 0), 2);

// =======================================================================
// 3. dashboard_layout - the tile catalogue (new feature, previously untested)
// =======================================================================
[$code, $data] = api_get_auth($base, 'action=dashboard_layout', $cookie_jar);
check('dashboard_layout vrací HTTP 200', $code, 200);
check_true('katalog je pole', isset($data['catalog']) && is_array($data['catalog']));

$panels = array_filter($data['catalog'] ?? [], fn($c) => ($c['kind'] ?? '') === 'panel');
check_true('katalog nabízí panely dashboardu', count($panels) >= 5);

// A metric without a single sample must not be offered as available - the user
// would enable a tile that stays forever empty.
foreach (($data['catalog'] ?? []) as $entry) {
    if (($entry['kind'] ?? '') === 'metric' && ($entry['key'] ?? '') === 'metric_cpu') {
        check('metrika bez vzorků není available', $entry['available'], false);
    }
}

// =======================================================================
// 4. websites_overview - the SLA windows for the websites page
// =======================================================================
[$code, $data] = api_get_auth($base, 'action=websites_overview', $cookie_jar);
check('websites_overview vrací HTTP 200', $code, 200);
check_true('vrací mapu monitorů', isset($data['monitors']) && is_array($data['monitors']));

$sla = $data['monitors'][1] ?? $data['monitors']['1'] ?? null;
check_true('web má spočítané SLA za 7 dní', $sla !== null && $sla['sla7'] !== null);
// In time, not in rows (W1-B1): the rows are one minute apart, so the 'down'
// row 30 minutes ago stands for 2.5 minutes (the cap), the nine up minutes
// after it for nine; the 18 minutes between them nobody measured. That is
// 78-82 % depending on how long after the insert the request runs. The row
// ratio (10 up / 11) was 90.9 % and a lost 'down' would be 100 %.
check_true(
    'SLA počítá výpadek v čase, ne v řádcích (dostal ' . json_encode($sla['sla7'] ?? null) . ')',
    $sla !== null && $sla['sla7'] !== null && $sla['sla7'] > 75 && $sla['sla7'] < 85
);

$sla_agent = $data['monitors'][2] ?? $data['monitors']['2'] ?? null;
check('monitor bez logů nemá vymyšlené SLA', $sla_agent, null);

// =======================================================================
// 5. Authorisation - mutating operations must not be reachable without login
// =======================================================================
foreach ([
    'incident_action' => 'akce nad incidentem',
    'create_incident' => 'založení incidentu',
    'save_settings' => 'uložení nastavení',
    'test_notification' => 'testovací notifikace',
] as $action => $label) {
    $ch = curl_init($base . '/api.php?action=' . $action);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    check_true("{$label} bez přihlášení je odmítnuto (dostal {$code})", in_array($code, [401, 403], true));
}

// =======================================================================
// 6. An unknown action and missing parameters must not crash the endpoint
// =======================================================================
[$code, , $raw] = api_get($base, 'action=neexistujici_akce_xyz');
check_true('neznámá akce nekončí chybou serveru', $code < 500);
check_true('odpověď neobsahuje fatální chybu', !str_contains($raw, 'Fatal error'));

[$code, , $raw] = api_get_auth($base, 'action=sla_report&days=999999', $cookie_jar);
check_true('nesmyslný rozsah SLA nekončí chybou serveru', $code < 500);
check_true('SLA report neobsahuje fatální chybu', !str_contains($raw, 'Fatal error'));

// Values pinned to the seed - they hold equivalence across the rewrite of the
// 3-queries-per-monitor loop into batched queries (see sla_report in api.php).
[, $sla_pin] = api_get_auth($base, 'action=sla_report&days=30', $cookie_jar);
$sla_by_id = [];
foreach (($sla_pin['monitors'] ?? []) as $sm) { $sla_by_id[(int)$sm['id']] = $sm; }
check('p50 monitoru 1 je 120 ms', $sla_by_id[1]['p50Ms'] ?? null, 120);
check('p99 monitoru 1 je 120 ms', $sla_by_id[1]['p99Ms'] ?? null, 120);
check_true('poslední výpadek monitoru 1 je vyřešený', ($sla_by_id[1]['lastOutage']['resolved'] ?? null) === true);
// array_key_exists, not ?? - the ?? operator treats NULL as a missing value.
check_true('monitor bez logů má p50 null, ne nulu', array_key_exists('p50Ms', $sla_by_id[2]) && $sla_by_id[2]['p50Ms'] === null);
check_true('a uptime null, ne 100', array_key_exists('uptimePercent', $sla_by_id[2]) && $sla_by_id[2]['uptimePercent'] === null);

// =======================================================================
// =======================================================================
// 7. WRITE ENDPOINTS
//
// This part exists because save_monitor turned out to use a $preset_id that
// was never assigned - every monitor save silently erased its preset.
// Read tests cannot see such a bug; only saving and reading back
// can catch it.
// =======================================================================


// The write guard end-to-end: a write without a token must fail with 403 before
// touching anything, and a GET on a POST-only action with 405. Without this the
// whole CSRF protection would be dead code nobody ever enforced.
[$wg_code, $wg_data] = api_post($base, 'action=create_incident', ['title' => 'CSRF test'], $cookie_jar, '');
check('zápis bez CSRF tokenu je 403', $wg_code, 403);
check_true('a hláška mluví o CSRF', str_contains((string)($wg_data['error'] ?? ''), 'CSRF'));
[$wg_code2] = api_get_auth($base, 'action=send_digest&period=weekly', $cookie_jar);
check('GET na POST-only akci je 405', $wg_code2, 405);
[$wg_code3, $wg_unknown] = api_get($base, 'action=neexistujici_akce');
check('neznámá akce je 400, ne tichých 200', $wg_code3, 400);
check_true('a nese klíč error', isset($wg_unknown['error']));

// Sign-out from the app: POST only, it ends the session the legacy admin page
// shares, and it leaves a trace in the audit log like that page's sign-out.
$jar_lo = tempnam(sys_get_temp_dir(), 'bk_test_lo');
[$lo_login] = api_post($base, 'action=login', ['username' => 'admin', 'password' => $bk_test_admin_password], $jar_lo, '');
check('druhá relace admina se přihlásí', $lo_login, 200);
[$lo_get] = api_get_auth($base, 'action=logout', $jar_lo);
check('odhlášení přes GET se odmítne', $lo_get, 405);
// The client drops its cookie on any logout answer, so the jar alone cannot
// prove the server ended the session. The old session id is replayed directly.
$lo_sid = null;
foreach (file($jar_lo) ?: [] as $lo_line) {
    $lo_line = trim($lo_line);
    if (str_starts_with($lo_line, '#HttpOnly_')) {
        $lo_line = substr($lo_line, strlen('#HttpOnly_'));
    } elseif ($lo_line === '' || $lo_line[0] === '#') {
        continue;
    }
    $lo_parts = explode("\t", $lo_line);
    if (count($lo_parts) >= 7 && $lo_parts[5] === 'PHPSESSID') {
        $lo_sid = $lo_parts[6];
    }
}
$lo_replay = function (string $sid) use ($base): array {
    $ch = curl_init($base . '/api.php?action=session');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIE => 'PHPSESSID=' . $sid]);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true)];
};
check_true('relace má ID, které jde přehrát', is_string($lo_sid) && $lo_sid !== '');
[, $lo_replay_before] = $lo_replay((string)$lo_sid);
check('přehrané ID je před odhlášením přihlášené', $lo_replay_before['authenticated'] ?? null, true);
$lo_before = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'logout'")->fetchColumn();
[$lo_code, $lo_res] = api_post($base, 'action=logout', [], $jar_lo, '');
check('odhlášení projde', $lo_code, 200);
check('a server ho potvrdí', $lo_res['success'] ?? null, true);
[$lo_replay_code, $lo_replay_after] = $lo_replay((string)$lo_sid);
check('staré ID relace po odhlášení odpoví', $lo_replay_code, 200);
check('staré ID relace už nikoho nepřihlásí', $lo_replay_after['authenticated'] ?? null, false);
check('odhlášení se zapíše do auditu', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'logout'")->fetchColumn(), $lo_before + 1);
check('záznam nese odhlášený účet', $pdo->query("SELECT actor_username FROM audit_log WHERE action = 'logout' ORDER BY id DESC LIMIT 1")->fetchColumn(), 'admin');
[, $lo_main] = api_get_auth($base, 'action=session', $cookie_jar);
check_true('jiná relace téhož admina zůstane přihlášená', !empty($lo_main['authenticated']));
@unlink($jar_lo);

// CORS: a response to a foreign Origin must not carry Allow-Origin (anyone
// could read credentialed responses otherwise); the own origin gets it.
$cors_probe = function (string $origin) use ($base): string {
    $ch = curl_init($base . '/api.php?action=public_status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Origin: ' . $origin],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = (string)curl_exec($ch);
    curl_close($ch);
    return $resp;
};
check_false('cizí Origin nedostane Allow-Origin', stripos($cors_probe('https://evil.example'), 'Access-Control-Allow-Origin') !== false);
// $base, not a fixed port: a run on a scratch copy (BK_TEST_PORT) has another origin.
check_true('vlastní Origin Allow-Origin dostane', stripos($cors_probe($base), 'Access-Control-Allow-Origin: ' . $base) !== false);

// save_user/delete_user: do 2026-08-17 v api.php neexistovaly - React je
// called them, got 400 "unknown action" and user management from the app did not work.
[$su_code, $su_res] = api_post($base, 'action=save_user', ['username' => 'audit_tester', 'email' => 'audit@example.com', 'role' => 'user', 'password' => 'TesterHeslo123!'], $cookie_jar);
check('save_user vytvoří účet', $su_code, 200);
$su_new_id = (int)($su_res['id'] ?? 0);
check_true('vrací id nového účtu', $su_new_id > 0);
api_post($base, 'action=save_user', ['id' => $su_new_id, 'username' => 'audit_tester', 'email' => 'audit2@example.com', 'role' => 'user'], $cookie_jar);
$su_mail_stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$su_mail_stmt->execute([$su_new_id]);
check('úprava e-mailu se skutečně uloží', $su_mail_stmt->fetchColumn(), 'audit2@example.com');
[$sd_code] = api_post($base, 'action=delete_user', ['id' => 1], $cookie_jar);
check('vlastní přihlášený účet smazat nejde', $sd_code, 400);

// Roles: a regular account (role user) must not reach admin writes; its own profile works.
$jar3 = tempnam(sys_get_temp_dir(), 'bk_test_c3');
[$u3_code, $u3_login] = api_post($base, 'action=login', ['username' => 'audit_tester', 'password' => 'TesterHeslo123!'], $jar3, '');
check_true('tester se přihlásí', $u3_code === 200 && !empty($u3_login['success']));
$u3_csrf = (string)($u3_login['csrfToken'] ?? '');
[$ri_code] = api_post($base, 'action=create_incident', ['title' => 'nesmí projít'], $jar3, $u3_csrf);
check('běžný uživatel nezaloží incident', $ri_code, 403);
[$rp_code] = api_post($base, 'action=save_preset', ['name' => 'nesmí projít'], $jar3, $u3_csrf);
check('běžný uživatel neuloží preset', $rp_code, 403);
// Muting a router recommendation silences a finding for EVERYBODY who can see
// the router, so it is an admin decision (CORE 3.9) - the CSRF token alone
// must not be enough.
[$rm3_code] = api_post($base, 'action=router_recommendation_mute',
    ['monitor_id' => 2, 'key' => 'fs_nearly_full:m:x', 'muted' => true], $jar3, $u3_csrf);
check('běžný uživatel neztlumí doporučení routeru', $rm3_code, 403);
[$mp3_code] = api_get_auth($base, 'action=my_profile', $jar3);
check('vlastní profil běžnému uživateli funguje', $mp3_code, 200);

// The maintenance switch and five admin reads were gated on login alone: a
// 'user' account could silence every alert by putting the monitors into
// maintenance, and download every user's e-mail and phone, the delivery log
// and the configuration export.
$rtm_before = (int)$pdo->query("SELECT maintenance FROM monitors WHERE id = 1")->fetchColumn();
[$rtm_code] = api_post($base, 'action=toggle_maintenance', ['monitor_ids' => [1], 'maintenance' => !$rtm_before], $jar3, $u3_csrf);
check('běžný uživatel nepřepne údržbu', $rtm_code, 403);
check('údržba monitoru zůstala, jak byla', (int)$pdo->query("SELECT maintenance FROM monitors WHERE id = 1")->fetchColumn(), $rtm_before);
foreach ([
    'users' => 'action=users',
    'notification_log' => 'action=notification_log&monitor_id=1',
    // Filtry ani souhrn nejsou jiná dvířka do téže tabulky - adresáti jsou
    // osobní údaj bez ohledu na to, jak se na ně někdo zeptá.
    'notification_log se souhrnem' => 'action=notification_log&summary=1&kind=alert&ok=0',
    'export_config' => 'action=export_config',
    'audit_logs' => 'action=audit_logs',
] as $ra_name => $ra_query) {
    [$ra_code] = api_get_auth($base, $ra_query, $jar3);
    check("běžný uživatel nedostane {$ra_name}", $ra_code, 403);
}
// A monitor that is not assigned answers exactly like one that does not exist.
foreach ([
    'process_top' => 'action=process_top&monitor_id=1',
    'interface_traffic_daily' => 'action=interface_traffic_daily&monitor_id=1',
    'export_csv' => 'action=export_csv&monitor_id=1',
] as $ra_name => $ra_query) {
    [$ra_code] = api_get_auth($base, $ra_query, $jar3);
    check("nepřiřazený monitor v {$ra_name} vrací 404", $ra_code, 404);
}
// Chart series keep their old answer for a missing monitor (200, empty points,
// an error text); an unassigned monitor must get exactly that answer.
[, , $ra_missing_series] = api_get_auth($base, 'action=metric_series&monitor_id=999999&metric=response_time&period=24h', $jar3);
[, , $ra_foreign_series] = api_get_auth($base, 'action=metric_series&monitor_id=1&metric=response_time&period=24h', $jar3);
check('graf nepřiřazeného monitoru vypadá jako neexistující monitor', $ra_foreign_series, $ra_missing_series);

// --- Monitors belong to users ------------------------------------------------
// A user sees only the monitors assigned to them (several users may share one),
// an admin sees every monitor, and the public view is the same status for
// everyone with no host internals.
$ma_details_before = $pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn();
$pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode([
    'version' => '1.2.3',
    'top_cpu_processes' => [['name' => 'tajny-proces', 'cpu' => 91.0, 'ram_mb' => 12]],
    'wan_l3_device' => 'pppoe-wan',
])]);
[$ma_save_code] = api_post($base, 'action=save_user', ['id' => $su_new_id, 'username' => 'audit_tester', 'email' => 'audit2@example.com', 'role' => 'user', 'monitorIds' => [1, 999999]], $cookie_jar);
check('admin přiřadí uživateli monitor', $ma_save_code, 200);
$ma_users_of = function () use ($base, $cookie_jar, $su_new_id): ?array {
    [, $list] = api_get_auth($base, 'action=users', $cookie_jar);
    foreach ($list['users'] ?? [] as $u) {
        if ((int)$u['id'] === $su_new_id) {
            return $u['monitorIds'] ?? null;
        }
    }
    return null;
};
check('uloží se jen existující monitor', $ma_users_of(), [1]);

[, $ma_admin] = api_get_auth($base, 'action=monitors', $cookie_jar);
$ma_all_ids = array_map(fn($m) => (int)$m['id'], $ma_admin['monitors'] ?? []);
check_true('admin vidí víc monitorů než jeden', count($ma_all_ids) > 1);
[$ma_user_code, $ma_user] = api_get_auth($base, 'action=monitors', $jar3);
check('uživatel dostane seznam', $ma_user_code, 200);
check('uživatel vidí jen přiřazený monitor', array_map(fn($m) => (int)$m['id'], $ma_user['monitors'] ?? []), [1]);

// The public view is the public set (W1-G3). The router is put on it for the
// checks below, so the public projection is still verified on a router's
// details: an owner may publish one, and it must not leak then either.
$pdo->exec("UPDATE monitors SET is_public = 1 WHERE id = 2");
[, $ma_admin_pub] = api_get_auth($base, 'action=monitors', $cookie_jar);
$ma_public_ids = array_map(fn($m) => (int)$m['id'], array_values(array_filter($ma_admin_pub['monitors'] ?? [], fn($m) => ($m['isPublic'] ?? null) === true)));
check_true('veřejná sada má web i zveřejněný router', in_array(1, $ma_public_ids, true) && in_array(2, $ma_public_ids, true));
[, $ma_user_public] = api_get_auth($base, 'action=monitors&scope=public', $jar3);
check('veřejný pohled ukáže uživateli celou veřejnou sadu', count($ma_user_public['monitors'] ?? []), count($ma_public_ids));
[, $ma_anon, $ma_anon_raw] = api_get($base, 'action=monitors');
check('anonym dostane veřejný pohled na veřejnou sadu', count($ma_anon['monitors'] ?? []), count($ma_public_ids));
check_false('veřejný pohled neukáže názvy procesů', str_contains($ma_anon_raw, 'tajny-proces'));
check_false('veřejný pohled neukáže názvy rozhraní', str_contains($ma_anon_raw, 'pppoe-wan'));
$ma_anon_router = null;
foreach ($ma_anon['monitors'] ?? [] as $m) {
    if ((int)$m['id'] === 2) { $ma_anon_router = $m; }
}
check('veřejný pohled nechá verzi, kterou karta ukazuje', $ma_anon_router['details']['version'] ?? null, '1.2.3');
check_true('veřejný pohled neukáže cíle monitorů', array_key_exists('target', $ma_anon_router ?? []) && $ma_anon_router['target'] === null);
[, , $ma_admin_raw] = api_get_auth($base, 'action=monitors', $cookie_jar);
check_true('admin procesy v aplikaci vidí', str_contains($ma_admin_raw, 'tajny-proces'));

[, $ma_own] = api_get_auth($base, 'action=metric_series&monitor_id=1&metric=response_time&period=24h', $jar3);
check_false('přiřazený monitor se uživateli otevře', isset($ma_own['error']));
[, , $ma_other_raw] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=24h', $jar3);
[, , $ma_missing_raw] = api_get_auth($base, 'action=metric_series&monitor_id=999999&metric=cpu&period=24h', $jar3);
check('cizí monitor zůstane zavřený i po přiřazení jiného', $ma_other_raw, $ma_missing_raw);
[$ma_other_pt] = api_get_auth($base, 'action=process_top&monitor_id=2', $jar3);
check('procesy cizího monitoru uživatel nedostane', $ma_other_pt, 404);
[$ma_anon_series] = api_get($base, 'action=metric_series&monitor_id=1&metric=response_time&period=24h');
check('anonym grafy monitoru nedostane', $ma_anon_series, 401);
[, $ma_user_du] = api_get_auth($base, 'action=daily_uptime&days=7', $jar3);
check_true('denní dostupnost uživatele nese jeho monitor', isset($ma_user_du['series']['1']) || isset($ma_user_du['series'][1]));
check_false('denní dostupnost uživatele nenese cizí monitor', isset($ma_user_du['series']['2']) || isset($ma_user_du['series'][2]));
// Both sides are checked, so an empty list or a renamed key cannot pass as private.
[, $ma_admin_ev] = api_get_auth($base, 'action=events&monitor_id=1&limit=50', $cookie_jar);
check_true(
    'admin u událostí cíl kontroly vidí',
    array_filter($ma_admin_ev['events'] ?? [], fn($e) => ($e['target'] ?? null) === 'https://example.com') !== []
);
[$ma_anon_ev_code, $ma_anon_ev] = api_get($base, 'action=events&monitor_id=1&limit=50');
check('anonym dostane veřejné události', $ma_anon_ev_code, 200);
check_true('veřejné události nejsou prázdné', count($ma_anon_ev['events'] ?? []) > 0);
check_true(
    'veřejné události nenesou cíl kontroly',
    array_filter($ma_anon_ev['events'] ?? [], fn($e) => !array_key_exists('target', $e) || $e['target'] !== null) === []
);
[$ma_anon_dl] = api_get($base, 'action=dashboard_layout');
check('anonym rozložení dashboardu nedostane', $ma_anon_dl, 401);

// --- What leaked around the lists: pages, summaries, texts -----------------
$page_get = function (string $path, ?string $jar = null) use ($base): array {
    $ch = curl_init($base . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($jar !== null) {
        $opts[CURLOPT_COOKIEFILE] = $jar;
        $opts[CURLOPT_COOKIEJAR] = $jar;
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
};

// The legacy status page printed every target, port and resolved address.
[$ix_code, $ix_anon] = $page_get('/index.php');
check('stará status stránka se anonymovi načte', $ix_code, 200);
check_false('a nespadne ani nehlásí nedefinovanou proměnnou', str_contains($ix_anon, 'Fatal error') || str_contains($ix_anon, 'Undefined variable'));
check_false('anonym na staré stránce nevidí cíl webu', str_contains($ix_anon, 'example.com'));
check_false('anonym na staré stránce nevidí adresu routeru', str_contains($ix_anon, '10.0.0.1'));
check_false('anonym na staré stránce nevidí procesy', str_contains($ix_anon, 'tajny-proces'));
[, $ix_admin] = $page_get('/index.php', $cookie_jar);
check_true('admin cíl webu na staré stránce vidí', str_contains($ix_admin, 'example.com'));

// Enrichment borrowed processes from another monitor on the same machine.
$ma_before = $pdo->query("SELECT id, asset_id, agent_key FROM monitors WHERE id IN (1, 2)")->fetchAll();
$pdo->exec("INSERT INTO assets (name) VALUES ('Sdílený stroj')");
$ma_asset = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE monitors SET asset_id = ? WHERE id IN (1, 2)")->execute([$ma_asset]);
$pdo->exec("UPDATE monitors SET agent_key = 'test-sibling-key' WHERE id = 2");
[$mp_user_code, $mp_user] = $page_get('/monitor.php?id=1', $jar3);
check('přiřazený monitor se uživateli otevře i na staré stránce', $mp_user_code, 200);
check_false('detail nepřevezme procesy z cizího monitoru na stejném stroji', str_contains($mp_user, 'tajny-proces'));
[, $mp_admin] = $page_get('/monitor.php?id=1', $cookie_jar);
check_true('admin procesy agenta na stejném stroji v detailu vidí', str_contains($mp_admin, 'tajny-proces'));
$ma_restore = $pdo->prepare("UPDATE monitors SET asset_id = ?, agent_key = ? WHERE id = ?");
foreach ($ma_before as $row) {
    $ma_restore->execute([$row['asset_id'], $row['agent_key'], (int)$row['id']]);
}
$pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$ma_asset]);

// Incident updates carried the check reason and the operator's name.
$pdo->exec("INSERT INTO incidents (title, impact, status, monitor_id) VALUES ('Výpadek: Router bez metrik', 'major', 'investigating', 2)");
$pi_id = (int)$pdo->lastInsertId();
$pi_upd = $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, 'investigating', ?)");
$pi_upd->execute([$pi_id, 'Automaticky detekován výpadek. Důvod: Chybí běžící proces: tajny-proces']);
$pi_upd->execute([$pi_id, 'Incident převzal: operator-jmeno']);
$pi_upd->execute([$pi_id, '[operator-jmeno] Měníme zdroj']);
[, , $pi_anon_raw] = api_get($base, 'action=incidents&scope=public');
check_true('veřejná aktualizace nese text poznámky', str_contains($pi_anon_raw, 'Měníme zdroj'));
check_false('veřejná aktualizace nenese důvod kontroly', str_contains($pi_anon_raw, 'tajny-proces'));
check_false('veřejná aktualizace nejmenuje operátora', str_contains($pi_anon_raw, 'operator-jmeno'));
[, , $pi_admin_raw] = api_get_auth($base, 'action=incidents', $cookie_jar);
check_true('admin vidí, kdo incident převzal', str_contains($pi_admin_raw, 'operator-jmeno'));
$pdo->prepare("DELETE FROM incident_updates WHERE incident_id = ?")->execute([$pi_id]);
$pdo->prepare("DELETE FROM incidents WHERE id = ?")->execute([$pi_id]);

// An outage whose incident gets closed while the monitor is still down. Closing
// the record used to leave the outage on the page with no incident behind it:
// no notes, no acknowledge, nothing for the escalation to find - and its
// duration restarted with every check, because cron writes a 'down' log each
// cycle and the card read the newest one.
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, last_status_change)
            VALUES (96, 'Router s trvajícím výpadkem', 'openwrt', '10.0.0.96', 'down', 'Síť', DATE_SUB(NOW(), INTERVAL 3 HOUR))");
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, error_message, checked_at) VALUES (96, 'down', 'Agent routeru neodpovídá', DATE_SUB(NOW(), INTERVAL 3 HOUR))");
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, error_message, checked_at) VALUES (96, 'down', 'Agent routeru neodpovídá', NOW())");
$pdo->exec("INSERT INTO incidents (title, impact, status, monitor_id) VALUES ('Výpadek: Router s trvajícím výpadkem', 'major', 'investigating', 96)");
$oi_id = (int)$pdo->lastInsertId();

$oi_find = function (array $payload): ?array {
    foreach ($payload['incidents'] ?? [] as $row) {
        if ((int)($row['monitor_id'] ?? 0) === 96) {
            return $row;
        }
    }
    return null;
};

[, $oi_list] = api_get_auth($base, 'action=incidents', $cookie_jar);
$oi_row = $oi_find($oi_list);
check_true('trvající výpadek je v seznamu', $oi_row !== null);
$oi_start = $oi_row !== null ? DateTime::createFromFormat('d.m.Y H:i:s', (string)$oi_row['started_at']) : false;
check_true(
    'začátek výpadku je pád monitoru, ne poslední kontrola',
    $oi_start !== false && (time() - $oi_start->getTimestamp()) > 7000
);

[$oi_res_code, $oi_res] = api_post($base, 'action=incident_action', ['id' => $oi_id, 'op' => 'resolve'], $cookie_jar);
check('incident trvajícího výpadku jde uzavřít', $oi_res_code, 200);
check_true('uzavření řekne, že monitor je pořád nedostupný', !empty($oi_res['monitorStillDown']));

[, $oi_list2] = api_get_auth($base, 'action=incidents', $cookie_jar);
$oi_row2 = $oi_find($oi_list2);
check_true('výpadek zůstane v seznamu i po uzavření incidentu', $oi_row2 !== null);
check_true('a zůstane bez otevřeného incidentu', $oi_row2 !== null && $oi_row2['incidentId'] === null);

[$oi_new_code, $oi_new] = api_post(
    $base,
    'action=create_incident',
    ['title' => 'Výpadek: Router s trvajícím výpadkem', 'impact' => 'major', 'monitorId' => 96],
    $cookie_jar
);
check('k trvajícímu výpadku jde incident otevřít znovu', $oi_new_code, 200);
[, $oi_list3] = api_get_auth($base, 'action=incidents', $cookie_jar);
$oi_row3 = $oi_find($oi_list3);
check_true(
    'a karta výpadku na něj zase odkazuje',
    $oi_row3 !== null && (int)$oi_row3['incidentId'] === (int)($oi_new['id'] ?? 0)
);

[$oi_dup_code] = api_post(
    $base,
    'action=create_incident',
    ['title' => 'Druhý incident', 'monitorId' => 96],
    $cookie_jar
);
check('druhý otevřený incident k témuž monitoru neprojde', $oi_dup_code, 409);
[$oi_missing_code] = api_post(
    $base,
    'action=create_incident',
    ['title' => 'Incident bez monitoru', 'monitorId' => 999999],
    $cookie_jar
);
check('incident k neexistujícímu monitoru neprojde', $oi_missing_code, 404);

$pdo->exec("DELETE FROM incident_updates WHERE incident_id IN (SELECT id FROM incidents WHERE monitor_id = 96)");
$pdo->exec("DELETE FROM incidents WHERE monitor_id = 96");
$pdo->exec("DELETE FROM monitor_logs WHERE monitor_id = 96");
$pdo->exec("DELETE FROM monitors WHERE id = 96");
// The explicit id moved the counter to 97, so the next monitor created without
// an id took 97 and the archive fixture below collided with it. InnoDB lowers a
// counter set below the highest id to that id + 1 - back to where it was.
$pdo->exec("ALTER TABLE monitors AUTO_INCREMENT = 1");

// A failure text named the host it could not resolve.
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_at) VALUES (1, 'down', NULL, 'cURL chyba: Could not resolve host: tajny-host.internal', DATE_SUB(NOW(), INTERVAL 5 SECOND))");
$pr_log = (int)$pdo->lastInsertId();
[, , $pr_anon_raw] = api_get($base, 'action=events&monitor_id=1&limit=50');
check_false('veřejná událost nejmenuje hostitele z chyby', str_contains($pr_anon_raw, 'tajny-host'));
check_true('ale řekne, že selhal překlad adresy', str_contains($pr_anon_raw, 'Adresu se nepodařilo přeložit'));
[, , $pr_admin_raw] = api_get_auth($base, 'action=events&monitor_id=1&limit=50', $cookie_jar);
check_true('admin celou chybu vidí', str_contains($pr_admin_raw, 'tajny-host'));
$pdo->prepare("DELETE FROM monitor_logs WHERE id = ?")->execute([$pr_log]);

// The dashboard summary counted the whole fleet for a user.
[, $ps_user] = api_get_auth($base, 'action=public_status', $jar3);
check('souhrn v aplikaci počítá jen přiřazené monitory', $ps_user['totalMonitors'] ?? null, 1);
check_false('uzly v aplikaci uživatele nenesou cizí monitor', in_array('Router bez metrik', array_column($ps_user['nodes'] ?? [], 'name'), true));
[, $ps_user_pub] = api_get_auth($base, 'action=public_status&scope=public', $jar3);
check('veřejný souhrn počítá veřejnou sadu', $ps_user_pub['totalMonitors'] ?? null, count($ma_public_ids));
[, $ps_anon] = api_get($base, 'action=public_status');
check('anonym dostane souhrn veřejné sady', $ps_anon['totalMonitors'] ?? null, count($ma_public_ids));
check_true('a v uzlech i zveřejněný router', in_array('Router bez metrik', array_column($ps_anon['nodes'] ?? [], 'name'), true));
// Back to the type's default: the router is off the public page again.
$pdo->exec("UPDATE monitors SET is_public = NULL WHERE id = 2");

// Admin-only diagnostics checked only for a login.
[$hl_user_code] = $page_get('/health.php?format=json', $jar3);
check('diagnostika schématu běžnému uživateli zůstane zavřená', $hl_user_code, 403);

// The role lived in the session: a demoted or deleted admin kept every right.
[$da_code] = api_post($base, 'action=save_user', ['username' => 'druhy_admin', 'email' => 'druhy@example.com', 'role' => 'admin', 'password' => 'DruhyAdmin123!'], $cookie_jar);
check('admin založí druhého admina', $da_code, 200);
$da_id = (int)$pdo->query("SELECT id FROM users WHERE username = 'druhy_admin'")->fetchColumn();
$jar4 = tempnam(sys_get_temp_dir(), 'bk_test_c4');
[$da_login] = api_post($base, 'action=login', ['username' => 'druhy_admin', 'password' => 'DruhyAdmin123!'], $jar4, '');
check('druhý admin se přihlásí', $da_login, 200);
[$da_users_code] = api_get_auth($base, 'action=users', $jar4);
check('jako admin seznam účtů dostane', $da_users_code, 200);
api_post($base, 'action=save_user', ['id' => $da_id, 'username' => 'druhy_admin', 'email' => 'druhy@example.com', 'role' => 'user', 'monitorIds' => []], $cookie_jar);
[$da_users_after] = api_get_auth($base, 'action=users', $jar4);
check('sesazený admin ve staré relaci práva ztratí', $da_users_after, 403);
[, $da_mon] = api_get_auth($base, 'action=monitors', $jar4);
check('a z monitorů v aplikaci nevidí nic', $da_mon['monitors'] ?? null, []);
api_post($base, 'action=delete_user', ['id' => $da_id], $cookie_jar);
[, $da_session] = api_get_auth($base, 'action=session', $jar4);
check_false('smazaný účet je ve staré relaci odhlášený', !empty($da_session['authenticated']));
@unlink($jar4);

api_post($base, 'action=save_user', ['id' => $su_new_id, 'username' => 'audit_tester', 'email' => 'audit2@example.com', 'role' => 'user'], $cookie_jar);
check('uložení bez seznamu monitorů přiřazení nesmaže', $ma_users_of(), [1]);
api_post($base, 'action=save_user', ['id' => $su_new_id, 'username' => 'audit_tester', 'email' => 'audit2@example.com', 'role' => 'user', 'monitorIds' => []], $cookie_jar);
check('prázdný seznam přiřazení zruší', $ma_users_of(), []);
[, $ma_user_none] = api_get_auth($base, 'action=monitors', $jar3);
check('uživatel bez přiřazení nevidí v aplikaci nic', $ma_user_none['monitors'] ?? null, []);
$pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([$ma_details_before === false ? null : $ma_details_before]);
@unlink($jar3);

[$du_code] = api_post($base, 'action=delete_user', ['id' => $su_new_id], $cookie_jar);
check('delete_user účet skutečně smaže', $du_code, 200);
$su_gone_stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
$su_gone_stmt->execute([$su_new_id]);
check('řádek v DB zmizel', (int)$su_gone_stmt->fetchColumn(), 0);

if ($logged_in) {
    // --- Preset ---------------------------------------------------------
    [$code, $res] = api_post($base, 'action=save_preset', [
        'name' => 'Testovací preset',
        'serviceType' => 'web',
        'metrics' => ['ssl_card', 'headers'],
        'cpuThreshold' => 70,
        'ramThreshold' => '',
        'hddThreshold' => 0,
    ], $cookie_jar);
    check('save_preset vrací 200', $code, 200);
    $preset_id = (int)($res['id'] ?? 0);
    check_true('preset dostal id', $preset_id > 0);

    [$code, $plist] = api_get_auth($base, 'action=presets', $cookie_jar);
    $saved_preset = null;
    foreach (($plist['presets'] ?? []) as $p) {
        if ((int)$p['id'] === $preset_id) {
            $saved_preset = $p;
        }
    }
    check_true('preset je vidět v seznamu', $saved_preset !== null);
    check('preset si drží vybrané metriky', $saved_preset['metrics'] ?? null, ['ssl_card', 'headers']);
    check('vyplněný práh se uloží', $saved_preset['cpuThreshold'] ?? 'chybí', 70);
    // An empty field means "the preset does not govern this threshold" - it is not a zero.
    check_true('prázdný práh zůstává null', array_key_exists('ramThreshold', $saved_preset) && $saved_preset['ramThreshold'] === null);
    check('nulový práh se uloží jako nula', $saved_preset['hddThreshold'] ?? 'chybí', 0);

    // --- Monitor: the preset and slowdown thresholds must survive a save ----
    [$code, $res] = api_post($base, 'action=save_monitor', [
        'name' => 'Zápisový test',
        'type' => 'web',
        'target' => 'https://example.com',
        'category' => 'Testy',
        'preset_id' => $preset_id,
        'latency_threshold_ms' => 750,
        'latency_threshold_mins' => 3,
    ], $cookie_jar);
    check('save_monitor vrací 200', $code, 200);
    $new_monitor_id = (int)($res['id'] ?? 0);
    check_true('monitor dostal id', $new_monitor_id > 0);

    [$code, $mlist] = api_get_auth($base, 'action=monitors', $cookie_jar);
    $saved_monitor = null;
    foreach (($mlist['monitors'] ?? []) as $m) {
        if ((int)$m['id'] === $new_monitor_id) {
            $saved_monitor = $m;
        }
    }
    check_true('nový monitor je v seznamu', $saved_monitor !== null);
    // Exactly this was the bug: the preset was lost on every save.
    check('preset zůstane přiřazený', $saved_monitor['presetId'] ?? 'chybí', $preset_id);
    check('práh zpomalení se uloží', $saved_monitor['latencyThresholdMs'] ?? 'chybí', 750);
    check('okno zpomalení se uloží', $saved_monitor['latencyThresholdMins'] ?? 'chybí', 3);

    // An edit must not knock out the other settings.
    [$code] = api_post($base, 'action=save_monitor', [
        'id' => $new_monitor_id,
        'name' => 'Zápisový test (upraveno)',
        'type' => 'web',
        'target' => 'https://example.com',
        'category' => 'Testy',
        'preset_id' => $preset_id,
        'latency_threshold_ms' => 750,
        'latency_threshold_mins' => 3,
    ], $cookie_jar);
    check('úprava monitoru vrací 200', $code, 200);

    [, $mlist2] = api_get_auth($base, 'action=monitors', $cookie_jar);
    $edited = null;
    foreach (($mlist2['monitors'] ?? []) as $m) {
        if ((int)$m['id'] === $new_monitor_id) {
            $edited = $m;
        }
    }
    check('přejmenování se projeví', $edited['name'] ?? 'chybí', 'Zápisový test (upraveno)');
    check('preset přežil i úpravu', $edited['presetId'] ?? 'chybí', $preset_id);

    // Disabling the alert: an empty value = null, not zero.
    api_post($base, 'action=save_monitor', [
        'id' => $new_monitor_id,
        'name' => 'Zápisový test (upraveno)',
        'type' => 'web',
        'target' => 'https://example.com',
        'category' => 'Testy',
        'latency_threshold_ms' => '',
    ], $cookie_jar);
    [, $mlist3] = api_get_auth($base, 'action=monitors', $cookie_jar);
    foreach (($mlist3['monitors'] ?? []) as $m) {
        if ((int)$m['id'] === $new_monitor_id) {
            check_true('vypnuté upozornění je null, ne 0', array_key_exists('latencyThresholdMs', $m) && $m['latencyThresholdMs'] === null);
        }
    }

    // --- Status pages ----------------------------------------------------
    [$code, $sp] = api_post($base, 'action=save_status_page', [
        'title' => 'Veřejný přehled',
        'isPublic' => true,
        'monitorIds' => [$new_monitor_id],
    ], $cookie_jar);
    check('save_status_page vrací 200', $code, 200);
    check('slug se odvodí bez diakritiky', $sp['slug'] ?? 'chybí', 'verejny-prehled');

    // A second page with the same slug must end in an intelligible error,
    // not a crash on the database index.
    [$code, $dup] = api_post($base, 'action=save_status_page', [
        'title' => 'Jiný název',
        'slug' => 'verejny-prehled',
    ], $cookie_jar);
    check('duplicitní slug vrací 400', $code, 400);
    check_true('duplicita má srozumitelnou hlášku', !empty($dup['error']));

    // A hidden page must not be visible to the unauthenticated.
    api_post($base, 'action=save_status_page', [
        'title' => 'Interní',
        'slug' => 'interni',
        'isPublic' => false,
    ], $cookie_jar);
    [, $anon_pages] = api_get($base, 'action=status_pages');
    $anon_slugs = array_column($anon_pages['pages'] ?? [], 'slug');
    check_false('skrytá stránka není vidět anonymně', in_array('interni', $anon_slugs, true));
    check_true('veřejná stránka vidět je', in_array('verejny-prehled', $anon_slugs, true));

    // --- One page by slug (the public page in React) --------------------
    //
    // Skryta stranka musi byt pro anonyma K NEROZEZNANI od neexistujici:
    // stejny kod, stejne telo. Kdyby se lisily, existence skrytych stranek
    // by sla zjistit zkousenim adres.
    [$code, $sp_pub] = api_get($base, 'action=status_page&slug=verejny-prehled');
    check('veřejná stránka podle slugu vrací 200', $code, 200);
    check('a nese titulek', $sp_pub['title'] ?? null, 'Veřejný přehled');

    [$code_hidden, , $raw_hidden] = api_get($base, 'action=status_page&slug=interni');
    [$code_missing, , $raw_missing] = api_get($base, 'action=status_page&slug=neexistuje');
    check('skrytá stránka vrací anonymovi 404', $code_hidden, 404);
    check('neexistující slug vrací 404', $code_missing, 404);
    check('a obě odpovědi jsou k nerozeznání', $raw_hidden, $raw_missing);

    // Prihlaseny admin skrytou stranku vidi.
    [$code, $sp_admin] = api_get_auth($base, 'action=status_page&slug=interni', $cookie_jar);
    check('admin skrytou stránku vidí', $code, 200);
    check('včetně titulku', $sp_admin['title'] ?? null, 'Interní');

    [$code] = api_get($base, 'action=status_page');
    check('chybějící slug vrací 400', $code, 400);

    // --- Status page display options ------------------------------------
    //
    // NULL in the database = "show everything". A page created before this
    // option must not change, so defaults are filled at read time and tested
    // before anything else.
    [, $sp_default] = api_get($base, 'action=status_page&slug=verejny-prehled');
    check('stránka bez voleb dostane výchozí showRegions', $sp_default['displayOptions']['showRegions'] ?? null, true);
    check('a detailLevel full', $sp_default['displayOptions']['detailLevel'] ?? null, 'full');

    [$code] = api_post($base, 'action=save_status_page', [
        'id' => 0,
        'title' => 'Jen stavy',
        'slug' => 'jen-stavy',
        'isPublic' => true,
        'displayOptions' => [
            'showRegions' => false,
            'showEvents' => false,
            'showIncidents' => true,
            'showUptime' => true,
            'detailLevel' => 'status',
        ],
    ], $cookie_jar);
    check('stránka s volbami se uloží', $code, 200);

    [, $sp_opts] = api_get($base, 'action=status_page&slug=jen-stavy');
    check('vypnuté sekce se vrátí vypnuté', $sp_opts['displayOptions']['showRegions'] ?? null, false);
    check('showEvents taky', $sp_opts['displayOptions']['showEvents'] ?? null, false);
    check('zapnuté zůstávají zapnuté', $sp_opts['displayOptions']['showIncidents'] ?? null, true);
    check('detailLevel status se drží', $sp_opts['displayOptions']['detailLevel'] ?? null, 'status');

    // An unknown key must not be stored - only the whitelist reaches the database.
    api_post($base, 'action=save_status_page', [
        'id' => 0,
        'title' => 'Podvržená',
        'slug' => 'podvrzena',
        'isPublic' => true,
        'displayOptions' => ['showRegions' => false, 'evil' => '<script>'],
    ], $cookie_jar);
    [, $sp_evil, $raw_evil] = api_get($base, 'action=status_page&slug=podvrzena');
    check_false('neznámý klíč se nevrací', str_contains($raw_evil, 'evil'));
    check('známý klíč z téhož požadavku ano', $sp_evil['displayOptions']['showRegions'] ?? null, false);

    // --- Export konfigurace ---------------------------------------------
    // cpanel_stats.php authenticates by ?key= alone - the stored URL is a credential.
    $cp_url_before = $pdo->query("SELECT cpanel_stats_url FROM monitors WHERE id = 1")->fetchColumn();
    $pdo->exec("UPDATE monitors SET cpanel_stats_url = 'https://hosting.example/status/cpanel_stats.php?key=tajny-klic-123' WHERE id = 1");
    [$code, , $raw_export] = api_get_auth($base, 'action=export_config', $cookie_jar);
    $pdo->prepare("UPDATE monitors SET cpanel_stats_url = ? WHERE id = 1")->execute([$cp_url_before === false ? null : $cp_url_before]);
    check_false('export nevydá klíč z adresy cPanel statistik', str_contains($raw_export, 'tajny-klic-123'));
    check_true('adresa cPanel statistik v exportu zůstane', str_contains($raw_export, 'hosting.example'));
    check('export vrací 200', $code, 200);
    $export = json_decode($raw_export, true);
    check_true('export je platný JSON', is_array($export));
    check_true('export obsahuje monitory', !empty($export['monitors']));
    check_true('export obsahuje nastavení', isset($export['settings']));

    // A secret in a downloadable file is an instant leak - guarded because
    // adding a new key with a password is all it takes, and without a test
    // nobody would notice.
    $leaked = [];
    foreach (array_keys($export['settings'] ?? []) as $k) {
        if (preg_match('/(pass|secret|token|key|hash|webhook)/i', $k)) {
            $leaked[] = $k;
        }
    }
    check('export neobsahuje tajemství', $leaked, []);
    check_false('export neobsahuje klíče agentů', str_contains($raw_export, 'agent_key'));
    check_false('export neobsahuje hesla ServerQuery', str_contains($raw_export, 'sq_password'));

    // Without login the export must not pass at all.
    [$anon_code] = api_get($base, 'action=export_config');
    check_true('export bez přihlášení je odmítnut', in_array($anon_code, [401, 403], true));

    // --- Cleanup ---------------------------------------------------------
    [$code] = api_post($base, 'action=delete_preset', ['id' => $preset_id], $cookie_jar);
    check('smazání presetu vrací 200', $code, 200);

    [, $mlist4] = api_get_auth($base, 'action=monitors', $cookie_jar);
    foreach (($mlist4['monitors'] ?? []) as $m) {
        if ((int)$m['id'] === $new_monitor_id) {
            // Deleting a preset must not break the monitor - it just returns to its own.
            check_true('monitor po smazání presetu zůstává', array_key_exists('presetId', $m) && $m['presetId'] === null);
        }
    }
}

// =======================================================================
// 8. Heartbeat - the whole flow from creation through the signal to evaluation
// =======================================================================
//
// The evaluation itself has database-free tests (run_tests.php). Here the
// point is what can only be verified for real: that the token truly appears,
// that a signal can be sent to it over HTTP, that it gets written, and above
// all that it leaks to nobody unauthenticated.
if (!empty($cookie_jar)) {
    [$code, $hb_created] = api_post($base, 'action=save_monitor', [
        'id' => 0,
        'name' => 'Noční záloha (test)',
        'type' => 'heartbeat',
        // The target is deliberately not sent - a heartbeat has none.
        'heartbeat_interval' => 3600,
        'heartbeat_grace' => 300,
    ], $cookie_jar);
    check('heartbeat monitor jde založit bez cíle', $code, 200);
    $hb_id = (int)($hb_created['id'] ?? 0);
    check_true('heartbeat monitor dostal id', $hb_id > 0);

    // The interval is the one thing a heartbeat makes no sense without.
    [$code_bad] = api_post($base, 'action=save_monitor', [
        'id' => 0,
        'name' => 'Heartbeat bez intervalu',
        'type' => 'heartbeat',
    ], $cookie_jar);
    check('heartbeat bez intervalu je odmítnut', $code_bad, 400);

    if ($hb_id > 0) {
        [$code, $info] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id, $cookie_jar);
        check('heartbeat_info vrací 200', $code, 200);
        $hb_token = (string)($info['token'] ?? '');
        check_true('token má tvar 48 hex znaků', (bool)preg_match('/^[0-9a-f]{48}$/', $hb_token));
        check('nový heartbeat je unknown, ne down', $info['state'] ?? null, 'unknown');
        // array_key_exists, not ?? - the operator would declare NULL a missing key
        // and the test would pass even if the endpoint did not send the field at all.
        check_true('bez signálu je čas posledního signálu null', array_key_exists('lastSignalAt', $info) && $info['lastSignalAt'] === null);
        check('interval se uložil v sekundách', $info['intervalSecs'] ?? null, 3600);

        // The token must not leave without login - whoever has it can send signals
        // for us and the monitor glows green while the backup has long stopped.
        [$anon_code] = api_get($base, 'action=heartbeat_info&monitor_id=' . $hb_id);
        check_true('heartbeat_info bez přihlášení je odmítnut', in_array($anon_code, [401, 403], true));

        // The token must not appear in the regular monitor list either.
        [, , $mon_raw] = api_get_auth($base, 'action=monitors', $cookie_jar);
        check_true('token není v seznamu monitorů', $hb_token !== '' && !str_contains($mon_raw, $hb_token));

        // --- The signal intake itself -----------------------------------
        [$hb_code, $hb_res] = hb_ping($base, $hb_token);
        check('signál s platným tokenem vrací 200', $hb_code, 200);
        check_true('odpověď potvrzuje přijetí', ($hb_res['ok'] ?? false) === true);

        [$code, $info2] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id, $cookie_jar);
        check_true('po signálu je znám čas posledního signálu', !empty($info2['lastSignalAt']));
        check('po čerstvém signálu je stav up', $info2['state'] ?? null, 'up');
        check('výsledek je ok', $info2['lastResult'] ?? null, 'ok');

        // --- A reported failure -----------------------------------------
        [$hb_code] = hb_ping($base, $hb_token, 'status=fail&msg=' . rawurlencode('tar skončil kódem 2'));
        check('signál o selhání vrací 200', $hb_code, 200);

        [, $info3] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id, $cookie_jar);
        check('ohlášené selhání sráží stav na down', $info3['state'] ?? null, 'down');
        check('výsledek je fail', $info3['lastResult'] ?? null, 'fail');
        check_true('zpráva od úlohy se uložila', str_contains((string)($info3['lastMessage'] ?? ''), 'tar'));

        // --- An invalid token must reveal nothing -----------------------
        [$bad_code] = hb_ping($base, str_repeat('a', 48));
        check('neznámý token vrací 404', $bad_code, 404);
        [$bad_code2] = hb_ping($base, 'nesmysl');
        check('token špatného tvaru vrací taky 404', $bad_code2, 404);

        // --- Token rotation ---------------------------------------------
        [, $info4] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id . '&regenerate=1', $cookie_jar);
        check_true('regenerate vyrobí jiný token', ($info4['token'] ?? '') !== $hb_token);
        [$old_code] = hb_ping($base, $hb_token);
        check('starý token po výměně přestane platit', $old_code, 404);

        api_post($base, 'action=delete_monitor', ['id' => $hb_id], $cookie_jar);
    }
}

// =======================================================================
// 9. The RSS feed - a subscription instead of waiting for a visit
// =======================================================================
//
// The feed is public, so it is tested without login. The last check matters
// most: a hidden page must not hand out via RSS anything it will not show on
// the web - otherwise guessing a slug would bypass visibility.
// Without incidents the feed would be empty and the tests below would pass
// even if items were never generated. One closed and one ongoing.
$pdo->exec("INSERT INTO incidents (id, title, impact, status, monitor_id, created_at, resolved_at)
            VALUES (901, 'Výpadek: Testovací web', 'major', 'resolved', 1,
                    DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR))");
$pdo->exec("INSERT INTO incidents (id, title, impact, status, monitor_id, created_at)
            VALUES (902, 'Výpadek: Router bez metrik', 'critical', 'investigating', 2,
                    DATE_SUB(NOW(), INTERVAL 20 MINUTE))");

// The feed covers the public set (W1-G3); the router is put on it for the
// item checks below and taken off again for the one that says it drops out.
$pdo->exec("UPDATE monitors SET is_public = 1 WHERE id = 2");
$rss_ch = curl_init($base . '/rss.php');
curl_setopt_array($rss_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true]);
$rss_raw = (string)curl_exec($rss_ch);
$pdo->exec("UPDATE monitors SET is_public = NULL WHERE id = 2");
[, , $rss_hidden_body] = bk_raw_request($base . '/rss.php');
check_false('incident routeru mimo veřejnou stránku v kanálu není', str_contains($rss_hidden_body, 'incident-902-opened'));
check_true('incident webu v kanálu zůstává', str_contains($rss_hidden_body, 'incident-901-opened'));
$rss_code = (int)curl_getinfo($rss_ch, CURLINFO_RESPONSE_CODE);
$rss_ctype = (string)curl_getinfo($rss_ch, CURLINFO_CONTENT_TYPE);
$rss_body = substr($rss_raw, curl_getinfo($rss_ch, CURLINFO_HEADER_SIZE));

check('RSS kanál vrací 200', $rss_code, 200);
check_true('RSS má správný Content-Type', str_contains($rss_ctype, 'application/rss+xml'));

// XML validity is verified by a parser, not substring search: an unescaped
// character in a monitor name breaks the feed in a way `str_contains` misses.
$prev_errors = libxml_use_internal_errors(true);
$rss_xml = simplexml_load_string($rss_body);
libxml_use_internal_errors($prev_errors);
check_true('RSS je platné XML', $rss_xml !== false);
if ($rss_xml !== false) {
    check_true('kanál má titulek', isset($rss_xml->channel->title) && (string)$rss_xml->channel->title !== '');
    check_true('kanál má odkaz na sebe (atom:self)', str_contains($rss_body, 'rel="self"'));

    $guids = [];
    $titles = [];
    $pub_dates = [];
    foreach ($rss_xml->channel->item as $item) {
        $guids[] = (string)$item->guid;
        $titles[] = (string)$item->title;
        $pub_dates[] = (string)$item->pubDate;
    }

    // Opening and resolution are two items with different guids. If the resolution
    // were merely appended to the original item, the reader would never show it
    // to the subscriber - a guid displayed once is not printed again.
    check_true('uzavřený incident má položku o vzniku', in_array('incident-901-opened', $guids, true));
    check_true('uzavřený incident má položku o vyřešení', in_array('incident-901-resolved', $guids, true));

    // An ongoing incident has no resolution - deriving one from "now" would be
    // an invented value about something that did not happen.
    check_true('probíhající incident má položku o vzniku', in_array('incident-902-opened', $guids, true));
    check_false('probíhající incident nemá vyřešení', in_array('incident-902-resolved', $guids, true));

    check_true('název monitoru je v položce', str_contains($rss_body, 'Testovací web'));
    check_true('každá položka má pubDate', count($pub_dates) === count($guids) && !in_array('', $pub_dates, true));

    // Newest on top - readers take the order from the feed.
    $first_ts = !empty($pub_dates) ? strtotime($pub_dates[0]) : 0;
    $last_ts = !empty($pub_dates) ? strtotime(end($pub_dates)) : 0;
    check_true('položky jsou od nejnovější', $first_ts >= $last_ts);
    check_true('titulek rozlišuje výpadek a vyřešení', count(array_unique($titles)) === count($titles));
}

if (!empty($cookie_jar)) {
    // A hidden page - an anonym must get 404 exactly like a nonexistent slug.
    [$code] = api_post($base, 'action=save_status_page', [
        'id' => 0,
        'title' => 'Skrytá pro RSS',
        'slug' => 'skryta-rss',
        'isPublic' => false,
        'monitorIds' => [1],
    ], $cookie_jar);
    check('skrytou stránku jde založit', $code, 200);

    $hidden_ch = curl_init($base . '/rss.php?page=skryta-rss');
    curl_setopt_array($hidden_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $hidden_body = (string)curl_exec($hidden_ch);
    $hidden_code = (int)curl_getinfo($hidden_ch, CURLINFO_RESPONSE_CODE);
    check('RSS skryté stránky je pro anonyma 404', $hidden_code, 404);
    check_true('RSS skryté stránky nevydá žádný kanál', !str_contains($hidden_body, '<rss'));

    // And a public page must serve the feed, otherwise the test above would
    // pass even with every slugged feed broken.
    api_post($base, 'action=save_status_page', [
        'id' => 0,
        'title' => 'Veřejná pro RSS',
        'slug' => 'verejna-rss',
        'isPublic' => true,
        'monitorIds' => [1],
    ], $cookie_jar);

    $pub_ch = curl_init($base . '/rss.php?page=verejna-rss');
    curl_setopt_array($pub_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $pub_body = (string)curl_exec($pub_ch);
    $pub_code = (int)curl_getinfo($pub_ch, CURLINFO_RESPONSE_CODE);
    check('RSS veřejné stránky vrací 200', $pub_code, 200);
    check_true('RSS veřejné stránky nese název stránky', str_contains($pub_body, 'Veřejná pro RSS'));
}

// =======================================================================
// 10. Escalation - the safety net for an alert nobody saw
// =======================================================================
//
// The decision logic has database-free tests. This is about the full
// mechanism: what really gets written to the database and above all what
// does NOT when escalation has nowhere to go.
require_once __DIR__ . '/../functions.php';

// Preset thresholds actually apply. bk_effective_threshold had tests but
// NO production caller - the preset editor offered thresholds while the
// alerts/tips/bands read only the monitor's value.
$pdo->exec("INSERT INTO metric_presets (id, name, cpu_threshold) VALUES (77, 'Tvrdší CPU limit', 70)");
$pdo->exec("UPDATE monitors SET preset_id = 77, cpu_threshold = 90, ram_threshold = 95 WHERE id = 2");
$thr_row = $pdo->query("SELECT * FROM monitors WHERE id = 2")->fetch();
$thr_eff = bk_monitor_thresholds($pdo, $thr_row);
check('preset práh přebíjí hodnotu monitoru', $thr_eff['cpu'], 70);
check('preset bez RAM prahu nechá hodnotu monitoru', $thr_eff['ram'], 95);
$thr_eff_nopdo = bk_monitor_thresholds(null, $thr_row);
check('bez PDO zůstává hodnota monitoru (žádný pád)', $thr_eff_nopdo['cpu'], 90);

// And here end-to-end over HTTP: the chart band (metric_detail) must draw the
// preset's limit - the very one agent_api actually alerts at.
[, $thr_detail] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=cpu', $cookie_jar);
check('metric_detail kreslí pásmo podle presetu', $thr_detail['thresholds']['critical'] ?? null, 70);

// "6 ms proti čemu a na čem?" - detail metriky musí umět pojmenovat cíl měření
// i místo, odkud se měří. Neznámé zůstává null, nikdy vymyšlená lokalita.
check('metric_detail vrací cíl měření', $thr_detail['monitor']['target'] ?? null, '10.0.0.1');
check_true('práh warning je označený jako odvozený', ($thr_detail['thresholdsDerived']['warning'] ?? null) === true);

// Predpoved zaplneni: cislo, ktere doted mel jen text insightu, takze odznak
// "Plno za X dni" nemel co zobrazit. Chybejici predpoved = klic chybi, ne nula.
$pdo->exec("DELETE FROM metrics_daily WHERE monitor_id = 2 AND metric_key = 'hdd'");
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', time() - $i * 86400);
    $val = 60 + (13 - $i) * 2; // roste o dve procenta denne
    $pdo->exec("INSERT INTO metrics_daily (monitor_id, day, metric_key, min_val, avg_val, max_val, samples)
                VALUES (2, '{$day}', 'hdd', {$val}, {$val}, {$val}, 10)
                ON DUPLICATE KEY UPDATE avg_val = VALUES(avg_val), min_val = VALUES(min_val), max_val = VALUES(max_val)");
}
[, $batch] = api_get_auth($base, 'action=metric_series_batch&monitor_id=2&period=24h', $cookie_jar);
$hdd_series = $batch['series']['hdd'] ?? null;
check_true('rostoucí disk dostane predikci zaplnění', is_array($hdd_series) && isset($hdd_series['daysToFull']));
check_true('a je to kladný počet dní', ($hdd_series['daysToFull'] ?? 0) > 0 && ($hdd_series['daysToFull'] ?? 0) <= 90);
$pdo->exec("DELETE FROM metrics_daily WHERE monitor_id = 2 AND metric_key = 'hdd'");
[, $batch2] = api_get_auth($base, 'action=metric_series_batch&monitor_id=2&period=24h', $cookie_jar);
check_true(
    'bez růstu se predikce nevrací vůbec',
    !array_key_exists('daysToFull', $batch2['series']['hdd'] ?? [])
);
check_true('a critical jako nastavený', ($thr_detail['thresholdsDerived']['critical'] ?? null) === false);
check_true('klíč port je přítomen', array_key_exists('port', $thr_detail['monitor'] ?? []));
check_true('klíč checkedFrom je přítomen', array_key_exists('checkedFrom', $thr_detail['monitor'] ?? []));
// array_key_exists, ne ?? - ten by null pod testem zaměnil za fallback.
check_true(
    'bez zapsané lokality zůstává checkedFrom null',
    array_key_exists('checkedFrom', $thr_detail['monitor'] ?? []) && $thr_detail['monitor']['checkedFrom'] === null
);
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_from, checked_at)
            VALUES (2, 'up', 5, 'Praha, CZ', NOW())");
// Only the metrics the SERVER measures may claim a vantage point. CPU is
// measured by the agent about its own machine; handing back cron's own label
// would claim the router's processor was measured from the hosting.
[, $cf_cpu] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=cpu', $cookie_jar);
check_true(
    'u metriky od agenta zůstává checkedFrom null',
    array_key_exists('checkedFrom', $cf_cpu['monitor'] ?? []) && $cf_cpu['monitor']['checkedFrom'] === null
);
[, $cf_detail] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=response_time', $cookie_jar);
check('u odezvy se lokalita vrátí', $cf_detail['monitor']['checkedFrom'] ?? null, 'Praha, CZ');
$pdo->exec("DELETE FROM monitor_logs WHERE monitor_id = 2");
$pdo->exec("UPDATE monitors SET preset_id = NULL, cpu_threshold = 90, ram_threshold = 95 WHERE id = 2");
$pdo->exec("DELETE FROM metric_presets WHERE id = 77");


// Settings load once at startup into the `$system_settings` global (db.php)
// and get_setting() reads from there. A database write alone therefore changes
// nothing - fine in cron, which starts with fresh values, but the test changes
// settings at runtime and must refresh the global itself.
$set_setting = function (string $key, string $value) use ($pdo) {
    $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
    $stmt->execute([$key, $value]);
    $GLOBALS['system_settings'] = get_settings($pdo);
};

$pdo->exec("INSERT INTO incidents (id, title, impact, status, monitor_id, created_at)
            VALUES (910, 'Výpadek: nepřevzatý', 'major', 'investigating', 1, DATE_SUB(NOW(), INTERVAL 40 MINUTE))");
$pdo->exec("INSERT INTO incidents (id, title, impact, status, monitor_id, created_at, acknowledged_at, acknowledged_by)
            VALUES (911, 'Výpadek: převzatý', 'major', 'investigating', 1, DATE_SUB(NOW(), INTERVAL 40 MINUTE), DATE_SUB(NOW(), INTERVAL 35 MINUTE), 'admin')");

$esc_state = fn(int $id) => $pdo->query("SELECT escalated_at FROM incidents WHERE id = {$id}")->fetchColumn();

// --- Disabled escalation does nothing ---
$set_setting('escalation_enabled', '0');
$res = bk_process_escalations($pdo);
check('vypnutá eskalace nic nekontroluje', $res['checked'], 0);
check_true('a nic neorazítkuje', $esc_state(910) === null);

// --- Enabled, but without a channel ---
// The stamp must not be set: the incident would look escalated and never speak
// up once a channel was added. A silent failure exactly where the safety net
// fungovat.
$set_setting('escalation_enabled', '1');
$set_setting('escalation_after_mins', '15');
$set_setting('escalation_webhook_url', '');
$res = bk_process_escalations($pdo);
check_true('bez kanálu se incident započítá jako čekající', $res['skipped_no_channel'] >= 1);
check('bez kanálu se nic neodešle', $res['escalated'], 0);
check_true('bez kanálu incident zůstává neorazítkovaný', $esc_state(910) === null);

// --- With a channel ---
// The webhook targets our own test server: what is verified is the database
// write, not delivery to Discord.
$set_setting('escalation_webhook_url', $base . '/api.php?action=ui_config');
$res = bk_process_escalations($pdo);
// A specific incident is checked, not a sum: the database has more open
// incidents (the RSS seed) and a count-based test would crack on every
// test-data change.
check_true('nepřevzatý incident eskaluje', $res['escalated'] >= 1);
check_true('a dostane razítko', $esc_state(910) !== null);
check_true('převzatý incident razítko nedostane', $esc_state(911) === null);

// --- Repetition ---
$res = bk_process_escalations($pdo);
check('podruhé už se stejný incident neeskaluje', $res['escalated'], 0);

$set_setting('escalation_enabled', '0');

// =======================================================================
// 11. Endpoints that were missing while calls to them silently fell through
// =======================================================================
//
// api.php used to answer an unknown action with the default service overview
// and a 200, so calling a nonexistent endpoint looked like success. These tests
// guard both: that the guard rejects an unknown action and that the missing
// endpoints really exist and do something.
[$code, $unknown] = api_get($base, 'action=rozhodne_neexistujici_akce');
check('neznámá akce vrací 400, ne tiché 200', $code, 400);
check_true('a řekne, co je špatně', str_contains((string)($unknown['error'] ?? ''), 'Neznámá akce'));

// An empty action keeps the old behaviour (the default service overview).
[$code] = api_get($base, '');
check('prázdná akce dál vrací výchozí přehled', $code, 200);

// --- Export CSV ---------------------------------------------------------
[$csv_anon_code] = api_get($base, 'action=export_csv&monitor_id=1');
check('anonym export historie nedostane', $csv_anon_code, 401);
$csv_ch = curl_init($base . '/api.php?action=export_csv&monitor_id=1');
curl_setopt_array($csv_ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEFILE => $cookie_jar,
]);
$csv_raw = (string)curl_exec($csv_ch);
$csv_code = (int)curl_getinfo($csv_ch, CURLINFO_RESPONSE_CODE);
$csv_body = substr($csv_raw, curl_getinfo($csv_ch, CURLINFO_HEADER_SIZE));
check('export CSV vrací 200', $csv_code, 200);
check_true('CSV se posílá ke stažení', str_contains($csv_raw, 'text/csv') && str_contains($csv_raw, 'attachment'));
check_true('CSV má hlavičku sloupců', str_contains($csv_body, 'Stav') && str_contains($csv_body, 'Odezva'));
// The export is only for accounts that see the monitor, so the error texts go with it.
check_true('export nese sloupec s chybami', str_contains($csv_body, 'Chybová hláška'));

[$csv_missing_code] = api_get_auth($base, 'action=export_csv&monitor_id=999999', $cookie_jar);
check('export neexistujícího monitoru vrací 404', $csv_missing_code, 404);

// --- Chart annotations ---------------------------------------------------
if (!empty($cookie_jar)) {
    [$code, $ann] = api_post($base, 'action=save_annotation', [
        'monitor_id' => 1,
        'metric_key' => 'response_time',
        'timestamp' => date('Y-m-d H:i:s'),
        'note' => 'Nasazena nová verze',
    ], $cookie_jar);
    check('poznámka se uloží', $code, 200);
    check_true('a vrátí své id', (int)($ann['id'] ?? 0) > 0);

    // The metric_annotations table stayed empty for years precisely because the
    // endpoint was missing and the note was silently dropped.
    $ann_count = (int)$pdo->query("SELECT COUNT(*) FROM metric_annotations")->fetchColumn();
    check_true('poznámka je opravdu v databázi', $ann_count > 0);

    [$code] = api_post($base, 'action=save_annotation', [
        'monitor_id' => 1,
        'metric_key' => 'response_time',
        'note' => '',
    ], $cookie_jar);
    check('poznámka bez textu je odmítnuta', $code, 400);

    [$code, $ann_list] = api_get_auth($base, 'action=annotations&monitor_id=1&metric=response_time', $cookie_jar);
    check('načtení poznámek vrací 200', $code, 200);
    check_true('a obsahuje uloženou poznámku', str_contains(json_encode($ann_list, JSON_UNESCAPED_UNICODE), 'Nasazena nová verze'));

    // Operational notes are not for the public.
    [, $anon_ann] = api_get($base, 'action=annotations&monitor_id=1&metric=response_time');
    check('anonym poznámky nevidí', $anon_ann['annotations'] ?? null, []);

    [$anon_save_code] = api_post($base, 'action=save_annotation', [
        'monitor_id' => 1, 'metric_key' => 'cpu', 'timestamp' => date('Y-m-d H:i:s'), 'note' => 'pokus',
    ], tempnam(sys_get_temp_dir(), 'bk_anon'));
    check('anonym poznámku neuloží', $anon_save_code, 403);

    // Deleting a note. The whole point of the delete endpoint is that a wrong
    // note can be taken back - a note is a claim, and a false claim next to a
    // chart is worse than no note.
    //
    // The returned id has to be the note's own. It used to be read after
    // bk_audit_log(), so lastInsertId() reported the audit row instead - which
    // stayed invisible for as long as nobody used the id for anything.
    $ann_del_id = (int)($ann['id'] ?? 0);
    $ann_real_id = (int)$pdo->query("SELECT id FROM metric_annotations ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('uložení vrací id poznámky, ne cizího řádku', $ann_del_id, $ann_real_id);

    // Authorisation, not just the CSRF guard: a logged-in non-admin carries a
    // valid token, so this is the only probe that reaches the role check. An
    // anonymous attempt is stopped one layer earlier and proves nothing about
    // the role. The account is created here because the shared one from the
    // user-management block above has been deleted by its own test by now.
    $ann_pw_hash = password_hash('PoznamkyTest123!', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES ('ann_tester', 'ann@example.com', ?, 'user')")
        ->execute([$ann_pw_hash]);
    $ann_jar = tempnam(sys_get_temp_dir(), 'bk_test_ann');
    [, $ann_login] = api_post($base, 'action=login', ['username' => 'ann_tester', 'password' => 'PoznamkyTest123!'], $ann_jar, '');
    $ann_user_csrf = (string)($ann_login['csrfToken'] ?? '');
    check_true('běžný uživatel se přihlásí (jinak by test roli nikdy neprověřil)', $ann_user_csrf !== '');
    [$user_del_code] = api_post($base, 'action=delete_annotation', ['id' => $ann_del_id], $ann_jar, $ann_user_csrf);
    check('běžný uživatel poznámku nesmaže', $user_del_code, 403);
    [$user_save_code] = api_post($base, 'action=save_annotation', [
        'monitor_id' => 1, 'metric_key' => 'cpu', 'timestamp' => date('Y-m-d H:i:s'), 'note' => 'nesmí projít',
    ], $ann_jar, $ann_user_csrf);
    check('běžný uživatel poznámku ani nezaloží', $user_save_code, 403);
    @unlink($ann_jar);

    [$anon_del_code] = api_post($base, 'action=delete_annotation', ['id' => $ann_del_id], tempnam(sys_get_temp_dir(), 'bk_anon'));
    check('anonym poznámku nesmaže', $anon_del_code, 403);
    check_true(
        'a poznámka po všech pokusech pořád existuje',
        (int)$pdo->query("SELECT COUNT(*) FROM metric_annotations WHERE id = {$ann_del_id}")->fetchColumn() === 1
    );

    // The account leaves no trace behind - a later test asserts the exact
    // number of accounts, and a leftover here would fail it from a distance.
    $pdo->exec("DELETE FROM users WHERE username = 'ann_tester'");

    [$del_code] = api_post($base, 'action=delete_annotation', ['id' => $ann_del_id], $cookie_jar);
    check('admin poznámku smaže', $del_code, 200);
    check_true(
        'a v databázi po ní nic nezůstalo',
        (int)$pdo->query("SELECT COUNT(*) FROM metric_annotations WHERE id = {$ann_del_id}")->fetchColumn() === 0
    );

    [$del_missing_code] = api_post($base, 'action=delete_annotation', ['id' => 999999], $cookie_jar);
    check('smazání neexistující poznámky vrací 404', $del_missing_code, 404);
}

// --- Metric heatmap (hour x day) -----------------------------------------
//
// The grid must be dense and honest: every one of the requested days, all 24
// hours, and an hour with no sample stays null. A zero there would repaint a
// sleeping agent as an idle server.
[$hm_code, $hm] = api_get_auth($base, 'action=metric_heatmap&monitor_id=1&metric=response_time&days=3', $cookie_jar);
check('metric_heatmap vrací 200', $hm_code, 200);
check_true('vrací mřížku dní', is_array($hm['days'] ?? null));
check('a přesně tolik dní, kolik se žádalo', count($hm['days'] ?? []), 3);
if (!empty($hm['days'])) {
    $hm_first = $hm['days'][0];
    check('každý den má 24 hodin', count($hm_first['hours'] ?? []), 24);
    check('a stejný počet údajů o vzorcích', count($hm_first['samples'] ?? []), 24);
    check_true('den je datum ve tvaru YYYY-MM-DD', (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($hm_first['day'] ?? '')));

    // Honesty: an hour without measurements is null, never 0. Verified against
    // the sample count, which is the server's own record of what it measured.
    $hm_zero_faked = false;
    foreach ($hm['days'] as $hm_day) {
        foreach ($hm_day['hours'] as $hm_i => $hm_val) {
            if (($hm_day['samples'][$hm_i] ?? 0) === 0 && $hm_val !== null) {
                $hm_zero_faked = true;
            }
        }
    }
    check_false('hodina bez vzorků nemá vymyšlenou hodnotu', $hm_zero_faked);

    $hm_days_seen = array_column($hm['days'], 'day');
    check_true('dny jdou vzestupně a nic se neopakuje', $hm_days_seen === array_unique($hm_days_seen) && $hm_days_seen === (function ($d) { sort($d); return $d; })($hm_days_seen));
}

// The window is capped at the raw-sample retention - a longer request must not
// silently answer with a shorter window pretending to be the requested one.
[, $hm_cap] = api_get_auth($base, 'action=metric_heatmap&monitor_id=1&metric=response_time&days=365', $cookie_jar);
check('delší okno než retence se ořízne na 30 dní', count($hm_cap['days'] ?? []), 30);

[, $hm_unknown] = api_get_auth($base, 'action=metric_heatmap&monitor_id=1&metric=neexistujici_metrika', $cookie_jar);
check_true('neznámá metrika se přizná chybou', ($hm_unknown['error'] ?? '') !== '' && ($hm_unknown['days'] ?? null) === []);

// --- Correlations between metrics (metric_correlations) -------------------
//
// Its own monitor with a known shape: CPU rises, iowait rises with it, RAM
// falls against it, and swap never moves. Deliberately not monitor 1 or 2 -
// those carry fixtures other tests assert on, and adding rows there would
// break them from a distance.
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category)
            VALUES (90, 'Korelační fixture', 'vps', '10.0.0.90', 'up', 'Test')");
for ($i = 0; $i < 30; $i++) {
    $cpu = 10 + $i * 2;
    $iow = 5 + $i;            // rises with CPU - correlation near +1
    $ram = 90 - $i * 2;       // falls against it - correlation near -1
    // A weak positive one on purpose: with only |r| = 1 values in the fixture,
    // ordering by strength and ordering by signed value look identical, and a
    // test cannot tell a correct sort from a broken one.
    $temp = 40 + $i * 0.4 + ($i % 6) * 6;   // r about +0.48
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, iowait_pct, ram_usage, swap_usage, temperature_c, checked_at)
                VALUES (90, {$cpu}, {$iow}, {$ram}, 0, {$temp}, DATE_SUB(NOW(), INTERVAL " . (30 - $i) . " MINUTE))");
}

[$corr_code, $corr] = api_get_auth($base, 'action=metric_correlations&monitor_id=90&metric=cpu&period=24h', $cookie_jar);
check('metric_correlations vrací 200', $corr_code, 200);
check('a počítá ze všech vzorků', $corr['samples'] ?? 0, 30);

$by_key = [];
foreach ($corr['correlations'] ?? [] as $c) {
    $by_key[$c['key']] = $c;
}
check_true('metrika rostoucí s cílem má korelaci blízko +1', ($by_key['iowait']['r'] ?? 0) > 0.98);
check_true('metrika klesající proti cíli má korelaci blízko -1', ($by_key['ram']['r'] ?? 0) < -0.98);

// A metric below the top-N cut looked simply absent ("where is IPv4?"), so
// all=1 has to return every comparison there is.
[, $corr_all] = api_get_auth($base, 'action=metric_correlations&monitor_id=90&metric=cpu&period=24h&all=1', $cookie_jar);
check_true(
    'all=1 vrací víc než zkrácený seznam',
    count($corr_all['correlations'] ?? []) >= count($corr['correlations'] ?? [])
);
check_true(
    'a nezamlčí ani jednu porovnávanou metriku',
    count($corr_all['correlations'] ?? []) === (int)($corr_all['total'] ?? -1)
);

// The rule that matters most here: a metric that never moved has no
// correlation to report. A zero would claim the two are unrelated.
check_true('neměnná metrika nemá koeficient', array_key_exists('swap', $by_key) && $by_key['swap']['r'] === null);
check('a přizná, že je konstantní', $by_key['swap']['reason'] ?? '', 'constant');

// Ordering is by strength in either direction, so the strongest relationship
// is the first thing read - a strong negative one must not sink below a weak
// positive one.
$first = $corr['correlations'][0] ?? null;
check_true('nejsilnější vztah je první', $first !== null && abs($first['r'] ?? 0) > 0.98);
check_true('slabší kladná korelace se spočítá', ($by_key['temperature_c']['r'] ?? 0) > 0.3 && ($by_key['temperature_c']['r'] ?? 1) < 0.7);
// The point of ordering by |r|: a strong negative relationship must outrank a
// weak positive one, not sink below it.
$pos_ram = array_search('ram', array_column($corr['correlations'], 'key'), true);
$pos_temp = array_search('temperature_c', array_column($corr['correlations'], 'key'), true);
check_true('silná záporná korelace je před slabou kladnou', $pos_ram !== false && $pos_temp !== false && $pos_ram < $pos_temp);
$r_values = array_map(fn($c) => $c['r'] === null ? -1 : abs($c['r']), $corr['correlations'] ?? []);
$r_sorted = $r_values;
rsort($r_sorted);
check_true('a pořadí je podle síly bez ohledu na znaménko', $r_values === $r_sorted);

// The target must not be correlated with itself, nor with an alias reading
// the same column - a guaranteed 1.0 that says nothing.
check_false('cílová metrika není ve vlastním seznamu', array_key_exists('cpu', $by_key));

[, $corr_rt] = api_get_auth($base, 'action=metric_correlations&monitor_id=90&metric=response_time', $cookie_jar);
check_true(
    'metrika mimo vps_metrics se odmítne s vysvětlením',
    ($corr_rt['error'] ?? '') !== '' && ($corr_rt['correlations'] ?? null) === []
);

// A monitor whose agent never reported anything has no rows to correlate -
// and must not invent a list of zeroes.
[, $corr_empty] = api_get_auth($base, 'action=metric_correlations&monitor_id=1&metric=cpu', $cookie_jar);
check('bez naměřených dat je seznam prázdný', $corr_empty['correlations'] ?? null, []);
check('a přiznaný počet vzorků je nula', $corr_empty['samples'] ?? -1, 0);

// The fixture leaves no trace - later tests count monitors and would fail
// from a distance over a leftover row.
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 90");
$pdo->exec("DELETE FROM monitors WHERE id = 90");

// --- Public e-mail subscriptions -----------------------------------------
//
// Double opt-in end-to-end: sign-up stores a row (nothing confirmed), the
// confirmation token flips it, the unsubscribe token deletes it. Tokens are
// injected via SQL - the raw ones live only in e-mails the test cannot read.
[$pss_code, $pss] = api_post($base, 'action=public_subscribe', ['email' => 'navstevnik@example.com', 'lang' => 'cs'], $cookie_jar, '');
check('public_subscribe vrací 200', $pss_code, 200);
// The response carries nothing beyond success. It used to report an
// `emailSent` flag for honesty about delivery, but only a not-yet-subscribed
// address triggers a send, so that flag identified subscribers whenever
// sending failed (2026-08-23 audit). Delivery problems now go to the server
// log, where they belong.
check('odpověď je konstantní a nic neprozrazuje', $pss, ['success' => true]);
$pss_row = $pdo->query("SELECT confirmed_at, confirm_token_hash, unsubscribe_token FROM public_subscribers WHERE email = 'navstevnik@example.com'")->fetch();
check_true('řádek vznikl a čeká na potvrzení', $pss_row !== false && $pss_row['confirmed_at'] === null);
check_true('potvrzovací token je hash, odhlašovací raw', strlen((string)$pss_row['confirm_token_hash']) === 64 && strlen((string)$pss_row['unsubscribe_token']) === 48);

[$pse_code] = api_post($base, 'action=public_subscribe', ['email' => 'neni-email'], $cookie_jar, '');
check('nevalidní adresa je 400', $pse_code, 400);

// Potvrzení: vstříknutý známý token.
$pdo->exec("UPDATE public_subscribers SET confirm_token_hash = '" . hash('sha256', 'test-confirm-token') . "' WHERE email = 'navstevnik@example.com'");
[$psc_code] = api_post($base, 'action=public_subscribe_confirm', ['token' => 'test-confirm-token'], $cookie_jar, '');
check('potvrzení projde', $psc_code, 200);
check_true('a v DB je potvrzeno', $pdo->query("SELECT confirmed_at FROM public_subscribers WHERE email = 'navstevnik@example.com'")->fetchColumn() !== null);
[$psc2_code] = api_post($base, 'action=public_subscribe_confirm', ['token' => 'test-confirm-token'], $cookie_jar, '');
check('použitý potvrzovací token podruhé neprojde', $psc2_code, 400);

// Already-confirmed address: the response must be INDISTINGUISHABLE from a
// fresh signup, or it becomes a one-request membership oracle (2026-08-23
// audit). The comparison is on the WHOLE body on purpose - an earlier fix
// reported a fixed `emailSent:true` for the confirmed case, which merely
// inverted the leak wherever sending fails (CI has no mail server, and it
// caught exactly that). Comparing bodies holds in both environments.
[$pss_fresh_code, $pss_fresh, $pss_fresh_raw] = api_post($base, 'action=public_subscribe', ['email' => 'cerstvy@example.com', 'lang' => 'cs'], $cookie_jar, '');
check('nová adresa vrací 200', $pss_fresh_code, 200);
[$pss2_code, $pss2, $pss2_raw] = api_post($base, 'action=public_subscribe', ['email' => 'navstevnik@example.com', 'lang' => 'cs'], $cookie_jar, '');
check('opakované přihlášení je neutrálních 200', $pss2_code, 200);
check(
    'potvrzená adresa vrací bajtově stejnou odpověď jako čerstvá (žádný oracle)',
    $pss2_raw,
    $pss_fresh_raw
);
// No delivery signal may reach an anonymous caller at all: only the
// not-yet-subscribed path attempts a send, so any such field identifies
// membership as soon as sending breaks.
check_false('odpověď nenese žádný příznak o odeslání', array_key_exists('emailSent', $pss2 ?? []));
$pdo->exec("DELETE FROM public_subscribers WHERE email = 'cerstvy@example.com'");

// Admin přehled + neutrální odhlášení.
[, $psl] = api_get_auth($base, 'action=public_subscribers', $cookie_jar);
check_true('admin vidí odběratele', count(array_filter($psl['subscribers'] ?? [], fn($r) => $r['email'] === 'navstevnik@example.com')) === 1);
[$psl_code] = api_get($base, 'action=public_subscribers');
check('anonym seznam nevidí', $psl_code, 403);

$pdo->exec("UPDATE public_subscribers SET unsubscribe_token = '" . str_pad('testunsub', 48, 'x') . "' WHERE email = 'navstevnik@example.com'");
[$psu_code] = api_post($base, 'action=public_unsubscribe', ['token' => str_pad('testunsub', 48, 'x')], $cookie_jar, '');
check('odhlášení projde', $psu_code, 200);
check('a řádek je pryč', (int)$pdo->query("SELECT COUNT(*) FROM public_subscribers WHERE email = 'navstevnik@example.com'")->fetchColumn(), 0);
[$psu2_code] = api_post($base, 'action=public_unsubscribe', ['token' => str_pad('testunsub', 48, 'x')], $cookie_jar, '');
check('odkaz ze starého mailu nikdy neukáže chybu', $psu2_code, 200);

// Rate limit: pátý zápis z téže IP v hodině projde, šestý ne.
$pdo->exec("DELETE FROM public_subscribers");
for ($ps_i = 1; $ps_i <= 5; $ps_i++) {
    api_post($base, 'action=public_subscribe', ['email' => "flood{$ps_i}@example.com"], $cookie_jar, '');
}
[$ps_flood_code] = api_post($base, 'action=public_subscribe', ['email' => 'flood6@example.com'], $cookie_jar, '');
check('šesté přihlášení z téže IP za hodinu je 429', $ps_flood_code, 429);
$pdo->exec("DELETE FROM public_subscribers");

// --- Forgotten password --------------------------------------------------
//
// The response must be identical for existing and nonexistent e-mails, else the
// form can probe who has an account.
[$code, $fp1] = api_post($base, 'action=forgot_password', ['email' => 'urcite-neexistuje@example.com'], tempnam(sys_get_temp_dir(), 'bk_fp'));
check('žádost o obnovu hesla vrací 200', $code, 200);
[$code, $fp2] = api_post($base, 'action=forgot_password', ['email' => 'admin@bloodkings.eu'], tempnam(sys_get_temp_dir(), 'bk_fp2'));
check('a pro existující účet vrací totéž', $code, 200);
check('odpověď neprozradí, jestli účet existuje', $fp1['message'] ?? 'a', $fp2['message'] ?? 'b');

// --- Instalace ----------------------------------------------------------
// The suite's own setup created the first account at the start, so the users
// table is not empty and a second install must refuse. It used to return 200
// and create nothing.
[$code, $su] = api_post($base, 'action=setup', [
    'username' => 'druhy_admin', 'email' => 'druhy@example.com', 'password' => 'DostDlouheHeslo1',
], tempnam(sys_get_temp_dir(), 'bk_setup'));
check('setup do neprázdné instalace vrací 409', $code, 409);
check_true('a vysvětlí proč', str_contains((string)($su['error'] ?? ''), 'Instalace už proběhla'));

$user_count_after = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
check('žádný účet navíc nevznikl', $user_count_after, 1);

// --- Stav relace --------------------------------------------------------
if (!empty($cookie_jar)) {
    [, $sess] = api_get_auth($base, 'action=session', $cookie_jar);
    check_true('session hlásí stav instalace', array_key_exists('installed', $sess) && $sess['installed'] === true);
    // The e-mail used to come back hardcoded as admin@bloodkings.eu no matter
    // who was logged in.
    check_true('e-mail je z databáze, ne natvrdo', array_key_exists('email', $sess['user'] ?? []));
}

// --- The audit trail -----------------------------------------------------
if (!empty($cookie_jar)) {
    [$code, $audit] = api_get_auth($base, 'action=user_audit_log&limit=20', $cookie_jar);
    check('auditní protokol vrací 200', $code, 200);
    check_true('a je to pole záznamů', isset($audit['entries']) && is_array($audit['entries']));
    // The admin login happened at the start of the tests, so the record must exist.
    check_true('obsahuje přihlášení', str_contains(json_encode($audit, JSON_UNESCAPED_UNICODE), 'login_success'));

    [$anon_audit_code] = api_get($base, 'action=user_audit_log');
    check('anonym auditní protokol nedostane', $anon_audit_code, 403);
}

// =======================================================================
// 12. The long-term metric series from the daily rollup
// =======================================================================
//
// Raw vps_metrics is pruned after 30 days, so without the rollup "how did
// disk usage grow over half a year" had no answer. The test writes data
// older than a month - exactly what would no longer exist raw.
// First the rollup itself: without it the test below would only verify reads
// from a table nobody fills.
$pdo->exec("DELETE FROM metrics_daily");
// Both samples on ONE day, chosen from the DB's own clock. Offsets from NOW()
// straddle midnight in the database's timezone for an hour every night: at
// 01:20 UTC the "2 hours ago" row lands on the previous day, the aggregate has
// one sample instead of two and the test fails with no code change. Seen in
// this suite twice now, so the day is pinned instead of hoped for.
$sample_day = $pdo->query("SELECT DATE(DATE_SUB(NOW(), INTERVAL 3 HOUR))")->fetchColumn();
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, checked_at)
            VALUES (2, 10, 50, 70, TIMESTAMP('{$sample_day}', '00:10:00'))");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, checked_at)
            VALUES (2, 90, 55, 71, TIMESTAMP('{$sample_day}', '00:20:00'))");

$rolled_metrics = bk_rollup_daily_metrics($pdo, 2);
check_true('agregace zapsala řádky', $rolled_metrics > 0);

$stmt_cpu_row = $pdo->prepare("SELECT min_val, avg_val, max_val, samples FROM metrics_daily
                               WHERE monitor_id = 2 AND metric_key = 'cpu' AND day = ?");
$stmt_cpu_row->execute([$sample_day]);
$cpu_row = $stmt_cpu_row->fetch();
check_true('CPU má agregát za den vzorků', $cpu_row !== false);
if ($cpu_row) {
    // The average alone would hide the spike; hence min and max are stored too.
    check('minimum sedí', (int)round($cpu_row['min_val']), 10);
    check('maximum sedí', (int)round($cpu_row['max_val']), 90);
    check('průměr sedí', (int)round($cpu_row['avg_val']), 50);
    check('počet vzorků sedí', (int)$cpu_row['samples'], 2);
}

// A metric the agent does not report must not appear as zero.
$swap_rows = (int)$pdo->query("SELECT COUNT(*) FROM metrics_daily WHERE metric_key = 'swap'")->fetchColumn();
check('nezměřená metrika se neagreguje', $swap_rows, 0);

// The 24 metrics of the router release ride along without a line of rollup
// code - but only while their key is in the map AND the column exists. A
// column added to the migration and forgotten in the map is invisible for a
// month, until the raw rows are pruned and nothing can be recomputed.
$pdo->exec("INSERT INTO vps_metrics (monitor_id, wifi_noise_5g, wifi_busy_5g, wifi_6e_unserved, cpu_core_max, wan_errors, wan_link_flaps, agent_run_ms, clock_skew_s, checked_at)
            VALUES (2, -92, 3.5, 1, 40, 4, 2, 4200, 3, TIMESTAMP('{$sample_day}', '00:30:00'))");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, wifi_noise_5g, wifi_busy_5g, wifi_6e_unserved, cpu_core_max, wan_errors, wan_link_flaps, agent_run_ms, clock_skew_s, checked_at)
            VALUES (2, -86, 7.5, 0, 96, 6, 0, 4400, 1, TIMESTAMP('{$sample_day}', '00:40:00'))");
bk_rollup_daily_metrics($pdo, 2);
$router_daily = $pdo->prepare("SELECT metric_key, avg_val, max_val, samples FROM metrics_daily WHERE monitor_id = 2 AND day = ? AND metric_key IN ('wifi_noise_5g', 'wifi_busy_5g', 'wifi_6e_unserved', 'cpu_core_max', 'wan_errors', 'wan_link_flaps', 'agent_run_ms', 'clock_skew_s')");
$router_daily->execute([$sample_day]);
$router_rows = [];
foreach ($router_daily->fetchAll() as $rr) {
    $router_rows[$rr['metric_key']] = $rr;
}
check('metriky routeru se agregují pod svými klíči', count($router_rows), 8);
check('nejhorší šum dne je vidět v maximu', (int)round((float)($router_rows['wifi_noise_5g']['max_val'] ?? 0)), -86);
// A step metric's day is avg x samples: 5 x 2 = 10 errors, not "5".
check('den krokové metriky je průměr × vzorky', [(float)($router_rows['wan_errors']['avg_val'] ?? 0) * (int)($router_rows['wan_errors']['samples'] ?? 0), (int)($router_rows['wan_errors']['samples'] ?? 0)], [10.0, 2]);
// Three-valued: the daily average IS the share of samples on which the answer
// was known, so 1 and 0 average to 0.5 - never "rounded to no".
check('wifi_6e_unserved se průměruje jako podíl času', (float)($router_rows['wifi_6e_unserved']['avg_val'] ?? -1), 0.5);
$pdo->exec("DELETE FROM metrics_daily WHERE monitor_id = 2 AND metric_key IN ('wifi_noise_5g', 'wifi_busy_5g', 'wifi_6e_unserved', 'cpu_core_max', 'wan_errors', 'wan_link_flaps', 'agent_run_ms', 'clock_skew_s')");
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2 AND checked_at IN (TIMESTAMP('{$sample_day}', '00:30:00'), TIMESTAMP('{$sample_day}', '00:40:00'))");

// Agent 0.1.9: the CPU time of the previous run rides the same rollup. An
// older agent's row is NULL and the day counts only measured runs - zeros in
// the average would halve what the agent really costs.
$pdo->exec("INSERT INTO vps_metrics (monitor_id, agent_prev_cpu_ms, checked_at) VALUES
            (2, 190, TIMESTAMP('{$sample_day}', '01:30:00')), (2, 250, TIMESTAMP('{$sample_day}', '01:40:00')),
            (2, NULL, TIMESTAMP('{$sample_day}', '01:50:00'))");
bk_rollup_daily_metrics($pdo, 2);
$cpu_prev_day = $pdo->prepare("SELECT avg_val, max_val, samples FROM metrics_daily WHERE monitor_id = 2 AND day = ? AND metric_key = 'agent_prev_cpu_ms'");
$cpu_prev_day->execute([$sample_day]);
$cpu_prev_row = $cpu_prev_day->fetch() ?: [];
check('CPU čas agenta se agreguje jen z naměřených běhů, NULL staršího agenta není nula',
    [(float)($cpu_prev_row['avg_val'] ?? -1), (float)($cpu_prev_row['max_val'] ?? -1), (int)($cpu_prev_row['samples'] ?? -1)],
    [220.0, 250.0, 2]);
$pdo->exec("DELETE FROM metrics_daily WHERE monitor_id = 2 AND metric_key = 'agent_prev_cpu_ms'");
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2 AND checked_at IN (TIMESTAMP('{$sample_day}', '01:30:00'), TIMESTAMP('{$sample_day}', '01:40:00'), TIMESTAMP('{$sample_day}', '01:50:00'))");

// A repeated run must neither duplicate nor double the counts.
bk_rollup_daily_metrics($pdo, 2);
$stmt_cpu_count = $pdo->prepare("SELECT COUNT(*) FROM metrics_daily
                                 WHERE monitor_id = 2 AND metric_key = 'cpu' AND day = ?");
$stmt_cpu_count->execute([$sample_day]);
check('opakovaná agregace nezaloží druhý řádek', (int)$stmt_cpu_count->fetchColumn(), 1);

$pdo->exec("DELETE FROM metrics_daily");
for ($d = 400; $d >= 0; $d -= 20) {
    $val = 40 + (400 - $d) / 20;   // pomalu rostoucí zaplnění disku
    $pdo->exec("INSERT INTO metrics_daily (monitor_id, day, metric_key, min_val, avg_val, max_val, samples)
                VALUES (2, DATE_SUB(CURDATE(), INTERVAL {$d} DAY), 'hdd', {$val}, {$val}, " . ($val + 5) . ", 1440)");
}

[$code, $year] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=hdd&period=1y', $cookie_jar);
check('roční řada vrací 200', $code, 200);
check_true('a nese body', !empty($year['points']));
check('a přizná, že jde o denní průměry', $year['resolution'] ?? null, 'daily');
check_true('k průměrům je min/max', !empty($year['dailyRange']) && array_key_exists('max', $year['dailyRange'][0]));

// A year must not reach beyond a year - the chart would claim more history than exists.
$oldest = $year['points'][0][0] ?? 0;
check_true('nejstarší bod není starší než rok', $oldest >= time() - 366 * 86400);

// Data older than the raw retention must not sneak into the short window.
[, $day] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=hdd&period=24h', $cookie_jar);
check_false('24h okno denní agregaci nepoužívá', ($day['resolution'] ?? null) === 'daily');

// An empty rollup means an empty series, not invented numbers.
$pdo->exec("DELETE FROM metrics_daily");
[, $empty_year] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=hdd&period=1y', $cookie_jar);
check('bez agregovaných dat je řada prázdná', $empty_year['points'] ?? null, []);

// =======================================================================
// 13. An agent report is stored as metrics
// =======================================================================
//
// The only test verifying the whole path: the agent sends JSON, the API writes
// it into columns and the chart then finds it. Right here 35 values used to
// get lost - sent every minute, ending only in the latest details snapshot.
$agent_key = $pdo->query("SELECT agent_key FROM monitors WHERE id = 2")->fetchColumn();
if (!$agent_key) {
    $agent_key = bin2hex(random_bytes(16));
    $stmt_ak = $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = 2");
    $stmt_ak->execute([$agent_key]);
}

$agent_payload = [
    'agent_key' => $agent_key,
    'cpu' => 12.5, 'ram' => 44.0, 'hdd' => 61.0,
    // Newly stored metrics across types: latency, signal quality, counters.
    'wan_latency_ms' => 18.4,
    'dns_latency_ms' => 7.2,
    'lte_rsrq' => -11.5,
    'lte_sinr' => 9.0,
    'ups_battery_pct' => 97.0,
    'conntrack_count' => 1234,
    'ram_used_mb' => 812.5,
    'tcp_retrans' => 500,
    'fw_dropped' => 4200,
    'dns_queries' => 9000,
    // An unmeasured value - must stay NULL, not fall to zero.
    'entropy' => null,
    'oom_kills' => 'null',
];

// --- LTE backup alert (SIM / registration from the modem) ------------------
//
// The bug this guards: a HiLink modem's interface stays up with no SIM in it,
// so the old `lte_up` flag showed a working backup for nine days and nothing
// ever notified anyone. The verdict now comes from the modem's own report,
// with a two-report debounce and a latch (one alert, one recovery).
$post_agent = function (array $extra) use ($base, $agent_payload): int {
    $ch = curl_init($base . '/agent_api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array_merge($agent_payload, $extra), JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
};
$lte_events = function (string $type) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM monitor_events WHERE monitor_id = 2 AND event_type = ?");
    $st->execute([$type]);
    return (int)$st->fetchColumn();
};
$lte_details = function () use ($pdo): array {
    return json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
};
$lte_bad = ['lte_up' => true, 'lte_uptime' => 778603, 'lte_connected' => false, 'lte_sim_state' => 'no_sim', 'lte_conn_code' => 902, 'lte_sim_code' => 255];
$lte_ok = ['lte_up' => true, 'lte_uptime' => 778700, 'lte_connected' => true, 'lte_sim_state' => 'ready', 'lte_conn_code' => 901, 'lte_sim_code' => 257];

check('1. špatné hlášení agent přijme', $post_agent($lte_bad), 200);
check('po jednom špatném hlášení se ještě nealertuje (debounce)', $lte_events('lte_backup_lost'), 0);
$d = $lte_details();
check('ale stav SIM je v details poctivě uložen', $d['lte_sim_state'] ?? null, 'no_sim');
check('a rozhraní zůstává hlášeno jako up (to je ta past)', $d['lte_up'] ?? null, true);
check('série špatných hlášení = 1', (int)($d['lte_backup_bad_streak'] ?? -1), 1);

check('2. špatné hlášení agent přijme', $post_agent($lte_bad), 200);
check('druhé špatné hlášení spustí alert', $lte_events('lte_backup_lost'), 1);
check_true('latch je nastaven', !empty($lte_details()['lte_backup_alert_sent']));
$lte_ev = $pdo->query("SELECT description FROM monitor_events WHERE monitor_id = 2 AND event_type = 'lte_backup_lost' ORDER BY id DESC LIMIT 1")->fetchColumn();
check_true('událost říká proč (SIM nenalezena)', str_contains((string)$lte_ev, 'SIM'));

check('3. špatné hlášení agent přijme', $post_agent($lte_bad), 200);
check('třetí špatné hlášení alert neopakuje (latch)', $lte_events('lte_backup_lost'), 1);

// A round the modem says nothing: no verdict, so the latch must survive it.
check('hlášení bez slova od modemu agent přijme', $post_agent(['lte_up' => true, 'lte_uptime' => 778800]), 200);
check_true('bez verdiktu latch přežije (žádné falešné "obnoveno")', !empty($lte_details()['lte_backup_alert_sent']));
check('a žádné obnovení se nehlásí', $lte_events('lte_backup_restored'), 0);

check('dobré hlášení agent přijme', $post_agent($lte_ok), 200);
check('obnovení zálohy se ohlásí jednou', $lte_events('lte_backup_restored'), 1);
check_true('a latch se uvolní', empty($lte_details()['lte_backup_alert_sent']));
check('série špatných se vynuluje', (int)($lte_details()['lte_backup_bad_streak'] ?? -1), 0);

check('další dobré hlášení agent přijme', $post_agent($lte_ok), 200);
check('obnovení se neopakuje', $lte_events('lte_backup_restored'), 1);

// PIN state is a distinct reason, and carries the attempts left.
check('hlášení s PINem agent přijme', $post_agent(['lte_up' => true, 'lte_connected' => false, 'lte_sim_state' => 'pin_required', 'lte_sim_pin_left' => 2]), 200);
check('hlášení s PINem podruhé', $post_agent(['lte_up' => true, 'lte_connected' => false, 'lte_sim_state' => 'pin_required', 'lte_sim_pin_left' => 2]), 200);
$lte_ev = $pdo->query("SELECT description FROM monitor_events WHERE monitor_id = 2 AND event_type = 'lte_backup_lost' ORDER BY id DESC LIMIT 1")->fetchColumn();
check_true('PIN alert nese důvod i zbývající pokusy', str_contains((string)$lte_ev, 'PIN') && str_contains((string)$lte_ev, '2'));
// Leave the monitor healthy for the tests that follow.
$post_agent($lte_ok);

// --- Primary link (WAN) alert ------------------------------------------------
//
// Same debounce and latch as the LTE backup, two signals: wan_up (interface)
// and wan_internet (one echo bound to the WAN device, agent 0.1.1+).
$wan_bad = ['wan_up' => true, 'wan_proto' => 'dhcp', 'wan_internet' => false];
$wan_ok = ['wan_up' => true, 'wan_proto' => 'dhcp', 'wan_internet' => true];
check('WAN: 1. špatné hlášení agent přijme', $post_agent($wan_bad), 200);
check('WAN: po jednom špatném hlášení se nealertuje (debounce)', $lte_events('wan_lost'), 0);
$d = $lte_details();
check_true('WAN: wan_internet=false je v details uloženo poctivě (false, ne 0 ani null)', array_key_exists('wan_internet', $d) && $d['wan_internet'] === false);
check('WAN: 2. špatné hlášení agent přijme', $post_agent($wan_bad), 200);
check('WAN: druhé špatné hlášení spustí alert', $lte_events('wan_lost'), 1);
check_true('WAN: latch nastaven', !empty($lte_details()['wan_alert_sent']));
$wan_ev = $pdo->query("SELECT description FROM monitor_events WHERE monitor_id = 2 AND event_type = 'wan_lost' ORDER BY id DESC LIMIT 1")->fetchColumn();
check_true('WAN: událost říká, že linka je nahoře, ale ping neprojde', str_contains((string)$wan_ev, 'ping'));
check('WAN: 3. špatné hlášení agent přijme', $post_agent($wan_bad), 200);
check('WAN: alert se neopakuje (latch)', $lte_events('wan_lost'), 1);
check('WAN: hlášení bez WAN údajů agent přijme', $post_agent(['lte_up' => true]), 200);
check_true('WAN: bez verdiktu latch přežije', !empty($lte_details()['wan_alert_sent']));
check('WAN: dobré hlášení agent přijme', $post_agent($wan_ok), 200);
check('WAN: obnovení se ohlásí jednou', $lte_events('wan_restored'), 1);
check_true('WAN: latch se uvolní', empty($lte_details()['wan_alert_sent']));
check('WAN: další dobré hlášení', $post_agent($wan_ok), 200);
check('WAN: obnovení se neopakuje', $lte_events('wan_restored'), 1);
// The interface being down is the other reason - and an older agent that
// sends no wan_internet at all still gets the alert from wan_up alone.
check('WAN: rozhraní dole (starý agent) 1×', $post_agent(['wan_up' => false, 'wan_proto' => 'dhcp']), 200);
check('WAN: rozhraní dole 2×', $post_agent(['wan_up' => false, 'wan_proto' => 'dhcp']), 200);
check('WAN: výpadek rozhraní alertuje', $lte_events('wan_lost'), 2);
$wan_ev = $pdo->query("SELECT description FROM monitor_events WHERE monitor_id = 2 AND event_type = 'wan_lost' ORDER BY id DESC LIMIT 1")->fetchColumn();
check_true('WAN: důvod je vypnuté rozhraní', str_contains((string)$wan_ev, 'vypnuté'));
check('WAN: starý agent hlásí rozhraní zpět', $post_agent(['wan_up' => true, 'wan_proto' => 'dhcp']), 200);
check('WAN: obnovení i bez wan_internet', $lte_events('wan_restored'), 2);
// An access point: no netifd interface called wan at all. Agents before
// 0.1.1 sent wan_up=false for that - with no protocol and no echo it is no
// verdict, not an outage.
check('WAN: AP bez rozhraní wan (starý agent) 1×', $post_agent(['wan_up' => false]), 200);
check('WAN: AP bez rozhraní wan 2×', $post_agent(['wan_up' => false]), 200);
check('WAN: AP nespouští wan_lost', $lte_events('wan_lost'), 2);
$post_agent($wan_ok);

// --- Wi-Fi clients per band and their Wi-Fi 6E support -------------------------
//
// Only the total used to be stored, so "how many were on 2.4 GHz" had no answer
// once the next report replaced the radio list. The server sums each band from
// the radios; 6E support comes from agent 0.1.6+ and stays null when unknown.
$wifi_before_id = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM vps_metrics")->fetchColumn();
$wifi_ni = fn ($v) => $v === null ? null : (int)$v;
$wifi_row = function () use ($pdo): array {
    return $pdo->query("SELECT wifi_clients_24g, wifi_clients_5g, wifi_clients_6g, wifi_6e_capable_24g, wifi_6e_known_24g, wifi_6e_capable_5g, wifi_6e_known_5g FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetch() ?: [];
};
check('hlášení s rádii a podporou 6E agent přijme', $post_agent([
    'wifi_clients_count' => 12,
    'wifi_radios' => [
        // `channel` decides whether the claimed band is believed: a disabled
        // 0.1.6 radio prints "2.4GHz" with no channel and no clients, and its
        // stale band must not land in a chart (sanitizer, D6).
        ['radio' => 'wlan0', 'band' => '2.4GHz', 'channel' => 6, 'clients' => 7, 'clients_6ghz_capable' => 2, 'clients_caps_known' => 6],
        ['radio' => 'wlan1', 'band' => '5GHz', 'channel' => 36, 'clients' => 4, 'clients_6ghz_capable' => 3, 'clients_caps_known' => 4],
        ['radio' => 'wlan2', 'band' => '2.4GHz', 'channel' => 11, 'clients' => 1, 'clients_6ghz_capable' => null, 'clients_caps_known' => null],
    ],
]), 200);
$w = $wifi_row();
check('klienti na 2.4 GHz se sečtou přes obě rádia', $wifi_ni($w['wifi_clients_24g'] ?? null), 8);
check('klienti na 5 GHz se uloží', $wifi_ni($w['wifi_clients_5g'] ?? null), 4);
check_true('bez rádia na 6 GHz je počet neznámý, ne nula', array_key_exists('wifi_clients_6g', $w) && $w['wifi_clients_6g'] === null);
check('na 2.4 GHz umí 6E dva klienti ze šesti známých', [$wifi_ni($w['wifi_6e_capable_24g']), $wifi_ni($w['wifi_6e_known_24g'])], [2, 6]);
check('na 5 GHz umí 6E tři ze čtyř', [$wifi_ni($w['wifi_6e_capable_5g']), $wifi_ni($w['wifi_6e_known_5g'])], [3, 4]);
check('hlášení staršího agenta bez podpory 6E agent přijme', $post_agent([
    'wifi_radios' => [
        ['radio' => 'wlan0', 'band' => '2.4GHz', 'channel' => 1, 'clients' => 5],
        ['radio' => 'wlan1', 'band' => '6GHz', 'channel' => 37, 'clients' => 2],
    ],
]), 200);
$w = $wifi_row();
check('starší agent: pásma se přesto sečtou', [$wifi_ni($w['wifi_clients_24g']), $wifi_ni($w['wifi_clients_5g']), $wifi_ni($w['wifi_clients_6g'])], [5, null, 2]);
check('starší agent: podpora 6E zůstane neznámá, ne nulová', [$w['wifi_6e_capable_24g'], $w['wifi_6e_known_24g']], [null, null]);
// A radio that claims a band but has no channel is the shape a DISABLED 0.1.6
// radio has. Believing it would draw yesterday's clients as today's.
check('vypnuté rádio bez kanálu agent přijme', $post_agent([
    'wifi_radios' => [['radio' => 'wlan0', 'band' => '2.4GHz', 'channel' => null, 'clients' => 3]],
]), 200);
$w = $wifi_row();
check('vypnuté rádio (pásmo bez kanálu) se do pásem nepočítá', [$w['wifi_clients_24g'], $w['wifi_clients_5g'], $w['wifi_clients_6g']], [null, null, null]);
[, $wifi_batch] = api_get_auth($base, 'action=metric_series_batch&monitor_id=2&period=24h', $cookie_jar);
check_true('graf klientů na 2.4 GHz je v grafech routeru', count($wifi_batch['series']['wifi_clients_24g']['points'] ?? []) >= 2);
[$wifi_s_code, $wifi_s] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=wifi_6e_capable_24g&period=24h', $cookie_jar);
check('metric_series zná podporu 6E na 2.4 GHz', $wifi_s_code, 200);
check_true('a vrátí změřenou hodnotu', in_array(2.0, array_map(fn ($p) => (float)$p[1], $wifi_s['points'] ?? []), true));
$pdo->prepare("DELETE FROM vps_metrics WHERE monitor_id = 2 AND id > ?")->execute([$wifi_before_id]);

// --- First report after a reboot: the load is not measured yet ---------------------
//
// Agents send cpu/ram/hdd as null on their first run and after a reboot. The
// server answered 400, so a new router's first report ended in an error.
check('hlášení s neznámým CPU, RAM a diskem agent přijme', $post_agent(['cpu' => null, 'ram' => null, 'hdd' => null]), 200);
$null_row = $pdo->query("SELECT cpu_usage, ram_usage, hdd_usage FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetch() ?: [];
check('neznámé hodnoty zůstanou NULL, ne nula', [$null_row['cpu_usage'] ?? 'chybí', $null_row['ram_usage'] ?? 'chybí', $null_row['hdd_usage'] ?? 'chybí'], ['chybí', 'chybí', 'chybí']);
check_true('a řádek se opravdu uložil', array_key_exists('cpu_usage', $null_row) && $null_row['cpu_usage'] === null);
check('hlášení bez klíče se dál odmítne', $post_agent(['agent_key' => '']), 400);

// --- Archive: a monitor gone for good ------------------------------------------------
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, agent_key) VALUES (97, 'Starý router', 'openwrt', 'Turris - domov', 'down', 'Síť', 'archive-test-key-97')");
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, error_message, checked_at) VALUES (97, 'down', 'Agent routeru neodpovídá', NOW())");
$pdo->exec("INSERT INTO incidents (title, impact, status, monitor_id) VALUES ('Výpadek: Starý router', 'major', 'investigating', 97)");
$arc_incident = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO agent_actions (monitor_id, action_type, status) VALUES (97, 'reboot_router', 'pending')");
$arc_ids = fn (?array $list): array => array_map(fn ($m) => (int)$m['id'], $list['monitors'] ?? []);
[, $arc_status_before] = api_get_auth($base, 'action=public_status', $cookie_jar);
[, $arc_live_before] = api_get_auth($base, 'action=monitors', $cookie_jar);
check_true('před archivací je monitor v seznamu', in_array(97, $arc_ids($arc_live_before), true));

[$arc_code, $arc_res] = api_post($base, 'action=archive_monitor', ['id' => 97], $cookie_jar);
check('admin monitor archivuje', $arc_code, 200);
check_true('archivace zapíše čas', $pdo->query("SELECT archived_at FROM monitors WHERE id = 97")->fetchColumn() !== null);
check('otevřený incident se při archivaci uzavře', $pdo->query("SELECT status FROM incidents WHERE id = {$arc_incident}")->fetchColumn(), 'resolved');
check('a server řekne kolik jich uzavřel', $arc_res['incidentsClosed'] ?? null, 1);
check('čekající vzdálená akce selže', $pdo->query("SELECT status FROM agent_actions WHERE monitor_id = 97")->fetchColumn(), 'failed');
[$arc_again] = api_post($base, 'action=archive_monitor', ['id' => 97], $cookie_jar);
check('archivovaný monitor znovu archivovat nejde', $arc_again, 409);
[$arc_get] = api_get_auth($base, 'action=archive_monitor', $cookie_jar);
check('archivace přes GET se odmítne', $arc_get, 405);

[, $arc_live] = api_get_auth($base, 'action=monitors', $cookie_jar);
check_false('archivovaný monitor zmizí ze seznamu', in_array(97, $arc_ids($arc_live), true));
[, $arc_list] = api_get_auth($base, 'action=monitors&archived=1', $cookie_jar);
check('archiv vypíše jen archivované monitory', $arc_ids($arc_list), [97]);
check_true('i s časem archivace', !empty($arc_list['monitors'][0]['archivedAt'] ?? null));
[, $arc_public] = api_get($base, 'action=monitors&scope=public&archived=1');
check_false('veřejný pohled archiv neukáže', in_array(97, $arc_ids($arc_public), true));
[, $arc_status] = api_get_auth($base, 'action=public_status', $cookie_jar);
check('souhrn archivovaný monitor nepočítá', (int)($arc_status['totalMonitors'] ?? 0), (int)($arc_status_before['totalMonitors'] ?? 0) - 1);
[, $arc_incidents] = api_get_auth($base, 'action=incidents', $cookie_jar);
check_false('probíhající výpadky ho nevypíšou', in_array(97, array_map(fn ($i) => (int)($i['monitor_id'] ?? 0), $arc_incidents['incidents'] ?? []), true));
[, $arc_windows] = api_get_auth($base, 'action=uptime_windows', $cookie_jar);
check_false('dostupnost po oknech ho vynechá', isset($arc_windows['windows']['97']) || isset($arc_windows['windows'][97]));
[$arc_history_code, $arc_history] = api_get_auth($base, 'action=events&monitor_id=97&limit=5', $cookie_jar);
check('historie archivovaného monitoru zůstane čitelná', $arc_history_code, 200);
check_true('a nese jeho záznamy', count($arc_history['events'] ?? []) > 0);

[$arc_save] = api_post($base, 'action=save_monitor', ['id' => 97, 'name' => 'Přejmenovaný', 'type' => 'openwrt'], $cookie_jar);
check('archivovaný monitor nejde upravit', $arc_save, 409);
check('jméno zůstane', $pdo->query("SELECT name FROM monitors WHERE id = 97")->fetchColumn(), 'Starý router');
[$arc_maint] = api_post($base, 'action=toggle_maintenance', ['monitor_ids' => [97], 'maintenance' => true], $cookie_jar);
check('ani mu nejde zapnout údržbu', $arc_maint, 409);
[$arc_info] = api_get_auth($base, 'action=agent_install_info&monitor_id=97', $cookie_jar);
check('instalační údaje archivovaného monitoru se nevydají', $arc_info, 409);
$arc_metrics_before = (int)$pdo->query("SELECT COUNT(*) FROM vps_metrics WHERE monitor_id = 97")->fetchColumn();
check('hlášení agenta archivovaného monitoru se odmítne', $post_agent(['agent_key' => 'archive-test-key-97']), 403);
check('a nic se z něj neuloží', (int)$pdo->query("SELECT COUNT(*) FROM vps_metrics WHERE monitor_id = 97")->fetchColumn(), $arc_metrics_before);

[$arc_restore] = api_post($base, 'action=unarchive_monitor', ['id' => 97], $cookie_jar);
check('admin monitor obnoví', $arc_restore, 200);
[, $arc_live_after] = api_get_auth($base, 'action=monitors', $cookie_jar);
check_true('obnovený monitor je zpět v seznamu', in_array(97, $arc_ids($arc_live_after), true));
check('a jeho stav je neznámý do příští kontroly', $pdo->query("SELECT status FROM monitors WHERE id = 97")->fetchColumn(), 'unknown');
check('hlášení obnoveného monitoru se zase uloží', $post_agent(['agent_key' => 'archive-test-key-97']), 200);
check_true('archivace i obnovení jsou v auditu', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('monitor_archived', 'monitor_restored') AND target_id = 97")->fetchColumn() === 2);
$pdo->exec("DELETE FROM monitors WHERE id = 97");

// --- Install details for an agent ------------------------------------------------------
//
// The app never showed the key, so an agent installed by its instructions
// stopped at "AGENT_KEY is not set".
[$ai_code, $ai] = api_get_auth($base, 'action=agent_install_info&monitor_id=2', $cookie_jar);
check('admin dostane údaje pro instalaci agenta', $ai_code, 200);
check('klíč je ten z databáze', $ai['agentKey'] ?? null, $pdo->query("SELECT agent_key FROM monitors WHERE id = 2")->fetchColumn());
check_true('adresa API míří na agent_api.php', str_ends_with((string)($ai['apiUrl'] ?? ''), '/agent_api.php'));
check_true('a nabídne skript routeru', str_ends_with((string)($ai['files']['openwrt'] ?? ''), '/agent_openwrt.sh'));
check_true('čtení klíče se zapíše do auditu', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'agent_key_viewed'")->fetchColumn() > 0);
[$ai_anon] = api_get($base, 'action=agent_install_info&monitor_id=2');
check('anonym klíč agenta nedostane', $ai_anon, 403);

// --- Threshold hysteresis -----------------------------------------------------
//
// The CPU alert clears five points below the threshold, and a latch set under
// a different threshold is stale. Default CPU threshold: 90.
$thr_before = $lte_events('threshold_exceeded');
$cpu_at = fn (float $cpu) => ['cpu' => $cpu, 'ram' => 10.0, 'hdd' => 10.0];
check('hystereze: 95 % přijato', $post_agent($cpu_at(95)), 200);
check('hystereze: překročení alertuje', $lte_events('threshold_exceeded'), $thr_before + 1);
check('hystereze: 88 % přijato', $post_agent($cpu_at(88)), 200);
check('hystereze: 95 % podruhé přijato', $post_agent($cpu_at(95)), 200);
check('hystereze: kmitání kolem limitu (88 → 95) neposílá nový alert', $lte_events('threshold_exceeded'), $thr_before + 1);
check('hystereze: 84 % přijato (pod pásmo)', $post_agent($cpu_at(84)), 200);
check('hystereze: 95 % potřetí přijato', $post_agent($cpu_at(95)), 200);
check('hystereze: po poklesu pod pásmo se překročení hlásí znovu', $lte_events('threshold_exceeded'), $thr_before + 2);
if ($pdo->query("SHOW COLUMNS FROM monitors LIKE 'cpu_threshold'")->fetch()) {
    // Latched at 95 with threshold 90; the admin raises the limit to 97.
    // The original value is put back afterwards - later tests read it.
    $thr_orig = $pdo->query("SELECT cpu_threshold FROM monitors WHERE id = 2")->fetchColumn();
    $pdo->exec("UPDATE monitors SET cpu_threshold = 97 WHERE id = 2");
    check('hystereze: 95 % po zvýšení limitu přijato', $post_agent($cpu_at(95)), 200);
    check('hystereze: 98 % přijato', $post_agent($cpu_at(98)), 200);
    check('hystereze: starý latch nepolyká překročení nového limitu', $lte_events('threshold_exceeded'), $thr_before + 3);
    $pdo->prepare("UPDATE monitors SET cpu_threshold = ? WHERE id = 2")->execute([$thr_orig === false ? null : $thr_orig]);
}
$post_agent($cpu_at(12.5));

// --- Traffic by link role (link_traffic) + the LTE throughput series -----------
//
// Roles come from the agent (wan_l3_device / lte_device); the daily totals per
// interface are the existing table, the backup periods come from the wan_lost /
// wan_restored events the WAN tests above produced.
check('hlášení s rolemi linek a LTE rychlostí agent přijme', $post_agent(['wan_up' => true, 'wan_proto' => 'dhcp', 'wan_internet' => true, 'wan_l3_device' => 'eth0', 'lte_device' => 'wwan0', 'net_lte' => 123.4]), 200);
$lte_row = $pdo->query("SELECT net_lte_kbps FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetch();
check('LTE rychlost se uložila jako metrika', round((float)($lte_row['net_lte_kbps'] ?? -1), 1), 123.4);
[$ls_code, $ls] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=net_lte&period=24h', $cookie_jar);
check('metric_series zná net_lte', $ls_code, 200);
check_true('a vrací body', count($ls['points'] ?? []) >= 1);

$pdo->exec("DELETE FROM monitor_interface_traffic WHERE monitor_id = 2");
$pdo->exec("INSERT INTO monitor_interface_traffic (monitor_id, iface, date, rx_bytes_total, tx_bytes_total) VALUES
    (2, 'eth0', CURDATE(), 1000, 2000),
    (2, 'eth0', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 10000, 20000),
    (2, 'wwan0', CURDATE(), 300, 400),
    (2, 'br-lan', CURDATE(), 99999, 99999)");
[$lt_code, $lt] = api_get_auth($base, 'action=link_traffic&monitor_id=2', $cookie_jar);
check('link_traffic vrací 200', $lt_code, 200);
// Interface names and per-link traffic are for signed-in accounts only (owner's decision).
[$lt_anon_code] = api_get($base, 'action=link_traffic&monitor_id=2');
check('link_traffic bez přihlášení je odmítnut', $lt_anon_code, 401);
check('primární linka je zařízení z wan_l3_device', $lt['primary']['iface'] ?? null, 'eth0');
check('záloha je zařízení z lte_device', $lt['backup']['iface'] ?? null, 'wwan0');
check('dnešní provoz primární linky (rx)', (float)($lt['primary']['today']['rx_bytes'] ?? -1), 1000.0);
check('7 dní primární linky sečte i starší den (tx)', (float)($lt['primary']['7d']['tx_bytes'] ?? -1), 22000.0);
check('dnešní provoz zálohy (tx)', (float)($lt['backup']['today']['tx_bytes'] ?? -1), 400.0);
check('výpadky primární linky = dva z testů výše', count($lt['wan_down_periods'] ?? []), 2);
check_true('primární linka teď neleží', ($lt['wan_down_now'] ?? null) === false);
check_true('LAN se do rolí nepočítá, ale v seznamu rozhraní je', in_array('br-lan', $lt['interfaces'] ?? [], true) && ($lt['primary']['iface'] ?? '') !== 'br-lan');
// A window with no rows at all is unknown, not "0 B transferred".
$pdo->exec("DELETE FROM monitor_interface_traffic WHERE monitor_id = 2 AND iface = 'wwan0'");
$pdo->exec("INSERT INTO monitor_interface_traffic (monitor_id, iface, date, rx_bytes_total, tx_bytes_total)
            VALUES (2, 'wwan0', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 500, 600)");
[, $lt_gap] = api_get_auth($base, 'action=link_traffic&monitor_id=2', $cookie_jar);
// `??` would turn the null we are asserting on into the fallback - the very
// trap this project has a rule about.
check_true('okno bez jediného měření je null, ne nula bajtů',
    is_array($lt_gap['backup'] ?? null) && array_key_exists('today', $lt_gap['backup']) && $lt_gap['backup']['today'] === null);
check('a okno, kde měření je, se spočítá', (float)($lt_gap['backup']['30d']['rx_bytes'] ?? -1), 500.0);
// An agent before 0.1.3 sends no wan_l3_device: the primary side is unknown, not guessed.
check('hlášení starého agenta bez rolí', $post_agent(['wan_up' => true, 'wan_proto' => 'dhcp']), 200);
[, $lt_old] = api_get_auth($base, 'action=link_traffic&monitor_id=2', $cookie_jar);
check_true('bez wan_l3_device je primární strana null', array_key_exists('primary', $lt_old) && $lt_old['primary'] === null);
check_true('a seznam rozhraní zůstává', in_array('eth0', $lt_old['interfaces'] ?? [], true));
[$lt404, ] = api_get_auth($base, 'action=link_traffic&monitor_id=999999', $cookie_jar);
check('neexistující monitor = 404', $lt404, 404);
$post_agent(['wan_up' => true, 'wan_proto' => 'dhcp', 'wan_internet' => true, 'wan_l3_device' => 'eth0', 'lte_device' => 'wwan0']);

// An outage that started before the window and never ended. Its events fall
// outside the query, so the whole period used to vanish and the answer was
// "never on the backup" for a router sitting on the backup right now.
$pdo->exec("DELETE FROM monitor_events WHERE monitor_id = 2 AND event_type IN ('wan_lost', 'wan_restored')");
$pdo->exec("INSERT INTO monitor_events (monitor_id, monitor_name, monitor_type, event_type, description, occurred_at)
            VALUES (2, 'Router bez metrik', 'openwrt', 'wan_lost', 'Výpadek před oknem', DATE_SUB(NOW(), INTERVAL 5 DAY))");
[, $lt_open] = api_get_auth($base, 'action=link_traffic&monitor_id=2&days=2', $cookie_jar);
check_true('výpadek z doby před oknem se neztratí', ($lt_open['wan_down_now'] ?? null) === true);
check('a je z něj jedno běžící období', count($lt_open['wan_down_periods'] ?? []), 1);
check_true('doba výpadku pokrývá celé okno', ($lt_open['wan_down_seconds'] ?? 0) >= 2 * 86400 - 120);
$pdo->exec("DELETE FROM monitor_events WHERE monitor_id = 2 AND event_type IN ('wan_lost', 'wan_restored')");

// --- One monitor of an asset must not answer for another -----------------
//
// `WHERE id = ? OR asset_id = ?` with a bare LIMIT 1 let MySQL return the
// sibling with the lower id, so a chart could show a different monitor's data.
$pdo->exec("DELETE FROM monitors WHERE id IN (91, 92)");
$pdo->exec("DELETE FROM assets WHERE id = 92");
$pdo->exec("INSERT INTO assets (id, name) VALUES (92, 'Asset se dvěma monitory')");
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, asset_id, category)
            VALUES (91, 'Sourozenec s nižším id', 'web', '10.0.0.91', 'up', 92, 'Test'),
                   (92, 'Cílový monitor', 'vps', '10.0.0.92', 'up', 92, 'Test')");
for ($i = 0; $i < 3; $i++) {
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (92, 42, DATE_SUB(NOW(), INTERVAL " . ($i + 1) . " MINUTE))");
}
[$sib_code, $sib] = api_get_auth($base, 'action=metric_series&monitor_id=92&metric=cpu&period=24h', $cookie_jar);
check('metric_series pro monitor se sourozencem vrací 200', $sib_code, 200);
check('a vrátí měření cílového monitoru, ne sourozencova', count($sib['points'] ?? []), 3);
[, $sib_detail] = api_get_auth($base, 'action=metric_detail&monitor_id=92&metric=cpu', $cookie_jar);
check('metric_detail ukazuje ten správný monitor', $sib_detail['monitor']['name'] ?? null, 'Cílový monitor');
[, $sib_corr] = api_get_auth($base, 'action=metric_correlations&monitor_id=92&metric=cpu&period=24h', $cookie_jar);
check('metric_correlations počítá z jeho vzorků', $sib_corr['samples'] ?? 0, 3);
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 92");
$pdo->exec("DELETE FROM monitors WHERE id IN (91, 92)");
$pdo->exec("DELETE FROM assets WHERE id = 92");

// --- last_details is a 64 KB TEXT column ----------------------------------
//
// Twelve pass-through lists of ~6.6 KB each (every one under the 8 KB
// per-key cap) add up to ~80 KB. That used to fail the UPDATE and, because
// the next report merged onto the same blob, every report after it.
$fat = [];
for ($i = 0; $i < 12; $i++) {
    $fat["bulk_list_$i"] = array_fill(0, 200, str_repeat('x', 30));
}
check('tlusté hlášení agent přijme', $post_agent($fat), 200);
$raw = (string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn();
check_true('details zůstaly validní JSON', json_decode($raw, true) !== null);
check_true('details se vešly do sloupce (<= 60 000 B)', strlen($raw) <= 60000 && strlen($raw) > 0);
$d = $lte_details();
check_true('skalární hodnoty přežily ořez (cpu z hlášení)', isset($d['cpu']));
check('a další hlášení po něm projde', $post_agent($wan_ok), 200);

$ch_agent = curl_init($base . '/agent_api.php');
curl_setopt_array($ch_agent, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($agent_payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 20,
]);
$agent_body = (string)curl_exec($ch_agent);
$agent_code = (int)curl_getinfo($ch_agent, CURLINFO_RESPONSE_CODE);
check('hlášení agenta je přijato', $agent_code, 200);

$saved = $pdo->query("SELECT * FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetch();
check_true('vznikl řádek s metrikami', $saved !== false);
if ($saved) {
    check('latence WAN se uložila', round((float)$saved['wan_latency_ms'], 1), 18.4);
    check('latence DNS se uložila', round((float)$saved['dns_latency_ms'], 1), 7.2);
    check('záporná hodnota signálu se uložila', round((float)$saved['lte_rsrq'], 1), -11.5);
    check('baterie UPS se uložila', (int)round((float)$saved['ups_battery_pct']), 97);
    check('conntrack se uložil', (int)$saved['conntrack_count'], 1234);
    check('obsazená paměť se uložila', round((float)$saved['ram_used_mb'], 1), 812.5);
    check('počítadlo TCP retransmisí se uložilo', (int)$saved['tcp_retrans'], 500);

    // The core rule: what the agent did not send is NULL. A zero would claim
    // measured zero entropy, which is something else entirely.
    check_true('neposlaná entropie je NULL', $saved['entropy_avail'] === null);
    check_true('řetězec "null" od agenta je taky NULL', $saved['oom_kills'] === null);
    check_true('nezmíněná metrika je NULL', $saved['sqm_dropped'] === null);
}

// --- Counters draw as deltas in the chart --------------------------------
//
// A cumulative kernel value would only give a rising ramp.
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
foreach ([[100, 5], [150, 4], [220, 3], [60, 2], [90, 1]] as [$val, $mins_ago]) {
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, tcp_retrans, checked_at)
                VALUES (2, {$val}, DATE_SUB(NOW(), INTERVAL {$mins_ago} MINUTE))");
}

[, $ctr] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=tcp_retrans&period=24h', $cookie_jar);
$ctr_values = array_map(fn($p) => $p[1], $ctr['points'] ?? []);
// 100→150→220 gives deltas of 50 and 70; then the value drops to 60 (counter
// reset after a reboot) - that point is skipped rather than producing a negative
// spike or a false zero. Then 60→90, i.e. 30.
// JSON returns whole numbers as int, not float - hence integer expectations.
check('počítadlo se kreslí jako přírůstek', $ctr_values, [50, 70, 30]);
check_true('popisek přizná, že jde o přírůstek', str_contains((string)($ctr['label'] ?? ''), 'přírůstek'));

// An instantaneous metric must not be converted to deltas.
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 30, DATE_SUB(NOW(), INTERVAL 2 MINUTE))");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 80, DATE_SUB(NOW(), INTERVAL 1 MINUTE))");
[, $gauge] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=24h', $cookie_jar);
check('okamžitá metrika se kreslí tak, jak byla naměřena', array_map(fn($p) => $p[1], $gauge['points'] ?? []), [30, 80]);

// --- Data collection health ----------------------------------------------
//
// An external watchdog polls the endpoint, so it must be reachable without
// login and must not claim all is well when cron never ran.
[$code, $health] = api_get($base, 'action=collection_health');
check('collection_health je veřejný a vrací 200', $code, 200);
check_true('obsahuje příznak stale', array_key_exists('stale', $health ?? []));
check_true('bez běhu cronu je lastRunAt null', array_key_exists('lastRunAt', $health ?? []) && $health['lastRunAt'] === null);
check('bez běhu cronu je stale true, ne false', $health['stale'] ?? null, true);
check_true('bez běhu cronu se nehlásí stáří', array_key_exists('ageSecs', $health ?? []) && $health['ageSecs'] === null);

// --- Metric detail (Level 3) --------------------------------------------
//
// The period window used to be computed by two identical ternary expressions
// and two of the values came out wrong: `15m` returned an hour and `6h`
// returned twenty-four. The label in the UI therefore claimed something other
// than the chart showed. This is tested against real data, because no
// response-shape check can catch that kind of bug.
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
foreach ([5, 30, 200, 2000] as $ago) {
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at)
                VALUES (2, 50, DATE_SUB(NOW(), INTERVAL {$ago} MINUTE))");
}

[, $m15] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=15m', $cookie_jar);
check('15m vrátí jen měření za posledních 15 minut', count($m15['points'] ?? []), 1);

[, $m6h] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=6h', $cookie_jar);
check('6h vrátí měření za šest hodin, ne za den', count($m6h['points'] ?? []), 3);

// The oldest point is 2000 minutes back, i.e. more than a day - it does not
// belong in the 24h window but does in the weekly one. That is what proves the
// window actually moves.
[, $m24h] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=24h', $cookie_jar);
check('24h nechá venku měření starší než den', count($m24h['points'] ?? []), 3);

[, $m7d] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=7d', $cookie_jar);
check('7d vrátí i to nejstarší', count($m7d['points'] ?? []), 4);

// The comparison curve in the chart is the same window shifted one period
// back, so `previous=1` must return exactly the points the current window
// leaves out. If the two shared even one point, the overlaid curves would
// claim two different measurements happened at the same moment.
[, $prev24] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=24h&previous=1', $cookie_jar);
check('previous=1 vrátí předchozí okno (jen měření staré 2000 minut)', count($prev24['points'] ?? []), 1);
check_true(
    'a nesdílí s aktuálním oknem ani jeden bod',
    empty(array_intersect(array_column($m24h['points'] ?? [], 0), array_column($prev24['points'] ?? [], 0)))
);
// All four measurements fall inside the week, so the week before it is empty.
// That is what proves the offset really is one whole period.
[, $prev7d] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu&period=7d&previous=1', $cookie_jar);
check('previous=1 pro týden sahá do předminulého týdne', count($prev7d['points'] ?? []), 0);

// Context for the metric detail page.
[$code, $detail] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=cpu', $cookie_jar);
check('metric_detail vrací 200', $code, 200);
check('a ví, o který monitor jde', $detail['monitor']['name'] ?? null, 'Router bez metrik');
check('a jak se metrika jmenuje', isset($detail['metric']['label']), true);
check_true('prahy jsou v odpovědi vždy', array_key_exists('thresholds', $detail ?? []));
// The schema gives a monitor a default cpu_threshold of 90, so a band SHOULD
// be drawn. The warning zone sits 15 points below critical - as in the legacy page.
check('kritická mez jde z prahu monitoru', $detail['thresholds']['critical'], 90);
check('varovné pásmo je 15 bodů pod ní', $detail['thresholds']['warning'], 75);

// A metric with no configurable threshold gets no band - a coloured zone would
// pretend a limit nobody ever defined.
// Beware `?? 'missing'`: null would fall through it and the test would report
// success even if the field were absent entirely. Hence array_key_exists and
// direct access.
[, $no_thr] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=load1', $cookie_jar);
check_true('metrika bez prahu má pole thresholds', array_key_exists('critical', $no_thr['thresholds'] ?? []));
check('a pásmo nekreslí', $no_thr['thresholds']['critical'], null);

// Related metrics only where the monitor actually reports them. Monitor 2 has
// only cpu_usage filled in, so the list must be empty (cpu is the one shown).
check('nenabízí proklik do metrik bez dat', $detail['related'] ?? null, []);

[$code] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=neexistujici', $cookie_jar);
check('neznámá metrika vrací 404, ne prázdnou stránku', $code, 404);

[$code] = api_get_auth($base, 'action=metric_detail&monitor_id=99999&metric=cpu', $cookie_jar);
check('neznámý monitor vrací 404', $code, 404);

// --- Process history: what was running when the metric spiked -----------
//
// The agents have sent these rankings every minute for a long time, but they
// only ever landed in last_details where the next report overwrote them. The
// chart could show that CPU hit 90 % at 19:40 and never what caused it.
$pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, pid, cpu_pct, ram_mb)
            VALUES (2, DATE_SUB(NOW(), INTERVAL 3 MINUTE), 'cpu', 'hostapd', 1234, 87.5, 12.5)");
$pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, pid, cpu_pct, ram_mb)
            VALUES (2, DATE_SUB(NOW(), INTERVAL 3 MINUTE), 'cpu', 'kresd', 2345, 12.0, NULL)");
// Outside the window - must not appear in the answer.
$pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, pid, cpu_pct, ram_mb)
            VALUES (2, DATE_SUB(NOW(), INTERVAL 5 HOUR), 'cpu', 'davno-pryc', 999, 99.0, 1.0)");

$now_ts = time();
[$code, $ph] = api_get_auth($base, 'action=process_history&monitor_id=2&kind=cpu&at=' . $now_ts . '&radius=10', $cookie_jar);
check('process_history vrací 200', $code, 200);
// Process names and PIDs are for signed-in accounts only (owner's decision).
[$ph_anon_code] = api_get($base, 'action=process_history&monitor_id=2&kind=cpu&at=' . $now_ts . '&radius=10');
check('process_history bez přihlášení je odmítnut', $ph_anon_code, 401);
check('vrátí jen procesy z okna', count($ph['samples'] ?? []), 2);
check('nejvyšší je první', $ph['samples'][0]['name'] ?? null, 'hostapd');
check('a se svou hodnotou CPU', $ph['samples'][0]['cpuPct'] ?? null, 87.5);

// An unmeasured dimension stays null. A zero would claim "measured, the process
// used nothing" - different from "the agent did not report it for this one".
$kresd = null;
foreach ($ph['samples'] as $sample) {
    if ($sample['name'] === 'kresd') { $kresd = $sample; }
}
check_true('proces bez údaje o paměti je v odpovědi', $kresd !== null);
check('a paměť má null, ne nulu', $kresd['ramMb'], null);

check_true('odpověď přiznává, jestli je sběr zapnutý', array_key_exists('enabled', $ph ?? []));
check('bez prořezání je pruned false', $ph['pruned'] ?? null, false);

// An empty window is not the same as collection turned off - the client must tell them apart.
[, $ph_empty] = api_get_auth($base, 'action=process_history&monitor_id=2&kind=cpu&at=' . ($now_ts - 86400 * 3) . '&radius=5', $cookie_jar);
check('okno bez záznamů vrátí prázdno, ne chybu', $ph_empty['samples'] ?? null, []);
check('a pořád hlásí, že sběr běží', $ph_empty['enabled'] ?? null, true);

[$code] = api_get_auth($base, 'action=process_history&monitor_id=2&kind=cpu', $cookie_jar);
check('bez času vrací 400, ne prázdný seznam', $code, 400);

// --- Executive summary: how long, and because of what --------------------
//
// The summary used to say "X is online. No current issues detected." and
// nothing else - true, but it fit on the page without telling you anything the
// rest of it did not. A threshold breach is only meaningful with a duration
// ("above 85 % for 18 minutes", not "at 91 % right now") and with the process
// behind it, and both are computable from data we already store.
bk_test_load_functions($root . '/functions.php', ['bk_metric_pressure', 'bk_top_process_in_window']);

if (function_exists('bk_metric_pressure')) {
    $pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
    $pdo->exec("DELETE FROM process_samples WHERE monitor_id = 2");

    // Ten minutes above the threshold, below it before.
    for ($i = 14; $i >= 0; $i--) {
        $val = $i <= 10 ? 91 : 20;
        $st = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at)
                             VALUES (2, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))");
        $st->execute([$val, $i]);
    }

    $pressure = bk_metric_pressure($pdo, 2, 'cpu_usage', 85.0);
    check_true('tlak nad prahem se rozpozná', $pressure !== null);
    check('a trvá deset minut, ne patnáct', $pressure['minutes'], 10);
    check('aktuální hodnota sedí', $pressure['current'], 91.0);

    // A value below the threshold is not pressure, even if a spike came before.
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 30, NOW())");
    check('po poklesu se tlak nehlásí', bk_metric_pressure($pdo, 2, 'cpu_usage', 85.0), null);

    // A single measurement is not a duration.
    $pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 99, NOW())");
    check('jeden vzorek se nevydává za trvání', bk_metric_pressure($pdo, 2, 'cpu_usage', 85.0), null);

    // The column name goes into SQL, so only list members may pass.
    check('neznámý sloupec se odmítne', bk_metric_pressure($pdo, 2, 'cpu_usage; DROP TABLE monitors', 85.0), null);
}

// --- Knowledge tips: the threshold comes from the monitor's settings ------
//
// Tips had hardcoded thresholds (CPU 80/50) while the chart bands and the
// Executive Summary follow monitors.cpu_threshold. Anyone who raised theirs
// to 95 still got a critical tip at 81 % - three opinions on "too high".
bk_test_load_functions($root . '/functions.php', [
    'bk_get_knowledge_tips', 'bk_enrich_threshold_tip', 'bk_metric_duration_above',
    'bk_format_duration', 'bk_get_enabled_metrics',
]);
require_once $root . '/lang.php';

if (function_exists('bk_get_knowledge_tips')) {
    $details_85 = ['cpu' => 85, 'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61.0, 'ram_mb' => 8.0]]];

    // Threshold 95: 85 % sits below it, no critical tip may appear.
    $mon_high = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 95];
    $tips_high = bk_get_knowledge_tips($mon_high, $details_85, [], 'up', [], $pdo);
    $crit_high = array_filter($tips_high, fn($t) => $t['severity'] === 'critical');
    check('s prahem 95 se při 85 % nekřičí', count($crit_high), 0);

    // Threshold 80: the same measurement sits above it.
    $mon_low = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 80];
    $tips_low = bk_get_knowledge_tips($mon_low, $details_85, [], 'up', [], $pdo);
    $crit_low = array_filter($tips_low, fn($t) => $t['severity'] === 'critical');
    check_true('s prahem 80 kritický tip vznikne', count($crit_low) > 0);

    // And it names the culprit - the sentence exists for that.
    $text = implode(' ', array_column($crit_low, 'text'));
    check_true('a jmenuje viníka', str_contains($text, 'hostapd'));

    // Without a configured threshold the original behaviour (80/50) stays, so
    // nothing shifts under anyone's feet.
    $mon_none = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt'];
    $tips_none = bk_get_knowledge_tips($mon_none, $details_85, [], 'up', [], $pdo);
    check_true('bez prahu platí původní 80', count(array_filter($tips_none, fn($t) => $t['severity'] === 'critical')) > 0);
}

// --- Knowledge tips: the threshold comes from the monitor's settings ------
//
// Tips had hardcoded thresholds (CPU 80/50) while the chart bands and the
// Executive Summary follow monitors.cpu_threshold. Anyone who raised theirs
// to 95 still got a critical tip at 81 % - three opinions on "too high".
bk_test_load_functions($root . '/functions.php', [
    'bk_get_knowledge_tips', 'bk_enrich_threshold_tip', 'bk_metric_duration_above',
    'bk_format_duration', 'bk_get_enabled_metrics',
]);
require_once $root . '/lang.php';

if (function_exists('bk_get_knowledge_tips')) {
    $details_85 = ['cpu' => 85, 'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61.0, 'ram_mb' => 8.0]]];

    // Threshold 95: 85 % sits below it, no critical tip may appear.
    $mon_high = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 95];
    $tips_high = bk_get_knowledge_tips($mon_high, $details_85, [], 'up', [], $pdo);
    check('s prahem 95 se při 85 % nekřičí', count(array_filter($tips_high, fn($t) => $t['severity'] === 'critical')), 0);

    // Threshold 80: the same measurement sits above it.
    $mon_low = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 80];
    $tips_low = bk_get_knowledge_tips($mon_low, $details_85, [], 'up', [], $pdo);
    $crit_low = array_filter($tips_low, fn($t) => $t['severity'] === 'critical');
    check_true('s prahem 80 kritický tip vznikne', count($crit_low) > 0);
    check_true('a jmenuje viníka', str_contains(implode(' ', array_column($crit_low, 'text')), 'hostapd'));

    // Without a configured threshold the original behaviour (80/50) stays.
    $mon_none = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt'];
    $tips_none = bk_get_knowledge_tips($mon_none, $details_85, [], 'up', [], $pdo);
    check_true('bez prahu platí původní 80', count(array_filter($tips_none, fn($t) => $t['severity'] === 'critical')) > 0);
}

// The summary must not contradict itself in one breath.
//
// The first version said "No current problems detected." immediately followed
// by "CPU has been at 91 % for 18 min." - the calm sentence was assembled
// before the pressure was even computed. Caught on a demo with real data, not by reasoning.
bk_test_load_functions($root . '/functions.php', ['bk_summary_pressure_line', 'bk_build_executive_summary']);
// The summary composes sentences via t(), so without the dictionary it dies
// on an undefined function - the same trap cron.php and agent_api.php already took.
require_once $root . '/lang.php';
if (function_exists('bk_build_executive_summary')) {
    $pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
    for ($i = 12; $i >= 0; $i--) {
        $st = $pdo->prepare("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at)
                             VALUES (2, 91, DATE_SUB(NOW(), INTERVAL ? MINUTE))");
        $st->execute([$i]);
    }
    $mon = ['id' => 2, 'name' => 'Router', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 85];
    $text = bk_build_executive_summary($mon, null, [], [], [], $pdo, []);
    check_true('shrnutí hlásí tlak', str_contains($text, '91'));
    check_false('a netvrdí zároveň, že je klid', str_contains($text, 'Žádné aktuální problémy'));

    // Without pressure that sentence MUST appear, or the summary would stay silent.
    $pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 10, NOW())");
    $calm = bk_build_executive_summary($mon, null, [], [], [], $pdo, []);
    check_true('bez tlaku se přizná klid', str_contains($calm, 'Žádné aktuální problémy'));
}

if (function_exists('bk_top_process_in_window')) {
    $pdo->exec("DELETE FROM process_samples WHERE monitor_id = 2");
    $pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, cpu_pct, ram_mb)
                VALUES (2, DATE_SUB(NOW(), INTERVAL 5 MINUTE), 'cpu', 'hostapd', 61.0, 12.0)");
    $pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, cpu_pct, ram_mb)
                VALUES (2, DATE_SUB(NOW(), INTERVAL 5 MINUTE), 'cpu', 'kresd', 8.0, 30.0)");

    $top = bk_top_process_in_window($pdo, 2, time() - 600, time(), 'cpu');
    check('viník se najde', $top['name'] ?? null, 'hostapd');
    check('i s hodnotou', $top['cpu'] ?? null, 61.0);

    // Outside the window nothing is invented - process history is young and an
    // older spike simply cannot be explained.
    check('mimo okno se nehádá', bk_top_process_in_window($pdo, 2, time() - 86400 * 5, time() - 86400 * 4, 'cpu'), null);
}

// --- Set password from an invite token -----------------------------------
//
// The React page /app/set-password posts here. The token is consumed on first
// success, and an invalid token gets the same answer as an expired one.
bk_test_load_functions($root . '/functions.php', ['bk_totp_calculate']);

$invite_token = bin2hex(random_bytes(24));
$pdo->prepare("UPDATE users SET password_reset_token_hash = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = 1")
    ->execute([hash('sha256', $invite_token)]);

[$code] = api_post($base, 'action=set_password', ['token' => $invite_token, 'password' => 'kratke'], $cookie_jar);
check('krátké heslo vrací 400', $code, 400);

[$code] = api_post($base, 'action=set_password', ['token' => 'neexistujici-token', 'password' => 'NoveHeslo123!'], $cookie_jar);
check('cizí token vrací 400', $code, 400);

[$code, $sp_ok] = api_post($base, 'action=set_password', ['token' => $invite_token, 'password' => 'NoveHeslo123!'], $cookie_jar);
check('platný token nastaví heslo', $code, 200);
check_true('a hlásí úspěch', !empty($sp_ok['success']));

// The token was consumed - a second use must fail.
[$code] = api_post($base, 'action=set_password', ['token' => $invite_token, 'password' => 'JineHeslo123!'], $cookie_jar);
check('spotřebovaný token vrací 400', $code, 400);

// And the new password can log in (into a new session, so the admin's stays intact).
$login_jar2 = tempnam(sys_get_temp_dir(), 'bk_test_c2');
[$code, $relog] = api_post($base, 'action=login', ['username' => 'admin', 'password' => 'NoveHeslo123!'], $login_jar2);
check_true('nové heslo funguje pro přihlášení', $code === 200 && !empty($relog['success']));
// Vratit puvodni heslo, dalsi testy s nim pocitaji.
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = 1")
    ->execute([password_hash($bk_test_admin_password, PASSWORD_BCRYPT)]);
@unlink($login_jar2);

// --- TOTP enrollment ------------------------------------------------------
//
// Two steps like the legacy admin: the secret lives in the session until a
// code proves the QR scanned. The test computes a real code with the same
// bk_totp_calculate the server verifies with.
if (function_exists('bk_totp_calculate')) {
    [$code, $ts] = api_post($base, 'action=totp_setup', [], $cookie_jar);
    check('totp_setup vrací 200', $code, 200);
    check_true('a secret', !empty($ts['secret']));
    check_true('a otpauth URI', str_starts_with($ts['otpauthUri'] ?? '', 'otpauth://totp/'));

    // A wrong code must not enable 2FA.
    [$code] = api_post($base, 'action=totp_confirm', ['code' => '000000'], $cookie_jar);
    check('špatný kód vrací 400', $code, 400);
    $totp_row = $pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn();
    check('a 2FA zůstává vypnuté', (int)$totp_row, 0);

    // The right code computed from the same secret.
    $valid_code = bk_totp_calculate($ts['secret'], (int)floor(time() / 30));
    [$code, $tc] = api_post($base, 'action=totp_confirm', ['code' => $valid_code], $cookie_jar);
    check('správný kód 2FA zapne', $code, 200);
    check('v databázi je zapnuto', (int)$pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn(), 1);

    // --- Recovery codes ----------------------------------------------------
    // Enabling 2FA hands out ten one-time codes exactly once; only hashes
    // are stored. A code signs the user in IN PLACE of the TOTP code when
    // the phone is gone - and is consumed by doing so.
    $rc = $tc['recoveryCodes'] ?? [];
    check('potvrzení vrací 10 záložních kódů', count($rc), 10);
    check_true('kódy mají tvar xxxxx-xxxxx', (bool)preg_match('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $rc[0] ?? ''));
    check('v DB je 10 hashů, ne kódy', (int)$pdo->query("SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = 1 AND used_at IS NULL")->fetchColumn(), 10);
    check_true('plaintext kód v DB není', $pdo->query("SELECT COUNT(*) FROM totp_recovery_codes WHERE code_hash = " . $pdo->quote(str_replace('-', '', $rc[0])))->fetchColumn() == 0);

    [, $mp_rc] = api_get_auth($base, 'action=my_profile', $cookie_jar);
    check('profil hlásí 10 zbývajících kódů', $mp_rc['totpRecoveryRemaining'] ?? null, 10);

    // Sign-in with a recovery code instead of the TOTP code (separate jar,
    // the admin session must stay intact).
    $rc_jar = tempnam(sys_get_temp_dir(), 'bk_test_rc');
    [$code, $rc_login] = api_post($base, 'action=login', ['username' => 'admin', 'password' => $bk_test_admin_password, 'totp_code' => $rc[0]], $rc_jar, '');
    check('záložní kód přihlásí', $code, 200);
    check('a odpověď říká, kolik kódů zbývá', $rc_login['recoveryCodesRemaining'] ?? null, 9);

    // Strictly single use: the same code a second time must fail.
    [$code] = api_post($base, 'action=login', ['username' => 'admin', 'password' => $bk_test_admin_password, 'totp_code' => $rc[0]], $rc_jar, '');
    check('použitý kód podruhé nepřihlásí', $code, 401);
    @unlink($rc_jar);

    // Regeneration requires the password and invalidates the old set.
    [$code] = api_post($base, 'action=totp_recovery_regenerate', ['password' => 'spatne-heslo'], $cookie_jar);
    check('regenerace se špatným heslem vrací 400', $code, 400);
    [$code, $rr] = api_post($base, 'action=totp_recovery_regenerate', ['password' => $bk_test_admin_password], $cookie_jar);
    check('se správným heslem vrací novou sadu', $code, 200);
    check('nová sada má zase 10 kódů', count($rr['recoveryCodes'] ?? []), 10);
    $rc_jar2 = tempnam(sys_get_temp_dir(), 'bk_test_rc2');
    [$code] = api_post($base, 'action=login', ['username' => 'admin', 'password' => $bk_test_admin_password, 'totp_code' => $rc[1]], $rc_jar2, '');
    check('starý kód po regeneraci neplatí', $code, 401);
    @unlink($rc_jar2);

    // Disabling without the password must fail - a stolen session must not quietly remove 2FA.
    [$code] = api_post($base, 'action=totp_disable', ['password' => 'spatne-heslo'], $cookie_jar);
    check('vypnutí se špatným heslem vrací 400', $code, 400);
    check('2FA drží', (int)$pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn(), 1);

    [$code] = api_post($base, 'action=totp_disable', ['password' => $bk_test_admin_password], $cookie_jar);
    check('se správným heslem se vypne', $code, 200);
    check('a v databázi je vypnuto', (int)$pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn(), 0);
    // Codes without 2FA would be a sign-in backdoor - disabling removes them.
    check('vypnutí 2FA smaže i záložní kódy', (int)$pdo->query("SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = 1")->fetchColumn(), 0);
}

// --- Retention for process history --------------------------------------
//
// The two-stage prune is the part that can quietly destroy data, so it is
// tested against a real database rather than reasoned about. Timestamps are
// built with MySQL DATE_SUB so they share a timezone with NOW() inside the
// function - the same mismatch already produced one bug in this endpoint.
bk_test_load_functions($root . '/functions.php', ['bk_prune_process_samples']);

$seed_samples = function (PDO $pdo) {
    $pdo->exec("DELETE FROM process_samples");
    $rows = [
        // [days back, name, cpu, ram]
        [1, 'dnesni-klidny', 3.0, 10.0],
        [1, 'dnesni-spicka', 95.0, 10.0],
        [10, 'stary-klidny', 3.0, 10.0],
        [10, 'stary-spicka', 95.0, 10.0],
        [10, 'stary-zravy-na-pamet', 1.0, 800.0],
        [90, 'davno-za-retenci', 99.0, 900.0],
    ];
    foreach ($rows as [$days_ago, $name, $cpu, $ram]) {
        $st = $pdo->prepare(
            "INSERT INTO process_samples (monitor_id, sampled_at, kind, name, cpu_pct, ram_mb)
             VALUES (2, DATE_SUB(NOW(), INTERVAL ? DAY), 'cpu', ?, ?, ?)"
        );
        $st->execute([$days_ago, $name, $cpu, $ram]);
    }
};

$names_left = function (PDO $pdo): array {
    $rows = $pdo->query("SELECT name FROM process_samples ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
};

if (function_exists('bk_prune_process_samples')) {
    // 1. Without thinning only what is beyond retention is dropped.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 30, 0, 50.0);
    check('za retencí se smaže', $r['deleted'], 1);
    check('bez prořezávání se nic neprořezává', $r['pruned'], 0);
    check(
        'v okně retence zůstane všechno',
        $names_left($pdo),
        ['dnesni-klidny', 'dnesni-spicka', 'stary-klidny', 'stary-spicka', 'stary-zravy-na-pamet']
    );

    // 2. With thinning on, old calm samples vanish and the peaks stay.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 30, 7, 50.0);
    check('prořezal se jeden klidný starý vzorek', $r['pruned'], 1);
    check('a dvě špičky se označily', $r['marked'], 2);
    check(
        'dnešek nedotčen, ze starých zbyly špičky',
        $names_left($pdo),
        ['dnesni-klidny', 'dnesni-spicka', 'stary-spicka', 'stary-zravy-na-pamet']
    );

    // The marking must be visible in the data - a thinned window would otherwise
    // look like a time when nothing happened.
    $kept = $pdo->query("SELECT kept_reason FROM process_samples WHERE name = 'stary-spicka'")->fetchColumn();
    check('přeživší vzorek přizná, že je z prořezaného okna', $kept, 'peak');
    $fresh = $pdo->query("SELECT kept_reason FROM process_samples WHERE name = 'dnesni-klidny'")->fetchColumn();
    check('čerstvý vzorek zůstává raw', $fresh, 'raw');

    // 3. Thinning configured beyond the retention boundary is a no-op.
    //
    // Mind what this case does NOT test: it does not guard data loss, because
    // the first phase deletes the affected window before the second reaches it -
    // verified by sabotage, the tests kept passing with the guard removed. The
    // guard therefore protects no data, it only saves two pointless queries.
    // The test guards what matters: fresh samples stay untouched in that configuration.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 5, 30, 50.0);
    check('prořezání za hranicí retence nic neprořeže', $r['pruned'], 0);
    check('a nic neoznačí', $r['marked'], 0);
    check('čerstvé vzorky zůstanou nedotčené', $names_left($pdo), ['dnesni-klidny', 'dnesni-spicka']);

    // 4. Disabled history empties the table - data nobody collects and
    //    nobody sees has no business in the database.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 0);
    check_true('vypnutá historie se přizná', $r['disabled']);
    check('a tabulka je prázdná', $names_left($pdo), []);
}

// --- Settings: save, read back, save again ------------------------------
//
// This set exists because of one specific silent data loss: `whatsapp_*` could
// be saved, but get_settings never returned those keys. The form therefore
// showed them empty and the next "Save all" overwrote them with empty values -
// the settings vanished without anyone making a mistake and without anything
// being reported. This reproduces the whole cycle, not just a single save.
if ($logged_in) {
    // The key list is read from the same source as the server, not from a copy
    // in the test - a copy would drift apart exactly like the original three.
    bk_test_load_functions($root . '/db.php', ['bk_settings_keys']);

    [$code, $res] = api_post($base, 'action=save_settings', [
        'settings' => [
            'escalation_enabled' => '1',
            'escalation_after_mins' => '20',
            'escalation_webhook_url' => 'https://discord.com/api/webhooks/test',
            'smtp_host' => 'smtp.example.com',
            'smtp_pass' => 'TajneHeslo123',
        ],
    ], $cookie_jar);
    check('save_settings vrací 200', $code, 200);

    [$code, $got] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    check('get_settings vrací 200', $code, 200);
    $s = $got['settings'] ?? [];

    // The test buttons send a REAL message and report the channel's verdict.
    // Without a saved webhook the verdict is an honest failure, not "Test OK".
    // Historie doručení: řádek vzniká i u neúspěchu, protože to je ta
    // zajímavější půlka. Bez přihlášení se nevydá vůbec - jsou tam adresáti.
    $pdo->exec("INSERT INTO notification_log (monitor_id, status, channel, recipient, ok, error_message)
                VALUES (1, 'down', 'email', 'admin@example.com', 1, NULL)");
    $pdo->exec("INSERT INTO notification_log (monitor_id, status, channel, recipient, ok, error_message)
                VALUES (1, 'down', 'discord', NULL, 0, 'HTTP 404')");
    // Vymazání historie je nevratné, takže server chce zpátky přesný název.
    // Překlep nebo prázdné pole nesmí smazat měsíce měření.
    $pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at) VALUES (1, 'up', 111, NOW())");
    [$clr_anon] = api_post($base, 'action=clear_monitor_history', ['monitor_id' => 1, 'confirm_name' => 'Testovací web'], '');
    check('anonym historii nesmaže', $clr_anon, 403);
    [$clr_bad] = api_post($base, 'action=clear_monitor_history', ['monitor_id' => 1, 'confirm_name' => 'špatný název'], $cookie_jar);
    check('špatné potvrzení je 400', $clr_bad, 400);
    $clr_before = (int)$pdo->query("SELECT COUNT(*) FROM monitor_logs WHERE monitor_id = 1")->fetchColumn();
    check_true('a nic nesmazalo', $clr_before > 0);
    [$clr_ok] = api_post($base, 'action=clear_monitor_history', ['monitor_id' => 1, 'confirm_name' => 'Testovací web'], $cookie_jar);
    check('se správným názvem projde', $clr_ok, 200);
    check('a historie je pryč', (int)$pdo->query("SELECT COUNT(*) FROM monitor_logs WHERE monitor_id = 1")->fetchColumn(), 0);
    // Stav jde s ní: „up“ vedle prázdné historie by tvrdilo měření, které
    // už neexistuje.
    check('stav se vrátil na neznámý', $pdo->query("SELECT status FROM monitors WHERE id = 1")->fetchColumn(), 'unknown');

    // Údržba jedním voláním, klidně pro víc monitorů. Vypnutí musí smazat i
    // okno, jinak by další údržba vypršela hned, jak ji někdo zapne.
    [$tm_anon] = api_post($base, 'action=toggle_maintenance', ['monitor_ids' => [1], 'maintenance' => true], '');
    check('anonym údržbu nepřepne', $tm_anon, 403);
    [$tm_code, $tm_res] = api_post(
        $base,
        'action=toggle_maintenance',
        ['monitor_ids' => [1, 2], 'maintenance' => true, 'description' => 'Výměna disku', 'maintenance_end' => '2030-01-02 03:00:00'],
        $cookie_jar
    );
    check('toggle_maintenance vrací 200', $tm_code, 200);
    check('a přepne oba monitory', $tm_res['changed'] ?? 0, 2);
    [, $tm_list] = api_get_auth($base, 'action=monitors', $cookie_jar);
    $tm_by_id = [];
    foreach (($tm_list['monitors'] ?? []) as $m) { $tm_by_id[(int)$m['id']] = $m; }
    check_true('údržba je zapnutá u obou', ($tm_by_id[1]['maintenance'] ?? null) === true && ($tm_by_id[2]['maintenance'] ?? null) === true);
    check('a popis je veřejný, dokud běží', $tm_by_id[1]['maintenanceDescription'] ?? null, 'Výměna disku');
    api_post($base, 'action=toggle_maintenance', ['monitor_ids' => [1, 2], 'maintenance' => false], $cookie_jar);
    [, $tm_off] = api_get_auth($base, 'action=monitors', $cookie_jar);
    $tm_off_by_id = [];
    foreach (($tm_off['monitors'] ?? []) as $m) { $tm_off_by_id[(int)$m['id']] = $m; }
    check_false('po vypnutí už údržba neběží', ($tm_off_by_id[1]['maintenance'] ?? null) === true);
    $tm_win = $pdo->query("SELECT maintenance_end FROM monitors WHERE id = 1")->fetchColumn();
    check_true('a okno je smazané, ne jen příznak', $tm_win === null);

    // Kdo bral výkon za období, ne kdo byl náhodou nahoře v posledním hlášení.
    // Seskupuje se podle jména: služba, která se restartuje, žere dál pod novým
    // pid a per-pid žebříček by ji rozdrobil na neškodně vypadající řádky.
    $pdo->exec("DELETE FROM process_samples WHERE monitor_id = 2");
    for ($i = 1; $i <= 5; $i++) {
        $pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, pid, cpu_pct, ram_mb)
                    VALUES (2, DATE_SUB(NOW(), INTERVAL {$i} MINUTE), 'cpu', 'hogger', " . (100 + $i) . ", " . (60 + $i) . ", NULL)");
        $pdo->exec("INSERT INTO process_samples (monitor_id, sampled_at, kind, name, pid, cpu_pct, ram_mb)
                    VALUES (2, DATE_SUB(NOW(), INTERVAL {$i} MINUTE), 'cpu', 'quiet', 200, 2, NULL)");
    }
    [$pt_anon] = api_get($base, 'action=process_top&monitor_id=2');
    check('anonym žebříček procesů nedostane', $pt_anon, 401);
    [$pt_code, $pt] = api_get_auth($base, 'action=process_top&monitor_id=2&kind=cpu&minutes=1440', $cookie_jar);
    check('process_top vrací 200', $pt_code, 200);
    check('nejžravější je první', $pt['processes'][0]['name'] ?? null, 'hogger');
    check_true('a nese průměr i špičku', ($pt['processes'][0]['max'] ?? 0) >= ($pt['processes'][0]['avg'] ?? 0));
    check_true('pět vzorků pod jedním jménem, ne pět řádků', ($pt['processes'][0]['samples'] ?? 0) === 5);
    $pdo->exec("DELETE FROM process_samples WHERE monitor_id = 2");

    // Denní provoz per rozhraní: tabulka ho drží dávno, četl ho jen součet.
    $pdo->exec("INSERT INTO monitor_interface_traffic (monitor_id, iface, date, rx_bytes_total, tx_bytes_total, rx_packets_total, tx_packets_total)
                VALUES (2, 'wan', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 5000000000, 1000000000, 10, 10)
                ON DUPLICATE KEY UPDATE rx_bytes_total = VALUES(rx_bytes_total)");
    $pdo->exec("INSERT INTO monitor_interface_traffic (monitor_id, iface, date, rx_bytes_total, tx_bytes_total, rx_packets_total, tx_packets_total)
                VALUES (2, 'wan', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 7000000000, 2000000000, 10, 10)
                ON DUPLICATE KEY UPDATE rx_bytes_total = VALUES(rx_bytes_total)");
    [$itd_anon] = api_get($base, 'action=interface_traffic_daily&monitor_id=2');
    check('anonym denní provoz rozhraní nedostane', $itd_anon, 401);
    [$itd_code, $itd] = api_get_auth($base, 'action=interface_traffic_daily&monitor_id=2&days=30', $cookie_jar);
    check('interface_traffic_daily vrací 200', $itd_code, 200);
    check_true('a vrací dny, ne jen součet', count($itd['interfaces'][0]['days'] ?? []) === 2);
    check_true('nejvytíženější rozhraní je první', ($itd['interfaces'][0]['iface'] ?? '') === 'wan');
    $pdo->exec("DELETE FROM monitor_interface_traffic WHERE monitor_id = 2");

    [$nl_anon_code] = api_get($base, 'action=notification_log&monitor_id=1');
    check('anonym historii notifikací nedostane', $nl_anon_code, 403);
    [$nl_code, $nl] = api_get_auth($base, 'action=notification_log&monitor_id=1&limit=50', $cookie_jar);
    check('notification_log vrací 200', $nl_code, 200);
    check_true('a obě odeslání', count($nl['entries'] ?? []) === 2);
    $nl_fail = null;
    foreach ($nl['entries'] ?? [] as $e) {
        if (($e['channel'] ?? '') === 'discord') $nl_fail = $e;
    }
    check('neúspěch je zaznamenaný jako neúspěch', $nl_fail['ok'] ?? null, false);
    check('i s důvodem', $nl_fail['error'] ?? null, 'HTTP 404');
    $pdo->exec("DELETE FROM notification_log");

    // --- Protokol odchozích zpráv: filtry, stránkování, souhrn -------------
    // Od chvíle, kdy zapisuje send_email(), tu nejsou jen výstrahy, ale i
    // pozvánky, digesty a resety hesla. Bez filtrů a stránkování se v tom
    // nedá hledat a bez souhrnu se jeden neúspěch schová mezi úspěchy.
    // [monitor, druh, kanál, příjemce, ok, chyba, předmět, způsob, stáří v minutách]
    // Od nejstaršího: řadí se podle id, protože podle něj se i stránkuje, a
    // ve skutečném provozu řádky přibývají v čase.
    foreach ([
        [null, 'password_reset', 'email', 'admin@example.com', 1, null, 'Nové heslo', 'smtp', 4320],
        [null, 'daily_reminder', 'email', 'admin@example.com', 1, null, 'Co je rozbité', 'smtp', 1500],
        [null, 'digest', 'email', 'sef@example.com', 0, 'SMTP connect() failed', 'Týdenní přehled', null, 120],
        [null, 'invitation', 'email', 'novy@example.com', 1, null, 'Pozvánka', 'fallback', 30],
        [1, 'alert', 'discord', null, 0, 'HTTP 404', null, null, 10],
        [1, 'alert', 'email', 'admin@example.com', 1, null, 'Monitor je dole', 'smtp', 5],
    ] as $nl_seed) {
        $st = $pdo->prepare("INSERT INTO notification_log
            (monitor_id, status, channel, recipient, ok, error_message, kind, subject, method, created_at)
            VALUES (?, '', ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))");
        $st->execute([$nl_seed[0], $nl_seed[2], $nl_seed[3], $nl_seed[4], $nl_seed[5], $nl_seed[1], $nl_seed[6], $nl_seed[7], $nl_seed[8]]);
    }

    [, $nl_all] = api_get_auth($base, 'action=notification_log&limit=50', $cookie_jar);
    check('bez filtru vrátí celý protokol, ne jen výstrahy', count($nl_all['entries'] ?? []), 6);
    check('nejnovější je první', $nl_all['entries'][0]['subject'] ?? null, 'Monitor je dole');
    check('řádek nese druh zprávy', $nl_all['entries'][0]['kind'] ?? null, 'alert');
    check('i způsob odeslání', $nl_all['entries'][0]['method'] ?? null, 'smtp');
    // array_key_exists, ne ??: ?? by z testovaného nullu udělalo náhradní
    // hodnotu a klíč, který server vůbec neposlal, by prošel jako null.
    check_true('na jedné stránce se kurzor nenabízí', array_key_exists('nextCursor', $nl_all ?: []) && $nl_all['nextCursor'] === null);
    // Nabídka filtrů se bere z celého protokolu - jinak by filtr sebral
    // možnost, která ho zruší.
    check('nabídka druhů je celý protokol', $nl_all['kinds'] ?? [], ['alert', 'daily_reminder', 'digest', 'invitation', 'password_reset']);
    check('nabídka kanálů taky', $nl_all['channels'] ?? [], ['discord', 'email']);

    [, $nl_kind] = api_get_auth($base, 'action=notification_log&kind=alert', $cookie_jar);
    check('filtr na druh vybere jen výstrahy', count($nl_kind['entries'] ?? []), 2);
    check_true('a nabídka zůstane úplná', count($nl_kind['kinds'] ?? []) === 5);
    [, $nl_ch] = api_get_auth($base, 'action=notification_log&channel=discord', $cookie_jar);
    check('filtr na kanál vybere jen ten kanál', count($nl_ch['entries'] ?? []), 1);
    [, $nl_bad] = api_get_auth($base, 'action=notification_log&kind=neexistuje', $cookie_jar);
    check('neznámý druh vrátí prázdno, ne všechno', count($nl_bad['entries'] ?? []), 0);
    [, $nl_fails] = api_get_auth($base, 'action=notification_log&ok=0', $cookie_jar);
    check('ok=0 vybere jen neúspěchy', count($nl_fails['entries'] ?? []), 2);
    check_true('a opravdu jen neúspěchy', !array_filter($nl_fails['entries'] ?? [], fn($e) => $e['ok'] !== false));
    [$nl_ok_code] = api_get_auth($base, 'action=notification_log&ok=mozna', $cookie_jar);
    check('nesmyslné ok je 400, ne tiše širší odpověď', $nl_ok_code, 400);

    [, $nl_q] = api_get_auth($base, 'action=notification_log&q=' . rawurlencode('novy@'), $cookie_jar);
    check('hledání podle příjemce', count($nl_q['entries'] ?? []), 1);
    check('a najde toho správného', $nl_q['entries'][0]['recipient'] ?? null, 'novy@example.com');
    // Podtržítko je v LIKE zástupný znak. Kdyby se neescapovalo, „admin_example"
    // by našlo „admin@example.com" a filtr by se sám rozšířil.
    [, $nl_wild] = api_get_auth($base, 'action=notification_log&q=' . rawurlencode('admin_example'), $cookie_jar);
    check('zástupný znak v hledání se nebere jako zástupný', count($nl_wild['entries'] ?? []), 0);

    // Stránkuje se kurzorem: během čtení přibývají řádky a offset by jeden
    // zopakoval a jiný přeskočil. Stránky proto musí být disjunktní.
    $nl_seen = [];
    $nl_cursor = null;
    $nl_pages = 0;
    do {
        [, $nl_page] = api_get_auth($base, 'action=notification_log&limit=2' . ($nl_cursor !== null ? '&before_id=' . $nl_cursor : ''), $cookie_jar);
        foreach ($nl_page['entries'] ?? [] as $e) {
            $nl_seen[] = (int)$e['id'];
        }
        $nl_cursor = $nl_page['nextCursor'] ?? null;
        $nl_pages++;
    } while ($nl_cursor !== null && $nl_pages < 10);
    check('kurzor projde celý protokol', count($nl_seen), 6);
    check('a žádný řádek nedá dvakrát', count(array_unique($nl_seen)), 6);
    check('po třech stránkách po dvou končí', $nl_pages, 3);
    $nl_desc = $nl_seen;
    rsort($nl_desc);
    check('a chodí od nejnovějšího', $nl_seen, $nl_desc);
    // Karta u monitoru volá pořád to samé volání jako dřív - filtry ji nesmí
    // rozbít.
    [, $nl_mon] = api_get_auth($base, 'action=notification_log&monitor_id=1&limit=50', $cookie_jar);
    check('filtr na monitor bere jen jeho zprávy', count($nl_mon['entries'] ?? []), 2);
    check('a nese jméno monitoru jako dřív', $nl_mon['entries'][0]['monitorName'] ?? null, 'Testovací web');

    // Souhrn se neptá na filtry. Pruh „něco neodešlo" musí říct pravdu i
    // uživateli, který si právě prohlíží jen pozvánky.
    [, $nl_sum] = api_get_auth($base, 'action=notification_log&summary=1', $cookie_jar);
    check('souhrn za 24 h počítá jen posledních 24 h', $nl_sum['summary']['last24h']['total'] ?? null, 4);
    check('a ví, kolik z toho neodešlo', $nl_sum['summary']['last24h']['failed'] ?? null, 2);
    check('souhrn za 7 dní vidí i starší', $nl_sum['summary']['last7d']['total'] ?? null, 6);
    check('a neúspěchy se mu nerozmnožily', $nl_sum['summary']['last7d']['failed'] ?? null, 2);
    $nl_by = [];
    foreach ($nl_sum['summary']['last24h']['byChannel'] ?? [] as $c) {
        $nl_by[$c['channel']] = $c;
    }
    check('souhrn rozpadá 24 h po kanálech', $nl_by['email'] ?? null, ['channel' => 'email', 'total' => 3, 'failed' => 1]);
    check('včetně kanálu, kde selhalo všechno', $nl_by['discord'] ?? null, ['channel' => 'discord', 'total' => 1, 'failed' => 1]);
    [, $nl_sum_f] = api_get_auth($base, 'action=notification_log&summary=1&kind=invitation', $cookie_jar);
    check('filtr zúží tabulku', count($nl_sum_f['entries'] ?? []), 1);
    check('ale souhrn ne - jinak by pruh mlčel kvůli filtru', $nl_sum_f['summary']['last24h']['failed'] ?? null, 2);
    [, $nl_nosum] = api_get_auth($base, 'action=notification_log', $cookie_jar);
    check_true('bez summary=1 se souhrn nepočítá', array_key_exists('summary', $nl_nosum ?: []) && $nl_nosum['summary'] === null);

    // Časové meze se porovnávají v databázi, takže hranice musí přijít taky
    // z ní - PHP testu a MySQL nesdílí časovou zónu.
    $nl_hour_ago = (string)$pdo->query("SELECT DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 60 MINUTE), '%Y-%m-%d %H:%i:%s')")->fetchColumn();
    [, $nl_from] = api_get_auth($base, 'action=notification_log&from=' . rawurlencode($nl_hour_ago), $cookie_jar);
    check('from vybere jen novější', count($nl_from['entries'] ?? []), 3);
    [, $nl_to] = api_get_auth($base, 'action=notification_log&to=' . rawurlencode($nl_hour_ago), $cookie_jar);
    check('to vybere jen starší', count($nl_to['entries'] ?? []), 3);
    [$nl_date_code] = api_get_auth($base, 'action=notification_log&from=vcera', $cookie_jar);
    check('nečitelné datum je 400, ne odpověď bez meze', $nl_date_code, 400);
    // Holé datum v „to" znamená celý den. Kdyby znamenalo půlnoc, tenhle
    // řádek by ve výběru nebyl a filtr by tiše mlžil o tom, co ten den odešlo.
    $pdo->exec("INSERT INTO notification_log (monitor_id, status, channel, recipient, ok, kind, subject, created_at)
                VALUES (NULL, '', 'email', 'historik@example.com', 1, 'admin_notice', 'Starý dopis', '2026-01-15 20:00:00')");
    [, $nl_day] = api_get_auth($base, 'action=notification_log&from=2026-01-15&to=2026-01-15', $cookie_jar);
    check('holé datum v to znamená celý den', count($nl_day['entries'] ?? []), 1);
    check('a je to ten řádek', $nl_day['entries'][0]['recipient'] ?? null, 'historik@example.com');
    $pdo->exec("DELETE FROM notification_log");

    [$tn_code, $tn_res] = api_post($base, 'action=test_notification', ['channel' => 'fax'], $cookie_jar);
    check('test_notification: neznámý kanál je 400', $tn_code, 400);
    $pdo->exec("DELETE FROM settings WHERE key_name IN ('discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id')");
    [$tn_code, $tn_res] = api_post($base, 'action=test_notification', ['channel' => 'discord'], $cookie_jar);
    check('test_notification: bez webhooku odpoví 200', $tn_code, 200);
    check('test_notification: bez webhooku ok=false', $tn_res['ok'] ?? null, false);
    check_true('test_notification: říká, co chybí', str_contains((string)($tn_res['message'] ?? ''), 'Discord'));
    [$tn_code, $tn_res] = api_post($base, 'action=test_notification', ['channel' => 'telegram'], $cookie_jar);
    check('test_notification: telegram bez tokenu ok=false', $tn_res['ok'] ?? null, false);

    check('eskalace se uložila a přečetla', $s['escalation_enabled'] ?? null, '1');
    check('lhůta na převzetí se uložila', $s['escalation_after_mins'] ?? null, '20');
    check('eskalační webhook se uložil', $s['escalation_webhook_url'] ?? null, 'https://discord.com/api/webhooks/test');

    // The heart of it: whatever can be saved must also be readable. If a key
    // dropped out of the read path again, the next save would erase it.
    // (The original canary were the whatsapp_* keys - they turned out dead on
    // 2026-08-17 and were deleted; the canary is now the SMTP plain+secret pair.)
    check('smtp host se vrací zpátky (jinak ho další uložení smaže)', $s['smtp_host'] ?? null, 'smtp.example.com');
    check_true('smtp heslo se vrací maskované, ne prázdné a ne plaintext',
        ($s['smtp_pass'] ?? '') !== '' && ($s['smtp_pass'] ?? '') !== 'TajneHeslo123');

    // The second save sends back what the form loaded - exactly like a user
    // flipping some other option and hitting save.
    api_post($base, 'action=save_settings', ['settings' => $s], $cookie_jar);
    [, $again] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    check('druhé uložení nastavení nesmaže', $again['settings']['smtp_host'] ?? null, 'smtp.example.com');
    // And a masked password sent back must NOT overwrite the real value.
    $smtp_pass_db = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'smtp_pass'")->fetchColumn();
    check('maskovaný secret nepřepsal skutečné heslo', $smtp_pass_db, 'TajneHeslo123');

    // Every key the server accepts must also be returnable. Without this the
    // same bug could be recreated with a different key.
    $missing = array_values(array_diff(bk_settings_keys(), array_keys($s)));
    check('get_settings vrací všechny klíče, které save_settings přijímá', $missing, []);
}

// =======================================================================
// A fresh install followed by one save leaves every alert switch unchanged.
//
// get_settings used to answer '' for a key nobody had saved, the form posted
// that '' back with its first "Save", and a stored '' read as "off": one save
// of an unrelated field silenced every WAN, LTE, disk and firewall alert and
// sent agent alerts to all users instead of admins. Reproduced end to end:
// the rows of a fresh install, the read the form does, the save it sends.
// =======================================================================
if ($logged_in) {
    bk_test_load_functions($root . '/db.php', ['bk_settings_keys', 'bk_settings_defaults', 'bk_settings_boolean_keys']);
    $fi_defaults = bk_settings_defaults();
    $fi_switches = bk_settings_boolean_keys();
    $fi_backup = $pdo->query("SELECT key_name, key_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    $fi_keys = array_keys($fi_defaults);
    $pdo->prepare("DELETE FROM settings WHERE key_name IN (" . implode(',', array_fill(0, count($fi_keys), '?')) . ")")
        ->execute($fi_keys);

    [$code, $fi_read] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    check('čerstvá instalace: get_settings vrací 200', $code, 200);
    $fi_s = $fi_read['settings'] ?? [];
    $fi_shown = array_intersect_key($fi_s, $fi_defaults);
    ksort($fi_shown);
    $fi_expected = $fi_defaults;
    ksort($fi_expected);
    check('nikdy neuložený klíč ukáže svou výchozí hodnotu, ne prázdno', $fi_shown, $fi_expected);

    // One save of exactly what the form loaded.
    [$code] = api_post($base, 'action=save_settings', ['settings' => $fi_s], $cookie_jar);
    check('první uložení čerstvé instalace projde', $code, 200);
    $fi_rows = $pdo->query("SELECT key_name, key_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $fi_stored = [];
    foreach ($fi_switches as $fi_key) {
        $fi_stored[$fi_key] = $fi_rows[$fi_key] ?? null;
    }
    check(
        'po jednom uložení zůstal každý přepínač upozornění na výchozí hodnotě',
        $fi_stored,
        array_intersect_key($fi_defaults, array_flip($fi_switches))
    );
    [, $fi_again] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    $fi_after = array_intersect_key($fi_again['settings'] ?? [], $fi_defaults);
    ksort($fi_after);
    check('a čte se stejně jako před uložením', $fi_after, $fi_shown);

    // A production row already damaged by the old form: '' reads as the
    // default, so the alerts come back on the deploy, not on the next save.
    $pdo->exec("REPLACE INTO settings (key_name, key_value) VALUES ('agent_notifications_enabled', ''), ('agent_notify_admin_only', '')");
    [, $fi_legacy] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    check('uložené prázdné upozornění z agentů se čte jako zapnuté', $fi_legacy['settings']['agent_notifications_enabled'] ?? null, '1');
    check('uložené prázdné "jen adminům" se čte jako zapnuté', $fi_legacy['settings']['agent_notify_admin_only'] ?? null, '1');

    // An empty switch is refused as a whole: nothing of that save is written.
    $fi_host_before = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'smtp_host'")->fetchColumn();
    [$code, $fi_bad] = api_post($base, 'action=save_settings', [
        'settings' => ['agent_notifications_enabled' => '', 'smtp_host' => 'smtp.nesmi-se-ulozit.example'],
    ], $cookie_jar);
    check('prázdný přepínač uložení odmítne (400)', $code, 400);
    check('a odpověď jmenuje ten klíč', $fi_bad['invalidKeys'] ?? null, ['agent_notifications_enabled']);
    check(
        'a z odmítnutého uložení se nezapsalo nic',
        $pdo->query("SELECT key_value FROM settings WHERE key_name = 'smtp_host'")->fetchColumn(),
        $fi_host_before
    );
    [$code] = api_post($base, 'action=save_settings', ['settings' => ['escalation_enabled' => 'ano']], $cookie_jar);
    check('přepínač s jinou hodnotou než 0/1 je taky 400', $code, 400);
    [$code] = api_post($base, 'action=save_settings', ['settings' => ['agent_notifications_enabled' => '0']], $cookie_jar);
    check('vědomé vypnutí (0) se uložit dá', $code, 200);
    check(
        'a opravdu se uložilo',
        $pdo->query("SELECT key_value FROM settings WHERE key_name = 'agent_notifications_enabled'")->fetchColumn(),
        '0'
    );

    // The rest of the suite runs on the settings it had before this block.
    $pdo->exec("DELETE FROM settings");
    $fi_restore = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?)");
    foreach ($fi_backup as $fi_key => $fi_val) {
        $fi_restore->execute([$fi_key, $fi_val]);
    }
}

// =======================================================================
// The digest in the recipient's language - the admin.php preview renders with
// the same template as the real e-mail. The EN version must not leak a single
// Czech template string; Czech data (monitor names from the seeds) is removed
// before the check, because it rightfully stays untranslated.
// =======================================================================
if (isset($cookie_jar)) {
    // admin.php has an install gate: a logged-in admin without setup_completed
    // gets the wizard-completion page instead of content. The test DB never ran
    // the wizard, so the flag is set directly.
    $pdo->exec("INSERT INTO settings (key_name, key_value) VALUES ('setup_completed', '1') ON DUPLICATE KEY UPDATE key_value = '1'");
    $digest_preview = function (string $lang) use ($pdo, $base, $cookie_jar): string {
        $pdo->exec("UPDATE users SET email_lang = " . ($lang === '' ? 'NULL' : "'{$lang}'") . " WHERE username = 'admin'");
        $ch = curl_init($base . '/admin.php?action=preview_weekly_digest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookie_jar,
            CURLOPT_COOKIEFILE => $cookie_jar,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = (string)curl_exec($ch);
        curl_close($ch);
        return $body;
    };

    $digest_en = $digest_preview('en');
    check_true('EN digest se vyrenderoval', str_contains($digest_en, 'Weekly Report'));
    // DB data stays in its original language - only the template translates.
    $digest_en_clean = str_replace(['Testovací web', 'Router bez metrik', 'Síť', 'Weby'], '', $digest_en);
    $digest_leaks = [];
    if (preg_match_all('/[^\s>]*[ěščřžýáíéúůťďňóĚŠČŘŽÝÁÍÉÚŮŤĎŇ][^\s<]*/u', $digest_en_clean, $m)) {
        $digest_leaks = array_slice(array_unique($m[0]), 0, 5);
    }
    check('EN digest nepropouští české řetězce šablony', $digest_leaks, []);

    $digest_cs = $digest_preview('cs');
    check_true('CS digest je česky', str_contains($digest_cs, 'Týdenní report'));

    // Cleanup: the admin back to the global language.
    $digest_preview('');
}

// --- Admin role of account 1 across a schema bump -------------------------
// Every schema bump used to run "UPDATE users SET role = 'admin' WHERE id = 1",
// giving full rights back to an account an admin had deliberately demoted.
$role_of = function (int $uid) use ($pdo): ?string {
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $st->execute([$uid]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
};
$force_schema_bump = function () use ($pdo): void {
    $pdo->exec("INSERT INTO settings (key_name, key_value) VALUES ('schema_version', 'test-old') ON DUPLICATE KEY UPDATE key_value = 'test-old'");
};
$pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES ('second_admin', 'second_admin@example.com', ?, 'admin')")
    ->execute([password_hash('DruheHeslo123!', PASSWORD_DEFAULT)]);
$second_admin_id = (int)$pdo->lastInsertId();
$role1_before = $role_of(1);
$pdo->exec("UPDATE users SET role = 'user' WHERE id = 1");
$force_schema_bump();
api_get($base, 'action=public_status');
check_true('migrace skutečně proběhla', $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'")->fetchColumn() !== 'test-old');
check('degradovaný účet 1 zůstane uživatelem, když jiný admin existuje', $role_of(1), 'user');
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$second_admin_id]);
$force_schema_bump();
api_get($base, 'action=public_status');
check('bez jediného admina migrace účtu 1 roli admin vrátí', $role_of(1), 'admin');
$pdo->prepare("UPDATE users SET role = ? WHERE id = 1")->execute([$role1_before ?? 'admin']);

// --- Ingest of an 0.1.7 report (fixture: the user's Turris Omnia) -------------
//
// The payload of a real router goes in and what comes out is checked column by
// column: the sanitizers, the band totals and the step metrics all sit on this
// one path, and a value that quietly became 0 or a bound would look exactly
// like a measurement on the page.
$omnia_fx = require __DIR__ . '/fixtures/omnia_router.php';
$omnia_pl = $omnia_fx['payload'];
$omnia_before_id = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM vps_metrics")->fetchColumn();
$omnia_details = function () use ($pdo): array {
    return json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
};
$omnia_metrics = function (string $cols) use ($pdo): array {
    return $pdo->query("SELECT {$cols} FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetch() ?: [];
};
$omnia_num = fn ($v) => $v === null ? null : (float)$v;
// `?? ` cannot tell a key that is missing from one that is honestly null.
$omnia_at = fn ($a, string $k) => is_array($a) && array_key_exists($k, $a) ? $a[$k] : 'chybí';

// The router's clock is read as the distance from ours, so the fixture's own
// moment (2026-09-16) would be stored as a five-day skew.
check('Omnia 0.1.7: hlášení z fixture agent přijme', $post_agent(array_merge($omnia_pl, ['agent_time' => time()])), 200);
$om = $omnia_metrics('wifi_noise_24g, wifi_noise_5g, wifi_noise_6g, wifi_busy_24g, wifi_busy_5g, wifi_busy_6g, wifi_busy_other_24g, wifi_busy_other_5g, wifi_busy_other_6g, wifi_weak_clients, wifi_wpa2_clients, wifi_6e_unserved, wifi_5g_capable_24g');
check('Omnia: šum a vytížení kanálu na 5 GHz', [$omnia_num($om['wifi_noise_5g']), $omnia_num($om['wifi_busy_5g']), $omnia_num($om['wifi_busy_other_5g'])], [-92.0, 3.5, 1.2]);
check('Omnia: slabý klient jeden, WPA2 klientů opravdu nula', [$omnia_num($om['wifi_weak_clients']), $omnia_num($om['wifi_wpa2_clients'])], [1.0, 0.0]);
check('Omnia: dva klienti s 6 GHz bez 6GHz rádia = 1', $omnia_num($om['wifi_6e_unserved']), 1.0);
check('Omnia: pásma bez rádia zůstanou NULL, ne nula', [
    $om['wifi_noise_24g'], $om['wifi_noise_6g'], $om['wifi_busy_24g'], $om['wifi_busy_6g'],
    $om['wifi_busy_other_24g'], $om['wifi_busy_other_6g'], $om['wifi_5g_capable_24g'],
], [null, null, null, null, null, null, null]);
$om_wan = $omnia_metrics('wan_link_mbit, cpu_core_max, cpu_core_max_softirq, wan_rx_mbps, wan_tx_mbps, wan_errors, wan_drops, wan_ring_drops, wan_link_flaps, conntrack_drops, agent_run_ms, clock_skew_s, conntrack_pct');
check('Omnia: rychlost linky WAN se uloží', $omnia_num($om_wan['wan_link_mbit']), 2500.0);
check('Omnia: nezměřené CPU jádro, rychlosti WAN a doba běhu zůstanou NULL', [
    $om_wan['cpu_core_max'], $om_wan['cpu_core_max_softirq'], $om_wan['wan_rx_mbps'],
    $om_wan['wan_tx_mbps'], $om_wan['agent_run_ms'], $om_wan['conntrack_pct'],
], [null, null, null, null, null, null]);
check('Omnia: první hlášení nemá s čím porovnat, kroky jsou NULL', [
    $om_wan['wan_errors'], $om_wan['wan_drops'], $om_wan['wan_ring_drops'],
    $om_wan['wan_link_flaps'], $om_wan['conntrack_drops'],
], [null, null, null, null, null]);
check_true('Omnia: odchylka hodin je změřená a malá', ($om_wan['clock_skew_s'] ?? null) !== null && (int)$om_wan['clock_skew_s'] <= 5);

$omd = $omnia_details();
check('Omnia: rádio dostane odvozenou generaci a šířku', [$omd['wifi_radios'][0]['generation'] ?? null, $omd['wifi_radios'][0]['width_mhz'] ?? null], [6, 80]);
check('Omnia: nástroje routeru jsou striktní booly', [
    $omd['agent_tools']['librespeed_cli'] ?? 'chybí', $omd['agent_tools']['ethtool'] ?? 'chybí',
    $omd['agent_tools']['tc'] ?? 'chybí', $omd['agent_tools']['pkg_manager'] ?? 'chybí',
], [true, false, false, 'opkg']);
check('Omnia: cesta WAN si nese conduit a strop portů LAN', [
    $omd['wan_path']['lan_port_cap_mbit'] ?? null, $omd['wan_path']['lan_conduits'][0]['dev'] ?? null,
    $omd['wan_path']['lan_conduits'][0]['mbit'] ?? null, $omnia_at($omd['wan_path'] ?? null, 'wan_rx_ring_drops'),
], [1000, 'eth1', 1000, null]);
// The wired switch (0.1.8). Five ports, two without a cable: their rate and
// duplex must arrive as null - a dead socket showing "1000 Mbit/s" would be
// read as a working one. lan1 at 100 is the partner's limit, not a fault.
$omd_lan = is_array($omd['lan_ports'] ?? null) ? $omd['lan_ports'] : [];
check('Omnia: přepínač dorazí port po portu, bez kabelu bez rychlosti', [
    array_column($omd_lan['ports'] ?? [], 'name'), array_column($omd_lan['ports'] ?? [], 'link'),
    array_column($omd_lan['ports'] ?? [], 'speed_mbit'),
], [['lan0', 'lan1', 'lan2', 'lan3', 'lan4'], [true, true, false, false, true], [1000, 100, null, null, 1000]]);
check('Omnia: 100 Mbit na lan1 je strop protistrany, port sám umí 1000', [
    $omd_lan['ports'][1]['partner_max_mbit'] ?? null, $omd_lan['ports'][1]['max_mbit'] ?? null,
    $omnia_at($omd_lan['ports'][2] ?? null, 'partner_max_mbit'),
], [100, 1000, null]);
check('Omnia: jedno gigabitové vedení ke CPU a nezměřené počty zůstanou null', [
    $omd_lan['conduits'] ?? null, $omd_lan['bridge'] ?? null,
    $omnia_at($omd_lan, 'clients_total'), $omnia_at($omd_lan['ports'][0] ?? null, 'clients'),
    $omd_lan['ports'][3]['clients'] ?? 'chybí',
], [[['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full']], 'br-lan', null, null, 0]);
// A router that could not look must say so, and the section must not be kept
// from an older report - the cable picture goes stale within a minute.
check('Omnia: router, který se nemohl podívat, hlásí null místo starého obrázku', [
    $post_agent(array_merge($omnia_pl, ['agent_time' => time(), 'lan_ports' => null])),
    $omnia_at($omnia_details(), 'lan_ports'),
], [200, null]);
$omnia_lan_dirty = array_merge($omnia_pl, ['agent_time' => time()]);
$omnia_lan_dirty['lan_ports']['ports'][0]['mac'] = 'aa:bb:cc:dd:ee:ff';
$omnia_lan_dirty['lan_ports']['ports'][0]['clients'] = -3;
$omnia_lan_dirty['lan_ports']['hostnames'] = ['notebook'];
check('Omnia: hlášení s MAC a jménem stanice u portu agent přijme', $post_agent($omnia_lan_dirty), 200);
$omd_lan2 = $omnia_details()['lan_ports'] ?? [];
check('MAC, jména stanic ani záporný počet se k portu nedostanou', [
    array_keys($omd_lan2['ports'][0] ?? []), array_key_exists('hostnames', $omd_lan2),
    $omnia_at($omd_lan2['ports'][0] ?? null, 'clients'),
], [['name', 'link', 'speed_mbit', 'duplex', 'max_mbit', 'partner_max_mbit', 'clients'], false, null]);
check_false('a ani hodnota té MAC nikde v details není',
    str_contains((string)json_encode($omnia_details()), 'aa:bb:cc'));
// It reaches the app on the router detail path that already exists - the
// `details` object of action=monitors - so there is no new endpoint to guard.
// The public view is an allow-list, and the household's wiring is not on it.
check('Omnia: čisté hlášení se uloží zpět', $post_agent(array_merge($omnia_pl, ['agent_time' => time()])), 200);
[, $lan_app] = api_get_auth($base, 'action=monitors', $cookie_jar);
$lan_app_router = null;
foreach ($lan_app['monitors'] ?? [] as $m) {
    if ((int)$m['id'] === 2) { $lan_app_router = $m; }
}
check('aplikace dostane přepínač stávající cestou detailu routeru, bez nového endpointu', [
    array_column($lan_app_router['details']['lan_ports']['ports'] ?? [], 'name'),
    $lan_app_router['details']['lan_ports']['conduits'][0]['speed_mbit'] ?? null,
], [['lan0', 'lan1', 'lan2', 'lan3', 'lan4'], 1000]);
[, $lan_anon, $lan_anon_raw] = api_get($base, 'action=monitors');
$lan_anon_router = null;
foreach ($lan_anon['monitors'] ?? [] as $m) {
    if ((int)$m['id'] === 2) { $lan_anon_router = $m; }
}
check_false('anonym zapojení kabelů v domácnosti nevidí',
    array_key_exists('lan_ports', $lan_anon_router['details'] ?? []) || str_contains($lan_anon_raw, 'lan_ports'));
check('Omnia: seznam disků se uloží i s časem odběru a klíčem', [
    count($omd['storage_disks'] ?? []), strlen((string)($omd['storage_disks'][0]['key'] ?? '')),
    isset($omd['storage_disks_at']), $omnia_at($omd['storage_disks'][0] ?? null, 'emmc'),
], [1, 16, true, null]);
check('Omnia: čisté hlášení nehlásí žádnou ztrátu dat', [$omnia_at($omd, 'details_dropped'), $omnia_at($omd, 'ingest_issues')], [[], []]);

// G24: co router nepozná, dojde jako null a null také zůstane. Agent 0.1.7
// posílá null, když žádnou konfiguraci SQM nenašel ani resolver nerozpoznal;
// dřív se z toho na serveru stala tvrzení „shaper vypnutý" a „Dnsmasq".
$omnia_unknown = array_merge($omnia_pl, ['agent_time' => time(),
    'sqm_enabled' => null, 'dns_engine' => null, 'dns_encryption' => null,
    'dns_servers' => null, 'wan_reconnect_count' => null]);
check('G24: hlášení, kde router pět věcí nepozná, agent přijme', $post_agent($omnia_unknown), 200);
$omd_unknown = $omnia_details();
check('G24: neznámý stav SQM a resolveru zůstane null, ne tvrzení', [
    $omnia_at($omd_unknown, 'sqm_enabled'), $omnia_at($omd_unknown, 'dns_engine'),
    $omnia_at($omd_unknown, 'dns_encryption'), $omnia_at($omd_unknown, 'dns_servers'),
], [null, null, null, null]);
// Starší agent posílá booly dál a nic se mu nemění.
check('G24: agent, který SQM zná, hlásí dál true/false', [
    $post_agent(array_merge($omnia_pl, ['agent_time' => time(), 'sqm_enabled' => false])),
    $omnia_at($omnia_details(), 'sqm_enabled'),
], [200, false]);

// --- Agent 0.1.9: CPU time of the previous run, runs the takeover stopped -----
//
// agent_prev_cpu_ms is a stored metric (the fleet's measured cost per release),
// runs_skipped_killed a counter in last_details for the reports_missing issue.
// An agent up to 0.1.8 sends neither key, and "not measured" must stay null:
// a 0 would read as a free run and as "nothing was killed".
$cpu_col = function () use ($omnia_metrics) {
    $row = $omnia_metrics('agent_prev_cpu_ms');
    if (!array_key_exists('agent_prev_cpu_ms', $row)) {
        return 'chybí';
    }
    return $row['agent_prev_cpu_ms'] === null ? null : (int)$row['agent_prev_cpu_ms'];
};
$cpu_seen = function () use ($cpu_col, $omnia_details, $omnia_at): array {
    $d = $omnia_details();
    return [$cpu_col(), $omnia_at($d, 'agent_prev_cpu_ms'), $omnia_at($d, 'runs_skipped_killed')];
};
$omnia_018 = array_merge($omnia_pl, ['agent_time' => time()]);
unset($omnia_018['agent_prev_cpu_ms'], $omnia_018['runs_skipped_killed']);
check('0.1.9: hlášení agenta 0.1.8 bez nových klíčů agent přijme', $post_agent($omnia_018), 200);
check('0.1.9: agent 0.1.8 CPU čas ani ukončené běhy neměří - null ve sloupci i v details, ne nula',
    $cpu_seen(), [null, null, null]);
$omnia_019 = array_merge($omnia_pl, ['agent_time' => time()]);
check('0.1.9: hlášení s CPU časem a ukončenými běhy agent přijme',
    $post_agent(array_merge($omnia_019, ['agent_prev_cpu_ms' => 190, 'runs_skipped_killed' => 2])), 200);
check('0.1.9: CPU čas předchozího běhu se uloží jako metrika i do details, ukončené běhy do details',
    $cpu_seen(), [190, 190, 2]);
check('0.1.9: skutečná nula je měření, ne neznámo',
    [$post_agent(array_merge($omnia_019, ['agent_prev_cpu_ms' => 0, 'runs_skipped_killed' => 0])), $cpu_seen()],
    [200, [0, 0, 0]]);
check('0.1.9: horní meze (600 s CPU, 100 000 běhů) ještě platí',
    [$post_agent(array_merge($omnia_019, ['agent_prev_cpu_ms' => 600000, 'runs_skipped_killed' => 100000])), $cpu_seen()],
    [200, [600000, 600000, 100000]]);
// Out of range is dropped, never clamped: a clamped 600000 would read as a
// real ten-minute run. The pass-through must not store the raw value either.
foreach ([
    'nad mezí' => [600001, 100001],
    'záporné' => [-10, -1],
    'text' => ['abc', 'dva'],
    'desetinné' => [190.5, 2.5],
    'bool' => [true, true],
    'pole' => [[190], [2]],
] as $cpu_case => [$cpu_bad, $killed_bad]) {
    check("0.1.9: {$cpu_case} CPU čas a počet ukončených běhů se zahodí na null",
        [$post_agent(array_merge($omnia_019, ['agent_prev_cpu_ms' => $cpu_bad, 'runs_skipped_killed' => $killed_bad])), $cpu_seen()],
        [200, [null, null, null]]);
}
check('0.1.9: celé číslo poslané jako text se přečte',
    [$post_agent(array_merge($omnia_019, ['agent_prev_cpu_ms' => '260', 'runs_skipped_killed' => '1'])), $cpu_seen()],
    [200, [260, 260, 1]]);
// The app reads the series under the same key; unmeasured minutes are gaps,
// not zeros (the only 0 in it is the one the agent really measured).
[$cpu_s_code, $cpu_s] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=agent_prev_cpu_ms&period=1h', $cookie_jar);
// Reports of one second tie on checked_at, so the values are compared sorted.
$cpu_s_vals = array_map(fn ($p) => (float)$p[1], $cpu_s['points'] ?? []);
sort($cpu_s_vals);
check('0.1.9: řada agent_prev_cpu_ms vrací jen naměřené body v ms', [$cpu_s_code, $cpu_s['unit'] ?? null, $cpu_s_vals],
    [200, 'ms', [0.0, 190.0, 260.0, 600000.0]]);

// Identifiers injected at three levels: the allow-lists must drop every one of
// them, whatever the agent calls itself. Nothing here may reach last_details.
$omnia_dirty = array_merge($omnia_pl, ['agent_time' => time()]);
$omnia_dirty['wifi_radios'][0]['bssid'] = 'aa:bb:cc:dd:ee:ff';
$omnia_dirty['wifi_radios'][0]['hwmodes'] = ['a', 'n', 'ac'];
$omnia_dirty['storage_disks'][0]['serial_number'] = 'SN50026B7683';
$omnia_dirty['storage_disks'][0]['smart']['wwn'] = '0x50026b7683';
$omnia_dirty['storage_disks'][0]['partitions'][0]['eui64'] = 'ABCDEF0123456789';
check('Omnia: hlášení s vloženými identifikátory agent přijme', $post_agent($omnia_dirty), 200);
$omd = $omnia_details();
check('vložený sériový klíč, WWN ani BSSID se do details nedostanou',
    preg_grep('/serial|wwn|eui|guid|cid|bssid|hwmodes/i', array_keys(array_merge(
        $omd['wifi_radios'][0] ?? [], $omd['storage_disks'][0] ?? [],
        $omd['storage_disks'][0]['smart'] ?? [], $omd['storage_disks'][0]['partitions'][0] ?? []
    ))), []);
check_true('a ani jejich hodnota nikde v details není',
    !str_contains((string)json_encode($omd), '50026b7683') && !str_contains(strtolower((string)json_encode($omd)), 'aa:bb:cc'));

// --- Step metrics of the WAN port: what grew since the previous report --------
//
// The stored series is the STEP, never the lifetime total: a counter that shows
// 4 000 errors since boot says nothing about this minute, and a reboot would
// otherwise book its whole bring-up as one minute's spike.
$omnia_counters = function (array $extra) use ($post_agent, $omnia_pl): int {
    return $post_agent(array_merge($omnia_pl, ['agent_time' => time()], $extra));
};
$wan_cols = 'wan_errors, wan_drops, wan_ring_drops, wan_link_flaps, conntrack_drops';
$wan_base = ['uptime' => 100000, 'wan_link_dev' => 'eth2', 'wan_rx_errors' => 10, 'wan_tx_errors' => 5,
    'wan_rx_dropped' => 7, 'wan_tx_dropped' => 3, 'conntrack_drop' => 2, 'wan_carrier_down_count' => 1];
check('WAN kroky: základní hlášení agent přijme', $omnia_counters($wan_base), 200);
check('WAN kroky: proti čemu porovnat se teprve uložilo', $omnia_metrics($wan_cols),
    ['wan_errors' => null, 'wan_drops' => null, 'wan_ring_drops' => null, 'wan_link_flaps' => null, 'conntrack_drops' => null]);
check('WAN kroky: další hlášení s vyššími čítači agent přijme', $omnia_counters(array_merge($wan_base, [
    'uptime' => 100060, 'wan_rx_errors' => 14, 'wan_tx_errors' => 5, 'wan_rx_dropped' => 9, 'wan_tx_dropped' => 3,
    'conntrack_drop' => 5, 'wan_carrier_down_count' => 3,
])), 200);
check('WAN kroky: uloží se přírůstek, ne celkový součet', $omnia_metrics($wan_cols),
    ['wan_errors' => 4, 'wan_drops' => 2, 'wan_ring_drops' => null, 'wan_link_flaps' => 2, 'conntrack_drops' => 3]);
check('WAN kroky: po restartu (nižší uptime) hlášení agent přijme', $omnia_counters(array_merge($wan_base, [
    'uptime' => 120, 'wan_rx_errors' => 20, 'wan_tx_errors' => 5, 'wan_rx_dropped' => 12, 'wan_tx_dropped' => 3,
    'conntrack_drop' => 8, 'wan_carrier_down_count' => 4,
])), 200);
check('WAN kroky: restart nevyrobí špičku, kroky jsou NULL', $omnia_metrics($wan_cols),
    ['wan_errors' => null, 'wan_drops' => null, 'wan_ring_drops' => null, 'wan_link_flaps' => null, 'conntrack_drops' => null]);
check('WAN kroky: hlášení z jiného portu WAN agent přijme', $omnia_counters(array_merge($wan_base, [
    'uptime' => 180, 'wan_link_dev' => 'eth0', 'wan_rx_errors' => 25, 'wan_tx_errors' => 5,
    'wan_rx_dropped' => 14, 'wan_tx_dropped' => 3, 'conntrack_drop' => 9, 'wan_carrier_down_count' => 5,
])), 200);
check('WAN kroky: cizí port není krok toho starého', $omnia_metrics($wan_cols),
    ['wan_errors' => null, 'wan_drops' => null, 'wan_ring_drops' => null, 'wan_link_flaps' => null, 'conntrack_drops' => null]);

// Ring drops come from ethtool once an hour: their step belongs to the report
// that carries a NEW wan_path.checked_at, every other minute is null.
$wan_path_ring = function (int $checked_at, ?int $ring) use ($omnia_pl): array {
    $path = $omnia_pl['wan_path'];
    $path['checked_at'] = $checked_at;
    $path['wan_rx_ring_drops'] = $ring;
    return $path;
};
$ring_base = array_merge($wan_base, ['uptime' => 200000, 'wan_link_dev' => 'eth2']);
check('prstenec: první hodinové čtení agent přijme', $omnia_counters(array_merge($ring_base, ['wan_path' => $wan_path_ring(1789600000, 40)])), 200);
check('prstenec: druhé čtení s novým časem agent přijme', $omnia_counters(array_merge($ring_base, [
    'uptime' => 200060, 'wan_path' => $wan_path_ring(1789603600, 46),
])), 200);
check('prstenec: krok se zapíše na hlášení s novým hodinovým čtením', $omnia_metrics('wan_ring_drops'), ['wan_ring_drops' => 6]);
check('prstenec: minuta beze změny času agent přijme', $omnia_counters(array_merge($ring_base, [
    'uptime' => 200120, 'wan_path' => $wan_path_ring(1789603600, 46),
])), 200);
check('prstenec: minuta beze změny času nemá krok', $omnia_metrics('wan_ring_drops'), ['wan_ring_drops' => null]);

// --- Nothing is dropped silently (details_dropped, ingest_issues) -------------
//
// last_details is one 64 KB column. A report that does not fit used to shed its
// largest lists with nothing but an error_log line, so a router whose disk list
// was dropped looked exactly like a router without disks.
$omnia_now = fn (array $extra = []) => array_merge($omnia_pl, ['agent_time' => time()], $extra);
// Each list is under the pass-through's own 8 KB cap, so they really are
// stored - and together they push the blob over the column.
$omnia_pad = [];
foreach (range(1, 9) as $pad_n) {
    $omnia_pad["pad_{$pad_n}"] = array_fill(0, 70, str_repeat('x', 100 + 9 - $pad_n));
}
check('přetečení: hlášení s devíti seznamy agent přijme', $post_agent($omnia_now(array_merge(['uptime' => 300000], $omnia_pad))), 200);
$omd = $omnia_details();
$omnia_shed = $omd['details_dropped'] ?? [];
check_true('přetečení: zahozené klíče jsou pojmenované a jdou od největšího',
    $omnia_shed !== [] && $omnia_shed[0] === 'pad_1' && preg_grep('/^pad_\d$/', $omnia_shed) === $omnia_shed);
check_true('přetečení: details se vejdou a malé klíče přežijí',
    isset($omd['wifi_radios'], $omd['storage_disks'], $omd['ingest_issues'], $omd['wan_counters_prev'])
    && strlen((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn()) <= 60000);
check('přetečení: další hlášení, které se vejde, agent přijme', $post_agent($omnia_now(['uptime' => 300060])), 200);
check('přetečení: seznam zahozených klíčů se vyprázdní sám', $omnia_details()['details_dropped'] ?? null, []);

// The pass-through has its own limits (8 KB per key, 64 new keys). What it
// refuses is named in ingest_issues instead of vanishing.
check('příjem: hlášení s přerostlým klíčem agent přijme', $post_agent($omnia_now([
    'uptime' => 300120, 'obri_klic' => str_repeat('y', 9000),
])), 200);
$omd = $omnia_details();
check('příjem: přerostlý klíč se nezapíše a je vidět v ingest_issues',
    [array_key_exists('obri_klic', $omd), $omd['ingest_issues'][0]['type'] ?? null, $omd['ingest_issues'][0]['key'] ?? null, $omd['ingest_issues'][0]['bytes'] ?? null],
    [false, 'passthrough_too_large', 'obri_klic', 9000]);
check('příjem: čisté hlášení seznam problémů vynuluje', [$post_agent($omnia_now(['uptime' => 300180])), $omnia_details()['ingest_issues'] ?? null], [200, []]);

// X15: a light run that stepped aside for the router's own speed test measured
// almost nothing ON PURPOSE. It is no evidence of loss, so it neither raises
// the two records nor clears what the last full report put there.
check('zkrácené hlášení: nejdřív přetečení agent přijme', $post_agent($omnia_now(array_merge(['uptime' => 300240], $omnia_pad))), 200);
$omnia_shed = $omnia_details()['details_dropped'] ?? [];
check_true('zkrácené hlášení: předtím je co ztratit', $omnia_shed !== []);
check('zkrácené hlášení agent přijme', $post_agent([
    'agent_type' => 'openwrt', 'version' => '0.1.7', 'agent_time' => time(),
    'uptime' => 300300, 'reduced' => 'wan_probe', 'speedtest_active' => true,
]), 200);
$omd = $omnia_details();
check('zkrácené hlášení ztrátu nevyvolá ani nesmaže', [$omd['details_dropped'] ?? null, $omd['reduced'] ?? null], [$omnia_shed, 'wan_probe']);
check('po zkráceném hlášení plné hlášení seznam uklidí', [$post_agent($omnia_now(['uptime' => 300360])), $omnia_details()['details_dropped'] ?? null], [200, []]);

// --- An 0.1.6 report must not erase what 0.1.7 measured -----------------------
// The old agent sends no storage_disks at all. Treating "absent" as "no disks"
// would wipe the list the router reported a minute ago.
check('agent 0.1.6 (bez seznamu disků) hlášení pošle', $post_agent([
    'agent_type' => 'openwrt', 'version' => '0.1.6', 'uptime' => 300420,
    'wifi_radios' => [['radio' => 'phy0-ap0', 'band' => '5GHz', 'channel' => 36, 'clients' => 4]],
]), 200);
$omd = $omnia_details();
check('agent 0.1.6 seznam disků nepřepíše', [count($omd['storage_disks'] ?? []), $omd['storage_disks'][0]['name'] ?? null], [1, 'sda']);
check('agent 0.1.6 nemá nástroje ani cestu WAN, a null se nepředstírá',
    [$omnia_at($omd, 'agent_tools'), $omnia_at($omd, 'wan_path')], [null, null]);

// --- The new series reach the charts -----------------------------------------
[, $om_batch] = api_get_auth($base, 'action=metric_series_batch&monitor_id=2&period=24h', $cookie_jar);
check_true('graf šumu na 5 GHz je mezi grafy routeru', isset($om_batch['series']['wifi_noise_5g']));
[$om_s_code, $om_s] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=wifi_busy_5g&period=24h', $cookie_jar);
check('metric_series zná vytížení kanálu na 5 GHz', $om_s_code, 200);
check_true('a vrátí změřenou hodnotu 3,5 %', in_array(3.5, array_map(fn ($p) => (float)$p[1], $om_s['points'] ?? []), true));
[$om_e_code, $om_e] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=wan_errors&period=24h', $cookie_jar);
check('metric_series zná krokovou metriku chyb na WAN', $om_e_code, 200);
check_true('a krok 4 chyb je v ní vidět', in_array(4.0, array_map(fn ($p) => (float)$p[1], $om_e['points'] ?? []), true));
$pdo->prepare("DELETE FROM vps_metrics WHERE monitor_id = 2 AND id > ?")->execute([$omnia_before_id]);

// --- A step metric is summed, never averaged ---------------------------------
//
// The 90-day view reads metrics_daily, where a step metric's day is
// avg_val x samples. Drawing avg_val instead would show a day with 2 880
// errors as "2 errors" and the chart would look healthy.
$pdo->prepare("DELETE FROM metrics_daily WHERE monitor_id = 2 AND metric_key IN ('wan_errors', 'cpu_core_max')")->execute();
$pdo->prepare("INSERT INTO metrics_daily (monitor_id, day, metric_key, min_val, avg_val, max_val, samples) VALUES (2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'wan_errors', 0, 2, 40, 1440)")->execute();
$pdo->prepare("INSERT INTO metrics_daily (monitor_id, day, metric_key, min_val, avg_val, max_val, samples) VALUES (2, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'cpu_core_max', 3, 12.5, 97, 1440)")->execute();
[$step_code, $step_s] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=wan_errors&period=90d', $cookie_jar);
check('90 dní: kroková metrika se načte', $step_code, 200);
check('90 dní: den kroků je součet, ne průměr (2 × 1440)', array_map(fn ($p) => (float)$p[1], $step_s['points'] ?? []), [2880.0]);
[, $avg_s] = api_get_auth($base, 'action=metric_series&monitor_id=2&metric=cpu_core_max&period=90d', $cookie_jar);
check('90 dní: běžná metrika zůstává průměrem', array_map(fn ($p) => (float)$p[1], $avg_s['points'] ?? []), [12.5]);
[$step_d_code, $step_d] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=wan_errors', $cookie_jar);
check('detail metriky přizná, že jde o krok', [$step_d_code, $step_d['metric']['step'] ?? null, $step_d['metric']['counter'] ?? null], [200, true, false]);
[, $avg_d] = api_get_auth($base, 'action=metric_detail&monitor_id=2&metric=cpu_core_max', $cookie_jar);
check('a u běžné metriky ne', $avg_d['metric']['step'] ?? null, false);
$pdo->prepare("DELETE FROM metrics_daily WHERE monitor_id = 2 AND metric_key IN ('wan_errors', 'cpu_core_max')")->execute();


// --- Disk tables: what one hourly SMART reading writes (CORE 3.4) -------------
//
// last_details keeps only the LATEST reading, so without these two tables
// "did the bad blocks grow since spring" has no answer at all. The reading is
// hourly, which means every gate here has to be proved on real reports: a
// report replayed twice must not become two samples, and a router whose disk
// list did not fit must not get one upsert per disk per minute.
$st_wipe = function () use ($pdo): void {
    $pdo->prepare("DELETE FROM storage_disks WHERE monitor_id = 2")->execute();
    $st_d = json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
    unset($st_d['storage_sample_at'], $st_d['storage_disks'], $st_d['storage_disks_at'], $st_d['fs_alerts']);
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode($st_d)]);
};
// Opens the hourly gate without waiting an hour - and ONLY the gate, so what
// the database decides about freshness stays visible.
$st_force = function () use ($pdo): void {
    $st_d = json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
    unset($st_d['storage_sample_at']);
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode($st_d)]);
};
$st_strip_list = function () use ($pdo): void {
    $st_d = json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
    unset($st_d['storage_disks']);
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode($st_d)]);
};
$st_disk = fn (): array => $pdo->query("SELECT * FROM storage_disks WHERE monitor_id = 2 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$st_day = fn (): array => $pdo->query("SELECT d.* FROM storage_disk_daily d JOIN storage_disks s ON s.id = d.disk_id WHERE s.monitor_id = 2 ORDER BY d.day DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$st_ev = function (string $type) use ($pdo): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM monitor_events WHERE monitor_id = 2 AND event_type = ?");
    $q->execute([$type]);
    return (int)$q->fetchColumn();
};
$st_report = function (int $checked, array $smart = [], array $extra = []) use ($post_agent, $omnia_pl): int {
    $pl = array_merge($omnia_pl, ['agent_time' => time()], $extra);
    $pl['storage_disks'][0]['smart'] = array_merge($pl['storage_disks'][0]['smart'], $smart, ['checked_at' => $checked]);
    return $post_agent($pl);
};
$st_checked = (int)$omnia_pl['storage_disks'][0]['smart']['checked_at'];

$st_wipe();
check('Disky: hlášení s čerstvým čtením SMART agent přijme', $st_report($st_checked), 200);
$st_r = $st_disk();
check('Disky: disk dostane řádek s 16znakovým klíčem', [
    strlen((string)($st_r['disk_key'] ?? '')), $st_r['name'] ?? null, $st_r['transport'] ?? null,
    $st_r['smart_model'] ?? null, (int)($st_r['last_checked_at'] ?? 0),
], [16, 'sda', 'sata', 'KINGSTON SUV500MS120G', $st_checked]);
$st_d1 = $st_day();
check('Disky: den disku má jeden vzorek a nezměřené čítače zůstanou NULL', [
    (int)$st_d1['samples'], (int)$st_d1['temp_max'], (int)$st_d1['temp_n'], (int)$st_d1['unsafe_shutdowns'],
    (int)$st_d1['runtime_bad_blocks'], $st_d1['offline_uncorrectable'], $st_d1['host_written_bytes'],
], [1, 67, 1, 227, 3, null, null]);

check('Disky: stejné hlášení podruhé neotevře ani bránu', $st_report($st_checked), 200);
check('Disky: a nepřidá vzorek', (int)$st_day()['samples'], 1);
$st_force();
$st_report($st_checked);
check('Disky: ani s otevřenou bránou - o čerstvosti rozhoduje databáze', (int)$st_day()['samples'], 1);
$st_report($st_checked + 60);
$st_d2 = $st_day();
check('Disky: novější checked_at přidá vzorek', [(int)$st_d2['samples'], (int)$st_d2['temp_n']], [2, 2]);

// The dropped disk list (storage_list_dropped) must fall back to the SCALAR
// throttle: three reports in three minutes are one hourly pass, not three.
$st_wipe();
$st_strip_list();
$st_report($st_checked + 100, ['temperature_c' => 70]);
$st_sampled_at = $st_disk()['last_sample_at'] ?? null;
for ($st_i = 1; $st_i < 3; $st_i++) {
    $st_strip_list();
    $st_report($st_checked + 100 + $st_i, ['temperature_c' => 70]);
}
$st_d3 = $st_day();
$st_state = json_decode((string)($st_disk()['alert_state'] ?? ''), true) ?: [];
$st_temp0 = $st_ev('disk_temp_critical');
check('Disky bez seznamu v details: tři hlášení za tři minuty dají jeden vzorek',
    [(int)$st_d3['samples'], (int)$st_d3['temp_n']], [1, 1]);
check('a druhé ani třetí hlášení do storage_disks nesáhne', $st_disk()['last_sample_at'] ?? null, $st_sampled_at);
check('teplotní série je 1, takže 70 °C zatím neupozorní',
    [$st_state['temp_streak'] ?? null, $st_temp0], [1, 0]);

// --- Host writes: the kernel counter is 32-bit and restarts at every boot ------
$st_wipe();
$pdo->exec("DELETE FROM storage_disk_daily");
$st_dev = fn (int $sectors, int $uptime): array => [
    'uptime' => $uptime,
    'disk_devices' => [['device' => 'sda', 'read_kbps' => null, 'write_kbps' => null,
        'read_sectors_total' => 10, 'write_sectors_total' => $sectors]],
];
$st_report($st_checked + 200, [], $st_dev(1000, 100000));
check('Zápisy hostitele: první vzorek nepřičte nic', $st_day()['host_written_bytes'], null);

// An hour of wall clock, faked on the row the delta is measured against.
$st_hour_ago = function () use ($pdo): void {
    $pdo->exec("UPDATE storage_disks SET last_sample_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE monitor_id = 2");
    $pdo->exec("DELETE FROM storage_disk_daily");
};
$st_hour_ago();
$st_report($st_checked + 201, [], $st_dev(3048, 103600));
check('Zápisy hostitele: 2048 sektorů za hodinu = 1 MiB', (int)$st_day()['host_written_bytes'], 1048576);

$st_hour_ago();
$st_report($st_checked + 202, [], $st_dev(500, 300));
$st_d4 = $st_day();
check('Zápisy hostitele: po restartu se počítá od nuly a den je neúplný',
    [(int)$st_d4['host_written_bytes'], (int)$st_d4['host_written_partial']], [256000, 1]);

$st_hour_ago();
$pdo->exec("UPDATE storage_disks SET last_write_sectors = 5000, last_uptime = 300 WHERE monitor_id = 2");
$st_report($st_checked + 203, [], $st_dev(400, 3900));
$st_d5 = $st_day();
check('Zápisy hostitele: nižší čítač při plynulém uptime nepřičte nic, jen označí den',
    [$st_d5['host_written_bytes'], (int)$st_d5['host_written_partial']], [null, 1]);

// --- Disk and filesystem alerts (CORE 3.5) -------------------------------------
$st_wipe();
$pdo->exec("DELETE FROM storage_disk_daily");
$st_notif = function () use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM notification_log WHERE monitor_id = 2 AND status = 'storage_warning'")->fetchColumn();
};
// An earlier block of this suite leaves the agent switch off; the alert path
// is only observable with it on, so it is set here and restored afterwards.
$st_agent_switch = (string)$pdo->query("SELECT key_value FROM settings WHERE key_name = 'agent_notifications_enabled'")->fetchColumn();
$set_setting('agent_notifications_enabled', '1');
$st_grow0 = $st_ev('disk_errors_growing');
$st_notif0 = $st_notif();
$st_report($st_checked + 300);
check('Upozornění: první pohled na 3 vadné bloky je tichý', $st_ev('disk_errors_growing') - $st_grow0, 0);
$st_report($st_checked + 301, ['runtime_bad_blocks' => 4]);
check('Upozornění: 3 → 4 vadné bloky dají právě jednu událost', $st_ev('disk_errors_growing') - $st_grow0, 1);
$st_warn_ev = $pdo->query("SELECT description FROM monitor_events WHERE monitor_id = 2 AND event_type = 'disk_errors_growing' ORDER BY id DESC LIMIT 1")->fetchColumn();
check_true('a událost říká, který čítač a z čeho kam', str_contains((string)$st_warn_ev, 'vadné bloky 3 → 4'));
check('a se zapnutými zprávami agenta odejde i notifikace', $st_notif() - $st_notif0, 1);
$st_report($st_checked + 302, ['runtime_bad_blocks' => 4]);
check('Upozornění: stejné čtení se už neohlásí', $st_ev('disk_errors_growing') - $st_grow0, 1);

// The gate of agent_notifications_enabled must silence the NOTIFICATION, never
// the event: the timeline is the record that the disk is getting worse.
$pdo->exec("UPDATE storage_disks SET alert_state = '{\"base\":{\"badblk\":6}}' WHERE monitor_id = 2");
$st_n0 = $st_notif();
$st_e0 = $st_ev('disk_errors_growing');
$set_setting('agent_notifications_enabled', '0');
$st_report($st_checked + 304, ['runtime_bad_blocks' => 7]);
check('Upozornění: s vypnutými zprávami agenta událost vznikne, notifikace ne',
    [$st_ev('disk_errors_growing') - $st_e0, $st_notif() - $st_n0], [1, 0]);
$set_setting('agent_notifications_enabled', $st_agent_switch === '' ? '1' : $st_agent_switch);

// The user's SSD idles at 67 °C with a lifetime maximum of 68 - forever.
$st_wipe();
$st_temp1 = $st_ev('disk_temp_critical');
for ($st_i = 0; $st_i < 5; $st_i++) {
    $st_report($st_checked + 400 + $st_i, ['temperature_c' => 67]);
}
check('Upozornění: 67 °C pětkrát po sobě nic nehlásí', $st_ev('disk_temp_critical') - $st_temp1, 0);

$st_fs = fn (float $pct, string $mount = '/srv'): array => ['filesystems' => [[
    'mount' => $mount, 'device' => '/dev/sda1', 'fstype' => 'btrfs',
    'total_kb' => 117217792, 'used_kb' => 1, 'avail_kb' => 1, 'used_pct' => $pct,
]]];
$st_full0 = $st_ev('fs_full');
$st_freed0 = $st_ev('fs_freed');
$st_report($st_checked + 500, [], $st_fs(95));
check('Oddíl: jedno hlášení nad limitem ještě nealertuje', $st_ev('fs_full') - $st_full0, 0);
$st_report($st_checked + 501, [], $st_fs(95));
check('Oddíl: /srv na 95 % dvakrát = jedna událost fs_full', $st_ev('fs_full') - $st_full0, 1);
$st_report($st_checked + 502, [], $st_fs(84));
check('Oddíl: 84 % uvolní a ohlásí fs_freed', $st_ev('fs_freed') - $st_freed0, 1);
$st_report($st_checked + 503, [], $st_fs(99, '/'));
$st_report($st_checked + 504, [], $st_fs(99, '/'));
check('Oddíl: kořen zůstává na starém hdd alertu', $st_ev('fs_full') - $st_full0, 1);
$st_wipe();
// --- Speed tests: ack, repair of damaged rows, nothing lost silently ---------
//
// W01 divided real speeds by 125000, W02 threw the files away on a bare 200.
// Both are fixed here: the response says how far the batch was dealt with, and
// a re-sent file repairs the damaged row instead of being ignored.
$pdo->exec("DELETE FROM speedtest_results WHERE monitor_id = 2");
$sp_post = function (array $extra) use ($base, $agent_payload): array {
    $ch = curl_init($base . '/agent_api.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array_merge($agent_payload, $extra), JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true) ?: []];
};
$sp_rows = function () use ($pdo): array {
    return $pdo->query("SELECT measured_at, download_mbps, upload_mbps, ping_ms, source, iface, tool, link_mbit,"
        . " bytes_received, bytes_sent, diagnostics FROM speedtest_results WHERE monitor_id = 2 ORDER BY measured_at")->fetchAll();
};
$sp_details = function () use ($pdo): array {
    return json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
};
$sp_ts1 = '2026-09-19T05:23:41+02:00';
$sp_ts2 = '2026-09-20T05:11:07+02:00';
$sp_probe = [
    'timestamp' => $sp_ts2, 'download_mbps' => 1350.12, 'upload_mbps' => 902.4, 'ping_ms' => 2.1, 'jitter_ms' => 0.3,
    'server' => 'Prague, Czech Republic (CESNET)', 'bytes_received' => 2531000000, 'bytes_sent' => 1692000000,
    'started_by' => 'agent', 'iface' => 'eth2', 'tool' => 'go-1.0.12', 'link_mbit' => 2500,
    'diagnostics' => ['v' => 1, 'cpu_measured' => true, 'path_verified' => true, 'samples' => 45,
        'dl' => ['secs' => 15.0, 'wan_mbps' => 1400.0, 'core' => 0, 'core_busy_pct' => 100.0], 'client' => ['ip' => '10.0.0.1']],
];
$sp_turris = ['timestamp' => $sp_ts1, 'download_mbps' => 830.0, 'upload_mbps' => 560.0,
    'bytes_received' => 1556250000, 'bytes_sent' => 1050000000, 'started_by' => 'turris', 'link_mbit' => 2500];

[$sp_code, $sp_body] = $sp_post(['speedtests' => [$sp_turris, $sp_probe]]);
check('Speedtesty: dávku agent přijme', $sp_code, 200);
check('Speedtesty: odpověď potvrdí nejnovější vyřízenou položku (holá 200 stačit nesmí)',
    $sp_body['speedtests_acked'] ?? null, $sp_ts2);
$sp_r = $sp_rows();
check('Speedtesty: obě měření se uloží se svým původem', [count($sp_r), $sp_r[0]['source'], $sp_r[1]['source']], [2, 'turris', 'agent']);
check('Speedtesty: kontext měření se uloží do nových sloupců',
    [(float)$sp_r[1]['download_mbps'], $sp_r[1]['iface'], $sp_r[1]['tool'], (int)$sp_r[1]['link_mbit'], (int)$sp_r[1]['bytes_received']],
    [1350.12, 'eth2', 'go-1.0.12', 2500, 2531000000]);
check('Speedtesty: měření z Turrisu rozhraní nevymýšlí', [$sp_r[0]['iface'], $sp_r[0]['tool']], [null, null]);
check_true('Speedtesty: diagnostika se uloží bez bloku client',
    str_contains((string)$sp_r[1]['diagnostics'], '"wan_mbps"') && !str_contains((string)$sp_r[1]['diagnostics'], '10.0.0.1'));
$spd = $sp_details();
check('Speedtesty: dávka se do details nedostane ani jako neznámý klíč',
    [array_key_exists('speedtests', $spd), $spd['ingest_issues'] ?? null], [false, []]);

// A row damaged by the pre-0.1.7 unit bug is repaired by the file the agent
// re-sends once; a healthy row is the same measurement and stays as it is.
$pdo->exec("UPDATE speedtest_results SET download_mbps = 0.0148, upload_mbps = NULL, iface = NULL WHERE monitor_id = 2 AND source = 'agent'");
$pdo->exec("UPDATE speedtest_results SET download_mbps = 830, upload_mbps = 560 WHERE monitor_id = 2 AND source = 'turris'");
[$sp_code2] = $sp_post(['speedtests' => [array_merge($sp_turris, ['download_mbps' => 111.0]), $sp_probe]]);
$sp_r = $sp_rows();
check('Speedtesty: opakovaná dávka projde', $sp_code2, 200);
check('Speedtesty: poškozený řádek se opraví (a doplní chybějící kontext)',
    [(float)$sp_r[1]['download_mbps'], (float)$sp_r[1]['upload_mbps'], $sp_r[1]['iface']], [1350.12, 902.4, 'eth2']);
check('Speedtesty: zdravý řádek se nepřepíše pomalejším čtením', (float)$sp_r[0]['download_mbps'], 830.0);
check('Speedtesty: dávka se neduplikuje', count($sp_r), 2);

// An item from an agent before 0.1.7: no byte counter, and 0.01 Mbit/s is the
// artefact of the unit bug, not a line. Unmeasured is NULL.
$sp_old = ['timestamp' => '2026-09-18T04:02:00+02:00', 'download_mbps' => 0.0148, 'upload_mbps' => 0.0095];
[, $sp_body3] = $sp_post(['speedtests' => [$sp_old]]);
$sp_r = $sp_rows();
check('Speedtesty: poškozená hodnota bez bajtů se uloží jako NULL, ne jako 0.01',
    [$sp_r[0]['download_mbps'], $sp_r[0]['upload_mbps'], $sp_r[0]['source']], [null, null, 'turris']);
check('Speedtesty: i tak je položka vyřízená a potvrzená', $sp_body3['speedtests_acked'] ?? null, $sp_old['timestamp']);

// A rejected item is acked (re-sending repairs nothing) and NAMED.
[, $sp_body4] = $sp_post(['speedtests' => [['download_mbps' => 500.0], $sp_probe]]);
$spd = $sp_details();
check('Speedtesty: položka bez času měření se odmítne a je vidět',
    [$spd['ingest_issues'][0]['type'] ?? null, $sp_body4['speedtests_acked'] ?? null], ['speedtest_rejected', $sp_ts2]);
[, $sp_body5] = $sp_post(['speedtests' => [array_merge($sp_probe, ['timestamp' => '2026-09-21T05:00:00+02:00', 'download_mbps' => 0.0148])]]);
$spd = $sp_details();
check('Speedtesty: neshoda jednotek se uloží i ohlásí',
    [$spd['ingest_issues'][0]['type'] ?? null, $sp_body5['speedtests_acked'] ?? null], ['unit_mismatch', '2026-09-21T05:00:00+02:00']);
check_true('Speedtesty: a je u samotného měření',
    str_contains((string)($pdo->query("SELECT diagnostics FROM speedtest_results WHERE monitor_id = 2 ORDER BY measured_at DESC LIMIT 1")->fetchColumn()), '"unit_mismatch":true'));
check('Speedtesty: hlášení bez dávky klíč speedtests_acked vůbec nenese',
    array_key_exists('speedtests_acked', $sp_post([])[1]), false);
$pdo->exec("DELETE FROM speedtest_results WHERE monitor_id = 2");

// Agent 0.1.11 says which line carried the test. The Turris ran its nightly
// test over the LTE backup and the page used to show 47 Mbit/s as the WAN.
$ul_wan = ['timestamp' => date('c', time() - 3600), 'download_mbps' => 393.09, 'upload_mbps' => 435.67, 'ping_ms' => 3.49,
    'started_by' => 'turris', 'iface' => 'pppoe-wan', 'uplink' => 'wan', 'uplink_evidence' => 'counters', 'proto' => 'https'];
$ul_lte = ['timestamp' => date('c', time() - 7200), 'download_mbps' => 47.18, 'upload_mbps' => 44.08, 'ping_ms' => 37.75,
    'started_by' => 'turris', 'iface' => 'eth3', 'uplink' => 'backup', 'uplink_evidence' => 'counters', 'proto' => 'http'];
$ul_bad = ['timestamp' => date('c', time() - 9000), 'download_mbps' => 10.0, 'upload_mbps' => 10.0,
    'started_by' => 'turris', 'uplink' => 'lte', 'uplink_evidence' => 'guess', 'proto' => 'ftp'];
check('Linka: dávku s linkami agent přijme', $sp_post(['speedtests' => [$ul_bad, $ul_lte, $ul_wan]])[0], 200);
$ul_db = $pdo->query("SELECT uplink, uplink_source, proto FROM speedtest_results WHERE monitor_id = 2 ORDER BY measured_at")->fetchAll(PDO::FETCH_NUM);
check('Linka: uloží se změřená linka, důkaz i protokol; neplatné hodnoty jako NULL',
    $ul_db, [[null, null, null], ['backup', 'counters', 'http'], ['wan', 'counters', 'https']]);
$sp_post(['speedtests' => [array_diff_key($ul_lte, ['uplink' => 1, 'uplink_evidence' => 1])]]);
check('Linka: opakované poslání od staršího agenta změřenou linku nesmaže',
    $pdo->query("SELECT uplink FROM speedtest_results WHERE monitor_id = 2 AND ABS(download_mbps - 47.18) < 0.001")->fetchColumn(), 'backup');
[$ul_code, $ul_hist] = api_get_auth($base, 'action=speedtest_history&monitor_id=2', $cookie_jar);
check('Linka: historie odpoví', $ul_code, 200);
check('Linka: každé měření nese svou linku a protokol',
    array_map(fn ($m) => [$m['uplink'], $m['uplinkSource'], $m['proto']], $ul_hist['measurements'] ?? []),
    [['wan', 'counters', 'https'], ['backup', 'counters', 'http'], [null, null, null]]);
check('Linka: týdenní průměr WAN LTE nestáhne dolů (WAN + neznámý řádek)',
    [$ul_hist['averages']['week']['samples'] ?? null, $ul_hist['averages']['week']['unknownSamples'] ?? null,
     (float)($ul_hist['averages']['week']['downloadMinMbps'] ?? -1)], [2, 1, 10.0]);
check('Linka: LTE má vlastní průměr',
    [$ul_hist['backupAverages']['week']['samples'] ?? null, $ul_hist['backupAverages']['week']['downloadMbps'] ?? null], [1, 47.18]);
$pdo->exec("DELETE FROM speedtest_results WHERE monitor_id = 2");

// X17: while the router's own test runs, the CPU of that minute is the test.
// The latch is left exactly as it was - not set, and not cleared either.
$sp_thr = function () use ($pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM monitor_events WHERE monitor_id = 2 AND event_type = 'threshold_exceeded'")->fetchColumn();
};
$sp_latch = fn () => $sp_details()['cpu_alert_sent'] ?? null;
$pdo->exec("UPDATE monitors SET last_details = JSON_SET(COALESCE(last_details, '{}'), '$.cpu_alert_sent', false) WHERE id = 2");
$sp_thr0 = $sp_thr();
$sp_post(['cpu' => 97.0, 'speedtest_active' => true]);
check('test rychlosti v minutě hlášení nespustí poplach CPU',
    [$sp_thr() - $sp_thr0, $sp_latch()], [0, false]);
$sp_post(['cpu' => 97.0, 'speedtest_active' => false]);
check('Hygiena: bez měření se stejná hodnota ohlásí', [$sp_thr() - $sp_thr0, $sp_latch()], [1, true]);
$sp_post(['cpu' => 12.5, 'speedtest_active' => true]);
check('Hygiena: hlášení s měřením západku ani nezhasne', $sp_latch(), true);
$sp_post(['cpu' => 12.5, 'speedtests' => [array_merge($sp_probe, ['timestamp' => date('c')])]]);
check('Hygiena: měření v téhle minutě si hlášení označí samo (agent o něm vědět nemusel)',
    [$sp_latch(), $sp_thr() - $sp_thr0], [true, 1]);
$sp_post(['cpu' => 12.5]);
check('Hygiena: čisté hlášení pod limitem západku zhasne', $sp_latch(), false);
$pdo->exec("DELETE FROM speedtest_results WHERE monitor_id = 2");

// --- Latches and events of the router (X14, alert sheet 2.2) -----------------
//
// Every one of them follows the debounce the WAN and LTE alerts already use: a
// streak, a latch so the alert goes out once, and a recovery that clears it.
// The timeline-only events (a restart, an emptied connection table, an OOM
// kill) must NOT turn into a notification - a router that loses power every
// few days would otherwise wake somebody up every few days.
$ra_ev = function (string $type) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM monitor_events WHERE monitor_id = 2 AND event_type = ?");
    $st->execute([$type]);
    return (int)$st->fetchColumn();
};
$ra_notif = function (string $status) use ($pdo): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM notification_log WHERE monitor_id = 2 AND status = ?");
    $st->execute([$status]);
    return (int)$st->fetchColumn();
};
$ra_details = function () use ($pdo): array {
    return json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
};
// Only the keys of these rules are reset: the blocks around this one live in
// the same column.
$ra_reset = function (array $keys) use ($pdo, $ra_details): void {
    $details = $ra_details();
    foreach ($keys as $key) {
        unset($details[$key]);
    }
    $st = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2");
    $st->execute([json_encode($details, JSON_UNESCAPED_UNICODE)]);
};
$ra_switch = (string)$pdo->query("SELECT key_value FROM settings WHERE key_name = 'agent_notifications_enabled'")->fetchColumn();
$set_setting('agent_notifications_enabled', '1');

// Link rate of the WAN port (W14).
$ra_reset(['wan_link_baseline', 'wan_link_low_since', 'wan_link_bad_streak', 'wan_link_alert_sent']);
$ra_deg0 = $ra_ev('wan_link_degraded');
$ra_notif0 = $ra_notif('wan_link_degraded');
$post_agent(['wan_link_mbit' => 2500, 'wan_link_dev' => 'eth2']);
check('Port WAN: první rychlost je jen základ', $ra_ev('wan_link_degraded') - $ra_deg0, 0);
$post_agent(['wan_link_mbit' => 1000, 'wan_link_dev' => 'eth2']);
$post_agent(['wan_link_mbit' => 1000, 'wan_link_dev' => 'eth2']);
check('Port WAN: dvě pomalejší hlášení ještě mlčí', $ra_ev('wan_link_degraded') - $ra_deg0, 0);
$post_agent(['wan_link_mbit' => 1000, 'wan_link_dev' => 'eth2']);
check('Port WAN: třetí pomalejší hlášení ohlásí degradaci právě jednou',
    [$ra_ev('wan_link_degraded') - $ra_deg0, $ra_notif('wan_link_degraded') - $ra_notif0], [1, 1]);
$post_agent(['wan_link_mbit' => 1000, 'wan_link_dev' => 'eth2']);
check('Port WAN: opakování už neohlásí nic', $ra_ev('wan_link_degraded') - $ra_deg0, 1);
$ra_res0 = $ra_ev('wan_link_restored');
$post_agent(['wan_link_mbit' => 2500, 'wan_link_dev' => 'eth2']);
check('Port WAN: návrat na plnou rychlost se ohlásí jednou',
    [$ra_ev('wan_link_restored') - $ra_res0, (float)($ra_details()['wan_link_baseline']['mbit'] ?? 0)], [1, 2500.0]);

// Connection table (W09).
$ra_reset(['conntrack_bad_streak', 'conntrack_full_sent']);
$ra_ct0 = $ra_ev('conntrack_full');
$ra_ctn0 = $ra_ev('conntrack_normal');
$post_agent(['conntrack_pct' => 92.0]);
check('Tabulka spojení: jedno hlášení na 92 % nealertuje', $ra_ev('conntrack_full') - $ra_ct0, 0);
$post_agent(['conntrack_pct' => 92.0]);
check('Tabulka spojení: druhé hlášení ohlásí plnou tabulku', $ra_ev('conntrack_full') - $ra_ct0, 1);
$post_agent(['conntrack_pct' => 80.0]);
check('Tabulka spojení: pod 85 % jde návrat do normálu jen do časové osy',
    [$ra_ev('conntrack_normal') - $ra_ctn0, $ra_notif('conntrack_normal')], [1, 0]);

// Firewall (G20): only a device with a WAN role.
$ra_reset(['firewall_bad_streak', 'firewall_alert_sent', 'firewall_off_since']);
$ra_fw0 = $ra_ev('firewall_disabled');
$post_agent(['firewall_enabled' => false, 'wan_up' => true]);
$post_agent(['firewall_enabled' => false, 'wan_up' => true]);
check('Firewall: dvě hlášení bez pravidel ještě mlčí', $ra_ev('firewall_disabled') - $ra_fw0, 0);
$post_agent(['firewall_enabled' => false, 'wan_up' => true]);
check('Firewall: tři hlášení po sobě ohlásí nenačtená pravidla a zapamatují si čas',
    [$ra_ev('firewall_disabled') - $ra_fw0, isset($ra_details()['firewall_off_since'])], [1, true]);
$ra_fwr0 = $ra_ev('firewall_restored');
$post_agent(['firewall_enabled' => true, 'wan_up' => true]);
check('Firewall: načtená pravidla ohlásí zotavení a čas zapomenou',
    [$ra_ev('firewall_restored') - $ra_fwr0, array_key_exists('firewall_off_since', $ra_details())], [1, true]);
check('Firewall: a čas je opravdu prázdný, ne jen chybějící', $ra_details()['firewall_off_since'], null);
$ra_reset(['firewall_bad_streak', 'firewall_alert_sent', 'firewall_off_since']);
$ra_fw1 = $ra_ev('firewall_disabled');
for ($ra_i = 0; $ra_i < 3; $ra_i++) {
    $post_agent(['firewall_enabled' => false, 'wan_up' => null]);
}
check('AP bez WAN role nedostane poplach o firewallu', $ra_ev('firewall_disabled') - $ra_fw1, 0);

// Local DNS resolver (G41): judged only while the line itself works.
$ra_reset(['dns_resolver_bad_streak', 'dns_resolver_alert_sent']);
$ra_dns0 = $ra_ev('dns_resolver_failed');
$post_agent(['dns_resolver_ok' => false, 'wan_internet' => false, 'wan_up' => true]);
$post_agent(['dns_resolver_ok' => false, 'wan_internet' => false, 'wan_up' => true]);
check('DNS resolver: při výpadku linky se nesoudí (mluví poplach o WAN)', $ra_ev('dns_resolver_failed') - $ra_dns0, 0);
$post_agent(['dns_resolver_ok' => false, 'wan_internet' => null, 'wan_up' => true]);
check('DNS resolver: bez měření dosažitelnosti taky ne', $ra_ev('dns_resolver_failed') - $ra_dns0, 0);
$post_agent(['dns_resolver_ok' => false, 'wan_internet' => true, 'wan_up' => true]);
$post_agent(['dns_resolver_ok' => false, 'wan_internet' => true, 'wan_up' => true]);
check('DNS resolver: dvě selhání s funkční linkou ohlásí výpadek resolveru', $ra_ev('dns_resolver_failed') - $ra_dns0, 1);
$ra_dnsr0 = $ra_ev('dns_resolver_restored');
$post_agent(['dns_resolver_ok' => true, 'wan_internet' => true, 'wan_up' => true]);
check('DNS resolver: odpovídající resolver ohlásí návrat', $ra_ev('dns_resolver_restored') - $ra_dnsr0, 1);

// Restart (G21) and OOM kills (G31): timeline only.
$ra_rb0 = $ra_ev('router_rebooted');
$post_agent(['uptime' => 400000, 'oom_kills' => 1]);
$post_agent(['uptime' => 400060, 'oom_kills' => 1]);
check('Restart: rostoucí uptime restart není', $ra_ev('router_rebooted') - $ra_rb0, 0);
$post_agent(['uptime' => 90, 'oom_kills' => 0]);
check('Restart: klesající uptime je událost, notifikace ne',
    [$ra_ev('router_rebooted') - $ra_rb0, $ra_notif('router_rebooted')], [1, 0]);
$ra_oom0 = $ra_ev('oom_kill');
$post_agent(['uptime' => 150, 'oom_kills' => 0]);
$post_agent(['uptime' => 210, 'oom_kills' => 2]);
check('OOM: růst čítače je událost a zapíše si čas',
    [$ra_ev('oom_kill') - $ra_oom0, isset($ra_details()['oom_kill_at'])], [1, true]);
$post_agent(['uptime' => 270, 'oom_kills' => 2]);
check('OOM: stejný čítač už událost nevyrobí', $ra_ev('oom_kill') - $ra_oom0, 1);

// The step of the connection table is the `drop` column ALONE: insert_failed
// counts unresolved clashes and early_drop successful evictions (WAN 3.1.4),
// and summing them would report a busy router as one refusing connections.
$ra_ct_base = ['uptime' => 500000, 'wan_link_dev' => 'eth2', 'conntrack_drop' => 2,
    'conntrack_insert_failed' => 100, 'conntrack_early_drop' => 200];
$post_agent($ra_ct_base);
$post_agent(array_merge($ra_ct_base, ['uptime' => 500060, 'conntrack_drop' => 5,
    'conntrack_insert_failed' => 140, 'conntrack_early_drop' => 260]));
check('krok conntracku je jen sloupec drop',
    (int)$pdo->query("SELECT conntrack_drops FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetchColumn(), 3);

// ... and the other two columns are kept as evidence: early_drop is the
// sentence under the rule conntrack_drops ("evicted to make room"),
// insert_failed is support material that no rule reads. Typed on the way in,
// not left to the pass-through - a rule renders one of them.
$ra_ct_det = $ra_details();
check('vytlačená a neuložená spojení se ukládají jako čísla',
    [$ra_ct_det['conntrack_early_drop'] ?? 'chybí', $ra_ct_det['conntrack_insert_failed'] ?? 'chybí'], [260, 140]);
$post_agent(array_merge($ra_ct_base, ['uptime' => 500120, 'conntrack_early_drop' => 'nesmysl',
    'conntrack_insert_failed' => -5]));
$ra_ct_det = $ra_details();
check('nečíselný čítač se neuloží jako text',
    [$ra_ct_det['conntrack_early_drop'], $ra_ct_det['conntrack_insert_failed']], [null, null]);

// G26: the metric column used to be NULL every minute - the list is a list.
$post_agent(['wireguard_peers' => [
    ['public_key' => 'aaa', 'latest_handshake' => 1789891000],
    ['public_key' => 'bbb', 'latest_handshake' => 1789891100],
]]);
check('WireGuard: do sloupce se uloží počet protějšků, ne NULL',
    (int)$pdo->query("SELECT wireguard_peers FROM vps_metrics WHERE monitor_id = 2 ORDER BY id DESC LIMIT 1")->fetchColumn(), 2);

// The first report of an interface carries its counters since boot. Booked
// as today's traffic it made a new agent look like it moved terabytes today.
$pdo->exec("DELETE FROM monitor_interface_traffic WHERE monitor_id = 2 AND iface = 'lan9'");
$if_row = function () use ($pdo) {
    return $pdo->query("SELECT rx_bytes_total, tx_bytes_total, last_rx_bytes FROM monitor_interface_traffic WHERE monitor_id = 2 AND iface = 'lan9'")->fetch();
};
$post_agent(['interfaces' => [['iface' => 'lan9', 'rx_bytes' => 5e12, 'tx_bytes' => 7e11, 'rx_packets' => 9e9, 'tx_packets' => 8e8]]]);
$if1 = $if_row();
check('Provoz rozhraní: první hlášení je jen výchozí bod, ne dnešní provoz',
    [(float)($if1['rx_bytes_total'] ?? -1), (float)($if1['tx_bytes_total'] ?? -1)], [0.0, 0.0]);
check('Provoz rozhraní: a čítač od startu se uloží jako výchozí bod', (float)($if1['last_rx_bytes'] ?? -1), 5e12);
$post_agent(['interfaces' => [['iface' => 'lan9', 'rx_bytes' => 5e12 + 1500, 'tx_bytes' => 7e11 + 300, 'rx_packets' => 9e9 + 3, 'tx_packets' => 8e8 + 2]]]);
$if2 = $if_row();
check('Provoz rozhraní: další hlášení přičte jen rozdíl',
    [(float)($if2['rx_bytes_total'] ?? -1), (float)($if2['tx_bytes_total'] ?? -1)], [1500.0, 300.0]);
$pdo->exec("DELETE FROM monitor_interface_traffic WHERE monitor_id = 2 AND iface = 'lan9'");

// G42: the hourly cron pass. Without it the banner could never say how many
// minutes are missing - the pure function has no database to count with.
$ra_reset(['reports_24h', 'boot_time']);
check_true('Hlášení za 24 h: hodinový krok cronu monitor přepočítá', bk_update_reports_24h($pdo) >= 1);
$ra_rep = $ra_details()['reports_24h'] ?? [];
check('Hlášení za 24 h: uloží se očekávání, skutečnost i čas výpočtu',
    [is_int($ra_rep['expected'] ?? null) && $ra_rep['expected'] >= 120, is_int($ra_rep['received'] ?? null), is_int($ra_rep['checked_at'] ?? null)], [true, true, true]);
check_true('Hlášení za 24 h: napočítá právě ta, která opravdu dorazila',
    ($ra_rep['received'] ?? 0) === (int)$pdo->query("SELECT COUNT(*) FROM vps_metrics WHERE monitor_id = 2 AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn());
check('Hlášení za 24 h: do hodiny se nepočítá znovu', bk_update_reports_24h($pdo), 0);
$set_setting('agent_notifications_enabled', $ra_switch === '' ? '1' : $ra_switch);

// --- The Routers section of the weekly digest (CORE 3.8) ----------------------
//
// The engine is only worth as much as what reaches the e-mail, and that path
// touches the database three times: the inputs batch, the weekly snapshot and
// the build itself. The snapshot is the dangerous one - it decides what counts
// as "new this week", so a wrong write would either mail the whole list again
// every Monday or never mail anything again.
bk_test_load_functions($root . '/functions.php', [
    'bk_router_rec_thresholds', 'bk_router_rec_window', 'bk_router_rec_inputs_batch', 'bk_router_rec_evaluate',
    'bk_router_rec_split', 'bk_router_rec_render', 'bk_router_rec_state_save', 'bk_rec_week_stat',
    'bk_rec_days_with_data', 'bk_rec_sort_items', 'bk_rec_item', 'bk_rec_num', 'bk_rec_band_label',
    'bk_rec_daily_value', 'bk_rec_over', 'bk_rec_above', 'bk_rec_state_active', 'bk_rec_state_severity',
    'bk_rec_disk_label', 'bk_rec_rules_disk_health', 'bk_rec_rules_disk_week', 'bk_rec_rules_filesystems',
    'bk_digest_routers', 'bk_digest_router_facts', 'bk_digest_router_facts_lines', 'bk_digest_router_new_split',
    'bk_disk_label', 'bk_disk_error_counters', 'bk_disk_temp_limit', 'bk_wifi_radio_profile',
    'bk_version_is_older', 'bk_format_bytes_cz',
    'bk_wan_down_ranges', 'bk_link_down_ranges', 'bk_pair_link_periods', 'bk_speedtest_attribute',
    'bk_speedtest_attribute_rows',
]);

if (function_exists('bk_digest_routers')) {
    $dg_state = fn (): array => $pdo->query("SELECT rec_key, active, severity, first_digest_week, raised_digest_week, UNIX_TIMESTAMP(last_seen) AS seen FROM router_rec_state WHERE monitor_id = 2 ORDER BY rec_key")->fetchAll(PDO::FETCH_ASSOC);
    $dg_week = date('o-\WW');
    $dg_item = fn (string $key, string $sev, string $rule = 'disk_temp_warm'): array =>
        bk_rec_item($rule, $key, 'storage', $sev, ['kind' => 'disk'], ['name' => 'sda', 'disk' => 'SSD (sda)']);
    $pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = 2")->execute();

    // The blocks above leave the router in whatever state their own last case
    // needed (one of them deliberately strips the disk list), so this block
    // seeds what it measures: the fixture's radios and disks, one disk row and
    // two days of its history.
    $dg_seed = json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
    $dg_seed['agent_version'] = $omnia_fx['payload']['version'];
    $dg_seed['agent_last_seen'] = time();
    $dg_seed['wifi_radios'] = $omnia_fx['payload']['wifi_radios'];
    $dg_seed['storage_disks'] = $omnia_fx['payload']['storage_disks'];
    $dg_seed['storage_disks'][0]['key'] = 'digestdiskaaaaaa';
    // The fixture payload carries no `filesystems` (the capture did not have
    // one), so the mount this block needs is written out here.
    $dg_seed['filesystems'] = [['mount' => '/srv', 'fstype' => 'ext4', 'total_kb' => 1048576,
        'used_pct' => 19.0, 'avail_kb' => 800000]];
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode($dg_seed)]);
    $pdo->prepare("DELETE FROM storage_disks WHERE monitor_id = 2")->execute();
    $pdo->prepare(
        "INSERT INTO storage_disks (monitor_id, disk_key, name, transport, rotational, size_bytes, first_seen, last_seen)
         VALUES (2, 'digestdiskaaaaaa', 'sda', 'sata', 0, 120034123776, DATE_SUB(NOW(), INTERVAL 400 DAY), NOW())"
    )->execute();
    $dg_disk_id = (int)$pdo->lastInsertId();
    $dg_day = $pdo->prepare(
        "INSERT INTO storage_disk_daily (disk_id, day, samples, temp_sum, temp_n, temp_max, runtime_bad_blocks)
         VALUES (?, DATE_SUB(CURDATE(), INTERVAL ? DAY), 24, ?, 24, 69, 3)"
    );
    $dg_day->execute([$dg_disk_id, 2, 24 * 67]);
    $dg_day->execute([$dg_disk_id, 3, 24 * 66]);

    // 1. The inputs batch: three queries for the whole chunk, the result keyed
    //    by monitor id. Monitor 2 has the fixture's disks and a week of metrics.
    $dg_in = bk_router_rec_inputs_batch($pdo, [2, 96], date('Y-m-d'));
    check('Digest: dávka vstupů odpoví na každý router zvlášť', array_keys($dg_in), [2, 96]);
    check('Digest: okno je sedm celých dní před dneškem', count($dg_in[2]['window']['days']), 7);
    check('Digest: disky routeru se načtou i se svými dny',
        [count($dg_in[2]['disks']), count($dg_in[2]['disks']['digestdiskaaaaaa']['daily'] ?? [])], [1, 2]);
    // A column the reading never filled has to come back NULL: the counter
    // rules ask "did it grow", and a zero would be an answer nobody measured.
    // (The day keys come from the database, whose date may be a timezone away
    // from PHP's, so the newest key is read rather than computed here.)
    $dg_daily = $dg_in[2]['disks']['digestdiskaaaaaa']['daily'];
    $dg_newest = $dg_daily[max(array_keys($dg_daily))] ?? [];
    check('Digest: den bez záznamu zůstane nezměřený, ne nulový',
        [array_key_exists('media_errors', $dg_newest), $dg_newest['media_errors'], $dg_newest['runtime_bad_blocks']],
        [true, null, 3]);
    check('Digest: prázdný stav je prázdné pole, ne null', $dg_in[96]['state'], []);

    // 2. The snapshot: the first build of a week writes, a retry does not.
    bk_router_rec_state_save($pdo, 2, [$dg_item('disk_temp_warm:d:a', 'warning')], $dg_week, [], []);
    $rows = $dg_state();
    check('Digest: první sestavení týdne uloží položku jako novou',
        [count($rows), $rows[0]['active'], $rows[0]['severity'], $rows[0]['first_digest_week'], $rows[0]['raised_digest_week']],
        [1, 1, 'warning', $dg_week, null]);

    // Back-date last_seen inside the SAME week: a retry between 08:00 and
    // 12:00 must leave the row completely alone.
    $pdo->exec("UPDATE router_rec_state SET last_seen = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE monitor_id = 2");
    $before = $dg_state()[0]['seen'];
    $state_now = [];
    foreach ($dg_state() as $r) {
        $state_now[$r['rec_key']] = ['active' => (int)$r['active'], 'severity' => $r['severity'],
            'first_digest_week' => $r['first_digest_week'], 'last_seen' => date('Y-m-d H:i:s', (int)$r['seen'])];
    }
    bk_router_rec_state_save($pdo, 2, [$dg_item('disk_temp_warm:d:a', 'warning')], $dg_week, [], $state_now);
    check('Digest: opakované sestavení ve stejném týdnu nic nepřepíše', $dg_state()[0]['seen'], $before);

    // 3. A severity that rose is the reason an old item is mailed in full again.
    bk_router_rec_state_save($pdo, 2, [$dg_item('disk_temp_warm:d:a', 'critical')], $dg_week, [], $state_now);
    $rows = $dg_state();
    check('Digest: zhoršení zapíše týden, kdy se zhoršilo',
        [$rows[0]['severity'], $rows[0]['raised_digest_week']], ['critical', $dg_week]);

    // 4. What was evaluated and stopped firing is cleared; what could NOT be
    //    evaluated keeps everything, or the next data would mail it as new.
    $state_now = [
        'disk_temp_warm:d:a' => ['active' => 1, 'severity' => 'critical', 'first_digest_week' => $dg_week],
        'disk_temp_warm:d:b' => ['active' => 1, 'severity' => 'warning', 'first_digest_week' => '2026-W30'],
    ];
    bk_router_rec_state_save($pdo, 2, [$dg_item('disk_temp_warm:d:b', 'warning')], $dg_week, [], $state_now);
    $rows = $dg_state();
    check('Digest: vyhodnocená a už nehořící položka se zhasne',
        [$rows[0]['rec_key'], $rows[0]['active'], $rows[0]['severity'], $rows[0]['first_digest_week']],
        ['disk_temp_warm:d:a', 0, null, null]);

    $pdo->prepare("UPDATE router_rec_state SET active = 1, severity = 'warning', first_digest_week = '2026-W30' WHERE monitor_id = 2 AND rec_key = 'disk_temp_warm:d:a'")->execute();
    $state_now['disk_temp_warm:d:a'] = ['active' => 1, 'severity' => 'warning', 'first_digest_week' => '2026-W30'];
    bk_router_rec_state_save($pdo, 2, [$dg_item('disk_temp_warm:d:b', 'warning')], $dg_week, ['disk_temp_warm:d:a'], $state_now);
    $rows = $dg_state();
    check('Digest: nevyhodnocená položka si nechá všechno',
        [$rows[0]['active'], $rows[0]['severity'], $rows[0]['first_digest_week']], [1, 'warning', '2026-W30']);

    // A mute survives the snapshot: it is the owner's decision, not a state.
    $pdo->prepare("UPDATE router_rec_state SET muted_at = NOW(), muted_severity = 'warning', mute_reason = 'vím o tom' WHERE monitor_id = 2 AND rec_key = 'disk_temp_warm:d:b'")->execute();
    bk_router_rec_state_save($pdo, 2, [$dg_item('disk_temp_warm:d:b', 'warning')], $dg_week, [], []);
    check('Digest: ztlumení přežije zápis snímku',
        (int)$pdo->query("SELECT COUNT(*) FROM router_rec_state WHERE monitor_id = 2 AND rec_key = 'disk_temp_warm:d:b' AND muted_at IS NOT NULL")->fetchColumn(), 1);
    $pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = 2")->execute();

    // 5. The whole section, against the routers the suite really has.
    $dg = bk_digest_routers($pdo, false);
    $dg_by_id = [];
    foreach ($dg['routers'] as $r) {
        $dg_by_id[(int)$r['id']] = $r;
    }
    check_true('Digest: sekce zná routery této databáze', isset($dg_by_id[2]));
    check('Digest: náhled nic nezapíše',
        (int)$pdo->query("SELECT COUNT(*) FROM router_rec_state WHERE monitor_id = 2")->fetchColumn(), 0);
    check_true('Digest: router s 0.1.7 se hodnotí', (bool)$dg_by_id[2]['applicable']);
    check_true('Digest: fakta se staví jen pro vykreslené routery a nesou rádio i disk',
        count($dg_by_id[2]['facts']['radios'] ?? []) >= 1 && count($dg_by_id[2]['facts']['disks'] ?? []) >= 1);
    check_false('Digest: v faktech není SSID',
        str_contains(json_encode($dg_by_id[2]['facts'], JSON_UNESCAPED_UNICODE), 'Domov'));
    check('Digest: nejhorší závažnost řadí routery, nejvýš deset', count($dg['routers']) <= 10, true);
    foreach ($dg['routers'] as $r) {
        check_true('Digest: každý router má jen položky bez page_only',
            !array_filter($r['items_full'], fn ($i) => !empty($i['page_only'])));
    }

    // A router whose agent is older than 0.1.7 says so instead of looking healthy.
    $dg_old = json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
    $dg_keep = $dg_old['agent_version'] ?? null;
    $dg_old['agent_version'] = '0.1.6';
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode($dg_old)]);
    $dg2 = bk_digest_routers($pdo, false);
    foreach ($dg2['routers'] as $r) {
        if ((int)$r['id'] === 2) {
            check('Digest: starší agent se nehodnotí a řekne proč', [$r['applicable'], $r['reason']], [false, 'agent_old']);
            check('Digest: a jeho verze je v datech', $r['reason_params']['version'] ?? null, '0.1.6');
        }
    }
    $dg_old['agent_version'] = $dg_keep;
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = 2")->execute([json_encode($dg_old)]);

    // 6. The snapshot really is written when the build is not a preview.
    //    The fixture's filesystems are a fifth full, so the monitor's own
    //    threshold is lowered to make a real rule fire through the real path.
    $dg_thr = $pdo->query("SELECT hdd_threshold FROM monitors WHERE id = 2")->fetchColumn();
    $pdo->prepare("UPDATE monitors SET hdd_threshold = 5 WHERE id = 2")->execute();
    $dg3 = bk_digest_routers($pdo, true);
    $dg_fired = [];
    foreach ($dg3['routers'] as $r) {
        if ((int)$r['id'] === 2) {
            foreach (array_merge($r['items_full'], $r['items_open']) as $i) {
                $dg_fired[] = (string)$i['key'];
            }
        }
    }
    sort($dg_fired);
    check_true('Digest: plný oddíl se do sekce dostane', count($dg_fired) >= 1);
    $dg_saved = $pdo->query("SELECT rec_key FROM router_rec_state WHERE monitor_id = 2 AND active = 1 ORDER BY rec_key")->fetchAll(PDO::FETCH_COLUMN);
    check('Digest: ostré sestavení uloží právě to, co hoří', $dg_saved, $dg_fired);
    check('Digest: a označí to za novinku tohoto týdne',
        $pdo->query("SELECT DISTINCT first_digest_week FROM router_rec_state WHERE monitor_id = 2 AND active = 1")->fetchAll(PDO::FETCH_COLUMN),
        [$dg_week]);

    // 7. And the same thing as HTML: the admin preview renders with the very
    //    template the e-mail uses, so this is the only place where a fatal in
    //    the section or a missing dictionary key would show up.
    if (isset($cookie_jar)) {
        // site_url as the old label asked for it - the URL of the status page.
        // The link is built from its origin, so it cannot lose /status.
        $pdo->exec("INSERT INTO settings (key_name, key_value) VALUES ('site_url', 'https://bloodkings.eu/status') ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $ch = curl_init($base . '/admin.php?action=preview_weekly_digest');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie_jar,
            CURLOPT_COOKIEFILE => $cookie_jar, CURLOPT_TIMEOUT => 30]);
        $dg_html = (string)curl_exec($ch);
        $pdo->exec("DELETE FROM settings WHERE key_name = 'site_url'");
        check_true('Digest: e-mail má sekci Routery', str_contains($dg_html, 'Routery'));
        check_true('Digest: a v ní jméno routeru i s odkazem na jeho stránku',
            str_contains($dg_html, 'Router bez metrik')
            && str_contains($dg_html, 'href="https://bloodkings.eu/status/index.php?expand=2"'));
        check_true('Digest: položka je vidět i s tím, co se naměřilo a co s tím',
            str_contains($dg_html, 'Plný oddíl') && str_contains($dg_html, 'Uvolněte místo'));
        check_true('Digest: fakta o disku jsou v e-mailu, ne poplach',
            str_contains($dg_html, 'vadné bloky za běhu: 3'));
        check_false('Digest: e-mail neobsahuje SSID', str_contains($dg_html, 'Domov'));
    }
    $pdo->prepare("UPDATE monitors SET hdd_threshold = ? WHERE id = 2")->execute([$dg_thr]);
    $pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = 2")->execute();
}


// --- The five router endpoints (CORE 3.9, WAN 3.6; CORE 6.3 tests 7-9) -------
//
// They are read-only except the mute, and the mute is the only place where a
// client could otherwise decide how loud a finding has to get before it is
// heard again. That is what most of this block is about.
if ($logged_in) {
    $ra_id = 2;                                     // the suite's OpenWrt monitor
    $ra_web = 1;                                    // a web monitor: not a router
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?")->execute([
        json_encode(['agent_version' => '0.1.7', 'agent_last_seen' => time(), 'wan_up' => true,
            'agent_tools' => ['iw' => false, 'smartctl' => true],
            'filesystems' => [['mount' => '/srv', 'fstype' => 'ext4', 'total_kb' => 2097152,
                'used_pct' => 96.0, 'avail_kb' => 60000]]], JSON_UNESCAPED_UNICODE), $ra_id]);
    $pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = ?")->execute([$ra_id]);

    [$ra_code, $ra] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id, $cookie_jar);
    check('doporučení routeru: 200 a tvar odpovědi', [$ra_code, is_array($ra['items'] ?? null),
        is_array($ra['muted'] ?? null), $ra['monitorId'] ?? null, $ra['canMute'] ?? null],
        [200, true, true, $ra_id, true]);
    check_true('doporučení routeru: plný oddíl se opravdu najde',
        in_array('fs_nearly_full', array_map(fn (array $i): string => (string)$i['id'], $ra['items']), true));
    check_true('doporučení routeru: chybějící balíček je v missingPackages i mezi položkami',
        in_array('iw', $ra['missingPackages'] ?? [], true)
        && in_array('pkg_iw', array_map(fn (array $i): string => (string)$i['id'], $ra['items']), true));
    check('doporučení routeru: GET nezapíše žádný stav',
        (int)$pdo->query("SELECT COUNT(*) FROM router_rec_state WHERE monitor_id = " . (int)$ra_id)->fetchColumn(), 0);

    [$ra_code, $ra_web_res] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_web, $cookie_jar);
    check('doporučení routeru: web monitor není router',
        [$ra_code, $ra_web_res['applicable'] ?? null, $ra_web_res['reason'] ?? null], [200, false, 'not_router']);
    [$ra_code] = api_get($base, 'action=router_recommendations&monitor_id=' . $ra_id);
    check('doporučení routeru: bez přihlášení 401', $ra_code, 401);

    // The English answer must not leak Czech: the texts are rendered per call.
    [, $ra_en] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id . '&lang=en', $cookie_jar);
    $ra_en_text = '';
    foreach ($ra_en['items'] ?? [] as $ra_item) {
        $ra_en_text .= (string)($ra_item['title'] ?? '') . (string)($ra_item['measured'] ?? '')
            . (string)($ra_item['action'] ?? '');
    }
    check_true('doporučení routeru: anglická odpověď nemá českou diakritiku',
        $ra_en_text !== '' && preg_match('/[ěščřžýáíéůúňťď]/u', $ra_en_text) === 0);

    // A Wi-Fi item over the wire (CORE 3.7). The item is stored language-
    // neutral and rendered per request, so the SAME key must come back as
    // „2,4 GHz" in Czech and "2.4 GHz" in English - and its key must survive
    // the interface being renamed, which is what a mute is stored under.
    $ra_wifi_details = json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = " . (int)$ra_id)->fetchColumn(), true);
    $ra_radio = ['radio' => 'phy3-ap0', 'phy' => 'phy3', 'ssid' => 'Domov', 'mode' => 'ap',
        'band' => '2.4GHz', 'frequency_mhz' => 2432, 'channel' => 5, 'htmode' => 'HT20',
        'htmodes_supported' => ['HT20', 'HT40'], 'encryption' => 'wpa2',
        'encryption_enterprise' => false, 'phy_has_6ghz' => false, 'clients' => 2];
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?")->execute([
        json_encode(array_merge($ra_wifi_details, ['wifi_radios' => [$ra_radio]]), JSON_UNESCAPED_UNICODE), $ra_id]);
    $ra_wifi_of = function (array $res): ?array {
        foreach ($res['items'] ?? [] as $item) {
            if ((string)($item['id'] ?? '') === 'wifi_wpa2_only') {
                return $item;
            }
        }
        return null;
    };
    // `lang=en` above stuck to the session (lang.php:11-21), so the Czech
    // call has to say `lang=cs` - exactly what the app's language switch does.
    [, $ra_w_cs] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id . '&lang=cs', $cookie_jar);
    [, $ra_w_en] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id . '&lang=en', $cookie_jar);
    $ra_item_cs = $ra_wifi_of($ra_w_cs);
    $ra_item_en = $ra_wifi_of($ra_w_en);
    check('doporučení Wi-Fi: pásmo se vykreslí v jazyce požadavku, klíč je jeden',
        [(string)($ra_item_cs['title'] ?? ''), (string)($ra_item_en['title'] ?? ''),
            (string)($ra_item_cs['key'] ?? '') === (string)($ra_item_en['key'] ?? '')],
        ['Jen WPA2 na 2,4 GHz, kanál 5', 'WPA2 only on 2.4 GHz, channel 5', true]);
    check_true('doporučení Wi-Fi: měření nese naměřenou hodnotu, ne holý pokyn',
        str_contains((string)($ra_item_cs['measured'] ?? ''), '2,4 GHz, kanál 5'));
    // The key is keyed on band + SSID, never on the interface name.
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?")->execute([
        json_encode(array_merge($ra_wifi_details, ['wifi_radios' => [array_merge($ra_radio, ['radio' => 'phy1-ap0', 'phy' => 'phy1'])]]), JSON_UNESCAPED_UNICODE), $ra_id]);
    [, $ra_w_renamed] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id . '&lang=cs', $cookie_jar);
    check('doporučení Wi-Fi: přejmenované rozhraní klíč nezmění',
        (string)($ra_wifi_of($ra_w_renamed)['key'] ?? ''), (string)($ra_item_cs['key'] ?? ''));
    // And the mute accepts that key, which is what V5 needs.
    [$ra_wm_code, $ra_wm] = api_post($base, 'action=router_recommendation_mute',
        ['monitor_id' => $ra_id, 'key' => (string)($ra_item_cs['key'] ?? ''), 'muted' => true,
            'reason' => 'stará tiskárna WPA3 neumí'], $cookie_jar);
    check('doporučení Wi-Fi: klíč rádia lze ztlumit', [$ra_wm_code, $ra_wm['ok'] ?? null], [200, true]);
    [, $ra_w_muted] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id, $cookie_jar);
    check('doporučení Wi-Fi: ztlumená položka je mezi ztlumenými, ne v seznamu',
        [$ra_wifi_of($ra_w_muted) === null,
            in_array('wifi_wpa2_only', array_map(fn (array $i): string => (string)$i['id'], $ra_w_muted['muted'] ?? []), true)],
        [true, true]);
    $pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = ?")->execute([$ra_id]);
    $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?")->execute([
        json_encode($ra_wifi_details, JSON_UNESCAPED_UNICODE), $ra_id]);

    // The mute. GET is refused, a missing token is refused, a non-admin is
    // refused, an unknown rule id is refused - and only then does it work.
    [$ra_code] = api_get_auth($base, 'action=router_recommendation_mute&monitor_id=' . $ra_id, $cookie_jar);
    check('ztlumení: GET je 405', $ra_code, 405);
    [$ra_code] = api_post($base, 'action=router_recommendation_mute',
        ['monitor_id' => $ra_id, 'key' => 'fs_nearly_full:m:x', 'muted' => true], $cookie_jar, '');
    check('ztlumení: bez CSRF tokenu 403', $ra_code, 403);
    [$ra_code] = api_post($base, 'action=router_recommendation_mute',
        ['monitor_id' => $ra_id, 'key' => 'nesmysl', 'muted' => true], $cookie_jar);
    check('ztlumení: neznámé id pravidla je 400', $ra_code, 400);

    $ra_key = (string)($ra['items'][0]['key'] ?? '');
    $ra_audit_before = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    [$ra_code, $ra_mute] = api_post($base, 'action=router_recommendation_mute',
        ['monitor_id' => $ra_id, 'key' => $ra_key, 'muted' => true, 'reason' => 'Vím o tom'], $cookie_jar);
    check('ztlumení: uloží se', [$ra_code, $ra_mute['ok'] ?? null, $ra_mute['muted'] ?? null], [200, true, true]);
    check_true('ztlumení: vznikne řádek v auditu',
        (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn() > $ra_audit_before);
    [, $ra_after] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id, $cookie_jar);
    $ra_muted_keys = array_map(fn (array $i): string => (string)$i['key'], $ra_after['muted'] ?? []);
    check_true('ztlumení: položka se přesune do muted i s důvodem',
        in_array($ra_key, $ra_muted_keys, true)
        && !in_array($ra_key, array_map(fn (array $i): string => (string)$i['key'], $ra_after['items']), true)
        && ($ra_after['muted'][0]['mute']['reason'] ?? null) === 'Vím o tom'
        && ($ra_after['muted'][0]['mute']['by'] ?? '') !== ''
        && ($ra_after['muted'][0]['mute']['at'] ?? null) !== null);
    [$ra_code] = api_post($base, 'action=router_recommendation_mute',
        ['monitor_id' => $ra_id, 'key' => $ra_key, 'muted' => false], $cookie_jar);
    [, $ra_back] = api_get_auth($base, 'action=router_recommendations&monitor_id=' . $ra_id, $cookie_jar);
    check('ztlumení: zrušení vrátí položku mezi items',
        [$ra_code, in_array($ra_key, array_map(fn (array $i): string => (string)$i['key'], $ra_back['items']), true)],
        [200, true]);

    // storage_history: shape, nulls kept, days clamped.
    [$ra_code, $ra_hist] = api_get_auth($base, 'action=storage_history&monitor_id=' . $ra_id . '&days=99999', $cookie_jar);
    check('historie disků: 200, tvar a ořezaný počet dní',
        [$ra_code, $ra_hist['monitorId'] ?? null, $ra_hist['days'] ?? null, is_array($ra_hist['disks'] ?? null)],
        [200, $ra_id, 400, true]);

    // wan_bottleneck + wan_settings_save.
    [$ra_code, $ra_wan] = api_get_auth($base, 'action=wan_bottleneck&monitor_id=' . $ra_id, $cookie_jar);
    check('rozbor WAN: 200 a oba směry mají verdikt',
        [$ra_code, $ra_wan['verdict']['dl']['class'] ?? null, $ra_wan['verdict']['ul']['reason'] ?? null],
        [200, 'inconclusive', 'not_enough_tests']);
    [$ra_code] = api_get($base, 'action=wan_bottleneck&monitor_id=' . $ra_id);
    check('rozbor WAN: bez přihlášení 401', $ra_code, 401);
    [$ra_code] = api_post($base, 'action=wan_settings_save',
        ['monitor_id' => $ra_id, 'plan_down_mbit' => 2000, 'plan_up_mbit' => 1000], $cookie_jar, '');
    check('tarif WAN: bez CSRF tokenu 403', $ra_code, 403);
    [$ra_code] = api_post($base, 'action=wan_settings_save',
        ['monitor_id' => $ra_id, 'plan_down_mbit' => 0], $cookie_jar);
    check('tarif WAN: hodnota mimo rozsah je 400, ne oříznutí', $ra_code, 400);
    [$ra_code, $ra_save] = api_post($base, 'action=wan_settings_save',
        ['monitor_id' => $ra_id, 'plan_down_mbit' => 2000, 'plan_up_mbit' => 1000, 'plan_ok_pct' => 60], $cookie_jar);
    check('tarif WAN: uloží se a vrátí se zpátky',
        [$ra_code, $ra_save['plan']['downMbit'] ?? null, $ra_save['plan']['okPct'] ?? null], [200, 2000, 60]);
    check('tarif WAN: chybějící probe_enabled nechá souhlas být',
        (int)$pdo->query("SELECT wan_probe_enabled FROM monitors WHERE id = " . (int)$ra_id)->fetchColumn(), 0);
    [$ra_code, $ra_clear] = api_post($base, 'action=wan_settings_save',
        ['monitor_id' => $ra_id, 'plan_down_mbit' => null, 'plan_up_mbit' => null, 'plan_ok_pct' => null], $cookie_jar);
    // `?? ` would read a real null as "missing", and the difference between
    // "no plan" and "a plan of zero" is the whole point of WAN 3.0.
    check('tarif WAN: prázdná hodnota tarif smaže, nenastaví nulu',
        [$ra_code, array_key_exists('downMbit', $ra_clear['plan'] ?? []), $ra_clear['plan']['downMbit']],
        [200, true, null]);
    $pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = ?")->execute([$ra_id]);
}

// --- What the digest costs at 120 routers (X13) -------------------------------
//
// The build runs on every cron minute from 08:00 to 12:00 until a send
// succeeds, on shared hosting. The bound is therefore a number and not a
// hope: at most 7 queries per chunk of 50 routers, and a router without a
// spike costs nothing extra. Measured with the server's own SELECT counter,
// so a rule that starts querying per router shows up here and not in
// production.
if (function_exists('bk_digest_routers')) {
    $selects = function () use ($pdo): int {
        $row = $pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC);
        return (int)($row['Value'] ?? 0);
    };
    $cost_ins = $pdo->prepare("INSERT INTO monitors (id, name, type, target, status, category, last_details) VALUES (?, ?, 'openwrt', '10.9.0.1', 'up', 'Síť', ?)");
    $cost_details = json_encode(['agent_version' => '0.1.7', 'agent_last_seen' => time(),
        'filesystems' => [['mount' => '/srv', 'fstype' => 'ext4', 'total_kb' => 1048576, 'used_pct' => 19.0, 'avail_kb' => 800000]]]);
    for ($i = 0; $i < 120; $i++) {
        $cost_ins->execute([9000 + $i, 'Cost router ' . $i, $cost_details]);
    }
    // The counter also sees the two SHOW statements themselves; they are not
    // SELECTs, so nothing has to be subtracted.
    $cost_before = $selects();
    $cost_data = bk_digest_routers($pdo, false);
    $cost_used = $selects() - $cost_before;
    check_true('Cena digestu: 120 routerů se sestaví ve třech dávkách', count($cost_data['routers']) === 10 && $cost_data['more'] >= 110);
    // 1 list + 3 chunks x at most 7 (X13) + the second fetch of facts, which
    // is one more batch of at most 7 plus its own details query. The bound is
    // per CHUNK, so it does not move when a router is added. A chunk that
    // holds speed tests adds two outage queries; these routers have none.
    check_true('Cena digestu: nejvýše 7 dotazů na dávku po 50 routerech (' . $cost_used . ' celkem)', $cost_used <= 1 + 4 * 7 + 1);
    check_true('Cena digestu: routery bez špiček žádný dotaz navíc', $cost_used < 120);
    $pdo->exec("DELETE FROM monitors WHERE id >= 9000 AND id < 9120");
    check('Cena digestu: testovací routery po sobě uklidí',
        (int)$pdo->query("SELECT COUNT(*) FROM monitors WHERE id >= 9000 AND id < 9120")->fetchColumn(), 0);
}

// --- Retention of the router tables (X7) -------------------------------------
//
// Disk history, recommendation state and speed tests are the three tables of
// this release that nothing else ever deletes from. The boundaries are tested
// against a real database because every one of them is a day apart from a
// deletion that cannot be undone, and because the mute exception ("a muted
// item is never pruned") only exists in the WHERE clause.
bk_test_load_functions($root . '/functions.php', ['bk_prune_router_health', 'bk_prune_wan_data']);

$pr_count = fn (string $sql): int => (int)$pdo->query($sql)->fetchColumn();
$pr_disks = "SELECT COUNT(*) FROM storage_disks WHERE monitor_id = 2";
$pr_days = "SELECT COUNT(*) FROM storage_disk_daily d JOIN storage_disks s ON s.id = d.disk_id WHERE s.monitor_id = 2";
$pr_state = "SELECT COUNT(*) FROM router_rec_state WHERE monitor_id = 2";
$pr_speed = "SELECT COUNT(*) FROM speedtest_results WHERE monitor_id = 2";

// A disk seen yesterday with one daily row just inside the window and one just
// outside it, and a second disk that has not been seen for two years.
$pdo->prepare("DELETE FROM storage_disks WHERE monitor_id = 2")->execute();
$pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = 2")->execute();
$pdo->prepare("DELETE FROM speedtest_results WHERE monitor_id = 2")->execute();
$pr_add_disk = function (string $key, int $seen_days_ago) use ($pdo): int {
    $st = $pdo->prepare(
        "INSERT INTO storage_disks (monitor_id, disk_key, name, transport, first_seen, last_seen)
         VALUES (2, ?, 'sda', 'sata', DATE_SUB(NOW(), INTERVAL 900 DAY), DATE_SUB(NOW(), INTERVAL ? DAY))"
    );
    $st->execute([$key, $seen_days_ago]);
    return (int)$pdo->lastInsertId();
};
$pr_add_day = function (int $disk_id, int $days_ago) use ($pdo): void {
    $st = $pdo->prepare(
        "INSERT INTO storage_disk_daily (disk_id, day, samples, temp_max)
         VALUES (?, DATE_SUB(CURDATE(), INTERVAL ? DAY), 1, 40)"
    );
    $st->execute([$disk_id, $days_ago]);
};
$pr_live = $pr_add_disk('pruneliveaaaaaaa', 1);
$pr_gone = $pr_add_disk('prunegoneaaaaaaa', 731);
$pr_add_day($pr_live, 729);
$pr_add_day($pr_live, 731);
$pr_add_day($pr_gone, 3);

// Recommendation state: a live item, an unmuted one just inside 90 days, an
// unmuted one past it and a muted one that is years old.
$pr_add_state = function (string $key, ?int $seen_days_ago, bool $muted) use ($pdo): void {
    $st = $pdo->prepare(
        "INSERT INTO router_rec_state (monitor_id, rec_key, rule_id, active, severity, first_seen, last_seen, muted_at)
         VALUES (2, ?, 'disk_temp_warm', 1, 'warning', DATE_SUB(NOW(), INTERVAL 400 DAY),
                 " . ($seen_days_ago === null ? "NULL" : "DATE_SUB(NOW(), INTERVAL ? DAY)") . ",
                 " . ($muted ? "NOW()" : "NULL") . ")"
    );
    $st->execute($seen_days_ago === null ? [$key] : [$key, $seen_days_ago]);
};
$pr_add_state('disk_temp_warm:d:live', 1, false);
$pr_add_state('disk_temp_warm:d:edge', 89, false);
$pr_add_state('disk_temp_warm:d:old', 91, false);
$pr_add_state('disk_temp_warm:d:muted', 400, true);
$pr_add_state('disk_temp_warm:d:never', null, false);

$pr_before = [$pr_count($pr_disks), $pr_count($pr_days), $pr_count($pr_state)];
$pr_r = bk_prune_router_health($pdo);
$pr_after = [$pr_count($pr_disks), $pr_count($pr_days), $pr_count($pr_state)];

check('Retence: denní řádek disku z -731 dní zmizí, z -729 zůstane',
    $pdo->query("SELECT DATEDIFF(CURDATE(), day) FROM storage_disk_daily d JOIN storage_disks s ON s.id = d.disk_id WHERE s.monitor_id = 2 ORDER BY day")->fetchAll(PDO::FETCH_COLUMN),
    [729]);
check('Retence: disk neviděný dva roky zmizí i se svými dny (kaskáda)',
    (int)$pdo->query("SELECT COUNT(*) FROM storage_disks WHERE monitor_id = 2 AND disk_key = 'prunegoneaaaaaaa'")->fetchColumn(), 0);
// The counts are what the three statements deleted THEMSELVES: the day row of
// the two-year-old disk disappears as well, but through the FK cascade, so it
// is in the table difference (3 -> 1) and not in `disk_daily` (1).
check('Retence: vrácené počty sedí na to, co opravdu zmizelo',
    [$pr_r['disk_daily'], $pr_r['disks'], $pr_r['rec_state'], $pr_before[1] - $pr_after[1]],
    [1, $pr_before[0] - $pr_after[0], $pr_before[2] - $pr_after[2], 2]);
check('Retence: ze stavů doporučení zůstane živý, hraničních 89 dní a ztlumený',
    $pdo->query("SELECT rec_key FROM router_rec_state WHERE monitor_id = 2 ORDER BY rec_key")->fetchAll(PDO::FETCH_COLUMN),
    ['disk_temp_warm:d:edge', 'disk_temp_warm:d:live', 'disk_temp_warm:d:muted']);
check('Retence: ztlumený stav se nemaže ani po 400 dnech',
    (int)$pdo->query("SELECT COUNT(*) FROM router_rec_state WHERE monitor_id = 2 AND rec_key = 'disk_temp_warm:d:muted'")->fetchColumn(), 1);
check('Retence: druhé volání už nemá co mazat', bk_prune_router_health($pdo), ['disk_daily' => 0, 'disks' => 0, 'rec_state' => 0]);

// Speed tests: the row lives 400 days, the per-test diagnostics 90.
$pr_add_speed = function (int $days_ago, ?string $diag) use ($pdo): void {
    $st = $pdo->prepare(
        "INSERT INTO speedtest_results (monitor_id, measured_at, download_mbps, upload_mbps, source, diagnostics)
         VALUES (2, DATE_SUB(NOW(), INTERVAL ? DAY), 300.0, 30.0, 'turris', ?)"
    );
    $st->execute([$days_ago, $diag]);
};
$pr_add_speed(401, '{"cpu_max_pct":42}');
$pr_add_speed(399, '{"cpu_max_pct":43}');
$pr_add_speed(91, '{"cpu_max_pct":44}');
$pr_add_speed(89, '{"cpu_max_pct":45}');
$pr_w = bk_prune_wan_data($pdo);
check('Retence WAN: test starší 400 dní zmizí, mladší zůstane',
    $pdo->query("SELECT DATEDIFF(NOW(), measured_at) FROM speedtest_results WHERE monitor_id = 2 ORDER BY measured_at")->fetchAll(PDO::FETCH_COLUMN),
    [399, 91, 89]);
check('Retence WAN: diagnostika se po 90 dnech vymaže, měření zůstane',
    $pdo->query("SELECT DATEDIFF(NOW(), measured_at) FROM speedtest_results WHERE monitor_id = 2 AND diagnostics IS NULL")->fetchAll(PDO::FETCH_COLUMN),
    [399, 91]);
check('Retence WAN: diagnostika mladší 90 dní zůstane',
    (int)$pdo->query("SELECT COUNT(*) FROM speedtest_results WHERE monitor_id = 2 AND diagnostics IS NOT NULL")->fetchColumn(), 1);
check('Retence WAN: vrácené počty', [$pr_w['speedtests'], $pr_w['diagnostics']], [1, 2]);
check('Retence WAN: druhé volání už nemá co mazat', bk_prune_wan_data($pdo), ['speedtests' => 0, 'diagnostics' => 0]);
$pdo->prepare("DELETE FROM storage_disks WHERE monitor_id = 2")->execute();
$pdo->prepare("DELETE FROM router_rec_state WHERE monitor_id = 2")->execute();
$pdo->prepare("DELETE FROM speedtest_results WHERE monitor_id = 2")->execute();

// --- Časová zóna databázové relace (oprava mimo release) ------------------
// db.php nastavuje zónu RELACE na aktuální posun PHP. Bez toho každé
// porovnání SQL NOW() / CURDATE() s časem naformátovaným v PHP mlčky závisí
// na tom, že databáze běží ve stejné zóně jako PHP: kontejner v UTC a
// TIMEZONE = 'Europe/Prague' hlásily u KAŽDÉHO monitoru „kontroly neběžely
// 120 minut", posun o dvě hodiny a nikde ani chyba. Sonda se pouští jako
// samostatný proces, protože se měří právě to, co udělá db.php při připojení
// (suita si otevírá vlastní PDO, které db.php nevidí).
$tz_probe_file = sys_get_temp_dir() . '/bk_tz_probe_' . getmypid() . '.php';
file_put_contents($tz_probe_file, <<<'PROBE'
<?php
require getenv('BK_TZ_DB');
$fixed = $pdo->query("SELECT @@session.time_zone AS tz, NOW() AS now")->fetch();
// Druhé spojení BEZ opravy: ukazuje, o kolik se obě strany rozcházely.
$raw_port = defined('DB_PORT') ? (int)DB_PORT : 3306;
$raw = new PDO("mysql:host=" . DB_HOST . ";port={$raw_port};dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$plain = $raw->query("SELECT NOW() AS now, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS srv_offset")->fetch();
echo '<<TZ>>' . json_encode([
    'tz'            => $fixed['tz'],
    'offset'        => date('P'),
    'php_offset'    => (int)date('Z'),
    'skew'          => strtotime($fixed['now']) - time(),
    'raw_skew'      => strtotime($plain['now']) - time(),
    'server_offset' => (int)$plain['srv_offset'],
]);
PROBE
);
$tz_out = (string)shell_exec('BK_TZ_DB=' . escapeshellarg($root . '/db.php')
    . ' php ' . escapeshellarg($tz_probe_file) . ' 2>&1');
@unlink($tz_probe_file);
$tz = preg_match('/<<TZ>>(\{.*\})/s', $tz_out, $tz_m) ? (json_decode($tz_m[1], true) ?: []) : [];

check('relace běží v aktuálním posunu PHP',
    $tz['tz'] ?? trim($tz_out), $tz['offset'] ?? 'sonda neodpověděla');
check_true('NOW() z databáze je stejný okamžik jako time() v PHP',
    isset($tz['skew']) && abs($tz['skew']) <= 2);
// Identita, která platí všude a pojmenuje chybu: neopravené spojení se míjí
// přesně o rozdíl zón databáze a PHP (v UTC kontejneru s Prahou o -7200 s).
check_true('bez nastavení zóny se NOW() míjí přesně o rozdíl zón',
    isset($tz['raw_skew'], $tz['server_offset'], $tz['php_offset'])
        && abs($tz['raw_skew'] - ($tz['server_offset'] - $tz['php_offset'])) <= 2);

// --- Router release migration (schema 20260920) ---------------------------
// The schema lint compares db.php with schema.sql as TEXT. Only a real run on
// an older database shows that the block at the end of the migrations works:
// that the speedtest columns arrive although their table is created late, and
// that the repair UPDATEs run after the column they filter on exists.
// Keep this block at the END of the suite: it drops and re-creates the router
// tables, so rows an earlier test stored in them are gone afterwards.
$column_exists = function (string $table, string $column) use ($pdo, $db_name): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$db_name, $table, $column]);
    return (int)$st->fetchColumn() === 1;
};
$table_exists = function (string $table) use ($pdo, $db_name): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $st->execute([$db_name, $table]);
    return (int)$st->fetchColumn() === 1;
};
$speedtest_row = function (string $measured_at) use ($pdo): array {
    $st = $pdo->prepare("SELECT download_mbps, upload_mbps, source FROM speedtest_results WHERE monitor_id = 2 AND measured_at = ?");
    $st->execute([$measured_at]);
    $row = $st->fetch() ?: [];
    foreach (['download_mbps', 'upload_mbps'] as $k) {
        $row[$k] = isset($row[$k]) ? round((float)$row[$k], 4) : null;
    }
    return $row;
};

// Back to the shape of 20260916: no router tables, none of the new columns.
foreach (['storage_disk_daily', 'storage_disks', 'router_rec_state'] as $old_missing) {
    $pdo->exec("DROP TABLE IF EXISTS `{$old_missing}`");
}
$pdo->exec("ALTER TABLE vps_metrics DROP COLUMN wifi_busy_5g, DROP COLUMN wan_link_flaps, DROP COLUMN clock_skew_s");
$pdo->exec("ALTER TABLE monitors DROP COLUMN wan_plan_ok_pct, DROP COLUMN wan_probe_enabled");
$pdo->exec("ALTER TABLE speedtest_results DROP COLUMN bytes_received, DROP COLUMN diagnostics");
$pdo->exec("DELETE FROM speedtest_results WHERE monitor_id = 2");
// What agents before 0.1.7 stored: a line of 1000 Mbit/s and more divided once
// too often (0.0148), next to honest results.
$old_speedtest = $pdo->prepare("INSERT INTO speedtest_results (monitor_id, measured_at, download_mbps, upload_mbps, source) VALUES (2, ?, ?, ?, ?)");
$old_speedtest->execute(['2026-09-01 05:00:00', 0.0148, 0.0087, 'librespeed']);
$old_speedtest->execute(['2026-09-02 05:00:00', 0.5, 0.0121, 'librespeed']);
$old_speedtest->execute(['2026-09-03 05:00:00', 940.25, 48.5, 'librespeed']);

$force_schema_bump();
api_get($base, 'action=public_status');
check_true('migrace routerového vydání doběhla', $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'")->fetchColumn() !== 'test-old');
check('migrace doplní starší databázi sloupce metrik, monitoru i měření rychlosti', [
    $column_exists('vps_metrics', 'wifi_busy_5g'), $column_exists('vps_metrics', 'wan_link_flaps'), $column_exists('vps_metrics', 'clock_skew_s'),
    $column_exists('monitors', 'wan_plan_ok_pct'), $column_exists('monitors', 'wan_probe_enabled'),
    $column_exists('speedtest_results', 'bytes_received'), $column_exists('speedtest_results', 'diagnostics'),
], [true, true, true, true, true, true, true]);
check('migrace založí tabulky disků a stavu doporučení', [
    $table_exists('storage_disks'), $table_exists('storage_disk_daily'), $table_exists('router_rec_state'),
], [true, true, true]);
check_false('tabulka grantů vlastního měření do téhle vlny nepatří', $table_exists('wan_probe_grants'));
check('souhlas s vlastním měřením je po migraci vypnutý', (int)$pdo->query("SELECT wan_probe_enabled FROM monitors WHERE id = 2")->fetchColumn(), 0);
check('migrace udělá z poškozené rychlosti 0.0148 neznámou a zdroj přepíše na turris', $speedtest_row('2026-09-01 05:00:00'), ['download_mbps' => null, 'upload_mbps' => null, 'source' => 'turris']);
check('migrace nechá 0.5 být a maže jen poškozený sloupec', $speedtest_row('2026-09-02 05:00:00'), ['download_mbps' => 0.5, 'upload_mbps' => null, 'source' => 'turris']);
check('zdravé měření migrace nezmění', $speedtest_row('2026-09-03 05:00:00'), ['download_mbps' => 940.25, 'upload_mbps' => 48.5, 'source' => 'turris']);

// Agent 0.1.9: a database from before the release gains the CPU column on the
// next schema bump. Without it every report's metrics INSERT would fail.
$pdo->exec("ALTER TABLE vps_metrics DROP COLUMN agent_prev_cpu_ms");
$force_schema_bump();
api_get($base, 'action=public_status');
check('migrace doplní starší databázi sloupec CPU času agenta (0.1.9)', $column_exists('vps_metrics', 'agent_prev_cpu_ms'), true);

// A later schema bump runs the same statements again. A result that a 0.1.7
// agent really measured (it carries bytes_received) must survive it, however small.
$pdo->exec("INSERT INTO speedtest_results (monitor_id, measured_at, download_mbps, upload_mbps, source, bytes_received) VALUES (2, '2026-09-04 05:00:00', 0.05, 0.04, 'agent', 93750)");
$force_schema_bump();
api_get($base, 'action=public_status');
check('opakovaná migrace nesmaže malou hodnotu, kterou agent 0.1.7 opravdu naměřil', $speedtest_row('2026-09-04 05:00:00'), ['download_mbps' => 0.05, 'upload_mbps' => 0.04, 'source' => 'agent']);
check('opakovaná migrace nechá dřív opravené řádky být', $speedtest_row('2026-09-02 05:00:00'), ['download_mbps' => 0.5, 'upload_mbps' => null, 'source' => 'turris']);
$pdo->exec("DELETE FROM speedtest_results WHERE monitor_id = 2");

// =======================================================================
// Outgoing message log - one row per attempt, written inside send_email()
// =======================================================================
// Only alerts used to be logged, so "did that invitation e-mail go out?" had
// no answer. These checks run against a real database and a real (failing)
// SMTP server, because the interesting half is the failure.
check('migrace doplní protokolu zpráv druh, předmět a způsob odeslání', [
    $column_exists('notification_log', 'kind'),
    $column_exists('notification_log', 'subject'),
    $column_exists('notification_log', 'method'),
], [true, true, true]);

$pdo->exec("DELETE FROM notification_log");

// A closed privileged port on loopback refuses the connection at once: nothing
// is really sent anywhere and the failure looks the same on every machine.
// Port 1 deliberately - an unprivileged process cannot occupy it, so the test
// cannot accidentally meet a listening server and wait out PHPMailer's timeout.
$set_setting('smtp_host', '127.0.0.1');
$set_setting('smtp_port', '1');
$set_setting('smtp_user', 'odesilatel@example.invalid');
$set_setting('smtp_pass', 'tajne');

$mail_kinds = ['invitation', 'password_reset', 'digest', 'subscriber_confirm', 'test', 'daily_reminder'];
$secret_body = '<p>TAJNE-TELO-ZPRAVY</p>';
$claimed_sent = 0;
foreach ($mail_kinds as $mk) {
    if (send_email('prijemce-' . $mk . '@example.invalid', 'Předmět ' . $mk, $secret_body, [], ['kind' => $mk])) {
        $claimed_sent++;
    }
}
check('neodeslaná zpráva se nehlásí jako odeslaná', $claimed_sent, 0);

$log_rows = $pdo->query("SELECT kind, channel, recipient, subject, ok, error_message, method, status
                         FROM notification_log ORDER BY id")->fetchAll();
check('každý pokus o odeslání má v protokolu právě jeden řádek', count($log_rows), count($mail_kinds));
check('a každý zná svůj druh zprávy', array_column($log_rows, 'kind'), $mail_kinds);
check('kanálem je e-mail', array_values(array_unique(array_column($log_rows, 'channel'))), ['email']);
check('bez události monitoru se stavem stává druh zprávy', array_column($log_rows, 'status'), $mail_kinds);
check('adresáta protokol drží', $log_rows[0]['recipient'] ?? null, 'prijemce-invitation@example.invalid');
check('předmět protokol drží', $log_rows[0]['subject'] ?? null, 'Předmět invitation');
check('selhání je zapsané jako selhání', array_values(array_unique(array_map('intval', array_column($log_rows, 'ok')))), [0]);
check_true('a nese text chyby, ne prázdno',
    str_contains((string)($log_rows[0]['error_message'] ?? ''), 'SMTP'));
// A failed attempt honestly knows no delivery route - claiming 'smtp' because
// SMTP was tried would turn the column into a guess.
// array_key_exists, ne ?? - fallback by pod testem zaměnil NULL za hodnotu.
check_true('u selhání zůstává způsob odeslání neznámý',
    array_key_exists('method', $log_rows[0] ?? []) && $log_rows[0]['method'] === null);

$body_leak = (int)$pdo->query("SELECT COUNT(*) FROM notification_log
    WHERE subject LIKE '%TAJNE-TELO%' OR error_message LIKE '%TAJNE-TELO%'
       OR recipient LIKE '%TAJNE-TELO%' OR status LIKE '%TAJNE-TELO%'")->fetchColumn();
check('tělo zprávy se do protokolu nikdy neukládá', $body_leak, 0);

// The other half: a confirmed delivery keeps the route it really used.
bk_log_notification($pdo, null, 'digest', 'email', 'admin@example.invalid', true, null, 'digest', 'Týdenní přehled', 'smtp');
$ok_row = $pdo->query("SELECT ok, method, error_message FROM notification_log ORDER BY id DESC LIMIT 1")->fetch();
check('potvrzené odeslání má ok = 1', (int)($ok_row['ok'] ?? -1), 1);
check('a pojmenuje ověřenou cestu', $ok_row['method'] ?? null, 'smtp');
check_true('a nevymýšlí si chybu',
    array_key_exists('error_message', $ok_row ?: []) && $ok_row['error_message'] === null);

$pdo->exec("DELETE FROM notification_log");
foreach (['smtp_host', 'smtp_user', 'smtp_pass'] as $smtp_key) {
    $set_setting($smtp_key, '');
}
$set_setting('smtp_port', '587');

// Retence protokolu: 180 dnů. Hranice se testuje z obou stran, protože chyba
// o den se pozná až tím, že důkaz o odeslání zmizel dřív, než na něj došlo.
$pdo->exec("DELETE FROM notification_log");
foreach ([
    'cerstvy' => 'DATE_SUB(NOW(), INTERVAL 1 HOUR)',
    'tesne_uvnitr' => 'DATE_ADD(DATE_SUB(NOW(), INTERVAL 180 DAY), INTERVAL 1 MINUTE)',
    'tesne_venku' => 'DATE_SUB(DATE_SUB(NOW(), INTERVAL 180 DAY), INTERVAL 1 MINUTE)',
    'davny' => 'DATE_SUB(NOW(), INTERVAL 400 DAY)',
] as $ret_key => $ret_when) {
    $pdo->exec("INSERT INTO notification_log (monitor_id, status, channel, recipient, ok, kind, subject, created_at)
                VALUES (NULL, '', 'email', '{$ret_key}@example.invalid', 1, 'digest', 'Retence', {$ret_when})");
}
$ret_result = bk_prune_notification_log($pdo);
check('retence smaže jen řádky za hranicí 180 dnů', $ret_result['deleted'], 2);
$ret_left = $pdo->query("SELECT recipient FROM notification_log ORDER BY recipient")->fetchAll(PDO::FETCH_COLUMN);
check('a nechá ty uvnitř, včetně řádku minutu před hranicí', $ret_left, ['cerstvy@example.invalid', 'tesne_uvnitr@example.invalid']);
// Kratší retence je volba volajícího, ne přepsaná konstanta - test si ji smí
// zavolat, aniž by čekal půl roku.
check('kratší retence se řídí parametrem', bk_prune_notification_log($pdo, 1)['deleted'], 1);
check_true('a nechá to, co je mladší než den', (int)$pdo->query("SELECT COUNT(*) FROM notification_log")->fetchColumn() === 1);
$pdo->exec("DELETE FROM notification_log");

/**
 * One raw request that keeps the response headers.
 *
 * @return array{0: int, 1: string, 2: string} status, headers, body
 */
function bk_raw_request(string $url, array $headers = [], ?string $post_body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($post_body !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $post_body;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [$code, substr($raw, 0, $size), substr($raw, $size)];
}

// =======================================================================
// The session cookie carries HttpOnly and SameSite (W1-H1).
//
// The deployed config.php starts the session without its own cookie block,
// and index.php started it even before config.php, so the admin cookie went
// out bare. db.php now sets the flags before config.php runs; this suite's
// config.php is exactly such a bare one, which makes it the right witness.
// =======================================================================
foreach (['api.php?action=session' => 'API', 'index.php' => 'veřejná stránka', 'admin.php' => 'administrace'] as $ck_path => $ck_label) {
    [, $ck_head] = bk_raw_request($base . '/' . $ck_path);
    preg_match('/^set-cookie:\s*(PHPSESSID=[^\r\n]*)/mi', $ck_head, $ck_m);
    $ck_cookie = $ck_m[1] ?? '';
    check_true("{$ck_label}: cookie relace přijde", $ck_cookie !== '');
    check_true("{$ck_label}: cookie relace má HttpOnly", (bool)preg_match('/;\s*HttpOnly/i', $ck_cookie));
    check_true("{$ck_label}: cookie relace má SameSite=Lax", (bool)preg_match('/;\s*SameSite=Lax/i', $ck_cookie));
}
[, $ck_https_head] = bk_raw_request($base . '/api.php?action=session', ['X-Forwarded-Proto: https']);
preg_match('/^set-cookie:\s*(PHPSESSID=[^\r\n]*)/mi', $ck_https_head, $ck_m);
check_true('za HTTPS proxy má cookie relace i Secure', (bool)preg_match('/;\s*Secure/i', $ck_m[1] ?? ''));

// =======================================================================
// Availability in time, through the real tables (W1-B1).
//
// run_tests.php pins the arithmetic without a database; this is the path the
// numbers really take: the rows cron and the agents write, uptime_windows,
// the cron rollup into uptime_daily and the 30-day strip. A router reported
// every five minutes for twelve hours, cron wrote its one "not responding"
// row, and it has been silent for almost eight hours since. By rows that was
// 145 up of 146 (99.3 %); in time it is 12 h up of 20 h measured. A website
// with the same rows and no silence row (cron stopped) keeps 100 %: the gap
// says nothing about the site, so it is unmeasured, not an outage.
// =======================================================================
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, created_at) VALUES
            (160, 'Router, který zmlkl', 'openwrt', 'router-b1', 'down', 'Síť', DATE_SUB(NOW(), INTERVAL 3 DAY)),
            (161, 'Web bez cronu', 'web', 'https://example.org', 'up', 'Weby', DATE_SUB(NOW(), INTERVAL 3 DAY))");
// One clock for every row: NOW() per insert let the 290 rows drift apart on
// a loaded host (43506-43532 s instead of 43500), and a flaky check is how a
// real failure gets ignored.
$b1_now = (string)$pdo->query('SELECT NOW()')->fetchColumn();
$b1_ins = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at)
                         VALUES (?, 'up', 20, DATE_SUB(?, INTERVAL ? MINUTE))");
for ($b1_min = 20 * 60; $b1_min >= 8 * 60; $b1_min -= 5) {
    $b1_ins->execute([160, $b1_now, $b1_min]);
    $b1_ins->execute([161, $b1_now, $b1_min]);
}
$pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, error_message, checked_at)
               VALUES (160, 'down', 'Agent routeru neodpovídá', DATE_SUB(?, INTERVAL 475 MINUTE))")
    ->execute([$b1_now]);
try {
    [$b1_code, $b1_uw] = api_get_auth($base, 'action=uptime_windows', $cookie_jar);
    check('uptime_windows vrací 200', $b1_code, 200);
    $b1_router = $b1_uw['windows']['160']['d1'] ?? null;
    $b1_web = $b1_uw['windows']['161']['d1'] ?? null;
    check_true('mlčící router: 24 h v čase kolem 60 %, ne 99,3 % z řádků (dostal ' . json_encode($b1_router) . ')', is_numeric($b1_router) && $b1_router > 55 && $b1_router < 62);
    check_true('web bez cronu: mezera není výpadek, 100 % z naměřeného (dostal ' . json_encode($b1_web) . ')', is_numeric($b1_web) && (float)$b1_web === 100.0);

    // The rollup cron runs every ten minutes, over the same rows.
    check_true('časový souhrn dnů se zapsal', bk_rollup_daily_uptime_time($pdo, 2) > 0);
    $b1_sum = fn (int $mid) => $pdo->query("SELECT COALESCE(SUM(secs_up), -1) AS up, COALESCE(SUM(secs_down), -1) AS down,
                                                  COALESCE(SUM(secs_silent), -1) AS silent
                                           FROM uptime_daily WHERE monitor_id = {$mid}")->fetch();
    $b1_r = $b1_sum(160);
    check('souhrn routeru: 12 h 5 min provozu', (int)$b1_r['up'], 43500);
    check('souhrn routeru: řádek „neodpovídá“ platí 2,5 intervalu', (int)$b1_r['down'], 750);
    check_true('souhrn routeru: mlčení je výpadek, ~7 h 42 min (dostal ' . $b1_r['silent'] . ' s)', abs((int)$b1_r['silent'] - 27750) < 300);
    $b1_w = $b1_sum(161);
    check('souhrn webu: bez cronu žádný výpadek ani mlčení', (int)$b1_w['down'] + (int)$b1_w['silent'], 0);

    // Today's strip day tells the silence apart from failed checks.
    [$b1_du_code, $b1_du] = api_get_auth($base, 'action=daily_uptime&days=2', $cookie_jar);
    check('daily_uptime vrací 200', $b1_du_code, 200);
    $b1_today = null;
    foreach ($b1_du['series']['160'] ?? [] as $b1_day) {
        if (($b1_day['date'] ?? '') === date('j.n.')) {
            $b1_today = $b1_day;
        }
    }
    check('dnešní den mlčícího routeru je výpadek', $b1_today['status'] ?? null, 'down');
    check_true('a popis říká, že agent mlčel (dostal ' . json_encode($b1_today['detail'] ?? null, JSON_UNESCAPED_UNICODE) . ')', str_contains((string)($b1_today['detail'] ?? ''), 'mlčel'));

    // The monthly report (report.php) counts in time as well, and a month
    // nobody measured says "bez dat" (W1-B3) - it used to say 100 % and
    // "SLA splněno" for a month without a single check.
    $b1_csv = function (int $year, int $month) use ($base, $cookie_jar): array {
        $ch = curl_init($base . '/report.php?format=csv&year=' . $year . '&month=' . $month);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie_jar, CURLOPT_TIMEOUT => 15]);
        $raw = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $rows = [];
        foreach (array_slice(explode("\n", trim($raw)), 1) as $line) {
            $cols = str_getcsv($line, ',', '"', '\\');
            $rows[(string)$cols[0]] = $cols;
        }
        return [$code, $rows, $raw];
    };
    [$b1_old_code, $b1_old, $b1_old_raw] = $b1_csv(2001, 1);
    check('CSV report za měsíc bez dat vrací 200', $b1_old_code, 200);
    check_false('CSV report neobsahuje hlášky PHP', str_contains($b1_old_raw, 'Deprecated') || str_contains($b1_old_raw, 'Warning'));
    check_true('CSV report za měsíc bez dat má řádky monitorů', count($b1_old) >= 2);
    check('měsíc bez jediné kontroly: dostupnost i SLA „bez dat“, ne 100 % a ANO',
        array_values(array_unique(array_map(fn ($r) => ($r[7] ?? '?') . '|' . ($r[9] ?? '?'), $b1_old))), ['bez dat|bez dat']);
    [, $b1_now] = $b1_csv((int)date('Y'), (int)date('n'));
    $b1_router_row = $b1_now['160'] ?? [];
    check_true('report měsíce: mlčící router pod 100 % (dostal ' . json_encode($b1_router_row[7] ?? null) . ')', is_numeric($b1_router_row[7] ?? null) && (float)$b1_router_row[7] < 100);
    check('report měsíce: SLA mlčícího routeru nesplněno', $b1_router_row[9] ?? null, 'NE');
    check_true('report měsíce: výpadek v minutách je čas ticha, ne počet řádků (dostal ' . json_encode($b1_router_row[10] ?? null) . ')', is_numeric($b1_router_row[10] ?? null) && (int)$b1_router_row[10] > 1);
} finally {
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id IN (160, 161)");
    $pdo->exec("DELETE FROM uptime_daily WHERE monitor_id IN (160, 161)");
    $pdo->exec("DELETE FROM monitors WHERE id IN (160, 161)");
}

// =======================================================================
// The public page shows a curated set (W1-G3, owner decision 5.3).
//
// It is indexed by search engines and listed every server and the home
// router by name. Servers, the router and agent services now stay off it
// unless the owner puts them on; websites and game services stay.
// =======================================================================
$g3_ids = fn (?array $list): array => array_map(fn ($m) => (int)$m['id'], $list['monitors'] ?? []);
[, $g3_anon] = api_get($base, 'action=monitors&scope=public');
check_true('veřejný seznam ukáže web', in_array(1, $g3_ids($g3_anon), true));
check_false('veřejný seznam neukáže domácí router', in_array(2, $g3_ids($g3_anon), true));
check('ani žádný server, router či službu pod agentem',
    array_values(array_filter($g3_anon['monitors'] ?? [], fn ($m) => in_array($m['type'], ['vps', 'openwrt', 'agent_service'], true))), []);
[, $g3_admin_pub] = api_get_auth($base, 'action=monitors&scope=public', $cookie_jar);
check('přihlášený admin na veřejné stránce vidí totéž co návštěvník', $g3_ids($g3_admin_pub), $g3_ids($g3_anon));
[, $g3_admin] = api_get_auth($base, 'action=monitors', $cookie_jar);
$g3_row = fn (int $id): array => array_values(array_filter($g3_admin['monitors'] ?? [], fn ($m) => (int)$m['id'] === $id))[0] ?? [];
check('admin vidí u webu, že je veřejný', $g3_row(1)['isPublic'] ?? null, true);
check('a u routeru, že veřejný není', $g3_row(2)['isPublic'] ?? null, false);
check('řádky logu jsou u routeru zapnuté', $g3_row(2)['logLinesEnabled'] ?? null, true);

[, $g3_ps] = api_get($base, 'action=public_status');
check('veřejný souhrn počítá jen veřejnou sadu', $g3_ps['totalMonitors'] ?? null, count($g3_ids($g3_anon)));
check_false('router není mezi veřejnými uzly', in_array('Router bez metrik', array_column($g3_ps['nodes'] ?? [], 'name'), true));

// The switch: a new server is hidden, the owner can put it on and hand the
// choice back to the type's default; "false" as text is refused, not read as on.
$g3_payload = ['name' => 'G3 veřejný server', 'type' => 'vps', 'target' => '', 'category' => 'Test'];
[$g3_code, $g3_created] = api_post($base, 'action=save_monitor', $g3_payload, $cookie_jar);
$g3_id = (int)($g3_created['id'] ?? 0);
check('nový server se uloží', $g3_code, 200);
[, $g3_l1] = api_get($base, 'action=monitors&scope=public');
check_false('nový server na veřejné stránce není', in_array($g3_id, $g3_ids($g3_l1), true));
api_post($base, 'action=save_monitor', $g3_payload + ['id' => $g3_id, 'is_public' => true], $cookie_jar);
[, $g3_l2] = api_get($base, 'action=monitors&scope=public');
check_true('vlastník ho na stránku dá', in_array($g3_id, $g3_ids($g3_l2), true));
api_post($base, 'action=save_monitor', $g3_payload + ['id' => $g3_id], $cookie_jar);
[, $g3_l3] = api_get($base, 'action=monitors&scope=public');
check_true('uložení bez přepínače volbu nezmění', in_array($g3_id, $g3_ids($g3_l3), true));
api_post($base, 'action=save_monitor', $g3_payload + ['id' => $g3_id, 'is_public' => null], $cookie_jar);
[, $g3_l4] = api_get($base, 'action=monitors&scope=public');
check_false('null vrátí rozhodnutí typu (server zase skrytý)', in_array($g3_id, $g3_ids($g3_l4), true));
[$g3_bad_code, $g3_bad] = api_post($base, 'action=save_monitor', $g3_payload + ['id' => $g3_id, 'is_public' => 'false'], $cookie_jar);
check('"false" jako text se odmítne', [$g3_bad_code, $g3_bad['error'] ?? null, $g3_bad['invalidKeys'] ?? null], [400, 'invalid_switch', ['is_public']]);
$pdo->prepare("DELETE FROM monitors WHERE id = ?")->execute([$g3_id]);

// Every other public read covers the same set.
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, error_message, checked_at) VALUES (2, 'down', 'Agent routeru neodpovídá', NOW())");
$g3_log = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO incidents (title, impact, status, monitor_id) VALUES ('Výpadek: Router bez metrik', 'major', 'investigating', 2)");
$g3_inc = (int)$pdo->lastInsertId();
try {
    [, $g3_ev] = api_get($base, 'action=events&monitor_id=2&limit=50');
    check('veřejné události skrytého routeru jsou prázdné', $g3_ev['events'] ?? null, []);
    [, $g3_ev_admin] = api_get_auth($base, 'action=events&monitor_id=2&limit=50', $cookie_jar);
    check_true('admin je vidí', count($g3_ev_admin['events'] ?? []) > 0);
    [, $g3_du] = api_get($base, 'action=daily_uptime&days=7&scope=public');
    check_false('veřejné denní pásy router nemají', isset($g3_du['series']['2']) || isset($g3_du['series'][2]));
    $g3_inc_mids = fn (?array $p): array => array_map(fn ($r) => (int)($r['monitor_id'] ?? $r['monitorId'] ?? 0), array_merge($p['incidents'] ?? [], $p['manualIncidents'] ?? []));
    [, $g3_inc_anon] = api_get($base, 'action=incidents&scope=public');
    check_false('veřejné incidenty skrytý router nejmenují', in_array(2, $g3_inc_mids($g3_inc_anon), true));
    [, $g3_inc_admin] = api_get_auth($base, 'action=incidents', $cookie_jar);
    check_true('admin incident routeru vidí', in_array(2, $g3_inc_mids($g3_inc_admin), true));
    [$g3_bdg] = api_get($base, 'action=badge&monitor_id=2');
    check('odznak skrytého routeru je pro návštěvníka 404', $g3_bdg, 404);
    [$g3_bdg_admin] = api_get_auth($base, 'action=badge&monitor_id=2', $cookie_jar);
    check('přihlášenému adminovi se ukáže', $g3_bdg_admin, 200);
    [, , $g3_widget] = bk_raw_request($base . '/widget.php?id=2');
    check_true('widget skrytého routeru: monitor nenalezen', str_contains($g3_widget, 'Monitor nenalezen'));
    [, , $g3_rss] = bk_raw_request($base . '/rss.php');
    check_false('RSS skrytý router nejmenuje', str_contains($g3_rss, 'Router bez metrik'));
    @unlink($root . '/cache/dashboard_agg.json');
    [$g3_idx_code, , $g3_idx] = bk_raw_request($base . '/index.php');
    check('stará veřejná stránka odpoví', $g3_idx_code, 200);
    check_false('stará veřejná stránka router nejmenuje', str_contains($g3_idx, 'Router bez metrik'));
    check_true('web na ní zůstává', str_contains($g3_idx, 'Testovací web'));
} finally {
    $pdo->prepare("DELETE FROM monitor_logs WHERE id = ?")->execute([$g3_log]);
    $pdo->prepare("DELETE FROM incidents WHERE id = ?")->execute([$g3_inc]);
}

// The default overview (api.php with no action) feeds the game portal and
// answers anyone, so it covers the public set as well. It used to take the
// first monitor whose type or name said TeamSpeak, hidden ones included, and
// hand its name, state and client count to every anonymous caller.
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, is_public, last_details)
            VALUES (180, 'Interní TeamSpeak', 'teamspeak', 'ts.example.com', 'up', 'Hry', 0, '{\"clients_online\":3,\"clients_max\":32}')");
try {
    [$ov_code, $ov, $ov_raw] = api_get($base, '');
    check('výchozí přehled vrací 200', $ov_code, 200);
    check_false('výchozí přehled skrytý TeamSpeak nejmenuje', str_contains($ov_raw, 'Interní TeamSpeak'));
    check_true('a nepřevezme jeho stav ani počet klientů (dostal ' . json_encode($ov['teamspeak'] ?? null, JSON_UNESCAPED_UNICODE) . ')',
        ($ov['teamspeak']['online'] ?? null) !== true && array_key_exists('clients_online', $ov['teamspeak'] ?? []) && $ov['teamspeak']['clients_online'] === null);
    $pdo->exec("UPDATE monitors SET is_public = 1 WHERE id = 180");
    [, $ov_pub] = api_get($base, '');
    check('veřejný TeamSpeak přehled ukáže i s počtem klientů',
        [$ov_pub['teamspeak']['name'] ?? null, $ov_pub['teamspeak']['online'] ?? null, $ov_pub['teamspeak']['clients_online'] ?? null],
        ['Interní TeamSpeak', true, 3]);
} finally {
    $pdo->exec("DELETE FROM monitors WHERE id = 180");
}

// A red strip day never says "100 %". A failed check answered 30 s later is
// 0.035 % of the day; rounded half-up to one decimal it was 100.0, and the
// strip printed "1 z 1441 kontrol selhalo (100 % dostupnost)" on a red cell.
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category) VALUES
            (181, 'Den s krátkým výpadkem', 'web', 'https://example.net', 'up', 'Test'),
            (182, 'Čistý den', 'web', 'https://example.org', 'up', 'Test')");
$rd_from = strtotime('yesterday 00:00:00');
$rd_to = strtotime('today 00:00:00');
$rd_rows = [];
for ($t = $rd_from; $t < $rd_to; $t += 60) {
    $rd_rows[] = sprintf("(181, '%s', 100, '%s')", $t === $rd_from + 720 * 60 ? 'down' : 'up', date('Y-m-d H:i:s', $t));
    $rd_rows[] = sprintf("(182, 'up', 100, '%s')", date('Y-m-d H:i:s', $t));
}
$rd_rows[] = sprintf("(181, 'up', 100, '%s')", date('Y-m-d H:i:s', $rd_from + 720 * 60 + 30));
try {
    $pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at) VALUES " . implode(',', $rd_rows));
    // The day as the ten-minute rollup writes it (bk_rollup_daily_uptime_time),
    // limited to these two monitors so the rest of the suite keeps its rows.
    $rd_ins = $pdo->prepare("INSERT INTO uptime_daily (monitor_id, day, secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach (bk_uptime_segments_for($pdo, $rd_from, $rd_to, [181, 182]) as $rd_mid => $rd_seg) {
        foreach (bk_uptime_by_day($rd_seg['segments'], $rd_seg['from'], $rd_seg['to']) as $rd_day => $rd_sum) {
            if ($rd_sum['measured'] > 0) {
                $rd_ins->execute([$rd_mid, $rd_day, $rd_sum['up'], $rd_sum['down'], $rd_sum['warning'], $rd_sum['silent'], $rd_sum['maintenance'], $rd_sum['unmeasured']]);
            }
        }
    }
    check('souhrn dne: výpadek trval 30 s', (int)$pdo->query("SELECT secs_down FROM uptime_daily WHERE monitor_id = 181 AND day = '" . date('Y-m-d', $rd_from) . "'")->fetchColumn(), 30);
    [$rd_code, $rd_du] = api_get_auth($base, 'action=daily_uptime&days=2', $cookie_jar);
    check('daily_uptime vrací 200', $rd_code, 200);
    $rd_day_of = function (int $mid) use ($rd_du, $rd_from): ?array {
        foreach ($rd_du['series'][(string)$mid] ?? [] as $day) {
            if (($day['date'] ?? '') === date('j.n.', $rd_from)) {
                return $day;
            }
        }
        return null;
    };
    $rd_bad = $rd_day_of(181);
    check('den s 30 s výpadku: červený a 99,9 %, ne 100 (dostal ' . json_encode($rd_bad, JSON_UNESCAPED_UNICODE) . ')',
        [$rd_bad['status'] ?? null, $rd_bad['uptimePct'] ?? null], ['down', 99.9]);
    check_false('a popisek netvrdí 100 % dostupnost', str_contains((string)($rd_bad['detail'] ?? ''), '(100 %'));
    $rd_ok = $rd_day_of(182);
    check('čistý den zůstane zelený a 100 %', [$rd_ok['status'] ?? null, $rd_ok['uptimePct'] ?? null], ['up', 100]);
} finally {
    $pdo->exec("DELETE FROM uptime_daily WHERE monitor_id IN (181, 182)");
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id IN (181, 182)");
    $pdo->exec("DELETE FROM monitors WHERE id IN (181, 182)");
}

// An old failure's outage ends at the first 'up' check that really follows it.
// The events list is the newest checks plus the newest failures of any age,
// and the pairing used to take the nearest newer 'up' in that list: for a
// 30-second blip three days ago that was the oldest recent check, so the
// public page showed a three-day outage that ended ten minutes ago.
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category) VALUES
            (183, 'Web s dávným výpadkem', 'web', 'https://example.com/a', 'up', 'Test'),
            (184, 'Web v otevřeném výpadku', 'web', 'https://example.com/b', 'down', 'Test')");
$op_now = time();
$op_blip = $op_now - 3 * 86400;
$op_long = $op_now - 2 * 86400;
$op_ins = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at) VALUES (?, ?, 100, ?)");
$op_at = fn (int $ts): string => date('Y-m-d H:i:s', $ts);
try {
    foreach ([[$op_blip, 'down'], [$op_blip + 30, 'up'], [$op_long, 'down'], [$op_long + 60, 'down'], [$op_long + 180, 'up']] as [$ts, $st]) {
        $op_ins->execute([183, $st, $op_at($ts)]);
    }
    for ($i = 20; $i >= 1; $i--) {
        $op_ins->execute([183, 'up', $op_at($op_now - $i * 60)]);
    }
    foreach ([[$op_now - 7200, 'up'], [$op_now - 3600, 'down'], [$op_now - 3540, 'down']] as [$ts, $st]) {
        $op_ins->execute([184, $st, $op_at($ts)]);
    }
    $op_by_time = function (array $events): array {
        $out = [];
        foreach ($events as $e) {
            if (($e['rawStatus'] ?? '') === 'down') {
                // array_key_exists, not ??: a missing key must not pass for null.
                $out[(string)$e['timeIso']] = [
                    array_key_exists('outageDurationSec', $e) ? $e['outageDurationSec'] : 'chybí',
                    array_key_exists('outageEnd', $e) ? $e['outageEnd'] : 'chybí',
                ];
            }
        }
        return $out;
    };
    [$op_code, $op_ev] = api_get($base, 'action=events&monitor_id=183&limit=10&scope=public');
    check('events veřejně vrací 200', $op_code, 200);
    $op_183 = $op_by_time($op_ev['events'] ?? []);
    check('výpadek před třemi dny trval 30 s a skončil tehdy, ne před deseti minutami',
        $op_183[date('c', $op_blip)] ?? null, [30, date('d.m.Y H:i:s', $op_blip + 30)]);
    check('dva selhané řádky za sebou končí stejnou OK kontrolou',
        [$op_183[date('c', $op_long)] ?? null, $op_183[date('c', $op_long + 60)] ?? null],
        [[180, date('d.m.Y H:i:s', $op_long + 180)], [120, date('d.m.Y H:i:s', $op_long + 180)]]);
    [, $op_open] = api_get($base, 'action=events&monitor_id=184&limit=10&scope=public');
    check('výpadek bez následné OK kontroly je otevřený: konec i délka null',
        array_values($op_by_time($op_open['events'] ?? [])), [[null, null], [null, null]]);
} finally {
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id IN (183, 184)");
    $pdo->exec("DELETE FROM monitors WHERE id IN (183, 184)");
}

// Several locations check in the same second, so "after the failure" is a
// later second or the same second written later (a higher id). A failure
// answered by another location within its own second ended then (0 s); an
// 'up' written before the failure in that second does not end it - the next
// one, a second later, does.
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category) VALUES
            (191, 'Web měřený z více míst', 'web', 'https://example.com/tie', 'up', 'Test')");
$ts_at = time() - 2 * 86400;
$ts_ins = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at, checked_from) VALUES (191, ?, 100, ?, ?)");
try {
    foreach ([
        [$ts_at, 'down', 'Praha'], [$ts_at, 'up', 'Frankfurt'],
        [$ts_at + 600, 'up', 'Frankfurt'], [$ts_at + 600, 'down', 'Praha'], [$ts_at + 601, 'up', 'Praha'],
    ] as [$ts, $st, $loc]) {
        $ts_ins->execute([$st, $op_at($ts), $loc]);
    }
    for ($i = 10; $i >= 1; $i--) {
        $ts_ins->execute(['up', $op_at($op_now - $i * 60), 'Praha']);
    }
    [$ts_code, $ts_ev] = api_get($base, 'action=events&monitor_id=191&limit=10&scope=public');
    check('events se dvěma místy vrací 200', $ts_code, 200);
    $ts_191 = $op_by_time($ts_ev['events'] ?? []);
    check('výpadek, na který jiné místo odpovědělo OK v téže sekundě, skončil tehdy: 0 s',
        $ts_191[date('c', $ts_at)] ?? null, [0, date('d.m.Y H:i:s', $ts_at)]);
    check('OK zapsané v téže sekundě před výpadkem ho neukončí; konec je OK o sekundu později',
        $ts_191[date('c', $ts_at + 600)] ?? null, [1, date('d.m.Y H:i:s', $ts_at + 601)]);
} finally {
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id = 191");
    $pdo->exec("DELETE FROM monitors WHERE id = 191");
}

// The public headline "30 days" figure is the mean of the monitors' figures.
// One second of outage in 29 days is 99.999 for that monitor; with three
// perfect ones the mean is 99.99975, which rounded at three decimals printed
// 100 on the public page over a month with an outage in it.
$av_saved = $pdo->query("SELECT id, is_public FROM monitors")->fetchAll();
$av_ins = $pdo->prepare("INSERT INTO uptime_daily (monitor_id, day, secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured)
                         VALUES (?, ?, ?, ?, 0, 0, 0, 0)");
try {
    $pdo->exec("UPDATE monitors SET is_public = 0");
    $pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, is_public, last_checked) VALUES
                (185, 'Měsíc s jednou sekundou výpadku', 'web', 'https://example.com/c', 'up', 'Test', 1, NOW()),
                (186, 'Čistý měsíc A', 'web', 'https://example.com/d', 'up', 'Test', 1, NOW()),
                (187, 'Čistý měsíc B', 'web', 'https://example.com/e', 'up', 'Test', 1, NOW()),
                (188, 'Čistý měsíc C', 'web', 'https://example.com/f', 'up', 'Test', 1, NOW())");
    for ($age = 1; $age <= 29; $age++) {
        $av_day = date('Y-m-d', strtotime("-{$age} day", strtotime('today')));
        $av_ins->execute([185, $av_day, $age === 10 ? 86399 : 86400, $age === 10 ? 1 : 0]);
        foreach ([186, 187, 188] as $av_mid) {
            $av_ins->execute([$av_mid, $av_day, 86400, 0]);
        }
    }
    [, $av_win] = api_get($base, 'action=uptime_windows&scope=public');
    check('monitor s 1 s výpadku za 29 dní: 99,999, ne 100', $av_win['windows']['185']['d30'] ?? null, 99.999);
    [, $av_ps] = api_get($base, 'action=public_status');
    check('souhrn 30 dní na veřejné stránce: 99,999, ne 100 (dostal ' . json_encode($av_ps['uptimePercent'] ?? null) . ')',
        $av_ps['uptimePercent'] ?? null, 99.999);
} finally {
    $pdo->exec("DELETE FROM uptime_daily WHERE monitor_id IN (185, 186, 187, 188)");
    $pdo->exec("DELETE FROM monitors WHERE id IN (185, 186, 187, 188)");
    $av_restore = $pdo->prepare("UPDATE monitors SET is_public = ? WHERE id = ?");
    foreach ($av_saved as $av_row) {
        $av_restore->execute([$av_row['is_public'], $av_row['id']]);
    }
}

// The rest of the places that print an availability. Five seconds of outage
// in 30 days is 99.9998 % and one failed check among 20 001 is 99.995 %; the
// badge, the widget, the legacy page, the monthly report, the SLA report's
// mean, the regions, the asset SLA, the old monitor page and the e-mail
// digest rounded both to 100. Only these two monitors are active meanwhile,
// so the means and the digest contain nothing else.
$fs_saved = $pdo->query("SELECT id, archived_at FROM monitors")->fetchAll();
$pdo->exec("INSERT INTO assets (name) VALUES ('Stroj s pěti sekundami výpadku')");
$fs_asset = (int)$pdo->lastInsertId();
$fs_day = strtotime('-3 day', strtotime('today'));
$fs_clear_caches = function () use ($pdo, $root): void {
    @unlink($root . '/cache/dashboard_agg.json');
    $pdo->exec("DELETE FROM settings WHERE key_name LIKE 'regions\\_cache\\_%'");
};
$fs_page = function (string $path, ?string $jar = null) use ($base): string {
    $ch = curl_init($base . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30];
    if ($jar !== null) {
        $opts[CURLOPT_COOKIEFILE] = $jar;
        $opts[CURLOPT_COOKIEJAR] = $jar;
    }
    curl_setopt_array($ch, $opts);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return $body;
};
try {
    $pdo->exec("UPDATE monitors SET archived_at = NOW() WHERE archived_at IS NULL");
    $pdo->prepare("INSERT INTO monitors (id, name, type, target, status, category, is_public, last_checked, asset_id) VALUES
                   (189, 'Pět sekund výpadku', 'web', 'https://example.com/g', 'up', 'Test', 1, NOW(), ?),
                   (190, 'Čistý web', 'web', 'https://example.com/h', 'up', 'Test', 1, NOW(), ?)")
        ->execute([$fs_asset, $fs_asset]);
    $fs_ins = $pdo->prepare("INSERT INTO uptime_daily (monitor_id, day, secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured)
                             VALUES (?, ?, ?, ?, 0, 0, 0, 0)");
    for ($age = 1; $age <= 29; $age++) {
        $fs_d = date('Y-m-d', strtotime("-{$age} day", strtotime('today')));
        $fs_ins->execute([189, $fs_d, $age === 3 ? 86395 : 86400, $age === 3 ? 5 : 0]);
        $fs_ins->execute([190, $fs_d, 86400, 0]);
    }
    // The same day in the check log: one failed check among 20 001, all from one location.
    $fs_rows = [];
    for ($i = 0; $i < 20001; $i++) {
        $fs_rows[] = sprintf("(189, '%s', 100, '%s', 'Zkušební region')", $i === 10000 ? 'down' : 'up', date('Y-m-d H:i:s', $fs_day + 3600 + $i * 4));
        if (count($fs_rows) === 2000 || $i === 20000) {
            $pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at, checked_from) VALUES " . implode(',', $fs_rows));
            $fs_rows = [];
        }
    }
    $fs_clear_caches();

    [, , $fs_badge] = api_get($base, 'action=badge&monitor_id=189&type=uptime');
    check_true('odznak dostupnosti: 99.99 %, ne 100.00 % (dostal ' . json_encode(preg_match('/[0-9.]+ %/', $fs_badge, $fs_m) ? $fs_m[0] : $fs_badge) . ')',
        str_contains($fs_badge, '99.99 %') && !str_contains($fs_badge, '100.00 %'));

    $fs_widget = $fs_page('/widget.php?id=189');
    check_true('widget: 99,99 %, ne 100,00 %', str_contains($fs_widget, '99,99%') && !str_contains($fs_widget, '100,00%'));

    $fs_index = $fs_page('/index.php');
    $fs_card = preg_match('/id="monitor-item-189".*?(?=id="monitor-item-|$)/s', $fs_index, $fs_m) ? $fs_m[0] : '';
    check_true('stará stránka: karta monitoru 99,99 %', str_contains($fs_card, '99,99%') && !str_contains($fs_card, '100,00%'));
    check('stará stránka: průměr za 30 dní 99,99 %, ne 100,00 %',
        preg_match('/class="stat-value [a-z]+">([^<]*)<\/div>\s*<div class="stat-label">Dostupnost 30 dní/u', $fs_index, $fs_m) ? $fs_m[1] : null, '99,99%');
    check_true('stará stránka: SLA stroje 99.99 %, ne 100.00 %', str_contains($fs_index, '99.99% SLA') && !str_contains($fs_index, '100.00% SLA'));
    check_true('stará stránka: den s výpadkem v pásu „dostupnost 99,99 %“',
        str_contains($fs_index, 'dostupnost 99,99 %') && !str_contains($fs_index, 'dostupnost 100,00'));

    $fs_month = '&year=' . date('Y', $fs_day) . '&month=' . date('n', $fs_day) . '&monitor_id=189';
    $fs_csv = $fs_page('/report.php?format=csv' . $fs_month, $cookie_jar);
    $fs_csv_row = str_getcsv((string)(explode("\n", trim($fs_csv))[1] ?? ''), ',', '"', '\\');
    check('měsíční report CSV: 99.99, ne 100.00', $fs_csv_row[7] ?? null, '99.99');
    $fs_report = $fs_page('/report.php?format=html' . $fs_month, $cookie_jar);
    check_true('měsíční report: 99,99 %, ne 100,00 %', str_contains($fs_report, '99,99%') && !str_contains($fs_report, '100,00%'));

    [, $fs_sla] = api_get_auth($base, 'action=sla_report&days=30', $cookie_jar);
    check('SLA report: průměr 99,999, ne 100 (dostal ' . json_encode($fs_sla['overallUptime'] ?? null) . ')', $fs_sla['overallUptime'] ?? null, 99.999);

    [, $fs_reg] = api_get_auth($base, 'action=regions&days=7', $cookie_jar);
    $fs_region = array_values(array_filter($fs_reg['regions'] ?? [], fn ($r) => ($r['location'] ?? null) === 'Zkušební region'))[0] ?? [];
    check('místa měření: jedna selhaná kontrola z 20 001 je 99,99 %, ne 100', $fs_region['successRate'] ?? null, 99.99);

    $fs_mon = $fs_page('/monitor.php?id=189', $cookie_jar);
    check_true('detail monitoru: karta Uptime 99.9 %, ne 100 %', str_contains($fs_mon, '>99.9%<') && !str_contains($fs_mon, '>100%<'));

    $fs_admin = $fs_page('/admin.php', $cookie_jar);
    $fs_asset_row = preg_match('/Stroj s pěti sekundami výpadku.*?<\/tr>/su', $fs_admin, $fs_m) ? $fs_m[0] : '';
    check_true('administrace: SLA stroje 99.99 %, ne 100.00 % (řádek ' . ($fs_asset_row === '' ? 'chybí' : 'nalezen') . ')',
        str_contains($fs_asset_row, '99.99%') && !str_contains($fs_asset_row, '100.00%'));

    $fs_weekly = $fs_page('/admin.php?action=preview_weekly_digest', $cookie_jar);
    check('týdenní digest: monitor s výpadkem 99.99 %, ne 100 %',
        preg_match('/Pět sekund výpadku<\/td><td[^>]*>([^<]*)</u', $fs_weekly, $fs_m) ? $fs_m[1] : null, '99.99%');
    check('týdenní digest: místo měření 99.99 %, ne 100 %',
        preg_match('/Zkušební region<\/td><td[^>]*>([^<]*)</u', $fs_weekly, $fs_m) ? $fs_m[1] : null, '99.99%');
    $fs_monthly = $fs_page('/admin.php?action=preview_monthly_digest', $cookie_jar);
    check_true('měsíční digest: den s výpadkem 99.99 %, ne 100 %',
        str_contains($fs_monthly, date('d.m.', $fs_day) . ' &middot; 99.99%') && !str_contains($fs_monthly, date('d.m.', $fs_day) . ' &middot; 100%'));
    check_true('měsíční digest: místo měření 99.99 %, ne 100 %',
        str_contains($fs_monthly, 'Zkušební region &middot; 99.99%') && !str_contains($fs_monthly, 'Zkušební region &middot; 100%'));
} finally {
    $pdo->exec("DELETE FROM uptime_daily WHERE monitor_id IN (189, 190)");
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id IN (189, 190)");
    $pdo->exec("DELETE FROM monitors WHERE id IN (189, 190)");
    $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$fs_asset]);
    $fs_restore = $pdo->prepare("UPDATE monitors SET archived_at = ? WHERE id = ?");
    foreach ($fs_saved as $fs_row) {
        $fs_restore->execute([$fs_row['archived_at'], $fs_row['id']]);
    }
    $fs_clear_caches();
}

// A failed check another location answered OK within the same second has no
// outage second of its own: the day's rollup says 0 s of down and the 30-day
// figure is exactly 100. The strip marks that day red, so the legacy card and
// the digest row next to it must not read 100 either.
$zs_saved = $pdo->query("SELECT id, archived_at FROM monitors")->fetchAll();
try {
    $pdo->exec("UPDATE monitors SET archived_at = NOW() WHERE archived_at IS NULL");
    $pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, is_public, last_checked) VALUES
                (192, 'Selhání v téže sekundě', 'web', 'https://example.com/zs', 'up', 'Test', 1, NOW())");
    $zs_ins = $pdo->prepare("INSERT INTO uptime_daily (monitor_id, day, checks_up, checks_down, secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured)
                             VALUES (192, ?, ?, ?, 86400, 0, 0, 0, 0, 0)");
    for ($age = 1; $age <= 29; $age++) {
        $zs_ins->execute([date('Y-m-d', strtotime("-{$age} day", strtotime('today'))), $age === 3 ? 3 : 1, $age === 3 ? 1 : 0]);
    }
    $zs_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at, checked_from) VALUES (192, ?, 100, ?, ?)");
    foreach ([[3600, 'up', 'Praha'], [3660, 'down', 'Praha'], [3660, 'up', 'Frankfurt'], [3720, 'up', 'Praha']] as [$zs_off, $zs_st, $zs_loc]) {
        $zs_log->execute([$zs_st, date('Y-m-d H:i:s', $fs_day + $zs_off), $zs_loc]);
    }
    $fs_clear_caches();

    $zs_index = $fs_page('/index.php');
    $zs_card = preg_match('/id="monitor-item-192".*?(?=id="monitor-item-|$)/s', $zs_index, $zs_m) ? $zs_m[0] : '';
    check_true('stará stránka: selhání bez vlastní sekundy výpadku - karta 99,99 %, ne 100,00 %',
        str_contains($zs_card, '99,99%') && !str_contains($zs_card, '100,00%'));
    $zs_weekly = $fs_page('/admin.php?action=preview_weekly_digest', $cookie_jar);
    check('týdenní digest: selhání bez vlastní sekundy výpadku 99.99 %, ne 100 %',
        preg_match('/Selhání v téže sekundě<\/td><td[^>]*>([^<]*)</u', $zs_weekly, $zs_m) ? $zs_m[1] : null, '99.99%');
} finally {
    $pdo->exec("DELETE FROM uptime_daily WHERE monitor_id = 192");
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id = 192");
    $pdo->exec("DELETE FROM monitors WHERE id = 192");
    $zs_restore = $pdo->prepare("UPDATE monitors SET archived_at = ? WHERE id = ?");
    foreach ($zs_saved as $zs_row) {
        $zs_restore->execute([$zs_row['archived_at'], $zs_row['id']]);
    }
    $fs_clear_caches();
}

// =======================================================================
// One overall verdict for public_status and the fleet badge (W1-B4).
//
// Both said "healthy" / "vše online" unless something was down: a degraded
// monitor, an unknown one and a stopped collector read as all-clear, and
// lastUpdated fell back to "now" when nothing had been measured.
// =======================================================================
$b4_saved = $pdo->query("SELECT id, status, last_checked, maintenance FROM monitors")->fetchAll();
$b4_cron = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'last_cron_run'")->fetchColumn();
$b4_set_cron = function (int $ago) use ($pdo): void {
    $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('last_cron_run', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)")
        ->execute([date('Y-m-d H:i:s', time() - $ago)]);
};
$b4_badge = function () use ($base): string {
    [, , $svg] = api_get($base, 'action=badge');
    return $svg;
};
try {
    $pdo->exec("UPDATE monitors SET status = 'up', maintenance = 0, last_checked = NOW()");
    $b4_set_cron(60);
    [, $b4_ok] = api_get($base, 'action=public_status');
    check('vše běží a sběr je čerstvý: healthy', $b4_ok['status'] ?? null, 'healthy');
    check_true('odznak flotily: vše online', str_contains($b4_badge(), 'vše online'));
    check_true('lastUpdated je skutečná poslední kontrola', is_string($b4_ok['lastUpdated'] ?? null));
    // The legacy /status/ page is indexed as well, and its headline said
    // "Všechny systémy jsou online" over a warning or a stopped collector.
    [, , $b4_idx_ok] = bk_raw_request($base . '/index.php');
    check_true('stará stránka: vše běží, říká online', str_contains($b4_idx_ok, 'Všechny systémy jsou online'));

    $pdo->exec("UPDATE monitors SET status = 'warning' WHERE id = 1");
    [, $b4_warn] = api_get($base, 'action=public_status');
    check('jeden zhoršený monitor: degraded', $b4_warn['status'] ?? null, 'degraded');
    check('a souhrn ho počítá', $b4_warn['warningMonitors'] ?? null, 1);
    $b4_warn_svg = $b4_badge();
    check_false('odznak flotily s varováním netvrdí „vše online“', str_contains($b4_warn_svg, 'vše online'));
    check_true('ale „zhoršeno“', str_contains($b4_warn_svg, 'zhoršeno'));
    [, , $b4_idx_warn] = bk_raw_request($base . '/index.php');
    check_false('stará stránka s varováním netvrdí „online“', str_contains($b4_idx_warn, 'Všechny systémy jsou online'));
    check_true('ale že jsou služby omezené', str_contains($b4_idx_warn, 'Některé služby jsou omezené'));

    $pdo->exec("UPDATE monitors SET status = 'up' WHERE id = 1");
    $b4_set_cron(7200);
    [, $b4_stale] = api_get($base, 'action=public_status');
    check('zastavený sběr: unknown, ne healthy', $b4_stale['status'] ?? null, 'unknown');
    check_true('odznak flotily: neznámý', str_contains($b4_badge(), 'neznámý'));
    [, , $b4_idx_stale] = bk_raw_request($base . '/index.php');
    check_true('stará stránka při zastaveném sběru: stav nezjištěn', str_contains($b4_idx_stale, 'Stav se nepodařilo zjistit')
        && !str_contains($b4_idx_stale, 'Všechny systémy jsou online'));

    $b4_set_cron(60);
    $pdo->exec("UPDATE monitors SET status = 'maintenance' WHERE id = 1");
    [, $b4_maint] = api_get($base, 'action=public_status');
    check('údržba: maintenance', $b4_maint['status'] ?? null, 'maintenance');

    $pdo->exec("UPDATE monitors SET status = 'unknown', last_checked = NULL");
    [, $b4_none] = api_get($base, 'action=public_status');
    check('nic nezměřeno: lastUpdated null, ne „teď“', array_key_exists('lastUpdated', $b4_none ?? []) ? $b4_none['lastUpdated'] : 'chybí', null);
    check('a verdikt unknown', $b4_none['status'] ?? null, 'unknown');

    // Nodes: unknown and maintenance keep their own words (were "offline").
    $pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, is_public, last_checked) VALUES (171, 'B4 uzel', 'vps', '', 'maintenance', 'Test', 1, NOW())");
    [, $b4_nodes] = api_get($base, 'action=public_status');
    $b4_node = array_values(array_filter($b4_nodes['nodes'] ?? [], fn ($n) => $n['name'] === 'B4 uzel'))[0] ?? [];
    check('uzel v údržbě je maintenance, ne offline', $b4_node['status'] ?? null, 'maintenance');
    check_false('web mezi uzly není (dřív tam byl kvůli last_details)', in_array('Testovací web', array_column($b4_nodes['nodes'] ?? [], 'name'), true));
} finally {
    $pdo->exec("DELETE FROM monitors WHERE id = 171");
    $b4_restore = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = ?, maintenance = ? WHERE id = ?");
    foreach ($b4_saved as $b4_row) {
        $b4_restore->execute([$b4_row['status'], $b4_row['last_checked'], $b4_row['maintenance'], $b4_row['id']]);
    }
    if ($b4_cron === false) {
        $pdo->exec("DELETE FROM settings WHERE key_name = 'last_cron_run'");
    } else {
        $pdo->prepare("UPDATE settings SET key_value = ? WHERE key_name = 'last_cron_run'")->execute([$b4_cron]);
    }
}

// =======================================================================
// Windows longer than 30 days read the daily rollup (W1-B2).
//
// The raw logs are kept for 30 days: "Rok" counted a month of rows under a
// one-year label, and response_time at 1y drew the last 24 hours. A monitor
// with 21 rolled-up days 40-60 days ago and a few checks today.
// =======================================================================
$b2_day = fn (int $ago): string => date('Y-m-d', strtotime('-' . $ago . ' day', strtotime('today')));
$pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, created_at) VALUES (170, 'B2 dlouhé okno', 'web', 'https://example.org', 'up', 'Test', DATE_SUB(NOW(), INTERVAL 70 DAY))");
$b2_ins = $pdo->prepare("INSERT INTO uptime_daily (monitor_id, day, checks_total, checks_up, checks_down, checks_warning, avg_response_ms,
    secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured) VALUES (170, ?, 288, 276, 12, 0, ?, 82800, 3600, 0, 0, 0, 0)");
for ($b2_ago = 40; $b2_ago <= 60; $b2_ago++) {
    $b2_ins->execute([$b2_day($b2_ago), 100 + $b2_ago]);
}
$pdo->exec("INSERT INTO monitor_logs (monitor_id, status, response_time, checked_at) VALUES
    (170, 'up', 90, DATE_SUB(NOW(), INTERVAL 10 MINUTE)), (170, 'up', 95, DATE_SUB(NOW(), INTERVAL 5 MINUTE)), (170, 'up', 99, NOW())");
try {
    [, $b2_uw] = api_get_auth($base, 'action=uptime_windows', $cookie_jar);
    $b2_row = $b2_uw['windows']['170'] ?? ($b2_uw['windows'][170] ?? []);
    check('30 dní: jen dnešek, 100 %', $b2_row['d30'] ?? null, 100);
    check_true('90 dní čte denní souhrny a liší se od 30 dní (dostal ' . json_encode($b2_row['d90'] ?? null) . ')', is_numeric($b2_row['d90'] ?? null) && $b2_row['d90'] < 99 && $b2_row['d90'] > 90);
    check('okno 90 dní řekne, od kdy má data', $b2_row['since'] ?? null, $b2_day(60));
    check('a kde okno začíná', $b2_uw['windowStart'] ?? null, ['d7' => $b2_day(6), 'd30' => $b2_day(29), 'd90' => $b2_day(89)]);

    [, $b2_year] = api_get_auth($base, 'action=sla_report&days=365', $cookie_jar);
    $b2_sla = array_values(array_filter($b2_year['monitors'] ?? [], fn ($m) => (int)$m['id'] === 170))[0] ?? [];
    check('rok: okno začíná před 364 dny', $b2_year['windowStart'] ?? null, $b2_day(364));
    check('rok: řádek řekne, od kdy má data', $b2_sla['since'] ?? null, $b2_day(60));
    check_true('rok: souhrn nezačíná později než nejstarší řádek', is_string($b2_year['since'] ?? null) && $b2_year['since'] <= $b2_day(60));
    check('rok: počet kontrol je z denních souhrnů, ne z měsíce logů', $b2_sla['totalChecks'] ?? null, 21 * 288 + 3);
    check('rok: výpadky kontrol taky', $b2_sla['downChecks'] ?? null, 21 * 12);
    check('percentily odezvy řeknou, že pokrývají 30 dní', $b2_year['percentileDays'] ?? null, 30);
    [, $b2_month] = api_get_auth($base, 'action=sla_report&days=30', $cookie_jar);
    $b2_sla30 = array_values(array_filter($b2_month['monitors'] ?? [], fn ($m) => (int)$m['id'] === 170))[0] ?? [];
    check('30 dní: kontroly z logů jako dřív', $b2_sla30['totalChecks'] ?? null, 3);

    [, $b2_rt] = api_get_auth($base, 'action=metric_series&monitor_id=170&metric=response_time&period=1y', $cookie_jar);
    check('odezva za rok: denní rozlišení', $b2_rt['resolution'] ?? null, 'daily');
    check('odezva za rok: 21 denních bodů, ne posledních 24 h', count($b2_rt['points'] ?? []), 21);
    $b2_first = $b2_rt['points'][0] ?? [null, null];
    check('první bod je nejstarší den se souhrnem', [$b2_first[0], (float)$b2_first[1]], [strtotime($b2_day(60) . ' 00:00:00'), 160.0]);
    $b2_band = $b2_rt['dailyRange'][0] ?? [];
    check('souhrn odezvy nemá min/max, pás se nevymýšlí',
        [array_key_exists('min', $b2_band) ? $b2_band['min'] : 'chybí', array_key_exists('max', $b2_band) ? $b2_band['max'] : 'chybí'], [null, null]);
    [, $b2_rt90] = api_get_auth($base, 'action=metric_series&monitor_id=170&metric=response_time&period=90d', $cookie_jar);
    check('odezva za 90 dní: stejné dny', count($b2_rt90['points'] ?? []), 21);
    [, $b2_prev] = api_get_auth($base, 'action=metric_series&monitor_id=170&metric=response_time&period=90d&previous=1', $cookie_jar);
    check('předchozí okno 90 dní je o periodu zpět (prázdné)', $b2_prev['points'] ?? null, []);
    [$b2_batch_code, $b2_batch] = api_get_auth($base, 'action=metric_series_batch&monitor_id=170&period=1y', $cookie_jar);
    check('dávka grafů rok odmítne místo 24 h', [$b2_batch_code, $b2_batch['error'] ?? null], [400, 'period_unsupported']);
} finally {
    $pdo->exec("DELETE FROM monitor_logs WHERE monitor_id = 170");
    $pdo->exec("DELETE FROM uptime_daily WHERE monitor_id = 170");
    $pdo->exec("DELETE FROM monitors WHERE id = 170");
}

// =======================================================================
// Insights: paged, no cap of eight, one cache per language (W1-B6).
//
// The Insights page reads the server's findings. The list stopped at eight,
// and one cache served whichever language filled it first to everybody.
// =======================================================================
$b6_items = fn (string $word): array => array_map(fn ($i) => ['monitorId' => 1, 'monitorName' => 'Testovací web',
    'kind' => 'trend', 'text' => "{$word} {$i}", 'detail' => ''], range(1, 10));
$b6_store = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
$b6_store->execute(['dashboard_insights_cache_cs', json_encode(['at' => time(), 'insights' => $b6_items('Česky')], JSON_UNESCAPED_UNICODE)]);
$b6_store->execute(['dashboard_insights_cache_en', json_encode(['at' => time(), 'insights' => $b6_items('English')], JSON_UNESCAPED_UNICODE)]);
try {
    [, $b6_all] = api_get_auth($base, 'action=dashboard_insights&limit=100&lang=cs', $cookie_jar);
    check('postřehy: všech deset, žádný strop osmi', count($b6_all['insights'] ?? []), 10);
    check('a celkový počet', $b6_all['total'] ?? null, 10);
    [, $b6_page] = api_get_auth($base, 'action=dashboard_insights&limit=3&offset=3&lang=cs', $cookie_jar);
    check('druhá stránka po třech začíná čtvrtým', array_column($b6_page['insights'] ?? [], 'text'), ['Česky 4', 'Česky 5', 'Česky 6']);
    check('a řekne svůj posun', $b6_page['offset'] ?? null, 3);
    [, $b6_default] = api_get_auth($base, 'action=dashboard_insights&lang=cs', $cookie_jar);
    check('přehled bez parametrů dostane čtyři jako dřív', count($b6_default['insights'] ?? []), 4);
    [, $b6_en] = api_get_auth($base, 'action=dashboard_insights&limit=1&lang=en', $cookie_jar);
    check('anglicky se čte anglická cache', $b6_en['insights'][0]['text'] ?? null, 'English 1');
    [, $b6_cs] = api_get_auth($base, 'action=dashboard_insights&limit=1&lang=cs', $cookie_jar);
    check('česky česká', $b6_cs['insights'][0]['text'] ?? null, 'Česky 1');
    [, $b6_wo] = api_get_auth($base, 'action=websites_overview', $cookie_jar);
    check('přehled webů nese mez upozornění na certifikát', $b6_wo['sslAlertDays'] ?? null, 14);
} finally {
    $pdo->exec("DELETE FROM settings WHERE key_name IN ('dashboard_insights_cache_cs', 'dashboard_insights_cache_en')");
}

// =======================================================================
// The router's last error lines (W1-C3, owner decision 5.7).
//
// Agent 0.1.8 sends up to five masked lines. The server masks them again,
// keeps them in last_details only, and a monitor switched off keeps none and
// tells the agent so with an unspaced "log_lines":false it matches literally.
// =======================================================================
$c3_post = function (array $extra) use ($base, $agent_payload): array {
    [$code, , $body] = bk_raw_request($base . '/agent_api.php', ['Content-Type: application/json'],
        (string)json_encode(array_merge($agent_payload, $extra), JSON_UNESCAPED_UNICODE));
    return [$code, $body];
};
$c3_details = fn (): array => json_decode((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), true) ?: [];
$c3_lines = [
    'log_errors_recent' => [
        ['ts' => 1758600000, 'prog' => 'netifd', 'msg' => 'Interface wan (10.20.30.40) is down', 'count' => 3],
        ['ts' => 1758590000, 'prog' => 'kernel', 'msg' => 'eth1: link down aa:bb:cc:dd:ee:ff', 'count' => 1],
    ],
    'log_window_secs' => 5400,
    'log_lines_state' => 'on',
];
try {
    [$c3_code, $c3_body] = $c3_post($c3_lines);
    check('hlášení s řádky logu agent přijme', $c3_code, 200);
    check_true('odpověď řekne "log_lines":true', str_contains($c3_body, '"log_lines":true'));
    $c3_d = $c3_details();
    check('řádky se uloží, adresy zamaskované i u starého agenta', array_column($c3_d['log_errors_recent'] ?? [], 'msg'),
        ['Interface wan (<ipv4>) is down', 'eth1: link down <mac>']);
    check('okno logu se uloží', $c3_d['log_window_secs'] ?? null, 5400);
    check('stav se uloží', $c3_d['log_lines_state'] ?? null, 'on');
    check_false('syrová adresa v details není', str_contains((string)$pdo->query("SELECT last_details FROM monitors WHERE id = 2")->fetchColumn(), '10.20.30.40'));

    $pdo->exec("UPDATE monitors SET log_lines_enabled = 0 WHERE id = 2");
    [, $c3_off_body] = $c3_post($c3_lines);
    check_true('vypnuto u monitoru: odpověď "log_lines":false', str_contains($c3_off_body, '"log_lines":false'));
    $c3_off = $c3_details();
    check('vypnuto: žádný řádek se neuloží', array_key_exists('log_errors_recent', $c3_off) ? $c3_off['log_errors_recent'] : 'chybí', null);
    check('vypnuto: stav řekne, že u monitoru', $c3_off['log_lines_state'] ?? null, 'off_monitor');
    [, $c3_admin] = api_get_auth($base, 'action=monitors', $cookie_jar);
    $c3_row = array_values(array_filter($c3_admin['monitors'] ?? [], fn ($m) => (int)$m['id'] === 2))[0] ?? [];
    check('admin vidí přepínač vypnutý', $c3_row['logLinesEnabled'] ?? null, false);
} finally {
    $pdo->exec("UPDATE monitors SET log_lines_enabled = 1 WHERE id = 2");
}

// =======================================================================
// The branded error page in both languages, absolute links (W1-F3).
// =======================================================================
[$f3_code, $f3_head, $f3_body] = bk_raw_request($base . '/error.php?code=410', ['Accept-Language: en-US,en;q=0.9']);
check('chybová stránka 410 odpoví 410', $f3_code, 410);
check_true('anglicky podle prohlížeče', str_contains($f3_body, '<html lang="en">') && str_contains($f3_body, 'This page is gone'));
check_true('tlačítka vedou na /app/public a /status/', str_contains($f3_body, 'href="/app/public"') && str_contains($f3_body, 'href="/status/"'));
check_false('a ne na administraci', str_contains($f3_body, 'admin.php'));
check_true('stránka se neindexuje', str_contains($f3_body, '<meta name="robots" content="noindex">') && (bool)preg_match('/^x-robots-tag:\s*noindex/mi', $f3_head));
[$f3_503, $f3_503_head, $f3_503_body] = bk_raw_request($base . '/error.php?code=503', ['Accept-Language: cs']);
check('chybová stránka 503 odpoví 503 s Retry-After', [$f3_503, (bool)preg_match('/^retry-after:\s*60/mi', $f3_503_head)], [503, true]);
check_true('česky', str_contains($f3_503_body, 'Služba je dočasně nedostupná'));
[$f3_other] = bk_raw_request($base . '/error.php?code=418');
check('neznámý kód je 404', $f3_other, 404);

// =======================================================================
// A failed query is a 5xx JSON error, never 200 with an empty list (W1-A2).
//
// Catch blocks answered `monitors: []`, `incidents: []`, `series: {}` with a
// 200, and every reader turned that into "all online", "no outages" or
// "nothing in the database" exactly when nothing was known.
// =======================================================================

// 1. Statically: no catch block in api.php echoes a body without an error
//    status. A catch may log, rethrow, fall back to null or go on - but when
//    it answers, it answers with 4xx/5xx (bk_api_fail or http_response_code).
$a2_tokens = token_get_all((string)file_get_contents($root . '/api.php'));
$a2_bad = [];
$a2_count = 0;
for ($i = 0, $n = count($a2_tokens); $i < $n; $i++) {
    if (!is_array($a2_tokens[$i]) || $a2_tokens[$i][0] !== T_CATCH) {
        continue;
    }
    $a2_count++;
    $a2_line = $a2_tokens[$i][2];
    $j = $i;
    while ($j < $n && $a2_tokens[$j] !== '{') {
        $j++;
    }
    $depth = 0;
    $body = '';
    for ($k = $j; $k < $n; $k++) {
        $t = $a2_tokens[$k];
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;
        } elseif ($t === '}' && --$depth === 0) {
            break;
        }
        $body .= is_array($t) ? $t[1] : $t;
    }
    $answers = (bool)preg_match('/\b(echo|print)\b/', $body);
    $error_status = (bool)preg_match('/bk_api_fail\s*\(|http_response_code\s*\([^;]*\b[45]\d\d\b/', $body);
    if ($answers && !$error_status) {
        $a2_bad[] = 'api.php:' . $a2_line;
    }
    if (trim($body, " \t\n\r{") === '') {
        // An empty catch hides the failure from everyone, the log included.
        $a2_bad[] = 'api.php:' . $a2_line . ' (prázdný catch)';
    }
}
check_true('api.php má bloky catch ke kontrole', $a2_count > 50);
check('žádný catch v api.php neodpovídá úspěchem ani nemlčí', $a2_bad, []);

// 2. For real: the two tables almost every read touches disappear for a
//    moment, and each list endpoint must say it failed.
$pdo->exec("RENAME TABLE monitor_logs TO monitor_logs_a2, vps_metrics TO vps_metrics_a2");
try {
    $a2_actions = [
        'monitors' => 'monitors',
        'incidents' => 'incidents',
        'daily_uptime&days=7' => 'series',
        'uptime_windows' => 'windows',
        'sla_report&days=30' => 'monitors',
        'audit_logs&limit=5' => 'logs',
        'public_status' => 'status',
        'metric_series&monitor_id=1&metric=response_time&period=24h' => 'points',
        'metric_series_batch&monitor_id=1&period=24h' => 'series',
        'metric_heatmap&monitor_id=1&metric=response_time&days=7' => 'days',
        'metrics_history&monitor_id=2&period=24h' => 'labels',
        'websites_overview' => 'monitors',
    ];
    // The overview is cached for ten minutes; a cached answer is not the read under test.
    $pdo->exec("DELETE FROM settings WHERE key_name = 'websites_overview_cache'");
    foreach ($a2_actions as $a2_query => $a2_list_key) {
        [$a2_code, $a2_json, $a2_raw] = api_get_auth($base, 'action=' . $a2_query, $cookie_jar);
        $a2_label = explode('&', $a2_query)[0];
        check_true("selhaný dotaz, {$a2_label}: 5xx (dostal {$a2_code})", $a2_code >= 500 && $a2_code < 600);
        check_true("selhaný dotaz, {$a2_label}: JSON s kódem chyby", is_array($a2_json) && is_string($a2_json['error'] ?? null) && $a2_json['error'] !== '');
        check_false("selhaný dotaz, {$a2_label}: žádný prázdný seznam vedle chyby", is_array($a2_json) && array_key_exists($a2_list_key, $a2_json));
        check_false("selhaný dotaz, {$a2_label}: nic neprozradí", str_contains($a2_raw, 'SQLSTATE') || str_contains($a2_raw, 'monitor_logs'));
    }
} finally {
    $pdo->exec("RENAME TABLE monitor_logs_a2 TO monitor_logs, vps_metrics_a2 TO vps_metrics");
}
[$a2_after] = api_get_auth($base, 'action=monitors', $cookie_jar);
check('po obnovení tabulek monitory zase odpovídají', $a2_after, 200);

// =======================================================================
// Cloudflare Worker history (agents e13a2d4): until then the Worker put its
// egress IP's country on every colo, and production shows "🇺🇸 Mumbai, US"
// in the regions list and in every event. The schema migration gives those
// rows the colo's country and leaves every other label as it was.
// =======================================================================
$cf_suffix = ' (AS13335 Cloudflare)';
// The version db.php stores after the migrations, read from its source: a
// later bump (or a merge that needs a third value) keeps these checks true.
$cf_version = preg_match("/BK_SCHEMA_VERSION',\s*'([0-9a-z]+)'/", (string)file_get_contents($root . '/db.php'), $cf_v) ? $cf_v[1] : null;
check_true('db.php definuje BK_SCHEMA_VERSION', $cf_version !== null);
$cf_seed = [
    // [minutes ago, status, label, label after the migration]
    [180, 'up', '🇺🇸 Mumbai, US' . $cf_suffix, '🇮🇳 Mumbai, IN' . $cf_suffix],
    [120, 'up', '🇺🇸 Mumbai, US' . $cf_suffix, '🇮🇳 Mumbai, IN' . $cf_suffix],
    [100, 'up', '🇺🇸 TXL, US' . $cf_suffix, '🇩🇪 Berlin, DE' . $cf_suffix],
    [90, 'down', '🇺🇸 Frankfurt, US' . $cf_suffix, '🇩🇪 Frankfurt, DE' . $cf_suffix],
    // Only the flag is wrong - to the table's unicode_ci the same value as
    // the correct label below, which is older: grouped in unicode_ci, the
    // correct label would stand for both and this row would stay wrong.
    [80, 'up', '🇺🇸 Frankfurt, DE' . $cf_suffix, '🇩🇪 Frankfurt, DE' . $cf_suffix],
    [95, 'up', '🇩🇪 Frankfurt, DE' . $cf_suffix, '🇩🇪 Frankfurt, DE' . $cf_suffix],
    [5, 'up', '🇺🇸 Seattle, US' . $cf_suffix, '🇺🇸 Seattle, US' . $cf_suffix],
    [4, 'up', '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)', '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)'],
    [3, 'up', '🇺🇸 Ashburn, US' . $cf_suffix, '🇺🇸 Ashburn, US' . $cf_suffix],
    // No colo in the trace, and a colo the map does not know.
    [70, 'up', '🇺🇸 US' . $cf_suffix, '🌐 Cloudflare Edge' . $cf_suffix],
    [60, 'up', '🇺🇸 XYZ, US' . $cf_suffix, '🌐 XYZ' . $cf_suffix],
];
$cf_labels = function () use ($pdo): array {
    return $pdo->query("SELECT id, checked_from COLLATE utf8mb4_bin AS label FROM monitor_logs ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
};
$cf_snapshot = function () use ($pdo): ?array {
    $raw = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'digest_snapshot_weekly'")->fetchColumn();
    $regions = json_decode((string)$raw, true)['regions'] ?? null;
    if (is_array($regions)) {
        ksort($regions);
    }
    return $regions;
};
$cf_locations = fn (array $rows): array => array_values(array_unique(array_map(fn ($r) => (string)($r['location'] ?? ''), $rows)));
try {
    $pdo->exec("INSERT INTO monitors (id, name, type, target, status, category, is_public, last_checked) VALUES
                (195, 'Web z Cloudflare', 'web', 'https://example.com/cf', 'up', 'Test', 1, NOW())");
    $cf_ins = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_at, checked_from)
                             VALUES (195, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), ?)");
    $cf_ids = [];
    foreach ($cf_seed as [$cf_ago, $cf_status, $cf_label]) {
        $cf_ins->execute([$cf_status, $cf_status === 'up' ? 80 : null, $cf_status === 'up' ? null : 'Timeout', $cf_ago, $cf_label]);
        $cf_ids[] = (int)$pdo->lastInsertId();
    }
    $pdo->exec("INSERT INTO settings (key_name, key_value) VALUES ('digest_snapshot_weekly', '" . json_encode(['score' => 90, 'regions' => [
        '🇺🇸 Mumbai, US' . $cf_suffix => 120,
        '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)' => 30,
        '🇺🇸 Frankfurt, US' . $cf_suffix => 40,
        '🇩🇪 Frankfurt, DE' . $cf_suffix => 35,
    ]], JSON_UNESCAPED_UNICODE) . "') ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");

    // Both regions answers get cached with the wrong labels first.
    [, $cf_reg_before] = api_get_auth($base, 'action=regions&days=7', $cookie_jar);
    [, $cf_pub_before] = api_get($base, 'action=regions&days=7');
    check_true('před migrací: místa měření ukazují Mumbai s vlajkou USA',
        in_array('🇺🇸 Mumbai, US' . $cf_suffix, $cf_locations($cf_reg_before['regions'] ?? []), true)
        && in_array('🇺🇸 Mumbai, US' . $cf_suffix, $cf_locations($cf_pub_before['regions'] ?? []), true));

    $cf_before = $cf_labels();
    $force_schema_bump();
    api_get($base, 'action=public_status');
    check('migrace Cloudflare proběhla a uložila novou verzi', $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'")->fetchColumn(), $cf_version);
    $cf_after = $cf_labels();

    $cf_expected = $cf_before;
    foreach ($cf_seed as $cf_i => $cf_row) {
        $cf_expected[$cf_ids[$cf_i]] = $cf_row[3];
    }
    check('přepíšou se jen špatné štítky Cloudflare, ostatní řádky beze změny', $cf_after, $cf_expected);
    check('změněné řádky: dva Mumbai, TXL, dva Frankfurty, bez kolokace a neznámý kód',
        array_keys(array_diff_assoc($cf_after, $cf_before)), [$cf_ids[0], $cf_ids[1], $cf_ids[2], $cf_ids[3], $cf_ids[4], $cf_ids[9], $cf_ids[10]]);

    [, $cf_reg] = api_get_auth($base, 'action=regions&days=7', $cookie_jar);
    $cf_reg_rows = array_values(array_filter($cf_reg['regions'] ?? [], fn ($r) => str_contains((string)($r['location'] ?? ''), 'Cloudflare') || str_contains((string)($r['location'] ?? ''), 'RackNerd')));
    $cf_reg_locs = $cf_locations($cf_reg_rows);
    sort($cf_reg_locs);
    $cf_reg_expected = [
        '🇩🇪 Berlin, DE' . $cf_suffix,
        '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)',
        '🇩🇪 Frankfurt, DE' . $cf_suffix,
        '🇮🇳 Mumbai, IN' . $cf_suffix,
        '🇺🇸 Ashburn, US' . $cf_suffix,
        '🇺🇸 Seattle, US' . $cf_suffix,
        '🌐 Cloudflare Edge' . $cf_suffix,
        '🌐 XYZ' . $cf_suffix,
    ];
    sort($cf_reg_expected);
    check('místa měření (administrace): opravené štítky, RackNerd a neznámé město beze změny', $cf_reg_locs, $cf_reg_expected);
    $cf_fra = array_values(array_filter($cf_reg_rows, fn ($r) => ($r['location'] ?? null) === '🇩🇪 Frankfurt, DE' . $cf_suffix))[0] ?? [];
    check('Frankfurt je jedno místo se všemi třemi kontrolami, výpadek v něm', [$cf_fra['checks'] ?? null, $cf_fra['downChecks'] ?? null], [3, 1]);

    [, $cf_pub] = api_get($base, 'action=regions&days=7');
    $cf_pub_locs = $cf_locations($cf_pub['regions'] ?? []);
    check_true('veřejná místa měření: Mumbai v Indii, žádná stará vlajka',
        in_array('🇮🇳 Mumbai, IN' . $cf_suffix, $cf_pub_locs, true)
        && !in_array('🇺🇸 Mumbai, US' . $cf_suffix, $cf_pub_locs, true)
        && !in_array('🇺🇸 Frankfurt, US' . $cf_suffix, $cf_pub_locs, true));

    [$cf_ev_code, $cf_ev] = api_get($base, 'action=events&monitor_id=195&limit=50&scope=public');
    check('události veřejně vrací 200', $cf_ev_code, 200);
    $cf_ev_locs = $cf_locations($cf_ev['events'] ?? []);
    sort($cf_ev_locs);
    $cf_ev_expected = array_values(array_unique(array_column($cf_seed, 3)));
    sort($cf_ev_expected);
    check('události: každá kontrola nese opravené místo', $cf_ev_locs, $cf_ev_expected);
    $cf_down = array_values(array_filter($cf_ev['events'] ?? [], fn ($e) => ($e['rawStatus'] ?? null) === 'down'))[0] ?? [];
    check('výpadek z Frankfurtu: vlajka a země Německa', $cf_down['location'] ?? null, '🇩🇪 Frankfurt, DE' . $cf_suffix);
    [, $cf_ev_admin] = api_get_auth($base, 'action=events&monitor_id=195&limit=50', $cookie_jar);
    $cf_ev_admin_locs = $cf_locations($cf_ev_admin['events'] ?? []);
    sort($cf_ev_admin_locs);
    check('události v administraci stejně', $cf_ev_admin_locs, $cf_ev_expected);

    check('týdenní digest porovná trend pod opraveným štítkem (správný už uložený vyhrává)', $cf_snapshot(), [
        '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)' => 30,
        '🇩🇪 Frankfurt, DE' . $cf_suffix => 35,
        '🇮🇳 Mumbai, IN' . $cf_suffix => 120,
    ]);

    // A second run (every later schema bump) finds nothing to change.
    $cf_snap_first = $cf_snapshot();
    $force_schema_bump();
    api_get($base, 'action=public_status');
    check('druhý běh migrace: verze zase uložená', $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'")->fetchColumn(), $cf_version);
    check('druhý běh migrace nezmění žádný řádek', $cf_labels(), $cf_after);
    check('druhý běh migrace nezmění snímek digestu', $cf_snapshot(), $cf_snap_first);
} finally {
    $pdo->exec("DELETE FROM monitors WHERE id = 195");
    $pdo->exec("DELETE FROM settings WHERE key_name = 'digest_snapshot_weekly' OR key_name LIKE 'regions_cache_%'");
}

// =======================================================================
// The database is down (W1-A6): 503 for everyone, JSON for programs, the
// branded page for people, and not one word about why.
//
// It used to be a 500 HTML page with the PDO message in it - the database
// host, the account and "config.php" - served to browsers, to the app and to
// the agents alike. The X-BK-Test-DB-Down header points the request at a
// closed port (see the generated config.php above).
// =======================================================================
$dd_down = ['X-BK-Test-DB-Down: 1'];
$dd_leaks = ['SQLSTATE', 'config.php', 'PDO', 'Connection refused', $db_name, 'Warning', 'Fatal', 'Stack trace'];
$dd_machine = [
    'api.php?action=monitors' => [null, 'API: monitory'],
    'api.php?action=public_status' => [null, 'API: veřejný stav'],
    'agent_api.php' => ['{"agent_key":"x"}', 'příjem od agenta'],
    'node_api.php?action=get_monitors' => [null, 'API vzdálených uzlů'],
    'heartbeat.php?token=' . str_repeat('a', 48) => [null, 'heartbeat'],
];
foreach ($dd_machine as $dd_path => [$dd_post, $dd_label]) {
    [$dd_code, $dd_head, $dd_body] = bk_raw_request($base . '/' . $dd_path, $dd_down, $dd_post);
    check("databáze dole, {$dd_label}: 503", $dd_code, 503);
    check_true("databáze dole, {$dd_label}: JSON", (bool)preg_match('/^content-type:\s*application\/json/mi', $dd_head));
    check("databáze dole, {$dd_label}: kód chyby", json_decode($dd_body, true), ['error' => 'database_unavailable']);
    check_true("databáze dole, {$dd_label}: Retry-After", (bool)preg_match('/^retry-after:\s*60\b/mi', $dd_head));
}
$dd_pages = ['index.php' => 'veřejná stránka', 'admin.php' => 'administrace', 'monitor.php?id=1' => 'detail monitoru'];
foreach ($dd_pages as $dd_path => $dd_label) {
    [$dd_code, $dd_head, $dd_body] = bk_raw_request($base . '/' . $dd_path, array_merge($dd_down, ['Accept: text/html']));
    check("databáze dole, {$dd_label}: 503", $dd_code, 503);
    check_true("databáze dole, {$dd_label}: HTML", (bool)preg_match('/^content-type:\s*text\/html/mi', $dd_head));
    check_true("databáze dole, {$dd_label}: značková stránka 503", str_contains($dd_body, 'CHYBA 503') && str_contains($dd_body, 'Blood Kings Monitoring'));
}
// A program asking a page for JSON gets JSON, not HTML it cannot parse.
[$dd_code, $dd_head, $dd_body] = bk_raw_request($base . '/widget.php?id=1', array_merge($dd_down, ['Accept: application/json']));
check('databáze dole, stránka s Accept JSON: JSON', json_decode($dd_body, true), ['error' => 'database_unavailable']);
foreach (array_merge(array_keys($dd_machine), array_keys($dd_pages)) as $dd_path) {
    [, , $dd_body] = bk_raw_request($base . '/' . $dd_path, $dd_down);
    $dd_found = array_values(array_filter($dd_leaks, fn (string $leak): bool => stripos($dd_body, $leak) !== false));
    check("databáze dole, {$dd_path}: tělo nic neprozradí", $dd_found, []);
}

$failed = bk_test_report('api.php (integrační)');
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
