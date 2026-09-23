// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

// ECharts needs a canvas 2D context, jsdom has none (see speedtest-card.test.tsx).
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="chart" />,
}));
vi.mock('@/components/charts/heatmap-panel', () => ({ HeatmapPanel: () => <div data-testid="heatmap" /> }));
vi.mock('@/components/charts/histogram-panel', () => ({ HistogramPanel: () => <div /> }));
vi.mock('@/components/charts/correlation-panel', () => ({
  CorrelationPanel: () => <div data-testid="correlations" />,
}));

import { MetricDetailPage } from './metric-detail';

/**
 * W1-C3 on the metric page: a count of log errors has no daily rhythm and
 * correlates with nothing, so once the router sends the masked lines the page
 * lists them instead of the heatmap and the correlations. A failed read of
 * the lines is said out loud and can be retried.
 */
const detail = {
  monitor: { id: 6, name: 'Turris', type: 'openwrt', target: '', port: null, checkedFrom: null, assetId: 6 },
  metric: { key: 'log_errors_24h', label: 'Chyby v logu', unit: '', counter: false, step: false },
  thresholds: { warning: null, critical: null },
  related: [],
  events: [],
};

const LINES = {
  log_errors_24h: 8,
  log_window_secs: 7200,
  log_lines_state: 'on',
  log_errors_recent: [{ ts: 1789000000, prog: 'dnsmasq', msg: 'failed to send packet to <ipv4>', count: 6 }],
};

const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

let monitorsUp = true;
let details: Record<string, unknown> = LINES;

function renderMetric() {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=metric_detail')) return Promise.resolve(json(detail));
      if (url.includes('action=metric_series')) return Promise.resolve(json({ label: 'x', unit: '', points: [] }));
      if (url.includes('action=monitors')) {
        return Promise.resolve(
          monitorsUp
            ? json({ monitors: [{ id: 6, name: 'Turris', type: 'openwrt', target: '', details }] })
            : json({ error: 'monitors_unavailable', message: 'x' }, 500)
        );
      }
      if (url.includes('action=annotations')) return Promise.resolve(json({ annotations: [] }));
      return Promise.resolve(json({}));
    })
  );
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/6/metric/6/log_errors_24h']}>
          <Routes>
            <Route path="/infrastructure/:assetId/metric/:monitorId/:metricKey" element={<MetricDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

beforeEach(() => {
  monitorsUp = true;
  details = LINES;
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

describe('Metrika chyb v logu: řádky místo heatmapy (W1-C3)', () => {
  it('router poslal řádky: seznam s maskovanou zprávou, bez heatmapy a korelací', async () => {
    renderMetric();
    const card = await screen.findByTestId('log-lines-card');
    expect(await within(card).findByText(/failed to send packet to <ipv4>/)).toBeTruthy();
    expect(screen.queryByTestId('heatmap')).toBeNull();
    expect(screen.queryByTestId('correlations')).toBeNull();
  });

  it('starší agent bez řádků: stránka zůstane s heatmapou', async () => {
    details = { log_errors_24h: 8 };
    renderMetric();
    expect(await screen.findByText('Denní rytmus (30 dní)')).toBeTruthy();
    // Let the monitors answer land, so the check is about the answer, not the wait for it.
    await waitFor(() =>
      expect(vi.mocked(fetch).mock.calls.some(([u]) => String(u).includes('action=monitors'))).toBe(true)
    );
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId('log-lines-card')).toBeNull();
    expect(screen.getByText('Denní rytmus (30 dní)')).toBeTruthy();
  });

  it('seznam monitorů selže: hlasitá chyba, opakování načte řádky', async () => {
    monitorsUp = false;
    renderMetric();
    const error = await screen.findByText('Řádky z logu se nepodařilo načíst.');
    monitorsUp = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect(await screen.findByText(/failed to send packet to <ipv4>/)).toBeTruthy();
    expect(screen.queryByText('Řádky z logu se nepodařilo načíst.')).toBeNull();
  });
});
