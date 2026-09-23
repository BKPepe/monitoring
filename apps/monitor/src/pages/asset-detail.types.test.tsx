// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// Same ECharts stub as asset-detail.router.test.tsx: jsdom has no canvas 2D
// context and the real chart throws inside setOption.
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));

import { AssetDetailPage } from './asset-detail';

/**
 * The overview built ONE page for every monitor type: five hero tiles, a
 * chart frame and a process card, whatever the monitor was. On the owner's
 * screenshot of an `agent_service` four of the five tiles read "—", the chart
 * frame announced an empty database and the process card said no agent is
 * connected - about a service that runs under one.
 *
 * lib/monitor-type.ts decides what a type can ever report; this file proves
 * the page follows it, per type, through the real component.
 */
const jsonResponse = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const base = {
  id: 9,
  status: 'up',
  category: 'Servery',
  assetId: 4,
  assetName: 'vps-prague-01',
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

/** The parent agent, with mariadbd in both of its rankings. */
const parent = {
  ...base,
  id: 4,
  name: 'vps-prague-01',
  type: 'vps',
  target: 'vps-prague-01',
  os: 'Debian 13',
  cpu: 34,
  ram: 63,
  hdd: 71,
  details: {
    top_cpu_processes: [{ name: 'mariadbd', cpu: 18.4 }],
    top_ram_processes: [{ name: 'mariadbd', ram_mb: 3120.5 }],
  },
};

const service = { ...base, name: 'mariadb', type: 'agent_service', target: 'mariadbd' };
const web = { ...base, id: 9, name: 'E-shop', type: 'web', target: 'https://shop.example.test', responseMs: 316 };
const heartbeat = { ...base, id: 9, name: 'Noční záloha DB', type: 'heartbeat', target: 'backup-nightly' };

function api(url: string, monitors: unknown[]): Response {
  if (url.includes('action=monitors')) return jsonResponse({ monitors });
  if (url.includes('action=router_recommendations'))
    return jsonResponse({ monitorId: 9, applicable: false, reason: 'not_a_router', items: [], muted: [] });
  // api.php always sends `series` (an empty map encodes as []); a body without
  // it is a broken answer and the charts now say so instead of "no data".
  if (url.includes('action=metric_series_batch')) return jsonResponse({ series: [] });
  return jsonResponse({});
}

function renderDetail() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/9']}>
          <Routes>
            <Route path="/infrastructure/:assetId" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

describe('asset detail per monitor type', () => {
  beforeEach(() => {
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

  const stubFetch = (monitors: unknown[]) =>
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => Promise.resolve(api(String(input), monitors)))
    );

  it('leaves out the tiles an agent_service can never fill', async () => {
    stubFetch([service, parent]);
    renderDetail();
    expect(await screen.findByText('Uptime')).toBeTruthy();
    // bk_apply_agent_service_result() logs response_time NULL on every row,
    // and a watched process has no filesystem of its own.
    expect(screen.queryByText('Odezva')).toBeNull();
    expect(screen.queryByText('Využití disku')).toBeNull();
  });

  it('fills its CPU and memory from the parent agent, in megabytes', async () => {
    stubFetch([service, parent]);
    renderDetail();
    // The dashboard has read the process rankings all along; the detail page
    // showed two dashes next to them.
    expect(await screen.findByText('18.4 %')).toBeTruthy();
    expect(screen.getByText('3120.5 MB')).toBeTruthy();
  });

  it('sends the reader to the parent server for the process list', async () => {
    stubFetch([service, parent]);
    renderDetail();
    expect(await screen.findByText('Procesy sbírá agent na serveru, pod kterým tato služba běží.')).toBeTruthy();
    expect(screen.queryByText('Zatím není připojen agent pro výpis procesů.')).toBeNull();
    const link = screen.getByText('Otevřít vps-prague-01');
    expect(link.getAttribute('href')).toBe('/infrastructure/4');
  });

  it('says the type keeps no history instead of announcing an empty database', async () => {
    stubFetch([service, parent]);
    renderDetail();
    expect(
      await screen.findByText('Tento typ monitoru neukládá časové řady - sleduje se jen dostupnost.')
    ).toBeTruthy();
    expect(screen.queryByText('Data pro tento monitor nejsou v databázi k dispozici')).toBeNull();
  });

  it('prints a human type instead of the stored enum', async () => {
    stubFetch([service, parent]);
    renderDetail();
    expect(await screen.findByText('Typ: Služba pod agentem')).toBeTruthy();
    expect(screen.queryByText('Typ: AGENT_SERVICE')).toBeNull();
    // The parameter row repeated it, and `os` merely echoed the type there.
    expect(screen.getByText('Typ protokolu')).toBeTruthy();
    expect(screen.queryByText('Operační systém')).toBeNull();
  });

  it('gives a web monitor its latency and none of a machine', async () => {
    stubFetch([web]);
    renderDetail();
    expect((await screen.findAllByText('316 ms')).length).toBeGreaterThan(0);
    for (const label of ['Využití CPU', 'Využití RAM', 'Využití disku']) {
      expect(screen.queryByText(label), label).toBeNull();
    }
    // A web check DOES store a latency history, so an empty answer is news.
    expect(await screen.findByText('Data pro tento monitor nejsou v databázi k dispozici')).toBeTruthy();
  });

  it('leaves a heartbeat without a single measurement tile or an empty chart frame', async () => {
    stubFetch([heartbeat]);
    renderDetail();
    // Awaited first: the charts settle last, and the absence below would pass
    // while the skeleton is still on screen.
    expect(
      await screen.findByText('Tento typ monitoru neukládá časové řady - sleduje se jen dostupnost.')
    ).toBeTruthy();
    expect(screen.getByText('Uptime')).toBeTruthy();
    for (const label of ['Odezva', 'Využití CPU', 'Využití RAM', 'Využití disku']) {
      expect(screen.queryByText(label), label).toBeNull();
    }
    expect(screen.queryByText('Data pro tento monitor nejsou v databázi k dispozici')).toBeNull();
    // Nobody collects processes for a job that reports itself - no card at all.
    expect(screen.queryByText('Nejvytíženější procesy')).toBeNull();
  });

  it('never cuts a date in half in the parameter list', async () => {
    stubFetch([service, parent]);
    renderDetail();
    // "Před 1 měsíci (12. 8. 2026 0:27:52)" was clipped to "(12. 8. 2026 0:…"
    // by a `truncate` span in a narrow column. Seconds gone, wrapping allowed.
    const row = await screen.findByText(/Před 1 měsíci \(12\. 8\. 2026 \d{2}:\d{2}\)$/);
    expect(row.className).not.toContain('truncate');
  });
});
