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

/**
 * Signál na heartbeat.php - jiný skript než api.php, proto vlastní helper.
 * Bez přihlášení: token je jediné, co endpoint autorizuje.
 */
function hb_ping(string $base, string $token, string $extra = ''): array {
    $url = $base . '/heartbeat.php?token=' . rawurlencode($token) . ($extra !== '' ? '&' . $extra : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
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

    // --- Jedna stránka podle slugu (verejna stranka v Reactu) -----------
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

    // --- Volby zobrazení status stránky ---------------------------------
    //
    // NULL v databázi = "ukázat všechno". Stránka založená před touto volbou
    // se nesmí změnit, proto se výchozí hodnoty doplňují při čtení a testují
    // se dřív než cokoliv jiného.
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

    // Neznámý klíč se nesmí uložit - do databáze jde jen whitelist.
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

// =======================================================================
// 8. Heartbeat - celý tok od založení přes signál po vyhodnocení
// =======================================================================
//
// Vyhodnocení samo má testy bez databáze (run_tests.php). Tady jde o to, co
// se dá ověřit jedině naostro: že token opravdu vznikne, že se na něj dá
// poslat signál přes HTTP, že se zapíše, a hlavně že se nedostane ven
// nikomu nepřihlášenému.
if (!empty($cookie_jar)) {
    [$code, $hb_created] = api_post($base, 'action=save_monitor', [
        'id' => 0,
        'name' => 'Noční záloha (test)',
        'type' => 'heartbeat',
        // Cíl se záměrně neposílá - heartbeat žádný nemá.
        'heartbeat_interval' => 3600,
        'heartbeat_grace' => 300,
    ], $cookie_jar);
    check('heartbeat monitor jde založit bez cíle', $code, 200);
    $hb_id = (int)($hb_created['id'] ?? 0);
    check_true('heartbeat monitor dostal id', $hb_id > 0);

    // Interval je jediné, bez čeho heartbeat nedává smysl.
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
        // array_key_exists, ne ?? - operátor by NULL prohlásil za chybějící klíč
        // a test by prošel i tehdy, kdyby endpoint pole vůbec neposílal.
        check_true('bez signálu je čas posledního signálu null', array_key_exists('lastSignalAt', $info) && $info['lastSignalAt'] === null);
        check('interval se uložil v sekundách', $info['intervalSecs'] ?? null, 3600);

        // Token nesmí ven bez přihlášení - kdo ho má, může posílat signál za nás
        // a monitor pak svítí zeleně, i když záloha dávno neběží.
        [$anon_code] = api_get($base, 'action=heartbeat_info&monitor_id=' . $hb_id);
        check_true('heartbeat_info bez přihlášení je odmítnut', in_array($anon_code, [401, 403], true));

        // Token se nesmí objevit ani v běžném seznamu monitorů.
        [, , $mon_raw] = api_get($base, 'action=monitors');
        check_true('token není v seznamu monitorů', $hb_token !== '' && !str_contains($mon_raw, $hb_token));

        // --- Samotný příjem signálu -------------------------------------
        [$hb_code, $hb_res] = hb_ping($base, $hb_token);
        check('signál s platným tokenem vrací 200', $hb_code, 200);
        check_true('odpověď potvrzuje přijetí', ($hb_res['ok'] ?? false) === true);

        [$code, $info2] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id, $cookie_jar);
        check_true('po signálu je znám čas posledního signálu', !empty($info2['lastSignalAt']));
        check('po čerstvém signálu je stav up', $info2['state'] ?? null, 'up');
        check('výsledek je ok', $info2['lastResult'] ?? null, 'ok');

        // --- Ohlášené selhání -------------------------------------------
        [$hb_code] = hb_ping($base, $hb_token, 'status=fail&msg=' . rawurlencode('tar skončil kódem 2'));
        check('signál o selhání vrací 200', $hb_code, 200);

        [, $info3] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id, $cookie_jar);
        check('ohlášené selhání sráží stav na down', $info3['state'] ?? null, 'down');
        check('výsledek je fail', $info3['lastResult'] ?? null, 'fail');
        check_true('zpráva od úlohy se uložila', str_contains((string)($info3['lastMessage'] ?? ''), 'tar'));

        // --- Neplatný token nesmí nic prozradit --------------------------
        [$bad_code] = hb_ping($base, str_repeat('a', 48));
        check('neznámý token vrací 404', $bad_code, 404);
        [$bad_code2] = hb_ping($base, 'nesmysl');
        check('token špatného tvaru vrací taky 404', $bad_code2, 404);

        // --- Výměna tokenu ----------------------------------------------
        [, $info4] = api_get_auth($base, 'action=heartbeat_info&monitor_id=' . $hb_id . '&regenerate=1', $cookie_jar);
        check_true('regenerate vyrobí jiný token', ($info4['token'] ?? '') !== $hb_token);
        [$old_code] = hb_ping($base, $hb_token);
        check('starý token po výměně přestane platit', $old_code, 404);

        api_post($base, 'action=delete_monitor', ['id' => $hb_id], $cookie_jar);
    }
}

