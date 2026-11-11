// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import DashboardTraffic from './dashboard-traffic';

/**
 * "Odezva k bráně" sat next to "Aktuální" without its freshness check: a
 * router silent for hours still showed its last gateway echo as now (R66).
 */
vi.mock('@/components/charts/metric-chart', () => ({ MetricChart: () => null }));

function renderWith(lastSampleAgoMin: number) {
  const nowS = Math.floor(Date.now() / 1000);
  const points = [3, 2, 1, 0].map((i) => [nowS - (lastSampleAgoMin + i) * 60, 1200]);
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ points, label: 'Provoz', unit: 'KB/s' }),
      } as Response)
    )
  );
  return render(
    <LanguageProvider>
      <DashboardTraffic source={{ id: 7, name: 'Router', metric: 'net' }} gatewayLatencyMs={12} />
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Odezva k bráně jen z čerstvého měření (R66)', () => {
  it('čerstvý provoz: odezva se ukáže', async () => {
    renderWith(0);
    expect(await screen.findByText('12')).toBeTruthy();
  });

  it('router mlčí dvě hodiny: odezva ne, jako u aktuální hodnoty', async () => {
    renderWith(120);
    expect((await screen.findAllByText('bez čerstvého měření')).length).toBe(2);
    expect(screen.queryByText('12')).toBeNull();
  });
});
