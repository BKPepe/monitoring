<?php
/**
 * Tests of the pure functions from functions.php (no DB, no network).
 *
 * Running:  php apps/status/tests/run_tests.php
 * In CI: .github/workflows/deploy-status.yml runs them before the deploy.
 *
 * Why this way: the app runs on shared hosting without Composer, so PHPUnit
 * is unavailable. This runner needs nothing but PHP and catches exactly the
 * class of regressions this project kept producing -
 * invented values and wrong input evaluation.
 */

require_once __DIR__ . '/assert_helpers.php';
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_validate_import_target',
    'bk_version_is_older',
    'bk_format_duration',
    'bk_effective_threshold',
    // bk_heartbeat_evaluate si vola bk_format_duration_secs, takze musi nacist obe.
    'bk_heartbeat_evaluate',
    'bk_format_duration_secs',
    'bk_heartbeat_generate_token',
    'bk_escalation_due',
    'bk_ip_in_cidr',
    'bk_agent_num',
    'bk_agent_int',
    'bk_period_minutes',
    'bk_pearson',
    'bk_counter_deltas',
    'bk_trusted_link_host',
    'bk_lte_backup_state',
    'bk_wan_link_state',
    'bk_pair_link_periods',
    'bk_pagerduty_action',
    'bk_down_is_confirmed',
    'bk_alert_color_class',
    'bk_should_notify_status_change',
    'bk_ssl_alert_due',
    'bk_maintenance_window_expired',
    'bk_agent_str',
    'bk_smart_is_missing',
    'bk_agent_bool',
    'bk_http_verdict',
    'bk_agent_backup_says_up',
    'bk_viewer',
    'bk_monitor_scope_sql',
    'bk_public_monitor_details',
    'bk_public_reason',
    'bk_public_incident_update',
    'bk_wifi_band_totals',
    'bk_wifi_count',
    'bk_exclude_ids_sql',
    'bk_agent_label',
    'bk_agent_silence_hint',
    'bk_sync_session_account',
    'bk_metric_column_map',
    'bk_ranged_num',
    'bk_ranged_int',
    'bk_wifi_radio_profile',
    'bk_wifi_band_of',
    'bk_sanitize_wifi_radios',
    'bk_disk_key',
    'bk_sanitize_storage_disks',
    'bk_sanitize_agent_tools',
    'bk_counter_step',
    'bk_wan_counter_steps',
    'bk_sanitize_wan_path',
    'bk_sanitize_lan_ports',
    'bk_details_fit',
    'bk_ingest_issue_add',
    'bk_pagerduty_dedup_key',
    'bk_disk_temp_limit',
    'bk_disk_error_counters',
    'bk_disk_label',
    'bk_storage_alert_state',
    'bk_storage_alert_event',
    'bk_disk_counter_rules',
    'bk_disk_wear_rules',
    'bk_storage_alert_eval',
    'bk_fs_alert_eval',
    'bk_storage_gate_open',
    'bk_disk_host_write_delta',
    'bk_speedtest_diagnostics',
    'bk_speedtest_unit_ok',
    'bk_speedtest_item',
    'bk_speedtest_ack',
    'bk_speedtest_in_report',
    'bk_router_alert_event',
    'bk_wan_link_baseline_rules',
    'bk_router_alert_eval',
    'bk_format_duration_secs',
    'bk_reports_24h_expected',
    'bk_wireguard_peer_count',
    'bk_uptime_interval',
    'bk_uptime_segments',
    'bk_uptime_summary',
    'bk_uptime_totals',
    'bk_uptime_pct_round',
    'bk_uptime_by_day',
]);


// --- bk_validate_import_target: imported targets ----------------------------
// Everything a hosting-side check can never reach is blocked. Exactly this
// gap produced three monitors reporting a permanent false outage.
if (function_exists('bk_validate_import_target')) {
    check('veřejná doména projde', bk_validate_import_target('dns.example.com'), null);
    check('veřejná IPv4 projde', bk_validate_import_target('8.8.8.8'), null);
    check('veřejná IPv6 projde', bk_validate_import_target('2a06:98c1:3120::3'), null);
    check('doména s portem projde', bk_validate_import_target('bloodkings.eu:53'), null);

    check_true('privátní IPv4 odmítnuta', bk_validate_import_target('192.168.1.10') !== null);
    check_true('privátní IPv4 s portem odmítnuta', bk_validate_import_target('10.0.0.5:53') !== null);
    check_true('loopback IPv6 v závorkách odmítnut', bk_validate_import_target('[::1]:53') !== null);
    check_true('link-local IPv6 odmítnuta', bk_validate_import_target('fe80::1') !== null);
    check_true('localhost odmítnut', bk_validate_import_target('localhost') !== null);
    check_true('.lan jméno odmítnuto', bk_validate_import_target('router.lan') !== null);
    check_true('.internal jméno odmítnuto', bk_validate_import_target('kresd.internal') !== null);
    check_true('prázdný cíl odmítnut', bk_validate_import_target('') !== null);
    check_true('URL na privátní IP odmítnuta', bk_validate_import_target('https://192.168.0.1/admin') !== null);
    // Exactly the input that produced the broken kresd monitor:
    check_true('jméno monitoru není adresa', bk_validate_import_target('Router - Praha') !== null);
}

// --- bk_version_is_older: the agent update offer ----------------------------
// String comparison reported "outdated" for current agents here.
if (function_exists('bk_version_is_older')) {
    check_true('1.5.2 je starší než 1.5.6', bk_version_is_older('1.5.2', '1.5.6'));
    check_false('1.5.6 není starší než 1.5.6', bk_version_is_older('1.5.6', '1.5.6'));
    check_false('1.7.3 není starší než 1.5.6', bk_version_is_older('1.7.3', '1.5.6'));
    // The classic string-comparison trap: "1.10.0" < "1.9.0" as text.
    check_false('1.10.0 není starší než 1.9.0', bk_version_is_older('1.10.0', '1.9.0'));
    check_true('1.9.0 je starší než 1.10.0', bk_version_is_older('1.9.0', '1.10.0'));
    check_false('neznámá verze nehlásí zastaralost', bk_version_is_older(null, '1.5.6'));
    check_false('bez referenční verze se nehlásí nic', bk_version_is_older('1.0.0', null));
}

// --- bk_format_duration --------------------------------------------------
if (function_exists('bk_format_duration')) {
    check_true('trvání je neprázdný text', is_string(bk_format_duration(1800)) && bk_format_duration(1800) !== '');
}

// --- bk_effective_threshold (presety) ------------------------------------
// Preset ma prednost pred hodnotou monitoru, ale jen kdyz prah opravdu resi.
if (function_exists('bk_effective_threshold')) {
    $preset_full = ['metrics' => null, 'cpu' => 70, 'ram' => 80, 'hdd' => null];

    check('preset prebiji hodnotu monitoru', bk_effective_threshold($preset_full, 90, 'cpu'), 70);
    check('preset bez prahu nechava hodnotu monitoru', bk_effective_threshold($preset_full, 90, 'hdd'), 90);
    check('bez presetu plati hodnota monitoru', bk_effective_threshold(null, 85, 'cpu'), 85);
    check('nic nenastaveno = zadny prah', bk_effective_threshold(null, null, 'cpu'), null);
    check('prazdny retezec u monitoru = zadny prah', bk_effective_threshold(null, '', 'ram'), null);
    // Nula je platny prah (napr. "hlas cokoli"), ne "nenastaveno".
    check('nulovy prah v presetu se respektuje', bk_effective_threshold(['metrics' => null, 'cpu' => 0, 'ram' => null, 'hdd' => null], 90, 'cpu'), 0);
    check('nulovy prah u monitoru se respektuje', bk_effective_threshold(null, 0, 'cpu'), 0);
}

// --- preset vs. vlastni sada metrik --------------------------------------
// Volajici musi predat $pdo, jinak je vetev s presetem mrtva a prepinac
// v UI nic nedela (presne to se stalo pri prvnim nasazeni presetu).
// Funkce se sem nenacita (potrebuje DB), takze se cte primo ze zdroje.
$fn_src = file_get_contents(__DIR__ . '/../functions.php');
check_true(
    'bk_get_enabled_metrics prijima $pdo',
    (bool)preg_match('/function bk_get_enabled_metrics\([^)]*\$pdo/', $fn_src)
);

$callers = [
    __DIR__ . '/../index.php',
    __DIR__ . '/../api.php',
    __DIR__ . '/../admin.php',
];
$missing_pdo = [];
foreach ($callers as $caller_file) {
    foreach (file($caller_file, FILE_IGNORE_NEW_LINES) as $line) {
        $trimmed = ltrim($line);
        // Zminka v komentari neni volani - bez tehle podminky test padal
        // na radku "(viz bk_get_enabled_metrics())".
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
            continue;
        }
        if (!str_contains($line, 'bk_get_enabled_metrics(')) {
            continue;
        }
        // Definice funkce se take nepocita.
        if (str_contains($line, 'function bk_get_enabled_metrics')) {
            continue;
        }
        if (!str_contains($line, '$pdo')) {
            $missing_pdo[] = basename($caller_file);
        }
    }
}
check('vsichni volajici predavaji $pdo', $missing_pdo, []);

// --- The public view carries no host internals ------------------------------
//
// agent_api passes through keys the server has never heard of, so the public
// monitor list uses an allowlist (bk_public_monitor_details) instead of the
// name-based denylist it replaced: a denylist let every new key through, from
// process lists to interface names. This guards both halves - the network
// identity and new agent keys stay out, what the public card renders stays in.
if (function_exists('bk_public_monitor_details')) {
    $sample = array_fill_keys(['lte_ipv4', 'wan_ipv6', 'public_ip', 'lan_subnet', 'wifi_ssid', 'peer_endpoint',
        'device_mac', 'board_serial', 'hostname', 'agent_key', 'api_token', 'top_ram_processes', 'wan_l3_device',
        'mwan3_active_gw', 'net', 'filesystems', 'some_future_agent_key',
        // Router release 0.1.7: new explicit keys of last_details. The disk
        // list names models and partitions, the radios name the SSID, and
        // wan_path describes the household's line - none of it is public.
        // lan_ports draws the household's own wiring: which socket carries a
        // cable and how many devices sit behind it. Never in the public view.
        'storage_disks', 'agent_tools', 'fs_alerts', 'wifi_radios', 'wan_path', 'wan_counters_prev', 'lan_ports',
        'version', 'motd', 'players_online', 'players_max', 'clients_online', 'clients_max',
        'presence_count', 'members', 'voice_channels', 'model', 'os', 'cpanel_stats'], 1);
    $public_keys = array_keys(bk_public_monitor_details($sample));
    foreach (['lte_ipv4', 'wan_ipv6', 'public_ip', 'lan_subnet', 'wifi_ssid', 'peer_endpoint', 'device_mac',
              'board_serial', 'hostname', 'agent_key', 'api_token', 'top_ram_processes', 'wan_l3_device',
              'mwan3_active_gw', 'net', 'filesystems', 'some_future_agent_key',
              'storage_disks', 'agent_tools', 'fs_alerts', 'wifi_radios', 'wan_path', 'wan_counters_prev',
              'lan_ports'] as $private_key) {
        check_false("anonym neuvidí {$private_key}", in_array($private_key, $public_keys, true));
    }
    foreach (['version', 'motd', 'players_online', 'players_max', 'clients_online', 'clients_max',
              'presence_count', 'members', 'voice_channels', 'model', 'os', 'cpanel_stats'] as $card_key) {
        check_true("veřejná karta dostane {$card_key}", in_array($card_key, $public_keys, true));
    }
}
check_true('veřejný pohled seznamu monitorů jde přes allowlist',
    str_contains(file_get_contents(__DIR__ . '/../api.php'), '$details_out = bk_public_monitor_details($details);'));

// --- Heartbeat monitory --------------------------------------------------
//
// Hlida se hlavne to, ze "jeste se neozvala" neni totez co "je dole".
// Monitor, ktery nikdy nedostal signal, o sobe nevi nic - a alert na vypadek,
// ktery se nestal, je stejna lez jako vymyslena nula v grafu.
$hb_base = [
    'heartbeat_interval' => 3600,
    'heartbeat_grace' => 300,
    'last_heartbeat' => null,
    'heartbeat_last_result' => null,
    'heartbeat_last_message' => null,
];
$now = strtotime('2026-08-10 12:00:00');
$hb_at = function (string $when) use ($now) { return date('Y-m-d H:i:s', strtotime($when, $now)); };

$r = bk_heartbeat_evaluate($hb_base, $now);
check('bez signalu neni down, ale unknown', $r['status'], 'unknown');
check('bez signalu se nehlasi stari', $r['age_secs'], null);

$r = bk_heartbeat_evaluate(array_merge($hb_base, ['heartbeat_interval' => null]), $now);
check('bez intervalu nejde nic vyhodnotit', $r['status'], 'unknown');

$r = bk_heartbeat_evaluate(array_merge($hb_base, ['last_heartbeat' => $hb_at('-10 minutes')]), $now);
check('cerstvy signal je up', $r['status'], 'up');
check('u up se nehlasi zpozdeni', $r['overdue_secs'], null);

// Presne na hranici intervalu + tolerance jeste ne - az za ni.
$r = bk_heartbeat_evaluate(array_merge($hb_base, ['last_heartbeat' => $hb_at('-3900 seconds')]), $now);
check('presne na limitu je jeste up', $r['status'], 'up');

$r = bk_heartbeat_evaluate(array_merge($hb_base, ['last_heartbeat' => $hb_at('-3901 seconds')]), $now);
check('sekundu za limitem uz je down', $r['status'], 'down');
check('zpozdeni se spocita od limitu', $r['overdue_secs'], 1);

// Tolerance musi opravdu odsouvat hranici, ne jen zdobit vypis.
$r = bk_heartbeat_evaluate(array_merge($hb_base, ['heartbeat_grace' => null, 'last_heartbeat' => $hb_at('-3700 seconds')]), $now);
check('bez tolerance plati holy interval', $r['status'], 'down');

// Uloha dobehla vcas, ale skoncila chybou. Mlcet o tom jen proto, ze signal
// prisel, by z hlidace udelalo kontrolu, ze cron startuje - ne ze zaloha vznikla.
$r = bk_heartbeat_evaluate(array_merge($hb_base, [
    'last_heartbeat' => $hb_at('-5 minutes'),
    'heartbeat_last_result' => 'fail',
    'heartbeat_last_message' => 'tar skoncil kodem 2',
]), $now);
check('ohlasene selhani je down i pri cerstvem signalu', $r['status'], 'down');
check_true('duvod nese text od ulohy', str_contains((string)$r['error'], 'tar skoncil kodem 2'));

// Stare 'fail' uz neni to hlavni - tam je problem, ze se uloha vubec neozvala.
$r = bk_heartbeat_evaluate(array_merge($hb_base, [
    'last_heartbeat' => $hb_at('-2 days'),
    'heartbeat_last_result' => 'fail',
]), $now);
check('po limitu prevazi mlceni nad starym selhanim', $r['status'], 'down');
check_true('hlaska mluvi o tom, ze se neozvala', str_contains((string)$r['error'], 'neozvala'));

// Rozejite hodiny na stroji s ulohou nesmi zpusobit falesny vypadek.
$r = bk_heartbeat_evaluate(array_merge($hb_base, ['last_heartbeat' => $hb_at('+10 minutes')]), $now);
check('signal z budoucnosti je porad cerstvy', $r['status'], 'up');

check('doba v sekundach', bk_format_duration_secs(45), '45 s');
check('doba v minutach', bk_format_duration_secs(300), '5 min');
check('doba v hodinach', bk_format_duration_secs(7320), '2 h 2 min');
check('doba ve dnech', bk_format_duration_secs(180000), '2 d 2 h');

// Token musi byt dost dlouhy a nahodny - je to jedina autorizace endpointu.
$t1 = bk_heartbeat_generate_token();
$t2 = bk_heartbeat_generate_token();
check('token ma 48 hex znaku', strlen($t1), 48);
check_true('token je hex', (bool)preg_match('/^[0-9a-f]+$/', $t1));
check_true('dva tokeny se nerovnaji', $t1 !== $t2);

// --- Cteni metrik z hlaseni agenta ---------------------------------------
//
// Jadro pravidla projektu: chybejici hodnota je NULL, nikdy nula. U zahozenych
// paketu nebo teploty je "nula" uplne jina informace nez "agent to neposlal".
check('cislo se precte', bk_agent_num(['cpu' => 42.5], 'cpu'), 42.5);
check('cislo v retezci se precte', bk_agent_num(['cpu' => '42.5'], 'cpu'), 42.5);
check('nula je platne mereni', bk_agent_num(['fw_dropped' => 0], 'fw_dropped'), 0.0);
check('chybejici klic je NULL, ne nula', bk_agent_num([], 'cpu'), null);
check('JSON null je NULL', bk_agent_num(['cpu' => null], 'cpu'), null);
check('retezec "null" od starsiho agenta je NULL', bk_agent_num(['cpu' => 'null'], 'cpu'), null);
check('prazdny retezec je NULL', bk_agent_num(['cpu' => ''], 'cpu'), null);
check('text neni cislo', bk_agent_num(['cpu' => 'neznamo'], 'cpu'), null);
check('pole neni cislo', bk_agent_num(['cpu' => [1, 2]], 'cpu'), null);
// true by se pretypovalo na 1.0 a tvarilo se jako mereni.
check('true neni cislo', bk_agent_num(['wan_up' => true], 'wan_up'), null);
check('zaporna hodnota projde', bk_agent_num(['lte_rsrp' => -95.5], 'lte_rsrp'), -95.5);

check('celociselna varianta zaokrouhli', bk_agent_int(['x' => 41.6], 'x'), 42);
check('celociselna varianta drzi NULL', bk_agent_int([], 'x'), null);

// --- Skutecna IP navstevnika za proxy ------------------------------------
//
// Bezpecnostni funkce, takze se testuje hlavne to, co se ma ODMITNOUT.
// Kdyby se hlavicce verilo vzdy, muze si kdokoli zapsat do audit logu
// libovolnou adresu a obchazet zamykani uctu tim, ze ji bude menit.
check_true('IPv4 v rozsahu Cloudflare', bk_ip_in_cidr('104.16.5.9', '104.16.0.0/13'));
check_false('IPv4 mimo rozsah', bk_ip_in_cidr('8.8.8.8', '104.16.0.0/13'));
check_true('hranice rozsahu patri dovnitr', bk_ip_in_cidr('104.16.0.0', '104.16.0.0/13'));
check_false('adresa tesne pod rozsahem uz ne', bk_ip_in_cidr('104.15.255.255', '104.16.0.0/13'));

// Prefix, ktery nekonci na cely bajt - tady selze naivni porovnani retezcu.
check_true('prefix /22 uvnitr', bk_ip_in_cidr('103.21.247.1', '103.21.244.0/22'));
check_false('prefix /22 vne', bk_ip_in_cidr('103.21.248.1', '103.21.244.0/22'));

// IPv6 ve zkracenem zapisu - bez binarniho porovnani by neprosla.
check_true('IPv6 v rozsahu Cloudflare', bk_ip_in_cidr('2606:4700::1111', '2606:4700::/32'));
check_false('IPv6 mimo rozsah', bk_ip_in_cidr('2001:4860::8888', '2606:4700::/32'));

// Michani rodin adres je vzdy ne - jinak by IPv4 "prosla" IPv6 rozsahem.
check_false('IPv4 neprojde IPv6 rozsahem', bk_ip_in_cidr('104.16.5.9', '2606:4700::/32'));
check_false('IPv6 neprojde IPv4 rozsahem', bk_ip_in_cidr('2606:4700::1', '104.16.0.0/13'));

check_false('nesmysl misto adresy', bk_ip_in_cidr('neni-adresa', '104.16.0.0/13'));
check_false('CIDR bez lomitka', bk_ip_in_cidr('104.16.5.9', '104.16.0.0'));
check_false('zaporny prefix', bk_ip_in_cidr('104.16.5.9', '104.16.0.0/-1'));
check_false('prilis velky prefix', bk_ip_in_cidr('104.16.5.9', '104.16.0.0/33'));

// --- Escalation of unacknowledged incidents --------------------------------
//
// Escalation wakes a human, so every condition must hold literally. The stamp
// matters most: without it the same incident would be reported on every cron
// run and we would learn to ignore it like the first alert.
$inc_base = [
    'status' => 'investigating',
    'created_at' => null,
    'resolved_at' => null,
    'acknowledged_at' => null,
    'escalated_at' => null,
];
$esc_at = function (string $when) use ($now) { return date('Y-m-d H:i:s', strtotime($when, $now)); };

$r = bk_escalation_due(array_merge($inc_base, ['created_at' => $esc_at('-30 minutes')]), 15, $now);
check_true('nepřevzatý incident po lhůtě eskaluje', $r['escalate']);
check('doba čekání se počítá od vzniku', $r['waiting_secs'], 1800);

$r = bk_escalation_due(array_merge($inc_base, ['created_at' => $esc_at('-10 minutes')]), 15, $now);
check_false('před uplynutím lhůty se neeskaluje', $r['escalate']);

// Exactly at the boundary yes - the period has elapsed.
$r = bk_escalation_due(array_merge($inc_base, ['created_at' => $esc_at('-900 seconds')]), 15, $now);
check_true('přesně po lhůtě se eskaluje', $r['escalate']);

$r = bk_escalation_due(array_merge($inc_base, [
    'created_at' => $esc_at('-2 hours'),
    'acknowledged_at' => $esc_at('-100 minutes'),
]), 15, $now);
check_false('převzatý incident neeskaluje', $r['escalate']);
check('a je řečeno proč', $r['reason'], 'incident někdo převzal');

$r = bk_escalation_due(array_merge($inc_base, [
    'created_at' => $esc_at('-2 hours'),
    'escalated_at' => $esc_at('-1 hour'),
]), 15, $now);
check_false('už eskalovaný incident se neopakuje', $r['escalate']);

$r = bk_escalation_due(array_merge($inc_base, [
    'created_at' => $esc_at('-2 hours'),
    'status' => 'resolved',
]), 15, $now);
check_false('vyřešený incident neeskaluje', $r['escalate']);

$r = bk_escalation_due(array_merge($inc_base, [
    'created_at' => $esc_at('-2 hours'),
    'resolved_at' => $esc_at('-30 minutes'),
]), 15, $now);
check_false('incident s časem vyřešení neeskaluje', $r['escalate']);

// A corrupted or missing creation time: waking a human over a broken record
// is worse than doing nothing.
$r = bk_escalation_due(array_merge($inc_base, ['created_at' => null]), 15, $now);
check_false('bez času vzniku se neeskaluje', $r['escalate']);
check('a je řečeno proč', $r['reason'], 'incident nemá použitelný čas vzniku');

$r = bk_escalation_due(array_merge($inc_base, ['created_at' => $esc_at('-2 hours')]), 0, $now);
check_false('nulová lhůta eskalaci vypíná', $r['escalate']);

// --- bk_period_minutes: the period window --------------------------------
// Two periods returned a different window than their label claimed: `15m`
// returned an hour and `6h` returned 24 hours (verified in production too).
// You switched the range, the chart changed, and it showed something else.
if (function_exists('bk_period_minutes')) {
    check('15m je opravdu 15 minut', bk_period_minutes('15m'), 15);
    check('1h je hodina', bk_period_minutes('1h'), 60);
    check('6h je šest hodin, ne 24', bk_period_minutes('6h'), 360);
    check('24h je den', bk_period_minutes('24h'), 1440);
    check('7d je týden', bk_period_minutes('7d'), 10080);
    check('30d je třicet dní', bk_period_minutes('30d'), 43200);
    // Longer periods come from the daily rollup, not raw data - hence null.
    check_true('90d jde přes denní agregaci', bk_period_minutes('90d') === null);
    check_true('1y jde přes denní agregaci', bk_period_minutes('1y') === null);
    check('neznámé období spadne na den', bk_period_minutes('nesmysl'), 1440);
}

// --- Correlations between metrics (metric_correlations) -------------------
//
// The statistics here are easy to get subtly wrong in ways that still produce
// a plausible-looking number, which is worse than producing none.
if (function_exists('bk_pearson')) {
    $rising = range(1, 20);
    $falling = array_reverse($rising);

    $r_same = bk_pearson($rising, $rising);
    check('dokonalá shoda je r = 1', $r_same['r'], 1.0);
    check_true('a nepřeteče přes 1 zaokrouhlením', $r_same['r'] <= 1.0);
    check('opačný průběh je r = -1', bk_pearson($rising, $falling)['r'], -1.0);
    check('a počítá se ze všech párů', $r_same['pairs'], 20);

    // The core honesty rule of this endpoint: a series that never moves has no
    // correlation to report. Zero would claim "these two are unrelated",
    // which is a different - and unearned - statement.
    $flat = array_fill(0, 20, 5.0);
    $const = bk_pearson($rising, $flat);
    check_true('konstantní řada nemá korelaci (null, ne nula)', $const['r'] === null);
    check('a přizná proč', $const['reason'], 'constant');

    // Too little data must not yield a coefficient either - with three points
    // almost anything correlates.
    $few = bk_pearson([1.0, 2.0, 3.0], [2.0, 4.0, 6.0]);
    check_true('málo vzorků nedá koeficient', $few['r'] === null);
    check('a řekne to', $few['reason'], 'few_samples');

    // Pairs are formed only where both metrics measured something; the agent
    // may report CPU and skip temperature in the same row.
    $with_gaps = $rising;
    $with_gaps[3] = null;
    $with_gaps[7] = null;
    $gapped = bk_pearson($with_gaps, $rising);
    check('dvojice s chybějícím měřením se nepočítá', $gapped['pairs'], 18);
    check('zbytek dá pořád dokonalou shodu', $gapped['r'], 1.0);

    // A real-world shape: related but not identical.
    $noisy = array_map(fn($v) => $v * 2 + (($v % 3) - 1), $rising);
    $partial = bk_pearson($rising, $noisy);
    check_true('zašuměná závislost vyjde vysoká, ale ne dokonalá', $partial['r'] > 0.9 && $partial['r'] < 1.0);
}

if (function_exists('bk_counter_deltas')) {
    // Counters only grow, so their raw values correlate with everything that
    // grows. The panel compares increments instead.
    check('počítadlo se převádí na přírůstky', bk_counter_deltas([10.0, 12.0, 15.0]), [null, 2.0, 3.0]);
    // A drop means the counter reset (reboot) - that increment is unknowable.
    $reset = bk_counter_deltas([10.0, 12.0, 3.0, 5.0]);
    check_true('po restartu počítadla je přírůstek neznámý, ne záporný', $reset[2] === null);
    check('a další přírůstek se počítá od nové hodnoty', $reset[3], 2.0);
    // A missing measurement breaks the chain: the next increment would span
    // an unknown stretch of time.
    $gap = bk_counter_deltas([10.0, null, 20.0, 22.0]);
    check_true('po výpadku měření se přírůstek nedopočítává', $gap[2] === null);
    check('a navazuje se až dalším měřením', $gap[3], 2.0);
}

// --- Trusted host for links in public mail (bk_trusted_link_host) ---------
//
// A confirmation mail goes to an address the requester names, so an arbitrary
// Host header must never end up in its link - that would be a signed phishing
// mail pointing at the attacker.
if (function_exists('bk_trusted_link_host')) {
    $allow = ['localhost', '127.0.0.1'];
    check('vlastní SERVER_NAME se přijme', bk_trusted_link_host('bloodkings.eu', 'bloodkings.eu', $allow), 'bloodkings.eu');
    check_true(
        'cizí Host se zahodí ve prospěch SERVER_NAME',
        bk_trusted_link_host('evil.example', 'bloodkings.eu', $allow) === 'bloodkings.eu'
    );
    check('povolený vývojový host projde', bk_trusted_link_host('localhost:5273', 'localhost', $allow), 'localhost:5273');
    check_true(
        'host mimo allowlist i mimo SERVER_NAME se zahodí',
        bk_trusted_link_host('attacker.test', 'app.internal', $allow) === 'app.internal'
    );
    check_true('prázdný Host spadne na SERVER_NAME', bk_trusted_link_host('', 'bloodkings.eu', $allow) === 'bloodkings.eu');
    // Case-folding: a Host differing only in case must still match SERVER_NAME,
    // or the check could be dodged with BloodKings.EU.
    check('velikost písmen nerozhoduje', bk_trusted_link_host('BloodKings.EU', 'bloodkings.eu', $allow), 'bloodkings.eu');
}

// --- LTE backup verdict (bk_lte_backup_state) -------------------------------
//
// The bug this guards: a HiLink modem's interface is "up" with no SIM in it,
// so the interface flag alone must never count as a working backup.
if (function_exists('bk_lte_backup_state')) {
    $v = bk_lte_backup_state(['lte_up' => true, 'lte_connected' => true, 'lte_sim_state' => 'ready']);
    check_true('přihlášený modem s připravenou SIM = záloha funkční', $v['ok'] === true);

    // THE regression: interface up, modem silent - that is not "working".
    $v = bk_lte_backup_state(['lte_up' => true, 'lte_connected' => null, 'lte_sim_state' => null]);
    check_true('rozhraní up bez slova od modemu není funkční záloha (null, ne true)', $v['ok'] === null);

    check_true('žádné LTE = žádný verdikt', bk_lte_backup_state([])['ok'] === null);
    check_true('rozhraní up, connected z monitoringu, SIM neznámá = funkční (901 registraci dokazuje)',
        bk_lte_backup_state(['lte_up' => true, 'lte_connected' => true, 'lte_sim_state' => null])['ok'] === true);

    foreach (['no_sim', 'pin_required', 'puk_required', 'invalid'] as $bad) {
        $v = bk_lte_backup_state(['lte_up' => true, 'lte_connected' => true, 'lte_sim_state' => $bad]);
        check_true("SIM ve stavu {$bad} = nefunkční i když modem tvrdí 901", $v['ok'] === false && $v['reason'] === $bad);
        check_true("a nese text pro operátora ({$bad})", is_string($v['text']) && $v['text'] !== '');
    }
    // The real case from the user's Turris: PIN fine (257), yet SimStatus 4 -
    // the network rejects the SIM - and the code must be in the text.
    $v = bk_lte_backup_state(['lte_up' => true, 'lte_connected' => false, 'lte_sim_state' => 'invalid', 'lte_sim_status_code' => 4]);
    check_true('SIM odmítnutá sítí nese SimStatus v textu', $v['reason'] === 'invalid' && str_contains($v['text'], 'SimStatus 4'));

    $v = bk_lte_backup_state(['lte_up' => true, 'lte_connected' => true, 'lte_sim_state' => 'pin_required', 'lte_sim_pin_left' => 2]);
    check_true('u PINu se hlásí zbývající pokusy', str_contains($v['text'], '2'));

    $v = bk_lte_backup_state(['lte_up' => true, 'lte_connected' => false, 'lte_sim_state' => 'ready', 'lte_conn_code' => 902]);
    check_true('SIM ok, ale modem odpojen = nefunkční', $v['ok'] === false && $v['reason'] === 'not_connected');
    check_true('a stavový kód je v textu', str_contains($v['text'], '902'));

    $v = bk_lte_backup_state(['lte_up' => false, 'lte_connected' => null, 'lte_sim_state' => null]);
    check_true('vypnuté rozhraní = nefunkční', $v['ok'] === false && $v['reason'] === 'interface_down');
}

// --- WAN link verdict (bk_wan_link_state) -------------------------------------
//
// Mirrors wanLinkState() in apps/monitor/src/lib/wan-link.ts (vitest there).
if (function_exists('bk_wan_link_state')) {
    check_true('bez signálu = bez verdiktu', bk_wan_link_state([])['ok'] === null);
    check_true('řetězec "true" není důkaz', bk_wan_link_state(['wan_up' => 'true'])['ok'] === null);
    $v = bk_wan_link_state(['wan_up' => false, 'wan_internet' => true]);
    check_true('vypnuté rozhraní vyhrává nad pingem', $v['ok'] === false && $v['reason'] === 'interface_down' && str_contains((string)$v['text'], 'WAN'));
    $v = bk_wan_link_state(['wan_up' => true, 'wan_internet' => false]);
    check_true('nahoře bez pingu = bez internetu', $v['ok'] === false && $v['reason'] === 'no_internet' && str_contains((string)$v['text'], 'ping'));
    check_true('starý agent bez wan_internet: rozhraní up = funkční', bk_wan_link_state(['wan_up' => true])['ok'] === true);
    // Agents before 0.1.1 sent false for "no interface called wan at all".
    check_true('starý agent na AP: wan_up=false bez protokolu i pingu = bez verdiktu', bk_wan_link_state(['wan_up' => false])['ok'] === null);
    check_true('ale s protokolem je vypnuté rozhraní opravdu výpadek', bk_wan_link_state(['wan_up' => false, 'wan_proto' => 'dhcp'])['ok'] === false);
    check_true('a s pingem false taky (bez protokolu)', bk_wan_link_state(['wan_up' => false, 'wan_internet' => false])['ok'] === false);
    check_true('oba signály dobré = funkční', bk_wan_link_state(['wan_up' => true, 'wan_internet' => true])['ok'] === true);
}

// --- PagerDuty event per status (bk_pagerduty_action) ---------------------------
//
// Everything but "down" used to be sent as "resolve": a silent agent, a lost
// WAN or a lost LTE backup CLOSED the open incident instead of paging.
if (function_exists('bk_pagerduty_action')) {
    foreach (['down', 'agent_offline', 'wan_lost', 'lte_backup_lost'] as $s) {
        check("{$s} pageuje", bk_pagerduty_action($s), 'trigger');
    }
    foreach (['up', 'wan_restored', 'lte_backup_restored'] as $s) {
        check("{$s} uzavírá incident", bk_pagerduty_action($s), 'resolve');
    }
    foreach (['vps_warning', 'latency_degraded', 'latency_recovered', 'ssl_expiring', 'maintenance', 'config_change'] as $s) {
        check("{$s} do PagerDuty nejde", bk_pagerduty_action($s), null);
    }
}

// --- Outage confirmation (bk_down_is_confirmed) --------------------------------
//
// One failed check is one failed check. With the confirmation at N the verdict
// waits for N in a row - the log records every failure either way.
if (function_exists('bk_down_is_confirmed')) {
    check_true('při jedné požadované se hlásí hned', bk_down_is_confirmed(1, 1));
    check_false('při třech nestačí jedna', bk_down_is_confirmed(1, 3));
    check_false('ani dvě', bk_down_is_confirmed(2, 3));
    check_true('třetí v řadě potvrzuje', bk_down_is_confirmed(3, 3));
    check_true('a víc než dost taky', bk_down_is_confirmed(9, 3));
    // Nesmyslné nastavení nesmí výpadky umlčet úplně.
    check_true('nula požadovaných se chová jako jedna', bk_down_is_confirmed(1, 0));
    check_true('záporná taky', bk_down_is_confirmed(1, -5));
}

