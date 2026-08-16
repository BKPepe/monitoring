<?php
/**
 * Hlídá, že číselná hodnota od agenta někde skončí - v metrikách, nebo
 * vědomě jen v detailech.
 *
 * Spuštění:  php apps/status/tests/run_agent_metric_lint.php
 *
 * Proč vznikl: agenti posílali každou minutu 35 číselných hodnot, ze kterých
 * se ukládal jen poslední snímek do `last_details`. Šlo tedy vidět aktuální
 * počet TCP retransmisí, ale ne jestli je to špička nebo normál. Nastřádalo
 * se to postupně, protože přidat klíč do agenta je jednodušší než doplnit
 * sloupec, mapu metrik a graf - a nic na ten rozdíl neupozorňovalo.
 *
 * Kontroluje se jen to, co je opravdu metrika. Stavy (`wan_up`), texty
 * (`wan_proto`), struktury (`interfaces`) a jednorázová metadata (`kernel`)
 * do tabulky metrik nepatří - patří do detailů nebo mezi události, a jsou
 * proto na seznamu výjimek.
 */

$root = realpath(__DIR__ . '/..');

// Viz run_agent_honesty_lint.php: cte se vyhradne ze submodulu, zadna zaloha
// na apps/status - ta by lint nechala projit i bez nej.
$agent_dir = $root . '/../../agents/vps-agent';
$agent_sources = [];
foreach (['agent.sh', 'agent_openwrt.sh'] as $name) {
    if (is_file($agent_dir . '/' . $name)) {
        $agent_sources[] = $agent_dir . '/' . $name;
    }
}
if (!$agent_sources) {
    fwrite(STDERR, "Nenasel jsem zadneho agenta v " . $agent_dir . ".\n");
    fwrite(STDERR, "Chybi submodul `agents`? Spustte: git submodule update --init\n");
    exit(1);
}

$agent_src = '';
foreach ($agent_sources as $file) {
    $agent_src .= "\n" . file_get_contents($file);
}

$api_src = file_get_contents($root . '/agent_api.php');
$fn_src = file_get_contents($root . '/functions.php');

if ($agent_src === '' || $api_src === false || $fn_src === false) {
    fwrite(STDERR, "Zdrojové soubory agentů nebo API se nepodařilo načíst.\n");
    exit(1);
}

/**
 * Klíče, které do tabulky metrik nepatří.
 *
 * Nejde o „zatím nedodělané" - jsou to hodnoty, které nejsou časovou řadou.
 * Když se objeví nová, patří sem s vysvětlením, ne mlčky.
 */
$not_metrics = [
    // Protokol a identifikace hlášení
    'agent_key', 'nonce', 'signature', 'timestamp', 'action', 'action_id',
    'agent_type', 'monitor_id', 'version', 'latest_version', 'auto_update',
    'update_available', 'update_url', 'update_sha256', 'heavy_op_interval_hours',
    // Popis stroje - mění se výjimečně, graf nedává smysl
    'hostname', 'os', 'kernel', 'model', 'board_name', 'timezone',
    'virtualization', 'cloud_provider', 'uptime', 'boot_time', 'reboot_required',
    // Stavy (true/false) - patří mezi události, ne do grafu. Čára skákající
    // mezi 0 a 1 řekne míň než záznam "WAN spadlo ve 3:14".
    'wan_up', 'lte_up', 'tailscale_up', 'sqm_enabled', 'sqm_ecn', 'dns_encryption',
    'firewall_enabled',
    // Texty a adresy
    'wan_proto', 'wan_ipv4', 'wan_ipv6', 'wan_gateway', 'wan_dns', 'wan_last_reconnect',
    'lan_subnet', 'dns_servers', 'dns_engine', 'ups_status', 'service_name',
    'lte_device', 'lte_carrier', 'lte_plmn', 'lte_band', 'lte_cell_id', 'lte_pci',
    'lte_bandwidth', 'lte_ipv4', 'mwan3_active_gw', 'port', 'process',
    // Struktury (pole/objekty) - vlastní tabulky nebo detaily
    'interfaces', 'processes', 'ports', 'top_cpu_processes', 'top_ram_processes',
    'service_checks', 'service_restarts', 'installed_packages', 'upgradable_packages',
    'usb_devices', 'discovered_services', 'smart', 'ts3_process', 'teamspeak_servers',
    'zerotier_networks', 'wifi_radios', 'mwan3_policies',
    // Pole objektu: filesystemy, disky a procesy podle zapisu. Casovou radou
    // by byla az jednotliva polozka (zaplneni konkretniho oddilu), ne cely
    // seznam - ten se navic mezi behy meni (pripojeny USB disk).
    'filesystems', 'disk_devices', 'top_io_processes',
    // Vysledky mereni rychlosti maji vlastni tabulku (speedtest_results):
    // meri se jednou denne, ne kazdou minutu, takze do rady s minutovym
    // krokem nepatri. `io_accounting` je schopnost jadra, ne metrika.
    'speedtests', 'io_accounting',
    // Kumulativní součty, u kterých se ukládá rovnou rychlost
    // (disk_io_read_kbps / disk_io_write_kbps).
    'disk_read_kb', 'disk_write_kb',
];

