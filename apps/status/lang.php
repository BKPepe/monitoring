<?php
/**
 * A simple dependency-free i18n loader. The language is chosen via ?lang=cs|en
 * and persists in a cookie; the default language is Czech (preserving the
 * existing behaviour of current deployments). Called from index.php (and later admin.php).
 */

$bk_supported_langs = ['cs', 'en'];
$bk_lang = 'cs';

if (isset($_GET['lang']) && in_array($_GET['lang'], $bk_supported_langs, true)) {
    $bk_lang = $_GET['lang'];
    if (!headers_sent()) {
        setcookie('bk_lang', $bk_lang, time() + 60 * 60 * 24 * 365, '/');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['bk_lang'] = $bk_lang;
    }
} elseif (isset($_SESSION['bk_lang']) && in_array($_SESSION['bk_lang'], $bk_supported_langs, true)) {
    $bk_lang = $_SESSION['bk_lang'];
} elseif (isset($_COOKIE['bk_lang']) && in_array($_COOKIE['bk_lang'], $bk_supported_langs, true)) {
    $bk_lang = $_COOKIE['bk_lang'];
}

$GLOBALS['BK_LANG'] = $bk_lang;
// Literal paths, not a path built from $bk_lang. The value is already
// allowlisted above, so this changes no behaviour - but an include whose path
// contains a request-derived variable is one edit away from a file-inclusion
// bug, and static analysis cannot tell the two apart. Taint analysis flagged
// this include and, because the dictionary loaded here feeds every t() call,
// treated ALL translated output as attacker-controlled - dozens of false
// reports downstream from this one line.
$GLOBALS['BK_STRINGS'] = $bk_lang === 'en'
    ? require __DIR__ . '/lang/en.php'
    : require __DIR__ . '/lang/cs.php';

/**
 * Returns the translated string for a key in the current language.
 * Untranslated/missing keys fall back to the Czech text (never empty output).
 */
function t(string $key): string {
    return $GLOBALS['BK_STRINGS'][$key] ?? ($GLOBALS['BK_STRINGS_CS_FALLBACK'][$key] ?? $key);
}

// Fallback array in case a key were missing from en.php (never falls to a bare key)
if ($GLOBALS['BK_LANG'] !== 'cs') {
    $GLOBALS['BK_STRINGS_CS_FALLBACK'] = require __DIR__ . '/lang/cs.php';
}
