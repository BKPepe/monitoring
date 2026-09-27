<?php
/**
 * The first-run installer behind /app/setup (site audit W1-5).
 *
 * A fresh upload used to find config.php copied from the sample by db.php and
 * die on its placeholder credentials with a Czech 500 page. api.php now routes
 * five actions here before the normal bootstrap, and the app walks them:
 *
 *   install_status         GET   the step this install is at, plus a CSRF token
 *   install_test_db        POST  try database credentials; changes nothing
 *   install_write_config   POST  test again, then write config.php - or return
 *                                its text for copying when PHP cannot write
 *   install_import_schema  POST  import schema.sql into the configured database
 *   install_cron           GET   the exact cron line for this directory
 *
 * The first account is the existing action=setup (409 once a user exists), and
 * the "first run" poll is the public action=collection_health.
 *
 * THE LOCK. An installer left open is a takeover path: whoever reaches it can
 * point the site at a database of their own or add an account. So:
 *  - every step refuses with 409 installer_locked once the database connects
 *    and an account exists; an account count that cannot be read counts as
 *    "exists" (fail closed);
 *  - config.php is written only where none exists or where it still holds the
 *    sample's placeholder password. A real config.php that cannot connect is
 *    an outage, and replacing it then would hand the site to whoever noticed;
 *  - and never in a directory the CI deploy uploads to (FTP-Deploy-Action
 *    leaves .ftp-deploy-sync-state.json there, a fresh upload never has it):
 *    a config.php missing or back to the sample there is a broken deploy, the
 *    same outage;
 *  - writes are POST with the session's CSRF token, like the rest of api.php;
 *  - a password is never sent back, never part of a URL and never logged.
 */

// Only ever required by api.php; opened directly it is nothing.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

/** Stands in for the password in the config text the app shows for copying. */
const BK_INSTALL_PASSWORD_PLACEHOLDER = '__DB_PASSWORD__';

/**
 * JSON that is safe in any HTML context (see bk_json_safe() in functions.php,
 * which is not loaded here): the config text echoes what the caller typed.
 *
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 */
function bk_install_json(array $body): string {
    return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
}

function bk_install_respond(int $http, array $body): never {
    if (!headers_sent()) {
        http_response_code($http);
        header('Cache-Control: no-store');
    }
    echo bk_install_json($body);
    exit;
}

/**
 * The sentence for an error code. Written out key by key so every language
 * key appears literally (find_dead_code.php looks for the quoted key).
 */
function bk_install_message(string $code): string {
    $keys = [
        'installer_locked' => 'install_err_installer_locked',
        'config_exists' => 'install_err_config_exists',
        'database_not_configured' => 'install_err_database_not_configured',
        'csrf_invalid' => 'install_err_csrf_invalid',
        'method_not_allowed' => 'install_err_method_not_allowed',
        'invalid_host' => 'install_err_invalid_host',
        'invalid_port' => 'install_err_invalid_port',
        'invalid_database' => 'install_err_invalid_database',
        'invalid_user' => 'install_err_invalid_user',
        'invalid_password' => 'install_err_invalid_password',
        'invalid_timezone' => 'install_err_invalid_timezone',
        'access_denied' => 'install_err_access_denied',
        'unknown_database' => 'install_err_unknown_database',
        'host_unreachable' => 'install_err_host_unreachable',
        'unknown_host' => 'install_err_unknown_host',
        'connect_failed' => 'install_err_connect_failed',
        'template_missing' => 'install_err_template_missing',
        'schema_file_missing' => 'install_err_schema_file_missing',
        'schema_import_failed' => 'install_err_schema_import_failed',
        'config_unwritable' => 'install_config_unwritable',
        'internal' => 'install_err_internal',
    ];
    return isset($keys[$code]) ? t($keys[$code]) : t('install_err_internal');
}

function bk_install_fail(int $http, string $code, array $extra = []): never {
    bk_install_respond($http, ['error' => $code, 'message' => bk_install_message($code)] + $extra);
}

/** The CSRF token of this session, created on first use (same key as bk_csrf_token()). */
function bk_install_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