// =======================================================================
// 9. RSS kanál - odběr místo čekání, až si stránku někdo otevře
// =======================================================================
//
// Kanál je veřejný, takže se testuje bez přihlášení. Nejdůležitější je
// poslední kontrola: skrytá stránka nesmí přes RSS vydat nic, co neukáže
// na webu - jinak by stačilo uhodnout slug a obejít tím viditelnost.
// Bez incidentů by kanál byl prázdný a testy níž by prošly, i kdyby se
// položky nikdy negenerovaly. Jeden uzavřený a jeden probíhající.
$pdo->exec("INSERT INTO incidents (id, title, impact, status, monitor_id, created_at, resolved_at)
            VALUES (901, 'Výpadek: Testovací web', 'major', 'resolved', 1,
                    DATE_SUB(NOW(), INTERVAL 3 HOUR), DATE_SUB(NOW(), INTERVAL 2 HOUR))");
$pdo->exec("INSERT INTO incidents (id, title, impact, status, monitor_id, created_at)
            VALUES (902, 'Výpadek: Router bez metrik', 'critical', 'investigating', 2,
                    DATE_SUB(NOW(), INTERVAL 20 MINUTE))");

$rss_ch = curl_init($base . '/rss.php');
curl_setopt_array($rss_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true]);
$rss_raw = (string)curl_exec($rss_ch);
$rss_code = (int)curl_getinfo($rss_ch, CURLINFO_RESPONSE_CODE);
$rss_ctype = (string)curl_getinfo($rss_ch, CURLINFO_CONTENT_TYPE);
$rss_body = substr($rss_raw, curl_getinfo($rss_ch, CURLINFO_HEADER_SIZE));

check('RSS kanál vrací 200', $rss_code, 200);
check_true('RSS má správný Content-Type', str_contains($rss_ctype, 'application/rss+xml'));

// Platnost XML se ověřuje parserem, ne hledáním podřetězců: neescapovaný
// znak v názvu monitoru rozbije kanál způsobem, který `str_contains` mine.
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

    // Vznik a vyřešení jsou dvě položky s různým guid. Kdyby se vyřešení jen
    // připsalo k původní položce, čtečka by ho odběrateli nikdy neukázala -
    // jednou zobrazené guid už znovu nevypisuje.
    check_true('uzavřený incident má položku o vzniku', in_array('incident-901-opened', $guids, true));
    check_true('uzavřený incident má položku o vyřešení', in_array('incident-901-resolved', $guids, true));

    // Probíhající incident vyřešení nemá - dopočítat ho z "teď" by byl
    // vymyšlený údaj o něčem, co se nestalo.
    check_true('probíhající incident má položku o vzniku', in_array('incident-902-opened', $guids, true));
    check_false('probíhající incident nemá vyřešení', in_array('incident-902-resolved', $guids, true));

    check_true('název monitoru je v položce', str_contains($rss_body, 'Testovací web'));
    check_true('každá položka má pubDate', count($pub_dates) === count($guids) && !in_array('', $pub_dates, true));

    // Nejnovější nahoře - čtečky pořadí z kanálu přebírají.
    $first_ts = !empty($pub_dates) ? strtotime($pub_dates[0]) : 0;
    $last_ts = !empty($pub_dates) ? strtotime(end($pub_dates)) : 0;
    check_true('položky jsou od nejnovější', $first_ts >= $last_ts);
    check_true('titulek rozlišuje výpadek a vyřešení', count(array_unique($titles)) === count($titles));
}

