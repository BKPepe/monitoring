import type { Plugin } from 'vite';

/**
 * Splits the app's dictionary by language at build time (PA-5).
 *
 * src/context/language-context.tsx keeps every key as one { cs, en } pair:
 * the roles that write the app add both languages next to each other, and
 * several tests and scripts/check-dead-i18n.mjs read that file as text. But
 * a visitor reads one language, and the pairs made the dictionary the
 * largest piece of the first download (290 of the 800 kB budget).
 *
 * In the production build this plugin rewrites that one module: the Czech
 * half stays in it, the English half becomes the virtual module
 * `virtual:bk-i18n-en`, which Rollup emits as its own chunk, and the stub
 * loadEnglishHalf() becomes its dynamic import. prepareLanguage() in the same
 * file fetches it before English is first shown, so t() stays synchronous.
 * Tests and the dev server never run this plugin (apply: 'build'; vitest has
 * its own config) and see the source as written.
 *
 * Anything unexpected - the dictionary header, its end or the stub not found
 * exactly once, an entry without both languages - fails the build instead of
 * shipping a half-translated app.
 */

export const DICTIONARY_HEAD = 'const translations: Record<string, { cs: string; en: string }> = ';
export const ENGLISH_ID = 'virtual:bk-i18n-en';
const STUB = `  // bk-i18n-split: the build puts the import of the English half here.
  return null;`;
const LOADER = `  return import('${ENGLISH_ID}').then((m) => m.default);`;

export interface DictionarySplit {
  /** The module with the Czech half and the real loader. */
  code: string;
  /** The English half as a JavaScript object literal: { key: text }. */
  english: string;
  keys: number;
}

export function splitDictionary(source: string): DictionarySplit {
  const head = source.indexOf(DICTIONARY_HEAD);
  if (head < 0 || source.indexOf(DICTIONARY_HEAD, head + 1) >= 0) {
    throw new Error('i18n-split: the dictionary header is not in language-context.tsx exactly once');
  }
  const start = head + DICTIONARY_HEAD.length;
  const end = source.indexOf('\n};\n', start);
  if (end < 0) throw new Error('i18n-split: the end of the dictionary ("\\n};") was not found');
  const literal = source.slice(start, end + 2);

  // The literal is plain JavaScript (quoted strings and comments), written in
  // this repository and evaluated at build time only.
  const dictionary = new Function(`return (${literal});`)() as Record<string, { cs?: unknown; en?: unknown }>;
  const czech: Record<string, { cs: string }> = {};
  const english: Record<string, string> = {};
  for (const [key, entry] of Object.entries(dictionary)) {
    if (typeof entry?.cs !== 'string' || typeof entry?.en !== 'string') {
      throw new Error(`i18n-split: "${key}" does not have both a cs and an en string`);
    }
    czech[key] = { cs: entry.cs };
    english[key] = entry.en;
  }

  const stubAt = source.indexOf(STUB);
  if (stubAt < end || source.indexOf(STUB, stubAt + 1) >= 0) {
    throw new Error('i18n-split: the loadEnglishHalf() stub is not in language-context.tsx exactly once');
  }
  const code =
    source.slice(0, start) +
    JSON.stringify(czech) +
    ';' +
    source.slice(end + 3, stubAt) +
    LOADER +
    source.slice(stubAt + STUB.length);
  return { code, english: JSON.stringify(english), keys: Object.keys(english).length };
}

export function i18nSplit(): Plugin {
  let english: string | null = null;
  return {
    name: 'bk-i18n-split',
    apply: 'build',
    enforce: 'pre',
    transform(code, id) {
      if (!id.replace(/\\/g, '/').endsWith('/src/context/language-context.tsx')) return null;
      const split = splitDictionary(code);
      english = split.english;
      return { code: split.code, map: null };
    },
    resolveId(id) {
      return id === ENGLISH_ID ? `\0${ENGLISH_ID}` : null;
    },
    load(id) {
      if (id !== `\0${ENGLISH_ID}`) return null;
      if (english === null) this.error('i18n-split: the English half was asked for before the dictionary was read');
      return `export default ${english};`;
    },
  };
}
