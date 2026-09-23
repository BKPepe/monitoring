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
 * W1-B2: response_time at 90 d / 1 y comes from the daily rollup. When the
 * monitor's data starts later than the window, the page says from when
 * instead of letting the axis shrink to the measured weeks unannounced.
 */
const NOW = new Date(2026, 8, 23, 12, 0);

const detail = {
  monitor: {
    id: 2,
    name: 'E-shop',
    type: 'web',
    target: 'https://shop.example.test',
    port: null,
    checkedFrom: null,
    assetId: 2,
  },
  metric: { key: 'response_time', label: 'Odezva', unit: 'ms', counter: false, step: false },
  thresholds: { warning: null, critical: null },
  related: [],
  events: [],
};

/** Daily averages stamped at local midnight, `from` days back to yesterday. */
const dailySeries = (from: number) => {
  const points: [number, number][] = [];
  for (let back = from; back >= 1; back--) {
    points.push([new Date(2026, 8, 23 - back).getTime() / 1000, 120 + back]);
  }
  return { label: 'Odezva', unit: 'ms', resolution: 'daily', points };
};

const json = (body: unknown) =>
  ({ ok: true, status: 200, json: () => Promise.resolve(body), text: () => Promise.resolve('') }) as Response;

function renderAt(range: string, from: number) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=metric_detail')) return Promise.resolve(json(detail));
      if (url.includes('action=metric_series')) return Promise.resolve(json(dailySeries(from)));
      if (url.includes('action=annotations')) return Promise.resolve(json({ annotations: [] }));
      return Promise.resolve(json({}));
    })
  );
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={[`/infrastructure/2/metric/2/response_time?range=${range}`]}>
          <Routes>
            <Route path="/infrastructure/:id/metric/:monitorId/:metricKey" element={<MetricDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(NOW);
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
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Metrika: 90 dní a rok z denních souhrnů (W1-B2)', () => {
  it('odezva 90d s daty jen 40 dní: „90d: data od 14. 8.“ vedle poznámky o denních bodech', async () => {
    renderAt('90d', 40);
    const note = await screen.findByTestId('metric-coverage');
    expect(note.textContent).toContain('90d: data od 14. 8.');
    expect(screen.getByText(/jeden bod denní průměr/)).toBeTruthy();
  });

  it('plné okno 90 dní poznámku o pokrytí nemá', async () => {
    renderAt('90d', 89);
    expect(await screen.findByText(/jeden bod denní průměr/)).toBeTruthy();
    expect(screen.queryByTestId('metric-coverage')).toBeNull();
  });
});
