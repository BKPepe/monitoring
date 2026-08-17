<?php
/**
 * Testy sběrové/notifikační/e-mailové vrstvy - bez DB a bez sítě.
 *
 * Spuštění:  php apps/status/tests/run_pipeline_tests.php
 *
 * Tyhle tři oblasti (cron pipeline, e-mailové šablony, notifikační kanály)
 * dosud neměly žádné pokrytí, přestože právě ony rozhodují, jestli se
 * uživatel o výpadku dozví - a jestli mu systém neukáže vymyšlená data.
 * Testuje se to, co jde deterministicky: vyhodnocení stavu sběru, jazyk
 * e-mailů, výběr příjemců/kanálů a skládání textů.
 */

require_once __DIR__ . '/../lang.php';

require_once __DIR__ . '/assert_helpers.php';
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_get_collection_issues', 'bk_with_email_lang', 'bk_enrich_threshold_tip',
    'bk_relative_time_label', 'bk_format_duration', 'bk_compute_baseline_anomaly',
    'bk_half_window_rate', 'bk_latency_score',
]);


// =======================================================================
// 1. SBĚR DAT - bk_get_collection_issues
// Tvrdé pravidlo projektu: výpadek sběru MUSÍ být vidět. Tyhle testy hlídají,
// že se hlásí právě tehdy, když se opravdu nesbírá - ani dřív, ani později.
// =======================================================================
if (function_exists('bk_get_collection_issues')) {
    $healthy_monitor = ['status' => 'up', 'last_checked' => date('Y-m-d H:i:s')];

    check('zdravý monitor bez agenta nehlásí nic',
        count(bk_get_collection_issues($healthy_monitor, [])), 0);

    // cPanel exporter selhává - přesně scénář, který dva týdny nikdo neviděl.
    $issues = bk_get_collection_issues($healthy_monitor, [
        'cpanel_stats_error' => ['error' => 'HTTP 403', 'hint' => 'Špatný klíč', 'since' => '2026-08-01T10:00:00+02:00'],
    ]);
    check('selhání cPanel sběru se hlásí', count($issues), 1);
    check('typ problému je cpanel_stats', $issues[0]['type'] ?? null, 'cpanel_stats');
    check('hint se propaguje k adminovi', $issues[0]['hint'] ?? null, 'Špatný klíč');
    check('začátek výpadku se propaguje', $issues[0]['since'] ?? null, '2026-08-01T10:00:00+02:00');

    // Mlčící agent: hlásí se až po vypršení timeoutu, ne dřív.
    $fresh = bk_get_collection_issues($healthy_monitor, ['agent_last_seen' => time() - 60], 3000);
    check('čerstvý agent nehlásí problém', count($fresh), 0);

    $stale = bk_get_collection_issues($healthy_monitor, ['agent_last_seen' => time() - 7200], 3000);
    check_true('mlčící agent se hlásí',
        count(array_filter($stale, fn($i) => $i['type'] === 'agent_silent')) === 1);

    // Monitor, který agenta nikdy neměl, nesmí hlásit "agent mlčí".
    $never = bk_get_collection_issues($healthy_monitor, ['agent_last_seen' => 0], 3000);
    check('monitor bez agenta nehlásí mlčení agenta',
        count(array_filter($never, fn($i) => $i['type'] === 'agent_silent')), 0);

    // Zastavené kontroly - pauznutý monitor je legitimní stav, ne porucha.
    $paused = bk_get_collection_issues(
        ['status' => 'paused', 'last_checked' => date('Y-m-d H:i:s', time() - 86400)], []);
    check('pozastavený monitor nehlásí zastavené kontroly',
        count(array_filter($paused, fn($i) => $i['type'] === 'checks_stalled')), 0);

    $stalled = bk_get_collection_issues(
        ['status' => 'up', 'last_checked' => date('Y-m-d H:i:s', time() - 3600)], []);
    check_true('zastavené kontroly se hlásí',
        count(array_filter($stalled, fn($i) => $i['type'] === 'checks_stalled')) === 1);
}

