import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Každý klíč použitý v t() musí být ve slovníku.
 *
 * t() umí fallback, takže chybějící klíč nic neshodí - jen se anglickému
 * uživateli ukáže česká věta. Přesně to se stalo u Discord karty: skript,
 * který klíče přidával, spadl před zápisem a nikdo si toho nevšiml,
 * protože v češtině vypadalo všechno správně.
 */

const SRC = join(__dirname, '..');

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((entry) => {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) return walk(full);
    return /\.tsx?$/.test(full) && !/\.test\.tsx?$/.test(full) ? [full] : [];
  });
}

const dictionarySource = readFileSync(join(SRC, 'context/language-context.tsx'), 'utf8');

/** Klíče definované ve slovníku: řádky tvaru  'klic': { cs: …, en: … } */
const definedKeys = new Set(Array.from(dictionarySource.matchAll(/^\s{2}'([^']+)':\s*\{/gm), (m) => m[1]));

/** Klíče použité v kódu: t('klic', …) */
function usedKeysIn(file: string): string[] {
  const source = readFileSync(file, 'utf8');
  return Array.from(source.matchAll(/\bt\(\s*'([a-z][a-zA-Z0-9_.]*)'/g), (m) => m[1]);
}

describe('i18n slovník', () => {
  it('obsahuje každý klíč použitý v t()', () => {
    const missing = new Map<string, string[]>();

    for (const file of walk(SRC)) {
      for (const key of usedKeysIn(file)) {
        if (!definedKeys.has(key)) {
          const short = file.slice(SRC.length + 1);
          missing.set(key, [...(missing.get(key) ?? []), short]);
        }
      }
    }

    // Výpis obsahuje soubory, aby šlo doplnit klíč bez dalšího hledání.
    const report = Array.from(missing.entries())
      .map(([key, files]) => `${key} (${[...new Set(files)].join(', ')})`)
      .sort();

    expect(report).toEqual([]);
  });

  it('má pro každý klíč českou i anglickou variantu', () => {
    // Tělo se čte párováním závorek, ne regexem: hodnoty obsahují
    // zástupné výrazy jako '{count}', na kterých by /\{([^}]*)\}/ skončil
    // předčasně a hlásil chybějící 'en' u desítek správných klíčů.
    const incomplete: string[] = [];
    const starts = dictionarySource.matchAll(/^\s{2}'([^']+)':\s*\{/gm);

    for (const start of starts) {
      const key = start[1];
      let depth = 0;
      let quote: string | null = null;
      let body = '';

      for (let i = start.index! + start[0].length - 1; i < dictionarySource.length; i++) {
        const ch = dictionarySource[i];
        const prev = dictionarySource[i - 1];

        if (quote) {
          if (ch === quote && prev !== '\\') quote = null;
        } else if (ch === "'" || ch === '"' || ch === '`') {
          quote = ch;
        } else if (ch === '{') {
          depth++;
        } else if (ch === '}') {
          depth--;
          if (depth === 0) break;
        }
        body += ch;
      }

      if (!/\bcs\s*:/.test(body) || !/\ben\s*:/.test(body)) {
        incomplete.push(key);
      }
    }

    expect(incomplete).toEqual([]);
  });
});
