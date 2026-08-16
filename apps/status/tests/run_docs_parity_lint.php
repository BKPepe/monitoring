<?php
/**
 * Keeps the two language versions of the API docs in step.
 *
 * Run:  php apps/status/tests/run_docs_parity_lint.php
 *
 * Why it exists: docs/api.md (English) and docs/api.cs.md (Czech) describe the
 * same interface. Two copies of anything in this project have drifted apart
 * every single time - the settings key list did it three ways and silently
 * erased data, the schema did it by seven columns. Documentation drifts more
 * quietly still: nothing breaks, one language just starts lying.
 *
 * Only the structure is compared, not the wording. Headings and the endpoint
 * names in `action=…` form are what carry the content; if one version gains a
 * section or an endpoint, the other has to gain it too.
 */

$repo = realpath(__DIR__ . '/../../..');
$en_path = $repo . '/docs/api.md';
$cs_path = $repo . '/docs/api.cs.md';

foreach ([$en_path, $cs_path] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Chybí " . basename($path) . " - dvojjazyčná dokumentace má obě verze.\n");
        exit(1);
    }
}

$en = file_get_contents($en_path);
$cs = file_get_contents($cs_path);

/** Endpointy zmíněné v textu - `action=neco` kdekoli v souboru. */
function bk_doc_actions(string $text): array {
    preg_match_all('/action=([a-z_0-9]+)/', $text, $m);
    $found = array_unique($m[1] ?? []);
    sort($found);
    return $found;
}

/** Počet nadpisů po úrovních - hrubá, ale citlivá míra struktury. */
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

// Obě verze musí odkazovat na tu druhou, jinak ji nikdo nenajde.
if (!str_contains($en, 'api.cs.md')) {
    $problems[] = 'api.md neodkazuje na českou verzi';
}
if (!str_contains($cs, '](api.md)')) {
    $problems[] = 'api.cs.md neodkazuje na anglickou verzi';
}

if ($problems) {
    fwrite(STDERR, "Jazykové verze dokumentace se rozešly:\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "  {$p}\n");
    }
    fwrite(STDERR, "\nRozdíl se neprojeví chybou - jen jedna z verzí začne popisovat\n");
    fwrite(STDERR, "něco, co už neplatí, a nikdo si toho nevšimne.\n");
    exit(1);
}

printf(
    "Docs parity lint: obě verze popisují %d endpointů se shodnou strukturou nadpisů.\n",
    count($en_actions)
);
exit(0);
