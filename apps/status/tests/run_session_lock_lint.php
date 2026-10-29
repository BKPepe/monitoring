<?php
/**
 * Guards the list of actions that may write to $_SESSION.
 *
 * Run:  php apps/status/tests/run_session_lock_lint.php
 *
 * Why it exists: config.php calls session_start() on every request and PHP
 * holds an exclusive lock on the session file until the script ends, so the
 * parallel requests a page makes are served one after another. Measured on
 * production: `daily_uptime` takes 0.23 s alone and 1.68 s next to the other
 * dashboard requests - the endpoint was never slow, it was queuing.
 *
 * api.php therefore releases the lock with session_write_close() for every
 * action that only reads the session. After that call, writes to $_SESSION are
 * silently discarded - no error, no warning, the value is simply gone on the
 * next request. An action that starts writing to the session and is not on the
 * allowlist would break logins in a way that looks like a random bug.
 *
 * So the allowlist is not maintained by memory: this lint re-derives it from
 * the code and fails when the two disagree.
 */

$root = realpath(__DIR__ . '/..');
$src = file_get_contents($root . '/api.php');
if ($src === false) {
    fwrite(STDERR, "api.php se nepodařilo načíst.\n");
    exit(1);
}

// The allowlist as declared in api.php.
if (!preg_match('/\$bk_session_writers\s*=\s*\[(.*?)\];/s', $src, $m)) {
    fwrite(STDERR, "V api.php chybí \$bk_session_writers - byl přejmenován?\n");
    exit(1);
}
preg_match_all("/'([a-z_0-9]+)'/", $m[1], $dm);
$declared = $dm[1] ?? [];

$writes_session = fn (string $code): bool =>
    preg_match('/\$_SESSION\[[^\]]+\]\s*=(?!=)/', $code) === 1
    || str_contains($code, 'session_regenerate_id')
    || str_contains($code, 'session_destroy')
    || str_contains($code, 'session_unset')
    || preg_match('/unset\(\s*\$_SESSION/', $code) === 1;

// The sign-in writes the session inside functions.php (bk_login_start_session
// and the functions around it), shared with admin.php. A call to a function
// there that writes the session - in its own body or through the functions it
// calls, at any depth (bk_oauth_login -> bk_login_first_factor_passed) - is a
// write of the calling action.
//
// One writer is left out: bk_sync_session_account() corrects the role of a
// demoted or deleted account on every request, through bk_viewer() in most
// read-only actions. After the lock is released that correction is meant to
// live in memory only and the next request makes it again (its docblock).
$memory_only_fns = ['bk_sync_session_account'];
$fn_src = file_get_contents($root . '/functions.php');
if ($fn_src === false) {
    fwrite(STDERR, "functions.php se nepodařilo načíst.\n");
    exit(1);
}
preg_match_all('/\nfunction ([a-zA-Z_0-9]+)\s*\(.*?\n\}/s', $fn_src, $fm, PREG_SET_ORDER);
$fn_bodies = [];
foreach ($fm as [$fn_body, $fn_name]) {
    if (!in_array($fn_name, $memory_only_fns, true)) {
        // Without its own header, so a name never matches itself below.
        $fn_bodies[$fn_name] = substr($fn_body, strlen("\nfunction " . $fn_name));
    }
}
$calls_any = fn (string $code, array $fns): bool =>
    $fns !== [] && preg_match('/\b(?:' . implode('|', array_keys($fns)) . ')\s*\(/', $code) === 1;
$session_fns = [];
foreach ($fn_bodies as $fn_name => $fn_body) {
    if ($writes_session($fn_body)) {
        $session_fns[$fn_name] = true;
    }
}
do {
    $grown = false;
    foreach ($fn_bodies as $fn_name => $fn_body) {
        if (!isset($session_fns[$fn_name]) && $calls_any($fn_body, $session_fns)) {
            $session_fns[$fn_name] = true;
            $grown = true;
        }
    }
} while ($grown);
$calls_session_fn = fn (string $code): bool => $calls_any($code, $session_fns);

// Which actions actually touch the session. Each `$action === 'x'` starts a new
// block; everything up to the next one belongs to it.
preg_match_all("/\\\$action === '([a-z_0-9]+)'/", $src, $am, PREG_OFFSET_CAPTURE);
$marks = $am[1] ?? [];
$writers = [];
foreach ($marks as $i => $mark) {
    [$name, $pos] = $mark;
    $end = isset($marks[$i + 1]) ? $marks[$i + 1][1] : strlen($src);
    $block = substr($src, $pos, $end - $pos);

    $writes = $writes_session($block) || $calls_session_fn($block);

    if ($writes) {
        $writers[$name] = true;
    }
}
$writers = array_keys($writers);
sort($writers);

$missing = array_values(array_diff($writers, $declared));
$extra = array_values(array_diff($declared, $writers));
$problems = [];

foreach ($missing as $a) {
    $problems[] = sprintf('action=%s zapisuje do $_SESSION, ale není v $bk_session_writers - zápis se zahodí', $a);
}
foreach ($extra as $a) {
    $problems[] = sprintf('action=%s je v $bk_session_writers, ale do session nezapisuje - drží zámek zbytečně', $a);
}

if ($problems) {
    fwrite(STDERR, "Seznam akcí se zápisem do session nesedí:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nChybějící akce se neprojeví chybou: přihlášení prostě neproběhne\n");
    fwrite(STDERR, "a uživatel skončí zpátky na přihlašovací stránce bez vysvětlení.\n");
    exit(1);
}

printf(
    "Session lock lint: %d akcí zapisuje do session (%s), zbytek zámek uvolňuje.\n",
    count($writers),
    implode(', ', $writers)
);
exit(0);
