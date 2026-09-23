<?php
/**
 * API endpoint receiving VPS agent reports
 */

header('Content-Type: application/json');
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Allow POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda není povolena. Použijte POST.']);
    exit;
}

// Read the JSON body of the request
$raw_data = file_get_contents('php://input');
$data = json_decode($raw_data, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    $json_err = json_last_error_msg();
    echo json_encode(['success' => false, 'message' => 'Neplatný JSON formát: ' . $json_err]);
    exit;
}

// --- Standalone Remote Action result acknowledgement ---
// The agent (agent_openwrt.sh) sends this as a lightweight follow-up POST
// right after executing an action, separately from the main telemetry
// (already sent for this cycle). It has no cpu/ram/hdd, so it must be
// handled before the usual required-telemetry validation below.
if (isset($data['action_result']) && is_array($data['action_result']) && !isset($data['cpu'])) {
    $ar_agent_key = bk_agent_str($data, 'agent_key', 128) ?? '';
    if (empty($ar_agent_key)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Chybí agent_key.']);
        exit;
    }
    $stmt_ar_mon = $pdo->prepare("SELECT id, archived_at FROM monitors WHERE agent_key = ? LIMIT 1");
    $stmt_ar_mon->execute([$ar_agent_key]);
    $ar_row = $stmt_ar_mon->fetch();
    $ar_monitor_id = $ar_row ? $ar_row['id'] : false;
    if ($ar_row && !empty($ar_row['archived_at'])) {
        bk_agent_refuse_archived();
    }
    if (!$ar_monitor_id) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Neplatný agent_key.']);
        exit;
    }

    $act_res = $data['action_result'];
    $act_id = intval($act_res['action_id'] ?? 0);
    $act_status = in_array($act_res['status'] ?? '', ['executed', 'failed'], true) ? $act_res['status'] : 'failed';
    $act_msg = trim((string)($act_res['message'] ?? ''));

    if ($act_id > 0) {
        $stmt_act = $pdo->prepare("UPDATE agent_actions SET status = ?, result_message = ?, executed_at = NOW() WHERE id = ? AND monitor_id = ?");
        $stmt_act->execute([$act_status, $act_msg, $act_id, $ar_monitor_id]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// --- Agent-side service check results (OpenWrt v1.5.3+) ---
// A standalone mini-request just like action_result: after the main report
// the agent locally verifies the services from the service_checks list in
// the response and sends the results. A result may only be written to
// 'agent_service' monitors of the SAME asset the agent_key belongs to.
if (isset($data['service_check_results']) && is_array($data['service_check_results']) && !isset($data['cpu'])) {
    $sc_agent_key = bk_agent_str($data, 'agent_key', 128) ?? '';
    if (empty($sc_agent_key)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Chybí agent_key.']);
        exit;
    }
    $stmt_sc_mon = $pdo->prepare("SELECT id, asset_id, archived_at FROM monitors WHERE agent_key = ? LIMIT 1");
    $stmt_sc_mon->execute([$sc_agent_key]);
    $sc_agent = $stmt_sc_mon->fetch();
    if (!$sc_agent) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Neplatný agent_key.']);
        exit;
    }
    if (!empty($sc_agent['archived_at'])) {
        bk_agent_refuse_archived();
    }
    require_once __DIR__ . '/lang.php';
    $sc_applied = 0;
    foreach (array_slice($data['service_check_results'], 0, 50) as $res) {
        if (!is_array($res)) continue;
        $sc_id = (int)($res['monitor_id'] ?? 0);
        if ($sc_id <= 0 || $sc_agent['asset_id'] === null) continue;
        $stmt_svc = $pdo->prepare("SELECT * FROM monitors WHERE id = ? AND asset_id = ? AND type = 'agent_service' AND archived_at IS NULL LIMIT 1");
        $stmt_svc->execute([$sc_id, $sc_agent['asset_id']]);
        $svc_row = $stmt_svc->fetch();
        if (!$svc_row) continue;
        $sc_running = !empty($res['running']);
        $sc_detail = mb_substr(trim((string)($res['detail'] ?? '')), 0, 255);
        bk_apply_agent_service_result($pdo, $svc_row, $sc_running, $sc_detail);
        $sc_applied++;
    }
    echo json_encode(['success' => true, 'applied' => $sc_applied]);
    exit;
}

// --- Automatic agent registration ---
if (isset($data['action']) && $data['action'] === 'register') {
    $token = bk_agent_str($data, 'token', 255) ?? '';
    $reg_token = get_setting('agent_registration_token');
    
    // When no registration token is configured, the cron_key acts as fallback
    if (empty($reg_token)) {
        $reg_token = get_setting('cron_key');
    }
    
    if (empty($reg_token) || !hash_equals((string)$reg_token, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Neplatný registrační token.']);
        exit;
    }
    
    $name = bk_agent_str($data, 'hostname', 100) ?? bk_agent_str($data, 'name', 100) ?? ('VPS Agent ' . date('Y-m-d H:i'));
    $type = (isset($data['agent_type']) && $data['agent_type'] === 'openwrt') ? 'openwrt' : 'vps';
    $agent_key = bin2hex(random_bytes(16));
    
    $stmt = $pdo->prepare("
        INSERT INTO monitors (name, type, target, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold)
        VALUES (?, ?, 'Local VPS Agent', 'unknown', ?, ?, ?, ?)
    ");
    // RAM and disk were swapped here (90/95) against the alerts below.
    $stmt->execute([$name, $type, $agent_key, BK_DEFAULT_THRESHOLDS['cpu'], BK_DEFAULT_THRESHOLDS['ram'], BK_DEFAULT_THRESHOLDS['hdd']]);
    $new_id = (int)$pdo->lastInsertId();
    
    log_monitor_event($pdo, $new_id, $name, $type, 'monitor_added', "Automatická registrace agenta ({$type})");
    
    echo json_encode([
        'success' => true,
        'agent_key' => $agent_key,
        'monitor_id' => $new_id,
        'name' => $name,
        'message' => 'Agent úspěšně zaregistrován.'
    ]);
    exit;
}

// Validate the required fields
// One bad key from an agent - a list where a string belongs - used to be a
// TypeError in trim() and a 500 for the whole report. The helpers below hand
// back NULL for anything that is not the expected shape.
$agent_key = bk_agent_str($data, 'agent_key', 128) ?? '';
$cpu = bk_agent_num($data, 'cpu');
$ram = bk_agent_num($data, 'ram');
$hdd = bk_agent_num($data, 'hdd');
// Network throughput (KB/s) is optional - older agents do not send it at all and
// a new agent returns it only from its second run (needs a previous sample to diff).
$net = bk_agent_num($data, 'net');

// Host layer (Level 2) - all optional, older agents do not send these fields at all.
$cpu_steal = bk_agent_num($data, 'cpu_steal');
$swap = bk_agent_num($data, 'swap');
$load1 = bk_agent_num($data, 'load1');
$load5 = bk_agent_num($data, 'load5');
$load15 = bk_agent_num($data, 'load15');
$disk_io_read = bk_agent_num($data, 'disk_io_read');
$disk_io_write = bk_agent_num($data, 'disk_io_write');
$net_errors = bk_agent_int($data, 'net_errors');

// TeamSpeak process (when the agent runs on the same VPS as ts3server)
$ts3_process = (isset($data['ts3_process']) && is_array($data['ts3_process'])) ? $data['ts3_process'] : null;

// Completion of the Level 2 Host layer - all optional, older agents do not
// send these fields at all (or the platform lacks them, see agent.ps1).
$iowait = bk_agent_num($data, 'iowait');
$inode_usage = bk_agent_num($data, 'inode_usage');
$fork_rate = bk_agent_int($data, 'fork_rate');
$temperature = bk_agent_num($data, 'temperature');
$zombie_count = bk_agent_int($data, 'zombie_count');
$top_cpu_processes = (isset($data['top_cpu_processes']) && is_array($data['top_cpu_processes'])) ? $data['top_cpu_processes'] : null;
$top_ram_processes = (isset($data['top_ram_processes']) && is_array($data['top_ram_processes'])) ? $data['top_ram_processes'] : null;
$sys_hostname = bk_agent_str($data, 'hostname');
$sys_kernel = bk_agent_str($data, 'kernel');
$sys_timezone = bk_agent_str($data, 'timezone');
$reboot_required = bk_agent_bool($data, 'reboot_required');
$cloud_provider = bk_agent_str($data, 'cloud_provider');
$virtualization = bk_agent_str($data, 'virtualization');
$tcp_retrans = bk_agent_int($data, 'tcp_retrans');
$conntrack_count = bk_agent_int($data, 'conntrack_count');

// OpenWrt profile - router identity + WAN interface state (see agent_openwrt.sh).
// hostname/kernel/os above are generic and the router fills them in unchanged here.
$ow_model = bk_agent_str($data, 'model');
$ow_board_name = bk_agent_str($data, 'board_name');
$ow_wan_up = bk_agent_bool($data, 'wan_up');
// One echo bound to the WAN device (agent 0.1.1+): true/false, or null when
// the agent could not try. Feeds bk_wan_link_state() and the wan_lost alert.
$ow_wan_internet = bk_agent_bool($data, 'wan_internet');
$ow_wan_proto = bk_agent_str($data, 'wan_proto');
$ow_wan_ipv4 = bk_agent_str($data, 'wan_ipv4');
$ow_wan_ipv6 = bk_agent_str($data, 'wan_ipv6');
$ow_wan_gateway = bk_agent_str($data, 'wan_gateway');
$ow_wan_dns = bk_agent_str($data, 'wan_dns');
$ow_wan_uptime = bk_agent_int($data, 'wan_uptime');
$ow_btrfs_errors = bk_agent_int($data, 'btrfs_errors');

// OpenWrt Deep Telemetry - WiFi, LAN/DHCP, DNS, Firewall, WireGuard (viz agent_openwrt.sh)
// Sanitized, never the raw list: the radios go straight into last_details and
// from there onto the page, so an out-of-range value would be drawn as a real
// measurement and a BSSID would end up stored. Out of range = null, never a
// bound; unknown keys are dropped (see bk_sanitize_wifi_radios()).
$ow_wifi_radios = bk_sanitize_wifi_radios($data['wifi_radios'] ?? null);
$ow_lan_subnet = bk_agent_str($data, 'lan_subnet');
$ow_dhcp_leases = bk_agent_int($data, 'dhcp_leases_count');
$ow_dhcp_reservations = bk_agent_int($data, 'dhcp_reservations_count');
$ow_dns_queries = bk_agent_int($data, 'dns_queries');
$ow_dns_cache_hits = bk_agent_int($data, 'dns_cache_hits');
$ow_dns_cache_misses = bk_agent_int($data, 'dns_cache_misses');
// Firewall state is three-valued: true/false/null. `null` means "the agent
// could not tell" - older versions did not send it at all - and the UI must be
// able to distinguish that from "disabled", otherwise it would raise an alarm
// about a router it simply knows nothing about. Hence no intval(), which would
// turn null into 0 = disabled.
$ow_firewall_enabled = bk_agent_bool($data, 'firewall_enabled');
$ow_fw_accepted = bk_agent_int($data, 'fw_accepted');
$ow_fw_dropped = bk_agent_int($data, 'fw_dropped');
$ow_fw_rejected = bk_agent_int($data, 'fw_rejected');
$ow_wireguard_peers = (isset($data['wireguard_peers']) && is_array($data['wireguard_peers'])) ? $data['wireguard_peers'] : null;
$ow_conntrack_pct = bk_agent_num($data, 'conntrack_pct');
$ow_swap_pct = bk_agent_num($data, 'swap_pct');
$ow_entropy = bk_agent_int($data, 'entropy');
$ow_upgradable_packages = bk_agent_int($data, 'upgradable_packages');
$ow_wifi_clients_count = bk_agent_int($data, 'wifi_clients_count');
// Per band, and how many clients could use 6 GHz - see bk_wifi_band_totals().
$ow_wifi_bands = bk_wifi_band_totals($ow_wifi_radios);
$ow_net_ipv4_kbps = bk_agent_num($data, 'net_ipv4_kbps');
$ow_net_ipv6_kbps = bk_agent_num($data, 'net_ipv6_kbps');
// Throughput over the LTE backup device and the name of the WAN device
// (agent 0.1.3+): together with wan_lost/wan_restored they tell the two
// links apart on the web.
$ow_net_lte = bk_agent_num($data, 'net_lte');
$ow_wan_l3_device = bk_agent_str($data, 'wan_l3_device', 32);
$heavy_op_interval_hours = bk_agent_int($data, 'heavy_op_interval_hours') ?? 24;

// OpenWrt Round 2 - mwan3, SQM, LTE, services, WAN reconnect, packages/logs
$ow_mwan3_policies = (isset($data['mwan3_policies']) && is_array($data['mwan3_policies'])) ? $data['mwan3_policies'] : null;
$ow_mwan3_active_gw = bk_agent_str($data, 'mwan3_active_gw');
// G24: null stays null. Agent 0.1.7 answers null when there is no SQM
// configuration at all ("not installed" is not "off"), and forcing that to
// false told every such router it had switched a shaper off. Older agents
// always send a boolean, so nothing they report changes.
$ow_sqm_enabled = bk_agent_bool($data, 'sqm_enabled');
$ow_sqm_download_kbps = bk_agent_int($data, 'sqm_download_kbps');
$ow_sqm_upload_kbps = bk_agent_int($data, 'sqm_upload_kbps');
$ow_sqm_dropped = bk_agent_int($data, 'sqm_dropped');
$ow_sqm_ecn = bk_agent_int($data, 'sqm_ecn');
$ow_lte_rsrp = bk_agent_num($data, 'lte_rsrp');
$ow_lte_rsrq = bk_agent_num($data, 'lte_rsrq');
$ow_lte_sinr = bk_agent_num($data, 'lte_sinr');
$ow_lte_band = bk_agent_str($data, 'lte_band', 32);
// Spojeni pres ubus (agent 1.5.9+): modem nemusi byt videt pres mmcli/uqmi,
// ale rozhrani "lte" v ubus ano - odtud se pozna, ze LTE opravdu jede.
$ow_lte_up = bk_agent_bool($data, 'lte_up');
// Signal z HiLink API modemu (agent 1.5.15+) - bez nej zustavaji null.
$ow_lte_rssi = bk_agent_num($data, 'lte_rssi');
$ow_lte_pci = bk_agent_int($data, 'lte_pci');
$ow_lte_cell_id = bk_agent_str($data, 'lte_cell_id');
$ow_lte_bandwidth = bk_agent_str($data, 'lte_bandwidth');
$ow_lte_plmn = bk_agent_str($data, 'lte_plmn');
$ow_lte_device = bk_agent_str($data, 'lte_device');
$ow_lte_uptime = bk_agent_int($data, 'lte_uptime');
$ow_lte_ipv4 = bk_agent_str($data, 'lte_ipv4');
// SIM and registration straight from the modem's HiLink API (agent 0.1.0+).
// `lte_up` only proves the router reaches the modem's LAN side - a HiLink
// modem hands out DHCP with no SIM inserted, so the backup looked alive for
// nine days while it could not carry a packet. Absent = null, never a guess.
$ow_lte_connected = (isset($data['lte_connected']) && is_bool($data['lte_connected'])) ? $data['lte_connected'] : null;
$ow_lte_sim_state = (isset($data['lte_sim_state']) && is_string($data['lte_sim_state']) && $data['lte_sim_state'] !== '')
    ? substr(trim($data['lte_sim_state']), 0, 20) : null;
$ow_lte_conn_code = (isset($data['lte_conn_code']) && is_numeric($data['lte_conn_code'])) ? (int)$data['lte_conn_code'] : null;
$ow_lte_sim_code = (isset($data['lte_sim_code']) && is_numeric($data['lte_sim_code'])) ? (int)$data['lte_sim_code'] : null;
$ow_lte_service_code = (isset($data['lte_service_code']) && is_numeric($data['lte_service_code'])) ? (int)$data['lte_service_code'] : null;
$ow_lte_sim_status_code = (isset($data['lte_sim_status_code']) && is_numeric($data['lte_sim_status_code'])) ? (int)$data['lte_sim_status_code'] : null;
$ow_lte_sim_pin_left = (isset($data['lte_sim_pin_left']) && is_numeric($data['lte_sim_pin_left'])) ? (int)$data['lte_sim_pin_left'] : null;
$ow_lte_carrier = bk_agent_str($data, 'lte_carrier');
$ow_service_restarts = (isset($data['service_restarts']) && is_array($data['service_restarts'])) ? $data['service_restarts'] : null;
$ow_auto_update = isset($data['auto_update']) ? (int)(bool)$data['auto_update'] : null;
$ow_tailscale_up = bk_agent_bool($data, 'tailscale_up');
$ow_tailscale_peers = bk_agent_int($data, 'tailscale_peers');
$ow_zerotier_networks = bk_agent_int($data, 'zerotier_networks');
$ow_ups_status = bk_agent_str($data, 'ups_status');
$ow_ups_battery = bk_agent_int($data, 'ups_battery_pct');
$ow_oom_kills = bk_agent_int($data, 'oom_kills');
$ow_boot_time = bk_agent_int($data, 'boot_time');
$ow_dns_latency_ms = bk_agent_num($data, 'dns_latency_ms');
$ow_openvpn_tunnels = bk_agent_int($data, 'openvpn_tunnels');
$ow_usb_devices = bk_agent_int($data, 'usb_devices');
$ow_wan_reconnect_count = bk_agent_int($data, 'wan_reconnect_count');
$ow_wan_last_reconnect = bk_agent_int($data, 'wan_last_reconnect');
$ow_installed_packages = bk_agent_int($data, 'installed_packages');
$ow_log_errors_24h = bk_agent_int($data, 'log_errors_24h');
$ow_log_warnings_24h = bk_agent_int($data, 'log_warnings_24h');

// --- Router release 0.1.7 ---------------------------------------------------
// Everything below is sanitized BEFORE it reaches $new_data, so the raw
// pass-through further down can never store an unchecked copy of it.
$bk_now = time();
// Only a report that CARRIES the key rewrites the disk list: an older agent
// sends nothing and must not erase the disks the router reported yesterday.
$ow_storage_sent = array_key_exists('storage_disks', $data);
$ow_storage_disks = $ow_storage_sent ? bk_sanitize_storage_disks($data['storage_disks'], $bk_now) : null;
$ow_agent_tools = bk_sanitize_agent_tools($data['agent_tools'] ?? null);
$ow_wan_path = bk_sanitize_wan_path($data['wan_path'] ?? null);
// The wired switch, every run (0.1.8). No carry-over of an older section on
// purpose, unlike storage_disks: which cable is plugged in is live state, and
// a picture kept from a report that did not carry it would be a lie by the
// next minute. An agent that does not send it has no switch picture.
$ow_lan_ports = bk_sanitize_lan_ports($data['lan_ports'] ?? null);
// Busiest core of the minute and how much of it was packet handling.
$ow_cpu_core_max = bk_ranged_num($data['cpu_core_max_pct'] ?? null, 0.0, 100.0);
$ow_cpu_core_max_softirq = bk_ranged_num($data['cpu_core_max_softirq_pct'] ?? null, 0.0, 100.0);
$ow_cpu_core_max_index = bk_ranged_int($data['cpu_core_max_index'] ?? null, 0, 255);
$ow_cpu_cores = bk_ranged_int($data['cpu_cores'] ?? null, 1, 256);
// Per-direction WAN rate. 100 Gbit/s is beyond any router this agent runs on;
// above it the reading is a counter that wrapped, not a measurement.
$ow_wan_rx_mbps = bk_ranged_num($data['wan_rx_mbps'] ?? null, 0.0, 100000.0);
$ow_wan_tx_mbps = bk_ranged_num($data['wan_tx_mbps'] ?? null, 0.0, 100000.0);
$ow_wan_link_dev = bk_agent_str($data, 'wan_link_dev', 32);
// The agent's own runtime, and the two counters of runs it had to skip.
$ow_agent_run_ms = bk_ranged_int($data['agent_run_ms'] ?? null, 0, 600000);
$ow_agent_prev_total_ms = bk_ranged_int($data['agent_prev_total_ms'] ?? null, 0, 600000);
$ow_runs_skipped_lock = bk_ranged_int($data['runs_skipped_lock'] ?? null, 0, 100000);
$ow_runs_skipped_post = bk_ranged_int($data['runs_skipped_post'] ?? null, 0, 100000);
$ow_dns_resolver_ok = bk_agent_bool($data, 'dns_resolver_ok');
$ow_speedtest_active = bk_agent_bool($data, 'speedtest_active');
// Alert hygiene (X17, WAN 3.3): a 45 s test saturates the line and one core of
// a two-core router, so the minute it overlapped says nothing about how the
// router behaves. The agent's own interval flag is one half; the other is the
// result travelling in this very report, which the agent cannot know about
// when it sets the flag.
$bk_speedtest_flagged = $ow_speedtest_active === true
    || bk_speedtest_in_report($data['speedtests'] ?? null, $bk_now);
// A light run that stepped aside for the router's own speed test. It measured
// almost nothing on purpose, so it is not evidence that anything was lost.
$ow_reduced = bk_agent_str($data, 'reduced', 32);
$ow_report_reduced = $ow_reduced === 'wan_probe';
// How far the router's clock is from ours. Stored as an ABSOLUTE value: a
// median of a signed column cannot be derived from a daily avg/min/max.
// The range starts at 0 on purpose: a router whose clock never synced reports
// 1970, and that is exactly the case the clock_skew rule exists for.
$ow_agent_time = bk_ranged_int($data['agent_time'] ?? null, 0, 4000000000);
$ow_clock_skew_s = $ow_agent_time === null ? null : abs($bk_now - $ow_agent_time);

// CPU, RAM and disk may be null: agents send null on their first run and after a
// reboot, because a load needs two readings, and a host that cannot read one
// sends null for good. Refusing those reports turned an honest "not measured
// yet" into a 400 and a new router's first report into an error.
if (empty($agent_key)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Chybí agent_key.']);
    exit;
}

// Look the monitor up by agent_key (any type - an agent can be attached to any monitor)
$stmt = $pdo->prepare("SELECT * FROM monitors WHERE agent_key = ? LIMIT 1");
$stmt->execute([$agent_key]);
$monitor = $stmt->fetch();

if (!$monitor) {
    error_log('[agent_api] Auth failed: invalid agent_key from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' (key prefix: ' . substr($agent_key, 0, 8) . '...)');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Neplatný klíč agenta nebo monitor neexistuje.']);
    exit;
}

if (!empty($monitor['archived_at'])) {
    bk_agent_refuse_archived();
}

$monitor_id = $monitor['id'];
$old_status = $monitor['status'];

// Auto-fill the target for purely agent-based types (vps/openwrt) - admin.php
// does not require one for them, because it either has no network meaning (vps)
// or the agent discovers it itself (openwrt). Never overwrites a target the
// user filled in - only completes an empty one.
if (in_array($monitor['type'], ['vps', 'openwrt'], true) && trim((string)$monitor['target']) === '') {
    $auto_target = null;
    if ($monitor['type'] === 'openwrt') {
        $auto_target = $sys_hostname ?: $ow_wan_ipv4;
    } else {
        $auto_target = $sys_hostname;
    }
    if (!empty($auto_target)) {
        $stmt_target = $pdo->prepare("UPDATE monitors SET target = ? WHERE id = ?");
        $stmt_target->execute([$auto_target, $monitor_id]);
        $monitor['target'] = $auto_target;
    }
}

// Process checks for the VPS
$missing_processes = [];
$monitored_processes_str = $monitor['monitored_processes'] ?? '';
if (!empty($monitored_processes_str)) {
    $monitored_processes = array_filter(array_map('trim', explode(',', $monitored_processes_str)));
    $agent_processes = isset($data['processes']) && is_array($data['processes']) ? $data['processes'] : [];
    foreach ($monitored_processes as $proc) {
        if (!in_array($proc, $agent_processes)) {
            $missing_processes[] = $proc;
        }
    }
}

if (!empty($missing_processes)) {
    $new_status = 'down';
    $error_msg = "Chybí běžící proces: " . implode(', ', $missing_processes);
} else {
    $new_status = 'up';
    $error_msg = null;
}

// Override the status while maintenance is active
if (is_in_maintenance($monitor)) {
    $new_status = 'maintenance';
    $m_desc = $monitor['maintenance_description'] ?: 'Plánovaná údržba';
    $m_end = $monitor['maintenance_end'] ? ' (do ' . date('d.m.Y H:i', strtotime($monitor['maintenance_end'])) . ')' : '';
    $error_msg = $m_desc . $m_end;
}

// Alerts are collected here and sent only after the commit. Sending them from
// inside the transaction meant that a rollback (a metrics INSERT failing, the
// details blob overflowing its column) re-fired the very same alert on the
// next report - the "already sent" latch was rolled back with everything else.
$bk_pending_notifications = [];

try {
    $pdo->beginTransaction();

    // Load past alert states to avoid spamming
    $old_details = json_decode($monitor['last_details'] ?? '{}', true);
    $cpu_alert_sent = $old_details['cpu_alert_sent'] ?? false;
    $ram_alert_sent = $old_details['ram_alert_sent'] ?? false;
    $hdd_alert_sent = $old_details['hdd_alert_sent'] ?? false;

    // If the agent was last marked inactive (cron.php), this successful
    // report is its recovery - record it in the event log for the digest.
    if (!empty($old_details['agent_alert_sent'])) {
        log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'agent_connected', 'Agent se znovu ozval');
    }
    
    // Preset > monitor > default. The preset editor offered thresholds, but
    // the alerts here never read them - a preset saying "alert at 70 %"
    // silently alerted at the monitor's own value instead.
    $eff_thresholds = bk_monitor_thresholds($pdo, $monitor);
    $cpu_threshold = floatval($eff_thresholds['cpu'] ?? BK_DEFAULT_THRESHOLDS['cpu']);
    $ram_threshold = floatval($eff_thresholds['ram'] ?? BK_DEFAULT_THRESHOLDS['ram']);
    $hdd_threshold = floatval($eff_thresholds['hdd'] ?? BK_DEFAULT_THRESHOLDS['hdd']);

    // Hysteresis: an alert clears only five points below its threshold (or
    // below the threshold itself when it is that low). A value hovering
    // around the limit used to fire a fresh warning every other minute - for
    // the admin that is spam, not information. A latch set under a different
    // threshold is stale: the admin raised the limit, so the next crossing
    // must alert again instead of hiding behind the old latch.
    $bk_clear_below = function (float $threshold): float {
        return $threshold > 5 ? $threshold - 5 : $threshold;
    };
    if ($cpu_alert_sent && isset($old_details['cpu_alert_threshold']) && (float)$old_details['cpu_alert_threshold'] !== $cpu_threshold) {
        $cpu_alert_sent = false;
    }
    if ($ram_alert_sent && isset($old_details['ram_alert_threshold']) && (float)$old_details['ram_alert_threshold'] !== $ram_threshold) {
        $ram_alert_sent = false;
    }
    if ($hdd_alert_sent && isset($old_details['hdd_alert_threshold']) && (float)$old_details['hdd_alert_threshold'] !== $hdd_threshold) {
        $hdd_alert_sent = false;
    }
    // X17: while the router's own speed test ran, the CPU of this minute is
    // the test, not the router's work. The latch is left exactly as it was -
    // neither set (no invented alert) nor cleared (an alert that was already
    // sent must not silently end because a test happened to run).
    if ($bk_speedtest_flagged) {
        // nothing: the value is still stored and charted, only not judged
    } elseif ($cpu !== null && $cpu >= $cpu_threshold) {
        if (!$cpu_alert_sent) {
            $bk_pending_notifications[] = ['vps_warning', "Vytížení CPU dosáhlo {$cpu}%."];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'threshold_exceeded', "CPU dosáhlo {$cpu}% (limit {$cpu_threshold}%)");
            $cpu_alert_sent = true;
        }
    } elseif ($cpu !== null && $cpu < $bk_clear_below($cpu_threshold)) {
        $cpu_alert_sent = false;
    }

    if ($ram !== null && $ram >= $ram_threshold) {
        if (!$ram_alert_sent) {
            $bk_pending_notifications[] = ['vps_warning', "Vytížení RAM dosáhlo {$ram}%."];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'threshold_exceeded', "RAM dosáhla {$ram}% (limit {$ram_threshold}%)");
            $ram_alert_sent = true;
        }
    } elseif ($ram !== null && $ram < $bk_clear_below($ram_threshold)) {
        $ram_alert_sent = false;
    }

    if ($hdd !== null && $hdd >= $hdd_threshold) {
        if (!$hdd_alert_sent) {
            $bk_pending_notifications[] = ['vps_warning', "Vytížení disku (HDD) dosáhlo {$hdd}%."];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'threshold_exceeded', "Disk (HDD) dosáhl {$hdd}% (limit {$hdd_threshold}%)");
            $hdd_alert_sent = true;
        }
    } elseif ($hdd !== null && $hdd < $bk_clear_below($hdd_threshold)) {
        $hdd_alert_sent = false;
    }

    // LTE backup of a router. The verdict is bk_lte_backup_state() - the modem's
    // own word on SIM and registration, not the interface flag (see there).
    // Two consecutive bad reports before alerting: a modem re-registering for
    // a minute is not a dead backup, and the latch mirrors the threshold ones
    // so a lost backup is reported once and its recovery once.
    $lte_backup_alert_sent = !empty($old_details['lte_backup_alert_sent']);
    $lte_backup_bad_streak = (int)($old_details['lte_backup_bad_streak'] ?? 0);
    $lte_backup = bk_lte_backup_state([
        'lte_up' => $ow_lte_up,
        'lte_connected' => $ow_lte_connected,
        'lte_sim_state' => $ow_lte_sim_state,
        'lte_sim_pin_left' => $ow_lte_sim_pin_left,
        'lte_conn_code' => $ow_lte_conn_code,
        'lte_sim_status_code' => $ow_lte_sim_status_code,
    ]);
    if ($lte_backup['ok'] === false) {
        $lte_backup_bad_streak++;
        if (!$lte_backup_alert_sent && $lte_backup_bad_streak >= 2) {
            $bk_pending_notifications[] = ['lte_backup_lost', $lte_backup['text']];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'lte_backup_lost', $lte_backup['text']);
            $lte_backup_alert_sent = true;
        }
    } elseif ($lte_backup['ok'] === true) {
        $lte_backup_bad_streak = 0;
        if ($lte_backup_alert_sent) {
            $bk_pending_notifications[] = ['lte_backup_restored', 'Modem je opět přihlášen do mobilní sítě, SIM je připravená.'];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'lte_backup_restored', 'LTE záloha je opět funkční.');
            $lte_backup_alert_sent = false;
        }
    }
    // ok === null: no verdict this round - latch and streak carry over untouched.

    // Primary link (WAN) of a router - bk_wan_link_state(): the interface
    // state plus one echo bound to the WAN device. The router reports this
    // through the LTE backup, so without an alert a dead main line stays
    // unnoticed for as long as the backup holds. Same debounce and latch.
    $wan_alert_sent = !empty($old_details['wan_alert_sent']);
    $wan_bad_streak = (int)($old_details['wan_bad_streak'] ?? 0);
    $wan_link = bk_wan_link_state(['wan_up' => $ow_wan_up, 'wan_internet' => $ow_wan_internet, 'wan_proto' => $ow_wan_proto]);
    if ($wan_link['ok'] === false) {
        $wan_bad_streak++;
        if (!$wan_alert_sent && $wan_bad_streak >= 2) {
            $bk_pending_notifications[] = ['wan_lost', $wan_link['text']];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'wan_lost', $wan_link['text']);
            $wan_alert_sent = true;
        }
    } elseif ($wan_link['ok'] === true) {
        $wan_bad_streak = 0;
        if ($wan_alert_sent) {
            $bk_pending_notifications[] = ['wan_restored', 'Primární připojení (WAN) je opět funkční.'];
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'wan_restored', 'Primární připojení (WAN) je opět funkční.');
            $wan_alert_sent = false;
        }
    }
    
    $old_details = json_decode($monitor['last_details'] ?? '{}', true);
    if (!is_array($old_details)) {
        $old_details = [];
    }
    
    // Step metrics of the WAN port and of conntrack: what the counters grew by
    // since the previous report. The state they are compared with lives in
    // last_details, keyed by the WAN device and the router's uptime, so another
    // netdev's totals or a reboot produce null instead of a fabricated spike.
    $ow_wan_steps = bk_wan_counter_steps(
        $old_details['wan_counters_prev'] ?? null,
        [
            'wan_rx_errors' => bk_ranged_int($data['wan_rx_errors'] ?? null, 0, 2 ** 53),
            'wan_tx_errors' => bk_ranged_int($data['wan_tx_errors'] ?? null, 0, 2 ** 53),
            'wan_rx_dropped' => bk_ranged_int($data['wan_rx_dropped'] ?? null, 0, 2 ** 53),
            'wan_tx_dropped' => bk_ranged_int($data['wan_tx_dropped'] ?? null, 0, 2 ** 53),
            'conntrack_drop' => bk_ranged_int($data['conntrack_drop'] ?? null, 0, 2 ** 53),
            'wan_carrier_down_count' => bk_ranged_int($data['wan_carrier_down_count'] ?? null, 0, 2 ** 53),
            'wan_rx_ring_drops' => $ow_wan_path['wan_rx_ring_drops'] ?? null,
        ],
        $ow_wan_link_dev,
        bk_agent_int($data, 'uptime'),
        $ow_wan_path['checked_at'] ?? null
    );

    // Latches and events of the router (X14, alert sheet 2.2): the link rate
    // of the WAN port against its baseline, the connection table, the firewall
    // rules, the local DNS resolver, restarts and OOM kills. Same debounce and
    // latch pattern as the WAN and LTE alerts above - a signal this report does
    // not carry leaves its latch untouched.
    $bk_router_alerts = bk_router_alert_eval([
        'wan_link_mbit' => bk_agent_num($data, 'wan_link_mbit'),
        'wan_link_dev' => $ow_wan_link_dev,
        'wan_up' => $ow_wan_up,
        'wan_internet' => $ow_wan_internet,
        'conntrack_pct' => $ow_conntrack_pct,
        'conntrack_drops' => $ow_wan_steps['steps']['conntrack_drops'],
        'firewall_enabled' => $ow_firewall_enabled,
        'dns_resolver_ok' => $ow_dns_resolver_ok,
        'uptime' => bk_agent_int($data, 'uptime'),
        'oom_kills' => $ow_oom_kills,
    ], $old_details, $bk_now);
    foreach ($bk_router_alerts['events'] as $bk_ev) {
        // status null = timeline only (X14): conntrack_normal, router_rebooted
        // and oom_kill belong on the page, not in anyone's phone at night.
        if ($bk_ev['status'] !== null) {
            $bk_pending_notifications[] = [$bk_ev['status'], $bk_ev['message']];
        }
        log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], $bk_ev['type'], $bk_ev['message']);
    }

    $bk_agent_type_str = bk_agent_str($data, 'agent_type', 32);
    $new_data = [
        'cpu' => $cpu,
        'ram' => $ram,
        'hdd' => $hdd,
        'net' => $net,
        'cpu_steal' => $cpu_steal,
        'swap' => $swap,
        'load1' => $load1,
        'load5' => $load5,
        'load15' => $load15,
        'disk_io_read' => $disk_io_read,
        'disk_io_write' => $disk_io_write,
        'disk_read_kb' => bk_agent_num($data, 'disk_read_kb') ?? $disk_io_read,
        'disk_write_kb' => bk_agent_num($data, 'disk_write_kb') ?? $disk_io_write,
        'net_errors' => $net_errors,
        'iowait' => $iowait,
        'inode_usage' => $inode_usage,
        'fork_rate' => $fork_rate,
        'temperature' => $temperature,
        'zombie_count' => $zombie_count,
        'tcp_retrans' => $tcp_retrans,
        'conntrack_count' => $conntrack_count,
        'top_cpu_processes' => $top_cpu_processes,
        'top_ram_processes' => $top_ram_processes,
        'hostname' => $sys_hostname,
        'kernel' => $sys_kernel,
        'timezone' => $sys_timezone,
        'reboot_required' => $reboot_required,
        'cloud_provider' => $cloud_provider,
        'virtualization' => $virtualization,
        'missing_processes' => $missing_processes,
        'version' => bk_agent_str($data, 'version', 32),
        // Explicit key: 'version' gets overwritten by the SERVICE version
        // when details are merged (TS3 etc.) - the agent version must survive under its own name.
        'agent_version' => bk_agent_str($data, 'version', 32),
        'uptime' => bk_agent_int($data, 'uptime'),
        'smart' => bk_agent_str($data, 'smart', 255),
        'ports' => isset($data['ports']) && is_array($data['ports']) ? $data['ports'] : [],
        'os' => bk_agent_str($data, 'os', 128),
        // Which agent reports (bash/python/powershell/openwrt) - stored so the
        // dashboard can compare the reported version against the right "latest"
        // number (see bk_get_agent_latest_version() in functions.php).
        'agent_type' => $bk_agent_type_str !== null ? strtolower($bk_agent_type_str) : null,
        'model' => $ow_model,
        'board_name' => $ow_board_name,
        'wan_up' => $ow_wan_up,
        'wan_proto' => $ow_wan_proto,
        'wan_l3_device' => $ow_wan_l3_device,
        'wan_ipv4' => $ow_wan_ipv4,
        'wan_ipv6' => $ow_wan_ipv6,
        'wan_gateway' => $ow_wan_gateway,
        'wan_dns' => $ow_wan_dns,
        'wan_uptime' => $ow_wan_uptime,
        'btrfs_errors' => $ow_btrfs_errors,
        // OpenWrt Deep Telemetry
        'wifi_radios' => $ow_wifi_radios,
        // Router release 0.1.7. Explicit entries, all of them already
        // sanitized above: the pass-through below never overwrites a key the
        // server knows, so this is what makes an unchecked copy impossible.
        'agent_tools' => $ow_agent_tools,
        'wan_path' => $ow_wan_path,
        'lan_ports' => $ow_lan_ports,
        'wan_link_dev' => $ow_wan_link_dev,
        'cpu_cores' => $ow_cpu_cores,
        'cpu_core_max_pct' => $ow_cpu_core_max,
        'cpu_core_max_softirq_pct' => $ow_cpu_core_max_softirq,
        'cpu_core_max_index' => $ow_cpu_core_max_index,
        'wan_rx_mbps' => $ow_wan_rx_mbps,
        'wan_tx_mbps' => $ow_wan_tx_mbps,
        'agent_run_ms' => $ow_agent_run_ms,
        'agent_prev_total_ms' => $ow_agent_prev_total_ms,
        'runs_skipped_lock' => $ow_runs_skipped_lock,
        'runs_skipped_post' => $ow_runs_skipped_post,
        'dns_resolver_ok' => $ow_dns_resolver_ok,
        'speedtest_active' => $ow_speedtest_active,
        'reduced' => $ow_reduced,
        'agent_time' => $ow_agent_time,
        'clock_skew_s' => $ow_clock_skew_s,
        // What the next report compares its counters with (about 240 B). A
        // report that read no counter at all keeps the stored state instead of
        // erasing it - the next step then spans the gap and the day's sum
        // loses nothing, the same rule the function applies per counter.
        'wan_counters_prev' => $ow_wan_steps['state'] ?? ($old_details['wan_counters_prev'] ?? null),
        // Latches of the router rules (X14). All scalars but the baseline, so
        // the 60 kB cap can never shed them; `wan_link_baseline` is the
        // {mbit, since} pair WAN 3.3 names, and `firewall_off_since` is the
        // time the rule `firewall_off` renders.
        'wan_link_baseline' => $bk_router_alerts['state']['wan_link_baseline'],
        'wan_link_low_since' => $bk_router_alerts['state']['wan_link_low_since'],
        'wan_link_bad_streak' => $bk_router_alerts['state']['wan_link_bad_streak'],
        'wan_link_alert_sent' => $bk_router_alerts['state']['wan_link_alert_sent'],
        'conntrack_bad_streak' => $bk_router_alerts['state']['conntrack_bad_streak'],
        'conntrack_full_sent' => $bk_router_alerts['state']['conntrack_full_sent'],
        'firewall_bad_streak' => $bk_router_alerts['state']['firewall_bad_streak'],
        'firewall_alert_sent' => $bk_router_alerts['state']['firewall_alert_sent'],
        'firewall_off_since' => $bk_router_alerts['state']['firewall_off_since'],
        'dns_resolver_bad_streak' => $bk_router_alerts['state']['dns_resolver_bad_streak'],
        'dns_resolver_alert_sent' => $bk_router_alerts['state']['dns_resolver_alert_sent'],
        // When the last OOM kill happened: the insight is limited to 24 h,
        // because the counter itself only resets at the next reboot (G31).
        'oom_kill_at' => $bk_router_alerts['state']['oom_kill_at'],
        'lan_subnet' => $ow_lan_subnet,
        'dhcp_leases_count' => $ow_dhcp_leases,
        'dhcp_reservations_count' => $ow_dhcp_reservations,
        'dns_queries' => $ow_dns_queries,
        'dns_cache_hits' => $ow_dns_cache_hits,
        'dns_cache_misses' => $ow_dns_cache_misses,
        'firewall_enabled' => $ow_firewall_enabled,
        'fw_accepted' => $ow_fw_accepted,
        'fw_dropped' => $ow_fw_dropped,
        'fw_rejected' => $ow_fw_rejected,
        'wireguard_peers' => $ow_wireguard_peers,
        'conntrack_pct' => $ow_conntrack_pct,
        // Columns 11 and 12 of /proc/net/stat/nf_conntrack, cumulative since
        // boot. Neither is a step metric and neither may be summed into
        // conntrack_drops (WAN 3.1.4): early_drop counts entries successfully
        // EVICTED to make room, insert_failed unresolved clashes and dying
        // entries - a busy router, not one refusing connections. early_drop is
        // the evidence line of the rule conntrack_drops; insert_failed is kept
        // for support (a clash bumps drop and insert_failed together, so the
        // pair tells a race apart from a real refusal) and read by no rule.
        // Typed here rather than left to the pass-through: a value a rule
        // renders must not arrive as an arbitrary agent string.
        'conntrack_insert_failed' => bk_ranged_int($data['conntrack_insert_failed'] ?? null, 0, 2 ** 53),
        'conntrack_early_drop' => bk_ranged_int($data['conntrack_early_drop'] ?? null, 0, 2 ** 53),
        'swap_pct' => $ow_swap_pct,
        'entropy' => $ow_entropy,
        'upgradable_packages' => $ow_upgradable_packages,
        'wifi_clients_count' => $ow_wifi_clients_count,
        // OpenWrt Round 2 - mwan3, SQM, LTE, services, WAN reconnect, packages/logs
        'mwan3_policies' => $ow_mwan3_policies,
        'mwan3_active_gw' => $ow_mwan3_active_gw,
        'sqm_enabled' => $ow_sqm_enabled,
        'sqm_download_kbps' => $ow_sqm_download_kbps,
        'sqm_upload_kbps' => $ow_sqm_upload_kbps,
        'sqm_dropped' => $ow_sqm_dropped,
        'sqm_ecn' => $ow_sqm_ecn,
        'lte_rsrp' => $ow_lte_rsrp,
        'lte_rsrq' => $ow_lte_rsrq,
        'lte_sinr' => $ow_lte_sinr,
        'lte_band' => $ow_lte_band,
        'lte_carrier' => $ow_lte_carrier,
        'lte_up' => $ow_lte_up,
        'lte_rssi' => $ow_lte_rssi,
        'lte_pci' => $ow_lte_pci,
        'lte_cell_id' => $ow_lte_cell_id,
        'lte_bandwidth' => $ow_lte_bandwidth,
        'lte_plmn' => $ow_lte_plmn,
        'lte_device' => $ow_lte_device,
        'lte_uptime' => $ow_lte_uptime,
        'lte_ipv4' => $ow_lte_ipv4,
        'lte_connected' => $ow_lte_connected,
        'lte_sim_state' => $ow_lte_sim_state,
        'lte_conn_code' => $ow_lte_conn_code,
        'lte_sim_code' => $ow_lte_sim_code,
        'lte_service_code' => $ow_lte_service_code,
        'lte_sim_status_code' => $ow_lte_sim_status_code,
        'lte_sim_pin_left' => $ow_lte_sim_pin_left,
        'service_restarts' => $ow_service_restarts,
        'auto_update' => $ow_auto_update,
        'tailscale_up' => $ow_tailscale_up,
        'tailscale_peers' => $ow_tailscale_peers,
        'zerotier_networks' => $ow_zerotier_networks,
        'ups_status' => $ow_ups_status,
        'ups_battery_pct' => $ow_ups_battery,
        'oom_kills' => $ow_oom_kills,
        'boot_time' => $ow_boot_time,
        'dns_latency_ms' => $ow_dns_latency_ms,
        'openvpn_tunnels' => $ow_openvpn_tunnels,
        'usb_devices' => $ow_usb_devices,
        'wan_reconnect_count' => $ow_wan_reconnect_count,
        'wan_last_reconnect' => $ow_wan_last_reconnect,
        'installed_packages' => $ow_installed_packages,
        'log_errors_24h' => $ow_log_errors_24h,
        'heavy_op_interval_hours' => $heavy_op_interval_hours,
        'log_warnings_24h' => $ow_log_warnings_24h,
        'cpu_alert_sent' => $cpu_alert_sent,
        'ram_alert_sent' => $ram_alert_sent,
        'hdd_alert_sent' => $hdd_alert_sent,
        'cpu_alert_threshold' => $cpu_threshold,
        'ram_alert_threshold' => $ram_threshold,
        'hdd_alert_threshold' => $hdd_threshold,
        'lte_backup_alert_sent' => $lte_backup_alert_sent,
        'wan_internet' => $ow_wan_internet,
        'net_lte' => $ow_net_lte,
        'wan_alert_sent' => $wan_alert_sent,
        'wan_bad_streak' => $wan_bad_streak,
        'lte_backup_bad_streak' => $lte_backup_bad_streak,
        'agent_alert_sent' => false,
        'agent_last_seen' => time()
    ];

    // The disk list is rewritten only by a report that carries the key: an
    // 0.1.6 agent sends nothing and must not erase yesterday's disks. The
    // timestamp says how old the list on the page is.
    if ($ow_storage_sent) {
        $new_data['storage_disks'] = $ow_storage_disks;
        $new_data['storage_disks_at'] = $bk_now;
    }

    // Disk tables and their alerts (CORE 3.4, 3.5). Non-fatal, like the
    // metrics INSERT below: an older database without the three router tables
    // must still answer the agent, and a disk row is never worth a rollback
    // that would re-fire every other alert of this report.
    //
    // `storage_sample_at` is a SCALAR on purpose: it is the throttle the 60 kB
    // cap can never drop, so a router whose disk list does not fit still gets
    // one hourly pass instead of one per minute.
    $bk_storage = ['fresh' => [], 'states' => [], 'ids' => [], 'events' => [], 'sampled' => false];
    if ($ow_storage_disks) {
        try {
            $bk_storage = bk_storage_record(
                $pdo, $monitor_id, $ow_storage_disks, $data['disk_devices'] ?? null,
                bk_agent_int($data, 'uptime'), $bk_now,
                $old_details['storage_disks'] ?? null,
                isset($old_details['storage_sample_at']) ? (int)$old_details['storage_sample_at'] : null
            );
            if ($bk_storage['sampled']) {
                $new_data['storage_sample_at'] = $bk_now;
                $bk_eval = bk_storage_alert_eval($bk_storage['fresh'], $bk_storage['states'], $bk_now);
                // Only the disks that were really evaluated: a disk in standby
                // keeps the latches it earned, it does not get them rewritten.
                bk_storage_state_save($pdo, $bk_storage['ids'], $bk_eval['states']);
                foreach (array_merge($bk_storage['events'], $bk_eval['events']) as $bk_ev) {
                    $bk_pending_notifications[] = [$bk_ev['status'], $bk_ev['message']];
                    log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], $bk_ev['type'], $bk_ev['message']);
                }
            }
        } catch (PDOException $e) {
            error_log("[agent_api] storage record failed for monitor {$monitor_id}: " . $e->getMessage());
        }
    }

    // Filesystem alerts (CORE 3.5 `fs_full`). Evaluated on EVERY report, not
    // only on the hourly disk pass: a partition fills up in minutes and the
    // two-report streak is what keeps it from crying over a single spike.
    $bk_fs = bk_fs_alert_eval($data['filesystems'] ?? null, $old_details['fs_alerts'] ?? [], $hdd_threshold, $bk_now);
    $new_data['fs_alerts'] = $bk_fs['state'];
    foreach ($bk_fs['events'] as $bk_ev) {
        $bk_pending_notifications[] = [$bk_ev['status'], $bk_ev['message']];
        log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], $bk_ev['type'], $bk_ev['message']);
    }

    // Data the server received and did NOT store. The list is rebuilt by every
    // report, so it says what is being lost right now, and it is what makes
    // the collection issue `ingest_dropped` end by itself.
    $bk_ingest_issues = [];

    // --- Passing through unknown agent keys ---------------------------------
    //
    // Above is the explicit list of fields the server understands (type
    // checks, conversions, defaults). Anything else used to be DROPPED
    // SILENTLY: the agent sent a new metric, it showed up nowhere, and it
    // was only found by manual digging (most recently LTE via ubus). The bug
    // cannot even be spotted in the UI - a missing value looks exactly like "not measured yet".
    //
    // Unknown scalar keys and small arrays therefore pass through unchanged.
    // The explicit list stays where conversion or validation is needed,
    // and takes precedence - a value from it is never overwritten by pass-through.
    $bk_passthrough_skip = [
        // Authentication and control - not part of details.
        'agent_key', 'api_key', 'token', 'secret', 'password',
        // Handled by their own paths (action round-trip, service checks).
        'action_result', 'service_check_results', 'pending_action',
        // Stored as rows in speedtest_results (WAN 3.3). A batch of up to 50
        // results is far too large for the details blob, and it would push
        // the disk list out of it; it may be skipped here only BECAUSE the
        // ingest below really stores it and says so when it cannot.
        'speedtests',
    ];
    $bk_passthrough_added = 0;
    foreach ($data as $bk_key => $bk_val) {
        if (!is_string($bk_key) || $bk_key === '') {
            continue;
        }
        // A key the server knows is not overwritten - its version is typed.
        if (array_key_exists($bk_key, $new_data) || in_array($bk_key, $bk_passthrough_skip, true)) {
            continue;
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $bk_key)) {
            continue;
        }
        if (is_scalar($bk_val) || $bk_val === null) {
            // Strings get the same 8 KB cap as the lists below - the details
            // column is 64 KB for everything together.
            if (is_string($bk_val) && strlen($bk_val) > 8192) {
                $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, 'passthrough_too_large', $bk_key, strlen($bk_val));
                continue;
            }
            $new_data[$bk_key] = $bk_val;
            $bk_passthrough_added++;
        } elseif (is_array($bk_val)) {
            // Size cap: details go into a single column and the agent
            // must not be able to inflate the row without limit.
            $encoded = json_encode($bk_val, JSON_UNESCAPED_UNICODE);
            if ($encoded !== false && strlen($encoded) <= 8192) {
                $new_data[$bk_key] = $bk_val;
                $bk_passthrough_added++;
            } else {
                $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, 'passthrough_too_large', $bk_key, $encoded === false ? null : strlen($encoded));
            }
        }
        // Cap on the number of new keys - same reason. The report is not
        // rejected, but the keys past the cap are said to be lost instead of
        // disappearing the way they did before the pass-through existed.
        if ($bk_passthrough_added >= 64) {
            $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, 'passthrough_key_limit', $bk_key);
            break;
        }
    }
    
    // Process the TeamSpeak statistics from the agent (when sent)
    if (isset($data['teamspeak_servers']) && is_array($data['teamspeak_servers'])) {
        $m_port = $monitor['port'] ?: 9987;
        $parts = explode(':', $monitor['target']);
        if (count($parts) === 2) {
            $m_port = intval($parts[1]);
        }
        
        foreach ($data['teamspeak_servers'] as $ts_srv) {
            if (!is_array($ts_srv)) {
                continue;
            }
            if (bk_agent_int($ts_srv, 'port') === (int)$m_port) {
                $new_data['ts3_clients_online'] = bk_agent_int($ts_srv, 'clients_online');
                $new_data['ts3_clients_max'] = bk_agent_int($ts_srv, 'clients_max');
                $new_data['ts3_name'] = bk_agent_str($ts_srv, 'name', 100) ?? '';
                break;
            }
        }
    }

    // TeamSpeak process (when the agent runs on the same VPS as ts3server) - the PID
    // is compared with the last report; a changed PID = the process was restarted.
    if ($ts3_process !== null) {
        $old_ts3_pid = $old_details['ts3_process']['pid'] ?? null;
        $new_ts3_pid = $ts3_process['pid'] ?? null;
        if ($old_ts3_pid !== null && $new_ts3_pid !== null && $old_ts3_pid != $new_ts3_pid) {
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'process_restarted', "ts3server restartován (PID {$old_ts3_pid} -> {$new_ts3_pid})");
        }
        $new_data['ts3_process'] = $ts3_process;
    }

    if (isset($data['discovered_services']) && is_array($data['discovered_services'])) {
        $new_data['discovered_services'] = $data['discovered_services'];

        // Detect changes in discovered services - event logging
        $old_svcs = is_array($old_details['discovered_services'] ?? null) ? $old_details['discovered_services'] : [];
        $new_svcs = $data['discovered_services'];
        // A name that is not a string cannot be an array key (TypeError).
        $old_names = [];
        foreach ($old_svcs as $os) { if (is_array($os) && ($os['confidence'] ?? 0) >= 50) $old_names[bk_agent_str($os, 'name', 100) ?? ''] = $os; }
        $new_names = [];
        foreach ($new_svcs as $ns) { if (is_array($ns) && ($ns['confidence'] ?? 0) >= 50) $new_names[bk_agent_str($ns, 'name', 100) ?? ''] = $ns; }
        // Newly discovered service
        foreach ($new_names as $sname => $svc) {
            if (!isset($old_names[$sname]) && !empty($old_svcs)) {
                log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'service_discovered', "Nová služba: {$sname} (" . ($svc['confidence'] ?? 0) . "%)");
            }
        }
        // Vanished service
        foreach ($old_names as $sname => $svc) {
            if (!isset($new_names[$sname]) && !empty($new_svcs)) {
                log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'service_lost', "Služba zmizela: {$sname}");
            }
        }
    }

    // --- Events for the rolling-window Network Insights ---
    // The agent's reconnect counter is cumulative since its start; the delta
    // between reports is written as an event so WAN stability can be judged
    // over a sliding window ("disconnected 14x in 7 days"), not just a snapshot.
    try {
        $prev_wr = isset($old_details['wan_reconnect_count']) ? (int)$old_details['wan_reconnect_count'] : null;
        $new_wr = isset($new_data['wan_reconnect_count']) ? (int)$new_data['wan_reconnect_count'] : null;
        if ($prev_wr !== null && $new_wr !== null && $new_wr > $prev_wr) {
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'wan_reconnected', 'WAN reconnect (' . $prev_wr . ' -> ' . $new_wr . ')');
        }
        // IPv6 prefix (/64) change: frequent flapping = unstable delegation from the ISP.
        $prev_v6 = (string)($old_details['wan_ipv6'] ?? '');
        $new_v6 = (string)($new_data['wan_ipv6'] ?? '');
        if ($prev_v6 !== '' && $new_v6 !== '' && $prev_v6 !== $new_v6) {
            $pfx = function ($ip) {
                $bin = @inet_pton($ip);
                return $bin !== false ? substr(bin2hex($bin), 0, 16) : null;
            };
            if ($pfx($prev_v6) !== null && $pfx($prev_v6) !== $pfx($new_v6)) {
                log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'ipv6_prefix_changed', $prev_v6 . ' -> ' . $new_v6);
            }
        }
    } catch (Throwable $e) {}

    // Public IP + ASN collection removed 2026-08-17 at the user's request:
    // the ASN half asked Team Cymru DNS, which ships the router's IP to a
    // third party on every change - against the project's no-third-party
    // stance - and the whole feature is being redesigned. The scrub below
    // actively drops previously collected values, so a stale IP does not
    // linger in last_details pretending to be current.
    unset($old_details['public_ip'], $old_details['asn'], $old_details['asn_name'], $old_details['asn_checked_at']);

    // X15: a light "reduced" run (it stepped aside for the router's own speed
    // test) measured almost nothing ON PURPOSE. It is no evidence that
    // anything was lost, so it neither raises the two records of lost data nor
    // clears what the last full report put there.
    if (!$ow_report_reduced) {
        $new_data['ingest_issues'] = $bk_ingest_issues;
    }

    $merged_details_arr = array_merge($old_details, $new_data);

    // Uklid po starsich agentech: nez existoval json_val(), zapisovaly se
    // zastupne hodnoty jako RETEZEC ("null", '"null"'), takze UI vypisovalo
    // `null · "null"` misto pomlcky. Merge je sam neprepsal - novy agent uz
    // takovy klic vubec neposila. Necha se jen to, co je skutecna hodnota.
    foreach ($merged_details_arr as $mk => $mv) {
        if (is_string($mv) && in_array(trim($mv, " \t\n\r\0\x0B\"'"), ['null', 'NULL', 'undefined'], true)) {
            $merged_details_arr[$mk] = null;
        }
    }

    // last_details is a TEXT column (64 KB). A report pushing the blob past
    // that failed the UPDATE - and, because the next report merged onto the
    // same oversized details, every report after it, until someone noticed the
    // monitor had gone quiet. The largest lists go first; the scalars the UI
    // lives on always fit. What was shed is NAMED in the blob itself
    // (`details_dropped`), because a router whose disk list was dropped used to
    // look exactly like a router without disks.
    $bk_details_limit = 60000;
    // Small keys that must survive: they are what makes a loss visible, plus
    // the 240 B of counter state the next step is computed from.
    $bk_details_protected = ['fs_alerts', 'ingest_issues', 'wan_counters_prev'];
    $bk_details_carried = $ow_report_reduced
        ? array_values(array_filter((array)($old_details['details_dropped'] ?? []), 'is_string'))
        : [];
    $bk_fit = bk_details_fit($merged_details_arr, $bk_details_limit, $bk_details_protected, $bk_details_carried);
    $details = $bk_fit['json'];
    foreach ($bk_fit['dropped'] as $bk_dk) {
        error_log("[agent_api] last_details over {$bk_details_limit} B for monitor {$monitor_id}: dropped '{$bk_dk}'");
    }

    // Write the metrics to the database - including TeamSpeak clients/process when
    // available (see above), so the history chart has data from this run.
    // Non-fatal: if the INSERT fails (missing column in an old DB), the agent still
    // gets a valid response with update info - the error goes to error_log.
    $ts3_clients_online = $new_data['ts3_clients_online'] ?? null;
    $ts3_clients_max = $new_data['ts3_clients_max'] ?? null;
    $ts3_process_cpu = $ts3_process['cpu'] ?? null;
    $ts3_process_ram = $ts3_process['ram_mb'] ?? null;
    try {
        // Column => value. This used to be a positional INSERT with dozens of
        // question marks; shifting a single value would silently write the
        // temperature into the disk-usage column and nobody would notice.
        $metric_row = [
            'cpu_usage' => $cpu,
            'ram_usage' => $ram,
            'hdd_usage' => $hdd,
            'net_usage' => $net,
            'load_avg_1' => $load1,
            'load_avg_5' => $load5,
            'load_avg_15' => $load15,
            'cpu_steal' => $cpu_steal,
            // The VPS agent sends `swap`, OpenWrt `swap_pct` - both are the same
            // percentage. Only the first was stored, so router swap had
            // no history at all (found by run_agent_metric_lint.php).
            'swap_usage' => $swap ?? $ow_swap_pct,
            'disk_io_read_kbps' => $disk_io_read,
            'disk_io_write_kbps' => $disk_io_write,
            'net_errors' => $net_errors,
            'ts_clients_online' => $ts3_clients_online,
            'ts_clients_max' => $ts3_clients_max,
            'ts_process_cpu' => $ts3_process_cpu,
            'ts_process_ram' => $ts3_process_ram,
            'iowait_pct' => $iowait,
            'inode_usage_pct' => $inode_usage,
            'zombie_count' => $zombie_count,
            'fork_rate' => $fork_rate,
            'temperature_c' => $temperature,
            'wifi_clients_total' => $ow_wifi_clients_count,
            'wifi_clients_24g' => $ow_wifi_bands['wifi_clients_24g'],
            'wifi_clients_5g' => $ow_wifi_bands['wifi_clients_5g'],
            'wifi_clients_6g' => $ow_wifi_bands['wifi_clients_6g'],
            'wifi_6e_capable_24g' => $ow_wifi_bands['wifi_6e_capable_24g'],
            'wifi_6e_known_24g' => $ow_wifi_bands['wifi_6e_known_24g'],
            'wifi_6e_capable_5g' => $ow_wifi_bands['wifi_6e_capable_5g'],
            'wifi_6e_known_5g' => $ow_wifi_bands['wifi_6e_known_5g'],
            // Radio conditions per band: a MAX over the AP radios of the band,
            // null when no radio of that band reported one (see
            // bk_wifi_band_totals()). wifi_6e_unserved is three-valued -
            // 1, 0, or null when the clients did not say.
            'wifi_noise_24g' => $ow_wifi_bands['wifi_noise_24g'],
            'wifi_noise_5g' => $ow_wifi_bands['wifi_noise_5g'],
            'wifi_noise_6g' => $ow_wifi_bands['wifi_noise_6g'],
            'wifi_busy_24g' => $ow_wifi_bands['wifi_busy_24g'],
            'wifi_busy_5g' => $ow_wifi_bands['wifi_busy_5g'],
            'wifi_busy_6g' => $ow_wifi_bands['wifi_busy_6g'],
            'wifi_busy_other_24g' => $ow_wifi_bands['wifi_busy_other_24g'],
            'wifi_busy_other_5g' => $ow_wifi_bands['wifi_busy_other_5g'],
            'wifi_busy_other_6g' => $ow_wifi_bands['wifi_busy_other_6g'],
            'wifi_weak_clients' => $ow_wifi_bands['wifi_weak_clients'],
            'wifi_wpa2_clients' => $ow_wifi_bands['wifi_wpa2_clients'],
            'wifi_6e_unserved' => $ow_wifi_bands['wifi_6e_unserved'],
            'wifi_5g_capable_24g' => $ow_wifi_bands['wifi_5g_capable_24g'],
            'conntrack_pct' => $ow_conntrack_pct,
            'net_ipv4_kbps' => $ow_net_ipv4_kbps,
            'net_ipv6_kbps' => $ow_net_ipv6_kbps,
            'net_lte_kbps' => $ow_net_lte,
            'lte_rsrp' => $ow_lte_rsrp,

            // Newly stored metrics - they were sent every minute before and
            // overwritten in last_details, so no history survived.
            'wan_latency_ms' => bk_agent_num($data, 'wan_latency_ms'),
            'dns_latency_ms' => bk_agent_num($data, 'dns_latency_ms'),
            'entropy_avail' => bk_agent_num($data, 'entropy'),
            'lte_rsrq' => bk_agent_num($data, 'lte_rsrq'),
            'lte_rssi' => bk_agent_num($data, 'lte_rssi'),
            'lte_sinr' => bk_agent_num($data, 'lte_sinr'),
            'lte_uptime_secs' => bk_agent_num($data, 'lte_uptime'),
            'ups_battery_pct' => bk_agent_num($data, 'ups_battery_pct'),
            'conntrack_count' => bk_agent_num($data, 'conntrack_count'),
            'dhcp_leases_count' => bk_agent_num($data, 'dhcp_leases_count'),
            'dhcp_reservations_count' => bk_agent_num($data, 'dhcp_reservations_count'),
            'tailscale_peers' => bk_agent_num($data, 'tailscale_peers'),
            // G26: a LIST of peers, so bk_agent_num() wrote NULL here every
            // minute since the column exists. The count is the metric.
            'wireguard_peers' => bk_wireguard_peer_count($ow_wireguard_peers),
            'openvpn_tunnels' => bk_agent_num($data, 'openvpn_tunnels'),
            'ram_used_mb' => bk_agent_num($data, 'ram_used_mb'),
            'ram_free_mb' => bk_agent_num($data, 'ram_free_mb'),
            'ram_available_mb' => bk_agent_num($data, 'ram_available_mb'),
            'ram_total_mb' => bk_agent_num($data, 'ram_total_mb'),
            'wan_link_mbit' => bk_agent_num($data, 'wan_link_mbit'),
            'wan_uptime_secs' => bk_agent_num($data, 'wan_uptime'),
            'log_errors_24h' => bk_agent_num($data, 'log_errors_24h'),
            'log_warnings_24h' => bk_agent_num($data, 'log_warnings_24h'),
            'btrfs_errors' => bk_agent_num($data, 'btrfs_errors'),
            'sqm_download_kbps' => bk_agent_num($data, 'sqm_download_kbps'),
            'sqm_upload_kbps' => bk_agent_num($data, 'sqm_upload_kbps'),
            'fw_accepted' => bk_agent_int($data, 'fw_accepted'),
            'fw_dropped' => bk_agent_int($data, 'fw_dropped'),
            'fw_rejected' => bk_agent_int($data, 'fw_rejected'),
            'dns_queries' => bk_agent_int($data, 'dns_queries'),
            'dns_cache_hits' => bk_agent_int($data, 'dns_cache_hits'),
            'dns_cache_misses' => bk_agent_int($data, 'dns_cache_misses'),
            'tcp_retrans' => bk_agent_int($data, 'tcp_retrans'),
            'oom_kills' => bk_agent_int($data, 'oom_kills'),
            'sqm_dropped' => bk_agent_int($data, 'sqm_dropped'),
            'wan_reconnect_count' => bk_agent_int($data, 'wan_reconnect_count'),

            // Router release 0.1.7. The five counters are STEPS - what grew
            // since the previous report - and they are null on a reboot or a
            // device change, where the raw total says nothing about this
            // minute. clock_skew_s is the absolute distance between the
            // router's clock and ours.
            'cpu_core_max' => $ow_cpu_core_max,
            'cpu_core_max_softirq' => $ow_cpu_core_max_softirq,
            'wan_rx_mbps' => $ow_wan_rx_mbps,
            'wan_tx_mbps' => $ow_wan_tx_mbps,
            'wan_errors' => $ow_wan_steps['steps']['wan_errors'],
            'wan_drops' => $ow_wan_steps['steps']['wan_drops'],
            'wan_ring_drops' => $ow_wan_steps['steps']['wan_ring_drops'],
            'wan_link_flaps' => $ow_wan_steps['steps']['wan_link_flaps'],
            'conntrack_drops' => $ow_wan_steps['steps']['conntrack_drops'],
            'agent_run_ms' => $ow_agent_run_ms,
            'clock_skew_s' => $ow_clock_skew_s,
        ];

        // Column names come from the code above, not from agent input.
        $metric_cols = array_keys($metric_row);
        $stmt_metrics = $pdo->prepare(
            "INSERT INTO vps_metrics (monitor_id, " . implode(", ", $metric_cols) . ")"
            . " VALUES (?" . str_repeat(", ?", count($metric_cols)) . ")"
        );
        $stmt_metrics->execute(array_merge([$monitor_id], array_values($metric_row)));
    } catch (PDOException $e) {
        $metrics_error = $e->getMessage();
        error_log('[agent_api] Metrics INSERT failed (monitor ' . $monitor_id . '): ' . $metrics_error);
        // A minute of measurements that reached the server and is not in the
        // database. Said out loud in last_details (issue `ingest_dropped`),
        // because an empty chart looks exactly like a router that sent nothing.
        if (!$ow_report_reduced) {
            $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, 'metrics_insert_failed');
            $merged_details_arr['ingest_issues'] = $bk_ingest_issues;
            $bk_fit = bk_details_fit($merged_details_arr, $bk_details_limit, $bk_details_protected, $bk_details_carried);
            $details = $bk_fit['json'];
        }
    }

    /**
     * Link speed test results (librespeed-cli on the router).
     *
     * The router parks them in /tmp, which is a ramdisk on OpenWrt - gone
     * after a reboot. The agent therefore sends them here and this table is
     * the durable storage; the unique key on (monitor, measurement time)
     * makes re-sending the same file a no-op.
     *
     * A bare 200 is not a receipt (WAN 3.1.6). The response says how far the
     * batch was really dealt with (`speedtests_acked`) and the agent deletes
     * its files only up to that item: before this, a failing INSERT was
     * logged, the agent got its 200 and threw away results nobody stored.
     * The ingest therefore runs BEFORE last_details is written, so what it
     * lost travels in the same blob as everything else (X15).
     */
    $bk_speedtests_acked = null;
    if (isset($data['speedtests']) && is_array($data['speedtests'])) {
        // Cap on the batch: the agent sends its history on the first run (it
        // re-sends everything once, so pre-0.1.7 rows can be repaired), but
        // the report must not grow without bound.
        $speed_batch = array_slice($data['speedtests'], 0, 50);
        $bk_speed_handled = [];
        $bk_speed_failed = [];
        $stmt_speed = null;
        try {
            $stmt_speed = $pdo->prepare("
                INSERT INTO speedtest_results
                    (monitor_id, measured_at, download_mbps, upload_mbps, ping_ms, jitter_ms, server_name, source,
                     iface, tool, link_mbit, bytes_received, bytes_sent, diagnostics)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    download_mbps = IF(VALUES(download_mbps) IS NOT NULL
                            AND (download_mbps IS NULL OR (download_mbps < 0.1 AND VALUES(download_mbps) > download_mbps)),
                        VALUES(download_mbps), download_mbps),
                    upload_mbps = IF(VALUES(upload_mbps) IS NOT NULL
                            AND (upload_mbps IS NULL OR (upload_mbps < 0.1 AND VALUES(upload_mbps) > upload_mbps)),
                        VALUES(upload_mbps), upload_mbps),
                    ping_ms = COALESCE(ping_ms, VALUES(ping_ms)),
                    jitter_ms = COALESCE(jitter_ms, VALUES(jitter_ms)),
                    server_name = COALESCE(server_name, VALUES(server_name)),
                    iface = COALESCE(iface, VALUES(iface)),
                    tool = COALESCE(tool, VALUES(tool)),
                    link_mbit = COALESCE(link_mbit, VALUES(link_mbit)),
                    bytes_received = COALESCE(bytes_received, VALUES(bytes_received)),
                    bytes_sent = COALESCE(bytes_sent, VALUES(bytes_sent)),
                    diagnostics = COALESCE(diagnostics, VALUES(diagnostics))
            ");
        } catch (PDOException $e) {
            // An old database without the six columns of this release. Nothing
            // is stored and nothing is acked, so the router keeps its files.
            error_log('[agent_api] speedtest INSERT prepare failed (monitor ' . $monitor_id . '): ' . $e->getMessage());
        }

        foreach ($speed_batch as $st) {
            $bk_item = bk_speedtest_item($st);
            if ($bk_item === null) {
                // No measurement time: there is no saying when it applied and
                // "now" would be a lie. Re-sending repairs nothing, so it is
                // NOT a reason to hold the ack back - it is said out loud instead.
                $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, 'speedtest_rejected',
                    is_array($st) && isset($st['timestamp']) && is_scalar($st['timestamp']) ? (string)$st['timestamp'] : null);
                continue;
            }
            foreach ($bk_item['issues'] as [$bk_issue_type, $bk_issue_key]) {
                $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, $bk_issue_type, $bk_issue_key);
            }
            if ($stmt_speed === null) {
                $bk_speed_failed[] = $bk_item;
                continue;
            }
            try {
                $stmt_speed->execute(array_merge([$monitor_id], array_values($bk_item['row'])));
                $bk_speed_handled[] = $bk_item;
            } catch (PDOException $e) {
                // Speed measurements are an extra - failing to store them must
                // not bring down telemetry ingestion. The ack stops here, so
                // the data stays on the router and comes back next minute.
                error_log('[agent_api] Uložení speedtestu selhalo: ' . $e->getMessage());
                $bk_speed_failed[] = $bk_item;
                break;
            }
        }
        if ($bk_speed_failed !== []) {
            $bk_ingest_issues = bk_ingest_issue_add($bk_ingest_issues, 'speedtest_store_failed', $bk_speed_failed[0]['raw_ts']);
        }
        $bk_speedtests_acked = bk_speedtest_ack($bk_speed_handled, $bk_speed_failed);
    }

    // What the speedtest ingest lost goes into the SAME blob as the rest
    // (X15): the details are built above, so they are refitted here exactly
    // like the metrics INSERT does when it fails.
    if (!$ow_report_reduced && $bk_ingest_issues !== ($merged_details_arr['ingest_issues'] ?? [])) {
        $merged_details_arr['ingest_issues'] = $bk_ingest_issues;
        $bk_fit = bk_details_fit($merged_details_arr, $bk_details_limit, $bk_details_protected, $bk_details_carried);
        $details = $bk_fit['json'];
    }

    // Process history - who was eating CPU and memory this minute.
    //
    // Agents have been sending these rankings for a long time, but they ended
    // in last_details where the next report overwrote them a minute later.
    // The chart showed THAT the CPU jumped at 19:40, but not WHAT did it.
    // The same snapshot is written here with a timestamp so it can be traced back.
    //
    // Stored only while history is enabled (process_history_days > 0) -
    // small hostings should be able to turn it off.
    $bk_proc_days = (int)get_setting('process_history_days', '30');
    if ($bk_proc_days > 0) {
        $bk_proc_rows = [];
        foreach ([['cpu', $top_cpu_processes], ['ram', $top_ram_processes]] as [$bk_kind, $bk_list]) {
            if (!is_array($bk_list)) {
                continue;
            }
            // Cap on the count: the agent sends five, it must not be able to write a thousand.
            foreach (array_slice($bk_list, 0, 10) as $bk_proc) {
                if (!is_array($bk_proc)) {
                    continue;
                }
                $bk_name = trim((string)($bk_proc['name'] ?? ''));
                if ($bk_name === '') {
                    continue;
                }
                // A missing value stays NULL. Zero would claim "measured,
                // the process did nothing" - which is different from "we do not know".
                $bk_cpu = isset($bk_proc['cpu']) && is_numeric($bk_proc['cpu']) ? (float)$bk_proc['cpu'] : null;
                $bk_ram = isset($bk_proc['ram_mb']) && is_numeric($bk_proc['ram_mb']) ? (float)$bk_proc['ram_mb'] : null;
                if ($bk_cpu === null && $bk_ram === null) {
                    continue;
                }
                $bk_pid = isset($bk_proc['pid']) && is_numeric($bk_proc['pid']) ? (int)$bk_proc['pid'] : null;
                $bk_proc_rows[] = [$monitor_id, $bk_kind, mb_substr($bk_name, 0, 64), $bk_pid, $bk_cpu, $bk_ram];
            }
        }

        if ($bk_proc_rows) {
            try {
                $bk_stmt_proc = $pdo->prepare(
                    "INSERT INTO process_samples (monitor_id, sampled_at, kind, name, pid, cpu_pct, ram_mb)"
                    . " VALUES (?, NOW(), ?, ?, ?, ?, ?)"
                );
                foreach ($bk_proc_rows as $bk_row) {
                    $bk_stmt_proc->execute($bk_row);
                }
            } catch (PDOException $e) {
                // Process history is an extra - if it fails to write, the
                // agent's report must not fail with it. Metrics matter more.
                error_log('[agent_api] process_samples INSERT failed (monitor ' . $monitor_id . '): ' . $e->getMessage());
            }
        }
    }

    // Cumulative interface traffic (LAN, WAN, pppoe-wan, ...) with reboot protection
    $interfaces_payload = (isset($data['interfaces']) && is_array($data['interfaces'])) ? $data['interfaces'] : null;
    if (!empty($interfaces_payload)) {
        $today_str = date('Y-m-d');
        // Prepared once for all interfaces (a router lists ten to fifteen).
        // Prepares are server-side here (no emulation), so a table that the
        // installer could not create fails right now - and that must stay a
        // skipped extra, not a 500 for the whole report.
        $stmt_if = null;
        $stmt_upsert = null;
        try {
            $stmt_if = $pdo->prepare("SELECT last_rx_bytes, last_tx_bytes, last_rx_packets, last_tx_packets FROM monitor_interface_traffic WHERE monitor_id = ? AND iface = ? ORDER BY date DESC LIMIT 1");
            $stmt_upsert = $pdo->prepare("
                INSERT INTO monitor_interface_traffic (
                    monitor_id, iface, date, rx_bytes_total, tx_bytes_total, rx_packets_total, tx_packets_total,
                    last_rx_bytes, last_tx_bytes, last_rx_packets, last_tx_packets
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    rx_bytes_total = rx_bytes_total + VALUES(rx_bytes_total),
                    tx_bytes_total = tx_bytes_total + VALUES(tx_bytes_total),
                    rx_packets_total = rx_packets_total + VALUES(rx_packets_total),
                    tx_packets_total = tx_packets_total + VALUES(tx_packets_total),
                    last_rx_bytes = VALUES(last_rx_bytes),
                    last_tx_bytes = VALUES(last_tx_bytes),
                    last_rx_packets = VALUES(last_rx_packets),
                    last_tx_packets = VALUES(last_tx_packets)
            ");
        } catch (PDOException $e) {
            error_log('[agent_api] Interface traffic update skipped: ' . $e->getMessage());
            $interfaces_payload = [];
        }
        foreach ($interfaces_payload as $ifitem) {
            if (!is_array($ifitem)) continue;
            $ifname = bk_agent_str($ifitem, 'iface', 64) ?? '';
            if (!$ifname || in_array($ifname, ['lo', 'ifb0', 'ifb1'], true)) continue;

            // A missing counter is NOT zero - such a "measurement" would produce
            // a nonsense delta (a jump to the full value on the next report).
            if (!isset($ifitem['rx_bytes'], $ifitem['tx_bytes'])) {
                continue;
            }
            $cur_rx_b = (float)$ifitem['rx_bytes'];
            $cur_tx_b = (float)$ifitem['tx_bytes'];
            $cur_rx_p = isset($ifitem['rx_packets']) ? (int)$ifitem['rx_packets'] : 0;
            $cur_tx_p = isset($ifitem['tx_packets']) ? (int)$ifitem['tx_packets'] : 0;

            try {
                $stmt_if->execute([$monitor_id, $ifname]);
                $prevrow = $stmt_if->fetch();

                $d_rx_b = $cur_rx_b; $d_tx_b = $cur_tx_b; $d_rx_p = $cur_rx_p; $d_tx_p = $cur_tx_p;
                if ($prevrow) {
                    $p_rx_b = (float)$prevrow['last_rx_bytes'];
                    $p_tx_b = (float)$prevrow['last_tx_bytes'];
                    $p_rx_p = (int)$prevrow['last_rx_packets'];
                    $p_tx_p = (int)$prevrow['last_tx_packets'];

                    $d_rx_b = ($cur_rx_b >= $p_rx_b) ? ($cur_rx_b - $p_rx_b) : $cur_rx_b;
                    $d_tx_b = ($cur_tx_b >= $p_tx_b) ? ($cur_tx_b - $p_tx_b) : $cur_tx_b;
                    $d_rx_p = ($cur_rx_p >= $p_rx_p) ? ($cur_rx_p - $p_rx_p) : $cur_rx_p;
                    $d_tx_p = ($cur_tx_p >= $p_tx_p) ? ($cur_tx_p - $p_tx_p) : $cur_tx_p;
                }

                $stmt_upsert->execute([
                    $monitor_id, $ifname, $today_str, $d_rx_b, $d_tx_b, $d_rx_p, $d_tx_p,
                    $cur_rx_b, $cur_tx_b, $cur_rx_p, $cur_tx_p
                ]);
            } catch (PDOException $e) {
                error_log('[agent_api] Interface traffic update skipped: ' . $e->getMessage());
            }
        }
    }

    if (in_array($monitor['type'], ['vps', 'openwrt'], true)) {
        // The router measures its own latency (wan_latency_ms, agent 1.5.7+).
        // The hosting used to ping the router's WAN IP as well - inside this
        // transaction, with the monitors row locked for the ping's full
        // timeout - and most routers drop ICMP anyway, so after a second or
        // two of waiting the answer was NULL. Without a measurement: NULL.
        $ping_ms = null;
        if (isset($data['wan_latency_ms']) && is_numeric($data['wan_latency_ms'])) {
            $ping_ms = (float)$data['wan_latency_ms'];
        }

        // Write the regular check log
        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message) VALUES (?, ?, ?, ?)");
        $stmt_log->execute([$monitor_id, $new_status, $ping_ms, $error_msg]);
        
        // Check for a status change
        if ($old_status !== $new_status) {
            $stmt_update = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
            $stmt_update->execute([$new_status, $details, $monitor_id]);
            
            // Status change - trigger notifications (unless in maintenance).
            // Coming back UP after a planned window is not an outage recovery,
            // so it gets no "back online" alert - unless an incident is still
            // open, which means the outage predates the window and the record
            // has to close.
            $bk_maint_to_up = ($old_status === 'maintenance' && $new_status === 'up');
            if ($new_status !== 'maintenance'
                && bk_should_notify_status_change((string)$old_status, $new_status,
                    $bk_maint_to_up ? bk_has_open_incident($pdo, (int)$monitor_id) : false)) {
                $bk_pending_notifications[] = [$new_status, $error_msg ?: 'Server opět komunikuje.'];
            }
        } else {
            // Only update the last-check time and metrics
            $stmt_update = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
            $stmt_update->execute([$details, $monitor_id]);
        }
    } else {
        // For other monitor types just store the load data (status is driven by the network check in cron)
        $stmt_update = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
        $stmt_update->execute([$details, $monitor_id]);
    }
    
    if (isset($data['action_result']) && is_array($data['action_result'])) {
        $act_res = $data['action_result'];
        $act_id = intval($act_res['action_id'] ?? 0);
        $act_status = bk_agent_str($act_res, 'status', 32) ?? 'failed';
        $act_msg = bk_agent_str($act_res, 'message', 1000) ?? '';
        
        if ($act_id > 0) {
            $stmt_act = $pdo->prepare("UPDATE agent_actions SET status = ?, result_message = ?, executed_at = NOW() WHERE id = ? AND monitor_id = ?");
            $stmt_act->execute([$act_status, $act_msg, $act_id, $monitor_id]);
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    // Committed - now the alerts (see $bk_pending_notifications above).
    foreach ($bk_pending_notifications as [$bk_notify_status, $bk_notify_message]) {
        trigger_notifications($pdo, $monitor, $bk_notify_status, $bk_notify_message);
    }

    $response_payload = ['success' => true, 'message' => 'Metriky uloženy a stav aktualizován.'];

    // How far the batch of speed tests was dealt with (WAN 3.1.6). The agent
    // deletes its probe files and advances `last_sent` only up to this
    // timestamp and never on a bare 200; null means nothing was, so
    // everything is re-sent (the unique key makes that idempotent).
    if (isset($data['speedtests']) && is_array($data['speedtests'])) {
        $response_payload['speedtests_acked'] = $bk_speedtests_acked;
    }

    // If the metrics INSERT failed, tell the agent (visible in its log)
    if (!empty($metrics_error)) {
        $response_payload['schema_warning'] = 'DB schema out of date - metrics not saved. Please update database schema.';
    }

    // Check the pending action queue for this agent. Consent is verified
    // again here (not only at enqueue time in admin.php) - the monitor may
    // have been reconfigured meanwhile and may no longer allow the action.
    // --- Agent-side service checks ---
    // 'agent_service' monitors of the same asset: agents that include live
    // port/process lists in their report (agent.sh, agent.py) are evaluated
    // right here; the others (OpenWrt) get a check list in the response
    // and the results arrive shortly after as service_check_results.
    try {
        if ($monitor['asset_id'] !== null) {
            $stmt_svcs = $pdo->prepare("SELECT * FROM monitors WHERE asset_id = ? AND type = 'agent_service' AND id != ? AND archived_at IS NULL");
            $stmt_svcs->execute([$monitor['asset_id'], $monitor_id]);
            $agent_services = $stmt_svcs->fetchAll();

            if (!empty($agent_services)) {
                $report_ports = isset($data['ports']) && is_array($data['ports']) ? array_map('intval', $data['ports']) : null;
                $report_procs = isset($data['processes']) && is_array($data['processes']) ? array_map('strval', $data['processes']) : null;

                if ($report_ports !== null || $report_procs !== null) {
                    foreach ($agent_services as $svc_row) {
                        $svc_port = $svc_row['port'] !== null ? (int)$svc_row['port'] : null;
                        $svc_proc = trim((string)$svc_row['target']);
                        $port_ok = $svc_port !== null && $report_ports !== null ? in_array($svc_port, $report_ports, true) : null;
                        $proc_ok = $svc_proc !== '' && $report_procs !== null ? in_array($svc_proc, $report_procs, true) : null;
                        // Without a single verifiable signal nothing is written -
                        // "we do not know" is not a measurement.
                        if ($port_ok === null && $proc_ok === null) continue;
                        $running = ($port_ok === true) || ($proc_ok === true);
                        $detail = $running ? '' : sprintf(
                            'Agent nehlásí %s.',
                            $svc_port !== null && $svc_proc !== ''
                                ? "proces '{$svc_proc}' ani otevřený port {$svc_port}"
                                : ($svc_port !== null ? "otevřený port {$svc_port}" : "proces '{$svc_proc}'")
                        );
                        bk_apply_agent_service_result($pdo, $svc_row, $running, $detail);
                    }
                } else {
                    $response_payload['service_checks'] = array_map(fn($s) => [
                        'monitor_id' => (int)$s['id'],
                        'process' => (string)$s['target'],
                        'port' => $s['port'] !== null ? (int)$s['port'] : 0,
                    ], $agent_services);
                }
            }
        }
    } catch (PDOException $e) {}

    try {
        $ra_allowed_here = !empty($monitor['remote_actions_enabled'])
            ? array_filter(explode(',', (string)($monitor['allowed_actions'] ?? '')))
            : [];

        if (!empty($ra_allowed_here)) {
            $stmt_pact = $pdo->prepare("SELECT id, action_type, service_name FROM agent_actions WHERE monitor_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1");
            $stmt_pact->execute([$monitor_id]);
            $pending_act = $stmt_pact->fetch();

            if ($pending_act && in_array($pending_act['action_type'], $ra_allowed_here, true)) {
                $action_id = (int)$pending_act['id'];
                $action_type = $pending_act['action_type'];
                $timestamp = time();
                $nonce = bin2hex(random_bytes(8));

                // HMAC-SHA256 signature of the request with the monitor's key ($monitor['agent_key'])
                $sig_payload = "action={$action_type}|ts={$timestamp}|nonce={$nonce}";
                $signature = hash_hmac('sha256', $sig_payload, $monitor['agent_key']);

                $response_payload['pending_action'] = [
                    'action_id' => $action_id,
                    'action' => $action_type,
                    'timestamp' => $timestamp,
                    'nonce' => $nonce,
                    'signature' => $signature
                ];
                // service_name travels outside the signed string - extending the signature
                // would break already-deployed agents (they compute HMAC from action|ts|nonce).
                // Agents validate the name with the [A-Za-z0-9_.@-] regex and transport is HTTPS.
                if (!empty($pending_act['service_name'])) {
                    $response_payload['pending_action']['service_name'] = $pending_act['service_name'];
                }

                // Mark as 'sent' IMMEDIATELY, not only after the agent acks - otherwise
                // the same (still 'pending') action would be re-signed and re-sent
                // on every subsequent poll until the ack arrives. For reboot_router
                // that would mean a reboot loop on a router that recovers more
                // slowly than one cron interval.
                $stmt_mark_sent = $pdo->prepare("UPDATE agent_actions SET status = 'sent' WHERE id = ?");
                $stmt_mark_sent->execute([$action_id]);
            }
        }
    } catch (PDOException $e) {}

    // Available agent update info - the version is read straight from the agent
    // files on the server (see bk_get_agent_latest_version() in functions.php),
    // so it is maintained in exactly one place (the script itself).
    // Typed like every other input: this runs after the commit, so a
    // TypeError here answered 500 to an agent whose report was already
    // stored - and it retried forever.
    $agent_type = strtolower(bk_agent_str($data, 'agent_type', 32) ?? '');
    $client_version = bk_agent_str($data, 'version', 32) ?? '';
    $agent_files = bk_agent_files();

    if ($client_version !== '' && isset($agent_files[$agent_type])) {
        $latest_version = bk_get_agent_latest_version($agent_type);
        if ($latest_version !== null) {
            $agent_file = __DIR__ . '/' . $agent_files[$agent_type];
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

            $response_payload['latest_version'] = $latest_version;
            $response_payload['update_available'] = ($client_version !== $latest_version);
            if ($response_payload['update_available']) {
                $response_payload['update_url'] = $base_url . '/' . $agent_files[$agent_type];
                $response_payload['update_sha256'] = hash_file('sha256', $agent_file) ?: null;
            }
        }
    }

    echo json_encode($response_payload, JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $re) {}
    }
    error_log('[agent_api] Error processing metrics: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interní chyba serveru při zápisu metrik: ' . $e->getMessage()]);
}
