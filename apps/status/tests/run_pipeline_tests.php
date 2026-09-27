<?php
/**
 * Tests of the collection/notification/e-mail layer - no DB and no network.
 *
 * Running:  php apps/status/tests/run_pipeline_tests.php
 *
 * These three areas (the cron pipeline, e-mail templates, notification
 * channels) had no coverage at all, although they are what decides whether
 * the user learns about an outage - and whether the system shows invented data.
 * What is deterministic gets tested: collection state evaluation, e-mail language,
 * e-mails, recipient/channel selection and text assembly.
 */

require_once __DIR__ . '/../lang.php';

require_once __DIR__ . '/assert_helpers.php';
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_get_collection_issues', 'bk_disk_label', 'bk_ranged_int', 'bk_get_network_insights', 'bk_lte_backup_state', 'bk_wan_link_state', 'bk_with_email_lang', 'bk_enrich_threshold_tip',
    'bk_relative_time_label', 'bk_format_duration', 'bk_compute_baseline_anomaly',
    'bk_half_window_rate', 'bk_latency_score', 'bk_notification_kinds',
]);


// =======================================================================
// 1. DATA COLLECTION - bk_get_collection_issues
// The project's hard rule: a collection outage MUST be visible. These tests
// guard that it is reported exactly when collection truly stops - no sooner, no later.
// =======================================================================
if (function_exists('bk_get_collection_issues')) {
    $healthy_monitor = ['status' => 'up', 'last_checked' => date('Y-m-d H:i:s')];

    check('zdravý monitor bez agenta nehlásí nic',
        count(bk_get_collection_issues($healthy_monitor, [])), 0);

    // The cPanel exporter is failing - exactly the scenario nobody saw for two weeks.
    $issues = bk_get_collection_issues($healthy_monitor, [
        'cpanel_stats_error' => ['error' => 'HTTP 403', 'hint' => 'Špatný klíč', 'since' => '2026-08-01T10:00:00+02:00'],
    ]);
    check('selhání cPanel sběru se hlásí', count($issues), 1);
    check('typ problému je cpanel_stats', $issues[0]['type'] ?? null, 'cpanel_stats');
    check('hint se propaguje k adminovi', $issues[0]['hint'] ?? null, 'Špatný klíč');
    check('začátek výpadku se propaguje', $issues[0]['since'] ?? null, '2026-08-01T10:00:00+02:00');

    // A silent agent: reported only after the timeout expires, not sooner.
    $fresh = bk_get_collection_issues($healthy_monitor, ['agent_last_seen' => time() - 60], 3000);
    check('čerstvý agent nehlásí problém', count($fresh), 0);

    $stale = bk_get_collection_issues($healthy_monitor, ['agent_last_seen' => time() - 7200], 3000);
    check_true('mlčící agent se hlásí',
        count(array_filter($stale, fn($i) => $i['type'] === 'agent_silent')) === 1);

    // A monitor that never had an agent must not report "the agent is silent".
    $never = bk_get_collection_issues($healthy_monitor, ['agent_last_seen' => 0], 3000);
    check('monitor bez agenta nehlásí mlčení agenta',
        count(array_filter($never, fn($i) => $i['type'] === 'agent_silent')), 0);

    // Stopped checks - a paused monitor is a legitimate state, not a fault.
    $paused = bk_get_collection_issues(
        ['status' => 'paused', 'last_checked' => date('Y-m-d H:i:s', time() - 86400)], []);
    check('pozastavený monitor nehlásí zastavené kontroly',
        count(array_filter($paused, fn($i) => $i['type'] === 'checks_stalled')), 0);

    $stalled = bk_get_collection_issues(
        ['status' => 'up', 'last_checked' => date('Y-m-d H:i:s', time() - 3600)], []);
    check_true('zastavené kontroly se hlásí',
        count(array_filter($stalled, fn($i) => $i['type'] === 'checks_stalled')) === 1);

    // --- Router (CORE 3.6, X15): SMART and the two records of dropped data ---
    // A disk card showing yesterday's values as if they were current is the
    // same silent loss as a dead exporter.
    $ci_probe = bk_get_collection_issues($healthy_monitor, ['agent_tools' => ['smart_probe_running_s' => 1000]]);
    check_true('zaseknuté čtení SMART se hlásí',
        count(array_filter($ci_probe, fn($i) => $i['type'] === 'smart_probe_stuck')) === 1);
    check('a říká, jak dlouho už visí', $ci_probe[0]['message'] ?? null,
        sprintf(t('collection_issue_smart_probe_stuck'), 16));
    check('krátká sonda SMART není problém',
        count(bk_get_collection_issues($healthy_monitor, ['agent_tools' => ['smart_probe_running_s' => 120]])), 0);

    $ci_disk = fn(?int $checked): array => ['storage_disks' => [[
        'name' => 'sdb', 'smart' => ['state' => 'error', 'checked_at' => $checked, 'model' => 'WDC WD10JPVX'],
    ]]];
    check_true('den nečitelný SMART se hlásí',
        count(array_filter(bk_get_collection_issues($healthy_monitor, $ci_disk(time() - 90000)),
            fn($i) => $i['type'] === 'smart_read_failing')) === 1);
    check('nikdy nepřečtený SMART se hlásí taky',
        bk_get_collection_issues($healthy_monitor, $ci_disk(null))[0]['message'] ?? null,
        sprintf(t('collection_issue_smart_read_failing'), 'WDC WD10JPVX (sdb)', t('collection_issue_smart_read_never')));
    check('čerstvě selhané čtení ještě není výpadek sběru',
        count(bk_get_collection_issues($healthy_monitor, $ci_disk(time() - 600))), 0);
    check('disk v pořádku nehlásí nic',
        count(bk_get_collection_issues($healthy_monitor, ['storage_disks' => [[
            'name' => 'sda', 'smart' => ['state' => 'ok', 'checked_at' => time() - 90000],
        ]]])), 0);

    $ci_dropped = bk_get_collection_issues($healthy_monitor, ['details_dropped' => ['storage_disks', 'wifi_radios']]);
    check('zahozený seznam disků se jmenuje', $ci_dropped[0]['message'] ?? null,
        sprintf(t('collection_issue_storage_list_dropped'), 'storage_disks, wifi_radios'));
    check('prázdný details_dropped nic nehlásí',
        count(bk_get_collection_issues($healthy_monitor, ['details_dropped' => []])), 0);

    // G42: minute reports that never arrived. The counting is cron's, this
    // only reads it - a partial loss used to be invisible until the agent
    // went silent for the whole 3000 s.
    $ci_missing = bk_get_collection_issues($healthy_monitor,
        ['reports_24h' => ['expected' => 1440, 'received' => 1280, 'checked_at' => time() - 600]]);
    check('chybějící minutová hlášení se hlásí i s počty', $ci_missing[0]['message'] ?? null,
        sprintf(t('collection_issue_reports_missing'), 160, 1440));
    check('agentovy vlastní přeskoky se k tomu dopíšou',
        bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 1440, 'received' => 1280, 'checked_at' => time()],
            'runs_skipped_lock' => 96, 'runs_skipped_post' => 4])[0]['message'] ?? null,
        sprintf(t('collection_issue_reports_missing'), 160, 1440) . ' ' . sprintf(t('collection_issue_reports_missing_skips'), 96, 4));
    // Agent 0.1.9: runs the lock takeover stopped are the third reason, in a
    // sentence of their own; an older agent's missing counter adds nothing.
    check('běh ukončený po 5 minutách visení se dopíše jako třetí důvod',
        bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 1440, 'received' => 1280, 'checked_at' => time()],
            'runs_skipped_lock' => 96, 'runs_skipped_post' => 4, 'runs_skipped_killed' => 3])[0]['message'] ?? null,
        sprintf(t('collection_issue_reports_missing'), 160, 1440) . ' ' . sprintf(t('collection_issue_reports_missing_skips'), 96, 4)
            . ' ' . sprintf(t('collection_issue_reports_missing_killed'), 3));
    check('samotné ukončené běhy se dopíšou i bez ostatních přeskoků',
        bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 1440, 'received' => 1280, 'checked_at' => time()],
            'runs_skipped_lock' => 0, 'runs_skipped_post' => 0, 'runs_skipped_killed' => 2])[0]['message'] ?? null,
        sprintf(t('collection_issue_reports_missing'), 160, 1440) . ' ' . sprintf(t('collection_issue_reports_missing_killed'), 2));
    check('nula ani chybějící počet ukončených běhů (agent do 0.1.8) nic nedopíše',
        [bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 1440, 'received' => 1280, 'checked_at' => time()],
            'runs_skipped_killed' => 0])[0]['message'] ?? null,
         bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 1440, 'received' => 1280, 'checked_at' => time()],
            'runs_skipped_killed' => null])[0]['message'] ?? null],
        [sprintf(t('collection_issue_reports_missing'), 160, 1440), sprintf(t('collection_issue_reports_missing'), 160, 1440)]);
    $ci_killed_cs = (require __DIR__ . '/../lang/cs.php')['collection_issue_reports_missing_killed'] ?? '';
    $ci_killed_en = (require __DIR__ . '/../lang/en.php')['collection_issue_reports_missing_killed'] ?? '';
    check('třetí důvod má český i anglický text a oba nesou počet',
        [sprintf($ci_killed_cs, 3), sprintf($ci_killed_en, 3)],
        ['Běh visel 5 minut a byl ukončen: 3×.', 'A run hung for 5 minutes and was stopped: 3×.']);
    check('ztráta pod deseti procenty se nehlásí',
        count(bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 1440, 'received' => 1300, 'checked_at' => time()]])), 0);
    check('krátké okno (router po restartu) se nesoudí',
        count(bk_get_collection_issues($healthy_monitor, ['reports_24h' => ['expected' => 100, 'received' => 10, 'checked_at' => time()]])), 0);

    $ci_ingest = bk_get_collection_issues($healthy_monitor, ['ingest_issues' => [
        ['type' => 'passthrough_too_large', 'key' => 'logread', 'bytes' => 12000],
        ['type' => 'metrics_insert_failed'],
    ]]);
    check('neuložená část hlášení se jmenuje', $ci_ingest[0]['message'] ?? null,
        sprintf(t('collection_issue_ingest_dropped'), 'passthrough_too_large (logread), metrics_insert_failed'));
    check('prázdné ingest_issues nic nehlásí',
        count(bk_get_collection_issues($healthy_monitor, ['ingest_issues' => []])), 0);
}

// =======================================================================
// 1b. NETWORK INSIGHTS - a radio without a measured noise floor (CORE 3.10)
// `?? -95` used to turn "iwinfo printed unknown" into a clean band nobody
// measured - and agent 0.1.7 sends null exactly where that happened.
// =======================================================================
if (function_exists('bk_get_network_insights')) {
    // The insights start with one query; a stub that refuses it leaves the
    // rest of the function exactly as production runs it.
    $ni_pdo = new class {
        public function prepare(string $sql) { throw new PDOException('no db in a pure test'); }
    };
    $ni = fn (array $radio): int => count(bk_get_network_insights($ni_pdo, ['id' => 2], ['wifi_radios' => [$radio]]));
    check('rádio bez změřeného šumu rušení nehlásí', $ni(['radio' => 'phy0-ap0', 'ssid' => 'X', 'noise' => null]), 0);
    check('a chybějící klíč šumu taky ne', $ni(['radio' => 'phy0-ap0', 'ssid' => 'X']), 0);
    check('změřený šum −60 dBm rušení hlásí', $ni(['radio' => 'phy0-ap0', 'ssid' => 'X', 'noise' => -60]), 1);
    check('čistý kanál −95 dBm nehlásí', $ni(['radio' => 'phy0-ap0', 'ssid' => 'X', 'noise' => -95]), 0);

    // X17: a lookup made on a line saturated by the router's own speed test is
    // slow because of the test. The value is stored and charted, only not
    // turned into advice.
    $ni_dns = fn (array $extra): int => count(bk_get_network_insights($ni_pdo, ['id' => 2], array_merge(['dns_latency_ms' => 620.0], $extra)));
    check('pomalé DNS se běžně hlásí', $ni_dns([]), 1);
    check('pomalé DNS během testu se nehlásí', $ni_dns(['speedtest_active' => true]), 0);

    // G31: the OOM counter only resets at the next reboot, so without the
    // event's timestamp the warning hung on the page for weeks.
    $ni_oom = fn (array $extra): int => count(bk_get_network_insights($ni_pdo, ['id' => 2], array_merge(['oom_kills' => 3], $extra)));
    check('OOM z posledních 24 h se hlásí', $ni_oom(['oom_kill_at' => time() - 3600]), 1);
    check('týden starý OOM už ne', [$ni_oom(['oom_kill_at' => time() - 7 * 86400]), $ni_oom([])], [0, 0]);
}

