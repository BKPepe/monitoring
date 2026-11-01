<?php
/**
 * Database connection and settings bootstrap
 */

/**
 * Session cookie flags, set before config.php can start the session.
 *
 * The flags used to live only in config.php's own session block, and the
 * deployed config.php does not have that block: the admin session cookie went
 * out without HttpOnly or SameSite, readable by any injected script and sent
 * along with cross-site requests. Setting them here, before config.php runs,
 * makes them independent of whichever config.php a host carries.
 * .user.ini sets the same values at PHP startup where the host honours it;
 * this is the layer that does not depend on that. Every entry point loads
 * db.php before anything else that can start a session.
 *
 * Secure is only ever turned ON here, for a request that arrived over HTTPS
 * (directly or through the proxy). It is never turned off, so .user.ini's
 * unconditional Secure stays in force on production.
 *
 * A host that ships session.gc_divisor = 0 gets PHP's default of 100 back,
 * on the command line too: cron.php starts a session through config.php as
 * well. This line used to open functions.php, which api.php and node_api.php
 * load only after config.php has started the session.
 */
function bk_session_harden(): void {
    if (ini_get('session.gc_divisor') === '0' || ini_get('session.gc_divisor') === false) {
        @ini_set('session.gc_divisor', 100);
    }
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE || headers_sent()) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($https) {
        ini_set('session.cookie_secure', '1');
    }
}
bk_session_harden();

/**
 * Why this install cannot serve yet, or null once it can.
 *
 * config_missing  no config.php: a fresh upload.
 * config_sample   config.php still holds the sample's placeholder credentials
 *                 and they do not connect (a hand copy, or the copy db.php
 *                 itself used to make).
 * schema_missing  config.php connects, but schema.sql was never imported.
 *
 * A config.php with real credentials that cannot connect is NOT on this list:
 * that is a database outage (or a typo only FTP can fix), it answers
 * database_unavailable, and the installer refuses to replace that file - an
 * installer that opened whenever the database was down would let anyone who
 * noticed the outage point the site at a database of their own.
 */
$bk_setup_reason = null;

// A missing config.php used to be "fixed" here by copying config.sample.php
// over it. A fresh upload then died on the sample's placeholder credentials
// with a Czech 500 page quoting the raw PDO error. Now it is a first run: the
// app's installer (/app/setup) writes config.php after testing the connection.
//
// Whatever config.php prints while it loads is held back. On 27 Sep 2026 a
// hand edit left "Ah" before <?php: every JSON answer began with those two
// bytes, /app could not parse any of them for an hour, and nothing said why.
// The bytes never reach a response now, and they are not dropped either:
// bk_config_output() keeps what they were for health.php and /app.
$GLOBALS['bk_config_output'] = null;
if (is_file(__DIR__ . '/config.php')) {
    $bk_config_ob_level = ob_get_level();
    ob_start();
    try {
        require_once __DIR__ . '/config.php';
    } finally {
        // Every buffer above ours too, in case config.php opened one of its own.
        $bk_config_printed = '';
        while (ob_get_level() > $bk_config_ob_level) {
            $bk_config_printed = (string)ob_get_clean() . $bk_config_printed;
        }
    }
    if ($bk_config_printed !== '') {
        $GLOBALS['bk_config_output'] = bk_config_output_issue($bk_config_printed,
            (string)@file_get_contents(__DIR__ . '/config.php'));
        bk_config_output_log($GLOBALS['bk_config_output']);
    }
} else {
    $bk_setup_reason = 'config_missing';
}
if ($bk_setup_reason !== null) {
    if (defined('BK_INSTALL_REQUEST')) {
        // The installer runs on exactly this state; it gets no connection.
        $pdo = null;
        return;
    }
    bk_needs_setup($bk_setup_reason);
}

// Numbers in JSON use the shortest representation that round-trips to the same value.
//
// Without this json_encode() prints the double's full decimal expansion, so
// z hodnoty 35,2 (sloupec FLOAT) stane 35.20000000000000284217094304040074348
// 0.4 becomes a fifty-character string. The status page embeds 1,728 points
// on four series per monitor every 24 h - this alone grew the page
// to 1.6 MB and made the server spend that long assembling it.
//
// PHP defaults to -1 since 7.1; this hosting has it overridden.
// Set here because every page and API request passes through db.php.
ini_set('serialize_precision', '-1');