/** The same check api.php makes for every session write: header or form field. */
function bk_install_csrf_ok(): bool {
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
    return $sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $sent);
}

/**
 * Database settings from the form, checked before they touch a DSN or a file.
 *
 * Host and database name go into the DSN, where ';' or '=' would add options,
 * so both are held to plain characters. User and password are PDO arguments
 * and var_export()ed into config.php, so only control characters are refused.
 * The sample's placeholder passwords are refused too: a config.php carrying
 * one counts as "not configured" and could later be replaced by the installer.
 *
 * @return array{0: ?array{host: string, port: int, database: string, user: string, password: string, timezone: string}, 1: ?string}
 */
function bk_install_validate(array $in): array {
    // A JSON array or object where a string belongs is not a value (null);
    // an absent or null field is empty.
    $str = static fn (string $key): ?string => ($in[$key] ?? null) === null ? ''
        : (is_scalar($in[$key]) ? (string)$in[$key] : null);
    $host = $str('host');
    if ($host === null || ($host = trim($host)) === '' || !preg_match('/^[A-Za-z0-9.:\-]{1,255}$/', $host)) {
        return [null, 'invalid_host'];
    }
    $port_raw = $str('port');
    $port = $port_raw === '' ? 3306
        : ($port_raw === null ? false : filter_var($port_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]));
    if ($port === false) {
        return [null, 'invalid_port'];
    }
    $database = trim((string)$str('database'));
    if ($str('database') === null || !preg_match('/^[A-Za-z0-9_$\-]{1,64}$/', $database)) {
        return [null, 'invalid_database'];
    }
    $user = trim((string)$str('user'));
    if ($str('user') === null || $user === '' || strlen($user) > 80 || preg_match('/[\x00-\x1F\x7F]/', $user)) {
        return [null, 'invalid_user'];
    }
    $password = $str('password');
    if ($password === null || strlen($password) > 256 || preg_match('/[\x00-\x1F\x7F]/', $password)
        || in_array($password, ['heslo_databaze', 'database_password'], true)) {
        return [null, 'invalid_password'];
    }
    $timezone = trim((string)$str('timezone'));
    // ALL_WITH_BC, not the canonical list: Chromium's ICU reports legacy names
    // such as Asia/Calcutta and Europe/Kiev, and the form has no field to fix
    // them by hand. This is exactly the set date_default_timezone_set() in
    // config.php accepts; timezone_open() would also let '+02:00' and 'PST'
    // through, which that call rejects.
    if ($str('timezone') === null || $timezone !== ''
        && !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
        return [null, 'invalid_timezone'];
    }
    return [['host' => $host, 'port' => (int)$port, 'database' => $database, 'user' => $user,
        'password' => $password, 'timezone' => $timezone], null];
}