// =======================================================================
// 2. E-MAILY - bk_with_email_lang
// E-mails have no visitor, so the setting decides the language. The test guards
// that the globals switch AND switch back - otherwise the first sent e-mail
// would flip the language of the whole running request (and the user's page).
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

    // A nonsense language code must not break sending - fallback to Czech.
    $fallback = bk_with_email_lang('xx', fn() => t('day_no_data'));
    check('neznámý jazyk padá na češtinu', $fallback, $cs_text);

    // An exception inside the builder must not leave the globals switched.
    try {
        bk_with_email_lang('en', function () { throw new RuntimeException('boom'); });
    } catch (RuntimeException $e) {
        // expected
    }
    check('výjimka v šabloně nenechá přepnutý jazyk', $GLOBALS['BK_LANG'], 'cs');
}

// =======================================================================
// 3. ALERT TEXTS - bk_enrich_threshold_tip
// Tip enrichment may only use data that really arrived. An invented
// "top process" or "Wi-Fi clients: 0" would be exactly the kind of lie this
// project went through a fabricated-data purge over.
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

    // Without Wi-Fi telemetry, clients must not be written about at all.
    $no_wifi = bk_enrich_threshold_tip([
        'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61]],
    ], 'cpu');
    check_false('bez telemetrie se nepíše o klientech', str_contains(mb_strtolower($no_wifi), 'klient'));

    // Channel utilisation from iwinfo survey: the busiest radio that REALLY
    // measured it is taken - a driver without survey support (busy_pct null)
    // must neither produce an invented zero nor enter the selection.
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

    // The generic kt_rec_wifi advice MAY talk about the channel - what must not be
    // invented is a MEASUREMENT. The radio name appears only with a measured value.
    $busy_none = bk_enrich_threshold_tip([
        'top_cpu_processes' => [['name' => 'hostapd', 'cpu' => 61]],
        'wifi_radios' => [['radio' => 'wlan0', 'busy_pct' => null]],
    ], 'cpu');
    check_false('samá null měření = žádné jméno rádia s číslem', str_contains($busy_none, 'wlan0'));

    // The RAM variant takes the memory ranking, not CPU.
    $ram_tip = bk_enrich_threshold_tip([
        'top_ram_processes' => [['name' => 'java', 'ram_mb' => 2048]],
    ], 'ram');
    check_true('RAM tip jmenuje paměťového viníka', str_contains($ram_tip, 'java'));
}

// =======================================================================
// 4. ANOMALY AND TREND DETECTION (the basis for notifications and insights)
// =======================================================================
if (function_exists('bk_compute_baseline_anomaly')) {
    // A stable series + a value in the norm = no anomaly.
    $stable = array_fill(0, 30, 20.0);
    check('hodnota v normálu není anomálie',
        bk_compute_baseline_anomaly($stable, 21.0, 5.0), null);

    // A marked deviation above the baseline must be an anomaly.
    $spike = bk_compute_baseline_anomaly($stable, 90.0, 5.0);
    check_true('výrazný výkyv je anomálie', is_array($spike));

    // Empty history must claim nothing (there is nothing to compare with).
    check('bez historie se anomálie nehlásí',
        bk_compute_baseline_anomaly([], 90.0, 5.0), null);
}

if (function_exists('bk_half_window_rate')) {
    // Growing disk usage - the basis for the "full in N days" prediction.
    $rows = [];
    for ($i = 0; $i < 14; $i++) {
        $rows[] = ['checked_at' => date('Y-m-d H:i:s', strtotime("-" . (14 - $i) . " days")), 'hdd_usage' => 50 + $i];
    }
    $rate = bk_half_window_rate($rows, 'hdd_usage');
    check_true('růst disku se detekuje', is_array($rate) && $rate['rate_per_day'] > 0);

    // A flat series must not generate a fill-up forecast.
    $flat = [];
    for ($i = 0; $i < 14; $i++) {
        $flat[] = ['checked_at' => date('Y-m-d H:i:s', strtotime("-" . (14 - $i) . " days")), 'hdd_usage' => 50];
    }
    $flat_rate = bk_half_window_rate($flat, 'hdd_usage');
    check_true('plochá řada nemá růst',
        $flat_rate === null || abs($flat_rate['rate_per_day']) < 0.01);
}

// =======================================================================
// 5. FORMATTING IN NOTIFICATIONS
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
    'casovy souhrn dnu (W1-B1) bezi taky pred mazanim logu',
    (function () {
        $cron = file_get_contents(__DIR__ . '/../cron.php');
        $time_pos = strpos($cron, 'bk_rollup_daily_uptime_time(');
        $delete_pos = strpos($cron, 'DELETE FROM monitor_logs');
        // Den, jehoz logy uz jsou smazane, se v case prepocitat neda - zustal
        // by jen s pocty kontrol a mlceni agenta by v nem nebylo.
        return $time_pos !== false && $delete_pos !== false && $time_pos < $delete_pos;
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
// ignore it - and then miss the real one too.
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

// --- cron: an alert fires once, and a failed check stays failed ------------
// The agent-silence latch was written to the database while the loop kept a
// stale copy of last_details and wrote that back later, so every run re-sent
// the alert. These guard the order of operations, which no pure test can see.
$cron_src = file_get_contents(__DIR__ . '/../cron.php');
check_true('cron po zápisu pojistky aktualizuje svou kopii last_details',
    (bool)preg_match('/\$stmt_up_agent->execute\(\[\$new_details, \$id\]\);\s*(?:\/\/[^\n]*\n\s*)*\$monitor\[\'last_details\'\] = \$new_details;/', $cron_src));
// The fresh reads only help where they sit: at the start of the monitor, after
// the check and the agent fallback but before the merge, and after the latency
// alert is sent but before its write. Order is checked, not just the count.
$fresh_positions = [];
$offset = 0;
while (($pos = strpos($cron_src, '$stmt_fresh_details->execute([$id]);', $offset)) !== false) {
    $fresh_positions[] = $pos;
    $offset = $pos + 1;
}
check('cron čte last_details čerstvě na třech místech', count($fresh_positions), 3);
$cron_before = fn(string $needle, int $pos) => ($p = strpos($cron_src, $needle)) !== false && $pos < $p;
$cron_after = fn(string $needle, int $pos) => ($p = strpos($cron_src, $needle)) !== false && $pos > $p;
check_true('první čtení je před pojistkou mlčícího agenta',
    isset($fresh_positions[0]) && $cron_before('$details_arr[\'agent_alert_sent\'] = true;', $fresh_positions[0]));
check_true('druhé čtení je po záloze přes agenta a před sloučením detailů',
    isset($fresh_positions[1]) && $cron_after('if (bk_agent_backup_says_up(', $fresh_positions[1])
    && $cron_before('// Merge old details', $fresh_positions[1]));
check_true('třetí čtení je po alertu latence a před jeho zápisem',
    isset($fresh_positions[2]) && $cron_after('trigger_notifications($pdo, $monitor, \'latency_\'', $fresh_positions[2])
    && $cron_before('$stmt_lat->execute(', $fresh_positions[2]));

// The call itself is matched - the helper's name also appears in comments.
check_true('záloha přes agenta rozhoduje voláním bk_agent_backup_says_up',
    (bool)preg_match('/if \(bk_agent_backup_says_up\(\(string\)\$type, \$details_decoded, \$monitor, time\(\)\)\) \{/', $cron_src));
check_false('záloha přes agenta nemá vlastní pravidla portů webu',
    (bool)preg_match('/in_array\((80|443),/', $cron_src));
check_true('záloha zapisuje jen svůj rozdíl, ne starou kopii detailů',
    str_contains($cron_src, '$details = json_encode($fallback_details') && !str_contains($cron_src, 'json_encode($details_decoded'));

// Both branches of check_http must RETURN the verdict, and cron must hand it the keyword.
$fn_src = file_get_contents(__DIR__ . '/../functions.php');
check('check_http vrací verdikt bk_http_verdict v cURL i náhradní větvi',
    preg_match_all('/\$verdict = bk_http_verdict\([^;]*\$body_keyword\);\s*return array_merge\(\[\s*\'status\' => \$verdict\[\'status\'\],\s*\'response_time\' => \$duration,\s*\'error\' => \$verdict\[\'error\'\]/', $fn_src), 2);
check('cron předává klíčové slovo první kontrole i opakování',
    substr_count($cron_src, 'check_http($target, $timeout, $monitor[\'body_keyword\'] ?? null)'), 2);
check_false('SMS neřeže chybovou zprávu po bajtech',
    (bool)preg_match('/substr\(\$error_msg, 0,/', $fn_src));


// --- Storage alerts: colour, page, and what a recovery does NOT do ---------
// (CORE 6.4). `storage_recovered` is shared by `disk_temp_normal`, `fs_freed`
// and by the OTHER disks of the same router, so an automatic resolve could
// close the page of a disk that is still failing.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_pagerduty_action', 'bk_alert_color_class', 'bk_pagerduty_dedup_key',
]);
if (function_exists('bk_pagerduty_action')) {
    check('storage_failing pageuje', bk_pagerduty_action('storage_failing'), 'trigger');
    check('storage_recovered nezavírá incident', bk_pagerduty_action('storage_recovered'), null);
    check('storage_warning nepageuje', bk_pagerduty_action('storage_warning'), null);
    check('výpadek WAN nezavře incident disku',
        [bk_pagerduty_dedup_key(6, 'storage_failing'), bk_pagerduty_dedup_key(6, 'wan_restored')],
        ['bk-monitor-6-storage', 'bk-monitor-6']);
    check('barvy tří stavů úložiště',
        [bk_alert_color_class('storage_failing'), bk_alert_color_class('storage_warning'),
            bk_alert_color_class('storage_recovered')],
        ['bad', 'warn', 'good']);
}

// =======================================================================
// 5. ROUTERS IN THE WEEKLY DIGEST (CORE 3.8)
// The e-mail is what most owners ever read, so what it prints has to be
// decided by data and not by the language it happens to be built in: the
// split into "new" and "unchanged", the facts line and the sentences of
// each item.
// =======================================================================
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_router_rec_thresholds', 'bk_rec_num', 'bk_rec_band_label', 'bk_rec_item', 'bk_rec_sort_items',
    'bk_router_rec_split', 'bk_router_rec_render', 'bk_digest_router_new_split', 'bk_digest_router_facts_lines',
    'bk_rec_days_with_data', 'bk_router_rec_window', 'bk_format_bytes_cz',
]);

if (function_exists('bk_digest_router_new_split')) {
    $week = '2026-W39';
    $dg_item = fn (string $key, string $sev): array => bk_rec_item('disk_temp_warm', $key, 'storage', $sev,
        ['kind' => 'disk', 'disk_key' => $key], ['disk' => 'SSD (sda)', 'name' => 'sda', 'avg_c' => 67, 'max_c' => 69, 'limit_c' => 70]);

    $split = bk_digest_router_new_split([$dg_item('a', 'warning')], [], $week);
    check('položka bez historie jde do e-mailu celá', count($split['full']), 1);

    $split = bk_digest_router_new_split([$dg_item('a', 'warning')],
        ['a' => ['severity' => 'warning', 'first_digest_week' => '2026-W30']], $week);
    check('stará položka je jen titulek v řádku beze změny', [count($split['full']), count($split['open'])], [0, 1]);

    $split = bk_digest_router_new_split([$dg_item('a', 'critical')],
        ['a' => ['severity' => 'critical', 'first_digest_week' => '2026-W30']], $week);
    check('kritická položka je celá i po letech', count($split['full']), 1);

    $split = bk_digest_router_new_split([$dg_item('a', 'warning')],
        ['a' => ['severity' => 'info', 'first_digest_week' => '2026-W30']], $week);
    check('položka, která se zhoršila, je zase celá', count($split['full']), 1);

    $split = bk_digest_router_new_split([$dg_item('a', 'info')],
        ['a' => ['severity' => 'warning', 'first_digest_week' => '2026-W30']], $week);
    check('položka, která se zlepšila, celá není', count($split['open']), 1);

    // A retry of the same week must render the same e-mail: the first build
    // stamped first_digest_week with THIS week.
    $split = bk_digest_router_new_split([$dg_item('a', 'warning')],
        ['a' => ['severity' => 'warning', 'first_digest_week' => $week]], $week);
    check('opakované sestavení ve stejném týdnu vypadá stejně', count($split['full']), 1);
}

