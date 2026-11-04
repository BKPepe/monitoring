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
 *
 * The other direction fails too: an action of api.php that nothing in the
 * app calls (tests, fixtures and comments do not count) is dead code with a
 * public address, unless $no_caller_ok below names why it stays.
 */

$root = realpath(__DIR__ . '/..');
$repo = realpath(__DIR__ . '/../../..');

$api_src = file_get_contents($root . '/api.php');
if ($api_src === false) {
    fwrite(STDERR, "api.php se nepodařilo načíst.\n");
    exit(1);
}

/** Actions api.php handles (`$action === 'x'`, compound conditions included). */
preg_match_all("/\\\$action === '([a-z0-9_]+)'/", $api_src, $m);
$known = array_unique($m[1] ?? []);
sort($known);

if (empty($known)) {
    fwrite(STDERR, "V api.php se nepodařilo najít žádnou akci - změnil se způsob dispatche?\n");
    exit(1);
}

/**
 * Every file under $dir whose name matches $pattern, at any depth. PHP's
 * glob() has no `**`: 'src/**' + '/*.ts*' matched exactly one directory level,
 * so calls in components/charts/, components/reports/ or
 * components/layout/ were never checked.
 */
function bk_lint_tree(string $dir, string $pattern): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match($pattern, $f->getFilename())) {
            $out[] = $f->getPathname();
        }
    }
    sort($out);
    return $out;
}

/**
 * $src with its comments blanked: a block comment, a JSDoc one-liner
 * included, and a // comment to the end of its line. A quoted string is read
 * past whole, and a // after a colon is an address (JSX text shows the API's
 * own URL), so both stay. Every newline stays, so a line number still points
 * at the source.
 */
function bk_lint_strip_comments(string $src): string {
    $re = <<<'RE'
        ~('(?:[^'\\\n]++|\\.)*+'|"(?:[^"\\\n]++|\\.)*+"|`(?:[^`\\]++|\\.)*+`)|/\*.*?\*/|(?<!:)//[^\n]*~s
        RE;
    $out = preg_replace_callback($re, fn (array $m): string => isset($m[1]) && $m[1] !== ''
        ? $m[0] : ' ' . str_repeat("\n", substr_count($m[0], "\n")), $src);
    if ($out === null) {
        // An empty file here would pass every action of it unseen.
        fwrite(STDERR, 'Komentáře se nepodařilo odstranit: ' . preg_last_error_msg() . "\n");
        exit(1);
    }
    return $out;
}

/** Files that may call the API. api.php does not check itself. */
$files = array_filter(array_merge(
    glob($root . '/*.php') ?: [],
    bk_lint_tree($repo . '/apps/monitor/src', '/\.tsx?$/'),
    glob($repo . '/apps/worker/src/*.ts') ?: [],
    bk_lint_tree($repo . '/apps/site/src', '/\.(astro|ts)$/')
), fn($f) => basename($f) !== 'api.php');

/**
 * Actions api.php keeps although nothing in this repository calls them, each
 * with the reason. An action outside this list with no caller fails the lint:
 * dead actions piled up unseen while the lint only printed them. An entry that
 * gets a caller, or whose action is gone, fails too, so the list cannot rot.
 */
$no_caller_ok = [
    'audit_logs' => 'only tests call it since the app reads user_audit_log; retiring it (R57) is an owner decision',
    'portal_status' => 'the game portal (BKPepe/bloodkings, app.js) calls it from outside this repository',
];

$used = [];
$app_used = [];
$problems = [];

foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    // A test or a fixture calling an action is no use by the app: only the
    // app's own calls keep an action alive. They are still checked below.
    $is_test = (bool)preg_match('/\.test\.tsx?$|\.fixture\.ts$/', basename($file));

    // The base-address constant: `const API_BASE = '/status/api.php';`
    // Calls then look like `${API_BASE}?action=…` and without this step there
    // would be no telling where they aim.
    $base_is_api = (bool)preg_match("/(?:const|\\\$)\s*\w*API_BASE\w*\s*=\s*['\"][^'\"]*api\.php['\"]/", implode("\n", $lines));

    // The file without its comments, which describe calls, not make them. A
    // line that began with a comment marker was all this dropped, so a JSDoc
    // one-liner over a type (`/** One row of api.php?action=x. */`) kept an
    // action alive after its last caller was gone.
    $code_lines = array_map(function (string $line): string {
        $trimmed = ltrim($line);
        return str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#') ? '' : $line;
    }, explode("\n", bk_lint_strip_comments(implode("\n", $lines))));
    $code = implode("\n", $code_lines);

    $hits = [];

    foreach ($code_lines as $no => $line) {
        // 1. A direct address: api.php?action=…, admin.php?action=… etc.
        if (preg_match_all('/(\w+)\.php\?action=([a-z0-9_]+)/', $line, $direct, PREG_SET_ORDER)) {
            foreach ($direct as $d) {
                if ($d[1] === 'api') {
                    $hits[] = [$d[2], $no];
                }
            }
        }

        // 2. Via the constant: `${API_BASE}?action=…`
        if ($base_is_api && preg_match_all('/API_BASE\}\?action=([a-z0-9_]+)/', $line, $viabase)) {
            foreach ($viabase[1] as $a) {
                $hits[] = [$a, $no];
            }
        }
    }

    // 3. Via a helper that puts its first argument into the address: the
    // appApi helpers request('action') / mutate('action') (`?action=${action}`),
    // which pattern 1 misses - save_user/delete_user slipped through that way:
    // the client called them, api.php never knew, the unknown action returned
    // 200 and the UI reported a success that never happened. Also a file's own
    // helper of that shape (totp-section's post()). The name may sit in quotes
    // or backticks, on the line after the parenthesis.
    $helpers = ['request', 'mutate'];
    if (preg_match_all('/api\.php\?action=\$\{(\w+)\}/', $code, $composed)) {
        foreach (array_unique($composed[1]) as $param) {
            if (preg_match_all('/(?:const\s+(\w+)\s*=\s*(?:async\s*)?|function\s+(\w+)\s*)\(\s*' . $param . '\s*[:,)]/', $code, $defs, PREG_SET_ORDER)) {
                foreach ($defs as $def) {
                    $helpers[] = $def[1] !== '' ? $def[1] : $def[2];
                }
            }
        }
    }
    $helper_re = '/\b(?:' . implode('|', array_unique($helpers)) . ')(?:<[^>]*>)?\(\s*[\'`]([a-z0-9_]+)/';
    if (preg_match_all($helper_re, $code, $helper_calls, PREG_OFFSET_CAPTURE)) {
        foreach ($helper_calls[1] as [$hc, $offset]) {
            $hits[] = [$hc, substr_count($code, "\n", 0, $offset)];
        }
    }

    foreach ($hits as [$action, $no]) {
        $used[$action] = true;
        if (!$is_test) {
            $app_used[$action] = true;
        }
        if (!in_array($action, $known, true)) {
            $problems[] = sprintf('%s:%d volá action=%s, kterou api.php nezná', basename($file), $no + 1, $action);
        }
    }
}

if ($problems) {
    fwrite(STDERR, "Volání na akce, které api.php neobsluhuje:\n\n");
    foreach (array_unique($problems) as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nTakové volání skončí chybou 400. Buď akci v api.php doplňte,\n");
    fwrite(STDERR, "nebo volání odstraňte - UI, které se tváří, že něco uložilo, je horší než chybějící tlačítko.\n");
    exit(1);
}

// An action nothing in the app calls is dead code with a public address. It
// fails unless the list above says why it stays.
$dead = [];
foreach (array_diff($known, array_keys($app_used)) as $action) {
    if (!isset($no_caller_ok[$action])) {
        $dead[] = $action . (isset($used[$action]) ? ' (volají ji jen testy)' : '');
    }
}
$stale = [];
foreach ($no_caller_ok as $action => $why) {
    if (!in_array($action, $known, true)) {
        $stale[] = "{$action}: api.php ji už nemá";
    } elseif (isset($app_used[$action])) {
        $stale[] = "{$action}: aplikace ji volá";
    }
}
if ($dead || $stale) {
    if ($dead) {
        fwrite(STDERR, "Akce api.php, které z repozitáře nic nevolá:\n\n  " . implode("\n  ", $dead) . "\n\n");
        fwrite(STDERR, "Smažte ji, nebo ji zapište do \$no_caller_ok i s důvodem (volající mimo repozitář).\n");
    }
    if ($stale) {
        fwrite(STDERR, "\$no_caller_ok drží akce, které tam nepatří:\n\n  " . implode("\n  ", $stale) . "\n");
    }
    exit(1);
}

printf("API action lint: %d akcí v api.php, všechna volání sedí, každou akci volá aplikace.\n", count($known));
printf("  Bez volajícího v repozitáři, s důvodem (%d): %s\n", count($no_caller_ok), implode(', ', array_keys($no_caller_ok)));
exit(0);
