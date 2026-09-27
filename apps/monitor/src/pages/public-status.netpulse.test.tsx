// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * The NetPulse look of the public page (w2m): the public services score
 * beside the verdict, the 30/90-day switch of the strips, the probe places
 * with drawn flags. Each keeps the honesty rules: "—" only for the server's
 * "not enough data", a failure said as one, no score on a custom page (it
 * would describe services the page does not list). usePublicStatus caches at
 * module level, so every test moves the clock on.
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

const day = (n: number) => ({
  date: `${n}. 9.`,
  day: `2026-09-${String(n).padStart(2, '0')}`,
  status: 'up',
  uptimePct: 100,
  avgMs: 100 + n,
});

const HEALTH = {
  network: {
    score: 87,
    grade: 'fair',
    formulaVersion: 1,
    assetsScored: 2,
    assetsTotal: 2,
    components: [
      { key: 'availability', label: 'Dostupnost', weight: 30, points: 95, assets: 2 },
      { key: 'latency', label: 'Odezva', weight: 10, points: 70, assets: 2 },
    ],
    deductions: [
      {
        monitorId: 1,
        monitorName: 'E-shop',
        component: 'availability',
        kind: 'availability_low',
        label: 'Dostupnost 97,2 % za 7 dní',
        points: 3.5,
      },
    ],
  },
  formulaVersion: 1,
  generatedAt: '2026-09-25T08:00:00+02:00',
};

function answering(
  extra: { health?: Response; regions?: unknown[]; page?: boolean; strips90?: Promise<Response> } = {}
) {
  const urls: string[] = [];
  const api = (url: string): Response | Promise<Response> => {
    urls.push(url);
    if (url.includes('action=monitors'))
      return json({ monitors: [monitor(1, 'E-shop', 'up'), monitor(2, 'Wiki', 'up')] });
    if (url.includes('action=public_status'))
      return json({ totalMonitors: 2, downMonitors: 0, uptimePercent: 99.9, lastUpdated: null, nodes: [] });
    if (url.includes('action=status_page')) return json({ title: 'Herní servery', monitorIds: [2] });
    if (url.includes('action=health')) return extra.health ?? json(HEALTH);
    if (url.includes('action=regions')) return json({ regions: extra.regions ?? [] });
    if (url.includes('action=daily_uptime&days=90'))
      return extra.strips90 ?? json({ series: { 1: Array.from({ length: 90 }, (_, i) => day((i % 28) + 1)) } });
    if (url.includes('action=daily_uptime'))
      return json({ series: { 1: Array.from({ length: 30 }, (_, i) => day((i % 28) + 1)) } });
    if (url.includes('action=uptime_windows'))
      return json({ windows: { 1: { d1: 100, d7: 100, d30: 99.95, d90: 98.5 } }, windowStart: { d90: '2026-06-27' } });
    if (url.includes('action=incidents')) return json({ incidents: [], manualIncidents: [] });
    if (url.includes('action=events')) return json({ events: [] });
    if (url.includes('action=ui_config')) return json({ siteTitle: '', customNavLinks: [] });
    return json({});
  };
  return { api, urls };
}

