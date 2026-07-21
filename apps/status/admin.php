<?php
/**
 * Administrace monitorovacího systému (Blood Kings)
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';

// Zpracování odhlášení
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['admin_logged_in']);
    session_destroy();
    header('Location: admin.php');
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
            
            $emails = json_decode($resp_emails, true);
            $primary_email = '';
            if (is_array($emails)) {
                foreach ($emails as $email_entry) {
                    if ($email_entry['primary'] && $email_entry['verified']) {
                        $primary_email = $email_entry['email'];
                        break;
                    }
                }
            }
            
            if (empty($primary_email)) {
                $login_error = 'Na vašem GitHub účtu nebyl nalezen ověřený primární e-mail.';
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$primary_email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    $stmt_up_oauth = $pdo->prepare("UPDATE users SET oauth_provider = 'github' WHERE id = ?");
                    $stmt_up_oauth->execute([$user['id']]);
                    
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_username'] = $user['username'];
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_role'] = $user['role'];
                    header('Location: admin.php');
                    exit;
                } else {
                    $login_error = 'Uživatel s e-mailem ' . htmlspecialchars($primary_email) . ' není v systému registrován jako administrátor.';
                }
            }
        }
    }
}

// Zpracování přihlášení (chybu z OAuth callbacku výše nesmíme přepsat)
$login_error = $login_error ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    $password_correct = false;
    if ($user) {
        if (password_verify($password, $user['password_hash'])) {
            $password_correct = true;
        } elseif ($user['username'] === 'admin' && $user['password_hash'] === '$2y$10$wK10b5JgOq3qg7g3qg7qg.2V2WpXy9B1e5D6F7G8H9I0J1K2L3M4N' && $password === 'BloodKingsAdmin123!') {
            $password_correct = true;
            // Přehashovat heslo správným bcryptem
            $new_hash = password_hash('BloodKingsAdmin123!', PASSWORD_BCRYPT);
            $stmt_hash = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt_hash->execute([$new_hash, $user['id']]);
        }
    }
    
    if ($password_correct) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_role'] = $user['role'];
        header('Location: admin.php');
        exit;
    } else {
        $login_error = 'Neplatné přihlašovací údaje.';
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
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.0/css/all.min.css">
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
            <form action="admin.php" method="POST">
                <div class="form-group">
                    <label for="username">Uživatelské jméno</label>
                    <input type="text" name="username" id="username" class="form-control" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Heslo</label>
                    <input type="password" name="password" id="password" class="form-control" required autocomplete="current-password">
                </div>
                <button type="submit" name="login" class="btn" style="width: 100%; margin-top: 1rem;"><i class="fas fa-sign-in-alt"></i> Přihlásit se</button>
                <?php
                $gh_client_id = get_setting('oauth_github_client_id');
                if (!empty($gh_client_id)):
                ?>
                    <a href="admin.php?login_oauth=github" class="btn" style="width: 100%; margin-top: 0.5rem; background-color: #24292e; color: #fff; display: flex; align-items: center; justify-content: center; gap: 0.5rem; border: none;">
                        <i class="fab fa-github"></i> Přihlásit se přes GitHub
                    </a>
                <?php endif; ?>
            </form>
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

$success_msg = '';
$error_msg = '';

// 1. Zpracování přidání / úpravy monitoru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_monitor']) && $user_role === 'admin') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = trim($_POST['name']);
    $type = $_POST['type'];
    $target = trim($_POST['target']);
    $port = !empty($_POST['port']) ? intval($_POST['port']) : null;
    $category = trim($_POST['category']);
    $timeout = !empty($_POST['timeout']) ? intval($_POST['timeout']) : 5;
    $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
    $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;
    $maintenance = isset($_POST['maintenance']) ? 1 : 0;
    $monitored_processes = isset($_POST['monitored_processes']) ? trim($_POST['monitored_processes']) : null;
    $maintenance_description = !empty($_POST['maintenance_description']) ? trim($_POST['maintenance_description']) : null;
    $maintenance_start = !empty($_POST['maintenance_start']) ? $_POST['maintenance_start'] : null;
    $maintenance_end = !empty($_POST['maintenance_end']) ? $_POST['maintenance_end'] : null;
    $cpu_threshold = !empty($_POST['cpu_threshold']) ? intval($_POST['cpu_threshold']) : 90;
    $ram_threshold = !empty($_POST['ram_threshold']) ? intval($_POST['ram_threshold']) : 95;
    $hdd_threshold = !empty($_POST['hdd_threshold']) ? intval($_POST['hdd_threshold']) : 90;
    $body_keyword = !empty($_POST['body_keyword']) && $type === 'web' ? trim($_POST['body_keyword']) : null;

    $cpanel_stats_url = !empty($_POST['cpanel_stats_url']) && $type === 'web' ? trim($_POST['cpanel_stats_url']) : null;

    // Volitelné ServerQuery přihlášení (hlubší TeamSpeak data - server groups, plný clientlist)
    $sq_username = !empty($_POST['sq_username']) && $type === 'teamspeak' ? trim($_POST['sq_username']) : null;
    $sq_password = !empty($_POST['sq_password']) && $type === 'teamspeak' ? trim($_POST['sq_password']) : null;
    $ts3_filetransfer_port = !empty($_POST['ts3_filetransfer_port']) && $type === 'teamspeak' ? intval($_POST['ts3_filetransfer_port']) : null;

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
            $stmt_check = $pdo->prepare("SELECT agent_key FROM monitors WHERE id = ?");
            $stmt_check->execute([$id]);
            $existing_key = $stmt_check->fetchColumn();
            if (empty($existing_key)) {
                $stmt_up_key = $pdo->prepare("UPDATE monitors SET agent_key = ? WHERE id = ?");
                $stmt_up_key->execute([bin2hex(random_bytes(16)), $id]);
            }

            $stmt = $pdo->prepare("
                UPDATE monitors
                SET name = ?, type = ?, target = ?, port = ?, category = ?, timeout = ?, email_notifications = ?, sms_notifications = ?, notes = ?, maintenance = ?, monitored_processes = ?, maintenance_description = ?, maintenance_start = ?, maintenance_end = ?, cpanel_stats_url = ?, cpu_threshold = ?, ram_threshold = ?, hdd_threshold = ?, body_keyword = ?, sq_username = ?, sq_password = ?, ts3_filetransfer_port = ?, enabled_metrics = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics, $id]);
            $success_msg = 'Monitor byl úspěšně upraven.';
        } else {
            // Vytvoření nového monitoru - vygenerujeme agent_key pro všechny typy
            $agent_key = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare("
                INSERT INTO monitors (name, type, target, port, category, timeout, email_notifications, sms_notifications, agent_key, status, notes, maintenance, monitored_processes, maintenance_description, maintenance_start, maintenance_end, cpanel_stats_url, cpu_threshold, ram_threshold, hdd_threshold, body_keyword, sq_username, sq_password, ts3_filetransfer_port, enabled_metrics)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $type, $target, $port, $category, $timeout, $email_notifications, $sms_notifications, $agent_key, $notes, $maintenance, $monitored_processes, $maintenance_description, $maintenance_start, $maintenance_end, $cpanel_stats_url, $cpu_threshold, $ram_threshold, $hdd_threshold, $body_keyword, $sq_username, $sq_password, $ts3_filetransfer_port, $enabled_metrics]);
            log_monitor_event($pdo, (int)$pdo->lastInsertId(), $name, $type, 'monitor_added', "Přidán nový monitor ({$type})");
            $success_msg = 'Monitor byl úspěšně přidán.';
        }
    }
}

// 2. Zpracování smazání monitoru
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id']) && $user_role === 'admin') {
    $del_id = intval($_GET['id']);
    $stmt_del_info = $pdo->prepare("SELECT name, type FROM monitors WHERE id = ?");
    $stmt_del_info->execute([$del_id]);
    $del_info = $stmt_del_info->fetch();
    if ($del_info) {
        log_monitor_event($pdo, null, $del_info['name'], $del_info['type'], 'monitor_removed', "Monitor odebrán ({$del_info['type']})");
    }
    $stmt = $pdo->prepare("DELETE FROM monitors WHERE id = ?");
    $stmt->execute([$del_id]);
    $success_msg = 'Monitor byl úspěšně smazán.';
}

// 3. Zpracování uložení konfigurace
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && $user_role === 'admin') {
    $settings_to_save = [
        'site_title', 'cron_key', 'cron_location', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'sms_gateway_type', 'twilio_sid', 'twilio_token', 'twilio_from', 'smsbrana_user', 'smsbrana_password',
        'agent_offline_timeout', 'agent_notifications_enabled', 'agent_notify_admin_only',
        'discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id', 'slack_webhook_url',
        'oauth_github_client_id', 'oauth_github_client_secret',
        'custom_logo_url', 'custom_color_theme', 'custom_nav_links', 'portal_url',
        'metrics_token', 'sla_goal_pct', 'ts3_latest_version'
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
                $success_msg = 'Profil a heslo byly úspěšně aktualizovány.';
            } else {
                $error_msg = 'Stávající heslo je nesprávné. Změna profilu neproběhla.';
            }
        } else {
            // Pouze aktualizace profilu bez hesla
            $stmt_up = $pdo->prepare("UPDATE users SET email = ?, phone = ?, whatsapp_apikey = ?, sms_notifications = ?, whatsapp_notifications = ? WHERE id = ?");
            $stmt_up->execute([$email, $phone, $wa_apikey, $sms_notif, $whatsapp_notif, $me['id']]);
            $success_msg = 'Profil byl úspěšně aktualizován.';
        }
        // Znovu načíst $me pro zobrazení formuláře s aktualizovanými hodnotami
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['admin_id']]);
        $me = $stmt->fetch();
    }
}

// 5. Zpracování uložení odběrů notifikací běžného uživatele
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subscriptions']) && $user_role === 'user') {
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
        $success_msg = 'Vaše předvolby notifikací byly úspěšně uloženy.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = 'Chyba při ukládání notifikací: ' . $e->getMessage();
    }
}

// 6. Zpracování správy uživatelů (pouze pro Admina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user']) && $user_role === 'admin') {
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
                if (!empty($u_password)) {
                    $new_pass_hash = password_hash($u_password, PASSWORD_BCRYPT);
                    $stmt_up = $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ?, password_hash = ? WHERE id = ?");
                    $stmt_up->execute([$u_username, $u_email, $u_phone, $u_role, $new_pass_hash, $u_id]);
                } else {
                    $stmt_up = $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                    $stmt_up->execute([$u_username, $u_email, $u_phone, $u_role, $u_id]);
                }
                $success_msg = 'Uživatel byl úspěšně upraven.';
            } else {
                if (empty($u_password)) {
                    $error_msg = 'Pro nového uživatele je nutné zadat heslo.';
                } else {
                    $new_pass_hash = password_hash($u_password, PASSWORD_BCRYPT);
                    $stmt_ins = $pdo->prepare("INSERT INTO users (username, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$u_username, $u_email, $u_phone, $u_role, $new_pass_hash]);
                    $success_msg = 'Nový uživatel byl úspěšně vytvořen.';
                }
            }
        } catch (Exception $e) {
            $error_msg = 'Chyba při ukládání uživatele: ' . $e->getMessage();
        }
    }
}

// Smazání uživatele (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'delete_user' && isset($_GET['id']) && $user_role === 'admin') {
    $del_u_id = intval($_GET['id']);
    if ($del_u_id === $user_id) {
        $error_msg = 'Nemůžete smazat svůj vlastní přihlášený účet.';
    } else {
        $stmt_del = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt_del->execute([$del_u_id]);
        $success_msg = 'Uživatel byl úspěšně smazán.';
    }
}

// Rychlé přepnutí notifikace z tabulky (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'toggle_notif' && isset($_GET['field']) && isset($_GET['id']) && $user_role === 'admin') {
    $t_id = intval($_GET['id']);
    $field = $_GET['field'] === 'email' ? 'email_notifications' : 'sms_notifications';
    
    $stmt_tog = $pdo->prepare("UPDATE monitors SET $field = 1 - $field WHERE id = ?");
    $stmt_tog->execute([$t_id]);
    
    header('Location: admin.php');
    exit;
}

// Smazání historie monitoru (logy a VPS metriky) (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'clear_history' && isset($_GET['id']) && $user_role === 'admin') {
    $clear_id = intval($_GET['id']);
    
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
            $success_msg = 'Aktualizace incidentu byla přidána.';
        }
    } elseif ($inc_action === 'delete') {
        $inc_id = (int)($_POST['inc_id'] ?? 0);
        if ($inc_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM incidents WHERE id = ?");
            $stmt->execute([$inc_id]);
            $success_msg = 'Incident byl smazán.';
        }
    }
}

// Zpracování odeslání testovacího e-mailu (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'test_email' && $user_role === 'admin') {
    $to = $me['email'] ?? '';
    if (empty($to)) {
        $error_msg = 'Chyba: Administrátor nemá nastavenou e-mailovou adresu.';
    } else {
        $subject = 'Zkušební e-mail - Blood Kings';
        $body = '<h1>Test SMTP / Mail odchozí pošty</h1>
                 <p>Tento e-mail byl odeslán jako test funkčnosti ze status panelu <strong>Blood Kings</strong>.</p>
                 <p>Pokud jste obdrželi tento e-mail, odchozí pošta z vašeho webhostingu funguje správně.</p>
                 <hr>
                 <p>Odesláno v: ' . date('d.m.Y H:i:s') . '</p>';
        
        if (send_email($to, $subject, $body)) {
            $success_msg = 'Testovací e-mail byl úspěšně odeslán na adresu ' . htmlspecialchars($to) . '.';
        } else {
            $detail = !empty($GLOBALS['last_mail_error']) ? ' Systémová chyba: ' . htmlspecialchars($GLOBALS['last_mail_error']) : ' Funkce mail() vrátila false – pravděpodobně chybí konfigurace odesílatele nebo webhostingový mail() je zakázán.';
            $error_msg = 'Chyba při odesílání e-mailu.' . $detail;
        }
    }
}

// Vynucení nové detekce geolokační lokace serveru (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'redetect_location' && $user_role === 'admin') {
    $loc = detect_server_location();
    $stmt_set = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
    $stmt_set->execute(['ip_loc_local', $loc, $loc]);
    $success_msg = 'Lokace serveru byla úspěšně znovuzjištěna: ' . htmlspecialchars($loc);
}

// Ruční odeslání týdenního reportu/digestu (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'send_weekly_digest' && $user_role === 'admin') {
    if (send_digest_report($pdo, 'weekly')) {
        $success_msg = 'Týdenní report byl úspěšně vygenerován a odeslán na e-maily všech administrátorů.';
    } else {
        $detail = !empty($GLOBALS['last_mail_error']) ? ' Detaily: ' . htmlspecialchars($GLOBALS['last_mail_error']) : '';
        $error_msg = 'Chyba při odesílání týdenního reportu.' . $detail;
    }
}

// Ruční odeslání měsíčního reportu/digestu (pouze pro Admina)
if (isset($_GET['action']) && $_GET['action'] === 'send_monthly_digest' && $user_role === 'admin') {
    if (send_digest_report($pdo, 'monthly')) {
        $success_msg = 'Měsíční report byl úspěšně vygenerován a odeslán na e-maily všech administrátorů.';
    } else {
        $detail = !empty($GLOBALS['last_mail_error']) ? ' Detaily: ' . htmlspecialchars($GLOBALS['last_mail_error']) : '';
        $error_msg = 'Chyba při odesílání měsíčního reportu.' . $detail;
    }
}

// Náhled infrastructure reportu v prohlížeči (bez odeslání e-mailu) - pouze pro Admina
if (isset($_GET['action']) && in_array($_GET['action'], ['preview_weekly_digest', 'preview_monthly_digest'], true) && $user_role === 'admin') {
    $preview_period = $_GET['action'] === 'preview_monthly_digest' ? 'monthly' : 'weekly';
    $preview_data = build_digest_data($pdo, $preview_period, false);
    echo render_digest_html($preview_data);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.0/css/all.min.css">
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

        <?php if ($user_role === 'admin'): ?>
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
                                                <a href="admin.php?action=toggle_notif&field=email&id=<?php echo $mon['id']; ?>" class="notif-toggle-link" title="Přepnout E-mail notifikace">
                                                    <span style="color: <?php echo $mon['email_notifications'] ? 'var(--color-green)' : 'var(--text-muted)'; ?>; margin-right: 0.5rem;"><i class="fas fa-envelope"></i></span>
                                                </a>
                                                <a href="admin.php?action=toggle_notif&field=sms&id=<?php echo $mon['id']; ?>" class="notif-toggle-link" title="Přepnout WhatsApp / SMS notifikace">
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
                                                    <a href="admin.php?action=clear_history&id=<?php echo $mon['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Opravdu chcete kompletně vymazat historii měření, response grafy a logy pro tento monitor?')" title="Vymazat historii a logy"><i class="fas fa-eraser"></i></a>
                                                    <a href="admin.php?action=delete&id=<?php echo $mon['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Opravdu chcete smazat tento monitor?')" title="Smazat"><i class="fas fa-trash"></i></a>
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
                                    <a href="admin.php?action=redetect_location" class="btn btn-secondary btn-sm" style="display: inline-block; padding: 0.15rem 0.4rem; font-size: 0.7rem; margin-left: 0.5rem; border-radius: 4px;" title="Vynutí opětovný dotaz na IP geolokační API"><i class="fas fa-sync-alt"></i> Znovu detekovat</a>
                                </div>
                            <?php else: ?>
                                <div style="margin-top: 0.5rem; font-size: 0.8rem; color: var(--text-muted);">
                                    <i class="fas fa-info-circle"></i> Lokace hostingu dosud nebyla automaticky zjištěna (zjistí se sama při příštím běhu cronu).
                                    <a href="admin.php?action=redetect_location" class="btn btn-secondary btn-sm" style="display: inline-block; padding: 0.15rem 0.4rem; font-size: 0.7rem; margin-left: 0.5rem; border-radius: 4px;"><i class="fas fa-sync-alt"></i> Detekovat nyní</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        </div>

                        <div class="settings-tab-panel" id="tab-notifikace">
                        <h3 style="font-size: 0.9rem; color: var(--color-red); margin: 0 0 1rem 0; text-transform: uppercase;">E-mailové notifikace (SMTP / Odchozí e-mail)</h3>
                        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 1rem;">
                            Nastavte si SMTP připojení pro bezpečné a spolehlivé odesílání notifikací. Pokud necháte SMTP Server prázdný, použije se výchozí PHP funkce <code>mail()</code>.
                        </p>
                        
                        <?php
                        $is_smtp_env = is_setting_env_defined('smtp_host') || is_setting_env_defined('smtp_port') || is_setting_env_defined('smtp_user') || is_setting_env_defined('smtp_pass');
                        ?>

                        <?php if (!$is_smtp_env): ?>
                        <div class="form-group">
                            <label for="smtp_user">Odesílatel zpráv (E-mailový účet / From E-mail)</label>
                            <input type="email" name="smtp_user" id="smtp_user" value="<?php echo htmlspecialchars(get_setting('smtp_user', 'status@bloodkings.eu')); ?>" class="form-control" placeholder="status@bloodkings.eu">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="smtp_host">SMTP Server (Host)</label>
                                <input type="text" name="smtp_host" id="smtp_host" value="<?php echo htmlspecialchars(get_setting('smtp_host')); ?>" class="form-control" placeholder="např. pixel.mxrouting.net">
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
                        <a href="admin.php?action=test_email" class="btn btn-secondary" style="margin-left: 0.5rem;"><i class="fas fa-paper-plane"></i> Odeslat testovací e-mail</a>
                        <a href="admin.php?action=send_weekly_digest" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Odeslat týdenní digest všem adminům"><i class="fas fa-chart-bar"></i> Odeslat týdenní digest</a>
                        <a href="admin.php?action=send_monthly_digest" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Odeslat měsíční digest všem adminům"><i class="fas fa-chart-pie"></i> Odeslat měsíční digest</a>
                        <a href="admin.php?action=preview_weekly_digest" target="_blank" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Zobrazit náhled týdenního reportu v prohlížeči (bez odeslání)"><i class="fas fa-eye"></i> Náhled týdenního reportu</a>
                        <a href="admin.php?action=preview_monthly_digest" target="_blank" class="btn btn-secondary" style="margin-left: 0.5rem;" title="Zobrazit náhled měsíčního reportu v prohlížeči (bez odeslání)"><i class="fas fa-eye"></i> Náhled měsíčního reportu</a>
                    </form>
                </div>

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
                                                    <a href="admin.php?action=delete_user&id=<?php echo $u['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Opravdu chcete smazat tohoto uživatele?')" title="Smazat"><i class="fas fa-trash"></i></a>
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
                            <label for="u_password">Heslo <?php echo $edit_user ? '(nechte prázdné pro beze změny)' : ''; ?></label>
                            <input type="password" name="password" id="u_password" class="form-control" <?php echo $edit_user ? '' : 'required'; ?>>
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
              </div>
              
          </div>
          
          <?php endif; ?>

      </div>

    <!-- Script pro interaktivní přepínání polí v administraci -->
    <script>
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
