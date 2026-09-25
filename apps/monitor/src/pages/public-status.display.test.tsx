// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * What the public page prints from the data it gets: whether an outage is
 * over, how long it lasted, and when the page was last updated.
 *
 * usePublicStatus shares answers for ten seconds at module level, so every
 * test moves the clock an hour on.
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

/** One failed check as `action=events` sends it. */
const failure = (
  monitorId: number,
  monitorName: string,
  errorMsg: string,
  outageEnd: string | null,
  outageDurationSec: number | null
) => ({
  id: monitorId * 1000 + (outageDurationSec ?? 0),
  time: '20.09.2026 03:14:00',
  timeIso: '2026-09-20T03:14:00+02:00',
  monitorId,
  monitorName,
  type: 'HTTP',
  location: null,
  rawStatus: 'down',
  isDown: true,
  isRecovery: false,
  errorMsg,
  responseTime: null,
  outageEnd,
  outageDurationSec,
});

function answering(monitors: unknown[], events: unknown[], lastUpdated: string | null = null) {
  return (url: string): Response => {
    if (url.includes('action=monitors')) return json({ monitors });
    if (url.includes('action=public_status'))
      return json({
        totalMonitors: monitors.length,
        downMonitors: (monitors as { status: string }[]).filter((m) => m.status === 'down').length,
        uptimePercent: 99.9,
        avgLatencyMs: 100,
        lastUpdated,
        nodes: [],
      });
    if (url.includes('action=events')) return json({ events });
    if (url.includes('action=incidents')) return json({ incidents: [], manualIncidents: [] });
    if (url.includes('action=regions')) return json({ regions: [] });
    return json({});
  };
}

let clock = Date.UTC(2026, 8, 23, 8, 0, 0);
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

function renderPage(path = '/public') {
  return render(
    <LanguageProvider>
      <MemoryRouter initialEntries={[path]}>
        <PublicStatusPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

/** The timeline row whose detail line starts with `errorMsg`. */
function eventRow(errorMsg: string): HTMLElement {
  const row = screen.getByText(new RegExp(`^${errorMsg}`)).closest('li');
  if (!row) throw new Error(`no timeline row for ${errorMsg}`);
  return row;
}

describe('Poslední události: probíhá jen výpadek, který opravdu trvá (extra-app-1)', () => {
  it('skončený výpadek není „Open“, konec neznámý bez vymyšlené délky, krátký výpadek není „0 min“', async () => {
    const api = answering(
      [monitor(1, 'E-shop', 'up'), monitor(2, 'Wiki', 'down')],
      [
        // Ended 20 s later: over, and not rounded down to nothing.
        failure(1, 'E-shop', 'Časový limit vypršel', '20.09.2026 03:14:20', 20),
        // No end recorded, but the monitor is up now: over, length unknown.
        failure(1, 'E-shop', 'Spojení odmítnuto', null, null),
        // No end recorded and the monitor is down now: still running.
        failure(2, 'Wiki', 'Cílový server neodpovídá.', null, null),
      ]
    );
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    await screen.findByText('Poslední události');
    await waitFor(() => expect(within(eventRow('Cílový server neodpovídá.')).queryByText('Probíhá')).toBeTruthy());

    const short = eventRow('Časový limit vypršel');
    expect(within(short).queryByText('Probíhá')).toBeNull();
    expect(within(short).getByText('Vyřešeno')).toBeTruthy();
    expect(short.textContent).toContain('Časový limit vypršel (trvání < 1 min)');
    expect(short.textContent).not.toContain('0 min');

    const unknownEnd = eventRow('Spojení odmítnuto');
    expect(within(unknownEnd).queryByText('Probíhá')).toBeNull();
    expect(within(unknownEnd).getByText('Vyřešeno')).toBeTruthy();
    expect(unknownEnd.textContent).not.toContain('trvání');

    // Exactly one outage is running, and the page says so once.
    expect(screen.getAllByText('Probíhá')).toHaveLength(1);
  });

  it('bez známého stavu služby a bez konce stránka nic netvrdí', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')], [failure(9, 'Stará služba', 'Chyba DNS', null, null)]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    await screen.findByText('Poslední události');
    const row = await waitFor(() => eventRow('Chyba DNS'));
    expect(within(row).queryByText('Probíhá')).toBeNull();
    expect(within(row).queryByText('Vyřešeno')).toBeNull();
  });
});

describe('Čas aktualizace v jazyce stránky (extra-app-4)', () => {
  it('česky i anglicky jako datum a čas, nikdy syrové ISO 8601', async () => {
    const api = answering([monitor(1, 'E-shop', 'up')], [], '2026-09-23T14:12:05+02:00');
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    // 12:12 UTC: 23 September from UTC-12 to UTC+11, whatever zone runs the test.
    const cs = await screen.findByText(/^Aktualizováno /);
    expect(cs.textContent).toMatch(/^Aktualizováno 23\.\s9\.\s2026\s\d{1,2}:\d{2} · /);
    expect(cs.textContent).not.toContain('2026-09-23T');
    cleanup();

    renderPage('/public?lang=en');
    const en = await screen.findByText(/^Updated /);
    expect(en.textContent).toMatch(/^Updated 23\sSept?\s2026,\s\d{2}:\d{2} · /);
    expect(en.textContent).not.toContain('+02:00');
  });
});

describe('Počty služeb v nadpisu v českých tvarech (extra-app-3)', () => {
  it('1 služba, 2–4 služby, 5 a více služeb; anglicky 1 service / 2 services', async () => {
    const fleet = (n: number, status: string) =>
      Array.from({ length: n }, (_, i) => monitor(i + 1, `Web ${i + 1}`, status));
    const show = async (monitors: unknown[], text: string, path = '/public') => {
      const api = answering(monitors, []);
      vi.stubGlobal(
        'fetch',
        vi.fn(async (input: RequestInfo | URL) => api(String(input)))
      );
      renderPage(path);
      expect(await screen.findByText(text)).toBeTruthy();
      cleanup();
      clock += 60 * 60_000;
    };

    await show(fleet(5, 'down'), '5 služeb mimo provoz');
    await show(fleet(3, 'down'), '3 služby mimo provoz');
    await show([...fleet(1, 'warning'), monitor(9, 'E-shop', 'up')], '1 služba hlásí zhoršení nebo neznámý stav');
    await show(fleet(5, 'warning'), '5 služeb hlásí zhoršení nebo neznámý stav');
    await show([...fleet(1, 'maintenance'), monitor(9, 'E-shop', 'up')], '1 služba v plánované údržbě');
    await show(fleet(2, 'maintenance'), '2 služby v plánované údržbě');
    await show(fleet(1, 'down'), '1 service down', '/public?lang=en');
    await show(fleet(2, 'down'), '2 services down', '/public?lang=en');
  });
});
