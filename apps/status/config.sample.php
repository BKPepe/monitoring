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
    session_start();
}

// --- DATABÁZOVÉ NASTAVENÍ ---
// Upravte tyto údaje podle vaší MySQL databáze na hostingu
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'nazev_databaze');
define('DB_USER', 'uzivatel_databaze');
define('DB_PASS', 'heslo_databaze');

// --- OSTATNÍ NASTAVENÍ ---
define('ADMIN_PASS_DEFAULT', 'BloodKingsAdmin123!'); // Výchozí heslo pokud není změněno
define('TIMEZONE', 'Europe/Prague');
date_default_timezone_set(TIMEZONE);
