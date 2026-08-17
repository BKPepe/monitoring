<?php
/**
 * A lint against invented data in the agents (shell / python / powershell).
 *
 * Running:  php apps/status/tests/run_agent_honesty_lint.php
 *
 * Why it exists: the honesty lint guarded PHP and TypeScript, but not the
 * agents - and right there a zero appeared claiming "0 dropped packets" on a
 * router where the firewall is not measured at all (awk `END{print sum+0}`
 * prints a zero even when nothing matched). The error then travels all the way
 * as a measured value.
 *
 * Guarded patterns:
 *   awk '... END{print sum+0}'      a zero even without a single matching row
 *   [ -z "$metric" ] && metric=0    an empty value overwritten with zero
 *   metric=${x:-0}                  the same in another notation
 *   'metric': 0 / = 0               a default zero in py/ps1
 *
 * A zero that is genuinely zero (counters of own actions, switches,
 * indices) goes through the allowed_names list.
 */

// Agenti bydli v samostatnem repozitari (submodul `agents`) a lint cte VYHRADNE
// odtud.
//
// Prvni verze mela zalohu na apps/status, kam agenty pri nasazeni kopiruje CI.
// Jenze diky ni lint prosel i bez submodulu - vzal si stare kopie, ktere po
// simulaci nasazeni zustaly lezet na disku, a ohlasil "ciste". Zaloha tedy
// nechranila pred nicim a zakryvala presne ten stav, ktery ma odhalit.
$agent_dir = __DIR__ . '/../../../agents/vps-agent';
$agents = [];
foreach (['agent.sh', 'agent_openwrt.sh', 'agent.py', 'agent.ps1'] as $name) {
    if (is_file($agent_dir . '/' . $name)) {
        $agents[] = $agent_dir . '/' . $name;
    }
}

// Prazdny seznam znamena, ze se submodul nenacetl - lint by pak "prosel" tim,
// ze nic nezkontroloval, coz je horsi nez kdyby spadl.
if (!$agents) {
    fwrite(STDERR, "Nenasel jsem zadneho agenta v " . $agent_dir . ".\n");
    fwrite(STDERR, "Chybi submodul `agents`? Spustte: git submodule update --init\n");
    exit(1);
}

/** Names where a zero means a measurement, i.e. it is a lie when unmeasured. */
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
 * Names where zero is legitimate: the script's own counters, switches
 * and helper variables that measure nothing.
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
        // Comments describe the problem, they do not create it (e.g. this file).
        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            continue;
        }

        $checks = [];

        // 1. awk END{print sum+0} - a zero even without a single matching row
        if (preg_match('/END\s*\{\s*print\s+[a-z_]+\s*\+\s*0\s*\}/i', $line)) {
            $checks[] = 'awk vypíše 0 i když nic neodpovídalo (END{print x+0})';
        }

        // 2. [ -z "$x" ] && x=0     nebo    x=${y:-0}
        //
        // Nula muze byt zapsana i jako "0.0" nebo v uvozovkach - puvodni vzor
        // hledal jen holou nulu, takze `[ -z "$hdd" ] && hdd="0.0"` prosel
        // a router hlasil nulove zaplneni disku, kdyz df selhal.
        if (preg_match('/\[\s*-z\s+"?\$\{?([a-z_][a-z0-9_]*)\}?"?\s*\]\s*&&\s*\1=(?:"0(?:\.0+)?"|0(?:\.0+)?)(?![0-9.])/i', $line, $m)) {
            $checks[] = "prázdná hodnota přepsaná nulou ({$m[1]}=0)";
            $var = $m[1];
        } elseif (preg_match('/^\s*([a-z_][a-z0-9_]*)=(?:"0(?:\.0+)?"|0(?:\.0+)?)(?![0-9.])\s*(?:;|$)/i', $line, $m)) {
            // Inicializace metriky nulou pred merenim: kdyz mereni selze,
            // odejde nula jako by byla namerena.
            $checks[] = "metrika inicializovaná nulou ({$m[1]}=0)";
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

        // The variable's name decides whether it is a measurement.
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
