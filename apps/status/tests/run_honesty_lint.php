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

/**
 * Datová pole, u nichž je vymyšlený ŘETĚZEC stejná lež jako vymyšlená nula.
 *
 * Zachytilo to `checked_from ?: 'Frankfurt am Main, DE (RackNerd, LLC)'` -
 * 37 ze 40 událostí tvrdilo konkrétní místo měření, které nikdo nezapsal.
 * Číselná kontrola níže takovou věc principiálně nevidí.
 */
$data_string_fields = [
    'checked_from', 'location', 'region', 'country', 'city', 'isp', 'asn',
    'hostname', 'public_ip', 'wan_ipv4', 'wan_ipv6', 'provider', 'datacenter',
    'virtualserver_name', 'os', 'kernel', 'model', 'board', 'firmware',
    'agent_version', 'version',
];

/** Hodnoty, které jsou poctivé zástupné texty, ne vymyšlená data. */
$honest_placeholders = [
    '—', '-', '', '?', 'n/a', 'N/A', 'null', 'unknown', 'neznámé', 'neznámý',
    'neznámá', 'nezjištěno', 'není k dispozici', 'not available',
];

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
            'kind' => 'nula',
        ];
    }

    // --- Vymyšlené řetězce u datových polí ------------------------------
    // Literál se musí vázat PŘÍMO na dané pole (field ?? 'hodnota'), ne jen
    // být kdesi na stejném řádku - jinak lint hlásil `m.category ?? 'Monitory'`
    // jen proto, že vedle stálo `m.os`.
    foreach ($lines as $no => $line) {
        foreach ($data_string_fields as $field) {
            $pattern = '/\\b' . preg_quote($field, '/') . "\\b['\"]?\\]?\\s*(\\?\\?|\\?:)\\s*'([^']{1,80})'/i";
            if (!preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $m) {
                $literal = trim($m[2]);
                if (in_array(mb_strtolower($literal), array_map('mb_strtolower', $honest_placeholders), true)) {
                    continue;
                }
                // Klíče překladů, CSS proměnné a enum hodnoty nejsou data.
                if (str_starts_with($literal, 'var(') || preg_match('/^[a-z0-9_]+$/', $literal)) {
                    continue;
                }
                $violations[] = [
                    'file' => str_replace(dirname(__DIR__, 3) . '/', '', $file),
                    'line' => $no + 1,
                    'code' => trim($line),
                    'kind' => "vymyšlená hodnota pole {$field}",
                ];
            }
        }
    }
}

if ($violations) {
    fwrite(STDERR, "Nalezené vymyšlené hodnoty (nezměřeno -> konkrétní údaj):\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, sprintf("  %s:%d  [%s]\n    %s\n", $v['file'], $v['line'], $v['kind'], substr($v['code'], 0, 120)));
    }
    fwrite(STDERR, "\nNezměřená veličina musí zůstat NULL a v UI se vykreslit jako pomlčka.\n");
    fwrite(STDERR, "Když je nula v daném místě opravdu správně, doplň výjimku do allowed_patterns.\n");
    printf("%d porušení\n", count($violations));
    exit(1);
}

printf("Honesty lint: žádné vymyšlené nuly (%d souborů)\n", count($targets));
exit(0);