if (function_exists('bk_router_rec_split')) {
    $mute_item = bk_rec_item('disk_temp_warm', 'disk_temp_warm:d:x', 'storage', 'warning',
        ['kind' => 'disk', 'disk_key' => 'x'], ['disk' => 'SSD (sda)', 'name' => 'sda']);
    $muted = ['disk_temp_warm:d:x' => ['muted_at' => '2026-09-01 10:00:00', 'muted_severity' => 'warning',
        'mute_reason' => 'vím o tom', 'first_seen' => '2026-08-01 10:00:00']];
    $res = bk_router_rec_split([$mute_item], $muted);
    check('ztlumená položka se do e-mailu nedostane', [count($res['items']), count($res['muted'])], [0, 1]);

    $worse = $mute_item;
    $worse['severity'] = 'critical';
    $res = bk_router_rec_split([$worse], $muted);
    check('ztlumená položka se vrátí, když se zhorší', count($res['items']), 1);
    check_true('a přizná, že byla ztlumená', !empty($res['items'][0]['params']['was_muted']));
    check('otevřeno od data z uloženého stavu', $res['items'][0]['openSince'], '2026-08-01 10:00:00');
}

if (function_exists('bk_router_rec_render')) {
    $GLOBALS['BK_LANG'] = 'cs';
    $GLOBALS['BK_STRINGS'] = require __DIR__ . '/../lang/cs.php';
    $warm = bk_rec_item('disk_temp_warm', 'disk_temp_warm:d:x', 'storage', 'warning', ['kind' => 'disk'],
        ['disk' => 'KINGSTON (sda)', 'name' => 'sda', 'avg_c' => 67.0, 'max_c' => 69.0, 'limit_c' => 70]);
    $cs = bk_router_rec_render($warm);
    check('titulek pravidla zná jméno disku', $cs['title'], 'Disk sda je trvale teplý');
    check_true('naměřená věta nese průměr, maximum i limit',
        str_contains($cs['measured'], '67') && str_contains($cs['measured'], '69') && str_contains($cs['measured'], '70'));
    check_true('rada říká, kdy teprve přijde upozornění', str_contains($cs['action'], '70 °C'));
    $en = bk_with_email_lang('en', fn () => bk_router_rec_render($warm));
    check('stejná položka se vykreslí i anglicky', $en['title'], 'Disk sda runs warm all the time');
    check('a jazyk se vrátí zpátky', $GLOBALS['BK_LANG'], 'cs');

    // A rule whose text was never written must still say WHAT fired. The id
    // is deliberately one no rule uses: every id of the rank list now has its
    // texts (the Wi-Fi twelve landed last), so a real one would not reach the
    // fallback any more.
    $unknown = bk_rec_item('future_rule', 'future_rule:5g', 'wifi', 'warning', ['kind' => 'band'], []);
    check('pravidlo bez textů se přizná svým id', bk_router_rec_render($unknown)['title'], 'future_rule');

    $fs = bk_rec_item('fs_nearly_full', 'fs_nearly_full:m:abc', 'storage', 'critical', ['kind' => 'mount'],
        ['mount' => '/srv', 'pct' => 98.5, 'free' => 1073741824.0, 'schnapps' => true]);
    $fs_cs = bk_router_rec_render($fs);
    check('plný oddíl se jmenuje přípojným bodem', $fs_cs['title'], 'Plný oddíl /srv');
    check_true('a rada na Turrisu zmíní snapshoty', str_contains($fs_cs['action'], 'schnapps'));
}

if (function_exists('bk_digest_router_facts_lines')) {
    $lines = bk_digest_router_facts_lines(['radios' => [[
        'band' => '5GHz', 'channel' => 36, 'generation' => 6, 'width_mhz' => 80, 'encryption' => 'wpa2_wpa3',
        'clients' => 4, 'clients_gen' => ['wifi6' => 2, 'wifi5' => 1, 'wifi4' => 1],
        'noise_week' => -92.0, 'busy_week' => 3.5,
    ]], 'disks' => [[
        'name' => 'sda', 'model' => 'KINGSTON SUV500MS120G', 'transport' => 'sata', 'rotational' => false,
        'size_bytes' => 120034123776, 'smart_state' => 'ok', 'smart_passed' => true, 'temperature_c' => 67,
        'wear_pct' => 0.0, 'written_bytes' => 451021000000, 'runtime_bad_blocks' => 3, 'reallocated_sectors' => 0,
    ]]]);
    check('fakta popíšou rádio i disk', count($lines), 2);
    check_true('řádek rádia nese pásmo, generaci, šířku, kanál a klienty',
        str_contains($lines[0], '5 GHz · Wi-Fi 6 · 80 MHz · kanál 36')
        && str_contains($lines[0], '4 klienti (Wi-Fi 6: 2, Wi-Fi 5: 1, Wi-Fi 4: 1)'));
    check_true('a týdenní šum i vytížení', str_contains($lines[0], 'šum -92 dBm') && str_contains($lines[0], 'vytížení 3,5 %'));
    check_true('řádek disku přizná stabilní vadné bloky místo poplachu',
        str_contains($lines[1], 'vadné bloky za běhu: 3') && str_contains($lines[1], 'SMART v pořádku'));
    check_false('nulové přemapované sektory se nevypisují', str_contains($lines[1], 'přemapované'));
    // No SSID may ever appear in a router text (CORE 3.7 / 3.10).
    check_false('fakta neobsahují SSID', str_contains(implode(' ', $lines), 'Domov'));

    $sparse = bk_digest_router_facts_lines(['radios' => [[
        'band' => '2.4GHz', 'channel' => null, 'generation' => null, 'width_mhz' => null, 'encryption' => null,
        'clients' => null, 'clients_gen' => null, 'noise_week' => null, 'busy_week' => null,
    ]], 'disks' => []]);
    check('nezměřené údaje se vynechávají, ne nulují', $sparse[0], '2,4 GHz');
}

if (function_exists('bk_rec_days_with_data')) {
    $window = bk_router_rec_window('2026-09-21');
    check('týden končí dnem před zadaným', [$window['days'][0], end($window['days'])], ['2026-09-14', '2026-09-20']);
    check('předchozí týden na něj navazuje', end($window['prev_days']), '2026-09-13');
    $window['metrics']['cpu'] = [
        '2026-09-14' => ['avg' => 5.0, 'samples' => 1440],
        '2026-09-15' => ['avg' => 5.0, 'samples' => 300],
        '2026-09-16' => ['avg' => 5.0, 'samples' => 1440],
        '2026-09-13' => ['avg' => 5.0, 'samples' => 1440],
    ];
    check('den s málo vzorky se do týdne nepočítá a starší den taky ne', bk_rec_days_with_data($window), 2);
}

// --- cron: the router tables really are pruned ----------------------------
// Nothing executes cron.php, so the only thing that can be checked here is
// that the two retention functions are CALLED, and called inside the prune
// try block - outside it a PDOException would take the whole cron run down
// with it, and every later step (digests, rollups) would stop with it.
$prune_block = (function () use ($cron_src): string {
    $start = strpos($cron_src, '// Prune old logs');
    $end = strpos($cron_src, 'Chyba při čištění starých logů', $start === false ? 0 : $start);
    return $start !== false && $end !== false ? substr($cron_src, $start, $end - $start) : '';
})();
check_true('cron maže historii disků a stavy doporučení voláním bk_prune_router_health',
    str_contains($prune_block, 'bk_prune_router_health($pdo)'));
check_true('cron maže stará měření WAN voláním bk_prune_wan_data',
    str_contains($prune_block, 'bk_prune_wan_data($pdo)'));
check_true('cron řekne, kolik toho smazal',
    str_contains($prune_block, 'Zdraví routeru: smazáno ') && str_contains($prune_block, 'Měření WAN: smazáno '));

// --- The Routers section is WEEKLY only (CORE 3.8, X16) --------------------
// `build_digest_data` needs thirty other functions, so the guard is read from
// the source: the section asks what a WEEK of measurements says, and a
// monthly e-mail carrying it would compare a week's items with a month's
// heading. The mutation "call bk_digest_routers unconditionally" turns this
// red and nothing else does.
$fn_src = (string)file_get_contents(__DIR__ . '/../functions.php');
$digest_routers_call = (function () use ($fn_src): string {
    $at = strpos($fn_src, '$rt = bk_digest_routers(');
    if ($at === false) {
        return '';
    }
    $from = max(0, $at - 200);
    return substr($fn_src, $from, $at - $from);
})();
check_true('sekce Routery se staví jen pro týdenní přehled',
    str_contains($digest_routers_call, '$period === ' . "'weekly'"));
check_true('měsíční přehled sekci Routery nestaví',
    substr_count($fn_src, 'bk_digest_routers($pdo') === 1);

// =======================================================================
// Outgoing message log - the logging has to sit in ONE place
// =======================================================================
// bk_log_notification() used to be called at ONE of the nine send_email()
// call sites, so only alerts left a trace and "did that invitation go out?"
// had no answer. The logging moved inside send_email(); these checks guard
// that it stays there, alone, and that every call site says WHICH kind of
// message it is sending.
$mail_src = (string)file_get_contents(__DIR__ . '/../functions.php');
// Comments explain WHY the second log call is gone - and would otherwise make
// the checks below believe it is still there.
$without_comments = fn(string $code): string => (string)preg_replace('~^\s*(//|\*|/\*).*$~m', '', $code);

$send_email_body = (function () use ($mail_src): string {
    $start = strpos($mail_src, 'function send_email(');
    $end = strpos($mail_src, 'function bk_deliver_email(', $start === false ? 0 : $start);
    return $start !== false && $end !== false ? substr($mail_src, $start, $end - $start) : '';
})();
check_true('send_email() přijímá kontext zprávy jako pátý argument',
    str_contains($send_email_body, 'array $context = []'));
check_true('a sám zapíše jeden řádek do protokolu',
    substr_count($send_email_body, 'bk_log_notification(') === 1);
check_true('protokolu se předává předmět, nikdy tělo zprávy',
    str_contains($send_email_body, '$subject') && !str_contains($send_email_body, 'bk_log_notification($html_body')
    && !preg_match('/bk_log_notification\((?:[^;]*?)\$html_body/s', $send_email_body));

$deliver_body = (function () use ($mail_src): string {
    $start = strpos($mail_src, 'function bk_deliver_email(');
    $end = strpos($mail_src, 'function send_sms(', $start === false ? 0 : $start);
    return $start !== false && $end !== false ? substr($mail_src, $start, $end - $start) : '';
})();
check_true('doručovací funkce sama nic neprotokoluje (jinak by řádky byly dva)',
    $deliver_body !== '' && !str_contains($without_comments($deliver_body), 'bk_log_notification('));

// The e-mail branch of trigger_notifications(): send_email() logs the attempt,
// so the explicit call that used to stand right after it would double every row.
$alert_mail_branch = (function () use ($mail_src): string {
    $start = strpos($mail_src, "\$alert_email_by_lang[\$rec_lang];");
    $end = strpos($mail_src, '// SMS notifications', $start === false ? 0 : $start);
    return $start !== false && $end !== false ? substr($mail_src, $start, $end - $start) : '';
})();
check_true('větev alertu e-mailem neprotokoluje podruhé',
    $alert_mail_branch !== '' && !str_contains($without_comments($alert_mail_branch), 'bk_log_notification('));
check_true('a předává druh zprávy i monitor', str_contains($alert_mail_branch, "'kind' => 'alert'")
    && str_contains($alert_mail_branch, "'monitor_id' =>"));

// Every call site names its kind. Without this a new feature adds a tenth
// send_email() and its messages land in the log as anonymous 'other'.
$kind_gaps = [];
$kind_calls = 0;
$kinds_used = [];
foreach (['functions.php', 'api.php', 'admin.php'] as $mail_file) {
    $src = (string)file_get_contents(__DIR__ . '/../' . $mail_file);
    preg_match_all('/(?<![a-z_])send_email\s*\(/', $src, $hits, PREG_OFFSET_CAPTURE);
    foreach ($hits[0] as [$_, $offset]) {
        $line_no = substr_count($src, "\n", 0, $offset) + 1;
        $line = strtok(substr($src, (int)strrpos(substr($src, 0, $offset), "\n")), "\n");
        if (str_contains((string)$line, 'function send_email(') || str_starts_with(ltrim((string)$line), '*')
            || str_starts_with(ltrim((string)$line), '//')) {
            continue;
        }
        $kind_calls++;
        // The arguments may wrap over several lines - look at the call as a whole.
        $call = substr($src, $offset, 400);
        if (!str_contains($call, "'kind' =>")) {
            $kind_gaps[] = $mail_file . ':' . $line_no;
        }
        if (preg_match("/'kind' => '([a-z_]+)'/", $call, $km)) {
            $kinds_used[$km[1]] = true;
        }
    }
}
// Nine call sites when the log was built, ten since the daily reminder,
// eleven since the admin notice (the self-check). The number is hard-coded on
// purpose: a new send_email() has to be noticed here, where someone decides
// which kind it writes into the log.
check_true('kontrola opravdu našla všech jedenáct volání', $kind_calls === 11);
check('každé volání send_email() uvádí druh zprávy', $kind_gaps, []);

