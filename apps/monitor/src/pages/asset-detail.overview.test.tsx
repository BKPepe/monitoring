// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// ECharts needs a canvas 2D context, jsdom has none: one stub per chart card.
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));

import { AssetDetailPage } from './asset-detail';

/**
 * W2-2: the router overview stops being a wall - at most six cards chosen by
 * type, a flat line as one sentence, the WAN and LTE charts only inside the
 * combined one, identity-only parameters, one status sentence and the
 * findings once.
 */
const router = {
  id: 6,
  name: 'Router',
  type: 'openwrt',
  target: 'router.example.test',
  status: 'up',
  category: 'Routery',
  assetId: 6,
  lastCheck: '2026-09-23T11:59:00Z',
  lastStatusChange: '2026-09-22T12:00:00Z',
  responseMs: 3,
  cpu: 41.2,
  ram: 38,
  hdd: 12,
  uptimeSeconds: 86_400,
  sinceStatusChangeSeconds: 86_400,
  agentLastSeen: 1,
  os: 'OpenWrt',
  details: { model: 'Turris Omnia', kernel: '5.15', net: 1234.5, tcp_retrans: 17, uptime: 1_209_600 },
};

/** The first render pays for loading the page module; under a full parallel run that is over a second. */
const SLOW = { timeout: 5000 };

const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

/** Six five-minute points ending now, inside the chart window. */
const pts = (values: number[]) => {
  const end = Math.floor(Date.now() / 1000);
  return values.map((v, i) => [end - (values.length - i) * 300, v]);
};
const series = (label: string, unit: string, values: number[]) => ({ label, unit, points: pts(values) });

const BATCH = {
  series: {
    response_time: series('Doba odezvy', 'ms', [3, 5, 9, 4, 3, 6]),
    cpu: series('Využití CPU', '%', [10, 40, 25, 60, 30, 20]),
    ram: series('Využití paměti', '%', [30, 45, 38, 50, 41, 39]),
    hdd: series('Zaplnění disku', '%', [12, 12, 12, 12, 12, 12]),
    net: series('Síťový provoz na WAN', 'KB/s', [100, 900, 400, 1200, 300, 800]),
    net_lte: series('Síťový provoz na LTE záloze', 'KB/s', [0, 0, 300, 0, 0, 0]),
    temperature_c: series('Teplota CPU (°C)', '°C', [50, 58, 63, 55, 60, 52]),
    iowait: series('Čekání na I/O', '%', [0.3, 0.6, 0.4, 0.5, 0.3, 0.4]),
    load5: series('Load Average (5 min)', '', [0.2, 0.9, 0.5, 0.4, 0.3, 0.6]),
    ram_used_mb: series('Obsazená paměť', 'MB', [200, 260, 230, 250, 240, 220]),
    wifi_clients: series('Wi-Fi klienti', '', [3, 9, 5, 7, 4, 6]),
  },
};

let insights: () => Response = () =>
  json({
    summary: 'Router je v pořádku. WireGuard protějšek neodpovídá.',
    statusSentence: 'Router je v pořádku.',
    tips: [],
    insights: [{ text: 'WireGuard protějšek neodpovídá.' }],
    timeline: [],
  });

const FINDINGS = {
  findings: [
    {
      key: 'insight:6:network',
      source: 'insight',
      kind: 'network',
      severity: 'warning',
      monitorId: 6,
      monitorName: 'Router',
      monitorType: 'openwrt',
      title: 'WireGuard protějšek neodpovídá.',
      detail: null,
      action: null,
      since: null,
    },
  ],
  total: 1,
  offset: 0,
  counts: { critical: 0, warning: 1, info: 0 },
  devices: [],
  monitorsChecked: 1,
  muted: [],
  canMute: false,
  sourceErrors: [],
  insightsCachedAt: null,
  generatedAt: '2026-09-23T12:00:00Z',
};

const api = (url: string): Response => {
  if (url.includes('action=monitors')) return json({ monitors: [router] });
  if (url.includes('action=metric_series_batch')) return json(BATCH);
  if (url.includes('action=monitor_insights')) return insights();
  if (url.includes('action=findings')) return json(FINDINGS);
  if (url.includes('action=router_recommendations'))
    return json({ monitorId: 6, applicable: true, reason: null, items: [], muted: [] });
  return json({});
};

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => Promise.resolve(api(String(input))))
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
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  insights = () =>
    json({
      summary: 'Router je v pořádku. WireGuard protějšek neodpovídá.',
      statusSentence: 'Router je v pořádku.',
      tips: [],
      insights: [{ text: 'WireGuard protějšek neodpovídá.' }],
      timeline: [],
    });
});

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

