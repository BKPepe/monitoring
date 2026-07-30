/**
 * Klientský přístup k datům z GitHubu.
 *
 * Všechno jde přes vlastní worker, nikdy přímo na api.github.com — worker
 * drží token, cachuje na hodinu a hlavně: IP návštěvníka neodchází třetí
 * straně. `connect-src` v CSP proto povoluje jen API_ORIGIN.
 */
import { API_ORIGIN } from '../config';

export interface Contributor {
  login: string;
  contributions: number;
  url: string;
}

export interface Release {
  tag: string;
  title: string;
  date: string;
  body: string;
  url: string;
  author: { name: string; url: string };
}

async function getJson<T>(path: string): Promise<T> {
  const res = await fetch(`${API_ORIGIN}${path}`);
  if (!res.ok) throw new Error(`${path} failed: HTTP ${res.status}`);
  return (await res.json()) as T;
}

export const fetchContributors = () => getJson<Contributor[]>('/api/contributors');
export const fetchReleases = () => getJson<Release[]>('/api/changelog');

/**
 * Escapování před vložením do innerHTML.
 *
 * Release notes píší lidé a GitHub je vrací jako syrový Markdown včetně
 * případného HTML. Bez escapování by se vložená značka vyrenderovala.
 * CSP sice zablokuje spuštění skriptu, ale defacement stránky ne.
 */
export function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

/**
 * Minimální Markdown → HTML pro těla releasů.
 *
 * Pořadí je zásadní: nejdřív se všechno zaescapuje, teprve pak se přidají
 * naše vlastní značky. Opačně by escapování zahodilo i to, co jsme přidali.
 */
export function renderReleaseBody(markdown: string, emptyText: string): string {
  if (!markdown?.trim()) return `<p>${escapeHtml(emptyText)}</p>`;

  const blocks: string[] = [];
  let listItems: string[] = [];

  const flushList = () => {
    if (listItems.length) {
      blocks.push(`<ul>${listItems.join('')}</ul>`);
      listItems = [];
    }
  };

  for (const rawLine of markdown.replace(/\r\n/g, '\n').split('\n')) {
    const line = rawLine.trim();

    if (!line) {
      flushList();
      continue;
    }

    if (line.startsWith('-') || line.startsWith('*')) {
      listItems.push(`<li>${escapeHtml(line.slice(1).trim())}</li>`);
      continue;
    }

    flushList();

    if (line.startsWith('#')) {
      const level = Math.min(6, (line.match(/^#+/)?.[0].length ?? 1) + 2);
      blocks.push(`<h${level}>${escapeHtml(line.replace(/^#+/, '').trim())}</h${level}>`);
      continue;
    }

    blocks.push(`<p>${escapeHtml(line)}</p>`);
  }

  flushList();
  return blocks.join('');
}

/** Monogram místo avataru z GitHubu — obrázek by byl další third-party request. */
export function initials(login: string): string {
  return login.slice(0, 2).toUpperCase();
}
