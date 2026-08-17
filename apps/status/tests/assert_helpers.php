<?php
/**
 * Shared helper functions of the test suites.
 *
 * A separate file because the coverage runner includes both suites in ONE
 * process - while each suite had its own check() it ended in a fatal
 * "Cannot redeclare check()" error. The function_exists guard keeps runs
 * working separately and together.
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
     * Extracts a function from a source file and evaluates it.
     *
     * functions.php cannot be loaded whole (it needs a DB and sends headers), so
     * the tested functions are isolated from the source. __DIR__ is rewritten,
     * otherwise inside eval() it would point at the tests directory and requiring lang files would fail.
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
        // Counters reset so a second suite in the same process
        // (the coverage runner) reports its own results.
        $GLOBALS['bk_test_passed'] = 0;
        $GLOBALS['bk_test_failed'] = 0;
        return $failed;
    }
}