describe('Přehled routeru bez zdi grafů (W2-2, charts-08)', () => {
  it('nejvýš šest karet podle typu; WAN a LTE jen ve společném grafu; rovný disk jedním řádkem', async () => {
    renderDetail();
    expect(await screen.findByText('Provoz po linkách (WAN + LTE)', undefined, SLOW)).toBeTruthy();
    // CPU, RAM, WAN + LTE, temperature, latency - the flat disk is not a card.
    expect(screen.getAllByTestId('chart')).toHaveLength(5);
    expect(screen.queryByText('Síťový provoz na LTE záloze')).toBeNull();

    const flat = screen.getByRole('list', { name: 'Beze změny v tomto období' });
    expect(within(flat).getByText('Zaplnění disku')).toBeTruthy();
    expect(within(flat).getByText('12 %, beze změny')).toBeTruthy();
  });

  it('dlaždice se obarví jen po překročení limitu a píše česká čísla (CR-8, C-1)', async () => {
    const full = { ...router, hdd: 97 };
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        return Promise.resolve(url.includes('action=monitors') ? json({ monitors: [full] }) : api(url));
      })
    );
    renderDetail();
    const disk = await screen.findByText('97 %', undefined, SLOW);
    expect(disk.closest('[data-tone]')?.getAttribute('data-tone')).toBe('down');
    const cpu = screen.getByText('41,2 %');
    expect(cpu.closest('[data-tone]')).toBeNull();
  });

  it('odznak Události počítá, co karta ukazuje, a běh má skutečné časy (V-15)', async () => {
    insights = () =>
      json({
        summary: 'Router je v pořádku.',
        statusSentence: 'Router je v pořádku.',
        tips: [],
        insights: [],
        timeline: [
          { type: 'status_changed_down', description: null, at: '2026-09-21 10:40:00', relative: 'před 3 dny' },
          { type: 'status_changed_down', description: null, at: '2026-09-21 10:00:00', relative: 'před 3 dny' },
          { type: 'status_changed_up', description: null, at: '2026-09-21 09:00:00', relative: 'před 3 dny' },
        ],
      });
    renderDetail();
    const tab = await screen.findByRole('tab', { name: /Události/ }, SLOW);
    await waitFor(() => expect(tab.textContent).toBe('Události2'));
  });

  it('skupiny metrik bez přepočtů RAM v MB a load5/15', async () => {
    renderDetail();
    await screen.findByText('Provoz po linkách (WAN + LTE)', undefined, SLOW);
    expect(screen.getByText('Čekání na I/O')).toBeTruthy();
    expect(screen.getByText('Wi-Fi klienti')).toBeTruthy();
    expect(screen.queryByText('Obsazená paměť')).toBeNull();
    expect(screen.queryByText('Load Average (5 min)')).toBeNull();
  });
});

describe('Každý fakt jednou (W2-2, clutter-03/17, honest-21)', () => {
  it('Parametry drží jen identitu, ne čísla z dlaždic a grafů', async () => {
    renderDetail();
    // The identity panel - a labelled region, main's "Parametry" card under its short name.
    const params = await screen.findByRole('region', { name: 'Parametry' }, SLOW);
    expect(within(params).getByText('Turris Omnia')).toBeTruthy();
    for (const gone of ['Odezva', 'Síťový průtok (Rx/Tx)', 'TCP Retransmissions (/proc/net/snmp)']) {
      expect(within(params).queryByText(gone), gone).toBeNull();
    }
  });

  it('„Online nepřetržitě“ a „Uptime zařízení“ jsou dvě různé dlaždice', async () => {
    renderDetail();
    expect(await screen.findByText('Online nepřetržitě', undefined, SLOW)).toBeTruthy();
    const device = screen.getByText('Uptime zařízení').closest('[data-slot="stat-block"]') as HTMLElement;
    expect(within(device).getByText('14 d')).toBeTruthy();
  });

  it('souhrn: věta o stavu, zjištění jednou, žádná žárovka ani čip „Typ“', async () => {
    renderDetail();
    expect(await screen.findByText('Router je v pořádku.', undefined, SLOW)).toBeTruthy();
    expect(await screen.findAllByText('WireGuard protějšek neodpovídá.')).toHaveLength(1);
    expect(screen.queryByText(/💡/)).toBeNull();
    expect(screen.queryByText(/^Typ: /)).toBeNull();
    expect(screen.queryByText('Všechny testy OK')).toBeNull();
  });

  it('selhání souhrnu je hlasité, ne prázdná karta', async () => {
    insights = () => json({ error: 'database_unavailable' }, 500);
    renderDetail();
    expect(await screen.findByText('Souhrn stavu se nepodařilo načíst.', undefined, SLOW)).toBeTruthy();
  });

  it('záložky mají krátké názvy, které se nezalomí', async () => {
    renderDetail();
    const tablist = await screen.findByRole('tablist', undefined, SLOW);
    expect(tablist.className).toContain('*:whitespace-nowrap');
    const names = within(tablist)
      .getAllByRole('tab')
      .map((tab) => tab.textContent);
    expect(names[0]).toBe('Přehled');
    expect(names).toContain('Úložiště');
  });
});
