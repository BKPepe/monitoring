import { cs } from './cs';
import { en, type Dict } from './en';

export type Lang = 'en' | 'cs';
export type { Dict };

export const dicts: Record<Lang, Dict> = { en, cs };

/** The page language follows its URL: everything under /cs/ is Czech. */
export function langFromPath(pathname: string): Lang {
  return pathname === '/cs' || pathname.startsWith('/cs/') ? 'cs' : 'en';
}

/** '/features' and '/features/' are the same page; compare them as one. */
export function withSlash(pathname: string): string {
  return pathname.endsWith('/') ? pathname : `${pathname}/`;
}

/**
 * The same page in both languages, for the language switch and hreflang.
 * '/cs/docs/' -> { en: '/docs/', cs: '/cs/docs/' }.
 */
export function alternates(pathname: string): Record<Lang, string> {
  const path = withSlash(pathname);
  const base = path.replace(/^\/cs(?=\/)/, '') || '/';
  return { en: base, cs: base === '/' ? '/cs/' : `/cs${base}` };
}