let clock = Date.UTC(2026, 8, 26, 8, 0, 0);
beforeEach(() => {
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
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

function renderPage(stub: ReturnType<typeof answering>, path = '/public') {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => stub.api(String(input)))
  );
  return render(
    <LanguageProvider>
      <MemoryRouter initialEntries={[path]}>
        <PublicStatusPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

describe('Veřejná stránka: skóre veřejných služeb u verdiktu', () => {
  it('kroužek se skóre serveru, popisek „veřejných služeb“ a srážka se jménem služby; ptá se scope=public v jazyce stránky', async () => {
    const stub = answering();
    renderPage(stub);
    const ring = await screen.findByRole('img', { name: 'Skóre veřejných služeb: 87 ze 100, Ucházející' });
    expect(ring).toBeTruthy();
    const deductions = screen.getByRole('list', { name: 'Co ubírá body' });
    expect(deductions.textContent).toContain('E-shop · Dostupnost 97,2 % za 7 dní');
    expect(deductions.textContent).toContain('−3,5');
    expect(screen.getByText('Hodnoceno služeb: 2 z 2')).toBeTruthy();
    const url = stub.urls.find((u) => u.includes('action=health'));
    expect(url).toContain('scope=public');
    expect(url).toContain('lang=cs');
  });

  it('score null je pomlčka „Nedostatek dat“ ze serveru, ne chyba a ne číslo', async () => {
    renderPage(
      answering({
        health: json({ ...HEALTH, network: { ...HEALTH.network, score: null, grade: null, deductions: [] } }),
      })
    );
    expect(await screen.findByRole('img', { name: 'Skóre veřejných služeb: nedostatek dat' })).toBeTruthy();
    expect(screen.queryByText('Skóre se nepodařilo načíst.')).toBeNull();
  });

  it('selhání skóre je řečeno nahlas, místo kroužku žádná pomlčka', async () => {
    const { container } = renderPage(answering({ health: json({ error: 'health_unavailable' }, 500) }));
    expect(await screen.findByText('Skóre se nepodařilo načíst.')).toBeTruthy();
    expect(container.querySelector('[data-slot="health-ring"]')).toBeNull();
  });

  it('vlastní stránka (?page=) skóre celé veřejné sady neukazuje ani se na něj neptá', async () => {
    const stub = answering();
    const { container } = renderPage(stub, '/public?page=hry');
    await screen.findByText('Wiki', { selector: 'span' });
    expect(stub.urls.some((u) => u.includes('action=health'))).toBe(false);
    expect(container.querySelector('[data-slot="health-ring"]')).toBeNull();
  });
});

describe('Veřejná stránka: pásy za 30 nebo 90 dní', () => {
  it('přepnutí na 90 dní se zeptá na 90 dní, řádky mezitím drží 30denní pás a číslo řádku je 90denní', async () => {
    let answer90: (r: Response) => void = () => {};
    const strips90 = new Promise<Response>((res) => (answer90 = res));
    const stub = answering({ strips90 });
    const { container } = renderPage(stub);
    const group = await screen.findByRole('group', { name: /posledních 30 dní/ });
    expect(group.querySelectorAll('[data-day-status]')).toHaveLength(30);
    await screen.findByText('99,95 %');

    fireEvent.click(screen.getByRole('button', { name: '90d' }));
    await waitFor(() => expect(stub.urls.some((u) => u.includes('action=daily_uptime&days=90'))).toBe(true));
    // The 30-day strips stay while the longer answer is on the way: no blank rows.
    expect(container.querySelector('[data-strip-pending]')).toBeNull();
    expect(screen.getByRole('group', { name: /posledních 30 dní/ })).toBeTruthy();

    answer90(json({ series: { 1: Array.from({ length: 90 }, (_, i) => day((i % 28) + 1)) } }));
    const long = await screen.findByRole('group', { name: /posledních 90 dní/ });
    expect(long.querySelectorAll('[data-day-status]')).toHaveLength(90);
    // The row's figure follows the period: the 90-day share, named so.
    const figure = screen.getByText('98,50 %');
    expect(figure.textContent).toContain('Dostupnost 90 dní');
    expect(screen.getByRole('button', { name: '90d' }).getAttribute('aria-pressed')).toBe('true');
  });
});

describe('Veřejná stránka: selhání 90denní historie', () => {
  it('90 dní vrátí 500: stránka to řekne, pás i číslo řádku zůstanou u 30 dní', async () => {
    const stub = answering({ strips90: Promise.resolve(json({ error: 'database_unavailable' }, 500)) });
    renderPage(stub);
    await screen.findByRole('group', { name: /posledních 30 dní/ });
    fireEvent.click(screen.getByRole('button', { name: '90d' }));
    expect(
      await screen.findByText('Historii za 90 dní se nepodařilo načíst, zobrazeno posledních 30 dní.')
    ).toBeTruthy();
    const group = screen.getByRole('group', { name: /posledních 30 dní/ });
    expect(group.querySelectorAll('[data-day-status]')).toHaveLength(30);
    // The figure matches the strip drawn, not the pressed pill.
    const figure = screen.getByText('99,95 %');
    expect(figure.textContent).toContain('Dostupnost 30 dní');
    expect(screen.queryByText('98,50 %')).toBeNull();
  });
});

describe('Veřejná stránka: místa měření s vlastními vlajkami', () => {
  it('vlajka ze země serveru, ne emoji; neuvedené místo řečeno slovy a bez vlajky', async () => {
    renderPage(
      answering({
        regions: [
          { location: '🇩🇪 Frankfurt, DE (AS13335 Cloudflare)', country: 'DE', successRate: 99.99 },
          { location: null, country: null, successRate: 100 },
        ],
      })
    );
    const panel = await screen.findByRole('region', { name: /Místa měření/ });
    await waitFor(() => expect(within(panel).getByText('Frankfurt, DE')).toBeTruthy());
    expect(within(panel).getByRole('img', { name: 'Německo' })).toBeTruthy();
    expect(panel.textContent).not.toContain('🇩🇪');
    expect(within(panel).getByText('AS13335 Cloudflare')).toBeTruthy();
    expect(within(panel).getByText('Místo neuvedeno')).toBeTruthy();
    expect(within(panel).getAllByRole('img')).toHaveLength(1);
  });
});

describe('Veřejná stránka: seznam služeb se neposouvá pod rukama', () => {
  it('služby čekají na incidenty, pak přijdou naráz s připnutým incidentem; selhání incidentů seznam neblokuje', async () => {
    const stub = answering();
    let answerIncidents: (r: Response) => void = () => {};
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=incidents')) return new Promise<Response>((res) => (answerIncidents = res));
        return Promise.resolve(stub.api(url));
      })
    );
    render(
      <LanguageProvider>
        <MemoryRouter initialEntries={['/public']}>
          <PublicStatusPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    // The list answered, the incidents not yet: one placeholder, no services under a gap.
    await screen.findByRole('img', { name: /Skóre veřejných služeb/ });
    expect(screen.getByText('Načítám služby…')).toBeTruthy();
    expect(screen.queryByText('Wiki', { selector: 'span' })).toBeNull();

    answerIncidents(
      json({
        incidents: [],
        manualIncidents: [
          {
            id: 1,
            title: 'Výpadek: E-shop',
            status: 'investigating',
            impact: 'major',
            monitorId: 1,
            createdAt: '26.09.2026 09:00:00',
            resolvedAt: null,
            durationText: null,
            updates: [],
          },
        ],
      })
    );
    const incident = await screen.findByText('Výpadek: E-shop');
    const service = screen.getByText('Wiki', { selector: 'span' });
    expect(incident.compareDocumentPosition(service) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByText('Načítám služby…')).toBeNull();
    cleanup();
    clock += 60 * 60_000;

    // The incidents fail: the list comes all the same.
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);
        return url.includes('action=incidents') ? json({ error: 'x' }, 500) : stub.api(url);
      })
    );
    render(
      <LanguageProvider>
        <MemoryRouter initialEntries={['/public']}>
          <PublicStatusPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    expect(await screen.findByText('Wiki', { selector: 'span' })).toBeTruthy();
  });
});

describe('Veřejná stránka: visící incidenty služby nezadrží', () => {
  it('incidenty neodpoví vůbec: služby přijdou po chvíli i bez nich', async () => {
    const stub = answering();
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=incidents')) return new Promise<Response>(() => {});
        return Promise.resolve(stub.api(url));
      })
    );
    render(
      <LanguageProvider>
        <MemoryRouter initialEntries={['/public']}>
          <PublicStatusPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    expect(await screen.findByText('Wiki', { selector: 'span' }, { timeout: 5000 })).toBeTruthy();
  }, 10_000);
});
