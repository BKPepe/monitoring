// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * W1-A1: a failed request is never an all-clear on the public page.
 *
 * usePublicStatus shares answers for ten seconds at module level, so every
 * test moves the clock a minute on - otherwise a success cached by one test
 * would answer the next one.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const failAll = () => json({ error: 'database_unavailable' }, 500);

const monitor = (id: number, name: string, status: string, extra: Record<string, unknown> = {}) => ({
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
  ...extra,
});

/**
 * `collectionFresh` and `status` stand for the server's view of cron; `pageIds` answers a ?page= page;
 * `lastUpdated` is the newest check the server holds (what the freshness pill reads).
 */
function answering(
  monitors: unknown[],
  server: { collectionFresh?: boolean; status?: string; pageIds?: number[]; lastUpdated?: string } = {}
) {
  return (url: string): Response => {
    if (url.includes('action=monitors')) return json({ monitors, collectionFresh: server.collectionFresh ?? true });
    if (url.includes('action=status_page')) return json({ title: 'Weby', monitorIds: server.pageIds ?? [] });
    if (url.includes('action=public_status'))
      return json({
        status:
          server.status ?? ((monitors as { status: string }[]).some((m) => m.status === 'down') ? 'down' : 'healthy'),
        totalMonitors: monitors.length,
        downMonitors: (monitors as { status: string }[]).filter((m) => m.status === 'down').length,
        uptimePercent: 99.9,
        avgLatencyMs: 100,
        nodes: [],
        lastUpdated: server.lastUpdated ?? null,
      });
    if (url.includes('action=regions')) return json({ regions: [{ location: 'prague', successRate: 100 }] });
    if (url.includes('action=events')) return json({ events: [] });
    if (url.includes('action=incidents')) return json({ incidents: [], manualIncidents: [] });
    if (url.includes('action=daily_uptime')) return json({ series: {} });
    if (url.includes('action=uptime_windows')) return json({ windows: {} });
    if (url.includes('action=ui_config')) return json({ siteTitle: '', customNavLinks: [] });
    return json({});
  };
}

let clock = Date.UTC(2026, 8, 23, 8, 0, 0);
beforeEach(() => {
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
  // Vite defines the build version; the footer prints it.
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
  // Node hides jsdom's storage behind a flag, and jsdom has no matchMedia;
  // the theme toggle reads both.
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }))
  );
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

/** The figure under the verdict whose micro-label is `label` (a StatBlock of the hero's strip). */
function tileValueEl(label: string): HTMLElement | null {
  const labelEl = screen.getByText(label, { selector: '[data-slot="stat-block"] .micro-label span' });
  return labelEl.closest<HTMLElement>('[data-slot="stat-block"]')?.querySelector<HTMLElement>('p > span') ?? null;
}

function tileValue(label: string): string {
  return tileValueEl(label)?.textContent ?? '';
}

