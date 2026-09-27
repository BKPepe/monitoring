// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * W2-5: the public page says each fact once. The headline names the one
 * service that is down, an open incident stays on the page however many
 * resolved ones are newer, the failed checks of the outage it covers leave
 * the event list, a custom page shows only its own incidents and events, and
 * the day sentences come in the page language. usePublicStatus caches
 * answers at module level, so every test moves the clock on.
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

const event = (
  id: number,
  monitorId: number,
  monitorName: string,
  minute: number,
  opts: { isDown?: boolean; outageEnd?: string | null; errorMsg?: string } = {}
) => {
  const isDown = opts.isDown ?? true;
  const hhmm = `09:${String(minute).padStart(2, '0')}`;
  return {
    id,
    time: `23.09.2026 ${hhmm}:00`,
    timeIso: `2026-09-23T${hhmm}:00+02:00`,
    monitorId,
    monitorName,
    isDown,
    rawStatus: isDown ? 'down' : 'warning',
    errorMsg: opts.errorMsg ?? 'Služba neodpovídá',
    location: null,
    type: 'WEB',
    responseTime: null,
    outageEnd: opts.outageEnd ?? null,
    outageDurationSec: opts.outageEnd ? 300 : null,
  };
};

const incident = (id: number, title: string, status: string, monitorId: number | null) => ({
  id,
  title,
  status,
  impact: 'major',
  monitorId,
  createdAt: '23.09.2026 09:00:12',
  resolvedAt: status === 'resolved' ? '23.09.2026 09:30:00' : null,
  durationText: status === 'resolved' ? '30 min' : null,
  updates: [],
  postmortem: null,
});

function answering(
  monitors: unknown[],
  extra: { incidents?: unknown[]; events?: unknown[]; page?: { monitorIds: number[] } } = {}
) {
  const urls: string[] = [];
  const api = (url: string): Response => {
    urls.push(url);
    if (url.includes('action=monitors')) return json({ monitors });
    if (url.includes('action=public_status'))
      return json({
        totalMonitors: monitors.length,
        downMonitors: (monitors as { status: string }[]).filter((m) => m.status === 'down').length,
        uptimePercent: 99.9,
        avgLatencyMs: 100,
        nodes: [],
      });
    if (url.includes('action=status_page'))
      return json({ title: 'Herní servery', monitorIds: extra.page?.monitorIds ?? [] });
    if (url.includes('action=regions')) return json({ regions: [] });
    if (url.includes('action=events')) return json({ events: extra.events ?? [] });
    // The API orders by id DESC, newest first.
    if (url.includes('action=incidents')) return json({ incidents: [], manualIncidents: extra.incidents ?? [] });
    if (url.includes('action=daily_uptime'))
      return json({
        series: {
          1: [{ date: '22. 9.', day: '2026-09-22', status: 'partial', uptimePct: 100, avgMs: 110, coveragePct: 58 }],
        },
      });
    if (url.includes('action=uptime_windows')) return json({ windows: {} });
    if (url.includes('action=ui_config')) return json({ siteTitle: '', customNavLinks: [] });
    return json({});
  };
  return { api, urls };
}

let clock = Date.UTC(2026, 8, 25, 8, 0, 0);
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

const before = (a: Node, b: Node) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;