// =======================================================================
// 2. E-MAILY - bk_with_email_lang
// E-maily nemají návštěvníka, takže jazyk určuje nastavení. Test hlídá, že
// se globály korektně přepnou A VRÁTÍ - jinak by první odeslaný e-mail
// přepnul jazyk celému běžícímu requestu (a tím i stránce uživatele).
// =======================================================================
if (function_exists('bk_with_email_lang')) {
    $GLOBALS['BK_LANG'] = 'cs';
    $GLOBALS['BK_STRINGS'] = require __DIR__ . '/../lang/cs.php';

    $en_text = bk_with_email_lang('en', fn() => t('day_no_data'));
    $cs_text = bk_with_email_lang('cs', fn() => t('day_no_data'));
    check_true('EN a CS text se liší', $en_text !== $cs_text);
    check_true('EN text není prázdný', is_string($en_text) && $en_text !== '');

    check('jazyk se po odeslání vrátí zpět', $GLOBALS['BK_LANG'], 'cs');
    check('slovník se po odeslání vrátí zpět', t('day_no_data'), $cs_text);

    // Nesmyslný kód jazyka nesmí shodit odesílání - fallback na češtinu.
    $fallback = bk_with_email_lang('xx', fn() => t('day_no_data'));
    check('neznámý jazyk padá na češtinu', $fallback, $cs_text);

    // Výjimka uvnitř builderu nesmí nechat globály přepnuté.
    try {
        bk_with_email_lang('en', function () { throw new RuntimeException('boom'); });
    } catch (RuntimeException $e) {
        // očekávané
    }
    check('výjimka v šabloně nenechá přepnutý jazyk', $GLOBALS['BK_LANG'], 'cs');
}

// =======================================================================
// 3. TEXTY UPOZORNĚNÍ - bk_enrich_threshold_tip
// Obohacení tipu smí použít jen data, která opravdu dorazila. Vymyšlený
// "top proces" nebo "Wi-Fi klientů: 0" by byl přesně ten druh lži, kvůli
// které tenhle projekt prošel čistkou fabrikovaných dat.
// =======================================================================
if (function_exists('bk_enrich_threshold_tip')) {
    $GLOBALS['BK_LANG'] = 'cs';
    $GLOBALS['BK_STRINGS'] = require __DIR__ . '/../lang/cs.php';

    $empty = bk_enrich_threshold_tip([], 'cpu');
    check_false('bez dat se nevymýšlí top proces', str_contains($empty, '%)'));

    $with_proc = bk_enrich_threshold_tip([
        'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61]],
        'load1' => 2.8, 'load5' => 2.4, 'load15' => 2.1,
        'wifi_clients_count' => 27,
    ], 'cpu');
    check_true('viník je v textu', str_contains($with_proc, 'hostapd'));
    check_true('podíl viníka je v textu', str_contains($with_proc, '61'));
    check_true('load average je v textu', str_contains($with_proc, '2.8'));
    check_true('kontext Wi-Fi klientů je v textu', str_contains($with_proc, '27'));
    check_true('doporučení je v textu', str_contains(mb_strtolower($with_proc), 'doporučení'));

    // Bez Wi-Fi telemetrie se o klientech nesmí psát vůbec.
    $no_wifi = bk_enrich_threshold_tip([
        'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61]],
    ], 'cpu');
    check_false('bez telemetrie se nepíše o klientech', str_contains(mb_strtolower($no_wifi), 'klient'));

    // Vytížení kanálu z iwinfo survey: bere se nejvytíženější rádio, které
    // ho REÁLNĚ změřilo - driver bez survey podpory (busy_pct null) nesmí
    // vyrobit vymyšlenou nulu ani se do výběru počítat.
    $busy_tip = bk_enrich_threshold_tip([
        'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61]],
        'wifi_radios' => [
            ['radio' => 'wlan0', 'busy_pct' => 34],
            ['radio' => 'wlan1', 'busy_pct' => 71],
            ['radio' => 'wlan2', 'busy_pct' => null],
        ],
    ], 'cpu');
    check_true('vytížení kanálu je v textu', str_contains($busy_tip, '71'));
    check_true('jmenuje se nejvytíženější rádio', str_contains($busy_tip, 'wlan1'));

    // Obecné doporučení kt_rec_wifi o kanálu mluvit smí - vymyšlené ale nesmí
    // být MĚŘENÍ. Jméno rádia se v textu objeví jedině se změřenou hodnotou.
    $busy_none = bk_enrich_threshold_tip([
        'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61]],
        'wifi_radios' => [['radio' => 'wlan0', 'busy_pct' => null]],
    ], 'cpu');
    check_false('samá null měření = žádné jméno rádia s číslem', str_contains($busy_none, 'wlan0'));

    // RAM varianta bere paměťový žebříček, ne CPU.
    $ram_tip = bk_enrich_threshold_tip([
        'top_ram_processes' => [['name' => 'java', 'ram_mb' => 2048]],
    ], 'ram');
    check_true('RAM tip jmenuje paměťového viníka', str_contains($ram_tip, 'java'));
}

