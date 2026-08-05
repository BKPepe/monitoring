<?php
/**
 * Hledání mrtvého kódu v PHP aplikaci.
 *
 * Spuštění:  php apps/status/tests/find_dead_code.php
 *            php apps/status/tests/find_dead_code.php --strict   (nenulový exit)
 *
 * Proč: repo dlouho neslo funkce, které nikdo nevolal, a nastavení, která se
 * ukládala a nikdy nečetla. Ruční audit to našel jednou; tenhle skript to
 * dokáže kdykoli znovu a v CI hlídá, aby mrtvého kódu nepřibývalo.
 */

$strict = in_array('--strict', $argv, true);
$dir = realpath(__DIR__ . '/..');

$php_files = array_filter(
    array_merge(glob($dir . '/*.php') ?: [], glob($dir . '/tests/*.php') ?: []),
    fn($f) => !str_contains($f, '/lib/') && !str_contains($f, 'config.php')
);

$all_src = '';
foreach ($php_files as $f) {
    $all_src .= "\n" . file_get_contents($f);
}

// --- 1. Definované, ale nikde nevolané funkce ---------------------------
$defs = [];
foreach ($php_files as $f) {
    $src = file_get_contents($f);
    if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $src, $m)) {
        foreach ($m[1] as $fn) {
            $defs[$fn] = basename($f);
        }
    }
}

$dead_functions = [];
foreach ($defs as $fn => $file) {
    // Počet výskytů "jmeno(" mimo samotnou definici.
    $uses = preg_match_all('/(?<![a-zA-Z0-9_$>])' . preg_quote($fn, '/') . '\s*\(/', $all_src);
    $definitions = preg_match_all('/^\s*function\s+' . preg_quote($fn, '/') . '\s*\(/m', $all_src);
    if ($uses <= $definitions) {
        $dead_functions[$fn] = $file;
    }
}

// --- 2. Nastavení ukládaná v adminu, ale nikde nečtená -------------------
$admin = file_get_contents($dir . '/admin.php');
$saved = [];
if (preg_match('/settings_to_save\s*=\s*\[(.*?)\];/s', $admin, $m)) {
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $keys);
    $saved = $keys[1];
}
$dead_settings = [];
foreach ($saved as $key) {
    $read_direct = preg_match("/get_setting\(\s*'" . preg_quote($key, '/') . "'/", $all_src);

    // Klíče se často skládají za běhu: get_setting('oauth_' . $provider . '_client_id').
    // Detektor proto zkouší i všechny prefixy/suffixy rozdělené podtržítkem -
    // bez toho hlásil 8 falešných poplachů u OAuth nastavení.
    $read_dynamic = false;
    $parts = explode('_', $key);
    for ($i = 1; $i < count($parts) && !$read_dynamic; $i++) {
        $prefix = implode('_', array_slice($parts, 0, $i)) . '_';
        $suffix = '_' . implode('_', array_slice($parts, $i + 1));
        if (preg_match("/get_setting\(\s*'" . preg_quote($prefix, '/') . "'\s*\./", $all_src)) {
            $read_dynamic = true;
        }
        if (strlen($suffix) > 4 && preg_match("/\.\s*'" . preg_quote($suffix, '/') . "'/", $all_src)) {
            $read_dynamic = true;
        }
    }

    if (!$read_direct && !$read_dynamic) {
        $dead_settings[] = $key;
    }
}

// --- 3. Jazykové klíče definované, ale nepoužité -------------------------
$cs_lang = file_get_contents($dir . '/lang/cs.php');
preg_match_all("/^\s*'([a-z0-9_]+)'\s*=>/m", $cs_lang, $lm);
$unused_lang = [];
foreach ($lm[1] as $key) {
    if (!preg_match("/t\(\s*'" . preg_quote($key, '/') . "'/", $all_src)
        && !preg_match("/'" . preg_quote($key, '/') . "'/", str_replace($cs_lang, '', $all_src))) {
        $unused_lang[] = $key;
    }
}

// --- Výstup -------------------------------------------------------------
printf("Prohledáno %d PHP souborů, %d funkcí.\n\n", count($php_files), count($defs));

if ($dead_functions) {
    echo "Funkce bez jediného volání (" . count($dead_functions) . "):\n";
    foreach ($dead_functions as $fn => $file) {
        printf("  %s  (%s)\n", $fn, $file);
    }
    echo "\n";
} else {
    echo "Funkce bez volání: žádné\n\n";
}

if ($dead_settings) {
    echo "Nastavení ukládaná v adminu, ale nikde nečtená (" . count($dead_settings) . "):\n";
    foreach ($dead_settings as $key) {
        printf("  %s\n", $key);
    }
    echo "\n";
} else {
    echo "Mrtvá nastavení: žádná\n\n";
}

if ($unused_lang) {
    printf("Nepoužité jazykové klíče: %d (nejsou chyba, jen zbytek po refaktoru)\n", count($unused_lang));
    echo '  ' . implode(', ', array_slice($unused_lang, 0, 15)) . (count($unused_lang) > 15 ? ', …' : '') . "\n\n";
}

$problems = count($dead_functions) + count($dead_settings);
if ($strict && $problems > 0) {
    fwrite(STDERR, "Mrtvý kód nalezen ({$problems} položek).\n");
    exit(1);
}
exit(0);