/**
 * Whether the caller of this request is a program that reads JSON.
 *
 * The API and the agent/node/heartbeat endpoints are called by the app and by
 * agents, which parse the body; a browser opening a page wants a page.
 * Decided by the script first (those endpoints answer JSON whatever the caller
 * sends), then by a JSON content type the script already declared, then by an
 * Accept header that asks for JSON and not for HTML.
 */
function bk_request_wants_json(): bool {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $json_scripts = ['api.php', 'agent_api.php', 'node_api.php', 'heartbeat.php', 'health.php', 'cron.php', 'metrics.php'];
    if (in_array($script, $json_scripts, true)) {
        return true;
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
            return true;
        }
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false;
}

/**
 * The database cannot be reached: 503 for everyone, and nothing about why.
 *
 * This used to be a 500 page with the PDO message printed into it - the
 * database host, the account name and "config.php" for anyone who happened to
 * load a page during an outage - and the API answered that HTML page to the
 * app and to the agents, which cannot parse it. Now: 503 with Retry-After (an
 * outage of the database is temporary, and a client or crawler should come
 * back rather than drop the page), a JSON code for programs, the branded error
 * page for people, and the detail only in the server's error log.
 */
function bk_database_unavailable(Throwable $e): never {
    error_log('[db] database unavailable: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        // A cron run by hand: the operator is the reader, and nothing here is
        // served to anyone.
        fwrite(STDERR, 'Databáze je nedostupná: ' . $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
    }
    if (bk_request_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'database_unavailable']);
        exit;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    $bk_error_code = 503;
    require __DIR__ . '/error.php';
    exit;
}

/**
 * A response time as monitor_logs.response_time stores it: whole
 * milliseconds, or NULL when nothing was measured.
 *
 * The column is an INT, so a measured 0.3 ms (a router's WAN gateway one hop
 * away, a port on the same machine) went in as 0 - and 0 is how older failed
 * checks were logged, so every reader skips it as unmeasured and the
 * measurement was gone. A positive value is therefore at least 1 ms. NULL, a
 * non-number or a negative value measured nothing: NULL. A bare 0 is NULL too
 * (node_client.php, the PHP node since deleted, sent 0 for a failed check),
 * unless the caller knows that an answer arrived ($answered: a probe's 'up'
 * result, timed only after the answer came) - then it is an answer under
 * 1 ms the sender truncated: 1 ms.
 */
function bk_response_ms(mixed $ms, bool $answered = false): ?int {
    if (!is_numeric($ms)) {
        return null;
    }
    $ms = (float)$ms;
    if (!is_finite($ms) || $ms < 0 || ($ms === 0.0 && !$answered)) {
        return null;
    }
    return max(1, (int)round($ms));
}

/**
 * A monitor row's last_details as an array: [] when the column is missing,
 * NULL, empty, not JSON, or JSON that is not an object or a list.
 *
 * Every reader decoded it inline, in idioms that disagreed on exactly those
 * cases: `?? '{}'` gave [] for a missing or NULL column but NULL for '' and
 * broken JSON; `?? ''` and `(string)` gave NULL for all four; the ternary on
 * the raw value gave NULL; `?: []` gave [] for all four. Each reader then
 * read NULL and [] alike (`??`, isset, empty, is_array), so one answer
 * serves them all. index.php keeps its own decode, see there.
 */
function bk_monitor_details(array $row): array {
    $raw = $row['last_details'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $details = json_decode($raw, true);
    return is_array($details) ? $details : [];
}

/**
 * An address in the app next to this directory: /status/x.php with
 * '/profile' -> /app/profile.
 *
 * Built from SCRIPT_NAME (the server's own path, never request input), so an
 * install under /monitoring/status/ sends people to /monitoring/app/profile.
 */
function bk_app_url(string $path): string {
    $status_dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/x.php'))), '/');
    $base = rtrim(str_replace('\\', '/', dirname($status_dir === '' ? '/' : $status_dir)), '/');
    return $base . '/app' . $path;
}

/** The installer's address, next to this directory: /status/x.php -> /app/setup. */
function bk_setup_url(): string {
    return bk_app_url('/setup');
}

/**
 * This install is not set up yet (see $bk_setup_reason): 503 needs_setup.
 *
 * Programs get JSON - the app turns it into the installer, and the machine
 * endpoints (agents, heartbeat, cron, nodes, Prometheus) get an answer they
 * can parse instead of a redirect to a page they cannot use. A browser opening
 * a page goes to the installer. Nothing else is said: no path, no PDO text.
 */
function bk_needs_setup(string $reason): never {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Instalace není dokončená ({$reason}). Otevřete v prohlížeči /app/setup a projděte instalátor.\n");
        exit(1);
    }
    $setup_url = bk_setup_url();
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    if (bk_request_wants_json()) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'needs_setup', 'reason' => $reason, 'setupUrl' => $setup_url]);
        exit;
    }
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false && !headers_sent()) {
        header('Location: ' . $setup_url, true, 302);
        exit;
    }
    // An image, a feed or a bare client: neither JSON nor a page it could follow.
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "needs_setup\n";
    exit;
}