// Klíče, které agenti posílají.
preg_match_all('/"([a-z_0-9]+)":/', $agent_src, $sent_matches);
$sent = array_unique($sent_matches[1] ?? []);
sort($sent);

// Klíče, které agent_api.php ze vstupu čte.
preg_match_all("/\\\$data\['([a-z_0-9]+)'\]/", $api_src, $read_matches);
$read = array_flip($read_matches[1] ?? []);

// Klíče, které končí ve sloupci tabulky metrik.
$stored = [];
if (preg_match('/\$metric_row = \[(.*?)\n        \];/s', $api_src, $row_match)) {
    preg_match_all("/bk_agent_(?:num|int)\(\\\$data, '([a-z_0-9]+)'\)/", $row_match[1], $direct);
    foreach ($direct[1] ?? [] as $k) {
        $stored[$k] = true;
    }
    // Starší zápisy jdou přes proměnnou; dohledá se, z jakého klíče vznikla.
    // Hodnota může být i složený výraz ($swap ?? $ow_swap_pct), takže se
    // berou všechny proměnné, ne jen první - jinak by druhý zdroj vypadal
    // jako neuložený.
    preg_match_all('/=>\s*([^,\n]+),/', $row_match[1], $exprs);
    $vars = [];
    foreach ($exprs[1] ?? [] as $expr) {
        preg_match_all('/\$(\w+)/', $expr, $expr_vars);
        foreach ($expr_vars[1] ?? [] as $v) {
            $vars[] = $v;
        }
    }
    foreach ($vars as $var) {
        if (preg_match("/\\\$" . preg_quote($var, '/') . "\s*=\s*[^;]*?\\\$data\['([a-z_0-9]+)'\]/s", $api_src, $vm)) {
            $stored[$vm[1]] = true;
        }
    }
}

if (empty($stored)) {
    fwrite(STDERR, "V agent_api.php se nepodařilo najít \$metric_row - změnil se zápis metrik?\n");
    exit(1);
}

$ignored = array_flip($not_metrics);
$problems = [];

foreach ($sent as $key) {
    if (isset($stored[$key]) || isset($ignored[$key])) {
        continue;
    }
    // Klíč, který se sice nečte do metrik, ale API ho zpracovává jinak
    // (např. ukládá do vlastní tabulky), není chyba - jen se o něm ví.
    $where = isset($read[$key]) ? 'čte se, ale neukládá jako metrika' : 'nikdo ho nečte';
    $problems[] = "{$key} - {$where}";
}

if ($problems) {
    fwrite(STDERR, "Agenti posílají hodnoty, které nekončí v metrikách ani nejsou mezi výjimkami:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nBuď hodnotu doplňte do \$metric_row v agent_api.php (a do sloupců,\n");
    fwrite(STDERR, "migrace a mapy metrik), nebo ji zapište do seznamu \$not_metrics\n");
    fwrite(STDERR, "v tomhle skriptu i s důvodem, proč časovou řadou není.\n");
    exit(1);
}

printf(
    "Agent metric lint: %d klíčů od agentů, %d se ukládá jako metrika, %d vědomých výjimek.\n",
    count($sent),
    count($stored),
    count($not_metrics)
);
exit(0);