// --- Alert colour class (bk_alert_color_class) ---------------------------------
//
// The Discord embed used to be green for "up" and red for everything else, so
// a recovered WAN link and an expiring certificate looked like outages.
if (function_exists('bk_alert_color_class')) {
    foreach (['up', 'wan_restored', 'lte_backup_restored', 'latency_recovered'] as $s) {
        check("{$s} je zelené", bk_alert_color_class($s), 'good');
    }
    foreach (['maintenance', 'vps_warning', 'latency_degraded', 'ssl_expiring'] as $s) {
        check("{$s} je oranžové", bk_alert_color_class($s), 'warn');
    }
    foreach (['down', 'agent_offline', 'wan_lost', 'lte_backup_lost'] as $s) {
        check("{$s} je červené", bk_alert_color_class($s), 'bad');
    }
}

// --- Notify on a status change? (bk_should_notify_status_change) ----------------
//
// The end of a planned window is not a recovery - but only when nothing was
// open. An outage that started BEFORE the window still has to be closed and
// announced, otherwise the incident stays open and escalates while the
// service runs.
if (function_exists('bk_should_notify_status_change')) {
    check_false('konec ohlášené údržby mlčí', bk_should_notify_status_change('maintenance', 'up', false));
    check_true('ale s otevřeným incidentem je to skutečné obnovení', bk_should_notify_status_change('maintenance', 'up', true));
    check_true('výpadek během údržby se hlásí', bk_should_notify_status_change('maintenance', 'down', false));
    check_true('běžný výpadek se hlásí', bk_should_notify_status_change('up', 'down', false));
    check_true('běžné obnovení se hlásí', bk_should_notify_status_change('down', 'up', false));
    check_true('začátek údržby se hlásí', bk_should_notify_status_change('up', 'maintenance', false));
}

// --- SSL expiry warning latch (bk_ssl_alert_due) -------------------------------
if (function_exists('bk_ssl_alert_due')) {
    $now = 1_800_000_000;
    check_true('uvnitř okna a nikdy nevarováno = varovat', bk_ssl_alert_due(10, 14, 0, $now));
    check_false('mimo okno = nevarovat', bk_ssl_alert_due(20, 14, 0, $now));
    check_true('práh z nastavení, ne pevných 14 dní', bk_ssl_alert_due(20, 30, 0, $now));
    check_false('varováno před hodinou = mlčet', bk_ssl_alert_due(10, 14, $now - 3600, $now));
    check_true('varováno před 25 hodinami = znovu', bk_ssl_alert_due(10, 14, $now - 90000, $now));
    check_false('prošlý certifikát řeší výpadek, ne varování', bk_ssl_alert_due(-1, 14, 0, $now));
    check_true('poslední den je ještě varování', bk_ssl_alert_due(0, 14, 0, $now));
}

// --- Expired maintenance window (bk_maintenance_window_expired) -----------------
if (function_exists('bk_maintenance_window_expired')) {
    $now = strtotime('2026-09-07 12:00:00');
    check_true('okno s koncem v minulosti vypršelo', bk_maintenance_window_expired(['maintenance' => 1, 'maintenance_end' => '2026-09-07 11:00:00'], $now));
    check_false('okno s koncem v budoucnu běží', bk_maintenance_window_expired(['maintenance' => 1, 'maintenance_end' => '2026-09-07 13:00:00'], $now));
    check_false('bez konce = ruční údržba, nikdy nevyprší', bk_maintenance_window_expired(['maintenance' => 1, 'maintenance_end' => null], $now));
    check_false('vypnutá údržba nemá co vypršet', bk_maintenance_window_expired(['maintenance' => 0, 'maintenance_end' => '2026-09-07 11:00:00'], $now));
    check_false('řetězcová "1" bez konce', bk_maintenance_window_expired(['maintenance' => '1', 'maintenance_end' => ''], $now));
    check_true('řetězcová "1" s prošlým koncem', bk_maintenance_window_expired(['maintenance' => '1', 'maintenance_end' => '2026-09-06 11:00:00'], $now));
    check_false('nečitelný konec nevypršel', bk_maintenance_window_expired(['maintenance' => 1, 'maintenance_end' => 'nesmysl'], $now));
}

// --- Periods on the backup link (bk_pair_link_periods) -------------------------
if (function_exists('bk_pair_link_periods')) {
    $w = 1000; $now = 5000;
    $r = bk_pair_link_periods([], $w, $now);
    check('bez událostí = žádná období', count($r['periods']), 0);
    check('a nula sekund', $r['seconds'], 0);
    $r = bk_pair_link_periods([['wan_lost', 2000], ['wan_restored', 2600]], $w, $now);
    check('výpadek a obnovení = jedno období', count($r['periods']), 1);
    check('délka je rozdíl časů', $r['seconds'], 600);
    check_true('uzavřené období není otevřené', $r['open'] === false);
    $r = bk_pair_link_periods([['wan_lost', 4000]], $w, $now);
    check_true('výpadek bez obnovení běží do teď', $r['open'] === true && $r['periods'][0]['to'] === null);
    check('a počítá se do teď', $r['seconds'], 1000);
    $r = bk_pair_link_periods([['wan_restored', 1500]], $w, $now);
    check('obnovení bez výpadku = výpadek před oknem, počítá se od začátku okna', $r['seconds'], 500);
    check_true('a začátek je neznámý (null), ne vymyšlený', $r['periods'][0]['from'] === null);
    $r = bk_pair_link_periods([['wan_lost', 500], ['wan_restored', 1200]], $w, $now);
    check('období přesahující začátek okna se ořízne na okno', $r['seconds'], 200);
    $r = bk_pair_link_periods([['wan_lost', 2000], ['wan_lost', 2100], ['wan_restored', 2500]], $w, $now);
    check('dvojí výpadek za sebou se nepočítá dvakrát', count($r['periods']), 1);
    check('a měří se od prvního', $r['seconds'], 500);

    // An outage that started before the window and never ended: its events are
    // outside the query, so without the seed the answer was "nikdy".
    $r = bk_pair_link_periods([], $w, $now, 200);
    check_true('výpadek z doby před oknem stále běží', $r['open'] === true);
    check('a počítá se od začátku okna do teď', $r['seconds'], $now - $w);
    check('začátek zůstává skutečný, ne oříznutý', $r['periods'][0]['from'], 200);
    $r = bk_pair_link_periods([['wan_restored', 1400]], $w, $now, 200);
    check('obnovení uvnitř okna ho ukončí', $r['seconds'], 400);
    check_true('a už není otevřený', $r['open'] === false);
    $r = bk_pair_link_periods([['wan_restored', 1400], ['wan_lost', 4000]], $w, $now, 200);
    check('po obnovení může začít další výpadek', count($r['periods']), 2);
    check('a sečtou se obě období', $r['seconds'], 400 + ($now - 4000));
}

// --- Typed agent input (bk_agent_str / bk_agent_bool) --------------------------
if (function_exists('bk_smart_is_missing')) {
    foreach (['', 'N/A', 'N/A (smartctl chybí)', 'N/A (SMART nedostupné pro /dev/sda)', 'N/A (Storage modul neni k dispozici)', 'n/a'] as $missing) {
        check_true("SMART '{$missing}' = neměřeno, ne zdravý disk", bk_smart_is_missing($missing) === true);
    }
    check_true('SMART OK je verdikt', bk_smart_is_missing('OK') === false);
    check_true('SMART WARNING je verdikt', bk_smart_is_missing('WARNING (Disk /dev/sdb selhal v SMART)') === false);
}
if (function_exists('bk_agent_str')) {
    check('řetězec se ořízne a zkrátí', bk_agent_str(['h' => '  abc  '], 'h', 2), 'ab');
    check_true('pole místo řetězce = null (ne TypeError v trim)', bk_agent_str(['h' => ['x']], 'h') === null);
    check_true('"null" od starého agenta = null', bk_agent_str(['h' => 'null'], 'h') === null);
    check_true('bool = null', bk_agent_str(['h' => true], 'h') === null);
    check('číslo se vrátí jako řetězec', bk_agent_str(['h' => 12], 'h'), '12');
}
if (function_exists('bk_agent_bool')) {
    check_true('true/false projdou', bk_agent_bool(['a' => true], 'a') === true && bk_agent_bool(['a' => false], 'a') === false);
    check_true('1/0 a "1"/"0"', bk_agent_bool(['a' => 1], 'a') === true && bk_agent_bool(['a' => '0'], 'a') === false);
    check_true('"false" je false, ne true jako u (bool)', bk_agent_bool(['a' => 'false'], 'a') === false);
    check_true('pole, null a "maybe" = null', bk_agent_bool(['a' => []], 'a') === null && bk_agent_bool(['a' => null], 'a') === null && bk_agent_bool(['a' => 'maybe'], 'a') === null);
}

// --- Web check verdict (bk_http_verdict) -----------------------------------
// The body keyword used to be measured and ignored: a suspended-hosting page
// served with 200 stayed green, although the form promises an outage.
if (function_exists('bk_http_verdict')) {
    check('200 bez klíčového slova je up', bk_http_verdict(200, 'cokoli', null)['status'], 'up');
    check('200 s nalezeným textem je up', bk_http_verdict(200, '<h1>Blood Kings</h1>', 'Blood Kings')['status'], 'up');
    $v = bk_http_verdict(200, '<h1>Account suspended</h1>', 'Blood Kings');
    check('200 bez očekávaného textu je výpadek', $v['status'], 'down');
    check_true('chyba jmenuje hledaný text', str_contains((string)$v['error'], 'Blood Kings'));
    check('prázdné tělo s klíčovým slovem je výpadek', bk_http_verdict(200, '', 'Blood Kings')['status'], 'down');
    check('nepřečtené tělo s klíčovým slovem je výpadek', bk_http_verdict(200, false, 'Blood Kings')['status'], 'down');
    check('text se porovnává přesně, jak ho admin zadal', bk_http_verdict(200, 'blood kings', 'Blood Kings')['status'], 'down');
    check('prázdné nebo mezerové slovo se nekontroluje', bk_http_verdict(200, 'x', '  ')['status'], 'up');
    check('3xx s nalezeným textem je up', bk_http_verdict(301, 'Blood Kings', 'Blood Kings')['status'], 'up');
    $v = bk_http_verdict(503, 'Blood Kings', 'Blood Kings');
    check('503 je výpadek i s nalezeným textem', $v['status'], 'down');
    check('503 hlásí HTTP kód, ne chybějící text', $v['error'], 'HTTP status kód: 503');
    check('žádná odpověď je výpadek', bk_http_verdict(0, '', null)['status'], 'down');
}

// --- Agent backup verdict (bk_agent_backup_says_up) ------------------------
// The fallback turned real outages green: a web with the agent listing 443,
// agent data 50 minutes old, and any monitored process vouching for TeamSpeak.
if (function_exists('bk_agent_backup_says_up')) {
    $now = 1800000000;
    $all_ports = ['agent_last_seen' => $now - 60, 'ports' => [80, 443, 9987, 10011, 25565], 'missing_processes' => []];
    check_false('web agent nikdy nezachrání (nginx na 443 s mrtvým PHP je výpadek)',
        bk_agent_backup_says_up('web', $all_ports, ['target' => 'https://x.cz'], $now));
    check_false('port monitor agent nezachrání', bk_agent_backup_says_up('port', $all_ports, ['port' => 80], $now));
    check_true('TeamSpeak s voice portem v čerstvém hlášení je up',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 60, 'ports' => [9987]], ['target' => 'ts.x.cz', 'port' => 10011], $now));
    check_true('voice port se bere z cíle host:port',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 60, 'ports' => [9988]], ['target' => 'ts.x.cz:9988'], $now));
    check_true('Docker agent hlásí po 300 s, hlášení staré 6 minut ještě platí',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 360, 'ports' => [9987]], ['target' => 'ts.x.cz'], $now));
    check_false('hlášení staré 20 minut nic nedokazuje',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 1200, 'ports' => [9987]], ['target' => 'ts.x.cz'], $now));
    check_false('agent, který nikdy nehlásil, nic nedokazuje',
        bk_agent_backup_says_up('minecraft', ['ports' => [25565]], ['port' => 25565], $now));
    check_true('Minecraft s portem v čerstvém hlášení je up',
        bk_agent_backup_says_up('minecraft', ['agent_last_seen' => $now - 30, 'ports' => ['25565']], ['port' => 25565], $now));
    check_false('cizí port Minecraft nezachrání',
        bk_agent_backup_says_up('minecraft', ['agent_last_seen' => $now - 30, 'ports' => [80]], ['port' => 25565], $now));
    check_true('běžící ts3server v čerstvém hlášení je up',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 60, 'ports' => [], 'missing_processes' => []], ['target' => 'ts.x.cz', 'monitored_processes' => 'ts3server, nginx'], $now));
    check_false('chybějící ts3server není up',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 60, 'ports' => [], 'missing_processes' => ['ts3server']], ['target' => 'ts.x.cz', 'monitored_processes' => 'ts3server'], $now));
    check_false('běžící nginx neručí za TeamSpeak',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 60, 'ports' => [], 'missing_processes' => []], ['target' => 'ts.x.cz', 'monitored_processes' => 'nginx'], $now));
    check_false('rozbitá data agenta nic nedokazují',
        bk_agent_backup_says_up('teamspeak', ['agent_last_seen' => $now - 60, 'ports' => 'x'], ['target' => 'ts.x.cz'], $now));
}

