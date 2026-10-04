<?php
/**
 * Guards that a numeric value from an agent ends up somewhere - in metrics,
 * or knowingly only in details.
 *
 * Running:  php apps/status/tests/run_agent_metric_lint.php
 *
 * Why it exists: agents sent 35 numeric values every minute, of which only
 * the latest snapshot was stored in `last_details`. One could see the current
 * TCP retransmission count, but not whether it is a spike or normal. It
 * accumulated gradually, because adding a key to the agent is easier than adding
 * the column, the metric map and the chart - and nothing pointed out the difference.
 *
 * Kontroluje se jen to, co je opravdu metrika. Stavy (`wan_up`), texty
 * (`wan_proto`), structures (`interfaces`) and one-off metadata (`kernel`)
 * do not belong in the metrics table - they belong in details or events, and
 * are therefore on the exception list.
 */

$root = realpath(__DIR__ . '/..');

// Viz run_agent_honesty_lint.php: cte se vyhradne ze submodulu, zadna zaloha
// na apps/status - ta by lint nechala projit i bez nej.
$agent_dir = $root . '/../../agents/vps-agent';
$agent_sources = [];
foreach (['agent.sh', 'agent_openwrt.sh'] as $name) {
    if (is_file($agent_dir . '/' . $name)) {
        $agent_sources[] = $agent_dir . '/' . $name;
    }
}
if (!$agent_sources) {
    fwrite(STDERR, "Nenasel jsem zadneho agenta v " . $agent_dir . ".\n");
    fwrite(STDERR, "Chybi submodul `agents`? Spustte: git submodule update --init\n");
    exit(1);
}

$agent_src = '';
foreach ($agent_sources as $file) {
    $agent_src .= "\n" . file_get_contents($file);
}

$api_src = file_get_contents($root . '/agent_api.php');
$fn_src = file_get_contents($root . '/functions.php');

if ($agent_src === '' || $api_src === false || $fn_src === false) {
    fwrite(STDERR, "Zdrojové soubory agentů nebo API se nepodařilo načíst.\n");
    exit(1);
}

// Keys that do not belong in the metrics table, each with the reason. Shared
// with run_tests.php and run_api_tests.php, so the list lives in one file.
$not_metrics = require __DIR__ . '/fixtures/agent_not_metrics.php';

// Keys the agents send. Read from code only: a full-line # comment that
// quotes something like "_": is prose, not a key in the payload.
preg_match_all('/"([a-z_0-9]+)":/', preg_replace('/^[ \t]*#.*$/m', '', $agent_src), $sent_matches);
$sent = array_unique($sent_matches[1] ?? []);
sort($sent);

// Keys agent_api.php reads from the input.
preg_match_all("/\\\$data\['([a-z_0-9]+)'\]/", $api_src, $read_matches);
// ...directly, or through the typed helpers (bk_agent_num/int/str/bool).
preg_match_all("/bk_agent_(?:num|int|str|bool)\(\\\$data, '([a-z_0-9]+)'/", $api_src, $helper_reads);
$read = array_flip(array_merge($read_matches[1] ?? [], $helper_reads[1] ?? []));

// Keys that end up in a metrics table column.
$stored = [];
if (preg_match('/\$metric_row = \[(.*?)\n        \];/s', $api_src, $row_match)) {
    preg_match_all("/bk_agent_(?:num|int)\(\\\$data, '([a-z_0-9]+)'\)/", $row_match[1], $direct);
    foreach ($direct[1] ?? [] as $k) {
        $stored[$k] = true;
    }
    // Older writes go through a variable; the key it came from is traced.
    // The value can be a composed expression ($swap ?? $ow_swap_pct), so all
    // variables are taken, not just the first - otherwise the second source would
    // look unstored.
    preg_match_all('/=>\s*([^,\n]+),/', $row_match[1], $exprs);
    $vars = [];
    foreach ($exprs[1] ?? [] as $expr) {
        preg_match_all('/\$(\w+)/', $expr, $expr_vars);
        foreach ($expr_vars[1] ?? [] as $v) {
            $vars[] = $v;
        }
    }
    foreach ($vars as $var) {
        if (preg_match("/\\\$" . preg_quote($var, '/') . "\s*=\s*[^;]*?(?:\\\$data\['([a-z_0-9]+)'\]|bk_agent_(?:num|int|str|bool)\(\\\$data, '([a-z_0-9]+)')/s", $api_src, $vm)) {
            $stored[$vm[1] !== '' ? $vm[1] : ($vm[2] ?? '')] = true;
        }
    }
}

