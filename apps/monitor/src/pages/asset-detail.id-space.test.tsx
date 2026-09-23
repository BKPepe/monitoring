// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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
    isAdmin: true,
  },
}));

vi.mock('@/api/use-session', () => ({
  useSession: () => mocks.session,
  useLogout: () => ({ logout: () => {}, pending: false, failed: false }),
}));

import { AssetDetailPage } from './asset-detail';
import { PublicMonitorCard, type PublicMonitor } from '@/components/public/monitor-card';

/**
 * W1-D2: one id space for /infrastructure/:id.
 *
 * The segment is monitors.id. Builders used to put monitors.asset_id there
 * and the page accepted either, so as soon as the two ids of different
 * devices collided, a card opened another device. Production has asset_id ==
 * id everywhere today; asset assignment and discovery break that, so the
 * fixtures below deliberately seed asset_id != id.
 */
const jsonResponse = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const base = {
  status: 'up',
  category: 'Servery',
  lastCheck: '2026-09-21T00:16:08Z',
  lastStatusChange: '2026-08-12T00:16:08Z',
  responseMs: null,
  cpu: null,
  ram: null,
  hdd: null,
  uptimeSeconds: 3_456_000,
  agentLastSeen: null,
  os: null,
  details: {},
};

// The router's asset_id (2) is the E-shop's monitor id, and a third asset
// id (7) belongs to no monitor at all.
const turris = { ...base, id: 6, assetId: 2, name: 'Turris Omnia', type: 'openwrt', target: 'router.example.test' };
const eshop = { ...base, id: 2, assetId: 9, name: 'E-shop', type: 'web', target: 'https://shop.example.test' };
const nas = { ...base, id: 11, assetId: 7, name: 'NAS', type: 'vps', target: 'nas.example.test' };

let monitorsAsked = 0;

function api(url: string): Response {
  if (url.includes('archived=1')) return jsonResponse({ monitors: [] });
  if (url.includes('action=monitors')) {
    monitorsAsked++;
    return jsonResponse({ monitors: [turris, eshop, nas] });
  }
  if (url.includes('action=router_recommendations'))
    return jsonResponse({ monitorId: 6, applicable: false, reason: 'not_a_router', items: [], muted: [] });
  return jsonResponse({});
}

function renderDetail(path: string) {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route path="/infrastructure/:id" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

beforeEach(() => {
  monitorsAsked = 0;
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
      onchange: null,
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
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => Promise.resolve(api(String(input))))
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Detail zařízení: jeden prostor id (W1-D2)', () => {
  it('/infrastructure/abc ukáže „nenalezeno“, ne monitor 1', async () => {
    renderDetail('/infrastructure/abc');
    expect(await screen.findByText(/STRÁNKA NENALEZENA/)).toBeTruthy();
    expect(monitorsAsked).toBe(0);
  });

  it('id monitoru otevře právě ten monitor, i když jiný má stejné asset_id', async () => {
    renderDetail('/infrastructure/2');
    expect(await screen.findByRole('heading', { name: /E-shop/ })).toBeTruthy();
    expect(screen.queryByRole('heading', { name: /Turris Omnia/ })).toBeNull();
  });

  it('karta routeru otevře router', async () => {
    renderDetail('/infrastructure/6');
    expect(await screen.findByRole('heading', { name: /Turris Omnia/ })).toBeTruthy();
  });

  it('asset_id bez monitoru se stejným id je „nenalezeno“, ne cizí zařízení', async () => {
    // Before: the fallback matched NAS by its asset_id 7.
    renderDetail('/infrastructure/7');
    expect(await screen.findByText('Zařízení nenalezeno')).toBeTruthy();
    expect(screen.queryByRole('heading', { name: /NAS/ })).toBeNull();
  });
});

describe('Veřejná karta monitoru: odkazy na detail (W1-D2)', () => {
  const publicTurris: PublicMonitor = {
    id: 6,
    name: 'Turris Omnia',
    type: 'openwrt',
    status: 'up',
    category: 'Síť',
    responseMs: null,
    lastCheck: null,
    lastStatusChange: null,
    details: {},
    assetId: 2,
    cpu: 12,
    ram: 40,
    hdd: null,
  };

  it('jméno i grafy vedou na id monitoru, ne na asset_id', () => {
    render(
      <LanguageProvider>
        <MemoryRouter>
          <ul>
            <PublicMonitorCard monitor={publicTurris} uptime={[]} uptimePct={null} />
          </ul>
        </MemoryRouter>
      </LanguageProvider>
    );
    const name = screen.getByRole('link', { name: 'Turris Omnia' });
    expect(name.getAttribute('href')).toBe('/infrastructure/6');

    // The chart link lives in the expandable detail.
    fireEvent.click(screen.getByRole('button', { expanded: false }));
    const charts = screen.getByRole('link', { name: /Zobrazit grafy metrik/ });
    expect(charts.getAttribute('href')).toBe('/infrastructure/6/metric/6/cpu');
  });
});
