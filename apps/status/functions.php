<?php
/**
 * Monitorovací funkce a odesílání notifikací
 */

require_once __DIR__ . '/db.php';

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
                <strong style="color: #fff;" class="stat-val"><?php echo $cpu; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $cpu_color; ?>" style="width: <?php echo $cpu; ?>%"></div>
            </div>
        </div>
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Physical Memory Usage</span>
                <strong style="color: #fff;" class="stat-val"><?php echo $ram; ?>%</strong>
            </div>
            <div class="chart-bar-container" style="height: 6px;">
                <div class="chart-bar-fill <?php echo $ram_color; ?>" style="width: <?php echo $ram; ?>%"></div>
            </div>
        </div>
        <div>
            <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 0.25rem;">
                <span style="color: var(--text-secondary);">Disk (HDD Usage)</span>
                <strong style="color: #fff;" class="stat-val"><?php echo $hdd; ?>%</strong>
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
                        <strong style="color: #fff;"><?php echo htmlspecialchars($v_reported); ?></strong>
                        <?php if ($has_update && $is_admin): ?>
                            <span style="background: rgba(243, 156, 18, 0.15); border: 1px solid rgba(243, 156, 18, 0.25); color: #f39c12; padding: 0.05rem 0.35rem; border-radius: 4px; font-size: 0.65rem; margin-left: 0.35rem;" title="Nová verze <?php echo $latest_v; ?> je k dispozici. Stáhněte nový agent skript ze sekce návodu níže."><i class="fas fa-arrow-up"></i> Aktualizace</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($details['os'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Operační systém:</span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($details['os']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['hostname'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Hostname:</span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($details['hostname']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['kernel'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Kernel:</span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($details['kernel']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['timezone'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Časové pásmo:</span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($details['timezone']); ?></strong>
                </div>
            <?php endif; ?>
            <?php if (!empty($details['cloud_provider']) || !empty($details['virtualization'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Poskytovatel / virtualizace:</span>
                    <strong style="color: #fff;">
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
                    <strong style="color: <?php echo $details['iowait'] > 20 ? 'var(--color-red)' : (($details['iowait'] > 10) ? 'var(--color-yellow)' : '#fff'); ?>;"><?php echo $details['iowait']; ?>%</strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['inode_usage']) && $details['inode_usage'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Zaplnění inodů:</span>
                    <strong style="color: <?php echo $details['inode_usage'] > 90 ? 'var(--color-red)' : (($details['inode_usage'] > 70) ? 'var(--color-yellow)' : '#fff'); ?>;"><?php echo $details['inode_usage']; ?>%</strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['zombie_count']) && $details['zombie_count'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Zombie procesy:</span>
                    <strong style="color: <?php echo $details['zombie_count'] > 5 ? 'var(--color-red)' : '#fff'; ?>;"><?php echo (int)$details['zombie_count']; ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['fork_rate']) && $details['fork_rate'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Nové procesy (od posl. kontroly):</span>
                    <strong style="color: #fff;"><?php echo (int)$details['fork_rate']; ?></strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['temperature']) && $details['temperature'] !== null): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Teplota:</span>
                    <strong style="color: <?php echo $details['temperature'] > 80 ? 'var(--color-red)' : (($details['temperature'] > 65) ? 'var(--color-yellow)' : '#fff'); ?>;"><?php echo $details['temperature']; ?>°C</strong>
                </div>
            <?php endif; ?>
            <?php if (isset($details['uptime'])): ?>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Uptime serveru:</span>
                    <strong style="color: #fff;"><?php echo format_uptime_cz($details['uptime']); ?></strong>
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

            <?php if (!empty($details['top_cpu_processes']) || !empty($details['top_ram_processes'])): ?>
                <div style="margin-top: 0.25rem; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 0.45rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <?php if (!empty($details['top_cpu_processes'])): ?>
                        <div>
                            <span style="color: var(--text-muted); display: block; margin-bottom: 0.25rem;">TOP CPU procesy:</span>
                            <div style="display: flex; flex-direction: column; gap: 0.2rem;">
                                <?php foreach ($details['top_cpu_processes'] as $tp): ?>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.7rem;">
                                        <span style="color: #e1e1e6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($tp['name'] ?? '?'); ?></span>
                                        <strong style="color: #fff; margin-left: 0.5rem; white-space: nowrap;"><?php echo htmlspecialchars((string)($tp['cpu'] ?? 0)); ?>%</strong>
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
                                        <span style="color: #e1e1e6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($tp['name'] ?? '?'); ?></span>
                                        <strong style="color: #fff; margin-left: 0.5rem; white-space: nowrap;"><?php echo htmlspecialchars((string)($tp['ram_mb'] ?? 0)); ?> MB</strong>
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
function bk_get_knowledge_tips($monitor, $details, $check_stages, $status, $enabled_metrics) {
    $tips = [];
    $add = function ($severity, $tip_key, ...$args) use (&$tips) {
        $text = $args ? sprintf(t($tip_key), ...$args) : t($tip_key);
        $tips[] = [
            'icon' => $severity === 'critical' ? 'fa-exclamation-circle' : 'fa-exclamation-triangle',
            'severity' => $severity,
            'text' => $text,
        ];
    };

    // --- VPS / agent (platí pro jakýkoli typ s propojeným agentem, stejně
    // jako render_vps_agent_details() sama není omezená na type=vps) ---
    if (is_array($details)) {
        if (isset($details['cpu'])) {
            $cpu = floatval($details['cpu']);
            if ($cpu > 80) $add('critical', 'knowledge_tip_cpu_high');
            elseif ($cpu > 50) $add('warn', 'knowledge_tip_cpu_high');
        }
        if (isset($details['ram'])) {
            $ram = floatval($details['ram']);
            if ($ram > 85) $add('critical', 'knowledge_tip_ram_high');
            elseif ($ram > 60) $add('warn', 'knowledge_tip_ram_high');
        }
        if (isset($details['hdd'])) {
            $hdd = floatval($details['hdd']);
            if ($hdd > 90) $add('critical', 'knowledge_tip_hdd_high');
            elseif ($hdd > 70) $add('warn', 'knowledge_tip_hdd_high');
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
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $http_response_header[0], $matches);
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
function check_minecraft($host, $port = 25565, $timeout = 3) {
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
function bk_get_enabled_metrics($monitor) {
    $profile = get_service_profiles()[$monitor['type'] ?? ''] ?? null;
    if (!$profile || empty($profile['metrics'])) {
        return null;
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

    // Zvolíme virtuální server na hlasovém portu (nezměněno oproti dřívější verzi)
    @fwrite($socket, "use port=$voice_port\n");
    @fgets($socket, 256); // přečíst odpověď (error id=0 msg=ok)

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
        'name' => $details['virtualserver_name'] ?? 'TeamSpeak Server',
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
        if (isset($http_response_header) && isset($http_response_header[0])) {
            preg_match('{HTTP\/\S*\s(\d\d\d)}', $http_response_header[0], $matches);
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
        'name' => $data['name'] ?? 'Discord Server',
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
            return true;
        } catch (Exception $e) {
            $GLOBALS['last_mail_error'] = $mail->ErrorInfo ?? $e->getMessage();
            return false;
        }
    }
    
    // Záloha: PHP mail() bez SMTP autentizace (funguje jen pokud webhosting povoluje)
    $from = !empty($smtp_user) ? $smtp_user : 'status@bloodkings.eu';
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
function trigger_notifications($pdo, $monitor, $new_status, $error_msg = '') {
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
    }
    $subject = "$emoji $status_text: $name";
    
    // Načtení všech příjemců notifikací (odběratelé + administrátoři bez přímého nastavení odběru)
    $stmt = $pdo->prepare("
        SELECT u.id, u.email, u.phone, u.role, u.whatsapp_apikey,
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
    
    // Vše inline (+ reálné <table>), ne v <style> bloku - Gmail, Outlook a
    // většina webmailů <style>/<head> při doručení ořízne, e-mail by dorazil
    // bez formátování. Viz stejný přístup u render_email_wrapper() (digest).
    $font = "font-family: Arial, Helvetica, sans-serif;";
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
                                <span style="display:inline-block; padding:6px 12px; border-radius:4px; font-weight:bold; color:#ffffff; background-color:' . $color_theme . '; margin-bottom:20px; text-transform:uppercase; ' . $font . '">' . $status_text . '</span>
                                <p style="' . $font . '">Upozorňujeme na změnu stavu vašeho monitorovaného serveru/služby.</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#12121a; margin:20px 0;">
                                    <tr>
                                        <td style="border-left:3px solid #ff4444; padding:15px; ' . $font . '">
                                            <strong>Název:</strong> ' . htmlspecialchars($name) . '<br>
                                            <strong>Typ:</strong> ' . htmlspecialchars(strtoupper($type)) . '<br>
                                            <strong>Cíl:</strong> ' . htmlspecialchars($target) . ($port ? ':'.$port : '') . '<br>
                                            <strong>Čas změny:</strong> ' . $time . '<br>
                                            ' . (!empty($error_msg) ? '<strong>Popis/Chyba:</strong> ' . htmlspecialchars($error_msg) . '<br>' : '') . '
                                        </td>
                                    </tr>
                                </table>
                                <p style="' . $font . '">Systém bude nadále monitorovat tuto službu a obdržíte další upozornění, jakmile se její stav změní.</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:15px 30px; text-align:center; font-size:12px; color:#888896; border-top:1px solid #22222f; background-color:#12121a; ' . $font . '">
                                Tento e-mail byl automaticky generován systémem Blood Kings.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>';
    
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
        // E-mailové notifikace
        if ($rec['email_notifications'] && !empty($rec['email'])) {
            send_email($rec['email'], $subject, $html_body);
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

    // Odeslání systémových webhooků (Discord, Slack, Telegram) - spouští se pouze 1x na událost
    $discord_webhook = get_setting('discord_webhook_url');
    $telegram_token = get_setting('telegram_bot_token');
    $telegram_chat = get_setting('telegram_chat_id');
    $slack_webhook = get_setting('slack_webhook_url');

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
            SUM(CASE WHEN status != 'maintenance' THEN 1 ELSE 0 END) as total_count,
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
               SUM(CASE WHEN status != 'maintenance' THEN 1 ELSE 0 END) as total_count,
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
        $regions[] = [
            'name' => $r['checked_from'],
            'uptime' => $r['total_count'] > 0 ? round(($r['up_count'] / $r['total_count']) * 100, 2) : 100.0,
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
               SUM(CASE WHEN l.status != 'maintenance' THEN 1 ELSE 0 END) as total_count
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
        SELECT monitor_name, monitor_type, event_type, description, occurred_at
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
            $new_servers[] = $ev['monitor_name'];
        } elseif ($ev['event_type'] === 'monitor_removed') {
            $removed_servers[] = $ev['monitor_name'];
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
            'label' => ($pct_change < 0 ? 'Latency improved' : 'Latency increased'),
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
                'location' => $s['checked_from'] ?: 'Main Server',
                'reason' => $s['error_message'] ?: 'Nespecifikovaná chyba spojení',
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
            $recommendations[] = "Certifikát pro {$c['name']} vypršel.";
        } else {
            $recommendations[] = "Certifikát pro {$c['name']} vyprší za {$c['days_remaining']} dní.";
        }
    }
    foreach ($agent_health as $ah) {
        if ($ah['cpu_usage'] >= $ah['cpu_threshold']) $recommendations[] = "Vytížení CPU na {$ah['name']} přesahuje {$ah['cpu_threshold']}%.";
        if ($ah['ram_usage'] >= $ah['ram_threshold']) $recommendations[] = "Vytížení RAM na {$ah['name']} přesahuje {$ah['ram_threshold']}%.";
        if ($ah['hdd_usage'] >= $ah['hdd_threshold']) $recommendations[] = "Zaplnění disku na {$ah['name']} přesahuje {$ah['hdd_threshold']}%.";
    }
    if ($dns_failures > 0) {
        $recommendations[] = "DNS selhává u {$dns_failures} monitorovaných domén.";
    }
    // Monitory bez IPv6 (aktuální last_details, jen 'web' typ)
    $stmt_ipv6 = $pdo->query("SELECT name, last_details FROM monitors WHERE type = 'web'");
    foreach ($stmt_ipv6->fetchAll() as $m) {
        $ld = json_decode($m['last_details'] ?? '', true);
        if (is_array($ld) && ($ld['has_ipv4'] ?? false) && empty($ld['has_ipv6'])) {
            $recommendations[] = "IPv6 není dostupné na {$m['name']}.";
        }
    }
    if ($perf_worst !== null && $perf_worst['avg_latency'] !== null && $perf_worst['avg_latency'] > 200) {
        $recommendations[] = "Vysoká latence z lokace {$perf_worst['name']} ({$perf_worst['avg_latency']} ms).";
    }
    $warning_count = count($recommendations);

    // --- Executive Summary (pravidly generované věty, ne AI) ---
    $executive_summary = [];
    if ($score >= 95) {
        $executive_summary[] = '🟢 Infrastruktura je zdravá.';
    } elseif ($score >= 80) {
        $executive_summary[] = '🟡 Infrastruktura je většinou zdravá, je tu pár otevřených bodů.';
    } else {
        $executive_summary[] = '🔴 Infrastruktura potřebuje pozornost.';
    }
    $executive_summary[] = "Dostupnost za období: " . number_format($availability, 3, ',', ' ') . " %.";
    if ($trend_latency === 'down') {
        $executive_summary[] = 'Průměrná latence se zlepšila.';
    } elseif ($trend_latency === 'up') {
        $executive_summary[] = 'Průměrná latence se zhoršila.';
    }
    if ($incident_count === 0) {
        $executive_summary[] = 'Žádné výpadky v tomto období.';
    } elseif ($biggest_incident !== null) {
        $executive_summary[] = 'Zaznamenán ' . ($incident_count > 1 ? "{$incident_count} incidentů" : '1 incident') . ", nejvýznamnější: {$biggest_incident['monitor']} ({$biggest_incident['location']}).";
    }
    if (!empty($recommendations)) {
        $executive_summary[] = 'Doporučená akce: ' . $recommendations[0];
    } else {
        $executive_summary[] = 'Žádná kritická akce není potřeba.';
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
               SUM(CASE WHEN status != 'maintenance' THEN 1 ELSE 0 END) as total_count
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
    // MySQL DAYOFWEEK: 1=neděle..7=sobota -> mapujeme na Po-Ne pro zobrazení
    $dow_map = [2 => 'Po', 3 => 'Út', 4 => 'St', 5 => 'Čt', 6 => 'Pá', 7 => 'So', 1 => 'Ne'];
    $incident_heatmap = ['Po' => 0, 'Út' => 0, 'St' => 0, 'Čt' => 0, 'Pá' => 0, 'So' => 0, 'Ne' => 0];
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
    $period_label = $is_monthly ? 'Monthly Infrastructure Report' : 'Weekly Infrastructure Report';
    $score_color = $data['score'] >= 90 ? '#1ec773' : ($data['score'] >= 70 ? '#f39c12' : '#ef233c');
    $accent_color = $data['score'] >= 70 ? '#1ec773' : '#c1121f';

    $body = '';

    // --- Hero: Infrastructure Score ---
    $score_delta_html = '';
    if ($data['score_prev'] !== null) {
        $delta = $data['score'] - $data['score_prev'];
        $delta_color = $delta > 0 ? '#1ec773' : ($delta < 0 ? '#ef233c' : '#888896');
        $delta_sign = $delta > 0 ? '+' : '';
        $score_delta_html = '<div style="margin-top:6px; font-size:13px; color:' . $delta_color . ';">' . bk_trend_glyph($data['trend_score']) . ' ' . $delta_sign . $delta . ' oproti minulému období</div>';
    }
    $body .= '<div style="text-align:center; margin-bottom:28px;">
        <div style="font-size:11px; color:#888896; text-transform:uppercase; letter-spacing:0.05em;">Infrastructure Score</div>
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
    $stat_html = bk_email_stat_box(number_format($data['availability'], 3, ',', ' ') . '%', 'Availability')
        . bk_email_stat_box(($data['avg_latency'] !== null ? $data['avg_latency'] . ' ms' : 'N/A'), 'Latency')
        . bk_email_stat_box($data['incident_count'], 'Incidents')
        . bk_email_stat_box($data['warning_count'], 'Warnings');
    $stat_html2 = bk_email_stat_box(number_format($data['total_checks'], 0, ',', ' '), 'Checks')
        . bk_email_stat_box($data['agent_count'], 'Agents')
        . bk_email_stat_box($data['region_count'], 'Regions')
        . bk_email_stat_box($is_monthly ? ($data['monthly']['sla_goal'] . '%') : '&mdash;', $is_monthly ? 'SLA Goal' : '');
    $body .= bk_email_section('Operational Overview', bk_email_stat_grid($stat_html) . bk_email_stat_grid($stat_html2));

    // --- Trend ---
    $trend_html = bk_email_kv('Availability', bk_trend_glyph($data['trend_availability']))
        . bk_email_kv('Latency', bk_trend_glyph($data['trend_latency'], false))
        . bk_email_kv('DNS', bk_trend_glyph($data['trend_dns']));
    if ($data['avg_cpu'] !== null) {
        $trend_html .= bk_email_kv('CPU', bk_trend_glyph($data['trend_cpu'], false)) . bk_email_kv('RAM', bk_trend_glyph($data['trend_ram'], false));
    }
    $body .= bk_email_section('Trend', $trend_html);

    // --- Nejlepší / nejhorší monitory ---
    if (!empty($data['best_monitors']) || !empty($data['worst_monitors'])) {
        $bw_html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:collapse; font-size:13px; margin-top:4px;"><thead><tr>'
            . '<th style="text-align:left; padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;">Monitor</th>'
            . '<th style="text-align:right; padding:7px 10px; color:#888896; font-size:11px; text-transform:uppercase; border-bottom:1px solid #22222f;">Dostupnost</th>'
            . '</tr></thead><tbody>';
        foreach ($data['best_monitors'] as $m) {
            $bw_html .= '<tr><td style="padding:7px 10px; border-top:1px solid #22222f; color:#e1e1e6;">' . htmlspecialchars($m['name']) . '</td><td style="padding:7px 10px; border-top:1px solid #22222f; text-align:right; color:#1ec773;">100%</td></tr>';
        }
        foreach ($data['worst_monitors'] as $m) {
            $u = $m['total_count'] > 0 ? round(($m['up_count'] / $m['total_count']) * 100, 2) : 100.0;
            $bw_html .= '<tr><td style="padding:7px 10px; border-top:1px solid #22222f; color:#e1e1e6;">' . htmlspecialchars($m['name']) . '</td><td style="padding:7px 10px; border-top:1px solid #22222f; text-align:right; color:#ef233c;">' . $u . '%</td></tr>';
        }
        if (empty($data['worst_monitors'])) {
            $bw_html .= '<tr><td colspan="2" style="padding:7px 10px; border-top:1px solid #22222f; color:#888896;">Žádné výpadky v tomto období.</td></tr>';
        }
        $bw_html .= '</tbody></table>';
        $body .= bk_email_section('Nejlepší / nejhorší monitory', $bw_html);
    }

    // --- Největší změny ---
    if (!empty($data['biggest_changes'])) {
        $chg_html = '';
        foreach ($data['biggest_changes'] as $c) {
            $color = $c['is_good'] ? '#1ec773' : '#ef233c';
            $chg_html .= bk_email_kv($c['label'] . ' — ' . $c['detail'], '<span style="color:' . $color . ';">' . $c['delta_text'] . '</span>');
        }
        $body .= bk_email_section('Největší změny', $chg_html);
    }

    // --- Region overview ---
    if (!empty($data['regions'])) {
        $reg_html = bk_email_report_table_open(['Region', 'Dostupnost', 'Latence']);
        foreach ($data['regions'] as $r) {
            $reg_html .= bk_email_report_table_row([
                ['html' => htmlspecialchars($r['name'])],
                ['html' => $r['uptime'] . '%'],
                ['html' => ($r['avg_latency'] !== null ? $r['avg_latency'] . ' ms' : 'N/A')],
            ]);
        }
        $reg_html .= '</tbody></table>';
        $body .= bk_email_section('Region Overview', $reg_html);
    }

    // --- Agent Health ---
    if (!empty($data['agent_health'])) {
        $ah_html = bk_email_report_table_open(['Agent', 'CPU', 'RAM', 'Disk']);
        foreach ($data['agent_health'] as $ah) {
            $ah_html .= bk_email_report_table_row([
                ['html' => htmlspecialchars($ah['name'])],
                ['html' => $ah['cpu_usage'] . '%'],
                ['html' => $ah['ram_usage'] . '%'],
                ['html' => $ah['hdd_usage'] . '%'],
            ]);
        }
        $ah_html .= '</tbody></table>';
        $body .= bk_email_section('Agent Health', $ah_html);
    }

    // --- SSL ---
    $ssl_html = bk_email_kv('Expiring', $data['ssl']['expiring']) . bk_email_kv('Renewed', $data['ssl']['renewed']) . bk_email_kv('Expired', $data['ssl']['expired']);
    $body .= bk_email_section('SSL Certificates', $ssl_html);

    // --- DNS ---
    $dns_html = bk_email_kv('DNS Failures', $data['dns']['failures']) . bk_email_kv('Slow Responses (>200ms)', $data['dns']['slow']);
    $body .= bk_email_section('DNS', $dns_html);

    // --- Biggest incident ---
    if ($data['biggest_incident'] !== null) {
        $bi = $data['biggest_incident'];
        $dur_min = round($bi['duration_sec'] / 60);
        $status_color = $bi['resolved'] ? '#1ec773' : '#ef233c';
        $status_text = $bi['resolved'] ? 'Resolved' : 'Ongoing';
        $bi_html = '<div style="background-color:#12121a; border-radius:6px; padding:16px;">'
            . '<div style="font-size:15px; font-weight:bold; color:#ffffff;">' . htmlspecialchars($bi['monitor']) . '</div>'
            . '<div style="font-size:13px; color:#888896; margin-top:2px;">' . htmlspecialchars($bi['location']) . ' &middot; ' . htmlspecialchars($bi['date']) . '</div>'
            . '<div style="font-size:13px; color:#e1e1e6; margin-top:8px;">' . htmlspecialchars($bi['reason']) . '</div>'
            . '<div style="margin-top:10px; font-size:13px;"><span style="color:#888896;">Doba trvání (odhad):</span> <strong style="color:#ffffff;">' . $dur_min . ' min</strong> &middot; <span style="color:' . $status_color . '; font-weight:bold;">' . $status_text . '</span></div>'
            . '</div>';
        $body .= bk_email_section('Největší incident', $bi_html);
    }

    // --- Performance ---
    $perf_html = bk_email_kv('Average latency', ($data['performance']['avg'] !== null ? $data['performance']['avg'] . ' ms' : 'N/A') . ' ' . bk_trend_glyph($data['performance']['trend'], false));
    if ($data['performance']['best'] !== null) {
        $perf_html .= bk_email_kv('Best', htmlspecialchars($data['performance']['best']['name']) . ' &middot; ' . $data['performance']['best']['avg_latency'] . ' ms');
    }
    if ($data['performance']['worst'] !== null) {
        $perf_html .= bk_email_kv('Worst', htmlspecialchars($data['performance']['worst']['name']) . ' &middot; ' . $data['performance']['worst']['avg_latency'] . ' ms');
    }
    $body .= bk_email_section('Performance', $perf_html);

    // --- Nové / odstraněné servery ---
    if (!empty($data['new_servers']) || !empty($data['removed_servers'])) {
        $ns_html = '';
        foreach ($data['new_servers'] as $s) {
            $ns_html .= '<div style="color:#1ec773; font-size:13px; padding:3px 0;">+ ' . htmlspecialchars($s) . '</div>';
        }
        foreach ($data['removed_servers'] as $s) {
            $ns_html .= '<div style="color:#ef233c; font-size:13px; padding:3px 0;">- ' . htmlspecialchars($s) . '</div>';
        }
        $body .= bk_email_section('Nové / odstraněné servery', $ns_html);
    }

    // --- Změny konfigurace ---
    if (!empty($data['config_change_examples'])) {
        $cc_html = '';
        foreach ($data['config_change_examples'] as $c) {
            $cc_html .= '<div style="font-size:13px; padding:3px 0; color:#e1e1e6;">&middot; ' . htmlspecialchars($c) . '</div>';
        }
        $body .= bk_email_section('Změny konfigurace', $cc_html);
    }

    // --- Monthly-only sekce ---
    if ($is_monthly && isset($data['monthly'])) {
        $mo = $data['monthly'];

        $sla_reached = $data['availability'] >= $mo['sla_goal'];
        $sla_html = bk_email_kv('SLA', number_format($data['availability'], 3, ',', ' ') . '%')
            . bk_email_kv('Goal', $mo['sla_goal'] . '%')
            . bk_email_kv('Stav', '<span style="color:' . ($sla_reached ? '#1ec773' : '#ef233c') . ';">' . ($sla_reached ? 'Splněno' : 'Nesplněno') . '</span>');
        $body .= bk_email_section('SLA', $sla_html);

        if ($mo['best_day'] !== null || $mo['worst_day'] !== null) {
            $day_html = '';
            if ($mo['best_day'] !== null) $day_html .= bk_email_kv('Nejlepší den', $mo['best_day']['date'] . ' &middot; ' . $mo['best_day']['uptime'] . '%');
            if ($mo['worst_day'] !== null) $day_html .= bk_email_kv('Nejhorší den', $mo['worst_day']['date'] . ' &middot; ' . $mo['worst_day']['uptime'] . '%');
            $body .= bk_email_section('Nejlepší / nejhorší den', $day_html);
        }

        if ($mo['best_region'] !== null || $mo['worst_region'] !== null) {
            $reg2_html = '';
            if ($mo['best_region'] !== null) $reg2_html .= bk_email_kv('Nejlepší region', htmlspecialchars($mo['best_region']['name']) . ' &middot; ' . $mo['best_region']['uptime'] . '%');
            if ($mo['worst_region'] !== null) $reg2_html .= bk_email_kv('Nejhorší region', htmlspecialchars($mo['worst_region']['name']) . ' &middot; ' . $mo['worst_region']['uptime'] . '%');
            $body .= bk_email_section('Nejlepší / nejhorší region', $reg2_html);
        }

        // Incident heatmap - barevné buňky tabulky (email klienti neumí CSS grid)
        $hm_html = '<table style="width:100%; border-collapse:collapse;"><tr>';
        foreach ($mo['incident_heatmap'] as $day => $cnt) {
            $bgcolor = $cnt === 0 ? '#1ec773' : ($cnt <= 2 ? '#f39c12' : '#ef233c');
            $hm_html .= '<td bgcolor="' . $bgcolor . '" style="background-color:' . $bgcolor . '; text-align:center; font-size:11px; color:#0f0f13; font-weight:bold; padding:8px 0;">' . htmlspecialchars($day) . '<br>' . $cnt . '</td>';
        }
        $hm_html .= '</tr></table>';
        $body .= bk_email_section('Incident Heatmap (dle dne v týdnu)', $hm_html);

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
            $body .= bk_email_section('Latency Heatmap', $lhm_html);
        }

        $growth_html = bk_email_stat_box('+' . $mo['growth']['new_monitors'], 'New Monitors') . bk_email_stat_box('+' . $mo['growth']['new_users'], 'New Users');
        $body .= bk_email_section('Growth', bk_email_stat_grid($growth_html));

        if ($mo['score_last_month'] !== null) {
            $score_cmp_html = bk_email_kv('Last Month', $mo['score_last_month']) . bk_email_kv('This Month', $data['score']);
            $body .= bk_email_section('Infrastructure Health Score', $score_cmp_html);
        }
    }

    // --- Recommendations ---
    $rec_html = '';
    if (empty($data['recommendations'])) {
        $rec_html = '<div style="font-size:13px; color:#1ec773;">Žádná doporučení - vše vypadá v pořádku.</div>';
    } else {
        foreach ($data['recommendations'] as $r) {
            $rec_html .= '<div style="font-size:13px; padding:4px 0; color:#e1e1e6;">&bull; ' . htmlspecialchars($r) . '</div>';
        }
    }
    $body .= bk_email_section('Recommendations', $rec_html);

    $subtitle = htmlspecialchars($data['site_title']) . ' &middot; ' . $data['range_from'] . ' &ndash; ' . $data['range_to'];
    return render_email_wrapper('📊 ' . $period_label, $subtitle, $accent_color, $body);
}

function send_digest_report_inner($pdo, $period = 'weekly') {
    $data = build_digest_data($pdo, $period);
    $html_body = render_digest_html($data);

    $period_label = ($period === 'monthly') ? 'Monthly' : 'Weekly';
    $subject = "📊 $period_label Infrastructure Report – {$data['site_title']} ({$data['range_from']} – {$data['range_to']})";

    // Příjemci - všichni administrátoři se zadaným e-mailem
    $stmt_admins = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''");
    $admin_emails = $stmt_admins->fetchAll(PDO::FETCH_COLUMN);
    if (empty($admin_emails)) {
        $GLOBALS['last_mail_error'] = 'Žádný administrátor nemá vyplněný e-mail.';
        return false;
    }

    $any_success = false;
    foreach ($admin_emails as $email) {
        if (send_email($email, $subject, $html_body)) {
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
            $flag = get_country_emoji($data['countryCode'] ?? 'CZ');
            $city = $data['city'] ?? 'Praha';
            $country = $data['countryCode'] ?? 'CZ';
            
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
                    'postgresql' => $data['postgresql'] ?? null
                ];
            } else {
                return [
                    'status' => 'down',
                    'response_time' => $duration,
                    'error' => 'Neplatný JSON formát nebo chybný bezpečnostní klíč.'
                ];
            }
        } else {
            return [
                'status' => 'down',
                'response_time' => $duration,
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

