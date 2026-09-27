import type { Language } from './language-context';

/** What the first render may look at; the tests hand in their own. */
interface LanguageEnv {
  /** location.search, with or without the leading "?". */
  search: string;
  /** localStorage, or null where storage is blocked (private mode, tests). */
  storage: Pick<Storage, 'getItem' | 'setItem'> | null;
}

function browserEnv(): LanguageEnv {
  let storage: LanguageEnv['storage'] = null;
  try {
    storage = window.localStorage;
  } catch {
    // Reading the property itself throws where storage is blocked.
  }
  return { search: typeof location === 'undefined' ? '' : location.search, storage };
}

const asLanguage = (value: string | null | undefined): Language | null =>
  value === 'cs' || value === 'en' ? value : null;

/**
 * The language the app opens in (site W1-8).
 *
 * The site links its English visitors to the live demo with ?lang=en, and the
 * app used to read only its own stored choice, so they landed on a Czech page.
 * Order:
 * 1. `?lang=cs|en` in the address. It is stored, so the next visit without
 *    the parameter keeps it, and a later switch in the app wins from then on.
 * 2. The stored choice (`bk_lang`), which the language switch writes.
 * 3. Czech, the language the app was written in.
 *
 * The browser's language is deliberately not a step: an operator with an
 * English browser UI would find the app switched to English on every new
 * device, and every link from the site already says which language it wants.
 */
export function readInitialLanguage(env: LanguageEnv = browserEnv()): Language {
  const fromUrl = asLanguage(new URLSearchParams(env.search).get('lang'));
  if (fromUrl) {
    try {
      env.storage?.setItem('bk_lang', fromUrl);
    } catch {
      // Not stored: this visit still opens in the asked language.
    }
    return fromUrl;
  }
  try {
    return asLanguage(env.storage?.getItem('bk_lang')) ?? 'cs';
  } catch {
    // Blocked storage reads as "no choice yet".
    return 'cs';
  }
}
