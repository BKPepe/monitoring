<?php
/**
 * Lint proti vymýšlení dat: hlídá převod "nezměřeno" na nulu.
 *
 * Spuštění:  php apps/status/tests/run_honesty_lint.php
 *
 * Proč vznikl: pravidlo "nenaměřená hodnota je NULL a v UI pomlčka, nikdy
 * vymyšlená nula" se v tomhle repu prosazovalo dvěma ručními audity - a
 * obojí něco propustilo (poprvé konstanty bez coercions, podruhé datovou
 * cestu React aplikace bez legacy PHP stránek). Ruční audit tuhle práci
 * dělat nemá; dělá ji tenhle skript při každém pushi.
 *
 * Co je a není porušení:
 *   $_GET['id'] ?? 0            OK  - parametr requestu, ne měření
 *   $stats['up_count'] ?? 0     OK  - COUNT() bez řádků je opravdu nula
 *   $d['response_time'] ?? 0    CHYBA - neexistující měření není 0 ms
 *   bk_ping_host($h) ?? 0       CHYBA - selhaný ping není 0 ms
 */

/** Názvy, které označují MĚŘENOU veličinu - u nich je nula lež. */
$metric_words = [
    'response_time', 'ping', 'latency', 'rtt',
    'cpu', 'ram', 'hdd', 'disk', 'swap', 'load',
    'temp', 'temperature', 'uptime_pct', 'uptime_percent',
    'rx_bytes', 'tx_bytes', 'rx_errors', 'tx_errors', 'rx_packets', 'tx_packets',
    'clients', 'threads', 'mbit', 'speed', 'usage', 'bytes_total',
];

/** Výrazy, kde nula skutečně znamená nulu (počty, identifikátory, stavy). */
$allowed_patterns = [
    '/\$_(GET|POST|REQUEST|SERVER)\[/',      // vstup z requestu
    '/_count\b/', '/\bcount\b/', '/\btotal\b/', // agregace COUNT()
    '/\bid\b/', '/_id\b/',                    // identifikátory
    '/alerts_read_log_id/',                   // ukazatel přečtenosti
];

/** Rekurzivní sběr souborů - glob() se ** nezvládá. */
function bk_collect_files(string $dir, array $extensions): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $path = $entry->getPathname();
        foreach ($extensions as $ext) {
            if (str_ends_with($path, $ext)) {
                $out[] = $path;
                break;
            }
        }
    }
    return $out;
}

// realpath: cesty z glob() obsahuji "/tests/../", takze by je filtr nize
// omylem zahodil VSECHNY (a lint by tise hlasil nula porusení).
$targets = array_merge(
    array_map('realpath', glob(__DIR__ . '/../*.php') ?: []),
    bk_collect_files(realpath(__DIR__ . '/../../monitor/src') ?: '', ['.ts', '.tsx'])
);
$targets = array_filter(
    $targets,
    fn($f) => !str_contains($f, '/lib/PHPMailer')
        && !str_contains($f, '/tests/')
        && !str_contains($f, '.test.ts')
);

$violations = [];

foreach ($targets as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $no => $line) {
        // Zajímají nás jen řádky, kde se něco převádí na nulu.
        if (!preg_match('/\?\?\s*0(?![.\d])|COALESCE\([^)]+,\s*0\s*\)/i', $line)) {
            continue;
        }

        $lower = strtolower($line);

        $is_metric = false;
        foreach ($metric_words as $word) {
            if (str_contains($lower, $word)) {
                $is_metric = true;
                break;
            }
        }
        if (!$is_metric) {
            continue;
        }

        // Povolené případy (počty, id, request) mají přednost - jinak by
        // lint hlásil "up_count ?? 0", což je korektní nula.
        $allowed = false;
        foreach ($allowed_patterns as $pattern) {
            if (preg_match($pattern, $lower)) {
                $allowed = true;
                break;
            }
        }
        if ($allowed) {
            continue;
        }

        $violations[] = [
            'file' => str_replace(dirname(__DIR__, 3) . '/', '', $file),
            'line' => $no + 1,
            'code' => trim($line),
        ];
    }
}

if ($violations) {
    fwrite(STDERR, "Nalezené převody nezměřené hodnoty na nulu:\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, sprintf("  %s:%d\n    %s\n", $v['file'], $v['line'], substr($v['code'], 0, 130)));
    }
    fwrite(STDERR, "\nNezměřená veličina musí zůstat NULL a v UI se vykreslit jako pomlčka.\n");
    fwrite(STDERR, "Když je nula v daném místě opravdu správně, doplň výjimku do allowed_patterns.\n");
    printf("%d porušení\n", count($violations));
    exit(1);
}

printf("Honesty lint: žádné vymyšlené nuly (%d souborů)\n", count($targets));
exit(0);
