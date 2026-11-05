// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

vi.mock('@/components/charts/metric-chart', () => ({ MetricChart: () => <div data-testid="chart" /> }));
vi.mock('@/components/charts/heatmap-panel', () => ({ HeatmapPanel: () => <div data-testid="heatmap" /> }));
vi.mock('@/components/charts/histogram-panel', () => ({ HistogramPanel: () => <div data-testid="histogram" /> }));
vi.mock('@/components/charts/correlation-panel', () => ({ CorrelationPanel: () => <div data-testid="corr" /> }));

import { MetricDetailPage } from './metric-detail';

/**
 * With the page's context loaded and only the series failing, the chart area
 * used to read "Načítám měření…" for ever. It says what happened and offers
 * another try.
 */
const NOW = Math.floor(Date.now() / 1000);
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const detail = {
  monitor: { id: 6, name: 'Router', type: 'openwrt', target: null, port: null, checkedFrom: null, assetId: 6 },
  metric: { key: 'cpu_usage', label: 'CPU', unit: '%', counter: false },
  thresholds: { warning: null, critical: null },
  related: [],
  events: [],
};

function show(series: () => Response, metricDetail: unknown = detail) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=metric_detail')) return Promise.resolve(json(metricDetail));
      if (url.includes('action=metric_series')) return Promise.resolve(series());
      if (url.includes('action=annotations')) return Promise.resolve(json({ annotations: [] }));
      return Promise.resolve(json({}));
    })
  );
  render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/6/metric/6/cpu_usage?range=24h']}>
          <Routes>
            <Route path="/infrastructure/:id/metric/:monitorId/:metricKey" element={<MetricDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

describe('Metrika: selhání řady měření', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('řekne, že se načíst nepodařilo, a další pokus načte graf', async () => {
    let fail = true;
    show(() =>
      fail
        ? json({ error: 'database_unavailable' }, 500)
        : json({
            label: 'CPU',
            unit: '%',
            points: Array.from({ length: 20 }, (_, i) => [NOW - (19 - i) * 60, 10 + i]),
            distinctValues: 20,
          })
    );
    expect(await screen.findByText('Měření se nepodařilo načíst.')).toBeTruthy();
    fail = false;
    fireEvent.click(screen.getByRole('button', { name: /Zkusit znovu|Try again/ }));
    expect(await screen.findByTestId('chart')).toBeTruthy();
    expect(screen.queryByText('Měření se nepodařilo načíst.')).toBeNull();
  });
});

describe('Metrika: stará hodnota není aktuální', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  const pointsEndingAgo = (seconds: number) =>
    Array.from({ length: 20 }, (_, i) => [NOW - seconds - (19 - i) * 60, 10 + i]);
  const withLimit = { ...detail, thresholds: { warning: 80, critical: 95 } };
  const tile = async () => (await screen.findByText('Aktuální')).closest('[data-slot="stat-block"]') as HTMLElement;

  it('čerstvé měření je aktuální', async () => {
    show(() => json({ label: 'CPU', unit: '%', points: pointsEndingAgo(0), distinctValues: 20 }), withLimit);
    expect(within(await tile()).queryByText('bez čerstvého měření')).toBeNull();
    expect(screen.getByText('Je to v pořádku?')).toBeTruthy();
  });

  it('agent mlčí deset hodin: žádná aktuální hodnota ani verdikt', async () => {
    show(() => json({ label: 'CPU', unit: '%', points: pointsEndingAgo(10 * 3600), distinctValues: 20 }), withLimit);
    const box = await tile();
    expect(within(box).getByText('bez čerstvého měření')).toBeTruthy();
    expect(within(box).getByText('—')).toBeTruthy();
    expect(screen.queryByText('Je to v pořádku?')).toBeNull();
  });

  it('jediné měření nemá s čím srovnat rytmus, zastaralé se neříká', async () => {
    show(() => json({ label: 'CPU', unit: '%', points: [[NOW - 600, 12]] }));
    expect(within(await tile()).queryByText('bez čerstvého měření')).toBeNull();
  });

  it('denní souhrn není aktuální hodnota', async () => {
    show(() => json({ label: 'CPU', unit: '%', points: pointsEndingAgo(0), distinctValues: 20, resolution: 'daily' }));
    expect(within(await tile()).getByText('bez čerstvého měření')).toBeTruthy();
  });
});
