<?php
/**
 * Hlídá, že schema.sql a migrace v db.php popisují stejnou databázi.
 *
 * Spuštění:  php apps/status/tests/run_schema_lint.php
 *
 * Proč vznikl: schéma se udržuje na dvou místech. `schema.sql` je to, co
 * dostane čerstvá instalace, migrace v `db.php` doplňují, co přibylo od
 * posledního importu. Nic je nedrželo pohromadě - a rozešly se: sedm sloupců
 * (preset_id, latency_threshold_ms/mins, acknowledged_by/at, postmortem,
 * lte_rsrp) existovalo jen v migracích.
 *
 * Dnes to projde jen shodou okolností. Verze v schema.sql je nižší než
 * BK_SCHEMA_VERSION, takže po importu doběhnou migrace a chybějící sloupce
 * doplní. Jakmile by ale někdo verze „srovnal" - což ten komentář na začátku
 * schema.sql přímo doporučuje - čerstvá instalace by se rozbila až za běhu,
 * na dotazu na neexistující sloupec.
 *
 * Kontroluje se obojí:
 *   1. Každý sloupec z `ALTER TABLE … ADD COLUMN` má být i v schema.sql.
 *   2. Každá tabulka zakládaná v migracích má být i v schema.sql.
 */

$db_src = file_get_contents(__DIR__ . '/../db.php');
$schema_src = file_get_contents(__DIR__ . '/../schema.sql');

if ($db_src === false || $schema_src === false) {
    fwrite(STDERR, "Nepodařilo se načíst db.php nebo schema.sql.\n");
    exit(1);
}

/** Tělo tabulky v schema.sql - hledá se v něm sloupec, ne v celém souboru. */
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
        // Tabulku řeší kontrola níž - tady by to byl druhý hlas o téže věci.
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
// Verze se schválně NEporovnávají na shodu. Dokud je v schema.sql nižší,
// migrace po importu doběhnou a případný zbytek dorovnají - je to bezpečná
// pojistka. Kontroluje se jen to, že v schema.sql opravdu nějaká je: bez ní
// by se migrace pouštěly při každém requestu.
// Verzí je v schema.sql víc (řádek v seed datech a přepis na konci souboru).
// Platí ta poslední - ON DUPLICATE KEY UPDATE přepíše předchozí - takže se
// bere ona, jinak by hlášení ukazovalo hodnotu, která se nikdy neuplatní.
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
