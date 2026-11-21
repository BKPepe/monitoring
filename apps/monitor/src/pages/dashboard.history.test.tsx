// @vitest-environment jsdom
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';

/**
 * UX wave 2 on main's dashboard: the history lists the worst rows first, an
 * empty fleet gets the first steps instead of an all-clear (site W1-5
 * conv-12), a silent agent is a row in "Vyžaduje pozornost" (CR-2/V-07) and
 * the state duration is labelled as one.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const NOW = Date.UTC(2026, 8, 23, 12, 0, 0);
const iso = (minutesAgo: number) => new Date(NOW - minutesAgo * 60_000).toISOString();

const monitor = (over: Record<string, unknown>) => ({
  status: 'up',
  category: 'Weby',
  target: 'https://example.test',
  type: 'web',
  responseMs: 120,
  cpu: null,
  ram: null,
  hdd: null,
  lastCheck: iso(1),
  lastStatusChange: iso(60 * 24),
  sinceStatusChangeSeconds: 86_400,
  details: {},
  ...over,
});

const FLEET = [
  monitor({ id: 1, name: 'E-shop', status: 'down', lastStatusChange: iso(51), sinceStatusChangeSeconds: 51 * 60 }),
  monitor({ id: 2, name: 'Wiki', status: 'warning' }),
  monitor({ id: 3, name: 'Blog' }),
  monitor({ id: 4, name: 'Server', type: 'vps', cpu: 97, ram: 40, hdd: 55 }),
];

const day = (status: string) => ({ date: '1.9.', status, uptimePct: status === 'nodata' ? null : 100 });
const days = (bad?: string) => Array.from({ length: 30 }, (_, i) => day(bad && i === 29 ? bad : 'up'));

function api(monitors: unknown[], series: Record<number, unknown[]> = {}, sslAlertDays?: number) {
  return (input: RequestInfo | URL): Promise<Response> => {
    const url = String(input);
    if (url.includes('action=session'))
      return Promise.resolve(json({ authenticated: true, user: { id: 1, username: 'admin', role: 'admin' } }));
    if (url.includes('action=monitors')) return Promise.resolve(json({ monitors, sslAlertDays }));
    if (url.includes('action=dashboard_layout')) return Promise.resolve(json({ catalog: [], tiles: [] }));
    if (url.includes('action=public_status'))
      return Promise.resolve(
        json({ status: 'healthy', totalMonitors: 4, downMonitors: 1, uptimePercent: 99.5, avgLatencyMs: 90, nodes: [] })
      );
    if (url.includes('action=daily_uptime')) return Promise.resolve(json({ series }));
    if (url.includes('action=findings')) return Promise.resolve(json({ findings: [], total: 0, sourceErrors: [] }));
    return Promise.resolve(json({}));
  };
}

// The dashboard loads four panels lazily (UX wave 2). Their first import is a
// module transform, and inside the timed test on a busy machine it pushed the
// run past the 5 s limit; warming them here keeps the test about the page.
beforeAll(async () => {
  await Promise.all([
    import('@/components/findings-list'),
    import('@/components/uptime-heatmap'),
    import('@/components/regions-panel'),
    import('@/components/dashboard-layout-editor'),
    // The traffic card's chunk brings the chart library.
    import('./dashboard-traffic'),
  ]);
}, 30_000);

beforeEach(() => {
  vi.spyOn(Date, 'now').mockImplementation(() => NOW);
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    }
  );
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

/** The count beside the "Vyžaduje pozornost" heading. */
function attentionCount(): string | null | undefined {
  const heading = screen.getByRole('heading', { name: /^Vyžaduje pozornost/ });
  return heading.querySelector('span')?.textContent;
}

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

describe('Dashboard: stav a jeho trvání (W2-1, CR-2)', () => {
  it('mlčící agent je řádek „Vyžaduje pozornost“ s dobou od změny stavu', async () => {
    const silent = monitor({
      id: 7,
      name: 'Router',
      type: 'openwrt',
      status: 'unknown',
      statusKey: 'unknown_stale',
      agentLastSeen: Math.round((NOW - 3 * 3600_000) / 1000),
      sinceStatusChangeSeconds: 2 * 3600,
    });
    vi.stubGlobal('fetch', vi.fn(api([...FLEET, silent])));
    renderPage();

    const row = (await screen.findByText('Agent přestal hlásit data')).closest('a') as HTMLElement;
    expect(row.getAttribute('href')).toBe('/infrastructure/7');
    expect(within(row).getByText('2 h')).toBeTruthy();
  });

  it('tabulka: „Ve stavu“ místo „Uptime“, doba ve stavu v jazyce stránky', async () => {
    vi.stubGlobal('fetch', vi.fn(api(FLEET)));
    renderPage();

    const table = await screen.findByRole('table', { name: 'Sledované Monitory & Služby' });
    const headers = within(table)
      .getAllByRole('columnheader')
      .map((h) => h.textContent);
    expect(headers).toContain('Ve stavu');
    expect(headers).not.toContain('Uptime');
    const shop = within(table).getByRole('link', { name: 'E-shop' }).closest('tr') as HTMLElement;
    expect(shop.lastElementChild?.previousElementSibling?.textContent).toBe('51 min');
  });
});

