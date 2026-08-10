<?php
/**
 * Výkonnostní kontrola veřejných API endpointů proti běžící instalaci.
 *
 * Spuštění:
 *   php apps/status/tests/run_perf_check.php                       (produkce)
 *   BK_PERF_BASE=http://localhost:8080/status php .../run_perf_check.php
 *
 * Proč vznikl: endpoint dashboard_insights se nasadil s dobou odezvy 3,7 s a
 * nikdo si toho nevšiml, protože se výkon nikdy neměřil - appka se prostě
 * "nějak dlouho načítala". Prahy jsou vědomě velkorysé (sdílený hosting),
 * hlídají řádovou regresi, ne desetiny sekundy.
 */

$base = rtrim(getenv('BK_PERF_BASE') ?: 'https://bloodkings.eu/status', '/');

// endpoint => maximální akceptovatelná doba odezvy v sekundách
$budgets = [
    // Prahy počítají s tím, že měření běží i z CI runneru přes internet na
    // sdílený hosting - hlídá se řádová regrese (endpoint, který se utrhne
    // na sekundy), ne desetiny sekundy kolísání sítě.
    'monitors' => 1.20,           // volá KAŽDÁ stránka aplikace jako první
    'public_status' => 1.00,
    'dashboard_insights' => 1.20, // těžké analýzy, ale cachované
    'daily_uptime' => 1.20,
    'events' => 1.20,
    'audit_logs' => 1.00,
    'ui_config' => 0.80,
];

$failed = 0;
$results = [];

foreach ($budgets as $action => $budget) {
    $url = $base . '/api.php?action=' . rawurlencode($action);
    $best = null;

    // Tři měření, bere se nejlepší - sdílený hosting kolísá a první
    // požadavek platí za studený cache/opcache stav.
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'BloodKings-PerfCheck',
        ]);
        $start = microtime(true);
        $body = curl_exec($ch);
        $elapsed = microtime(true) - $start;
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($body === false || $code >= 500) {
            $results[] = [$action, null, $budget, 'HTTP ' . $code];
            $failed++;
            $best = null;
            break;
        }
        $best = $best === null ? $elapsed : min($best, $elapsed);
    }

    if ($best === null) {
        continue;
    }

    $over = $best > $budget;
    if ($over) {
        $failed++;
    }
    $results[] = [$action, $best, $budget, $over ? 'PŘEKROČENO' : 'ok'];
}

printf("%-22s %10s %10s   %s\n", 'endpoint', 'čas', 'limit', 'stav');
foreach ($results as [$action, $elapsed, $budget, $state]) {
    printf(
        "%-22s %9s %10s   %s\n",
        $action,
        $elapsed === null ? '-' : number_format($elapsed, 3) . 's',
        number_format($budget, 2) . 's',
        $state
    );
}

if ($failed > 0) {
    fwrite(STDERR, "\n{$failed} endpointů mimo rozpočet.\n");
    exit(1);
}
echo "\nVšechny endpointy v rozpočtu.\n";
exit(0);