/**
 * What config.php printed while it loaded, or null when it printed nothing.
 *
 * @return array{bytes: int, where: list<string>, excerpt: string}|null
 */
function bk_config_output(): ?array {
    $issue = $GLOBALS['bk_config_output'] ?? null;
    return is_array($issue) ? $issue : null;
}

/**
 * Describes output that config.php printed: how many bytes, where in the file
 * they come from, and a short excerpt that is safe to show.
 *
 * where: before_open_tag (text or a BOM ahead of "<?php"), after_close_tag
 * (text after the last "?>"; PHP swallows the one newline right after it, so
 * the classic "?>\n" prints nothing) and inside (what neither explains: an
 * echo, or a notice PHP displayed while the file ran).
 *
 * @return array{bytes: int, where: list<string>, excerpt: string}
 */
function bk_config_output_issue(string $printed, string $source): array {
    $where = [];
    $explained = 0;
    $open = stripos($source, '<?php');
    if ($open === false) {
        $open = strpos($source, '<?');
    }
    if ($open !== false && $open > 0 && str_starts_with($printed, substr($source, 0, $open))) {
        $where[] = 'before_open_tag';
        $explained += $open;
    }
    $close = strrpos($source, '?>');
    if ($close !== false && strpos($source, '<?', $close) === false) {
        $trail = (string)preg_replace('/^(\r\n|\n|\r)/', '', substr($source, $close + 2));
        if ($trail !== '' && strlen($printed) - $explained >= strlen($trail) && str_ends_with($printed, $trail)) {
            $where[] = 'after_close_tag';
            $explained += strlen($trail);
        }
    }
    if ($explained < strlen($printed)) {
        $where[] = 'inside';
    }
    return ['bytes' => strlen($printed), 'where' => $where, 'excerpt' => bk_config_output_excerpt($printed)];
}

/**
 * The first 16 bytes of the stray output, safe to show. The output of
 * config.php can be a line of the file itself, a password included, so no
 * more than this is kept and anything that looks like a secret is masked.
 *
 * Masking comes first and judges each whole run of non-blank bytes: cut
 * first, and the start of a long token would pass for a short word. A run is
 * masked when it is 12 bytes or longer, or 6 or longer with anything but
 * letters in it (a digit, a symbol, a byte outside ASCII) or a capital after
 * a small letter. Short words and plain words stay readable; a masked run
 * that crosses byte 16 shows as its mask, never as its first bytes. The rest
 * becomes printable ASCII: control bytes and anything outside ASCII as \n,
 * \r, \t or \xNN (a BOM stays visible).
 */
function bk_config_output_excerpt(string $printed): string {
    $out = '';
    $used = 0;
    preg_match_all('/[ \t\n\r\x0B\x0C]+|[^ \t\n\r\x0B\x0C]+/', $printed, $runs);
    foreach ($runs[0] as $run) {
        if ($used >= 16) {
            break;
        }
        $len = strlen($run);
        if ($len >= 6 && !str_contains(" \t\n\r\x0B\x0C", $run[0])
            && ($len >= 12 || preg_match('/[^A-Za-z]|[a-z][A-Z]/', $run) === 1)) {
            $out .= '•••';
            $used += $len;
            continue;
        }
        for ($i = 0; $i < $len && $used < 16; $i++, $used++) {
            $c = $run[$i];
            $o = ord($c);
            $out .= match (true) {
                $c === "\n" => '\n',
                $c === "\r" => '\r',
                $c === "\t" => '\t',
                $o >= 0x20 && $o < 0x7F => $c,
                default => sprintf('\x%02X', $o),
            };
        }
    }
    return strlen($printed) > $used ? $out . '…' : $out;
}