describe('Veřejná stránka: každý fakt jednou (W2-5)', () => {
  it('jediná služba mimo provoz je v nadpisu jménem; „řešíme“ jen s otevřeným incidentem', async () => {
    renderPage(
      answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'up')], {
        incidents: [incident(3, 'Výpadek: E-shop', 'investigating', 1)],
      })
    );
    expect(await screen.findByText('E-shop mimo provoz - řešíme')).toBeTruthy();
    cleanup();
    clock += 60 * 60_000;

    renderPage(answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'up')]));
    expect(await screen.findByText('E-shop mimo provoz')).toBeTruthy();
    expect(screen.queryByText(/řešíme/)).toBeNull();
    cleanup();
    clock += 60 * 60_000;

    // Two services down, an incident for only one of them: counted, no promise.
    renderPage(
      answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'down')], {
        incidents: [incident(3, 'Výpadek: E-shop', 'investigating', 1)],
      })
    );
    expect(await screen.findByText('2 služby mimo provoz')).toBeTruthy();
    cleanup();
    clock += 60 * 60_000;

    // A second incident covers Wiki too: now every down service is handled.
    renderPage(
      answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'down')], {
        incidents: [incident(4, 'Výpadek: Wiki', 'investigating', 2), incident(3, 'Výpadek: E-shop', 'open', 1)],
      })
    );
    expect(await screen.findByText('2 služby mimo provoz - řešíme')).toBeTruthy();
  });

  it('otevřený incident starší než deset vyřešených zůstane nad službami (PUB-04)', async () => {
    // Ids DESC as the API sends them: eleven newer resolved ones, then the open one.
    const resolved = Array.from({ length: 11 }, (_, i) => incident(20 - i, `Vyřešený ${20 - i}`, 'resolved', 2));
    renderPage(
      answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'up')], {
        incidents: [...resolved, incident(1, 'Dlouhý výpadek E-shopu', 'investigating', 1)],
      })
    );
    const open = await screen.findByText('Dlouhý výpadek E-shopu');
    const service = await screen.findByText('E-shop', { selector: 'span' });
    expect(before(open, service)).toBe(true);
    // The resolved history keeps its cap of ten: the oldest of the eleven goes.
    expect(screen.getByText('Vyřešený 20')).toBeTruthy();
    expect(screen.getByText('Vyřešený 11')).toBeTruthy();
    expect(screen.queryByText('Vyřešený 10')).toBeNull();
  });

  it('kontroly běžícího výpadku, za který mluví otevřený incident, se neopakují; skončený výpadek zůstává', async () => {
    const events = [
      event(10, 1, 'E-shop', 30, { errorMsg: 'Běžící výpadek' }),
      event(9, 2, 'Wiki', 25, { isDown: false, errorMsg: 'Pomalá odezva' }),
      event(8, 1, 'E-shop', 20, { errorMsg: 'Běžící výpadek' }),
      // An earlier outage of the same service, already over: another fact.
      event(7, 1, 'E-shop', 5, { outageEnd: '23.09.2026 09:10:00', errorMsg: 'Ranní výpadek' }),
    ];
    renderPage(
      answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'warning')], {
        incidents: [incident(3, 'Výpadek: E-shop', 'investigating', 1)],
        events,
      })
    );

    const card = await screen.findByRole('region', { name: 'Poslední události' });
    await waitFor(() => expect(within(card).getByText(/^Pomalá odezva/)).toBeTruthy());
    expect(within(card).queryByText(/^Běžící výpadek/)).toBeNull();
    expect(within(card).getByText(/^Ranní výpadek/)).toBeTruthy();
    // The pinned incident says "Probíhá"; the event list does not say it again.
    expect(within(card).queryByText('Probíhá')).toBeNull();
    expect(screen.getAllByText('Probíhá')).toHaveLength(1);
  });

  it('opakování se složí do řádku s počtem a druhem; dva výpadky tři dny od sebe zůstanou dva (PUB-08, V-01)', async () => {
    const earlier = (id: number, minute: number) => ({
      ...event(id, 1, 'E-shop', minute, { outageEnd: '20.09.2026 09:40:00', errorMsg: 'Starší výpadek' }),
      time: `20.09.2026 09:${String(minute).padStart(2, '0')}:00`,
      timeIso: `2026-09-20T09:${String(minute).padStart(2, '0')}:00+02:00`,
    });
    const events = [
      event(20, 2, 'Wiki', 25, { isDown: false, errorMsg: 'Pomalá odezva' }),
      event(19, 2, 'Wiki', 24, { isDown: false, errorMsg: 'Pomalá odezva' }),
      event(18, 2, 'Wiki', 23, { isDown: false, errorMsg: 'Pomalá odezva' }),
      event(17, 1, 'E-shop', 10, { errorMsg: 'Nový výpadek' }),
      event(16, 1, 'E-shop', 9, { errorMsg: 'Nový výpadek' }),
      earlier(15, 35),
      earlier(14, 34),
    ];
    renderPage(answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Wiki', 'warning')], { events }));

    const card = await screen.findByRole('region', { name: 'Poslední události' });
    const runs = await waitFor(() => {
      const found = [...card.querySelectorAll<HTMLElement>('[data-run]')];
      if (found.length < 3) throw new Error('runs not rendered yet');
      return found;
    });
    // Wiki's three slow checks, the running E-shop outage, the older one.
    expect(runs.map((r) => r.getAttribute('data-run'))).toEqual(['3', '2', '2']);
    expect(runs[0].textContent).toContain('3× za sebou');
    expect(runs[0].textContent).toContain('Zhoršení');
    expect(runs[1].textContent).toContain('Výpadek');
    // Only the running outage says "Probíhá", once, on its run line.
    expect(within(runs[1]).getAllByText('Probíhá')).toHaveLength(1);
    expect(within(runs[2]).queryByText('Probíhá')).toBeNull();
  });

  it('„Zobrazit další“ počítá řádky, které návštěvník vidí, ne složené kontroly (PUB-08)', async () => {
    // Twelve single failures of different services, then a run of five.
    const singles = Array.from({ length: 12 }, (_, i) =>
      event(100 - i, 10 + i, `Služba ${i + 1}`, 50 - i, {
        outageEnd: '23.09.2026 09:55:00',
        errorMsg: `Chyba ${i + 1}`,
      })
    );
    const run = Array.from({ length: 5 }, (_, i) =>
      event(50 - i, 2, 'Wiki', 30 - i, { isDown: false, errorMsg: 'Pomalá odezva' })
    );
    renderPage(answering([monitor(2, 'Wiki', 'warning')], { events: [...singles, ...run] }));

    const card = await screen.findByRole('region', { name: 'Poslední události' });
    // 13 rows on screen once folded: 10 shown, 3 more - not 7 (17 checks - 10).
    expect(await within(card).findByRole('button', { name: 'Zobrazit další (3)' })).toBeTruthy();
    fireEvent.click(within(card).getByRole('button', { name: 'Zobrazit další (3)' }));
    await waitFor(() => expect(card.querySelector('[data-run="5"]')).not.toBeNull());
    expect(within(card).queryByRole('button', { name: /Zobrazit další/ })).toBeNull();
  });

  it('vlastní stránka ukáže jen incidenty a události svého výběru a ty ohlášené pro všechny (PUB-05)', async () => {
    renderPage(
      answering([monitor(1, 'E-shop', 'down'), monitor(2, 'Minecraft', 'up')], {
        page: { monitorIds: [2] },
        incidents: [
          incident(5, 'Výpadek: E-shop', 'investigating', 1),
          incident(4, 'Plánovaná migrace datacentra', 'investigating', null),
          incident(3, 'Lag na Minecraftu', 'resolved', 2),
        ],
        events: [
          event(10, 1, 'E-shop', 30, { errorMsg: 'E-shop neodpovídá' }),
          event(9, 2, 'Minecraft', 25, { outageEnd: '23.09.2026 09:27:00', errorMsg: 'Minecraft neodpovídá' }),
        ],
      }),
      '/public?page=herni'
    );

    expect(await screen.findByText('Plánovaná migrace datacentra')).toBeTruthy();
    expect(await screen.findByText('Lag na Minecraftu')).toBeTruthy();
    const card = await screen.findByRole('region', { name: 'Poslední události' });
    await waitFor(() => expect(within(card).getByText(/^Minecraft neodpovídá/)).toBeTruthy());
    expect(screen.queryByText('Výpadek: E-shop')).toBeNull();
    expect(within(card).queryByText(/^E-shop neodpovídá/)).toBeNull();
    // The verdict describes the selection, which is up.
    expect(screen.queryByText(/mimo provoz/)).toBeNull();
  });

  it('karta drží řádek pásu, dokud historie nedorazí; selhání historie stránka řekne (PUB-19)', async () => {
    const stub = answering([monitor(1, 'E-shop', 'up')]);
    let answerStrips: (r: Response) => void = () => {};
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=daily_uptime')) return new Promise<Response>((res) => (answerStrips = res));
        return Promise.resolve(stub.api(url));
      })
    );
    const { container } = render(
      <LanguageProvider>
        <MemoryRouter initialEntries={['/public']}>
          <PublicStatusPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    await screen.findByText('E-shop', { selector: 'span' });
    expect(container.querySelector('[data-strip-pending]')).not.toBeNull();

    answerStrips(json({ error: 'database_unavailable' }, 500));
    expect(await screen.findByText('Denní historii dostupnosti se nepodařilo načíst.')).toBeTruthy();
    expect(container.querySelector('[data-strip-pending]')).toBeNull();
  });

  it('denní věty pásů se ptají v jazyce stránky a po přepnutí jazyka znovu; pásy mají jednu legendu (PUB-10, PUB-09)', async () => {
    const stub = answering([monitor(1, 'E-shop', 'up')]);
    renderPage(stub);

    // One legend for every strip, naming the cells a green/red strip cannot.
    expect(await screen.findByText('Měřeno jen zčásti')).toBeTruthy();
    expect(screen.getAllByText('Bez měření')).toHaveLength(1);

    await waitFor(() => expect(stub.urls.some((u) => u.includes('action=daily_uptime'))).toBe(true));
    expect(stub.urls.find((u) => u.includes('action=daily_uptime'))).toContain('lang=cs');

    fireEvent.click(screen.getByRole('button', { name: 'EN' }));
    await waitFor(() =>
      expect(stub.urls.some((u) => u.includes('action=daily_uptime') && u.includes('lang=en'))).toBe(true)
    );
  });
});
