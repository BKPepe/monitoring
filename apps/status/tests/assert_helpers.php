<?php
/**
 * Sdílené pomocné funkce testovacích sad.
 *
 * Vlastní soubor proto, že coverage runner obě sady includuje do JEDNOHO
 * procesu - dokud měla každá sada vlastní check(), skončilo to fatální
 * chybou "Cannot redeclare check()". Guard přes function_exists drží běh
 * samostatně i dohromady.
 */

if (!function_exists('bk_test_report')) {
    $GLOBALS['bk_test_passed'] = $GLOBALS['bk_test_passed'] ?? 0;
    $GLOBALS['bk_test_failed'] = $GLOBALS['bk_test_failed'] ?? 0;

    function check(string $name, $actual, $expected): void {
        $ok = $expected === null ? $actual === null : $actual === $expected;
        if ($ok) {
            $GLOBALS['bk_test_passed']++;
            return;
        }
        $GLOBALS['bk_test_failed']++;
        fwrite(STDERR, sprintf(
            "FAIL %s\n  očekáváno: %s\n  skutečnost: %s\n",
            $name,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }

    function check_true(string $name, bool $actual): void { check($name, $actual, true); }
    function check_false(string $name, bool $actual): void { check($name, $actual, false); }

    /**
     * Vytáhne funkci ze zdrojového souboru a vyhodnotí ji.
     *
     * functions.php nejde načíst celý (vyžaduje DB a posílá hlavičky), takže
     * se testované funkce izolují ze zdroje. __DIR__ se přepisuje, jinak by
     * uvnitř eval() ukazovalo na adresář testů a require lang souborů selhal.
     */
    function bk_test_load_functions(string $source_file, array $names): void {
        $src = file_get_contents($source_file);
        $base = var_export(realpath(dirname($source_file)), true);
        foreach ($names as $fn) {
            if (function_exists($fn)) {
                continue;
            }
            if (preg_match('/\nfunction ' . preg_quote($fn, '/') . '\s*\(.*?\n\}/s', $src, $m)) {
                eval(str_replace('__DIR__', $base, $m[0]));
            }
        }
    }

    function bk_test_report(string $suite): int {
        $passed = $GLOBALS['bk_test_passed'];
        $failed = $GLOBALS['bk_test_failed'];
        printf("\n[%s] %d prošlo, %d selhalo\n", $suite, $passed, $failed);
        // Počítadla se resetují, aby druhá sada ve stejném procesu
        // (coverage runner) reportovala své vlastní výsledky.
        $GLOBALS['bk_test_passed'] = 0;
        $GLOBALS['bk_test_failed'] = 0;
        return $failed;
    }
}
