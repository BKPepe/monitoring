// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import omnia from '@/api/omnia-router.fixture';
import { InsightsPage } from './insights';

/**
 * W1-B6: the Insights page lists what the server measured or computed - a
 * website that is down, a certificate inside the alert window, the
 * dashboard_insights findings (all of them, paged) and each router's weekly
 * recommendations - and none of the old hand-written copy.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const MONITORS = [
  {
    id: 2,
    name: 'E-shop',
    type: 'web',
    status: 'down',
    target: 'https://shop.example.test',
    sinceStatusChangeSeconds: 5400,
  },
  {
    id: 3,
    name: 'Wiki',
    type: 'https',
    status: 'up',
    target: 'https://wiki.example.test',
    details: { ssl_days_remaining: 9, ssl_valid_to: '2026-10-02T12:00:00+02:00' },
  },
  {
    id: 4,
    name: 'Blog',
    type: 'https',
    status: 'up',
    target: 'https://blog.example.test',
    details: { ssl_days_remaining: 80 },
  },
  { id: 6, name: 'Turris', type: 'openwrt', status: 'up', target: 'router', details: { agent_version: '0.1.8' } },
];

const insight = (i: number) => ({
  monitorId: 6,
  monitorName: 'Turris',
  kind: i === 0 ? 'network' : 'forecast',
  text: `Zjištění číslo ${i + 1}`,
  detail: '',
});

interface Options {
  insightsStatus?: number;
  total?: number | null;
  sslAlertDays?: number | null;
}

function stubApi({ insightsStatus = 200, total = 2, sslAlertDays = 14 }: Options = {}) {
  const all = Array.from({ length: total ?? 2 }, (_, i) => insight(i));
  const fetchMock = vi.fn((input: RequestInfo | URL) => {
    const url = new URL(String(input), 'http://localhost');
    const action = url.searchParams.get('action');
    if (action === 'monitors') return Promise.resolve(json({ monitors: MONITORS }));
    if (action === 'websites_overview') {
      return Promise.resolve(
        json(sslAlertDays === null ? { slaGoal: 99.9, monitors: {} } : { slaGoal: 99.9, sslAlertDays, monitors: {} })
      );
    }
    if (action === 'dashboard_insights') {
      if (insightsStatus !== 200) {
        return Promise.resolve(json({ error: 'dashboard_insights_unavailable', message: 'x' }, insightsStatus));
      }
      const offset = Number(url.searchParams.get('offset') ?? 0);
      const limit = Number(url.searchParams.get('limit') ?? 4);
      const page = all.slice(offset, offset + limit);
      return Promise.resolve(json(total === null ? { insights: page } : { insights: page, total, offset }));
    }
    if (action === 'router_recommendations') return Promise.resolve(json(omnia.recommendations));
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
  const calls = (action: string) =>
    fetchMock.mock.calls
      .map(([u]) => String(u))
      .filter((u) => u.includes(`action=${action}&`) || u.endsWith(`action=${action}`));
  return { calls };
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

describe('Zjištění: jen změřená a spočítaná data (W1-B6)', () => {
  it('E-shop mimo provoz a certifikát Wiki za 9 dní jsou na seznamu, Blog (80 dní) ne; žádná ruční karta', async () => {
    stubApi();
    renderPage();

    const list = await screen.findByTestId('website-findings');
    const rows = within(list).getAllByRole('listitem');
    expect(rows).toHaveLength(2);
    expect(rows[0].textContent).toContain('E-shop');
    expect(rows[0].textContent).toContain('Web je mimo provoz 1h 30m.');
    expect(rows[1].textContent).toContain('Wiki');
    expect(rows[1].textContent).toContain('Certifikát vyprší za 9 dní');
    expect(within(list).queryByText(/Blog/)).toBeNull();
    // E-shop (HTTPS) has no certificate read yet: said, in the singular.
    expect(screen.getByText('U 1 webu s HTTPS kontrola certifikát zatím nepřečetla, proto tu chybí.')).toBeTruthy();
    expect(within(rows[0]).getByRole('link', { name: 'E-shop' }).getAttribute('href')).toBe('/infrastructure/2');

    expect(await screen.findByText(/Zjištění číslo 1/)).toBeTruthy();
    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Zjištění');
    // The old cards: a green certificate badge, "TLS 1.3", "Lineární regrese", "AI", "v pořádku".
    expect(document.body.textContent).not.toMatch(/SSL|TLS 1\.3|Lineární|\bAI\b|v pořádku|optimálním/);
  });

  it('bez hranice ze serveru se certifikát za 9 dní nevymýšlí: jen výpadek a poznámka o neznámé hranici', async () => {
    stubApi({ sslAlertDays: null });
    renderPage();

    const list = await screen.findByTestId('website-findings');
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    expect(list.textContent).not.toContain('Wiki');
    expect(screen.getByText(/Hranici pro upozornění na certifikát se nepodařilo zjistit/)).toBeTruthy();
  });

  it('zjištění serveru se stránkují bez stropu 8: 60 položek = 50 a „Načíst další“ pro zbytek', async () => {
    const { calls } = stubApi({ total: 60 });
    renderPage();

    const list = await screen.findByTestId('server-insights');
    expect(within(list).getAllByRole('listitem')).toHaveLength(50);
    expect(calls('dashboard_insights')[0]).toContain('limit=50');
    expect(calls('dashboard_insights')[0]).toContain('offset=0');

    fireEvent.click(screen.getByRole('button', { name: 'Načíst další (zobrazeno 50 z 60)' }));
    expect(await screen.findByText(/Zjištění číslo 60$/)).toBeTruthy();
    expect(within(screen.getByTestId('server-insights')).getAllByRole('listitem')).toHaveLength(60);
    expect(calls('dashboard_insights')[1]).toContain('offset=50');
    expect(screen.queryByRole('button', { name: /Načíst další/ })).toBeNull();
  });

  it('dashboard_insights vrací 500: chyba s opakováním, ne „nic nenalezeno“; weby se vykreslí dál', async () => {
    stubApi({ insightsStatus: 500 });
    renderPage();

    const error = await screen.findByText('Zjištění serveru se nepodařilo načíst.');
    expect(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    ).toBeTruthy();
    expect(screen.queryByText('V naměřených datech server nic nenašel.')).toBeNull();
    expect(await screen.findByTestId('website-findings')).toBeTruthy();
  });

  it('router má svá doporučení (stejný engine jako pondělní e-mail) s odkazem na zařízení', async () => {
    const { calls } = stubApi();
    renderPage();

    expect(await screen.findByText(omnia.recommendations.items[0].title as string)).toBeTruthy();
    const section = screen.getByRole('region', { name: 'Doporučení pro Turris' });
    expect(within(section).getByRole('link', { name: 'Turris' }).getAttribute('href')).toBe('/infrastructure/6');
    expect(calls('router_recommendations')[0]).toContain('monitor_id=6');
    expect(document.getElementById('router-recommendations-6')).not.toBeNull();
  });

  it('v angličtině jde jazyk do dotazů a texty serveru se zobrazí tak, jak přišly', async () => {
    // The language provider reads the stored choice once, when it mounts.
    vi.stubGlobal('localStorage', {
      getItem: (k: string) => (k === 'bk_lang' ? 'en' : null),
      setItem: () => {},
      removeItem: () => {},
    });
    const { calls } = stubApi();
    renderPage();

    expect(await screen.findByText('Findings', { selector: 'h1' })).toBeTruthy();
    await screen.findByTestId('server-insights');
    expect(calls('dashboard_insights')[0]).toContain('lang=en');
    const list = await screen.findByTestId('website-findings');
    expect(list.textContent).toContain('The certificate expires in 9 days');
    await screen.findByText(omnia.recommendations.items[0].title as string);
    expect(calls('router_recommendations')[0]).toContain('lang=en');
  });
});