// --- Per-monitor access (bk_viewer, bk_monitor_scope_sql) --------------------
// Monitors belong to users; an admin sees all, a user the assigned ones, an
// anonymous visitor none through the app. The scope helper must never widen.
if (function_exists('bk_viewer')) {
    $saved_session = $_SESSION ?? null;
    $_SESSION = [];
    check('anonym není přihlášený ani admin', bk_viewer(), ['logged_in' => false, 'is_admin' => false, 'user_id' => 0]);
    $_SESSION = ['admin_logged_in' => true, 'admin_role' => 'user', 'admin_id' => 7];
    check('uživatel je přihlášený, ne admin', bk_viewer(), ['logged_in' => true, 'is_admin' => false, 'user_id' => 7]);
    $_SESSION = ['admin_logged_in' => true, 'admin_role' => 'admin', 'admin_id' => 1];
    check_true('admin je admin', bk_viewer()['is_admin']);
    $_SESSION = ['admin_role' => 'admin', 'admin_id' => 1];
    check_false('role bez přihlášení nic neznamená', bk_viewer()['is_admin']);
    $_SESSION = $saved_session ?? [];
}
if (function_exists('bk_monitor_scope_sql')) {
    check('admin nemá omezení', bk_monitor_scope_sql(null, 'm.id'), ['1=1', []]);
    check('nic přiřazeného = nic, ne všechno', bk_monitor_scope_sql([], 'm.id'), ['1=0', []]);
    check('přiřazené monitory jako vázané parametry', bk_monitor_scope_sql([3, '5', 3], 'm.id'), ['m.id IN (?,?)', [3, 5]]);
    $threw = false;
    try {
        bk_monitor_scope_sql([1], 'm.id) OR (1=1');
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    check_true('podvržený název sloupce se odmítne', $threw);
}

if (function_exists('bk_public_monitor_details')) {
    $pub = bk_public_monitor_details([
        'version' => '3.13.7', 'players_online' => 4, 'cpanel_stats' => ['disk' => ['percent' => 40]],
        'top_cpu_processes' => [['name' => 'mysqld', 'cpu' => 80]], 'processes' => ['sshd', 'nginx'],
        'ts3_process' => ['pid' => 42], 'missing_processes' => ['kresd'], 'discovered_services' => [['name' => 'x']],
        'wan_l3_device' => 'pppoe-wan', 'lte_device' => 'wwan0', 'interfaces' => [['iface' => 'eth0']],
        'net_lte' => 120, 'wan_ipv4' => '203.0.113.9', 'ports' => [22, 443], 'agent_key' => 'x',
        'containers_future_key' => ['db'],
    ]);
    check('veřejné detaily nesou jen hodnoty karty', array_keys($pub), ['version', 'players_online', 'cpanel_stats']);
    check('prázdné detaily zůstanou prázdné', bk_public_monitor_details([]), []);
}

if (function_exists('bk_public_reason')) {
    check('HTTP chyba webu zůstane veřejná', bk_public_reason('HTTP status kód: 503', 'web'), 'HTTP status kód: 503');
    check('chybějící proces agenta se veřejně nejmenuje', bk_public_reason('Chybí běžící proces: nginx', 'vps'), null);
    check('agent-side kontrola nejmenuje proces', bk_public_reason("Agent nehlásí proces 'kresd' ani otevřený port 53.", 'agent_service'), null);
    check('zmínka o procesu u jiného typu se také skryje', bk_public_reason('ts3server restartován (PID 1 -> 2)', 'teamspeak'), null);
    check('prázdný důvod zůstane prázdný', bk_public_reason(null, 'vps'), null);
    check('DNS chyba neprozradí jméno hostitele', bk_public_reason('cURL chyba: Could not resolve host: interni.example', 'web'), 'Adresu se nepodařilo přeložit (DNS)');
    check('neúspěšné spojení neprozradí hostitele ani port', bk_public_reason("cURL chyba: Failed to connect to 10.0.0.5 port 8443 after 3 ms: Couldn't connect to server", 'web'), 'Spojení selhalo');
    check('vypršený čas se pozná', bk_public_reason('cURL chyba: Operation timed out after 10001 milliseconds with 0 bytes received', 'web'), 'Vypršel časový limit spojení');
    check('hledaný text stránky se neprozradí', bk_public_reason('Stránka odpověděla HTTP 200, ale neobsahuje očekávaný text „tajne-slovo“', 'web'), 'Stránka odpověděla HTTP 200, ale neobsahuje očekávaný obsah');
    check('zavřený port nenese číslo portu', bk_public_reason('Port 2222 je zavřený nebo nedostupný: Connection refused (111)', 'port'), 'Port je zavřený nebo nedostupný');
    check('chyba TS3 neprozradí IP hostingu', bk_public_reason('TS3 Query port (10011) nedostupný: Connection timed out (110). Tip: Ujistěte se, že váš VPS neblokuje IP adresu webhostingu (203.0.113.7) ve svém firewallu.', 'teamspeak'), 'TeamSpeak ServerQuery neodpovídá');
    check('pevná hláška Minecraftu zůstane', bk_public_reason('Minecraft server je podle API vypnutý.', 'minecraft'), 'Minecraft server je podle API vypnutý.');
    check('neznámý text se veřejně neřekne', bk_public_reason('Cíl 10.0.0.1 vrátil něco nečekaného', 'web'), null);
}

if (function_exists('bk_public_incident_update')) {
    check('automatický důvod výpadku zůstane interní', bk_public_incident_update('Automaticky detekován výpadek. Důvod: Chybí běžící proces: nginx'), 'Automaticky detekován výpadek.');
    check('převzetí nejmenuje operátora', bk_public_incident_update('Incident převzal: pepe'), 'Incident převzat.');
    check('poznámka ztratí jméno autora', bk_public_incident_update('[pepe] Vyměňujeme disk'), 'Vyměňujeme disk');
    check('ruční zpráva bez jména zůstane celá', bk_public_incident_update('Pracujeme na opravě'), 'Pracujeme na opravě');
    check('zpráva jen se jménem je prázdná', bk_public_incident_update('[pepe] '), null);
}

if (function_exists('bk_wifi_band_totals')) {
    $wb = bk_wifi_band_totals([
        ['band' => '2.4GHz', 'clients' => 3, 'clients_6ghz_capable' => 1, 'clients_caps_known' => 3],
        ['band' => '2.4GHz', 'clients' => '2', 'clients_6ghz_capable' => '0', 'clients_caps_known' => '1'],
        ['band' => '5GHz', 'clients' => 4],
        ['band' => '6GHz', 'clients' => 1, 'clients_6ghz_capable' => 1, 'clients_caps_known' => 1],
        ['band' => '60GHz', 'clients' => 9],
        'not a radio',
    ]);
    check('pásma se sčítají po rádiích', [$wb['wifi_clients_24g'], $wb['wifi_clients_5g'], $wb['wifi_clients_6g']], [5, 4, 1]);
    check('podpora 6E na 2.4 GHz se sečte i z čísel v textu', [$wb['wifi_6e_capable_24g'], $wb['wifi_6e_known_24g']], [1, 4]);
    check('rádio bez údaje o podpoře nechá pásmo neznámé, ne nulové', [$wb['wifi_6e_capable_5g'], $wb['wifi_6e_known_5g']], [null, null]);
    $wb_bad = bk_wifi_band_totals([['band' => '2.4GHz', 'clients' => -1, 'clients_6ghz_capable' => 5, 'clients_caps_known' => 2]]);
    check('záporný počet ani víc schopných než známých se neuloží', [$wb_bad['wifi_clients_24g'], $wb_bad['wifi_6e_capable_24g'], $wb_bad['wifi_6e_known_24g']], [null, null, null]);
    check('bez rádií je všechno neznámé', array_values(array_unique(array_values(bk_wifi_band_totals(null)), SORT_REGULAR)), [null]);
    check('pravdivostní hodnota ani desetinné číslo nejsou počet', [bk_wifi_count(true), bk_wifi_count(2.5), bk_wifi_count('7')], [null, null, 7]);
    check('počet nad 4096 je chyba parseru, ne dav', [bk_wifi_count(4096), bk_wifi_count(4097), bk_wifi_count('99999')], [4096, null, null]);

    // Agent 0.1.7: the air per band, over access-point radios only.
    $ap = fn (array $r) => $r + ['mode' => 'ap', 'band' => '5GHz'];
    $air = bk_wifi_band_totals([
        $ap(['radio' => 'a', 'noise' => -95, 'busy_pct' => 12.5, 'busy_other_pct' => 2.0]),
        $ap(['radio' => 'b', 'noise' => -80, 'busy_pct' => 60.0, 'busy_other_pct' => null]),
        $ap(['radio' => 'c', 'band' => '2.4GHz', 'noise' => null, 'busy_pct' => null]),
    ]);
    check('šum je nejhorší rádio pásma, ne průměr', [$air['wifi_noise_5g'], $air['wifi_noise_24g'], $air['wifi_noise_6g']], [-80.0, null, null]);
    check('cizí provoz se bere z nejvytíženějšího rádia', [$air['wifi_busy_5g'], $air['wifi_busy_other_5g'], $air['wifi_busy_24g']], [60.0, null, null]);
    $vaps = bk_wifi_band_totals([
        $ap(['radio' => 'phy0-ap0', 'phy' => 'phy0', 'frequency_mhz' => 5180, 'noise' => -92, 'busy_pct' => 30.0, 'busy_other_pct' => null, 'clients_weak' => 1]),
        $ap(['radio' => 'phy0-ap1', 'phy' => 'phy0', 'frequency_mhz' => 5180, 'noise' => -92, 'busy_pct' => 30.0, 'busy_other_pct' => 11.0, 'clients_weak' => 2]),
    ]);
    check('dvě VAP jednoho rádia jsou jedno měření', [$vaps['wifi_noise_5g'], $vaps['wifi_busy_5g'], $vaps['wifi_busy_other_5g'], $vaps['wifi_weak_clients']], [-92.0, 30.0, 11.0, 3]);

    $unserved = fn (array $radios) => bk_wifi_band_totals($radios)['wifi_6e_unserved'];
    $sta = fn (int $clients, ?int $known, ?int $capable, string $band = '5GHz') => ['mode' => 'ap', 'band' => $band, 'clients' => $clients, 'clients_caps_known' => $known, 'clients_6ghz_capable' => $capable];
    check('wifi_6e_unserved: 2 schopní klienti bez 6GHz rádia → 1, s 6GHz rádiem → 0, bez hostapd → null',
        [$unserved([$sta(4, 4, 2)]), $unserved([$sta(4, 4, 2), $sta(1, null, null, '6GHz')]), $unserved([$sta(4, null, null)])], [1, 0, null]);
    check('wifi_6e_unserved: 1 schopný + 2 neznámí → null, ne 0', $unserved([$sta(5, 3, 1)]), null);
    check('1 schopný + 0 neznámých → 0', $unserved([$sta(3, 3, 1)]), 0);
    check('2 schopní + 3 neznámí → 1', $unserved([$sta(3, 2, 1, '2.4GHz'), $sta(4, 2, 1)]), 1);
    check('rádio bez počtu klientů → null, pokud nejsou 2 schopní', [
        $unserved([$sta(3, 3, 1), ['mode' => 'ap', 'band' => '2.4GHz', 'clients' => null, 'clients_caps_known' => 2, 'clients_6ghz_capable' => 0]]),
        $unserved([$sta(3, 3, 1), ['mode' => 'ap', 'band' => '2.4GHz', 'clients' => null, 'clients_caps_known' => 2, 'clients_6ghz_capable' => 1]]),
    ], [null, 1]);
    $uplink = bk_wifi_band_totals([
        ['mode' => 'client', 'band' => '5GHz', 'clients' => 1, 'noise' => -60, 'busy_pct' => 90.0, 'clients_weak' => 1, 'clients_caps_known' => 1, 'clients_6ghz_capable' => 1, 'clients_akm_known' => 1, 'clients_wpa2' => 1],
        ['mode' => 'mesh', 'band' => '6GHz', 'clients' => 2, 'noise' => -70],
        $ap(['noise' => -90, 'clients' => 2, 'clients_weak' => 0, 'clients_caps_known' => 2, 'clients_6ghz_capable' => 2, 'clients_akm_known' => 2, 'clients_wpa2' => 0]),
    ]);
    // The mesh link on 6 GHz is not a 6 GHz network for the clients: still unserved.
    check('client/mesh rozhraní se nepočítá', [$uplink['wifi_noise_5g'], $uplink['wifi_noise_6g'], $uplink['wifi_busy_5g'], $uplink['wifi_weak_clients'], $uplink['wifi_wpa2_clients'], $uplink['wifi_6e_unserved']], [-90.0, null, null, 0, 0, 1]);
    $silent = bk_wifi_band_totals([$ap(['noise' => null, 'busy_pct' => null, 'clients_weak' => null, 'clients_wpa2' => null, 'clients_akm_known' => null]), ['radio' => 'phy3-ap0', 'band' => null, 'mode' => null]]);
    check('samé null zůstane null', array_values(array_unique(array_values($silent), SORT_REGULAR)), [null]);
    $wpa = bk_wifi_band_totals([
        $ap(['clients_akm_known' => 3, 'clients_wpa2' => 2]), $ap(['clients_akm_known' => null, 'clients_wpa2' => 5]),
        $ap(['band' => '2.4GHz', 'clients_opclass_known' => 4, 'clients_5ghz_capable' => 3]), $ap(['clients_opclass_known' => 2, 'clients_5ghz_capable' => 2]),
        $ap(['band' => '2.4GHz', 'clients_opclass_known' => null, 'clients_5ghz_capable' => 7]),
    ]);
    check('WPA2 klienti jen z rádií, která zabezpečení znají; podpora 5 GHz jen z 2.4GHz rádií', [$wpa['wifi_wpa2_clients'], $wpa['wifi_5g_capable_24g']], [2, 3]);
    check('starší agent bez klíče mode je přístupový bod', bk_wifi_band_totals([['band' => '5GHz', 'channel' => 36, 'noise' => -91, 'busy_pct' => 8]])['wifi_noise_5g'], -91.0);
}

if (function_exists('bk_exclude_ids_sql')) {
    check('bez archivovaných monitorů se nic nefiltruje', bk_exclude_ids_sql([], 'id'), ['1=1', []]);
    check('archivované id se vynechá, jednou a jen kladné', bk_exclude_ids_sql([5, '7', 5, 0, -2], 'm.id'), ['m.id NOT IN (?,?)', [5, 7]]);
    $bk_bad_column = false;
    try {
        bk_exclude_ids_sql([1], 'id; DROP TABLE monitors');
    } catch (InvalidArgumentException $e) {
        $bk_bad_column = true;
    }
    check_true('neplatný název sloupce se odmítne', $bk_bad_column);
    check('router má agenta routeru', bk_agent_label('openwrt'), 'Agent routeru');
    check('server má agenta serveru', bk_agent_label('vps'), 'Agent serveru');
    check_false('rada u routeru nemluví o VPS', str_contains(bk_agent_silence_hint('OpenWrt'), 'VPS'));
}

// --- bk_sanitize_wifi_radios / bk_wifi_radio_profile -------------------------
// The radio list used to be stored exactly as sent. Rules read it now, so a
// value out of range must become "not measured" - never the edge of the range.
if (function_exists('bk_sanitize_wifi_radios')) {
    $band_of = fn ($freq) => bk_sanitize_wifi_radios([['radio' => 'w0', 'frequency_mhz' => $freq, 'band' => '5GHz']])[0]['band'];
    check('pásmo se odvodí z frekvence (2412, 5180, 5925, 5955, 7125, 0)',
        array_map($band_of, [2412, 5180, 5925, 5955, 7125, 0]), ['2.4GHz', '5GHz', '6GHz', '6GHz', '6GHz', null]);
    $old_agent = bk_sanitize_wifi_radios([
        ['radio' => 'wlan0', 'band' => '2.4GHz', 'channel' => null, 'clients' => null],
        ['radio' => 'wlan1', 'band' => '5GHz', 'channel' => 36, 'clients' => 3],
    ]);
    check('vypnuté rádio agenta 0.1.6 nemá pásmo 2.4GHz', [$old_agent[0]['band'], $old_agent[1]['band']], [null, '5GHz']);
    $edge = bk_sanitize_wifi_radios([['radio' => 'w0', 'frequency_mhz' => 5180, 'busy_pct' => 101, 'noise' => 0, 'tx_power' => 41,
        'signal_min' => 0, 'channel' => 0, 'bitrate_tx_avg_mbps' => 0, 'clients' => 5000]])[0];
    check('hodnota mimo rozsah je null, ne okraj rozsahu (busy 101, noise 0)',
        [$edge['busy_pct'], $edge['noise'], $edge['tx_power'], $edge['signal_min'], $edge['channel'], $edge['bitrate_tx_avg_mbps'], $edge['clients']],
        [null, null, null, null, null, null, null]);
    $inside = bk_sanitize_wifi_radios([['radio' => 'w0', 'frequency_mhz' => 5180, 'busy_pct' => 100, 'noise' => -20, 'tx_power' => 0, 'signal_min' => -1]])[0];
    check('okraj rozsahu sám je platné měření', [$inside['busy_pct'], $inside['noise'], $inside['tx_power'], $inside['signal_min']], [100.0, -20, 0, -1]);
    $leaky = bk_sanitize_wifi_radios([['radio' => 'w0', 'bssid' => 'x', 'mac' => 'y', 'hwmodes' => ['ax'], 'ssid' => "Do\x07ma"]])[0];
    check('neznámý klíč (bssid, hwmodes) se zahodí', array_values(array_intersect(array_keys($leaky), ['bssid', 'mac', 'hwmodes'])), []);
    check('řídicí znaky z SSID zmizí', $leaky['ssid'], 'Doma');
    $over = bk_sanitize_wifi_radios([['radio' => 'w0', 'clients_6ghz_capable' => 3, 'clients_caps_known' => 2,
        'clients_5ghz_capable' => 1, 'clients_opclass_known' => 4]])[0];
    check('capable > known vynuluje obojí', [$over['clients_6ghz_capable'], $over['clients_caps_known'], $over['clients_5ghz_capable'], $over['clients_opclass_known']], [null, null, 1, 4]);
    $akm = bk_sanitize_wifi_radios([['radio' => 'w0', 'clients_akm_known' => 3, 'clients_wpa2' => 2, 'clients_wpa3' => 2, 'clients_8021x' => 0]])[0];
    check('víc klíčů než stanic se známým zabezpečením vynuluje všechny čtyři', [$akm['clients_akm_known'], $akm['clients_wpa2'], $akm['clients_wpa3'], $akm['clients_8021x']], [null, null, null, null]);
    $gen = ['legacy' => 0, 'wifi4' => 1, 'wifi5' => 1, 'wifi6' => 2, 'wifi7' => 1];
    $ubus = bk_sanitize_wifi_radios([['radio' => 'w0', 'clients_gen' => ['source' => 'ubus'] + $gen], ['radio' => 'w1', 'clients_gen' => ['source' => 'hostapd_cli'] + $gen],
        ['radio' => 'w2', 'clients_gen' => ['source' => 'guess'] + $gen]]);
    check('zdroj ubus vynutí wifi7 = null', [$ubus[0]['clients_gen']['wifi7'], $ubus[0]['clients_gen']['wifi6'], $ubus[1]['clients_gen']['wifi7'], $ubus[2]['clients_gen']], [null, 2, 1, null]);
    $many = [];
    for ($i = 0; $i < 17; $i++) {
        $many[] = ['radio' => "wlan{$i}"];
    }
    check('17. rádio se zahodí', count(bk_sanitize_wifi_radios($many)), 16);
    check('rádio se jménem mimo vzor se zahodí', array_column(bk_sanitize_wifi_radios([['radio' => 'wlan0; rm'], ['radio' => 'phy0-ap0'], 'x', ['ssid' => 'bez jména']]), 'radio'), ['phy0-ap0']);
    check('co není seznam, není hlášení rádií', [bk_sanitize_wifi_radios(null), bk_sanitize_wifi_radios('x'), bk_sanitize_wifi_radios([])], [null, null, []]);
    // The app tells an older agent (no key) from "the router could not tell"
    // (key = null): the sanitizer must not invent the key.
    $sent = bk_sanitize_wifi_radios([['radio' => 'wlan0', 'band' => '5GHz', 'channel' => 36, 'clients' => 2, 'clients_gen' => null]])[0];
    check('klíč, který agent neposlal, nevznikne; poslaný null zůstane', [array_key_exists('clients_akm_known', $sent), array_key_exists('clients_gen', $sent), $sent['clients_gen']], [false, true, null]);
    $enc = bk_sanitize_wifi_radios([['radio' => 'w0', 'encryption' => 'wpa_wpa2', 'mode' => 'ap', 'busy_state' => 'measured', 'weakest_gen' => 4, 'phy_has_6ghz' => false],
        ['radio' => 'w1', 'encryption' => 'wpa9', 'mode' => 'Master', 'busy_state' => 'ok', 'weakest_gen' => 3, 'phy_has_6ghz' => 0]]);
    check('sanitizer hodnotu wpa_wpa2 propustí, neznámou hodnotu šifrování dá null',
        [$enc[0]['encryption'], $enc[1]['encryption'], $enc[1]['mode'], $enc[1]['busy_state'], $enc[1]['weakest_gen'], $enc[0]['phy_has_6ghz'], $enc[1]['phy_has_6ghz']],
        ['wpa_wpa2', null, null, null, null, false, null]);
    $foreign = bk_sanitize_wifi_radios([['radio' => 'w0', 'busy_pct' => 10, 'busy_other_pct' => 12], ['radio' => 'w1', 'busy_pct' => null, 'busy_other_pct' => 5],
        ['radio' => 'w2', 'busy_pct' => 10.5, 'busy_other_pct' => 10.5]]);
    check('cizí provoz větší než celé vytížení, nebo bez něj, není měření', [$foreign[0]['busy_other_pct'], $foreign[1]['busy_other_pct'], $foreign[2]['busy_other_pct']], [null, null, 10.5]);
    $modes = bk_sanitize_wifi_radios([['radio' => 'w0', 'htmode' => 'HE80', 'htmodes_supported' => ['HT20', 'HT20', 'HT40+', 'bogus', 7, 'HE80'], 'phy' => 'phy0'],
        ['radio' => 'w1', 'htmode' => 'AX80', 'htmodes_supported' => 'HE80', 'phy' => 'radio0']]);
    check('režimy mimo vzor se ze seznamu vyřadí a opakované sloučí', [$modes[0]['htmodes_supported'], $modes[1]['htmode'], $modes[1]['htmodes_supported'], $modes[1]['phy']], [['HT20', 'HE80'], null, null, null]);

    $profile = fn (array $r) => array_values(bk_wifi_radio_profile($r));
    check('HE80 → 6/80', array_slice($profile(['htmode' => 'HE80']), 0, 2), [6, 80]);
    check('VHT80+80 → 5/160', array_slice($profile(['htmode' => 'VHT80+80']), 0, 2), [5, 160]);
    check('NOHT → 0/20', array_slice($profile(['htmode' => 'NOHT']), 0, 2), [0, 20]);
    check('neznámý režim nemá generaci ani šířku', array_slice($profile(['htmode' => null]), 0, 2), [null, null]);
    $phy_modes = ['HT20', 'HT40', 'VHT20', 'VHT40', 'VHT80', 'VHT160', 'HE20', 'HE40', 'HE80', 'HE160'];
    check('podporovaná generace se omezí pásmem (2,4 GHz bez VHT, 6 GHz bez HT/VHT)', [
        array_slice($profile(['band' => '2.4GHz', 'htmodes_supported' => ['HT20', 'HT40', 'VHT20', 'VHT80']]), 2),
        array_slice($profile(['band' => '2.4GHz', 'htmodes_supported' => $phy_modes]), 2),
        array_slice($profile(['band' => '5GHz', 'htmodes_supported' => $phy_modes]), 2),
        array_slice($profile(['band' => '6GHz', 'htmodes_supported' => ['HT20', 'VHT80']]), 2),
        array_slice($profile(['band' => '6GHz', 'htmodes_supported' => ['HT40', 'HE160', 'EHT320']]), 2),
    ], [[4, 40], [6, 40], [6, 160], [null, null], [7, 320]]);
    check('bez pásma není podporovaná generace', array_slice($profile(['htmode' => 'HE80', 'htmodes_supported' => $phy_modes]), 2), [null, null]);
}

// --- bk_sanitize_storage_disks / bk_disk_key / bk_sanitize_agent_tools --------
if (function_exists('bk_sanitize_storage_disks')) {
    $sd_now = 1789553300;
    $sd_smart = ['state' => 'ok', 'checked_at' => $sd_now - 97, 'temperature_c' => 67, 'power_on_hours' => 24750, 'passed' => true,
        'serial_number' => 'S3CR3T', 'wwn' => '5 0026b7 000000000', 'model' => 'KINGSTON SUV500MS120G'];
    $sd_disk = ['name' => 'sda', 'transport' => 'sata', 'port' => 'ata1', 'model' => 'KINGSTON SUV500M', 'size_bytes' => 120034123776,
        'rotational' => false, 'removable' => false, 'serial_number' => 'S3CR3T', 'wwn' => 'x', 'eui64' => 'y', 'cid' => 'z',
        'partitions' => [['name' => 'sda1', 'size_bytes' => 120033075200, 'mount' => '/', 'fstype' => 'btrfs', 'used_pct' => 19, 'uuid' => 'u']],
        'emmc' => null, 'smart' => $sd_smart];
    $sd = bk_sanitize_storage_disks([$sd_disk], $sd_now);
    check('klíče serial_number, wwn, eui64, cid se zahodí na disku i ve smart',
        [preg_match('/S3CR3T|0026b7|serial|wwn|eui|"cid"|uuid/i', (string)json_encode($sd)), $sd[0]['smart']['model'], $sd[0]['partitions'][0]['mount']],
        [0, 'KINGSTON SUV500MS120G', '/']);
    $sd_temp = fn ($t) => bk_sanitize_storage_disks([['smart' => ['temperature_c' => $t] + $sd_smart] + $sd_disk], $sd_now)[0]['smart']['temperature_c'];
    check('teplota 0 a 300 → null', [$sd_temp(0), $sd_temp(300), $sd_temp(-5), $sd_temp(67), $sd_temp(125)], [null, null, null, 67, 125]);
    $sd_emmc = bk_sanitize_storage_disks([['name' => 'mmcblk0', 'transport' => 'emmc', 'emmc' => ['life_a' => 12, 'life_b' => 2, 'pre_eol' => 0, 'cid' => 'abc']]], $sd_now)[0];
    check('eMMC life 12 → null', $sd_emmc['emmc'], ['life_a' => null, 'life_b' => 2, 'pre_eol' => null]);
    $sd_state = fn ($smart) => bk_sanitize_storage_disks([['smart' => $smart] + $sd_disk], $sd_now)[0]['smart']['state'];
    check('stav mimo výčet → error', [$sd_state(['state' => 'fine']), $sd_state(null), $sd_state(['state' => 'standby'])], ['error', 'error', 'standby']);
    $sd_many = [];
    for ($i = 0; $i < 9; $i++) {
        $sd_many[] = ['name' => 'sd' . chr(97 + $i), 'partitions' => array_map(fn ($n) => ['name' => 'sd' . chr(97 + $i) . $n], range(1, 17))];
    }
    $sd_capped = bk_sanitize_storage_disks($sd_many, $sd_now);
    check('9. disk a 17. oddíl se zahodí', [count($sd_capped), count($sd_capped[0]['partitions'])], [8, 16]);
    check('loop, mtdblock a zram nejsou disky', array_column(bk_sanitize_storage_disks([['name' => 'loop0'], ['name' => 'mtdblock0'], ['name' => 'zram0'], ['name' => 'mmcblk0boot0'], ['name' => 'nvme0n1'], ['name' => 'sda']], $sd_now), 'name'), ['nvme0n1', 'sda']);
    $sd_old = bk_sanitize_storage_disks([['smart' => ['checked_at' => $sd_now - 401 * 86400] + $sd_smart] + $sd_disk, ['name' => 'sdb', 'smart' => ['checked_at' => $sd_now + 2 * 86400] + $sd_smart]], $sd_now);
    check('čas čtení SMART starší 400 dní nebo z budoucnosti není čas čtení', [$sd_old[0]['smart']['checked_at'], $sd_old[1]['smart']['checked_at'], $sd[0]['smart']['checked_at']], [null, null, $sd_now - 97]);
    check('neznámý transport je other, neplatný port a přípojný bod null', (function () use ($sd_disk, $sd_now) {
        $d = bk_sanitize_storage_disks([['transport' => 'scsi', 'port' => 'ata 1;', 'partitions' => [['name' => 'sda1', 'mount' => 'srv', 'fstype' => 'BTRFS!', 'used_pct' => 101]]] + $sd_disk], $sd_now)[0];
        return [$d['transport'], $d['port'], $d['partitions'][0]['mount'], $d['partitions'][0]['fstype'], $d['partitions'][0]['used_pct']];
    })(), ['other', null, null, null, null]);
    check('bajty zapsané za život disku se nezaokrouhlí ani nad 2^53', bk_sanitize_storage_disks([['smart' => ['written_bytes' => 9007199254740993] + $sd_smart] + $sd_disk], $sd_now)[0]['smart']['written_bytes'], 9007199254740993);
    check('co není seznam, není hlášení disků; prázdný seznam = žádný disk', [bk_sanitize_storage_disks(null, $sd_now), bk_sanitize_storage_disks([], $sd_now)], [null, []]);

    $sd_pending = bk_sanitize_storage_disks([['smart' => ['state' => 'pending']] + $sd_disk], $sd_now)[0];
    check('stejný před i po prvním čtení SMART', [$sd_pending['key'], strlen($sd[0]['key'])], [$sd[0]['key'], 16]);
    $sd_key = fn (array $change) => bk_sanitize_storage_disks([$change + $sd_disk], $sd_now)[0]['key'];
    check_true('jiný port nebo velikost → jiný klíč', count(array_unique([$sd[0]['key'], $sd_key(['port' => 'ata2']), $sd_key(['size_bytes' => 240057409536]), $sd_key(['transport' => 'usb'])])) === 4);

    $tools = bk_sanitize_agent_tools(['smartctl' => true, 'smart_drivedb' => 1, 'hostapd_cli' => 'yes', 'iw' => false, 'ethtool' => false,
        'pkg_manager' => 'dpkg', 'smart_probe_age_s' => 97, 'smart_probe_running_s' => -1, 'shell' => '/bin/ash']);
    check('nástroje routeru: jen přísné true/false, neznámý správce balíčků a záporný čas jsou null',
        $tools, ['smartctl' => true, 'smart_drivedb' => null, 'hostapd_cli' => null, 'iw' => false, 'librespeed_cli' => null, 'ethtool' => false, 'tc' => null,
            'pkg_manager' => null, 'smart_probe_age_s' => 97, 'smart_probe_running_s' => null]);
    check('starší agent nástroje neposílá: null, ne samá false', bk_sanitize_agent_tools(null), null);
}

// --- bk_counter_step / bk_wan_counter_steps: WAN error and drop counters -------
// Stored as STEPS (new events per report). A reset is unknowable, not "the
// value since boot": that number is link bring-up noise and would feed a rule.
if (function_exists('bk_counter_step')) {
    check('přírůstek je rozdíl proti minulému hlášení', [bk_counter_step(7, 12, true), bk_counter_step(7, 7, true)], [5, 0]);
    check('null na kterékoli straně zůstává null', [bk_counter_step(null, 5, true), bk_counter_step(5, null, true), bk_counter_step(null, null, true)], [null, null, null]);
    check('restart routeru: přírůstek je neznámý, ne hodnota od startu', bk_counter_step(120, 4, true), null);

    $cnt = fn (int $errors, int $drops = 0, ?int $flaps = 0, ?int $ct = 0) => ['wan_rx_errors' => $errors, 'wan_tx_errors' => 0,
        'wan_rx_dropped' => $drops, 'wan_tx_dropped' => 0, 'conntrack_drop' => $ct, 'wan_carrier_down_count' => $flaps, 'wan_rx_ring_drops' => null];
    $first = bk_wan_counter_steps(null, $cnt(7, 120, 3, 1), 'eth2', 5000, null);
    check('první hlášení = žádný přírůstek', array_values(array_unique(array_values($first['steps']), SORT_REGULAR)), [null]);
    $second = bk_wan_counter_steps($first['state'], $cnt(9, 125, 4, 1), 'eth2', 5060, null);
    check('druhé hlášení: chyby, zahození, výpadky linky a odmítnutá spojení jsou přírůstky', $second['steps'],
        ['wan_errors' => 2, 'wan_drops' => 5, 'wan_ring_drops' => null, 'wan_link_flaps' => 1, 'conntrack_drops' => 0]);
    $rebooted = bk_wan_counter_steps($second['state'], $cnt(40, 300, 9, 5), 'eth2', 90, null);
    check('restart, po kterém čítač stihl přerůst starou hodnotu, se pozná podle uptime', array_values(array_unique(array_values($rebooted['steps']), SORT_REGULAR)), [null]);
    check('po restartu se počítá od nové hodnoty', bk_wan_counter_steps($rebooted['state'], $cnt(41, 300, 9, 5), 'eth2', 150, null)['steps']['wan_errors'], 1);
    $moved = bk_wan_counter_steps($second['state'], $cnt(10, 126, 4, 2), 'eth0', 5120, null);
    check('jiné WAN zařízení = žádný přírůstek', array_values(array_unique(array_values($moved['steps']), SORT_REGULAR)), [null]);
    check('bez známého uptime nejde restart vyloučit: žádný přírůstek', bk_wan_counter_steps($second['state'], $cnt(10), 'eth2', null, null)['steps']['wan_errors'], null);
    $one_side = bk_wan_counter_steps($second['state'], ['wan_tx_errors' => null] + $cnt(10, 126, 4, 1), 'eth2', 5120, null);
    check('součet směrů je známý, jen když jsou známé oba', [$one_side['steps']['wan_errors'], $one_side['steps']['wan_drops']], [null, 1]);
    $gap = bk_wan_counter_steps($second['state'], array_fill_keys(array_keys($cnt(0)), null), 'eth2', 5120, null);
    $after_gap = bk_wan_counter_steps($gap['state'], $cnt(12, 125, 4, 1), 'eth2', 5180, null);
    check('minuta bez čtení čítačů nic neztratí: další přírůstek ji překlene', [$gap['steps']['wan_errors'], $after_gap['steps']['wan_errors']], [null, 3]);
    check('agent, který čítače nečte, žádný stav neukládá', bk_wan_counter_steps(null, [], null, 300, null)['state'], null);

    // Ring drops are read once an hour (ethtool -S): a step only with a new reading.
    $ring = fn ($prev, ?int $drops, int $uptime, int $checked) => bk_wan_counter_steps($prev, ['wan_rx_ring_drops' => $drops] + $cnt(0), 'eth2', $uptime, $checked);
    $r1 = $ring(null, 100, 1000, 1789550000);
    $r2 = $ring($r1['state'], 100, 1060, 1789550000);
    $r3 = $ring($r2['state'], 140, 4600, 1789553600);
    $r4 = $ring($r3['state'], null, 8200, 1789557200);
    $r5 = $ring($r4['state'], 150, 11800, 1789560800);
    check('zahozené rámce portu: přírůstek jen v hlášení s novým čtením, jinak null', [$r1['steps']['wan_ring_drops'], $r2['steps']['wan_ring_drops'], $r3['steps']['wan_ring_drops']], [null, null, 40]);
    check('čtení bez ethtool řetěz přeruší, nula z něj nevznikne', [$r4['steps']['wan_ring_drops'], $r5['steps']['wan_ring_drops']], [null, null]);
    // A reboot empties the router's hourly cache, so the first report after it
    // carries a fresh reading: no step against the value from before the reboot.
    $r_boot = $ring($r3['state'], 5, 60, 1789553700);
    check('restart mezi dvěma čteními: první čtení po něm nemá přírůstek, další ano',
        [$r_boot['steps']['wan_ring_drops'], $ring($r_boot['state'], 900, 3660, 1789557300)['steps']['wan_ring_drops']], [null, 895]);
}

// --- bk_sanitize_wan_path ------------------------------------------------------
if (function_exists('bk_sanitize_wan_path')) {
    $wp = bk_sanitize_wan_path(['checked_at' => 1789553100, 'flow_offloading' => true, 'flow_offloading_hw' => 'false', 'flowtable_active' => 1,
        'packet_steering' => 'unset', 'packet_steering_active' => true, 'wan_rps_mask' => '3', 'wan_threaded_napi' => false, 'wan_rx_ring_drops' => -1,
        'sqm' => [['iface' => 'eth2', 'download_kbps' => 0, 'upload_kbps' => 900000, 'egress_dropped' => null, 'ingress_dropped' => 12, 'script' => 'x'], ['iface' => 'eth2; reboot'], 'x'],
        'lan_port_max_mbit' => 1000, 'lan_port_cap_mbit' => 0, 'lan_conduits' => [['dev' => 'eth1', 'mbit' => 1000, 'mac' => 'x'], ['dev' => null]], 'gateway' => 'x']);
    check('cesta WAN: přísné true/false, hodnota mimo rozsah null, neznámé klíče pryč',
        [$wp['flow_offloading'], $wp['flow_offloading_hw'], $wp['flowtable_active'], $wp['wan_rx_ring_drops'], $wp['lan_port_cap_mbit'], array_key_exists('gateway', $wp)],
        [true, null, null, null, null, false]);
    check('nulová rychlost fronty SQM znamená neomezený směr, ne 0 kbit/s', $wp['sqm'],
        [['iface' => 'eth2', 'download_kbps' => null, 'upload_kbps' => 900000, 'egress_dropped' => null, 'ingress_dropped' => 12]]);
    check('vedení LAN portů nese jen zařízení a rychlost', $wp['lan_conduits'], [['dev' => 'eth1', 'mbit' => 1000]]);
    $wp_null = bk_sanitize_wan_path(['sqm' => null, 'lan_conduits' => 'x']);
    check('sqm null (nešlo ověřit) se nezmění na prázdný seznam (ověřeno, žádná fronta)', [$wp_null['sqm'], bk_sanitize_wan_path(['sqm' => []])['sqm'], $wp_null['lan_conduits']], [null, [], null]);
    check('starší agent cestu WAN neposílá', bk_sanitize_wan_path(null), null);
}

// --- bk_sanitize_lan_ports -----------------------------------------------------
if (function_exists('bk_sanitize_lan_ports')) {
    $lp = bk_sanitize_lan_ports(['bridge' => 'br-lan', 'clients_total' => 6, 'mac' => 'x',
        'ports' => [
            ['name' => 'lan0', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full', 'max_mbit' => 1000, 'partner_max_mbit' => 1000, 'clients' => 3, 'mac' => 'x'],
            ['name' => 'lan1', 'link' => true, 'speed_mbit' => 100, 'duplex' => 'full', 'max_mbit' => 1000, 'partner_max_mbit' => 100, 'clients' => 0],
            // A port with no carrier that still carries a rate: the agent does
            // not send this, a broken one might - and a rate on a dead port
            // reads like a measurement of a live one.
            ['name' => 'lan2', 'link' => false, 'speed_mbit' => 1000, 'duplex' => 'full', 'max_mbit' => 1000, 'partner_max_mbit' => 1000, 'clients' => 0],
            ['name' => 'lan3', 'link' => null, 'speed_mbit' => 'fast', 'duplex' => 'plný', 'max_mbit' => 0, 'partner_max_mbit' => 2000000, 'clients' => 9999],
            ['name' => 'lan4; reboot', 'link' => true],
            ['link' => true],
            'lan5',
        ],
        'conduits' => [['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full'], ['dev' => null], 'x']]);
    check('LAN porty: jen známé klíče, jméno musí vypadat jako síťové zařízení',
        [array_column($lp['ports'], 'name'), array_key_exists('mac', $lp), array_key_exists('mac', $lp['ports'][0])],
        [['lan0', 'lan1', 'lan2', 'lan3'], false, false]);
    check('LAN port bez linku nemá vyjednanou rychlost, duplex ani protistranu',
        [$lp['ports'][2]['speed_mbit'], $lp['ports'][2]['duplex'], $lp['ports'][2]['partner_max_mbit']], [null, null, null]);
    check('LAN port: nečitelná hodnota je null, ne dohad; nula klientů je měření',
        [$lp['ports'][3]['speed_mbit'], $lp['ports'][3]['duplex'], $lp['ports'][3]['max_mbit'],
            $lp['ports'][3]['partner_max_mbit'], $lp['ports'][3]['clients'], $lp['ports'][1]['clients']],
        [null, null, null, null, null, 0]);
    check('LAN porty: vedení ke CPU nese jen zařízení, link, rychlost a duplex',
        [$lp['conduits'], $lp['bridge'], $lp['clients_total']],
        [[['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full']], 'br-lan', 6]);
    check('starší agent přepínač neposílá a router, který se nemohl podívat, hlásí null',
        [bk_sanitize_lan_ports(null), bk_sanitize_lan_ports(['bridge' => 'br-lan', 'ports' => []]),
            bk_sanitize_lan_ports(['bridge' => 'br-lan', 'conduits' => [], 'clients_total' => 0])],
        [null, null, null]);
    // 16 ports is past any board the agent runs on; a longer list is malformed.
    $lp_many = bk_sanitize_lan_ports(['ports' => array_map(fn ($i) => ['name' => "lan{$i}", 'link' => false, 'clients' => 0], range(0, 39))]);
    check('LAN porty: seznam má strop a přepočítaný součet se nepodsouvá',
        [count($lp_many['ports']), $lp_many['clients_total'], $lp_many['bridge'], $lp_many['conduits']], [16, null, null, []]);
}

// --- bk_details_fit / bk_ingest_issue_add: nothing is dropped silently ----------
if (function_exists('bk_details_fit')) {
    $fit_small = bk_details_fit(['cpu' => 12.5, 'wifi_radios' => [['radio' => 'phy0-ap0']], 'details_dropped' => ['storage_disks']], 60000, ['fs_alerts']);
    check('hlášení, které se vejde, seznam zahozených klíčů vynuluje', [$fit_small['dropped'], json_decode($fit_small['json'], true)['details_dropped']], [[], []]);
    $fit_big = ['cpu' => 12.5, 'storage_disks' => array_fill(0, 300, str_repeat('d', 100)), 'wifi_radios' => array_fill(0, 200, str_repeat('r', 100)),
        'interfaces' => array_fill(0, 50, 'eth0'), 'fs_alerts' => array_fill(0, 400, str_repeat('f', 100)), 'ingest_issues' => [['type' => 'x']], 'note' => str_repeat('n', 500)];
    $fit = bk_details_fit($fit_big, 60000, ['fs_alerts', 'ingest_issues']);
    $fit_kept = json_decode($fit['json'], true);
    // fs_alerts is the BIGGEST list here and survives anyway: shedding it would
    // hide exactly the alert the blob exists for. Unprotected lists go by size.
    check('při přetečení jdou největší nechráněné seznamy první a jejich jména se zapíšou', [$fit['dropped'], $fit_kept['details_dropped']], [['storage_disks', 'wifi_radios'], ['storage_disks', 'wifi_radios']]);
    check_true('při přetečení zůstane fs_alerts i ingest_issues', isset($fit_kept['fs_alerts'], $fit_kept['ingest_issues'], $fit_kept['cpu']) && strlen($fit['json']) <= 60000);
    $fit_str = bk_details_fit(['cpu' => 1, 'blob' => str_repeat('x', 900), 'list' => array_fill(0, 5, 'abcdefgh')], 300, []);
    check('když seznamy nestačí, jdou i dlouhé řetězce a skaláry zůstanou', [$fit_str['dropped'], json_decode($fit_str['json'], true)['cpu']], [['list', 'blob'], 1]);

    $issues = [];
    foreach (range(1, 7) as $n) {
        $issues = bk_ingest_issue_add($issues, 'passthrough_key_limit', "key_{$n}");
    }
    check('problémů příjmu je nejvýš pět a nesou typ, klíč a velikost', [count($issues), $issues[0], bk_ingest_issue_add([], 'passthrough_too_large', 'big_list', 9000)[0]],
        [5, ['type' => 'passthrough_key_limit', 'key' => 'key_1', 'bytes' => null], ['type' => 'passthrough_too_large', 'key' => 'big_list', 'bytes' => 9000]]);
}

// --- bk_metric_column_map: the 24 router metrics of the release ---------------
// A metric missing from the map is stored every minute and read by nobody;
// a metric whose key is longer than metrics_daily.metric_key is rolled up into
// a TRUNCATED key and its chart stays empty for 30 days before anyone notices.
if (function_exists('bk_metric_column_map')) {
    $reg = bk_metric_column_map();
    $reg_new = [
        'wifi_noise_24g', 'wifi_noise_5g', 'wifi_noise_6g',
        'wifi_busy_24g', 'wifi_busy_5g', 'wifi_busy_6g',
        'wifi_busy_other_24g', 'wifi_busy_other_5g', 'wifi_busy_other_6g',
        'wifi_weak_clients', 'wifi_wpa2_clients', 'wifi_6e_unserved', 'wifi_5g_capable_24g',
        'cpu_core_max', 'cpu_core_max_softirq', 'wan_rx_mbps', 'wan_tx_mbps',
        'wan_errors', 'wan_drops', 'wan_ring_drops', 'wan_link_flaps', 'conntrack_drops',
        'agent_run_ms', 'clock_skew_s',
    ];
    check('registr metrik: všech 24 metrik routeru v něm je', [count($reg_new), array_values(array_diff($reg_new, array_keys($reg)))], [24, []]);
    $reg_schema = (string)file_get_contents(__DIR__ . '/../schema.sql');
    preg_match('/CREATE TABLE IF NOT EXISTS `vps_metrics`\s*\((.*?)\n\)\s*ENGINE/s', $reg_schema, $reg_m);
    preg_match_all('/^\s*`(\w+)`\s/m', $reg_m[1] ?? '', $reg_cols);
    $reg_bad_col = [];
    $reg_bad_only = [];
    $reg_long = [];
    foreach ($reg_new as $reg_key) {
        $reg_def = $reg[$reg_key] ?? [];
        if (($reg_def['col'] ?? null) !== $reg_key || !in_array($reg_key, $reg_cols[1], true)) {
            $reg_bad_col[] = $reg_key;
        }
        if (($reg_def['only'] ?? null) !== ['openwrt']) {
            $reg_bad_only[] = $reg_key;
        }
        // metrics_daily.metric_key is VARCHAR(30).
        if (strlen($reg_key) > 30) {
            $reg_long[] = $reg_key;
        }
    }
    check('registr metrik: klíč = sloupec vps_metrics a sloupec existuje ve schématu', $reg_bad_col, []);
    check('registr metrik: nové metriky posílá jen OpenWrt', $reg_bad_only, []);
    check('registr metrik: klíč se vejde do metrics_daily.metric_key', $reg_long, []);
    $reg_steps = [];
    foreach ($reg as $reg_key => $reg_def) {
        if (($reg_def['step'] ?? false) === true) {
            $reg_steps[] = $reg_key;
            // A step is already a difference; reading it as a cumulative
            // counter as well would subtract the same minute twice.
            check_true("registr metrik: krokovou metriku {$reg_key} nikdo nečte jako čítač", ($reg_def['counter'] ?? false) === false);
        }
    }
    check('registr metrik: krokových metrik je přesně pět', $reg_steps,
        ['wan_errors', 'wan_drops', 'wan_ring_drops', 'wan_link_flaps', 'conntrack_drops']);
}

// --- Fixture: the user's Turris Omnia (tests/fixtures/omnia_router.php) -------
// Every "must fire / must not fire on this router" test of the router release
// stands on this file, so the file itself is held to the rules of the release:
// masked, honest about what was not measured, and in step with the schema.
{
    $omnia = require __DIR__ . '/fixtures/omnia_router.php';
    $omnia_src = (string)file_get_contents(__DIR__ . '/fixtures/omnia_router.php');
    $omnia_p = $omnia['payload'];
    $schema_sql = (string)file_get_contents(__DIR__ . '/../schema.sql');
    $table_columns = function (string $table) use ($schema_sql): array {
        preg_match('/CREATE TABLE IF NOT EXISTS `' . $table . '`\s*\((.*?)\n\)\s*ENGINE/s', $schema_sql, $m);
        preg_match_all('/^\s*`(\w+)`\s/m', $m[1] ?? '', $cols);
        return $cols[1];
    };
    $all_keys = function (array $a) use (&$all_keys): array {
        $keys = [];
        foreach ($a as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            if (is_array($v)) {
                $keys = array_merge($keys, $all_keys($v));
            }
        }
        return $keys;
    };

    check('Omnia: A8 fixture nenese MAC, BSSID ani IP adresu', preg_match('/(?:[0-9a-f]{2}[:-]){5}[0-9a-f]{2}|\b\d{1,3}(?:\.\d{1,3}){3}\b/i', $omnia_src . json_encode($omnia)), 0);
    check('Omnia: A8 žádný klíč nepojmenovává sériové číslo, WWN ani MAC', array_values(preg_grep('/serial|wwn|eui|guid|cid|bssid|^mac/i', $all_keys($omnia))), []);
    check('Omnia: A1 jediný disk je sda a o eMMC není nic nulou', [array_column($omnia_p['storage_disks'], 'name'), $omnia_p['storage_disks'][0]['emmc']], [['sda'], null]);
    check('Omnia: A5 teplota je 67 °C, chybějící atribut 198 zůstává neznámý', [$omnia_p['storage_disks'][0]['smart']['temperature_c'], $omnia_p['storage_disks'][0]['smart']['offline_uncorrectable']], [67, null]);
    check('Omnia: bez ethtool a tc jsou zahozené rámce neznámé, ne nula', [$omnia_p['wan_path']['wan_rx_ring_drops'], $omnia_p['agent_tools']['ethtool'], $omnia_p['agent_tools']['tc']], [null, false, false]);
    check('Omnia: stav portu je z fyzického eth2, 174 zahozených rámců VLAN mu nepatří', [$omnia_p['wan_link_dev'], $omnia_p['wan_link_mbit'], $omnia_p['wan_rx_dropped']], ['eth2', 2500, 0]);
    check('Omnia: LAN porty sdílejí jedno gigabitové vedení eth1', [$omnia_p['wan_path']['lan_port_cap_mbit'], $omnia_p['wan_path']['lan_conduits']], [1000, [['dev' => 'eth1', 'mbit' => 1000]]]);
    $omnia_lan = bk_sanitize_lan_ports($omnia_p['lan_ports']);
    check('Omnia: přepínač má pět portů, dva bez kabelu, a ty nehlásí rychlost ani duplex',
        [array_column($omnia_lan['ports'], 'name'), array_column($omnia_lan['ports'], 'link'),
            array_column($omnia_lan['ports'], 'speed_mbit')],
        [['lan0', 'lan1', 'lan2', 'lan3', 'lan4'], [true, true, false, false, true], [1000, 100, null, null, 1000]]);
    check('Omnia: lan1 jede 100 kvůli protistraně, ne kvůli závadě - port sám umí 1000',
        [$omnia_lan['ports'][1]['partner_max_mbit'], $omnia_lan['ports'][1]['max_mbit'], $omnia_lan['ports'][0]['partner_max_mbit']],
        [100, 1000, 1000]);
    check('Omnia: všech pět portů visí na jednom gigabitovém vedení eth1',
        [$omnia_lan['conduits'], $omnia_lan['bridge']],
        [[['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000, 'duplex' => 'full']], 'br-lan']);
    check('Omnia: nesečtené počty zařízení zůstávají neznámé, ne nula',
        [$omnia_lan['ports'][0]['clients'], $omnia_lan['clients_total'], $omnia_lan['ports'][2]['clients']], [null, null, 0]);
    check('Omnia: vypnutá sekce SQM není fronta a měření rychlosti žádné není', [$omnia_p['wan_path']['sqm'], $omnia_p['speedtests']], [[], []]);
    check('Omnia: co router nezměřil, je neznámé (špička jádra, zaplnění conntrack, délka běhu)', [$omnia_p['cpu_core_max_pct'], $omnia_p['conntrack_pct'], $omnia_p['agent_run_ms']], [null, null, null]);
    check_false('Omnia: tarif není součástí hlášení, zadává ho až majitel', array_key_exists('wan_plan_down_mbit', $omnia_p));

    // The week and the disk day must fit the tables they are seeded into.
    $metric_map = bk_metric_column_map();
    $vps_columns = $table_columns('vps_metrics');
    $week_without_column = [];
    foreach (array_keys($omnia['week']['metrics']) as $metric_key) {
        $column = $metric_map[$metric_key]['col'] ?? $metric_key;
        if (!in_array($column, $vps_columns, true)) {
            $week_without_column[] = $metric_key;
        }
    }
    check('Omnia: každá týdenní metrika má sloupec ve vps_metrics', $week_without_column, []);
    check('Omnia: klíče metrik se vejdou do metrics_daily.metric_key', array_values(array_filter(array_keys($omnia['week']['metrics']), fn($k) => strlen($k) > 30)), []);
    check('Omnia: denní řádek disku zná jen sloupce storage_disk_daily', array_values(array_diff(array_keys($omnia['disk_day']), $table_columns('storage_disk_daily'))), []);
    check('Omnia: průměrná teplota disku za den je 67 °C', $omnia['disk_day']['temp_sum'] / $omnia['disk_day']['temp_n'], 67);
    check('Omnia: klíč disku je 16 hex znaků z transportu, portu, modelu a velikosti', preg_match('/^[0-9a-f]{16}$/', $omnia['disk_key']), 1);

    // The second radio (REAL_FACTS „Second radio phy3-ap0"): USB adapter,
    // 2,4 GHz, ovladač bez šumu a s prázdným survey. Fixture musí nést přesně
    // to, co agent z reálných záznamů posílá - jinak se serverová pravidla učí
    // na rádiu, které neexistuje.
    check('Omnia: druhé rádio má stejná pole jako první', array_diff(array_keys($omnia_p['wifi_radios'][0]), array_keys($omnia['second_radio'])), []);
    $omnia_r2 = $omnia['second_radio'];
    check('Omnia: druhé rádio je 2,4GHz AP na kanálu 5 s šesti klienty',
        [$omnia_r2['band'], $omnia_r2['channel'], $omnia_r2['frequency_mhz'], $omnia_r2['htmode'], $omnia_r2['clients']],
        ['2.4GHz', 5, 2432, 'HT20', 6]);
    check('Omnia: ovladač druhého rádia nehlásí šum ani obsazenost - null, ne nula',
        [$omnia_r2['noise'], $omnia_r2['snr_min'], $omnia_r2['busy_pct'], $omnia_r2['busy_other_pct'], $omnia_r2['busy_state']],
        [null, null, null, null, 'unsupported']);
    // CORE D11 („[refines REAL_FACTS]"): AP bez HE nikdy [HE] nenastaví, takže
    // stanice bez něj nic nedokazuje - známých je 0, ne 6.
    check('Omnia: na AP bez HE je schopnost 6 GHz neznámá (0 z 0), ne změřená nula ze šesti',
        [$omnia_r2['clients_caps_known'], $omnia_r2['clients_6ghz_capable'], $omnia_r2['clients_opclass_known']],
        [0, 0, 0]);
    check('Omnia: bez AKM řádku je stanice bez [MFP] WPA2 - 3 ze 6, zbytek neznámý',
        [$omnia_r2['clients_akm_known'], $omnia_r2['clients_wpa2'], $omnia_r2['clients_wpa3'], $omnia_r2['clients_8021x']],
        [3, 3, 0, 0]);
    check_true('Omnia: síla signálu druhého rádia se nezachytila, takže zůstává neznámá',
        $omnia_r2['signal_median'] === null && $omnia_r2['signal_min'] === null
        && $omnia_r2['clients_weak'] === null && $omnia_r2['bitrate_tx_avg_mbps'] === null);
    // Obě rádia dohromady: pásma se nesmějí slít a nezměřená hodnota nesmí
    // druhé pásmo vynulovat.
    if (function_exists('bk_wifi_band_totals')) {
        $omnia_tot = bk_wifi_band_totals(bk_sanitize_wifi_radios(
            array_merge($omnia_p['wifi_radios'], [$omnia['second_radio']])));
        check('Omnia: dvě rádia, dvě pásma - klienti se nesčítají přes pásma',
            [$omnia_tot['wifi_clients_5g'], $omnia_tot['wifi_clients_24g'], $omnia_tot['wifi_clients_6g']],
            [4, 6, null]);
        check('Omnia: pásmo bez měřitelného šumu a obsazenosti zůstává neznámé',
            [$omnia_tot['wifi_noise_24g'], $omnia_tot['wifi_busy_24g'], $omnia_tot['wifi_busy_other_24g']],
            [null, null, null]);
        check('Omnia: 5GHz pásmo si své hodnoty podrží i vedle nezměřeného rádia',
            [$omnia_tot['wifi_noise_5g'], $omnia_tot['wifi_busy_5g'], $omnia_tot['wifi_busy_other_5g']],
            [-92.0, 3.5, 1.2]);
        check('Omnia: druhé rádio přidá tři WPA2 stanice, 6 GHz ale nikdo neobslouží',
            [$omnia_tot['wifi_wpa2_clients'], $omnia_tot['wifi_6e_unserved']], [3, 1]);
    }

    // X9: a top-level key of the report is either stored as a metric or a
    // deliberate exemption of run_agent_metric_lint.php - otherwise the lint
    // turns red the day the agent really sends it.
    preg_match('/\$not_metrics = \[(.*?)\n\];/s', (string)file_get_contents(__DIR__ . '/run_agent_metric_lint.php'), $nm);
    preg_match_all("/'([a-z_0-9]+)'/", (string)preg_replace('#^\s*//.*$#m', '', $nm[1] ?? ''), $nm_keys);
    $stored_by_contract = ['wifi_clients_count', 'wan_link_mbit', 'conntrack_count', 'conntrack_pct', 'cpu_core_max_pct', 'cpu_core_max_softirq_pct', 'wan_rx_mbps', 'wan_tx_mbps', 'agent_run_ms'];
    check('Omnia: každý klíč hlášení je ukládaná metrika, nebo vědomá výjimka lintu', array_values(array_diff(array_keys($omnia_p), $nm_keys[1], $stored_by_contract)), []);
    check('Omnia: ukládané klíče mezi výjimkami lintu nejsou', array_values(array_intersect($stored_by_contract, $nm_keys[1])), []);
}


// --- bk_pagerduty_dedup_key: a disk incident is not an outage -----------------
//
// Everything used to page under one key `bk-monitor-<id>`, so a WAN flap that
// resolved closed the open incident of a failing disk - and the on-call saw
// nothing until the disk died.
if (function_exists('bk_pagerduty_dedup_key')) {
    check('storage_failing pageuje pod vlastním klíčem bk-monitor-6-storage',
        bk_pagerduty_dedup_key(6, 'storage_failing'), 'bk-monitor-6-storage');
    foreach (['wan_restored', 'up', 'lte_backup_restored'] as $s) {
        check("wan_restored, up a lte_backup_restored zavírají jen klíč bk-monitor-6 ({$s})",
            bk_pagerduty_dedup_key(6, $s), 'bk-monitor-6');
    }
    check('storage_recovered a storage_warning do PagerDuty nejdou (recovered)', bk_pagerduty_action('storage_recovered'), null);
    check('storage_recovered a storage_warning do PagerDuty nejdou (warning)', bk_pagerduty_action('storage_warning'), null);
    check('storage_failing pageuje', bk_pagerduty_action('storage_failing'), 'trigger');
    check('down má dál klíč bk-monitor-6', bk_pagerduty_dedup_key(6, 'down'), 'bk-monitor-6');
}

// --- bk_storage_alert_eval: disk rules (CORE 3.5) -------------------------------
//
// The whole point is hysteresis: a disk sits at its limit for months, so an
// alert that fires on every hourly reading is an alert nobody reads.
if (function_exists('bk_storage_alert_eval')) {
    $sd_now = 1758000000;
    $sd_disk = function (array $smart = [], array $over = []): array {
        return array_merge([
            'key' => 'aaaabbbbccccdddd',
            'name' => 'sda',
            'transport' => 'sata',
            'rotational' => false,
            'emmc' => null,
            'smart' => array_merge([
                'state' => 'ok', 'passed' => true, 'checked_at' => 1,
                'model' => 'KINGSTON SUV500MS120G', 'protocol' => 'ATA',
            ], $smart),
        ], $over);
    };
    /** Event types of one evaluation, in order. */
    $sd_types = fn (array $res): array => array_map(fn ($e) => $e['type'], $res['events']);
    $sd_key = 'aaaabbbbccccdddd';

    // Temperature: the user's SSD idles at 67 °C with a lifetime max of 68.
    $sd_a = bk_storage_alert_eval([$sd_disk(['temperature_c' => 67])], [], $sd_now);
    $sd_b = bk_storage_alert_eval([$sd_disk(['temperature_c' => 67])], $sd_a['states'], $sd_now + 3600);
    check('Omnia: 67 °C dvakrát po sobě neupozorní', array_merge($sd_types($sd_a), $sd_types($sd_b)), []);

    $sd_1 = bk_storage_alert_eval([$sd_disk(['temperature_c' => 70])], [], $sd_now);
    $sd_2 = bk_storage_alert_eval([$sd_disk(['temperature_c' => 70])], $sd_1['states'], $sd_now + 3600);
    $sd_3 = bk_storage_alert_eval([$sd_disk(['temperature_c' => 66])], $sd_2['states'], $sd_now + 7200);
    $sd_4 = bk_storage_alert_eval([$sd_disk(['temperature_c' => 65])], $sd_3['states'], $sd_now + 10800);
    check('70 °C jednou nic, podruhé varování, 66 °C drží, 65 °C obnoví',
        [$sd_types($sd_1), $sd_types($sd_2), $sd_types($sd_3), $sd_types($sd_4)],
        [[], ['disk_temp_critical'], [], ['disk_temp_normal']]);
    check('varování o teplotě má status storage_warning', $sd_2['events'][0]['status'], 'storage_warning');

    check('rotační limit 60, NVMe 80',
        [
            bk_disk_temp_limit(['smart' => ['rotation_rpm' => 5400]]),
            bk_disk_temp_limit(['transport' => 'nvme', 'smart' => []]),
            bk_disk_temp_limit(['rotational' => false, 'smart' => []]),
        ], [60, 80, 70]);

    // SMART counters. A drive bought with three bad blocks must stay quiet.
    $sd_c1 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 3])], [], $sd_now);
    check('první pohled na 3 vadné bloky je tichý', $sd_types($sd_c1), []);
    check('první pohled si uloží základnu', $sd_c1['states'][$sd_key]['base']['badblk'], 3);

    $sd_c2 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 4])], $sd_c1['states'], $sd_now + 3600);
    $sd_c3 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 4])], $sd_c2['states'], $sd_now + 7200);
    check('3 → 4 vadné bloky = varování, stejné čtení znovu nic',
        [$sd_types($sd_c2), $sd_c2['events'][0]['status'], $sd_types($sd_c3)],
        [['disk_errors_growing'], 'storage_warning', []]);

    // The baseline rises only with the alert, so growth during the cooldown is
    // reported in full afterwards - never swallowed.
    $sd_c4 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 6])], $sd_c2['states'], $sd_now + 10800);
    $sd_c5 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 6])], $sd_c4['states'], $sd_now + 3600 + 90000);
    check('růst během 24h odkladu se ohlásí po něm',
        [$sd_types($sd_c4), $sd_types($sd_c5)], [[], ['disk_errors_growing']]);
    check_true('a ohlásí celý růst 4 → 6', str_contains($sd_c5['events'][0]['message'], '4 → 6'));

    $sd_p = bk_storage_alert_eval([$sd_disk(['pending_sectors' => 2])], [], $sd_now);
    check('čekající sektory při prvním pohledu = selhání',
        [$sd_types($sd_p), $sd_p['events'][0]['status']], [['disk_errors_growing'], 'storage_failing']);

    $sd_d1 = bk_storage_alert_eval([$sd_disk(['reallocated_sectors' => 10])], [], $sd_now);
    $sd_d2 = bk_storage_alert_eval([$sd_disk(['reallocated_sectors' => 4])], $sd_d1['states'], $sd_now + 3600);
    check('pokles čítače sníží základnu potichu',
        [$sd_types($sd_d2), $sd_d2['states'][$sd_key]['base']['realloc']], [[], 4]);
}

