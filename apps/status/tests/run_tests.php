<?php
/**
 * Testy čistých funkcí z functions.php (bez DB a bez sítě).
 *
 * Spuštění:  php apps/status/tests/run_tests.php
 * V CI: .github/workflows/deploy-status.yml je pouští před nasazením.
 *
 * Proč takhle: aplikace běží na sdíleném hostingu bez Composeru, takže
 * PHPUnit není k dispozici. Tenhle runner nepotřebuje nic než PHP a chytá
 * přesně tu třídu regresí, kterou tenhle projekt opakovaně vyráběl -
 * vymyšlené hodnoty a špatné vyhodnocení vstupů.
 */

require_once __DIR__ . '/assert_helpers.php';
bk_test_load_functions(__DIR__ . '/../functions.php', ['bk_validate_import_target', 'bk_version_is_older', 'bk_format_duration', 'bk_effective_threshold']);


// --- bk_validate_import_target: importované cíle -------------------------
// Blokuje se všechno, na co kontrola z hostingu nikdy nedosáhne. Právě tahle
// mezera vyrobila tři monitory hlásící trvale falešný výpadek.
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
    // Přesně ten vstup, se kterým vznikl rozbitý kresd monitor:
    check_true('jméno monitoru není adresa', bk_validate_import_target('Router - Praha') !== null);
}

// --- bk_version_is_older: nabídka aktualizace agenta ---------------------
// Řetězcové porovnání tu hlásilo "neaktuální" u aktuálních agentů.
if (function_exists('bk_version_is_older')) {
    check_true('1.5.2 je starší než 1.5.6', bk_version_is_older('1.5.2', '1.5.6'));
    check_false('1.5.6 není starší než 1.5.6', bk_version_is_older('1.5.6', '1.5.6'));
    check_false('1.7.3 není starší než 1.5.6', bk_version_is_older('1.7.3', '1.5.6'));
    // Klasická past řetězcového porovnání: "1.10.0" < "1.9.0" jako text.
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

$failed = bk_test_report('čisté funkce');
// Pod coverage runnerem se nekončí procesem - jinak by se report nikdy nevygeneroval.
if (!defined('BK_COVERAGE_RUN')) {
    exit($failed > 0 ? 1 : 0);
}
