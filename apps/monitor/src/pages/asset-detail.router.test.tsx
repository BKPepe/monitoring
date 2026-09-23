// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// ECharts needs a canvas 2D context, jsdom has none: the real chart throws
// inside setOption and tears the page down. This file is about the tiles and
// the rows, not about the drawing (same stub as speedtest-card.test.tsx).
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));

import { AssetDetailPage } from './asset-detail';

/**
 * The router's overview through the real page: the KPI tiles and the rows
 * that carry a `last_details` value rather than a mapped metric (G22, G24,
 * G41, G42). The libraries are tested on their own - what this file proves is
 * the wiring, which is exactly where the temperature tile was lost for years.
 */
const details = {
  // G22: the payload key. `temperature_c` is the vps_metrics column name.
  temperature: 67.5,
  cpu_core_max_pct: 97.4,
  cpu_core_max_index: 1,
  // G24: the router could not tell, and says so instead of claiming.
  dns_engine: null,
  dns_encryption: null,
  dns_servers: null,
  wan_reconnect_count: null,
  dns_latency_ms: null,
  // G41 + G42.
  dns_resolver_ok: false,
  agent_run_ms: 9470,
  agent_prev_total_ms: 12100,
  runs_skipped_lock: 2,
  runs_skipped_post: 0,
  reports_24h: { expected: 1440, received: 1298 },
  wan_proto: 'pppoe',
  entropy: 3900,
  version: '0.1.7',
};

const monitor = {
  id: 6,
  name: 'Turris Omnia',
  type: 'openwrt',
  target: '10.0.0.1',
  status: 'up',
  category: 'Routery',
  assetId: 6,
  assetName: 'Omnia',
  lastCheck: '2026-09-21T00:00:00Z',
  lastStatusChange: null,
  responseMs: 3,
  cpu: 41.2,
  ram: 38,
  hdd: 12,
  uptimeSeconds: 89000,
  agentLastSeen: null,
  hostname: 'omnia',
  // G24: agents that could not read the system name sent a single space.
  os: ' ',
  details,
};

const jsonResponse = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const api = (url: string): Response => {
  if (url.includes('action=monitors')) return jsonResponse({ monitors: [monitor] });
  if (url.includes('action=router_recommendations'))
    return jsonResponse({ monitorId: 6, applicable: true, reason: null, items: [], muted: [] });
  if (url.includes('action=storage_history')) return jsonResponse({ monitorId: 6, days: 90, disks: [] });
  if (url.includes('action=wan_bottleneck')) return jsonResponse({ error: 'not_ready' });
  return jsonResponse({});
};

function renderDetail() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/6']}>
          <Routes>
            <Route path="/infrastructure/:id" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

describe('router overview', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => Promise.resolve(api(String(input))))
    );
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
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('shows the temperature tile the page used to read under the column name (G22)', async () => {
    renderDetail();
    expect(await screen.findByText('68 °C')).toBeTruthy();
    expect(screen.getByText('Teplota')).toBeTruthy();
  });

  it('says which core was busy while the average looked calm', async () => {
    renderDetail();
    expect(await screen.findByText('nejvytíženější jádro 1: 97 %')).toBeTruthy();
  });

  it('does not open the subtitle with a separator for a blank os (G24)', async () => {
    renderDetail();
    await screen.findByText('68 °C');
    // " · 10.0.0.1 · Routery" is what a single space for the system name did.
    expect(screen.getByText('10.0.0.1 · Routery')).toBeTruthy();
  });

  it('prints an em dash for the values the agent used to make up (G24)', async () => {
    renderDetail();
    await screen.findByText('68 °C');
    // Radix activates a tab on mouseDown, not on click.
    fireEvent.mouseDown(screen.getByText('Síť'));

    for (const label of ['Resolver', 'Šifrování', 'Servery', 'Reconnecty (od startu)']) {
      const row = (await screen.findByText(label)).parentElement;
      expect(row?.textContent, label).toContain('—');
    }
  });

  it('says that the router resolver did not answer (G41)', async () => {
    renderDetail();
    await screen.findByText('68 °C');
    // Radix activates a tab on mouseDown, not on click.
    fireEvent.mouseDown(screen.getByText('Síť'));

    expect(await screen.findByText('DNS resolver')).toBeTruthy();
    expect(screen.getAllByText('Neodpovídá').length).toBeGreaterThan(0);
  });

  it('shows what the minute run cost and how many runs were skipped (G42)', async () => {
    renderDetail();
    await screen.findByText('68 °C');
    // Radix activates a tab on mouseDown, not on click.
    fireEvent.mouseDown(screen.getByText('Síť'));

    expect(await screen.findByText('9.5 s (předchozí běh i s odesláním 12.1 s)')).toBeTruthy();
    expect(screen.getByText('2× předchozí běh ještě běžel · 0× se nepodařilo odeslat')).toBeTruthy();
    expect(screen.getByText('1298 z 1440 minut (90 %)')).toBeTruthy();
  });
});