// --- bk_storage_alert_eval: SMART failure, wear and disk replacement ------------
if (function_exists('bk_storage_alert_eval')) {
    // exit bit 4 = "the disk is failing now"; bit 3 = "it failed in the past".
    $sd_fail = fn (): array => $sd_disk(['passed' => false, 'exit_bits' => 0x10, 'state' => 'failing']);
    $sd_ok = fn (): array => $sd_disk(['passed' => true, 'exit_bits' => 0]);

    $sd_f1 = bk_storage_alert_eval([$sd_fail()], [], $sd_now);
    $sd_f2 = bk_storage_alert_eval([$sd_ok()], $sd_f1['states'], $sd_now + 3600);
    $sd_f3 = bk_storage_alert_eval([$sd_ok()], $sd_f2['states'], $sd_now + 7200);
    $sd_f4 = bk_storage_alert_eval([$sd_ok()], $sd_f3['states'], $sd_now + 10800);
    check('SMART failed jednou, tři čtení passed po sobě → obnoveno, dvě nestačí',
        [$sd_types($sd_f1), $sd_types($sd_f2), $sd_types($sd_f3), $sd_types($sd_f4)],
        [['disk_smart_failed'], [], [], ['disk_smart_ok']]);
    check('obnovení jde jako storage_recovered', $sd_f4['events'][0]['status'], 'storage_recovered');

    // A prefail attribute crossing its threshold back and forth on a warm disk
    // used to send an hourly failing/recovered pair.
    $sd_g1 = bk_storage_alert_eval([$sd_fail()], [], $sd_now);
    $sd_g2 = bk_storage_alert_eval([$sd_ok()], $sd_g1['states'], $sd_now + 3600);
    $sd_g3 = bk_storage_alert_eval([$sd_fail()], $sd_g2['states'], $sd_now + 7200);
    check('failing, ok, failing během 3 hodin = jedno upozornění a žádné obnovení',
        array_merge($sd_types($sd_g1), $sd_types($sd_g2), $sd_types($sd_g3)), ['disk_smart_failed']);

    $sd_h = bk_storage_alert_eval([$sd_fail()], $sd_g3['states'], $sd_now + 12 * 3600);
    check('nové selhání do 24 h od posledního upozornění se neposílá znovu', $sd_types($sd_h), []);
    $sd_h2 = bk_storage_alert_eval([$sd_fail()], $sd_h['states'], $sd_now + 30 * 3600);
    check('po 24 h se selhání připomene', $sd_types($sd_h2), ['disk_smart_failed']);

    $sd_w1 = bk_storage_alert_eval([$sd_disk(['wear_pct' => 89])], [], $sd_now);
    $sd_w2 = bk_storage_alert_eval([$sd_disk(['wear_pct' => 90])], $sd_w1['states'], $sd_now + 3600);
    $sd_w3 = bk_storage_alert_eval([$sd_disk(['wear_pct' => 95])], $sd_w2['states'], $sd_now + 7200);
    check('opotřebení 89 nic, 90 varování, 95 už ne',
        [$sd_types($sd_w1), $sd_types($sd_w2), $sd_types($sd_w3)], [[], ['disk_wear_high'], []]);

    $sd_emmc = fn (int $pre_eol): array => $sd_disk(
        ['state' => 'not_applicable', 'passed' => null, 'checked_at' => null],
        ['name' => 'mmcblk0', 'transport' => 'emmc', 'emmc' => ['life_a' => 2, 'life_b' => 1, 'pre_eol' => $pre_eol]]
    );
    $sd_e2 = bk_storage_alert_eval([$sd_emmc(2)], [], $sd_now);
    $sd_e3 = bk_storage_alert_eval([$sd_emmc(3)], $sd_e2['states'], $sd_now + 3600);
    check('pre-EOL 2 varování, 3 selhání',
        [$sd_types($sd_e2), $sd_e2['events'][0]['status'], $sd_types($sd_e3), $sd_e3['events'][0]['status']],
        [['disk_wear_high'], 'storage_warning', ['disk_emmc_eol'], 'storage_failing']);
    check('eMMC na konci životnosti se nepřipomíná každou hodinu',
        $sd_types(bk_storage_alert_eval([$sd_emmc(3)], $sd_e3['states'], $sd_now + 7200)), []);

    // The disk identity is a hash of transport, port, model and size, so an
    // identical replacement lands on the same row. Its power-on hours give it
    // away, and the old drive's baselines must not judge the new one.
    $sd_r1 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 100, 'power_on_hours' => 24750])], [], $sd_now);
    $sd_r2 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 3, 'power_on_hours' => 10])], $sd_r1['states'], $sd_now + 3600);
    check('výměna disku (hodiny −48) vynuluje základny',
        [$sd_types($sd_r2), $sd_r2['states'][$sd_key]['base']['badblk']], [[], 3]);
    $sd_r3 = bk_storage_alert_eval([$sd_disk(['runtime_bad_blocks' => 100, 'power_on_hours' => 24720])], $sd_r1['states'], $sd_now + 3600);
    check('hodiny o 30 nižší jsou zaokrouhlení firmwaru, ne nový disk',
        $sd_r3['states'][$sd_key]['base']['badblk'], 100);
}

// --- bk_storage_gate_open: the hourly pre-filter of CORE 3.4 step 1 -------------
if (function_exists('bk_storage_gate_open')) {
    $g_now = 1758000000;
    $g_disks = [['key' => 'aaaabbbbccccdddd', 'smart' => ['checked_at' => 500]]];
    $g_old = [['key' => 'aaaabbbbccccdddd', 'smart' => ['checked_at' => 500]]];
    check_false('stejné checked_at nic nevyhodnotí', bk_storage_gate_open($g_now - 60, $g_disks, $g_old, $g_now));
    check_true('novější čtení SMART otevře bránu hned',
        bk_storage_gate_open($g_now - 60, [['key' => 'aaaabbbbccccdddd', 'smart' => ['checked_at' => 900]]], $g_old, $g_now));
    check_true('nový disk otevře bránu hned',
        bk_storage_gate_open($g_now - 60, [['key' => 'ffffeeeeddddcccc', 'smart' => ['checked_at' => 500]]], $g_old, $g_now));
    check_true('bez předchozího vzorku se jede vždy', bk_storage_gate_open(null, $g_disks, $g_old, $g_now));
    check_true('po 55 minutách se jede kvůli zápisům hostitele',
        bk_storage_gate_open($g_now - 3400, $g_disks, $g_old, $g_now));
    // The dropped list is exactly the storage_list_dropped case: without this
    // it would mean one upsert per disk per minute.
    check_false('chybějící starý seznam neznamená, že je každý disk nový',
        bk_storage_gate_open($g_now - 60, $g_disks, null, $g_now));
}

// --- bk_disk_host_write_delta: host writes between two samples ------------------
if (function_exists('bk_disk_host_write_delta')) {
    $hw_now = 1758000000;
    $hw_row = ['last_write_sectors' => 1000, 'last_uptime' => 100000, 'last_sample_ts' => $hw_now - 3600];
    check('první vzorek disku nepřičte nic', bk_disk_host_write_delta(null, 1000, 103600, $hw_now), [null, 0]);
    check('běžný přírůstek 2048 sektorů = 1 MiB',
        bk_disk_host_write_delta($hw_row, 3048, 103600, $hw_now), [1048576, 0]);
    check('restart routeru: počítá se od nuly a den je neúplný',
        bk_disk_host_write_delta($hw_row, 3048, 200, $hw_now), [1560576, 1]);
    check('přetečení čítače bez restartu nepřičte nic a den je neúplný',
        bk_disk_host_write_delta($hw_row, 500, 103600, $hw_now), [null, 1]);
    check('2 GiB/s není zápis routeru',
        bk_disk_host_write_delta($hw_row, 1000 + 20000000000, 103600, $hw_now), [null, 1]);
}

// --- bk_fs_alert_eval: a partition that is filling up (CORE 3.5) ----------------
//
// The root is left to the legacy hdd alert, a squashfs is 100 % full by
// construction, and a partition one point under the limit must not flap daily.
if (function_exists('bk_fs_alert_eval')) {
    $fs_now = 1758000000;
    $fs_one = fn (float $pct, string $mount = '/srv', array $over = []): array => [array_merge(
        ['mount' => $mount, 'device' => '/dev/sda1', 'fstype' => 'btrfs', 'total_kb' => 117217792, 'used_pct' => $pct],
        $over
    )];
    $fs_types = fn (array $res): array => array_map(fn ($e) => $e['type'], $res['events']);

    $fs_1 = bk_fs_alert_eval($fs_one(95.0), [], 90.0, $fs_now);
    $fs_2 = bk_fs_alert_eval($fs_one(95.0), $fs_1['state'], 90.0, $fs_now + 60);
    $fs_3 = bk_fs_alert_eval($fs_one(95.0), $fs_2['state'], 90.0, $fs_now + 120);
    check('dvě hlášení nad limitem',
        [$fs_types($fs_1), $fs_types($fs_2), $fs_types($fs_3)], [[], ['fs_full'], []]);
    check('zaplněný oddíl jde jako storage_warning', $fs_2['events'][0]['status'], 'storage_warning');

    $fs_hold = bk_fs_alert_eval($fs_one(86.0), $fs_2['state'], 90.0, $fs_now + 180);
    $fs_free = bk_fs_alert_eval($fs_one(84.0), $fs_hold['state'], 90.0, $fs_now + 240);
    check('uvolnění o 5 bodů',
        [$fs_types($fs_hold), $fs_types($fs_free), $fs_free['events'][0]['status']],
        [[], ['fs_freed'], 'storage_recovered']);

    // The same reset the hdd alert does at agent_api.php:458 - an admin who
    // lowers the limit must be able to be warned again.
    $fs_thr = bk_fs_alert_eval($fs_one(95.0), $fs_2['state'], 80.0, $fs_now + 300);
    check('změna limitu resetuje', [$fs_types($fs_thr), $fs_thr['state']['/srv']['streak']], [[], 1]);

    $fs_skip = [
        ['mount' => '/', 'fstype' => 'ext4', 'total_kb' => 117217792, 'used_pct' => 99.0],
        ['mount' => '/overlay', 'fstype' => 'ext4', 'total_kb' => 117217792, 'used_pct' => 99.0],
        ['mount' => '/rom', 'fstype' => 'squashfs', 'total_kb' => 117217792, 'used_pct' => 100.0],
        ['mount' => '/mnt/img', 'fstype' => 'squashfs', 'total_kb' => 117217792, 'used_pct' => 100.0],
    ];
    $fs_s1 = bk_fs_alert_eval($fs_skip, [], 90.0, $fs_now);
    $fs_s2 = bk_fs_alert_eval($fs_skip, $fs_s1['state'], 90.0, $fs_now + 60);
    check('/, /overlay, /rom a squashfs se přeskočí',
        [$fs_types($fs_s2), array_keys($fs_s2['state'])], [[], []]);

    $fs_tiny = fn (): array => $fs_one(99.0, '/boot', ['fstype' => 'ext4', 'total_kb' => 32768]);
    $fs_t1 = bk_fs_alert_eval($fs_tiny(), [], 90.0, $fs_now);
    $fs_t2 = bk_fs_alert_eval($fs_tiny(), $fs_t1['state'], 90.0, $fs_now + 60);
    check('oddíl pod 64 MB se přeskočí', [$fs_types($fs_t2), array_keys($fs_t2['state'])], [[], []]);

    // An unplugged USB disk keeps its latch for a week, then goes, or every
    // mount the router ever had would sit in last_details forever.
    $fs_gone = bk_fs_alert_eval([], $fs_2['state'], 90.0, $fs_now + 3 * 86400);
    check('odpojený oddíl si týden drží záznam', array_keys($fs_gone['state']), ['/srv']);
    check('po sedmi dnech záznam zmizí',
        array_keys(bk_fs_alert_eval([], $fs_2['state'], 90.0, $fs_now + 8 * 86400)['state']), []);
    check('a po návratu se upozornění neposílá znovu',
        $fs_types(bk_fs_alert_eval($fs_one(95.0), $fs_gone['state'], 90.0, $fs_now + 3 * 86400 + 60)), []);
}
// --- bk_speedtest_item / _ack / _in_report: příjem měření rychlosti ----------
// W01: agent před 0.1.7 dělil skutečné Mbit/s číslem 125000, takže v tabulce
// leží hodnoty jako 0.0148. Neměřeno je NULL, nikdy vymyšlená nula.
if (function_exists('bk_speedtest_item')) {
    $st_ts = '2026-09-20T05:23:41+02:00';
    $st_at = date('Y-m-d H:i:s', (int)strtotime($st_ts));
    $st_full = [
        'timestamp' => $st_ts, 'download_mbps' => 1350.12, 'upload_mbps' => 902.4, 'ping_ms' => 2.1, 'jitter_ms' => 0.3,
        'server' => 'Prague, Czech Republic (CESNET)', 'bytes_received' => 2531000000, 'bytes_sent' => 1692000000,
        'started_by' => 'agent', 'iface' => 'eth2', 'tool' => 'go-1.0.12', 'link_mbit' => 2500,
    ];
    $st_one = bk_speedtest_item($st_full);
    check('měření se rozpadne na sloupce tabulky', [$st_one['row']['measured_at'], $st_one['row']['download_mbps'],
        $st_one['row']['server_name'], $st_one['row']['iface'], $st_one['row']['tool'], $st_one['row']['link_mbit']],
        [$st_at, 1350.12, 'Prague, Czech Republic (CESNET)', 'eth2', 'go-1.0.12', 2500]);
    check('started_by se ukládá do sloupce source', [$st_one['row']['source'],
        bk_speedtest_item(['timestamp' => $st_ts, 'started_by' => 'turris'])['row']['source'],
        bk_speedtest_item(['timestamp' => $st_ts])['row']['source']], ['agent', 'turris', 'turris']);
    check('bez času měření se položka odmítne', bk_speedtest_item(['download_mbps' => 5.0]), null);

    // The mark of a pre-0.1.7 agent is the missing byte counter.
    $st_dmg = bk_speedtest_item(['timestamp' => $st_ts, 'download_mbps' => 0.0148, 'upload_mbps' => 0.05]);
    check('poškozená hodnota bez počtu bajtů se uloží jako NULL',
        [$st_dmg['row']['download_mbps'], $st_dmg['row']['upload_mbps']], [null, null]);
    $st_slow = bk_speedtest_item(['timestamp' => $st_ts, 'download_mbps' => 0.05, 'bytes_received' => 93750]);
    check('stejně malá hodnota S počtem bajtů je měření a zůstane', $st_slow['row']['download_mbps'], 0.05);

    check('jednotková totožnost nástroje projde', bk_speedtest_unit_ok(1350.12, 2531000000), true);
    check('bajty/s místo Mbit/s totožnost neprojdou', bk_speedtest_unit_ok(0.0148, 2531000000), false);
    check('bez jedné ze dvou hodnot se nerozhoduje',
        [bk_speedtest_unit_ok(1350.12, null), bk_speedtest_unit_ok(null, 2531000000), bk_speedtest_unit_ok(0.0, 2531000000)], [null, null, null]);
    $st_bad_unit = bk_speedtest_item(['timestamp' => $st_ts, 'download_mbps' => 0.0148, 'bytes_received' => 2531000000]);
    check('neshoda jednotek se uloží k výsledku a ohlásí',
        [json_decode($st_bad_unit['row']['diagnostics'], true)['unit_mismatch'], $st_bad_unit['issues'][0][0]], [true, 'unit_mismatch']);

    // Diagnostics: only the named keys, re-encoded by the server.
    $st_diag = bk_speedtest_item($st_full + ['diagnostics' => ['v' => 1, 'cpu_measured' => true, 'path_verified' => true,
        'client' => ['ip' => '10.0.0.1'], 'samples' => 45, 'gaps' => 0,
        'dl' => ['secs' => 15.0, 'wan_mbps' => 1400.0, 'core' => 0, 'core_busy_pct' => 100.0, 'hostname' => 'omnia'],
        'run' => ['wan_rx_errors' => 0], 'path' => ['flow_offloading' => true]]]);
    $st_dec = json_decode($st_diag['row']['diagnostics'], true);
    check('diagnostika projde jen seznamem povolených klíčů',
        [isset($st_dec['client']), isset($st_dec['dl']['hostname']), (float)$st_dec['dl']['wan_mbps'], $st_dec['samples'], $st_dec['path']['flow_offloading']],
        [false, false, 1400.0, 45, true]);
    check('měření z Turrisu diagnostiku mít nemusí', bk_speedtest_item(['timestamp' => $st_ts])['row']['diagnostics'], null);
    $st_huge = bk_speedtest_item(['timestamp' => $st_ts, 'diagnostics' => ['v' => 1, 'dl' => ['wan_mbps' => 1.0], 'run' => array_fill_keys(
        array_map(fn ($n) => "pad_{$n}", range(1, 400)), 1)]]);
    check_true('příliš velká diagnostika se zahodí a řekne se to',
        $st_huge['row']['diagnostics'] === null || strlen($st_huge['row']['diagnostics']) <= 2048);
    $st_drop = bk_speedtest_diagnostics(['neznamy' => 1, 'jiny' => 'x']);
    check('diagnostika bez jediného známého klíče je zahozená položka', [$st_drop['diagnostics'], $st_drop['dropped']], [null, true]);

    // The ack is what the agent deletes its files by (WAN 3.1.6).
    $st_h = fn (int $n): array => ['ts' => $n, 'raw_ts' => "t{$n}"];
    check('potvrzuje se nejnovější zpracovaná položka',
        bk_speedtest_ack([$st_h(10), $st_h(30), $st_h(20)], []), 't30');
    check('po chybě zápisu se potvrdí jen to, co je před ní',
        bk_speedtest_ack([$st_h(10), $st_h(20)], [$st_h(20), $st_h(30)]), 't10');
    check('když neprošlo nic, nepotvrzuje se nic',
        [bk_speedtest_ack([], [$st_h(10)]), bk_speedtest_ack([], [])], [null, null]);

    // X17: the report itself says a test overlapped this minute.
    $st_now = 1789891234;
    check('výsledek z poslední minuty hlášení označí',
        bk_speedtest_in_report([['timestamp' => date('c', $st_now - 60)]], $st_now), true);
    check('starší výsledek ve stejném hlášení nic neoznačuje',
        [bk_speedtest_in_report([['timestamp' => date('c', $st_now - 600)]], $st_now), bk_speedtest_in_report(null, $st_now)], [false, false]);
}

// --- bk_router_alert_eval: západky a události routeru (X14, list 2.2) --------
// Stejný vzorec jako u WAN a LTE: streak, západka, zotavení. Co hlášení
// neneslo, nechává západku na pokoji - „nevíme" není „je to v pořádku".
if (function_exists('bk_router_alert_eval')) {
    $ra_now = 1789891234;
    $ra_types = fn (array $res): array => array_map(fn ($e) => $e['type'], $res['events']);
    $ra_run = function (array $cur, array $state, int $at) { return bk_router_alert_eval($cur, $state, $at); };

    // Link speed of the WAN port: baseline = the highest rate ever seen.
    $ra_link = fn (float $mbit): array => ['wan_link_mbit' => $mbit, 'wan_link_dev' => 'eth2'];
    $ra_l1 = $ra_run($ra_link(2500.0), [], $ra_now);
    check('první rychlost portu WAN je jen základ, žádná událost',
        [$ra_types($ra_l1), $ra_l1['state']['wan_link_baseline']['mbit']], [[], 2500.0]);
    $ra_l2 = $ra_run($ra_link(1000.0), $ra_l1['state'], $ra_now + 60);
    $ra_l3 = $ra_run($ra_link(1000.0), $ra_l2['state'], $ra_now + 120);
    check('dvě pomalejší hlášení ještě poplach nespustí', [$ra_types($ra_l2), $ra_types($ra_l3)], [[], []]);
    $ra_l4 = $ra_run($ra_link(1000.0), $ra_l3['state'], $ra_now + 180);
    check('třetí pomalejší hlášení ohlásí degradaci s oběma rychlostmi',
        [$ra_types($ra_l4), $ra_l4['events'][0]['status'], $ra_l4['events'][0]['message']],
        [['wan_link_degraded'], 'wan_link_degraded', 'Port WAN (eth2) je spojený rychlostí 1000 Mbit/s, dosud 2500 Mbit/s.']);
    check('další pomalé hlášení už mlčí', $ra_types($ra_run($ra_link(1000.0), $ra_l4['state'], $ra_now + 240)), []);
    $ra_l5 = $ra_run($ra_link(2500.0), $ra_l4['state'], $ra_now + 300);
    check('návrat na plnou rychlost se ohlásí jednou',
        [$ra_types($ra_l5), $ra_l5['state']['wan_link_alert_sent'],
            $ra_types($ra_run($ra_link(2500.0), $ra_l5['state'], $ra_now + 360))],
        [['wan_link_restored'], false, []]);
    // Seven days at the lower rate: the ISP really moved the customer, the
    // weekly rule takes over and the latch goes out WITHOUT a "restored" lie.
    $ra_l6 = $ra_run($ra_link(1000.0), $ra_l4['state'], $ra_now + 8 * 86400);
    check('po týdnu na nižší rychlosti se základ přeučí a západka zhasne beze slova',
        [$ra_types($ra_l6), $ra_l6['state']['wan_link_baseline']['mbit'], $ra_l6['state']['wan_link_alert_sent']],
        [[], 1000.0, false]);
    check('bez měření rychlosti se západka nemění',
        $ra_run(['wan_link_mbit' => null], $ra_l4['state'], $ra_now + 400)['state']['wan_link_alert_sent'], true);

    // Connection table (W09): a refused connection counts only while the
    // table really is full - below 90 % a clash is an ordinary clash.
    $ra_c1 = $ra_run(['conntrack_pct' => 92.0], [], $ra_now);
    $ra_c2 = $ra_run(['conntrack_pct' => 92.0], $ra_c1['state'], $ra_now + 60);
    check('plná tabulka spojení se ohlásí až po druhém hlášení',
        [$ra_types($ra_c1), $ra_types($ra_c2), $ra_c2['events'][0]['message']],
        [[], ['conntrack_full'], 'Tabulka spojení routeru je zaplněná z 92 % a nová spojení odmítá.']);
    check('odmítnuté spojení při plné tabulce ohlásí hned první hlášení',
        $ra_types($ra_run(['conntrack_pct' => 95.0, 'conntrack_drops' => 3], [], $ra_now)), ['conntrack_full']);
    check('kolize při poloprázdné tabulce nejsou odmítnutá spojení',
        $ra_types($ra_run(['conntrack_pct' => 40.0, 'conntrack_drops' => 3], [], $ra_now)), []);
    $ra_c3 = $ra_run(['conntrack_pct' => 88.0], $ra_c2['state'], $ra_now + 120);
    $ra_c4 = $ra_run(['conntrack_pct' => 80.0], $ra_c3['state'], $ra_now + 180);
    check('mezi 85 a 90 % se nic nemění, pod 85 % se zapíše návrat do normálu',
        [$ra_types($ra_c3), $ra_types($ra_c4), $ra_c4['events'][0]['status']], [[], ['conntrack_normal'], null]);

    // Firewall (G20): only a device with a WAN role. On a dumb AP a disabled
    // firewall is the recommended setup.
    $ra_fw = ['firewall_enabled' => false, 'wan_up' => true];
    $ra_f1 = $ra_run($ra_fw, [], $ra_now);
    $ra_f2 = $ra_run($ra_fw, $ra_f1['state'], $ra_now + 60);
    $ra_f3 = $ra_run($ra_fw, $ra_f2['state'], $ra_now + 120);
    check('firewall se ohlásí až po třech hlášeních a pamatuje si čas prvního',
        [$ra_types($ra_f1), $ra_types($ra_f2), $ra_types($ra_f3), $ra_f3['state']['firewall_off_since']],
        [[], [], ['firewall_disabled'], $ra_now]);
    $ra_f4 = $ra_run(['firewall_enabled' => true, 'wan_up' => true], $ra_f3['state'], $ra_now + 180);
    check('načtená pravidla ohlásí zotavení a zapomenou čas',
        [$ra_types($ra_f4), $ra_f4['state']['firewall_off_since']], [['firewall_restored'], null]);
    $ra_ap = ['firewall_enabled' => false, 'wan_up' => null];
    $ra_ap3 = $ra_run($ra_ap, $ra_run($ra_ap, $ra_run($ra_ap, [], $ra_now)['state'], $ra_now + 60)['state'], $ra_now + 120);
    check('AP bez WAN role poplach o firewallu nedostane', $ra_types($ra_ap3), []);

    // Local DNS resolver (G41): judged only while the line itself works.
    $ra_dns = ['dns_resolver_ok' => false, 'wan_internet' => true];
    $ra_d1 = $ra_run($ra_dns, [], $ra_now);
    $ra_d2 = $ra_run($ra_dns, $ra_d1['state'], $ra_now + 60);
    check('DNS resolver se ohlásí po druhém neúspěchu',
        [$ra_types($ra_d1), $ra_types($ra_d2), $ra_d2['events'][0]['message']],
        [[], ['dns_resolver_failed'], 'DNS resolver routeru neodpovídá (připojení k internetu funguje).']);
    check('při výpadku linky se resolver nesoudí a západka se nemění',
        [$ra_types($ra_run(['dns_resolver_ok' => false, 'wan_internet' => false], $ra_d1['state'], $ra_now + 60)),
            $ra_run(['dns_resolver_ok' => false, 'wan_internet' => null], $ra_d1['state'], $ra_now + 60)['state']['dns_resolver_bad_streak']],
        [[], 1]);
    check('odpovídající resolver ohlásí návrat',
        $ra_types($ra_run(['dns_resolver_ok' => true, 'wan_internet' => true], $ra_d2['state'], $ra_now + 120)), ['dns_resolver_restored']);

    // Restart and OOM: timeline only, and the cause is never claimed.
    $ra_r = $ra_run(['uptime' => 120], ['uptime' => 400000], $ra_now);
    check('klesající uptime je restart, jen do časové osy a bez příčiny',
        [$ra_types($ra_r), $ra_r['events'][0]['status'], strpos($ra_r['events'][0]['message'], 'Příčinu router nezaznamenává') !== false],
        [['router_rebooted'], null, true]);
    check('rostoucí uptime restart není', $ra_types($ra_run(['uptime' => 400060], ['uptime' => 400000], $ra_now)), []);
    $ra_o = $ra_run(['oom_kills' => 3, 'uptime' => 400060], ['oom_kills' => 1, 'uptime' => 400000], $ra_now);
    check('růst OOM čítače je událost a zapíše si čas',
        [$ra_types($ra_o), $ra_o['events'][0]['message'], $ra_o['state']['oom_kill_at']],
        [['oom_kill'], 'Jádro ukončilo 2 proces(y) pro nedostatek paměti.', $ra_now]);
    check('po restartu se nulovaný OOM čítač za událost nepovažuje',
        $ra_types($ra_run(['oom_kills' => 0, 'uptime' => 120], ['oom_kills' => 5, 'uptime' => 400000], $ra_now)), ['router_rebooted']);
}

