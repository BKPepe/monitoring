import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Every key used in t() must exist in the dictionary.
 *
 * t() has a fallback, so a missing key crashes nothing - the English user
 * just sees a Czech sentence. Exactly that happened with the Discord card: the
 * script adding the keys crashed before writing and nobody noticed,
 * because in Czech everything looked right.
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

/** Keys defined in the dictionary: lines shaped  'key': { cs: …, en: … } */
const definedKeys = new Set(Array.from(dictionarySource.matchAll(/^\s{2}'([^']+)':\s*\{/gm), (m) => m[1]));

/** Keys used in code: t('key', …) */
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

    // The listing includes the files, so a key can be added without more searching.
    const report = Array.from(missing.entries())
      .map(([key, files]) => `${key} (${[...new Set(files)].join(', ')})`)
      .sort();

    expect(report).toEqual([]);
  });

  it('nepoužívá dynamicky skládané klíče', () => {
    // `t(`prefix.${x}`)` cannot be verified statically, so a missing translation
    // would slip past the test above too. When such a need arises, it belongs in
    // an explicit label map - see voice_activity in teamspeak-card.tsx.
    const dynamic: string[] = [];

    for (const file of walk(SRC)) {
      const source = readFileSync(file, 'utf8');
      if (/\bt\(\s*`[^`]*\$\{/.test(source)) {
        dynamic.push(file.slice(SRC.length + 1));
      }
    }

    expect(dynamic).toEqual([]);
  });

  it('má pro každý klíč českou i anglickou variantu', () => {
    // The body is read by bracket matching, not a regex: values contain
    // placeholders like '{count}', where /\{([^}]*)\}/ would stop early and
    // report a missing 'en' on dozens of correct keys.
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