if (function_exists('bk_notification_kinds')) {
    $kinds = bk_notification_kinds();
    check_true('seznam druhů zná výstrahu i denní připomínku',
        in_array('alert', $kinds, true) && in_array('daily_reminder', $kinds, true));
    check('a nemá duplicity', count($kinds), count(array_unique($kinds)));
    // Every kind that a call site really uses must be in the canonical list,
    // otherwise the admin filter would offer a value nothing ever writes - or
    // worse, hide one that does.
    check('použité druhy jsou všechny v kanonickém seznamu',
        array_values(array_diff(array_keys($kinds_used), $kinds)), []);
}

// Every logged attempt says what is known about it: sent, failed, unknown or
// skipped. A call without it leaves delivery NULL, which reads back as a
// guess from ok - the guess that logged a refused CallMeBot message as sent.
// Tokens, not a regex: the calls span lines, carry comments and nest parens.
$delivery_gaps = [];
$delivery_calls = 0;
foreach (['functions.php', 'api.php', 'admin.php', 'cron.php', 'agent_api.php'] as $log_file) {
    $tokens = token_get_all((string)file_get_contents(__DIR__ . '/../' . $log_file));
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'bk_log_notification') {
            continue;
        }
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            $prev--;
        }
        if ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (($tokens[$j] ?? null) !== '(') {
            continue;
        }
        $delivery_calls++;
        $depth = 0;
        $named = false;
        for (; $j < $n; $j++) {
            $tok = $tokens[$j];
            if ($tok === '(') {
                $depth++;
            } elseif ($tok === ')') {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($depth === 1 && is_array($tok) && $tok[0] === T_STRING && $tok[1] === 'delivery') {
                $k = $j + 1;
                while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    $k++;
                }
                $named = $named || ($tokens[$k] ?? null) === ':';
            }
        }
        if (!$named) {
            $delivery_gaps[] = $log_file . ':' . $tokens[$i][2];
        }
    }
}
// Hard-coded like the send_email() count above: a new call has to be noticed
// here, where somebody decides what its row may claim. 14, then 12: the
// reminder's four shared-channel calls became one in bk_send_shared_channels(),
// and the admin notice added its WhatsApp row.
check('kontrola našla všech 12 volání protokolu', $delivery_calls, 12);
check('každé volání protokolu uvádí výsledek doručení (delivery:)', $delivery_gaps, []);

// SMS: a refusal says why. Only the paths that stop before any request are
// run here; nothing in this suite may reach a gateway.
bk_test_load_functions(__DIR__ . '/../functions.php', ['send_sms']);
bk_test_load_functions(__DIR__ . '/../db.php', ['get_setting', 'bk_settings_defaults']);
if (function_exists('send_sms') && function_exists('get_setting')) {
    $sms_saved = $GLOBALS['system_settings'] ?? null;
    $GLOBALS['system_settings'] = ['sms_gateway_type' => ''];
    check('bez brány SMS neodejde', send_sms('+420777123456', 'x'), false);
    check('a řekne proč', $GLOBALS['last_sms_error'] ?? null, 'No SMS gateway is configured.');
    $GLOBALS['system_settings'] = ['sms_gateway_type' => 'twilio'];
    check('nenastavené Twilio neodešle', send_sms('+420777123456', 'x'), false);
    check_true('a jmenuje, co chybí', str_contains((string)($GLOBALS['last_sms_error'] ?? ''), 'twilio_sid'));
    $GLOBALS['system_settings'] = ['sms_gateway_type' => 'smsbrana'];
    check('nenastavená SMSbrána neodešle', send_sms('+420777123456', 'x'), false);
    check_true('a jmenuje, co chybí', str_contains((string)($GLOBALS['last_sms_error'] ?? ''), 'smsbrana_user'));
    $GLOBALS['system_settings'] = $sms_saved;
}

// =======================================================================
// E-mail - what the row may claim
//
// ok = 1 covered both an SMTP 250 and a mail() that merely returned true.
// Only the first is a confirmation; the second is "handed over, unknown".
// =======================================================================
bk_test_load_functions(__DIR__ . '/../functions.php', ['bk_smtp_failure_reason', 'bk_mail_missing_settings']);
if (function_exists('bk_smtp_failure_reason')) {
    check('důvod selhání SMTP bere ErrorInfo a kód serveru',
        bk_smtp_failure_reason('SMTP Error: data not accepted.', 'x',
            ['error' => 'DATA END command failed', 'detail' => 'Message rejected', 'smtp_code' => '550', 'smtp_code_ex' => '5.7.1'], 'a@b.cz'),
        'SMTP Error: data not accepted. (server: 550 5.7.1 Message rejected)');
    // '' ?? 'x' is '' - an empty ErrorInfo used to leave the reason empty.
    check('prázdné ErrorInfo propadne na text výjimky',
        bk_smtp_failure_reason('', 'SMTP connect() failed.', [], 'a@b.cz'), 'SMTP connect() failed.');
    check('adresa příjemce se v důvodu nahradí',
        bk_smtp_failure_reason('SMTP Error: The following recipients failed: Nekdo@Example.com: 550 No such user', '',
            ['detail' => 'No such user'], 'nekdo@example.com'),
        'SMTP Error: The following recipients failed: <recipient>: 550 No such user');
    check('i adresa odesílatele (přihlašovací jméno SMTP)',
        bk_smtp_failure_reason('SMTP Error: The following From address failed: Status@Example.com : 553 not allowed', '',
            [], 'nekdo@example.com', 'status@example.com'),
        'SMTP Error: The following From address failed: <sender> : 553 not allowed');
    check('bez jakéhokoli textu zůstane srozumitelná věta',
        bk_smtp_failure_reason('', '', [], ''), 'SMTP send failed without a message.');
    check('chybějící nastavení SMTP se vyjmenují',
        bk_mail_missing_settings('', 'odesilatel@example.com', ' ', true), ['smtp_host', 'smtp_pass']);
    check('i chybějící knihovna', bk_mail_missing_settings('h', 'u', 'p', false), ['lib/PHPMailer.php']);
}

// The mail() fallback runs only in a child PHP whose sendmail is `cat`, so no
// message can leave this machine; the address is .invalid on top of that.
$mail_child = function (string $sendmail): array {
    $code = 'require ' . var_export(__DIR__ . '/assert_helpers.php', true) . ';'
        . 'bk_test_load_functions(' . var_export(__DIR__ . '/../functions.php', true)
        . ', ["bk_deliver_email", "bk_mail_missing_settings", "bk_smtp_failure_reason"]);'
        . 'bk_test_load_functions(' . var_export(__DIR__ . '/../db.php', true) . ', ["get_setting", "bk_settings_defaults"]);'
        . '$GLOBALS["system_settings"] = ["smtp_host" => "", "smtp_user" => "", "smtp_pass" => "", "site_title" => "T"];'
        . '$ok = bk_deliver_email("nikdo@example.invalid", "Předmět", "<p>tělo</p>");'
        . 'echo json_encode([ini_get("sendmail_path"), $ok, $GLOBALS["last_mail_method"], $GLOBALS["last_mail_delivery"],'
        . ' $GLOBALS["last_mail_error"], $GLOBALS["last_mail_reply"]]);';
    $out = [];
    // Quoted: in an ini value an unquoted ';' starts a comment.
    exec(escapeshellarg(PHP_BINARY) . ' -d ' . escapeshellarg('sendmail_path="' . $sendmail . '"')
        . ' -r ' . escapeshellarg($code) . ' 2>/dev/null', $out);
    $got = json_decode((string)end($out), true);
    return is_array($got) ? $got : [];
};
$mc = $mail_child('cat >/dev/null');
check('podřízené PHP opravdu posílá do cat', $mc[0] ?? null, 'cat >/dev/null');
check('mail(), který vrátil true: předáno, nepotvrzeno', [$mc[1] ?? null, $mc[2] ?? null, $mc[3] ?? null], [true, 'fallback', 'unknown']);
check_true('a řádek řekne proč a co chybí',
    str_contains((string)($mc[4] ?? ''), 'nothing confirmed delivery')
    && str_contains((string)($mc[4] ?? ''), 'smtp_host, smtp_user, smtp_pass'));
check('odpověď serveru tu žádná není', array_key_exists(5, $mc) ? $mc[5] : 'CHYBÍ', null);
$mc = $mail_child('cat >/dev/null; false');
check('podřízené PHP opravdu posílá do selhávajícího cat', $mc[0] ?? null, 'cat >/dev/null; false');
check('mail(), který vrátil false: neodesláno', [$mc[1] ?? null, $mc[3] ?? null], [false, 'failed']);
check_true('s důvodem', trim((string)($mc[4] ?? '')) !== '');

// =======================================================================
// WhatsApp (CallMeBot) - what counts as sent
//
// Any HTTP 2xx used to be "sent", with the body thrown away. CallMeBot answers
// its refusals with 2xx too (a used-up quota, a paused account, a wrong key),
// so those were logged as sent while nothing arrived.
// =======================================================================
// The stub transport, defined before the sender is loaded: nothing in this
// suite can reach CallMeBot. Every URL is kept, so a test can see whether a
// request was made at all.
// Fails closed: if the real transport is already loaded (a runner that
// required functions.php first), the tests below would send real requests,
// so the suite stops instead of quietly using it. The definition stays inside
// the else: an unconditional one is bound at compile time, before this check.
if (function_exists('bk_callmebot_request')) {
    fwrite(STDERR, "bk_callmebot_request() is already defined, so the WhatsApp tests would reach"
        . " CallMeBot. Stopping.\n");
    exit(1);
} else {
    function bk_callmebot_request(string $url): array {
        $GLOBALS['bk_test_callmebot_urls'][] = $url;
        return $GLOBALS['bk_test_callmebot_answer']
            ?? ['code' => 0, 'body' => '', 'errno' => 7, 'error' => 'stub: no answer set', 'sent' => false];
    }
}
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_send_whatsapp', 'bk_callmebot_verdict', 'bk_callmebot_scrub', 'bk_log_notification',
]);
check_true('CallMeBot se volá jen přes testovací náhradu',
    (new ReflectionFunction('bk_callmebot_request'))->getFileName() === __FILE__);