/** A connection with exactly the DSN db.php will build from the written file. */
function bk_install_connect(array $cfg): PDO {
    return new PDO(bk_db_dsn('mysql', $cfg['host'], $cfg['port'], $cfg['database']), $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        // A host that swallows packets would otherwise hold the request for minutes.
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/**
 * Why a connection failed, as a code the app can word - from the driver code
 * in "SQLSTATE[HY000] [1045] ...". The PDO text itself is never sent: it names
 * the account and the host as the server sees them.
 *
 * @return array{error: string, driverCode: ?int}
 */
function bk_install_connect_error(Throwable $e): array {
    $driver = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? (int)$m[1] : null;
    $codes = [1044 => 'access_denied', 1045 => 'access_denied', 1049 => 'unknown_database',
        2002 => 'host_unreachable', 2003 => 'host_unreachable', 2006 => 'host_unreachable', 2005 => 'unknown_host'];
    return ['error' => ($driver !== null && isset($codes[$driver])) ? $codes[$driver] : 'connect_failed', 'driverCode' => $driver];
}

/**
 * What the connected database holds: tables, whether schema.sql ran (its last
 * statement stores schema_version) and how many accounts exist.
 *
 * users is null when the count cannot be read for any reason other than a
 * missing table - and null locks the installer, so an error never opens it.
 *
 * @return array{tables: ?int, schema: ?string, users: ?int}
 */
function bk_install_inspect(PDO $pdo): array {
    $tables = null;
    try {
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    } catch (PDOException $e) {
        // The count is only a hint for the form ("this database is not empty").
    }
    try {
        $version = $pdo->query("SELECT key_value FROM settings WHERE key_name = 'schema_version'")->fetchColumn();
        $schema = ($version !== false && $version !== null && $version !== '') ? 'ready' : 'partial';
    } catch (PDOException $e) {
        $schema = (string)$e->getCode() === '42S02' ? 'missing' : null;
    }
    try {
        $users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    } catch (PDOException $e) {
        $users = (string)$e->getCode() === '42S02' ? 0 : null;
    }
    return ['tables' => $tables, 'schema' => $schema, 'users' => $users];
}

/**
 * The step an install is at. 'installed' is the lock and it is checked first.
 *
 * config              no config.php, or the sample's that cannot connect, outside a
 *                     CI-deployed directory: write one
 * config_unreachable  a real config.php that cannot connect, or no working one in
 *                     a CI-deployed directory: FTP or the deploy, never the installer
 * schema              connected, schema.sql not (fully) imported
 * account             schema ready, no account yet: action=setup
 * installed           connected and an account exists (or the count failed)
 */
function bk_install_step(bool $config_exists, bool $config_sample, ?array $db, bool $ci_deployed = false): string {
    if ($db !== null && ($db['users'] === null || $db['users'] > 0)) {
        return 'installed';
    }
    if ($db === null) {
        return ((!$config_exists || $config_sample) && !$ci_deployed) ? 'config' : 'config_unreachable';
    }
    return $db['schema'] === 'ready' ? 'account' : 'schema';
}

/** Whether PHP can put config.php in place (a new file, or over the placeholder one). */
function bk_install_can_write(string $dir): bool {
    $path = $dir . '/config.php';
    return is_writable($dir) || (is_file($path) && is_writable($path));
}

/** What this server has of what the app needs, so the form can say what is missing. */
function bk_install_requirements(): array {
    return [
        'php' => PHP_VERSION,
        'phpOk' => version_compare(PHP_VERSION, '8.2.0', '>='),
        'extensions' => [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'curl' => extension_loaded('curl'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
        ],
    ];
}

/**
 * schema.sql as single statements.
 *
 * Splits on ';' outside quotes and comments. The test suite's own import
 * splits on ";\n" and drops whole-line comments, which is enough for a file
 * it controls; an installer that stopped halfway through someone's database
 * because a comment held a semicolon would be worse than no installer.
 *
 * @return list<string>
 */
function bk_install_split_sql(string $sql): array {
    $statements = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buf .= $sql[++$i];
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
            continue;
        }
        // "-- " needs whitespace after the dashes in MySQL; "#" runs to the line end.
        $dash_comment = $c === '-' && ($sql[$i + 1] ?? '') === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]));
        if ($dash_comment || $c === '#') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $buf .= "\n";
            continue;
        }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            $buf .= ' ';
            continue;
        }
        if ($c === ';') {
            if (trim($buf) !== '') {
                $statements[] = trim($buf);
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    if (trim($buf) !== '') {
        $statements[] = trim($buf);
    }
    return $statements;
}

/**
 * config.php from config.sample.php with the tested values put in.
 *
 * The sample stays the one template: its session block, error settings and
 * comments land in every install. Each value is var_export()ed, so no input can
 * end the string and become code. Null when a define the installer fills is
 * missing from the template (or appears twice) - never a half-filled file.
 */
function bk_install_render_config(string $template, array $cfg, string $stamp): ?string {
    $values = [
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => $cfg['host'],
        'DB_PORT' => $cfg['port'],
        'DB_NAME' => $cfg['database'],
        'DB_USER' => $cfg['user'],
        'DB_PASS' => $cfg['password'],
    ];
    if (($cfg['timezone'] ?? '') !== '') {
        $values['TIMEZONE'] = $cfg['timezone'];
    }
    $out = $template;
    foreach ($values as $const => $value) {
        $line = "define('" . $const . "', " . var_export($value, true) . ");";
        $out = preg_replace_callback("/^define\\('" . $const . "',[^\\n]*\\);[ \\t]*$/m", static fn (): string => $line, $out, -1, $count);
        if ($out === null || $count !== 1) {
            return null;
        }
    }
    $header = "<?php\n// Written by the installer (/app/setup) on {$stamp}, after a successful\n"
        . "// connection test with the database settings below.\n";
    $body = preg_replace('/^<\?php[ \t]*\r?\n/', '', $out, 1, $had_tag);
    return ($body === null || $had_tag !== 1) ? null : $header . $body;
}

/** The file on disk still carries a placeholder password (re-checked right before replacing it). */
function bk_install_file_is_placeholder(string $path): bool {
    $text = @file_get_contents($path);
    return is_string($text) && preg_match("/^define\\('DB_PASS',\\s*'(heslo_databaze|database_password)'\\);/m", $text) === 1;
}

/**
 * Puts config.php in place: a temporary file in the same directory, then one
 * rename, so a request never reads a half-written config (a PHP parse error
 * there would take the whole site down). Where the directory is read-only but
 * a placeholder config.php is writable, that file is overwritten in place.
 *
 * @return 'written'|'exists'|'unwritable'
 */
function bk_install_write_config(string $dir, string $text): string {
    $path = $dir . '/config.php';
    // The state was read at the start of the request; a config.php that
    // appeared since, or stopped being the placeholder, is left alone.
    if (is_file($path) && !bk_install_file_is_placeholder($path)) {
        return 'exists';
    }
    $written = false;
    if (is_writable($dir)) {
        $tmp = $dir . '/config.php.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmp, $text, LOCK_EX) === strlen($text)) {
            @chmod($tmp, 0640);
            $written = @rename($tmp, $path);
        }
        if (!$written) {
            @unlink($tmp);
        }
    }
    if (!$written && is_file($path) && is_writable($path)) {
        $written = @file_put_contents($path, $text, LOCK_EX) === strlen($text);
    }
    if (!$written) {
        return 'unwritable';
    }
    // OPcache re-reads a changed file only every revalidate_freq seconds, so
    // the next request could still run the placeholder config.php it cached.
    // Where the host restricts the call, that window is all that is left.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return 'written';
}

