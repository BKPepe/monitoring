<?php
/**
 * Dead code hunting in the PHP application.
 *
 * Running:  php apps/status/tests/find_dead_code.php
 *            php apps/status/tests/find_dead_code.php --strict   (non-zero exit)
 *
 * Why: the repo long carried functions nobody called and settings that were
 * saved and never read. A manual audit found them once; this script can do it
 * again any time and guards in CI that dead code does not accumulate.
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

// --- 1. Functions defined but never called ---------------------------------
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
    // Number of "name(" occurrences outside the definition itself.
    $uses = preg_match_all('/(?<![a-zA-Z0-9_$>])' . preg_quote($fn, '/') . '\s*\(/', $all_src);
    $definitions = preg_match_all('/^\s*function\s+' . preg_quote($fn, '/') . '\s*\(/m', $all_src);
    if ($uses <= $definitions) {
        $dead_functions[$fn] = $file;
    }
}

// --- 1b. Functions called but defined nowhere ------------------------------
// The opposite direction of the check above, and in practice more dangerous:
// widget.php called calculate_uptime(), which did not exist in the repo, so the
// page ended with a fatal error for every existing monitor. php -l cannot catch it -
// an undefined function is a runtime error, not a syntactic one.
$builtin = get_defined_functions()['internal'];
$builtin_map = array_flip(array_map('strtolower', $builtin));

// Language constructs and library things called like functions.
$known_external = array_flip([
    'echo', 'print', 'isset', 'unset', 'empty', 'list', 'array', 'exit', 'die',
    'include', 'require', 'include_once', 'require_once', 'eval', 'fn', 'function',
    'match', 'if', 'for', 'foreach', 'while', 'switch', 'catch', 'return', 'new',
    'and', 'or', 'xor', 'use', 'static', 'parent', 'self', 'PHPMailer', 'SMTP',
    'Exception', 'DateTime', 'DateTimeZone', 'DateInterval', 'PDO', 'PDOException',
    'Throwable', 'SimpleXMLElement', 'RecursiveIteratorIterator', 'RecursiveDirectoryIterator',
    'FilesystemIterator', 'ArrayObject', 'stdClass', 'Closure', 'Generator', 'JsonException',
    // Xdebug nemusi byt nainstalovany; volani jsou hlidana function_exists().
    'xdebug_start_code_coverage', 'xdebug_stop_code_coverage', 'xdebug_get_code_coverage',
]);

$undefined_calls = [];
foreach ($php_files as $f) {
    // token_get_all() misto regexu: rozumi komentarum, retezcum i vlozenemu
    // HTML/JS, takze "poskytovatele(" v ceskem komentari uz nikdo nehlasi
    // jako volani funkce.
    $tokens = @token_get_all(file_get_contents($f));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_STRING) {
            continue;
        }

        // Nasleduje zavorka? (mezi tim smi byt jen bily znak)
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        // Predchozi vyznamovy token urcuje, jestli jde o volani funkce.
        $k = $i - 1;
        while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
            $k--;
        }
        if ($k >= 0 && is_array($tokens[$k])) {
            $prev = $tokens[$k][0];
            // definice funkce, metoda objektu, staticka metoda, new Trida()
            if (in_array($prev, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CLASS], true)) {
                continue;
            }
            if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $prev === T_NULLSAFE_OBJECT_OPERATOR) {
                continue;
            }
        }

        $name = $tok[1];
        $lower = strtolower($name);
        if (isset($defs[$name]) || isset($builtin_map[$lower]) || isset($known_external[$name])) {
            continue;
        }
        // Jmena tridy pouzita jako typ/konstruktor bez new (napr. Closure::)
        if ($name !== $lower) {
            continue;
        }

        $undefined_calls[$name . '|' . basename($f) . ':' . $tok[2]] = [$name, basename($f), $tok[2]];
    }
}

// --- 2. Settings saved in the admin but read nowhere ----------------------
$admin = file_get_contents($dir . '/admin.php');
$saved = [];
if (preg_match('/settings_to_save\s*=\s*\[(.*?)\];/s', $admin, $m)) {
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $keys);
    $saved = $keys[1];
}
$dead_settings = [];
foreach ($saved as $key) {
    $read_direct = preg_match("/get_setting\(\s*'" . preg_quote($key, '/') . "'/", $all_src);

    // Keys are often composed at runtime: get_setting('oauth_' . $provider . '_client_id').
    // The detector therefore tries all underscore-separated prefixes/suffixes -
    // without that it reported 8 false alarms on the OAuth settings.
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

// --- 2b. Settings read but saved nowhere ----------------------------------
//
// The opposite direction of the check above, over the same data. When a key is
// missing from the admin, get_setting() always returns the default and nobody
// notices - nothing crashes, it just silently returns something else than the user set.
//
// Exactly this is how the RSS feed was named "Monitoring" instead of the site
// name: the `site_name` key was read while `site_title` is what gets saved.
// A typo that passes tests and review, because the result looks like a legitimate default.
//
// Keys written outside the admin (cron stamps its own runs, the geolocation
// cache) are not errors here - hence writes into `settings` are searched too.
preg_match_all("/get_setting\(\s*'([a-z0-9_]+)'/", $all_src, $read_keys);
$written_anywhere = [];
preg_match_all("/VALUES\s*\(\s*'([a-z0-9_]+)'/i", $all_src, $sql_writes);
foreach ($sql_writes[1] ?? [] as $k) {
    $written_anywhere[$k] = true;
}
// Writes with the key in a parameter: execute(['last_cron_run', ...]).
preg_match_all("/execute\(\s*\[\s*'([a-z0-9_]+)'/", $all_src, $param_writes);
foreach ($param_writes[1] ?? [] as $k) {
    $written_anywhere[$k] = true;
}

$phantom_settings = [];
foreach (array_unique($read_keys[1] ?? []) as $key) {
    if (in_array($key, $saved, true) || isset($written_anywhere[$key])) {
        continue;
    }
    // A composed key: get_setting('oauth_' . $provider . '_client_id').
    // The captured piece is not the full name, so it must not be searched -
    // otherwise the detector reports 'oauth_' as a nonexistent setting.
    if (preg_match("/get_setting\(\s*'" . preg_quote($key, '/') . "'\s*\./", $all_src)) {
        continue;
    }
    // A config.php constant or an environment variable takes precedence over
    // the database in get_setting(), so such a key needs no saving anywhere.
    if (preg_match('/\b' . strtoupper($key) . '\b/', $all_src)) {
        continue;
    }
    $phantom_settings[] = $key;
}

// --- 3. Language keys defined but unused -----------------------------------
$cs_lang = file_get_contents($dir . '/lang/cs.php');
preg_match_all("/^\s*'([a-z0-9_]+)'\s*=>/m", $cs_lang, $lm);
$unused_lang = [];
foreach ($lm[1] as $key) {
    if (!preg_match("/t\(\s*'" . preg_quote($key, '/') . "'/", $all_src)
        && !preg_match("/'" . preg_quote($key, '/') . "'/", str_replace($cs_lang, '', $all_src))) {
        $unused_lang[] = $key;
    }
}

// --- Output ----------------------------------------------------------------
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

if ($phantom_settings) {
    echo "Nastavení čtená, ale nikde neukládaná (" . count($phantom_settings) . "):\n";
    foreach ($phantom_settings as $key) {
        echo "  get_setting('{$key}') - klíč není v settings_to_save ani se nikam nezapisuje,\n";
        echo "    takže vždycky vrátí výchozí hodnotu. Překlep v názvu?\n";
    }
    echo "\n";
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

if ($undefined_calls) {
    echo "Volání funkcí, které nikde nejsou definované (" . count($undefined_calls) . "):\n";
    foreach ($undefined_calls as [$name, $file, $line]) {
        printf("  %s()  (%s:%d)\n", $name, $file, $line);
    }
    echo "\n";
} else {
    echo "Nedefinovaná volání: žádná\n\n";
}

// An undefined call is always a runtime error, so it fails even without --strict.
$problems = count($dead_functions) + count($dead_settings) + count($phantom_settings);
if ($undefined_calls) {
    fwrite(STDERR, "Nalezena volání nedefinovaných funkcí - stránka by skončila fatální chybou.\n");
    exit(1);
}
if ($strict && $problems > 0) {
    fwrite(STDERR, "Mrtvý kód nalezen ({$problems} položek).\n");
    exit(1);
}
exit(0);