if (function_exists('bk_callmebot_verdict')) {
    $wa_phone = '+420 777 123 456';
    $wa_key = '1234567';
    // The alert text itself says ERROR: a verdict read from the whole body
    // would call this refusal a success or this success a refusal.
    $wa_text = "🟢 Monitor Router & NAS je opět v pořádku. Čas: 27.09.2026 09:25:07. Důvod: disk ERROR cleared <sda>";
    $wa_echo = fn (string $status): string => '<p>Message to: +420777123456<br>Text to send: '
        . htmlspecialchars($wa_text, ENT_QUOTES) . '<br><br><b>' . $status . '</b></p>';
    $wa_v = fn (int $code, string $body, int $errno = 0, string $error = '', bool $sent = true): array
        => bk_callmebot_verdict($code, $body, $errno, $error, $sent, $wa_phone, $wa_key, $wa_text);
    $wa_all = [];

    $v = $wa_all[] = $wa_v(200, $wa_echo('Message queued. You will receive it in a few seconds. You have 13 Messages left'));
    check('200 s „Message queued" je odesláno', $v['delivery'], 'sent');
    check('a nemá důvod k selhání', $v['reason'], null);
    check_true('odpověď poskytovatele zůstane, i se zbývajícím počtem',
        str_contains((string)$v['reply'], 'Message queued') && str_contains((string)$v['reply'], '13 Messages left'));
    check_false('text zprávy (s ERROR) o verdiktu nerozhodl', str_contains((string)$v['reply'], 'ERROR'));

    $v = $wa_all[] = $wa_v(200, $wa_echo('APIKey is invalid. Please check the key.'));
    check('200 se špatným klíčem je neodesláno', $v['delivery'], 'failed');
    check_true('a důvod říká odmítnuto, ne „nerozpoznáno"',
        str_contains((string)$v['reason'], 'refused') && !str_contains((string)$v['reason'], 'unrecognised'));
    $v = $wa_all[] = $wa_v(200, $wa_echo('Your Account is Paused due to technical issues. Please send the word "resume" to the bot.'));
    check('200 s pozastaveným účtem je neodesláno', $v['delivery'], 'failed');
    check_true('a důvod říká proč', str_contains((string)$v['reason'], 'Paused'));
    $v = $wa_all[] = $wa_v(200, $wa_echo('Message queued. ERROR: something went wrong'));
    check('„queued" s chybou v téže odpovědi není potvrzení', $v['delivery'], 'failed');
    $v = $wa_all[] = $wa_v(200, '');
    check('200 s prázdným tělem je neodesláno', [$v['delivery'], str_contains((string)$v['reason'], 'empty reply')], ['failed', true]);
    $v = $wa_all[] = $wa_v(200, $wa_echo('Thank you for using CallMeBot'));
    check('200 bez značky přijetí je neodesláno', [$v['delivery'], str_contains((string)$v['reason'], 'unrecognised reply')], ['failed', true]);
    $v = $wa_all[] = $wa_v(200, 'Message queued');
    check('odpověď bez ozvěny zprávy se čte celá', $v['delivery'], 'sent');

    // The community table (ioBroker whatsapp-cmb): every one of these is a 2xx
    // that production counted as sent.
    foreach ([201 => 'Wrong parameters', 202 => 'Number banned', 203 => 'API key incorrect',
              204 => 'Too many messages', 205 => 'Unknown error', 207 => 'Service down',
              208 => 'Account paused', 209 => 'Quota exceeded'] as $wa_code => $wa_why) {
        $v = $wa_all[] = $wa_v($wa_code, $wa_code === 204 ? '' : $wa_echo('Your Account is...'));
        check("CallMeBot {$wa_code} je neodesláno", $v['delivery'], 'failed');
        check_true("a {$wa_code} řekne proč", str_contains((string)$v['reason'], $wa_why));
    }
    $v = $wa_all[] = $wa_v(206, $wa_echo('?'));
    check('kód mimo tabulku je neodesláno', [$v['delivery'], str_contains((string)$v['reason'], 'unrecognised')], ['failed', true]);
    $v = $wa_all[] = $wa_v(210, $wa_echo('Message queued to be sent later'));
    check('210 (odloženo) je nepotvrzené, ne odeslané', $v['delivery'], 'unknown');
    $v = $wa_all[] = $wa_v(200, $wa_echo('Message queued to be sent later'));
    check('táž slova se 200 jsou taky nepotvrzená', $v['delivery'], 'unknown');
    $v = $wa_all[] = $wa_v(503, '<html><head><title>503 Service Unavailable</title></head><body><h1>Service Unavailable</h1><p>nginx</p></body></html>');
    check('503 je neodesláno', $v['delivery'], 'failed');
    check_true('důvod nese kód i slova serveru bez HTML',
        str_contains((string)$v['reason'], '503') && str_contains((string)$v['reply'], 'Service Unavailable')
        && !str_contains((string)$v['reply'], '<'));
    $v = $wa_all[] = $wa_v(301, '<a href="https://www.callmebot.com/">Moved</a>');
    check('přesměrování není doručení', $v['delivery'], 'failed');

    // Transport errors: before the request left, CallMeBot never saw it; after,
    // nobody can say.
    $v = $wa_all[] = $wa_v(0, '', 6, 'Could not resolve host: api.callmebot.com', false);
    check('nedosažitelný CallMeBot je neodesláno', [$v['delivery'], str_contains((string)$v['reason'], 'Could not resolve host')], ['failed', true]);
    $v = $wa_all[] = $wa_v(0, '', 28, 'Operation timed out after 5001 milliseconds with 0 bytes received', true);
    check('vypršení po odeslání požadavku je nepotvrzené', $v['delivery'], 'unknown');

    // An echo that differs from what was sent cannot be told apart from the
    // tail, so nothing in it may decide - and nothing of it may be stored.
    $v = $wa_all[] = $wa_v(200, '<p>Message to: +420777123456<br>Text to send: Monitor Router je opet v poradku ERROR<br>Message queued</p>');
    check('neoddělitelná ozvěna je nepotvrzená, ne hádaná', [$v['delivery'], $v['reply']], ['unknown', null]);

    // The status sentence printed before the echo, nothing after it: not the
    // known shape, so never sent - but not a bare "empty reply" either, and
    // CallMeBot's words are kept for whoever has to read the row.
    $v = $wa_all[] = $wa_v(200, '<p>Message queued. You will receive it in a few seconds.</p>' . $wa_echo(''));
    check('„queued" před ozvěnou je nepotvrzené, ne odeslané ani neodeslané', $v['delivery'], 'unknown');
    check_true('a odpověď i důvod nesou slova CallMeBotu',
        str_contains((string)$v['reply'], 'Message queued') && str_contains((string)$v['reason'], 'before the echoed message'));
    $v = $wa_all[] = $wa_v(200, '<p>Your Account is Paused.</p>' . $wa_echo(''));
    check('odmítnutí před ozvěnou je neodesláno', $v['delivery'], 'failed');
    check_true('s textem před ozvěnou místo „empty reply"',
        str_contains((string)$v['reason'], 'no text after the echoed message') && str_contains((string)$v['reply'], 'Paused'));
    $v = $wa_all[] = $wa_v(200, $wa_echo(''));
    check('holá ozvěna bez stavu je prázdná odpověď',
        [$v['delivery'], str_contains((string)$v['reason'], 'empty reply'), $v['reply']], ['failed', true, null]);

    // Nothing stored may carry the phone, the key or the message itself.
    $wa_leaks = [];
    $wa_slice = mb_substr($wa_text, 20, 12, 'UTF-8');
    foreach ($wa_all as $i => $v) {
        foreach ([(string)$v['reason'], (string)$v['reply']] as $wa_stored) {
            foreach (['777123456', '777 123 456', $wa_key, $wa_slice, 'Router & NAS', '<sda>'] as $wa_secret) {
                if (str_contains($wa_stored, $wa_secret)) {
                    $wa_leaks[] = "#{$i} obsahuje {$wa_secret}";
                }
            }
        }
    }
    check('odpověď ani důvod nenesou telefon, klíč ani text zprávy', $wa_leaks, []);
    $v = $wa_v(200, 'Message queued. ID 123456789012, phone +420 777 999 888');
    check_true('dlouhé řady číslic se neukládají',
        !preg_match('/\d{7}/', (string)$v['reply']) && !str_contains((string)$v['reply'], '777 999 888'));
}

if (function_exists('bk_send_whatsapp')) {
    $GLOBALS['bk_test_callmebot_urls'] = [];
    $wa_r = bk_send_whatsapp('+420 777 123 456', 'Ahoj', '');
    check('bez klíče je neodesláno', $wa_r['delivery'], 'failed');
    $wa_r = bk_send_whatsapp('', 'Ahoj', '1234567');
    check('bez telefonu je neodesláno', $wa_r['delivery'], 'failed');
    check('a v obou případech nikam nic neodešlo', count($GLOBALS['bk_test_callmebot_urls']), 0);

    $GLOBALS['bk_test_callmebot_answer'] = ['code' => 200, 'body' => 'Message queued', 'errno' => 0, 'error' => '', 'sent' => true];
    $wa_r = bk_send_whatsapp('777 123 456', 'Ahoj & čau', '1234567');
    check('potvrzené přijetí je odesláno', $wa_r['delivery'], 'sent');
    $wa_url = $GLOBALS['bk_test_callmebot_urls'][0] ?? '';
    check_true('devítimístné číslo dostane +420 a jde na CallMeBot přes https',
        str_starts_with($wa_url, 'https://api.callmebot.com/whatsapp.php?phone=420777123456&'));
    check_true('text a klíč jsou v adrese zakódované',
        str_contains($wa_url, 'text=' . urlencode('Ahoj & čau')) && str_contains($wa_url, 'apikey=1234567'));
    $GLOBALS['bk_test_callmebot_answer'] = null;
}

// bk_log_notification() against a real (SQLite) table: the new columns, the
// whitelist and the length cap.
if (function_exists('bk_log_notification') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $ln_pdo = new PDO('sqlite::memory:');
    $ln_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ln_pdo->exec("CREATE TABLE notification_log (id INTEGER PRIMARY KEY, monitor_id INTEGER, status TEXT,
        channel TEXT, recipient TEXT, ok INTEGER, error_message TEXT, kind TEXT, subject TEXT, method TEXT,
        delivery TEXT, provider_reply TEXT)");
    bk_log_notification($ln_pdo, 3, 'down', 'whatsapp', '+420777123456', true, null, 'alert',
        delivery: 'sent', reply: str_repeat('Message queued ', 20));
    bk_log_notification($ln_pdo, 3, 'down', 'whatsapp', '+420777123456', true, null, 'alert', delivery: 'delivered');
    bk_log_notification($ln_pdo, 3, 'down', 'discord', null, false, 'HTTP 404', 'alert');
    $ln_rows = $ln_pdo->query("SELECT delivery, provider_reply, ok FROM notification_log ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('protokol uloží, co poskytovatel potvrdil', $ln_rows[0]['delivery'] ?? null, 'sent');
    check('odpověď oříznutá na 190 znaků', mb_strlen((string)($ln_rows[0]['provider_reply'] ?? ''), 'UTF-8'), 190);
    check_true('hodnota mimo čtyři povolené se uloží jako NULL, ne jako potvrzení',
        array_key_exists('delivery', $ln_rows[1] ?? []) && $ln_rows[1]['delivery'] === null);
    check('volání bez výsledku doručení nechá NULL', [$ln_rows[2]['delivery'], $ln_rows[2]['provider_reply']], [null, null]);
} else {
    check_true('SQLite ovladač pro test protokolu je k dispozici', false);
}

// =======================================================================
// Which channel stopped reaching whom - the /app warning
// =======================================================================
bk_test_load_functions(__DIR__ . '/../functions.php', ['bk_notification_problems', 'bk_mask_recipient']);
if (function_exists('bk_notification_problems')) {
    $np_row = fn (string $ch, ?string $to, string $d, string $at, ?string $reply = null, ?string $reason = null, bool $rec = true): array
        => ['channel' => $ch, 'recipient' => $to, 'delivery' => $d, 'recorded' => $rec, 'reply' => $reply, 'reason' => $reason, 'at' => $at];
    // The owner's week: WhatsApp confirmed until the quota hit zero, then
    // refused; the e-mail went through mail() and nobody confirmed any of it.
    $np = bk_notification_problems([
        $np_row('whatsapp', '+420777123456', 'sent', '2026-09-25 08:00:00', 'Message queued. You have 1 Messages left'),
        $np_row('email', 'owner@example.com', 'unknown', '2026-09-25 08:00:01', null, "Handed to the hosting's mail()"),
        $np_row('whatsapp', '+420777123456', 'sent', '2026-09-26 08:00:00', 'Message queued. You have 0 Messages left'),
        $np_row('whatsapp', '+420777123456', 'failed', '2026-09-27 06:15:07', null, 'CallMeBot 209: Quota exceeded or banned'),
        $np_row('email', 'owner@example.com', 'unknown', '2026-09-27 09:25:07', null, "Handed to the hosting's mail()"),
        $np_row('whatsapp', '+420777123456', 'failed', '2026-09-27 09:25:07', null, 'CallMeBot 209: Quota exceeded or banned'),
        $np_row('discord', null, 'failed', '2026-09-26 10:00:00', null, 'HTTP 404'),
        $np_row('discord', null, 'sent', '2026-09-27 10:00:00'),
    ]);
    $np_by = array_column($np, null, 'channel');
    check('WhatsApp, který odmítá: neodesláno', $np_by['whatsapp']['state'] ?? null, 'failed');
    check('od prvního odmítnutí po posledním potvrzení', [$np_by['whatsapp']['since'] ?? null, $np_by['whatsapp']['count'] ?? null],
        ['2026-09-27 06:15:07', 2]);
    check('s posledním důvodem a posledním potvrzeným odesláním',
        [$np_by['whatsapp']['lastReason'] ?? null, $np_by['whatsapp']['lastSentAt'] ?? null],
        ['CallMeBot 209: Quota exceeded or banned', '2026-09-26 08:00:00']);
    check('e-mail přes mail(): nepotvrzeno, celý týden', [$np_by['email']['state'] ?? null, $np_by['email']['count'] ?? null],
        ['unknown', 2]);
    check_true('a nic z toho nikdo nepotvrdil',
        array_key_exists('lastSentAt', $np_by['email'] ?? []) && $np_by['email']['lastSentAt'] === null);
    check('kanál, který po selhání zase doručil, problém nemá', isset($np_by['discord']), false);
    check('nejčerstvější problém je první', $np[0]['lastAt'] ?? null, '2026-09-27 09:25:07');

    $np = bk_notification_problems([
        $np_row('whatsapp', '+420777123456', 'sent', '2026-09-27 08:00:00', 'Message queued. You have 0 Messages left'),
    ]);
    check('přijato s „0 messages left" je varování: další zpráva už nepůjde',
        [$np[0]['state'] ?? null, str_starts_with((string)($np[0]['lastReason'] ?? ''), 'CallMeBot quota at 0')], ['unknown', true]);
    check('10 zbývajících zpráv problém není', bk_notification_problems([
        $np_row('whatsapp', '+420777123456', 'sent', '2026-09-27 08:00:00', 'Message queued. You have 10 Messages left'),
    ]), []);
    $np = bk_notification_problems([$np_row('whatsapp', '+420777123456', 'unknown', '2026-09-27 08:00:00', null, null, false)]);
    check('odvozený (starší) řádek se tak označí', $np[0]['legacy'] ?? null, true);
    check('dva příjemci na jednom kanálu jsou dva problémy', count(bk_notification_problems([
        $np_row('email', 'a@example.com', 'failed', '2026-09-27 08:00:00'),
        $np_row('email', 'b@example.com', 'failed', '2026-09-27 08:00:00'),
        $np_row('email', 'c@example.com', 'sent', '2026-09-27 08:00:00'),
    ])), 2);

    $np_users = [
        ['username' => 'spravce', 'email' => 'Owner@Example.com', 'phone' => '777 123 456'],
        ['username' => 'druhy', 'email' => 'druhy@example.com', 'phone' => null],
    ];
    check('telefon: jen poslední tři číslice a jméno účtu podle posledních devíti',
        bk_mask_recipient('+420777123456', $np_users), ['masked' => '•••456', 'username' => 'spravce']);
    check('e-mail: první znak a doména, účet podle adresy bez ohledu na velikost',
        bk_mask_recipient('owner@example.com', $np_users), ['masked' => 'o…@example.com', 'username' => 'spravce']);
    check('neznámý příjemce zůstane bez jména', bk_mask_recipient('-100123456789', $np_users),
        ['masked' => '•••789', 'username' => null]);
    check('webhook bez příjemce', bk_mask_recipient(null, $np_users), ['masked' => null, 'username' => null]);
    check('akce PagerDuty není číslo, zůstane čitelná', bk_mask_recipient('trigger', $np_users),
        ['masked' => 'trigger', 'username' => null]);
    check('kanál Telegramu taky', bk_mask_recipient('@provoz', $np_users), ['masked' => '@provoz', 'username' => null]);
}

// =======================================================================
// Daily reminder - the whole path from the database to the message
//
// The rule that counts ("what is broken") is tested in run_tests.php without
// a database. Here the parts are put together: does anything actually leave,
// how often, and what stays behind in the outgoing message log when nothing
// does. The database is an in-memory SQLite - the queries are plain enough to
// run on both, and the alternative would be no coverage of this path at all
// until someone ran the integration suite with MySQL.
// =======================================================================
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_send_daily_reminder', 'bk_daily_reminder_collect', 'bk_daily_reminder_select',
    'bk_daily_reminder_recipients', 'bk_daily_reminder_due', 'bk_daily_reminder_text',
    'bk_render_daily_reminder', 'render_email_wrapper', 'bk_log_notification', 'bk_send_shared_channels',
    'bk_heartbeat_evaluate', 'bk_alert_color_class', 'bk_format_duration_secs',
]);
bk_test_load_functions(__DIR__ . '/../db.php', ['get_setting', 'bk_settings_defaults']);

