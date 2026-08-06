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
    "/badge.php?id={$monitor_id}" => ['odznak (stav)', [200]],
    "/badge.php?id={$monitor_id}&type=uptime" => ['odznak (dostupnost)', [200]],
    '/widget.php?id=999999' => ['widget s neexistujícím id', [200]],
    '/badge.php?id=999999' => ['odznak s neexistujícím id', [200]],
    '/error.php?code=404' => ['chybová stránka', [200, 404]],
    '/health.php' => ['health endpoint', [200, 403]],
    '/api.php?action=public_status' => ['veřejné API', [200]],
    '/api.php?action=ui_config' => ['konfigurace UI', [200]],
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

foreach ($pages as $path => [$label, $expected]) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'BloodKings-PageSmoke',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

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
    $results[] = [$path, $label, $code, $problem];
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
foreach ($results as [$path, $label, $code, $problem]) {
    printf(
        "%s %s %6d   %s\n",
        $pad($path, 42),
        $pad($label, 28),
        $code,
        $problem === null ? 'ok' : 'CHYBA: ' . $problem
    );
}

if ($failed > 0) {
    fwrite(STDERR, "\n{$failed} stránek je rozbitých.\n");
    exit(1);
}
echo "\nVšechny stránky odpovídají bez pádu.\n";
exit(0);
