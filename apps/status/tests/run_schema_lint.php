<?php
/**
 * Guards that schema.sql and the migrations (migrations.php) describe the same database.
 *
 * Running:  php apps/status/tests/run_schema_lint.php
 *
 * Why it exists: the schema is maintained in two places. `schema.sql` is what
 * a fresh install gets, the migrations in `migrations.php` top up what accrued since
 * the last import. Nothing held them together - and they drifted: seven columns
 * (preset_id, latency_threshold_ms/mins, acknowledged_by/at, postmortem,
 * lte_rsrp) existed only in the migrations.
 *
 * It used to pass only by luck: schema.sql stored a version lower than
 * BK_SCHEMA_VERSION, so after an import the migrations ran and added the
 * missing columns, and the comment at the top of schema.sql recommended
 * "aligning" the two. Now schema.sql stores no version at all; the installer
 * stores one that is never BK_SCHEMA_VERSION, so the migrations always run
 * once over a fresh import.
 *
 * Both directions are checked:
 *   1. Every column from `ALTER TABLE … ADD COLUMN` must also be in schema.sql.
 *   2. Every table created in the migrations must also be in schema.sql.
 * And the version migrations.php says its list is for must be BK_SCHEMA_VERSION
 * in db.php, which runs the list only when the two agree.
 */

$db_src = file_get_contents(__DIR__ . '/../db.php');
$migrations_src = file_get_contents(__DIR__ . '/../migrations.php');
$schema_src = file_get_contents(__DIR__ . '/../schema.sql');

if ($db_src === false || $migrations_src === false || $schema_src === false) {
    fwrite(STDERR, "Nepodařilo se načíst db.php, migrations.php nebo schema.sql.\n");
    exit(1);
}

/** The table body in schema.sql - the column is searched there, not in the whole file. */
function bk_schema_table_body(string $schema, string $table): ?string {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS `' . preg_quote($table, '/') . '`\s*\((.*?)\n\)\s*ENGINE/s', $schema, $m)) {
        return null;
    }
    return $m[1];
}

$problems = [];

// --- 1. Sloupce ----------------------------------------------------------
preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN\s+`?(\w+)`?/i', $migrations_src, $col_matches, PREG_SET_ORDER);
// A lint that finds no migration at all checks nothing (a moved list).
if ($col_matches === []) {
    $problems[] = 'migrations.php nemá žádné ADD COLUMN - lint by nic neověřil';
}

$checked_columns = 0;
foreach ($col_matches as [$_, $table, $column]) {
    $body = bk_schema_table_body($schema_src, $table);
    if ($body === null) {
        // The table is handled by the check below - here it would be a second voice on the same thing.
        continue;
    }
    $checked_columns++;
    if (!preg_match('/^\s*`' . preg_quote($column, '/') . '`\s/mi', $body)) {
        $problems[] = "sloupec {$table}.{$column} je v migracích, ale ne v schema.sql";
    }
}

// --- 2. Tabulky ----------------------------------------------------------
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`(\w+)`/i', $migrations_src, $tbl_matches);
foreach (array_unique($tbl_matches[1] ?? []) as $table) {
    if (!str_contains($schema_src, "CREATE TABLE IF NOT EXISTS `{$table}`")) {
        $problems[] = "tabulka {$table} se zakládá v migracích, ale v schema.sql chybí";
    }
}

// --- 3. Verze ------------------------------------------------------------
//
// schema.sql stores no version: one written halfway through the file marked
// a cut-off import as finished (R214), and one equal to BK_SCHEMA_VERSION
// would skip the migrations that add what schema.sql lacks. The installer
// stores its own after the whole import, never BK_SCHEMA_VERSION.
$installer_src = (string)file_get_contents(__DIR__ . '/../installer.php');
if (preg_match("/'schema_version'/", $schema_src)) {
    $problems[] = 'schema.sql zapisuje schema_version - verzi ukládá až instalátor po celém importu';
}
if (!preg_match("/const BK_INSTALL_SCHEMA_VERSION = '([^']+)';/", $installer_src, $iv)
    || !str_contains($installer_src, "bk_setting_set(\$pdo, 'schema_version', BK_INSTALL_SCHEMA_VERSION)")) {
    $problems[] = 'installer.php po importu neuloží schema_version - migrace by běžely při každém requestu';
}
if (!preg_match("/BK_SCHEMA_VERSION',\s*'([0-9a-z]+)'/", $db_src, $bv)) {
    $problems[] = 'db.php nedefinuje BK_SCHEMA_VERSION';
}
// A bump in one of the two files alone: db.php would never run the list and
// every request would log that it did not.
if (!preg_match("/function bk_schema_migrations_version\(\): string \{\s*return '([0-9a-z]+)';/", $migrations_src, $mv)) {
    $problems[] = 'migrations.php neříká, pro kterou verzi je seznam (bk_schema_migrations_version)';
} elseif (isset($bv[1]) && $mv[1] !== $bv[1]) {
    $problems[] = "migrations.php je pro verzi {$mv[1]}, db.php pro {$bv[1]} - seznam by nikdy neběžel";
}
if (isset($iv[1], $bv[1]) && $iv[1] === $bv[1]) {
    $problems[] = 'instalátor ukládá BK_SCHEMA_VERSION - migrace by po importu nedoplnily, co schema.sql nemá';
}

if ($problems) {
    fwrite(STDERR, "schema.sql a migrace v migrations.php se rozešly:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nDoplňte chybějící do schema.sql. Čerstvá instalace vychází z něj,\n");
    fwrite(STDERR, "takže co tam není, existuje jen díky tomu, že po importu doběhnou migrace.\n");
    exit(1);
}

printf(
    "Schema lint: schema.sql odpovídá migracím (%d sloupců, verze po importu %s / %s)\n",
    $checked_columns,
    $iv[1] ?? '?',
    $bv[1] ?? '?'
);
exit(0);
