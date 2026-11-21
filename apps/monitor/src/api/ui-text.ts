/**
 * The page's language as the data layer can read it. LanguageProvider keeps
 * <html lang> in step with the chosen language, so no React context is needed
 * here: the server's metric labels follow it (charts-22: an English page
 * titled its charts "Využití CPU"), and so do this layer's own messages.
 */
export function uiLang(): 'cs' | 'en' {
  return typeof document !== 'undefined' && document.documentElement.lang === 'en' ? 'en' : 'cs';
}

/**
 * One of the data layer's own messages in the page's language. Pages show
 * them as they come, and they were Czech on the English page too (R309).
 */
export function uiText(cs: string, en: string): string {
  return uiLang() === 'en' ? en : cs;
}
