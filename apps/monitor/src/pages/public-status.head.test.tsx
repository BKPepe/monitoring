// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * W1-G4: the indexed public page names itself - a running outage leads the
 * title, a custom page is its own canonical URL, a missing one is not indexed.
 * usePublicStatus caches answers at module level, so every test moves the
 * clock on (as in public-status.failure.test.tsx).
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const monitor = (id: number, name: string, status: string) => ({
  id,
  name,
  type: 'web',
  status,
  category: 'Weby',
  responseMs: 120,
  lastCheck: '2026-09-23T10:00:00+02:00',
  lastStatusChange: null,
  details: null,
  assetId: id,
  cpu: null,
  ram: null,
  hdd: null,
});

function answering(monitors: { status: string }[], statusPage: Response | null = null) {
  return (url: string): Response => {
    if (url.includes('action=status_page')) return statusPage ?? json({ error: 'not_found' }, 404);
    if (url.includes('action=monitors')) return json({ monitors });
    if (url.includes('action=public_status'))
      return json({
        totalMonitors: monitors.length,
        downMonitors: monitors.filter((m) => m.status === 'down').length,
        uptimePercent: 99.9,
        avgLatencyMs: 100,
        nodes: [],
      });
    if (url.includes('action=regions')) return json({ regions: [] });
    if (url.includes('action=events')) return json({ events: [] });
    if (url.includes('action=incidents')) return json({ incidents: [], manualIncidents: [] });
    if (url.includes('action=daily_uptime')) return json({ series: {} });
    if (url.includes('action=uptime_windows')) return json({ windows: {} });
    if (url.includes('action=ui_config')) return json({ siteTitle: '', customNavLinks: [] });
    return json({});
  };
}

let clock = Date.UTC(2026, 8, 24, 8, 0, 0);
beforeEach(() => {
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }))
  );
  // The static head public.html ships.
  document.head.innerHTML =
    '<title>Stav služeb | Blood Kings</title>' +
    '<link rel="canonical" href="https://bloodkings.eu/app/public" />' +
    '<meta property="og:url" content="https://bloodkings.eu/app/public" />';
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function renderPage(path = '/public') {
  return render(
    <LanguageProvider>
      <MemoryRouter initialEntries={[path]}>
        <PublicStatusPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

describe('Hlavička veřejné stránky pro vyhledávače (W1-G4)', () => {
  it('běžící výpadek je v titulku první: „(2) výpadky · Stav služeb | Blood Kings“', async () => {
    const api = answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'down'), monitor(3, 'Web', 'up')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    await screen.findByText('2 služby mimo provoz');
    await waitFor(() => expect(document.title).toBe('(2) výpadky · Stav služeb | Blood Kings'));
  });

  it('vše v pořádku i selhané načtení: titulek bez počtu výpadků', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();
    await screen.findByText('Všechny systémy jsou online');
    expect(document.title).toBe('Stav služeb | Blood Kings');
    cleanup();

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json({ error: 'database_unavailable' }, 500))
    );
    renderPage();
    await screen.findByText('Stav se nepodařilo zjistit');
    // A failed load neither claims nor denies an outage.
    expect(document.title).toBe('Stav služeb | Blood Kings');
  });

  it('vlastní stránka (?page=) je svou kanonickou adresou, neexistující má noindex', async () => {
    const page = json({ title: 'Herní servery', monitorIds: [1] });
    const api = answering([monitor(1, 'E-shop', 'up')], page);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage('/public?page=hry&lang=cs');
    await waitFor(() => expect(document.title).toBe('Herní servery | Blood Kings'));
    const canonical = document.head.querySelectorAll('link[rel="canonical"]');
    expect(canonical).toHaveLength(1);
    expect(canonical[0].getAttribute('href')).toBe('https://bloodkings.eu/app/public?page=hry');
    expect(document.head.querySelector('meta[property="og:url"]')?.getAttribute('content')).toBe(
      'https://bloodkings.eu/app/public?page=hry'
    );
    expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
    cleanup();

    const missing = answering([monitor(1, 'E-shop', 'up')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => missing(String(input)))
    );
    renderPage('/public?page=neni');
    await screen.findByText('Tato status stránka neexistuje nebo není veřejná.');
    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe('noindex');
  });
});
