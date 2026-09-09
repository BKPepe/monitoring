import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Rozpočet na velikost buildu.
 *
 * Proč: aplikace se dlouho posílala jako jeden soubor 1 523 kB, který se
 * musel celý stáhnout a rozparsovat, než se ukázalo cokoli - i když
 * uživatel otevřel jen dashboard. Rozdělení na líně načítané stránky to
 * srazilo pod půl megabajtu, ale nic nebránilo tomu, aby to zase narostlo:
 * jediný `import` na úrovni modulu přitáhne knihovnu zpět do prvního
 * načtení a nikdo si toho nevšimne.
 *
 * Měří se KRITICKÁ CESTA, ne jen vstupní chunk: prohlížeč stáhne všechno,
 * na co index.html ukazuje přes <script> a <link modulepreload>, ještě než
 * cokoli vykreslí. Měření jediného index-*.js hlásilo 449 kB tam, kde se
 * ve skutečnosti přenášelo 756 kB - rozpočet, který podhodnocuje o dvě
 * pětiny, nehlídá nic. Líně načítané kusy se do kritické cesty nepočítají,
 * ty uživatele při startu nezdržují.
 */

const DIST = new URL('../dist/', import.meta.url).pathname;
const ASSETS = join(DIST, 'assets');

/** Strop pro součet skriptů na kritické cestě, v kB. */
const CRITICAL_JS_LIMIT_KB = 750;

/** Strop pro CSS na kritické cestě. Blokuje vykreslení stejně jako skript. */
const CRITICAL_CSS_LIMIT_KB = 120;

/** Strop pro kterýkoli líně načítaný kus (detail zařízení nese grafy). */
const CHUNK_LIMIT_KB = 800;

let html;
try {
  html = readFileSync(join(DIST, 'index.html'), 'utf8');
} catch {
  console.error(`Build nenalezen v ${DIST} - spusťte nejdřív "npm run build".`);
  process.exit(1);
}

// Vite píše absolutní cesty s base (/app/assets/...); zajímá nás jméno souboru.
const referenced = [...html.matchAll(/(?:src|href)="([^"]*\/assets\/[^"]+)"/g)].map((m) =>
  m[1].slice(m[1].lastIndexOf('/') + 1)
);
const critical = [...new Set(referenced)];

if (critical.length === 0) {
  console.error('index.html neodkazuje na žádný asset - build je prázdný?');
  process.exit(1);
}

const sizeOf = (name) => {
  try {
    return statSync(join(ASSETS, name)).size / 1024;
  } catch {
    return null;
  }
};

const missing = critical.filter((name) => sizeOf(name) === null);
if (missing.length > 0) {
  console.error(`index.html odkazuje na soubory, které v dist/assets nejsou: ${missing.join(', ')}`);
  process.exit(1);
}

const criticalJs = critical.filter((n) => n.endsWith('.js')).map((name) => ({ name, kb: sizeOf(name) }));
const criticalCss = critical.filter((n) => n.endsWith('.css')).map((name) => ({ name, kb: sizeOf(name) }));
const jsTotal = criticalJs.reduce((sum, f) => sum + f.kb, 0);
const cssTotal = criticalCss.reduce((sum, f) => sum + f.kb, 0);

const lazy = readdirSync(ASSETS)
  .filter((f) => f.endsWith('.js') && !critical.includes(f))
  .map((name) => ({ name, kb: sizeOf(name) }))
  .sort((a, b) => b.kb - a.kb);

const problems = [];
if (jsTotal > CRITICAL_JS_LIMIT_KB) {
  problems.push(
    `skripty na kritické cestě mají ${jsTotal.toFixed(0)} kB, limit je ${CRITICAL_JS_LIMIT_KB} kB\n` +
      '    Nejčastější příčina: nový import na úrovni modulu vtáhl knihovnu do\n' +
      '    prvního načtení. Zvažte React.lazy() nebo dynamický import().'
  );
}
if (cssTotal > CRITICAL_CSS_LIMIT_KB) {
  problems.push(`CSS na kritické cestě má ${cssTotal.toFixed(0)} kB, limit je ${CRITICAL_CSS_LIMIT_KB} kB`);
}
for (const f of lazy) {
  if (f.kb > CHUNK_LIMIT_KB) {
    problems.push(`líně načítaný chunk ${f.name} má ${f.kb.toFixed(0)} kB, limit je ${CHUNK_LIMIT_KB} kB`);
  }
}

console.log('Kritická cesta (co prohlížeč stáhne před prvním vykreslením):');
for (const f of [...criticalJs, ...criticalCss].sort((a, b) => b.kb - a.kb)) {
  console.log(`  ${f.kb.toFixed(0).padStart(6)} kB  ${f.name}`);
}
if (lazy.length > 0) {
  console.log(`\nNejvětší líně načítané kusy (${lazy.length} celkem):`);
  for (const f of lazy.slice(0, 3)) {
    console.log(`  ${f.kb.toFixed(0).padStart(6)} kB  ${f.name}`);
  }
}

if (problems.length > 0) {
  console.error('\nPřekročený rozpočet velikosti:\n');
  for (const p of problems) console.error(`  ${p}`);
  process.exit(1);
}

console.log(
  `\nKritická cesta: skripty ${jsTotal.toFixed(0)} kB / ${CRITICAL_JS_LIMIT_KB} kB, ` +
    `CSS ${cssTotal.toFixed(0)} kB / ${CRITICAL_CSS_LIMIT_KB} kB - v rozpočtu.`
);
