<?php
/**
 * Configuration file template for Blood Kings Status Monitoring
 * 
 * Copy this file and save it as "config.php" in the same directory,
 * then fill in your MySQL database credentials below.
 * 
 * THE "config.php" FILE IS IGNORED BY GIT AND MUST NOT BE COMMITTED!
 */

// Prevent direct access to the configuration file
if (count(get_included_files()) === 1) {
    http_response_code(403);
    exit("Access denied.");
}

// Error reporting settings (disable display_errors in production)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Start session for administration with secure cookie flags
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    // HttpOnly: JS cannot access cookie (XSS protection)
    // SameSite=Lax: Cookie not sent on cross-site POST/fetch
    // Secure: Enabled automatically when running over HTTPS
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

// --- DATABASE CONFIGURATION ---
// db.php lets you pass 'pgsql' here, but every migration and query in this
// app (schema.sql, db.php, api.php, functions.php, cron.php) is written in
// MySQL dialect (AUTO_INCREMENT, ON DUPLICATE KEY UPDATE, backticked
// identifiers, ...). Setting DB_DRIVER to 'pgsql' will connect fine and
// then silently fail every migration/query that follows - this app only
// actually works against MySQL/MariaDB today.
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'bloodkings_status');
define('DB_USER', 'bloodkings');
define('DB_PASS', 'heslo_databaze');

// --- SMTP ---
// SMTP se NEnastavuje tady: kompletní konfigurace (host, port, účet, heslo,
// šifrování) žije v databázi a spravuje se v Admin -> Nastavení -> SMTP.
// Dřívější zakomentované SMTP_* konstanty tu slibovaly přednost před databází,
// ale žádný kód je nikdy nečetl - byla to mrtvá (a matoucí) dokumentace.

// --- OTHER SETTINGS ---
// Default admin credentials after schema.sql import: admin / BloodKingsAdmin123!
// Change your password immediately after first login (Admin -> Profile -> Change Password).
define('TIMEZONE', 'Europe/Prague');
date_default_timezone_set(TIMEZONE);
