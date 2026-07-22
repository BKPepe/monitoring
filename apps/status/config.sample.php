<?php
/**
 * Šablona konfiguračního souboru pro Blood Kings Status Monitoring
 * 
 * Zkopírujte tento soubor a uložte jej jako "config.php" ve stejné složce,
 * a následně doplňte přístupové údaje k databázi.
 * 
 * SOUBOR "config.php" JE IGNOROVÁN V GITU A NESMÍ BÝT COMMITNUT!
 */

// Zamezení přímému přístupu ke konfiguračnímu souboru
if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit("Přístup odepřen.");
}

// Nastavení chybových hlášení (v produkci můžete vypnout)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Spuštění session pro administraci
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    // Zpevnění session cookie - musí být nastaveno před session_start().
    // HttpOnly: JS se ke cookie nedostane (XSS nemůže ukrást session ID).
    // SameSite=Lax: cookie se neposílá při cross-site POST/fetch (základní
    // obrana proti CSRF, nenahrazuje to ale CSRF token na citlivých akcích).
    // Secure jen když běžíme přes HTTPS - jinak by cookie na čistém HTTP vůbec nefungovala.
    $bk_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $bk_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    @session_start();
}

// --- DATABÁZOVÉ NASTAVENÍ ---
// Upravte tyto údaje podle vaší MySQL databáze na hostingu
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'nazev_databaze');
define('DB_USER', 'uzivatel_databaze');
define('DB_PASS', 'heslo_databaze');

// --- SMTP (VOLITELNÉ) ---
// Pokud tyto konstanty odkomentujete a vyplníte, mají přednost před nastavením
// z databáze (E-mailové notifikace v administraci) - stejně jako DB_HOST výše.
// Užitečné hlavně pro nasazení přes GitHub Actions secret (STATUS_CONFIG_PHP),
// kde SMTP přihlašovací údaje nechcete mít uložené v databázi.
// define('SMTP_HOST', 'smtp.vasedomena.cz');
// define('SMTP_PORT', 465);
// define('SMTP_USER', 'status@vasedomena.cz');
// define('SMTP_PASS', 'heslo_k_emailu');
// define('SMTP_SECURE', 'ssl'); // 'ssl', 'tls', nebo 'none'

// --- OSTATNÍ NASTAVENÍ ---
// Výchozí přihlašovací účet po importu schema.sql je admin / BloodKingsAdmin123!
// - změňte si ho hned po prvním přihlášení (Admin -> Profil -> Změnit heslo).
define('TIMEZONE', 'Europe/Prague');
date_default_timezone_set(TIMEZONE);
