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
    'bk_agent_str',
    'bk_agent_bool',
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

// --- Anonymni odpoved nesmi nest sitovou identitu ------------------------
//
// agent_api dnes propousti i klice, o kterych server predem nevi (jinak nova
// metrika tise zmizi). Vycet citlivych klicu by je nepokryl, takze filtr jede
// i podle nazvu - tenhle test hlida, ze vzor zabira a zaroven nesmaze
// agregaty, kvuli kterym verejny status existuje.
$api_src = file_get_contents(__DIR__ . '/../api.php');
if (preg_match("/preg_match\('(\/\(ipv4\|.*?)',/", $api_src, $re_m)) {
    $anon_re = $re_m[1];

    foreach (['lte_ipv4', 'wan_ipv6', 'public_ip', 'lan_subnet', 'wifi_ssid', 'peer_endpoint',
              'device_mac', 'board_serial', 'hostname', 'agent_key', 'api_token'] as $secret_key) {
        check_true("anonym neuvidi {$secret_key}", (bool)preg_match($anon_re, $secret_key));
    }

    foreach (['cpu', 'ram', 'hdd', 'dns_latency_ms', 'conntrack_pct', 'lte_up', 'lte_uptime',
              'presence_count', 'uptime_seconds', 'wifi_clients_total'] as $public_key) {
        check_false("agregat {$public_key} zustava", (bool)preg_match($anon_re, $public_key));
    }
} else {
    check_true('filtr citlivych klicu je v api.php k nalezeni', false);
}

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

$failed = bk_test_report('čisté funkce');
// Under the coverage runner the process does not exit - the report would never generate.
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
