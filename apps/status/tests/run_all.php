<?php
/**
 * The whole PHP gate as one command.
 *
 * Running:  php apps/status/tests/run_all.php
 *           php apps/status/tests/run_all.php --list   (only what would run)
 *
 * Why it exists: the set of PHP checks was kept by hand in quality.yml,
 * package.json and deploy-status.yml, and the copies drifted (package.json
 * knew four of the ten lints, the deploy ran none). All three run this file
 * now, so a new lint is just a new run_*_lint.php next to it.
 *
 * Each check runs in its own process, in this order: php -l over the app, its
 * translations and the suites; run_tests.php; run_pipeline_tests.php; every
 * run_*_lint.php; find_dead_code.php --strict, so a dead function, a
 * function only the tests call or a dead setting fails the gate; and
 * run_api_tests.php only when BK_TEST_DB_NAME is set. That suite drops and
 * recreates the database it is given, and without a name it takes bk_test,
 * so a host or port alone does not start it, and a skip is said out loud. A
 * failed check does not stop the rest: the summary lists every check, and
 * the exit code is non-zero when any of them failed.
 */

$root = realpath(__DIR__ . '/..');
$list_only = in_array('--list', $argv, true);
$in_ci = getenv('GITHUB_ACTIONS') === 'true';

$lint_files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/lang/*.php') ?: [], glob(__DIR__ . '/*.php') ?: []);
$lints = glob(__DIR__ . '/run_*_lint.php') ?: [];
sort($lints);
// Set but empty still runs it: the suite then refuses the empty name, red.
$db_named = getenv('BK_TEST_DB_NAME') !== false;

// A check is a script to run, the php -l pass, or a reason it does not run.
// An empty input list is a failure, not a pass: a gate that found nothing to
// read has verified nothing (the same rule the lints apply to themselves).
$checks = [['name' => 'php -l (' . count($lint_files) . ' souborů)', 'files' => $lint_files]];
foreach (['run_tests.php', 'run_pipeline_tests.php'] as $suite) {
    $checks[] = ['name' => $suite, 'script' => __DIR__ . '/' . $suite];
}
foreach ($lints as $lint) {
    $checks[] = ['name' => basename($lint), 'script' => $lint];
}
if (!$lints) {
    $checks[] = ['name' => 'run_*_lint.php', 'fail' => 'žádný lint nenalezen, brána lintů neověřila nic'];
}
$checks[] = ['name' => 'find_dead_code.php --strict', 'script' => __DIR__ . '/find_dead_code.php', 'args' => ['--strict']];
$checks[] = $db_named
    ? ['name' => 'run_api_tests.php', 'script' => __DIR__ . '/run_api_tests.php']
    : ['name' => 'run_api_tests.php', 'skip' => 'není nastavené BK_TEST_DB_NAME, bez něj by sada smazala databázi bk_test'];

if ($list_only) {
    foreach ($checks as $c) {
        if (isset($c['skip'])) {
            echo "přeskočí se: {$c['name']} ({$c['skip']})\n";
        } elseif (isset($c['fail'])) {
            echo "selže: {$c['name']} ({$c['fail']})\n";
        } else {
            echo "spustí se: {$c['name']}\n";
        }
    }
    exit(0);
}

$results = [];
foreach ($checks as $c) {
    if (isset($c['skip'])) {
        echo "\n=== {$c['name']} přeskočeno: {$c['skip']}\n";
        $results[] = ['přeskočeno', $c['name'] . ': ' . $c['skip'], null];
        continue;
    }
    echo $in_ci ? "::group::{$c['name']}\n" : "\n=== {$c['name']}\n";
    $started = microtime(true);
    $code = 0;
    if (isset($c['fail'])) {
        fwrite(STDERR, $c['fail'] . "\n");
        $code = 1;
    } elseif (isset($c['files'])) {
        if (!$c['files']) {
            fwrite(STDERR, "Žádný PHP soubor nenalezen, php -l neověřil nic.\n");
            $code = 1;
        }
        foreach ($c['files'] as $file) {
            $out = [];
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                fwrite(STDERR, implode("\n", $out) . "\n");
                $code = 1;
            }
        }
        if ($code === 0) {
            echo "Všechny PHP soubory se parsují.\n";
        }
    } elseif (!is_file($c['script'])) {
        fwrite(STDERR, "Chybí {$c['script']}.\n");
        $code = 1;
    } else {
        $args = implode('', array_map(fn ($a) => ' ' . escapeshellarg($a), $c['args'] ?? []));
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($c['script']) . $args, $code);
    }
    $took = microtime(true) - $started;
    if ($in_ci) {
        echo "::endgroup::\n";
        if ($code !== 0) {
            echo "::error title=PHP brána::{$c['name']} selhal (kód {$code})\n";
        }
    }
    $results[] = [$code === 0 ? 'ok' : 'SELHALO', $c['name'] . ($code === 0 ? '' : " (kód {$code})"), $took];
}

$failed = count(array_filter($results, fn($r) => $r[0] === 'SELHALO'));
echo "\nSouhrn PHP brány:\n";
foreach ($results as [$verdict, $label, $took]) {
    $pad = str_repeat(' ', 12 - mb_strlen($verdict));
    printf("  %s%s%7s  %s\n", $verdict, $pad, $took === null ? '' : sprintf('%.1f s', $took), $label);
}
printf(
    "\nPHP brána: %d prošlo, %d selhalo, %d přeskočeno\n",
    count(array_filter($results, fn($r) => $r[0] === 'ok')),
    $failed,
    count(array_filter($results, fn($r) => $r[0] === 'přeskočeno'))
);
exit($failed > 0 ? 1 : 0);
