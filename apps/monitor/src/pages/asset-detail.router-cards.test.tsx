// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// ECharts needs a canvas 2D context, jsdom has none (same stub as
// asset-detail.router.test.tsx): this file is about rows, sentences and tiles.
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));

import { AssetDetailPage } from './asset-detail';

/**
 * The owner-reported router cards through the real page (W1-C1, W1-C3,
 * W1-B5): the LTE advice under the verdict, the honest log label and lines,
 * and KPI tiles that draw their own metric.
 */
const baseDetails = {
  temperature: 58,
  version: '0.1.8',
  wan_proto: 'dhcp',
  lte_up: true,
  lte_rsrp: -85,
  lte_rsrq: -12,
  lte_sinr: -2,
  log_errors_24h: 8,
  log_warnings_24h: 3,
};

const monitorWith = (details: Record<string, unknown>) => ({
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
  os: 'OpenWrt',
  details,
});

const jsonResponse = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

/** Eight points so the tile delta (quarter averages) has something to compare. */
const eight = (values: number[]) => values.map((v, i) => [1_758_000_000 + i * 300, v]);

let details: Record<string, unknown> = baseDetails;
let batch: Record<string, unknown> = { series: [] };

const api = (url: string): Response => {
  if (url.includes('action=monitors')) return jsonResponse({ monitors: [monitorWith(details)] });
  if (url.includes('action=metric_series_batch')) return jsonResponse(batch);
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
            <Route path="/infrastructure/:assetId" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

async function openTab(name: string) {
  // Radix activates a tab on mouseDown, not on click.
  fireEvent.mouseDown(await screen.findByRole('tab', { name: new RegExp(name) }));
}

/** The KPI tile (Card) whose label is exactly `label`. */
function tile(label: string): HTMLElement {
  const el = screen.getAllByText(label).find((n) => n.tagName === 'P' && n.closest('[class*="flex-col"]'));
  if (!el) throw new Error(`dlaždice ${label} nenalezena`);
  return el.parentElement as HTMLElement;
}

describe('Router: karty nahlášené vlastníkem', () => {
  beforeEach(() => {
    details = { ...baseDetails };
    batch = { series: [] };
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

  it('LTE: dobrý RSRP se špatným SINR řekne, že jde o rušení, ne o anténu u okna (W1-C1)', async () => {
    renderDetail();
    await openTab('Síť');
    const advice = await screen.findByTestId('lte-advice');
    expect(advice.textContent).toContain('Co s tím:');
    expect(advice.textContent).toContain('rušení nebo přetížená buňka');
    expect(advice.textContent).not.toContain('k oknu či výš');
  });

  it('LTE: slabý signál poradí anténu výš nebo k oknu (W1-C1)', async () => {
    details = { ...baseDetails, lte_rsrp: -104, lte_rsrq: -9, lte_sinr: 15 };
    renderDetail();
    await openTab('Síť');
    expect((await screen.findByTestId('lte-advice')).textContent).toContain('Signál je slabý');
  });

  it('LTE: výborný signál žádnou radu nepřidává (W1-C1)', async () => {
    details = { ...baseDetails, lte_rsrp: -72, lte_rsrq: -7, lte_sinr: 24 };
    renderDetail();
    await openTab('Síť');
    await screen.findByText('Kvalita LTE signálu');
    expect(screen.queryByTestId('lte-advice')).toBeNull();
  });

  it('LTE: RSRP -101 dBm čtou karty Služby i Síť stejným slovem (W1-C1)', async () => {
    details = { ...baseDetails, lte_rsrp: -101, lte_rsrq: -9, lte_sinr: 15 };
    renderDetail();
    await openTab('Služby');
    expect(await screen.findByText('RSRP: -101 dBm (špatný)')).toBeTruthy();
    expect(screen.queryByText(/RSRP: -101 dBm \(slabý\)/)).toBeNull();

    await openTab('Síť');
    const row = (await screen.findByText('LTE RSRP')).closest('div.border-b') as HTMLElement;
    expect(within(row).getByText('špatný')).toBeTruthy();
  });

  it('Log: bez okna od agenta popisek říká 500 řádků, ne 24 h (W1-C3)', async () => {
    renderDetail();
    await openTab('Síť');
    expect(await screen.findByText('Chyby v posledních 500 řádcích logu')).toBeTruthy();
    expect(screen.getByText('Varování v posledních 500 řádcích logu')).toBeTruthy();
    expect(screen.queryByText(/\(24 h\)/)).toBeNull();
    // An agent older than 0.1.8 says nothing about lines, and neither does the row.
    expect(screen.queryByTestId('log-lines')).toBeNull();
  });

  it('Log: agent 0.1.8 ukáže okno v hodinách a zamaskované řádky s počtem opakování (W1-C3)', async () => {
    details = {
      ...baseDetails,
      log_window_secs: 3 * 3600 + 600,
      log_lines_state: 'on',
      log_errors_recent: [
        { ts: 1_758_000_000, prog: 'netifd', msg: 'Interface <ipv4> lost carrier', count: 6 },
        { ts: null, prog: null, msg: 'kernel: nf_conntrack: table full', count: 1 },
        { prog: 'bogus' },
      ],
    };
    renderDetail();
    await openTab('Síť');
    expect(await screen.findByText('Chyby v logu za posledních 3 h')).toBeTruthy();
    expect(screen.getByText('Varování v logu za posledních 3 h')).toBeTruthy();
    const lines = screen.getByTestId('log-lines');
    expect(lines.tagName).toBe('DETAILS');
    expect(lines.textContent).toContain('Poslední chybové řádky (2)');
    expect(lines.textContent).toContain('netifd');
    expect(lines.textContent).toContain('6×');
    expect(lines.textContent).toContain('Interface <ipv4> lost carrier');
    expect(lines.textContent).toContain('nf_conntrack: table full');
  });

  it('Log: vypnuté odesílání řekne kde, prázdný seznam řekne „žádná chyba" (W1-C3)', async () => {
    details = { ...baseDetails, log_errors_recent: null, log_lines_state: 'off_monitor', log_window_secs: 1200 };
    renderDetail();
    await openTab('Síť');
    expect(await screen.findByText('Chyby v logu za posledních 20 min')).toBeTruthy();
    expect(screen.getByTestId('log-lines').textContent).toBe('Odesílání řádků je vypnuté (u monitoru)');
    cleanup();

    details = { ...baseDetails, log_errors_24h: 0, log_errors_recent: [], log_lines_state: 'on' };
    renderDetail();
    await openTab('Síť');
    expect((await screen.findByTestId('log-lines')).textContent).toBe('žádná chyba v logu');
    cleanup();

    // Sending on and still no list: the log was unreadable - a dash, not "no errors".
    details = { ...baseDetails, log_errors_recent: null, log_lines_state: 'on' };
    renderDetail();
    await openTab('Síť');
    expect((await screen.findByTestId('log-lines')).textContent).toBe('Chybové řádky: —');
  });

  it('Dlaždice: teplota kreslí svou řadu, ne provoz na LTE ve stejném odstínu (W1-B5)', async () => {
    batch = {
      series: {
        // Same "temperature" tone and listed first: the tile used to take it.
        net_lte: { points: eight([10, 10, 20, 40, 60, 80, 100, 100]), unit: 'KB/s', label: 'LTE' },
        temperature_c: { points: eight([60, 60, 60, 60, 60, 60, 66, 66]), unit: '°C', label: 'Teplota' },
      },
    };
    renderDetail();
    await screen.findByText('Teplota');
    const temp = tile('Teplota');
    expect(await within(temp).findByText('↑ 10 %')).toBeTruthy();
    expect(temp.querySelector('svg')).not.toBeNull();
  });

  it('Dlaždice: odezva bez vlastní řady nemá šipku ani křivku, i když I/O čekání roste (W1-B5)', async () => {
    batch = {
      series: {
        // iowait shares the "latency" tone; it is not the response time.
        iowait: { points: eight([1, 1, 2, 3, 5, 8, 9, 9]), unit: '%', label: 'I/O' },
        cpu: { points: eight([40, 40, 40, 40, 40, 40, 44, 44]), unit: '%', label: 'CPU' },
      },
    };
    renderDetail();
    await screen.findByText('Teplota');
    const cpu = tile('Využití CPU');
    expect(await within(cpu).findByText('↑ 10 %')).toBeTruthy();
    const latency = tile('Odezva');
    expect(latency.textContent).not.toMatch(/[↑↓]/);
    expect(latency.querySelector('svg')).toBeNull();
  });
});
