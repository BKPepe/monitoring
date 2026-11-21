import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchLatestRelease, formatReleaseDate, releaseLine } from './release';

afterEach(() => {
  vi.unstubAllGlobals();
});

const answer = (body: unknown, status = 200) =>
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify(body), { status }))
  );

const words = { line: 'Latest release:', none: 'No release yet.', error: 'Could not be loaded.' };

describe('poslední vydání', () => {
  it('rozliší vydání, žádné vydání a chybu', async () => {
    answer({ monitoring: 'v0.3.0-alpha', latestReleaseDate: '2026-09-24' });
    expect(await fetchLatestRelease()).toEqual({ kind: 'release', tag: 'v0.3.0-alpha', date: '2026-09-24' });
    answer({ monitoring: null, latestReleaseDate: null });
    expect(await fetchLatestRelease()).toEqual({ kind: 'none' });
    answer({ error: 'GitHub API error: 502' }, 503);
    expect(await fetchLatestRelease()).toEqual({ kind: 'error' });
    // A string that is no version is an error, not something to print.
    answer({ monitoring: 'unknown' });
    expect(await fetchLatestRelease()).toEqual({ kind: 'error' });
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new TypeError('offline');
      })
    );
    expect(await fetchLatestRelease()).toEqual({ kind: 'error' });
  });

  it('řádek u tlačítka ukáže tag a datum, jinak řekne, co platí', () => {
    expect(releaseLine({ kind: 'release', tag: 'v0.3.0-alpha', date: '2026-09-24' }, words, 'en')).toBe(
      'Latest release: v0.3.0-alpha, 24 Sep 2026'
    );
    expect(releaseLine({ kind: 'release', tag: 'v0.3.0-alpha', date: '2026-09-24' }, words, 'cs')).toBe(
      'Latest release: v0.3.0-alpha, 24. 9. 2026'
    );
    expect(releaseLine({ kind: 'release', tag: 'v1.0.0', date: null }, words, 'en')).toBe('Latest release: v1.0.0');
    expect(releaseLine({ kind: 'none' }, words, 'en')).toBe('No release yet.');
    expect(releaseLine({ kind: 'error' }, words, 'cs')).toBe('Could not be loaded.');
  });

  it('datum bere jako kalendářní den v UTC a nic si nevymýšlí', () => {
    expect(formatReleaseDate('2026-01-01', 'en')).toBe('1 Jan 2026');
    expect(formatReleaseDate('2026-01-01', 'cs')).toBe('1. 1. 2026');
    expect(formatReleaseDate('yesterday', 'en')).toBeNull();
  });
});
