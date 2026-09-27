// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import type { Finding, FindingsResponse } from '@/api/types';
import { InsightsPage } from './insights';

/**
 * W2-8: the Insights page is the server's one findings feed (C-12), grouped
 * by device, worst first, with the mute on router items - not three lists
 * assembled in the browser from the monitor list, dashboard_insights and one
 * request per router.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const finding = (key: string, monitorId: number, name: string, extra: Partial<Finding> = {}): Finding => ({
  key,
  source: 'status',
  kind: 'status_down',
  severity: 'warning',
  monitorId,
  monitorName: name,
  monitorType: 'web',
  title: `Nález ${key}`,
  detail: null,
  action: null,
  since: null,
  ...extra,
});

const device = (monitorId: number, monitorName: string, worst: Finding['severity'], total: number) => ({
  monitorId,
  monitorName,
  monitorType: 'web',
  worst,
  critical: worst === 'critical' ? total : 0,
  warning: worst === 'warning' ? total : 0,
  info: worst === 'info' ? total : 0,
  total,
});

const FEED: FindingsResponse = {
  findings: [
    finding('status:2:status_down', 2, 'E-shop', {
      severity: 'critical',
      title: 'Web je mimo provoz',
      detail: 'HTTP status kód: 502',
      since: '2026-09-23T08:30:00+02:00',
    }),
    finding('certificate:3:ssl_expiring', 3, 'Wiki', {
      source: 'certificate',
      kind: 'ssl_expiring',
      title: 'Certifikát vyprší za 9 dní',
    }),
    finding('router:6:wifi_noise', 6, 'Turris', {
      source: 'router',
      kind: 'wifi_noise',
      monitorType: 'openwrt',
      title: 'Rušení na 2,4 GHz',
      action: 'Přesuňte kanál',
      rec: { key: 'wifi_noise', command: null } as Finding['rec'],
    }),
  ],
  total: 3,
  offset: 0,
  counts: { critical: 1, warning: 2, info: 0 },
  devices: [device(2, 'E-shop', 'critical', 1), device(3, 'Wiki', 'warning', 1), device(6, 'Turris', 'warning', 1)],
  monitorsChecked: 12,
  muted: [],
  canMute: true,
  sourceErrors: [],
  insightsCachedAt: null,
  generatedAt: '2026-09-23T10:00:00+02:00',
};

function stubApi(body: FindingsResponse = FEED) {
  const fetchMock = vi.fn((input: RequestInfo | URL) =>
    Promise.resolve(String(input).includes('action=findings') ? json(body) : json({ error: 'unexpected' }, 404))
  );
  vi.stubGlobal('fetch', fetchMock);
  return { urls: () => fetchMock.mock.calls.map(([u]) => String(u)) };
}

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <InsightsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('Zjištění: jeden seznam ze serveru, po zařízeních (W2-8)', () => {
  it('nálezy jsou pod svým zařízením v pořadí serveru, ztlumit jde jen doporučení routeru', async () => {
    const { urls } = stubApi();
    renderPage();

    const shop = await screen.findByRole('region', { name: 'E-shop' });
    expect(within(shop).getByText('Web je mimo provoz')).toBeTruthy();
    expect(within(shop).getByText('HTTP status kód: 502')).toBeTruthy();
    expect(within(shop).getByRole('link', { name: 'E-shop' }).getAttribute('href')).toBe('/infrastructure/2');
    const regions = screen.getAllByRole('region').map((r) => r.getAttribute('aria-label'));
    expect(regions).toEqual(['E-shop', 'Wiki', 'Turris']);
    // The mute belongs to router recommendations only - the one advice that has a mute on the server.
    const mute = screen.getAllByRole('button', { name: /Ztlumit/ });
    expect(mute).toHaveLength(1);
    expect(screen.getByRole('region', { name: 'Turris' }).contains(mute[0])).toBe(true);
    // Twelve looked at, three with findings: the rest is one line, not nine empty cards.
    expect(screen.getByText('Ostatní zařízení bez nálezů (9)')).toBeTruthy();

    // One destination, one name: the sidebar, the phone tab and the bell say "Upozornění" too.
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Upozornění');
    // One request for the whole page - no monitor list, no per-router calls.
    expect(urls()).toHaveLength(1);
    expect(urls()[0]).toContain('action=findings');
    expect(urls()[0]).toContain('limit=500');
    expect(urls()[0]).toContain('lang=cs');
  });

  it('selhaný zdroj uvnitř odpovědi je hlasitý: seznam se ukáže, ale jako neúplný', async () => {
    stubApi({ ...FEED, sourceErrors: [{ source: 'insight', monitorId: null, error: 'insights_unavailable' }] });
    renderPage();

    expect(await screen.findByText('Seznam není úplný: trendy a odchylky se nepodařilo načíst.')).toBeTruthy();
    expect(screen.getByRole('region', { name: 'E-shop' })).toBeTruthy();
  });

  it('bez nálezů říká „nic k řešení“ a kolik zařízení prošlo', async () => {
    stubApi({ ...FEED, findings: [], devices: [], total: 0, counts: { critical: 0, warning: 0, info: 0 } });
    renderPage();

    expect(await screen.findByText('Nic k řešení - žádné zjištění.')).toBeTruthy();
    expect(screen.getByText('Ostatní zařízení bez nálezů (12)')).toBeTruthy();
  });

  it('v angličtině jde jazyk do dotazu a nadpis je anglicky', async () => {
    // The language provider reads the stored choice once, when it mounts.
    vi.stubGlobal('localStorage', {
      getItem: (k: string) => (k === 'bk_lang' ? 'en' : null),
      setItem: () => {},
      removeItem: () => {},
    });
    const { urls } = stubApi();
    renderPage();

    expect(await screen.findByText('Alerts', { selector: 'h1' })).toBeTruthy();
    await screen.findByRole('region', { name: 'E-shop' });
    expect(urls()[0]).toContain('lang=en');
  });
});

describe('Upozornění jako feed (NetPulse): dlaždice, filtry, čas', () => {
  it('dlaždice ukazují počty serveru; filtr závažnosti jen zúží tentýž seznam, bez nového dotazu', async () => {
    const { urls } = stubApi();
    renderPage();
    await screen.findByRole('region', { name: 'E-shop' });

    const severity = screen.getByRole('group', { name: 'Závažnost' });
    fireEvent.click(within(severity).getByRole('button', { name: /Kritické/ }));
    expect(screen.getAllByRole('region').map((r) => r.getAttribute('aria-label'))).toEqual(['E-shop']);
    // The critical item says its severity in words, not only by the red rail.
    expect(
      within(screen.getByRole('region', { name: 'E-shop' })).getByText('Kritické:', { exact: false })
    ).toBeTruthy();

    const sources = screen.getByRole('group', { name: 'Zdroj' });
    fireEvent.click(within(severity).getByRole('button', { name: /Vše/ }));
    fireEvent.click(within(sources).getByRole('button', { name: /Routery/ }));
    expect(screen.getAllByRole('region').map((r) => r.getAttribute('aria-label'))).toEqual(['Turris']);

    expect(urls()).toHaveLength(1);
  });

  it('položka s časem říká, jak dlouho trvá; přesný čas je v titulku', async () => {
    stubApi();
    renderPage();
    const shop = await screen.findByRole('region', { name: 'E-shop' });
    const time = shop.querySelector('time');
    expect(time?.getAttribute('dateTime')).toBe('2026-09-23T08:30:00+02:00');
    expect(time?.textContent).toMatch(/^před /);
    expect(time?.getAttribute('title')).toMatch(/^trvá od /);
  });
});
