// Raw Tailwind palette colours (text-emerald-400, bg-slate-900, ...) are not
// allowed in the SPA. Every colour there goes through the tokens in
// apps/monitor/src/styles/theme.css - up / down / warning / info / paused for
// a verdict, muted-foreground / border / card / secondary for chrome - so it
// has a light and a dark value and a measured contrast on both.
//
// The rule exists because the palette crept back in 389 places over the
// months, most of them a bare -400 step tuned for a dark canvas: on a white
// card that is 1.4 to 2.1:1, and the same silent agent was amber on one page
// and grey on the next. Cleaning it up once took a day; a grep in CI keeps it
// clean for free.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'apps/monitor/src');

const FAMILIES =
  'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const UTILITIES = 'text|bg|border|ring|stroke|fill|accent|from|to|via|divide|outline|decoration|shadow|caret|placeholder';
const RAW = new RegExp(`(?:^|[\\s"'\`])(?:[a-z-]+:)*(?:${UTILITIES})-(?:${FAMILIES})-\\d{2,3}(?:/\\d{1,3})?(?=[\\s"'\`]|$)`, 'g');

function* walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* walk(p);
    else if (/\.(tsx|ts|css)$/.test(name) && !/\.test\.tsx?$/.test(name)) yield p;
  }
}

const hits = [];
for (const file of walk(SRC)) {
  const lines = readFileSync(file, 'utf8').split('\n');
  lines.forEach((line, i) => {
    for (const m of line.matchAll(RAW)) hits.push(`${relative(ROOT, file)}:${i + 1}: ${m[0].trim()}`);
  });
}

if (hits.length) {
  console.error(`Raw palette lint: ${hits.length} raw Tailwind colour(s) in the SPA - use the theme tokens instead:`);
  for (const h of hits) console.error(`  ${h}`);
  process.exit(1);
}
console.log('Raw palette lint: no raw Tailwind colours in the SPA, every colour goes through a token.');