// The mail stub: nothing leaves the machine, and every attempt is kept so the
// tests can ask what the reminder said. send_email() writes the log row
// itself in production - here that row is not the subject of the test.
// Fails closed like the CallMeBot stub: with the real one loaded, the
// reminder and admin-notice tests would send real mail.
if (function_exists('send_email')) {
    fwrite(STDERR, "send_email() is already defined, so the reminder and admin-notice tests would send real mail."
        . " Stopping.\n");
    exit(1);
} else {
    function send_email($to, $subject, $html_body, array $extra_headers = [], array $context = []) {
        $GLOBALS['bk_test_mails'][] = ['to' => $to, 'subject' => $subject, 'body' => $html_body, 'context' => $context];
        return true;
    }
}
check_true('e-mail se posílá jen přes testovací náhradu', (new ReflectionFunction('send_email'))->getFileName() === __FILE__);

$dr_pdo = null;
if (function_exists('bk_send_daily_reminder') && in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $dr_pdo = new PDO('sqlite::memory:');
    $dr_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dr_pdo->exec("CREATE TABLE monitors (id INTEGER PRIMARY KEY, name TEXT, type TEXT, status TEXT,
        last_checked TEXT, last_status_change TEXT, last_details TEXT, maintenance INTEGER DEFAULT 0,
        archived_at TEXT, email_notifications INTEGER DEFAULT 1, heartbeat_interval INTEGER,
        heartbeat_grace INTEGER, last_heartbeat TEXT, heartbeat_last_result TEXT, heartbeat_last_message TEXT)");
    $dr_pdo->exec("CREATE TABLE monitor_logs (id INTEGER PRIMARY KEY, monitor_id INTEGER, error_message TEXT)");
    $dr_pdo->exec("CREATE TABLE incidents (id INTEGER PRIMARY KEY, title TEXT, impact TEXT, status TEXT,
        created_at TEXT, acknowledged_at TEXT, monitor_id INTEGER)");
    // Every column bk_log_notification() writes: it swallows a failed INSERT,
    // so a missing one would leave these tests with no rows to read.
    $dr_pdo->exec("CREATE TABLE notification_log (id INTEGER PRIMARY KEY, monitor_id INTEGER, status TEXT,
        channel TEXT, recipient TEXT, ok INTEGER, error_message TEXT, kind TEXT, subject TEXT, method TEXT,
        delivery TEXT, provider_reply TEXT)");
    $dr_pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, email_lang TEXT, role TEXT,
        phone TEXT, whatsapp_apikey TEXT, whatsapp_notifications INTEGER DEFAULT 0)");
    $dr_pdo->exec("CREATE TABLE user_subscriptions (user_id INTEGER, monitor_id INTEGER, email_notifications INTEGER)");
    $dr_pdo->exec("CREATE TABLE monitor_users (user_id INTEGER, monitor_id INTEGER)");
    $dr_pdo->exec("INSERT INTO users (id, email, email_lang, role) VALUES (1, 'admin@example.com', 'cs', 'admin')");
}

