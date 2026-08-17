/**
 * Dead translation keys in the React dictionary.
 *
 * Reliable by construction: the i18n test forbids dynamically composed
 * t() keys, so a key that appears nowhere in src as a literal is dead.
 * Found 86 dead keys on its first manual run and 4 more two days later
 * (leftovers of a deleted card) - exactly why it now runs in CI instead
 * of relying on somebody remembering to re-run the scan.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const SRC = join(ROOT, 'apps/monitor/src');
const DICT = join(SRC, 'context/language-context.tsx');

const dict = readFileSync(DICT, 'utf8');
const keys = [...dict.matchAll(/^\s{2}'([^']+)':\s*\{/gm)].map((m) => m[1]);

let corpus = '';
const walk = (dir) => {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p);
    else if (/\.(ts|tsx)$/.test(name) && !p.endsWith('language-context.tsx')) corpus += readFileSync(p, 'utf8');
  }
};
walk(SRC);

const dead = keys.filter((k) => !corpus.includes(`'${k}'`) && !corpus.includes(`"${k}"`) && !corpus.includes('`' + k + '`'));

if (dead.length > 0) {
  console.error(`Dead i18n lint: ${dead.length} keys defined in the dictionary but used nowhere:`);
  for (const k of dead) console.error(`  ${k}`);
  process.exit(1);
}
console.log(`Dead i18n lint: ${keys.length} keys, all used.`);
