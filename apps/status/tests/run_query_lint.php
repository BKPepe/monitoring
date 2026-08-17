<?php
/**
 * Static check for SQL patterns proven to slow production down.
 *
 * Running:  php apps/status/tests/run_query_lint.php
 * Runs in CI before the deploy.
 *
 * Why it exists: the monitors endpoint took 0.7 s because of the derived table
 * "SELECT monitor_id, MAX(id) FROM vps_metrics GROUP BY monitor_id", which
 * scanned the whole metrics table on every request - and would grow with history.
 * Nobody noticed, because performance was never measured. This lint catches
 * exactly that class of bugs the moment it appears, not in production.
 */

$files = array_merge(
    glob(__DIR__ . '/../*.php') ?: [],
    glob(__DIR__ . '/../lib/*.php') ?: []
);
$files = array_filter($files, fn($f) => !str_contains($f, '/lib/PHPMailer.php') && !str_contains($f, 'config'));

$violations = [];

/** Tables that grow with time - a full scan is a killer on them. */
$hot_tables = ['monitor_logs', 'vps_metrics', 'monitor_events', 'audit_log'];

foreach ($files as $file) {
    $src = file_get_contents($file);
    $name = basename($file);

    // Lines are joined so multi-line queries can be checked as one.
    foreach ($hot_tables as $table) {
        // 1. Aggregation over a whole table without WHERE (typically MAX(id) GROUP BY)
        $pattern = '/SELECT[^;]{0,400}?(?:MAX|MIN|COUNT|SUM|AVG)\s*\([^)]*\)[^;]{0,400}?FROM\s+`?' . $table . '`?(?![^;]{0,200}?WHERE)[^;]{0,200}?GROUP\s+BY/is';
        if (preg_match($pattern, $src, $m)) {
            $violations[] = [
                'file' => $name,
                'rule' => "agregace přes celou tabulku {$table} bez WHERE",
                'snippet' => trim(preg_replace('/\s+/', ' ', substr($m[0], 0, 120))),
                'hint' => 'Omez rozsah (WHERE monitor_id / časové okno) nebo dohledej poslední řádek přes index (ORDER BY id DESC LIMIT 1).',
            ];
        }

        // 2. SELECT * without WHERE or LIMIT on a growing table
        // A LIMIT (even a placeholder one) is a sufficient bound - only a query
        // with neither WHERE nor LIMIT is flagged.
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

// Check that the "latest row by id" indexes exist in the migrations.
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
