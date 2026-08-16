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

// Which actions actually touch the session. Each `$action === 'x'` starts a new
// block; everything up to the next one belongs to it.
preg_match_all("/\\\$action === '([a-z_0-9]+)'/", $src, $am, PREG_OFFSET_CAPTURE);
$marks = $am[1] ?? [];
$writers = [];
foreach ($marks as $i => $mark) {
    [$name, $pos] = $mark;
    $end = isset($marks[$i + 1]) ? $marks[$i + 1][1] : strlen($src);
    $block = substr($src, $pos, $end - $pos);

    $writes = preg_match('/\$_SESSION\[[^\]]+\]\s*=(?!=)/', $block) === 1
        || str_contains($block, 'session_regenerate_id')
        || str_contains($block, 'session_destroy')
        || str_contains($block, 'session_unset')
        || preg_match('/unset\(\s*\$_SESSION/', $block) === 1;

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
