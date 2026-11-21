import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

/**
 * Hlídá exportovaný kód, který nikdo nepoužívá.
 *
 * Proč: detektor mrtvého kódu v apps/status pokrývá jen PHP. TypeScriptová
 * část tak roky nikoho nehlídala a nastřádaly se v ní komponenty, které
 * nikdo neimportuje - CardFooter, DialogTrigger, DialogClose.
 *
 * Bundler je sice z výsledného balíku vyhodí, takže uživatele nezdržují.
 * Cena je jiná: čte se to při revizi, udržuje se to při refaktoru a při
 * čtení kódu to vypadá jako součást aplikace.
 *
 * Hlásí se JEN běhový kód (function, const, class). Typy a rozhraní se do
 * výstupu vůbec nedostanou, takže zbytečné `export` u nich nic nestojí a
 * nemá smysl kvůli tomu upravovat dvacet souborů.
 */

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SRC = join(ROOT, 'apps/monitor/src');

/** Soubory, jejichž exporty spotřebovává něco mimo tenhle strom. */
const ENTRY_FILES = ['main.tsx', 'App.tsx', 'routes.tsx', 'vite-env.d.ts'];

function collect(dir, out = []) {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      collect(full, out);
    } else if (/\.tsx?$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

const files = collect(SRC).filter((f) => !/\.test\.tsx?$/.test(f));
const sources = new Map(files.map((f) => [f, readFileSync(f, 'utf8')]));

const dead = [];

for (const [file, code] of sources) {
  if (ENTRY_FILES.includes(relative(SRC, file))) continue;

  // Jen běhové exporty. `export type`/`interface`/`enum` se ignorují.
  const pattern = /^export\s+(?:async\s+)?(function|const|class)\s+([A-Za-z_]\w*)/gm;
  for (const match of code.matchAll(pattern)) {
    const name = match[2];
    const word = new RegExp(`\\b${name}\\b`);

    // Použití v jiném souboru?
    let usedElsewhere = false;
    for (const [other, otherCode] of sources) {
      if (other !== file && word.test(otherCode)) {
        usedElsewhere = true;
        break;
      }
    }
    if (usedElsewhere) continue;

    // Použití ve vlastním souboru mimo řádek definice? Pak jde jen o
    // zbytečné `export`, ne o mrtvý kód - to se nehlásí.
    const withoutDefinition = code.slice(0, match.index) + code.slice(match.index + match[0].length);
    if (word.test(withoutDefinition)) continue;

    dead.push({ name, file: relative(ROOT, file) });
  }
}

if (dead.length > 0) {
  console.error('Exportovaný kód, který nikdo nepoužívá:\n');
  for (const { name, file } of dead) {
    console.error(`  ${name}  (${file})`);
  }
  console.error('\nBundler to z balíku vyhodí, takže o rychlost nejde - jde o to,');
  console.error('že se to čte při revizi a udržuje při refaktoru. Smažte to, nebo');
  console.error('použijte. Testovací soubory se nepočítají jako použití.');
  process.exit(1);
}

console.log(`Dead export lint: ${sources.size} souborů, žádný nepoužitý běhový export.`);
