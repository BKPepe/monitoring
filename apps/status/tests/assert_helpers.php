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

    /**
     * bk_test_load_functions() on a pinned clock: the functions are evaluated
     * as copies in the namespace BkTestClock, where time(), date() and
     * strtotime() read $GLOBALS['bk_test_now'] (unset = the wall clock).
     * Returns the prefix to call the copies by: $fn = $ns . 'name'; $fn(...).
     *
     * A fixture that reaches "30 minutes back" means something else at 00:15
     * than at noon, and a suite only ever runs at the time CI picks. Pinned,
     * the midnight case runs on every run. MySQL's NOW() is pinned apart from
     * this, with SET timestamp on the connection the copies are given.
     *
     * Only the named functions are copied; what they call resolves to the
     * global originals, so every function that reads the clock must be named.
     * A copy that reads it any other way would get the wall clock unnoticed,
     * so that is refused.
     */
    function bk_test_load_pinned(string $source_file, array $names): string {
        $ns = 'BkTestClock';
        if (!function_exists($ns . '\time')) {
            eval(<<<'PHP'
                namespace BkTestClock;
                function time(): int { return $GLOBALS['bk_test_now'] ?? \time(); }
                function date(string $format, ?int $timestamp = null): string { return \date($format, $timestamp ?? time()); }
                function strtotime(string $datetime, ?int $base = null): int|false { return \strtotime($datetime, $base ?? time()); }
                PHP);
        }
        $src = (string)file_get_contents($source_file);
        $base = var_export(realpath(dirname($source_file)), true);
        foreach ($names as $fn) {
            if (function_exists($ns . '\\' . $fn)) {
                continue;
            }
            if (!preg_match('/\nfunction ' . preg_quote($fn, '/') . '\s*\(.*?\n\}/s', $src, $m)) {
                throw new RuntimeException("bk_test_load_pinned: {$fn}() is not in {$source_file}");
            }
            if (preg_match('/\b(?:microtime|hrtime|mktime|gmmktime|gmdate|idate|getdate|localtime|date_create\w*|DateTime\w*)\b/', $m[0], $clock)) {
                throw new RuntimeException("bk_test_load_pinned: {$fn}() reads the clock through {$clock[0]}, which the pin does not cover");
            }
            eval('namespace ' . $ns . '; use PDO, PDOException, Throwable; ' . str_replace('__DIR__', $base, $m[0]));
        }
        return $ns . '\\';
    }

    function bk_test_report(string $suite): int {
        $passed = $GLOBALS['bk_test_passed'];
        $failed = $GLOBALS['bk_test_failed'];
        // A suite that executed no check verified nothing, and a green gate over
        // nothing is worse than no gate: it reports success for a run that never
        // happened (a missing driver, an early return, a filter that matched no
        // case). Such a run ends red and says so, the same as a real failure.
        $empty = $passed === 0 && $failed === 0;
        printf("\n[%s] %d prošlo, %d selhalo\n", $suite, $passed, $failed);
        if ($empty) {
            // The counts above stay honest (nothing passed, nothing failed); the
            // non-zero return is about the run itself, not about a failed check.
            fwrite(STDERR, sprintf(
                "PRÁZDNÁ SADA [%s]: neproběhla ani jedna kontrola, sada tedy nic neověřila.\n",
                $suite
            ));
        }
        // Counters reset so a second suite in the same process
        // (the coverage runner) reports its own results.
        $GLOBALS['bk_test_passed'] = 0;
        $GLOBALS['bk_test_failed'] = 0;
        return $empty ? $failed + 1 : $failed;
    }
}
