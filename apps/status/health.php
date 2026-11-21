<?php
/**
 * Blood Kings Monitoring - Health Check / Schema Validator
 * Verifies the DB schema is complete and the agent API will work.
 * Access: admin-only (web) or the CLI.
 */

require_once __DIR__ . '/db.php';

$is_cli = php_sapi_name() === 'cli';
if (!$is_cli) {
    // config.php has usually started the session already; a second call only
    // printed a notice. db.php set the cookie flags either way.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // The role is read from the users table, not the session: a demoted or
    // deleted administrator keeps the old session until logging out.
    $bk_health_role = false;
    try {
        $stmt_role = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt_role->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        $bk_health_role = $stmt_role->fetchColumn();
    } catch (Throwable $e) {
        $bk_health_role = false;
    }
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true || $bk_health_role !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
}

$checks = [];
$all_ok = true;

/**
 * Whether PHP can write its error log. Empty or "syslog" is no file: the
 * messages go to the server's log. A file PHP has not created yet (it does so
 * on the first message) is writable when its directory is. The check used to
 * test /tmp when no file was set, and failed on a log that did not exist yet.
 */
function bk_error_log_writable(string $path): bool {
    if ($path === '' || $path === 'syslog') {
        return true;
    }
    return file_exists($path) ? is_writable($path) : is_dir(dirname($path)) && is_writable(dirname($path));
}

function add_check(&$checks, &$all_ok, $name, $ok, $detail = '') {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $all_ok = false;
}

// 1. DB connection
add_check($checks, $all_ok, 'DB connection', true, 'PDO connected');

// 1b. config.php prints nothing. db.php holds such bytes back from every
// response, so the site works - which is exactly why this check has to fail:
// the file was edited by hand and nothing else would say so.
$bk_cfg_out = bk_config_output();
add_check($checks, $all_ok, 'config.php output', $bk_cfg_out === null, $bk_cfg_out === null
    ? 'prints nothing'
    : sprintf('prints %d bytes (%s), starting "%s"; held back from responses, remove them from the file',
        $bk_cfg_out['bytes'], implode(', ', $bk_cfg_out['where']), $bk_cfg_out['excerpt']));

// 1c. The database clock across DST (see bk_db_clock_sync in db.php). On
// today's offset alone everything stored on the other side of a DST switch
// reads an hour off, and nothing else on the site would say so.
if ($db_driver !== 'pgsql' && $db_driver !== 'postgres') {
    try {
        $bk_clock = bk_db_clock($pdo);
        add_check($checks, $all_ok, 'DB clock across DST', $bk_clock['agrees'], $bk_clock['agrees']
            ? 'session ' . $bk_clock['zone'] . ' converts winter and summer times as PHP does'
            : 'session ' . $bk_clock['zone'] . ' reads times from the other side of a DST switch an hour off;'
                . ' load the MySQL time-zone tables (mysql_tzinfo_to_sql) or run the server in ' . date_default_timezone_get());
    } catch (PDOException $e) {
        add_check($checks, $all_ok, 'DB clock across DST', false, $e->getMessage());
    }
}

// 2. Tables and columns: every one schema.sql creates (bk_schema_missing).
// Hand-picked lists here went stale and passed a database without
// metric_annotations (R280). What is missing is named, not explained: a
// migration still to run (schema_version below), one that was never written,
// or a table dropped by hand all look the same from here.
try {
    $schema_missing = bk_schema_missing($pdo);
    if ($schema_missing === null) {
        add_check($checks, $all_ok, 'Schema tables', false, 'schema.sql cannot be read or creates no table - upload it');
        add_check($checks, $all_ok, 'Schema columns', false, 'not checked without schema.sql');
    } else {
        add_check($checks, $all_ok, 'Schema tables', $schema_missing['tables'] === [], $schema_missing['tables'] === []
            ? 'every table of schema.sql'
            : 'Missing: ' . implode(', ', $schema_missing['tables']) . ' (schema.sql creates them)');
        add_check($checks, $all_ok, 'Schema columns', $schema_missing['columns'] === [], $schema_missing['columns'] === []
            ? 'every column of schema.sql in the tables present'
            : 'Missing: ' . implode(', ', $schema_missing['columns']) . ' (schema.sql defines them)');
    }
} catch (PDOException $e) {
    add_check($checks, $all_ok, 'Schema tables', false, 'Cannot read the schema: ' . $e->getMessage());
    add_check($checks, $all_ok, 'Schema columns', false, 'not checked without the schema');
}

// 3. schema_version: the one this db.php stores after its migrations. Any
// other value means they did not finish, though every request still works:
// a step failed for real (db.php tries again 10 minutes later, the error log
// names the cause), migrations.php is missing, or an upload is half done.
try {
    $stmt = $pdo->query("SELECT key_name, key_value FROM settings WHERE key_name IN ('schema_version', 'schema_migrations_failed')");
    $schema_rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $ver = (string)($schema_rows['schema_version'] ?? '');
    $schema_failure = bk_schema_failure($schema_rows['schema_migrations_failed'] ?? null, BK_SCHEMA_VERSION);
    if ($ver === BK_SCHEMA_VERSION) {
        $schema_detail = $ver;
    } elseif ($ver === '') {
        $schema_detail = 'NOT SET - import schema.sql';
    } elseif ($schema_failure !== null) {
        $schema_detail = sprintf('%s, not %s: migration %s %s failed at %s, next try after %s; the error log has the cause',
            $ver, BK_SCHEMA_VERSION, count($schema_failure['steps']) === 1 ? 'step' : 'steps', implode(', ', $schema_failure['steps']),
            date('Y-m-d H:i:s', $schema_failure['at']), date('H:i:s', $schema_failure['retry']));
    } elseif (!is_file(__DIR__ . '/migrations.php')) {
        $schema_detail = $ver . ', not ' . BK_SCHEMA_VERSION . ': migrations.php is missing, upload it';
    } else {
        $schema_detail = $ver . ', not ' . BK_SCHEMA_VERSION . ': the migrations did not finish; the error log says why';
    }
    add_check($checks, $all_ok, 'schema_version', $ver === BK_SCHEMA_VERSION, $schema_detail);
} catch (PDOException $e) {
    add_check($checks, $all_ok, 'schema_version', false, $e->getMessage());
}

