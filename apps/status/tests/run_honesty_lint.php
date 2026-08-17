<?php
/**
 * A lint against invented data: guards the "unmeasured" to zero conversion.
 *
 * Running:  php apps/status/tests/run_honesty_lint.php
 *
 * Why it exists: the rule "an unmeasured value is NULL and a dash in the UI,
 * never an invented zero" was enforced in this repo by two manual audits - and
 * both let something through (first constants without coercions, then the
 * React app's data path without the legacy PHP pages). A manual audit cannot
 * to; this script does it on every push.
 *
 * What is and is not a violation:
 *   $_GET['id'] ?? 0            OK  - a request parameter, not a measurement
 *   $stats['up_count'] ?? 0     OK  - COUNT() without rows really is zero
 *   $d['response_time'] ?? 0    ERROR - a nonexistent measurement is not 0 ms
 *   bk_ping_host($h) ?? 0       ERROR - a failed ping is not 0 ms
 */

/** Names denoting a MEASURED quantity - for them a zero is a lie. */
$metric_words = [
    'response_time', 'ping', 'latency', 'rtt',
    'cpu', 'ram', 'hdd', 'disk', 'swap', 'load',
    'temp', 'temperature', 'uptime_pct', 'uptime_percent',
    'rx_bytes', 'tx_bytes', 'rx_errors', 'tx_errors', 'rx_packets', 'tx_packets',
    'clients', 'threads', 'mbit', 'speed', 'usage', 'bytes_total',
    'presence_count', 'members_online', 'players', 'players_online',
];

/** Expressions where zero genuinely means zero (counts, identifiers, states). */
$allowed_patterns = [
    '/\$_(GET|POST|REQUEST|SERVER)\[/',      // vstup z requestu
    '/_count\b/', '/\bcount\b/', '/\btotal\b/', // agregace COUNT()
    '/\bid\b/', '/_id\b/',                    // identifikátory
    '/alerts_read_log_id/',                   // ukazatel přečtenosti
];

/** Recursive file collection - glob() cannot do **. */
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
// accidentally dropped ALL of them (and the lint would quietly report zero violations).
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
 * Data fields where an invented STRING is the same lie as an invented zero.
 *
 * Zachytilo to `checked_from ?: 'Frankfurt am Main, DE (RackNerd, LLC)'` -
 * 37 of 40 events claimed a concrete vantage point nobody ever recorded.
 * The numeric check below cannot see such a thing in principle.
 */
$data_string_fields = [
    'checked_from', 'location', 'region', 'country', 'city', 'isp', 'asn',
    'hostname', 'public_ip', 'wan_ipv4', 'wan_ipv6', 'provider', 'datacenter',
    'virtualserver_name', 'os', 'kernel', 'model', 'board', 'firmware',
    'agent_version', 'version',
];

/** Values that are honest placeholders, not invented data. */
$honest_placeholders = [
    '—', '-', '', '?', 'n/a', 'N/A', 'null', 'unknown', 'neznámé', 'neznámý',
    'neznámá', 'nezjištěno', 'není k dispozici', 'not available',
];

$violations = [];

foreach ($targets as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $no => $line) {
        // Only lines converting something to zero are of interest.
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

        // Allowed cases (counts, ids, request) take precedence - otherwise the
        // lint reported "up_count ?? 0", which is a correct zero.
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

    // --- Invented strings on data fields --------------------------------
    // The literal must bind DIRECTLY to the field (field ?? 'value'), not just
    // sit on the same line - the lint used to report `m.category ?? 'Monitory'`
    // merely because `m.os` stood nearby.
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
                // Translation keys, CSS variables and enum values are not data.
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
