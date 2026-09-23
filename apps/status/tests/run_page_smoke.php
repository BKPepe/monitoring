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
    $json = @file_get_contents($base . '/api.php?action=monitors&scope=public');
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
    // The per-monitor detail needs a login and access to that monitor since
    // monitors belong to users; an anonymous visitor is sent to the login.
    "/monitor.php?id={$monitor_id}" => ['detail monitoru (bez přihlášení na login)', [302]],
    '/report.php' => ['SLA report (bez přihlášení na login)', [302]],
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
    // Code and deploy files that were served to anyone until 09/2026: the test
    // suites ran on an anonymous GET, and schema.sql plus the deploy sync state
    // mapped the database and every file. They must stay closed; a change that
    // reopens them fails the deploy here instead of going unnoticed.
    '/tests/' => ['testy nejsou veřejné', [404]],
    '/tests/run_tests.php' => ['testovací sada se nespustí', [404]],
    '/tests/fixtures/omnia_router.php' => ['fixtury nejsou veřejné', [404]],
    '/lib/' => ['knihovny nejsou veřejné', [404]],
    '/schema.sql' => ['schéma databáze není veřejné', [403]],
    '/README.md' => ['interní dokumentace není veřejná', [403]],
    '/.ftp-deploy-sync-state.json' => ['seznam nasazených souborů není veřejný', [403]],
    '/uploads/' => ['adresář nahraných souborů se nevypisuje', [404]],
    // assets/ listed its files until W1-F3; the files themselves stay served.
    '/assets/' => ['adresář stylů a log se nevypisuje', [404]],
    '/assets/style.css' => ['styl stránek se dál poskytuje', [200]],
    // PHP's per-directory settings (the session cookie flags) are read from
    // disk by the server and are nobody's business over HTTP.
    '/.user.ini' => ['nastavení PHP není veřejné', [403, 404]],
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

/**
 * One request with the retry rule above.
 *
 * @return array{0: int, 1: string|false, 2: int} status, body, attempts
 */