/**
 * One line in the PHP error log about the stray output: when it first shows
 * up, when it changes, and then once an hour - not on every request.
 * Without a writable stamp file it logs every time (loud beats silent).
 *
 * @param array{bytes: int, where: list<string>, excerpt: string} $issue
 */
function bk_config_output_log(array $issue): void {
    // Only a hash on disk: even masked, the excerpt has no business in a file.
    $signature = md5($issue['bytes'] . '|' . implode(',', $issue['where']) . '|' . $issue['excerpt']);
    $dir = is_dir(__DIR__ . '/cache') && is_writable(__DIR__ . '/cache') ? __DIR__ . '/cache' : sys_get_temp_dir();
    $stamp = $dir . '/bk_config_output_' . md5(__DIR__) . '.txt';
    if (is_file($stamp) && (string)@file_get_contents($stamp) === $signature && time() - (int)@filemtime($stamp) < 3600) {
        return;
    }
    @file_put_contents($stamp, $signature);
    error_log('[db] config.php prints ' . $issue['bytes'] . ' bytes (' . implode(', ', $issue['where'])
        . '), starting "' . $issue['excerpt'] . '". They are held back from every response; remove them from the file.');
}

/**
 * config.php still carries the sample's placeholder password.
 *
 * Both placeholders config.sample.php has ever shipped. Only a file like that
 * may be replaced by the installer; a real password marks a real install.
 */
function bk_config_is_placeholder(): bool {
    return defined('DB_PASS') && in_array(DB_PASS, ['heslo_databaze', 'database_password'], true);
}

/**
 * The PDO DSN for these settings - shared by the bootstrap and the installer's
 * connection test, so "the test connected" means "the app will connect".
 */
function bk_db_dsn(string $driver, string $host, ?int $port, string $name): string {
    if ($driver === 'pgsql' || $driver === 'postgres') {
        return "pgsql:host=" . $host . ";port=" . ($port ?? 5432) . ";dbname=" . $name;
    }
    // DB_PORT used to apply only to Postgres, so MySQL on a non-default port
    // never connected and the user only saw a generic connection error.
    return "mysql:host=" . $host . ";port=" . ($port ?? 3306) . ";dbname=" . $name . ";charset=utf8mb4";
}

/**
 * Whether a failed statement only says its change is already there:
 * 1050 table, 1060 column, 1061 index, 1826 foreign key, and with $rows
 * 1062 row.
 *
 * That is what re-running a statement that took effect produces, both in the
 * installer's schema.sql import and in the migrations (migrations.php). Only
 * the import passes $rows: its seed INSERTs meet their own rows on a re-run,
 * while in the migrations a 1062 is a UNIQUE index the data breaks. Any other
 * error is real, and neither of them treats it as done.
 */
function bk_db_already_applied(PDOException $e, bool $rows = false): bool {
    $driver = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
    return in_array($driver, [1050, 1060, 1061, 1826], true) || ($rows && $driver === 1062);
}

/**
 * The failed migration run stored for $version (settings key
 * schema_migrations_failed), or null when there is none for it: no record,
 * one for another version (a newer list may hold the fix, so it runs at
 * once), or one that does not read.
 *
 * @return array{at: int, retry: int, steps: list<int>}|null
 */
function bk_schema_failure(mixed $record, string $version): ?array {
    $data = is_string($record) ? json_decode($record, true) : null;
    if (!is_array($data) || ($data['version'] ?? null) !== $version
        || !is_int($data['at'] ?? null) || !is_int($data['retry'] ?? null)) {
        return null;
    }
    $steps = is_array($data['steps'] ?? null) ? array_values(array_filter($data['steps'], 'is_int')) : [];
    return ['at' => $data['at'], 'retry' => $data['retry'], 'steps' => $steps];
}

/**
 * Whether this request runs the migrations: unless a run for this version
 * failed and its retry time has not come. A clock set back to before the
 * failure does not make it wait longer.
 *
 * @param array{at: int, retry: int, steps: list<int>}|null $failure
 */
function bk_schema_migrations_due(?array $failure, int $now): bool {
    return $failure === null || $now < $failure['at'] || $now >= $failure['retry'];
}

/**
 * The session's time zone, and whether it turns a winter and a summer time of
 * this year into the same instants PHP does - i.e. whether a time stored on
 * the other side of a DST switch reads back right.
 *
 * @return array{zone: string, agrees: bool}
 */
