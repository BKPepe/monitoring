<?php
/**
 * Smoke test of the public PHP pages against a running installation.
 *
 * Running:
 *   php apps/status/tests/run_page_smoke.php
 *   BK_SMOKE_BASE=http://localhost:8080/status php .../run_page_smoke.php
 *
 * Why it exists: widget.php and badge.php returned HTTP 500 for months for
 * every existing monitor, because they called a function that did not exist.
 * Nobody noticed - those pages were never opened and `php -l` cannot catch a
 * runtime error. This test simply requests every page and expects it
 * nespadne.
 *
 * Both the status code AND the response body are checked: PHP on shared
 * hosting can return a 200 with a printed fatal error in the body, which is
 * just as broken for the user as a 500.
 */

$base = rtrim(getenv('BK_SMOKE_BASE') ?: 'https://bloodkings.eu/status', '/');

/** A real monitor id for pages that require one. */
$monitor_id = getenv('BK_SMOKE_MONITOR_ID') ?: null;
if ($monitor_id === null) {
    // The first monitor from the public API is taken - the test thus does not
    // depend on which ids happen to be in the database.
    $json = @file_get_contents($base . '/api.php?action=monitors');
    $decoded = $json ? json_decode($json, true) : null;
    $monitor_id = $decoded['monitors'][0]['id'] ?? 1;
}

/**
 * page => [description, expected status codes]
 *
 * 403 for admin.php is the right answer (an unauthenticated user), 404 for a
 * nonexistent monitor too - the test guards crashes, not authorisation.
 */
$pages = [
    '/' => ['veřejná status stránka', [200]],
    '/index.php' => ['status stránka přímo', [200]],
    "/monitor.php?id={$monitor_id}" => ['detail monitoru', [200]],
    "/widget.php?id={$monitor_id}" => ['embed widget', [200]],
    // badge.php je od konsolidace 302 alias na api.php?action=badge -
    // both the redirect AND the target action are checked (right below).
    "/badge.php?id={$monitor_id}" => ['odznak (alias, stav)', [302]],
    "/badge.php?id={$monitor_id}&type=uptime" => ['odznak (alias, dostupnost)', [302]],
    '/widget.php?id=999999' => ['widget s neexistujícím id', [200]],
    '/badge.php?id=999999' => ['odznak s neexistujícím id (alias)', [302]],
    '/api.php?action=badge' => ['odznak (API, flotila)', [200]],
    "/api.php?action=badge&monitor_id={$monitor_id}&type=uptime" => ['odznak (API, dostupnost)', [200]],
    '/api.php?action=badge&monitor_id=999999' => ['odznak API s neexistujícím id', [404]],
    '/error.php?code=404' => ['chybová stránka', [200, 404]],
    '/health.php' => ['health endpoint', [200, 403]],
    '/api.php?action=public_status' => ['veřejné API', [200]],
    '/api.php?action=ui_config' => ['konfigurace UI', [200]],
    // The external watchdog stands on this - if it breaks, nobody learns about
    // a collection outage, because this very endpoint is meant to report it.
    '/api.php?action=collection_health' => ['stav sběru dat', [200]],
    // The feed must return valid XML even with an empty incident database - an
    // empty feed is a legitimate state, a server error is not.
    '/rss.php' => ['RSS kanál', [200]],
    '/rss.php?page=neexistuje' => ['RSS neexistující stránky', [404]],
    // An unknown token should return 404, not 500. Exactly here hid the bug
    // that took down widget.php and badge.php for every real monitor.
    '/heartbeat.php?token=' . str_repeat('f', 48) => ['příjem heartbeatu', [404]],
    '/heartbeat.php' => ['heartbeat bez tokenu', [404]],
    '/metrics.php' => ['Prometheus exportér', [200, 401, 403]],
    '/admin.php' => ['admin (nepřihlášený)', [200, 302, 403]],
];

/** Strings that mean a broken page even with a 200 status. */
$fatal_markers = [
    'Fatal error',
    'Parse error',
    'Uncaught Error',
    'Uncaught TypeError',
    'Call to undefined',
    'Warning: require',
    'Warning: include',
];

$failed = 0;
$results = [];

/**
 * Statuses worth retrying the request for.
 *
 * The test runs right after the FTP deploy, while files on the server are still
 * being replaced one by one. A request hitting a half-written state gets a 520
 * from Cloudflare (the origin returned nothing usable) - and fails the deploy
 * although the page is fine. Exactly that happened with /heartbeat.php: a 520
 * in CI, five correct 404s in a row a minute later.
 *
 * Only gateway errors are retried. An application 500 is not - that is the
 * error this test exists to report, not to wait out.
 */
const BK_SMOKE_RETRY_CODES = [0, 502, 503, 504, 520, 521, 522, 523, 524];
const BK_SMOKE_MAX_ATTEMPTS = 3;

foreach ($pages as $path => [$label, $expected]) {
    $attempts = 0;
    do {
        $attempts++;
        if ($attempts > 1) {
            sleep(3);
        }
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'BloodKings-PageSmoke',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // The expected status ends it; otherwise retry only for gateway errors.
        $transient = !in_array($code, $expected, true) && in_array($code, BK_SMOKE_RETRY_CODES, true);
    } while ($transient && $attempts < BK_SMOKE_MAX_ATTEMPTS);

    $problem = null;
    if ($body === false) {
        $problem = 'požadavek selhal';
    } elseif (!in_array($code, $expected, true)) {
        $problem = 'neočekávaný stav (čekáno ' . implode('/', $expected) . ')';
    } else {
        foreach ($fatal_markers as $marker) {
            if (stripos($body, $marker) !== false) {
                $problem = 'v těle odpovědi je "' . $marker . '"';
                break;
            }
        }
    }

    if ($problem !== null) {
        $failed++;
    }
    // The attempt count is printed: retries must not vanish from sight, or an
    // occasional failure becomes a norm nobody notices.
    $results[] = [$path, $label, $code, $problem, $attempts];
}

/** printf counts bytes, so Czech diacritics would misalign the columns. */
$pad = function (string $text, int $width): string {
    $len = mb_strlen($text, 'UTF-8');
    if ($len > $width) {
        return mb_substr($text, 0, $width - 3, 'UTF-8') . '...';
    }
    return $text . str_repeat(' ', $width - $len);
};

echo $pad('stránka', 42) . ' ' . $pad('co to je', 28) . '   stav   výsledek' . "\n";
foreach ($results as [$path, $label, $code, $problem, $attempts]) {
    $note = $problem === null ? 'ok' : 'CHYBA: ' . $problem;
    if ($attempts > 1) {
        $note .= " (až na {$attempts}. pokus)";
    }
    printf(
        "%s %s %6d   %s\n",
        $pad($path, 42),
        $pad($label, 28),
        $code,
        $note
    );
}

if ($failed > 0) {
    fwrite(STDERR, "\n{$failed} stránek je rozbitých.\n");
    exit(1);
}
echo "\nVšechny stránky odpovídají bez pádu.\n";
exit(0);
