<?php
/**
 * Guards that agent_openwrt.sh only uses what OpenWrt's busybox actually has.
 *
 * Running:  php apps/status/tests/run_openwrt_busybox_lint.php
 *
 * Why it exists: busybox is built per target, and OpenWrt compiles a smaller
 * set of applets and features than the busybox:1.36 image the end-to-end
 * harness runs. Two constructs therefore passed every test and did nothing on
 * a real router:
 *
 *   stat -c %s     the log trim never ran, so /tmp filled up in RAM
 *   sort -t -k     the option is accepted and ignored, so the top process
 *                  lists ranked by the whole line and named the wrong five
 *
 * Both were found by reading the OpenWrt build config, not by testing - the
 * harness cannot see them, because its busybox has the applets. This lint is
 * the cheap standing check that they do not come back.
 *
 * Each rule names the config symbol it comes from, so it can be re-checked
 * against a build tree (package/utils/busybox/Config-defaults.in and the
 * generated busybox .config).
 */

$agent = __DIR__ . '/../../../agents/vps-agent/agent_openwrt.sh';
if (!is_file($agent)) {
    fwrite(STDERR, "Nenašel jsem agents/vps-agent/agent_openwrt.sh.\n");
    fwrite(STDERR, "Chybí submodul `agents`? Spusťte: git submodule update --init\n");
    exit(1);
}

/**
 * pattern => [what it is, why OpenWrt cannot do it, what to use instead]
 *
 * The patterns deliberately ignore comments (checked below), so the reason
 * can name the forbidden construct without tripping over itself.
 */
$rules = [
    '/(^|[|;&(`]|\$\()\s*stat\s+-/' => [
        'applet `stat`',
        'OpenWrt staví busybox bez něj (CONFIG_STAT is not set)',
        'velikost souboru zjistěte přes `wc -c < soubor`',
    ],
    '/\bsort\b[^|\n]*\s-(t|k)\b/' => [
        'třídění podle sloupce (`sort -t` / `sort -k`)',
        'busybox bez FEATURE_SORT_BIG obě volby přijme a ignoruje, takže porovná celý řádek',
        'dejte klíč na začátek řádku a použijte prosté `sort -rn`',
    ],
    // Only at a command position: `curl --connect-timeout 5` and `timeout=5`
    // are fine, `timeout 30 smartctl` is not.
    '/(^|[|;&(`]|\$\(|\b(?:then|do|else|if|elif|while|until)\s|!\s)\s*timeout\s+(-|[0-9]|"?\$)/' => [
        'applet `timeout`',
        'OpenWrt staví busybox bez něj (CONFIG_TIMEOUT is not set), příkaz skončí „not found“ a hlídaný program se vůbec nespustí',
        'pomalý příkaz spusťte odděleně na pozadí s vlastním zámkem a hlídejte jeho stáří (viz --smart-refresh)',
    ],
    '/\bstrtonum\s*\(/' => [
        'awk funkce `strtonum()`',
        'je to rozšíření gawk; busybox awk ji nezná a celý awk program skončí chybou',
        'hex převádějte přes `index("0123456789abcdef", ...)` v awk nebo `$((0x..))` v shellu',
    ],
    '/\breadlink\s+(-\w*[em]\w*|--canonicalize-(existing|missing))/' => [
        '`readlink -e` / `readlink -m`',
        'busybox umí jen `-f` (FEATURE_READLINK_FOLLOW); -e a -m jsou z GNU coreutils',
        'použijte `readlink -f` a existenci ověřte přes `[ -e ... ]`',
    ],
];

/**
 * Samples the rules are tried on before the agent is read. The harness cannot
 * see these constructs, so a pattern that stopped matching would report a clean
 * agent forever - a lint that checks nothing is worse than none.
 */
$must_catch = [
    'size=$(stat -c %s "$f")',
    'ps | sort -t: -k2 -rn | head -5',
    'timeout 30 smartctl -a /dev/sda',
    '    if timeout -t 5 iwinfo "$dev" info; then',
    'out=$(timeout "$secs" ethtool -S eth2)',
    'ok=1 && timeout 5 nslookup example.com',
    'awk \'{ v = strtonum("0x" $1) }\'',
    'p=$(readlink -e /sys/block/sda)',
    'p=$(readlink -m "$link")',
];
$must_pass = [
    'curl -s -m 20 --connect-timeout 5 "$API_URL"',
    'timeout=5',
    'wget -T "$timeout" -O - "$url"',
    'case "$(readlink -f "$(command -v top)")" in *busybox*) ;; esac',
    'sort -rn "$tmp" | head -5',
];

$broken_rules = [];
foreach ($must_catch as $sample) {
    $hit = false;
    foreach ($rules as $pattern => $_) {
        $hit = $hit || preg_match($pattern, $sample) === 1;
    }
    if (!$hit) {
        $broken_rules[] = "žádné pravidlo nezachytilo: {$sample}";
    }
}
foreach ($must_pass as $sample) {
    foreach ($rules as $pattern => [$what]) {
        if (preg_match($pattern, $sample) === 1) {
            $broken_rules[] = "pravidlo „{$what}“ chytá i nevinný řádek: {$sample}";
        }
    }
}
if ($broken_rules) {
    fwrite(STDERR, "Pravidla busybox lintu neodpovídají svým vzorkům:\n\n  " . implode("\n  ", $broken_rules) . "\n");
    exit(1);
}

$lines = file($agent, FILE_IGNORE_NEW_LINES) ?: [];
$violations = [];

foreach ($lines as $no => $line) {
    $trimmed = ltrim($line);
    // Comments explain the rules, they do not break them.
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        continue;
    }
    foreach ($rules as $pattern => [$what, $why, $instead]) {
        if (preg_match($pattern, $line)) {
            $violations[] = [
                'line' => $no + 1,
                'what' => $what,
                'why' => $why,
                'instead' => $instead,
                'code' => trim($line),
            ];
        }
    }
}

if ($violations) {
    fwrite(STDERR, "agent_openwrt.sh používá, co busybox na routeru nemá:\n\n");
    foreach ($violations as $v) {
        fwrite(STDERR, sprintf(
            "  agent_openwrt.sh:%d\n    %s\n    %s\n    Místo toho: %s\n    %s\n\n",
            $v['line'],
            $v['what'],
            $v['why'],
            $v['instead'],
            substr($v['code'], 0, 120)
        ));
    }
    fwrite(STDERR, "Tyhle konstrukce projdou v testovacím busyboxu a na routeru tiše nedělají nic.\n");
    printf("%d porušení\n", count($violations));
    exit(1);
}

printf("Busybox lint: agent_openwrt.sh nepoužívá nic mimo applety OpenWrtu (%d pravidel).\n", count($rules));
exit(0);