function bk_db_clock(PDO $pdo): array {
    $winter = date('Y') . '-01-15 12:00:00';
    $summer = date('Y') . '-07-15 12:00:00';
    $stmt = $pdo->prepare('SELECT @@session.time_zone AS tz, UNIX_TIMESTAMP(?) AS w, UNIX_TIMESTAMP(?) AS s');
    $stmt->execute([$winter, $summer]);
    $row = (array)$stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'zone'   => (string)($row['tz'] ?? ''),
        'agrees' => (int)($row['w'] ?? 0) === strtotime($winter) && (int)($row['s'] ?? 0) === strtotime($summer),
    ];
}

/**
 * Puts the MySQL session on PHP's clock and returns the zone it ended on.
 *
 * The server's own zone stays when it already agrees with PHP in winter and
 * summer (one query). Otherwise PHP's zone by name, which needs the MySQL
 * time-zone tables and is kept only if those tables agree with PHP too (stale
 * ones do not: Brazil dropped DST in 2019). Only then today's UTC offset - the
 * one setting that reads a time from the other side of a DST switch an hour
 * off, and what every request ran on before. Throws when even that is refused.
 */
function bk_db_clock_sync(PDO $pdo, string $zone): string {
    $clock = bk_db_clock($pdo);
    if ($clock['agrees']) {
        return $clock['zone'];
    }
    $set = $pdo->prepare('SET time_zone = ?');
    try {
        $set->execute([$zone]);
        if (bk_db_clock($pdo)['agrees']) {
            return $zone;
        }
    } catch (PDOException $e) {
        // No time-zone tables, the rule on shared hosting: not a log line per request.
    }
    $set->execute([date('P')]);
    return date('P');
}

