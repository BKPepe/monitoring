<?php
/**
 * Guards that schema.sql and the migrations in db.php describe the same database.
 *
 * Running:  php apps/status/tests/run_schema_lint.php
 *
 * Why it exists: the schema is maintained in two places. `schema.sql` is what
 * a fresh install gets, the migrations in `db.php` top up what accrued since
 * the last import. Nothing held them together - and they drifted: seven columns
 * (preset_id, latency_threshold_ms/mins, acknowledged_by/at, postmortem,
 * lte_rsrp) existed only in the migrations.
 *
 * Today it passes only by luck. The version in schema.sql is lower than
 * BK_SCHEMA_VERSION, so after an import the migrations run and add the missing
 * columns. But the moment someone "aligned" the versions - which the comment
 * at the top of schema.sql outright recommends - a fresh install would break at
 * runtime, on a query against a nonexistent column.
 *
 * Both directions are checked:
 *   1. Every column from `ALTER TABLE … ADD COLUMN` must also be in schema.sql.
 *   2. Every table created in the migrations must also be in schema.sql.
 */

$db_src = file_get_contents(__DIR__ . '/../db.php');
$schema_src = file_get_contents(__DIR__ . '/../schema.sql');

if ($db_src === false || $schema_src === false) {
    fwrite(STDERR, "Nepodařilo se načíst db.php nebo schema.sql.\n");
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
preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN\s+`?(\w+)`?/i', $db_src, $col_matches, PREG_SET_ORDER);

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
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`(\w+)`/i', $db_src, $tbl_matches);
foreach (array_unique($tbl_matches[1] ?? []) as $table) {
    if (!str_contains($schema_src, "CREATE TABLE IF NOT EXISTS `{$table}`")) {
        $problems[] = "tabulka {$table} se zakládá v migracích, ale v schema.sql chybí";
    }
}

// --- 3. Verze ------------------------------------------------------------
//
// Versions are deliberately NOT compared for equality. As long as schema.sql is
// lower, migrations finish after an import and top up the rest - a safe
// safety net. Only its presence is checked: without one, migrations would
// run on every request.
// schema.sql holds several versions (a seed-data row and an overwrite at the end).
// The last one wins - ON DUPLICATE KEY UPDATE overwrites the previous - so it is
// the one taken, otherwise the report would show a value that never applies.
preg_match_all("/schema_version['\"]?,\s*'([0-9a-z]+)'/i", $schema_src, $sv_all);
$schema_version = !empty($sv_all[1]) ? end($sv_all[1]) : null;
if ($schema_version === null) {
    $problems[] = 'schema.sql nenastavuje schema_version - migrace by běžely při každém requestu';
}
if (!preg_match("/BK_SCHEMA_VERSION',\s*'([0-9a-z]+)'/", $db_src, $bv)) {
    $problems[] = 'db.php nedefinuje BK_SCHEMA_VERSION';
}

if ($problems) {
    fwrite(STDERR, "schema.sql a migrace v db.php se rozešly:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nDoplňte chybějící do schema.sql. Čerstvá instalace vychází z něj,\n");
    fwrite(STDERR, "takže co tam není, existuje jen díky tomu, že po importu doběhnou migrace.\n");
    exit(1);
}

printf(
    "Schema lint: schema.sql odpovídá migracím (%d sloupců, verze %s / %s)\n",
    $checked_columns,
    $schema_version ?? '?',
    $bv[1] ?? '?'
);
exit(0);
