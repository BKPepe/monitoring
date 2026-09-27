import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { DICTIONARY_HEAD, ENGLISH_ID, splitDictionary } from '../../scripts/i18n-split';

/**
 * The production build splits the dictionary by language (PA-5). These tests
 * run the split on the real language-context.tsx, so a change to its layout
 * fails here and not in a build nobody looked at.
 */
const source = readFileSync(join(__dirname, 'language-context.tsx'), 'utf8');

describe('rozdělení slovníku podle jazyka při buildu (PA-5)', () => {
  it('česká půlka zůstane v modulu, anglická jde do vlastního chunku, žádný klíč se neztratí', () => {
    const split = splitDictionary(source);
    const head = split.code.indexOf(DICTIONARY_HEAD) + DICTIONARY_HEAD.length;
    const czech = JSON.parse(split.code.slice(head, split.code.indexOf(';\n', head))) as Record<string, { cs: string }>;
    const english = JSON.parse(split.english) as Record<string, string>;
    expect(Object.keys(czech)).toEqual(Object.keys(english));
    expect(split.keys).toBeGreaterThan(2000);
    expect(czech['nav.dashboard']).toEqual({ cs: 'Přehled' });
    expect(english['nav.dashboard']).toBe('Dashboard');
    expect(Object.values(czech).every((entry) => !('en' in entry))).toBe(true);
    // The loader imports the English chunk; nothing else of the module changes.
    expect(split.code).toContain(`import('${ENGLISH_ID}')`);
    expect(split.code).not.toContain('bk-i18n-split: the build puts');
    expect(split.code).toContain('export function prepareLanguage');
    expect(split.code.length).toBeLessThan(source.length * 0.75);
  });

  it('nečekaný tvar souboru build zastaví, ne tiše pokazí', () => {
    expect(() => splitDictionary(source.replace(DICTIONARY_HEAD, 'const dictionary = '))).toThrow(/header/);
    expect(() =>
      splitDictionary(
        source.replace("'nav.dashboard': { cs: 'Přehled', en: 'Dashboard' },", "'nav.dashboard': { cs: 'Přehled' },")
      )
    ).toThrow(/nav\.dashboard/);
    expect(() => splitDictionary(source.replace('// bk-i18n-split: the build puts', '// moved'))).toThrow(/stub/);
  });
});
