// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// jsdom has no canvas 2D context and the real chart throws inside setOption.
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));

const mocks = vi.hoisted(() => ({
  session: {
    session: { authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' },
    loading: false,
    error: null,
    isAdmin: true,
  },
}));

vi.mock('@/api/use-session', () => ({
  useSession: () => mocks.session,
  useLogout: () => ({ logout: () => {}, pending: false, failed: false }),
}));

import { AssetDetailPage } from './asset-detail';
import { AvailabilityWindows } from '@/components/availability-windows';
import { InterfaceTrafficDaily } from '@/components/interface-traffic-daily';
import { httpMetricsSource } from '@/api/http-source';

/**
 * W1-A5: a chart request that fails is an error with a retry, never "no data".
 * The empty copy is reserved for a server that answered with an empty series.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const NO_DATA = 'Data pro tento monitor nejsou v databázi k dispozici';
// The asset page is the heaviest one; its first render under the full parallel
// suite took 1.27 s, past findBy's 1 s default, while the answer was right.
const FIRST_RENDER = { timeout: 5000 };
const FAILED = 'Grafy se nepodařilo načíst';

const eshop = {
  id: 2,
  assetId: 2,
  name: 'E-shop',
  type: 'web',
  target: 'https://shop.example.test',
  status: 'up',
  category: 'Weby',
  lastCheck: '2026-09-21T00:16:08Z',
  lastStatusChange: '2026-08-12T00:16:08Z',
  responseMs: 120,
  cpu: null,
  ram: null,
  hdd: null,
  details: {},
};

type Handler = () => Response;

function stubApi(overrides: Record<string, Handler>) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      for (const [action, handler] of Object.entries(overrides)) {
        if (url.includes(`action=${action}`)) return Promise.resolve(handler());
      }
      if (url.includes('archived=1')) return Promise.resolve(json({ monitors: [] }));
      if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: [eshop] }));
      if (url.includes('action=public_status'))
        return Promise.resolve(json({ totalMonitors: 1, uptimePercent: null, avgLatencyMs: null, nodes: [] }));
      return Promise.resolve(json({}));
    })
  );
}

beforeEach(() => {
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
  vi.unstubAllGlobals();
});

function renderDetail() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/2']}>
          <Routes>
            <Route path="/infrastructure/:id" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

describe('Grafy: selhání API není „žádná data“ (W1-A5)', () => {
  it('metric_series_batch vrací 500: „Grafy se nepodařilo načíst“ s opakováním, po něm skutečně prázdná řada', async () => {
    let batch: Handler = () => json({ error: 'database_unavailable' }, 500);
    stubApi({ metric_series_batch: () => batch() });
    renderDetail();

    const failed = await screen.findByText(FAILED, {}, FIRST_RENDER);
    expect(screen.queryByText(NO_DATA)).toBeNull();
    const alert = failed.closest('[role="alert"]') as HTMLElement;

    batch = () => json({ series: [] });
    fireEvent.click(within(alert).getByRole('button', { name: 'Zkusit znovu' }));
    expect(await screen.findByText(NO_DATA)).toBeTruthy();
    expect(screen.queryByText(FAILED)).toBeNull();
  });

  it('starý server (200 + prázdná řada + error) je také selhání', async () => {
    stubApi({ metric_series_batch: () => json({ series: [], error: 'Chyba při načítání metrik' }) });
    renderDetail();

    expect(await screen.findByText(FAILED, {}, FIRST_RENDER)).toBeTruthy();
    expect(screen.getByText('Chyba při načítání metrik')).toBeTruthy();
    expect(screen.queryByText(NO_DATA)).toBeNull();
  });

  it('seznam monitorů vrací 500: chyba s opakováním, ne „Zařízení nenalezeno“', async () => {
    let up = false;
    stubApi({ monitors: () => (up ? json({ monitors: [eshop] }) : json({ error: 'database_unavailable' }, 500)) });
    renderDetail();

    const error = await screen.findByText(/Detail zařízení se nepodařilo načíst/, {}, FIRST_RENDER);
    expect(screen.queryByText('Zařízení nenalezeno')).toBeNull();
    up = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect((await screen.findAllByText('E-shop')).length).toBeGreaterThan(0);
  });

  it('getJson odmítne 500 i pojmenovanou chybu a propustí skutečně prázdnou odpověď', async () => {
    stubApi({ metric_series_batch: () => json({ error: 'x' }, 500) });
    await expect(httpMetricsSource.getAssetCharts(2, '24h')).rejects.toThrow();
    stubApi({ metric_heatmap: () => json({ days: [], unit: '', label: '', error: 'Chyba při načítání heatmapy' }) });
    await expect(httpMetricsSource.getMetricHeatmap(2, 'cpu', 30)).rejects.toThrow('Chyba při načítání heatmapy');
    stubApi({ metric_series_batch: () => json({ series: [] }) });
    await expect(httpMetricsSource.getAssetCharts(2, '24h')).resolves.toEqual([]);
  });
});

describe('Dostupnost a provoz po dnech: chyba místo zmizení (W1-A5)', () => {
  it('uptime_windows vrací 500: panel ukáže chybu s opakováním', async () => {
    let up = false;
    stubApi({
      uptime_windows: () =>
        up ? json({ windows: { '2': { d1: 100, d7: 99.5, d30: null, d90: null } } }) : json({}, 500),
    });
    render(
      <LanguageProvider>
        <AvailabilityWindows monitorId={2} />
      </LanguageProvider>
    );
    const error = await screen.findByText('Dostupnost se nepodařilo načíst.');
    up = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect(await screen.findByText('100.00 %')).toBeTruthy();
  });

  it('interface_traffic_daily vrací 500: panel ukáže chybu, nezmizí', async () => {
    stubApi({ interface_traffic_daily: () => json({ error: 'x' }, 500) });
    render(
      <LanguageProvider>
        <InterfaceTrafficDaily monitorId={6} />
      </LanguageProvider>
    );
    expect(await screen.findByText('Provoz po dnech se nepodařilo načíst.')).toBeTruthy();
  });
});
