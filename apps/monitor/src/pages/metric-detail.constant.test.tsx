// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import type { ChartData, MetricCorrelationsResponse, MetricHeatmapResponse } from '@/api/types';

// Canvas charts cannot draw in jsdom; the mocks keep what the page handed them.
const handed: {
  chart: ChartData | null;
  heatmap: MetricHeatmapResponse | null;
  corr: MetricCorrelationsResponse | null;
} = { chart: null, heatmap: null, corr: null };
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: ({ data }: { data: ChartData }) => {
    handed.chart = data;
    return <div data-testid="chart" />;
  },
}));
vi.mock('@/components/charts/heatmap-panel', () => ({
  HeatmapPanel: ({ data }: { data: MetricHeatmapResponse }) => {
    handed.heatmap = data;
    return <div data-testid="heatmap" />;
  },
}));
vi.mock('@/components/charts/histogram-panel', () => ({ HistogramPanel: () => <div data-testid="histogram" /> }));
vi.mock('@/components/charts/correlation-panel', () => ({
  CorrelationPanel: ({ data }: { data: MetricCorrelationsResponse }) => {
    handed.corr = data;
    return <div data-testid="correlations" />;
  },
}));

import { MetricDetailPage } from './metric-detail';

/**
 * The metric page for a metric that sat still, and the three smaller fixes
 * of W2-4 (charts-21, -24, -25, -29): a heatmap that starts at the first
 * sample, events counted inside the drawn window, correlations without the
 * subject's own family, a forecast clipped at the window's edge.
 */
const NOW = Math.floor(Date.now() / 1000);
/** One sample a minute, ending now: no break at the end of the window. */
const minutes = (values: number[]) => values.map((v, i) => [NOW - (values.length - 1 - i) * 60, v]);

let series: Record<string, unknown>;
let detailOver: Record<string, unknown>;
let heatmap: Record<string, unknown>;
let corr: Record<string, unknown>;
let metricKey = 'conntrack_count';

const detail = () => ({
  monitor: { id: 6, name: 'Router', type: 'openwrt', target: null, port: null, checkedFrom: null, assetId: 6 },
  metric: { key: metricKey, label: 'Metrika', unit: '', counter: false },
  thresholds: { warning: null, critical: null },
  related: [],
  events: [],
  ...detailOver,
});

const json = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

