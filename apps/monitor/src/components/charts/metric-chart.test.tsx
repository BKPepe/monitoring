// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render } from '@testing-library/react';
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
