<?php
/**
 * Keys an agent sends that do not belong in the metrics table.
 *
 * Usage:  $not_metrics = require __DIR__ . '/fixtures/agent_not_metrics.php';
 *
 * Read by run_agent_metric_lint.php (the keys in the agents' sources) and
 * run_tests.php (the Omnia fixture). One list, so they cannot drift apart.
 * Pure data on purpose, like omnia_router.php: find_dead_code.php does not
 * read this directory.
 *
 * Not "unfinished" - these are values that are not a time series.
 * A new one belongs here with an explanation, not silently.
 */
return [
    // Protocol and report identification
    'agent_key', 'nonce', 'signature', 'timestamp', 'action', 'action_id',
    'agent_type', 'monitor_id', 'version', 'latest_version', 'auto_update',
    'update_available', 'update_url', 'update_sha256', 'heavy_op_interval_hours',
    // The one-time registration token (--register, agent 0.1.10 sends the body
    // from a file, so the lint now sees the key) - a secret, never a series.
    'token',
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
    // wan_rx_mbps, wan_tx_mbps, agent_run_ms, wan_link_mbit, and agent_prev_cpu_ms
    // of agent 0.1.9.
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
    // Self-observation of the agent. agent_run_ms and agent_prev_cpu_ms (0.1.9)
    // are the stored ones; the total with the POST and the three skip counters
    // feed the reports_missing issue. runs_skipped_killed (0.1.9) counts runs
    // the lock takeover stopped after 300 s: a count of incidents since the
    // last accepted report, cleared by the next one, so a chart of it would be
    // a sawtooth of the agent's own bookkeeping.
    'agent_prev_total_ms', 'runs_skipped_lock', 'runs_skipped_post', 'runs_skipped_killed',
    // The --selfcheck line (agent 0.1.12+): the updater reads it on the
    // host before a swap; it is never sent to the server.
    'agent_version', 'payload',
    // Keys of the server RESPONSE that the agent parses by name (the regex above
    // sees them like update_available and action_id).
    'speedtests_acked',
    // The router's own speed test (wave 2 of the release). State object in the
    // report, grant and durable counters in the response.
    'wan_probe_state', 'wan_probe', 'wan_probe_server', 'wan_probe_seed',
    // The error lines behind log_errors_24h (agent 0.1.8, W1-C3): kept in
    // last_details only (owner decision 5.7, no history). log_window_secs
    // labels the count ("in the last N h"), it is not a series of its own;
    // log_lines_state says which switch keeps the lines at home. `log_lines`
    // is a key of the server RESPONSE the agent parses by name. ts / prog /
    // msg / count are the items of log_errors_recent[] (composed inside awk).
    'log_errors_recent', 'log_window_secs', 'log_lines_state', 'log_lines',
    'ts', 'prog', 'msg', 'count',

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
    'devtype', 'conduit', 'speed', 'carrier',
    // `lan_ports`: the wired switch, port by port (agent 0.1.8). One object
    // in last_details, like wan_path. `clients` IS a number, but it is a
    // per-port count that belongs to the picture of the switch, not a series
    // of its own; `ports`/`conduits` are lists and the rest are labels and
    // capabilities of the wiring.
    'lan_ports', 'ports', 'conduits', 'link', 'speed_mbit', 'duplex',
    'max_mbit', 'partner_max_mbit', 'clients', 'clients_total',
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