function renderMetric() {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=metric_detail')) return Promise.resolve(json(detail()));
      if (url.includes('action=metric_series')) return Promise.resolve(json(series));
      if (url.includes('action=metric_heatmap')) return Promise.resolve(json(heatmap));
      if (url.includes('action=metric_correlations')) return Promise.resolve(json(corr));
      if (url.includes('action=annotations')) return Promise.resolve(json({ annotations: [] }));
      return Promise.resolve(json({}));
    })
  );
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={[`/infrastructure/6/metric/6/${metricKey}?range=24h`]}>
          <Routes>
            <Route path="/infrastructure/:id/metric/:monitorId/:metricKey" element={<MetricDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

beforeEach(() => {
  metricKey = 'conntrack_count';
  series = { label: 'Metrika', unit: '', points: minutes(Array(20).fill(8)), distinctValues: 1 };
  detailOver = {};
  heatmap = { label: 'Metrika', unit: '', days: [] };
  corr = { label: 'Metrika', samples: 0, minPairs: 30, total: 0, correlations: [] };
  handed.chart = handed.heatmap = handed.corr = null;
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Metrika, která stojí: jedna věta a „Rozbor" (W2-4)', () => {
  it('stálá hodnota je jedna věta místo osmi dlaždic, graf je schod', async () => {
    renderMetric();
    const sentence = await screen.findByTestId('metric-constant');
    expect(sentence.textContent).toBe('Celé období 8 · 20 měření · 0 přerušení');
    expect(screen.queryByText('Obvykle (medián)')).toBeNull();
    await screen.findByTestId('chart');
    expect(handed.chart?.series[0].step).toBe(true);
  });

  it('rytmus, rozložení a korelace čekají zavřené v „Rozboru" i s důvodem', async () => {
    renderMetric();
    const fold = (await screen.findByTestId('metric-analysis')) as HTMLDetailsElement;
    expect(fold.open).toBe(false);
    expect(fold.querySelector('summary')?.textContent).toBe('Rozbor');
    expect(fold.textContent).toContain('Hodnota se za celé období nezměnila');
    expect(screen.queryByTestId('histogram')).toBeNull();

    fold.open = true;
    fireEvent(fold, new Event('toggle'));
    expect(await screen.findByTestId('histogram')).toBeTruthy();
  });

  it('tři hodnoty se vyjmenují', async () => {
    series = { label: 'Metrika', unit: '', points: minutes([0, 1, 2, 0, 1, 2, 0, 1, 2, 0, 1, 2]), distinctValues: 3 };
    renderMetric();
    expect((await screen.findByTestId('metric-constant')).textContent).toBe(
      'Celé období jen 0, 1 a 2 · 12 měření · 0 přerušení'
    );
  });

  it('proměnlivá metrika má dlaždice a rozbor hned, bez skládání', async () => {
    series = { label: 'Metrika', unit: '', points: minutes([3, 7, 12, 5, 9, 14, 2, 8]), distinctValues: 8 };
    renderMetric();
    expect(await screen.findByText('Obvykle (medián)')).toBeTruthy();
    expect(screen.queryByTestId('metric-constant')).toBeNull();
    expect(screen.queryByTestId('metric-analysis')).toBeNull();
  });
});

describe('Metrika: heatmapa, události, korelace, odhad (W2-4)', () => {
  beforeEach(() => {
    series = { label: 'Metrika', unit: '%', points: minutes([30, 31, 33, 34, 36, 37, 40, 41]), distinctValues: 8 };
  });

  it('heatmapa začíná prvním dnem s měřením a řekne od kdy', async () => {
    const day = (d: string) => ({ day: d, hours: Array(24).fill(null), samples: Array(24).fill(0) });
    const year = new Date().getFullYear();
    heatmap = {
      label: 'Metrika',
      unit: '%',
      days: [day(`${year}-09-19`), day(`${year}-09-20`), day(`${year}-09-21`)],
      firstSampleDay: `${year}-09-20`,
      requestedDays: 30,
    };
    renderMetric();
    expect((await screen.findByTestId('heatmap-since')).textContent).toBe(
      'Měří se od 20. 9.; dny před tím v mřížce nejsou.'
    );
    expect(handed.heatmap?.days.map((d) => d.day)).toEqual([`${year}-09-20`, `${year}-09-21`]);
  });

  it('popisek událostí počítá jen ty v zobrazeném okně', async () => {
    detailOver = {
      events: [
        { t: (NOW - 3600) * 1000, type: 'wan_lost', label: 'WAN mimo provoz' },
        { t: (NOW - 10 * 86400) * 1000, type: 'wan_lost', label: 'WAN mimo provoz' },
      ],
    };
    renderMetric();
    expect(await screen.findByText(/Svislé čáry v grafu jsou události \(1 v zobrazeném období\)/)).toBeTruthy();
  });

  it('korelace nevypíší vlastní rodinu metriky', async () => {
    metricKey = 'load1';
    corr = {
      label: 'Load 1',
      samples: 500,
      minPairs: 30,
      total: 3,
      family: 'load',
      correlations: [
        { key: 'load5', label: 'Load 5', unit: '', r: 0.98, pairs: 500, reason: null, family: 'load' },
        { key: 'cpu', label: 'CPU', unit: '%', r: 0.7, pairs: 500, reason: null, family: null },
      ],
    };
    renderMetric();
    await screen.findByTestId('correlations');
    expect(handed.corr?.correlations.map((c) => c.key)).toEqual(['cpu']);
  });

  it('odhad zaplnění za koncem okna se utne na okraji a řekne „plno za 24 dní →"', async () => {
    series = { ...series, daysToFull: 24 };
    renderMetric();
    await screen.findByTestId('chart');
    const forecast = handed.chart?.series.find((s) => s.predicted);
    expect(forecast?.label).toBe('plno za 24 dní →');
    const end = forecast?.points[1];
    expect(end && handed.chart?.window && end.t <= handed.chart.window.to).toBe(true);
    expect(end && end.v !== null && end.v < 100).toBe(true);
  });

  it('souvislé metriky: nejbližších 8 a „Všechny metriky"', async () => {
    metricKey = 'cpu';
    const related = [
      ...['wan_rx', 'wan_tx', 'net', 'ping_ms', 'wifi_noise_5g', 'dns_queries'].map((key) => ({
        key,
        label: key,
        unit: '',
        latest: 1,
      })),
      ...['load1', 'load5', 'temperature_c', 'iowait', 'entropy'].map((key) => ({
        key,
        label: key,
        unit: '',
        latest: 2,
      })),
    ];
    detailOver = { related };
    renderMetric();
    const all = await screen.findByRole('button', { name: 'Všechny metriky (11)' });
    // The system metrics of the CPU come first, then the rest in the server's order.
    const chips = screen.getAllByRole('link').filter((a) => a.getAttribute('href')?.includes('/metric/6/'));
    expect(chips.map((a) => a.getAttribute('href')?.split('/').pop())).toEqual([
      'load1',
      'load5',
      'temperature_c',
      'iowait',
      'entropy',
      'wan_rx',
      'wan_tx',
      'net',
    ]);
    fireEvent.click(all);
    expect(screen.getAllByRole('link').filter((a) => a.getAttribute('href')?.includes('/metric/6/'))).toHaveLength(11);
  });
});
