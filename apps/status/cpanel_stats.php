<?php
/**
 * cPanel Resource Exporter for Blood Kings Status Monitoring
 * 
 * Umístěte tento soubor do kořenového adresáře vašeho cPanel webu (např. public_html/cpanel_stats.php).
 * A nastavit token do STATS_KEY níže. Tento klíč musí odpovídat konfiguraci monitoru.
 */

// Načtení klíče z externího konfiguračního souboru, pokud existuje (zabraňuje přepsání při git deployi)
if (file_exists(__DIR__ . '/cpanel_config.php')) {
    include_once __DIR__ . '/cpanel_config.php';
}

// Určení klíče (Environment variable -> Definovaná konstanta -> Výchozí placeholder)
$configured_key = getenv('STATS_KEY') ?: (defined('STATS_KEY') ? STATS_KEY : 'YOUR_SECURE_TOKEN_HERE');

// Zamezení neoprávněnému přístupu bez správného klíče v URL (?key=...)
if (!isset($_GET['key']) || $_GET['key'] !== $configured_key || $configured_key === 'YOUR_SECURE_TOKEN_HERE') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Přístup odepřen. Neplatný nebo nenastavený bezpečnostní klíč (?key=).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Výchozí struktura odpovědi
$stats = [
    'status' => 'ok',
    'timestamp' => time(),
    'disk' => null,
    'memory' => null,
    'processes' => null,
    'database' => null,
    'bandwidth' => null,
    'postgresql' => null
];

/**
 * Pomocná funkce pro převod lidsky čitelné hodnoty (např. "1.23 GB", "100 MB", "0 bytes") na bajty
 */
function parse_cpanel_value($val, $default_unit = '') {
    if ($val === null || $val === '') return 0;
    
    $val = trim(strtolower($val));
    $val = str_replace(array(' ', "\xc2\xa0", "\xa0", "\t", "\r", "\n", ','), '', $val);
    
    if ($val === 'unlimited') return -1;
    if ($val === 'none' || $val === '0' || $val === '0bytes' || $val === '0bytes') return 0;
    
    if (!preg_match('/[a-z]$/', $val) && !empty($default_unit)) {
        $val .= strtolower(trim($default_unit));
    }
    
    if (preg_match('/^([0-9.]+)(kb|mb|gb|tb|bytes|b|m|g|t)?$/i', $val, $matches)) {
        $num = floatval($matches[1]);
        $unit = isset($matches[2]) ? strtolower($matches[2]) : '';
        switch ($unit) {
            case 'kb':
            case 'k':
                return $num * 1024;
            case 'mb':
            case 'm':
                return $num * 1024 * 1024;
            case 'gb':
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'tb':
            case 't':
                return $num * 1024 * 1024 * 1024 * 1024;
            default:
                return $num;
        }
    }
    return floatval($val);
}

/**
 * Pomocná funkce pro zobrazení bajtů v lidsky čitelném formátu (KB, MB, GB atd.)
 */
