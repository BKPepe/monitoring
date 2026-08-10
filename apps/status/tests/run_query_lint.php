<?php
/**
 * Statická kontrola SQL vzorů, které na produkci prokazatelně brzdí.
 *
 * Spuštění:  php apps/status/tests/run_query_lint.php
 * V CI běží před deployem.
 *
 * Proč vznikl: endpoint monitors trval 0,7 s kvůli odvozené tabulce
 * "SELECT monitor_id, MAX(id) FROM vps_metrics GROUP BY monitor_id", která
 * projela celou tabulku metrik při každém požadavku - a rostla by s historií.
 * Nikdo si toho nevšiml, protože se výkon nikdy neměřil. Tenhle lint chytá
 * přesně tu třídu chyb ve chvíli, kdy vznikne, ne až v produkci.
 */

$files = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../lib/*.php') ?: []
);
$files = array_filter($files, fn($f) => !str_contains($f, '/lib/PHPMailer.php') && !str_contains($f, 'config'));

$violations = [];

/** Tabulky, které rostou s časem - u nich je full scan zabiják. */
$hot_tables = ['monitor_logs', 'vps_metrics', 'monitor_events', 'audit_log'];

foreach ($files as $file) {
    $src = file_get_contents($file);
    $name = basename($file);

    // Řádky se spojí, aby se dotazy přes víc řádků daly kontrolovat vcelku.
    foreach ($hot_tables as $table) {
        // 1. Agregace přes celou tabulku bez WHERE (typicky MAX(id) GROUP BY)
        $pattern = '/SELECT[^;]{0,400}?(?:MAX|MIN|COUNT|SUM|AVG)\s*\([^)]*\)[^;]{0,400}?FROM\s+`?' . $table . '`?(?![^;]{0,200}?WHERE)[^;]{0,200}?GROUP\s+BY/is';
        if (preg_match($pattern, $src, $m)) {
            $violations[] = [
                'file' => $name,
                'rule' => "agregace přes celou tabulku {$table} bez WHERE",
                'snippet' => trim(preg_replace('/\s+/', ' ', substr($m[0], 0, 120))),
                'hint' => 'Omez rozsah (WHERE monitor_id / časové okno) nebo dohledej poslední řádek přes index (ORDER BY id DESC LIMIT 1).',
            ];
        }

        // 2. SELECT * bez WHERE i LIMIT nad rostoucí tabulkou
        // LIMIT (i s placeholderem) je dostatečné omezení - hlídá se jen
        // dotaz, který nemá ani WHERE, ani LIMIT.
        $pattern2 = '/SELECT\s+\*\s+FROM\s+`?' . $table . '`?\s*(?:ORDER\s+BY(?![^;]{0,120}LIMIT)[^;]{0,80})?["\')]/is';
        if (preg_match($pattern2, $src, $m2)) {
            $violations[] = [
                'file' => $name,
                'rule' => "SELECT * z {$table} bez WHERE a bez LIMIT",
                'snippet' => trim(preg_replace('/\s+/', ' ', substr($m2[0], 0, 120))),
                'hint' => 'Doplň WHERE (monitor_id / časové okno) nebo LIMIT.',
            ];
        }
    }
}

// Kontrola, že indexy pro "poslední řádek podle id" existují v migracích.
$db_src = file_get_contents(__DIR__ . '/../db.php');
foreach (['monitor_logs (monitor_id, id)', 'vps_metrics (monitor_id, id)'] as $needed) {
    $normalized = str_replace(' ', '\s*', preg_quote($needed, '/'));
    if (!preg_match('/' . $normalized . '/i', $db_src)) {
        $violations[] = [
            'file' => 'db.php',
            'rule' => "chybí index pro {$needed}",
            'snippet' => '-',
            'hint' => 'Dotazy typu ORDER BY id DESC LIMIT 1 potřebují složený index (monitor_id, id).',
        ];
    }
}

if ($violations) {
    fwrite(STDERR, "Nalezené rizikové dotazy:\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, sprintf("  %s: %s\n    %s\n    → %s\n\n", $v['file'], $v['rule'], $v['snippet'], $v['hint']));
    }
    printf("%d problémů\n", count($violations));
    exit(1);
}

echo "Query lint: žádné rizikové vzory (" . count($files) . " souborů)\n";
exit(0);