describe('Věta nad prstencem a stavy zařízení (W2-1, V-18)', () => {
  it('jmenuje jen problémové stavy, nejhorší první - žádný pozdrav', async () => {
    const silent = monitor({
      id: 7,
      name: 'Router',
      type: 'openwrt',
      status: 'unknown',
      statusKey: 'unknown_stale',
      agentLastSeen: Math.round((NOW - 3 * 3600_000) / 1000),
    });
    vi.stubGlobal('fetch', vi.fn(api([...FLEET, silent])));
    renderPage();

    const verdict = () => screen.getByTestId('fleet-verdict-sentence');
    await waitFor(() => expect(verdict().textContent).toBe('1 výpadek, 1 agent mlčí, 1 varování'));
    expect(verdict().dataset.state).toBe('down');
    expect(screen.queryByText(/Dobré ráno|Dobré odpoledne|Dobrý večer|vyžadují pozornost/)).toBeNull();
  });

  it('pozastavený a nový monitor nejsou problém: věta to řekne bez „vše v provozu“', async () => {
    const paused = monitor({ id: 8, name: 'Archiv', status: 'paused' });
    const fresh = monitor({ id: 9, name: 'Nový', status: 'unknown', lastCheck: null, agentLastSeen: null });
    vi.stubGlobal('fetch', vi.fn(api([monitor({ id: 3, name: 'Blog' }), paused, fresh])));
    renderPage();

    const verdict = () => screen.getByTestId('fleet-verdict-sentence');
    await waitFor(() => expect(verdict().textContent).toBe('Žádný výpadek ani varování'));
    expect(verdict().dataset.state).toBe('clear');
  });

  it('routery a servery: nadpis našimi slovy, stavy jako ikona a počet, nejhorší první', async () => {
    const router = monitor({ id: 5, name: 'Turris', type: 'openwrt', status: 'down' });
    vi.stubGlobal('fetch', vi.fn(api([...FLEET, router])));
    renderPage();

    const heading = await screen.findByRole('heading', { name: /^Routery a servery/ });
    expect(heading.textContent).toBe('Routery a servery2');
    const chips = within(screen.getByTestId('devices-state-counts')).getAllByText(/^(Výpadek|Online): \d+$/);
    expect(chips.map((c) => c.textContent)).toEqual(['Výpadek: 1', 'Online: 1']);
    expect(screen.queryByText(/Vaše zařízení|ONLINE ·|Online ·/)).toBeNull();
  });
});

describe('Historie dostupnosti: nejhorší první (charts-16)', () => {
  it('kreslí jen řádky se špatným dnem a zbytek shrne jedním odkazem', async () => {
    vi.stubGlobal('fetch', vi.fn(api(FLEET, { 1: days('down'), 2: days('warning'), 3: days(), 4: days() })));
    renderPage();

    const footnote = await screen.findByText(/2 další: 30 dní bez výpadku/);
    expect(footnote.closest('a')?.getAttribute('href')).toBe('/reports');
    const history = footnote.closest('[class*="space-y-3"]') as HTMLElement;
    // The strips load on demand (below the fold), so wait for the rows.
    const names = (await within(history).findAllByRole('rowheader')).map((h) => h.textContent);
    expect(names).toEqual(['E-shop', 'Wiki']);
  });
});

describe('Prázdná instalace (site W1-5 conv-12)', () => {
  it('bez monitorů ukáže první kroky, ne „vše v normálu“', async () => {
    vi.stubGlobal('fetch', vi.fn(api([])));
    renderPage();

    const card = await screen.findByTestId('dashboard-first-run');
    expect(
      within(card)
        .getByRole('link', { name: /Přidat první monitor/ })
        .getAttribute('href')
    ).toBe('/infrastructure?add=1');
    expect(
      within(card)
        .getByRole('link', { name: /Připojit router/ })
        .getAttribute('href')
    ).toBe('/api-agents?platform=openwrt');
    expect(screen.queryByText(/Nic nevyžaduje pozornost/)).toBeNull();
    expect(screen.queryByTestId('fleet-verdict-sentence')).toBeNull();
  });

  it('samotné sondy nejsou monitory: prázdná instalace zůstane prázdnou', async () => {
    vi.stubGlobal('fetch', vi.fn(api([monitor({ id: 30, name: 'Sonda Frankfurt', type: 'node' })])));
    renderPage();

    expect(await screen.findByTestId('dashboard-first-run')).toBeTruthy();
    expect(screen.queryByText('Sonda Frankfurt')).toBeNull();
  });
});

describe('Vyžaduje pozornost: stejná pravidla jako zvonek (CR-5, CORR-4)', () => {
  it('certifikát hlídá podle ssl_alert_days ze serveru, ne podle pevných 14 dní', async () => {
    const cert = monitor({ id: 40, name: 'Obchod', details: { ssl_days_remaining: 20 } });
    vi.stubGlobal('fetch', vi.fn(api([cert], {}, 30)));
    renderPage();

    expect(await screen.findByText('SSL certifikát vyprší za 20 dní')).toBeTruthy();
  });

  it('bez nastavení ze serveru platí výchozích 14 dní', async () => {
    const cert = monitor({ id: 40, name: 'Obchod', details: { ssl_days_remaining: 20 } });
    vi.stubGlobal('fetch', vi.fn(api([cert])));
    renderPage();

    expect(await screen.findByText('Žádný výpadek ani varování')).toBeTruthy();
    expect(attentionCount()).toBe('0');
    expect(screen.queryByText('SSL certifikát vyprší za 20 dní')).toBeNull();
  });

  it('nová verze agenta je v seznamu, ale nepočítá se jako problém', async () => {
    const old = monitor({ id: 41, name: 'Server', type: 'vps', agentUpdateAvailable: '0.1.11' });
    vi.stubGlobal('fetch', vi.fn(api([old])));
    renderPage();

    expect(await screen.findByText(/Agent je zastaralý/)).toBeTruthy();
    expect(screen.getByText('Žádný výpadek ani varování')).toBeTruthy();
    expect(attentionCount()).toBe('0');
  });
});
