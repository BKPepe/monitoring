// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';
import { HealthDonut } from '@/components/health-donut';

/**
 * W1-A4: the dashboard shows no zero KPIs before the first answer or after a
 * failure, and a failed history request stops the spinner.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const NO_OUTAGES = 'Všechny systémy bez výpadku';

const MONITORS = [
  { id: 1, name: 'E-shop', type: 'web', status: 'up', target: 'https://example.test', category: 'Weby' },
  { id: 2, name: 'Wiki', type: 'web', status: 'down', target: 'https://wiki.example.test', category: 'Weby' },
];

type Handler = (url: string) => Promise<Response>;

function api(overrides: Record<string, Handler> = {}) {
  return (input: RequestInfo | URL): Promise<Response> => {
    const url = String(input);
    for (const [action, handler] of Object.entries(overrides)) {
      if (url.includes(`action=${action}`)) return handler(url);
    }
    if (url.includes('action=session'))
      return Promise.resolve(json({ authenticated: true, user: { id: 1, username: 'admin', role: 'admin' } }));
    if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: MONITORS }));
    if (url.includes('action=dashboard_layout')) return Promise.resolve(json({ catalog: [], tiles: [] }));
    if (url.includes('action=public_status'))
      return Promise.resolve(
        json({ totalMonitors: 2, downMonitors: 1, uptimePercent: 99.5, avgLatencyMs: 90, nodes: [] })
      );
    if (url.includes('action=daily_uptime')) return Promise.resolve(json({ series: {} }));
    return Promise.resolve(json({}));
  };
}

const never = () => new Promise<Response>(() => {});
const fail = () => Promise.resolve(json({ error: 'database_unavailable' }, 500));

/** The value line of the KPI tile labelled `label`. */
function tile(label: string): HTMLElement {
  const labels = screen.getAllByText(label, { selector: 'p' });
  for (const l of labels) {
    const card = l.parentElement?.parentElement;
    if (card?.querySelector('span.text-2xl, [data-testid="metric-tile-skeleton"]')) return card as HTMLElement;
  }
  throw new Error(`no tile ${label}`);
}

let clock = Date.UTC(2026, 8, 23, 8, 0, 0);
beforeEach(() => {
  // usePublicStatus shares answers for ten seconds at module level.
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
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

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

describe('Dashboard: žádné nuly před daty ani po selhání (W1-A4)', () => {
  it('před první odpovědí: zástupné pruhy místo čísel, žádné „bez výpadku“', async () => {
    vi.stubGlobal('fetch', vi.fn(api({ monitors: never, public_status: never })));
    renderPage();

    await waitFor(() => expect(screen.getAllByTestId('metric-tile-skeleton').length).toBe(4));
    expect(screen.queryByText(NO_OUTAGES)).toBeNull();
    expect(within(tile('Výpadky')).queryByText('0')).toBeNull();
  });

  it('seznam monitorů vrací 500: pomlčky, chyba s opakováním, a po opakování skutečná čísla', async () => {
    let healthy = false;
    vi.stubGlobal('fetch', vi.fn(api({ monitors: (url) => (healthy ? api()(url) : fail()) })));
    renderPage();

    const error = await screen.findByText(/Souhrnná čísla nejsou k dispozici/);
    expect(screen.queryByText(NO_OUTAGES)).toBeNull();
    expect(tile('Výpadky').querySelector('span.text-2xl')?.textContent).toBe('—');
    expect(tile('Monitorů celkem').querySelector('span.text-2xl')?.textContent).toBe('—');
    expect(within(tile('Výpadky')).getByText('Stav nelze zjistit')).toBeTruthy();

    healthy = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    await waitFor(() => expect(tile('Výpadky').querySelector('span.text-2xl')?.textContent).toBe('1'));
    expect(screen.queryByText(/Souhrnná čísla nejsou k dispozici/)).toBeNull();
  });

  it('historie vrací 500: chyba, ne nekonečné „Načítám historii“', async () => {
    vi.stubGlobal('fetch', vi.fn(api({ daily_uptime: fail })));
    renderPage();

    expect(await screen.findByText('Chyba při načítání denní dostupnosti.')).toBeTruthy();
    expect(screen.queryByText('Načítám historii dostupnosti…')).toBeNull();
  });

  it('souhrn (public_status) vrací 500: uptime je pomlčka se „Stav nelze zjistit“, ne „žádná data“', async () => {
    vi.stubGlobal('fetch', vi.fn(api({ public_status: fail })));
    renderPage();

    await waitFor(() => expect(within(tile('Uptime (30 dní)')).getByText('Stav nelze zjistit')).toBeTruthy());
    expect(tile('Uptime (30 dní)').querySelector('span.text-2xl')?.textContent).toBe('—');
    expect(screen.queryByText('Zatím žádná data za 30 dní')).toBeNull();
  });
});

describe('Kruh zdraví bez dat (W1-A4)', () => {
  it('součet 0: pomlčky místo „0 · 0.0 %“', () => {
    render(
      <MemoryRouter>
        <HealthDonut
          centerLabel={{ value: '—', caption: 'Zdravých' }}
          segments={[
            { label: 'Online', value: 0, variant: 'up' },
            { label: 'Offline', value: 0, variant: 'down' },
          ]}
        />
      </MemoryRouter>
    );
    const offline = screen.getByText('Offline').parentElement as HTMLElement;
    expect(within(offline).queryByText('0')).toBeNull();
    expect(within(offline).queryByText('0.0 %')).toBeNull();
    expect(within(offline).getAllByText('—').length).toBe(2);
  });
});
