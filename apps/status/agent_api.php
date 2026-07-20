<?php
/**
 * API Endpoint pro příjem dat z VPS agenta
 */

header('Content-Type: application/json');
require_once __DIR__ . '/functions.php';

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
    echo json_encode(['success' => false, 'message' => 'Neplatný JSON formát.']);
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
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Neplatný klíč agenta nebo monitor neexistuje.']);
    exit;
}

$monitor_id = $monitor['id'];
$old_status = $monitor['status'];

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
            $cpu_alert_sent = true;
        }
    } else {
        $cpu_alert_sent = false;
    }
    
    if ($ram >= $ram_threshold) {
        if (!$ram_alert_sent) {
            trigger_notifications($pdo, $monitor, 'vps_warning', "Vytížení RAM dosáhlo {$ram}%.");
            $ram_alert_sent = true;
        }
    } else {
        $ram_alert_sent = false;
    }
    
    if ($hdd >= $hdd_threshold) {
        if (!$hdd_alert_sent) {
            trigger_notifications($pdo, $monitor, 'vps_warning', "Vytížení disku (HDD) dosáhlo {$hdd}%.");
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
        'missing_processes' => $missing_processes,
        'version' => isset($data['version']) ? trim($data['version']) : null,
        'uptime' => isset($data['uptime']) ? intval($data['uptime']) : null,
        'smart' => isset($data['smart']) ? trim($data['smart']) : null,
        'ports' => isset($data['ports']) && is_array($data['ports']) ? $data['ports'] : [],
        'os' => isset($data['os']) ? trim($data['os']) : null,
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

    $merged_details_arr = array_merge($old_details, $new_data);
    $details = json_encode($merged_details_arr, JSON_UNESCAPED_UNICODE);

    // Zapsat metriky do databáze - včetně TeamSpeak klientů/procesu, pokud jsou
    // k dispozici (viz výše), aby graf historie měl data z tohoto běhu.
    $ts3_clients_online = $new_data['ts3_clients_online'] ?? null;
    $ts3_clients_max = $new_data['ts3_clients_max'] ?? null;
    $ts3_process_cpu = $ts3_process['cpu'] ?? null;
    $ts3_process_ram = $ts3_process['ram_mb'] ?? null;
    $stmt_metrics = $pdo->prepare("
        INSERT INTO vps_metrics (
            monitor_id, cpu_usage, ram_usage, hdd_usage, net_usage,
            load_avg_1, load_avg_5, load_avg_15, cpu_steal, swap_usage,
            disk_io_read_kbps, disk_io_write_kbps, net_errors,
            ts_clients_online, ts_clients_max, ts_process_cpu, ts_process_ram
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_metrics->execute([
        $monitor_id, $cpu, $ram, $hdd, $net,
        $load1, $load5, $load15, $cpu_steal, $swap,
        $disk_io_read, $disk_io_write, $net_errors,
        $ts3_clients_online, $ts3_clients_max, $ts3_process_cpu, $ts3_process_ram,
    ]);

    if ($monitor['type'] === 'vps') {
        // Zapsat běžný log kontroly
        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message) VALUES (?, ?, ?, ?)");
        $stmt_log->execute([$monitor_id, $new_status, 0, $error_msg]);
        
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
    
    $pdo->commit();

    $response_payload = ['success' => true, 'message' => 'Metriky uloženy a stav aktualizován.'];

    // Informace o dostupné aktualizaci agenta - verzi čteme přímo ze souborů
    // agentů na serveru, takže se udržuje na jediném místě (v samotném skriptu).
    $agent_type = isset($data['agent_type']) ? strtolower(trim($data['agent_type'])) : '';
    $client_version = isset($data['version']) ? trim($data['version']) : '';
    $agent_files = ['bash' => 'agent.sh', 'python' => 'agent.py', 'powershell' => 'agent.ps1'];

    if ($client_version !== '' && isset($agent_files[$agent_type])) {
        $agent_file = __DIR__ . '/' . $agent_files[$agent_type];
        if (is_readable($agent_file)) {
            $agent_source = (string)file_get_contents($agent_file);
            if (preg_match('/\$?AGENT_VERSION\s*=\s*["\']([0-9][0-9A-Za-z.\-]*)["\']/', $agent_source, $vm)) {
                $latest_version = $vm[1];
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
    }

    echo json_encode($response_payload, JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Interní chyba serveru při zápisu metrik: ' . $e->getMessage()]);
}
