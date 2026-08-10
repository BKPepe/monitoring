<?php
/**
 * Integrační testy api.php proti skutečné databázi a skutečnému HTTP.
 *
 * Spuštění (vyžaduje MySQL/MariaDB a PHP CLI):
 *   BK_TEST_DB_NAME=bk_test BK_TEST_DB_USER=root BK_TEST_DB_PASS=root \
 *     php apps/status/tests/run_api_tests.php
 *
 * Proč zrovna takhle: api.php je monolit, který si sám načítá config,
 * otevírá PDO a rovnou tiskne JSON. Vytáhnout z něj funkce jako u
 * ostatních sad nejde. Zároveň jsou to právě dotazy a názvy sloupců, kde
 * se tenhle projekt opakovaně sekl (Prometheus token psal do
 * setting_key/setting_value místo key_name/key_value a endpoint vracel
 * 500; daily_uptime odkazoval na proměnnou, která už neexistovala).
 * Takové chyby odhalí jedině skutečné zavolání endpointu.
 *
 * Test si proto postaví vlastní databázi ze schema.sql, naplní ji známými
 * daty, spustí `php -S` nad apps/status a mluví s ním přes HTTP.
 */

require_once __DIR__ . '/assert_helpers.php';

$db_host = getenv('BK_TEST_DB_HOST') ?: '127.0.0.1';
$db_port = (int)(getenv('BK_TEST_DB_PORT') ?: 3306);
$db_name = getenv('BK_TEST_DB_NAME') ?: 'bk_test';
$db_user = getenv('BK_TEST_DB_USER') ?: 'root';
$db_pass = getenv('BK_TEST_DB_PASS') ?: '';
$port = (int)(getenv('BK_TEST_PORT') ?: 8123);
$root = realpath(__DIR__ . '/..');

