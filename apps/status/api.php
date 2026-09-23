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

// db.php loads config.php itself, after it has set the session cookie flags.
// Requiring config.php first let its session start before them, and the
// admin cookie went out without HttpOnly and SameSite.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * A read that failed: an error status and a code, never a success body.
 *
 * Many catch blocks here used to answer 200 with an empty list - `monitors: []`,
 * `incidents: []`, `series: {}` - and every reader turned that into "all
 * online", "no outages" or "nothing in the database" precisely when nothing
 * was known. Now the status says it failed, `error` is a stable code the app
 * can branch on, `message` is the sentence it shows (app-api.ts prefers it),
 * and the exception text goes to the server log only: it can name tables,
 * columns and the database host.
 */
function bk_api_fail(string $code, int $http = 500, ?Throwable $e = null, string $message = ''): never {
    global $action;
    if ($e !== null) {
        error_log('[api.php action=' . (string)$action . '] ' . $code . ': ' . $e->getMessage());
    }
    if (!headers_sent()) {
        http_response_code($http);
        header('Cache-Control: no-store');
    }
    $body = ['error' => $code];
    if ($message !== '') {
        $body['message'] = $message;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

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
    'save_monitor', 'delete_monitor', 'archive_monitor', 'unarchive_monitor', 'import_discovered_service', 'upload_logo',
    'trigger_remote_action', 'convert_to_agent_check', 'save_settings', 'test_notification', 'toggle_maintenance',
    'clear_monitor_history', 'redetect_location',
    'generate_metrics_token', 'incident_action', 'create_incident',
    'save_preset', 'delete_preset', 'assign_preset',
    'update_profile', 'oauth_unlink', 'totp_setup', 'totp_confirm', 'totp_disable', 'totp_recovery_regenerate',
    'save_status_page', 'delete_status_page', 'send_digest', 'save_subscriptions',
    'save_annotation', 'delete_annotation', 'save_user', 'delete_user',
    'router_recommendation_mute', 'wan_settings_save',
    'delete_public_subscriber',
    // these establish the session / authenticate by other means than the cookie - POST yes, CSRF no
    'login', 'logout', 'setup', 'forgot_password', 'set_password',
    'public_subscribe', 'public_subscribe_confirm', 'public_unsubscribe',
];
$bk_csrf_exempt = ['login', 'logout', 'setup', 'forgot_password', 'set_password',
    'public_subscribe', 'public_subscribe_confirm', 'public_unsubscribe'];
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
        $login_recovery_left = null;
        if (!bk_totp_verify_code($user['totp_secret'], $totp_code)) {
            // Lost phone: a one-time recovery code works in place of the TOTP
            // code. Consumed on success - the audit records how many remain.
            $login_recovery_left = bk_totp_try_recovery_code($pdo, (int)$user['id'], $totp_code);
            if ($login_recovery_left === null) {
                bk_audit_log($pdo, 'login_failed', 'Invalid 2FA code', null, null, $user['id'], $user['username']);
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Invalid 2FA code.']);
                exit;
            }
            bk_audit_log($pdo, 'totp_recovery_used', "Zbývá {$login_recovery_left} záložních kódů", 'user', $user['id'], $user['id'], $user['username']);
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
        // Present only when a recovery code signed this login in - the client
        // should tell the user how many one-time codes are left.
        'recoveryCodesRemaining' => $login_recovery_left ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1c. Logout (SPA)
if ($action === 'logout') {
    // Recorded like the legacy admin page's sign-out, while the session still names the account.
    if (!empty($_SESSION['admin_logged_in'])) {
        bk_audit_log($pdo, 'logout');
    }
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
    $viewer = bk_viewer();
    // Two views of one list. scope=public - and every anonymous caller - gets
    // the public status of every monitor: the same for everyone, with no host
    // internals. The app view needs a login and shows a user only the monitors
    // assigned to them, but those whole; an admin sees every monitor.
    $public_view = ($_GET['scope'] ?? '') === 'public' || !$viewer['logged_in'];
    $is_admin = $viewer['is_admin'] && !$public_view;
    // The archive is its own list: the live list leaves archived monitors out and
    // archived=1 shows only them - to the app, never to the public view.
    $mon_archived = !$public_view && ($_GET['archived'] ?? '') === '1';
    $mon_archive_sql = $mon_archived ? 'm.archived_at IS NOT NULL' : 'm.archived_at IS NULL';
    $monitors = [];
    $details_by_id = [];

    // The base list. response_time/cpu_usage/ram_usage/hdd_usage are NOT
    // columns of the monitors table (never were - confirmed live: "Unknown column
    // 'response_time'") - they are the latest measured values from monitor_logs
    // (availability checks) and vps_metrics (agent reports), attached via
    // subquery/join. This must NOT fail because of the extended fields below
    // (exactly that happened in production: one query for 20+ columns at once,
    // one schema mismatch = the whole monitor list gone, and the app with it).
    try {
        // The public view lists the public set only (W1-G3): servers, the
        // home router and agent services stay off it unless the owner puts
        // them on. Read inside the try: a failed read is a 500, not a list.
        [$mon_scope_sql, $mon_scope_params] = bk_monitor_scope_sql($public_view ? bk_public_monitor_ids($pdo) : bk_visible_monitor_ids($pdo), 'm.id');
        $stmt = $pdo->prepare("
            SELECT m.id, m.name, m.type, m.target, m.port, m.status, m.category, m.asset_id,
                   m.last_checked, m.last_status_change, m.last_details,
                   m.maintenance, m.maintenance_description, m.maintenance_start, m.maintenance_end, m.archived_at,
                   (SELECT l.response_time FROM monitor_logs l
                    WHERE l.monitor_id = m.id AND l.response_time > 0
                    ORDER BY l.id DESC LIMIT 1) AS response_time,
                   vm.cpu_usage, vm.ram_usage, vm.hdd_usage
            FROM monitors m
            LEFT JOIN vps_metrics vm
                   ON vm.id = (SELECT vm2.id FROM vps_metrics vm2
                               WHERE vm2.monitor_id = m.id
                               ORDER BY vm2.id DESC LIMIT 1)
            WHERE {$mon_scope_sql} AND {$mon_archive_sql}
            ORDER BY m.id ASC
        ");
        $stmt->execute($mon_scope_params);
        $agent_offline_secs = intval(get_setting('agent_offline_timeout', '50')) * 60;
        foreach ($stmt->fetchAll() as $r) {
            $details = json_decode($r['last_details'] ?? '', true) ?: [];
            $details_by_id[(int)$r['id']] = $details;
            if ($public_view) {
                // An allowlist of what the public card renders. The denylist this
                // replaced let every new agent key through - process lists,
                // interface names, per-link traffic - to anyone.
                $details_out = bk_public_monitor_details($details);
            } else {
                // The query is scoped, so the viewer may see this monitor, and a
                // monitor belongs to its users whole. Only the administrator's
                // collection diagnostics (hints naming config files) stay admin-only.
                $details_out = $details;
                if (!$is_admin) {
                    unset($details_out['cpanel_stats_error']);
                }
            }
            $last_change_ts = $r['last_status_change'] ? strtotime($r['last_status_change']) : null;
            $monitors[(int)$r['id']] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'type' => strtolower($r['type'] ?? 'web'),
                // Targets name internal hosts and, for agent-side checks, the
                // process itself. The public card never shows them.
                'target' => $public_view ? null : $r['target'],
                'port' => ($public_view || !$r['port']) ? null : (int)$r['port'],
                'status' => strtolower($r['status'] ?? 'up'),
                'category' => $r['category'] ?? 'Monitory',
                'assetId' => $r['asset_id'] ? (int)$r['asset_id'] : (int)$r['id'],
                'assetName' => $r['name'],
                'archivedAt' => !empty($r['archived_at']) ? date('c', strtotime((string)$r['archived_at'])) : null,
                'lastCheck' => $r['last_checked'] ? date('c', strtotime($r['last_checked'])) : null,
                'lastStatusChange' => $r['last_status_change'] ? date('c', strtotime($r['last_status_change'])) : null,
                'responseMs' => $r['response_time'] !== null ? (int)$r['response_time'] : null,
                'cpu' => $r['cpu_usage'] !== null ? (float)$r['cpu_usage'] : null,
                'ram' => $r['ram_usage'] !== null ? (float)$r['ram_usage'] : null,
                'hdd' => $r['hdd_usage'] !== null ? (float)$r['hdd_usage'] : null,
                // Time since the last status change. uptimeSeconds is only an
                // uptime while the monitor is UP - it used to be 0 for a monitor
                // that was down ("uptime 0 s", not "down for 3 h"), and 0 before
                // the first check ran. Unknown -> null.
                'uptimeSeconds' => ($last_change_ts && strtolower($r['status'] ?? '') === 'up') ? max(0, time() - $last_change_ts) : null,
                'sinceStatusChangeSeconds' => $last_change_ts ? max(0, time() - $last_change_ts) : null,
                // Whether an agent that has reported before has now been silent
                // longer than agent_offline_timeout - the SPA has no other way
                // to tell "active" from "was active once".
                // agent_offline_timeout = 0 means the detection is off (cron says
                // so too), so there is no verdict to give - null, not "silent".
                'agentSilent' => ($agent_offline_secs > 0 && isset($details['agent_last_seen']))
                    ? ((time() - (int)$details['agent_last_seen']) > $agent_offline_secs)
                    : null,
                // Announced maintenance is public by design - the legacy page
                // prints the description and window in a public banner. Only
                // while the flag is on; a stale description of a past window
                // stays private.
                'maintenance' => !empty($r['maintenance']),
                'maintenanceDescription' => !empty($r['maintenance']) ? ($r['maintenance_description'] ?: null) : null,
                'maintenanceStart' => (!empty($r['maintenance']) && $r['maintenance_start']) ? $r['maintenance_start'] : null,
                'maintenanceEnd' => (!empty($r['maintenance']) && $r['maintenance_end']) ? $r['maintenance_end'] : null,
                'agentLastSeen' => $public_view ? null : ($details['agent_last_seen'] ?? null),
                'hostname' => $public_view ? null : ($details['hostname'] ?? $r['target']),
                'os' => $details['os'] ?? $r['type'],
                'details' => $details_out,
                // Outages of data COLLECTION (not of the service) - the frontend MUST
                // show them, silently dropping data is forbidden (see bk_get_collection_issues).
                // Admin only: operational diagnostics, not public service status.
                'collectionIssues' => $is_admin ? bk_get_collection_issues($r, $details, $agent_offline_secs) : [],
            ];
        }
    } catch (Throwable $e) {
        // An empty list here read as "no monitors" on the public page and as
        // "all online" on the dashboard.
        bk_api_fail('monitors_unavailable', 500, $e, 'Seznam monitorů se nepodařilo načíst.');
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
                       discord_webhook_url, slack_webhook_url, telegram_bot_token, telegram_chat_id,
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
                $monitors[$mid]['cpuThreshold'] = (int)($r['cpu_threshold'] ?? BK_DEFAULT_THRESHOLDS['cpu']);
                $monitors[$mid]['ramThreshold'] = (int)($r['ram_threshold'] ?? BK_DEFAULT_THRESHOLDS['ram']);
                $monitors[$mid]['hddThreshold'] = (int)($r['hdd_threshold'] ?? BK_DEFAULT_THRESHOLDS['hdd']);
                // The limits that actually decide, preset first and null where
                // nobody set one. The three fields above are the raw columns
                // the edit form writes back, with a default filled in by ??,
                // so they cannot answer "is this configured?" and they ignore
                // a preset entirely - the frontend coloured a monitor whose
                // preset says 70 against 90.
                $eff = bk_monitor_thresholds($pdo, $r);
                $monitors[$mid]['effectiveThresholds'] = [
                    'cpu' => $eff['cpu'],
                    'ram' => $eff['ram'],
                    'hdd' => $eff['hdd'],
                ];
                $monitors[$mid]['presetId'] = $r['preset_id'] !== null ? (int)$r['preset_id'] : null;
                // null = upozornovani na zpomaleni je vypnute
                $monitors[$mid]['latencyThresholdMs'] = $r['latency_threshold_ms'] !== null ? (int)$r['latency_threshold_ms'] : null;
                $monitors[$mid]['latencyThresholdMins'] = (int)($r['latency_threshold_mins'] ?? 5);
                $monitors[$mid]['bodyKeyword'] = $r['body_keyword'];
                $monitors[$mid]['cpanelStatsUrl'] = $r['cpanel_stats_url'];
                // The per-monitor channel overrides, so the form can show what is
                // set and clear it. Admin extension only - they are secrets.
                $monitors[$mid]['discordWebhookUrl'] = $r['discord_webhook_url'];
                $monitors[$mid]['slackWebhookUrl'] = $r['slack_webhook_url'];
                $monitors[$mid]['telegramBotToken'] = $r['telegram_bot_token'];
                $monitors[$mid]['telegramChatId'] = $r['telegram_chat_id'];
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

                // This row's own details. The loop above leaves $details holding
                // the LAST monitor's, and reading that here gave every monitor
                // the agent version of whichever monitor had the highest id.
                $row_details = $details_by_id[$mid] ?? [];
                $agent_ver = $row_details['agent_version'] ?? null;
                $agent_type_key = $row_details['agent_type'] ?? null;
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
        // Two switches of the monitor dialog, in a query of their own so a
        // pending migration costs these two fields and nothing else.
        // isPublic is the effective answer (the owner's choice, or the type's
        // default when never chosen), so the switch shows what the public page does.
        try {
            foreach ($pdo->query("SELECT id, type, is_public, log_lines_enabled FROM monitors")->fetchAll() as $r) {
                $mid = (int)$r['id'];
                if (!isset($monitors[$mid])) continue;
                $monitors[$mid]['isPublic'] = bk_monitor_is_public($r['is_public'], (string)$r['type']);
                $monitors[$mid]['logLinesEnabled'] = (int)$r['log_lines_enabled'] === 1;
            }
        } catch (Throwable $t) {
            error_log('[api.php action=monitors] public/log-lines switches not read: ' . $t->getMessage());
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
    bk_refuse_archived_write($pdo, $id);
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
    $cpu_threshold = !empty($input['cpu_threshold']) ? (int)$input['cpu_threshold'] : BK_DEFAULT_THRESHOLDS['cpu'];
    $ram_threshold = !empty($input['ram_threshold']) ? (int)$input['ram_threshold'] : BK_DEFAULT_THRESHOLDS['ram'];
    $hdd_threshold = !empty($input['hdd_threshold']) ? (int)$input['hdd_threshold'] : BK_DEFAULT_THRESHOLDS['hdd'];

    $body_keyword = (!empty($input['body_keyword']) && $type === 'web') ? trim($input['body_keyword']) : null;

    // Per-monitor notification channels. The notifier has honoured these
    // columns for a long time - "this router shouts into the ops channel, the
    // rest go to the general one" - but no form or endpoint ever wrote them,
    // so they were dead settings. An empty string clears the override and the
    // monitor falls back to the global channel.
    $chan = function (string $key) use ($input): ?string {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        $val = trim((string)$input[$key]);
        return $val === '' ? null : $val;
    };
    $mon_discord = $chan('discord_webhook_url');
    $mon_slack = $chan('slack_webhook_url');
    $mon_tg_token = $chan('telegram_bot_token');
    $mon_tg_chat = $chan('telegram_chat_id');
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

    // Two switches of the dialog: on the public status page (W1-G3) and the
    // router's masked log lines (W1-C3). A key the request leaves out keeps
    // what is stored, so an older form cannot flip either of them by omission.
    // is_public = null hands the choice back to the type's default.
    $mon_switches = [];
    foreach (['is_public' => true, 'log_lines_enabled' => false] as $sw_key => $sw_nullable) {
        if (!array_key_exists($sw_key, $input)) {
            continue;
        }
        $sw_val = $input[$sw_key];
        if ($sw_val === null && $sw_nullable) {
            $mon_switches[$sw_key] = null;
        } elseif ($sw_val === true || $sw_val === 1 || $sw_val === '1') {
            $mon_switches[$sw_key] = 1;
        } elseif ($sw_val === false || $sw_val === 0 || $sw_val === '0') {
            $mon_switches[$sw_key] = 0;
        } else {
            // "false" or "" would read as on through a truthiness test; a
            // switch that decides what the public sees is refused instead.
            http_response_code(400);
            echo json_encode([
                'error' => 'invalid_switch',
                'invalidKeys' => [$sw_key],
                'message' => 'Přepínač musí být true/false (1/0).',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $mon_save_switches = function (int $monitor_id) use ($pdo, $mon_switches): void {
        foreach ($mon_switches as $sw_key => $sw_val) {
            $column = $sw_key === 'is_public' ? 'is_public' : 'log_lines_enabled';
            $pdo->prepare("UPDATE monitors SET {$column} = ? WHERE id = ?")->execute([$sw_val, $monitor_id]);
        }
    };

    try {
        if ($id > 0) {
            // Passwords (ServerQuery, RCON) are overwritten only when the administrator
            // typed a new value - an empty edit-form field must not erase a stored password.
            $stmt = $pdo->prepare("
                UPDATE monitors
                SET name = ?, type = ?, target = ?, port = ?, category = ?, timeout = ?, email_notifications = ?, sms_notifications = ?, notes = ?, maintenance = ?, monitored_processes = ?, maintenance_description = ?, maintenance_start = ?, maintenance_end = ?, cpanel_stats_url = ?, cpu_threshold = ?, ram_threshold = ?, hdd_threshold = ?, preset_id = ?, latency_threshold_ms = ?, latency_threshold_mins = ?, body_keyword = ?, sq_username = ?, sq_password = COALESCE(?, sq_password), ts3_filetransfer_port = ?, enabled_metrics = ?, rcon_port = ?, rcon_password = COALESCE(?, rcon_password), remote_actions_enabled = ?, allowed_actions = ?, asset_id = ?, heartbeat_interval = ?, heartbeat_grace = ?, discord_webhook_url = ?, slack_webhook_url = ?, telegram_bot_token = ?, telegram_chat_id = ?
                WHERE id = ?
            ");
            // Token se pri editaci zamerne neprepisuje: uloha uz ho ma zadraty
            // v curl prikazu na svem stroji a zmena by ji tise odstrihla.
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $preset_id, $latency_threshold_ms, $latency_threshold_mins, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id, $heartbeat_interval, $heartbeat_grace, $mon_discord, $mon_slack, $mon_tg_token, $mon_tg_chat, $id]);
            $mon_save_switches($id);
            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Monitor úspěšně upraven'], JSON_UNESCAPED_UNICODE);
        } else {
            $agent_key = bin2hex(random_bytes(16));
            if ($asset_id === null) {
                $stmt_auto_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
                $stmt_auto_asset->execute([$name]);
                $asset_id = (int)$pdo->lastInsertId();
            }
            $stmt = $pdo->prepare("
                INSERT INTO monitors (name, type, target, port, category, timeout, email_notifications, sms_notifications, agent_key, status, notes, maintenance, monitored_processes, maintenance_description, maintenance_start, maintenance_end, cpanel_stats_url, cpu_threshold, ram_threshold, hdd_threshold, preset_id, latency_threshold_ms, latency_threshold_mins, body_keyword, sq_username, sq_password, ts3_filetransfer_port, enabled_metrics, rcon_port, rcon_password, remote_actions_enabled, allowed_actions, asset_id, heartbeat_interval, heartbeat_grace, heartbeat_token, discord_webhook_url, slack_webhook_url, telegram_bot_token, telegram_chat_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            // Token vznika jen u heartbeat monitoru - u ostatnich typu by to byl
            // jen nepouzitelny tajny retezec navic v databazi.
            $heartbeat_token = $type === 'heartbeat' ? bk_heartbeat_generate_token() : null;
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $agent_key, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $preset_id, $latency_threshold_ms, $latency_threshold_mins, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id, $heartbeat_interval, $heartbeat_grace, $heartbeat_token, $mon_discord, $mon_slack, $mon_tg_token, $mon_tg_chat]);
            $new_id = (int)$pdo->lastInsertId();
            $mon_save_switches($new_id);
            echo json_encode(['success' => true, 'id' => $new_id, 'message' => 'Monitor úspěšně vytvořen'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        // The PDO text named tables and columns; it goes to the log only.
        bk_api_fail('save_monitor_failed', 500, $e, 'Monitor se nepodařilo uložit.');
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
    if ($regenerate) {
        bk_refuse_archived_write($pdo, $hb_id);
    }

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
            // Explicitly, not only through the foreign key: an install whose table
            // was created without it must not keep access rows pointing at a
            // monitor id that a new monitor could later reuse.
            $pdo->prepare("DELETE FROM monitor_users WHERE monitor_id = ?")->execute([$del_id]);
            $stmt = $pdo->prepare("DELETE FROM monitors WHERE id = ?");
            $stmt->execute([$del_id]);
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            bk_api_fail('delete_monitor_failed', 500, $e, 'Monitor se nepodařilo smazat.');
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatné ID monitoru'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Archiving. A monitor that is gone for good - a replaced router, a closed site -
// keeps its history but takes no part in anything live: it leaves every list and
// summary, cron skips it, nobody is alerted and its agent's reports are refused.
// A detail by id stays readable, and restoring brings it back as it was.
if ($action === 'archive_monitor' || $action === 'unarchive_monitor') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $am_input = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $am_id = (int)($am_input['id'] ?? 0);
    $am_archive = $action === 'archive_monitor';
    $am_closed = 0;
    try {
        $stmt_am = $pdo->prepare("SELECT id, name, type, archived_at FROM monitors WHERE id = ? LIMIT 1");
        $stmt_am->execute([$am_id]);
        $am = $stmt_am->fetch();
        if (!$am) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($am_archive === !empty($am['archived_at'])) {
            http_response_code(409);
            echo json_encode(['error' => $am_archive ? 'Monitor už je archivovaný.' : 'Monitor není archivovaný.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->beginTransaction();
        if ($am_archive) {
            $pdo->prepare("UPDATE monitors SET archived_at = NOW() WHERE id = ?")->execute([$am_id]);
            // An open incident of a monitor nobody watches any more would stay
            // open and escalate forever.
            $stmt_open = $pdo->prepare("SELECT id FROM incidents WHERE monitor_id = ? AND status != 'resolved'");
            $stmt_open->execute([$am_id]);
            foreach ($stmt_open->fetchAll(PDO::FETCH_COLUMN) as $am_incident) {
                $pdo->prepare("UPDATE incidents SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([(int)$am_incident]);
                $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, 'resolved', 'Monitor byl archivován, incident je uzavřen.')")
                    ->execute([(int)$am_incident]);
                $am_closed++;
            }
            // Remote actions still waiting for this agent would never be picked up.
            $pdo->prepare("UPDATE agent_actions SET status = 'failed', result_message = 'Monitor byl archivován.' WHERE monitor_id = ? AND status IN ('pending', 'sent')")
                ->execute([$am_id]);
        } else {
            // Its last state is history; the next check or report says what is true now.
            $pdo->prepare("UPDATE monitors SET archived_at = NULL, status = 'unknown', last_status_change = NOW() WHERE id = ?")->execute([$am_id]);
        }
        $pdo->commit();
        // Summaries cached with the monitor in (or out) are stale now.
        $pdo->exec("DELETE FROM settings WHERE key_name IN ('websites_overview_cache', 'dashboard_insights_cache', 'dashboard_insights_cache_cs', 'dashboard_insights_cache_en') OR key_name LIKE 'regions_cache_%'");
        log_monitor_event($pdo, $am_id, $am['name'], $am['type'], $am_archive ? 'monitor_archived' : 'monitor_restored', $am_archive ? 'Monitor archivován' : 'Monitor obnoven z archivu');
        bk_audit_log($pdo, $am_archive ? 'monitor_archived' : 'monitor_restored', (string)$am['name'], 'monitor', $am_id);
        echo json_encode(['success' => true, 'archived' => $am_archive, 'incidentsClosed' => $am_closed], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Archivaci se nepodařilo provést.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// What an administrator needs to install the agent for one monitor: its key and
// the addresses on this server. The app never showed the key, so an agent
// installed by its instructions stopped at "AGENT_KEY is not set". The key is a
// credential: admin-only, and every read is written to the audit log.
if ($action === 'agent_install_info') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ai_id = (int)($_GET['monitor_id'] ?? 0);
    try {
        $stmt_ai = $pdo->prepare("SELECT id, name, type, agent_key, archived_at FROM monitors WHERE id = ? LIMIT 1");
        $stmt_ai->execute([$ai_id]);
        $ai = $stmt_ai->fetch();
        if (!$ai) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($ai['archived_at'])) {
            http_response_code(409);
            echo json_encode(['error' => 'Monitor je archivovaný. Nejdřív ho obnovte.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (empty($ai['agent_key'])) {
            $ai['agent_key'] = bin2hex(random_bytes(16));
            $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = ?")->execute([$ai['agent_key'], $ai_id]);
        }
        $ai_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $ai_host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $ai_host)) {
            $ai_host = 'localhost';
        }
        $ai_dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/status/api.php'))), '/');
        $ai_base = ($ai_https ? 'https' : 'http') . '://' . $ai_host . $ai_dir;
        bk_audit_log($pdo, 'agent_key_viewed', (string)$ai['name'], 'monitor', $ai_id);
        echo json_encode([
            'monitorId' => $ai_id,
            'name' => $ai['name'],
            'type' => strtolower((string)$ai['type']),
            'agentKey' => $ai['agent_key'],
            'apiUrl' => $ai_base . '/agent_api.php',
            'files' => [
                'openwrt' => $ai_base . '/agent_openwrt.sh',
                'shell' => $ai_base . '/agent.sh',
                'python' => $ai_base . '/agent.py',
                'windows' => $ai_base . '/agent.ps1',
                'docker' => $ai_base . '/docker-compose.agent.yml',
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Údaje pro instalaci agenta se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
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
        $stmt_ex = $pdo->query("SELECT LOWER(name) AS lname, port, asset_id FROM monitors WHERE archived_at IS NULL");
        while ($ex = $stmt_ex->fetch()) {
            $existing_names[$ex['lname']] = true;
            if ($ex['port'] !== null && $ex['asset_id'] !== null) {
                $existing_port_asset[$ex['asset_id'] . ':' . $ex['port']] = true;
            }
        }

        $stmt = $pdo->query("SELECT id, name, type, target, asset_id, last_details FROM monitors WHERE last_details IS NOT NULL AND archived_at IS NULL");
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
        // [] read as "the agent found nothing to import".
        bk_api_fail('discovered_services_unavailable', 500, $e, 'Nalezené služby se nepodařilo načíst.');
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
    if ($source_monitor_id) {
        bk_refuse_archived_write($pdo, $source_monitor_id);
    }

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
            VALUES (?, ?, ?, ?, ?, 'unknown', ?, ?, ?, ?, ?)
        ");
        // RAM and disk were swapped here (90/95) against every other place.
        $stmt->execute([$s_name, $s_type, $s_target, $s_port, $discovered_category, $agent_key,
            BK_DEFAULT_THRESHOLDS['cpu'], BK_DEFAULT_THRESHOLDS['ram'], BK_DEFAULT_THRESHOLDS['hdd'], $discovered_asset_id]);
        $new_id = (int)$pdo->lastInsertId();
        log_monitor_event($pdo, $new_id, $s_name, $s_type, 'monitor_added', "Importováno z automatické detekce služeb (Service Discovery)");
        bk_audit_log($pdo, 'monitor_created', $s_name . ' (Service Discovery)', 'monitor', $new_id);
        echo json_encode(['success' => true, 'id' => $new_id, 'assetId' => $discovered_asset_id], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        bk_api_fail('import_failed', 500, $e, 'Službu se nepodařilo importovat.');
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
        // Only the one sentence this action throws itself is shown; a PDO or
        // filesystem message would name server paths.
        $logo_why = $e instanceof RuntimeException ? ' ' . $e->getMessage() : '';
        bk_api_fail('upload_logo_failed', 500, $e, 'Logo se nepodařilo uložit.' . $logo_why);
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
    bk_refuse_archived_write($pdo, $ra_mid);

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
    bk_refuse_archived_write($pdo, $cv_id);
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
        $stmt_ag = $pdo->prepare("SELECT COUNT(*) FROM monitors WHERE asset_id = ? AND type IN ('vps', 'openwrt') AND archived_at IS NULL");
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
        // 0 turned every alert back to unread, and a failed POST looked saved.
        bk_api_fail('alerts_read_state_unavailable', 500, $e, 'Stav přečtených upozornění se nepodařilo načíst ani uložit.');
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

    // The catalogue names agent machines; a user's lists only their monitors.
    bk_require_login();
    $dl_visible = bk_visible_monitor_ids($pdo);
    [$dl_scope, $dl_scope_params] = bk_list_scope_sql($pdo, $dl_visible, 'monitor_id');
    [$dl_agent_scope, $dl_agent_params] = bk_list_scope_sql($pdo, $dl_visible, 'm.id');
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
                $stmt_m = $pdo->prepare("SELECT COUNT(*) FROM vps_metrics WHERE `{$def['col']}` IS NOT NULL AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND {$dl_scope}");
                $stmt_m->execute($dl_scope_params);
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
            $stmt_a = $pdo->prepare("
                SELECT m.id, m.name,
                       (SELECT vm.cpu_usage FROM vps_metrics vm
                        WHERE vm.monitor_id = m.id ORDER BY vm.id DESC LIMIT 1) AS cpu_usage
                FROM monitors m
                WHERE LOWER(m.type) IN ('vps', 'openwrt') AND {$dl_agent_scope}
                ORDER BY m.name
            ");
            $stmt_a->execute($dl_agent_params);
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
            error_log('[api.php action=' . $action . '] per-machine tiles skipped: ' . $e->getMessage());
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
        // An unset key answers its default from bk_settings_defaults() (inside
        // get_setting), never ''. The form posts back what it loaded, and a ''
        // it posted back used to switch agent alerts off.
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
// One real test message through a notification channel, with the SAVED
// settings. The settings page's test buttons used to flash "Test OK" without
// calling anything - a dead webhook looked fine until the first outage.
// Wipes a monitor's measured history. Irreversible, so it asks for the
// monitor's own name back: a stray click on a button labelled "clear" must not
// be able to delete months of measurements.
if ($action === 'clear_monitor_history') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ch_input = json_decode((string)file_get_contents('php://input'), true);
    $ch_id = (int)($ch_input['monitor_id'] ?? 0);
    $ch_confirm = trim((string)($ch_input['confirm_name'] ?? ''));
    bk_refuse_archived_write($pdo, $ch_id);
    if ($ch_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt_name = $pdo->prepare("SELECT name FROM monitors WHERE id = ? LIMIT 1");
        $stmt_name->execute([$ch_id]);
        $ch_name = $stmt_name->fetchColumn();
        if ($ch_name === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($ch_confirm !== (string)$ch_name) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Pro potvrzení opište přesný název monitoru.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        bk_audit_log($pdo, 'monitor_history_cleared', (string)$ch_name, 'monitor', $ch_id);
        $pdo->prepare("DELETE FROM monitor_logs WHERE monitor_id = ?")->execute([$ch_id]);
        $pdo->prepare("DELETE FROM vps_metrics WHERE monitor_id = ?")->execute([$ch_id]);
        $pdo->prepare("DELETE FROM metrics_daily WHERE monitor_id = ?")->execute([$ch_id]);
        // The state goes with it: keeping "up" next to an empty history would
        // claim a measurement that no longer exists.
        $pdo->prepare("
            UPDATE monitors
            SET status = 'unknown', last_checked = NULL, last_status_change = NULL, last_details = NULL
            WHERE id = ?
        ")->execute([$ch_id]);

        echo json_encode(['success' => true, 'message' => 'Historie monitoru byla smazána.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[api.php action=clear_monitor_history] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Historii se nepodařilo smazat.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Asks the geolocation API again where this server is. The answer is cached in
// a setting and every check writes it into its log row, so a wrong one follows
// the data around until somebody forces a new lookup.
if ($action === 'redetect_location') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $rl_loc = detect_server_location();
        $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
        $stmt_set->execute(['ip_loc_local', $rl_loc, $rl_loc]);
        bk_audit_log($pdo, 'location_redetected', $rl_loc);
        echo json_encode(['success' => true, 'location' => $rl_loc], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[api.php action=redetect_location] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Lokalitu se nepodařilo zjistit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Maintenance on or off in one click, for one monitor or several.
//
// Putting a machine into maintenance meant opening the edit form, ticking a
// box and saving the whole monitor - so during an actual maintenance window,
// when speed matters, the operator was editing forms. Several monitors at once
// was not possible at all.
if ($action === 'toggle_maintenance') {
    // Admin only. Maintenance silences every alert for the monitors it covers,
    // and without an end it lasts forever: a 'user' account - the kind created
    // for notification subscriptions - could switch off alerting for the fleet.
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tm_input = json_decode((string)file_get_contents('php://input'), true);
    $tm_ids = [];
    foreach ((array)($tm_input['monitor_ids'] ?? []) as $tm_id) {
        $tm_id = (int)$tm_id;
        if ($tm_id > 0) {
            $tm_ids[] = $tm_id;
        }
    }
    $tm_ids = array_values(array_unique($tm_ids));
    foreach ($tm_ids as $tm_check_id) {
        bk_refuse_archived_write($pdo, $tm_check_id);
    }
    $tm_on = !empty($tm_input['maintenance']);
    $tm_desc = trim((string)($tm_input['description'] ?? ''));
    $tm_end = trim((string)($tm_input['maintenance_end'] ?? ''));
    if (count($tm_ids) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_ids.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $place = implode(',', array_fill(0, count($tm_ids), '?'));
        if ($tm_on) {
            // The window is written with the flag, so cron can end it by itself
            // (bk_maintenance_window_expired). Without an end it is a manual
            // window that waits to be switched off - both are legitimate.
            $stmt = $pdo->prepare("
                UPDATE monitors
                SET maintenance = 1,
                    maintenance_description = ?,
                    maintenance_start = NOW(),
                    maintenance_end = ?
                WHERE id IN ({$place})
            ");
            $stmt->execute(array_merge(
                [$tm_desc !== '' ? $tm_desc : null, $tm_end !== '' ? $tm_end : null],
                $tm_ids
            ));
        } else {
            // Off clears the window too - a leftover start/end would make the
            // next maintenance expire the moment it is switched on.
            $stmt = $pdo->prepare("
                UPDATE monitors
                SET maintenance = 0, maintenance_description = NULL, maintenance_start = NULL, maintenance_end = NULL
                WHERE id IN ({$place})
            ");
            $stmt->execute($tm_ids);
        }
        bk_audit_log(
            $pdo,
            $tm_on ? 'maintenance_on' : 'maintenance_off',
            implode(', ', array_map('strval', $tm_ids))
        );
        echo json_encode(['success' => true, 'changed' => count($tm_ids), 'maintenance' => $tm_on], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[api.php action=toggle_maintenance] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Údržbu se nepodařilo přepnout.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Which processes have been eating the machine over a whole window, not which
// ones happened to be on top in the last report. process_samples has kept a
// per-minute history for a long time and the only reader asked it about one
// moment (the culprits panel), so "what has been chewing the CPU today?" could
// only be answered by watching the page.
if ($action === 'process_top') {
    $pt_monitor = (int)($_GET['monitor_id'] ?? 0);
    $pt_kind = ($_GET['kind'] ?? 'cpu') === 'ram' ? 'ram' : 'cpu';
    $pt_minutes = min(43200, max(15, (int)($_GET['minutes'] ?? 1440)));
    if ($pt_monitor <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // For the users the monitor belongs to, and admins: a monitor is its users' whole.
    bk_require_monitor_view($pdo, $pt_monitor);
    try {
        if ((int)get_setting('process_history_days', '30') <= 0) {
            echo json_encode(['enabled' => false, 'processes' => []], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $col = $pt_kind === 'ram' ? 'ram_mb' : 'cpu_pct';
        // Grouped by process NAME, not pid: a service that restarts keeps
        // eating the same machine under a new pid, and a per-pid ranking would
        // split it into a dozen harmless-looking rows.
        $stmt = $pdo->prepare("
            SELECT name,
                   AVG({$col}) AS avg_val,
                   MAX({$col}) AS max_val,
                   COUNT(*) AS samples,
                   MAX(sampled_at) AS last_seen
            FROM process_samples
            WHERE monitor_id = ? AND kind = ?
              AND sampled_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
              AND {$col} IS NOT NULL
            GROUP BY name
            ORDER BY avg_val DESC
            LIMIT 12
        ");
        $stmt->execute([$pt_monitor, $pt_kind, $pt_minutes]);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'name' => (string)$r['name'],
                'avg' => round((float)$r['avg_val'], 2),
                'max' => round((float)$r['max_val'], 2),
                'samples' => (int)$r['samples'],
                'lastSeenIso' => date('c', strtotime((string)$r['last_seen'])),
            ];
        }
        echo json_encode([
            'enabled' => true,
            'kind' => $pt_kind,
            'minutes' => $pt_minutes,
            'processes' => $rows,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // A 200 with processes: [] next to the error read as "nothing ran".
        bk_api_fail('process_top_unavailable', 500, $e, 'Historii procesů se nepodařilo načíst.');
    }
    exit;
}

// Per-interface traffic by day. The table keeps a row per interface per day
// and the only reader summed it into today / 7 / 30 days, so "which day did we
// move forty gigabytes?" had no answer. Admin only, like every other endpoint
// that names interfaces - that is network topology.
if ($action === 'interface_traffic_daily') {
    $itd_monitor = (int)($_GET['monitor_id'] ?? 0);
    $itd_days = min(180, max(1, (int)($_GET['days'] ?? 30)));
    if ($itd_monitor <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí monitor_id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // For the users the monitor belongs to, and admins: a monitor is its users' whole.
    bk_require_monitor_view($pdo, $itd_monitor);
    try {
        $stmt = $pdo->prepare("
            SELECT iface, date, rx_bytes_total, tx_bytes_total
            FROM monitor_interface_traffic
            WHERE monitor_id = ? AND date > DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY iface ASC, date ASC
        ");
        $stmt->execute([$itd_monitor, $itd_days]);
        $by_iface = [];
        foreach ($stmt->fetchAll() as $r) {
            $iface = (string)$r['iface'];
            if (!isset($by_iface[$iface])) {
                $by_iface[$iface] = ['iface' => $iface, 'total' => 0.0, 'days' => []];
            }
            $rx = $r['rx_bytes_total'] !== null ? (float)$r['rx_bytes_total'] : null;
            $tx = $r['tx_bytes_total'] !== null ? (float)$r['tx_bytes_total'] : null;
            $by_iface[$iface]['total'] += ($rx ?? 0) + ($tx ?? 0);
            $by_iface[$iface]['days'][] = [
                'date' => (string)$r['date'],
                // A day the agent never reported is absent from the table; a
                // day it reported with no traffic is a real zero. Null here
                // would be a third thing that does not exist in the data.
                'rxBytes' => $rx,
                'txBytes' => $tx,
            ];
        }
        // Busiest first: a router has a dozen interfaces and three of them
        // carry everything.
        $out = array_values($by_iface);
        usort($out, fn($a, $b) => $b['total'] <=> $a['total']);
        echo json_encode(['interfaces' => $out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // interfaces: [] with a 200 is "no traffic", which a failed read is not.
        bk_api_fail('interface_traffic_unavailable', 500, $e, 'Denní provoz se nepodařilo načíst.');
    }
    exit;
}

// What was actually sent and whether it went. Admin only: it names
// recipients and carries the delivery errors of the channels.
if ($action === 'notification_log') {
    // Admin only: it names the recipients and carries the channels' delivery errors.
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $nl_monitor = isset($_GET['monitor_id']) ? (int)$_GET['monitor_id'] : 0;
    $nl_limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    // Paging by cursor, not by OFFSET: rows keep being written while somebody
    // reads the log, and an offset page would repeat one row and skip another.
    $nl_before = isset($_GET['before_id']) ? max(0, (int)$_GET['before_id']) : 0;
    $nl_kind = trim((string)($_GET['kind'] ?? ''));
    $nl_channel = trim((string)($_GET['channel'] ?? ''));
    $nl_recipient = trim((string)($_GET['q'] ?? ''));
    $nl_ok = isset($_GET['ok']) ? trim((string)$_GET['ok']) : '';
    $nl_want_summary = ($_GET['summary'] ?? '') === '1';

    if ($nl_ok !== '' && $nl_ok !== '0' && $nl_ok !== '1') {
        http_response_code(400);
        echo json_encode(['error' => 'Parametr ok smí být jen 0 nebo 1.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // A time bound that cannot be parsed must not quietly become "no bound":
    // the answer would then hold more rows than the administrator asked for
    // and still look like the complete one.
    $nl_range = ['from' => null, 'to' => null];
    foreach (['from', 'to'] as $nl_bound) {
        $nl_raw = trim((string)($_GET[$nl_bound] ?? ''));
        if ($nl_raw === '') {
            continue;
        }
        $nl_ts = strtotime($nl_raw);
        if ($nl_ts === false) {
            http_response_code(400);
            echo json_encode(['error' => "Parametr {$nl_bound} není platné datum."], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // A bare date as the upper bound means the whole day. "to=2026-09-20"
        // asked for that day, not for the split second it began.
        if ($nl_bound === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nl_raw)) {
            $nl_ts += 86399;
        }
        $nl_range[$nl_bound] = date('Y-m-d H:i:s', $nl_ts);
    }

    $nl_where = [];
    $nl_params = [];
    if ($nl_monitor > 0) {
        $nl_where[] = 'n.monitor_id = ?';
        $nl_params[] = $nl_monitor;
    }
    if ($nl_kind !== '') {
        $nl_where[] = 'n.kind = ?';
        $nl_params[] = $nl_kind;
    }
    if ($nl_channel !== '') {
        $nl_where[] = 'n.channel = ?';
        $nl_params[] = $nl_channel;
    }
    if ($nl_ok !== '') {
        $nl_where[] = 'n.ok = ?';
        $nl_params[] = (int)$nl_ok;
    }
    if ($nl_range['from'] !== null) {
        $nl_where[] = 'n.created_at >= ?';
        $nl_params[] = $nl_range['from'];
    }
    if ($nl_range['to'] !== null) {
        $nl_where[] = 'n.created_at <= ?';
        $nl_params[] = $nl_range['to'];
    }
    if ($nl_recipient !== '') {
        // The LIKE wildcards in the needle are escaped: a search for "a_b" must
        // not also match "axb", or the filter would silently widen itself.
        $nl_where[] = 'n.recipient LIKE ?';
        $nl_params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $nl_recipient) . '%';
    }
    if ($nl_before > 0) {
        $nl_where[] = 'n.id < ?';
        $nl_params[] = $nl_before;
    }
    $where = $nl_where ? 'WHERE ' . implode(' AND ', $nl_where) : '';

    try {
        // One row more than asked for tells us whether another page exists,
        // without a second COUNT over the whole table.
        $nl_fetch = $nl_limit + 1;
        $stmt = $pdo->prepare("
            SELECT n.id, n.monitor_id, n.kind, n.status, n.channel, n.recipient, n.subject,
                   n.method, n.ok, n.error_message, n.created_at,
                   m.name AS monitor_name
            FROM notification_log n
            LEFT JOIN monitors m ON m.id = n.monitor_id
            {$where}
            ORDER BY n.id DESC
            LIMIT {$nl_fetch}
        ");
        $stmt->execute($nl_params);
        $nl_fetched = $stmt->fetchAll();
        $nl_has_more = count($nl_fetched) > $nl_limit;
        if ($nl_has_more) {
            array_pop($nl_fetched);
        }
        $rows = [];
        foreach ($nl_fetched as $r) {
            $rows[] = [
                'id' => (int)$r['id'],
                'monitorId' => $r['monitor_id'] !== null ? (int)$r['monitor_id'] : null,
                'monitorName' => $r['monitor_name'],
                'kind' => (string)($r['kind'] ?? 'other'),
                'status' => $r['status'],
                'channel' => $r['channel'],
                // The address is the point of the record: "did it reach ME?"
                'recipient' => $r['recipient'],
                // The subject is the only part of a message ever stored.
                'subject' => $r['subject'],
                'method' => $r['method'],
                'ok' => (bool)$r['ok'],
                'error' => $r['error_message'],
                'atIso' => date('c', strtotime((string)$r['created_at'])),
            ];
        }

        // The offered values come from the WHOLE log, not from the page on
        // screen: a filter must never take away the option that would undo it.
        $nl_kinds = $pdo->query("SELECT DISTINCT kind FROM notification_log ORDER BY kind")->fetchAll(PDO::FETCH_COLUMN);
        $nl_channels = $pdo->query("SELECT DISTINCT channel FROM notification_log ORDER BY channel")->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'entries' => $rows,
            'nextCursor' => $nl_has_more && $rows ? $rows[count($rows) - 1]['id'] : null,
            'kinds' => array_map('strval', $nl_kinds ?: []),
            'channels' => array_map('strval', $nl_channels ?: []),
            // Only when asked for: the second page does not need it, and it is
            // two more aggregations. Never narrowed by the filters above - see
            // bk_notification_summary().
            'summary' => $nl_want_summary ? [
                'last24h' => bk_notification_summary($pdo, 24),
                'last7d' => bk_notification_summary($pdo, 24 * 7),
            ] : null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[api.php action=notification_log] ' . $e->getMessage());
        // 500, not a 200 with an empty list: both readers of this endpoint show
        // an empty log as "nothing was sent", and a failed read is no evidence
        // of that. The admin page turns the status code into a visible error.
        http_response_code(500);
        echo json_encode(['entries' => [], 'error' => 'Historii notifikací se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'test_notification') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tn_input = json_decode((string)file_get_contents('php://input'), true);
    $tn_channel = is_array($tn_input) ? (string)($tn_input['channel'] ?? '') : '';
    if (!in_array($tn_channel, ['email', 'discord', 'telegram', 'slack'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Neznámý kanál (email, discord, telegram, slack).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tn_email = null;
    $tn_lang = (string)get_setting('email_lang', 'cs');
    try {
        $stmt_tn = $pdo->prepare("SELECT email, email_lang FROM users WHERE id = ? LIMIT 1");
        $stmt_tn->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        $tn_me = $stmt_tn->fetch() ?: [];
        $tn_email = !empty($tn_me['email']) ? (string)$tn_me['email'] : null;
        if (in_array($tn_me['email_lang'] ?? '', ['cs', 'en'], true)) {
            $tn_lang = (string)$tn_me['email_lang'];
        }
    } catch (Exception $e) {
        error_log('[api.php action=test_notification] user lookup failed: ' . $e->getMessage());
    }
    $tn_result = bk_send_test_notification($tn_channel, $tn_email, $tn_lang);
    bk_audit_log($pdo, 'test_notification', $tn_channel . ': ' . ($tn_result['ok'] ? 'ok' : 'failed'));
    echo json_encode($tn_result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_settings') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['settings']) || !is_array($input['settings'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybějící data nastavení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allowed_keys = bk_settings_keys();
    $secret_keys = bk_settings_secret_keys();

    // A switch is '1' or '0'. Anything else - above all '' - means the client
    // lost the value, and storing it silently changed alerting (an empty
    // agent_notifications_enabled read as "off"). The whole save is refused
    // before anything is written, and the answer names the keys.
    $bk_bad_switches = [];
    foreach (bk_settings_boolean_keys() as $bool_key) {
        if (!array_key_exists($bool_key, $input['settings']) || is_setting_env_defined($bool_key)) {
            continue;
        }
        $bool_val = $input['settings'][$bool_key];
        $bool_val = is_string($bool_val) ? trim($bool_val) : (is_int($bool_val) ? (string)$bool_val : null);
        if (!in_array($bool_val, ['0', '1'], true)) {
            $bk_bad_switches[] = $bool_key;
        }
    }
    if ($bk_bad_switches !== []) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Přepínač musí být 0 nebo 1, prázdná hodnota se neukládá: ' . implode(', ', $bk_bad_switches),
            'invalidKeys' => $bk_bad_switches,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        bk_api_fail('save_settings_failed', 500, $e, 'Nastavení se nepodařilo uložit.');
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
        // Public view (scope=public, or no login): the status of the public
        // set, the same for everyone, with reasons that name no process and no
        // target. App view: only the monitors this viewer may see.
        $ev_public = bk_public_view();
        if (!$ev_public && $monitor_id > 0) {
            bk_require_monitor_view($pdo, $monitor_id);
        }
        // A monitor's own event log stays readable after archiving; the fleet-wide log leaves it out.
        $ev_visible = bk_request_monitor_ids($pdo);
        [$ev_scope, $ev_scope_params] = $monitor_id > 0
            ? bk_monitor_scope_sql($ev_visible, 'l.monitor_id')
            : bk_list_scope_sql($pdo, $ev_visible, 'l.monitor_id');
        $ev_params = array_merge($monitor_id > 0 ? [$monitor_id] : [], $ev_scope_params);
        $ev_status_type = '';

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
            WHERE 1=1 $where_monitor AND {$ev_scope}
            ORDER BY l.id DESC
            LIMIT $limit
        ");
        $stmt->execute($ev_params);
        $recent_rows = $stmt->fetchAll();

        $fail_limit = min(50, $limit);
        $stmt_fails = $pdo->prepare("
            SELECT $select_cols
            FROM monitor_logs l
            JOIN monitors m ON l.monitor_id = m.id
            WHERE l.status IN ('down', 'warning') $where_monitor AND {$ev_scope}
            ORDER BY l.id DESC
            LIMIT $fail_limit
        ");
        $stmt_fails->execute($ev_params);

        $rows_by_id = [];
        foreach (array_merge($recent_rows, $stmt_fails->fetchAll()) as $mr) {
            $rows_by_id[(int)$mr['id']] = $mr;
        }
        krsort($rows_by_id);
        $rows = array_values($rows_by_id);

        // A recovery is an OK check whose IMMEDIATELY PRECEDING check failed.
        // Only the fresh tail ($recent_rows: one monitor, consecutive ids) has
        // real neighbours - the older failures merged in above sit next to rows
        // hours apart, so deciding this from the merged list (as the frontend
        // did) invents recoveries at times when nothing happened.
        $recovery_ids = [];
        if ($monitor_id > 0) {
            for ($i = 0, $n = count($recent_rows) - 1; $i < $n; $i++) {
                if ($recent_rows[$i]['status'] === 'up'
                    && in_array($recent_rows[$i + 1]['status'], ['down', 'warning'], true)) {
                    $recovery_ids[(int)$recent_rows[$i]['id']] = true;
                }
            }
        }
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
                'target' => $ev_public ? null : $r['target'],
                'type' => strtoupper($r['type']),
                // An unknown check location stays null. A hardcoded location
                // (Frankfurt/RackNerd) used to be filled in here - for 37 of 40
                // events it was invented, because the column was empty.
                'location' => $r['checked_from'] ?: null,
                'status' => $r['status'] === 'down' ? 'VÝPADEK' : ($r['status'] === 'warning' ? 'VAROVÁNÍ' : 'OK'),
                'rawStatus' => $r['status'],
                'errorMsg' => ($ev_public ? bk_public_reason($r['error_message'], (string)$r['type']) : $r['error_message']) ?: ($r['status'] === 'down' ? 'Cílový server neodpovídá.' : 'Kontrola proběhla v pořádku.'),
                'responseTime' => $r['response_time'] !== null ? (int)$r['response_time'] : null,
                'isDown' => $r['status'] === 'down',
                // true = this OK check ended an outage. false on rows whose
                // neighbour is unknown - never a guess.
                'isRecovery' => isset($recovery_ids[(int)$r['id']]),
                'outageDurationSec' => $outage_duration,
                'outageEnd' => $outage_end,
            ];
        }
        // Which check recorded the LAST status change.
        //
        // It cannot be found in the list above: that list is the newest rows
        // plus the newest failures, so for a monitor that has been down for
        // hours the transition itself has already fallen out of both windows,
        // and every routine passing check looks alike anyway. The row is
        // therefore looked up by monitors.last_status_change - the only record
        // of WHEN the state changed - and its status has to agree with the
        // monitor's, otherwise it is a neighbouring check rather than the
        // change. Nothing found stays null; no row is promoted on a guess.
        $status_change = null;
        if ($monitor_id > 0) {
            try {
                $stmt_mon = $pdo->prepare("SELECT status, last_status_change, type FROM monitors WHERE id = ? LIMIT 1");
                $stmt_mon->execute([$monitor_id]);
                $mon_row = $stmt_mon->fetch();
                $ev_status_type = is_array($mon_row) ? (string)($mon_row['type'] ?? '') : '';
                $changed_at = is_array($mon_row) ? ($mon_row['last_status_change'] ?? null) : null;
                $changed_ts = ($changed_at !== null && $changed_at !== '') ? strtotime((string)$changed_at) : false;

                if ($changed_ts !== false) {
                    // +-120 s: the log INSERT and the monitors UPDATE are two
                    // statements of the same run, so they differ by about a
                    // second - never by a check interval.
                    $stmt_change = $pdo->prepare("
                        SELECT id, checked_at, status, error_message, checked_from, response_time
                        FROM monitor_logs
                        WHERE monitor_id = ? AND status = ? AND checked_at BETWEEN ? AND ?
                        ORDER BY ABS(TIMESTAMPDIFF(SECOND, checked_at, ?)) ASC, id ASC
                        LIMIT 1
                    ");
                    $stmt_change->execute([
                        $monitor_id,
                        (string)$mon_row['status'],
                        date('Y-m-d H:i:s', $changed_ts - 120),
                        date('Y-m-d H:i:s', $changed_ts + 120),
                        date('Y-m-d H:i:s', $changed_ts),
                    ]);
                    $change_row = $stmt_change->fetch();

                    if (is_array($change_row)) {
                        // The state it came FROM. Absent (the first check ever,
                        // or older rows already pruned) stays null - "it was up
                        // before" is not a safe default.
                        $stmt_prev = $pdo->prepare("
                            SELECT status FROM monitor_logs
                            WHERE monitor_id = ? AND id < ? ORDER BY id DESC LIMIT 1
                        ");
                        $stmt_prev->execute([$monitor_id, (int)$change_row['id']]);
                        $prev = $stmt_prev->fetchColumn();
                        $status_change = [
                            'changedAtIso' => date('c', strtotime((string)$change_row['checked_at'])),
                            'status' => (string)$change_row['status'],
                            'fromStatus' => ($prev === false || $prev === null) ? null : (string)$prev,
                            'errorMsg' => $change_row['error_message'] !== null && $change_row['error_message'] !== ''
                                ? (string)$change_row['error_message']
                                : null,
                            'location' => $change_row['checked_from'] ?: null,
                            'responseTime' => $change_row['response_time'] !== null ? (int)$change_row['response_time'] : null,
                        ];
                    }
                }
            } catch (Throwable $e) {
                error_log('[api.php action=events] status change lookup failed: ' . $e->getMessage());
            }
        }

        if ($ev_public && is_array($status_change)) {
            $status_change['errorMsg'] = bk_public_reason($status_change['errorMsg'], $ev_status_type);
        }
        echo json_encode(['events' => $events, 'statusChange' => $status_change], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // events: [] read as "nothing happened" on the timeline.
        bk_api_fail('events_unavailable', 500, $e, 'Události se nepodařilo načíst.');
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
    bk_require_monitor_view($pdo, $monitor_id);
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
    bk_require_login();
    // A user's insights come from their monitors only, computed fresh: the cache
    // is the admin's fleet-wide top list and must not leak into a user's view.
    $di_visible = bk_visible_monitor_ids($pdo);
    [$di_scope, $di_scope_params] = bk_list_scope_sql($pdo, $di_visible, 'id');
    try {
        // Paged (W1-B6): the dashboard asks for its four, the Insights page
        // pages through all of them. The list used to stop at eight, so the
        // ninth finding existed nowhere in the app.
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 4)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        // The analyses run over every monitor's history (regression, baselines,
        // rolling windows) - single-digit seconds on shared hosting, and the
        // dashboard waited for it on every load. The result is therefore
        // cached for 5 minutes; the data changes on the scale of minutes anyway.
        // One cache per language: the texts are worded in the request's
        // language (t()), and a single key served whichever language filled it
        // first to everybody for five minutes.
        $cache_key = $GLOBALS['BK_LANG'] === 'en' ? 'dashboard_insights_cache_en' : 'dashboard_insights_cache_cs';
        $cached_raw = $di_visible === null ? get_setting($cache_key, '') : '';
        if ($cached_raw !== '') {
            $cached = json_decode($cached_raw, true);
            if (is_array($cached) && (time() - (int)($cached['at'] ?? 0)) < 300 && isset($cached['insights']) && is_array($cached['insights'])) {
                echo json_encode([
                    'insights' => array_slice($cached['insights'], $offset, $limit),
                    'total' => count($cached['insights']),
                    'offset' => $offset,
                    'cachedAt' => (int)$cached['at'],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM monitors WHERE type NOT IN ('node', 'probe') AND {$di_scope} ORDER BY id ASC");
        $stmt->execute($di_scope_params);
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
            if ($di_visible === null) $stmt_cache->execute([$cache_key, json_encode(['at' => time(), 'insights' => $items], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $ce) {
            // cache je volitelná - the answer still goes out, the log says why it was not kept
            error_log('[api.php action=' . $action . '] cache not stored: ' . $ce->getMessage());
        }
        echo json_encode([
            'insights' => array_slice($items, $offset, $limit),
            'total' => count($items),
            'offset' => $offset,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        bk_api_fail('dashboard_insights_unavailable', 500, $e, 'Nepodařilo se sestavit postřehy.');
    }
    exit;
}

// 2c1b. Daily availability breakdown for the last N days (dashboard heatmap) -
// real aggregates from monitor_logs, not a guess from the monitor's current state.
if ($action === 'daily_uptime') {
    try {
        $days = min(366, max(1, (int)($_GET['days'] ?? 30)));

        // Public view: the public set. App view: the monitors this viewer may see.
        [$du_scope, $du_scope_params] = bk_list_scope_sql($pdo, bk_request_monitor_ids($pdo), 'id');
        $stmt_mon = $pdo->prepare("SELECT id, name FROM monitors WHERE type NOT IN ('node', 'probe') AND {$du_scope} ORDER BY id ASC");
        $stmt_mon->execute($du_scope_params);
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

        // The day's availability in time (bk_uptime_segments), not in rows:
        // a day a silent agent wrote no row at all was "no data" here, and
        // the strip showed grey where the router was off. Today is computed
        // live; finished days come from the uptime_daily rollup (cron, every
        // ten minutes) - 30 days of raw logs per page load cost seconds.
        $du_ids = array_map(fn ($m) => (int)$m['id'], $mon_rows);
        $du_today = date('Y-m-d');
        $du_time = [];
        foreach (bk_uptime_segments_for($pdo, strtotime($du_today . ' 00:00:00'), time(), $du_ids) as $du_mid => $du_seg) {
            $du_time[$du_mid][$du_today] = bk_uptime_summary($du_seg['segments'], $du_seg['from'], $du_seg['to']);
        }
        $stmt_ud = $pdo->prepare("
            SELECT monitor_id, day, secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured
            FROM uptime_daily
            WHERE day >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND day < CURDATE() AND secs_up IS NOT NULL
        ");
        $stmt_ud->execute([$days]);
        foreach ($stmt_ud->fetchAll() as $ud) {
            $du_time[(int)$ud['monitor_id']][(string)$ud['day']] = bk_uptime_totals([bk_uptime_daily_part($ud)]);
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
                // A finished day the rollup has not reached yet (cron behind,
                // the first minutes after a deploy) falls back to its check
                // counts rather than to "no data".
                $tm = $du_time[$mid][$day_key]
                    ?? ($d ? bk_uptime_totals([bk_uptime_daily_part([
                        'checks_up' => $d['up_count'], 'checks_down' => $d['down_count'],
                        'checks_warning' => $d['warning_count'], 'checks_maintenance' => $d['maint_count'],
                    ])]) : null);

                // 'unknown' rows (types without an active check) are not measurements -
                // they do not count into availability at all, otherwise a monitor
                // nobody ever tested would show false outages.
                $down = $d ? (int)$d['down_count'] : 0;
                $warn = $d ? (int)$d['warning_count'] : 0;
                $measured = $d ? (int)$d['up_count'] + $down + $warn : 0;
                $m_secs = $tm['measured'] ?? 0;
                $maint_secs = $tm['maintenance'] ?? 0;

                // The day's average response for the latency sparkline - null until
                // something actually answered that day (0 would claim instant responses).
                $avg_ms = ($d && $d['avg_rt'] !== null) ? (int)round((float)$d['avg_rt']) : null;

                if ($m_secs === 0 && $maint_secs === 0) {
                    // A day without a single measured second has no 0% uptime - it has none.
                    $day_list[] = ['date' => $day_display, 'status' => 'paused', 'uptimePct' => null, 'avgMs' => $avg_ms, 'detail' => t('day_no_data')];
                    continue;
                }

                $uptimePct = $tm['pct'] !== null ? round($tm['pct'], 1) : null;

                if ($maint_secs > 0 && $maint_secs >= $m_secs) {
                    $status = 'maintenance';
                    $detail = t('day_maintenance');
                } elseif (($tm['silent'] ?? 0) > 0) {
                    // The agent wrote nothing, so there are no failed checks to
                    // count - the outage is how long it was silent.
                    $status = 'down';
                    $detail = sprintf(t('day_silent_detail'), bk_format_duration_secs((int)$tm['outage']), $uptimePct);
                } elseif (($tm['down'] ?? 0) > 0 || $down > 0) {
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
        // An empty series drew an empty 30-day strip where days of outage were.
        bk_api_fail('daily_uptime_unavailable', 500, $e, 'Denní dostupnost se nepodařilo načíst.');
    }
    exit;
}

// Availability over several windows at once (24 h / 7 d / 30 d / 90 d) for the
// public page. One pass over 90 days of logs instead of four sla_report calls -
// those would also compute percentiles and last outages four times, which nobody here wants.
// A window without a single measured check is null, not 100.
if ($action === 'uptime_windows') {
    try {
        // Public view: the public set. App view: the monitors this viewer may see.
        $uw_visible = bk_request_monitor_ids($pdo);
        $uw_archived = bk_archived_monitor_ids($pdo);
        $uw_ids = [];
        foreach ($pdo->query("SELECT id FROM monitors WHERE type NOT IN ('node', 'probe')")->fetchAll(PDO::FETCH_COLUMN) as $uw_id) {
            $uw_id = (int)$uw_id;
            if (in_array($uw_id, $uw_archived, true)) {
                continue;
            }
            if ($uw_visible !== null && !in_array($uw_id, $uw_visible, true)) {
                continue;
            }
            $uw_ids[] = $uw_id;
        }
        // In time, not in rows: the row count let a silent agent's blackout
        // vanish (one 'down' row). d1 is the last 24 hours from the logs;
        // 7/30/90 are calendar days, today live and the rest from the
        // uptime_daily rollup, which outlives the 30-day log retention.
        $uw_to = time();
        $uw_out = [];
        $uw_24h = bk_uptime_segments_for($pdo, $uw_to - 86400, $uw_to, $uw_ids);
        $uw_days = bk_uptime_day_windows($pdo, $uw_ids, [7, 30, 90]);
        foreach ($uw_ids as $uw_mid) {
            $uw_seg = $uw_24h[$uw_mid] ?? null;
            $row = ['d1' => $uw_seg !== null ? bk_uptime_summary($uw_seg['segments'], $uw_seg['from'], $uw_seg['to'])['pct'] : null];
            foreach ([7, 30, 90] as $w) {
                $row["d{$w}"] = $uw_days[$uw_mid][$w]['pct'] ?? null;
            }
            // The first day with data in the 90-day window (W1-B2). Against
            // windowStart below, 90 days over a monitor that exists for 40
            // print "data od <since>" instead of passing for a quarter.
            $row['since'] = $uw_days[$uw_mid][90]['since'] ?? null;
            if ($row['d1'] === null && $row['d90'] === null) {
                // Nothing measured at all: the monitor stays out, as before.
                continue;
            }
            $uw_out[$uw_mid] = $row;
        }
        // (object): an empty result and sequential ids must both stay an object,
        // the frontend indexes into it by monitor id. windowStart is each
        // window's first calendar day in the server's zone, so the client
        // compares day strings and a browser elsewhere cannot shift the cut.
        echo json_encode([
            'windows' => (object)$uw_out,
            'windowStart' => [
                'd7' => date('Y-m-d', strtotime('-6 day', strtotime('today'))),
                'd30' => date('Y-m-d', strtotime('-29 day', strtotime('today'))),
                'd90' => date('Y-m-d', strtotime('-89 day', strtotime('today'))),
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        bk_api_fail('uptime_windows_unavailable', 500, $e, 'Dostupnost se nepodařilo spočítat.');
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
            $stmt_bdg = $pdo->prepare("SELECT name, status FROM monitors WHERE id = ? AND archived_at IS NULL LIMIT 1");
            $stmt_bdg->execute([$bdg_mid]);
            $bdg_row = $stmt_bdg->fetch();
            // A monitor off the public page (W1-G3) answers like a missing one
            // to anyone who may not see it: the badge prints its name, and ids
            // are easy to count through.
            $bdg_allowed = in_array($bdg_mid, bk_public_monitor_ids($pdo), true)
                || (!empty($_SESSION['admin_logged_in']) && bk_can_view_monitor($pdo, $bdg_mid));
            if (!$bdg_row || !$bdg_allowed) {
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
            // Summary of the public set, with the verdict public_status gives
            // (W1-B4). It said "vše online" unless something was down - a
            // degraded or unknown monitor and a stopped collector included.
            [$bdg_scope, $bdg_params] = bk_list_scope_sql($pdo, bk_public_monitor_ids($pdo), 'id');
            $stmt_bdg = $pdo->prepare("SELECT status, maintenance, last_checked FROM monitors WHERE {$bdg_scope}");
            $stmt_bdg->execute($bdg_params);
            $bdg_verdict = bk_overall_verdict($stmt_bdg->fetchAll(), bk_collection_is_fresh());
            $bdg_label = trim((string)get_setting('site_title', 'status')) ?: 'status';
            $bdg_state = [
                'healthy' => 'up',
                'down' => 'down',
                'degraded' => 'warning',
                'maintenance' => 'maintenance',
            ][$bdg_verdict['verdict']] ?? 'unknown';
            $bdg_value = [
                'up' => $bdg_words['fleet_ok'],
                'down' => $bdg_words['fleet_down'] . ' (' . $bdg_verdict['counts']['down'] . ')',
                'warning' => $bdg_words['warning'],
                'maintenance' => $bdg_words['maintenance'],
            ][$bdg_state] ?? $bdg_words['unknown'];
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
        // Public view: open outages and announced incidents for everyone, without
        // targets, operator names or reasons that name a process. App view: the
        // monitors this viewer may see, plus incidents tied to no monitor.
        $inc_public = bk_public_view();
        // The public view covers the public set: an outage of a hidden server is
        // not announced to anonymous visitors under its name (W1-G3).
        $inc_visible = bk_request_monitor_ids($pdo);
        [$inc_scope, $inc_scope_params] = bk_list_scope_sql($pdo, $inc_visible, 'm.id');
        [$inc_m_scope, $inc_m_params] = bk_monitor_scope_sql($inc_visible, 'monitor_id');
        $inc_manual_where = $inc_visible === null ? '1=1' : "(monitor_id IS NULL OR {$inc_m_scope})";

        // Outages of TARGET monitors that are down RIGHT NOW - takes the latest
        // 'down' row per currently unavailable monitor, not every historical down
        // row (that would show long-resolved outages as still ongoing).
        // No inner catch any more: a failed read here used to leave the list
        // empty, and the page said "no outages" during one. Every query of
        // this action now fails the whole answer (the outer catch -> 500).
        $stmt_logs = $pdo->prepare("
            SELECT l.id, l.monitor_id, l.checked_at, l.error_message,
                   m.name as monitor_name, m.target, m.type, m.last_status_change
            FROM monitor_logs l
            JOIN monitors m ON l.monitor_id = m.id
            WHERE m.status = 'down' AND {$inc_scope}
              AND l.id = (SELECT MAX(l2.id) FROM monitor_logs l2 WHERE l2.monitor_id = l.monitor_id AND l2.status = 'down')
            ORDER BY l.id DESC
            LIMIT 50
        ");
        $stmt_logs->execute($inc_scope_params);
        $log_rows = $stmt_logs->fetchAll();

        // Open DB incidents by monitor - a live outage links to them so it
        // can be acknowledged/closed from the UI (the lifecycle creates them
        // automatically on the transition to down).
        $open_by_monitor = [];
        // monitor_id has to be SELECTed - the loop below indexes by it.
        // Without it every open incident landed under key 0, so no
        // monitor ever matched and "acknowledged by" and the incident
        // id came out null for all of them: the Ack button was missing
        // on exactly the incidents that had one.
        $stmt_open = $pdo->query("
            SELECT id, monitor_id, acknowledged_by, acknowledged_at, escalated_at
            FROM incidents
            WHERE status != 'resolved' AND monitor_id IS NOT NULL
        ");
        foreach ($stmt_open->fetchAll() as $oi) {
            $open_by_monitor[(int)$oi['monitor_id']] = $oi;
        }

        foreach ($log_rows as $r) {
            // When the outage STARTED, not when it was last confirmed. For an
            // actively checked monitor cron writes a log every cycle, so the
            // newest 'down' row is a few minutes old and the duration on the
            // card reset with every check - an outage running since morning
            // kept reporting "2 minutes". last_status_change is stamped once,
            // on the transition to down. Rows from before that column existed
            // fall back to the log.
            $start_ts = !empty($r['last_status_change'])
                ? strtotime((string)$r['last_status_change'])
                : false;
            if ($start_ts === false || $start_ts <= 0) {
                $start_ts = strtotime($r['checked_at']);
            }
            $open_inc = $open_by_monitor[(int)$r['monitor_id']] ?? null;
            $incidents[] = [
                'id' => (int)$r['id'],
                'incidentId' => $open_inc ? (int)$open_inc['id'] : null,
                'acknowledgedBy' => ($open_inc && !$inc_public) ? $open_inc['acknowledged_by'] : null,
                'acknowledgedAt' => ($open_inc && !empty($open_inc['acknowledged_at']))
                    ? date('c', strtotime((string)$open_inc['acknowledged_at']))
                    : null,
                // Escalation happened silently: cron stamps it and nobody
                // could see that an outage had already been escalated past
                // whoever was supposed to pick it up.
                'escalatedAt' => (!$inc_public && $open_inc && !empty($open_inc['escalated_at']))
                    ? date('c', strtotime((string)$open_inc['escalated_at']))
                    : null,
                'monitor_id' => (int)$r['monitor_id'],
                'monitor_name' => $r['monitor_name'],
                'target' => $inc_public ? null : $r['target'],
                'type' => strtoupper($r['type']),
                'status' => 'open',
                'severity' => 'down',
                'started_at' => date('d.m.Y H:i:s', $start_ts),
                'resolved_at' => null,
                'duration_text' => bk_duration_text(time() - $start_ts),
                'reason' => ($inc_public ? bk_public_reason($r['error_message'], (string)$r['type']) : $r['error_message']) ?: 'Cílový port neodpovídá',
            ];
        }

        // Manually reported / global incidents (the `incidents` table - title/impact/status,
        // without a link to a specific monitor).
        $manual_incidents = [];
        $stmt_inc = $pdo->prepare("
            SELECT id, title, impact, status, created_at, updated_at, resolved_at,
                   monitor_id, acknowledged_by, acknowledged_at, postmortem
            FROM incidents
            WHERE {$inc_manual_where}
            ORDER BY id DESC
            LIMIT 50
        ");
        $stmt_inc->execute($inc_visible === null ? [] : $inc_m_params);
        foreach ($stmt_inc->fetchAll() as $r) {
            $start_ts = strtotime($r['created_at']);
            $end_ts = $r['resolved_at'] ? strtotime($r['resolved_at']) : time();
            $updates = [];
            $stmt_upd = $pdo->prepare("SELECT status, message, created_at FROM incident_updates WHERE incident_id = ? ORDER BY id ASC");
            $stmt_upd->execute([(int)$r['id']]);
            foreach ($stmt_upd->fetchAll() as $u) {
                // The lifecycle stores the raw check failure and the incident
                // actions the operator's name; the public view gets neither.
                $updates[] = [
                    'status' => $u['status'],
                    'message' => $inc_public ? bk_public_incident_update($u['message']) : $u['message'],
                    'at' => date('d.m.Y H:i:s', strtotime($u['created_at'])),
                ];
            }

            $manual_incidents[] = [
                'id' => (int)$r['id'],
                'title' => $r['title'],
                'impact' => $r['impact'],
                'status' => $r['status'],
                'monitorId' => $r['monitor_id'] !== null ? (int)$r['monitor_id'] : null,
                'acknowledgedBy' => $inc_public ? null : $r['acknowledged_by'],
                'acknowledgedAt' => $r['acknowledged_at'] ? date('d.m.Y H:i:s', strtotime($r['acknowledged_at'])) : null,
                'postmortem' => $r['postmortem'],
                'createdAt' => date('d.m.Y H:i:s', $start_ts),
                'resolvedAt' => $r['resolved_at'] ? date('d.m.Y H:i:s', $end_ts) : null,
                'durationText' => bk_duration_text($end_ts - $start_ts),
                'updates' => $updates,
            ];
        }

        echo json_encode(['incidents' => $incidents, 'manualIncidents' => $manual_incidents], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Both lists empty was the green "no incidents" box.
        bk_api_fail('incidents_unavailable', 500, $e, 'Incidenty se nepodařilo načíst.');
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
        // The monitor's state comes along: closing an incident does not end the
        // outage, and the caller has to be told so plainly instead of watching
        // the outage stay on the page with no explanation.
        $stmt = $pdo->prepare("
            SELECT i.id, i.status, i.monitor_id, m.status AS monitor_status
            FROM incidents i
            LEFT JOIN monitors m ON m.id = i.monitor_id
            WHERE i.id = ?
        ");
        $stmt->execute([$incident_id]);
        $incident = $stmt->fetch();
        if (!$incident) {
            http_response_code(404);
            echo json_encode(['error' => 'Incident nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Extra fields for the answer - empty unless an operation has something
        // to report beyond success.
        $ia_extra = [];
        $ia_still_down = $incident['monitor_id'] !== null
            && (string)($incident['monitor_status'] ?? '') === 'down';

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
            // A closed incident over a monitor that is still down is a record
            // closed, not an outage ended. The timeline says which one happened.
            $ia_note = "[{$username}] " . ($note !== '' ? $note : 'Incident uzavřen ručně.');
            if ($ia_still_down) {
                $ia_note .= ' Monitor byl v tu chvíli stále nedostupný.';
                $ia_extra['monitorStillDown'] = true;
            }
            $add_update('resolved', $ia_note);
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

        echo json_encode(array_merge(['success' => true], $ia_extra), JSON_UNESCAPED_UNICODE);
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
    // An incident may belong to a monitor. Closing the incident of a monitor that
    // is still down used to leave its outage with no record to work in: the
    // outage card hangs off the open incident, and there was none - no notes, no
    // acknowledge, and the escalation had nothing to escalate. Opening one again
    // must not wait for the service to recover.
    $ci_monitor_id = isset($input['monitorId']) ? (int)$input['monitorId'] : 0;

    if ($title === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Název incidentu je povinný.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($ci_monitor_id > 0) {
        $stmt_ci_mon = $pdo->prepare("SELECT id FROM monitors WHERE id = ?");
        $stmt_ci_mon->execute([$ci_monitor_id]);
        if ($stmt_ci_mon->fetchColumn() === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        bk_refuse_archived_write($pdo, $ci_monitor_id);
        $stmt_ci_open = $pdo->prepare("SELECT id FROM incidents WHERE monitor_id = ? AND status != 'resolved' LIMIT 1");
        $stmt_ci_open->execute([$ci_monitor_id]);
        $ci_open = $stmt_ci_open->fetchColumn();
        if ($ci_open !== false) {
            // Two open incidents for one monitor would split the timeline and the
            // outage card can only ever link to one of them.
            http_response_code(409);
            echo json_encode(['error' => 'Tento monitor už má otevřený incident.', 'id' => (int)$ci_open], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO incidents (title, impact, status, monitor_id) VALUES (?, ?, 'investigating', ?)");
        $stmt->execute([$title, $impact, $ci_monitor_id > 0 ? $ci_monitor_id : null]);
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
        // No catch of its own: usedBy 0 after a failed read made deleting a
        // preset that monitors rely on look harmless.
        $usage = [];
        $stmt_u = $pdo->query("SELECT preset_id, COUNT(*) AS c FROM monitors WHERE preset_id IS NOT NULL GROUP BY preset_id");
        foreach ($stmt_u->fetchAll() as $u) {
            $usage[(int)$u['preset_id']] = (int)$u['c'];
        }
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
    bk_require_monitor_view($pdo, $monitor_id);
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
    // Admin only: internal targets, notes, SMTP and SMS account identifiers.
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
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
        // cpanel_stats.php authenticates by ?key= alone, so the stored URL is a
        // credential. The file promises no secrets: the address stays, the key goes.
        foreach ($export['monitors'] as &$exp_monitor) {
            if (!empty($exp_monitor['cpanel_stats_url'])) {
                $exp_monitor['cpanel_stats_url'] = strtok((string)$exp_monitor['cpanel_stats_url'], '?#');
            }
        }
        unset($exp_monitor);

        try {
            $export['presets'] = $pdo->query("SELECT name, description, service_type, metrics, cpu_threshold, ram_threshold, hdd_threshold FROM metric_presets ORDER BY name")->fetchAll();
        } catch (Throwable $e) {
            // Stara DB bez tabulky presetu - export ostatniho ma stale smysl.
            // null, not []: the file must not claim there were no presets.
            error_log('[api.php action=export_config] presets skipped: ' . $e->getMessage());
            $export['presets'] = null;
        }
        try {
            $export['statusPages'] = $pdo->query("SELECT title, slug, description, is_public, monitor_ids FROM status_pages ORDER BY title")->fetchAll();
        } catch (Throwable $e) {
            // Same as presets: a backup that silently lacks the status pages
            // would restore without them. null says "not exported".
            error_log('[api.php action=export_config] status pages skipped: ' . $e->getMessage());
            $export['statusPages'] = null;
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
                'totpRecoveryRemaining' => !empty($mp['totp_enabled']) ? bk_totp_recovery_remaining($pdo, $mp_uid) : null,
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
if ($action === 'totp_setup' || $action === 'totp_confirm' || $action === 'totp_disable' || $action === 'totp_recovery_regenerate') {
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
            // Recovery codes are part of enabling 2FA, not an optional extra -
            // without them a lost phone means a locked account. Returned in
            // plaintext exactly once; only hashes are stored.
            $totp_recovery = bk_totp_generate_recovery_codes($pdo, $totp_uid);
            echo json_encode(['success' => true, 'recoveryCodes' => $totp_recovery], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'totp_recovery_regenerate') {
            // A new set invalidates the old one, so it demands the password -
            // a stolen session must not be able to mint sign-in codes.
            $stmt_me = $pdo->prepare("SELECT password_hash, totp_enabled FROM users WHERE id = ? LIMIT 1");
            $stmt_me->execute([$totp_uid]);
            $trr_me = $stmt_me->fetch();
            if (!$trr_me || empty($trr_me['totp_enabled'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Záložní kódy mají smysl jen se zapnutým 2FA.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!password_verify((string)($totp_input['password'] ?? ''), $trr_me['password_hash'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Nesprávné heslo - kódy zůstávají beze změny.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $totp_recovery = bk_totp_generate_recovery_codes($pdo, $totp_uid);
            bk_audit_log($pdo, 'totp_recovery_regenerated', '', 'user', $totp_uid);
            echo json_encode(['success' => true, 'recoveryCodes' => $totp_recovery], JSON_UNESCAPED_UNICODE);
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
        // Codes without 2FA are a sign-in backdoor - they go with it.
        try {
            $pdo->prepare("DELETE FROM totp_recovery_codes WHERE user_id = ?")->execute([$totp_uid]);
        } catch (Throwable $e) {
            // 2FA is already off; codes that outlive it cannot sign anyone in,
            // because the login checks them only while totp_enabled = 1.
            error_log('[api.php action=totp_disable] recovery codes not deleted: ' . $e->getMessage());
        }
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
        // Hidden pages are the admin's drafts, not something every account may open.
        $sp_is_admin = bk_viewer()['is_admin'];

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
    // Hidden pages are the admin's drafts, not something every account may open.
    $is_admin_sp = bk_viewer()['is_admin'];
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
        // Public view: where the checks run from and how often they succeed,
        // nothing more. A user's app view counts only their monitors and never
        // reads or writes the fleet-wide cache.
        $rg_public = bk_public_view();
        $rg_visible = bk_request_monitor_ids($pdo);
        // Two shared caches: the public set, the same for every visitor, and
        // the whole fleet for administrators. A user's own scope is not cached.
        $rg_cacheable = $rg_public || $rg_visible === null;
        [$rg_scope, $rg_scope_params] = bk_list_scope_sql($pdo, $rg_visible, 'monitor_id');
        $rg_project = function (array $payload) use ($rg_public): array {
            if ($rg_public) {
                $payload['regions'] = array_map(
                    fn($r) => ['location' => $r['location'] ?? null, 'successRate' => $r['successRate'] ?? null],
                    $payload['regions'] ?? []
                );
            }
            return $payload;
        };

        // Server-side cache, stejny vzorec jako websites_overview o kus niz.
        //
        // Agregace 30 dni monitor_logs bezi na tomhle hostingu ~5 s I S krycim
        // indexem - casovani skaluje linearne s oknem (0,45 s pro den, 5 s pro
        // mesic), takze index se pouziva a pomale je proste secteni ctvrt
        // milionu radku. Mista mereni se pritom meni jen kdyz pribude sonda
        // nebo lokalita Cloudflare - 10 minut stara odpoved je porad pravdiva,
        // a `cachedAt` to odpovedi priznava.
        $regions_cache_key = 'regions_cache_' . $days . 'd' . ($rg_public ? '_public' : '');
        $cache_raw = $rg_cacheable ? get_setting($regions_cache_key, '') : '';
        if ($cache_raw !== '') {
            $cached = json_decode($cache_raw, true);
            if (is_array($cached) && isset($cached['at'], $cached['data']) && time() - (int)$cached['at'] < 600) {
                echo json_encode($rg_project($cached['data']), JSON_UNESCAPED_UNICODE);
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
              AND {$rg_scope}
            GROUP BY checked_from
            ORDER BY checks DESC
        ");
        $stmt->execute(array_merge([$days], $rg_scope_params));

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
            if ($rg_cacheable) $stmt_c->execute([$regions_cache_key, json_encode(['at' => time(), 'data' => $regions_payload], JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            // Cache je optimalizace - kdyz se nezapise, odpoved stejne odejde.
            error_log('[api.php action=' . $action . '] cache not stored: ' . $e->getMessage());
        }
        echo json_encode($rg_project($regions_payload), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Přehled měřicích míst se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'websites_overview') {
    bk_require_login();
    // The cache holds the whole fleet; a user gets only their monitors out of it,
    // and the filtered map is never written back.
    $wo_visible = bk_visible_monitor_ids($pdo);
    $wo_filter = function (array $data) use ($wo_visible, $pdo): array {
        $wo_monitors = (array)($data['monitors'] ?? []);
        if ($wo_visible !== null) {
            $wo_monitors = array_intersect_key($wo_monitors, array_flip($wo_visible));
        }
        // Archived sites leave the overview, also when the cache predates the archiving.
        $data['monitors'] = (object)array_diff_key($wo_monitors, array_flip(bk_archived_monitor_ids($pdo)));
        // The limit cron alerts at (ssl_alert_days), read fresh rather than
        // cached: the Insights page lists a certificate by the same rule the
        // alert uses, not by a number of its own (W1-B6).
        $data['sslAlertDays'] = max(1, (int)get_setting('ssl_alert_days', '14'));
        return $data;
    };
    try {
        $cache_raw = get_setting('websites_overview_cache', '');
        if ($cache_raw !== '') {
            $cached = json_decode($cache_raw, true);
            if (is_array($cached) && isset($cached['at'], $cached['data']) && time() - (int)$cached['at'] < 600) {
                echo json_encode($wo_filter($cached['data']), JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // Which monitors have anything measured, and since when. The
        // percentages themselves are computed in time below.
        $stmt = $pdo->query("
            SELECT monitor_id, MIN(checked_at) AS measured_since
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND status IN ('up','down','warning')
            GROUP BY monitor_id
        ");
        $sla = [];
        while ($row = $stmt->fetch()) {
            $sla[(int)$row['monitor_id']] = [
                'sla7' => null,
                'sla30' => null,
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
                $sla[$mid]['longTermDays'] = (int)$lrow['days_covered'];
                if (empty($sla[$mid]['measuredSince']) && !empty($lrow['since'])) {
                    $sla[$mid]['measuredSince'] = $lrow['since'];
                }
            }
        } catch (Throwable $e) {
            // Without the rollup table the long window stays null - visibly empty.
            error_log('[api.php action=' . $action . '] long-term coverage unavailable: ' . $e->getMessage());
        }

        // Availability in time, not in rows (bk_uptime_day_windows): the
        // same definition as the badge and the SLA report. A window without a
        // single measured second = null, not an invented 100 %.
        foreach (bk_uptime_day_windows($pdo, array_keys($sla), [7, 30, 365]) as $wo_mid => $wo_win) {
            $sla[$wo_mid]['sla7'] = $wo_win[7]['pct'];
            $sla[$wo_mid]['sla30'] = $wo_win[30]['pct'];
            $sla[$wo_mid]['sla365'] = $wo_win[365]['pct'];
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
            error_log('[api.php action=' . $action . '] cache not stored: ' . $e->getMessage());
        }
        echo json_encode($wo_filter($data), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Přehled SLA se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'sla_report') {
    bk_require_login();
    // A user's report covers only their monitors, and so do the fleet totals.
    [$sla_scope, $sla_scope_params] = bk_list_scope_sql($pdo, bk_visible_monitor_ids($pdo), 'm.id');
    try {
        $days = min(366, max(1, (int)($_GET['days'] ?? 30)));

        // Per-monitor uptime and outages
        $stmt = $pdo->prepare("
            SELECT m.id, m.name, m.target, m.type, m.status as current_status, m.last_status_change,
                   COUNT(l.id) as total_checks,
                   SUM(CASE WHEN l.status = 'up' THEN 1 ELSE 0 END) as up_checks,
                   SUM(CASE WHEN l.status = 'down' THEN 1 ELSE 0 END) as down_checks
            FROM monitors m
            LEFT JOIN monitor_logs l ON l.monitor_id = m.id AND l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            WHERE m.type NOT IN ('node', 'probe') AND {$sla_scope}
            GROUP BY m.id, m.name, m.target, m.type, m.status, m.last_status_change
            ORDER BY m.id ASC
        ");
        $stmt->execute(array_merge([$days], $sla_scope_params));
        $monitors = $stmt->fetchAll();

        // The loop here used to fire 3 queries PER monitor (last outage, its
        // end, percentiles) - N+1 in its purest form. Now three batched
        // queries before the loop; results live in maps keyed by monitor_id and
        // the values are bit-for-bit identical (pinned by integration tests).
        $sla_outage_by_mid = [];
        // No catch of its own: a failed read here used to report "never down"
        // (lastOutage null) for every monitor. It fails the report instead.
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
        } catch (Throwable $t) {
            // The percentiles need window functions (MySQL 8 / MariaDB 10.2).
            // Without them p50/p95/p99 stay null and the report prints "—";
            // availability does not depend on them.
            error_log('[api.php action=sla_report] percentiles unavailable: ' . $t->getMessage());
        }

        // Availability in time, not in rows (bk_uptime_segments): a silent
        // agent's single 'down' row used to leave a three-day blackout at
        // ~99.99 %, and outageMinutes was the number of down ROWS.
        // Calendar days, today included: today live, the finished days from
        // the uptime_daily rollup - which also reaches past the 30 days the
        // raw logs are kept.
        $sla_time = bk_uptime_day_windows($pdo, array_map(fn ($m) => (int)$m['id'], $monitors), [$days]);

        // Check counts past the 30 days the raw logs are kept (W1-B2): "Rok"
        // counted the last month of rows under a one-year label. The finished
        // days come from the rollup, today from the logs - the same split the
        // percentages use. Up to 30 days the logs hold everything.
        $sla_checks = null;
        if ($days > 30) {
            $sla_checks = [];
            $stmt_ck = $pdo->prepare("
                SELECT monitor_id, SUM(checks_total) AS total, SUM(checks_up) AS up, SUM(checks_down) AS down
                FROM uptime_daily
                WHERE day >= ? AND day < CURDATE()
                GROUP BY monitor_id
            ");
            $stmt_ck->execute([date('Y-m-d', strtotime('-' . ($days - 1) . ' day', strtotime('today')))]);
            foreach ($stmt_ck->fetchAll() as $ck) {
                $sla_checks[(int)$ck['monitor_id']] = ['total' => (int)$ck['total'], 'up' => (int)$ck['up'], 'down' => (int)$ck['down']];
            }
            $stmt_ck_today = $pdo->query("
                SELECT monitor_id, COUNT(*) AS total, SUM(status = 'up') AS up, SUM(status = 'down') AS down
                FROM monitor_logs
                WHERE checked_at >= CURDATE() AND status IN ('up', 'down', 'warning')
                GROUP BY monitor_id
            ");
            foreach ($stmt_ck_today->fetchAll() as $ck) {
                $ck_mid = (int)$ck['monitor_id'];
                $sla_checks[$ck_mid] = [
                    'total' => ($sla_checks[$ck_mid]['total'] ?? 0) + (int)$ck['total'],
                    'up' => ($sla_checks[$ck_mid]['up'] ?? 0) + (int)$ck['up'],
                    'down' => ($sla_checks[$ck_mid]['down'] ?? 0) + (int)$ck['down'],
                ];
            }
        }
        // The first day with data across the report, against the window's
        // first day (windowStart): a year over seven weeks of history says so.
        $sla_since = null;

        $report = [];
        foreach ($monitors as $m) {
            $mid = (int)$m['id'];
            if ($sla_checks !== null) {
                $m['total_checks'] = $sla_checks[$mid]['total'] ?? 0;
                $m['up_checks'] = $sla_checks[$mid]['up'] ?? 0;
                $m['down_checks'] = $sla_checks[$mid]['down'] ?? 0;
            }
            $up = (int)$m['up_checks'];
            $down = (int)$m['down_checks'];
            $sla_sum = $sla_time[$mid][$days] ?? null;
            // A monitor without a single measured second in the window has no
            // SLA - null, not a perfect 100.0 in a report nobody measured.
            $uptimePct = $sla_sum['pct'] ?? null;
            $mon_since = $sla_sum['since'] ?? null;
            if ($mon_since !== null && ($sla_since === null || strcmp($mon_since, $sla_since) < 0)) {
                $sla_since = $mon_since;
            }

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

            $outageMinutes = $sla_sum !== null ? (int)round($sla_sum['outage'] / 60) : 0;
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
                // Of which the agent was silent - the outage nobody wrote a row for.
                'silentMinutes' => $sla_sum !== null ? (int)round($sla_sum['silent'] / 60) : 0,
                // Time nothing measured (cron stopped, an agent-side check's
                // agent went quiet): outside the percentage, and said so.
                'unmeasuredMinutes' => $sla_sum !== null ? (int)round($sla_sum['unmeasured'] / 60) : null,
                'since' => $mon_since,
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
            'days' => $days,
            'windowStart' => date('Y-m-d', strtotime('-' . ($days - 1) . ' day', strtotime('today'))),
            'since' => $sla_since,
            // Latency percentiles read raw logs, which are kept for 30 days.
            'percentileDays' => min($days, 30),
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
    // Admin only: the raw check history of every monitor. It used to answer
    // anyone, with error texts naming processes and internal targets.
    bk_require_login();
    if (!bk_viewer()['is_admin']) {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $limit = min(200, max(10, (int)($_GET['limit'] ?? 50)));

        // The same problem as action=events: the log tail covers only a few
        // dozen minutes, older error rows fall out of the window and the trail
        // then lies that all is OK. The latest down/warning rows are therefore
        // always mixed in.
        $audit_cols = "l.id, l.checked_at as time, l.monitor_id, l.status, l.response_time, l.error_message, m.name as monitor_name, m.type as monitor_type";
        [$al_active, $al_active_params] = bk_active_monitor_sql($pdo, 'l.monitor_id');
        $al_bind = function (PDOStatement $st, int $last_limit) use ($al_active_params): void {
            $pos = 1;
            foreach ($al_active_params as $al_param) {
                $st->bindValue($pos++, $al_param, PDO::PARAM_INT);
            }
            $st->bindValue($pos, $last_limit, PDO::PARAM_INT);
        };
        $stmt = $pdo->prepare("
            SELECT $audit_cols
            FROM monitor_logs l
            LEFT JOIN monitors m ON m.id = l.monitor_id
            WHERE {$al_active}
            ORDER BY l.id DESC
            LIMIT ?
        ");
        $al_bind($stmt, $limit);
        $stmt->execute();
        $recent_rows = $stmt->fetchAll();

        $stmt_fails = $pdo->prepare("
            SELECT $audit_cols
            FROM monitor_logs l
            LEFT JOIN monitors m ON m.id = l.monitor_id
            WHERE l.status IN ('down', 'warning') AND {$al_active}
            ORDER BY l.id DESC
            LIMIT ?
        ");
        $al_bind($stmt_fails, min(50, $limit));
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
        // logs: [] read as "nothing happened".
        bk_api_fail('audit_logs_unavailable', 500, $e, 'Protokol kontrol se nepodařilo načíst.');
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

    // ?previous=1 vrátí stejně dlouhé okno posunuté o jednu periodu zpět -
    // podklad pro srovnávací křivku v grafu ("je tohle na úterý normální?").
    // Posun je přesně jedna perioda, aby na sebe obě křivky seděly bod po bodu.
    $previous_window = !empty($_GET['previous']);
    $window_sql = $previous_window
        ? 'checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) AND checked_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        : 'checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)';
    $window_params = $previous_window ? [$minutes * 2, $minutes] : [$minutes];

    // Periods longer than the raw-data retention (30 days) read from the daily rollup
    // `metrics_daily`. 0 = the regular window over vps_metrics.
    $long_term_days = $period === '1y' ? 365 : ($period === '180d' ? 180 : ($period === '90d' ? 90 : 0));
    $daily_range = [];

    try {
        // The id itself wins over "some monitor of that asset". With OR and a bare
        // LIMIT 1, MySQL is free to return the sibling with the lower id, so an asset
        // with several monitors answered chart requests with another monitor's data.
        // Among the monitors this viewer may see: a user gets only their own;
        // anyone else's monitor answers like one that does not exist.
        $stmt_mon = bk_visible_monitor_stmt($pdo, $monitor_id, 'id');
        $real_id = $stmt_mon->fetchColumn();

        if (!$real_id) {
            echo json_encode(['points' => [], 'unit' => '', 'label' => 'Metrika', 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $points = [];
        if (($metric === 'response_time' || $metric === 'latency') && $long_term_days > 0) {
            // 90 days and a year from the daily rollup (W1-B2). The period
            // fell back to 1440 minutes here, so "1 rok" drew the last 24 hours.
            // A point is the day's average answer; the rollup keeps no minimum
            // or maximum for it, so the band stays empty rather than invented.
            // ?previous=1 shifts the window by one period, as the raw path does.
            $unit = 'ms';
            $label = 'Doba odezvy (HTTP/Ping)';
            $stmt = $pdo->prepare("
                SELECT UNIX_TIMESTAMP(day) AS ts, avg_response_ms, checks_total
                FROM uptime_daily
                WHERE monitor_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND day < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ORDER BY day ASC
            ");
            $stmt->execute($previous_window
                ? [$real_id, 2 * $long_term_days - 1, $long_term_days - 1]
                : [$real_id, $long_term_days - 1, -1]);
            foreach ($stmt->fetchAll() as $r) {
                if ($r['avg_response_ms'] === null) {
                    continue;
                }
                $points[] = [(int)$r['ts'], round((float)$r['avg_response_ms'], 2)];
                $daily_range[] = ['ts' => (int)$r['ts'], 'min' => null, 'max' => null, 'samples' => (int)$r['checks_total']];
            }
        } elseif ($metric === 'response_time' || $metric === 'latency') {
            $unit = 'ms';
            $label = 'Doba odezvy (HTTP/Ping)';
            $stmt = $pdo->prepare("
                SELECT UNIX_TIMESTAMP(checked_at) as ts, response_time as val
                FROM monitor_logs
                WHERE monitor_id = ? AND {$window_sql} AND response_time IS NOT NULL
                ORDER BY checked_at ASC
            ");
            $stmt->execute(array_merge([$real_id], $window_params));
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
            // A STEP metric is already the increment between two reports (the
            // ingest subtracted them), so a raw point needs no arithmetic. What
            // it does need is the right aggregation: the day of a step is the
            // SUM of its minutes, and an average would draw a day with 40
            // errors as "0.03 errors" - a chart nobody would ever look at twice.
            $is_step = !empty($def['step']);

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
                    if ($is_step) {
                        // The day's total, immune to the reset bug above: a lost
                        // report loses nothing, the next step spans it.
                        $points[] = [(int)$r['ts'], round((float)$r['avg_val'] * (int)$r['samples'], 2)];
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
                    WHERE monitor_id = ? AND {$window_sql} AND {$col} IS NOT NULL
                    ORDER BY checked_at ASC
                ");
                $stmt->execute(array_merge([$real_id], $window_params));

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
        // The same projection the batch endpoint sends, so the detail page can
        // draw where this is heading instead of only printing the number.
        $md_forecast = bk_days_to_full($pdo, (int)$real_id);
        if (isset($md_forecast[$metric])) {
            $series_payload['daysToFull'] = $md_forecast[$metric];
        }
        if ($long_term_days > 0) {
            // The client must be able to tell it is looking at daily averages,
            // not individual measurements - otherwise it would read precision into the chart the data does not have.
            $series_payload['resolution'] = 'daily';
            $series_payload['dailyRange'] = $daily_range;
        }
        echo json_encode($series_payload, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // points: [] with a 200 was the chart's "no data in the database".
        bk_api_fail('metric_series_unavailable', 500, $e, 'Metriku se nepodařilo načíst.');
    }
    exit;
}

// 2g1a. Hour-by-day heatmap of one metric over the raw-sample window.
//
// One cell = one hour of one day. The value is the hour's average (for
// counters the hour's increment, max - min, the same rule the daily rollup
// uses - the average of a counter means nothing). An hour with no sample is
// NULL and stays NULL all the way to the UI: an empty cell, never a
// fabricated zero, which for most metrics would read as "everything idle".
if ($action === 'metric_heatmap') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $metric = $_GET['metric'] ?? 'response_time';
    // Raw samples are pruned after 30 days - a longer window would silently
    // render as this same 30-day one, so the cap keeps the label honest.
    $hm_days = max(1, min(30, (int)($_GET['days'] ?? 30)));

    try {
        // Among the monitors this viewer may see: a user gets only their own;
        // anyone else's monitor answers like one that does not exist.
        $stmt_mon = bk_visible_monitor_stmt($pdo, $monitor_id, 'id');
        $real_id = $stmt_mon->fetchColumn();
        if (!$real_id) {
            echo json_encode(['days' => [], 'unit' => '', 'label' => '', 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($metric === 'response_time' || $metric === 'latency') {
            $unit = 'ms';
            $label = 'Doba odezvy (HTTP/Ping)';
            $stmt = $pdo->prepare("
                SELECT DATE(checked_at) AS d, HOUR(checked_at) AS h,
                       AVG(response_time) AS cell_val, COUNT(*) AS samples
                FROM monitor_logs
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND response_time IS NOT NULL
                GROUP BY d, h
            ");
            $stmt->execute([$real_id, $hm_days]);
        } elseif (isset($BK_METRIC_COLUMN_MAP[$metric])) {
            $def = $BK_METRIC_COLUMN_MAP[$metric];
            $col = $def['col'];
            $unit = $def['unit'];
            $label = $def['label'];
            $cell_expr = "AVG({$col})";
            if (!empty($def['counter'])) {
                $cell_expr = "MAX({$col}) - MIN({$col})";
                $label .= ' (přírůstek)';
            } elseif (!empty($def['step'])) {
                // Each row already holds one minute's increment: an hour of the
                // heatmap is their SUM, never their average.
                $cell_expr = "SUM({$col})";
            }
            $stmt = $pdo->prepare("
                SELECT DATE(checked_at) AS d, HOUR(checked_at) AS h,
                       {$cell_expr} AS cell_val, COUNT(*) AS samples
                FROM vps_metrics
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND {$col} IS NOT NULL
                GROUP BY d, h
            ");
            $stmt->execute([$real_id, $hm_days]);
        } else {
            echo json_encode(['days' => [], 'unit' => '', 'label' => '', 'error' => 'Neznámá metrika'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $by_day = [];
        foreach ($stmt->fetchAll() as $r) {
            $by_day[$r['d']][(int)$r['h']] = [
                'v' => round((float)$r['cell_val'], 2),
                'n' => (int)$r['samples'],
            ];
        }

        // A dense grid: every day of the window, all 24 hours. The client then
        // renders a gap as a gap without computing which days are missing -
        // and a day the agent slept through shows as a visibly empty row.
        // PHP's "today" and MySQL's DATE() must agree for the grid to line up;
        // both follow the server timezone (no per-user TZ exists here).
        $days_out = [];
        for ($i = $hm_days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $hours = [];
            $samples = [];
            for ($h = 0; $h < 24; $h++) {
                $cell = $by_day[$day][$h] ?? null;
                $hours[] = $cell !== null ? $cell['v'] : null;
                $samples[] = $cell !== null ? $cell['n'] : 0;
            }
            $days_out[] = ['day' => $day, 'hours' => $hours, 'samples' => $samples];
        }

        echo json_encode(['unit' => $unit, 'label' => $label, 'days' => $days_out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        bk_api_fail('metric_heatmap_unavailable', 500, $e, 'Heatmapu se nepodařilo načíst.');
    }
    exit;
}

// 2g1a2. Which other metrics of the same device moved with this one.
//
// The chart says CPU peaked at 19:40; "what was running" answers with
// processes. This answers with the device's OTHER metrics - the peak came
// with iowait, or with nothing at all.
//
// Only metrics stored in vps_metrics take part, and that is what makes the
// result trustworthy: they share one measurement row, so samples pair up
// exactly instead of being averaged into common time buckets. Averaging
// smooths both series and inflates the coefficient - a correlation nobody
// measured. response_time lives in monitor_logs and is therefore not offered.
if ($action === 'metric_correlations') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $metric = $_GET['metric'] ?? '';
    $period = $_GET['period'] ?? '24h';
    $minutes = bk_period_minutes($period) ?? 1440;
    // Raw samples only - the daily rollup keeps no per-metric alignment.
    $minutes = min($minutes, 30 * 24 * 60);
    $corr_min_pairs = 10;
    // Eight is what fits without turning the panel into a wall, but the rest
    // must be reachable: a metric below the cut looked simply absent ("where
    // is IPv4?"). `all=1` returns every comparison, including the ones whose
    // coefficient is undefined and their reason.
    $corr_top = !empty($_GET['all']) ? 100 : 8;

    if (!isset($BK_METRIC_COLUMN_MAP[$metric])) {
        echo json_encode([
            'correlations' => [],
            'error' => 'Korelace se počítají jen pro metriky hlášené agentem.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // Among the monitors this viewer may see: a user gets only their own;
        // anyone else's monitor answers like one that does not exist.
        $stmt_mon = bk_visible_monitor_stmt($pdo, $monitor_id, 'id, type');
        $mon_row = $stmt_mon->fetch();
        if (!$mon_row) {
            echo json_encode(['correlations' => [], 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $real_id = (int)$mon_row['id'];
        $mon_type = (string)($mon_row['type'] ?? '');

        // Candidates: every mapped metric except the target, minus the ones
        // restricted to another monitor type. The same-column check below is a
        // guard, not a live filter: today the only shared column
        // (ts_clients_online, read by ts_clients / discord_presence /
        // mc_players) is already split by 'only', so nothing reaches it. It
        // stays because an alias added without 'only' would otherwise show up
        // as a perfect correlation of a metric with itself under another name.
        $candidates = [];
        foreach ($BK_METRIC_COLUMN_MAP as $key => $def) {
            if ($key === $metric) {
                continue;
            }
            if (!empty($def['only']) && !in_array($mon_type, $def['only'], true)) {
                continue;
            }
            if ($def['col'] === $BK_METRIC_COLUMN_MAP[$metric]['col']) {
                continue;
            }
            $candidates[$key] = $def;
        }

        $columns = [$BK_METRIC_COLUMN_MAP[$metric]['col']];
        foreach ($candidates as $def) {
            $columns[] = $def['col'];
        }
        $columns = array_values(array_unique($columns));
        $select = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        $stmt = $pdo->prepare("
            SELECT {$select}
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY checked_at ASC
        ");
        $stmt->execute([$real_id, $minutes]);
        $rows = $stmt->fetchAll();

        $series = [];
        foreach ($columns as $col) {
            $series[$col] = array_map(fn($r) => $r[$col] === null ? null : (float)$r[$col], $rows);
        }

        $target_def = $BK_METRIC_COLUMN_MAP[$metric];
        $target = !empty($target_def['counter'])
            ? bk_counter_deltas($series[$target_def['col']])
            : $series[$target_def['col']];

        $out = [];
        foreach ($candidates as $key => $def) {
            $values = !empty($def['counter']) ? bk_counter_deltas($series[$def['col']]) : $series[$def['col']];
            $res = bk_pearson($target, $values, $corr_min_pairs);
            // A metric the agent never reported is absent, not uncorrelated -
            // listing it would fill the panel with rows about nothing.
            if ($res['pairs'] === 0) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $def['label'] . (!empty($def['counter']) ? ' (přírůstek)' : ''),
                'unit' => $def['unit'],
                'r' => $res['r'],
                'pairs' => $res['pairs'],
                'reason' => $res['reason'],
            ];
        }

        // Strongest relationship first, in either direction - a strong negative
        // correlation is every bit as interesting as a positive one. Rows
        // without a coefficient sink to the bottom instead of being hidden.
        usort($out, function ($a, $b) {
            $ra = $a['r'] === null ? -1 : abs($a['r']);
            $rb = $b['r'] === null ? -1 : abs($b['r']);
            return $rb <=> $ra;
        });

        $total = count($out);
        echo json_encode([
            'metric' => $metric,
            'label' => $target_def['label'],
            'samples' => count($rows),
            'minPairs' => $corr_min_pairs,
            'total' => $total,
            'correlations' => array_slice($out, 0, $corr_top),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        bk_api_fail('metric_correlations_unavailable', 500, $e, 'Korelace se nepodařilo spočítat.');
    }
    exit;
}

// 2g1c. Traffic split by link role - a router with an LTE backup: which bytes
// went over the primary line and which over the backup, and when the router
// was on the backup at all (wan_lost / wan_restored). See bk_get_link_traffic().
if ($action === 'link_traffic') {
    // Interface names and per-link traffic are a map of the network: only for
    // the users a monitor belongs to (and admins), never for anonymous visitors.
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $days = max(1, min(30, (int)($_GET['days'] ?? 30)));
    try {
        // The exact monitor id wins over "a monitor of that asset" - with
        // OR + LIMIT 1 alone a sibling with a lower id answered instead.
        // Among the monitors this viewer may see: a user gets only their own;
        // anyone else's monitor answers like one that does not exist.
        $stmt_mon = bk_visible_monitor_stmt($pdo, $monitor_id, 'id, type, last_details');
        $mon_row = $stmt_mon->fetch();
        if (!$mon_row) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $details = json_decode((string)($mon_row['last_details'] ?? ''), true);
        if (!is_array($details)) {
            $details = [];
        }
        $out = bk_get_link_traffic($pdo, (int)$mon_row['id'], $details, $days);
        $out['monitor'] = ['id' => (int)$mon_row['id'], 'type' => (string)$mon_row['type']];
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[api] link_traffic selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Chyba při načítání provozu podle linky'], JSON_UNESCAPED_UNICODE);
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
    bk_require_monitor_view($pdo, $monitor_id);
    $map = bk_metric_column_map();

    if (!isset($map[$metric]) && $metric !== 'response_time' && $metric !== 'latency') {
        http_response_code(404);
        echo json_encode(['error' => 'Neznámá metrika.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, name, type, target, port, asset_id, preset_id, cpu_threshold, ram_threshold, hdd_threshold
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
        // The vantage point of the last check - ONLY for the metrics the
        // monitoring server measures itself (response time). Everything else
        // is measured by the agent about its own machine, and monitor_logs
        // would hand back cron's own label ('Main Server'), which would claim
        // the router's CPU was measured from the hosting.
        $md_checked_from = null;
        if ($metric === 'response_time' || $metric === 'latency') {
            try {
                $stmt_cf = $pdo->prepare("SELECT checked_from FROM monitor_logs WHERE monitor_id = ? AND checked_from IS NOT NULL AND checked_from <> '' ORDER BY id DESC LIMIT 1");
                $stmt_cf->execute([(int)$mon['id']]);
                $cf = $stmt_cf->fetchColumn();
                $md_checked_from = ($cf === false || $cf === null || $cf === '') ? null : (string)$cf;
            } catch (Throwable $e) {
                error_log('[api.php action=metric_detail] checked_from lookup failed: ' . $e->getMessage());
            }
        }

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
                // What is actually being measured, and from where. A latency of
                // 6 ms says nothing until the page can name the target and the
                // vantage point - unknown stays null, never a guessed location.
                'target' => $mon['target'] !== null && $mon['target'] !== '' ? (string)$mon['target'] : null,
                'port' => $mon['port'] !== null ? (int)$mon['port'] : null,
                'checkedFrom' => $md_checked_from,
                'assetId' => $mon['asset_id'] !== null ? (int)$mon['asset_id'] : null,
            ],
            'metric' => [
                'key' => $metric,
                'label' => $def['label'],
                'unit' => $def['unit'],
                'counter' => !empty($def['counter']),
                // A step series is already an increment per report: a bucket is
                // the SUM of its minutes, so the app must not average it either.
                'step' => !empty($def['step']),
            ],
            // `warning` is DERIVED (the band below the configured limit), not
            // something an admin ever typed. The frontend words the two
            // differently, so it has to be able to tell them apart.
            'thresholdsDerived' => ['warning' => true, 'critical' => false],
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
    // Process names, PIDs and per-process usage describe what runs on the machine:
    // only for the users the monitor belongs to, and admins.
    bk_require_monitor_view($pdo, $monitor_id);

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
    $minutes = bk_period_minutes($period);
    if ($minutes === null) {
        // 90d/180d/1y live in the daily rollups, which only action=metric_series
        // reads. This answered them with the last 24 hours under the long label.
        http_response_code(400);
        echo json_encode([
            'error' => 'period_unsupported',
            'message' => 'Období delší než 30 dní čte action=metric_series (denní souhrny).',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // Among the monitors this viewer may see: a user gets only their own;
        // anyone else's monitor answers like one that does not exist.
        $stmt_mon = bk_visible_monitor_stmt($pdo, $monitor_id, 'id, type');
        $mon_row = $stmt_mon->fetch();
        $real_id = $mon_row['id'] ?? null;
        $mon_type = strtolower((string)($mon_row['type'] ?? ''));

        if (!$real_id) {
            echo json_encode(['series' => [], 'error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $series = [];
        $days_to_full = bk_days_to_full($pdo, (int)$real_id);

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
            // Days until this metric reaches 100 %, where that is a real
            // projection. The chart card has always had a badge for it and
            // never a number to put in it. Absent = no forecast, never a zero.
            if (isset($days_to_full[$metric_key])) {
                $series[$metric_key]['daysToFull'] = $days_to_full[$metric_key];
            }
        }

        echo json_encode(['series' => $series], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // series: [] with a 200 made every chart on the asset page say "no data".
        bk_api_fail('metric_series_unavailable', 500, $e, 'Grafy se nepodařilo načíst.');
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
        bk_api_fail('send_digest_failed', 500, $e, 'Digest se nepodařilo odeslat.');
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
        // Alerts only about monitors this account may see.
        [$gs_scope, $gs_scope_params] = bk_list_scope_sql($pdo, bk_visible_monitor_ids($pdo), 'id');
        $stmt_mon = $pdo->prepare("SELECT id, name, type FROM monitors WHERE {$gs_scope} ORDER BY id ASC");
        $stmt_mon->execute($gs_scope_params);
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
        // [] read as "subscribed to nothing", and saving that form would say so.
        bk_api_fail('subscriptions_unavailable', 500, $e, 'Odběry se nepodařilo načíst.');
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
            // A subscription to someone else's monitor would mail its name and
            // outage reasons to an account that may not see it.
            if ($mid <= 0 || !bk_can_view_monitor($pdo, $mid)) continue;
            $stmt_ins->execute([$user_id, $mid, (int)($s['email'] ?? 0), (int)($s['sms'] ?? 0), (int)($s['whatsapp'] ?? 0)]);
        }
        $pdo->commit();
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        error_log('[save_subscriptions] ' . $e->getMessage());
        echo json_encode(['error' => 'Odběry se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
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
            // Explicitly, not only through the foreign key: an install whose
            // table was created without it must not keep access rows of a deleted account.
            $pdo->prepare("DELETE FROM monitor_users WHERE user_id = ?")->execute([$du_id]);
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
    // Which monitors this account may see. A monitor can belong to several
    // users. Absent = keep the current assignment (a client that does not send
    // it must not wipe it); an empty list removes every assignment.
    $su_monitor_ids = null;
    if (array_key_exists('monitorIds', $su_input)) {
        $su_monitor_ids = array_values(array_unique(array_filter(
            array_map('intval', (array)$su_input['monitorIds']),
            fn($v) => $v > 0
        )));
    }
    // Replaces the account's assignments inside the caller's transaction. Only
    // ids of existing monitors are stored. Returns the stored count, or null
    // when the request did not touch assignments.
    $su_assign = function (int $uid) use ($pdo, $su_monitor_ids): ?int {
        if ($su_monitor_ids === null) {
            return null;
        }
        // The access editor lists only live monitors, so what it sends cannot
        // speak for archived ones: their assignments stay as they were.
        $pdo->prepare("DELETE FROM monitor_users WHERE user_id = ? AND monitor_id NOT IN (SELECT id FROM monitors WHERE archived_at IS NOT NULL)")->execute([$uid]);
        if (!$su_monitor_ids) {
            return 0;
        }
        [$su_scope, $su_scope_params] = bk_monitor_scope_sql($su_monitor_ids, 'id');
        $stmt_assign = $pdo->prepare("INSERT INTO monitor_users (monitor_id, user_id) SELECT id, ? FROM monitors WHERE archived_at IS NULL AND " . $su_scope);
        $stmt_assign->execute(array_merge([$uid], $su_scope_params));
        return $stmt_assign->rowCount();
    };

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
            $pdo->beginTransaction();
            if ($su_password !== '') {
                $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ?, password_hash = ? WHERE id = ?")
                    ->execute([$su_username, $su_email, $su_phone, $su_role, password_hash($su_password, PASSWORD_BCRYPT), $su_id]);
            } else {
                $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ? WHERE id = ?")
                    ->execute([$su_username, $su_email, $su_phone, $su_role, $su_id]);
            }
            $su_assigned = $su_assign($su_id);
            $pdo->commit();
            $su_changes = [];
            if ($su_old['username'] !== $su_username) $su_changes[] = "jméno {$su_old['username']} -> {$su_username}";
            if ($su_old['email'] !== $su_email) $su_changes[] = "e-mail {$su_old['email']} -> {$su_email}";
            if ((string)$su_old['phone'] !== $su_phone) $su_changes[] = 'telefon změněn';
            if ($su_old['role'] !== $su_role) $su_changes[] = "role {$su_old['role']} -> {$su_role}";
            if ($su_password !== '') $su_changes[] = 'heslo nastaveno adminem';
            if ($su_assigned !== null) $su_changes[] = "přístup k monitorům: {$su_assigned}";
            bk_audit_log($pdo, 'user_updated', $su_username . (!empty($su_changes) ? ' (' . implode(', ', $su_changes) . ')' : ' (beze změny)'), 'user', $su_id);
            echo json_encode(['success' => true, 'id' => $su_id], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($su_password !== '') {
            // The admin typed the password by hand - no invitation.
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)")
                ->execute([$su_username, $su_email, $su_phone, $su_role, password_hash($su_password, PASSWORD_BCRYPT)]);
            $su_new_id = (int)$pdo->lastInsertId();
            $su_assign($su_new_id);
            $pdo->commit();
            bk_audit_log($pdo, 'user_created', $su_username . ' (' . $su_role . ', heslo nastaveno adminem)', 'user', $su_new_id);
            echo json_encode(['success' => true, 'id' => $su_new_id, 'invited' => false], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Without a password: a placeholder hash matching no plaintext, and an
        // invitation link - the admin never knows the user's password.
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)")
            ->execute([$su_username, $su_email, $su_phone, $su_role, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)]);
        $su_new_id = (int)$pdo->lastInsertId();
        $su_assign($su_new_id);
        $pdo->commit();
        $su_token = bk_issue_password_reset_token($pdo, $su_new_id);
        $su_link = $default_origin . '/app/set-password?token=' . $su_token;
        $su_site = get_setting('site_title', 'Blood Kings');
        $su_body = '<h1>Vítejte v ' . htmlspecialchars($su_site) . '</h1>'
            . '<p>Byl pro vás vytvořen účet <strong>' . htmlspecialchars($su_username) . '</strong>. Nastavte si prosím heslo kliknutím na odkaz níže (platnost 48 hodin):</p>'
            . '<p><a href="' . htmlspecialchars($su_link) . '">' . htmlspecialchars($su_link) . '</a></p>';
        bk_audit_log($pdo, 'user_created', $su_username . ' (' . $su_role . ', pozvánka e-mailem)', 'user', $su_new_id);
        $su_sent = send_email($su_email, 'Nastavení hesla - ' . $su_site, $su_body, [], [
            'kind' => 'invitation',
        ]);
        // invited=false when the mail fails: the UI then truthfully says "account
        // created, but the invitation did not go out" instead of a lying "invitation sent".
        echo json_encode(['success' => true, 'id' => $su_new_id, 'invited' => (bool)$su_sent], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
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
    // Admin only: every account's e-mail, phone and 2FA state. A non-admin
    // needs at most their own row, which my_profile already returns.
    if (($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt = $pdo->query("SELECT id, username, email, phone, role, totp_enabled, oauth_provider, created_at FROM users ORDER BY id ASC");
        $rows = $stmt->fetchAll();
        // Assigned monitors per account, for the admin's access editor.
        $assigned_by_user = [];
        try {
            foreach ($pdo->query("SELECT user_id, monitor_id FROM monitor_users ORDER BY monitor_id")->fetchAll() as $mu) {
                $assigned_by_user[(int)$mu['user_id']][] = (int)$mu['monitor_id'];
            }
        } catch (PDOException $e) {
            error_log('[users] monitor_users unavailable: ' . $e->getMessage());
        }
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
                'monitorIds' => $assigned_by_user[(int)$u['id']] ?? [],
            ];
        }
        echo json_encode(['users' => $users], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        bk_api_fail('users_unavailable', 500, $e, 'Seznam uživatelů se nepodařilo načíst.');
    }
    exit;
}

// 4. Historie metrik pro grafy
if ($action === 'metrics_history') {
    $monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $period = $_GET['period'] ?? '24h';
    // One monitor for the users it belongs to; monitor_id=0 averages every
    // agent in the fleet, which only an admin may see.
    if ($monitor_id > 0) {
        bk_require_monitor_view($pdo, $monitor_id);
    } elseif (!bk_viewer()['is_admin']) {
        bk_require_login();
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

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
    } catch (Throwable $e) {
        // The zero averages of the empty $result above went out as measurements.
        bk_api_fail('metrics_history_unavailable', 500, $e, 'Historii metrik se nepodařilo načíst.');
    }

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
        // The public view covers the public set (W1-G3), the same for everyone.
        // Inside the app a user's dashboard counts only the monitors assigned to them.
        $ps_visible = bk_request_monitor_ids($pdo);
        [$ps_scope, $ps_params] = bk_list_scope_sql($pdo, $ps_visible, 'id');
        [$ps_log_scope, $ps_log_params] = bk_list_scope_sql($pdo, $ps_visible, 'monitor_id');
        [$ps_m_scope, $ps_m_params] = bk_list_scope_sql($pdo, $ps_visible, 'm.id');
        $stmt_rows = $pdo->prepare("SELECT id, type, status, maintenance, last_checked FROM monitors WHERE {$ps_scope}");
        $stmt_rows->execute($ps_params);
        $ps_rows = $stmt_rows->fetchAll();
        $total_monitors = count($ps_rows);

        // One verdict, the same one the fleet badge prints (W1-B4). It used to
        // be "healthy unless something is down": a degraded monitor, an
        // unknown one and a stopped collector all read as all-clear.
        $ps_verdict = bk_overall_verdict($ps_rows, bk_collection_is_fresh());
        $ps_counts = $ps_verdict['counts'];

        // The newest real check, or null. It fell back to date('c'), so a set
        // nobody had measured looked checked a second ago.
        $ps_last = null;
        foreach ($ps_rows as $ps_row) {
            if (!empty($ps_row['last_checked']) && ($ps_last === null || strcmp((string)$ps_row['last_checked'], $ps_last) > 0)) {
                $ps_last = (string)$ps_row['last_checked'];
            }
        }

        // null (not 100.0) until real data says otherwise - a fresh install
        // or a dead cron with zero logged checks isn't "100% uptime", it's
        // unmeasured, and the frontend needs to tell those two apart.
        $avg_uptime = null;
        // No inner catches in this action any more: an empty node list or a
        // null uptime after a failed read looked like a real answer. Any
        // failure now reaches the outer catch and answers 500.
        // In time, not in rows (bk_uptime_segments) - the same number the
        // badge and the SLA report print for each monitor.
        $uptime_values = [];
        foreach (bk_uptime_day_windows($pdo, array_map(fn($r) => (int)$r['id'], $ps_rows), [30]) as $ps_win) {
            if ($ps_win[30]['pct'] !== null) {
                $uptime_values[] = $ps_win[30]['pct'];
            }
        }
        if (!empty($uptime_values)) {
            $avg_uptime = round(array_sum($uptime_values) / count($uptime_values), 3);
        }

        // Nodes are the machines that report on their own (agents) and the
        // servers probed as hosts - not every monitor that happens to carry
        // details: websites and Discord were listed as "nodes" through that.
        // response_time is not a monitors column - the latest value comes from
        // monitor_logs. No name/outage fallback: with no real nodes an empty
        // list is returned, not a hardcoded "Donald"/"Router - Praha".
        $nodes = [];
        $stmt_nodes = $pdo->prepare("
            SELECT m.name, m.status, m.maintenance,
                   (SELECT l.response_time FROM monitor_logs l WHERE l.monitor_id = m.id AND l.response_time IS NOT NULL ORDER BY l.id DESC LIMIT 1) AS response_time
            FROM monitors m
            WHERE LOWER(m.type) IN ('agent', 'vps', 'openwrt', 'teamspeak', 'node', 'router')
              AND {$ps_m_scope}
        ");
        $stmt_nodes->execute($ps_m_params);
        $ps_node_states = ['up' => 'online', 'warning' => 'warning', 'down' => 'offline', 'maintenance' => 'maintenance'];
        while ($nd = $stmt_nodes->fetch()) {
            $nd_status = !empty($nd['maintenance']) ? 'maintenance' : strtolower((string)$nd['status']);
            $nodes[] = [
                'name' => $nd['name'],
                // Unknown and maintenance keep their own words: both used to
                // become "offline", a red dot for a server nobody had checked yet.
                'status' => $ps_node_states[$nd_status] ?? 'unknown',
                'latencyMs' => $nd['response_time'] !== null ? (int)$nd['response_time'] : null,
            ];
        }

        // null until a real measurement exists - "10 ms" as a fabricated
        // default would misrepresent actual latency the same way the old
        // 100% uptime default misrepresented actual availability.
        $avg_latency = null;
        $stmt_latency = $pdo->prepare("
            SELECT AVG(response_time) as avg_latency
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) AND response_time > 0 AND {$ps_log_scope}
        ");
        $stmt_latency->execute($ps_log_params);
        $lat_row = $stmt_latency->fetch() ?: null;
        if ($lat_row && isset($lat_row['avg_latency']) && $lat_row['avg_latency'] !== null) {
            $avg_latency = (int)round($lat_row['avg_latency']);
        }

        $agents_online = count(array_filter($nodes, fn($n) => $n['status'] === 'online'));
        $agents_total = count($nodes);

        echo json_encode([
            // healthy | degraded | down | maintenance | unknown (bk_overall_verdict)
            'status' => $ps_verdict['verdict'],
            'uptimePercent' => $avg_uptime,
            'totalMonitors' => $total_monitors,
            'downMonitors' => $ps_counts['down'],
            'warningMonitors' => $ps_counts['warning'],
            'unknownMonitors' => $ps_counts['unknown'],
            'unmeasuredMonitors' => $ps_counts['unmeasured'],
            'maintenanceMonitors' => $ps_counts['maintenance'],
            'agentsOnline' => $agents_online,
            'agentsTotal' => $agents_total,
            'avgLatencyMs' => $avg_latency,
            'lastUpdated' => $ps_last !== null ? date('c', (int)strtotime($ps_last)) : null,
            'nodes' => $nodes,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        // Never return an invented "healthy" state on error - the client must see
        // that the infrastructure state could not be determined, not a false "all OK".
        error_log('[api] public_status failed: ' . $e->getMessage());
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
    bk_require_monitor_view($pdo, $sp_monitor_id);

    try {
        $stmt = $pdo->prepare("
            SELECT measured_at, download_mbps, upload_mbps, ping_ms, jitter_ms, server_name,
                   source, iface, tool, link_mbit
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
                // Who ran it (WAN 3.3): the router's own nightly test or the
                // agent's probe. The column is the old `source`, whose
                // 'librespeed' constant the migration rewrote to 'turris'.
                'startedBy' => in_array($r['source'] ?? null, ['turris', 'agent'], true) ? $r['source'] : null,
                'iface' => $r['iface'] ?? null,
                'tool' => $r['tool'] ?? null,
                'linkMbit' => $r['link_mbit'] !== null ? (int)$r['link_mbit'] : null,
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
 * What the week says about one router (CORE 3.9).
 *
 * The SAME engine the Monday e-mail runs, in the language of the request: the
 * page and the e-mail must never tell the owner two different stories, so the
 * texts are the server's and the app renders them as they arrive.
 *
 * Read-only on purpose. The digest owns the snapshot (`router_rec_state`) -
 * if a page load wrote it, opening the router on Sunday evening would make
 * Monday's e-mail call every open item "unchanged since last week".
 */
if ($action === 'router_recommendations') {
    $rr_monitor_id = (int)($_GET['monitor_id'] ?? 0);
    bk_require_monitor_view($pdo, $rr_monitor_id);
    try {
        $rr_stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ?");
        $rr_stmt->execute([$rr_monitor_id]);
        $rr_monitor = $rr_stmt->fetch();
        if (!$rr_monitor) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rr_details = json_decode((string)($rr_monitor['last_details'] ?? ''), true);
        if (!is_array($rr_details)) {
            $rr_details = [];
        }
        $rr_in = bk_router_rec_inputs($pdo, $rr_monitor, $rr_details, date('Y-m-d'));
        $rr_res = bk_router_rec_evaluate($rr_in);
        $rr_state = is_array($rr_in['state'] ?? null) ? $rr_in['state'] : [];
        $rr_split = bk_router_rec_split($rr_res['items'], $rr_state);

        $rr_items = [];
        foreach ($rr_split['items'] as $rr_item) {
            $rr_items[] = bk_rec_item_json($rr_item, $rr_state[(string)$rr_item['key']] ?? null);
        }
        $rr_muted = [];
        $rr_seen = [];
        foreach ($rr_split['muted'] as $rr_item) {
            $rr_key = (string)$rr_item['key'];
            $rr_seen[$rr_key] = true;
            $rr_muted[] = bk_rec_item_json($rr_item, $rr_state[$rr_key] ?? null);
        }
        // A mute whose rule no longer fires: listed with null texts and
        // `active: false`, or the owner could never take the mute back.
        foreach ($rr_state as $rr_key => $rr_row) {
            if (empty($rr_row['muted_at']) || isset($rr_seen[(string)$rr_key])) {
                continue;
            }
            $rr_id = (string)($rr_row['rule_id'] ?? '');
            $rr_muted[] = bk_rec_item_json([
                'id' => $rr_id,
                'key' => (string)$rr_key,
                'area' => bk_rec_area_of($rr_id),
                'severity' => (string)($rr_row['muted_severity'] ?? 'info'),
                'subject' => ['kind' => 'router'],
                'params' => [],
                'openSince' => $rr_row['first_seen'] ?? null,
            ], $rr_row, false);
        }

        $rr_window = null;
        if ($rr_res['applicable']) {
            $rr_days = $rr_in['window']['days'] ?? [];
            $rr_prev = $rr_in['window']['prev_days'] ?? [];
            $rr_window = [
                'from' => $rr_days[0] ?? null,
                'to' => $rr_days === [] ? null : $rr_days[count($rr_days) - 1],
                'previousFrom' => $rr_prev[0] ?? null,
                'previousTo' => $rr_prev === [] ? null : $rr_prev[count($rr_prev) - 1],
                'daysWithData' => (int)$rr_res['days_with_data'],
            ];
        }
        echo json_encode([
            'monitorId' => $rr_monitor_id,
            'applicable' => (bool)$rr_res['applicable'],
            'reason' => $rr_res['reason'],
            'generatedAt' => date('c'),
            'window' => $rr_window,
            // Muting is an admin decision: it silences a finding for everybody
            // who can see the router, not just for the person clicking.
            'canMute' => ($_SESSION['admin_role'] ?? '') === 'admin',
            'missingPackages' => bk_rec_missing_packages($rr_details['agent_tools'] ?? null),
            'items' => $rr_items,
            'muted' => $rr_muted,
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] router_recommendations selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Doporučení pro router se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * "I know about this one" - mute or unmute a single recommendation (CORE 3.9).
 *
 * A mute is per router and per key and remembers the severity it was made at,
 * so it silences the finding as it is today and NOT a worse version of it: a
 * disk muted at "warm" comes back the week it starts failing. That is why the
 * engine is run here instead of trusting a severity from the request body -
 * the client must not be able to decide how loud a finding has to get before
 * it is heard again.
 */
if ($action === 'router_recommendation_mute') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rm_input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($rm_input)) {
        $rm_input = $_POST;
    }
    $rm_monitor_id = (int)($rm_input['monitor_id'] ?? 0);
    $rm_key = trim((string)($rm_input['key'] ?? ''));
    $rm_on = !empty($rm_input['muted']);
    $rm_reason = mb_substr(trim((string)($rm_input['reason'] ?? '')), 0, 255);
    bk_require_monitor_view($pdo, $rm_monitor_id);
    bk_refuse_archived_write($pdo, $rm_monitor_id);

    // The key is stored and later matched against what the engine produces, so
    // it is validated the same way twice: shape, and a rule id that exists.
    $rm_rule = explode(':', $rm_key, 2)[0];
    if (!preg_match('/^[a-z0-9_]{3,40}(:[A-Za-z0-9._:-]{1,39})?$/', $rm_key)
        || !in_array($rm_rule, bk_router_rec_thresholds()['rank'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Neznámý klíč doporučení.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $rm_stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ?");
        $rm_stmt->execute([$rm_monitor_id]);
        $rm_monitor = $rm_stmt->fetch();
        if (!$rm_monitor) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rm_mute = null;
        if ($rm_on) {
            $rm_details = json_decode((string)($rm_monitor['last_details'] ?? ''), true);
            $rm_in = bk_router_rec_inputs($pdo, $rm_monitor, is_array($rm_details) ? $rm_details : [], date('Y-m-d'));
            $rm_res = bk_router_rec_evaluate($rm_in);
            // Not firing right now = `info` (CORE 3.9): the mute then hides
            // nothing worse than the mildest degree, and anything above it
            // comes straight back.
            $rm_severity = 'info';
            foreach ($rm_res['items'] as $rm_item) {
                if ((string)$rm_item['key'] === $rm_key) {
                    $rm_severity = (string)$rm_item['severity'];
                    break;
                }
            }
            $rm_by = (string)($_SESSION['admin_username'] ?? '');
            $rm_now = date('Y-m-d H:i:s');
            $pdo->prepare("
                INSERT INTO router_rec_state
                    (monitor_id, rec_key, rule_id, active, severity, first_seen, last_seen,
                     muted_at, muted_by, muted_severity, mute_reason)
                VALUES (?, ?, ?, 0, NULL, NULL, NULL, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    muted_at = VALUES(muted_at), muted_by = VALUES(muted_by),
                    muted_severity = VALUES(muted_severity), mute_reason = VALUES(mute_reason)
            ")->execute([$rm_monitor_id, $rm_key, $rm_rule, $rm_now, $rm_by, $rm_severity,
                $rm_reason !== '' ? $rm_reason : null]);
            $rm_mute = ['at' => $rm_now, 'by' => $rm_by,
                'reason' => $rm_reason !== '' ? $rm_reason : null, 'severity' => $rm_severity];
        } else {
            $pdo->prepare("
                UPDATE router_rec_state
                SET muted_at = NULL, muted_by = NULL, muted_severity = NULL, mute_reason = NULL
                WHERE monitor_id = ? AND rec_key = ?
            ")->execute([$rm_monitor_id, $rm_key]);
        }
        bk_audit_log($pdo, $rm_on ? 'router_rec_mute' : 'router_rec_unmute',
            $rm_key . ($rm_reason !== '' ? ': ' . $rm_reason : ''), 'monitor', $rm_monitor_id);
        echo json_encode(['ok' => true, 'key' => $rm_key, 'muted' => $rm_on, 'mute' => $rm_mute],
            JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] router_recommendation_mute selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Změnu se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * The daily SMART history of a router's disks (CORE 3.9).
 *
 * `last_details` carries only the newest reading and the next report
 * overwrites it, so "did the bad blocks grow since spring" has no answer
 * without this table. A day with no reading has no row and comes back as a
 * gap - never as a zero, which would draw a disk that cooled to 0 °C.
 *
 * No serial number or WWN can appear here: the disk is identified by
 * `disk_key`, a hash of transport, port, model and size, and the tables have
 * no column to put an identifier in.
 */
if ($action === 'storage_history') {
    $sh_monitor_id = (int)($_GET['monitor_id'] ?? 0);
    $sh_days = max(1, min(400, (int)($_GET['days'] ?? 90)));
    bk_require_monitor_view($pdo, $sh_monitor_id);

    try {
        $sh_stmt = $pdo->prepare("
            SELECT id, disk_key, name, transport, port, model, smart_model, size_bytes,
                   rotational, first_seen, last_seen, replaced_at,
                   (replaced_at IS NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 3 HOUR)) AS present
            FROM storage_disks
            WHERE monitor_id = ?
            ORDER BY name
        ");
        $sh_stmt->execute([$sh_monitor_id]);
        $sh_disks = $sh_stmt->fetchAll();

        $sh_daily = [];
        if ($sh_disks !== []) {
            $sh_ids = array_map(fn (array $d): int => (int)$d['id'], $sh_disks);
            $sh_in = implode(',', array_fill(0, count($sh_ids), '?'));
            $sh_rows = $pdo->prepare("
                SELECT * FROM storage_disk_daily
                WHERE disk_id IN ({$sh_in}) AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                ORDER BY day
            ");
            $sh_rows->execute(array_merge($sh_ids, [$sh_days]));
            foreach ($sh_rows->fetchAll() as $sh_row) {
                $sh_daily[(int)$sh_row['disk_id']][] = $sh_row;
            }
        }

        // NULL stays NULL through every conversion below: a counter the drive
        // does not implement is not a counter at zero.
        $sh_int = fn ($v): ?int => $v === null ? null : (int)$v;
        $sh_out = [];
        foreach ($sh_disks as $sh_disk) {
            $sh_list = [];
            foreach ($sh_daily[(int)$sh_disk['id']] ?? [] as $r) {
                $sh_temp_n = $sh_int($r['temp_n']);
                $sh_list[] = [
                    'day' => $r['day'],
                    'samples' => (int)$r['samples'],
                    'smartPassed' => $r['smart_passed'] === null ? null : (bool)$r['smart_passed'],
                    'tempMin' => $sh_int($r['temp_min']),
                    'tempAvg' => ($sh_temp_n !== null && $sh_temp_n > 0 && $r['temp_sum'] !== null)
                        ? round((float)$r['temp_sum'] / $sh_temp_n, 1) : null,
                    'tempMax' => $sh_int($r['temp_max']),
                    'powerOnHours' => $sh_int($r['power_on_hours']),
                    'powerCycles' => $sh_int($r['power_cycles']),
                    'unsafeShutdowns' => $sh_int($r['unsafe_shutdowns']),
                    'reallocated' => $sh_int($r['reallocated_sectors']),
                    'pending' => $sh_int($r['pending_sectors']),
                    'offlineUncorrectable' => $sh_int($r['offline_uncorrectable']),
                    'reportedUncorrect' => $sh_int($r['reported_uncorrect']),
                    'crcErrors' => $sh_int($r['crc_errors']),
                    'runtimeBadBlocks' => $sh_int($r['runtime_bad_blocks']),
                    'mediaErrors' => $sh_int($r['media_errors']),
                    'errorLogCount' => $sh_int($r['error_log_count']),
                    'wearPct' => $sh_int($r['wear_pct']),
                    'emmcLife' => $sh_int($r['emmc_life']),
                    'writtenBytes' => $sh_int($r['written_bytes']),
                    'hostWrittenBytes' => $sh_int($r['host_written_bytes']),
                    'hostWrittenPartial' => (bool)$r['host_written_partial'],
                ];
            }
            $sh_out[] = [
                'key' => $sh_disk['disk_key'],
                'name' => $sh_disk['name'],
                'transport' => $sh_disk['transport'],
                'port' => $sh_disk['port'],
                'model' => $sh_disk['model'],
                'smartModel' => $sh_disk['smart_model'],
                'sizeBytes' => $sh_int($sh_disk['size_bytes']),
                'rotational' => $sh_disk['rotational'] === null ? null : (bool)$sh_disk['rotational'],
                'firstSeen' => $sh_disk['first_seen'],
                'lastSeen' => $sh_disk['last_seen'],
                'replacedAt' => $sh_disk['replaced_at'],
                'present' => (bool)$sh_disk['present'],
                'daily' => $sh_list,
            ];
        }
        echo json_encode(['monitorId' => $sh_monitor_id, 'days' => $sh_days, 'disks' => $sh_out],
            JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] storage_history selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Historii disků se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * Where the WAN line is limited, as the server classified it (WAN 3.4, 3.6).
 *
 * The classifier is PHP and only PHP: the card renders the class and the
 * reason it was given and never re-derives one, so the page and the weekly
 * e-mail cannot drift apart. Everything it cannot prove comes back as
 * `inconclusive` with the reason - never as a softer claim.
 */
if ($action === 'wan_bottleneck') {
    $wb_monitor_id = (int)($_GET['monitor_id'] ?? 0);
    bk_require_monitor_view($pdo, $wb_monitor_id);

    try {
        $wb_stmt = $pdo->prepare("
            SELECT id, last_details, wan_plan_down_mbit, wan_plan_up_mbit, wan_plan_ok_pct, wan_probe_enabled
            FROM monitors WHERE id = ?
        ");
        $wb_stmt->execute([$wb_monitor_id]);
        $wb_monitor = $wb_stmt->fetch();
        if (!$wb_monitor) {
            http_response_code(404);
            echo json_encode(['error' => 'Monitor nenalezen.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $wb_details = json_decode((string)($wb_monitor['last_details'] ?? ''), true);
        if (!is_array($wb_details)) {
            $wb_details = [];
        }
        $wb_path = is_array($wb_details['wan_path'] ?? null) ? $wb_details['wan_path'] : null;
        $wb_tools = is_array($wb_details['agent_tools'] ?? null) ? $wb_details['agent_tools'] : [];
        $wb_int = fn ($v): ?int => $v === null ? null : (int)$v;

        // More than the three shown: a probe that was gated (background
        // traffic, an unverified path) does not count towards the three the
        // aggregate stands on, so the classifier has to be able to look past it.
        $wb_agent = $pdo->prepare("
            SELECT id, measured_at, download_mbps, upload_mbps, server_name, source, link_mbit, diagnostics
            FROM speedtest_results
            WHERE monitor_id = ? AND source = 'agent' AND measured_at >= DATE_SUB(NOW(), INTERVAL 21 DAY)
            ORDER BY measured_at DESC LIMIT 10
        ");
        $wb_agent->execute([$wb_monitor_id]);
        $wb_agent_rows = $wb_agent->fetchAll();

        $wb_turris = $pdo->prepare("
            SELECT id, measured_at, download_mbps, upload_mbps, server_name, source, link_mbit, diagnostics
            FROM speedtest_results
            WHERE monitor_id = ? AND (source IS NULL OR source <> 'agent')
            ORDER BY measured_at DESC LIMIT 1
        ");
        $wb_turris->execute([$wb_monitor_id]);
        $wb_turris_rows = $wb_turris->fetchAll();

        // Server capability is observed, never assumed (WAN 3.4): the Turris
        // list publishes no capacity. Only the maximum this server has ever
        // delivered leaves the query - no other account's router and no other
        // account's value.
        $wb_server_max = [];
        $wb_names = [];
        foreach ($wb_agent_rows as $r) {
            if (($r['server_name'] ?? '') !== '') {
                $wb_names[(string)$r['server_name']] = true;
            }
        }
        if ($wb_names !== []) {
            $wb_in = implode(',', array_fill(0, count($wb_names), '?'));
            $wb_cap = $pdo->prepare("
                SELECT server_name, MAX(download_mbps) AS dl, MAX(upload_mbps) AS ul
                FROM speedtest_results
                WHERE server_name IN ({$wb_in}) AND measured_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY server_name
            ");
            $wb_cap->execute(array_keys($wb_names));
            foreach ($wb_cap->fetchAll() as $r) {
                $wb_server_max[(string)$r['server_name']] = [
                    'dl' => $r['dl'] === null ? null : (float)$r['dl'],
                    'ul' => $r['ul'] === null ? null : (float)$r['ul'],
                ];
            }
        }

        $wb_ctx = [
            'plan_down' => $wb_int($wb_monitor['wan_plan_down_mbit']),
            'plan_up' => $wb_int($wb_monitor['wan_plan_up_mbit']),
            'plan_ok_pct' => $wb_int($wb_monitor['wan_plan_ok_pct']),
            'threaded_napi' => is_bool($wb_path['wan_threaded_napi'] ?? null) ? $wb_path['wan_threaded_napi'] : null,
            'server_max' => $wb_server_max,
            'now' => time(),
        ];

        $wb_tests = [];
        foreach (array_merge(array_slice($wb_agent_rows, 0, 3), $wb_turris_rows) as $r) {
            $wb_diag = json_decode((string)($r['diagnostics'] ?? ''), true);
            $wb_tests[] = [
                'measuredAt' => $r['measured_at'],
                'startedBy' => in_array($r['source'] ?? null, ['turris', 'agent'], true) ? $r['source'] : null,
                'server' => $r['server_name'],
                'downloadMbps' => $r['download_mbps'] === null ? null : round((float)$r['download_mbps'], 2),
                'uploadMbps' => $r['upload_mbps'] === null ? null : round((float)$r['upload_mbps'], 2),
                'linkMbit' => $wb_int($r['link_mbit']),
                'verdict' => bk_wan_test_verdict($r, $wb_ctx),
                'diagnostics' => is_array($wb_diag) ? $wb_diag : null,
            ];
        }

        echo json_encode([
            'monitorId' => $wb_monitor_id,
            'generatedAt' => date('c'),
            'canEdit' => ($_SESSION['admin_role'] ?? '') === 'admin',
            'plan' => [
                'downMbit' => $wb_ctx['plan_down'],
                'upMbit' => $wb_ctx['plan_up'],
                'okPct' => $wb_ctx['plan_ok_pct'],
            ],
            // The router's own probe is not built in this release (wave 2).
            // The keys travel so the card has one shape to render and the
            // agent one shape to meet; `enabledServer` is the stored consent.
            'probe' => [
                'enabledServer' => (bool)$wb_monitor['wan_probe_enabled'],
                'state' => is_array($wb_details['wan_probe_state'] ?? null) ? $wb_details['wan_probe_state'] : null,
                'waitSince' => $wb_details['wan_probe_wait_since'] ?? null,
                'budgetSpent' => false,
            ],
            'verdict' => bk_wan_bottleneck($wb_agent_rows, $wb_ctx),
            'tests' => $wb_tests,
            'wanPath' => $wb_path,
            'linkDev' => $wb_details['wan_link_dev'] ?? null,
            'linkMbit' => $wb_int($wb_details['wan_link_mbit'] ?? null),
            'tools' => [
                'librespeedCli' => is_bool($wb_tools['librespeed_cli'] ?? null) ? $wb_tools['librespeed_cli'] : null,
                'ethtool' => is_bool($wb_tools['ethtool'] ?? null) ? $wb_tools['ethtool'] : null,
                'tc' => is_bool($wb_tools['tc'] ?? null) ? $wb_tools['tc'] : null,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] wan_bottleneck selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Rozbor linky WAN se nepodařilo sestavit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * The router's tariff, and the consent for its own probe (WAN 3.6, INDEX 3.4).
 *
 * The plan is the one thing the classifier cannot measure: without it nothing
 * is ever called "below plan" (WAN 3.0), so a rate is stored as null when it
 * is not set and never as a zero that would read as "the line should do
 * nothing". A value outside the range is a client error and is refused -
 * clamping it would store a plan the owner never entered.
 */
if ($action === 'wan_settings_save') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ws_input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($ws_input)) {
        $ws_input = $_POST;
    }
    $ws_monitor_id = (int)($ws_input['monitor_id'] ?? 0);
    bk_require_monitor_view($pdo, $ws_monitor_id);
    bk_refuse_archived_write($pdo, $ws_monitor_id);

    $ws_bad = [];
    $ws_range = function ($value, int $min, int $max, string $name) use (&$ws_bad): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int)$value < $min || (int)$value > $max) {
            $ws_bad[] = $name;
            return null;
        }
        return (int)$value;
    };
    $ws_down = $ws_range($ws_input['plan_down_mbit'] ?? null, 1, 100000, 'plan_down_mbit');
    $ws_up = $ws_range($ws_input['plan_up_mbit'] ?? null, 1, 100000, 'plan_up_mbit');
    $ws_pct = $ws_range($ws_input['plan_ok_pct'] ?? null, 30, 100, 'plan_ok_pct');
    if ($ws_bad !== []) {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatná hodnota: ' . implode(', ', $ws_bad)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // This release has no probe toggle, so the app does not send the key.
        // A missing key must leave the stored consent alone - reading it as
        // `false` would silently withdraw a consent nobody took back.
        if (array_key_exists('probe_enabled', $ws_input)) {
            $ws_probe = !empty($ws_input['probe_enabled']) ? 1 : 0;
            $pdo->prepare("
                UPDATE monitors
                SET wan_plan_down_mbit = ?, wan_plan_up_mbit = ?, wan_plan_ok_pct = ?, wan_probe_enabled = ?
                WHERE id = ?
            ")->execute([$ws_down, $ws_up, $ws_pct, $ws_probe, $ws_monitor_id]);
        } else {
            $pdo->prepare("
                UPDATE monitors
                SET wan_plan_down_mbit = ?, wan_plan_up_mbit = ?, wan_plan_ok_pct = ?
                WHERE id = ?
            ")->execute([$ws_down, $ws_up, $ws_pct, $ws_monitor_id]);
        }
        $ws_read = $pdo->prepare("SELECT wan_probe_enabled FROM monitors WHERE id = ?");
        $ws_read->execute([$ws_monitor_id]);
        $ws_enabled = (bool)$ws_read->fetchColumn();
        bk_audit_log($pdo, 'wan_plan_saved',
            sprintf('Tarif WAN: %s / %s Mbit/s, %s %%',
                $ws_down === null ? '—' : (string)$ws_down,
                $ws_up === null ? '—' : (string)$ws_up,
                $ws_pct === null ? '85' : (string)$ws_pct),
            'monitor', $ws_monitor_id);
        echo json_encode([
            'ok' => true,
            'plan' => ['downMbit' => $ws_down, 'upMbit' => $ws_up, 'okPct' => $ws_pct],
            'probe' => ['enabledServer' => $ws_enabled],
        ], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] wan_settings_save selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Nastavení se nepodařilo uložit.'], JSON_UNESCAPED_UNICODE);
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
 * Public e-mail subscriptions - visitors without accounts.
 *
 * Double opt-in: the form is public, so anyone can type any address - nothing
 * is sent until the owner clicks the confirmation link. The response is
 * deliberately identical whether the address is new, pending or already
 * subscribed, so the form cannot be used to probe who subscribes. The
 * confirm/unsubscribe links lead to React pages with an explicit button -
 * mail scanners follow bare GET links and would confirm or cancel
 * subscriptions nobody asked for.
 */
if ($action === 'public_subscribe') {
    $ps_input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ps_email = trim((string)($ps_input['email'] ?? ''));
    $ps_lang = ($ps_input['lang'] ?? '') === 'en' ? 'en' : 'cs';
    if (!filter_var($ps_email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatná e-mailová adresa.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ps_ip = bk_client_ip();
    try {
        // Sign-up rate limit per IP: the form must not become a mail cannon.
        $stmt_rl = $pdo->prepare("SELECT COUNT(*) FROM public_subscribers WHERE created_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt_rl->execute([$ps_ip]);
        if ((int)$stmt_rl->fetchColumn() >= 5) {
            http_response_code(429);
            echo json_encode(['error' => 'Příliš mnoho pokusů. Zkuste to později.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ps_token = bk_public_sub_issue($pdo, $ps_email, $ps_lang, $ps_ip);
        if ($ps_token !== null && !bk_public_sub_send_confirm($ps_email, $ps_lang, $ps_token, bk_public_base_origin())) {
            // The operator needs to know delivery is broken; the visitor must
            // not, because the answer would identify them. See below.
            error_log('[public_subscribe] potvrzovací e-mail se nepodařilo odeslat');
        }
        // The response is deliberately constant for every outcome - new address,
        // already confirmed, or resend cooldown.
        //
        // It used to carry an `emailSent` flag, meant as honesty about delivery.
        // But only the not-yet-subscribed path ever attempts a send, so ANY
        // per-request delivery signal identifies membership the moment sending
        // breaks: an attacker submits an address and reads "send failed" =>
        // "not a subscriber". Reporting a fixed `true` instead just inverted
        // that (CI, which has no mail server, made it obvious). Delivery health
        // is an operator concern and goes to the log above; what the visitor is
        // told stays true for every case and reveals nothing about who
        // subscribes.
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[public_subscribe] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Odběr se nepodařilo založit.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'public_subscribe_confirm') {
    $psc_input = json_decode(file_get_contents('php://input'), true) ?: [];
    $psc_token = trim((string)($psc_input['token'] ?? ''));
    if ($psc_token === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí token.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt_psc = $pdo->prepare("SELECT id FROM public_subscribers WHERE confirm_token_hash = ? AND confirmed_at IS NULL LIMIT 1");
        $stmt_psc->execute([hash('sha256', $psc_token)]);
        $psc_row = $stmt_psc->fetch();
        if (!$psc_row) {
            http_response_code(400);
            echo json_encode(['error' => 'Odkaz je neplatný nebo už byl použit.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->prepare("UPDATE public_subscribers SET confirmed_at = NOW(), confirm_token_hash = NULL WHERE id = ?")
            ->execute([(int)$psc_row['id']]);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[public_subscribe_confirm] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Potvrzení se nepodařilo.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'public_unsubscribe') {
    $pu_input = json_decode(file_get_contents('php://input'), true) ?: [];
    $pu_token = trim((string)($pu_input['token'] ?? ''));
    if ($pu_token === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí token.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $stmt_pu = $pdo->prepare("DELETE FROM public_subscribers WHERE unsubscribe_token = ?");
        $stmt_pu->execute([$pu_token]);
        // Deleting an already-deleted row is still a successful unsubscribe -
        // the link in an old mail must never show the visitor an error.
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[public_unsubscribe] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Odhlášení se nepodařilo.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Admin overview of public subscribers - the admin must be able to see and
// remove addresses (somebody asks to be removed by hand, GDPR requests...).
if ($action === 'public_subscribers' || $action === 'delete_public_subscriber') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        if ($action === 'delete_public_subscriber') {
            $dps_input = json_decode(file_get_contents('php://input'), true) ?: [];
            $pdo->prepare("DELETE FROM public_subscribers WHERE id = ?")->execute([(int)($dps_input['id'] ?? 0)]);
            bk_audit_log($pdo, 'public_subscriber_deleted', '', 'subscriber', (int)($dps_input['id'] ?? 0));
            echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ps_rows = $pdo->query("SELECT id, email, lang, confirmed_at, created_at FROM public_subscribers ORDER BY id DESC")->fetchAll();
        $ps_out = [];
        foreach ($ps_rows as $r) {
            $ps_out[] = [
                'id' => (int)$r['id'],
                'email' => $r['email'],
                'lang' => $r['lang'],
                'confirmed' => !empty($r['confirmed_at']),
                'createdAt' => $r['created_at'],
            ];
        }
        echo json_encode(['subscribers' => $ps_out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[public_subscribers] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Seznam odběratelů se nepodařilo načíst.'], JSON_UNESCAPED_UNICODE);
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
        // for the account it just created itself. A new session id, as login
        // does, so a session id planted before the install is not the admin's.
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $new_user_id;
        $_SESSION['admin_username'] = $su_username;
        $_SESSION['admin_role'] = 'admin';

        bk_audit_log($pdo, 'setup_completed', $su_username, 'user', $new_user_id, $new_user_id, $su_username);
        // The CSRF token a login hands out. Without it the session had none, so
        // the new admin's first save in the app was refused with 403 until they
        // signed out and in again - and since W1-H2 setup is the only way a
        // fresh install gets its first account.
        echo json_encode(['success' => true, 'id' => $new_user_id, 'csrfToken' => bk_csrf_token()], JSON_UNESCAPED_UNICODE);
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
    bk_require_monitor_view($pdo, $csv_monitor_id);
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
    bk_refuse_archived_write($pdo, $ann_monitor_id);

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
            // Cap the note server-side: the client sends maxLength=500, but the
            // client is not a security boundary. The note is rendered escaped,
            // so this is a size limit, not the XSS defence.
            mb_substr($ann_note, 0, 500),
            $_SESSION['admin_id'] ?? null,
        ]);
        // Read the id BEFORE the audit entry: bk_audit_log() inserts a row of
        // its own, and lastInsertId() would then report the audit row's id.
        // The response has been returning that foreign id all along - harmless
        // while nobody used it, wrong the moment deletion did.
        $ann_new_id = (int)$pdo->lastInsertId();
        bk_audit_log($pdo, 'annotation_created', mb_substr($ann_note, 0, 80), 'monitor', $ann_monitor_id);
        echo json_encode(['success' => true, 'id' => $ann_new_id], JSON_UNESCAPED_UNICODE);
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
    // Notes on someone else's monitor are not this user's to read. The empty
    // list is the same answer an anonymous caller already gets.
    if (!bk_can_view_monitor($pdo, $ann_monitor_id)) {
        echo json_encode(['annotations' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

/** Deleting a chart note. Admin-only, the same gate as creating one. */
if ($action === 'delete_annotation') {
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Přístup odepřen — vyžadována role administrátora.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $ann_id = (int)($input['id'] ?? 0);
    if ($ann_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Chybí id poznámky.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // Read before delete: the audit entry names what vanished, not just a number.
        $stmt = $pdo->prepare("SELECT monitor_id, note FROM metric_annotations WHERE id = ? LIMIT 1");
        $stmt->execute([$ann_id]);
        $ann_row = $stmt->fetch();
        if (!$ann_row) {
            http_response_code(404);
            echo json_encode(['error' => 'Poznámka nenalezena.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->prepare("DELETE FROM metric_annotations WHERE id = ?")->execute([$ann_id]);
        bk_audit_log($pdo, 'annotation_deleted', mb_substr((string)$ann_row['note'], 0, 80), 'monitor', (int)$ann_row['monitor_id']);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[api] delete_annotation selhal: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Poznámku se nepodařilo smazat.'], JSON_UNESCAPED_UNICODE);
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
    // Only the public set, whoever asks: the one consumer is the game portal,
    // anonymous by nature, and a monitor the owner took off the public page
    // (W1-G3) must not reappear here by name and state. ORDER BY id: with
    // several matches the answer must not depend on the storage order.
    [$ov_scope, $ov_scope_params] = bk_monitor_scope_sql(bk_public_monitor_ids($pdo), 'id');
    $stmt = $pdo->prepare("SELECT status, last_details, name FROM monitors WHERE archived_at IS NULL AND {$ov_scope} AND (LOWER(type) LIKE '%teamspeak%' OR LOWER(type) LIKE '%ts3%' OR LOWER(name) LIKE '%teamspeak%') ORDER BY id LIMIT 1");
    $stmt->execute($ov_scope_params);
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

    $stmt = $pdo->prepare("SELECT status, last_details, name FROM monitors WHERE archived_at IS NULL AND {$ov_scope} AND (LOWER(type) LIKE '%minecraft%' OR LOWER(type) LIKE '%mc%' OR LOWER(name) LIKE '%minecraft%') ORDER BY id LIMIT 1");
    $stmt->execute($ov_scope_params);
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
} catch (Throwable $e) {
    // Every service "offline" was the answer to a failed read.
    bk_api_fail('overview_unavailable', 500, $e, 'Přehled služeb se nepodařilo načíst.');
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
