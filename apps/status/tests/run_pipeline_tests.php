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
// Nine call sites when the log was built, ten since the daily reminder. The
// number is hard-coded on purpose: a new send_email() has to be noticed here,
// where someone decides which kind it writes into the log.
check_true('kontrola opravdu našla všech deset volání', $kind_calls === 10);
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
    'bk_render_daily_reminder', 'render_email_wrapper', 'bk_log_notification',
    'bk_heartbeat_evaluate', 'bk_alert_color_class', 'bk_format_duration_secs',
]);
bk_test_load_functions(__DIR__ . '/../db.php', ['get_setting', 'bk_settings_defaults']);

// The mail stub: nothing leaves the machine, and every attempt is kept so the
// tests can ask what the reminder said. send_email() writes the log row
// itself in production - here that row is not the subject of the test.
if (!function_exists('send_email')) {
    function send_email($to, $subject, $html_body, array $extra_headers = [], array $context = []) {
        $GLOBALS['bk_test_mails'][] = ['to' => $to, 'subject' => $subject, 'body' => $html_body, 'context' => $context];
        return true;
    }
}

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
    $dr_pdo->exec("CREATE TABLE notification_log (id INTEGER PRIMARY KEY, monitor_id INTEGER, status TEXT,
        channel TEXT, recipient TEXT, ok INTEGER, error_message TEXT, kind TEXT, subject TEXT, method TEXT)");
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
} elseif (function_exists('bk_send_daily_reminder')) {
    // Reported, never skipped in silence: a suite that quietly tests nothing
    // is the same lie as a chart with invented values.
    check_true('SQLite ovladač pro testy denní připomínky je k dispozici', false);
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
