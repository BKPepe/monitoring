<?php
/**
 * Hlídá, že se volají jen akce, které api.php opravdu zná.
 *
 * Spuštění:  php apps/status/tests/run_api_action_lint.php
 *
 * Proč vznikl: api.php dosud na neznámou akci vracelo výchozí přehled služeb
 * s kódem 200, takže překlep v názvu vypadal jako úspěch. Díky tomu roky
 * nikdo nezjistil, že chybí dva endpointy, na které se volalo z UI:
 *
 *   save_annotation  poznámky klikané do grafu se tiše zahazovaly
 *   setup            průvodce prvním spuštěním hlásil úspěch a nezaložil účet
 *
 * Guard v api.php teď vrací 400, takže se to projeví hned. Tenhle lint to
 * odhalí ještě dřív - při buildu, ne až u uživatele.
 *
 * Nekontroluje se jen samotné `action=`: rozhoduje, na KTERÝ skript volání
 * míří. admin.php, node_api.php i agent_api.php mají vlastní sadu akcí a
 * do api.php jim nic není.
 */

$root = realpath(__DIR__ . '/..');
$repo = realpath(__DIR__ . '/../../..');

$api_src = file_get_contents($root . '/api.php');
if ($api_src === false) {
    fwrite(STDERR, "api.php se nepodařilo načíst.\n");
    exit(1);
}

/** Akce, které api.php obsluhuje (`$action === 'x'`, i ve složených podmínkách). */
preg_match_all("/\\\$action === '([a-z_]+)'/", $api_src, $m);
$known = array_unique($m[1] ?? []);
sort($known);

if (empty($known)) {
    fwrite(STDERR, "V api.php se nepodařilo najít žádnou akci - změnil se způsob dispatche?\n");
    exit(1);
}

/** Soubory, které mohou API volat. api.php sám sebe nekontroluje. */
$files = array_filter(array_merge(
    glob($root . '/*.php') ?: [],
    glob($repo . '/apps/monitor/src/*.ts*') ?: [],
    glob($repo . '/apps/monitor/src/**/*.ts*') ?: [],
    glob($repo . '/apps/worker/src/*.ts') ?: [],
    glob($repo . '/apps/site/src/**/*.astro') ?: []
), fn($f) => basename($f) !== 'api.php');

$used = [];
$problems = [];

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

    // Konstanta se základní adresou: `const API_BASE = '/status/api.php';`
    // Volání pak vypadá jako `${API_BASE}?action=…` a bez tohohle kroku by
    // se nepoznalo, kam míří.
    $base_is_api = (bool)preg_match("/(?:const|\\\$)\s*\w*API_BASE\w*\s*=\s*['\"][^'\"]*api\.php['\"]/", implode("\n", $lines));

    foreach ($lines as $no => $line) {
        $trimmed = ltrim($line);
        // Komentáře popisují chování, nevolají ho - zmínka „viz action=monitors"
        // není volání a nemá se kontrolovat.
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $hits = [];

        // 1. Přímá adresa: api.php?action=…, admin.php?action=… atd.
        if (preg_match_all('/(\w+)\.php\?action=([a-z_]+)/', $line, $direct, PREG_SET_ORDER)) {
            foreach ($direct as $d) {
                if ($d[1] === 'api') {
                    $hits[] = $d[2];
                }
            }
        }

        // 2b. Přes appApi helpery: request('akce') / mutate('akce') skládají
        // URL dynamicky (`?action=${action}`), takže vzor 1 je nevidí. Přesně
        // tudy proklouzlo save_user/delete_user - klient je volal, api.php je
        // neznal, neznámá akce vracela 200 a UI hlásilo neexistující úspěch.
        if (preg_match_all("/(?:request|mutate)(?:<[^>]*>)?\(\s*'([a-z_]+)'/", $line, $helper_calls)) {
            foreach ($helper_calls[1] as $hc) {
                $hits[] = $hc;
            }
        }

        // 2. Přes konstantu: `${API_BASE}?action=…`
        if ($base_is_api && preg_match_all('/API_BASE\}\?action=([a-z_]+)/', $line, $viabase)) {
            foreach ($viabase[1] as $a) {
                $hits[] = $a;
            }
        }

        foreach ($hits as $action) {
            $used[$action] = true;
            if (!in_array($action, $known, true)) {
                $problems[] = sprintf('%s:%d volá action=%s, kterou api.php nezná', basename($file), $no + 1, $action);
            }
        }
    }
}

if ($problems) {
    fwrite(STDERR, "Volání na akce, které api.php neobsluhuje:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nTakové volání skončí chybou 400. Buď akci v api.php doplňte,\n");
    fwrite(STDERR, "nebo volání odstraňte - UI, které se tváří, že něco uložilo, je horší než chybějící tlačítko.\n");
    exit(1);
}

// Akce, na které se odsud nevolá, NEJSOU chyba: část API obsluhuje agenty,
// Prometheus nebo veřejné odkazy, které v repozitáři nikde nefigurují.
// Vypisují se jen pro přehled a exit kód neovlivňují.
$unused = array_values(array_diff($known, array_keys($used)));

printf("API action lint: %d akcí v api.php, všechna volání sedí.\n", count($known));
if ($unused) {
    printf("  Bez volání z repozitáře (%d) - typicky agenti, Prometheus nebo externí odkazy:\n    %s\n",
        count($unused), implode(', ', $unused));
}
exit(0);