// --- bk_reports_24h_expected / bk_wireguard_peer_count ----------------------
if (function_exists('bk_reports_24h_expected')) {
    $rq_now = 1789891234;
    check('za celý den se čeká 1440 minutových hlášení',
        bk_reports_24h_expected(['status' => 'up'], null, $rq_now)['expected'], 1440);
    check('router nahozený před dvěma hodinami dluží jen dvě hodiny',
        bk_reports_24h_expected(['status' => 'up'], $rq_now - 7200, $rq_now)['expected'], 120);
    check('vypnutý (pozastavený) monitor nedluží nic',
        bk_reports_24h_expected(['status' => 'paused'], null, $rq_now)['expected'], 0);
    check('naplánovaná údržba se z očekávání odečte',
        bk_reports_24h_expected(['status' => 'up', 'maintenance' => 1,
            'maintenance_start' => date('Y-m-d H:i:s', $rq_now - 7200),
            'maintenance_end' => date('Y-m-d H:i:s', $rq_now - 3600)], null, $rq_now)['expected'], 1380);

    check('seznam protějšků WireGuardu se do sloupce ukládá jako počet',
        [bk_wireguard_peer_count([['public_key' => 'a'], ['public_key' => 'b']]), bk_wireguard_peer_count([]),
            bk_wireguard_peer_count(3), bk_wireguard_peer_count(null), bk_wireguard_peer_count('x')], [2, 0, 3, null, null]);
}

// --- Pořadí doporučení routeru (CORE 3.7) --------------------------------
// The order is what the reader of the weekly e-mail sees first, and it is
// NOT alphabetical: a disk that is losing power must come before an optional
// self-test, whatever their keys are.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_router_rec_thresholds', 'bk_rec_item', 'bk_rec_sort_items',
]);

if (function_exists('bk_rec_sort_items')) {
    $mk = fn (string $id, string $area, string $sev, string $key = ''): array =>
        bk_rec_item($id, $key === '' ? $id : $key, $area, $sev, ['kind' => 'disk']);
    $ids = fn (array $items): array => array_map(fn ($i) => $i['key'], $items);

    check('závažnost rozhoduje první',
        $ids(bk_rec_sort_items([$mk('disk_selftest_never', 'storage', 'info'), $mk('disk_smart_failing', 'storage', 'critical')])),
        ['disk_smart_failing', 'disk_selftest_never']);

    check('při stejné závažnosti jde úložiště před wifi a wifi před balíčky',
        $ids(bk_rec_sort_items([$mk('pkg_iw', 'packages', 'info'), $mk('wifi_noise_high', 'wifi', 'info'), $mk('disk_selftest_never', 'storage', 'info')])),
        ['disk_selftest_never', 'wifi_noise_high', 'pkg_iw']);

    // The reason the rank list exists: alphabetically `disk_selftest_never`
    // would come first, and „router loses power" would be under it.
    check('pořadí pravidel není abecední',
        $ids(bk_rec_sort_items([$mk('disk_selftest_never', 'storage', 'info'), $mk('disk_unclean_shutdowns', 'storage', 'info')])),
        ['disk_unclean_shutdowns', 'disk_selftest_never']);

    check('dvě instance téhož pravidla řadí klíč',
        $ids(bk_rec_sort_items([$mk('disk_temp_warm', 'storage', 'warning', 'disk_temp_warm:d:b'), $mk('disk_temp_warm', 'storage', 'warning', 'disk_temp_warm:d:a')])),
        ['disk_temp_warm:d:a', 'disk_temp_warm:d:b']);

    check('každé id ze seznamu pravidel má svůj rank',
        count(bk_router_rec_thresholds()['rank']), count(array_unique(bk_router_rec_thresholds()['rank'])));

    check_true('položka jen pro stránku to o sobě řekne',
        !empty(bk_rec_item('wan_cpu_packet_path', 'wan_cpu_packet_path', 'wan', 'info', [], [], true)['page_only'])
        && !array_key_exists('page_only', bk_rec_item('disk_temp_warm', 'k', 'storage', 'info', [])));
}


// --- Pravidla Wi-Fi (CORE 3.7) ---------------------------------------------
// Každé z dvanácti pravidel má trojici „spustí se" / „těsně pod hranicí se
// nespustí" / „drží, dokud je aktivní": bez držení by hodnota na hranici
// blikala v pondělním e-mailu týden co týden.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_rec_rules_wifi', 'bk_rec_band_suffix', 'bk_rec_radio_key', 'bk_rec_metrics_of',
    'bk_rec_htmode', 'bk_rec_state_severity', 'bk_wifi_radio_profile',
    'bk_rec_week_stat', 'bk_rec_over', 'bk_rec_above', 'bk_rec_state_active',
    'bk_router_rec_window', 'bk_router_rec_thresholds', 'bk_rec_item',
]);

if (function_exists('bk_rec_rules_wifi')) {
    $wf_win = bk_router_rec_window(date('Y-m-d'));
    // One constant value on every day of a window slice. `samples` decides
    // whether the day counts at all (CORE: >= 360).
    $wf_series = function (array $days, $value, int $samples = 1440): array {
        $out = [];
        foreach ($days as $day) {
            $out[$day] = ['min' => $value, 'avg' => $value, 'max' => $value, 'samples' => $samples];
        }
        return $out;
    };
    $wf_radio = function (array $over = []): array {
        return array_merge([
            'radio' => 'phy0-ap0', 'ssid' => 'Test 5', 'mode' => 'ap', 'band' => '5GHz',
            'channel' => 36, 'htmode' => 'HE80', 'htmodes_supported' => ['HT20', 'VHT80', 'HE80'],
            'encryption' => 'wpa2_wpa3', 'encryption_enterprise' => false, 'phy_has_6ghz' => false,
            'clients' => 2, 'clients_weak' => 0, 'signal_min' => -50, 'snr_min' => 40,
            'weakest_gen' => 6, 'bitrate_tx_avg_mbps' => 500.0,
            'clients_caps_known' => 2, 'clients_6ghz_capable' => 0,
        ], $over);
    };
    // One evaluation of the Wi-Fi group: radios, weekly metrics, saved state.
    $wf_run = function (array $radios, array $metrics, array $state = [], array $details = []) use ($wf_win): array {
        $win = $wf_win;
        $win['metrics'] = $metrics;
        return bk_rec_rules_wifi([
            'window' => $win,
            'details' => array_merge(['wifi_radios' => $radios], $details),
            'monitor' => ['id' => 1, 'type' => 'openwrt'],
            'now' => time(),
        ], $state);
    };
    // rule id => severity, so a test reads like the e-mail.
    $wf_fired = function (array $res): array {
        $out = [];
        foreach ($res['items'] as $item) {
            $out[(string)$item['id']] = (string)$item['severity'];
        }
        ksort($out);
        return $out;
    };
    $wf_active = fn (string $key, string $sev = 'info'): array => [$key => ['active' => 1, 'severity' => $sev]];
    // Which of the LISTED rules answered "not measured" instead of silently
    // passing - the difference between "nothing found" and "nothing measured".
    $wf_skipped = fn (array $res, array $ids): array =>
        array_values(array_intersect($ids, array_values($res['not_evaluated'])));

    // --- wifi_channel_busy: cizí provoz má vlastní (nižší) práh, protože
    //     vlastní stahování změnou kanálu nezmizí.
    $wf_busy = fn (float $other, ?float $total = 20.0): array => array_merge(
        ['wifi_busy_other_5g' => $wf_series($wf_win['days'], $other)],
        $total === null ? [] : ['wifi_busy_5g' => $wf_series($wf_win['days'], $total)]);
    check('Wi-Fi: cizí provoz 35 % na kanálu se ozve', $wf_fired($wf_run([$wf_radio()], $wf_busy(35.0))),
        ['wifi_channel_busy' => 'info']);
    check('Wi-Fi: cizí provoz 34,9 % je ticho', $wf_fired($wf_run([$wf_radio()], $wf_busy(34.9))), []);
    check('Wi-Fi: rozdělaný kanál drží na 30 %, pod 30 % končí',
        [$wf_fired($wf_run([$wf_radio()], $wf_busy(30.0), $wf_active('wifi_channel_busy:5g'))),
            $wf_fired($wf_run([$wf_radio()], $wf_busy(29.9), $wf_active('wifi_channel_busy:5g')))],
        [['wifi_channel_busy' => 'info'], []]);
    check('Wi-Fi: cizí provoz 55 % je varování, 50 % s uloženým varováním drží',
        [$wf_fired($wf_run([$wf_radio()], $wf_busy(55.0))),
            $wf_fired($wf_run([$wf_radio()], $wf_busy(50.0), $wf_active('wifi_channel_busy:5g', 'warning')))],
        [['wifi_channel_busy' => 'warning'], ['wifi_channel_busy' => 'warning']]);
    check('Wi-Fi: bez cizího provozu platí práh celkové obsazenosti 50 %',
        [$wf_fired($wf_run([$wf_radio()], ['wifi_busy_5g' => $wf_series($wf_win['days'], 50.0)])),
            $wf_fired($wf_run([$wf_radio()], ['wifi_busy_5g' => $wf_series($wf_win['days'], 49.9)]))],
        [['wifi_channel_busy' => 'info'], []]);
    check('Wi-Fi: pásmo bez survey se nevyhodnocuje, nemlčí jako v pořádku',
        $wf_skipped($wf_run([$wf_radio()], []),
            ['wifi_channel_busy', 'wifi_noise_high', 'wifi_week_degraded']),
        ['wifi_channel_busy', 'wifi_noise_high', 'wifi_week_degraded']);

    // --- wifi_noise_high: hranice jsou „fair" / „poor" z aplikace.
    $wf_noise = fn (float $dbm): array => ['wifi_noise_5g' => $wf_series($wf_win['days'], $dbm)];
    check('Wi-Fi: šum −84 dBm se ozve, −85 dBm ne',
        [$wf_fired($wf_run([$wf_radio()], $wf_noise(-84.0))), $wf_fired($wf_run([$wf_radio()], $wf_noise(-85.0)))],
        [['wifi_noise_high' => 'info'], []]);
    check('Wi-Fi: šum drží do −87 dBm, pod ním končí',
        [$wf_fired($wf_run([$wf_radio()], $wf_noise(-86.0), $wf_active('wifi_noise_high:5g'))),
            $wf_fired($wf_run([$wf_radio()], $wf_noise(-87.0), $wf_active('wifi_noise_high:5g')))],
        [['wifi_noise_high' => 'info'], []]);
    check('Wi-Fi: šum −79 dBm je varování, −82 s uloženým varováním drží',
        [$wf_fired($wf_run([$wf_radio()], $wf_noise(-79.0))),
            $wf_fired($wf_run([$wf_radio()], $wf_noise(-81.0), $wf_active('wifi_noise_high:5g', 'warning')))],
        [['wifi_noise_high' => 'warning'], ['wifi_noise_high' => 'warning']]);

    // --- wifi_week_degraded: jen prostředí, ne klienti - jinak by týden
    //     prázdného bytu vypadal jako závada.
    $wf_two_weeks = function (array $w_vals, array $p_vals) use ($wf_win, $wf_series): array {
        $out = [];
        foreach ($w_vals as $key => $v) {
            $out[$key] = $wf_series($wf_win['days'], $v);
        }
        foreach ($p_vals as $key => $v) {
            $out[$key] = ($out[$key] ?? []) + $wf_series($wf_win['prev_days'], $v);
        }
        return $out;
    };
    check('Wi-Fi: šum o 8 dB výš a nad −88 dBm je zhoršené prostředí',
        $wf_fired($wf_run([$wf_radio()], $wf_two_weeks(['wifi_noise_5g' => -87.0], ['wifi_noise_5g' => -95.0]))),
        ['wifi_week_degraded' => 'info']);
    check('Wi-Fi: stejný růst, ale pořád pod −88 dBm, je ticho',
        $wf_fired($wf_run([$wf_radio()], $wf_two_weeks(['wifi_noise_5g' => -89.0], ['wifi_noise_5g' => -97.0]))), []);
    check('Wi-Fi: zhoršený šum i cizí provoz najednou je varování',
        $wf_fired($wf_run([$wf_radio()], $wf_two_weeks(
            ['wifi_noise_5g' => -87.0, 'wifi_busy_other_5g' => 31.0],
            ['wifi_noise_5g' => -95.0, 'wifi_busy_other_5g' => 10.0]))),
        ['wifi_week_degraded' => 'warning']);
    check('Wi-Fi: cizí provoz o 15 bodů výš, ale pod 30 %, prostředí nezhorší',
        $wf_fired($wf_run([$wf_radio()], $wf_two_weeks(
            ['wifi_busy_other_5g' => 29.0], ['wifi_busy_other_5g' => 10.0]))), []);
    check('Wi-Fi: bez předchozího týdne se zhoršení nevyhodnocuje, šum ano',
        $wf_skipped($wf_run([$wf_radio()], $wf_noise(-92.0)),
            ['wifi_noise_high', 'wifi_week_degraded']), ['wifi_week_degraded']);

    // --- wifi_weak_encryption / wifi_wpa2_only: konfigurace jedné sítě.
    $wf_enc = fn (string $enc, array $over = []): array =>
        $wf_fired($wf_run([$wf_radio(array_merge(['encryption' => $enc], $over))], $wf_noise(-92.0)));
    check('Wi-Fi: WEP a WPA1 jsou kritické, otevřená síť varování',
        [$wf_enc('wep'), $wf_enc('wpa'), $wf_enc('open')],
        [['wifi_weak_encryption' => 'critical'], ['wifi_weak_encryption' => 'critical'],
            ['wifi_weak_encryption' => 'warning']]);
    check('Wi-Fi: WPA/WPA2 je varování a nikdy „jen WPA2"', $wf_enc('wpa_wpa2'),
        ['wifi_weak_encryption' => 'warning']);
    check('Wi-Fi: OWE ani WPA2/WPA3 se za slabé nepovažuje',
        [$wf_enc('owe'), $wf_enc('wpa2_wpa3')], [[], []]);
    check('Wi-Fi: samotné WPA2 je informace, podnikové WPA2 ne',
        [$wf_enc('wpa2'), $wf_enc('wpa2', ['encryption_enterprise' => true])],
        [['wifi_wpa2_only' => 'info'], []]);

    // --- wifi_wpa3_ready: nejpřísnější pravidlo, zve majitele vypnout WPA2.
    $wf_wpa3 = function (array $days_over = [], int $clients = 4) use ($wf_win, $wf_series, $wf_radio, $wf_run): array {
        $days = $wf_series($wf_win['days'], 0);
        foreach ($days_over as $idx => $row) {
            $days[$wf_win['days'][$idx]] = $row;
        }
        return $wf_run([$wf_radio()], ['wifi_wpa2_clients' => $days,
            'wifi_clients' => $wf_series($wf_win['days'], $clients)]);
    };
    check('Wi-Fi: celý týden bez jediného WPA2 klienta nabídne čisté WPA3',
        $wf_fired($wf_wpa3()), ['wifi_wpa3_ready' => 'info']);
    check('Wi-Fi: jedna stanice přes WPA2 v jediném dni nabídku zruší',
        $wf_fired($wf_wpa3([3 => ['min' => 0, 'avg' => 0.1, 'max' => 1, 'samples' => 1440]])), []);
    check('Wi-Fi: den s mezerou v datech se nevyhodnotí, místo aby prošel',
        [$wf_fired($wf_wpa3([3 => ['min' => 0, 'avg' => 0, 'max' => 0, 'samples' => 1295]])),
            $wf_skipped($wf_wpa3([3 => ['min' => 0, 'avg' => 0, 'max' => 0, 'samples' => 1295]]),
                ['wifi_wpa3_ready'])],
        [[], ['wifi_wpa3_ready']]);
    check('Wi-Fi: týden bez jediného klienta se na WPA3 nepřepíná', $wf_fired($wf_wpa3([], 0)), []);

    // --- wifi_mode_below_card / wifi_channel_narrow / wifi_24_wide_channel:
    //     co karta na TOMTO pásmu opravdu umí (REAL_FACTS: na 2,4 GHz je HT
    //     maximum, i když ovladač hlásí „ac").
    check('Wi-Fi: HT80 na kartě s HE je varování a navrhne HE80',
        $wf_fired($wf_run([$wf_radio(['htmode' => 'HT80'])], $wf_noise(-92.0))),
        ['wifi_mode_below_card' => 'warning']);
    check('Wi-Fi: na 2,4 GHz je stejný nález jen informace',
        $wf_fired($wf_run([$wf_radio(['band' => '2.4GHz', 'channel' => 6, 'htmode' => 'NOHT',
            'htmodes_supported' => ['HT20', 'HT40']])], $wf_noise(-92.0))),
        ['wifi_mode_below_card' => 'info']);
    check('Wi-Fi: HT20 na 2,4 GHz kartě hlásící „ac" je maximum pásma, ne nález',
        $wf_fired($wf_run([$wf_radio(['band' => '2.4GHz', 'channel' => 6, 'htmode' => 'HT20',
            'htmodes_supported' => ['HT20', 'HT40', 'VHT80']])], $wf_noise(-92.0))), []);
    // The suggestion keeps the width the radio runs NOW: widening the channel
    // is a different rule with a different guard.
    $wf_suggested = function (array $res, string $id): ?string {
        foreach ($res['items'] as $item) {
            if ((string)$item['id'] === $id) {
                return (string)($item['params']['suggested'] ?? '');
            }
        }
        return null;
    };
    check('Wi-Fi: navržený režim si nechá současnou šířku kanálu',
        $wf_suggested($wf_run([$wf_radio(['htmode' => 'HT40'])], $wf_noise(-92.0)), 'wifi_mode_below_card'),
        'HE40');
    check('Wi-Fi: 40 MHz na 5 GHz s kartou na 80 MHz je úzký kanál',
        $wf_fired($wf_run([$wf_radio(['htmode' => 'HE40'])], $wf_noise(-92.0))),
        ['wifi_channel_narrow' => 'info']);
    check('Wi-Fi: na obsazeném kanálu se rozšíření nedoporučuje',
        $wf_fired($wf_run([$wf_radio(['htmode' => 'HE40'])],
            ['wifi_busy_5g' => $wf_series($wf_win['days'], 40.0)])), []);
    check('Wi-Fi: 20 MHz na 2,4 GHz je správně, 40 MHz je nález',
        [$wf_fired($wf_run([$wf_radio(['band' => '2.4GHz', 'channel' => 5, 'htmode' => 'HT20',
            'htmodes_supported' => ['HT20', 'HT40']])], $wf_noise(-92.0))),
            $wf_fired($wf_run([$wf_radio(['band' => '2.4GHz', 'channel' => 5, 'htmode' => 'HT40',
                'htmodes_supported' => ['HT20', 'HT40']])], $wf_noise(-92.0)))],
        [[], ['wifi_24_wide_channel' => 'info']]);

    // --- wifi_6ghz_unserved: počítá jen klienty připojené k TOMUTO routeru.
    $wf_6e = fn (float $share): array => ['wifi_6e_unserved' => $wf_series($wf_win['days'], $share)]
        + ['wifi_busy_5g' => $wf_series($wf_win['days'], 3.5)];
    $wf_r6 = fn (array $over = []): array => $wf_radio(array_merge(
        ['clients_caps_known' => 4, 'clients_6ghz_capable' => 2, 'bitrate_tx_avg_mbps' => 736.8], $over));
    check('Wi-Fi: dva klienti s 6 GHz půlku týdne se ozvou, 0,49 ne',
        [$wf_fired($wf_run([$wf_r6()], $wf_6e(0.50))), $wf_fired($wf_run([$wf_r6()], $wf_6e(0.49)))],
        [['wifi_6ghz_unserved' => 'info'], []]);
    check('Wi-Fi: jeden večer bez nich (0,30) běžící nález neukončí, 0,20 ano',
        [$wf_fired($wf_run([$wf_r6()], $wf_6e(0.30), $wf_active('wifi_6ghz_unserved'))),
            $wf_fired($wf_run([$wf_r6()], $wf_6e(0.20), $wf_active('wifi_6ghz_unserved')))],
        [['wifi_6ghz_unserved' => 'info'], []]);
    check('Wi-Fi: 0,30 bez uloženého nálezu nález nezačne',
        $wf_fired($wf_run([$wf_r6()], $wf_6e(0.30))), []);
    check('Wi-Fi: vlastní rádio na 6 GHz nález ukončí, i když podíl zůstal vysoký',
        $wf_fired($wf_run([$wf_r6(), $wf_radio(['band' => '6GHz', 'channel' => 37, 'ssid' => 'Domov 6'])],
            $wf_6e(0.90), $wf_active('wifi_6ghz_unserved'))), []);
    check('Wi-Fi: WAN 2500 Mbit/s dostane větu o smyslu Wi-Fi nad 1 Gbit/s, 1000 Mbit/s vedle 736,8 nic',
        [$wf_run([$wf_r6()], $wf_6e(0.90), [], ['wan_link_mbit' => 2500])['items'][0]['params']['wan_variant'],
            $wf_run([$wf_r6()], $wf_6e(0.90), [], ['wan_link_mbit' => 1000])['items'][0]['params']['wan_variant'],
            $wf_run([$wf_r6()], $wf_6e(0.90), [], ['wan_link_mbit' => 300])['items'][0]['params']['wan_variant']],
        ['fast', null, 'slow']);

    // --- wifi_weak_client: informace, a jen když je pásmo tiché.
    $wf_weak = fn (float $mean, float $noise = -92.0): array =>
        ['wifi_weak_clients' => $wf_series($wf_win['days'], $mean)] + $wf_noise($noise);
    $wf_rw = fn (array $over = []): array => $wf_radio(array_merge(
        ['clients_weak' => 1, 'signal_min' => -75, 'snr_min' => 17, 'weakest_gen' => 4], $over));
    check('Wi-Fi: slabý klient většinu týdne se ozve, 0,49 ne',
        [$wf_fired($wf_run([$wf_rw()], $wf_weak(0.5))), $wf_fired($wf_run([$wf_rw()], $wf_weak(0.49)))],
        [['wifi_weak_client' => 'info'], []]);
    check('Wi-Fi: klient přesně na hranici −75 dBm nebliká - drží do 0,25',
        [$wf_fired($wf_run([$wf_rw()], $wf_weak(0.45), $wf_active('wifi_weak_client'))),
            $wf_fired($wf_run([$wf_rw()], $wf_weak(0.20), $wf_active('wifi_weak_client')))],
        [['wifi_weak_client' => 'info'], []]);
    check('Wi-Fi: v zarušeném pásmu mluví o rušení, ne o vzdálenosti',
        $wf_fired($wf_run([$wf_rw()], $wf_weak(1.0, -82.0))), ['wifi_noise_high' => 'info']);
    check('Wi-Fi: bez změřeného šumu pásma se o vzdálenosti nic netvrdí',
        $wf_fired($wf_run([$wf_rw()], ['wifi_weak_clients' => $wf_series($wf_win['days'], 1.0)])), []);

    // --- wifi_5ghz_clients_on_24: jen když opravdu běží obě pásma.
    $wf_24 = fn (array $over = []): array => $wf_radio(array_merge(
        ['band' => '2.4GHz', 'channel' => 5, 'htmode' => 'HT20', 'ssid' => 'Domov 2G',
            'htmodes_supported' => ['HT20', 'HT40']], $over));
    $wf_cap = fn (float $n): array => ['wifi_5g_capable_24g' => $wf_series($wf_win['days'], $n)] + $wf_noise(-92.0);
    check('Wi-Fi: jedno zařízení s 5 GHz na 2,4 GHz se ozve, 0,99 ne',
        [$wf_fired($wf_run([$wf_radio(), $wf_24()], $wf_cap(1.0))),
            $wf_fired($wf_run([$wf_radio(), $wf_24()], $wf_cap(0.99)))],
        [['wifi_5ghz_clients_on_24' => 'info'], []]);
    check('Wi-Fi: drží do 0,75, pod ní končí',
        [$wf_fired($wf_run([$wf_radio(), $wf_24()], $wf_cap(0.75), $wf_active('wifi_5ghz_clients_on_24'))),
            $wf_fired($wf_run([$wf_radio(), $wf_24()], $wf_cap(0.74), $wf_active('wifi_5ghz_clients_on_24')))],
        [['wifi_5ghz_clients_on_24' => 'info'], []]);
    check('Wi-Fi: bez druhého pásma se přechod na 5 GHz nedoporučuje',
        $wf_fired($wf_run([$wf_24()], $wf_cap(2.0))), []);

    // --- Klíč rádia. REAL_FACTS: totéž USB rádio bylo phy3-ap0 i phy1-ap0,
    //     takže ztlumení klíčované jménem rozhraní by se po restartu ztratilo.
    $wf_key = fn (array $over): string => (string)$wf_run([$wf_radio(array_merge(['encryption' => 'wpa2'], $over))],
        $wf_noise(-92.0))['items'][0]['key'];
    check('Wi-Fi: přejmenované rozhraní klíč nezmění, jiná síť ano',
        [$wf_key(['radio' => 'phy3-ap0']) === $wf_key(['radio' => 'phy1-ap0']),
            $wf_key(['radio' => 'phy0-ap0']) === $wf_key(['ssid' => 'Jiná'])],
        [true, false]);
    check('Wi-Fi: jméno rozhraní zůstává popiskem v subjektu, ne klíčem',
        $wf_run([$wf_radio(['encryption' => 'wpa2', 'radio' => 'phy3-ap0'])], $wf_noise(-92.0))['items'][0]['subject'],
        ['kind' => 'radio', 'radio' => 'phy3-ap0', 'band' => '5GHz']);
    check('Wi-Fi: položka nese pásmo jako hodnotu, ne jako přeložený popisek',
        array_intersect_key($wf_run([$wf_radio(['encryption' => 'wpa2'])], $wf_noise(-92.0))['items'][0]['params'],
            ['band_id' => 1, 'ch' => 1]),
        ['band_id' => '5GHz', 'ch' => 36]);

    // --- Rádia mimo režim AP nemají majitelova klienta ani jeho síť.
    check('Wi-Fi: klientské ani vypnuté rádio žádné doporučení nevydá',
        [$wf_fired($wf_run([$wf_radio(['mode' => 'sta', 'encryption' => 'open'])], $wf_noise(-70.0))),
            $wf_fired($wf_run([$wf_radio(['mode' => null, 'encryption' => 'open'])], $wf_noise(-70.0)))],
        [[], []]);
}

// --- Pravidla WAN, systému a balíčků (rule sheet 2.1, WAN 3.5) --------------
// Každé pravidlo má dvojici „spustí se" / „těsně pod hranicí se nespustí"
// a tam, kde má pár enter/hold, i test držení: bez něj by metrika na hranici
// blikala v pondělním e-mailu každý týden.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_rec_week_stat', 'bk_rec_over', 'bk_rec_above', 'bk_rec_state_active', 'bk_rec_days_over',
    'bk_rec_days_with_data', 'bk_router_rec_window', 'bk_rec_rules_wan_week', 'bk_rec_rules_wan_tests',
    'bk_rec_rules_gap', 'bk_rec_dir_label', 'bk_router_rec_render', 'bk_rec_area_of',
    // The aggregate rules ARE the classifier's consumer, so it is loaded here
    // too; the block below re-loads it for its own tests.
    'bk_wan_dir_inputs', 'bk_wan_core_pinned', 'bk_wan_core_net_share', 'bk_wan_dir_verdict',
    'bk_wan_test_verdict', 'bk_wan_test_countable', 'bk_wan_bottleneck', 'bk_wan_aggregate',
    'bk_wan_agree_numbers', 'bk_wan_line_guards', 'bk_rec_install_cmd', 'bk_rec_command',
    'bk_with_email_lang', 'bk_rec_num', 'bk_rec_band_label', 'bk_rec_radio_label', 'bk_rec_disk_label',
    'bk_format_bytes_cz', 'bk_rec_wifi_gen_label',
]);

if (function_exists('bk_rec_rules_wan_week')) {
    $rw_days = bk_router_rec_window(date('Y-m-d'))['days'];
    // A full week of reports: every rule that reads the week needs four days
    // with data, so the base fixture has seven.
    $rw_series = function (string $key, array $values, int $samples = 1440) use ($rw_days): array {
        $out = [];
        foreach ($rw_days as $i => $day) {
            $v = $values[$i] ?? null;
            if ($v === null) {
                continue;
            }
            $out[$day] = ['min' => $v, 'avg' => $v / max(1, $samples), 'max' => $v, 'samples' => $samples];
        }
        return [$key => $out];
    };
    // A step metric's DAILY value is avg * samples, so a day carrying `n` is
    // written as avg = n / samples.
    $rw_step = function (string $key, array $totals) use ($rw_days): array {
        $out = [];
        foreach ($rw_days as $i => $day) {
            $t = $totals[$i] ?? null;
            if ($t === null) {
                continue;
            }
            $out[$day] = ['min' => 0.0, 'avg' => $t / 1440.0, 'max' => $t, 'samples' => 1440];
        }
        return [$key => $out];
    };
    $rw_base = $rw_series('cpu', array_fill(0, 7, 10.0));
    $rw_in = fn (array $metrics, array $over = []): array => $over + [
        'window' => ['days' => $rw_days, 'metrics' => $metrics + $rw_base, 'prev_days' => [], 'days30' => []],
        'details' => ['wan_link_dev' => 'eth2'],
        'monitor' => ['wan_plan_down_mbit' => 2000, 'wan_plan_up_mbit' => 1000],
        'rx_packets' => [], 'events' => [], 'speedtests' => [], 'server_max' => [],
        'forwarding_minutes' => null, 'conntrack_days' => null, 'now' => time(),
    ];
    $rw_ids = function (array $res): array {
        return array_map(fn (array $i): string => (string)$i['id'], $res['items']);
    };
    $rw_active = fn (string $key): array => [$key => ['active' => 1, 'severity' => 'warning', 'rule_id' => $key]];

    // R-W6: a day counts when even its best minute was below the plan.
    $rw_link = fn (int $below): array =>
        $rw_series('wan_link_mbit', array_merge(array_fill(0, $below, 1000.0), array_fill(0, 7 - $below, 2500.0)));
    check_true('port pod tarifem 6 dní ze 7 = doporučení, 5 dní ne',
        in_array('wan_link_below_plan', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_link(6)), [])), true)
        && !in_array('wan_link_below_plan', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_link(5)), [])), true));
    check_true('port pod tarifem 5 dní: trvající doporučení zůstává, nové nevznikne',
        in_array('wan_link_below_plan', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_link(5)), $rw_active('wan_link_below_plan'))), true)
        && !in_array('wan_link_below_plan', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_link(4)), $rw_active('wan_link_below_plan'))), true));
    check('bez tarifu se port pod tarifem nevyhodnocuje',
        bk_rec_rules_wan_week($rw_in($rw_link(7), ['monitor' => []]), [])['not_evaluated']['wan_link_below_plan'] ?? null,
        'wan_link_below_plan');

    // R-W9, the error branch: a week sum AND enough distinct days.
    $rw_err = fn (array $per_day): array => $rw_step('wan_errors', $per_day);
    check_true('100 chyb ve 3 dnech = doporučení, 99 nebo 2 dny ne',
        in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_err([40.0, 40.0, 20.0])), [])), true)
        && !in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_err([40.0, 40.0, 19.0])), [])), true)
        && !in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_err([60.0, 60.0])), [])), true));
    check_true('50 chyb ve 2 dnech drží jen aktivní doporučení',
        in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_err([25.0, 25.0])), $rw_active('wan_port_errors'))), true)
        && !in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_err([25.0, 25.0])), [])), true));

    // R-W9, the ring branch: a share of the week's received packets, never a
    // bare count - and sysfs drops alone never raise the rule.
    $rw_ring = fn (array $per_day, float $rx): array =>
        $rw_in($rw_step('wan_ring_drops', $per_day), ['rx_packets' => array_fill_keys($rw_days, $rx / 7.0)]);
    check_true('kruhový buffer: 0,1 % paketů ve 2 dnech = doporučení, v 1 dni ne',
        in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_ring([600.0, 600.0], 1000000.0), [])), true)
        && !in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_ring([1200.0], 1000000.0), [])), true));
    // The sysfs counter is evidence only: on mvneta it counts junk frames of
    // protocols nobody asked for, and a week of them must not raise the rule
    // even when the days would be there.
    check_true('R-W9 nevzniká ze samotných sysfs dropů',
        !in_array('wan_port_errors', $rw_ids(bk_rec_rules_wan_week($rw_in(
            $rw_step('wan_drops', [5000.0, 5000.0, 5000.0]) + $rw_step('wan_errors', [1.0, 1.0, 1.0])), [])), true));

    // R-W10: the step is null across a reboot, so those outages cannot count.
    check_true('3 výpadky linky za týden = doporučení, 2 ne',
        in_array('wan_link_flaps', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_step('wan_link_flaps', [1.0, 1.0, 1.0])), [])), true)
        && !in_array('wan_link_flaps', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_step('wan_link_flaps', [1.0, 1.0])), [])), true));
    check_true('2 výpadky drží jen aktivní doporučení',
        in_array('wan_link_flaps', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_step('wan_link_flaps', [1.0, 1.0])), $rw_active('wan_link_flaps'))), true)
        && !in_array('wan_link_flaps', $rw_ids(bk_rec_rules_wan_week($rw_in($rw_step('wan_link_flaps', [1.0])), $rw_active('wan_link_flaps'))), true));
    check('výpadky přes restart routeru se nepočítají (krok je null)',
        bk_rec_rules_wan_week($rw_in($rw_step('wan_link_flaps', [])), [])['not_evaluated']['wan_link_flaps'] ?? null,
        'wan_link_flaps');
    check('méně než 4 dny dat týdenní pravidla nevyhodnotí',
        count(bk_rec_rules_wan_week($rw_in($rw_series('cpu', [10.0, 10.0, 10.0])), [])['not_evaluated']), 3);
}