// 4. Agent files exist
$agent_files = ['agent.sh', 'agent.py', 'agent.ps1', 'agent_openwrt.sh'];
foreach ($agent_files as $af) {
    $exists = file_exists(__DIR__ . '/' . $af);
    add_check($checks, $all_ok, "Agent file: $af", $exists, $exists ? '' : 'MISSING');
}

// 5. Writable check (error_log)
$log_path = (string)ini_get('error_log');
$log_ok = bk_error_log_writable($log_path);
add_check($checks, $all_ok, 'Error log writable', $log_ok, $log_ok
    ? ($log_path !== '' ? $log_path : 'server log')
    : 'Cannot write to error_log (' . $log_path . ')');

// Output
$result = [
    'success' => $all_ok,
    'status' => $all_ok ? 'healthy' : 'degraded',
    'timestamp' => date('c'),
    'checks' => $checks,
    'checks_total' => count($checks),
    'checks_passed' => count(array_filter($checks, fn($c) => $c['ok'])),
    'checks_failed' => count(array_filter($checks, fn($c) => !$c['ok'])),
];

if ($is_cli) {
    echo "=== BK Monitoring Health Check ===\n";
    foreach ($checks as $c) {
        $icon = $c['ok'] ? '✓' : '✗';
        echo "  $icon {$c['name']}" . ($c['detail'] ? " — {$c['detail']}" : '') . "\n";
    }
    // Not every failed check is the schema any more (config.php output).
    echo "\n" . ($all_ok ? 'ALL OK' : 'ISSUES FOUND - see the ✗ lines above') . "\n";
    exit;
}

// JSON API mode (?format=json)
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// HTML rendering for browser. The page stands alone, with no stylesheet or icon
// font from elsewhere: a diagnostic page has to open while the rest of the site
// is in trouble, and it runs on db.php only (run_tests.php holds it to that).
$site_title = 'Blood Kings';
try {
    $stmt_st = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'site_title'");
    $stmt_st->execute();
    $st = $stmt_st->fetchColumn();
    if ($st) $site_title = $st;
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/favicon.png">
    <title>Health Check — <?php echo htmlspecialchars($site_title); ?></title>
    <style>
        :root { color-scheme: light dark; --bg: #f5f6f8; --panel: #fff; --text: #16191f; --muted: #5a6370; --line: #dde1e6; --ok: #0e7346; --bad: #b3141e; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f1115; --panel: #171a20; --text: #e7e9ed; --muted: #9aa3af; --line: #2a2f37; --ok: #3ecf8e; --bad: #ff7b82; }
        }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 800px; margin: 0 auto; padding: 2rem 1rem; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; }
        .verdict { padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; border-left: 4px solid var(--ok); }
        .verdict.bad { border-left-color: var(--bad); }
        .verdict h1 { margin: 0; font-size: 1.2rem; }
        .verdict p { margin: 0.2rem 0 0; font-size: 0.82rem; color: var(--muted); }
        .verdict strong { color: var(--bad); }
        .check { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem; padding: 0.7rem 1.25rem; }
        .check + .check { border-top: 1px solid var(--line); }
        .mark { width: 1.1rem; text-align: center; font-weight: 700; color: var(--ok); }
        .name { flex: 1 1 12rem; font-size: 0.85rem; font-weight: 500; }
        .detail { font: 0.75rem ui-monospace, Menlo, Consolas, monospace; color: var(--muted); overflow-wrap: anywhere; }
        .fail .mark, .fail .detail { color: var(--bad); }
        nav { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; font-size: 0.85rem; }
    </style>
</head>
<body>
<main>
    <section class="panel verdict<?php echo $all_ok ? '' : ' bad'; ?>">
        <h1><?php echo $all_ok ? 'Vše v pořádku' : 'Nalezeny problémy'; ?></h1>
        <p>
            <?php echo $result['checks_passed']; ?>/<?php echo $result['checks_total']; ?> kontrol prošlo &middot; <?php echo date('j.n.Y H:i'); ?>
            <?php if (!$all_ok): ?> &middot; <strong>Opravte neprošlé kontroly níže</strong><?php endif; ?>
        </p>
    </section>

    <section class="panel">
        <?php foreach ($checks as $c): ?>
        <div class="check<?php echo $c['ok'] ? '' : ' fail'; ?>">
            <span class="mark" role="img" aria-label="<?php echo $c['ok'] ? 'prošlo' : 'neprošlo'; ?>"><?php echo $c['ok'] ? '✓' : '✗'; ?></span>
            <span class="name"><?php echo htmlspecialchars($c['name']); ?></span>
            <?php if ($c['detail']): ?>
                <span class="detail"><?php echo htmlspecialchars($c['detail']); ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </section>

    <nav>
        <a href="health.php">Zkontrolovat znovu</a>
        <a href="health.php?format=json">JSON výstup</a>
        <a href="<?php echo htmlspecialchars(bk_app_url('/')); ?>">Zpět do aplikace</a>
    </nav>
</main>
</body>
</html>
