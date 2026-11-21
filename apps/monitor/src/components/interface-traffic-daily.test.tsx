// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';

// The chart is echarts on a canvas (jsdom has none); the mock keeps what it
// was handed, so the test can check bars (main's `bars` prop) and gaps.
const drawn: { bars?: boolean; days: (number | null)[] }[] = [];
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: ({ data, bars }: { data: { series: { points: { v: number | null }[] }[] }; bars?: boolean }) => {
    drawn.push({ bars, days: data.series[0].points.map((p) => p.v) });
    return <div data-testid="chart" />;
  },
}));

vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true }, loading: false, error: null, isAdmin: true }),
}));

import { InterfaceTrafficDaily } from './interface-traffic-daily';

const GB = 1073741824;
const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

function renderPanel(body: unknown) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(json(body)))
  );
  return render(
    <LanguageProvider>
      <InterfaceTrafficDaily monitorId={6} />
    </LanguageProvider>
  );
}

describe('Provoz po dnech: sloupce a mezery (W2-3, charts-15)', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    drawn.length = 0;
  });

  it('každý den je vlastní sloupec a chybějící den zůstává mezerou', async () => {
    // 21. 9. is missing: nothing was reported that day, the server books nothing.
    renderPanel({
      interfaces: [
        {
          iface: 'eth2',
          total: 5 * GB,
          linkMbit: 2500,
          days: [
            { date: '2026-09-20', rxBytes: 2 * GB, txBytes: GB },
            { date: '2026-09-22', rxBytes: GB, txBytes: GB },
          ],
        },
      ],
    });
    await screen.findByTestId('chart');
    expect(drawn[0]).toEqual({ bars: true, days: [2, 1] });
    expect(screen.queryByTestId('iftraffic-rejected')).toBeNull();
  });

  it('den nad rychlost linky × 24 h server vyřadí a panel to řekne nahlas', async () => {
    renderPanel({
      interfaces: [
        {
          iface: 'eth2',
          total: 3 * GB,
          linkMbit: 100,
          days: [
            { date: '2026-09-20', rxBytes: 2 * GB, txBytes: GB },
            { date: '2026-09-21', rxBytes: null, txBytes: GB, rejected: ['rx'] },
          ],
        },
      ],
    });
    expect((await screen.findByTestId('iftraffic-rejected')).textContent).toContain('Vyřazené dny: 1.');
    // The refused side has no bar - never a zero, never the artefact itself.
    expect(drawn[0].days).toEqual([2, null]);
  });
});
