<?php
/**
 * Monitorovací funkce a odesílání notifikací
 */

if (ini_get('session.gc_divisor') === '0' || ini_get('session.gc_divisor') === false) {
    @ini_set('session.gc_divisor', 100);
}

require_once __DIR__ . '/db.php';

// CDN verze frontend knihoven - JEDINÉ místo, které se upravuje při aktualizaci.
// apps/status/package.json zrcadlí stejná čísla jen kvůli Dependabotu (žádný
// build krok tu není, Dependabot ale potřebuje manifest, aby vůbec věděl, že
// tahle verze existuje a dá se sledovat) - najdeš je i tam, ale nejsou svázané
// automaticky, aktualizace se musí udělat na obou místech ručně.
// ECharts je jediná knihovna pro grafy v celé appce (index.php i Level 3 detail
// stránka) - Chart.js byl odstraněný, aby se nemusely držet dvě různé knihovny
// pro totéž. Má dostupnou 6.x větev, ale je to major verze s možnými breaking
// changes v obou místech, co ji používají - záměrně necháno na 5.5.1, dokud
// to někdo neověří v prohlížeči (tady není jak).
define('BK_CDN_FONTAWESOME', 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css');
define('BK_CDN_FONTAWESOME_SRI', 'sha384-qrALq7+6jBOZIQsNnT6xGkMDru64qD6uTlDra39xrt2SoXl4pO3FX6Roz/RpR/BS');
define('BK_CDN_ECHARTS', 'https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js');
define('BK_CDN_ECHARTS_SRI', 'sha384-Mx5lkUEQPM1pOJCwFtUICyX45KNojXbkWdYhkKUKsbv391mavbfoAmONbzkgYPzR');
define('BK_CDN_QRCODE', 'https://cdn.jsdelivr.net/npm/qrcode@1.5.4/lib/browser.min.js');
define('BK_CDN_QRCODE_SRI', 'sha384-dykayVHnol2xD+KCZ38PbDk0WZnbP5x/sO6gOXKU3h+bodE3ILyIk1FOEfwO1hya');

/**
 * Konfigurace podporovaných OAuth poskytovatelů - jedno místo pro všechny 4,
 * místo separátní kopie GitHub-specifické logiky pro každého zvlášť. Scope je
 * vždy jen "přečti mi stabilní ID účtu", nic víc (žádný e-mail) - přihlášení
 * i propojení účtu se řeší výhradně přes users.oauth_provider/oauth_id
 * (nastavuje se jen explicitním propojením ve vlastním Profilu, nikdy
 * automaticky podle e-mailu - viz bezpečnostní poznámka u OAuth callbacku
 * v admin.php, proč byl e-mail jako identifikátor problém).
 */
function bk_oauth_providers() {
    return [
        'github' => [
            'label' => 'GitHub',
            'icon' => 'fab fa-github',
            'brand_color' => '#24292e',
            'authorize_url' => 'https://github.com/login/oauth/authorize',
            'token_url' => 'https://github.com/login/oauth/access_token',
            'scope' => 'read:user',
            'user_url' => 'https://api.github.com/user',
            'id_field' => 'id',
            'extra_headers' => ['User-Agent: BloodKingsStatus/1.3.0'],
        ],
        'google' => [
            'label' => 'Google',
            'icon' => 'fab fa-google',
            'brand_color' => '#4285f4',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'openid profile',
            'user_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'id_field' => 'sub',
            'extra_headers' => ['User-Agent: BloodKingsStatus/1.3.0'],
        ],
        'discord' => [
            'label' => 'Discord',
            'icon' => 'fab fa-discord',
            'brand_color' => '#5865F2',
            'authorize_url' => 'https://discord.com/api/oauth2/authorize',
            'token_url' => 'https://discord.com/api/oauth2/token',
            'scope' => 'identify',
            'user_url' => 'https://discord.com/api/users/@me',
            'id_field' => 'id',
            'extra_headers' => ['User-Agent: BloodKingsStatus/1.3.0'],
        ],
        'gitlab' => [
            'label' => 'GitLab',
            'icon' => 'fab fa-gitlab',
            'brand_color' => '#fc6d26',
            'authorize_url' => 'https://gitlab.com/oauth/authorize',
            'token_url' => 'https://gitlab.com/oauth/token',
            'scope' => 'read_user',
            'user_url' => 'https://gitlab.com/api/v4/user',
            'id_field' => 'id',
            'extra_headers' => ['User-Agent: BloodKingsStatus/1.3.0'],
        ],
    ];
}

/**
 * Provede OAuth token exchange (code -> access_token) + načtení stabilního ID
 * uživatele u zadaného poskytovatele. Vrací ['ok' => bool, 'id' => string|null,
 * 'error' => string|null] - nikdy nevyhazuje výjimku, volající si jen ověří 'ok'.
 */
function bk_oauth_fetch_identity($provider_key, $code, $redirect_uri) {
    $providers = bk_oauth_providers();
    if (!isset($providers[$provider_key])) {
        return ['ok' => false, 'id' => null, 'error' => 'Neznámý OAuth poskytovatel.'];
    }
    $cfg = $providers[$provider_key];
    $client_id = get_setting('oauth_' . $provider_key . '_client_id');
    $client_secret = get_setting('oauth_' . $provider_key . '_client_secret');

    $ch = curl_init($cfg['token_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatus/1.3.0');
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code',
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode((string)$resp, true);
    $access_token = $token_data['access_token'] ?? '';
    if (empty($access_token)) {
        return ['ok' => false, 'id' => null, 'error' => 'Nepodařilo se získat přístupový token.'];
    }

    $ch = curl_init($cfg['user_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
        ['Authorization: Bearer ' . $access_token],
        $cfg['extra_headers']
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp_user = curl_exec($ch);
    curl_close($ch);

    $user_data = json_decode((string)$resp_user, true);
    $id = $user_data[$cfg['id_field']] ?? null;
    if (empty($id)) {
        return ['ok' => false, 'id' => null, 'error' => 'Nepodařilo se načíst identitu účtu.'];
    }
    return ['ok' => true, 'id' => (string)$id, 'error' => null];
}

/**
 * Vrátí HTML ikonu pro daný typ monitoru (+ cíl u typu 'web', pro favicon).
 * Sdíleno mezi index.php (veřejný dashboard) a admin.php (seznam monitorů),
 * aby oba místa vždy zobrazovaly stejnou ikonu pro stejný typ.
 */
function monitor_type_icon(string $type, string $target = '', string $size = '1.1rem'): string {
    switch ($type) {
        case 'discord':
            return '<svg width="18" height="18" viewBox="0 0 127.14 96.36" fill="#5865F2" style="vertical-align:middle;display:inline-block;" title="Discord"><path d="M107.7 8.07A105.15 105.15 0 0 0 81.47 0a72.06 72.06 0 0 0-3.36 6.83 97.68 97.68 0 0 0-29.11 0A72.37 72.37 0 0 0 45.64 0a105.89 105.89 0 0 0-26.25 8.09C2.79 32.65-1.71 56.6.54 80.21a105.73 105.73 0 0 0 32.17 16.15 77.7 77.7 0 0 0 6.89-11.11 68.42 68.42 0 0 1-10.85-5.18c.91-.66 1.8-1.34 2.66-2.05a75.52 75.52 0 0 0 64.32 0c.87.71 1.76 1.39 2.66 2.05a68.68 68.68 0 0 1-10.87 5.19 77 77 0 0 0 6.89 11.1 105.25 105.25 0 0 0 32.19-16.14c2.64-27.38-4.51-51.11-18.91-72.14zM42.45 65.69c-6.58 0-12-6.04-12-13.43s5.3-13.43 12-13.43c6.74 0 12.05 6.09 12 13.43 0 7.39-5.26 13.43-12 13.43zm42.24 0c-6.58 0-12-6.04-12-13.43s5.3-13.43 12-13.43c6.74 0 12.05 6.09 12 13.43 0 7.39-5.26 13.43-12 13.43z"/></svg>';
        case 'minecraft':
            return '<img src="https://www.google.com/s2/favicons?sz=32&domain=minecraft.net"
                        width="16" height="16" style="border-radius:3px;vertical-align:middle;"
                        onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\'"
                        title="Minecraft">
                    <i class="fas fa-cubes" style="display:none;color:#5e8b4d;font-size:'.$size.';" title="Minecraft"></i>';
        case 'teamspeak':
            return '<i class="fas fa-headset" style="color:#5bb5e5;font-size:'.$size.';" title="TeamSpeak"></i>';
        case 'vps':
            return '<i class="fas fa-server" style="color:#a78bfa;font-size:'.$size.';" title="VPS"></i>';
        case 'cpanel':
            return '<i class="fas fa-server" style="color:#0f9f90;font-size:'.$size.';" title="cPanel Hosting"></i>';
        case 'port':
            return '<i class="fas fa-network-wired" style="color:#60a5fa;font-size:'.$size.';" title="Port"></i>';
        case 'openwrt':
            return '<i class="fas fa-wifi" style="color:#f39c12;font-size:'.$size.';" title="OpenWrt"></i>';
        case 'web':
        default:
            // Extract domain for favicon lookup
            $domain = '';
            if ($target) {
                $parsed = parse_url($target);
                $domain = $parsed['host'] ?? $target;
            }
            if ($domain) {
                return '<img src="https://www.google.com/s2/favicons?sz=32&domain='.htmlspecialchars($domain).'"
                            width="16" height="16" style="border-radius:3px;vertical-align:middle;"
                            onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'inline\'"
                            title="'.htmlspecialchars($domain).'">
                        <i class="fas fa-globe" style="display:none;color:#34d399;font-size:'.$size.';" title="Web"></i>';
            }
            return '<i class="fas fa-globe" style="color:#34d399;font-size:'.$size.';" title="Web"></i>';
    }
}

/**
 * Mapování agent_type -> soubor agenta na serveru. Jediné místo, které oba
 * spotřebitelé (kontrola aktualizace v agent_api.php i zobrazení verze na
 * dashboardu) sdílí, aby se nikde neopakoval hardcoded seznam.
 */
function bk_agent_files() {
    return [
        'bash' => 'agent.sh',
        'python' => 'agent.py',
        'powershell' => 'agent.ps1',
        'openwrt' => 'agent_openwrt.sh',
    ];
}

/**
 * Přečte AGENT_VERSION přímo z live souboru agenta na serveru (jediné místo
 * pravdy - stejná hodnota, kterou agent skutečně obsahuje), podle typu, který
 * agent sám nahlásil. Vrací null, pokud typ neznáme nebo soubor nejde přečíst
 * (např. stará data bez uloženého agent_type) - volající pak nedělá žádné
 * srovnání verzí, místo srovnávání proti cizímu/neplatnému číslu.
 */
/**
 * Je verze $have starší než $latest? Porovnává se po číselných složkách,
 * ne řetězcově - "1.10.0" < "1.9.0" by jinak vyšlo jako novější.
 */
function bk_version_is_older(?string $have, ?string $latest): bool {
    if (!$have || !$latest) {
        return false;
    }
    return version_compare($have, $latest, '<');
}

function bk_get_agent_latest_version($agent_type) {
    $agent_files = bk_agent_files();
    if (!isset($agent_files[$agent_type])) {
        return null;
    }
    $agent_file = __DIR__ . '/' . $agent_files[$agent_type];
    if (!is_readable($agent_file)) {
        return null;
    }
    $agent_source = (string)file_get_contents($agent_file);
    if (preg_match('/\$?AGENT_VERSION\s*=\s*["\']([0-9][0-9A-Za-z.\-]*)["\']/', $agent_source, $vm)) {
        return $vm[1];
    }
    return null;
}

/**
 * Zformátuje sekundy uptimu do české gramatiky
 */
function format_uptime_cz($seconds) {
    if (!$seconds || $seconds <= 0) return 'N/A';
    
    $days = floor($seconds / 86400);
    $seconds %= 86400;
    $hours = floor($seconds / 3600);
    $seconds %= 3600;
    $minutes = floor($seconds / 60);
    
    $parts = [];
    if ($days > 0) {
        if ($days == 1) $parts[] = '1 den';
        elseif ($days >= 2 && $days <= 4) $parts[] = $days . ' dny';
        else $parts[] = $days . ' dní';
    }
    if ($hours > 0) {
        if ($hours == 1) $parts[] = '1 hodina';
        elseif ($hours >= 2 && $hours <= 4) $parts[] = $hours . ' hodiny';
        else $parts[] = $hours . ' hodin';
    }
    if ($minutes > 0) {
        if ($minutes == 1) $parts[] = '1 minuta';
        elseif ($minutes >= 2 && $minutes <= 4) $parts[] = $minutes . ' minuty';
        else $parts[] = $minutes . ' minut';
    }
    
    if (empty($parts)) {
        return 'méně než minuta';
    }
    
    return implode(', ', $parts);
}

/**
 * Vykreslí grid a detaily z VPS agenta (CPU, RAM, Disk, Uptime, SMART, Porty)
 */
function render_vps_agent_details($details, $monitor = null) {
    if (!isset($details['cpu'])) return '';
    
    $cpu = floatval($details['cpu']);
    $ram = floatval($details['ram']);
    $hdd = floatval($details['hdd']);
    
    $cpu_color = ($cpu > 80) ? 'red' : (($cpu > 50) ? 'yellow' : 'green');
    $ram_color = ($ram > 85) ? 'red' : (($ram > 60) ? 'yellow' : 'green');
    $hdd_color = ($hdd > 90) ? 'red' : (($hdd > 70) ? 'yellow' : 'green');
    
    $is_admin = (session_status() === PHP_SESSION_ACTIVE) && isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    
    ob_start();
    ?>
    <div style="display: flex; flex-direction: column; gap: 0.85rem; margin-top: 0.5rem;">
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Zatížení CPU</span>
                <strong style="color: var(--text-primary);" class="stat-val"><?php echo $cpu; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $cpu_color; ?>" style="width: <?php echo $cpu; ?>%"></div>
            </div>
        </div>
        <div>
            <?php
            $ram_detail_str = '';
            // used chodí vždy spolu s total; bez něj by "0 MB / X MB" bylo vymyšlené.
            if (!empty($details['ram_total_mb']) && isset($details['ram_used_mb'])) {
                $tot_mb = (int)$details['ram_total_mb'];
                $used_mb = (int)$details['ram_used_mb'];
                $avail_mb = (int)($details['ram_available_mb'] ?? max(0, $tot_mb - $used_mb));
                if ($tot_mb >= 1024) {
                    $tot_fmt = round($tot_mb / 1024, 1) . ' GB';
                    $used_fmt = round($used_mb / 1024, 1) . ' GB';
                    $avail_fmt = round($avail_mb / 1024, 1) . ' GB';
                } else {
                    $tot_fmt = $tot_mb . ' MB';
                    $used_fmt = $used_mb . ' MB';
                    $avail_fmt = $avail_mb . ' MB';
                }
                $ram_detail_str = " ({$used_fmt} / {$tot_fmt} — volné: {$avail_fmt})";
            }
            ?>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Physical Memory Usage</span>
                <strong style="color: var(--text-primary);" class="stat-val"><?php echo $ram; ?>%<?php echo htmlspecialchars($ram_detail_str); ?></strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $ram_color; ?>" style="width: <?php echo $ram; ?>%"></div>
            </div>
        </div>
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Disk (HDD Usage)</span>
                <strong style="color: var(--text-primary);" class="stat-val"><?php echo $hdd; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $hdd_color; ?>" style="width: <?php echo $hdd; ?>%"></div>
            </div>
        </div>
    </div>
    
    <?php if (isset($details['uptime']) || isset($details['smart']) || isset($details['ports']) || isset($details['version']) || isset($details['os']) || isset($details['hostname']) || isset($details['iowait'])): ?>
        <div style="margin-top: 0.85rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.85rem; font-size: 0.78rem; display: flex; flex-direction: column; gap: 0.45rem;">
            <?php if (isset($details['version'])):
                $v_reported = trim($details['version']);
                // Správné "nejnovější" číslo se odvíjí od toho, KTERÝ agent
                // hlásí (VPS Python/Bash/PowerShell a OpenWrt agent mají
                // vlastní, na sobě nezávislé verzování) - viz bk_get_agent_latest_version().
                $latest_v = bk_get_agent_latest_version($details['agent_type'] ?? '');
                $has_update = $latest_v !== null && version_compare($v_reported, $latest_v, '<');
            ?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: var(--text-muted);">Verze agenta:</span>
                    <div>
                        <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($v_reported); ?></strong>
                        <?php if ($has_update && $is_admin): ?>
                            <span style="background: rgba(243, 156, 18, 0.15); border: 1px solid rgba(243, 156, 18, 0.25); color: #f39c12; padding: 0.05rem 0.35rem; border-radius: 4px; font-size: 0.65rem; margin-left: 0.35rem;" title="Nová verze <?php echo $latest_v; ?> je k dispozici. Stáhněte nový agent skript ze sekce návodu níže."><i class="fas fa-arrow-up"></i> Aktualizace</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($details['os'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Operační systém:</span>
                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($details['os']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['hostname'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Hostname:</span>
                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($details['hostname']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['kernel'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Kernel:</span>
                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($details['kernel']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['timezone'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Časové pásmo:</span>
                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($details['timezone']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['cloud_provider']) || !empty($details['virtualization'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Poskytovatel / virtualizace:</span>
                    <strong style="color: var(--text-primary);">
                        <?php echo htmlspecialchars($details['cloud_provider'] ?? '?'); ?><?php if (!empty($details['virtualization'])): ?> (<?php echo htmlspecialchars($details['virtualization']); ?>)<?php endif; ?>
                    </strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['reboot_required'])): ?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: var(--text-muted);">Systém:</span>
                    <span style="background: rgba(243, 156, 18, 0.15); border: 1px solid rgba(243, 156, 18, 0.25); color: #f39c12; padding: 0.1rem 0.4rem; border-radius: 4px; font-size: 0.68rem; font-weight: bold;"><i class="fas fa-power-off"></i> Vyžaduje restart</span>
                </div>
            <?php endif; ?>
            <?php if (isset($details['iowait']) && $details['iowait'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">IO Wait:</span>
                    <strong style="color: <?php echo $details['iowait'] > 20 ? 'var(--color-red)' : (($details['iowait'] > 10) ? 'var(--color-yellow)' : 'var(--text-primary)'); ?>;"><?php echo $details['iowait']; ?>%</strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['inode_usage']) && $details['inode_usage'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Zaplnění inodů:</span>
                    <strong style="color: <?php echo $details['inode_usage'] > 90 ? 'var(--color-red)' : (($details['inode_usage'] > 70) ? 'var(--color-yellow)' : 'var(--text-primary)'); ?>;"><?php echo $details['inode_usage']; ?>%</strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['btrfs_errors']) && $details['btrfs_errors'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Chyby Btrfs:</span>
                    <strong style="color: <?php echo $details['btrfs_errors'] > 0 ? 'var(--color-red)' : 'var(--color-green)'; ?>;"><?php echo (int)$details['btrfs_errors'] > 0 ? (int)$details['btrfs_errors'] : 'OK'; ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['zombie_count']) && $details['zombie_count'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Zombie procesy:</span>
                    <strong style="color: <?php echo $details['zombie_count'] > 5 ? 'var(--color-red)' : 'var(--text-primary)'; ?>;"><?php echo (int)$details['zombie_count']; ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['fork_rate']) && $details['fork_rate'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Nové procesy (od posl. kontroly):</span>
                    <strong style="color: var(--text-primary);"><?php echo (int)$details['fork_rate']; ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['temperature']) && $details['temperature'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Teplota:</span>
                    <strong style="color: <?php echo $details['temperature'] > 80 ? 'var(--color-red)' : (($details['temperature'] > 65) ? 'var(--color-yellow)' : 'var(--text-primary)'); ?>;"><?php echo $details['temperature']; ?>°C</strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['uptime'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Uptime serveru:</span>
                    <strong style="color: var(--text-primary);"><?php echo format_uptime_cz($details['uptime']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['smart'])): 
                $smart_val = $details['smart'];
                $smart_missing = (empty($smart_val) || strpos($smart_val, 'chybí') !== false || strpos($smart_val, 'missing') !== false || $smart_val === 'N/A');
                if (!$smart_missing):
                    $smart_color = (strpos($smart_val, 'WARNING') !== false) ? 'var(--color-red)' : 'var(--color-green)';
            ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Stav disků (SMART):</span>
                    <strong style="color: <?php echo $smart_color; ?>;"><?php echo htmlspecialchars($smart_val); ?></strong>
                </div>
            <?php elseif ($is_admin): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Stav disků (SMART):</span>
                    <strong style="color: var(--color-red);" title="Doporučujeme nainstalovat balíček 'smartmontools' (smartctl) na VPS pro monitorování zdraví disků.">N/A (smartctl chybí)</strong>
                </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php 
            if ($monitor):
                $monitored_str = $monitor['monitored_processes'] ?? '';
                if (!empty($monitored_str)):
                    $monitored_arr = array_filter(array_map('trim', explode(',', $monitored_str)));
                    $missing_arr = $details['missing_processes'] ?? [];
            ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem;">
                    <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Sledované procesy:</span>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                        <?php foreach ($monitored_arr as $proc): 
                            $is_missing = in_array($proc, $missing_arr);
                            $badge_bg = $is_missing ? 'rgba(193,18,31,0.1)' : 'rgba(30,199,115,0.1)';
                            $badge_border = $is_missing ? 'rgba(193,18,31,0.2)' : 'rgba(30,199,115,0.2)';
                            $badge_color = $is_missing ? 'var(--color-red)' : 'var(--color-green)';
                            $badge_icon = $is_missing ? 'fa-times-circle' : 'fa-check-circle';
                        ?>
                            <span style="background: <?php echo $badge_bg; ?>; border: 1px solid <?php echo $badge_border; ?>; color: <?php echo $badge_color; ?>; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.68rem; display: inline-flex; align-items: center; gap: 0.25rem; font-weight: bold;" title="<?php echo $is_missing ? 'Proces neběží!' : 'Proces je aktivní'; ?>">
                                <i class="fas <?php echo $badge_icon; ?>"></i> <?php echo htmlspecialchars($proc); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($monitor && $monitor['type'] === 'vps' && !empty($details['ports'])): 
                // Porty mohou přijít jako pole nebo čárkou oddělený řetězec
                $ports_arr = is_array($details['ports']) ? $details['ports'] : array_filter(array_map('trim', explode(',', $details['ports'])));
            ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem;">
                    <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">Aktivní porty serveru:</span>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.35rem;">
                        <?php foreach ($ports_arr as $p): ?>
                            <span style="background: rgba(30,199,115,0.1); border: 1px solid rgba(30,199,115,0.2); color: var(--color-green); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.68rem; font-family: monospace; font-weight: bold;"><?php echo htmlspecialchars($p); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($details['discovered_services']) && is_array($details['discovered_services'])): ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem;">
                    <span style="color: var(--text-muted); display: block; margin-bottom: 0.35rem;"><?php echo htmlspecialchars(t('agent_discovered_services')); ?></span>
                    <div style="display: flex; flex-direction: column; gap: 0.3rem;">
                        <?php foreach ($details['discovered_services'] as $svc):
                            $svc_conf = (int)($svc['confidence'] ?? 0);
                            $svc_color = $svc_conf >= 70 ? 'var(--color-green)' : ($svc_conf >= 40 ? 'var(--color-yellow)' : 'var(--text-secondary)');
                            $svc_bg = $svc_conf >= 70 ? 'rgba(30,199,115,0.1)' : ($svc_conf >= 40 ? 'rgba(243,156,18,0.1)' : 'rgba(148,163,184,0.08)');
                            $svc_border = $svc_conf >= 70 ? 'rgba(30,199,115,0.2)' : ($svc_conf >= 40 ? 'rgba(243,156,18,0.2)' : 'rgba(148,163,184,0.15)');
                            $svc_evidence = $svc['evidence'] ?? [];
                            $svc_missing = $svc['missing'] ?? [];
                            $svc_title = implode(', ', $svc_evidence);
                            if (!empty($svc_missing)) $svc_title .= ' | ' . t('agent_svc_missing') . ': ' . implode(', ', $svc_missing);
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background: <?php echo $svc_bg; ?>; border: 1px solid <?php echo $svc_border; ?>; padding: 0.25rem 0.5rem; border-radius: 5px;" title="<?php echo htmlspecialchars($svc_title); ?>">
                                <span style="font-size: 0.72rem; color: var(--text-primary); font-weight: 600;">
                                    <i class="fas fa-cube" style="color: <?php echo $svc_color; ?>; margin-right: 0.3rem;"></i><?php echo htmlspecialchars($svc['name'] ?? '?'); ?>
                                    <?php if (!empty($svc['port'])): ?><span style="color: var(--text-muted); font-weight: normal; font-family: monospace; font-size: 0.65rem; margin-left: 0.3rem;">:<?php echo (int)$svc['port']; ?></span><?php endif; ?>
                                </span>
                                <span style="font-size: 0.65rem; font-weight: bold; color: <?php echo $svc_color; ?>;"><?php echo $svc_conf; ?>%</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($details['top_cpu_processes']) || !empty($details['top_ram_processes'])): ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <?php if (!empty($details['top_cpu_processes'])): ?>
                        <div>
                            <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">TOP CPU procesy:</span>
                            <div style="display: flex; flex-direction: column; gap: 0.2rem;">
                                <?php foreach ($details['top_cpu_processes'] as $tp): ?>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem;">
                                        <span style="color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($tp['name'] ?? '?'); ?></span>
                                        <strong style="color: var(--text-primary); margin-left: 0.5rem; white-space: nowrap;"><?php echo bk_num($tp['cpu'] ?? null, ' %', 1); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($details['top_ram_processes'])): ?>
                        <div>
                            <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">TOP RAM procesy:</span>
                            <div style="display: flex; flex-direction: column; gap: 0.2rem;">
                                <?php foreach ($details['top_ram_processes'] as $tp): ?>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem;">
                                        <span style="color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($tp['name'] ?? '?'); ?></span>
                                        <strong style="color: var(--text-primary); margin-left: 0.5rem; white-space: nowrap;"><?php echo bk_num($tp['ram_mb'] ?? null, ' MB', 1); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

/**
 * Knowledge layer - vrátí pole tipů vysvětlujících, co znamená aktuálně
 * překročený práh u některé z metrik. Nevymýšlí nové prahy - každé pravidlo
 * zrcadlí práh, který už dnes rozhoduje o červené/žluté barvě jinde v kódu
 * (render_vps_agent_details() výše, SSL karta a check pipeline v index.php,
 * status polí z build_teamspeak_health_areas()). Tipy dědí viditelnost od
 * metriky, kterou vysvětlují (viz $enabled_metrics) - nejsou samostatně
 * vypínatelné, protože bez zobrazené metriky by tip nedával smysl.
 *
 * @return array<int, array{icon: string, severity: string, text: string}>
 */
/**
 * Zjistí, jak dlouho (v minutách) je metrika nad daným prahem.
 * Vrací null pokud není dostatek dat nebo metrika aktuálně pod prahem je.
 */
function bk_metric_duration_above($pdo, $monitor_id, $column, $threshold, $lookback_hours = 24) {
    try {
        $stmt = $pdo->prepare("
            SELECT checked_at, $column AS val FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY checked_at DESC
        ");
        $stmt->execute([$monitor_id, $lookback_hours]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return null;
    }

    if (empty($rows)) return null;

    // Aktuální hodnota musí být nad prahem
    $latest = (float)$rows[0]['val'];
    if ($latest <= $threshold) return null;

    // Projdi od nejnovějšího zpět a najdi první vzorek pod prahem
    $above_since = strtotime($rows[0]['checked_at']);
    foreach ($rows as $row) {
        if ((float)$row['val'] <= $threshold) {
            break;
        }
        $above_since = strtotime($row['checked_at']);
    }

    $minutes = (int)round((time() - $above_since) / 60);
    return $minutes > 0 ? $minutes : null;
}

/**
 * Zformátuje dobu trvání v minutách do čitelného řetězce (ČR/EN).
 */
/**
 * Vykreslení naměřené hodnoty: číslo, nebo pomlčka když se nezměřilo.
 *
 * Existuje proto, aby se nemuselo psát `$x ?? 0` - nula je platné měření
 * ("disk je z 0 % plný"), takže jí nesmí nahrazovat chybějící údaj.
 *
 * @param mixed  $value    hodnota z details/JSON (může být null nebo chybět)
 * @param string $unit     jednotka připojená za číslo (" %", " MB", " ms"…)
 * @param int    $decimals počet desetinných míst
 */
/**
 * Má rozhraní nenulový počet chybových paketů?
 *
 * Neznámý počet chyb NENÍ nula chyb - vrací false (nebarví se červeně),
 * ale bez předstírání, že je vše v pořádku změřené.
 */
/**
 * Dostupnost monitoru za posledních N dní z reálných kontrol.
 *
 * Vrací null, když v okně není ani jedno měření - widget/odznak pak ukáže
 * pomlčku místo vymyšlených 100 %. Vznikla proto, že widget.php i badge.php
 * volaly funkci calculate_uptime(), která v kódu vůbec neexistovala, a obě
 * stránky proto končily fatální chybou.
 */
/**
 * Přepočte denní souhrny dostupnosti z monitor_logs.
 *
 * Volá se z cronu TĚSNĚ PŘED mazáním starých logů - jinak by se data, která
 * se právě chystají zmizet, do souhrnu nikdy nedostala.
 *
 * Přepisuje celé dny (INSERT ... ON DUPLICATE KEY UPDATE), takže opakované
 * spuštění nic nezdvojí a dnešek se v průběhu dne postupně zpřesňuje.
 *
 * Do jmenovatele jdou jen skutečná měření - 'maintenance' a 'unknown' se
 * nepočítají, protože plánovaná odstávka není výpadek a nezměřený stav
 * není měření.
 *
 * @param int $days Kolik posledních dnů přepočítat (výchozí 2 = dnešek
 *                  a včerejšek, což stačí při běhu každou minutu).
 * @return int Počet zapsaných/aktualizovaných dnů.
 */
/**
 * Číselná metrika z hlášení agenta, nebo NULL.
 *
 * Jádro pravidla celého projektu na jednom místě: chybějící, prázdná nebo
 * nečíselná hodnota je NULL, nikdy nula. Nula znamená „naměřeno nula" -
 * u zahozených paketů nebo teploty je to úplně jiná informace než
 * „agent tuhle hodnotu neposlal".
 *
 * Shell agenti posílají nezměřené hodnoty jako JSON null, ale starší verze
 * poslaly řetězec "null" - ten se proto bere taky jako chybějící.
 */
function bk_agent_num(array $data, string $key): ?float {
    if (!array_key_exists($key, $data)) {
        return null;
    }
    $value = $data[$key];
    if ($value === null || $value === '' || $value === 'null' || is_array($value) || is_bool($value)) {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

/** Celočíselná varianta bk_agent_num() - pro počítadla a počty. */
function bk_agent_int(array $data, string $key): ?int {
    $num = bk_agent_num($data, $key);
    return $num === null ? null : (int)round($num);
}

/**
 * Denní agregace metrik agentů do `metrics_daily`.
 *
 * Syrové `vps_metrics` se po 30 dnech mažou. Dostupnost tuhle hranici
 * přežívala díky `uptime_daily`, metriky ne - takže na otázku „jak rostlo
 * zaplnění disku za půl roku" neexistovala odpověď a odhad „disk bude plný
 * za X dní" se navždy počítal nejvýš z třiceti dnů.
 *
 * Ukládá se min/průměr/max a počet vzorků. Průměr sám by schoval špičky,
 * na které se kapacitní plánování ptá především; max bez počtu vzorků by
 * zase nešlo odlišit od jediného výkyvu.
 *
 * Dny se přepočítávají znovu (ON DUPLICATE KEY UPDATE), aby doběhnutí
 * pozdních dat dnešek opravilo.
 *
 * @param int $days Kolik posledních dnů přepočítat (výchozí 2 = dnešek a včerejšek).
 * @return int Počet zapsaných/aktualizovaných řádků.
 */
/**
 * Retention for process history.
 *
 * This table grows fastest of all - ten rows per monitor per minute - so how
 * long it is kept is a setting rather than a constant. Two stages:
 *
 *   1. Anything past `$days` goes.
 *   2. Optionally, samples older than `$peak_after_days` are thinned down to
 *      the ones that were actually interesting. Survivors are stamped
 *      kept_reason='peak' so a thinned window can be told apart from a quiet
 *      one - without that, pruned history would read as "nothing was running".
 *
 * `$days = 0` means the feature is off, and then the table is emptied: leaving
 * data behind that nothing collects any more and nobody can see is worse than
 * deleting it.
 *
 * Returns counts so cron.php can report what it did instead of claiming success.
 */
function bk_prune_process_samples(PDO $pdo, int $days, int $peak_after_days = 0, float $peak_threshold = 50.0): array {
    $result = ['deleted' => 0, 'pruned' => 0, 'marked' => 0, 'disabled' => false];

    if ($days <= 0) {
        $result['disabled'] = true;
        $result['deleted'] = (int)$pdo->exec("DELETE FROM process_samples");
        return $result;
    }

    $stmt = $pdo->prepare("DELETE FROM process_samples WHERE sampled_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);
    $result['deleted'] = $stmt->rowCount();

    // Thinning only makes sense strictly inside the retention window. A
    // threshold at or past the retention would mark rows that are about to be
    // deleted anyway; a zero switches it off entirely.
    if ($peak_after_days <= 0 || $peak_after_days >= $days) {
        return $result;
    }

    $stmt_mark = $pdo->prepare(
        "UPDATE process_samples SET kept_reason = 'peak'
          WHERE kept_reason = 'raw'
            AND sampled_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND (cpu_pct >= ? OR ram_mb >= ?)"
    );
    $stmt_mark->execute([$peak_after_days, $peak_threshold, $peak_threshold]);
    $result['marked'] = $stmt_mark->rowCount();

    $stmt_prune = $pdo->prepare(
        "DELETE FROM process_samples WHERE kept_reason = 'raw' AND sampled_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
    );
    $stmt_prune->execute([$peak_after_days]);
    $result['pruned'] = $stmt_prune->rowCount();

    return $result;
}

function bk_rollup_daily_metrics(PDO $pdo, int $days = 2): int {
    $days = max(1, min(400, $days));
    $written = 0;

    foreach (bk_metric_column_map() as $metric_key => $def) {
        $col = $def['col'] ?? null;
        if ($col === null) {
            continue;
        }
        // Název sloupce nejde předat jako parametr; bere se z vlastní mapy
        // v kódu, ne ze vstupu, ale pro jistotu se ověří tvar.
        if (!preg_match('/^[a-z0-9_]+$/', $col)) {
            continue;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO metrics_daily (monitor_id, day, metric_key, min_val, avg_val, max_val, samples)
                SELECT monitor_id,
                       DATE(checked_at) AS day,
                       ?,
                       MIN({$col}), AVG({$col}), MAX({$col}), COUNT({$col})
                FROM vps_metrics
                WHERE checked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  AND {$col} IS NOT NULL
                GROUP BY monitor_id, DATE(checked_at)
                ON DUPLICATE KEY UPDATE
                    min_val = VALUES(min_val),
                    avg_val = VALUES(avg_val),
                    max_val = VALUES(max_val),
                    samples = VALUES(samples)
            ");
            $stmt->execute([$metric_key, $days]);
            $written += $stmt->rowCount();
        } catch (Throwable $e) {
            // Chybějící sloupec u starší databáze nesmí shodit celou agregaci -
            // ostatní metriky se zpracují dál.
            error_log("[rollup] Metrika {$metric_key} ({$col}) selhala: " . $e->getMessage());
        }
    }

    return $written;
}

/**
 * How many minutes of history the selected period means.
 *
 * This used to be computed in hours by two identical ternary expressions in
 * api.php, and two periods came out wrong: `15m` returned an hour and `6h`
 * returned 24 hours (verified in production - a request for 6h came back with
 * 1435 minutes of data). You switched the range, the chart visibly changed,
 * and it showed something other than its own label. Minutes are used here
 * because hours could not express 15 minutes.
 *
 * Returns null for periods read from the daily rollup (90d and longer).
 */
function bk_period_minutes(string $period): ?int {
    return match ($period) {
        '15m' => 15,
        '1h' => 60,
        '6h' => 360,
        '12h' => 720,
        '24h' => 1440,
        '7d' => 10080,
        '30d' => 43200,
        // 90d/180d/1y go through metrics_daily - raw data is purged after 30 days.
        '90d', '180d', '1y' => null,
        default => 1440,
    };
}

/**
 * Mapa metrik na sloupce ve `vps_metrics`.
 *
 * Bydlela v api.php, ale potřebuje ji i cron kvůli denní agregaci. Kdyby
 * existovala dvakrát, rozejdou se - a chybějící metrika v agregaci se pozná
 * až za měsíc, kdy už syrová data nejsou.
 */
function bk_metric_column_map(): array {
    // Pozor na `net` vs `net_ipv4`/`net_ipv6`: NEJSOU to části téhož celku.
    // `net` je rx+tx jen na WAN rozhraní, zatímco protokolové čítače jdou
    // z /proc/net/netstat, tedy přes VŠECHNA rozhraní včetně LAN. Součet
    // IPv4+IPv6 proto bývá vyšší než `net` a postavit je vedle sebe jako
    // rozpad jednoho čísla by uživateli ukázalo součet, který nesedí.
    return [
    'cpu' => ['col' => 'cpu_usage', 'unit' => '%', 'label' => 'Využití CPU'],
    'ram' => ['col' => 'ram_usage', 'unit' => '%', 'label' => 'Využití paměti'],
    'hdd' => ['col' => 'hdd_usage', 'unit' => '%', 'label' => 'Zaplnění disku'],
    'net' => ['col' => 'net_usage', 'unit' => 'KB/s', 'label' => 'Síťový provoz na WAN'],
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
    // Tyhle dvě řady čtou TÝŽ sloupec (kolik lidí je online) a liší se jen
    // pojmenováním. 'only' je proto omezuje na typ monitoru, kterému dávají
    // smysl - jinak by detail Discordu ukazoval dva identické grafy, z toho
    // jeden nadepsaný "TeamSpeak Klienti".
    'ts_clients' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'TeamSpeak Klienti', 'only' => ['teamspeak']],
    'discord_presence' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'Online na Discordu', 'only' => ['discord']],
    'mc_players' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'Hráči online', 'only' => ['minecraft']],
    'ts_process_cpu' => ['col' => 'ts_process_cpu', 'unit' => '%', 'label' => 'CPU procesu TS3'],
    'ts_process_ram' => ['col' => 'ts_process_ram', 'unit' => 'MB', 'label' => 'RAM procesu TS3'],
    'net_ipv4' => ['col' => 'net_ipv4_kbps', 'unit' => 'KB/s', 'label' => 'IPv4 provoz (všechna rozhraní)'],
    'net_ipv6' => ['col' => 'net_ipv6_kbps', 'unit' => 'KB/s', 'label' => 'IPv6 provoz (všechna rozhraní)'],
    'temperature_c' => ['col' => 'temperature_c', 'unit' => '°C', 'label' => 'Teplota CPU'],
    // Sloupce, které se roky ukládaly, ale žádný graf je nečetl (audit 2026-08-05):
    'zombie_count' => ['col' => 'zombie_count', 'unit' => '', 'label' => 'Zombie procesy'],
    'fork_rate' => ['col' => 'fork_rate', 'unit' => '/s', 'label' => 'Fork rate'],
    'wifi_clients' => ['col' => 'wifi_clients_total', 'unit' => '', 'label' => 'Wi-Fi klienti'],
    'conntrack' => ['col' => 'conntrack_pct', 'unit' => '%', 'label' => 'Conntrack tabulka'],
    // Metriky doplněné 08/2026: agenti je posílali každou minutu, ale
    // ukládal se jen poslední snímek, takže z nich nezbyla historie.
    'wan_latency_ms' => ['col' => 'wan_latency_ms', 'unit' => 'ms', 'label' => 'Latence WAN'],
    'dns_latency_ms' => ['col' => 'dns_latency_ms', 'unit' => 'ms', 'label' => 'Latence DNS'],
    'entropy' => ['col' => 'entropy_avail', 'unit' => 'bit', 'label' => 'Dostupná entropie'],
    'lte_rsrq' => ['col' => 'lte_rsrq', 'unit' => 'dB', 'label' => 'LTE RSRQ (kvalita)'],
    'lte_rssi' => ['col' => 'lte_rssi', 'unit' => 'dBm', 'label' => 'LTE RSSI (síla)'],
    'lte_sinr' => ['col' => 'lte_sinr', 'unit' => 'dB', 'label' => 'LTE SINR (odstup)'],
    'lte_uptime' => ['col' => 'lte_uptime_secs', 'unit' => 's', 'label' => 'Doba spojení LTE'],
    'ups_battery_pct' => ['col' => 'ups_battery_pct', 'unit' => '%', 'label' => 'Baterie UPS'],
    'conntrack_count' => ['col' => 'conntrack_count', 'unit' => '', 'label' => 'Spojení v conntracku'],
    'dhcp_leases_count' => ['col' => 'dhcp_leases_count', 'unit' => '', 'label' => 'Aktivní DHCP zápůjčky'],
    'dhcp_reservations_count' => ['col' => 'dhcp_reservations_count', 'unit' => '', 'label' => 'DHCP rezervace'],
    'tailscale_peers' => ['col' => 'tailscale_peers', 'unit' => '', 'label' => 'Tailscale protějšky'],
    'wireguard_peers' => ['col' => 'wireguard_peers', 'unit' => '', 'label' => 'WireGuard protějšky'],
    'openvpn_tunnels' => ['col' => 'openvpn_tunnels', 'unit' => '', 'label' => 'OpenVPN tunely'],
    'ram_used_mb' => ['col' => 'ram_used_mb', 'unit' => 'MB', 'label' => 'Obsazená paměť'],
    'ram_free_mb' => ['col' => 'ram_free_mb', 'unit' => 'MB', 'label' => 'Volná paměť'],
    'ram_available_mb' => ['col' => 'ram_available_mb', 'unit' => 'MB', 'label' => 'Dostupná paměť'],
    'ram_total_mb' => ['col' => 'ram_total_mb', 'unit' => 'MB', 'label' => 'Celková paměť'],
    'wan_link_mbit' => ['col' => 'wan_link_mbit', 'unit' => 'Mbit/s', 'label' => 'Rychlost linky WAN'],
    'wan_uptime' => ['col' => 'wan_uptime_secs', 'unit' => 's', 'label' => 'Doba spojení WAN'],
    'log_errors_24h' => ['col' => 'log_errors_24h', 'unit' => '', 'label' => 'Chyby v logu za 24 h'],
    'log_warnings_24h' => ['col' => 'log_warnings_24h', 'unit' => '', 'label' => 'Varování v logu za 24 h'],
    'btrfs_errors' => ['col' => 'btrfs_errors', 'unit' => '', 'label' => 'Chyby Btrfs'],
    'sqm_download_kbps' => ['col' => 'sqm_download_kbps', 'unit' => 'kbit/s', 'label' => 'SQM limit stahování'],
    'sqm_upload_kbps' => ['col' => 'sqm_upload_kbps', 'unit' => 'kbit/s', 'label' => 'SQM limit odesílání'],
    'fw_accepted' => ['col' => 'fw_accepted', 'unit' => '', 'label' => 'Firewall - propuštěno', 'counter' => true],
    'fw_dropped' => ['col' => 'fw_dropped', 'unit' => '', 'label' => 'Firewall - zahozeno', 'counter' => true],
    'fw_rejected' => ['col' => 'fw_rejected', 'unit' => '', 'label' => 'Firewall - odmítnuto', 'counter' => true],
    'dns_queries' => ['col' => 'dns_queries', 'unit' => '', 'label' => 'DNS dotazy', 'counter' => true],
    'dns_cache_hits' => ['col' => 'dns_cache_hits', 'unit' => '', 'label' => 'DNS z cache', 'counter' => true],
    'dns_cache_misses' => ['col' => 'dns_cache_misses', 'unit' => '', 'label' => 'DNS mimo cache', 'counter' => true],
    'tcp_retrans' => ['col' => 'tcp_retrans', 'unit' => '', 'label' => 'TCP retransmise', 'counter' => true],
    'oom_kills' => ['col' => 'oom_kills', 'unit' => '', 'label' => 'Zabito kvůli paměti', 'counter' => true],
    'sqm_dropped' => ['col' => 'sqm_dropped', 'unit' => '', 'label' => 'SQM zahozené pakety', 'counter' => true],
    'wan_reconnect_count' => ['col' => 'wan_reconnect_count', 'unit' => '', 'label' => 'Znovupřipojení WAN', 'counter' => true],
];
}

/**
 * Rozsahy Cloudflare, ze kterých smíme věřit hlavičce s IP návštěvníka.
 *
 * Zdroj: https://www.cloudflare.com/ips/ - mění se řádově jednou za roky,
 * ale při rozšíření je potřeba tenhle seznam doplnit. Kdo Cloudflare
 * nepoužívá, nemusí dělat nic: bez shody se prostě použije REMOTE_ADDR.
 */
const BK_CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/**
 * Spadá IP do rozsahu zapsaného v CIDR? Umí IPv4 i IPv6.
 *
 * Porovnává se po bitech nad binární podobou z inet_pton - řetězcové
 * porovnání prefixu by u IPv6 selhalo na zkráceném zápisu (2400:cb00::
 * a 2400:cb00:0:0:0:0:0:0 jsou tatáž adresa).
 */
function bk_ip_in_cidr(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) {
        return false;
    }
    [$subnet, $bits_raw] = explode('/', $cidr, 2);
    $bits = (int)$bits_raw;

    $ip_bin = @inet_pton($ip);
    $subnet_bin = @inet_pton($subnet);
    if ($ip_bin === false || $subnet_bin === false) {
        return false;
    }
    // Nemíchat IPv4 a IPv6 - délka binární podoby se liší (4 vs 16 bajtů).
    if (strlen($ip_bin) !== strlen($subnet_bin)) {
        return false;
    }
    if ($bits < 0 || $bits > strlen($ip_bin) * 8) {
        return false;
    }

    $whole_bytes = intdiv($bits, 8);
    $remaining_bits = $bits % 8;

    if ($whole_bytes > 0 && strncmp($ip_bin, $subnet_bin, $whole_bytes) !== 0) {
        return false;
    }
    if ($remaining_bits === 0) {
        return true;
    }

    $mask = ~((1 << (8 - $remaining_bits)) - 1) & 0xFF;
    return (ord($ip_bin[$whole_bytes]) & $mask) === (ord($subnet_bin[$whole_bytes]) & $mask);
}

/**
 * IP adresa návštěvníka - ta skutečná, ne adresa proxy.
 *
 * Aplikace běží za Cloudflare, takže REMOTE_ADDR je adresa jejich edge uzlu.
 * Audit log kvůli tomu zaznamenával u přihlášení IP Cloudflare a k dohledání,
 * kdo se odkud přihlásil, byl nepoužitelný. Horší dopad měl na zamykání účtu
 * po neúspěšných pokusech: to počítá pokusy podle jména NEBO IP, takže se
 * z IP části stalo buď nic, nebo způsob, jak zamknout přihlášení někomu
 * cizímu, kdo se trefil do stejného edge uzlu.
 *
 * Hlavičce se věří JEN tehdy, když request opravdu přišel z Cloudflare.
 * Bez té podmínky si může kdokoli poslat `CF-Connecting-IP: 1.2.3.4` a
 * zapsat si do protokolu libovolnou adresu - nebo se vyhnout zamykání tím,
 * že ji bude při každém pokusu měnit.
 *
 * Vlastní proxy (nginx, HAProxy) jde přidat nastavením `trusted_proxies`
 * jako čárkou oddělený seznam CIDR.
 */
function bk_client_ip(): ?string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($remote === null || $remote === '') {
        return null;
    }

    $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null;
    if ($forwarded === null || $forwarded === '') {
        return $remote;
    }

    $trusted = BK_CLOUDFLARE_RANGES;
    $extra = trim((string)get_setting('trusted_proxies', ''));
    if ($extra !== '') {
        foreach (explode(',', $extra) as $range) {
            $range = trim($range);
            if ($range !== '') {
                $trusted[] = $range;
            }
        }
    }

    foreach ($trusted as $range) {
        if (bk_ip_in_cidr($remote, $range)) {
            // Hlavička může nést i nesmysl - bez ověření tvaru by se do
            // protokolu dostal libovolný řetězec.
            return filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? $forwarded : $remote;
        }
    }

    return $remote;
}

/**
 * Prohlížeč/klient, který požadavek poslal.
 *
 * Ořezáno na 255 znaků: sloupec má tuhle délku a delší User-Agent existují
 * (některé korporátní prohlížeče posílají stovky znaků).
 */
function bk_client_user_agent(): ?string {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return null;
    }
    return mb_substr($ua, 0, 255);
}

/**
 * Rozhodne, jestli incident zraje na eskalaci.
 *
 * Upozornění na výpadek dnes odejde jednou a tím to končí. Když ho nikdo
 * nevidí - je noc, telefon je ztlumený, Discord přehlušila jiná konverzace -
 * výpadek běží dál a monitoring má splněno. Eskalace je pojistka: co nikdo
 * nepřevzal do nastavené doby, se ohlásí znovu a jinam.
 *
 * Podmínky jsou schválně přísné, protože eskalace budí člověka:
 *   - incident není vyřešený (na vyřešený nemá smysl upozorňovat)
 *   - nikdo ho nepotvrdil (acknowledged_at je prázdné)
 *   - od vzniku uplynula nastavená doba
 *   - ještě neeskaloval (escalated_at je prázdné) - jinak by se opakoval
 *     při každém běhu cronu a naučil by se ignorovat stejně jako ten první
 *
 * @param array $incident Řádek z `incidents`
 * @param int   $after_mins Po kolika minutách bez převzetí eskalovat
 * @param ?int  $now Čas vyhodnocení; NULL = teď (parametr kvůli testům)
 * @return array{escalate: bool, reason: string, waiting_secs: ?int}
 */
function bk_escalation_due(array $incident, int $after_mins, ?int $now = null): array {
    $now = $now ?? time();

    if ($after_mins <= 0) {
        return ['escalate' => false, 'reason' => 'eskalace nemá nastavenou dobu', 'waiting_secs' => null];
    }
    if (($incident['status'] ?? '') === 'resolved' || !empty($incident['resolved_at'])) {
        return ['escalate' => false, 'reason' => 'incident je vyřešený', 'waiting_secs' => null];
    }
    if (!empty($incident['acknowledged_at'])) {
        return ['escalate' => false, 'reason' => 'incident někdo převzal', 'waiting_secs' => null];
    }
    if (!empty($incident['escalated_at'])) {
        return ['escalate' => false, 'reason' => 'incident už eskaloval', 'waiting_secs' => null];
    }

    $created_raw = $incident['created_at'] ?? null;
    $created_ts = ($created_raw !== null && $created_raw !== '') ? strtotime((string)$created_raw) : false;
    if ($created_ts === false) {
        // Bez času vzniku nejde spočítat, jak dlouho čeká. Eskalovat "pro
        // jistotu" by znamenalo budit člověka kvůli poškozenému záznamu.
        return ['escalate' => false, 'reason' => 'incident nemá použitelný čas vzniku', 'waiting_secs' => null];
    }

    $waiting = $now - $created_ts;
    if ($waiting < $after_mins * 60) {
        return ['escalate' => false, 'reason' => 'lhůta na převzetí ještě běží', 'waiting_secs' => $waiting];
    }

    return ['escalate' => true, 'reason' => 'nikdo incident nepřevzal', 'waiting_secs' => $waiting];
}

/**
 * Projde otevřené incidenty a ty nepřevzaté ohlásí na eskalační kanál.
 *
 * Volá se z cronu po kontrole monitorů. Kanál je schválně jiný než ten
 * běžný: eskalace má smysl jen tehdy, když se ozve někde, kde první
 * upozornění zapadlo.
 *
 * @return array{checked: int, escalated: int, skipped_no_channel: int}
 */
function bk_process_escalations(PDO $pdo, ?int $now = null): array {
    $result = ['checked' => 0, 'escalated' => 0, 'skipped_no_channel' => 0];

    if (get_setting('escalation_enabled', '0') !== '1') {
        return $result;
    }

    $after_mins = (int)get_setting('escalation_after_mins', '15');
    $webhook = trim((string)get_setting('escalation_webhook_url', ''));

    try {
        $stmt = $pdo->query("
            SELECT i.id, i.title, i.impact, i.status, i.created_at, i.resolved_at,
                   i.acknowledged_at, i.escalated_at, i.monitor_id, m.name AS monitor_name
            FROM incidents i
            LEFT JOIN monitors m ON m.id = i.monitor_id
            WHERE i.status != 'resolved' AND i.escalated_at IS NULL
            ORDER BY i.id ASC
            LIMIT 50
        ");
        $open = $stmt ? $stmt->fetchAll() : [];
    } catch (PDOException $e) {
        error_log('[escalation] Načtení incidentů selhalo: ' . $e->getMessage());
        return $result;
    }

    foreach ($open as $incident) {
        $result['checked']++;
        $verdict = bk_escalation_due($incident, $after_mins, $now);
        if (!$verdict['escalate']) {
            continue;
        }

        // Bez nastaveného kanálu se razítko NEDÁVÁ. Kdyby se dalo, incident by
        // se tvářil jako eskalovaný a po doplnění kanálu by se už neozval -
        // tiché selhání přesně tam, kde má pojistka fungovat.
        if ($webhook === '') {
            $result['skipped_no_channel']++;
            continue;
        }

        $waited = bk_format_duration_secs((int)$verdict['waiting_secs']);
        $service = !empty($incident['monitor_name']) ? $incident['monitor_name'] : null;
        $text = "🚨 **Eskalace: nikdo nepřevzal výpadek**\n"
            . '**Incident:** ' . $incident['title'] . "\n"
            . ($service !== null ? '**Služba:** ' . $service . "\n" : '')
            . '**Trvá:** ' . $waited . "\n"
            . '**Limit na převzetí:** ' . $after_mins . " min\n"
            . 'Původní upozornění odešlo a nikdo na něj nezareagoval.';

        // Payload nese `content` i `text`: Discord čte první, Slack druhý a
        // neznámý klíč obě strany ignorují. Jeden kanál tak pokryje obojí.
        send_webhook_post($webhook, json_encode(['content' => $text, 'text' => $text], JSON_UNESCAPED_UNICODE));

        try {
            $stmt_mark = $pdo->prepare("UPDATE incidents SET escalated_at = ? WHERE id = ?");
            $stmt_mark->execute([date('Y-m-d H:i:s', $now ?? time()), (int)$incident['id']]);
            $result['escalated']++;
        } catch (PDOException $e) {
            error_log('[escalation] Razítko eskalace se nepodařilo zapsat: ' . $e->getMessage());
        }
    }

    if ($result['skipped_no_channel'] > 0) {
        error_log(sprintf(
            '[escalation] %d incidentů čeká na eskalaci, ale escalation_webhook_url není nastavená.',
            $result['skipped_no_channel']
        ));
    }

    return $result;
}

/**
 * Vygeneruje tajemství pro heartbeat URL.
 *
 * Token je jediné, co úlohu autorizuje - proto z CSPRNG a dost dlouhý na to,
 * aby se nedal uhádnout. Hex, aby přežil průchod curl, wget i Task Schedulerem
 * bez escapování.
 */
function bk_heartbeat_generate_token(): string {
    return bin2hex(random_bytes(24));
}

/**
 * Vyhodnotí stav heartbeat monitoru z toho, kdy se úloha naposledy ozvala.
 *
 * Obrácený směr než zbytek monitoringu: neptáme se my služby, ale ona se hlásí
 * nám. Pokrývá to, na co aktivní kontrola nedosáhne - zálohy, cronjoby a dávky,
 * u kterých není co pingnout a jejichž selhání se dnes pozná až ve chvíli, kdy
 * je záloha potřeba.
 *
 * Rozlišují se tři stavy, ne dva:
 *   up      - signál přišel včas a úloha hlásí úspěch
 *   down    - buď se úloha neozvala do intervalu + tolerance, nebo přímo
 *             ohlásila selhání (?status=fail)
 *   unknown - ještě se neozvala ani jednou, nebo nemá nastavený interval
 *
 * To poslední je důležité: monitor, který nikdy nedostal signál, NENÍ dole.
 * Nevíme o něm nic. Kdyby se založil jako 'down', přišel by alert na výpadek,
 * který se nestal, a to je stejná lež jako vymyšlená nula v grafu.
 *
 * @param array $monitor Řádek z `monitors` (heartbeat_* sloupce)
 * @param ?int  $now     Čas vyhodnocení; NULL = teď (parametr kvůli testům)
 * @return array{status: string, error: ?string, age_secs: ?int, deadline_secs: ?int, overdue_secs: ?int}
 */
function bk_heartbeat_evaluate(array $monitor, ?int $now = null): array {
    $now = $now ?? time();

    $interval = isset($monitor['heartbeat_interval']) && $monitor['heartbeat_interval'] !== null
        ? (int)$monitor['heartbeat_interval']
        : 0;

    if ($interval <= 0) {
        return [
            'status' => 'unknown',
            'error' => 'Heartbeat monitor nemá nastavený interval, takže není podle čeho poznat zpoždění.',
            'age_secs' => null,
            'deadline_secs' => null,
            'overdue_secs' => null,
        ];
    }

    // Tolerance je volitelná; bez ní se hlídá přesně na interval.
    $grace = isset($monitor['heartbeat_grace']) && $monitor['heartbeat_grace'] !== null
        ? max(0, (int)$monitor['heartbeat_grace'])
        : 0;
    $deadline = $interval + $grace;

    $last_raw = $monitor['last_heartbeat'] ?? null;
    $last_ts = ($last_raw !== null && $last_raw !== '') ? strtotime((string)$last_raw) : false;

    if ($last_ts === false) {
        return [
            'status' => 'unknown',
            'error' => 'Zatím nepřišel žádný signál - úloha se ještě ani jednou neohlásila.',
            'age_secs' => null,
            'deadline_secs' => $deadline,
            'overdue_secs' => null,
        ];
    }

    // Záporný věk = signál z budoucnosti (rozejité hodiny na stroji s úlohou).
    // Bereme ho jako čerstvý, ale nula by tvrdila, že dorazil právě teď.
    $age = $now - $last_ts;

    // Ohlášené selhání má přednost před stářím: úloha doběhla včas, ale
    // skončila chybou. Mlčet o tom jen proto, že signál přišel, by z hlídače
    // udělalo kontrolu, že cron vůbec startuje - ne že záloha vznikla.
    if (($monitor['heartbeat_last_result'] ?? null) === 'fail' && $age <= $deadline) {
        $msg = trim((string)($monitor['heartbeat_last_message'] ?? ''));
        return [
            'status' => 'down',
            'error' => $msg !== ''
                ? 'Úloha ohlásila selhání: ' . $msg
                : 'Úloha ohlásila selhání, ale neposlala žádný popis.',
            'age_secs' => $age,
            'deadline_secs' => $deadline,
            'overdue_secs' => null,
        ];
    }

    if ($age > $deadline) {
        return [
            'status' => 'down',
            'error' => sprintf(
                'Úloha se neozvala %s (limit je %s: interval %s + tolerance %s).',
                bk_format_duration_secs($age),
                bk_format_duration_secs($deadline),
                bk_format_duration_secs($interval),
                bk_format_duration_secs($grace)
            ),
            'age_secs' => $age,
            'deadline_secs' => $deadline,
            'overdue_secs' => $age - $deadline,
        ];
    }

    return [
        'status' => 'up',
        'error' => null,
        'age_secs' => $age,
        'deadline_secs' => $deadline,
        'overdue_secs' => null,
    ];
}

/**
 * Doba v sekundách jako čitelný text ("2 h 5 min").
 *
 * Hlášky o heartbeatu čte člověk uprostřed noci - "neozvala se 7 320 s" nutí
 * počítat, "neozvala se 2 h 2 min" ne.
 */
function bk_format_duration_secs(int $secs): string {
    if ($secs < 0) {
        $secs = 0;
    }
    if ($secs < 60) {
        return $secs . ' s';
    }
    if ($secs < 3600) {
        return intdiv($secs, 60) . ' min';
    }
    if ($secs < 86400) {
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        return $m > 0 ? "{$h} h {$m} min" : "{$h} h";
    }
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    return $h > 0 ? "{$d} d {$h} h" : "{$d} d";
}

/**
 * Vyhodnotí, jestli je odezva monitoru trvale zhoršená.
 *
 * Monitoring dosud uměl říct jen „služba je dole". Web, který zpomalil
 * z 80 ms na 900 ms a tam zůstal, byl přitom pořád „up" a nikdo se to
 * nedozvěděl.
 *
 * Podmínka je záměrně přísná: PRŮMĚR i VŠECHNY kontroly ve sledovaném okně
 * musí být nad prahem. Jedna pomalá odpověď (přetížený DNS resolver,
 * náhodný packet loss) je šum, ne incident - a alert, který houká na šum,
 * se během týdne naučí každý ignorovat.
 *
 * @return array{state: string, avg_ms: ?float, checks: int}
 *         state: 'degraded' | 'recovered' | 'ok'
 */
function bk_evaluate_latency(PDO $pdo, array $monitor, bool $alert_already_sent): array {
    $threshold = isset($monitor['latency_threshold_ms']) && $monitor['latency_threshold_ms'] !== null
        ? (int)$monitor['latency_threshold_ms']
        : 0;
    if ($threshold <= 0) {
        return ['state' => 'ok', 'avg_ms' => null, 'checks' => 0];
    }

    $window = max(1, (int)($monitor['latency_threshold_mins'] ?? 5));

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS checks,
                   AVG(response_time) AS avg_ms,
                   MIN(response_time) AS min_ms
            FROM monitor_logs
            WHERE monitor_id = ?
              AND status = 'up'
              AND response_time IS NOT NULL
              AND response_time > 0
              AND checked_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([(int)$monitor['id'], $window]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return ['state' => 'ok', 'avg_ms' => null, 'checks' => 0];
    }

    $checks = (int)($row['checks'] ?? 0);
    // Aspoň dvě měření - z jednoho se trvalost poznat nedá.
    if ($checks < 2) {
        return ['state' => 'ok', 'avg_ms' => null, 'checks' => $checks];
    }

    $avg = (float)$row['avg_ms'];
    $min = (float)$row['min_ms'];
    $degraded = $min > $threshold;

    if ($degraded && !$alert_already_sent) {
        return ['state' => 'degraded', 'avg_ms' => round($avg, 1), 'checks' => $checks];
    }
    if (!$degraded && $alert_already_sent) {
        // Zotavení se hlásí, jakmile se aspoň jedna kontrola vejde pod práh -
        // jinak by upozornění „trvá to dál" viselo i po návratu do normálu.
        return ['state' => 'recovered', 'avg_ms' => round($avg, 1), 'checks' => $checks];
    }
    return ['state' => 'ok', 'avg_ms' => round($avg, 1), 'checks' => $checks];
}

function bk_rollup_daily_uptime(PDO $pdo, int $days = 2): int {
    $days = max(1, min(400, $days));
    try {
        $stmt = $pdo->prepare("
            INSERT INTO uptime_daily (monitor_id, day, checks_total, checks_up, checks_down, checks_warning, avg_response_ms)
            SELECT monitor_id,
                   DATE(checked_at) AS day,
                   COUNT(*) AS checks_total,
                   SUM(status = 'up') AS checks_up,
                   SUM(status = 'down') AS checks_down,
                   SUM(status = 'warning') AS checks_warning,
                   AVG(NULLIF(response_time, 0)) AS avg_response_ms
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND status IN ('up', 'down', 'warning')
            GROUP BY monitor_id, DATE(checked_at)
            ON DUPLICATE KEY UPDATE
                checks_total = VALUES(checks_total),
                checks_up = VALUES(checks_up),
                checks_down = VALUES(checks_down),
                checks_warning = VALUES(checks_warning),
                avg_response_ms = VALUES(avg_response_ms)
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        // Bez tabulky (stará DB) se souhrn přeskočí; SLA pak stále funguje
        // nad syrovými logy v rámci jejich retence.
        error_log('[rollup] uptime_daily skipped: ' . $e->getMessage());
        return 0;
    }
}


function bk_uptime_30d(PDO $pdo, int $monitor_id, int $days = 30): ?float {
    try {
        $stmt = $pdo->prepare("
            SELECT SUM(status = 'up') AS up_count, COUNT(*) AS total
            FROM monitor_logs
            WHERE monitor_id = ?
              AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('up', 'down', 'warning')
        ");
        $stmt->execute([$monitor_id, $days]);
        $row = $stmt->fetch();
        if ($row && (int)$row['total'] > 0) {
            return ((int)$row['up_count'] / (int)$row['total']) * 100;
        }
    } catch (Throwable $e) {
        // Bez dat zůstává null.
    }
    return null;
}

function bk_iface_has_errors(array $iface): bool {
    $rx = isset($iface['rx_errors']) && is_numeric($iface['rx_errors']) ? (int)$iface['rx_errors'] : 0;
    $tx = isset($iface['tx_errors']) && is_numeric($iface['tx_errors']) ? (int)$iface['tx_errors'] : 0;
    return ($rx + $tx) > 0;
}

/**
 * Odstraní diakritiku, aby šel z názvu udělat slug do URL.
 *
 * iconv//TRANSLIT je na různých systémech nekonzistentní (a na některých
 * OpenWrt/Alpine buildech chybí úplně), takže se česká písmena mapují
 * explicitně - "Veřejný přehled" -> "verejny prehled".
 */
function bk_slug_ascii(string $text): string {
    $map = [
        'á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r',
        'š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z',
        'Á'=>'a','Č'=>'c','Ď'=>'d','É'=>'e','Ě'=>'e','Í'=>'i','Ň'=>'n','Ó'=>'o','Ř'=>'r',
        'Š'=>'s','Ť'=>'t','Ú'=>'u','Ů'=>'u','Ý'=>'y','Ž'=>'z',
        'ä'=>'a','ö'=>'o','ü'=>'u','ß'=>'ss','ł'=>'l','ą'=>'a','ę'=>'e','ś'=>'s','ć'=>'c','ź'=>'z','ż'=>'z',
    ];
    return strtolower(strtr($text, $map));
}

function bk_num($value, string $unit = '', int $decimals = 0): string {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '—';
    }
    return number_format((float)$value, $decimals, ',', ' ') . $unit;
}

function bk_format_duration($minutes) {
    if ($minutes < 60) return $minutes . ' min';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    if ($h < 24) return $h . ' h' . ($m > 0 ? ' ' . $m . ' min' : '');
    $d = floor($h / 24);
    $h = $h % 24;
    return $d . ' d ' . $h . ' h';
}

/**
 * Obohacení threshold tipu o důkazy - kvalita podle laťky uživatele
 * (2026-07-21): "CPU je nad 85 % po dobu 18 minut. Nejvíce zatěžuje:
 * hostapd (61 %). Load average: 2.8/2.4/2.1. Wi-Fi klienti: 27.
 * Doporučení: ...". Skládá se JEN ze skutečně dostupných dat - bez top
 * procesů se věta o viníkovi prostě vynechá.
 */
function bk_enrich_threshold_tip(
    array $details,
    string $metric,
    ?PDO $pdo = null,
    ?array $monitor = null,
    ?int $duration_secs = null
): string {
    $parts = [];
    $top_key = $metric === 'ram' ? 'top_ram_processes' : 'top_cpu_processes';
    $top = (!empty($details[$top_key]) && is_array($details[$top_key])) ? ($details[$top_key][0] ?? null) : null;

    // Viník za celou dobu, ne jen za poslední minutu.
    //
    // Poslední snímek říká, kdo zatěžuje stroj teď. U stavu, který trvá tři
    // hodiny, to ale nemusí být ten, kdo ho způsobil - a když agent zrovna
    // žebříček neposlal, není tu nikdo. Historie procesů (od 14. 8. 2026) umí
    // odpovědět za celé okno; když ji nemáme, zůstává snímek.
    if ($pdo instanceof PDO && $monitor !== null && $duration_secs !== null && $duration_secs > 0) {
        $from = time() - $duration_secs;
        $historic = bk_top_process_in_window($pdo, (int)$monitor['id'], $from, time(), $metric === 'ram' ? 'ram' : 'cpu');
        if ($historic !== null) {
            $top = [
                'name' => $historic['name'],
                'cpu' => $historic['cpu'],
                'ram_mb' => $historic['ram_mb'],
            ];
        }
    }

    $proc_name = $top ? strtolower((string)($top['name'] ?? '')) : '';

    if ($top && $proc_name !== '') {
        if ($metric === 'ram' && isset($top['ram_mb'])) {
            $parts[] = sprintf(t('kt_top_proc_ram'), $top['name'], number_format((float)$top['ram_mb'], 0, ',', ' '));
        } elseif (isset($top['cpu'])) {
            $parts[] = sprintf(t('kt_top_proc_cpu'), $top['name'], number_format((float)$top['cpu'], 0));
        }
    }
    if (isset($details['load1'], $details['load5'], $details['load15'])) {
        $parts[] = sprintf(t('kt_load_avg'), $details['load1'], $details['load5'], $details['load15']);
    }

    // Kontext podle viníka - jen když příslušná telemetrie existuje.
    $rec_key = 'kt_rec_generic';
    if (strpos($proc_name, 'hostapd') !== false) {
        if (isset($details['wifi_clients_count'])) {
            $parts[] = sprintf(t('kt_ctx_wifi_clients'), (int)$details['wifi_clients_count']);
        }
        // The generic advice says "check the channel" - with survey data the
        // tip can say HOW busy it is. Only radios that measured busy_pct
        // count; a driver without survey support must not produce a made-up 0.
        $busiest_pct = null;
        $busiest_radio = '';
        foreach ((array)($details['wifi_radios'] ?? []) as $ktr) {
            if (is_array($ktr) && isset($ktr['busy_pct']) && $ktr['busy_pct'] !== null) {
                if ($busiest_pct === null || (float)$ktr['busy_pct'] > $busiest_pct) {
                    $busiest_pct = (float)$ktr['busy_pct'];
                    $busiest_radio = (string)($ktr['radio'] ?? '');
                }
            }
        }
        if ($busiest_pct !== null && $busiest_radio !== '') {
            $parts[] = sprintf(t('kt_ctx_wifi_busy'), $busiest_radio, (int)round($busiest_pct));
        }
        $rec_key = 'kt_rec_wifi';
    } elseif (preg_match('/dnsmasq|kresd|unbound/', $proc_name)) {
        if (isset($details['dns_queries'])) {
            $parts[] = sprintf(t('kt_ctx_dns_queries'), (int)$details['dns_queries']);
        }
        $rec_key = 'kt_rec_dns';
    } elseif (strpos($proc_name, 'ts3server') !== false) {
        $ts = $details['teamspeak_servers'][0] ?? null;
        if (is_array($ts) && isset($ts['clients_online'])) {
            $parts[] = sprintf(t('kt_ctx_ts3_clients'), (int)$ts['clients_online']);
        }
        $rec_key = 'kt_rec_voice';
    } elseif (strpos($proc_name, 'java') !== false) {
        $rec_key = 'kt_rec_game';
    } elseif (strpos($proc_name, 'wireguard') !== false || $proc_name === 'wg') {
        if (!empty($details['wireguard_peers']) && is_array($details['wireguard_peers'])) {
            $parts[] = sprintf(t('kt_ctx_wg_peers'), count($details['wireguard_peers']));
        }
        $rec_key = 'kt_rec_vpn';
    }
    $parts[] = t($rec_key);

    return $parts ? ' ' . implode(' ', $parts) : '';
}

function bk_get_knowledge_tips($monitor, $details, $check_stages, $status, $enabled_metrics, $pdo = null) {
    $tips = [];
    $add = function ($severity, $tip_key, ...$args) use (&$tips) {
        $text = $args ? sprintf(t($tip_key), ...$args) : t($tip_key);
        $tips[] = [
            'icon' => $severity === 'critical' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle',
            'severity' => $severity,
            'text' => $text,
        ];
    };

    // Prahy tipů se řídí nastavením monitoru, ne konstantou v kódu.
    //
    // Dřív tu bylo natvrdo 80/50 pro CPU, 85/60 pro paměť a 90/70 pro disk,
    // zatímco pásma v grafu se kreslí podle monitors.cpu_threshold (výchozí 90)
    // a Executive Summary počítá tlak z téhož nastavení. Kdo si zvedl práh na
    // 95, dostával přesto kritický tip už při 81 % - tři různé názory na "moc
    // vysoko" v jednom produktu.
    //
    // Varovná úroveň je 15 bodů pod kritickou, stejně jako varovné pásmo
    // v grafu. Bez nastaveného prahu zůstávají původní hodnoty, aby se chování
    // u nikoho neposunulo bez jeho vědomí.
    $tip_threshold = static function ($configured, float $fallback_crit, float $fallback_warn): array {
        $crit = is_numeric($configured) && (float)$configured > 0 ? (float)$configured : $fallback_crit;
        $warn = $crit === $fallback_crit ? $fallback_warn : max(1.0, $crit - 15);
        return [$crit, $warn];
    };

    // --- VPS / agent (platí pro jakýkoli typ s propojeným agentem, stejně
    // jako render_vps_agent_details() sama není omezená na type=vps) ---
    if (is_array($details)) {
        if (isset($details['cpu'])) {
            $cpu = floatval($details['cpu']);
            [$cpu_crit, $cpu_warn] = $tip_threshold($monitor['cpu_threshold'] ?? null, 80.0, 50.0);
            if ($cpu > $cpu_crit) {
                $dur = ($pdo && $monitor) ? bk_metric_duration_above($pdo, $monitor['id'], 'cpu_usage', $cpu_crit) : null;
                $suffix = $dur ? ' (' . bk_format_duration($dur) . ')' : '';
                $add('critical', 'knowledge_tip_cpu_high');
                $tips[count($tips)-1]['text'] .= $suffix . bk_enrich_threshold_tip($details, 'cpu', $pdo, $monitor, $dur);
            } elseif ($cpu > $cpu_warn) $add('warn', 'knowledge_tip_cpu_high');
        }
        if (isset($details['ram'])) {
            $ram = floatval($details['ram']);
            [$ram_crit, $ram_warn] = $tip_threshold($monitor['ram_threshold'] ?? null, 85.0, 60.0);
            if ($ram > $ram_crit) {
                $dur = ($pdo && $monitor) ? bk_metric_duration_above($pdo, $monitor['id'], 'ram_usage', $ram_crit) : null;
                $suffix = $dur ? ' (' . bk_format_duration($dur) . ')' : '';
                $add('critical', 'knowledge_tip_ram_high');
                $tips[count($tips)-1]['text'] .= $suffix . bk_enrich_threshold_tip($details, 'ram', $pdo, $monitor, $dur);
            } elseif ($ram > $ram_warn) $add('warn', 'knowledge_tip_ram_high');
        }
        if (isset($details['hdd'])) {
            $hdd = floatval($details['hdd']);
            [$hdd_crit, $hdd_warn] = $tip_threshold($monitor['hdd_threshold'] ?? null, 90.0, 70.0);
            if ($hdd > $hdd_crit) {
                $dur = ($pdo && $monitor) ? bk_metric_duration_above($pdo, $monitor['id'], 'hdd_usage', $hdd_crit) : null;
                $suffix = $dur ? ' (' . bk_format_duration($dur) . ')' : '';
                $add('critical', 'knowledge_tip_hdd_high');
                $tips[count($tips)-1]['text'] .= $suffix;
            } elseif ($hdd > $hdd_warn) $add('warn', 'knowledge_tip_hdd_high');
        }
        if (isset($details['iowait']) && $details['iowait'] !== null) {
            if ($details['iowait'] > 20) $add('critical', 'knowledge_tip_iowait_high');
            elseif ($details['iowait'] > 10) $add('warn', 'knowledge_tip_iowait_high');
        }
        if (isset($details['inode_usage']) && $details['inode_usage'] !== null) {
            if ($details['inode_usage'] > 90) $add('critical', 'knowledge_tip_inode_high');
            elseif ($details['inode_usage'] > 70) $add('warn', 'knowledge_tip_inode_high');
        }
        if (isset($details['zombie_count']) && $details['zombie_count'] !== null && $details['zombie_count'] > 5) {
            $add('critical', 'knowledge_tip_zombie_high');
        }
        if (isset($details['btrfs_errors']) && $details['btrfs_errors'] !== null && $details['btrfs_errors'] > 0) {
            $add('critical', 'knowledge_tip_btrfs_errors');
        }
        if (isset($details['temperature']) && $details['temperature'] !== null) {
            if ($details['temperature'] > 80) $add('critical', 'knowledge_tip_temperature_high');
            elseif ($details['temperature'] > 65) $add('warn', 'knowledge_tip_temperature_high');
        }
        if (isset($details['smart']) && strpos((string)$details['smart'], 'WARNING') !== false) {
            $add('critical', 'knowledge_tip_smart_warning');
        }
        if (!empty($details['reboot_required'])) {
            $add('warn', 'knowledge_tip_reboot_required');
        }
        if ($monitor && !empty($monitor['monitored_processes'])) {
            $missing = $details['missing_processes'] ?? [];
            foreach ($missing as $proc) {
                $add('critical', 'knowledge_tip_process_missing', $proc);
            }
        }
        if (isset($details['tps_1m']) && $details['tps_1m'] !== null) {
            $tps1 = floatval($details['tps_1m']);
            if ($tps1 < 15.0) {
                $add('critical', 'knowledge_tip_mc_tps_low', number_format($tps1, 2));
            } elseif ($tps1 < 19.0) {
                $add('warn', 'knowledge_tip_mc_tps_low', number_format($tps1, 2));
            }
        }
    }

    $web_enabled = $enabled_metrics === null || in_array('check_pipeline', $enabled_metrics, true);
    $ssl_enabled = $enabled_metrics === null || in_array('ssl_card', $enabled_metrics, true);
    $health_score_enabled = $enabled_metrics === null || in_array('health_score', $enabled_metrics, true);

    // --- Web check pipeline (DNS/TCP/TLS/HTTP) ---
    if ($monitor && $monitor['type'] === 'web' && is_array($check_stages)) {
        if ($web_enabled) {
            $stage_tip_keys = [
                'dns' => 'knowledge_tip_web_dns_fail',
                'tcp' => 'knowledge_tip_web_tcp_fail',
                'tls' => 'knowledge_tip_web_tls_fail',
                'http' => 'knowledge_tip_web_http_fail',
            ];
            foreach ($stage_tip_keys as $stage => $tip_key) {
                if (isset($check_stages[$stage]) && empty($check_stages[$stage]['ok'])) {
                    $add('critical', $tip_key);
                }
            }
        }
        if ($ssl_enabled && isset($check_stages['tls']['cert']['days_remaining'])) {
            $days = (int)$check_stages['tls']['cert']['days_remaining'];
            if ($days < 14) $add('critical', 'knowledge_tip_ssl_expiring');
            elseif ($days < 30) $add('warn', 'knowledge_tip_ssl_expiring');
        }
    }

    // --- TeamSpeak Health Score areas - jen pokud je tabulka vůbec zobrazená ---
    if ($monitor && $monitor['type'] === 'teamspeak' && $health_score_enabled) {
        $ts3_area_tip_keys = [
            'availability' => 'knowledge_tip_ts3_availability',
            'process' => 'knowledge_tip_ts3_process',
            'serverquery' => 'knowledge_tip_ts3_serverquery',
            'ports' => 'knowledge_tip_ts3_ports',
            'vps' => 'knowledge_tip_ts3_vps',
            'clients' => 'knowledge_tip_ts3_clients',
            'version' => 'knowledge_tip_ts3_version',
        ];
        $areas = build_teamspeak_health_areas($monitor, $status, $check_stages, $details);
        foreach ($areas as $area) {
            if ($area['status'] === 'fail') {
                $add('critical', $ts3_area_tip_keys[$area['key']]);
            } elseif ($area['status'] === 'warn') {
                $add('warn', $ts3_area_tip_keys[$area['key']]);
            }
        }
    }

    // --- OpenWrt service-specific context tips ---
    if ($monitor && $monitor['type'] === 'openwrt' && is_array($details)) {
        $top_procs = $details['top_cpu_processes'] ?? [];
        $top_proc_name = !empty($top_procs) ? ($top_procs[0]['name'] ?? '') : '';
        // Bez namereneho CPU u top procesu se tip nesestavuje (viz podminky nize).
    $top_proc_cpu = !empty($top_procs) && isset($top_procs[0]['cpu']) ? (float)$top_procs[0]['cpu'] : null;

        // CPU high + hostapd -> WiFi client context
        if (isset($details['cpu']) && floatval($details['cpu']) > 70 && stripos($top_proc_name, 'hostapd') !== false) {
            $wifi_clients = 0;
            if (!empty($details['wifi_radios']) && is_array($details['wifi_radios'])) {
                // Scita se jen to, co radio opravdu nahlasilo.
        foreach ($details['wifi_radios'] as $r) { if (isset($r['clients'])) { $wifi_clients += (int)$r['clients']; } }
            }
            $add('warn', 'knowledge_tip_ow_hostapd_cpu', $top_proc_cpu, $wifi_clients);
        }
        // CPU high + wireguard -> WG throughput context
        if (isset($details['cpu']) && floatval($details['cpu']) > 70 && stripos($top_proc_name, 'wireguard') !== false) {
            $wg_rx = 0; $wg_tx = 0;
            if (!empty($details['wireguard_peers']) && is_array($details['wireguard_peers'])) {
                foreach ($details['wireguard_peers'] as $p) { if (isset($p['rx_bytes'])) { $wg_rx += (int)$p['rx_bytes']; } if (isset($p['tx_bytes'])) { $wg_tx += (int)$p['tx_bytes']; } }
            }
            $add('warn', 'knowledge_tip_ow_wg_cpu', $top_proc_cpu, round($wg_rx / 1048576, 1), round($wg_tx / 1048576, 1));
        }
        // CPU high + dnsmasq -> DNS query rate context
        if (isset($details['cpu']) && floatval($details['cpu']) > 70 && stripos($top_proc_name, 'dnsmasq') !== false) {
            $dns_q = $details['dns_queries'] ?? 0;
            $add('warn', 'knowledge_tip_ow_dns_cpu', $top_proc_cpu, $dns_q);
        }
    }

    return $tips;
}

/**
 * Vykreslí panel s Knowledge tipy (viz bk_get_knowledge_tips()). Prázdné pole
 * = prázdný řetězec, žádný panel se nezobrazí.
 */
function render_knowledge_panel(array $tips) {
    if (empty($tips)) return '';
    ob_start();
    ?>
    <div class="knowledge-panel-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
        <div class="detail-section-title"><?php echo htmlspecialchars(t('knowledge_panel_heading')); ?></div>
        <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.6rem;">
            <?php foreach ($tips as $tip): ?>
                <?php $color = $tip['severity'] === 'critical' ? 'var(--color-red)' : 'var(--color-yellow)'; ?>
                <div style="display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.8rem; line-height: 1.4; color: var(--text-secondary);">
                    <i class="fas <?php echo htmlspecialchars($tip['icon']); ?>" style="color: <?php echo $color; ?>; margin-top: 0.15rem; flex-shrink: 0;"></i>
                    <span><?php echo htmlspecialchars($tip['text']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Sdílená matematika pro Insights (Level 1 Forecasting) - rozdělí seřazenou
 * (podle checked_at ASC) řadu vzorků na starší/novější polovinu, porovná
 * průměry a vrátí rychlost změny za den. Deterministické, žádná AI - stejný
 * princip pro disk/RAM i pro latenci, proto jedna sdílená funkce.
 *
 * @param array $rows Řádky s klíči $time_key (datum) a $value_key (číslo)
 * @return array{avg_older: float, avg_newer: float, latest: float, rate_per_day: float}|null
 *         null, pokud je dat málo na to, aby extrapolace dávala smysl.
 */
function bk_half_window_rate(array $rows, string $value_key, string $time_key = 'checked_at') {
    $rows = array_values(array_filter($rows, fn($r) => isset($r[$value_key]) && $r[$value_key] !== null));
    if (count($rows) < 5) {
        return null;
    }
    $first_ts = strtotime($rows[0][$time_key]);
    $last_ts = strtotime($rows[count($rows) - 1][$time_key]);
    if ($first_ts === false || $last_ts === false || ($last_ts - $first_ts) < 4 * 86400) {
        return null; // Méně než 4 dny rozestupu - příliš krátké okno na spolehlivou extrapolaci
    }

    $mid = intdiv(count($rows), 2);
    $older = array_slice($rows, 0, $mid);
    $newer = array_slice($rows, $mid);

    $avg_older = array_sum(array_column($older, $value_key)) / count($older);
    $avg_newer = array_sum(array_column($newer, $value_key)) / count($newer);
    $mid_ts_older = strtotime($older[intdiv(count($older), 2)][$time_key]);
    $mid_ts_newer = strtotime($newer[intdiv(count($newer), 2)][$time_key]);
    $days_between = ($mid_ts_newer - $mid_ts_older) / 86400;
    if ($days_between <= 0) {
        return null;
    }

    return [
        'avg_older' => $avg_older,
        'avg_newer' => $avg_newer,
        'latest' => (float)$rows[count($rows) - 1][$value_key],
        'rate_per_day' => ($avg_newer - $avg_older) / $days_between,
    ];
}

/**
 * Insights v1 (Level 1 Forecasting) - trendová matematika nad historií, kterou
 * už sbíráme (vps_metrics/monitor_logs, oboje 30denní retence - viz cron.php).
 * Záměrně nezahrnuje SSL expiraci (tu už pokrývá knowledge_tip_ssl_expiring
 * v bk_get_knowledge_tips() - duplicitní hlášení stejné věci by jen otravovalo)
 * ani sloučení s Knowledge panelem (viz plán - samostatné rozhodnutí až bude
 * víc typů insightů hotovo).
 */
function bk_get_forecast_insights($pdo, $monitor) {
    $insights = [];
    $monitor_id = $monitor['id'];

    // --- Disk / RAM growth forecast ---
    //
    // Denní agregace místo syrových měření: jde o trend růstu, ne o rozptyl,
    // takže denní průměr je pro extrapolaci lepší vstup (míň šumu) a hlavně
    // je to 14 řádků místo dvaceti tisíc. Sloupce se jmenují stejně jako
    // dřív, aby bk_half_window_rate() zůstala beze změny.
    $stmt = $pdo->prepare("
        SELECT day AS checked_at,
               MAX(CASE WHEN metric_key = 'hdd' THEN avg_val END) AS hdd_usage,
               MAX(CASE WHEN metric_key = 'ram' THEN avg_val END) AS ram_usage
        FROM metrics_daily
        WHERE monitor_id = ? AND metric_key IN ('hdd', 'ram')
          AND day >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY day
        ORDER BY day ASC
    ");
    $stmt->execute([$monitor_id]);
    $metrics_rows = $stmt->fetchAll();

    // Záloha pro případ, že denní agregace ještě neproběhla (čerstvá instalace,
    // první půlhodina po nasazení) nebo se zasekla. Bez ní by předpověď tiše
    // zmizela a vypadalo by to, že disk neroste - což je horší než pomalý dotaz.
    if (count($metrics_rows) < 5) {
        $stmt_raw = $pdo->prepare("
            SELECT checked_at, hdd_usage, ram_usage
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            ORDER BY checked_at ASC
        ");
        $stmt_raw->execute([$monitor_id]);
        $metrics_rows = $stmt_raw->fetchAll();
    }

    foreach (['hdd_usage' => 'insight_forecast_disk', 'ram_usage' => 'insight_forecast_ram'] as $metric_key => $tip_key) {
        $rate_info = bk_half_window_rate($metrics_rows, $metric_key);
        if ($rate_info === null || $rate_info['rate_per_day'] <= 0.01) {
            continue; // Ploché nebo klesající - není co predikovat
        }
        $days_until_full = (100 - $rate_info['latest']) / $rate_info['rate_per_day'];
        if ($days_until_full <= 0 || $days_until_full > 90) {
            continue; // Už plné (nesmysl), nebo za hranicí toho, co stojí za varování
        }
        $insights[] = [
            'type' => 'forecast',
            'icon' => 'fa-hourglass-half',
            'color' => 'var(--color-yellow)',
            'text' => sprintf(t($tip_key), number_format($rate_info['rate_per_day'], 2, ',', ' '), (int)round($days_until_full)),
            'detail' => sprintf(t('insight_forecast_basis'), number_format($rate_info['latest'], 1, ',', ' ')),
        ];
    }

    // --- Latency trend ---
    // Denní průměr odezvy už se ukládá kvůli dlouhodobému SLA, takže se
    // sem nemusí tahat čtrnáct dní jednotlivých kontrol.
    $stmt2 = $pdo->prepare("
        SELECT day AS checked_at, avg_response_ms AS response_time
        FROM uptime_daily
        WHERE monitor_id = ? AND avg_response_ms IS NOT NULL
          AND day >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        ORDER BY day ASC
    ");
    $stmt2->execute([$monitor_id]);
    $latency_rows = $stmt2->fetchAll();

    if (count($latency_rows) < 5) {
        $stmt2_raw = $pdo->prepare("
            SELECT checked_at, response_time
            FROM monitor_logs
            WHERE monitor_id = ? AND status = 'up' AND response_time IS NOT NULL
              AND checked_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
            ORDER BY checked_at ASC
        ");
        $stmt2_raw->execute([$monitor_id]);
        $latency_rows = $stmt2_raw->fetchAll();
    }

    $lat_rate = bk_half_window_rate($latency_rows, 'response_time');
    if ($lat_rate !== null && $lat_rate['avg_older'] > 0) {
        $pct_change = (($lat_rate['avg_newer'] - $lat_rate['avg_older']) / $lat_rate['avg_older']) * 100;
        if (abs($pct_change) >= 15) {
            $is_good = $pct_change < 0; // Nižší latence = lepší
            $insights[] = [
                'type' => 'trend',
                'icon' => $pct_change > 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down',
                'color' => $is_good ? 'var(--color-green)' : 'var(--color-red)',
                'text' => sprintf(t($pct_change > 0 ? 'insight_trend_latency_up' : 'insight_trend_latency_down'), number_format(abs($pct_change), 0)),
                'detail' => sprintf(t('insight_trend_latency_basis'), (int)round($lat_rate['avg_older']), (int)round($lat_rate['avg_newer'])),
            ];
        }
    }

    return $insights;
}

/**
 * Vykreslí panel s Insights (viz bk_get_forecast_insights()). Stejný tvar
 * jako render_knowledge_panel() - prázdné pole = prázdný řetězec.
 */
function render_insights_panel(array $insights) {
    if (empty($insights)) return '';
    ob_start();
    ?>
    <div class="insights-panel-section" style="margin-top: 1.5rem; width: 100%; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 1.25rem;">
        <div class="detail-section-title"><?php echo htmlspecialchars(t('insights_panel_heading')); ?></div>
        <div style="display: flex; flex-direction: column; gap: 0.65rem; margin-top: 0.6rem;">
            <?php foreach ($insights as $insight): ?>
                <div style="display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.8rem; line-height: 1.4;">
                    <i class="fas <?php echo htmlspecialchars($insight['icon']); ?>" style="color: <?php echo $insight['color']; ?>; margin-top: 0.15rem; flex-shrink: 0;"></i>
                    <div>
                        <div style="color: var(--text-secondary);"><?php echo htmlspecialchars($insight['text']); ?></div>
                        <div style="color: var(--text-muted); font-size: 0.72rem; margin-top: 0.1rem;"><?php echo htmlspecialchars($insight['detail']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Insights v2 (Level 2 Anomaly Detection) - sdílená matematika. Na rozdíl od
 * Knowledge tipů (pevný práh stejný pro všechny monitory) se tu zjišťuje, jestli
 * je aktuální hodnota neobvyklá VZHLEDEM K VLASTNÍ historii tohoto konkrétního
 * monitoru - server, který běžně jede na 85 % CPU, tu nikdy nenaskočí, i kdyby
 * pevný Knowledge práh (>80 %) hlásil "vysoké" pořád.
 *
 * @param array $baseline_values Číselné hodnoty z "klidového" období (bez posledních pár dní)
 * @param float $current Aktuální (nejnovější) hodnota, mimo baseline okno
 * @param float $min_sigma Podlaha pro efektivní sigma - brání falešným poplachům
 *        na monitoru s podezřele plochou historií (sigma blízko 0)
 * @param float $sigma_multiplier Kolik efektivních sigma od průměru už je "neobvyklé"
 * @return array{low: float, high: float, mean: float, current: float}|null
 *         null = nedost dat, nebo hodnota je v normálu
 */
/**
 * Totéž co bk_compute_baseline_anomaly(), ale ze statistik spočítaných v SQL.
 *
 * Původní verze si kvůli průměru a odchylce tahala do PHP celých 30 dní
 * měření - u agenta hlásícího každou minutu přes 43 000 řádků na monitor.
 * Na status stránce se to dělo pro každý monitor zvlášť a byla to největší
 * část z těch patnácti sekund, co se stránka skládala.
 *
 * AVG a STDDEV_POP počítají přesně totéž, co se dřív počítalo v cyklu
 * (populační rozptyl, dělí se n), takže se práh nikam neposunul.
 *
 * @param ?float $mean  Průměr základny; NULL = nedostatek dat
 * @param ?float $sigma Populační směrodatná odchylka
 * @param ?int   $count Počet hodnot, ze kterých statistika vznikla.
 *                      NULL = dotaz nic nevrátil, což není totéž co nula vzorků.
 */
function bk_baseline_anomaly_from_stats(?float $mean, ?float $sigma, ?int $count, float $current, float $min_sigma, float $sigma_multiplier = 2.5) {
    if ($mean === null || $sigma === null || $count === null || $count < 20) {
        return null; // Málo historie na to, aby průměr/sigma dávaly smysl
    }

    $effective_sigma = max($sigma, $min_sigma);
    if (abs($current - $mean) <= $sigma_multiplier * $effective_sigma) {
        return null; // V normálu pro tenhle konkrétní monitor
    }

    return [
        'low' => $mean - $sigma_multiplier * $effective_sigma,
        'high' => $mean + $sigma_multiplier * $effective_sigma,
        'mean' => $mean,
        'current' => $current,
    ];
}

function bk_compute_baseline_anomaly(array $baseline_values, float $current, float $min_sigma, float $sigma_multiplier = 2.5) {
    $baseline_values = array_values(array_filter($baseline_values, fn($v) => $v !== null));
    $n = count($baseline_values);
    if ($n < 20) {
        return null; // Málo historie na to, aby průměr/sigma dávaly smysl
    }

    $mean = array_sum($baseline_values) / $n;
    $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $baseline_values)) / $n;
    $sigma = sqrt($variance);
    $effective_sigma = max($sigma, $min_sigma);

    if (abs($current - $mean) <= $sigma_multiplier * $effective_sigma) {
        return null; // V normálu pro tenhle konkrétní monitor
    }

    return [
        'low' => $mean - $sigma_multiplier * $effective_sigma,
        'high' => $mean + $sigma_multiplier * $effective_sigma,
        'mean' => $mean,
        'current' => $current,
    ];
}

/**
 * Network Insights - rolling-window analýza síťových dat pro OpenWrt/VPS monitory.
 * Vrací pole insightů ve stejném formátu jako bk_get_anomaly_insights().
 */
function bk_get_network_insights($pdo, $monitor, $details) {
    $insights = [];
    if (!is_array($details)) return $insights;
    $monitor_id = $monitor['id'];

    // WAN reconnect frequency (7d rolling window)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM monitor_events WHERE monitor_id = ? AND event_type = 'status_changed_down' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$monitor_id]);
        $row = $stmt->fetch();
        $down_count = (int)($row['cnt'] ?? 0);
        if ($down_count >= 5) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-rotate',
                'color' => 'var(--color-red)',
                'text' => sprintf(t('net_insight_wan_reconnects'), $down_count),
                'detail' => t('net_insight_wan_reconnects_detail'),
            ];
        } elseif ($down_count >= 2) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-rotate',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('net_insight_wan_reconnects'), $down_count),
                'detail' => t('net_insight_wan_reconnects_detail'),
            ];
        }
    } catch (PDOException $e) {}

    // Conntrack table pressure
    if (isset($details['conntrack_pct']) && $details['conntrack_pct'] !== null) {
        $ct = (float)$details['conntrack_pct'];
        if ($ct > 90) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-table-list',
                'color' => 'var(--color-red)',
                'text' => sprintf(t('net_insight_conntrack_high'), number_format($ct, 1)),
                'detail' => t('net_insight_conntrack_detail'),
            ];
        } elseif ($ct > 80) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-table-list',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('net_insight_conntrack_high'), number_format($ct, 1)),
                'detail' => t('net_insight_conntrack_detail'),
            ];
        }
    }

    // WiFi interference (noise floor)
    if (!empty($details['wifi_radios']) && is_array($details['wifi_radios'])) {
        foreach ($details['wifi_radios'] as $radio) {
            $noise = (int)($radio['noise'] ?? -95);
            if ($noise < 0 && $noise > -70) {
                $insights[] = [
                    'type' => 'network',
                    'icon' => 'fa-wifi',
                    'color' => 'var(--color-orange, #f39c12)',
                    'text' => sprintf(t('net_insight_wifi_noise'), $radio['ssid'] ?? $radio['radio'] ?? '?', $noise),
                    'detail' => t('net_insight_wifi_noise_detail'),
                ];
                break; // Jeden insight stačí
            }
        }
    }

    // WireGuard stale peer
    if (!empty($details['wireguard_peers']) && is_array($details['wireguard_peers'])) {
        $now = time();
        foreach ($details['wireguard_peers'] as $peer) {
            $hs = (int)($peer['latest_handshake'] ?? 0);
            if ($hs > 0 && ($now - $hs) > 172800) { // 48h
                $insights[] = [
                    'type' => 'network',
                    'icon' => 'fa-shield-halved',
                    'color' => 'var(--color-orange, #f39c12)',
                    'text' => sprintf(t('net_insight_wg_stale'), $peer['public_key'] ?? '?', round(($now - $hs) / 3600)),
                    'detail' => t('net_insight_wg_stale_detail'),
                ];
                break;
            }
        }
    }

    // DNS cache efficiency
    if (isset($details['dns_queries']) && $details['dns_queries'] !== null && $details['dns_queries'] > 0) {
        $hits = (int)($details['dns_cache_hits'] ?? 0);
        $total = (int)$details['dns_queries'];
        $hit_rate = $total > 0 ? ($hits / $total) * 100 : 0;
        if ($hit_rate < 50 && $total > 100) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-magnifying-glass',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('net_insight_dns_cache_low'), number_format($hit_rate, 0)),
                'detail' => t('net_insight_dns_cache_detail'),
            ];
        }
    }

    // Vytížení Wi-Fi kanálu (busy/active z iwinfo survey, sbíráno od v1.5.4)
    if (!empty($details['wifi_radios']) && is_array($details['wifi_radios'])) {
        foreach ($details['wifi_radios'] as $radio) {
            $busy = isset($radio['busy_pct']) && $radio['busy_pct'] !== null ? (float)$radio['busy_pct'] : null;
            if ($busy !== null && $busy >= 65) {
                $insights[] = [
                    'type' => 'network',
                    'icon' => 'fa-wifi',
                    'color' => $busy >= 85 ? 'var(--color-red)' : 'var(--color-orange, #f39c12)',
                    'text' => sprintf(t('net_insight_channel_busy'), (string)($radio['ssid'] ?? $radio['radio'] ?? '?'), (int)($radio['channel'] ?? 0), number_format($busy, 0)),
                    'detail' => t('net_insight_channel_busy_detail'),
                ];
                break;
            }
        }
    }

    // OOM killer zásahy (od startu systému)
    if (isset($details['oom_kills']) && (int)$details['oom_kills'] > 0) {
        $insights[] = [
            'type' => 'network',
            'icon' => 'fa-skull-crossbones',
            'color' => 'var(--color-red)',
            'text' => sprintf(t('net_insight_oom'), (int)$details['oom_kills']),
            'detail' => t('net_insight_oom_detail'),
        ];
    }

    // Pomalé DNS odpovědi (měřený dotaz, sbíráno od v1.5.4/1.7.2)
    if (isset($details['dns_latency_ms']) && $details['dns_latency_ms'] !== null) {
        $dl = (float)$details['dns_latency_ms'];
        if ($dl >= 150) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-hourglass-half',
                'color' => $dl >= 400 ? 'var(--color-red)' : 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('net_insight_dns_slow'), number_format($dl, 0)),
                'detail' => t('net_insight_dns_slow_detail'),
            ];
        }
    }

    // Chybovost v systémovém logu
    if (isset($details['log_errors_24h']) && (int)$details['log_errors_24h'] >= 50) {
        $le = (int)$details['log_errors_24h'];
        $insights[] = [
            'type' => 'network',
            'icon' => 'fa-file-lines',
            'color' => $le >= 200 ? 'var(--color-red)' : 'var(--color-orange, #f39c12)',
            'text' => sprintf(t('net_insight_log_errors'), $le),
            'detail' => t('net_insight_log_errors_detail'),
        ];
    }

    // Skutečné WAN reconnecty (event 'wan_reconnected' loguje agent_api
    // z delty čítače agenta - přesnější než pády celého monitoru výše)
    try {
        $stmt_wr = $pdo->prepare("SELECT COUNT(*) FROM monitor_events WHERE monitor_id = ? AND event_type = 'wan_reconnected' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt_wr->execute([$monitor_id]);
        $wr = (int)$stmt_wr->fetchColumn();
        if ($wr >= 2) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-plug-circle-xmark',
                'color' => $wr >= 5 ? 'var(--color-red)' : 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('net_insight_wan_flaps'), $wr),
                'detail' => t('net_insight_wan_flaps_detail'),
            ];
        }
    } catch (PDOException $e) {}

    // Nestabilní IPv6 prefix (event loguje agent_api při změně /64)
    try {
        $stmt_p6 = $pdo->prepare("SELECT COUNT(*) FROM monitor_events WHERE monitor_id = ? AND event_type = 'ipv6_prefix_changed' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt_p6->execute([$monitor_id]);
        $p6 = (int)$stmt_p6->fetchColumn();
        if ($p6 >= 3) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-shuffle',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('net_insight_ipv6_flapping'), $p6),
                'detail' => t('net_insight_ipv6_flapping_detail'),
            ];
        }
    } catch (PDOException $e) {}

    // UPS na baterii (NUT) - "OB" = on battery, "LB" = low battery
    if (!empty($details['ups_status'])) {
        $ups = (string)$details['ups_status'];
        if (strpos($ups, 'OB') !== false || strpos($ups, 'LB') !== false) {
            $bat = isset($details['ups_battery_pct']) ? (int)$details['ups_battery_pct'] : null;
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-battery-half',
                'color' => 'var(--color-red)',
                'text' => $bat !== null ? sprintf(t('net_insight_ups_battery'), $bat) : t('net_insight_ups_battery_nopct'),
                'detail' => t('net_insight_ups_battery_detail'),
            ];
        }
    }

    // LTE signal quality
    if (isset($details['lte_rsrp']) && $details['lte_rsrp'] !== null) {
        $rsrp = (float)$details['lte_rsrp'];
        if ($rsrp < -120) {
            $insights[] = [
                'type' => 'network',
                'icon' => 'fa-signal',
                'color' => 'var(--color-red)',
                'text' => sprintf(t('net_insight_lte_weak'), $rsrp),
                'detail' => t('net_insight_lte_weak_detail'),
            ];
        }
    }

    return $insights;
}

/**
 * Insights v2 (Level 2 Anomaly Detection) - tři pravidla (CPU/RAM/latence),
 * všechna nad bk_compute_baseline_anomaly(). Baseline okno je 3-30 dní zpět
 * (mezera před "teď", aby trvající anomálie nezkreslila vlastní baseline),
 * aktuální hodnota je poslední skutečný vzorek mimo toto okno.
 */
function bk_get_anomaly_insights($pdo, $monitor) {
    $insights = [];
    $monitor_id = $monitor['id'];

    // --- CPU / RAM anomálie (vps_metrics) ---
    //
    // Průměr a odchylku počítá databáze. Dřív se sem načítalo celých 30 dní
    // měření - u agenta hlásícího každou minutu přes 43 000 řádků na monitor,
    // a to pro každý monitor na stránce zvlášť. STDDEV_POP je populační
    // odchylka, tedy přesně to, co se dřív počítalo v cyklu; práh se neposunul.
    $stmt = $pdo->prepare("
        SELECT COUNT(cpu_usage) AS cpu_n, AVG(cpu_usage) AS cpu_mean, STDDEV_POP(cpu_usage) AS cpu_sd,
               COUNT(ram_usage) AS ram_n, AVG(ram_usage) AS ram_mean, STDDEV_POP(ram_usage) AS ram_sd
        FROM vps_metrics
        WHERE monitor_id = ?
          AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND checked_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
    ");
    $stmt->execute([$monitor_id]);
    $baseline = $stmt->fetch() ?: [];

    // Poslední měření za poslední tři dny - to je hodnota, která se proti
    // základně porovnává.
    $stmt_last = $pdo->prepare("
        SELECT cpu_usage, ram_usage
        FROM vps_metrics
        WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt_last->execute([$monitor_id]);
    $latest = $stmt_last->fetch();

    if ($latest) {
        $to_float = fn($v) => ($v === null || $v === '') ? null : (float)$v;

        // Nezměřená hodnota se dřív přetypovala na 0.0 a proti základně
        // vyšla jako anomálie - hlásili jsme "CPU je 0 %" u agenta, který
        // CPU vůbec neposlal.
        $cpu_current = $to_float($latest['cpu_usage']);
        $ram_current = $to_float($latest['ram_usage']);

        $cpu_anomaly = $cpu_current === null ? null : bk_baseline_anomaly_from_stats(
            $to_float($baseline['cpu_mean'] ?? null),
            $to_float($baseline['cpu_sd'] ?? null),
            isset($baseline['cpu_n']) ? (int)$baseline['cpu_n'] : null,
            $cpu_current,
            3.0
        );
        if ($cpu_anomaly !== null) {
            $insights[] = [
                'type' => 'anomaly',
                'icon' => 'fa-triangle-exclamation',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('insight_anomaly_cpu'), number_format($cpu_anomaly['current'], 1, ',', ' ')),
                'detail' => sprintf(t('insight_anomaly_range'), number_format(max(0, $cpu_anomaly['low']), 0), number_format(min(100, $cpu_anomaly['high']), 0)),
            ];
        }

        $ram_anomaly = $ram_current === null ? null : bk_baseline_anomaly_from_stats(
            $to_float($baseline['ram_mean'] ?? null),
            $to_float($baseline['ram_sd'] ?? null),
            isset($baseline['ram_n']) ? (int)$baseline['ram_n'] : null,
            $ram_current,
            3.0
        );
        if ($ram_anomaly !== null) {
            $insights[] = [
                'type' => 'anomaly',
                'icon' => 'fa-triangle-exclamation',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('insight_anomaly_ram'), number_format($ram_anomaly['current'], 1, ',', ' ')),
                'detail' => sprintf(t('insight_anomaly_range'), number_format(max(0, $ram_anomaly['low']), 0), number_format(min(100, $ram_anomaly['high']), 0)),
            ];
        }
    }

    // --- Latence anomálie (monitor_logs) ---
    // Stejný důvod jako u CPU/RAM výše: statistika se počítá v databázi,
    // ne přenosem třiceti dnů kontrol do PHP.
    $stmt2 = $pdo->prepare("
        SELECT COUNT(response_time) AS lat_n, AVG(response_time) AS lat_mean, STDDEV_POP(response_time) AS lat_sd
        FROM monitor_logs
        WHERE monitor_id = ? AND status = 'up' AND response_time IS NOT NULL
          AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND checked_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
    ");
    $stmt2->execute([$monitor_id]);
    $lat_baseline = $stmt2->fetch() ?: [];

    $stmt_last_lat = $pdo->prepare("
        SELECT response_time
        FROM monitor_logs
        WHERE monitor_id = ? AND status = 'up' AND response_time IS NOT NULL
          AND checked_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt_last_lat->execute([$monitor_id]);
    $latest_lat = $stmt_last_lat->fetch();

    if ($latest_lat) {
        $lat_mean_for_floor = isset($lat_baseline['lat_mean']) ? (float)$lat_baseline['lat_mean'] : 0;
        $latency_min_sigma = max(5.0, $lat_mean_for_floor * 0.10);

        $lat_anomaly = bk_baseline_anomaly_from_stats(
            isset($lat_baseline['lat_mean']) ? (float)$lat_baseline['lat_mean'] : null,
            isset($lat_baseline['lat_sd']) ? (float)$lat_baseline['lat_sd'] : null,
            isset($lat_baseline['lat_n']) ? (int)$lat_baseline['lat_n'] : null,
            (float)$latest_lat['response_time'],
            $latency_min_sigma
        );
        if ($lat_anomaly !== null) {
            $insights[] = [
                'type' => 'anomaly',
                'icon' => 'fa-triangle-exclamation',
                'color' => 'var(--color-orange, #f39c12)',
                'text' => sprintf(t('insight_anomaly_latency'), (int)round($lat_anomaly['current'])),
                'detail' => sprintf(t('insight_anomaly_range_ms'), (int)round(max(0, $lat_anomaly['low'])), (int)round($lat_anomaly['high'])),
            ];
        }
    }

    return $insights;
}



/**
 * Sloučí monitor_events (přidání/odebrání, DNS/cert/schéma, agent
 * connect/disconnect, limity, config změny...), agent_actions (Remote
 * Actions historie) a stavové přechody odvozené z monitor_logs do jednoho
 * chronologického seznamu (nejnovější první). Čistě datová funkce - den/den
 * skupinové popisky ("Dnes"/"Včera") a i18n štítky řeší až šablona, aby
 * zůstala testovatelná bez závislosti na t()/aktuálním datu.
 * @return array<int, array{event_type: string, description: ?string, ts: string}>
 */
function bk_get_monitor_timeline($pdo, $monitor_id, $days = 30) {
    $timeline = [];

    try {
        $stmt = $pdo->prepare("SELECT event_type, description, occurred_at FROM monitor_events WHERE monitor_id = ? AND occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY occurred_at DESC");
        $stmt->execute([$monitor_id, $days]);
        foreach ($stmt->fetchAll() as $row) {
            $timeline[] = [
                'event_type' => $row['event_type'],
                'description' => $row['description'],
                'ts' => $row['occurred_at'],
            ];
        }
    } catch (PDOException $e) {
        // Tabulka/sloupec chybí (stará instalace před migrací) - timeline bude jen částečná
    }

    try {
        $stmt = $pdo->prepare("SELECT action_type, status, created_at, result_message FROM agent_actions WHERE monitor_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY created_at DESC");
        $stmt->execute([$monitor_id, $days]);
        foreach ($stmt->fetchAll() as $row) {
            $desc = $row['action_type'] . ' (' . $row['status'] . ')';
            if (!empty($row['result_message'])) {
                $desc .= ' - ' . $row['result_message'];
            }
            $timeline[] = [
                'event_type' => 'remote_action',
                'description' => $desc,
                'ts' => $row['created_at'],
            ];
        }
    } catch (PDOException $e) {
    }

    // Změny stavu hledá databáze, ne PHP.
    //
    // Dřív se sem načetlo 30 dní VŠECH kontrol - u monitoru kontrolovaného
    // každou minutu přes 43 000 řádků - a v cyklu se z nich vybralo těch
    // pár, kde se stav změnil. Na status stránce se to dělo pro každý
    // monitor zvlášť a byl to největší jednotlivý přenos na celé stránce.
    //
    // LAG() vrátí rovnou jen zlomy. Starší MariaDB/MySQL bez okenních funkcí
    // spadne na výjimku a použije se původní cesta - proto ten catch.
    $rows = [];
    $used_window_fn = false;
    try {
        $stmt = $pdo->prepare("
            SELECT status, checked_at, error_message
            FROM (
                SELECT status, checked_at, error_message,
                       LAG(status) OVER (ORDER BY checked_at) AS prev_status
                FROM monitor_logs
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ) zmeny
            WHERE prev_status IS NOT NULL AND status <> prev_status
            ORDER BY checked_at ASC
        ");
        $stmt->execute([$monitor_id, $days]);
        $rows = $stmt->fetchAll();
        $used_window_fn = true;
    } catch (PDOException $e) {
        error_log('[timeline] Okenní funkce nedostupná, používám původní cestu: ' . $e->getMessage());
    }

    try {
        if (!$used_window_fn) {
            $stmt = $pdo->prepare("SELECT status, checked_at, error_message FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY checked_at ASC");
            $stmt->execute([$monitor_id, $days]);
            $rows = $stmt->fetchAll();
        }

        $prev_status = null;
        foreach ($rows as $row) {
            // S okenní funkcí jsou v $rows rovnou jen zlomy, takže se podmínka
            // vyhodnotí vždy; bez ní se prochází vše jako dřív.
            if ($used_window_fn || ($prev_status !== null && $row['status'] !== $prev_status)) {
                $desc = null;
                if (in_array($row['status'], ['down', 'warning'], true) && !empty($row['error_message'])) {
                    $desc = mb_substr($row['error_message'], 0, 120);
                }
                $event_type = match($row['status']) {
                    'down' => 'status_changed_down',
                    'warning' => 'status_changed_warning',
                    'maintenance' => 'status_changed_maintenance',
                    default => 'status_changed_up',
                };
                $timeline[] = [
                    'event_type' => $event_type,
                    'description' => $desc,
                    'ts' => $row['checked_at'],
                ];
            }
            $prev_status = $row['status'];
        }
    } catch (PDOException $e) {
    }

    usort($timeline, function ($a, $b) {
        return strtotime($b['ts']) <=> strtotime($a['ts']);
    });

    return $timeline;
}

/**
 * Asset-level Timeline - sloučí události ze všech monitorů patřících pod asset.
 * Každá událost nese navíc monitor_name pro identifikaci zdroje.
 */
function bk_get_asset_timeline($pdo, $asset_id, $days = 30) {
    $timeline = [];

    // Získej všechny monitory assetu
    $stmt = $pdo->prepare("SELECT id, name FROM monitors WHERE asset_id = ?");
    $stmt->execute([$asset_id]);
    $monitors = $stmt->fetchAll();

    if (empty($monitors)) {
        return $timeline;
    }

    $monitor_ids = array_column($monitors, 'id');
    $monitor_names = [];
    foreach ($monitors as $m) {
        $monitor_names[$m['id']] = $m['name'];
    }
    $placeholders = implode(',', array_fill(0, count($monitor_ids), '?'));

    // Monitor events
    try {
        $stmt = $pdo->prepare("SELECT monitor_id, event_type, description, occurred_at FROM monitor_events WHERE monitor_id IN ($placeholders) AND occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY occurred_at DESC");
        $stmt->execute(array_merge($monitor_ids, [$days]));
        foreach ($stmt->fetchAll() as $row) {
            $timeline[] = [
                'event_type' => $row['event_type'],
                'description' => $row['description'],
                'ts' => $row['occurred_at'],
                'monitor_name' => $monitor_names[$row['monitor_id']] ?? '?',
            ];
        }
    } catch (PDOException $e) {
    }

    // Remote actions
    try {
        $stmt = $pdo->prepare("SELECT monitor_id, action_type, status, created_at, result_message FROM agent_actions WHERE monitor_id IN ($placeholders) AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY created_at DESC");
        $stmt->execute(array_merge($monitor_ids, [$days]));
        foreach ($stmt->fetchAll() as $row) {
            $desc = $row['action_type'] . ' (' . $row['status'] . ')';
            if (!empty($row['result_message'])) {
                $desc .= ' - ' . $row['result_message'];
            }
            $timeline[] = [
                'event_type' => 'remote_action',
                'description' => $desc,
                'ts' => $row['created_at'],
                'monitor_name' => $monitor_names[$row['monitor_id']] ?? '?',
            ];
        }
    } catch (PDOException $e) {
    }

    // Status changes
    try {
        $stmt = $pdo->prepare("SELECT monitor_id, status, checked_at FROM monitor_logs WHERE monitor_id IN ($placeholders) AND checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY checked_at ASC");
        $stmt->execute(array_merge($monitor_ids, [$days]));
        $prev_status = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = $row['monitor_id'];
            if (isset($prev_status[$mid]) && $row['status'] !== $prev_status[$mid]) {
                $event_type = match($row['status']) {
                    'down' => 'status_changed_down',
                    'warning' => 'status_changed_warning',
                    'maintenance' => 'status_changed_maintenance',
                    default => 'status_changed_up',
                };
                $timeline[] = [
                    'event_type' => $event_type,
                    'description' => null,
                    'ts' => $row['checked_at'],
                    'monitor_name' => $monitor_names[$mid] ?? '?',
                ];
            }
            $prev_status[$mid] = $row['status'];
        }
    } catch (PDOException $e) {
    }

    usort($timeline, function ($a, $b) {
        return strtotime($b['ts']) <=> strtotime($a['ts']);
    });

    return $timeline;
}

/**
 * Poskládá krátké shrnutí monitoru (1-2 věty: celkový stav + nejzávažnější
 * aktuální problém, pokud nějaký je) z už existujících dat - health score,
 * Knowledge tips, Insights (forecast/anomaly). Záměrně neopakuje nic, co už
 * je vidět jinde v Overview tabu (Server Information) nebo v Timeline tabu.
 * Čistě deterministická skládačka šablon (t() + sprintf), žádné AI volání -
 * stejná filozofie jako zbytek Insights enginu.
 */
/**
 * How long a metric has been sitting above its threshold, without interruption.
 *
 * "CPU is at 91 %" is a snapshot and says nothing about whether it is a blip or
 * a problem. "CPU has been above 85 % for 18 minutes" is a judgement, and it is
 * plain arithmetic over samples we already store - no new collection needed.
 *
 * Walks backwards from the newest sample and stops at the first one below the
 * threshold. Returns null when the newest sample is already below it, when
 * there are no samples, or when the run is a single sample - one reading is a
 * moment, not a duration.
 */
function bk_metric_pressure(PDO $pdo, int $monitor_id, string $column, float $threshold): ?array {
    // Whitelist: the column name goes into SQL and must never come from input.
    $allowed = ['cpu_usage', 'ram_usage', 'hdd_usage', 'swap_usage'];
    if (!in_array($column, $allowed, true)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT {$column} AS val, UNIX_TIMESTAMP(checked_at) AS ts
               FROM vps_metrics
              WHERE monitor_id = ? AND {$column} IS NOT NULL
                AND checked_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
              ORDER BY checked_at DESC
              LIMIT 720"
        );
        $stmt->execute([$monitor_id]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('[exec_summary] Nešlo načíst řadu pro ' . $column . ': ' . $e->getMessage());
        return null;
    }

    if (count($rows) < 2 || (float)$rows[0]['val'] < $threshold) {
        return null;
    }

    $newest_ts = (int)$rows[0]['ts'];
    $oldest_ts = $newest_ts;
    $peak = (float)$rows[0]['val'];
    foreach ($rows as $row) {
        if ((float)$row['val'] < $threshold) {
            break;
        }
        $oldest_ts = (int)$row['ts'];
        $peak = max($peak, (float)$row['val']);
    }

    $minutes = (int)round(($newest_ts - $oldest_ts) / 60);
    if ($minutes < 1) {
        return null;
    }

    return [
        'minutes' => $minutes,
        'current' => round((float)$rows[0]['val'], 1),
        'peak' => round($peak, 1),
        'threshold' => $threshold,
        'since_ts' => $oldest_ts,
    ];
}

/**
 * Which process was loading the machine during a window.
 *
 * The missing half of the enriched summary: a threshold alert says something is
 * wrong, this says what. Reads process_samples, which only started being kept
 * on 14 Aug 2026 - for anything older it returns null rather than guessing from
 * the latest snapshot, which would name today's process for yesterday's spike.
 */
function bk_top_process_in_window(PDO $pdo, int $monitor_id, int $from_ts, int $to_ts, string $kind = 'cpu'): ?array {
    $kind = $kind === 'ram' ? 'ram' : 'cpu';
    $order = $kind === 'ram' ? 'ram_mb' : 'cpu_pct';

    try {
        $stmt = $pdo->prepare(
            "SELECT name, cpu_pct, ram_mb
               FROM process_samples
              WHERE monitor_id = ? AND kind = ?
                AND sampled_at BETWEEN FROM_UNIXTIME(?) AND FROM_UNIXTIME(?)
                AND {$order} IS NOT NULL
              ORDER BY {$order} DESC
              LIMIT 1"
        );
        $stmt->execute([$monitor_id, $kind, $from_ts, $to_ts]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // Table missing on an older install - the summary just stays shorter.
        return null;
    }

    if (!$row) {
        return null;
    }

    return [
        'name' => (string)$row['name'],
        'cpu' => $row['cpu_pct'] !== null ? round((float)$row['cpu_pct'], 1) : null,
        'ram_mb' => $row['ram_mb'] !== null ? round((float)$row['ram_mb'], 1) : null,
    ];
}

/**
 * The one thing the rest of the page does not say: how long, and because of what.
 *
 * A chart shows CPU is high. It does not say whether that is half a minute or
 * three hours, nor which process is behind it. Both are computable from data we
 * already store - the duration from vps_metrics, the culprit from
 * process_samples.
 *
 * Returns null when nothing is above a threshold, so the caller can say "no
 * current issues" without contradicting itself in the next sentence.
 */
function bk_summary_pressure_line(PDO $pdo, array $monitor, array $details): ?string {
    // Chybějící práh je null, ne nula.
    //
    // `?? 0` by znamenalo práh nula procent, tedy že všechno je vždycky nad
    // ním. Tady by se to sice hned zase vyfiltrovalo podmínkou, ale je to
    // přesně ten zápis, ze kterého jinde vznikla vymyšlená hodnota - proto
    // rovnou null a explicitní kontrola.
    $threshold_of = static function ($raw): ?float {
        return is_numeric($raw) && (float)$raw > 0 ? (float)$raw : null;
    };

    $watched = [
        ['cpu', 'cpu_usage', $threshold_of($monitor['cpu_threshold'] ?? null), 'cpu'],
        ['ram', 'ram_usage', $threshold_of($monitor['ram_threshold'] ?? null), 'ram'],
    ];

    foreach ($watched as [$key, $column, $threshold, $kind]) {
        if ($threshold === null) {
            continue;
        }
        $pressure = bk_metric_pressure($pdo, (int)$monitor['id'], $column, $threshold);
        if ($pressure === null) {
            continue;
        }

        $line = sprintf(
            t('exec_summary_pressure_' . $key),
            $pressure['current'],
            bk_format_duration_secs($pressure['minutes'] * 60)
        );

        $top = bk_top_process_in_window($pdo, (int)$monitor['id'], $pressure['since_ts'], time(), $kind);
        if ($top !== null) {
            $value = $kind === 'ram' ? $top['ram_mb'] : $top['cpu'];
            if ($value !== null) {
                $line .= ' ' . sprintf(
                    t('exec_summary_pressure_process'),
                    $top['name'],
                    $value,
                    $kind === 'ram' ? 'MB' : '%'
                );
            }
        }

        // Kontext, který dává doporučení smysl. Vypisuje se jen to, co agent
        // opravdu poslal - chybějící údaj se mlčky vynechá, místo aby se do
        // věty dostala nula.
        $context = [];
        if (isset($details['load1']) && is_numeric($details['load1'])) {
            $context[] = sprintf(t('exec_summary_ctx_load'), (float)$details['load1']);
        }
        if (isset($details['wifi_clients_count']) && is_numeric($details['wifi_clients_count'])) {
            $context[] = sprintf(t('exec_summary_ctx_wifi'), (int)$details['wifi_clients_count']);
        }
        if ($context) {
            $line .= ' ' . implode(', ', $context) . '.';
        }

        return $line;
    }

    return null;
}

function bk_build_executive_summary($monitor, $health_score, array $knowledge_tips, array $insights, array $recent_events, ?PDO $pdo = null, array $details = []) {
    $sentences = [];
    $name = $monitor['name'] ?? '';

    // 1. Celkový stav
    if (($monitor['status'] ?? '') !== 'up') {
        $sentences[] = sprintf(t('exec_summary_down'), $name);
    } elseif (is_array($health_score) && isset($health_score['score'])) {
        $score = (int)$health_score['score'];
        if ($score >= 90) {
            $sentences[] = sprintf(t('exec_summary_healthy_score'), $name, $score);
        } elseif ($score >= 70) {
            $sentences[] = sprintf(t('exec_summary_warn_score'), $name, $score);
        } else {
            $sentences[] = sprintf(t('exec_summary_fail_score'), $name, $score);
        }
    } else {
        $sentences[] = sprintf(t('exec_summary_up'), $name);
    }

    // 2. Nejzávažnější aktuální problém (critical tip > warn tip > insight)
    $top_concern = null;
    foreach ($knowledge_tips as $tip) {
        if (($tip['severity'] ?? '') === 'critical') { $top_concern = $tip['text']; break; }
    }
    if ($top_concern === null) {
        foreach ($knowledge_tips as $tip) {
            if (($tip['severity'] ?? '') === 'warn') { $top_concern = $tip['text']; break; }
        }
    }
    if ($top_concern === null && !empty($insights)) {
        $top_concern = $insights[0]['text'] ?? null;
    }
    // Tlak se počítá dřív, než se rozhodne o větě "žádné problémy".
    //
    // Kdyby se přidával až za ni, shrnutí by si odporovalo v jednom dechu:
    // "Žádné aktuální problémy nebyly zjištěny. CPU je na 91 % už 18 minut."
    // Chycené na ukázce se skutečnými daty, ne úvahou.
    $pressure_line = ($pdo instanceof PDO && ($monitor['status'] ?? '') === 'up')
        ? bk_summary_pressure_line($pdo, $monitor, $details)
        : null;

    if ($top_concern !== null) {
        $sentences[] = $top_concern;
    } elseif ($pressure_line === null && ($monitor['status'] ?? '') === 'up') {
        $sentences[] = t('exec_summary_no_concerns');
    }

    if ($pressure_line !== null) {
        $sentences[] = $pressure_line;
    }

    // Dřív tu byla i "nejnovější událost" a "stáří dat" věta - odstraněno,
    // duplikovalo se to se sekcí Server Information v Overview tabu (Poslední
    // kontrola / Poslední změna stavu) a s Timeline tabem, který má tu samou
    // událost v plném kontextu. Shrnutí obsahuje jen to, co jinde vidět není.
    return implode(' ', $sentences);
}

/**
 * Hrubý relativní popisek času ("dnes", "včera", "N dní zpět") - sdílený
 * mezi Executive Summary a Timeline, aby oboje mluvily stejnou řečí.
 */
function bk_relative_time_label($timestamp) {
    $ts = strtotime((string)$timestamp);
    if (!$ts) return '';
    $today = date('Y-m-d');
    $that_day = date('Y-m-d', $ts);
    if ($that_day === $today) return t('timeline_today');
    if ($that_day === date('Y-m-d', strtotime('-1 day'))) return t('timeline_yesterday');
    $days_ago = (int)round((strtotime($today) - strtotime($that_day)) / 86400);
    return sprintf(t('timeline_days_ago'), $days_ago);
}

/**
 * Vykoná $builder() s t() dočasně přepnutým na $lang, bez ohledu na to, jaký
 * jazyk (pokud vůbec nějaký) má aktuálně nastavený request/cookie - e-maily
 * nemají návštěvníka, jejich jazyk určuje jen nastavení email_lang admina.
 * t() (lang.php) čte $GLOBALS['BK_LANG']/['BK_STRINGS'] při každém volání
 * znovu, takže dočasná výměna těchhle dvou globálů kolem $builder() stačí -
 * není potřeba žádný objekt/singleton refaktoring. Bezpečné i z CLI (cron.php)
 * - lang.php bez $_GET/$_COOKIE prostě zůstane na výchozí 'cs', než ho tahle
 * funkce přepíše, a setcookie() bez HTTP hlaviček je tichý no-op.
 */
function bk_with_email_lang(string $lang, callable $builder) {
    require_once __DIR__ . '/lang.php';
    $saved_lang = $GLOBALS['BK_LANG'] ?? null;
    $saved_strings = $GLOBALS['BK_STRINGS'] ?? null;
    $saved_fallback = $GLOBALS['BK_STRINGS_CS_FALLBACK'] ?? null;

    $lang = in_array($lang, ['cs', 'en'], true) ? $lang : 'cs';
    $GLOBALS['BK_LANG'] = $lang;
    $GLOBALS['BK_STRINGS'] = require __DIR__ . "/lang/{$lang}.php";
    // lang.php samo nastavuje CS_FALLBACK jen když je BK_LANG !== 'cs' (viz tam) -
    // stejná podmínka i tady, aby chybějící klíč nikdy nespadl na holý t()['key'] warning.
    $GLOBALS['BK_STRINGS_CS_FALLBACK'] = $lang === 'cs' ? null : require __DIR__ . '/lang/cs.php';

    try {
        return $builder();
    } finally {
        $GLOBALS['BK_LANG'] = $saved_lang;
        $GLOBALS['BK_STRINGS'] = $saved_strings;
        $GLOBALS['BK_STRINGS_CS_FALLBACK'] = $saved_fallback;
    }
}

/**
 * Registr metrik dostupných na Level 3 Metric Detail stránce (index.php
 * ?view=metric). Jeden zdroj pravdy pro klíč->sloupec mapování, sdílený mezi
 * api.php (dotaz do vps_metrics) a renderem stránky (popisky/jednotky/Related
 * Metrics odkazy) - viz project_dashboard_ia_redesign.md v paměti.
 */
function bk_get_metric_registry() {
    return [
        'cpu' => ['column' => 'cpu_usage', 'label_key' => 'metric_label_cpu', 'unit' => '%'],
        'ram' => ['column' => 'ram_usage', 'label_key' => 'metric_label_ram', 'unit' => '%'],
        'hdd' => ['column' => 'hdd_usage', 'label_key' => 'metric_label_hdd', 'unit' => '%'],
        'net' => ['column' => 'net_usage', 'label_key' => 'metric_label_net', 'unit' => 'KB/s'],
        'load1' => ['column' => 'load_avg_1', 'label_key' => 'metric_label_load1', 'unit' => ''],
        'load5' => ['column' => 'load_avg_5', 'label_key' => 'metric_label_load5', 'unit' => ''],
        'load15' => ['column' => 'load_avg_15', 'label_key' => 'metric_label_load15', 'unit' => ''],
        'cpu_steal' => ['column' => 'cpu_steal', 'label_key' => 'metric_label_cpu_steal', 'unit' => '%'],
        'swap' => ['column' => 'swap_usage', 'label_key' => 'metric_label_swap', 'unit' => '%'],
        'disk_io_read' => ['column' => 'disk_io_read_kbps', 'label_key' => 'metric_label_disk_io_read', 'unit' => 'KB/s'],
        'disk_io_write' => ['column' => 'disk_io_write_kbps', 'label_key' => 'metric_label_disk_io_write', 'unit' => 'KB/s'],
        'net_errors' => ['column' => 'net_errors', 'label_key' => 'metric_label_net_errors', 'unit' => ''],
        'iowait' => ['column' => 'iowait_pct', 'label_key' => 'metric_label_iowait', 'unit' => '%'],
        'inode_usage' => ['column' => 'inode_usage_pct', 'label_key' => 'metric_label_inode_usage', 'unit' => '%'],
        'ts_clients' => ['column' => 'ts_clients_online', 'label_key' => 'metric_label_ts_clients', 'unit' => ''],
        'ts_process_cpu' => ['column' => 'ts_process_cpu', 'label_key' => 'metric_label_ts_process_cpu', 'unit' => '%'],
        'ts_process_ram' => ['column' => 'ts_process_ram', 'label_key' => 'metric_label_ts_process_ram', 'unit' => 'MB'],
        'net_ipv4' => ['column' => 'net_ipv4_kbps', 'label' => 'IPv4 Provoz', 'unit' => 'KB/s'],
        'net_ipv6' => ['column' => 'net_ipv6_kbps', 'label' => 'IPv6 Provoz', 'unit' => 'KB/s'],
    ];
}

/**
 * Sáhne do vps_metrics pro jednu metriku (sloupec z bk_get_metric_registry())
 * v daném období a vrátí syrové body [timestamp, hodnota, špička]. Sdíleno
 * mezi api.php (JSON pro graf) a render_metric_detail_page() (číslo pro stat
 * kartu při prvním vykreslení stránky) - jedna SQL logika, ne dvě kopie.
 * $column musí pocházet z bk_get_metric_registry(), nikdy přímo z $_GET.
 */
function bk_fetch_metric_series($pdo, $monitor_id, $column, $period) {
    $points = [];
    if ($period === '30d') {
        $stmt = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(MIN(checked_at)) AS ts, AVG($column) AS val, MAX($column) AS val_peak
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND $column IS NOT NULL
            GROUP BY DATE(checked_at)
            ORDER BY ts ASC
        ");
    } elseif ($period === '7d') {
        $stmt = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(MIN(checked_at)) AS ts, AVG($column) AS val, MAX($column) AS val_peak
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND $column IS NOT NULL
            GROUP BY DATE_FORMAT(checked_at, '%Y-%m-%d %H')
            ORDER BY ts ASC
        ");
    } else {
        $hours = ['15m' => 0.25, '1h' => 1, '6h' => 6, '24h' => 24][$period] ?? 24;
        $interval_expr = $hours < 1 ? sprintf('%d MINUTE', (int)($hours * 60)) : sprintf('%d HOUR', (int)$hours);
        $stmt = $pdo->prepare("
            SELECT UNIX_TIMESTAMP(checked_at) AS ts, $column AS val, $column AS val_peak
            FROM vps_metrics
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL $interval_expr) AND $column IS NOT NULL
            ORDER BY checked_at ASC
        ");
    }
    $stmt->execute([$monitor_id]);
    foreach ($stmt->fetchAll() as $r) {
        $points[] = [(int)$r['ts'], round((float)$r['val'], 2), round((float)$r['val_peak'], 2)];
    }
    return $points;
}

/**
 * Current/average/peak/trend pro jednu metriku - $points je výstup
 * bk_fetch_metric_series() ([timestamp, hodnota, špička] řádky, chronologicky
 * vzestupně). Trend se počítá stejnou technikou jako bk_half_window_rate()
 * (starší/novější polovina okna), jen vrací procentuální změnu místo rate/den
 * - pro stat kartu chceme "o kolik % je to jinak než dřív", ne projekci.
 * @return array{current: ?float, average: ?float, peak: ?float, trend_pct: ?float}
 */
function bk_compute_metric_stats(array $points) {
    $values = [];
    foreach ($points as $p) {
        if (isset($p[1]) && $p[1] !== null) {
            $values[] = (float)$p[1];
        }
    }
    if (empty($values)) {
        return ['current' => null, 'average' => null, 'peak' => null, 'trend_pct' => null];
    }

    $current = end($values);
    $average = round(array_sum($values) / count($values), 1);
    $peak = round(max($values), 1);

    $trend_pct = null;
    $count = count($values);
    if ($count >= 4) {
        $half = intdiv($count, 2);
        $older = array_slice($values, 0, $half);
        $newer = array_slice($values, $half);
        $older_avg = array_sum($older) / count($older);
        $newer_avg = array_sum($newer) / count($newer);
        if (abs($older_avg) > 0.01) {
            $trend_pct = round((($newer_avg - $older_avg) / $older_avg) * 100, 1);
        }
        // Růst z nuly nemá smysluplné procento - dřívější sentinel "+100 %"
        // vypadal jako spočítaný trend; null nechá UI trend prostě nevypsat.
    }

    return ['current' => round($current, 1), 'average' => $average, 'peak' => $peak, 'trend_pct' => $trend_pct];
}

/**
 * Level 3 Metric Detail stránka (index.php?view=metric&monitor=X&metric=Y) -
 * vlastní samostatná HTML stránka (ne tab v panelu), protože potřebuje být
 * adresovatelná URL kvůli breadcrumbům a Related Metrics odkazům. Ukončuje
 * request sama (exit), volající (index.php) do ní jen předá $pdo/$monitor/
 * $metric_key/$is_admin a nic dalšího po ní nerenderuje.
 */
function render_metric_detail_page($pdo, $monitor, $metric_key, $is_admin) {
    $registry = bk_get_metric_registry();
    $site_title = get_setting('site_title', 'Blood Kings');

    if (!$monitor || !isset($registry[$metric_key])) {
        http_response_code(404);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($site_title) . '</title><link rel="stylesheet" href="assets/style.css"></head><body style="display:flex;align-items:center;justify-content:center;height:100vh;"><p style="color:var(--text-secondary,#94a3b8);">' . htmlspecialchars(t('metric_not_found')) . ' <a href="index.php">' . htmlspecialchars(t('breadcrumb_dashboard')) . '</a></p></body></html>';
        exit;
    }

    $meta = $registry[$metric_key];
    $column = $meta['column'];
    $metric_label = t($meta['label_key'] ?? $metric_key);
    $unit = $meta['unit'] ?? '';

    $points_24h = bk_fetch_metric_series($pdo, $monitor['id'], $column, '24h');
    $stats = bk_compute_metric_stats($points_24h);

    // Related Metrics - jen ty, u kterých tenhle monitor reálně hlásí data
    // (poslední řádek vps_metrics), aby se neproklikávalo do prázdna.
    $stmt_latest = $pdo->prepare("SELECT * FROM vps_metrics WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT 1");
    $stmt_latest->execute([$monitor['id']]);
    $latest_row = $stmt_latest->fetch();
    $related = [];
    if ($latest_row) {
        foreach ($registry as $rkey => $rmeta) {
            if ($rkey === $metric_key) continue;
            if (isset($latest_row[$rmeta['column']]) && $latest_row[$rmeta['column']] !== null) {
                $related[$rkey] = $rmeta;
            }
        }
    }

    // "Proč" vrstva - poslední pozoruhodná událost za stejné okno jako graf (24h),
    // stejný zdroj dat jako Timeline (Phase 1) a Executive Summary.
    $recent_events = bk_get_monitor_timeline($pdo, $monitor['id'], 1);
    $latest_event_line = null;
    if (!empty($recent_events)) {
        $ev = $recent_events[0];
        $ev_label_key = 'timeline_event_' . $ev['event_type'];
        $ev_label = t($ev_label_key);
        if ($ev_label === $ev_label_key) { $ev_label = $ev['description'] ?: $ev['event_type']; }
        $latest_event_line = sprintf(t('exec_summary_last_event'), $ev_label, bk_relative_time_label($ev['ts']));
    }

    $trend_dir = 'flat';
    if ($stats['trend_pct'] !== null) {
        $trend_dir = $stats['trend_pct'] > 2 ? 'up' : ($stats['trend_pct'] < -2 ? 'down' : 'flat');
    }

    // Alert Regions - threshold bands pro metriky s nastavitelným prahem
    $warn_threshold = null;
    $crit_threshold = null;
    $threshold_map = ['cpu' => 'cpu_threshold', 'ram' => 'ram_threshold', 'hdd' => 'hdd_threshold'];
    if (isset($threshold_map[$metric_key]) && !empty($monitor[$threshold_map[$metric_key]])) {
        $crit_threshold = (float)$monitor[$threshold_map[$metric_key]];
        $warn_threshold = max(0, $crit_threshold - 15); // Warning zone 15% pod critical
    }
    ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($GLOBALS['BK_LANG']); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <title><?php echo htmlspecialchars($metric_label . ' - ' . $monitor['name'] . ' - ' . $site_title); ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?php echo filemtime(__DIR__ . '/assets/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo BK_CDN_FONTAWESOME; ?>" integrity="<?php echo BK_CDN_FONTAWESOME_SRI; ?>" crossorigin="anonymous">
    <script src="<?php echo BK_CDN_ECHARTS; ?>" integrity="<?php echo BK_CDN_ECHARTS_SRI; ?>" crossorigin="anonymous"></script>
    <script>
        if (localStorage.getItem('theme') === 'light') { document.documentElement.classList.add('light-theme'); }
    </script>
</head>
<body>
    <div class="container" style="max-width: 900px; margin: 0 auto; padding: 1.5rem 1rem;">
        <nav style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 1.25rem;">
            <a href="index.php" style="color: var(--text-muted); text-decoration: none;"><?php echo htmlspecialchars(t('breadcrumb_dashboard')); ?></a>
            <span style="margin: 0 0.4rem;">/</span>
            <a href="monitor.php?id=<?php echo (int)$monitor['id']; ?>" style="color: var(--text-muted); text-decoration: none;"><?php echo htmlspecialchars($monitor['name']); ?></a>
            <span style="margin: 0 0.4rem;">/</span>
            <span style="color: var(--text-primary);"><?php echo htmlspecialchars($metric_label); ?></span>
        </nav>

        <h1 style="font-size: 1.3rem; margin: 0 0 1rem 0;"><?php echo htmlspecialchars($metric_label); ?></h1>

        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 1.25rem;">
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem 1rem; min-width: 120px;">
                <div style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('metric_stat_current')); ?></div>
                <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary);"><?php echo $stats['current'] !== null ? $stats['current'] . $unit : '—'; ?></div>
                <?php if ($stats['trend_pct'] !== null): ?>
                    <div style="font-size: 0.75rem; color: <?php echo $trend_dir === 'up' ? 'var(--color-red)' : ($trend_dir === 'down' ? 'var(--color-green)' : 'var(--text-muted)'); ?>;">
                        <i class="fas fa-arrow-<?php echo $trend_dir === 'up' ? 'up' : ($trend_dir === 'down' ? 'down' : 'right'); ?>"></i> <?php echo ($stats['trend_pct'] > 0 ? '+' : '') . $stats['trend_pct']; ?>%
                    </div>
                <?php endif; ?>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem 1rem; min-width: 120px;">
                <div style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('metric_stat_average')); ?></div>
                <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary);"><?php echo $stats['average'] !== null ? $stats['average'] . $unit : '—'; ?></div>
            </div>
            <div style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem 1rem; min-width: 120px;">
                <div style="color: var(--text-muted); font-size: 0.72rem; text-transform: uppercase;"><?php echo htmlspecialchars(t('metric_stat_peak')); ?></div>
                <div style="font-size: 1.4rem; font-weight: 700; color: var(--text-primary);"><?php echo $stats['peak'] !== null ? $stats['peak'] . $unit : '—'; ?></div>
            </div>
        </div>

        <div id="predictionBadge" style="display: none; margin-bottom: 1rem; font-size: 0.8rem; color: var(--color-red); background: rgba(231,76,60,0.08); border: 1px solid rgba(231,76,60,0.2); border-radius: 6px; padding: 0.5rem 0.75rem; align-items: center; gap: 0.4rem;"></div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
            <div style="display: flex; gap: 0.25rem;" id="metricViewSwitch">
                <button type="button" data-view="line" class="btn btn-secondary btn-sm active" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><i class="fas fa-chart-line"></i> <?php echo htmlspecialchars(t('chart_view_line')); ?></button>
                <button type="button" data-view="heatmap" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><i class="fas fa-table-cells"></i> <?php echo htmlspecialchars(t('chart_view_heatmap')); ?></button>
                <button type="button" data-view="histogram" class="btn btn-secondary btn-sm" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><i class="fas fa-chart-bar"></i> <?php echo htmlspecialchars(t('chart_view_histogram')); ?></button>
            </div>
            <div style="display: flex; gap: 0.25rem;" id="metricPeriodSwitch" data-monitor="<?php echo (int)$monitor['id']; ?>" data-metric="<?php echo htmlspecialchars($metric_key); ?>">
                <?php foreach (['15m', '1h', '6h', '24h', '7d', '30d'] as $p): ?>
                    <button type="button" data-period="<?php echo $p; ?>" class="btn btn-secondary btn-sm <?php echo $p === '24h' ? 'active' : ''; ?>" style="padding: 0.25rem 0.6rem; font-size: 0.72rem;"><?php echo htmlspecialchars(t('period_' . $p)); ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
            <label style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                <input type="checkbox" id="compareToggle" style="width: auto;"> <?php echo htmlspecialchars(t('chart_compare_yesterday')); ?>
            </label>
            <label style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                <input type="checkbox" id="compareWeekToggle" style="width: auto;"> <?php echo htmlspecialchars(t('chart_compare_last_week')); ?>
            </label>
            <label style="font-size: 0.75rem; color: var(--text-muted); display: flex; align-items: center; gap: 0.3rem; cursor: pointer;">
                <input type="checkbox" id="baselineToggle" style="width: auto;"> <?php echo htmlspecialchars(t('chart_show_baseline')); ?>
            </label>
        </div>
        <div style="position: relative; height: 340px; width: 100%; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px;">
            <div id="metricChart" style="position: absolute; inset: 0;"></div>
        </div>

        <?php if ($latest_event_line): ?>
            <div style="margin-top: 1rem; font-size: 0.82rem; color: var(--text-secondary); background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; padding: 0.75rem 1rem;">
                <i class="fas fa-file-lines" style="color: var(--text-muted); margin-right: 0.4rem;"></i><?php echo htmlspecialchars($latest_event_line); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($related)): ?>
            <div style="margin-top: 1.5rem;">
                <div class="detail-section-title" style="margin-bottom: 0.6rem;"><i class="fas fa-diagram-project"></i> <?php echo htmlspecialchars(t('related_metrics_heading')); ?></div>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <?php foreach ($related as $rkey => $rmeta):
                        $rval = $latest_row[$rmeta['column']] ?? null;
                        $rdot = 'var(--color-green)';
                        if ($rval !== null) {
                            if (in_array($rkey, ['cpu', 'ram', 'hdd']) && $rval > 80) $rdot = 'var(--color-red)';
                            elseif (in_array($rkey, ['cpu', 'ram', 'hdd']) && $rval > 50) $rdot = 'var(--color-yellow)';
                        }
                    ?>
                        <a href="index.php?view=metric&monitor=<?php echo (int)$monitor['id']; ?>&metric=<?php echo htmlspecialchars($rkey); ?>" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 0.4rem 0.75rem; font-size: 0.8rem; color: var(--text-secondary); text-decoration: none; display: flex; align-items: center; gap: 0.4rem;">
                            <span style="width:7px;height:7px;border-radius:50%;background:<?php echo $rdot; ?>;flex-shrink:0;"></span>
                            <?php echo htmlspecialchars(t($rmeta['label_key'] ?? $rkey)); ?>
                            <?php if ($rval !== null): ?><strong style="color:var(--text-primary);font-size:0.78rem;"><?php echo is_numeric($rval) ? round((float)$rval, 1) : htmlspecialchars((string)$rval); ?><?php echo htmlspecialchars($rmeta['unit'] ?? ''); ?></strong><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var chart = echarts.init(document.getElementById('metricChart'), (localStorage.getItem('theme') === 'light') ? null : 'dark');
        var switcher = document.getElementById('metricPeriodSwitch');
        var viewSwitch = document.getElementById('metricViewSwitch');
        var compareToggle = document.getElementById('compareToggle');
        var compareWeekToggle = document.getElementById('compareWeekToggle');
        var baselineToggle = document.getElementById('baselineToggle');
        var monitorId = switcher.dataset.monitor;
        var metricKey = switcher.dataset.metric;
        var unit = <?php echo json_encode($unit); ?>;
        var warnThreshold = <?php echo $warn_threshold !== null ? $warn_threshold : 'null'; ?>;
        var critThreshold = <?php echo $crit_threshold !== null ? $crit_threshold : 'null'; ?>;
        var currentView = 'line';
        var currentPeriod = '24h';
        var cachedPayload = null;

        function renderLine(payload, compareData, baselineData) {
            var seriesData = payload.points.map(function (p) { return [p[0] * 1000, p[1]]; });
            var markPoints = (payload.events || []).map(function (ev) {
                return { name: ev.label, coord: [ev.ts * 1000, null], value: ev.label };
            });
            var series = [{
                type: 'line',
                name: '<?php echo htmlspecialchars($metric_label); ?>',
                showSymbol: false,
                data: seriesData,
                areaStyle: { opacity: 0.08 },
                lineStyle: { width: 2 },
                markPoint: { symbol: 'pin', symbolSize: 28, data: markPoints },
                markArea: (warnThreshold !== null && critThreshold !== null) ? {
                    silent: true,
                    data: [
                        [{ yAxis: warnThreshold, itemStyle: { color: 'rgba(243,156,18,0.07)' } }, { yAxis: critThreshold }],
                        [{ yAxis: critThreshold, itemStyle: { color: 'rgba(231,76,60,0.09)' } }, { yAxis: 100 }]
                    ]
                } : undefined
            }];
            // Prediction band (dashed trend line for growth metrics)
            if (payload.prediction && payload.prediction.length > 1) {
                series.push({
                    type: 'line',
                    name: '<?php echo htmlspecialchars(t('chart_prediction')); ?>',
                    showSymbol: false,
                    data: payload.prediction.map(function (p) { return [p[0] * 1000, p[1]]; }),
                    lineStyle: { width: 2, type: 'dashed', color: '#e74c3c' },
                    itemStyle: { color: '#e74c3c' },
                    areaStyle: { opacity: 0.04, color: '#e74c3c' }
                });
            }
            if (compareData && compareData.length > 0) {
                series.push({
                    type: 'line',
                    name: '<?php echo htmlspecialchars(t('chart_yesterday')); ?>',
                    showSymbol: false,
                    data: compareData.map(function (p) { return [p[0] * 1000, p[1]]; }),
                    lineStyle: { width: 1, type: 'dashed', opacity: 0.5 },
                    itemStyle: { opacity: 0.5 }
                });
            }
            if (baselineData && baselineData.length > 0) {
                series.push({
                    type: 'line',
                    name: '<?php echo htmlspecialchars(t('chart_baseline')); ?>',
                    showSymbol: false,
                    data: baselineData.map(function (p) { return [p[0] * 1000, p[1]]; }),
                    lineStyle: { width: 1, type: 'dotted', color: '#888' },
                    itemStyle: { color: '#888' }
                });
            }
            chart.setOption({
                backgroundColor: 'transparent',
                grid: { left: 50, right: 20, top: 30, bottom: 40 },
                legend: series.length > 1 ? { top: 0, textStyle: { fontSize: 11 } } : undefined,
                tooltip: { trigger: 'axis', valueFormatter: function (v) { return v !== null && v !== undefined ? v + ' ' + unit : '—'; } },
                xAxis: { type: 'time' },
                yAxis: { type: 'value', axisLabel: { formatter: '{value}' + unit } },
                dataZoom: [{ type: 'inside' }, { type: 'slider', height: 28, bottom: 0, borderColor: 'rgba(255,255,255,0.1)', fillerColor: 'rgba(88,166,255,0.1)' }],
                series: series
            }, true);
        }

        function renderHeatmap(payload) {
            var data = [];
            var hours = [];
            var days = [];
            for (var h = 0; h < 24; h++) hours.push(h + ':00');
            payload.points.forEach(function (p) {
                var d = new Date(p[0] * 1000);
                var dayKey = d.toLocaleDateString();
                if (days.indexOf(dayKey) === -1) days.push(dayKey);
                var dayIdx = days.indexOf(dayKey);
                data.push([d.getHours(), dayIdx, p[1]]);
            });
            var maxVal = Math.max.apply(null, data.map(function (d) { return d[2]; }).concat([1]));
            chart.setOption({
                backgroundColor: 'transparent',
                grid: { left: 80, right: 40, top: 20, bottom: 60 },
                tooltip: { position: 'top', formatter: function (p) { return days[p.value[1]] + ' ' + hours[p.value[0]] + '<br/>' + p.value[2] + ' ' + unit; } },
                xAxis: { type: 'category', data: hours, splitArea: { show: true } },
                yAxis: { type: 'category', data: days, splitArea: { show: true } },
                visualMap: { min: 0, max: maxVal, calculable: true, orient: 'horizontal', left: 'center', bottom: 0, inRange: { color: ['#313695', '#4575b4', '#74add1', '#abd9e9', '#fee090', '#fdae61', '#f46d43', '#d73027'] } },
                series: [{ type: 'heatmap', data: data, label: { show: false }, emphasis: { itemStyle: { shadowBlur: 10, shadowColor: 'rgba(0,0,0,0.5)' } } }]
            }, true);
        }

        function renderHistogram(payload) {
            var values = payload.points.map(function (p) { return p[1]; }).filter(function (v) { return v !== null; });
            var min = Math.min.apply(null, values);
            var max = Math.max.apply(null, values);
            var bucketCount = 10;
            var bucketSize = (max - min) / bucketCount || 1;
            var buckets = [];
            var counts = [];
            for (var i = 0; i < bucketCount; i++) {
                var lo = min + i * bucketSize;
                var hi = lo + bucketSize;
                buckets.push(Math.round(lo) + '-' + Math.round(hi));
                counts.push(0);
            }
            values.forEach(function (v) {
                var idx = Math.min(Math.floor((v - min) / bucketSize), bucketCount - 1);
                counts[idx]++;
            });
            chart.setOption({
                backgroundColor: 'transparent',
                grid: { left: 50, right: 20, top: 20, bottom: 40 },
                tooltip: { trigger: 'axis' },
                xAxis: { type: 'category', data: buckets, axisLabel: { rotate: 45, fontSize: 10 } },
                yAxis: { type: 'value', name: '<?php echo htmlspecialchars(t('chart_count')); ?>' },
                series: [{ type: 'bar', data: counts, itemStyle: { color: '#5470c6' } }]
            }, true);
        }

        function render(payload) {
            cachedPayload = payload;
            var compareData = null;
            var baselineData = null;
            if (currentView === 'line') {
                renderLine(payload, compareData, baselineData);
            } else if (currentView === 'heatmap') {
                renderHeatmap(payload);
            } else if (currentView === 'histogram') {
                renderHistogram(payload);
            }
        }

        function load(period) {
            currentPeriod = period;
            var url = 'api.php?action=metric_series&monitor_id=' + encodeURIComponent(monitorId) + '&metric=' + encodeURIComponent(metricKey) + '&period=' + encodeURIComponent(period);
            if (compareToggle.checked) url += '&compare=yesterday';
            else if (compareWeekToggle.checked) url += '&compare=last_week';
            if (baselineToggle.checked) url += '&baseline=7d';
            // Poznámky se tahají zvlášť a přimíchávají mezi události, které graf
            // už umí vykreslit jako špendlíky. Kdyby se nenačetly, graf se
            // vykreslí i bez nich - poznámka navíc není důvod nezobrazit data.
            var annUrl = 'api.php?action=annotations&monitor_id=' + encodeURIComponent(monitorId)
                + '&metric=' + encodeURIComponent(metricKey)
                + '&hours=' + (period === '30d' ? 720 : (period === '7d' ? 168 : 24));

            Promise.all([
                fetch(url).then(function (r) { return r.json(); }),
                fetch(annUrl).then(function (r) { return r.json(); }).catch(function () { return { annotations: [] }; })
            ])
                .then(function (both) {
                    var payload = both[0];
                    var anns = (both[1] && both[1].annotations) || [];
                    if (anns.length) {
                        payload.events = (payload.events || []).concat(anns.map(function (a) {
                            return { ts: a.ts, label: a.author ? a.note + ' (' + a.author + ')' : a.note };
                        }));
                    }
                    cachedPayload = payload;
                    if (currentView === 'line') {
                        renderLine(payload, payload.compare || null, payload.baseline || null);
                    } else if (currentView === 'heatmap') {
                        renderHeatmap(payload);
                    } else {
                        renderHistogram(payload);
                    }
                    // Show prediction info badge
                    var badge = document.getElementById('predictionBadge');
                    if (badge) {
                        if (payload.days_to_full) {
                            badge.style.display = 'inline-flex';
                            badge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + <?php echo json_encode(t('chart_estimated_full')); ?>.replace('%d', payload.days_to_full);
                        } else {
                            badge.style.display = 'none';
                        }
                    }
                })
                .catch(function () {});
        }

        switcher.querySelectorAll('button[data-period]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switcher.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                load(btn.dataset.period);
            });
        });

        viewSwitch.querySelectorAll('button[data-view]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                viewSwitch.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentView = btn.dataset.view;
                if (cachedPayload) render(cachedPayload);
            });
        });

        compareToggle.addEventListener('change', function () { if (this.checked) compareWeekToggle.checked = false; load(currentPeriod); });
        compareWeekToggle.addEventListener('change', function () { if (this.checked) compareToggle.checked = false; load(currentPeriod); });
        baselineToggle.addEventListener('change', function () { load(currentPeriod); });

        // Click-to-annotate (admin only)
        <?php if ($is_admin): ?>
        chart.on('click', function (params) {
            if (params.componentType === 'series' && params.data) {
                var ts = new Date(params.data[0]);
                var note = prompt('<?php echo htmlspecialchars(t('chart_annotation_prompt')); ?>', '');
                if (note) {
                    fetch('api.php?action=save_annotation', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ monitor_id: parseInt(monitorId), metric_key: metricKey, timestamp: ts.toISOString().slice(0, 19).replace('T', ' '), note: note })
                    }).then(function () { load(currentPeriod); });
                }
            }
        });
        <?php endif; ?>

        window.addEventListener('resize', function () { chart.resize(); });
        load('24h');
    })();
    </script>
</body>
</html>
    <?php
    exit;
}

/**
 * Vrátí informace o aktuální verzi aplikace.
 * Ve produkci čte soubor version.php vygenerovaný GitHub Actions deployem.
 * Lokálně (dev) se jako záloha pokusí o git log.
 * @return array ['hash' => '...', 'date' => '...', 'label' => '...']
 */
function get_app_version() {
    static $version = null;
    if ($version !== null) return $version;

    $version_file = __DIR__ . '/version.php';

    // Produkce: version.php vygeneroval GitHub Actions deploy
    if (file_exists($version_file)) {
        require_once $version_file;
        $version = [
            'hash'  => defined('APP_VERSION_HASH')  ? APP_VERSION_HASH  : '?',
            'date'  => defined('APP_VERSION_DATE')  ? APP_VERSION_DATE  : '?',
            'label' => defined('APP_VERSION_LABEL') ? APP_VERSION_LABEL : 'unknown',
        ];
        return $version;
    }

    // Lokální vývoj: záloha přes git log (nevolá se v produkci)
    $hash = '';
    $date = '';
    $git_dir = dirname(__DIR__);
    if (is_dir($git_dir . '/.git')) {
        $hash = @shell_exec("git -C " . escapeshellarg($git_dir) . " log --pretty=format:'%h' -1 2>/dev/null");
        $date = @shell_exec("git -C " . escapeshellarg($git_dir) . " log --pretty=format:'%ci' -1 2>/dev/null");
        $hash = $hash ? trim(str_replace("'", '', $hash)) : '';
        $date = $date ? trim(str_replace("'", '', $date)) : '';
        if ($date) {
            try {
                $dt = new DateTime($date);
                $date = $dt->format('Y-m-d H:i') . ' (local)';
            } catch (Exception $e) {
                $date = substr($date, 0, 16);
            }
        }
    }

    $version = [
        'hash'  => $hash ?: 'dev',
        'date'  => $date ?: date('Y-m-d'),
        'label' => ($hash && $date) ? $date . ' · ' . $hash : 'dev (no version.php)',
    ];
    return $version;
}

/**
 * Vytáhne informace o TLS certifikátu (issuer, CN, SAN, platnost) přes vlastní
 * samostatné spojení - záměrně nesdílí handle s hlavní HTTP kontrolou, aby tato
 * (čistě informativní) fáze nemohla nijak ovlivnit chování/timing check_http().
 * Vrací null při jakémkoli selhání (nehttps cíl, timeout, chyba parsování).
 */
function get_ssl_certificate_info($host, $port = 443, $timeout = 5) {
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]
    ]);

    $stream = @stream_socket_client(
        "ssl://{$host}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$stream) {
        return null;
    }

    $params = stream_context_get_params($stream);
    fclose($stream);

    if (!isset($params['options']['ssl']['peer_certificate'])) {
        return null;
    }

    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    if (!$cert) {
        return null;
    }

    $valid_to = $cert['validTo_time_t'] ?? null;
    $days_remaining = $valid_to !== null ? (int)floor(($valid_to - time()) / 86400) : null;

    $san = [];
    if (!empty($cert['extensions']['subjectAltName'])) {
        foreach (explode(',', $cert['extensions']['subjectAltName']) as $part) {
            $san[] = trim(str_replace('DNS:', '', $part));
        }
    }

    return [
        'issuer' => $cert['issuer']['O'] ?? ($cert['issuer']['CN'] ?? ''),
        'cn' => $cert['subject']['CN'] ?? '',
        'san' => $san,
        'valid_from' => isset($cert['validFrom_time_t']) ? date('c', $cert['validFrom_time_t']) : null,
        'valid_to' => $valid_to !== null ? date('c', $valid_to) : null,
        'days_remaining' => $days_remaining,
        'algo' => $cert['signatureTypeSN'] ?? '',
    ];
}

/**
 * Kontrola HTTP/HTTPS webu
 */
function check_http($url, $timeout = 5, $body_keyword = null) {
    $start = microtime(true);

    $host = parse_url($url, PHP_URL_HOST);
    $has_ipv4 = false;
    $has_ipv6 = false;
    $dns_start = microtime(true);
    $dns_records = ['A' => [], 'AAAA' => []];
    if ($host) {
        $dns_a = @dns_get_record($host, DNS_A);
        $has_ipv4 = !empty($dns_a);
        foreach ((array)$dns_a as $rec) {
            if (!empty($rec['ip'])) $dns_records['A'][] = $rec['ip'];
        }

        $dns_aaaa = @dns_get_record($host, DNS_AAAA);
        $has_ipv6 = !empty($dns_aaaa);
        foreach ((array)$dns_aaaa as $rec) {
            if (!empty($rec['ipv6'])) $dns_records['AAAA'][] = $rec['ipv6'];
        }
    }
    $dns_time_ms = round((microtime(true) - $dns_start) * 1000);

    // Rozpad kontroly na jednotlivé fáze (DNS/TCP/TLS/HTTP/body) pro diagnostický
    // "check pipeline" na detailu monitoru. Nic z tohoto nemá vliv na $status níže -
    // ten určuje výhradně HTTP kód/cURL chyba stejně jako dřív.
    $check_stages = [
        'dns' => [
            'ok' => $host ? ($has_ipv4 || $has_ipv6) : false,
            'time_ms' => $dns_time_ms,
            'records' => $dns_records,
        ],
    ];

    // Zjistíme zda je k dispozici cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');

        $raw_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $primary_ip = curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        $scheme = curl_getinfo($ch, CURLINFO_SCHEME);
        $http_version_raw = curl_getinfo($ch, CURLINFO_HTTP_VERSION);

        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $appconnect_time = curl_getinfo($ch, CURLINFO_APPCONNECT_TIME);
        $starttransfer_time = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $response = $raw_response;
        $response_headers = '';
        if ($raw_response !== false && $header_size > 0) {
            $response_headers = substr($raw_response, 0, $header_size);
            $response = substr($raw_response, $header_size);
        }

        $duration = round((microtime(true) - $start) * 1000);

        $http_version = 'HTTP/1.1';
        if ($http_version_raw === 3) {
            $http_version = 'HTTP/2';
        } elseif ($http_version_raw === 4) {
            $http_version = 'HTTP/3';
        } elseif ($http_version_raw === 1) {
            $http_version = 'HTTP/1.0';
        }

        $conn_details = [
            'has_ipv4' => $has_ipv4,
            'has_ipv6' => $has_ipv6,
            'primary_ip' => $primary_ip ?: '',
            'scheme' => $scheme ?: 'HTTP',
            'http_version' => $http_version
        ];

        $check_stages['tcp'] = [
            'ok' => $response !== false,
            'time_ms' => round($connect_time * 1000),
        ];

        // TLS certifikát jen u úspěšně navázaných https spojení - u nedostupného
        // hostu by samostatný SSL pokus jen zdvojil čekání na timeout zbytečně.
        if ($response !== false && stripos((string)$scheme, 'https') !== false && $host) {
            $tls_port = parse_url($url, PHP_URL_PORT) ?: 443;
            $cert_info = get_ssl_certificate_info($host, $tls_port, min($timeout, 5));
            $check_stages['tls'] = [
                'ok' => $cert_info !== null,
                'time_ms' => round(max(0, $appconnect_time - $connect_time) * 1000),
                'cert' => $cert_info,
            ];
        }

        $parsed_headers = [];
        foreach (explode("\r\n", $response_headers) as $h_line) {
            if (strpos($h_line, ':') === false) continue;
            [$h_key, $h_val] = explode(':', $h_line, 2);
            $parsed_headers[strtolower(trim($h_key))] = trim($h_val);
        }
        $check_stages['http'] = [
            'ok' => $http_code >= 200 && $http_code < 400,
            'time_ms' => round($starttransfer_time * 1000),
            'status_code' => $http_code,
            'headers' => [
                'server' => $parsed_headers['server'] ?? null,
                'cache_control' => $parsed_headers['cache-control'] ?? null,
                'content_encoding' => $parsed_headers['content-encoding'] ?? null,
                'etag' => $parsed_headers['etag'] ?? null,
            ],
        ];

        if ($body_keyword !== null && $body_keyword !== '') {
            $keyword_found = $response !== false && strpos($response, $body_keyword) !== false;
            $check_stages['body'] = [
                'ok' => $keyword_found,
                'time_ms' => round(max(0, $total_time - $starttransfer_time) * 1000),
                'keyword_found' => $keyword_found,
            ];
        }

        $check_stages['total_time_ms'] = round($total_time * 1000);

        if ($response === false) {
            return array_merge([
                'status' => 'down',
                'response_time' => 0,
                'error' => "cURL chyba: " . $error,
                'check_stages' => $check_stages
            ], $conn_details);
        }

        if ($http_code >= 200 && $http_code < 400) {
            return array_merge([
                'status' => 'up',
                'response_time' => $duration,
                'error' => null,
                'check_stages' => $check_stages
            ], $conn_details);
        } else {
            return array_merge([
                'status' => 'down',
                'response_time' => $duration,
                'error' => "HTTP status kód: " . $http_code,
                'check_stages' => $check_stages
            ], $conn_details);
        }
    } else {
        // Fallback na file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        $duration = round((microtime(true) - $start) * 1000);
        
        $conn_details = [
            'has_ipv4' => $has_ipv4,
            'has_ipv6' => $has_ipv6,
            'primary_ip' => $host ? @gethostbyname($host) : '',
            'scheme' => strpos($url, 'https://') === 0 ? 'HTTPS' : 'HTTP',
            'http_version' => 'HTTP/1.1'
        ];
        
        if ($response === false) {
            return array_merge([
                'status' => 'down',
                'response_time' => 0,
                'error' => "Spojení selhalo"
            ], $conn_details);
        }
        
        // Získání HTTP kódu z hlaviček
        $http_code = 200;
        $resp_headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if (!empty($resp_headers[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $resp_headers[0], $matches);
            if (isset($matches[1])) {
                $http_code = (int)$matches[1];
            }
        }
        
        if ($http_code >= 200 && $http_code < 400) {
            return array_merge([
                'status' => 'up',
                'response_time' => $duration,
                'error' => null
            ], $conn_details);
        } else {
            return array_merge([
                'status' => 'down',
                'response_time' => $duration,
                'error' => "HTTP status kód: " . $http_code
            ], $conn_details);
        }
    }
}

/**
 * Zapíše jednu událost do monitor_events (přidání/odebrání monitoru, změna
 * schématu/DNS/certifikátu, připojení/odpojení agenta atd.) - lehký event log,
 * ze kterého čerpá infrastructure report (weekly/monthly digest).
 */
function log_monitor_event($pdo, $monitor_id, $monitor_name, $monitor_type, $event_type, $description = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO monitor_events (monitor_id, monitor_name, monitor_type, event_type, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$monitor_id, $monitor_name, $monitor_type, $event_type, $description]);
    } catch (PDOException $e) {
        // Nezastavovat běh cronu kvůli chybě v logování události
    }
}

/**
 * Porovná aktuální stav 'web' monitoru (schéma, DNS, platnost certifikátu) proti
 * poslednímu uloženému snímku (monitors.config_snapshot) a při změně zapíše
 * událost do monitor_events. Snímek se poté vždy přepíše na aktuální hodnoty
 * (tick/tock porovnání), bez ohledu na to, zda k nějaké změně došlo.
 *
 * Záměrně nesleduje vyjednanou verzi TLS protokolu (1.2 vs 1.3) - PHP/cURL
 * takovou informaci na rozdíl od jiných jazyků nevystavuje (jen interní C API
 * libcurl), takže by šlo jen o odhad, ne o spolehlivá data.
 */
function detect_config_changes($pdo, $monitor, $check_result) {
    if (empty($check_result['check_stages'])) {
        return;
    }
    $stages = $check_result['check_stages'];

    $old_snapshot = json_decode($monitor['config_snapshot'] ?? '', true);
    if (!is_array($old_snapshot)) {
        $old_snapshot = null;
    }

    $new_snapshot = [
        'scheme' => strtolower((string)($check_result['scheme'] ?? '')),
        'dns_ok' => $stages['dns']['ok'] ?? null,
        'cert_valid_to' => $stages['tls']['cert']['valid_to'] ?? null,
    ];

    if ($old_snapshot !== null) {
        // HTTP -> HTTPS
        if ($old_snapshot['scheme'] === 'http' && $new_snapshot['scheme'] === 'https') {
            log_monitor_event($pdo, $monitor['id'], $monitor['name'], $monitor['type'], 'scheme_upgraded', 'HTTP -> HTTPS');
        }

        // DNS ztraceno / obnoveno
        if ($old_snapshot['dns_ok'] === true && $new_snapshot['dns_ok'] === false) {
            log_monitor_event($pdo, $monitor['id'], $monitor['name'], $monitor['type'], 'dns_lost', 'DNS přestalo odpovídat');
        } elseif ($old_snapshot['dns_ok'] === false && $new_snapshot['dns_ok'] === true) {
            log_monitor_event($pdo, $monitor['id'], $monitor['name'], $monitor['type'], 'dns_recovered', 'DNS opět odpovídá');
        }

        // Certifikát obnoven (nová platnost do budoucna, pozdější než ta stará)
        if (!empty($old_snapshot['cert_valid_to']) && !empty($new_snapshot['cert_valid_to'])
            && $new_snapshot['cert_valid_to'] !== $old_snapshot['cert_valid_to']
            && strtotime($new_snapshot['cert_valid_to']) > strtotime($old_snapshot['cert_valid_to'])
        ) {
            log_monitor_event($pdo, $monitor['id'], $monitor['name'], $monitor['type'], 'cert_renewed', 'TLS certifikát obnoven');
        }
    }

    try {
        $stmt = $pdo->prepare("UPDATE monitors SET config_snapshot = ? WHERE id = ?");
        $stmt->execute([json_encode($new_snapshot, JSON_UNESCAPED_UNICODE), $monitor['id']]);
    } catch (PDOException $e) {
        // Ignorujeme
    }
}

/**
 * ICMP ping - vrátí odezvu v ms nebo null při selhání.
 * Používá systémový `ping` s 1 paketem a 2s timeoutem.
 */
function bk_ping_host($host, $timeout_ms = 2000) {
    if (empty($host)) return null;
    $host = escapeshellarg($host);
    $timeout_s = max(1, (int)ceil($timeout_ms / 1000));
    // Linux ping: -c 1 paket, -W timeout ve sekundách
    $cmd = "ping -c 1 -W $timeout_s $host 2>/dev/null";
    $output = @shell_exec($cmd);
    if ($output === null) return null;
    // Parsování "time=1.23 ms" nebo "time=1 ms"
    if (preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $output, $m)) {
        return round((float)$m[1], 1);
    }
    return null;
}

/**
 * Kontrola přes TCP Socket (port check / TCP ping)
 */
function check_socket($host, $port, $timeout = 5) {
    $start = microtime(true);
    // Odstranění protokolu z hostitele, pokud byl zadán
    $host = preg_replace('~^https?://~', '', $host);
    
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $duration = round((microtime(true) - $start) * 1000);
    
    if ($socket) {
        @fclose($socket);
        return [
            'status' => 'up',
            'response_time' => $duration,
            'error' => null
        ];
    } else {
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => "Port $port je zavřený nebo nedostupný: $errstr ($errno)"
        ];
    }
}

/**
 * Minecraft Server Query přes Server List Ping (SLP)
 */
/**
 * Záložní dotaz na Minecraft server přes veřejné API mcsrvstat.us
 */
/**
 * Source RCON protokol (Valve/Source engine RCON - stejný binární protokol
 * používá Minecraft Paper/Spigot i Source-based hry). Nezávislé na
 * konkrétním herním software - jen packet framing (int32 délka/id/typ +
 * null-terminated tělo). Auth -> exec command -> přečti odpověď.
 *
 * @return string|null Text odpovědi na příkaz, nebo null při chybě spojení/autentizace.
 */
function bk_rcon_execute($host, $port, $password, $command, $timeout = 3) {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return null;
    }
    stream_set_timeout($socket, $timeout);

    $send_packet = function($socket, $id, $type, $body) {
        $payload = pack('V', $id) . pack('V', $type) . $body . "\x00\x00";
        return @fwrite($socket, pack('V', strlen($payload)) . $payload);
    };

    $read_packet = function($socket) {
        $len_raw = @fread($socket, 4);
        if ($len_raw === false || strlen($len_raw) < 4) {
            return null;
        }
        $len = unpack('V', $len_raw)[1];
        if ($len < 8 || $len > 1000000) {
            return null; // Nesmyslná délka - poškozená/neplatná odpověď
        }
        $body_raw = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = @fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body_raw .= $chunk;
            $remaining -= strlen($chunk);
        }
        if (strlen($body_raw) < 8) {
            return null;
        }
        $id_unsigned = unpack('V', substr($body_raw, 0, 4))[1];
        $id = $id_unsigned > 0x7FFFFFFF ? $id_unsigned - 0x100000000 : $id_unsigned;
        $type = unpack('V', substr($body_raw, 4, 4))[1];
        return ['id' => $id, 'type' => $type, 'body' => rtrim(substr($body_raw, 8), "\x00")];
    };

    $auth_id = random_int(1, 2147483646);
    $send_packet($socket, $auth_id, 3, $password); // SERVERDATA_AUTH

    // Auth response (typ 2) - někteří servery před ním pošlou i prázdný
    // SERVERDATA_RESPONSE_VALUE paket, proto čteme, dokud typ 2 nedorazí
    // (nebo spojení neskončí).
    $auth_ok = false;
    for ($i = 0; $i < 3; $i++) {
        $resp = $read_packet($socket);
        if ($resp === null) {
            break;
        }
        if ($resp['type'] === 2) {
            $auth_ok = ($resp['id'] === $auth_id); // ID -1 = špatné heslo
            break;
        }
    }
    if (!$auth_ok) {
        @fclose($socket);
        return null;
    }

    $cmd_id = random_int(1, 2147483646);
    $send_packet($socket, $cmd_id, 2, $command); // SERVERDATA_EXECCOMMAND
    $resp = $read_packet($socket);
    @fclose($socket);

    if ($resp === null || $resp['id'] !== $cmd_id) {
        return null;
    }
    return $resp['body'];
}

/**
 * TPS přes Paper/Spigot příkaz "/tps" (RCON) - vanilla tento příkaz nemá.
 * Formát výstupu je u Paperu stabilní už řadu let: "TPS from last 1m, 5m,
 * 15m: X, Y, Z" (obvykle s §-barevnými kódy) - odstraníme barevné kódy
 * (v obou možných kódováních, aby se náhodou nesloučil kód-číslice s reálným
 * číslem, např. "§220.0" by se bez ošetření četlo jako 220.0) a vytáhneme
 * první trojici čísel oddělených čárkou.
 */
function check_minecraft_rcon($host, $rcon_port, $rcon_password, $timeout = 3) {
    if (empty($rcon_port) || empty($rcon_password)) {
        return null;
    }
    $response = bk_rcon_execute($host, (int)$rcon_port, $rcon_password, 'tps', $timeout);
    if ($response === null) {
        return null;
    }
    $clean = preg_replace('/\xC2\xA7[0-9a-fk-or]/i', '', $response);
    $clean = preg_replace('/\xA7[0-9a-fk-or]/i', '', $clean);

    if (!preg_match('/(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)\s*,\s*(\d+(?:\.\d+)?)/', $clean, $m)) {
        return null;
    }
    return [
        'tps_1m' => (float)$m[1],
        'tps_5m' => (float)$m[2],
        'tps_15m' => (float)$m[3],
    ];
}

function check_minecraft_api_fallback($host, $start, $timeout = 3) {
    $url = "https://api.mcsrvstat.us/2/" . urlencode($host);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if ($data && isset($data['online'])) {
            if ($data['online'] === true) {
                $players_online = isset($data['players']['online']) ? (int)$data['players']['online'] : 0;
                $players_max = isset($data['players']['max']) ? (int)$data['players']['max'] : 20;
                $version = isset($data['version']) ? $data['version'] : '';
                if (isset($data['software'])) {
                    $version = $data['software'] . ' ' . $version;
                }
                $players_list = isset($data['players']['list']) ? $data['players']['list'] : [];
                $motd = '';
                if (isset($data['motd']['clean']) && is_array($data['motd']['clean'])) {
                    $motd = implode("\n", $data['motd']['clean']);
                }
                return [
                    'status' => 'up',
                    'response_time' => round((microtime(true) - $start) * 1000),
                    'error' => null,
                    'players_online' => $players_online,
                    'players_max' => $players_max,
                    'version' => $version,
                    'players_list' => $players_list,
                    'motd' => $motd,
                    'api_fallback' => true
                ];
            } else {
                return [
                    'status' => 'down',
                    'response_time' => 0,
                    'error' => 'Minecraft server je podle API vypnutý.',
                    'players_online' => 0,
                    'players_max' => 0
                ];
            }
        }
    }
    return null;
}

/**
 * Blood Kings Status - Minecraft SLP kontrola s jedním rychlým opakováním
 *
 * Krátký timeout a čtení odpovědi po jednotlivých bytech dělá jednorázový
 * pokus náchylný na běžné síťové zádrhele (server odpoví o zlomek sekundy
 * později, než limit dovolí) - proto se před přechodem na fallback API a
 * případným nahlášením výpadku zkusí spojení ještě jednou.
 */
function check_minecraft($host, $port = 25565, $timeout = 3, $rcon_port = null, $rcon_password = null) {
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);

    // Rozdělení hostitele a portu, pokud je zadáno jako host:port
    $parts = explode(':', $host);
    if (count($parts) === 2) {
        $host = $parts[0];
        $port = intval($parts[1]);
    }

    $result = check_minecraft_slp_attempt($host, $port, $timeout, $start);
    if ($result === null) {
        // Krátká prodleva a druhý pokus - odchytí přechodné výpadky/zpoždění
        usleep(300000); // 0.3 s
        $result = check_minecraft_slp_attempt($host, $port, $timeout, $start);
    }
    if ($result !== null) {
        // TPS přes RCON je volitelné (Paper/Spigot only) a nikdy nesmí
        // rozbít základní SLP kontrolu - best-effort, tiše se přeskočí,
        // pokud RCON není nakonfigurovaný nebo selže.
        if ($result['status'] === 'up' && !empty($rcon_port) && !empty($rcon_password)) {
            $tps = check_minecraft_rcon($host, $rcon_port, $rcon_password, $timeout);
            if ($tps !== null) {
                $result = array_merge($result, $tps);
            }
        }
        return $result;
    }

    $fb = check_minecraft_api_fallback($host, $start, $timeout);
    if ($fb) return $fb;

    return [
        'status' => 'down',
        'response_time' => 0,
        'error' => 'Prázdná odpověď od MC serveru (timeout nebo nepodporovaný protokol), i po opakovaném pokusu.',
        'players_online' => 0,
        'players_max' => 0
    ];
}

/**
 * Jeden pokus o SLP handshake. Vrací null při selhání spojení/čtení
 * (volající pak zkusí znovu nebo přejde na fallback API), jinak vrací
 * hotové pole výsledku (status up i down - down se vrací jen v případech,
 * kde je odpověď jednoznačně platná, ale zjevně chybná, např. neplatné ID paketu).
 */
function check_minecraft_slp_attempt($host, $port, $timeout, $start) {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return null;
    }

    stream_set_timeout($socket, $timeout);

    // Minecraft SLP handshake protocol (1.7+)
    $packVarInt = function($value) {
        $string = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value > 0) {
                $byte |= 0x80;
            }
            $string .= chr($byte);
        } while ($value > 0);
        return $string;
    };

    // Handshake Packet
    $handshakePayload = $packVarInt(47) // Protocol version
                      . $packVarInt(strlen($host)) . $host
                      . pack('n', $port) // port
                      . $packVarInt(1); // Next state (1 = status)
    $handshakePacket = $packVarInt(strlen($handshakePayload) + 1)
                     . $packVarInt(0x00) // Packet ID (0)
                     . $handshakePayload;

    // Request Packet
    $requestPacket = $packVarInt(1) . $packVarInt(0x00);

    @fwrite($socket, $handshakePacket);
    @fwrite($socket, $requestPacket);

    // Read response length (VarInt)
    $readVarInt = function($socket) {
        $value = 0;
        $i = 0;
        do {
            $byte = @fread($socket, 1);
            if ($byte === false || strlen($byte) === 0) return false;
            $byteVal = ord($byte);
            $value |= ($byteVal & 0x7F) << ($i * 7);
            $i++;
            if ($i > 5) return false;
        } while (($byteVal & 0x80) != 0);
        return $value;
    };

    $packetLength = $readVarInt($socket);
    if ($packetLength === false) {
        // Nejde odlišit "server neběží" od "byte dorazil o zlomek sekundy později" -
        // necháváme volajícího zkusit znovu, než se sáhne po fallback API.
        @fclose($socket);
        return null;
    }

    $packetId = $readVarInt($socket);
    if ($packetId !== 0x00) {
        // Tady server reálně odpověděl, jen jiným ID paketu - opakování by
        // nepomohlo, jde o skutečný nesoulad protokolu/portu.
        @fclose($socket);
        $fb = check_minecraft_api_fallback($host, $start, $timeout);
        if ($fb) return $fb;

        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => 'Neočekávané ID paketu od MC serveru',
            'players_online' => 0,
            'players_max' => 0
        ];
    }

    $stringLength = $readVarInt($socket);
    if ($stringLength === false || $stringLength <= 0) {
        @fclose($socket);
        return null;
    }

    $jsonData = '';
    $bytesRemaining = $stringLength;
    while ($bytesRemaining > 0 && !feof($socket)) {
        $chunk = @fread($socket, min($bytesRemaining, 4096));
        if ($chunk === false) break;
        $jsonData .= $chunk;
        $bytesRemaining -= strlen($chunk);
    }
    @fclose($socket);

    $data = json_decode($jsonData, true);
    $duration = round((microtime(true) - $start) * 1000);

    if (!$data) {
        $fb = check_minecraft_api_fallback($host, $start, $timeout);
        if ($fb) return $fb;
        
        return [
            'status' => 'up',
            'response_time' => $duration,
            'error' => 'Nelze dekódovat JSON stav Minecraft serveru',
            'players_online' => 0,
            'players_max' => 0
        ];
    }

    $playersOnline = isset($data['players']['online']) ? (int)$data['players']['online'] : 0;
    $playersMax = isset($data['players']['max']) ? (int)$data['players']['max'] : 0;
    $version = isset($data['version']['name']) ? $data['version']['name'] : 'Neznámá';
    
    // Získání seznamu hráčů
    $playersList = [];
    if (isset($data['players']['sample']) && is_array($data['players']['sample'])) {
        foreach ($data['players']['sample'] as $p) {
            if (isset($p['name'])) {
                $playersList[] = $p['name'];
            }
        }
    }
    
    // Získání a očistění MOTD
    $motd = '';
    if (isset($data['description'])) {
        if (is_string($data['description'])) {
            $motd = $data['description'];
        } elseif (isset($data['description']['text'])) {
            $motd = $data['description']['text'];
        } elseif (isset($data['description']['extra']) && is_array($data['description']['extra'])) {
            foreach ($data['description']['extra'] as $el) {
                if (isset($el['text'])) {
                    $motd .= $el['text'];
                }
            }
        }
        $motd = preg_replace('/§[0-9a-fk-orx]/i', '', $motd);
        $motd = trim($motd);
    }

    return [
        'status' => 'up',
        'response_time' => $duration,
        'error' => null,
        'players_online' => $playersOnline,
        'players_max' => $playersMax,
        'version' => $version,
        'players_list' => $playersList,
        'motd' => $motd,
        'api_fallback' => false
    ];
}

/**
 * TeamSpeak 3 Query Port Check
 */
/**
 * ==== TeamSpeak ServerQuery helpers ====
 */

/**
 * Dekóduje TS3 ServerQuery escape sekvence v přijaté hodnotě (plná tabulka -
 * dřívější verze řešila jen \s, \/, \p, což stačilo na pár polí serverinfo,
 * ale u jmen kanálů/klientů je potřeba kompletní sada).
 */
function bk_ts3_escape_decode($value) {
    static $map = null;
    if ($map === null) {
        $map = [
            '\\\\' => '\\', '\\/' => '/', '\\s' => ' ', '\\p' => '|',
            '\\a' => "\x07", '\\b' => "\x08", '\\f' => "\x0C",
            '\\n' => "\x0A", '\\r' => "\x0D", '\\t' => "\x09", '\\v' => "\x0B",
        ];
    }
    return strtr($value, $map);
}

/**
 * Zakóduje hodnotu pro odeslání v ServerQuery příkazu (opak bk_ts3_escape_decode) -
 * potřeba např. pro přihlašovací jméno/heslo, pokud obsahují mezery nebo jiné znaky.
 */
function bk_ts3_escape_encode($value) {
    static $map = null;
    if ($map === null) {
        $map = [
            '\\' => '\\\\', '/' => '\\/', ' ' => '\\s', '|' => '\\p',
            "\x07" => '\\a', "\x08" => '\\b', "\x0C" => '\\f',
            "\x0A" => '\\n', "\x0D" => '\\r', "\x09" => '\\t', "\x0B" => '\\v',
        ];
    }
    return strtr($value, $map);
}

/**
 * Pošle ServerQuery příkaz a čte odpověď, dokud se neobjeví ukončovací
 * "error id=..." řádek (nebo dokud nevyprší bezpečnostní limity).
 */
function bk_ts3_send_command($socket, $command, $max_bytes = 65536, $max_seconds = 5) {
    @fwrite($socket, $command . "\n");
    $response = '';
    $start = microtime(true);
    while (strpos($response, 'error id=') === false) {
        $chunk = @fgets($socket, 4096);
        if ($chunk === false) break;
        $response .= $chunk;
        if (strlen($response) > $max_bytes) break;
        if ((microtime(true) - $start) > $max_seconds) break;
    }
    return $response;
}

/**
 * Vytáhne číselný "error id=" z odpovědi ServerQuery. Vrací null, pokud chybí
 * (spojení spadlo dřív, než přišla ukončovací hláška).
 */
function bk_ts3_parse_error_id($response) {
    if (preg_match('/error id=(\d+)/', $response, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Rozparsuje jednořádkovou odpověď typu "klic=hodnota klic2=hodnota2 ..." (např.
 * serverinfo) do asociativního pole, s plným escape dekódováním hodnot.
 */
function bk_ts3_parse_kv_line($line) {
    $details = [];
    $line = rtrim((string)$line, "\r\n");
    foreach (explode(' ', $line) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $details[$kv[0]] = bk_ts3_escape_decode($kv[1]);
        }
    }
    return $details;
}

/**
 * Rozparsuje seznamovou odpověď (channellist/clientlist/servergrouplist) - záznamy
 * oddělené "|", v každém záznamu klic=hodnota páry oddělené mezerou. Ukončovací
 * "error id=..." řádek se odřízne, ne je součástí posledního záznamu.
 */
function bk_ts3_parse_list_response($response) {
    $err_pos = strrpos($response, 'error id=');
    $body = $err_pos !== false ? substr($response, 0, $err_pos) : $response;
    $body = trim($body);
    if ($body === '') {
        return [];
    }

    $records = [];
    foreach (explode('|', $body) as $record_str) {
        $record_str = trim($record_str);
        if ($record_str === '') continue;
        $record = bk_ts3_parse_kv_line($record_str);
        if (!empty($record)) {
            $records[] = $record;
        }
    }
    return $records;
}

/**
 * Rychlá TCP kontrola ServerQuery a FileTransfer portů. Voice port (výchozí 9987)
 * je UDP a nelze ho stejným způsobem "connect probovat" - jeho stav je jen odvozený
 * z úspěšného serverinfo výše, proto má 'ok' => null (nezávisle neověřeno).
 */
function check_ts3_ports($host, $query_port, $filetransfer_port, $timeout = 2) {
    $ft_ok = false;
    $ft_socket = @fsockopen($host, $filetransfer_port, $errno, $errstr, min($timeout, 3));
    if ($ft_socket) {
        $ft_ok = true;
        @fclose($ft_socket);
    }
    return [
        'query' => ['ok' => true, 'port' => $query_port],
        'filetransfer' => ['ok' => $ft_ok, 'port' => $filetransfer_port],
        'voice' => ['ok' => null, 'note' => 'odvozeno z úspěšného serverinfo - UDP nelze nezávisle TCP-probovat'],
    ];
}

/**
 * Aproximace kvality hlasového spojení z jitteru (směrodatné odchylky) posledních
 * ServerQuery TCP odezev za poslední hodinu. NENÍ to skutečné měření hlasového
 * (UDP) packet loss - to z PHP na sdíleném hostingu spolehlivě neumíme změřit -
 * jde jen o proxy signál "jak stabilní je spojení k serveru v poslední době".
 */
function bk_ts3_voice_quality($pdo, $monitor_id) {
    $stmt = $pdo->prepare("
        SELECT response_time FROM monitor_logs
        WHERE monitor_id = ? AND status = 'up' AND response_time > 0
              AND checked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ORDER BY checked_at DESC LIMIT 30
    ");
    $stmt->execute([$monitor_id]);
    $samples = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($samples) < 3) {
        return ['band' => null, 'jitter_ms' => null, 'sample_count' => count($samples)];
    }

    $mean = array_sum($samples) / count($samples);
    $variance = 0.0;
    foreach ($samples as $s) {
        $variance += ($s - $mean) ** 2;
    }
    $variance /= count($samples);
    $jitter = sqrt($variance);

    if ($jitter < 5) {
        $band = 'Excellent';
    } elseif ($jitter < 15) {
        $band = 'Good';
    } elseif ($jitter < 40) {
        $band = 'Fair';
    } else {
        $band = 'Poor';
    }

    return ['band' => $band, 'jitter_ms' => round($jitter, 1), 'sample_count' => count($samples)];
}

/**
 * Obecný vážený Health Score kalkulátor - typově agnostický, použitelný pro
 * jakýkoli budoucí Service Profile, ne jen TeamSpeak. $areas je pole
 * [['label'=>, 'weight_pct'=>, 'score_pct'=>0-100, 'status'=>'ok'|'warn'|'fail'|'na'], ...].
 * Oblasti se status='na' (nelze změřit - typicky chybí propojený agent) se z
 * výpočtu vynechají a jejich váha se poměrně přerozdělí mezi měřitelné oblasti,
 * místo aby uměle doplňovaly 100 % nebo nespravedlivě strhávaly skóre na 0.
 */
function bk_compute_health_score(array $areas) {
    $weighted_sum = 0.0;
    $available_weight = 0.0;
    foreach ($areas as $area) {
        if (($area['status'] ?? '') === 'na') {
            continue;
        }
        $weight = (float)($area['weight_pct'] ?? 0);
        $score_pct = (float)($area['score_pct'] ?? 0);
        $weighted_sum += ($weight * $score_pct) / 100;
        $available_weight += $weight;
    }
    $score = $available_weight > 0 ? (int)round(($weighted_sum / $available_weight) * 100) : 0;
    return ['score' => $score, 'areas' => $areas];
}



/**
 * Sestaví 7 vážených oblastí Health Score pro TeamSpeak monitor:
 * Dostupnost 35 % / Proces 20 % / ServerQuery 15 % / Porty 10 % / Výkon VPS 10 % /
 * Klienti-limity 5 % / Verze 5 %. $agent_data je dekódovaný monitors.last_details
 * (obsahuje cpu/ram a případně ts3_process, pokud je na VPS propojený agent),
 * $check_stages je dekódovaný monitor_logs.check_stages z posledního běhu.
 */
function build_teamspeak_health_areas($monitor, $current_status, $check_stages, $agent_data) {
    $areas = [];

    // Dostupnost (35 %)
    $avail_ok = $current_status === 'up';
    $areas[] = ['key' => 'availability', 'label' => 'Dostupnost', 'weight_pct' => 35, 'score_pct' => $avail_ok ? 100 : 0, 'status' => $avail_ok ? 'ok' : 'fail'];

    // TeamSpeak proces (20 %) - jen pokud je agent propojený a hlásí ts3_process
    if (is_array($agent_data) && isset($agent_data['ts3_process']) && is_array($agent_data['ts3_process'])) {
        $areas[] = ['key' => 'process', 'label' => 'TeamSpeak proces', 'weight_pct' => 20, 'score_pct' => 100, 'status' => 'ok'];
    } elseif (is_array($agent_data) && !empty($agent_data['cpu'])) {
        // Agent je propojený, ale proces ts3server nenašel
        $areas[] = ['key' => 'process', 'label' => 'TeamSpeak proces', 'weight_pct' => 20, 'score_pct' => 0, 'status' => 'fail'];
    } else {
        $areas[] = ['key' => 'process', 'label' => 'TeamSpeak proces', 'weight_pct' => 20, 'score_pct' => 0, 'status' => 'na'];
    }

    // ServerQuery (15 %)
    if (is_array($check_stages) && isset($check_stages['query']['ok'])) {
        $sq_ok = (bool)$check_stages['query']['ok'];
        $areas[] = ['key' => 'serverquery', 'label' => 'ServerQuery', 'weight_pct' => 15, 'score_pct' => $sq_ok ? 100 : 0, 'status' => $sq_ok ? 'ok' : 'fail'];
    } else {
        $areas[] = ['key' => 'serverquery', 'label' => 'ServerQuery', 'weight_pct' => 15, 'score_pct' => 0, 'status' => 'na'];
    }

    // Porty (10 %) - voice port se do poměru nepočítá (nezávisle neověřený, ok=null)
    if (is_array($check_stages) && isset($check_stages['ports']) && is_array($check_stages['ports'])) {
        $port_total = 0;
        $port_ok = 0;
        foreach ($check_stages['ports'] as $p) {
            if (!isset($p['ok']) || $p['ok'] === null) continue;
            $port_total++;
            if ($p['ok']) $port_ok++;
        }
        $port_score = $port_total > 0 ? ($port_ok / $port_total) * 100 : 0;
        $port_status = $port_total === 0 ? 'na' : ($port_score >= 100 ? 'ok' : 'warn');
        $areas[] = ['key' => 'ports', 'label' => 'Porty', 'weight_pct' => 10, 'score_pct' => $port_score, 'status' => $port_status];
    } else {
        $areas[] = ['key' => 'ports', 'label' => 'Porty', 'weight_pct' => 10, 'score_pct' => 0, 'status' => 'na'];
    }

    // Výkon VPS (10 %) - jen pokud agent hlásí cpu/ram
    if (is_array($agent_data) && isset($agent_data['cpu'], $agent_data['ram'])) {
        $cpu_threshold = (float)($monitor['cpu_threshold'] ?? 90);
        $ram_threshold = (float)($monitor['ram_threshold'] ?? 95);
        $cpu_ok = (float)$agent_data['cpu'] < $cpu_threshold;
        $ram_ok = (float)$agent_data['ram'] < $ram_threshold;
        $perf_score = ($cpu_ok && $ram_ok) ? 100 : (($cpu_ok || $ram_ok) ? 60 : 20);
        $areas[] = ['key' => 'vps', 'label' => 'Výkon VPS', 'weight_pct' => 10, 'score_pct' => $perf_score, 'status' => $perf_score >= 100 ? 'ok' : 'warn'];
    } else {
        $areas[] = ['key' => 'vps', 'label' => 'Výkon VPS', 'weight_pct' => 10, 'score_pct' => 0, 'status' => 'na'];
    }

    // Počet klientů / limity (5 %)
    $slot_pct = $check_stages['service']['slot_usage_pct'] ?? null;
    if ($slot_pct !== null) {
        $clients_score = $slot_pct < 90 ? 100 : ($slot_pct < 100 ? 60 : 20);
        $areas[] = ['key' => 'clients', 'label' => 'Klienti / limity', 'weight_pct' => 5, 'score_pct' => $clients_score, 'status' => $clients_score >= 100 ? 'ok' : 'warn'];
    } else {
        $areas[] = ['key' => 'clients', 'label' => 'Klienti / limity', 'weight_pct' => 5, 'score_pct' => 0, 'status' => 'na'];
    }

    // Verze (5 %) - jen pokud je ručně vyplněná "poslední známá verze" v nastavení
    $latest_version = trim((string)get_setting('ts3_latest_version', ''));
    $current_version = is_array($check_stages) ? (string)($check_stages['version'] ?? '') : '';
    if ($latest_version !== '' && $current_version !== '') {
        $up_to_date = version_compare($current_version, $latest_version, '>=');
        $areas[] = ['key' => 'version', 'label' => 'Verze', 'weight_pct' => 5, 'score_pct' => $up_to_date ? 100 : 70, 'status' => $up_to_date ? 'ok' : 'warn'];
    } else {
        $areas[] = ['key' => 'version', 'label' => 'Verze', 'weight_pct' => 5, 'score_pct' => 0, 'status' => 'na'];
    }

    return $areas;
}

/**
 * Registr Service Profiles - label/ikona/health-score funkce podle typu monitoru.
 * Zatím jen 'teamspeak' má reálnou implementaci; tvar registru je to, co dělá z
 * jednorázového TeamSpeak Health Score obecný framework - přidání dalšího typu
 * (web/minecraft/...) v budoucnu znamená jeden nový záznam, ne přepis.
 */
/**
 * Registr Service Profiles - pro každý typ monitoru definuje popisek/ikonu pro
 * vizuální picker v admin.php a (u typů, které to podporují) seznam
 * togglovatelných sekcí dashboardu. Typy bez klíče 'metrics' nemají v adminu
 * checklist a jejich dashboard se negatuje - zobrazuje se vše jako dosud
 * (viz bk_get_enabled_metrics()).
 */
function get_service_profiles() {
    return [
        'web' => [
            'label' => t('profile_label_web'),
            'icon' => 'fa-globe',
            'metrics' => [
                ['key' => 'check_pipeline', 'label' => t('metric_label_check_pipeline'), 'recommended' => true],
                ['key' => 'response_breakdown', 'label' => t('metric_label_response_breakdown'), 'recommended' => true],
                ['key' => 'ssl_card', 'label' => t('metric_label_ssl_card'), 'recommended' => true],
                ['key' => 'headers', 'label' => t('metric_label_headers'), 'recommended' => false],
            ],
        ],
        'port' => [
            'label' => t('profile_label_port'),
            'icon' => 'fa-network-wired',
        ],
        'vps' => [
            'label' => t('profile_label_vps'),
            'icon' => 'fa-server',
        ],
        'minecraft' => [
            'label' => t('profile_label_minecraft'),
            'icon' => 'fa-cubes',
        ],
        'teamspeak' => [
            'label' => t('profile_label_teamspeak'),
            'icon' => 'fa-headset',
            'health_score_fn' => 'build_teamspeak_health_areas',
            'metrics' => [
                ['key' => 'health_score', 'label' => t('metric_label_health_score'), 'recommended' => true],
                ['key' => 'process', 'label' => t('metric_label_process'), 'recommended' => true],
                ['key' => 'service', 'label' => t('metric_label_service'), 'recommended' => true],
                ['key' => 'clients_chart', 'label' => t('metric_label_clients_chart'), 'recommended' => true],
                ['key' => 'quality', 'label' => t('metric_label_quality'), 'recommended' => false],
                ['key' => 'ports', 'label' => t('metric_label_ports'), 'recommended' => false],
                ['key' => 'license_version', 'label' => t('metric_label_license_version'), 'recommended' => false],
            ],
        ],
        'discord' => [
            'label' => t('profile_label_discord'),
            'icon' => 'fa-discord',
        ],
        'openwrt' => [
            'label' => t('profile_label_openwrt'),
            'icon' => 'fa-wifi',
        ],
    ];
}

/**
 * Vrátí pole klíčů zapnutých metrik pro daný monitor, nebo NULL pokud se pro
 * jeho typ gating neprovádí (typ bez 'metrics' v get_service_profiles() -
 * dashboard se chová jako dřív, zobrazuje vše). Volající vždy kontrolují
 * `$enabled_metrics === null || in_array('klíč', $enabled_metrics)`.
 */
/**
 * Metriky a prahy z presetu přiřazeného monitoru.
 *
 * Preset je pojmenovaná sada „co se u téhle služby zobrazuje a kdy je to
 * problém". Monitor bez presetu funguje jako dřív (doporučené metriky
 * profilu + vlastní prahy), takže zavedení presetů nic nerozbije.
 *
 * @return array|null ['metrics' => string[]|null, 'cpu' => ?int, 'ram' => ?int, 'hdd' => ?int]
 */
function bk_get_preset($pdo, $preset_id): ?array {
    $preset_id = (int)$preset_id;
    if ($preset_id <= 0) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($preset_id, $cache)) {
        return $cache[$preset_id];
    }
    try {
        $stmt = $pdo->prepare("SELECT metrics, cpu_threshold, ram_threshold, hdd_threshold FROM metric_presets WHERE id = ?");
        $stmt->execute([$preset_id]);
        $row = $stmt->fetch();
        if (!$row) {
            return $cache[$preset_id] = null;
        }
        $metrics = json_decode($row['metrics'] ?? '', true);
        return $cache[$preset_id] = [
            'metrics' => is_array($metrics) ? $metrics : null,
            // Prahy jsou volitelné - preset může řešit jen sadu metrik.
            'cpu' => $row['cpu_threshold'] !== null ? (int)$row['cpu_threshold'] : null,
            'ram' => $row['ram_threshold'] !== null ? (int)$row['ram_threshold'] : null,
            'hdd' => $row['hdd_threshold'] !== null ? (int)$row['hdd_threshold'] : null,
        ];
    } catch (Throwable $e) {
        // Bez tabulky (stará DB) se preset prostě neuplatní.
        return $cache[$preset_id] = null;
    }
}

/**
 * Účinný práh pro metriku: preset má přednost před hodnotou na monitoru.
 *
 * Vrací null, když není nastaveno ani jedno - volající pak práh neuplatní
 * místo toho, aby si vymyslel výchozí číslo.
 */
function bk_effective_threshold(?array $preset, $monitor_value, string $key): ?int {
    if ($preset !== null && $preset[$key] !== null) {
        return $preset[$key];
    }
    return $monitor_value !== null && $monitor_value !== '' ? (int)$monitor_value : null;
}

function bk_get_enabled_metrics($monitor, $pdo = null) {
    $profile = get_service_profiles()[$monitor['type'] ?? ''] ?? null;
    if (!$profile || empty($profile['metrics'])) {
        return null;
    }
    // Preset (pokud je přiřazený) přebíjí sadu uloženou u monitoru - to je
    // celý smysl presetu: změna na jednom místě se projeví všude.
    if ($pdo !== null && !empty($monitor['preset_id'])) {
        $preset = bk_get_preset($pdo, $monitor['preset_id']);
        if ($preset !== null && is_array($preset['metrics']) && !empty($preset['metrics'])) {
            return $preset['metrics'];
        }
    }
    $stored = json_decode($monitor['enabled_metrics'] ?? '', true);
    if (is_array($stored) && !empty($stored)) {
        return $stored;
    }
    // Nic explicitně uloženo (nový/needitovaný monitor) - použijí se recommended
    // výchozí hodnoty, které přesně odpovídají tomu, co se dnes vždy zobrazuje.
    return array_column(array_filter($profile['metrics'], fn($m) => !empty($m['recommended'])), 'key');
}

/**
 * Kontrola TeamSpeak serveru přes ServerQuery. Základní anonymní sekvence
 * (use + serverinfo) je záměrně beze změny oproti dřívější verzi - je to
 * produkční kontrola běžící každou 1-5 minutu, nic navíc ji nesmí nově shodit.
 * Nové věci (přihlášení, channely, server groups, hlasová aktivita, porty) jsou
 * čistě přídavné a jejich případné selhání (chybějící oprávnění, chybějící
 * přihlašovací údaje) nikdy nemění výsledné 'status'.
 */

function check_teamspeak($host, $port = 10011, $timeout = 3, $sq_username = null, $sq_password = null, $filetransfer_port = null) {
    // Rozdělení voice portu a query portu (např. host:voice_port)
    $voice_port = 9987;
    $parts = explode(':', $host);
    if (count($parts) === 2) {
        $host = $parts[0];
        $voice_port = intval($parts[1]);
    }
    if (!$filetransfer_port) {
        $filetransfer_port = 30033;
    }

    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);

    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $duration = round((microtime(true) - $start) * 1000);

    $connected_ip = '';
    $ip_version = 'IPv4';
    if ($socket) {
        $remote_name = @stream_socket_get_name($socket, true);
        if ($remote_name) {
            $last_colon = strrpos($remote_name, ':');
            if ($last_colon !== false) {
                $connected_ip = substr($remote_name, 0, $last_colon);
                $connected_ip = trim($connected_ip, '[]');
            }
            if (strpos($connected_ip, ':') !== false) {
                $ip_version = 'IPv6';
            }
        }
    }
    if (!$socket) {
        $server_ip = $_SERVER['SERVER_ADDR'] ?? null;
        if (!$server_ip && function_exists('gethostname')) {
            $server_ip = @gethostbyname(@gethostname());
        }
        if (!$server_ip || $server_ip === '127.0.0.1') {
            $server_ip = 'IP vašeho webhostingu';
        }
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => "TS3 Query port ($port) nedostupný: $errstr ($errno). Tip: Ujistěte se, že váš VPS neblokuje IP adresu webhostingu ($server_ip) ve svém firewallu nebo v souboru query_ip_whitelist.txt."
        ];
    }

    stream_set_timeout($socket, $timeout);

    // Čtení úvodního pozdravu ze ServerQuery (přesně 2 řádky: TS3 a Welcome zpráva)
    $greeting = '';
    $line1 = @fgets($socket, 256);
    $line2 = @fgets($socket, 256);
    if ($line1 !== false) $greeting .= $line1;
    if ($line2 !== false) $greeting .= $line2;

    if (strpos($greeting, 'TS3') === false && strpos($greeting, 'Welcome') === false) {
        @fclose($socket);
        $visible_greeting = !empty(trim($greeting)) ? '"' . trim(substr($greeting, 0, 50)) . '"' : 'žádná odezva (prázdná)';
        return [
            'status' => 'down',
            'response_time' => $duration,
            'error' => "Chyba komunikace s TS3 ServerQuery (přijatá data: $visible_greeting). Ujistěte se, že IP adresa webhostingu je přidána v query_ip_whitelist.txt na VPS."
        ];
    }

    $query_start = microtime(true);

    // Zvolíme virtuální server na hlasovém portu
    @fwrite($socket, "use port=$voice_port\n");
    $use_resp = @fgets($socket, 256);

    if ($use_resp && strpos($use_resp, 'error id=0') === false) {
        // Pokud zadaný voice port neexistuje nebo je neplatný, automaticky detekujeme port přes serverlist
        @fwrite($socket, "serverlist\n");
        $s_list = @fgets($socket, 4096);
        if ($s_list && preg_match('/virtualserver_port=(\d+)/', $s_list, $m_port)) {
            $voice_port = (int)$m_port[1];
            @fwrite($socket, "use port=$voice_port\n");
            @fgets($socket, 256);
        }
    }

    // Dotaz na info o serveru (nezměněno - toto je baseline, na kterém stojí up/down)
    @fwrite($socket, "serverinfo\n");
    $info = @fgets($socket, 4096);

    if (!$info || strpos($info, 'virtualserver_clientsonline') === false) {
        @fwrite($socket, "quit\n");
        @fclose($socket);
        $error_detail = 'Spojení navázáno, ale nepodařilo se načíst detaily z Query portu';
        if ($info) {
            $error_detail .= ' (Odpověď serveru: ' . trim($info) . ')';
        }
        $server_ip = $_SERVER['SERVER_ADDR'] ?? null;
        if (!$server_ip && function_exists('gethostname')) {
            $server_ip = @gethostbyname(@gethostname());
        }
        if (!$server_ip || $server_ip === '127.0.0.1') {
            $server_ip = 'IP vašeho webhostingu';
        }
        $error_detail .= ". Tip: Pokud vidíte chybu 'flooding', přidejte IP webhostingu ($server_ip) do souboru query_ip_whitelist.txt na vašem TS3 VPS.";
        return [
            'status' => 'up',
            'response_time' => $duration,
            'error' => $error_detail
        ];
    }

    $details = bk_ts3_parse_kv_line($info);
    // ServerQuery hlásí skutečný filetransfer port přímo v serverinfo - použijeme
    // ho místo ručně nastavené hodnoty, pokud je k dispozici (server se nemůže mýlit
    // sám o sobě, na rozdíl od ručně vyplněného pole v administraci).
    if (isset($details['virtualserver_filetransfer_port']) && (int)$details['virtualserver_filetransfer_port'] > 0) {
        $filetransfer_port = (int)$details['virtualserver_filetransfer_port'];
    }
    $clients_online = isset($details['virtualserver_clientsonline']) ? (int)$details['virtualserver_clientsonline'] : 0;
    $query_clients = isset($details['virtualserver_queryclientsonline']) ? (int)$details['virtualserver_queryclientsonline'] : 0;
    $clients_max = isset($details['virtualserver_maxclients']) ? (int)$details['virtualserver_maxclients'] : 0;
    $real_clients_online = max(0, $clients_online - $query_clients);

    // --- Od tady jsou to čistě přídavné dotazy (check pipeline) - nic z tohoto
    // --- nemůže shodit status určený serverinfo výše. ---
    $query_steps = ['serverinfo' => true];
    $authenticated = false;

    if (!empty($sq_username) && !empty($sq_password)) {
        $login_cmd = 'login client_login_name=' . bk_ts3_escape_encode($sq_username)
            . ' client_login_password=' . bk_ts3_escape_encode($sq_password);
        $login_resp = bk_ts3_send_command($socket, $login_cmd);
        $authenticated = (bk_ts3_parse_error_id($login_resp) === 0);
        $query_steps['login'] = $authenticated;
        if ($authenticated) {
            // Po přihlášení je nutné virtuální server vybrat znovu (ServerQuery to vyžaduje)
            bk_ts3_send_command($socket, "use port=$voice_port");
        }
    }

    $channel_count = null;
    $channellist_resp = bk_ts3_send_command($socket, 'channellist');
    $channellist_ok = (bk_ts3_parse_error_id($channellist_resp) === 0);
    $query_steps['channellist'] = $channellist_ok;
    if ($channellist_ok) {
        $channel_count = count(bk_ts3_parse_list_response($channellist_resp));
    }

    $query_client_count = null;
    $active_channel_count = null;
    $voice_activity = null;
    $clientlist_cmd = $authenticated ? 'clientlist -voice -away' : 'clientlist';
    $clientlist_resp = bk_ts3_send_command($socket, $clientlist_cmd);
    $clientlist_ok = (bk_ts3_parse_error_id($clientlist_resp) === 0);
    $query_steps['clientlist'] = $clientlist_ok;
    if ($clientlist_ok) {
        $clients = bk_ts3_parse_list_response($clientlist_resp);
        $query_client_count = 0;
        $active_cids = [];
        $talking = $away = $muted = $recording = 0;
        foreach ($clients as $c) {
            $is_query_client = ($c['client_type'] ?? '0') === '1';
            if ($is_query_client) {
                $query_client_count++;
                continue;
            }
            if (isset($c['cid'])) {
                $active_cids[$c['cid']] = true;
            }
            if ($authenticated) {
                if (($c['client_flag_talking'] ?? '0') === '1') $talking++;
                if (($c['client_away'] ?? '0') === '1') $away++;
                if (($c['client_input_muted'] ?? '0') === '1' || ($c['client_output_muted'] ?? '0') === '1') $muted++;
                if (($c['client_is_recording'] ?? '0') === '1') $recording++;
            }
        }
        $active_channel_count = count($active_cids);
        if ($authenticated) {
            $voice_activity = ['talking' => $talking, 'away' => $away, 'muted' => $muted, 'recording' => $recording];
        }
    }

    $server_group_count = null;
    if ($authenticated) {
        $sg_resp = bk_ts3_send_command($socket, 'servergrouplist');
        $sg_ok = (bk_ts3_parse_error_id($sg_resp) === 0);
        $query_steps['servergrouplist'] = $sg_ok;
        if ($sg_ok) {
            $server_group_count = count(bk_ts3_parse_list_response($sg_resp));
        }
        bk_ts3_send_command($socket, 'logout');
        $query_steps['logout'] = true;
    }

    @fwrite($socket, "quit\n");
    @fclose($socket);

    $check_stages = [
        'query' => [
            'ok' => true,
            'time_ms' => round((microtime(true) - $query_start) * 1000),
            'authenticated' => $authenticated,
            'steps' => $query_steps,
        ],
        'service' => [
            'clients_online' => $real_clients_online,
            'clients_max' => $clients_max,
            'slot_usage_pct' => $clients_max > 0 ? round(($real_clients_online / $clients_max) * 100, 1) : null,
            'channel_count' => $channel_count,
            'active_channel_count' => $active_channel_count,
            'query_client_count' => $query_client_count,
            'server_group_count' => $server_group_count,
            'voice_activity' => $voice_activity,
        ],
        'ports' => check_ts3_ports($host, $port, $filetransfer_port, min($timeout, 2)),
        'license' => $details['virtualserver_license'] ?? null,
        'version' => $details['virtualserver_version'] ?? null,
    ];

    return [
        'status' => 'up',
        'response_time' => $duration,
        'error' => null,
        'clients_online' => $real_clients_online,
        'clients_max' => $clients_max,
        'name' => $details['virtualserver_name'] ?? null,
        'version' => $details['virtualserver_version'] ?? '',
        'checked_ip' => $connected_ip,
        'ip_version' => $ip_version,
        'check_stages' => $check_stages,
    ];
}

/**
 * Discord Guild Widget API Check
 */
function check_discord($guild_id, $timeout = 3) {
    $start = microtime(true);
    $url = "https://discord.com/api/guilds/" . urlencode($guild_id) . "/widget.json";
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n",
                'ignore_errors' => true
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        $http_code = 200;
        $resp_headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if (!empty($resp_headers[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $resp_headers[0], $matches);
            if (isset($matches[1])) {
                $http_code = (int)$matches[1];
            }
        }
    }
    
    $duration = round((microtime(true) - $start) * 1000);
    
    if ($http_code !== 200 || !$response) {
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => "Discord API neodpovídá nebo server neexistuje (kód $http_code). Ujistěte se, že máte v nastavení Discord serveru zapnutý Widget.",
            'presence_count' => 0
        ];
    }
    
    $data = json_decode($response, true);
    if (!$data || isset($data['code'])) {
        return [
            'status' => 'down',
            'response_time' => 0,
            'error' => isset($data['message']) ? $data['message'] : 'Chyba parsování Discord API',
            'presence_count' => 0
        ];
    }
    
    $presence_count = isset($data['presence_count']) ? (int)$data['presence_count'] : 0;
    
    // Projdeme členy a seskupíme je do hlasových kanálů
    $channels_with_users = [];
    $members_list = [];
    if (isset($data['members']) && is_array($data['members'])) {
        foreach ($data['members'] as $m) {
            $username = $m['username'] ?? '';
            $status = $m['status'] ?? 'online';
            $game = isset($m['game']['name']) ? $m['game']['name'] : null;
            
            $members_list[] = [
                'username' => $username,
                'status' => $status,
                'game' => $game
            ];
            
            if (isset($m['channel_id']) && $m['channel_id'] !== null) {
                $chan_id = $m['channel_id'];
                $channels_with_users[$chan_id][] = $username;
            }
        }
    }
    
    // Doplníme názvy kanálů
    $voice_channels = [];
    if (isset($data['channels']) && is_array($data['channels'])) {
        foreach ($data['channels'] as $ch) {
            $ch_id = $ch['id'];
            if (isset($channels_with_users[$ch_id])) {
                $voice_channels[] = [
                    'name' => $ch['name'],
                    'users' => $channels_with_users[$ch_id]
                ];
            }
        }
    }

    return [
        'status' => 'up',
        'response_time' => $duration,
        'error' => null,
        'presence_count' => $presence_count,
        'name' => $data['name'] ?? null,
        'instant_invite' => $data['instant_invite'] ?? null,
        'voice_channels' => $voice_channels,
        'members' => array_slice($members_list, 0, 15) // Zobrazit max 15 členů pro úsporu místa
    ];
}

/**
 * Odeslání e-mailu přes PHPMailer (SMTP autentizace) nebo PHP mail() jako záloha
 */
function send_email($to, $subject, $html_body) {
    $GLOBALS['last_mail_error'] = '';
    // 'smtp' = ověřené odeslání přes autentizovaný SMTP server (silný signál
    // úspěchu), 'fallback' = neautentizovaný PHP mail() - vrátí true, i když
    // to jen znamená "místní MTA to přijal ke zpracování", ne že to reálně
    // dorazilo. Volající (digest apod.) podle tohohle rozlišuje, jak sebejistě
    // formulovat hlášku o úspěchu - viz send_digest_report_inner().
    $GLOBALS['last_mail_method'] = null;

    $smtp_host = get_setting('smtp_host', '');
    $smtp_port = (int) get_setting('smtp_port', 587);
    $smtp_user = get_setting('smtp_user', '');
    $smtp_pass = get_setting('smtp_pass', '');
    $smtp_secure = get_setting('smtp_secure', 'tls'); // 'tls' = STARTTLS, 'ssl' = SSL
    $site_title = get_setting('site_title', 'Blood Kings Status');
    
    $lib_path = __DIR__ . '/lib/';
    
    // Pokud jsou SMTP přihlašovací údaje nastaveny a PHPMailer je dostupný, použijeme ho
    if (!empty($smtp_host) && !empty($smtp_user) && !empty($smtp_pass) && file_exists($lib_path . 'PHPMailer.php')) {
        require_once $lib_path . 'Exception.php';
        require_once $lib_path . 'SMTP.php';
        require_once $lib_path . 'PHPMailer.php';
        
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_user;
            $mail->Password   = $smtp_pass;
            $mail->SMTPSecure = ($smtp_secure === 'ssl')
                ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp_port;
            $mail->CharSet    = 'UTF-8';
            
            $mail->setFrom($smtp_user, $site_title);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject    = $subject;
            $mail->Body       = $html_body;
            
            $mail->send();
            $GLOBALS['last_mail_method'] = 'smtp';
            return true;
        } catch (Exception $e) {
            $GLOBALS['last_mail_error'] = $mail->ErrorInfo ?? $e->getMessage();
            return false;
        }
    }
    
    // Záloha: PHP mail() bez SMTP autentizace (funguje jen pokud webhosting povoluje)
    // noreply@example.com je záměrně obecná - IANA vyhrazená doména pro dokumentaci
    // (RFC 2606), ne odhad skutečné domény nasazení.
    $from = !empty($smtp_user) ? $smtp_user : 'noreply@example.com';
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . $site_title . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . phpversion()
    ];
    set_error_handler(function($errno, $errstr) {
        $GLOBALS['last_mail_error'] = $errstr;
    });
    $result = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html_body, implode("\r\n", $headers));
    restore_error_handler();
    if (!$result && empty($GLOBALS['last_mail_error'])) {
        $GLOBALS['last_mail_error'] = 'mail() vrátilo false – SMTP host/heslo nejsou nastaveny, zkuste nakonfigurovat SMTP v nastavení systému.';
    }
    if ($result) {
        $GLOBALS['last_mail_method'] = 'fallback';
    }
    return $result;
}

/**
 * Odeslání SMS přes Twilio nebo SMSbrana.cz
 */
function send_sms($phone, $message, $user_whatsapp_apikey = '', $force_gateway = '') {
    $gateway = !empty($force_gateway) ? $force_gateway : get_setting('sms_gateway_type', '');
    
    if ($gateway === 'whatsapp') {
        // CallMeBot klíč je vázaný na konkrétní telefonní číslo, takže existuje
        // jen jako osobní klíč každého uživatele - žádný globální fallback.
        $apikey = $user_whatsapp_apikey;
        if (empty($apikey) || empty($phone)) {
            return false;
        }
        
        // Vyčistit telefonní číslo pro CallMeBot (pouze číslice)
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        // Pokud číslo nezačíná mezinárodní předvolbou (má 9 číslic), doplníme české +420
        if (strlen($clean_phone) === 9) {
            $clean_phone = '420' . $clean_phone;
        }
        
        $url = "https://api.callmebot.com/whatsapp.php?phone=" . urlencode($clean_phone) . "&text=" . urlencode($message) . "&apikey=" . urlencode($apikey);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code >= 200 && $code < 300);
    }
    
    if ($gateway === 'twilio') {
        $sid = get_setting('twilio_sid');
        $token = get_setting('twilio_token');
        $from = get_setting('twilio_from');
        
        if (empty($sid) || empty($token) || empty($from)) {
            return false;
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
        
        $data = [
            'From' => $from,
            'To' => $phone,
            'Body' => $message
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$sid:$token");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code >= 200 && $code < 300);
    } 
    elseif ($gateway === 'smsbrana') {
        $user = get_setting('smsbrana_user');
        $password = get_setting('smsbrana_password');
        
        if (empty($user) || empty($password)) {
            return false;
        }
        
        // SMS Brána API odeslání SMS (HTTP GET/POST)
        $url = "https://api.smsbrana.cz/sms/apixml.xml";
        $xml = '<?xml version="1.0" encoding="utf-8"?>
        <apirequest>
            <user>' . htmlspecialchars($user) . '</user>
            <password>' . htmlspecialchars($password) . '</password>
            <action>send_sms</action>
            <params>
                <sms>
                    <sender>txt</sender>
                    <number>' . htmlspecialchars($phone) . '</number>
                    <message>' . htmlspecialchars($message) . '</message>
                </sms>
            </params>
        </apirequest>';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($code === 200 && strpos($response, '<err>0</err>') !== false);
    }
    
    return false;
}

/**
 * Pomocná funkce pro zjištění, zda je monitor v plánované údržbě
 */
function is_in_maintenance($monitor) {
    if ((int)($monitor['maintenance'] ?? 0) !== 1) {
        return false;
    }
    if (!empty($monitor['maintenance_start']) && !empty($monitor['maintenance_end'])) {
        $now = time();
        $start = strtotime($monitor['maintenance_start']);
        $end = strtotime($monitor['maintenance_end']);
        if ($now >= $start && $now <= $end) {
            return true;
        }
        return false;
    }
    return true;
}

/**
 * Spuštění notifikačního procesu při změně stavu monitoru
 */
/**
 * Životní cyklus incidentů navázaných na monitor.
 *
 * Výpadek automaticky založí incident (jednou - dokud je otevřený, další
 * down průchody cronu nic nepřidávají), obnovení ho automaticky uzavře
 * se záznamem v timeline. Ruční kroky (převzetí, poznámky, postmortem)
 * dělá admin přes API - tohle jen garantuje, že žádný výpadek nezmizí
 * bez záznamu.
 */
function bk_incident_lifecycle($pdo, $monitor, $new_status, $error_msg = '') {
    $monitor_id = (int)($monitor['id'] ?? 0);
    if ($monitor_id <= 0) {
        return;
    }
    try {
        if ($new_status === 'down') {
            $stmt = $pdo->prepare("SELECT id FROM incidents WHERE monitor_id = ? AND status != 'resolved' LIMIT 1");
            $stmt->execute([$monitor_id]);
            if ($stmt->fetchColumn() === false) {
                $ins = $pdo->prepare("INSERT INTO incidents (title, impact, status, monitor_id) VALUES (?, 'major', 'investigating', ?)");
                $ins->execute(['Výpadek: ' . ($monitor['name'] ?? ('monitor #' . $monitor_id)), $monitor_id]);
                $incident_id = (int)$pdo->lastInsertId();
                $upd = $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, 'investigating', ?)");
                $upd->execute([$incident_id, 'Automaticky detekován výpadek. ' . ($error_msg !== '' ? 'Důvod: ' . $error_msg : '')]);
            }
        } elseif ($new_status === 'up') {
            $stmt = $pdo->prepare("SELECT id FROM incidents WHERE monitor_id = ? AND status != 'resolved'");
            $stmt->execute([$monitor_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $incident_id) {
                $pdo->prepare("UPDATE incidents SET status = 'resolved', resolved_at = NOW() WHERE id = ?")->execute([$incident_id]);
                $pdo->prepare("INSERT INTO incident_updates (incident_id, status, message) VALUES (?, 'resolved', 'Monitor je opět dostupný - incident uzavřen automaticky.')")
                    ->execute([$incident_id]);
            }
        }
    } catch (Throwable $e) {
        // Incident je doprovodný záznam - jeho selhání nesmí zastavit notifikace.
    }
}

function trigger_notifications($pdo, $monitor, $new_status, $error_msg = '') {
    bk_incident_lifecycle($pdo, $monitor, $new_status, $error_msg);
    $name = $monitor['name'];
    $type = $monitor['type'];
    $target = $monitor['target'];
    $port = $monitor['port'];

    // Notifikace o neaktivitě agenta se řídí přímo časovým limitem (0 = zcela vypnuto, viz cron.php)
    // - žádný samostatný přepínač pro ně proto není potřeba.
    // Upozornění na překročení limitů CPU/RAM/HDD lze v nastavení vypnout samostatně.
    $is_agent_event = in_array($new_status, ['agent_offline', 'vps_warning'], true);
    if ($new_status === 'vps_warning' && get_setting('agent_notifications_enabled', '1') !== '1') {
        return;
    }

    $status_text = 'DOWN (Výpadek)';
    $emoji = '🔴';
    if ($new_status === 'up') {
        $status_text = 'ONLINE (Zpět v provozu)';
        $emoji = '🟢';
    } elseif ($new_status === 'maintenance') {
        $status_text = 'ÚDRŽBA (Plánovaná odstávka)';
        $emoji = '⚠️';
    } elseif ($new_status === 'agent_offline') {
        $status_text = 'VPS AGENT NEAKTIVNÍ';
        $emoji = '🔴';
    } elseif ($new_status === 'vps_warning') {
        $status_text = 'VPS METRIKY - VAROVÁNÍ';
        $emoji = '⚠️';
    } elseif ($new_status === 'latency_degraded') {
        // Služba běží, jen pomalu - proto varování, ne výpadek.
        $status_text = 'ZPOMALENÍ (služba odpovídá pomalu)';
        $emoji = '🐢';
    } elseif ($new_status === 'latency_recovered') {
        $status_text = 'ODEZVA ZPĚT V NORMÁLU';
        $emoji = '🟢';
    }
    // Načtení všech příjemců notifikací (odběratelé + administrátoři bez přímého nastavení odběru)
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.phone, u.role, u.whatsapp_apikey, u.email_lang,
               COALESCE(s.email_notifications, m.email_notifications) as email_notifications,
               COALESCE(s.sms_notifications, m.sms_notifications, u.sms_notifications) as sms_notifications,
               COALESCE(s.whatsapp_notifications, u.whatsapp_notifications) as whatsapp_notifications
        FROM users u
        CROSS JOIN (SELECT * FROM monitors WHERE id = ?) m
        LEFT JOIN user_subscriptions s ON u.id = s.user_id AND s.monitor_id = m.id
        WHERE u.role = 'admin' OR s.user_id IS NOT NULL
    ");
    $stmt->execute([$monitor['id']]);
    $recipients = $stmt->fetchAll();

    // Události VPS agenta jsou ve výchozím stavu interní - chodí pouze administrátorům, ne běžným odběratelům
    if ($is_agent_event && get_setting('agent_notify_admin_only', '1') === '1') {
        $recipients = array_values(array_filter($recipients, function ($r) {
            return ($r['role'] ?? '') === 'admin';
        }));
    }

    $time = date('d.m.Y H:i:s');

    // HTML Šablona pro E-mail v barvách Blood Kings (červeno-černá)
    $color_theme = '#c1121f'; // red
    if ($new_status === 'up') {
        $color_theme = '#1ec773'; // teal
    } elseif ($new_status === 'maintenance' || $new_status === 'vps_warning') {
        $color_theme = '#f39c12'; // orange
    }

    // Jazyk e-mailového kanálu se řídí nastavením email_lang (viz bk_with_email_lang()),
    // ne prohlížečem příjemce ani prostředím (cron/agent_api), ve kterém tahle funkce běží.
    // SMS/WhatsApp a Discord/Slack/Telegram/Pushover/PagerDuty zprávy níže zůstávají
    // beze změny na $status_text (česky) - přeložit se má jen e-mailový kanál.
    $alert_status_keys = [
        'down' => 'alert_status_down',
        'up' => 'alert_status_up',
        'maintenance' => 'alert_status_maintenance',
        'agent_offline' => 'alert_status_agent_offline',
        'vps_warning' => 'alert_status_vps_warning',
    ];
    $alert_status_key = $alert_status_keys[$new_status] ?? 'alert_status_down';

    // Vše inline (+ reálné <table>), ne v <style> bloku - Gmail, Outlook a
    // většina webmailů <style>/<head> při doručení ořízne, e-mail by dorazil
    // bez formátování. Viz stejný přístup u render_email_wrapper() (digest).
    $font = "font-family: Arial, Helvetica, sans-serif;";
    // E-mail se renderuje per jazyk příjemce (users.email_lang, NULL = globální
    // email_lang) - jednou na jazyk, ne jednou na příjemce. SMS/WhatsApp a
    // webhooky níže zůstávají jednojazyčné.
    $default_email_lang = get_setting('email_lang', 'cs');
    $render_alert_email = function (string $lang) use ($alert_status_key, $emoji, $name, $type, $target, $port, $time, $error_msg, $color_theme, $font) {
        return bk_with_email_lang($lang, function () use ($alert_status_key, $emoji, $name, $type, $target, $port, $time, $error_msg, $color_theme, $font) {
        $status_label = t($alert_status_key);
        $subject = "$emoji $status_label: $name";
        $html_body = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Blood Kings Status</title>
    </head>
    <body style="margin:0; padding:20px; background-color:#0f0f13; ' . $font . '">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td align="center">
                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px; width:100%; background-color:#1a1a24; border-radius:8px; border-top:5px solid ' . $color_theme . '; overflow:hidden;">
                        <tr>
                            <td style="padding:25px; text-align:center; background-color:#12121a;">
                                <h1 style="margin:0; font-size:22px; color:#ffffff; ' . $font . '">Blood Kings Status</h1>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:30px; line-height:1.6; color:#e1e1e6; font-size:14px; ' . $font . '">
                                <span style="display:inline-block; padding:6px 12px; border-radius:4px; font-weight:bold; color:#ffffff; background-color:' . $color_theme . '; margin-bottom:20px; text-transform:uppercase; ' . $font . '">' . htmlspecialchars($status_label) . '</span>
                                <p style="' . $font . '">' . htmlspecialchars(t('alert_email_intro')) . '</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#12121a; margin:20px 0;">
                                    <tr>
                                        <td style="border-left:3px solid #ff4444; padding:15px; ' . $font . '">
                                            <strong>' . htmlspecialchars(t('alert_email_label_name')) . '</strong> ' . htmlspecialchars($name) . '<br>
                                            <strong>' . htmlspecialchars(t('alert_email_label_type')) . '</strong> ' . htmlspecialchars(strtoupper($type)) . '<br>
                                            <strong>' . htmlspecialchars(t('alert_email_label_target')) . '</strong> ' . htmlspecialchars($target) . ($port ? ':'.$port : '') . '<br>
                                            <strong>' . htmlspecialchars(t('alert_email_label_changed_at')) . '</strong> ' . $time . '<br>
                                            ' . (!empty($error_msg) ? '<strong>' . htmlspecialchars(t('alert_email_label_error')) . '</strong> ' . htmlspecialchars($error_msg) . '<br>' : '') . '
                                        </td>
                                    </tr>
                                </table>
                                <p style="' . $font . '">' . htmlspecialchars(t('alert_email_outro')) . '</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:15px 30px; text-align:center; font-size:12px; color:#888896; border-top:1px solid #22222f; background-color:#12121a; ' . $font . '">
                                ' . htmlspecialchars(t('alert_email_footer')) . '
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
        return [$subject, $html_body];
        });
    };
    // Cache vyrenderovaných variant - klíčem je jazyk.
    $alert_email_by_lang = [];

    // SMS / WhatsApp Zpráva
    $sms_body = "$emoji Monitor $name je $status_text. Čas: $time.";
    if ($new_status === 'maintenance') {
        $sms_body = "$emoji Monitor $name byl přepnut do režimu plánované údržby. Důvod: $error_msg";
    } elseif ($new_status === 'down' && !empty($error_msg)) {
        $sms_body .= " Chyba: " . substr($error_msg, 0, 100);
    } elseif ($is_agent_event && !empty($error_msg)) {
        // U událostí agenta (neaktivní agent, překročené limity) vždy uvést důvod, co se stalo
        $sms_body .= " Důvod: " . substr($error_msg, 0, 220);
    }
    
    foreach ($recipients as $rec) {
        // E-mailové notifikace - v jazyce příjemce (fallback globální email_lang)
        if ($rec['email_notifications'] && !empty($rec['email'])) {
            $rec_lang = in_array($rec['email_lang'] ?? '', ['cs', 'en'], true) ? $rec['email_lang'] : $default_email_lang;
            if (!isset($alert_email_by_lang[$rec_lang])) {
                $alert_email_by_lang[$rec_lang] = $render_alert_email($rec_lang);
            }
            [$email_subject, $html_body] = $alert_email_by_lang[$rec_lang];
            send_email($rec['email'], $email_subject, $html_body);
        }
        
        // SMS notifikace (Twilio / SMSbrana) - nezávislé na WhatsApp
        $gateway_type = get_setting('sms_gateway_type', '');
        if ($rec['sms_notifications'] && !empty($rec['phone'])) {
            if ($gateway_type === 'twilio' || $gateway_type === 'smsbrana') {
                send_sms($rec['phone'], $sms_body);
            }
        }

        // WhatsApp notifikace (CallMeBot) - nezávislé na SMS bráně, vlastní kanál.
        // Klíč je vázaný na konkrétní telefonní číslo, takže existuje jen per-user.
        if (($rec['whatsapp_notifications'] ?? 0) && !empty($rec['phone']) && !empty($rec['whatsapp_apikey'])) {
            send_sms($rec['phone'], $sms_body, $rec['whatsapp_apikey'], 'whatsapp');
        }
    }

    // Odeslání systémových/monitorových webhooků (Discord, Slack, Telegram) - spouští se pouze 1x na událost
    $discord_webhook = !empty($monitor['discord_webhook_url']) ? $monitor['discord_webhook_url'] : get_setting('discord_webhook_url');
    $telegram_token = !empty($monitor['telegram_bot_token']) ? $monitor['telegram_bot_token'] : get_setting('telegram_bot_token');
    $telegram_chat = !empty($monitor['telegram_chat_id']) ? $monitor['telegram_chat_id'] : get_setting('telegram_chat_id');
    $slack_webhook = !empty($monitor['slack_webhook_url']) ? $monitor['slack_webhook_url'] : get_setting('slack_webhook_url');

    if (!empty($discord_webhook)) {
        $color = ($new_status === 'up') ? 3066993 : 15073280; // Zelená / Červená
        $payload = [
            "embeds" => [[
                "title" => "Blood Kings Status Alert",
                "description" => "**Monitor:** " . htmlspecialchars($name) . "\n**Status:** " . strtoupper($status_text) . "\n**Čas:** " . $time . (!empty($error_msg) ? "\n**Detaily:** " . htmlspecialchars($error_msg) : ""),
                "color" => $color
            ]]
        ];
        send_webhook_post($discord_webhook, json_encode($payload));
    }

    if (!empty($slack_webhook)) {
        $slack_msg = "$emoji *Blood Kings Alert*:\n*Monitor:* $name\n*Status:* " . strtoupper($status_text) . "\n*Čas:* $time" . (!empty($error_msg) ? "\n*Detaily:* $error_msg" : "");
        send_webhook_post($slack_webhook, json_encode(["text" => $slack_msg]));
    }

    if (!empty($telegram_token) && !empty($telegram_chat)) {
        $tg_msg = "$emoji *Blood Kings Alert*:\n*Monitor:* $name\n*Status:* " . strtoupper($status_text) . "\n*Čas:* $time" . (!empty($error_msg) ? "\n*Detaily:* $error_msg" : "");
        $tg_url = "https://api.telegram.org/bot" . $telegram_token . "/sendMessage";
        $payload = [
            "chat_id" => $telegram_chat,
            "text" => $tg_msg,
            "parse_mode" => "Markdown"
        ];
        send_webhook_post($tg_url, json_encode($payload));
    }

    // Pushover notifikace
    $po_user = get_setting('pushover_user_key');
    $po_token = get_setting('pushover_api_token');
    if (!empty($po_user) && !empty($po_token)) {
        $po_prio = ($new_status === 'down') ? 1 : 0;
        send_pushover_alert($po_user, $po_token, "Blood Kings Alert: $name", "$emoji Monitor $name je $status_text. $error_msg", $po_prio);
    }

    // PagerDuty notifikace
    $pd_key = get_setting('pagerduty_routing_key');
    if (!empty($pd_key)) {
        $pd_action = ($new_status === 'down') ? 'trigger' : 'resolve';
        send_pagerduty_event($pd_key, $pd_action, "$emoji Monitor $name je $status_text. $error_msg");
    }
}

/**
 * Pomocná funkce pro odeslání HTTP POST požadavků (webhooků)
 */
function send_webhook_post($url, $payload_json) {
    $ch = curl_init($url);
    if ($ch === false) return;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: BloodKingsStatus/1.3.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Sestaví a odešle pravidelný souhrnný report (týdenní/měsíční) na e-maily
 * všech administrátorů. Volá se z cron.php (automaticky, s ochranou proti
 * duplicitnímu odeslání) a z admin.php (ruční okamžité odeslání).
 *
 * @param PDO $pdo
 * @param string $period 'weekly' nebo 'monthly'
 * @return bool True, pokud se report podařilo odeslat alespoň jednomu administrátorovi.
 */
function send_digest_report($pdo, $period = 'weekly') {
    $GLOBALS['last_mail_error'] = '';
    try {
        // Jazyková obálka se přesunula dovnitř - digest se renderuje per
        // jazyk příjemce (users.email_lang), ne jednou globálně.
        return send_digest_report_inner($pdo, $period);
    } catch (Exception $e) {
        $GLOBALS['last_mail_error'] = $e->getMessage();
        return false;
    }
}

/**
 * ==== Infrastructure Report (weekly/monthly digest) - helper functions ====
 */

/**
 * Určí směr trendu mezi aktuální a předchozí hodnotou. Vrací null, pokud
 * předchozí hodnota není k dispozici (první report, žádný snapshot).
 */
function bk_trend_direction($current, $previous, $threshold = 0.01) {
    if ($previous === null || $current === null) {
        return null;
    }
    $diff = $current - $previous;
    if (abs($diff) < $threshold) {
        return 'flat';
    }
    return $diff > 0 ? 'up' : 'down';
}

/**
 * Latence -> skóre 0-100 pro výpočet Infrastructure Score. 100 do 150 ms,
 * lineárně klesá na 40 při 1000 ms a výš.
 */
function bk_latency_score($avg_latency_ms) {
    if ($avg_latency_ms === null) {
        return 100;
    }
    if ($avg_latency_ms <= 150) {
        return 100;
    }
    if ($avg_latency_ms >= 1000) {
        return 40;
    }
    return 100 - (($avg_latency_ms - 150) / (1000 - 150)) * 60;
}

/**
 * Infrastructure Score (0-100) - vlastní heuristika, ne standardizovaná
 * metrika. Váhy: dostupnost 55 %, latence 20 %, incidenty 15 %, certifikáty 10 %.
 * Snadno laditelné, pokud se ukáže, že váhy neodpovídají realitě.
 */
function bk_infra_score($availability, $avg_latency_ms, $incident_count, $expiring_certs, $expired_certs) {
    $availability_component = min(100, $availability) * 0.55;
    $latency_component = bk_latency_score($avg_latency_ms) * 0.20;
    $incident_component = max(0, 100 - $incident_count * 5) * 0.15;
    $cert_component = max(0, 100 - $expiring_certs * 10 - $expired_certs * 30) * 0.10;
    return (int)round($availability_component + $latency_component + $incident_component + $cert_component);
}

/**
 * Asset Overview - univerzální health score (0-100) pro libovolný typ monitoru.
 * Váhy: uptime 30%, thresholdy 30%, konektivita 20%, čerstvost dat 20%.
 */
function bk_compute_asset_health_score($pdo, $monitor, array $details, $latest_metrics) {
    $score = 0.0;
    $weight_used = 0.0;

    // 1. Uptime (30%) - z posledních 30 dní. Bez jediné kontroly (nebo při
    // chybě DB) se komponenta vynechá a skóre se přenormuje na zbylé váhy -
    // dřívější dosazení 100 dávalo plný kredit za dostupnost, o které nic nevíme.
    $uptime_pct = null;
    try {
        $stmt = $pdo->prepare("SELECT SUM(status='up') as up_cnt, COUNT(*) as total FROM monitor_logs WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status IN ('up','down','warning')");
        $stmt->execute([$monitor['id']]);
        $row = $stmt->fetch();
        if ($row && $row['total'] > 0) {
            $uptime_pct = ($row['up_cnt'] / $row['total']) * 100;
        }
    } catch (PDOException $e) { /* neznámé zůstává neznámé */ }
    if ($uptime_pct !== null) {
        $score += min(100, $uptime_pct) * 0.30;
        $weight_used += 0.30;
    }

    // 2. Thresholdy (30%) - CPU/RAM/HDD pod limity
    $threshold_score = 100;
    $cpu_thresh = (float)($monitor['cpu_threshold'] ?? 90);
    $ram_thresh = (float)($monitor['ram_threshold'] ?? 90);
    $hdd_thresh = (float)($monitor['hdd_threshold'] ?? 95);
    $cpu_val = $details['cpu'] ?? null;
    $ram_val = $details['ram'] ?? null;
    $hdd_val = $details['hdd'] ?? null;
    $violations = 0;
    if ($cpu_val !== null && $cpu_val > $cpu_thresh) $violations++;
    if ($ram_val !== null && $ram_val > $ram_thresh) $violations++;
    if ($hdd_val !== null && $hdd_val > $hdd_thresh) $violations++;
    $threshold_score = max(0, 100 - $violations * 33);
    $score += $threshold_score * 0.30;

    // 3. Konektivita (20%) - aktuální status
    $status_score = match($monitor['status']) {
        'up' => 100,
        'maintenance' => 80,
        'unknown' => 50,
        default => 0,
    };
    $score += $status_score * 0.20;

    // 4. Čerstvost (20%) - jak dávno agent/check reportoval
    $freshness = 100;
    $last_seen = $details['agent_last_seen'] ?? null;
    if ($last_seen) {
        $age_min = (time() - (int)$last_seen) / 60;
        if ($age_min > 30) $freshness = 30;
        elseif ($age_min > 10) $freshness = 60;
        elseif ($age_min > 5) $freshness = 80;
    } elseif ($monitor['last_checked']) {
        $age_min = (time() - strtotime($monitor['last_checked'])) / 60;
        if ($age_min > 30) $freshness = 30;
        elseif ($age_min > 10) $freshness = 60;
    }
    $score += $freshness * 0.20;
    $weight_used += 0.70; // thresholdy + konektivita + čerstvost se počítají vždy

    // Přenormování na skutečně změřené komponenty (0-100).
    return (int)round(min(100, max(0, $score / $weight_used)));
}

/**
 * Asset Overview - kontext metriky (průměr/min/max za 24h, trend, top proces).
 */
function bk_metric_context($pdo, $monitor_id, $metric_column, $current_value) {
    $ctx = ['avg' => null, 'min' => null, 'max' => null, 'trend' => 'stable', 'top_process' => null];
    $allowed_cols = [
        'cpu_usage', 'ram_usage', 'hdd_usage', 'net_usage',
        'load_avg_1', 'load_avg_5', 'load_avg_15', 'cpu_steal', 'swap_usage',
        'disk_io_read_kbps', 'disk_io_write_kbps', 'net_errors',
        'iowait_pct', 'inode_usage_pct', 'zombie_count', 'fork_rate', 'temperature_c',
        'wifi_clients_total', 'conntrack_pct', 'net_ipv4_kbps', 'net_ipv6_kbps'
    ];
    if (!in_array($metric_column, $allowed_cols, true)) {
        return $ctx;
    }
    try {
        $stmt = $pdo->prepare("SELECT AVG($metric_column) as avg_v, MIN($metric_column) as min_v, MAX($metric_column) as max_v FROM vps_metrics WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND $metric_column IS NOT NULL");
        $stmt->execute([$monitor_id]);
        $row = $stmt->fetch();
        if ($row && $row['avg_v'] !== null) {
            $ctx['avg'] = round((float)$row['avg_v'], 1);
            $ctx['min'] = round((float)$row['min_v'], 1);
            $ctx['max'] = round((float)$row['max_v'], 1);
            // Trend: current vs avg
            if ($current_value !== null && $ctx['avg'] > 0) {
                $ratio = $current_value / $ctx['avg'];
                if ($ratio > 1.3) $ctx['trend'] = 'up';
                elseif ($ratio < 0.7) $ctx['trend'] = 'down';
            }
        }
    } catch (PDOException $e) { /* best-effort */ }
    return $ctx;
}

/**
 * Asset Overview - 30 denních teček zdraví (zelená/žlutá/červená).
 */
function bk_get_30day_health_dots($pdo, $monitor_id) {
    $dots = [];
    try {
        $stmt = $pdo->prepare("
            SELECT DATE(checked_at) as d,
                   SUM(status = 'down') as down_cnt,
                   SUM(status = 'maintenance') as maint_cnt,
                   COUNT(*) as total
            FROM monitor_logs
            WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(checked_at) ORDER BY d ASC
        ");
        $stmt->execute([$monitor_id]);
        $by_date = [];
        foreach ($stmt->fetchAll() as $row) {
            $by_date[$row['d']] = $row;
        }
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $label = date('j.n.', strtotime($date));
            if (isset($by_date[$date])) {
                $r = $by_date[$date];
                if ($r['down_cnt'] > 0) $status = 'down';
                elseif ($r['maint_cnt'] > 0 && $r['maint_cnt'] >= $r['total'] * 0.5) $status = 'maintenance';
                else $status = 'up';
            } else {
                $status = 'none';
            }
            $dots[] = ['date' => $date, 'label' => $label, 'status' => $status];
        }
    } catch (PDOException $e) { /* best-effort */ }
    return $dots;
}

/**
 * Asset Overview - profil karet pro daný typ monitoru.
 * Vrací pole ['key' => ['icon'=>, 'label_key'=>, 'source'=>]]
 */
function bk_get_type_card_profile($type) {
    $profiles = [
        'openwrt' => [
            'cpu' => ['icon' => 'fa-microchip', 'label' => 'CPU', 'source' => 'details.cpu', 'unit' => '%'],
            'ram' => ['icon' => 'fa-memory', 'label' => 'RAM', 'source' => 'details.ram', 'unit' => '%'],
            'hdd' => ['icon' => 'fa-hard-drive', 'label' => 'Flash', 'source' => 'details.hdd', 'unit' => '%'],
            'net' => ['icon' => 'fa-network-wired', 'label' => 'Síť', 'source' => 'details.net', 'unit' => ' KB/s'],
            'temperature' => ['icon' => 'fa-temperature-half', 'label' => 'Teplota', 'source' => 'details.temperature', 'unit' => '°C'],
            'wan' => ['icon' => 'fa-earth-europe', 'label' => 'WAN', 'source' => 'special.wan', 'unit' => ''],
            'wireguard' => ['icon' => 'fa-shield-halved', 'label' => 'WireGuard', 'source' => 'special.wireguard', 'unit' => ''],
            'wifi_clients' => ['icon' => 'fa-wifi', 'label' => 'Wi-Fi', 'source' => 'special.wifi', 'unit' => ''],
            'conntrack' => ['icon' => 'fa-table-list', 'label' => 'Conntrack', 'source' => 'details.conntrack_pct', 'unit' => '%'],
        ],
        'vps' => [
            'cpu' => ['icon' => 'fa-microchip', 'label' => 'CPU', 'source' => 'details.cpu', 'unit' => '%'],
            'ram' => ['icon' => 'fa-memory', 'label' => 'RAM', 'source' => 'details.ram', 'unit' => '%'],
            'hdd' => ['icon' => 'fa-hard-drive', 'label' => 'Disk', 'source' => 'details.hdd', 'unit' => '%'],
            'load' => ['icon' => 'fa-gauge-high', 'label' => 'Load', 'source' => 'details.load1', 'unit' => ''],
            'net' => ['icon' => 'fa-network-wired', 'label' => 'Síť', 'source' => 'details.net', 'unit' => ' KB/s'],
            'temperature' => ['icon' => 'fa-temperature-half', 'label' => 'Teplota', 'source' => 'details.temperature', 'unit' => '°C'],
            'swap' => ['icon' => 'fa-arrows-rotate', 'label' => 'Swap', 'source' => 'details.swap', 'unit' => '%'],
            'uptime' => ['icon' => 'fa-clock', 'label' => 'Uptime', 'source' => 'details.uptime', 'unit' => 's'],
        ],
        'teamspeak' => [
            'clients' => ['icon' => 'fa-users', 'label' => 'Klienti', 'source' => 'special.ts_clients', 'unit' => ''],
            'voice' => ['icon' => 'fa-volume-high', 'label' => 'Voice', 'source' => 'special.ts_voice', 'unit' => ''],
            'ping' => ['icon' => 'fa-signal', 'label' => 'Ping', 'source' => 'special.ts_ping', 'unit' => ' ms'],
            'process_cpu' => ['icon' => 'fa-microchip', 'label' => 'CPU TS3', 'source' => 'details.ts3_process.cpu', 'unit' => '%'],
            'process_ram' => ['icon' => 'fa-memory', 'label' => 'RAM TS3', 'source' => 'details.ts3_process.ram_mb', 'unit' => ' MB'],
            'uptime' => ['icon' => 'fa-clock', 'label' => 'Uptime', 'source' => 'details.uptime', 'unit' => 's'],
        ],
        'minecraft' => [
            'players' => ['icon' => 'fa-users', 'label' => 'Hráči', 'source' => 'special.mc_players', 'unit' => ''],
            'version' => ['icon' => 'fa-code-branch', 'label' => 'Verze', 'source' => 'details.version', 'unit' => ''],
            'tps' => ['icon' => 'fa-gauge-high', 'label' => 'TPS', 'source' => 'details.tps', 'unit' => ''],
            'uptime' => ['icon' => 'fa-clock', 'label' => 'Uptime', 'source' => 'details.uptime', 'unit' => 's'],
        ],
        'web' => [
            'response' => ['icon' => 'fa-stopwatch', 'label' => 'Odezva', 'source' => 'special.response_time', 'unit' => ' ms'],
            'http' => ['icon' => 'fa-globe', 'label' => 'HTTP', 'source' => 'details.http_code', 'unit' => ''],
            'ssl' => ['icon' => 'fa-lock', 'label' => 'SSL', 'source' => 'special.ssl_days', 'unit' => ' dní'],
            'uptime' => ['icon' => 'fa-clock', 'label' => 'Uptime', 'source' => 'special.uptime_pct', 'unit' => '%'],
        ],
    ];
    return $profiles[$type] ?? $profiles['vps'];
}

/**
 * Sestaví veškerá data pro infrastructure report (weekly/monthly). Čistě
 * výpočetní funkce bez vedlejších efektů, kromě zápisu trend-snapshotu do
 * settings na konci (potřebný pro příští report, retence logů to jinak
 * neumožňuje - viz komentář u digest_snapshot_* níže).
 */
function build_digest_data($pdo, $period = 'weekly', $save_snapshot = true) {
    $days = ($period === 'monthly') ? 30 : 7;
    $site_title = get_setting('site_title', 'Blood Kings Status');
    $range_from = date('d.m.Y', strtotime("-$days days"));
    $range_to = date('d.m.Y');

    // --- Hlavní server / hub lokace (pro vyloučení z regionů, stejná logika jako index.php) ---
    $hub_location = trim(get_setting('cron_location', ''));
    if ($hub_location === '' || $hub_location === 'AUTO' || $hub_location === '🇨🇿 Praha, CZ') {
        $hub_location = trim(get_setting('ip_loc_local', ''));
    }

    // --- Trend snapshot z minulého období ---
    $snapshot_key = 'digest_snapshot_' . $period;
    $prev_snapshot = json_decode(get_setting($snapshot_key, ''), true);
    if (!is_array($prev_snapshot)) {
        $prev_snapshot = null;
    }

    // --- Základní KPI ---
    $stmt_overall = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
            SUM(CASE WHEN status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count,
            SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count,
            COUNT(*) as all_rows,
            AVG(CASE WHEN response_time > 0 THEN response_time END) as avg_latency
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    $stmt_overall->execute([$days]);
    $overall = $stmt_overall->fetch();
    $total_checks = (int)($overall['all_rows'] ?? 0);
    $availability = ($overall['total_count'] ?? 0) > 0 ? round(($overall['up_count'] / $overall['total_count']) * 100, 3) : 100.0;
    $incident_count = (int)($overall['down_count'] ?? 0);
    $avg_latency = $overall['avg_latency'] !== null ? (int)round($overall['avg_latency']) : null;

    // --- Agenti (jen ty, které se někdy reálně ozvaly - stejná logika jako index.php) ---
    $offline_timeout_secs = max(0, (int)get_setting('agent_offline_timeout', '50')) * 60;
    $agent_count = 0;
    $stmt_agents = $pdo->query("SELECT last_details FROM monitors WHERE agent_key IS NOT NULL AND agent_key != ''");
    while ($row = $stmt_agents->fetch()) {
        $det = json_decode($row['last_details'] ?? '', true);
        if (($det['agent_last_seen'] ?? 0) > 0) {
            $agent_count++;
        }
    }

    // --- Regiony (per checked_from, dostupnost + latence za období) ---
    $stmt_regions = $pdo->prepare("
        SELECT checked_from,
               SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count,
               AVG(CASE WHEN response_time > 0 THEN response_time END) as avg_latency
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND checked_from IS NOT NULL
              AND checked_from != 'Main Server'" . ($hub_location !== '' ? " AND checked_from != ?" : "") . "
        GROUP BY checked_from
        ORDER BY checked_from ASC
    ");
    $stmt_regions->execute($hub_location !== '' ? [$days, $hub_location] : [$days]);
    $regions_raw = $stmt_regions->fetchAll();
    $regions = [];
    foreach ($regions_raw as $r) {
        // Region, ze kterého v okně nepřišla jediná měřená kontrola, se
        // vynechá - dřív dostal vymyšlených 100.0 % uptime.
        if ((int)$r['total_count'] <= 0) {
            continue;
        }
        $regions[] = [
            'name' => $r['checked_from'],
            'uptime' => round(($r['up_count'] / $r['total_count']) * 100, 2),
            'avg_latency' => $r['avg_latency'] !== null ? (int)round($r['avg_latency']) : null,
        ];
    }
    $region_count = count($regions);

    // --- Infrastructure Score ---
    // SSL/DNS souhrn se počítá níže, ale skóre potřebuje počty expirujících/expirovaných certifikátů -
    // proto se SSL data počítají dřív a skóre až po nich (viz níže po sekci SSL).

    // --- Nejlepší / nejhorší monitory ---
    $stmt_worst = $pdo->prepare("
        SELECT m.name, m.type,
               SUM(CASE WHEN l.status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN l.status = 'down' THEN 1 ELSE 0 END) as down_count,
               SUM(CASE WHEN l.status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count
        FROM monitor_logs l
        JOIN monitors m ON m.id = l.monitor_id
        WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY l.monitor_id, m.name, m.type
        ORDER BY down_count DESC
        LIMIT 30
    ");
    $stmt_worst->execute([$days]);
    $all_monitor_stats = $stmt_worst->fetchAll();

    $worst_monitors = array_values(array_filter($all_monitor_stats, function ($m) {
        return (int)$m['down_count'] > 0;
    }));
    usort($worst_monitors, function ($a, $b) {
        $ratio_a = $a['total_count'] > 0 ? $a['up_count'] / $a['total_count'] : 1;
        $ratio_b = $b['total_count'] > 0 ? $b['up_count'] / $b['total_count'] : 1;
        return $ratio_a <=> $ratio_b;
    });
    $worst_monitors = array_slice($worst_monitors, 0, 5);

    $best_monitors = array_values(array_filter($all_monitor_stats, function ($m) {
        return (int)$m['down_count'] === 0 && (int)$m['total_count'] > 0;
    }));
    usort($best_monitors, function ($a, $b) {
        return $b['total_count'] <=> $a['total_count'];
    });
    $best_monitors = array_slice($best_monitors, 0, 4);

    // --- Agent Health (poslední vps_metrics řádek za monitor v okně) ---
    $stmt_agent_health = $pdo->prepare("
        SELECT vm.cpu_usage, vm.ram_usage, vm.hdd_usage, m.name, m.cpu_threshold, m.ram_threshold, m.hdd_threshold
        FROM vps_metrics vm
        INNER JOIN (
            SELECT monitor_id, MAX(checked_at) as max_at
            FROM vps_metrics
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY monitor_id
        ) latest ON latest.monitor_id = vm.monitor_id AND latest.max_at = vm.checked_at
        JOIN monitors m ON m.id = vm.monitor_id
    ");
    $stmt_agent_health->execute([$days]);
    $agent_health = $stmt_agent_health->fetchAll();

    // --- SSL souhrn (poslední check_stages za 'web' monitor v okně) ---
    $stmt_ssl = $pdo->prepare("
        SELECT l.check_stages, m.name
        FROM monitor_logs l
        INNER JOIN (
            SELECT monitor_id, MAX(checked_at) as max_at
            FROM monitor_logs
            WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND check_stages IS NOT NULL
            GROUP BY monitor_id
        ) latest ON latest.monitor_id = l.monitor_id AND latest.max_at = l.checked_at
        JOIN monitors m ON m.id = l.monitor_id
        WHERE m.type = 'web'
    ");
    $stmt_ssl->execute([$days]);
    $ssl_rows = $stmt_ssl->fetchAll();

    $certs_expiring = 0;
    $certs_expired = 0;
    $expiring_list = [];
    $dns_failures = 0;
    $dns_slow = 0;
    foreach ($ssl_rows as $row) {
        $stages = json_decode($row['check_stages'] ?? '', true);
        if (!is_array($stages)) continue;

        if (isset($stages['dns']['ok']) && $stages['dns']['ok'] === false) {
            $dns_failures++;
        }
        if (isset($stages['dns']['time_ms']) && $stages['dns']['time_ms'] > 200) {
            $dns_slow++;
        }

        $days_remaining = $stages['tls']['cert']['days_remaining'] ?? null;
        if ($days_remaining === null) continue;
        if ($days_remaining <= 0) {
            $certs_expired++;
            $expiring_list[] = ['name' => $row['name'], 'days_remaining' => $days_remaining];
        } elseif ($days_remaining < 30) {
            $certs_expiring++;
            $expiring_list[] = ['name' => $row['name'], 'days_remaining' => $days_remaining];
        }
    }
    usort($expiring_list, function ($a, $b) { return $a['days_remaining'] <=> $b['days_remaining']; });

    // --- Config change eventy tohoto období (renewed počítáme z eventů, ne z aktuálního stavu) ---
    $stmt_events_summary = $pdo->prepare("
        SELECT event_type, COUNT(*) as cnt
        FROM monitor_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY event_type
    ");
    $stmt_events_summary->execute([$days]);
    $event_counts = [];
    foreach ($stmt_events_summary->fetchAll() as $row) {
        $event_counts[$row['event_type']] = (int)$row['cnt'];
    }

    $stmt_events_recent = $pdo->prepare("
        SELECT monitor_id, monitor_name, monitor_type, event_type, description, occurred_at
        FROM monitor_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY occurred_at DESC
        LIMIT 25
    ");
    $stmt_events_recent->execute([$days]);
    $recent_events = $stmt_events_recent->fetchAll();

    $new_servers = [];
    $removed_servers = [];
    $config_change_examples = [];
    foreach ($recent_events as $ev) {
        if ($ev['event_type'] === 'monitor_added') {
            // monitor_id tu ještě existuje (monitor právě přibyl) - jde proklik.
            $new_servers[] = ['name' => $ev['monitor_name'], 'type' => $ev['monitor_type'], 'id' => $ev['monitor_id']];
        } elseif ($ev['event_type'] === 'monitor_removed') {
            // monitor_id je tu vždycky NULL (ON DELETE SET NULL - monitor už
            // neexistuje, proto se to vůbec loguje), proklik proto nejde nikdy.
            $removed_servers[] = ['name' => $ev['monitor_name'], 'type' => $ev['monitor_type'], 'id' => null];
        } elseif (in_array($ev['event_type'], ['scheme_upgraded', 'dns_lost', 'dns_recovered', 'cert_renewed', 'agent_connected', 'agent_disconnected'], true)) {
            $config_change_examples[] = $ev['monitor_name'] . ': ' . $ev['description'];
        }
    }
    $certs_renewed = $event_counts['cert_renewed'] ?? 0;

    // --- Infrastructure Score (po SSL datech, viz výše) ---
    $score = bk_infra_score($availability, $avg_latency, $incident_count, $certs_expiring, $certs_expired);

    // --- Trendy vs. minulé období ---
    $trend_availability = bk_trend_direction($availability, $prev_snapshot['availability'] ?? null);
    $trend_latency = bk_trend_direction($avg_latency, $prev_snapshot['avg_latency'] ?? null);
    $trend_score = bk_trend_direction($score, $prev_snapshot['score'] ?? null, 1);
    $avg_cpu = null;
    $avg_ram = null;
    if (!empty($agent_health)) {
        $avg_cpu = round(array_sum(array_column($agent_health, 'cpu_usage')) / count($agent_health), 1);
        $avg_ram = round(array_sum(array_column($agent_health, 'ram_usage')) / count($agent_health), 1);
    }
    $trend_cpu = bk_trend_direction($avg_cpu, $prev_snapshot['avg_cpu'] ?? null, 1);
    $trend_ram = bk_trend_direction($avg_ram, $prev_snapshot['avg_ram'] ?? null, 1);
    $dns_health = $total_checks > 0 && count($ssl_rows) > 0 ? round((1 - $dns_failures / count($ssl_rows)) * 100, 1) : 100.0;
    $trend_dns = bk_trend_direction($dns_health, $prev_snapshot['dns_health'] ?? null, 0.5);

    // --- Biggest changes (latence podle regionu vs. uložený snapshot) ---
    $biggest_changes = [];
    $prev_regions = $prev_snapshot['regions'] ?? [];
    foreach ($regions as $r) {
        if ($r['avg_latency'] === null || !isset($prev_regions[$r['name']]) || $prev_regions[$r['name']] <= 0) continue;
        $pct_change = round((($r['avg_latency'] - $prev_regions[$r['name']]) / $prev_regions[$r['name']]) * 100);
        if (abs($pct_change) < 5) continue; // ignorovat šum pod 5 %
        $biggest_changes[] = [
            'label' => ($pct_change < 0 ? t('digest_latency_improved') : t('digest_latency_increased')),
            'detail' => $r['name'],
            'delta_text' => ($pct_change > 0 ? '+' : '') . $pct_change . '%',
            'is_good' => $pct_change < 0,
        ];
    }
    usort($biggest_changes, function ($a, $b) {
        return abs((int)$b['delta_text']) <=> abs((int)$a['delta_text']);
    });
    $biggest_changes = array_slice($biggest_changes, 0, 3);

    // --- Performance (nejlepší/nejhorší region podle latence) ---
    $perf_best = null;
    $perf_worst = null;
    foreach ($regions as $r) {
        if ($r['avg_latency'] === null) continue;
        if ($perf_best === null || $r['avg_latency'] < $perf_best['avg_latency']) $perf_best = $r;
        if ($perf_worst === null || $r['avg_latency'] > $perf_worst['avg_latency']) $perf_worst = $r;
    }

    // --- Biggest incident (aproximace: souvislé úseky 'down' řádků, mezera > 15 min = nový incident) ---
    $stmt_down = $pdo->prepare("
        SELECT l.monitor_id, m.name, l.checked_at, l.checked_from, l.error_message, m.status as current_status
        FROM monitor_logs l
        JOIN monitors m ON m.id = l.monitor_id
        WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND l.status = 'down'
        ORDER BY l.monitor_id ASC, l.checked_at ASC
        LIMIT 2000
    ");
    $stmt_down->execute([$days]);
    $down_rows = $stmt_down->fetchAll();

    $streaks = [];
    $cur = null;
    foreach ($down_rows as $row) {
        $ts = strtotime($row['checked_at']);
        if ($cur === null || $cur['monitor_id'] !== $row['monitor_id'] || ($ts - $cur['last_ts']) > 900) {
            if ($cur !== null) $streaks[] = $cur;
            $cur = [
                'monitor_id' => $row['monitor_id'], 'name' => $row['name'],
                'first_ts' => $ts, 'last_ts' => $ts,
                'checked_from' => $row['checked_from'], 'error_message' => $row['error_message'],
                'current_status' => $row['current_status'],
            ];
        } else {
            $cur['last_ts'] = $ts;
        }
    }
    if ($cur !== null) $streaks[] = $cur;

    $biggest_incident = null;
    foreach ($streaks as $s) {
        $dur = max(60, $s['last_ts'] - $s['first_ts']); // min. 60s, jde jen o aproximaci
        if ($biggest_incident === null || $dur > $biggest_incident['duration_sec']) {
            $biggest_incident = [
                'monitor' => $s['name'],
                // Nezaznamenane misto kontroly zustava null - "Main Server"
                // by tvrdilo, odkud se meril, aniz to kdokoli zapsal.
                'location' => $s['checked_from'] ?: null,
                'reason' => $s['error_message'] ?: t('digest_unspecified_error'),
                'duration_sec' => $dur,
                'resolved' => $s['current_status'] !== 'down',
                'date' => date('d.m.Y', $s['first_ts']),
            ];
        }
    }

    // --- Doporučení (znovupoužita i jako počet "warnings") ---
    $recommendations = [];
    foreach ($expiring_list as $c) {
        if ($c['days_remaining'] <= 0) {
            $recommendations[] = sprintf(t('digest_cert_expired'), $c['name']);
        } else {
            $recommendations[] = sprintf(t('digest_cert_expiring'), $c['name'], $c['days_remaining']);
        }
    }
    foreach ($agent_health as $ah) {
        if ($ah['cpu_usage'] >= $ah['cpu_threshold']) $recommendations[] = sprintf(t('digest_cpu_high'), $ah['name'], $ah['cpu_threshold']);
        if ($ah['ram_usage'] >= $ah['ram_threshold']) $recommendations[] = sprintf(t('digest_ram_high'), $ah['name'], $ah['ram_threshold']);
        if ($ah['hdd_usage'] >= $ah['hdd_threshold']) $recommendations[] = sprintf(t('digest_hdd_high'), $ah['name'], $ah['hdd_threshold']);
    }
    if ($dns_failures > 0) {
        $recommendations[] = sprintf(t('digest_dns_failing'), $dns_failures);
    }
    // Monitory bez IPv6 (aktuální last_details, jen 'web' typ)
    $stmt_ipv6 = $pdo->query("SELECT name, last_details FROM monitors WHERE type = 'web'");
    foreach ($stmt_ipv6->fetchAll() as $m) {
        $ld = json_decode($m['last_details'] ?? '', true);
        if (is_array($ld) && ($ld['has_ipv4'] ?? false) && empty($ld['has_ipv6'])) {
            $recommendations[] = sprintf(t('digest_no_ipv6'), $m['name']);
        }
    }
    if ($perf_worst !== null && $perf_worst['avg_latency'] !== null && $perf_worst['avg_latency'] > 200) {
        $recommendations[] = sprintf(t('digest_high_latency'), $perf_worst['name'], $perf_worst['avg_latency']);
    }
    $warning_count = count($recommendations);

    // --- Executive Summary (pravidly generované věty, ne AI) ---
    $executive_summary = [];
    if ($score >= 95) {
        $executive_summary[] = t('digest_summary_healthy');
    } elseif ($score >= 80) {
        $executive_summary[] = t('digest_summary_mostly_healthy');
    } else {
        $executive_summary[] = t('digest_summary_needs_attention');
    }
    $executive_summary[] = sprintf(t('digest_summary_availability'), number_format($availability, 3, ',', ' '));
    if ($trend_latency === 'down') {
        $executive_summary[] = t('digest_summary_latency_improved');
    } elseif ($trend_latency === 'up') {
        $executive_summary[] = t('digest_summary_latency_worsened');
    }
    if ($incident_count === 0) {
        $executive_summary[] = t('digest_summary_no_outages');
    } elseif ($biggest_incident !== null) {
        $incident_phrase = $incident_count > 1 ? sprintf(t('digest_summary_incidents_plural'), $incident_count) : t('digest_summary_incident_singular');
        $executive_summary[] = sprintf(t('digest_summary_incident_detail'), $incident_phrase, $biggest_incident['monitor'], $biggest_incident['location']);
    }
    if (!empty($recommendations)) {
        $executive_summary[] = sprintf(t('digest_summary_recommended_action'), $recommendations[0]);
    } else {
        $executive_summary[] = t('digest_summary_no_critical_action');
    }

    $data = [
        'period' => $period,
        'days' => $days,
        'site_title' => $site_title,
        'range_from' => $range_from,
        'range_to' => $range_to,
        'score' => $score,
        'trend_score' => $trend_score,
        'score_prev' => $prev_snapshot['score'] ?? null,
        'availability' => $availability,
        'trend_availability' => $trend_availability,
        'avg_latency' => $avg_latency,
        'trend_latency' => $trend_latency,
        'incident_count' => $incident_count,
        'warning_count' => $warning_count,
        'total_checks' => $total_checks,
        'agent_count' => $agent_count,
        'region_count' => $region_count,
        'avg_cpu' => $avg_cpu, 'trend_cpu' => $trend_cpu,
        'avg_ram' => $avg_ram, 'trend_ram' => $trend_ram,
        'dns_health' => $dns_health, 'trend_dns' => $trend_dns,
        'best_monitors' => $best_monitors,
        'worst_monitors' => $worst_monitors,
        'biggest_changes' => $biggest_changes,
        'regions' => $regions,
        'agent_health' => $agent_health,
        'ssl' => ['expiring' => $certs_expiring, 'renewed' => $certs_renewed, 'expired' => $certs_expired, 'list' => array_slice($expiring_list, 0, 6)],
        'dns' => ['failures' => $dns_failures, 'slow' => $dns_slow],
        'biggest_incident' => $biggest_incident,
        'performance' => ['avg' => $avg_latency, 'trend' => $trend_latency, 'best' => $perf_best, 'worst' => $perf_worst],
        'new_servers' => $new_servers,
        'removed_servers' => $removed_servers,
        'config_change_examples' => array_slice($config_change_examples, 0, 6),
        'recommendations' => array_slice($recommendations, 0, 8),
        'executive_summary' => $executive_summary,
    ];

    if ($period === 'monthly') {
        $data['monthly'] = build_monthly_digest_extras($pdo, $days, $regions, $prev_snapshot, $score, $event_counts);
    }

    // --- Uložit snapshot pro příští období (dostupnost, latence, skóre, regiony atd.) ---
    // Přeskočeno u náhledu (preview) - opakované prohlížení by jinak přepisovalo
    // srovnávací základ dřív, než reálně proběhne odpovídající období.
    if ($save_snapshot) {
        $region_latency_map = [];
        foreach ($regions as $r) {
            if ($r['avg_latency'] !== null) $region_latency_map[$r['name']] = $r['avg_latency'];
        }
        $new_snapshot = [
            'score' => $score, 'availability' => $availability, 'avg_latency' => $avg_latency,
            'avg_cpu' => $avg_cpu, 'avg_ram' => $avg_ram, 'dns_health' => $dns_health,
            'regions' => $region_latency_map, 'saved_at' => date('c'),
        ];
        try {
            $stmt_snap = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
            $stmt_snap->execute([$snapshot_key, json_encode($new_snapshot, JSON_UNESCAPED_UNICODE)]);
        } catch (PDOException $e) {
            // Ignorujeme - report se i tak odešle, jen příští trend nebude mít srovnání
        }
    }

    return $data;
}

/**
 * Doplňkové sekce jen pro měsíční report (SLA, nejlepší/nejhorší den, heatmapy, růst).
 */
function build_monthly_digest_extras($pdo, $days, $regions, $prev_snapshot, $score, $event_counts) {
    $sla_goal = (float)get_setting('sla_goal_pct', '99.95');

    // Nejlepší/nejhorší den (poměr se dopočítává v PHP, ne v ORDER BY - stejný důvod jako u worst monitors výše)
    $stmt_days = $pdo->prepare("
        SELECT DATE(checked_at) as d,
               SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(checked_at)
    ");
    $stmt_days->execute([$days]);
    $day_rows = $stmt_days->fetchAll();
    $best_day = null;
    $worst_day = null;
    foreach ($day_rows as $d) {
        if ($d['total_count'] <= 0) continue;
        $uptime = round(($d['up_count'] / $d['total_count']) * 100, 2);
        $entry = ['date' => date('d.m.', strtotime($d['d'])), 'uptime' => $uptime];
        if ($best_day === null || $uptime > $best_day['uptime']) $best_day = $entry;
        if ($worst_day === null || $uptime < $worst_day['uptime']) $worst_day = $entry;
    }

    // Nejlepší/nejhorší region (z už spočtených dat)
    $best_region = null;
    $worst_region = null;
    foreach ($regions as $r) {
        if ($best_region === null || $r['uptime'] > $best_region['uptime']) $best_region = $r;
        if ($worst_region === null || $r['uptime'] < $worst_region['uptime']) $worst_region = $r;
    }

    // Incident heatmap podle dne v týdnu (agregováno přes celý měsíc)
    $stmt_dow = $pdo->prepare("
        SELECT DAYOFWEEK(checked_at) as dow, COUNT(*) as cnt
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND status = 'down'
        GROUP BY DAYOFWEEK(checked_at)
    ");
    $stmt_dow->execute([$days]);
    // MySQL DAYOFWEEK: 1=neděle..7=sobota -> mapujeme na neutrální klíče mon-sun.
    // Klíče musí zůstat jazykově neutrální (ne 'Po'/'Út'/...), protože se překlad
    // řeší až při renderu (render_digest_html) - jinak by přepnutí email_lang na
    // 'en' muselo měnit i strukturu tohohle pole, ne jen zobrazený popisek.
    $dow_map = [2 => 'mon', 3 => 'tue', 4 => 'wed', 5 => 'thu', 6 => 'fri', 7 => 'sat', 1 => 'sun'];
    $incident_heatmap = ['mon' => 0, 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0];
    foreach ($stmt_dow->fetchAll() as $row) {
        $label = $dow_map[(int)$row['dow']] ?? null;
        if ($label !== null) $incident_heatmap[$label] = (int)$row['cnt'];
    }

    // Latency heatmap - z regionů, obarveno podle pásma
    $latency_heatmap = [];
    foreach ($regions as $r) {
        if ($r['avg_latency'] === null) continue;
        $band = $r['avg_latency'] < 50 ? 'green' : ($r['avg_latency'] < 150 ? 'yellow' : 'red');
        $latency_heatmap[] = ['region' => $r['name'], 'ms' => $r['avg_latency'], 'band' => $band];
    }

    // Růst
    $new_monitors_count = $event_counts['monitor_added'] ?? 0;
    $stmt_new_users = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt_new_users->execute([$days]);
    $new_users_count = (int)$stmt_new_users->fetchColumn();

    return [
        'sla_goal' => $sla_goal,
        'best_day' => $best_day, 'worst_day' => $worst_day,
        'best_region' => $best_region, 'worst_region' => $worst_region,
        'incident_heatmap' => $incident_heatmap,
        'latency_heatmap' => $latency_heatmap,
        'growth' => ['new_monitors' => $new_monitors_count, 'new_users' => $new_users_count],
        'score_last_month' => $prev_snapshot['score'] ?? null,
    ];
}

/**
 * Sdílený obal (header/footer/základní styly) pro e-maily infrastructure reportu.
 * Odděleno od šablony upozornění v trigger_notifications() - ta zůstává beze změny.
 */
function render_email_wrapper($title, $subtitle, $accent_color, $body_html) {
    // Veškerý layout je inline (+ reálné <table>), ne v <style> bloku - Gmail,
    // Outlook a většina webmailů <style>/<head> při doručení ořízne, takže by
    // e-mail dorazil bez formátování. Viz stejný přístup u trigger_notifications().
    $font = "font-family: Arial, Helvetica, sans-serif;";
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($title) . '</title>
    </head>
    <body style="margin:0; padding:20px; background-color:#0f0f13; ' . $font . '">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td align="center">
                    <table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="max-width:640px; width:100%; background-color:#1a1a24; border-radius:8px; border-top:5px solid ' . $accent_color . '; overflow:hidden;">
                        <tr>
                            <td style="padding:25px; text-align:center; background-color:#12121a;">
                                <h1 style="margin:0; font-size:21px; color:#ffffff; ' . $font . '">' . htmlspecialchars($title) . '</h1>
                                <p style="margin:6px 0 0 0; color:#888896; font-size:13px; ' . $font . '">' . $subtitle . '</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:28px; line-height:1.55; color:#e1e1e6; font-size:14px; ' . $font . '">' . $body_html . '</td>
                        </tr>
                        <tr>
                            <td style="padding:15px 30px; text-align:center; font-size:12px; color:#888896; border-top:1px solid #22222f; background-color:#12121a; ' . $font . '">' . htmlspecialchars(get_setting('site_title', 'Blood Kings Status')) . ' &mdash; ' . date('d.m.Y H:i') . '</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
}

function bk_trend_glyph($direction, $good_when_up = true) {
    if ($direction === null || $direction === 'flat') {
        return '<span style="color:#888896;">=</span>';
    }
    $is_good = ($direction === 'up' && $good_when_up) || ($direction === 'down' && !$good_when_up);
    $color = $is_good ? '#1ec773' : '#ef233c';
    $arrow = $direction === 'up' ? '&uarr;' : '&darr;';
    return '<span style="color:' . $color . ';">' . $arrow . '</span>';
}

function bk_email_stat_box($value, $label) {
    $font = "font-family: Arial, Helvetica, sans-serif;";
    return '<td align="center" valign="top" style="padding:10px 4px; background-color:#12121a;">'
        . '<div style="font-size:19px; font-weight:bold; color:#ffffff; ' . $font . '">' . $value . '</div>'
        . '<div style="font-size:10px; color:#888896; text-transform:uppercase; margin-top:4px; ' . $font . '">' . htmlspecialchars($label) . '</div>'
        . '</td>';
}

/**
 * Obalí několik bk_email_stat_box() buněk do skutečné <table><tr> - e-mailoví
 * klienti CSS "display: table" (dřívější .stat-grid) nerespektují.
 */
function bk_email_stat_grid($cells_html) {
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; margin-bottom:8px;"><tr>' . $cells_html . '</tr></table>';
}

function bk_email_section($title, $inner_html) {
    $font = "font-family: Arial, Helvetica, sans-serif;";
    return '<div style="margin-bottom:26px;">'
        . '<div style="font-size:12px; text-transform:uppercase; letter-spacing:0.05em; color:#888896; margin-bottom:10px; font-weight:bold; ' . $font . '">' . htmlspecialchars($title) . '</div>'
        . $inner_html
        . '</div>';
}

/**
 * Otevírací <table><thead> pro report-table sekce digestu (nahrazuje dřívější
 * .report-table CSS třídu, kterou e-mailoví klienti ignorují). $headers je pole
 * popisků; první je vlevo, zbytek zarovnaný vpravo (číselné sloupce).
 */
function bk_email_report_table_open(array $headers) {
    $th_base = 'padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;';
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; font-size:13px; margin-top:4px;"><thead><tr>';
    foreach ($headers as $i => $h) {
        $align = $i === 0 ? 'left' : 'right';
        $html .= '<th style="text-align:' . $align . '; ' . $th_base . '">' . htmlspecialchars($h) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    return $html;
}

function bk_email_report_table_row(array $cells) {
    $td_base = 'padding:7px 10px; border-top:1px solid #22222f;';
    $html = '<tr>';
    foreach ($cells as $i => $cell) {
        $align = $i === 0 ? 'left' : 'right';
        $color = $cell['color'] ?? '#e1e1e6';
        $html .= '<td style="text-align:' . $align . '; ' . $td_base . ' color:' . $color . ';">' . $cell['html'] . '</td>';
    }
    $html .= '</tr>';
    return $html;
}

function bk_email_kv($label, $value_html) {
    $font = "font-family: Arial, Helvetica, sans-serif;";
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-top:1px solid #22222f;"><tr>'
        . '<td style="padding:6px 0; color:#888896; font-size:13px; ' . $font . '">' . htmlspecialchars($label) . '</td>'
        . '<td align="right" style="padding:6px 0; font-weight:bold; font-size:13px; color:#ffffff; ' . $font . '">' . $value_html . '</td>'
        . '</tr></table>';
}

/**
 * Vykreslí kompletní infrastructure report (weekly i monthly) do HTML e-mailu.
 * Struktura odpovídá 4 blokům: Executive Summary / Operational Overview /
 * Technical Insights / Recommendations.
 */
function render_digest_html($data) {
    $is_monthly = $data['period'] === 'monthly';
    $period_label = $is_monthly ? t('digest_title_monthly') : t('digest_title_weekly');
    $score_color = $data['score'] >= 90 ? '#1ec773' : ($data['score'] >= 70 ? '#f39c12' : '#ef233c');
    $accent_color = $data['score'] >= 70 ? '#1ec773' : '#c1121f';

    $body = '';

    // --- Hero: Infrastructure Score ---
    $score_delta_html = '';
    if ($data['score_prev'] !== null) {
        $delta = $data['score'] - $data['score_prev'];
        $delta_color = $delta > 0 ? '#1ec773' : ($delta < 0 ? '#ef233c' : '#888896');
        $delta_sign = $delta > 0 ? '+' : '';
        $score_delta_html = '<div style="margin-top:6px; font-size:13px; color:' . $delta_color . ';">' . bk_trend_glyph($data['trend_score']) . ' ' . $delta_sign . $delta . ' ' . htmlspecialchars(t('digest_vs_previous_period')) . '</div>';
    }
    $body .= '<div style="text-align:center; margin-bottom:28px;">
        <div style="font-size:11px; color:#888896; text-transform:uppercase; letter-spacing:0.05em;">' . htmlspecialchars(t('digest_hero_score_label')) . '</div>
        <div style="font-size:48px; font-weight:bold; color:' . $score_color . '; line-height:1.3;">' . $data['score'] . '<span style="font-size:20px; color:#888896;">/100</span></div>'
        . $score_delta_html .
    '</div>';

    // --- Executive Summary ---
    $exec_html = '';
    foreach ($data['executive_summary'] as $line) {
        $exec_html .= '<p style="margin:5px 0; font-size:14px;">' . htmlspecialchars($line) . '</p>';
    }
    $body .= '<div style="background-color:#12121a; border-radius:6px; padding:16px 18px; margin-bottom:26px;">' . $exec_html . '</div>';

    // --- Operational Overview: KPI mřížka ---
    $na = t('digest_na');
    $stat_html = bk_email_stat_box(number_format($data['availability'], 3, ',', ' ') . '%', t('digest_stat_availability'))
        . bk_email_stat_box(($data['avg_latency'] !== null ? $data['avg_latency'] . ' ms' : $na), t('digest_stat_latency'))
        . bk_email_stat_box($data['incident_count'], t('digest_stat_incidents'))
        . bk_email_stat_box($data['warning_count'], t('digest_stat_warnings'));
    $stat_html2 = bk_email_stat_box(number_format($data['total_checks'], 0, ',', ' '), t('digest_stat_checks'))
        . bk_email_stat_box($data['agent_count'], t('digest_stat_agents'))
        . bk_email_stat_box($data['region_count'], t('digest_stat_regions'))
        . bk_email_stat_box($is_monthly ? ($data['monthly']['sla_goal'] . '%') : '&mdash;', $is_monthly ? t('digest_stat_sla_goal') : '');
    $body .= bk_email_section(t('digest_section_overview'), bk_email_stat_grid($stat_html) . bk_email_stat_grid($stat_html2));

    // --- Trend ---
    $trend_html = bk_email_kv(t('digest_stat_availability'), bk_trend_glyph($data['trend_availability']))
        . bk_email_kv(t('digest_stat_latency'), bk_trend_glyph($data['trend_latency'], false))
        . bk_email_kv(t('digest_stat_dns'), bk_trend_glyph($data['trend_dns']));
    if ($data['avg_cpu'] !== null) {
        $trend_html .= bk_email_kv(t('digest_stat_cpu'), bk_trend_glyph($data['trend_cpu'], false)) . bk_email_kv(t('digest_stat_ram'), bk_trend_glyph($data['trend_ram'], false));
    }
    $body .= bk_email_section(t('digest_section_trend'), $trend_html);

    // --- Nejlepší / nejhorší monitory ---
    if (!empty($data['best_monitors']) || !empty($data['worst_monitors'])) {
        $bw_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; font-size:13px; margin-top:4px;"><thead><tr>'
            . '<th style="text-align:left; padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;">' . htmlspecialchars(t('digest_col_monitor')) . '</th>'
            . '<th style="text-align:right; padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;">' . htmlspecialchars(t('digest_col_availability')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($data['best_monitors'] as $m) {
            $bw_html .= '<tr><td style="padding:7px 10px; border-top:1px solid #22222f; color:#e1e1e6;">' . htmlspecialchars($m['name']) . '</td><td style="padding:7px 10px; border-top:1px solid #22222f; text-align:right; color:#1ec773;">100%</td></tr>';
        }
        foreach ($data['worst_monitors'] as $m) {
            $u = $m['total_count'] > 0 ? round(($m['up_count'] / $m['total_count']) * 100, 2) : 100.0;
            $bw_html .= '<tr><td style="padding:7px 10px; border-top:1px solid #22222f; color:#e1e1e6;">' . htmlspecialchars($m['name']) . '</td><td style="padding:7px 10px; border-top:1px solid #22222f; text-align:right; color:#ef233c;">' . $u . '%</td></tr>';
        }
        if (empty($data['worst_monitors'])) {
            $bw_html .= '<tr><td colspan="2" style="padding:7px 10px; border-top:1px solid #22222f; color:#888896;">' . htmlspecialchars(t('digest_summary_no_outages')) . '</td></tr>';
        }
        $bw_html .= '</tbody></table>';
        $body .= bk_email_section(t('digest_section_best_worst_monitors'), $bw_html);
    }

    // --- Největší změny ---
    if (!empty($data['biggest_changes'])) {
        $chg_html = '';
        foreach ($data['biggest_changes'] as $c) {
            $color = $c['is_good'] ? '#1ec773' : '#ef233c';
            $chg_html .= bk_email_kv($c['label'] . ' — ' . $c['detail'], '<span style="color:' . $color . ';">' . $c['delta_text'] . '</span>');
        }
        $body .= bk_email_section(t('digest_section_biggest_changes'), $chg_html);
    }

    // --- Region overview ---
    if (!empty($data['regions'])) {
        $reg_html = bk_email_report_table_open([t('digest_col_region'), t('digest_col_availability'), t('digest_col_latency')]);
        foreach ($data['regions'] as $r) {
            $reg_html .= bk_email_report_table_row([
                ['html' => htmlspecialchars($r['name'])],
                ['html' => $r['uptime'] . '%'],
                ['html' => ($r['avg_latency'] !== null ? $r['avg_latency'] . ' ms' : $na)],
            ]);
        }
        $reg_html .= '</tbody></table>';
        $body .= bk_email_section(t('digest_section_regions'), $reg_html);
    }

    // --- Agent Health ---
    if (!empty($data['agent_health'])) {
        $ah_html = bk_email_report_table_open([t('digest_col_agent'), t('digest_stat_cpu'), t('digest_stat_ram'), t('digest_col_disk')]);
        foreach ($data['agent_health'] as $ah) {
            $ah_html .= bk_email_report_table_row([
                ['html' => htmlspecialchars($ah['name'])],
                ['html' => $ah['cpu_usage'] . '%'],
                ['html' => $ah['ram_usage'] . '%'],
                ['html' => $ah['hdd_usage'] . '%'],
            ]);
        }
        $ah_html .= '</tbody></table>';
        $body .= bk_email_section(t('digest_section_agent_health'), $ah_html);
    }

    // --- SSL ---
    $ssl_html = bk_email_kv(t('digest_ssl_expiring'), $data['ssl']['expiring']) . bk_email_kv(t('digest_ssl_renewed'), $data['ssl']['renewed']) . bk_email_kv(t('digest_ssl_expired'), $data['ssl']['expired']);
    $body .= bk_email_section(t('digest_section_ssl'), $ssl_html);

    // --- DNS ---
    $dns_html = bk_email_kv(t('digest_dns_failures'), $data['dns']['failures']) . bk_email_kv(t('digest_dns_slow'), $data['dns']['slow']);
    $body .= bk_email_section(t('digest_section_dns'), $dns_html);

    // --- Biggest incident ---
    if ($data['biggest_incident'] !== null) {
        $bi = $data['biggest_incident'];
        $dur_min = round($bi['duration_sec'] / 60);
        $status_color = $bi['resolved'] ? '#1ec773' : '#ef233c';
        $status_text = $bi['resolved'] ? t('digest_incident_resolved') : t('digest_incident_ongoing');
        $bi_html = '<div style="background-color:#12121a; border-radius:6px; padding:16px;">'
            . '<div style="font-size:15px; font-weight:bold; color:#ffffff;">' . htmlspecialchars($bi['monitor']) . '</div>'
            . '<div style="font-size:13px; color:#888896; margin-top:2px;">' . htmlspecialchars($bi['location']) . ' &middot; ' . htmlspecialchars($bi['date']) . '</div>'
            . '<div style="font-size:13px; color:#e1e1e6; margin-top:8px;">' . htmlspecialchars($bi['reason']) . '</div>'
            . '<div style="margin-top:10px; font-size:13px;"><span style="color:#888896;">' . htmlspecialchars(t('digest_incident_duration_label')) . '</span> <strong style="color:#ffffff;">' . $dur_min . ' min</strong> &middot; <span style="color:' . $status_color . '; font-weight:bold;">' . htmlspecialchars($status_text) . '</span></div>'
            . '</div>';
        $body .= bk_email_section(t('digest_section_biggest_incident'), $bi_html);
    }

    // --- Performance ---
    $perf_html = bk_email_kv(t('digest_perf_avg_latency'), ($data['performance']['avg'] !== null ? $data['performance']['avg'] . ' ms' : $na) . ' ' . bk_trend_glyph($data['performance']['trend'], false));
    if ($data['performance']['best'] !== null) {
        $perf_html .= bk_email_kv(t('digest_perf_best'), htmlspecialchars($data['performance']['best']['name']) . ' &middot; ' . $data['performance']['best']['avg_latency'] . ' ms');
    }
    if ($data['performance']['worst'] !== null) {
        $perf_html .= bk_email_kv(t('digest_perf_worst'), htmlspecialchars($data['performance']['worst']['name']) . ' &middot; ' . $data['performance']['worst']['avg_latency'] . ' ms');
    }
    $body .= bk_email_section(t('digest_section_performance'), $perf_html);

    // --- Nové / odstraněné servery ---
    if (!empty($data['new_servers']) || !empty($data['removed_servers'])) {
        $ns_html = '';
        $site_url = rtrim((string)get_setting('site_url', ''), '/');
        foreach ($data['new_servers'] as $s) {
            $ns_label = htmlspecialchars($s['name']) . ' <span style="color:#888896;">(' . htmlspecialchars($s['type']) . ')</span>';
            if ($site_url !== '' && !empty($s['id'])) {
                $ns_label = '<a href="' . htmlspecialchars($site_url . '/index.php?expand=' . (int)$s['id']) . '" style="color:#1ec773; text-decoration: underline;">' . $ns_label . '</a>';
            }
            $ns_html .= '<div style="color:#1ec773; font-size:13px; padding:3px 0;">+ ' . $ns_label . '</div>';
        }
        foreach ($data['removed_servers'] as $s) {
            // Odstraněný monitor už neexistuje - proklik cíleně nejde (viz build_digest_data()).
            $ns_html .= '<div style="color:#ef233c; font-size:13px; padding:3px 0;">- ' . htmlspecialchars($s['name']) . ' <span style="color:#888896;">(' . htmlspecialchars($s['type']) . ')</span></div>';
        }
        $body .= bk_email_section(t('digest_section_new_removed_servers'), $ns_html);
    }

    // --- Změny konfigurace ---
    if (!empty($data['config_change_examples'])) {
        $cc_html = '';
        foreach ($data['config_change_examples'] as $c) {
            $cc_html .= '<div style="font-size:13px; padding:3px 0; color:#e1e1e6;">&middot; ' . htmlspecialchars($c) . '</div>';
        }
        $body .= bk_email_section(t('digest_section_config_changes'), $cc_html);
    }

    // --- Monthly-only sekce ---
    if ($is_monthly && isset($data['monthly'])) {
        $mo = $data['monthly'];

        $sla_reached = $data['availability'] >= $mo['sla_goal'];
        $sla_html = bk_email_kv(t('digest_sla_current'), number_format($data['availability'], 3, ',', ' ') . '%')
            . bk_email_kv(t('digest_sla_goal'), $mo['sla_goal'] . '%')
            . bk_email_kv(t('digest_sla_status'), '<span style="color:' . ($sla_reached ? '#1ec773' : '#ef233c') . ';">' . htmlspecialchars($sla_reached ? t('digest_sla_met') : t('digest_sla_not_met')) . '</span>');
        $body .= bk_email_section(t('digest_section_sla'), $sla_html);

        if ($mo['best_day'] !== null || $mo['worst_day'] !== null) {
            $day_html = '';
            if ($mo['best_day'] !== null) $day_html .= bk_email_kv(t('digest_best_day'), $mo['best_day']['date'] . ' &middot; ' . $mo['best_day']['uptime'] . '%');
            if ($mo['worst_day'] !== null) $day_html .= bk_email_kv(t('digest_worst_day'), $mo['worst_day']['date'] . ' &middot; ' . $mo['worst_day']['uptime'] . '%');
            $body .= bk_email_section(t('digest_section_best_worst_day'), $day_html);
        }

        if ($mo['best_region'] !== null || $mo['worst_region'] !== null) {
            $reg2_html = '';
            if ($mo['best_region'] !== null) $reg2_html .= bk_email_kv(t('digest_best_region'), htmlspecialchars($mo['best_region']['name']) . ' &middot; ' . $mo['best_region']['uptime'] . '%');
            if ($mo['worst_region'] !== null) $reg2_html .= bk_email_kv(t('digest_worst_region'), htmlspecialchars($mo['worst_region']['name']) . ' &middot; ' . $mo['worst_region']['uptime'] . '%');
            $body .= bk_email_section(t('digest_section_best_worst_region'), $reg2_html);
        }

        // Incident heatmap - barevné buňky tabulky (email klienti neumí CSS grid).
        // $day je neutrální klíč (mon/tue/...) z build_monthly_digest_extras() -
        // zobrazený popisek se překládá až tady přes digest_day_*.
        $hm_html = '<table style="width:100%; border-collapse:collapse;"><tr>';
        foreach ($mo['incident_heatmap'] as $day => $cnt) {
            $bgcolor = $cnt === 0 ? '#1ec773' : ($cnt <= 2 ? '#f39c12' : '#ef233c');
            $hm_html .= '<td bgcolor="' . $bgcolor . '" style="background-color:' . $bgcolor . '; text-align:center; font-size:11px; color:#0f0f13; font-weight:bold; padding:8px 0;">' . htmlspecialchars(t('digest_day_' . $day)) . '<br>' . $cnt . '</td>';
        }
        $hm_html .= '</tr></table>';
        $body .= bk_email_section(t('digest_section_incident_heatmap'), $hm_html);

        // Latency heatmap - jeden řádek na region
        if (!empty($mo['latency_heatmap'])) {
            $lhm_html = '<table style="width:100%; border-collapse:collapse;">';
            foreach ($mo['latency_heatmap'] as $lh) {
                $bgcolor = $lh['band'] === 'green' ? '#1ec773' : ($lh['band'] === 'yellow' ? '#f39c12' : '#ef233c');
                $lhm_html .= '<tr><td style="padding:4px 8px; font-size:12px; color:#e1e1e6;">' . htmlspecialchars($lh['region']) . '</td>'
                    . '<td bgcolor="' . $bgcolor . '" style="background-color:' . $bgcolor . '; width:60%;">&nbsp;</td>'
                    . '<td style="padding:4px 8px; font-size:12px; text-align:right; color:#ffffff;">' . $lh['ms'] . ' ms</td></tr>';
            }
            $lhm_html .= '</table>';
            $body .= bk_email_section(t('digest_section_latency_heatmap'), $lhm_html);
        }

        $growth_html = bk_email_stat_box('+' . $mo['growth']['new_monitors'], t('digest_growth_new_monitors')) . bk_email_stat_box('+' . $mo['growth']['new_users'], t('digest_growth_new_users'));
        $body .= bk_email_section(t('digest_section_growth'), bk_email_stat_grid($growth_html));

        if ($mo['score_last_month'] !== null) {
            $score_cmp_html = bk_email_kv(t('digest_score_last_month'), $mo['score_last_month']) . bk_email_kv(t('digest_score_this_month'), $data['score']);
            $body .= bk_email_section(t('digest_section_health_score_compare'), $score_cmp_html);
        }
    }

    // --- Recommendations ---
    $rec_html = '';
    if (empty($data['recommendations'])) {
        $rec_html = '<div style="font-size:13px; color:#1ec773;">' . htmlspecialchars(t('digest_no_recommendations')) . '</div>';
    } else {
        foreach ($data['recommendations'] as $r) {
            $rec_html .= '<div style="font-size:13px; padding:4px 0; color:#e1e1e6;">&bull; ' . htmlspecialchars($r) . '</div>';
        }
    }
    $body .= bk_email_section(t('digest_section_recommendations'), $rec_html);

    $subtitle = htmlspecialchars($data['site_title']) . ' &middot; ' . $data['range_from'] . ' &ndash; ' . $data['range_to'];
    return render_email_wrapper('📊 ' . $period_label, $subtitle, $accent_color, $body);
}

function send_digest_report_inner($pdo, $period = 'weekly') {
    // Data jsou jazykově neutrální a staví se jednou; jazyk se uplatní až
    // při renderu HTML - jednou na každý jazyk mezi příjemci.
    $data = build_digest_data($pdo, $period);

    // Příjemci - všichni administrátoři se zadaným e-mailem, včetně jejich jazyka
    $stmt_admins = $pdo->query("SELECT email, email_lang FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
    $admins = $stmt_admins->fetchAll();
    if (empty($admins)) {
        $GLOBALS['last_mail_error'] = t('digest_error_no_admin_email');
        return false;
    }

    $default_lang = get_setting('email_lang', 'cs');
    $rendered_by_lang = [];
    $any_success = false;
    foreach ($admins as $adm) {
        $lang = in_array($adm['email_lang'] ?? '', ['cs', 'en'], true) ? $adm['email_lang'] : $default_lang;
        if (!isset($rendered_by_lang[$lang])) {
            $rendered_by_lang[$lang] = bk_with_email_lang($lang, function () use ($data, $period) {
                $period_label = ($period === 'monthly') ? t('digest_subject_monthly') : t('digest_subject_weekly');
                return [
                    "📊 $period_label – {$data['site_title']} ({$data['range_from']} – {$data['range_to']})",
                    render_digest_html($data),
                ];
            });
        }
        [$subject, $html_body] = $rendered_by_lang[$lang];
        if (send_email($adm['email'], $subject, $html_body)) {
            $any_success = true;
        }
    }

    return $any_success;
}

/**
 * Převod dvoumístného kódu země (např. CZ, DE) na emoji vlaječku
 */
function get_country_emoji($country_code) {
    $code = strtoupper($country_code);
    if (strlen($code) !== 2) return '🌐';
    $first = ord($code[0]) - 65 + 127462;
    $second = ord($code[1]) - 65 + 127462;
    return mb_convert_encoding('&#' . $first . ';&#' . $second . ';', 'UTF-8', 'HTML-ENTITIES');
}

/**
 * Automatická detekce geografické lokace a ASN serveru přes veřejné API
 */
function detect_server_location() {
    if (!function_exists('curl_init')) {
        return '🇨🇿 Praha, CZ';
    }
    
    $ch = curl_init("http://ip-api.com/json/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $resp = curl_exec($ch);
    curl_close($ch);
    
    if ($resp) {
        $data = json_decode($resp, true);
        if ($data && isset($data['status']) && $data['status'] === 'success') {
            // Chybejici udaje z geolokace se NEDOPLNUJI - driv se sem psalo
            // "Praha, CZ", takze kazdy neuspesny lookup tvrdil ceskou
            // lokalitu bez ohledu na to, kde server opravdu je.
            if (empty($data['city']) || empty($data['countryCode'])) {
                return null;
            }
            $flag = get_country_emoji($data['countryCode']);
            $city = $data['city'];
            $country = $data['countryCode'];
            
            $org = $data['org'] ?? $data['isp'] ?? '';
            $org_clean = '';
            if (!empty($org)) {
                $org_parts = explode(' ', $org);
                $org_clean = implode(' ', array_slice($org_parts, 0, 3));
            }
            
            return $flag . ' ' . $city . ', ' . $country . ($org_clean ? ' (' . $org_clean . ')' : '');
        }
    }
    return '🇨🇿 Praha, CZ'; // Výchozí fallback
}

/**
 * Kontrola cPanel status endpointu a načtení statistik
 */
function check_cpanel($url, $timeout = 5) {
    $start = microtime(true);
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BloodKingsStatusBot/1.0');
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $duration = round((microtime(true) - $start) * 1000);
        
        if ($response === false) {
            return [
                'status' => 'down',
                'response_time' => 0,
                'http_code' => 0,
                'error' => "cURL chyba: " . $error
            ];
        }
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            if ($data && isset($data['status']) && $data['status'] === 'ok') {
                return [
                    'status' => 'up',
                    'response_time' => $duration,
                    'error' => null,
                    'disk' => $data['disk'] ?? null,
                    'memory' => $data['memory'] ?? null,
                    'processes' => $data['processes'] ?? null,
                    'database' => $data['database'] ?? null,
                    'bandwidth' => $data['bandwidth'] ?? null,
                    'postgresql' => $data['postgresql'] ?? null,
                    // cpanel_stats.php exportuje i cpuusage (uapi StatsBar) - bez
                    // tohohle passthrough cron četl $cp_res['cpu'] vždy jako null
                    // a do vps_metrics zapisoval CPU trvale 0.0.
                    'cpu' => $data['cpu'] ?? null
                ];
            } else {
                return [
                    'status' => 'down',
                    'response_time' => $duration,
                    'http_code' => (int)$http_code,
                    'error' => 'Neplatný JSON formát nebo chybný bezpečnostní klíč.'
                ];
            }
        } else {
            return [
                'status' => 'down',
                'response_time' => $duration,
                'http_code' => (int)$http_code,
                'error' => "HTTP status kód: " . $http_code
            ];
        }
    } else {
        // Fallback bez cURL
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: BloodKingsStatusBot/1.0\r\n"
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
        $duration = round((microtime(true) - $start) * 1000);
        
        if ($response === false) {
            return [
                'status' => 'down',
                'response_time' => 0,
                'error' => 'Chyba při stahování dat přes stream.'
            ];
        }
        
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && $data['status'] === 'ok') {
            return [
                'status' => 'up',
                'response_time' => $duration,
                'error' => null,
                'disk' => $data['disk'] ?? null,
                'memory' => $data['memory'] ?? null,
                'processes' => $data['processes'] ?? null,
                'database' => $data['database'] ?? null,
                'bandwidth' => $data['bandwidth'] ?? null,
                'postgresql' => $data['postgresql'] ?? null
            ];
        } else {
            return [
                'status' => 'down',
                'response_time' => $duration,
                'error' => 'Neplatná struktura dat.'
            ];
        }
    }
}

/**
 * --- RFC 6238 TOTP 2FA ENGINE ---
 */
function bk_totp_base32_decode($b32) {
    $b32 = strtoupper($b32);
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buf = 0;
    $bufSize = 0;
    $res = '';

    for ($i = 0; $i < strlen($b32); $i++) {
        $c = $b32[$i];
        if ($c === '=') break;
        $v = strpos($chars, $c);
        if ($v === false) continue;

        $buf = ($buf << 5) | $v;
        $bufSize += 5;

        if ($bufSize >= 8) {
            $bufSize -= 8;
            $res .= chr(($buf >> $bufSize) & 0xFF);
        }
    }
    return $res;
}

function bk_totp_calculate($secret, $timeStep) {
    $key = bk_totp_base32_decode($secret);
    $data = pack('N*', 0) . pack('N*', $timeStep);
    $hash = hash_hmac('sha1', $data, $key, true);

    $offset = ord($hash[19]) & 0xf;
    $calc = (((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)) % 1000000;

    return str_pad((string)$calc, 6, '0', STR_PAD_LEFT);
}

function bk_totp_generate_secret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}

function bk_totp_verify_code($secret, $code, $discrepancy = 1) {
    if (empty($secret) || empty($code)) return false;
    $timeStep = floor(time() / 30);
    $code = trim($code);
    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        if (hash_equals(bk_totp_calculate($secret, $timeStep + $i), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Vykreslí kartu pro zapnutí/vypnutí 2FA na vlastním účtu (Profil administrátora
 * i běžného uživatele - obě sdílí tuhle jednu implementaci). QR kód se generuje
 * čistě na klientovi (knihovna qrcode z CDN) - secret se tak nikdy neposílá žádné třetí
 * straně jako u veřejných QR-generátorových API, jen zůstává v odpovědi vlastní
 * autentizované stránky.
 */
function bk_render_totp_section($me, $site_title) {
    $html = '<div class="admin-card" id="totp-section">'
        . '<div class="admin-header"><h2><i class="fas fa-shield-halved"></i> Dvoufázové ověření (2FA)</h2></div>';

    if (!empty($me['totp_enabled'])) {
        $html .= '<p style="font-size: 0.85rem; color: var(--color-green);"><i class="fas fa-check-circle"></i> 2FA je na tomhle účtu zapnuté.</p>'
            . '<form action="admin.php#totp-section" method="POST" style="max-width: 320px;">'
            . '<div class="form-group"><label for="totp_disable_password">Heslo pro potvrzení vypnutí</label>'
            . '<input type="password" name="totp_disable_password" id="totp_disable_password" class="form-control" autocomplete="off" required></div>'
            . '<button type="submit" name="totp_disable" class="btn btn-danger" onclick="return confirm(\'Opravdu vypnout 2FA? Účet pak bude chráněný jen heslem.\');"><i class="fas fa-shield-halved"></i> Vypnout 2FA</button>'
            . '</form>';
    } elseif (!empty($_SESSION['totp_pending_secret'])) {
        $secret = $_SESSION['totp_pending_secret'];
        $issuer = rawurlencode($site_title);
        $account = rawurlencode($me['username'] ?? 'admin');
        $otpauth_uri = "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        // Verze BK_CDN_QRCODE (viz konstanty na začátku souboru).
        $html .= '<p style="font-size: 0.85rem; color: var(--text-muted);">Naskenujte QR kód v autentikační aplikaci (např. Proton Pass) a potvrďte 6místným kódem.</p>'
            . '<canvas id="totp-qr" style="margin: 0.75rem 0; background: #fff; padding: 8px; border-radius: 6px;"></canvas>'
            . '<p style="font-size: 0.75rem; color: var(--text-muted);">Nebo zadejte ručně: <code style="user-select: all;">' . htmlspecialchars($secret) . '</code></p>'
            . '<form action="admin.php#totp-section" method="POST" style="max-width: 220px; margin-top: 0.75rem;">'
            . '<div class="form-group"><label for="totp_code">6místný kód z appky</label>'
            . '<input type="text" name="totp_code" id="totp_code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" required></div>'
            . '<button type="submit" name="totp_confirm" class="btn"><i class="fas fa-check"></i> Potvrdit a zapnout</button>'
            . '</form>'
            . '<script src="' . BK_CDN_QRCODE . '" integrity="' . BK_CDN_QRCODE_SRI . '" crossorigin="anonymous"></script>'
            . '<script>QRCode.toCanvas(document.getElementById("totp-qr"), ' . json_encode($otpauth_uri) . ', { width: 184 }, function (err) { if (err) console.error(err); });</script>';
    } else {
        $html .= '<p style="font-size: 0.85rem; color: var(--text-muted);">2FA je vypnuté. Doporučujeme ho zapnout, hlavně pokud se přihlašujete heslem (ne přes GitHub OAuth).</p>'
            . '<form action="admin.php#totp-section" method="POST" style="display:inline;">' . bk_csrf_field()
            . '<button type="submit" name="totp_setup_start" class="btn"><i class="fas fa-qrcode"></i> Zapnout 2FA</button></form>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Vykreslí kartu propojených OAuth účtů (Profil) - jen jeden poskytovatel
 * najednou (schema má jediný oauth_provider/oauth_id sloupec na uživatele).
 * Propojení jde jen skrz link_oauth (viz admin.php), nikdy podle e-mailu.
 */
function bk_render_oauth_section($me) {
    $providers = bk_oauth_providers();
    $linked_provider = $me['oauth_provider'] ?? null;

    $html = '<div id="oauth-section">';
    if (!empty($linked_provider) && isset($providers[$linked_provider])) {
        $cfg = $providers[$linked_provider];
        $html .= '<p style="font-size: 0.85rem;"><i class="' . htmlspecialchars($cfg['icon']) . '" style="color: ' . htmlspecialchars($cfg['brand_color']) . ';"></i> Propojeno s <strong>' . htmlspecialchars($cfg['label']) . '</strong>.</p>'
            . '<form action="admin.php#profile-section" method="POST" style="max-width: 320px;">' . bk_csrf_field()
            . '<div class="form-group"><label for="oauth_unlink_password">Heslo pro potvrzení odpojení</label>'
            . '<input type="password" name="oauth_unlink_password" id="oauth_unlink_password" class="form-control" autocomplete="off" required></div>'
            . '<button type="submit" name="oauth_unlink" class="btn btn-danger" onclick="return confirm(\'Opravdu odpojit propojený účet?\');"><i class="fas fa-link-slash"></i> Odpojit</button>'
            . '</form>';
    } else {
        $html .= '<p style="font-size: 0.85rem; color: var(--text-muted);">Žádný účet zatím není propojený. Propojení umožní přihlášení bez hesla.</p>'
            . '<div style="display: flex; flex-direction: column; gap: 0.5rem; max-width: 280px;">';
        $any_configured = false;
        foreach ($providers as $key => $cfg) {
            if (empty(get_setting('oauth_' . $key . '_client_id'))) continue;
            $any_configured = true;
            $html .= '<a href="admin.php?link_oauth=' . $key . '" class="btn btn-oauth" style="--oauth-bg: ' . htmlspecialchars($cfg['brand_color']) . ';"><i class="' . htmlspecialchars($cfg['icon']) . '"></i> Propojit ' . htmlspecialchars($cfg['label']) . '</a>';
        }
        if (!$any_configured) {
            $html .= '<p style="font-size: 0.8rem; color: var(--text-muted);">Žádný OAuth poskytovatel není nakonfigurovaný - nastavte Client ID/Secret v Nastavení -> Integrace.</p>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * CSRF ochrana (synchronizer token pattern) - jeden token na session, líné
 * vygenerování při první potřebě. bk_csrf_field() se vkládá do každého
 * <form>, bk_csrf_check() se volá na začátku každého handleru, co mění stav
 * (mazání, přepínání, odesílání e-mailů...). Čtecí/needitující akce (login
 * formulář samotný, logout, náhledy, prefill editace) token nepotřebují.
 */
function bk_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bk_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(bk_csrf_token()) . '">';
}

function bk_csrf_check() {
    $submitted = (string)($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        exit('Neplatný nebo vypršelý bezpečnostní token (CSRF). Obnovte stránku a zkuste akci znovu.');
    }
}

/**
 * Zápis do audit logu - kdo/kdy/co. $actor_user_id/$actor_username se berou
 * ze session, pokud nejsou předané explicitně (potřeba jen pro neúspěšný
 * login, kde přihlášený uživatel ještě neexistuje). Nikdy nevyhazuje výjimku
 * ven - audit log nesmí shodit samotnou akci, kterou zaznamenává.
 */
function bk_audit_log($pdo, $action, $description = '', $target_type = null, $target_id = null, $actor_user_id = null, $actor_username = null) {
    if ($actor_user_id === null && isset($_SESSION['admin_id'])) {
        $actor_user_id = $_SESSION['admin_id'];
    }
    if ($actor_username === null && isset($_SESSION['admin_username'])) {
        $actor_username = $_SESSION['admin_username'];
    }
    // Skutečná IP návštěvníka, ne adresa Cloudflare - viz bk_client_ip().
    $ip = bk_client_ip();
    $ua = bk_client_user_agent();
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (actor_user_id, actor_username, action, target_type, target_id, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$actor_user_id, $actor_username, $action, $target_type, $target_id, $description, $ip, $ua]);
    } catch (PDOException $e) {
        // Tabulka ještě neexistuje nebo DB chyba - audit log je best-effort, nesmí shodit hlavní akci
    }
}

/**
 * Rate limiting / lockout pro login.
 * Vrací počet zbývajících sekund lockoutu (0 = není zamčeno).
 * Limit: 5 neúspěšných pokusů za 15 minut → lockout 15 minut.
 */
function bk_login_lockout_seconds($pdo, $username) {
    $max_attempts = 5;
    $window_min = 15;
    $lockout_min = 15;
    // Bez skutečné IP by se zamykání opíralo o adresu Cloudflare, tedy
    // o hodnotu společnou všem návštěvníkům z téhož edge uzlu.
    $ip = bk_client_ip() ?? '';
    try {
        // Počet neúspěšných pokusů pro toto jméno NEBO tuto IP v posledním okně
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_log
            WHERE action = 'login_failed'
              AND (actor_username = ? OR ip_address = ?)
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$username, $ip, $window_min]);
        $attempts = (int)$stmt->fetchColumn();
        if ($attempts < $max_attempts) return 0;

        // Najít čas posledního neúspěšného pokusu → od něj běží lockout
        $stmt2 = $pdo->prepare("
            SELECT created_at FROM audit_log
            WHERE action = 'login_failed'
              AND (actor_username = ? OR ip_address = ?)
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt2->execute([$username, $ip]);
        $last_fail = $stmt2->fetchColumn();
        if (!$last_fail) return 0;

        $lockout_end = strtotime($last_fail) + ($lockout_min * 60);
        $remaining = $lockout_end - time();
        return max(0, $remaining);
    } catch (PDOException $e) {
        return 0; // best-effort: při DB chybě nezamykat
    }
}

/**
 * Samostatná stránka s posledními záznamy audit logu (kdo/kdy/co) - jen pro
 * admina, read-only (žádný CSRF token potřeba). Vlastní shell místo napojení
 * na hlavní admin.php šablonu, aby to nezáviselo na proměnných z hlavního
 * requestu (stejný přístup jako bk_render_setup_wizard()).
 */
function bk_render_audit_log_page($pdo, $site_title) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 100;
    $offset = ($page - 1) * $per_page;

    $total = (int)$pdo->query("SELECT COUNT(*) FROM audit_log")->fetchColumn();
    $stmt = $pdo->prepare("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $per_page, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $action_labels = [
        'login_success' => 'Přihlášení', 'login_failed' => 'Neúspěšné přihlášení', 'logout' => 'Odhlášení',
        'monitor_created' => 'Monitor vytvořen', 'monitor_updated' => 'Monitor upraven', 'monitor_deleted' => 'Monitor smazán',
        'monitor_notif_toggled' => 'Přepnuta notifikace monitoru', 'monitor_maintenance_toggled' => 'Přepnuta údržba',
        'monitor_history_cleared' => 'Vymazána historie monitoru',
        'asset_renamed' => 'Asset přejmenován', 'asset_deleted' => 'Asset smazán',
        'settings_updated' => 'Nastavení uloženo', 'profile_updated' => 'Profil upraven', 'password_changed' => 'Heslo změněno',
        'totp_enabled' => '2FA zapnuto', 'totp_disabled' => '2FA vypnuto', 'subscriptions_updated' => 'Odběry upraveny',
        'user_created' => 'Uživatel vytvořen', 'user_updated' => 'Uživatel upraven', 'user_deleted' => 'Uživatel smazán',
        'incident_created' => 'Incident vytvořen', 'incident_updated' => 'Incident upraven', 'incident_deleted' => 'Incident smazán',
        'remote_action_triggered' => 'Vzdálená akce zařazena', 'test_email_sent' => 'Testovací e-mail odeslán',
        'location_redetected' => 'Lokace znovu zjištěna', 'digest_sent' => 'Digest odeslán',
        'wizard_step_completed' => 'Krok wizardu dokončen', 'wizard_completed' => 'Wizard dokončen',
        'oauth_linked' => 'OAuth účet propojen', 'oauth_unlinked' => 'OAuth účet odpojen',
        'password_reset_requested' => 'Vyžádán reset hesla', 'password_set_via_link' => 'Heslo nastaveno přes odkaz',
    ];

    $rows_html = '';
    foreach ($rows as $r) {
        $label = $action_labels[$r['action']] ?? $r['action'];
        $color = str_contains($r['action'], 'failed') || str_contains($r['action'], 'deleted') ? '#ef233c'
            : (str_contains($r['action'], 'created') || str_contains($r['action'], 'success') || $r['action'] === 'wizard_completed' ? '#1ec773' : '#e1e1e6');
        $rows_html .= '<tr>'
            . '<td style="white-space:nowrap;">' . htmlspecialchars(date('d.m.Y H:i:s', strtotime($r['created_at']))) . '</td>'
            . '<td>' . htmlspecialchars($r['actor_username'] ?? '(neznámý)') . '</td>'
            . '<td style="color:' . $color . ';">' . htmlspecialchars($label) . '</td>'
            . '<td>' . htmlspecialchars($r['description'] ?? '') . '</td>'
            . '<td style="white-space:nowrap; color: var(--text-muted);">' . htmlspecialchars($r['ip_address'] ?? '') . '</td>'
            . '</tr>';
    }
    if (empty($rows)) {
        $rows_html = '<tr><td colspan="5" style="text-align:center; color: var(--text-muted); padding: 2rem;">Zatím žádné záznamy.</td></tr>';
    }

    $total_pages = max(1, (int)ceil($total / $per_page));
    $pagination = '';
    if ($total_pages > 1) {
        $pagination = '<div style="display:flex; gap:0.5rem; justify-content:center; margin-top:1rem;">';
        if ($page > 1) $pagination .= '<a href="admin.php?view=audit_log&page=' . ($page - 1) . '" class="btn btn-secondary btn-sm">&laquo; Novější</a>';
        $pagination .= '<span style="align-self:center; font-size:0.8rem; color: var(--text-muted);">Strana ' . $page . ' / ' . $total_pages . '</span>';
        if ($page < $total_pages) $pagination .= '<a href="admin.php?view=audit_log&page=' . ($page + 1) . '" class="btn btn-secondary btn-sm">Starší &raquo;</a>';
        $pagination .= '</div>';
    }

    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Audit log | ' . htmlspecialchars($site_title) . '</title>'
        . '<link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<link rel="stylesheet" href="' . BK_CDN_FONTAWESOME . '" integrity="' . BK_CDN_FONTAWESOME_SRI . '" crossorigin="anonymous"></head>'
        . '<body>'
        . '<header><div class="container header-wrapper"><a href="admin.php" class="logo"><i class="fas fa-server" style="color: var(--color-red);"></i> ' . htmlspecialchars($site_title) . ' <span>Admin</span></a>'
        . '<div class="nav-links"><a href="admin.php"><i class="fas fa-arrow-left"></i> Zpět do administrace</a></div></div></header>'
        . '<div class="container">'
        . '<div class="admin-card"><div class="admin-header"><h2><i class="fas fa-clipboard-list"></i> Audit log (' . $total . ' záznamů)</h2></div>'
        . '<p style="font-size:0.8rem; color: var(--text-muted); margin-bottom:1rem;">Kdo, kdy a co udělal v administraci - přihlášení, mazání, změny nastavení a uživatelů, odeslané e-maily.</p>'
        . '<div style="overflow-x:auto;"><table class="admin-table"><thead><tr><th>Kdy</th><th>Kdo</th><th>Akce</th><th>Detail</th><th>IP</th></tr></thead><tbody>' . $rows_html . '</tbody></table></div>'
        . $pagination
        . '</div></div></body></html>';
    exit;
}

/**
 * Vygeneruje token na nastavení hesla (pozvánka nového uživatele i zapomenuté
 * heslo sdílí tenhle mechanismus) - do DB se ukládá jen sha256 hash tokenu,
 * ne token samotný, aby ho případný únik DB dumpu nešel rovnou použít. Vrací
 * SUROVÝ token pro sestavení odkazu v e-mailu.
 */
function bk_issue_password_reset_token($pdo, $user_id, $ttl_seconds = 172800) {
    $raw_token = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $raw_token);
    $expires = date('Y-m-d H:i:s', time() + $ttl_seconds);
    $stmt = $pdo->prepare("UPDATE users SET password_reset_token_hash = ?, password_reset_expires = ? WHERE id = ?");
    $stmt->execute([$token_hash, $expires, $user_id]);
    return $raw_token;
}

/**
 * Absolutní URL na aktuální admin.php odvozená z requestu - stejný přístup
 * jako u OAuth redirect_uri. Na rozdíl od digest e-mailů (cron, žádný request)
 * tady vždycky běžíme uvnitř HTTP requestu, takže site_url setting není potřeba.
 */
function bk_current_admin_url() {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
}

/**
 * Vystaví token pro obnovu hesla a pošle na e-mail odkaz.
 *
 * Vzniklo vytažením z bk_render_forgot_password_page(), aby totéž mohlo dělat
 * i API pro React - tam se dosud volala akce `forgot_password`, která v api.php
 * vůbec nebyla. Uživatel dostal 200, aplikace napsala „návod byl odeslán" a
 * žádný e-mail nikdy nepřišel.
 *
 * Odkaz míří vždycky na admin.php, i když request běží nad api.php: stránku
 * pro nastavení hesla obsluhuje administrace.
 *
 * @return bool Jestli e-mail odpovídal existujícímu účtu. Volající to NESMÍ
 *              prozradit ven - jinak jde formulářem zjišťovat, kdo je
 *              zaregistrovaný.
 */
function bk_password_reset_request(PDO $pdo, string $email, string $site_title): bool {
    $email = trim($email);
    if ($email === '') {
        return false;
    }

    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $raw_token = bk_issue_password_reset_token($pdo, $user['id'], 7200);

    // Odkaz míří do React aplikace. Stará adresa admin.php?action=set_password
    // zůstává funkční kvůli e-mailům, které už odešly.
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $set_link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/app/set-password?token=' . $raw_token;

    $subject = 'Obnovení hesla - ' . $site_title;
    $body = '<h1>Obnovení hesla</h1>'
        . '<p>Někdo (doufejme vy) požádal o obnovení hesla k účtu <strong>' . htmlspecialchars($user['username']) . '</strong>. '
        . 'Klikněte na odkaz níže pro nastavení nového hesla (platnost 2 hodiny):</p>'
        . '<p><a href="' . htmlspecialchars($set_link) . '">' . htmlspecialchars($set_link) . '</a></p>'
        . '<p>Pokud jste o obnovení hesla nežádali, tento e-mail můžete ignorovat.</p>';

    send_email($email, $subject, $body);
    bk_audit_log($pdo, 'password_reset_requested', $email, 'user', $user['id'], $user['id'], $user['username']);
    return true;
}

/**
 * "Zapomenuté heslo" - formulář na zadání e-mailu + odeslání odkazu. Odpověď
 * je vždy stejná bez ohledu na to, jestli e-mail v systému existuje, jinak by
 * šel formulář zneužít k ověřování, které e-maily jsou zaregistrované.
 */
function bk_render_forgot_password_page($pdo, $site_title) {
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password_request'])) {
        bk_csrf_check();
        // Vlastní odeslání je v bk_password_reset_request(), aby totéž uměla
        // i API cesta pro React a text e-mailu existoval jen na jednom místě.
        bk_password_reset_request($pdo, trim($_POST['email'] ?? ''), $site_title);
        $sent = true;
    }

    $body_html = '<h2><i class="fas fa-unlock-alt" style="color: var(--color-red); margin-right: 0.5rem;"></i> Zapomenuté heslo</h2>';
    if ($sent) {
        $body_html .= '<div class="alert alert-success">Pokud e-mail existuje v systému, byl na něj odeslán odkaz pro nastavení nového hesla.</div>'
            . '<a href="admin.php" class="btn btn-secondary" style="width:100%;">Zpět na přihlášení</a>';
    } else {
        $body_html .= '<form action="admin.php?action=forgot_password" method="POST">' . bk_csrf_field()
            . '<div class="form-group"><label for="email">E-mail</label><input type="email" name="email" id="email" class="form-control" required autofocus></div>'
            . '<button type="submit" name="forgot_password_request" class="btn" style="width:100%; margin-top:1rem;"><i class="fas fa-paper-plane"></i> Odeslat odkaz</button>'
            . '</form><a href="admin.php" style="display:block; text-align:center; margin-top:1rem; font-size:0.85rem; color: var(--text-muted);">Zpět na přihlášení</a>';
    }

    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Zapomenuté heslo | ' . htmlspecialchars($site_title) . '</title>'
        . '<link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<link rel="stylesheet" href="' . BK_CDN_FONTAWESOME . '" integrity="' . BK_CDN_FONTAWESOME_SRI . '" crossorigin="anonymous"></head>'
        . '<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding: 2rem 0;">'
        . '<div class="login-wrapper" style="max-width: 380px;">' . $body_html . '</div>'
        . '</body></html>';
    exit;
}

/**
 * Nastavení hesla přes e-mailový odkaz - slouží pro pozvánku nového uživatele
 * (viz save_user v admin.php) i zapomenuté heslo (viz výše). Token dokazuje
 * vlastnictví e-mailu, ne totožnost jinak.
 */
function bk_render_set_password_page($pdo, $site_title) {
    $raw_token = trim($_GET['token'] ?? $_POST['token'] ?? '');
    $error = '';
    $done = false;
    $user = null;

    if (empty($raw_token)) {
        $error = 'Chybí token pro nastavení hesla.';
    } else {
        $token_hash = hash('sha256', $raw_token);
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE password_reset_token_hash = ? AND password_reset_expires > NOW() LIMIT 1");
        $stmt->execute([$token_hash]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Odkaz je neplatný nebo už vypršel. Požádejte prosím o nový.';
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
            bk_csrf_check();
            $new_password = $_POST['password'] ?? '';
            $confirm = $_POST['password_confirm'] ?? '';
            if (strlen($new_password) < 8) {
                $error = 'Heslo musí mít alespoň 8 znaků.';
            } elseif ($new_password !== $confirm) {
                $error = 'Hesla se neshodují.';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt_up = $pdo->prepare("UPDATE users SET password_hash = ?, password_reset_token_hash = NULL, password_reset_expires = NULL WHERE id = ?");
                $stmt_up->execute([$new_hash, $user['id']]);
                bk_audit_log($pdo, 'password_set_via_link', '', 'user', $user['id'], $user['id'], $user['username']);
                $done = true;
            }
        }
    }

    $body_html = '<h2><i class="fas fa-key" style="color: var(--color-red); margin-right: 0.5rem;"></i> Nastavení hesla</h2>';
    if ($done) {
        $body_html .= '<div class="alert alert-success">Heslo bylo úspěšně nastaveno.</div><a href="admin.php" class="btn" style="width:100%;">Přihlásit se</a>';
    } elseif (!empty($error) && !$user) {
        $body_html .= '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>'
            . '<a href="admin.php?action=forgot_password" class="btn btn-secondary" style="width:100%;">Požádat o nový odkaz</a>';
    } else {
        if (!empty($error)) {
            $body_html .= '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
        }
        $body_html .= '<form action="admin.php?action=set_password&token=' . htmlspecialchars($raw_token) . '" method="POST">' . bk_csrf_field()
            . '<div class="form-group"><label for="password">Nové heslo</label><input type="password" name="password" id="password" class="form-control" autocomplete="new-password" required autofocus></div>'
            . '<div class="form-group"><label for="password_confirm">Nové heslo znovu</label><input type="password" name="password_confirm" id="password_confirm" class="form-control" autocomplete="new-password" required></div>'
            . '<button type="submit" name="set_password" class="btn" style="width:100%; margin-top:1rem;"><i class="fas fa-check"></i> Nastavit heslo</button>'
            . '</form>';
    }

    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Nastavení hesla | ' . htmlspecialchars($site_title) . '</title>'
        . '<link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<link rel="stylesheet" href="' . BK_CDN_FONTAWESOME . '" integrity="' . BK_CDN_FONTAWESOME_SRI . '" crossorigin="anonymous"></head>'
        . '<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding: 2rem 0;">'
        . '<div class="login-wrapper" style="max-width: 380px;">' . $body_html . '</div>'
        . '</body></html>';
    exit;
}

/**
 * Vynucený setup wizard po čerstvé instalaci - 3 kroky (účet, cron_key,
 * základy webu), po dokončení nastaví jediný zdroj pravdy setup_completed
 * v settings. admin.php volá tuhle funkci a rovnou ukončuje request, dokud
 * flag není '1' - žádná jiná admin akce se dřív neprovede (viz volání níže).
 * Nahrazuje dřívější porovnávání natvrdo psaného hashe hesla v security banneru.
 */
function bk_render_setup_wizard($pdo, $me) {
    $step = (int)($_GET['step'] ?? 1);
    if ($step < 1 || $step > 3) $step = 1;
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        bk_csrf_check();

        if (isset($_POST['wizard_step1'])) {
            $new_username = trim($_POST['username'] ?? '');
            $new_email = trim($_POST['email'] ?? '');
            $new_password = $_POST['password'] ?? '';
            $confirm_password = $_POST['password_confirm'] ?? '';
            if (empty($new_username) || empty($new_email)) {
                $error = 'Uživatelské jméno a e-mail jsou povinné.';
            } elseif (strlen($new_password) < 8) {
                $error = 'Heslo musí mít alespoň 8 znaků.';
            } elseif ($new_password !== $confirm_password) {
                $error = 'Hesla se neshodují.';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$new_username, $new_email, $new_hash, $me['id']]);
                $_SESSION['admin_username'] = $new_username;
                bk_audit_log($pdo, 'wizard_step_completed', 'Krok 1 - účet', 'user', $me['id']);
                header('Location: admin.php?action=setup_wizard&step=2');
                exit;
            }
            $step = 1;
        } elseif (isset($_POST['wizard_step2'])) {
            $cron_key = trim($_POST['cron_key'] ?? '');
            if (empty($cron_key)) {
                $error = 'Cron key nesmí být prázdný.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('cron_key', ?) ON DUPLICATE KEY UPDATE key_value = ?");
                $stmt->execute([$cron_key, $cron_key]);
                bk_audit_log($pdo, 'wizard_step_completed', 'Krok 2 - cron_key', 'user', $me['id']);
                header('Location: admin.php?action=setup_wizard&step=3');
                exit;
            }
            $step = 2;
        } elseif (isset($_POST['wizard_step3'])) {
            $new_site_title = trim($_POST['site_title'] ?? '') ?: 'Blood Kings Status';
            $new_site_url = trim($_POST['site_url'] ?? '');
            foreach (['site_title' => $new_site_title, 'site_url' => $new_site_url, 'setup_completed' => '1'] as $k => $v) {
                $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
                $stmt->execute([$k, $v, $v]);
            }
            bk_audit_log($pdo, 'wizard_completed', $new_site_title, 'user', $me['id']);
            header('Location: admin.php');
            exit;
        }
    }

    $steps_labels = ['1' => 'Účet', '2' => 'Cron key', '3' => 'Základy webu'];
    $site_title_current = get_setting('site_title', 'Blood Kings');

    $body = '<h2><i class="fas fa-flag-checkered" style="color: var(--color-red); margin-right: 0.5rem;"></i> Dokončení instalace</h2>'
        . '<p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.25rem;">Než budete moct appku běžně používat, projděte prosím tyhle kroky - vyřeší se tím výchozí přístupové údaje z čerstvé instalace.</p>';

    $body .= '<div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem;">';
    foreach ($steps_labels as $n => $label) {
        $active = ((int)$n === $step);
        $done = ((int)$n < $step);
        $color = $active ? 'var(--color-red)' : ($done ? 'var(--color-green)' : 'var(--text-muted)');
        $body .= '<div style="flex:1; text-align:center; font-size:0.75rem; color:' . $color . '; border-bottom: 2px solid ' . $color . '; padding-bottom: 0.4rem;">' . ($done ? '<i class="fas fa-check"></i> ' : htmlspecialchars($n) . '. ') . htmlspecialchars($label) . '</div>';
    }
    $body .= '</div>';

    if (!empty($error)) {
        $body .= '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
    }

    if ($step === 1) {
        $body .= '<form action="admin.php?action=setup_wizard" method="POST">' . bk_csrf_field()
            . '<div class="form-group"><label for="username">Uživatelské jméno</label><input type="text" name="username" id="username" class="form-control" value="' . htmlspecialchars($me['username']) . '" required></div>'
            . '<div class="form-group"><label for="email">E-mail</label><input type="email" name="email" id="email" class="form-control" value="' . htmlspecialchars($me['email'] ?? '') . '" required></div>'
            . '<div class="form-group"><label for="password">Nové heslo</label><input type="password" name="password" id="password" class="form-control" autocomplete="new-password" required></div>'
            . '<div class="form-group"><label for="password_confirm">Nové heslo znovu</label><input type="password" name="password_confirm" id="password_confirm" class="form-control" autocomplete="new-password" required></div>'
            . '<button type="submit" name="wizard_step1" class="btn" style="width:100%; margin-top:1rem;"><i class="fas fa-arrow-right"></i> Pokračovat</button>'
            . '</form>';
    } elseif ($step === 2) {
        $suggested_key = bin2hex(random_bytes(16));
        $body .= '<p style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.75rem;">Chrání HTTP spouštění cron.php a Distributed Node API (node_api.php). Předvyplněná náhodná hodnota je bezpečná, klidně ji nechte tak.</p>'
            . '<form action="admin.php?action=setup_wizard" method="POST">' . bk_csrf_field()
            . '<div class="form-group"><label for="cron_key">Cron key</label><input type="text" name="cron_key" id="cron_key" class="form-control" value="' . htmlspecialchars($suggested_key) . '" required></div>'
            . '<button type="submit" name="wizard_step2" class="btn" style="width:100%; margin-top:1rem;"><i class="fas fa-arrow-right"></i> Pokračovat</button>'
            . '</form>';
    } else {
        $body .= '<form action="admin.php?action=setup_wizard" method="POST">' . bk_csrf_field()
            . '<div class="form-group"><label for="site_title">Název webu</label><input type="text" name="site_title" id="site_title" class="form-control" value="' . htmlspecialchars($site_title_current) . '" required></div>'
            . '<div class="form-group"><label for="site_url">URL webu (pro odkazy v digestu)</label><input type="url" name="site_url" id="site_url" class="form-control" value="' . htmlspecialchars(get_setting('site_url', '')) . '" placeholder="https://status.vasedomena.cz"></div>'
            . '<button type="submit" name="wizard_step3" class="btn" style="width:100%; margin-top:1rem;"><i class="fas fa-check"></i> Dokončit instalaci</button>'
            . '</form>';
    }

    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>Dokončení instalace | ' . htmlspecialchars($site_title_current) . '</title>'
        . '<link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<link rel="stylesheet" href="' . BK_CDN_FONTAWESOME . '" integrity="' . BK_CDN_FONTAWESOME_SRI . '" crossorigin="anonymous"></head>'
        . '<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding: 2rem 0;">'
        . '<div class="login-wrapper" style="max-width: 420px;">' . $body . '</div>'
        . '</body></html>';
    exit;
}

/**
 * Odeslání Push notifikace přes Pushover API
 */
function send_pushover_alert($user_key, $api_token, $title, $message, $priority = 0) {
    if (empty($user_key) || empty($api_token)) return false;
    $url = "https://api.pushover.net/1/messages.json";
    $payload = [
        'token' => $api_token,
        'user' => $user_key,
        'title' => $title,
        'message' => $message,
        'priority' => $priority
    ];
    return send_webhook_post($url, json_encode($payload));
}

/**
 * Odeslání události přes PagerDuty Events v2 API
 */
function send_pagerduty_event($routing_key, $event_type, $summary, $source = 'Blood Kings Monitoring') {
    if (empty($routing_key)) return false;
    $url = "https://events.pagerduty.com/v2/enqueue";
    $payload = [
        'routing_key' => $routing_key,
        'event_action' => $event_type,
        'payload' => [
            'summary' => $summary,
            'severity' => $event_type === 'trigger' ? 'error' : 'info',
            'source' => $source
        ]
    ];
    return send_webhook_post($url, json_encode($payload));
}

/**
 * Převede počet bajtů na lidsky čitelný formát (např. 188.22 GB, 3.62 TB).
 */
function bk_format_bytes_cz($bytes) {
    $bytes = (float)$bytes;
    if ($bytes >= 1099511627776) return round($bytes / 1099511627776, 2) . ' TB';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 0) . ' KB';
    return number_format($bytes, 0, ',', ' ') . ' B';
}

/**
 * Převede počet paketů na lidsky čitelný formát (např. 707M Pkts, 2.57B Pkts).
 */
function bk_format_packets_cz($cnt) {
    $cnt = (float)$cnt;
    if ($cnt >= 1000000000) return round($cnt / 1000000000, 2) . ' B Pkts.';
    if ($cnt >= 1000000) return round($cnt / 1000000, 2) . ' M Pkts.';
    if ($cnt >= 1000) return round($cnt / 1000, 1) . ' k Pkts.';
    return number_format($cnt, 0, ',', ' ') . ' Pkts.';
}

/**
 * Získá kumulativní přenesená data (RX/TX bajty a pakety) pro všechna rozhraní daného monitoru pro různá období.
 */
function bk_get_interface_traffic_stats($pdo, $monitor_id) {
    $result = [];
    try {
        $stmt = $pdo->prepare("
            SELECT iface,
                   SUM(CASE WHEN date = CURDATE() THEN rx_bytes_total ELSE 0 END) as rx_today,
                   SUM(CASE WHEN date = CURDATE() THEN tx_bytes_total ELSE 0 END) as tx_today,
                   SUM(CASE WHEN date = CURDATE() THEN rx_packets_total ELSE 0 END) as rx_pkts_today,
                   SUM(CASE WHEN date = CURDATE() THEN tx_packets_total ELSE 0 END) as tx_pkts_today,

                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN rx_bytes_total ELSE 0 END) as rx_7d,
                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN tx_bytes_total ELSE 0 END) as tx_7d,
                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN rx_packets_total ELSE 0 END) as rx_pkts_7d,
                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN tx_packets_total ELSE 0 END) as tx_pkts_7d,

                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN rx_bytes_total ELSE 0 END) as rx_30d,
                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN tx_bytes_total ELSE 0 END) as tx_30d,
                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN rx_packets_total ELSE 0 END) as rx_pkts_30d,
                   SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN tx_packets_total ELSE 0 END) as tx_pkts_30d,

                   SUM(rx_bytes_total) as rx_total,
                   SUM(tx_bytes_total) as tx_total,
                   SUM(rx_packets_total) as rx_pkts_total,
                   SUM(tx_packets_total) as tx_pkts_total
            FROM monitor_interface_traffic
            WHERE monitor_id = ?
            GROUP BY iface
            ORDER BY iface ASC
        ");
        $stmt->execute([$monitor_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $result[$r['iface']] = [
                'today' => [
                    'rx_bytes' => (float)$r['rx_today'],
                    'tx_bytes' => (float)$r['tx_today'],
                    'rx_pkts' => (int)$r['rx_pkts_today'],
                    'tx_pkts' => (int)$r['tx_pkts_today'],
                ],
                '7d' => [
                    'rx_bytes' => (float)$r['rx_7d'],
                    'tx_bytes' => (float)$r['tx_7d'],
                    'rx_pkts' => (int)$r['rx_pkts_7d'],
                    'tx_pkts' => (int)$r['tx_pkts_7d'],
                ],
                '30d' => [
                    'rx_bytes' => (float)$r['rx_30d'],
                    'tx_bytes' => (float)$r['tx_30d'],
                    'rx_pkts' => (int)$r['rx_pkts_30d'],
                    'tx_pkts' => (int)$r['tx_pkts_30d'],
                ],
                'all' => [
                    'rx_bytes' => (float)$r['rx_total'],
                    'tx_bytes' => (float)$r['tx_total'],
                    'rx_pkts' => (int)$r['rx_pkts_total'],
                    'tx_pkts' => (int)$r['tx_pkts_total'],
                ],
            ];
        }
    } catch (PDOException $e) { /* best-effort */ }
    return $result;
}

/**
 * ASN lookup přes Team Cymru DNS (origin.asn.cymru.com) - žádná externí
 * HTTP API: jeden TXT dotaz z hostingu, který ven nese jen IP, na kterou
 * se ptáme (a tu už server stejně zná z REMOTE_ADDR agenta). Privátní
 * rozsahy se nedotazují vůbec.
 * Vrací [asn ("AS12345"|null), jméno sítě (string|null)].
 */
function bk_lookup_asn(string $ip): array {
    try {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [null, null];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $q = implode('.', array_reverse(explode('.', $ip))) . '.origin.asn.cymru.com';
        } else {
            $hex = bin2hex(inet_pton($ip));
            $q = implode('.', array_reverse(str_split($hex))) . '.origin6.asn.cymru.com';
        }
        $rec = @dns_get_record($q, DNS_TXT);
        if (empty($rec[0]['txt'])) {
            return [null, null];
        }
        // "23033 | 85.195.64.0/18 | CZ | ripencc | 2004-04-05"
        $parts = array_map('trim', explode('|', $rec[0]['txt']));
        $asn_num = preg_replace('/[^0-9].*$/', '', $parts[0] ?? '');
        if ($asn_num === '') {
            return [null, null];
        }
        $asn_name = null;
        $rec2 = @dns_get_record("AS{$asn_num}.asn.cymru.com", DNS_TXT);
        if (!empty($rec2[0]['txt'])) {
            // "23033 | CZ | ripencc | 2004-04-05 | WEDOS, CZ"
            $p2 = array_map('trim', explode('|', $rec2[0]['txt']));
            $asn_name = $p2[count($p2) - 1] ?? null;
        }
        return ['AS' . $asn_num, $asn_name];
    } catch (Throwable $e) {
        return [null, null];
    }
}

/**
 * Zápis výsledku agent-side kontroly do monitoru typu 'agent_service'.
 *
 * Služby na privátní síti (kresd na routeru, MQTT broker na LAN) hosting
 * nikdy nedosáhne - kontrolu proto dělá agent přímo na stroji a server tu
 * jen poctivě zaznamená výsledek: přechod stavu, log BEZ response_time
 * (agent neměří latenci, nula by byla vymyšlená) a notifikaci při změně.
 */
function bk_apply_agent_service_result($pdo, array $svc_monitor, bool $running, string $detail): void {
    $new_status = $running ? 'up' : 'down';
    $old_status = $svc_monitor['status'] ?? 'unknown';
    $error_msg = $running ? null : ($detail !== '' ? $detail : 'Agent hlásí, že služba neběží.');

    if ($old_status !== $new_status) {
        $stmt = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW(), last_status_change = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $svc_monitor['id']]);
        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, ?, NULL, ?, 'Agent')");
        $stmt_log->execute([$svc_monitor['id'], $new_status, $error_msg]);
        // Notifikace jen pro reálné up/down přechody; přechod z 'unknown'
        // na 'up' při prvním výsledku nikoho budit nemusí.
        if (in_array($old_status, ['up', 'down'], true) || $new_status === 'down') {
            trigger_notifications($pdo, $svc_monitor, $new_status, (string)$error_msg);
        }
    } else {
        $stmt = $pdo->prepare("UPDATE monitors SET status = ?, last_checked = NOW() WHERE id = ?");
        $stmt->execute([$new_status, $svc_monitor['id']]);
        $stmt_log = $pdo->prepare("INSERT INTO monitor_logs (monitor_id, status, response_time, error_message, checked_from) VALUES (?, ?, NULL, ?, 'Agent')");
        $stmt_log->execute([$svc_monitor['id'], $new_status, $error_msg]);
    }
}

/**
 * Validace cíle monitoru importovaného ze Service Discovery.
 *
 * Cíl pochází od AGENTA (discovery payload i hostname fallback) - tedy od
 * nižší úrovně důvěry než admin, který import jen odklikává. Kontroly navíc
 * běží z webhostingu: loopback, privátní a link-local rozsahy odsud nikdy
 * nejsou dosažitelné, takže by kontrola jen věčně selhávala a mířila do
 * vnitřní sítě poskytovatele hostingu. Ruční tvorba monitoru přes admin
 * (save_monitor) zůstává bez tohoto omezení - admin je plně důvěryhodný.
 *
 * Vrací text chyby pro uživatele, nebo null, když je cíl v pořádku.
 */
function bk_validate_import_target(string $target): ?string {
    $host = trim($target);
    // Případná URL (web služby) - zajímá nás jen host.
    if (preg_match('#^[a-z][a-z0-9+.-]*://([^/]+)#i', $host, $m)) {
        $host = $m[1];
    }
    // Oddělení portu: [ipv6]:port, host:port. Holé IPv6 (víc dvojteček) se nechává být.
    if ($host !== '' && $host[0] === '[') {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $host, $m)) {
            $host = $m[1];
        }
    } elseif (substr_count($host, ':') === 1) {
        $host = preg_replace('/:\d+$/', '', $host);
    }

    if ($host === '') {
        return 'Služba nehlásí použitelnou cílovou adresu. Vytvořte monitor ručně a adresu doplňte.';
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return "Cíl '{$host}' je v privátním nebo rezervovaném rozsahu - z hostingu není dosažitelný a kontrola by sondovala cizí vnitřní síť. Zadejte veřejnou adresu ručně.";
        }
        return null;
    }
    $lower = strtolower($host);
    foreach (['.local', '.localhost', '.internal', '.lan', '.home.arpa'] as $suffix) {
        if (str_ends_with($lower, $suffix)) {
            return "Cíl '{$host}' je interní jméno - z hostingu není dosažitelné. Zadejte veřejnou adresu ručně.";
        }
    }
    if ($lower === 'localhost') {
        return "Cíl 'localhost' by kontroloval hostingový server, ne službu agenta. Zadejte veřejnou adresu ručně.";
    }
    if (!preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host)) {
        return "Cíl '{$host}' není platný hostname ani IP adresa.";
    }
    return null;
}

/**
 * Propojí a sloučí detaily z agenta (ts3_process, discovered_services, top_cpu_processes, interfaces atd.)
 * pro libovolný monitor (např. TeamSpeak, Minecraft, Web), i když uživatel ručně nenastavil asset_id.
 */
/**
 * Detekce výpadků SBĚRU dat pro jeden monitor - ne výpadků služby samotné.
 * Zásada (2026-08-05, po dvou týdnech neviditelně mrtvého cpanel sběru):
 * když se data přestanou sbírat, frontend musí řvát, ne tiše nic neukazovat.
 * Vrací pole položek {type, message, since}; prázdné pole = sběr zdravý.
 * Sdílené mezi api.php (React SPA) a index.php (veřejná status stránka).
 */
function bk_get_collection_issues(array $monitor_row, array $details, int $agent_offline_timeout_secs = 3000): array {
    $issues = [];
    $status = strtolower($monitor_row['status'] ?? '');

    // 1. Selhávající sběr cPanel statistik (zapisuje cron.php při každém
    //    neúspěšném check_cpanel; klíč nese i důvod a začátek výpadku).
    if (!empty($details['cpanel_stats_error']) && is_array($details['cpanel_stats_error'])) {
        $issues[] = [
            'type' => 'cpanel_stats',
            'message' => (string)($details['cpanel_stats_error']['error'] ?? t('collection_issue_cpanel_generic')),
            'hint' => $details['cpanel_stats_error']['hint'] ?? null,
            'since' => $details['cpanel_stats_error']['since'] ?? null,
        ];
    }

    // 2. Agent přestal hlásit (agent_last_seen starší než offline timeout).
    //    Jen pro monitory, které někdy agenta měly - jinak by řval každý web.
    $agent_last_seen = (int)($details['agent_last_seen'] ?? 0);
    if ($agent_last_seen > 0 && (time() - $agent_last_seen) > $agent_offline_timeout_secs) {
        $issues[] = [
            'type' => 'agent_silent',
            'message' => sprintf(t('collection_issue_agent_silent'), round((time() - $agent_last_seen) / 60)),
            'since' => date('c', $agent_last_seen),
        ];
    }

    // 3. Kontroly samotné neběží (mrtvý cron pro tento monitor). Pauza a
    //    údržba jsou legitimní stavy bez kontrol - ty se nehlásí.
    if (!in_array($status, ['paused', 'maintenance'], true) && !empty($monitor_row['last_checked'])) {
        $last_checked_ts = strtotime($monitor_row['last_checked']);
        if ($last_checked_ts && (time() - $last_checked_ts) > 15 * 60) {
            $issues[] = [
                'type' => 'checks_stalled',
                'message' => sprintf(t('collection_issue_checks_stalled'), round((time() - $last_checked_ts) / 60)),
                'since' => date('c', $last_checked_ts),
            ];
        }
    }

    return $issues;
}

function bk_enrich_monitor_details($pdo, $monitor, &$details) {
    if (!is_array($details)) $details = [];
    if (!$pdo || empty($monitor) || !is_array($monitor)) return;

    $mid = (int)($monitor['id'] ?? 0);
    $asset_id = $monitor['asset_id'] ?? null;
    $target = trim($monitor['target'] ?? '');
    $sib_details_raw = null;

    // 1. Zkusit párování přes asset_id (pokud je nastaven)
    if (!empty($asset_id)) {
        try {
            $stmt = $pdo->prepare("SELECT last_details FROM monitors WHERE asset_id = ? AND id != ? AND last_details IS NOT NULL AND agent_key IS NOT NULL AND agent_key != '' LIMIT 1");
            $stmt->execute([$asset_id, $mid]);
            $sib_details_raw = $stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    // 2. Zkusit párování přes Target IP / Hostname
    if (!$sib_details_raw && !empty($target)) {
        try {
            $host_or_ip = parse_url($target, PHP_URL_HOST) ?: $target;

            // DNS dotaz jen tehdy, když cíl vůbec vypadá jako adresa.
            //
            // U agentových monitorů bývá v `target` lidský popisek - třeba
            // "Turris - domov". gethostbyname() se ho přesto pokusí přeložit
            // a čeká na timeout resolveru: na produkci to dělalo šest sekund
            // při každém vykreslení, a to synchronně uprostřed stránky.
            // Detail toho monitoru se tím protáhl na osm sekund, zatímco
            // ostatní se vykreslovaly do jedné.
            //
            // gethostbyname() navíc při neúspěchu vrací vstup beze změny,
            // takže se tímhle o nic nepřichází - jen se nečeká.
            $looks_like_address = filter_var($host_or_ip, FILTER_VALIDATE_IP) !== false
                || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host_or_ip) === 1;
            $resolved_ip = $looks_like_address ? gethostbyname($host_or_ip) : $host_or_ip;
            
            $stmt = $pdo->prepare("
                SELECT last_details FROM monitors 
                WHERE id != ? AND agent_key IS NOT NULL AND agent_key != '' AND last_details IS NOT NULL
                  AND (target = ? OR target = ? OR last_details LIKE ?)
                ORDER BY updated_at DESC LIMIT 1
            ");
            $stmt->execute([$mid, $target, $resolved_ip, '%' . $resolved_ip . '%']);
            $sib_details_raw = $stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    // 3. Pro TeamSpeak: Pokud stále nemáme ts3_process, najít LIBOVOLNÉHO agenta, který hlásí aktivní ts3_process
    if (!$sib_details_raw && ($monitor['type'] ?? '') === 'teamspeak') {
        try {
            $stmt = $pdo->prepare("
                SELECT last_details FROM monitors 
                WHERE agent_key IS NOT NULL AND agent_key != '' 
                  AND last_details LIKE '%\"ts3_process\"%' 
                  AND last_details NOT LIKE '%\"ts3_process\":null%' 
                ORDER BY updated_at DESC LIMIT 1
            ");
            $stmt->execute();
            $sib_details_raw = $stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    // 4. Pokud jsme našli detaily agenta, doplníme chybějící položky do $details
    if ($sib_details_raw) {
        $sib_det = json_decode($sib_details_raw, true);
        if (is_array($sib_det)) {
            $fields = [
                'ts3_process', 'discovered_services', 'top_cpu_processes', 'top_ram_processes',
                'interfaces', 'wifi_radios', 'dns_engine', 'dns_encryption', 'dns_servers',
                'heavy_op_interval_hours', 'conntrack_pct', 'swap_pct', 'entropy',
                'upgradable_packages', 'installed_packages', 'log_errors_24h', 'log_warnings_24h'
            ];
            foreach ($fields as $field) {
                if (empty($details[$field]) && !empty($sib_det[$field])) {
                    $details[$field] = $sib_det[$field];
                }
            }
            if (!isset($details['cpu']) && isset($sib_det['cpu'])) $details['cpu'] = $sib_det['cpu'];
            if (!isset($details['ram']) && isset($sib_det['ram'])) $details['ram'] = $sib_det['ram'];
            if (!isset($details['ram_total_mb']) && isset($sib_det['ram_total_mb'])) $details['ram_total_mb'] = $sib_det['ram_total_mb'];
            if (!isset($details['ram_used_mb']) && isset($sib_det['ram_used_mb'])) $details['ram_used_mb'] = $sib_det['ram_used_mb'];
            if (!isset($details['ram_available_mb']) && isset($sib_det['ram_available_mb'])) $details['ram_available_mb'] = $sib_det['ram_available_mb'];
        }
    }
}


