<?php
/**
 * The start of every page and endpoint that uses the app's functions.
 *
 * db.php sets the session cookie flags, loads config.php (which starts the
 * session), connects and loads the settings. functions.php only defines
 * functions. Then the signed-in account is brought in line with the users
 * table, before any access check reads $_SESSION['admin_role'] directly:
 * once per web request, never on the command line. That call used to end
 * functions.php, so nothing could load the library without a database.
 *
 * heartbeat.php and metrics.php need only the database and load db.php
 * alone. health.php loads it alone too, but its HTML view prints
 * BK_CDN_FONTAWESOME and its SRI, which only functions.php defines, and
 * stops there: a break older than this file.
 */

// Included only; requested directly it has nothing to show.
if (PHP_SAPI !== 'cli' && get_included_files()[0] === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (PHP_SAPI !== 'cli' && isset($pdo) && $pdo instanceof PDO) {
    bk_sync_session_account($pdo);
}
