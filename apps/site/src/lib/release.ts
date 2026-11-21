/**
 * The latest release, for the line under the install guide's download button.
 *
 * The worker's /api/versions names GitHub's releases/latest, the release the
 * fixed ZIP URL serves: a version string, null when nothing is released yet,
 * or an error. Each of the three reads differently on the page, and none of
 * them invents a version. (The home page's live panel reads the same endpoint
 * on its own for "Latest server release".)
 */
import { API_ORIGIN } from '../config';

export type Lang = 'en' | 'cs';

export type ReleaseState = { kind: 'release'; tag: string; date: string | null } | { kind: 'none' } | { kind: 'error' };

export async function fetchLatestRelease(): Promise<ReleaseState> {
  try {
    const res = await fetch(`${API_ORIGIN}/api/versions`);
    if (!res.ok) return { kind: 'error' };
    const body = (await res.json()) as { monitoring?: unknown; latestReleaseDate?: unknown };
    if (body.monitoring === null) return { kind: 'none' };
    if (typeof body.monitoring !== 'string' || !/^v?\d[\w.+-]*$/.test(body.monitoring)) return { kind: 'error' };
    const date = typeof body.latestReleaseDate === 'string' ? body.latestReleaseDate : null;
    return { kind: 'release', tag: body.monitoring, date };
  } catch {
    return { kind: 'error' };
  }
}

// A fixed table, not Intl: ICU versions disagree ("Sep" vs "Sept"), and the
// page and its test must print the same thing everywhere.
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** 2026-09-24 as "24 Sep 2026" / "24. 9. 2026"; the day is a UTC calendar day. */
export function formatReleaseDate(isoDay: string, lang: Lang): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(isoDay)) return null;
  const date = new Date(`${isoDay}T00:00:00Z`);
  if (Number.isNaN(date.getTime())) return null;
  const [day, month, year] = [date.getUTCDate(), date.getUTCMonth(), date.getUTCFullYear()];
  return lang === 'cs' ? `${day}. ${month + 1}. ${year}` : `${day} ${MONTHS[month]} ${year}`;
}

/** The words the page supplies (src/i18n, server.upload), so this file has no copy of its own. */
export interface ReleaseWords {
  line: string;
  none: string;
  error: string;
}

/** "Latest release: v0.3.0-alpha, 24 Sep 2026", or what is true instead. */
export function releaseLine(state: ReleaseState, words: ReleaseWords, lang: Lang): string {
  if (state.kind === 'none') return words.none;
  if (state.kind === 'error') return words.error;
  const date = state.date ? formatReleaseDate(state.date, lang) : null;
  return `${words.line} ${date ? `${state.tag}, ${date}` : state.tag}`;
}

let pending: Promise<ReleaseState> | null = null;

/**
 * Fills every [data-release="line"] and [data-release="download"] on the page
 * from one request. The line carries its words and language in data-*
 * attributes; until the answer, the server-rendered "—" stays.
 */
export function fillReleaseInfo(root: ParentNode = document): void {
  pending ??= fetchLatestRelease();
  void pending.then((state) => {
    for (const el of root.querySelectorAll<HTMLElement>('[data-release]')) {
      if (el.dataset.release === 'line') {
        const lang: Lang = el.dataset.lang === 'cs' ? 'cs' : 'en';
        const words = {
          line: el.dataset.wordLine ?? '',
          none: el.dataset.wordNone ?? '',
          error: el.dataset.wordError ?? '',
        };
        el.textContent = releaseLine(state, words, lang);
        el.dataset.state = state.kind;
      } else if (el.dataset.release === 'download' && state.kind === 'none') {
        // The fixed URL answers 404 until the first release: the button
        // stops pretending there is something to download (V-21). After an
        // error it stays - a release may well exist.
        el.removeAttribute('href');
        el.setAttribute('aria-disabled', 'true');
        el.classList.add('is-disabled');
      }
    }
  });
}

/** Tests only: forget the shared request so each test asks again. */
export function resetReleaseCache(): void {
  pending = null;
}
