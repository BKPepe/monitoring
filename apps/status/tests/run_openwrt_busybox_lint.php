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
];

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
