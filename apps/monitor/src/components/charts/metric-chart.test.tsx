// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import type { ChartData } from '@/api/types';

/**
 * What MetricChart hands to ECharts, captured at the boundary: the canvas
 * itself cannot be inspected in jsdom, but every honesty rule is decided in
 * the option - so that is what is checked.
 */
const captured: { option: Record<string, any>; summary?: string }[] = [];
vi.mock('./chart', () => ({
  Chart: (props: { option: Record<string, any>; summary?: string }) => {
    captured.push({ option: props.option, summary: props.summary });
    return null;
  },
}));

const { MetricChart } = await import('./metric-chart');
const { LanguageProvider } = await import('@/context/language-context');

const NOW = Date.UTC(2026, 8, 25, 12, 0, 0);
const MIN = 60_000;

beforeEach(() => {
  captured.length = 0;
  vi.useFakeTimers({ now: NOW, toFake: ['Date'] });
  window.matchMedia = ((q: string) => ({
    matches: false,
    media: q,
    addEventListener: () => {},
    removeEventListener: () => {},
  })) as unknown as typeof window.matchMedia;
  // The chart reads its colours from the CSS tokens, as in the browser.
  const root = document.documentElement.style;
  root.setProperty('--chart-cpu', '#123456');
  root.setProperty('--chart-memory', '#654321');
  root.setProperty('--card', '#f7f8fa');
  root.setProperty('--chart-glow', '0');
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

function chart(points: { t: number; v: number | null }[], extra: Partial<ChartData> = {}): ChartData {
  return {
    id: 'cpu',
    title: 'CPU',
    yMax: 100,
    yMin: 0,
    series: [{ key: 'cpu', label: 'CPU', unit: '%', tone: 'cpu', points }],
    ...extra,
  };
}

function draw(data: ChartData) {
  render(
    <LanguageProvider>
      <MetricChart data={data} />
    </LanguageProvider>
  );
  return captured[captured.length - 1];
}

const minutes = (n: number, v: (i: number) => number | null, end = NOW) =>
  Array.from({ length: n }, (_, i) => ({ t: end - (n - 1 - i) * MIN, v: v(i) }));

describe('MetricChart option', () => {
  it('series colours are the CSS tokens, not constants', () => {
    const { option } = draw(
      chart(
        minutes(10, (i) => i),
        {
          series: [
            { key: 'a', label: 'A', unit: '%', tone: 'cpu', points: minutes(10, (i) => i) },
            { key: 'b', label: 'B', unit: '%', tone: 'memory', points: minutes(10, (i) => i) },
          ],
        }
      )
    );
    const lines = option.series.filter((s: { type: string }) => s.type === 'line');
    expect(lines.map((s: { lineStyle: { color: string } }) => s.lineStyle.color)).toEqual(['#123456', '#654321']);
  });

  it('draws one y-axis, even with two series', () => {
    const { option } = draw(
      chart([], {
        series: [
          { key: 'a', label: 'A', unit: 'Mbit/s', tone: 'network', points: minutes(10, (i) => i * 100) },
          { key: 'b', label: 'B', unit: 'Mbit/s', tone: 'memory', points: minutes(10, (i) => i) },
        ],
      })
    );
    expect(Array.isArray(option.yAxis)).toBe(false);
  });

  it('keeps a null as a gap and prints it as a dash in the tooltip', () => {
    const points = minutes(10, (i) => (i === 5 ? null : 20 + i));
    const { option } = draw(chart(points));
    const line = option.series.find((s: { name: string }) => s.name === 'CPU');
    expect(line.data[5]).toEqual([points[5].t, null]);
    expect(line.connectNulls).toBe(false);

    const html: string = option.tooltip.formatter([
      { seriesName: 'CPU', value: [points[5].t, null], axisValue: points[5].t },
    ]);
    expect(html).toContain('—');
    expect(html).not.toMatch(/>0 %</);
  });

  it('marks the newest sample only while it is fresh', () => {
    const fresh = draw(chart(minutes(10, (i) => i)));
    expect(fresh.option.series.find((s: { name: string }) => s.name === 'CPU').markPoint).toBeDefined();

    cleanup();
    // The agent stopped three hours ago: the last value is history, not "now".
    const stale = draw(chart(minutes(10, (i) => i, NOW - 3 * 60 * MIN)));
    expect(stale.option.series.find((s: { name: string }) => s.name === 'CPU').markPoint).toBeUndefined();
  });

  it('the screen-reader summary counts only measured points and says when nothing was measured', () => {
    const { summary } = draw(
      chart([], {
        series: [
          { key: 'a', label: 'A', unit: '%', tone: 'cpu', points: minutes(4, (i) => [10, null, 30, null][i]) },
          { key: 'b', label: 'B', unit: '%', tone: 'memory', points: minutes(3, () => null) },
        ],
      })
    );
    expect(summary).toContain('minimum 10 %');
    expect(summary).toContain('průměr 20 %');
    expect(summary).toContain('B: žádná data');
  });
});

type Line = Record<string, any>;
const firstLine = (option: Record<string, any>): Line =>
  option.series.find((s: Line) => s.type === 'line' && !String(s.name).startsWith('__range'));

describe('MetricChart: co graf smí tvrdit (C-5)', () => {
  it('osu x připne na zvolené okno a ticho na konci vystínuje „bez dat od“ (charts-02)', () => {
    const window = { from: NOW - 24 * 60 * MIN, to: NOW };
    // The agent's last report is three hours old; the axis still ends now.
    const { option } = draw(
      chart(
        minutes(10, (i) => i, NOW - 3 * 60 * MIN),
        { window }
      )
    );
    expect(option.xAxis.min).toBe(window.from);
    expect(option.xAxis.max).toBe(window.to);
    const tail = firstLine(option).markArea.data.find((d: Line[]) => String(d[0].name).startsWith('bez dat od'));
    expect(tail?.[0].label.show).toBe(true);
  });

  it('nemá toolbox na plátně; PNG, CSV a reset jsou v menu „⋯“ (charts-11)', () => {
    const { option } = draw(chart(minutes(10, (i) => i)));
    expect(option).not.toHaveProperty('toolbox');
    expect(screen.getByRole('button', { name: 'Akce grafu' })).toBeTruthy();
  });

  it('řídkou řadu kreslí s tečkami, hustou bez nich', () => {
    expect(firstLine(draw(chart(minutes(10, (i) => i))).option).showSymbol).toBe(true);
    cleanup();
    expect(firstLine(draw(chart(minutes(120, (i) => i % 7))).option).showSymbol).toBe(false);
  });

  it('počty bez jednotky kreslí schodovitě a nevyhlazuje je, procenta vyhlazuje', () => {
    const players = draw(
      chart([], {
        yMax: null,
        series: [{ key: 'mc', label: 'Hráči', unit: '', tone: 'memory', points: minutes(8, (i) => i % 3) }],
      })
    );
    expect(firstLine(players.option).step).toBe('end');
    expect(firstLine(players.option).smooth).toBe(false);
    cleanup();
    expect(firstLine(draw(chart(minutes(8, (i) => i))).option).step).toBeUndefined();
  });

  it('plochu kreslí jen nad osou od nuly - dBm plochu nemá (charts-13)', () => {
    expect(firstLine(draw(chart(minutes(8, (i) => i))).option).areaStyle).toBeDefined();
    cleanup();
    const dbm = draw(
      chart([], {
        yMin: null,
        yMax: null,
        series: [
          { key: 'rsrp', label: 'RSRP', unit: 'dBm', tone: 'latency', points: minutes(8, (i) => -98 + (i % 2)) },
        ],
      })
    );
    expect(firstLine(dbm.option).areaStyle).toBeUndefined();
  });

  it('názvy pásem nepíše do grafu, ale pod něj (charts-20)', () => {
    const bands: ChartData['bands'] = [
      { from: 65, to: 80, tone: 'warning', label: 'Varování' },
      { from: 80, to: 100, tone: 'critical', label: 'Kritické' },
    ];
    const { option } = draw(
      chart(
        minutes(8, (i) => i),
        { bands }
      )
    );
    const areas = firstLine(option).markArea.data as Line[][];
    expect(areas.filter((d) => d[0].yAxis != null).every((d) => d[0].label?.show === false)).toBe(true);
    expect(screen.getByText('Varování od 65 % · Kritické od 80 %')).toBeTruthy();
  });

  it('dvě řady téhož odstínu (WAN a LTE) dostanou různé barvy (C-2)', () => {
    const { option } = draw(
      chart([], {
        yMax: null,
        series: [
          { key: 'net', label: 'WAN', unit: 'KB/s', tone: 'network', points: minutes(8, (i) => i) },
          { key: 'net_lte', label: 'LTE', unit: 'KB/s', tone: 'network', points: minutes(8, (i) => i) },
        ],
      })
    );
    const colors = option.series.filter((s: Line) => s.type === 'line').map((s: Line) => s.lineStyle.color);
    expect(colors[0]).not.toBe(colors[1]);
  });

  it('čísla v popisku píše česky s desetinnou čárkou (V-11)', () => {
    const points = minutes(8, (i) => 12.5 + i);
    const { option } = draw(chart(points));
    const html: string = option.tooltip.formatter([
      { seriesName: 'CPU', value: [points[0].t, 12.5], axisValue: points[0].t },
    ]);
    expect(html).toContain('12,5 %');
  });
});