function bk_smoke_request(string $url, array $expected): array {
    $attempts = 0;
    do {
        $attempts++;
        if ($attempts > 1) {
            sleep(3);
        }
        $ch = curl_init($url);
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

    return [$code, $body, $attempts];
}

foreach ($pages as $path => [$label, $expected]) {
    [$code, $body, $attempts] = bk_smoke_request($base . $path, $expected);

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

/**
 * The agent scripts served from /status/.
 *
 * Routers and servers download these when they self-update, and the install
 * instructions send people straight to the URL. On 2026-09-02 agent.sh was
 * missing from production while the other three were served fine: the deploy
 * had uploaded it hours earlier ("File replace: agent.sh" in its log) and it
 * was gone afterwards, so every bash agent was quietly stuck on its old
 * version. Every page answered, the deploy reported success, and nothing
 * ever asked for the agents.
 *
 * The body is checked, not just the status - a missing file answers with the
 * styled error page, and a quarantined or half-written one would answer 200
 * with nothing usable in it. The served version is only printed, not compared
 * with the repository: the quality gate runs this test in parallel with the
 * deploy, so right after a version bump the two legitimately differ for a
 * minute.
 */
$agent_files = [
    'agent.sh' => 'agent pro Linux (bash)',
    'agent.py' => 'agent pro Linux (Python)',
    'agent.ps1' => 'agent pro Windows (PowerShell)',
    'agent_openwrt.sh' => 'agent pro OpenWrt',
];

foreach ($agent_files as $file => $label) {
    [$code, $body, $attempts] = bk_smoke_request($base . '/' . $file, [200]);

    $problem = null;
    $version = null;
    if ($body === false) {
        $problem = 'požadavek selhal';
    } elseif ($code !== 200) {
        $problem = 'agent se neposkytuje (čekáno 200) - self-update i návod na instalaci vedou na tuhle adresu';
    } elseif (!preg_match('/^\$?AGENT_VERSION\s*=\s*["\']([0-9][0-9A-Za-z.\-]*)["\']/m', $body, $vm)) {
        // A styled 404 page, a quarantined stub or a truncated upload - all of
        // them arrive as "something", none of them is the agent.
        $problem = 'odpověď není skript agenta (chybí AGENT_VERSION)';
    } else {
        $version = $vm[1];
    }

    if ($problem !== null) {
        $failed++;
    }
    $results[] = ['/' . $file, $label . ($version !== null ? " v{$version}" : ''), $code, $problem, $attempts];
}

/**
 * The branded error page (W1-F3).
 *
 * It answers for any path, so its links have to be absolute: the relative
 * "index.php" it used to print led from /status/foo/bar.php to
 * /status/foo/index.php, which is another 404. It must not point at the admin
 * login, must stay out of search results, and has to exist for 410 and 503 as
 * well - db.php shows it with 503 whenever the database is down.
 */
$error_pages = [
    '/neexistuje/stranka.php' => ['chybová stránka (hluboká cesta)', 404],
    '/error.php?code=410' => ['chybová stránka 410', 410],
    '/error.php?code=503' => ['chybová stránka 503', 503],
    '/error.php?code=404&lang=en' => ['chybová stránka anglicky', 404],
];
foreach ($error_pages as $path => [$label, $status]) {
    [$code, $body, $attempts] = bk_smoke_request($base . $path, [$status]);

    $problem = null;
    if ($body === false) {
        $problem = 'požadavek selhal';
    } elseif ($code !== $status) {
        $problem = "neočekávaný stav (čekáno {$status})";
    } elseif (strpos($body, 'href="/status/"') === false || strpos($body, 'href="/app/public"') === false) {
        $problem = 'tlačítka nevedou na /status/ a /app/public';
    } elseif (preg_match('/href="(index|admin)\.php"/', $body)) {
        $problem = 'stránka má relativní odkaz nebo odkaz na administraci';
    } elseif (stripos($body, '<meta name="robots" content="noindex">') === false) {
        $problem = 'chybí meta robots noindex';
    } elseif (str_contains($path, 'lang=en') && stripos($body, '<html lang="en">') === false) {
        $problem = 'anglická verze nepřišla anglicky';
    }
    if ($problem !== null) {
        $failed++;
    }
    $results[] = [$path, $label, $code, $problem, $attempts];
}

/**
 * The session cookie's flags.
 *
 * The admin login rides on this cookie. Until 09/2026 it went out without
 * HttpOnly (any injected script could read it) and without SameSite (other
 * sites' requests carried it), because the deployed config.php started the
 * session without its own flags. db.php and .user.ini now set them before
 * anything can start a session; this reads the real header a visitor gets.
 * No cookie at all is a failure too: the sign-in form cannot work without one.
 */
$cookie_pages = [
    '/admin.php' => 'cookie přihlášení (admin)',
    '/api.php?action=session' => 'cookie přihlášení (aplikace)',
];
foreach ($cookie_pages as $path => $label) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'BloodKings-PageSmoke',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $header_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = is_string($raw) ? substr($raw, 0, $header_size) : '';
    preg_match_all('/^set-cookie:\s*(.+)$/mi', $head, $cm);
    // Cloudflare's own cookies (__cf_bm, cf_clearance) are its business and
    // carry SameSite=None by design; only the application's cookies are ours.
    $cookies = array_values(array_filter(
        array_map('trim', $cm[1] ?? []),
        fn (string $c): bool => !preg_match('/^(__cf|_cf|cf_)/i', $c)
    ));

    $problem = null;
    if ($raw === false) {
        $problem = 'požadavek selhal';
    } elseif ($cookies === []) {
        $problem = 'nepřišla žádná cookie relace - přihlášení bez ní nefunguje';
    } else {
        foreach ($cookies as $cookie) {
            $cookie_name = strtok($cookie, '=');
            $missing = [];
            if (!preg_match('/;\s*httponly\b/i', $cookie)) {
                $missing[] = 'HttpOnly';
            }
            if (!preg_match('/;\s*samesite=(lax|strict)\b/i', $cookie)) {
                $missing[] = 'SameSite=Lax';
            }
            if (str_starts_with($base, 'https://') && !preg_match('/;\s*secure\b/i', $cookie)) {
                $missing[] = 'Secure';
            }
            if ($missing !== []) {
                $problem = sprintf('cookie %s nemá %s', $cookie_name, implode(', ', $missing));
                break;
            }
        }
    }
    if ($problem !== null) {
        $failed++;
    }
    $results[] = [$path, $label, $code, $problem, 1];
}

/** printf counts bytes, so Czech diacritics would misalign the columns. */
$pad = function (string $text, int $width): string {
    $len = mb_strlen($text, 'UTF-8');
    if ($len > $width) {
        return mb_substr($text, 0, $width - 3, 'UTF-8') . '...';
    }
    return $text . str_repeat(' ', $width - $len);
};

echo $pad('adresa', 42) . ' ' . $pad('co to je', 34) . '   stav   výsledek' . "\n";
foreach ($results as [$path, $label, $code, $problem, $attempts]) {
    $note = $problem === null ? 'ok' : 'CHYBA: ' . $problem;
    if ($attempts > 1) {
        $note .= " (až na {$attempts}. pokus)";
    }
    printf(
        "%s %s %6d   %s\n",
        $pad($path, 42),
        $pad($label, 34),
        $code,
        $note
    );
}

if ($failed > 0) {
    // 1 adresa / 2-4 adresy / 5+ adres - číslo v hlášce o chybě má být česky.
    $noun = $failed === 1 ? '1 adresa je rozbitá' : ($failed < 5 ? "{$failed} adresy jsou rozbité" : "{$failed} adres je rozbitých");
    fwrite(STDERR, "\n{$noun}.\n");
    exit(1);
}
echo "\nVšechny stránky odpovídají bez pádu a agenti se poskytují.\n";
exit(0);