if (function_exists('bk_rec_rules_wan_tests')) {
    // A router with a full week of data and three agreeing probes below the
    // plan, outside both goodput plateaus, from two proven servers.
    $rt_row = function (int $id, int $days_ago, float $dl, string $server, array $phase = [], array $diag = []): array {
        return ['id' => $id, 'measured_at' => date('Y-m-d H:i:s', time() - $days_ago * 86400),
            'download_mbps' => $dl, 'upload_mbps' => null, 'link_mbit' => 2500, 'source' => 'agent',
            'server_name' => $server, 'diagnostics' => json_encode($diag + [
                'cpu_measured' => true, 'path_verified' => true,
                'background_dl_mbps' => 0.0, 'background_ul_mbps' => 0.0,
                'dl' => $phase + ['secs' => 15.0, 'wan_mbps' => 0.0, 'core' => 0, 'core_busy_pct' => 20.0,
                    'core_user_pct' => 5.0, 'core_system_pct' => 5.0, 'core_irq_softirq_pct' => 10.0,
                    'hot_share' => 0.1, 'all_cores_avg_pct' => 12.0, 'softnet_time_squeeze' => 0.0,
                    'softnet_dropped' => 0.0, 'rx_packets' => 1000000.0, 'retrans_pct' => 0.1],
                'ul' => null, 'run' => ['wan_rx_ring_drops' => 0]])];
    };
    $rt_in = fn (array $over = []): array => $over + [
        'window' => ['days' => $rw_days, 'metrics' => $rw_base, 'prev_days' => [], 'days30' => []],
        'details' => ['wan_link_dev' => 'eth2', 'wan_link_mbit' => 2500, 'wan_path' => []],
        'monitor' => ['wan_plan_down_mbit' => 2000, 'wan_plan_up_mbit' => 1000],
        'speedtests' => [], 'server_max' => ['Praha A' => ['dl' => 1900.0, 'ul' => null],
            'Praha B' => ['dl' => 1900.0, 'ul' => null]],
        'rx_packets' => [], 'events' => [], 'forwarding_minutes' => null, 'conntrack_days' => null,
        'now' => time(),
    ];
    $rt_ids = fn (array $res): array => array_map(fn (array $i): string => (string)$i['id'], $res['items']);
    $rt_three = [$rt_row(1, 1, 700.0, 'Praha A'), $rt_row(2, 3, 710.0, 'Praha B'), $rt_row(3, 5, 705.0, 'Praha A')];

    check_true('shoda tří sond pod tarifem = doporučení o pomalé lince',
        in_array('wan_line_below_plan', $rt_ids(bk_rec_rules_wan_tests($rt_in(['speedtests' => $rt_three]), [])), true));
    check_true('jediná sonda na doporučení o lince nestačí',
        !in_array('wan_line_below_plan', $rt_ids(bk_rec_rules_wan_tests($rt_in(['speedtests' => [$rt_three[0]]]), [])), true));
    check_true('dva servery na 940 při tarifu 2000 linku neobviní',
        !in_array('wan_line_below_plan', $rt_ids(bk_rec_rules_wan_tests($rt_in([
            'speedtests' => [$rt_row(1, 1, 905.0, 'Praha A'), $rt_row(2, 3, 910.0, 'Praha B'),
                $rt_row(3, 5, 900.0, 'Praha A')]]), [])), true));

    // R-W3: the port ceiling, and the negative CORE 7 asks for.
    // The port ceiling can only be the answer where the plan is HIGHER than it:
    // with a 2000 Mbit plan on a 2500 Mbit port, 2200 Mbit/s is the plan being
    // delivered, and rule 1 has to win.
    check_true('rychlost u stropu portu = doporučení o portu, ne o lince',
        in_array('wan_port_limited', $rt_ids(bk_rec_rules_wan_tests($rt_in([
            'monitor' => ['wan_plan_down_mbit' => 5000, 'wan_plan_up_mbit' => 1000],
            'speedtests' => [$rt_row(1, 1, 2200.0, 'Praha A'), $rt_row(2, 3, 2210.0, 'Praha B'),
                $rt_row(3, 5, 2205.0, 'Praha A')]]), [])), true));
    check_true('port 2500 a tarif 2000: port není limit',
        $rt_ids(bk_rec_rules_wan_tests($rt_in(['speedtests' => $rt_three]), [])) === ['wan_line_below_plan']);

    // R-W4: only a shaper set WELL below the plan is worth a sentence.
    $rt_sqm = fn (int $kbps): array => $rt_in([
        'details' => ['wan_link_dev' => 'eth2', 'wan_link_mbit' => 2500,
            'wan_path' => ['sqm' => [['iface' => 'eth2', 'download_kbps' => $kbps, 'upload_kbps' => $kbps]]]],
        'speedtests' => [$rt_row(1, 1, 85.0, 'Praha A', [], ['path' => ['sqm_dl_kbps' => $kbps]]),
            $rt_row(2, 3, 84.0, 'Praha B', [], ['path' => ['sqm_dl_kbps' => $kbps]]),
            $rt_row(3, 5, 85.5, 'Praha A', [], ['path' => ['sqm_dl_kbps' => $kbps]])]]);
    check_true('zapnuté SQM na 85 Mbit při tarifu 2000 se doporučí',
        in_array('wan_sqm_limited', $rt_ids(bk_rec_rules_wan_tests($rt_sqm(85000), [])), true));
    check_true('Omnia: vypnuté SQM nic nedoporučí; zapnuté 85 Mbit při tarifu 2000 ano',
        !in_array('wan_sqm_limited', $rt_ids(bk_rec_rules_wan_tests($rt_in([
            'speedtests' => [$rt_row(1, 1, 85.0, 'Praha A'), $rt_row(2, 3, 84.0, 'Praha B'),
                $rt_row(3, 5, 85.5, 'Praha A')]]), [])), true)
        && in_array('wan_sqm_limited', $rt_ids(bk_rec_rules_wan_tests($rt_sqm(85000), [])), true));

    // R-W7 and the X12 flag it controls.
    $rt_fwd = fn (int $minutes, array $over = []): array =>
        $rt_in($over + ['forwarding_minutes' => $minutes]);
    check_true('10 minut vytíženého jádra = doporučení, 9 ne',
        in_array('wan_forwarding_core_saturated', $rt_ids(bk_rec_rules_wan_tests($rt_fwd(10), [])), true)
        && !in_array('wan_forwarding_core_saturated', $rt_ids(bk_rec_rules_wan_tests($rt_fwd(9), [])), true));
    check_true('5 minut drží jen aktivní doporučení',
        in_array('wan_forwarding_core_saturated', $rt_ids(bk_rec_rules_wan_tests($rt_fwd(5),
            ['wan_forwarding_core_saturated' => ['active' => 1]])), true)
        && !in_array('wan_forwarding_core_saturated', $rt_ids(bk_rec_rules_wan_tests($rt_fwd(5), [])), true));

    // R-W11: only drops from a table that was really full.
    $rt_ct = fn (array $days): array => $rt_in(['conntrack_days' => $days]);
    check_true('odmítnutá spojení ve 2 dnech = doporučení, v 1 dni ne',
        in_array('conntrack_drops', $rt_ids(bk_rec_rules_wan_tests($rt_ct(
            ['2026-09-14' => ['drops' => 5.0, 'pct' => 99.0], '2026-09-15' => ['drops' => 3.0, 'pct' => 97.0]]), [])), true)
        && !in_array('conntrack_drops', $rt_ids(bk_rec_rules_wan_tests($rt_ct(
            ['2026-09-14' => ['drops' => 50.0, 'pct' => 99.0]]), [])), true));
    check_true('1 den drží jen aktivní doporučení',
        in_array('conntrack_drops', $rt_ids(bk_rec_rules_wan_tests($rt_ct(
            ['2026-09-14' => ['drops' => 5.0, 'pct' => 99.0]]), ['conntrack_drops' => ['active' => 1]])), true));
    check_true('kolize při poloprázdné tabulce nejsou odmítnutá spojení',
        !in_array('conntrack_drops', $rt_ids(bk_rec_rules_wan_tests($rt_ct([]), [])), true));

    // WAN 3.4: the evidence row. `early_drop` reaches the SENTENCE, never the
    // count - an evicted connection was not refused. It arrives in details as
    // a counter since boot (the ingest types it), and stays out of the text
    // when the router did not report it.
    $rt_ct_days = ['2026-09-14' => ['drops' => 5.0, 'pct' => 99.0], '2026-09-15' => ['drops' => 3.0, 'pct' => 97.0]];
    $rt_ct_item = function (array $details) use ($rt_in, $rt_ct_days): array {
        foreach (bk_rec_rules_wan_tests($rt_in(['conntrack_days' => $rt_ct_days, 'details' => $details]), [])['items'] as $i) {
            if ((string)$i['id'] === 'conntrack_drops') {
                return $i;
            }
        }
        return [];
    };
    $rt_ct_det = ['wan_link_dev' => 'eth2', 'wan_link_mbit' => 2500, 'wan_path' => []];
    check('vytlačená spojení se berou z hlášení routeru',
        $rt_ct_item($rt_ct_det + ['conntrack_early_drop' => 18])['params']['evicted'] ?? 'chybí', 18.0);
    check('a nepřičítají se k odmítnutým',
        $rt_ct_item($rt_ct_det + ['conntrack_early_drop' => 18])['params']['drops'] ?? null, 8.0);
    $rt_ct_none = $rt_ct_item($rt_ct_det)['params'] ?? [];
    check('nezměřený čítač zůstane null, ne nula',
        [array_key_exists('evicted', $rt_ct_none), $rt_ct_none['evicted']], [true, null]);
    $rt_ct_text = fn (string $lang, array $details): string => bk_with_email_lang($lang, fn (): string =>
        (string)bk_router_rec_render($rt_ct_item($details))['measured']);
    check_true('věta o vytlačených spojeních je v obou jazycích a říká od kdy',
        str_contains($rt_ct_text('cs', $rt_ct_det + ['conntrack_early_drop' => 18]), 'Vytlačeno kvůli místu: 18 spojení od startu routeru.')
        && str_contains($rt_ct_text('en', $rt_ct_det + ['conntrack_early_drop' => 18]), 'Evicted to make room: 18 connections since boot.'));
    check_true('bez změřeného čítače se věta nevykreslí (ani s nulou v textu)',
        !str_contains($rt_ct_text('cs', $rt_ct_det), 'Vytlačeno')
        && !str_contains($rt_ct_text('en', $rt_ct_det), 'Evicted'));
    // A measured zero is a measurement, not a missing value - it stays visible.
    check_true('změřená nula je výsledek, ne prázdno',
        str_contains($rt_ct_text('cs', $rt_ct_det + ['conntrack_early_drop' => 0]), 'Vytlačeno kvůli místu: 0 spojení'));

    // R-W8: the capability, never the rate the ports happen to link at.
    $rt_lan = fn (?int $cap, ?int $max): array => $rt_in([
        'details' => ['wan_link_dev' => 'eth2', 'wan_link_mbit' => 2500,
            'wan_path' => ['lan_port_cap_mbit' => $cap, 'lan_port_max_mbit' => $max]]]);
    check_true('gigabitové porty LAN při tarifu 2000 = informace',
        in_array('lan_wired_ceiling', $rt_ids(bk_rec_rules_wan_tests($rt_lan(1000, 1000), [])), true));
    check_true('neznámá schopnost portů LAN mlčí, i když jsou spojené na gigabitu',
        !in_array('lan_wired_ceiling', $rt_ids(bk_rec_rules_wan_tests($rt_lan(null, 1000), [])), true));

    // The evidence the switch adds (0.1.8): the conduit that is really shared
    // and how many devices share it. It is evidence, never a second rule.
    $rt_lanp = function (?array $lan) use ($rt_in): array {
        $in = $rt_in([]);
        $in['details'] = ['wan_link_dev' => 'eth2', 'wan_link_mbit' => 2500,
            'wan_path' => ['lan_port_cap_mbit' => 1000, 'lan_port_max_mbit' => 1000]];
        if ($lan !== null) {
            $in['details']['lan_ports'] = $lan;
        }
        return $in;
    };
    $rt_lan_item = function (?array $lan) use ($rt_lanp): array {
        foreach (bk_rec_rules_wan_tests($rt_lanp($lan), [])['items'] as $it) {
            if (($it['id'] ?? '') === 'lan_wired_ceiling') {
                return $it;
            }
        }
        return [];
    };
    // Two linked conduits: the SLOWER one is what every wired client shares.
    $rt_lan_two = ['clients_total' => 6, 'ports' => [['name' => 'lan0']], 'conduits' => [
        ['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000],
        ['dev' => 'eth0', 'link' => true, 'speed_mbit' => 2500],
        ['dev' => 'eth5', 'link' => false, 'speed_mbit' => 100]]];
    check('sdílené vedení je to nejpomalejší spojené, nespojené se nepočítá',
        [$rt_lan_item($rt_lan_two)['params']['conduit'], $rt_lan_item($rt_lan_two)['params']['clients']], [1000.0, 6]);
    check('bez údajů z přepínače zůstanou čísla domácnosti neznámá',
        [$rt_lan_item(null)['params']['conduit'], $rt_lan_item(null)['params']['clients']], [null, null]);
    check('jedno zařízení nic nesdílí, proto se nepočítá',
        $rt_lan_item(['clients_total' => 1, 'conduits' => [['dev' => 'eth1', 'link' => true, 'speed_mbit' => 1000]]])['params']['clients'], null);
    $rt_lan_text = fn (string $lang, ?array $lan): string => bk_with_email_lang($lang, fn (): string =>
        (string)bk_router_rec_render($rt_lan_item($lan))['measured']);
    check_true('věta o sdíleném vedení je v obou jazycích a nese obě čísla',
        str_contains($rt_lan_text('cs', $rt_lan_two), 'Kabelem je připojeno 6 zařízení a všechna sdílejí jedno vedení do routeru o rychlosti 1000 Mbit/s.')
        && str_contains($rt_lan_text('en', $rt_lan_two), '6 wired devices are connected and all of them share one 1000 Mbit/s line to the router.'));
    check_true('chybí-li jedno z čísel, věta se nevykreslí vůbec',
        !str_contains($rt_lan_text('cs', null), 'sdílejí')
        && !str_contains($rt_lan_text('en', ['clients_total' => 6, 'conduits' => []]), 'share one'));
}


if (function_exists('bk_rec_rules_gap')) {
    $rg_in = fn (array $over = []): array => $over + [
        'window' => ['days' => $rw_days, 'metrics' => $rw_base, 'prev_days' => [], 'days30' => []],
        'details' => ['wan_up' => true], 'monitor' => [], 'events' => [], 'now' => time(),
    ];
    $rg_ids = fn (array $res): array => array_map(fn (array $i): string => (string)$i['id'], $res['items']);
    $rg_days = fn (int $n, int $per_day): array => array_slice(array_combine($rw_days,
        array_map(fn (string $d): array => ['n' => $per_day, 'last_at' => $d . ' 03:00:00'], $rw_days)), 0, $n);

    // R-F1. Never for a device without a WAN role: a dumb AP has no firewall.
    check_true('nenačtená pravidla firewallu jsou kritické doporučení',
        in_array('firewall_off', $rg_ids(bk_rec_rules_gap($rg_in([
            'details' => ['wan_up' => true, 'firewall_off_since' => time() - 3600]]), [])), true));
    check_true('AP bez role WAN doporučení o firewallu nedostane',
        !in_array('firewall_off', $rg_ids(bk_rec_rules_gap($rg_in([
            'details' => ['firewall_off_since' => time() - 3600]]), [])), true));

    // R-S1 / R-D1: events, counted per day.
    check_true('3 restarty za týden = doporučení, 2 ne',
        in_array('router_restarts', $rg_ids(bk_rec_rules_gap($rg_in(['events' => ['router_rebooted' => $rg_days(3, 1)]]), [])), true)
        && !in_array('router_restarts', $rg_ids(bk_rec_rules_gap($rg_in(['events' => ['router_rebooted' => $rg_days(2, 1)]]), [])), true));
    check_true('2 restarty drží jen aktivní doporučení',
        in_array('router_restarts', $rg_ids(bk_rec_rules_gap($rg_in(['events' => ['router_rebooted' => $rg_days(2, 1)]]),
            ['router_restarts' => ['active' => 1]])), true));
    check_true('věta o nečistých vypnutích jen tam, kde to doporučení opravdu stojí',
        !empty(bk_rec_rules_gap($rg_in(['events' => ['router_rebooted' => $rg_days(3, 1)]]),
            ['disk_unclean_shutdowns:d:a' => ['active' => 1]])['items'][0]['params']['unclean'])
        && empty(bk_rec_rules_gap($rg_in(['events' => ['router_rebooted' => $rg_days(3, 1)]]),
            ['disk_unclean_shutdowns:d:a' => ['active' => 1, 'muted_at' => '2026-01-01']])['items'][0]['params']['unclean']));
    check_true('resolver selhal ve 3 dnech = doporučení, ve 2 ne',
        in_array('dns_resolver_failing', $rg_ids(bk_rec_rules_gap($rg_in(['events' => ['dns_resolver_failed' => $rg_days(3, 2)]]), [])), true)
        && !in_array('dns_resolver_failing', $rg_ids(bk_rec_rules_gap($rg_in(['events' => ['dns_resolver_failed' => $rg_days(2, 9)]]), [])), true));
    check('počet výpadků v textu je počet událostí, ne minuty',
        bk_rec_rules_gap($rg_in(['events' => ['dns_resolver_failed' => $rg_days(3, 2)]]), [])['items'][0]['params']['count'], 6);
    check_true('2 dny drží jen aktivní doporučení',
        in_array('dns_resolver_failing', $rg_ids(bk_rec_rules_gap($rg_in(['events' => ['dns_resolver_failed' => $rg_days(2, 1)]]),
            ['dns_resolver_failing' => ['active' => 1]])), true));

    // R-S2: the column stores the ABSOLUTE skew, so the weekly mean is a real
    // distance and a week of +21 s and -21 s does not cancel itself out.
    $rg_skew = function (float $secs) use ($rw_days, $rw_base): array {
        $days = [];
        foreach ($rw_days as $day) {
            $days[$day] = ['min' => $secs, 'avg' => $secs, 'max' => $secs, 'samples' => 1440];
        }
        return ['clock_skew_s' => $days] + $rw_base;
    };
    $rg_skew_in = fn (float $secs): array => $rg_in([
        'window' => ['days' => $rw_days, 'metrics' => $rg_skew($secs), 'prev_days' => [], 'days30' => []]]);
    check_true('průměrný rozdíl hodin 21 s = doporučení, 20 s ne',
        in_array('clock_skew', $rg_ids(bk_rec_rules_gap($rg_skew_in(21.0), [])), true)
        && !in_array('clock_skew', $rg_ids(bk_rec_rules_gap($rg_skew_in(20.0), [])), true));
    check_true('16 s drží jen aktivní doporučení, 15 s ho ukončí',
        in_array('clock_skew', $rg_ids(bk_rec_rules_gap($rg_skew_in(16.0), ['clock_skew' => ['active' => 1]])), true)
        && !in_array('clock_skew', $rg_ids(bk_rec_rules_gap($rg_skew_in(15.0), ['clock_skew' => ['active' => 1]])), true));

    // Packages: a strict false only, never a missing key.
    check_true('chybějící balíček se doporučí, netestovaný ne',
        $rg_ids(bk_rec_rules_gap($rg_in(['details' => ['wan_up' => true, 'agent_tools' => ['iw' => false]]]), [])) === ['pkg_iw']
        && $rg_ids(bk_rec_rules_gap($rg_in(['details' => ['wan_up' => true, 'agent_tools' => ['smartctl' => true]]]), [])) === []);
    check_true('pkg_librespeed_cli: jen se zapnutým měřením a chybějícím programem',
        in_array('pkg_librespeed_cli', $rg_ids(bk_rec_rules_gap($rg_in([
            'details' => ['wan_up' => true, 'agent_tools' => ['librespeed_cli' => false]],
            'monitor' => ['wan_probe_enabled' => 1]]), [])), true)
        && !in_array('pkg_librespeed_cli', $rg_ids(bk_rec_rules_gap($rg_in([
            'details' => ['wan_up' => true, 'agent_tools' => ['librespeed_cli' => false]],
            'monitor' => ['wan_probe_enabled' => 0]]), [])), true));
}

// --- Texty 15 nových pravidel v obou jazycích (CORE 6.2 / WAN 3.7) ----------
// Pravidlo bez textu vykreslí své id; tenhle blok je to jediné, co takovou
// chybu najde dřív než čtenář pondělního e-mailu.
if (function_exists('bk_router_rec_render')) {
    $rr_params = [
        // The twelve Wi-Fi ids of CORE 3.7. Each one is rendered in both
        // languages below, so a missing text or an unreplaced placeholder is
        // a failure here and not a blank line in the Monday e-mail.
        'wifi_6ghz_unserved' => ['hw' => 'none', 'capable' => 2, 'known' => 4, 'share' => 90.0,
            'band_id' => '5GHz', 'rate' => 736.8, 'busy' => 3.5, 'wan_variant' => 'fast', 'wan_mbit' => 2500.0],
        'wifi_channel_busy' => ['band_id' => '2.4GHz', 'ch' => 5, 'variant' => 'other',
            'other' => 41.0, 'busy' => 62.0],
        'wifi_noise_high' => ['band_id' => '5GHz', 'noise' => -79.0],
        'wifi_week_degraded' => ['band_id' => '5GHz', 'changes' => [
            ['what' => 'noise', 'from' => -95.0, 'to' => -87.0],
            ['what' => 'busy_other', 'from' => 10.0, 'to' => 31.0]]],
        'wifi_weak_encryption' => ['band_id' => '2.4GHz', 'ch' => 5, 'what' => 'legacy', 'enc' => 'WEP'],
        'wifi_wpa2_only' => ['band_id' => '5GHz', 'ch' => 36],
        'wifi_wpa3_ready' => ['radios' => [['band' => '5GHz', 'ch' => 36]],
            'from' => '2026-09-14', 'to' => '2026-09-20'],
        'wifi_mode_below_card' => ['band_id' => '5GHz', 'ch' => 36, 'cur' => 4, 'best' => 6,
            'htmode' => 'HT80', 'suggested' => 'HE80'],
        'wifi_channel_narrow' => ['band_id' => '5GHz', 'ch' => 36, 'w' => 40, 'suggested' => 'HE80'],
        'wifi_24_wide_channel' => ['band_id' => '2.4GHz', 'ch' => 5, 'w' => 40, 'suggested' => 'HT20'],
        'wifi_weak_client' => ['band_id' => '5GHz', 'ch' => 36, 'signal' => -75.0, 'snr' => 17,
            'gen' => 4, 'noise' => -92.0],
        'wifi_5ghz_clients_on_24' => ['n' => 1.4],
        'wan_link_below_plan' => ['name' => 'eth2', 'mbit' => 1000.0, 'plan' => 2000.0, 'days' => 6],
        'wan_port_errors' => ['name' => 'eth2', 'errors' => 120.0, 'drops' => 5.0, 'days' => 3, 'ring' => 7.0],
        'wan_link_flaps' => ['name' => 'eth2', 'flaps' => 3.0],
        'wan_line_below_plan' => ['dir' => 'dl', 'mbps' => 700.0, 'plan' => 2000.0, 'core' => 20.0,
            'link' => 2500.0, 'agree' => 3, 'span_days' => 3, 'servers' => 2, 'ok_pct' => 85],
        'wan_port_limited' => ['dir' => 'ul', 'mbps' => 2200.0, 'name' => 'eth2', 'link' => 2500.0],
        'wan_sqm_limited' => ['dir' => 'dl', 'mbps' => 85.0, 'sqm' => 85.0, 'plan' => 2000.0],
        'wan_cpu_packet_path' => ['dir' => 'dl', 'mbps' => 900.0, 'core_busy' => 98.0, 'net_share' => 95.0,
            'all_cores' => 52.0, 'agree' => 3, 'tests' => 3, 'link' => 2500.0, 'with_forwarding' => true,
            'steering_off' => true],
        'wan_forwarding_core_saturated' => ['minutes' => 12, 'softirq' => 85.0, 'mbps' => 300.0,
            'steering_off' => true, 'offloading_off' => true],
        'conntrack_drops' => ['drops' => 40.0, 'pct' => 99.0, 'days' => 2, 'evicted' => 18.0],
        'lan_wired_ceiling' => ['plan' => 2000.0, 'cap' => 1000.0],
        'firewall_off' => ['since' => 1758000000],
        'router_restarts' => ['count' => 3, 'last_at' => '2026-09-20 03:00:00', 'unclean' => true],
        'dns_resolver_failing' => ['days' => 3, 'count' => 6],
        'clock_skew' => ['secs' => 21.4],
        'pkg_smartmontools' => ['pkg' => 'smartmontools', 'pkg_manager' => 'opkg'],
        'pkg_smart_drivedb' => ['pkg' => 'smartmontools-drivedb', 'pkg_manager' => 'opkg'],
        'pkg_hostapd_utils' => ['pkg' => 'hostapd-utils', 'pkg_manager' => 'apk'],
        'pkg_iw' => ['pkg' => 'iw', 'pkg_manager' => null],
        'pkg_librespeed_cli' => ['pkg' => 'librespeed-cli', 'pkg_manager' => 'opkg'],
    ];
    $rr_bad = [];
    $rr_same = [];
    foreach ($rr_params as $rr_id => $rr_p) {
        $rr_out = [];
        foreach (['cs', 'en'] as $rr_lang) {
            $rr_out[$rr_lang] = bk_with_email_lang($rr_lang, fn (): array =>
                bk_router_rec_render(bk_rec_item($rr_id, $rr_id, bk_rec_area_of($rr_id), 'info', [], $rr_p)));
            foreach (['title', 'measured', 'action'] as $rr_part) {
                $rr_text = (string)$rr_out[$rr_lang][$rr_part];
                // A literal per-cent sign is fine (`%%` in the source); an
                // UNREPLACED placeholder is the bug this looks for.
                if ($rr_text === '' || $rr_text === $rr_id || preg_match('/%\d*\$?[sd]/', $rr_text)
                    || str_starts_with($rr_text, 'rr_') || str_contains($rr_text, '  ')) {
                    $rr_bad[] = $rr_lang . ':' . $rr_id . ':' . $rr_part;
                }
            }
        }
        if ($rr_out['cs']['measured'] === $rr_out['en']['measured']) {
            $rr_same[] = $rr_id;
        }
    }
    check('každé nové pravidlo má v obou jazycích titulek, měření i akci', $rr_bad, []);
    check('český a anglický text se liší', $rr_same, []);
    // N9 / CORE 7.3: nothing on this card may propose enabling a band the
    // MT7915E cannot serve. The regex runs over every rendered action.
    $rr_band = [];
    foreach ($rr_params as $rr_id => $rr_p) {
        foreach (['cs', 'en'] as $rr_lang) {
            $rr_act = (string)bk_with_email_lang($rr_lang, fn (): array =>
                bk_router_rec_render(bk_rec_item($rr_id, $rr_id, bk_rec_area_of($rr_id), 'info', [], $rr_p)))['action'];
            if (preg_match('/(zapn|enable)[^.]{0,40}(2[,.]4|5) ?GHz/i', $rr_act)) {
                $rr_band[] = $rr_lang . ':' . $rr_id;
            }
        }
    }
    check('žádný text neobsahuje výzvu zapnout 2,4 GHz / 5 GHz', $rr_band, []);
    // CORE 3.7: every rule says WHAT WAS MEASURED, not a bare instruction. A
    // text that loses its number reads like a leaflet and cannot be acted on,
    // so each Wi-Fi sentence is checked against the value it was given.
    $rr_values = [
        'wifi_6ghz_unserved' => ['2 z 4', '90 %'], 'wifi_channel_busy' => ['Kanál 5', '41,0 %', '62,0 %'],
        'wifi_noise_high' => ['-79 dBm'], 'wifi_week_degraded' => ['-95', '-87', '10,0', '31,0'],
        'wifi_weak_encryption' => ['WEP'], 'wifi_wpa2_only' => ['5 GHz, kanál 36'],
        'wifi_wpa3_ready' => ['2026-09-14', '2026-09-20'], 'wifi_mode_below_card' => ['HT80', 'Wi-Fi 4', 'Wi-Fi 6'],
        'wifi_channel_narrow' => ['40 MHz'], 'wifi_24_wide_channel' => ['40 MHz'],
        'wifi_weak_client' => ['-75 dBm', '17 dB', '-92 dBm'], 'wifi_5ghz_clients_on_24' => ['1,4'],
    ];
    $rr_missing = [];
    foreach ($rr_values as $rr_id => $rr_needles) {
        $rr_text = bk_with_email_lang('cs', fn (): array =>
            bk_router_rec_render(bk_rec_item($rr_id, $rr_id, 'wifi', 'info', [], $rr_params[$rr_id])))['measured'];
        foreach ($rr_needles as $rr_needle) {
            if (!str_contains($rr_text, $rr_needle)) {
                $rr_missing[] = $rr_id . ': ' . $rr_needle;
            }
        }
    }
    check('každé pravidlo Wi-Fi říká, co bylo naměřeno', $rr_missing, []);
    // One sentence, one minus sign: bk_rec_num() writes an ASCII one, so a
    // typographic one in the literal text would sit next to it.
    $rr_minus = [];
    foreach (array_keys($rr_values) as $rr_id) {
        foreach (['cs', 'en'] as $rr_lang) {
            $rr_one = bk_with_email_lang($rr_lang, fn (): array =>
                bk_router_rec_render(bk_rec_item($rr_id, $rr_id, 'wifi', 'info', [], $rr_params[$rr_id])));
            if (str_contains(implode(' ', $rr_one), "\u{2212}")) {
                $rr_minus[] = $rr_lang . ':' . $rr_id;
            }
        }
    }
    check('texty píšou mínus stejně jako čísla', $rr_minus, []);
    check('Omnia: zapnutý flow offloading větu o offloadingu nepřidá',
        str_contains((string)bk_router_rec_render(bk_rec_item('wan_forwarding_core_saturated',
            'wan_forwarding_core_saturated', 'wan', 'warning', [],
            ['minutes' => 12, 'softirq' => 85.0, 'mbps' => 300.0, 'offloading_off' => false]))['action'],
            'offloading'), false);
}

// --- bk_wan_test_verdict: jeden test, jeden směr (WAN 3.4) ------------------
// Klasifikátor je jen na serveru: aplikace vykresluje, co dostane. Proto se
// tady zkouší každé pravidlo i každá branka - špatný verdikt by poslal
// majitele reklamovat linku, která je v pořádku.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_wan_dir_inputs', 'bk_wan_core_pinned', 'bk_wan_core_net_share', 'bk_wan_dir_verdict',
    'bk_wan_test_verdict', 'bk_wan_test_countable', 'bk_wan_bottleneck', 'bk_wan_aggregate',
    'bk_wan_agree_numbers', 'bk_wan_line_guards',
]);