/**
 * Runs schema.sql statement by statement and stops at the first real error.
 *
 * "Already exists" errors are skipped: they are what re-running a partly
 * imported file produces (1050 table, 1060 column, 1061 index, 1062 row,
 * 1826 foreign key). The statement number goes back to the app; the MySQL
 * text goes to the server log only.
 *
 * @return array{ok: bool, executed: int, total: int, statement?: int, driverCode?: ?int}
 */
function bk_install_import_schema(PDO $pdo, string $sql): array {
    $statements = bk_install_split_sql($sql);
    $executed = 0;
    foreach ($statements as $i => $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $driver = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
            if (!in_array($driver, [1050, 1060, 1061, 1062, 1826], true)) {
                error_log('[installer] schema.sql statement ' . ($i + 1) . ' failed: ' . $e->getMessage());
                return ['ok' => false, 'executed' => $executed, 'total' => count($statements), 'statement' => $i + 1, 'driverCode' => $driver];
            }
        }
        $executed++;
    }
    return ['ok' => true, 'executed' => $executed, 'total' => count($statements)];
}

/**
 * The PHP command line binary for the cron line, and whether it was found.
 *
 * Under LiteSpeed or FPM, PHP_BINARY is the web binary, not the CLI; the CLI
 * normally sits in PHP_BINDIR (on cPanel /opt/cpanel/ea-phpXY/root/usr/bin).
 * Its full path pins cron to the PHP version serving the site - a bare "php"
 * in cron is often a different, older one. Bare "php" is the fallback, and
 * found=false lets the app say so.
 *
 * @return array{path: string, found: bool}
 */
function bk_install_php_cli(): array {
    $candidates = [];
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
        $candidates[] = PHP_BINARY;
    }
    $candidates[] = PHP_BINDIR . '/php';
    foreach ($candidates as $candidate) {
        if ($candidate !== '' && @is_file($candidate) && @is_executable($candidate)) {
            return ['path' => $candidate, 'found' => true];
        }
    }
    return ['path' => 'php', 'found' => false];
}