if (!empty($cookie_jar)) {
    // Skrytá stránka - anonym musí dostat 404 stejně jako u neexistujícího slugu.
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

    // A veřejná stránka kanál vydat musí, jinak by test výše prošel i tehdy,
    // kdyby byl rozbitý úplně každý kanál se slugem.
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
// 10. Eskalace - pojistka pro upozornění, které nikdo neviděl
// =======================================================================
//
// Rozhodovací logika má testy bez databáze. Tady jde o průchod celým
// mechanismem: co se opravdu zapíše do databáze a hlavně co se NEzapíše,
// když eskalace nemá kam odejít.
require_once __DIR__ . '/../functions.php';

// Nastavení se načítá jednou při startu do globálu `$system_settings`
// (db.php) a get_setting() čte odtud. Zápis do databáze tedy sám o sobě nic
// nezmění - v cronu to nevadí, protože ten startuje s čerstvými hodnotami,
// ale test mění nastavení za běhu a musí si globál obnovit.
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

// --- Vypnutá eskalace nedělá nic ---
$set_setting('escalation_enabled', '0');
$res = bk_process_escalations($pdo);
check('vypnutá eskalace nic nekontroluje', $res['checked'], 0);
check_true('a nic neorazítkuje', $esc_state(910) === null);

// --- Zapnutá, ale bez kanálu ---
// Razítko se nesmí dát: incident by se tvářil jako eskalovaný a po doplnění
// kanálu by se už nikdy neozval. Tiché selhání přesně tam, kde má pojistka
// fungovat.
$set_setting('escalation_enabled', '1');
$set_setting('escalation_after_mins', '15');
$set_setting('escalation_webhook_url', '');
$res = bk_process_escalations($pdo);
check_true('bez kanálu se incident započítá jako čekající', $res['skipped_no_channel'] >= 1);
check('bez kanálu se nic neodešle', $res['escalated'], 0);
check_true('bez kanálu incident zůstává neorazítkovaný', $esc_state(910) === null);

// --- S kanálem ---
// Webhook míří na vlastní testovací server: ověřuje se zápis do databáze,
// ne doručení do Discordu.
$set_setting('escalation_webhook_url', $base . '/api.php?action=ui_config');
$res = bk_process_escalations($pdo);
// Kontroluje se konkrétní incident, ne součet: otevřených incidentů je
// v databázi víc (seed pro RSS) a na počtu by test praskal při každé
// změně testovacích dat.
check_true('nepřevzatý incident eskaluje', $res['escalated'] >= 1);
check_true('a dostane razítko', $esc_state(910) !== null);
check_true('převzatý incident razítko nedostane', $esc_state(911) === null);

// --- Opakování ---
$res = bk_process_escalations($pdo);
check('podruhé už se stejný incident neeskaluje', $res['escalated'], 0);

$set_setting('escalation_enabled', '0');

// =======================================================================
// 11. Endpointy, které chyběly a volání na ně tiše propadala
// =======================================================================
//
// api.php dosud na neznámou akci vracelo výchozí přehled služeb s kódem 200,
// takže volání na neexistující endpoint vypadalo jako úspěch. Tyhle testy
// hlídají obojí: že guard neznámou akci odmítne a že chybějící endpointy
// opravdu existují a něco dělají.
[$code, $unknown] = api_get($base, 'action=rozhodne_neexistujici_akce');
check('neznámá akce vrací 400, ne tiché 200', $code, 400);
check_true('a řekne, co je špatně', str_contains((string)($unknown['error'] ?? ''), 'Neznámá akce'));

// Prázdná akce si ponechává staré chování (výchozí přehled služeb).
[$code] = api_get($base, '');
check('prázdná akce dál vrací výchozí přehled', $code, 200);

// --- Export CSV ---------------------------------------------------------
$csv_ch = curl_init($base . '/api.php?action=export_csv&monitor_id=1');
curl_setopt_array($csv_ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true]);
$csv_raw = (string)curl_exec($csv_ch);
$csv_code = (int)curl_getinfo($csv_ch, CURLINFO_RESPONSE_CODE);
$csv_body = substr($csv_raw, curl_getinfo($csv_ch, CURLINFO_HEADER_SIZE));
check('export CSV vrací 200', $csv_code, 200);
check_true('CSV se posílá ke stažení', str_contains($csv_raw, 'text/csv') && str_contains($csv_raw, 'attachment'));
check_true('CSV má hlavičku sloupců', str_contains($csv_body, 'Stav') && str_contains($csv_body, 'Odezva'));
// Chybové hlášky jsou jen pro přihlášené - stránka monitoru je veřejná.
check_false('anonym nedostane sloupec s chybami', str_contains($csv_body, 'Chybová hláška'));

[$csv_missing_code] = api_get($base, 'action=export_csv&monitor_id=999999');
check('export neexistujícího monitoru vrací 404', $csv_missing_code, 404);

// --- Poznámky do grafů --------------------------------------------------
if (!empty($cookie_jar)) {
    [$code, $ann] = api_post($base, 'action=save_annotation', [
        'monitor_id' => 1,
        'metric_key' => 'response_time',
        'timestamp' => date('Y-m-d H:i:s'),
        'note' => 'Nasazena nová verze',
    ], $cookie_jar);
    check('poznámka se uloží', $code, 200);
    check_true('a vrátí své id', (int)($ann['id'] ?? 0) > 0);

    // Tabulka metric_annotations byla roky prázdná právě proto, že endpoint
    // chyběl a poznámka se tiše zahodila.
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

    // Provozní poznámky nejsou pro veřejnost.
    [, $anon_ann] = api_get($base, 'action=annotations&monitor_id=1&metric=response_time');
    check('anonym poznámky nevidí', $anon_ann['annotations'] ?? null, []);

    [$anon_save_code] = api_post($base, 'action=save_annotation', [
        'monitor_id' => 1, 'metric_key' => 'cpu', 'timestamp' => date('Y-m-d H:i:s'), 'note' => 'pokus',
    ], tempnam(sys_get_temp_dir(), 'bk_anon'));
    check('anonym poznámku neuloží', $anon_save_code, 403);
}

// --- Zapomenuté heslo ---------------------------------------------------
//
// Odpověď musí být stejná pro existující i neexistující e-mail, jinak jde
// formulářem zjišťovat, kdo má účet.
[$code, $fp1] = api_post($base, 'action=forgot_password', ['email' => 'urcite-neexistuje@example.com'], tempnam(sys_get_temp_dir(), 'bk_fp'));
check('žádost o obnovu hesla vrací 200', $code, 200);
[$code, $fp2] = api_post($base, 'action=forgot_password', ['email' => 'admin@bloodkings.eu'], tempnam(sys_get_temp_dir(), 'bk_fp2'));
check('a pro existující účet vrací totéž', $code, 200);
check('odpověď neprozradí, jestli účet existuje', $fp1['message'] ?? 'a', $fp2['message'] ?? 'b');

