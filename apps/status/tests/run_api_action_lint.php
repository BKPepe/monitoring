<?php
/**
 * Guards that only actions api.php really knows get called.
 *
 * Running:  php apps/status/tests/run_api_action_lint.php
 *
 * Why it exists: api.php used to answer an unknown action with the default
 * service overview with a 200, so a typo in a name looked like success. Thanks
 * to that, for years nobody learned that two endpoints the UI called were
 *
 *   save_annotation  chart notes were silently dropped
 *   setup            the first-run wizard reported success and created no account
 *
 * The guard in api.php now returns 400, so it shows immediately. This lint
 * catches it even earlier - at build time, not at the user.
 *
 * More than the bare `action=` is checked: what matters is WHICH script the
 * call targets. admin.php, node_api.php and agent_api.php have their own
 * action sets and have no business in api.php.
 */

$root = realpath(__DIR__ . '/..');
$repo = realpath(__DIR__ . '/../../..');

$api_src = file_get_contents($root . '/api.php');
if ($api_src === false) {
    fwrite(STDERR, "api.php se nepodařilo načíst.\n");
    exit(1);
}

/** Actions api.php handles (`$action === 'x'`, compound conditions included). */
preg_match_all("/\\\$action === '([a-z_]+)'/", $api_src, $m);
$known = array_unique($m[1] ?? []);
sort($known);

if (empty($known)) {
    fwrite(STDERR, "V api.php se nepodařilo najít žádnou akci - změnil se způsob dispatche?\n");
    exit(1);
}

/** Files that may call the API. api.php does not check itself. */
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

    // The base-address constant: `const API_BASE = '/status/api.php';`
    // Calls then look like `${API_BASE}?action=…` and without this step there
    // would be no telling where they aim.
    $base_is_api = (bool)preg_match("/(?:const|\\\$)\s*\w*API_BASE\w*\s*=\s*['\"][^'\"]*api\.php['\"]/", implode("\n", $lines));

    foreach ($lines as $no => $line) {
        $trimmed = ltrim($line);
        // Comments describe behaviour, they do not call it - a "see action=monitors"
        // mention is not a call and must not be checked.
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
            continue;
        }

        $hits = [];

        // 1. A direct address: api.php?action=…, admin.php?action=… etc.
        if (preg_match_all('/(\w+)\.php\?action=([a-z_]+)/', $line, $direct, PREG_SET_ORDER)) {
            foreach ($direct as $d) {
                if ($d[1] === 'api') {
                    $hits[] = $d[2];
                }
            }
        }

        // 2b. Via the appApi helpers: request('action') / mutate('action') compose
        // the URL dynamically (`?action=${action}`), so pattern 1 misses them. This
        // tudy proklouzlo save_user/delete_user - klient je volal, api.php je
        // never knew, the unknown action returned 200 and the UI reported a nonexistent success.
        if (preg_match_all("/(?:request|mutate)(?:<[^>]*>)?\(\s*'([a-z_]+)'/", $line, $helper_calls)) {
            foreach ($helper_calls[1] as $hc) {
                $hits[] = $hc;
            }
        }

        // 2. Via the constant: `${API_BASE}?action=…`
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

// Actions not called from here are NOT errors: part of the API serves agents,
// Prometheus or public links that appear nowhere in the repository.
// They are listed for overview only and do not affect the exit code.
$unused = array_values(array_diff($known, array_keys($used)));

printf("API action lint: %d akcí v api.php, všechna volání sedí.\n", count($known));
if ($unused) {
    printf("  Bez volání z repozitáře (%d) - typicky agenti, Prometheus nebo externí odkazy:\n    %s\n",
        count($unused), implode(', ', $unused));
}
exit(0);
