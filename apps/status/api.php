<?php
/**
 * Blood Kings - Public Status & SPA API
 * 
 * Serves the live status of game servers, websites, nodes and users from the MySQL database.
 */

header('Content-Type: application/json; charset=utf-8');

$request_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'bloodkings.eu';
$default_origin = $request_scheme . '://' . $host;

// CORS: ANY Origin used to be reflected here, with Allow-Credentials: true -
// the confidentiality of every authenticated response then rested solely on
// the cookie's SameSite=Lax attribute. Only the site's own origin is allowed
// (the SPA and the legacy pages run on the same domain as the API); a foreign
// origin gets no headers at all and the browser withholds the response from it.
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$bk_host_no_port = strtolower(explode(':', (string)$host)[0]);
$bk_allowed_origins = [
    $default_origin,
    'https://' . $bk_host_no_port,
    'http://' . $bk_host_no_port,
];
if ($origin === '' || in_array($origin, $bk_allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : $default_origin));
    header('Access-Control-Allow-Credentials: true');
}
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

/**
 * Release the session lock for endpoints that only read from it.
 *
 * config.php calls session_start() on every request and PHP holds an exclusive
 * lock on the session file until the script ends. The browser opens several
 * API requests in parallel when a page loads, but they are served one after
 * another - each waiting for the previous to finish.
 *
 * Measured on production before this: `daily_uptime` takes 0.23 s on its own,
 * but 1.68 s when it runs alongside the other dashboard requests. The endpoint
 * was never slow; it was waiting in a queue.
 *
 * $_SESSION stays readable after this call - only writes stop being persisted.
 * That is why every action that logs in, logs out or otherwise changes the
 * session has to be listed here, or its changes would be silently thrown away.
 * Keeping that list correct is not left to memory - run_session_lock_lint.php
 * re-derives it from the code and fails the build when the two disagree.
 */