if (function_exists('bk_wan_test_verdict')) {
    // Čistá fáze: CPU změřené, cesta ověřená, žádné ztráty ani pozadí.
    $wphase = fn (array $over = []): array => $over + [
        'secs' => 15.0, 'wan_mbps' => 0.0, 'core' => 0, 'core_busy_pct' => 20.0,
        'core_user_pct' => 5.0, 'core_system_pct' => 5.0, 'core_irq_softirq_pct' => 10.0,
        'hot_share' => 0.1, 'all_cores_avg_pct' => 12.0, 'softnet_time_squeeze' => 0.0,
        'softnet_dropped' => 0.0, 'rx_packets' => 1000000.0, 'retrans_pct' => 0.1,
    ];
    $wdiag = fn (array $dl = [], array $over = []): array => $over + [
        'cpu_measured' => true, 'path_verified' => true,
        'background_dl_mbps' => 0.0, 'background_ul_mbps' => 0.0,
        'dl' => $dl, 'ul' => null, 'run' => ['wan_rx_ring_drops' => 0],
    ];
    $wtest = fn (?float $dl, array $diag, array $over = []): array => $over + [
        'id' => 1, 'measured_at' => date('Y-m-d H:i:s'), 'download_mbps' => $dl,
        'upload_mbps' => null, 'link_mbit' => 2500, 'source' => 'agent',
        'server_name' => 'Praha A', 'diagnostics' => $diag,
    ];
    $wdl = fn (array $test, array $ctx = []): array => bk_wan_test_verdict($test, $ctx)['dl'];

    // S = vyšší z obou měření: nástroj počítá i rozjezd, fáze ne.
    $win = bk_wan_dir_inputs($wtest(800.0, $wdiag($wphase(['wan_mbps' => 1000.0]))), $wdiag($wphase(['wan_mbps' => 1000.0])), 'dl', []);
    check('S je vyšší z nástroje a 0,95× fázové rychlosti', round($win['s'], 1), 950.0);
    check('G je 0,93 × rychlost portu', round($win['g'], 1), 2325.0);
    check('bez tarifu není žádný strop', $win['cap'], null);
    check('výchozí podíl tarifu je 85 %', $win['k'], 0.85);

    $wctx = ['plan_down' => 2000, 'plan_up' => 1000];
    $win2 = bk_wan_dir_inputs($wtest(800.0, []), $wdiag($wphase(), ['path' => ['sqm_dl_kbps' => 500000]]), 'dl', $wctx);
    check('strop je menší z tarifu a tvarovače', $win2['cap'], 500.0);
    check('vlastní podíl tarifu se použije',
        bk_wan_dir_inputs([], [], 'dl', ['plan_ok_pct' => 60])['k'], 0.6);
    check('podíl mimo rozsah spadne na 85 %',
        bk_wan_dir_inputs([], [], 'dl', ['plan_ok_pct' => 5])['k'], 0.85);

    // Pravidlo 1: tarif dosažen - nic se nehledá dál.
    $v = $wdl($wtest(1800.0, $wdiag($wphase())), $wctx);
    check('dosažený tarif není úzké hrdlo', [$v['class'], $v['reason']], ['none', 'plan_reached']);
    check_true('u dosaženého tarifu se nehlásí nedostatek CPU', !isset($v['numbers']['no_cpu_headroom']));

    // Stejný výsledek, ale jádro je vytížené: dnes to stačí, rezerva žádná.
    $v = $wdl($wtest(1800.0, $wdiag($wphase(['core_busy_pct' => 96.0, 'core_irq_softirq_pct' => 80.0]))), $wctx);
    check('tarif dosažen s vytíženým jádrem hlásí chybějící rezervu',
        [$v['reason'], $v['numbers']['no_cpu_headroom'] ?? null, $v['numbers']['also'][0] ?? null],
        ['plan_reached', true, 'packet_path']);

    // Pravidlo 2: tvarovač SQM je záměrný strop.
    $v = $wdl($wtest(480.0, $wdiag($wphase(), ['path' => ['sqm_dl_kbps' => 500000]])), $wctx);
    check('rychlost na hraně SQM je záměrný strop', [$v['class'], $v['reason']], ['link_limited', 'sqm_shaper']);

    // Pravidlo 3: port WAN. 0,90 × G = 2092,5 při lince 2500.
    $v = $wdl($wtest(2200.0, $wdiag($wphase())), ['plan_down' => 4000]);
    check('rychlost u stropu portu je limit portu', [$v['class'], $v['reason']], ['link_limited', 'wan_port']);

    // Pravidlo 4: zahlcená síťová cesta v jádře.
    $v = $wdl($wtest(900.0, $wdiag($wphase(['core_busy_pct' => 98.0, 'core_irq_softirq_pct' => 85.0, 'core_user_pct' => 8.0]))), $wctx);
    check('vytížené jádro v softirq je síťová cesta', [$v['class'], $v['reason']], ['cpu_limited', 'packet_path']);
    check('verdikt nese číslo nejvytíženějšího jádra', $v['numbers']['core'], 0);

    // Pravidlo 5: totéž jádro, ale práci dělá klient testu.
    $v = $wdl($wtest(900.0, $wdiag($wphase(['core_busy_pct' => 98.0, 'core_irq_softirq_pct' => 20.0, 'core_user_pct' => 70.0]))), $wctx);
    check('vytížené jádro v uživatelském čase je klient testu', [$v['class'], $v['reason']], ['cpu_limited', 'test_client']);
    $v = $wdl($wtest(900.0, $wdiag($wphase(['hot_share' => 0.6, 'core_irq_softirq_pct' => 20.0, 'core_user_pct' => 20.0]))), $wctx);
    check('jedno jádro nad polovinou práce bez převahy je smíšené', [$v['class'], $v['reason']], ['cpu_limited', 'mixed']);

    // Pravidlo 6: pod tarifem s klidným jádrem a bez ztrát.
    $v = $wdl($wtest(900.0, $wdiag($wphase())), $wctx);
    check('pod tarifem s klidným routerem je limit linka', [$v['class'], $v['reason']], ['line_limited', 'below_plan']);
    check('jednotlivý test o lince má nízkou důvěru', $v['confidence'], 'low');
    $v = $wdl($wtest(900.0, $wdiag($wphase(['retrans_pct' => 2.0]))), $wctx);
    check('retransmise nad procento ukazují na ztráty výš', $v['reason'], 'upstream_loss');

    // ... ale zahozený paket v routeru to zastaví: to není vina linky.
    $v = $wdl($wtest(900.0, $wdiag($wphase(['softnet_dropped' => 3.0]))), $wctx);
    check('zahozené pakety brání verdiktu o lince', $v['class'], 'inconclusive');
    check('bez pojmenovatelné příčiny se důvod nevymýšlí', $v['reason'], null);
    $v = $wdl($wtest(900.0, $wdiag($wphase(), ['run' => ['wan_rx_ring_drops' => 5]])), $wctx);
    check('přetečený kruhový buffer brání verdiktu o lince', $v['class'], 'inconclusive');
}

if (function_exists('bk_wan_test_verdict')) {
    // Branky. Bez výsledku a bez ověřené cesty se neřekne nic; ostatní tři
    // nechají projít jen pravidla 1-3 (dolní mez, která dosáhne stropu, ho
    // dosáhla), nikdy ne „pomalá linka".
    $v = $wdl($wtest(null, $wdiag($wphase())), $wctx);
    check('bez naměřené rychlosti není verdikt', [$v['class'], $v['reason']], ['inconclusive', 'no_result']);
    $v = $wdl($wtest(900.0, $wdiag($wphase(), ['path_verified' => false])), $wctx);
    check('neověřená cesta zastaví i rychlý výsledek', [$v['class'], $v['reason']], ['inconclusive', 'path_unverified']);

    $v = $wdl($wtest(900.0, $wdiag($wphase(), ['background_dl_mbps' => 120.0])), $wctx);
    check('provoz na pozadí zakáže verdikt o lince', [$v['class'], $v['reason']], ['inconclusive', 'background_traffic']);
    $v = $wdl($wtest(1800.0, $wdiag($wphase(), ['background_dl_mbps' => 120.0])), $wctx);
    check('provoz na pozadí nebrání potvrdit dosažený tarif', [$v['class'], $v['reason']], ['none', 'plan_reached']);

    $v = $wdl($wtest(900.0, $wdiag($wphase()), ['source' => 'turris']), $wctx);
    check('test od Turrisu nemá data o CPU', [$v['class'], $v['reason']], ['inconclusive', 'cpu_not_measured']);
    $v = $wdl($wtest(1800.0, $wdiag($wphase()), ['source' => 'turris']), $wctx);
    check('test od Turrisu smí potvrdit dosažený tarif', $v['reason'], 'plan_reached');
    $v = $wdl($wtest(900.0, $wdiag($wphase(['secs' => 4.0]))), $wctx);
    check('fáze kratší než deset sekund se pro CPU nepočítá', $v['reason'], 'cpu_not_measured');
    $v = $wdl($wtest(900.0, $wdiag($wphase(), ['minute_overlap_secs' => 0])), $wctx);
    check('bez překryvu s minutovým během branka nepadne', $v['reason'], 'below_plan');
    $ov = $wphase();
    $ov['minute_overlap_secs'] = 3.0;
    $v = $wdl($wtest(900.0, $wdiag($ov)), $wctx);
    check('minutový běh přes fázi zakáže verdikt o lince', $v['reason'], 'minute_run_overlap');

    // Pravidlo 7.
    $v = $wdl($wtest(900.0, $wdiag($wphase(['core_busy_pct' => 85.0]))), $wctx);
    check('jádro mezi 80 a 90 % je hraniční', [$v['class'], $v['reason']], ['inconclusive', 'cpu_borderline']);
    $v = $wdl($wtest(900.0, $wdiag($wphase())), []);
    check('bez tarifu se o lince nerozhoduje', [$v['class'], $v['reason']], ['inconclusive', 'no_plan_known']);
    check('verdikt nese id testu, o který se opírá', $v['basis'], [1]);
}

// --- bk_wan_bottleneck: shoda posledních sond (WAN 3.4, agregace) -----------
if (function_exists('bk_wan_bottleneck')) {
    $wnow = time();
    $wat = fn (int $days_ago): string => date('Y-m-d H:i:s', $wnow - $days_ago * 86400);
    // Test „pod tarifem, router v klidu" - základ agregace.
    $wrow = function (int $id, int $days_ago, float $dl, string $server, array $over = []) use ($wphase, $wdiag): array {
        return $over + ['id' => $id, 'measured_at' => date('Y-m-d H:i:s', time() - $days_ago * 86400),
            'download_mbps' => $dl, 'upload_mbps' => null, 'link_mbit' => 2500, 'source' => 'agent',
            'server_name' => $server, 'diagnostics' => $wdiag($wphase())];
    };
    $wc = ['plan_down' => 2000, 'plan_up' => 1000, 'now' => $wnow,
        'server_max' => ['Praha A' => ['dl' => 1900.0, 'ul' => null], 'Praha B' => ['dl' => 1900.0, 'ul' => null]]];

    $agg = bk_wan_bottleneck([$wrow(1, 1, 900.0, 'Praha A')], $wc)['dl'];
    check('jedna sonda na verdikt nestačí', [$agg['reason'], $agg['numbers']['needed']], ['not_enough_tests', 1]);

    // Hodnoty leží mimo obě náhorní plošiny (880-950 a 2200-2380 Mbit/s),
    // jinak by je čtvrtá pojistka poslala na `port_plateau` - což je vlastní
    // kontrola o kus níž.
    $three = [$wrow(1, 1, 700.0, 'Praha A'), $wrow(2, 3, 710.0, 'Praha B'), $wrow(3, 5, 705.0, 'Praha A')];
    $agg = bk_wan_bottleneck($three, $wc)['dl'];
    check('tři shodné sondy dají vysokou důvěru',
        [$agg['class'], $agg['reason'], $agg['confidence']], ['line_limited', 'below_plan', 'high']);
    check('agregace jmenuje všechny testy, o které se opírá', $agg['basis'], [1, 2, 3]);

    // Dvě ze tří: střední důvěra.
    $mixed = [$wrow(1, 1, 700.0, 'Praha A'), $wrow(2, 3, 710.0, 'Praha B'),
        $wrow(3, 5, 1900.0, 'Praha A')];
    $agg = bk_wan_bottleneck($mixed, $wc)['dl'];
    check('dvě ze tří shodných dají střední důvěru', [$agg['class'], $agg['confidence']], ['line_limited', 'medium']);

    // Tři různé verdikty: nerozhodnuto, ne „většina".
    // Tři RŮZNÉ verdikty: pod tarifem, tarif dosažen a hraniční jádro. Dvě
    // hodnoty nad tarifem by byly shoda dvou ze tří, ne neshoda.
    $dis = [$wrow(1, 1, 700.0, 'Praha A'), $wrow(2, 3, 1900.0, 'Praha B'),
        $wrow(3, 5, 705.0, 'Praha A', ['diagnostics' => $wdiag($wphase(['core_busy_pct' => 85.0]))])];
    $agg = bk_wan_bottleneck($dis, $wc)['dl'];
    check('tři neshodné sondy nerozhodnou nic', [$agg['class'], $agg['reason']], ['inconclusive', 'tests_disagree']);

    // Turrisovy soubory do agregace nikdy nevstupují.
    $turris = [$wrow(1, 1, 900.0, 'Praha A', ['source' => 'turris']),
        $wrow(2, 3, 910.0, 'Praha B', ['source' => 'turris']),
        $wrow(3, 5, 905.0, 'Praha A', ['source' => 'turris'])];
    $agg = bk_wan_bottleneck($turris, $wc)['dl'];
    check('měření od Turrisu se do agregace nepočítají', $agg['reason'], 'not_enough_tests');

    // Neplatná jednotka: výsledek neznámého rozměru se neprůměruje.
    $bad = $three;
    $bad[0]['diagnostics'] = $wdiag($wphase(), ['unit_mismatch' => true]);
    $agg = bk_wan_bottleneck($bad, $wc)['dl'];
    check('test s neshodou jednotek do agregace nepatří', $agg['numbers']['tests'], 2);

    // Release contract section 4: this is the state of the user's router at
    // release - the probe is wave 2 and no result has ever been stored, so
    // both directions must say so instead of guessing.
    $empty = bk_wan_bottleneck([], $wc);
    check('Omnia: bez jediného měření je verdikt neprůkazný',
        [$empty['dl']['class'], $empty['dl']['reason'], $empty['ul']['class'], $empty['ul']['reason']],
        ['inconclusive', 'not_enough_tests', 'inconclusive', 'not_enough_tests']);
    check('bez měření karta ví, kolik jich ještě chybí', $empty['dl']['numbers']['needed'], 2);

    // X27: a probe measured while an ordinary minute run was collecting does
    // not count towards the three. Without this the aggregate would stand on
    // a rate the router's own telemetry was competing with.
    $overlap = $three;
    $ov3 = $wphase();
    $ov3['minute_overlap_secs'] = 4.0;
    $overlap[2]['diagnostics'] = $wdiag($ov3);
    check('test rušený minutovým během se do verdiktu nepočítá',
        bk_wan_bottleneck($overlap, $wc)['dl']['numbers']['tests'], 2);

    // Starší než 21 dní.
    $old = [$wrow(1, 1, 900.0, 'Praha A'), $wrow(2, 25, 910.0, 'Praha B'), $wrow(3, 30, 905.0, 'Praha A')];
    $agg = bk_wan_bottleneck($old, $wc)['dl'];
    check('sonda starší tří týdnů se nepočítá', $agg['reason'], 'not_enough_tests');
}

// --- Čtyři pojistky verdiktu „limit je linka" (WAN 3.4) ---------------------
// Shoda sama o sobě nedokazuje, že limituje linka: dva servery můžou narazit
// do stejného stropu, který linka není.
if (function_exists('bk_wan_bottleneck')) {
    $one = [$wrow(1, 1, 900.0, 'Praha A'), $wrow(2, 3, 910.0, 'Praha A'), $wrow(3, 5, 905.0, 'Praha A')];
    $agg = bk_wan_bottleneck($one, $wc)['dl'];
    check('jediný testovací server na verdikt o lince nestačí', $agg['reason'], 'single_server');

    $sameday = [$wrow(1, 1, 900.0, 'Praha A'), $wrow(2, 1, 910.0, 'Praha B')];
    $agg = bk_wan_bottleneck($sameday, $wc)['dl'];
    check('dvě měření z jednoho dne nejsou dvě pozorování', $agg['reason'], 'not_enough_tests');

    $far = [$wrow(1, 1, 500.0, 'Praha A'), $wrow(2, 3, 900.0, 'Praha B'), $wrow(3, 5, 505.0, 'Praha A')];
    $agg = bk_wan_bottleneck($far, $wc)['dl'];
    check('servery lišící se o víc než 15 % měřily něco jiného', $agg['reason'], 'server_limited');
    check('rychlejší server je pak dolní mez linky', $agg['numbers']['s_lower_bound_mbps'], 900.0);

    $unproven = $wc;
    $unproven['server_max'] = ['Praha A' => ['dl' => 940.0, 'ul' => null], 'Praha B' => ['dl' => 940.0, 'ul' => null]];
    $agg = bk_wan_bottleneck([$wrow(1, 1, 900.0, 'Praha A'), $wrow(2, 3, 910.0, 'Praha B'),
        $wrow(3, 5, 905.0, 'Praha A')], $unproven)['dl'];
    check('server, který stropu nikdy nedosáhl, verdikt neunese', $agg['reason'], 'server_capacity_unproven');
    check('pojistka řekne, jaký strop se nepodařilo doložit', $agg['numbers']['cap_mbit'], 2000.0);

    // Plató gigabitového portu pod tarifem: v cestě je něco s pomalejším portem.
    $plateau = $wc;
    $plateau['server_max'] = ['Praha A' => ['dl' => 2000.0, 'ul' => null], 'Praha B' => ['dl' => 2000.0, 'ul' => null]];
    $agg = bk_wan_bottleneck([$wrow(1, 1, 930.0, 'Praha A'), $wrow(2, 3, 940.0, 'Praha B'),
        $wrow(3, 5, 935.0, 'Praha A')], $plateau)['dl'];
    check('plató gigabitového portu pod tarifem nesvádí na linku', $agg['reason'], 'port_plateau');

    // A když projdou všechny čtyři, verdikt padne.
    $agg = bk_wan_bottleneck([$wrow(1, 1, 700.0, 'Praha A'), $wrow(2, 3, 710.0, 'Praha B'),
        $wrow(3, 5, 705.0, 'Praha A')], $plateau)['dl'];
    check('po všech pojistkách verdikt o lince padne',
        [$agg['class'], $agg['reason'], $agg['confidence']], ['line_limited', 'below_plan', 'high']);
    check('agregace uvádí rozptyl dní a počet serverů',
        [$agg['numbers']['span_days'], $agg['numbers']['servers']], [3, 2]);
}

// --- Doporučení na drát: příkaz, chybějící balíčky, oblast, tvar (CORE 3.9) --
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_rec_install_cmd', 'bk_rec_command', 'bk_rec_missing_packages', 'bk_rec_area_of', 'bk_rec_item_json',
]);

if (function_exists('bk_rec_command')) {
    check('rostoucí chyby disku nabídnou dlouhý test',
        bk_rec_command(['id' => 'disk_errors_growing', 'params' => ['name' => 'sda']]),
        'smartctl -t long /dev/sda');
    check('nikdy nespuštěný test nabídne krátký',
        bk_rec_command(['id' => 'disk_selftest_never', 'params' => ['name' => 'mmcblk0']]),
        'smartctl -t short /dev/mmcblk0');
    check('chybějící balíček nabídne instalaci podle správce balíčků',
        bk_rec_command(['id' => 'pkg_librespeed_cli', 'params' => ['pkg_manager' => 'opkg']]),
        bk_rec_install_cmd('opkg', 'librespeed-cli'));
    check('pravidlo bez příkazu žádný nevymýšlí',
        bk_rec_command(['id' => 'disk_temp_warm', 'params' => ['name' => 'sda']]), null);
    check('podezřelé jméno zařízení se do příkazu nedostane',
        bk_rec_command(['id' => 'disk_selftest_never', 'params' => ['name' => 'sda; rm -rf /']]), null);

    check('chybí jen to, co agent opravdu hledal a nenašel',
        bk_rec_missing_packages(['smartctl' => true, 'iw' => false, 'ethtool' => false, 'tc' => true]),
        ['iw', 'ethtool']);
    check('bez objektu nástrojů se nic netvrdí', bk_rec_missing_packages(null), []);

    $areas = [];
    foreach (bk_router_rec_thresholds()['rank'] as $rid) {
        $areas[bk_rec_area_of($rid)] = true;
    }
    check('každé id pravidla má svou oblast', count($areas), 6);
    check('oblast disku i souborového systému je úložiště',
        [bk_rec_area_of('disk_temp_warm'), bk_rec_area_of('fs_nearly_full')], ['storage', 'storage']);
    check('conntrack a strop LAN patří k WAN',
        [bk_rec_area_of('conntrack_drops'), bk_rec_area_of('lan_wired_ceiling')], ['wan', 'wan']);

    $json = bk_rec_item_json([
        'id' => 'disk_temp_warm', 'key' => 'disk_temp_warm:d:abc', 'area' => 'storage',
        'severity' => 'warning', 'subject' => ['kind' => 'disk', 'disk_key' => 'abc', 'name' => 'sda'],
        'params' => [], 'openSince' => '2026-09-01',
    ], ['muted_at' => '2026-09-02 10:00:00', 'muted_by' => 'pepe', 'mute_reason' => '', 'muted_severity' => 'info'], false);
    check('klíč disku jde na drát v camelCase', $json['subject']['diskKey'], 'abc');
    check('ztlumená položka, která už nehoří, nemá texty',
        [$json['title'], $json['measured'], $json['action'], $json['active']], [null, null, null, false]);
    check('prázdný důvod ztlumení je null, ne prázdný řetězec', $json['mute']['reason'], null);
    check('ztlumení si pamatuje závažnost, při které vzniklo', $json['mute']['severity'], 'info');
}

// --- Daily reminder: what counts as broken --------------------------------
// The feature exists because of a four-day outage nobody was told about: the
// alert goes out on a CHANGE of state and then never again. These tests guard
// the one decision the whole reminder rests on - what gets into it, and what
// must never, because a message that cries wolf daily is a message nobody reads.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_daily_reminder_due', 'bk_daily_reminder_select',
    'bk_get_collection_issues', 'bk_disk_label',
]);

if (function_exists('bk_daily_reminder_select')) {
    // Relative to the real clock, not a fixed date: the selection asks
    // bk_get_collection_issues(), which reads time() itself (it is shared with
    // the app and the public page). With a frozen "now" the fixtures would age
    // against the real clock and a suite that passed at nine would fail at noon.
    $dr_now = time();
    $dr_at = fn (int $secs_ago): string => date('Y-m-d H:i:s', $dr_now - $secs_ago);
    $dr_monitor = fn (array $over): array => array_merge([
        'id' => 1, 'name' => 'Web', 'type' => 'web', 'status' => 'up',
        'last_checked' => $dr_at(60), 'last_status_change' => $dr_at(3600),
        'maintenance' => 0, 'archived_at' => null, 'details' => [], 'last_error' => null,
    ], $over);
    // The message texts come from lang/, so the selection runs inside the
    // e-mail language exactly as the cron does.
    $dr_select = fn (array $monitors, array $incidents = [], ?string $cron = null): array =>
        bk_with_email_lang('cs', fn (): array => bk_daily_reminder_select($monitors, $incidents, $cron, 3000, 900, $dr_now));

    // Nothing wrong = nothing to send. Without this the reminder would arrive
    // every morning saying "all good" and be filtered within a week.
    $dr_ok = $dr_select([$dr_monitor([])]);
    check('zdravý systém nemá co hlásit', $dr_ok['problem_count'], 0);
    check('a ani jednu sekci', [count($dr_ok['outages']), count($dr_ok['silent']), count($dr_ok['incidents'])], [0, 0, 0]);

    $dr_down = $dr_select([$dr_monitor([
        'status' => 'down', 'last_status_change' => $dr_at(4 * 86400), 'last_error' => 'HTTP 502',
    ])]);
    check('výpadek se hlásí', count($dr_down['outages']), 1);
    check('i s uloženým důvodem', $dr_down['outages'][0]['reason'], 'HTTP 502');
    check('a s délkou trvání', $dr_down['outages'][0]['duration_secs'], 4 * 86400);
    check_false('výpadek není varování', $dr_down['outages'][0]['warning']);

    // A slow service and an expiring certificate are warnings, not outages -
    // the same classification the alert e-mail uses.
    $dr_warn = $dr_select([$dr_monitor(['status' => 'latency_degraded'])]);
    check_true('zhoršená odezva se hlásí jako varování', $dr_warn['outages'][0]['warning'] ?? false);

    // Longest first: the four-day outage must not end up under the ten-minute one.
    $dr_order = $dr_select([
        $dr_monitor(['id' => 1, 'name' => 'Krátký', 'status' => 'down', 'last_status_change' => $dr_at(600)]),
        $dr_monitor(['id' => 2, 'name' => 'Dlouhý', 'status' => 'down', 'last_status_change' => $dr_at(4 * 86400)]),
        $dr_monitor(['id' => 3, 'name' => 'Neznámý', 'status' => 'down', 'last_status_change' => null]),
    ]);
    check('nejdéle trvající výpadek je první',
        array_column($dr_order['outages'], 'name'), ['Dlouhý', 'Krátký', 'Neznámý']);
    check('neznámá délka se nevymýšlí', $dr_order['outages'][2]['duration_secs'], null);

    // Maintenance is a planned outage: announcing it daily would teach the
    // reader that the message is usually about nothing.
    check('monitor v údržbě se nehlásí',
        $dr_select([$dr_monitor(['status' => 'down', 'maintenance' => 1])])['problem_count'], 0);
    check('ani stav maintenance',
        $dr_select([$dr_monitor(['status' => 'maintenance'])])['problem_count'], 0);
    // Archived = out of service for good; trigger_notifications() ignores it too.
    check('archivovaný monitor se nehlásí',
        $dr_select([$dr_monitor(['status' => 'down', 'archived_at' => $dr_at(86400)])])['problem_count'], 0);
    check('nezměřený a pozastavený monitor není porucha', [
        $dr_select([$dr_monitor(['status' => 'unknown', 'last_checked' => null])])['problem_count'],
        $dr_select([$dr_monitor(['status' => 'paused', 'last_checked' => null])])['problem_count'],
    ], [0, 0]);

    // The case that started the whole feature: nothing crashed, the data just
    // stopped arriving. It has its own section - merged into the outages it
    // would be buried among them again.
    $dr_silent = $dr_select([$dr_monitor([
        'type' => 'vps', 'status' => 'up', 'details' => ['agent_last_seen' => $dr_now - 7200],
    ])]);
    check('mlčící agent se hlásí', count($dr_silent['silent']), 1);
    check('a NE mezi výpadky', count($dr_silent['outages']), 0);
    check('sekce zná svůj druh problému', $dr_silent['silent'][0]['issue'], 'agent_silent');

    // A heartbeat past interval + grace stopped reporting - the silent half.
    $dr_hb = $dr_select([$dr_monitor([
        'type' => 'heartbeat', 'status' => 'down', 'last_checked' => null, 'last_status_change' => $dr_at(7200),
        'heartbeat_interval' => 3600, 'heartbeat_grace' => 300, 'last_heartbeat' => $dr_at(7200),
    ])]);
    check('heartbeat po lhůtě je tichý sběr', array_column($dr_hb['silent'], 'issue'), ['heartbeat_overdue']);
    check('a nepočítá se zároveň jako výpadek', count($dr_hb['outages']), 0);
    // A job that reported its own failure DID fail - that belongs to the outages.
    $dr_hb_fail = $dr_select([$dr_monitor([
        'type' => 'heartbeat', 'status' => 'down', 'last_checked' => null,
        'heartbeat_interval' => 3600, 'heartbeat_grace' => 300, 'last_heartbeat' => $dr_at(120),
        'heartbeat_last_result' => 'fail', 'last_error' => 'Záloha skončila chybou',
    ])]);
    check('ohlášené selhání úlohy je výpadek, ne ticho',
        [count($dr_hb_fail['outages']), count($dr_hb_fail['silent'])], [1, 0]);

    // Two rules, one monitor: the heartbeat is past its grace period AND the
    // checks stopped running (this is how it looked in the e2e message -
    // "Zaloha NAS" twice, counted as two problems). One subject, one row, and
    // the second finding stays readable under the first.
    $dr_dup = $dr_select([$dr_monitor([
        'id' => 7, 'name' => 'Záloha NAS', 'type' => 'heartbeat', 'status' => 'down',
        'last_checked' => $dr_at(300 * 60), 'last_status_change' => $dr_at(7200),
        'heartbeat_interval' => 3600, 'heartbeat_grace' => 300, 'last_heartbeat' => $dr_at(18000),
    ])]);
    check('jeden monitor je v tichém sběru jednou, i když ho našla dvě pravidla',
        [count($dr_dup['silent']), $dr_dup['problem_count']], [1, 1]);
    check('hlavní nález je ten od heartbeatu', $dr_dup['silent'][0]['issue'], 'heartbeat_overdue');
    check('a druhý nález se neztratil, jen nepočítá znovu',
        array_column($dr_dup['silent'][0]['also'], 'issue'), ['checks_stalled']);
    check_true('text druhého nálezu zůstal celý',
        str_contains($dr_dup['silent'][0]['also'][0]['message'] ?? '', '300'));
    // Řádek si nechává to delší ticho - kratší věk by poruchu zlehčil.
    check('řádek hlásí delší z obou dob ticha', $dr_dup['silent'][0]['age_secs'], 300 * 60);

    // The same rule for two collection issues on one monitor.
    $dr_dup2 = $dr_select([$dr_monitor([
        'id' => 8, 'name' => 'VPS', 'type' => 'vps', 'status' => 'up', 'last_checked' => $dr_at(60),
        'details' => [
            'agent_last_seen' => $dr_now - 7200,
            'cpanel_stats_error' => ['error' => 'HTTP 403', 'since' => $dr_at(9000)],
        ],
    ])]);
    check('dva výpadky sběru na jednom monitoru jsou jeden problém',
        [count($dr_dup2['silent']), count($dr_dup2['silent'][0]['also']), $dr_dup2['problem_count']], [1, 1, 1]);

    // Two monitors stay two problems - the deduplication is per monitor, not per section.
    $dr_two = $dr_select([
        $dr_monitor(['id' => 1, 'name' => 'A', 'type' => 'vps', 'details' => ['agent_last_seen' => $dr_now - 7200]]),
        $dr_monitor(['id' => 2, 'name' => 'B', 'type' => 'vps', 'details' => ['agent_last_seen' => $dr_now - 7200]]),
    ]);
    check('dva mlčící monitory jsou dva problémy',
        [count($dr_two['silent']), $dr_two['problem_count']], [2, 2]);

    // Unacknowledged incidents, oldest first.
    $dr_inc = $dr_select([$dr_monitor([])], [
        ['id' => 5, 'title' => 'Nový', 'impact' => 'minor', 'status' => 'investigating', 'created_at' => $dr_at(600), 'monitor_name' => null],
        ['id' => 4, 'title' => 'Starý', 'impact' => 'major', 'status' => 'identified', 'created_at' => $dr_at(86400), 'monitor_name' => 'Web'],
    ]);
    check('nepřevzaté incidenty se hlásí od nejstaršího',
        array_column($dr_inc['incidents'], 'title'), ['Starý', 'Nový']);
    check('a znají svůj věk', $dr_inc['incidents'][0]['age_secs'], 86400);
    // A healthy monitor plus two incidents: the reminder goes out for the
    // incidents alone, nothing else is needed.
    check('incidenty se počítají mezi problémy', $dr_inc['problem_count'], 2);

    // The collection stamp is context, never a reason to send: this code runs
    // FROM cron, so "the collector is late" alone would be a message from the
    // machine proving it runs.
    $dr_cron = $dr_select([$dr_monitor([])], [], $dr_at(4 * 3600));
    check('starý běh sběru sám o sobě zprávu nevyvolá', $dr_cron['problem_count'], 0);
    check_true('ale je označený jako zastaralý', $dr_cron['cron']['stale']);
    check('a ví, jak je starý', $dr_cron['cron']['age_secs'], 4 * 3600);
    check_true('žádný dokončený běh = taky zastaralý', $dr_select([$dr_monitor([])], [], null)['cron']['stale']);
    check('čerstvý běh zastaralý není',
        $dr_select([$dr_monitor([])], [], $dr_at(120))['cron']['stale'], false);
    // The limit is the collection watchdog's own (collection_max_age_secs), not
    // a second number invented here: the e-mail and the app must not disagree
    // about whether collection is alive.
    check('hranice zastaralosti se řídí nastavením hlídače', bk_with_email_lang('cs', fn (): bool =>
        bk_daily_reminder_select([$dr_monitor([])], [], $dr_at(1200), 3000, 1800, $dr_now)['cron']['stale']), false);
}

// --- Daily reminder: the once-a-day guard ---------------------------------
// The router's cron runs every minute. Without the stamp the reminder would
// go out nine hundred times a day, and the next real one would be filtered.
if (function_exists('bk_daily_reminder_due')) {
    $dr_day = mktime(8, 0, 0, 9, 21, 2026);
    check_false('před nastavenou hodinou se nic neposílá', bk_daily_reminder_due('', 8, $dr_day - 60));
    check_true('od nastavené hodiny ano', bk_daily_reminder_due('', 8, $dr_day));
    check_false('podruhé týž den už ne', bk_daily_reminder_due('2026-09-21', 8, $dr_day + 3600));
    check_true('a další den zase ano', bk_daily_reminder_due('2026-09-21', 8, $dr_day + 86400));
    check_true('pozdě večer se dnešek ještě dožene', bk_daily_reminder_due('2026-09-20', 8, $dr_day + 14 * 3600));
    // A nonsensical hour must not silence the reminder forever - it falls back
    // to the documented default, the same one get_setting() hands out.
    check_true('nesmyslná hodina spadne na výchozích 8:00', bk_daily_reminder_due('', 99, $dr_day));
    check_false('a před osmou pak taky mlčí', bk_daily_reminder_due('', -5, $dr_day - 60));

    // A whole day of a per-minute cron sends exactly once.
    $dr_last = '';
    $dr_sends = 0;
    for ($dr_min = 0; $dr_min < 48 * 60; $dr_min++) {
        $dr_ts = mktime(0, 0, 0, 9, 21, 2026) + $dr_min * 60;
        if (bk_daily_reminder_due($dr_last, 8, $dr_ts)) {
            $dr_sends++;
            $dr_last = date('Y-m-d', $dr_ts);
        }
    }
    check('cron každou minutu odešle za dva dny právě dvakrát', $dr_sends, 2);
}


// --- Přejímka na routeru majitele (INDEX 4, CORE 7.2-7.4) -------------------
// Celý engine nad fixture, ne jedna skupina pravidel: tohle je seznam, který
// majitel dostane v pondělním e-mailu. „Nespustilo se" musí znamenat, že
// pravidlo hodnotu VIDĚLO a řeklo ne - ne že chybí.
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_router_rec_evaluate', 'bk_rec_rules_disk_health', 'bk_rec_rules_disk_week',
    'bk_rec_rules_filesystems', 'bk_rec_rules_wifi', 'bk_rec_rules_wan_week',
    'bk_rec_rules_wan_tests', 'bk_rec_rules_gap', 'bk_rec_daily_value', 'bk_disk_temp_limit',
    'bk_disk_label', 'bk_disk_error_counters', 'bk_rec_disk_label', 'bk_rec_week_days_over',
    'bk_router_rec_split',
]);

