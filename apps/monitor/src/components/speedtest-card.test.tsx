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
// The mock keeps the points it was handed, so a test can check the time axis.
const drawn: { t: number; v: number | null }[][] = [];
vi.mock('@/components/charts/metric-chart', () => ({
  MetricChart: ({ data }: { data: { series: { points: { t: number; v: number | null }[] }[] } }) => {
    drawn.push(data.series[0].points);
    return <div data-testid="speed-chart" />;
  },
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
    const damaged = rows.find((row) => /13\. ?9\. ?2026/.test(row.textContent ?? ''));
    expect(damaged).toBeTruthy();
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

describe('Rychlost linky: server a datum (W1-C4)', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    drawn.length = 0;
  });

  it('řádek bez serveru i nástroje (agent 0.1.6) řekne „nezaznamenáno", ne holou pomlčku', async () => {
    stubHistory(() => history);
    renderCard();
    const rows = await screen.findAllByRole('row');
    const old = rows.find((row) => /13\. ?9\. ?2026/.test(row.textContent ?? ''));
    expect(old?.textContent).toContain('nezaznamenáno (agent 0.1.6 a starší)');
    // Nothing is backfilled: the newer row keeps its own server, the old one gets none.
    expect(screen.getAllByText('Prague, Czech Republic (CESNET)')).toHaveLength(1);
  });

  it('známý nástroj bez jména serveru zůstává pomlčkou', async () => {
    stubHistory(() => ({
      measurements: [{ ...omnia.speedtestHistory[0], server: null, tool: 'librespeed-cli' }],
      averages: {},
    }));
    renderCard();
    await screen.findByText('Spustil');
    expect(screen.queryByText(/nezaznamenáno/)).toBeNull();
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });

  it('čas z MySQL s mezerou se kreslí i čte jako datum (Safari vracelo NaN)', async () => {
    // WebKit's Date.parse: the 'YYYY-MM-DD HH:MM:SS' form is NaN there, only the
    // ISO 'T' form parses. V8 accepts both, so the test plays WebKit.
    const parse = Date.parse;
    vi.spyOn(Date, 'parse').mockImplementation((value: string) =>
      /^\d{4}-\d{2}-\d{2} /.test(value) ? NaN : parse(value)
    );
    stubHistory(() => ({
      measurements: [
        { ...omnia.speedtestHistory[0], measuredAt: '2026-09-15 06:10:00' },
        { ...omnia.speedtestHistory[0], measuredAt: '2026-09-14 06:10:00', downloadMbps: 900 },
      ],
      averages: {},
    }));
    renderCard();
    await screen.findByText('Spustil');
    expect(screen.queryByText('2026-09-15 06:10:00')).toBeNull();
    expect(screen.getAllByText(/15\. ?9\. ?2026/).length).toBeGreaterThan(0);
    const points = drawn[drawn.length - 1];
    expect(points.filter((p) => Number.isFinite(p.t) && p.v !== null)).toHaveLength(2);
    vi.restoreAllMocks();
  });
});

describe('Rychlost linky: přes kterou linku se měřilo (agent 0.1.11)', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    drawn.length = 0;
  });

  const wan = { ...omnia.speedtestHistory[0], uplink: 'wan' as const, uplinkSource: 'counters' as const };
  const lte = {
    ...omnia.speedtestHistory[0],
    measuredAt: '2026-09-21T05:23:41+02:00',
    downloadMbps: 47.18,
    uploadMbps: 44.08,
    uplink: 'backup' as const,
    uplinkSource: 'counters' as const,
    proto: 'http' as const,
  };

  it('a test over LTE is labelled, kept out of the chart and not the headline', async () => {
    const older = { ...wan, measuredAt: '2026-09-19T05:23:41+02:00', downloadMbps: 1300 };
    stubHistory(() => ({ measurements: [lte, wan, older], averages: {} }));
    renderCard();
    await screen.findByText('Linka');
    expect(screen.getByText('LTE záloha')).toBeTruthy();
    expect(screen.getAllByText('WAN')).toHaveLength(2);
    expect(screen.getByText(/Naposledy přes LTE zálohu/)).toBeTruthy();
    const points = drawn[drawn.length - 1];
    expect(points.map((p) => p.v)).toEqual([1300, 1350.12]);
  });

  it('an outage guess says it is a guess, and an unknown line stays a dash', async () => {
    stubHistory(() => ({
      measurements: [{ ...lte, uplinkSource: 'outage' as const }, omnia.speedtestHistory[0]],
      averages: {},
    }));
    renderCard();
    const guess = await screen.findByText('LTE?');
    expect(guess.closest('td')?.getAttribute('title')).toContain('odhad, ne měření');
    const rows = screen.getAllByRole('row');
    expect(rows.some((row) => row.textContent?.includes('WAN'))).toBe(false);
  });
});
