<?php
/**
 * Lint proti vymýšlení dat v agentech (shell / python / powershell).
 *
 * Spuštění:  php apps/status/tests/run_agent_honesty_lint.php
 *
 * Proč vznikl: honesty lint hlídal PHP a TypeScript, ale ne agenty - a
 * právě tam vznikla nula, která tvrdila „0 zahozených paketů" na routeru,
 * kde firewall vůbec neměříme (awk `END{print sum+0}` vypíše nulu i když
 * nic neodpovídalo). Chyba se pak přenese celou cestou až do UI a vypadá
 * jako naměřený údaj.
 *
 * Hlídané vzory:
 *   awk '... END{print sum+0}'      nula i bez jediného shodného řádku
 *   [ -z "$metrika" ] && metrika=0  prázdná hodnota přepsaná nulou
 *   metrika=${x:-0}                 totéž jiným zápisem
 *   'metrika': 0 / = 0              výchozí nula v py/ps1
 *
 * Nulu, která je opravdu nula (počítadla vlastních akcí, přepínače,
 * indexy), pusťte přes seznam allowed_names.
 */

$agents = array_filter([
    __DIR__ . '/../agent.sh',
    __DIR__ . '/../agent_openwrt.sh',
    __DIR__ . '/../agent.py',
    __DIR__ . '/../agent.ps1',
], 'is_file');

/** Názvy, u nichž nula znamená měření, a je tedy lží, když se nezměřilo. */
$metric_names = [
    'cpu', 'ram', 'mem', 'hdd', 'disk', 'swap', 'load', 'temp', 'temperature',
    'latency', 'ping', 'rtt', 'response',
    'dropped', 'rejected', 'accepted', 'errors', 'retrans', 'oom', 'kills',
    'clients', 'peers', 'tunnels', 'devices', 'networks', 'sessions',
    'uptime', 'usage', 'pct', 'percent', 'rate', 'speed', 'mbit', 'kbps',
    'bytes', 'packets', 'queries', 'hits', 'misses', 'leases', 'zombie',
    'entropy', 'inode', 'iowait', 'conntrack', 'wifi',
];

/**
 * Názvy, kde je nula legitimní: vlastní počítadla skriptu, přepínače
 * a pomocné proměnné, které nic neměří.
 */
$allowed_names = [
    'auto_update', 'verbose', 'debug', 'exit_code', 'retry', 'attempt',
    'count_ok', 'i', 'n', 'idx', 'index', 'found', 'flag', 'enabled',
    'now_ts', 'prev_ts', 'start', 'end', 'elapsed', 'sleep',
];

$violations = [];

foreach ($agents as $file) {
    $name = basename($file);
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

    foreach ($lines as $no => $line) {
        $trimmed = ltrim($line);
        // Komentáře popisují problém, netvoří ho (např. tenhle soubor).
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            continue;
        }

        $checks = [];

        // 1. awk END{print sum+0} - nula i bez jediného shodného řádku
        if (preg_match('/END\s*\{\s*print\s+[a-z_]+\s*\+\s*0\s*\}/i', $line)) {
            $checks[] = 'awk vypíše 0 i když nic neodpovídalo (END{print x+0})';
        }

        // 2. [ -z "$x" ] && x=0     nebo    x=${y:-0}
        if (preg_match('/\[\s*-z\s+"?\$\{?([a-z_][a-z0-9_]*)\}?"?\s*\]\s*&&\s*\1=0\b/i', $line, $m)) {
            $checks[] = "prázdná hodnota přepsaná nulou ({$m[1]}=0)";
            $var = $m[1];
        } elseif (preg_match('/([a-z_][a-z0-9_]*)=\$\{[a-z_][a-z0-9_]*:-0\}/i', $line, $m)) {
            $checks[] = "výchozí nula ({$m[1]}=\${…:-0})";
            $var = $m[1];
        } elseif (preg_match('/\|\|\s*echo\s+0\b/', $line)) {
            $checks[] = 'fallback "|| echo 0"';
            $var = null;
        } else {
            $var = null;
        }

        if (empty($checks)) {
            continue;
        }

        // Jméno proměnné rozhoduje, jestli jde o měření.
        $haystack = strtolower($var ?? $line);
        $is_metric = false;
        foreach ($metric_names as $metric) {
            if (preg_match('/\b' . preg_quote($metric, '/') . '/', $haystack)) {
                $is_metric = true;
                break;
            }
        }
        if (!$is_metric) {
            continue;
        }
        foreach ($allowed_names as $allowed) {
            if ($var !== null && strtolower($var) === $allowed) {
                continue 2;
            }
        }

        $violations[] = [
            'file' => $name,
            'line' => $no + 1,
            'why' => implode('; ', $checks),
            'code' => trim($line),
        ];
    }
}

if ($violations) {
    fwrite(STDERR, "Agenti vyrábějí nuly tam, kde se neměří:\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, sprintf("  %s:%d\n    %s\n    %s\n\n", $v['file'], $v['line'], $v['why'], substr($v['code'], 0, 120)));
    }
    fwrite(STDERR, "Nezměřená hodnota musí zůstat null - server i UI s tím počítají.\n");
    printf("%d porušení\n", count($violations));
    exit(1);
}

printf("Agent honesty lint: čisté (%d agentů)\n", count($agents));
exit(0);
