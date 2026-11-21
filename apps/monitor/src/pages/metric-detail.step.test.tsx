// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// ECharts needs a canvas 2D context, jsdom has none (see speedtest-card.test.tsx).
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));
vi.mock('@/components/charts/heatmap-panel', () => ({ HeatmapPanel: () => <div /> }));
vi.mock('@/components/charts/histogram-panel', () => ({ HistogramPanel: () => <div /> }));
vi.mock('@/components/charts/correlation-panel', () => ({ CorrelationPanel: () => <div /> }));

import { MetricDetailPage } from './metric-detail';

/**
 * A step metric (X8) stores the increment itself, so the server SUMS a bucket
 * instead of averaging it. The page has to say so: without it the five step
 * metrics of this release would be read as a level - "on average 3 errors"
 * instead of "3 errors that day".
 */
const detail = (step: boolean) => ({
  monitor: {
    id: 6,
    name: 'Turris Omnia',
    type: 'openwrt',
    target: '10.0.0.1',
    port: null,
    checkedFrom: null,
    assetId: 6,
  },
  metric: { key: 'wan_errors', label: 'Chyby na portu WAN', unit: '', counter: false, step },
  thresholds: { warning: null, critical: null },
  related: [],
  events: [],
});

const series = {
  label: 'Chyby na portu WAN',
  unit: '',
  points: [
    [1789000000, 2],
    [1789000060, 4],
    [1789000120, 6],
  ],
};

const jsonResponse = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

function stubApi(step: boolean) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=metric_detail')) return Promise.resolve(jsonResponse(detail(step)));
      if (url.includes('action=metric_series')) return Promise.resolve(jsonResponse(series));
      if (url.includes('action=metric_heatmap')) return Promise.resolve(jsonResponse({ days: [] }));
      if (url.includes('action=annotations')) return Promise.resolve(jsonResponse({ annotations: [] }));
      return Promise.resolve(jsonResponse({}));
    })
  );
}

function renderMetric() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/6/metric/6/wan_errors']}>
          <Routes>
            <Route path="/infrastructure/:id/metric/:monitorId/:metricKey" element={<MetricDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

describe('metric detail of a step metric', () => {
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

  it('calls the statistic an average increase and says a bucket is a sum', async () => {
    stubApi(true);
    renderMetric();
    expect(await screen.findByText('Průměrný přírůstek')).toBeTruthy();
    expect(screen.getByText(/bod v grafu je součet za dané období/)).toBeTruthy();
  });

  it('leaves an ordinary metric worded as an average', async () => {
    stubApi(false);
    renderMetric();
    expect(await screen.findByText('Průměr')).toBeTruthy();
    expect(screen.queryByText(/součet za dané období/)).toBeNull();
  });
});