// --- Instalace ----------------------------------------------------------
// Schema.sql zakládá výchozí účet, takže tabulka uživatelů není prázdná
// a instalace se musí odmítnout. Dřív vracela 200 a netvořila nic.
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
    // E-mail se dřív vracel natvrdo jako admin@bloodkings.eu bez ohledu na to,
    // kdo je přihlášený.
    check_true('e-mail je z databáze, ne natvrdo', array_key_exists('email', $sess['user'] ?? []));
}

// --- Auditní protokol ---------------------------------------------------
if (!empty($cookie_jar)) {
    [$code, $audit] = api_get_auth($base, 'action=user_audit_log&limit=20', $cookie_jar);
    check('auditní protokol vrací 200', $code, 200);
    check_true('a je to pole záznamů', isset($audit['entries']) && is_array($audit['entries']));
    // Přihlášení admina proběhlo na začátku testů, takže záznam existovat musí.
    check_true('obsahuje přihlášení', str_contains(json_encode($audit, JSON_UNESCAPED_UNICODE), 'login_success'));

    [$anon_audit_code] = api_get($base, 'action=user_audit_log');
    check('anonym auditní protokol nedostane', $anon_audit_code, 403);
}

// =======================================================================
// 12. Dlouhodobá řada metrik z denní agregace
// =======================================================================
//
// Syrové vps_metrics se po 30 dnech mažou, takže bez agregace nešlo
// odpovědět na "jak rostlo zaplnění disku za půl roku". Test zapisuje data
// stará víc než měsíc - přesně ta, která by v syrové podobě už neexistovala.
// Nejdřív samotná agregace: bez ní by test níž ověřoval jen čtení z tabulky,
// kterou nikdo neplní.
$pdo->exec("DELETE FROM metrics_daily");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, checked_at)
            VALUES (2, 10, 50, 70, DATE_SUB(NOW(), INTERVAL 2 HOUR))");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, ram_usage, hdd_usage, checked_at)
            VALUES (2, 90, 55, 71, DATE_SUB(NOW(), INTERVAL 1 HOUR))");

$rolled_metrics = bk_rollup_daily_metrics($pdo, 2);
check_true('agregace zapsala řádky', $rolled_metrics > 0);

// Den, do kterého ty vzorky patří - NE dnešek.
//
// Původně se tu kontrolovalo `day = CURDATE()`, jenže vzorky jsou hodinu a dvě
// staré: mezi půlnocí a druhou ranní spadnou do včerejška a test selhal, aniž
// by se v kódu cokoli změnilo. Chytlo se to až v CI, které jednou proběhlo
// v 00:11 UTC. Test se teď ptá na den, kam data podle svých vlastních časů
// patří, takže na hodině spuštění nezáleží.
$sample_day = $pdo->query("SELECT DATE(DATE_SUB(NOW(), INTERVAL 1 HOUR))")->fetchColumn();

$stmt_cpu_row = $pdo->prepare("SELECT min_val, avg_val, max_val, samples FROM metrics_daily
                               WHERE monitor_id = 2 AND metric_key = 'cpu' AND day = ?");
$stmt_cpu_row->execute([$sample_day]);
$cpu_row = $stmt_cpu_row->fetch();
check_true('CPU má agregát za den vzorků', $cpu_row !== false);
if ($cpu_row) {
    // Průměr sám by schoval špičku; proto se ukládá i min a max.
    check('minimum sedí', (int)round($cpu_row['min_val']), 10);
    check('maximum sedí', (int)round($cpu_row['max_val']), 90);
    check('průměr sedí', (int)round($cpu_row['avg_val']), 50);
    check('počet vzorků sedí', (int)$cpu_row['samples'], 2);
}

// Metrika, kterou agent nehlásí, nesmí vzniknout jako nula.
$swap_rows = (int)$pdo->query("SELECT COUNT(*) FROM metrics_daily WHERE metric_key = 'swap'")->fetchColumn();
check('nezměřená metrika se neagreguje', $swap_rows, 0);

// Opakovaný běh nesmí duplikovat ani zdvojnásobit počty.
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

[$code, $year] = api_get($base, 'action=metric_series&monitor_id=2&metric=hdd&period=1y');
check('roční řada vrací 200', $code, 200);
check_true('a nese body', !empty($year['points']));
check('a přizná, že jde o denní průměry', $year['resolution'] ?? null, 'daily');
check_true('k průměrům je min/max', !empty($year['dailyRange']) && array_key_exists('max', $year['dailyRange'][0]));

// Rok nesmí sahat dál než rok - jinak by graf tvrdil delší historii, než má.
$oldest = $year['points'][0][0] ?? 0;
check_true('nejstarší bod není starší než rok', $oldest >= time() - 366 * 86400);

// Data starší než retence syrových dat se do krátkého okna nesmí připlést.
[, $day] = api_get($base, 'action=metric_series&monitor_id=2&metric=hdd&period=24h');
check_false('24h okno denní agregaci nepoužívá', ($day['resolution'] ?? null) === 'daily');

// Prázdná agregace znamená prázdnou řadu, ne vymyšlená čísla.
$pdo->exec("DELETE FROM metrics_daily");
[, $empty_year] = api_get($base, 'action=metric_series&monitor_id=2&metric=hdd&period=1y');
check('bez agregovaných dat je řada prázdná', $empty_year['points'] ?? null, []);

// =======================================================================
// 13. Hlášení agenta se uloží jako metriky
// =======================================================================
//
// Tohle je jediný test, který ověřuje celou cestu: agent pošle JSON, API ho
// zapíše do sloupců a graf ho pak najde. Právě tady se ztrácelo 35 hodnot -
// posílaly se každou minutu a končily jen v posledním snímku detailů.
$agent_key = $pdo->query("SELECT agent_key FROM monitors WHERE id = 2")->fetchColumn();
if (!$agent_key) {
    $agent_key = bin2hex(random_bytes(16));
    $stmt_ak = $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = 2");
    $stmt_ak->execute([$agent_key]);
}

$agent_payload = [
    'agent_key' => $agent_key,
    'cpu' => 12.5, 'ram' => 44.0, 'hdd' => 61.0,
    // Nově ukládané metriky napříč typy: latence, kvalita signálu, počítadla.
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
    // Nezměřená hodnota - musí zůstat NULL, ne spadnout na nulu.
    'entropy' => null,
    'oom_kills' => 'null',
];

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

    // Jádro pravidla: co agent neposlal, je NULL. Nula by tvrdila, že se
    // naměřila nulová entropie, což je něco úplně jiného.
    check_true('neposlaná entropie je NULL', $saved['entropy_avail'] === null);
    check_true('řetězec "null" od agenta je taky NULL', $saved['oom_kills'] === null);
    check_true('nezmíněná metrika je NULL', $saved['sqm_dropped'] === null);
}