// =======================================================================
// 4. DETEKCE ANOMÁLIÍ A TRENDŮ (podklad pro notifikace a insights)
// =======================================================================
if (function_exists('bk_compute_baseline_anomaly')) {
    // Stabilní řada + hodnota v normálu = žádná anomálie.
    $stable = array_fill(0, 30, 20.0);
    check('hodnota v normálu není anomálie',
        bk_compute_baseline_anomaly($stable, 21.0, 5.0), null);

    // Výrazný výkyv nad baseline anomálie být musí.
    $spike = bk_compute_baseline_anomaly($stable, 90.0, 5.0);
    check_true('výrazný výkyv je anomálie', is_array($spike));

    // Prázdná historie nesmí nic tvrdit (nemáme s čím porovnávat).
    check('bez historie se anomálie nehlásí',
        bk_compute_baseline_anomaly([], 90.0, 5.0), null);
}

if (function_exists('bk_half_window_rate')) {
    // Rostoucí zaplnění disku - podklad pro predikci "plno za N dní".
    $rows = [];
    for ($i = 0; $i < 14; $i++) {
        $rows[] = ['checked_at' => date('Y-m-d H:i:s', strtotime("-" . (14 - $i) . " days")), 'hdd_usage' => 50 + $i];
    }
    $rate = bk_half_window_rate($rows, 'hdd_usage');
    check_true('růst disku se detekuje', is_array($rate) && $rate['rate_per_day'] > 0);

    // Plochá řada nesmí generovat předpověď zaplnění.
    $flat = [];
    for ($i = 0; $i < 14; $i++) {
        $flat[] = ['checked_at' => date('Y-m-d H:i:s', strtotime("-" . (14 - $i) . " days")), 'hdd_usage' => 50];
    }
    $flat_rate = bk_half_window_rate($flat, 'hdd_usage');
    check_true('plochá řada nemá růst',
        $flat_rate === null || abs($flat_rate['rate_per_day']) < 0.01);
}

// =======================================================================
// 5. FORMÁTOVÁNÍ V NOTIFIKACÍCH
// =======================================================================
if (function_exists('bk_relative_time_label')) {
    check_true('relativní čas vrací text', is_string(bk_relative_time_label(time() - 300)));
}
if (function_exists('bk_latency_score')) {
    check_true('nízká latence skóruje lépe než vysoká',
        bk_latency_score(20) > bk_latency_score(2000));
}

// --- Nova metrika od agenta nesmi tise zmizet ----------------------------
//
// agent_api.php dlouho skladal details z pevneho seznamu poli a cokoli mimo
// nej zahodil. Chyba se pozna az rucnim hledanim - v UI vypada chybejici
// udaj stejne jako "zatim nezmereno". Tenhle test cte pravidla propousteni
// primo ze zdroje a overuje je na modelovych datech.
$agent_src = file_get_contents(__DIR__ . '/../agent_api.php');

check_true(
    'agent_api propousti neznama pole',
    str_contains($agent_src, '$bk_passthrough_added') && str_contains($agent_src, 'foreach ($data as $bk_key')
);

// Simulace stejnych pravidel, jaka ma agent_api: co projde a co ne.
$passthrough = function (array $data, array $known) {
    $skip = ['agent_key', 'api_key', 'token', 'secret', 'password', 'action_result', 'service_check_results', 'pending_action'];
    $out = $known;
    $added = 0;
    foreach ($data as $k => $v) {
        if (!is_string($k) || $k === '' || array_key_exists($k, $known) || in_array($k, $skip, true)) continue;
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $k)) continue;
        if (is_scalar($v) || $v === null) { $out[$k] = $v; $added++; }
        elseif (is_array($v)) {
            $enc = json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($enc !== false && strlen($enc) <= 8192) { $out[$k] = $v; $added++; }
        }
        if ($added >= 64) break;
    }
    return $out;
};

$known = ['cpu' => 12.5, 'ram' => 40.0];
$result = $passthrough([
    'cpu' => 99.0,                    // znamy klic - server si drzi svou verzi
    'brand_new_metric' => 42,         // presne to, co drive mizelo
    'nested' => ['a' => 1],           // male pole projde
    'agent_key' => 'tajne',           // nikdy do details
    'bad key!' => 1,                  // nevalidni nazev
    'huge' => array_fill(0, 5000, 'xxxxxxxxxx'), // pres limit velikosti
], $known);

check('nova metrika projde', $result['brand_new_metric'] ?? null, 42);
check('male pole projde', $result['nested']['a'] ?? null, 1);
check('typovany klic serveru se neprepise', $result['cpu'], 12.5);
check_false('agent_key se neuklada', array_key_exists('agent_key', $result));
check_false('nevalidni nazev se neuklada', array_key_exists('bad key!', $result));
check_false('prilis velke pole se neuklada', array_key_exists('huge', $result));

