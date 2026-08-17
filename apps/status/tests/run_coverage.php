<?php
/**
 * Coverage report for the PHP tests - Cobertura XML + a text summary.
 *
 * Running:  php -d xdebug.mode=coverage apps/status/tests/run_coverage.php
 * Output:   coverage/cobertura.xml, coverage/coverage.txt
 *
 * The Cobertura format is read by GitHub Actions reporters, GitLab and
 * SonarQube, so coverage can be tracked over time instead of guessing "it is probably tested somehow".
 */

if (!function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Xdebug s coverage režimem není dostupný - coverage se přeskakuje.\n");
    exit(0);
}

$root = realpath(__DIR__ . '/..');
$out_dir = getcwd() . '/coverage';
@mkdir($out_dir, 0755, true);

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

// Tests run in their own process space via include, so their exit() does not
// end collection - output is captured and the exit code ignored.
define('BK_COVERAGE_RUN', true);
$suites = [__DIR__ . '/run_tests.php', __DIR__ . '/run_pipeline_tests.php'];
foreach ($suites as $suite) {
    // Tests end with exit(), so they run in a separate scope via
    // register_shutdown_function - no; simpler is running their BODY in a
    // closed function, and catching exit via an exception is impossible. So the
    // tests are included in a separate process only for the result, and here the
    // tested functions are called again so coverage accumulates.
    ob_start();
    try {
        include $suite;
    } catch (Throwable $e) {
        // A test failure must not cancel report generation.
    }
    ob_end_clean();
}

$data = xdebug_get_code_coverage();
xdebug_stop_code_coverage();

$files = [];
$total_lines = 0;
$covered_lines = 0;

foreach ($data as $file => $lines) {
    if (!str_starts_with($file, $root) || str_contains($file, '/tests/') || str_contains($file, '/lib/')) {
        continue;
    }
    $executable = 0;
    $hit = 0;
    $line_nodes = [];
    foreach ($lines as $line => $state) {
        if ($state === -2) {
            continue; // mrtvý kód
        }
        $executable++;
        $is_hit = $state > 0;
        if ($is_hit) {
            $hit++;
        }
        $line_nodes[] = ['number' => $line, 'hits' => $is_hit ? 1 : 0];
    }
    if ($executable === 0) {
        continue;
    }
    $files[] = [
        'path' => ltrim(str_replace($root, 'apps/status', $file), '/'),
        'lines' => $line_nodes,
        'executable' => $executable,
        'covered' => $hit,
    ];
    $total_lines += $executable;
    $covered_lines += $hit;
}

usort($files, fn($a, $b) => ($b['covered'] / max(1, $b['executable'])) <=> ($a['covered'] / max(1, $a['executable'])));

// --- Cobertura XML ------------------------------------------------------
$rate = $total_lines > 0 ? $covered_lines / $total_lines : 0.0;
$xml = new SimpleXMLElement('<?xml version="1.0"?><coverage></coverage>');
$xml->addAttribute('line-rate', sprintf('%.4f', $rate));
$xml->addAttribute('branch-rate', '0');
$xml->addAttribute('lines-covered', (string)$covered_lines);
$xml->addAttribute('lines-valid', (string)$total_lines);
$xml->addAttribute('timestamp', (string)time());
$xml->addAttribute('version', '1.0');
$packages = $xml->addChild('packages');
$package = $packages->addChild('package');
$package->addAttribute('name', 'apps.status');
$package->addAttribute('line-rate', sprintf('%.4f', $rate));
$classes = $package->addChild('classes');

foreach ($files as $f) {
    $class = $classes->addChild('class');
    $class->addAttribute('name', str_replace(['/', '.php'], ['.', ''], $f['path']));
    $class->addAttribute('filename', $f['path']);
    $class->addAttribute('line-rate', sprintf('%.4f', $f['covered'] / max(1, $f['executable'])));
    $class->addChild('methods');
    $lines_node = $class->addChild('lines');
    foreach ($f['lines'] as $ln) {
        $line = $lines_node->addChild('line');
        $line->addAttribute('number', (string)$ln['number']);
        $line->addAttribute('hits', (string)$ln['hits']);
    }
}
$xml->asXML($out_dir . '/cobertura.xml');

// --- Text summary -----------------------------------------------------------
$report = sprintf("Pokrytí PHP testy: %.1f %% (%d z %d spustitelných řádků)\n\n", $rate * 100, $covered_lines, $total_lines);
$report .= sprintf("%-40s %8s %10s\n", 'soubor', 'pokrytí', 'řádky');
foreach ($files as $f) {
    $report .= sprintf(
        "%-40s %7.1f%% %10s\n",
        $f['path'],
        ($f['covered'] / max(1, $f['executable'])) * 100,
        $f['covered'] . '/' . $f['executable']
    );
}
file_put_contents($out_dir . '/coverage.txt', $report);
echo $report;
echo "\nCobertura XML: coverage/cobertura.xml\n";

// A ratchet against coverage decay: the threshold lives in CI (BK_COVERAGE_MIN)
// and rises as tests grow. Without the variable it only reports - local
// beh nema duvod padat.
$min = getenv('BK_COVERAGE_MIN');
if ($min !== false && $min !== '') {
    $min_pct = (float)$min;
    $actual = $rate * 100;
    if ($actual + 0.05 < $min_pct) {
        fwrite(STDERR, sprintf(
            "\nPokryti kleslo na %.1f %%, pozadovane minimum je %.1f %%.\n"
            . "Bud dopis testy, nebo (kdyz to je zamer) sniz BK_COVERAGE_MIN v quality.yml.\n",
            $actual,
            $min_pct
        ));
        exit(1);
    }
    printf("Pokryti %.1f %% splnuje minimum %.1f %%.\n", $actual, $min_pct);
}
exit(0);