// --- Počítadla se v grafu kreslí jako přírůstek --------------------------
//
// Kumulativní hodnota z jádra by dala jen stoupající rampu.
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
foreach ([[100, 5], [150, 4], [220, 3], [60, 2], [90, 1]] as [$val, $mins_ago]) {
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, tcp_retrans, checked_at)
                VALUES (2, {$val}, DATE_SUB(NOW(), INTERVAL {$mins_ago} MINUTE))");
}

[, $ctr] = api_get($base, 'action=metric_series&monitor_id=2&metric=tcp_retrans&period=24h');
$ctr_values = array_map(fn($p) => $p[1], $ctr['points'] ?? []);
// 100→150→220 dá přírůstky 50 a 70; pak hodnota spadne na 60 (reset
// počítadla po rebootu) - ten bod se přeskočí, ne aby vyrobil zápornou
// špičku nebo falešnou nulu. Následuje 60→90, tedy 30.
// JSON vrací celá čísla jako int, ne float - proto celočíselná očekávání.
check('počítadlo se kreslí jako přírůstek', $ctr_values, [50, 70, 30]);
check_true('popisek přizná, že jde o přírůstek', str_contains((string)($ctr['label'] ?? ''), 'přírůstek'));

// Okamžitá metrika se nesmí do přírůstku převádět.
$pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 30, DATE_SUB(NOW(), INTERVAL 2 MINUTE))");
$pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 80, DATE_SUB(NOW(), INTERVAL 1 MINUTE))");
[, $gauge] = api_get($base, 'action=metric_series&monitor_id=2&metric=cpu&period=24h');
check('okamžitá metrika se kreslí tak, jak byla naměřena', array_map(fn($p) => $p[1], $gauge['points'] ?? []), [30, 80]);

// --- Stav sběru dat -----------------------------------------------------
//
// Endpoint hlídá hlídač zvenku, takže musí být dostupný bez přihlášení a
// nesmí tvrdit, že je vše v pořádku, když cron nikdy neběžel.
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

[, $m15] = api_get($base, 'action=metric_series&monitor_id=2&metric=cpu&period=15m');
check('15m vrátí jen měření za posledních 15 minut', count($m15['points'] ?? []), 1);

[, $m6h] = api_get($base, 'action=metric_series&monitor_id=2&metric=cpu&period=6h');
check('6h vrátí měření za šest hodin, ne za den', count($m6h['points'] ?? []), 3);

// The oldest point is 2000 minutes back, i.e. more than a day - it does not
// belong in the 24h window but does in the weekly one. That is what proves the
// window actually moves.
[, $m24h] = api_get($base, 'action=metric_series&monitor_id=2&metric=cpu&period=24h');
check('24h nechá venku měření starší než den', count($m24h['points'] ?? []), 3);

[, $m7d] = api_get($base, 'action=metric_series&monitor_id=2&metric=cpu&period=7d');
check('7d vrátí i to nejstarší', count($m7d['points'] ?? []), 4);

// Context for the metric detail page.
[$code, $detail] = api_get($base, 'action=metric_detail&monitor_id=2&metric=cpu');
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
[, $no_thr] = api_get($base, 'action=metric_detail&monitor_id=2&metric=load1');
check_true('metrika bez prahu má pole thresholds', array_key_exists('critical', $no_thr['thresholds'] ?? []));
check('a pásmo nekreslí', $no_thr['thresholds']['critical'], null);

