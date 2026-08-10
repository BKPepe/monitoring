<?php
/**
 * Coverage report pro PHP testy - Cobertura XML + textový souhrn.
 *
 * Spuštění:  php -d xdebug.mode=coverage apps/status/tests/run_coverage.php
 * Výstup:    coverage/cobertura.xml, coverage/coverage.txt
 *
 * Cobertura formát čtou GitHub Actions reportéry, GitLab i SonarQube, takže
 * pokrytí jde sledovat v čase místo hádání "asi to nějak otestované je".
 */

if (!function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Xdebug s coverage režimem není dostupný - coverage se přeskakuje.\n");
    exit(0);
}

$root = realpath(__DIR__ . '/..');
$out_dir = getcwd() . '/coverage';
@mkdir($out_dir, 0755, true);

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

// Testy se spouští ve vlastním procesním prostoru přes include, aby jejich
// exit() neukončil sběr - proto se zachytává výstup a exit kód se ignoruje.
define('BK_COVERAGE_RUN', true);
$suites = [__DIR__ . '/run_tests.php', __DIR__ . '/run_pipeline_tests.php'];
foreach ($suites as $suite) {
    // Testy končí exit(), takže se spouští v odděleném rozsahu přes
    // register_shutdown_function ne - jednodušší je pustit jejich TĚLO
    // v uzavřené funkci a exit odchytit přes výjimku není možný. Proto se
    // testy includují v samostatném procesu jen kvůli výsledku a zde se
    // znovu volají testované funkce, aby se pokrytí nasbíralo.
    ob_start();
    try {
        include $suite;
    } catch (Throwable $e) {
        // Selhání testu nesmí zrušit generování reportu.
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

// --- Textový souhrn -----------------------------------------------------
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

// Rohatka proti propadu pokrytí: prah se drží v CI (BK_COVERAGE_MIN) a
// zvedá se, kdyz testu pribude. Bez promenne se jen reportuje - lokalni
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
