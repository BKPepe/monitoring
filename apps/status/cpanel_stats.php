<?php
/**
 * cPanel Resource Exporter for Blood Kings Status Monitoring
 * 
 * Place this file into the root of your cPanel site (e.g. public_html/cpanel_stats.php).
 * And set the token in STATS_KEY below. The key must match the monitor's configuration.
 */

// Load the key from an external config file when present (prevents overwrite on git deploy)
if (file_exists(__DIR__ . '/cpanel_config.php')) {
    include_once __DIR__ . '/cpanel_config.php';
}

// Resolve the key (environment variable -> defined constant -> default placeholder)
$configured_key = getenv('STATS_KEY') ?: (defined('STATS_KEY') ? STATS_KEY : 'YOUR_SECURE_TOKEN_HERE');

// Deny unauthorised access without the right key in the URL (?key=...)
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

// Default response structure
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
 * Helper converting a human-readable value (e.g. "1.23 GB", "100 MB", "0 bytes") to bytes
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
 * Helper printing bytes in a human-readable format (KB, MB, GB, ...)
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

// 1. Try the cPanel CLI UAPI for the statistics
$output = null;
if (function_exists('shell_exec')) {
    // Run uapi with JSON output for the selected metrics, cpuusage newly added
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
            
            // Formatting by metric type
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

// 2. Safe fallbacks for servers where UAPI does not work (e.g. a VPS or other shared hosting)

// Disk usage fallback (standard PHP functions)
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

// RAM fallback from /proc/meminfo system info
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

// CPU fallback: StatsBar returns 'cpuusage' only on CloudLinux/LVE hosts.
// Elsewhere the whole machine's load average relative to core count is used -
// a real number about server health (though not per-account), and labelled as
// such via 'source' and in 'formatted'. Percent is deliberately not capped at 100,
// overload > 100 % is exactly the information we want to see.
if (empty($stats['cpu'])) {
    $load1 = null;
    if (function_exists('sys_getloadavg')) {
        $la = @sys_getloadavg();
        if (is_array($la) && isset($la[0])) $load1 = floatval($la[0]);
    }
    if ($load1 === null && @is_readable('/proc/loadavg')) {
        $la_raw = @file_get_contents('/proc/loadavg');
        if ($la_raw !== false) $load1 = floatval(strtok($la_raw, ' '));
    }
    if ($load1 !== null) {
        $cores = 0;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo) $cores = preg_match_all('/^processor\s*:/m', $cpuinfo);
        if ($cores > 0) {
            $stats['cpu'] = [
                'used' => $load1,
                'limit' => $cores,
                'percent' => round(($load1 / $cores) * 100, 2),
                'formatted' => "load {$load1} / {$cores} jader (celý server)",
                'source' => 'loadavg'
            ];
        }
    }
}

// When detailed process/database metrics are unavailable, they stay null,
// which the status page recognises and does not chart.

echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