// Related metrics only where the monitor actually reports them. Monitor 2 has
// only cpu_usage filled in, so the list must be empty (cpu is the one shown).
check('nenabízí proklik do metrik bez dat', $detail['related'] ?? null, []);

[$code] = api_get($base, 'action=metric_detail&monitor_id=2&metric=neexistujici');
check('neznámá metrika vrací 404, ne prázdnou stránku', $code, 404);

[$code] = api_get($base, 'action=metric_detail&monitor_id=99999&metric=cpu');
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
[$code, $ph] = api_get($base, 'action=process_history&monitor_id=2&kind=cpu&at=' . $now_ts . '&radius=10');
check('process_history vrací 200', $code, 200);
check('vrátí jen procesy z okna', count($ph['samples'] ?? []), 2);
check('nejvyšší je první', $ph['samples'][0]['name'] ?? null, 'hostapd');
check('a se svou hodnotou CPU', $ph['samples'][0]['cpuPct'] ?? null, 87.5);

// Nezměřená dimenze zůstává null. Nula by tvrdila "změřeno, proces nic
// nezabíral" - to je něco jiného než "agent to u něj nehlásil".
$kresd = null;
foreach ($ph['samples'] as $sample) {
    if ($sample['name'] === 'kresd') { $kresd = $sample; }
}
check_true('proces bez údaje o paměti je v odpovědi', $kresd !== null);
check('a paměť má null, ne nulu', $kresd['ramMb'], null);

check_true('odpověď přiznává, jestli je sběr zapnutý', array_key_exists('enabled', $ph ?? []));
check('bez prořezání je pruned false', $ph['pruned'] ?? null, false);

// Prázdné okno není totéž co vypnutý sběr - klient to musí rozlišit.
[, $ph_empty] = api_get($base, 'action=process_history&monitor_id=2&kind=cpu&at=' . ($now_ts - 86400 * 3) . '&radius=5');
check('okno bez záznamů vrátí prázdno, ne chybu', $ph_empty['samples'] ?? null, []);
check('a pořád hlásí, že sběr běží', $ph_empty['enabled'] ?? null, true);

[$code] = api_get($base, 'action=process_history&monitor_id=2&kind=cpu');
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

    // Deset minut nad prahem, předtím pod ním.
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

    // Hodnota pod prahem není tlak, i kdyby předtím špička byla.
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 30, NOW())");
    check('po poklesu se tlak nehlásí', bk_metric_pressure($pdo, 2, 'cpu_usage', 85.0), null);

    // Jediné měření není doba trvání.
    $pdo->exec("DELETE FROM vps_metrics WHERE monitor_id = 2");
    $pdo->exec("INSERT INTO vps_metrics (monitor_id, cpu_usage, checked_at) VALUES (2, 99, NOW())");
    check('jeden vzorek se nevydává za trvání', bk_metric_pressure($pdo, 2, 'cpu_usage', 85.0), null);

    // Název sloupce jde do SQL, takže smí projít jen ze seznamu.
    check('neznámý sloupec se odmítne', bk_metric_pressure($pdo, 2, 'cpu_usage; DROP TABLE monitors', 85.0), null);
}

// --- Knowledge tipy: práh se bere z nastavení monitoru ------------------
//
// Tipy měly prahy natvrdo (CPU 80/50), zatímco pásma v grafu i Executive
// Summary jedou podle monitors.cpu_threshold. Kdo si práh zvedl na 95,
// dostával kritický tip už při 81 % - tři různé názory na "moc vysoko".
bk_test_load_functions($root . '/functions.php', [
    'bk_get_knowledge_tips', 'bk_enrich_threshold_tip', 'bk_metric_duration_above',
    'bk_format_duration', 'bk_get_enabled_metrics',
]);
require_once $root . '/lang.php';

if (function_exists('bk_get_knowledge_tips')) {
    $details_85 = ['cpu' => 85, 'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61.0, 'ram_mb' => 8.0]]];

    // Práh 95: 85 % je pod ním, kritický tip nemá vzniknout.
    $mon_high = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 95];
    $tips_high = bk_get_knowledge_tips($mon_high, $details_85, [], 'up', [], $pdo);
    $crit_high = array_filter($tips_high, fn($t) => $t['severity'] === 'critical');
    check('s prahem 95 se při 85 % nekřičí', count($crit_high), 0);

    // Práh 80: totéž měření je nad ním.
    $mon_low = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 80];
    $tips_low = bk_get_knowledge_tips($mon_low, $details_85, [], 'up', [], $pdo);
    $crit_low = array_filter($tips_low, fn($t) => $t['severity'] === 'critical');
    check_true('s prahem 80 kritický tip vznikne', count($crit_low) > 0);

    // A nese jméno viníka - kvůli tomu ta věta existuje.
    $text = implode(' ', array_column($crit_low, 'text'));
    check_true('a jmenuje viníka', str_contains($text, 'hostapd'));

    // Bez nastaveného prahu zůstává původní chování (80/50), aby se nikomu
    // nezměnilo pod rukama.
    $mon_none = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt'];
    $tips_none = bk_get_knowledge_tips($mon_none, $details_85, [], 'up', [], $pdo);
    check_true('bez prahu platí původní 80', count(array_filter($tips_none, fn($t) => $t['severity'] === 'critical')) > 0);
}