if (empty($stored)) {
    fwrite(STDERR, "V agent_api.php se nepodařilo najít \$metric_row - změnil se zápis metrik?\n");
    exit(1);
}

// G26: a key the API itself reads as a LIST must never reach $metric_row
// through bk_agent_num()/bk_agent_int(). Both answer null for an array, so the
// column is written NULL every minute and the chart stays empty for ever -
// which is exactly what `wireguard_peers` did since the day it was added, with
// no test saying a word. A list belongs in a column as a COUNT.
$array_metric_reads = function (string $row_src, string $src, array $array_keys): array {
    $found = [];
    preg_match_all('/\$(\w+)/', $row_src, $row_vars);
    $vars = array_unique($row_vars[1] ?? []);
    foreach (array_keys($array_keys) as $key) {
        $quoted = preg_quote($key, '/');
        if (preg_match("/bk_agent_(?:num|int)\(\\\$data, '{$quoted}'\)/", $row_src)) {
            $found[] = $key;
            continue;
        }
        foreach ($vars as $var) {
            if (preg_match("/\\\$" . preg_quote($var, '/') . "\s*=\s*bk_agent_(?:num|int)\(\\\$data, '{$quoted}'\)/", $src)) {
                $found[] = $key;
                break;
            }
        }
    }
    return array_values(array_unique($found));
};

// The rule is tried on its own samples first: a regex that stopped matching
// would otherwise report "clean" for ever (the same reason the busybox lint
// carries samples).
$sample_src = "\$ow_list = (isset(\$data['peer_list']) && is_array(\$data['peer_list'])) ? \$data['peer_list'] : null;\n"
    . "\$ow_bad = bk_agent_num(\$data, 'peer_list');\n";
$sample_keys = ['peer_list' => true];
if ($array_metric_reads("'peers' => bk_agent_num(\$data, 'peer_list'),", $sample_src, $sample_keys) !== ['peer_list']
    || $array_metric_reads("'peers' => \$ow_bad,", $sample_src, $sample_keys) !== ['peer_list']
    || $array_metric_reads("'peers' => bk_wireguard_peer_count(\$ow_list),", $sample_src, $sample_keys) !== []) {
    fwrite(STDERR, "Pravidlo o polích v \$metric_row neodpovídá svým vzorkům - zkontrolujte regulární výrazy v tomhle skriptu.\n");
    exit(1);
}

$array_keys = [];
preg_match_all("/is_array\(\\\$data\['([a-z_0-9]+)'\]\)/", $api_src, $array_matches);
foreach ($array_matches[1] ?? [] as $k) {
    $array_keys[$k] = true;
}
$array_metrics = isset($row_match[1]) ? $array_metric_reads($row_match[1], $api_src, $array_keys) : [];
if ($array_metrics) {
    fwrite(STDERR, "Do \$metric_row se přes bk_agent_num()/bk_agent_int() čtou klíče, které API samo zpracovává jako pole:\n\n");
    foreach ($array_metrics as $k) {
        fwrite(STDERR, "  {$k} - pole se do číselného sloupce uloží jako NULL, graf zůstane prázdný\n");
    }
    fwrite(STDERR, "\nDo sloupce patří POČET (viz bk_wireguard_peer_count()), ne samotný seznam.\n");
    exit(1);
}

$ignored = array_flip($not_metrics);
$problems = [];

foreach ($sent as $key) {
    if (isset($stored[$key]) || isset($ignored[$key])) {
        continue;
    }
    // A key that is not read into metrics but processed differently by the API
    // (e.g. stored into its own table) is not an error - it is just known.
    $where = isset($read[$key]) ? 'čte se, ale neukládá jako metrika' : 'nikdo ho nečte';
    $problems[] = "{$key} - {$where}";
}

if ($problems) {
    fwrite(STDERR, "Agenti posílají hodnoty, které nekončí v metrikách ani nejsou mezi výjimkami:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nBuď hodnotu doplňte do \$metric_row v agent_api.php (a do sloupců,\n");
    fwrite(STDERR, "migrace a mapy metrik), nebo ji zapište do seznamu \$not_metrics\n");
    fwrite(STDERR, "v tomhle skriptu i s důvodem, proč časovou řadou není.\n");
    exit(1);
}

printf(
    "Agent metric lint: %d klíčů od agentů, %d se ukládá jako metrika, %d vědomých výjimek.\n",
    count($sent),
    count($stored),
    count($not_metrics)
);
exit(0);
