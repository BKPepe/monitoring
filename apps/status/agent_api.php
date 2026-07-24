<?php
/**
 * API Endpoint pro příjem dat z VPS agenta
 */

header('Content-Type: application/json');
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Povolit pouze POST požadavky
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metoda není povolena. Použijte POST.']);
    exit;
}

// Načtení JSON dat z těla požadavku
$raw_data = file_get_contents('php://input');
$data = json_decode($raw_data, true);

if (!$data) {
    http_response_code(400);
    $json_err = json_last_error_msg();
    echo json_encode(['success' => false, 'message' => 'Neplatný JSON formát: ' . $json_err]);
    exit;
}

// --- Zpracování automatické registrace agenta ---
if (isset($data['action']) && $data['action'] === 'register') {
    $token = trim($data['token'] ?? '');
    $reg_token = get_setting('agent_registration_token');
    
    // Pokud registrační token není v nastavení, použije se záložní cron_key
    if (empty($reg_token)) {
        $reg_token = get_setting('cron_key');
    }
    
    if (empty($reg_token) || !hash_equals((string)$reg_token, $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Neplatný registrační token.']);
        exit;
    }
    
    $name = trim($data['hostname'] ?? $data['name'] ?? ('VPS Agent ' . date('Y-m-d H:i')));
    $type = (isset($data['agent_type']) && $data['agent_type'] === 'openwrt') ? 'openwrt' : 'vps';
    $agent_key = bin2hex(random_bytes(16));
    
    $stmt = $pdo->prepare("
        INSERT INTO monitors (name, type, target, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold)
        VALUES (?, ?, 'Local VPS Agent', 'unknown', ?, 90, 90, 95)
    ");
    $stmt->execute([$name, $type, $agent_key]);
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

// Ověření povinných údajů
$agent_key = isset($data['agent_key']) ? trim($data['agent_key']) : '';
$cpu = isset($data['cpu']) ? floatval($data['cpu']) : null;
$ram = isset($data['ram']) ? floatval($data['ram']) : null;
$hdd = isset($data['hdd']) ? floatval($data['hdd']) : null;
// Propustnost sítě (KB/s) je volitelná - starší agenti ji neposílají vůbec a
// nový agent ji vrací až od druhého běhu (potřebuje předchozí vzorek pro výpočet).
$net = (isset($data['net']) && $data['net'] !== null) ? floatval($data['net']) : null;

// Host vrstva (Level 2) - vše volitelné, starší agenti tato pole neposílají vůbec.
$cpu_steal = (isset($data['cpu_steal']) && $data['cpu_steal'] !== null) ? floatval($data['cpu_steal']) : null;
$swap = (isset($data['swap']) && $data['swap'] !== null) ? floatval($data['swap']) : null;
$load1 = (isset($data['load1']) && $data['load1'] !== null) ? floatval($data['load1']) : null;
$load5 = (isset($data['load5']) && $data['load5'] !== null) ? floatval($data['load5']) : null;
$load15 = (isset($data['load15']) && $data['load15'] !== null) ? floatval($data['load15']) : null;
$disk_io_read = (isset($data['disk_io_read']) && $data['disk_io_read'] !== null) ? floatval($data['disk_io_read']) : null;
$disk_io_write = (isset($data['disk_io_write']) && $data['disk_io_write'] !== null) ? floatval($data['disk_io_write']) : null;
$net_errors = (isset($data['net_errors']) && $data['net_errors'] !== null) ? intval($data['net_errors']) : null;

// TeamSpeak proces (pokud agent běží na stejném VPS jako ts3server)
$ts3_process = (isset($data['ts3_process']) && is_array($data['ts3_process'])) ? $data['ts3_process'] : null;

// Dokončení Level 2 Host vrstvy - vše volitelné, starší agenti tato pole
// neposílají vůbec (nebo je platforma nepodporuje, viz agent.ps1).
$iowait = (isset($data['iowait']) && $data['iowait'] !== null) ? floatval($data['iowait']) : null;
$inode_usage = (isset($data['inode_usage']) && $data['inode_usage'] !== null) ? floatval($data['inode_usage']) : null;
$fork_rate = (isset($data['fork_rate']) && $data['fork_rate'] !== null) ? intval($data['fork_rate']) : null;
$temperature = (isset($data['temperature']) && $data['temperature'] !== null) ? floatval($data['temperature']) : null;
$zombie_count = (isset($data['zombie_count']) && $data['zombie_count'] !== null) ? intval($data['zombie_count']) : null;
$top_cpu_processes = (isset($data['top_cpu_processes']) && is_array($data['top_cpu_processes'])) ? $data['top_cpu_processes'] : null;
$top_ram_processes = (isset($data['top_ram_processes']) && is_array($data['top_ram_processes'])) ? $data['top_ram_processes'] : null;
$sys_hostname = (isset($data['hostname']) && $data['hostname'] !== null && $data['hostname'] !== '') ? trim($data['hostname']) : null;
$sys_kernel = (isset($data['kernel']) && $data['kernel'] !== null && $data['kernel'] !== '') ? trim($data['kernel']) : null;
$sys_timezone = (isset($data['timezone']) && $data['timezone'] !== null && $data['timezone'] !== '') ? trim($data['timezone']) : null;
$reboot_required = isset($data['reboot_required']) ? $data['reboot_required'] : null;
$cloud_provider = (isset($data['cloud_provider']) && $data['cloud_provider'] !== null) ? trim($data['cloud_provider']) : null;
$virtualization = (isset($data['virtualization']) && $data['virtualization'] !== null) ? trim($data['virtualization']) : null;

// OpenWrt profil - identita routeru + stav WAN rozhraní (viz agent_openwrt.sh).
// hostname/kernel/os výše jsou generické a router je vyplňuje beze změny zde.
$ow_model = (isset($data['model']) && $data['model'] !== null && $data['model'] !== '') ? trim($data['model']) : null;
$ow_board_name = (isset($data['board_name']) && $data['board_name'] !== null && $data['board_name'] !== '') ? trim($data['board_name']) : null;
$ow_wan_up = isset($data['wan_up']) ? (bool)$data['wan_up'] : null;
$ow_wan_proto = (isset($data['wan_proto']) && $data['wan_proto'] !== null && $data['wan_proto'] !== '') ? trim($data['wan_proto']) : null;
$ow_wan_ipv4 = (isset($data['wan_ipv4']) && $data['wan_ipv4'] !== null && $data['wan_ipv4'] !== '') ? trim($data['wan_ipv4']) : null;
$ow_wan_ipv6 = (isset($data['wan_ipv6']) && $data['wan_ipv6'] !== null && $data['wan_ipv6'] !== '') ? trim($data['wan_ipv6']) : null;
$ow_wan_gateway = (isset($data['wan_gateway']) && $data['wan_gateway'] !== null && $data['wan_gateway'] !== '') ? trim($data['wan_gateway']) : null;
$ow_wan_dns = (isset($data['wan_dns']) && $data['wan_dns'] !== null && $data['wan_dns'] !== '') ? trim($data['wan_dns']) : null;
$ow_wan_uptime = (isset($data['wan_uptime']) && $data['wan_uptime'] !== null) ? intval($data['wan_uptime']) : null;
$ow_btrfs_errors = (isset($data['btrfs_errors']) && $data['btrfs_errors'] !== null) ? intval($data['btrfs_errors']) : null;

// OpenWrt Deep Telemetry - WiFi, LAN/DHCP, DNS, Firewall, WireGuard (viz agent_openwrt.sh)
$ow_wifi_radios = (isset($data['wifi_radios']) && is_array($data['wifi_radios'])) ? $data['wifi_radios'] : null;
$ow_lan_subnet = (isset($data['lan_subnet']) && $data['lan_subnet'] !== null && $data['lan_subnet'] !== '') ? trim($data['lan_subnet']) : null;
$ow_dhcp_leases = (isset($data['dhcp_leases_count']) && $data['dhcp_leases_count'] !== null) ? intval($data['dhcp_leases_count']) : null;
$ow_dhcp_reservations = (isset($data['dhcp_reservations_count']) && $data['dhcp_reservations_count'] !== null) ? intval($data['dhcp_reservations_count']) : null;
$ow_dns_queries = (isset($data['dns_queries']) && $data['dns_queries'] !== null) ? intval($data['dns_queries']) : null;
$ow_dns_cache_hits = (isset($data['dns_cache_hits']) && $data['dns_cache_hits'] !== null) ? intval($data['dns_cache_hits']) : null;
$ow_dns_cache_misses = (isset($data['dns_cache_misses']) && $data['dns_cache_misses'] !== null) ? intval($data['dns_cache_misses']) : null;
$ow_fw_accepted = (isset($data['fw_accepted']) && $data['fw_accepted'] !== null) ? intval($data['fw_accepted']) : null;
$ow_fw_dropped = (isset($data['fw_dropped']) && $data['fw_dropped'] !== null) ? intval($data['fw_dropped']) : null;
$ow_fw_rejected = (isset($data['fw_rejected']) && $data['fw_rejected'] !== null) ? intval($data['fw_rejected']) : null;
$ow_wireguard_peers = (isset($data['wireguard_peers']) && is_array($data['wireguard_peers'])) ? $data['wireguard_peers'] : null;
$ow_conntrack_pct = (isset($data['conntrack_pct']) && $data['conntrack_pct'] !== null) ? floatval($data['conntrack_pct']) : null;
$ow_swap_pct = (isset($data['swap_pct']) && $data['swap_pct'] !== null) ? floatval($data['swap_pct']) : null;
$ow_entropy = (isset($data['entropy']) && $data['entropy'] !== null) ? intval($data['entropy']) : null;
$ow_upgradable_packages = (isset($data['upgradable_packages']) && $data['upgradable_packages'] !== null) ? intval($data['upgradable_packages']) : null;
$ow_wifi_clients_count = (isset($data['wifi_clients_count']) && $data['wifi_clients_count'] !== null) ? intval($data['wifi_clients_count']) : null;
$ow_net_ipv4_kbps = (isset($data['net_ipv4_kbps']) && $data['net_ipv4_kbps'] !== null) ? floatval($data['net_ipv4_kbps']) : null;
$ow_net_ipv6_kbps = (isset($data['net_ipv6_kbps']) && $data['net_ipv6_kbps'] !== null) ? floatval($data['net_ipv6_kbps']) : null;

// OpenWrt Round 2 - mwan3, SQM, LTE, services, WAN reconnect, packages/logs
$ow_mwan3_policies = (isset($data['mwan3_policies']) && is_array($data['mwan3_policies'])) ? $data['mwan3_policies'] : null;
$ow_mwan3_active_gw = (isset($data['mwan3_active_gw']) && $data['mwan3_active_gw'] !== null) ? trim($data['mwan3_active_gw']) : null;
$ow_sqm_enabled = (isset($data['sqm_enabled']) && $data['sqm_enabled'] === true) ? true : false;
$ow_sqm_download_kbps = (isset($data['sqm_download_kbps']) && $data['sqm_download_kbps'] !== null) ? intval($data['sqm_download_kbps']) : null;
$ow_sqm_upload_kbps = (isset($data['sqm_upload_kbps']) && $data['sqm_upload_kbps'] !== null) ? intval($data['sqm_upload_kbps']) : null;
$ow_sqm_dropped = (isset($data['sqm_dropped']) && $data['sqm_dropped'] !== null) ? intval($data['sqm_dropped']) : null;
$ow_sqm_ecn = (isset($data['sqm_ecn']) && $data['sqm_ecn'] !== null) ? intval($data['sqm_ecn']) : null;
$ow_lte_rsrp = (isset($data['lte_rsrp']) && $data['lte_rsrp'] !== null) ? floatval($data['lte_rsrp']) : null;
$ow_lte_rsrq = (isset($data['lte_rsrq']) && $data['lte_rsrq'] !== null) ? floatval($data['lte_rsrq']) : null;
$ow_lte_sinr = (isset($data['lte_sinr']) && $data['lte_sinr'] !== null) ? floatval($data['lte_sinr']) : null;
$ow_lte_band = (isset($data['lte_band']) && $data['lte_band'] !== null) ? $data['lte_band'] : null;
$ow_lte_carrier = (isset($data['lte_carrier']) && $data['lte_carrier'] !== null) ? trim($data['lte_carrier']) : null;
$ow_service_restarts = (isset($data['service_restarts']) && is_array($data['service_restarts'])) ? $data['service_restarts'] : null;
$ow_wan_reconnect_count = (isset($data['wan_reconnect_count']) && $data['wan_reconnect_count'] !== null) ? intval($data['wan_reconnect_count']) : null;
$ow_wan_last_reconnect = (isset($data['wan_last_reconnect']) && $data['wan_last_reconnect'] !== null) ? intval($data['wan_last_reconnect']) : null;
$ow_installed_packages = (isset($data['installed_packages']) && $data['installed_packages'] !== null) ? intval($data['installed_packages']) : null;
$ow_log_errors_24h = (isset($data['log_errors_24h']) && $data['log_errors_24h'] !== null) ? intval($data['log_errors_24h']) : null;
$ow_log_warnings_24h = (isset($data['log_warnings_24h']) && $data['log_warnings_24h'] !== null) ? intval($data['log_warnings_24h']) : null;

if (empty($agent_key) || $cpu === null || $ram === null || $hdd === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Chybí povinné údaje (agent_key, cpu, ram, hdd).']);
    exit;
}

// Vyhledání monitoru podle agent_key (libovolného typu, jelikož agenta lze propojit k jakémukoliv monitoru)
$stmt = $pdo->prepare("SELECT * FROM monitors WHERE agent_key = ? LIMIT 1");
$stmt->execute([$agent_key]);
$monitor = $stmt->fetch();

if (!$monitor) {
    error_log('[agent_api] Auth failed: invalid agent_key from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' (key prefix: ' . substr($agent_key, 0, 8) . '...)');
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Neplatný klíč agenta nebo monitor neexistuje.']);
    exit;
}

$monitor_id = $monitor['id'];
$old_status = $monitor['status'];

// Auto-doplnění cíle (target) pro čistě agentové typy (vps/openwrt) - admin.php
// u nich cíl nevyžaduje, protože buď nemá síťový význam (vps) nebo ho agent
// stejně zjistí sám (openwrt). Nikdy nepřepisuje cíl, který si uživatel sám
// vyplnil - jen doplňuje prázdný.
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

// Kontrola procesů u VPS
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

// Přepsání stavu pokud je aktivní údržba
if (is_in_maintenance($monitor)) {
    $new_status = 'maintenance';
    $m_desc = $monitor['maintenance_description'] ?: 'Plánovaná údržba';
    $m_end = $monitor['maintenance_end'] ? ' (do ' . date('d.m.Y H:i', strtotime($monitor['maintenance_end'])) . ')' : '';
    $error_msg = $m_desc . $m_end;
}

try {
    $pdo->beginTransaction();

    // Načíst minulé stavy výstrah pro zamezení spamu
    $old_details = json_decode($monitor['last_details'] ?? '{}', true);
    $cpu_alert_sent = $old_details['cpu_alert_sent'] ?? false;
    $ram_alert_sent = $old_details['ram_alert_sent'] ?? false;
    $hdd_alert_sent = $old_details['hdd_alert_sent'] ?? false;

    // Pokud byl agent naposledy označen jako neaktivní (cron.php), tak tímto
    // úspěšným reportem se právě zotavil - zaznamenat do event logu pro digest.
    if (!empty($old_details['agent_alert_sent'])) {
        log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'agent_connected', 'Agent se znovu ozval');
    }
    
    $cpu_threshold = floatval(isset($monitor['cpu_threshold']) ? $monitor['cpu_threshold'] : 90.0);
    $ram_threshold = floatval(isset($monitor['ram_threshold']) ? $monitor['ram_threshold'] : 95.0);
    $hdd_threshold = floatval(isset($monitor['hdd_threshold']) ? $monitor['hdd_threshold'] : 90.0);
    
    if ($cpu >= $cpu_threshold) {
        if (!$cpu_alert_sent) {
            trigger_notifications($pdo, $monitor, 'vps_warning', "Vytížení CPU dosáhlo {$cpu}%.");
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'threshold_exceeded', "CPU dosáhlo {$cpu}% (limit {$cpu_threshold}%)");
            $cpu_alert_sent = true;
        }
    } else {
        $cpu_alert_sent = false;
    }

    if ($ram >= $ram_threshold) {
        if (!$ram_alert_sent) {
            trigger_notifications($pdo, $monitor, 'vps_warning', "Vytížení RAM dosáhlo {$ram}%.");
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'threshold_exceeded', "RAM dosáhla {$ram}% (limit {$ram_threshold}%)");
            $ram_alert_sent = true;
        }
    } else {
        $ram_alert_sent = false;
    }

    if ($hdd >= $hdd_threshold) {
        if (!$hdd_alert_sent) {
            trigger_notifications($pdo, $monitor, 'vps_warning', "Vytížení disku (HDD) dosáhlo {$hdd}%.");
            log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'threshold_exceeded', "Disk (HDD) dosáhl {$hdd}% (limit {$hdd_threshold}%)");
            $hdd_alert_sent = true;
        }
    } else {
        $hdd_alert_sent = false;
    }
    
    $old_details = json_decode($monitor['last_details'] ?? '{}', true);
    if (!is_array($old_details)) {
        $old_details = [];
    }
    
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
        'net_errors' => $net_errors,
        'iowait' => $iowait,
        'inode_usage' => $inode_usage,
        'fork_rate' => $fork_rate,
        'temperature' => $temperature,
        'zombie_count' => $zombie_count,
        'top_cpu_processes' => $top_cpu_processes,
        'top_ram_processes' => $top_ram_processes,
        'hostname' => $sys_hostname,
        'kernel' => $sys_kernel,
        'timezone' => $sys_timezone,
        'reboot_required' => $reboot_required,
        'cloud_provider' => $cloud_provider,
        'virtualization' => $virtualization,
        'missing_processes' => $missing_processes,
        'version' => isset($data['version']) ? trim($data['version']) : null,
        'uptime' => isset($data['uptime']) ? intval($data['uptime']) : null,
        'smart' => isset($data['smart']) ? trim($data['smart']) : null,
        'ports' => isset($data['ports']) && is_array($data['ports']) ? $data['ports'] : [],
        'os' => isset($data['os']) ? trim($data['os']) : null,
        // Který agent hlásí (bash/python/powershell/openwrt) - uloženo, aby
        // dashboard mohl srovnávat nahlášenou verzi se správným "nejnovějším"
        // číslem (viz bk_get_agent_latest_version() ve functions.php).
        'agent_type' => isset($data['agent_type']) ? strtolower(trim($data['agent_type'])) : null,
        'model' => $ow_model,
        'board_name' => $ow_board_name,
        'wan_up' => $ow_wan_up,
        'wan_proto' => $ow_wan_proto,
        'wan_ipv4' => $ow_wan_ipv4,
        'wan_ipv6' => $ow_wan_ipv6,
        'wan_gateway' => $ow_wan_gateway,
        'wan_dns' => $ow_wan_dns,
        'wan_uptime' => $ow_wan_uptime,
        'btrfs_errors' => $ow_btrfs_errors,
        // OpenWrt Deep Telemetry
        'wifi_radios' => $ow_wifi_radios,
        'lan_subnet' => $ow_lan_subnet,
        'dhcp_leases_count' => $ow_dhcp_leases,
        'dhcp_reservations_count' => $ow_dhcp_reservations,
        'dns_queries' => $ow_dns_queries,
        'dns_cache_hits' => $ow_dns_cache_hits,
        'dns_cache_misses' => $ow_dns_cache_misses,
        'fw_accepted' => $ow_fw_accepted,
        'fw_dropped' => $ow_fw_dropped,
        'fw_rejected' => $ow_fw_rejected,
        'wireguard_peers' => $ow_wireguard_peers,
        'conntrack_pct' => $ow_conntrack_pct,
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
        'service_restarts' => $ow_service_restarts,
        'wan_reconnect_count' => $ow_wan_reconnect_count,
        'wan_last_reconnect' => $ow_wan_last_reconnect,
        'installed_packages' => $ow_installed_packages,
        'log_errors_24h' => $ow_log_errors_24h,
        'log_warnings_24h' => $ow_log_warnings_24h,
        'cpu_alert_sent' => $cpu_alert_sent,
        'ram_alert_sent' => $ram_alert_sent,
        'hdd_alert_sent' => $hdd_alert_sent,
        'agent_alert_sent' => false,
        'agent_last_seen' => time()
    ];
    
    // Zpracování TeamSpeak statistik z agenta (pokud je poslal)
    if (isset($data['teamspeak_servers']) && is_array($data['teamspeak_servers'])) {
        $m_port = $monitor['port'] ?: 9987;
        $parts = explode(':', $monitor['target']);
        if (count($parts) === 2) {
            $m_port = intval($parts[1]);
        }
        
        foreach ($data['teamspeak_servers'] as $ts_srv) {
            if (intval($ts_srv['port']) === intval($m_port)) {
                $new_data['ts3_clients_online'] = intval($ts_srv['clients_online']);
                $new_data['ts3_clients_max'] = intval($ts_srv['clients_max']);
                $new_data['ts3_name'] = $ts_srv['name'] ?? '';
                break;
            }
        }
    }

    // TeamSpeak proces (pokud agent běží na stejném VPS jako ts3server) - PID se
    // porovná s posledním hlášením; změna PID = proces byl restartován.
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

        // Detekce změn v discovered services - logování událostí
        $old_svcs = $old_details['discovered_services'] ?? [];
        $new_svcs = $data['discovered_services'];
        $old_names = [];
        foreach ($old_svcs as $os) { if (($os['confidence'] ?? 0) >= 50) $old_names[$os['name'] ?? ''] = $os; }
        $new_names = [];
        foreach ($new_svcs as $ns) { if (($ns['confidence'] ?? 0) >= 50) $new_names[$ns['name'] ?? ''] = $ns; }
        // Nově objevená služba
        foreach ($new_names as $sname => $svc) {
            if (!isset($old_names[$sname]) && !empty($old_svcs)) {
                log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'service_discovered', "Nová služba: {$sname} (" . ($svc['confidence'] ?? 0) . "%)");
            }
        }
        // Zmizelá služba
        foreach ($old_names as $sname => $svc) {
            if (!isset($new_names[$sname]) && !empty($new_svcs)) {
                log_monitor_event($pdo, $monitor_id, $monitor['name'], $monitor['type'], 'service_lost', "Služba zmizela: {$sname}");
            }
        }
    }

    $merged_details_arr = array_merge($old_details, $new_data);
    $details = json_encode($merged_details_arr, JSON_UNESCAPED_UNICODE);

    // Zapsat metriky do databáze - včetně TeamSpeak klientů/procesu, pokud jsou
    // k dispozici (viz výše), aby graf historie měl data z tohoto běhu.
    // Non-fatal: pokud INSERT selže (chybějící sloupec ve staré DB), agent stále
    // dostane validní odpověď s update info - chyba se zaloguje do error_logu.
    $ts3_clients_online = $new_data['ts3_clients_online'] ?? null;
    $ts3_clients_max = $new_data['ts3_clients_max'] ?? null;
    $ts3_process_cpu = $ts3_process['cpu'] ?? null;
    $ts3_process_ram = $ts3_process['ram_mb'] ?? null;
    try {
        $stmt_metrics = $pdo->prepare("
            INSERT INTO vps_metrics (
                monitor_id, cpu_usage, ram_usage, hdd_usage, net_usage,
                load_avg_1, load_avg_5, load_avg_15, cpu_steal, swap_usage,
                disk_io_read_kbps, disk_io_write_kbps, net_errors,
                ts_clients_online, ts_clients_max, ts_process_cpu, ts_process_ram,
                iowait_pct, inode_usage_pct, zombie_count, fork_rate, temperature_c,
                wifi_clients_total, conntrack_pct, net_ipv4_kbps, net_ipv6_kbps
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_metrics->execute([
            $monitor_id, $cpu, $ram, $hdd, $net,
            $load1, $load5, $load15, $cpu_steal, $swap,
            $disk_io_read, $disk_io_write, $net_errors,
            $ts3_clients_online, $ts3_clients_max, $ts3_process_cpu, $ts3_process_ram,
            $iowait, $inode_usage, $zombie_count, $fork_rate, $temperature,
            $ow_wifi_clients_count, $ow_conntrack_pct, $ow_net_ipv4_kbps, $ow_net_ipv6_kbps,
        ]);
    } catch (PDOException $e) {
        $metrics_error = $e->getMessage();
        error_log('[agent_api] Metrics INSERT failed (monitor ' . $monitor_id . '): ' . $metrics_error);
    }

    if (in_array($monitor['type'], ['vps', 'openwrt'], true)) {
        // OpenWrt: ping na WAN IP pro reálnou odezvu (agent posílá wan_ipv4)
        $ping_ms = 0;
        if ($monitor['type'] === 'openwrt') {
            $ping_target = $ow_wan_ipv4 ?: ($monitor['target'] ?: null);
            if ($ping_target) {
                $ping_ms = bk_ping_host($ping_target) ?? 0;
            }
        }
        // Zapsat běžný log kontroly
        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message) VALUES (?, ?, ?, ?)");
        $stmt_log->execute([$monitor_id, $new_status, $ping_ms, $error_msg]);
        
        // Kontrola změny stavu
        if ($old_status !== $new_status) {
            $stmt_update = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW(), last_details = ? WHERE id = ?");
            $stmt_update->execute([$new_status, $details, $monitor_id]);
            
            // Změna stavu - trigger notifikace (pouze pokud nejde o údržbu)
            if ($new_status !== 'maintenance') {
                trigger_notifications($pdo, $monitor, $new_status, $error_msg ?: 'Server opět komunikuje.');
            }
        } else {
            // Pouze aktualizovat čas poslední kontroly a metriky
            $stmt_update = $pdo->prepare("UPDATE monitors SET last_checked = NOW(), last_details = ? WHERE id = ?");
            $stmt_update->execute([$details, $monitor_id]);
        }
    } else {
        // Pro ostatní typy monitorů pouze uložíme data o zátěži (stav ovládá síťová kontrola v cronu)
        $stmt_update = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
        $stmt_update->execute([$details, $monitor_id]);
    }
    
    if (isset($data['action_result']) && is_array($data['action_result'])) {
        $act_res = $data['action_result'];
        $act_id = intval($act_res['action_id'] ?? 0);
        $act_status = trim($act_res['status'] ?? 'failed');
        $act_msg = trim($act_res['message'] ?? '');
        
        if ($act_id > 0) {
            $stmt_act = $pdo->prepare("UPDATE agent_actions SET status = ?, result_message = ?, executed_at = NOW() WHERE id = ? AND monitor_id = ?");
            $stmt_act->execute([$act_status, $act_msg, $act_id, $monitor_id]);
        }
    }

    $pdo->commit();

    $response_payload = ['success' => true, 'message' => 'Metriky uloženy a stav aktualizován.'];
    
    // Pokud metrics INSERT selhal, informovat agenta (viditelné v jeho logu)
    if (!empty($metrics_error)) {
        $response_payload['schema_warning'] = 'DB schema out of date - metrics not saved. Please update database schema.';
    }

    // Kontrola nevyřízených akcí ve frontě pro tohoto agenta. Souhlas se
    // ověřuje znovu tady (ne jen při zařazení v admin.php) - monitor mohl být
    // mezitím překonfigurován a konkrétní akci už nemusí povolovat.
    try {
        $ra_allowed_here = !empty($monitor['remote_actions_enabled'])
            ? array_filter(explode(',', (string)($monitor['allowed_actions'] ?? '')))
            : [];

        if (!empty($ra_allowed_here)) {
            $stmt_pact = $pdo->prepare("SELECT id, action_type FROM agent_actions WHERE monitor_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 1");
            $stmt_pact->execute([$monitor_id]);
            $pending_act = $stmt_pact->fetch();

            if ($pending_act && in_array($pending_act['action_type'], $ra_allowed_here, true)) {
                $action_id = (int)$pending_act['id'];
                $action_type = $pending_act['action_type'];
                $timestamp = time();
                $nonce = bin2hex(random_bytes(8));

                // HMAC-SHA256 podpis požadavku klíčem monitoru ($monitor['agent_key'])
                $sig_payload = "action={$action_type}|ts={$timestamp}|nonce={$nonce}";
                $signature = hash_hmac('sha256', $sig_payload, $monitor['agent_key']);

                $response_payload['pending_action'] = [
                    'action_id' => $action_id,
                    'action' => $action_type,
                    'timestamp' => $timestamp,
                    'nonce' => $nonce,
                    'signature' => $signature
                ];

                // Označit jako 'sent' HNED, ne až po potvrzení agentem - jinak
                // by stejná (stále 'pending') akce byla znovu podepsána a
                // odeslána při každém dalším pollu, dokud nedorazí ack. U
                // reboot_router by to znamenalo smyčku restartů u routeru,
                // který se stihne vzpamatovat pomaleji než jeden cron interval.
                $stmt_mark_sent = $pdo->prepare("UPDATE agent_actions SET status = 'sent' WHERE id = ?");
                $stmt_mark_sent->execute([$action_id]);
            }
        }
    } catch (PDOException $e) {}

    // Informace o dostupné aktualizaci agenta - verzi čteme přímo ze souborů
    // agentů na serveru (viz bk_get_agent_latest_version() ve functions.php),
    // takže se udržuje na jediném místě (v samotném skriptu).
    $agent_type = isset($data['agent_type']) ? strtolower(trim($data['agent_type'])) : '';
    $client_version = isset($data['version']) ? trim($data['version']) : '';
    $agent_files = bk_agent_files();

    if ($client_version !== '' && isset($agent_files[$agent_type])) {
        $latest_version = bk_get_agent_latest_version($agent_type);
        if ($latest_version !== null) {
            $agent_file = __DIR__ . '/' . $agent_files[$agent_type];
            $agent_source = (string)file_get_contents($agent_file);
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

            $response_payload['latest_version'] = $latest_version;
            $response_payload['update_available'] = version_compare($client_version, $latest_version, '<');
            if ($response_payload['update_available']) {
                $response_payload['update_url'] = $base_url . '/' . $agent_files[$agent_type];
                $response_payload['update_sha256'] = hash('sha256', $agent_source);
            }
        }
    }

    echo json_encode($response_payload, JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interní chyba serveru při zápisu metrik: ' . $e->getMessage()]);
}
