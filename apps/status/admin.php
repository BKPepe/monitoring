<?php
/**
 * Administrace monitorovacího systému (Blood Kings)
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Zpracování odhlášení
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (!empty($_SESSION['admin_logged_in'])) {
        bk_audit_log($pdo, 'logout');
    }
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Zapomenuté heslo / nastavení hesla přes e-mailový odkaz - musí být
// dostupné bez přihlášení (to je celý smysl), proto se řeší dřív, než se
// vůbec kontroluje $is_logged_in.
if (isset($_GET['action']) && $_GET['action'] === 'forgot_password') {
    bk_render_forgot_password_page($pdo, get_setting('site_title', 'Blood Kings'));
    exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'set_password') {
    bk_render_set_password_page($pdo, get_setting('site_title', 'Blood Kings'));
    exit;
}

// GitHub OAuth SSO Přihlášení
if (isset($_GET['login_oauth']) && $_GET['login_oauth'] === 'github') {
    $client_id = get_setting('oauth_github_client_id');
    if (!empty($client_id)) {
        $redirect_uri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
        $authorize_url = "https://github.com/login/oauth/authorize?" . http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'scope' => 'user:email',
            'state' => $state
        ]);
        header('Location: ' . $authorize_url);
        exit;
    }
}

// GitHub Callback zpracování
if (isset($_GET['code']) && isset($_GET['state'])) {
    if (!isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        $login_error = 'Neplatný stav OAuth (CSRF ochrana).';
    } else {
        unset($_SESSION['oauth_state']);
        $client_id = get_setting('oauth_github_client_id');
        $client_secret = get_setting('oauth_github_client_secret');
        
        $token_url = "https://github.com/login/oauth/access_token";
        $ch = curl_init($token_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $_GET['code']
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $token_data = json_decode($resp, true);
        $access_token = $token_data['access_token'] ?? '';
        
        if (empty($access_token)) {
            $login_error = 'Chyba při získávání přístupového tokenu z GitHubu.';
        } else {
            $emails_url = "https://api.github.com/user/emails";
            $ch = curl_init($emails_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: token ' . $access_token,
                'User-Agent: BloodKingsStatus/1.3.0'
            ]);
            $resp_emails = curl_exec($ch);
            curl_close($ch);
            
            // Kontrola proti VŠEM ověřeným e-mailům z GitHub účtu, ne jen "primary" -
            // uživatel může mít v Blood Kings registrovaný jiný (i tak ověřený)
            // e-mail, než který má GitHub zrovna nastavený jako primární.
            $emails = json_decode($resp_emails, true);
            $verified_emails = [];
            if (is_array($emails)) {
                foreach ($emails as $email_entry) {
                    if (!empty($email_entry['verified']) && !empty($email_entry['email'])) {
                        $verified_emails[] = $email_entry['email'];
                    }
                }
            }

            if (empty($verified_emails)) {
                $login_error = 'Na vašem GitHub účtu nebyl nalezen žádný ověřený e-mail.';
            } else {
                $placeholders = implode(',', array_fill(0, count($verified_emails), '?'));
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email IN ($placeholders) LIMIT 1");
                $stmt->execute($verified_emails);
                $user = $stmt->fetch();

                if ($user) {
                    $stmt_up_oauth = $pdo->prepare("UPDATE users SET oauth_provider = 'github' WHERE id = ?");
                    $stmt_up_oauth->execute([$user['id']]);

                    // Nová session ID při přihlášení - session fixation ochrana (útočník
                    // nemůže vnutit oběti předem známé ID a pak ho po loginu použít).
                    session_regenerate_id(true);
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_role'] = $user['role'];
                    bk_audit_log($pdo, 'login_success', 'Přes GitHub OAuth', 'user', $user['id'], $user['id'], $user['username']);
                    header('Location: admin.php');
                    exit;
                } else {
                    bk_audit_log($pdo, 'login_failed', 'GitHub OAuth - žádný účet neodpovídá ověřeným e-mailům: ' . implode(', ', $verified_emails));
                    $login_error = 'Žádný z ověřených e-mailů vašeho GitHub účtu (' . htmlspecialchars(implode(', ', $verified_emails)) . ') není v systému registrován jako administrátor.';
                }
            }
        }
    }
}

// Zpracování přihlášení (chybu z OAuth callbacku výše nesmíme přepsat)
$login_error = $login_error ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    bk_csrf_check();
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        if (!empty($user['totp_enabled'])) {
            // Heslo sedí, ale účet má 2FA - do session jde jen ID (důkaz, že
            // tahle session už prošla heslem), samotné heslo se nikam neukládá.
            // Krok 2 (kód) zpracovává samostatný handler níže.
            $_SESSION['pending_2fa_user_id'] = $user['id'];
        } else {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_role'] = $user['role'];
            bk_audit_log($pdo, 'login_success', 'Jméno a heslo', 'user', $user['id'], $user['id'], $user['username']);
            header('Location: admin.php');
            exit;
        }
    } else {
        bk_audit_log($pdo, 'login_failed', 'Neplatné jméno/heslo, zadané jméno: ' . $username, null, null, null, $username);
        $login_error = 'Neplatné přihlašovací údaje.';
    }
}

// Krok 2 přihlášení s 2FA - ověření 6místného kódu proti účtu, co už prošel
// heslem v kroku výše (pending_2fa_user_id). Samostatný POST handler, protože
// přihlašovací formulář teď v tomhle kroku posílá jen kód, ne username/password.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_login_code'])) {
    bk_csrf_check();
    $pending_user_id = $_SESSION['pending_2fa_user_id'] ?? 0;
    $totp_input = trim($_POST['totp_code'] ?? '');

    if (empty($pending_user_id)) {
        $login_error = 'Relace vypršela, přihlaste se prosím znovu.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$pending_user_id]);
        $user = $stmt->fetch();

        if ($user && bk_totp_verify_code($user['totp_secret'], $totp_input)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_role'] = $user['role'];
            unset($_SESSION['pending_2fa_user_id']);
            bk_audit_log($pdo, 'login_success', 'Jméno a heslo + 2FA', 'user', $user['id'], $user['id'], $user['username']);
            header('Location: admin.php');
            exit;
        } else {
            bk_audit_log($pdo, 'login_failed', 'Neplatný 2FA kód', null, null, $pending_user_id, $user['username'] ?? null);
            $login_error = 'Neplatný 2FA kód z autentikační aplikace.';
        }
    }
}

// Kontrola přihlášení
$is_logged_in = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

if (!$is_logged_in) {
    // Zobrazení přihlašovacího formuláře
    $site_title = get_setting('site_title', 'Blood Kings');
    ?>
    <!DOCTYPE html>
    <html lang="cs">
    <head>
        <meta charset="UTF-8">
        <title>Přihlášení | <?php echo htmlspecialchars($site_title); ?></title>
        <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime('assets/style.css'); ?>">
        <link rel="stylesheet" href="<?php echo BK_CDN_FONTAWESOME; ?>">
        <script>
            if (localStorage.getItem('theme') === 'light') {
                document.documentElement.classList.add('light-theme');
            }
        </script>
    </head>
    <body style="display: flex; align-items: center; justify-content: center; height: 100vh; padding: 0; position: relative;">
        <div style="position: absolute; top: 1.5rem; right: 1.5rem;">
            <button id="theme-toggle" class="btn btn-secondary btn-sm" style="border-radius: 4px; padding: 0.5rem 0.75rem;" title="Přepnout tmavý/světlý motiv"><i class="fas fa-sun"></i></button>
        </div>
        <div class="login-wrapper">
            <h2><i class="fas fa-lock" style="color: var(--color-red); margin-right: 0.5rem;"></i> <?php echo htmlspecialchars($site_title); ?> Admin</h2>
            <?php if (!empty($login_error)): ?>
                <div class="alert alert-danger"><?php echo $login_error; ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['pending_2fa_user_id'])): ?>
                <form action="admin.php" method="POST">
                    <?php echo bk_csrf_field(); ?>
                    <div class="form-group">
                        <label for="totp_code">6místný kód z autentikační aplikace</label>
                        <input type="text" name="totp_code" id="totp_code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code">
                    </div>
                    <button type="submit" name="totp_login_code" class="btn" style="width: 100%; margin-top: 1rem;"><i class="fas fa-shield-halved"></i> Potvrdit</button>
                </form>
            <?php else: ?>
            <form action="admin.php" method="POST">
                <?php echo bk_csrf_field(); ?>
                <div class="form-group">
                    <label for="username">Uživatelské jméno</label>
                    <input type="text" name="username" id="username" class="form-control" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Heslo</label>
                    <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
                </div>
                <button type="submit" name="login" class="btn" style="width: 100%; margin-top: 1rem;"><i class="fas fa-sign-in-alt"></i> Přihlásit se</button>
                <a href="admin.php?action=forgot_password" style="display:block; text-align:center; margin-top:0.75rem; font-size:0.8rem; color: var(--text-muted);">Zapomenuté heslo?</a>
                <?php
                $gh_client_id = get_setting('oauth_github_client_id');
                if (!empty($gh_client_id)):
                ?>
                    <a href="admin.php?login_oauth=github" class="btn btn-github" style="width: 100%; margin-top: 0.5rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; border: none;">
                        <i class="fab fa-github"></i> Přihlásit se přes GitHub
                    </a>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
        
        <script>
            const themeToggle = document.getElementById('theme-toggle');
            if (themeToggle) {
                const updateIcon = () => {
                    const isLight = document.documentElement.classList.contains('light-theme');
                    themeToggle.innerHTML = isLight ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
                };
                updateIcon();
                
                themeToggle.addEventListener('click', () => {
                    const isLight = document.documentElement.classList.toggle('light-theme');
                    localStorage.setItem('theme', isLight ? 'light' : 'dark');
                    updateIcon();
                });
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- ADMINISTRACE - PŘIHLÁŠENÝ UŽIVATEL ---

$user_id = intval($_SESSION['admin_id']);
$stmt_me = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt_me->execute([$user_id]);
$me = $stmt_me->fetch();
$user_role = $me ? $me['role'] : 'user';

// Vynucený setup wizard po čerstvé instalaci - jediný zdroj pravdy je
// setup_completed flag v settings, žádné porovnávání natvrdo psaných hashů.
// Dokud admin wizardem neprojde, žádná jiná akce v admin.php se nespustí.
if ($user_role === 'admin' && get_setting('setup_completed', '') !== '1') {
    // Migrace pro instalace, co existovaly už před wizardem - mít vyplněný
    // vlastní cron_key jasně znamená, že instalace není "čerstvá" a nemá smysl
    // nutit admina projít wizard od nuly jen kvůli chybějícímu flagu.
    if (trim((string)get_setting('cron_key', '')) !== '') {
        $stmt_sc = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('setup_completed', '1') ON DUPLICATE KEY UPDATE key_value = '1'");
        $stmt_sc->execute();
        $GLOBALS['system_settings']['setup_completed'] = '1';
    } elseif (!(isset($_GET['action']) && $_GET['action'] === 'logout')) {
        bk_render_setup_wizard($pdo, $me);
        exit;
    }
}

// Zbývající bezpečnostní upozornění, co wizard neřeší napřímo (cron_key se sice
// nastavuje v kroku 2, ale admin ho mohl později v nastavení zase vymazat).
$security_warnings = [];
if ($user_role === 'admin' && trim((string)get_setting('cron_key', '')) === '') {
    $security_warnings[] = 'Není nastavený "Cron key" (záložka Notifikace -> Cron). Bez něj jede cron.php přes HTTP bez ověření a Distributed Node API (node_api.php) je úplně vypnuté - nastavte si vlastní klíč.';
}

// Audit log - samostatná read-only stránka (viz bk_render_audit_log_page()).
if ($user_role === 'admin' && ($_GET['view'] ?? '') === 'audit_log') {
    bk_render_audit_log_page($pdo, get_setting('site_title', 'Blood Kings'));
    exit;
}

$success_msg = '';
$error_msg = '';

// 1. Zpracování přidání / úpravy monitoru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_monitor']) && $user_role === 'admin') {
    bk_csrf_check();
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $target = trim($_POST['target']);
    $port = !empty($_POST['port']) ? intval($_POST['port']) : null;
    $category = trim($_POST['category']);

    // Asset (Phase 4) - fyzické/logické zařízení, ke kterému monitor patří.
    // Nové jméno v poli má přednost před výběrem z existujících (vytvoří nový
    // asset při ukládání - typicky "odpojení" monitoru do vlastní skupiny).
    $new_asset_name = trim($_POST['new_asset_name'] ?? '');
    $asset_id = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
    if ($new_asset_name !== '') {
        $stmt_new_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
        $stmt_new_asset->execute([$new_asset_name]);
        $asset_id = (int)$pdo->lastInsertId();
    }

    $timeout = !empty($_POST['timeout']) ? intval($_POST['timeout']) : 5;
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $maintenance = isset($_POST['maintenance']) ? 1 : 0;
    $monitored_processes = isset($_POST['monitored_processes']) ? trim($_POST['monitored_processes']) : null;
    $maintenance_description = ($maintenance === 1 && !empty($_POST['maintenance_description'])) ? trim($_POST['maintenance_description']) : null;
    $maintenance_start = ($maintenance === 1 && !empty($_POST['maintenance_start'])) ? $_POST['maintenance_start'] : null;
    $maintenance_end = ($maintenance === 1 && !empty($_POST['maintenance_end'])) ? $_POST['maintenance_end'] : null;
    $cpu_threshold = !empty($_POST['cpu_threshold']) ? intval($_POST['cpu_threshold']) : 90;
    $ram_threshold = !empty($_POST['ram_threshold']) ? intval($_POST['ram_threshold']) : 95;
    $hdd_threshold = !empty($_POST['hdd_threshold']) ? intval($_POST['hdd_threshold']) : 90;
    $body_keyword = !empty($_POST['body_keyword']) && $type === 'web' ? trim($_POST['body_keyword']) : null;

    $cpanel_stats_url = !empty($_POST['cpanel_stats_url']) && $type === 'web' ? trim($_POST['cpanel_stats_url']) : null;

    // Volitelné ServerQuery přihlášení (hlubší TeamSpeak data - server groups, plný clientlist)
    $sq_username = !empty($_POST['sq_username']) && $type === 'teamspeak' ? trim($_POST['sq_username']) : null;
    $sq_password = !empty($_POST['sq_password']) && $type === 'teamspeak' ? trim($_POST['sq_password']) : null;
    $ts3_filetransfer_port = !empty($_POST['ts3_filetransfer_port']) && $type === 'teamspeak' ? intval($_POST['ts3_filetransfer_port']) : null;

    // Volitelné RCON přihlášení (TPS přes Paper/Spigot příkaz "tps")
    $rcon_port = !empty($_POST['rcon_port']) && $type === 'minecraft' ? intval($_POST['rcon_port']) : null;
    $rcon_password = !empty($_POST['rcon_password']) && $type === 'minecraft' ? trim($_POST['rcon_password']) : null;

    // Remote Actions - souhlas je VÝSLOVNĚ per-monitor, výchozí vypnuto. Ani
    // pro OpenWrt monitory se nikdy nezapíná automaticky - musí ho admin sám
    // zaškrtnout v editaci monitoru (viz remote-actions-group níže).
    $remote_actions_enabled = ($type === 'openwrt' && isset($_POST['remote_actions_enabled'])) ? 1 : 0;
    $allowed_actions_post = isset($_POST['allowed_actions']) && is_array($_POST['allowed_actions'])
        ? array_values(array_intersect($_POST['allowed_actions'], ['restart_wan', 'restart_wireguard', 'reboot_router', 'renew_dhcp', 'restart_service', 'reconnect_pppoe']))
        : [];
    $allowed_actions = ($type === 'openwrt' && $remote_actions_enabled && !empty($allowed_actions_post))
        ? implode(',', $allowed_actions_post)
        : null;

    // Service Profiles - zapnuté sekce dashboardu. Prázdný výběr (nebo typ bez
    // checklistu v get_service_profiles()) ukládáme jako NULL, aby se uplatnily
    // recommended výchozí hodnoty profilu (viz bk_get_enabled_metrics()).
    $enabled_metrics_post = isset($_POST['enabled_metrics']) && is_array($_POST['enabled_metrics'])
        ? array_values(array_filter(array_map('trim', $_POST['enabled_metrics'])))
        : [];
    $enabled_metrics = !empty($enabled_metrics_post) ? json_encode($enabled_metrics_post) : null;

    // Pro TeamSpeak spojíme hostitele s hlasovým portem
    if ($type === 'teamspeak') {
        $voice_port = !empty($_POST['teamspeak_voice_port']) ? intval($_POST['teamspeak_voice_port']) : 9987;
        $target = $target . ':' . $voice_port;
    }
    
    // Cíl je povinný všude kromě čistě agentových typů (vps/openwrt) - tam se
    // buď vůbec nepoužívá (openwrt), nebo je to jen popisek bez síťového
    // významu (vps); pokud zůstane prázdný, doplní ho agent_api.php podle
    // hostname/WAN IP z první zprávy agenta (viz agent_api.php).
    if (empty($name) || (empty($target) && !in_array($type, ['vps', 'openwrt'], true))) {
        $error_msg = 'Název a cíl jsou povinné údaje.';
    } else {
        if ($id > 0) {
            // Úprava stávajícího monitoru
            // Pokud stávající monitor nemá agent_key, vygenerujeme ho dodatečně
            $stmt_check = $pdo->prepare("SELECT agent_key, asset_id FROM monitors WHERE id = ?");
            $stmt_check->execute([$id]);
            $existing_row = $stmt_check->fetch();
            $existing_key = $existing_row['agent_key'] ?? null;
            if (empty($existing_key)) {
                $stmt_up_key = $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = ?");
                $stmt_up_key->execute([bin2hex(random_bytes(16)), $id]);
            }
            // Obranná záloha - pokud formulář z nějakého důvodu neposlal ani
            // výběr, ani nové jméno, monitor si podrží svůj současný asset
            // místo aby o něj tiše přišel.
            if ($asset_id === null && !empty($existing_row['asset_id'])) {
                $asset_id = (int)$existing_row['asset_id'];
            }

            $stmt = $pdo->prepare("
                UPDATE monitors
                SET name = ?, type = ?, target = ?, port = ?, category = ?, timeout = ?, email_notifications = ?, sms_notifications = ?, notes = ?, maintenance = ?, monitored_processes = ?, maintenance_description = ?, maintenance_start = ?, maintenance_end = ?, cpanel_stats_url = ?, cpu_threshold = ?, ram_threshold = ?, hdd_threshold = ?, body_keyword = ?, sq_username = ?, sq_password = ?, ts3_filetransfer_port = ?, enabled_metrics = ?, rcon_port = ?, rcon_password = ?, remote_actions_enabled = ?, allowed_actions = ?, asset_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $rcon_port, $rcon_password, $remote_actions_enabled, $allowed_actions, $asset_id, $id]);
            log_monitor_event($pdo, $id, $name, $type, 'config_changed', 'Nastavení monitoru bylo upraveno');
            bk_audit_log($pdo, 'monitor_updated', $name, 'monitor', $id);
            $success_msg = 'Monitor byl úspěšně upraven.';
        } else {
            // Vytvoření nového monitoru - vygenerujeme agent_key pro všechny typy
            $agent_key = bin2hex(random_bytes(16));
            // Monitor nikdy nezůstává bez assetu - bez výběru/nového jména dostane
            // vlastní 1:1 asset pojmenovaný po sobě (stejný fallback jako zpětná
            // migrace v db.php pro monitory, co existovaly před Phase 4).
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
            $new_monitor_id = (int)$pdo->lastInsertId();
            log_monitor_event($pdo, $new_monitor_id, $name, $type, 'monitor_added', "Přidán nový monitor ({$type})");
            bk_audit_log($pdo, 'monitor_created', $name, 'monitor', $new_monitor_id);
            $success_msg = 'Monitor byl úspěšně přidán.';
        }
    }
}

// 2. Zpracování smazání monitoru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_monitor']) && isset($_POST['id']) && $user_role === 'admin') {
    bk_csrf_check();
    $del_id = intval($_POST['id']);
    $stmt_del_info = $pdo->prepare("SELECT name, type FROM monitors WHERE id = ?");
    $stmt_del_info->execute([$del_id]);
    $del_info = $stmt_del_info->fetch();
    if ($del_info) {
        log_monitor_event($pdo, null, $del_info['name'], $del_info['type'], 'monitor_removed', "Monitor odebrán ({$del_info['type']})");
    }
    $stmt = $pdo->prepare("DELETE FROM monitors WHERE id = ?");
    $stmt->execute([$del_id]);
    bk_audit_log($pdo, 'monitor_deleted', $del_info['name'] ?? ('#' . $del_id), 'monitor', $del_id);
    $success_msg = 'Monitor byl úspěšně smazán.';
}

// 2b. Přejmenování assetu (Phase 4)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_asset']) && $user_role === 'admin') {
    bk_csrf_check();
    $ra_asset_id = (int)($_POST['asset_id'] ?? 0);
    $ra_new_name = trim($_POST['asset_name'] ?? '');
    if ($ra_asset_id > 0 && $ra_new_name !== '') {
        $stmt = $pdo->prepare("UPDATE assets SET name = ? WHERE id = ?");
        $stmt->execute([$ra_new_name, $ra_asset_id]);
        bk_audit_log($pdo, 'asset_renamed', $ra_new_name, 'asset', $ra_asset_id);
        $success_msg = 'Asset byl přejmenován.';
    }
}

// 2c. Smazání assetu (Phase 4) - jen pokud je prázdný, aby nikdy tiše
// neosiřely monitory (viz FOREIGN KEY ... ON DELETE SET NULL v db.php, což by
// se stalo, kdyby šlo smazat i neprázdný asset - raději to admin udělá vědomě
// přeřazením monitorů jinam, ne omylem kliknutím na "smazat").
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset']) && isset($_POST['id']) && $user_role === 'admin') {
    bk_csrf_check();
    $da_id = (int)$_POST['id'];
    $stmt_da_check = $pdo->prepare("SELECT COUNT(*) FROM monitors WHERE asset_id = ?");
    $stmt_da_check->execute([$da_id]);
    if ((int)$stmt_da_check->fetchColumn() === 0) {
        $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
        $stmt->execute([$da_id]);
        bk_audit_log($pdo, 'asset_deleted', '#' . $da_id, 'asset', $da_id);
        $success_msg = 'Prázdný asset byl smazán.';
    } else {
        $error_msg = 'Asset nelze smazat - pořád k němu patří monitory. Nejdřív je přeřaďte jinam.';
    }
}

// 3. Zpracování uložení konfigurace
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && $user_role === 'admin') {
    bk_csrf_check();
    $settings_to_save = [
        'site_title', 'site_url', 'email_lang', 'cron_key', 'cron_location', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'sms_gateway_type', 'twilio_sid', 'twilio_token', 'twilio_from', 'smsbrana_user', 'smsbrana_password',
        'agent_offline_timeout', 'agent_notifications_enabled', 'agent_notify_admin_only',
        'discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id', 'slack_webhook_url',
        'oauth_github_client_id', 'oauth_github_client_secret',
        'custom_logo_url', 'custom_color_theme', 'custom_nav_links', 'portal_url',
        'metrics_token', 'sla_goal_pct', 'ts3_latest_version',
        'pushover_user_key', 'pushover_api_token', 'pagerduty_routing_key', 'ssl_alert_days', 'agent_registration_token'
    ];

    // Checkboxy - nezaškrtnuté pole nedorazí v POST, ukládáme proto explicitně '0' / '1'
    $checkbox_settings = ['agent_notifications_enabled', 'agent_notify_admin_only'];

    try {
        $pdo->beginTransaction();
        $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
        foreach ($settings_to_save as $key) {
            // Pokud je hodnota bezpečně definována v config.php nebo prostředí, nebudeme ji přepisovat
            if (is_setting_env_defined($key)) {
                continue;
            }
            if (in_array($key, $checkbox_settings, true)) {
                $val = isset($_POST[$key]) ? '1' : '0';
            } else {
                $val = isset($_POST[$key]) ? trim($_POST[$key]) : '';
            }
            $stmt_set->execute([$key, $val, $val]);
        }
        $pdo->commit();
        bk_audit_log($pdo, 'settings_updated', implode(', ', $settings_to_save));
        $success_msg = 'Nastavení systému bylo úspěšně uloženo.';

        // Znovu načíst nastavení
        $system_settings = get_settings($pdo);
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = 'Chyba při ukládání nastavení: ' . $e->getMessage();
    }
}

// 4. Zpracování změny hesla / profilu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    bk_csrf_check();
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $wa_apikey = trim($_POST['whatsapp_apikey'] ?? '');
    $sms_notif = isset($_POST['sms_notifications']) ? 1 : 0;
    $whatsapp_notif = isset($_POST['whatsapp_notifications']) ? 1 : 0;
    $old_pass = $_POST['old_password'];
    $new_pass = $_POST['new_password'];
    
    // Načtení dat aktuálního uživatele
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['admin_id']]);
    $me = $stmt->fetch();
    
    if (empty($email)) {
        $error_msg = 'E-mail administrátora je povinný.';
    } else {
        // Kontrola a změna hesla
        if (!empty($new_pass)) {
            if (password_verify($old_pass, $me['password_hash'])) {
                $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                $stmt_up = $pdo->prepare("UPDATE users SET email = ?, phone = ?, whatsapp_apikey = ?, sms_notifications = ?, whatsapp_notifications = ?, password_hash = ? WHERE id = ?");
                $stmt_up->execute([$email, $phone, $wa_apikey, $sms_notif, $whatsapp_notif, $new_hash, $me['id']]);
                bk_audit_log($pdo, 'password_changed', 'Vlastní profil', 'user', $me['id']);
                $success_msg = 'Profil a heslo byly úspěšně aktualizovány.';
            } else {
                $error_msg = 'Stávající heslo je nesprávné. Změna profilu neproběhla.';
            }
        } else {
            // Pouze aktualizace profilu bez hesla
            $stmt_up = $pdo->prepare("UPDATE users SET email = ?, phone = ?, whatsapp_apikey = ?, sms_notifications = ?, whatsapp_notifications = ? WHERE id = ?");
            $stmt_up->execute([$email, $phone, $wa_apikey, $sms_notif, $whatsapp_notif, $me['id']]);
            bk_audit_log($pdo, 'profile_updated', 'Vlastní profil', 'user', $me['id']);
            $success_msg = 'Profil byl úspěšně aktualizován.';
        }
        // Znovu načíst $me pro zobrazení formuláře s aktualizovanými hodnotami
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $me = $stmt->fetch();
    }
}

// 4b. Zapnutí 2FA - krok 1: vygenerovat nový secret a uložit ho jen do session,
// dokud uživatel nepotvrdí kódem z appky, že se mu opravdu naskenoval (viz
// totp_confirm níže). Do DB (totp_secret/totp_enabled) se nic nezapisuje, dokud
// se to takhle neověří - jinak by šlo omylem zamknout účet naskenovaným, ale
// nikdy neověřeným secretem.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_setup_start'])) {
    bk_csrf_check();
    $_SESSION['totp_pending_secret'] = bk_totp_generate_secret();
    header('Location: admin.php#totp-section');
    exit;
}

// Zapnutí 2FA - krok 2: ověření kódu z appky proti pending secretu ze session.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_confirm'])) {
    bk_csrf_check();
    $pending_secret = $_SESSION['totp_pending_secret'] ?? '';
    $totp_confirm_code = trim($_POST['totp_code'] ?? '');
    if (empty($pending_secret)) {
        $error_msg = '2FA nastavení vypršelo, zkuste to prosím znovu.';
    } elseif (bk_totp_verify_code($pending_secret, $totp_confirm_code)) {
        $stmt_totp = $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
        $stmt_totp->execute([$pending_secret, $_SESSION['admin_id']]);
        unset($_SESSION['totp_pending_secret']);
        bk_audit_log($pdo, 'totp_enabled', '', 'user', $_SESSION['admin_id']);
        $success_msg = 'Dvoufázové ověření (2FA) bylo úspěšně zapnuto.';
    } else {
        $error_msg = 'Neplatný kód z autentikační aplikace - zkuste to znovu.';
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['admin_id']]);
    $me = $stmt->fetch();
}

// Vypnutí 2FA - vyžaduje potvrzení aktuálním heslem, aby to nešlo tiše
// vypnout jen tak (např. přes ukradenou/CSRF session bez znalosti hesla).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_disable'])) {
    bk_csrf_check();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['admin_id']]);
    $me = $stmt->fetch();
    if ($me && password_verify($_POST['totp_disable_password'] ?? '', $me['password_hash'])) {
        $stmt_totp = $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
        $stmt_totp->execute([$_SESSION['admin_id']]);
        bk_audit_log($pdo, 'totp_disabled', '', 'user', $_SESSION['admin_id']);
        $success_msg = 'Dvoufázové ověření (2FA) bylo vypnuto.';
    } else {
        $error_msg = 'Nesprávné heslo - 2FA zůstává zapnuté.';
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['admin_id']]);
    $me = $stmt->fetch();
}

// 5. Zpracování uložení odběrů notifikací běžného uživatele
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subscriptions']) && $user_role === 'user') {
    bk_csrf_check();
    try {
        $pdo->beginTransaction();
        $stmt_del = $pdo->prepare("DELETE FROM user_subscriptions WHERE user_id = ?");
        $stmt_del->execute([$user_id]);
        
        if (isset($_POST['subs']) && is_array($_POST['subs'])) {
            $stmt_ins = $pdo->prepare("INSERT INTO user_subscriptions (user_id, monitor_id, email_notifications, sms_notifications, whatsapp_notifications) VALUES (?, ?, ?, ?, ?)");
            foreach ($_POST['subs'] as $mid => $opts) {
                $email_sub = isset($opts['email']) ? 1 : 0;
                $sms_sub = isset($opts['sms']) ? 1 : 0;
                $wa_sub = isset($opts['whatsapp']) ? 1 : 0;
                if ($email_sub || $sms_sub || $wa_sub) {
                    $stmt_ins->execute([$user_id, $mid, $email_sub, $sms_sub, $wa_sub]);
                }
            }
        }
        $pdo->commit();
        bk_audit_log($pdo, 'subscriptions_updated', '', 'user', $user_id);
        $success_msg = 'Vaše předvolby notifikací byly úspěšně uloženy.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = 'Chyba při ukládání notifikací: ' . $e->getMessage();
    }
}

// 6. Zpracování správy uživatelů (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user']) && $user_role === 'admin') {
    bk_csrf_check();
    $u_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $u_username = trim($_POST['username']);
    $u_email = trim($_POST['email']);
    $u_phone = trim($_POST['phone']);
    $u_role = trim($_POST['role']);
    $u_password = $_POST['password'];
    
    if (empty($u_username) || empty($u_email)) {
        $error_msg = 'Uživatelské jméno a e-mail jsou povinné.';
    } else {
        try {
            if ($u_id > 0) {
                // Před uložením zachytit staré hodnoty, aby audit log ukázal, co se
                // skutečně změnilo (hlavně e-mail - viz bezpečnostní poznámka u
                // OAuth loginu: tichá změna e-mailu jiného účtu je vektor převzetí).
                $stmt_old = $pdo->prepare("SELECT username, email, phone, role FROM users WHERE id = ?");
                $stmt_old->execute([$u_id]);
                $old_row = $stmt_old->fetch();

                if (!empty($u_password)) {
                    $new_pass_hash = password_hash($u_password, PASSWORD_BCRYPT);
                    $stmt_up = $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ?, password_hash = ? WHERE id = ?");
                    $stmt_up->execute([$u_username, $u_email, $u_phone, $u_role, $new_pass_hash, $u_id]);
                } else {
                    $stmt_up = $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                    $stmt_up->execute([$u_username, $u_email, $u_phone, $u_role, $u_id]);
                }

                $changes = [];
                if ($old_row) {
                    if ($old_row['username'] !== $u_username) $changes[] = "jméno {$old_row['username']} -> {$u_username}";
                    if ($old_row['email'] !== $u_email) $changes[] = "e-mail {$old_row['email']} -> {$u_email}";
                    if ($old_row['phone'] !== $u_phone) $changes[] = "telefon změněn";
                    if ($old_row['role'] !== $u_role) $changes[] = "role {$old_row['role']} -> {$u_role}";
                }
                if (!empty($u_password)) $changes[] = 'heslo nastaveno adminem';
                bk_audit_log($pdo, 'user_updated', $u_username . (!empty($changes) ? ' (' . implode(', ', $changes) . ')' : ' (beze změny)'), 'user', $u_id);
                $success_msg = 'Uživatel byl úspěšně upraven.';
            } else {
                if (!empty($u_password)) {
                    // Admin heslo zadal ručně - uloží se rovnou, žádný pozvánkový e-mail.
                    $new_pass_hash = password_hash($u_password, PASSWORD_BCRYPT);
                    $stmt_ins = $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$u_username, $u_email, $u_phone, $u_role, $new_pass_hash]);
                    bk_audit_log($pdo, 'user_created', $u_username . ' (' . $u_role . ', heslo nastaveno adminem)', 'user', (int)$pdo->lastInsertId());
                    $success_msg = 'Nový uživatel byl úspěšně vytvořen.';
                } else {
                    // Heslo nevyplněné - vytvoří se s nepoužitelným placeholder hashem
                    // (nikdy se nedá uhodnout, protože žádnou plaintext hodnotu
                    // neodpovídá) a pošle se pozvánkový odkaz na nastavení hesla.
                    // Admin tak sám heslo uživatele nikdy nezná.
                    $placeholder_hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);
                    $stmt_ins = $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$u_username, $u_email, $u_phone, $u_role, $placeholder_hash]);
                    $new_user_id = (int)$pdo->lastInsertId();

                    $raw_token = bk_issue_password_reset_token($pdo, $new_user_id);
                    $set_link = bk_current_admin_url() . '?action=set_password&token=' . $raw_token;
                    $site_title_mail = get_setting('site_title', 'Blood Kings');
                    $subject = 'Nastavení hesla - ' . $site_title_mail;
                    $body = '<h1>Vítejte v ' . htmlspecialchars($site_title_mail) . '</h1>'
                        . '<p>Byl pro vás vytvořen účet <strong>' . htmlspecialchars($u_username) . '</strong>. Nastavte si prosím heslo kliknutím na odkaz níže (platnost 48 hodin):</p>'
                        . '<p><a href="' . htmlspecialchars($set_link) . '">' . htmlspecialchars($set_link) . '</a></p>';

                    bk_audit_log($pdo, 'user_created', $u_username . ' (' . $u_role . ', pozvánka e-mailem)', 'user', $new_user_id);
                    if (send_email($u_email, $subject, $body)) {
                        $success_msg = 'Nový uživatel byl vytvořen. Na jeho e-mail byl odeslán odkaz pro nastavení hesla.';
                    } else {
                        $detail = !empty($GLOBALS['last_mail_error']) ? ' (' . htmlspecialchars($GLOBALS['last_mail_error']) . ')' : '';
                        $error_msg = "Uživatel byl vytvořen, ale pozvánkový e-mail se nepodařilo odeslat{$detail}. Otevřete jeho editaci a nastavte mu heslo ručně, nebo zkuste pozvánku odeslat znovu.";
                    }
                }
            }
        } catch (Exception $e) {
            $error_msg = 'Chyba při ukládání uživatele: ' . $e->getMessage();
        }
    }
}

// Smazání uživatele (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user']) && isset($_POST['id']) && $user_role === 'admin') {
    bk_csrf_check();
    $del_u_id = intval($_POST['id']);
    if ($del_u_id === $user_id) {
        $error_msg = 'Nemůžete smazat svůj vlastní přihlášený účet.';
    } else {
        $stmt_du = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt_du->execute([$del_u_id]);
        $del_u_username = $stmt_du->fetchColumn();
        $stmt_del = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt_del->execute([$del_u_id]);
        bk_audit_log($pdo, 'user_deleted', $del_u_username ?: ('#' . $del_u_id), 'user', $del_u_id);
        $success_msg = 'Uživatel byl úspěšně smazán.';
    }
}

// Rychlé přepnutí notifikace z tabulky (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_notif']) && isset($_POST['field']) && isset($_POST['id']) && $user_role === 'admin') {
    bk_csrf_check();
    $t_id = intval($_POST['id']);
    $field = $_POST['field'] === 'email' ? 'email_notifications' : 'sms_notifications';
    
    $stmt_tog = $pdo->prepare("UPDATE monitors SET $field = 1 - $field WHERE id = ?");
    $stmt_tog->execute([$t_id]);
    bk_audit_log($pdo, 'monitor_notif_toggled', $field, 'monitor', $t_id);

    header('Location: admin.php');
    exit;
}

// Rychlé přepnutí / okamžité zapnutí či ukončení režimu údržby z tabulky (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance']) && isset($_POST['id']) && $user_role === 'admin') {
    bk_csrf_check();
    $t_id = intval($_POST['id']);

    $stmt_m = $pdo->prepare("SELECT maintenance FROM monitors WHERE id = ?");
    $stmt_m->execute([$t_id]);
    $curr_m = (int)$stmt_m->fetchColumn();

    if ($curr_m === 1) {
        // Vypnutí údržby -> vyčistit popis a časování staré údržby
        $stmt_tog = $pdo->prepare("UPDATE monitors SET maintenance = 0, maintenance_description = NULL, maintenance_start = NULL, maintenance_end = NULL, status = 'unknown' WHERE id = ?");
        $stmt_tog->execute([$t_id]);
    } else {
        // Okamžité zapnutí údržby (nepovinný popis z rychlého přepínače v tabulce)
        $t_desc = !empty($_POST['desc']) ? trim($_POST['desc']) : null;
        $stmt_tog = $pdo->prepare("UPDATE monitors SET maintenance = 1, status = 'maintenance', maintenance_description = ? WHERE id = ?");
        $stmt_tog->execute([$t_desc, $t_id]);
    }
    bk_audit_log($pdo, 'monitor_maintenance_toggled', $curr_m === 1 ? 'vypnuto' : 'zapnuto', 'monitor', $t_id);

    header('Location: admin.php');
    exit;
}

// Smazání historie monitoru (logy a VPS metriky) (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_history']) && isset($_POST['id']) && $user_role === 'admin') {
    bk_csrf_check();
    $clear_id = intval($_POST['id']);
    bk_audit_log($pdo, 'monitor_history_cleared', '', 'monitor', $clear_id);

    $stmt_del_logs = $pdo->prepare("DELETE FROM monitor_logs WHERE monitor_id = ?");
    $stmt_del_logs->execute([$clear_id]);
    
    $stmt_del_metrics = $pdo->prepare("DELETE FROM vps_metrics WHERE monitor_id = ?");
    $stmt_del_metrics->execute([$clear_id]);
    
    $stmt_reset = $pdo->prepare("UPDATE monitors SET status = 'unknown', last_checked = NULL, last_status_change = NULL, last_details = NULL WHERE id = ?");
    $stmt_reset->execute([$clear_id]);
    
    $success_msg = 'Historie měření a logy pro daný monitor byly úspěšně smazány.';
}

// Zpracování vytvoření/úpravy incidentu (Správa incidentů)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_incident']) && $user_role === 'admin') {
    bk_csrf_check();
    $inc_action = $_POST['action_incident'];
    if ($inc_action === 'create') {
        $title = trim($_POST['inc_title'] ?? '');
        $impact = $_POST['inc_impact'] ?? 'minor';
        $status = $_POST['inc_status'] ?? 'investigating';
        $message = trim($_POST['inc_message'] ?? '');
        
        if (!empty($title) && !empty($message)) {
            $stmt = $pdo->prepare("INSERT INTO incidents (title, impact, status) VALUES (?, ?, ?)");
            $stmt->execute([$title, $impact, $status]);
            $inc_id = (int)$pdo->lastInsertId();
            
            $stmt_up = $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, ?, ?)");
            $stmt_up->execute([$inc_id, $status, $message]);
            bk_audit_log($pdo, 'incident_created', $title, 'incident', $inc_id);
            $success_msg = 'Incident byl úspěšně zaznamenán.';
        }
    } elseif ($inc_action === 'add_update') {
        $inc_id = (int)($_POST['inc_id'] ?? 0);
        $status = $_POST['inc_status'] ?? 'investigating';
        $message = trim($_POST['inc_message'] ?? '');

        if ($inc_id > 0 && !empty($message)) {
            $stmt_up = $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, ?, ?)");
            $stmt_up->execute([$inc_id, $status, $message]);

            $resolved_at = ($status === 'resolved') ? date('Y-m-d H:i:s') : null;
            $stmt_inc = $pdo->prepare("UPDATE incidents SET status = ?, resolved_at = COALESCE(resolved_at, ?) WHERE id = ?");
            $stmt_inc->execute([$status, $resolved_at, $inc_id]);
            bk_audit_log($pdo, 'incident_updated', $status, 'incident', $inc_id);
            $success_msg = 'Aktualizace incidentu byla přidána.';
        }
    } elseif ($inc_action === 'delete') {
        $inc_id = (int)($_POST['inc_id'] ?? 0);
        if ($inc_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM incidents WHERE id = ?");
            $stmt->execute([$inc_id]);
            bk_audit_log($pdo, 'incident_deleted', '', 'incident', $inc_id);
            $success_msg = 'Incident byl smazán.';
        }
    }
}

// 1-Click Import Objevené Služby (Service Discovery)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_import_service']) && $user_role === 'admin') {
    bk_csrf_check();
    $s_name = trim($_POST['service_name'] ?? '');
    $s_type = $_POST['service_type'] ?? 'web';
    $s_port = !empty($_POST['service_port']) ? (int)$_POST['service_port'] : null;
    $s_target = trim($_POST['service_target'] ?? '127.0.0.1');

    // Phase 4 - pokud import přišel z návrhu Service Discovery (viz níže),
    // víme, KTERÝ monitor tu službu objevil - jeho agent běží na stejném
    // fyzickém stroji, takže nový monitor rovnou dostane jeho asset. Jediné
    // místo v aplikaci, kde má smysl přiřazení assetu odhadovat automaticky,
    // protože tenhle signál je skutečně spolehlivý (na rozdíl od hádání podle
    // jména apod.).
    $source_monitor_id = !empty($_POST['source_monitor_id']) ? (int)$_POST['source_monitor_id'] : null;
    $discovered_asset_id = null;
    if ($source_monitor_id) {
        $stmt_src = $pdo->prepare("SELECT asset_id FROM monitors WHERE id = ?");
        $stmt_src->execute([$source_monitor_id]);
        $src_asset_id = $stmt_src->fetchColumn();
        if ($src_asset_id) {
            $discovered_asset_id = (int)$src_asset_id;
        }
    }
    if ($discovered_asset_id === null) {
        $stmt_new_asset = $pdo->prepare("INSERT INTO assets (name) VALUES (?)");
        $stmt_new_asset->execute([$s_name]);
        $discovered_asset_id = (int)$pdo->lastInsertId();
    }

    if (!empty($s_name)) {
        $agent_key = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("
            INSERT INTO monitors (name, type, target, port, status, agent_key, cpu_threshold, ram_threshold, hdd_threshold, asset_id)
            VALUES (?, ?, ?, ?, 'unknown', ?, 90, 90, 95, ?)
        ");
        $stmt->execute([$s_name, $s_type, $s_target, $s_port, $agent_key, $discovered_asset_id]);
        $new_id = (int)$pdo->lastInsertId();
        log_monitor_event($pdo, $new_id, $s_name, $s_type, 'monitor_added', "Importováno z automatické detekce služeb (Service Discovery)");
        bk_audit_log($pdo, 'monitor_created', $s_name . ' (Service Discovery)', 'monitor', $new_id);
        $success_msg = "Monitor '{$s_name}' byl úspěšně vytvořen z automatické detekce!";
    }
}

// 2. Zpracování zařazení vzdálené akce (Remote Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trigger_remote_action']) && $user_role === 'admin') {
    bk_csrf_check();
    $mid = intval($_POST['monitor_id'] ?? 0);
    $action_type = trim($_POST['action_type'] ?? '');

    $allowed_action_types = ['restart_wan', 'restart_wireguard', 'reboot_router', 'renew_dhcp', 'restart_service', 'reconnect_pppoe'];
    $stmt_ra = $pdo->prepare("SELECT remote_actions_enabled, allowed_actions, name FROM monitors WHERE id = ?");
    $stmt_ra->execute([$mid]);
    $ra_monitor = $stmt_ra->fetch();

    // Souhlas se kontroluje na monitoru samotném, ne jen proti globálnímu
    // seznamu typů akcí - bez toho by šlo zařadit i reboot pro router, jehož
    // vlastník Remote Actions nikdy nepovolil (viz bezpečnostní audit).
    $ra_monitor_allowed = $ra_monitor ? array_filter(explode(',', (string)($ra_monitor['allowed_actions'] ?? ''))) : [];
    if (!$ra_monitor || empty($ra_monitor['remote_actions_enabled'])) {
        $error_msg = 'Remote Actions nejsou pro tento monitor povolené - nejdřív je zapněte v jeho nastavení.';
    } elseif (!in_array($action_type, $allowed_action_types, true) || !in_array($action_type, $ra_monitor_allowed, true)) {
        $error_msg = "Akce '{$action_type}' není pro tento monitor v seznamu povolených akcí.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO agent_actions (monitor_id, action_type, status) VALUES (?, ?, 'pending')");
        $stmt->execute([$mid, $action_type]);
        bk_audit_log($pdo, 'remote_action_triggered', $action_type . ' na ' . ($ra_monitor['name'] ?? ('#' . $mid)), 'monitor', $mid);
        $success_msg = "Požadavek na akční příkaz '{$action_type}' byl podepsán a zařazen do fronty pro agenta.";
    }
}

// Zpracování odeslání testovacího e-mailu (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email']) && $user_role === 'admin') {
    bk_csrf_check();
    $to = $me['email'] ?? '';
    if (empty($to)) {
        $error_msg = 'Chyba: Administrátor nemá nastavenou e-mailovou adresu.';
    } else {
        $sent_at = date('d.m.Y H:i:s');
        [$subject, $body] = bk_with_email_lang(get_setting('email_lang', 'cs'), function () use ($sent_at) {
            $subject = t('test_email_subject');
            $body = '<h1>' . htmlspecialchars(t('test_email_heading')) . '</h1>
                 <p>' . htmlspecialchars(t('test_email_body1')) . '</p>
                 <p>' . htmlspecialchars(t('test_email_body2')) . '</p>
                 <hr>
                 <p>' . htmlspecialchars(t('test_email_sent_at')) . ' ' . $sent_at . '</p>';
            return [$subject, $body];
        });

        if (send_email($to, $subject, $body)) {
            bk_audit_log($pdo, 'test_email_sent', $to);
            $success_msg = ($GLOBALS['last_mail_method'] ?? null) === 'fallback'
                ? 'Testovací e-mail byl předán k odeslání přes systémovou funkci mail() (SMTP není nastaveno) na adresu ' . htmlspecialchars($to) . ' - zkontrolujte, zda opravdu dorazil, tohle jen potvrzuje, že to webhosting přijal ke zpracování.'
                : 'Testovací e-mail byl úspěšně odeslán na adresu ' . htmlspecialchars($to) . '.';
        } else {
            $detail = !empty($GLOBALS['last_mail_error']) ? ' Systémová chyba: ' . htmlspecialchars($GLOBALS['last_mail_error']) : ' Funkce mail() vrátila false – pravděpodobně chybí konfigurace odesílatele nebo webhostingový mail() je zakázán.';
            $error_msg = 'Chyba při odesílání e-mailu.' . $detail;
        }
    }
}

// Vynucení nové detekce geolokační lokace serveru (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redetect_location']) && $user_role === 'admin') {
    bk_csrf_check();
    $loc = detect_server_location();
    $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
    $stmt_set->execute(['ip_loc_local', $loc, $loc]);
    bk_audit_log($pdo, 'location_redetected', $loc);
    $success_msg = 'Lokace serveru byla úspěšně znovuzjištěna: ' . htmlspecialchars($loc);
}

// Ruční odeslání týdenního reportu/digestu (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_weekly_digest']) && $user_role === 'admin') {
    bk_csrf_check();
    if (send_digest_report($pdo, 'weekly')) {
        bk_audit_log($pdo, 'digest_sent', 'weekly');
        $success_msg = ($GLOBALS['last_mail_method'] ?? null) === 'fallback'
            ? 'Týdenní report byl předán k odeslání přes systémovou funkci mail() (SMTP není nastaveno) - to znamená jen "webhosting to přijal ke zpracování", ne potvrzené doručení. Pokud e-mail nedorazí, nastavte prosím SMTP níže.'
            : 'Týdenní report byl úspěšně vygenerován a odeslán na e-maily všech administrátorů.';
    } else {
        $detail = !empty($GLOBALS['last_mail_error']) ? ' Detaily: ' . htmlspecialchars($GLOBALS['last_mail_error']) : '';
        $error_msg = 'Chyba při odesílání týdenního reportu.' . $detail;
    }
}

// Ruční odeslání měsíčního reportu/digestu (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_monthly_digest']) && $user_role === 'admin') {
    bk_csrf_check();
    if (send_digest_report($pdo, 'monthly')) {
        bk_audit_log($pdo, 'digest_sent', 'monthly');
        $success_msg = ($GLOBALS['last_mail_method'] ?? null) === 'fallback'
            ? 'Měsíční report byl předán k odeslání přes systémovou funkci mail() (SMTP není nastaveno) - to znamená jen "webhosting to přijal ke zpracování", ne potvrzené doručení. Pokud e-mail nedorazí, nastavte prosím SMTP níže.'
            : 'Měsíční report byl úspěšně vygenerován a odeslán na e-maily všech administrátorů.';
    } else {
        $detail = !empty($GLOBALS['last_mail_error']) ? ' Detaily: ' . htmlspecialchars($GLOBALS['last_mail_error']) : '';
        $error_msg = 'Chyba při odesílání měsíčního reportu.' . $detail;
    }
}

// Náhled infrastructure reportu v prohlížeči (bez odeslání e-mailu) - pouze pro Admina
if (isset($_GET['action']) && in_array($_GET['action'], ['preview_weekly_digest', 'preview_monthly_digest'], true) && $user_role === 'admin') {
    $preview_period = $_GET['action'] === 'preview_monthly_digest' ? 'monthly' : 'weekly';
    echo bk_with_email_lang(get_setting('email_lang', 'cs'), function () use ($pdo, $preview_period) {
        $preview_data = build_digest_data($pdo, $preview_period, false);
        return render_digest_html($preview_data);
    });
    exit;
}

// Načtení monitoru pro editaci, pokud je požadováno (pouze pro Admina)
$edit_monitor = null;
if ($user_role === 'admin' && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT * FROM monitors WHERE id = ? LIMIT 1");
    $stmt->execute([$edit_id]);
    $edit_monitor = $stmt->fetch();
}

// Načtení uživatele pro editaci, pokud je požadováno (pouze pro Admina)
$edit_user = null;
if ($user_role === 'admin' && isset($_GET['action']) && $_GET['action'] === 'edit_user' && isset($_GET['id'])) {
    $edit_u_id = intval($_GET['id']);
    $stmt_u = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt_u->execute([$edit_u_id]);
    $edit_user = $stmt_u->fetch();
}

// Načtení všech monitorů k zobrazení
$stmt_all = $pdo->query("SELECT m.*, (SELECT error_message FROM monitor_logs WHERE monitor_id = m.id ORDER BY checked_at DESC LIMIT 1) as error_message FROM monitors m ORDER BY m.category, m.name");
$all_monitors = $stmt_all->fetchAll();

// Načtení všech uživatelů pro administraci (pouze pro Admina)
$all_users = [];
if ($user_role === 'admin') {
    $stmt_users = $pdo->query("SELECT * FROM users ORDER BY username");
    $all_users = $stmt_users->fetchAll();
}

// Načtení vlastních odběrů (pouze pro běžného uživatele)
$my_subscriptions = [];
if ($user_role === 'user') {
    $stmt_subs = $pdo->prepare("SELECT monitor_id, email_notifications, sms_notifications, whatsapp_notifications FROM user_subscriptions WHERE user_id = ?");
    $stmt_subs->execute([$user_id]);
    while ($row = $stmt_subs->fetch()) {
        $my_subscriptions[$row['monitor_id']] = [
            'email' => (int)$row['email_notifications'],
            'sms' => (int)$row['sms_notifications'],
            'whatsapp' => (int)($row['whatsapp_notifications'] ?? 0)
        ];
    }
}

$site_title = get_setting('site_title', 'Blood Kings');
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Administrace | <?php echo htmlspecialchars($site_title); ?></title>
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime('assets/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BK_CDN_FONTAWESOME; ?>">
    <style>
        /* Záložky ve formuláři nastavení */
        .settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 1.25rem;
        }
        .settings-tabs button {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            color: var(--text-muted);
            padding: 0.55rem 0.9rem;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.45rem;
            transition: color 0.15s ease, border-color 0.15s ease;
        }
        .settings-tabs button:hover {
            color: var(--text-primary);
        }
        .settings-tabs button.active {
            color: var(--text-primary);
            border-bottom-color: var(--color-red);
        }
        .settings-tab-panel {
            display: none;
        }
        .settings-tab-panel.active {
            display: block;
        }

        /* Service Profile picker - vizuální výběr typu monitoru místo dropdownu */
        .profile-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
            gap: 0.6rem;
            margin-bottom: 0.5rem;
        }
        .profile-picker-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.4rem;
            padding: 0.75rem 0.5rem;
            background: var(--bg-secondary, #12121a);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 0.75rem;
            text-align: center;
            transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease;
        }
        .profile-picker-card i {
            font-size: 1.3rem;
        }
        .profile-picker-card:hover {
            color: var(--text-primary);
            border-color: var(--color-red);
        }
        .profile-picker-card.active {
            color: var(--text-primary);
            border-color: var(--color-red);
            background: rgba(193, 18, 31, 0.08);
        }
        .metrics-checklist-group label.metric-checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
            font-weight: normal;
            font-size: 0.85rem;
        }
        .metrics-checklist-group .metric-recommended-badge {
            font-size: 0.6rem;
            color: var(--color-green);
            text-transform: uppercase;
            font-weight: bold;
        }

        .password-toggle-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }
        .password-toggle-group input {
            padding-right: 2.5rem !important;
            width: 100%;
        }
        .password-toggle-btn {
            position: absolute;
            right: 0.5rem;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.35rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.15s ease;
        }
        .password-toggle-btn:hover {
            color: var(--color-green);
        }
    </style>
    <script>
        if (localStorage.getItem('theme') === 'light') {
            document.documentElement.classList.add('light-theme');
        }
        
        function togglePasswordInput(inputId, btnId) {
            const input = document.getElementById(inputId);
            const btn = document.getElementById(btnId);
            if (!input || !btn) return;
            const icon = btn.querySelector('i');
            if (!icon) return;
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
    </script>
</head>
<body>

    <!-- Header -->
    <header>
        <div class="container header-wrapper">
            <a href="index.php" class="logo">
                <i class="fas fa-server" style="color: var(--color-red);"></i> <?php echo htmlspecialchars($site_title); ?> <span>Admin</span>
            </a>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-chart-line"></i> Dashboard</a>
                <?php if ($user_role === 'admin'): ?>
                <a href="admin.php?view=audit_log"><i class="fas fa-clipboard-list"></i> Audit log</a>
                <?php endif; ?>
                <a href="admin.php?action=logout" class="btn btn-secondary btn-sm"><i class="fas fa-sign-out-alt"></i> Odhlásit (<?php echo htmlspecialchars($_SESSION['admin_username']); ?>)</a>
                <button id="theme-toggle" class="btn btn-secondary btn-sm" style="padding: 0.4rem 0.6rem; margin-left: 0.25rem; border-radius: 4px;" title="Přepnout tmavý/světlý motiv"><i class="fas fa-sun"></i></button>
            </div>
        </div>
    </header>

    <div class="container">
        
        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success"><i class="fas fa-check"></i> <?php echo $success_msg; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?></div>
        <?php endif; ?>

        <?php if (!empty($security_warnings)): ?>
        <div class="admin-card" style="border: 2px solid var(--color-red); background: rgba(193,18,31,0.08);">
            <div class="admin-header">
                <h2 style="color: var(--color-red);"><i class="fas fa-exclamation-triangle"></i> Bezpečnostní upozornění - nedokončená instalace</h2>
            </div>
            <ul style="margin: 0; padding-left: 1.25rem;">
                <?php foreach ($security_warnings as $w): ?>
                    <li style="margin-bottom: 0.4rem;"><?php echo htmlspecialchars($w); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($user_role === 'admin'): ?>
        <?php
        // Assets (Phase 4) - fyzická/logická zařízení, která můžou sdružovat víc
        // monitorů. Po migraci má typicky každý monitor svůj vlastní 1:1 asset -
        // tenhle panel je hlavně pro sloučené případy (víc služeb na jednom VPS).
        $stmt_assets_panel = $pdo->query("SELECT a.id, a.name, COUNT(m.id) AS member_count FROM assets a LEFT JOIN monitors m ON m.asset_id = a.id GROUP BY a.id, a.name ORDER BY member_count DESC, a.name");
        $assets_panel = $stmt_assets_panel->fetchAll();
        $multi_assets = array_filter($assets_panel, function ($a) { return (int)$a['member_count'] > 1; });
        ?>
        <?php if (!empty($multi_assets)): ?>
        <div class="admin-card">
            <div class="admin-header">
                <h2><i class="fas fa-layer-group"></i> Assety se sloučenými monitory</h2>
            </div>
            <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0 0 0.75rem;">Tyhle assety mají víc než jeden monitor - na veřejném dashboardu se zobrazí vizuálně seskupené. Ostatní assety (1:1 s monitorem) tu kvůli přehlednosti nejsou vypsané - najdete je ve výběru assetu v editaci monitoru.</p>
            <div style="overflow-x: auto;">
                <table class="admin-table">
                    <thead><tr><th>Jméno</th><th>Počet monitorů</th><th>Přejmenovat</th></tr></thead>
                    <tbody>
                        <?php foreach ($multi_assets as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['name']); ?></td>
                                <td><?php echo (int)$a['member_count']; ?></td>
                                <td>
                                    <form method="POST" style="display: flex; gap: 0.4rem;">
                                        <?php echo bk_csrf_field(); ?>
                                        <input type="hidden" name="asset_id" value="<?php echo (int)$a['id']; ?>">
                                        <input type="text" name="asset_name" value="<?php echo htmlspecialchars($a['name']); ?>" class="form-control" style="font-size: 0.8rem; padding: 0.3rem 0.5rem;">
                                        <button type="submit" name="rename_asset" value="1" class="btn btn-secondary btn-sm"><i class="fas fa-save"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <!-- Seznam monitorů - vlastní plná šířka, aby se tabulka nemusela vejít vedle jiné karty -->
        <div class="admin-card">
                    <div class="admin-header">
                        <h2><i class="fas fa-list-ul"></i> Sledované servery a služby</h2>
                        <button type="button" onclick="openMonitorModal()" class="btn btn-sm"><i class="fas fa-plus"></i> Přidat monitor</button>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Název</th>
                                    <th class="hide-mobile">Kategorie</th>
                                    <th class="hide-mobile">Typ</th>
                                    <th>Cíl</th>
                                    <th>Notifikace</th>
                                    <th>Stav</th>
                                    <th>Akce</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_monitors)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">Zatím nejsou nastaveny žádné monitory.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($all_monitors as $mon): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($mon['name']); ?></strong>
                                                <?php 
                                                $det = json_decode($mon['last_details'] ?? '', true);
                                                if (!empty($mon['agent_key'])): 
                                                    $version = $det['version'] ?? null;
                                                    $agent_last_seen = $det['agent_last_seen'] ?? null;
                                                    if ($agent_last_seen) {
                                                        $diff = time() - intval($agent_last_seen);
                                                        $mins = round($diff / 60);
                                                        
                                                        $offline_timeout_mins = intval(get_setting('agent_offline_timeout', '50'));
                                                        $offline_timeout_secs = $offline_timeout_mins * 60;
                                                        
                                                        if ($diff > $offline_timeout_secs) {
                                                            $time_lbl = ($mins > 1440) ? round($mins / 1440) . ' dny' : ($mins > 60 ? round($mins / 60) . ' hod' : $mins . ' min');
                                                            echo "<span style='color: var(--color-red); font-size: 0.72rem; display: block; margin-top: 0.2rem; font-weight: bold;'><i class='fas fa-exclamation-triangle'></i> Agent neaktivní (před {$time_lbl}) - zkontrolujte agenta!</span>";
                                                        } else {
                                                            $time_lbl = ($mins <= 0) ? 'nyní' : "před {$mins} min";
                                                            $ver_str = $version ? " v" . htmlspecialchars($version) : "";
                                                            echo "<span style='color: var(--color-green); font-size: 0.72rem; display: block; margin-top: 0.2rem;'><i class='fas fa-check-circle'></i> Agent{$ver_str} (aktivní {$time_lbl})</span>";
                                                        }
                                                        
                                                        if (!empty($det['os']) || !empty($det['uptime']) || !empty($det['ports']) || !empty($det['processes'])): 
                                                        ?>
                                                            <div style="font-size: 0.65rem; color: var(--text-muted); margin-top: 0.25rem; line-height: 1.3; background: rgba(0,0,0,0.15); padding: 0.35rem 0.5rem; border-radius: 4px; border: 1px solid rgba(255,255,255,0.03); max-width: 250px;">
                                                                <?php if (!empty($det['os'])): ?>
                                                                    <strong>OS:</strong> <?php echo htmlspecialchars($det['os']); ?><br>
                                                                <?php endif; ?>
                                                                <?php if (!empty($det['uptime'])): ?>
                                                                    <strong>Uptime:</strong> <?php echo format_uptime_cz($det['uptime']); ?><br>
                                                                <?php endif; ?>
                                                                <?php if (!empty($det['ports'])): ?>
                                                                    <strong>Porty:</strong> <?php echo implode(', ', $det['ports']); ?><br>
                                                                <?php endif; ?>
                                                                <?php if (!empty($det['processes'])): 
                                                                    $proc_arr = array_filter(array_unique($det['processes']));
                                                                    ?>
                                                                    <strong>Procesy:</strong> <?php echo htmlspecialchars(implode(', ', array_slice($proc_arr, 0, 8))) . (count($proc_arr) > 8 ? '...' : ''); ?><br>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php
                                                    } else {
                                                        if ($mon['type'] === 'vps') {
                                                            echo "<span style='color: var(--text-muted); font-size: 0.72rem; display: block; margin-top: 0.2rem; font-style: italic;'><i class='fas fa-clock'></i> Agent: Čeká se na první data...</span>";
                                                        }
                                                    }
                                                endif;
                                                
                                                if (isset($det['api_fallback']) && $det['api_fallback'] === true): ?>
                                                    <br><span class="category-badge" style="background: var(--color-yellow); font-size: 0.65rem; padding: 2px 6px; display: inline-block; margin-top: 0.25rem;" title="Přímé TCP připojení selhalo, stav je stahován ze záložního mcsrvstat.us API.">Záložní API</span>
                                                <?php endif; ?>
                                                <?php if (!empty($mon['notes'])): ?>
                                                    <br><span style="font-size: 0.7rem; color: var(--text-muted); font-style: italic; display: inline-block; margin-top: 0.15rem;" title="<?php echo htmlspecialchars($mon['notes']); ?>"><i class="fas fa-sticky-note" style="margin-right: 0.25rem;"></i> <?php echo htmlspecialchars(substr($mon['notes'], 0, 35)) . (strlen($mon['notes']) > 35 ? '...' : ''); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="hide-mobile"><span class="category-badge"><?php echo htmlspecialchars($mon['category'] ?: 'Ostatní'); ?></span></td>
                                            <td class="hide-mobile"><span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.35rem;"><?php echo monitor_type_icon($mon['type'], $mon['target'], '0.85rem'); ?> <?php echo htmlspecialchars($mon['type']); ?></span></td>
                                            <td data-label="Cíl">
                                                <span style="font-size: 0.85rem;" title="<?php echo htmlspecialchars($mon['target']); ?>">
                                                    <?php 
                                                    if ($mon['type'] === 'discord') {
                                                        echo 'Guild ID: ' . htmlspecialchars(substr($mon['target'], 0, 10)) . '...';
                                                    } elseif ($mon['type'] === 'teamspeak') {
                                                        echo htmlspecialchars(substr($mon['target'], 0, 30));
                                                    } else {
                                                        echo htmlspecialchars(substr($mon['target'], 0, 30)) . ($mon['port'] ? ':'.$mon['port'] : ''); 
                                                    }
                                                    ?>
                                                </span>
                                            </td>
                                            <td data-label="Notifikace">
                                                <a href="#" onclick="bkPostAction({toggle_notif: '1', field: 'email', id: <?php echo (int)$mon['id']; ?>}); return false;" class="notif-toggle-link" title="Přepnout E-mail notifikace">
                                                    <span style="color: <?php echo $mon['email_notifications'] ? 'var(--color-green)' : 'var(--text-muted)'; ?>; margin-right: 0.5rem;"><i class="fas fa-envelope"></i></span>
                                                </a>
                                                <a href="#" onclick="bkPostAction({toggle_notif: '1', field: 'sms', id: <?php echo (int)$mon['id']; ?>}); return false;" class="notif-toggle-link" title="Přepnout WhatsApp / SMS notifikace">
                                                    <?php if ($mon['sms_notifications']): ?>
                                                        <?php if (get_setting('sms_gateway_type') === 'whatsapp'): ?>
                                                            <span style="color: var(--color-green);"><i class="fab fa-whatsapp" style="font-size: 1.1rem; vertical-align: middle;"></i></span>
                                                        <?php else: ?>
                                                            <span style="color: var(--color-green);"><i class="fas fa-comment-sms"></i></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php if (get_setting('sms_gateway_type') === 'whatsapp'): ?>
                                                            <span style="color: var(--text-muted);"><i class="fab fa-whatsapp" style="font-size: 1.1rem; vertical-align: middle;"></i></span>
                                                        <?php else: ?>
                                                            <span style="color: var(--text-muted);"><i class="fas fa-comment-sms"></i></span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </a>
                                            </td>
                                            <td data-label="Stav">
                                                <span class="status-dot <?php echo $mon['status']; ?>" style="display: inline-block; width: 10px; height: 10px;"></span>
                                            </td>
                                            <td data-label="Akce">
                                                <div class="action-btns">
                                                    <a href="admin.php?action=edit&id=<?php echo $mon['id']; ?>" class="btn btn-secondary btn-sm" title="Upravit"><i class="fas fa-edit"></i></a>
                                                    <a href="#" class="btn btn-sm" style="background: <?php echo $mon['maintenance'] ? 'rgba(245, 158, 11, 0.2)' : 'rgba(255, 255, 255, 0.05)'; ?>; border: 1px solid <?php echo $mon['maintenance'] ? '#f59e0b' : 'var(--border-color)'; ?>; color: <?php echo $mon['maintenance'] ? '#f59e0b' : 'var(--text-secondary)'; ?>;" title="<?php echo $mon['maintenance'] ? 'Ukončit režim údržby (vyčistí starý popis)' : 'Okamžitě zapnout režim údržby'; ?>" onclick="<?php if ($mon['maintenance']): ?>bkPostAction({toggle_maintenance: '1', id: <?php echo (int)$mon['id']; ?>});<?php else: ?>var d = prompt('Popis údržby (zobrazí se uživatelům, nepovinné):', ''); if (d !== null) { bkPostAction({toggle_maintenance: '1', id: <?php echo (int)$mon['id']; ?>, desc: d}); }<?php endif; ?> return false;"><i class="fas fa-wrench"></i></a>
                                                    <a href="#" onclick="if (confirm('Opravdu chcete kompletně vymazat historii měření, response grafy a logy pro tento monitor?')) bkPostAction({clear_history: '1', id: <?php echo (int)$mon['id']; ?>}); return false;" class="btn btn-warning btn-sm" title="Vymazat historii a logy"><i class="fas fa-eraser"></i></a>
                                                    <a href="#" onclick="if (confirm('Opravdu chcete smazat tento monitor?')) bkPostAction({delete_monitor: '1', id: <?php echo (int)$mon['id']; ?>}); return false;" class="btn btn-danger btn-sm" title="Smazat"><i class="fas fa-trash"></i></a>
                                                    <?php if (!empty($mon['agent_key'])): ?>
                                                        <button id="agent-btn-<?php echo $mon['id']; ?>" class="btn btn-success btn-sm" onclick="showAgentInstructions('<?php echo $mon['agent_key']; ?>', '<?php echo htmlspecialchars($mon['name']); ?>', '<?php echo htmlspecialchars($mon['type']); ?>')" title="Klíč a instalace agenta"><i class="fas fa-terminal"></i></button>
                                                    <?php elseif ($mon['type'] === 'cpanel'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="showCpanelInstructions('<?php echo htmlspecialchars($mon['name']); ?>')" title="Nastavení cPanel monitoringu"><i class="fas fa-info-circle"></i></button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Diagnostika a rady k řešení výpadků -->
                <?php 
                $problem_monitors = [];
                foreach ($all_monitors as $m) {
                    $det = json_decode($m['last_details'] ?? '', true);
                    $has_fallback = isset($det['api_fallback']) && $det['api_fallback'] === true;
                    if ($m['status'] === 'down' || $has_fallback) {
                        $original_error = $det['last_error'] ?? null;
                        if ($m['status'] === 'down') {
                            $error_desc = $m['error_message'] ?: 'Neznámá chyba';
                        } else {
                            $error_desc = 'Používá se záložní API (přímé TCP spojení selhalo). Původní chyba: ' . ($original_error ?: 'Neznámá chyba');
                        }
                        $problem_monitors[] = [
                            'mon' => $m,
                            'fallback' => $has_fallback,
                            'error' => $error_desc
                        ];
                    }
                }

                if (!empty($problem_monitors)):
                ?>
                    <div class="admin-card" style="border-top: 4px solid var(--color-yellow);">
                        <div class="admin-header">
                            <h2 style="color: var(--color-yellow);"><i class="fas fa-exclamation-triangle"></i> Diagnostika a rady k řešení výpadků</h2>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem;">
                            <?php foreach ($problem_monitors as $pm): 
                                $m = $pm['mon'];
                                $is_fallback = $pm['fallback'];
                                $err_desc = $pm['error'];
                            ?>
                                <div style="background: rgba(243, 156, 18, 0.05); border: 1px solid rgba(243, 156, 18, 0.2); padding: 1rem; border-radius: 8px;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(243, 156, 18, 0.15); padding-bottom: 0.5rem; margin-bottom: 0.5rem;">
                                        <strong><i class="fas fa-server"></i> <?php echo htmlspecialchars($m['name']); ?></strong>
                                        <span class="category-badge" style="background: <?php echo $is_fallback ? 'var(--color-yellow)' : 'var(--color-red)'; ?>; font-size: 0.7rem; color: #fff;">
                                            <?php echo $is_fallback ? 'Záložní API' : 'Výpadek (DOWN)'; ?>
                                        </span>
                                    </div>
                                    <p style="font-size: 0.85rem; color: #fff; margin-bottom: 0.5rem;"><strong>Zjištěný problém:</strong> <code><?php echo htmlspecialchars($err_desc); ?></code></p>
                                    
                                    <div style="font-size: 0.8rem; color: var(--text-secondary); line-height: 1.5; background: rgba(0,0,0,0.2); padding: 0.75rem; border-radius: 6px;">
                                        <strong style="color: #fff; display: block; margin-bottom: 0.25rem;"><i class="fas fa-wrench"></i> Doporučený postup řešení:</strong>
                                        <?php if ($m['type'] === 'minecraft' && $is_fallback): ?>
                                            1. Webhosting se pokusil připojit na IP/hostitele <code><?php echo htmlspecialchars($m['target']); ?></code> na TCP portu 25565, ale spojení bylo odmítnuto (*Connection refused*). Stav je stahován ze záložního API.<br>
                                            2. **Řešení:** Zkontrolujte, zda na vašem Minecraft serveru VPS neblokuje firewall (např. UFW/CSF) příchozí dotazy z webhostingu. Povolte port 25565 pro příchozí komunikaci: <code>ufw allow 25565/tcp</code>.<br>
                                            3. Ujistěte se, že Minecraft server je spuštěn a naslouchá na zadaném portu (výchozí 25565).
                                        <?php elseif ($m['type'] === 'teamspeak'): 
                                            $wh_ip = $_SERVER['SERVER_ADDR'] ?? null;
                                            if (!$wh_ip && function_exists('gethostname')) {
                                                $wh_ip = @gethostbyname(@gethostname());
                                            }
                                            $wh_ip = $wh_ip ?: 'IP_VASEHO_WEBHOSTINGU';
                                            
                                            $outbound_ip = null;
                                            if (function_exists('curl_init')) {
                                                $ch = curl_init();
                                                curl_setopt($ch, CURLOPT_URL, "https://api.ipify.org");
                                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                                                $res = curl_exec($ch);
                                                curl_close($ch);
                                                if ($res) {
                                                    $outbound_ip = trim($res);
                                                }
                                            }
                                        ?>
                                            1. Spojení na Query port TeamSpeaku selhalo nebo vrátilo chybový kód.<br>
                                            2. **Řešení:** Ujistěte se, že odchozí IP adresa vašeho webhostingu (lokální IP: <strong><?php echo htmlspecialchars($wh_ip); ?></strong><?php if ($outbound_ip && $outbound_ip !== $wh_ip): ?>, zjištěná odchozí IP: <strong style="color: var(--color-green);"><?php echo htmlspecialchars($outbound_ip); ?></strong><?php endif; ?>) je přidána na samostatný řádek do souboru <code>query_ip_whitelist.txt</code> v kořenovém adresáři vašeho TeamSpeak serveru na VPS a restartujte TS3 server.<br>
                                            3. Pokud používáte UFW firewall na VPS, povolte query port (např. 8219/10011): <code>ufw allow from <?php echo htmlspecialchars($outbound_ip ?: $wh_ip); ?> to any port 8219 proto tcp</code>.
                                        <?php elseif ($m['type'] === 'web'): ?>
                                            1. Webová stránka vrátila HTTP kód chyby nebo cURL timeout.<br>
                                            2. **Řešení:** Ověřte, zda webová stránka funguje v prohlížeči. Pokud ano, zkontrolujte, zda váš webový server (Nginx/Apache) nebo ddos ochrana (Cloudflare) neblokuje User-Agent <code>BloodKingsStatusBot/1.0</code>.
                                        <?php else: ?>
                                            1. Síťové připojení na zadaný cíl a port selhalo.<br>
                                            2. **Řešení:** Ověřte, zda je služba na cílovém serveru spuštěna a naslouchá na správném portu. Zkontrolujte firewall (IP adresy a porty) na straně cílového serveru.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Historie plánovaných odstávek -->
                <?php 
                $stmt_maint_logs = $pdo->query("
                    SELECT l.*, m.name 
                    FROM monitor_logs l 
                    JOIN monitors m ON l.monitor_id = m.id 
                    WHERE l.status = 'maintenance' 
                    ORDER BY l.checked_at DESC 
                    LIMIT 20
                ");
                $maint_logs = $stmt_maint_logs->fetchAll();
                ?>
                <div class="admin-card" style="border-top: 4px solid var(--color-yellow);">
                    <div class="admin-header">
                        <h2 style="color: var(--color-yellow);"><i class="fas fa-history" style="margin-right: 0.5rem;"></i> Historie plánovaných odstávek (Maintenance)</h2>
                    </div>
                    <div style="overflow-x: auto; margin-top: 1rem;">
                        <table class="admin-table" style="font-size: 0.82rem;">
                            <thead>
                                <tr>
                                    <th>Čas záznamu</th>
                                    <th>Název serveru/služby</th>
                                    <th>Detaily / Popis údržby</th>
                                    <th>Lokace</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($maint_logs)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">Žádné předchozí plánované odstávky nebyly zaznamenány.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($maint_logs as $ml): ?>
                                        <tr>
                                            <td><strong><?php echo date('d.m.Y H:i:s', strtotime($ml['checked_at'])); ?></strong></td>
                                            <td data-label="Server"><span style="color: #fff; font-weight: bold;"><?php echo htmlspecialchars($ml['name']); ?></span></td>
                                            <td data-label="Popis" style="color: var(--text-secondary);"><?php echo htmlspecialchars($ml['error_message'] ?? ''); ?></td>
                                            <td data-label="Lokace"><span style="font-size: 0.75rem; color: var(--text-muted);"><i class="fas fa-map-marker-alt" style="color: var(--color-red); margin-right: 0.15rem;"></i> <?php echo htmlspecialchars($ml['checked_from'] ?: 'Main Server'); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- VPS Agent instrukce (Modal simulace přes skryté okno) -->
                <div id="agent-instructions-card" class="admin-card" style="display: none; border-top: 4px solid var(--color-green); max-width: 1100px; width: 95%;">
                    <div class="admin-header">
                        <h2 style="color: var(--color-green);"><i class="fas fa-terminal"></i> Návod k instalaci agenta na VPS</h2>
                        <button onclick="document.getElementById('agent-instructions-card').style.display='none'" class="modal-close"><i class="fas fa-times"></i></button>
                    </div>
                    <p>Pro monitorování zátěže (CPU, RAM, HDD) serveru <strong id="agent-server-name">VPS</strong> zvolte jednu z variant instalace na vašem VPS:</p>

                    <div class="settings-tabs" role="tablist" id="agent-tabs">
                        <button type="button" data-agent-tab="agent-tab-python" class="active"><i class="fab fa-python"></i> Python 3</button>
                        <button type="button" data-agent-tab="agent-tab-bash"><i class="fas fa-terminal"></i> Bash/Shell</button>
                        <button type="button" data-agent-tab="agent-tab-powershell"><i class="fab fa-windows"></i> Windows</button>
                        <button type="button" data-agent-tab="agent-tab-docker"><i class="fab fa-docker"></i> Docker</button>
                        <button type="button" data-agent-tab="agent-tab-openwrt"><i class="fas fa-wifi"></i> OpenWrt</button>
                    </div>

                    <div class="settings-tab-panel active" id="agent-tab-python">
                        <ol style="margin-left: 1.25rem; font-size: 0.8rem; line-height: 1.7; color: var(--text-secondary);">
                            <li>Stáhněte Python agenta:<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">wget -O agent.py <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent.py', $_SERVER['REQUEST_URI']); ?></pre>
                            </li>
                            <li>Nastavte konfiguraci (buď přímo v kódu, nebo pohodlněji vytvořením souboru <code>agent.cfg</code> ve stejné složce):<br>
                                <div style="background: rgba(0,0,0,0.3); padding: 0.65rem 0.75rem; border-radius: 6px; font-size: 0.85rem; margin-top: 0.4rem; line-height: 1.6;">
                                    <strong>Obsah agent.cfg (doporučeno pro zachování při deployi):</strong><br>
                                    API_URL = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent_api.php', $_SERVER['REQUEST_URI']); ?></span>"<br>
                                    AGENT_KEY = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;" id="agent-server-key">KLIC</span>"
                                </div>
                            </li>
                            <li>Povolte spuštění: <code>chmod +x agent.py</code></li>
                            <li>Cron úloha (<code>crontab -e</code>):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">*/5 * * * * /cesta/k/agent.py > /dev/null 2>&1</pre>
                            </li>
                            <li>Volitelně: nastavte <code>AUTO_UPDATE = True</code> (nebo v <code>agent.cfg</code> <code>AUTO_UPDATE = 1</code>), aby se agent sám aktualizoval na novější verze publikované na tomto serveru.</li>
                        </ol>
                    </div>

                    <div class="settings-tab-panel" id="agent-tab-bash">
                        <ol style="margin-left: 1.25rem; font-size: 0.8rem; line-height: 1.7; color: var(--text-secondary);">
                            <li>Stáhněte Shell agenta (nevyžaduje Python):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">wget -O agent.sh <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent.sh', $_SERVER['REQUEST_URI']); ?></pre>
                            </li>
                            <li>Nastavte konfiguraci (buď přímo v kódu, nebo pohodlněji vytvořením souboru <code>agent.cfg</code> ve stejné složce):<br>
                                <div style="background: rgba(0,0,0,0.3); padding: 0.65rem 0.75rem; border-radius: 6px; font-size: 0.85rem; margin-top: 0.4rem; line-height: 1.6;">
                                    <strong>Obsah agent.cfg (doporučeno pro zachování při deployi):</strong><br>
                                    API_URL = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent_api.php', $_SERVER['REQUEST_URI']); ?></span>"<br>
                                    AGENT_KEY = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;" id="agent-server-key-sh">KLIC</span>"
                                </div>
                            </li>
                            <li>Povolte spuštění: <code>chmod +x agent.sh</code></li>
                            <li>Cron úloha (<code>crontab -e</code>):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">*/5 * * * * /cesta/k/agent.sh > /dev/null 2>&1</pre>
                            </li>
                            <li>Volitelně: nastavte <code>AUTO_UPDATE="1"</code> pro automatické aktualizace agenta.</li>
                        </ol>
                    </div>

                    <div class="settings-tab-panel" id="agent-tab-powershell">
                        <ol style="margin-left: 1.25rem; font-size: 0.8rem; line-height: 1.7; color: var(--text-secondary);">
                            <li>Stáhněte Windows agenta (PowerShell 5.1+, bez závislostí):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">Invoke-WebRequest -Uri "<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent.ps1', $_SERVER['REQUEST_URI']); ?>" -OutFile agent.ps1</pre>
                            </li>
                            <li>Nastavte konfiguraci vytvořením souboru <code>agent.cfg</code> ve stejné složce jako <code>agent.ps1</code>:<br>
                                <div style="background: rgba(0,0,0,0.3); padding: 0.65rem 0.75rem; border-radius: 6px; font-size: 0.85rem; margin-top: 0.4rem; line-height: 1.6;">
                                    API_URL = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent_api.php', $_SERVER['REQUEST_URI']); ?></span>"<br>
                                    AGENT_KEY = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;" id="agent-server-key-ps1">KLIC</span>"
                                </div>
                            </li>
                            <li>Vytvořte naplánovanou úlohu spouštěnou každých 5 minut (spusťte v PowerShellu jako Administrátor):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument '-ExecutionPolicy Bypass -File "C:\bloodkings\agent.ps1"'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration ([TimeSpan]::MaxValue)
Register-ScheduledTask -TaskName "BloodKingsAgent" -Action $action -Trigger $trigger -RunLevel Highest</pre>
                            </li>
                            <li>Volitelně: nastavte v <code>agent.cfg</code> <code>AUTO_UPDATE = "1"</code> pro automatické aktualizace agenta.</li>
                        </ol>
                    </div>

                    <div class="settings-tab-panel" id="agent-tab-docker">
                        <ol style="margin-left: 1.25rem; font-size: 0.8rem; line-height: 1.7; color: var(--text-secondary);">
                            <li>Stáhněte <code>agent.py</code> a <code>docker-compose.agent.yml</code> na server do jedné složky:<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">wget -O agent.py <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent.py', $_SERVER['REQUEST_URI']); ?>
wget -O docker-compose.agent.yml <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'docker-compose.agent.yml', $_SERVER['REQUEST_URI']); ?></pre>
                            </li>
                            <li>V <code>docker-compose.agent.yml</code> vyplňte proměnné prostředí:<br>
                                <div style="background: rgba(0,0,0,0.3); padding: 0.65rem 0.75rem; border-radius: 6px; font-size: 0.85rem; margin-top: 0.4rem; line-height: 1.6;">
                                    STATUS_API_URL: "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent_api.php', $_SERVER['REQUEST_URI']); ?></span>"<br>
                                    STATUS_AGENT_KEY: "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;" id="agent-server-key-docker">KLIC</span>"
                                </div>
                            </li>
                            <li>Spusťte kontejner:<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">docker compose -f docker-compose.agent.yml up -d</pre>
                            </li>
                            <li>Kontejner běží s <code>pid: host</code> a připojeným kořenovým FS hostitele (<code>/:/host:ro</code>), takže hlásí metriky <strong>hostitele</strong>, ne kontejneru. Funguje na Linuxu; vyžaduje Docker Engine s podporou <code>pid: host</code> a <code>network_mode: host</code>.</li>
                            <li>Automatické aktualizace jsou v Docker režimu vypnuté (skript je připojen read-only) - novou verzi nasadíte stažením aktuálního <code>agent.py</code> a restartem kontejneru.</li>
                        </ol>
                    </div>

                    <div class="settings-tab-panel" id="agent-tab-openwrt">
                        <p style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.75rem;">Pro OpenWrt/TurrisOS routery - nevyžaduje Python ani bash, jen standardní BusyBox ash a <code>ubus</code> (tedy prakticky jakýkoli router s OpenWrt). Žádný přístup zvenčí není potřeba - router se sám ozývá ven.</p>
                        <ol style="margin-left: 1.25rem; font-size: 0.8rem; line-height: 1.7; color: var(--text-secondary);">
                            <li>Stáhněte OpenWrt agenta přímo na router (přes SSH):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">wget -O /root/agent_openwrt.sh <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent_openwrt.sh', $_SERVER['REQUEST_URI']); ?></pre>
                                Pokud router nemá SSL podporu ve <code>wget</code>, použijte <code>uclient-fetch</code> místo <code>wget</code>.
                            </li>
                            <li>Nastavte konfiguraci vytvořením souboru <code>agent_openwrt.cfg</code> ve stejné složce:<br>
                                <div style="background: rgba(0,0,0,0.3); padding: 0.65rem 0.75rem; border-radius: 6px; font-size: 0.85rem; margin-top: 0.4rem; line-height: 1.6;">
                                    API_URL = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;"><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'agent_api.php', $_SERVER['REQUEST_URI']); ?></span>"<br>
                                    AGENT_KEY = "<span style="color: var(--color-green); font-family: monospace; word-break: break-all;" id="agent-server-key-openwrt">KLIC</span>"
                                </div>
                            </li>
                            <li>Volitelné - Remote Actions (restart WAN/WireGuard, reboot, obnova DHCP na dálku z administrace). Bez tohoto kroku router žádnou vzdálenou akci nikdy neprovede, i kdyby ji administrace zařadila do fronty:<br>
                                <div style="background: rgba(0,0,0,0.3); padding: 0.65rem 0.75rem; border-radius: 6px; font-size: 0.85rem; margin-top: 0.4rem; line-height: 1.6;">
                                    REMOTE_ACTIONS_ENABLED = "<span style="color: var(--color-green); font-family: monospace;">1</span>"<br>
                                    ALLOWED_ACTIONS = "<span style="color: var(--color-green); font-family: monospace;">restart_wan,restart_wireguard,reboot_router,renew_dhcp,restart_service,reconnect_pppoe</span>"
                                </div>
                                <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.35rem;">Toto povoluje akce jen na straně routeru. Musíte je navíc zaškrtnout i zde v administraci u tohoto monitoru (sekce "Remote Actions" ve formuláři) - server odešle akci jen tehdy, když ji povolují OBĚ strany.</small>
                            </li>
                            <li>Povolte spuštění: <code>chmod +x /root/agent_openwrt.sh</code></li>
                            <li>Nejdřív spusťte ručně a zkontrolujte log, než ho zařadíte do cronu:<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">/root/agent_openwrt.sh</pre>
                            </li>
                            <li>Přidejte do crontabu (OpenWrt má <code>crond</code> ve výchozí instalaci):<br>
                                <pre style="background: rgba(0,0,0,0.4); padding: 0.65rem 0.75rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.85rem; margin-top: 0.4rem; white-space: pre-wrap; word-break: break-all;">echo '*/5 * * * * /root/agent_openwrt.sh > /dev/null 2>&1' >> /etc/crontabs/root
/etc/init.d/cron restart</pre>
                            </li>
                        </ol>
                    </div>
                </div>

                <script>
                (function() {
                    const tabsBar = document.getElementById('agent-tabs');
                    if (!tabsBar) return;
                    const buttons = tabsBar.querySelectorAll('button[data-agent-tab]');
                    const panels = document.querySelectorAll('#agent-instructions-card .settings-tab-panel');
                    buttons.forEach((btn) => {
                        btn.addEventListener('click', () => {
                            buttons.forEach((b) => b.classList.toggle('active', b === btn));
                            panels.forEach((p) => p.classList.toggle('active', p.id === btn.dataset.agentTab));
                        });
                    });
                })();
                </script>

                <!-- cPanel Agent instrukce -->
                <div id="cpanel-instructions-card" class="admin-card" style="display: none; border-top: 4px solid var(--color-green);">
                    <div class="admin-header">
                        <h2 style="color: var(--color-green);"><i class="fas fa-info-circle"></i> Nastavení cPanel monitoringu</h2>
                        <button onclick="document.getElementById('cpanel-instructions-card').style.display='none'" class="modal-close"><i class="fas fa-times"></i></button>
                    </div>
                    <p>Pro sledování zátěže (Disk, RAM, Procesy, MySQL, PostgreSQL, Bandwidth) vašeho cPanel hostingu pro <strong id="cpanel-server-name">Web</strong> proveďte následující kroky:</p>
                    <ol style="margin-left: 1.5rem; margin-top: 1rem; line-height: 1.8;">
                        <li>Vezměte soubor <code>cpanel_stats.php</code> ze složky <code>status/</code> ve vašem staženém status projektu.</li>
                        <li>Nahrajte ho do kořenové složky vašeho sledovaného webu (např. do <code>public_html/cpanel_stats.php</code>).</li>
                        <li>Otevřete nahraný soubor v editoru a změňte konstantu <code>STATS_KEY</code> na vlastní dlouhé tajné heslo:<br>
                            <code>define('STATS_KEY', 'VasVlastniTajnyKlic123!');</code>
                        </li>
                        <li>Zde v administraci vytvořte nebo upravte monitor, zvolte typ **cPanel Hosting** a do cíle vložte plnou URL adresu souboru i s vaším klíčem jako parametr:<br>
                            <code style="color: var(--color-green);">https://bloodkings.eu/cpanel_stats.php?key=VasVlastniTajnyKlic123!</code>
                        </li>
                    </ol>
                </div>



                    <?php
                    // Zjistit nedávné lokace agentů z logů
                    try {
                        $stmt_agents = $pdo->query("SELECT DISTINCT checked_from, COUNT(*) as cnt, MAX(checked_at) as last_seen
                            FROM monitor_logs
                            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                            GROUP BY checked_from
                            ORDER BY last_seen DESC
                            LIMIT 20");
                        $agents = $stmt_agents->fetchAll();
                        if ($agents):
                    ?>
                    <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                        <h3 style="font-size: 0.82rem; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.75rem;">
                            <i class="fas fa-satellite-dish"></i> Monitorovací agenti
                        </h3>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 290px; overflow-y: auto; padding-right: 0.25rem;">
                            <?php foreach ($agents as $ag):
                                $last_seen = new DateTime($ag['last_seen']);
                                $now = new DateTime();
                                $diff_min = round(($now->getTimestamp() - $last_seen->getTimestamp()) / 60);
                                // Color coding: active < 15 min, warning 15-60 min, inactive > 60 min
                                if ($diff_min < 15) {
                                    $dot_color = 'var(--color-green)';
                                    $badge_status = 'Aktivní';
                                    $status_color = 'var(--color-green)';
                                } elseif ($diff_min < 60) {
                                    $dot_color = '#f59e0b';
                                    $badge_status = 'Varování';
                                    $status_color = '#f59e0b';
                                } else {
                                    $dot_color = 'var(--color-red)';
                                    $badge_status = 'Neaktivní';
                                    $status_color = 'var(--color-red)';
                                }
                                $time_ago = $diff_min < 2 ? 'právě teď'
                                    : ($diff_min < 60 ? "před {$diff_min} min"
                                    : ($diff_min < 1440 ? 'před ' . round($diff_min / 60) . ' hod'
                                    : 'před ' . round($diff_min / 1440) . ' dny'));
                            ?>
                            <div class="agent-card-item">
                                <div style="width:8px;height:8px;border-radius:50%;background:<?php echo $dot_color; ?>;flex-shrink:0;box-shadow:0 0 6px <?php echo $dot_color; ?>;"></div>
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:0.82rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?php echo htmlspecialchars($ag['checked_from']); ?>
                                    </div>
                                    <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.1rem;">
                                        Poslední měření: <strong style="color:var(--text-secondary);"><?php echo $time_ago; ?></strong>
                                        &nbsp;·&nbsp; <?php echo number_format($ag['cnt']); ?> měření / 24h
                                    </div>
                                </div>
                                <span style="font-size:0.7rem;font-weight:600;color:<?php echo $status_color; ?>;text-transform:uppercase;flex-shrink:0;">
                                    <?php echo $badge_status; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="font-size:0.72rem;color:var(--text-muted);margin-top:0.75rem;">
                            <i class="fas fa-info-circle"></i>
                            Agent je <strong style="color:var(--color-green);">aktivní</strong> pokud měřil v posledních 15 min,
                            <strong style="color:#f59e0b;">varování</strong> 15–60 min, <strong style="color:var(--color-red);">neaktivní</strong> nad 60 min.
                            <a href="https://github.com/BKPepe/monitoring-agent/blob/main/README.md" target="_blank" rel="noopener" style="color:var(--color-green);text-decoration:none;">
                                <i class="fab fa-github"></i> Setup agentů
                            </a>
                        </p>
                    </div>
                    <?php endif; } catch (Exception $e) {} ?>

                <!-- Formulář nastavení systému -->
                <div class="admin-card">
                    <div class="admin-header">
                        <h2><i class="fas fa-cogs"></i> Nastavení systému a notifikací</h2>
                    </div>
                    
                    <form action="admin.php" method="POST" id="settings-form">
                        <?php echo bk_csrf_field(); ?>
                        <div class="settings-tabs" role="tablist">
                            <button type="button" data-tab="tab-obecne" class="active"><i class="fas fa-sliders-h"></i> Obecné</button>
                            <button type="button" data-tab="tab-notifikace"><i class="fas fa-bell"></i> Notifikace</button>
                            <button type="button" data-tab="tab-integrace"><i class="fas fa-plug"></i> Integrace</button>
                            <button type="button" data-tab="tab-vzhled"><i class="fas fa-paint-brush"></i> Vzhled</button>
                        </div>

                        <div class="settings-tab-panel active" id="tab-obecne">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="site_title">Název status stránky</label>
                                <input type="text" name="site_title" id="site_title" value="<?php echo htmlspecialchars(get_setting('site_title')); ?>" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="site_url">Veřejná URL status stránky (bez lomítka na konci)</label>
                                <input type="url" name="site_url" id="site_url" value="<?php echo htmlspecialchars(get_setting('site_url')); ?>" class="form-control" placeholder="https://status.vasedomena.cz">
                                <small style="font-size: 0.75rem; color: var(--text-muted);">Používá se k prokliku z e-mailů (digest, upozornění) zpět na konkrétní monitor - bez vyplnění se v e-mailech nezobrazí odkazy, jen text.</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cron_key">Cron Bezpečnostní Klíč (URL parametr ?key=...)</label>
                                <input type="text" name="cron_key" id="cron_key" value="<?php echo htmlspecialchars(get_setting('cron_key')); ?>" class="form-control" placeholder="Např. secure123key">
                                <small style="font-size: 0.75rem; color: var(--text-muted);">
                                    Cron URL: <code><?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . str_replace('admin.php', 'cron.php', $_SERVER['REQUEST_URI']); ?><?php echo get_setting('cron_key') ? '?key='.get_setting('cron_key') : ''; ?></code>
                                </small>
                            </div>
                        </div>
                        
                        <div class="form-group" style="margin-top: 1rem;">
                            <label for="sla_goal_pct">Cílová dostupnost SLA (%)</label>
                            <input type="number" name="sla_goal_pct" id="sla_goal_pct" value="<?php echo htmlspecialchars(get_setting('sla_goal_pct', '99.95')); ?>" class="form-control" min="0" max="100" step="0.01" style="max-width: 200px;">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Používá se v měsíčním infrastructure reportu (SLA vs Goal).</small>
                        </div>

                        <div class="form-group" style="margin-top: 1rem;">
                            <label for="ts3_latest_version">Poslední známá verze TeamSpeak serveru (volitelné)</label>
                            <input type="text" name="ts3_latest_version" id="ts3_latest_version" value="<?php echo htmlspecialchars(get_setting('ts3_latest_version', '')); ?>" class="form-control" placeholder="Např. 3.13.7" style="max-width: 200px;">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Ručně zadaná hodnota pro zobrazení "Update Available" u TeamSpeak monitorů. Prázdné = kontrola verze se přeskočí (nekontroluje se automaticky přes internet).</small>
                        </div>

                        <div class="form-group" style="margin-top: 1rem;">
                            <label for="cron_location">Lokace hlavního serveru (necháte prázdné = AUTO detekce)</label>
                            <input type="text" name="cron_location" id="cron_location" value="<?php echo htmlspecialchars(get_setting('cron_location', '')); ?>" class="form-control" placeholder="Necháte prázdné pro automatickou detekci nebo zadejte např. 🇩🇪 Frankfurt, DE">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Necháte prázdné (nebo <code>AUTO</code>) = automaticky se zjistí lokace dle IP vašeho hostingu. Nebo zadejte vlastní název (např. <code>🇨🇿 Praha, CZ</code>).
                            </small>
                            <br>
                            <?php 
                            $detected_loc = get_setting('ip_loc_local');
                            if ($detected_loc): ?>
                                <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--color-green);">
                                    <i class="fas fa-map-marker-alt"></i> Automaticky detekovaná lokace serveru: <strong><?php echo htmlspecialchars($detected_loc); ?></strong>
                                    <a href="#" onclick="bkPostAction({redetect_location: '1'}); return false;" class="btn btn-secondary btn-sm" style="display: inline-block; padding: 0.15rem 0.4rem; font-size: 0.7rem; margin-left: 0.5rem; border-radius: 4px;" title="Vynutí opětovný dotaz na IP geolokační API"><i class="fas fa-sync-alt"></i> Znovu detekovat</a>
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">
                                    <i class="fas fa-info-circle"></i> Lokace hostingu dosud nebyla automaticky zjištěna (zjistí se sama při příštím běhu cronu).
                                    <a href="#" onclick="bkPostAction({redetect_location: '1'}); return false;" class="btn btn-secondary btn-sm" style="display: inline-block; padding: 0.15rem 0.4rem; font-size: 0.7rem; margin-left: 0.5rem; border-radius: 4px;"><i class="fas fa-sync-alt"></i> Detekovat nyní</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        </div>

                        <div class="settings-tab-panel" id="tab-notifikace">
                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 0 0 1rem 0; text-transform: uppercase;">E-mailové notifikace (SMTP / Odchozí e-mail)</h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                            Nastavte si SMTP připojení pro bezpečné a spolehlivé odesílání notifikací. Pokud necháte SMTP Server prázdný, použije se výchozí PHP funkce <code>mail()</code>.
                        </p>

                        <div class="form-group" style="max-width: 280px;">
                            <label for="email_lang">Jazyk odchozích e-mailů</label>
                            <select name="email_lang" id="email_lang" class="form-control">
                                <option value="cs" <?php echo get_setting('email_lang', 'cs') === 'cs' ? 'selected' : ''; ?>>Čeština</option>
                                <option value="en" <?php echo get_setting('email_lang', 'cs') === 'en' ? 'selected' : ''; ?>>English</option>
                            </select>
                            <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.25rem;">Platí pro digest reporty, výstražná upozornění a testovací e-mail - nezávisí na jazyce prohlížeče, e-maily nemají "návštěvníka".</small>
                        </div>

                        <?php
                        $is_smtp_env = is_setting_env_defined('smtp_host') || is_setting_env_defined('smtp_port') || is_setting_env_defined('smtp_user') || is_setting_env_defined('smtp_pass');
                        ?>

                        <?php if (!$is_smtp_env): ?>
                        <div class="form-group">
                            <label for="smtp_user">Odesílatel zpráv (E-mailový účet / From E-mail)</label>
                            <input type="email" name="smtp_user" id="smtp_user" value="<?php echo htmlspecialchars(get_setting('smtp_user', '')); ?>" class="form-control" placeholder="např. status@vasedomena.cz">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="smtp_host">SMTP Server (Host)</label>
                                <input type="text" name="smtp_host" id="smtp_host" value="<?php echo htmlspecialchars(get_setting('smtp_host')); ?>" class="form-control" placeholder="např. smtp.vasedomena.cz">
                            </div>
                            <div class="form-group">
                                <label for="smtp_port">SMTP Port</label>
                                <input type="text" name="smtp_port" id="smtp_port" value="<?php echo htmlspecialchars(get_setting('smtp_port', '465')); ?>" class="form-control" placeholder="465">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="smtp_pass">SMTP Heslo</label>
                                <input type="password" name="smtp_pass" id="smtp_pass" value="<?php echo htmlspecialchars(get_setting('smtp_pass')); ?>" class="form-control" placeholder="Heslo k e-mailové schránce" autocomplete="new-password">
                            </div>
                            <div class="form-group">
                                <label for="smtp_secure">SMTP Zabezpečení</label>
                                <select name="smtp_secure" id="smtp_secure" class="form-control">
                                    <option value="ssl" <?php echo get_setting('smtp_secure', 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL (Port 465)</option>
                                    <option value="tls" <?php echo get_setting('smtp_secure') === 'tls' ? 'selected' : ''; ?>>TLS (Port 587)</option>
                                    <option value="none" <?php echo get_setting('smtp_secure') === 'none' ? 'selected' : ''; ?>>Bez zabezpečení</option>
                                </select>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="form-group" style="background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 8px; padding: 0.75rem 1rem;">
                            <p style="font-size: 0.8rem; color: var(--text-secondary); margin: 0;">
                                <i class="fas fa-lock" style="color: #3b82f6; margin-right: 0.4rem;"></i>
                                SMTP je nastaveno pevně v <code>config.php</code> (nebo proměnné prostředí serveru) a nelze ho změnit odsud - úprava databáze by neměla žádný efekt. Pokud potřebujete změnit přihlašovací údaje, upravte je v <code>config.php</code> (příp. v GitHub Actions secretu <code>STATUS_CONFIG_PHP</code>, pokud nasazujete přes CI).
                            </p>
                        </div>
                        <?php endif; ?>

                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 1.5rem 0 1rem 0; text-transform: uppercase;">SMS Gateway Notifikace</h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                            SMS a WhatsApp jsou dva nezávislé kanály - lze mít aktivní jen SMS bránu, jen WhatsApp, nebo oba zároveň.
                        </p>

                        <div class="form-group">
                            <label for="sms_gateway_type">Placená SMS brána</label>
                            <select name="sms_gateway_type" id="sms_gateway_type" class="form-control" onchange="toggleSMSFields(this.value)">
                                <option value="" <?php echo get_setting('sms_gateway_type', '') === '' ? 'selected' : ''; ?>>Žádná (SMS notifikace vypnuty)</option>
                                <option value="twilio" <?php echo get_setting('sms_gateway_type') === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                                <option value="smsbrana" <?php echo get_setting('sms_gateway_type') === 'smsbrana' ? 'selected' : ''; ?>>SMSbrana.cz</option>
                            </select>
                        </div>

                        <!-- Twilio pole -->
                        <div id="twilio-fields" style="display: <?php echo get_setting('sms_gateway_type') === 'twilio' ? 'block' : 'none'; ?>;">
                            <div class="form-group">
                                <label for="twilio_sid">Twilio Account SID</label>
                                <input type="text" name="twilio_sid" id="twilio_sid" value="<?php echo htmlspecialchars(get_setting('twilio_sid')); ?>" class="form-control">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="twilio_token">Twilio Auth Token</label>
                                    <input type="password" name="twilio_token" id="twilio_token" value="<?php echo htmlspecialchars(get_setting('twilio_token')); ?>" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="twilio_from">Twilio Odesílací číslo (From Number)</label>
                                    <input type="text" name="twilio_from" id="twilio_from" value="<?php echo htmlspecialchars(get_setting('twilio_from')); ?>" class="form-control" placeholder="+1234567890">
                                </div>
                            </div>
                        </div>

                        <!-- SMS Brána pole -->
                        <div id="smsbrana-fields" style="display: <?php echo get_setting('sms_gateway_type') === 'smsbrana' ? 'block' : 'none'; ?>;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="smsbrana_user">SMS Brána - Přihlašovací jméno (API)</label>
                                    <input type="text" name="smsbrana_user" id="smsbrana_user" value="<?php echo htmlspecialchars(get_setting('smsbrana_user')); ?>" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="smsbrana_password">SMS Brána - Bezpečnostní heslo (API)</label>
                                    <input type="password" name="smsbrana_password" id="smsbrana_password" value="<?php echo htmlspecialchars(get_setting('smsbrana_password')); ?>" class="form-control">
                                </div>
                            </div>
                        </div>

                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 1.5rem 0 1rem 0; text-transform: uppercase;">VPS Agent Nastavení</h3>
                        <div class="form-group">
                            <label for="agent_offline_timeout">Časový limit pro označení agenta za offline (minuty)</label>
                            <input type="number" name="agent_offline_timeout" id="agent_offline_timeout" value="<?php echo htmlspecialchars(get_setting('agent_offline_timeout', '50')); ?>" class="form-control" min="0" max="1440" required>
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Doba neaktivity (v minutách), po které bude agent považován za odpojeného a obdržíte upozornění. Hodnota <code>0</code> detekci neaktivity agenta úplně vypne (monitor zůstane v posledním nahlášeném stavu, žádná "VPS AGENT NEAKTIVNÍ" upozornění).</small>
                        </div>
                        <div class="form-group" style="display: flex; flex-direction: column; gap: 0.6rem; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 0.85rem 1rem; border-radius: 8px;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.88rem;">
                                <input type="checkbox" name="agent_notifications_enabled" value="1" <?php echo get_setting('agent_notifications_enabled', '1') === '1' ? 'checked' : ''; ?> style="width: auto; margin: 0;">
                                <span>Upozorňovat na překročení limitů CPU/RAM/HDD (prahové hodnoty se nastavují u každého VPS monitoru zvlášť)</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.88rem;">
                                <input type="checkbox" name="agent_notify_admin_only" value="1" <?php echo get_setting('agent_notify_admin_only', '1') === '1' ? 'checked' : ''; ?> style="width: auto; margin: 0;">
                                <span>Upozornění VPS agenta doručovat pouze administrátorům (ne běžným odběratelům)</span>
                            </label>
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Druhá volba se týká obou interních událostí agenta (neaktivní agent i překročené limity). Běžná upozornění na výpadek/obnovení služby zůstávají beze změny.
                            </small>
                        </div>

                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 1.5rem 0 1rem 0; text-transform: uppercase;">Webhooky a externí notifikace</h3>
                        <div class="form-group">
                            <label for="discord_webhook_url">Discord Webhook URL</label>
                            <input type="url" name="discord_webhook_url" id="discord_webhook_url" value="<?php echo htmlspecialchars(get_setting('discord_webhook_url')); ?>" class="form-control" placeholder="https://discord.com/api/webhooks/...">
                        </div>
                        <div class="form-group">
                            <label for="slack_webhook_url">Slack Webhook URL</label>
                            <input type="url" name="slack_webhook_url" id="slack_webhook_url" value="<?php echo htmlspecialchars(get_setting('slack_webhook_url')); ?>" class="form-control" placeholder="https://hooks.slack.com/services/...">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="telegram_bot_token">Telegram Bot Token</label>
                                <input type="text" name="telegram_bot_token" id="telegram_bot_token" value="<?php echo htmlspecialchars(get_setting('telegram_bot_token')); ?>" class="form-control" placeholder="123456789:ABCdefGhI...">
                            </div>
                            <div class="form-group">
                                <label for="telegram_chat_id">Telegram Chat ID</label>
                                <input type="text" name="telegram_chat_id" id="telegram_chat_id" value="<?php echo htmlspecialchars(get_setting('telegram_chat_id')); ?>" class="form-control" placeholder="-100123456789">
                            </div>
                        </div>

                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 1.5rem 0 1rem 0; text-transform: uppercase;">Pushover & PagerDuty</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="pushover_user_key">Pushover User Key</label>
                                <input type="text" name="pushover_user_key" id="pushover_user_key" value="<?php echo htmlspecialchars(get_setting('pushover_user_key')); ?>" class="form-control" placeholder="uQiROw1C4K3Y...">
                            </div>
                            <div class="form-group">
                                <label for="pushover_api_token">Pushover API Token</label>
                                <input type="password" name="pushover_api_token" id="pushover_api_token" value="<?php echo htmlspecialchars(get_setting('pushover_api_token')); ?>" class="form-control" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="pagerduty_routing_key">PagerDuty Integration / Routing Key</label>
                            <input type="password" name="pagerduty_routing_key" id="pagerduty_routing_key" value="<?php echo htmlspecialchars(get_setting('pagerduty_routing_key')); ?>" class="form-control" autocomplete="new-password">
                        </div>

                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 1.5rem 0 1rem 0; text-transform: uppercase;">SSL Expirace & Registrace Agenta</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="ssl_alert_days">Varování před vypršením SSL (dní)</label>
                                <input type="number" name="ssl_alert_days" id="ssl_alert_days" value="<?php echo htmlspecialchars(get_setting('ssl_alert_days', '14')); ?>" class="form-control" min="1" max="90">
                            </div>
                            <div class="form-group">
                                <label for="agent_registration_token">Token pro auto-registraci agentů</label>
                                <input type="text" name="agent_registration_token" id="agent_registration_token" value="<?php echo htmlspecialchars(get_setting('agent_registration_token')); ?>" class="form-control" placeholder="TajnyRegistracniToken123">
                            </div>
                        </div>

                        </div>

                        <div class="settings-tab-panel" id="tab-vzhled">
                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 0 0 1rem 0; text-transform: uppercase;">Vlastní branding (Custom Branding)</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="custom_logo_url">Adresa loga (Logo URL)</label>
                                <input type="url" name="custom_logo_url" id="custom_logo_url" value="<?php echo htmlspecialchars(get_setting('custom_logo_url')); ?>" class="form-control" placeholder="https://example.com/logo.png">
                            </div>
                            <div class="form-group">
                                <label for="custom_color_theme">Akcentová barva (Hex Color)</label>
                                <input type="text" name="custom_color_theme" id="custom_color_theme" value="<?php echo htmlspecialchars(get_setting('custom_color_theme', '#b00020')); ?>" class="form-control" placeholder="#b00020">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="custom_nav_links">Vlastní odkazy v menu (JSON formát)</label>
                            <textarea name="custom_nav_links" id="custom_nav_links" class="form-control" rows="2" placeholder='[{"name": "Hlavní Web", "url": "https://example.com"}]'><?php echo htmlspecialchars(get_setting('custom_nav_links')); ?></textarea>
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Zadejte pole objektů ve formátu JSON: <code>[{"name": "Nápověda", "url": "..."}]</code></small>
                        </div>
                        <div class="form-group">
                            <label for="portal_url">Odkaz na nadřazený portál (nepovinné)</label>
                            <input type="url" name="portal_url" id="portal_url" value="<?php echo htmlspecialchars(get_setting('portal_url')); ?>" class="form-control" placeholder="https://vas-hlavni-web.cz nebo ../index.html">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Pokud provozujete status stránku jako součást většího webu, zadejte sem odkaz zpět - zobrazí se v menu jako "Portál" a v patičce. Necháte-li prázdné, odkaz se nezobrazí (výchozí stav pro samostatnou instalaci).</small>
                        </div>

                        </div>

                        <div class="settings-tab-panel" id="tab-integrace">
                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 0 0 1rem 0; text-transform: uppercase;">Prometheus Exporter</h3>
                        <div class="form-group">
                            <label for="metrics_token">Přístupový token pro /status/metrics.php</label>
                            <input type="text" name="metrics_token" id="metrics_token" value="<?php echo htmlspecialchars(get_setting('metrics_token')); ?>" class="form-control" placeholder="Prázdné = endpoint vypnutý" autocomplete="off">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Scraper předává token jako <code>?token=...</code> nebo hlavičkou <code>Authorization: Bearer ...</code>. Vygenerujte např. <code>openssl rand -hex 24</code>.</small>
                        </div>

                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 1.5rem 0 1rem 0; text-transform: uppercase;">Přihlášení přes GitHub (SSO / OIDC)</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="oauth_github_client_id">GitHub Client ID</label>
                                <input type="text" name="oauth_github_client_id" id="oauth_github_client_id" value="<?php echo htmlspecialchars(get_setting('oauth_github_client_id')); ?>" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="oauth_github_client_secret">GitHub Client Secret</label>
                                <input type="password" name="oauth_github_client_secret" id="oauth_github_client_secret" value="<?php echo htmlspecialchars(get_setting('oauth_github_client_secret')); ?>" class="form-control" autocomplete="new-password">
                            </div>
                        </div>
                        <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: -0.25rem;">
                            Tlačítko „Přihlásit se přes GitHub" se na přihlašovací stránce zobrazí až po vyplnění a uložení Client ID.
                            OAuth App vytvoříte na GitHubu v <strong>Settings → Developer settings → OAuth Apps</strong>;
                            jako Authorization callback URL zadejte <code><?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}"); ?></code>.
                            Přihlásit se mohou pouze uživatelé, jejichž ověřený GitHub e-mail patří existujícímu administrátorovi.
                        </small>

                        </div>

                        <button type="submit" name="save_settings" class="btn" style="margin-top: 1rem;"><i class="fas fa-save"></i> Uložit nastavení</button>
                    </form>

                    <script>
                    (function() {
                        const form = document.getElementById('settings-form');
                        if (!form) return;
                        const tabButtons = form.querySelectorAll('.settings-tabs button[data-tab]');
                        const panels = form.querySelectorAll('.settings-tab-panel');

                        function activateTab(tabId, persist) {
                            const panel = document.getElementById(tabId);
                            if (!panel) return;
                            tabButtons.forEach((b) => b.classList.toggle('active', b.dataset.tab === tabId));
                            panels.forEach((p) => p.classList.toggle('active', p.id === tabId));
                            if (persist) {
                                try { localStorage.setItem('bk_settings_tab', tabId); } catch (e) {}
                            }
                        }

                        tabButtons.forEach((btn) => {
                            btn.addEventListener('click', () => activateTab(btn.dataset.tab, true));
                        });

                        // Obnovení naposledy otevřené záložky (přežije i uložení formuláře)
                        try {
                            const saved = localStorage.getItem('bk_settings_tab');
                            if (saved && document.getElementById(saved)) activateTab(saved, false);
                        } catch (e) {}

                        // Pokud browser validace zastaví odeslání kvůli poli ve skryté záložce,
                        // přepneme na záložku s daným polem, aby uživatel chybu viděl
                        form.addEventListener('invalid', (e) => {
                            const panel = e.target.closest('.settings-tab-panel');
                            if (panel && !panel.classList.contains('active')) {
                                activateTab(panel.id, true);
                            }
                        }, true);
                    })();
                    </script>
                </div>

                <!-- Formulář přidání / editace monitoru (modal) -->
                <div class="modal-overlay modal-overlay-wide" id="monitor-form-modal" style="display: <?php echo $edit_monitor ? 'flex' : 'none'; ?>;">
                <div class="admin-card" style="border-top: 4px solid var(--color-red);">
                    <div class="admin-header">
                        <h2>
                            <?php if ($edit_monitor): ?>
                                <i class="fas fa-edit"></i> Upravit server / monitor
                            <?php else: ?>
                                <i class="fas fa-plus"></i> Přidat nový server
                            <?php endif; ?>
                        </h2>
                        <button type="button" onclick="closeMonitorModal()" class="modal-close" title="Zavřít"><i class="fas fa-times"></i></button>
                    </div>

                    <form action="admin.php" method="POST">
                        <?php echo bk_csrf_field(); ?>
                        <?php if ($edit_monitor): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_monitor['id']; ?>">
                        <?php endif; ?>
                        
                        <?php
                        $service_profiles = get_service_profiles();
                        $current_profile_type = $edit_monitor['type'] ?? 'web';
                        $current_enabled_metrics = bk_get_enabled_metrics($edit_monitor ?: ['type' => $current_profile_type, 'enabled_metrics' => null]);
                        ?>
                        <div class="form-group">
                            <label><?php echo htmlspecialchars(t('profile_picker_heading')); ?></label>
                            <div class="profile-picker-grid" id="profile-picker-grid">
                                <?php foreach ($service_profiles as $p_type => $p_profile): ?>
                                    <button type="button" class="profile-picker-card<?php echo $p_type === $current_profile_type ? ' active' : ''; ?>" data-type="<?php echo htmlspecialchars($p_type); ?>" onclick="selectProfileType('<?php echo htmlspecialchars($p_type); ?>')">
                                        <i class="fas <?php echo htmlspecialchars($p_profile['icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($p_profile['label']); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="type">Typ monitoringu</label>
                            <select name="type" id="type" class="form-control" onchange="selectProfileType(this.value);">
                                <option value="web" <?php echo ($edit_monitor && $edit_monitor['type'] === 'web') ? 'selected' : ''; ?>>Webová stránka (HTTP/HTTPS)</option>
                                <option value="port" <?php echo ($edit_monitor && $edit_monitor['type'] === 'port') ? 'selected' : ''; ?>>TCP Port (libovolný port)</option>
                                <option value="vps" <?php echo ($edit_monitor && $edit_monitor['type'] === 'vps') ? 'selected' : ''; ?>>VPS Agent (CPU, RAM, Disk)</option>
                                <option value="minecraft" <?php echo ($edit_monitor && $edit_monitor['type'] === 'minecraft') ? 'selected' : ''; ?>>Minecraft server (SLP Query)</option>
                                <option value="teamspeak" <?php echo ($edit_monitor && $edit_monitor['type'] === 'teamspeak') ? 'selected' : ''; ?>>TeamSpeak server (ServerQuery)</option>
                                <option value="discord" <?php echo ($edit_monitor && $edit_monitor['type'] === 'discord') ? 'selected' : ''; ?>>Discord Server Widget</option>
                                <option value="openwrt" <?php echo ($edit_monitor && $edit_monitor['type'] === 'openwrt') ? 'selected' : ''; ?>>OpenWrt router (ubus agent)</option>
                            </select>

                            <?php foreach ($service_profiles as $p_type => $p_profile):
                                if (empty($p_profile['metrics'])) continue;
                            ?>
                                <div class="form-group metrics-checklist-group" id="metrics-checklist-<?php echo htmlspecialchars($p_type); ?>" style="margin-top: 0.75rem;<?php echo $p_type === $current_profile_type ? '' : ' display:none;'; ?>">
                                    <label><?php echo htmlspecialchars(t('profile_metrics_heading')); ?></label>
                                    <p style="color: var(--text-muted); font-size: 0.8rem; margin: 0 0 0.5rem;"><?php echo htmlspecialchars(t('profile_metrics_hint')); ?></p>
                                    <?php foreach ($p_profile['metrics'] as $metric):
                                        $is_checked = $p_type === $current_profile_type && in_array($metric['key'], $current_enabled_metrics ?? [], true);
                                    ?>
                                        <label class="metric-checkbox-label">
                                            <input type="checkbox" name="enabled_metrics[]" value="<?php echo htmlspecialchars($metric['key']); ?>" <?php echo $is_checked ? 'checked' : ''; ?> <?php echo $p_type === $current_profile_type ? '' : 'disabled'; ?>>
                                            <?php echo htmlspecialchars($metric['label']); ?>
                                            <?php if (!empty($metric['recommended'])): ?>
                                                <span class="metric-recommended-badge"><?php echo htmlspecialchars(t('profile_metric_recommended')); ?></span>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-group">
                            <label for="name">Zobrazovaný název</label>
                            <input type="text" name="name" id="name" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['name']) : ''; ?>" class="form-control" required placeholder="Např. Blood Kings Wowko">
                        </div>
                        
                        <div class="form-group">
                            <label for="target" id="target-label">Cíl (URL, Hostname, IP, Guild ID)</label>
                            <?php 
                            $target_val = '';
                            if ($edit_monitor) {
                                if ($edit_monitor['type'] === 'teamspeak') {
                                    $parts = explode(':', $edit_monitor['target']);
                                    $target_val = $parts[0];
                                } else {
                                    $target_val = $edit_monitor['target'];
                                }
                            }
                            ?>
                            <div class="password-toggle-group">
                                <input type="text" name="target" id="target" value="<?php echo htmlspecialchars($target_val); ?>" class="form-control" required placeholder="Např. https://bloodkings.eu nebo 123.45.67.89">
                                <button type="button" id="target-toggle-btn" class="password-toggle-btn" onclick="togglePasswordInput('target', 'target-toggle-btn')" style="display: none;"><i class="fas fa-eye"></i></button>
                            </div>
                            <small id="target-desc" style="font-size: 0.75rem; color: var(--text-muted);">Zadejte úplnou URL nebo IP adresu / hostname.</small>
                        </div>
                        
                        <div class="form-group" id="cpanel-stats-group" style="display: <?php echo (!$edit_monitor || $edit_monitor['type'] === 'web') ? 'block' : 'none'; ?>;">
                            <label for="cpanel_stats_url">cPanel Stats API URL (volitelné)</label>
                            <div class="password-toggle-group">
                                <input type="password" name="cpanel_stats_url" id="cpanel_stats_url" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['cpanel_stats_url'] ?? '') : ''; ?>" class="form-control" placeholder="Např. https://bloodkings.eu/cpanel_stats.php?key=Klic123">
                                <button type="button" id="cpanel-toggle-btn" class="password-toggle-btn" onclick="togglePasswordInput('cpanel_stats_url', 'cpanel-toggle-btn')"><i class="fas fa-eye"></i></button>
                            </div>
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Pokud chcete u tohoto webu sledovat i zatížení hostingu (Disk, RAM, CPU atd.), vložte sem odkaz na nahraný soubor <code>cpanel_stats.php</code> s vaším tajným klíčem.
                            </small>
                        </div>

                        <div class="form-group" id="body-keyword-group" style="display: <?php echo (!$edit_monitor || $edit_monitor['type'] === 'web') ? 'block' : 'none'; ?>;">
                            <label for="body_keyword">Ověření obsahu odpovědi (volitelné)</label>
                            <input type="text" name="body_keyword" id="body_keyword" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['body_keyword'] ?? '') : ''; ?>" class="form-control" placeholder="Např. Blood Kings">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Pokud vyplníte, kontrola ověří, že tělo odpovědi obsahuje tento řetězec (fáze "Body" v check pipeline). Prázdné = fáze se přeskočí.
                            </small>
                        </div>

                        <div class="form-group" id="port-group" style="display: <?php echo ($edit_monitor && in_array($edit_monitor['type'], ['port', 'minecraft', 'teamspeak'])) ? 'block' : 'none'; ?>;">
                            <label for="port" id="port-label">Síťový port</label>
                            <input type="number" name="port" id="port" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['port'] ?? '') : ''; ?>" class="form-control" placeholder="Minecraft: 25565, TS3 Query: 10011">
                        </div>

                        <div class="form-group" id="teamspeak-voice-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] === 'teamspeak') ? 'block' : 'none'; ?>;">
                            <label for="teamspeak_voice_port">Hlasový port (Voice Port)</label>
                            <?php 
                            $v_port = 9987;
                            if ($edit_monitor && $edit_monitor['type'] === 'teamspeak') {
                                $parts = explode(':', $edit_monitor['target']);
                                if (count($parts) === 2) {
                                    $v_port = intval($parts[1]);
                                }
                            }
                            ?>
                            <input type="number" name="teamspeak_voice_port" id="teamspeak_voice_port" value="<?php echo $v_port; ?>" class="form-control" placeholder="9987">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Zadejte hlavní port, na který se připojují uživatelé ve svém TS3 klientu.</small>
                        </div>

                        <div class="form-group" id="ts3-filetransfer-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] === 'teamspeak') ? 'block' : 'none'; ?>;">
                            <label for="ts3_filetransfer_port">File Transfer port (volitelné)</label>
                            <input type="number" name="ts3_filetransfer_port" id="ts3_filetransfer_port" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['ts3_filetransfer_port'] ?? '') : ''; ?>" class="form-control" placeholder="30033 (výchozí)">
                        </div>

                        <div class="form-group" id="sq-login-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] === 'teamspeak') ? 'block' : 'none'; ?>;">
                            <label for="sq_username">ServerQuery přihlášení (volitelné, pro hlubší data)</label>
                            <div class="form-row">
                                <input type="text" name="sq_username" id="sq_username" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['sq_username'] ?? '') : ''; ?>" class="form-control" placeholder="ServerQuery jméno" autocomplete="off">
                                <input type="password" name="sq_password" id="sq_password" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['sq_password'] ?? '') : ''; ?>" class="form-control" placeholder="ServerQuery heslo" autocomplete="new-password">
                            </div>
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Bez přihlášení funguje jen základní anonymní dotaz (dostupnost, počet klientů). S přihlášením přibudou server groups, plný seznam kanálů/klientů a hlasová aktivita (mluví/AFK/ztlumeno/nahrává).
                            </small>
                        </div>

                        <div class="form-group" id="rcon-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] === 'minecraft') ? 'block' : 'none'; ?>;">
                            <label for="rcon_port">RCON přihlášení (volitelné, pro zobrazení TPS)</label>
                            <div class="form-row">
                                <input type="number" name="rcon_port" id="rcon_port" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['rcon_port'] ?? '') : ''; ?>" class="form-control" placeholder="25575 (výchozí)">
                                <input type="password" name="rcon_password" id="rcon_password" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['rcon_password'] ?? '') : ''; ?>" class="form-control" placeholder="RCON heslo" autocomplete="new-password">
                            </div>
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Nepovinné - funguje jen na Paper/Spigot (vanilla nemá příkaz "/tps"). Bez vyplnění se zobrazují jen data z veřejného SLP dotazu (hráči, verze, MOTD) jako dosud.
                            </small>
                        </div>

                        <?php
                        $ra_enabled = $edit_monitor && !empty($edit_monitor['remote_actions_enabled']);
                        $ra_allowed = $edit_monitor ? array_filter(explode(',', (string)($edit_monitor['allowed_actions'] ?? ''))) : [];
                        $ra_action_labels = [
                            'restart_wan' => 'Restartovat WAN',
                            'restart_wireguard' => 'Restartovat WireGuard (wg0)',
                            'reboot_router' => 'Restartovat celý router',
                            'renew_dhcp' => 'Obnovit DHCP nájem na WAN',
                            'reconnect_pppoe' => 'Znovu připojit PPPoE',
                            'restart_service' => 'Restartovat službu',
                        ];
                        ?>
                        <div class="form-group" id="remote-actions-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] === 'openwrt') ? 'block' : 'none'; ?>;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: 600;">
                                <input type="checkbox" name="remote_actions_enabled" id="remote_actions_enabled" value="1" <?php echo $ra_enabled ? 'checked' : ''; ?> onchange="document.getElementById('allowed-actions-list').style.opacity = this.checked ? '1' : '0.4';">
                                Povolit Remote Actions pro tento router
                            </label>
                            <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin: 0.25rem 0 0.75rem;">
                                Ve výchozím stavu VYPNUTO. Bez zaškrtnutí server nikdy nezařadí žádnou akci do fronty pro tento konkrétní monitor, bez ohledu na to, co se pokusí odeslat administrace.
                            </small>
                            <div id="allowed-actions-list" style="display: flex; flex-direction: column; gap: 0.4rem; opacity: <?php echo $ra_enabled ? '1' : '0.4'; ?>;">
                                <?php foreach ($ra_action_labels as $ra_key => $ra_label): ?>
                                    <label class="metric-checkbox-label">
                                        <input type="checkbox" name="allowed_actions[]" value="<?php echo htmlspecialchars($ra_key); ?>" <?php echo in_array($ra_key, $ra_allowed, true) ? 'checked' : ''; ?>>
                                        <?php echo htmlspecialchars($ra_label); ?>
                                        <?php if ($ra_key === 'reboot_router'): ?>
                                            <span class="metric-recommended-badge" style="background: none; color: var(--color-red);">Restartuje celé zařízení</span>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                            <?php if ($edit_monitor && $ra_enabled && !empty($ra_allowed)): ?>
                                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color);">
                                    <label style="font-weight: 600; display: block; margin-bottom: 0.5rem;">Vyvolat akci nyní</label>
                                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                                        <?php foreach ($ra_action_labels as $ra_key => $ra_label): if (!in_array($ra_key, $ra_allowed, true)) continue; ?>
                                            <button type="button" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.75rem;" onclick="triggerRemoteAction(<?php echo (int)$edit_monitor['id']; ?>, '<?php echo htmlspecialchars($ra_key, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($ra_label, ENT_QUOTES); ?>')"><?php echo htmlspecialchars($ra_label); ?></button>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php
                                    $stmt_ra_hist = $pdo->prepare("SELECT action_type, status, created_at, executed_at, result_message FROM agent_actions WHERE monitor_id = ? ORDER BY id DESC LIMIT 5");
                                    $stmt_ra_hist->execute([$edit_monitor['id']]);
                                    $ra_history = $stmt_ra_hist->fetchAll();
                                    $ra_status_colors = ['pending' => 'var(--text-muted)', 'sent' => '#e0a800', 'executed' => 'var(--color-green, #2ecc71)', 'failed' => 'var(--color-red, #e74c3c)'];
                                    $ra_status_labels = ['pending' => 'čeká na odeslání', 'sent' => 'odesláno, čeká na potvrzení', 'executed' => 'provedeno', 'failed' => 'selhalo'];
                                    ?>
                                    <?php if (!empty($ra_history)): ?>
                                        <div style="margin-top: 0.75rem; font-size: 0.78rem;">
                                            <div style="color: var(--text-muted); text-transform: uppercase; font-size: 0.7rem; margin-bottom: 0.35rem;">Poslední akce</div>
                                            <?php foreach ($ra_history as $rh): ?>
                                                <div style="display: flex; justify-content: space-between; gap: 0.5rem; padding: 0.3rem 0; border-top: 1px solid rgba(128,128,128,0.15);" title="<?php echo htmlspecialchars($rh['result_message'] ?? ''); ?>">
                                                    <span><?php echo htmlspecialchars($ra_action_labels[$rh['action_type']] ?? $rh['action_type']); ?></span>
                                                    <span style="color: <?php echo $ra_status_colors[$rh['status']] ?? 'inherit'; ?>;"><?php echo htmlspecialchars($ra_status_labels[$rh['status']] ?? $rh['status']); ?></span>
                                                    <span style="color: var(--text-muted); white-space: nowrap;"><?php echo date('d.m. H:i', strtotime($rh['created_at'])); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php
                        // Service Discovery - "propose" krok (Phase 4). Backend ukládání
                        // objevených služeb existovalo už dřív (agent_api.php), ale nikde
                        // v adminu se to nikdy nečetlo zpátky - tenhle panel to napravuje.
                        // Import defaultně přiřadí nový monitor ke stejnému assetu jako
                        // objevující monitor (viz action_import_service výše).
                        $discovered_services = [];
                        if ($edit_monitor) {
                            $ed_details = json_decode($edit_monitor['last_details'] ?? '', true);
                            if (is_array($ed_details) && !empty($ed_details['discovered_services']) && is_array($ed_details['discovered_services'])) {
                                $discovered_services = $ed_details['discovered_services'];
                            }
                        }
                        ?>
                        <?php if (!empty($discovered_services)): ?>
                            <div class="form-group">
                                <label style="font-weight: 600; display: block; margin-bottom: 0.5rem;"><i class="fas fa-magnifying-glass"></i> Objevené služby na tomto hostu</label>
                                <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-bottom: 0.5rem;">Agent na tomto monitoru zjistil běžící služby, které se zatím nesledují. Import vytvoří nový monitor a rovnou ho přiřadí ke stejnému assetu.</small>
                                <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                                    <?php foreach ($discovered_services as $ds): ?>
                                        <?php
                                        $ds_name = $ds['name'] ?? $ds['service_name'] ?? '?';
                                        $ds_type = $ds['type'] ?? 'web';
                                        $ds_port = $ds['port'] ?? null;
                                        $ds_target = $ds['target'] ?? $edit_monitor['target'] ?? '127.0.0.1';
                                        ?>
                                        <form method="POST" style="display: flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 0.4rem 0.6rem;">
                                            <?php echo bk_csrf_field(); ?>
                                            <input type="hidden" name="service_name" value="<?php echo htmlspecialchars($ds_name); ?>">
                                            <input type="hidden" name="service_type" value="<?php echo htmlspecialchars($ds_type); ?>">
                                            <input type="hidden" name="service_port" value="<?php echo htmlspecialchars((string)$ds_port); ?>">
                                            <input type="hidden" name="service_target" value="<?php echo htmlspecialchars($ds_target); ?>">
                                            <input type="hidden" name="source_monitor_id" value="<?php echo (int)$edit_monitor['id']; ?>">
                                            <span style="flex: 1; font-size: 0.82rem;"><?php echo htmlspecialchars($ds_name); ?><?php if ($ds_port): ?> <span style="color: var(--text-muted);">:<?php echo htmlspecialchars((string)$ds_port); ?></span><?php endif; ?></span>
                                            <button type="submit" name="action_import_service" value="1" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> Sledovat</button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-group" id="processes-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] !== 'cpanel' && $edit_monitor['type'] !== 'discord' && $edit_monitor['type'] !== 'openwrt') ? 'block' : 'none'; ?>;">
                            <label for="monitored_processes">Sledované procesy (čárkou oddělené)</label>
                            <input type="text" name="monitored_processes" id="monitored_processes" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['monitored_processes'] ?? '') : ''; ?>" class="form-control" placeholder="Např. ts3server, nginx, mysql">
                            <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.25rem;">Zadejte názvy procesů, které má agent na VPS hlídat. Pokud některý z nich nepoběží, monitor bude označen jako DOWN.</small>
                        </div>

                        <div class="form-group" id="agent-thresholds-group" style="display: <?php echo ($edit_monitor && $edit_monitor['type'] !== 'cpanel' && $edit_monitor['type'] !== 'discord') ? 'block' : 'none'; ?>;">
                            <label style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Výstražné limity agenta</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                                <div>
                                    <label for="cpu_threshold" style="font-size: 0.8rem; color: var(--text-secondary);">CPU Limit (%)</label>
                                    <input type="number" name="cpu_threshold" id="cpu_threshold" value="<?php echo $edit_monitor ? intval($edit_monitor['cpu_threshold']) : '90'; ?>" class="form-control" min="10" max="100">
                                </div>
                                <div>
                                    <label for="ram_threshold" style="font-size: 0.8rem; color: var(--text-secondary);">RAM Limit (%)</label>
                                    <input type="number" name="ram_threshold" id="ram_threshold" value="<?php echo $edit_monitor ? intval($edit_monitor['ram_threshold']) : '95'; ?>" class="form-control" min="10" max="100">
                                </div>
                                <div>
                                    <label for="hdd_threshold" style="font-size: 0.8rem; color: var(--text-secondary);">HDD Limit (%)</label>
                                    <input type="number" name="hdd_threshold" id="hdd_threshold" value="<?php echo $edit_monitor ? intval($edit_monitor['hdd_threshold']) : '90'; ?>" class="form-control" min="10" max="100">
                                </div>
                            </div>
                            <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.25rem;">Zadejte hodnoty zátěže (v %), při jejichž překročení vám agent zašle upozornění (notifikaci).</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="category">Kategorie</label>
                            <input type="text" name="category" id="category" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['category'] ?? 'Weby') : 'Weby'; ?>" class="form-control" required placeholder="Weby, VPS, Herní servery...">
                        </div>

                        <?php
                        // Asset (Phase 4) - fyzické/logické zařízení, ke kterému monitor patří.
                        // Víc monitorů se stejným assetem (např. web + TeamSpeak na jednom VPS)
                        // se na veřejném dashboardu zobrazí vizuálně seskupené.
                        $stmt_all_assets = $pdo->query("SELECT a.id, a.name, COUNT(m.id) AS member_count FROM assets a LEFT JOIN monitors m ON m.asset_id = a.id GROUP BY a.id, a.name ORDER BY a.name");
                        $all_assets = $stmt_all_assets->fetchAll();
                        ?>
                        <div class="form-group">
                            <label for="asset_id">Asset (fyzické/logické zařízení)</label>
                            <select name="asset_id" id="asset_id" class="form-control">
                                <option value="">-- Vlastní nový asset (výchozí) --</option>
                                <?php foreach ($all_assets as $a): ?>
                                    <option value="<?php echo (int)$a['id']; ?>" <?php echo ($edit_monitor && (int)($edit_monitor['asset_id'] ?? 0) === (int)$a['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($a['name']); ?> (<?php echo (int)$a['member_count']; ?> <?php echo (int)$a['member_count'] === 1 ? 'monitor' : 'monitorů'; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="new_asset_name" id="new_asset_name" class="form-control" style="margin-top: 0.4rem;" placeholder="Nebo sem napište jméno pro nový asset...">
                            <small style="font-size: 0.75rem; color: var(--text-muted); display: block; margin-top: 0.25rem;">Monitory se stejným assetem se na dashboardu zobrazí seskupené (např. web + TeamSpeak na jednom fyzickém serveru). Vyplnění pole "nový asset" má přednost před výběrem výše.</small>
                        </div>

                        <div class="form-group">
                            <label for="timeout">Timeout kontroly (sekund)</label>
                            <input type="number" name="timeout" id="timeout" value="<?php echo $edit_monitor ? htmlspecialchars($edit_monitor['timeout'] ?? '5') : '5'; ?>" class="form-control" min="1" max="60">
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Poznámky (neveřejné - slouží pro interní info, ceny, hosting atd.)</label>
                            <textarea name="notes" id="notes" class="form-control" style="height: 80px; resize: vertical;" placeholder="Sem můžete zapsat například cenu hostingu, datum expirace nebo kde je server umístěn..."><?php echo $edit_monitor ? htmlspecialchars($edit_monitor['notes'] ?? '') : ''; ?></textarea>
                        </div>
                        
                        <h3 style="font-size: 0.85rem; color: var(--text-secondary); margin: 1rem 0 0.5rem 0; text-transform: uppercase;">Aktivní notifikace</h3>
                        
                        <div class="form-group" style="display: flex; gap: 1.5rem; align-items: center; margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                                <input type="checkbox" name="email_notifications" <?php echo (!$edit_monitor || $edit_monitor['email_notifications']) ? 'checked' : ''; ?>> E-mail
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem;">
                                <input type="checkbox" name="sms_notifications" <?php echo ($edit_monitor && $edit_monitor['sms_notifications']) ? 'checked' : ''; ?>> SMS / WhatsApp
                            </label>
                        </div>

                        <?php if ($edit_monitor): ?>
                        <!-- Plánovaná údržba (Maintenance Window) -->
                        <h3 style="font-size: 0.85rem; color: var(--text-secondary); margin: 1.5rem 0 0.5rem 0; text-transform: uppercase;">Režim plánované údržby (Maintenance)</h3>
                        <div class="form-group" style="background: rgba(255,255,255,0.02); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border-color); margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.9rem; font-weight: bold; color: var(--color-yellow);">
                                <input type="checkbox" name="maintenance" <?php echo ($edit_monitor && $edit_monitor['maintenance']) ? 'checked' : ''; ?>>
                                <span><i class="fas fa-tools"></i> Manuální údržba (Okamžitě zapnout)</span>
                            </label>
                            
                            <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 1rem;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="maintenance_start">Plánovaný začátek</label>
                                    <input type="datetime-local" name="maintenance_start" id="maintenance_start" value="<?php echo ($edit_monitor && $edit_monitor['maintenance_start']) ? date('Y-m-d\TH:i', strtotime($edit_monitor['maintenance_start'])) : ''; ?>" class="form-control" style="font-size: 0.85rem;">
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="maintenance_end">Plánovaný konec</label>
                                    <input type="datetime-local" name="maintenance_end" id="maintenance_end" value="<?php echo ($edit_monitor && $edit_monitor['maintenance_end']) ? date('Y-m-d\TH:i', strtotime($edit_monitor['maintenance_end'])) : ''; ?>" class="form-control" style="font-size: 0.85rem;">
                                </div>
                            </div>
                            
                            <div class="form-group" style="margin-top: 1rem; margin-bottom: 0;">
                                <label for="maintenance_description">Popis údržby (zobrazí se uživatelům)</label>
                                <textarea name="maintenance_description" id="maintenance_description" class="form-control" style="height: 60px; resize: vertical; font-size: 0.85rem;" placeholder="Např. Stěhování serveru do jiné serverovny, údržba databáze..."><?php echo htmlspecialchars($edit_monitor['maintenance_description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 0.75rem; margin-top: 1.5rem;">
                            <button type="submit" name="save_monitor" class="btn"><i class="fas fa-save"></i> <?php echo $edit_monitor ? 'Uložit změny' : 'Vytvořit'; ?></button>
                            <?php if ($edit_monitor): ?>
                                <a href="admin.php" class="btn btn-secondary"><i class="fas fa-times"></i> Zrušit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                </div>

                <!-- Profil administrátora (E-mail, SMS telefon a heslo) -->
                <div class="admin-card">
                    <div class="admin-header">
                        <h2><i class="fas fa-user-shield"></i> Profil administrátora</h2>
                    </div>
                    
                    <form action="admin.php" method="POST">
                        <?php echo bk_csrf_field(); ?>
                        <div class="form-group">
                            <label for="email">Kontaktní E-mail (pro notifikace)</label>
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Telefonní číslo (pro WhatsApp / SMS)</label>
                            <input type="text" name="phone" id="phone" value="<?php echo htmlspecialchars($me['phone'] ?: ''); ?>" class="form-control" placeholder="+420777123456">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Zadejte v mezinárodním formátu vč. předvolby.</small>
                        </div>

                        <div class="form-group">
                            <label for="whatsapp_apikey">CallMeBot API klíč pro WhatsApp</label>
                            <input type="password" name="whatsapp_apikey" id="whatsapp_apikey" value="<?php echo htmlspecialchars($me['whatsapp_apikey'] ?? ''); ?>" class="form-control" placeholder="Váš osobní CallMeBot API klíč" autocomplete="off">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Chcete-li získat klíč zdarma: Postupujte dle návodu na oficiálním <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener" style="color: var(--color-green);">webu CallMeBot</a>. Zprávu pro povolení zašlete na tam uvedené aktuální telefonní číslo. Bot vám obratem zašle váš unikátní API klíč.
                            </small>
                        </div>

                        <div class="form-group" style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem;">
                                <input type="checkbox" name="whatsapp_notifications" id="whatsapp_notifications" value="1" <?php echo ($me['whatsapp_notifications'] ?? 0) ? 'checked' : ''; ?> style="width: auto; margin: 0;">
                                <span>Zapnout WhatsApp notifikace pro tento účet</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-size: 0.85rem;">
                                <input type="checkbox" name="sms_notifications" id="sms_notifications" value="1" <?php echo ($me['sms_notifications'] ?? 0) ? 'checked' : ''; ?> style="width: auto; margin: 0;">
                                <span>Zapnout SMS notifikace pro tento účet</span>
                            </label>
                        </div>
                        
                        <h3 style="font-size: 0.85rem; color: var(--color-red); margin: 1.5rem 0 0.5rem 0; text-transform: uppercase;">Změna hesla</h3>
                        <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.75rem;">Nechte prázdné, pokud heslo nechcete měnit.</p>
                        
                        <div class="form-group">
                            <label for="old_password">Stávající heslo</label>
                            <input type="password" name="old_password" id="old_password" class="form-control" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="new_password">Nové heslo</label>
                            <input type="password" name="new_password" id="new_password" class="form-control" autocomplete="new-password">
                        </div>
                        
                        <button type="submit" name="change_password" class="btn"><i class="fas fa-user-edit"></i> Aktualizovat profil</button>
                        <a href="#" onclick="bkPostAction({test_email: '1'}); return false;" class="btn btn-secondary" style="margin-left: 0.5rem;"><i class="fas fa-paper-plane"></i> Odeslat testovací e-mail</a>
                        <a href="#" onclick="bkPostAction({send_weekly_digest: '1'}); return false;" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Odeslat týdenní digest všem adminům"><i class="fas fa-chart-bar"></i> Odeslat týdenní digest</a>
                        <a href="#" onclick="bkPostAction({send_monthly_digest: '1'}); return false;" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Odeslat měsíční digest všem adminům"><i class="fas fa-chart-pie"></i> Odeslat měsíční digest</a>
                        <a href="admin.php?action=preview_weekly_digest" target="_blank" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Zobrazit náhled týdenního reportu v prohlížeči (bez odeslání)"><i class="fas fa-eye"></i> Náhled týdenního reportu</a>
                        <a href="admin.php?action=preview_monthly_digest" target="_blank" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Zobrazit náhled měsíčního reportu v prohlížeči (bez odeslání)"><i class="fas fa-eye"></i> Náhled měsíčního reportu</a>
                    </form>
                </div>

                <?php echo bk_render_totp_section($me, $site_title); ?>

        <!-- SEKCE: Správa uživatelů (pouze pro Admina) -->
        <div class="admin-grid" style="margin-top: 2rem;">
            <!-- LEVÝ SLOUPEC: Seznam uživatelů -->
            <div>
                <div class="admin-card">
                    <div class="admin-header">
                        <h2><i class="fas fa-users"></i> Správa uživatelů a odběratelů</h2>
                    </div>
                    <div style="overflow-x: auto;">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Jméno</th>
                                    <th>E-mail</th>
                                    <th>Telefon (WhatsApp)</th>
                                    <th>Role</th>
                                    <th>Akce</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_users as $u): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($u['username']); ?></strong></td>
                                        <td data-label="E-mail"><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
                                        <td data-label="Telefon"><?php echo htmlspecialchars($u['phone'] ?: '-'); ?></td>
                                        <td data-label="Role">
                                            <span class="category-badge" style="background: <?php echo $u['role'] === 'admin' ? 'var(--color-red)' : 'var(--color-green)'; ?>;">
                                                <?php echo $u['role'] === 'admin' ? 'Admin' : 'Uživatel'; ?>
                                            </span>
                                        </td>
                                        <td data-label="Akce">
                                            <div class="action-btns">
                                                <a href="admin.php?action=edit_user&id=<?php echo $u['id']; ?>" class="btn btn-secondary btn-sm" title="Upravit"><i class="fas fa-edit"></i></a>
                                                <?php if ($u['id'] !== $user_id): ?>
                                                    <a href="#" onclick="if (confirm('Opravdu chcete smazat tohoto uživatele?')) bkPostAction({delete_user: '1', id: <?php echo (int)$u['id']; ?>}); return false;" class="btn btn-danger btn-sm" title="Smazat"><i class="fas fa-trash"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- PRAVÝ SLOUPEC: Formulář přidání / editace uživatele -->
            <div>
                <div class="admin-card" style="border-top: 4px solid var(--color-green);">
                    <div class="admin-header">
                        <h2>
                            <?php if ($edit_user): ?>
                                <i class="fas fa-user-edit"></i> Upravit uživatele / odběratele
                            <?php else: ?>
                                <i class="fas fa-user-plus"></i> Vytvořit nového uživatele
                            <?php endif; ?>
                        </h2>
                    </div>
                    <form action="admin.php" method="POST">
                        <?php echo bk_csrf_field(); ?>
                        <?php if ($edit_user): ?>
                            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label for="u_username">Uživatelské jméno</label>
                            <input type="text" name="username" id="u_username" value="<?php echo $edit_user ? htmlspecialchars($edit_user['username']) : ''; ?>" class="form-control" required placeholder="Např. petr">
                        </div>
                        
                        <div class="form-group">
                            <label for="u_email">E-mail</label>
                            <input type="email" name="email" id="u_email" value="<?php echo $edit_user ? htmlspecialchars($edit_user['email']) : ''; ?>" class="form-control" required placeholder="petr@bloodkings.eu">
                        </div>
                        
                        <div class="form-group">
                            <label for="u_phone">Telefon (vč. předvolby)</label>
                            <input type="text" name="phone" id="u_phone" value="<?php echo $edit_user ? htmlspecialchars($edit_user['phone'] ?? '') : ''; ?>" class="form-control" placeholder="+420777123456">
                        </div>
                        
                        <div class="form-group">
                            <label for="u_role">Role</label>
                            <select name="role" id="u_role" class="form-control">
                                <option value="user" <?php echo ($edit_user && $edit_user['role'] === 'user') ? 'selected' : ''; ?>>Uživatel (Odběratel)</option>
                                <option value="admin" <?php echo ($edit_user && $edit_user['role'] === 'admin') ? 'selected' : ''; ?>>Administrátor</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="u_password">Heslo <?php echo $edit_user ? '(nechte prázdné pro beze změny)' : '(nepovinné)'; ?></label>
                            <input type="password" name="password" id="u_password" class="form-control" autocomplete="new-password">
                            <small style="font-size: 0.75rem; color: var(--text-muted);"><?php echo $edit_user ? 'Nastaví nové heslo přímo.' : 'Necháte-li prázdné, uživateli přijde e-mail s odkazem, kterým si heslo nastaví sám - vy ho tak nikdy neznáte.'; ?></small>
                        </div>
                        
                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                            <button type="submit" name="save_user" class="btn"><i class="fas fa-save"></i> <?php echo $edit_user ? 'Uložit' : 'Vytvořit'; ?></button>
                            <?php if ($edit_user): ?>
                                <a href="admin.php" class="btn btn-secondary"><i class="fas fa-times"></i> Zrušit</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <?php else: // BĚŽNÝ UŽIVATEL (role 'user') ?>
        
        <div class="admin-grid">
            
            <!-- LEVÝ SLOUPEC: Moje odběry notifikací -->
            <div>
                <div class="admin-card">
                    <div class="admin-header">
                        <h2><i class="fas fa-bell"></i> Moje odběry upozornění</h2>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem; padding: 0 0.5rem;">
                        Zde si můžete nastavit, na které servery a služby chcete dostávat upozornění při výpadku či nápravě.
                    </p>
                    
                    <form action="admin.php" method="POST">
                        <?php echo bk_csrf_field(); ?>
                        <div style="overflow-x: auto;">
                            <table class="admin-table">
                                <thead>
                                    <tr>
                                        <th>Název serveru</th>
                                        <th>Kategorie</th>
                                        <th>Typ</th>
                                        <th style="text-align: center;">Odběr E-mailem</th>
                                        <th style="text-align: center;">Odběr SMS</th>
                                        <th style="text-align: center;">Odběr WhatsApp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_monitors)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">Zatím nejsou k dispozici žádné servery k monitorování.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_monitors as $mon): 
                                            $mid = $mon['id'];
                                            $has_email = isset($my_subscriptions[$mid]['email']) && $my_subscriptions[$mid]['email'] === 1;
                                            $has_sms = isset($my_subscriptions[$mid]['sms']) && $my_subscriptions[$mid]['sms'] === 1;
                                            $has_wa = isset($my_subscriptions[$mid]['whatsapp']) && $my_subscriptions[$mid]['whatsapp'] === 1;
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($mon['name']); ?></strong></td>
                                                <td data-label="Kategorie"><span class="category-badge"><?php echo htmlspecialchars($mon['category'] ?: 'Ostatní'); ?></span></td>
                                                <td data-label="Typ"><span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.35rem;"><?php echo monitor_type_icon($mon['type'], $mon['target'], '0.85rem'); ?> <?php echo htmlspecialchars($mon['type']); ?></span></td>
                                                <td data-label="Odběr E-mailem" style="text-align: center;">
                                                    <input type="checkbox" name="subs[<?php echo $mid; ?>][email]" <?php echo $has_email ? 'checked' : ''; ?> style="width: 16px; height: 16px; cursor: pointer;">
                                                </td>
                                                <td data-label="Odběr SMS" style="text-align: center;">
                                                    <input type="checkbox" name="subs[<?php echo $mid; ?>][sms]" <?php echo $has_sms ? 'checked' : ''; ?> style="width: 16px; height: 16px; cursor: pointer;">
                                                </td>
                                                <td data-label="Odběr WhatsApp" style="text-align: center;">
                                                    <input type="checkbox" name="subs[<?php echo $mid; ?>][whatsapp]" <?php echo $has_wa ? 'checked' : ''; ?> style="width: 16px; height: 16px; cursor: pointer;">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <button type="submit" name="save_subscriptions" class="btn" style="margin-top: 1.5rem;"><i class="fas fa-save"></i> Uložit odběry upozornění</button>
                    </form>
                </div>
            </div>
            
            <!-- PRAVÝ SLOUPEC: Můj profil (Nastavení kontaktu) -->
            <div>
                <div class="admin-card" style="border-top: 4px solid var(--color-green);">
                    <div class="admin-header">
                        <h2><i class="fas fa-user-cog"></i> Můj profil odběratele</h2>
                    </div>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                        Ujistěte se, že máte zadané správné kontaktní údaje pro doručování upozornění.
                    </p>
                    
                    <form action="admin.php" method="POST">
                        <?php echo bk_csrf_field(); ?>
                        <div class="form-group">
                            <label for="email">Můj E-mail (pro notifikace)</label>
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="phone">Telefonní číslo (pro WhatsApp / SMS)</label>
                            <input type="text" name="phone" id="phone" value="<?php echo htmlspecialchars($me['phone'] ?: ''); ?>" class="form-control" placeholder="+420777123456">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">Zadejte v mezinárodním formátu vč. předvolby.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="whatsapp_apikey">CallMeBot API klíč pro WhatsApp</label>
                            <input type="password" name="whatsapp_apikey" id="whatsapp_apikey" value="<?php echo htmlspecialchars($me['whatsapp_apikey'] ?? ''); ?>" class="form-control" placeholder="Váš osobní CallMeBot API klíč" autocomplete="off">
                            <small style="font-size: 0.75rem; color: var(--text-muted);">
                                Chcete-li získat klíč zdarma: Postupujte dle návodu na oficiálním <a href="https://www.callmebot.com/blog/free-api-whatsapp-messages/" target="_blank" rel="noopener" style="color: var(--color-green);">webu CallMeBot</a>. Zprávu pro povolení zašlete na tam uvedené aktuální telefonní číslo. Bot vám obratem zašle váš unikátní API klíč.
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="sms_notifications" id="sms_notifications" value="1" <?php echo ($me['sms_notifications'] ?? 0) ? 'checked' : ''; ?> style="width: auto; margin: 0;">
                                <span>Zapnout SMS notifikace pro tento účet</span>
                            </label>
                        </div>
                        
                        <div class="form-group" style="margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="whatsapp_notifications" id="whatsapp_notifications" value="1" <?php echo ($me['whatsapp_notifications'] ?? 0) ? 'checked' : ''; ?> style="width: auto; margin: 0;">
                                <span>Zapnout WhatsApp notifikace pro tento účet</span>
                            </label>
                            <small style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; display: block;">
                                Zvolte typy zpráv (SMS, WhatsApp, případně oboje), které chcete dostávat při každé změně stavu monitorů.
                            </small>
                        </div>
                        
                        <h3 style="font-size: 0.85rem; color: var(--color-red); margin: 1.5rem 0 0.5rem 0; text-transform: uppercase;">Změna hesla</h3>
                        <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.75rem;">Nechte prázdné, pokud heslo nechcete měnit.</p>
                        
                        <div class="form-group">
                            <label for="old_password">Stávající heslo</label>
                            <input type="password" name="old_password" id="old_password" class="form-control" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="new_password">Nové heslo</label>
                            <input type="password" name="new_password" id="new_password" class="form-control" autocomplete="new-password">
                        </div>
                        
                        <button type="submit" name="change_password" class="btn"><i class="fas fa-user-edit"></i> Aktualizovat profil</button>
                    </form>
                  </div>

                  <?php echo bk_render_totp_section($me, $site_title); ?>
              </div>

          </div>

          <?php endif; ?>

      </div>

    <!-- Script pro interaktivní přepínání polí v administraci -->
    <script>
        // CSRF token pro akce spouštěné z JS (jednoduché ikonové odkazy v tabulkách,
        // kde by samostatný <form> na každou buňku byl zbytečně těžkopádný) - stejný
        // token jako u <?php echo 'bk_csrf_field()'; ?> ve formulářích, jen vložený jednou globálně.
        const BK_CSRF_TOKEN = <?php echo json_encode(bk_csrf_token()); ?>;

        // Sestaví a odešle skrytý POST formulář - náhrada za dřívější GET odkazy
        // (admin.php?action=...), aby šly stavové akce chránit CSRF tokenem a
        // aby je nešlo spustit jen tak otevřením odkazu/obrázku (viz bezpečnostní audit).
        function bkPostAction(fields) {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = 'admin.php';
            f.style.display = 'none';
            fields.csrf_token = BK_CSRF_TOKEN;
            for (const key in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                f.appendChild(input);
            }
            document.body.appendChild(f);
            f.submit();
        }

        function togglePortField(type) {
            const portGroup = document.getElementById('port-group');
            const targetLabel = document.getElementById('target-label');
            const targetDesc = document.getElementById('target-desc');
            const targetInput = document.getElementById('target');
            const processesGroup = document.getElementById('processes-group');
            const agentThresholdsGroup = document.getElementById('agent-thresholds-group');
            const portLabel = document.getElementById('port-label');
            const tsVoiceGroup = document.getElementById('teamspeak-voice-group');
            const cpanelStatsGroup = document.getElementById('cpanel-stats-group');
            const bodyKeywordGroup = document.getElementById('body-keyword-group');
            const ts3FiletransferGroup = document.getElementById('ts3-filetransfer-group');
            const sqLoginGroup = document.getElementById('sq-login-group');
            const rconGroup = document.getElementById('rcon-group');
            const remoteActionsGroup = document.getElementById('remote-actions-group');

            // Výchozí zobrazení
            portGroup.style.display = 'none';
            if (processesGroup) {
                // OpenWrt agent zatím neposílá seznam běžících procesů (viz
                // agent_openwrt.sh) - zobrazovat pole by bylo zavádějící.
                processesGroup.style.display = (type !== 'cpanel' && type !== 'discord' && type !== 'openwrt') ? 'block' : 'none';
            }
            if (agentThresholdsGroup) {
                agentThresholdsGroup.style.display = (type !== 'cpanel' && type !== 'discord') ? 'block' : 'none';
            }
            if (tsVoiceGroup) {
                tsVoiceGroup.style.display = 'none';
            }
            if (cpanelStatsGroup) {
                cpanelStatsGroup.style.display = 'none';
            }
            if (bodyKeywordGroup) {
                bodyKeywordGroup.style.display = 'none';
            }
            if (ts3FiletransferGroup) {
                ts3FiletransferGroup.style.display = 'none';
            }
            if (sqLoginGroup) {
                sqLoginGroup.style.display = 'none';
            }
            if (rconGroup) {
                rconGroup.style.display = 'none';
            }
            if (remoteActionsGroup) {
                remoteActionsGroup.style.display = 'none';
            }
            if (portLabel) {
                portLabel.textContent = "Síťový port";
            }
            targetInput.placeholder = "Např. https://bloodkings.eu nebo 123.45.67.89";
            // Cíl je povinný všude kromě čistě agentových typů (vps/openwrt) -
            // tam se buď nepoužívá vůbec (openwrt), nebo je to jen popisek bez
            // síťového významu (vps), takže vyplnění nemá smysl vynucovat.
            targetInput.required = true;

            if (type === 'web') {
                if (cpanelStatsGroup) {
                    cpanelStatsGroup.style.display = 'block';
                }
                if (bodyKeywordGroup) {
                    bodyKeywordGroup.style.display = 'block';
                }
            } else if (type === 'port') {
                portGroup.style.display = 'block';
                targetLabel.textContent = "Cíl (IP adresa nebo Hostname)";
                targetDesc.textContent = "Zadejte IP adresu nebo doménu serveru, na kterém port běží.";
            } else if (type === 'minecraft') {
                portGroup.style.display = 'block';
                if (rconGroup) {
                    rconGroup.style.display = 'block';
                }
                targetLabel.textContent = "Adresa Minecraft serveru (IP/Hostname)";
                targetDesc.textContent = "Zadejte adresu herního serveru. Port doplňte níže (výchozí: 25565).";
                document.getElementById('port').placeholder = "25565";
                if (portLabel) {
                    portLabel.textContent = "Port serveru (Query)";
                }
            } else if (type === 'teamspeak') {
                portGroup.style.display = 'block';
                if (tsVoiceGroup) {
                    tsVoiceGroup.style.display = 'block';
                }
                if (ts3FiletransferGroup) {
                    ts3FiletransferGroup.style.display = 'block';
                }
                if (sqLoginGroup) {
                    sqLoginGroup.style.display = 'block';
                }
                targetLabel.textContent = "IP adresa / Hostname serveru (bez portu)";
                targetDesc.textContent = "Zadejte pouze IP adresu nebo doménu serveru, např. donald.bloodkings.eu. Hlasový a query port doplňte samostatně níže.";
                if (portLabel) {
                    portLabel.textContent = "ServerQuery Port (TS Query port)";
                }
                document.getElementById('port').placeholder = "10011";
            } else if (type === 'discord') {
                targetLabel.textContent = "Discord Guild ID (ID serveru)";
                targetInput.placeholder = "Např. 936354859604859604";
                targetDesc.textContent = "Zadejte číselné ID vašeho Discord serveru. Ujistěte se, že máte v nastavení povolený widget.";
            } else if (type === 'vps') {
                targetLabel.textContent = "Název VPS / Identifikátor";
                targetInput.placeholder = "Např. vps-server-germany";
                targetDesc.textContent = "Volitelné - libovolný textový identifikátor VPS serveru (není síťová adresa). Pokud necháte prázdné, doplní se automaticky podle hostname z první zprávy agenta.";
                targetInput.required = false;
            } else if (type === 'openwrt') {
                targetLabel.textContent = "Interní poznámka (volitelné)";
                targetInput.placeholder = "Nepovinné - necháte-li prázdné, doplní se samo";
                targetDesc.textContent = "Toto pole nemá na OpenWrt monitor žádný vliv - router se identifikuje sám přes agenta. Klidně nechte prázdné, po prvním hlášení agenta se doplní hostname nebo WAN IP adresa routeru.";
                targetInput.required = false;
                if (remoteActionsGroup) {
                    remoteActionsGroup.style.display = 'block';
                }
            } else if (type === 'cpanel') {
                targetLabel.textContent = "cPanel Stats URL (cpanel_stats.php s klíčem)";
                targetInput.placeholder = "Např. https://bloodkings.eu/cpanel_stats.php?key=TajnyKlic";
                targetDesc.textContent = "Zadejte plnou URL adresu souboru cpanel_stats.php včetně bezpečnostního klíče.";
                targetInput.type = "password";
                const targetToggle = document.getElementById('target-toggle-btn');
                if (targetToggle) {
                    targetToggle.style.display = 'block';
                    const icon = targetToggle.querySelector('i');
                    if (icon) icon.className = 'fas fa-eye';
                }
            } else {
                targetLabel.textContent = "Cíl (URL, Hostname, IP)";
                targetDesc.textContent = "Zadejte úplnou URL adresu (vč. http/https) pro weby.";
                targetInput.type = "text";
                const targetToggle = document.getElementById('target-toggle-btn');
                if (targetToggle) {
                    targetToggle.style.display = 'none';
                }
            }
        }

        function triggerRemoteAction(monitorId, actionType, actionLabel) {
            if (!confirm('Opravdu chcete provést akci "' + actionLabel + '" na tomto routeru?')) return;
            bkPostAction({ trigger_remote_action: '1', monitor_id: monitorId, action_type: actionType });
        }

        function toggleSMSFields(gateway) {
            const twilioFields = document.getElementById('twilio-fields');
            const smsbranaFields = document.getElementById('smsbrana-fields');

            twilioFields.style.display = (gateway === 'twilio') ? 'block' : 'none';
            smsbranaFields.style.display = (gateway === 'smsbrana') ? 'block' : 'none';
        }
        
        function openMonitorModal() {
            const modal = document.getElementById('monitor-form-modal');
            if (modal) modal.style.display = 'flex';
        }

        function closeMonitorModal() {
            const modal = document.getElementById('monitor-form-modal');
            if (modal) modal.style.display = 'none';
            // Pokud jsme na stránku přišli přes editační odkaz (?action=edit&id=ID), vyčistíme URL,
            // aby obnovení stránky znovu neotevřelo modal ve stavu editace.
            if (window.location.search.includes('action=edit')) {
                const url = new URL(window.location.href);
                url.searchParams.delete('action');
                url.searchParams.delete('id');
                window.history.replaceState({}, '', url);
            }
        }

        function showAgentInstructions(key, serverName, monitorType) {
            document.getElementById('cpanel-instructions-card').style.display = 'none';
            document.getElementById('agent-instructions-card').style.display = 'block';
            document.getElementById('agent-server-name').textContent = serverName;

            ['agent-server-key', 'agent-server-key-sh', 'agent-server-key-ps1', 'agent-server-key-docker', 'agent-server-key-openwrt'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) el.textContent = key;
            });

            // Výchozí záložka je Python, kromě OpenWrt monitorů - tam rovnou OpenWrt.
            const tabsBar = document.getElementById('agent-tabs');
            if (tabsBar) {
                const targetTab = monitorType === 'openwrt' ? 'agent-tab-openwrt' : null;
                const targetBtn = targetTab ? tabsBar.querySelector('button[data-agent-tab="' + targetTab + '"]') : null;
                const firstBtn = targetBtn || tabsBar.querySelector('button[data-agent-tab]');
                if (firstBtn) firstBtn.click();
            }

            // Odrolovat na instrukce
            document.getElementById('agent-instructions-card').scrollIntoView({ behavior: 'smooth' });
        }

        // Deep-link z veřejné status stránky (?show_agent=ID monitoru) - otevře rovnou
        // instrukce pro daný monitor, místo obecného přistání na administraci.
        (function() {
            const params = new URLSearchParams(window.location.search);
            const showAgentId = params.get('show_agent');
            if (showAgentId) {
                const btn = document.getElementById('agent-btn-' + showAgentId);
                if (btn) btn.click();
            }
        })();

        // Service Profile picker - karta jen řídí skrytý <select name="type">, aby
        // veškerá stávající logika (togglePortField, ukládání) zůstala beze změny.
        function updateMetricsChecklist(type) {
            document.querySelectorAll('.metrics-checklist-group').forEach((group) => {
                const isActive = group.id === 'metrics-checklist-' + type;
                group.style.display = isActive ? '' : 'none';
                group.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                    input.disabled = !isActive;
                });
            });
        }

        function selectProfileType(type) {
            const select = document.getElementById('type');
            if (select && select.value !== type) select.value = type;
            document.querySelectorAll('.profile-picker-card').forEach((card) => {
                card.classList.toggle('active', card.getAttribute('data-type') === type);
            });
            togglePortField(type);
            updateMetricsChecklist(type);
        }

        function showCpanelInstructions(serverName) {
            document.getElementById('agent-instructions-card').style.display = 'none';
            document.getElementById('cpanel-instructions-card').style.display = 'block';
            document.getElementById('cpanel-server-name').textContent = serverName;
            // Odrolovat na instrukce
            document.getElementById('cpanel-instructions-card').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Inicializace při načtení pro správný stav formuláře
        window.addEventListener('DOMContentLoaded', () => {
            const typeEl = document.getElementById('type');
            if (typeEl) {
                togglePortField(typeEl.value);
                updateMetricsChecklist(typeEl.value);
            }

            const smsEl = document.getElementById('sms_gateway_type');
            if (smsEl) toggleSMSFields(smsEl.value);
        });
        
        // Theme toggle – skript je na konci <body>, DOM je plně dostupný, nepotřebujeme DOMContentLoaded
        (function() {
            const themeToggle = document.getElementById('theme-toggle');
            if (!themeToggle) return;
            
            const updateIcon = () => {
                const isLight = document.documentElement.classList.contains('light-theme');
                themeToggle.innerHTML = isLight ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
            };
            updateIcon();
            
            themeToggle.addEventListener('click', () => {
                const isLight = document.documentElement.classList.toggle('light-theme');
                localStorage.setItem('theme', isLight ? 'light' : 'dark');
                updateIcon();
            });
        })();
    </script>

    <!-- Footer -->
    <footer style="margin-top: 4rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_title); ?>. Všechna práva vyhrazena.</p>
            <?php $ver = get_app_version(); ?>
            <p style="font-size: 0.75rem; opacity: 0.55; margin-top: 0.25rem;">
                <i class="fas fa-code-branch"></i> <?php echo htmlspecialchars($ver['label']); ?>
            </p>
            <div class="social-links" style="margin-top: 0.75rem; display: flex; justify-content: center; gap: 1.25rem; font-size: 1.2rem;">
                <a href="https://www.facebook.com/bloodkings" target="_blank" style="color: var(--text-muted); transition: color 0.15s ease;" onmouseover="this.style.color='#1877f2'" onmouseout="this.style.color='var(--text-muted)'" title="Facebook Page"><i class="fab fa-facebook"></i></a>
                <a href="https://discord.gg/bloodkings" target="_blank" style="color: var(--text-muted); transition: color 0.15s ease;" onmouseover="this.style.color='#5865f2'" onmouseout="this.style.color='var(--text-muted)'" title="Discord Server"><i class="fab fa-discord"></i></a>
            </div>
        </div>
    </footer>

</body>
</html>