// --- 1. Databáze ---------------------------------------------------------
try {
    $pdo = new PDO("mysql:host={$db_host};port={$db_port};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "MySQL není dostupná ({$e->getMessage()}) - integrační testy se přeskakují.\n");
    exit(0);
}

$pdo->exec("DROP DATABASE IF EXISTS `{$db_name}`");
$pdo->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$db_name}`");

// schema.sql se pouští po jednotlivých příkazech - PDO::exec zvládne víc
// dotazů najednou jen někdy a chyba by pak zůstala neviditelná.
$schema = file_get_contents($root . '/schema.sql');
$schema = preg_replace('/^\s*--.*$/m', '', $schema);
foreach (array_filter(array_map('trim', explode(";\n", $schema))) as $sql) {
    if ($sql === '') {
        continue;
    }
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // Sekvence INSERT s ukázkovými daty nejsou pro testy podstatné.
        if (!str_contains($e->getMessage(), 'Duplicate')) {
            fwrite(STDERR, "schema.sql: " . substr($e->getMessage(), 0, 120) . "\n");
        }
    }
}

// --- 2. Známá testovací data --------------------------------------------
// Web s naměřenou odezvou a SSL, agent BEZ naměřených metrik. Ten druhý je
// tu schválně: většina regresí v tomhle projektu byla o tom, že se
// nezměřená hodnota ukázala jako nula.
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
// Agent hlásí, že žije, ale metriky nezměřil - v odpovědi musí být null.
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, checked_at)
            VALUES (2, NULL, NULL, NULL, NOW())");

// --- 3. config.php pro testovací instanci --------------------------------
$config_path = $root . '/config.php';
$config_backup = file_exists($config_path) ? file_get_contents($config_path) : null;
file_put_contents($config_path, "<?php\n"
    . "ini_set('display_errors', 1);\n"
    . "error_reporting(E_ALL);\n"
    . "if (session_status() === PHP_SESSION_NONE && !headers_sent()) { @session_start(); }\n"
    . "define('DB_DRIVER', 'mysql');\n"
    . "define('DB_HOST', " . var_export($db_host, true) . ");\n"
    . "define('DB_PORT', {$db_port});\n"
    . "define('DB_NAME', " . var_export($db_name, true) . ");\n"
    . "define('DB_USER', " . var_export($db_user, true) . ");\n"
    . "define('DB_PASS', " . var_export($db_pass, true) . ");\n"
    . "define('TIMEZONE', 'Europe/Prague');\n"
    . "date_default_timezone_set(TIMEZONE);\n");

// --- 4. Vestavěný PHP server --------------------------------------------
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
    // config.php patří vývojáři, ne testu - vrací se do původního stavu.
    if ($config_backup !== null) {
        file_put_contents($config_path, $config_backup);
    } elseif (file_exists($config_path)) {
        unlink($config_path);
    }
};
register_shutdown_function($cleanup);

// Server chvíli startuje; čeká se na první úspěšné spojení.
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
        break;
    }
    usleep(100000);
}

/** Zavolá endpoint a vrátí [stavový kód, dekódované JSON, syrové tělo]. */
function api_get(string $base, string $query): array {
    $ch = curl_init($base . '/api.php?' . $query);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$body, true), (string)$body];
}

/** Cookie jar drží PHP session mezi požadavky (přihlášení admina). */
$cookie_jar = tempnam(sys_get_temp_dir(), 'bk_test_cookies');
register_shutdown_function(function () use ($cookie_jar) {
    if ($cookie_jar && file_exists($cookie_jar)) {
        unlink($cookie_jar);
    }
});

/** POST s JSON tělem; sdílí session přes cookie jar. */
function api_post(string $base, string $query, array $payload, string $jar): array {
    $ch = curl_init($base . '/api.php?' . $query);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$body, true), (string)$body];
}

/** GET se session - pro endpointy, které vrací víc přihlášenému. */
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

// =======================================================================
// 1. monitors - páteřní endpoint, volá ho každá stránka aplikace
// =======================================================================
[$code, $data, $raw] = api_get($base, 'action=monitors');
check('monitors vrací HTTP 200', $code, 200);
check_true('monitors vrací pole monitorů', isset($data['monitors']) && is_array($data['monitors']));

$by_id = [];
foreach (($data['monitors'] ?? []) as $m) {
    $by_id[$m['id']] = $m;
}
check_true('web monitor je v odpovědi', isset($by_id[1]));
check_true('agent monitor je v odpovědi', isset($by_id[2]));

// Jádro pravidla o poctivosti: nezměřené metriky jsou null, ne nula.
// array_key_exists, ne ?? - operátor ?? považuje NULL za chybějící hodnotu
// a testu na null by tím podrazil nohy.
$agent = $by_id[2] ?? [];
foreach (['cpu' => 'CPU', 'ram' => 'RAM', 'hdd' => 'disk'] as $key => $label) {
    check_true("klíč {$key} je v odpovědi přítomen", array_key_exists($key, $agent));
    check("nezměřené {$label} je null, ne 0", $agent[$key] ?? null, null);
    check_false("nezměřené {$label} není nula", ($agent[$key] ?? null) === 0 || ($agent[$key] ?? null) === 0.0);
}
check('naměřená odezva se vrací', (int)($by_id[1]['responseMs'] ?? 0), 120);

// Anonymní přístup nesmí vidět síťovou topologii.
check_true(
    'anonymní odpověď neobsahuje wan_ipv4',
    !str_contains($raw, 'wan_ipv4') && !str_contains($raw, 'wireguard_peers')
);

// =======================================================================
// 2. public_status - podklad pro veřejnou stránku i widget
// =======================================================================
[$code, $data] = api_get($base, 'action=public_status');
check('public_status vrací HTTP 200', $code, 200);
check_true('public_status zná počet monitorů', isset($data['totalMonitors']));
check('public_status počítá oba monitory', (int)($data['totalMonitors'] ?? 0), 2);

// =======================================================================
// 3. dashboard_layout - katalog dlaždic (nová funkce, dosud bez testu)
// =======================================================================
[$code, $data] = api_get($base, 'action=dashboard_layout');
check('dashboard_layout vrací HTTP 200', $code, 200);
check_true('katalog je pole', isset($data['catalog']) && is_array($data['catalog']));

$panels = array_filter($data['catalog'] ?? [], fn($c) => ($c['kind'] ?? '') === 'panel');
check_true('katalog nabízí panely dashboardu', count($panels) >= 5);

// Metrika bez jediného vzorku se nesmí nabízet jako dostupná - jinak si
// uživatel zapne dlaždici, která bude navždy prázdná.
foreach (($data['catalog'] ?? []) as $entry) {
    if (($entry['kind'] ?? '') === 'metric' && ($entry['key'] ?? '') === 'metric_cpu') {
        check('metrika bez vzorků není available', $entry['available'], false);
    }
}

// =======================================================================
// 4. websites_overview - SLA okna pro stránku webů
// =======================================================================
[$code, $data] = api_get($base, 'action=websites_overview');
check('websites_overview vrací HTTP 200', $code, 200);
check_true('vrací mapu monitorů', isset($data['monitors']) && is_array($data['monitors']));

$sla = $data['monitors'][1] ?? $data['monitors']['1'] ?? null;
check_true('web má spočítané SLA za 7 dní', $sla !== null && $sla['sla7'] !== null);
// 10 up + 1 down = 90,909 %; kdyby se 'down' ztratil, vyšlo by 100 %.
check_true(
    'SLA počítá i výpadky (není 100 %)',
    $sla !== null && $sla['sla7'] < 100 && $sla['sla7'] > 85
);

$sla_agent = $data['monitors'][2] ?? $data['monitors']['2'] ?? null;
check('monitor bez logů nemá vymyšlené SLA', $sla_agent, null);

// =======================================================================
// 5. Autorizace - měnící operace nesmí být přístupné bez přihlášení
// =======================================================================
foreach ([
    'incident_action' => 'akce nad incidentem',
    'create_incident' => 'založení incidentu',
    'save_settings' => 'uložení nastavení',
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
// 6. Neznámá akce a chybějící parametry nesmí shodit endpoint
// =======================================================================
[$code, , $raw] = api_get($base, 'action=neexistujici_akce_xyz');
check_true('neznámá akce nekončí chybou serveru', $code < 500);
check_true('odpověď neobsahuje fatální chybu', !str_contains($raw, 'Fatal error'));

[$code, , $raw] = api_get($base, 'action=sla_report&days=999999');
check_true('nesmyslný rozsah SLA nekončí chybou serveru', $code < 500);
check_true('SLA report neobsahuje fatální chybu', !str_contains($raw, 'Fatal error'));

// =======================================================================
// =======================================================================
// 7. ZAPISOVACÍ ENDPOINTY
//
// Tahle část vznikla poté, co se ukázalo, že save_monitor používal
// $preset_id, který se nikde nepřiřazoval - každé uložení monitoru tiše
// smazalo jeho preset. Čtecí testy takovou chybu nevidí; pozná ji jedině
// uložení a následné přečtení.
// =======================================================================

[$code, $login] = api_post($base, 'action=login', [
    'username' => 'admin',
    'password' => 'BloodKingsAdmin123!',
], $cookie_jar);
$logged_in = $code === 200 && !empty($login['success']);
check_true('přihlášení admina projde', $logged_in);

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
    // Prázdné pole znamená "preset ten práh neřeší" - není to nula.
    check_true('prázdný práh zůstává null', array_key_exists('ramThreshold', $saved_preset) && $saved_preset['ramThreshold'] === null);
    check('nulový práh se uloží jako nula', $saved_preset['hddThreshold'] ?? 'chybí', 0);

    // --- Monitor: preset a prahy zpomalení musí přežít uložení ----------
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
    // Přesně tohle byla ta chyba: preset se ztrácel při každém uložení.
    check('preset zůstane přiřazený', $saved_monitor['presetId'] ?? 'chybí', $preset_id);
    check('práh zpomalení se uloží', $saved_monitor['latencyThresholdMs'] ?? 'chybí', 750);
    check('okno zpomalení se uloží', $saved_monitor['latencyThresholdMins'] ?? 'chybí', 3);

    // Úprava nesmí ostatní nastavení shodit.
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

    // Vypnutí upozornění: prázdná hodnota = null, ne nula.
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

    // --- Status stránky -------------------------------------------------
    [$code, $sp] = api_post($base, 'action=save_status_page', [
        'title' => 'Veřejný přehled',
        'isPublic' => true,
        'monitorIds' => [$new_monitor_id],
    ], $cookie_jar);
    check('save_status_page vrací 200', $code, 200);
    check('slug se odvodí bez diakritiky', $sp['slug'] ?? 'chybí', 'verejny-prehled');

    // Druhá stránka se stejným slugem musí skončit srozumitelnou chybou,
    // ne pádem na databázovém indexu.
    [$code, $dup] = api_post($base, 'action=save_status_page', [
        'title' => 'Jiný název',
        'slug' => 'verejny-prehled',
    ], $cookie_jar);
    check('duplicitní slug vrací 400', $code, 400);
    check_true('duplicita má srozumitelnou hlášku', !empty($dup['error']));

    // Skrytá stránka nesmí být vidět nepřihlášenému.
    api_post($base, 'action=save_status_page', [
        'title' => 'Interní',
        'slug' => 'interni',
        'isPublic' => false,
    ], $cookie_jar);
    [, $anon_pages] = api_get($base, 'action=status_pages');
    $anon_slugs = array_column($anon_pages['pages'] ?? [], 'slug');
    check_false('skrytá stránka není vidět anonymně', in_array('interni', $anon_slugs, true));
    check_true('veřejná stránka vidět je', in_array('verejny-prehled', $anon_slugs, true));

    // --- Export konfigurace ---------------------------------------------
    [$code, , $raw_export] = api_get_auth($base, 'action=export_config', $cookie_jar);
    check('export vrací 200', $code, 200);
    $export = json_decode($raw_export, true);
    check_true('export je platný JSON', is_array($export));
    check_true('export obsahuje monitory', !empty($export['monitors']));
    check_true('export obsahuje nastavení', isset($export['settings']));

    // Tajemství v souboru ke stažení je únik na počkání - hlídá se to,
    // protože stačí přidat nový klíč s heslem a bez testu si toho nikdo
    // nevšimne.
    $leaked = [];
    foreach (array_keys($export['settings'] ?? []) as $k) {
        if (preg_match('/(pass|secret|token|key|hash|webhook)/i', $k)) {
            $leaked[] = $k;
        }
    }
    check('export neobsahuje tajemství', $leaked, []);
    check_false('export neobsahuje klíče agentů', str_contains($raw_export, 'agent_key'));
    check_false('export neobsahuje hesla ServerQuery', str_contains($raw_export, 'sq_password'));

    // Bez přihlášení nesmí export projít vůbec.
    [$anon_code] = api_get($base, 'action=export_config');
    check_true('export bez přihlášení je odmítnut', in_array($anon_code, [401, 403], true));

    // --- Úklid ----------------------------------------------------------
    [$code] = api_post($base, 'action=delete_preset', ['id' => $preset_id], $cookie_jar);
    check('smazání presetu vrací 200', $code, 200);

    [, $mlist4] = api_get_auth($base, 'action=monitors', $cookie_jar);
    foreach (($mlist4['monitors'] ?? []) as $m) {
        if ((int)$m['id'] === $new_monitor_id) {
            // Smazání presetu nesmí monitor rozbít - jen se vrátí ke svému.
            check_true('monitor po smazání presetu zůstává', array_key_exists('presetId', $m) && $m['presetId'] === null);
        }
    }
}

$failed = bk_test_report('api.php (integrační)');
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