function format_cpanel_bytes($bytes) {
    if ($bytes === null || $bytes === '') return 'N/A';
    if ($bytes < 0) return 'Bez limitu';
    if ($bytes == 0) return '0 B';
    
    // Prefer GB for values >= 100 MB
    if ($bytes >= 100 * 1024 * 1024) {
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
}

// 1. Zkusíme zavolat cPanel CLI UAPI pro získání statistik
$output = null;
if (function_exists('shell_exec')) {
    // Spustíme uapi s JSON výstupem pro vybrané metriky, nově přidáno cpuusage
    $output = @shell_exec("uapi --output=json StatsBar get_stats display='diskusage|physicalmemoryusage|numberofprocesses|bandwidthusage|cachedmysqldiskusage|cachedpostgresdiskusage|cpuusage'");
}

if ($output) {
    $json = json_decode($output, true);
    if (isset($_GET['debug'])) {
        $stats['raw_uapi_output'] = $json;
        $stats['raw_shell_output'] = $output;
    }
    if ($json && isset($json['result']['data'])) {
        foreach ($json['result']['data'] as $stat) {
            $name = $stat['name'] ?? '';
            $count = $stat['count'] ?? 0;
            $max = $stat['max'] ?? 0;
            $percent = isset($stat['percent']) ? floatval($stat['percent']) : null;
            
            $default_unit = $stat['units'] ?? '';
            $used_val = parse_cpanel_value($count, $default_unit);
            $limit_val = parse_cpanel_value($max, $default_unit);
            
            if ($percent === null && $limit_val > 0) {
                $percent = round(($used_val / $limit_val) * 100, 2);
            }
            
            // Formátování podle typu metriky
            $is_byte_metric = in_array($name, ['diskusage', 'physicalmemoryusage', 'bandwidthusage', 'cachedmysqldiskusage', 'cachedpostgresdiskusage']);
            if ($is_byte_metric) {
                $used_formatted = ($used_val >= 0) ? format_cpanel_bytes($used_val) : $count;
                $limit_formatted = ($limit_val >= 0) ? format_cpanel_bytes($limit_val) : ($max === 'unlimited' ? 'Bez limitu' : $max);
                $formatted_str = "$used_formatted / $limit_formatted";
            } else {
                $formatted_str = "$count / $max";
            }
            
            $struct = [
                'used' => $used_val,
                'limit' => $limit_val,
                'percent' => $percent !== null ? floatval($percent) : 0,
                'formatted' => $formatted_str
            ];
            
            if ($name === 'diskusage') {
                $stats['disk'] = $struct;
            } elseif ($name === 'physicalmemoryusage') {
                $stats['memory'] = $struct;
            } elseif ($name === 'numberofprocesses') {
                $stats['processes'] = $struct;
            } elseif ($name === 'bandwidthusage') {
                $stats['bandwidth'] = $struct;
            } elseif ($name === 'cachedmysqldiskusage') {
                $stats['database'] = $struct;
            } elseif ($name === 'cachedpostgresdiskusage') {
                $stats['postgresql'] = $struct;
            } elseif ($name === 'cpuusage') {
                $stats['cpu'] = $struct;
            }
        }
    }
}

// 2. Bezpečné fallbacks pro servery, kde nefunguje UAPI (např. VPS nebo jiné typy sdíleného hostingu)

// Fallback pro využití disku (standardní PHP funkce)
if (!$stats['disk']) {
    $free = @disk_free_space(__DIR__);
    $total = @disk_total_space(__DIR__);
    if ($total > 0) {
        $used = $total - $free;
        $pct = round(($used / $total) * 100, 2);
        
        $stats['disk'] = [
            'used' => $used,
            'limit' => $total,
            'percent' => $pct,
            'formatted' => round($used / 1024 / 1024 / 1024, 2) . " GB / " . round($total / 1024 / 1024 / 1024, 2) . " GB"
        ];
    }
}

// Fallback pro paměť RAM ze systémových informací /proc/meminfo
if (!$stats['memory']) {
    if (@file_exists('/proc/meminfo')) {
        $mem_data = @file_get_contents('/proc/meminfo');
        if ($mem_data && preg_match('/MemTotal:\s+(\d+)\s+kB/', $mem_data, $m1) && preg_match('/MemAvailable:\s+(\d+)\s+kB/', $mem_data, $m2)) {
            $total = intval($m1[1]) * 1024;
            $avail = intval($m2[1]) * 1024;
            $used = $total - $avail;
            $pct = round(($used / $total) * 100, 2);
            
            $stats['memory'] = [
                'used' => $used,
                'limit' => $total,
                'percent' => $pct,
                'formatted' => format_cpanel_bytes($used) . " / " . format_cpanel_bytes($total)
            ];
        }
    }
}

// Pokud nejsou dostupné detailní metriky o procesech a databázích, necháme je jako null,
// což status stránka rozpozná a nezobrazí graficky.

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