/** One shell word: plain paths stay readable, anything else is single-quoted. */
function bk_install_shell_word(string $word): string {
    return preg_match('~^[A-Za-z0-9_./:@%+=,-]+$~', $word) === 1
        ? $word
        : "'" . str_replace("'", "'\\''", $word) . "'";
}

/**
 * The cron job for this directory: cPanel takes the schedule and the command
 * separately, crontab -e takes one line. Every minute, as the docs say; the
 * output goes nowhere so cron does not mail every run.
 */
function bk_install_cron(string $php, string $dir): array {
    $script = rtrim($dir, '/') . '/cron.php';
    $command = bk_install_shell_word($php) . ' -q ' . bk_install_shell_word($script) . ' >/dev/null 2>&1';
    return [
        'schedule' => '* * * * *',
        'command' => $command,
        'crontabLine' => '* * * * * ' . $command,
        'script' => $script,
    ];
}

/** An administrator's session, checked against the users table (a demoted admin keeps the old session). */
function bk_install_is_admin(?PDO $pdo): bool {
    if ($pdo === null || empty($_SESSION['admin_logged_in'])) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        return $stmt->fetchColumn() === 'admin';
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * A fresh install gets its own cron key. With an empty one cron.php runs for
 * anyone who opens it over HTTP and node_api.php stays off; the installer's
 * cron line is CLI and needs no key. Only an empty key is ever filled.
 */
function bk_install_cron_key(PDO $pdo): void {
    $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('cron_key', ?)
        ON DUPLICATE KEY UPDATE key_value = IF(key_value IS NULL OR key_value = '', VALUES(key_value), key_value)");
    $stmt->execute([bin2hex(random_bytes(16))]);
}

/** A JSON request body, or the form fields when there is none. */
function bk_install_input(): array {
    $json = json_decode((string)file_get_contents('php://input'), true);
    return is_array($json) ? $json : $_POST;
}

/**
 * Runs one installer action and ends the request.
 *
 * $pdo is what db.php could open from config.php: null without a working
 * config, or a connection that may still lack the schema.
 */
function bk_install_dispatch(string $action, ?PDO $pdo, string $dir): never {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        // Without config.php nothing has started the session yet; db.php
        // already set the cookie flags (HttpOnly, SameSite, Secure over HTTPS).
        @session_start();
    }
    require_once __DIR__ . '/lang.php';

    $writes = ['install_test_db', 'install_write_config', 'install_import_schema'];
    if (in_array($action, $writes, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        bk_install_fail(405, 'method_not_allowed');
    }

    try {
        $config_exists = is_file($dir . '/config.php');
        $config_sample = $config_exists && bk_config_is_placeholder();
        $db = $pdo !== null ? bk_install_inspect($pdo) : null;
        $step = bk_install_step($config_exists, $config_sample, $db, is_file($dir . '/.ftp-deploy-sync-state.json'));
        $is_admin = $step === 'installed' && bk_install_is_admin($pdo);

        if ($action === 'install_status') {
            if (($step === 'installed' && !$is_admin) || $step === 'config_unreachable') {
                // A finished install, or a real config.php whose database is not
                // answering (an outage, or a typo only FTP can fix) or a CI-deployed
                // directory without a working one: no step runs, and anyone
                // learns only that - no versions, no token.
                bk_install_respond(200, ['step' => $step, 'locked' => true]);
            }
            bk_install_respond(200, [
                'step' => $step,
                'locked' => $step === 'installed',
                'config' => ['exists' => $config_exists, 'sample' => $config_sample, 'writable' => bk_install_can_write($dir)],
                'database' => ['connects' => $db !== null, 'schema' => $db['schema'] ?? null,
                    'users' => $db['users'] ?? null, 'tables' => $db['tables'] ?? null],
                'requirements' => bk_install_requirements(),
                'csrfToken' => bk_install_csrf_token(),
            ]);
        }

        if ($action === 'install_cron') {
            // A read, so no CSRF; after the install only an administrator gets it
            // (the account step signs the new admin in, so the wizard still does).
            if ($db === null) {
                bk_install_fail(409, 'database_not_configured');
            }
            if ($step === 'installed' && !$is_admin) {
                bk_install_fail(409, 'installer_locked');
            }
            $php = bk_install_php_cli();
            bk_install_respond(200, bk_install_cron($php['path'], $dir)
                + ['phpBinary' => $php['path'], 'phpBinaryFound' => $php['found']]);
        }

        // The writes. The lock answers first - a finished install says 409 to
        // everyone, with or without a token.
        if ($step === 'installed') {
            bk_install_fail(409, 'installer_locked');
        }
        if (!bk_install_csrf_ok()) {
            bk_install_fail(403, 'csrf_invalid');
        }

        if ($action === 'install_test_db' || $action === 'install_write_config') {
            if ($step !== 'config') {
                bk_install_fail(409, 'config_exists', ['step' => $step]);
            }
            [$cfg, $invalid] = bk_install_validate(bk_install_input());
            if ($cfg === null) {
                bk_install_fail(400, (string)$invalid);
            }
            try {
                $candidate = bk_install_connect($cfg);
            } catch (PDOException $e) {
                // PDO never puts the password into its message; the account and
                // host it names stay in the server log.
                error_log('[installer] connection test failed: ' . $e->getMessage());
                $why = bk_install_connect_error($e);
                if ($action === 'install_test_db') {
                    bk_install_respond(200, ['ok' => false, 'error' => $why['error'],
                        'message' => bk_install_message($why['error']), 'driverCode' => $why['driverCode']]);
                }
                bk_install_fail(422, $why['error'], ['driverCode' => $why['driverCode']]);
            }
            $found = bk_install_inspect($candidate);
            if ($action === 'install_test_db') {
                bk_install_respond(200, ['ok' => true, 'database' => $found]);
            }

            $template = @file_get_contents($dir . '/config.sample.php');
            $stamp = date('Y-m-d H:i:s T');
            $text = is_string($template) ? bk_install_render_config($template, $cfg, $stamp) : null;
            if ($text === null) {
                bk_install_fail(500, 'template_missing');
            }
            $next = bk_install_step(true, false, $found);
            $written = bk_install_write_config($dir, $text);
            if ($written === 'written') {
                bk_install_respond(200, ['written' => true, 'next' => $next]);
            }
            if ($written === 'exists') {
                bk_install_fail(409, 'config_exists');
            }
            // PHP may not write here. The text comes back for copying, with the
            // password left out: the app fills it in only on the way to the
            // clipboard, so it is never rendered into the page or sent twice.
            bk_install_respond(200, [
                'written' => false,
                'next' => $next,
                'fileName' => 'config.php',
                'directory' => $dir,
                'configText' => bk_install_render_config((string)$template, ['password' => BK_INSTALL_PASSWORD_PLACEHOLDER] + $cfg, $stamp),
                'passwordPlaceholder' => BK_INSTALL_PASSWORD_PLACEHOLDER,
                'message' => bk_install_message('config_unwritable'),
            ]);
        }

        if ($action === 'install_import_schema') {
            if ($pdo === null || $db === null) {
                bk_install_fail(409, 'database_not_configured');
            }
            if ($db['schema'] === 'ready') {
                bk_install_respond(200, ['imported' => false, 'alreadyImported' => true, 'next' => $step]);
            }
            $sql = @file_get_contents($dir . '/schema.sql');
            if (!is_string($sql) || trim($sql) === '') {
                bk_install_fail(500, 'schema_file_missing');
            }
            $result = bk_install_import_schema($pdo, $sql);
            if (!$result['ok']) {
                bk_install_fail(500, 'schema_import_failed',
                    ['statement' => $result['statement'] ?? null, 'driverCode' => $result['driverCode'] ?? null]);
            }
            bk_install_cron_key($pdo);
            bk_install_respond(200, ['imported' => true, 'statements' => $result['executed'],
                'next' => bk_install_step(true, false, bk_install_inspect($pdo))]);
        }
    } catch (Throwable $e) {
        error_log('[installer] ' . $action . ' failed: ' . $e->getMessage());
        bk_install_fail(500, 'internal');
    }
    // api.php routes only the five names above.
    bk_install_fail(400, 'internal');
}