if (function_exists('bk_router_rec_evaluate') && is_readable(__DIR__ . '/fixtures/omnia_router.php')) {
    $ac = require __DIR__ . '/fixtures/omnia_router.php';
    $ac_win = bk_router_rec_window(date('Y-m-d'));
    // The fixture's one day of metrics_daily, repeated over the whole week.
    $ac_metrics = [];
    foreach ($ac['week']['metrics'] as $ac_key => $ac_vals) {
        foreach ($ac_win['days'] as $ac_day) {
            $ac_metrics[$ac_key][$ac_day] = ['min' => $ac_vals[0], 'avg' => $ac_vals[1],
                'max' => $ac_vals[2], 'samples' => $ac['week']['samples']];
        }
    }
    $ac_win['metrics'] = $ac_metrics;
    // The disk as the server stores it: the payload plus the derived key.
    $ac_details = $ac['payload'];
    $ac_details['storage_disks'][0]['key'] = $ac['disk_key'];
    $ac_details['agent_version'] = '0.1.7';
    $ac_daily = [];
    foreach ($ac_win['days'] as $ac_i => $ac_day) {
        // power_on_hours is the LAST day's value; a day back is 24 hours less.
        $ac_daily[$ac_day] = ['day' => $ac_day] + $ac['disk_day'];
        $ac_daily[$ac_day]['power_on_hours'] = $ac['disk_day']['power_on_hours']
            - (count($ac_win['days']) - 1 - $ac_i) * 24;
    }
    $ac_run = fn (array $details, array $state = []): array => bk_router_rec_evaluate([
        'monitor' => ['id' => 6, 'type' => 'openwrt'],
        'details' => $details,
        'window' => $ac_win,
        'disks' => [$ac['disk_key'] => ['row' => ['disk_key' => $ac['disk_key']], 'daily' => $ac_daily]],
        'state' => $state,
        'now' => time(),
    ]);
    $ac_res = $ac_run($ac_details);
    $ac_ids = array_values(array_map(fn (array $i): string => (string)$i['id'], $ac_res['items']));
    $ac_sev = [];
    foreach ($ac_res['items'] as $ac_item) {
        $ac_sev[(string)$ac_item['id']] = (string)$ac_item['severity'];
    }
    $ac_of = function (string $id) use ($ac_res): ?array {
        foreach ($ac_res['items'] as $item) {
            if ((string)$item['id'] === $id) {
                return $item;
            }
        }
        return null;
    };
    $ac_text = fn (string $id, string $lang = 'cs'): array => bk_with_email_lang($lang,
        fn (): array => bk_router_rec_render((array)$ac_of($id)));

    check_true('Omnia: doporučení se vůbec vyhodnocují', $ac_res['applicable'] === true && $ac_res['reason'] === null);
    // F1-F5 and their order (CORE 7.2): severity, then area, then rank -
    // NOT the alphabetical order of the keys, which would give F2, F4, F3.
    // `wifi_wpa3_ready` stands between F1 and F5 because the seeded week
    // already IS the clean week of CORE 7.4 V1 (`wifi_wpa2_clients` max 0 on
    // all seven days, 1,440 samples each, four clients) - the variant needs no
    // change to the fixture. Its rank puts it after F1 and before F5.
    check('Omnia: F1-F5 se spustí právě jednou a ve správném pořadí', $ac_ids,
        ['disk_temp_warm', 'disk_unclean_shutdowns', 'disk_selftest_never',
            'wifi_6ghz_unserved', 'wifi_wpa3_ready', 'wifi_weak_client']);
    check('Omnia: F2 je varování, ostatní informace', $ac_sev,
        ['disk_temp_warm' => 'warning', 'disk_unclean_shutdowns' => 'info',
            'disk_selftest_never' => 'info', 'wifi_6ghz_unserved' => 'info',
            'wifi_wpa3_ready' => 'info', 'wifi_weak_client' => 'info']);

    // N1-N6, N8: silent because the rule LOOKED. A rule that is missing or
    // that could not be evaluated would pass a bare "is not in the list", so
    // both halves are asserted: not fired AND not in `not_evaluated`.
    $ac_evaluated_no = function (array $ids) use ($ac_res, $ac_sev): array {
        $bad = [];
        foreach ($ids as $id) {
            if (isset($ac_sev[$id])) {
                $bad[] = $id . ': spustilo se';
            } elseif (in_array($id, $ac_res['not_evaluated'], true)) {
                $bad[] = $id . ': nevyhodnoceno';
            }
        }
        return $bad;
    };
    check('Omnia: N1, N2 - kanál ani šum se neozvou, protože 3,5 % a −92 dBm změřeny byly',
        $ac_evaluated_no(['wifi_channel_busy', 'wifi_noise_high']), []);
    check('Omnia: N3-N5 - smíšené WPA2/WPA3, HE80 a karta na HE nedávají nález',
        $ac_evaluated_no(['wifi_wpa2_only', 'wifi_channel_narrow', 'wifi_24_wide_channel',
            'wifi_mode_below_card']), []);
    check('Omnia: N6 - SMART prošel, 183 stojí na 3, opotřebení 0 %',
        $ac_evaluated_no(['disk_smart_failing', 'disk_errors_growing', 'disk_wear_high',
            'disk_wear_fast', 'disk_heavy_writes', 'disk_smart_unreadable']), []);
    check('Omnia: N8 - jediné pásmo, 19 % zaplnění, WPA2/WPA3 a beze změny prostředí',
        $ac_evaluated_no(['wifi_5ghz_clients_on_24', 'fs_nearly_full', 'wifi_weak_encryption',
            'wifi_week_degraded']), []);
    check('Omnia: N7 - všechny nástroje jsou nainstalované',
        $ac_evaluated_no(['pkg_smartmontools', 'pkg_smart_drivedb', 'pkg_hostapd_utils', 'pkg_iw']), []);

    // F1: the measured sentence names 2 of 4 clients and the 90 % share, the
    // action quotes the 736.8 Mbit/s link rate and the 3.5 % airtime, carries
    // the 2500 Mbit/s WAN sentence with its caveat and invites a mute.
    $ac_f1 = $ac_text('wifi_6ghz_unserved');
    check_true('Omnia: F1 měření jmenuje 2 ze 4 klientů a 90 % času',
        str_contains($ac_f1['measured'], '2 z 4') && str_contains($ac_f1['measured'], '90 %'));
    check_true('Omnia: F1 říká, že žádné rádio 6 GHz nenabízí',
        str_contains($ac_f1['measured'], 'kanály 6 GHz nenabízí'));
    check_true('Omnia: F1 cituje 736,8 Mbit/s a 3,5 % obsazenosti',
        str_contains($ac_f1['action'], '736,8') && str_contains($ac_f1['action'], '3,5 %'));
    check_true('Omnia: F1 nese větu o WAN 2500 Mbit/s i s výhradou o procesoru a lince',
        str_contains($ac_f1['action'], '2500 Mbit/s')
        && str_contains($ac_f1['action'], 'procesoru routeru nebo v samotné lince'));
    check_true('Omnia: F1 zmíní WPA3 na 6 GHz, kratší dosah a nabídne ztlumení',
        str_contains($ac_f1['action'], 'WPA3') && str_contains($ac_f1['action'], 'kratší dosah')
        && str_contains($ac_f1['action'], 'ztlumte'));
    check_false('Omnia: F1 neslibuje, co zapnout - karta 6 GHz nemá',
        str_contains($ac_f1['action'], 'Přidejte síť na 6 GHz'));

    // F5: information, not a fault. It names the signal, the SNR, the client's
    // generation and the band's noise, and it never offers another band on a
    // card that cannot serve one (N9).
    $ac_f5 = $ac_text('wifi_weak_client');
    check_true('Omnia: F5 jmenuje -75 dBm, odstup 17 dB a Wi-Fi 4',
        str_contains($ac_f5['measured'], '-75 dBm') && str_contains($ac_f5['measured'], 'odstup od šumu 17 dB')
        && str_contains($ac_f5['measured'], 'umí jen Wi-Fi 4'));
    check_true('Omnia: F5 nazve šum -92 dBm v pořádku a mluví o vzdálenosti',
        str_contains($ac_f5['measured'], '-92 dBm') && str_contains($ac_f5['measured'], 'v pořádku')
        && str_contains($ac_f5['measured'], 'o vzdálenost'));
    check_true('Omnia: F5 je podaná jako informace a 6 GHz podle ní nepomůže',
        str_contains($ac_f5['action'], 'informace, ne závada')
        && str_contains($ac_f5['action'], '6 GHz tomuto zařízení nepomůže'));
    check('Omnia: N9 - žádný text F1 ani F5 nenavrhuje zapnout jiné pásmo karty',
        array_values(array_filter([$ac_f1['action'], $ac_f5['action'], $ac_f1['measured'], $ac_f5['measured']],
            fn (string $txt): bool => (bool)preg_match('/(zapn|enable)[^.]{0,40}(2[,.]4|5) ?GHz/i', $txt))), []);
    check_true('Omnia: anglický text F5 je jiný a stejně konkrétní',
        str_contains($ac_text('wifi_weak_client', 'en')['measured'], '17 dB above the noise')
        && $ac_text('wifi_weak_client', 'en')['measured'] !== $ac_f5['measured']);

    // V3 / V4 / V13 / V14: the two thresholds that keep the Monday e-mail from
    // flapping. A share that fell to 0.30 keeps a RUNNING item; the same 0.30
    // never starts one.
    $ac_share = function (float $share) use ($ac_win, $ac_metrics): array {
        $win = $ac_win;
        $win['metrics'] = $ac_metrics;
        foreach ($win['days'] as $day) {
            $win['metrics']['wifi_6e_unserved'][$day] = ['min' => 0, 'avg' => $share, 'max' => 1, 'samples' => 1440];
        }
        return $win;
    };
    $ac_wifi = function (array $win, array $state) use ($ac_details): array {
        $res = bk_rec_rules_wifi(['window' => $win, 'details' => $ac_details,
            'monitor' => ['id' => 6, 'type' => 'openwrt'], 'now' => time()], $state);
        return array_map(fn (array $i): string => (string)$i['id'], $res['items']);
    };
    $ac_active = fn (string $key): array => [$key => ['active' => 1, 'severity' => 'info']];
    check('Omnia: V3 klienti na jiném AP (0,30) běžící F1 neukončí, 0,20 ano',
        [in_array('wifi_6ghz_unserved', $ac_wifi($ac_share(0.30), $ac_active('wifi_6ghz_unserved')), true),
            in_array('wifi_6ghz_unserved', $ac_wifi($ac_share(0.20), $ac_active('wifi_6ghz_unserved')), true)],
        [true, false]);
    check_false('Omnia: V4 jediný večer (0,30) F1 nezačne',
        in_array('wifi_6ghz_unserved', $ac_wifi($ac_share(0.30), []), true));
    // V13 / V14: the real weak-client week. The client sits exactly ON the
    // -75 dBm boundary, so the weekly mean hovers around 0.5 (REAL_FACTS).
    $ac_weak = function (float $mean) use ($ac_win, $ac_metrics): array {
        $win = $ac_win;
        $win['metrics'] = $ac_metrics;
        foreach ($win['days'] as $day) {
            $win['metrics']['wifi_weak_clients'][$day] = ['min' => 0, 'avg' => $mean, 'max' => 1, 'samples' => 1440];
        }
        return $win;
    };
    check('Omnia: V13 týden s průměrem 0,45 drží F5, V14 bez uloženého nálezu nezačne',
        [in_array('wifi_weak_client', $ac_wifi($ac_weak(0.45), $ac_active('wifi_weak_client')), true),
            in_array('wifi_weak_client', $ac_wifi($ac_weak(0.45), []), true),
            in_array('wifi_weak_client', $ac_wifi($ac_weak(0.20), $ac_active('wifi_weak_client')), true)],
        [true, false, false]);

    // V5 / V6: the mute path of a Wi-Fi item. The key is what the API stores
    // and what the engine produces the next week, so both are checked at once.
    $ac_mute_state = ['wifi_6ghz_unserved' => ['muted_at' => '2026-06-01 09:00:00',
        'muted_by' => 'pepe', 'muted_severity' => 'info',
        'mute_reason' => '6 GHz obsluhuje jiný přístupový bod', 'first_seen' => '2026-05-25 09:00:00']];
    $ac_split = bk_router_rec_split($ac_res['items'], $ac_mute_state);
    check('Omnia: V5 ztlumené F1 zmizí ze seznamu a zůstane s důvodem v ztlumených',
        [array_map(fn (array $i): string => (string)$i['id'], $ac_split['muted']),
            in_array('wifi_6ghz_unserved', array_map(fn (array $i): string => (string)$i['id'], $ac_split['items']), true),
            (string)$ac_split['muted'][0]['muteReason']],
        [['wifi_6ghz_unserved'], false, '6 GHz obsluhuje jiný přístupový bod']);
    check('Omnia: klíče Wi-Fi položek projdou kontrolou ztlumení v API',
        array_values(array_filter(array_map(fn (array $i): string => (string)$i['key'], $ac_res['items']),
            fn (string $k): bool => !preg_match('/^[a-z0-9_]{3,40}(:[A-Za-z0-9._:-]{1,39})?$/', $k)
                || !in_array(explode(':', $k, 2)[0], bk_router_rec_thresholds()['rank'], true))), []);
    // V6: a 6 GHz radio of the router's own ends F1 at once - the share is 0
    // by construction, and the rule is EVALUATED, not skipped.
    $ac_with6 = $ac_details;
    $ac_with6['wifi_radios'][] = ['radio' => 'wlan6', 'ssid' => 'Domov 6 GHz', 'mode' => 'ap',
        'band' => '6GHz', 'frequency_mhz' => 5955, 'channel' => 1, 'htmode' => 'HE80',
        'htmodes_supported' => ['HE20', 'HE40', 'HE80'], 'phy_has_6ghz' => true,
        'encryption' => 'wpa2_wpa3', 'encryption_enterprise' => false, 'clients' => 2,
        'clients_caps_known' => 2, 'clients_6ghz_capable' => 2];
    $ac_res6 = $ac_run($ac_with6, $ac_active('wifi_6ghz_unserved'));
    check('Omnia: V6 vlastní rádio na 6 GHz F1 ukončí a nezůstane nevyhodnocené',
        [in_array('wifi_6ghz_unserved', array_map(fn (array $i): string => (string)$i['id'], $ac_res6['items']), true),
            in_array('wifi_6ghz_unserved', $ac_res6['not_evaluated'], true)],
        [false, false]);
    // V11: a week with too little data is "not measured", never "nothing found".
    $ac_short = $ac_win;
    $ac_short['metrics'] = $ac_metrics;
    foreach (array_slice($ac_win['days'], 0, 5) as $ac_day) {
        foreach (array_keys($ac_short['metrics']) as $ac_key) {
            unset($ac_short['metrics'][$ac_key][$ac_day]);
        }
    }
    $ac_res11 = bk_rec_rules_wifi(['window' => $ac_short, 'details' => $ac_details,
        'monitor' => ['id' => 6, 'type' => 'openwrt'], 'now' => time()], []);
    check('Omnia: V11 dva dny dat znamenají „nezměřeno", ne „nic jsme nenašli"',
        [array_map(fn (array $i): string => (string)$i['id'], $ac_res11['items']),
            array_values(array_intersect(['wifi_6ghz_unserved', 'wifi_weak_client'],
                array_values($ac_res11['not_evaluated'])))],
        [[], ['wifi_6ghz_unserved', 'wifi_weak_client']]);
}

// --- Dostupnost v čase, ne v řádcích (W1-B1) ----------------------------------
// Dostupnost bývala „řádky up / všechny řádky". Mlčící agent zapíše jediný
// řádek 'down' a pak nic, takže třídenní výpadek byl jeden řádek z tisíců
// a měsíc pořád ukazoval ~99,99 %. Teď každý řádek platí do dalšího (nejvýš
// 2,5 intervalu) a nepokrytý čas je u agenta výpadek, u aktivní kontroly
// neměřeno.
{
    $ut_now = 1_790_000_000;
    $ut_from = $ut_now - 30 * 86400;
    $ut_rows = [];
    for ($t = $ut_from; $t <= $ut_now - 3 * 86400; $t += 300) {
        $ut_rows[] = [$t, 'up'];
    }
    $ut_iv = bk_uptime_interval(array_column($ut_rows, 0));
    check('interval se čte z vlastních řádků (medián mezer)', $ut_iv, 300);

    $ut_agent = bk_uptime_summary(bk_uptime_segments($ut_rows, $ut_from, $ut_now, $ut_iv, true), $ut_from, $ut_now);
    check_true('27 dní hlášení a 3 dny ticha u agenta: pod 91 %', $ut_agent['pct'] !== null && $ut_agent['pct'] < 91 && $ut_agent['pct'] > 89);
    check_true('ticho agenta je výpadek, ne neměřeno', $ut_agent['silent'] > 3 * 86400 - 1000 && $ut_agent['unmeasured'] === 0);
    check('výpadek v sekundách = ticho + řádky down', $ut_agent['outage'], $ut_agent['silent'] + $ut_agent['down']);

    // cron.php zapíše po padesáti minutách ticha jeden řádek 'down' - v řádcích
    // to byla 1 z 7 777 kontrol (99,99 %), v čase to na výsledku nic nemění.
    $ut_rows_cron = $ut_rows;
    $ut_rows_cron[] = [$ut_now - 3 * 86400 + 3000, 'down'];
    $ut_rows_count = count(array_filter($ut_rows_cron, fn ($r) => $r[1] === 'up')) / count($ut_rows_cron) * 100;
    check_true('starý výpočet po řádcích by ukázal přes 99,9 %', $ut_rows_count > 99.9);
    $ut_cron = bk_uptime_summary(bk_uptime_segments($ut_rows_cron, $ut_from, $ut_now, $ut_iv, true), $ut_from, $ut_now);
    check_true('s řádkem down z cronu pořád pod 91 %', $ut_cron['pct'] < 91);

    $ut_active = bk_uptime_summary(bk_uptime_segments($ut_rows, $ut_from, $ut_now, $ut_iv, false), $ut_from, $ut_now);
    check('aktivní kontrola: když cron neběží, nic se neměřilo - 100 % z naměřeného', $ut_active['pct'], 100.0);
    check_true('a ty tři dny jsou neměřené, ne výpadek', $ut_active['unmeasured'] > 3 * 86400 - 1000 && $ut_active['outage'] === 0);

    check('bez řádků není dostupnost 100, ale null', bk_uptime_summary(bk_uptime_segments([], $ut_from, $ut_now, 300, true), $ut_from, $ut_now)['pct'], null);
    check('agent, o kterém nevíme, že kdy hlásil: ticho je neměřeno', bk_uptime_summary(bk_uptime_segments([], $ut_from, $ut_now, 300, true), $ut_from, $ut_now)['silent'], 0);
    check('agent, který hlásil před oknem a celé okno mlčí: 0 %', bk_uptime_summary(bk_uptime_segments([], $ut_from, $ut_now, 300, true, true), $ut_from, $ut_now)['pct'], 0.0);
    // Poslední řádek před oknem (stav, ve kterém okno začíná) se počítá jen do okna.
    $ut_carry = bk_uptime_summary(bk_uptime_segments([[$ut_from - 100, 'down'], [$ut_from + 200, 'up']], $ut_from, $ut_from + 260, 60, false), $ut_from, $ut_from + 260);
    check('řádek před oknem kryje jen začátek okna', [$ut_carry['down'], $ut_carry['up']], [50, 60]);

    // Výpadek v minutách jsou skutečné minuty, ne počet řádků.
    $ut_min = [];
    for ($i = 0; $i < 60; $i++) {
        $ut_min[] = [$ut_now - 3600 + $i * 60, ($i >= 20 && $i < 30) ? 'down' : 'up'];
    }
    $ut_min_sum = bk_uptime_summary(bk_uptime_segments($ut_min, $ut_now - 3600, $ut_now, 60, false), $ut_now - 3600, $ut_now);
    check('deset minutových řádků down je 600 s výpadku', $ut_min_sum['outage'], 600);
    check('a dostupnost 50 z 60 minut', $ut_min_sum['pct'], 83.333);

    // agent_service, jehož agent zmlkl: cron zapíše 'unknown'. Ten čas je
    // neměřený, ale monitor ze SLA nevypadne - naměřený den v něm zůstane.
    $ut_svc = [];
    for ($t = $ut_now - 2 * 86400; $t < $ut_now - 86400; $t += 60) {
        $ut_svc[] = [$t, 'up'];
    }
    $ut_svc[] = [$ut_now - 86400, 'unknown'];
    $ut_svc_sum = bk_uptime_summary(bk_uptime_segments($ut_svc, $ut_now - 2 * 86400, $ut_now, 60, false), $ut_now - 2 * 86400, $ut_now);
    check('služba s neznámým stavem zůstane v SLA s naměřeným časem', $ut_svc_sum['pct'], 100.0);
    check_true('a neznámý den je neměřený', $ut_svc_sum['unmeasured'] >= 86400 - 150);

    // Údržba stojí mimo zlomek: nesnižuje ani nezvyšuje.
    $ut_maint = bk_uptime_summary(bk_uptime_segments([[$ut_now - 600, 'up'], [$ut_now - 300, 'maintenance']], $ut_now - 600, $ut_now, 300, false), $ut_now - 600, $ut_now);
    check('údržba se do dostupnosti nepočítá', [$ut_maint['pct'], $ut_maint['maintenance']], [100.0, 300]);
    check('warning není „up" (stejně jako dřív)', bk_uptime_summary([[0, 60, 'up'], [60, 120, 'warning']], 0, 120)['pct'], 50.0);

    check('jedna dlouhá mezera medián nepohne', bk_uptime_interval([0, 60, 120, 180, 180 + 3 * 86400, 180 + 3 * 86400 + 60]), 60);
    check('málo řádků: výchozích 300 s', bk_uptime_interval([0, 60]), 300);
    check('sondy z více míst nezkrátí interval pod minutu', bk_uptime_interval([0, 5, 10, 15, 20]), 60);

    // Úsek přes půlnoc se rozdělí na dva dny a součet sedí.
    $ut_mid = strtotime('2026-09-10 00:00:00');
    $ut_days = bk_uptime_by_day([[$ut_mid - 600, $ut_mid + 1200, 'silent']], $ut_mid - 3600, $ut_mid + 3600);
    check('úsek přes půlnoc: 600 s do prvního dne, 1 200 s do druhého', [$ut_days['2026-09-09']['silent'] ?? null, $ut_days['2026-09-10']['silent'] ?? null], [600, 1200]);
}

// --- Zaokrouhlení výpadek neschová ---------------------------------------------
// Den s výpadkem kratším než 43 s (0,05 % dne) se zaokrouhlil na 100,0 a pás
// ukázal červený den s popiskem „100 % dostupnost". Totéž tři desetinná místa
// u třiceti dní: pět sekund výpadku bylo 100,0.
{
    // 1440 minutových kontrol, jedna selže a další přijde za 30 s.
    $pr_day = [];
    for ($i = 0; $i < 1440; $i++) {
        $pr_day[] = [$i * 60, 'up'];
    }
    $pr_day[] = [720 * 60 + 30, 'up'];
    $pr_day[720] = [720 * 60, 'down'];
    usort($pr_day, fn ($a, $b) => $a[0] <=> $b[0]);
    $pr_sum = bk_uptime_summary(bk_uptime_segments($pr_day, 0, 86400, 60, false), 0, 86400);
    check('den s 30 s výpadku: 30 s down', $pr_sum['down'], 30);
    check('den s 30 s výpadku: na jedno desetinné místo 99,9, ne 100', bk_uptime_pct_round($pr_sum['pct'], 1, true), 99.9);
    check('čistý den zůstane 100', bk_uptime_pct_round(100.0, 1), 100.0);
    check('jinak se zaokrouhluje normálně', [bk_uptime_pct_round(57.25, 1), bk_uptime_pct_round(99.94, 1), bk_uptime_pct_round(99.95, 1)], [57.3, 99.9, 99.9]);
    check('selhaná kontrola bez zapsaného času: pod 100', bk_uptime_pct_round(100.0, 1, true), 99.9);
    check('nic neměřeno zůstane null', bk_uptime_pct_round(null, 1, true), null);
    $pr_month = bk_uptime_totals([['up' => 30 * 86400 - 5, 'down' => 5]]);
    check('třicet dní s 5 s výpadku: 99,999, ne 100,0', $pr_month['pct'], 99.999);
    check('třicet dní bez výpadku: 100,0', bk_uptime_totals([['up' => 30 * 86400]])['pct'], 100.0);
}

// --- Chybějící data nikdy nedají 100 % (W1-B3) ----------------------------------
// Tři ze čtyř částí skóre zdraví dávaly za nic plný počet: web nemá cpu/ram/hdd
// (prahy 100), monitor bez časového razítka byl „čerstvý" (100) a latence bez
// měření měla v infra skóre 100. Neměřená část teď vypadne a váhy se přepočtou;
// když se nenaměřilo nic, je výsledek null („nedostatek dat").
bk_test_load_functions(__DIR__ . '/../functions.php', [
    'bk_latency_score', 'bk_infra_score', 'bk_compute_asset_health_score',
    'bk_monitor_thresholds', 'bk_effective_threshold',
]);
if (!defined('BK_DEFAULT_THRESHOLDS')
    && preg_match('/\nconst BK_DEFAULT_THRESHOLDS = [^;]+;/', (string)file_get_contents(__DIR__ . '/../functions.php'), $hs_const)) {
    eval(trim($hs_const[0]));
}
{
    check('latence bez měření: null, ne 100', bk_latency_score(null), null);
    check('rychlá latence dál 100', bk_latency_score(80), 100.0);
    check('infra skóre bez dostupnosti i latence: nedostatek dat', bk_infra_score(null, null, 0, 0, 0), null);
    // 90 % dostupnosti bez latence: (90·0,55 + 100·0,15 + 100·0,10) / 0,80 = 93
    check('infra skóre bez latence se přepočte, nepřičte 100 za latenci', bk_infra_score(90.0, null, 0, 0, 0), 93);
    check('infra skóre se vším naměřeným beze změny', bk_infra_score(100.0, 100, 0, 0, 0), 100);

    $hs_base = ['id' => 9, 'type' => 'web', 'status' => 'unknown', 'last_checked' => null,
        'cpu_threshold' => null, 'ram_threshold' => null, 'hdd_threshold' => null, 'preset_id' => null];
    check('monitor, který nic nenaměřil: nedostatek dat, ne skóre', bk_compute_asset_health_score(null, $hs_base, [], null), null);
    check('stav „up" bez jediného časového razítka se nepočítá',
        bk_compute_asset_health_score(null, ['status' => 'up'] + $hs_base, [], null), null);
    // Web bez latence i heartbeatu, poslední kontrola před dvěma hodinami:
    // připojení 100, čerstvost 30 → 65. Dřív prahy i čerstvost daly 100.
    $hs_stale = bk_compute_asset_health_score(null, ['status' => 'up', 'last_checked' => date('Y-m-d H:i:s', time() - 7200)] + $hs_base, [], null);
    check('web bez latence a heartbeatu nedostane 100', $hs_stale, 65);

    // Výchozí prahy jsou jedny (BK_DEFAULT_THRESHOLDS): RAM 95, disk 90.
    check('výchozí prahy: cpu 90, ram 95, disk 90', BK_DEFAULT_THRESHOLDS, ['cpu' => 90, 'ram' => 95, 'hdd' => 90]);
    $hs_vps = ['type' => 'vps', 'status' => 'up'] + $hs_base;
    $hs_fresh = ['agent_last_seen' => time() - 60];
    check('RAM 93 % je pod výchozím prahem 95 (dřív přehozeno na 90)',
        bk_compute_asset_health_score(null, $hs_vps, $hs_fresh + ['ram' => 93], null), 100);
    check('disk 93 % je nad výchozím prahem 90 (dřív přehozeno na 95)',
        bk_compute_asset_health_score(null, $hs_vps, $hs_fresh + ['hdd' => 93], null), 86);
    check('práh monitoru přebije výchozí',
        bk_compute_asset_health_score(null, ['hdd_threshold' => 95] + $hs_vps, $hs_fresh + ['hdd' => 93], null), 100);
}


// --- Veřejná stránka ukazuje vybranou sadu (W1-G3) -------------------------------
// Indexovaná veřejná stránka jmenovala každý server i domácí router. Servery,
// router a služby pod agentem jsou teď mimo ni, dokud je vlastník nezapne;
// weby a herní služby na ní zůstávají. NULL = nikdy nevybráno, rozhoduje typ.
bk_test_load_functions(__DIR__ . '/../functions.php', ['bk_monitor_is_public', 'bk_overall_verdict']);
if (!defined('BK_PRIVATE_BY_DEFAULT_TYPES')
    && preg_match('/\nconst BK_PRIVATE_BY_DEFAULT_TYPES = [^;]+;/', (string)file_get_contents(__DIR__ . '/../functions.php'), $pub_const)) {
    eval(trim($pub_const[0]));
}
{
    check_true('web bez volby je veřejný', bk_monitor_is_public(null, 'web'));
    check_true('herní server bez volby je veřejný', bk_monitor_is_public(null, 'minecraft'));
    check_false('server (vps) bez volby veřejný není', bk_monitor_is_public(null, 'vps'));
    check_false('domácí router bez volby veřejný není', bk_monitor_is_public(null, 'openwrt'));
    check_false('služba pod agentem bez volby veřejná není', bk_monitor_is_public(null, 'agent_service'));
    check_false('typ se čte bez ohledu na velikost písmen', bk_monitor_is_public(null, 'OpenWrt'));
    check_true('vlastník může router na stránku dát', bk_monitor_is_public(1, 'openwrt'));
    check_false('a web z ní sundat', bk_monitor_is_public('0', 'web'));
    check_true('prázdná hodnota = nikdy nevybráno, rozhodne typ', bk_monitor_is_public('', 'discord'));
}

// --- Jeden celkový verdikt (W1-B4) ------------------------------------------------
// public_status i odznak říkaly „v pořádku", dokud nic nebylo dole: zhoršený
// monitor, neznámý stav i zastavený sběr dat se tvářily jako vše v pořádku.
{
    $vd = fn (string $status, ?string $checked = '2026-09-23 10:00:00', int $maint = 0): array
        => ['status' => $status, 'last_checked' => $checked, 'maintenance' => $maint];
    check('vše běží a sběr je čerstvý: v pořádku', bk_overall_verdict([$vd('up'), $vd('up')], true)['verdict'], 'healthy');
    check('jeden zhoršený monitor: zhoršeno', bk_overall_verdict([$vd('up'), $vd('warning')], true)['verdict'], 'degraded');
    check('výpadek přebije zhoršení', bk_overall_verdict([$vd('warning'), $vd('down')], true)['verdict'], 'down');
    check('neznámý stav změřeného monitoru: zhoršeno, ne v pořádku', bk_overall_verdict([$vd('up'), $vd('unknown')], true)['verdict'], 'degraded');
    check('údržba: údržba, ne v pořádku', bk_overall_verdict([$vd('up'), $vd('up', null, 1)], true)['verdict'], 'maintenance');
    check('zastavený sběr: neznámý, ne v pořádku', bk_overall_verdict([$vd('up')], false)['verdict'], 'unknown');
    check('zastavený sběr výpadek neschová', bk_overall_verdict([$vd('down')], false)['verdict'], 'down');
    check('prázdná sada: neznámý', bk_overall_verdict([], true)['verdict'], 'unknown');
    check('nový monitor bez první kontroly verdikt nekazí', bk_overall_verdict([$vd('up'), $vd('unknown', null)], true)['verdict'], 'healthy');
    check('sada jen z nezměřených monitorů: neznámý', bk_overall_verdict([$vd('unknown', null)], true)['verdict'], 'unknown');
    check('počty podle stavů',
        bk_overall_verdict([$vd('up'), $vd('down'), $vd('warning'), $vd('unknown'), $vd('unknown', null), $vd('maintenance')], true)['counts'],
        ['up' => 1, 'down' => 1, 'warning' => 1, 'maintenance' => 1, 'unknown' => 1, 'unmeasured' => 1]);
}


// --- Řádky chyb z logu routeru (W1-C3) --------------------------------------------
// Agent 0.1.8 posílá posledních pět chybových řádků, zamaskovaných už na
// routeru. Server masky opakuje (starší nebo cizí agent), drží nejvýš pět
// řádků po 200 znacích a s vypnutým přepínačem monitoru neuloží nic.
bk_test_load_functions(__DIR__ . '/../functions.php', ['bk_mask_log_line', 'bk_sanitize_log_lines', 'bk_log_lines_details']);
{
    check('IPv4 se zamaskuje', bk_mask_log_line('DHCPACK(br-lan) 192.168.1.23 to host'), 'DHCPACK(br-lan) <ipv4> to host');
    check('MAC se zamaskuje', bk_mask_log_line('station aa:bb:cc:dd:ee:0f left'), 'station <mac> left');
    check('IPv6 se zamaskuje', bk_mask_log_line('route to fe80::1c2:3ff:fe44:5566 failed'), 'route to <ipv6> failed');
    check('čas 12:34:56 IPv6 není', bk_mask_log_line('at 12:34:56 retry'), 'at 12:34:56 retry');
    check('e-mail se zamaskuje', bk_mask_log_line('mail for jan.novak@example.com bounced'), 'mail for <email> bounced');
    check('místní jméno se zamaskuje', bk_mask_log_line('lookup nas.lan failed'), 'lookup <host> failed');
    check('dlouhé hex id se zamaskuje', bk_mask_log_line('serial 0123456789abcdef bad'), 'serial <id> bad');
    check('netisknutelný bajt je otazník', bk_mask_log_line("bad\x01byte"), 'bad?byte');
    check('řádek nad 200 znaků se zkrátí a řekne to', strlen(bk_mask_log_line(str_repeat('a ', 150))), 200);
    check_true('a končí třemi tečkami', str_ends_with(bk_mask_log_line(str_repeat('a ', 150)), '...'));

    $ll = bk_sanitize_log_lines([
        ['ts' => 1758600000, 'prog' => 'netifd', 'msg' => 'Interface wan is down', 'count' => 3],
        ['ts' => 'x', 'prog' => 'bad prog!', 'msg' => 'kernel: oops', 'count' => 0],
        ['ts' => 1, 'prog' => 'a', 'msg' => 42],
        'není objekt',
        ['msg' => '   '],
    ]);
    check('položka bez textu a neplatné kusy vypadnou', count($ll), 2);
    check('platná položka projde beze změny', $ll[0], ['ts' => 1758600000, 'prog' => 'netifd', 'msg' => 'Interface wan is down', 'count' => 3]);
    check('neplatný čas, program a počet: null, null, 1', [$ll[1]['ts'], $ll[1]['prog'], $ll[1]['count']], [null, null, 1]);
    check('nejvýš pět řádků', count(bk_sanitize_log_lines(array_fill(0, 9, ['msg' => 'x']))), 5);
    check('prázdný seznam = log přečten, chyba žádná', bk_sanitize_log_lines([]), []);
    check('nečitelný seznam je null', bk_sanitize_log_lines('řádky'), null);
    check('mapa místo seznamu je null', bk_sanitize_log_lines(['a' => ['msg' => 'x']]), null);

    $ld_report = ['log_errors_recent' => [['msg' => 'boom 10.0.0.1']], 'log_window_secs' => 7200, 'log_lines_state' => 'on'];
    check('zapnuto: řádky, okno i stav se uloží',
        bk_log_lines_details($ld_report, true),
        ['log_errors_recent' => [['ts' => null, 'prog' => null, 'msg' => 'boom <ipv4>', 'count' => 1]], 'log_window_secs' => 7200, 'log_lines_state' => 'on']);
    check('vypnuto u monitoru: žádný řádek, stav řekne proč',
        bk_log_lines_details($ld_report, false),
        ['log_errors_recent' => null, 'log_window_secs' => 7200, 'log_lines_state' => 'off_monitor']);
    check('vypnuto na routeru zůstane vypnuto na routeru',
        bk_log_lines_details(['log_errors_recent' => null, 'log_lines_state' => 'off_router'], false)['log_lines_state'], 'off_router');
    check('starší agent bez klíčů nic nezanechá', bk_log_lines_details(['cpu' => 5], true), []);
    check('záporné okno a neznámý stav jsou null',
        bk_log_lines_details(['log_window_secs' => -5, 'log_lines_state' => 'maybe'], true), ['log_window_secs' => null, 'log_lines_state' => null]);
}

$failed = bk_test_report('čisté funkce');
// Under the coverage runner the process does not exit - the report would never generate.
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