describe('Veřejná stránka: selhání není „vše v pořádku“ (W1-A1)', () => {
  it('všechna API vrací 500: nadpis „Stav se nepodařilo zjistit“, dlaždice s pomlčkou, žádné „online“', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => failAll())
    );
    renderPage();

    expect(await screen.findByText('Stav se nepodařilo zjistit')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
    expect(tileValue('Online')).toBe('—');
    // A green dash would still read as "fine".
    expect(tileValueEl('Online')?.className).not.toContain('text-up');
    expect(tileValue('Míst měření')).toBe('—');
    expect(tileValue('Mimo provoz')).toBe('—');
    expect(screen.getByText('Seznam služeb se nepodařilo načíst.')).toBeTruthy();
    // No endless spinner either: the list says it failed.
    expect(screen.queryByText('Načítám služby…')).toBeNull();
  });

  it('„Zkusit znovu“ po obnovení API ukáže skutečný stav', async () => {
    let healthy = false;
    const ok = answering([monitor(1, 'E-shop', 'up')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => (healthy ? ok(String(input)) : failAll()))
    );
    renderPage();
    await screen.findByText('Stav se nepodařilo zjistit');
    healthy = true;
    fireEvent.click(screen.getByRole('button', { name: 'Zkusit znovu' }));

    expect(await screen.findByText('Všechny systémy jsou online')).toBeTruthy();
    expect(screen.queryByText('Stav se nepodařilo zjistit')).toBeNull();
    await waitFor(() => expect(tileValue('Online')).toBe('1'));
  });

  it('zhoršená nebo mlčící služba: „částečně omezen“, nikdy „všechny online“', async () => {
    const api = answering([
      monitor(1, 'E-shop', 'up'),
      monitor(2, 'Web', 'up', { agentSilent: true }),
      monitor(3, 'Discord', 'warning'),
    ]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    expect(await screen.findByText('Provoz je částečně omezen')).toBeTruthy();
    expect(screen.getByText('2 služby hlásí zhoršení nebo neznámý stav')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
  });

  it('výpadek má přednost před zhoršením; jediná služba mimo provoz je v nadpisu jménem', async () => {
    const api = answering([monitor(1, 'E-shop', 'down'), monitor(3, 'Discord', 'warning')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    expect(await screen.findByText('E-shop mimo provoz')).toBeTruthy();
    expect(screen.queryByText('Provoz je částečně omezen')).toBeNull();
  });

  it('dostupnost za 30 dní a úspěšnost míst měření česky s desetinnou čárkou (PUB-14)', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    await screen.findByText('Všechny systémy jsou online');
    await waitFor(() => expect(tileValue('Dostupnost 30 dní')).toBe('99,90 %'));
    expect(await screen.findByText('100,00 %')).toBeTruthy();
  });

  it('jen neznámý stav (nikdy nehlášeno) také není „všechny online“', async () => {
    const api = answering([monitor(1, 'E-shop', 'up'), monitor(2, 'Nový web', 'unknown')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    expect(await screen.findByText('Provoz je částečně omezen')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
  });
});

describe('Veřejná stránka: zastavený sběr dat není „vše v pořádku“ (R3)', () => {
  const STALE_DESC = 'Pravidelné kontroly v poslední době neproběhly. Níže je poslední známý stav.';
  const UNKNOWN_DESC = 'Aktuální stav teď nelze ověřit. Níže je poslední známý stav.';
  // An agent report writes last_checked without cron, so the newest check can
  // be seconds old while nothing checks the rest.
  const recent = () => new Date(Date.now() - 30_000).toISOString();
  const pillOf = (c: HTMLElement) => c.querySelector('[data-slot="pill"][data-state]');

  it('cron stojí: server hlásí „unknown“, uložené stavy „up“ - nadpis „nelze ověřit“, nikdy „všechny online“', async () => {
    const api = answering([monitor(1, 'E-shop', 'up'), monitor(2, 'Wiki', 'up')], {
      collectionFresh: false,
      status: 'unknown',
      lastUpdated: recent(),
    });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    const { container } = renderPage();

    expect(await screen.findByText('Stav služeb nelze ověřit')).toBeTruthy();
    // The cause is known here: the server says cron has not run lately.
    expect(screen.getByText(STALE_DESC)).toBeTruthy();
    expect(screen.queryByText(UNKNOWN_DESC)).toBeNull();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
    // A check seconds old is not "live" while nothing checks the rest.
    expect(pillOf(container)?.getAttribute('data-state')).toBe('unverified');
    expect(pillOf(container)?.textContent).toMatch(/^Neověřeno· 30 s$/);
    expect(pillOf(container)?.textContent).not.toMatch(/Živě/);
  });

  it('serverový verdikt „unknown“ stačí sám, i když seznam tvrdí čerstvý sběr - příčinu ale nevymýšlí', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')], {
      collectionFresh: true,
      status: 'unknown',
      lastUpdated: recent(),
    });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    const { container } = renderPage();

    expect(await screen.findByText('Stav služeb nelze ověřit')).toBeTruthy();
    expect(screen.getByText(UNKNOWN_DESC)).toBeTruthy();
    expect(screen.queryByText(STALE_DESC)).toBeNull();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
    expect(pillOf(container)?.getAttribute('data-state')).toBe('unverified');
  });

  it('?page=: výběr „up“ při zastaveném sběru je „nelze ověřit“, i když výpadek jinde dělá serverový verdikt „down“', async () => {
    const api = answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'up')], {
      collectionFresh: false,
      pageIds: [2],
    });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage('/public?page=weby');

    expect(await screen.findByText('Stav služeb nelze ověřit')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
  });

  it('odpověď bez příznaku čerstvosti sběru není „všechny online“ a netvrdí, že cron stojí', async () => {
    // A server without the flag (or one whose status part did not deploy):
    // nobody knows whether cron runs, so the page says neither.
    const api = answering([monitor(1, 'E-shop', 'up')], { lastUpdated: recent() });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) =>
        String(input).includes('action=monitors')
          ? json({ monitors: [monitor(1, 'E-shop', 'up')] })
          : api(String(input))
      )
    );
    const { container } = renderPage();

    expect(await screen.findByText('Stav služeb nelze ověřit')).toBeTruthy();
    expect(screen.getByText(UNKNOWN_DESC)).toBeTruthy();
    expect(screen.queryByText(STALE_DESC)).toBeNull();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
    expect(pillOf(container)?.getAttribute('data-state')).toBe('unverified');
    expect(pillOf(container)?.textContent).not.toMatch(/Živě/);
  });

  it('výpadek při zastaveném sběru: nadpis výpadek, pilulka ale ne „Živě“', async () => {
    const api = answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'up')], {
      collectionFresh: false,
      lastUpdated: recent(),
    });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    const { container } = renderPage();

    expect(await screen.findByText('E-shop mimo provoz')).toBeTruthy();
    expect(pillOf(container)?.getAttribute('data-state')).toBe('unverified');
  });

  it('serverový „unknown“ dorazí dřív než seznam: pilulka hned „Neověřeno“, nikdy „Živě“', async () => {
    // The list hangs (an overloaded database); the server's verdict is in.
    const api = answering([monitor(1, 'E-shop', 'up')], { status: 'unknown', lastUpdated: recent() });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) =>
        String(input).includes('action=monitors') ? new Promise<Response>(() => {}) : api(String(input))
      )
    );
    const { container } = renderPage();

    await waitFor(() => expect(pillOf(container)).not.toBeNull());
    expect(screen.getByText('Zjišťuji stav…')).toBeTruthy();
    expect(pillOf(container)?.getAttribute('data-state')).toBe('unverified');
    expect(pillOf(container)?.textContent).not.toMatch(/Živě/);
  });

  it('seznam ještě nedorazil a server hlásí „healthy“: pilulka zůstává „Živě“, nebliká', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')], { lastUpdated: recent() });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) =>
        String(input).includes('action=monitors') ? new Promise<Response>(() => {}) : api(String(input))
      )
    );
    const { container } = renderPage();

    await waitFor(() => expect(pillOf(container)).not.toBeNull());
    expect(screen.getByText('Zjišťuji stav…')).toBeTruthy();
    expect(pillOf(container)?.getAttribute('data-state')).toBe('fresh');
  });

  it('?page= dokud seznam služeb nedorazil: „Zjišťuji stav…“, nikdy „všechny online“', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')], { pageIds: [1] });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) =>
        String(input).includes('action=monitors') ? new Promise<Response>(() => {}) : api(String(input))
      )
    );
    renderPage('/public?page=weby');

    // The page's title comes with its selection: from then on only the list is missing.
    expect(await screen.findByRole('heading', { level: 1, name: 'Weby' })).toBeTruthy();
    expect(screen.getByText('Zjišťuji stav…')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
  });

  it('čerstvý sběr a čerstvá kontrola: „Živě“ zůstává', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')], { collectionFresh: true, lastUpdated: recent() });
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    const { container } = renderPage();

    expect(await screen.findByText('Všechny systémy jsou online')).toBeTruthy();
    expect(pillOf(container)?.getAttribute('data-state')).toBe('fresh');
  });
});