$bk_session_writers = ['login', 'logout', 'setup', 'totp_setup', 'totp_confirm'];
if (!in_array($action, $bk_session_writers, true) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/**
 * Write guard: every state-changing action must arrive as POST, and every
 * session-authenticated write must carry the session's CSRF token
 * (X-CSRF-Token header, csrf_token field for multipart forms).
 *
 * Until now the only cross-site protection was SameSite=Lax on the cookie -
 * a single attribute between "safe" and "any website may call admin actions".
 * And because a Lax cookie IS sent on top-level GET navigation, actions reading
 * parameters from $_GET (send_digest) could be fired by a plain link.
 */
$bk_post_only_actions = [
    // session-authenticated writes (these also require CSRF)
    'save_monitor', 'delete_monitor', 'import_discovered_service', 'upload_logo',
    'trigger_remote_action', 'convert_to_agent_check', 'save_settings',
    'generate_metrics_token', 'incident_action', 'create_incident',
    'save_preset', 'delete_preset', 'assign_preset',
    'update_profile', 'oauth_unlink', 'totp_setup', 'totp_confirm', 'totp_disable',
    'save_status_page', 'delete_status_page', 'send_digest', 'save_subscriptions',
    'save_annotation', 'save_user', 'delete_user',
    // these establish the session / authenticate by other means than the cookie - POST yes, CSRF no
    'login', 'logout', 'setup', 'forgot_password', 'set_password',
];
$bk_csrf_exempt = ['login', 'logout', 'setup', 'forgot_password', 'set_password'];
// alerts_read_state and dashboard_layout are GET read + POST write in one action.
$bk_csrf_on_post = array_merge(
    array_values(array_diff($bk_post_only_actions, $bk_csrf_exempt)),
    ['alerts_read_state', 'dashboard_layout']
);

if (in_array($action, $bk_post_only_actions, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Vyžadován POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, $bk_csrf_on_post, true)) {
    $bk_csrf_in = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    if ($bk_csrf_in === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $bk_csrf_in)) {
        http_response_code(403);
        echo json_encode(['error' => 'Chybí nebo nesouhlasí CSRF token. Obnovte stránku a zkuste akci znovu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 1. Session state check (login from admin.php / PHP session)
if ($action === 'session') {
    $is_logged_in = !empty($_SESSION['admin_logged_in']);
    $user = null;
    if ($is_logged_in) {
        // The e-mail used to be hardcoded as 'admin@bloodkings.eu' regardless
        // of who was logged in. Anyone else saw a stranger's address as their own.
        $u_email = null;
        $u_totp = null;
        try {
            $stmt_me = $pdo->prepare("SELECT email, totp_enabled FROM users WHERE id = ? LIMIT 1");
            $stmt_me->execute([(int)($_SESSION['admin_id'] ?? 0)]);
            $me_row = $stmt_me->fetch();
            if ($me_row) {
                $u_email = ($me_row['email'] ?? '') !== '' ? $me_row['email'] : null;
                $u_totp = !empty($me_row['totp_enabled']);
            }
        } catch (PDOException $e) {
            error_log('[api] session: e-mail uživatele se nepodařilo načíst: ' . $e->getMessage());
        }

        $user = [
            'id' => $_SESSION['admin_id'] ?? 1,
            'username' => $_SESSION['admin_username'] ?? 'admin',
            // NULL = address unknown; inventing one is worse than not showing it.
            'email' => $u_email,
            'role' => $_SESSION['admin_role'] ?? 'admin',
            // NULL = could not determine; false would claim "disabled".
            'totpEnabled' => $u_totp,
        ];
    }

    // The app reads `installed` to decide between offering first-account
    // creation and the login form. It was never sent before, so React stayed
    // on its default `true` and the setup wizard was unreachable.
    $has_users = null;
    try {
        $has_users = ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()) > 0;
    } catch (PDOException $e) {
        // Without the users table nothing can be said. `null` means "we do not
        // know" - the app handles that better than an invented `false`, which
        // would offer account creation on top of an unnamed database state.
        error_log('[api] session: stav instalace se nepodařilo zjistit: ' . $e->getMessage());
    }

    echo json_encode([
        'authenticated' => $is_logged_in,
        'user' => $user,
        'installed' => $has_users,
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

// 2. List of all monitors from the database
if ($action === 'monitors') {
    $is_admin = !empty($_SESSION['admin_logged_in']) && ($_SESSION['admin_role'] ?? '') === 'admin';
    $monitors = [];

    // The base list. response_time/cpu_usage/ram_usage/hdd_usage are NOT
    // columns of the monitors table (never were - confirmed live: "Unknown column
    // 'response_time'") - they are the latest measured values from monitor_logs
    // (availability checks) and vps_metrics (agent reports), attached via
    // subquery/join. This must NOT fail because of the extended fields below
    // (exactly that happened in production: one query for 20+ columns at once,
    // one schema mismatch = the whole monitor list gone, and the app with it).
    try {
        $stmt = $pdo->query("
            SELECT m.id, m.name, m.type, m.target, m.port, m.status, m.category, m.asset_id,
                   m.last_checked, m.last_status_change, m.last_details,
                   m.maintenance, m.maintenance_description, m.maintenance_start, m.maintenance_end,
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
            // Operational diagnostics (collection error messages, hints naming
            // config files) belong to the administrator, not the public
            // dashboard - the public response does not contain them at all.
            $details_out = $details;
            if (!$is_admin) {
                unset($details_out['cpanel_stats_error']);
                // The infrastructure's network identity (WAN addresses, gateway,
                // internal subnet, SSIDs, WireGuard endpoints, interface lists) is
                // a map for an attacker - the anonymous response carries only
                // aggregates (counts, percentages), no addresses. The old status page gates it the same way.
                foreach (['wan_ipv4', 'wan_ipv6', 'wan_gateway', 'wan_dns', 'lan_subnet', 'wifi_radios', 'wireguard_peers', 'interfaces', 'dns_servers', 'mwan3_policies', 'service_restarts', 'public_ip', 'asn', 'asn_name'] as $priv_key) {
                    unset($details_out[$priv_key]);
                }

                // The list above enumerates KNOWN keys, but agent_api nowadays
                // passes through keys the server has never heard of - the
                // enumeration would miss them and a new metric carrying an address
                // would leak to an anonymous visitor. Hence the extra name-based filter.
                //
                // The patterns target network identity and secrets. Aggregates
                // (counts, percentages, latency) deliberately do not match.
                foreach (array_keys($details_out) as $priv_key) {
                    if (preg_match('/(ipv4|ipv6|(^|_)ip($|_)|addr|gateway|subnet|ssid|(^|_)mac($|_)|endpoint|peer|serial|hostname|token|secret|passw|_key$|^key$)/i', (string)$priv_key)) {
                        unset($details_out[$priv_key]);
                    }
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
                // Time since the last status change (not a fixed value) - 0 until the first check runs.
                'uptimeSeconds' => ($last_change_ts && strtolower($r['status'] ?? '') === 'up') ? max(0, time() - $last_change_ts) : 0,
                // Announced maintenance is public by design - the legacy page
                // prints the description and window in a public banner. Only
                // while the flag is on; a stale description of a past window
                // stays private.
                'maintenance' => !empty($r['maintenance']),
                'maintenanceDescription' => !empty($r['maintenance']) ? ($r['maintenance_description'] ?: null) : null,
                'maintenanceStart' => (!empty($r['maintenance']) && $r['maintenance_start']) ? $r['maintenance_start'] : null,
                'maintenanceEnd' => (!empty($r['maintenance']) && $r['maintenance_end']) ? $r['maintenance_end'] : null,
                'agentLastSeen' => $details['agent_last_seen'] ?? null,
                'hostname' => $details['hostname'] ?? $r['target'],
                'os' => $details['os'] ?? $r['type'],
                'details' => $details_out,
                // Outages of data COLLECTION (not of the service) - the frontend MUST
                // show them, silently dropping data is forbidden (see bk_get_collection_issues).
                // Admin only: operational diagnostics, not public service status.
                'collectionIssues' => $is_admin ? bk_get_collection_issues($r, $details, $agent_offline_secs) : [],
            ];
        }
    } catch (Exception $e) {
        error_log('[api.php action=monitors] Base query failed: ' . $e->getMessage());
        echo json_encode(['monitors' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Configuration fields (may contain internals like the ServerQuery user,
    // webhook URLs, ...) are returned to a logged-in administrator only. A separate
    // query and separate try/catch - a missing/incompatible column here may cost
    // the admin these extended fields, never the base list.
    if ($is_admin && !empty($monitors)) {
        try {
            $stmt2 = $pdo->query("
                SELECT id, timeout, email_notifications, sms_notifications, notes, maintenance, maintenance_description,
                       maintenance_start, maintenance_end, monitored_processes, cpu_threshold, ram_threshold, hdd_threshold, preset_id,
                       latency_threshold_ms, latency_threshold_mins,
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
                $monitors[$mid]['presetId'] = $r['preset_id'] !== null ? (int)$r['preset_id'] : null;
                // null = upozornovani na zpomaleni je vypnute
                $monitors[$mid]['latencyThresholdMs'] = $r['latency_threshold_ms'] !== null ? (int)$r['latency_threshold_ms'] : null;
                $monitors[$mid]['latencyThresholdMins'] = (int)($r['latency_threshold_mins'] ?? 5);
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
                // Agent update offer: compares the version from the last report
                // with the agent file's version on the server. Only when both are known.
                // Protection against "forever offline" monitors: an active check
                // from the hosting against a private-network target never succeeds.
                // When the asset has an agent, offer converting to an agent-side
                // check instead of silently generating false outages.
                // CAREFUL: $r is a row from the extended-fields query, which does
                // NOT select asset_id/type/target - reading them from it was a silent
                // "undefined array key" and the condition never held, so the
                // unreachable-target warning never showed.
                // The base fields are already assembled above in $monitors[$mid].
                $base_row = $monitors[$mid];
                if (
                    ($base_row['assetId'] ?? null) !== null
                    && in_array(strtolower((string)($base_row['type'] ?? '')), ['web', 'port', 'minecraft', 'teamspeak', 'discord', 'dns'], true)
                ) {
                    if (bk_validate_import_target((string)($base_row['target'] ?? '')) !== null) {
                        $monitors[$mid]['unreachableTarget'] = true;
                    }
                }

                $agent_ver = $details['agent_version'] ?? null;
                $agent_type_key = $details['agent_type'] ?? null;
                if ($agent_ver && $agent_type_key && function_exists('bk_get_agent_latest_version')) {
                    $latest_agent = bk_get_agent_latest_version($agent_type_key);
                    if ($latest_agent !== null) {
                        // The agent FILE's version on the server - the single source of truth.
                        // The frontend used to compare against a hardcoded '3.13.8'
                        // (which is the TeamSpeak server version, not the agent's) with
                        // string '<', reporting "outdated" even for a freshly deployed agent.
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

// 2b. Insert or update a monitor directly in the MySQL database
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
    // Preset i prahy zpomaleni: prazdna hodnota znamena "nenastaveno" (NULL),
    // ne nulu. $preset_id se driv vubec neprirazoval, takze kazde ulozeni
    // monitoru jeho preset tise smazalo.
    $preset_id = isset($input['preset_id']) && $input['preset_id'] !== null && $input['preset_id'] !== ''
        ? (int)$input['preset_id']
        : null;
    $latency_threshold_ms = isset($input['latency_threshold_ms']) && $input['latency_threshold_ms'] !== null && $input['latency_threshold_ms'] !== ''
        ? max(1, (int)$input['latency_threshold_ms'])
        : null;
    $latency_threshold_mins = isset($input['latency_threshold_mins']) && $input['latency_threshold_mins'] !== ''
        ? max(1, min(1440, (int)$input['latency_threshold_mins']))
        : 5;
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

    // Heartbeat: interval je povinny, protoze bez nej neni podle ceho poznat
    // zpozdeni. Tolerance je volitelna (NULL = hlida se presne na interval).
    $heartbeat_interval = ($type === 'heartbeat' && isset($input['heartbeat_interval']) && $input['heartbeat_interval'] !== '')
        ? max(60, (int)$input['heartbeat_interval'])
        : null;
    $heartbeat_grace = ($type === 'heartbeat' && isset($input['heartbeat_grace']) && $input['heartbeat_grace'] !== '')
        ? max(0, (int)$input['heartbeat_grace'])
        : null;

    if ($type === 'heartbeat' && $heartbeat_interval === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Heartbeat monitor potřebuje interval - jak často se má úloha ozvat.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Heartbeat nema co kontrolovat aktivne, takze cil nevyzadujeme - uloha se
    // hlasi sama. Cil by u nej byl jen matouci prazdny formularovy radek.
    if (empty($name) || (empty($target) && !in_array($type, ['vps', 'openwrt', 'heartbeat'], true))) {
        http_response_code(400);
        echo json_encode(['error' => 'Název a cíl jsou povinné údaje.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($id > 0) {
            // Passwords (ServerQuery, RCON) are overwritten only when the administrator
            // typed a new value - an empty edit-form field must not erase a stored password.
            $stmt = $pdo->prepare("
                UPDATE monitors
                SET name = ?, type = ?, target = ?, port = ?, category = ?, timeout = ?, email_notifications = ?, sms_notifications = ?, notes = ?, maintenance = ?, monitored_processes = ?, maintenance_description = ?, maintenance_start = ?, maintenance_end = ?, cpanel_stats_url = ?, cpu_threshold = ?, ram_threshold = ?, hdd_threshold = ?, preset_id = ?, latency_threshold_ms = ?, latency_threshold_mins = ?, body_keyword = ?, sq_username = ?, sq_password = COALESCE(?, sq_password), ts3_filetransfer_port = ?, enabled_metrics = ?, rcon_port = ?, rcon_password = COALESCE(?, rcon_password), remote_actions_enabled = ?, allowed_actions = ?, asset_id = ?, heartbeat_interval = ?, heartbeat_grace = ?
                WHERE id = ?
            ");
            // Token se pri editaci zamerne neprepisuje: uloha uz ho ma zadraty
            // v curl prikazu na svem stroji a zmena by ji tise odstrihla.
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $preset_id, $latency_threshold_ms, $latency_threshold_mins, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id, $heartbeat_interval, $heartbeat_grace, $id]);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Monitor úspěšně upraven'], JSON_UNESCAPED_UNICODE);
        } else {
            $agent_key = bin2hex(random_bytes(16));
            if ($asset_id === null) {
                $stmt_auto_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
                $stmt_auto_asset->execute([$name]);
                $asset_id = (int)$pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("
                INSERT INTO monitors (name, type, target, port, category, timeout, email_notifications, sms_notifications, agent_key, status, notes, maintenance, monitored_processes, maintenance_description, maintenance_start, maintenance_end, cpanel_stats_url, cpu_threshold, ram_threshold, hdd_threshold, preset_id, latency_threshold_ms, latency_threshold_mins, body_keyword, sq_username, sq_password, ts3_filetransfer_port, enabled_metrics, rcon_port, rcon_password, remote_actions_enabled, allowed_actions, asset_id, heartbeat_interval, heartbeat_grace, heartbeat_token)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            // Token vznika jen u heartbeat monitoru - u ostatnich typu by to byl
            // jen nepouzitelny tajny retezec navic v databazi.
            $heartbeat_token = $type === 'heartbeat' ? bk_heartbeat_generate_token() : null;
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $agent_key, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $preset_id, $latency_threshold_ms, $latency_threshold_mins, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id, $heartbeat_interval, $heartbeat_grace, $heartbeat_token]);
            $new_id = (int)$pdo->lastInsertId();
            echo json_encode(['success' => true, 'id' => $new_id, 'message' => 'Monitor úspěšně vytvořen'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Heartbeat setup info - the address the job should report to.
 *
 * The token is all the endpoint authorises, so nobody gets here without
 * logging in and it is not returned in the regular monitor list either. If it
 * leaked, a stranger could send heartbeats on your behalf and the monitor
 * would stay green while the backup has long stopped - a silent failure, exactly the kind this
 * typ monitoru vznikl.
 */
if ($action === 'heartbeat_info') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $hb_id = (int)($_GET['monitor_id'] ?? 0);
    $regenerate = ($_GET['regenerate'] ?? '') === '1';

    try {
        $stmt = $pdo->prepare("SELECT id, name, type, heartbeat_token, heartbeat_interval, heartbeat_grace, last_heartbeat, heartbeat_last_result, heartbeat_last_message FROM monitors WHERE id = ? LIMIT 1");
        $stmt->execute([$hb_id]);
        $hb = $stmt->fetch();

        if (!$hb || $hb['type'] !== 'heartbeat') {
            http_response_code(404);
            echo json_encode(['error' => 'Heartbeat monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // The token is missing on monitors created before this type existed,
        // and on deliberate rotation. Both are handled the same - mint a new one.
        if ($regenerate || empty($hb['heartbeat_token'])) {
            $hb['heartbeat_token'] = bk_heartbeat_generate_token();
            $stmt_tok = $pdo->prepare("UPDATE monitors SET heartbeat_token = ? WHERE id = ?");
            $stmt_tok->execute([$hb['heartbeat_token'], $hb['id']]);
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/status/api.php');
        $url = $host !== '' ? sprintf('%s://%s%s/heartbeat.php?token=%s', $scheme, $host, rtrim($base, '/'), $hb['heartbeat_token']) : null;

        $eval = bk_heartbeat_evaluate($hb);

        echo json_encode([
            'monitorId' => (int)$hb['id'],
            'name' => $hb['name'],
            'token' => $hb['heartbeat_token'],
            // NULL when the address cannot be built (CLI context) - inventing a
            // domain would hand the administrator a URL that leads nowhere.
            'url' => $url,
            'intervalSecs' => $hb['heartbeat_interval'] !== null ? (int)$hb['heartbeat_interval'] : null,
            'graceSecs' => $hb['heartbeat_grace'] !== null ? (int)$hb['heartbeat_grace'] : null,
            'lastSignalAt' => $hb['last_heartbeat'],
            'lastResult' => $hb['heartbeat_last_result'],
            'lastMessage' => $hb['heartbeat_last_message'],
            'state' => $eval['status'],
            'stateReason' => $eval['error'],
            'ageSecs' => $eval['age_secs'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] heartbeat_info selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Údaje o heartbeatu se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2. Delete a monitor from the MySQL database
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

// 2b2b. List of discovered but not-yet-monitored services (Service Discovery).
// Agents have long stored this in monitors.last_details.discovered_services
// (agent_api.php) and admin.php can import it, but none of the front-end apps
// (apps/monitor React SPA) ever read it back - hence this endpoint.
if ($action === 'discovered_services') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        // Already-monitored services are not proposed again - otherwise an
        // imported service stayed in the panel as "unmonitored" (reported: kresd twice).
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

                // Target of the future check: the agent's address, else the hostname
                // from its report, else the source monitor's target. This used to
                // insert the monitor NAME ("Router - Praha"), which the import
                // then rightly rejected as an invalid address.
                $resolved_target = trim((string)($svc['target'] ?? ''));
                if ($resolved_target === '' || $resolved_target === '127.0.0.1' || $resolved_target === 'localhost') {
                    $resolved_target = trim((string)($details['hostname'] ?? ''));
                }
                if ($resolved_target === '') {
                    $resolved_target = trim((string)($row['target'] ?? ''));
                }
                // Pre-validation: a service that cannot be checked from the hosting
                // (private address, no address) is offered as an agent-side check
                // on agent monitors (vps/openwrt) - the agent verifies it locally.
                // Blocking remains only where there is no agent to take the
                // check over.
                $import_blocked = $resolved_target === ''
                    ? 'Agent nehlásí žádnou adresu, přes kterou by šla služba z hostingu testovat.'
                    : bk_validate_import_target($resolved_target);
                // Choosing the check mode:
                //  - active (from the hosting) only when the service has a publicly
                //    reachable target AND a type/port cron can test,
                //  - otherwise agent-side, when the source monitor IS an agent and the
                //    service has a process name or port (the agent verifies locally) -
                //    this includes portless daemons (Turris Sentinel etc.),
                //  - blocking remains only where no path is left.
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
        // Most confident proposals first - the admin usually wants those imported first.
        usort($services, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        echo json_encode(['services' => $services], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['services' => []], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2c. Import one discovered service as a new monitor (Service Discovery -
// the "propose -> confirm" step). Mirrors admin.php action_import_service,
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

    // 'agent' mode: a private-network service is checked locally by the agent
    // itself (process + port), the server only receives results. The monitor's
    // target is then the PROCESS NAME, not a network address - public target validation is skipped.
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

    // Cron can actively check only these types. Anything else (dns, smtp,
    // samba...) imports as a port check - otherwise the monitor just generated
    // unmeasurable 'unknown' records every minute (that is how kresd got
    // 0 % SLA despite never being tested by any check).
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
        // The discovering monitor runs on the same physical machine, so the new
        // monitor rovnou dostane jeho asset I kategorii - bez kategorie by
        // import ended up in the "Other" group, which confuses (reported by the user).
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
                // The check runs from the hosting, so the target must be the address
                // of the machine where the agent discovered the service - not localhost
                // and not the monitor name. Without a usable target, take the source
                // monitor's target, or the hostname from its last report.
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
        // The final target (whether from the agent's discovery payload or the
        // fallback above) is validated ALWAYS - an agent is a lower trust level
        // than an admin and checks run from the hosting, where private/internal
        // targets do not belong. Validation runs before creating the asset so a reject leaves no orphan.
        // Exception: an agent-side check has no public address (target = process).
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

// 2b2d. Custom logo upload (admin-only). Accepts only raster formats verified
// via getimagesize (magic bytes, not the extension) - SVG is rejected on
// purpose: it can carry scripts and opening the uploaded URL directly would
// run them on our domain. The file is stored under a fixed name in uploads/
// and the URL goes straight into the custom_logo_url setting.
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
        // Cache-bust via mtime so a logo swap is not hostage to the browser cache.
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

// 2b2e. Public appearance config for the React app - the same data index.php
// already renders publicly (title, logo, custom menu links).
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
        // For the public page footer - the © line links to the main portal,
        // same as the legacy footer.
        'portalUrl' => trim((string)get_setting('portal_url')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2b2f. Queue a Remote Action (admin-only) - the JSON counterpart of the
// admin.php form, for the Actions dropdown in the React device detail.
// The same double consent check: global type list + per-monitor
// allowed_actions; restart_service additionally requires a valid service name.
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

// 2b2g. Convert a monitor to an agent-side check (admin-only). Used for
// monitors whose target sits on a private network - an active check from the
// hosting never succeeds there and only generates false outages.
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
        // The asset must have an agent to take the check over.
        $stmt_ag = $pdo->prepare("SELECT COUNT(*) FROM monitors WHERE asset_id = ? AND type IN ('vps', 'openwrt')");
        $stmt_ag->execute([$cv_mon['asset_id']]);
        if ((int)$stmt_ag->fetchColumn() === 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Na tomto zařízení není agent, který by kontrolu převzal.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // History stays (only type and target change), the status resets to
        // 'unknown' - nothing is claimed until the agent's first result.
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

// 2b2h. Read-alert state (per user, not per browser).
// GET returns the last read monitor_logs.id, POST advances it.
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

// 2b2i. Catalogue of available dashboard tiles + the user's layout.
// Answers "what do we actually collect": the catalogue derives from REAL data
// (the metric map in vps_metrics + keys agents really sent), not
// a fixed list - a tile nobody ever measured anything for is not offered.

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
        // 1. What is actually measured: vps_metrics columns with at least
        //    one non-null value over the last 7 days.
        // The catalogue offers only metrics the dashboard can draw from data
        // the monitors endpoint really returns (cpu/ram/hdd on the monitor row).
        // Further metrics (temperature, load, wifi clients...) join once they
        // render - offering a switch without an implementation is a dead switch.
        $metric_defs = [
            'cpu' => ['col' => 'cpu_usage', 'label' => t('metric_label_cpu'), 'unit' => '%'],
            'ram' => ['col' => 'ram_usage', 'label' => t('metric_label_ram'), 'unit' => '%'],
            'hdd' => ['col' => 'hdd_usage', 'label' => t('metric_label_hdd'), 'unit' => '%'],
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

        // 2. Fixed dashboard panels (not metrics, but page sections).
        $panels = [
            'health' => t('tile_health'),
            'attention' => t('tile_attention'),
            'monitors' => t('tile_monitors'),
            'alerts' => t('tile_alerts'),
            'insights' => t('tile_insights'),
            'uptime_history' => t('tile_uptime_history'),
            'regions' => t('tile_regions'),
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
                // A tile for a metric nobody measures would only show dashes.
                'available' => $info['samples'] > 0,
                'samples' => $info['samples'],
            ];
        }

        // Dlazdice pro konkretni stroj: nabizi se jen agenti, kteri opravdu
        // posilaji metriky (jinak by slo zapnout kartu, ktera nikdy nic
        // neukaze). Klic nese id monitoru: metric_cpu_12.
        try {
            $stmt_a = $pdo->query("
                SELECT m.id, m.name,
                       (SELECT vm.cpu_usage FROM vps_metrics vm
                        WHERE vm.monitor_id = m.id ORDER BY vm.id DESC LIMIT 1) AS cpu_usage
                FROM monitors m
                WHERE LOWER(m.type) IN ('vps', 'openwrt')
                ORDER BY m.name
            ");
            foreach ($stmt_a->fetchAll() as $agent) {
                if ($agent['cpu_usage'] === null) {
                    continue;
                }
                foreach (['cpu' => t('metric_label_cpu'), 'ram' => t('metric_label_ram'), 'hdd' => t('metric_label_hdd')] as $mkey => $mlabel) {
                    $catalog[] = [
                        'key' => 'metric_' . $mkey . '_' . (int)$agent['id'],
                        'label' => $mlabel . ' — ' . $agent['name'],
                        'kind' => 'metric',
                        'available' => true,
                        'samples' => null,
                    ];
                }
            }
        } catch (Throwable $e) {
            // Bez per-stroj dlazdic se katalog jen zkrati.
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

// 2b3. Read the system settings (admin-only, masked passwords)
if ($action === 'get_settings') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // The list is shared with the write path and the legacy admin (db.php).
    // When read and write drift apart, the form shows an empty field and saves it.
    $all_keys = bk_settings_keys();
    $secret_keys = bk_settings_secret_keys();

    $settings = [];
    $env_locked = [];
    foreach ($all_keys as $key) {
        $val = get_setting($key, '');
        $is_env = is_setting_env_defined($key);
        if ($is_env) {
            $env_locked[] = $key;
        }
        // Mask passwords/tokens: empty stays empty, otherwise ••••••+last 4 chars
        if (in_array($key, $secret_keys, true) && $val !== '') {
            $suffix = mb_strlen($val) >= 4 ? mb_substr($val, -4) : $val;
            $val = '••••••' . $suffix;
        }
        $settings[$key] = $val;
    }

    echo json_encode(['settings' => $settings, 'envLocked' => $env_locked], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2b4. Save the system settings (admin-only)
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

    $allowed_keys = bk_settings_keys();
    $secret_keys = bk_settings_secret_keys();

    try {
        $pdo->beginTransaction();
        $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");

        foreach ($input['settings'] as $key => $val) {
            if (!in_array($key, $allowed_keys, true)) continue;
            if (is_setting_env_defined($key)) continue;

            $val = is_string($val) ? trim($val) : (string)$val;

            // If the user left a masked password untouched, skip it
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

// 2b5. Generate and activate the Prometheus token (admin-only)
if ($action === 'generate_metrics_token') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $new_token = bin2hex(random_bytes(16));
        // The settings table has key_name/key_value columns - the earlier
        // setting_key/setting_value crashed the INSERT here and the
        // "Activate token" button ended in a 500.
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('metrics_token', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
        $stmt->execute([$new_token]);

        echo json_encode(['success' => true, 'metricsToken' => $new_token], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Chyba při generování tokenu.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c. Recent event history from the DB (monitor_logs)
if ($action === 'events') {
    try {
        $monitor_id = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : 0;
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        // The last N rows COVER ONLY A FEW DOZEN MINUTES (cron writes every
        // minute per monitor), so outages older than the window were simply
        // absent and the UI claimed "all pass" while history had outages.
        // The fresh window therefore always gets the latest down/warning rows
        // mixed in regardless of age - the same principle the public status
        // page uses to fill its incident list (a filtered query, not a log tail).
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

        // Compute the outage duration: for down rows find the nearest up row after them
        foreach ($rows as $i => $r) {
            $outage_duration = null;
            $outage_end = null;
            if ($r['status'] === 'down') {
                // Search older records (lower index = newer) for the nearest up
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
                // An unknown check location stays null. A hardcoded location
                // (Frankfurt/RackNerd) used to be filled in here - for 37 of 40
                // events it was invented, because the column was empty.
                'location' => $r['checked_from'] ?: null,
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
// score, knowledge tips, forecast/anomaly/network insights, textual summary)
// has existed in PHP and served the public status page (index.php/monitor.php)
// for a long time - but the React SPA never called it and instead assembled
// its own generic template sentence on the client ("Monitor X (type) runs on target Y...").
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

        $enabled_metrics = bk_get_enabled_metrics($monitor, $pdo);
        $knowledge_tips = bk_get_knowledge_tips($monitor, $details, $check_stages, $status, $enabled_metrics, $pdo);
        $monitor_insights = array_merge(
            bk_get_forecast_insights($pdo, $monitor),
            bk_get_anomaly_insights($pdo, $monitor),
            bk_get_network_insights($pdo, $monitor, $details)
        );

        // The health score is currently computed only for TeamSpeak (build_teamspeak_health_areas) -
        // the same limitation as on the public status page, not an accident here.
        $health_score = null;
        if (strtolower($monitor['type'] ?? '') === 'teamspeak') {
            $health_areas = build_teamspeak_health_areas($monitor, $status, $check_stages, $details, $pdo);
            $health_score = bk_compute_health_score($health_areas);
        }

        $timeline = bk_get_monitor_timeline($pdo, $monitor_id, 30);
        // $pdo a $details navic: shrnuti si z nich dopocita, jak dlouho je
        // metrika nad prahem a ktery proces za tim stoji.
        $summary = bk_build_executive_summary(
            $monitor,
            $health_score,
            $knowledge_tips,
            $monitor_insights,
            array_slice($timeline, 0, 5),
            $pdo,
            is_array($details) ? $details : []
        );

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

// 2c1c. Aggregated insights for the dashboard (mockup: the "System Insights" row).
// Reuses the same per-monitor builders as monitor_insights - forecast
// (disk/RAM regression), latency anomalies and network insights - just
// collected across all monitors. An empty array is a legitimate answer: no
// invented "all good" cards are generated.
if ($action === 'dashboard_insights') {
    try {
        $limit = min(8, max(1, (int)($_GET['limit'] ?? 4)));

        // The analyses run over every monitor's history (regression, baselines,
        // rolling windows) - single-digit seconds on shared hosting, and the
        // dashboard waited for it on every load. The result is therefore
        // cached for 5 minutes; the data changes on the scale of minutes anyway.
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
        // More critical kinds first: network/anomalies before long-term trends.
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

// 2c1b. Daily availability breakdown for the last N days (dashboard heatmap) -
// real aggregates from monitor_logs, not a guess from the monitor's current state.
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
                   COUNT(*) AS total_count,
                   AVG(CASE WHEN response_time > 0 THEN response_time END) AS avg_rt
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

                // 'unknown' rows (types without an active check) are not measurements -
                // they do not count into availability at all, otherwise a monitor
                // nobody ever tested would show false outages.
                $up = $d ? (int)$d['up_count'] : 0;
                $down = $d ? (int)$d['down_count'] : 0;
                $warn = $d ? (int)$d['warning_count'] : 0;
                $maint = $d ? (int)$d['maint_count'] : 0;
                $measured = $up + $down + $warn;

                // The day's average response for the latency sparkline - null until
                // something actually answered that day (0 would claim instant responses).
                $avg_ms = ($d && $d['avg_rt'] !== null) ? (int)round((float)$d['avg_rt']) : null;

                if ($measured === 0 && $maint === 0) {
                    // A day without a single measured check has no 0% uptime - it has none.
                    $day_list[] = ['date' => $day_display, 'status' => 'paused', 'uptimePct' => null, 'avgMs' => $avg_ms, 'detail' => t('day_no_data')];
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

                $day_list[] = ['date' => $day_display, 'status' => $status, 'uptimePct' => $uptimePct, 'avgMs' => $avg_ms, 'detail' => $detail];
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

// Availability over several windows at once (24 h / 7 d / 30 d / 90 d) for the
// public page. One pass over 90 days of logs instead of four sla_report calls -
// those would also compute percentiles and last outages four times, which nobody here wants.
// A window without a single measured check is null, not 100.
if ($action === 'uptime_windows') {
    try {
        $uw_selects = [];
        foreach ([1, 7, 30, 90] as $w) {
            $uw_selects[] = "SUM(CASE WHEN checked_at >= DATE_SUB(NOW(), INTERVAL {$w} DAY) AND status = 'up' THEN 1 ELSE 0 END) AS up{$w}";
            $uw_selects[] = "SUM(CASE WHEN checked_at >= DATE_SUB(NOW(), INTERVAL {$w} DAY) AND status IN ('up','down','warning') THEN 1 ELSE 0 END) AS total{$w}";
        }
        $stmt_uw = $pdo->query("
            SELECT monitor_id, " . implode(', ', $uw_selects) . "
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            GROUP BY monitor_id
        ");
        $uw_out = [];
        foreach ($stmt_uw->fetchAll() as $r) {
            $row = [];
            foreach ([1, 7, 30, 90] as $w) {
                $total = (int)$r["total{$w}"];
                $row["d{$w}"] = $total > 0 ? round(((int)$r["up{$w}"] / $total) * 100, 2) : null;
            }
            $uw_out[(int)$r['monitor_id']] = $row;
        }
        // (object): an empty result and sequential ids must both stay an object,
        // the frontend indexes into it by monitor id.
        echo json_encode(['windows' => (object)$uw_out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Dostupnost se nepodařilo spočítat.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Embeddable SVG status badge - `<img src=".../api.php?action=badge&monitor_id=N">`
// on a foreign site. Without an id it summarises the fleet. Honesty applies
// here too: an unknown state is grey "unknown", not green "online", and a
// nonexistent monitor is 404, not an invented badge.
if ($action === 'badge') {
    $bdg_lang = ($_GET['lang'] ?? '') === 'en' ? 'en' : 'cs';
    $bdg_words = [
        'cs' => ['up' => 'online', 'down' => 'výpadek', 'warning' => 'zhoršeno', 'maintenance' => 'údržba', 'unknown' => 'neznámý', 'fleet_ok' => 'vše online', 'fleet_down' => 'výpadek'],
        'en' => ['up' => 'online', 'down' => 'outage', 'warning' => 'degraded', 'maintenance' => 'maintenance', 'unknown' => 'unknown', 'fleet_ok' => 'all online', 'fleet_down' => 'outage'],
    ][$bdg_lang];
    $bdg_colors = ['up' => '#3fb950', 'down' => '#f85149', 'warning' => '#d29922', 'maintenance' => '#d29922', 'unknown' => '#8b949e'];

    $bdg_mid = (int)($_GET['monitor_id'] ?? 0);
    try {
        if ($bdg_mid > 0) {
            $stmt_bdg = $pdo->prepare("SELECT name, status FROM monitors WHERE id = ? LIMIT 1");
            $stmt_bdg->execute([$bdg_mid]);
            $bdg_row = $stmt_bdg->fetch();
            if (!$bdg_row) {
                http_response_code(404);
                echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $bdg_label = (string)$bdg_row['name'];
            if (($_GET['type'] ?? '') === 'uptime') {
                // Uptime variant (from the legacy badge.php, now an alias of
                // this action): 30-day availability instead of the live state.
                // No measurements = grey "no data", never a made-up percent.
                $bdg_uptime = bk_uptime_30d($pdo, $bdg_mid);
                if ($bdg_uptime === null) {
                    $bdg_state = 'unknown';
                    $bdg_value = $bdg_lang === 'en' ? 'no data' : 'bez dat';
                } else {
                    $bdg_state = $bdg_uptime < 95.0 ? 'down' : ($bdg_uptime < 99.0 ? 'warning' : 'up');
                    $bdg_value = number_format($bdg_uptime, 2, '.', '') . ' %';
                }
            } else {
                $bdg_state = in_array($bdg_row['status'], ['up', 'down', 'warning', 'maintenance'], true) ? $bdg_row['status'] : 'unknown';
                $bdg_value = $bdg_words[$bdg_state];
            }
        } else {
            // Summary: down > maintenance > all online. An empty fleet is not "online".
            $stmt_bdg = $pdo->query("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) AS down_c,
                       SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maint_c
                FROM monitors
            ");
            $bdg_sum = $stmt_bdg->fetch() ?: ['total' => 0, 'down_c' => 0, 'maint_c' => 0];
            $bdg_label = trim((string)get_setting('site_title', 'status')) ?: 'status';
            if ((int)$bdg_sum['total'] === 0) {
                $bdg_state = 'unknown';
                $bdg_value = $bdg_words['unknown'];
            } elseif ((int)$bdg_sum['down_c'] > 0) {
                $bdg_state = 'down';
                $bdg_value = $bdg_words['fleet_down'] . ' (' . (int)$bdg_sum['down_c'] . ')';
            } elseif ((int)$bdg_sum['maint_c'] > 0) {
                $bdg_state = 'maintenance';
                $bdg_value = $bdg_words['maintenance'];
            } else {
                $bdg_state = 'up';
                $bdg_value = $bdg_words['fleet_ok'];
            }
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Stav se nepodařilo zjistit.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Width by estimate, ~6.2 px per character at 11px Verdana - the same trick
    // shields.io uses; pixel-perfect measurement is not needed here.
    $bdg_lw = (int)max(40, round(strlen($bdg_label) * 6.2) + 12);
    $bdg_vw = (int)max(40, round(mb_strlen($bdg_value) * 6.2) + 12);
    $bdg_w = $bdg_lw + $bdg_vw;
    $bdg_color = $bdg_colors[$bdg_state];
    $bdg_label_x = htmlspecialchars($bdg_label, ENT_QUOTES);
    $bdg_value_x = htmlspecialchars($bdg_value, ENT_QUOTES);
    // Centres of both fields for text-anchor="middle".
    $bdg_lw2 = (int)round($bdg_lw / 2);
    $bdg_vw2 = $bdg_lw + (int)round($bdg_vw / 2);

    header('Content-Type: image/svg+xml; charset=utf-8');
    // Short cache: a badge on a foreign site must not hammer the DB on every
    // view, but must not claim "online" about a dead server for an hour either.
    header('Cache-Control: public, max-age=60');
    echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$bdg_w}" height="20" role="img" aria-label="{$bdg_label_x}: {$bdg_value_x}">
  <linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
  <clipPath id="r"><rect width="{$bdg_w}" height="20" rx="3" fill="#fff"/></clipPath>
  <g clip-path="url(#r)">
    <rect width="{$bdg_lw}" height="20" fill="#555"/>
    <rect x="{$bdg_lw}" width="{$bdg_vw}" height="20" fill="{$bdg_color}"/>
    <rect width="{$bdg_w}" height="20" fill="url(#s)"/>
  </g>
  <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
    <text x="{$bdg_lw2}" y="14">{$bdg_label_x}</text>
    <text x="{$bdg_vw2}" y="14">{$bdg_value_x}</text>
  </g>
</svg>
SVG;
    exit;
}

// 2c2. Incidents and outages from the DB (incidents and monitor_logs)
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

        // Outages of TARGET monitors that are down RIGHT NOW - takes the latest
        // 'down' row per currently unavailable monitor, not every historical down
        // row (that would show long-resolved outages as still ongoing).
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

            // Open DB incidents by monitor - a live outage links to them so it
            // can be acknowledged/closed from the UI (the lifecycle creates them
            // automatically on the transition to down).
            $open_by_monitor = [];
            try {
                $stmt_open = $pdo->query("SELECT id, acknowledged_by, acknowledged_at FROM incidents WHERE status != 'resolved' AND monitor_id IS NOT NULL");
                foreach ($stmt_open->fetchAll() as $oi) {
                    $open_by_monitor[(int)$oi['monitor_id']] = $oi;
                }
            } catch (Throwable $t) {}

            foreach ($log_rows as $r) {
                $start_ts = strtotime($r['checked_at']);
                $open_inc = $open_by_monitor[(int)$r['monitor_id']] ?? null;
                $incidents[] = [
                    'id' => (int)$r['id'],
                    'incidentId' => $open_inc ? (int)$open_inc['id'] : null,
                    'acknowledgedBy' => $open_inc ? $open_inc['acknowledged_by'] : null,
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

        // Manually reported / global incidents (the `incidents` table - title/impact/status,
        // without a link to a specific monitor).
        $manual_incidents = [];
        try {
            $stmt_inc = $pdo->query("
                SELECT id, title, impact, status, created_at, updated_at, resolved_at,
                       monitor_id, acknowledged_by, acknowledged_at, postmortem
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
                    'monitorId' => $r['monitor_id'] !== null ? (int)$r['monitor_id'] : null,
                    'acknowledgedBy' => $r['acknowledged_by'],
                    'acknowledgedAt' => $r['acknowledged_at'] ? date('d.m.Y H:i:s', strtotime($r['acknowledged_at'])) : null,
                    'postmortem' => $r['postmortem'],
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

// 2c2x. Actions on an incident (admin-only): acknowledge, note/status change,
// resolve with a note, postmortem. Every step is written into
// incident_updates - the timeline stays complete no matter who did what.
if ($action === 'incident_action') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $incident_id = (int)($input['id'] ?? 0);
    $op = (string)($input['op'] ?? '');
    $username = $_SESSION['admin_username'] ?? 'admin';

    try {
        $stmt = $pdo->prepare("SELECT id, status FROM incidents WHERE id = ?");
        $stmt->execute([$incident_id]);
        $incident = $stmt->fetch();
        if (!$incident) {
            http_response_code(404);
            echo json_encode(['error' => 'Incident nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $add_update = function (string $status, string $message) use ($pdo, $incident_id) {
            $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, ?, ?)")
                ->execute([$incident_id, $status, $message]);
        };

        if ($op === 'ack') {
            $pdo->prepare("UPDATE incidents SET acknowledged_by = ?, acknowledged_at = NOW() WHERE id = ?")
                ->execute([$username, $incident_id]);
            $add_update((string)$incident['status'], "Incident převzal: {$username}");
            bk_audit_log($pdo, 'incident_ack', "Incident #{$incident_id} převzat", 'incident', $incident_id);
        } elseif ($op === 'note') {
            $message = trim((string)($input['message'] ?? ''));
            if ($message === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Poznámka nesmí být prázdná.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $status = in_array($input['status'] ?? '', ['investigating', 'identified', 'monitoring'], true)
                ? $input['status'] : (string)$incident['status'];
            $pdo->prepare("UPDATE incidents SET status = ? WHERE id = ?")->execute([$status, $incident_id]);
            $add_update($status, "[{$username}] " . $message);
            bk_audit_log($pdo, 'incident_note', "Incident #{$incident_id}: poznámka", 'incident', $incident_id);
        } elseif ($op === 'resolve') {
            $note = trim((string)($input['note'] ?? ''));
            $pdo->prepare("UPDATE incidents SET status = 'resolved', resolved_at = NOW() WHERE id = ?")
                ->execute([$incident_id]);
            $add_update('resolved', "[{$username}] " . ($note !== '' ? $note : 'Incident uzavřen ručně.'));
            bk_audit_log($pdo, 'incident_resolve', "Incident #{$incident_id} uzavřen", 'incident', $incident_id);
        } elseif ($op === 'postmortem') {
            $text = trim((string)($input['postmortem'] ?? ''));
            $pdo->prepare("UPDATE incidents SET postmortem = ? WHERE id = ?")
                ->execute([$text !== '' ? $text : null, $incident_id]);
            $add_update((string)$incident['status'], "[{$username}] Postmortem " . ($text !== '' ? 'doplněn.' : 'odstraněn.'));
            bk_audit_log($pdo, 'incident_postmortem', "Incident #{$incident_id}: postmortem", 'incident', $incident_id);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Neznámá operace.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Akci se nepodařilo provést.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2c3. Manual incident report (admin-only) - writes into `incidents` + the first
// message into `incident_updates`.
if ($action === 'create_incident') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
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

// 2d. SLA Report - real uptime and outage data from monitor_logs over the last 30 days
// 2b2j. SLA overview for the websites page: 7/30/365-day availability per monitor.
// sla_report can take 3.7 s (detailed outages, MTTR) - the websites page needs
// just the percentages, so they come from one windowed query cached for 10 minutes.
// 2b2k. Vantage point overview: where the checks really ran from.
// Groups monitor_logs by checked_from (nodes write their location via
// node_api.php, cron keeps the default). Returns only what is in the
// data - no map with invented dots across the world.
// 2b2l. Metric presets: named sets of "what shows and when it is a problem",
// assignable to monitors. Reading is public (the UI needs them to render),
// writing is for a logged-in admin only.
if ($action === 'presets') {
    try {
        $presets = [];
        $stmt = $pdo->query("SELECT id, name, description, service_type, metrics, cpu_threshold, ram_threshold, hdd_threshold FROM metric_presets ORDER BY name");
        foreach ($stmt->fetchAll() as $r) {
            $metrics = json_decode($r['metrics'] ?? '', true);
            $presets[] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'description' => $r['description'],
                'serviceType' => $r['service_type'],
                'metrics' => is_array($metrics) ? $metrics : [],
                // null = the preset does not govern the threshold and leaves it to the monitor
                'cpuThreshold' => $r['cpu_threshold'] !== null ? (int)$r['cpu_threshold'] : null,
                'ramThreshold' => $r['ram_threshold'] !== null ? (int)$r['ram_threshold'] : null,
                'hddThreshold' => $r['hdd_threshold'] !== null ? (int)$r['hdd_threshold'] : null,
            ];
        }

        // How many monitors use the preset - so a delete's impact is visible.
        $usage = [];
        try {
            $stmt_u = $pdo->query("SELECT preset_id, COUNT(*) AS c FROM monitors WHERE preset_id IS NOT NULL GROUP BY preset_id");
            foreach ($stmt_u->fetchAll() as $u) {
                $usage[(int)$u['preset_id']] = (int)$u['c'];
            }
        } catch (Throwable $e) {}
        foreach ($presets as &$p) {
            $p['usedBy'] = $usage[$p['id']] ?? 0;
        }
        unset($p);

        // Catalogue of available metrics per service type (source: the profiles).
        $catalog = [];
        foreach (get_service_profiles() as $type => $profile) {
            if (empty($profile['metrics'])) {
                continue;
            }
            $catalog[$type] = [
                'label' => $profile['label'],
                'metrics' => array_map(
                    fn($m) => ['key' => $m['key'], 'label' => $m['label'], 'recommended' => !empty($m['recommended'])],
                    $profile['metrics']
                ),
            ];
        }

        echo json_encode(['presets' => $presets, 'catalog' => $catalog], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Presety se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save_preset' || $action === 'delete_preset' || $action === 'assign_preset') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    try {
        if ($action === 'save_preset') {
            $name = trim((string)($input['name'] ?? ''));
            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'Název presetu nesmí být prázdný.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $metrics = [];
            foreach ((array)($input['metrics'] ?? []) as $mk) {
                $clean = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$mk));
                if ($clean !== '') {
                    $metrics[] = $clean;
                }
            }
            // An empty threshold = the preset does not govern it; zero is a valid
            // value, hence '' is distinguished from 0 and no default is substituted.
            $thr = function ($v) {
                if ($v === null || $v === '') {
                    return null;
                }
                return max(0, min(100, (int)$v));
            };
            $id = (int)($input['id'] ?? 0);
            $params = [
                $name,
                trim((string)($input['description'] ?? '')) ?: null,
                trim((string)($input['serviceType'] ?? '')) ?: null,
                json_encode(array_values(array_unique($metrics)), JSON_UNESCAPED_UNICODE),
                $thr($input['cpuThreshold'] ?? null),
                $thr($input['ramThreshold'] ?? null),
                $thr($input['hddThreshold'] ?? null),
            ];
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE metric_presets SET name = ?, description = ?, service_type = ?, metrics = ?, cpu_threshold = ?, ram_threshold = ?, hdd_threshold = ? WHERE id = ?");
                $stmt->execute(array_merge($params, [$id]));
                bk_audit_log($pdo, 'preset_updated', "Preset '{$name}' upraven", 'preset', $id);
            } else {
                $stmt = $pdo->prepare("INSERT INTO metric_presets (name, description, service_type, metrics, cpu_threshold, ram_threshold, hdd_threshold) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute($params);
                $id = (int)$pdo->lastInsertId();
                bk_audit_log($pdo, 'preset_created', "Preset '{$name}' vytvořen", 'preset', $id);
            }
            echo json_encode(['success' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete_preset') {
            $id = (int)($input['id'] ?? 0);
            // Monitors merely lose the preset and return to their own settings -
            // deleting a preset must not take anyone's monitoring down.
            $pdo->prepare("UPDATE monitors SET preset_id = NULL WHERE preset_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM metric_presets WHERE id = ?")->execute([$id]);
            bk_audit_log($pdo, 'preset_deleted', "Preset #{$id} smazán", 'preset', $id);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // assign_preset: assign to one or more monitors (0 = remove)
        $preset_id = (int)($input['presetId'] ?? 0);
        $monitor_ids = array_values(array_filter(array_map('intval', (array)($input['monitorIds'] ?? []))));
        if (empty($monitor_ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nebyl vybrán žádný monitor.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($monitor_ids), '?'));
        $stmt = $pdo->prepare("UPDATE monitors SET preset_id = ? WHERE id IN ({$placeholders})");
        $stmt->execute(array_merge([$preset_id > 0 ? $preset_id : null], $monitor_ids));
        bk_audit_log($pdo, 'preset_assigned', "Preset #{$preset_id} přiřazen " . count($monitor_ids) . " monitorům", 'preset', $preset_id);
        echo json_encode(['success' => true, 'updated' => count($monitor_ids)], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Operaci s presetem se nepodařilo provést.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2m. Breakdown of the last check (DNS -> TCP -> TLS -> HTTP).
//
// The data existed from the start, but was stored solely in monitor_logs and
// no API served it - so the app had no way to tell in which stage a site
// stalled. Returns the latest check that actually carries the breakdown.
if ($action === 'check_stages') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    if ($monitor_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT check_stages, checked_at, response_time, status
            FROM monitor_logs
            WHERE monitor_id = ? AND check_stages IS NOT NULL AND check_stages <> ''
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$monitor_id]);
        $row = $stmt->fetch();

        if (!$row) {
            // No breakdown yet - not an error, it just has not been measured.
            echo json_encode(['stages' => null], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stages = json_decode($row['check_stages'], true);
        echo json_encode([
            'stages' => is_array($stages) ? $stages : null,
            'checkedAt' => $row['checked_at'],
            'responseMs' => $row['response_time'] !== null ? (int)$row['response_time'] : null,
            'status' => $row['status'],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Rozpad kontroly se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2b2n. Public status pages: a custom monitor selection under a custom slug.
//
// Reading is public (the page is meant to be public), but hidden pages are
// admin-only and only the monitors the page really contains are returned.
// 2b2o. Export konfigurace (admin-only).
//
// Self-hosted nastroj na sdilenem hostingu: kdyz se ucet rusi nebo stehuje,
// mel by si clovek odnest, co si nastavil - monitory, presety, status
// stranky a nastaveni. Bez toho je jedina zaloha rucni vypis z phpMyAdminu.
//
// Zamerne se NEEXPORTUJI: hesla, tokeny, klice agentu ani namerena data.
// Tajemstvi v souboru ke stazeni je uniku na pockani; historie merenі je
// desitky MB a pro obnovu nastaveni k nicemu.
if ($action === 'export_config') {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $export = [
            'exportedAt' => date('c'),
            'schemaVersion' => defined('BK_SCHEMA_VERSION') ? BK_SCHEMA_VERSION : null,
            'monitors' => [],
            'presets' => [],
            'statusPages' => [],
            'settings' => [],
        ];

        $stmt = $pdo->query("
            SELECT name, type, target, port, category, timeout, notes,
                   email_notifications, sms_notifications,
                   monitored_processes, cpu_threshold, ram_threshold, hdd_threshold,
                   latency_threshold_ms, latency_threshold_mins,
                   body_keyword, cpanel_stats_url, enabled_metrics,
                   remote_actions_enabled, allowed_actions
            FROM monitors ORDER BY id
        ");
        $export['monitors'] = $stmt->fetchAll();

        try {
            $export['presets'] = $pdo->query("SELECT name, description, service_type, metrics, cpu_threshold, ram_threshold, hdd_threshold FROM metric_presets ORDER BY name")->fetchAll();
        } catch (Throwable $e) {
            // Stara DB bez tabulky presetu - export ostatniho ma stale smysl.
        }
        try {
            $export['statusPages'] = $pdo->query("SELECT title, slug, description, is_public, monitor_ids FROM status_pages ORDER BY title")->fetchAll();
        } catch (Throwable $e) {
        }

        // Nastaveni: vse krome tajemstvi. Radeji seznam zakazanych vzoru nez
        // vycet povolenych - novy klic s heslem by se jinak v exportu objevil
        // hned, jak ho nekdo prida.
        $secret_pattern = '/(pass|secret|token|key|hash|credential|webhook|_url$|dsn)/i';
        foreach ($pdo->query("SELECT key_name, key_value FROM settings ORDER BY key_name")->fetchAll() as $row) {
            $k = (string)$row['key_name'];
            if (preg_match($secret_pattern, $k) || str_ends_with($k, '_cache')) {
                continue;
            }
            $export['settings'][$k] = $row['key_value'];
        }

        bk_audit_log($pdo, 'config_exported', 'Export konfigurace stažen', 'system', null);

        $filename = 'bloodkings-config-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Export se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Vlastni profil prihlaseneho uzivatele - pro /app/profile.
//
// Zrcadli legacy handler change_password v admin.php: zmena hesla vyzaduje
// stavajici heslo, samotny profil ne. Cizi ucet tudy zmenit nejde - ID se
// bere VYHRADNE ze session.
if ($action === 'my_profile' || $action === 'update_profile' || $action === 'oauth_unlink') {
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mp_uid = (int)$_SESSION['admin_id'];

    if ($action === 'my_profile') {
        try {
            $stmt_mp = $pdo->prepare("SELECT username, email, phone, whatsapp_apikey, sms_notifications, whatsapp_notifications, email_lang, totp_enabled, oauth_provider FROM users WHERE id = ? LIMIT 1");
            $stmt_mp->execute([$mp_uid]);
            $mp = $stmt_mp->fetch();
            if (!$mp) {
                http_response_code(404);
                echo json_encode(['error' => 'Účet nenalezen.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode([
                'username' => $mp['username'],
                'email' => $mp['email'] ?: null,
                'phone' => $mp['phone'] ?: null,
                // Klic se vraci maskovany - je to credential pro CallMeBot.
                'whatsappApikeySet' => ($mp['whatsapp_apikey'] ?? '') !== '',
                'smsNotifications' => !empty($mp['sms_notifications']),
                'whatsappNotifications' => !empty($mp['whatsapp_notifications']),
                // NULL = ridit se globalnim nastavenim email_lang.
                'emailLang' => in_array($mp['email_lang'], ['cs', 'en'], true) ? $mp['email_lang'] : null,
                'totpEnabled' => !empty($mp['totp_enabled']),
                'oauthProvider' => $mp['oauth_provider'] ?: null,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[my_profile] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Profil se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Vyžadován POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $mp_input = json_decode(file_get_contents('php://input'), true) ?: [];

    try {
        $stmt_me = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt_me->execute([$mp_uid]);
        $mp_me = $stmt_me->fetch();
        if (!$mp_me) {
            http_response_code(404);
            echo json_encode(['error' => 'Účet nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'oauth_unlink') {
            // Odpojeni chce heslo - ukradena session nesmi tise odpojit
            // prihlasovani a prevzit ucet pres OAuth provider.
            if (!password_verify((string)($mp_input['password'] ?? ''), $mp_me['password_hash'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Nesprávné heslo - účet zůstává propojený.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pdo->prepare("UPDATE users SET oauth_provider = NULL, oauth_id = NULL WHERE id = ?")->execute([$mp_uid]);
            bk_audit_log($pdo, 'oauth_unlinked', '', 'user', $mp_uid);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // update_profile
        $mp_email = trim((string)($mp_input['email'] ?? ''));
        if ($mp_email === '') {
            http_response_code(400);
            echo json_encode(['error' => 'E-mail je povinný.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $mp_phone = trim((string)($mp_input['phone'] ?? ''));
        $mp_lang = in_array($mp_input['emailLang'] ?? null, ['cs', 'en'], true) ? $mp_input['emailLang'] : null;
        $mp_sms = !empty($mp_input['smsNotifications']) ? 1 : 0;
        $mp_wa = !empty($mp_input['whatsappNotifications']) ? 1 : 0;
        // Prazdny klic = beze zmeny (vraci se jen maskovany priznak, takze
        // formular original nezna a nesmi ho prepsat prazdnem).
        $mp_wa_key = trim((string)($mp_input['whatsappApikey'] ?? ''));
        $mp_wa_key_sql = $mp_wa_key !== '' ? $mp_wa_key : $mp_me['whatsapp_apikey'];

        $mp_new_pass = (string)($mp_input['newPassword'] ?? '');
        $mp_hash = $mp_me['password_hash'];
        if ($mp_new_pass !== '') {
            if (strlen($mp_new_pass) < 8) {
                http_response_code(400);
                echo json_encode(['error' => 'Heslo musí mít alespoň 8 znaků.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!password_verify((string)($mp_input['oldPassword'] ?? ''), $mp_me['password_hash'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Stávající heslo je nesprávné. Změna neproběhla.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $mp_hash = password_hash($mp_new_pass, PASSWORD_BCRYPT);
        }

        $pdo->prepare("UPDATE users SET email = ?, phone = ?, whatsapp_apikey = ?, sms_notifications = ?, whatsapp_notifications = ?, email_lang = ?, password_hash = ? WHERE id = ?")
            ->execute([$mp_email, $mp_phone ?: null, $mp_wa_key_sql, $mp_sms, $mp_wa, $mp_lang, $mp_hash, $mp_uid]);
        bk_audit_log($pdo, $mp_new_pass !== '' ? 'password_changed' : 'profile_updated', 'Vlastní profil', 'user', $mp_uid);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[update_profile] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Profil se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Nastaveni hesla z pozvanky / resetu - pro React stranku /app/set-password.
//
// Zrcadli bk_render_set_password_page z admin.php: token se hashuje, plati
// jen do expirace a spotrebuje se prvnim uspesnym nastavenim. Neplatny token
// dostane stejnou odpoved jako expirovany - z chyby se neda poznat, jestli
// token nekdy existoval.
if ($action === 'set_password') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Vyžadován POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sp_input = json_decode(file_get_contents('php://input'), true) ?: [];
    $sp_token = trim((string)($sp_input['token'] ?? ''));
    $sp_pass = (string)($sp_input['password'] ?? '');

    if ($sp_token === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí token.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($sp_pass) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Heslo musí mít alespoň 8 znaků.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $sp_hash = hash('sha256', $sp_token);
        $stmt_sp = $pdo->prepare("SELECT id, username FROM users WHERE password_reset_token_hash = ? AND password_reset_expires > NOW() LIMIT 1");
        $stmt_sp->execute([$sp_hash]);
        $sp_user = $stmt_sp->fetch();
        if (!$sp_user) {
            http_response_code(400);
            echo json_encode(['error' => 'Odkaz je neplatný nebo už vypršel. Požádejte o nový.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt_up = $pdo->prepare("UPDATE users SET password_hash = ?, password_reset_token_hash = NULL, password_reset_expires = NULL WHERE id = ?");
        $stmt_up->execute([password_hash($sp_pass, PASSWORD_BCRYPT), (int)$sp_user['id']]);
        bk_audit_log($pdo, 'password_set_via_link', '', 'user', (int)$sp_user['id'], (int)$sp_user['id'], $sp_user['username']);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[set_password] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Heslo se nepodařilo nastavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2FA pro prihlaseneho uzivatele. Stejny dvoukrokovy postup jako admin.php:
// secret zije jen v session, dokud uzivatel kodem nepotvrdi, ze se mu QR
// opravdu naskenoval - jinak by sel ucet zamknout neoverenym secretem.
if ($action === 'totp_setup' || $action === 'totp_confirm' || $action === 'totp_disable') {
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Vyžadován POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $totp_input = json_decode(file_get_contents('php://input'), true) ?: [];
    $totp_uid = (int)$_SESSION['admin_id'];

    try {
        if ($action === 'totp_setup') {
            $totp_secret = bk_totp_generate_secret();
            $_SESSION['totp_pending_secret'] = $totp_secret;
            $totp_issuer = rawurlencode(get_setting('site_title', 'Blood Kings'));
            $totp_account = rawurlencode((string)($_SESSION['admin_user'] ?? 'admin'));
            echo json_encode([
                'secret' => $totp_secret,
                'otpauthUri' => "otpauth://totp/{$totp_issuer}:{$totp_account}?secret={$totp_secret}&issuer={$totp_issuer}&algorithm=SHA1&digits=6&period=30",
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'totp_confirm') {
            $totp_pending = $_SESSION['totp_pending_secret'] ?? '';
            $totp_code = trim((string)($totp_input['code'] ?? ''));
            if ($totp_pending === '') {
                http_response_code(400);
                echo json_encode(['error' => '2FA nastavení vypršelo, začněte prosím znovu.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!bk_totp_verify_code($totp_pending, $totp_code)) {
                http_response_code(400);
                echo json_encode(['error' => 'Neplatný kód z autentikační aplikace.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $stmt_t = $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
            $stmt_t->execute([$totp_pending, $totp_uid]);
            unset($_SESSION['totp_pending_secret']);
            bk_audit_log($pdo, 'totp_enabled', '', 'user', $totp_uid);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // totp_disable: vypnuti chce aktualni heslo - ukradena session bez
        // znalosti hesla 2FA tise nevypne.
        $stmt_me = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt_me->execute([$totp_uid]);
        $totp_me = $stmt_me->fetch();
        if (!$totp_me || !password_verify((string)($totp_input['password'] ?? ''), $totp_me['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nesprávné heslo - 2FA zůstává zapnuté.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $stmt_t = $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
        $stmt_t->execute([$totp_uid]);
        bk_audit_log($pdo, 'totp_disabled', '', 'user', $totp_uid);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[totp] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Operace se nepodařila.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Jedna status stranka podle slugu - pro verejnou stranku v Reactu.
//
// Chovani kopiruje legacy index.php?page=: skryta stranka je pro anonyma
// k nerozeznani od neexistujici (404 v obou pripadech), aby se existence
// skrytych stranek nedala zjistit zkousenim adres.
if ($action === 'status_page') {
    $sp_slug = trim((string)($_GET['slug'] ?? ''));
    if ($sp_slug === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí slug.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt_sp = $pdo->prepare("SELECT title, description, is_public, monitor_ids, display_options FROM status_pages WHERE slug = ? LIMIT 1");
        $stmt_sp->execute([$sp_slug]);
        $sp_row = $stmt_sp->fetch();
        $sp_is_admin = !empty($_SESSION['admin_logged_in']);

        if (!$sp_row || ((int)$sp_row['is_public'] !== 1 && !$sp_is_admin)) {
            http_response_code(404);
            echo json_encode(['error' => 'Stránka nenalezena.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $sp_ids = json_decode($sp_row['monitor_ids'] ?? '', true);
        // The options always come back complete with defaults filled in - the
        // client then does not need to know what a missing key means.
        $sp_opts = json_decode($sp_row['display_options'] ?? '', true) ?: [];
        echo json_encode([
            'title' => $sp_row['title'],
            'description' => $sp_row['description'],
            // Prazdny vyber znamena "vsechny monitory" - stejne jako legacy.
            'monitorIds' => is_array($sp_ids) ? array_map('intval', $sp_ids) : [],
            'displayOptions' => [
                'showRegions' => $sp_opts['showRegions'] ?? true,
                'showEvents' => $sp_opts['showEvents'] ?? true,
                'showIncidents' => $sp_opts['showIncidents'] ?? true,
                'showUptime' => $sp_opts['showUptime'] ?? true,
                'detailLevel' => ($sp_opts['detailLevel'] ?? 'full') === 'status' ? 'status' : 'full',
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Bez tabulky (stara DB) se stranka tvari jako neexistujici.
        http_response_code(404);
        echo json_encode(['error' => 'Stránka nenalezena.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'status_pages') {
    $is_admin_sp = !empty($_SESSION['admin_logged_in']);
    try {
        $stmt = $pdo->query("SELECT id, title, slug, description, is_public, monitor_ids, display_options FROM status_pages ORDER BY title");
        $pages = [];
        foreach ($stmt->fetchAll() as $r) {
            $public = (int)$r['is_public'] === 1;
            if (!$public && !$is_admin_sp) {
                continue;
            }
            $ids = json_decode($r['monitor_ids'] ?? '', true);
            $pages[] = [
                'id' => (int)$r['id'],
                'title' => $r['title'],
                'slug' => $r['slug'],
                'description' => $r['description'],
                'isPublic' => $public,
                // An empty list = the page shows all monitors.
                'monitorIds' => is_array($ids) ? array_values(array_map('intval', $ids)) : [],
                'displayOptions' => (function () use ($r) {
                    $o = json_decode($r['display_options'] ?? '', true) ?: [];
                    return [
                        'showRegions' => $o['showRegions'] ?? true,
                        'showEvents' => $o['showEvents'] ?? true,
                        'showIncidents' => $o['showIncidents'] ?? true,
                        'showUptime' => $o['showUptime'] ?? true,
                        'detailLevel' => ($o['detailLevel'] ?? 'full') === 'status' ? 'status' : 'full',
                    ];
                })(),
            ];
        }
        echo json_encode(['pages' => $pages], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Status stránky se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'save_status_page' || $action === 'delete_status_page') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadováno přihlášení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    try {
        if ($action === 'delete_status_page') {
            $pdo->prepare("DELETE FROM status_pages WHERE id = ?")->execute([(int)($input['id'] ?? 0)]);
            bk_audit_log($pdo, 'status_page_deleted', 'Status stránka smazána', 'status_page', (int)($input['id'] ?? 0));
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Název stránky nesmí být prázdný.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // The slug goes into the URL, hence safe characters only. Derived from
        // the title when the user leaves it empty.
        $slug = strtolower(trim((string)($input['slug'] ?? '')));
        if ($slug === '') {
            $slug = $title;
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', bk_slug_ascii($slug));
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'stranka-' . time();
        }

        $ids = [];
        foreach ((array)($input['monitorIds'] ?? []) as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $ids[] = $mid;
            }
        }

        // Display options: known keys only, so arbitrary JSON cannot be stored
        // in the database. When everything is at its default, NULL is stored -
        // an unconfigured page and a "show everything" page are the same thing.
        $display = null;
        if (isset($input['displayOptions']) && is_array($input['displayOptions'])) {
            $opts = [];
            foreach (['showRegions', 'showEvents', 'showIncidents', 'showUptime'] as $flag) {
                if (array_key_exists($flag, $input['displayOptions']) && !$input['displayOptions'][$flag]) {
                    $opts[$flag] = false;
                }
            }
            $lvl = $input['displayOptions']['detailLevel'] ?? null;
            if ($lvl === 'status') {
                $opts['detailLevel'] = 'status';
            }
            $display = $opts ? json_encode($opts) : null;
        }

        $params = [
            $title,
            $slug,
            trim((string)($input['description'] ?? '')) ?: null,
            !empty($input['isPublic']) ? 1 : 0,
            json_encode(array_values(array_unique($ids))),
            $display,
        ];
        $id = (int)($input['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE status_pages SET title = ?, slug = ?, description = ?, is_public = ?, monitor_ids = ?, display_options = ? WHERE id = ?");
            $stmt->execute(array_merge($params, [$id]));
            bk_audit_log($pdo, 'status_page_updated', "Status stránka '{$title}' upravena", 'status_page', $id);
        } else {
            $stmt = $pdo->prepare("INSERT INTO status_pages (title, slug, description, is_public, monitor_ids, display_options) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute($params);
            $id = (int)$pdo->lastInsertId();
            bk_audit_log($pdo, 'status_page_created', "Status stránka '{$title}' vytvořena", 'status_page', $id);
        }
        echo json_encode(['success' => true, 'id' => $id, 'slug' => $slug], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        // A duplicate slug is the user's error, not the server's - say it clearly.
        $duplicate = str_contains($e->getMessage(), 'uniq_status_page_slug') || $e->getCode() === '23000';
        http_response_code($duplicate ? 400 : 500);
        echo json_encode([
            'error' => $duplicate
                ? 'Stránka s tímto slugem už existuje — zvolte jiný.'
                : 'Status stránku se nepodařilo uložit.',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Status stránku se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'regions') {
    try {
        $days = max(1, min(30, (int)($_GET['days'] ?? 7)));

        // Server-side cache, stejny vzorec jako websites_overview o kus niz.
        //
        // Agregace 30 dni monitor_logs bezi na tomhle hostingu ~5 s I S krycim
        // indexem - casovani skaluje linearne s oknem (0,45 s pro den, 5 s pro
        // mesic), takze index se pouziva a pomale je proste secteni ctvrt
        // milionu radku. Mista mereni se pritom meni jen kdyz pribude sonda
        // nebo lokalita Cloudflare - 10 minut stara odpoved je porad pravdiva,
        // a `cachedAt` to odpovedi priznava.
        $regions_cache_key = 'regions_cache_' . $days . 'd';
        $cache_raw = get_setting($regions_cache_key, '');
        if ($cache_raw !== '') {
            $cached = json_decode($cache_raw, true);
            if (is_array($cached) && isset($cached['at'], $cached['data']) && time() - (int)$cached['at'] < 600) {
                echo json_encode($cached['data'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $stmt = $pdo->prepare("
            SELECT checked_from,
                   COUNT(*) AS checks,
                   SUM(status = 'up') AS up_checks,
                   SUM(status = 'down') AS down_checks,
                   AVG(NULLIF(response_time, 0)) AS avg_response,
                   MIN(checked_at) AS first_seen,
                   MAX(checked_at) AS last_seen,
                   COUNT(DISTINCT monitor_id) AS monitors
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('up', 'down', 'warning')
            GROUP BY checked_from
            ORDER BY checks DESC
        ");
        $stmt->execute([$days]);

        $regions = [];
        foreach ($stmt->fetchAll() as $r) {
            $checks = (int)$r['checks'];
            $regions[] = [
                // null = the node does not report its location; the UI says so plainly.
                'location' => $r['checked_from'] !== null && $r['checked_from'] !== '' ? $r['checked_from'] : null,
                'checks' => $checks,
                'upChecks' => (int)$r['up_checks'],
                'downChecks' => (int)$r['down_checks'],
                'successRate' => $checks > 0 ? round(((int)$r['up_checks'] / $checks) * 100, 2) : null,
                // Average via NULLIF(...,0): a zero response is not a measurement.
                'avgResponseMs' => $r['avg_response'] !== null ? round((float)$r['avg_response']) : null,
                'monitors' => (int)$r['monitors'],
                'firstSeen' => $r['first_seen'],
                'lastSeen' => $r['last_seen'],
            ];
        }

        $regions_payload = ['days' => $days, 'regions' => $regions, 'cachedAt' => date('c')];
        try {
            $stmt_c = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt_c->execute([$regions_cache_key, json_encode(['at' => time(), 'data' => $regions_payload], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // Cache je optimalizace - kdyz se nezapise, odpoved stejne odejde.
        }
        echo json_encode($regions_payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Přehled měřicích míst se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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

        // Short windows from raw logs (exact, the data is there), long windows
        // from daily rollups - monitor_logs is pruned after 30 days, so the
        // yearly value used to come out identical to the 30-day one and claimed
        // a year of availability nobody measured.
        $stmt = $pdo->query("
            SELECT monitor_id,
                   SUM(status = 'up' AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS up7,
                   SUM(status IN ('up','down','warning') AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))   AS tot7,
                   SUM(status = 'up' AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  AS up30,
                   SUM(status IN ('up','down','warning') AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  AS tot30,
                   MIN(checked_at)                                                        AS measured_since
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND status IN ('up','down','warning')
            GROUP BY monitor_id
        ");
        $sla = [];
        $pct = function ($up, $tot) {
            // A window without a single measurement = null, not an invented 100 %.
            return (int)$tot > 0 ? round((int)$up / (int)$tot * 100, 3) : null;
        };
        while ($row = $stmt->fetch()) {
            $sla[(int)$row['monitor_id']] = [
                'sla7' => $pct($row['up7'], $row['tot7']),
                'sla30' => $pct($row['up30'], $row['tot30']),
                'sla365' => null,
                'measuredSince' => $row['measured_since'],
                'longTermDays' => 0,
            ];
        }

        // Long windows from uptime_daily. The real history length is returned
        // too, so the UI can say "over 47 days" instead of pretending a year.
        try {
            $stmt_long = $pdo->query("
                SELECT monitor_id,
                       SUM(checks_total) AS total,
                       SUM(checks_up) AS up_count,
                       MIN(day) AS since,
                       DATEDIFF(CURDATE(), MIN(day)) + 1 AS days_covered
                FROM uptime_daily
                WHERE day >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                GROUP BY monitor_id
            ");
            foreach ($stmt_long->fetchAll() as $lrow) {
                $mid = (int)$lrow['monitor_id'];
                if (!isset($sla[$mid])) {
                    $sla[$mid] = ['sla7' => null, 'sla30' => null, 'sla365' => null, 'measuredSince' => null, 'longTermDays' => 0];
                }
                $sla[$mid]['sla365'] = $pct($lrow['up_count'], $lrow['total']);
                $sla[$mid]['longTermDays'] = (int)$lrow['days_covered'];
                if (empty($sla[$mid]['measuredSince']) && !empty($lrow['since'])) {
                    $sla[$mid]['measuredSince'] = $lrow['since'];
                }
            }
        } catch (Throwable $e) {
            // Without the rollup table the long window stays null - visibly empty.
        }

        $data = [
            'slaGoal' => (float)get_setting('sla_goal_pct', '99.95'),
            'monitors' => $sla,
        ];
        try {
            $stmt2 = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('websites_overview_cache', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt2->execute([json_encode(['at' => time(), 'data' => $data], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // The cache is an optimisation - if it fails to store, the endpoint just computes more often.
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

        // Per-monitor uptime and outages
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

        // The loop here used to fire 3 queries PER monitor (last outage, its
        // end, percentiles) - N+1 in its purest form. Now three batched
        // queries before the loop; results live in maps keyed by monitor_id and
        // the values are bit-for-bit identical (pinned by integration tests).
        $sla_outage_by_mid = [];
        try {
            // The latest down row of every monitor + the nearest following up
            // (the correlated subquery runs only for monitors that ever went down).
            $stmt_out_all = $pdo->query("
                SELECT l.monitor_id, l.id, l.checked_at, l.error_message,
                       (SELECT u.checked_at FROM monitor_logs u
                        WHERE u.monitor_id = l.monitor_id AND u.status = 'up' AND u.id > l.id
                        ORDER BY u.id ASC LIMIT 1) AS next_up_at
                FROM monitor_logs l
                JOIN (SELECT monitor_id, MAX(id) AS max_id FROM monitor_logs WHERE status = 'down' GROUP BY monitor_id) ld
                  ON ld.max_id = l.id
            ");
            foreach ($stmt_out_all->fetchAll() as $orow) {
                $sla_outage_by_mid[(int)$orow['monitor_id']] = $orow;
            }
        } catch (Throwable $t) {}

        $sla_pct_by_mid = [];
        try {
            // Exact percentiles in one pass: ROW_NUMBER/COUNT per monitor and
            // only the rows at percentile positions leave the query. The position
            // formula matches the original PHP: idx = floor(p*(n-1)), rn = idx+1.
            $stmt_pct = $pdo->prepare("
                SELECT monitor_id, rn, cnt, response_time FROM (
                    SELECT monitor_id, response_time,
                           ROW_NUMBER() OVER (PARTITION BY monitor_id ORDER BY response_time) AS rn,
                           COUNT(*) OVER (PARTITION BY monitor_id) AS cnt
                    FROM monitor_logs
                    WHERE response_time IS NOT NULL AND response_time > 0
                          AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ) t
                WHERE rn = FLOOR(0.50 * (cnt - 1)) + 1
                   OR rn = FLOOR(0.95 * (cnt - 1)) + 1
                   OR rn = FLOOR(0.99 * (cnt - 1)) + 1
            ");
            $stmt_pct->execute([$days]);
            foreach ($stmt_pct->fetchAll() as $prow) {
                $p_mid = (int)$prow['monitor_id'];
                $p_rn = (int)$prow['rn'];
                $p_cnt = (int)$prow['cnt'];
                $p_val = (int)$prow['response_time'];
                // One row can occupy several positions at once (small n).
                foreach ([['p50', 0.50], ['p95', 0.95], ['p99', 0.99]] as [$pk, $pp]) {
                    if ($p_rn === (int)floor($pp * ($p_cnt - 1)) + 1) {
                        $sla_pct_by_mid[$p_mid][$pk] = $p_val;
                    }
                }
            }
        } catch (Throwable $t) {}

        $report = [];
        foreach ($monitors as $m) {
            $mid = (int)$m['id'];
            $non_maint = (int)$m['non_maint_checks'];
            $up = (int)$m['up_checks'];
            $down = (int)$m['down_checks'];
            // A monitor without a single measured check in the window has no SLA -
            // null, not a perfect 100.0 in a report nobody measured.
            $uptimePct = $non_maint > 0 ? round(($up / $non_maint) * 100, 3) : null;

            // The last outage from the batched map - the outage end is the nearest
            // following 'up' row, same as action=events.
            $last_outage = null;
            $out_row = $sla_outage_by_mid[$mid] ?? null;
            if ($out_row) {
                $out_start = strtotime($out_row['checked_at']);
                $resolved = $m['current_status'] !== 'down';
                $out_end_ts = time();
                if ($resolved) {
                    $out_end_ts = $out_row['next_up_at'] ? strtotime($out_row['next_up_at']) : $out_start;
                }
                $last_outage = [
                    'start' => date('d.m.Y H:i:s', $out_start),
                    'end' => $resolved ? date('d.m.Y H:i:s', $out_end_ts) : null,
                    'durationSec' => max(0, $out_end_ts - $out_start),
                    'reason' => $out_row['error_message'] ?: 'Port neodpovídá',
                    'resolved' => $resolved,
                ];
            }

            $outageMinutes = $down;
            $mttr = $last_outage['resolved'] ?? false ? $last_outage['durationSec'] : null;

            $p50 = $sla_pct_by_mid[$mid]['p50'] ?? null;
            $p95 = $sla_pct_by_mid[$mid]['p95'] ?? null;
            $p99 = $sla_pct_by_mid[$mid]['p99'] ?? null;

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
        // Average only over monitors with actually measured SLA; without a
        // single one the result is null, not an invented 100 %.
        $uptime_vals = array_filter(array_column($report, 'uptimePercent'), fn($v) => $v !== null);
        $overall_uptime = count($uptime_vals) > 0
            ? round(array_sum($uptime_vals) / count($uptime_vals), 3)
            : null;
        $total_outage = array_sum(array_column($report, 'outageMinutes'));
        $mttr_values = array_filter(array_column($report, 'mttrSec'), fn($v) => $v !== null);
        $overall_mttr = !empty($mttr_values) ? round(array_sum($mttr_values) / count($mttr_values)) : null;

        // The Prometheus token is a credential - it belongs in the response ONLY
        // for a logged-in administrator. sla_report is otherwise a public endpoint,
        // so without this condition anyone who opened /app/reports could read the
        // token (and metrics.php would genuinely answer them).
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
        // A DB error here used to return 100% uptime and 0 outage minutes -
        // a perfect SLA precisely when nothing is known about the real state.
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se sestavit SLA report.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2e. Audit logs from the database (settings changes, sign-ins, audit trail)
if ($action === 'audit_logs') {
    try {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        // The same problem as action=events: the log tail covers only a few
        // dozen minutes, older error rows fall out of the window and the trail
        // then lies that all is OK. The latest down/warning rows are therefore
        // always mixed in.
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

// 2g. Time series and metrics for charts (metric_series)
// Map of metric keys (see apps/monitor/src/api/types.ts MetricKey) to real
// columns in `vps_metrics`. 'response_time'/'latency' is special - it is
// measured on every check into monitor_logs, not on agent reports into vps_metrics.
// The definition lives in functions.php - cron needs it too for daily rollups.
$BK_METRIC_COLUMN_MAP = bk_metric_column_map();

if ($action === 'metric_series') {
    $monitor_id = (int)($_GET['monitor_id'] ?? $_GET['id'] ?? 1);
    $metric = $_GET['metric'] ?? 'response_time';
    $period = $_GET['period'] ?? '24h';
    $minutes = bk_period_minutes($period) ?? 1440;

    // Periods longer than the raw-data retention (30 days) read from the daily rollup
    // `metrics_daily`. 0 = the regular window over vps_metrics.
    $long_term_days = $period === '1y' ? 365 : ($period === '180d' ? 180 : ($period === '90d' ? 90 : 0));
    $daily_range = [];

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
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) AND response_time IS NOT NULL
                ORDER BY checked_at ASC
            ");
            $stmt->execute([$real_id, $minutes]);
            foreach ($stmt->fetchAll() as $r) {
                $points[] = [(int)$r['ts'], (float)$r['val']];
            }
        } elseif (isset($BK_METRIC_COLUMN_MAP[$metric])) {
            $def = $BK_METRIC_COLUMN_MAP[$metric];
            $col = $def['col'];
            $unit = $def['unit'];
            $label = $def['label'];
            // Cumulative counters (firewall, DNS, TCP retransmissions) are stored
            // as the kernel reports them - ever-growing. Drawing them directly
            // would give a rising ramp that tells nothing. The chart therefore
            // gets the DELTA between measurements.
            $is_counter = !empty($def['counter']);
            if ($is_counter) {
                $label .= ' (přírůstek)';
            }

            if ($long_term_days > 0) {
                // Raw data is pruned after 30 days, so a year cannot be built from
                // it. A point is the daily AVERAGE; min/max ride along so spikes
                // the average would hide stay visible.
                $stmt = $pdo->prepare("
                    SELECT UNIX_TIMESTAMP(day) as ts, avg_val, min_val, max_val, samples
                    FROM metrics_daily
                    WHERE monitor_id = ? AND metric_key = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                    ORDER BY day ASC
                ");
                $stmt->execute([$real_id, $metric, $long_term_days]);
                foreach ($stmt->fetchAll() as $r) {
                    if ($r['avg_val'] === null) {
                        continue;
                    }
                    if ($is_counter) {
                        // For a counter the daily increment is the difference between the
                        // day's max and min. The average of a counter means nothing.
                        if ($r['max_val'] === null || $r['min_val'] === null) {
                            continue;
                        }
                        $points[] = [(int)$r['ts'], round((float)$r['max_val'] - (float)$r['min_val'], 2)];
                        continue;
                    }
                    $points[] = [(int)$r['ts'], round((float)$r['avg_val'], 2)];
                    $daily_range[] = [
                        'ts' => (int)$r['ts'],
                        'min' => $r['min_val'] !== null ? round((float)$r['min_val'], 2) : null,
                        'max' => $r['max_val'] !== null ? round((float)$r['max_val'], 2) : null,
                        'samples' => (int)$r['samples'],
                    ];
                }
            } else {
                $stmt = $pdo->prepare("
                    SELECT UNIX_TIMESTAMP(checked_at) as ts, {$col} as val
                    FROM vps_metrics
                    WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) AND {$col} IS NOT NULL
                    ORDER BY checked_at ASC
                ");
                $stmt->execute([$real_id, $minutes]);

                if ($is_counter) {
                    // The delta against the previous measurement. When the value drops,
                    // the counter was reset (reboot, firewall restart) - the point is
                    // skipped. Computing it from zero would fabricate a spike that
                    // never happened.
                    $prev = null;
                    foreach ($stmt->fetchAll() as $r) {
                        $val = (float)$r['val'];
                        if ($prev !== null && $val >= $prev) {
                            $points[] = [(int)$r['ts'], round($val - $prev, 2)];
                        }
                        $prev = $val;
                    }
                } else {
                    foreach ($stmt->fetchAll() as $r) {
                        $points[] = [(int)$r['ts'], (float)$r['val']];
                    }
                }
            }
        } else {
            echo json_encode(['points' => [], 'unit' => '', 'label' => 'Metrika', 'error' => 'Neznámá metrika'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // No fabrication: an empty series means the agent has not sent this
        // metric yet or the period has no records - not that we make one up.
        $series_payload = ['unit' => $unit, 'label' => $label, 'points' => $points];
        if ($long_term_days > 0) {
            // The client must be able to tell it is looking at daily averages,
            // not individual measurements - otherwise it would read precision into the chart the data does not have.
            $series_payload['resolution'] = 'daily';
            $series_payload['dailyRange'] = $daily_range;
        }
        echo json_encode($series_payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['points' => [], 'unit' => '', 'label' => 'Metrika', 'error' => 'Chyba při načítání metriky'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2g1b. Context for the metric detail page (Level 3).
//
// The chart data itself comes from `metric_series` - this endpoint supplies
// the rest: what the metric means, which thresholds this monitor has, which
// related metrics it actually reports and what happened around it. Statistics
// (current/average/peak) are deliberately NOT sent: the client computes them
// from the very points it draws, so after switching the period they cannot
// describe a different window than the chart.
if ($action === 'metric_detail') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $metric = (string)($_GET['metric'] ?? '');
    $map = bk_metric_column_map();

    if (!isset($map[$metric]) && $metric !== 'response_time' && $metric !== 'latency') {
        http_response_code(404);
        echo json_encode(['error' => 'Neznámá metrika.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, type, asset_id, preset_id, cpu_threshold, ram_threshold, hdd_threshold
            FROM monitors WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$monitor_id]);
        $mon = $stmt->fetch();

        if (!$mon) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $is_latency = ($metric === 'response_time' || $metric === 'latency');
        $def = $is_latency
            ? ['label' => 'Doba odezvy (HTTP/Ping)', 'unit' => 'ms', 'counter' => false]
            : $map[$metric];

        // Related metrics: only those this monitor actually reports in its latest
        // measurement. Offering a link into an empty chart is worse than nothing.
        $related = [];
        $mon_type = strtolower((string)$mon['type']);
        try {
            $stmt_latest = $pdo->prepare("SELECT * FROM vps_metrics WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 1");
            $stmt_latest->execute([(int)$mon['id']]);
            $latest = $stmt_latest->fetch();
            if ($latest) {
                foreach ($map as $rkey => $rdef) {
                    if ($rkey === $metric) continue;
                    if (!empty($rdef['only']) && !in_array($mon_type, $rdef['only'], true)) continue;
                    $col = $rdef['col'];
                    if (!array_key_exists($col, $latest) || $latest[$col] === null) continue;
                    $related[] = [
                        'key' => $rkey,
                        'label' => $rdef['label'],
                        'unit' => $rdef['unit'],
                        'latest' => (float)$latest[$col],
                    ];
                }
            }
        } catch (PDOException $e) {
            // A missing column on an older database does not mean the page cannot
            // render - only that related metrics are not offered.
            error_log('[metric_detail] Příbuzné metriky selhaly: ' . $e->getMessage());
        }

        // Thresholds exist only for metrics that can be watched. Elsewhere a band
        // in the chart would pretend a limit nobody ever set.
        // Preset > monitor - the same order as the alerts in agent_api; the chart
        // bands must draw the same limit that actually alerts.
        $threshold_key = ['cpu' => 'cpu', 'ram' => 'ram', 'hdd' => 'hdd'][$metric] ?? null;
        $eff_detail = bk_monitor_thresholds($pdo, $mon);
        $critical = ($threshold_key !== null && $eff_detail[$threshold_key] !== null && (float)$eff_detail[$threshold_key] > 0)
            ? (float)$eff_detail[$threshold_key]
            : null;

        // Events for the chart markers. Same source as the Timeline, so the chart
        // and the timeline never show a different history.
        $events = [];
        foreach (bk_get_monitor_timeline($pdo, (int)$mon['id'], 30) as $ev) {
            $ts = strtotime((string)$ev['ts']);
            if ($ts === false) continue;
            $events[] = [
                't' => $ts * 1000,
                'type' => $ev['event_type'],
                'label' => (string)($ev['description'] ?: $ev['event_type']),
            ];
        }

        echo json_encode([
            'monitor' => [
                'id' => (int)$mon['id'],
                'name' => $mon['name'],
                'type' => $mon['type'],
                'assetId' => $mon['asset_id'] !== null ? (int)$mon['asset_id'] : null,
            ],
            'metric' => [
                'key' => $metric,
                'label' => $def['label'],
                'unit' => $def['unit'],
                'counter' => !empty($def['counter']),
            ],
            'thresholds' => [
                // The warning band sits 15 points below the critical limit - same
                // as the legacy page, so both show the same thing.
                'warning' => $critical !== null ? max(0.0, $critical - 15) : null,
                'critical' => $critical,
            ],
            'related' => $related,
            'events' => $events,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[metric_detail] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se načíst kontext metriky.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2g1c. What was running on the machine in a given time window.
//
// Answers the question the chart alone cannot: I can see a CPU spike at 19:40,
// but what caused it? Read exclusively from here, on demand - no page issues
// this query on load, because process_samples is the largest table in the database.
// The covering index handles it regardless (measured over 1.7 million rows:
// 0.089 ms, 60 rows examined), but running it on every dashboard view would
// mean paying for data nobody is looking at.
if ($action === 'process_history') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $kind = ($_GET['kind'] ?? 'cpu') === 'ram' ? 'ram' : 'cpu';
    // Window centre in UNIX seconds (the point the user clicked in the chart)
    // and the radius in minutes.
    $around = (int)($_GET['at'] ?? 0);
    $radius = min(180, max(1, (int)($_GET['radius'] ?? 10)));

    if ($monitor_id <= 0 || $around <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_id nebo čas.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // The window is computed in SQL, not in PHP.
        //
        // `sampled_at` is written by agent_api.php via NOW(), i.e. in the database
        // zone, while PHP here runs in the application zone - two hours apart on
        // the test environment. If the bounds were built with date(), the query
        // would ask about a different two hours than where the data lies, and the
        // panel would forever report "nothing for this moment". FROM_UNIXTIME
        // shares the zone with NOW(), so both sides speak about the same moment.
        $stmt = $pdo->prepare(
            "SELECT sampled_at, name, pid, cpu_pct, ram_mb, kept_reason
               FROM process_samples
              WHERE monitor_id = ? AND kind = ?
                AND sampled_at BETWEEN DATE_SUB(FROM_UNIXTIME(?), INTERVAL ? MINUTE)
                                   AND DATE_ADD(FROM_UNIXTIME(?), INTERVAL ? MINUTE)
              ORDER BY " . ($kind === 'ram' ? 'ram_mb' : 'cpu_pct') . " DESC
              LIMIT 60"
        );
        $stmt->execute([$monitor_id, $kind, $around, $radius, $around, $radius]);
        $rows = $stmt->fetchAll();

        // The window bounds are reported by the database, so the UI displays the
        // same time the search really used.
        $stmt_win = $pdo->prepare(
            "SELECT DATE_SUB(FROM_UNIXTIME(?), INTERVAL ? MINUTE) AS win_from,
                    DATE_ADD(FROM_UNIXTIME(?), INTERVAL ? MINUTE) AS win_to"
        );
        $stmt_win->execute([$around, $radius, $around, $radius]);
        $win = $stmt_win->fetch() ?: [];
        $from = $win['win_from'] ?? null;
        $to = $win['win_to'] ?? null;

        $samples = [];
        $pruned = false;
        foreach ($rows as $r) {
            if (($r['kept_reason'] ?? '') === 'peak') {
                $pruned = true;
            }
            $samples[] = [
                'at' => $r['sampled_at'],
                'name' => $r['name'],
                'pid' => $r['pid'] !== null ? (int)$r['pid'] : null,
                // An unmeasured dimension stays null - the table will show a dash.
                'cpuPct' => $r['cpu_pct'] !== null ? round((float)$r['cpu_pct'], 1) : null,
                'ramMb' => $r['ram_mb'] !== null ? round((float)$r['ram_mb'], 1) : null,
            ];
        }

        echo json_encode([
            'samples' => $samples,
            'from' => $from,
            'to' => $to,
            // An empty result has two different causes and the client must tell
            // them apart: either history is not enabled, or the window really had nothing.
            'enabled' => (int)get_setting('process_history_days', '30') > 0,
            // A thinned window keeps only the peaks - "nothing here" then means
            // "things ran, just nothing significant", not "nothing ran".
            'pruned' => $pruned,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[process_history] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se načíst historii procesů.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 2g2. Batched load of all device charts in one DB query (instead of 9 separate
// metric_series requests) - a much faster device-detail page load.
if ($action === 'metric_series_batch') {
    $monitor_id = (int)($_GET['monitor_id'] ?? $_GET['id'] ?? 1);
    $period = $_GET['period'] ?? '24h';
    $minutes = bk_period_minutes($period) ?? 1440;

    try {
        $stmt_mon = $pdo->prepare("SELECT id, type FROM monitors WHERE id = ? OR asset_id = ? LIMIT 1");
        $stmt_mon->execute([$monitor_id, $monitor_id]);
        $mon_row = $stmt_mon->fetch();
        $real_id = $mon_row['id'] ?? null;
        $mon_type = strtolower((string)($mon_row['type'] ?? ''));

        if (!$real_id) {
            echo json_encode(['series' => [], 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $series = [];

        // Latency - from monitor_logs (one row per check)
        $stmt_lat = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(checked_at) as ts, response_time as val
            FROM monitor_logs
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) AND response_time IS NOT NULL
            ORDER BY checked_at ASC
        ");
        $stmt_lat->execute([$real_id, $minutes]);
        $lat_points = [];
        foreach ($stmt_lat->fetchAll() as $r) {
            $lat_points[] = [(int)$r['ts'], (float)$r['val']];
        }
        $series['response_time'] = ['unit' => 'ms', 'label' => 'Doba odezvy (HTTP/Ping)', 'points' => $lat_points];

        // All agent metrics - from vps_metrics (one row per agent report), in one query.
        $cols = array_column($BK_METRIC_COLUMN_MAP, 'col');
        $col_list = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $stmt_vm = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(checked_at) as ts, {$col_list}
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY checked_at ASC
        ");
        $stmt_vm->execute([$real_id, $minutes]);
        $vm_rows = $stmt_vm->fetchAll();

        foreach ($BK_METRIC_COLUMN_MAP as $metric_key => $def) {
            // A series restricted to another monitor type is not offered at all.
            if (!empty($def['only']) && !in_array($mon_type, $def['only'], true)) {
                continue;
            }
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

// 2f. Manual digest send (admin-only)
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

// 2f. Notification subscriptions of the current user
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

// 2g. Save the notification subscriptions
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


// 3. User list from the database (requires login)
// User management for the React app (/app/users). Until 2026-08-17 these
// actions DID NOT EXIST: appApi posted save_user/delete_user, the unknown
// action fell through to the default response with HTTP 200 and no error key -
// and the UI reported "saved" while nothing happened at all. Same logic as the admin.php handlers.
if ($action === 'save_user' || $action === 'delete_user') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $su_input = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($action === 'delete_user') {
        $du_id = (int)($su_input['id'] ?? 0);
        if ($du_id === (int)($_SESSION['admin_id'] ?? 0)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nemůžete smazat svůj vlastní přihlášený účet.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $stmt_du = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt_du->execute([$du_id]);
            $du_username = $stmt_du->fetchColumn();
            if ($du_username === false) {
                http_response_code(404);
                echo json_encode(['error' => 'Uživatel nenalezen.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$du_id]);
            bk_audit_log($pdo, 'user_deleted', (string)$du_username, 'user', $du_id);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            error_log('[delete_user] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Uživatele se nepodařilo smazat.'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // save_user
    $su_id = (int)($su_input['id'] ?? 0);
    $su_username = trim((string)($su_input['username'] ?? ''));
    $su_email = trim((string)($su_input['email'] ?? ''));
    $su_phone = trim((string)($su_input['phone'] ?? ''));
    $su_role = ($su_input['role'] ?? '') === 'admin' ? 'admin' : 'user';
    $su_password = (string)($su_input['password'] ?? '');

    if ($su_username === '' || $su_email === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Uživatelské jméno a e-mail jsou povinné.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($su_password !== '' && strlen($su_password) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Heslo musí mít alespoň 8 znaků.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        if ($su_id > 0) {
            // Old values for the audit - silently changing someone else's e-mail
            // is a takeover vector, the audit must say what exactly changed.
            $stmt_old = $pdo->prepare("SELECT username, email, phone, role FROM users WHERE id = ?");
            $stmt_old->execute([$su_id]);
            $su_old = $stmt_old->fetch();
            if (!$su_old) {
                http_response_code(404);
                echo json_encode(['error' => 'Uživatel nenalezen.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($su_password !== '') {
                $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ?, password_hash = ? WHERE id = ?")
                    ->execute([$su_username, $su_email, $su_phone, $su_role, password_hash($su_password, PASSWORD_BCRYPT), $su_id]);
            } else {
                $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ? WHERE id = ?")
                    ->execute([$su_username, $su_email, $su_phone, $su_role, $su_id]);
            }
            $su_changes = [];
            if ($su_old['username'] !== $su_username) $su_changes[] = "jméno {$su_old['username']} -> {$su_username}";
            if ($su_old['email'] !== $su_email) $su_changes[] = "e-mail {$su_old['email']} -> {$su_email}";
            if ((string)$su_old['phone'] !== $su_phone) $su_changes[] = 'telefon změněn';
            if ($su_old['role'] !== $su_role) $su_changes[] = "role {$su_old['role']} -> {$su_role}";
            if ($su_password !== '') $su_changes[] = 'heslo nastaveno adminem';
            bk_audit_log($pdo, 'user_updated', $su_username . (!empty($su_changes) ? ' (' . implode(', ', $su_changes) . ')' : ' (beze změny)'), 'user', $su_id);
            echo json_encode(['success' => true, 'id' => $su_id], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($su_password !== '') {
            // The admin typed the password by hand - no invitation.
            $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)")
                ->execute([$su_username, $su_email, $su_phone, $su_role, password_hash($su_password, PASSWORD_BCRYPT)]);
            $su_new_id = (int)$pdo->lastInsertId();
            bk_audit_log($pdo, 'user_created', $su_username . ' (' . $su_role . ', heslo nastaveno adminem)', 'user', $su_new_id);
            echo json_encode(['success' => true, 'id' => $su_new_id, 'invited' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Without a password: a placeholder hash matching no plaintext, and an
        // invitation link - the admin never knows the user's password.
        $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)")
            ->execute([$su_username, $su_email, $su_phone, $su_role, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)]);
        $su_new_id = (int)$pdo->lastInsertId();
        $su_token = bk_issue_password_reset_token($pdo, $su_new_id);
        $su_link = $default_origin . '/app/set-password?token=' . $su_token;
        $su_site = get_setting('site_title', 'Blood Kings');
        $su_body = '<h1>Vítejte v ' . htmlspecialchars($su_site) . '</h1>'
            . '<p>Byl pro vás vytvořen účet <strong>' . htmlspecialchars($su_username) . '</strong>. Nastavte si prosím heslo kliknutím na odkaz níže (platnost 48 hodin):</p>'
            . '<p><a href="' . htmlspecialchars($su_link) . '">' . htmlspecialchars($su_link) . '</a></p>';
        bk_audit_log($pdo, 'user_created', $su_username . ' (' . $su_role . ', pozvánka e-mailem)', 'user', $su_new_id);
        $su_sent = send_email($su_email, 'Nastavení hesla - ' . $su_site, $su_body);
        // invited=false when the mail fails: the UI then truthfully says "account
        // created, but the invitation did not go out" instead of a lying "invitation sent".
        echo json_encode(['success' => true, 'id' => $su_new_id, 'invited' => (bool)$su_sent], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            http_response_code(400);
            echo json_encode(['error' => 'Uživatelské jméno nebo e-mail už existuje.'], JSON_UNESCAPED_UNICODE);
        } else {
            error_log('[save_user] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Uživatele se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
        }
    }
    exit;
}

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

        // No rows = no chart. A synthetic sine series used to be generated here
        // "for a natural look" - invented data passed off as measurements.
        // An empty array lets the frontend say "no data", which is the truth.
        foreach ($rows as $r) {
            $result['labels'][] = $r['label'];
            // A NULL metric (e.g. cpuusage without CloudLinux) stays NULL -
            // the chart shows a gap, not a false zero.
            $result['cpu'][] = $r['cpu'] !== null ? round((float)$r['cpu'], 1) : null;
            $result['ram'][] = $r['ram'] !== null ? round((float)$r['ram'], 1) : null;
            $result['hdd'][] = $r['hdd'] !== null ? round((float)$r['hdd'], 1) : null;
            $result['net'][] = $r['net'] !== null ? round((float)$r['net'], 1) : null;
        }

        // Averages/maxima only from actually measured values; without a single
        // value it stays null and the frontend prints a dash instead of a zero.
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

// 5. Public aggregated overview
/**
 * Data-collection health - for a watchdog running outside this server.
 *
 * Answers a single question: is cron still running? When it stops, the app
 * does not break - it keeps showing the last known states and looks healthy.
 * Of all the ways monitoring can fail, this one is the most insidious,
 * because it does not announce itself.
 *
 * The endpoint is deliberately public: the watchdog has nothing to log in
 * with and learns nothing sensitive here - just the last run time and counts.
 * It is also the cheapest possible response, so it can be polled every few minutes.
 */
if ($action === 'collection_health') {
    try {
        $last_run = get_setting('last_cron_run', '');
        $last_run_ts = $last_run !== '' ? strtotime($last_run) : false;

        // How soon cron must report before it counts as a problem.
        // The run interval is 1-5 minutes; the default 15 minutes allows a slow
        // run or a skipped tick, but not an hour-long outage.
        $max_age = max(60, (int)get_setting('collection_max_age_secs', '900'));

        // Never ran = we do not know things are bad, but we surely do not know
        // they are good. The watchdog must treat it as a problem, not as "fine so far".
        $age = $last_run_ts !== false ? (time() - $last_run_ts) : null;

        $duration_raw = get_setting('last_cron_duration_ms', '');
        $monitors_raw = get_setting('last_cron_monitors', '');

        echo json_encode([
            'lastRunAt' => $last_run_ts !== false ? date('c', $last_run_ts) : null,
            'ageSecs' => $age,
            'maxAgeSecs' => $max_age,
            'stale' => $age === null || $age > $max_age,
            // An empty string means "cron never ran with this stamp", not a zero
            // duration - hence NULL, not 0.
            'lastDurationMs' => $duration_raw !== '' ? (int)$duration_raw : null,
            'monitorsChecked' => $monitors_raw !== '' ? (int)$monitors_raw : null,
            'serverTime' => date('c'),
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] collection_health selhal: ' . $e->getMessage());
        http_response_code(503);
        echo json_encode(['error' => 'Stav sběru dat se nepodařilo zjistit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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

        // response_time is not a monitors column - the latest value comes from
        // monitor_logs. No name/outage fallback: with no real nodes an empty
        // list is returned, not a hardcoded "Donald"/"Router - Praha".
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
        // Never return an invented "healthy" state on error - the client must see
        // that the infrastructure state could not be determined, not a false "all OK".
        http_response_code(500);
        echo json_encode(['error' => 'Nepodařilo se zjistit stav infrastruktury.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Link speed measurement history and per-period averages.
 *
 * The router measures via librespeed-cli and stores results in /tmp, a
 * ramdisk - gone after a reboot. The agent sends them here, so this is
 * the only place where the history truly survives.
 *
 * Averages are computed only from what was actually measured in the window;
 * `samples` says from how many measurements, so "average of thirty" can be
 * told apart from "average of one".
 */
if ($action === 'speedtest_history') {
    $sp_monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $sp_limit = max(1, min(200, (int)($_GET['limit'] ?? 60)));

    try {
        $stmt = $pdo->prepare("
            SELECT measured_at, download_mbps, upload_mbps, ping_ms, jitter_ms, server_name
            FROM speedtest_results
            WHERE monitor_id = ?
            ORDER BY measured_at DESC
            LIMIT {$sp_limit}
        ");
        $stmt->execute([$sp_monitor_id]);

        $measurements = [];
        foreach ($stmt->fetchAll() as $r) {
            $measurements[] = [
                'measuredAt' => $r['measured_at'],
                // NULL stays NULL: librespeed sometimes returns no jitter and a zero
                // would claim a perfectly stable line.
                'downloadMbps' => $r['download_mbps'] !== null ? round((float)$r['download_mbps'], 2) : null,
                'uploadMbps' => $r['upload_mbps'] !== null ? round((float)$r['upload_mbps'], 2) : null,
                'pingMs' => $r['ping_ms'] !== null ? round((float)$r['ping_ms'], 1) : null,
                'jitterMs' => $r['jitter_ms'] !== null ? round((float)$r['jitter_ms'], 1) : null,
                'server' => $r['server_name'],
            ];
        }

        $averages = [];
        $stmt_avg = $pdo->prepare("
            SELECT AVG(download_mbps) AS dl, AVG(upload_mbps) AS ul, AVG(ping_ms) AS ping,
                   MIN(download_mbps) AS dl_min, MAX(download_mbps) AS dl_max,
                   COUNT(*) AS samples, MIN(measured_at) AS since
            FROM speedtest_results
            WHERE monitor_id = ? AND measured_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        foreach (['week' => 7, 'month' => 30, 'year' => 365] as $label => $days) {
            $stmt_avg->execute([$sp_monitor_id, $days]);
            $row = $stmt_avg->fetch() ?: [];
            $samples = (int)($row['samples'] ?? 0);
            $averages[$label] = [
                'days' => $days,
                'samples' => $samples,
                // Nothing measured, nothing to average - a zero would look like
                // a measured zero speed.
                'downloadMbps' => $samples > 0 && $row['dl'] !== null ? round((float)$row['dl'], 2) : null,
                'uploadMbps' => $samples > 0 && $row['ul'] !== null ? round((float)$row['ul'], 2) : null,
                'pingMs' => $samples > 0 && $row['ping'] !== null ? round((float)$row['ping'], 1) : null,
                'downloadMinMbps' => $samples > 0 && $row['dl_min'] !== null ? round((float)$row['dl_min'], 2) : null,
                'downloadMaxMbps' => $samples > 0 && $row['dl_max'] !== null ? round((float)$row['dl_max'], 2) : null,
                'measuredSince' => $row['since'] ?? null,
            ];
        }

        echo json_encode(['measurements' => $measurements, 'averages' => $averages], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] speedtest_history selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Historii měření rychlosti se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * The real audit trail - who signed in, who changed what.
 *
 * The `audit_log` table is filled from 62 places and the legacy admin has
 * its own page over it, but React never showed it: its "log" called
 * `audit_logs` (with an "s"), which is cron check results with a hardcoded
 * "System Agent (Cron)" user. Filters for sign-ins and config changes
 * could therefore never find anything.
 *
 * Admin-only: it contains who signed in from where, including failed
 * attempts and IP addresses.
 */
if ($action === 'user_audit_log') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ua_limit = max(10, min(500, (int)($_GET['limit'] ?? 100)));

    try {
        $stmt = $pdo->prepare("
            SELECT id, actor_username, action, target_type, target_id, description, ip_address, user_agent, created_at
            FROM audit_log
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $ua_limit, PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        foreach ($stmt->fetchAll() as $row) {
            $entries[] = [
                'id' => (int)$row['id'],
                'time' => $row['created_at'],
                'action' => $row['action'],
                // NULL when someone unauthenticated performed the action (a failed
                // sign-in attempt with an unknown name) - substituting "system"
                // would claim the application did it.
                'actor' => $row['actor_username'],
                'targetType' => $row['target_type'],
                'targetId' => $row['target_id'] !== null ? (int)$row['target_id'] : null,
                'description' => $row['description'],
                'ip' => $row['ip_address'],
                // NULL for records predating user-agent storage and for cron
                // actions (cron has no browser).
                'userAgent' => $row['user_agent'],
            ];
        }

        echo json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] user_audit_log selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Auditní protokol se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Password reset request from React.
 *
 * This action never existed in api.php. The app only checked `res.ok`, and
 * because an unknown action returned 200, it printed "password reset
 * instructions were sent" every time - and no e-mail ever went out.
 *
 * The response is deliberately identical for existing and nonexistent
 * e-mails, otherwise the form could be used to probe who has an account.
 */
if ($action === 'forgot_password') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Použijte POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $fp_email = trim((string)($input['email'] ?? ''));

    try {
        bk_password_reset_request($pdo, $fp_email, get_setting('site_title', 'Blood Kings'));
    } catch (Throwable $e) {
        // Even a send failure must not reveal whether the account exists. Into
        // the log yes, into the response no.
        error_log('[api] forgot_password selhal: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Pokud e-mail v systému existuje, byl na něj odeslán odkaz pro nastavení nového hesla.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Creating the first administrator account from the React wizard.
 *
 * The action was missing, so the wizard reported "Installation successful"
 * and created nothing. It never happened in practice only because
 * `action=session` never returned the `installed` field and the app stayed
 * on its default `true` - two halves of one unfinished feature, each hiding the other.
 *
 * An account can be created ONLY into an empty users table. Otherwise a
 * public endpoint could add an administrator to a running installation.
 */
if ($action === 'setup') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Použijte POST.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $user_count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($user_count > 0) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Instalace už proběhla - účet existuje. Přihlaste se, nebo použijte obnovu hesla.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $su_username = trim((string)($input['username'] ?? ''));
        $su_email = trim((string)($input['email'] ?? ''));
        $su_password = (string)($input['password'] ?? '');

        if ($su_username === '' || $su_email === '' || strlen($su_password) < 8) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Zadejte jméno, e-mail a heslo dlouhé aspoň 8 znaků.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!filter_var($su_email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'E-mail nemá platný tvar.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO users (username, email, role, password_hash) VALUES (?, ?, 'admin', ?)");
        $stmt->execute([$su_username, $su_email, password_hash($su_password, PASSWORD_BCRYPT, ['cost' => 12])]);
        $new_user_id = (int)$pdo->lastInsertId();

        // Sign in right away - otherwise the wizard would end on a login form
        // for the account it just created itself.
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $new_user_id;
        $_SESSION['admin_username'] = $su_username;
        $_SESSION['admin_role'] = 'admin';

        bk_audit_log($pdo, 'setup_completed', $su_username, 'user', $new_user_id, $new_user_id, $su_username);
        echo json_encode(['success' => true, 'id' => $new_user_id], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] setup selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Účet se nepodařilo založit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Export historie kontrol jednoho monitoru do CSV.
 *
 * The "Export CSV" button on the monitor detail pointed at this action, but
 * it never existed - the visitor downloaded the default JSON service
 * overview with a 200 instead of a table and had no way to see the failure.
 *
 * Error texts go only to the logged-in: the monitor page is public and error
 * messages can carry internal server names that are not visible on it.
 */
if ($action === 'export_csv') {
    $csv_monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $csv_days = max(1, min(366, (int)($_GET['days'] ?? 30)));
    $csv_is_admin = !empty($_SESSION['admin_logged_in']);

    try {
        $stmt_mon = $pdo->prepare("SELECT id, name FROM monitors WHERE id = ? LIMIT 1");
        $stmt_mon->execute([$csv_monitor_id]);
        $csv_monitor = $stmt_mon->fetch();

        if (!$csv_monitor) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt_logs = $pdo->prepare("
            SELECT checked_at, status, response_time, checked_from, error_message
            FROM monitor_logs
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY id DESC
        ");
        $stmt_logs->execute([$csv_monitor_id, $csv_days]);

        // The JSON header is set at the top of the file; for a download it must
        // be overridden, or the browser displays the file instead of saving it.
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="monitor-' . $csv_monitor_id . '-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        // BOM so Excel recognises UTF-8 and does not scramble the diacritics in names.
        fwrite($out, "\xEF\xBB\xBF");

        $header = ['Čas kontroly', 'Stav', 'Odezva (ms)', 'Měřeno z'];
        if ($csv_is_admin) {
            $header[] = 'Chybová hláška';
        }
        fputcsv($out, $header);

        foreach ($stmt_logs->fetchAll() as $log) {
            $row = [
                $log['checked_at'],
                $log['status'],
                // An unmeasured response stays empty, not zero - a zero would read
                // as a lightning-fast answer in the table.
                $log['response_time'] !== null ? (int)$log['response_time'] : '',
                $log['checked_from'] ?? '',
            ];
            if ($csv_is_admin) {
                $row[] = $log['error_message'] ?? '';
            }
            fputcsv($out, $row);
        }
        fclose($out);
    } catch (PDOException $e) {
        error_log('[api] export_csv selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Export se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Annotations for the metric charts ("deploy happened here", "disk swapped").
 *
 * The `metric_annotations` table existed in the database from the start and
 * the chart had clickable controls, but the endpoint they posted to never
 * existed. The note was silently dropped and the user got a 200. Not a
 * single row was ever written into that table.
 */
if ($action === 'save_annotation') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ann_monitor_id = (int)($input['monitor_id'] ?? 0);
    $ann_metric = trim((string)($input['metric_key'] ?? ''));
    $ann_note = trim((string)($input['note'] ?? ''));
    $ann_ts_raw = trim((string)($input['timestamp'] ?? ''));
    $ann_ts = $ann_ts_raw !== '' ? strtotime($ann_ts_raw) : false;

    if ($ann_monitor_id <= 0 || $ann_metric === '' || $ann_note === '' || $ann_ts === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor, metrika, čas nebo text poznámky.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO metric_annotations (monitor_id, metric_key, timestamp, note, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ann_monitor_id,
            mb_substr($ann_metric, 0, 30),
            date('Y-m-d H:i:s', $ann_ts),
            $ann_note,
            $_SESSION['admin_id'] ?? null,
        ]);
        bk_audit_log($pdo, 'annotation_created', mb_substr($ann_note, 0, 80), 'monitor', $ann_monitor_id);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] save_annotation selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Poznámku se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/** Annotations to draw into the chart. Operational notes are for the logged-in only. */
if ($action === 'annotations') {
    if (empty($_SESSION['admin_logged_in'])) {
        // Not 403: for an anonymous visitor the chart simply has no notes,
        // which is not an error the frontend should surface.
        echo json_encode(['annotations' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ann_monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $ann_metric = trim((string)($_GET['metric'] ?? ''));
    $ann_hours = max(1, min(8760, (int)($_GET['hours'] ?? 24)));

    try {
        $sql = "SELECT a.id, UNIX_TIMESTAMP(a.timestamp) AS ts, a.note, u.username
                FROM metric_annotations a
                LEFT JOIN users u ON u.id = a.created_by
                WHERE a.monitor_id = ? AND a.timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$ann_monitor_id, $ann_hours];
        if ($ann_metric !== '') {
            $sql .= " AND a.metric_key = ?";
            $params[] = $ann_metric;
        }
        $sql .= " ORDER BY a.timestamp ASC LIMIT 200";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $annotations = [];
        foreach ($stmt->fetchAll() as $a) {
            $annotations[] = [
                'id' => (int)$a['id'],
                'ts' => (int)$a['ts'],
                'note' => $a['note'],
                // NULL when the author has since vanished - inventing "admin" would
                // attribute the note to someone who did not write it.
                'author' => $a['username'],
            ];
        }
        echo json_encode(['annotations' => $annotations], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] annotations selhaly: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Poznámky se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * An unknown action must be heard.
 *
 * Everything that matched no handler above falls through here, and it used to
 * silently receive the default service overview with a 200. A typo in an
 * action name thus looked like success - exactly why nobody noticed for years
 * that `save_annotation` (chart notes) and `setup` (the first-run wizard) were
 * missing from api.php. The caller got 200, the note was dropped, nobody learned anything.
 *
 * An empty action keeps the default overview - it is old behaviour and I do
 * not want to cut off whatever relies on it out there.
 */
if ($action !== '') {
    http_response_code(400);
    echo json_encode([
        'error' => sprintf('Neznámá akce „%s".', $action),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 6. Default JSON service overview from the DB
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
            // Unknown capacity stays null - no "X / 100" with an invented limit.
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
