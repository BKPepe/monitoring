<?php
/**
 * Smoke test veřejných PHP stránek proti běžící instalaci.
 *
 * Spuštění:
 *   php apps/status/tests/run_page_smoke.php
 *   BK_SMOKE_BASE=http://localhost:8080/status php .../run_page_smoke.php
 *
 * Proč vznikl: widget.php a badge.php měsíce vracely HTTP 500 pro každý
 * existující monitor, protože volaly funkci, která v kódu vůbec nebyla.
 * Nikdo si toho nevšiml - ty stránky se nikdy neotevřely a `php -l` chybu
 * za běhu nezachytí. Tenhle test každou stránku prostě požádá a čeká, že
 * nespadne.
 *
 * Kontroluje se stavový kód A obsah odpovědi: PHP se sdíleným hostingem
 * umí vrátit 200 s vypsanou fatální chybou v těle, což je z pohledu
 * uživatele stejně rozbité jako 500.
 */

$base = rtrim(getenv('BK_SMOKE_BASE') ?: 'https://bloodkings.eu/status', '/');

/** Reálné id monitoru pro stránky, které ho vyžadují. */
$monitor_id = getenv('BK_SMOKE_MONITOR_ID') ?: null;
if ($monitor_id === null) {
    // Vezme se první monitor z veřejného API - test tak nezávisí na tom,
    // jaká id zrovna v databázi jsou.
    $json = @file_get_contents($base . '/api.php?action=monitors');
    $decoded = $json ? json_decode($json, true) : null;
    $monitor_id = $decoded['monitors'][0]['id'] ?? 1;
}

/**
 * stránka => [popis, očekávané stavové kódy]
 *
 * 403 u admin.php je správná odpověď (nepřihlášený uživatel), 404 u
 * neexistujícího monitoru taky - test hlídá pády, ne autorizaci.
 */
$pages = [
    '/' => ['veřejná status stránka', [200]],
    '/index.php' => ['status stránka přímo', [200]],
    "/monitor.php?id={$monitor_id}" => ['detail monitoru', [200]],
    "/widget.php?id={$monitor_id}" => ['embed widget', [200]],
    // badge.php je od konsolidace 302 alias na api.php?action=badge -
    // kontroluje se přesměrování I cílová akce (hned pod tím).
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
    // Hlídač zvenku na tomhle stojí - když spadne, nikdo se o výpadku sběru
    // dat nedozví, protože hlásit ho má právě tenhle endpoint.
    '/api.php?action=collection_health' => ['stav sběru dat', [200]],
    // Kanál musí vrátit platné XML i s prázdnou databází incidentů - prázdný
    // kanál je legitimní stav, chyba serveru ne.
    '/rss.php' => ['RSS kanál', [200]],
    '/rss.php?page=neexistuje' => ['RSS neexistující stránky', [404]],
    // Neznámý token má vrátit 404, ne 500. Přesně tady se dřív schovávala
    // chyba, která shodila widget.php i badge.php pro každý reálný monitor.
    '/heartbeat.php?token=' . str_repeat('f', 48) => ['příjem heartbeatu', [404]],
    '/heartbeat.php' => ['heartbeat bez tokenu', [404]],
    '/metrics.php' => ['Prometheus exportér', [200, 401, 403]],
    '/admin.php' => ['admin (nepřihlášený)', [200, 302, 403]],
];

/** Řetězce, které v odpovědi znamenají rozbitou stránku i při stavu 200. */
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
 * Stavy, u kterých má smysl požadavek zopakovat.
 *
 * Test běží hned po FTP nasazení, kdy se soubory na serveru ještě přepisují
 * jeden po druhém. Požadavek, který trefí rozepsaný stav, dostane od
 * Cloudflare 520 (origin nevrátil nic použitelného) - a shodí deploy, přestože
 * stránka je v pořádku. Přesně to se stalo u /heartbeat.php: v CI 520,
 * o minutu později pětkrát po sobě správné 404.
 *
 * Opakují se jen chyby brány. Aplikační 500 se neopakuje - to je chyba, kterou
 * má tenhle test hlásit, ne přesedět.
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

        // Očekávaný stav je hotovo; jinak zkusit znovu, jen když jde o bránu.
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
    // Počet pokusů se vypisuje: opakování nemá zmizet z očí, jinak by se
    // z občasného výpadku stal normál, kterého si nikdo nevšimne.
    $results[] = [$path, $label, $code, $problem, $attempts];
}

/** printf počítá bajty, takže česká diakritika rozhodí sloupce. */
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
