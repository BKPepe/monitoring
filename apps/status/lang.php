<?php
/**
 * Jednoduchý i18n loader bez závislostí. Jazyk se volí přes ?lang=cs|en
 * a persistuje se v cookie; výchozí jazyk je čeština (zachovává současné
 * chování pro stávající nasazení). Volané z index.php (a časem admin.php).
 */

$bk_supported_langs = ['cs', 'en'];
$bk_lang = 'cs';

if (isset($_GET['lang']) && in_array($_GET['lang'], $bk_supported_langs, true)) {
    $bk_lang = $_GET['lang'];
    if (!headers_sent()) {
        setcookie('bk_lang', $bk_lang, time() + 60 * 60 * 24 * 365, '/');
    }
} elseif (isset($_COOKIE['bk_lang']) && in_array($_COOKIE['bk_lang'], $bk_supported_langs, true)) {
    $bk_lang = $_COOKIE['bk_lang'];
}

$GLOBALS['BK_LANG'] = $bk_lang;
$GLOBALS['BK_STRINGS'] = require __DIR__ . '/lang/' . $bk_lang . '.php';

/**
 * Vrátí přeložený řetězec pro daný klíč v aktuálním jazyce.
 * Nepřeložené/chybějící klíče spadnou zpět na český text (nikdy prázdný výstup).
 */
function t(string $key): string {
    return $GLOBALS['BK_STRINGS'][$key] ?? ($GLOBALS['BK_STRINGS_CS_FALLBACK'][$key] ?? $key);
}

// Fallback pole pro případ, že by v en.php nějaký klíč chyběl (nikdy nespadne na holý klíč)
if ($GLOBALS['BK_LANG'] !== 'cs') {
    $GLOBALS['BK_STRINGS_CS_FALLBACK'] = require __DIR__ . '/lang/cs.php';
}