if ($dr_pdo instanceof PDO) {
    // The real clock, for the same reason as in run_tests.php:
    // bk_get_collection_issues() reads time() itself, so a frozen "now" would
    // make these fixtures age against it during the day.
    $dr_now = time();
    $dr_at = fn (int $secs_ago): string => date('Y-m-d H:i:s', $dr_now - $secs_ago);
    // No webhook is configured, so nothing can reach the network from here;
    // the reminder has only the e-mail channel to use.
    $GLOBALS['system_settings'] = [
        'agent_offline_timeout' => '50',
        'last_cron_run' => $dr_at(120),
        'email_lang' => 'cs',
        'site_title' => 'Blood Kings Status',
    ];
    $dr_reset = function () use ($dr_pdo): void {
        $dr_pdo->exec('DELETE FROM monitors');
        $dr_pdo->exec('DELETE FROM monitor_logs');
        $dr_pdo->exec('DELETE FROM incidents');
        $dr_pdo->exec('DELETE FROM notification_log');
        $GLOBALS['bk_test_mails'] = [];
    };
    $dr_add = function (array $row) use ($dr_pdo): void {
        $row = array_merge([
            'id' => 1, 'name' => 'Web', 'type' => 'web', 'status' => 'up', 'last_checked' => null,
            'last_status_change' => null, 'last_details' => null, 'maintenance' => 0, 'archived_at' => null,
            'heartbeat_interval' => null, 'heartbeat_grace' => null, 'last_heartbeat' => null,
            'heartbeat_last_result' => null, 'heartbeat_last_message' => null,
        ], $row);
        $stmt = $dr_pdo->prepare("INSERT INTO monitors (id, name, type, status, last_checked, last_status_change,
            last_details, maintenance, archived_at, heartbeat_interval, heartbeat_grace, last_heartbeat,
            heartbeat_last_result, heartbeat_last_message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute(array_values($row));
    };

    // --- 1. A healthy system says nothing ---------------------------------
    // A daily "all good" would teach the reader to filter the sender, and the
    // first real reminder would be filtered with it.
    $dr_reset();
    $dr_add(['id' => 1, 'name' => 'Web', 'status' => 'up', 'last_checked' => $dr_at(60), 'last_status_change' => $dr_at(86400)]);
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    check('zdravý systém: nic se neposílá', [$dr_res['sent'], $dr_res['problems']], [false, 0]);
    check('a žádný e-mail neodešel', count($GLOBALS['bk_test_mails']), 0);

    // ... but the decision is not silent: without a row nobody could tell
    // "there was nothing to report" from "the reminder is broken".
    $dr_rows = $dr_pdo->query("SELECT kind, channel, status, ok, recipient, subject FROM notification_log")->fetchAll(PDO::FETCH_ASSOC);
    check('rozhodnutí neposílat je v protokolu', count($dr_rows), 1);
    check('jako přeskočená denní připomínka',
        [$dr_rows[0]['kind'] ?? null, $dr_rows[0]['status'] ?? null, $dr_rows[0]['channel'] ?? null],
        ['daily_reminder', 'skipped', 'none']);
    // ok = 1: nothing failed. A zero would light up the "something did not go
    // out" banner every healthy day, and then nobody reads it on the day it matters.
    check('a není to neúspěch', (int)($dr_rows[0]['ok'] ?? -1), 1);
    check('bez příjemce (nikomu se neposílalo)', $dr_rows[0]['recipient'], null);
    check_true('důvod je u toho napsaný', trim((string)($dr_rows[0]['subject'] ?? '')) !== '');

    // --- 2. A broken system sends, and says what is wrong -----------------
    $dr_reset();
    $dr_add(['id' => 1, 'name' => 'Web', 'status' => 'down', 'last_checked' => $dr_at(60),
        'last_status_change' => $dr_at(4 * 86400)]);
    $dr_add(['id' => 2, 'name' => 'Router', 'type' => 'vps', 'status' => 'up', 'last_checked' => $dr_at(60),
        'last_status_change' => $dr_at(86400),
        'last_details' => json_encode(['agent_last_seen' => $dr_now - 7200])]);
    $dr_pdo->exec("INSERT INTO monitor_logs (monitor_id, error_message) VALUES (1, 'HTTP 502 Bad Gateway')");
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    check('rozbitý systém posílá', [$dr_res['sent'], $dr_res['emails'], $dr_res['problems']], [true, 1, 2]);
    $dr_mail = $GLOBALS['bk_test_mails'][0] ?? ['to' => null, 'subject' => '', 'body' => '', 'context' => []];
    check('administrátorovi', $dr_mail['to'], 'admin@example.com');
    check('jako denní připomínka', $dr_mail['context']['kind'] ?? null, 'daily_reminder');
    check_true('předmět říká, co to je', str_contains((string)$dr_mail['subject'], 'Denní připomínka'));
    check_true('zpráva jmenuje rozbitý monitor a uložený důvod',
        str_contains($dr_mail['body'], 'Web') && str_contains($dr_mail['body'], 'HTTP 502 Bad Gateway'));
    check_true('a říká, jak dlouho to trvá', str_contains($dr_mail['body'], 'trvá 4 d'));
    // The two sections stay apart: the silent agent is the fault nothing else
    // makes visible, and among the outages it would be buried again.
    check_true('tichý sběr má vlastní sekci', str_contains($dr_mail['body'], 'Tichý sběr dat')
        && str_contains($dr_mail['body'], 'Výpadky a varování'));
    check_true('a mlčící agent je v ní jmenovaný', str_contains($dr_mail['body'], 'Router'));
    // The collection stamp closes the message so a dead collector cannot hide
    // behind a report that happens to be short.
    check_true('zpráva končí otiskem posledního běhu sběru',
        str_contains($dr_mail['body'], 'Poslední dokončený běh sběru'));

    // The e2e case behind the fix: "Záloha NAS" was in the silent section
    // twice - once from the heartbeat rule, once from the stalled checks -
    // and the summary counted one monitor as two problems.
    $dr_reset();
    $dr_add(['id' => 3, 'name' => 'Záloha NAS', 'type' => 'heartbeat', 'status' => 'down',
        'last_checked' => $dr_at(300 * 60), 'last_status_change' => $dr_at(18000),
        'heartbeat_interval' => 3600, 'heartbeat_grace' => 300, 'last_heartbeat' => $dr_at(18000)]);
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    check('monitor nalezený dvěma pravidly je jeden problém', $dr_res['problems'], 1);
    $dr_dup_body = $GLOBALS['bk_test_mails'][0]['body'] ?? '';
    check('a ve zprávě je jmenovaný jednou', substr_count($dr_dup_body, 'Záloha NAS'), 1);
    check_true('druhý nález je pod ním, ne v dalším řádku sekce',
        str_contains($dr_dup_body, 'Navíc:') && str_contains($dr_dup_body, 'Kontroly dostupnosti'));

    // --- 3. A cron every minute must not send twice -----------------------
    // This is what the whole guard is for: without the stamp the reminder
    // would arrive nine hundred times a day and be filtered by the evening.
    $dr_reset();
    $dr_add(['id' => 1, 'name' => 'Web', 'status' => 'down', 'last_checked' => $dr_at(60),
        'last_status_change' => $dr_at(2 * 86400)]);
    $dr_stamp = '';
    $dr_runs = 0;
    $dr_today = [(int)date('n'), (int)date('j'), (int)date('Y')];
    foreach ([7, 8, 9, 13, 23] as $dr_hour) {
        $dr_ts = mktime($dr_hour, 0, 0, $dr_today[0], $dr_today[1], $dr_today[2]);
        // Exactly what cron.php does: ask the guard, send, write the date.
        if (bk_daily_reminder_due($dr_stamp, 8, $dr_ts)) {
            bk_send_daily_reminder($dr_pdo, $dr_ts);
            $dr_stamp = date('Y-m-d', $dr_ts);
            $dr_runs++;
        }
    }
    check('pět běhů cronu za den = jedno odeslání', $dr_runs, 1);
    check('a právě jeden e-mail', count($GLOBALS['bk_test_mails']), 1);
    check('první odešlo v nastavenou hodinu, ne dřív', $dr_stamp, date('Y-m-d'));

    // The next day it speaks up again - the outage is still running.
    $dr_tomorrow = mktime(8, 0, 0, $dr_today[0], $dr_today[1] + 1, $dr_today[2]);
    if (bk_daily_reminder_due($dr_stamp, 8, $dr_tomorrow)) {
        bk_send_daily_reminder($dr_pdo, $dr_tomorrow);
    }
    check('druhý den se ozve znovu', count($GLOBALS['bk_test_mails']), 2);

    // --- 4. Exclusions on the real path -----------------------------------
    // The same rules as in the pure test, but this time read out of the
    // database: a query that forgot the condition would pass there and fail here.
    $dr_reset();
    $dr_add(['id' => 1, 'name' => 'Údržba', 'status' => 'down', 'maintenance' => 1, 'last_checked' => $dr_at(60)]);
    $dr_add(['id' => 2, 'name' => 'Archiv', 'status' => 'down', 'archived_at' => $dr_at(86400), 'last_checked' => $dr_at(60)]);
    $dr_pdo->exec("INSERT INTO incidents (id, title, impact, status, created_at, acknowledged_at, monitor_id)
                   VALUES (1, 'Převzatý', 'major', 'identified', '2026-09-20 08:00:00', '2026-09-20 08:05:00', NULL)");
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    check('údržba, archiv ani převzatý incident zprávu nevyvolají',
        [$dr_res['problems'], count($GLOBALS['bk_test_mails'])], [0, 0]);

    // An unacknowledged incident does - nobody has taken it over.
    $dr_pdo->exec("INSERT INTO incidents (id, title, impact, status, created_at, acknowledged_at, monitor_id)
                   VALUES (2, 'Nikdo nepřevzal', 'major', 'investigating', '2026-09-20 08:00:00', NULL, NULL)");
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    check('nepřevzatý incident zprávu vyvolá', $dr_res['problems'], 1);
    check_true('a je v ní jmenovaný',
        str_contains($GLOBALS['bk_test_mails'][0]['body'] ?? '', 'Nikdo nepřevzal'));

    // --- 5. WhatsApp: a refusal is a refusal ------------------------------
    // The reminder's WhatsApp went through the same 2xx-is-sent check as the
    // alerts, so a paused CallMeBot account looked like a delivered reminder.
    $dr_reset();
    $dr_add(['id' => 1, 'name' => 'Web', 'status' => 'down', 'last_checked' => $dr_at(60),
        'last_status_change' => $dr_at(2 * 86400)]);
    $dr_pdo->exec("UPDATE users SET phone = '777123456', whatsapp_apikey = '1234567', whatsapp_notifications = 1 WHERE id = 1");
    $GLOBALS['bk_test_callmebot_answer'] = ['code' => 208, 'body' => 'Message to: +420777123456 Text to send: x Your Account is Paused',
        'errno' => 0, 'error' => '', 'sent' => true];
    $dr_wa_row = function () use ($dr_pdo): array {
        $row = $dr_pdo->query("SELECT ok, delivery, error_message, provider_reply, recipient FROM notification_log
                               WHERE channel = 'whatsapp' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    };
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    $dr_wa = $dr_wa_row();
    check('pozastavený CallMeBot: řádek říká neodesláno', [(int)($dr_wa['ok'] ?? -1), $dr_wa['delivery'] ?? null], [0, 'failed']);
    check_true('s důvodem od CallMeBotu', str_contains((string)($dr_wa['error_message'] ?? ''), 'Account paused'));
    check('a WhatsApp se nepočítá mezi kanály, které prošly', $dr_res['channels'], 0);

    $GLOBALS['bk_test_callmebot_answer'] = ['code' => 200, 'body' => 'Message queued. You have 3 Messages left',
        'errno' => 0, 'error' => '', 'sent' => true];
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    $dr_wa = $dr_wa_row();
    check('potvrzené přijetí: odesláno', [(int)($dr_wa['ok'] ?? -1), $dr_wa['delivery'] ?? null], [1, 'sent']);
    check('i se slovy CallMeBotu', $dr_wa['provider_reply'] ?? null, 'Message queued. You have 3 Messages left');
    check('a počítá se', $dr_res['channels'], 1);

    // Switched on, but no key: no row at all used to be written, so the
    // channel that never worked was also the one nothing said anything about.
    $dr_pdo->exec("UPDATE users SET whatsapp_apikey = NULL WHERE id = 1");
    $GLOBALS['bk_test_callmebot_urls'] = [];
    $dr_res = bk_send_daily_reminder($dr_pdo, $dr_now);
    $dr_wa = $dr_wa_row();
    check('zapnutý WhatsApp bez klíče: řádek říká neodesláno a proč',
        [(int)($dr_wa['ok'] ?? -1), $dr_wa['delivery'] ?? null, str_contains((string)($dr_wa['error_message'] ?? ''), 'No CallMeBot key')],
        [0, 'failed', true]);
    check('a nikam nic neodešlo', count($GLOBALS['bk_test_callmebot_urls']), 0);
    $dr_pdo->exec("UPDATE users SET phone = NULL, whatsapp_apikey = NULL, whatsapp_notifications = 0 WHERE id = 1");
    $GLOBALS['bk_test_callmebot_answer'] = null;
} elseif (function_exists('bk_send_daily_reminder')) {
    // Reported, never skipped in silence: a suite that quietly tests nothing
    // is the same lie as a chart with invented values.
    check_true('SQLite ovladač pro testy denní připomínky je k dispozici', false);
}

// =======================================================================
// Self-check of the site's own public API (cron)
//
// On 27 Sep 2026 every PHP answer began with "Ah" for an hour, /app could not
// read any of them, and the cron - watching everything else - did not notice
// its own site. What counts as a working API, when to tell the administrators
// and how the telling is logged. bk_self_check_run() itself needs MySQL and
// is covered in run_api_tests.php.
// =======================================================================
// The webhook stub, the same rule as the CallMeBot one: fail closed.
if (function_exists('send_webhook_post')) {
    fwrite(STDERR, "send_webhook_post() is already defined, so the admin-notice tests would reach real webhooks."
        . " Stopping.\n");
    exit(1);
} else {
    function send_webhook_post($url, $payload_json): bool {
        $GLOBALS['bk_test_webhooks'][] = ['url' => (string)$url, 'payload' => (string)$payload_json];
        $ok = !str_contains((string)$url, 'telegram');
        $GLOBALS['last_webhook_error'] = $ok ? null : 'HTTP 400';
        return $ok;
    }
}
bk_test_load_functions(__DIR__ . '/../db.php', ['bk_config_output_excerpt']);
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_self_check_url', 'bk_site_origin', 'bk_self_check_quote', 'bk_self_check_verdict', 'bk_self_check_step',
    'bk_self_check_message',
    'bk_send_admin_notice', 'bk_send_shared_channels', 'send_pushover_alert',
]);

if (function_exists('bk_self_check_verdict')) {
    check('adresa kontroly: původ site_url + /status/api.php, cesta se ignoruje',
        [bk_self_check_url('https://Example.com/status/'), bk_self_check_url(''), bk_self_check_url('ftp://x')],
        ['https://example.com/status/api.php?action=public_status', null, null]);

    $sc_good = json_encode(['status' => 'healthy', 'uptimePercent' => 99.9, 'totalMonitors' => 3, 'downMonitors' => 0,
        'lastUpdated' => null, 'nodes' => []]);
    check('platný JSON se všemi klíči prochází', bk_self_check_verdict(200, (string)$sc_good, 0, ''), ['ok' => true, 'reason' => null]);
    check('„Ah" před JSONem: kolik bajtů a jaké',
        bk_self_check_verdict(200, 'Ah' . $sc_good, 0, ''), ['ok' => false, 'reason' => '2 bytes before the JSON: "Ah"']);
    check('500 s chybou API ji ocituje',
        bk_self_check_verdict(500, '{"error":"Nepodařilo se zjistit stav infrastruktury."}', 0, '')['reason'],
        'HTTP 500: Nepodařilo se zjistit stav infrastruktury.');
    $sc_v = bk_self_check_verdict(503, '<html><head><title>503 Service Unavailable</title></head><body>nginx secret-host-123456</body></html>', 0, '');
    check_true('stránka chyby: kód a nanejvýš 16 bajtů textu', $sc_v['ok'] === false
        && str_starts_with((string)$sc_v['reason'], 'HTTP 503: 503 Service Unav…') && !str_contains((string)$sc_v['reason'], 'secret-host'));
    check('nedosažitelné', bk_self_check_verdict(0, '', 6, 'Could not resolve host: example.com')['reason'],
        'Not reached: Could not resolve host: example.com');
    check('prázdná odpověď', bk_self_check_verdict(200, '', 0, '')['reason'], 'Empty answer');
    check('stránka místo JSONu (výzva proxy): její titulek', bk_self_check_verdict(200,
        "<!DOCTYPE html><html><head><title>Just a moment...</title></head><body><script>x()</script></body></html>", 0, '')['reason'],
        'Not JSON but an HTML page: "Just a •••"');
    check('stránka bez titulku: text bez značek', bk_self_check_verdict(200, "<html><body><p>Ahoj</p>\n<p>svete</p></body></html>", 0, '')['reason'],
        'Not JSON but an HTML page: "Ahoj svete"');
    check('text, který není JSON, maskovaný jako výstup config.php',
        bk_self_check_verdict(200, 'Tr0ub4dor&3! {', 0, '')['reason'], 'Not JSON, starts with "••• {"');
    check('JSON bez klíčů, které čte /app',
        bk_self_check_verdict(200, '{"status":"healthy","nodes":[]}', 0, '')['reason'],
        'JSON without totalMonitors, downMonitors, uptimePercent, lastUpdated');
    check('neznámý stav je nečekaný tvar', bk_self_check_verdict(200, str_replace('healthy', 'fine', (string)$sc_good), 0, '')['ok'], false);

    // The latch. One failure is a blip; the second in a row is announced,
    // once; a notice nobody took is retried after an hour, not every minute.
    $sc_t = 1790000000;
    $sc_bad = ['ok' => false, 'reason' => '2 bytes before the JSON: "Ah"'];
    $sc_ok = ['ok' => true, 'reason' => null];
    $sc1 = bk_self_check_step([], $sc_bad, $sc_t);
    check('první selhání: zatím nikomu', [$sc1['notify'], $sc1['state']['failures']], [null, 1]);
    $sc2 = bk_self_check_step($sc1['state'], $sc_bad, $sc_t + 60);
    check('druhé za sebou: upozornit', [$sc2['notify'], $sc2['state']['failures'], $sc2['state']['since']],
        ['failed', 2, date('c', $sc_t)]);
    $sc_told = $sc2['state'] + [];
    $sc_told['alerted'] = true;
    $sc_told['alertAttemptAt'] = date('c', $sc_t + 60);
    $sc_told['alertResult'] = 'sent';
    $sc3 = bk_self_check_step($sc_told, $sc_bad, $sc_t + 120);
    check('ohlášené selhání se neopakuje', [$sc3['notify'], $sc3['state']['failures'], $sc3['state']['alertResult']], [null, 3, 'sent']);
    $sc_untold = $sc_told;
    $sc_untold['alerted'] = false;
    $sc_untold['alertResult'] = 'failed';
    check('nepřevzaté upozornění se za 10 minut neopakuje', bk_self_check_step($sc_untold, $sc_bad, $sc_t + 660)['notify'], null);
    check('ale za hodinu ano', bk_self_check_step($sc_untold, $sc_bad, $sc_t + 60 + 3600)['notify'], 'failed');
    // An announced failure is over after two passes in a row, not one.
    $sc4a = bk_self_check_step($sc3['state'], $sc_ok, $sc_t + 180);
    check('první úspěch po ohlášeném selhání: zotavuje se, nikomu nic',
        [$sc4a['notify'], $sc4a['state']['state'], $sc4a['state']['alerted'], $sc4a['state']['since']],
        [null, 'recovering', true, date('c', $sc_t)]);
    $sc4 = bk_self_check_step($sc4a['state'], $sc_ok, $sc_t + 240);
    check('druhý za sebou: obnova se ohlásí, i s tím, co selhávalo',
        [$sc4['notify'], $sc4['state']['state'], $sc4['state']['recoveredFrom']],
        ['restored', 'ok', ['since' => date('c', $sc_t), 'reason' => $sc_bad['reason']]]);
    $sc_relapse = bk_self_check_step($sc4a['state'], $sc_bad, $sc_t + 240);
    check('návrat selhání při zotavování pokračuje v ohlášeném: bez nového upozornění, stejné od kdy',
        [$sc_relapse['notify'], $sc_relapse['state']['alerted'], $sc_relapse['state']['since'], $sc_relapse['state']['alertResult']],
        [null, true, date('c', $sc_t), 'sent']);
    // The review's flapping API: fail, fail, ok for an hour at the cron's
    // cadence gave 20 notices. Now one failure notice, and one recovery once
    // it passes twice in a row.
    $sc_flap = [];
    $sc_notes = [];
    for ($i = 0; $i < 30; $i++) {
        $step = bk_self_check_step($sc_flap, $i % 3 === 2 ? $sc_ok : $sc_bad, $sc_t + $i * 60);
        if ($step['notify'] === 'failed') {
            $step['state']['alerted'] = true;
            $step['state']['alertResult'] = 'sent';
            $step['state']['alertAttemptAt'] = date('c', $sc_t + $i * 60);
        }
        $sc_notes[] = $step['notify'];
        $sc_flap = $step['state'];
    }
    foreach ([$sc_ok, $sc_ok] as $i => $v) {
        $step = bk_self_check_step($sc_flap, $v, $sc_t + (30 + $i) * 60);
        $sc_notes[] = $step['notify'];
        $sc_flap = $step['state'];
    }
    check('kolísající API: jedno selhání a jedna obnova', array_values(array_filter($sc_notes)), ['failed', 'restored']);
    // The two short sequences step by step (f = failed check, o = passed),
    // with every failure notice taken by a channel.
    $sc_seq = function (string $seq) use ($sc_bad, $sc_ok, $sc_t): array {
        $state = [];
        $notes = [];
        foreach (str_split($seq) as $i => $c) {
            $step = bk_self_check_step($state, $c === 'o' ? $sc_ok : $sc_bad, $sc_t + $i * 60);
            if ($step['notify'] === 'failed') {
                $step['state']['alerted'] = true;
                $step['state']['alertResult'] = 'sent';
                $step['state']['alertAttemptAt'] = date('c', $sc_t + $i * 60);
            }
            $notes[] = $step['notify'];
            $state = $step['state'];
        }
        return $notes;
    };
    check('selhání, selhání, úspěch, selhání, selhání, úspěch: jediné upozornění, žádná obnova',
        $sc_seq('ffoffo'), [null, 'failed', null, null, null, null]);
    check('selhání, selhání, úspěch, úspěch: upozornění a jedna obnova', $sc_seq('ffoo'), [null, 'failed', null, 'restored']);
    check('obnova po neohlášeném blipu mlčí', bk_self_check_step($sc1['state'], $sc_ok, $sc_t + 60)['notify'], null);
    check('po chybějící adrese začíná počítání znovu',
        bk_self_check_step(['state' => 'unconfigured'], $sc_bad, $sc_t)['state']['failures'], 1);

    // The message: the URL, how long, why - escaped in the HTML.
    $sc_state = $sc2['state'] + ['url' => 'https://example.com/status/api.php?action=public_status'];
    $sc_state['reason'] = 'Not JSON, starts with "<b>x"';
    [$sc_subj, $sc_html, $sc_text] = bk_with_email_lang('cs', fn (): array => bk_self_check_message('failed', $sc_state));
    check_true('zpráva o selhání: co, kolikrát, proč',
        str_contains($sc_subj, 'Samokontrola webu') && str_contains($sc_text, '2× za sebou')
        && str_contains($sc_text, 'https://example.com/status/api.php') && str_contains($sc_text, '<b>x'));
    check_true('v HTML escapovaná', str_contains($sc_html, '&lt;b&gt;x') && !str_contains($sc_html, '<b>x'));
    [$sc_subj_en] = bk_with_email_lang('en', fn (): array => bk_self_check_message('restored', $sc4['state'] + ['url' => 'u']));
    check_true('obnova anglicky', str_contains($sc_subj_en, 'passes again'));
}

if (function_exists('bk_send_admin_notice') && $dr_pdo instanceof PDO) {
    // Every channel an alert uses, each with its own honest row.
    $dr_pdo->exec('DELETE FROM notification_log');
    $dr_pdo->exec("UPDATE users SET phone = '777123456', whatsapp_apikey = '1234567', whatsapp_notifications = 1 WHERE id = 1");
    $GLOBALS['bk_test_mails'] = [];
    $GLOBALS['bk_test_webhooks'] = [];
    $GLOBALS['bk_test_callmebot_answer'] = ['code' => 200, 'body' => 'Message queued', 'errno' => 0, 'error' => '', 'sent' => true];
    $sc_settings = $GLOBALS['system_settings'];
    $GLOBALS['system_settings'] += ['discord_webhook_url' => 'https://discord.invalid/api/webhooks/1/x',
        'telegram_bot_token' => '123:abc', 'telegram_chat_id' => '-100200300'];
    $sc_n = bk_send_admin_notice($dr_pdo, 'self_check_failed',
        fn (): array => bk_self_check_message('failed', $sc_state), 1);
    // The mail stub confirms nothing, so its e-mail counts as unconfirmed.
    check('upozornění: e-mail, WhatsApp, Discord a Telegram, každý se svým výsledkem', $sc_n,
        ['attempted' => 4, 'sent' => 2, 'unknown' => 1, 'failed' => 1]);
    $sc_mail = $GLOBALS['bk_test_mails'][0] ?? ['to' => null, 'subject' => '', 'context' => []];
    check('e-mail administrátorovi jako admin_notice se stavem',
        [$sc_mail['to'], $sc_mail['context']['kind'] ?? null, $sc_mail['context']['status'] ?? null],
        ['admin@example.com', 'admin_notice', 'self_check_failed']);
    $sc_rows = $dr_pdo->query("SELECT channel, status, kind, ok, delivery, error_message FROM notification_log ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    check('řádky v protokolu: kanál, druh a výsledek',
        array_map(fn (array $r): string => "{$r['channel']}/{$r['kind']}/{$r['status']}/{$r['delivery']}", $sc_rows),
        ['whatsapp/admin_notice/self_check_failed/sent', 'discord/admin_notice/self_check_failed/sent',
         'telegram/admin_notice/self_check_failed/failed']);
    check('odmítnutí nese důvod', $sc_rows[2]['error_message'] ?? null, 'HTTP 400');
    check_false('webhooky dostaly text, ne HTML', str_contains($GLOBALS['bk_test_webhooks'][0]['payload'] ?? '<', '<p'));
    // "public_status" in the URL: an unpaired "_" makes Telegram refuse Markdown.
    check_false('Telegram bez Markdownu', str_contains($GLOBALS['bk_test_webhooks'][1]['payload'] ?? 'parse_mode', 'parse_mode'));

    // Nobody to tell: nothing attempted, and the caller can say so.
    $dr_pdo->exec("UPDATE users SET role = 'user' WHERE id = 1");
    $GLOBALS['system_settings'] = $sc_settings;
    check('bez administrátora a kanálů se nic nepokusí',
        bk_send_admin_notice($dr_pdo, 'self_check_failed', fn (): array => bk_self_check_message('failed', $sc_state), 1),
        ['attempted' => 0, 'sent' => 0, 'unknown' => 0, 'failed' => 0]);
    $dr_pdo->exec("UPDATE users SET role = 'admin', phone = NULL, whatsapp_apikey = NULL, whatsapp_notifications = 0 WHERE id = 1");
    $GLOBALS['bk_test_callmebot_answer'] = null;
}

// --- cron: the reminder really is wired in --------------------------------
// Nothing executes cron.php here, so what can be checked is that the block
// exists, that it asks the guard BEFORE sending, that it writes the date
// stamp, and that it lives in a try of its own - inside the digest's try a
// failing reminder would take the escalations down with it.
$dr_block = (function () use ($cron_src): string {
    $start = strpos($cron_src, '// --- Daily reminder');
    $end = strpos($cron_src, '// --- Escalation of unacknowledged incidents', $start === false ? 0 : $start);
    return $start !== false && $end !== false ? substr($cron_src, $start, $end - $start) : '';
})();
check_true('cron má blok denní připomínky za digesty', $dr_block !== '');
check_true('ptá se nejdřív stráže, pak posílá',
    $dr_block !== '' && strpos($dr_block, 'bk_daily_reminder_due(') < strpos($dr_block, 'bk_send_daily_reminder('));
check_true('a respektuje vypínač', str_contains($dr_block, "get_setting('daily_reminder_enabled', '1')"));
check_true('zapisuje razítko dne', str_contains($dr_block, 'last_daily_reminder_sent')
    && str_contains($dr_block, "date('Y-m-d')"));
// The stamp is written whichever way it ended. Only inside an `if ($sent)` it
// would let a refused channel be retried every minute until midnight.
check_false('razítko není podmíněné úspěchem odeslání',
    str_contains($dr_block, "if (\$reminder['sent'])") && strpos($dr_block, 'last_daily_reminder_sent') > strpos($dr_block, "if (\$reminder['sent'])"));
check_true('běží ve vlastním try/catch', str_contains($dr_block, '} catch (Throwable $e) {'));
check_true('a selhání nezůstane potichu', str_contains($dr_block, 'Denní připomínka selhala'));

// --- testovací brány: běh bez kontrol musí skončit červeně -----------------
// Why here: run_api_tests.php used to exit 0 when MySQL was unreachable, so a
// wrong password looked exactly like 980 passing checks. A gate that can be
// green without running is the one failure mode nobody notices, so the
// behaviour itself is tested - in subprocesses, because an exit code is the
// only thing a caller (CI) ever sees.
$gate_run = function (array $env, string $script, array $args = []): array {
    $cmd = escapeshellarg(PHP_BINARY);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }
    $out = [];
    $code = 0;
    exec($prefix . $cmd . ' ' . escapeshellarg($script) . ' 2>&1', $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
};

// bk_test_report() over an untouched counter: no check ran, so the run is red.
$gate_empty = $gate_run([], __DIR__ . '/assert_helpers.php', [
    '-r',
    'require ' . var_export(__DIR__ . '/assert_helpers.php', true) . '; exit(bk_test_report("prázdná ukázka") > 0 ? 1 : 0);',
]);
check('sada, která neprovedla ani jednu kontrolu, končí nenulovým kódem', $gate_empty['code'], 1);
check_true('a řekne, že neověřila nic', str_contains($gate_empty['out'], 'PRÁZDNÁ SADA'));
check_true('a počty v souhrnu si nevymýšlí', str_contains($gate_empty['out'], '0 prošlo, 0 selhalo'));

// The API suite without a reachable database. Port 1 has no listener, so the
// connection is refused before the suite writes config.php or touches any schema.
$gate_db = $gate_run(['BK_TEST_DB_HOST' => '127.0.0.1', 'BK_TEST_DB_PORT' => '1', 'BK_TEST_DB_NAME' => 'bk_gate_nikdy'],
    __DIR__ . '/run_api_tests.php');
check('API sada bez dostupné databáze končí nenulovým kódem', $gate_db['code'], 1);
check_true('a přizná, že testy neproběhly', str_contains($gate_db['out'], 'NEPROBĚHLY'));

// An empty database name is a typo in the environment, not a request for the default.
$gate_name = $gate_run(['BK_TEST_DB_NAME' => ''], __DIR__ . '/run_api_tests.php');
check('prázdné BK_TEST_DB_NAME API sadu zastaví', $gate_name['code'], 1);
check_true('a vysvětlí proč', str_contains($gate_name['out'], 'BK_TEST_DB_NAME je prázdné'));

// The same hole in the two linters that discover their own inputs: a copy of
// the script into an empty tree finds nothing to read. No test hook in the
// lint itself - the real path is the one worth proving.
$gate_tmp = sys_get_temp_dir() . '/bk_gate_' . getmypid() . '/tests';
@mkdir($gate_tmp, 0777, true);
foreach (['run_query_lint.php', 'run_honesty_lint.php'] as $gate_lint) {
    copy(__DIR__ . '/' . $gate_lint, $gate_tmp . '/' . $gate_lint);
    $gate_res = $gate_run([], $gate_tmp . '/' . $gate_lint);
    check("lint {$gate_lint} bez jediného souboru ke čtení končí červeně", $gate_res['code'], 1);
    check_true("a {$gate_lint} řekne, že neověřil nic", str_contains($gate_res['out'], 'neověřil nic'));
    @unlink($gate_tmp . '/' . $gate_lint);
}
@rmdir($gate_tmp);
@rmdir(dirname($gate_tmp));

$failed = bk_test_report('sběr, e-maily, notifikace');
// Under the coverage runner the process does not exit - the report would never generate.
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
