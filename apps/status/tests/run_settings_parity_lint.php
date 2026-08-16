<?php
/**
 * Checks that React, api.php and the legacy admin talk about the same settings.
 *
 * Run:  php apps/status/tests/run_settings_parity_lint.php
 *
 * Why it exists: the key list existed three times - in admin.php for writing,
 * and in api.php separately for reading and for writing. They drifted apart and
 * nobody noticed, because it never surfaces as an error:
 *
 *   whatsapp_api_endpoint, whatsapp_token, whatsapp_phone_number
 *       could be saved, but get_settings never returned them. React therefore
 *       showed empty fields and the next "Save all" stored empty values - the
 *       settings vanished without anyone making a mistake.
 *
 *   agent_outdated_email_enabled
 *       React had a toggle for it, but the API knew that key neither for
 *       reading nor for writing. You could flip it, save it, and after a
 *       reload it was back.
 *
 * The keys now live in one place (bk_settings_keys() in db.php). This lint
 * covers what a single source cannot: that the UI does not ask for a key the
 * server has never heard of.
 */

$root = realpath(__DIR__ . '/..');
$repo = realpath(__DIR__ . '/../../..');

// db.php cannot simply be included - it connects to the database at the end.
// Only those two functions are extracted, the same way the other tests do it.
require_once __DIR__ . '/assert_helpers.php';
bk_test_load_functions($root . '/db.php', ['bk_settings_keys', 'bk_settings_secret_keys']);

if (!function_exists('bk_settings_keys')) {
    fwrite(STDERR, "bk_settings_keys() se nepodařilo načíst z db.php - přejmenovala se?\n");
    exit(1);
}

$known = bk_settings_keys();
$secrets = bk_settings_secret_keys();
$problems = [];

// 1. Secret keys must be a subset of all keys - otherwise something that is
//    never read gets masked, and you believe it is hidden when it is not.
foreach ($secrets as $key) {
    if (!in_array($key, $known, true)) {
        $problems[] = sprintf('bk_settings_secret_keys() zná %s, ale bk_settings_keys() ne', $key);
    }
}

// 2. No file may keep its own copy of the list. This is checked through a
//    characteristic key: if someone copies the list around again, the literal
//    'oauth_github_client_secret' shows up outside db.php.
$copy_marker = 'oauth_github_client_secret';
foreach (['api.php', 'admin.php'] as $file) {
    $src = file_get_contents($root . '/' . $file);
    if ($src !== false && str_contains($src, "'" . $copy_marker . "'")) {
        $problems[] = sprintf(
            '%s obsahuje vlastní seznam klíčů (našel se %s) - má volat bk_settings_keys()',
            $file,
            $copy_marker
        );
    }
}

// 3. Keys React touches. Taken from `k="..."` on form fields, `set('...')` on
//    toggles and `settings.key` in conditions.
$settings_page = $repo . '/apps/monitor/src/pages/settings.tsx';
$tsx = file_get_contents($settings_page);
if ($tsx === false) {
    fwrite(STDERR, "settings.tsx se nepodařilo načíst.\n");
    exit(1);
}

$ui_keys = [];
preg_match_all('/\bk="([a-z0-9_]+)"/', $tsx, $m);
$ui_keys = array_merge($ui_keys, $m[1] ?? []);
preg_match_all("/\bset\('([a-z0-9_]+)'/", $tsx, $m);
$ui_keys = array_merge($ui_keys, $m[1] ?? []);
// `settings.key` only where the value is compared or used as a value -
// otherwise translation keys from t('settings.something') would match too.
preg_match_all('/settings\.([a-z0-9_]+)\??\s*(?:===|!==|\|\||\?\.)/', $tsx, $m);
$ui_keys = array_merge($ui_keys, $m[1] ?? []);

// Template-built keys: OAuth fields are formed as `oauth_${op.key}_client_id`
// over the provider list. If templates were simply ignored, a typo in one
// (…_clientid) would pass the lint - and that is exactly the silent kind of bug
// this lint was written for. They are expanded using the list in settings.tsx.
preg_match_all('/OAUTH_PROVIDERS\s*=\s*\[(.*?)\n\];/s', $tsx, $m);
$providers = [];
if (!empty($m[1][0])) {
    preg_match_all("/\bkey:\s*'([a-z0-9_]+)'/", $m[1][0], $pm);
    $providers = $pm[1] ?? [];
}

preg_match_all('/\bk=\{`([a-z0-9_]*)\$\{[a-z]+\.key\}([a-z0-9_]*)`\}/i', $tsx, $m, PREG_SET_ORDER);
foreach ($m as $tpl) {
    if (!$providers) {
        $problems[] = sprintf(
            'settings.tsx skládá klíč šablonou %s${…}%s, ale seznam poskytovatelů se nepodařilo přečíst',
            $tpl[1],
            $tpl[2]
        );
        continue;
    }
    foreach ($providers as $p) {
        $ui_keys[] = $tpl[1] . $p . $tpl[2];
    }
}

$ui_keys = array_values(array_unique($ui_keys));
sort($ui_keys);

foreach ($ui_keys as $key) {
    if (!in_array($key, $known, true)) {
        $problems[] = sprintf('settings.tsx pracuje s klíčem %s, který bk_settings_keys() nezná', $key);
    }
}

if ($problems) {
    fwrite(STDERR, "Nastavení se rozchází mezi UI a serverem:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nDůsledek není chybová hláška, ale ticho: pole se zobrazí prázdné\n");
    fwrite(STDERR, "a další uložení přepíše skutečnou hodnotu prázdnou.\n");
    exit(1);
}

printf(
    "Settings parity lint: %d klíčů na serveru, %d z nich používá React, seznam je jen jeden.\n",
    count($known),
    count($ui_keys)
);
exit(0);
