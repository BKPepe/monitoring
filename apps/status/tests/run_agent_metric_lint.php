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

/**
 * Keys that do not belong in the metrics table.
 *
 * Not "unfinished" - these are values that are not a time series.
 * A new one belongs here with an explanation, not silently.
 */
$not_metrics = [
    // Protocol and report identification
    'agent_key', 'nonce', 'signature', 'timestamp', 'action', 'action_id',
    'agent_type', 'monitor_id', 'version', 'latest_version', 'auto_update',
    'update_available', 'update_url', 'update_sha256', 'heavy_op_interval_hours',
    // Machine description - changes rarely, a chart makes no sense
    'hostname', 'os', 'kernel', 'model', 'board_name', 'timezone',
    'virtualization', 'cloud_provider', 'uptime', 'boot_time', 'reboot_required',
    // States (true/false) - they belong in events, not charts. A line jumping
    // between 0 and 1 says less than a record "WAN dropped at 3:14".
    'wan_up', 'lte_up', 'tailscale_up', 'sqm_enabled', 'sqm_ecn', 'dns_encryption',
    // LTE backup verdict inputs (agent 0.1.0+): a registration flag, a SIM
    // state word and the modem's raw status codes. A chart of "PIN required"
    // says nothing a red tile and an alert do not.
    'lte_connected', 'lte_sim_state', 'lte_conn_code', 'lte_sim_code', 'lte_service_code', 'lte_sim_status_code', 'lte_sim_pin_left',
    // wan_internet: true/false verdict of one echo bound to the WAN device - state
    // for the wan_lost alert, not a time series.
    'wan_internet',
    // wan_l3_device: the name of the WAN device - a role label for the traffic split, not a number.
    'wan_l3_device',
    'firewall_enabled',
    // Texty a adresy
    'wan_proto', 'wan_ipv4', 'wan_ipv6', 'wan_gateway', 'wan_dns', 'wan_last_reconnect',
    'lan_subnet', 'dns_servers', 'dns_engine', 'ups_status', 'service_name',
    'lte_device', 'lte_carrier', 'lte_plmn', 'lte_band', 'lte_cell_id', 'lte_pci',
    'lte_bandwidth', 'lte_ipv4', 'mwan3_active_gw', 'port', 'process',
    // Structures (arrays/objects) - their own tables or details
    'interfaces', 'processes', 'ports', 'top_cpu_processes', 'top_ram_processes',
    'service_checks', 'service_restarts', 'installed_packages', 'upgradable_packages',
    'usb_devices', 'discovered_services', 'smart', 'ts3_process', 'teamspeak_servers',
    'zerotier_networks', 'wifi_radios', 'mwan3_policies',
    // Pole objektu: filesystemy, disky a procesy podle zapisu. Casovou radou
    // by byla az jednotliva polozka (zaplneni konkretniho oddilu), ne cely
    // seznam - ten se navic mezi behy meni (pripojeny USB disk).
    'filesystems', 'disk_devices', 'top_io_processes',
    // Vysledky mereni rychlosti maji vlastni tabulku (speedtest_results):
    // meri se jednou denne, ne kazdou minutu, takze do rady s minutovym
    // krokem nepatri. `io_accounting` je schopnost jadra, ne metrika.
    'speedtests', 'io_accounting',
    // Cumulative sums where the rate is stored directly
    // (disk_io_read_kbps / disk_io_write_kbps).
    'disk_read_kb', 'disk_write_kb',

    // --- Router release 0.1.7 (release contract X9) --------------------------
    // The list may run ahead of the agent: this lint has no stale-entry check,
    // and it must be green BEFORE the agents gitlink moves.
    //
    // NOT here on purpose, because they are stored columns and an exemption
    // would hide a broken $metric_row: cpu_core_max_pct, cpu_core_max_softirq_pct,
    // wan_rx_mbps, wan_tx_mbps, agent_run_ms, wan_link_mbit.
    //
    // storage_disks: per-disk structure with its own tables (storage_disks,
    // storage_disk_daily). agent_tools: capability flags, not a time series.
    'storage_disks', 'agent_tools',
    // Cumulative counters of the WAN port and of conntrack. The stored series
    // is the STEP the server computes from two reports (wan_errors, wan_drops,
    // conntrack_drops, wan_link_flaps); a raw total only shows uptime.
    'wan_rx_errors', 'wan_tx_errors', 'wan_rx_dropped', 'wan_tx_dropped',
    'conntrack_insert_failed', 'conntrack_drop', 'conntrack_early_drop',
    'wan_carrier_down_count',
    // wan_link_dev: name of the physical WAN port, a label. wan_path: hourly
    // path state (object, last_details). speedtest_active / reduced: flags of
    // THIS report that gate the alerts, not a series.
    'wan_link_dev', 'wan_path', 'speedtest_active', 'reduced',
    // agent_time: the router's clock; the stored metric is clock_skew_s, the
    // distance from the server's clock. cpu_cores: a hardware constant.
    // cpu_core_max_index: WHICH core was busiest - a label, averaging it means nothing.
    'agent_time', 'cpu_cores', 'cpu_core_max_index',
    // dns_resolver_ok: true/false, an event (dns_resolver_failed), not a chart.
    'dns_resolver_ok',
    // Self-observation of the agent. agent_run_ms is the stored one; the total
    // with the POST and the two skip counters feed the reports_missing issue.
    'agent_prev_total_ms', 'runs_skipped_lock', 'runs_skipped_post',
    // Keys of the server RESPONSE that the agent parses by name (the regex above
    // sees them like update_available and action_id).
    'speedtests_acked',
    // The router's own speed test (wave 2 of the release). State object in the
    // report, grant and durable counters in the response.
    'wan_probe_state', 'wan_probe', 'wan_probe_server', 'wan_probe_seed',

    // Nested keys, one block per parent. They are listed because the regex above
    // does not know nesting: written with a single-quoted printf they would fail
    // the lint, and composing them only inside awk (escaped quotes) would pass by
    // an accident of quoting style.
    //
    // Nested in `wan_path`, stored as JSON in last_details.
    'checked_at', 'flow_offloading', 'flow_offloading_hw', 'flowtable_active',
    'packet_steering', 'packet_steering_active', 'wan_rps_mask', 'wan_threaded_napi',
    'wan_rx_ring_drops', 'sqm', 'lan_port_max_mbit', 'lan_port_cap_mbit', 'lan_conduits',
    // Nested in `wan_path.sqm[]`: one queue on a device of the WAN chain.
    'iface', 'download_kbps', 'upload_kbps', 'egress_dropped', 'ingress_dropped',
    // Nested in `wan_path.lan_conduits[]`.
    'dev', 'mbit',
    // Fields of one br-lan member in the ubus device dump the LAN ceiling is
    // parsed from (X5): what KIND of port it is, which conduit it hangs on and
    // what the link negotiated. They describe the wiring, which does not change
    // from minute to minute and has nothing to plot.
    'devtype', 'conduit', 'speed',
    // Nested in `wan_probe_state` (and in the response's `wan_probe_seed`).
    'enabled', 'due', 'window', 'valid_count', 'attempts', 'bootstrap',
    'last_invalid_reason', 'last_at', 'skipped', 'first_grant_at',
    // Item of `speedtests[]`: its own table speedtest_results (`iface` is above,
    // `timestamp` at the top of this list).
    'download_mbps', 'upload_mbps', 'ping_ms', 'jitter_ms', 'server',
    'bytes_received', 'bytes_sent', 'started_by', 'tool', 'link_mbit', 'diagnostics',
    // Nested in `speedtests[].diagnostics`, stored as whitelisted JSON in
    // speedtest_results.diagnostics: what the CPU and the WAN port did during
    // the test. The blocks dl / ul / run / path repeat wan_rx_dropped,
    // wan_rx_errors, conntrack_drop, wan_rx_ring_drops, flow_offloading and
    // packet_steering_active, which are listed above.
    'v', 'cpu_measured', 'path_verified', 'background_dl_mbps', 'background_ul_mbps',
    'samples', 'gaps', 'dl', 'ul', 'run', 'path',
    'secs', 'wan_mbps', 'core', 'core_busy_pct', 'core_user_pct', 'core_system_pct',
    'core_irq_softirq_pct', 'hot_share', 'all_cores_avg_pct', 'rx_packets', 'retrans_pct',
    'sqm_dl_kbps', 'sqm_ul_kbps',
    // nested in diagnostics only; REMOVE when W08 adds the top-level keys
    'softnet_dropped', 'softnet_time_squeeze',
];

// Keys the agents send.
preg_match_all('/"([a-z_0-9]+)":/', $agent_src, $sent_matches);
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