// --- Knowledge tipy: práh se bere z nastavení monitoru ------------------
//
// Tipy měly prahy natvrdo (CPU 80/50), zatímco pásma v grafu i Executive
// Summary jedou podle monitors.cpu_threshold. Kdo si práh zvedl na 95,
// dostával kritický tip už při 81 % - tři různé názory na "moc vysoko".
bk_test_load_functions($root . '/functions.php', [
    'bk_get_knowledge_tips', 'bk_enrich_threshold_tip', 'bk_metric_duration_above',
    'bk_format_duration', 'bk_get_enabled_metrics',
]);
require_once $root . '/lang.php';

if (function_exists('bk_get_knowledge_tips')) {
    $details_85 = ['cpu' => 85, 'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61.0, 'ram_mb' => 8.0]]];

    // Práh 95: 85 % je pod ním, kritický tip nemá vzniknout.
    $mon_high = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 95];
    $tips_high = bk_get_knowledge_tips($mon_high, $details_85, [], 'up', [], $pdo);
    check('s prahem 95 se při 85 % nekřičí', count(array_filter($tips_high, fn($t) => $t['severity'] === 'critical')), 0);

    // Práh 80: totéž měření je nad ním.
    $mon_low = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt', 'cpu_threshold' => 80];
    $tips_low = bk_get_knowledge_tips($mon_low, $details_85, [], 'up', [], $pdo);
    $crit_low = array_filter($tips_low, fn($t) => $t['severity'] === 'critical');
    check_true('s prahem 80 kritický tip vznikne', count($crit_low) > 0);
    check_true('a jmenuje viníka', str_contains(implode(' ', array_column($crit_low, 'text')), 'hostapd'));

    // Bez nastaveného prahu zůstává původní chování (80/50).
    $mon_none = ['id' => 2, 'name' => 'R', 'status' => 'up', 'type' => 'openwrt'];
    $tips_none = bk_get_knowledge_tips($mon_none, $details_85, [], 'up', [], $pdo);
    check_true('bez prahu platí původní 80', count(array_filter($tips_none, fn($t) => $t['severity'] === 'critical')) > 0);
}

// Shrnutí si nesmí odporovat v jednom dechu.
//
// První verze říkala "Žádné aktuální problémy nebyly zjištěny." a hned za tím
// "CPU je na 91 % už 18 min." - věta o klidu se skládala dřív, než se tlak
// vůbec spočítal. Chyceno na ukázce se skutečnými daty, ne úvahou.
bk_test_load_functions($root . '/functions.php', ['bk_summary_pressure_line', 'bk_build_executive_summary']);
// Shrnutí skládá věty přes t(), takže bez slovníku spadne na nedefinovanou
// funkci - stejná past, jakou už jednou schytal cron.php a agent_api.php.
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

    // Bez tlaku ta věta naopak zaznít musí, jinak by shrnutí mlčelo.
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

    // Mimo okno se nic nevymýšlí - historie procesů je mladá a starší špičku
    // prostě vysvětlit neumíme.
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

// Token se spotřeboval - druhé použití musí selhat.
[$code] = api_post($base, 'action=set_password', ['token' => $invite_token, 'password' => 'JineHeslo123!'], $cookie_jar);
check('spotřebovaný token vrací 400', $code, 400);

