import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Rozpočet na velikost buildu.
 *
 * Proč: aplikace se dlouho posílala jako jeden soubor 1 523 kB, který se
 * musel celý stáhnout a rozparsovat, než se ukázalo cokoli - i když
 * uživatel otevřel jen dashboard. Rozdělení na líně načítané stránky to
 * srazilo na ~455 kB, ale nic nebránilo tomu, aby to zase narostlo:
 * jediný `import` na úrovni modulu přitáhne knihovnu zpět do vstupního
 * balíku a nikdo si toho nevšimne.
 *
 * Hlídá se VSTUPNÍ balík (ten čeká uživatel při prvním načtení), ne součet
 * všeho - líně načítané kusy uživatele nezdržují.
 */

const DIST = new URL('../dist/assets/', import.meta.url).pathname;

/** Strop pro vstupní chunk v kB. Nad tím se první načtení začne táhnout. */
const ENTRY_LIMIT_KB = 550;

/** Strop pro kterýkoli líně načítaný kus (detail zařízení nese grafy). */
const CHUNK_LIMIT_KB = 800;

let files;
try {
  files = readdirSync(DIST).filter((f) => f.endsWith('.js'));
} catch {
  console.error(`Build nenalezen v ${DIST} - spusťte nejdřív "npm run build".`);
  process.exit(1);
}

if (files.length === 0) {
  console.error('V dist/assets nejsou žádné .js soubory - build je prázdný?');
  process.exit(1);
}

const sizes = files
  .map((name) => ({ name, kb: statSync(join(DIST, name)).size / 1024 }))
  .sort((a, b) => b.kb - a.kb);

// Vstupní balík je ten, který index.html načítá jako modul; ve výstupu
// Vite se jmenuje index-*.js.
const entry = sizes.find((f) => f.name.startsWith('index-'));
if (!entry) {
  console.error('Nenašel jsem vstupní balík (index-*.js).');
  process.exit(1);
}

const problems = [];
if (entry.kb > ENTRY_LIMIT_KB) {
  problems.push(
    `vstupní balík ${entry.name} má ${entry.kb.toFixed(0)} kB, limit je ${ENTRY_LIMIT_KB} kB\n` +
      '    Nejčastější příčina: nový import na úrovni modulu vtáhl knihovnu do\n' +
      '    prvního načtení. Zvažte React.lazy() nebo dynamický import().'
  );
}
for (const f of sizes) {
  if (f !== entry && f.kb > CHUNK_LIMIT_KB) {
    problems.push(`chunk ${f.name} má ${f.kb.toFixed(0)} kB, limit je ${CHUNK_LIMIT_KB} kB`);
  }
}

console.log('Největší soubory buildu:');
for (const f of sizes.slice(0, 5)) {
  const mark = f === entry ? ' (vstupní)' : '';
  console.log(`  ${f.kb.toFixed(0).padStart(6)} kB  ${f.name}${mark}`);
}

if (problems.length > 0) {
  console.error('\nPřekročený rozpočet velikosti:\n');
  for (const p of problems) console.error(`  ${p}`);
  process.exit(1);
}

console.log(`\nVstupní balík ${entry.kb.toFixed(0)} kB / ${ENTRY_LIMIT_KB} kB - v rozpočtu.`);
