<?php
/**
 * Keeps the API docs in step with api.php and with each other.
 *
 * Run:  php apps/status/tests/run_docs_parity_lint.php
 *
 * Why it exists: docs/api.md (English) and docs/api.cs.md (Czech) describe the
 * same interface. Two copies of anything in this project have drifted apart
 * every single time - the settings key list did it three ways and silently
 * erased data, the schema did it by seven columns. Documentation drifts more
 * quietly still: nothing breaks, one language just starts lying.
 *
 * Comparing the two languages kept them in step with each other, not with the
 * code: `status_page` was in neither. So both versions are also held against
 * the actions api.php really has, in both directions - an action nobody
 * documented, and a documented action that does not exist.
 *
 * Only the structure is compared, not the wording. Headings and the endpoint
 * names in `action=…` form are what carry the content; if one version gains a
 * section or an endpoint, the other has to gain it too.
 */

$repo = realpath(__DIR__ . '/../../..');
$en_path = $repo . '/docs/api.md';
$cs_path = $repo . '/docs/api.cs.md';
$api_path = $repo . '/apps/status/api.php';

foreach ([$en_path, $cs_path] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Chybí " . basename($path) . " - dvojjazyčná dokumentace má obě verze.\n");
        exit(1);
    }
}
if (!is_file($api_path)) {
    fwrite(STDERR, "Chybí apps/status/api.php - není s čím dokumentaci porovnat.\n");
    exit(1);
}

$en = file_get_contents($en_path);
$cs = file_get_contents($cs_path);
$api = file_get_contents($api_path);

/** Endpoints mentioned in the text - `action=something` anywhere in the file. */
function bk_doc_actions(string $text): array {
    preg_match_all('/action=([a-z_0-9]+)/', $text, $m);
    $found = array_unique($m[1] ?? []);
    sort($found);
    return $found;
}

/**
 * The api.php actions a doc names: every `action=x`, plus the bare names a
 * table row or a heading lists after it (`action=a` / `b` / `c`). A query
 * belongs to the script before its `?`, wherever `action=` stands in it, so
 * another script's (`node_api.php?action=…`, `x.php?key=…&action=…`) is left
 * out, and so is an `action=` that only ends a longer name (`data-action=`).
 */
function bk_doc_api_actions(string $text): array {
    // A mention starts a word: at a line start or after a space, a backtick,
    // a table pipe or a bracket. An optional path runs up to the `?`, then
    // any parameters that stand before `action=`.
    preg_match_all(
        '/(?<![^\s`|(\[])(?:([^\s`|()?&=]*)\?(?:[^\s`|()?&]*&)*)?action=([a-z_0-9]+)(?:[^`\n]*`((?:\s*\/\s*`[a-z_0-9]+`)+))?/',
        $text,
        $m,
        PREG_SET_ORDER
    );
    $found = [];
    foreach ($m as $hit) {
        if (($hit[1] ?? '') !== '' && basename($hit[1]) !== 'api.php') {
            continue;
        }
        $found[$hit[2]] = true;
        preg_match_all('/`([a-z_0-9]+)`/', $hit[3] ?? '', $more);
        foreach ($more[1] ?? [] as $name) {
            $found[$name] = true;
        }
    }
    $found = array_keys($found);
    sort($found);
    return $found;
}

/** Actions api.php handles - every `$action === 'x'`, compound conditions included. */
function bk_api_php_actions(string $src): array {
    preg_match_all("/\\\$action === '([a-z_0-9]+)'/", $src, $m);
    $found = array_unique($m[1] ?? []);
    sort($found);
    return $found;
}

/** Heading counts per level - a coarse but sensitive structure measure. */
function bk_doc_heading_shape(string $text): array {
    preg_match_all('/^(#{1,6}) /m', $text, $m);
    $shape = [];
    foreach ($m[1] ?? [] as $hashes) {
        $level = strlen($hashes);
        $shape[$level] = ($shape[$level] ?? 0) + 1;
    }
    ksort($shape);
    return $shape;
}

$problems = [];

$en_actions = bk_doc_actions($en);
$cs_actions = bk_doc_actions($cs);

foreach (array_diff($en_actions, $cs_actions) as $a) {
    $problems[] = sprintf('action=%s je v api.md, ale chybí v api.cs.md', $a);
}
foreach (array_diff($cs_actions, $en_actions) as $a) {
    $problems[] = sprintf('action=%s je v api.cs.md, ale chybí v api.md', $a);
}

$api_actions = bk_api_php_actions($api);
if ($api_actions === []) {
    fwrite(STDERR, "V api.php se nepodařilo najít žádnou akci - změnil se způsob dispatche?\n");
    exit(1);
}
// The reader itself, on lines that are not api.php's or are only in part:
// another script's query under any file name and with `action=` anywhere in
// it, and a name that merely ends in `action=`.
foreach ([
    'GET /status/api.php?monitor_id=1&action=a' => ['a'],
    '`node_api.php?action=b` / `c`' => [],
    '`agent-api.php?action=d`, `api2.php?action=e`' => [],
    'cpanel_stats.php?key=1&action=f' => [],
    '`data-action=g`' => [],
] as $probe => $want) {
    $got = bk_doc_api_actions($probe);
    if ($got !== $want) {
        $problems[] = sprintf('čtení akcí z dokumentace se plete: „%s“ dává [%s] místo [%s]',
            $probe, implode(', ', $got), implode(', ', $want));
    }
}
foreach (['api.md' => $en, 'api.cs.md' => $cs] as $doc_name => $doc_text) {
    $documented = bk_doc_api_actions($doc_text);
    foreach (array_diff($api_actions, $documented) as $a) {
        $problems[] = sprintf('api.php obsluhuje action=%s, ale %s ji nepopisuje', $a, $doc_name);
    }
    foreach (array_diff($documented, $api_actions) as $a) {
        $problems[] = sprintf('%s popisuje action=%s, kterou api.php nezná', $doc_name, $a);
    }
}

$en_shape = bk_doc_heading_shape($en);
$cs_shape = bk_doc_heading_shape($cs);
if ($en_shape !== $cs_shape) {
    $fmt = function (array $shape): string {
        $parts = [];
        foreach ($shape as $level => $count) {
            $parts[] = str_repeat('#', $level) . " ×{$count}";
        }
        return implode(', ', $parts);
    };
    $problems[] = sprintf(
        'struktura nadpisů se liší - api.md: %s | api.cs.md: %s',
        $fmt($en_shape),
        $fmt($cs_shape)
    );
}

// Both versions must link to the other, or nobody finds it.
if (!str_contains($en, 'api.cs.md')) {
    $problems[] = 'api.md neodkazuje na českou verzi';
}
if (!str_contains($cs, '](api.md)')) {
    $problems[] = 'api.cs.md neodkazuje na anglickou verzi';
}

if ($problems) {
    fwrite(STDERR, "Dokumentace API se rozešla s api.php nebo mezi jazyky:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nRozdíl se neprojeví chybou - jen jedna z verzí začne popisovat\n");
    fwrite(STDERR, "něco, co už neplatí, a nikdo si toho nevšimne.\n");
    exit(1);
}

printf(
    "Docs parity lint: obě verze popisují všech %d akcí api.php, %d endpointů a shodnou strukturu nadpisů.\n",
    count($api_actions),
    count($en_actions)
);
exit(0);
