// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import omnia from '@/api/omnia-router.fixture';
import { SpeedtestCard } from './speedtest-card';

// The history chart is echarts on a canvas, and jsdom hands it no 2D context:
// the real component throws inside setOption and React tears the whole card
// down, so every later assertion would fail for the wrong reason. This file
// tests the table columns and the error state, not the drawing.
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: () => <div data-testid="speed-chart" />,
}));

const json = (body: unknown, ok = true, status = 200) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as Response;

function stubHistory(answer: () => unknown, ok = true) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(json(answer(), ok, ok ? 200 : 500)))
  );
}

function renderCard() {
  return render(
    <LanguageProvider>
      <SpeedtestCard monitorId={6} />
    </LanguageProvider>
  );
}

const history = { measurements: omnia.speedtestHistory, averages: {} };

describe('SpeedtestCard', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('a failed request renders the card with the error, not nothing', async () => {
    stubHistory(() => ({ error: 'boom' }), false);
    const { container } = renderCard();
    expect((await screen.findByRole('alert')).textContent).toContain('Naměřené rychlosti se nepodařilo načíst.');
    // The old card returned null here, and a router whose history could not be
    // read looked exactly like a router that was never tested.
    expect(container.textContent).toContain('Rychlost linky');
  });

  it('a router with no measurement at all still renders nothing', async () => {
    stubHistory(() => ({ measurements: [], averages: {} }));
    const { container } = renderCard();
    // Nothing to draw, and no error either: the card stays out of the way.
    await vi.waitFor(() => expect(container.textContent).toBe(''));
  });

  it('every measurement names its server and who started it', async () => {
    stubHistory(() => history);
    renderCard();
    expect(await screen.findByText('Server')).toBeTruthy();
    expect(screen.getByText('Spustil')).toBeTruthy();
    expect(screen.getByText('Prague, Czech Republic (CESNET)')).toBeTruthy();
    expect(screen.getAllByText('Turris OS')).toHaveLength(2);
  });

  it('a row damaged by the old agent shows dashes, never 0.01 Mb/s or a made-up server', async () => {
    stubHistory(() => history);
    renderCard();
    const rows = await screen.findAllByRole('row');
    // The second data row is the W01-damaged one: speeds NULL, server unknown.
    const damaged = rows.find((row) => row.textContent?.includes('2026-09-13'));
    expect(damaged?.textContent).toContain('—');
    expect(damaged?.textContent).not.toContain('0.01');
    expect(damaged?.textContent).not.toContain('0 Mb/s');
  });

  it('a measurement of an unknown origin is not attributed to Turris', async () => {
    stubHistory(() => ({
      measurements: [{ ...omnia.speedtestHistory[0], startedBy: null }],
      averages: {},
    }));
    renderCard();
    await screen.findByText('Spustil');
    expect(screen.queryByText('Turris OS')).toBeNull();
  });
});
