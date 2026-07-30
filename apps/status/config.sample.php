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
// Update these values according to your MySQL database
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'database_name');
define('DB_USER', 'database_user');
define('DB_PASS', 'database_password');

// --- SMTP CONFIGURATION (OPTIONAL) ---
// If uncommented, these values take precedence over database settings.
// Useful for deployments using secrets (e.g. GitHub Actions STATUS_CONFIG_PHP).
// define('SMTP_HOST', 'smtp.yourdomain.com');
// define('SMTP_PORT', 465);
// define('SMTP_USER', 'status@yourdomain.com');
// define('SMTP_PASS', 'your_email_password');
// define('SMTP_SECURE', 'ssl'); // 'ssl', 'tls', or 'none'

// --- OTHER SETTINGS ---
// Default admin credentials after schema.sql import: admin / BloodKingsAdmin123!
// Change your password immediately after first login (Admin -> Profile -> Change Password).
define('TIMEZONE', 'Europe/Prague');
date_default_timezone_set(TIMEZONE);