// --- Denni agregace dostupnosti ------------------------------------------
//
// SLA za dlouha obdobi se pocita z uptime_daily, protoze monitor_logs se
// mazou po 30 dnech. Testuje se samotny vypocet a poradi zdroju - bez DB,
// nad modelovymi souhrny.
$agg_src = file_get_contents(__DIR__ . '/../functions.php');

check_true(
    'rollup bezi pred mazanim logu',
    (function () {
        $cron = file_get_contents(__DIR__ . '/../cron.php');
        $rollup_pos = strpos($cron, 'bk_rollup_daily_uptime');
        $delete_pos = strpos($cron, 'DELETE FROM monitor_logs');
        // Kdyby se mazalo driv, prisla by se data prave o ten den, ktery
        // se chysta zmizet - presne tomu ma agregace zabranit.
        return $rollup_pos !== false && $delete_pos !== false && $rollup_pos < $delete_pos;
    })()
);

check_true(
    'rollup ignoruje udrzbu a neznamy stav',
    str_contains($agg_src, "AND status IN ('up', 'down', 'warning')")
);

check_true(
    'rollup je idempotentni (ON DUPLICATE KEY UPDATE)',
    str_contains($agg_src, 'ON DUPLICATE KEY UPDATE')
);

// Vypocet dostupnosti ze souhrnu: stejna matematika jako v SQL.
$uptime_from_days = function (array $days): ?float {
    $total = array_sum(array_column($days, 'total'));
    if ($total <= 0) {
        return null;
    }
    return round(array_sum(array_column($days, 'up')) / $total * 100, 3);
};

check('bez dat je dostupnost null, ne 100 %', $uptime_from_days([]), null);
check('same nuly = null, ne delení nulou', $uptime_from_days([['total' => 0, 'up' => 0]]), null);
check(
    'soucet pres dny odpovida podilu kontrol',
    $uptime_from_days([['total' => 1440, 'up' => 1440], ['total' => 1440, 'up' => 1430]]),
    99.653
);
check(
    'cely den vypadku snizi mesic spravne',
    $uptime_from_days(array_merge(
        array_fill(0, 29, ['total' => 1440, 'up' => 1440]),
        [['total' => 1440, 'up' => 0]]
    )),
    96.667
);

// --- Upozorneni na zhorsenou odezvu --------------------------------------
//
// Prah je zamerne prisny: nad limitem musi byt KAZDA kontrola v okne, ne jen
// prumer. Alert, ktery houka na jednu pomalou odpoved, se nauci kazdy
// ignorovat - a pak prehlédne i ten skutecny.
$lat_src = file_get_contents(__DIR__ . '/../functions.php');

check_true(
    'vyhodnoceni bere minimum, ne jen prumer',
    str_contains($lat_src, '$degraded = $min > $threshold')
);
check_true(
    'do okna jdou jen uspesne kontroly se zmerenou odezvou',
    str_contains($lat_src, "status = 'up'") && str_contains($lat_src, 'response_time > 0')
);

// Model stejneho rozhodovani, jake dela SQL + PHP dohromady.
$decide = function (array $samples, int $threshold, bool $alert_sent): string {
    $samples = array_values(array_filter($samples, fn($v) => $v !== null && $v > 0));
    if (count($samples) < 2) {
        return 'ok';
    }
    $degraded = min($samples) > $threshold;
    if ($degraded && !$alert_sent) return 'degraded';
    if (!$degraded && $alert_sent) return 'recovered';
    return 'ok';
};

check('trvale pomale = upozorneni', $decide([900, 950, 880], 500, false), 'degraded');
check('jedna pomala odpoved neposila nic', $decide([80, 900, 75], 500, false), 'ok');
check('jedno mereni na rozhodnuti nestaci', $decide([900], 500, false), 'ok');
check('opakovane se nehlasi znovu', $decide([900, 950], 500, true), 'ok');
check('navrat pod limit se ohlasi', $decide([90, 85], 500, true), 'recovered');
check('bez odeslaneho alertu se navrat nehlasi', $decide([90, 85], 500, false), 'ok');
// Prah presne na hranici: rovnost neni prekroceni.
check('hodnota rovna prahu neni zpomaleni', $decide([500, 500], 500, false), 'ok');

$failed = bk_test_report('sběr, e-maily, notifikace');
// Pod coverage runnerem se nekončí procesem - jinak by se report nikdy nevygeneroval.
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