// A novým heslem se jde přihlásit (do nové session, ať nerozbijeme tu adminovu).
$login_jar2 = tempnam(sys_get_temp_dir(), 'bk_test_c2');
[$code, $relog] = api_post($base, 'action=login', ['username' => 'admin', 'password' => 'NoveHeslo123!'], $login_jar2);
check_true('nové heslo funguje pro přihlášení', $code === 200 && !empty($relog['success']));
// Vratit puvodni heslo, dalsi testy s nim pocitaji.
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = 1")
    ->execute([password_hash('BloodKingsAdmin123!', PASSWORD_BCRYPT)]);
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

    // Špatný kód nesmí 2FA zapnout.
    [$code] = api_post($base, 'action=totp_confirm', ['code' => '000000'], $cookie_jar);
    check('špatný kód vrací 400', $code, 400);
    $totp_row = $pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn();
    check('a 2FA zůstává vypnuté', (int)$totp_row, 0);

    // Správný kód spočítaný z téhož secretu.
    $valid_code = bk_totp_calculate($ts['secret'], (int)floor(time() / 30));
    [$code] = api_post($base, 'action=totp_confirm', ['code' => $valid_code], $cookie_jar);
    check('správný kód 2FA zapne', $code, 200);
    check('v databázi je zapnuto', (int)$pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn(), 1);

    // Vypnutí bez hesla neprojde - ukradená session nesmí 2FA tiše sundat.
    [$code] = api_post($base, 'action=totp_disable', ['password' => 'spatne-heslo'], $cookie_jar);
    check('vypnutí se špatným heslem vrací 400', $code, 400);
    check('2FA drží', (int)$pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn(), 1);

    [$code] = api_post($base, 'action=totp_disable', ['password' => 'BloodKingsAdmin123!'], $cookie_jar);
    check('se správným heslem se vypne', $code, 200);
    check('a v databázi je vypnuto', (int)$pdo->query("SELECT totp_enabled FROM users WHERE id = 1")->fetchColumn(), 0);
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
        // [dní zpět, jméno, cpu, ram]
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
    // 1. Bez prořezávání se zahodí jen to, co je za retencí.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 30, 0, 50.0);
    check('za retencí se smaže', $r['deleted'], 1);
    check('bez prořezávání se nic neprořezává', $r['pruned'], 0);
    check(
        'v okně retence zůstane všechno',
        $names_left($pdo),
        ['dnesni-klidny', 'dnesni-spicka', 'stary-klidny', 'stary-spicka', 'stary-zravy-na-pamet']
    );

    // 2. Se zapnutým prořezáváním zmizí staré klidné vzorky, špičky zůstanou.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 30, 7, 50.0);
    check('prořezal se jeden klidný starý vzorek', $r['pruned'], 1);
    check('a dvě špičky se označily', $r['marked'], 2);
    check(
        'dnešek nedotčen, ze starých zbyly špičky',
        $names_left($pdo),
        ['dnesni-klidny', 'dnesni-spicka', 'stary-spicka', 'stary-zravy-na-pamet']
    );

    // Označení musí být vidět v datech - jinak by prořezané okno vypadalo
    // jako doba, kdy se nic nedělo.
    $kept = $pdo->query("SELECT kept_reason FROM process_samples WHERE name = 'stary-spicka'")->fetchColumn();
    check('přeživší vzorek přizná, že je z prořezaného okna', $kept, 'peak');
    $fresh = $pdo->query("SELECT kept_reason FROM process_samples WHERE name = 'dnesni-klidny'")->fetchColumn();
    check('čerstvý vzorek zůstává raw', $fresh, 'raw');

    // 3. Prořezání nastavené za hranicí retence je no-op.
    //
    // Pozor na to, co tenhle případ NEtestuje: ztrátu dat tu nehlídá, protože
    // první fáze dotčené okno smaže dřív, než se k němu druhá dostane - ověřeno
    // sabotáží, po odstranění pojistky testy dál procházely. Pojistka tedy
    // nechrání data, jen ušetří dva zbytečné dotazy. Test hlídá to, na čem
    // záleží: že se v takové konfiguraci nesáhne na čerstvé vzorky.
    $seed_samples($pdo);
    $r = bk_prune_process_samples($pdo, 5, 30, 50.0);
    check('prořezání za hranicí retence nic neprořeže', $r['pruned'], 0);
    check('a nic neoznačí', $r['marked'], 0);
    check('čerstvé vzorky zůstanou nedotčené', $names_left($pdo), ['dnesni-klidny', 'dnesni-spicka']);

    // 4. Vypnutá historie tabulku vyprázdní - data, která nikdo nesbírá
    //    a nikdo nevidí, nemají v databázi co dělat.
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
            'whatsapp_api_endpoint' => 'https://graph.facebook.com/v20.0/123/messages',
            'whatsapp_phone_number' => '+420777123456',
        ],
    ], $cookie_jar);
    check('save_settings vrací 200', $code, 200);

    [$code, $got] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    check('get_settings vrací 200', $code, 200);
    $s = $got['settings'] ?? [];

    check('eskalace se uložila a přečetla', $s['escalation_enabled'] ?? null, '1');
    check('lhůta na převzetí se uložila', $s['escalation_after_mins'] ?? null, '20');
    check('eskalační webhook se uložil', $s['escalation_webhook_url'] ?? null, 'https://discord.com/api/webhooks/test');

    // The heart of it: whatever can be saved must also be readable. If these two
    // keys dropped out of the read path again, the next save would erase them.
    check(
        'whatsapp endpoint se vrací zpátky (jinak ho další uložení smaže)',
        $s['whatsapp_api_endpoint'] ?? null,
        'https://graph.facebook.com/v20.0/123/messages'
    );
    check('whatsapp číslo se vrací zpátky', $s['whatsapp_phone_number'] ?? null, '+420777123456');

    // The second save sends back what the form loaded - exactly like a user
    // flipping some other option and hitting save.
    api_post($base, 'action=save_settings', ['settings' => $s], $cookie_jar);
    [, $again] = api_get_auth($base, 'action=get_settings', $cookie_jar);
    check(
        'druhé uložení nastavení nesmaže',
        $again['settings']['whatsapp_api_endpoint'] ?? null,
        'https://graph.facebook.com/v20.0/123/messages'
    );

    // Every key the server accepts must also be returnable. Without this the
    // same bug could be recreated with a different key.
    $missing = array_values(array_diff(bk_settings_keys(), array_keys($s)));
    check('get_settings vrací všechny klíče, které save_settings přijímá', $missing, []);
}

$failed = bk_test_report('api.php (integrační)');
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