try {
    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        throw new PDOException('config.php does not define DB_HOST, DB_NAME, DB_USER and DB_PASS');
    }
    $db_driver = defined('DB_DRIVER') ? strtolower(DB_DRIVER) : (defined('BK_DATABASE_URL') && strpos(BK_DATABASE_URL, 'postgres') !== false ? 'pgsql' : 'mysql');
    $dsn = bk_db_dsn($db_driver, (string)DB_HOST, defined('DB_PORT') ? (int)DB_PORT : null, (string)DB_NAME);
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // The session runs in PHP's time zone, because the application constantly
    // compares the two sides: SQL NOW() / CURDATE() against timestamps
    // formatted in PHP (last_checked, checked_at, the day of a daily row).
    // Without this every such comparison silently depends on the database
    // server happening to run in the same zone as PHP - a fresh checkout
    // pointed at the local MySQL container (UTC) with TIMEZONE = 'Europe/Prague'
    // reported "checks have not run for 120 minutes" for EVERY monitor: a
    // two-hour shift and not one error anywhere.
    //
    // The current UTC offset alone (date('P'), e.g. "+01:00") used to be the
    // whole fix, and it is right only for times from today's side of a DST
    // switch: after the October switch a summer TIMESTAMP read as text came
    // back as a +01:00 wall clock that PHP takes for summer time, so July's
    // incidents, outages and notifications showed an hour early, and a
    // DATETIME from the summer went through UNIX_TIMESTAMP() an hour late.
    // bk_db_clock_sync() keeps the server's own zone when it agrees with PHP
    // in winter and summer, else sets PHP's zone by name (the MySQL time-zone
    // tables are often missing on shared hosting, and the name is refused
    // there), and only then falls back to that offset. health.php shows which
    // one a site ended on.
    //
    // MySQL only: Postgres reads a bare offset with the opposite sign, and
    // everything below is MySQL syntax anyway. A refused statement is logged
    // and the request continues - a clock read an hour wrong is bad, being
    // unable to open the site at all is worse.
    if ($db_driver !== 'pgsql' && $db_driver !== 'postgres') {
        try {
            bk_db_clock_sync($pdo, date_default_timezone_get());
        } catch (PDOException $e) {
            error_log('[db] the database refused the session time zone ' . date('P') . ': ' . $e->getMessage());
        }
    }

    // Schema version - bump when adding to the migrations (migrations.php) and
    // schema.sql, or a data fix (bk_data_fixes), to the same value as
    // bk_schema_migrations_version() there. Thanks to this, migrations run
    // only once, not on every request: the version is stored after a run
    // without a real error.
    define('BK_SCHEMA_VERSION', '20261101r024');

    $bk_current_schema = false;
    $bk_schema_rows = [];
    try {
        // The version and the failed run kept for it, in one query.
        $stmt_ver = $pdo->query("SELECT key_name, key_value FROM settings WHERE key_name IN ('schema_version', 'schema_migrations_failed')");
        $bk_schema_rows = $stmt_ver->fetchAll(PDO::FETCH_KEY_PAIR);
        $bk_current_schema = $bk_schema_rows['schema_version'] ?? false;
    } catch (PDOException $e) {
        // No settings table (42S02) = schema.sql was never imported: every
        // table the migrations would alter is missing too, and the few
        // they create would then shadow schema.sql's own definitions. That is
        // the installer's import step, not a migration. Any other error keeps
        // the old path: the migrations try to finish.
        if ((string)$e->getCode() === '42S02') {
            $bk_setup_reason = 'schema_missing';
        }
    }

    if ($bk_setup_reason === null && $bk_current_schema !== BK_SCHEMA_VERSION) {
        if (!bk_schema_migrations_due(bk_schema_failure($bk_schema_rows['schema_migrations_failed'] ?? null, BK_SCHEMA_VERSION), time())) {
            // A run for this version failed less than 10 minutes ago. Its
            // steps are in the log and health.php fails its schema_version
            // check; every step again on every request until the cause is
            // fixed would only add load and lock waits.
        } elseif (!is_file(__DIR__ . '/migrations.php')) {
            // Gone (a malware scanner's quarantine, an upload cut short): the
            // FTP sync state does not upload it again, and a require here was
            // a fatal error on every request. The site serves on; the version
            // stays until the file is back.
            error_log('[db] schema_version stays ' . var_export($bk_current_schema, true)
                . ': migrations.php is missing, the list did not run');
        } else {
            require_once __DIR__ . '/migrations.php';
            if (bk_schema_migrations_version() !== BK_SCHEMA_VERSION) {
                // A deploy uploads db.php and migrations.php one after the other.
                // A list for another version is not run: the old list would find
                // its steps applied and the new version would be stored without
                // the new ones, and a newer list may call a helper this db.php
                // lacks. The version stays; a request after the upload runs it.
                error_log('[db] schema_version stays ' . var_export($bk_current_schema, true) . ': migrations.php is for '
                    . bk_schema_migrations_version() . ', db.php for ' . BK_SCHEMA_VERSION . ', the list did not run');
            } else {
                $bk_migrations_failed = bk_schema_migrate($pdo, bk_schema_migrations());
                if ($bk_migrations_failed === []) {
                    // The migrations are skipped from the next request on.
                    try {
                        bk_setting_set($pdo, 'schema_version', BK_SCHEMA_VERSION);
                    } catch (PDOException $e) {
                        error_log('[db] schema_version not stored, the migrations run again: ' . $e->getMessage());
                    }
                    if (array_key_exists('schema_migrations_failed', $bk_schema_rows)) {
                        try {
                            $pdo->exec("DELETE FROM settings WHERE key_name = 'schema_migrations_failed'");
                        } catch (PDOException $e) {
                            error_log('[db] the record of a failed migration run was not deleted: ' . $e->getMessage());
                        }
                    }
                } else {
                    // Each failed step is in the log already. The version stays,
                    // and the run is kept with its time: the list runs again
                    // 10 minutes later, not on every request in between.
                    $bk_failed_at = time();
                    $bk_retry_at = $bk_failed_at + 600;
                    error_log('[db] schema_version stays ' . var_export($bk_current_schema, true) . ': '
                        . count($bk_migrations_failed) . ' migrations failed (' . implode(', ', $bk_migrations_failed) . '),'
                        . ' the list runs again after ' . date('H:i:s', $bk_retry_at));
                    try {
                        bk_setting_set($pdo, 'schema_migrations_failed', (string)json_encode([
                            'version' => BK_SCHEMA_VERSION, 'at' => $bk_failed_at, 'retry' => $bk_retry_at,
                            'steps' => $bk_migrations_failed,
                        ]));
                    } catch (PDOException $e) {
                        error_log('[db] the failed migration run was not recorded, the next request runs the list again: '
                            . $e->getMessage());
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    if (defined('BK_INSTALL_REQUEST')) {
        // The installer decides from the whole picture (a placeholder config
        // may be replaced, a real one never), so it gets "no connection", not
        // an exit. The reason stays in the log; the page never sees it.
        error_log('[db] installer: no database connection: ' . $e->getMessage());
        $pdo = null;
        return;
    }
    if (bk_config_is_placeholder()) {
        bk_needs_setup('config_sample');
    }
    bk_database_unavailable($e);
}

if ($bk_setup_reason === 'schema_missing') {
    if (defined('BK_INSTALL_REQUEST')) {
        // The connection is fine; importing schema.sql over it is the installer's job.
        return;
    }
    bk_needs_setup('schema_missing');
}

// Loads dynamic settings from the database
function get_settings($pdo) {
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT key_name, key_value FROM settings");
        while ($row = $stmt->fetch()) {
            $settings[$row['key_name']] = $row['key_value'];
        }
    } catch (PDOException $e) {
        // Table does not exist yet (e.g. before import) - ignore
    }
    return $settings;
}

$system_settings = get_settings($pdo);

// Helper: checks whether a setting is defined safely in config.php or the server environment
function is_setting_env_defined($key) {
    $const_name = strtoupper($key);
    return defined($const_name) || getenv($const_name) !== false || isset($_SERVER[$const_name]);
}

// Helper: fetch one setting with a default (config.php/environment take precedence)
// Never returns null (PHP 8.1+ deprecations when passed to htmlspecialchars etc.)
function get_setting($key, $default = '') {
    global $system_settings;

    $const_name = strtoupper($key);

    // Priority 1: a constant defined in config.php
    if (defined($const_name) && constant($const_name) !== null) {
        return constant($const_name);
    }

    // Priority 2: an environment variable (getenv)
    $env_val = getenv($const_name);
    if ($env_val !== false) {
        return $env_val;
    }

    // Priority 3: a server variable (e.g. from .htaccess)
    if (isset($_SERVER[$const_name])) {
        return $_SERVER[$const_name];
    }

    // Priority 4: the value stored in the database
    $val = $system_settings[$key] ?? null;

    // A key with a documented default answers that default while it is unset
    // OR stored empty. The empty row is what the settings form used to write
    // for a switch it had never loaded: `agent_notifications_enabled = ''`
    // then read as "off" and silenced every WAN, LTE, disk and firewall alert
    // after one unrelated save. The default comes from the one map the read
    // and write paths share, so the page and the cron cannot disagree.
    if ($val === null || $val === '') {
        $bk_defaults = bk_settings_defaults();
        if (array_key_exists($key, $bk_defaults)) {
            return $bk_defaults[$key];
        }
    }
    return $val === null ? $default : $val;
}

/**
 * Stores one setting and keeps this request's snapshot in step with the row.
 *
 * $system_settings is read once per request, and all but one of the upserts
 * this replaced (setup_completed) wrote the row only: a get_setting() after
 * them answered the old value for the rest of the request, so a cron run that
 * found ip_loc_local empty asked the geolocation service again for every
 * monitor. NULL stores NULL, as the upserts did. A failed write throws and
 * leaves the snapshot alone; whether that matters is the caller's call.
 *
 * Not for a write inside a transaction: a rollback takes the row back but
 * not the snapshot. The two bulk saves (admin.php, api.php save_settings)
 * keep their own upsert and reload or exit after the commit.
 */
function bk_setting_set(PDO $pdo, string $key, ?string $value): void {
    global $system_settings;
    $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)")
        ->execute([$key, $value]);
    if (!is_array($system_settings)) {
        // A migration writes before the snapshot is first read.
        $system_settings = [];
    }
    $system_settings[$key] = $value;
}

/**
 * Stores a setting only when no row has its key yet (a default a migration
 * seeds). An existing row keeps its value, and so does its snapshot entry; a
 * snapshot without the key takes what the row holds, seed or not.
 */
function bk_setting_add_if_absent(PDO $pdo, string $key, ?string $value): void {
    global $system_settings;
    $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = key_value")
        ->execute([$key, $value]);
    if (!is_array($system_settings)) {
        $system_settings = [];
    }
    if (!array_key_exists($key, $system_settings)) {
        // The row may predate this call with another value (a migration runs
        // before the snapshot is loaded), so the snapshot learns the row.
        $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        if ($row !== false) {
            $system_settings[$key] = $row[0];
        }
    }
}

/**
 * The single list of system settings keys.
 *
 * It used to exist three times: in admin.php for writing, and in api.php
 * separately for reading and for writing. They drifted apart - `whatsapp_*`
 * could be saved, but `get_settings` never returned them, so React showed
 * empty fields and the next "Save all" overwrote them with empty values.
 * Settings disappeared without anyone making a mistake. Hence one source of
 * truth; parity is additionally guarded by a test
 * (tests/run_settings_parity_lint.php).
 */
function bk_settings_keys(): array {
    return [
        'site_title', 'site_url', 'email_lang', 'cron_key', 'cron_location',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_secure',
        'sms_gateway_type', 'twilio_sid', 'twilio_token', 'twilio_from',
        'smsbrana_user', 'smsbrana_password',
        'agent_offline_timeout', 'agent_notifications_enabled', 'agent_notify_admin_only',
        // How many consecutive failures declare an outage. 1 = alert on the first.
        'alert_confirm_failures',
        'discord_webhook_url', 'telegram_bot_token', 'telegram_chat_id', 'slack_webhook_url',
        'oauth_github_client_id', 'oauth_github_client_secret',
        'oauth_google_client_id', 'oauth_google_client_secret',
        'oauth_discord_client_id', 'oauth_discord_client_secret',
        'oauth_gitlab_client_id', 'oauth_gitlab_client_secret',
        'custom_logo_url', 'custom_color_theme', 'custom_nav_links', 'portal_url',
        'metrics_token', 'sla_goal_pct', 'ts3_latest_version',
        'pushover_user_key', 'pushover_api_token', 'pagerduty_routing_key',
        'ssl_alert_days', 'agent_registration_token',
        'escalation_enabled', 'escalation_after_mins', 'escalation_webhook_url',
        // The daily reminder of what is still broken. On by default: an alert
        // fires only on a CHANGE of state, so without it a running outage is
        // announced once and then never mentioned again.
        'daily_reminder_enabled', 'daily_reminder_hour',
        'collection_max_age_secs', 'trusted_proxies',
        'process_history_days', 'process_history_peak_after_days', 'process_history_peak_pct',
    ];
}

/**
 * The default of every setting that has one, shared by reading and saving.
 *
 * Before this map each caller carried its own default and get_settings
 * answered '' for a key nobody had saved yet. The form showed that '' and the
 * next "Save all" stored it, and a stored '' beat the caller's default: the
 * agent-alert switch read as off, the admin-only switch as "everyone", the
 * offline timeout as 0. One save of an unrelated field changed alerting.
 *
 * Only keys whose every get_setting() caller agrees on the default belong
 * here; tests/run_settings_parity_lint.php fails when a caller disagrees.
 * Keys with no default (credentials, URLs, the site title that each page
 * words differently) stay out and keep '' as "not configured".
 */
function bk_settings_defaults(): array {
    return [
        'email_lang' => 'cs',
        'agent_offline_timeout' => '50',
        'agent_notifications_enabled' => '1',
        'agent_notify_admin_only' => '1',
        'alert_confirm_failures' => '1',
        'sla_goal_pct' => '99.95',
        'ssl_alert_days' => '14',
        'escalation_enabled' => '0',
        'escalation_after_mins' => '15',
        'daily_reminder_enabled' => '1',
        'daily_reminder_hour' => '8',
        'collection_max_age_secs' => '900',
        'process_history_days' => '30',
        'process_history_peak_after_days' => '0',
        'process_history_peak_pct' => '50',
    ];
}

/**
 * On/off switches. A switch is exactly '1' or '0': save_settings refuses
 * anything else, because an empty value is not a choice anybody made - it is
 * a form that never loaded the value - and storing it used to flip the switch.
 */
function bk_settings_boolean_keys(): array {
    return ['agent_notifications_enabled', 'agent_notify_admin_only', 'escalation_enabled', 'daily_reminder_enabled'];
}

/**
 * Keys whose value is masked when read, and whose masked value is ignored on
 * write (otherwise "Save all" would store the literal `••••••1234`).
 */
function bk_settings_secret_keys(): array {
    return [
        'smtp_pass', 'twilio_token', 'smsbrana_password',
        'oauth_github_client_secret', 'oauth_google_client_secret',
        'oauth_discord_client_secret', 'oauth_gitlab_client_secret',
        'pushover_api_token', 'pagerduty_routing_key', 'metrics_token',
        'agent_registration_token',
    ];
}
