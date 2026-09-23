<?php
/**
 * Monitoring functions and notification delivery
 */

if (ini_get('session.gc_divisor') === '0' || ini_get('session.gc_divisor') === false) {
    @ini_set('session.gc_divisor', 100);
}

require_once __DIR__ . '/db.php';

// CDN versions of frontend libraries - the ONLY place edited on upgrade.
// apps/status/package.json mirrors the same numbers just for Dependabot (there
// is no build step here, but Dependabot needs a manifest to even know the
// version exists and can be tracked) - you will find them there too, but they
// are not linked automatically, an upgrade must touch both places by hand.
// ECharts is the only charting library in the whole app (index.php and the
// Level 3 detail page) - Chart.js was removed so two libraries would not be
// kept for the same job. A 6.x branch exists, but it is a major version with
// possible breaking changes in both usage sites - deliberately kept at 5.5.1
// until someone verifies it in a browser (no way to do that from here).
define('BK_CDN_FONTAWESOME', 'https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7.3.1/css/all.min.css');
define('BK_CDN_FONTAWESOME_SRI', 'sha384-qrALq7+6jBOZIQsNnT6xGkMDru64qD6uTlDra39xrt2SoXl4pO3FX6Roz/RpR/BS');
define('BK_CDN_ECHARTS', 'https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js');
define('BK_CDN_ECHARTS_SRI', 'sha384-Mx5lkUEQPM1pOJCwFtUICyX45KNojXbkWdYhkKUKsbv391mavbfoAmONbzkgYPzR');
define('BK_CDN_QRCODE', 'https://cdn.jsdelivr.net/npm/qrcode@1.5.4/lib/browser.min.js');
define('BK_CDN_QRCODE_SRI', 'sha384-dykayVHnol2xD+KCZ38PbDk0WZnbP5x/sO6gOXKU3h+bodE3ILyIk1FOEfwO1hya');

/**
 * Configuration of the supported OAuth providers - one place for all 4,
 * instead of a separate copy of GitHub-specific logic for each. The scope is
 * always just "read my stable account ID", nothing more (no e-mail) - both
 * sign-in and account linking run exclusively through users.oauth_provider/oauth_id
 * (set only by explicit linking in one's own Profile, never automatically
 * by e-mail - see the security note at the OAuth callback in admin.php on
 * why e-mail as an identifier was a problem).
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
 * Performs the OAuth token exchange (code -> access_token) + reads the user's
 * stable ID at the given provider. Returns ['ok' => bool, 'id' => string|null,
 * 'error' => string|null] - never throws, the caller just checks 'ok'.
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
 * Returns the HTML icon for a monitor type (+ the target for 'web', for the favicon).
 * Shared between index.php (public dashboard) and admin.php (monitor list),
 * so both places always show the same icon for the same type.
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
 * Maps agent_type -> agent file on the server. The single place both
 * consumers (the update check in agent_api.php and the version display on
 * the dashboard) share, so no hardcoded list repeats anywhere.
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
 * Reads AGENT_VERSION straight from the live agent file on the server (the
 * single source of truth - the very value the agent actually contains), by the
 * type the agent itself reported. Returns null when the type is unknown or the
 * file unreadable (e.g. old data without a stored agent_type) - the caller then
 * skips version comparison instead of comparing against a foreign/invalid number.
 */
/**
 * Is version $have older than $latest? Compared by numeric components,
 * not as strings - "1.10.0" < "1.9.0" would otherwise come out newer.
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
 * Formats uptime seconds with Czech grammar
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
 * Renders the grid and details from the VPS agent (CPU, RAM, Disk, Uptime, SMART, Ports)
 */
function render_vps_agent_details($details, $monitor = null) {
    if (!isset($details['cpu'])) return '';
    
    $cpu = floatval($details['cpu']);
    $ram = floatval($details['ram']);
    $hdd = floatval($details['hdd']);
    
    $cpu_color = ($cpu > 80) ? 'red' : (($cpu > 50) ? 'yellow' : 'green');
    $ram_color = ($ram > 85) ? 'red' : (($ram > 60) ? 'yellow' : 'green');
    $hdd_color = ($hdd > 90) ? 'red' : (($hdd > 70) ? 'yellow' : 'green');
    
    // Admin-only bits (agent update offers, setup hints) need the admin role.
    // What runs on the machine - processes, ports, discovered services - needs
    // access to this monitor. The legacy page used to show both to any visitor.
    $is_admin = function_exists('bk_viewer') && bk_viewer()['is_admin'];
    $can_view = $monitor !== null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO
        && bk_can_view_monitor($GLOBALS['pdo'], (int)($monitor['id'] ?? 0));
    
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
            // used always travels with total; without it "0 MB / X MB" would be invented.
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
                // The right "latest" number depends on WHICH agent reports
                // (the VPS Python/Bash/PowerShell and OpenWrt agents have their
                // own independent versioning) - see bk_get_agent_latest_version().
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
                $smart_missing = bk_smart_is_missing($smart_val);
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
            if ($monitor && $can_view):
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
            
            <?php if ($can_view && $monitor && $monitor['type'] === 'vps' && !empty($details['ports'])): 
                // Ports may arrive as an array or a comma-separated string
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

            <?php if ($can_view && !empty($details['discovered_services']) && is_array($details['discovered_services'])): ?>
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

            <?php if ($can_view && (!empty($details['top_cpu_processes']) || !empty($details['top_ram_processes']))): ?>
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
 * Knowledge layer - returns tips explaining what a currently exceeded
 * threshold on a metric means. Invents no new thresholds - every rule
 * mirrors a threshold that already drives red/yellow elsewhere in the code
 * (render_vps_agent_details() above, the SSL card and check pipeline in index.php,
 * the status fields from build_teamspeak_health_areas()). Tips inherit visibility
 * from the metric they explain (see $enabled_metrics) - they cannot be disabled
 * separately, because a tip without its metric would make no sense.
 *
 * @return array<int, array{icon: string, severity: string, text: string}>
 */
/**
 * Determines how long (in minutes) a metric has been above a threshold.
 * Returns null when there is not enough data or the metric is currently below it.
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

    // The current value must be above the threshold
    $latest = (float)$rows[0]['val'];
    if ($latest <= $threshold) return null;

    // Walk back from the newest sample and find the first one below the threshold
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
 * Formats a duration in minutes into a readable string (CZ/EN).
 */
/**
 * Renders a measured value: the number, or a dash when unmeasured.
 *
 * Exists so nobody has to write `$x ?? 0` - zero is a valid measurement
 * ("the disk is 0 % full"), so it must not stand in for a missing value.
 *
 * @param mixed  $value    value from details/JSON (may be null or absent)
 * @param string $unit     unit appended after the number (" %", " MB", " ms"...)
 * @param int    $decimals number of decimal places
 */
/**
 * Does the interface have a non-zero error-packet count?
 *
 * An unknown error count is NOT zero errors - returns false (no red
 * colouring), but without pretending everything was measured fine.
 */
/**
 * Monitor availability over the last N days from real checks.
 *
 * Returns null when the window has no measurement at all - the widget/badge
 * then shows a dash instead of an invented 100 %. Exists because widget.php
 * and badge.php both called calculate_uptime(), which never existed in the
 * code, and both pages died with a fatal error.
 */
/**
 * Recomputes the daily availability rollups from monitor_logs.
 *
 * Called from cron RIGHT BEFORE old logs are pruned - otherwise data about
 * to disappear would never reach the rollup.
 *
 * Overwrites whole days (INSERT ... ON DUPLICATE KEY UPDATE), so repeated
 * runs duplicate nothing and today keeps refining during the day.
 *
 * Only real measurements enter the denominator - 'maintenance' and 'unknown'
 * do not count, because a planned outage is not a failure and an unmeasured
 * state is not a measurement.
 *
 * @param int $days How many recent days to recompute (default 2 = today
 *                  and yesterday, enough when running every minute).
 * @return int Number of days written/updated.
 */
/**
 * A numeric metric from the agent's report, or NULL.
 *
 * The whole project's core rule in one place: a missing, empty or
 * non-numeric value is NULL, never zero. Zero means "measured zero" -
 * for dropped packets or temperature that is a completely different message than
 * „agent tuhle hodnotu neposlal".
 *
 * Shell agents send unmeasured values as JSON null, but older versions
 * sent the string "null" - it therefore counts as missing too.
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

/** Integer variant of bk_agent_num() - for counters and counts. */
function bk_agent_int(array $data, string $key): ?int {
    $num = bk_agent_num($data, $key);
    return $num === null ? null : (int)round($num);
}

/**
 * String variant: a trimmed, length-capped string or NULL. A list or an
 * object where a string was expected is NULL too - trim() on an array is a
 * TypeError, and one bad key from an agent used to turn the whole report
 * into a 500, every minute, until someone read the server log.
 */
/** Boolean variant: JSON true/false, 0/1 or "true"/"false"; anything else is NULL. */
function bk_agent_bool(array $data, string $key): ?bool {
    if (!array_key_exists($key, $data)) {
        return null;
    }
    $value = $data[$key];
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
        return (bool)(int)$value;
    }
    if (is_string($value)) {
        $lower = strtolower(trim($value));
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
    }
    return null;
}

/**
 * Whether a SMART string from an agent means "not measured" rather than a
 * verdict. The agents phrase it several ways - "N/A", "N/A (smartctl chybí)",
 * "N/A (SMART nedostupné pro /dev/sda)", "N/A (Storage modul neni k
 * dispozici)" - and the legacy pages matched two of them by hand and painted
 * the rest green: a missing measurement shown as a healthy disk.
 */
function bk_smart_is_missing($value): bool {
    $v = trim((string)$value);
    if ($v === '') {
        return true;
    }
    if (stripos($v, 'N/A') === 0) {
        return true;
    }
    return (bool)preg_match('~chyb|missing|nedostupn|unavail~iu', $v);
}

function bk_agent_str(array $data, string $key, int $max = 255): ?string {
    if (!array_key_exists($key, $data)) {
        return null;
    }
    $value = $data[$key];
    if (is_array($value) || is_bool($value) || $value === null) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '' || $value === 'null') {
        return null;
    }
    return mb_substr($value, 0, $max);
}

/**
 * Daily aggregation of agent metrics into `metrics_daily`.
 *
 * Raw `vps_metrics` is pruned after 30 days. Availability outlived that
 * boundary thanks to `uptime_daily`, metrics did not - so "how did disk
 * usage grow over half a year" had no answer and the "disk full in X days"
 * estimate was forever computed from at most thirty days.
 *
 * Stored are min/average/max and the sample count. The average alone would
 * hide the spikes capacity planning asks about most; max without a sample
 * count could not be told apart from a single blip.
 *
 * Days are recomputed (ON DUPLICATE KEY UPDATE) so late-arriving data
 * corrects today.
 *
 * @param int $days How many recent days to recompute (default 2 = today and yesterday).
 * @return int Number of rows written/updated.
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

/**
 * Retention for the router health tables (schema 20260920).
 *
 * A function and not three inline DELETEs in cron.php, for the same reason
 * `bk_prune_process_samples` is one: no suite ever executes `cron.php`, so
 * inline SQL could only be checked by reading the file as text. Here the API
 * suite can call it with boundary rows and see what really disappears.
 *
 * Two years of disk history, because the rules that read it (wear over a
 * year, a disk replaced in the same slot) compare across seasons.
 * Recommendation state is kept for 90 days after the item last fired - long
 * enough that a rule which returns every few weeks is still "unchanged since
 * last week" rather than new. A MUTED row is never pruned: the mute is the
 * owner's decision and it goes only with the monitor (FK cascade).
 *
 * Returns the deleted row counts so cron.php can report what it did instead
 * of claiming success.
 */
function bk_prune_router_health(PDO $pdo): array {
    $result = ['disk_daily' => 0, 'disks' => 0, 'rec_state' => 0];

    $stmt = $pdo->prepare("DELETE FROM storage_disk_daily WHERE day < DATE_SUB(CURDATE(), INTERVAL 730 DAY)");
    $stmt->execute();
    $result['disk_daily'] = $stmt->rowCount();

    // The daily rows of a disk that has not been seen for two years go with
    // it through the FK cascade; they are not counted here, because MySQL
    // reports only the rows this statement deleted itself.
    $stmt = $pdo->prepare("DELETE FROM storage_disks WHERE last_seen < DATE_SUB(NOW(), INTERVAL 730 DAY)");
    $stmt->execute();
    $result['disks'] = $stmt->rowCount();

    $stmt = $pdo->prepare(
        "DELETE FROM router_rec_state
          WHERE muted_at IS NULL
            AND (last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL 90 DAY))"
    );
    $stmt->execute();
    $result['rec_state'] = $stmt->rowCount();

    return $result;
}

/**
 * Retention for the WAN measurements.
 *
 * Line speed is the one router metric worth comparing with last year's
 * contract, so the rows live 400 days - a year plus the month it takes to
 * notice. The `diagnostics` blob is the opposite: up to 2 kB per row that
 * only answers "was the router itself the bottleneck during THIS test", a
 * question nobody asks about a test from last quarter. It is nulled after 90
 * days, while the measurement itself stays.
 *
 * Returns the affected row counts, like `bk_prune_router_health`.
 */
function bk_prune_wan_data(PDO $pdo): array {
    $result = ['speedtests' => 0, 'diagnostics' => 0];

    $stmt = $pdo->prepare("DELETE FROM speedtest_results WHERE measured_at < DATE_SUB(NOW(), INTERVAL 400 DAY)");
    $stmt->execute();
    $result['speedtests'] = $stmt->rowCount();

    $stmt = $pdo->prepare(
        "UPDATE speedtest_results SET diagnostics = NULL
          WHERE diagnostics IS NOT NULL
            AND measured_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    $stmt->execute();
    $result['diagnostics'] = $stmt->rowCount();

    return $result;
}

/**
 * Retention for the outgoing message log.
 *
 * Half a year, twice what the audit trail keeps. Since `send_email()` writes
 * here, the table stopped being an alert history: it also answers "did that
 * invitation ever arrive?", which somebody asks the next time the invited
 * person fails to log in - months later. The rows are cheap (no message body
 * is ever stored) and their number is bounded by what this installation
 * really sends, so the length costs almost nothing.
 *
 * A function, not the inline DELETE it replaced, so the suite can hand it
 * rows on both sides of the boundary; nothing in a suite runs cron.php.
 * Returns the deleted count, like the prune functions above it.
 */
function bk_prune_notification_log(PDO $pdo, int $days = 180): array {
    $days = max(1, $days);

    $stmt = $pdo->prepare("DELETE FROM notification_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $stmt->execute([$days]);

    return ['deleted' => $stmt->rowCount()];
}

function bk_rollup_daily_metrics(PDO $pdo, int $days = 2): int {
    $days = max(1, min(400, $days));
    $written = 0;

    foreach (bk_metric_column_map() as $metric_key => $def) {
        $col = $def['col'] ?? null;
        if ($col === null) {
            continue;
        }
        // A column name cannot be a bound parameter; it comes from our own map
        // in code, not from input, but its shape is verified just in case.
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
            // A column missing on an older database must not kill the whole
            // aggregation - the remaining metrics still get processed.
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
 * Lived in api.php, but cron needs it too for the daily rollups. If it
 * existed twice they would drift apart - and a metric missing from the
 * rollup shows only a month later, when the raw data is already gone.
 */
function bk_metric_column_map(): array {
    // Mind `net` vs `net_ipv4`/`net_ipv6`: they are NOT parts of one whole.
    // `net` is rx+tx on the WAN interface only, while the protocol counters
    // come from /proc/net/netstat, i.e. across ALL interfaces including LAN.
    // The IPv4+IPv6 sum therefore tends to exceed `net`, and placing them side
    // by side as a breakdown of one number would show a sum that does not add up.
    return [
    'cpu' => ['col' => 'cpu_usage', 'unit' => '%', 'label' => 'Využití CPU'],
    'ram' => ['col' => 'ram_usage', 'unit' => '%', 'label' => 'Využití paměti'],
    'hdd' => ['col' => 'hdd_usage', 'unit' => '%', 'label' => 'Zaplnění disku'],
    'net' => ['col' => 'net_usage', 'unit' => 'KB/s', 'label' => 'Síťový provoz na WAN'],
    'net_lte' => ['col' => 'net_lte_kbps', 'unit' => 'KB/s', 'label' => 'Síťový provoz na LTE záloze'],
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
    // These two series read the SAME column (how many people are online) and
    // differ only in naming. 'only' therefore restricts them to the monitor
    // type they make sense for - otherwise the Discord detail would show two
    // identical charts, one titled "TeamSpeak Clients".
    'ts_clients' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'TeamSpeak Klienti', 'only' => ['teamspeak']],
    'discord_presence' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'Online na Discordu', 'only' => ['discord']],
    'mc_players' => ['col' => 'ts_clients_online', 'unit' => '', 'label' => 'Hráči online', 'only' => ['minecraft']],
    'ts_process_cpu' => ['col' => 'ts_process_cpu', 'unit' => '%', 'label' => 'CPU procesu TS3'],
    'ts_process_ram' => ['col' => 'ts_process_ram', 'unit' => 'MB', 'label' => 'RAM procesu TS3'],
    'net_ipv4' => ['col' => 'net_ipv4_kbps', 'unit' => 'KB/s', 'label' => 'IPv4 provoz (všechna rozhraní)'],
    'net_ipv6' => ['col' => 'net_ipv6_kbps', 'unit' => 'KB/s', 'label' => 'IPv6 provoz (všechna rozhraní)'],
    'temperature_c' => ['col' => 'temperature_c', 'unit' => '°C', 'label' => 'Teplota CPU'],
    // Columns stored for years that no chart ever read (audit 2026-08-05):
    'zombie_count' => ['col' => 'zombie_count', 'unit' => '', 'label' => 'Zombie procesy'],
    'fork_rate' => ['col' => 'fork_rate', 'unit' => '/s', 'label' => 'Fork rate'],
    'wifi_clients' => ['col' => 'wifi_clients_total', 'unit' => '', 'label' => 'Wi-Fi klienti'],
    // Clients per band. The total alone could not say how many were on 2.4 GHz;
    // the server sums each band from the radios every OpenWrt agent reports.
    'wifi_clients_24g' => ['col' => 'wifi_clients_24g', 'unit' => '', 'label' => 'Wi-Fi klienti na 2.4 GHz', 'only' => ['openwrt']],
    'wifi_clients_5g' => ['col' => 'wifi_clients_5g', 'unit' => '', 'label' => 'Wi-Fi klienti na 5 GHz', 'only' => ['openwrt']],
    'wifi_clients_6g' => ['col' => 'wifi_clients_6g', 'unit' => '', 'label' => 'Wi-Fi klienti na 6 GHz', 'only' => ['openwrt']],
    // Of the clients on 2.4 and 5 GHz: how many list a 6 GHz operating class
    // (Wi-Fi 6E), and how many told at all - the rest are unknown, not "no".
    'wifi_6e_capable_24g' => ['col' => 'wifi_6e_capable_24g', 'unit' => '', 'label' => 'Klienti na 2.4 GHz s podporou Wi-Fi 6E', 'only' => ['openwrt']],
    'wifi_6e_known_24g' => ['col' => 'wifi_6e_known_24g', 'unit' => '', 'label' => 'Klienti na 2.4 GHz se známou podporou pásem', 'only' => ['openwrt']],
    'wifi_6e_capable_5g' => ['col' => 'wifi_6e_capable_5g', 'unit' => '', 'label' => 'Klienti na 5 GHz s podporou Wi-Fi 6E', 'only' => ['openwrt']],
    'wifi_6e_known_5g' => ['col' => 'wifi_6e_known_5g', 'unit' => '', 'label' => 'Klienti na 5 GHz se známou podporou pásem', 'only' => ['openwrt']],
    // Radio conditions per band (agent 0.1.7). Noise and channel load are a
    // MAX over the AP radios of the band - two radios of one phy are one
    // measurement - and `busy_other` belongs to the radio the maximum came
    // from, so "the channel is full" and "of somebody else's traffic" always
    // describe the same radio.
    'wifi_noise_24g' => ['col' => 'wifi_noise_24g', 'unit' => 'dBm', 'label' => 'Šum Wi-Fi na 2.4 GHz', 'only' => ['openwrt']],
    'wifi_noise_5g' => ['col' => 'wifi_noise_5g', 'unit' => 'dBm', 'label' => 'Šum Wi-Fi na 5 GHz', 'only' => ['openwrt']],
    'wifi_noise_6g' => ['col' => 'wifi_noise_6g', 'unit' => 'dBm', 'label' => 'Šum Wi-Fi na 6 GHz', 'only' => ['openwrt']],
    'wifi_busy_24g' => ['col' => 'wifi_busy_24g', 'unit' => '%', 'label' => 'Vytížení kanálu na 2.4 GHz', 'only' => ['openwrt']],
    'wifi_busy_5g' => ['col' => 'wifi_busy_5g', 'unit' => '%', 'label' => 'Vytížení kanálu na 5 GHz', 'only' => ['openwrt']],
    'wifi_busy_6g' => ['col' => 'wifi_busy_6g', 'unit' => '%', 'label' => 'Vytížení kanálu na 6 GHz', 'only' => ['openwrt']],
    'wifi_busy_other_24g' => ['col' => 'wifi_busy_other_24g', 'unit' => '%', 'label' => 'Cizí provoz na kanálu 2.4 GHz', 'only' => ['openwrt']],
    'wifi_busy_other_5g' => ['col' => 'wifi_busy_other_5g', 'unit' => '%', 'label' => 'Cizí provoz na kanálu 5 GHz', 'only' => ['openwrt']],
    'wifi_busy_other_6g' => ['col' => 'wifi_busy_other_6g', 'unit' => '%', 'label' => 'Cizí provoz na kanálu 6 GHz', 'only' => ['openwrt']],
    'wifi_weak_clients' => ['col' => 'wifi_weak_clients', 'unit' => '', 'label' => 'Wi-Fi klienti se slabým signálem', 'only' => ['openwrt']],
    'wifi_wpa2_clients' => ['col' => 'wifi_wpa2_clients', 'unit' => '', 'label' => 'Wi-Fi klienti připojení přes WPA2', 'only' => ['openwrt']],
    // Three-valued at the ingest (1/0/null), so its daily average is the share
    // of the samples on which the answer was KNOWN - never a measured zero.
    'wifi_6e_unserved' => ['col' => 'wifi_6e_unserved', 'unit' => '', 'label' => 'Klienti s 6 GHz bez 6GHz rádia (podíl času)', 'only' => ['openwrt']],
    'wifi_5g_capable_24g' => ['col' => 'wifi_5g_capable_24g', 'unit' => '', 'label' => 'Klienti na 2.4 GHz s podporou 5 GHz', 'only' => ['openwrt']],
    'conntrack' => ['col' => 'conntrack_pct', 'unit' => '%', 'label' => 'Conntrack tabulka'],
    // Metrics added 08/2026: agents sent them every minute, but only the
    // last snapshot was stored, so no history survived.
    'wan_latency_ms' => ['col' => 'wan_latency_ms', 'unit' => 'ms', 'label' => 'Latence WAN'],
    'dns_latency_ms' => ['col' => 'dns_latency_ms', 'unit' => 'ms', 'label' => 'Latence DNS'],
    'entropy' => ['col' => 'entropy_avail', 'unit' => 'bit', 'label' => 'Dostupná entropie'],
    'lte_rsrp' => ['col' => 'lte_rsrp', 'unit' => 'dBm', 'label' => 'LTE RSRP (síla signálu)'],
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
    // WAN path and router runtime (agent 0.1.7). The rate and CPU series are
    // ordinary averages; the five counters below are STEPS - the ingest already
    // stored the difference against the previous report, so a bucket is their
    // SUM, not their average. Without 'step' a day with 40 errors would be
    // drawn as "0.03 errors" and nobody would ever see it.
    //
    // Why 'step' and not the existing 'counter': a counter series is the raw
    // cumulative value and a lost report or a reboot breaks it (max - min);
    // a step series loses nothing when a report is lost (the next step spans
    // it) and the daily total is avg_val * samples.
    'cpu_core_max' => ['col' => 'cpu_core_max', 'unit' => '%', 'label' => 'Nejvytíženější jádro CPU', 'only' => ['openwrt']],
    'cpu_core_max_softirq' => ['col' => 'cpu_core_max_softirq', 'unit' => '%', 'label' => 'Obsluha přerušení na nejvytíženějším jádře', 'only' => ['openwrt']],
    'wan_rx_mbps' => ['col' => 'wan_rx_mbps', 'unit' => 'Mbit/s', 'label' => 'Stahování na WAN', 'only' => ['openwrt']],
    'wan_tx_mbps' => ['col' => 'wan_tx_mbps', 'unit' => 'Mbit/s', 'label' => 'Odesílání na WAN', 'only' => ['openwrt']],
    'wan_errors' => ['col' => 'wan_errors', 'unit' => '', 'label' => 'Chyby na portu WAN', 'only' => ['openwrt'], 'step' => true],
    // Evidence only, no rule reads it: sysfs counts unhandled protocols here too.
    'wan_drops' => ['col' => 'wan_drops', 'unit' => '', 'label' => 'Zahozené pakety na WAN (i neznámé protokoly)', 'only' => ['openwrt'], 'step' => true],
    'wan_ring_drops' => ['col' => 'wan_ring_drops', 'unit' => '', 'label' => 'Zahozeno ve frontě portu WAN', 'only' => ['openwrt'], 'step' => true],
    'wan_link_flaps' => ['col' => 'wan_link_flaps', 'unit' => '', 'label' => 'Výpadky linky na portu WAN', 'only' => ['openwrt'], 'step' => true],
    'conntrack_drops' => ['col' => 'conntrack_drops', 'unit' => '', 'label' => 'Odmítnutá spojení v conntracku', 'only' => ['openwrt'], 'step' => true],
    'agent_run_ms' => ['col' => 'agent_run_ms', 'unit' => 'ms', 'label' => 'Doba běhu agenta', 'only' => ['openwrt']],
    // Absolute value: a median of a signed column cannot be derived from a
    // daily avg/min/max, a weighted mean of a non-negative one can.
    'clock_skew_s' => ['col' => 'clock_skew_s', 'unit' => 's', 'label' => 'Odchylka hodin routeru', 'only' => ['openwrt']],
];
}

/**
 * Pearson correlation of two aligned series.
 *
 * Pairs where either side is null are skipped - the two metrics come from the
 * same measurement row, but an agent may report one and not the other.
 *
 * Returns NULL, never 0, when the coefficient is undefined: fewer than
 * $min_pairs usable pairs, or a series that never changes (a swap that sat at
 * 0 % all week has no variance to correlate). Zero would be a statement -
 * "these two are unrelated" - and that is not what an undefined result says.
 *
 * @param array<int,float|null> $xs
 * @param array<int,float|null> $ys
 * @return array{r: float|null, pairs: int, reason: string|null}
 */
function bk_pearson(array $xs, array $ys, int $min_pairs = 10): array {
    $x = [];
    $y = [];
    foreach ($xs as $i => $xv) {
        $yv = $ys[$i] ?? null;
        if ($xv === null || $yv === null) {
            continue;
        }
        $x[] = (float)$xv;
        $y[] = (float)$yv;
    }

    $n = count($x);
    if ($n < $min_pairs) {
        return ['r' => null, 'pairs' => $n, 'reason' => 'few_samples'];
    }

    $mean_x = array_sum($x) / $n;
    $mean_y = array_sum($y) / $n;
    $cov = 0.0;
    $var_x = 0.0;
    $var_y = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $x[$i] - $mean_x;
        $dy = $y[$i] - $mean_y;
        $cov += $dx * $dy;
        $var_x += $dx * $dx;
        $var_y += $dy * $dy;
    }

    if ($var_x <= 0.0 || $var_y <= 0.0) {
        return ['r' => null, 'pairs' => $n, 'reason' => 'constant'];
    }

    // Rounding keeps the result off the edge: accumulated float error can push
    // a perfect correlation to 1.0000000002, and |r| > 1 is not a number the
    // UI should ever have to explain.
    $r = $cov / sqrt($var_x * $var_y);
    return ['r' => round(max(-1.0, min(1.0, $r)), 3), 'pairs' => $n, 'reason' => null];
}

/**
 * Turns a cumulative counter into per-measurement increments.
 *
 * Counters (firewall packets, DNS queries) only ever grow, so correlating
 * their raw values would find every pair of counters near-perfectly related -
 * they all just count upwards with time. A drop means the counter reset
 * (reboot); that increment is unknowable, so it becomes null rather than a
 * fabricated spike. The first sample has no predecessor and is null too, which
 * keeps the array aligned with the other metrics' rows.
 *
 * @param array<int,float|null> $values
 * @return array<int,float|null>
 */
function bk_counter_deltas(array $values): array {
    $out = [];
    $prev = null;
    foreach ($values as $i => $v) {
        if ($v === null) {
            $out[$i] = null;
            // A gap breaks the chain: the next reading's increment would span
            // an unknown stretch of time.
            $prev = null;
            continue;
        }
        $out[$i] = ($prev !== null && $v >= $prev) ? (float)$v - $prev : null;
        $prev = (float)$v;
    }
    return $out;
}

/**
 * New events of a cumulative counter since the previous report.
 *
 * Null when either side is unknown, when the source changed (another WAN
 * device, a reboot) and on a RESET: the value after a reboot is dominated by
 * link bring-up, and booking it as one minute's step would fabricate a spike -
 * the same convention as bk_counter_deltas() and the counter charts. What is
 * lost is said as such: events between the last report and the reboot are not
 * counted, so every step series is a lower bound.
 */
function bk_counter_step(?int $prev, ?int $cur, bool $same_source): ?int {
    if ($prev === null || $cur === null || !$same_source || $cur < $prev) {
        return null;
    }
    return $cur - $prev;
}

/**
 * Step metrics of the WAN path for one report, and the state the next report
 * is compared with (`last_details.wan_counters_prev`).
 *
 * The state is keyed by the WAN device and the router's uptime: totals of
 * another netdev are not one minute's step, and a reboot after which the
 * counter already outgrew the old value (`cur >= prev`) is only visible as an
 * uptime that went backwards. A counter the agent could not read this minute
 * keeps its previous value, so the next step spans the gap and the day's sum
 * loses nothing.
 *
 * Ring drops come from `ethtool -S` once an hour: their step is written only
 * on the report that carries a NEW `wan_path.checked_at`, null on every other.
 *
 * @param mixed $prev the stored state (anything but an array = first report)
 * @param array<string, ?int> $cur cumulative values of this report
 * @return array{steps: array<string, ?int>, state: ?array<string, mixed>}
 */
function bk_wan_counter_steps($prev, array $cur, ?string $dev, ?int $uptime, ?int $path_checked_at): array {
    $minute_keys = ['wan_rx_errors', 'wan_tx_errors', 'wan_rx_dropped', 'wan_tx_dropped', 'conntrack_drop', 'wan_carrier_down_count'];
    $prev = is_array($prev) ? $prev : [];
    $prev_uptime = is_int($prev['uptime'] ?? null) ? $prev['uptime'] : null;
    $same = $prev !== [] && ($prev['dev'] ?? null) === $dev
        && $uptime !== null && $prev_uptime !== null && $uptime >= $prev_uptime;
    $old = fn (string $key): ?int => is_int($prev[$key] ?? null) ? $prev[$key] : null;

    $step = [];
    $state = ['dev' => $dev, 'uptime' => $uptime];
    foreach ($minute_keys as $key) {
        $now = $cur[$key] ?? null;
        $step[$key] = bk_counter_step($old($key), $now, $same);
        $state[$key] = ($now === null && $same) ? $old($key) : $now;
    }
    // A sum of two directions is known only when both are.
    $both = fn (?int $a, ?int $b): ?int => ($a === null || $b === null) ? null : $a + $b;

    $ring = $cur['wan_rx_ring_drops'] ?? null;
    $ring_step = null;
    $state['wan_rx_ring_drops'] = $same ? $old('wan_rx_ring_drops') : null;
    $state['ring_at'] = $same ? $old('ring_at') : null;
    if ($path_checked_at !== null && $path_checked_at !== $state['ring_at']) {
        $ring_step = bk_counter_step($state['wan_rx_ring_drops'], $ring, $same);
        $state['wan_rx_ring_drops'] = $ring;
        $state['ring_at'] = $path_checked_at;
    }

    $measured = array_filter($state, fn ($v, $k) => $v !== null && !in_array($k, ['dev', 'uptime', 'ring_at'], true), ARRAY_FILTER_USE_BOTH);
    return [
        'steps' => [
            'wan_errors' => $both($step['wan_rx_errors'], $step['wan_tx_errors']),
            'wan_drops' => $both($step['wan_rx_dropped'], $step['wan_tx_dropped']),
            'wan_ring_drops' => $ring_step,
            'wan_link_flaps' => $step['wan_carrier_down_count'],
            // `drop` only: insert_failed also grows on harmless races and
            // early_drop counts entries evicted to MAKE room - nothing refused.
            'conntrack_drops' => $step['conntrack_drop'],
        ],
        // An agent that reads none of the counters (0.1.6, a VPS) keeps no state.
        'state' => $measured === [] ? null : $state,
    ];
}

/**
 * Cloudflare ranges from which the visitor-IP header may be trusted.
 *
 * Source: https://www.cloudflare.com/ips/ - changes about once in years,
 * but an expansion means updating this list. Anyone not using Cloudflare
 * does nothing: without a match, REMOTE_ADDR is simply used.
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
 * Does the IP fall into a CIDR range? Handles IPv4 and IPv6.
 *
 * Compared bit by bit over the binary form from inet_pton - a string prefix
 * comparison would fail on shortened IPv6 notation (2400:cb00:: and
 * 2400:cb00:0:0:0:0:0:0 are the same address).
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
    // Do not mix IPv4 and IPv6 - the binary length differs (4 vs 16 bytes).
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
 * The visitor's IP address - the real one, not the proxy's.
 *
 * The app runs behind Cloudflare, so REMOTE_ADDR is their edge node. The
 * audit log therefore recorded Cloudflare IPs for sign-ins and was useless
 * for tracing who signed in from where. Worse was the effect on account
 * lockout after failed attempts: it counts attempts by name OR IP, so the
 * IP part became either nothing, or a way to lock out someone else who
 * happened to hit the same edge node.
 *
 * The header is trusted ONLY when the request really came from Cloudflare.
 * Without that condition anyone can send `CF-Connecting-IP: 1.2.3.4` and
 * write an arbitrary address into the log - or dodge the lockout by
 * changing it on every attempt.
 *
 * A custom proxy (nginx, HAProxy) can be added via the `trusted_proxies`
 * setting as a comma-separated CIDR list.
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
            // The header may carry nonsense too - without a shape check an
            // arbitrary string would land in the log.
            return filter_var($forwarded, FILTER_VALIDATE_IP) !== false ? $forwarded : $remote;
        }
    }

    return $remote;
}

/**
 * The browser/client that sent the request.
 *
 * Trimmed to 255 characters: the column has that length and longer
 * User-Agents exist (some corporate browsers send hundreds of characters).
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
 * An outage alert goes out once and that is it. When nobody sees it -
 * it is night, the phone is muted, Discord drowned it in another
 * conversation - the outage keeps running and monitoring calls it a day.
 * Escalation is the safety net: whatever nobody acknowledged in time is announced again, elsewhere.
 *
 * The conditions are strict on purpose, because escalation wakes a human:
 *   - the incident is not resolved (alerting on a resolved one is pointless)
 *   - nobody acknowledged it (acknowledged_at is empty)
 *   - the configured time has passed since creation
 *   - it has not escalated yet (escalated_at is empty) - otherwise it would
 *     repeat on every cron run and train people to ignore it like the first one
 *
 * @param array $incident Row from `incidents`
 * @param int   $after_mins Minutes without acknowledgement before escalating
 * @param ?int  $now Evaluation time; NULL = now (parameter for tests)
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
        // Without a creation time there is no computing how long it waited.
        // Escalating "just in case" would wake a human over a corrupt record.
        return ['escalate' => false, 'reason' => 'incident nemá použitelný čas vzniku', 'waiting_secs' => null];
    }

    $waiting = $now - $created_ts;
    if ($waiting < $after_mins * 60) {
        return ['escalate' => false, 'reason' => 'lhůta na převzetí ještě běží', 'waiting_secs' => $waiting];
    }

    return ['escalate' => true, 'reason' => 'nikdo incident nepřevzal', 'waiting_secs' => $waiting];
}

/**
 * Walks the open incidents and reports the unacknowledged ones to the escalation channel.
 *
 * Called from cron after the monitor checks. The channel is deliberately different from the one
 * regular one: escalation only makes sense somewhere the first alert
 * did not just sink.
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
            WHERE i.status != 'resolved' AND i.escalated_at IS NULL AND (i.monitor_id IS NULL OR m.archived_at IS NULL)
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

        // Without a configured channel the stamp is NOT set. If it were, the
        // incident would look escalated and never speak up once a channel was
        // added - a silent failure exactly where the safety net must work.
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

        // The payload carries both `content` and `text`: Discord reads the first,
        // Slack the second, and both ignore an unknown key. One channel covers both.
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
 * Generates the secret for a heartbeat URL.
 *
 * The token is all that authorises the job - hence from a CSPRNG and long
 * enough not to be guessable. Hex, so it survives curl, wget and Task
 * Scheduler without escaping.
 */
function bk_heartbeat_generate_token(): string {
    return bin2hex(random_bytes(24));
}

/**
 * Evaluates a heartbeat monitor's state from when the job last reported.
 *
 * The opposite direction from the rest of monitoring: we do not ask the
 * service, it reports to us. Covers what an active check cannot reach -
 * backups, cronjobs and batches with nothing to ping, whose failure today
 * shows only the moment the backup is needed.
 *
 * Three states are distinguished, not two:
 *   up      - the signal arrived in time and the job reports success
 *   down    - either the job missed the interval + grace, or it directly
 *             reported failure (?status=fail)
 *   unknown - it never reported yet, or has no interval configured
 *
 * The last one matters: a monitor that never got a signal is NOT down.
 * We know nothing about it. Created as 'down' it would alert on an outage
 * that never happened - the same lie as an invented zero in a chart.
 *
 * @param array $monitor Row from `monitors` (the heartbeat_* columns)
 * @param ?int  $now     Evaluation time; NULL = now (parameter for tests)
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

    // Grace is optional; without it the interval is enforced exactly.
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

    // Negative age = a signal from the future (skewed clock on the job's
    // machine). Treated as fresh, but zero would claim it arrived just now.
    $age = $now - $last_ts;

    // A reported failure beats age: the job ran on time but ended in error.
    // Staying silent just because the signal arrived would reduce the watchdog
    // to checking that cron starts - not that the backup was made.
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
 * A duration in seconds as readable text ("2 h 5 min").
 *
 * Heartbeat messages are read by a human in the middle of the night -
 * "silent for 7,320 s" forces arithmetic, "silent for 2 h 2 min" does not.
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
 * Evaluates whether a monitor's latency is persistently degraded.
 *
 * Monitoring could only say "the service is down". A site that slowed from
 * 80 ms to 900 ms and stayed there was still "up" and nobody ever learned.
 *
 *
 * The condition is strict on purpose: both the AVERAGE and ALL checks in the
 * window must sit above the threshold. One slow response (overloaded DNS
 * resolver, random packet loss) is noise, not an incident - and an alert
 * that cries at noise teaches everyone to ignore it within a week.
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
    // At least two measurements - persistence cannot be judged from one.
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
        // Recovery is reported as soon as one check fits under the threshold -
        // otherwise the "still ongoing" notice would hang after the return to normal.
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
        // Without the table (old DB) the rollup is skipped; SLA still works
        // over raw logs within their retention.
        error_log('[rollup] uptime_daily skipped: ' . $e->getMessage());
        return 0;
    }
}

/**
 * The same days of uptime_daily in TIME (bk_uptime_segments, W1-B1).
 *
 * Kept apart from the row counts above because it reads every log row of the
 * window into PHP; cron runs it every ten minutes, and once over the whole
 * retained range after the deploy so the older days get the same definition.
 * A day the monitor did not exist yet gets no row. Days whose logs were
 * already pruned keep NULL seconds - they cannot be recomputed, and the
 * readers say that such a day knows only its check counts.
 *
 * @return int rows written, -1 when the rollup failed (the caller keeps its
 *             backfill flag unset and tries again)
 */
function bk_rollup_daily_uptime_time(PDO $pdo, int $days = 5): int {
    $days = max(1, min(400, $days));
    try {
        $to = time();
        $from = strtotime(date('Y-m-d 00:00:00', strtotime('-' . $days . ' day')));
        $stmt = $pdo->prepare("
            INSERT INTO uptime_daily (monitor_id, day, secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                secs_up = VALUES(secs_up),
                secs_down = VALUES(secs_down),
                secs_warning = VALUES(secs_warning),
                secs_silent = VALUES(secs_silent),
                secs_maintenance = VALUES(secs_maintenance),
                secs_unmeasured = VALUES(secs_unmeasured)
        ");
        $written = 0;
        foreach (bk_uptime_segments_for($pdo, $from, $to) as $mid => $seg) {
            foreach (bk_uptime_by_day($seg['segments'], $seg['from'], $seg['to']) as $day => $sum) {
                // A day nobody measured gets no row: a row would count as a
                // covered day (websites_overview longTermDays, the window's
                // day count) for a monitor that has no history there.
                if ($sum['measured'] + $sum['maintenance'] === 0) {
                    continue;
                }
                $stmt->execute([$mid, $day, $sum['up'], $sum['down'], $sum['warning'], $sum['silent'], $sum['maintenance'], $sum['unmeasured']]);
                $written++;
            }
        }
        return $written;
    } catch (Throwable $e) {
        error_log('[rollup] uptime_daily time columns skipped: ' . $e->getMessage());
        return -1;
    }
}


/**
 * Availability measured in TIME, not in check rows (W1-B1).
 *
 * Uptime used to be "up rows / all rows". A silent agent writes a single
 * 'down' row (cron.php) and then nothing, so a three-day blackout was one row
 * among thousands and still scored about 99.99 %. Here every row stands for
 * the time until the next row, capped at 2.5 check intervals; what no row
 * covers is a gap. For an agent (vps, openwrt) that has reported before, a
 * gap is the outage itself - the router is off or its line is down. For an
 * active check a gap means cron did not run, which says nothing about the
 * service: that time is unmeasured and leaves the percentage alone.
 *
 * The functions below are pure (rows in, seconds out), so run_tests.php can
 * pin the arithmetic without a database; bk_uptime_segments_for() feeds them.
 */

/**
 * The monitor's check interval, read off its own rows.
 *
 * There is no interval column: cron checks every minute where the host runs
 * it every minute, agents report on their own cron. The median gap is immune
 * to the blackouts it is about to measure (one three-day gap among thousands
 * of one-minute gaps moves it nowhere). Clamped to 60-1800 s - cron runs at
 * most once a minute, and several probe locations writing within seconds of
 * each other must not shrink the cap to nothing.
 *
 * @param list<int> $timestamps ascending
 */
function bk_uptime_interval(array $timestamps): int {
    $gaps = [];
    $prev = null;
    foreach ($timestamps as $ts) {
        if ($prev !== null && $ts > $prev) {
            $gaps[] = $ts - $prev;
        }
        $prev = $ts;
    }
    if (count($gaps) < 2) {
        // Too little to read a cadence from: the slow end of the documented
        // 1-5 minute cron, so an unknown cadence never invents an outage.
        return 300;
    }
    sort($gaps);
    $median = $gaps[intdiv(count($gaps), 2)];
    return max(60, min(1800, $median));
}

/**
 * Turns log rows into time segments [start, end, class] inside [from, to].
 *
 * Classes: up, down, warning, maintenance, unmeasured, silent. 'silent' is
 * uncovered time of an agent that had reported before - it counts as down
 * and is kept apart only so a report can say how much of the outage was
 * silence. 'unknown' rows (an agent-side check whose agent went quiet) are
 * unmeasured time: the monitor stays in the SLA with what was measured.
 *
 * @param list<array{0:int,1:string}> $rows [unix ts, status], ascending; the
 *        first may lie before $from (the state the window starts in)
 * @param bool $silence_is_down an agent type with silence detection on
 * @param bool $reported_before the agent is known to have reported before
 *        $from even though no row shows it (its logs were pruned)
 * @return list<array{0:int,1:int,2:string}>
 */
function bk_uptime_segments(array $rows, int $from, int $to, int $interval, bool $silence_is_down, bool $reported_before = false): array {
    $segments = [];
    if ($to <= $from) {
        return $segments;
    }
    $cap = max(1, (int)round($interval * 2.5));
    $push = function (int $a, int $b, string $class) use (&$segments, $from, $to): void {
        $a = max($a, $from);
        $b = min($b, $to);
        if ($b <= $a) {
            return;
        }
        $last = count($segments) - 1;
        if ($last >= 0 && $segments[$last][2] === $class && $segments[$last][1] === $a) {
            $segments[$last][1] = $b;
            return;
        }
        $segments[] = [$a, $b, $class];
    };
    $gap_class = fn (bool $reported): string => ($silence_is_down && $reported) ? 'silent' : 'unmeasured';

    $cursor = $from;
    $reported = $reported_before;
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $ts = (int)$rows[$i][0];
        if ($ts >= $to) {
            break;
        }
        if ($ts > $cursor) {
            $push($cursor, $ts, $gap_class($reported));
            $cursor = $ts;
        }
        $next = $i + 1 < $n ? (int)$rows[$i + 1][0] : PHP_INT_MAX;
        $end = min($next, $ts + $cap, $to);
        $status = strtolower((string)$rows[$i][1]);
        $class = in_array($status, ['up', 'down', 'warning', 'maintenance'], true) ? $status : 'unmeasured';
        if ($end > $cursor) {
            $push(max($cursor, $ts), $end, $class);
            $cursor = $end;
        }
        $reported = true;
    }
    if ($cursor < $to) {
        $push($cursor, $to, $gap_class($reported));
    }
    return $segments;
}

/**
 * Seconds per class inside [from, to], and the availability they give.
 *
 * pct = up / (up + down + warning + silent): warning is not "up" (the same
 * rule the row count used), maintenance and unmeasured time are outside the
 * fraction. Nothing measured -> pct null, never 100.
 *
 * @param list<array{0:int,1:int,2:string}> $segments
 * @return array{up:int,down:int,warning:int,maintenance:int,unmeasured:int,silent:int,measured:int,outage:int,pct:?float}
 */
function bk_uptime_summary(array $segments, int $from, int $to): array {
    $s = ['up' => 0, 'down' => 0, 'warning' => 0, 'maintenance' => 0, 'unmeasured' => 0, 'silent' => 0];
    foreach ($segments as [$a, $b, $class]) {
        $a = max($a, $from);
        $b = min($b, $to);
        if ($b > $a) {
            $s[$class] = ($s[$class] ?? 0) + ($b - $a);
        }
    }
    return bk_uptime_totals([$s]);
}

/**
 * Adds up seconds per class (days, a live part and rolled-up days) and
 * derives the outage, the measured time and the percentage from the sum -
 * never an average of percentages, which would weigh a half-measured day
 * like a whole one.
 *
 * @param list<array<string,int|float|null>> $parts
 * @return array{up:int,down:int,warning:int,maintenance:int,unmeasured:int,silent:int,measured:int,outage:int,pct:?float}
 */
function bk_uptime_totals(array $parts): array {
    $s = ['up' => 0, 'down' => 0, 'warning' => 0, 'maintenance' => 0, 'unmeasured' => 0, 'silent' => 0];
    foreach ($parts as $part) {
        foreach ($s as $class => $sum) {
            $s[$class] = $sum + (int)($part[$class] ?? 0);
        }
    }
    $outage = $s['down'] + $s['silent'];
    $measured = $s['up'] + $s['warning'] + $outage;
    return $s + [
        'measured' => $measured,
        'outage' => $outage,
        'pct' => $measured > 0 ? round($s['up'] / $measured * 100, 3) : null,
    ];
}

/**
 * The same summary per calendar day (Y-m-d in PHP's zone), for the 30-day
 * strip and the uptime_daily rollup. A segment across midnight is split.
 *
 * @param list<array{0:int,1:int,2:string}> $segments
 * @return array<string, array{up:int,down:int,warning:int,maintenance:int,unmeasured:int,silent:int,measured:int,outage:int,pct:?float}>
 */
function bk_uptime_by_day(array $segments, int $from, int $to): array {
    $raw = [];
    $day_start = strtotime(date('Y-m-d 00:00:00', $from));
    while ($day_start < $to) {
        $raw[date('Y-m-d', $day_start)] = [];
        $day_start = strtotime('+1 day', $day_start);
    }
    foreach ($segments as [$a, $b, $class]) {
        $a = max($a, $from);
        $b = min($b, $to);
        while ($b > $a) {
            // strtotime, not +86400: a DST day is 23 or 25 hours long.
            $midnight = strtotime('+1 day', strtotime(date('Y-m-d 00:00:00', $a)));
            $piece_end = min($b, $midnight);
            $raw[date('Y-m-d', $a)][] = [$a, $piece_end, $class];
            $a = $piece_end;
        }
    }
    $days = [];
    foreach ($raw as $day => $pieces) {
        $days[$day] = bk_uptime_summary($pieces, PHP_INT_MIN, PHP_INT_MAX);
    }
    return $days;
}

/**
 * The time segments of each monitor inside [from, to], read from monitor_logs.
 *
 * One indexed range read per monitor (monitor_id, checked_at), plus the last
 * row before the window: the state the window opens in, and the evidence that
 * a silent agent had been reporting. An agent whose logs were pruned counts
 * as reporting before when its last report (agent_last_seen) predates the
 * window - a router silent for five weeks is down, not unmeasured.
 * The window starts no earlier than the monitor existed, and ends when it was
 * archived: an archived monitor is not checked, and that is no outage.
 *
 * @param list<int>|null $monitor_ids null = every monitor
 * @return array<int, array{type:string,from:int,to:int,segments:list<array{0:int,1:int,2:string}>}>
 */
function bk_uptime_segments_for(PDO $pdo, int $from, int $to, ?array $monitor_ids = null): array {
    $params = [];
    $where = '1=1';
    if ($monitor_ids !== null) {
        $monitor_ids = array_values(array_unique(array_map('intval', $monitor_ids)));
        if (!$monitor_ids) {
            return [];
        }
        $where = 'id IN (' . implode(',', array_fill(0, count($monitor_ids), '?')) . ')';
        $params = $monitor_ids;
    }
    $stmt_m = $pdo->prepare("
        SELECT id, type, last_details,
               UNIX_TIMESTAMP(created_at) AS created_ts,
               UNIX_TIMESTAMP(archived_at) AS archived_ts
        FROM monitors WHERE {$where}
    ");
    $stmt_m->execute($params);
    $monitors = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    // agent_offline_timeout = 0 switches silence detection off; then silence
    // is not an outage here either, only unmeasured time.
    $silence_detection = (int)get_setting('agent_offline_timeout', '50') > 0;
    $stmt_prev = $pdo->prepare("
        SELECT UNIX_TIMESTAMP(checked_at), status FROM monitor_logs
        WHERE monitor_id = ? AND checked_at < FROM_UNIXTIME(?)
        ORDER BY checked_at DESC, id DESC LIMIT 1
    ");
    $stmt_rows = $pdo->prepare("
        SELECT UNIX_TIMESTAMP(checked_at), status FROM monitor_logs
        WHERE monitor_id = ? AND checked_at >= FROM_UNIXTIME(?) AND checked_at < FROM_UNIXTIME(?)
        ORDER BY checked_at ASC, id ASC
    ");

    $out = [];
    foreach ($monitors as $m) {
        $mid = (int)$m['id'];
        $type = strtolower((string)$m['type']);
        $m_to = $m['archived_ts'] !== null ? min($to, (int)$m['archived_ts']) : $to;

        $stmt_prev->execute([$mid, $from]);
        $prev = $stmt_prev->fetch(PDO::FETCH_NUM) ?: null;
        $stmt_rows->execute([$mid, $from, $to]);
        $rows = $stmt_rows->fetchAll(PDO::FETCH_NUM);
        if ($prev !== null) {
            array_unshift($rows, $prev);
        }

        // Rows can predate created_at (a restored or re-created monitor), so
        // the earlier of the two opens the monitor's own window.
        $m_from = $from;
        if ($prev === null) {
            $opened = $m['created_ts'] !== null ? (int)$m['created_ts'] : $from;
            if ($rows) {
                $opened = min($opened, (int)$rows[0][0]);
            }
            $m_from = max($from, $opened);
        }

        $is_agent = in_array($type, ['vps', 'openwrt'], true);
        $reported_before = false;
        if ($is_agent && $prev === null) {
            $details = json_decode((string)($m['last_details'] ?? ''), true);
            $seen = is_array($details) ? ($details['agent_last_seen'] ?? null) : null;
            $reported_before = is_numeric($seen) && (int)$seen > 0 && (int)$seen < $m_from;
        }

        $interval = bk_uptime_interval(array_map(fn ($r) => (int)$r[0], $rows));
        $out[$mid] = [
            'type' => $type,
            'from' => $m_from,
            'to' => $m_to,
            'segments' => bk_uptime_segments($rows, $m_from, $m_to, $interval, $is_agent && $silence_detection, $reported_before),
        ];
    }
    return $out;
}

/**
 * One uptime_daily row as seconds per class.
 *
 * A day rolled up in time carries its seconds. A day from before that (its
 * logs are pruned, it cannot be recomputed) knows only its check counts; it
 * is read as a whole measured day split by those counts and flagged
 * 'approx' - the one place the old row ratio survives, and only for days
 * nothing better exists for. A day with no measured check is unmeasured.
 *
 * @param array<string,mixed> $row
 * @return array{up:int,down:int,warning:int,maintenance:int,unmeasured:int,silent:int,approx:int}
 */
function bk_uptime_daily_part(array $row): array {
    if (($row['secs_up'] ?? null) !== null) {
        return [
            'up' => (int)$row['secs_up'],
            'down' => (int)($row['secs_down'] ?? 0),
            'warning' => (int)($row['secs_warning'] ?? 0),
            'maintenance' => (int)($row['secs_maintenance'] ?? 0),
            'unmeasured' => (int)($row['secs_unmeasured'] ?? 0),
            'silent' => (int)($row['secs_silent'] ?? 0),
            'approx' => 0,
        ];
    }
    $up = (int)($row['checks_up'] ?? 0);
    $down = (int)($row['checks_down'] ?? 0);
    $warning = (int)($row['checks_warning'] ?? 0);
    $total = $up + $down + $warning;
    if ($total <= 0) {
        $maintenance = (int)($row['checks_maintenance'] ?? 0) > 0 ? 86400 : 0;
        return ['up' => 0, 'down' => 0, 'warning' => 0, 'maintenance' => $maintenance, 'unmeasured' => 86400 - $maintenance, 'silent' => 0, 'approx' => 1];
    }
    $down_s = (int)round(86400 * $down / $total);
    $warning_s = (int)round(86400 * $warning / $total);
    return ['up' => 86400 - $down_s - $warning_s, 'down' => $down_s, 'warning' => $warning_s,
        'maintenance' => 0, 'unmeasured' => 0, 'silent' => 0, 'approx' => 1];
}

/**
 * Availability over calendar-day windows ending now, in time.
 *
 * $windows are lengths in days, today included: 7 = today and the six days
 * before it. Today is computed live from monitor_logs (it is short); the
 * finished days come from uptime_daily, which cron rolls up in time
 * (bk_rollup_daily_uptime_time). Reading 30 days of raw logs into PHP on
 * every public page load would cost seconds on the shared hosting, and the
 * rollup is also what reaches past the 30 days the logs are kept.
 *
 * 'days' counts the days of the window that hold any data and 'since' is the
 * oldest of them (Y-m-d, null for none): a 90-day window over a monitor that
 * exists for 40 days says so (W1-B2) instead of passing for 90 days.
 *
 * @param list<int> $monitor_ids
 * @param list<int> $windows
 * @return array<int, array<int, array{up:int,down:int,warning:int,maintenance:int,unmeasured:int,silent:int,measured:int,outage:int,pct:?float,approxDays:int,days:int,since:?string}>>
 */
function bk_uptime_day_windows(PDO $pdo, array $monitor_ids, array $windows): array {
    $monitor_ids = array_values(array_unique(array_map('intval', $monitor_ids)));
    $windows = array_values(array_unique(array_map(fn ($w) => max(1, (int)$w), $windows)));
    if (!$monitor_ids || !$windows) {
        return [];
    }
    $now = time();
    $today = strtotime(date('Y-m-d 00:00:00', $now));
    $live = bk_uptime_segments_for($pdo, $today, $now, $monitor_ids);

    // age 1 = yesterday, age N-1 = the oldest day of an N-day window
    $past = [];
    $max = max($windows);
    if ($max > 1) {
        $in = implode(',', array_fill(0, count($monitor_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT monitor_id, day, checks_up, checks_down, checks_warning,
                   secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured
            FROM uptime_daily
            WHERE monitor_id IN ({$in}) AND day >= ? AND day < ?
        ");
        $stmt->execute(array_merge($monitor_ids, [date('Y-m-d', strtotime('-' . ($max - 1) . ' day', $today)), date('Y-m-d', $today)]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $age = (int)round(($today - strtotime((string)$row['day'] . ' 00:00:00')) / 86400);
            $past[(int)$row['monitor_id']][] = [$age, bk_uptime_daily_part($row)];
        }
    }

    $out = [];
    foreach ($monitor_ids as $mid) {
        $live_part = isset($live[$mid]) ? bk_uptime_summary($live[$mid]['segments'], $live[$mid]['from'], $live[$mid]['to']) : [];
        foreach ($windows as $w) {
            $parts = [$live_part];
            $approx = 0;
            $days = ($live_part['measured'] ?? 0) + ($live_part['maintenance'] ?? 0) > 0 ? 1 : 0;
            $oldest_age = $days > 0 ? 0 : null;
            foreach ($past[$mid] ?? [] as [$age, $part]) {
                if ($age >= 1 && $age <= $w - 1) {
                    $parts[] = $part;
                    $approx += $part['approx'];
                    $days++;
                    $oldest_age = max($oldest_age ?? 0, $age);
                }
            }
            $out[$mid][$w] = bk_uptime_totals($parts) + [
                'approxDays' => $approx,
                'days' => $days,
                'since' => $oldest_age !== null ? date('Y-m-d', strtotime('-' . $oldest_age . ' day', $today)) : null,
            ];
        }
    }
    return $out;
}

/**
 * Availability of each monitor over the calendar days [$first_day, $last_day]
 * (Y-m-d, both included), in time - the window of the monthly report.
 *
 * The same sources as bk_uptime_day_windows: finished days from the
 * uptime_daily rollup (it outlives the 30-day log retention, so last
 * quarter's report still has numbers), today live from the logs when the
 * range reaches it. Days after today are the future, not unmeasured time.
 *
 * @param list<int> $monitor_ids
 * @return array<int, array{up:int,down:int,warning:int,maintenance:int,unmeasured:int,silent:int,measured:int,outage:int,pct:?float,approxDays:int,days:int}>
 */
function bk_uptime_between(PDO $pdo, array $monitor_ids, string $first_day, string $last_day): array {
    $monitor_ids = array_values(array_unique(array_map('intval', $monitor_ids)));
    $first = strtotime($first_day . ' 00:00:00');
    $last = strtotime($last_day . ' 00:00:00');
    if (!$monitor_ids || $first === false || $last === false || $last < $first) {
        return [];
    }
    $now = time();
    $today = strtotime(date('Y-m-d 00:00:00', $now));
    $parts = [];
    $approx = [];
    $days = [];
    if ($first < $today) {
        $in = implode(',', array_fill(0, count($monitor_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT monitor_id, checks_up, checks_down, checks_warning,
                   secs_up, secs_down, secs_warning, secs_silent, secs_maintenance, secs_unmeasured
            FROM uptime_daily
            WHERE monitor_id IN ({$in}) AND day >= ? AND day <= ? AND day < ?
        ");
        $stmt->execute(array_merge($monitor_ids, [date('Y-m-d', $first), date('Y-m-d', $last), date('Y-m-d', $today)]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mid = (int)$row['monitor_id'];
            $part = bk_uptime_daily_part($row);
            $parts[$mid][] = $part;
            $approx[$mid] = ($approx[$mid] ?? 0) + $part['approx'];
            $days[$mid] = ($days[$mid] ?? 0) + 1;
        }
    }
    if ($first <= $today && $last >= $today) {
        foreach (bk_uptime_segments_for($pdo, $today, $now, $monitor_ids) as $mid => $seg) {
            $live = bk_uptime_summary($seg['segments'], $seg['from'], $seg['to']);
            if ($live['measured'] + $live['maintenance'] > 0) {
                $parts[$mid][] = $live;
                $days[$mid] = ($days[$mid] ?? 0) + 1;
            }
        }
    }
    $out = [];
    foreach ($monitor_ids as $mid) {
        $out[$mid] = bk_uptime_totals($parts[$mid] ?? []) + ['approxDays' => $approx[$mid] ?? 0, 'days' => $days[$mid] ?? 0];
    }
    return $out;
}

/**
 * Availability of one monitor over the last $days (today included), in time.
 * null = nothing measured in the window, never an invented 100.
 */
function bk_uptime_30d(PDO $pdo, int $monitor_id, int $days = 30): ?float {
    try {
        return bk_uptime_day_windows($pdo, [$monitor_id], [$days])[$monitor_id][$days]['pct'] ?? null;
    } catch (Throwable $e) {
        // The badge prints "bez dat" for null - a failed read has no number.
        error_log('[uptime] monitor ' . $monitor_id . ': ' . $e->getMessage());
        return null;
    }
}

function bk_iface_has_errors(array $iface): bool {
    $rx = isset($iface['rx_errors']) && is_numeric($iface['rx_errors']) ? (int)$iface['rx_errors'] : 0;
    $tx = isset($iface['tx_errors']) && is_numeric($iface['tx_errors']) ? (int)$iface['tx_errors'] : 0;
    return ($rx + $tx) > 0;
}

/**
 * Strips diacritics so a name can become a URL slug.
 *
 * iconv//TRANSLIT is inconsistent across systems (and entirely missing on
 * some OpenWrt/Alpine builds), so Czech letters map explicitly -
 * "Verejny prehled" -> "verejny prehled" (Czech letters mapped explicitly).
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
 * Enriching a threshold tip with evidence - quality per the user's bar
 * (2026-07-21): "CPU has been above 85 % for 18 minutes. Top consumer:
 * hostapd (61 %). Load average: 2.8/2.4/2.1. Wi-Fi klienti: 27.
 * Recommendation: ...". Built ONLY from actually available data - without
 * top processes the culprit sentence is simply omitted.
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

    // The culprit over the whole period, not just the last minute.
    //
    // The latest snapshot says who loads the machine now. For a state lasting
    // three hours that need not be who caused it - and when the agent just
    // skipped the ranking, there is nobody. Process history (since 14 Aug 2026)
    // can answer for the whole window; without it, the snapshot remains.
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

    // Context by culprit - only when the telemetry actually exists.
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

    // Tip thresholds follow the monitor's settings, not a constant in code.
    //
    // This used to hardcode 80/50 for CPU, 85/60 for memory and 90/70 for disk,
    // while the chart bands draw from monitors.cpu_threshold (default 90)
    // and the Executive Summary computes pressure from the same setting. Anyone
    // who raised their threshold to 95 still got a critical tip at 81 % - three
    // vysoko" v jednom produktu.
    //
    // The warning level sits 15 points below critical, like the chart's warning
    // band. Without a configured threshold the original values remain, so
    // nobody's behaviour shifts without their knowledge.
    $tip_threshold = static function ($configured, float $fallback_crit, float $fallback_warn): array {
        $crit = is_numeric($configured) && (float)$configured > 0 ? (float)$configured : $fallback_crit;
        $warn = $crit === $fallback_crit ? $fallback_warn : max(1.0, $crit - 15);
        return [$crit, $warn];
    };
    // Preset > monitor > default - the same order as the alerts in agent_api.
    $kt_eff_thr = bk_monitor_thresholds($pdo instanceof PDO ? $pdo : null, (array)$monitor);

    // --- VPS / agent (applies to any type with an attached agent, just as
    // render_vps_agent_details() itself is not limited to type=vps) ---
    if (is_array($details)) {
        if (isset($details['cpu'])) {
            $cpu = floatval($details['cpu']);
            [$cpu_crit, $cpu_warn] = $tip_threshold($kt_eff_thr['cpu'], 80.0, 50.0);
            if ($cpu > $cpu_crit) {
                $dur = ($pdo && $monitor) ? bk_metric_duration_above($pdo, $monitor['id'], 'cpu_usage', $cpu_crit) : null;
                $suffix = $dur ? ' (' . bk_format_duration($dur) . ')' : '';
                $add('critical', 'knowledge_tip_cpu_high');
                $tips[count($tips)-1]['text'] .= $suffix . bk_enrich_threshold_tip($details, 'cpu', $pdo, $monitor, $dur);
            } elseif ($cpu > $cpu_warn) $add('warn', 'knowledge_tip_cpu_high');
        }
        if (isset($details['ram'])) {
            $ram = floatval($details['ram']);
            [$ram_crit, $ram_warn] = $tip_threshold($kt_eff_thr['ram'], 85.0, 60.0);
            if ($ram > $ram_crit) {
                $dur = ($pdo && $monitor) ? bk_metric_duration_above($pdo, $monitor['id'], 'ram_usage', $ram_crit) : null;
                $suffix = $dur ? ' (' . bk_format_duration($dur) . ')' : '';
                $add('critical', 'knowledge_tip_ram_high');
                $tips[count($tips)-1]['text'] .= $suffix . bk_enrich_threshold_tip($details, 'ram', $pdo, $monitor, $dur);
            } elseif ($ram > $ram_warn) $add('warn', 'knowledge_tip_ram_high');
        }
        if (isset($details['hdd'])) {
            $hdd = floatval($details['hdd']);
            [$hdd_crit, $hdd_warn] = $tip_threshold($kt_eff_thr['hdd'], 90.0, 70.0);
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

    // --- TeamSpeak Health Score areas - only when the table shows at all ---
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
        $areas = build_teamspeak_health_areas($monitor, $status, $check_stages, $details, $pdo);
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
 * Renders the Knowledge tips panel (see bk_get_knowledge_tips()). Empty array
 * = empty string, no panel shows.
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
 * Shared math for Insights (Level 1 Forecasting) - splits a series sorted
 * by checked_at ASC into an older/newer half, compares the means and
 * returns the rate of change per day. Deterministic, no AI - the same
 * principle for disk/RAM and latency, hence one shared function.
 *
 * @param array $rows Rows with keys $time_key (date) and $value_key (number)
 * @return array{avg_older: float, avg_newer: float, latest: float, rate_per_day: float}|null
 *         null when there is too little data for the extrapolation to make sense.
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
 * Insights v1 (Level 1 Forecasting) - trend math over history we already
 * collect (vps_metrics/monitor_logs, both 30-day retention - see cron.php).
 * Deliberately excludes SSL expiry (already covered by knowledge_tip_ssl_expiring
 * in bk_get_knowledge_tips() - reporting the same thing twice would only annoy)
 * and the Knowledge panel merge (see the plan - a separate decision once
 * more insight types exist).
 */
/**
 * Days until a growing metric reaches 100 %, per metric key.
 *
 * The same computation the forecast insight prints as a sentence, returned as
 * a number so a chart can carry the "full in X days" badge. Keys are the SPA's
 * metric keys ('hdd', 'ram'); a metric that is flat, shrinking, already full or
 * more than 90 days out is absent rather than present with a made-up value.
 *
 * @return array<string, int>
 */
function bk_days_to_full(PDO $pdo, int $monitor_id): array {
    $out = [];
    try {
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
        $rows = $stmt->fetchAll();

        // Same fallback as the insight: without it a fresh install would show
        // no forecast at all and the disk would look like it never grows.
        if (count($rows) < 5) {
            $stmt_raw = $pdo->prepare("
                SELECT checked_at, hdd_usage, ram_usage
                FROM vps_metrics
                WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ORDER BY checked_at ASC
            ");
            $stmt_raw->execute([$monitor_id]);
            $rows = $stmt_raw->fetchAll();
        }

        foreach (['hdd_usage' => 'hdd', 'ram_usage' => 'ram'] as $column => $metric_key) {
            $rate_info = bk_half_window_rate($rows, $column);
            if ($rate_info === null || $rate_info['rate_per_day'] <= 0.01) {
                continue;
            }
            $days = (100 - $rate_info['latest']) / $rate_info['rate_per_day'];
            // Under a day is not a forecast anyone can act on, and rounding it
            // would print "full in 0 days" - a date that has already passed.
            if ($days < 1 || $days > 90) {
                continue;
            }
            $out[$metric_key] = (int)round($days);
        }
    } catch (Throwable $e) {
        error_log('[bk_days_to_full] ' . $e->getMessage());
    }
    return $out;
}

function bk_get_forecast_insights($pdo, $monitor) {
    $insights = [];
    $monitor_id = $monitor['id'];

    // --- Disk / RAM growth forecast ---
    //
    // Daily aggregates instead of raw measurements: this is about growth trend,
    // not variance, so the daily mean is the better extrapolation input (less
    // noise) and above all 14 rows instead of twenty thousand. Columns keep
    // their old names so bk_half_window_rate() stays untouched.
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

    // Fallback for when the daily rollup has not run yet (fresh install, the
    // first half hour after deploy) or got stuck. Without it the forecast would
    // silently vanish and the disk would appear not to grow - worse than a slow query.
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

    // The same rule as bk_days_to_full(), which the chart badge reads: a
    // forecast under a day or beyond 90 is not one. Two copies of this
    // arithmetic would eventually disagree, and the sentence and the badge
    // would contradict each other on the same page.
    foreach (['hdd_usage' => 'insight_forecast_disk', 'ram_usage' => 'insight_forecast_ram'] as $metric_key => $tip_key) {
        $rate_info = bk_half_window_rate($metrics_rows, $metric_key);
        if ($rate_info === null || $rate_info['rate_per_day'] <= 0.01) {
            continue; // Ploché nebo klesající - není co predikovat
        }
        $days_until_full = (100 - $rate_info['latest']) / $rate_info['rate_per_day'];
        if ($days_until_full < 1 || $days_until_full > 90) {
            continue; // Do dne se nedá nic naplánovat, přes 90 dní nestojí za varování
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
    // The daily latency average is already stored for long-term SLA, so
    // fourteen days of individual checks need not be dragged in here.
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
 * Renders the Insights panel (see bk_get_forecast_insights()). Same shape
 * as render_knowledge_panel() - empty array = empty string.
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
 * Insights v2 (Level 2 Anomaly Detection) - shared math. Unlike Knowledge
 * tips (a fixed threshold shared by all monitors), this asks whether the
 * current value is unusual RELATIVE TO this monitor's OWN history -
 * a server that routinely runs at 85 % CPU never triggers here, even though
 * the fixed Knowledge threshold (>80 %) would report "high" nonstop.
 *
 * @param array $baseline_values Numeric values from the "calm" period (excluding the last few days)
 * @param float $current The current (latest) value, outside the baseline window
 * @param float $min_sigma Floor for the effective sigma - guards against false
 *        alarms on a monitor with a suspiciously flat history (sigma near 0)
 * @param float $sigma_multiplier How many effective sigmas from the mean count as "unusual"
 * @return array{low: float, high: float, mean: float, current: float}|null
 *         null = insufficient data, or the value is normal
 */
/**
 * Same as bk_compute_baseline_anomaly(), but from statistics computed in SQL.
 *
 * The original pulled all 30 days of measurements into PHP for the mean and
 * deviation - over 43,000 rows per monitor for an agent reporting every
 * minute. The status page did that per monitor, and it was the largest
 * share of the fifteen seconds the page took to assemble.
 *
 * AVG and STDDEV_POP compute exactly what the loop used to (population
 * variance, divides by n), so the threshold moved nowhere.
 *
 * @param ?float $mean  Baseline mean; NULL = insufficient data
 * @param ?float $sigma Population standard deviation
 * @param ?int   $count Number of values behind the statistic.
 *                      NULL = the query returned nothing, which is not the same as zero samples.
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
 * Network Insights - rolling-window analysis of network data for OpenWrt/VPS monitors.
 * Returns insights in the same format as bk_get_anomaly_insights().
 */
/**
 * Whether the LTE backup of a router can actually carry traffic.
 *
 * `lte_up` alone is not evidence: on a HiLink modem the OpenWrt interface is
 * a DHCP lease from the modem's own LAN side, handed out with no SIM inserted
 * and with a wrong PIN alike. The router reported nine days of "LTE running"
 * while the modem could not have registered to any network. The verdict
 * therefore rests on what the modem itself says (agent 0.1.0+):
 *
 *   ok = true   registered to the network (ConnectionStatus 901) and the SIM
 *               is not in a blocking state
 *   ok = false  SIM missing / waiting for PIN or PUK / invalid, or the modem
 *               reports itself disconnected, or the interface is down
 *   ok = null   nothing to judge by - no LTE at all, or an interface that is
 *               up but a modem that reports nothing (older agent, non-HiLink
 *               modem). Unknown is reported as unknown, never as working.
 *
 * `text` is the operator-facing reason in the same language as the other
 * agent alerts (Czech), `reason` a stable key the UI translates itself.
 *
 * @return array{ok: bool|null, reason: string|null, text: string|null}
 */
/**
 * Whether a router's primary link (WAN) carries traffic.
 *
 * Two signals from the OpenWrt agent: the interface state (`wan_up`, all
 * versions) and one ICMP echo bound to the WAN device (`wan_internet`,
 * agent 0.1.1+). Either one false means the primary link is dead - while
 * the router itself may still report through the LTE backup, which is
 * exactly when nobody would notice otherwise. An older agent that does not
 * measure reachability is judged on the interface alone; no signal at all
 * is no verdict.
 *
 * Mirrors wanLinkState() in apps/monitor/src/lib/wan-link.ts.
 *
 * @return array{ok: bool|null, reason: string|null, text: string|null}
 */
/**
 * PagerDuty event for a notification status: 'trigger' for something that
 * needs a human now, 'resolve' for its recovery, null for everything that
 * belongs on other channels (warnings, maintenance, an expiring certificate).
 */
/**
 * Severity class of a notification status: 'good' for a recovery, 'warn' for
 * something to look at, 'bad' for an outage. The e-mail and the Discord embed
 * read the same classification - Discord used to paint everything but "up"
 * red, so a recovered WAN and an expiring certificate looked like outages.
 */
function bk_alert_color_class(string $status): string {
    if (in_array($status, ['up', 'wan_restored', 'lte_backup_restored', 'latency_recovered', 'storage_recovered',
        // Router recoveries of alert sheet 2.2 - a restored link, firewall or
        // resolver is good news and must not arrive painted like an outage.
        'wan_link_restored', 'firewall_restored', 'dns_resolver_restored'], true)) {
        return 'good';
    }
    if (in_array($status, ['maintenance', 'vps_warning', 'latency_degraded', 'ssl_expiring', 'config_change', 'storage_warning',
        // The router keeps working through all four: a slower WAN port, a full
        // connection table, missing firewall rules and a silent local resolver
        // are things to look at, not outages of the monitor itself.
        'wan_link_degraded', 'conntrack_full', 'firewall_disabled', 'dns_resolver_failed'], true)) {
        return 'warn';
    }
    return 'bad';
}

/**
 * PagerDuty pairs a resolve with its trigger by the dedup key, and folds two
 * triggers under one key into ONE incident. Disks therefore need a key of
 * their own: with the shared per-monitor key a WAN flap's `wan_restored` would
 * resolve the page for a failing disk, and a disk page would swallow the
 * outage that follows.
 */
function bk_pagerduty_dedup_key(int $monitor_id, string $status): string {
    $base = 'bk-monitor-' . $monitor_id;
    return in_array($status, ['storage_failing', 'storage_warning', 'storage_recovered'], true)
        ? $base . '-storage'
        : $base;
}

/**
 * Whether a status change is worth telling humans about. The single silent
 * case is the end of a planned window with no incident open: the window was
 * announced, so "back online" is noise. If an incident IS open the outage
 * started before the window - that transition is a real recovery and must go
 * through the normal path, which is also what closes the incident.
 */
function bk_should_notify_status_change(string $old_status, string $new_status, bool $has_open_incident): bool {
    if ($old_status === 'maintenance' && $new_status === 'up') {
        return $has_open_incident;
    }
    return true;
}

/**
 * Whether the monitor has an unresolved incident (auto-opened by an outage or
 * created by hand).
 */
function bk_has_open_incident(PDO $pdo, int $monitor_id): bool {
    if ($monitor_id <= 0) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM incidents WHERE monitor_id = ? AND status != 'resolved' LIMIT 1");
        $stmt->execute([$monitor_id]);
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        // No incidents table (an old install) - then there is nothing to close.
        return false;
    }
}

/**
 * Whether a failing check may declare the monitor down yet.
 *
 * One failed check is one failed check: a retried connection, a slow DNS
 * answer, a router that dropped a packet. Alerting on the first one is how a
 * monitoring system teaches people to ignore it. With the confirmation set to
 * N, the state flips only after N consecutive failures - the log still records
 * every one of them, so nothing is hidden, only the verdict waits.
 *
 * @param int $consecutive Failures in a row INCLUDING the one just measured.
 * @param int $required How many are needed; 1 keeps the old behaviour.
 */
function bk_down_is_confirmed(int $consecutive, int $required): bool {
    return $consecutive >= max(1, $required);
}

function bk_pagerduty_action(string $status): ?string {
    if (in_array($status, ['down', 'agent_offline', 'wan_lost', 'lte_backup_lost', 'storage_failing'], true)) {
        return 'trigger';
    }
    if (in_array($status, ['up', 'wan_restored', 'lte_backup_restored'], true)) {
        return 'resolve';
    }
    // storage_recovered deliberately does NOT resolve, even under its own key:
    // the status is shared by disk_temp_normal and fs_freed (warnings that
    // never paged) and by the OTHER disks of the same router, so an automatic
    // resolve could close the page of a disk that is still failing. Repeated
    // storage_failing triggers fold into the one open incident; the on-call
    // closes it once the disk is replaced.
    return null;
}

/**
 * Whether an SSL-expiry warning is due: inside the alert window, and not
 * warned about within the last day. Pure, so cron's behaviour can be tested
 * without a certificate. The threshold is the ssl_alert_days setting - a
 * second code path used to hardcode 14 days and a first one warned on every
 * single run, labelled as "back online".
 */
function bk_ssl_alert_due(int $days_remaining, int $threshold_days, int $last_warn_ts, int $now): bool {
    if ($days_remaining < 0 || $days_remaining > $threshold_days) {
        return false;
    }
    return ($now - $last_warn_ts) > 86400;
}

/**
 * A maintenance window whose end has passed. The flag used to stay on
 * forever after the window - the list kept saying "maintenance" and the
 * public page reasoned about a window in the past.
 */
function bk_maintenance_window_expired(array $monitor, int $now): bool {
    if ((int)($monitor['maintenance'] ?? 0) !== 1 || empty($monitor['maintenance_end'])) {
        return false;
    }
    $end = strtotime((string)$monitor['maintenance_end']);
    return $end !== false && $now > $end;
}

function bk_wan_link_state(array $d): array {
    $up = array_key_exists('wan_up', $d) && is_bool($d['wan_up']) ? $d['wan_up'] : null;
    $internet = array_key_exists('wan_internet', $d) && is_bool($d['wan_internet']) ? $d['wan_internet'] : null;
    $proto = array_key_exists('wan_proto', $d) && is_string($d['wan_proto']) && $d['wan_proto'] !== '' ? $d['wan_proto'] : null;

    if ($up === null && $internet === null) {
        return ['ok' => null, 'reason' => null, 'text' => null];
    }
    // Agents before 0.1.1 sent wan_up=false for "no netifd interface called
    // wan at all" (access points, uplink on wwan). No protocol and no echo
    // alongside it means nothing was measured - no verdict, no alert.
    if ($up === false && $internet === null && $proto === null) {
        return ['ok' => null, 'reason' => null, 'text' => null];
    }
    if ($up === false) {
        return ['ok' => false, 'reason' => 'interface_down', 'text' => 'Primární připojení (WAN) je vypnuté nebo bez linky.'];
    }
    if ($internet === false) {
        return ['ok' => false, 'reason' => 'no_internet', 'text' => 'Primární připojení (WAN) je nahoře, ale ven přes něj neprojde ani ping (1.1.1.1 / 9.9.9.9).'];
    }
    return ['ok' => true, 'reason' => null, 'text' => null];
}

function bk_lte_backup_state(array $d): array {
    $up = array_key_exists('lte_up', $d) ? $d['lte_up'] : null;
    $connected = array_key_exists('lte_connected', $d) ? $d['lte_connected'] : null;
    $sim = array_key_exists('lte_sim_state', $d) ? $d['lte_sim_state'] : null;
    $pin_left = array_key_exists('lte_sim_pin_left', $d) ? $d['lte_sim_pin_left'] : null;
    $conn_code = array_key_exists('lte_conn_code', $d) ? $d['lte_conn_code'] : null;
    $sim_status = array_key_exists('lte_sim_status_code', $d) ? $d['lte_sim_status_code'] : null;

    if ($up === null && $connected === null && $sim === null) {
        return ['ok' => null, 'reason' => null, 'text' => null];
    }
    if ($up === false) {
        return ['ok' => false, 'reason' => 'interface_down', 'text' => 'Rozhraní LTE je vypnuté.'];
    }
    switch ($sim) {
        case 'no_sim':
            return ['ok' => false, 'reason' => 'no_sim', 'text' => 'SIM karta nenalezena - modem hlásí, že není vložená nebo je neplatná.'];
        case 'pin_required':
            $attempts = is_numeric($pin_left) ? " (zbývá pokusů: {$pin_left})" : '';
            return ['ok' => false, 'reason' => 'pin_required', 'text' => "SIM karta čeká na PIN - bez něj se modem do sítě nepřihlásí{$attempts}."];
        case 'puk_required':
            return ['ok' => false, 'reason' => 'puk_required', 'text' => 'SIM karta je zablokovaná a čeká na PUK.'];
        case 'invalid':
            // SimStatus 2/3/4: the network rejects the SIM - deactivated or blocked
            // by the operator - while the PIN check reports it "ready".
            $code = is_numeric($sim_status) ? " (SimStatus {$sim_status})" : '';
            return ['ok' => false, 'reason' => 'invalid', 'text' => "SIM kartu síť nepřijímá{$code} - bývá deaktivovaná nebo zablokovaná operátorem."];
    }
    if ($connected === false) {
        $code = is_numeric($conn_code) ? " (stav {$conn_code})" : '';
        return ['ok' => false, 'reason' => 'not_connected', 'text' => "Modem není přihlášen do mobilní sítě{$code}."];
    }
    if ($connected === true) {
        return ['ok' => true, 'reason' => null, 'text' => null];
    }
    // Interface up, modem silent about registration: not a verdict.
    return ['ok' => null, 'reason' => null, 'text' => null];
}

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
            // A radio that reports no noise floor (agent 0.1.7 sends null where
            // iwinfo prints "unknown") used to be read as a measured -95 dBm,
            // which is a clean band nobody measured. Skipped instead.
            $noise = isset($radio['noise']) && is_numeric($radio['noise']) ? (int)$radio['noise'] : null;
            if ($noise !== null && $noise < 0 && $noise > -70) {
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

    // Wi-Fi channel utilisation (busy/active from iwinfo survey, collected since v1.5.4)
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

    // OOM killer interventions. G31: the counter only resets at the next
    // reboot, so one incident kept this warning on the page for weeks and
    // people learned to scroll past it. The `oom_kill` event's timestamp says
    // when the counter last GREW; without one nothing recent is claimed.
    $oom_at = bk_ranged_int($details['oom_kill_at'] ?? null, 1, 4102444800);
    if (isset($details['oom_kills']) && (int)$details['oom_kills'] > 0
        && $oom_at !== null && (time() - $oom_at) <= 86400) {
        $insights[] = [
            'type' => 'network',
            'icon' => 'fa-skull-crossbones',
            'color' => 'var(--color-red)',
            'text' => sprintf(t('net_insight_oom'), (int)$details['oom_kills']),
            'detail' => t('net_insight_oom_detail'),
        ];
    }

    // Slow DNS answers (a measured query, collected since v1.5.4/1.7.2).
    // Not judged while the router's own speed test runs (X17): a lookup made
    // on a saturated line is slow because of the test, and the value is stored
    // and charted either way - it is only not turned into advice.
    if (isset($details['dns_latency_ms']) && $details['dns_latency_ms'] !== null
        && ($details['speedtest_active'] ?? null) !== true) {
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

    // Error rate in the system log
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

    // Real WAN reconnects (the 'wan_reconnected' event is logged by agent_api
    // from the agent's counter delta - more precise than whole-monitor drops above)
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

    // Unstable IPv6 prefix (agent_api logs the event on a /64 change)
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

    // LTE backup that cannot carry traffic (no SIM, PIN, not registered).
    $lte_backup = bk_lte_backup_state($details);
    if ($lte_backup['ok'] === false) {
        $reason_keys = [
            'no_sim' => 'net_insight_lte_reason_no_sim',
            'pin_required' => 'net_insight_lte_reason_pin_required',
            'puk_required' => 'net_insight_lte_reason_puk_required',
            'invalid' => 'net_insight_lte_reason_invalid',
            'not_connected' => 'net_insight_lte_reason_not_connected',
            'interface_down' => 'net_insight_lte_reason_interface_down',
        ];
        $insights[] = [
            'type' => 'network',
            'icon' => 'fa-sim-card',
            'color' => 'var(--color-red)',
            'text' => sprintf(t('net_insight_lte_backup'), t($reason_keys[$lte_backup['reason']] ?? 'net_insight_lte_reason_not_connected')),
            'detail' => t('net_insight_lte_backup_detail'),
        ];
    }

    // Primary link that cannot carry traffic. The router keeps reporting
    // through the LTE backup, so this insight (and the wan_lost alert) is the
    // only place anybody would notice.
    $wan_link = bk_wan_link_state($details);
    if ($wan_link['ok'] === false) {
        $insights[] = [
            'type' => 'network',
            'icon' => 'fa-ethernet',
            'color' => 'var(--color-red)',
            'text' => t($wan_link['reason'] === 'interface_down' ? 'net_insight_wan_down' : 'net_insight_wan_no_internet'),
            'detail' => t('net_insight_wan_detail'),
        ];
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
 * Insights v2 (Level 2 Anomaly Detection) - three rules (CPU/RAM/latency),
 * all over bk_compute_baseline_anomaly(). The baseline window is 3-30 days
 * back (a gap before "now", so an ongoing anomaly does not skew its own
 * baseline), the current value is the latest real sample outside that window.
 */
function bk_get_anomaly_insights($pdo, $monitor) {
    $insights = [];
    $monitor_id = $monitor['id'];

    // --- CPU / RAM anomalies (vps_metrics) ---
    //
    // The database computes the mean and deviation. All 30 days of measurements
    // used to be loaded here - over 43,000 rows per monitor for an agent
    // reporting every minute, and per monitor on the page at that. STDDEV_POP
    // is the population deviation, exactly what the loop computed; the threshold moved nowhere.
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

    // The latest measurement within the last three days - the value compared
    // against the baseline.
    $stmt_last = $pdo->prepare("
        SELECT cpu_usage, ram_usage
        FROM vps_metrics
        WHERE monitor_id = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt_last->execute([$monitor_id]);
    $latest = $stmt_last->fetch();

    // X17 / WAN 3.3: while the router's own speed test runs, the newest
    // minute is the test. Comparing it with a 30-day baseline would report
    // the measurement itself as an anomaly, so only the CURRENT-sample half
    // is skipped - the baseline query is untouched (two minutes a week cannot
    // move a mean) and the chart still shows what happened.
    $anom_details = json_decode((string)($monitor['last_details'] ?? '{}'), true);
    if (is_array($anom_details) && ($anom_details['speedtest_active'] ?? null) === true) {
        $latest = false;
    }

    if ($latest) {
        $to_float = fn($v) => ($v === null || $v === '') ? null : (float)$v;

        // An unmeasured value used to be cast to 0.0 and came out as an anomaly
        // against the baseline - we reported "CPU is 0 %" for an agent that
        // never sent CPU at all.
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

    // --- Latency anomalies (monitor_logs) ---
    // Same reason as CPU/RAM above: the statistic is computed in the database,
    // not by shipping thirty days of checks into PHP.
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
 * Merges monitor_events (add/remove, DNS/cert/schema, agent connect/
 * disconnect, limits, config changes...), agent_actions (Remote Actions
 * history) and status transitions derived from monitor_logs into one
 * chronological list (newest first). A pure data function - day grouping
 * labels ("Today"/"Yesterday") and i18n labels belong to the template, so
 * this stays testable without t()/the current date.
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
        // Table/column missing (old install before migration) - the timeline will just be partial
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

    // The database finds the status changes, not PHP.
    //
    // 30 days of ALL checks used to be loaded here - over 43,000 rows for a
    // monitor checked every minute - and a loop picked the few where the
    // status changed. The status page did that per monitor and it was the
    // single largest transfer on the whole page.
    //
    // LAG() returns just the transitions directly. Older MariaDB/MySQL without
    // window functions throws and the original path is used - hence the catch.
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
            // With the window function $rows holds only transitions, so the
            // condition always fires; without it everything is walked as before.
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
 * Asset-level Timeline - merges events from all monitors under the asset.
 * Each event additionally carries monitor_name to identify the source.
 */
function bk_get_asset_timeline($pdo, $asset_id, $days = 30) {
    $timeline = [];

    // Fetch all the asset's monitors
    $stmt = $pdo->prepare("SELECT id, name FROM monitors WHERE asset_id = ? AND archived_at IS NULL");
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
                'monitor_id' => (int)$row['monitor_id'],
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
                'monitor_id' => (int)$row['monitor_id'],
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
                    'monitor_id' => (int)$mid,
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
 * Assembles a short monitor summary (1-2 sentences: overall state + the most
 * severe current problem, if any) from already-existing data - health score,
 * Knowledge tips, Insights (forecast/anomaly). Deliberately repeats nothing
 * already visible in the Overview tab (Server Information) or the Timeline tab.
 * A purely deterministic template assembly (t() + sprintf), no AI calls -
 * the same philosophy as the rest of the Insights engine.
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
    // A missing threshold is null, not zero.
    //
    // `?? 0` would mean a zero-percent threshold, i.e. everything is always
    // above it. It would get filtered right out again here, but it is exactly
    // the notation that bred invented values elsewhere - hence null outright
    // and an explicit check.
    $threshold_of = static function ($raw): ?float {
        return is_numeric($raw) && (float)$raw > 0 ? (float)$raw : null;
    };

    $eff_thr = bk_monitor_thresholds($pdo, $monitor);
    $watched = [
        ['cpu', 'cpu_usage', $threshold_of($eff_thr['cpu']), 'cpu'],
        ['ram', 'ram_usage', $threshold_of($eff_thr['ram']), 'ram'],
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

        // The context that makes the recommendation make sense. Only what the
        // agent really sent is printed - a missing value is silently omitted
        // rather than letting a zero into the sentence.
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

    // 1. Overall state
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

    // 2. The most severe current problem (critical tip > warn tip > insight)
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
    // Pressure is computed before the "no problems" sentence is decided.
    //
    // Added after it, the summary would contradict itself in one breath:
    // "No current problems detected. CPU has been at 91 % for 18 minutes."
    // Caught on a demo with real data, not by reasoning.
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

    // A "latest event" and "data age" sentence used to live here too - removed,
    // it duplicated the Server Information section in the Overview tab (Last
    // check / Last status change) and the Timeline tab, which has the same
    // event in full context. The summary keeps only what is visible nowhere else.
    return implode(' ', $sentences);
}

/**
 * A coarse relative time label ("today", "yesterday", "N days ago") - shared
 * between the Executive Summary and the Timeline so both speak the same language.
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
 * Runs $builder() with t() temporarily switched to $lang, regardless of what
 * language (if any) the current request/cookie has - e-mails have no visitor,
 * their language is set solely by the admin's email_lang setting.
 * t() (lang.php) re-reads $GLOBALS['BK_LANG']/['BK_STRINGS'] on every call,
 * so temporarily swapping those two globals around $builder() suffices -
 * no object/singleton refactoring needed. Safe from the CLI too (cron.php)
 * - without $_GET/$_COOKIE lang.php just stays at the default 'cs' until this
 * function switches it, and setcookie() without HTTP headers is a silent no-op.
 */
function bk_with_email_lang(string $lang, callable $builder) {
    require_once __DIR__ . '/lang.php';
    $saved_lang = $GLOBALS['BK_LANG'] ?? null;
    $saved_strings = $GLOBALS['BK_STRINGS'] ?? null;
    $saved_fallback = $GLOBALS['BK_STRINGS_CS_FALLBACK'] ?? null;

    $lang = in_array($lang, ['cs', 'en'], true) ? $lang : 'cs';
    $GLOBALS['BK_LANG'] = $lang;
    $GLOBALS['BK_STRINGS'] = require __DIR__ . "/lang/{$lang}.php";
    // lang.php itself sets CS_FALLBACK only when BK_LANG !== 'cs' (see there) -
    // the same condition here, so a missing key never hits a bare t()['key'] warning.
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
 * Registry of metrics available on the Level 3 Metric Detail page (index.php
 * ?view=metric). One source of truth for the key->column mapping, shared by
 * api.php (the vps_metrics query) and the page render (labels/units/Related
 * Metrics links) - see project_dashboard_ia_redesign.md in memory.
 */
function bk_get_metric_registry() {
    return [
        'cpu' => ['column' => 'cpu_usage', 'label_key' => 'metric_label_cpu', 'unit' => '%'],
        'ram' => ['column' => 'ram_usage', 'label_key' => 'metric_label_ram', 'unit' => '%'],
        'hdd' => ['column' => 'hdd_usage', 'label_key' => 'metric_label_hdd', 'unit' => '%'],
        'net' => ['column' => 'net_usage', 'label_key' => 'metric_label_net', 'unit' => 'KB/s'],
        'net_lte' => ['column' => 'net_lte_kbps', 'label_key' => 'metric_label_net_lte', 'unit' => 'KB/s'],
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
 * Reaches into vps_metrics for one metric (a column from bk_get_metric_registry())
 * over the period and returns raw points [timestamp, value, peak]. Shared by
 * api.php (chart JSON) and render_metric_detail_page() (the stat-card number on
 * first render) - one SQL logic, not two copies.
 * $column must come from bk_get_metric_registry(), never straight from $_GET.
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
 * Current/average/peak/trend for one metric - $points is the output of
 * bk_fetch_metric_series() ([timestamp, value, peak] rows, chronologically
 * ascending). The trend uses the same technique as bk_half_window_rate()
 * (older/newer half of the window), only returning a percentage change instead
 * of a rate per day - the stat card wants "how much % different", not a projection.
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
        // Growth from zero has no meaningful percentage - the earlier "+100 %"
        // sentinel looked like a computed trend; null lets the UI just omit it.
    }

    return ['current' => round($current, 1), 'average' => $average, 'peak' => $peak, 'trend_pct' => $trend_pct];
}

/**
 * Level 3 Metric Detail page (index.php?view=metric&monitor=X&metric=Y) - its
 * own standalone HTML page (not a panel tab), because it needs an addressable
 * URL for breadcrumbs and Related Metrics links. It ends the request itself
 * (exit); the caller (index.php) only hands it $pdo/$monitor/
 * $metric_key/$is_admin and renders nothing after it.
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

    // Related Metrics - only those this monitor actually reports data for
    // (latest vps_metrics row), so nobody clicks through into a void.
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

    // The "why" layer - the last notable event over the same window as the chart (24h),
    // the same data source as the Timeline (Phase 1) and the Executive Summary.
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

    // Alert Regions - threshold bands for metrics with a configurable threshold
    $warn_threshold = null;
    $crit_threshold = null;
    $threshold_map = ['cpu' => 'cpu', 'ram' => 'ram', 'hdd' => 'hdd'];
    if (isset($threshold_map[$metric_key])) {
        $eff_band = bk_monitor_thresholds($pdo, $monitor)[$threshold_map[$metric_key]];
        if ($eff_band !== null && $eff_band > 0) {
            $crit_threshold = (float)$eff_band;
            $warn_threshold = max(0, $crit_threshold - 15); // Warning zone 15% pod critical
        }
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
            // Notes are fetched separately and mixed among events the chart
            // already draws as pins. If they fail to load, the chart still
            // renders - a note is no reason to withhold the data.
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
 * Returns info about the current application version.
 * In production it reads the version.php file generated by the GitHub Actions deploy.
 * Locally (dev) it falls back to git log.
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

    // Local development: fallback via git log (not called in production)
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
 * Extracts TLS certificate info (issuer, CN, SAN, validity) over its own
 * separate connection - deliberately not sharing the handle with the main HTTP
 * check, so this (purely informative) stage cannot affect check_http() behaviour/timing.
 * Returns null on any failure (non-https target, timeout, parse error).
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

    // Breakdown of the check into stages (DNS/TCP/TLS/HTTP/body) for the
    // diagnostic "check pipeline" on the monitor detail. The verdict itself
    // comes from bk_http_verdict(): the HTTP code and, when the monitor has
    // one, the expected text in the body.
    $check_stages = [
        'dns' => [
            'ok' => $host ? ($has_ipv4 || $has_ipv6) : false,
            'time_ms' => $dns_time_ms,
            'records' => $dns_records,
        ],
    ];

    // Determine whether cURL is available
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

        // TLS certificate only for successfully established https connections - for
        // an unreachable host a separate SSL attempt would only double the timeout wait.
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

        if (is_string($body_keyword) && trim($body_keyword) !== '') {
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

        $verdict = bk_http_verdict((int)$http_code, $response, $body_keyword);
        return array_merge([
            'status' => $verdict['status'],
            'response_time' => $duration,
            'error' => $verdict['error'],
            'check_stages' => $check_stages
        ], $conn_details);
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
        
        // Extract the HTTP code from the headers
        $http_code = 200;
        $resp_headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        if (!empty($resp_headers[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $resp_headers[0], $matches);
            if (isset($matches[1])) {
                $http_code = (int)$matches[1];
            }
        }
        
        $verdict = bk_http_verdict($http_code, $response, $body_keyword);
        return array_merge([
            'status' => $verdict['status'],
            'response_time' => $duration,
            'error' => $verdict['error']
        ], $conn_details);
    }
}

/**
 * The verdict of a web check: does the site work for its visitors?
 *
 * A 2xx/3xx answer used to be the whole verdict. The body keyword was measured
 * for the diagnostic pipeline and then ignored, so a hosting "account
 * suspended" page, a parked domain or a blank page after a PHP fatal - all
 * served with 200 - stayed green, while the monitor form promised that a
 * missing keyword counts as an outage. The keyword is compared as the exact
 * string the admin typed.
 *
 * @param int $http_code Final status code after redirects (0 = no answer).
 * @param string|false|null $body Response body; false or null when none was read.
 * @param mixed $body_keyword Expected text; null or blank means not checked.
 * @return array{status: string, error: ?string}
 */
function bk_http_verdict(int $http_code, $body, $body_keyword): array {
    if ($http_code < 200 || $http_code >= 400) {
        return ['status' => 'down', 'error' => 'HTTP status kód: ' . $http_code];
    }
    if (is_string($body_keyword) && trim($body_keyword) !== ''
        && (!is_string($body) || strpos($body, $body_keyword) === false)) {
        return [
            'status' => 'down',
            'error' => 'Stránka odpověděla HTTP ' . $http_code . ', ale neobsahuje očekávaný text „' . $body_keyword . '“',
        ];
    }
    return ['status' => 'up', 'error' => null];
}

/**
 * Does fresh evidence from the agent on the same machine show the service
 * running, although the check from the hosting failed?
 *
 * The fallback exists for game servers the hosting cannot reach directly
 * (an outbound firewall on shared hosting): the agent sees the port listening
 * or the process running. Three rules used to turn real outages green:
 * - a web monitor counted as up whenever the agent listed port 80 or 443, so
 *   nginx in front of a dead PHP-FPM or database answered every visitor with
 *   a 502 while the monitor stayed up - a site that serves no pages is down;
 * - agent data as old as the silence timeout (50 minutes by default) was
 *   trusted, so a machine that went down with its agent stayed up that long.
 *   Eleven minutes still covers the slowest agent - the Docker loop reports
 *   every 300 s - with one report missed;
 * - any monitored process counted, so a running nginx vouched for TeamSpeak.
 *
 * @param string $type Monitor type.
 * @param array $agent The monitor's last_details as the agent wrote them.
 * @param array $monitor The monitor row (target, port, monitored_processes).
 * @param int $now Unix time.
 * @param int $max_age_secs How old the agent's report may be to count.
 */
function bk_agent_backup_says_up(string $type, array $agent, array $monitor, int $now, int $max_age_secs = 660): bool {
    $seen = (int)($agent['agent_last_seen'] ?? 0);
    if ($seen <= 0 || $now - $seen > $max_age_secs) {
        return false;
    }

    if ($type === 'teamspeak') {
        $parts = explode(':', (string)($monitor['target'] ?? ''));
        $service_ports = [count($parts) === 2 ? (int)$parts[1] : 9987, (int)($monitor['port'] ?? 0) ?: 10011];
        $process_pattern = '/ts3|teamspeak/i';
    } elseif ($type === 'minecraft') {
        $service_ports = [(int)($monitor['port'] ?? 0) ?: 25565];
        $process_pattern = '/minecraft|java/i';
    } else {
        return false;
    }

    $listening = array_map('intval', is_array($agent['ports'] ?? null) ? $agent['ports'] : []);
    if (array_intersect($service_ports, $listening)) {
        return true;
    }

    $monitored = array_filter(array_map('trim', explode(',', (string)($monitor['monitored_processes'] ?? ''))));
    $service_processes = array_filter($monitored, fn($p) => preg_match($process_pattern, $p) === 1);
    if (!$service_processes) {
        return false;
    }
    $missing = is_array($agent['missing_processes'] ?? null) ? $agent['missing_processes'] : [];
    return !array_intersect($service_processes, $missing);
}

/**
 * Writes one event into monitor_events (monitor added/removed, schema/DNS/
 * certificate change, agent connect/disconnect, ...) - the light event log
 * that feeds the infrastructure report (weekly/monthly digest).
 */
function log_monitor_event($pdo, $monitor_id, $monitor_name, $monitor_type, $event_type, $description = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO monitor_events (monitor_id, monitor_name, monitor_type, event_type, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$monitor_id, $monitor_name, $monitor_type, $event_type, $description]);
    } catch (PDOException $e) {
        // Do not stop the cron run over an event-logging error
    }
}

/**
 * Compares the current state of a 'web' monitor (schema, DNS, certificate
 * validity) against the last stored snapshot (monitors.config_snapshot) and on
 * change writes an event into monitor_events. The snapshot is then always
 * overwritten with the current values (tick/tock), whether anything changed or not.
 *
 * Deliberately does not track the negotiated TLS protocol version (1.2 vs 1.3) -
 * unlike other languages PHP/cURL does not expose it (only libcurl's internal
 * C API), so it would be a guess, not reliable data.
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

        // Certificate renewed (new validity in the future, later than the old one)
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
 * ICMP ping - returns latency in ms or null on failure.
 * Uses the system `ping` with 1 packet and a 2s timeout.
 */
function bk_ping_host($host, $timeout_ms = 2000) {
    if (empty($host)) return null;
    $host = escapeshellarg($host);
    $timeout_s = max(1, (int)ceil($timeout_ms / 1000));
    // Linux ping: -c 1 packet, -W timeout in seconds
    $cmd = "ping -c 1 -W $timeout_s $host 2>/dev/null";
    $output = @shell_exec($cmd);
    if ($output === null) return null;
    // Parses "time=1.23 ms" or "time=1 ms"
    if (preg_match('/time[=<]\s*([0-9.]+)\s*ms/i', $output, $m)) {
        return round((float)$m[1], 1);
    }
    return null;
}

/**
 * TCP socket check (port check / TCP ping)
 */
function check_socket($host, $port, $timeout = 5) {
    $start = microtime(true);
    // Strip the protocol from the host if one was given
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
 * Minecraft server query via Server List Ping (SLP)
 */
/**
 * Fallback Minecraft query via the public mcsrvstat.us API
 */
/**
 * Source RCON protocol (Valve/Source engine RCON - the same binary protocol
 * is used by Minecraft Paper/Spigot and Source-based games). Independent of
 * the game software - just packet framing (int32 length/id/type +
 * null-terminated body). Auth -> exec command -> read the response.
 *
 * @return string|null The command response text, or null on connection/auth failure.
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

    // Auth response (type 2) - some servers precede it with an empty
    // SERVERDATA_RESPONSE_VALUE packet, so we read until type 2 arrives
    // (or the connection ends).
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
 * TPS via the Paper/Spigot "/tps" command (RCON) - vanilla lacks it.
 * Paper's output format has been stable for years: "TPS from last 1m, 5m,
 * 15m: X, Y, Z" (usually with §-colour codes) - the colour codes are removed
 * (in both possible encodings, so a code digit cannot merge with a real
 * number, e.g. "§220.0" would read as 220.0 untreated) and the first
 * comma-separated triple of numbers is extracted.
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
 * Blood Kings Status - Minecraft SLP check with a single quick retry
 *
 * The short timeout and byte-by-byte response reading make a single attempt
 * prone to ordinary network snags (the server answers a fraction of a second
 * later than the limit allows) - so before falling back to the API and
 * possibly reporting an outage, the connection is tried once more.
 */
function check_minecraft($host, $port = 25565, $timeout = 3, $rcon_port = null, $rcon_password = null) {
    $start = microtime(true);
    $host = preg_replace('~^https?://~', '', $host);

    // Split host and port when given as host:port
    $parts = explode(':', $host);
    if (count($parts) === 2) {
        $host = $parts[0];
        $port = intval($parts[1]);
    }

    $result = check_minecraft_slp_attempt($host, $port, $timeout, $start);
    if ($result === null) {
        // A short pause and a second attempt - catches transient failures/delays
        usleep(300000); // 0.3 s
        $result = check_minecraft_slp_attempt($host, $port, $timeout, $start);
    }
    if ($result !== null) {
        // TPS via RCON is optional (Paper/Spigot only) and must never break
        // the basic SLP check - best-effort, silently skipped when RCON
        // is unconfigured or fails.
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
 * One SLP handshake attempt. Returns null on connection/read failure (the
 * caller then retries or moves to the fallback API), otherwise returns a
 * finished result array (status up or down - down is returned only where the
 * response is clearly valid yet plainly wrong, e.g. an invalid packet ID).
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
        // "Server is down" cannot be told apart from "the byte arrived a fraction
        // late" - let the caller retry before reaching for the fallback API.
        @fclose($socket);
        return null;
    }

    $packetId = $readVarInt($socket);
    if ($packetId !== 0x00) {
        // Here the server genuinely replied, just with another packet ID - a retry
        // would not help, it is a real protocol/port mismatch.
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
    
    // Fetch the player list
    $playersList = [];
    if (isset($data['players']['sample']) && is_array($data['players']['sample'])) {
        foreach ($data['players']['sample'] as $p) {
            if (isset($p['name'])) {
                $playersList[] = $p['name'];
            }
        }
    }
    
    // Fetch and clean the MOTD
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
 * Decodes TS3 ServerQuery escape sequences in a received value (the full table -
 * an earlier version handled only \s, \/, \p, enough for a few serverinfo
 * fields, but channel/client names need the complete set).
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
 * Encodes a value for sending in a ServerQuery command (inverse of bk_ts3_escape_decode) -
 * needed e.g. for a login name/password containing spaces or other characters.
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
 * Sends a ServerQuery command and reads the response until the terminating
 * "error id=..." line appears (or the safety limits run out).
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
 * Extracts the numeric "error id=" from a ServerQuery response. Returns null
 * when missing (the connection died before the terminating line).
 */
function bk_ts3_parse_error_id($response) {
    if (preg_match('/error id=(\d+)/', $response, $m)) {
        return (int)$m[1];
    }
    return null;
}

/**
 * Parses a single-line "key=value key2=value2 ..." response (e.g. serverinfo)
 * into an associative array, with full escape decoding of values.
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
 * Parses a list response (channellist/clientlist/servergrouplist) - records
 * separated by "|", each holding key=value pairs separated by spaces. The
 * terminating "error id=..." line is cut off, not part of the last record.
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
 * Quick TCP check of the ServerQuery and FileTransfer ports. The voice port
 * (default 9987) is UDP and cannot be connect-probed the same way - its state
 * is only derived from a successful serverinfo above, hence 'ok' => null (not independently verified).
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
 * Approximates voice quality from the jitter (standard deviation) of the last
 * hour's ServerQuery TCP latencies. It is NOT a real measurement of voice (UDP)
 * packet loss - that cannot be measured reliably from PHP on shared hosting -
 * just a proxy signal for "how stable the connection to the server has been lately".
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
 * Generic weighted Health Score calculator - type-agnostic, usable for any
 * future Service Profile, not just TeamSpeak. $areas is an array
 * [['label'=>, 'weight_pct'=>, 'score_pct'=>0-100, 'status'=>'ok'|'warn'|'fail'|'na'], ...].
 * Areas with status='na' (unmeasurable - typically no attached agent) are
 * left out of the computation and their weight redistributes proportionally
 * among the measurable areas, instead of padding to 100 % or unfairly dragging the score to 0.
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
 * Builds the 7 weighted Health Score areas for a TeamSpeak monitor:
 * Availability 35 % / Process 20 % / ServerQuery 15 % / Ports 10 % / VPS performance 10 % /
 * Client limits 5 % / Version 5 %. $agent_data is the decoded monitors.last_details
 * (holds cpu/ram and possibly ts3_process when an agent is attached to the VPS),
 * $check_stages is the decoded monitor_logs.check_stages from the last run.
 */
function build_teamspeak_health_areas($monitor, $current_status, $check_stages, $agent_data, $pdo = null) {
    $areas = [];

    // Dostupnost (35 %)
    $avail_ok = $current_status === 'up';
    $areas[] = ['key' => 'availability', 'label' => 'Dostupnost', 'weight_pct' => 35, 'score_pct' => $avail_ok ? 100 : 0, 'status' => $avail_ok ? 'ok' : 'fail'];

    // TeamSpeak process (20 %) - only when an agent is attached and reports ts3_process
    if (is_array($agent_data) && isset($agent_data['ts3_process']) && is_array($agent_data['ts3_process'])) {
        $areas[] = ['key' => 'process', 'label' => 'TeamSpeak proces', 'weight_pct' => 20, 'score_pct' => 100, 'status' => 'ok'];
    } elseif (is_array($agent_data) && !empty($agent_data['cpu'])) {
        // The agent is attached but found no ts3server process
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

    // Ports (10 %) - the voice port does not count into the ratio (not independently verified, ok=null)
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

    // VPS performance (10 %) - only when the agent reports cpu/ram
    if (is_array($agent_data) && isset($agent_data['cpu'], $agent_data['ram'])) {
        $eff_perf = bk_monitor_thresholds($pdo instanceof PDO ? $pdo : null, (array)$monitor);
        $cpu_threshold = (float)($eff_perf['cpu'] ?? 90);
        $ram_threshold = (float)($eff_perf['ram'] ?? 95);
        $cpu_ok = (float)$agent_data['cpu'] < $cpu_threshold;
        $ram_ok = (float)$agent_data['ram'] < $ram_threshold;
        $perf_score = ($cpu_ok && $ram_ok) ? 100 : (($cpu_ok || $ram_ok) ? 60 : 20);
        $areas[] = ['key' => 'vps', 'label' => 'Výkon VPS', 'weight_pct' => 10, 'score_pct' => $perf_score, 'status' => $perf_score >= 100 ? 'ok' : 'warn'];
    } else {
        $areas[] = ['key' => 'vps', 'label' => 'Výkon VPS', 'weight_pct' => 10, 'score_pct' => 0, 'status' => 'na'];
    }

    // Client count / limits (5 %)
    $slot_pct = $check_stages['service']['slot_usage_pct'] ?? null;
    if ($slot_pct !== null) {
        $clients_score = $slot_pct < 90 ? 100 : ($slot_pct < 100 ? 60 : 20);
        $areas[] = ['key' => 'clients', 'label' => 'Klienti / limity', 'weight_pct' => 5, 'score_pct' => $clients_score, 'status' => $clients_score >= 100 ? 'ok' : 'warn'];
    } else {
        $areas[] = ['key' => 'clients', 'label' => 'Klienti / limity', 'weight_pct' => 5, 'score_pct' => 0, 'status' => 'na'];
    }

    // Version (5 %) - only when a "last known version" is filled in manually in settings
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
 * Only 'teamspeak' has a real implementation so far; the registry shape is what
 * turns the one-off TeamSpeak Health Score into a general framework - adding
 * another type (web/minecraft/...) later means one new entry, not a rewrite.
 */
/**
 * Service Profiles registry - defines the label/icon for the visual picker in
 * admin.php per monitor type and (for types that support it) the list of
 * togglable dashboard sections. Types without a 'metrics' key have no checklist
 * in the admin and their dashboard is not gated - everything shows as before
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
 * Returns the list of enabled metric keys for a monitor, or NULL when its type
 * is not gated (a type without 'metrics' in get_service_profiles() - the
 * dashboard behaves as before and shows everything). Callers always check
 * `$enabled_metrics === null || in_array('key', $enabled_metrics)`.
 */
/**
 * Metrics and thresholds from the preset assigned to a monitor.
 *
 * A preset is a named set of "what shows for this service and when it is a
 * problem". A monitor without a preset works as before (the profile's
 * recommended metrics + its own thresholds), so introducing presets breaks nothing.
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
            // Thresholds are optional - a preset may govern only the metric set.
            'cpu' => $row['cpu_threshold'] !== null ? (int)$row['cpu_threshold'] : null,
            'ram' => $row['ram_threshold'] !== null ? (int)$row['ram_threshold'] : null,
            'hdd' => $row['hdd_threshold'] !== null ? (int)$row['hdd_threshold'] : null,
        ];
    } catch (Throwable $e) {
        // Without the table (old DB) the preset simply does not apply.
        return $cache[$preset_id] = null;
    }
}

/**
 * Effective threshold for a metric: the preset beats the monitor's own value.
 *
 * Returns null when neither is set - the caller then applies no threshold
 * instead of inventing a default number.
 */
function bk_effective_threshold(?array $preset, $monitor_value, string $key): ?int {
    if ($preset !== null && $preset[$key] !== null) {
        return $preset[$key];
    }
    return $monitor_value !== null && $monitor_value !== '' ? (int)$monitor_value : null;
}

/**
 * The documented cpu/ram/hdd alert limits in % when neither a preset nor the
 * monitor sets one - the same numbers as the schema column defaults. Two
 * places had RAM and disk swapped (90/95 instead of 95/90), so the health
 * score and the service-discovery import disagreed with the alerts.
 */
const BK_DEFAULT_THRESHOLDS = ['cpu' => 90, 'ram' => 95, 'hdd' => 90];

/**
 * Effective cpu/ram/hdd thresholds for a monitor - preset first, then the
 * monitor's own value, then null. Written together with
 * bk_effective_threshold(), which had tests but no production caller: the
 * preset editor offered thresholds, and nothing anywhere read them.
 */
function bk_monitor_thresholds(?PDO $pdo, array $monitor): array {
    $preset = ($pdo !== null && !empty($monitor['preset_id'])) ? bk_get_preset($pdo, $monitor['preset_id']) : null;
    return [
        'cpu' => bk_effective_threshold($preset, $monitor['cpu_threshold'] ?? null, 'cpu'),
        'ram' => bk_effective_threshold($preset, $monitor['ram_threshold'] ?? null, 'ram'),
        'hdd' => bk_effective_threshold($preset, $monitor['hdd_threshold'] ?? null, 'hdd'),
    ];
}

function bk_get_enabled_metrics($monitor, $pdo = null) {
    $profile = get_service_profiles()[$monitor['type'] ?? ''] ?? null;
    if (!$profile || empty($profile['metrics'])) {
        return null;
    }
    // The preset (when assigned) overrides the set stored on the monitor - that
    // is the whole point of a preset: one change shows everywhere.
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
    // Nothing explicitly stored (a new/unedited monitor) - the recommended
    // defaults are used, which match exactly what has always been displayed.
    return array_column(array_filter($profile['metrics'], fn($m) => !empty($m['recommended'])), 'key');
}

/**
 * TeamSpeak server check via ServerQuery. The basic anonymous sequence
 * (use + serverinfo) is deliberately unchanged from the earlier version - it is
 * a production check running every 1-5 minutes, nothing new may bring it down.
 * The new things (login, channels, server groups, voice activity, ports) are
 * purely additive and their failure (missing permissions, missing
 * credentials) never changes the resulting 'status'.
 */

function check_teamspeak($host, $port = 10011, $timeout = 3, $sq_username = null, $sq_password = null, $filetransfer_port = null) {
    // Splitting the voice port from the query port (e.g. host:voice_port)
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

    // Read the ServerQuery greeting (exactly 2 lines: TS3 and the Welcome message)
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

    // Select the virtual server on the voice port
    @fwrite($socket, "use port=$voice_port\n");
    $use_resp = @fgets($socket, 256);

    if ($use_resp && strpos($use_resp, 'error id=0') === false) {
        // If the given voice port does not exist or is invalid, auto-detect the port via serverlist
        @fwrite($socket, "serverlist\n");
        $s_list = @fgets($socket, 4096);
        if ($s_list && preg_match('/virtualserver_port=(\d+)/', $s_list, $m_port)) {
            $voice_port = (int)$m_port[1];
            @fwrite($socket, "use port=$voice_port\n");
            @fgets($socket, 256);
        }
    }

    // Query the server info (unchanged - this is the baseline up/down rests on)
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
    // ServerQuery reports the real filetransfer port right in serverinfo - use it
    // instead of the manually configured value when available (the server cannot
    // be wrong about itself, unlike a hand-filled field in the admin).
    if (isset($details['virtualserver_filetransfer_port']) && (int)$details['virtualserver_filetransfer_port'] > 0) {
        $filetransfer_port = (int)$details['virtualserver_filetransfer_port'];
    }
    $clients_online = isset($details['virtualserver_clientsonline']) ? (int)$details['virtualserver_clientsonline'] : 0;
    $query_clients = isset($details['virtualserver_queryclientsonline']) ? (int)$details['virtualserver_queryclientsonline'] : 0;
    $clients_max = isset($details['virtualserver_maxclients']) ? (int)$details['virtualserver_maxclients'] : 0;
    $real_clients_online = max(0, $clients_online - $query_clients);

    // --- From here on these are purely additive queries (check pipeline) - none
    // --- of this can bring down the status determined by serverinfo above. ---
    $query_steps = ['serverinfo' => true];
    $authenticated = false;

    if (!empty($sq_username) && !empty($sq_password)) {
        $login_cmd = 'login client_login_name=' . bk_ts3_escape_encode($sq_username)
            . ' client_login_password=' . bk_ts3_escape_encode($sq_password);
        $login_resp = bk_ts3_send_command($socket, $login_cmd);
        $authenticated = (bk_ts3_parse_error_id($login_resp) === 0);
        $query_steps['login'] = $authenticated;
        if ($authenticated) {
            // After login the virtual server must be selected again (ServerQuery requires it)
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
    
    // Walk the members and group them into voice channels
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
    
    // Fill in the channel names
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
 * Sends an e-mail and writes down that it happened.
 *
 * The logging sits HERE and not at the call sites on purpose. It used to be at
 * one of nine of them, so an administrator could answer "did that alert go
 * out?" and nothing else - an invitation, a password reset or a digest left no
 * trace whatsoever. A call site can forget to log; send_email() cannot, and
 * neither can the next feature that starts sending something.
 *
 * $context describes the message for the log:
 *   kind       - one of bk_notification_kinds(), default 'other'
 *   monitor_id - the monitor it is about, when there is one
 *   status     - the monitor event ('down', 'up', ...); defaults to the kind,
 *                because for a password reset the kind IS what happened.
 * The body is never passed on: the log must not become a copy of people's mail.
 *
 * Returns exactly what the delivery returned - the log is bookkeeping and
 * never changes the answer.
 */
function send_email($to, $subject, $html_body, array $extra_headers = [], array $context = []) {
    $ok = bk_deliver_email($to, $subject, $html_body, $extra_headers);

    $kind = (string)($context['kind'] ?? 'other');
    bk_log_notification(
        // The same guard as everywhere else in this file: bookkeeping must
        // never be able to throw a TypeError over a mail that did go out.
        ($GLOBALS['pdo'] ?? null) instanceof PDO ? $GLOBALS['pdo'] : null,
        isset($context['monitor_id']) ? (int)$context['monitor_id'] : null,
        (string)($context['status'] ?? $kind),
        'email',
        is_string($to) ? $to : null,
        (bool)$ok,
        // Only on a failure: a success has nothing to explain, and an error
        // text next to ok=1 would read as "it went out, but...".
        $ok ? null : ($GLOBALS['last_mail_error'] ?? null),
        $kind,
        is_string($subject) ? $subject : null,
        // NULL when nothing confirmed a route - that is what a failed attempt
        // honestly knows, and the row must not claim 'smtp' because SMTP was tried.
        $GLOBALS['last_mail_method'] ?? null
    );

    return $ok;
}

/**
 * Sends an e-mail via PHPMailer (SMTP auth) or PHP mail() as fallback.
 *
 * Private to send_email() - call that one, so the attempt gets logged.
 */
function bk_deliver_email($to, $subject, $html_body, array $extra_headers = []) {
    $GLOBALS['last_mail_error'] = '';
    // 'smtp' = verified delivery through an authenticated SMTP server (a strong
    // success signal), 'fallback' = unauthenticated PHP mail() - returns true even
    // when it only means "the local MTA accepted it for processing", not that it
    // actually arrived. Callers (the digest etc.) use this to calibrate how
    // confidently to word the success message - see send_digest_report_inner().
    $GLOBALS['last_mail_method'] = null;

    $smtp_host = get_setting('smtp_host', '');
    $smtp_port = (int) get_setting('smtp_port', 587);
    $smtp_user = get_setting('smtp_user', '');
    $smtp_pass = get_setting('smtp_pass', '');
    $smtp_secure = get_setting('smtp_secure', 'tls'); // 'tls' = STARTTLS, 'ssl' = SSL
    $site_title = get_setting('site_title', 'Blood Kings Status');
    
    $lib_path = __DIR__ . '/lib/';
    
    // When SMTP credentials are configured and PHPMailer is available, use it
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
            foreach ($extra_headers as $eh_name => $eh_value) {
                $mail->addCustomHeader($eh_name, $eh_value);
            }
            
            $mail->send();
            $GLOBALS['last_mail_method'] = 'smtp';
            return true;
        } catch (Exception $e) {
            $GLOBALS['last_mail_error'] = $mail->ErrorInfo ?? $e->getMessage();
            return false;
        }
    }
    
    // Fallback: PHP mail() without SMTP auth (works only if the hosting allows it)
    // noreply@example.com is deliberately generic - the IANA-reserved documentation
    // domain (RFC 2606), not a guess at the real deployment domain.
    $from = !empty($smtp_user) ? $smtp_user : 'noreply@example.com';
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . $site_title . ' <' . $from . '>',
        'Reply-To: ' . $from,
        ...array_map(fn($k) => $k . ': ' . $extra_headers[$k], array_keys($extra_headers)),
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
 * Sends an SMS via Twilio or SMSbrana.cz
 */
function send_sms($phone, $message, $user_whatsapp_apikey = '', $force_gateway = '') {
    $gateway = !empty($force_gateway) ? $force_gateway : get_setting('sms_gateway_type', '');
    
    if ($gateway === 'whatsapp') {
        // A CallMeBot key is bound to a specific phone number, so it exists
        // only as each user's personal key - no global fallback.
        $apikey = $user_whatsapp_apikey;
        if (empty($apikey) || empty($phone)) {
            return false;
        }
        
        // Clean the phone number for CallMeBot (digits only)
        $clean_phone = preg_replace('/[^0-9]/', '', $phone);
        // If the number lacks an international prefix (has 9 digits), prepend the Czech +420
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
        
        // SMS Brana API SMS send (HTTP GET/POST)
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
 * Helper telling whether a monitor is in planned maintenance
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
 * Runs the notification process on a monitor status change
 */
/**
 * Lifecycle of incidents tied to a monitor.
 *
 * An outage automatically opens an incident (once - while it is open, further
 * down passes of cron add nothing), recovery automatically closes it with a
 * timeline record. Manual steps (acknowledge, notes, postmortem) are done
 * by the admin via the API - this only guarantees no outage vanishes
 * without a record.
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
        // The incident is an auxiliary record - its failure must not stop notifications.
    }
}

/**
 * Records one delivery attempt.
 *
 * Nothing used to write down what was sent, so after an outage "did the alert
 * reach me?" had no answer, and a channel that had been failing for weeks
 * looked exactly like a channel with nothing to report. The row is written
 * whether the attempt succeeded or not - a failure is the interesting half.
 *
 * $subject is the only part of a message ever stored; the body never is, and
 * the recipient is kept as the bare address (no name), because the whole log
 * is personal data that only an administrator gets to see.
 *
 * Never throws: an alert must go out even when its bookkeeping cannot.
 */
function bk_log_notification(
    ?PDO $pdo,
    ?int $monitor_id,
    string $status,
    string $channel,
    ?string $recipient,
    bool $ok,
    ?string $error = null,
    string $kind = 'other',
    ?string $subject = null,
    ?string $method = null
): void {
    if ($pdo === null) {
        return;
    }
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notification_log (monitor_id, status, channel, recipient, ok, error_message, kind, subject, method)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $monitor_id !== null && $monitor_id > 0 ? $monitor_id : null,
            substr($status, 0, 32),
            substr($channel, 0, 24),
            $recipient !== null && $recipient !== '' ? substr($recipient, 0, 190) : null,
            $ok ? 1 : 0,
            $error !== null && $error !== '' ? substr($error, 0, 255) : null,
            // Stored as given, not forced into bk_notification_kinds(): a typo in
            // a future call site must stay visible in the log instead of quietly
            // joining the 'other' pile, where nobody would ever find it.
            substr($kind !== '' ? $kind : 'other', 0, 32),
            $subject !== null && $subject !== '' ? mb_strimwidth($subject, 0, 190, '', 'UTF-8') : null,
            $method !== null && $method !== '' ? substr($method, 0, 16) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[bk_log_notification] ' . $e->getMessage());
    }
}

/**
 * The canonical kinds of outgoing message.
 *
 * One list so the admin filter, the tests and the call sites cannot drift
 * apart. It is deliberately NOT an allow-list for writing - see the comment
 * at the INSERT above.
 */
function bk_notification_kinds(): array {
    return [
        'alert',
        'daily_reminder',
        'digest',
        'digest_preview',
        'invitation',
        'password_reset',
        'subscriber_confirm',
        'subscriber_broadcast',
        'admin_notice',
        'test',
        'other',
    ];
}

/**
 * How much went out over the last $hours and how much of it failed, per channel.
 *
 * A function of its own so the suite can call it with known rows, and because
 * the admin page needs the answer for two windows at once - counting a whole
 * log in PHP to get there would be the obvious wrong way.
 *
 * It deliberately knows nothing about the page's filters: the number feeds a
 * banner that says "something did not go out", and a banner that a filter can
 * talk out of a failure is the quiet failure this release exists to end.
 *
 * A database error is not caught here. A summary that counts to zero because
 * the query died would claim nothing failed, which is the one answer this
 * function must never give.
 */
function bk_notification_summary(PDO $pdo, int $hours): array {
    $hours = max(1, $hours);
    $result = ['total' => 0, 'failed' => 0, 'byChannel' => []];

    $stmt = $pdo->prepare("
        SELECT channel, COUNT(*) AS total, SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failed
        FROM notification_log
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        GROUP BY channel
        ORDER BY total DESC, channel ASC
    ");
    $stmt->execute([$hours]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int)$row['total'];
        $failed = (int)$row['failed'];
        $result['total'] += $total;
        $result['failed'] += $failed;
        $result['byChannel'][] = [
            'channel' => (string)$row['channel'],
            'total' => $total,
            'failed' => $failed,
        ];
    }

    return $result;
}

/**
 * Is the daily reminder due on this cron run?
 *
 * Pure, because the whole feature rests on this guard: a router's cron runs
 * every minute, so "send from 8:00" without a written-down stamp would mean
 * nine hundred identical e-mails a day. The stamp is a DATE, exactly like
 * `last_weekly_digest_sent` holds a week (cron.php) - comparing timestamps
 * would leave the decision to arithmetic that daylight saving gets to break.
 *
 * @param string $last_sent Y-m-d of the last send, '' = never sent
 * @param int    $hour      0-23, the hour from which the reminder may go out
 * @param ?int   $now       evaluation time; NULL = now (a parameter for tests)
 */
function bk_daily_reminder_due(string $last_sent, int $hour, ?int $now = null): bool {
    $now = $now ?? time();
    // A value outside the dial is not a reason to go silent forever - it falls
    // back to the documented default, the same one get_setting() hands out.
    if ($hour < 0 || $hour > 23) {
        $hour = 8;
    }
    if ((int)date('G', $now) < $hour) {
        return false;
    }
    return trim($last_sent) !== date('Y-m-d', $now);
}

/**
 * What is broken right now - the content of the daily reminder.
 *
 * Pure on purpose: rows in, verdict out. The decision whether anybody gets an
 * e-mail at all is then testable without a database; the reading is
 * bk_daily_reminder_collect()'s job.
 *
 * Two sections, never one. A silent agent is not an outage: nothing crashed,
 * the data simply stopped arriving - and that is the fault this whole feature
 * exists for (four days invisible, because the service itself was fine).
 * Folded into the outage list it would be buried among them again.
 *
 * Excluded: monitors in maintenance (a planned outage is not news) and
 * archived ones (out of service for good - trigger_notifications() ignores
 * them too, and a reminder must not resurrect them).
 *
 * @param array   $monitors  `monitors` rows plus 'details' (decoded
 *                           last_details) and 'last_error' (the reason stored
 *                           with the last check)
 * @param array   $incidents open incidents nobody has acknowledged
 * @param ?string $last_cron_run  the `last_cron_run` setting, NULL = never
 * @param int     $agent_offline_timeout seconds of silence that make an agent silent
 * @param int     $cron_max_age  age at which a collection run counts as late
 * @param ?int    $now
 * @return array{outages: array, silent: array, incidents: array, cron: array, problem_count: int}
 */
function bk_daily_reminder_select(
    array $monitors,
    array $incidents,
    ?string $last_cron_run,
    int $agent_offline_timeout = 3000,
    int $cron_max_age = 900,
    ?int $now = null
): array {
    $now = $now ?? time();
    $report = ['outages' => [], 'silent' => [], 'incidents' => [], 'cron' => [], 'problem_count' => 0];

    $age_of = static function ($stamp) use ($now): ?int {
        if ($stamp === null || $stamp === '') {
            return null;
        }
        $ts = is_int($stamp) ? $stamp : strtotime((string)$stamp);
        // An unreadable date stays unknown. "0 seconds" would read as "it
        // started just now" - the invented value this project keeps out.
        return $ts === false ? null : max(0, $now - $ts);
    };

    // One monitor = one row in the silent section, however many rules noticed
    // it. A heartbeat past its grace period and a stalled check are two real
    // findings, but they are the same subject: printed as two rows the reader
    // sees the name twice and the summary counts one monitor as two problems.
    // The extra findings are kept in 'also' - nothing measured is thrown away,
    // it just stops being counted (and read) twice.
    $silent_at = [];
    $add_silent = static function (int $id, string $name, string $type, string $issue, string $message, ?int $age) use (&$report, &$silent_at): void {
        $key = $id !== 0 ? 'id:' . $id : 'name:' . $name;
        if (isset($silent_at[$key])) {
            $at = $silent_at[$key];
            // Deduplicated by the wording too: two rules describing the silence
            // with the same sentence say nothing new the second time.
            if ($message !== '' && $message !== $report['silent'][$at]['message']
                && !in_array($message, array_column($report['silent'][$at]['also'], 'message'), true)) {
                $report['silent'][$at]['also'][] = ['issue' => $issue, 'message' => $message];
            }
            // The row keeps the longest silence it knows about: a summary that
            // shortened the outage because a second rule noticed later would
            // under-report it.
            if ($age !== null && ($report['silent'][$at]['age_secs'] === null || $age > $report['silent'][$at]['age_secs'])) {
                $report['silent'][$at]['age_secs'] = $age;
            }
            return;
        }
        $silent_at[$key] = count($report['silent']);
        $report['silent'][] = [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'issue' => $issue,
            'message' => $message,
            'age_secs' => $age,
            'also' => [],
        ];
    };

    foreach ($monitors as $m) {
        if (!empty($m['archived_at'])) {
            continue;
        }
        $status = strtolower(trim((string)($m['status'] ?? '')));
        if (!empty($m['maintenance']) || $status === 'maintenance') {
            continue;
        }
        $id = (int)($m['id'] ?? 0);
        $name = (string)($m['name'] ?? '');
        $type = (string)($m['type'] ?? '');
        $details = is_array($m['details'] ?? null) ? $m['details'] : [];

        // A heartbeat past interval + grace belongs to the silent section: the
        // job did not fail, it stopped reporting. One that announced its own
        // failure DID fail and falls through to the outage list below.
        $heartbeat_silent = false;
        if ($type === 'heartbeat') {
            $hb = bk_heartbeat_evaluate($m, $now);
            if ($hb['overdue_secs'] !== null) {
                $heartbeat_silent = true;
                $add_silent($id, $name, $type, 'heartbeat_overdue', (string)$hb['error'], $hb['age_secs']);
            }
        }

        // 'unknown' and 'paused' are not faults: the first means nobody has
        // measured yet, the second that nobody is supposed to. Reported daily
        // they would teach the reader to skip the whole message.
        $alert_class = bk_alert_color_class($status);
        if (!$heartbeat_silent
            && !in_array($status, ['unknown', 'paused', ''], true)
            && $alert_class !== 'good') {
            $report['outages'][] = [
                'id' => $id,
                'name' => $name,
                'type' => $type,
                'status' => $status,
                'reason' => ($m['last_error'] ?? null) !== null && $m['last_error'] !== ''
                    ? (string)$m['last_error']
                    : null,
                'since' => ($m['last_status_change'] ?? null) ?: null,
                'duration_secs' => $age_of($m['last_status_change'] ?? null),
                'warning' => $alert_class === 'warn',
            ];
        }

        // The collection outages of this monitor - read from the same source
        // as the app's banner, so the e-mail cannot disagree with the screen.
        foreach (bk_get_collection_issues($m, $details, $agent_offline_timeout) as $issue) {
            $add_silent($id, $name, $type, (string)($issue['type'] ?? 'other'),
                (string)($issue['message'] ?? ''), $age_of($issue['since'] ?? null));
        }
    }

    foreach ($incidents as $inc) {
        $report['incidents'][] = [
            'id' => (int)($inc['id'] ?? 0),
            'title' => (string)($inc['title'] ?? ''),
            'impact' => (string)($inc['impact'] ?? ''),
            'status' => (string)($inc['status'] ?? ''),
            'monitor_name' => ($inc['monitor_name'] ?? null) !== null && $inc['monitor_name'] !== ''
                ? (string)$inc['monitor_name']
                : null,
            'age_secs' => $age_of($inc['created_at'] ?? null),
        ];
    }

    // Longest first, and an unknown duration goes last: it cannot claim to be
    // the longest outage of the lot.
    $longest_first = static function (array $a, array $b, string $key): int {
        $av = $a[$key];
        $bv = $b[$key];
        if ($av === $bv) {
            return 0;
        }
        if ($av === null) {
            return 1;
        }
        if ($bv === null) {
            return -1;
        }
        return $bv <=> $av;
    };
    usort($report['outages'], static fn (array $a, array $b): int => $longest_first($a, $b, 'duration_secs'));
    usort($report['silent'], static fn (array $a, array $b): int => $longest_first($a, $b, 'age_secs'));
    usort($report['incidents'], static fn (array $a, array $b): int => $longest_first($a, $b, 'age_secs'));

    // The last finished cron run. Context, never a reason to send on its own:
    // this code runs FROM cron, so a reminder whose only content was "the
    // collector is late" would be a message from the machine proving it runs.
    // It is here so a dead collector cannot hide behind an otherwise quiet
    // report - the stamp is written only when a whole run completes.
    //
    // "Late" is the same limit the collection watchdog uses
    // (collection_max_age_secs, api.php action=collection_health). Two numbers
    // for one question would let the e-mail and the app disagree about whether
    // collection is alive.
    $cron_age = $age_of($last_cron_run);
    $report['cron'] = [
        'last' => $last_cron_run !== null && $last_cron_run !== '' ? (string)$last_cron_run : null,
        'age_secs' => $cron_age,
        'stale' => $cron_age === null || $cron_age > max(60, $cron_max_age),
    ];

    $report['problem_count'] = count($report['outages']) + count($report['silent']) + count($report['incidents']);

    return $report;
}

/**
 * Reads what bk_daily_reminder_select() needs and hands it the rows.
 *
 * Split from the selection so the rule "what counts as broken" can be tested
 * without a database - the part that talks to MySQL stays this thin on purpose.
 */
function bk_daily_reminder_collect(PDO $pdo, ?int $now = null): array {
    $now = $now ?? time();

    // Archived monitors are filtered in SQL as well as in the selection: no
    // reason to read rows nobody may ever be told about.
    $stmt = $pdo->query("
        SELECT id, name, type, status, last_checked, last_status_change, last_details,
               maintenance, archived_at,
               heartbeat_interval, heartbeat_grace, last_heartbeat,
               heartbeat_last_result, heartbeat_last_message
        FROM monitors
        WHERE archived_at IS NULL
        ORDER BY name ASC
    ");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // The reason of the last check. Read per monitor through the (monitor_id,
    // id) index and only for the ones that are not fine - on a healthy system
    // this loop does not run at all.
    $reason_stmt = $pdo->prepare("
        SELECT error_message FROM monitor_logs
        WHERE monitor_id = ? ORDER BY id DESC LIMIT 1
    ");
    $monitors = [];
    foreach ($rows as $row) {
        $decoded = json_decode((string)($row['last_details'] ?? ''), true);
        $row['details'] = is_array($decoded) ? $decoded : [];
        $row['last_error'] = null;
        $status = strtolower(trim((string)($row['status'] ?? '')));
        if (!in_array($status, ['up', 'maintenance', 'paused', 'unknown', ''], true) && empty($row['maintenance'])) {
            $reason_stmt->execute([(int)$row['id']]);
            $reason = $reason_stmt->fetchColumn();
            $row['last_error'] = ($reason !== false && $reason !== null && $reason !== '') ? (string)$reason : null;
        }
        $monitors[] = $row;
    }

    // Open incidents nobody has taken over. An acknowledged one has an owner -
    // reminding them daily is what makes people filter the sender.
    $inc_stmt = $pdo->query("
        SELECT i.id, i.title, i.impact, i.status, i.created_at, m.name AS monitor_name
        FROM incidents i
        LEFT JOIN monitors m ON m.id = i.monitor_id
        WHERE i.status != 'resolved' AND i.acknowledged_at IS NULL
          AND (i.monitor_id IS NULL OR (m.archived_at IS NULL AND m.maintenance = 0))
        ORDER BY i.created_at ASC
        LIMIT 50
    ");
    $incidents = $inc_stmt ? $inc_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    // Minutes in the setting, seconds in the function - the same conversion
    // the app and the public page make, so the three cannot disagree about
    // which agent is silent.
    $offline_secs = intval(get_setting('agent_offline_timeout', '50')) * 60;
    $last_cron = (string)get_setting('last_cron_run', '');
    // The watchdog's own limit, so the reminder and action=collection_health
    // cannot disagree about whether collection is still alive.
    $cron_max_age = max(60, (int)get_setting('collection_max_age_secs', '900'));

    return bk_daily_reminder_select($monitors, $incidents, $last_cron !== '' ? $last_cron : null,
        $offline_secs, $cron_max_age, $now);
}

/**
 * Who gets the reminder - the alert's recipient rule, widened to several
 * monitors at once.
 *
 * One alert asks about one monitor (trigger_notifications()); the reminder is
 * about everything that is broken, so a user belongs in the list when they
 * would get an alert for at least ONE of those monitors: an administrator, or
 * a subscriber who still has access to it. The monitor's own
 * `email_notifications` switch is respected exactly as there - a monitor that
 * sends no mail must not start sending it once a day.
 *
 * @param array $monitor_ids ids of the monitors the reminder talks about
 * @param bool  $admins_only agent internals only (see agent_notify_admin_only)
 */
function bk_daily_reminder_recipients(PDO $pdo, array $monitor_ids, bool $admins_only): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $monitor_ids), fn (int $id): bool => $id > 0)));

    // Administrators always, even when the reminder is only about incidents
    // that belong to no monitor - then there is nothing to subscribe to.
    $sql = "SELECT DISTINCT u.id, u.email, u.email_lang, u.role, u.phone, u.whatsapp_apikey,
                   u.whatsapp_notifications
            FROM users u
            WHERE u.email IS NOT NULL AND u.email != '' AND u.role = 'admin'";
    $params = [];

    if (!$admins_only && $ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql .= "
            UNION
            SELECT DISTINCT u.id, u.email, u.email_lang, u.role, u.phone, u.whatsapp_apikey,
                   u.whatsapp_notifications
            FROM users u
            JOIN user_subscriptions s ON s.user_id = u.id
            JOIN monitors m ON m.id = s.monitor_id
            JOIN monitor_users mu ON mu.user_id = u.id AND mu.monitor_id = m.id
            WHERE u.email IS NOT NULL AND u.email != ''
              AND m.id IN ({$placeholders})
              AND COALESCE(s.email_notifications, m.email_notifications) = 1";
        $params = $ids;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // The same fallback as the alert path: without the access table the
        // message still reaches every administrator. Losing the reminder
        // entirely over a migration that has not run is the worse half.
        error_log('[reminder] Seznam příjemců selhal, posílá se jen administrátorům: ' . $e->getMessage());
        $stmt = $pdo->query("SELECT id, email, email_lang, role, phone, whatsapp_apikey, whatsapp_notifications
                             FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

/**
 * The reminder as a subject and an HTML body, in the language that is set at
 * the moment of the call (see bk_with_email_lang()).
 *
 * @param array $report the output of bk_daily_reminder_select()
 * @return array{0: string, 1: string}
 */
function bk_render_daily_reminder(array $report): array {
    $font = "font-family: Arial, Helvetica, sans-serif;";
    $muted = 'color:#888896; font-size:12px; ' . $font;
    $duration = static function (?int $secs): string {
        // An unknown length is said out loud. "0 s" would claim it started now.
        return $secs === null ? t('reminder_duration_unknown') : sprintf(t('reminder_duration'), bk_format_duration_secs($secs));
    };
    $section = static function (string $title, string $note, string $rows) use ($font): string {
        return '<h2 style="margin:22px 0 8px 0; font-size:16px; color:#ffffff; ' . $font . '">' . htmlspecialchars($title) . '</h2>'
            . ($note !== '' ? '<p style="margin:0 0 10px 0; color:#888896; font-size:12px; ' . $font . '">' . htmlspecialchars($note) . '</p>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $rows . '</table>';
    };
    $row = static function (string $accent, string $head, string $detail) use ($font): string {
        return '<tr><td style="border-left:3px solid ' . $accent . '; background-color:#12121a; padding:12px 14px; margin:0 0 8px 0; ' . $font . '">'
            . '<strong style="color:#ffffff;">' . $head . '</strong>'
            . ($detail !== '' ? '<br><span style="color:#b9b9c4; font-size:13px;">' . $detail . '</span>' : '')
            . '</td></tr><tr><td style="height:8px; line-height:8px;">&nbsp;</td></tr>';
    };

    $body = '<p style="margin:0 0 4px 0; ' . $font . '">' . htmlspecialchars(t('reminder_intro')) . '</p>';

    if ($report['outages'] !== []) {
        $rows = '';
        foreach ($report['outages'] as $item) {
            $head = htmlspecialchars($item['name']) . ' &mdash; ' . htmlspecialchars(strtoupper($item['status']));
            $detail = htmlspecialchars($duration($item['duration_secs']));
            $detail .= ' &middot; ' . htmlspecialchars(strtoupper($item['type']));
            $detail .= '<br>' . htmlspecialchars($item['reason'] ?? t('reminder_no_reason'));
            $rows .= $row($item['warning'] ? '#f39c12' : '#ef233c', $head, $detail);
        }
        $body .= $section(t('reminder_section_outages'), '', $rows);
    }

    if ($report['silent'] !== []) {
        $rows = '';
        foreach ($report['silent'] as $item) {
            $head = htmlspecialchars($item['name']);
            $detail = htmlspecialchars($item['message']);
            // The other rules that noticed the same monitor. Kept under the
            // first finding instead of in a row of their own: one subject, one
            // row, but no finding is dropped.
            foreach ($item['also'] ?? [] as $also) {
                $detail .= '<br>' . htmlspecialchars(t('reminder_silent_also') . ' ' . $also['message']);
            }
            if ($item['age_secs'] !== null) {
                $detail .= '<br>' . htmlspecialchars($duration($item['age_secs']));
            }
            $rows .= $row('#f39c12', $head, $detail);
        }
        $body .= $section(t('reminder_section_silent'), t('reminder_section_silent_note'), $rows);
    }

    if ($report['incidents'] !== []) {
        $rows = '';
        foreach ($report['incidents'] as $item) {
            $head = htmlspecialchars($item['title']);
            $detail = htmlspecialchars(sprintf(t('reminder_incident_age'),
                $item['age_secs'] === null ? t('reminder_duration_unknown') : bk_format_duration_secs($item['age_secs'])));
            if ($item['monitor_name'] !== null) {
                $detail .= ' &middot; ' . htmlspecialchars($item['monitor_name']);
            }
            $rows .= $row('#ef233c', $head, $detail);
        }
        $body .= $section(t('reminder_section_incidents'), '', $rows);
    }

    // The collection stamp closes the message so a dead collector cannot hide
    // behind a report that happens to be short.
    $cron = $report['cron'];
    if ($cron['last'] === null) {
        $cron_line = t('reminder_cron_never');
    } else {
        $cron_line = sprintf(t('reminder_cron_last'), $cron['last'],
            $cron['age_secs'] === null ? t('reminder_duration_unknown') : bk_format_duration_secs($cron['age_secs']));
        if ($cron['stale']) {
            $cron_line .= ' ' . t('reminder_cron_stale');
        }
    }
    $body .= '<p style="margin:22px 0 0 0; padding-top:14px; border-top:1px solid #22222f; ' . $muted . '">'
        . htmlspecialchars($cron_line) . '</p>';

    $subject = '🔔 ' . t('reminder_subject') . ' – ' . get_setting('site_title', 'Blood Kings Status');

    return [$subject, render_email_wrapper(t('reminder_title'), htmlspecialchars(date('d.m.Y H:i')), '#f39c12', $body)];
}

/**
 * The same reminder as a short text for the chat channels.
 *
 * Czech only, like every other webhook text in this file: the e-mail follows
 * the recipient's language, a shared Discord channel has no recipient to ask.
 */
function bk_daily_reminder_text(array $report): string {
    $parts = [];
    foreach (array_slice($report['outages'], 0, 5) as $item) {
        $parts[] = '• ' . $item['name'] . ' – ' . strtoupper($item['status'])
            . ($item['duration_secs'] !== null ? ' (' . bk_format_duration_secs($item['duration_secs']) . ')' : '');
    }
    foreach (array_slice($report['silent'], 0, 5) as $item) {
        $parts[] = '• ' . $item['name'] . ' – ' . $item['message'];
    }
    foreach (array_slice($report['incidents'], 0, 5) as $item) {
        $parts[] = '• ' . $item['title'] . ' – nepřevzatý incident';
    }
    $shown = count($parts);
    if ($report['problem_count'] > $shown) {
        $parts[] = '… a dalších ' . ($report['problem_count'] - $shown) . ' v aplikaci.';
    }
    return "🔔 **Denní připomínka: pořád je něco rozbité**\n" . implode("\n", $parts);
}

/**
 * Sends the daily reminder - or writes down that there was nothing to send.
 *
 * When nothing is wrong, nothing goes out. A daily "all good" teaches the
 * reader to filter the sender, and the first real message is filtered with it.
 * The decision is not silent though: it lands in the outgoing message log as a
 * skipped row, so "no e-mail came" can be told apart from "the reminder is
 * broken" - exactly the question this release exists to answer.
 *
 * @return array{sent: bool, reason: string, problems: int, emails: int, channels: int}
 */
function bk_send_daily_reminder(PDO $pdo, ?int $now = null): array {
    $report = bk_daily_reminder_collect($pdo, $now);
    $result = [
        'sent' => false,
        'reason' => 'nothing_wrong',
        'problems' => $report['problem_count'],
        'emails' => 0,
        'channels' => 0,
    ];
    $default_lang = get_setting('email_lang', 'cs');

    if ($report['problem_count'] === 0) {
        // ok = 1: nothing failed here. A zero would light up the "something did
        // not go out" banner every single healthy day, and a banner that cries
        // daily is a banner nobody reads on the day it matters.
        bk_log_notification(
            $pdo,
            null,
            'skipped',
            'none',
            null,
            true,
            null,
            'daily_reminder',
            bk_with_email_lang($default_lang, fn (): string => t('reminder_skipped')),
            null
        );
        return $result;
    }

    $monitor_ids = [];
    foreach (array_merge($report['outages'], $report['silent']) as $item) {
        $monitor_ids[] = (int)$item['id'];
    }

    // Nothing but silent collection and unacknowledged incidents means nothing
    // a subscriber would have been alerted about either - that is the agent
    // class of events, and agent_notify_admin_only keeps those internal.
    $admins_only = get_setting('agent_notify_admin_only', '1') === '1' && $report['outages'] === [];
    $recipients = bk_daily_reminder_recipients($pdo, $monitor_ids, $admins_only);

    $rendered_by_lang = [];
    $text = bk_daily_reminder_text($report);
    foreach ($recipients as $rec) {
        $lang = in_array($rec['email_lang'] ?? '', ['cs', 'en'], true) ? $rec['email_lang'] : $default_lang;
        if (!isset($rendered_by_lang[$lang])) {
            $rendered_by_lang[$lang] = bk_with_email_lang($lang, fn (): array => bk_render_daily_reminder($report));
        }
        [$subject, $html_body] = $rendered_by_lang[$lang];
        // send_email() writes the log row itself - one per attempt, failures included.
        if (send_email($rec['email'], $subject, $html_body, [], ['kind' => 'daily_reminder'])) {
            $result['emails']++;
        }

        // WhatsApp goes through CallMeBot with the user's own key. SMS is
        // deliberately NOT used: a paid message every day is a cost nobody
        // agreed to, and the alert that pays for itself already went out.
        if (!empty($rec['whatsapp_notifications']) && !empty($rec['phone']) && !empty($rec['whatsapp_apikey'])) {
            $wa_ok = send_sms($rec['phone'], mb_strimwidth($text, 0, 900, '…', 'UTF-8'), $rec['whatsapp_apikey'], 'whatsapp');
            bk_log_notification($pdo, null, 'daily_reminder', 'whatsapp', $rec['phone'], (bool)$wa_ok, null, 'daily_reminder');
            if ($wa_ok) {
                $result['channels']++;
            }
        }
    }

    // The shared channels - global settings only. A per-monitor webhook belongs
    // to one monitor and this message is about all of them at once.
    $discord = (string)get_setting('discord_webhook_url', '');
    if ($discord !== '') {
        $ok = send_webhook_post($discord, json_encode(['content' => $text], JSON_UNESCAPED_UNICODE));
        bk_log_notification($pdo, null, 'daily_reminder', 'discord', null, $ok,
            $ok ? null : ($GLOBALS['last_webhook_error'] ?? null), 'daily_reminder');
        $result['channels'] += $ok ? 1 : 0;
    }
    $slack = (string)get_setting('slack_webhook_url', '');
    if ($slack !== '') {
        $ok = send_webhook_post($slack, json_encode(['text' => $text], JSON_UNESCAPED_UNICODE));
        bk_log_notification($pdo, null, 'daily_reminder', 'slack', null, $ok,
            $ok ? null : ($GLOBALS['last_webhook_error'] ?? null), 'daily_reminder');
        $result['channels'] += $ok ? 1 : 0;
    }
    $tg_token = (string)get_setting('telegram_bot_token', '');
    $tg_chat = (string)get_setting('telegram_chat_id', '');
    if ($tg_token !== '' && $tg_chat !== '') {
        $ok = send_webhook_post('https://api.telegram.org/bot' . $tg_token . '/sendMessage',
            json_encode(['chat_id' => $tg_chat, 'text' => $text, 'parse_mode' => 'Markdown'], JSON_UNESCAPED_UNICODE));
        bk_log_notification($pdo, null, 'daily_reminder', 'telegram', $tg_chat, $ok,
            $ok ? null : ($GLOBALS['last_webhook_error'] ?? null), 'daily_reminder');
        $result['channels'] += $ok ? 1 : 0;
    }
    $po_user = (string)get_setting('pushover_user_key', '');
    $po_token = (string)get_setting('pushover_api_token', '');
    if ($po_user !== '' && $po_token !== '') {
        // Priority 0: the reminder is a summary of what is already known, not
        // a page. The outage itself paged when it happened.
        $ok = send_pushover_alert($po_user, $po_token, 'Blood Kings: denní připomínka',
            mb_strimwidth($text, 0, 900, '…', 'UTF-8'), 0);
        bk_log_notification($pdo, null, 'daily_reminder', 'pushover', null, (bool)$ok, null, 'daily_reminder');
        $result['channels'] += $ok ? 1 : 0;
    }

    // "Sent" means at least one message really left. Without this the cron
    // would stamp the day as done even when every channel refused, and the
    // next attempt would be tomorrow.
    $result['sent'] = $result['emails'] > 0 || $result['channels'] > 0;
    $result['reason'] = $result['sent'] ? 'sent' : 'no_recipient';

    return $result;
}

function trigger_notifications($pdo, $monitor, $new_status, $error_msg = '') {
    // An archived monitor is out of service for good: nobody is told anything about it.
    if (!empty($monitor['archived_at']) || ($pdo instanceof PDO && !empty($monitor['id']) && bk_monitor_is_archived($pdo, (int)$monitor['id']))) {
        return;
    }
    bk_incident_lifecycle($pdo, $monitor, $new_status, $error_msg);
    $name = $monitor['name'];
    $type = $monitor['type'];
    $target = $monitor['target'];
    $port = $monitor['port'];

    // Agent inactivity notifications follow the timeout directly (0 = fully disabled, see cron.php)
    // - no separate toggle is needed for them.
    // CPU/RAM/HDD threshold alerts can be disabled separately in settings.
    // lte_backup_lost/restored: the router's mobile backup stopped being able to
    // carry traffic (no SIM, PIN, not registered) and came back. Agent-class
    // like the threshold alerts - same admin-only default and the same switch.
    // wan_lost/restored: the router's primary link stopped carrying traffic
    // (interface down, or up but no echo gets out) - reported through the LTE
    // backup, which is why it needs its own alert at all.
    // storage_*: the SMART and filesystem alerts of a router's disks. They
    // come from the agent like the threshold ones, so they follow the same
    // admin-only default and the same switch.
    // wan_link_degraded/restored, conntrack_full, firewall_disabled/restored,
    // dns_resolver_failed/restored: the router rules of alert sheet 2.2. They
    // are measured by the agent like the ones above, so they follow the same
    // admin-only default and the same switch. None of them pages (X14):
    // bk_pagerduty_action() is null for all seven.
    $bk_agent_statuses = ['vps_warning', 'lte_backup_lost', 'lte_backup_restored', 'wan_lost', 'wan_restored',
        'storage_failing', 'storage_warning', 'storage_recovered',
        'wan_link_degraded', 'wan_link_restored', 'conntrack_full',
        'firewall_disabled', 'firewall_restored', 'dns_resolver_failed', 'dns_resolver_restored'];
    $is_agent_event = $new_status === 'agent_offline' || in_array($new_status, $bk_agent_statuses, true);
    if (in_array($new_status, $bk_agent_statuses, true)
        && get_setting('agent_notifications_enabled', '1') !== '1') {
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
        // The service runs, just slowly - hence a warning, not an outage.
        $status_text = 'ZPOMALENÍ (služba odpovídá pomalu)';
        $emoji = '🐢';
    } elseif ($new_status === 'latency_recovered') {
        $status_text = 'ODEZVA ZPĚT V NORMÁLU';
        $emoji = '🟢';
    } elseif ($new_status === 'lte_backup_lost') {
        $status_text = 'LTE ZÁLOHA NEFUNKČNÍ';
        $emoji = '📵';
    } elseif ($new_status === 'lte_backup_restored') {
        $status_text = 'LTE ZÁLOHA OBNOVENA';
        $emoji = '🟢';
    } elseif ($new_status === 'wan_lost') {
        $status_text = 'PRIMÁRNÍ PŘIPOJENÍ (WAN) VÝPADEK';
        $emoji = '🔌';
    } elseif ($new_status === 'wan_restored') {
        $status_text = 'PRIMÁRNÍ PŘIPOJENÍ (WAN) OBNOVENO';
        $emoji = '🟢';
    } elseif ($new_status === 'storage_failing') {
        $status_text = 'DISK SELHÁVÁ';
        $emoji = '💽';
    } elseif ($new_status === 'storage_warning') {
        $status_text = 'VAROVÁNÍ ÚLOŽIŠTĚ';
        $emoji = '⚠️';
    } elseif ($new_status === 'storage_recovered') {
        $status_text = 'ÚLOŽIŠTĚ V POŘÁDKU';
        $emoji = '🟢';
    } elseif ($new_status === 'wan_link_degraded') {
        $status_text = 'PORT WAN SPOJEN POMALEJI';
        $emoji = '⚠️';
    } elseif ($new_status === 'wan_link_restored') {
        $status_text = 'PORT WAN OPĚT NA PLNÉ RYCHLOSTI';
        $emoji = '🟢';
    } elseif ($new_status === 'conntrack_full') {
        $status_text = 'TABULKA SPOJENÍ JE PLNÁ';
        $emoji = '⚠️';
    } elseif ($new_status === 'firewall_disabled') {
        $status_text = 'PRAVIDLA FIREWALLU NEJSOU NAČTENÁ';
        $emoji = '🛡️';
    } elseif ($new_status === 'firewall_restored') {
        $status_text = 'PRAVIDLA FIREWALLU OPĚT NAČTENÁ';
        $emoji = '🟢';
    } elseif ($new_status === 'dns_resolver_failed') {
        $status_text = 'DNS RESOLVER ROUTERU NEODPOVÍDÁ';
        $emoji = '⚠️';
    } elseif ($new_status === 'dns_resolver_restored') {
        $status_text = 'DNS RESOLVER ROUTERU OPĚT ODPOVÍDÁ';
        $emoji = '🟢';
    } elseif ($new_status === 'ssl_expiring') {
        // A certificate about to expire is a warning about the future, not an
        // outage now - it used to go out with the red DOWN label.
        $status_text = 'SSL CERTIFIKÁT BRZY VYPRŠÍ';
        $emoji = '🔒';
    }
    // Load all notification recipients (subscribers + administrators without an explicit subscription).
    // A subscribed user gets the alert only while the monitor is assigned to
    // them: an alert carries the monitor's name and outage reason, and a stale
    // subscription must not keep mailing them to an account that lost access.
    $recipients_sql = "
        SELECT u.id, u.email, u.phone, u.role, u.whatsapp_apikey, u.email_lang,
               COALESCE(s.email_notifications, m.email_notifications) as email_notifications,
               COALESCE(s.sms_notifications, m.sms_notifications, u.sms_notifications) as sms_notifications,
               COALESCE(s.whatsapp_notifications, u.whatsapp_notifications) as whatsapp_notifications
        FROM users u
        CROSS JOIN (SELECT * FROM monitors WHERE id = ?) m
        LEFT JOIN user_subscriptions s ON u.id = s.user_id AND s.monitor_id = m.id
        WHERE u.role = 'admin' OR (s.user_id IS NOT NULL AND %s)
    ";
    try {
        $stmt = $pdo->prepare(sprintf($recipients_sql, "EXISTS (SELECT 1 FROM monitor_users mu WHERE mu.user_id = u.id AND mu.monitor_id = m.id)"));
        $stmt->execute([$monitor['id']]);
    } catch (PDOException $e) {
        // No access table yet (a migration that has not run): the alert still
        // reaches every admin - it must never fail as a whole - but no user.
        error_log('[notify] monitor_users unavailable, alerting admins only: ' . $e->getMessage());
        $stmt = $pdo->prepare(sprintf($recipients_sql, '1=0'));
        $stmt->execute([$monitor['id']]);
    }
    $recipients = $stmt->fetchAll();

    // VPS agent events are internal by default - they go to administrators only, not regular subscribers
    if ($is_agent_event && get_setting('agent_notify_admin_only', '1') === '1') {
        $recipients = array_values(array_filter($recipients, function ($r) {
            return ($r['role'] ?? '') === 'admin';
        }));
    }

    $time = date('d.m.Y H:i:s');

    // HTML e-mail template in Blood Kings colours (red-black)
    $color_theme = '#c1121f'; // red
    $alert_class = bk_alert_color_class($new_status);
    if ($alert_class === 'good') {
        $color_theme = '#1ec773'; // teal
    } elseif ($alert_class === 'warn') {
        $color_theme = '#f39c12'; // orange
    }

    // The e-mail channel's language follows the email_lang setting (see bk_with_email_lang()),
    // not the recipient's browser nor the environment (cron/agent_api) this function runs in.
    // The SMS/WhatsApp and Discord/Slack/Telegram/Pushover/PagerDuty messages below stay
    // on $status_text (Czech) unchanged - only the e-mail channel is translated.
    $alert_status_keys = [
        'down' => 'alert_status_down',
        'up' => 'alert_status_up',
        'maintenance' => 'alert_status_maintenance',
        'agent_offline' => 'alert_status_agent_offline',
        'vps_warning' => 'alert_status_vps_warning',
        'lte_backup_lost' => 'alert_status_lte_backup_lost',
        'lte_backup_restored' => 'alert_status_lte_backup_restored',
        'wan_lost' => 'alert_status_wan_lost',
        'wan_restored' => 'alert_status_wan_restored',
        // Without these three the e-mail subject and badge fell back to
        // "DOWN (Výpadek)" - a slow response and an expiring certificate
        // arrived looking like outages.
        'latency_degraded' => 'alert_status_latency_degraded',
        'latency_recovered' => 'alert_status_latency_recovered',
        'ssl_expiring' => 'alert_status_ssl_expiring',
        'storage_failing' => 'alert_status_storage_failing',
        'storage_warning' => 'alert_status_storage_warning',
        'storage_recovered' => 'alert_status_storage_recovered',
        'wan_link_degraded' => 'alert_status_wan_link_degraded',
        'wan_link_restored' => 'alert_status_wan_link_restored',
        'conntrack_full' => 'alert_status_conntrack_full',
        'firewall_disabled' => 'alert_status_firewall_disabled',
        'firewall_restored' => 'alert_status_firewall_restored',
        'dns_resolver_failed' => 'alert_status_dns_resolver_failed',
        'dns_resolver_restored' => 'alert_status_dns_resolver_restored',
    ];
    $alert_status_key = $alert_status_keys[$new_status] ?? 'alert_status_down';

    // Everything inline (+ real <table>), not a <style> block - Gmail, Outlook and
    // most webmails strip <style>/<head> on delivery and the e-mail would arrive
    // unformatted. Same approach as render_email_wrapper() (the digest).
    $font = "font-family: Arial, Helvetica, sans-serif;";
    // The e-mail renders per recipient language (users.email_lang, NULL = the global
    // email_lang) - once per language, not once per recipient. The SMS/WhatsApp and
    // webhooks below stay single-language.
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
    // Cache of rendered variants - keyed by language.
    $alert_email_by_lang = [];

    // SMS / WhatsApp message
    $sms_body = "$emoji Monitor $name je $status_text. Čas: $time.";
    if ($new_status === 'maintenance') {
        $sms_body = "$emoji Monitor $name byl přepnut do režimu plánované údržby. Důvod: $error_msg";
    } elseif ($new_status === 'down' && !empty($error_msg)) {
        // Cut by characters. A byte cut split a letter like "ů" in half, and the
        // keyword error arrived naming a shorter keyword than the configured one.
        $sms_body .= " Chyba: " . mb_strimwidth($error_msg, 0, 100, '…', 'UTF-8');
    } elseif ($is_agent_event && !empty($error_msg)) {
    // For agent events (inactive agent, exceeded limits) always state the reason
        $sms_body .= " Důvod: " . mb_strimwidth($error_msg, 0, 220, '…', 'UTF-8');
    }
    
    foreach ($recipients as $rec) {
        // E-mail notifications - in the recipient's language (fallback: global email_lang)
        if ($rec['email_notifications'] && !empty($rec['email'])) {
            $rec_lang = in_array($rec['email_lang'] ?? '', ['cs', 'en'], true) ? $rec['email_lang'] : $default_email_lang;
            if (!isset($alert_email_by_lang[$rec_lang])) {
                $alert_email_by_lang[$rec_lang] = $render_alert_email($rec_lang);
            }
            [$email_subject, $html_body] = $alert_email_by_lang[$rec_lang];
            // send_email() writes the row itself now. The explicit
            // bk_log_notification() that used to stand here would make it two
            // rows for one mail - and every count over the log twice the truth.
            send_email($rec['email'], $email_subject, $html_body, [], [
                'kind' => 'alert',
                'monitor_id' => (int)($monitor['id'] ?? 0),
                'status' => $new_status,
            ]);
        }
        
        // SMS notifications (Twilio / SMSbrana) - independent of WhatsApp
        $gateway_type = get_setting('sms_gateway_type', '');
        if ($rec['sms_notifications'] && !empty($rec['phone'])) {
            if ($gateway_type === 'twilio' || $gateway_type === 'smsbrana') {
                $sms_ok = send_sms($rec['phone'], $sms_body);
                bk_log_notification($pdo, (int)($monitor['id'] ?? 0), $new_status, 'sms', $rec['phone'], (bool)$sms_ok, null, 'alert');
            }
        }

        // WhatsApp notifications (CallMeBot) - independent of the SMS gateway, its own channel.
        // The key is bound to a specific phone number, so it exists per-user only.
        if (($rec['whatsapp_notifications'] ?? 0) && !empty($rec['phone']) && !empty($rec['whatsapp_apikey'])) {
            $wa_ok = send_sms($rec['phone'], $sms_body, $rec['whatsapp_apikey'], 'whatsapp');
            bk_log_notification($pdo, (int)($monitor['id'] ?? 0), $new_status, 'whatsapp', $rec['phone'], (bool)$wa_ok, null, 'alert');
        }
    }

    // Public subscribers (no accounts): outage and recovery only - agent
    // internals (vps_warning, agent_offline) are operations, not public news.
    try {
        bk_public_sub_notify($pdo, $monitor, $new_status);
    } catch (Throwable $e) {
        error_log('[pubsub] ' . $e->getMessage());
    }

    // System/monitor webhooks (Discord, Slack, Telegram) - fired only once per event
    $discord_webhook = !empty($monitor['discord_webhook_url']) ? $monitor['discord_webhook_url'] : get_setting('discord_webhook_url');
    $telegram_token = !empty($monitor['telegram_bot_token']) ? $monitor['telegram_bot_token'] : get_setting('telegram_bot_token');
    $telegram_chat = !empty($monitor['telegram_chat_id']) ? $monitor['telegram_chat_id'] : get_setting('telegram_chat_id');
    $slack_webhook = !empty($monitor['slack_webhook_url']) ? $monitor['slack_webhook_url'] : get_setting('slack_webhook_url');

    if (!empty($discord_webhook)) {
        // The same classification as the e-mail: recovery green, warning
        // orange, only a real outage red.
        $color = ['good' => 3066993, 'warn' => 15965202, 'bad' => 15073280][$alert_class];
        $payload = [
            "embeds" => [[
                "title" => "Blood Kings Status Alert",
                "description" => "**Monitor:** " . htmlspecialchars($name) . "\n**Status:** " . strtoupper($status_text) . "\n**Čas:** " . $time . (!empty($error_msg) ? "\n**Detaily:** " . htmlspecialchars($error_msg) : ""),
                "color" => $color
            ]]
        ];
        $discord_ok = send_webhook_post($discord_webhook, json_encode($payload));
        bk_log_notification(
            $pdo,
            (int)($monitor['id'] ?? 0),
            $new_status,
            'discord',
            null,
            $discord_ok,
            $discord_ok ? null : ($GLOBALS['last_webhook_error'] ?? null),
            'alert'
        );
    }

    if (!empty($slack_webhook)) {
        $slack_msg = "$emoji *Blood Kings Alert*:\n*Monitor:* $name\n*Status:* " . strtoupper($status_text) . "\n*Čas:* $time" . (!empty($error_msg) ? "\n*Detaily:* $error_msg" : "");
        $slack_ok = send_webhook_post($slack_webhook, json_encode(["text" => $slack_msg]));
        bk_log_notification(
            $pdo,
            (int)($monitor['id'] ?? 0),
            $new_status,
            'slack',
            null,
            $slack_ok,
            $slack_ok ? null : ($GLOBALS['last_webhook_error'] ?? null),
            'alert'
        );
    }

    if (!empty($telegram_token) && !empty($telegram_chat)) {
        $tg_msg = "$emoji *Blood Kings Alert*:\n*Monitor:* $name\n*Status:* " . strtoupper($status_text) . "\n*Čas:* $time" . (!empty($error_msg) ? "\n*Detaily:* $error_msg" : "");
        $tg_url = "https://api.telegram.org/bot" . $telegram_token . "/sendMessage";
        $payload = [
            "chat_id" => $telegram_chat,
            "text" => $tg_msg,
            "parse_mode" => "Markdown"
        ];
        $tg_ok = send_webhook_post($tg_url, json_encode($payload));
        bk_log_notification(
            $pdo,
            (int)($monitor['id'] ?? 0),
            $new_status,
            'telegram',
            $telegram_chat,
            $tg_ok,
            $tg_ok ? null : ($GLOBALS['last_webhook_error'] ?? null),
            'alert'
        );
    }

    // Pushover notifikace
    $po_user = get_setting('pushover_user_key');
    $po_token = get_setting('pushover_api_token');
    if (!empty($po_user) && !empty($po_token)) {
        $po_prio = ($new_status === 'down') ? 1 : 0;
        $po_ok = send_pushover_alert($po_user, $po_token, "Blood Kings Alert: $name", "$emoji Monitor $name je $status_text. $error_msg", $po_prio);
        bk_log_notification($pdo, (int)($monitor['id'] ?? 0), $new_status, 'pushover', null, (bool)$po_ok, null, 'alert');
    }

    // PagerDuty notifikace
    $pd_key = get_setting('pagerduty_routing_key');
    if (!empty($pd_key)) {
        // Every status other than "down" used to be sent as "resolve" - so
        // an agent going silent, a lost WAN or a lost LTE backup CLOSED the
        // open PagerDuty incident instead of paging, and warnings closed it
        // too. Now: outages page, recoveries resolve, warnings stay off
        // PagerDuty (they have their own channels).
        $pd_action = bk_pagerduty_action($new_status);
        if ($pd_action !== null) {
            // One key per monitor: the agent-silence page and the outage page
            // are the same incident, and the recovery closes it. Disks get
            // their own key (bk_pagerduty_dedup_key), so neither side can
            // close the other's incident.
            $pd_ok = send_pagerduty_event($pd_key, $pd_action, "$emoji Monitor $name je $status_text. $error_msg",
                'Blood Kings Monitoring', bk_pagerduty_dedup_key((int)($monitor['id'] ?? 0), $new_status));
            bk_log_notification(
                $pdo,
                (int)($monitor['id'] ?? 0),
                $new_status,
                'pagerduty',
                $pd_action,
                (bool)$pd_ok,
                $pd_ok ? null : ($GLOBALS['last_webhook_error'] ?? null),
                'alert'
            );
        }
    }
}

/**
 * Helper for sending HTTP POST requests (webhooks)
 */
/**
 * POSTs a JSON payload to a webhook. Returns whether the endpoint accepted it
 * (transport OK and a 2xx/3xx answer) - the settings page's test button
 * reports this verdict; the alert paths ignore it, as before.
 */
function send_webhook_post($url, $payload_json): bool {
    $ch = curl_init($url);
    if ($ch === false) return false;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_json);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: BloodKingsStatus/1.3.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    // A redirect is not a delivery: curl does not follow it for this POST, so
    // the message never reached the endpoint. Only 2xx counts as accepted.
    $ok = ($res !== false && $code >= 200 && $code < 300);
    $GLOBALS['last_webhook_error'] = ($res === false) ? curl_error($ch) : ($ok ? null : "HTTP {$code}");
    curl_close($ch);
    return $ok;
}

/**
 * Sends one real test message through a notification channel using the SAVED
 * global settings, and says whether it went. Backs the settings page's test
 * buttons, which used to flash "Test OK" without calling anything.
 *
 * @return array{ok: bool, message: string}
 */
function bk_send_test_notification(string $channel, ?string $to_email, string $lang): array {
    $time = date('d.m.Y H:i:s');
    $text = "🧪 Testovací zpráva z Blood Kings Status ({$time}). Pokud ji vidíte, kanál funguje.";
    switch ($channel) {
        case 'email':
            if (empty($to_email)) {
                return ['ok' => false, 'message' => 'Přihlášený administrátor nemá nastavenou e-mailovou adresu.'];
            }
            [$subject, $body] = bk_with_email_lang($lang, function () use ($time) {
                $body = '<h1>' . htmlspecialchars(t('test_email_heading')) . '</h1>'
                    . '<p>' . htmlspecialchars(t('test_email_body1')) . '</p>'
                    . '<p>' . htmlspecialchars(t('test_email_body2')) . '</p><hr>'
                    . '<p>' . htmlspecialchars(t('test_email_sent_at')) . ' ' . $time . '</p>';
                return [t('test_email_subject'), $body];
            });
            if (send_email($to_email, $subject, $body, [], ['kind' => 'test'])) {
                $fallback = ($GLOBALS['last_mail_method'] ?? null) === 'fallback';
                return ['ok' => true, 'message' => $fallback
                    ? "Předáno systémové funkci mail() (SMTP není nastaveno) na {$to_email} - zkontrolujte, zda opravdu dorazil."
                    : "Odesláno na {$to_email}."];
            }
            $detail = !empty($GLOBALS['last_mail_error']) ? ' ' . $GLOBALS['last_mail_error'] : '';
            return ['ok' => false, 'message' => 'Odeslání e-mailu selhalo.' . $detail];

        case 'discord':
            $url = (string)get_setting('discord_webhook_url', '');
            if ($url === '') {
                return ['ok' => false, 'message' => 'Discord webhook není v nastavení uložen.'];
            }
            $ok = send_webhook_post($url, json_encode(['embeds' => [[
                'title' => 'Blood Kings Status - test',
                'description' => $text,
                'color' => 3447003,
            ]]]));
            break;

        case 'slack':
            $url = (string)get_setting('slack_webhook_url', '');
            if ($url === '') {
                return ['ok' => false, 'message' => 'Slack webhook není v nastavení uložen.'];
            }
            $ok = send_webhook_post($url, json_encode(['text' => $text]));
            break;

        case 'telegram':
            $token = (string)get_setting('telegram_bot_token', '');
            $chat = (string)get_setting('telegram_chat_id', '');
            if ($token === '' || $chat === '') {
                return ['ok' => false, 'message' => 'Telegram bot token nebo chat ID není v nastavení uložen.'];
            }
            $ok = send_webhook_post('https://api.telegram.org/bot' . $token . '/sendMessage',
                json_encode(['chat_id' => $chat, 'text' => $text]));
            break;

        default:
            return ['ok' => false, 'message' => 'Neznámý kanál.'];
    }
    if ($ok) {
        return ['ok' => true, 'message' => 'Kanál zprávu přijal.'];
    }
    $err = $GLOBALS['last_webhook_error'] ?? null;
    return ['ok' => false, 'message' => 'Kanál zprávu nepřijal.' . ($err ? " ({$err})" : '')];
}

/**
 * Builds and sends the periodic summary report (weekly/monthly) to the e-mails
 * of all administrators. Called from cron.php (automatically, guarded against
 * duplicate sending) and from admin.php (manual immediate send).
 *
 * @param PDO $pdo
 * @param string $period 'weekly' nebo 'monthly'
 * @return bool True when the report reached at least one administrator.
 */
function send_digest_report($pdo, $period = 'weekly') {
    $GLOBALS['last_mail_error'] = '';
    try {
        // The language wrapper moved inside - the digest renders per recipient
        // language (users.email_lang), not once globally.
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
 * Determines the trend direction between the current and previous value. Returns
 * null when no previous value is available (first report, no snapshot).
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
 * Latency -> 0-100 score for the Infrastructure Score. 100 up to 150 ms,
 * linearly falling to 40 at 1000 ms and beyond. null = nothing answered in
 * the period: it used to score a perfect 100, full credit for a latency
 * nobody measured (W1-B3).
 */
function bk_latency_score($avg_latency_ms): ?float {
    if ($avg_latency_ms === null) {
        return null;
    }
    if ($avg_latency_ms <= 150) {
        return 100.0;
    }
    if ($avg_latency_ms >= 1000) {
        return 40.0;
    }
    return 100 - (($avg_latency_ms - 150) / (1000 - 150)) * 60;
}

/**
 * Infrastructure Score (0-100) - our own heuristic, not a standardised
 * metric. Weights: availability 55 %, latency 20 %, incidents 15 %, certificates 10 %.
 * Easy to tune if the weights turn out not to match reality.
 *
 * An unmeasured part (availability or latency null) drops out and the
 * weights renormalise over the rest. Incidents and certificates alone say
 * nothing about health - "no incidents" is also what an empty period looks
 * like - so without availability AND latency the score is null, "not enough
 * data", never 100.
 */
function bk_infra_score($availability, $avg_latency_ms, $incident_count, $expiring_certs, $expired_certs): ?int {
    $latency = bk_latency_score($avg_latency_ms);
    if ($availability === null && $latency === null) {
        return null;
    }
    $parts = [
        [$availability !== null ? min(100, (float)$availability) : null, 0.55],
        [$latency, 0.20],
        [max(0, 100 - (int)$incident_count * 5), 0.15],
        [max(0, 100 - (int)$expiring_certs * 10 - (int)$expired_certs * 30), 0.10],
    ];
    $sum = 0.0;
    $weight = 0.0;
    foreach ($parts as [$value, $w]) {
        if ($value !== null) {
            $sum += $value * $w;
            $weight += $w;
        }
    }
    return (int)round($sum / $weight);
}

/**
 * Asset Overview - universal health score (0-100) for any monitor type.
 * Weights: uptime 30%, thresholds 30%, connectivity 20%, data freshness 20%.
 *
 * Every part that was not measured drops out and the weights renormalise over
 * the measured ones (W1-B3). Three of the four used to default to full marks:
 * a website has no cpu/ram/hdd (thresholds 100), a monitor that was never
 * checked has no timestamp (freshness 100), and 'unknown' earned half the
 * connectivity. Nothing measured at all -> null, which the page prints as
 * "nedostatek dat" instead of a score.
 *
 * @param array<string,mixed> $monitor
 * @param array<string,mixed> $details
 */
function bk_compute_asset_health_score($pdo, $monitor, array $details, $latest_metrics): ?int {
    $score = 0.0;
    $weight_used = 0.0;
    $add = function (?float $value, float $weight) use (&$score, &$weight_used): void {
        if ($value !== null) {
            $score += $value * $weight;
            $weight_used += $weight;
        }
    };

    // 1. Uptime (30%) - the last 30 days in time, the same number as the
    // badge and the SLA report (bk_uptime_30d); null without a measured second.
    $uptime_pct = null;
    if ($pdo instanceof PDO && isset($monitor['id'])) {
        $uptime_pct = bk_uptime_30d($pdo, (int)$monitor['id']);
    }
    $add($uptime_pct !== null ? min(100.0, $uptime_pct) : null, 0.30);

    // 2. Thresholds (30%) - CPU/RAM/HDD under their limits, the preset's
    // limits first (bk_monitor_thresholds), the documented defaults after.
    // Only the values the monitor actually reports take part; a type without
    // any of them (a website, a game server) has no threshold part at all.
    $thresholds = bk_monitor_thresholds($pdo instanceof PDO ? $pdo : null, $monitor);
    $measured = 0;
    $violations = 0;
    foreach (['cpu', 'ram', 'hdd'] as $key) {
        $value = $details[$key] ?? null;
        if (!is_numeric($value)) {
            continue;
        }
        $measured++;
        if ((float)$value > (float)($thresholds[$key] ?? BK_DEFAULT_THRESHOLDS[$key])) {
            $violations++;
        }
    }
    $add($measured > 0 ? (float)max(0, 100 - $violations * 33) : null, 0.30);

    // 4. Freshness (20%) - how long ago the agent/check reported. Without
    // any timestamp there is nothing to be fresh.
    $freshness = null;
    $last_seen = $details['agent_last_seen'] ?? null;
    if (is_numeric($last_seen) && (int)$last_seen > 0) {
        $age_min = (time() - (int)$last_seen) / 60;
        $freshness = $age_min > 30 ? 30.0 : ($age_min > 10 ? 60.0 : ($age_min > 5 ? 80.0 : 100.0));
    } elseif (!empty($monitor['last_checked']) && ($checked = strtotime((string)$monitor['last_checked'])) !== false) {
        $age_min = (time() - $checked) / 60;
        $freshness = $age_min > 30 ? 30.0 : ($age_min > 10 ? 60.0 : 100.0);
    }

    // 3. Connectivity (20%) - the current status. 'unknown' (never checked,
    // or an agent-side check whose agent went quiet) is not a measurement,
    // and neither is a status nothing ever reported (no timestamp at all):
    // that is the value the row was created with.
    $status_score = $freshness === null ? null : match ($monitor['status'] ?? null) {
        'up' => 100.0,
        'maintenance' => 80.0,
        'down', 'warning' => 0.0,
        default => null,
    };
    $add($status_score, 0.20);
    $add($freshness, 0.20);

    if ($weight_used <= 0.0) {
        return null;
    }
    // Renormalise over the actually measured components (0-100).
    return (int)round(min(100, max(0, $score / $weight_used)));
}

/**
 * A client count an agent sent: a whole number from 0 to 4096, or null.
 * More stations than any access point holds is a parser error, not a crowd.
 */
function bk_wifi_count($value): ?int {
    if (is_string($value) && preg_match('/^\d{1,6}$/', $value)) {
        $value = (int)$value;
    }
    return (is_int($value) && $value >= 0 && $value <= 4096) ? $value : null;
}

/**
 * Wi-Fi clients per band, summed over the radios an OpenWrt agent reports.
 *
 * Only the total used to be stored, so "how many were on 2.4 GHz" had no
 * answer once the next report replaced the radio list. Every agent already
 * sends band and clients per radio, so these sums need no agent update.
 *
 * Wi-Fi 6E comes from agent 0.1.6+ with hostapd_cli: clients_caps_known is how
 * many stations listed their operating classes, clients_6ghz_capable how many
 * of those listed a 6 GHz one. A band with no radio that knows stays null -
 * unknown, not zero - and a radio claiming more capable than known is ignored.
 * On 6 GHz itself every client supports it, so there is nothing to count.
 *
 * Agent 0.1.7 adds the air itself, over ACCESS-POINT radios only (a client or
 * mesh uplink measures someone else's network): per band the WORST noise and
 * the busiest channel with the foreign share of THAT radio - an average of a
 * quiet and a jammed radio would describe neither; two VAPs of one radio
 * report the same reading and a maximum counts it once by itself. Sums start
 * at the first radio that reported a value: all-null stays null, never 0.
 *
 * `wifi_6e_unserved` answers "are at least two 6 GHz-capable clients here
 * while no 6 GHz network is?" and is three-valued. A station that did not say
 * what it supports is UNKNOWN, so the answer is 0 only when even all unknown
 * stations together could not reach two - otherwise null, never a measured 0.
 *
 * @return array<string, int|float|null> keyed by the vps_metrics column
 */
function bk_wifi_band_totals($radios): array {
    $out = [
        'wifi_clients_24g' => null, 'wifi_clients_5g' => null, 'wifi_clients_6g' => null,
        'wifi_6e_capable_24g' => null, 'wifi_6e_known_24g' => null,
        'wifi_6e_capable_5g' => null, 'wifi_6e_known_5g' => null,
        'wifi_noise_24g' => null, 'wifi_noise_5g' => null, 'wifi_noise_6g' => null,
        'wifi_busy_24g' => null, 'wifi_busy_5g' => null, 'wifi_busy_6g' => null,
        'wifi_busy_other_24g' => null, 'wifi_busy_other_5g' => null, 'wifi_busy_other_6g' => null,
        'wifi_weak_clients' => null, 'wifi_wpa2_clients' => null,
        'wifi_6e_unserved' => null, 'wifi_5g_capable_24g' => null,
    ];
    if (!is_array($radios)) {
        return $out;
    }
    // Inputs of wifi_6e_unserved, over the 2.4 and 5 GHz access points.
    $ap_on_6g = false;
    $caps_reported = false;
    $capable_sum = 0;
    $unknown_sum = 0;
    $unknown_bounded = true;
    $suffix = ['2.4GHz' => '24g', '5GHz' => '5g', '6GHz' => '6g'];
    foreach ($radios as $radio) {
        if (!is_array($radio) || !is_string($radio['band'] ?? null) || !isset($suffix[$radio['band']])) {
            continue;
        }
        $band = $suffix[$radio['band']];
        // A band's sum starts at the first radio that reported a count; one
        // without a count leaves the sum as it is instead of starting it at zero.
        $add = function (string $key, int $value) use (&$out): void {
            $out[$key] = $out[$key] === null ? $value : $out[$key] + $value;
        };
        $clients = bk_wifi_count($radio['clients'] ?? null);
        if ($clients !== null) {
            $add("wifi_clients_{$band}", $clients);
        }
        $known = bk_wifi_count($radio['clients_caps_known'] ?? null);
        $capable = bk_wifi_count($radio['clients_6ghz_capable'] ?? null);
        // A radio claiming more capable than known stations is ignored.
        if ($known === null || $capable === null || $capable > $known) {
            $known_ok = null;
            $capable = null;
        } else {
            $known_ok = $known;
        }
        if ($band !== '6g' && $known_ok !== null && $capable !== null) {
            $add("wifi_6e_known_{$band}", $known_ok);
            $add("wifi_6e_capable_{$band}", $capable);
        }

        // `mode` is absent from agents up to 0.1.6, which report access points only.
        if (($radio['mode'] ?? 'ap') !== 'ap') {
            continue;
        }
        $noise = bk_ranged_num($radio['noise'] ?? null, -120.0, -20.0);
        if ($noise !== null && ($out["wifi_noise_{$band}"] === null || $noise > $out["wifi_noise_{$band}"])) {
            $out["wifi_noise_{$band}"] = $noise;
        }
        $busy = bk_ranged_num($radio['busy_pct'] ?? null, 0.0, 100.0);
        $busy_other = bk_ranged_num($radio['busy_other_pct'] ?? null, 0.0, 100.0);
        $busiest = $out["wifi_busy_{$band}"];
        // On a tie (two VAPs of one radio) the one that knows the foreign share wins.
        if ($busy !== null && ($busiest === null || $busy > $busiest || ($busy === $busiest && $out["wifi_busy_other_{$band}"] === null))) {
            $out["wifi_busy_{$band}"] = $busy;
            $out["wifi_busy_other_{$band}"] = $busy_other;
        }
        $weak = bk_wifi_count($radio['clients_weak'] ?? null);
        if ($weak !== null) {
            $add('wifi_weak_clients', $weak);
        }
        $wpa2 = bk_wifi_count($radio['clients_wpa2'] ?? null);
        if ($wpa2 !== null && bk_wifi_count($radio['clients_akm_known'] ?? null) !== null) {
            $add('wifi_wpa2_clients', $wpa2);
        }
        $on_5g = bk_wifi_count($radio['clients_5ghz_capable'] ?? null);
        if ($band === '24g' && $on_5g !== null && bk_wifi_count($radio['clients_opclass_known'] ?? null) !== null) {
            $add('wifi_5g_capable_24g', $on_5g);
        }

        if ($band === '6g') {
            $ap_on_6g = true;
            continue;
        }
        $caps_reported = $caps_reported || $known !== null;
        if ($capable !== null) {
            $capable_sum += $capable;
        }
        if ($known_ok !== null && $clients !== null) {
            $unknown_sum += max(0, $clients - $known_ok);
        } else {
            // Nothing bounds how many more capable clients this radio may hold.
            $unknown_bounded = false;
        }
    }
    if ($caps_reported) {
        if ($ap_on_6g) {
            $out['wifi_6e_unserved'] = 0;
        } elseif ($capable_sum >= 2) {
            // Unknown stations can only add to it.
            $out['wifi_6e_unserved'] = 1;
        } elseif ($unknown_bounded && $capable_sum + $unknown_sum < 2) {
            $out['wifi_6e_unserved'] = 0;
        }
    }
    return $out;
}

/**
 * A number an agent sent, inside [min, max] - or null.
 *
 * Out of range becomes NULL, never the bound: a noise floor of 0 dBm pulled
 * to -20 would be an invented measurement that a chart then draws as real.
 */
function bk_ranged_num($value, float $min, float $max): ?float {
    if (is_bool($value) || is_array($value) || $value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $num = (float)$value;
    return ($num >= $min && $num <= $max) ? $num : null;
}

/**
 * Whole-number variant of bk_ranged_num(): 3.5 is not a count and is null too.
 * Compared as integers - lifetime byte counters run past 2^53, where a float
 * would silently round them.
 */
function bk_ranged_int($value, int $min, int $max): ?int {
    if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value)) {
        $value = (int)$value;
    } elseif (is_float($value) && floor($value) === $value && abs($value) < 9.0e15) {
        $value = (int)$value;
    }
    return (is_int($value) && $value >= $min && $value <= $max) ? $value : null;
}

/**
 * Wi-Fi generation and channel width of a radio, read from its HT mode.
 *
 * The server derives them so that the rules, the digest and the app all read
 * the same numbers; the app only formats them. `supported_*` is what the card
 * can do ON THIS BAND: iwinfo lists the modes of the whole phy, and a 2.4 GHz
 * radio that lists VHT80 must never be told "your card can do Wi-Fi 5 at
 * 80 MHz" - 2.4 GHz has no VHT and no channel wider than 40 MHz.
 *
 * @param array<string, mixed> $r a sanitized radio (htmode, htmodes_supported, band)
 * @return array{generation: ?int, width_mhz: ?int, supported_generation: ?int, supported_width_mhz: ?int}
 */
function bk_wifi_radio_profile(array $r): array {
    $gen_of = ['HT' => 4, 'VHT' => 5, 'HE' => 6, 'EHT' => 7];
    // [generation, width] of one mode string, or null when it is not a mode.
    $parse = function ($mode) use ($gen_of): ?array {
        if ($mode === 'NOHT') {
            return [0, 20];
        }
        if (!is_string($mode) || !preg_match('/^(HT|VHT|HE|EHT)(\d{2,3})(\+80)?$/', $mode, $m)) {
            return null;
        }
        // VHT80+80 is two 80 MHz segments: 160 MHz of air.
        return [$gen_of[$m[1]], isset($m[3]) ? 160 : (int)$m[2]];
    };
    $out = ['generation' => null, 'width_mhz' => null, 'supported_generation' => null, 'supported_width_mhz' => null];
    $current = $parse($r['htmode'] ?? null);
    if ($current !== null) {
        [$out['generation'], $out['width_mhz']] = $current;
    }
    // Without a band nothing can be said about what the card could do on it.
    $limits = ['2.4GHz' => [[0, 4, 6, 7], 40], '5GHz' => [[0, 4, 5, 6, 7], 160], '6GHz' => [[6, 7], 320]];
    $band = $r['band'] ?? null;
    if (!is_string($band) || !isset($limits[$band]) || !is_array($r['htmodes_supported'] ?? null)) {
        return $out;
    }
    [$allowed_gens, $max_width] = $limits[$band];
    foreach ($r['htmodes_supported'] as $mode) {
        $parsed = $parse($mode);
        if ($parsed === null || !in_array($parsed[0], $allowed_gens, true)) {
            continue;
        }
        $out['supported_generation'] = max($out['supported_generation'] ?? 0, $parsed[0]);
        $out['supported_width_mhz'] = max($out['supported_width_mhz'] ?? 0, min($parsed[1], $max_width));
    }
    return $out;
}

/**
 * The `wifi_radios` list of an OpenWrt agent, validated before it is stored.
 *
 * It used to go into last_details exactly as sent. Rules now read it, so:
 *  - only the keys of the contract survive (a BSSID or a MAC an agent might
 *    send one day never reaches the database);
 *  - a value out of range is NULL, not the bound (see bk_ranged_num());
 *  - only keys the agent SENT are copied. The app tells "an older agent" (no
 *    key, no line) from "the router could not tell" (key = null, a hint to
 *    install hostapd-utils) by the presence of the key, so filling absent
 *    keys with null would show that hint on every 0.1.6 router;
 *  - the band follows the frequency. Agents up to 0.1.6 defaulted a disabled
 *    radio to "2.4GHz"; without a frequency their word counts only together
 *    with a channel.
 *
 * @return ?array<int, array<string, mixed>> null when the agent sent no list
 */
function bk_sanitize_wifi_radios($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $htmode_re = '/^(NOHT|(HT|VHT|HE|EHT)\d{2,3}(\+80)?)$/';
    $enums = [
        'mode' => ['ap', 'client', 'mesh', 'other'],
        'encryption' => ['open', 'owe', 'wep', 'wpa', 'wpa_wpa2', 'wpa2', 'wpa2_wpa3', 'wpa3'],
        'busy_state' => ['measured', 'warming_up', 'unsupported', 'not_installed'],
    ];
    // key => [min, max]; whole numbers unless listed in $decimals.
    $ranges = [
        'channel' => [1, 233], 'tx_power' => [0, 40], 'noise' => [-120, -20],
        'signal_median' => [-120, -1], 'signal_min' => [-120, -1], 'snr_min' => [0, 100],
        'bitrate_tx_avg_mbps' => [0, 50000], 'busy_pct' => [0, 100], 'busy_other_pct' => [0, 100],
    ];
    $decimals = ['bitrate_tx_avg_mbps', 'busy_pct', 'busy_other_pct'];
    $counts = ['clients', 'clients_weak', 'clients_caps_known', 'clients_6ghz_capable', 'clients_opclass_known',
        'clients_5ghz_capable', 'clients_akm_known', 'clients_wpa2', 'clients_wpa3', 'clients_8021x'];

    $out = [];
    foreach ($raw as $item) {
        if (count($out) >= 16) {
            break;
        }
        if (!is_array($item) || !is_string($item['radio'] ?? null) || !preg_match('/^[A-Za-z0-9._-]{1,32}$/', $item['radio'])) {
            continue;
        }
        $r = ['radio' => $item['radio']];
        foreach ($ranges as $key => [$min, $max]) {
            if (array_key_exists($key, $item)) {
                $r[$key] = in_array($key, $decimals, true)
                    ? bk_ranged_num($item[$key], (float)$min, (float)$max)
                    : bk_ranged_int($item[$key], $min, $max);
            }
        }
        foreach ($counts as $key) {
            if (array_key_exists($key, $item)) {
                $r[$key] = bk_wifi_count($item[$key]);
            }
        }
        foreach ($enums as $key => $allowed) {
            if (array_key_exists($key, $item)) {
                $r[$key] = in_array($item[$key], $allowed, true) ? $item[$key] : null;
            }
        }
        foreach (['phy_has_6ghz', 'encryption_enterprise'] as $key) {
            if (array_key_exists($key, $item)) {
                $r[$key] = is_bool($item[$key]) ? $item[$key] : null;
            }
        }
        if (array_key_exists('ssid', $item)) {
            $ssid = is_string($item['ssid']) ? (string)preg_replace('/[\x00-\x1F\x7F]/', '', $item['ssid']) : '';
            $r['ssid'] = $ssid !== '' ? mb_substr($ssid, 0, 64) : null;
        }
        if (array_key_exists('phy', $item)) {
            $r['phy'] = (is_string($item['phy']) && preg_match('/^phy\d{1,2}$/', $item['phy'])) ? $item['phy'] : null;
        }
        if (array_key_exists('htmode', $item)) {
            $r['htmode'] = (is_string($item['htmode']) && preg_match($htmode_re, $item['htmode'])) ? $item['htmode'] : null;
        }
        if (array_key_exists('htmodes_supported', $item)) {
            $modes = is_array($item['htmodes_supported'])
                ? array_filter($item['htmodes_supported'], fn($m) => is_string($m) && preg_match($htmode_re, $m))
                : null;
            $r['htmodes_supported'] = $modes === null ? null : array_slice(array_values(array_unique($modes)), 0, 24);
        }
        if (array_key_exists('weakest_gen', $item)) {
            $r['weakest_gen'] = in_array($item['weakest_gen'], [0, 4, 5, 6, 7], true) ? $item['weakest_gen'] : null;
        }
        // (0, 50000]: a mean TX rate of exactly 0 Mbit/s is not a measurement.
        if (($r['bitrate_tx_avg_mbps'] ?? null) === 0.0) {
            $r['bitrate_tx_avg_mbps'] = null;
        }
        // Foreign traffic is a part of the busy time; more than the whole, or a
        // part of an unknown whole, is not a reading.
        if (isset($r['busy_other_pct']) && (!isset($r['busy_pct']) || $r['busy_other_pct'] > $r['busy_pct'])) {
            $r['busy_other_pct'] = null;
        }

        $frequency = array_key_exists('frequency_mhz', $item) ? bk_ranged_int($item['frequency_mhz'], 2400, 7125) : null;
        if (array_key_exists('frequency_mhz', $item)) {
            $r['frequency_mhz'] = $frequency;
        }
        $r['band'] = bk_wifi_band_of($frequency, $item['band'] ?? null, $r['channel'] ?? null);

        if (array_key_exists('clients_gen', $item)) {
            $gen = $item['clients_gen'];
            $r['clients_gen'] = null;
            if (is_array($gen) && in_array($gen['source'] ?? null, ['hostapd_cli', 'ubus'], true)) {
                $r['clients_gen'] = ['source' => $gen['source']];
                foreach (['legacy', 'wifi4', 'wifi5', 'wifi6', 'wifi7'] as $key) {
                    $r['clients_gen'][$key] = bk_wifi_count($gen[$key] ?? null);
                }
                // Over ubus an EHT station cannot be told from an HE one:
                // "wifi6" means "6 or newer" and a wifi7 count would be invented.
                if ($gen['source'] === 'ubus') {
                    $r['clients_gen']['wifi7'] = null;
                }
            }
        }
        // A part larger than its whole discredits both numbers.
        foreach ([['clients_6ghz_capable', 'clients_caps_known'], ['clients_5ghz_capable', 'clients_opclass_known']] as [$part, $whole]) {
            if (isset($r[$part]) && (!isset($r[$whole]) || $r[$part] > $r[$whole])) {
                $r[$part] = null;
                if (array_key_exists($whole, $r)) {
                    $r[$whole] = null;
                }
            }
        }
        $akm_parts = ['clients_wpa2', 'clients_wpa3', 'clients_8021x'];
        $akm_sum = array_sum(array_map(fn($k) => $r[$k] ?? 0, $akm_parts));
        if ($akm_sum > 0 && (!isset($r['clients_akm_known']) || $akm_sum > $r['clients_akm_known'])) {
            foreach (array_merge($akm_parts, ['clients_akm_known']) as $key) {
                if (array_key_exists($key, $r)) {
                    $r[$key] = null;
                }
            }
        }
        $out[] = array_merge($r, bk_wifi_radio_profile($r));
    }
    return $out;
}

/**
 * Band of a radio: from the frequency only (2400-2499 / 5150-5924 / 5925-7125
 * MHz). Without a valid frequency the agent's own word is kept only when it
 * names a known band AND the radio has a channel - a disabled radio of an
 * agent up to 0.1.6 claimed "2.4GHz" by default.
 */
function bk_wifi_band_of(?int $frequency_mhz, $agent_band, ?int $channel): ?string {
    if ($frequency_mhz !== null) {
        if ($frequency_mhz >= 2400 && $frequency_mhz < 2500) {
            return '2.4GHz';
        }
        if ($frequency_mhz >= 5150 && $frequency_mhz < 5925) {
            return '5GHz';
        }
        return ($frequency_mhz >= 5925 && $frequency_mhz <= 7125) ? '6GHz' : null;
    }
    $known = in_array($agent_band, ['2.4GHz', '5GHz', '6GHz'], true);
    return ($known && $channel !== null) ? $agent_band : null;
}

/**
 * Identity of a physical disk on one router: transport, port, the 16-character
 * sysfs model and the size. NEVER a serial number or a WWN - neither may leave
 * the router. The fuller model smartctl reads is display only and not part of
 * the key, so the key is the same before and after the first SMART reading.
 *
 * @param array<string, mixed> $disk a sanitized disk
 */
function bk_disk_key(array $disk): string {
    return substr(sha1(implode('|', [
        (string)($disk['transport'] ?? ''), (string)($disk['port'] ?? ''),
        (string)($disk['model'] ?? ''), (string)($disk['size_bytes'] ?? ''),
    ])), 0, 16);
}

/**
 * The `storage_disks` list of an OpenWrt agent (0.1.7+), validated.
 *
 * An allow-list on every level: anything else is dropped, and a key whose
 * name smells of an identifier (serial, WWN, EUI, GUID, CID) is dropped even
 * if someone later puts it on the list. Out of range = null (bk_ranged_int);
 * only counts and lengths are capped.
 *
 * @return ?array<int, array<string, mixed>> null when the agent sent no list
 */
function bk_sanitize_storage_disks($raw, int $now): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $never = '/serial|wwn|eui|guid|cid/i';
    $text = function ($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim(mb_substr((string)preg_replace('~[^A-Za-z0-9 ._()+/-]~', '', $value), 0, 64));
        return $clean !== '' ? $clean : null;
    };
    $bool = fn ($value): ?bool => is_bool($value) ? $value : null;
    $smart_ints = [
        'exit_bits' => [0, 255], 'rotation_rpm' => [0, 30000],
        // 0 °C and below is a sensor that answers nothing, not a cold disk.
        'temperature_c' => [1, 125],
        'power_on_hours' => [0, 1000000], 'power_cycles' => [0, 10000000], 'unsafe_shutdowns' => [0, 10000000],
        'reallocated_sectors' => [0, 1000000000], 'pending_sectors' => [0, 1000000000],
        'offline_uncorrectable' => [0, 1000000000], 'reported_uncorrect' => [0, 1000000000],
        'crc_errors' => [0, 1000000000], 'runtime_bad_blocks' => [0, 1000000000],
        'media_errors' => [0, 2 ** 53], 'critical_warning' => [0, 255], 'available_spare_pct' => [0, 100],
        'wear_pct' => [0, 255], 'written_bytes' => [0, 2 ** 60],
        'error_log_count' => [0, 65535], 'selftest_count' => [0, 65535],
    ];
    $smart_enums = [
        'protocol' => ['ATA', 'NVMe', 'SCSI'],
        'wear_source' => ['devstat', 'attr231', 'attr169', 'attr202', 'attr233', 'attr177', 'nvme'],
        'written_source' => ['devstat', 'nvme', 'attr241'],
    ];
    $states = ['ok', 'failing', 'standby', 'idle_skipped', 'pending', 'not_installed', 'unsupported', 'error', 'stuck', 'not_applicable'];

    $out = [];
    foreach ($raw as $item) {
        if (count($out) >= 8) {
            break;
        }
        if (!is_array($item) || !is_string($item['name'] ?? null)
            || !preg_match('/^(sd[a-z]{1,2}|hd[a-z]|vd[a-z]|nvme\d{1,2}n\d{1,2}|mmcblk\d{1,2})$/', $item['name'])) {
            continue;
        }
        $transport = $item['transport'] ?? null;
        $port = $item['port'] ?? null;
        $disk = [
            'name' => $item['name'],
            'transport' => in_array($transport, ['sata', 'usb', 'nvme', 'emmc', 'sd', 'virtio', 'other'], true) ? $transport : 'other',
            'port' => (is_string($port) && preg_match('/^[A-Za-z0-9.:-]{1,32}$/', $port)) ? $port : null,
            'model' => $text($item['model'] ?? null),
            'size_bytes' => bk_ranged_int($item['size_bytes'] ?? null, 1, 2 ** 50),
            'rotational' => $bool($item['rotational'] ?? null),
            'removable' => $bool($item['removable'] ?? null),
            'partitions' => [],
            'emmc' => null,
        ];
        foreach (is_array($item['partitions'] ?? null) ? $item['partitions'] : [] as $part) {
            if (count($disk['partitions']) >= 16) {
                break;
            }
            if (!is_array($part) || !is_string($part['name'] ?? null) || !preg_match('/^[a-z0-9]{1,24}$/', $part['name'])) {
                continue;
            }
            $mount = $part['mount'] ?? null;
            $fstype = $part['fstype'] ?? null;
            $disk['partitions'][] = [
                'name' => $part['name'],
                'size_bytes' => bk_ranged_int($part['size_bytes'] ?? null, 1, 2 ** 50),
                'mount' => (is_string($mount) && $mount !== '' && $mount[0] === '/' && strlen($mount) <= 128 && !preg_match('/[\x00-\x1F\x7F]/', $mount)) ? $mount : null,
                'fstype' => (is_string($fstype) && preg_match('/^[a-z0-9_.-]{1,16}$/', $fstype)) ? $fstype : null,
                'used_pct' => bk_ranged_num($part['used_pct'] ?? null, 0.0, 100.0),
            ];
        }
        if (is_array($item['emmc'] ?? null)) {
            $disk['emmc'] = [
                'life_a' => bk_ranged_int($item['emmc']['life_a'] ?? null, 1, 11),
                'life_b' => bk_ranged_int($item['emmc']['life_b'] ?? null, 1, 11),
                'pre_eol' => bk_ranged_int($item['emmc']['pre_eol'] ?? null, 1, 3),
            ];
        }
        $raw_smart = is_array($item['smart'] ?? null) ? $item['smart'] : [];
        $smart = [
            // A state the server does not know is a failed reading, not a healthy one.
            'state' => in_array($raw_smart['state'] ?? null, $states, true) ? $raw_smart['state'] : 'error',
            'checked_at' => bk_ranged_int($raw_smart['checked_at'] ?? null, $now - 400 * 86400, $now + 86400),
            'passed' => $bool($raw_smart['passed'] ?? null),
            'in_drivedb' => $bool($raw_smart['in_drivedb'] ?? null),
            'model' => $text($raw_smart['model'] ?? null),
        ];
        foreach ($smart_ints as $key => [$min, $max]) {
            $smart[$key] = bk_ranged_int($raw_smart[$key] ?? null, $min, $max);
        }
        foreach ($smart_enums as $key => $allowed) {
            $smart[$key] = in_array($raw_smart[$key] ?? null, $allowed, true) ? $raw_smart[$key] : null;
        }
        $disk['smart'] = $smart;
        // Belt and braces: the lists above name no identifier today, and this
        // keeps it so for whoever extends them.
        foreach (['smart', 'emmc'] as $nested) {
            if (is_array($disk[$nested])) {
                $disk[$nested] = array_filter($disk[$nested], fn ($k) => !preg_match($never, (string)$k), ARRAY_FILTER_USE_KEY);
            }
        }
        $disk = array_filter($disk, fn ($k) => !preg_match($never, (string)$k), ARRAY_FILTER_USE_KEY);
        $disk['key'] = bk_disk_key($disk);
        $out[] = $disk;
    }
    return $out;
}

/**
 * The `agent_tools` object: which optional programs the router has. Strict
 * booleans - "1" or "yes" is not an answer a rule may act on.
 *
 * @return ?array<string, mixed> null when the agent sent no object
 */
function bk_sanitize_agent_tools($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $out = [];
    foreach (['smartctl', 'smart_drivedb', 'hostapd_cli', 'iw', 'librespeed_cli', 'ethtool', 'tc'] as $key) {
        $out[$key] = is_bool($raw[$key] ?? null) ? $raw[$key] : null;
    }
    $out['pkg_manager'] = in_array($raw['pkg_manager'] ?? null, ['opkg', 'apk'], true) ? $raw['pkg_manager'] : null;
    foreach (['smart_probe_age_s', 'smart_probe_running_s'] as $key) {
        $out[$key] = bk_ranged_int($raw[$key] ?? null, 0, 10000000);
    }
    return $out;
}

/**
 * Temperature limit of a disk's class: 60 °C spinning, 80 °C NVMe, 70 °C
 * everything else. Mirrors `lib/disk-health.ts:tempClassLimit` - the card and
 * the alert must never disagree about what "too hot" means.
 */
function bk_disk_temp_limit(array $disk): int {
    $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
    $rpm = $smart['rotation_rpm'] ?? null;
    // The rotation speed wins over sysfs; never guessed from the model.
    $spinning = $rpm !== null ? ($rpm > 0) : (is_bool($disk['rotational'] ?? null) ? $disk['rotational'] : null);
    if ($spinning === true) {
        return 60;
    }
    if (($disk['transport'] ?? null) === 'nvme' || ($smart['protocol'] ?? null) === 'NVMe') {
        return 80;
    }
    return 70;
}

/** The alert latches of one disk, with every field present. */
function bk_storage_alert_state($raw): array {
    $raw = is_array($raw) ? $raw : [];
    $base = is_array($raw['base'] ?? null) ? $raw['base'] : [];
    $int_or_null = function ($v): ?int {
        return is_int($v) ? $v : (is_float($v) && $v == (int)$v ? (int)$v : null);
    };
    // A counter of readings, not a measurement: "no consecutive reading yet"
    // really is zero. Written without `?? 0` so the honesty lint, which reads
    // "temp" as a measured quantity, does not have to carry an exception.
    $counted = fn ($v): int => is_int($v) && $v > 0 ? $v : 0;
    $state = [
        'failed' => !empty($raw['failed']),
        'failed_sent_at' => $int_or_null($raw['failed_sent_at'] ?? null),
        'ok_streak' => $counted($raw['ok_streak'] ?? null),
        'base' => [],
        'sectors_sent_at' => $int_or_null($raw['sectors_sent_at'] ?? null),
        'wear_step' => $counted($raw['wear_step'] ?? null),
        'temp_hot' => !empty($raw['temp_hot']),
        'temp_streak' => $counted($raw['temp_streak'] ?? null),
        'eol' => $int_or_null($raw['eol'] ?? null),
        // Power-on hours of the last evaluated reading. A drop of more than
        // 48 h is another drive in the same slot (3.4 step 3), and its
        // history must not be measured against the old one's baselines.
        'hours' => $int_or_null($raw['hours'] ?? null),
    ];
    foreach (bk_disk_error_counters() as $short => $ignored) {
        // null = never seen. 0 would claim "the disk reported zero errors",
        // which is what makes a first sight alert instead of storing a baseline.
        $state['base'][$short] = $int_or_null($base[$short] ?? null);
    }
    return $state;
}

/** The six SMART error counters that `disk_errors_growing` watches: short name => [smart key, Czech name]. */
function bk_disk_error_counters(): array {
    return [
        'realloc' => ['reallocated_sectors', 'přemapované sektory'],
        'pending' => ['pending_sectors', 'čekající sektory'],
        'offline' => ['offline_uncorrectable', 'neopravitelné sektory'],
        'reported' => ['reported_uncorrect', 'neopravitelné chyby'],
        'badblk' => ['runtime_bad_blocks', 'vadné bloky'],
        'media' => ['media_errors', 'chyby média'],
    ];
}

/** `Kingston SUV500 (sda)`, or just `(sda)` when the router never read a model. */
function bk_disk_label(array $disk): string {
    $model = $disk['smart']['model'] ?? ($disk['model'] ?? null);
    $name = (string)($disk['name'] ?? '?');
    return is_string($model) && $model !== '' ? $model . ' (' . $name . ')' : '(' . $name . ')';
}

/**
 * The disk alert rules of CORE 3.5. Pure: it is handed the FRESH readings
 * (3.4 step 5 - a reading whose guarded UPDATE changed a row, so the same
 * SMART reading can never be evaluated twice) and the stored latches, and it
 * answers the new latches plus the events to send.
 *
 * Hysteresis everywhere, because a disk sits at its limit for months: a
 * temperature alerts on the second reading and clears five degrees lower, a
 * SMART failure alerts at most once a day and needs three clean readings to
 * clear, and a counter's baseline rises only when an alert really went out.
 *
 * @param list<array<string, mixed>> $fresh_disks sanitized disks, each with `key`
 * @param array<string, mixed> $states disk key => stored alert_state
 * @return array{states: array<string, array<string, mixed>>, events: list<array<string, string>>}
 */
function bk_storage_alert_eval(array $fresh_disks, array $states, int $now): array {
    $out_states = [];
    $events = [];
    foreach ($fresh_disks as $disk) {
        if (!is_array($disk) || !is_string($disk['key'] ?? null) || $disk['key'] === '') {
            continue;
        }
        $key = $disk['key'];
        $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
        $st = bk_storage_alert_state($states[$key] ?? null);
        $label = bk_disk_label($disk);
        $name = (string)($disk['name'] ?? '?');

        // Another drive in the same slot: the power-on hours went backwards by
        // more than the 48 h a clock or a firmware rounding can explain. Its
        // baselines and latches describe a disk that is no longer here.
        $hours = $smart['power_on_hours'] ?? null;
        if ($hours !== null && $st['hours'] !== null && $hours < $st['hours'] - 48) {
            $st = bk_storage_alert_state(null);
        }
        if ($hours !== null) {
            $st['hours'] = $hours;
        }

        $cw = $smart['critical_warning'] ?? null;
        $bits = $smart['exit_bits'] ?? null;
        // Bit 3 = the disk failed in the past, bit 4 = it is failing now.
        $bits_bad = $bits !== null && ($bits & 0x18) !== 0;
        $cw_bad = $cw !== null && ($cw & 0x3D) !== 0;
        $failing = ($smart['state'] ?? null) === 'failing' || $bits_bad || $cw_bad;
        $clean = !$failing && ($smart['passed'] ?? null) === true;

        if ($failing) {
            $st['ok_streak'] = 0;
            // At most one page a day per disk: a prefail attribute that
            // crosses its threshold back and forth on a warm disk would
            // otherwise send an hourly failing/recovered pair.
            if ($st['failed_sent_at'] === null || ($now - $st['failed_sent_at']) >= 86400) {
                $events[] = bk_storage_alert_event($key, 'disk_smart_failed', 'storage_failing',
                    sprintf('Disk %s hlásí selhání SMART. Zálohujte data a disk vyměňte.', $label));
                $st['failed_sent_at'] = $now;
            }
            $st['failed'] = true;
        } elseif ($clean) {
            if ($st['failed']) {
                $st['ok_streak']++;
                // Three consecutive clean readings: one is a re-read of the
                // same attribute set, and the disk must prove itself.
                if ($st['ok_streak'] >= 3) {
                    $events[] = bk_storage_alert_event($key, 'disk_smart_ok', 'storage_recovered',
                        sprintf('Disk %s je podle SMART opět v pořádku.', $label));
                    $st['failed'] = false;
                    $st['failed_sent_at'] = null;
                    $st['ok_streak'] = 0;
                }
            }
        } else {
            // Neither a failure nor a pass (standby, unreadable): the streak
            // of CONSECUTIVE clean readings is broken, nothing is claimed.
            $st['ok_streak'] = 0;
        }

        [$st, $counter_events] = bk_disk_counter_rules($st, $disk, $smart, $label, $key, $now);
        $events = array_merge($events, $counter_events);
        [$st, $wear_events] = bk_disk_wear_rules($st, $disk, $smart, $label, $name, $key);
        $events = array_merge($events, $wear_events);

        $temp = $smart['temperature_c'] ?? null;
        if ($temp !== null) {
            $limit = bk_disk_temp_limit($disk);
            if ($temp >= $limit) {
                $st['temp_streak']++;
                if (!$st['temp_hot'] && $st['temp_streak'] >= 2) {
                    $events[] = bk_storage_alert_event($key, 'disk_temp_critical', 'storage_warning',
                        sprintf('Disk %s má %d °C (limit %d °C).', $label, $temp, $limit));
                    $st['temp_hot'] = true;
                }
            } elseif ($temp <= $limit - 5) {
                $st['temp_streak'] = 0;
                if ($st['temp_hot']) {
                    $events[] = bk_storage_alert_event($key, 'disk_temp_normal', 'storage_recovered',
                        sprintf('Disk %s zchladl na %d °C.', $label, $temp));
                    $st['temp_hot'] = false;
                }
            } else {
                // Inside the five-degree band: the alert neither fires nor
                // clears, or a disk idling one degree under its limit would
                // notify every hour.
                $st['temp_streak'] = 0;
            }
        }

        $out_states[$key] = $st;
    }
    return ['states' => $out_states, 'events' => $events];
}

/** One event of the disk rules. The message is operator Czech and fits monitor_events.description. */
function bk_storage_alert_event(string $disk_key, string $type, string $status, string $message): array {
    return [
        'disk' => $disk_key,
        'type' => $type,
        'status' => $status,
        'message' => mb_substr($message, 0, 255),
    ];
}

/**
 * Rule `disk_errors_growing` (CORE 3.5) for one disk.
 *
 * The baselines live in `$st['base']`: null = never seen, so a disk that has
 * carried three bad blocks since the day it was bought stays quiet, while a
 * counter that is ALREADY non-zero at first sight and means data loss
 * (pending or offline uncorrectable sectors) pages at once.
 *
 * A baseline rises only together with the alert that reported it. Without
 * that, growth during the 24 h cooldown would be swallowed: the counter would
 * be "not above the baseline" by the time the disk is allowed to alert again.
 *
 * @return array{0: array<string, mixed>, 1: list<array<string, string>>}
 */
function bk_disk_counter_rules(array $st, array $disk, array $smart, string $label, string $key, int $now): array {
    // Sectors that can no longer be read are lost data; a remapped sector or a
    // bad block is the drive doing its job. That is the whole split.
    $severe = ['pending', 'offline', 'reported', 'media'];
    $grown = [];
    $bad = false;
    $first_sight_bad = [];
    foreach (bk_disk_error_counters() as $short => [$smart_key, $cs_name]) {
        $cur = $smart[$smart_key] ?? null;
        if ($cur === null) {
            continue; // not read this time - the baseline keeps what it knows
        }
        $base = $st['base'][$short];
        if ($base === null) {
            $st['base'][$short] = $cur;
            if ($cur > 0 && in_array($short, ['pending', 'offline'], true)) {
                $first_sight_bad[] = sprintf('%s %d', $cs_name, $cur);
            }
            continue;
        }
        if ($cur < $base) {
            // A counter that falls is a firmware reset or a new drive database;
            // claiming an improvement would be as dishonest as claiming growth.
            $st['base'][$short] = $cur;
            continue;
        }
        if ($cur > $base) {
            $grown[] = sprintf('%s %d → %d', $cs_name, $base, $cur);
            $bad = $bad || in_array($short, $severe, true);
        }
    }

    $events = [];
    if ($first_sight_bad !== []) {
        // Not growth but a state: the disk already has unreadable sectors.
        $events[] = bk_storage_alert_event($key, 'disk_errors_growing', 'storage_failing',
            sprintf('Disk %s: %s.', $label, implode(', ', $first_sight_bad)));
        $st['sectors_sent_at'] = $now;
        return [$st, $events];
    }
    if ($grown === []) {
        return [$st, $events];
    }
    if ($st['sectors_sent_at'] !== null && ($now - $st['sectors_sent_at']) < 86400) {
        // Still in the cooldown: the baselines stay where they are, so the
        // growth is reported in full by the first alert after it.
        return [$st, $events];
    }
    $events[] = bk_storage_alert_event($key, 'disk_errors_growing',
        $bad ? 'storage_failing' : 'storage_warning',
        sprintf('Disk %s: %s.', $label, implode(', ', $grown)));
    $st['sectors_sent_at'] = $now;
    foreach (bk_disk_error_counters() as $short => [$smart_key, $ignored]) {
        $cur = $smart[$smart_key] ?? null;
        if ($cur !== null) {
            $st['base'][$short] = $cur;
        }
    }
    return [$st, $events];
}

/**
 * Rules `disk_wear_high` and `disk_emmc_eol` (CORE 3.5) for one disk.
 *
 * `wear_step` is the coarse ten-percent step the last warning went out at, so
 * a drive that ages from 90 % to 95 % over a year does not warn again; it warns
 * once more when it reaches 100 %. eMMC has no wear percentage, only the JEDEC
 * life registers (one step = 10 % consumed) and pre-EOL.
 *
 * @return array{0: array<string, mixed>, 1: list<array<string, string>>}
 */
function bk_disk_wear_rules(array $st, array $disk, array $smart, string $label, string $name, string $key): array {
    $events = [];
    $emmc = is_array($disk['emmc'] ?? null) ? $disk['emmc'] : [];
    $lives = array_filter([$emmc['life_a'] ?? null, $emmc['life_b'] ?? null], fn ($v) => $v !== null);
    $life = $lives === [] ? null : max($lives);
    $pre_eol = $emmc['pre_eol'] ?? null;

    $step = 0;
    $message = null;
    $wear = $smart['wear_pct'] ?? null;
    if ($wear !== null && $wear >= 90) {
        $step = min(100, intdiv($wear, 10) * 10);
        $message = sprintf('Opotřebení disku %s dosáhlo %d %%.', $label, $wear);
    } elseif ($life !== null && $life >= 10) {
        // life 10 = 90-100 % of the rated write cycles are gone, 11 = over it.
        $step = min(100, ($life - 1) * 10);
        $message = sprintf('Opotřebení disku %s dosáhlo %d %%.', $label, $step);
    } elseif ($pre_eol === 2) {
        // JEDEC: pre-EOL 2 means 80 % of the reserve blocks are consumed.
        $step = 80;
        $message = sprintf('eMMC %s: rezervní bloky z 80 %% spotřebované.', $name);
    }
    if ($message !== null && $step > $st['wear_step']) {
        $events[] = bk_storage_alert_event($key, 'disk_wear_high', 'storage_warning', $message);
        $st['wear_step'] = $step;
    }

    if ($pre_eol === 3) {
        if ($st['eol'] !== 3) {
            $events[] = bk_storage_alert_event($key, 'disk_emmc_eol', 'storage_failing',
                sprintf('eMMC %s: rezervní bloky jsou téměř vyčerpané.', $name));
            $st['eol'] = 3;
        }
    } elseif ($pre_eol === 1) {
        // Back to normal only on the value the JEDEC register uses for it,
        // which in practice means the card was swapped. There is no
        // "eMMC recovered" event: a chip does not heal, so an automatic
        // all-clear would be a claim nobody measured.
        $st['eol'] = null;
    }
    return [$st, $events];
}

/**
 * Rule `fs_full` / `fs_freed` (CORE 3.5) over the agent's `filesystems[]`.
 *
 * Pure. The state lives in `last_details.fs_alerts[mount]` and is protected
 * from the 60 kB cap, because losing it would re-send every alert.
 *
 * The root and the read-only OpenWrt images are left out: `/` already has the
 * legacy `hdd` alert and a squashfs is 100 % full by construction, which is
 * what it is FOR. A tiny partition (`/boot`) crosses any percentage on one
 * kernel image, so it is left out by size rather than by a guessed name.
 *
 * `$now` is not in CORE 3.5's signature; without it the "unseen for 7 days"
 * rule cannot be applied and the state would grow with every mount a USB disk
 * ever had. Defaults to the wall clock, so the contract's call still works.
 *
 * @param array<string, mixed> $fs_state previous `fs_alerts`
 * @return array{state: array<string, array<string, mixed>>, events: list<array<string, string>>}
 */
function bk_fs_alert_eval($filesystems, $fs_state, float $threshold, ?int $now = null): array {
    $now = $now ?? time();
    $fs_state = is_array($fs_state) ? $fs_state : [];
    $state = [];
    $events = [];
    $skip_mounts = ['/', '/overlay', '/rom'];
    $skip_fstypes = ['squashfs', 'iso9660', 'erofs', 'romfs', 'cramfs'];

    foreach (is_array($filesystems) ? $filesystems : [] as $fs) {
        if (count($state) >= 32) {
            break;
        }
        if (!is_array($fs) || !is_string($fs['mount'] ?? null) || $fs['mount'] === '') {
            continue;
        }
        $mount = $fs['mount'];
        if (in_array($mount, $skip_mounts, true) || in_array($fs['fstype'] ?? null, $skip_fstypes, true)) {
            continue;
        }
        $total_kb = $fs['total_kb'] ?? null;
        if (!is_int($total_kb) && !is_float($total_kb)) {
            continue;
        }
        if ($total_kb < 65536) {
            continue;
        }
        $used = $fs['used_pct'] ?? null;
        if (!is_int($used) && !is_float($used)) {
            continue;
        }
        $used = (float)$used;

        $prev = is_array($fs_state[$mount] ?? null) ? $fs_state[$mount] : [];
        $streak = max(0, (int)($prev['streak'] ?? 0));
        $sent = !empty($prev['sent']);
        // The admin lowering the limit must be able to alert again, and raising
        // it must not leave a stale "full" latch behind - the same reset the
        // hdd alert does at agent_api.php:458.
        if (isset($prev['threshold']) && (float)$prev['threshold'] !== $threshold) {
            $streak = 0;
            $sent = false;
        }

        if ($used >= $threshold) {
            $streak++;
            if (!$sent && $streak >= 2) {
                $events[] = [
                    'mount' => $mount,
                    'type' => 'fs_full',
                    'status' => 'storage_warning',
                    'message' => mb_substr(sprintf('Oddíl %s je zaplněný z %d %% (limit %d %%).',
                        $mount, (int)round($used), (int)round($threshold)), 0, 255),
                ];
                $sent = true;
            }
        } elseif ($used <= $threshold - 5) {
            $streak = 0;
            if ($sent) {
                $events[] = [
                    'mount' => $mount,
                    'type' => 'fs_freed',
                    'status' => 'storage_recovered',
                    'message' => mb_substr(sprintf('Na oddílu %s je zase místo, zaplněný je z %d %%.',
                        $mount, (int)round($used)), 0, 255),
                ];
                $sent = false;
            }
        } else {
            // Inside the five-point band: neither fires nor clears, or a
            // partition sitting one point under the limit would flap daily.
            $streak = 0;
        }

        $state[$mount] = ['streak' => $streak, 'sent' => $sent, 'threshold' => $threshold, 'seen' => $now];
    }

    // A mount that disappeared (an unplugged USB disk) keeps its latch for a
    // week, so re-plugging it does not re-send the alert; after that it goes,
    // or every mount point the router ever had would stay in the blob.
    foreach ($fs_state as $mount => $prev) {
        if (isset($state[$mount]) || !is_array($prev)) {
            continue;
        }
        $seen = is_int($prev['seen'] ?? null) ? $prev['seen'] : $now;
        if (($now - $seen) <= 7 * 86400 && count($state) < 32) {
            $prev['seen'] = $seen;
            $state[$mount] = $prev;
        }
    }

    return ['state' => $state, 'events' => $events];
}

/**
 * The cheap pre-filter of CORE 3.4 step 1. It never decides freshness - that
 * is the guarded UPDATE in `bk_storage_record` - it only keeps the disk tables
 * from being touched every minute.
 *
 * (a) The scalar throttle: at most one DB pass per 55 minutes. It is a SCALAR
 *     in `last_details`, so the 60 kB cap can never drop it.
 * (b) A new SMART reading or a disk that was not in the previous list.
 *
 * A MISSING old list is deliberately not read as "every disk is unknown": that
 * is exactly the `storage_list_dropped` case, and it would mean one upsert per
 * disk per minute for a router whose details do not fit.
 *
 * @param list<array<string, mixed>> $disks sanitized disks, each with `key`
 */
function bk_storage_gate_open(?int $sample_at, array $disks, $old_disks, int $now): bool {
    if ($sample_at === null || ($now - $sample_at) >= 55 * 60) {
        return true;
    }
    if (!is_array($old_disks)) {
        return false;
    }
    $seen = [];
    foreach ($old_disks as $old) {
        if (is_array($old) && is_string($old['key'] ?? null)) {
            $seen[$old['key']] = $old['smart']['checked_at'] ?? null;
        }
    }
    foreach ($disks as $disk) {
        if (!is_array($disk) || !is_string($disk['key'] ?? null)) {
            continue;
        }
        if (!array_key_exists($disk['key'], $seen) || ($disk['smart']['checked_at'] ?? null) !== $seen[$disk['key']]) {
            return true;
        }
    }
    return false;
}

/**
 * Writes one report's disks into `storage_disks` / `storage_disk_daily`
 * (CORE 3.4) and answers what the alert rules of 3.5 may look at.
 *
 * Called inside the report transaction in its own try/catch, like the metrics
 * INSERT: a router must still get a valid response when the disk tables are
 * missing from an older database.
 *
 * FRESH IS DECIDED BY THE DATABASE. The guarded `UPDATE ... WHERE
 * last_checked_at IS NULL OR last_checked_at < ?` either changes one row - a
 * SMART reading nobody folded in yet - or none. Only the first kind reaches
 * 3.5, so "two consecutive readings" can never be satisfied by one reading
 * that was reported twice, whatever happened to `last_details`.
 *
 * `$last_sample_at` is not in CORE 3.4's signature; the scalar throttle needs
 * it and the function has no other way to see `last_details`.
 *
 * @param list<array<string, mixed>> $disks sanitized disks, each with `key`
 * @return array{fresh: list<array<string, mixed>>, states: array<string, array<string, mixed>>,
 *               ids: array<string, int>, events: list<array<string, string>>, sampled: bool}
 */
function bk_storage_record(PDO $pdo, int $monitor_id, array $disks, $disk_devices, ?int $uptime, int $now, $old_disks, ?int $last_sample_at = null): array {
    $out = ['fresh' => [], 'states' => [], 'ids' => [], 'events' => [], 'sampled' => false];
    if ($disks === [] || !bk_storage_gate_open($last_sample_at, $disks, $old_disks, $now)) {
        return $out;
    }
    $out['sampled'] = true;

    $writes = [];
    foreach (is_array($disk_devices) ? $disk_devices : [] as $dev) {
        if (is_array($dev) && is_string($dev['device'] ?? null)) {
            $writes[$dev['device']] = bk_ranged_int($dev['write_sectors_total'] ?? null, 0, 2 ** 53);
        }
    }

    // UNIX_TIMESTAMP, not the DATETIME string: `last_sample_at` is written
    // with MySQL's NOW() and read for an elapsed time in PHP. On a host whose
    // PHP timezone differs from the database's, strtotime() on that string is
    // off by the whole offset, and the reboot rule below would read every
    // report as a reboot.
    $sel = $pdo->prepare('SELECT *, UNIX_TIMESTAMP(last_sample_at) AS last_sample_ts FROM storage_disks WHERE monitor_id = ?');
    $sel->execute([$monitor_id]);
    $rows = [];
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(string)$row['disk_key']] = $row;
    }

    $ins = $pdo->prepare(
        'INSERT INTO storage_disks (monitor_id, disk_key, name, transport, port, model, smart_model, size_bytes, rotational, first_seen, last_seen)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        . ' ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name = VALUES(name), port = VALUES(port),'
        . ' smart_model = COALESCE(VALUES(smart_model), smart_model), rotational = VALUES(rotational), last_seen = NOW()'
    );
    $fold = $pdo->prepare(
        'UPDATE storage_disks SET last_checked_at = ?, last_power_on_hours = ? WHERE id = ? AND (last_checked_at IS NULL OR last_checked_at < ?)'
    );
    // COALESCE, not a plain assignment: a report without `disk_devices` (the
    // agent could not read /proc/diskstats) must not erase the counter the
    // next delta is measured against.
    $sample = $pdo->prepare(
        'UPDATE storage_disks SET last_sample_at = NOW(), last_write_sectors = COALESCE(?, last_write_sectors),'
        . ' last_uptime = COALESCE(?, last_uptime) WHERE id = ?'
    );
    $replace = $pdo->prepare(
        'UPDATE storage_disks SET replaced_at = NOW(), alert_state = NULL, last_checked_at = NULL,'
        . ' last_power_on_hours = NULL, last_write_sectors = NULL WHERE id = ?'
    );

    foreach ($disks as $disk) {
        if (!is_array($disk) || !is_string($disk['key'] ?? null) || $disk['key'] === '') {
            continue;
        }
        $key = $disk['key'];
        $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
        $ins->execute([
            $monitor_id, $key, (string)$disk['name'], (string)$disk['transport'], $disk['port'],
            $disk['model'], $smart['model'] ?? null, $disk['size_bytes'],
            $disk['rotational'] === null ? null : (int)$disk['rotational'],
        ]);
        $disk_id = (int)$pdo->lastInsertId();
        $out['ids'][$key] = $disk_id;
        $row = $rows[$key] ?? null;

        // Another drive in the same slot: the identity is a hash of transport,
        // port, model and size, so an identical replacement model lands on the
        // same row. Its history must not be measured against the old one.
        $hours = $smart['power_on_hours'] ?? null;
        $prev_hours = ($row !== null && $row['last_power_on_hours'] !== null) ? (int)$row['last_power_on_hours'] : null;
        if ($hours !== null && $prev_hours !== null && $hours < $prev_hours - 48) {
            $replace->execute([$disk_id]);
            $row = null;
            $out['events'][] = bk_storage_alert_event($key, 'disk_replaced', 'storage_recovered',
                sprintf('Disk %s byl vyměněn, historie a základny upozornění začínají znovu.', bk_disk_label($disk)));
        }
        $out['states'][$key] = bk_storage_alert_state($row !== null ? json_decode((string)($row['alert_state'] ?? ''), true) : null);

        [$delta, $partial] = bk_disk_host_write_delta($row, $writes[$disk['name']] ?? null, $uptime, $now);

        $checked = $smart['checked_at'] ?? null;
        $fresh = false;
        if ($checked !== null) {
            $fold->execute([$checked, $hours, $disk_id, $checked]);
            $fresh = $fold->rowCount() === 1;
        }
        $sample->execute([$writes[$disk['name']] ?? null, $uptime, $disk_id]);
        if ($fresh) {
            $out['fresh'][] = $disk;
        }
        if ($fresh || $delta !== null || $partial === 1 || is_array($disk['emmc'] ?? null)) {
            bk_storage_day_upsert($pdo, $disk_id, $disk, $fresh, $delta, $partial, $now);
        }
    }
    return $out;
}

/**
 * Host writes of one disk since the last DB pass (CORE 3.4 step 4).
 *
 * The kernel counter in /proc/diskstats is 32-bit on the Omnia and restarts at
 * every boot, so three cases are distinguished and NONE of them invents a
 * number: a reboot counts the counter from zero and marks the day partial, a
 * counter that fell without a reboot is a wrap and adds nothing, and the first
 * sample of a disk adds nothing at all (the whole lifetime counter is not what
 * was written today).
 *
 * @param ?array<string, mixed> $row the stored storage_disks row (with `last_sample_ts`), null the first time
 * @return array{0: ?int, 1: int} delta in bytes (null = unknown) and the partial flag
 */
function bk_disk_host_write_delta($row, ?int $cur, ?int $uptime, int $now): array {
    if ($cur === null || $row === null || $row['last_write_sectors'] === null) {
        return [null, 0];
    }
    $last = (int)$row['last_write_sectors'];
    $last_uptime = $row['last_uptime'] !== null ? (int)$row['last_uptime'] : null;
    $last_sample = isset($row['last_sample_ts']) ? (int)$row['last_sample_ts'] : null;
    $elapsed = $last_sample !== null ? max(0, $now - $last_sample) : null;

    // The wall clock advanced but the uptime did not: the router rebooted
    // between the two samples. boot_time is not used - NTP moves it.
    $rebooted = $uptime !== null && $last_uptime !== null && $elapsed !== null
        && $uptime < $last_uptime + $elapsed - 120;
    if ($rebooted) {
        return [$cur * 512, 1];
    }
    if ($cur < $last) {
        return [null, 1];
    }
    $delta = ($cur - $last) * 512;
    // 2 GiB/s sustained is not a router writing to a disk, it is a counter that
    // wrapped or was replaced. The day is marked incomplete, never padded.
    if ($elapsed !== null && $delta > ($elapsed + 60) * 2147483648) {
        return [null, 1];
    }
    return [$delta, 0];
}

/**
 * The daily row of one disk (CORE 3.4 step 6). A counter the drive does not
 * report stays NULL for the whole day and is never folded into a zero, or the
 * storage card would show a measured zero where nothing was measured.
 *
 * `samples` counts SMART readings only. eMMC and any disk without SMART still
 * get a row, because their host writes and life registers are real data.
 */
function bk_storage_day_upsert(PDO $pdo, int $disk_id, array $disk, bool $fresh, ?int $delta, int $partial, int $now): void {
    $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
    $emmc = is_array($disk['emmc'] ?? null) ? $disk['emmc'] : [];
    $lives = array_filter([$emmc['life_a'] ?? null, $emmc['life_b'] ?? null], fn ($v) => $v !== null);
    // Only on a fresh reading: a replayed report must not count as a sample.
    $s = fn (string $key) => $fresh ? ($smart[$key] ?? null) : null;
    $temp = $s('temperature_c');
    $passed = $s('passed');

    $row = [
        'disk_id' => $disk_id,
        'day' => date('Y-m-d', $now),
        'samples' => $fresh ? 1 : 0,
        'smart_passed' => $passed === null ? null : (int)$passed,
        'temp_min' => $temp,
        'temp_max' => $temp,
        'temp_sum' => $temp,
        'temp_n' => $temp === null ? null : 1,
        'power_on_hours' => $s('power_on_hours'),
        'power_cycles' => $s('power_cycles'),
        'unsafe_shutdowns' => $s('unsafe_shutdowns'),
        'reallocated_sectors' => $s('reallocated_sectors'),
        'pending_sectors' => $s('pending_sectors'),
        'offline_uncorrectable' => $s('offline_uncorrectable'),
        'reported_uncorrect' => $s('reported_uncorrect'),
        'crc_errors' => $s('crc_errors'),
        'runtime_bad_blocks' => $s('runtime_bad_blocks'),
        'media_errors' => $s('media_errors'),
        'error_log_count' => $s('error_log_count'),
        'wear_pct' => $s('wear_pct'),
        // sysfs, not SMART: it is current on every report, fresh or not.
        'emmc_life' => $lives === [] ? null : max($lives),
        'written_bytes' => $s('written_bytes'),
        'host_written_bytes' => $delta,
        'host_written_partial' => $partial,
    ];
    $latest = ['power_on_hours', 'power_cycles', 'unsafe_shutdowns', 'reallocated_sectors', 'pending_sectors',
        'offline_uncorrectable', 'reported_uncorrect', 'crc_errors', 'runtime_bad_blocks', 'media_errors',
        'error_log_count', 'wear_pct', 'emmc_life', 'written_bytes'];

    $updates = [
        'samples = samples + VALUES(samples)',
        // A single failed reading makes the whole day failed; an unknown one changes nothing.
        'smart_passed = CASE WHEN smart_passed = 0 OR VALUES(smart_passed) = 0 THEN 0 ELSE COALESCE(VALUES(smart_passed), smart_passed) END',
        'temp_min = CASE WHEN VALUES(temp_min) IS NULL THEN temp_min WHEN temp_min IS NULL THEN VALUES(temp_min) ELSE LEAST(temp_min, VALUES(temp_min)) END',
        'temp_max = CASE WHEN VALUES(temp_max) IS NULL THEN temp_max WHEN temp_max IS NULL THEN VALUES(temp_max) ELSE GREATEST(temp_max, VALUES(temp_max)) END',
        'temp_sum = CASE WHEN VALUES(temp_sum) IS NULL THEN temp_sum WHEN temp_sum IS NULL THEN VALUES(temp_sum) ELSE temp_sum + VALUES(temp_sum) END',
        'temp_n = CASE WHEN VALUES(temp_n) IS NULL THEN temp_n WHEN temp_n IS NULL THEN VALUES(temp_n) ELSE temp_n + VALUES(temp_n) END',
        'host_written_bytes = CASE WHEN VALUES(host_written_bytes) IS NULL THEN host_written_bytes WHEN host_written_bytes IS NULL THEN VALUES(host_written_bytes) ELSE host_written_bytes + VALUES(host_written_bytes) END',
        'host_written_partial = GREATEST(host_written_partial, VALUES(host_written_partial))',
    ];
    foreach ($latest as $col) {
        $updates[] = "{$col} = COALESCE(VALUES({$col}), {$col})";
    }

    $cols = array_keys($row);
    $sql = 'INSERT INTO storage_disk_daily (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
    $pdo->prepare($sql)->execute(array_values($row));
}

/** Writes the alert latches of 3.5 back to their disk rows. */
function bk_storage_state_save(PDO $pdo, array $ids, array $states): void {
    $upd = $pdo->prepare('UPDATE storage_disks SET alert_state = ? WHERE id = ?');
    foreach ($states as $key => $state) {
        if (!isset($ids[$key])) {
            continue;
        }
        $upd->execute([json_encode($state, JSON_UNESCAPED_UNICODE), (int)$ids[$key]]);
    }
}

/**
 * The hourly `wan_path` object of an OpenWrt agent (0.1.7+), validated.
 *
 * Same rules as the other sanitizers: an allow-list, strict booleans, out of
 * range = null. Two nulls carry meaning and must survive as they are:
 * `sqm: null` = "could not check" while `sqm: []` = "checked, no queue on the
 * WAN device", and a shaper rate of 0 is "this direction is not shaped" - so
 * null, never "0 kbit/s".
 *
 * @return ?array<string, mixed> null when the agent sent no object
 */
function bk_sanitize_wan_path($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $netdev = fn ($v): ?string => (is_string($v) && preg_match('/^[A-Za-z0-9._@-]{1,32}$/', $v)) ? $v : null;
    // Until 2100: a plain epoch, not tied to the server's clock (the router's may be off).
    $out = ['checked_at' => bk_ranged_int($raw['checked_at'] ?? null, 1, 4102444800)];
    foreach (['flow_offloading', 'flow_offloading_hw', 'flowtable_active', 'packet_steering_active', 'wan_threaded_napi'] as $key) {
        $out[$key] = is_bool($raw[$key] ?? null) ? $raw[$key] : null;
    }
    // The raw uci value, a label only: what "unset" means depends on the release.
    $out['packet_steering'] = in_array($raw['packet_steering'] ?? null, ['0', '1', '2', 'unset'], true) ? $raw['packet_steering'] : null;
    $mask = $raw['wan_rps_mask'] ?? null;
    $out['wan_rps_mask'] = (is_string($mask) && preg_match('/^[0-9a-fA-F,]{1,64}$/', $mask)) ? $mask : null;
    $out['wan_rx_ring_drops'] = bk_ranged_int($raw['wan_rx_ring_drops'] ?? null, 0, 2 ** 53);

    $out['sqm'] = null;
    if (is_array($raw['sqm'] ?? null)) {
        $out['sqm'] = [];
        foreach (array_slice($raw['sqm'], 0, 8) as $queue) {
            if (!is_array($queue) || $netdev($queue['iface'] ?? null) === null) {
                continue;
            }
            $out['sqm'][] = [
                'iface' => $netdev($queue['iface']),
                'download_kbps' => bk_ranged_int($queue['download_kbps'] ?? null, 1, 100000000),
                'upload_kbps' => bk_ranged_int($queue['upload_kbps'] ?? null, 1, 100000000),
                'egress_dropped' => bk_ranged_int($queue['egress_dropped'] ?? null, 0, 2 ** 53),
                'ingress_dropped' => bk_ranged_int($queue['ingress_dropped'] ?? null, 0, 2 ** 53),
            ];
        }
    }
    // Current link state of the LAN ports vs what they COULD do: never derive one from the other.
    $out['lan_port_max_mbit'] = bk_ranged_int($raw['lan_port_max_mbit'] ?? null, 1, 1000000);
    $out['lan_port_cap_mbit'] = bk_ranged_int($raw['lan_port_cap_mbit'] ?? null, 1, 1000000);
    $out['lan_conduits'] = null;
    if (is_array($raw['lan_conduits'] ?? null)) {
        $out['lan_conduits'] = [];
        foreach (array_slice($raw['lan_conduits'], 0, 8) as $conduit) {
            if (is_array($conduit) && $netdev($conduit['dev'] ?? null) !== null) {
                $out['lan_conduits'][] = ['dev' => $netdev($conduit['dev']), 'mbit' => bk_ranged_int($conduit['mbit'] ?? null, 1, 1000000)];
            }
        }
    }
    return $out;
}

/**
 * The wired switch of an OpenWrt agent (0.1.8+), port by port, validated.
 *
 * The agent sends this every run: which cable carries a link, at what rate,
 * what the port and the other end can do, and how many devices the bridge has
 * learnt behind it. The conduit comes with it - on a DSA switch every wired
 * client shares that one link to the CPU, and it is the real ceiling.
 *
 * Two nulls carry meaning and are kept apart: the whole section is null when
 * the router could not look (no ubus, no `bridge`, no DSA switch), while a
 * port's `speed_mbit` is null when there is nothing plugged in. A count of 0
 * is a MEASUREMENT - "this port is quiet" - and must not become null.
 *
 * A port that reports no carrier cannot have negotiated a rate, a duplex or a
 * link partner: those three are forced to null rather than stored, because a
 * rate next to a dead port reads as a measurement of a live one.
 *
 * @return ?array<string, mixed> null when the router could not answer
 */
function bk_sanitize_lan_ports($raw): ?array {
    if (!is_array($raw) || !is_array($raw['ports'] ?? null)) {
        return null;
    }
    $netdev = fn ($v): ?string => (is_string($v) && preg_match('/^[A-Za-z0-9._@-]{1,32}$/', $v)) ? $v : null;
    $bool = fn ($v): ?bool => is_bool($v) ? $v : null;
    // 1 Mbit/s to 1 Tbit/s: below it no switch port negotiates, above it the
    // value is a parse mistake and not a rate.
    $rate = fn ($v): ?int => bk_ranged_int($v, 1, 1000000);
    $duplex = fn ($v): ?string => in_array($v, ['full', 'half'], true) ? $v : null;

    $ports = [];
    // A home switch has at most a handful of ports; 16 is well past any board
    // this agent runs on and stops a malformed list from filling the blob.
    foreach (array_slice($raw['ports'], 0, 16) as $port) {
        if (!is_array($port) || ($name = $netdev($port['name'] ?? null)) === null) {
            continue;
        }
        $link = $bool($port['link'] ?? null);
        $ports[] = [
            'name' => $name,
            'link' => $link,
            'speed_mbit' => $link === false ? null : $rate($port['speed_mbit'] ?? null),
            'duplex' => $link === false ? null : $duplex($port['duplex'] ?? null),
            // What the port itself supports. A capability, not today's state:
            // it stays 1000 on a port linked at 100.
            'max_mbit' => $rate($port['max_mbit'] ?? null),
            'partner_max_mbit' => $link === false ? null : $rate($port['partner_max_mbit'] ?? null),
            // 0 is "nothing has spoken behind this port", which is a result.
            // 4096 is more addresses than a household switch can learn.
            'clients' => bk_ranged_int($port['clients'] ?? null, 0, 4096),
        ];
    }
    if (!$ports) {
        return null;
    }

    $conduits = [];
    foreach (array_slice(is_array($raw['conduits'] ?? null) ? $raw['conduits'] : [], 0, 4) as $conduit) {
        if (!is_array($conduit) || ($dev = $netdev($conduit['dev'] ?? null)) === null) {
            continue;
        }
        $clink = $bool($conduit['link'] ?? null);
        $conduits[] = [
            'dev' => $dev,
            'link' => $clink,
            'speed_mbit' => $clink === false ? null : $rate($conduit['speed_mbit'] ?? null),
            'duplex' => $clink === false ? null : $duplex($conduit['duplex'] ?? null),
        ];
    }

    return [
        'bridge' => $netdev($raw['bridge'] ?? null),
        'ports' => $ports,
        'conduits' => $conduits,
        // The agent's own sum over the ports it kept. Out of range it is null,
        // never the sum of the list above - that would turn a dropped port
        // into a smaller, believable-looking total.
        'clients_total' => bk_ranged_int($raw['clients_total'] ?? null, 0, 65535),
    ];
}

/**
 * One line of the router's log as the server keeps it (W1-C3). Pure.
 *
 * The agent masks the line before it leaves the router (owner decision 5.7);
 * this runs the same masks again, so an older or a foreign agent cannot put a
 * raw address into the details: e-mail, MAC, IPv6 (validated, so a clock time
 * "12:34:56" stays), IPv4, local host names and long hex ids (serials, keys).
 * Only printable ASCII stays, and a line is at most 200 characters.
 */
function bk_mask_log_line(string $line): string {
    $line = (string)preg_replace('/[^\x20-\x7E]/', '?', $line);
    $line = (string)preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '<email>', $line);
    $line = (string)preg_replace('/(?<![0-9A-Fa-f:])(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}(?![0-9A-Fa-f:])/', '<mac>', $line);
    $line = (string)preg_replace_callback('/(?<![0-9A-Za-z:.])[0-9A-Fa-f:.]*:[0-9A-Fa-f:.]*:[0-9A-Fa-f:.]*(?![0-9A-Za-z:])/', function (array $m): string {
        $candidate = rtrim($m[0], '.');
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '<ipv6>' . substr($m[0], strlen($candidate));
        }
        return $m[0];
    }, $line);
    $line = (string)preg_replace('/(?<![0-9.])(?:\d{1,3}\.){3}\d{1,3}(?![0-9])/', '<ipv4>', $line);
    $line = (string)preg_replace('/\b[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.(?:lan|local|localdomain|home\.arpa|home|internal)\b/i', '<host>', $line);
    $line = (string)preg_replace('/\b[0-9A-Fa-f]{12,}\b/', '<id>', $line);
    $line = trim($line);
    return strlen($line) > 200 ? substr($line, 0, 197) . '...' : $line;
}

/**
 * The last error lines of an OpenWrt router's log (agent 0.1.8, W1-C3).
 *
 * At most five, newest first, each {ts, prog, msg, count}: ts is the newest
 * repeat (epoch seconds or null), prog the program that wrote it or null,
 * msg the masked text, count how often it repeats in the buffer (at least 1).
 * An item without a text is dropped rather than shown empty. null = the
 * router sent no readable list; [] = it read the log and found no error line.
 *
 * @return ?list<array{ts: ?int, prog: ?string, msg: string, count: int}>
 */
function bk_sanitize_log_lines($raw): ?array {
    if (!is_array($raw) || !array_is_list($raw)) {
        return null;
    }
    $out = [];
    foreach (array_slice($raw, 0, 5) as $item) {
        if (!is_array($item) || !isset($item['msg']) || !is_string($item['msg'])) {
            continue;
        }
        $msg = bk_mask_log_line($item['msg']);
        if ($msg === '') {
            continue;
        }
        $ts = $item['ts'] ?? null;
        $prog = $item['prog'] ?? null;
        $count = $item['count'] ?? null;
        $out[] = [
            'ts' => (is_int($ts) || (is_string($ts) && ctype_digit($ts))) && (int)$ts > 0 ? (int)$ts : null,
            'prog' => is_string($prog) && preg_match('/^[A-Za-z0-9_.@\/-]{1,64}$/', $prog) ? $prog : null,
            'msg' => $msg,
            'count' => (is_int($count) || (is_string($count) && ctype_digit($count))) && (int)$count >= 1 ? (int)$count : 1,
        ];
    }
    return $out;
}

/**
 * The three log-line keys of a report as last_details keeps them (W1-C3).
 *
 * Only keys the report carries are returned, so an older agent that sends
 * none of them leaves nothing behind. With the monitor's switch off (owner
 * decision 5.7, default on) no line is kept whatever the agent sent, and the
 * state says who switched it off, so the page does not print "—" as if the
 * router had simply said nothing.
 *
 * @param array<string, mixed> $data the agent's report
 * @return array<string, mixed>
 */
function bk_log_lines_details(array $data, bool $enabled): array {
    $out = [];
    if (array_key_exists('log_errors_recent', $data)) {
        $out['log_errors_recent'] = $enabled ? bk_sanitize_log_lines($data['log_errors_recent']) : null;
    }
    if (array_key_exists('log_window_secs', $data)) {
        $win = $data['log_window_secs'];
        $out['log_window_secs'] = (is_int($win) || (is_string($win) && ctype_digit($win))) && (int)$win >= 0 ? (int)$win : null;
    }
    if (array_key_exists('log_lines_state', $data) || (!$enabled && array_key_exists('log_errors_recent', $data))) {
        $state = $data['log_lines_state'] ?? null;
        $state = in_array($state, ['on', 'off_monitor', 'off_router'], true) ? $state : null;
        // The router may not have heard the switch yet (it reads the answer
        // of this very report); what the page shows is what is stored.
        $out['log_lines_state'] = (!$enabled && $state !== 'off_router') ? 'off_monitor' : $state;
    }
    return $out;
}

/**
 * Fits the details of a monitor into the `last_details` TEXT column.
 *
 * The largest lists go first, then the largest strings; the scalars the UI
 * lives on always fit. It used to happen silently (error_log only), so a
 * router whose disk list was shed looked exactly like a router without disks.
 * The shed keys are returned AND written into the blob as `details_dropped`:
 * that list is reset by every report that fits, so the collection issue ends
 * by itself. `$protected` keys are never shed - they are small, and they are
 * what makes the loss visible.
 *
 * `$carried` is what a report that is NOT evidence of loss hands over: a
 * light "reduced" run sheds nothing of its own, and clearing the list would
 * hide what the last full report really lost.
 *
 * @param array<string, mixed> $details
 * @param string[] $protected
 * @param string[] $carried
 * @return array{json: string, dropped: string[]}
 */
function bk_details_fit(array $details, int $limit, array $protected, array $carried = []): array {
    $details['details_dropped'] = $carried;
    $protected[] = 'details_dropped';
    $json = (string)json_encode($details, JSON_UNESCAPED_UNICODE);
    if (strlen($json) <= $limit) {
        return ['json' => $json, 'dropped' => $carried];
    }
    $dropped = $carried;
    foreach (['is_array', 'is_string'] as $is_kind) {
        $sizes = [];
        foreach ($details as $key => $value) {
            if ($is_kind($value) && !in_array($key, $protected, true)) {
                $sizes[$key] = strlen((string)json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        }
        arsort($sizes);
        foreach (array_keys($sizes) as $key) {
            unset($details[$key]);
            $dropped[] = (string)$key;
            $details['details_dropped'] = $dropped;
            $json = (string)json_encode($details, JSON_UNESCAPED_UNICODE);
            if (strlen($json) <= $limit) {
                return ['json' => $json, 'dropped' => $dropped];
            }
        }
    }
    return ['json' => $json, 'dropped' => $dropped];
}

/**
 * Adds one entry to `ingest_issues`: data the server received and did not
 * store. At most 5 entries - the list says THAT something is being lost and
 * where to look, it is not a log.
 *
 * @param array<int, array{type: string, key: ?string, bytes: ?int}> $issues
 * @return array<int, array{type: string, key: ?string, bytes: ?int}>
 */
function bk_ingest_issue_add(array $issues, string $type, ?string $key = null, ?int $bytes = null): array {
    if (count($issues) < 5) {
        $issues[] = ['type' => $type, 'key' => $key === null ? null : mb_substr($key, 0, 64), 'bytes' => $bytes];
    }
    return $issues;
}

/**
 * Whitelist of the `diagnostics` object of a speedtest item (WAN 3.2).
 *
 * Re-encoded from named keys only: the object comes from the router's own
 * analyzer, goes into a TEXT column and is read back by the classifier, so an
 * unknown key would be stored unchecked and a large one would push the row
 * past its budget. Everything is nullable; a key that is not a number, bool
 * or object of the shape below is simply not copied.
 *
 * @return array{diagnostics: ?array<string, mixed>, dropped: bool}
 */
function bk_speedtest_diagnostics($raw): array {
    if (!is_array($raw) || $raw === []) {
        return ['diagnostics' => null, 'dropped' => false];
    }
    // Everything in this object is a rate, a share or a duration: never negative.
    $num = fn ($v, float $max): ?float => bk_ranged_num($v, 0.0, $max);
    $count = fn ($v): ?int => bk_ranged_int($v, 0, 2 ** 53);
    $out = [];
    $out['v'] = bk_ranged_int($raw['v'] ?? null, 1, 99);
    foreach (['cpu_measured', 'path_verified'] as $flag) {
        $out[$flag] = is_bool($raw[$flag] ?? null) ? $raw[$flag] : null;
    }
    $out['background_dl_mbps'] = $num($raw['background_dl_mbps'] ?? null, 1000000.0);
    $out['background_ul_mbps'] = $num($raw['background_ul_mbps'] ?? null, 1000000.0);
    foreach (['samples', 'gaps', 'bad_lines'] as $counter) {
        $out[$counter] = $count($raw[$counter] ?? null);
    }
    foreach (['dl', 'ul'] as $phase) {
        $out[$phase] = null;
        if (!is_array($raw[$phase] ?? null)) {
            continue;
        }
        $src = $raw[$phase];
        $out[$phase] = [
            'secs' => $num($src['secs'] ?? null, 86400.0),
            'wan_mbps' => $num($src['wan_mbps'] ?? null, 1000000.0),
            'core' => bk_ranged_int($src['core'] ?? null, 0, 255),
            'core_busy_pct' => $num($src['core_busy_pct'] ?? null, 100.0),
            'core_user_pct' => $num($src['core_user_pct'] ?? null, 100.0),
            'core_system_pct' => $num($src['core_system_pct'] ?? null, 100.0),
            'core_irq_softirq_pct' => $num($src['core_irq_softirq_pct'] ?? null, 100.0),
            'hot_share' => $num($src['hot_share'] ?? null, 1.0),
            'all_cores_avg_pct' => $num($src['all_cores_avg_pct'] ?? null, 100.0),
            'softnet_time_squeeze' => $count($src['softnet_time_squeeze'] ?? null),
            'softnet_dropped' => $count($src['softnet_dropped'] ?? null),
            'rx_packets' => $count($src['rx_packets'] ?? null),
            'retrans_pct' => $num($src['retrans_pct'] ?? null, 100.0),
        ];
    }
    $out['run'] = null;
    if (is_array($raw['run'] ?? null)) {
        $out['run'] = [];
        foreach (['wan_rx_dropped', 'wan_rx_ring_drops', 'wan_rx_errors', 'conntrack_drop'] as $key) {
            $out['run'][$key] = $count($raw['run'][$key] ?? null);
        }
    }
    $out['path'] = null;
    if (is_array($raw['path'] ?? null)) {
        $out['path'] = [
            'flow_offloading' => is_bool($raw['path']['flow_offloading'] ?? null) ? $raw['path']['flow_offloading'] : null,
            'packet_steering_active' => is_bool($raw['path']['packet_steering_active'] ?? null) ? $raw['path']['packet_steering_active'] : null,
            'sqm_dl_kbps' => bk_ranged_int($raw['path']['sqm_dl_kbps'] ?? null, 1, 100000000),
            'sqm_ul_kbps' => bk_ranged_int($raw['path']['sqm_ul_kbps'] ?? null, 1, 100000000),
        ];
    }
    // Only what the router really sent: an object of nothing but nulls would
    // claim the analyzer ran when it did not.
    $kept = array_filter($out, fn ($v) => $v !== null);
    if ($kept === []) {
        return ['diagnostics' => null, 'dropped' => $raw !== []];
    }
    // The column budget of WAN 3.3. The result is the server's own encoding,
    // so this is the size that will really be stored.
    if (strlen((string)json_encode($out, JSON_UNESCAPED_UNICODE)) > 2048) {
        return ['diagnostics' => null, 'dropped' => true];
    }
    return ['diagnostics' => $out, 'dropped' => false];
}

/**
 * The tool-internal unit check of WAN 3.4 ("unit or parser bugs, the W01 class").
 *
 * librespeed reports `total bytes / elapsed / 125000`, and the default run is
 * 15 s per direction, so `mbps * 15 * 125000` must match `bytes` within 10 %.
 * The check needs no knowledge of the line: only a unit or parser error can
 * break the identity (bytes/s instead of Mbit/s is a factor of eight).
 *
 * null = not decidable (one of the two is missing, or the tool ran with a
 * different duration, which 0.1.7 never does).
 */
function bk_speedtest_unit_ok(?float $mbps, ?int $bytes, int $secs = 15): ?bool {
    if ($mbps === null || $bytes === null || $mbps <= 0 || $bytes <= 0) {
        return null;
    }
    $expected = $mbps * $secs * 125000;
    return abs($bytes - $expected) <= 0.1 * $expected;
}

/**
 * One item of `speedtests[]`, sanitized into the columns of
 * `speedtest_results` (WAN 3.1.7, 3.3).
 *
 * Returns null for an item that carries no measurement time: there is no
 * saying when it applied and substituting "now" would lie. Such an item is
 * still ACKED by the caller and named in `ingest_issues` - re-sending it
 * forever would repair nothing.
 *
 * W01 repair at ingest: an item WITHOUT its byte counter comes from an agent
 * older than 0.1.7, whose "> 1000 means bytes" heuristic divided real speeds
 * by 125000. A value below 0.1 Mbit/s is that artefact, not a line nobody can
 * measure, so it is stored as NULL - unmeasured, never a fabricated 0.01.
 *
 * @return array{ts: int, raw_ts: string, row: array<string, mixed>, issues: list<array{0: string, 1: ?string}>}|null
 */
function bk_speedtest_item($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $raw_ts = trim((string)($raw['timestamp'] ?? ''));
    $ts = $raw_ts !== '' ? strtotime($raw_ts) : false;
    if ($ts === false) {
        return null;
    }
    $issues = [];
    $bytes_rx = bk_ranged_int($raw['bytes_received'] ?? null, 0, 2 ** 60);
    $bytes_tx = bk_ranged_int($raw['bytes_sent'] ?? null, 0, 2 ** 60);
    $rate = function ($value, ?int $bytes): ?float {
        $mbps = bk_ranged_num($value, 0.0, 1000000.0);
        return ($mbps !== null && $mbps < 0.1 && $bytes === null) ? null : $mbps;
    };
    $download = $rate($raw['download_mbps'] ?? null, $bytes_rx);
    $upload = $rate($raw['upload_mbps'] ?? null, $bytes_tx);

    $diag = bk_speedtest_diagnostics($raw['diagnostics'] ?? null);
    if ($diag['dropped']) {
        $issues[] = ['speedtest_diagnostics_dropped', $raw_ts];
    }
    // The unit check is the tool's own identity, so it also judges results
    // that carry no phase rates (a Turris file). A mismatch is stored with the
    // result - the classifier must not average a number of unknown unit.
    $unit = bk_speedtest_unit_ok($download, $bytes_rx) === false
        || bk_speedtest_unit_ok($upload, $bytes_tx) === false;
    if ($unit) {
        $issues[] = ['unit_mismatch', $raw_ts];
        $diag['diagnostics'] = ($diag['diagnostics'] ?? []) + ['unit_mismatch' => true];
    }

    $server = trim((string)($raw['server'] ?? ''));
    $iface = $raw['iface'] ?? null;
    $tool = $raw['tool'] ?? null;
    $row = [
        'measured_at' => date('Y-m-d H:i:s', $ts),
        'download_mbps' => $download,
        'upload_mbps' => $upload,
        'ping_ms' => bk_ranged_num($raw['ping_ms'] ?? null, 0.0, 600000.0),
        'jitter_ms' => bk_ranged_num($raw['jitter_ms'] ?? null, 0.0, 600000.0),
        'server_name' => $server !== '' ? mb_substr($server, 0, 120) : null,
        // `started_by` has no column of its own (WAN 3.3): it is written into
        // the existing `source`, whose 'librespeed' constant the migration
        // rewrote to 'turris'. Anything the agent did not call a probe is a
        // file the router's own nightly test left behind.
        'source' => ($raw['started_by'] ?? null) === 'agent' ? 'agent' : 'turris',
        'iface' => (is_string($iface) && preg_match('/^[A-Za-z0-9._@-]{1,32}$/', $iface)) ? $iface : null,
        'tool' => (is_string($tool) && preg_match('/^[A-Za-z0-9._+-]{1,24}$/', $tool)) ? $tool : null,
        'link_mbit' => bk_ranged_int($raw['link_mbit'] ?? null, 1, 1000000),
        'bytes_received' => $bytes_rx,
        'bytes_sent' => $bytes_tx,
        'diagnostics' => $diag['diagnostics'] === null ? null : (string)json_encode($diag['diagnostics'], JSON_UNESCAPED_UNICODE),
    ];
    return ['ts' => (int)$ts, 'raw_ts' => $raw_ts, 'row' => $row, 'issues' => $issues];
}

/**
 * The `speedtests_acked` of the response (WAN 3.1.6, "A bare 200 is not a receipt").
 *
 * The agent deletes its probe files and advances `last_sent` up to this
 * timestamp and never on a bare 200, so the answer must be the newest item
 * that is dealt with AND has nothing unfinished before it: the largest
 * handled timestamp older than every failed one. A batch that fails in the
 * middle therefore acks its prefix, and the rest stays on the router.
 *
 * @param list<array{ts: int, raw_ts: string}> $handled stored, already present, or rejected for good
 * @param list<array{ts: int, raw_ts: string}> $failed  the INSERT threw - the data is not here
 */
function bk_speedtest_ack(array $handled, array $failed): ?string {
    $limit = null;
    foreach ($failed as $item) {
        $limit = $limit === null ? $item['ts'] : min($limit, $item['ts']);
    }
    $ack = null;
    foreach ($handled as $item) {
        if ($limit !== null && $item['ts'] >= $limit) {
            continue;
        }
        if ($ack === null || $item['ts'] > $ack['ts']) {
            $ack = $item;
        }
    }
    return $ack === null ? null : $ack['raw_ts'];
}

/**
 * Whether the report carries a speed test that overlapped its own measuring
 * interval (X17, WAN 3.3 "Alert hygiene").
 *
 * `speedtest_active` is the agent's own interval flag; this is the server's
 * belt and braces: a result travels in the same report as the CPU minute it
 * polluted, so the ingest can see it before it judges anything. The window is
 * symmetric because the router's clock may be off by a minute, and one
 * skipped threshold evaluation is cheaper than an invented CPU alert.
 */
function bk_speedtest_in_report($speedtests, int $now, int $window = 120): bool {
    if (!is_array($speedtests)) {
        return false;
    }
    foreach ($speedtests as $item) {
        if (!is_array($item)) {
            continue;
        }
        $raw_ts = trim((string)($item['timestamp'] ?? ''));
        $ts = $raw_ts !== '' ? strtotime($raw_ts) : false;
        if ($ts !== false && abs($now - (int)$ts) <= $window) {
            return true;
        }
    }
    return false;
}

/**
 * The inputs of ONE direction of ONE speedtest, as WAN 3.4's table names them.
 *
 * `S` is the higher of the tool's own figure and 0.95 x the rate the agent
 * measured on the WAN device during that phase: the tool averages over the
 * whole 15 s including ramp-up, the phase rate does not, so taking the better
 * of the two keeps a slow start from being read as a slow line.
 *
 * Everything is nullable and null means "not measured" - never a stand-in.
 * `cap` is deliberately null without a plan: a 300 Mbit plan on a gigabit port
 * is the normal state, not a bottleneck, so without `P` nothing may be called
 * below anything.
 *
 * @param array<string, mixed> $test one speedtest_results row
 * @param array<string, mixed> $diag its decoded diagnostics (may be empty)
 * @param array<string, mixed> $ctx  ['plan_down', 'plan_up', 'plan_ok_pct', 'threaded_napi']
 * @return array<string, mixed>
 */
function bk_wan_dir_inputs(array $test, array $diag, string $dir, array $ctx): array {
    $num = function ($v): ?float {
        return is_numeric($v) ? (float)$v : null;
    };
    $tool = $num($test[$dir === 'dl' ? 'download_mbps' : 'upload_mbps'] ?? null);
    $phase = is_array($diag[$dir] ?? null) ? $diag[$dir] : [];
    $wan = $num($phase['wan_mbps'] ?? null);
    $candidates = [];
    if ($tool !== null && $tool > 0) {
        $candidates[] = $tool;
    }
    if ($wan !== null && $wan > 0) {
        $candidates[] = 0.95 * $wan;
    }
    $s = $candidates === [] ? null : max($candidates);

    $link = $num($test['link_mbit'] ?? null);
    $plan = $num($ctx[$dir === 'dl' ? 'plan_down' : 'plan_up'] ?? null);
    $ok_pct = $num($ctx['plan_ok_pct'] ?? null);
    // WAN 3.4: an empty field is 0.85, not "the whole advertised rate".
    $k = ($ok_pct !== null && $ok_pct >= 30 && $ok_pct <= 100) ? $ok_pct / 100 : 0.85;

    $path = is_array($diag['path'] ?? null) ? $diag['path'] : [];
    $sqm_kbps = $num($path[$dir === 'dl' ? 'sqm_dl_kbps' : 'sqm_ul_kbps'] ?? null);
    $q = ($sqm_kbps !== null && $sqm_kbps > 0) ? $sqm_kbps / 1000 : null;

    $cap = null;
    if ($plan !== null && $plan > 0) {
        $cap = ($q !== null && $q < $plan) ? $q : $plan;
    }
    $run = is_array($diag['run'] ?? null) ? $diag['run'] : [];

    return [
        's' => $s,
        'tool_mbps' => $tool,
        'phase_mbps' => $wan,
        'l' => ($link !== null && $link > 0) ? $link : null,
        'g' => ($link !== null && $link > 0) ? 0.93 * $link : null,
        'p' => ($plan !== null && $plan > 0) ? $plan : null,
        'k' => $k,
        'q' => $q,
        'cap' => $cap,
        'secs' => $num($phase['secs'] ?? null),
        'core' => isset($phase['core']) && is_numeric($phase['core']) ? (int)$phase['core'] : null,
        'core_busy_pct' => $num($phase['core_busy_pct'] ?? null),
        'core_user_pct' => $num($phase['core_user_pct'] ?? null),
        'core_system_pct' => $num($phase['core_system_pct'] ?? null),
        'core_irq_softirq_pct' => $num($phase['core_irq_softirq_pct'] ?? null),
        'hot_share' => $num($phase['hot_share'] ?? null),
        'all_cores_avg_pct' => $num($phase['all_cores_avg_pct'] ?? null),
        'squeeze' => $num($phase['softnet_time_squeeze'] ?? null),
        'softnet_dropped' => $num($phase['softnet_dropped'] ?? null),
        'rx_packets' => $num($phase['rx_packets'] ?? null),
        'retrans_pct' => $num($phase['retrans_pct'] ?? null),
        'ring_drops' => $num($run['wan_rx_ring_drops'] ?? null),
        'background' => $num($diag[$dir === 'dl' ? 'background_dl_mbps' : 'background_ul_mbps'] ?? null),
        // X27 counts a full-weight minute run that overlapped this phase. The
        // probe that produces it is wave 2; the key is read here so the gate
        // works the day it arrives instead of being retro-fitted.
        'minute_overlap_secs' => $num($phase['minute_overlap_secs'] ?? null),
        'cpu_measured' => is_bool($diag['cpu_measured'] ?? null) ? $diag['cpu_measured'] : null,
        'path_verified' => is_bool($diag['path_verified'] ?? null) ? $diag['path_verified'] : null,
        'threaded_napi' => is_bool($ctx['threaded_napi'] ?? null) ? $ctx['threaded_napi'] : null,
    ];
}

/**
 * Does the busiest core of this phase look pinned (WAN 3.4 rules 4 and 5)?
 *
 * Only the busiest core is read. A four-core router whose one RX core sits at
 * 98 % shows an average of 52 %, and an average is exactly how this kind of
 * limit stays invisible for years.
 */
function bk_wan_core_pinned(array $in): bool {
    $busy = $in['core_busy_pct'];
    $hot = $in['hot_share'];
    return ($busy !== null && $busy >= 90.0) || ($hot !== null && $hot >= 0.5);
}

/**
 * Share of the pinned core spent in the kernel network path (WAN 3.4 rule 4).
 *
 * `system` counts only with threaded NAPI, where the softirq work is done by
 * kernel threads and shows up as system time instead of softirq time.
 */
function bk_wan_core_net_share(array $in): ?float {
    $irq = $in['core_irq_softirq_pct'];
    if ($irq === null) {
        return null;
    }
    $sys = $in['core_system_pct'];
    if ($in['threaded_napi'] === true && $sys !== null) {
        return $irq + $sys;
    }
    return $irq;
}

/**
 * The verdict of ONE speedtest in ONE direction (WAN 3.4).
 *
 * The rules are tried in their documented order and the FIRST match is the
 * verdict; every other match is kept in `numbers.also`, because "the plan was
 * reached AND a core was pinned" is a different situation from "the plan was
 * reached" and the card has to be able to say so.
 *
 * A gated direction may still be confirmed fast (rules 1-3: a lower bound that
 * reaches a cap has reached it) but can never be declared slow - without CPU
 * data the router can be neither blamed nor cleared.
 *
 * @param array<string, mixed> $in the output of bk_wan_dir_inputs()
 * @param list<int|string> $basis ids of the tests this verdict stands on
 * @return array<string, mixed>
 */
function bk_wan_dir_verdict(array $in, ?string $started_by, array $basis): array {
    $numbers = [
        's_mbps' => $in['s'] === null ? null : round($in['s'], 2),
        'plan_mbit' => $in['p'],
        'ok_pct' => (int)round($in['k'] * 100),
        'cap_mbit' => $in['cap'] === null ? null : round($in['cap'], 2),
        'link_mbit' => $in['l'],
        'goodput_ceiling_mbit' => $in['g'] === null ? null : round($in['g'], 1),
        'sqm_mbit' => $in['q'] === null ? null : round($in['q'], 2),
        'core' => $in['core'],
        'core_busy_pct' => $in['core_busy_pct'],
        'core_net_pct' => bk_wan_core_net_share($in),
        'core_user_pct' => $in['core_user_pct'],
        'hot_share' => $in['hot_share'],
        'all_cores_avg_pct' => $in['all_cores_avg_pct'],
        'squeeze' => $in['squeeze'],
        'softnet_dropped' => $in['softnet_dropped'],
        'ring_drops' => $in['ring_drops'],
        'retrans_pct' => $in['retrans_pct'],
        'background_mbps' => $in['background'],
        'phase_secs' => $in['secs'],
        'also' => [],
    ];
    $verdict = function (string $class, ?string $reason) use (&$numbers, $basis): array {
        return ['class' => $class, 'reason' => $reason,
            // Per test there is nothing to be confident ABOUT: confidence is
            // how many of the last probes agreed, and that is the aggregate's
            // business. `line_limited` is pinned low by WAN 3.4 itself.
            'confidence' => $class === 'line_limited' ? 'low' : null,
            'basis' => $basis, 'numbers' => $numbers];
    };

    // Gates. The first two say nothing can be judged at all; the other three
    // leave rules 1-3 open.
    $gate = null;
    $restricted = false;
    if ($in['s'] === null) {
        $gate = 'no_result';
    } elseif ($in['path_verified'] === false) {
        $gate = 'path_unverified';
    } elseif ($in['background'] !== null && $in['background'] > max(20.0, 0.05 * $in['s'])) {
        $gate = 'background_traffic';
        $restricted = true;
    } elseif ($in['minute_overlap_secs'] !== null && $in['minute_overlap_secs'] > 0) {
        $gate = 'minute_run_overlap';
        $restricted = true;
    } elseif ($started_by === 'turris' || $in['cpu_measured'] !== true
        || $in['secs'] === null || $in['secs'] < 10.0) {
        $gate = 'cpu_not_measured';
        $restricted = true;
    }
    if ($gate !== null && !$restricted) {
        return $verdict('inconclusive', $gate);
    }

    $s = $in['s'];
    $matches = [];
    if ($in['p'] !== null && $s >= $in['k'] * $in['p']) {
        $matches[] = ['none', 'plan_reached'];
    }
    if ($in['q'] !== null && $s >= 0.85 * $in['q']) {
        $matches[] = ['link_limited', 'sqm_shaper'];
    }
    if ($in['g'] !== null && $s >= 0.90 * $in['g']) {
        $matches[] = ['link_limited', 'wan_port'];
    }
    $pinned = bk_wan_core_pinned($in);
    $net_share = bk_wan_core_net_share($in);
    if (!$restricted) {
        if ($pinned && $net_share !== null && $net_share >= 50.0) {
            $matches[] = ['cpu_limited', 'packet_path'];
        } elseif ($pinned) {
            $user = $in['core_user_pct'];
            $matches[] = ['cpu_limited', ($user !== null && $user >= 50.0) ? 'test_client' : 'mixed'];
        }
        $busy = $in['core_busy_pct'];
        $hot = $in['hot_share'];
        $no_drops = ($in['softnet_dropped'] !== null && $in['softnet_dropped'] <= 0.0);
        if ($no_drops && $in['ring_drops'] !== null && $in['ring_drops'] > 0.0) {
            $no_drops = false; // a full ring is the router's own loss, not the line's
        }
        if ($in['cap'] !== null && $s < $in['k'] * $in['cap']
            && $busy !== null && $busy < 80.0 && ($hot === null || $hot < 0.2) && $no_drops) {
            $retrans = $in['retrans_pct'];
            $matches[] = ['line_limited', ($retrans !== null && $retrans >= 1.0) ? 'upstream_loss' : 'below_plan'];
        }
    }

    foreach (array_slice($matches, 1) as $extra) {
        $numbers['also'][] = $extra[1];
    }
    if ($matches !== []) {
        // Rule 1 with a pinned core: within the plan today, nothing to spare.
        if ($matches[0][1] === 'plan_reached' && $pinned) {
            $numbers['no_cpu_headroom'] = true;
        }
        return $verdict($matches[0][0], $matches[0][1]);
    }
    if ($restricted) {
        return $verdict('inconclusive', (string)$gate);
    }
    $busy = $in['core_busy_pct'];
    if ($busy !== null && $busy >= 80.0 && $busy < 90.0) {
        return $verdict('inconclusive', 'cpu_borderline');
    }
    if ($in['p'] === null) {
        return $verdict('inconclusive', 'no_plan_known');
    }
    // Everything else: the plan is known and was not reached, but a rule that
    // could NAME the limit did not fire (drops with an idle core, for
    // instance). WAN 3.4 gives this branch no reason, and inventing one would
    // be a claim; the numbers say what was measured.
    return $verdict('inconclusive', null);
}

/**
 * Both directions of one stored speedtest (WAN 3.4).
 *
 * @param array<string, mixed> $test a speedtest_results row; `diagnostics` may
 *        already be decoded or still be the stored JSON string
 * @param array<string, mixed> $ctx  ['plan_down', 'plan_up', 'plan_ok_pct', 'threaded_napi']
 * @return array{dl: array<string, mixed>, ul: array<string, mixed>}
 */
function bk_wan_test_verdict(array $test, array $ctx): array {
    $diag = $test['diagnostics'] ?? null;
    if (is_string($diag)) {
        $diag = json_decode($diag, true);
    }
    if (!is_array($diag)) {
        $diag = [];
    }
    $started_by = isset($test['source']) && is_string($test['source']) ? $test['source'] : null;
    $id = $test['id'] ?? ($test['measured_at'] ?? null);
    $basis = $id === null ? [] : [is_numeric($id) ? (int)$id : (string)$id];
    $out = [];
    foreach (['dl', 'ul'] as $dir) {
        $out[$dir] = bk_wan_dir_verdict(bk_wan_dir_inputs($test, $diag, $dir, $ctx), $started_by, $basis);
    }
    return $out;
}

/**
 * Is this speedtest one the aggregate may stand on (WAN 3.4, "Aggregation")?
 *
 * Turris-started files never enter it: they carry no phases, so they can only
 * ever show rules 1-3 and would drag the agreement count with results nobody
 * could classify. A result whose units did not check out is excluded too - the
 * classifier must not average a number of unknown unit.
 */
function bk_wan_test_countable(array $test, array $diag, array $in, int $now, int $days = 21): bool {
    if (($test['source'] ?? null) !== 'agent') {
        return false;
    }
    if (!empty($diag['unit_mismatch'])) {
        return false;
    }
    $ts = isset($test['measured_at']) ? strtotime((string)$test['measured_at']) : false;
    if ($ts === false || $ts < $now - $days * 86400) {
        return false;
    }
    // The three gates that say the probe itself was not clean. X27's overlap
    // is the one WAN names explicitly: such a test "does not count towards
    // the 3 valid probes".
    return $in['s'] !== null && $in['path_verified'] !== false
        && !($in['minute_overlap_secs'] !== null && $in['minute_overlap_secs'] > 0);
}

/**
 * The verdict of a ROUTER, per direction, over its last probes (WAN 3.4).
 *
 * One test can be wrong in a dozen ways, so nothing is declared from one: the
 * last three valid agent probes of 21 days have to agree, and a `line_limited`
 * verdict - the only one that blames somebody else's equipment - has to pass
 * four more guards before it is said out loud. Everything it cannot prove
 * comes back as `inconclusive` with the reason, never as a softer claim.
 *
 * @param list<array<string, mixed>> $tests speedtest_results rows, any order
 * @param array<string, mixed> $ctx ['plan_down', 'plan_up', 'plan_ok_pct', 'threaded_napi',
 *        'now', 'server_max' => [server => ['dl' => ?float, 'ul' => ?float]]]
 * @return array{dl: array<string, mixed>, ul: array<string, mixed>}
 */
function bk_wan_bottleneck(array $tests, array $ctx): array {
    $now = (int)($ctx['now'] ?? time());
    $out = [];
    foreach (['dl', 'ul'] as $dir) {
        $valid = [];
        foreach ($tests as $test) {
            if (!is_array($test)) {
                continue;
            }
            $diag = $test['diagnostics'] ?? null;
            if (is_string($diag)) {
                $diag = json_decode($diag, true);
            }
            if (!is_array($diag)) {
                $diag = [];
            }
            $in = bk_wan_dir_inputs($test, $diag, $dir, $ctx);
            if (!bk_wan_test_countable($test, $diag, $in, $now)) {
                continue;
            }
            $valid[] = ['test' => $test, 'in' => $in, 'ts' => (int)strtotime((string)$test['measured_at']),
                'verdict' => bk_wan_dir_verdict($in, 'agent', [])];
        }
        usort($valid, fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);
        $valid = array_slice($valid, 0, 3);
        $out[$dir] = bk_wan_aggregate($valid, $dir, $ctx);
    }
    return $out;
}

/**
 * Agreement, confidence and the four `line_limited` guards (WAN 3.4).
 *
 * @param list<array<string, mixed>> $valid newest first, at most three
 * @return array<string, mixed>
 */
function bk_wan_aggregate(array $valid, string $dir, array $ctx): array {
    $n = count($valid);
    $basis = [];
    foreach ($valid as $v) {
        $id = $v['test']['id'] ?? ($v['test']['measured_at'] ?? null);
        if ($id !== null) {
            $basis[] = is_numeric($id) ? (int)$id : (string)$id;
        }
    }
    $verdict = function (string $class, ?string $reason, ?string $conf, array $numbers) use ($basis): array {
        return ['class' => $class, 'reason' => $reason, 'confidence' => $conf,
            'basis' => $basis, 'numbers' => $numbers + ['tests' => count($basis)]];
    };
    if ($n < 2) {
        return $verdict('inconclusive', 'not_enough_tests', null, ['have' => $n, 'needed' => 2 - $n]);
    }

    // The modal class+reason. A tie between two pairs is a disagreement.
    $groups = [];
    foreach ($valid as $i => $v) {
        $key = (string)$v['verdict']['class'] . '/' . (string)($v['verdict']['reason'] ?? '');
        $groups[$key][] = $i;
    }
    uasort($groups, fn (array $a, array $b): int => count($b) <=> count($a));
    $top = array_key_first($groups);
    $members = $groups[$top];
    $counts = array_map('count', $groups);
    sort($counts);
    if (count($members) < 2 || (count($counts) > 1 && $counts[count($counts) - 1] === $counts[count($counts) - 2])) {
        return $verdict('inconclusive', 'tests_disagree', null, ['agree' => count($members)]);
    }
    [$class, $reason] = array_pad(explode('/', $top, 2), 2, '');
    $conf = (count($members) === $n && $n >= 3) ? 'high' : 'medium';

    $agreeing = array_map(fn (int $i): array => $valid[$i], $members);
    $numbers = bk_wan_agree_numbers($agreeing, $conf);
    if ($class !== 'line_limited') {
        return $verdict($class, $reason === '' ? null : $reason, $conf, $numbers);
    }
    return bk_wan_line_guards($agreeing, $dir, $ctx, $reason, $conf, $numbers, $verdict);
}

/** The figures the agreeing tests share: rate, span, servers. */
function bk_wan_agree_numbers(array $agreeing, string $conf): array {
    $rates = $days = $servers = [];
    foreach ($agreeing as $v) {
        if ($v['in']['s'] !== null) {
            $rates[] = $v['in']['s'];
        }
        $days[date('Y-m-d', $v['ts'])] = true;
        $name = $v['test']['server_name'] ?? null;
        if (is_string($name) && $name !== '') {
            $servers[$name] = true;
        }
    }
    // The CPU figures of the NEWEST agreeing test, not an average of three:
    // the weekly rule quotes one measurement and has to be able to say which.
    $newest = $agreeing[0]['in'];
    return [
        's_mbps' => $rates === [] ? null : round(min($rates), 2),
        's_max_mbps' => $rates === [] ? null : round(max($rates), 2),
        'agree' => count($agreeing),
        'confidence_from' => $conf,
        'span_days' => count($days),
        'servers' => count($servers),
        'last_at' => date('Y-m-d H:i:s', (int)$agreeing[0]['ts']),
        'server' => is_string($agreeing[0]['test']['server_name'] ?? null)
            ? $agreeing[0]['test']['server_name'] : null,
        'core' => $newest['core'],
        'core_busy_pct' => $newest['core_busy_pct'],
        'net_share_pct' => bk_wan_core_net_share($newest),
        'all_cores_avg_pct' => $newest['all_cores_avg_pct'],
        'link_mbit' => $newest['l'],
    ];
}

/**
 * The four guards a `line_limited` verdict has to pass (WAN 3.4).
 *
 * Agreement alone does not prove the LINE is the limit: two test servers can
 * hit the same ceiling that is not the line. LibreSpeed servers commonly sit
 * on gigabit ports, so on a 2000 Mbit plan two of them would agree on about
 * 940 Mbit/s with an idle router and the owner would be told to call the ISP
 * about their own test infrastructure.
 *
 * Each guard answers `inconclusive` with its own reason and the numbers that
 * say why, so the card never has to guess what was missing.
 *
 * @param list<array<string, mixed>> $agreeing
 * @param callable(string, ?string, ?string, array): array $verdict
 * @return array<string, mixed>
 */
function bk_wan_line_guards(array $agreeing, string $dir, array $ctx, string $reason, string $conf, array $numbers, callable $verdict): array {
    // 1. One evening is not a week: the same congested hour twice is one
    // observation, and WAN asks for a span of at least two days.
    if (($numbers['span_days'] ?? 0) < 2) {
        return $verdict('inconclusive', 'not_enough_tests', null,
            $numbers + ['have' => count($agreeing), 'needed' => 1]);
    }
    if (($numbers['servers'] ?? 0) < 2) {
        return $verdict('inconclusive', 'single_server', null, $numbers);
    }
    $min = $numbers['s_mbps'];
    $max = $numbers['s_max_mbps'];
    // 2. Servers that differ by more than 15 % measured two different things;
    // the faster one is then the line's lower bound, and that is all.
    if ($min !== null && $max !== null && $min > 0 && ($max - $min) > 0.15 * $max) {
        return $verdict('inconclusive', 'server_limited', null,
            $numbers + ['s_lower_bound_mbps' => $max]);
    }

    $cap = $agreeing[0]['in']['cap'];
    $plan = $agreeing[0]['in']['p'];
    // 3. Server capacity is never assumed - the Turris list publishes none.
    // It counts as proven when one of the agreeing servers has really
    // delivered 0.85 x cap in this direction within 90 days, to any router.
    $proven = false;
    $server_max = is_array($ctx['server_max'] ?? null) ? $ctx['server_max'] : [];
    foreach ($agreeing as $v) {
        $name = (string)($v['test']['server_name'] ?? '');
        $seen = $server_max[$name][$dir] ?? null;
        if ($cap !== null && is_numeric($seen) && (float)$seen >= 0.85 * $cap) {
            $proven = true;
            break;
        }
    }
    if (!$proven) {
        return $verdict('inconclusive', 'server_capacity_unproven', null,
            $numbers + ['cap_mbit' => $cap === null ? null : round($cap, 2)]);
    }

    // 4. A goodput plateau of a 1 G or 2.5 G port BELOW the plan: something
    // with a slower port is in the path and the data cannot say whose.
    if ($plan !== null && $min !== null && $max !== null && $max < $plan) {
        foreach ([[880.0, 950.0], [2200.0, 2380.0]] as [$lo, $hi]) {
            if ($min >= $lo && $max <= $hi) {
                return $verdict('inconclusive', 'port_plateau', null,
                    $numbers + ['plateau_mbit' => [$lo, $hi]]);
            }
        }
    }
    return $verdict('line_limited', $reason === '' ? null : $reason, $conf, $numbers);
}

/** One event of the router rules. `status` null = timeline only, no notification (X14). */
function bk_router_alert_event(string $type, ?string $status, string $message): array {
    return ['type' => $type, 'status' => $status, 'message' => mb_substr($message, 0, 255)];
}

/**
 * Link rate of the WAN port against its baseline (W14, alert sheet 2.2).
 *
 * The baseline is the highest rate ever seen, `{mbit, since}`. A reseated SFP
 * or a bad module that halves the line is invisible otherwise: the interface
 * stays up and every other signal looks healthy.
 *
 * It is re-learned DOWNWARDS after seven days at the lower rate, because an
 * ISP that really moved the customer to a slower port must not produce an
 * alert every week for ever; from then on the weekly rule `wan_link_below_plan`
 * carries it. That re-learn clears the latch SILENTLY - a `wan_link_restored`
 * would claim the port came back, which is the opposite of what happened.
 *
 * @param array<string, mixed> $st
 * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
 */
function bk_wan_link_baseline_rules(array $st, ?float $mbit, ?string $dev, int $now): array {
    $events = [];
    if ($mbit === null || $mbit <= 0) {
        return [$st, $events]; // no reading, no verdict - the latch carries over
    }
    $where = $dev !== null ? ' (' . $dev . ')' : '';
    $base = is_array($st['wan_link_baseline'] ?? null) ? $st['wan_link_baseline'] : null;
    $base_mbit = bk_ranged_num($base['mbit'] ?? null, 0.1, 10000000.0);
    if ($base_mbit === null) {
        $st['wan_link_baseline'] = ['mbit' => $mbit, 'since' => $now];
        $st['wan_link_low_since'] = null;
        $st['wan_link_bad_streak'] = 0;
        return [$st, $events];
    }
    if ($mbit >= $base_mbit) {
        if ($mbit > $base_mbit) {
            $st['wan_link_baseline'] = ['mbit' => $mbit, 'since' => $now];
        }
        $st['wan_link_low_since'] = null;
        $st['wan_link_bad_streak'] = 0;
        if (!empty($st['wan_link_alert_sent'])) {
            $events[] = bk_router_alert_event('wan_link_restored', 'wan_link_restored',
                sprintf('Port WAN%s je opět spojený rychlostí %d Mbit/s.', $where, (int)round($mbit)));
            $st['wan_link_alert_sent'] = false;
        }
        return [$st, $events];
    }

    $low_since = bk_ranged_int($st['wan_link_low_since'] ?? null, 1, 4102444800) ?? $now;
    $st['wan_link_low_since'] = $low_since;
    if ($now - $low_since >= 7 * 86400) {
        $st['wan_link_baseline'] = ['mbit' => $mbit, 'since' => $now];
        $st['wan_link_low_since'] = null;
        $st['wan_link_bad_streak'] = 0;
        $st['wan_link_alert_sent'] = false;
        return [$st, $events];
    }
    $st['wan_link_bad_streak'] = (int)($st['wan_link_bad_streak'] ?? 0) + 1;
    if (!empty($st['wan_link_alert_sent']) || $st['wan_link_bad_streak'] < 3) {
        return [$st, $events];
    }
    // Three reports in a row: not a renegotiation, a link that stayed slower.
    $events[] = bk_router_alert_event('wan_link_degraded', 'wan_link_degraded',
        sprintf('Port WAN%s je spojený rychlostí %d Mbit/s, dosud %d Mbit/s.', $where, (int)round($mbit), (int)round($base_mbit)));
    $st['wan_link_alert_sent'] = true;
    return [$st, $events];
}

/**
 * The router latches and events of X14 / alert sheet 2.2, in one pass.
 *
 * All of them follow the debounce the WAN and LTE alerts already use: a
 * streak before the alert, a latch so it is sent once, and a recovery that
 * clears it. A signal the report does not carry (null) leaves its latch and
 * streak exactly as they were - "we do not know" is not "it is fine".
 *
 * `$state` is the subset of last_details these rules own; the returned state
 * is written back key by key as explicit `$new_data` entries.
 *
 * @param array<string, mixed> $cur   the sanitized reading of this report
 * @param array<string, mixed> $state last_details
 * @return array{events: list<array<string, mixed>>, state: array<string, mixed>}
 */
function bk_router_alert_eval(array $cur, array $state, int $now): array {
    $st = [
        'wan_link_baseline' => is_array($state['wan_link_baseline'] ?? null) ? $state['wan_link_baseline'] : null,
        'wan_link_low_since' => bk_ranged_int($state['wan_link_low_since'] ?? null, 1, 4102444800),
        'wan_link_bad_streak' => max(0, (int)($state['wan_link_bad_streak'] ?? 0)),
        'wan_link_alert_sent' => !empty($state['wan_link_alert_sent']),
        'conntrack_bad_streak' => max(0, (int)($state['conntrack_bad_streak'] ?? 0)),
        'conntrack_full_sent' => !empty($state['conntrack_full_sent']),
        'firewall_bad_streak' => max(0, (int)($state['firewall_bad_streak'] ?? 0)),
        'firewall_alert_sent' => !empty($state['firewall_alert_sent']),
        'firewall_off_since' => bk_ranged_int($state['firewall_off_since'] ?? null, 1, 4102444800),
        'dns_resolver_bad_streak' => max(0, (int)($state['dns_resolver_bad_streak'] ?? 0)),
        'dns_resolver_alert_sent' => !empty($state['dns_resolver_alert_sent']),
        'oom_kill_at' => bk_ranged_int($state['oom_kill_at'] ?? null, 1, 4102444800),
    ];
    $events = [];

    [$st, $link_events] = bk_wan_link_baseline_rules($st, bk_ranged_num($cur['wan_link_mbit'] ?? null, 0.0, 10000000.0),
        is_string($cur['wan_link_dev'] ?? null) ? $cur['wan_link_dev'] : null, $now);
    $events = array_merge($events, $link_events);

    // Connection table (W09). Two readings at 90 %, or a refused connection
    // measured in the same minute as a table that full: `conntrack_drop`
    // counts genuine clashes too, so below 90 % it is not evidence of a full
    // table (WAN 3.1.4). Clears five points lower, like every other threshold.
    $ct_pct = bk_ranged_num($cur['conntrack_pct'] ?? null, 0.0, 100.0);
    $ct_drops = bk_ranged_int($cur['conntrack_drops'] ?? null, 0, 2 ** 53);
    if ($ct_pct !== null) {
        if ($ct_pct >= 90.0) {
            $st['conntrack_bad_streak']++;
            $ct_refused = $ct_drops !== null && $ct_drops > 0;
            if (!$st['conntrack_full_sent'] && ($st['conntrack_bad_streak'] >= 2 || $ct_refused)) {
                $events[] = bk_router_alert_event('conntrack_full', 'conntrack_full',
                    sprintf('Tabulka spojení routeru je zaplněná z %d %% a nová spojení odmítá.', (int)round($ct_pct)));
                $st['conntrack_full_sent'] = true;
            }
        } elseif ($ct_pct < 85.0) {
            $st['conntrack_bad_streak'] = 0;
            if ($st['conntrack_full_sent']) {
                // Timeline only (X14): the table emptying is not news anybody
                // needs at night, but the page must show when it ended.
                $events[] = bk_router_alert_event('conntrack_normal', null,
                    sprintf('Tabulka spojení routeru klesla na %d %%.', (int)round($ct_pct)));
                $st['conntrack_full_sent'] = false;
            }
        } else {
            $st['conntrack_bad_streak'] = 0;
        }
    }

    // Firewall rules (G20). Only for a device with a WAN role: on a dumb AP a
    // disabled firewall is the recommended setup, and `wan_up` is exactly the
    // signal that distinguishes the two (the agent leaves it null without a
    // netifd `wan`). Three reports in a row, because a firewall restart is a
    // stop followed by a start.
    $fw = $cur['firewall_enabled'] ?? null;
    $has_wan_role = ($cur['wan_up'] ?? null) !== null;
    if (is_bool($fw) && $has_wan_role) {
        if ($fw === false) {
            $st['firewall_bad_streak']++;
            if ($st['firewall_off_since'] === null) {
                $st['firewall_off_since'] = $now;
            }
            if (!$st['firewall_alert_sent'] && $st['firewall_bad_streak'] >= 3) {
                $events[] = bk_router_alert_event('firewall_disabled', 'firewall_disabled',
                    'Router nemá načtená pravidla firewallu (3 hlášení po sobě).');
                $st['firewall_alert_sent'] = true;
            }
        } else {
            $st['firewall_bad_streak'] = 0;
            $st['firewall_off_since'] = null;
            if ($st['firewall_alert_sent']) {
                $events[] = bk_router_alert_event('firewall_restored', 'firewall_restored',
                    'Pravidla firewallu routeru jsou opět načtená.');
                $st['firewall_alert_sent'] = false;
            }
        }
    }

    // Local DNS resolver (G41). Judged only while the line itself works: with
    // the WAN down the `wan_lost` alert already speaks, and a resolver that
    // cannot reach the root servers is not a broken resolver. `wan_internet`
    // null = the agent could not measure it, so nothing is decided.
    $dns_ok = $cur['dns_resolver_ok'] ?? null;
    if (is_bool($dns_ok) && ($cur['wan_internet'] ?? null) === true) {
        if ($dns_ok === false) {
            $st['dns_resolver_bad_streak']++;
            if (!$st['dns_resolver_alert_sent'] && $st['dns_resolver_bad_streak'] >= 2) {
                $events[] = bk_router_alert_event('dns_resolver_failed', 'dns_resolver_failed',
                    'DNS resolver routeru neodpovídá (připojení k internetu funguje).');
                $st['dns_resolver_alert_sent'] = true;
            }
        } else {
            $st['dns_resolver_bad_streak'] = 0;
            if ($st['dns_resolver_alert_sent']) {
                $events[] = bk_router_alert_event('dns_resolver_restored', 'dns_resolver_restored',
                    'DNS resolver routeru znovu odpovídá.');
                $st['dns_resolver_alert_sent'] = false;
            }
        }
    }

    // Restart (G21). The cause is never claimed: the router records none.
    // Timeline only - the weekly rule `router_restarts` is what reaches the
    // e-mail, and a router that loses power every few days would otherwise
    // send an alert every few days.
    $uptime = bk_ranged_int($cur['uptime'] ?? null, 0, 2 ** 40);
    $prev_uptime = bk_ranged_int($state['uptime'] ?? null, 0, 2 ** 40);
    $rebooted = $uptime !== null && $prev_uptime !== null && $uptime < $prev_uptime;
    if ($rebooted) {
        $events[] = bk_router_alert_event('router_rebooted', null,
            sprintf('Router se restartoval (předchozí běh %s, nyní %s). Příčinu router nezaznamenává.',
                bk_format_duration_secs($prev_uptime), bk_format_duration_secs($uptime)));
    }

    // Out-of-memory kills (G31). The counter is cumulative since boot, so only
    // its GROWTH is an event; after a reboot it starts from zero and a lower
    // value says nothing. The timestamp is what limits the insight to 24 h.
    $oom = bk_ranged_int($cur['oom_kills'] ?? null, 0, 2 ** 40);
    $prev_oom = bk_ranged_int($state['oom_kills'] ?? null, 0, 2 ** 40);
    if ($oom !== null && $prev_oom !== null && !$rebooted && $oom > $prev_oom) {
        $events[] = bk_router_alert_event('oom_kill', null,
            sprintf('Jádro ukončilo %d proces(y) pro nedostatek paměti.', $oom - $prev_oom));
        $st['oom_kill_at'] = $now;
    }

    return ['events' => $events, 'state' => $st];
}

/**
 * G26: how many WireGuard peers the router really reported.
 *
 * The metric column has existed for a long time and was always NULL: the
 * payload key is a LIST of peers and `bk_agent_num()` of a list is null, so
 * the chart stayed empty and nothing said why. The count is what the column
 * means ("WireGuard protejsky" in the metric map). An agent that already
 * sends a number keeps working; anything that is neither is not measured.
 */
function bk_wireguard_peer_count($raw): ?int {
    if (is_array($raw)) {
        $peers = 0;
        foreach ($raw as $peer) {
            // A peer is an object (public_key, handshake, bytes) or, on an
            // older agent, the key itself. An empty slot is not a peer.
            if ((is_array($peer) && $peer !== []) || (is_string($peer) && trim($peer) !== '')) {
                $peers++;
            }
        }
        return $peers;
    }
    return bk_ranged_int($raw, 0, 4096);
}

/**
 * G42: how many minute reports a router COULD have sent in the last 24 h.
 *
 * The window starts at the later of "24 h ago" and the router's boot time: a
 * router that was powered off did not lose reports, it was off, and that is
 * G21's event, not a collection failure. Maintenance is excluded for the same
 * reason - nothing is expected while the monitor is knowingly silent.
 *
 * @param array<string, mixed> $monitor_row id, status, maintenance window
 * @return array{from: int, expected: int}
 */
function bk_reports_24h_expected(array $monitor_row, ?int $boot_time, int $now): array {
    $from = $now - 86400;
    if ($boot_time !== null && $boot_time > $from && $boot_time <= $now) {
        $from = $boot_time;
    }
    $minutes = max(0, intdiv($now - $from, 60));
    if (in_array(strtolower((string)($monitor_row['status'] ?? '')), ['paused', 'maintenance'], true)) {
        // Silent on purpose right now: nothing is expected, so nothing is missing.
        return ['from' => $from, 'expected' => 0];
    }
    if (!empty($monitor_row['maintenance'])) {
        $m_start = !empty($monitor_row['maintenance_start']) ? strtotime((string)$monitor_row['maintenance_start']) : false;
        $m_end = !empty($monitor_row['maintenance_end']) ? strtotime((string)$monitor_row['maintenance_end']) : false;
        if ($m_start !== false && $m_end !== false && $m_end > $from && $m_start < $now) {
            $overlap = min($now, $m_end) - max($from, $m_start);
            $minutes = max(0, $minutes - intdiv(max(0, $overlap), 60));
        }
    }
    return ['from' => $from, 'expected' => $minutes];
}

/**
 * The hourly `reports_24h` pass of cron (G42).
 *
 * One indexed COUNT per agent monitor per hour, no more: the banner has to be
 * able to say "142 of 1440 minutes are missing", and the pure
 * bk_get_collection_issues() has no $pdo to count with. The result goes into
 * last_details on a FRESH read, because an agent report lands in the same
 * column every minute and only this one key is this pass's to change.
 *
 * @return int how many monitors were recounted
 */
function bk_update_reports_24h(PDO $pdo, ?int $now = null): int {
    $now = $now ?? time();
    $updated = 0;
    try {
        $stmt = $pdo->query("SELECT id, status, maintenance, maintenance_start, maintenance_end, last_details
            FROM monitors WHERE agent_key IS NOT NULL AND archived_at IS NULL");
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
    } catch (PDOException $e) {
        error_log('[cron] reports_24h: seznam monitorů selhal: ' . $e->getMessage());
        return 0;
    }
    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM vps_metrics WHERE monitor_id = ? AND checked_at >= FROM_UNIXTIME(?)");
    $stmt_fresh = $pdo->prepare("SELECT last_details FROM monitors WHERE id = ?");
    $stmt_save = $pdo->prepare("UPDATE monitors SET last_details = ? WHERE id = ?");
    foreach ($rows as $row) {
        $details = json_decode((string)($row['last_details'] ?? '{}'), true);
        if (!is_array($details)) {
            $details = [];
        }
        // Only routers and servers that really report: a monitor whose agent
        // never sent anything has no agent_last_seen and nothing to compare.
        if (empty($details['agent_last_seen'])) {
            continue;
        }
        $last = $details['reports_24h']['checked_at'] ?? null;
        if (is_int($last) && ($now - $last) < 3600) {
            continue;
        }
        $window = bk_reports_24h_expected($row, bk_ranged_int($details['boot_time'] ?? null, 1, 4102444800), $now);
        try {
            $stmt_count->execute([(int)$row['id'], $window['from']]);
            $received = (int)$stmt_count->fetchColumn();
            $stmt_fresh->execute([(int)$row['id']]);
            $fresh = json_decode((string)($stmt_fresh->fetchColumn() ?: '{}'), true);
            if (!is_array($fresh)) {
                $fresh = [];
            }
            $fresh['reports_24h'] = ['expected' => $window['expected'], 'received' => $received, 'checked_at' => $now];
            $stmt_save->execute([json_encode($fresh, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
            $updated++;
        } catch (PDOException $e) {
            error_log('[cron] reports_24h selhalo pro monitor ' . $row['id'] . ': ' . $e->getMessage());
        }
    }
    return $updated;
}

/**
 * Asset Overview - metric context (24h average/min/max, trend, top process).
 */
function bk_metric_context($pdo, $monitor_id, $metric_column, $current_value) {
    $ctx = ['avg' => null, 'min' => null, 'max' => null, 'trend' => 'stable', 'top_process' => null];
    $allowed_cols = [
        'cpu_usage', 'ram_usage', 'hdd_usage', 'net_usage',
        'load_avg_1', 'load_avg_5', 'load_avg_15', 'cpu_steal', 'swap_usage',
        'disk_io_read_kbps', 'disk_io_write_kbps', 'net_errors',
        'iowait_pct', 'inode_usage_pct', 'zombie_count', 'fork_rate', 'temperature_c',
        'wifi_clients_total', 'conntrack_pct', 'net_ipv4_kbps', 'net_ipv6_kbps',
        // Router metrics of agent 0.1.7. The five STEP columns (wan_errors,
        // wan_drops, wan_ring_drops, wan_link_flaps, conntrack_drops) are
        // deliberately absent: this context is a 24h average of a level, and
        // the average of a per-minute step says nothing a reader could use.
        'wifi_noise_24g', 'wifi_noise_5g', 'wifi_noise_6g',
        'wifi_busy_24g', 'wifi_busy_5g', 'wifi_busy_6g',
        'wifi_busy_other_24g', 'wifi_busy_other_5g', 'wifi_busy_other_6g',
        'wifi_weak_clients', 'wifi_wpa2_clients', 'wifi_6e_unserved', 'wifi_5g_capable_24g',
        'cpu_core_max', 'cpu_core_max_softirq', 'wan_rx_mbps', 'wan_tx_mbps',
        'agent_run_ms', 'clock_skew_s'
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
 * Asset Overview - 30 daily health dots (green/yellow/red).
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
 * Asset Overview - card profile for the given monitor type.
 * Returns an array ['key' => ['icon'=>, 'label_key'=>, 'source'=>]]
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
 * The Routers section of the weekly digest, as language-neutral data.
 *
 * Its own function, not twenty lines inside `build_digest_data`, because it
 * is the one part of the digest that can be tested against a database on its
 * own: what the engine found, which items are new this week and what the
 * snapshot did to `router_rec_state`.
 *
 * Returns ['routers' => [...], 'more' => int, 'critical' => [...]].
 */
/**
 * Which items the e-mail prints in full, and which are one title in the
 * "unchanged since last week" line.
 *
 * Full when the item is critical, when it has never been mailed, when it was
 * first mailed THIS week (so a retry between 08:00 and 12:00 renders the same
 * e-mail) or when its severity rose. Everything else is old news and the
 * reader has seen it; it is still listed, by title, so nothing silently
 * disappears.
 *
 * `$state` is the state as it was BEFORE this build saved anything, which is
 * why the rise is computed here and not read from `raised_digest_week`.
 */
function bk_digest_router_new_split(array $items, array $state, string $iso_week): array {
    $rank = bk_router_rec_thresholds()['severity'];
    $full = [];
    $open = [];
    foreach ($items as $item) {
        $prev = $state[(string)($item['key'] ?? '')] ?? null;
        $prev_sev = is_array($prev) ? (string)($prev['severity'] ?? '') : '';
        $rose = $prev_sev !== '' && ($rank[(string)$item['severity']] ?? 9) < ($rank[$prev_sev] ?? 9);
        $first_week = is_array($prev) ? ($prev['first_digest_week'] ?? null) : null;
        if (($item['severity'] ?? '') === 'critical' || $first_week === null || $first_week === $iso_week
            || (is_array($prev) && ($prev['raised_digest_week'] ?? null) === $iso_week) || $rose) {
            $full[] = $item;
        } else {
            $open[] = $item;
        }
    }
    return ['full' => $full, 'open' => $open];
}

function bk_digest_routers(PDO $pdo, bool $save_snapshot = true): array {
    // Language-neutral: the e-mail is rendered once per recipient language,
    // so a sentence built here would carry the language of whoever happened
    // to trigger cron.
    //
    // The build is repeated - `send_digest_report_inner` builds the data
    // before it even looks for recipients, and cron retries every minute from
    // 08:00 to 12:00 until one send succeeds - so it is bounded by design:
    // ids and names first (no `last_details`, up to 60 kB each), then chunks
    // of 50 with one details query and one inputs batch, and `facts` only for
    // the routers that are really rendered.
    $routers = [];
    $router_critical = [];
    $routers_more = 0;
    $rt_start = microtime(true);
    $iso_week = date('o-\WW');
    $end_day = date('Y-m-d');
    $rt_sev = bk_router_rec_thresholds()['severity'];
    $rt_list = $pdo->query("SELECT id, name FROM monitors WHERE type = 'openwrt' AND archived_at IS NULL ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach (array_chunk($rt_list, 50) as $rt_chunk) {
        $rt_ids = array_map(fn ($r) => (int)$r['id'], $rt_chunk);
        $rt_in = implode(',', array_fill(0, count($rt_ids), '?'));
        $stmt_rt = $pdo->prepare("SELECT id, name, type, hdd_threshold, last_details FROM monitors WHERE id IN ($rt_in)");
        $stmt_rt->execute($rt_ids);
        $rt_rows = [];
        foreach ($stmt_rt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rt_rows[(int)$row['id']] = $row;
        }
        $rt_inputs = bk_router_rec_inputs_batch($pdo, $rt_ids, $end_day);
        foreach ($rt_ids as $rid) {
            $row = $rt_rows[$rid] ?? null;
            if ($row === null) {
                continue;
            }
            $details = json_decode((string)($row['last_details'] ?? ''), true);
            $in = $rt_inputs[$rid] ?? ['window' => bk_router_rec_window($end_day), 'disks' => [], 'state' => []];
            $in['monitor'] = $row;
            $in['details'] = is_array($details) ? $details : [];
            $in['now'] = time();
            $res = bk_router_rec_evaluate($in);
            $state = $in['state'];
            // The snapshot is written BEFORE the split, from every item the
            // rules produced: a muted item is still open, and a page-only
            // item still has to keep its state (X12).
            if ($save_snapshot) {
                bk_router_rec_state_save($pdo, $rid, $res['items'], $iso_week, $res['not_evaluated'], $state);
            }
            $split = bk_router_rec_split($res['items'], $state);
            $visible = [];
            foreach ($split['items'] as $item) {
                if (empty($item['page_only'])) {
                    $visible[] = $item;
                }
            }
            // New / unchanged is decided against the state as it was BEFORE
            // this build wrote anything, so a retry in the same week and a
            // manual send render exactly the same split.
            ['full' => $full, 'open' => $open] = bk_digest_router_new_split($visible, $state, $iso_week);
            $worst = 9;
            foreach ($visible as $item) {
                $worst = min($worst, $rt_sev[(string)$item['severity']] ?? 9);
            }
            foreach ($full as $item) {
                if ($item['severity'] === 'critical' && count($router_critical) < 5) {
                    $router_critical[] = $item + ['monitor_id' => $rid, 'name' => (string)$row['name']];
                }
            }
            // Only what the section prints survives the loop; the details
            // blob and the inputs of this router are released with it.
            $routers[] = [
                'id' => $rid,
                'name' => (string)$row['name'],
                'applicable' => (bool)$res['applicable'],
                'reason' => $res['reason'],
                'reason_params' => $res['params'] ?? [],
                'days_with_data' => (int)$res['days_with_data'],
                'worst' => $worst,
                'facts' => [],
                'items_full' => array_slice($full, 0, 6),
                'items_open' => $open,
                'more' => max(0, count($full) - 6),
            ];
        }
    }
    // Worst severity first, then name - the router that needs reading is
    // at the top of the section, not the one with the lowest id.
    usort($routers, fn ($a, $b) => $a['worst'] <=> $b['worst'] ?: strcasecmp($a['name'], $b['name']));
    if (count($routers) > 10) {
        $routers_more = count($routers) - 10;
        $routers = array_slice($routers, 0, 10);
    }
    $routers = bk_digest_router_facts($pdo, $routers);
    $rt_ms = (int)round((microtime(true) - $rt_start) * 1000);
    // One line so a regression of last_cron_duration_ms is attributable.
    error_log(sprintf('[digest] Doporučení routerů: %d routerů, %d ms.', count($rt_list), $rt_ms));

    return ['routers' => $routers, 'more' => $routers_more, 'critical' => $router_critical];
}

/**
 * The facts line of the routers the digest really prints.
 *
 * It runs AFTER the sort and only for the at most ten routers that are
 * rendered: the radios and the disks come from their `last_details`, the
 * week's noise and airtime from one more inputs batch of the same ten ids.
 * Building facts for every router would mean keeping every `last_details`
 * (up to 60 kB each) alive for the whole build.
 *
 * Unknown values stay null; the renderer omits them instead of printing a
 * zero nobody measured.
 */
function bk_digest_router_facts(PDO $pdo, array $routers): array {
    $ids = [];
    foreach ($routers as $r) {
        if (!empty($r['applicable'])) {
            $ids[] = (int)$r['id'];
        }
    }
    if (!$ids) {
        return $routers;
    }
    $in_list = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, last_details FROM monitors WHERE id IN ($in_list)");
    $stmt->execute($ids);
    $details = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = json_decode((string)$row['last_details'], true);
        $details[(int)$row['id']] = is_array($d) ? $d : [];
    }
    $inputs = bk_router_rec_inputs_batch($pdo, $ids, date('Y-m-d'));
    $band_key = ['2.4GHz' => '24g', '5GHz' => '5g', '6GHz' => '6g'];

    foreach ($routers as $i => $r) {
        $id = (int)$r['id'];
        if (!isset($details[$id])) {
            continue;
        }
        $d = $details[$id];
        $window = $inputs[$id]['window'] ?? ['metrics' => []];
        $radios = [];
        foreach (is_array($d['wifi_radios'] ?? null) ? $d['wifi_radios'] : [] as $radio) {
            if (!is_array($radio)) {
                continue;
            }
            $band = is_string($radio['band'] ?? null) ? $radio['band'] : null;
            $suffix = $band_key[$band] ?? null;
            $noise = $suffix !== null ? bk_rec_week_stat($window['metrics'] ?? [], 'wifi_noise_' . $suffix) : null;
            $busy = $suffix !== null ? bk_rec_week_stat($window['metrics'] ?? [], 'wifi_busy_' . $suffix) : null;
            // The agent sends `htmode`, not a generation: HE80 is Wi-Fi 6 on
            // 80 MHz. One helper decides that for the card, the rules and here.
            $profile = bk_wifi_radio_profile($radio);
            $radios[] = [
                'band' => $band,
                'channel' => isset($radio['channel']) && is_numeric($radio['channel']) ? (int)$radio['channel'] : null,
                'generation' => $profile['generation'],
                'width_mhz' => $profile['width_mhz'],
                'encryption' => $radio['encryption'] ?? null,
                'clients' => isset($radio['clients']) && is_numeric($radio['clients']) ? (int)$radio['clients'] : null,
                'clients_gen' => is_array($radio['clients_gen'] ?? null) ? $radio['clients_gen'] : null,
                'noise_week' => $noise['value'] ?? null,
                'busy_week' => $busy['value'] ?? null,
            ];
        }
        $disks = [];
        foreach (is_array($d['storage_disks'] ?? null) ? $d['storage_disks'] : [] as $disk) {
            if (!is_array($disk)) {
                continue;
            }
            $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
            $disks[] = [
                'name' => $disk['name'] ?? null,
                'model' => $smart['model'] ?? ($disk['model'] ?? null),
                'transport' => $disk['transport'] ?? null,
                'rotational' => $disk['rotational'] ?? null,
                'size_bytes' => isset($disk['size_bytes']) && is_numeric($disk['size_bytes']) ? (int)$disk['size_bytes'] : null,
                'smart_state' => $smart['state'] ?? null,
                'smart_passed' => $smart['passed'] ?? null,
                'temperature_c' => isset($smart['temperature_c']) && is_numeric($smart['temperature_c']) ? (int)$smart['temperature_c'] : null,
                'wear_pct' => isset($smart['wear_pct']) && is_numeric($smart['wear_pct']) ? (float)$smart['wear_pct'] : null,
                'written_bytes' => isset($smart['written_bytes']) && is_numeric($smart['written_bytes']) ? (int)$smart['written_bytes'] : null,
                'runtime_bad_blocks' => isset($smart['runtime_bad_blocks']) && is_numeric($smart['runtime_bad_blocks']) ? (int)$smart['runtime_bad_blocks'] : null,
                'reallocated_sectors' => isset($smart['reallocated_sectors']) && is_numeric($smart['reallocated_sectors']) ? (int)$smart['reallocated_sectors'] : null,
            ];
        }
        $routers[$i]['facts'] = ['radios' => $radios, 'disks' => $disks];
    }
    return $routers;
}

/**
 * Builds all the data for the infrastructure report (weekly/monthly). A purely
 * computational function without side effects, except writing the trend
 * snapshot into settings at the end (needed for the next report; log retention
 * makes it impossible otherwise - see the digest_snapshot_* comment below).
 */
function build_digest_data($pdo, $period = 'weekly', $save_snapshot = true) {
    $days = ($period === 'monthly') ? 30 : 7;
    $site_title = get_setting('site_title', 'Blood Kings Status');
    $range_from = date('d.m.Y', strtotime("-$days days"));
    $range_to = date('d.m.Y');

    // --- Main server / hub location (excluded from regions, same logic as index.php) ---
    $hub_location = trim(get_setting('cron_location', ''));
    if ($hub_location === '' || $hub_location === 'AUTO' || $hub_location === '🇨🇿 Praha, CZ') {
        $hub_location = trim(get_setting('ip_loc_local', ''));
    }

    // --- Trend snapshot from the previous period ---
    $snapshot_key = 'digest_snapshot_' . $period;
    $prev_snapshot = json_decode(get_setting($snapshot_key, ''), true);
    if (!is_array($prev_snapshot)) {
        $prev_snapshot = null;
    }

    // --- Core KPIs ---
    // Archived monitors are out of the digest, history included.
    [$dg_active, $dg_active_params] = bk_active_monitor_sql($pdo, 'monitor_id');
    $stmt_overall = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
            SUM(CASE WHEN status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count,
            SUM(CASE WHEN status = 'down' THEN 1 ELSE 0 END) as down_count,
            COUNT(*) as all_rows,
            AVG(CASE WHEN response_time > 0 THEN response_time END) as avg_latency
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND {$dg_active}
    ");
    $stmt_overall->execute(array_merge([$days], $dg_active_params));
    $overall = $stmt_overall->fetch();
    $total_checks = (int)($overall['all_rows'] ?? 0);
    // Availability in time, not in rows (W1-B1), summed over every active
    // monitor's seconds - the same definition as the SLA report in the app.
    // A period nobody measured is null (W1-B3): it used to print 100.000 %.
    // Calendar days, today included, like the SLA report.
    $dg_ids = array_map('intval', $pdo->query("SELECT id FROM monitors WHERE archived_at IS NULL AND type NOT IN ('node', 'probe')")->fetchAll(PDO::FETCH_COLUMN));
    $dg_time = [];
    foreach (bk_uptime_day_windows($pdo, $dg_ids, [$days]) as $dg_mid => $dg_win) {
        $dg_time[$dg_mid] = $dg_win[$days];
    }
    $dg_pct = bk_uptime_totals(array_values($dg_time))['pct'];
    $availability = $dg_pct !== null ? round($dg_pct, 3) : null;
    $incident_count = (int)($overall['down_count'] ?? 0);
    $avg_latency = $overall['avg_latency'] !== null ? (int)round($overall['avg_latency']) : null;

    // --- Agents (only those that ever actually reported - same logic as index.php) ---
    $offline_timeout_secs = max(0, (int)get_setting('agent_offline_timeout', '50')) * 60;
    $agent_count = 0;
    $stmt_agents = $pdo->query("SELECT last_details FROM monitors WHERE agent_key IS NOT NULL AND agent_key != '' AND archived_at IS NULL");
    while ($row = $stmt_agents->fetch()) {
        $det = json_decode($row['last_details'] ?? '', true);
        if (($det['agent_last_seen'] ?? 0) > 0) {
            $agent_count++;
        }
    }

    // --- Regions (per checked_from, availability + latency over the period) ---
    $stmt_regions = $pdo->prepare("
        SELECT checked_from,
               SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count,
               AVG(CASE WHEN response_time > 0 THEN response_time END) as avg_latency
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND checked_from IS NOT NULL AND {$dg_active}
              AND checked_from != 'Main Server'" . ($hub_location !== '' ? " AND checked_from != ?" : "") . "
        GROUP BY checked_from
        ORDER BY checked_from ASC
    ");
    $stmt_regions->execute(array_merge([$days], $dg_active_params, $hub_location !== '' ? [$hub_location] : []));
    $regions_raw = $stmt_regions->fetchAll();
    $regions = [];
    foreach ($regions_raw as $r) {
        // A region with no measured check in the window is left out -
        // it used to get an invented 100.0 % uptime.
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
    // --- The SSL/DNS summary is computed below, but the score needs the expiring/expired
    // certificate counts - so SSL data comes first and the score after it (see below, after the SSL section).

    // --- Best / worst monitors ---
    $stmt_worst = $pdo->prepare("
        SELECT l.monitor_id, m.name, m.type,
               SUM(CASE WHEN l.status = 'up' THEN 1 ELSE 0 END) as up_count,
               SUM(CASE WHEN l.status = 'down' THEN 1 ELSE 0 END) as down_count,
               SUM(CASE WHEN l.status IN ('up','down','warning') THEN 1 ELSE 0 END) as total_count
        FROM monitor_logs l
        JOIN monitors m ON m.id = l.monitor_id
        WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.archived_at IS NULL
        GROUP BY l.monitor_id, m.name, m.type
        ORDER BY down_count DESC
        LIMIT 30
    ");
    $stmt_worst->execute([$days]);
    $all_monitor_stats = $stmt_worst->fetchAll();
    // Each row carries its availability in time; the e-mail prints this
    // number, not a literal "100%" (a monitor with warnings is below 100).
    // An agent's silence is outage time without a single 'down' row, so
    // the best/worst split reads the outage seconds too, not only the rows.
    foreach ($all_monitor_stats as $i => $m) {
        $t = $dg_time[(int)$m['monitor_id']] ?? null;
        $all_monitor_stats[$i]['uptime_pct'] = isset($t['pct']) ? round((float)$t['pct'], 2) : null;
        $all_monitor_stats[$i]['outage_secs'] = (int)($t['outage'] ?? 0);
    }

    $worst_monitors = array_values(array_filter($all_monitor_stats, function ($m) {
        return (int)$m['down_count'] > 0 || $m['outage_secs'] > 0;
    }));
    usort($worst_monitors, function ($a, $b) {
        // The worst availability in time first; unmeasured ones last.
        return ($a['uptime_pct'] ?? 101) <=> ($b['uptime_pct'] ?? 101);
    });
    $worst_monitors = array_slice($worst_monitors, 0, 5);

    $best_monitors = array_values(array_filter($all_monitor_stats, function ($m) {
        return (int)$m['down_count'] === 0 && $m['outage_secs'] === 0 && $m['uptime_pct'] !== null;
    }));
    usort($best_monitors, function ($a, $b) {
        return $b['total_count'] <=> $a['total_count'];
    });
    $best_monitors = array_slice($best_monitors, 0, 4);

    // --- Agent Health (the last vps_metrics row per monitor in the window) ---
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
        WHERE m.archived_at IS NULL
    ");
    $stmt_agent_health->execute([$days]);
    $agent_health = $stmt_agent_health->fetchAll();

    // --- SSL summary (the last check_stages per 'web' monitor in the window) ---
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
        WHERE m.type = 'web' AND m.archived_at IS NULL
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

    // --- Config change events of this period (renewed is counted from events, not the current state) ---
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
        $bk_digest_link_events = [
            'lte_backup_lost' => 'digest_event_lte_backup_lost',
            'lte_backup_restored' => 'digest_event_lte_backup_restored',
            'wan_lost' => 'digest_event_wan_lost',
            'wan_restored' => 'digest_event_wan_restored',
            // Disk events of a router. Same reason as above for translating
            // the label instead of the stored description: the description
            // names the model and the counter values in operator Czech.
            'disk_smart_failed' => 'digest_event_disk_smart_failed',
            'disk_errors_growing' => 'digest_event_disk_errors_growing',
            'disk_wear_high' => 'digest_event_disk_wear_high',
            'disk_emmc_eol' => 'digest_event_disk_emmc_eol',
            'disk_temp_critical' => 'digest_event_disk_temp_critical',
            'fs_full' => 'digest_event_fs_full',
            'disk_replaced' => 'digest_event_disk_replaced',
            // The seven NOTIFYING router events of alert sheet 2.2. The
            // timeline-only ones (conntrack_normal, router_rebooted, oom_kill)
            // stay out on purpose: restarts reach the e-mail through the rule
            // `router_restarts`, and a router that loses power every few days
            // would bury the whole section.
            'wan_link_degraded' => 'digest_event_wan_link_degraded',
            'wan_link_restored' => 'digest_event_wan_link_restored',
            'conntrack_full' => 'digest_event_conntrack_full',
            'firewall_disabled' => 'digest_event_firewall_disabled',
            'firewall_restored' => 'digest_event_firewall_restored',
            'dns_resolver_failed' => 'digest_event_dns_resolver_failed',
            'dns_resolver_restored' => 'digest_event_dns_resolver_restored',
        ];
        if ($ev['event_type'] === 'monitor_added') {
            // monitor_id still exists here (the monitor was just added) - the link works.
            $new_servers[] = ['name' => $ev['monitor_name'], 'type' => $ev['monitor_type'], 'id' => $ev['monitor_id']];
        } elseif ($ev['event_type'] === 'monitor_removed') {
            // monitor_id is always NULL here (ON DELETE SET NULL - the monitor no
            // longer exists, which is why this is logged at all), so the link never works.
            $removed_servers[] = ['name' => $ev['monitor_name'], 'type' => $ev['monitor_type'], 'id' => null];
        } elseif (isset($bk_digest_link_events[$ev['event_type']])) {
            // Translated at render time, not copied from the stored description:
            // the description is the operator-language reason text ("SIM karta
            // čeká na PIN…"), and the digest renders per recipient language. The
            // reason itself stays in the timeline and in the alert that fired.
            $config_change_examples[] = $ev['monitor_name'] . ': ' . t($bk_digest_link_events[$ev['event_type']]);
        } elseif (in_array($ev['event_type'], ['scheme_upgraded', 'dns_lost', 'dns_recovered', 'cert_renewed', 'agent_connected', 'agent_disconnected'], true)) {
            $config_change_examples[] = $ev['monitor_name'] . ': ' . $ev['description'];
        }
    }
    $certs_renewed = $event_counts['cert_renewed'] ?? 0;

    // --- Infrastructure Score (after the SSL data, see above) ---
    $score = bk_infra_score($availability, $avg_latency, $incident_count, $certs_expiring, $certs_expired);

    // --- Trends vs. the previous period ---
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
    // No website with DNS data -> no DNS health, not 100.
    $dns_health = $total_checks > 0 && count($ssl_rows) > 0 ? round((1 - $dns_failures / count($ssl_rows)) * 100, 1) : null;
    $trend_dns = bk_trend_direction($dns_health, $prev_snapshot['dns_health'] ?? null, 0.5);

    // --- Biggest changes (latency by region vs. the stored snapshot) ---
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

    // --- Performance (best/worst region by latency) ---
    $perf_best = null;
    $perf_worst = null;
    foreach ($regions as $r) {
        if ($r['avg_latency'] === null) continue;
        if ($perf_best === null || $r['avg_latency'] < $perf_best['avg_latency']) $perf_best = $r;
        if ($perf_worst === null || $r['avg_latency'] > $perf_worst['avg_latency']) $perf_worst = $r;
    }

    // --- Biggest incident (approximation: contiguous runs of 'down' rows, a gap > 15 min = a new incident) ---
    $stmt_down = $pdo->prepare("
        SELECT l.monitor_id, m.name, l.checked_at, l.checked_from, l.error_message, m.status as current_status
        FROM monitor_logs l
        JOIN monitors m ON m.id = l.monitor_id
        WHERE l.checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND l.status = 'down' AND m.archived_at IS NULL
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

    // --- Recommendations (reused as the "warnings" count too) ---
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
    // Monitors without IPv6 (current last_details, 'web' type only)
    $stmt_ipv6 = $pdo->query("SELECT name, last_details FROM monitors WHERE type = 'web' AND archived_at IS NULL");
    foreach ($stmt_ipv6->fetchAll() as $m) {
        $ld = json_decode($m['last_details'] ?? '', true);
        if (is_array($ld) && ($ld['has_ipv4'] ?? false) && empty($ld['has_ipv6'])) {
            $recommendations[] = sprintf(t('digest_no_ipv6'), $m['name']);
        }
    }
    if ($perf_worst !== null && $perf_worst['avg_latency'] !== null && $perf_worst['avg_latency'] > 200) {
        $recommendations[] = sprintf(t('digest_high_latency'), $perf_worst['name'], $perf_worst['avg_latency']);
    }
    // --- Routers: the weekly recommendations (CORE 3.8) -------------------
    // Weekly only: the section asks what a WEEK of measurements says.
    $routers = [];
    $routers_more = 0;
    $router_critical = [];
    if ($period === 'weekly') {
        $rt = bk_digest_routers($pdo, (bool)$save_snapshot);
        $routers = $rt['routers'];
        $routers_more = $rt['more'];
        $router_critical = $rt['critical'];
    }

    // Router items that page are warnings of the week too - the stat box must
    // not say "0 warnings" above a critical disk.
    $warning_count = count($recommendations) + count($router_critical);

    // --- Executive Summary (rule-generated sentences, not AI) ---
    $executive_summary = [];
    if ($score === null) {
        // Nothing measured: no verdict either way (W1-B3).
        $executive_summary[] = t('digest_summary_no_data');
    } elseif ($score >= 95) {
        $executive_summary[] = t('digest_summary_healthy');
    } elseif ($score >= 80) {
        $executive_summary[] = t('digest_summary_mostly_healthy');
    } else {
        $executive_summary[] = t('digest_summary_needs_attention');
    }
    if ($availability !== null) {
        $executive_summary[] = sprintf(t('digest_summary_availability'), number_format($availability, 3, ',', ' '));
    }
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
    } elseif (!empty($router_critical)) {
        // `$recommendations` is a list of STRINGS everywhere it is consumed, so
        // a router item cannot be pushed into it. The summary line carries the
        // neutral item instead and render_digest_html turns it into a sentence
        // in the recipient's language.
        $executive_summary[] = ['key' => 'digest_summary_recommended_action', 'rec' => $router_critical[0]];
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
        'routers' => $routers,
        'routers_more' => $routers_more,
        'router_critical' => $router_critical,
        'executive_summary' => $executive_summary,
    ];

    if ($period === 'monthly') {
        $data['monthly'] = build_monthly_digest_extras($pdo, $days, $regions, $prev_snapshot, $score, $event_counts);
    }

    // --- Store the snapshot for the next period (availability, latency, score, regions, ...) ---
    // Skipped for previews - repeated viewing would otherwise overwrite the
    // comparison base before the corresponding period actually runs.
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
            // Ignored - the report still goes out, only the next trend will lack a comparison
        }
    }

    return $data;
}

/**
 * Extra sections for the monthly report only (SLA, best/worst day, heatmaps, growth).
 */
function build_monthly_digest_extras($pdo, $days, $regions, $prev_snapshot, $score, $event_counts) {
    $sla_goal = (float)get_setting('sla_goal_pct', '99.95');

    // Best/worst day (the ratio is computed in PHP, not in ORDER BY - same reason as worst monitors above)
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

    // Best/worst region (from already computed data)
    $best_region = null;
    $worst_region = null;
    foreach ($regions as $r) {
        if ($best_region === null || $r['uptime'] > $best_region['uptime']) $best_region = $r;
        if ($worst_region === null || $r['uptime'] < $worst_region['uptime']) $worst_region = $r;
    }

    // Incident heatmap by day of week (aggregated over the whole month)
    $stmt_dow = $pdo->prepare("
        SELECT DAYOFWEEK(checked_at) as dow, COUNT(*) as cnt
        FROM monitor_logs
        WHERE checked_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND status = 'down'
        GROUP BY DAYOFWEEK(checked_at)
    ");
    $stmt_dow->execute([$days]);
    // MySQL DAYOFWEEK: 1=Sunday..7=Saturday -> mapped to neutral mon-sun keys.
    // The keys must stay language-neutral (not Czech day abbreviations), because translation
    // happens at render time (render_digest_html) - otherwise switching email_lang
    // to 'en' would have to change this array's structure, not just the displayed label.
    $dow_map = [2 => 'mon', 3 => 'tue', 4 => 'wed', 5 => 'thu', 6 => 'fri', 7 => 'sat', 1 => 'sun'];
    $incident_heatmap = ['mon' => 0, 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0];
    foreach ($stmt_dow->fetchAll() as $row) {
        $label = $dow_map[(int)$row['dow']] ?? null;
        if ($label !== null) $incident_heatmap[$label] = (int)$row['cnt'];
    }

    // Latency heatmap - from the regions, coloured by band
    $latency_heatmap = [];
    foreach ($regions as $r) {
        if ($r['avg_latency'] === null) continue;
        $band = $r['avg_latency'] < 50 ? 'green' : ($r['avg_latency'] < 150 ? 'yellow' : 'red');
        $latency_heatmap[] = ['region' => $r['name'], 'ms' => $r['avg_latency'], 'band' => $band];
    }

    // Growth
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
 * Shared wrapper (header/footer/base styles) for infrastructure report e-mails.
 * Separate from the alert template in trigger_notifications() - that one stays unchanged.
 */
function render_email_wrapper($title, $subtitle, $accent_color, $body_html) {
    // The whole layout is inline (+ real <table>), not a <style> block - Gmail,
    // Outlook and most webmails strip <style>/<head> on delivery, so the e-mail
    // would arrive unformatted. Same approach as trigger_notifications().
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
 * Wraps several bk_email_stat_box() cells into a real <table><tr> - e-mail
 * clients do not respect CSS "display: table" (the former .stat-grid).
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
 * Opening <table><thead> for the digest's report-table sections (replaces the
 * former .report-table CSS class, which e-mail clients ignore). $headers is an
 * array of labels; the first sits left, the rest align right (numeric columns).
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
 * The grey facts line under a router's name: what the week's radios and disks
 * ARE, next to what the rules say about them.
 *
 * Every unknown value is omitted, never printed as a zero or a dash soup: the
 * line exists so the reader can see that 3 stable bad blocks or 67 C are known
 * and deliberately not an alert.
 */
function bk_digest_router_facts_lines(array $facts): array {
    $lines = [];
    $gen_label = [4 => 'Wi-Fi 4', 5 => 'Wi-Fi 5', 6 => 'Wi-Fi 6', 7 => 'Wi-Fi 7'];
    foreach ($facts['radios'] ?? [] as $radio) {
        $parts = [];
        $parts[] = bk_rec_band_label($radio['band'] ?? null);
        if (isset($gen_label[(int)($radio['generation'] ?? 0)])) {
            $parts[] = $gen_label[(int)$radio['generation']];
        }
        if (!empty($radio['width_mhz'])) {
            $parts[] = bk_rec_num($radio['width_mhz']) . ' MHz';
        }
        if (($radio['channel'] ?? null) !== null) {
            $parts[] = sprintf(t('rr_channel'), bk_rec_num($radio['channel']));
        }
        if (is_string($radio['encryption'] ?? null) && $radio['encryption'] !== '') {
            $parts[] = strtoupper(str_replace('_', '/', $radio['encryption']));
        }
        if (($radio['clients'] ?? null) !== null) {
            $clients = (int)$radio['clients'];
            $key = $clients === 1 ? 'digest_fact_clients_1' : ($clients < 5 ? 'digest_fact_clients_few' : 'digest_fact_clients_many');
            $gens = [];
            foreach (['wifi7' => 7, 'wifi6' => 6, 'wifi5' => 5, 'wifi4' => 4] as $gk => $gn) {
                $n = $radio['clients_gen'][$gk] ?? null;
                if (is_numeric($n) && (int)$n > 0) {
                    $gens[] = $gen_label[$gn] . ': ' . bk_rec_num((int)$n);
                }
            }
            $parts[] = sprintf(t($key), bk_rec_num($clients)) . ($gens ? ' (' . implode(', ', $gens) . ')' : '');
        }
        if (($radio['noise_week'] ?? null) !== null) {
            $parts[] = sprintf(t('digest_fact_noise'), bk_rec_num($radio['noise_week']));
        }
        if (($radio['busy_week'] ?? null) !== null) {
            $parts[] = sprintf(t('digest_fact_busy'), bk_rec_num($radio['busy_week'], 1));
        }
        $lines[] = implode(' · ', $parts);
    }
    foreach ($facts['disks'] ?? [] as $disk) {
        $parts = [];
        if (is_string($disk['name'] ?? null) && $disk['name'] !== '') {
            $parts[] = $disk['name'];
        }
        if (is_string($disk['model'] ?? null) && $disk['model'] !== '') {
            $parts[] = $disk['model'];
        }
        $kind = [];
        if (is_string($disk['transport'] ?? null) && $disk['transport'] !== '') {
            $kind[] = strtoupper($disk['transport']);
        }
        if (($disk['rotational'] ?? null) === false) {
            $kind[] = 'SSD';
        } elseif (($disk['rotational'] ?? null) === true) {
            $kind[] = 'HDD';
        }
        if (($disk['size_bytes'] ?? null) !== null) {
            $kind[] = bk_format_bytes_cz((float)$disk['size_bytes']);
        }
        if ($kind) {
            $parts[] = implode(' ', $kind);
        }
        if (($disk['smart_state'] ?? null) === 'ok' && ($disk['smart_passed'] ?? null) !== false) {
            $parts[] = t('digest_fact_smart_ok');
        } elseif (($disk['smart_passed'] ?? null) === false || ($disk['smart_state'] ?? null) === 'failing') {
            $parts[] = t('digest_fact_smart_failing');
        }
        if (($disk['temperature_c'] ?? null) !== null) {
            $parts[] = bk_rec_num($disk['temperature_c']) . ' °C';
        }
        if (($disk['wear_pct'] ?? null) !== null) {
            $parts[] = sprintf(t('digest_fact_wear'), bk_rec_num($disk['wear_pct']));
        }
        if (($disk['written_bytes'] ?? null) !== null) {
            $parts[] = sprintf(t('digest_fact_written'), bk_format_bytes_cz((float)$disk['written_bytes']));
        }
        if (!empty($disk['runtime_bad_blocks'])) {
            $parts[] = sprintf(t('digest_fact_bad_blocks'), bk_rec_num($disk['runtime_bad_blocks']));
        }
        if (!empty($disk['reallocated_sectors'])) {
            $parts[] = sprintf(t('digest_fact_realloc'), bk_rec_num($disk['reallocated_sectors']));
        }
        $lines[] = implode(' · ', $parts);
    }
    return array_values(array_filter($lines, fn (string $l): bool => $l !== ''));
}

/**
 * Renders the complete infrastructure report (weekly and monthly) into an HTML
 * e-mail. The structure matches 4 blocks: Executive Summary / Operational Overview /
 * Technical Insights / Recommendations.
 */
function render_digest_html($data) {
    $is_monthly = $data['period'] === 'monthly';
    $period_label = $is_monthly ? t('digest_title_monthly') : t('digest_title_weekly');
    // A null score (nothing measured, W1-B3) is grey and prints no number.
    $score_color = $data['score'] === null ? '#888896' : ($data['score'] >= 90 ? '#1ec773' : ($data['score'] >= 70 ? '#f39c12' : '#ef233c'));
    $accent_color = $data['score'] !== null && $data['score'] >= 70 ? '#1ec773' : '#c1121f';

    $body = '';

    // --- Hero: Infrastructure Score ---
    $score_delta_html = '';
    if ($data['score_prev'] !== null && $data['score'] !== null) {
        $delta = $data['score'] - $data['score_prev'];
        $delta_color = $delta > 0 ? '#1ec773' : ($delta < 0 ? '#ef233c' : '#888896');
        $delta_sign = $delta > 0 ? '+' : '';
        $score_delta_html = '<div style="margin-top:6px; font-size:13px; color:' . $delta_color . ';">' . bk_trend_glyph($data['trend_score']) . ' ' . $delta_sign . $delta . ' ' . htmlspecialchars(t('digest_vs_previous_period')) . '</div>';
    }
    $body .= '<div style="text-align:center; margin-bottom:28px;">
        <div style="font-size:11px; color:#888896; text-transform:uppercase; letter-spacing:0.05em;">' . htmlspecialchars(t('digest_hero_score_label')) . '</div>
        <div style="font-size:48px; font-weight:bold; color:' . $score_color . '; line-height:1.3;">' . ($data['score'] !== null
            ? $data['score'] . '<span style="font-size:20px; color:#888896;">/100</span>'
            : '&mdash;<div style="font-size:14px; font-weight:normal;">' . htmlspecialchars(t('digest_not_enough_data')) . '</div>') . '</div>'
        . $score_delta_html .
    '</div>';

    // --- Executive Summary ---
    $exec_html = '';
    foreach ($data['executive_summary'] as $line) {
        // One line can be a neutral router item instead of a string: its
        // sentence is built here, in the recipient's language (CORE 3.8).
        if (is_array($line)) {
            $rec = is_array($line['rec'] ?? null) ? $line['rec'] : [];
            $line = sprintf(t((string)$line['key']),
                (string)($rec['name'] ?? '') . ': ' . bk_router_rec_render($rec)['title']);
        }
        $exec_html .= '<p style="margin:5px 0; font-size:14px;">' . htmlspecialchars($line) . '</p>';
    }
    $body .= '<div style="background-color:#12121a; border-radius:6px; padding:16px 18px; margin-bottom:26px;">' . $exec_html . '</div>';

    // --- Operational Overview: the KPI grid ---
    $na = t('digest_na');
    $stat_html = bk_email_stat_box(($data['availability'] !== null ? number_format($data['availability'], 3, ',', ' ') . '%' : $na), t('digest_stat_availability'))
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

    // --- Best / worst monitors ---
    if (!empty($data['best_monitors']) || !empty($data['worst_monitors'])) {
        $bw_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; font-size:13px; margin-top:4px;"><thead><tr>'
            . '<th style="text-align:left; padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;">' . htmlspecialchars(t('digest_col_monitor')) . '</th>'
            . '<th style="text-align:right; padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;">' . htmlspecialchars(t('digest_col_availability')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($data['best_monitors'] as $m) {
            $bw_html .= '<tr><td style="padding:7px 10px; border-top:1px solid #22222f; color:#e1e1e6;">' . htmlspecialchars($m['name']) . '</td><td style="padding:7px 10px; border-top:1px solid #22222f; text-align:right; color:#1ec773;">' . ($m['uptime_pct'] !== null ? $m['uptime_pct'] . '%' : htmlspecialchars(t('digest_na'))) . '</td></tr>';
        }
        foreach ($data['worst_monitors'] as $m) {
            $u = $m['uptime_pct'] !== null ? $m['uptime_pct'] . '%' : htmlspecialchars(t('digest_na'));
            $bw_html .= '<tr><td style="padding:7px 10px; border-top:1px solid #22222f; color:#e1e1e6;">' . htmlspecialchars($m['name']) . '</td><td style="padding:7px 10px; border-top:1px solid #22222f; text-align:right; color:#ef233c;">' . $u . '</td></tr>';
        }
        if (empty($data['worst_monitors'])) {
            $bw_html .= '<tr><td colspan="2" style="padding:7px 10px; border-top:1px solid #22222f; color:#888896;">' . htmlspecialchars(t('digest_summary_no_outages')) . '</td></tr>';
        }
        $bw_html .= '</tbody></table>';
        $body .= bk_email_section(t('digest_section_best_worst_monitors'), $bw_html);
    }

    // --- Biggest changes ---
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

    // --- New / removed servers ---
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
            // A removed monitor no longer exists - the link deliberately does not work (see build_digest_data()).
            $ns_html .= '<div style="color:#ef233c; font-size:13px; padding:3px 0;">- ' . htmlspecialchars($s['name']) . ' <span style="color:#888896;">(' . htmlspecialchars($s['type']) . ')</span></div>';
        }
        $body .= bk_email_section(t('digest_section_new_removed_servers'), $ns_html);
    }

    // --- Configuration changes ---
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

        // Nothing measured -> neither met nor missed (W1-B3).
        $sla_reached = $data['availability'] !== null ? $data['availability'] >= $mo['sla_goal'] : null;
        $sla_html = bk_email_kv(t('digest_sla_current'), $data['availability'] !== null ? number_format($data['availability'], 3, ',', ' ') . '%' : t('digest_na'))
            . bk_email_kv(t('digest_sla_goal'), $mo['sla_goal'] . '%')
            . bk_email_kv(t('digest_sla_status'), $sla_reached === null
                ? '<span style="color:#888896;">' . htmlspecialchars(t('digest_not_enough_data')) . '</span>'
                : '<span style="color:' . ($sla_reached ? '#1ec773' : '#ef233c') . ';">' . htmlspecialchars($sla_reached ? t('digest_sla_met') : t('digest_sla_not_met')) . '</span>');
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

        // Incident heatmap - coloured table cells (e-mail clients cannot do CSS grid).
        // $day is the neutral key (mon/tue/...) from build_monthly_digest_extras() -
        // the displayed label is translated only here via digest_day_*.
        $hm_html = '<table style="width:100%; border-collapse:collapse;"><tr>';
        foreach ($mo['incident_heatmap'] as $day => $cnt) {
            $bgcolor = $cnt === 0 ? '#1ec773' : ($cnt <= 2 ? '#f39c12' : '#ef233c');
            $hm_html .= '<td bgcolor="' . $bgcolor . '" style="background-color:' . $bgcolor . '; text-align:center; font-size:11px; color:#0f0f13; font-weight:bold; padding:8px 0;">' . htmlspecialchars(t('digest_day_' . $day)) . '<br>' . $cnt . '</td>';
        }
        $hm_html .= '</tr></table>';
        $body .= bk_email_section(t('digest_section_incident_heatmap'), $hm_html);

        // Latency heatmap - one row per region
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
            $score_cmp_html = bk_email_kv(t('digest_score_last_month'), $mo['score_last_month']) . bk_email_kv(t('digest_score_this_month'), $data['score'] ?? t('digest_not_enough_data'));
            $body .= bk_email_section(t('digest_section_health_score_compare'), $score_cmp_html);
        }
    }

    // --- Routers (CORE 3.8) ---
    // Weekly only, and only when there is an openwrt monitor at all: an empty
    // section would suggest the router data is missing rather than absent.
    if (!empty($data['routers'])) {
        $rt_color = ['critical' => '#ef233c', 'warning' => '#f39c12', 'info' => '#888896'];
        // The new/removed section above defines $site_url only when it renders.
        $rt_base = rtrim((string)get_setting('site_url', ''), '/');
        $rt_html = '';
        foreach ($data['routers'] as $router) {
            $link = $rt_base . '/index.php?expand=' . (int)$router['id'];
            $rt_html .= '<div style="padding:10px 0; border-bottom:1px solid #22222c;">'
                . '<div style="font-size:14px; font-weight:bold;"><a href="' . htmlspecialchars($link)
                . '" style="color:#e1e1e6; text-decoration:none;">' . htmlspecialchars((string)$router['name']) . '</a></div>';
            if (empty($router['applicable'])) {
                $params = is_array($router['reason_params'] ?? null) ? $router['reason_params'] : [];
                $why = '';
                if ($router['reason'] === 'agent_old') {
                    $why = sprintf(t('digest_router_agent_old'), (string)($params['version'] ?? '?'));
                } elseif ($router['reason'] === 'silent' && isset($params['days'])) {
                    $why = sprintf(t('digest_router_silent'), (int)$params['days']);
                }
                if ($why !== '') {
                    $rt_html .= '<div style="font-size:12px; color:#888896; padding-top:3px;">' . htmlspecialchars($why) . '</div>';
                }
                $rt_html .= '</div>';
                continue;
            }
            foreach (bk_digest_router_facts_lines(is_array($router['facts'] ?? null) ? $router['facts'] : []) as $fact) {
                $rt_html .= '<div style="font-size:12px; color:#888896; padding-top:3px;">' . htmlspecialchars($fact) . '</div>';
            }
            if ((int)$router['days_with_data'] < 4) {
                $rt_html .= '<div style="font-size:12px; color:#888896; padding-top:3px;">'
                    . htmlspecialchars(sprintf(t('digest_router_few_days'), (int)$router['days_with_data'])) . '</div>';
            }
            foreach ($router['items_full'] as $item) {
                $text = bk_router_rec_render($item);
                $color = $rt_color[(string)$item['severity']] ?? '#888896';
                $rt_html .= '<div style="padding:5px 0 5px 10px; border-left:3px solid ' . $color . '; margin-top:6px;">'
                    . '<div style="font-size:13px; color:#e1e1e6; font-weight:bold;">' . htmlspecialchars($text['title']) . '</div>'
                    . '<div style="font-size:13px; color:#e1e1e6;">' . htmlspecialchars($text['measured']) . '</div>'
                    . '<div style="font-size:12px; color:#a0a0ab;">&rarr; ' . htmlspecialchars($text['action']) . '</div>'
                    . '</div>';
            }
            if (!empty($router['more'])) {
                $rt_html .= '<div style="font-size:12px; color:#888896; padding-top:4px;">'
                    . htmlspecialchars(sprintf(t('digest_router_more_items'), (int)$router['more'])) . '</div>';
            }
            // An item that has been open since an earlier week is a title only:
            // the e-mail says what is new, the app says everything.
            if (!empty($router['items_open'])) {
                $titles = [];
                foreach ($router['items_open'] as $item) {
                    $titles[] = bk_router_rec_render($item)['title'];
                }
                $rt_html .= '<div style="font-size:12px; color:#888896; padding-top:4px;">'
                    . htmlspecialchars(sprintf(t('digest_router_unchanged'), count($titles), implode(', ', $titles))) . '</div>';
            }
            if (empty($router['items_full']) && empty($router['items_open']) && (int)$router['days_with_data'] >= 4) {
                $rt_html .= '<div style="font-size:12px; color:#1ec773; padding-top:3px;">'
                    . htmlspecialchars(t('digest_router_ok')) . '</div>';
            }
            $rt_html .= '</div>';
        }
        if (!empty($data['routers_more'])) {
            $rt_html .= '<div style="font-size:12px; color:#888896; padding-top:6px;">'
                . htmlspecialchars(sprintf(t('digest_routers_more'), (int)$data['routers_more'])) . '</div>';
        }
        $body .= bk_email_section(t('digest_section_routers'), $rt_html);
    }

    // --- Recommendations ---
    $rec_html = '';
    // A critical router item is the most urgent thing in the whole e-mail, so
    // it goes above the existing sentences - as a bullet like the rest, never
    // pushed into `$data['recommendations']`, which is a list of strings.
    foreach ($data['router_critical'] ?? [] as $rec) {
        $rec_html .= '<div style="font-size:13px; padding:4px 0; color:#e1e1e6;">&bull; '
            . htmlspecialchars((string)($rec['name'] ?? '') . ': ' . bk_router_rec_render($rec)['title']) . '</div>';
    }
    if (empty($data['recommendations'])) {
        if ($rec_html === '') {
            $rec_html = '<div style="font-size:13px; color:#1ec773;">' . htmlspecialchars(t('digest_no_recommendations')) . '</div>';
        }
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
    // The data is language-neutral and built once; the language applies only at
    // HTML render time - once per language among the recipients.
    $data = build_digest_data($pdo, $period);

    // Recipients - all administrators with an e-mail set, including their language
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
        if (send_email($adm['email'], $subject, $html_body, [], ['kind' => 'digest'])) {
            $any_success = true;
        }
    }

    return $any_success;
}

/**
 * Converts a two-letter country code (e.g. CZ, DE) to an emoji flag
 */
function get_country_emoji($country_code) {
    $code = strtoupper($country_code);
    if (strlen($code) !== 2) return '🌐';
    $first = ord($code[0]) - 65 + 127462;
    $second = ord($code[1]) - 65 + 127462;
    return mb_convert_encoding('&#' . $first . ';&#' . $second . ';', 'UTF-8', 'HTML-ENTITIES');
}

/**
 * Automatic detection of the server's geographic location and ASN via a public API
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
 * Checks the cPanel status endpoint and loads the statistics
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
                    // without this passthrough cron always read $cp_res['cpu'] as null
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
 * Generates one-time 2FA recovery codes and stores their sha256 hashes,
 * replacing any previous set. Returns the PLAINTEXT codes - they are shown
 * exactly once; only hashes survive, so a DB dump cannot be used to sign in.
 * The alphabet omits 0/O, 1/l/I - the codes are meant to be read off paper.
 */
function bk_totp_generate_recovery_codes(PDO $pdo, int $user_id, int $count = 10): array {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $raw = '';
        for ($j = 0; $j < 10; $j++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
    }
    $pdo->prepare("DELETE FROM totp_recovery_codes WHERE user_id = ?")->execute([$user_id]);
    $ins = $pdo->prepare("INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)");
    foreach ($codes as $c) {
        $ins->execute([$user_id, hash('sha256', str_replace('-', '', $c))]);
    }
    return $codes;
}

/**
 * Tries a recovery code in place of a TOTP code. On match the code is
 * consumed (strictly single use) and the number of remaining codes is
 * returned; null means no match. Input is normalised (case, dashes), so the
 * code works however the user re-types it from paper.
 */
function bk_totp_try_recovery_code(PDO $pdo, int $user_id, string $code): ?int {
    $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $code));
    if (strlen($norm) < 8) {
        // TOTP codes are 6 digits - do not even look those up here.
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT id FROM totp_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL LIMIT 1");
        $stmt->execute([$user_id, hash('sha256', $norm)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $pdo->prepare("UPDATE totp_recovery_codes SET used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
        return bk_totp_recovery_remaining($pdo, $user_id);
    } catch (Throwable $e) {
        // Without the table (old DB before migration) recovery simply does not exist.
        return null;
    }
}

/** Number of unused recovery codes; 0 also when the table does not exist yet. */
function bk_totp_recovery_remaining(PDO $pdo, int $user_id): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ? AND used_at IS NULL");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Public e-mail subscriptions - visitors without accounts.
 *
 * Double opt-in: anyone can type any address into a public form, so nothing
 * is ever sent to an address whose owner did not click the confirmation
 * link. Tokens are stored as sha256 hashes only; the raw token exists just
 * in the e-mail. The confirmation/unsubscribe links lead to React pages
 * with an explicit button - mail scanners follow bare GET links and would
 * otherwise confirm (or cancel) subscriptions nobody asked for.
 */

/** Issues (or refreshes) a subscription and returns [raw confirm token, lang] or null on a recent resend. */
function bk_public_sub_issue(PDO $pdo, string $email, string $lang, ?string $ip): ?string {
    $confirm_raw = bin2hex(random_bytes(24));
    $unsub_raw = bin2hex(random_bytes(24));
    $stmt = $pdo->prepare("SELECT id, confirmed_at, confirm_sent_at FROM public_subscribers WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row) {
        if (!empty($row['confirmed_at'])) {
            // Already confirmed - nothing to send, and the caller must not
            // reveal that the address is subscribed (no enumeration).
            return null;
        }
        // Resend cooldown: an unconfirmed address gets a fresh mail at most
        // once per 10 minutes, so the form cannot be used to bombard someone.
        if (!empty($row['confirm_sent_at']) && strtotime($row['confirm_sent_at']) > time() - 600) {
            return null;
        }
        $pdo->prepare("UPDATE public_subscribers SET confirm_token_hash = ?, confirm_sent_at = NOW() WHERE id = ?")
            ->execute([hash('sha256', $confirm_raw), (int)$row['id']]);
        return $confirm_raw;
    }
    // The unsubscribe token is stored RAW on purpose: its only power is
    // cancelling a subscription, and hashing it would invalidate the links
    // in every previously sent mail (the raw value cannot be re-derived).
    $pdo->prepare("INSERT INTO public_subscribers (email, lang, confirm_token_hash, unsubscribe_token, created_ip, confirm_sent_at) VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$email, in_array($lang, ['cs', 'en'], true) ? $lang : 'cs', hash('sha256', $confirm_raw), $unsub_raw, $ip]);
    return $confirm_raw;
}

/** Renders + sends the confirmation e-mail. Returns whether sending succeeded. */
function bk_public_sub_send_confirm(string $email, string $lang, string $confirm_raw, string $base_origin): bool {
    return (bool)bk_with_email_lang($lang, function () use ($email, $confirm_raw, $base_origin) {
        $site = get_setting('site_title', 'Blood Kings Monitoring');
        $link = $base_origin . '/app/subscribe-confirm?token=' . $confirm_raw;
        $subject = sprintf(t('pubsub_confirm_subject'), $site);
        $body = '<p>' . htmlspecialchars(sprintf(t('pubsub_confirm_intro'), $site)) . '</p>'
            . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars(t('pubsub_confirm_button')) . '</a></p>'
            . '<p style="color:#888;font-size:12px">' . htmlspecialchars(t('pubsub_confirm_ignore')) . '</p>';
        return send_email($email, $subject, $body, [], ['kind' => 'subscriber_confirm']);
    });
}

/**
 * Sends outage/recovery mails to confirmed public subscribers.
 *
 * Deliberately carries no error details - the mail says WHICH service and
 * WHAT happened, the status page says the rest. Rendered once per language,
 * sent per subscriber because every mail carries a personal unsubscribe link.
 */
function bk_public_sub_notify(PDO $pdo, array $monitor, string $new_status): void {
    if (!in_array($new_status, ['down', 'up'], true)) {
        return;
    }
    try {
        $subs = $pdo->query("SELECT id, email, lang, unsubscribe_token FROM public_subscribers WHERE confirmed_at IS NOT NULL")->fetchAll();
    } catch (Throwable $e) {
        return; // Table missing on an old DB - the feature simply is not there yet.
    }
    if (!$subs) {
        return;
    }
    $base_origin = bk_public_base_origin();
    $rendered = [];
    foreach ($subs as $sub) {
        $lang = in_array($sub['lang'], ['cs', 'en'], true) ? $sub['lang'] : 'cs';
        if (!isset($rendered[$lang])) {
            $rendered[$lang] = bk_with_email_lang($lang, function () use ($monitor, $new_status, $base_origin) {
                $site = get_setting('site_title', 'Blood Kings Monitoring');
                $subject = sprintf(t($new_status === 'down' ? 'pubsub_down_subject' : 'pubsub_up_subject'), $monitor['name']);
                $body = '<p>' . htmlspecialchars(sprintf(t($new_status === 'down' ? 'pubsub_down_body' : 'pubsub_up_body'), $monitor['name'])) . '</p>'
                    . '<p><a href="' . htmlspecialchars($base_origin . '/app/public') . '">' . htmlspecialchars(sprintf(t('pubsub_status_link'), $site)) . '</a></p>';
                return [$subject, $body, t('pubsub_unsub_line')];
            });
        }
        [$subject, $body, $unsub_label] = $rendered[$lang];
        $unsub_link = $base_origin . '/app/unsubscribe?token=' . $sub['unsubscribe_token'];
        $full_body = $body . '<p style="color:#888;font-size:12px"><a href="' . htmlspecialchars($unsub_link) . '">' . htmlspecialchars($unsub_label) . '</a></p>';
        send_email($sub['email'], $subject, $full_body, ['List-Unsubscribe' => '<' . $unsub_link . '>'], [
            'kind' => 'subscriber_broadcast',
            'monitor_id' => (int)($monitor['id'] ?? 0),
            'status' => $new_status,
        ]);
    }
}

/**
 * Absolute origin for links in public mails - the configured site_url first.
 *
 * The fallback must NOT trust the client-controlled Host header. A confirmation
 * mail goes to an address the requester names, so `Host: evil.example` on an
 * anonymous public_subscribe would produce a legitimately-signed mail whose
 * "Confirm" button points at the attacker's site (phishing + token capture).
 * With no site_url configured we accept Host only when it is on an allowlist
 * (dev hosts by default, extendable via the BK_PUBLIC_LINK_HOSTS constant) or
 * matches the web server's own SERVER_NAME; anything else falls back to
 * SERVER_NAME, which the server config sets, not the request.
 */
function bk_public_base_origin(): string {
    $configured = trim((string)get_setting('site_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $allow = array_filter(array_map(
        'trim',
        explode(',', defined('BK_PUBLIC_LINK_HOSTS') ? (string)BK_PUBLIC_LINK_HOSTS : 'localhost,127.0.0.1')
    ));
    $trusted = bk_trusted_link_host(
        (string)($_SERVER['HTTP_HOST'] ?? ''),
        (string)($_SERVER['SERVER_NAME'] ?? 'localhost'),
        $allow
    );
    return $scheme . '://' . $trusted;
}

/**
 * Decides which host may appear in a link inside mail to a third party.
 *
 * Pure and side-effect free so the trust rule can be tested directly. The
 * client-supplied Host is honoured only when it matches the server's own
 * SERVER_NAME or an explicit allowlist; otherwise SERVER_NAME wins. This is
 * what stops `Host: evil.example` from poisoning a confirmation link.
 *
 * @param string[] $allow lowercased hostnames (no port) that may be trusted
 */
function bk_trusted_link_host(string $http_host, string $server_name, array $allow): string {
    $host = strtolower(trim($http_host));
    $server_name = strtolower(trim($server_name)) ?: 'localhost';
    $host_no_port = explode(':', $host)[0];
    if ($host !== '' && ($host_no_port === $server_name || in_array($host_no_port, $allow, true))) {
        return $host;
    }
    return $server_name;
}

/**
 * Renders the card for enabling/disabling 2FA on one's own account (the admin
 * and regular-user Profile share this one implementation). The QR code is
 * generated purely client-side (the qrcode CDN library) - the secret is thus never
 * sent to any third party like with public QR-generator APIs, it only stays in the
 * response of one's own authenticated page.
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

        // The BK_CDN_QRCODE version (see the constants at the top of the file).
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
 * Renders the linked OAuth accounts card (Profile) - only one provider at a
 * time (the schema has a single oauth_provider/oauth_id column pair per user).
 * Linking goes only through link_oauth (see admin.php), never by e-mail.
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
 * CSRF protection (synchronizer token pattern) - one token per session, lazily
 * generated on first need. bk_csrf_field() goes into every <form>,
 * bk_csrf_check() is called at the start of every state-changing handler
 * (deleting, toggling, sending e-mails...). Read-only actions (the login form
 * itself, logout, previews, edit prefills) need no token.
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
 * Audit log write - who/when/what. $actor_user_id/$actor_username come from
 * the session unless passed explicitly (needed only for a failed login,
 * where no logged-in user exists yet). Never lets an exception escape -
 * the audit log must not bring down the very action it records.
 */
function bk_audit_log($pdo, $action, $description = '', $target_type = null, $target_id = null, $actor_user_id = null, $actor_username = null) {
    if ($actor_user_id === null && isset($_SESSION['admin_id'])) {
        $actor_user_id = $_SESSION['admin_id'];
    }
    if ($actor_username === null && isset($_SESSION['admin_username'])) {
        $actor_username = $_SESSION['admin_username'];
    }
    // The visitor's real IP, not Cloudflare's address - see bk_client_ip().
    $ip = bk_client_ip();
    $ua = bk_client_user_agent();
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_log (actor_user_id, actor_username, action, target_type, target_id, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$actor_user_id, $actor_username, $action, $target_type, $target_id, $description, $ip, $ua]);
    } catch (PDOException $e) {
        // Table missing yet or DB error - the audit log is best-effort and must not kill the main action
    }
}

/**
 * Rate limiting / lockout pro login.
 * Returns the remaining lockout seconds (0 = not locked).
 * Limit: 5 failed attempts in 15 minutes -> a 15-minute lockout.
 */
function bk_login_lockout_seconds($pdo, $username) {
    $max_attempts = 5;
    $window_min = 15;
    $lockout_min = 15;
    // Without the real IP the lockout would key on Cloudflare's address, i.e.
    // a value shared by every visitor from the same edge node.
    $ip = bk_client_ip() ?? '';
    try {
        // Failed attempts for this name OR this IP within the window
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM audit_log
            WHERE action = 'login_failed'
              AND (actor_username = ? OR ip_address = ?)
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$username, $ip, $window_min]);
        $attempts = (int)$stmt->fetchColumn();
        if ($attempts < $max_attempts) return 0;

        // Find the last failed attempt's time -> the lockout runs from it
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
 * A standalone page with the latest audit log records (who/when/what) - admin
 * only, read-only (no CSRF token needed). Its own shell instead of hooking into
 * the main admin.php template, so it does not depend on the main request's
 * variables (same approach as bk_render_setup_wizard()).
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
 * Generates a password-setup token (new-user invitations and forgotten
 * passwords share this mechanism) - only the token's sha256 hash is stored in
 * the DB, not the token itself, so a leaked DB dump cannot be used directly.
 * Returns the RAW token for building the e-mail link.
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
 * Absolute URL of the current admin.php derived from the request - same
 * approach as the OAuth redirect_uri. Unlike digest e-mails (cron, no request)
 * this always runs inside an HTTP request, so the site_url setting is not needed.
 */
function bk_current_admin_url() {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
}

/**
 * Issues a password reset token and e-mails the link.
 *
 * Extracted from bk_render_forgot_password_page() so the React API path could
 * do the same - it used to call a `forgot_password` action that did not exist
 * in api.php at all. The user got a 200, the app said "instructions sent",
 * and no e-mail ever arrived.
 *
 * The link always points to admin.php even when the request runs through
 * api.php: the password-setup page is served by the admin.
 *
 * @return bool Whether the e-mail matched an existing account. The caller MUST
 *              NOT reveal it - otherwise the form can probe who is registered.
 *
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

    // The link points into the React app. The old admin.php?action=set_password
    // address keeps working for e-mails already sent.
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $set_link = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/app/set-password?token=' . $raw_token;

    $subject = 'Obnovení hesla - ' . $site_title;
    $body = '<h1>Obnovení hesla</h1>'
        . '<p>Někdo (doufejme vy) požádal o obnovení hesla k účtu <strong>' . htmlspecialchars($user['username']) . '</strong>. '
        . 'Klikněte na odkaz níže pro nastavení nového hesla (platnost 2 hodiny):</p>'
        . '<p><a href="' . htmlspecialchars($set_link) . '">' . htmlspecialchars($set_link) . '</a></p>'
        . '<p>Pokud jste o obnovení hesla nežádali, tento e-mail můžete ignorovat.</p>';

    send_email($email, $subject, $body, [], ['kind' => 'password_reset']);
    bk_audit_log($pdo, 'password_reset_requested', $email, 'user', $user['id'], $user['id'], $user['username']);
    return true;
}

/**
 * "Forgotten password" - the e-mail form + sending the link. The response is
 * always the same whether the e-mail exists or not, otherwise the form could
 * be abused to verify which e-mails are registered.
 */
function bk_render_forgot_password_page($pdo, $site_title) {
    $sent = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password_request'])) {
        bk_csrf_check();
        // The actual sending lives in bk_password_reset_request() so the React
        // API path can do the same and the e-mail text exists in one place only.
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
 * Password setup via an e-mail link - serves both the new-user invitation
 * (see save_user in admin.php) and the forgotten password (see above). The
 * token proves e-mail ownership, nothing else about identity.
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
 * The forced setup wizard after a fresh install - 3 steps (account, cron_key,
 * site basics); on completion it sets the single source of truth
 * setup_completed in settings. admin.php calls this function and ends the
 * request outright until the flag is '1' - no other admin action runs first
 * (see the call below). Replaces the former hardcoded password-hash comparison in the security banner.
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
 * Sends a push notification via the Pushover API
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
 * Sends an event via the PagerDuty Events v2 API
 */
/**
 * @param string|null $dedup_key Identifies the alert across events. Without it
 *   PagerDuty cannot pair a resolve with its trigger (the resolve was silently
 *   rejected) and two triggers for one outage - agent silence plus the outage
 *   it causes - open two incidents.
 */
function send_pagerduty_event($routing_key, $event_type, $summary, $source = 'Blood Kings Monitoring', ?string $dedup_key = null) {
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
    if ($dedup_key !== null && $dedup_key !== '') {
        $payload['dedup_key'] = $dedup_key;
    }
    return send_webhook_post($url, json_encode($payload));
}

/**
 * Converts a byte count to a human-readable format (e.g. 188.22 GB, 3.62 TB).
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
 * Converts a packet count to a human-readable format (e.g. 707M Pkts, 2.57B Pkts).
 */
function bk_format_packets_cz($cnt) {
    $cnt = (float)$cnt;
    if ($cnt >= 1000000000) return round($cnt / 1000000000, 2) . ' B Pkts.';
    if ($cnt >= 1000000) return round($cnt / 1000000, 2) . ' M Pkts.';
    if ($cnt >= 1000) return round($cnt / 1000, 1) . ' k Pkts.';
    return number_format($cnt, 0, ',', ' ') . ' Pkts.';
}

/**
 * Pairs wan_lost / wan_restored events into "on the backup link" periods.
 *
 * Pure. Events arrive in ascending time order as [type, unix ts]. A restore
 * with no loss before it means the line was down since before the window
 * (from = null, counted from the window start); a loss with no restore is
 * still open and runs until $now. Seconds are clamped to the window so a
 * month-old outage cannot inflate the total.
 *
 * $open_since carries an outage that started BEFORE the window and never
 * ended: its events are outside the query, so without it a router that has
 * been on the backup for weeks reported "never on the backup" - a confident
 * no for the exact situation this feature exists to show.
 *
 * @param array<int, array{0: string, 1: int}> $events
 * @return array{periods: array<int, array{from: int|null, to: int|null, seconds: int}>, seconds: int, open: bool}
 */
function bk_pair_link_periods(array $events, int $window_start, int $now, ?int $open_since = null): array {
    $periods = [];
    $open_from = $open_since;
    foreach ($events as $ev) {
        $type = (string)($ev[0] ?? '');
        $ts = (int)($ev[1] ?? 0);
        if ($type === 'wan_lost') {
            if ($open_from === null) {
                $open_from = $ts;
            }
        } elseif ($type === 'wan_restored') {
            $from = $open_from ?? $window_start;
            $periods[] = ['from' => $open_from, 'to' => $ts, 'seconds' => max(0, $ts - max($from, $window_start))];
            $open_from = null;
        }
    }
    $open = $open_from !== null;
    if ($open) {
        $periods[] = ['from' => $open_from, 'to' => null, 'seconds' => max(0, $now - max($open_from, $window_start))];
    }
    $total = 0;
    foreach ($periods as $p) {
        $total += $p['seconds'];
    }
    return ['periods' => $periods, 'seconds' => $total, 'open' => $open];
}

/**
 * Traffic split by link role for a router: the bytes that went over the
 * primary line (wan_l3_device) and over the LTE backup (lte_device), plus
 * the periods spent on the backup (wan_lost / wan_restored events).
 *
 * The daily per-interface totals existed for years; only the legacy page
 * read them, under raw interface names, so "how much went over LTE" had no
 * answer on the web. Roles come from what the agent reports, never guessed
 * from names: an agent that does not send wan_l3_device (before 0.1.3) gets
 * null for the primary side.
 */
function bk_get_link_traffic($pdo, int $monitor_id, array $details, int $days = 30): array {
    $stats = bk_get_interface_traffic_stats($pdo, $monitor_id);
    $primary_dev = (isset($details['wan_l3_device']) && is_string($details['wan_l3_device']) && $details['wan_l3_device'] !== '')
        ? $details['wan_l3_device'] : null;
    $backup_dev = (isset($details['lte_device']) && is_string($details['lte_device']) && $details['lte_device'] !== '' && $details['lte_device'] !== 'null')
        ? $details['lte_device'] : null;
    $pick = function (?string $dev) use ($stats): ?array {
        if ($dev === null) {
            return null;
        }
        $row = $stats[$dev] ?? null;
        $out = ['iface' => $dev, 'today' => null, '7d' => null, '30d' => null, 'total' => null];
        if ($row) {
            foreach (['today' => 'today', '7d' => '7d', '30d' => '30d', 'total' => 'all'] as $w => $src) {
                // A window the router never reported into stays null. Zero
                // bytes would claim the link was idle, which is a different
                // statement from "the agent was not reporting".
                if (isset($row[$src]) && (int)($row['days'][$src] ?? 0) > 0) {
                    $out[$w] = ['rx_bytes' => (float)$row[$src]['rx_bytes'], 'tx_bytes' => (float)$row[$src]['tx_bytes']];
                }
            }
        }
        return $out;
    };

    $now = time();
    $window_start = $now - $days * 86400;
    $events = [];
    // An outage still running from before the window: the newest event older
    // than the window decides. Without it the whole period is invisible and
    // the answer becomes "never on the backup" for a router that is on the
    // backup right now.
    $open_since = null;
    try {
        $stmt_prev = $pdo->prepare("SELECT event_type, UNIX_TIMESTAMP(occurred_at) AS ts FROM monitor_events WHERE monitor_id = ? AND event_type IN ('wan_lost', 'wan_restored') AND occurred_at < DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY occurred_at DESC, id DESC LIMIT 1");
        $stmt_prev->execute([$monitor_id, $days]);
        $prev = $stmt_prev->fetch();
        if ($prev && (string)$prev['event_type'] === 'wan_lost') {
            $open_since = (int)$prev['ts'];
        }
    } catch (PDOException $e) {
        // Swallowing this answered a measured "no outages" for a query that
        // never ran. The caller (api.php) logs it and answers 500.
        throw $e;
    }
    try {
        $stmt = $pdo->prepare("SELECT event_type, UNIX_TIMESTAMP(occurred_at) AS ts FROM monitor_events WHERE monitor_id = ? AND event_type IN ('wan_lost', 'wan_restored') AND occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ORDER BY occurred_at ASC, id ASC");
        $stmt->execute([$monitor_id, $days]);
        foreach ($stmt->fetchAll() as $r) {
            $events[] = [(string)$r['event_type'], (int)$r['ts']];
        }
    } catch (PDOException $e) {
        throw $e;
    }
    $paired = bk_pair_link_periods($events, $window_start, $now, $open_since);

    return [
        'primary' => $pick($primary_dev),
        'backup' => $pick($backup_dev),
        'days' => $days,
        // Named for what they measure: the primary link being down. Whether
        // traffic really went over the backup in that time is what the backup
        // device's byte counts say - a router with no LTE has outages too.
        'wan_down_periods' => $paired['periods'],
        'wan_down_seconds' => $paired['seconds'],
        'wan_down_now' => $paired['open'],
        'interfaces' => array_keys($stats),
    ];
}

/**
 * Fetches cumulative transferred data (RX/TX bytes and packets) for all of a monitor's interfaces over several periods.
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

                   -- Strictly after the boundary day: a >= against today minus 7 is eight calendar days.
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN rx_bytes_total ELSE 0 END) as rx_7d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN tx_bytes_total ELSE 0 END) as tx_7d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN rx_packets_total ELSE 0 END) as rx_pkts_7d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN tx_packets_total ELSE 0 END) as tx_pkts_7d,

                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN rx_bytes_total ELSE 0 END) as rx_30d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN tx_bytes_total ELSE 0 END) as tx_30d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN rx_packets_total ELSE 0 END) as rx_pkts_30d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN tx_packets_total ELSE 0 END) as tx_pkts_30d,

                   -- Days that actually carry a measurement. Without them a
                   -- window with no reports at all is indistinguishable from
                   -- one where nothing was transferred.
                   SUM(CASE WHEN date = CURDATE() THEN 1 ELSE 0 END) as days_today,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as days_7d,
                   SUM(CASE WHEN date > DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as days_30d,
                   COUNT(*) as days_all,

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
                // How many days each window is built from - 0 means nothing
                // was measured there, which is not the same as no traffic.
                'days' => [
                    'today' => (int)$r['days_today'],
                    '7d' => (int)$r['days_7d'],
                    '30d' => (int)$r['days_30d'],
                    'all' => (int)$r['days_all'],
                ],
            ];
        }
    } catch (PDOException $e) { /* best-effort */ }
    return $result;
}


/**
 * Writes an agent-side check result into an 'agent_service' monitor.
 *
 * Private-network services (kresd on the router, an MQTT broker on the LAN)
 * are forever unreachable from the hosting - so the agent checks them right
 * on the machine and the server just honestly records the result: the status
 * transition, a log WITHOUT response_time (the agent measures no latency, a zero would be invented) and a notification on change.
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
        // Notify only on real up/down transitions; the first result's
        // 'unknown' -> 'up' need not wake anyone.
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
 * Validates the target of a monitor imported from Service Discovery.
 *
 * The target comes from the AGENT (both the discovery payload and the hostname
 * fallback) - a lower trust level than the admin who merely confirms the
 * import. The checks also run from the webhosting: loopback, private and
 * link-local ranges are never reachable from there, so the check would fail
 * forever while aiming into the hosting provider's internal network. Manual
 * monitor creation via the admin (save_monitor) stays unrestricted - the admin is fully trusted.
 *
 * Returns an error text for the user, or null when the target is fine.
 */
function bk_validate_import_target(string $target): ?string {
    $host = trim($target);
    // A possible URL (web service) - only the host matters.
    if (preg_match('#^[a-z][a-z0-9+.-]*://([^/]+)#i', $host, $m)) {
        $host = $m[1];
    }
    // Port separation: [ipv6]:port, host:port. Bare IPv6 (more colons) is left alone.
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
 * Links and merges agent details (ts3_process, discovered_services, top_cpu_processes, interfaces, ...)
 * for any monitor (e.g. TeamSpeak, Minecraft, Web), even when the user never set an asset_id manually.
 */
/**
 * Thresholds and rule order of the weekly router recommendations.
 *
 * ONE place for every number a rule compares against, and the explicit rule
 * order. The order is NOT alphabetical: sorting by key would put
 * `disk_selftest_never` (an optional hygiene item) above
 * `disk_unclean_shutdowns` (a router losing power), which is the opposite of
 * what the week's reader needs first.
 *
 * `enter` / `hold`: a rule starts at `enter`, and while the last SAVED
 * evaluation had it active it continues down to `hold`. Without the pair an
 * item that sits on its boundary (the owner's weakest client is exactly on
 * -75 dBm) would vanish and come back as NEW every other week.
 */
function bk_router_rec_thresholds(): array {
    return [
        // Order = severity, area, THIS rank, key. Areas themselves are ordered
        // storage, wifi, wan, security, system, packages (release contract X10).
        'rank' => [
            // storage
            'disk_smart_failing', 'disk_errors_growing', 'disk_wear_high', 'disk_wear_fast',
            'disk_heavy_writes', 'disk_temp_warm', 'disk_unclean_shutdowns', 'disk_selftest_never',
            'fs_nearly_full', 'disk_smart_unreadable',
            // wifi
            'wifi_6ghz_unserved', 'wifi_channel_busy', 'wifi_noise_high', 'wifi_week_degraded',
            'wifi_weak_encryption', 'wifi_wpa2_only', 'wifi_wpa3_ready', 'wifi_mode_below_card',
            'wifi_channel_narrow', 'wifi_24_wide_channel', 'wifi_weak_client', 'wifi_5ghz_clients_on_24',
            // wan
            'wan_link_below_plan', 'wan_port_errors', 'wan_link_flaps', 'wan_line_below_plan',
            'wan_port_limited', 'wan_cpu_packet_path', 'wan_forwarding_core_saturated',
            'wan_sqm_limited', 'conntrack_drops', 'lan_wired_ceiling',
            // security
            'firewall_off',
            // system
            'router_restarts', 'dns_resolver_failing', 'clock_skew',
            // packages, always last
            'pkg_smartmontools', 'pkg_smart_drivedb', 'pkg_hostapd_utils', 'pkg_iw', 'pkg_librespeed_cli',
        ],
        'area' => [
            'storage' => 0, 'wifi' => 1, 'wan' => 2, 'security' => 3, 'system' => 4, 'packages' => 5,
        ],
        'severity' => ['critical' => 0, 'warning' => 1, 'info' => 2],
        // A weekly rule needs this many days with data, otherwise it is not
        // evaluated at all (a router switched off over a holiday must not
        // clear an item and mail it again as new when it comes back).
        'min_days' => 4,
        // A day counts as "with data" from this many samples (a quarter of the
        // minutes of a day).
        'day_samples' => 360,
        'wifi_6ghz_unserved' => ['enter' => 0.50, 'hold' => 0.25],
        'wifi_weak_client' => ['enter' => 0.5, 'hold' => 0.25],
        'wifi_noise_high' => ['enter' => -85.0, 'hold' => -87.0, 'enter_warn' => -80.0, 'hold_warn' => -82.0],
        'wifi_channel_busy_other' => ['enter' => 35.0, 'hold' => 30.0, 'enter_warn' => 55.0, 'hold_warn' => 50.0],
        'wifi_channel_busy_total' => ['enter' => 50.0, 'hold' => 45.0, 'enter_warn' => 70.0, 'hold_warn' => 65.0],
        // Offsets from the class limit L of the disk (3.5): mean >= L-10 fires,
        // >= L-5 is a warning; a saved item holds two degrees longer.
        'disk_temp_warm' => ['enter' => -10, 'hold' => -12, 'enter_warn' => -5, 'hold_warn' => -7],
        'wifi_5ghz_clients_on_24' => ['enter' => 1.0, 'hold' => 0.75],
        'wifi_week_degraded' => ['noise_rise' => 6.0, 'noise_above' => -88.0, 'busy_rise' => 15.0, 'busy_above' => 30.0],
        'disk_wear_high' => ['warn' => 80.0, 'crit' => 90.0, 'emmc_warn' => 9, 'emmc_crit' => 10],
        'disk_wear_fast' => ['min_days' => 14, 'min_delta' => 1.0, 'days_left' => 730],
        'disk_heavy_writes' => ['min_days' => 3, 'flash_gib' => 1.0, 'flash_share' => 0.10,
            'ssd_gib' => 10.0, 'ssd_share' => 0.30],
        'disk_unclean_shutdowns' => ['min' => 10, 'share' => 0.5],
        'disk_selftest_never' => ['min_hours' => 168],
        'fs_nearly_full' => ['crit' => 98.0],
        'wifi_channel_narrow' => ['busy_below' => 40.0],
        'wifi_wpa3_ready' => ['day_samples' => 1296],
        // The WAN and gap rules of the release contract's rule sheet 2.1.
        'wan_link_below_plan' => ['enter' => 6, 'hold' => 5],
        'wan_port_errors' => ['enter' => 100.0, 'enter_days' => 3, 'hold' => 50.0, 'hold_days' => 2,
            'ring_enter' => 0.1, 'ring_enter_days' => 2, 'ring_hold' => 0.05, 'ring_hold_days' => 1],
        'wan_link_flaps' => ['enter' => 3, 'hold' => 2],
        'wan_forwarding_core_saturated' => ['enter' => 10, 'hold' => 5,
            'core_pct' => 95.0, 'softirq_pct' => 85.0, 'wan_mbps' => 300.0],
        'conntrack_drops' => ['enter' => 2, 'hold' => 1, 'full_pct' => 90.0],
        'router_restarts' => ['enter' => 3, 'hold' => 2],
        'dns_resolver_failing' => ['enter' => 3, 'hold' => 2],
        'clock_skew' => ['enter' => 20.0, 'hold' => 15.0],
        'lan_wired_ceiling' => ['cap_at_most' => 1000],
        'wan_sqm_limited' => ['below_plan' => 0.8],
    ];
}


/**
 * One weekly value of one metric out of the daily rows of the window.
 *
 * `value` = SUM(avg_val * samples) / SUM(samples) - the weighted mean, not the
 * mean of daily means, so a day with four samples cannot outweigh a full one.
 * `sum` is that same total and IS the week's value of a step metric.
 * `days` counts only days with enough samples; a rule that compares a weekly
 * value needs `days >= min_days`, everything else is "not evaluated".
 *
 * @param array $days  ['Y-m-d' => ['min'=>?float,'avg'=>?float,'max'=>?float,'samples'=>int], ...]
 */
function bk_rec_week_stat(array $window, string $key): array {
    $th = bk_router_rec_thresholds();
    $days = $window[$key] ?? [];
    $sum = 0.0;
    $samples = 0;
    $full_days = 0;
    $max = null;
    $min = null;
    $daily = [];
    foreach ($days as $day => $row) {
        $n = (int)($row['samples'] ?? 0);
        $avg = isset($row['avg']) && is_numeric($row['avg']) ? (float)$row['avg'] : null;
        if ($n <= 0 || $avg === null) {
            continue;
        }
        $sum += $avg * $n;
        $samples += $n;
        $daily[$day] = ['sum' => $avg * $n, 'avg' => $avg, 'samples' => $n,
            'max' => isset($row['max']) && is_numeric($row['max']) ? (float)$row['max'] : null,
            'min' => isset($row['min']) && is_numeric($row['min']) ? (float)$row['min'] : null];
        if ($n >= $th['day_samples']) {
            $full_days++;
        }
        if (isset($row['max']) && is_numeric($row['max'])) {
            $max = $max === null ? (float)$row['max'] : max($max, (float)$row['max']);
        }
        if (isset($row['min']) && is_numeric($row['min'])) {
            $min = $min === null ? (float)$row['min'] : min($min, (float)$row['min']);
        }
    }
    return [
        'value' => $samples > 0 ? $sum / $samples : null,
        'sum' => $samples > 0 ? $sum : null,
        'days' => $full_days,
        'samples' => $samples,
        'max' => $max,
        'min' => $min,
        'daily' => $daily,
    ];
}

/**
 * Is this weekly value over its threshold right now?
 *
 * `$active` is the state of the last SAVED evaluation, so the pair works at
 * the weekly granularity the digest has. A null value never fires a rule.
 */
function bk_rec_over(?float $value, float $enter, float $hold, bool $active): bool {
    if ($value === null) {
        return false;
    }
    return $active ? $value >= $hold : $value >= $enter;
}

/** The same for a threshold that fires when the value is ABOVE it (noise, dBm). */
function bk_rec_above(?float $value, float $enter, float $hold, bool $active): bool {
    if ($value === null) {
        return false;
    }
    return $active ? $value > $hold : $value > $enter;
}

/** Was this key active in the last saved evaluation? */
function bk_rec_state_active(array $state, string $key): bool {
    return !empty($state[$key]['active']);
}

/** The severity the last saved evaluation stored for this key (null = none). */
function bk_rec_state_severity(array $state, string $key): ?string {
    $sev = $state[$key]['severity'] ?? null;
    return is_string($sev) && $sev !== '' ? $sev : null;
}

/**
 * A number for a rule text: decimal comma in Czech, an em dash when the value
 * was never measured (a rule that renders "0" for an unknown quantity is the
 * invented zero this project keeps hunting).
 */
function bk_rec_num($value, int $decimals = 0): string {
    if ($value === null || !is_numeric($value)) {
        return '—';
    }
    $out = number_format((float)$value, $decimals, '.', '');
    if (($GLOBALS['BK_LANG'] ?? 'cs') !== 'en') {
        $out = str_replace('.', ',', $out);
    }
    return $out;
}

/** cs "stahování" / en "download" - a direction is never shown as `dl`. */
function bk_rec_dir_label(?string $dir): string {
    return t($dir === 'ul' ? 'rr_dir_ul' : 'rr_dir_dl');
}

/** cs "2,4 GHz" / en "2.4 GHz" - never a raw enum value in a sentence. */
function bk_rec_band_label(?string $band): string {
    switch ($band) {
        case '2.4GHz': return t('rr_band_24g');
        case '5GHz': return t('rr_band_5g');
        case '6GHz': return t('rr_band_6g');
    }
    return t('rr_band_unknown');
}

/** cs "5 GHz, kanál 36" / en "5 GHz, channel 36". Never the SSID (CORE 3.7). */
function bk_rec_radio_label(?string $band, $channel): string {
    $label = bk_rec_band_label($band);
    if ($channel === null || !is_numeric($channel)) {
        return $label;
    }
    return $label . ', ' . sprintf(t('rr_channel'), (string)(int)$channel);
}

/** "{smart model or model} ({name})" - the disk as the owner sees it on the card. */
function bk_rec_disk_label(array $disk): string {
    return bk_disk_label($disk);
}

/**
 * The install command for a `pkg_*` rule, in the package manager the router
 * really has. Unknown manager -> a sentence, never a command that would fail.
 */
function bk_rec_install_cmd(?string $pkg_manager, string $pkg): string {
    if ($pkg_manager === 'opkg') {
        return 'opkg update && opkg install ' . $pkg;
    }
    if ($pkg_manager === 'apk') {
        return 'apk add ' . $pkg;
    }
    return sprintf(t('rr_cmd_manual'), $pkg);
}

/**
 * One language-neutral item. `params` carries the numbers the text will need,
 * `subject` says what the item is about (a disk, a radio, a band, a mount, a
 * direction of the WAN), so the app can group them without parsing texts.
 */
function bk_rec_item(string $id, string $key, string $area, string $severity, array $subject, array $params = [], bool $page_only = false): array {
    $item = [
        'id' => $id,
        'key' => $key,
        'area' => $area,
        'severity' => $severity,
        'subject' => $subject,
        'params' => $params,
    ];
    if ($page_only) {
        // X12: dropped by the digest before the new/unchanged split, still saved.
        $item['page_only'] = true;
    }
    return $item;
}


/**
 * The copyable command an action names, if it names one (CORE 3.9).
 *
 * The sentence itself carries the command in prose so the e-mail reads
 * naturally; the page renders it a second time in a code block, and this is
 * where that block gets its text. It is built from the item, not parsed out of
 * the translated sentence - a Czech and an English text would otherwise have
 * to keep an identical command substring for ever.
 */
function bk_rec_command(array $item): ?string {
    $id = (string)($item['id'] ?? '');
    $p = is_array($item['params'] ?? null) ? $item['params'] : [];
    $dev = (string)($p['name'] ?? '');
    if ($dev === '' || !preg_match('/^[a-z0-9]{1,16}$/', $dev)) {
        $dev = '';
    }
    switch ($id) {
        case 'disk_errors_growing':
            return $dev === '' ? null : 'smartctl -t long /dev/' . $dev;
        case 'disk_selftest_never':
            return $dev === '' ? null : 'smartctl -t short /dev/' . $dev;
        case 'pkg_librespeed_cli':
            return bk_rec_install_cmd(is_string($p['pkg_manager'] ?? null) ? $p['pkg_manager'] : null, 'librespeed-cli');
        default:
            return null;
    }
}

/**
 * Packages the router does NOT have, by the name you would install them under.
 *
 * `agent_tools` carries strict booleans (X4), so false really means "looked and
 * did not find it" - a tool the agent could not test at all is absent from the
 * object and absent from this list.
 *
 * @return list<string>
 */
function bk_rec_missing_packages($agent_tools): array {
    if (!is_array($agent_tools)) {
        return [];
    }
    $pkgs = [
        'smartctl' => 'smartmontools',
        'smart_drivedb' => 'smartmontools-drivedb',
        'hostapd_cli' => 'hostapd-utils',
        'iw' => 'iw',
        'librespeed_cli' => 'librespeed-cli',
        'ethtool' => 'ethtool',
        'tc' => 'tc-tiny',
    ];
    $out = [];
    foreach ($pkgs as $flag => $pkg) {
        if (($agent_tools[$flag] ?? null) === false) {
            $out[] = $pkg;
        }
    }
    return $out;
}

/**
 * One recommendation as the app receives it (CORE 3.9).
 *
 * `$render` is false for a muted key that no longer fires: there is no item to
 * render any more, only the mute itself, and the three texts are null so the
 * app shows the stored reason and an "unmute" button instead of a sentence
 * that is no longer true.
 *
 * @param array<string, mixed> $item
 * @param array<string, mixed>|null $state_row the router_rec_state row, for the mute
 * @return array<string, mixed>
 */
function bk_rec_item_json(array $item, $state_row = null, bool $render = true): array {
    $subject = is_array($item['subject'] ?? null) ? $item['subject'] : ['kind' => 'router'];
    // The wire is camelCase (CORE 3.9); inside the engine the subject is
    // snake_case like everything else the rules build.
    $subject_json = [];
    foreach ($subject as $k => $v) {
        $subject_json[$k === 'disk_key' ? 'diskKey' : (string)$k] = $v;
    }
    $params = is_array($item['params'] ?? null) ? $item['params'] : [];
    $texts = $render ? bk_router_rec_render($item) : ['title' => null, 'measured' => null, 'action' => null];
    $out = [
        'key' => (string)($item['key'] ?? ''),
        'id' => (string)($item['id'] ?? ''),
        'area' => (string)($item['area'] ?? ''),
        'severity' => (string)($item['severity'] ?? 'info'),
        'title' => $texts['title'],
        'measured' => $texts['measured'],
        'action' => $texts['action'],
        'subject' => $subject_json,
        'params' => $params,
        'command' => $render ? bk_rec_command($item) : null,
        'openSince' => $item['openSince'] ?? null,
    ];
    if (!empty($item['page_only'])) {
        $out['pageOnly'] = true;
    }
    if (!empty($params['was_muted'])) {
        $out['wasMuted'] = true;
    }
    if (!$render) {
        $out['active'] = false;
    }
    if (is_array($state_row) && !empty($state_row['muted_at'])) {
        $out['mute'] = [
            'at' => $state_row['muted_at'],
            'by' => (string)($state_row['muted_by'] ?? ''),
            'reason' => ($state_row['mute_reason'] ?? null) !== '' ? ($state_row['mute_reason'] ?? null) : null,
            'severity' => (string)($state_row['muted_severity'] ?? 'info'),
        ];
    }
    return $out;
}

/**
 * The last (or first) non-null value of one column over a list of days.
 *
 * A disk counter is read hourly, so a day with no SMART reading has no row at
 * all - "the last value in the window" is the newest day that really has one,
 * never a zero standing in for a missing day.
 *
 * @param array $daily ['Y-m-d' => row, ...]
 * @param array $days  the window, oldest first
 */
function bk_rec_daily_value(array $daily, array $days, string $col, bool $first = false) {
    $order = $first ? $days : array_reverse($days);
    foreach ($order as $day) {
        $v = $daily[$day][$col] ?? null;
        if ($v !== null && is_numeric($v)) {
            return $v + 0;
        }
    }
    return null;
}

/**
 * Disk health rules of CORE 3.7: what SMART and eMMC say about the drive
 * itself. One item per disk, key `<rule>:d:<disk_key>`.
 *
 * Every rule reads what the week really recorded: a counter that is high but
 * STABLE (the owner's three runtime bad blocks) says nothing new and must stay
 * silent, a counter that GREW is what precedes a failure.
 */
function bk_rec_rules_disk_health(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $not_evaluated = [];
    $days = $in['window']['days'] ?? [];
    $days30 = $in['window']['days30'] ?? $days;
    $disks = is_array($in['details']['storage_disks'] ?? null) ? $in['details']['storage_disks'] : [];

    foreach ($disks as $disk) {
        if (!is_array($disk) || !is_string($disk['key'] ?? null)) {
            continue;
        }
        $dk = $disk['key'];
        $daily = is_array($in['disks'][$dk]['daily'] ?? null) ? $in['disks'][$dk]['daily'] : [];
        $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
        $emmc = is_array($disk['emmc'] ?? null) ? $disk['emmc'] : [];
        $subject = ['kind' => 'disk', 'disk_key' => $dk, 'name' => (string)($disk['name'] ?? '?')];
        $label = bk_rec_disk_label($disk);
        $sfx = ':d:' . $dk;

        // --- disk_smart_failing (critical): the drive itself says it is going.
        $cw = $smart['critical_warning'] ?? null;
        $pre_eol = $emmc['pre_eol'] ?? null;
        $reason = null;
        if (is_int($cw) && ($cw & 0x3D) !== 0) {
            $reason = 'nvme';
        } elseif ($pre_eol === 3) {
            $reason = 'emmc';
        } elseif (($smart['state'] ?? null) === 'failing') {
            $reason = ($smart['passed'] ?? null) === false ? 'verdict' : 'attribute';
        }
        if ($reason !== null) {
            $items[] = bk_rec_item('disk_smart_failing', 'disk_smart_failing' . $sfx, 'storage', 'critical',
                $subject, ['disk' => $label, 'name' => $subject['name'], 'reason' => $reason,
                    'critical_warning' => is_int($cw) ? $cw : null]);
        }

        // --- disk_errors_growing: growth inside the week, or a pending sector now.
        $changes = [];
        $severe = false;
        foreach (bk_disk_error_counters() as $def) {
            $col = $def[0];
            $before = bk_rec_daily_value($daily, $in['window']['prev_days'] ?? [], $col);
            $last = bk_rec_daily_value($daily, $days, $col);
            if ($last === null) {
                continue;
            }
            $from = $before !== null ? $before : bk_rec_daily_value($daily, $days, $col, true);
            if ($from === null || $last <= $from) {
                continue;
            }
            $changes[] = ['counter' => $col, 'from' => $from + 0, 'to' => $last + 0];
            if (in_array($col, ['pending_sectors', 'offline_uncorrectable', 'reported_uncorrect', 'media_errors'], true)) {
                $severe = true;
            }
        }
        $pending_now = $smart['pending_sectors'] ?? null;
        $offline_now = $smart['offline_uncorrectable'] ?? null;
        if ((is_int($pending_now) && $pending_now > 0) || (is_int($offline_now) && $offline_now > 0)) {
            $severe = true;
            $changes[] = ['counter' => 'pending_now',
                'to' => (is_int($pending_now) && $pending_now > 0) ? $pending_now : $offline_now];
        }
        if ($changes !== []) {
            $items[] = bk_rec_item('disk_errors_growing', 'disk_errors_growing' . $sfx, 'storage',
                $severe ? 'critical' : 'warning', $subject,
                ['disk' => $label, 'name' => $subject['name'], 'changes' => $changes]);
        }

        // --- disk_wear_high: the drive's own wear estimate, SMART or eMMC.
        $wear = $smart['wear_pct'] ?? null;
        $life = null;
        foreach (['life_a', 'life_b'] as $lk) {
            $v = $emmc[$lk] ?? null;
            if (is_int($v) && $v >= 1 && $v <= 11) {
                $life = $life === null ? $v : max($life, $v);
            }
        }
        $wear_sev = null;
        $wear_params = ['disk' => $label, 'name' => $subject['name'], 'variant' => 'attr'];
        if (is_numeric($wear) && (float)$wear >= $th['disk_wear_high']['warn']) {
            $wear_sev = (float)$wear >= $th['disk_wear_high']['crit'] ? 'critical' : 'warning';
            $wear_params['wear'] = (float)$wear;
            $wear_params['source'] = $smart['wear_source'] ?? null;
        } elseif ($life !== null && $life >= $th['disk_wear_high']['emmc_warn']) {
            $wear_sev = $life >= $th['disk_wear_high']['emmc_crit'] ? 'critical' : 'warning';
            $wear_params['variant'] = 'emmc';
            $wear_params['life'] = $life;
            $wear_params['pre_eol'] = is_int($pre_eol) ? $pre_eol : null;
        } elseif ($pre_eol === 2) {
            $wear_sev = 'warning';
            $wear_params['variant'] = 'emmc';
            $wear_params['life'] = $life;
            $wear_params['pre_eol'] = 2;
        }
        if ($wear_sev !== null) {
            $items[] = bk_rec_item('disk_wear_high', 'disk_wear_high' . $sfx, 'storage', $wear_sev, $subject, $wear_params);
        }

        // --- disk_wear_fast: the PACE of the wear, over 30 days, not the level.
        $w0 = null;
        $w1 = null;
        $d0 = null;
        $d1 = null;
        foreach ($days30 as $day) {
            $row = $daily[$day] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $v = null;
            if (isset($row['wear_pct']) && is_numeric($row['wear_pct'])) {
                $v = (float)$row['wear_pct'];
            } elseif (isset($row['emmc_life']) && is_numeric($row['emmc_life'])) {
                $v = (float)$row['emmc_life'] * 10.0;
            }
            if ($v === null) {
                continue;
            }
            if ($w0 === null) {
                $w0 = $v;
                $d0 = $day;
            }
            $w1 = $v;
            $d1 = $day;
        }
        if ($w0 !== null && $w1 !== null && $d0 !== null && $d1 !== null) {
            $span = (int)round((strtotime($d1) - strtotime($d0)) / 86400);
            $delta = $w1 - $w0;
            if ($span >= $th['disk_wear_fast']['min_days'] && $delta >= $th['disk_wear_fast']['min_delta'] && $w1 < 100.0) {
                $left = (int)floor((100.0 - $w1) / ($delta / $span));
                if ($left < $th['disk_wear_fast']['days_left']) {
                    $items[] = bk_rec_item('disk_wear_fast', 'disk_wear_fast' . $sfx, 'storage', 'warning', $subject,
                        ['disk' => $label, 'name' => $subject['name'], 'days' => $span,
                            'w0' => $w0, 'w1' => $w1, 'left' => $left]);
                }
            }
        }
    }

    return ['items' => $items, 'not_evaluated' => $not_evaluated];
}


/**
 * The disk rules that read the WEEK, not one SMART reading: how warm the disk
 * ran, how much was written to it, how it is being powered off, and whether it
 * was ever tested. `disk_smart_unreadable` sits here too - it is about the
 * collection, not about the drive.
 */
function bk_rec_rules_disk_week(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $not_evaluated = [];
    $days = $in['window']['days'] ?? [];
    $now = (int)($in['now'] ?? time());
    $disks = is_array($in['details']['storage_disks'] ?? null) ? $in['details']['storage_disks'] : [];

    foreach ($disks as $disk) {
        if (!is_array($disk) || !is_string($disk['key'] ?? null)) {
            continue;
        }
        $dk = $disk['key'];
        $daily = is_array($in['disks'][$dk]['daily'] ?? null) ? $in['disks'][$dk]['daily'] : [];
        $smart = is_array($disk['smart'] ?? null) ? $disk['smart'] : [];
        $subject = ['kind' => 'disk', 'disk_key' => $dk, 'name' => (string)($disk['name'] ?? '?')];
        $label = bk_rec_disk_label($disk);
        $sfx = ':d:' . $dk;

        // --- disk_heavy_writes: only flash can be worn out by writing.
        $rot = $disk['rotational'] ?? null;
        $rpm = $smart['rotation_rpm'] ?? null;
        $spinning = $rpm !== null ? ($rpm > 0) : (is_bool($rot) ? $rot : null);
        $wb_sum = 0.0;
        $wb_days = 0;
        foreach ($days as $day) {
            $row = $daily[$day] ?? null;
            if (!is_array($row) || !empty($row['host_written_partial'])) {
                continue;
            }
            $v = $row['host_written_bytes'] ?? null;
            if ($v === null || !is_numeric($v)) {
                continue;
            }
            $wb_sum += (float)$v;
            $wb_days++;
        }
        $size = $disk['size_bytes'] ?? null;
        if ($spinning === false && $wb_days >= $th['disk_heavy_writes']['min_days']) {
            $per_day = $wb_sum / $wb_days;
            $transport = $disk['transport'] ?? null;
            $flash = in_array($transport, ['emmc', 'sd', 'usb'], true);
            $gib = $flash ? $th['disk_heavy_writes']['flash_gib'] : $th['disk_heavy_writes']['ssd_gib'];
            $share = $flash ? $th['disk_heavy_writes']['flash_share'] : $th['disk_heavy_writes']['ssd_share'];
            $limit = $gib * 1073741824.0;
            if (is_numeric($size) && (float)$size > 0) {
                $limit = max($limit, (float)$size * $share);
            }
            if ($per_day >= $limit) {
                $items[] = bk_rec_item('disk_heavy_writes', 'disk_heavy_writes' . $sfx, 'storage',
                    $flash ? 'warning' : 'info', $subject,
                    ['disk' => $label, 'name' => $subject['name'], 'gb' => $per_day / 1000000000.0,
                        'pct' => is_numeric($size) && (float)$size > 0 ? $per_day / (float)$size * 100.0 : null]);
            }
        }

        // --- disk_temp_warm: the WEEKLY answer to a disk that is always warm.
        // The notification of 3.5 only fires when the limit itself is crossed.
        $temp_sum = 0.0;
        $temp_n = 0;
        $temp_max = null;
        $temp_days = 0;
        foreach ($days as $day) {
            $row = $daily[$day] ?? null;
            // Both halves of the day's mean have to be there: a day that
            // counted readings but stored no sum would otherwise pull the
            // week's mean towards a temperature nobody measured.
            if (!is_array($row) || !is_numeric($row['temp_n'] ?? null) || (int)$row['temp_n'] <= 0
                || !is_numeric($row['temp_sum'] ?? null)) {
                continue;
            }
            $temp_sum += (float)$row['temp_sum'];
            $temp_n += (int)$row['temp_n'];
            $temp_days++;
            if (isset($row['temp_max']) && is_numeric($row['temp_max'])) {
                $temp_max = $temp_max === null ? (float)$row['temp_max'] : max($temp_max, (float)$row['temp_max']);
            }
        }
        $temp_key = 'disk_temp_warm' . $sfx;
        if ($temp_days < $th['min_days'] || $temp_n <= 0) {
            $not_evaluated[$temp_key] = 'disk_temp_warm';
        } else {
            $mean = $temp_sum / $temp_n;
            $limit = bk_disk_temp_limit($disk);
            $active = bk_rec_state_active($state, $temp_key);
            $tt = $th['disk_temp_warm'];
            if (bk_rec_over($mean, $limit + $tt['enter'], $limit + $tt['hold'], $active)) {
                $was_warn = bk_rec_state_severity($state, $temp_key) === 'warning';
                $sev = bk_rec_over($mean, $limit + $tt['enter_warn'], $limit + $tt['hold_warn'], $active && $was_warn)
                    ? 'warning' : 'info';
                $items[] = bk_rec_item('disk_temp_warm', $temp_key, 'storage', $sev, $subject,
                    ['disk' => $label, 'name' => $subject['name'], 'avg_c' => $mean,
                        'max_c' => $temp_max, 'limit_c' => $limit]);
            }
        }

        // --- disk_unclean_shutdowns: the router loses power instead of being shut down.
        $unsafe = $smart['unsafe_shutdowns'] ?? null;
        $cycles = $smart['power_cycles'] ?? null;
        if (is_numeric($unsafe) && is_numeric($cycles) && (float)$cycles > 0
            && (float)$unsafe >= $th['disk_unclean_shutdowns']['min']
            && (float)$unsafe / (float)$cycles >= $th['disk_unclean_shutdowns']['share']) {
            $first = bk_rec_daily_value($daily, $days, 'unsafe_shutdowns', true);
            $last = bk_rec_daily_value($daily, $days, 'unsafe_shutdowns');
            $grew = ($first !== null && $last !== null && $last > $first) ? (int)($last - $first) : null;
            $items[] = bk_rec_item('disk_unclean_shutdowns', 'disk_unclean_shutdowns' . $sfx, 'storage',
                $grew !== null ? 'warning' : 'info', $subject,
                ['disk' => $label, 'name' => $subject['name'], 'unsafe' => (int)$unsafe,
                    'cycles' => (int)$cycles, 'grew' => $grew]);
        }

        // --- disk_selftest_never (info): optional hygiene, the drive is healthy.
        $hours = $smart['power_on_hours'] ?? null;
        if (($smart['state'] ?? null) === 'ok' && ($smart['protocol'] ?? null) === 'ATA'
            && ($smart['selftest_count'] ?? null) === 0
            && is_numeric($hours) && (float)$hours >= $th['disk_selftest_never']['min_hours']) {
            $items[] = bk_rec_item('disk_selftest_never', 'disk_selftest_never' . $sfx, 'storage', 'info', $subject,
                ['disk' => $label, 'name' => $subject['name'], 'hours' => (int)$hours]);
        }

        // --- disk_smart_unreadable: the collection is failing, not the disk.
        $st = $smart['state'] ?? null;
        $checked = $smart['checked_at'] ?? null;
        $why = null;
        if ($st === 'unsupported') {
            $why = 'unsupported';
        } elseif ($st === 'stuck') {
            $why = 'stuck';
        } elseif ($st === 'error' && (!is_numeric($checked) || ($now - (int)$checked) > 86400)) {
            $why = 'error';
        }
        if ($why !== null) {
            $items[] = bk_rec_item('disk_smart_unreadable', 'disk_smart_unreadable' . $sfx, 'storage',
                $why === 'unsupported' ? 'info' : 'warning', $subject,
                ['disk' => $label, 'name' => $subject['name'], 'why' => $why]);
        }
    }

    return ['items' => $items, 'not_evaluated' => $not_evaluated];
}

/**
 * `fs_nearly_full`, per mount, from the CURRENT `filesystems` list.
 *
 * A configuration rule: it reads this minute, not the week, because a full
 * partition stops services now. `/` and `/overlay` ARE included here (unlike
 * the notification of 3.5, which would page for a full read-only root).
 */
function bk_rec_rules_filesystems(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $threshold = $in['monitor']['hdd_threshold'] ?? null;
    $threshold = is_numeric($threshold) ? (float)$threshold : 90.0;
    $skip_fstypes = ['squashfs', 'iso9660', 'erofs', 'romfs', 'cramfs'];
    $list = is_array($in['details']['filesystems'] ?? null) ? $in['details']['filesystems'] : [];

    foreach ($list as $fs) {
        if (!is_array($fs) || !is_string($fs['mount'] ?? null) || $fs['mount'] === '') {
            continue;
        }
        $mount = $fs['mount'];
        if ($mount === '/rom' || in_array($fs['fstype'] ?? null, $skip_fstypes, true)) {
            continue;
        }
        $total_kb = $fs['total_kb'] ?? null;
        $used = $fs['used_pct'] ?? null;
        if (!is_numeric($total_kb) || (float)$total_kb < 65536 || !is_numeric($used)) {
            continue;
        }
        $used = (float)$used;
        if ($used < $threshold) {
            continue;
        }
        $avail = $fs['avail_kb'] ?? null;
        $items[] = bk_rec_item('fs_nearly_full', 'fs_nearly_full:m:' . substr(sha1($mount), 0, 12), 'storage',
            $used >= $th['fs_nearly_full']['crit'] ? 'critical' : 'warning',
            ['kind' => 'mount', 'mount' => $mount],
            ['mount' => $mount, 'pct' => $used,
                'free' => is_numeric($avail) ? (float)$avail * 1024.0 : null,
                // Turris keeps btrfs snapshots of the root: the action can name
                // the tool that frees the space instead of a generic sentence.
                'schnapps' => ($mount === '/' && ($fs['fstype'] ?? null) === 'btrfs')]);
    }

    return ['items' => $items, 'not_evaluated' => []];
}


/**
 * The band suffix the weekly Wi-Fi metrics are stored under: `wifi_noise_5g`,
 * `wifi_busy_other_24g`. Null for a radio whose band is unknown - such a radio
 * has no weekly values at all and every band rule skips it.
 */
function bk_rec_band_suffix(?string $band): ?string {
    $map = ['2.4GHz' => '24g', '5GHz' => '5g', '6GHz' => '6g'];
    return $band !== null && isset($map[$band]) ? $map[$band] : null;
}

/**
 * The stable identity of one radio inside a `rec_key`.
 *
 * NEVER the interface name: on this hardware the same USB radio was
 * `phy3-ap0` one day and `phy1-ap0` the next (REAL_FACTS, "Cron and unstable
 * radio names"), so a mute keyed on the name would be silently lost at the
 * next reboot and the item the owner silenced would come back. Band plus SSID
 * survives a rename; the interface name stays a display label in `subject`.
 */
function bk_rec_radio_key(array $radio): string {
    $band = is_string($radio['band'] ?? null) ? $radio['band'] : '?';
    $ssid = is_string($radio['ssid'] ?? null) ? $radio['ssid'] : '';
    return substr(sha1($band . '|' . $ssid), 0, 12);
}

/**
 * The daily rows of ONE window slice ('days' = W, 'prev_days' = P).
 *
 * `bk_rec_week_stat` sums every day it is handed and the inputs batch loads
 * thirty of them for the wear trend, so a weekly rule that read the whole map
 * would answer for a month. `wifi_week_degraded` needs W and P to be two
 * different things, which is the same reason.
 */
function bk_rec_metrics_of(array $window, string $which = 'days'): array {
    $wanted = array_flip(is_array($window[$which] ?? null) ? $window[$which] : []);
    $out = [];
    foreach (is_array($window['metrics'] ?? null) ? $window['metrics'] : [] as $key => $rows) {
        $out[$key] = [];
        foreach (is_array($rows) ? $rows : [] as $day => $row) {
            if (isset($wanted[$day])) {
                $out[$key][(string)$day] = $row;
            }
        }
    }
    return $out;
}

/** cs / en "Wi-Fi 6"; generation 0 is a device older than Wi-Fi 4, not "Wi-Fi 0". */
function bk_rec_wifi_gen_label(?int $gen): string {
    if ($gen === null) {
        return t('rr_wifi_gen_unknown');
    }
    return $gen <= 0 ? t('rr_wifi_gen_legacy') : sprintf(t('rr_wifi_gen'), (string)$gen);
}

/**
 * The `htmode` value a suggestion names, built from a generation and a width.
 *
 * The suggestion is always "the best prefix the card supports at the width it
 * runs now" (CORE 3.7); a rule that widens the channel passes the width it
 * wants. Never 160 MHz, and on 2.4 GHz never more than 20.
 */
function bk_rec_htmode(?int $gen, ?int $width): ?string {
    $prefix = [4 => 'HT', 5 => 'VHT', 6 => 'HE', 7 => 'EHT'];
    if ($gen === null || !isset($prefix[$gen]) || $width === null || $width <= 0) {
        return null;
    }
    return $prefix[$gen] . (string)(int)$width;
}

/**
 * The Wi-Fi rules of CORE 3.7 - AP-mode radios only.
 *
 * Three shapes of key live here, and the difference is what a mute survives:
 *   - per band   `<rule>:<24g|5g|6g>` - noise and airtime belong to the band,
 *     and the weekly metrics are per band as well;
 *   - per radio  `<rule>:r:<sha1(band|ssid)[0..12]>` - the configuration of
 *     one network, keyed on something a reboot cannot rename;
 *   - router-wide `<rule>` - what no single radio can answer.
 *
 * What was not measured stays silent and says so: a driver that reports no
 * noise and no survey (the owner's USB radio) leaves those metrics null, so
 * the noise and airtime rules are NOT EVALUATED for that band - they do not
 * quietly "pass", and `bk_router_rec_state_save` leaves their rows alone.
 *
 * @return array{items: list<array<string, mixed>>, not_evaluated: array<string, string>}
 */
function bk_rec_rules_wifi(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $skip = [];
    $details = is_array($in['details'] ?? null) ? $in['details'] : [];
    $window = is_array($in['window'] ?? null) ? $in['window'] : ['days' => [], 'prev_days' => [], 'metrics' => []];
    $w = bk_rec_metrics_of($window, 'days');
    $p = bk_rec_metrics_of($window, 'prev_days');

    // AP radios only: a client, mesh or disabled interface has no channel of
    // the owner's to recommend and no network of his to secure.
    $radios = [];
    foreach (is_array($details['wifi_radios'] ?? null) ? $details['wifi_radios'] : [] as $radio) {
        if (is_array($radio) && ($radio['mode'] ?? null) === 'ap') {
            $radios[] = $radio;
        }
    }

    // --- Per band. Two radios of the same band share one item: the channel
    //     and its noise are a property of the air, not of an interface.
    $bands = [];
    foreach ($radios as $radio) {
        $sfx = bk_rec_band_suffix(is_string($radio['band'] ?? null) ? $radio['band'] : null);
        if ($sfx === null) {
            continue;
        }
        if (!isset($bands[$sfx])) {
            $bands[$sfx] = ['band' => (string)$radio['band'], 'channel' => null];
        }
        if ($bands[$sfx]['channel'] === null && isset($radio['channel']) && is_numeric($radio['channel'])) {
            $bands[$sfx]['channel'] = (int)$radio['channel'];
        }
    }

    foreach ($bands as $sfx => $band_info) {
        $band = $band_info['band'];
        // The band travels as its ENUM, never as a rendered label: an item is
        // evaluated once (the Monday digest) and rendered per recipient, so a
        // Czech "2,4 GHz" baked in here would end up in an English e-mail.
        $subject = ['kind' => 'band', 'band' => $band];
        $noise = bk_rec_week_stat($w, 'wifi_noise_' . $sfx);
        $busy = bk_rec_week_stat($w, 'wifi_busy_' . $sfx);
        $other = bk_rec_week_stat($w, 'wifi_busy_other_' . $sfx);

        // --- wifi_channel_busy. The foreign-traffic share is the honest
        //     number and it wins whenever the driver gives it; the total
        //     includes the owner's own downloads, which a channel change
        //     cannot help, so it carries its own (higher) threshold and says
        //     in the text that the driver cannot separate the two.
        $key = 'wifi_channel_busy:' . $sfx;
        $variant = null;
        if ($other['days'] >= $th['min_days'] && $other['value'] !== null) {
            $variant = 'other';
            $value = $other['value'];
        } elseif ($busy['days'] >= $th['min_days'] && $busy['value'] !== null) {
            $variant = 'total';
            $value = $busy['value'];
        } else {
            $skip[$key] = 'wifi_channel_busy';
        }
        if ($variant !== null) {
            $active = bk_rec_state_active($state, $key);
            $t = $th['wifi_channel_busy_' . $variant];
            if (bk_rec_over($value, (float)$t['enter'], (float)$t['hold'], $active)) {
                $was_warn = bk_rec_state_severity($state, $key) === 'warning';
                $sev = bk_rec_over($value, (float)$t['enter_warn'], (float)$t['hold_warn'], $active && $was_warn)
                    ? 'warning' : 'info';
                $items[] = bk_rec_item('wifi_channel_busy', $key, 'wifi', $sev, $subject,
                    ['band_id' => $band, 'variant' => $variant,
                        'ch' => $band_info['channel'], 'other' => $other['value'], 'busy' => $busy['value']]);
            }
        }

        // --- wifi_noise_high. The bounds are the app's "fair" / "poor" ones
        //     (lib/signal-quality.ts), so the weekly sentence and the live
        //     signal bar cannot disagree about the same dBm.
        $key = 'wifi_noise_high:' . $sfx;
        if ($noise['days'] < $th['min_days'] || $noise['value'] === null) {
            $skip[$key] = 'wifi_noise_high';
        } else {
            $active = bk_rec_state_active($state, $key);
            $t = $th['wifi_noise_high'];
            if (bk_rec_above($noise['value'], (float)$t['enter'], (float)$t['hold'], $active)) {
                $was_warn = bk_rec_state_severity($state, $key) === 'warning';
                $sev = bk_rec_above($noise['value'], (float)$t['enter_warn'], (float)$t['hold_warn'],
                    $active && $was_warn) ? 'warning' : 'info';
                $items[] = bk_rec_item('wifi_noise_high', $key, 'wifi', $sev, $subject,
                    ['band_id' => $band, 'noise' => $noise['value']]);
            }
        }

        // --- wifi_week_degraded. Only the two ENVIRONMENT metrics: client
        //     counts and link rates depend on who happened to be at home this
        //     week, and a rule built on them would call a holiday a fault.
        $key = 'wifi_week_degraded:' . $sfx;
        $noise_p = bk_rec_week_stat($p, 'wifi_noise_' . $sfx);
        $other_p = bk_rec_week_stat($p, 'wifi_busy_other_' . $sfx);
        $noise_ok = $noise['days'] >= $th['min_days'] && $noise_p['days'] >= $th['min_days']
            && $noise['value'] !== null && $noise_p['value'] !== null;
        $other_ok = $other['days'] >= $th['min_days'] && $other_p['days'] >= $th['min_days']
            && $other['value'] !== null && $other_p['value'] !== null;
        if (!$noise_ok && !$other_ok) {
            $skip[$key] = 'wifi_week_degraded';
        } else {
            $t = $th['wifi_week_degraded'];
            $changes = [];
            if ($noise_ok && ($noise['value'] - $noise_p['value']) >= $t['noise_rise']
                && $noise['value'] > $t['noise_above']) {
                $changes[] = ['what' => 'noise', 'from' => $noise_p['value'], 'to' => $noise['value']];
            }
            if ($other_ok && ($other['value'] - $other_p['value']) >= $t['busy_rise']
                && $other['value'] >= $t['busy_above']) {
                $changes[] = ['what' => 'busy_other', 'from' => $other_p['value'], 'to' => $other['value']];
            }
            if ($changes !== []) {
                $items[] = bk_rec_item('wifi_week_degraded', $key, 'wifi',
                    count($changes) > 1 ? 'warning' : 'info', $subject,
                    ['band_id' => $band, 'changes' => $changes]);
            }
        }
    }

    // --- Per radio: what is configured on this one network.
    foreach ($radios as $radio) {
        $band = is_string($radio['band'] ?? null) ? $radio['band'] : null;
        $sfx = bk_rec_band_suffix($band);
        $rk = ':r:' . bk_rec_radio_key($radio);
        $ch = isset($radio['channel']) && is_numeric($radio['channel']) ? (int)$radio['channel'] : null;
        // The interface name travels as a LABEL only (the app shows it next to
        // the item); nothing is ever keyed on it.
        $subject = ['kind' => 'radio', 'radio' => is_string($radio['radio'] ?? null) ? $radio['radio'] : null,
            'band' => $band];
        $profile = bk_wifi_radio_profile($radio);
        $enc = is_string($radio['encryption'] ?? null) ? $radio['encryption'] : null;

        // --- wifi_weak_encryption. `wpa_wpa2` is a warning of its own: a
        //     WPA2 client is not affected, but the network still accepts
        //     WPA version 1 with TKIP and must never read as "WPA2 only".
        //     `owe` is unencrypted by design and is never called weak here.
        $what = null;
        $sev = 'warning';
        if ($enc === 'wep' || $enc === 'wpa') {
            $what = 'legacy';
            $sev = 'critical';
        } elseif ($enc === 'open') {
            $what = 'open';
        } elseif ($enc === 'wpa_wpa2') {
            $what = 'mixed';
        }
        if ($what !== null) {
            $items[] = bk_rec_item('wifi_weak_encryption', 'wifi_weak_encryption' . $rk, 'wifi', $sev, $subject,
                ['band_id' => $band, 'ch' => $ch, 'what' => $what,
                    'enc' => $enc === 'wep' ? 'WEP' : 'WPA']);
        }

        // --- wifi_wpa2_only. Strict: `wpa_wpa2` belongs to the rule above,
        //     never here, and an enterprise network has no PSK to migrate.
        if ($enc === 'wpa2' && empty($radio['encryption_enterprise'])) {
            $items[] = bk_rec_item('wifi_wpa2_only', 'wifi_wpa2_only' . $rk, 'wifi', 'info', $subject,
                ['band_id' => $band, 'ch' => $ch]);
        }

        // --- wifi_mode_below_card. `bk_wifi_radio_profile` answers what the
        //     card can do ON THIS BAND, which is why the owner's 2.4 GHz
        //     adapter stays silent: its HW modes list `ac`, but VHT does not
        //     exist on 2.4 GHz, so HT really is the maximum there.
        if ($band !== null && $profile['generation'] !== null && $profile['supported_generation'] !== null
            && $profile['generation'] < $profile['supported_generation']) {
            $suggested = bk_rec_htmode($profile['supported_generation'], $profile['width_mhz']);
            if ($suggested !== null) {
                $items[] = bk_rec_item('wifi_mode_below_card', 'wifi_mode_below_card' . $rk, 'wifi',
                    $band === '2.4GHz' ? 'info' : 'warning', $subject,
                    ['band_id' => $band, 'ch' => $ch, 'cur' => $profile['generation'],
                        'best' => $profile['supported_generation'],
                        'htmode' => is_string($radio['htmode'] ?? null) ? $radio['htmode'] : null,
                        'suggested' => $suggested]);
            }
        }

        // --- wifi_channel_narrow. 5 and 6 GHz only, and only while the
        //     channel is not busy: on a crowded channel a wider one is worse
        //     advice than a narrow one. An unknown airtime does not block it
        //     (the owner's 5 GHz radio measures it, most do).
        if (($band === '5GHz' || $band === '6GHz') && $profile['width_mhz'] !== null
            && $profile['width_mhz'] < 80 && ($profile['supported_width_mhz'] ?? 0) >= 80) {
            $busy_band = $sfx !== null ? bk_rec_week_stat($w, 'wifi_busy_' . $sfx) : ['value' => null, 'days' => 0];
            $busy_val = $busy_band['days'] >= $th['min_days'] ? $busy_band['value'] : null;
            $suggested = bk_rec_htmode($profile['generation'], 80);
            if ($suggested !== null && ($busy_val === null || $busy_val < $th['wifi_channel_narrow']['busy_below'])) {
                $items[] = bk_rec_item('wifi_channel_narrow', 'wifi_channel_narrow' . $rk, 'wifi', 'info', $subject,
                    ['band_id' => $band, 'ch' => $ch, 'w' => $profile['width_mhz'], 'suggested' => $suggested]);
            }
        }

        // --- wifi_24_wide_channel. The opposite advice, and only on 2.4 GHz,
        //     where 40 MHz overlaps most of the neighbourhood.
        if ($band === '2.4GHz' && $profile['width_mhz'] !== null && $profile['width_mhz'] >= 40) {
            $suggested = bk_rec_htmode($profile['generation'], 20);
            if ($suggested !== null) {
                $items[] = bk_rec_item('wifi_24_wide_channel', 'wifi_24_wide_channel' . $rk, 'wifi', 'info', $subject,
                    ['band_id' => $band, 'ch' => $ch, 'w' => $profile['width_mhz'], 'suggested' => $suggested]);
            }
        }
    }

    // --- Router-wide: the questions no single radio can answer.
    $has_6g = false;
    $has_24g = false;
    $has_5g = false;
    $phy_6g = false;
    $capable = null;
    $known = null;
    $best = null;
    $mixed = [];
    $weak_radio = null;
    foreach ($radios as $radio) {
        $band = is_string($radio['band'] ?? null) ? $radio['band'] : null;
        $has_6g = $has_6g || $band === '6GHz';
        $has_24g = $has_24g || $band === '2.4GHz';
        $has_5g = $has_5g || $band === '5GHz';
        $phy_6g = $phy_6g || ($radio['phy_has_6ghz'] ?? null) === true;
        // A radio claiming more capable than known stations is ignored whole,
        // exactly as `bk_wifi_band_totals` does with the same pair.
        $c = $radio['clients_6ghz_capable'] ?? null;
        $k = $radio['clients_caps_known'] ?? null;
        if (is_numeric($c) && is_numeric($k) && (int)$c <= (int)$k) {
            $capable = ($capable ?? 0) + (int)$c;
            $known = ($known ?? 0) + (int)$k;
        }
        $rate = $radio['bitrate_tx_avg_mbps'] ?? null;
        if (is_numeric($rate) && (float)$rate > 0 && ($best === null || (float)$rate > $best['rate'])) {
            $best = ['rate' => (float)$rate, 'band' => $band, 'sfx' => bk_rec_band_suffix($band)];
        }
        if (($radio['encryption'] ?? null) === 'wpa2_wpa3' && empty($radio['encryption_enterprise'])) {
            $mixed[] = ['band' => $band,
                'ch' => isset($radio['channel']) && is_numeric($radio['channel']) ? (int)$radio['channel'] : null];
        }
        // The radio a weak client sits on RIGHT NOW: the weekly metric says
        // how long one was there, this says where and how weak.
        $cw = $radio['clients_weak'] ?? null;
        $sig = $radio['signal_min'] ?? null;
        if (is_numeric($cw) && (int)$cw > 0 && is_numeric($sig)
            && ($weak_radio === null || (float)$sig < $weak_radio['signal'])) {
            $weak_radio = ['signal' => (float)$sig, 'band' => $band, 'sfx' => bk_rec_band_suffix($band),
                'ch' => isset($radio['channel']) && is_numeric($radio['channel']) ? (int)$radio['channel'] : null,
                'snr' => is_numeric($radio['snr_min'] ?? null) ? (int)$radio['snr_min'] : null,
                'gen' => is_numeric($radio['weakest_gen'] ?? null) ? (int)$radio['weakest_gen'] : null];
        }
    }

    // --- wifi_6ghz_unserved. Counts only clients associated with THIS
    //     router, so when they move to another AP the share falls and the
    //     item ends by itself; a 6 GHz radio of the router's own ends it at
    //     once (the share is then 0 by construction) - which is why this is
    //     an evaluated "does not fire" and not a skip.
    $key = 'wifi_6ghz_unserved';
    $share = bk_rec_week_stat($w, 'wifi_6e_unserved');
    if ($share['days'] < $th['min_days'] || $share['value'] === null) {
        $skip[$key] = $key;
    } elseif (!$has_6g && bk_rec_over($share['value'], (float)$th[$key]['enter'], (float)$th[$key]['hold'],
            bk_rec_state_active($state, $key))) {
        $busy_best = $best !== null && $best['sfx'] !== null
            ? bk_rec_week_stat($w, 'wifi_busy_' . $best['sfx']) : ['value' => null, 'days' => 0];
        $wan = $details['wan_link_mbit'] ?? null;
        $wan = is_numeric($wan) ? (float)$wan : null;
        // The WAN sentence is allowed in exactly two shapes. Between them the
        // router knows nothing: denying a gain it cannot measure is the same
        // fault as promising one (REAL_FACTS).
        $wan_variant = null;
        if ($wan !== null && $wan >= 2500.0) {
            $wan_variant = 'fast';
        } elseif ($wan !== null && $best !== null && $wan <= 0.5 * $best['rate']) {
            $wan_variant = 'slow';
        }
        $items[] = bk_rec_item($key, $key, 'wifi', 'info', ['kind' => 'router'],
            ['hw' => $phy_6g ? 'present' : 'none',
                'capable' => $capable, 'known' => $known, 'share' => $share['value'] * 100.0,
                'band_id' => $best !== null ? $best['band'] : null,
                'rate' => $best !== null ? $best['rate'] : null,
                'busy' => $busy_best['days'] >= $th['min_days'] ? $busy_best['value'] : null,
                'wan_variant' => $wan_variant, 'wan_mbit' => $wan]);
    }

    // --- wifi_wpa3_ready. The strictest rule of the set on purpose: it
    //     invites the owner to lock out every WPA2 device he owns, so one
    //     day with a gap in the data is enough NOT to evaluate it. Six clean
    //     days do not make a clean week.
    $key = 'wifi_wpa3_ready';
    if ($mixed !== []) {
        $wpa2_days = $w['wifi_wpa2_clients'] ?? [];
        $complete = true;
        $clean = true;
        foreach ($window['days'] ?? [] as $day) {
            $row = $wpa2_days[$day] ?? null;
            if (!is_array($row) || (int)($row['samples'] ?? 0) < $th[$key]['day_samples']
                || !isset($row['max']) || !is_numeric($row['max'])) {
                $complete = false;
                break;
            }
            if ((float)$row['max'] > 0.0) {
                $clean = false;
            }
        }
        $clients = bk_rec_week_stat($w, 'wifi_clients');
        if (!$complete || $clients['max'] === null) {
            $skip[$key] = $key;
        } elseif ($clean && $clients['max'] >= 1.0) {
            $days = $window['days'] ?? [];
            $items[] = bk_rec_item($key, $key, 'wifi', 'info', ['kind' => 'router'],
                ['radios' => $mixed,
                    'from' => $days === [] ? null : (string)reset($days),
                    'to' => $days === [] ? null : (string)end($days)]);
        }
    }

    // --- wifi_weak_client. Information, not a fault, and only when the band
    //     is quiet: with noise above the bar the noise rule speaks instead,
    //     so the two can never alternate from week to week. The hold at 0.25
    //     is what keeps a client sitting exactly ON the -75 dBm boundary from
    //     appearing and disappearing every Monday (REAL_FACTS).
    $key = 'wifi_weak_client';
    $weak = bk_rec_week_stat($w, 'wifi_weak_clients');
    if ($weak['days'] < $th['min_days'] || $weak['value'] === null) {
        $skip[$key] = $key;
    } elseif (bk_rec_over($weak['value'], (float)$th[$key]['enter'], (float)$th[$key]['hold'],
            bk_rec_state_active($state, $key)) && $weak_radio !== null && $weak_radio['sfx'] !== null) {
        $band_noise = bk_rec_week_stat($w, 'wifi_noise_' . $weak_radio['sfx']);
        $noisy = false;
        foreach ($items as $item) {
            if ((string)$item['key'] === 'wifi_noise_high:' . $weak_radio['sfx']) {
                $noisy = true;
            }
        }
        // A band whose noise was never measured gets no verdict: the whole
        // point of the sentence is "the air is fine, it is the distance".
        if (!$noisy && $band_noise['days'] >= $th['min_days'] && $band_noise['value'] !== null) {
            $items[] = bk_rec_item($key, $key, 'wifi', 'info',
                ['kind' => 'radio', 'band' => $weak_radio['band']],
                ['band_id' => $weak_radio['band'], 'ch' => $weak_radio['ch'], 'signal' => $weak_radio['signal'],
                    'snr' => $weak_radio['snr'], 'noise' => $band_noise['value'],
                    // Only 0, 4 and 5 have a sentence; a Wi-Fi 6 device at the
                    // edge of the house is not held back by its generation.
                    'gen' => in_array($weak_radio['gen'], [0, 4, 5], true) ? $weak_radio['gen'] : null]);
        }
    }

    // --- wifi_5ghz_clients_on_24. Only when both bands really run: there is
    //     no "enable 5 GHz" variant, because this card cannot serve two bands
    //     at once and the advice would be impossible to follow.
    $key = 'wifi_5ghz_clients_on_24';
    if ($has_24g && $has_5g) {
        $cap24 = bk_rec_week_stat($w, 'wifi_5g_capable_24g');
        if ($cap24['days'] < $th['min_days'] || $cap24['value'] === null) {
            $skip[$key] = $key;
        } elseif (bk_rec_over($cap24['value'], (float)$th[$key]['enter'], (float)$th[$key]['hold'],
                bk_rec_state_active($state, $key))) {
            $items[] = bk_rec_item($key, $key, 'wifi', 'info', ['kind' => 'router'],
                ['n' => $cap24['value']]);
        }
    }

    return ['items' => $items, 'not_evaluated' => $skip];
}


/**
 * The WAN rules that read the WEEK (rule sheet 2.1, WAN 3.4 "static and
 * passive rules").
 *
 * Every one of them compares a weekly value with an enter / hold pair, so a
 * line that sits on the threshold does not appear and disappear from the
 * Monday e-mail. A week the router did not report is `not_evaluated`, never
 * "nothing found": `bk_router_rec_state_save` then leaves the row alone
 * instead of clearing it and mailing it again as new.
 *
 * @return array{items: list<array<string, mixed>>, not_evaluated: array<string, string>}
 */
function bk_rec_rules_wan_week(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $skip = [];
    $window = is_array($in['window'] ?? null) ? $in['window'] : ['days' => [], 'metrics' => []];
    $details = is_array($in['details'] ?? null) ? $in['details'] : [];
    $monitor = is_array($in['monitor'] ?? null) ? $in['monitor'] : [];
    $enough = bk_rec_days_with_data($window) >= $th['min_days'];
    $dev = is_string($details['wan_link_dev'] ?? null) ? $details['wan_link_dev'] : null;
    $plan_down = isset($monitor['wan_plan_down_mbit']) && is_numeric($monitor['wan_plan_down_mbit'])
        ? (float)$monitor['wan_plan_down_mbit'] : null;

    // R-W6. A day counts when even its BEST minute was below the plan: the
    // daily rollup has no share of samples, and X13 keeps the raw scans for
    // two other rules.
    $key = 'wan_link_below_plan';
    if (!$enough || $plan_down === null) {
        $skip[$key] = $key;
    } else {
        $below = 0;
        $seen = 0;
        $worst = null;
        foreach ($window['days'] as $day) {
            $row = $window['metrics']['wan_link_mbit'][$day] ?? null;
            if (!is_array($row) || !isset($row['max']) || !is_numeric($row['max'])) {
                continue;
            }
            $seen++;
            if ((float)$row['max'] < $plan_down) {
                $below++;
                $worst = $worst === null ? (float)$row['max'] : min($worst, (float)$row['max']);
            }
        }
        if ($seen === 0) {
            $skip[$key] = $key;
        } elseif (bk_rec_over((float)$below, (float)$th[$key]['enter'], (float)$th[$key]['hold'],
                bk_rec_state_active($state, $key))) {
            $items[] = bk_rec_item($key, $key, 'wan', 'warning', ['kind' => 'wan'],
                ['name' => $dev, 'mbit' => $worst, 'plan' => $plan_down, 'days' => $below]);
        }
    }

    // R-W9. Two independent branches: receive errors, and a receive queue that
    // overflowed. The sysfs drop counter is evidence only - on mvneta it
    // counts junk frames of protocols nobody asked for (WAN 3.1.3).
    $key = 'wan_port_errors';
    if (!$enough) {
        $skip[$key] = $key;
    } else {
        $errors = bk_rec_week_stat($window['metrics'] ?? [], 'wan_errors');
        $drops = bk_rec_week_stat($window['metrics'] ?? [], 'wan_drops');
        $ring = bk_rec_week_stat($window['metrics'] ?? [], 'wan_ring_drops');
        $active = bk_rec_state_active($state, $key);
        $err_days = bk_rec_days_over($errors, 0.0);
        $err_hit = $errors['sum'] !== null && ($active
            ? ($errors['sum'] >= $th[$key]['hold'] && $err_days >= $th[$key]['hold_days'])
            : ($errors['sum'] >= $th[$key]['enter'] && $err_days >= $th[$key]['enter_days']));
        // The share needs the week's received packets; without them the ring
        // branch cannot fire (a count without a denominator is not a share).
        $rx = 0.0;
        foreach (is_array($in['rx_packets'] ?? null) ? $in['rx_packets'] : [] as $n) {
            $rx += is_numeric($n) ? (float)$n : 0.0;
        }
        $ring_days = bk_rec_days_over($ring, 0.0);
        $ring_share = ($ring['sum'] !== null && $rx > 0.0) ? $ring['sum'] / $rx * 100.0 : null;
        $ring_hit = $ring_share !== null && ($active
            ? ($ring_share >= $th[$key]['ring_hold'] && $ring_days >= $th[$key]['ring_hold_days'])
            : ($ring_share >= $th[$key]['ring_enter'] && $ring_days >= $th[$key]['ring_enter_days']));
        if ($errors['sum'] === null && $ring['sum'] === null) {
            $skip[$key] = $key;
        } elseif ($err_hit || $ring_hit) {
            $items[] = bk_rec_item($key, $key, 'wan', 'warning', ['kind' => 'wan'],
                ['name' => $dev, 'errors' => $errors['sum'], 'drops' => $drops['sum'],
                    'days' => max($err_days, $ring_days),
                    // Only a ring counter that really grew may claim "could not
                    // keep up"; null is not zero and gets no sentence at all.
                    'ring' => ($ring['sum'] !== null && $ring['sum'] > 0.0) ? $ring['sum'] : null]);
        }
    }

    // R-W10. The step is null across a reboot and across a device change
    // (`bk_counter_step`), which IS the condition "while the router did not
    // restart" - no separate uptime test is needed here.
    $key = 'wan_link_flaps';
    $flaps = bk_rec_week_stat($window['metrics'] ?? [], 'wan_link_flaps');
    if (!$enough || $flaps['sum'] === null) {
        $skip[$key] = $key;
    } elseif (bk_rec_over($flaps['sum'], (float)$th[$key]['enter'], (float)$th[$key]['hold'],
            bk_rec_state_active($state, $key))) {
        $items[] = bk_rec_item($key, $key, 'wan', 'warning', ['kind' => 'wan'],
            ['name' => $dev, 'flaps' => $flaps['sum']]);
    }

    return ['items' => $items, 'not_evaluated' => $skip];
}


/**
 * The WAN rules that read the aggregate verdict, the raw-minute scans and the
 * router's own configuration (rule sheet 2.1, WAN 3.4-3.5).
 *
 * The four aggregate rules do NOT get an enter / hold pair: the aggregate is
 * flap-safe by construction ("2 of 3 agree", WAN 3.4), and a second threshold
 * on top of it would only hide a verdict that already survived four guards.
 *
 * @return array{items: list<array<string, mixed>>, not_evaluated: array<string, string>}
 */
function bk_rec_rules_wan_tests(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $skip = [];
    $details = is_array($in['details'] ?? null) ? $in['details'] : [];
    $monitor = is_array($in['monitor'] ?? null) ? $in['monitor'] : [];
    $path = is_array($details['wan_path'] ?? null) ? $details['wan_path'] : [];
    $window = is_array($in['window'] ?? null) ? $in['window'] : ['days' => [], 'metrics' => []];
    $enough = bk_rec_days_with_data($window) >= $th['min_days'];
    $dev = is_string($details['wan_link_dev'] ?? null) ? $details['wan_link_dev'] : null;
    $num = fn ($v): ?float => is_numeric($v) ? (float)$v : null;
    $plan_down = $num($monitor['wan_plan_down_mbit'] ?? null);

    $ctx = [
        'plan_down' => $plan_down === null ? null : (int)$plan_down,
        'plan_up' => ($v = $num($monitor['wan_plan_up_mbit'] ?? null)) === null ? null : (int)$v,
        'plan_ok_pct' => ($v = $num($monitor['wan_plan_ok_pct'] ?? null)) === null ? null : (int)$v,
        'threaded_napi' => is_bool($path['wan_threaded_napi'] ?? null) ? $path['wan_threaded_napi'] : null,
        'server_max' => is_array($in['server_max'] ?? null) ? $in['server_max'] : [],
        'now' => (int)($in['now'] ?? time()),
    ];
    $tests = is_array($in['speedtests'] ?? null) ? $in['speedtests'] : [];
    $verdict = bk_wan_bottleneck($tests, $ctx);

    // R-W7 first: it is the passive evidence that turns R-W1 from a page note
    // into a digest warning (WAN 3.4 "what packet_path does not mean").
    $key = 'wan_forwarding_core_saturated';
    $minutes = $in['forwarding_minutes'] ?? null;
    if (!$enough) {
        $skip[$key] = $key;
    } else {
        // A router the pre-filter skipped never had a minute at 95 %, so zero
        // is measured here, not assumed (X13).
        $minutes = $minutes === null ? 0 : (int)$minutes;
        if (bk_rec_over((float)$minutes, (float)$th[$key]['enter'], (float)$th[$key]['hold'],
                bk_rec_state_active($state, $key))) {
            $items[] = bk_rec_item($key, $key, 'wan', 'warning', ['kind' => 'wan'],
                ['minutes' => $minutes, 'softirq' => $th[$key]['softirq_pct'], 'mbps' => $th[$key]['wan_mbps'],
                    'steering_off' => ($path['packet_steering_active'] ?? null) === false,
                    // The offloading sentence belongs to THIS rule only: it does
                    // nothing for a test the router terminates itself.
                    'offloading_off' => ($path['flow_offloading'] ?? null) === false]);
        }
    }
    $forwarding_now = false;
    foreach ($items as $item) {
        $forwarding_now = $forwarding_now || $item['id'] === 'wan_forwarding_core_saturated';
    }
    // "the same or the previous week": the saved row still stands from the
    // last digest, so an active state counts as the previous week's match.
    $forwarding_seen = $forwarding_now || bk_rec_state_active($state, 'wan_forwarding_core_saturated');

    foreach (['dl', 'ul'] as $dir) {
        $v = is_array($verdict[$dir] ?? null) ? $verdict[$dir] : [];
        $class = (string)($v['class'] ?? 'inconclusive');
        $reason = (string)($v['reason'] ?? '');
        $n = is_array($v['numbers'] ?? null) ? $v['numbers'] : [];
        $common = ['dir' => $dir, 'mbps' => $n['s_mbps'] ?? null, 'agree' => $n['agree'] ?? null,
            'span_days' => $n['span_days'] ?? null, 'servers' => $n['servers'] ?? null,
            'name' => $dev, 'link' => $num($details['wan_link_mbit'] ?? null)];
        if ($class === 'line_limited' && $reason !== '') {
            $plan = $dir === 'dl' ? $ctx['plan_down'] : $ctx['plan_up'];
            $items[] = bk_rec_item('wan_line_below_plan', 'wan_line_below_plan:' . $dir, 'wan', 'warning',
                ['kind' => 'wan', 'direction' => $dir],
                $common + ['plan' => $plan, 'core' => $n['core_busy_pct'] ?? null,
                    'ok_pct' => $ctx['plan_ok_pct'] ?? 85]);
        } elseif ($class === 'link_limited' && $reason === 'wan_port') {
            $items[] = bk_rec_item('wan_port_limited', 'wan_port_limited:' . $dir, 'wan', 'warning',
                ['kind' => 'wan', 'direction' => $dir], $common);
        } elseif ($class === 'link_limited' && $reason === 'sqm_shaper') {
            // Only a shaper set WELL below the plan is worth a sentence: one at
            // 95 % of the plan is the shaper doing its job.
            $sqm = null;
            foreach (is_array($path['sqm'] ?? null) ? $path['sqm'] : [] as $queue) {
                $kbps = $queue[$dir === 'dl' ? 'download_kbps' : 'upload_kbps'] ?? null;
                if (is_numeric($kbps) && (float)$kbps > 0) {
                    $sqm = $sqm === null ? (float)$kbps / 1000.0 : min($sqm, (float)$kbps / 1000.0);
                }
            }
            $plan = $dir === 'dl' ? $ctx['plan_down'] : $ctx['plan_up'];
            if ($sqm !== null && $plan !== null && $sqm < $th['wan_sqm_limited']['below_plan'] * $plan) {
                $items[] = bk_rec_item('wan_sqm_limited', 'wan_sqm_limited:' . $dir, 'wan', 'info',
                    ['kind' => 'wan', 'direction' => $dir], $common + ['sqm' => $sqm, 'plan' => $plan]);
            }
        } elseif ($class === 'cpu_limited' && $reason === 'packet_path') {
            // X12: info and page-only on its own; a warning that reaches the
            // digest only when ordinary forwarded traffic saturated the same
            // core this week or last.
            $items[] = bk_rec_item('wan_cpu_packet_path', 'wan_cpu_packet_path:' . $dir, 'wan',
                $forwarding_seen ? 'warning' : 'info', ['kind' => 'wan', 'direction' => $dir],
                $common + ['core' => $n['core'] ?? null, 'core_busy' => $n['core_busy_pct'] ?? null,
                    'net_share' => $n['net_share_pct'] ?? null, 'all_cores' => $n['all_cores_avg_pct'] ?? null,
                    'tests' => $n['tests'] ?? null, 'server' => $n['server'] ?? null,
                    'with_forwarding' => $forwarding_seen,
                    'steering_off' => ($path['packet_steering_active'] ?? null) === false],
                !$forwarding_seen);
        }
    }

    // R-W11. Only drops measured in a minute whose own table was >= 90 % full
    // reach the rule - a clash race in a half-empty table is not a refusal.
    $key = 'conntrack_drops';
    $days = $in['conntrack_days'] ?? null;
    if (!$enough) {
        $skip[$key] = $key;
    } else {
        $days = is_array($days) ? $days : [];
        $sum = 0.0;
        $pct = null;
        foreach ($days as $row) {
            $sum += (float)($row['drops'] ?? 0);
            $pct = $pct === null ? (float)($row['pct'] ?? 0) : max($pct, (float)($row['pct'] ?? 0));
        }
        if (bk_rec_over((float)count($days), (float)$th[$key]['enter'], (float)$th[$key]['hold'],
                bk_rec_state_active($state, $key))) {
            // WAN 3.4's evidence row. `early_drop` counts entries the kernel
            // successfully evicted to make room - nothing was refused - so it
            // never enters the count above, only the sentence under it. It is
            // cumulative since boot (its label says so), and null when the
            // router did not report it: an unmeasured counter prints no line
            // rather than a zero that would read as "nothing was evicted".
            $items[] = bk_rec_item($key, $key, 'wan', 'warning', ['kind' => 'wan'],
                ['drops' => $sum, 'pct' => $pct, 'days' => count($days),
                    'evicted' => $num($details['conntrack_early_drop'] ?? null)]);
        }
    }

    // R-W8. A configuration statement, not a measurement: it needs the port
    // CAPABILITY, never the rate the ports happen to be linked at today.
    $cap = $path['lan_port_cap_mbit'] ?? null;
    $best = null;
    foreach (['dl', 'ul'] as $dir) {
        $s = $verdict[$dir]['numbers']['s_max_mbps'] ?? null;
        if (is_numeric($s)) {
            $best = $best === null ? (float)$s : max($best, (float)$s);
        }
    }
    // The evidence, when the switch reported it (agent 0.1.8): the conduit the
    // wired ports really share and how many devices are behind it. It does not
    // decide whether the rule fires - it only turns "your ports are gigabit"
    // into the household's own numbers. The SLOWEST linked conduit is the
    // ceiling; an unlinked one carries nothing and is left out.
    $lan = is_array($details['lan_ports'] ?? null) ? $details['lan_ports'] : [];
    $conduit = null;
    foreach (is_array($lan['conduits'] ?? null) ? $lan['conduits'] : [] as $c) {
        $rate = is_array($c) && is_numeric($c['speed_mbit'] ?? null) ? (float)$c['speed_mbit'] : null;
        if ($rate !== null && ($c['link'] ?? null) === true) {
            $conduit = $conduit === null ? $rate : min($conduit, $rate);
        }
    }
    // One device does not share anything, so the sentence is only true from
    // two upwards - and a count the agent could not take stays out entirely.
    $wired = bk_ranged_int($lan['clients_total'] ?? null, 2, 65535);
    if (is_numeric($cap) && (float)$cap <= $th['lan_wired_ceiling']['cap_at_most']
        && (($plan_down !== null && $plan_down > 1000.0) || ($best !== null && $best > 1000.0))) {
        $items[] = bk_rec_item('lan_wired_ceiling', 'lan_wired_ceiling', 'wan', 'info', ['kind' => 'wan'],
            ['plan' => $plan_down, 'cap' => (float)$cap, 'conduit' => $conduit, 'clients' => $wired]);
    }

    return ['items' => $items, 'not_evaluated' => $skip];
}


/**
 * Security, system and package rules (rule sheet 2.1, WAN 4.1 B).
 *
 * Three of them are CONFIGURATION rules: they read what the router reports
 * right now, not a weekly average, so they still fire on a router with three
 * days of history (CORE 7.4 V11). The two event rules and the clock read the
 * week and follow the same enter / hold discipline as every other rule.
 *
 * @return array{items: list<array<string, mixed>>, not_evaluated: array<string, string>}
 */
function bk_rec_rules_gap(array $in, array $state): array {
    $th = bk_router_rec_thresholds();
    $items = [];
    $skip = [];
    $details = is_array($in['details'] ?? null) ? $in['details'] : [];
    $monitor = is_array($in['monitor'] ?? null) ? $in['monitor'] : [];
    $window = is_array($in['window'] ?? null) ? $in['window'] : ['days' => [], 'metrics' => []];
    $events = is_array($in['events'] ?? null) ? $in['events'] : [];
    $enough = bk_rec_days_with_data($window) >= $th['min_days'];
    $tools = is_array($details['agent_tools'] ?? null) ? $details['agent_tools'] : null;

    // R-F1. Never for a device without a WAN role: a dumb AP has no firewall
    // to load and would carry a critical item for ever (G20).
    $since = $details['firewall_off_since'] ?? null;
    if (is_numeric($since) && (int)$since > 0 && ($details['wan_up'] ?? null) !== null) {
        $items[] = bk_rec_item('firewall_off', 'firewall_off', 'security', 'critical', ['kind' => 'router'],
            ['since' => (int)$since]);
    }

    // R-S1. The cause is not claimed anywhere: the router does not record it.
    $key = 'router_restarts';
    $reboots = $events['router_rebooted'] ?? [];
    $count = 0;
    $last = null;
    foreach ($reboots as $row) {
        $count += (int)($row['n'] ?? 0);
        $at = $row['last_at'] ?? null;
        $last = ($last === null || (is_string($at) && $at > $last)) ? $at : $last;
    }
    if (!$enough) {
        $skip[$key] = $key;
    } elseif (bk_rec_over((float)$count, (float)$th[$key]['enter'], (float)$th[$key]['hold'],
            bk_rec_state_active($state, $key))) {
        // The disk sentence is appended only when CORE's unclean-power-off item
        // really stands for this router and is not muted - a router without a
        // disk must not be pointed at a recommendation that does not exist.
        $unclean = false;
        foreach ($state as $skey => $srow) {
            if (str_starts_with((string)$skey, 'disk_unclean_shutdowns:') && !empty($srow['active'])
                && empty($srow['muted_at'])) {
                $unclean = true;
            }
        }
        $items[] = bk_rec_item($key, $key, 'system', 'warning', ['kind' => 'router'],
            ['count' => $count, 'last_at' => $last, 'unclean' => $unclean]);
    }

    // R-D1. Distinct DAYS, and the second number is the count of outages, not
    // minutes: minutes would need failed/restored pairs and a pair cut by the
    // week boundary would invent a number (2.1 decision 5).
    $key = 'dns_resolver_failing';
    $dns = $events['dns_resolver_failed'] ?? [];
    $dns_days = count($dns);
    $dns_count = 0;
    foreach ($dns as $row) {
        $dns_count += (int)($row['n'] ?? 0);
    }
    if (!$enough) {
        $skip[$key] = $key;
    } elseif (bk_rec_over((float)$dns_days, (float)$th[$key]['enter'], (float)$th[$key]['hold'],
            bk_rec_state_active($state, $key))) {
        $items[] = bk_rec_item($key, $key, 'system', 'warning', ['kind' => 'router'],
            ['days' => $dns_days, 'count' => $dns_count]);
    }

    // R-S2. The column stores the ABSOLUTE skew (2.1 decision 3), so a
    // weighted mean of it is a real average distance, not a signed one that
    // would cancel itself out over a week.
    $key = 'clock_skew';
    $skew = bk_rec_week_stat($window['metrics'] ?? [], 'clock_skew_s');
    if (!$enough || $skew['value'] === null) {
        $skip[$key] = $key;
    } elseif (bk_rec_above($skew['value'], (float)$th[$key]['enter'], (float)$th[$key]['hold'],
            bk_rec_state_active($state, $key))) {
        $items[] = bk_rec_item($key, $key, 'system', 'warning', ['kind' => 'router'],
            ['secs' => $skew['value']]);
    }

    // Packages. `agent_tools` carries strict booleans (X4), so `false` means
    // "looked and did not find it"; a tool the agent could not test at all is
    // absent from the object and no rule fires.
    $pkg_manager = is_string($details['pkg_manager'] ?? null) ? $details['pkg_manager'] : null;
    $pkg_rules = [
        'pkg_smartmontools' => ['smartctl', 'smartmontools'],
        'pkg_smart_drivedb' => ['smart_drivedb', 'smartmontools-drivedb'],
        'pkg_hostapd_utils' => ['hostapd_cli', 'hostapd-utils'],
        'pkg_iw' => ['iw', 'iw'],
    ];
    foreach ($pkg_rules as $id => [$flag, $pkg]) {
        if ($tools !== null && ($tools[$flag] ?? null) === false) {
            $items[] = bk_rec_item($id, $id, 'packages', 'info', ['kind' => 'router'],
                ['pkg' => $pkg, 'pkg_manager' => $pkg_manager]);
        }
    }
    // X4: the librespeed client is only missing for a router whose owner asked
    // for the probe. On every other router it is simply not installed.
    if ($tools !== null && ($tools['librespeed_cli'] ?? null) === false
        && !empty($monitor['wan_probe_enabled'])) {
        $items[] = bk_rec_item('pkg_librespeed_cli', 'pkg_librespeed_cli', 'packages', 'info',
            ['kind' => 'router'], ['pkg' => 'librespeed-cli', 'pkg_manager' => $pkg_manager]);
    }

    return ['items' => $items, 'not_evaluated' => $skip];
}

/** On how many days of the week did this metric's daily total exceed `$over`? */
function bk_rec_days_over(array $stat, float $over): int {
    $days = 0;
    foreach ($stat['daily'] ?? [] as $row) {
        if (isset($row['sum']) && (float)$row['sum'] > $over) {
            $days++;
        }
    }
    return $days;
}

/**
 * The two windows of a weekly evaluation.
 *
 * W = the 7 complete days BEFORE `$end_day` (the digest and the live page both
 * pass today, so the page shows exactly what the last e-mail said), P = the 7
 * days before W, and a 30-day list for the rules that measure a trend (disk
 * wear). Days are 'Y-m-d', oldest first.
 */
function bk_router_rec_window(string $end_day): array {
    $end = strtotime($end_day . ' 00:00:00');
    if ($end === false) {
        $end = strtotime(date('Y-m-d') . ' 00:00:00');
    }
    $day = fn (int $back): string => date('Y-m-d', $end - $back * 86400);
    $days = $prev = $days30 = [];
    for ($i = 7; $i >= 1; $i--) {
        $days[] = $day($i);
    }
    for ($i = 14; $i >= 8; $i--) {
        $prev[] = $day($i);
    }
    for ($i = 30; $i >= 1; $i--) {
        $days30[] = $day($i);
    }
    return ['days' => $days, 'prev_days' => $prev, 'days30' => $days30, 'metrics' => []];
}

/**
 * Everything the rules of one WEEK need, for many routers at once.
 *
 * Three indexed queries for the whole chunk instead of three per router: the
 * digest evaluates every router it has (sorting by worst severity and the
 * state save need all of them), and `send_digest_report_inner` builds the data
 * again on every cron run between 08:00 and 12:00 until one send succeeds. At
 * 1,000 routers the per-router shape would be 3,000 queries every minute of
 * that window.
 *
 * The result is keyed by monitor id and holds, per router:
 *   'window' => ['days','prev_days','days30','metrics' => [key => [day => row]]]
 *   'disks'  => [disk_key => ['row' => storage_disks row, 'daily' => [day => row]]]
 *   'state'  => [rec_key => router_rec_state row]
 * `details`, `monitor` and `now` are added by the caller, which already holds
 * them (the digest reads `last_details` in its own chunked query).
 */
function bk_router_rec_inputs_batch(PDO $pdo, array $monitor_ids, string $end_day): array {
    $ids = [];
    foreach ($monitor_ids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return [];
    }
    $window = bk_router_rec_window($end_day);
    $out = [];
    foreach ($ids as $id) {
        $out[$id] = ['window' => $window, 'disks' => [], 'state' => [], 'speedtests' => [],
            'events' => [], 'rx_packets' => [], 'forwarding_minutes' => null, 'conntrack_days' => null, 'server_max' => []];
    }
    $in_list = implode(',', array_fill(0, count($ids), '?'));
    $id_list = array_values($ids);

    // 1. Daily metrics. The window is 30 days because the wear trend reads
    //    that far back; W and P are slices of the same rows.
    $stmt = $pdo->prepare(
        "SELECT monitor_id, metric_key, DATE_FORMAT(day, '%Y-%m-%d') AS day, min_val, avg_val, max_val, samples
           FROM metrics_daily
          WHERE monitor_id IN ($in_list) AND day BETWEEN ? AND ?"
    );
    $stmt->execute(array_merge($id_list, [$window['days30'][0], end($window['days30'])]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mid = (int)$row['monitor_id'];
        $out[$mid]['window']['metrics'][$row['metric_key']][$row['day']] = [
            'min' => $row['min_val'] === null ? null : (float)$row['min_val'],
            'avg' => $row['avg_val'] === null ? null : (float)$row['avg_val'],
            'max' => $row['max_val'] === null ? null : (float)$row['max_val'],
            'samples' => (int)$row['samples'],
        ];
    }

    // 2. Disks and their daily rows in ONE join: a disk with no daily row in
    //    the window still has to be known (a newly plugged drive), so the join
    //    is a LEFT one and the identity row survives an empty history.
    $stmt = $pdo->prepare(
        "SELECT s.monitor_id, s.disk_key, s.id AS disk_id, s.name, s.transport, s.model, s.smart_model,
                s.size_bytes, s.rotational, s.replaced_at, s.first_seen, s.last_seen,
                DATE_FORMAT(d.day, '%Y-%m-%d') AS day, d.samples, d.smart_passed, d.temp_min, d.temp_max,
                d.temp_sum, d.temp_n, d.power_on_hours, d.power_cycles, d.unsafe_shutdowns,
                d.reallocated_sectors, d.pending_sectors, d.offline_uncorrectable, d.reported_uncorrect,
                d.crc_errors, d.runtime_bad_blocks, d.media_errors, d.error_log_count, d.wear_pct,
                d.emmc_life, d.written_bytes, d.host_written_bytes, d.host_written_partial
           FROM storage_disks s
           LEFT JOIN storage_disk_daily d ON d.disk_id = s.id AND d.day BETWEEN ? AND ?
          WHERE s.monitor_id IN ($in_list)"
    );
    $stmt->execute(array_merge([$window['days30'][0], end($window['days30'])], $id_list));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mid = (int)$row['monitor_id'];
        $dk = (string)$row['disk_key'];
        if (!isset($out[$mid]['disks'][$dk])) {
            $out[$mid]['disks'][$dk] = ['row' => [
                'id' => (int)$row['disk_id'], 'key' => $dk, 'name' => $row['name'],
                'transport' => $row['transport'], 'model' => $row['model'], 'smart_model' => $row['smart_model'],
                'size_bytes' => $row['size_bytes'] === null ? null : (int)$row['size_bytes'],
                'rotational' => $row['rotational'] === null ? null : (bool)$row['rotational'],
                'replaced_at' => $row['replaced_at'], 'first_seen' => $row['first_seen'], 'last_seen' => $row['last_seen'],
            ], 'daily' => []];
        }
        if ($row['day'] === null) {
            continue;
        }
        $day = ['samples' => (int)$row['samples'], 'host_written_partial' => (int)$row['host_written_partial']];
        foreach (['smart_passed', 'temp_min', 'temp_max', 'temp_sum', 'temp_n', 'power_on_hours', 'power_cycles',
                  'unsafe_shutdowns', 'reallocated_sectors', 'pending_sectors', 'offline_uncorrectable',
                  'reported_uncorrect', 'crc_errors', 'runtime_bad_blocks', 'media_errors', 'error_log_count',
                  'wear_pct', 'emmc_life', 'written_bytes', 'host_written_bytes'] as $col) {
            // A missing reading stays null: a zero here would be a measurement
            // nobody took, and these columns feed "did the counter grow".
            $day[$col] = $row[$col] === null ? null : $row[$col] + 0;
        }
        $out[$mid]['disks'][$dk]['daily'][(string)$row['day']] = $day;
    }

    // 3. The saved evaluation: hysteresis, mutes and the two digest weeks.
    $stmt = $pdo->prepare("SELECT * FROM router_rec_state WHERE monitor_id IN ($in_list)");
    $stmt->execute($id_list);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mid = (int)$row['monitor_id'];
        $row['active'] = (int)$row['active'];
        $out[$mid]['state'][(string)$row['rec_key']] = $row;
    }

    // 4. The agent's own speed tests of the last 21 days - the input of the
    //    four aggregate rules. Turris-started rows travel too: the classifier
    //    itself decides they may only ever confirm a reached plan (WAN 3.4).
    $stmt = $pdo->prepare(
        "SELECT id, monitor_id, measured_at, download_mbps, upload_mbps, link_mbit, source,
                server_name, diagnostics
           FROM speedtest_results
          WHERE monitor_id IN ($in_list) AND measured_at >= (NOW() - INTERVAL 21 DAY)
          ORDER BY measured_at DESC"
    );
    $stmt->execute($id_list);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['monitor_id']]['speedtests'][] = $row;
    }

    // 4b. What those test servers have ever delivered, to ANY router, in 90
    //     days. Server capability is observed, never assumed (WAN 3.4): only
    //     the maximum leaves the query, no other account's router and no
    //     other account's value.
    $names = [];
    foreach ($ids as $id) {
        foreach ($out[$id]['speedtests'] as $row) {
            if (($row['server_name'] ?? '') !== '') {
                $names[(string)$row['server_name']] = true;
            }
        }
    }
    if ($names !== []) {
        $name_list = implode(',', array_fill(0, count($names), '?'));
        $stmt = $pdo->prepare(
            "SELECT server_name, MAX(download_mbps) AS dl, MAX(upload_mbps) AS ul
               FROM speedtest_results
              WHERE server_name IN ($name_list) AND measured_at >= (NOW() - INTERVAL 90 DAY)
              GROUP BY server_name"
        );
        $stmt->execute(array_keys($names));
        $server_max = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $server_max[(string)$row['server_name']] = [
                'dl' => $row['dl'] === null ? null : (float)$row['dl'],
                'ul' => $row['ul'] === null ? null : (float)$row['ul'],
            ];
        }
        foreach ($ids as $id) {
            $out[$id]['server_max'] = $server_max;
        }
    }

    // 5. The week's events, counted per type and day in ONE grouped query
    //    (2.1 decision 6): `router_restarts` needs the count, and
    //    `dns_resolver_failing` the number of DISTINCT days - minutes would
    //    need failed/restored pairs and a pair cut by the week boundary would
    //    invent a number.
    $stmt = $pdo->prepare(
        "SELECT monitor_id, event_type, DATE(occurred_at) AS day, COUNT(*) AS n,
                MAX(occurred_at) AS last_at
           FROM monitor_events
          WHERE monitor_id IN ($in_list)
            AND event_type IN ('router_rebooted', 'dns_resolver_failed')
            AND occurred_at >= ? AND occurred_at < (? + INTERVAL 1 DAY)
          GROUP BY monitor_id, event_type, DATE(occurred_at)"
    );
    $stmt->execute(array_merge($id_list, [$window['days'][0] . ' 00:00:00', end($window['days'])]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['monitor_id']]['events'][(string)$row['event_type']][(string)$row['day']] =
            ['n' => (int)$row['n'], 'last_at' => $row['last_at']];
    }

    // 6. Received packets of the week, per day - the denominator of the ring
    //    branch of `wan_port_errors`. Summed over every interface: the WAN
    //    device name of a week ago is not knowable here, and a share of the
    //    router's whole traffic is the conservative direction (it can only
    //    make the share smaller, never raise a false alarm).
    $stmt = $pdo->prepare(
        "SELECT monitor_id, DATE_FORMAT(date, '%Y-%m-%d') AS day, SUM(rx_packets_total) AS rx
           FROM monitor_interface_traffic
          WHERE monitor_id IN ($in_list) AND date BETWEEN ? AND ?
          GROUP BY monitor_id, date"
    );
    $stmt->execute(array_merge($id_list, [$window['days'][0], end($window['days'])]));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['monitor_id']]['rx_packets'][(string)$row['day']] =
            $row['rx'] === null ? null : (float)$row['rx'];
    }

    // 7. The two raw-minute scans of X13, and ONLY for a router whose loaded
    //    daily week already shows the peak they look for. A chunk of fifty
    //    quiet routers issues neither query; a router that never saturated a
    //    core cannot have ten such minutes, so the pre-filter cannot hide a
    //    rule that would have fired.
    $th = bk_router_rec_thresholds();
    $w_from = $window['days'][0] . ' 00:00:00';
    $w_to = end($window['days']) . ' 23:59:59';
    foreach ($ids as $id) {
        $metrics = $out[$id]['window']['metrics'];
        $peak = function (string $key) use ($metrics): ?float {
            $max = null;
            foreach ($metrics[$key] ?? [] as $row) {
                if (isset($row['max']) && is_numeric($row['max'])) {
                    $max = $max === null ? (float)$row['max'] : max($max, (float)$row['max']);
                }
            }
            return $max;
        };
        $core_peak = $peak('cpu_core_max');
        if ($core_peak !== null && $core_peak >= $th['wan_forwarding_core_saturated']['core_pct']) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM vps_metrics
                  WHERE monitor_id = ? AND created_at BETWEEN ? AND ?
                    AND cpu_core_max >= ? AND cpu_core_max_softirq >= ?
                    AND (COALESCE(wan_rx_mbps, 0) + COALESCE(wan_tx_mbps, 0)) >= ?"
            );
            $stmt->execute([$id, $w_from, $w_to, $th['wan_forwarding_core_saturated']['core_pct'],
                $th['wan_forwarding_core_saturated']['softirq_pct'],
                $th['wan_forwarding_core_saturated']['wan_mbps']]);
            $out[$id]['forwarding_minutes'] = (int)$stmt->fetchColumn();
        }
        $ct_peak = $peak('conntrack_pct');
        if ($ct_peak !== null && $ct_peak >= $th['conntrack_drops']['full_pct']) {
            $stmt = $pdo->prepare(
                "SELECT DATE(created_at) AS day, SUM(conntrack_drops) AS drops, MAX(conntrack_pct) AS pct
                   FROM vps_metrics
                  WHERE monitor_id = ? AND created_at BETWEEN ? AND ?
                    AND conntrack_pct >= ? AND conntrack_drops > 0
                  GROUP BY DATE(created_at)"
            );
            $stmt->execute([$id, $w_from, $w_to, $th['conntrack_drops']['full_pct']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[$id]['conntrack_days'][(string)$row['day']] =
                    ['drops' => (float)$row['drops'], 'pct' => (float)$row['pct']];
            }
        }
    }

    return $out;
}

/**
 * The same inputs for ONE router - the live page's entry point.
 *
 * It calls the batch with a single id on purpose: the page and the weekly
 * e-mail then read the same SQL and cannot drift apart.
 */
function bk_router_rec_inputs(PDO $pdo, array $monitor, array $details, string $end_day): array {
    $id = (int)($monitor['id'] ?? 0);
    $batch = bk_router_rec_inputs_batch($pdo, [$id], $end_day);
    $in = $batch[$id] ?? ['window' => bk_router_rec_window($end_day), 'disks' => [], 'state' => []];
    $in['monitor'] = $monitor;
    $in['details'] = $details;
    $in['now'] = time();
    return $in;
}

/**
 * How many of the week's days the router really reported.
 *
 * A day counts from `day_samples` samples of any metric - the rules that read
 * `metrics_daily` use the same bar, and the digest prints this number when it
 * is below four ("not enough data for a weekly assessment").
 */
function bk_rec_days_with_data(array $window): int {
    $th = bk_router_rec_thresholds();
    $per_day = [];
    foreach ($window['metrics'] ?? [] as $days) {
        foreach ($days as $day => $row) {
            $n = (int)($row['samples'] ?? 0);
            $per_day[$day] = max($per_day[$day] ?? 0, $n);
        }
    }
    $count = 0;
    foreach ($window['days'] ?? [] as $day) {
        if (($per_day[$day] ?? 0) >= $th['day_samples']) {
            $count++;
        }
    }
    return $count;
}

/**
 * The weekly evaluation of one router. PURE - everything it reads is in `$in`.
 *
 * Returns the language-neutral items, sorted the way both the e-mail and the
 * page show them (severity, area, the rule's own rank, key), plus the keys
 * that could NOT be evaluated this week. Those two lists are what the state
 * save needs: an item that stopped firing clears its row, an item that was
 * never evaluated (the router was off) must leave its row alone, or the
 * digest would mail it as new when the data comes back.
 */
function bk_router_rec_evaluate(array $in): array {
    $monitor = is_array($in['monitor'] ?? null) ? $in['monitor'] : [];
    $details = is_array($in['details'] ?? null) ? $in['details'] : [];
    $state = is_array($in['state'] ?? null) ? $in['state'] : [];
    $now = (int)($in['now'] ?? time());
    $window = is_array($in['window'] ?? null) ? $in['window'] : ['days' => [], 'metrics' => []];
    $days_with_data = bk_rec_days_with_data($window);
    $out = ['applicable' => false, 'reason' => null, 'days_with_data' => $days_with_data,
        'items' => [], 'not_evaluated' => []];

    if ((string)($monitor['type'] ?? '') !== 'openwrt') {
        $out['reason'] = 'not_router';
        return $out;
    }
    // The router rules read fields only 0.1.7 sends. An older agent is not a
    // healthy router with nothing to report, and saying so is the difference
    // between "nothing found" and "nothing measured".
    $version = $details['agent_version'] ?? ($monitor['agent_version'] ?? null);
    if (bk_version_is_older(is_string($version) ? $version : null, '0.1.7')) {
        $out['reason'] = 'agent_old';
        $out['params'] = ['version' => (string)$version];
        return $out;
    }
    // `agent_last_seen` is a unix timestamp inside last_details (agent_api.php
    // writes it there); a monitors column of the same name does not exist.
    $last_seen = $details['agent_last_seen'] ?? ($monitor['agent_last_seen'] ?? null);
    $last_ts = is_numeric($last_seen) ? (int)$last_seen : (is_string($last_seen) && $last_seen !== '' ? strtotime($last_seen) : false);
    if ($last_ts !== false && $last_ts > 0 && $now - $last_ts > 7 * 86400) {
        $out['reason'] = 'silent';
        $out['params'] = ['days' => (int)floor(($now - $last_ts) / 86400)];
        return $out;
    }

    $out['applicable'] = true;
    // Every rule group answers the same shape ['items', 'not_evaluated'], so
    // a group joins this list as it lands and nothing below has to change.
    // The calls are written out rather than dispatched through a variable so
    // that find_dead_code.php can see them.
    $groups = [
        bk_rec_rules_disk_health($in, $state),
        bk_rec_rules_disk_week($in, $state),
        bk_rec_rules_filesystems($in, $state),
        bk_rec_rules_wifi($in, $state),
        bk_rec_rules_wan_week($in, $state),
        bk_rec_rules_wan_tests($in, $state),
        bk_rec_rules_gap($in, $state),
    ];
    foreach ($groups as $res) {
        foreach ($res['items'] ?? [] as $item) {
            $out['items'][] = $item;
        }
        // The rules answer a map <rec_key> => <rule id>; the state save only
        // needs the keys it must leave alone.
        foreach (array_keys($res['not_evaluated'] ?? []) as $key) {
            $out['not_evaluated'][] = (string)$key;
        }
    }

    $out['items'] = bk_rec_sort_items($out['items']);
    $out['not_evaluated'] = array_values(array_unique($out['not_evaluated']));
    return $out;
}

/**
 * Severity, then area, then the rule's own rank, then key.
 *
 * The rank is explicit (`bk_router_rec_thresholds()['rank']`) because a plain
 * sort by key would put `disk_selftest_never` - an optional hygiene item -
 * above `disk_unclean_shutdowns`, a router losing power.
 */
function bk_rec_sort_items(array $items): array {
    $th = bk_router_rec_thresholds();
    $rank = array_flip($th['rank']);
    usort($items, function (array $a, array $b) use ($th, $rank): int {
        $sev = ($th['severity'][$a['severity']] ?? 9) <=> ($th['severity'][$b['severity']] ?? 9);
        if ($sev !== 0) {
            return $sev;
        }
        $area = ($th['area'][$a['area']] ?? 9) <=> ($th['area'][$b['area']] ?? 9);
        if ($area !== 0) {
            return $area;
        }
        $r = ($rank[$a['id']] ?? 999) <=> ($rank[$b['id']] ?? 999);
        return $r !== 0 ? $r : strcmp((string)$a['key'], (string)$b['key']);
    });
    return $items;
}

/**
 * The area of a rule id, for a row that has no item any more.
 *
 * A muted key whose rule stopped firing is still listed (so it can be
 * unmuted), and the list needs its area for grouping - but there is no item
 * left to read it from. The mapping is the one the rank list of
 * `bk_router_rec_thresholds()` is written in; a test walks that list and fails
 * on any id this function cannot place.
 */
function bk_rec_area_of(string $id): string {
    if (str_starts_with($id, 'pkg_')) {
        return 'packages';
    }
    if (str_starts_with($id, 'disk_') || str_starts_with($id, 'fs_')) {
        return 'storage';
    }
    if (str_starts_with($id, 'wifi_')) {
        return 'wifi';
    }
    if (str_starts_with($id, 'wan_') || $id === 'conntrack_drops' || $id === 'lan_wired_ceiling') {
        return 'wan';
    }
    if ($id === 'firewall_off') {
        return 'security';
    }
    if (in_array($id, ['router_restarts', 'dns_resolver_failing', 'clock_skew'], true)) {
        return 'system';
    }
    return 'system';
}

/**
 * Mutes: what the owner asked not to see again, and what rose above it.
 *
 * A mute is per router and per key and remembers the severity it was made at.
 * An item that is now WORSE than that comes back - a mute is "I know about
 * this", not "never tell me about this disk again" - and it is marked
 * `params.was_muted` so the app and the e-mail can say why it is back.
 * `openSince` is the moment the item first fired, for the page's "open since".
 */
function bk_router_rec_split(array $items, array $state): array {
    $th = bk_router_rec_thresholds();
    $out = ['items' => [], 'muted' => []];
    foreach ($items as $item) {
        $key = (string)($item['key'] ?? '');
        $row = $state[$key] ?? null;
        $item['openSince'] = is_array($row) && !empty($row['first_seen']) ? $row['first_seen'] : null;
        if (is_array($row) && !empty($row['muted_at'])) {
            $muted_rank = $th['severity'][(string)($row['muted_severity'] ?? '')] ?? 9;
            $now_rank = $th['severity'][(string)($item['severity'] ?? '')] ?? 9;
            $item['mutedAt'] = $row['muted_at'];
            $item['muteReason'] = $row['mute_reason'] ?? null;
            if ($now_rank >= $muted_rank) {
                $out['muted'][] = $item;
                continue;
            }
            $item['params']['was_muted'] = true;
        }
        $out['items'][] = $item;
    }
    return $out;
}

/**
 * The human sentences of one item, in the language that is active RIGHT NOW.
 *
 * Rendering is deliberately separate from evaluating: the same stored item is
 * mailed in the recipient's language and shown in the request's language, so
 * `t()` must run at call time and never at build time. `params` carries the
 * numbers, this function decides which text and which variant they go into.
 *
 * Returns ['title', 'measured', 'action']; an item whose rule has no texts yet
 * renders its id, never an empty box.
 */
function bk_router_rec_render(array $item): array {
    $id = (string)($item['id'] ?? '');
    $p = is_array($item['params'] ?? null) ? $item['params'] : [];
    $name = (string)($p['name'] ?? '');
    $disk = (string)($p['disk'] ?? '');
    // The Wi-Fi items carry the band as an enum and the channel as a number,
    // so both labels are built HERE, in the recipient's language. cs writes
    // "2,4 GHz", en "2.4 GHz" - the same item, two e-mails.
    $band_id = is_string($p['band_id'] ?? null) ? $p['band_id'] : null;
    $band = $band_id !== null ? bk_rec_band_label($band_id) : '';
    $radio = $band_id !== null ? bk_rec_radio_label($band_id, $p['ch'] ?? null) : '';
    $title = sprintf(t('rr_' . $id . '_title'), $name);
    $action = t('rr_' . $id . '_action');
    $measured = '';

    switch ($id) {
        case 'disk_smart_failing':
            $reason = (string)($p['reason'] ?? 'verdict');
            $reason_text = $reason === 'nvme'
                ? sprintf(t('rr_disk_smart_failing_reason_nvme'), bk_rec_num($p['critical_warning'] ?? null))
                : t('rr_disk_smart_failing_reason_' . $reason);
            $measured = sprintf(t('rr_disk_smart_failing_measured'), $disk, $reason_text);
            break;
        case 'disk_errors_growing':
            $frags = [];
            foreach (is_array($p['changes'] ?? null) ? $p['changes'] : [] as $ch) {
                $counter = (string)($ch['counter'] ?? '');
                $frags[] = $counter === 'pending_now'
                    ? sprintf(t('rr_disk_errors_growing_pending_now'), bk_rec_num($ch['to'] ?? null))
                    : sprintf(t('rr_disk_errors_growing_' . $counter), bk_rec_num($ch['from'] ?? null), bk_rec_num($ch['to'] ?? null));
            }
            $measured = sprintf(t('rr_disk_errors_growing_measured'), $disk, implode(', ', $frags));
            $action = sprintf(t('rr_disk_errors_growing_action'), $name);
            break;
        case 'disk_wear_high':
            if (($p['variant'] ?? 'attr') === 'emmc') {
                $life = $p['life'] ?? null;
                $range = $life === null ? t('rr_wear_range_unknown')
                    : ($life >= 11 ? t('rr_wear_range_over')
                        : sprintf(t('rr_wear_range'), bk_rec_num(((int)$life - 1) * 10), bk_rec_num((int)$life * 10)));
                $eol = t('rr_emmc_eol_' . (string)(int)($p['pre_eol'] ?? 1));
                $measured = sprintf(t('rr_disk_wear_high_measured_emmc'), $name, $range, $eol);
            } else {
                // Any `attr*` source is a vendor attribute read, not a
                // standardised wear field, and the sentence has to say so.
                $src = str_starts_with((string)($p['source'] ?? ''), 'attr') ? t('rr_wear_src_attr') : '';
                $measured = sprintf(t('rr_disk_wear_high_measured'), $disk, bk_rec_num($p['wear'] ?? null), $src);
            }
            break;
        case 'disk_wear_fast':
            $measured = sprintf(t('rr_disk_wear_fast_measured'), $disk, bk_rec_num($p['days'] ?? null),
                bk_rec_num($p['w0'] ?? null), bk_rec_num($p['w1'] ?? null), bk_rec_num($p['left'] ?? null));
            break;
        case 'disk_heavy_writes':
            $measured = sprintf(t('rr_disk_heavy_writes_measured'), $disk, bk_rec_num($p['gb'] ?? null, 1),
                bk_rec_num($p['pct'] ?? null, 1));
            break;
        case 'disk_temp_warm':
            $measured = sprintf(t('rr_disk_temp_warm_measured'), $disk, bk_rec_num($p['avg_c'] ?? null),
                bk_rec_num($p['max_c'] ?? null), bk_rec_num($p['limit_c'] ?? null));
            $action = sprintf(t('rr_disk_temp_warm_action'), bk_rec_num($p['limit_c'] ?? null));
            break;
        case 'disk_unclean_shutdowns':
            $grew = !empty($p['grew']) ? sprintf(t('rr_disk_unclean_shutdowns_grew'), bk_rec_num($p['grew'])) : '';
            $measured = sprintf(t('rr_disk_unclean_shutdowns_measured'), $disk, bk_rec_num($p['unsafe'] ?? null),
                bk_rec_num($p['cycles'] ?? null), $grew);
            $title = t('rr_disk_unclean_shutdowns_title');
            break;
        case 'disk_selftest_never':
            $measured = sprintf(t('rr_disk_selftest_never_measured'), $disk, bk_rec_num($p['hours'] ?? null));
            $action = sprintf(t('rr_disk_selftest_never_action'), $name, $name);
            break;
        case 'disk_smart_unreadable':
            $measured = sprintf(t('rr_disk_smart_unreadable_measured'), $disk,
                t('rr_disk_smart_unreadable_why_' . (string)($p['why'] ?? 'error')));
            break;
        case 'fs_nearly_full':
            $mount = (string)($p['mount'] ?? '');
            $title = sprintf(t('rr_fs_nearly_full_title'), $mount);
            $measured = sprintf(t('rr_fs_nearly_full_measured'), $mount, bk_rec_num($p['pct'] ?? null),
                isset($p['free']) && $p['free'] !== null ? bk_format_bytes_cz((float)$p['free']) : '—');
            $action = sprintf(t('rr_fs_nearly_full_action'), !empty($p['schnapps']) ? t('rr_fs_nearly_full_schnapps') : '');
            break;
        case 'wifi_6ghz_unserved':
            // Three fragments, each allowed to be empty: the hardware
            // sentence, the honest-gain sentence (only with a measured link
            // rate AND a measured airtime) and the WAN sentence of X-none.
            $measured = sprintf(t('rr_wifi_6ghz_unserved_measured'), bk_rec_num($p['capable'] ?? null),
                bk_rec_num($p['known'] ?? null), bk_rec_num($p['share'] ?? null),
                t('rr_wifi_6ghz_unserved_hw_' . (($p['hw'] ?? 'none') === 'present' ? 'present' : 'none')));
            $wan_frag = in_array($p['wan_variant'] ?? null, ['fast', 'slow'], true)
                ? sprintf(t('rr_wifi_6ghz_unserved_wan_' . $p['wan_variant']), bk_rec_num($p['wan_mbit'] ?? null)) . ' '
                : '';
            if (($p['hw'] ?? 'none') === 'present') {
                $action = sprintf(t('rr_wifi_6ghz_unserved_action_present'), $wan_frag);
            } else {
                $gain = $band_id !== null && ($p['rate'] ?? null) !== null && ($p['busy'] ?? null) !== null
                    ? sprintf(t('rr_wifi_6ghz_unserved_gain'), $band, bk_rec_num($p['rate'], 1),
                        bk_rec_num($p['busy'], 1)) . ' '
                    : '';
                $action = sprintf(t('rr_wifi_6ghz_unserved_action_none'), $gain, $wan_frag);
            }
            break;
        case 'wifi_channel_busy':
            $variant = ($p['variant'] ?? 'other') === 'total' ? 'total' : 'other';
            $title = sprintf(t('rr_wifi_channel_busy_title'), $band);
            $measured = sprintf(t('rr_wifi_channel_busy_measured_' . $variant), bk_rec_num($p['ch'] ?? null),
                $band, bk_rec_num($variant === 'other' ? ($p['other'] ?? null) : ($p['busy'] ?? null), 1),
                bk_rec_num($p['busy'] ?? null, 1));
            $action = t('rr_wifi_channel_busy_action_' . $variant);
            break;
        case 'wifi_noise_high':
            $title = sprintf(t('rr_wifi_noise_high_title'), $band);
            $measured = sprintf(t('rr_wifi_noise_high_measured'), $band, bk_rec_num($p['noise'] ?? null));
            break;
        case 'wifi_week_degraded':
            $frags = [];
            foreach (is_array($p['changes'] ?? null) ? $p['changes'] : [] as $ch) {
                $what = (string)($ch['what'] ?? '');
                $frags[] = sprintf(t('rr_wifi_week_degraded_' . ($what === 'noise' ? 'noise' : 'busy_other')),
                    bk_rec_num($ch['from'] ?? null, $what === 'noise' ? 0 : 1),
                    bk_rec_num($ch['to'] ?? null, $what === 'noise' ? 0 : 1));
            }
            $title = sprintf(t('rr_wifi_week_degraded_title'), $band);
            $measured = sprintf(t('rr_wifi_week_degraded_measured'), $band, implode(', ', $frags));
            break;
        case 'wifi_weak_encryption':
            $what = (string)($p['what'] ?? 'mixed');
            $what_text = $what === 'legacy'
                ? sprintf(t('rr_wifi_weak_encryption_what_legacy'), (string)($p['enc'] ?? 'WPA'))
                : t('rr_wifi_weak_encryption_what_' . ($what === 'open' ? 'open' : 'mixed'));
            $title = sprintf(t('rr_wifi_weak_encryption_title'), $radio);
            $measured = sprintf(t('rr_wifi_weak_encryption_measured'), $radio, $what_text);
            break;
        case 'wifi_wpa2_only':
            $title = sprintf(t('rr_wifi_wpa2_only_title'), $radio);
            $measured = sprintf(t('rr_wifi_wpa2_only_measured'), $radio);
            break;
        case 'wifi_wpa3_ready':
            $radios = [];
            foreach (is_array($p['radios'] ?? null) ? $p['radios'] : [] as $one) {
                $radios[] = bk_rec_radio_label(is_string($one['band'] ?? null) ? $one['band'] : null, $one['ch'] ?? null);
            }
            $measured = sprintf(t('rr_wifi_wpa3_ready_measured'), implode(', ', $radios),
                (string)($p['from'] ?? '—'), (string)($p['to'] ?? '—'));
            break;
        case 'wifi_mode_below_card':
            $title = sprintf(t('rr_wifi_mode_below_card_title'), $radio);
            $measured = sprintf(t('rr_wifi_mode_below_card_measured'), $radio,
                bk_rec_wifi_gen_label(isset($p['cur']) ? (int)$p['cur'] : null),
                (string)($p['htmode'] ?? '—'),
                bk_rec_wifi_gen_label(isset($p['best']) ? (int)$p['best'] : null));
            $action = sprintf(t('rr_wifi_mode_below_card_action'), (string)($p['suggested'] ?? ''),
                (string)($p['suggested'] ?? ''));
            break;
        case 'wifi_channel_narrow':
            $title = sprintf(t('rr_wifi_channel_narrow_title'), $radio);
            $measured = sprintf(t('rr_wifi_channel_narrow_measured'), $radio, bk_rec_num($p['w'] ?? null));
            $action = sprintf(t('rr_wifi_channel_narrow_action'), (string)($p['suggested'] ?? ''));
            break;
        case 'wifi_24_wide_channel':
            $measured = sprintf(t('rr_wifi_24_wide_channel_measured'), $radio, bk_rec_num($p['w'] ?? null));
            $action = sprintf(t('rr_wifi_24_wide_channel_action'), (string)($p['suggested'] ?? ''));
            break;
        case 'wifi_weak_client':
            // The SNR and the generation clauses are dropped when unknown:
            // "0 dB above the noise" or "Wi-Fi 0" would both be invented.
            $snr = isset($p['snr']) && $p['snr'] !== null
                ? sprintf(t('rr_wifi_weak_client_snr'), bk_rec_num($p['snr'])) : '';
            $gen = '';
            if (isset($p['gen']) && $p['gen'] !== null) {
                $gen = (int)$p['gen'] <= 0 ? t('rr_wifi_weak_client_gen_legacy')
                    : sprintf(t('rr_wifi_weak_client_gen'), bk_rec_num((int)$p['gen']));
            }
            $measured = sprintf(t('rr_wifi_weak_client_measured'), $radio,
                bk_rec_num($p['signal'] ?? null), $snr, $gen, bk_rec_num($p['noise'] ?? null));
            break;
        case 'wifi_5ghz_clients_on_24':
            $measured = sprintf(t('rr_wifi_5ghz_clients_on_24_measured'), bk_rec_num($p['n'] ?? null, 1));
            break;
        case 'wan_link_below_plan':
            $measured = sprintf(t('rr_wan_link_below_plan_measured'), $name === '' ? '—' : $name,
                bk_rec_num($p['mbit'] ?? null), bk_rec_num($p['plan'] ?? null));
            break;
        case 'wan_port_errors':
            // The "could not keep up" sentence needs the HARDWARE counters:
            // sysfs drops count unhandled-protocol junk on mvneta and would
            // blame the router for frames it was right to throw away.
            $ring = isset($p['ring']) && $p['ring'] !== null
                ? ' ' . sprintf(t('rr_wan_port_errors_ring'), bk_rec_num($p['ring'])) : '';
            $measured = sprintf(t('rr_wan_port_errors_measured'), $name === '' ? '—' : $name,
                bk_rec_num($p['errors'] ?? null), bk_rec_num($p['drops'] ?? null),
                (int)($p['days'] ?? 0)) . $ring;
            break;
        case 'wan_link_flaps':
            $measured = sprintf(t('rr_wan_link_flaps_measured'), $name === '' ? '—' : $name,
                (int)($p['flaps'] ?? 0));
            break;
        case 'wan_line_below_plan':
            $measured = sprintf(t('rr_wan_line_below_plan_measured'), bk_rec_dir_label($p['dir'] ?? null),
                bk_rec_num($p['mbps'] ?? null), bk_rec_num($p['plan'] ?? null),
                bk_rec_num($p['core'] ?? null), bk_rec_num($p['link'] ?? null),
                (int)($p['agree'] ?? 0), (int)($p['span_days'] ?? 0), (int)($p['servers'] ?? 0));
            $action = sprintf(t('rr_wan_line_below_plan_action'), bk_rec_num($p['ok_pct'] ?? 85));
            break;
        case 'wan_port_limited':
            $measured = sprintf(t('rr_wan_port_limited_measured'), bk_rec_dir_label($p['dir'] ?? null),
                bk_rec_num($p['mbps'] ?? null), $name === '' ? '—' : $name, bk_rec_num($p['link'] ?? null));
            break;
        case 'wan_sqm_limited':
            $measured = sprintf(t('rr_wan_sqm_limited_measured'), bk_rec_dir_label($p['dir'] ?? null),
                bk_rec_num($p['mbps'] ?? null), bk_rec_num($p['sqm'] ?? null), bk_rec_num($p['plan'] ?? null));
            break;
        case 'wan_cpu_packet_path':
            $measured = sprintf(t('rr_wan_cpu_packet_path_measured'), bk_rec_dir_label($p['dir'] ?? null),
                bk_rec_num($p['mbps'] ?? null), bk_rec_num($p['core_busy'] ?? null),
                bk_rec_num($p['net_share'] ?? null), bk_rec_num($p['all_cores'] ?? null),
                (int)($p['agree'] ?? 0), (int)($p['tests'] ?? 0));
            $action = sprintf(t('rr_wan_cpu_packet_path_action'), bk_rec_num($p['mbps'] ?? null),
                bk_rec_num($p['link'] ?? null))
                . (!empty($p['with_forwarding']) ? ' ' . t('rr_wan_cpu_packet_path_with_forwarding') : '')
                . (!empty($p['steering_off']) ? ' ' . t('rr_packet_steering_off') : '');
            break;
        case 'wan_forwarding_core_saturated':
            $measured = sprintf(t('rr_wan_forwarding_core_saturated_measured'), (int)($p['minutes'] ?? 0),
                bk_rec_num($p['softirq'] ?? null), bk_rec_num($p['mbps'] ?? null));
            $action = t('rr_wan_forwarding_core_saturated_action')
                . (!empty($p['steering_off']) ? ' ' . t('rr_packet_steering_off') : '')
                . (!empty($p['offloading_off']) ? ' ' . t('rr_flow_offloading_off') : '');
            break;
        case 'conntrack_drops':
            $measured = sprintf(t('rr_conntrack_drops_measured'), bk_rec_num($p['drops'] ?? null),
                bk_rec_num($p['pct'] ?? null));
            // The evidence row: evicted is something else than refused, so it
            // gets its own sentence instead of being added to the count.
            if (($p['evicted'] ?? null) !== null) {
                $measured .= ' ' . sprintf(t('rr_conntrack_drops_evicted'), bk_rec_num($p['evicted']));
            }
            break;
        case 'lan_wired_ceiling':
            $measured = sprintf(t('rr_lan_wired_ceiling_measured'), bk_rec_num($p['plan'] ?? null),
                bk_rec_num($p['cap'] ?? null));
            // The household's own evidence, only when the switch measured BOTH
            // numbers: how many wired devices share which conduit. Half of it
            // would be a sentence with a dash in it, so it stays unsaid.
            if (($p['conduit'] ?? null) !== null && ($p['clients'] ?? null) !== null) {
                $measured .= ' ' . sprintf(t('rr_lan_wired_ceiling_shared'), (int)$p['clients'],
                    bk_rec_num($p['conduit']));
            }
            break;
        case 'firewall_off':
            $measured = sprintf(t('rr_firewall_off_measured'),
                isset($p['since']) ? date('Y-m-d H:i', (int)$p['since']) : '—');
            break;
        case 'router_restarts':
            $measured = sprintf(t('rr_router_restarts_measured'), (int)($p['count'] ?? 0),
                is_string($p['last_at'] ?? null) ? $p['last_at'] : '—');
            $action = t('rr_router_restarts_action')
                . (!empty($p['unclean']) ? ' ' . t('rr_router_restarts_unclean') : '');
            break;
        case 'dns_resolver_failing':
            $measured = sprintf(t('rr_dns_resolver_failing_measured'), (int)($p['days'] ?? 0),
                (int)($p['count'] ?? 0));
            break;
        case 'clock_skew':
            $measured = sprintf(t('rr_clock_skew_measured'), bk_rec_num($p['secs'] ?? null, 1));
            break;
        case 'pkg_smartmontools':
        case 'pkg_smart_drivedb':
        case 'pkg_hostapd_utils':
        case 'pkg_iw':
        case 'pkg_librespeed_cli':
            $cmd = bk_rec_install_cmd(is_string($p['pkg_manager'] ?? null) ? $p['pkg_manager'] : null,
                (string)($p['pkg'] ?? ''));
            $measured = t('rr_' . $id . '_measured');
            $action = sprintf(t('rr_' . $id . '_action'), $cmd);
            break;
        default:
            // A rule whose texts have not landed yet still shows WHAT fired.
            $title = $id;
            break;
    }

    return ['title' => $title, 'measured' => $measured, 'action' => $action];
}

/**
 * The weekly snapshot of one router's recommendations.
 *
 * Called ONLY by the weekly digest build (`save_snapshot = true`), because
 * "new this week" has to mean the same thing for everybody who reads the
 * e-mail; a page view must never move that line.
 *
 * Three groups of keys, and the difference between them is the whole point:
 *   - firing keys get `active = 1`, their severity, a `first_digest_week` if
 *     they had none and a `raised_digest_week` when the severity ROSE;
 *   - keys that were evaluated and no longer fire are cleared, so the next
 *     time they appear they are new again;
 *   - keys that could NOT be evaluated (fewer than four days of data) are left
 *     exactly as they are - not cleared, not refreshed. A router switched off
 *     over a holiday must not mail its whole list again as new.
 *
 * The write is skipped entirely when it would change nothing (every firing key
 * already stands with the same severity and was last seen in THIS ISO week,
 * and nothing evaluated is still active): the digest is rebuilt on every cron
 * run from 08:00 to 12:00 until a send succeeds, and only the first build of a
 * week has anything to say.
 */
function bk_router_rec_state_save(PDO $pdo, int $monitor_id, array $items, string $iso_week, array $not_evaluated = [], array $state = []): void {
    $th = bk_router_rec_thresholds();
    $firing = [];
    foreach ($items as $item) {
        $key = (string)($item['key'] ?? '');
        if ($key !== '') {
            $firing[$key] = $item;
        }
    }
    $skip_clear = [];
    foreach ($not_evaluated as $key) {
        $skip_clear[(string)$key] = true;
    }

    // Would this write change anything? The rows are already in the inputs, so
    // the question costs no query.
    $changes = false;
    foreach ($firing as $key => $item) {
        $row = $state[$key] ?? null;
        $seen_week = is_array($row) && !empty($row['last_seen']) ? date('o-\WW', (int)strtotime((string)$row['last_seen'])) : null;
        if (!is_array($row) || (int)($row['active'] ?? 0) !== 1
            || (string)($row['severity'] ?? '') !== (string)($item['severity'] ?? '')
            || $seen_week !== $iso_week) {
            $changes = true;
            break;
        }
    }
    if (!$changes) {
        foreach ($state as $key => $row) {
            if ((int)($row['active'] ?? 0) === 1 && !isset($firing[$key]) && !isset($skip_clear[$key])) {
                $changes = true;
                break;
            }
        }
    }
    if (!$changes) {
        return;
    }

    $upsert = $pdo->prepare(
        "INSERT INTO router_rec_state
             (monitor_id, rec_key, rule_id, active, severity, first_seen, last_seen, first_digest_week)
         VALUES (?, ?, ?, 1, ?, NOW(), NOW(), ?)
         ON DUPLICATE KEY UPDATE
             rule_id = VALUES(rule_id),
             active = 1,
             -- A severity that ROSE is what makes an old item full again in the
             -- e-mail, so the week it rose is recorded before severity is
             -- overwritten. FIELD() ranks critical < warning < info.
             raised_digest_week = IF(severity IS NOT NULL
                 AND FIELD(VALUES(severity), 'critical', 'warning', 'info')
                   < FIELD(severity, 'critical', 'warning', 'info'),
                 ?, raised_digest_week),
             severity = VALUES(severity),
             first_seen = COALESCE(first_seen, VALUES(first_seen)),
             last_seen = VALUES(last_seen),
             first_digest_week = COALESCE(first_digest_week, VALUES(first_digest_week))"
    );
    foreach ($firing as $key => $item) {
        $upsert->execute([$monitor_id, $key, (string)($item['id'] ?? ''), (string)($item['severity'] ?? ''), $iso_week, $iso_week]);
    }

    // Everything that was evaluated and did not fire stops being open. The
    // row itself stays (a mute lives in it, and `last_seen` dates the 90-day
    // retention), only the "is open" half is cleared.
    $clear = $pdo->prepare(
        "UPDATE router_rec_state SET active = 0, severity = NULL, first_digest_week = NULL, raised_digest_week = NULL
          WHERE monitor_id = ? AND rec_key = ? AND active = 1"
    );
    foreach ($state as $key => $row) {
        $key = (string)$key;
        if (isset($firing[$key]) || isset($skip_clear[$key]) || (int)($row['active'] ?? 0) !== 1) {
            continue;
        }
        $clear->execute([$monitor_id, $key]);
    }
}

/**
 * Detection of data COLLECTION outages for one monitor - not outages of the service.
 * The principle (2026-08-05, after two weeks of invisibly dead cpanel collection):
 * when data stops being collected, the frontend must scream, not silently show nothing.
 * Returns items {type, message, since}; an empty array = collection healthy.
 * Shared between api.php (React SPA) and index.php (the public status page).
 */
function bk_get_collection_issues(array $monitor_row, array $details, int $agent_offline_timeout_secs = 3000): array {
    $issues = [];
    $status = strtolower($monitor_row['status'] ?? '');

    // 1. Failing cPanel stats collection (written by cron.php on every failed
    //    check_cpanel; the key carries the reason and the outage start too).
    if (!empty($details['cpanel_stats_error']) && is_array($details['cpanel_stats_error'])) {
        $issues[] = [
            'type' => 'cpanel_stats',
            'message' => (string)($details['cpanel_stats_error']['error'] ?? t('collection_issue_cpanel_generic')),
            'hint' => $details['cpanel_stats_error']['hint'] ?? null,
            'since' => $details['cpanel_stats_error']['since'] ?? null,
        ];
    }

    // 2. The agent stopped reporting (agent_last_seen older than the offline
    //    timeout). Only for monitors that ever had an agent - otherwise every web would scream.
    $agent_last_seen = (int)($details['agent_last_seen'] ?? 0);
    if ($agent_last_seen > 0 && (time() - $agent_last_seen) > $agent_offline_timeout_secs) {
        $issues[] = [
            'type' => 'agent_silent',
            'message' => sprintf(t('collection_issue_agent_silent'), round((time() - $agent_last_seen) / 60)),
            'since' => date('c', $agent_last_seen),
        ];
    }

    // 3. The checks themselves are not running (dead cron for this monitor).
    //    Pause and maintenance are legitimate no-check states - not reported.
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

    // 4. The router's SMART probe is wedged (CORE 3.6). smartctl on a USB
    //    bridge that stopped answering blocks for minutes; the disk card would
    //    otherwise keep showing yesterday's values as if they were current.
    $agent_tools = is_array($details['agent_tools'] ?? null) ? $details['agent_tools'] : [];
    $probe_running = $agent_tools['smart_probe_running_s'] ?? null;
    if (is_int($probe_running) && $probe_running > 900) {
        $issues[] = [
            'type' => 'smart_probe_stuck',
            'message' => sprintf(t('collection_issue_smart_probe_stuck'), intdiv($probe_running, 60)),
            'since' => date('c', time() - $probe_running),
        ];
    }

    // 5. A disk whose SMART could not be read for a day. The values in the
    //    card are the last successful reading, so without this the disk looks
    //    healthy exactly while nobody knows anything about it.
    foreach (is_array($details['storage_disks'] ?? null) ? $details['storage_disks'] : [] as $ci_disk) {
        if (!is_array($ci_disk) || ($ci_disk['smart']['state'] ?? null) !== 'error') {
            continue;
        }
        $ci_checked = $ci_disk['smart']['checked_at'] ?? null;
        if (is_int($ci_checked) && (time() - $ci_checked) <= 86400) {
            continue;
        }
        $issues[] = [
            'type' => 'smart_read_failing',
            'message' => sprintf(t('collection_issue_smart_read_failing'), bk_disk_label($ci_disk),
                is_int($ci_checked) ? date('j. n. Y', $ci_checked) : t('collection_issue_smart_read_never')),
            'since' => is_int($ci_checked) ? date('c', $ci_checked) : null,
        ];
    }

    // 6. X15: the two records of lost data. `details_dropped` is the 60 kB cap
    //    of last_details, `ingest_issues` everything else the ingest refused.
    //    Both are reset by the next report that fits, so the issue ends by itself.
    $dropped = array_values(array_filter((array)($details['details_dropped'] ?? []), 'is_string'));
    if ($dropped !== []) {
        $issues[] = [
            'type' => 'storage_list_dropped',
            'message' => sprintf(t('collection_issue_storage_list_dropped'), implode(', ', array_slice($dropped, 0, 8))),
            'since' => null,
        ];
    }
    // 7. G42: minute reports that never arrived. The counting is cron's (the
    //    pure function has no $pdo), this only reads what it stored. Below 120
    //    expected minutes nothing is claimed - a router that booted twenty
    //    minutes ago has not missed anything yet.
    $reports = is_array($details['reports_24h'] ?? null) ? $details['reports_24h'] : [];
    $expected = bk_ranged_int($reports['expected'] ?? null, 0, 1440);
    $received = bk_ranged_int($reports['received'] ?? null, 0, 100000);
    if ($expected !== null && $received !== null && $expected >= 120 && $received < 0.9 * $expected) {
        $skipped_lock = bk_ranged_int($details['runs_skipped_lock'] ?? null, 0, 100000) ?? 0;
        $skipped_post = bk_ranged_int($details['runs_skipped_post'] ?? null, 0, 100000) ?? 0;
        $message = sprintf(t('collection_issue_reports_missing'), $expected - $received, $expected);
        if ($skipped_lock > 0 || $skipped_post > 0) {
            // The agent's own counters: the difference between "the server
            // lost them" and "the previous run was still going".
            $message .= ' ' . sprintf(t('collection_issue_reports_missing_skips'), $skipped_lock, $skipped_post);
        }
        $issues[] = [
            'type' => 'reports_missing',
            'message' => $message,
            'since' => isset($reports['checked_at']) && is_int($reports['checked_at']) ? date('c', $reports['checked_at']) : null,
        ];
    }

    $ingest = is_array($details['ingest_issues'] ?? null) ? $details['ingest_issues'] : [];
    if ($ingest !== []) {
        $ci_names = [];
        foreach (array_slice($ingest, 0, 5) as $ci_item) {
            if (!is_array($ci_item) || !is_string($ci_item['type'] ?? null)) {
                continue;
            }
            $ci_names[] = is_string($ci_item['key'] ?? null)
                ? $ci_item['type'] . ' (' . $ci_item['key'] . ')'
                : $ci_item['type'];
        }
        if ($ci_names !== []) {
            $issues[] = [
                'type' => 'ingest_dropped',
                'message' => sprintf(t('collection_issue_ingest_dropped'), implode(', ', $ci_names)),
                'since' => null,
            ];
        }
    }

    return $issues;
}

function bk_enrich_monitor_details($pdo, $monitor, &$details, bool $system = false) {
    if (!is_array($details)) $details = [];
    if (!$pdo || empty($monitor) || !is_array($monitor)) return;

    $mid = (int)($monitor['id'] ?? 0);
    $asset_id = $monitor['asset_id'] ?? null;
    $target = trim($monitor['target'] ?? '');
    $sib_details_raw = null;

    // Every lookup below reads ANOTHER monitor. A viewer may borrow only from
    // monitors they can see, or a user assigned one TeamSpeak monitor would get
    // the processes and interfaces of any agent in the fleet. Cron ($system)
    // runs without a session and sees everything.
    $visible = $system ? null : bk_visible_monitor_ids($pdo);
    if ($visible === []) {
        return;
    }
    [$vis_sql, $vis_params] = bk_monitor_scope_sql($visible, 'id');

    // 1. Try matching via asset_id (when set)
    if (!empty($asset_id)) {
        try {
            $stmt = $pdo->prepare("SELECT last_details FROM monitors WHERE asset_id = ? AND id != ? AND last_details IS NOT NULL AND agent_key IS NOT NULL AND agent_key != '' AND archived_at IS NULL AND {$vis_sql} LIMIT 1");
            $stmt->execute(array_merge([$asset_id, $mid], $vis_params));
            $sib_details_raw = $stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    // 2. Try matching via the target IP / hostname
    if (!$sib_details_raw && !empty($target)) {
        try {
            $host_or_ip = parse_url($target, PHP_URL_HOST) ?: $target;

            // A DNS query only when the target looks like an address at all.
            //
            // Agent monitors tend to carry a human label in `target` - e.g.
            // "Turris - domov". gethostbyname() tries to resolve it anyway and
            // waits for the resolver timeout: six seconds per render in
            // production, synchronously in the middle of the page. That
            // monitor's detail stretched to eight seconds while the others
            // rendered within one.
            //
            // gethostbyname() moreover returns its input unchanged on failure,
            // so nothing is lost this way - only the wait goes away.
            $looks_like_address = filter_var($host_or_ip, FILTER_VALIDATE_IP) !== false
                || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i', $host_or_ip) === 1;
            $resolved_ip = $looks_like_address ? gethostbyname($host_or_ip) : $host_or_ip;
            
            $stmt = $pdo->prepare("
                SELECT last_details FROM monitors 
                WHERE id != ? AND agent_key IS NOT NULL AND agent_key != '' AND last_details IS NOT NULL
                  AND (target = ? OR target = ? OR last_details LIKE ?)
                  AND archived_at IS NULL AND {$vis_sql}
                ORDER BY updated_at DESC LIMIT 1
            ");
            $stmt->execute(array_merge([$mid, $target, $resolved_ip, '%' . $resolved_ip . '%'], $vis_params));
            $sib_details_raw = $stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    // 3. For TeamSpeak: if ts3_process is still missing, find ANY agent reporting an active ts3_process
    if (!$sib_details_raw && ($monitor['type'] ?? '') === 'teamspeak') {
        try {
            $stmt = $pdo->prepare("
                SELECT last_details FROM monitors 
                WHERE agent_key IS NOT NULL AND agent_key != '' 
                  AND last_details LIKE '%\"ts3_process\"%' 
                  AND last_details NOT LIKE '%\"ts3_process\":null%'
                  AND archived_at IS NULL AND {$vis_sql}
                ORDER BY updated_at DESC LIMIT 1
            ");
            $stmt->execute($vis_params);
            $sib_details_raw = $stmt->fetchColumn();
        } catch (Exception $e) {}
    }

    // 4. When agent details were found, fill the gaps in $details
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

/**
 * Who is looking: an anonymous visitor, an admin, or a user who sees only the
 * monitors assigned to them. The session key admin_logged_in means "any
 * signed-in account", despite its name.
 *
 * @return array{logged_in: bool, is_admin: bool, user_id: int}
 */
function bk_viewer(): array {
    bk_sync_session_account($GLOBALS['pdo'] ?? null);
    $logged_in = !empty($_SESSION['admin_logged_in']);
    return [
        'logged_in' => $logged_in,
        'is_admin' => $logged_in && ($_SESSION['admin_role'] ?? '') === 'admin',
        'user_id' => $logged_in ? (int)($_SESSION['admin_id'] ?? 0) : 0,
    ];
}

/**
 * The monitor ids the current viewer may see.
 *
 * Monitors belong to users through monitor_users, and several users may share
 * one. An admin sees everything (null means no restriction). A signed-in user
 * sees only the assigned monitors. An anonymous visitor gets none through the
 * app - the public status page has its own status-only endpoints.
 *
 * @return int[]|null null = every monitor.
 */
/**
 * Brings the signed-in account in line with the users table, once per request.
 *
 * The role was written into the session at login and never read again, so an
 * administrator demoted to a user, or deleted, kept every right until they
 * logged out. The role is re-read here and a deleted account is logged out.
 * When the session lock is already released the correction stays in memory,
 * which is enough: the next request corrects it again.
 */
function bk_sync_session_account($pdo): void {
    static $synced = false;
    if ($synced || empty($_SESSION['admin_logged_in']) || !($pdo instanceof PDO)) {
        return;
    }
    $synced = true;
    $user_id = (int)($_SESSION['admin_id'] ?? 0);
    $role = false;
    if ($user_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn();
        } catch (PDOException $e) {
            // Fail closed: the login stays, admin rights do not for this request.
            error_log('[access] session account check failed: ' . $e->getMessage());
            $_SESSION['admin_role'] = 'user';
            return;
        }
    }
    if ($role === false) {
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_role'], $_SESSION['admin_id'], $_SESSION['admin_username']);
        return;
    }
    $_SESSION['admin_role'] = (string)$role;
}

function bk_visible_monitor_ids(PDO $pdo): ?array {
    $viewer = bk_viewer();
    if ($viewer['is_admin']) {
        return null;
    }
    if (!$viewer['logged_in'] || $viewer['user_id'] <= 0) {
        return [];
    }
    static $cache = [];
    if (!array_key_exists($viewer['user_id'], $cache)) {
        try {
            $stmt = $pdo->prepare("SELECT monitor_id FROM monitor_users WHERE user_id = ?");
            $stmt->execute([$viewer['user_id']]);
            $cache[$viewer['user_id']] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            // The table is missing (migration pending): fail closed. A user who
            // sees nothing for a minute is a nuisance; one who sees everything is a leak.
            error_log('[access] monitor_users unavailable: ' . $e->getMessage());
            $cache[$viewer['user_id']] = [];
        }
    }
    return $cache[$viewer['user_id']];
}

/** Whether the current viewer may see this monitor. */
function bk_can_view_monitor(PDO $pdo, int $monitor_id): bool {
    $ids = bk_visible_monitor_ids($pdo);
    return $ids === null || in_array($monitor_id, $ids, true);
}

/**
 * An SQL condition restricting a monitor id column to the viewer's monitors.
 * Pure, so the three cases are testable without a database.
 *
 * @param int[]|null $ids From bk_visible_monitor_ids(): null = all monitors.
 * @param string $column A column reference written in code, e.g. 'm.id'.
 * @return array{0: string, 1: int[]} The condition and its bound parameters.
 */
function bk_monitor_scope_sql(?array $ids, string $column): array {
    if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
        throw new InvalidArgumentException('Invalid column reference: ' . $column);
    }
    if ($ids === null) {
        return ['1=1', []];
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return ['1=0', []];
    }
    return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/**
 * SQL that leaves the given monitor ids out: [sql, params]. No ids, no filter.
 */
function bk_exclude_ids_sql(array $ids, string $column): array {
    if (!preg_match('/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/i', $column)) {
        throw new InvalidArgumentException('Invalid column reference: ' . $column);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids) {
        return ['1=1', []];
    }
    return [$column . ' NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

/**
 * Ids of archived monitors, read once per request.
 *
 * An archived monitor keeps its history and takes no part in anything live: no
 * checks, no alerts, no lists, no summaries, no agent reports. Before the
 * migration adds the column nothing is archived, so a failed read is an empty list.
 */
function bk_archived_monitor_ids(PDO $pdo, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    try {
        $cache = array_map('intval', $pdo->query("SELECT id FROM monitors WHERE archived_at IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

/** [sql, params] that leaves archived monitors out of a list or a summary. */
function bk_active_monitor_sql(PDO $pdo, string $column): array {
    return bk_exclude_ids_sql(bk_archived_monitor_ids($pdo), $column);
}

/**
 * The scope of a list or a summary: the monitors the viewer may see, without
 * the archived ones. A detail by id keeps bk_monitor_scope_sql, so an archived
 * monitor stays readable.
 */
function bk_list_scope_sql(PDO $pdo, ?array $visible, string $column): array {
    [$scope, $params] = bk_monitor_scope_sql($visible, $column);
    [$active, $active_params] = bk_active_monitor_sql($pdo, $column);
    return ["({$scope} AND {$active})", array_merge($params, $active_params)];
}

function bk_monitor_is_archived(PDO $pdo, int $monitor_id): bool {
    return in_array($monitor_id, bk_archived_monitor_ids($pdo), true);
}

/** Stops a change to an archived monitor: it is read-only until restored. */
function bk_refuse_archived_write(PDO $pdo, int $monitor_id): void {
    if ($monitor_id > 0 && bk_monitor_is_archived($pdo, $monitor_id)) {
        http_response_code(409);
        echo json_encode(['error' => 'Monitor je archivovaný a nejde upravovat. Nejdřív ho obnovte.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** An agent of an archived monitor: its report is refused, not stored. */
function bk_agent_refuse_archived(): void {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'archived' => true,
        'message' => 'Monitor je archivovaný, hlášení se neukládá. Obnovte ho v aplikaci, nebo agenta na tomto zařízení odinstalujte.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** "Agent routeru" or "Agent serveru" - an OpenWrt monitor is not a VPS. */
function bk_agent_label(string $monitor_type): string {
    return strtolower($monitor_type) === 'openwrt' ? 'Agent routeru' : 'Agent serveru';
}

/** What to check when an agent stops reporting, in the words of its device. */
function bk_agent_silence_hint(string $monitor_type): string {
    return strtolower($monitor_type) === 'openwrt'
        ? 'Zkontrolujte, zda router běží, má připojení k internetu a agent je zařazený v cronu routeru.'
        : 'Zkontrolujte, zda na serveru běží cron úloha agenta, server je zapnutý a síť ani firewall neblokují spojení.';
}

/**
 * A monitor's details as the public status page may show them.
 *
 * An allowlist, not a denylist: agents pass through keys the server has never
 * seen, so a denylist leaked every new one - process lists, interface names,
 * per-link traffic - to anonymous visitors by default. These are the keys the
 * public monitor card renders. Everything else, from process names to the
 * network identity, is for the signed-in users the monitor belongs to.
 */
function bk_public_monitor_details(array $details): array {
    $allowed = [
        // Load percentages the public cards draw as bars - they name nothing.
        'cpu', 'ram', 'hdd', 'disk', 'memory',
        'version', 'motd', 'players_online', 'players_max',
        'clients_online', 'clients_max',
        'presence_count', 'members', 'voice_channels',
        'model', 'os', 'cpanel_stats',
    ];
    return array_intersect_key($details, array_flip($allowed));
}

/**
 * Whether the request gets the public status view: asked for with
 * scope=public, and always for an anonymous caller. The public view shows the
 * status of every public monitor, the same for everyone, with no host internals.
 */
function bk_public_view(): bool {
    return ($_GET['scope'] ?? '') === 'public' || empty($_SESSION['admin_logged_in']);
}

/**
 * Monitor types the public status page leaves out unless the owner turns them
 * on (owner decision 5.3, W1-G3): servers, the home router and the services an
 * agent watches on them. The page is indexed by search engines, and it used to
 * list every machine by name - the home router included - to anyone.
 * Websites and game services, the things visitors come to check, stay on it.
 */
const BK_PRIVATE_BY_DEFAULT_TYPES = ['vps', 'openwrt', 'agent_service'];

/**
 * Whether a monitor is on the public status page. Pure.
 *
 * @param mixed $is_public The monitors.is_public column: 1/0 is the owner's
 *                         own choice, NULL means "never chosen" and follows
 *                         the type, so every way a monitor is created (the
 *                         app, an import, an agent's first report) gets the
 *                         same default without each of them knowing it.
 */
function bk_monitor_is_public($is_public, string $type): bool {
    if ($is_public !== null && $is_public !== '') {
        return (int)$is_public === 1;
    }
    return !in_array(strtolower($type), BK_PRIVATE_BY_DEFAULT_TYPES, true);
}

/**
 * Ids of the monitors on the public status page, read once per request.
 *
 * Before the migration adds the column every monitor follows its type's
 * default. A database that cannot answer at all throws: an empty list here
 * would read as "no services, nothing down" on the public page.
 *
 * @return int[]
 */
function bk_public_monitor_ids(PDO $pdo, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    try {
        $rows = $pdo->query("SELECT id, type, is_public FROM monitors")->fetchAll();
    } catch (PDOException $e) {
        $rows = $pdo->query("SELECT id, type, NULL AS is_public FROM monitors")->fetchAll();
    }
    $ids = [];
    foreach ($rows as $row) {
        if (bk_monitor_is_public($row['is_public'], (string)$row['type'])) {
            $ids[] = (int)$row['id'];
        }
    }
    $cache = $ids;
    return $ids;
}

/**
 * The monitors a list or a summary covers for this request: the public set in
 * the public view (scope=public or no login), the viewer's own otherwise
 * (null = all, for an administrator).
 *
 * @return int[]|null
 */
function bk_request_monitor_ids(PDO $pdo): ?array {
    return bk_public_view() ? bk_public_monitor_ids($pdo) : bk_visible_monitor_ids($pdo);
}

/**
 * Whether data collection (cron) has finished a run recently enough that the
 * stored states are current. The same rule as action=collection_health: no run
 * ever recorded is not fresh.
 */
function bk_collection_is_fresh(): bool {
    $last_run = (string)get_setting('last_cron_run', '');
    $last_ts = $last_run !== '' ? strtotime($last_run) : false;
    if ($last_ts === false) {
        return false;
    }
    $max_age = max(60, (int)get_setting('collection_max_age_secs', '900'));
    return (time() - $last_ts) <= $max_age;
}

/**
 * The one overall verdict for a set of monitors (W1-B4). Pure.
 *
 * public_status, the fleet badge, the public page and the marketing site all
 * say what this says. Each used to decide on its own, and all of them said
 * "healthy" unless something was down: a degraded monitor, one whose state
 * nobody knows and a collector that stopped running all read as all-clear.
 *
 * Order: down > degraded (warning, or a state nobody knows) > unknown (the
 * collector is not running, or nothing was ever measured) > maintenance >
 * healthy. A monitor that has never been checked ("čeká na první data") is
 * counted but does not degrade the verdict - it is new, not broken; only a set
 * made of nothing else is unknown.
 *
 * @param array<int, array<string, mixed>> $monitors Rows with status,
 *        maintenance and last_checked.
 * @param bool $collection_fresh bk_collection_is_fresh()
 * @return array{verdict: string, counts: array<string, int>}
 */
function bk_overall_verdict(array $monitors, bool $collection_fresh): array {
    $counts = ['up' => 0, 'down' => 0, 'warning' => 0, 'maintenance' => 0, 'unknown' => 0, 'unmeasured' => 0];
    foreach ($monitors as $m) {
        $status = strtolower((string)($m['status'] ?? ''));
        if ($status === 'maintenance' || !empty($m['maintenance'])) {
            $counts['maintenance']++;
        } elseif (in_array($status, ['up', 'down', 'warning'], true)) {
            $counts[$status]++;
        } elseif (empty($m['last_checked'])) {
            $counts['unmeasured']++;
        } else {
            $counts['unknown']++;
        }
    }
    $total = array_sum($counts);
    if ($total === 0 || $counts['unmeasured'] === $total) {
        $verdict = 'unknown';
    } elseif ($counts['down'] > 0) {
        $verdict = 'down';
    } elseif ($counts['warning'] > 0 || $counts['unknown'] > 0) {
        $verdict = 'degraded';
    } elseif (!$collection_fresh) {
        // The stored states are the last known ones, and nothing is checking
        // them: "operational" would be a claim nobody verified.
        $verdict = 'unknown';
    } elseif ($counts['maintenance'] > 0) {
        $verdict = 'maintenance';
    } else {
        $verdict = 'healthy';
    }
    return ['verdict' => $verdict, 'counts' => $counts];
}

/** Stops the request with 401 unless someone is signed in. */
function bk_require_login(): void {
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Stops the request unless the viewer may see the monitor: 401 for an
 * anonymous caller, 404 for a signed-in user it is not assigned to. The 404 is
 * the same answer as for a monitor that does not exist, so a user cannot probe
 * which ids belong to someone else.
 */
function bk_require_monitor_view(PDO $pdo, int $monitor_id): void {
    bk_require_login();
    if ($monitor_id <= 0 || !bk_can_view_monitor($pdo, $monitor_id)) {
        http_response_code(404);
        echo json_encode(['error' => 'Monitor nenalezen'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * What the public view may say about why a check failed.
 *
 * A status page says that a service is down, not what runs on the machine
 * behind it or where it lives: no process names, hosts, ports, addresses or
 * the text a page is searched for. Null lets the public page fall back to its
 * generic wording.
 */
function bk_public_reason(?string $message, string $monitor_type): ?string {
    if ($message === null || trim($message) === '') {
        return null;
    }
    // Agent-side checks describe the machine from inside: processes, ports, units.
    if (in_array(strtolower($monitor_type), ['vps', 'openwrt', 'agent_service'], true)) {
        return null;
    }
    $message = trim($message);
    // What the checks store names hosts ("Could not resolve host: ..."), ports,
    // the text a page is searched for and the hosting's own IP. A list of
    // forbidden words let all of that through, so each known shape maps to a
    // sentence without them and anything else says nothing - the caller then
    // shows its generic label.
    if (preg_match('/^HTTP status kód: (\d{1,3})$/u', $message, $m)) {
        return 'HTTP status kód: ' . $m[1];
    }
    if (preg_match('/^Stránka odpověděla HTTP (\d{1,3}), ale neobsahuje očekávaný text/u', $message, $m)) {
        return 'Stránka odpověděla HTTP ' . $m[1] . ', ale neobsahuje očekávaný obsah';
    }
    if (str_starts_with($message, 'cURL chyba:') || $message === 'Spojení selhalo') {
        if (preg_match('/timed?\s*out|timeout/i', $message)) {
            return 'Vypršel časový limit spojení';
        }
        if (preg_match('/resolve|name lookup/i', $message)) {
            return 'Adresu se nepodařilo přeložit (DNS)';
        }
        if (preg_match('/ssl|tls|certificate/i', $message)) {
            return 'Zabezpečené spojení (TLS) selhalo';
        }
        return 'Spojení selhalo';
    }
    if (preg_match('/^Port \S+ je zavřený nebo nedostupný/u', $message)) {
        return 'Port je zavřený nebo nedostupný';
    }
    if (str_starts_with($message, 'TS3 Query port') || str_starts_with($message, 'Chyba komunikace s TS3 ServerQuery')) {
        return 'TeamSpeak ServerQuery neodpovídá';
    }
    if (str_starts_with($message, 'Discord API neodpovídá')) {
        return 'Discord API neodpovídá nebo server neexistuje';
    }
    static $fixed = [
        'Minecraft server je podle API vypnutý.',
        'Prázdná odpověď od MC serveru (timeout nebo nepodporovaný protokol), i po opakovaném pokusu.',
        'Neočekávané ID paketu od MC serveru',
        'Nelze dekódovat JSON stav Minecraft serveru',
        'Heartbeat monitor nemá nastavený interval, takže není podle čeho poznat zpoždění.',
        'Zatím nepřišel žádný signál - úloha se ještě ani jednou neohlásila.',
    ];
    return in_array($message, $fixed, true) ? $message : null;
}

/**
 * An incident update as the public status page may show it.
 *
 * Updates are written by people and by the outage lifecycle. The lifecycle
 * appends the raw check failure ("Důvod: Chybí běžící proces: nginx") and the
 * incident actions prefix the operator's username; neither is public.
 */
function bk_public_incident_update(?string $message): ?string {
    if ($message === null) {
        return null;
    }
    $message = trim($message);
    if (str_starts_with($message, 'Automaticky detekován výpadek.')) {
        return 'Automaticky detekován výpadek.';
    }
    if (str_starts_with($message, 'Incident převzal:')) {
        return 'Incident převzat.';
    }
    $message = trim((string)preg_replace('/^\[[^\]\r\n]{1,100}\]\s*/u', '', $message));
    return $message === '' ? null : $message;
}

/**
 * Looks up the monitor a chart request means - the exact monitor id first,
 * otherwise a monitor of that asset - among the monitors the viewer may see,
 * and returns the executed statement so the caller keeps its own fetch and
 * its own "not found" answer. An anonymous caller gets 401; a monitor the
 * viewer may not see is simply not found.
 *
 * @param string $columns Column list written in code, e.g. 'id, type'.
 */
function bk_visible_monitor_stmt(PDO $pdo, int $id, string $columns): PDOStatement {
    bk_require_login();
    if (!preg_match('/^[a-z_][a-z0-9_]*(\s*,\s*[a-z_][a-z0-9_]*)*$/i', $columns)) {
        throw new InvalidArgumentException('Invalid column list: ' . $columns);
    }
    [$scope, $scope_params] = bk_monitor_scope_sql(bk_visible_monitor_ids($pdo), 'id');
    $stmt = $pdo->prepare("SELECT {$columns} FROM monitors WHERE (id = ? OR asset_id = ?) AND {$scope} ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, id LIMIT 1");
    $stmt->execute(array_merge([$id, $id], $scope_params, [$id]));
    return $stmt;
}

// Every entry point reaches here with its session started. Correct the role
// before any access check reads $_SESSION['admin_role'] directly.
if (PHP_SAPI !== 'cli' && isset($pdo) && $pdo instanceof PDO) {
    bk_sync_session_account($pdo);
}
