// @vitest-environment jsdom
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';
import { withNewTiles } from '@/components/dashboard-layout';

/**
 * A saved layout used to lose the router and server cards for good: they were
 * neither in the catalogue nor among the sections a layout can draw. Layouts
 * saved before the catalogue offered them get them back, and one that turned
 * them off keeps them off.
 */
const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

const catalog = [
  { key: 'alerts', label: 'Výstrahy', kind: 'panel', available: true, samples: null },
  { key: 'devices', label: 'Routery a servery', kind: 'panel', available: true, samples: null },
  { key: 'metric_cpu', label: 'CPU', kind: 'metric', available: true, samples: 5 },
];

let tiles: unknown[] = [];
function api(input: RequestInfo | URL): Promise<Response> {
  const url = String(input);
  if (url.includes('action=monitors'))
    return Promise.resolve(
      json({
        monitors: [{ id: 2, name: 'Donald', type: 'vps', status: 'up', target: 'donald', lastCheck: null }],
      })
    );
  if (url.includes('action=dashboard_layout')) return Promise.resolve(json({ catalog, tiles }));
  if (url.includes('action=public_status'))
    return Promise.resolve(json({ totalMonitors: 1, downMonitors: 0, uptimePercent: 100, avgLatencyMs: 5, nodes: [] }));
  if (url.includes('action=daily_uptime')) return Promise.resolve(json({ series: {} }));
  return Promise.resolve(json({}));
}

beforeAll(async () => {
  await Promise.all([
    import('@/components/findings-list'),
    import('@/components/uptime-heatmap'),
    import('@/components/regions-panel'),
    import('@/components/dashboard-layout-editor'),
    import('./dashboard-traffic'),
  ]);
}, 30_000);

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn(api));
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

const show = () =>
  render(
    <LanguageProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </LanguageProvider>
  );

describe('Dashboard: karty routerů a serverů ve vlastním rozložení', () => {
  it('rozložení uložené dřív o ně nepřijde', async () => {
    tiles = [{ key: 'alerts', visible: true, size: 'normal' }];
    show();
    expect(await screen.findByText('Routery a servery')).toBeTruthy();
  });

  it('rozložení, které je vypnulo, je neukáže', async () => {
    tiles = [
      { key: 'alerts', visible: true, size: 'normal' },
      { key: 'devices', visible: false, size: 'wide' },
    ];
    show();
    await waitFor(() => expect(screen.getAllByText(/Aktivní výstrahy/).length).toBeGreaterThan(0));
    expect(screen.queryByText('Routery a servery')).toBeNull();
  });
});

describe('withNewTiles', () => {
  it('přidá jen neznámé dlaždice, panel zapnutý, metriku vypnutou', () => {
    const merged = withNewTiles([{ key: 'alerts', visible: false, size: 'normal' }], catalog as never);
    expect(merged).toEqual([
      { key: 'alerts', visible: false, size: 'normal' },
      { key: 'devices', visible: true, size: 'wide' },
      { key: 'metric_cpu', visible: false, size: 'normal' },
    ]);
  });
});
