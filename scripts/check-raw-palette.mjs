// Colours in the SPA go through the theme tokens in
// apps/monitor/src/styles/theme.css - up / down / warning / info / paused for
// a verdict, muted-foreground / border / card / secondary for chrome - so each
// has a light and a dark value and a measured contrast on both.
//
// The palette crept back in 389 places over the months, most of them a bare
// -400 step tuned for a dark canvas: on a white card that is 1.4 to 2.1:1, and
// the same silent agent was amber on one page and grey on the next. Cleaning it
// up once took a day; this keeps it clean for free.
//
// Three rules:
// 1. No Tailwind palette colour in any form Tailwind accepts: every utility
//    (border-l-, ring-offset-, divide-), every variant (data-[state=open]:,
//    [&>svg]:, group-hover/row:), important markers, arbitrary opacity, and
//    @apply in CSS.
// 2. No arbitrary colour value (text-[#abc], bg-[rgb(...)]), except the brand
//    colours of third-party logos listed below - a logo's colour is not ours
//    to theme.
// 3. No opacity modifier on status text (text-warning/80): a token's contrast
//    is measured at full strength, and 80 % of it is not.
//
// The rules test themselves before scanning. A regex quietly loosened by a
// later edit would otherwise pass every file and look like a clean codebase.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'apps/monitor/src');

// Tailwind 4.3 ships mauve, olive, mist and taupe on top of the classic set.
const FAMILIES =
  'slate|gray|zinc|neutral|stone|mauve|olive|mist|taupe|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
const START = `(?<![\\w-])(?:[^\\s"'\`]*:)?!?`;
const OPACITY = `(?:\\/(?:\\d{1,3}|\\[[^\\]\\s]+\\]))?!?`;
const END = `(?=[\\s"'\`;,)}\\]]|$)`;

const RULES = [
  {
    name: 'raw palette colour',
    re: new RegExp(`${START}[a-z][a-z-]*-(?:${FAMILIES})-\\d{2,3}${OPACITY}${END}`, 'g'),
  },
  {
    name: 'arbitrary colour value',
    re: new RegExp(
      `${START}[a-z][a-z-]*-\\[(?:#[0-9a-fA-F]{3,8}|(?:rgba?|hsla?|oklch|oklab|lab|lch|color)\\()[^\\]\\s]*\\]${OPACITY}${END}`,
      'g'
    ),
    allow: (hit) => {
      const hex = hit.match(/\[(#[0-9a-fA-F]{3,8})\]/)?.[1]?.toLowerCase();
      return hex !== undefined && BRAND_COLOURS.has(hex);
    },
  },
  {
    name: 'opacity on status text',
    re: new RegExp(`${START}text-(?:up|down|warning|info|paused)\\/(?:\\d{1,3}|\\[[^\\]\\s]+\\])!?${END}`, 'g'),
  },
];

/** Third-party logo colours, the one fixed colour that is not ours to theme. */
const BRAND_COLOURS = new Set([
  '#5865f2', // Discord
  '#fc6d26', // GitLab
]);

const matches = (text) =>
  RULES.flatMap((rule) =>
    [...` ${text} `.matchAll(rule.re)].map((m) => m[0].trim()).filter((hit) => !rule.allow?.(hit))
  );

const MUST_FLAG = [
  'text-emerald-400',
  'border-l-amber-500',
  'ring-offset-red-500',
  'divide-slate-800',
  '@apply bg-slate-900;',
  'data-[state=open]:bg-red-500',
  '[&>svg]:text-red-500',
  '*:text-red-500',
  'group-hover/row:text-red-500',
  'md:hover:text-emerald-400/50',
  '!text-red-500',
  'text-red-500!',
  'bg-red-500/[0.3]',
  'text-olive-400',
  'text-[#abcdef]',
  'bg-[rgb(1,2,3)]',
  'fill-[oklch(0.5_0.1_20)]',
  'text-warning/80',
  "cn('x', ok ? 'text-rose-400' : '')",
];
const MUST_PASS = [
  'text-up',
  'bg-down/10',
  'border-warning/30',
  'hover:bg-up/90',
  'text-muted-foreground',
  'text-foreground/80',
  'stroke-muted',
  'bg-primary/90',
  'max-w-3xl',
  'text-2xs',
  'translate-y-1/2',
  'w-[calc(100%-2rem)]',
  '--chart-cpu: #15803d;',
  'text-[#5865F2]',
  'bg-[#fc6d26]/15',
];
const selfTest = [
  ...MUST_FLAG.filter((s) => matches(s).length === 0).map((s) => `not flagged: ${s}`),
  ...MUST_PASS.filter((s) => matches(s).length > 0).map((s) => `wrongly flagged: ${s}`),
];
if (selfTest.length) {
  console.error('Raw palette lint: the rules failed their own test - fix the regex before trusting a clean run:');
  for (const f of selfTest) console.error(`  ${f}`);
  process.exit(1);
}

function* walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) yield* walk(p);
    else if (/\.(tsx|ts|css)$/.test(name) && !/\.test\.tsx?$/.test(name)) yield p;
  }
}

const hits = [];
for (const file of walk(SRC)) {
  readFileSync(file, 'utf8')
    .split('\n')
    .forEach((line, i) => {
      for (const hit of matches(line)) hits.push(`${relative(ROOT, file)}:${i + 1}: ${hit}`);
    });
}

if (hits.length) {
  console.error(`Raw palette lint: ${hits.length} colour(s) outside the theme tokens:`);
  for (const h of hits) console.error(`  ${h}`);
  process.exit(1);
}
console.log(
  `Raw palette lint: ${MUST_FLAG.length + MUST_PASS.length} self-test cases pass, every colour in the SPA goes through a token.`
);
