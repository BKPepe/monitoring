<?php
/**
 * Blood Kings - Public Status & SPA API
 * 
 * Poskytuje živý stav herních serverů, webů, uzlů a uživatelů z MySQL databáze.
 */

header('Content-Type: application/json; charset=utf-8');

$request_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'bloodkings.eu';
$default_origin = $request_scheme . '://' . $host;

$origin = $_SERVER['HTTP_ORIGIN'] ?? $default_origin;
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Vary: Origin');

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Kontrola stavu relace (přihlášení z admin.php / PHP session)
if ($action === 'session') {
    $is_logged_in = !empty($_SESSION['admin_logged_in']);
    $user = null;
    if ($is_logged_in) {
        $user = [
            'id' => $_SESSION['admin_id'] ?? 1,
            'username' => $_SESSION['admin_username'] ?? 'admin',
            'email' => 'admin@bloodkings.eu',
            'role' => $_SESSION['admin_role'] ?? 'admin',
        ];
    }
    echo json_encode([
        'authenticated' => $is_logged_in,
        'user' => $user,
        'csrfToken' => $_SESSION['csrf_token'] ?? null,
        'loginUrl' => '/app/setup',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1b. Login (SPA) - mirrors admin.php's own POST login handler so both
// front ends share the same session keys, rate limiting and 2FA flow.
// This action never existed before: the SPA's login call silently hit the
// "unknown action" fallback below (HTTP 200, unrelated payload), so every
// login attempt looked successful client-side while no session was ever
// created.
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    $login_input = json_decode(file_get_contents('php://input'), true) ?: [];
    $username = trim($login_input['username'] ?? '');
    $password = (string)($login_input['password'] ?? '');
    $totp_code = trim($login_input['totp_code'] ?? '');

    $lockout_secs = bk_login_lockout_seconds($pdo, $username);
    if ($lockout_secs > 0) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Try again in ' . ceil($lockout_secs / 60) . ' min.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        bk_audit_log($pdo, 'login_failed', 'Invalid username/password: ' . $username, null, null, null, $username);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        exit;
    }

    if (!empty($user['totp_enabled'])) {
        if ($totp_code === '') {
            // Password checked out, but the account has 2FA - session only
            // stores the pending user id (proof this session passed the
            // password step), never the password itself.
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            echo json_encode(['success' => true, 'requires2fa' => true]);
            exit;
        }
        if (!bk_totp_verify_code($user['totp_secret'], $totp_code)) {
            bk_audit_log($pdo, 'login_failed', 'Invalid 2FA code', null, null, $user['id'], $user['username']);
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid 2FA code.']);
            exit;
        }
    }

    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_role'] = $user['role'];
    unset($_SESSION['pending_2fa_user_id']);
    bk_audit_log($pdo, 'login_success', !empty($user['totp_enabled']) ? 'Password + 2FA' : 'Password', 'user', $user['id'], $user['id'], $user['username']);

    echo json_encode([
        'success' => true,
        'authenticated' => true,
        'user' => ['id' => (int)$user['id'], 'username' => $user['username'], 'email' => $user['email'] ?? '', 'role' => $user['role']],
        'csrfToken' => bk_csrf_token(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1c. Logout (SPA)
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// 2. Seznam všech monitorů z databáze
if ($action === 'monitors') {
    $is_admin = !empty($_SESSION['admin_logged_in']) && ($_SESSION['admin_role'] ?? '') === 'admin';
    $monitors = [];

    // Základní seznam. response_time/cpu_usage/ram_usage/hdd_usage NEJSOU
    // sloupce tabulky monitors (nikdy nebyly - potvrzeno živě: "Unknown column
    // 'response_time'") - to jsou poslední naměřené hodnoty z monitor_logs
    // (kontroly dostupnosti) a vps_metrics (hlášení agenta), doplněné
    // poddotazem/joinem. Tohle NESMÍ selhat kvůli rozšířeným polím níže
    // (na produkci se přesně tohle stalo: jeden dotaz na 20+ sloupců naráz,
    // jedna neshoda schématu = celý seznam monitorů zmizel a s ním celá appka).
    try {
        $stmt = $pdo->query("
            SELECT m.id, m.name, m.type, m.target, m.port, m.status, m.category, m.asset_id,
                   m.last_checked, m.last_status_change, m.last_details,
                   (SELECT l.response_time FROM monitor_logs l
                    WHERE l.monitor_id = m.id AND l.response_time > 0
                    ORDER BY l.id DESC LIMIT 1) AS response_time,
                   vm.cpu_usage, vm.ram_usage, vm.hdd_usage
            FROM monitors m
            LEFT JOIN vps_metrics vm
                   ON vm.id = (SELECT vm2.id FROM vps_metrics vm2
                               WHERE vm2.monitor_id = m.id
                               ORDER BY vm2.id DESC LIMIT 1)
            ORDER BY m.id ASC
        ");
        $agent_offline_secs = intval(get_setting('agent_offline_timeout', '50')) * 60;
        foreach ($stmt->fetchAll() as $r) {
            $details = json_decode($r['last_details'] ?? '', true) ?: [];
            // Provozní diagnostika (chybové hlášky sběru, hinty s názvy
            // konfiguračních souborů) patří administrátorovi, ne veřejnému
            // dashboardu - veřejná odpověď ji neobsahuje vůbec.
            $details_out = $details;
            if (!$is_admin) {
                unset($details_out['cpanel_stats_error']);
                // Síťová identita infrastruktury (WAN adresy, brána, vnitřní
                // subnet, SSID, WireGuard endpointy, výpis rozhraní) je mapa
                // pro útočníka - anonymní odpověď nese jen agregáty (počty,
                // procenta), ne adresy. Stará status stránka to gatuje stejně.
                foreach (['wan_ipv4', 'wan_ipv6', 'wan_gateway', 'wan_dns', 'lan_subnet', 'wifi_radios', 'wireguard_peers', 'interfaces', 'dns_servers', 'mwan3_policies', 'service_restarts', 'public_ip', 'asn', 'asn_name'] as $priv_key) {
                    unset($details_out[$priv_key]);
                }
            }
            $last_change_ts = $r['last_status_change'] ? strtotime($r['last_status_change']) : null;
            $monitors[(int)$r['id']] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'type' => strtolower($r['type'] ?? 'web'),
                'target' => $r['target'],
                'port' => $r['port'] ? (int)$r['port'] : null,
                'status' => strtolower($r['status'] ?? 'up'),
                'category' => $r['category'] ?? 'Monitory',
                'assetId' => $r['asset_id'] ? (int)$r['asset_id'] : (int)$r['id'],
                'assetName' => $r['name'],
                'lastCheck' => $r['last_checked'] ? date('c', strtotime($r['last_checked'])) : null,
                'lastStatusChange' => $r['last_status_change'] ? date('c', strtotime($r['last_status_change'])) : null,
                'responseMs' => $r['response_time'] !== null ? (int)$r['response_time'] : null,
                'cpu' => $r['cpu_usage'] !== null ? (float)$r['cpu_usage'] : null,
                'ram' => $r['ram_usage'] !== null ? (float)$r['ram_usage'] : null,
                'hdd' => $r['hdd_usage'] !== null ? (float)$r['hdd_usage'] : null,
                // Doba od poslední změny stavu (ne fixní hodnota) - 0 dokud první kontrola neproběhne.
                'uptimeSeconds' => ($last_change_ts && strtolower($r['status'] ?? '') === 'up') ? max(0, time() - $last_change_ts) : 0,
                'agentLastSeen' => $details['agent_last_seen'] ?? null,
                'hostname' => $details['hostname'] ?? $r['target'],
                'os' => $details['os'] ?? $r['type'],
                'details' => $details_out,
                // Výpadky SBĚRU dat (ne služby) - frontend je MUSÍ zobrazit,
                // tiché zahazování dat je zakázané (viz bk_get_collection_issues).
                // Jen pro admina: je to provozní diagnostika, ne veřejný stav služeb.
                'collectionIssues' => $is_admin ? bk_get_collection_issues($r, $details, $agent_offline_secs) : [],
            ];
        }
    } catch (Exception $e) {
        error_log('[api.php action=monitors] Base query failed: ' . $e->getMessage());
        echo json_encode(['monitors' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Konfigurační pole (mohou obsahovat interní údaje typu ServerQuery uživatel,
    // webhook URL apod.) se vrací jen přihlášenému administrátorovi. Samostatný
    // dotaz a samostatný try/catch - chybějící/nekompatibilní sloupec tady smí
    // připravit admina jen o tahle rozšířená pole, nikdy o základní seznam.
    if ($is_admin && !empty($monitors)) {
        try {
            $stmt2 = $pdo->query("
                SELECT id, timeout, email_notifications, sms_notifications, notes, maintenance, maintenance_description,
                       maintenance_start, maintenance_end, monitored_processes, cpu_threshold, ram_threshold, hdd_threshold,
                       body_keyword, cpanel_stats_url, sq_username, ts3_filetransfer_port, rcon_port,
                       (sq_password IS NOT NULL AND sq_password <> '') AS sq_password_set,
                       (rcon_password IS NOT NULL AND rcon_password <> '') AS rcon_password_set,
                       enabled_metrics, remote_actions_enabled, allowed_actions
                FROM monitors
            ");
            foreach ($stmt2->fetchAll() as $r) {
                $mid = (int)$r['id'];
                if (!isset($monitors[$mid])) continue;
                $monitors[$mid]['timeout'] = (int)($r['timeout'] ?? 5);
                $monitors[$mid]['emailNotifications'] = (bool)$r['email_notifications'];
                $monitors[$mid]['smsNotifications'] = (bool)$r['sms_notifications'];
                $monitors[$mid]['notes'] = $r['notes'];
                $monitors[$mid]['maintenance'] = (bool)$r['maintenance'];
                $monitors[$mid]['maintenanceDescription'] = $r['maintenance_description'];
                $monitors[$mid]['maintenanceStart'] = $r['maintenance_start'];
                $monitors[$mid]['maintenanceEnd'] = $r['maintenance_end'];
                $monitors[$mid]['monitoredProcesses'] = $r['monitored_processes'];
                $monitors[$mid]['cpuThreshold'] = (int)($r['cpu_threshold'] ?? 90);
                $monitors[$mid]['ramThreshold'] = (int)($r['ram_threshold'] ?? 95);
                $monitors[$mid]['hddThreshold'] = (int)($r['hdd_threshold'] ?? 90);
                $monitors[$mid]['bodyKeyword'] = $r['body_keyword'];
                $monitors[$mid]['cpanelStatsUrl'] = $r['cpanel_stats_url'];
                $monitors[$mid]['sqUsername'] = $r['sq_username'];
                $monitors[$mid]['sqPasswordSet'] = (bool)$r['sq_password_set'];
                $monitors[$mid]['ts3FiletransferPort'] = $r['ts3_filetransfer_port'] ? (int)$r['ts3_filetransfer_port'] : null;
                $monitors[$mid]['rconPort'] = $r['rcon_port'] ? (int)$r['rcon_port'] : null;
                $monitors[$mid]['rconPasswordSet'] = (bool)$r['rcon_password_set'];
                $monitors[$mid]['enabledMetrics'] = $r['enabled_metrics'] ? (json_decode($r['enabled_metrics'], true) ?: []) : [];
                $monitors[$mid]['remoteActionsEnabled'] = (bool)$r['remote_actions_enabled'];
                $monitors[$mid]['allowedActions'] = $r['allowed_actions'] ? explode(',', $r['allowed_actions']) : [];
                // Nabídka aktualizace agenta: srovnání verze z posledního reportu
                // s verzí souboru agenta na serveru. Jen když obě známe.
                // Ochrana proti "věčně offline" monitorům: aktivní kontrola
                // z hostingu na cíl v privátní síti nikdy neuspěje. Když má
                // asset agenta, nabídneme převod na agent-side kontrolu
                // místo tichého generování falešných výpadků.
                if ($r['asset_id'] !== null && in_array(strtolower($r['type'] ?? ''), ['web', 'port', 'minecraft', 'teamspeak', 'discord', 'dns'], true)) {
                    if (bk_validate_import_target((string)$r['target']) !== null) {
                        $monitors[$mid]['unreachableTarget'] = true;
                    }
                }

                $agent_ver = $details['agent_version'] ?? null;
                $agent_type_key = $details['agent_type'] ?? null;
                if ($agent_ver && $agent_type_key && function_exists('bk_get_agent_latest_version')) {
                    $latest_agent = bk_get_agent_latest_version($agent_type_key);
                    if ($latest_agent !== null) {
                        // Verze SOUBORU agenta na serveru - jediný zdroj pravdy.
                        // Frontend dřív porovnával proti natvrdo zapsané '3.13.8'
                        // (což je verze TeamSpeak serveru, ne agenta) a stringovým
                        // '<', takže hlásil "neaktuální" i u čerstvě nasazeného agenta.
                        $monitors[$mid]['agentLatestVersion'] = $latest_agent;
                        if (bk_version_is_older($agent_ver, $latest_agent)) {
                            $monitors[$mid]['agentUpdateAvailable'] = $latest_agent;
                        }
                    }
                }
            }
        } catch (Throwable $t) {
            error_log('[api.php action=monitors] Extended fields query failed: ' . $t->getMessage());
        }
    }

    echo json_encode(['monitors' => array_values($monitors)], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2b. Uložení nového nebo stávajícího monitoru přímo do MySQL databáze
if ($action === 'save_monitor') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $name = trim($input['name'] ?? '');
    $type = trim($input['type'] ?? 'web');
    if ($type === 'https' || $type === 'http') $type = 'web';
    $target = trim($input['target'] ?? '');
    $port = !empty($input['port']) ? (int)$input['port'] : null;
    $category = trim($input['category'] ?? ($type === 'web' ? 'Webové Portály & API' : ($type === 'teamspeak' || $type === 'minecraft' ? 'Komunikační & Herní Servery' : 'Síťová Infrastruktura & Routery')));

    $asset_id = !empty($input['asset_id']) ? (int)$input['asset_id'] : null;
    $new_asset_name = trim($input['new_asset_name'] ?? '');
    if ($new_asset_name !== '') {
        $stmt_new_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
        $stmt_new_asset->execute([$new_asset_name]);
        $asset_id = (int)$pdo->lastInsertId();
    }

    $timeout = !empty($input['timeout']) ? (int)$input['timeout'] : 5;
    $email_notifications = isset($input['email_notifications']) ? ($input['email_notifications'] ? 1 : 0) : 1;
    $sms_notifications = isset($input['sms_notifications']) ? ($input['sms_notifications'] ? 1 : 0) : 0;
    $notes = !empty($input['notes']) ? trim($input['notes']) : null;
    $maintenance = !empty($input['maintenance']) ? 1 : 0;
    $maintenance_description = ($maintenance === 1 && !empty($input['maintenance_description'])) ? trim($input['maintenance_description']) : null;
    $maintenance_start = ($maintenance === 1 && !empty($input['maintenance_start'])) ? $input['maintenance_start'] : ($maintenance === 1 ? date('Y-m-d H:i:s') : null);
    $maintenance_end = ($maintenance === 1 && !empty($input['maintenance_end'])) ? $input['maintenance_end'] : null;

    $monitored_processes = !empty($input['monitored_processes']) ? trim($input['monitored_processes']) : null;
    $cpu_threshold = !empty($input['cpu_threshold']) ? (int)$input['cpu_threshold'] : 90;
    $ram_threshold = !empty($input['ram_threshold']) ? (int)$input['ram_threshold'] : 95;
    $hdd_threshold = !empty($input['hdd_threshold']) ? (int)$input['hdd_threshold'] : 90;

    $body_keyword = (!empty($input['body_keyword']) && $type === 'web') ? trim($input['body_keyword']) : null;
    $cpanel_stats_url = (!empty($input['cpanel_stats_url']) && $type === 'web') ? trim($input['cpanel_stats_url']) : null;

    $sq_username = (!empty($input['sq_username']) && $type === 'teamspeak') ? trim($input['sq_username']) : null;
    $sq_password = (!empty($input['sq_password']) && $type === 'teamspeak') ? trim($input['sq_password']) : null;
    $ts3_filetransfer_port = (!empty($input['ts3_filetransfer_port']) && $type === 'teamspeak') ? (int)$input['ts3_filetransfer_port'] : null;

    $rcon_port = (!empty($input['rcon_port']) && $type === 'minecraft') ? (int)$input['rcon_port'] : null;
    $rcon_password = (!empty($input['rcon_password']) && $type === 'minecraft') ? trim($input['rcon_password']) : null;

    $remote_actions_enabled = ($type === 'openwrt' && !empty($input['remote_actions_enabled'])) ? 1 : 0;
    $allowed_actions_input = isset($input['allowed_actions']) && is_array($input['allowed_actions']) ? $input['allowed_actions'] : [];
    $allowed_actions = ($type === 'openwrt' && $remote_actions_enabled && !empty($allowed_actions_input))
        ? implode(',', array_intersect($allowed_actions_input, ['restart_wan', 'restart_wireguard', 'reboot_router', 'renew_dhcp', 'restart_service', 'reconnect_pppoe']))
        : null;

    $enabled_metrics_input = isset($input['enabled_metrics']) && is_array($input['enabled_metrics']) ? $input['enabled_metrics'] : [];
    $enabled_metrics = !empty($enabled_metrics_input) ? json_encode(array_values($enabled_metrics_input)) : null;

    if (empty($name) || (empty($target) && !in_array($type, ['vps', 'openwrt'], true))) {
        http_response_code(400);
        echo json_encode(['error' => 'Název a cíl jsou povinné údaje.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($id > 0) {
            // Hesla (ServerQuery, RCON) se přepíšou, jen když administrátor zadal novou
            // hodnotu - prázdné pole ve formuláři pro editaci nesmí smazat už uložené heslo.
            $stmt = $pdo->prepare("
                UPDATE monitors
                SET name = ?, type = ?, target = ?, port = ?, category = ?, timeout = ?, email_notifications = ?, sms_notifications = ?, notes = ?, maintenance = ?, monitored_processes = ?, maintenance_description = ?, maintenance_start = ?, maintenance_end = ?, cpanel_stats_url = ?, cpu_threshold = ?, ram_threshold = ?, hdd_threshold = ?, body_keyword = ?, sq_username = ?, sq_password = COALESCE(?, sq_password), ts3_filetransfer_port = ?, enabled_metrics = ?, rcon_port = ?, rcon_password = COALESCE(?, rcon_password), remote_actions_enabled = ?, allowed_actions = ?, asset_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id, $id]);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Monitor úspěšně upraven'], JSON_UNESCAPED_UNICODE);
        } else {
            $agent_key = bin2hex(random_bytes(16));
            if ($asset_id === null) {
                $stmt_auto_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
                $stmt_auto_asset->execute([$name]);
                $asset_id = (int)$pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("
                INSERT INTO monitors (name, type, target, port, category, timeout, email_notifications, sms_notifications, agent_key, status, notes, maintenance, monitored_processes, maintenance_description, maintenance_start, maintenance_end, cpanel_stats_url, cpu_threshold, ram_threshold, hdd_threshold, body_keyword, sq_username, sq_password, ts3_filetransfer_port, enabled_metrics, rcon_port, rcon_password, remote_actions_enabled, allowed_actions, asset_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $agent_key, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id]);
            $new_id = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'message' => 'Monitor úspěšně vytvořen'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2. Smazání monitoru z MySQL databáze
if ($action === 'delete_monitor') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $del_id = (int)($input['id'] ?? 0);
    if ($del_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM monitors WHERE id = ?");
            $stmt->execute([$del_id]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatné ID monitoru'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2b. Seznam objevených, ale zatím nesledovaných služeb (Service Discovery).
// Agenti tohle ukládají do monitors.last_details.discovered_services už dlouho
// (agent_api.php), admin.php to i umí importovat, ale žádné z front-end appek
// (apps/monitor React SPA) to nikdy nečetlo zpátky - proto tenhle endpoint.
if ($action === 'discovered_services') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        // Už monitorované služby se nenavrhují znovu - jinak importovaná
        // služba zůstala v panelu viset jako "nesledovaná" (hlášeno: kresd 2×).
        $existing_names = [];
        $existing_port_asset = [];
        $stmt_ex = $pdo->query("SELECT LOWER(name) AS lname, port, asset_id FROM monitors");
        while ($ex = $stmt_ex->fetch()) {
            $existing_names[$ex['lname']] = true;
            if ($ex['port'] !== null && $ex['asset_id'] !== null) {
                $existing_port_asset[$ex['asset_id'] . ':' . $ex['port']] = true;
            }
        }

        $stmt = $pdo->query("SELECT id, name, type, target, asset_id, last_details FROM monitors WHERE last_details IS NOT NULL");
        $services = [];
        while ($row = $stmt->fetch()) {
            $details = json_decode($row['last_details'] ?? '', true);
            if (!is_array($details) || empty($details['discovered_services']) || !is_array($details['discovered_services'])) {
                continue;
            }
            foreach ($details['discovered_services'] as $svc) {
                if (empty($svc['name'])) continue;

                $asset_id = $row['asset_id'] !== null ? (int)$row['asset_id'] : null;
                $port = isset($svc['port']) && $svc['port'] !== '' ? (int)$svc['port'] : null;
                if (isset($existing_names[strtolower((string)$svc['name'])])) continue;
                if ($port !== null && $asset_id !== null && isset($existing_port_asset[$asset_id . ':' . $port])) continue;

                // Cíl budoucí kontroly: adresa od agenta, jinak hostname
                // z jeho reportu, jinak target zdrojového monitoru. Dřív se
                // tu dosazovalo JMÉNO monitoru ("Router - Praha"), které
                // pak import po právu odmítl jako nevalidní adresu.
                $resolved_target = trim((string)($svc['target'] ?? ''));
                if ($resolved_target === '' || $resolved_target === '127.0.0.1' || $resolved_target === 'localhost') {
                    $resolved_target = trim((string)($details['hostname'] ?? ''));
                }
                if ($resolved_target === '') {
                    $resolved_target = trim((string)($row['target'] ?? ''));
                }
                // Předběžná validace: služba, kterou z hostingu nelze
                // kontrolovat (privátní adresa, žádná adresa), se u agentních
                // monitorů (vps/openwrt) nabídne jako agent-side kontrola -
                // ověří ji lokálně sám agent. Blokace zůstává jen tam, kde
                // není agent, který by kontrolu převzal.
                $import_blocked = $resolved_target === ''
                    ? 'Agent nehlásí žádnou adresu, přes kterou by šla služba z hostingu testovat.'
                    : bk_validate_import_target($resolved_target);
                // Volba režimu kontroly:
                //  - aktivní (z hostingu) jen když má služba veřejně dosažitelný
                //    cíl A typ/port, který cron umí testovat,
                //  - jinak agent-side, pokud zdrojový monitor JE agent a služba
                //    má název procesu nebo port (agent ověří lokálně) - sem
                //    patří i démoni bez portu (Turris Sentinel apod.),
                //  - blokace zůstává jen tam, kde nezbývá žádná cesta.
                $src_is_agent = in_array(strtolower((string)($row['type'] ?? '')), ['vps', 'openwrt'], true);
                $svc_proc = isset($svc['process']) && $svc['process'] !== '' ? (string)$svc['process'] : null;
                $cron_checkable_types = ['web', 'cpanel', 'port', 'minecraft', 'teamspeak', 'discord'];
                $svc_type_lc = strtolower((string)($svc['type'] ?? 'web'));
                $active_possible = $import_blocked === null
                    && (in_array($svc_type_lc, $cron_checkable_types, true) || ($port !== null && $port > 0));

                $import_mode = 'active';
                if (!$active_possible) {
                    if ($src_is_agent && ($svc_proc !== null || ($port !== null && $port > 0))) {
                        $import_mode = 'agent';
                        $import_blocked = null;
                    } elseif ($import_blocked === null) {
                        $import_blocked = 'Službu nelze kontrolovat z hostingu (neznámý typ bez portu) a zdrojový monitor není agent, který by kontrolu převzal.';
                    }
                }

                $services[] = [
                    'sourceMonitorId' => (int)$row['id'],
                    'sourceMonitorName' => $row['name'],
                    'sourceHostname' => $details['hostname'] ?? null,
                    'sourceAssetId' => $asset_id,
                    'name' => (string)$svc['name'],
                    'type' => (string)($svc['type'] ?? 'web'),
                    'port' => $port,
                    'target' => $resolved_target !== '' ? $resolved_target : null,
                    'process' => isset($svc['process']) && $svc['process'] !== '' ? (string)$svc['process'] : null,
                    'mode' => $import_mode,
                    'importBlocked' => $import_blocked,
                    'confidence' => (int)($svc['confidence'] ?? 0),
                    'evidence' => is_array($svc['evidence'] ?? null) ? array_values($svc['evidence']) : [],
                    'missing' => is_array($svc['missing'] ?? null) ? array_values($svc['missing']) : [],
                ];
            }
        }
        // Nejjistější návrhy nahoře - admin obvykle chce importovat nejdřív ty.
        usort($services, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        echo json_encode(['services' => $services], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['services' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2c. Import jedné objevené služby jako nový monitor (Service Discovery -
// "propose -> confirm" krok). Zrcadlí admin.php action_import_service,
// jen jako JSON API pro React SPA.
if ($action === 'import_discovered_service') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $s_name = trim($input['name'] ?? '');
    $s_type = trim($input['type'] ?? 'web');
    $s_port = !empty($input['port']) ? (int)$input['port'] : null;
    $s_target = trim($input['target'] ?? '127.0.0.1');
    $source_monitor_id = !empty($input['sourceMonitorId']) ? (int)$input['sourceMonitorId'] : null;

    if ($s_name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Název služby je povinný.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Režim 'agent': službu na privátní síti kontroluje lokálně sám agent
    // (proces + port), server jen přijímá výsledky. Cíl monitoru je pak
    // NÁZEV PROCESU, ne síťová adresa - veřejná validace cíle se přeskočí.
    $s_mode = ($input['mode'] ?? '') === 'agent' ? 'agent' : 'active';
    $s_process = trim((string)($input['process'] ?? ''));
    if ($s_mode === 'agent') {
        $s_type = 'agent_service';
        $s_target = $s_process !== '' && preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $s_process) ? $s_process : '';
        if ($s_target === '' && !$s_port) {
            http_response_code(400);
            echo json_encode(['error' => 'Agent-side kontrola potřebuje aspoň název procesu nebo port služby - agent nehlásí ani jedno.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Cron umí aktivně kontrolovat jen tyhle typy. Cokoli jiného (dns, smtp,
    // samba...) se importuje jako kontrola portu - jinak monitor jen každou
    // minutu generoval neměřitelné 'unknown' záznamy (takhle dostal kresd
    // 0 % SLA, ačkoli ho nikdy žádná kontrola netestovala).
    $cron_checkable = ['web', 'cpanel', 'port', 'minecraft', 'teamspeak', 'discord', 'vps', 'openwrt', 'agent_service'];
    if (!in_array($s_type, $cron_checkable, true)) {
        if (!$s_port) {
            http_response_code(400);
            echo json_encode(['error' => "Typ služby '{$s_type}' zatím neumíme aktivně kontrolovat a služba nehlásí port, přes který by šla testovat. Import by generoval jen prázdné kontroly."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $s_type = 'port';
    }

    try {
        // Objevující monitor běží na stejném fyzickém stroji, takže nový
        // monitor rovnou dostane jeho asset I kategorii - bez kategorie by
        // import skončil ve skupině "Ostatní", což mate (hlášeno uživatelem).
        $discovered_asset_id = null;
        $discovered_category = null;
        if ($source_monitor_id) {
            $stmt_src = $pdo->prepare("SELECT asset_id, category, target, last_details FROM monitors WHERE id = ?");
            $stmt_src->execute([$source_monitor_id]);
            $src_row = $stmt_src->fetch();
            if ($src_row) {
                if (!empty($src_row['asset_id'])) {
                    $discovered_asset_id = (int)$src_row['asset_id'];
                }
                if (!empty($src_row['category'])) {
                    $discovered_category = $src_row['category'];
                }
                // Kontrola běží z hostingu, takže cíl musí být adresa stroje,
                // na kterém agent službu objevil - ne localhost ani jméno
                // monitoru. Bez použitelného cíle se vezme target zdrojového
                // monitoru, případně hostname z jeho posledního reportu.
                if ($s_target === '' || $s_target === '127.0.0.1' || $s_target === 'localhost') {
                    $src_details = json_decode($src_row['last_details'] ?? '{}', true) ?: [];
                    $fallback_target = trim((string)($src_row['target'] ?? ''));
                    if ($fallback_target === '') {
                        $fallback_target = trim((string)($src_details['hostname'] ?? ''));
                    }
                    if ($fallback_target !== '') {
                        $s_target = $fallback_target;
                    }
                }
            }
        }
        // Finální cíl (ať přišel z discovery payloadu agenta, nebo z fallbacku
        // výše) se validuje VŽDY - agent je nižší úroveň důvěry než admin
        // a kontroly běží z hostingu, kam privátní/interní cíle nepatří.
        // Validace běží před založením assetu, aby po odmítnutí nezůstal sirotek.
        // Výjimka: agent-side kontrola žádnou veřejnou adresu nemá (cíl = proces).
        $target_error = $s_mode === 'agent' ? null : bk_validate_import_target($s_target);
        if ($target_error !== null) {
            http_response_code(400);
            echo json_encode(['error' => $target_error], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($discovered_asset_id === null) {
            $stmt_new_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
            $stmt_new_asset->execute([$s_name]);
            $discovered_asset_id = (int)$pdo->lastInsertId();
        }

        $agent_key = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("
            INSERT INTO monitors (name, type, target, port, category, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold, asset_id)
            VALUES (?, ?, ?, ?, ?, 'unknown', ?, 90, 90, 95, ?)
        ");
        $stmt->execute([$s_name, $s_type, $s_target, $s_port, $discovered_category, $agent_key, $discovered_asset_id]);
        $new_id = (int)$pdo->lastInsertId();
        log_monitor_event($pdo, $new_id, $s_name, $s_type, 'monitor_added', "Importováno z automatické detekce služeb (Service Discovery)");
        bk_audit_log($pdo, 'monitor_created', $s_name . ' (Service Discovery)', 'monitor', $new_id);
        echo json_encode(['success' => true, 'id' => $new_id, 'assetId' => $discovered_asset_id], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2d. Upload vlastního loga (admin-only). Přijímá jen rastrové formáty
// ověřené přes getimagesize (magic bytes, ne příponu) - SVG je záměrně
// odmítnuté: umí nést skripty a přímé otevření nahrané URL by je spustilo
// na naší doméně. Soubor se ukládá pod pevným jménem do uploads/ a URL se
// rovnou zapíše do nastavení custom_logo_url.
if ($action === 'upload_logo') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($_FILES['logo']) || !is_array($_FILES['logo'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí soubor (pole "logo").'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $f = $_FILES['logo'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Nahrání selhalo (kód ' . (int)$f['error'] . ').'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$f['size'] > 2 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Soubor je příliš velký (max 2 MB).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) {
        http_response_code(400);
        echo json_encode(['error' => 'Podporované formáty: PNG, JPG, WebP. SVG z bezpečnostních důvodů nahrát nelze — vložte na něj URL ručně.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $ext = $allowed[$info[2]];
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Adresář uploads/ nejde vytvořit.');
        }
        foreach ((glob($dir . '/custom-logo.*') ?: []) as $old) {
            @unlink($old);
        }
        $dest = $dir . '/custom-logo.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            throw new RuntimeException('Soubor se nepodařilo uložit.');
        }
        // Cache-bust přes mtime, aby výměna loga nebyla rukojmím browser cache.
        $url = '/status/uploads/custom-logo.' . $ext . '?v=' . filemtime($dest);
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('custom_logo_url', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt->execute([$url]);
        bk_audit_log($pdo, 'setting_changed', 'custom_logo_url (upload loga, ' . strtoupper($ext) . ', ' . round($f['size'] / 1024) . ' kB)');
        echo json_encode(['success' => true, 'url' => $url], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Logo se nepodařilo uložit: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2e. Veřejná konfigurace vzhledu pro React app - stejná data, která už
// veřejně renderuje index.php (titulek, logo, vlastní odkazy v menu).
if ($action === 'ui_config') {
    $links_raw = json_decode(get_setting('custom_nav_links'), true);
    $links = [];
    if (is_array($links_raw)) {
        foreach ($links_raw as $l) {
            $l_name = trim((string)($l['name'] ?? ''));
            $l_url = trim((string)($l['url'] ?? ''));
            if ($l_name !== '' && preg_match('#^https?://#i', $l_url)) {
                $links[] = ['name' => $l_name, 'url' => $l_url];
            }
        }
    }
    echo json_encode([
        'siteTitle' => trim((string)get_setting('site_title', 'Blood Kings Monitoring')),
        'customLogoUrl' => trim((string)get_setting('custom_logo_url')),
        'customNavLinks' => $links,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2b2f. Zařazení Remote Action do fronty (admin-only) - JSON obdoba
// formuláře v admin.php, pro Actions dropdown v React detailu zařízení.
// Stejná dvojitá kontrola souhlasu: globální seznam typů + per-monitor
// allowed_actions; restart_service navíc vyžaduje validní název služby.
if ($action === 'trigger_remote_action') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ra_mid = (int)($input['monitorId'] ?? 0);
    $ra_action = trim((string)($input['action'] ?? ''));
    $ra_service = trim((string)($input['serviceName'] ?? ''));

    $ra_allowed_types = ['restart_wan', 'restart_wireguard', 'reboot_router', 'renew_dhcp', 'restart_service', 'reconnect_pppoe'];
    try {
        $stmt_ra = $pdo->prepare("SELECT remote_actions_enabled, allowed_actions, name FROM monitors WHERE id = ?");
        $stmt_ra->execute([$ra_mid]);
        $ra_monitor = $stmt_ra->fetch();
        $ra_monitor_allowed = $ra_monitor ? array_filter(explode(',', (string)($ra_monitor['allowed_actions'] ?? ''))) : [];

        if (!$ra_monitor || empty($ra_monitor['remote_actions_enabled'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Remote Actions nejsou pro tento monitor povolené - nejdřív je zapněte v jeho nastavení.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!in_array($ra_action, $ra_allowed_types, true) || !in_array($ra_action, $ra_monitor_allowed, true)) {
            http_response_code(400);
            echo json_encode(['error' => "Akce '{$ra_action}' není pro tento monitor v seznamu povolených akcí."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($ra_action === 'restart_service' && !preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $ra_service)) {
            http_response_code(400);
            echo json_encode(['error' => 'Akce restart_service vyžaduje název služby (povolené znaky: písmena, číslice, _.@-).'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO agent_actions (monitor_id, action_type, service_name, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$ra_mid, $ra_action, $ra_action === 'restart_service' ? $ra_service : null]);
        bk_audit_log($pdo, 'remote_action_triggered', $ra_action . ($ra_action === 'restart_service' ? " ({$ra_service})" : '') . ' na ' . $ra_monitor['name'], 'monitor', $ra_mid);
        echo json_encode(['success' => true, 'queued' => $ra_action], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Akci se nepodařilo zařadit do fronty.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2g. Převod monitoru na agent-side kontrolu (admin-only). Používá se
// u monitorů, jejichž cíl leží v privátní síti - aktivní kontrola z
// hostingu u nich nikdy neuspěje a jen generuje falešné výpadky.
if ($action === 'convert_to_agent_check') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $cv_id = (int)($input['id'] ?? 0);
    $cv_proc = trim((string)($input['process'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $cv_proc)) {
        http_response_code(400);
        echo json_encode(['error' => 'Zadejte název procesu (písmena, číslice, _.@-), který má agent kontrolovat.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt_cv = $pdo->prepare("SELECT m.id, m.name, m.asset_id FROM monitors m WHERE m.id = ?");
        $stmt_cv->execute([$cv_id]);
        $cv_mon = $stmt_cv->fetch();
        if (!$cv_mon || $cv_mon['asset_id'] === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Monitor nenalezen nebo nemá přiřazené zařízení (asset).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Na assetu musí být agent, který kontrolu převezme.
        $stmt_ag = $pdo->prepare("SELECT COUNT(*) FROM monitors WHERE asset_id = ? AND type IN ('vps', 'openwrt')");
        $stmt_ag->execute([$cv_mon['asset_id']]);
        if ((int)$stmt_ag->fetchColumn() === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Na tomto zařízení není agent, který by kontrolu převzal.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Historie zůstává (měníme jen typ a cíl), stav se resetuje na
        // 'unknown' - do prvního výsledku od agenta nic netvrdíme.
        $stmt_up = $pdo->prepare("UPDATE monitors SET type = 'agent_service', target = ?, status = 'unknown', last_status_change = NOW() WHERE id = ?");
        $stmt_up->execute([$cv_proc, $cv_id]);
        log_monitor_event($pdo, $cv_id, $cv_mon['name'], 'agent_service', 'monitor_updated', 'Převedeno na kontrolu agentem (proces ' . $cv_proc . ')');
        bk_audit_log($pdo, 'monitor_updated', $cv_mon['name'] . ' → agent-side kontrola (' . $cv_proc . ')', 'monitor', $cv_id);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Převod se nepodařil.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2h. Stav přečtených upozornění (per uživatel, ne per prohlížeč).
// GET vrací poslední přečtené monitor_logs.id, POST ho posune.
if ($action === 'alerts_read_state') {
    if (empty($_SESSION['admin_logged_in'])) {
        echo json_encode(['readUpToId' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int)($_SESSION['admin_id'] ?? 0);
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $up_to = max(0, (int)($input['readUpToId'] ?? 0));
            $stmt = $pdo->prepare("UPDATE users SET alerts_read_log_id = GREATEST(COALESCE(alerts_read_log_id, 0), ?) WHERE id = ?");
            $stmt->execute([$up_to, $uid]);
            echo json_encode(['success' => true, 'readUpToId' => $up_to], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt = $pdo->prepare("SELECT COALESCE(alerts_read_log_id, 0) FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        echo json_encode(['readUpToId' => (int)$stmt->fetchColumn()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['readUpToId' => 0], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2i. Katalog dostupných dlaždic dashboardu + uživatelské rozložení.
// Odpovídá na "co vlastně sbíráme": katalog se odvozuje z REÁLNÝCH dat
// (mapa metrik ve vps_metrics + klíče, které agenti opravdu poslali), ne
// z pevného seznamu - dlaždice, pro kterou nikdo nikdy nic nezměřil, se
// nenabízí.
if ($action === 'dashboard_layout') {
    $uid = (int)($_SESSION['admin_id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$uid) {
            http_response_code(403);
            echo json_encode(['error' => 'Rozložení lze uložit jen přihlášenému uživateli.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $tiles = [];
        foreach (($input['tiles'] ?? []) as $t) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($t['key'] ?? '')));
            if ($key === '') continue;
            $tiles[] = [
                'key' => $key,
                'visible' => !empty($t['visible']),
                'size' => in_array($t['size'] ?? 'normal', ['normal', 'wide'], true) ? $t['size'] : 'normal',
            ];
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt->execute(['dashboard_layout_user_' . $uid, json_encode($tiles, JSON_UNESCAPED_UNICODE)]);
            echo json_encode(['success' => true, 'tiles' => $tiles], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Rozložení se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    try {
        // 1. Co se skutečně měří: sloupce vps_metrics, které mají alespoň
        //    jednu nenulovou hodnotu za posledních 7 dní.
        $metric_defs = [
            'cpu' => ['col' => 'cpu_usage', 'label' => t('metric_label_cpu'), 'unit' => '%'],
            'ram' => ['col' => 'ram_usage', 'label' => t('metric_label_ram'), 'unit' => '%'],
            'hdd' => ['col' => 'hdd_usage', 'label' => t('metric_label_hdd'), 'unit' => '%'],
            'net' => ['col' => 'net_usage', 'label' => t('metric_label_net'), 'unit' => 'KB/s'],
            'temperature' => ['col' => 'temperature_c', 'label' => t('metric_label_temp'), 'unit' => '°C'],
            'load1' => ['col' => 'load_avg_1', 'label' => 'Load average', 'unit' => ''],
            'swap' => ['col' => 'swap_usage', 'label' => t('metric_label_swap'), 'unit' => '%'],
            'wifi_clients' => ['col' => 'wifi_clients_total', 'label' => t('metric_label_wifi'), 'unit' => ''],
            'conntrack' => ['col' => 'conntrack_pct', 'label' => 'Conntrack', 'unit' => '%'],
        ];
        $measured = [];
        foreach ($metric_defs as $key => $def) {
            try {
                $stmt_m = $pdo->prepare("SELECT COUNT(*) FROM vps_metrics WHERE `{$def['col']}` IS NOT NULL AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $stmt_m->execute();
                $count = (int)$stmt_m->fetchColumn();
            } catch (Throwable $e) {
                $count = 0;
            }
            $measured[$key] = ['label' => $def['label'], 'unit' => $def['unit'], 'samples' => $count];
        }

        // 2. Pevné panely dashboardu (nejsou metrika, ale sekce stránky).
        $panels = [
            'health' => t('tile_health'),
            'attention' => t('tile_attention'),
            'monitors' => t('tile_monitors'),
            'alerts' => t('tile_alerts'),
            'insights' => t('tile_insights'),
            'uptime_history' => t('tile_uptime_history'),
        ];

        $catalog = [];
        foreach ($panels as $key => $label) {
            $catalog[] = ['key' => $key, 'label' => $label, 'kind' => 'panel', 'available' => true, 'samples' => null];
        }
        foreach ($measured as $key => $info) {
            $catalog[] = [
                'key' => 'metric_' . $key,
                'label' => $info['label'] . ($info['unit'] !== '' ? " ({$info['unit']})" : ''),
                'kind' => 'metric',
                // Dlaždice metriky, kterou nikdo neměří, by ukazovala jen pomlčky.
                'available' => $info['samples'] > 0,
                'samples' => $info['samples'],
            ];
        }

        $saved = [];
        if ($uid) {
            $raw = get_setting('dashboard_layout_user_' . $uid, '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $saved = $decoded;
            }
        }

        echo json_encode(['catalog' => $catalog, 'tiles' => $saved], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Katalog dlaždic se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b3. Načtení systémových nastavení (admin-only, maskovaná hesla)
if ($action === 'get_settings') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $all_keys = [
        'site_title', 'site_url', 'email_lang', 'cron_key', 'cron_location', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'sms_gateway_type', 'twilio_sid', 'twilio_token', 'twilio_from', 'smsbrana_user', 'smsbrana_password',
        'agent_offline_timeout', 'agent_notifications_enabled', 'agent_notify_admin_only',
        'discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id', 'slack_webhook_url',
        'oauth_github_client_id', 'oauth_github_client_secret',
        'oauth_google_client_id', 'oauth_google_client_secret',
        'oauth_discord_client_id', 'oauth_discord_client_secret',
        'oauth_gitlab_client_id', 'oauth_gitlab_client_secret',
        'custom_logo_url', 'custom_color_theme', 'custom_nav_links', 'portal_url',
        'metrics_token', 'sla_goal_pct', 'ts3_latest_version',
        'pushover_user_key', 'pushover_api_token', 'pagerduty_routing_key', 'ssl_alert_days', 'agent_registration_token'
    ];

    // Klíče, jejichž hodnoty se maskují (hesla, tokeny, secrety)
    $secret_keys = [
        'smtp_pass', 'twilio_token', 'smsbrana_password',
        'oauth_github_client_secret', 'oauth_google_client_secret', 'oauth_discord_client_secret', 'oauth_gitlab_client_secret',
        'pushover_api_token', 'pagerduty_routing_key', 'metrics_token', 'agent_registration_token'
    ];

    $settings = [];
    $env_locked = [];
    foreach ($all_keys as $key) {
        $val = get_setting($key, '');
        $is_env = is_setting_env_defined($key);
        if ($is_env) {
            $env_locked[] = $key;
        }
        // Maskovat hesla/tokeny: prázdné zůstanou prázdné, jinak ••••••+poslední 4 znaky
        if (in_array($key, $secret_keys, true) && $val !== '') {
            $suffix = mb_strlen($val) >= 4 ? mb_substr($val, -4) : $val;
            $val = '••••••' . $suffix;
        }
        $settings[$key] = $val;
    }

    echo json_encode(['settings' => $settings, 'envLocked' => $env_locked], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2b4. Uložení systémových nastavení (admin-only)
if ($action === 'save_settings') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['settings'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybějící data nastavení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allowed_keys = [
        'site_title', 'site_url', 'email_lang', 'cron_key', 'cron_location', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'sms_gateway_type', 'twilio_sid', 'twilio_token', 'twilio_from', 'smsbrana_user', 'smsbrana_password',
        'whatsapp_api_endpoint', 'whatsapp_token', 'whatsapp_phone_number',
        'agent_offline_timeout', 'agent_notifications_enabled', 'agent_notify_admin_only',
        'discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id', 'slack_webhook_url',
        'oauth_github_client_id', 'oauth_github_client_secret',
        'oauth_google_client_id', 'oauth_google_client_secret',
        'oauth_discord_client_id', 'oauth_discord_client_secret',
        'oauth_gitlab_client_id', 'oauth_gitlab_client_secret',
        'custom_logo_url', 'custom_color_theme', 'custom_nav_links', 'portal_url',
        'metrics_token', 'sla_goal_pct', 'ts3_latest_version',
        'pushover_user_key', 'pushover_api_token', 'pagerduty_routing_key', 'ssl_alert_days', 'agent_registration_token'
    ];

    $secret_keys = [
        'smtp_pass', 'twilio_token', 'smsbrana_password', 'whatsapp_token',
        'oauth_github_client_secret', 'oauth_google_client_secret', 'oauth_discord_client_secret', 'oauth_gitlab_client_secret',
        'pushover_api_token', 'pagerduty_routing_key', 'metrics_token', 'agent_registration_token'
    ];

    try {
        $pdo->beginTransaction();
        $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");

        foreach ($input['settings'] as $key => $val) {
            if (!in_array($key, $allowed_keys, true)) continue;
            if (is_setting_env_defined($key)) continue;

            $val = is_string($val) ? trim($val) : (string)$val;

            // Pokud uživatel nezměnil maskované heslo, přeskočíme
            if (in_array($key, $secret_keys, true) && str_starts_with($val, '••••••')) {
                continue;
            }

            $stmt_set->execute([$key, $val, $val]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Nastavení systému bylo úspěšně uloženo.'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Chyba při ukládání nastavení: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b5. Vygenerování a aktivace Prometheus tokenu (admin-only)
if ($action === 'generate_metrics_token') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $new_token = bin2hex(random_bytes(16));
        // Tabulka settings má sloupce key_name/key_value - dřívější
        // setting_key/setting_value tu shazovalo INSERT a tlačítko
        // "Aktivovat token" končilo pětistovkou.
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('metrics_token', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt->execute([$new_token]);

        echo json_encode(['success' => true, 'metricsToken' => $new_token], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Chyba při generování tokenu.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c. Historie posledních událostí z DB (monitor_logs)
if ($action === 'events') {
    try {
        $monitor_id = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : 0;
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        // Posledních N řádků POKRYJE JEN PÁR DESÍTEK MINUT (cron zapisuje každou
        // minutu za každý monitor), takže výpadky starší než okno v odpovědi
        // vůbec nebyly a UI tvrdilo "vše pass", i když historie výpadky má.
        // Proto se k čerstvému oknu vždy přimíchají i poslední down/warning
        // záznamy bez ohledu na stáří - stejný princip, jakým veřejná status
        // stránka plní svůj seznam incidentů (filtrovaný dotaz, ne tail logu).
        $where_monitor = $monitor_id > 0 ? 'AND l.monitor_id = ?' : '';
        $select_cols = "l.id, l.checked_at, l.status, l.error_message, l.checked_from, l.response_time,
                       m.id as monitor_id, m.name as monitor_name, m.target, m.type";

        $stmt = $pdo->prepare("
            SELECT $select_cols
            FROM monitor_logs l
            JOIN monitors m ON l.monitor_id = m.id
            WHERE 1=1 $where_monitor
            ORDER BY l.id DESC
            LIMIT $limit
        ");
        $stmt->execute($monitor_id > 0 ? [$monitor_id] : []);
        $recent_rows = $stmt->fetchAll();

        $fail_limit = min(50, $limit);
        $stmt_fails = $pdo->prepare("
            SELECT $select_cols
            FROM monitor_logs l
            JOIN monitors m ON l.monitor_id = m.id
            WHERE l.status IN ('down', 'warning') $where_monitor
            ORDER BY l.id DESC
            LIMIT $fail_limit
        ");
        $stmt_fails->execute($monitor_id > 0 ? [$monitor_id] : []);

        $rows_by_id = [];
        foreach (array_merge($recent_rows, $stmt_fails->fetchAll()) as $mr) {
            $rows_by_id[(int)$mr['id']] = $mr;
        }
        krsort($rows_by_id);
        $rows = array_values($rows_by_id);
        $events = [];

        // Vypočítat dobu výpadku: pro down záznamy najít nejbližší up záznam po něm
        foreach ($rows as $i => $r) {
            $outage_duration = null;
            $outage_end = null;
            if ($r['status'] === 'down') {
                // Hledat ve starších záznamech (nižší index = novější) nejbližší up
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($rows[$j]['monitor_id'] == $r['monitor_id'] && $rows[$j]['status'] === 'up') {
                        $start = strtotime($r['checked_at']);
                        $end = strtotime($rows[$j]['checked_at']);
                        $outage_duration = $end - $start;
                        $outage_end = date('d.m.Y H:i:s', $end);
                        break;
                    }
                }
            }

            $events[] = [
                'id' => (int)$r['id'],
                'time' => date('d.m.Y H:i:s', strtotime($r['checked_at'])),
                'timeIso' => date('c', strtotime($r['checked_at'])),
                'monitorId' => (int)$r['monitor_id'],
                'monitorName' => $r['monitor_name'],
                'target' => $r['target'],
                'type' => strtoupper($r['type']),
                'location' => $r['checked_from'] ?: '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)',
                'status' => $r['status'] === 'down' ? 'VÝPADEK' : ($r['status'] === 'warning' ? 'VAROVÁNÍ' : 'OK'),
                'rawStatus' => $r['status'],
                'errorMsg' => $r['error_message'] ?: ($r['status'] === 'down' ? 'Cílový server neodpovídá.' : 'Kontrola proběhla v pořádku.'),
                'responseTime' => $r['response_time'] !== null ? (int)$r['response_time'] : null,
                'isDown' => $r['status'] === 'down',
                'outageDurationSec' => $outage_duration,
                'outageEnd' => $outage_end,
            ];
        }
        echo json_encode(['events' => $events], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['events' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c1a2. Executive Summary + Timeline pro jeden monitor. Tahle logika (health
// score, knowledge tips, forecast/anomaly/network insights, textový souhrn)
// v PHP existuje a běží na veřejné status stránce (index.php/monitor.php) už
// dlouho - React SPA ji ale nikdy nevolala a místo toho si na klientovi
// skládala vlastní obecnou šablonovou větu ("Monitor X (typ) běží na cíli Y...").
// Tenhle endpoint vystavuje tu samou serverovou logiku jako JSON.
if ($action === 'monitor_insights') {
    $monitor_id = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : 0;
    if ($monitor_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt_mon = $pdo->prepare("SELECT * FROM monitors WHERE id = ?");
        $stmt_mon->execute([$monitor_id]);
        $monitor = $stmt_mon->fetch();
        if (!$monitor) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $details = json_decode($monitor['last_details'] ?? '', true);
        if (!is_array($details)) $details = [];
        bk_enrich_monitor_details($pdo, $monitor, $details);

        $stmt_last_log = $pdo->prepare("SELECT status, check_stages FROM monitor_logs WHERE monitor_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_last_log->execute([$monitor_id]);
        $last_log = $stmt_last_log->fetch();
        $status = $last_log['status'] ?? ($monitor['status'] ?? 'unknown');
        $check_stages = null;
        if (!empty($last_log['check_stages'])) {
            $decoded_stages = json_decode($last_log['check_stages'], true);
            if (is_array($decoded_stages)) $check_stages = $decoded_stages;
        }

        $enabled_metrics = bk_get_enabled_metrics($monitor);
        $knowledge_tips = bk_get_knowledge_tips($monitor, $details, $check_stages, $status, $enabled_metrics, $pdo);
        $monitor_insights = array_merge(
            bk_get_forecast_insights($pdo, $monitor),
            bk_get_anomaly_insights($pdo, $monitor),
            bk_get_network_insights($pdo, $monitor, $details)
        );

        // Health score se dnes počítá jen pro TeamSpeak (build_teamspeak_health_areas) -
        // stejné omezení jako na veřejné status stránce, ne nedopatření tady.
        $health_score = null;
        if (strtolower($monitor['type'] ?? '') === 'teamspeak') {
            $health_areas = build_teamspeak_health_areas($monitor, $status, $check_stages, $details);
            $health_score = bk_compute_health_score($health_areas);
        }

        $timeline = bk_get_monitor_timeline($pdo, $monitor_id, 30);
        $summary = bk_build_executive_summary($monitor, $health_score, $knowledge_tips, $monitor_insights, array_slice($timeline, 0, 5));

        echo json_encode([
            'summary' => $summary,
            'healthScore' => $health_score,
            'tips' => array_map(fn($t) => ['severity' => $t['severity'], 'text' => $t['text']], $knowledge_tips),
            'insights' => array_map(fn($i) => ['text' => $i['text'] ?? ''], $monitor_insights),
            'timeline' => array_map(fn($e) => [
                'type' => $e['event_type'],
                'description' => $e['description'],
                'at' => $e['ts'],
                'relative' => bk_relative_time_label($e['ts']),
            ], $timeline),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se sestavit souhrn monitoru.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c1c. Agregované postřehy pro dashboard (mockup: "System Insights" řada).
// Reuse stejných per-monitor builderů jako monitor_insights - forecast
// (regrese disku/RAM), anomálie latence a síťové postřehy - jen posbírané
// přes všechny monitory. Prázdné pole je legitimní odpověď: žádné vymyšlené
// "vše v pořádku" karty se negenerují.
if ($action === 'dashboard_insights') {
    try {
        $limit = min(8, max(1, (int)($_GET['limit'] ?? 4)));

        // Analýzy běží nad historií všech monitorů (regrese, baseline,
        // rolling okna) - na sdíleném hostingu to trvá jednotky sekund a
        // dashboard na to čekal při každém načtení. Výsledek se proto
        // cachuje 5 minut; data se stejně mění v řádu minut, ne sekund.
        $cache_key = 'dashboard_insights_cache';
        $cached_raw = get_setting($cache_key, '');
        if ($cached_raw !== '') {
            $cached = json_decode($cached_raw, true);
            if (is_array($cached) && (time() - (int)($cached['at'] ?? 0)) < 300 && isset($cached['insights'])) {
                echo json_encode(['insights' => array_slice($cached['insights'], 0, $limit), 'cachedAt' => (int)$cached['at']], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $stmt = $pdo->query("SELECT * FROM monitors WHERE type NOT IN ('node', 'probe') ORDER BY id ASC");
        $items = [];
        while ($monitor = $stmt->fetch()) {
            $details = json_decode($monitor['last_details'] ?? '', true);
            if (!is_array($details)) $details = [];
            $found = array_merge(
                bk_get_forecast_insights($pdo, $monitor),
                bk_get_anomaly_insights($pdo, $monitor),
                bk_get_network_insights($pdo, $monitor, $details)
            );
            foreach ($found as $i) {
                $items[] = [
                    'monitorId' => (int)$monitor['id'],
                    'monitorName' => $monitor['name'],
                    'kind' => (string)($i['type'] ?? 'trend'),
                    'text' => (string)($i['text'] ?? ''),
                    'detail' => (string)($i['detail'] ?? ''),
                ];
            }
        }
        // Kritičtější druhy dopředu: síť/anomálie před dlouhodobými trendy.
        $rank = ['network' => 0, 'anomaly' => 1, 'forecast' => 2, 'trend' => 3];
        usort($items, fn($a, $b) => ($rank[$a['kind']] ?? 4) <=> ($rank[$b['kind']] ?? 4));
        try {
            $stmt_cache = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt_cache->execute([$cache_key, json_encode(['at' => time(), 'insights' => array_slice($items, 0, 8)], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $ce) { /* cache je volitelná */ }
        echo json_encode(['insights' => array_slice($items, 0, $limit)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se sestavit postřehy.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c1b. Denní rozpad dostupnosti za posledních N dní (pro heatmapu na dashboardu) -
// skutečné agregace z monitor_logs, ne odhad z aktuálního stavu monitoru.
if ($action === 'daily_uptime') {
    try {
        $days = min(366, max(1, (int)($_GET['days'] ?? 30)));

        $stmt_mon = $pdo->query("SELECT id, name FROM monitors WHERE type NOT IN ('node', 'probe') ORDER BY id ASC");
        $mon_rows = $stmt_mon->fetchAll();

        $stmt_days = $pdo->prepare("
            SELECT monitor_id, DATE(checked_at) AS day,
                   SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) AS up_count,
                   SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) AS down_count,
                   SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) AS warning_count,
                   SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maint_count,
                   COUNT(*) AS total_count
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY monitor_id, DATE(checked_at)
        ");
        $stmt_days->execute([$days]);

        $by_monitor = [];
        while ($row = $stmt_days->fetch()) {
            $by_monitor[(int)$row['monitor_id']][$row['day']] = $row;
        }

        // Frontend (dashboard.tsx) expects `series` keyed by monitor id -> day
        // list, i.e. Record<number, DayRow[]>, not an array of
        // {monitorId, name, days} objects. A shape mismatch here means
        // data.series is always undefined, so the real data never loads and
        // the UI silently falls back to a fabricated all-green 30-day history.
        $series = [];
        foreach ($mon_rows as $m) {
            $mid = (int)$m['id'];
            $day_list = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $day_key = date('Y-m-d', strtotime("-$i day"));
                $day_display = date('j.n.', strtotime($day_key));
                $d = $by_monitor[$mid][$day_key] ?? null;

                // 'unknown' záznamy (typy bez aktivní kontroly) nejsou měření -
                // do dostupnosti se nepočítají vůbec, jinak by monitor, který
                // nikdy nikdo netestoval, vykazoval falešné výpadky.
                $up = $d ? (int)$d['up_count'] : 0;
                $down = $d ? (int)$d['down_count'] : 0;
                $warn = $d ? (int)$d['warning_count'] : 0;
                $maint = $d ? (int)$d['maint_count'] : 0;
                $measured = $up + $down + $warn;

                if ($measured === 0 && $maint === 0) {
                    // Den bez jediné měřené kontroly nemá 0% uptime - nemá žádný.
                    $day_list[] = ['date' => $day_display, 'status' => 'paused', 'uptimePct' => null, 'detail' => t('day_no_data')];
                    continue;
                }

                $uptimePct = $measured > 0 ? round(($up / $measured) * 100, 1) : null;

                if ($maint > 0 && $maint >= $measured) {
                    $status = 'maintenance';
                    $detail = t('day_maintenance');
                } elseif ($down > 0) {
                    $status = 'down';
                    $detail = sprintf(t('day_down_detail'), $down, $measured, $uptimePct);
                } elseif ($warn > 0) {
                    $status = 'warning';
                    $detail = sprintf(t('day_warning_detail'), $warn, $measured);
                } else {
                    $status = 'up';
                    $detail = sprintf(t('day_up_detail'), $measured);
                }

                $day_list[] = ['date' => $day_display, 'status' => $status, 'uptimePct' => $uptimePct, 'detail' => $detail];
            }

            $series[$mid] = $day_list;
        }

        // Cast to stdClass (not JSON_FORCE_OBJECT, which would also flatten
        // the nested `days` arrays into objects) so `series` always encodes
        // as a {monitorId: days[]} object even if it's empty or its keys
        // happen to form a 0-indexed sequence - json_encode() would
        // otherwise emit `[]` for a plain array in either case, and the
        // frontend indexes into it by id.
        echo json_encode(['series' => (object)$series], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['series' => (object)[]], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c2. Incidenty a výpadky z DB (incidents a monitor_logs)
function bk_duration_text(int $diff): string {
    $diff = max(0, $diff);
    $days_d = floor($diff / 86400);
    $hours_d = floor(($diff % 86400) / 3600);
    $mins_d = floor(($diff % 3600) / 60);
    $parts = [];
    if ($days_d > 0) $parts[] = "$days_d dní";
    if ($hours_d > 0) $parts[] = "$hours_d hodin";
    $parts[] = "$mins_d minut";
    return implode(', ', $parts);
}

if ($action === 'incidents') {
    try {
        $incidents = [];

        // Výpadky CÍLOVÝCH monitorů, co jsou down PRÁVĚ TEĎ - bere se poslední 'down'
        // záznam pro každý aktuálně nedostupný monitor, ne každý historický down
        // řádek (ten by ukazoval dávno vyřešené výpadky jako stále probíhající).
        try {
            $stmt_logs = $pdo->query("
                SELECT l.id, l.monitor_id, l.checked_at, l.error_message,
                       m.name as monitor_name, m.target, m.type
                FROM monitor_logs l
                JOIN monitors m ON l.monitor_id = m.id
                WHERE m.status = 'down'
                  AND l.id = (SELECT MAX(l2.id) FROM monitor_logs l2 WHERE l2.monitor_id = l.monitor_id AND l2.status = 'down')
                ORDER BY l.id DESC
                LIMIT 50
            ");
            $log_rows = $stmt_logs ? $stmt_logs->fetchAll() : [];
            foreach ($log_rows as $r) {
                $start_ts = strtotime($r['checked_at']);
                $incidents[] = [
                    'id' => (int)$r['id'],
                    'monitor_id' => (int)$r['monitor_id'],
                    'monitor_name' => $r['monitor_name'],
                    'target' => $r['target'],
                    'type' => strtoupper($r['type']),
                    'status' => 'open',
                    'severity' => 'down',
                    'started_at' => date('d.m.Y H:i:s', $start_ts),
                    'resolved_at' => null,
                    'duration_text' => bk_duration_text(time() - $start_ts),
                    'reason' => $r['error_message'] ?: 'Cílový port neodpovídá',
                ];
            }
        } catch (Throwable $t) {}

        // Ručně nahlášené / globální incidenty (tabulka `incidents` - title/impact/status,
        // bez vazby na konkrétní monitor).
        $manual_incidents = [];
        try {
            $stmt_inc = $pdo->query("
                SELECT id, title, impact, status, created_at, updated_at, resolved_at
                FROM incidents
                ORDER BY id DESC
                LIMIT 50
            ");
            foreach ($stmt_inc->fetchAll() as $r) {
                $start_ts = strtotime($r['created_at']);
                $end_ts = $r['resolved_at'] ? strtotime($r['resolved_at']) : time();
                $updates = [];
                try {
                    $stmt_upd = $pdo->prepare("SELECT status, message, created_at FROM incident_updates WHERE incident_id = ? ORDER BY id ASC");
                    $stmt_upd->execute([(int)$r['id']]);
                    foreach ($stmt_upd->fetchAll() as $u) {
                        $updates[] = ['status' => $u['status'], 'message' => $u['message'], 'at' => date('d.m.Y H:i:s', strtotime($u['created_at']))];
                    }
                } catch (Throwable $t) {}

                $manual_incidents[] = [
                    'id' => (int)$r['id'],
                    'title' => $r['title'],
                    'impact' => $r['impact'],
                    'status' => $r['status'],
                    'createdAt' => date('d.m.Y H:i:s', $start_ts),
                    'resolvedAt' => $r['resolved_at'] ? date('d.m.Y H:i:s', $end_ts) : null,
                    'durationText' => bk_duration_text($end_ts - $start_ts),
                    'updates' => $updates,
                ];
            }
        } catch (Throwable $t) {}

        echo json_encode(['incidents' => $incidents, 'manualIncidents' => $manual_incidents], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['incidents' => [], 'manualIncidents' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c3. Ruční nahlášení incidentu (admin-only) - zapisuje do `incidents` + první
// zprávu do `incident_updates`.
if ($action === 'create_incident') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim($input['title'] ?? '');
    $message = trim($input['message'] ?? '');
    $impact = in_array($input['impact'] ?? '', ['minor', 'major', 'critical'], true) ? $input['impact'] : 'minor';

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Název incidentu je povinný.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO incidents (title, impact, status) VALUES (?, ?, 'investigating')");
        $stmt->execute([$title, $impact]);
        $incident_id = (int)$pdo->lastInsertId();

        if ($message !== '') {
            $stmt_upd = $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, 'investigating', ?)");
            $stmt_upd->execute([$incident_id, $message]);
        }

        echo json_encode(['success' => true, 'id' => $incident_id], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Incident se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2d. SLA Report — reálná uptime a outage data z monitor_logs za posledních 30 dní
// 2b2j. Přehled SLA pro stránku webů: dostupnost 7/30/365 dní na monitor.
// sla_report trvá i 3,7 s (detailní výpadky, MTTR) - stránka webů potřebuje
// jen procenta, takže se počítají jedním oknovaným dotazem a cachují 10 minut.
if ($action === 'websites_overview') {
    try {
        $cache_raw = get_setting('websites_overview_cache', '');
        if ($cache_raw !== '') {
            $cached = json_decode($cache_raw, true);
            if (is_array($cached) && isset($cached['at'], $cached['data']) && time() - (int)$cached['at'] < 600) {
                echo json_encode($cached['data'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Jediný průchod ročním oknem; kratší okna přes podmíněné sumy.
        // 'unknown'/'paused' se nepočítají ani do jmenovatele - nejsou to
        // měření, jen přiznaná díra ve sběru.
        $stmt = $pdo->query("
            SELECT monitor_id,
                   SUM(status = 'up' AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS up7,
                   SUM(status IN ('up','down','warning') AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS tot7,
                   SUM(status = 'up' AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  AS up30,
                   SUM(status IN ('up','down','warning') AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  AS tot30,
                   SUM(status = 'up')                                                     AS up365,
                   SUM(status IN ('up','down','warning'))                                 AS tot365,
                   MIN(checked_at)                                                        AS measured_since
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)
            GROUP BY monitor_id
        ");
        $sla = [];
        while ($row = $stmt->fetch()) {
            $pct = function ($up, $tot) {
                // Okno bez jediného měření = null, ne vymyšlených 100 %.
                return (int)$tot > 0 ? round((int)$up / (int)$tot * 100, 3) : null;
            };
            $sla[(int)$row['monitor_id']] = [
                'sla7' => $pct($row['up7'], $row['tot7']),
                'sla30' => $pct($row['up30'], $row['tot30']),
                'sla365' => $pct($row['up365'], $row['tot365']),
                'measuredSince' => $row['measured_since'],
            ];
        }

        $data = [
            'slaGoal' => (float)get_setting('sla_goal_pct', '99.95'),
            'monitors' => $sla,
        ];
        try {
            $stmt2 = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('websites_overview_cache', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt2->execute([json_encode(['at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // Cache je optimalizace - když se neuloží, endpoint jen počítá častěji.
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Přehled SLA se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'sla_report') {
    try {
        $days = min(366, max(1, (int)($_GET['days'] ?? 30)));

        // Per-monitor uptime a výpadky
        $stmt = $pdo->prepare("
            SELECT m.id, m.name, m.target, m.type, m.status as current_status, m.last_status_change,
                   COUNT(l.id) as total_checks,
                   SUM(CASE WHEN l.status = 'up' THEN 1 ELSE 0 END) as up_checks,
                   SUM(CASE WHEN l.status = 'down' THEN 1 ELSE 0 END) as down_checks,
                   SUM(CASE WHEN l.status IN ('up','down','warning') THEN 1 ELSE 0 END) as non_maint_checks
            FROM monitors m
            LEFT JOIN monitor_logs l ON l.monitor_id = m.id AND l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            WHERE m.type NOT IN ('node', 'probe')
            GROUP BY m.id, m.name, m.target, m.type, m.status, m.last_status_change
            ORDER BY m.id ASC
        ");
        $stmt->execute([$days]);
        $monitors = $stmt->fetchAll();

        $report = [];
        foreach ($monitors as $m) {
            $mid = (int)$m['id'];
            $non_maint = (int)$m['non_maint_checks'];
            $up = (int)$m['up_checks'];
            $down = (int)$m['down_checks'];
            // Monitor bez jediné měřené kontroly v okně nemá SLA - null,
            // ne dokonalých 100.0 do reportu, který nikdo nenaměřil.
            $uptimePct = $non_maint > 0 ? round(($up / $non_maint) * 100, 3) : null;

            // Rychlé načtení posledního výpadku přímo z DB (monitor_logs) - konec výpadku
            // dohledáme jako nejbližší následující 'up' záznam, stejně jako u action=events,
            // ne odhadem pevných 10 minut.
            $last_outage = null;
            try {
                $stmt_out = $pdo->prepare("
                    SELECT id, checked_at, error_message
                    FROM monitor_logs
                    WHERE monitor_id = ? AND status = 'down'
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt_out->execute([$mid]);
                $out_row = $stmt_out->fetch();
                if ($out_row) {
                    $out_start = strtotime($out_row['checked_at']);
                    $resolved = $m['current_status'] !== 'down';
                    $out_end_ts = time();
                    if ($resolved) {
                        $stmt_next_up = $pdo->prepare("
                            SELECT checked_at FROM monitor_logs
                            WHERE monitor_id = ? AND status = 'up' AND id > ?
                            ORDER BY id ASC LIMIT 1
                        ");
                        $stmt_next_up->execute([$mid, $out_row['id']]);
                        $next_up = $stmt_next_up->fetchColumn();
                        $out_end_ts = $next_up ? strtotime($next_up) : $out_start;
                    }
                    $last_outage = [
                        'start' => date('d.m.Y H:i:s', $out_start),
                        'end' => $resolved ? date('d.m.Y H:i:s', $out_end_ts) : null,
                        'durationSec' => max(0, $out_end_ts - $out_start),
                        'reason' => $out_row['error_message'] ?: 'Port neodpovídá',
                        'resolved' => $resolved,
                    ];
                }
            } catch (Throwable $t) {}

            $outageMinutes = $down;
            $mttr = $last_outage['resolved'] ?? false ? $last_outage['durationSec'] : null;

            // Percentily odezvy počítané z reálných měření za období, ne odhadem podle typu.
            $p50 = $p95 = $p99 = null;
            try {
                $stmt_rt = $pdo->prepare("
                    SELECT response_time FROM monitor_logs
                    WHERE monitor_id = ? AND response_time IS NOT NULL AND response_time > 0
                          AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ORDER BY response_time ASC
                ");
                $stmt_rt->execute([$mid, $days]);
                $rt_values = array_map('intval', $stmt_rt->fetchAll(PDO::FETCH_COLUMN));
                $rt_count = count($rt_values);
                if ($rt_count > 0) {
                    $pct = function (array $vals, int $n, float $p) {
                        $idx = (int)floor($p * ($n - 1));
                        return $vals[max(0, min($n - 1, $idx))];
                    };
                    $p50 = $pct($rt_values, $rt_count, 0.50);
                    $p95 = $pct($rt_values, $rt_count, 0.95);
                    $p99 = $pct($rt_values, $rt_count, 0.99);
                }
            } catch (Throwable $t) {}

            $report[] = [
                'id' => $mid,
                'name' => $m['name'],
                'target' => $m['target'],
                'type' => strtoupper($m['type']),
                'currentStatus' => $m['current_status'],
                'lastStatusChange' => $m['last_status_change'] ? date('c', strtotime($m['last_status_change'])) : null,
                'uptimePercent' => $uptimePct,
                'totalChecks' => (int)$m['total_checks'],
                'upChecks' => $up,
                'downChecks' => $down,
                'outageMinutes' => $outageMinutes,
                'lastOutage' => $last_outage,
                'mttrSec' => $mttr,
                'p50Ms' => $p50,
                'p95Ms' => $p95,
                'p99Ms' => $p99,
            ];
        }

        $sla_goal = (float)get_setting('sla_goal_pct', '99.95');
        // Průměr jen z monitorů, které mají skutečně naměřené SLA; bez
        // jediného takového je výsledek null, ne vymyšlených 100 %.
        $uptime_vals = array_filter(array_column($report, 'uptimePercent'), fn($v) => $v !== null);
        $overall_uptime = count($uptime_vals) > 0
            ? round(array_sum($uptime_vals) / count($uptime_vals), 3)
            : null;
        $total_outage = array_sum(array_column($report, 'outageMinutes'));
        $mttr_values = array_filter(array_column($report, 'mttrSec'), fn($v) => $v !== null);
        $overall_mttr = !empty($mttr_values) ? round(array_sum($mttr_values) / count($mttr_values)) : null;

        // Prometheus token je credential - do odpovědi patří JEN pro
        // přihlášeného administrátora. sla_report je jinak veřejný endpoint,
        // takže bez téhle podmínky si token mohl přečíst kdokoli, kdo
        // otevřel /app/reports (a metrics.php by mu pak reálně odpovídalo).
        $is_admin_session = !empty($_SESSION['admin_logged_in']) && ($_SESSION['admin_role'] ?? '') === 'admin';
        $metrics_token = $is_admin_session ? trim((string)get_setting('metrics_token')) : '';
        $site_title = trim((string)get_setting('site_title', 'Blood Kings Monitoring'));
        $custom_logo_url = trim((string)get_setting('custom_logo_url', ''));

        echo json_encode([
            'slaGoal' => $sla_goal,
            'overallUptime' => $overall_uptime,
            'totalOutageMinutes' => $total_outage,
            'overallMttrSec' => $overall_mttr,
            'monitors' => $report,
            'metricsToken' => $metrics_token,
            'siteTitle' => $site_title,
            'customLogoUrl' => $custom_logo_url,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Dřív se tu při chybě DB vracelo 100% uptime a 0 minut výpadků -
        // dokonalé SLA přesně ve chvíli, kdy o skutečném stavu nevíme nic.
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se sestavit SLA report.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2e. Auditní logy z databáze (změny nastavení, přihlášení, auditní protokol)
if ($action === 'audit_logs') {
    try {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        // Stejný problém jako u action=events: tail logu pokryje jen pár
        // desítek minut, starší chybové záznamy z okna vypadnou a protokol
        // pak lže, že je vše OK. Poslední down/warning řádky se proto
        // přimíchávají vždy.
        $audit_cols = "l.id, l.checked_at as time, l.monitor_id, l.status, l.response_time, l.error_message, m.name as monitor_name, m.type as monitor_type";
        $stmt = $pdo->prepare("
            SELECT $audit_cols
            FROM monitor_logs l
            LEFT JOIN monitors m ON m.id = l.monitor_id
            ORDER BY l.id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $recent_rows = $stmt->fetchAll();

        $stmt_fails = $pdo->prepare("
            SELECT $audit_cols
            FROM monitor_logs l
            LEFT JOIN monitors m ON m.id = l.monitor_id
            WHERE l.status IN ('down', 'warning')
            ORDER BY l.id DESC
            LIMIT ?
        ");
        $stmt_fails->bindValue(1, min(50, $limit), PDO::PARAM_INT);
        $stmt_fails->execute();

        $rows_by_id = [];
        foreach (array_merge($recent_rows, $stmt_fails->fetchAll()) as $mr) {
            $rows_by_id[(int)$mr['id']] = $mr;
        }
        krsort($rows_by_id);
        $rows = array_values($rows_by_id);

        $logs = [];
        foreach ($rows as $r) {
            $row_status = strtolower($r['status'] ?? '');
            $isDown = $row_status === 'down';
            $isWarn = $row_status === 'warning';
            $mName = $r['monitor_name'] ?: "Monitor #{$r['monitor_id']}";
            $mType = strtoupper($r['monitor_type'] ?: 'HTTP');

            $logs[] = [
                'id' => (int)$r['id'],
                'time' => date('d.m.Y H:i:s', strtotime($r['time'])),
                'action' => $isDown ? "VÝPADEK: {$mName}" : ($isWarn ? "VAROVÁNÍ: {$mName}" : "KONTROLA OK: {$mName}"),
                'details' => $r['error_message'] ?: ($isDown ? "[{$mName}] {$mType} neodpovídá na test" : "[{$mName}] {$mType} test OK (Odezva {$r['response_time']} ms)"),
                'status' => $isDown ? 'down' : ($isWarn ? 'warning' : 'up'),
                'user' => 'Systémový Agent (Cron)',
            ];
        }
        echo json_encode(['logs' => $logs], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['logs' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2g. Časové řady a metriky pro grafy (metric_series)
// Mapa klíčů metrik (viz apps/monitor/src/api/types.ts MetricKey) na skutečné
// sloupce ve `vps_metrics`. 'response_time'/'latency' je zvlášť - to se měří
// při každé kontrole do monitor_logs, ne při agent reportu do vps_metrics.
$BK_METRIC_COLUMN_MAP = [
    'cpu' => ['col' => 'cpu_usage', 'unit' => '%', 'label' => 'Využití CPU'],
    'ram' => ['col' => 'ram_usage', 'unit' => '%', 'label' => 'Využití paměti'],
    'hdd' => ['col' => 'hdd_usage', 'unit' => '%', 'label' => 'Zaplnění disku'],
    'net' => ['col' => 'net_usage', 'unit' => 'KB/s', 'label' => 'Síťový provoz'],
    'load1' => ['col' => 'load_avg_1', 'unit' => '', 'label' => 'Load Average (1 min)'],
    'load5' => ['col' => 'load_avg_5', 'unit' => '', 'label' => 'Load Average (5 min)'],
    'load15' => ['col' => 'load_avg_15', 'unit' => '', 'label' => 'Load Average (15 min)'],
    'cpu_steal' => ['col' => 'cpu_steal', 'unit' => '%', 'label' => 'CPU Steal'],
    'swap' => ['col' => 'swap_usage', 'unit' => '%', 'label' => 'Využití swapu'],
    'disk_io_read' => ['col' => 'disk_io_read_kbps', 'unit' => 'KB/s', 'label' => 'Čtení z disku'],
    'disk_io_write' => ['col' => 'disk_io_write_kbps', 'unit' => 'KB/s', 'label' => 'Zápis na disk'],
    'net_errors' => ['col' => 'net_errors', 'unit' => '', 'label' => 'Síťové chyby'],
    'iowait' => ['col' => 'iowait_pct', 'unit' => '%', 'label' => 'Čekání na I/O'],
    'inode_usage' => ['col' => 'inode_usage_pct', 'unit' => '%', 'label' => 'Využití inodů'],
    'ts_clients' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'TeamSpeak Klienti'],
    'ts_process_cpu' => ['col' => 'ts_process_cpu', 'unit' => '%', 'label' => 'CPU procesu TS3'],
    'ts_process_ram' => ['col' => 'ts_process_ram', 'unit' => 'MB', 'label' => 'RAM procesu TS3'],
    'net_ipv4' => ['col' => 'net_ipv4_kbps', 'unit' => 'KB/s', 'label' => 'IPv4 provoz'],
    'net_ipv6' => ['col' => 'net_ipv6_kbps', 'unit' => 'KB/s', 'label' => 'IPv6 provoz'],
    'temperature_c' => ['col' => 'temperature_c', 'unit' => '°C', 'label' => 'Teplota CPU'],
    // Sloupce, které se roky ukládaly, ale žádný graf je nečetl (audit 2026-08-05):
    'zombie_count' => ['col' => 'zombie_count', 'unit' => '', 'label' => 'Zombie procesy'],
    'fork_rate' => ['col' => 'fork_rate', 'unit' => '/s', 'label' => 'Fork rate'],
    'wifi_clients' => ['col' => 'wifi_clients_total', 'unit' => '', 'label' => 'Wi-Fi klienti'],
    'conntrack' => ['col' => 'conntrack_pct', 'unit' => '%', 'label' => 'Conntrack tabulka'],
];

if ($action === 'metric_series') {
    $monitor_id = (int)($_GET['monitor_id'] ?? $_GET['id'] ?? 1);
    $metric = $_GET['metric'] ?? 'response_time';
    $period = $_GET['period'] ?? '24h';
    $hours = $period === '30d' ? 720 : ($period === '7d' ? 168 : ($period === '1h' ? 1 : ($period === '15m' ? 1 : 24)));

    try {
        $stmt_mon = $pdo->prepare("SELECT id FROM monitors WHERE id = ? OR asset_id = ? LIMIT 1");
        $stmt_mon->execute([$monitor_id, $monitor_id]);
        $real_id = $stmt_mon->fetchColumn();

        if (!$real_id) {
            echo json_encode(['points' => [], 'unit' => '', 'label' => 'Metrika', 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $points = [];
        if ($metric === 'response_time' || $metric === 'latency') {
            $unit = 'ms';
            $label = 'Doba odezvy (HTTP/Ping)';
            $stmt = $pdo->prepare("
                SELECT UNIX_TIMESTAMP(checked_at) as ts, response_time as val
                FROM monitor_logs
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) AND response_time IS NOT NULL
                ORDER BY checked_at ASC
            ");
            $stmt->execute([$real_id, $hours]);
            foreach ($stmt->fetchAll() as $r) {
                $points[] = [(int)$r['ts'], (float)$r['val']];
            }
        } elseif (isset($BK_METRIC_COLUMN_MAP[$metric])) {
            $def = $BK_METRIC_COLUMN_MAP[$metric];
            $col = $def['col'];
            $unit = $def['unit'];
            $label = $def['label'];
            $stmt = $pdo->prepare("
                SELECT UNIX_TIMESTAMP(checked_at) as ts, {$col} as val
                FROM vps_metrics
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) AND {$col} IS NOT NULL
                ORDER BY checked_at ASC
            ");
            $stmt->execute([$real_id, $hours]);
            foreach ($stmt->fetchAll() as $r) {
                $points[] = [(int)$r['ts'], (float)$r['val']];
            }
        } else {
            echo json_encode(['points' => [], 'unit' => '', 'label' => 'Metrika', 'error' => 'Neznámá metrika'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Žádná fabrikace: prázdná řada znamená, že agent tuhle metriku zatím
        // neposlal nebo pro zadané období nejsou záznamy - ne že si to vymyslíme.
        echo json_encode(['unit' => $unit, 'label' => $label, 'points' => $points], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['points' => [], 'unit' => '', 'label' => 'Metrika', 'error' => 'Chyba při načítání metriky'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2g2. Dávkové načtení všech grafů zařízení jedním DB dotazem (místo 9 samostatných
// požadavků na metric_series) - výrazně rychlejší načtení stránky detailu zařízení.
if ($action === 'metric_series_batch') {
    $monitor_id = (int)($_GET['monitor_id'] ?? $_GET['id'] ?? 1);
    $period = $_GET['period'] ?? '24h';
    $hours = $period === '30d' ? 720 : ($period === '7d' ? 168 : ($period === '1h' ? 1 : ($period === '15m' ? 1 : 24)));

    try {
        $stmt_mon = $pdo->prepare("SELECT id FROM monitors WHERE id = ? OR asset_id = ? LIMIT 1");
        $stmt_mon->execute([$monitor_id, $monitor_id]);
        $real_id = $stmt_mon->fetchColumn();

        if (!$real_id) {
            echo json_encode(['series' => [], 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $series = [];

        // Latence - z monitor_logs (jeden řádek na kontrolu)
        $stmt_lat = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(checked_at) as ts, response_time as val
            FROM monitor_logs
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? HOUR) AND response_time IS NOT NULL
            ORDER BY checked_at ASC
        ");
        $stmt_lat->execute([$real_id, $hours]);
        $lat_points = [];
        foreach ($stmt_lat->fetchAll() as $r) {
            $lat_points[] = [(int)$r['ts'], (float)$r['val']];
        }
        $series['response_time'] = ['unit' => 'ms', 'label' => 'Doba odezvy (HTTP/Ping)', 'points' => $lat_points];

        // Všechny agentí metriky - z vps_metrics (jeden řádek na agent report), jedním dotazem.
        $cols = array_column($BK_METRIC_COLUMN_MAP, 'col');
        $col_list = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $stmt_vm = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(checked_at) as ts, {$col_list}
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY checked_at ASC
        ");
        $stmt_vm->execute([$real_id, $hours]);
        $vm_rows = $stmt_vm->fetchAll();

        foreach ($BK_METRIC_COLUMN_MAP as $metric_key => $def) {
            $pts = [];
            foreach ($vm_rows as $r) {
                if ($r[$def['col']] !== null) {
                    $pts[] = [(int)$r['ts'], (float)$r[$def['col']]];
                }
            }
            $series[$metric_key] = ['unit' => $def['unit'], 'label' => $def['label'], 'points' => $pts];
        }

        echo json_encode(['series' => $series], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['series' => [], 'error' => 'Chyba při načítání metrik'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2f. Ruční odeslání digestu (admin-only)
if ($action === 'send_digest') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $period = ($input['period'] ?? $_GET['period'] ?? '') === 'monthly' ? 'monthly' : 'weekly';
    try {
        if (function_exists('send_digest_report') && send_digest_report($pdo, $period)) {
            echo json_encode(['success' => true, 'message' => ($period === 'monthly' ? 'Měsíční' : 'Týdenní') . ' digest byl úspěšně odeslán.'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => false, 'message' => 'Odeslání digestu selhalo — zkontrolujte SMTP nastavení a e-mailové adresy administrátorů.'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2f. Odběry notifikací pro aktuálního uživatele
if ($action === 'get_subscriptions') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nepřihlášen'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $user_id = (int)($_SESSION['admin_id'] ?? 0);
    try {
        $stmt_mon = $pdo->query("SELECT id, name, type FROM monitors ORDER BY id ASC");
        $monitors = $stmt_mon->fetchAll();
        $stmt_sub = $pdo->prepare("SELECT monitor_id, email_notifications, sms_notifications, whatsapp_notifications FROM user_subscriptions WHERE user_id = ?");
        $stmt_sub->execute([$user_id]);
        $subs_raw = $stmt_sub->fetchAll();
        $subs = [];
        foreach ($subs_raw as $s) {
            $subs[(int)$s['monitor_id']] = [
                'email' => (int)$s['email_notifications'],
                'sms' => (int)$s['sms_notifications'],
                'whatsapp' => (int)$s['whatsapp_notifications'],
            ];
        }

        $result = [];
        foreach ($monitors as $m) {
            $mid = (int)$m['id'];
            $result[] = [
                'id' => $mid,
                'name' => $m['name'],
                'type' => $m['type'],
                'email' => $subs[$mid]['email'] ?? 0,
                'sms' => $subs[$mid]['sms'] ?? 0,
                'whatsapp' => $subs[$mid]['whatsapp'] ?? 0,
            ];
        }
        echo json_encode(['subscriptions' => $result], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['subscriptions' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2g. Uložení odběrů notifikací
if ($action === 'save_subscriptions') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Nepřihlášen'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $user_id = (int)($_SESSION['admin_id'] ?? 0);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['subscriptions'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybějící data.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $stmt_del = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
        $stmt_del->execute([$user_id]);
        $stmt_ins = $pdo->prepare("INSERT INTO user_subscriptions (user_id, monitor_id, email_notifications, sms_notifications, whatsapp_notifications) VALUES (?, ?, ?, ?, ?)");
        foreach ($input['subscriptions'] as $s) {
            $mid = (int)($s['id'] ?? 0);
            if ($mid <= 0) continue;
            $stmt_ins->execute([$user_id, $mid, (int)($s['email'] ?? 0), (int)($s['sms'] ?? 0), (int)($s['whatsapp'] ?? 0)]);
        }
        $pdo->commit();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}


// 3. Seznam uživatelů z databáze (vyžaduje přihlášení)
if ($action === 'users') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt = $pdo->query("SELECT id, username, email, phone, role, totp_enabled, oauth_provider, created_at FROM users ORDER BY id ASC");
        $rows = $stmt->fetchAll();
        $users = [];
        foreach ($rows as $u) {
            $users[] = [
                'id' => (int)$u['id'],
                'username' => $u['username'],
                'email' => $u['email'],
                'phone' => $u['phone'] ?? null,
                'role' => $u['role'] ?? 'admin',
                'totpEnabled' => !empty($u['totp_enabled']),
                'oauthProvider' => $u['oauth_provider'] ?? null,
                'createdAt' => $u['created_at'] ? date('c', strtotime($u['created_at'])) : null,
                'isSelf' => ($u['id'] == ($_SESSION['admin_id'] ?? 0)),
            ];
        }
        echo json_encode(['users' => $users], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['users' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 4. Historie metrik pro grafy
if ($action === 'metrics_history') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $period = $_GET['period'] ?? '24h';

    $result = [
        'labels' => [], 'cpu' => [], 'ram' => [], 'hdd' => [], 'net' => [],
        'cpu_avg' => 0, 'cpu_max' => 0, 'ram_avg' => 0, 'ram_max' => 0, 'hdd_avg' => 0, 'hdd_max' => 0, 'net_avg' => 0, 'net_max' => 0,
    ];

    try {
        if ($period === '7d') {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(checked_at, '%d.%m. %H:00') AS label,
                       AVG(cpu_usage) AS cpu, MAX(cpu_usage) AS cpu_peak,
                       AVG(ram_usage) AS ram, MAX(ram_usage) AS ram_peak,
                       AVG(hdd_usage) AS hdd, MAX(hdd_usage) AS hdd_peak,
                       AVG(net_usage) AS net, MAX(net_usage) AS net_peak
                FROM vps_metrics
                WHERE (monitor_id = ? OR ? = 0) AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE_FORMAT(checked_at, '%Y-%m-%d %H')
                ORDER BY MIN(checked_at) ASC
            ");
            $stmt->execute([$monitor_id, $monitor_id]);
        } elseif ($period === '30d') {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(checked_at, '%d.%m.') AS label,
                       AVG(cpu_usage) AS cpu, MAX(cpu_usage) AS cpu_peak,
                       AVG(ram_usage) AS ram, MAX(ram_usage) AS ram_peak,
                       AVG(hdd_usage) AS hdd, MAX(hdd_usage) AS hdd_peak,
                       AVG(net_usage) AS net, MAX(net_usage) AS net_peak
                FROM vps_metrics
                WHERE (monitor_id = ? OR ? = 0) AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(checked_at)
                ORDER BY MIN(checked_at) ASC
            ");
            $stmt->execute([$monitor_id, $monitor_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(checked_at, '%H:%i') AS label,
                       cpu_usage AS cpu, cpu_usage AS cpu_peak,
                       ram_usage AS ram, ram_usage AS ram_peak,
                       hdd_usage AS hdd, hdd_usage AS hdd_peak,
                       net_usage AS net, net_usage AS net_peak
                FROM vps_metrics
                WHERE (monitor_id = ? OR ? = 0) AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY checked_at ASC
            ");
            $stmt->execute([$monitor_id, $monitor_id]);
        }
        $rows = $stmt->fetchAll();

        // Žádné řádky = žádný graf. Dřív se tu generovala syntetická sinusová
        // řada "pro přirozený průběh" - tedy vymyšlená data vydávaná za měření.
        // Prázdné pole nechá frontend říct "žádná data", což je pravda.
        foreach ($rows as $r) {
            $result['labels'][] = $r['label'];
            // NULL v metrice (např. cpuusage bez CloudLinux) zůstává NULL -
            // graf ukáže mezeru, ne falešnou nulu.
            $result['cpu'][] = $r['cpu'] !== null ? round((float)$r['cpu'], 1) : null;
            $result['ram'][] = $r['ram'] !== null ? round((float)$r['ram'], 1) : null;
            $result['hdd'][] = $r['hdd'] !== null ? round((float)$r['hdd'], 1) : null;
            $result['net'][] = $r['net'] !== null ? round((float)$r['net'], 1) : null;
        }

        // Průměry/maxima jen ze skutečně naměřených hodnot; bez jediné hodnoty
        // zůstává null a frontend vypíše pomlčku místo nuly.
        foreach (['cpu', 'ram', 'hdd', 'net'] as $mk) {
            $valid = array_filter($result[$mk], fn($v) => $v !== null);
            if (!empty($valid)) {
                $result["{$mk}_avg"] = round(array_sum($valid) / count($valid), 1);
                $result["{$mk}_max"] = max($valid);
            } else {
                $result["{$mk}_avg"] = null;
                $result["{$mk}_max"] = null;
            }
        }
    } catch (Exception $e) { /* empty */ }

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. Veřejný agregovaný přehled
if ($action === 'public_status') {
    try {
        $stmt_stats = $pdo->query("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
                SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count,
                MAX(last_checked) as last_checked
            FROM monitors
        ");
        $stats = $stmt_stats ? $stmt_stats->fetch() : null;
        $total_monitors = (int)($stats['total'] ?? 0);
        $down_monitors = (int)($stats['down_count'] ?? 0);

        // null (not 100.0) until real data says otherwise - a fresh install
        // or a dead cron with zero logged checks isn't "100% uptime", it's
        // unmeasured, and the frontend needs to tell those two apart.
        $avg_uptime = null;
        try {
            $stmt_upt = $pdo->query("
                SELECT monitor_id,
                       SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
                       SUM(CASE WHEN status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count
                FROM monitor_logs
                WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY monitor_id
            ");
            if ($stmt_upt) {
                $uptime_values = [];
                while ($row = $stmt_upt->fetch()) {
                    if ($row && !empty($row['total_count'])) {
                        $uptime_values[] = ($row['up_count'] / $row['total_count']) * 100;
                    }
                }
                if (!empty($uptime_values)) {
                    $avg_uptime = round(array_sum($uptime_values) / count($uptime_values), 3);
                }
            }
        } catch (Throwable $t) {}

        // response_time není sloupec monitors - bere se poslední hodnota z
        // monitor_logs. Beze jména/výpadku fallbacku: chybí-li reálné uzly,
        // vrací se prázdný seznam, ne natvrdo napsané "Donald"/"Router - Praha".
        $nodes = [];
        try {
            $stmt_nodes = $pdo->query("
                SELECT m.name, m.status,
                       (SELECT l.response_time FROM monitor_logs l WHERE l.monitor_id = m.id AND l.response_time IS NOT NULL ORDER BY l.id DESC LIMIT 1) AS response_time
                FROM monitors m
                WHERE LOWER(m.type) IN ('agent', 'vps', 'openwrt', 'teamspeak', 'node', 'router') OR m.last_details IS NOT NULL
            ");
            while ($nd = $stmt_nodes->fetch()) {
                $nodes[] = [
                    'name' => $nd['name'],
                    'status' => $nd['status'] === 'up' ? 'online' : ($nd['status'] === 'warning' ? 'warning' : 'offline'),
                    'latencyMs' => $nd['response_time'] !== null ? (int)$nd['response_time'] : null,
                ];
            }
        } catch (Throwable $t) {}

        // null until a real measurement exists - "10 ms" as a fabricated
        // default would misrepresent actual latency the same way the old
        // 100% uptime default misrepresented actual availability.
        $avg_latency = null;
        try {
            $stmt_latency = $pdo->query("
                SELECT AVG(response_time) as avg_latency
                FROM monitor_logs
                WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND response_time > 0
            ");
            $lat_row = $stmt_latency ? $stmt_latency->fetch() : null;
            if ($lat_row && isset($lat_row['avg_latency']) && $lat_row['avg_latency'] !== null) {
                $avg_latency = (int)round($lat_row['avg_latency']);
            }
        } catch (Throwable $t) {}

        $agents_online = count(array_filter($nodes, fn($n) => $n['status'] === 'online'));
        $agents_total = count($nodes);

        echo json_encode([
            'status' => $down_monitors > 0 ? 'degraded' : 'healthy',
            'uptimePercent' => $avg_uptime,
            'totalMonitors' => $total_monitors,
            'downMonitors' => $down_monitors,
            'agentsOnline' => $agents_online,
            'agentsTotal' => $agents_total,
            'avgLatencyMs' => $avg_latency,
            'lastUpdated' => (!empty($stats['last_checked'])) ? date('c', strtotime($stats['last_checked'])) : date('c'),
            'nodes' => $nodes,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Nikdy nevracet vymyšlený "healthy" stav při chybě - klient musí poznat,
        // že se stav infrastruktury nepodařilo zjistit, ne dostat falešné "vše OK".
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se zjistit stav infrastruktury.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 6. Výchozí JSON přehled služeb z DB
$response = [
    'teamspeak' => ['online' => false, 'clients_online' => null, 'clients_max' => null, 'name' => 'TeamSpeak Server'],
    'minecraft' => ['online' => false, 'players_online' => null, 'players_max' => null, 'version' => ''],
    'discord' => ['online' => false, 'online_count' => null, 'total_count' => null]
];

try {
    $stmt = $pdo->prepare("SELECT status, last_details, name FROM monitors WHERE LOWER(type) LIKE '%teamspeak%' OR LOWER(type) LIKE '%ts3%' OR LOWER(name) LIKE '%teamspeak%' LIMIT 1");
    $stmt->execute();
    $ts = $stmt->fetch();
    if ($ts) {
        $response['teamspeak']['online'] = ($ts['status'] === 'up');
        $response['teamspeak']['name'] = $ts['name'];
        $details = json_decode($ts['last_details'] ?? '', true);
        if ($details && isset($details['clients_online'])) {
            $response['teamspeak']['clients_online'] = (int)$details['clients_online'];
            // Neznámá kapacita zůstává null - "X / 100" s vymyšleným limitem neukazujeme.
            $response['teamspeak']['clients_max'] = isset($details['clients_max']) ? (int)$details['clients_max'] : null;
        }
    }

    $stmt = $pdo->prepare("SELECT status, last_details, name FROM monitors WHERE LOWER(type) LIKE '%minecraft%' OR LOWER(type) LIKE '%mc%' OR LOWER(name) LIKE '%minecraft%' LIMIT 1");
    $stmt->execute();
    $mc = $stmt->fetch();
    if ($mc) {
        $response['minecraft']['online'] = ($mc['status'] === 'up');
        $details = json_decode($mc['last_details'] ?? '', true);
        if ($details && isset($details['players_online'])) {
            $response['minecraft']['players_online'] = (int)$details['players_online'];
            $response['minecraft']['players_max'] = (int)($details['players_max'] ?? 20);
            $response['minecraft']['version'] = $details['version'] ?? '';
        }
    }
} catch (Exception $e) {}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
