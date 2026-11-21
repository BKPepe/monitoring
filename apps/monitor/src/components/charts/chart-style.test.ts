import { describe, expect, it } from 'vitest';
import type { ChartData, MetricSeries } from '@/api/types';
import {
  bandCaption,
  displayData,
  formatChartValue,
  isIntegerCount,
  isZeroBased,
  silentTail,
  isFresh,
  lineSeries,
  NO_VALUE,
  summarizeSeries,
  timeAxes,
  tooltipRow,
  type ChartTheme,
} from './chart-style';

const theme: ChartTheme = {
  text: '#0b0d10',
  textMuted: '#6b7280',
  grid: 'rgba(0,0,0,0.07)',
  surface: '#f7f8fa',
  tooltipBg: '#ffffff',
  tooltipBorder: 'rgba(0,0,0,0.18)',
  series: {
    cpu: '#007d51',
    memory: '#2a71fe',
    network: '#03919d',
    temperature: '#ab4501',
    disk: '#7544cd',
    latency: '#9c6900',
  },
  band: { warning: 'rgba(133,77,14,0.08)', critical: 'rgba(153,27,27,0.08)' },
  alert: '#991b1b',
  annotation: '#c026d3',
  glow: 8,
  fill: 0.3,
  fontMono: 'monospace',
  fontSans: 'sans-serif',
  key: 'light',
};

const MIN = 60_000;
const at = (i: number) => 1_700_000_000_000 + i * MIN;

describe('a missing measurement stays missing', () => {
  it('a null is kept as null in the drawn data and the line is not joined across it', () => {
    const s = lineSeries(theme, {
      name: 'CPU',
      color: theme.series.cpu,
      points: [
        { t: at(0), v: 10 },
        { t: at(1), v: null },
        { t: at(2), v: 30 },
      ],
    });
    expect(s.data).toEqual([
      [at(0), 10],
      [at(1), null],
      [at(2), 30],
    ]);
    expect(s.connectNulls).toBe(false);
  });

  it('a hole with no row at all becomes an explicit gap, never a straight line across it', () => {
    // Per-minute samples, then nothing for an hour: the endpoint only returns
    // rows that have a value, so the hour arrives as two neighbouring points.
    const points = [0, 1, 2, 3, 4, 64, 65, 66].map((i) => ({ t: at(i), v: i }));
    const s = lineSeries(theme, { name: 'CPU', color: theme.series.cpu, points });
    const values = s.data.map(([, v]) => v);
    expect(values).toContain(null);
    // The measured samples are untouched - nothing moved, nothing invented.
    expect(values.filter((v) => v !== null)).toEqual([0, 1, 2, 3, 4, 64, 65, 66]);
  });

  it('smoothing is monotone, so a curve never overshoots the samples it joins', () => {
    const s = lineSeries(theme, { name: 'CPU', color: theme.series.cpu, points: [{ t: at(0), v: 1 }] });
    expect(s.smoothMonotone).toBe('x');
  });
});

describe('colour comes from the tokens and follows the series', () => {
  it('line, hovered point and wash all wear the colour handed in', () => {
    const s = lineSeries(theme, {
      name: 'RAM',
      color: theme.series.memory,
      area: true,
      points: [{ t: at(0), v: 1 }],
    });
    expect(s.lineStyle.color).toBe('#2a71fe');
    expect(s.lineStyle.width).toBe(2);
    expect(s.itemStyle.color).toBe('#2a71fe');
    // The ring around a hovered point is the card colour, not a border colour.
    expect(s.itemStyle.borderColor).toBe(theme.surface);
    const stops = s.areaStyle?.color.colorStops ?? [];
    expect(stops[0].color).toBe('rgba(42,113,254,0.3)');
    // The wash fades to nothing - it never ends in a solid block at the baseline.
    expect(stops[stops.length - 1].color).toBe('rgba(42,113,254,0)');
  });

  it('the glow follows the theme: none when the theme sets 0', () => {
    const flat = lineSeries({ ...theme, glow: 0 }, { name: 'x', color: '#007d51', points: [{ t: at(0), v: 1 }] });
    expect(flat.lineStyle.shadowBlur).toBe(0);
    const lit = lineSeries(theme, { name: 'x', color: '#007d51', points: [{ t: at(0), v: 1 }] });
    expect(lit.lineStyle.shadowBlur).toBe(8);
  });

  it('a prediction draws dashed and without a wash, so it cannot pass for a measurement', () => {
    const s = lineSeries(theme, {
      name: 'x',
      color: '#007d51',
      dashed: true,
      area: true,
      points: [{ t: at(0), v: 1 }],
    });
    expect(s.lineStyle.type).toBe('dashed');
    expect(s.areaStyle).toBeUndefined();
  });
});

describe('the end dot marks only a fresh newest sample', () => {
  const points = [0, 1, 2, 3].map((i) => ({ t: at(i), v: i }));

  it('drawn on the newest point when the caller asks for it', () => {
    const s = lineSeries(theme, { name: 'x', color: '#007d51', points, endDot: true });
    expect(s.markPoint?.data).toEqual([{ coord: [at(3), 3] }]);
  });

  it('absent when the series ends in a gap - the last value is not "now"', () => {
    const s = lineSeries(theme, {
      name: 'x',
      color: '#007d51',
      points: [...points, { t: at(4), v: null }],
      endDot: true,
    });
    expect(s.markPoint).toBeUndefined();
  });

  it('isFresh: recent sample yes, a sample three hours old no, a trailing gap no', () => {
    expect(isFresh(points, at(3) + 2 * MIN)).toBe(true);
    expect(isFresh(points, at(3) + 180 * MIN)).toBe(false);
    expect(isFresh([...points, { t: at(4), v: null }], at(4))).toBe(false);
    expect(isFresh([], at(0))).toBe(false);
  });

  it('a daily rollup is never "live", however recent its newest day', () => {
    const days = [0, 1, 2].map((d) => ({ t: at(d * 1440), v: d }));
    expect(isFresh(days, at(2 * 1440) + 30 * MIN)).toBe(false);
  });
});

describe('one y-axis', () => {
  it('timeAxes returns a single value axis, not a list', () => {
    const { yAxis } = timeAxes(theme, { unit: 'ms', locale: 'cs-CZ' });
    expect(Array.isArray(yAxis)).toBe(false);
    expect(yAxis.type).toBe('value');
  });

  it('measured from zero unless the caller lets the range follow the data', () => {
    expect(timeAxes(theme, { unit: '%', locale: 'cs-CZ' }).yAxis.min).toBe(0);
    const free = timeAxes(theme, { unit: 'dBm', yMin: null, locale: 'cs-CZ' }).yAxis;
    expect(free.min).toBeUndefined();
    expect(free.scale).toBe(true);
  });
});

describe('summaries count only what was measured', () => {
  it('nulls are skipped: no invented zero minimum, no diluted average', () => {
    const s = summarizeSeries([
      { t: at(0), v: 40 },
      { t: at(1), v: null },
      { t: at(2), v: null },
      { t: at(3), v: 60 },
      { t: at(4), v: null },
    ]);
    expect(s).toMatchObject({ count: 2, min: 40, max: 60, avg: 50, gaps: 2 });
    expect(s.last).toEqual({ t: at(3), v: 60 });
  });

  it('a window with no measurement says so with nulls, not zeros', () => {
    const s = summarizeSeries([
      { t: at(0), v: null },
      { t: at(1), v: null },
    ]);
    expect(s).toMatchObject({ count: 0, min: null, max: null, avg: null, last: null, gaps: 1 });
  });
});

describe('a missing value prints as a dash', () => {
  it('formatChartValue', () => {
    expect(formatChartValue(null, 'ms')).toBe(NO_VALUE);
    expect(formatChartValue(undefined)).toBe(NO_VALUE);
    expect(formatChartValue(Number.NaN, '%')).toBe(NO_VALUE);
    expect(formatChartValue(0, '%')).toBe('0 %');
    expect(formatChartValue(12.3456, 'ms')).toBe('12.35 ms');
  });

  it('the tooltip row shows the dash and escapes the series name', () => {
    const row = tooltipRow(theme, '#007d51', '<img src=x onerror=alert(1)>', formatChartValue(null, '%'));
    expect(row).toContain('—');
    expect(row).not.toContain('<img');
    expect(row).toContain('&lt;img');
  });
});

const series = (
  key: string,
  unit: string,
  values: (number | null)[],
  tone: MetricSeries['tone'] = 'cpu'
): MetricSeries => ({
  key,
  label: key,
  unit,
  tone,
  points: values.map((v, i) => ({ t: at(i), v })),
});
const chartOf = (over: Partial<ChartData> = {}): ChartData => ({
  id: 'cpu',
  title: 'CPU',
  yMax: 100,
  yMin: 0,
  series: [series('cpu', '%', [10, 12, 11, 13])],
  ...over,
});
// The dictionary is not loaded here: the fallback with its params filled in, like the provider does.
const tr = (_key: string, params?: Record<string, string | number> | string, fallback?: string) => {
  let text = typeof params === 'string' ? params : (fallback ?? _key);
  if (params && typeof params === 'object') {
    for (const [k, v] of Object.entries(params)) text = text.replace(`{${k}}`, String(v));
  }
  return text;
};

describe('čísla a osy v jazyce stránky (V-11, charts-22)', () => {
  it('formatChartValue s jazykem píše desetinnou čárku a trvání v sekundách jako hodiny', () => {
    expect(formatChartValue(12.3456, 'ms', 'cs')).toBe('12,35 ms');
    expect(formatChartValue(12.3456, 'ms', 'en')).toBe('12.35 ms');
    expect(formatChartValue(3 * 3600 + 12 * 60, 's', 'cs')).toBe('3 h 12 min');
    expect(formatChartValue(null, 's', 'cs')).toBe(NO_VALUE);
  });

  it('osa x končí oknem, ne daty, a dny píše „23. 9.“ česky, „23 Sep“ anglicky', () => {
    const window = { from: at(0), to: at(600) };
    const cs = timeAxes(theme, { unit: '%', locale: 'cs-CZ', window }).xAxis;
    expect([cs.min, cs.max]).toEqual([window.from, window.to]);
    expect(cs.axisLabel.formatter).toEqual({ day: '{d}. {M}.' });
    expect(timeAxes(theme, { unit: '%', locale: 'en-GB' }).xAxis.axisLabel.formatter).toEqual({ day: '{d} {MMM}' });
  });

  it('trvání v sekundách nemá jednotku v názvu osy, popisky ji nesou samy', () => {
    const y = timeAxes(theme, { unit: 's', locale: 'cs-CZ' }).yAxis;
    expect(y.name).toBeUndefined();
    expect(y.axisLabel.formatter(7200)).toBe('2 h');
  });
});

describe('co graf smí tvrdit (C-5)', () => {
  it('schodovitá čára se nevyhlazuje, řídká řada má tečky', () => {
    const s = lineSeries(theme, {
      name: 'Hráči',
      color: '#000000',
      points: [{ t: at(0), v: 1 }],
      step: true,
      symbols: true,
    });
    expect(s.step).toBe('end');
    expect(s.smooth).toBe(false);
    expect(s.showSymbol).toBe(true);
  });

  it('počty bez jednotky poznají jen celá čísla', () => {
    expect(isIntegerCount(series('mc', '', [3, 4, 2]))).toBe(true);
    expect(isIntegerCount(series('load', '', [0.5, 1]))).toBe(false);
    expect(isIntegerCount(series('cpu', '%', [3, 4]))).toBe(false);
  });

  it('plocha pod čarou patří jen na osu od nuly bez záporných hodnot (charts-13)', () => {
    expect(isZeroBased(chartOf())).toBe(true);
    expect(isZeroBased(chartOf({ yMin: null, series: [series('rsrp', 'dBm', [-98, -97])] }))).toBe(false);
  });

  it('ticho na konci okna najde, běžnou kadenci za ticho nepovažuje', () => {
    expect(silentTail(chartOf({ window: { from: at(-60), to: at(180) } }))).toEqual({ from: at(3), to: at(180) });
    expect(silentTail(chartOf({ window: { from: at(-60), to: at(4) } }))).toBeNull();
    expect(silentTail(chartOf())).toBeNull();
  });

  it('názvy pásem skládá do jedné věty pod graf (charts-20)', () => {
    const bands: ChartData['bands'] = [
      { from: 65, to: 80, tone: 'warning', label: 'Varování' },
      { from: 80, to: 100, tone: 'critical', label: 'Kritické' },
    ];
    expect(bandCaption(chartOf({ bands }), 'cs', tr)).toBe('Varování od 65 % · Kritické od 80 %');
    expect(bandCaption(chartOf(), 'cs', tr)).toBeNull();
  });
});

describe('displayData: jednotky a odstíny, jak je graf ukáže', () => {
  it('propustnost v KB/s převede na Mbit/s i s pásmy a průměrem minulého období (charts-23)', () => {
    const data = chartOf({
      yMax: null,
      series: [{ ...series('net', 'KB/s', [1500, 1600, 1700], 'network'), previousAvg: 1000 }],
      bands: [{ from: 1000, to: 2000, tone: 'warning', label: 'Varování' }],
    });
    const shown = displayData(data);
    expect(shown.series[0].unit).toBe('Mbit/s');
    expect(shown.series[0].points[0].v).toBe(12.29);
    expect(shown.series[0].previousAvg).toBe(8.19);
    expect(shown.bands?.[0].from).toBe(8.19);
    // Idempotent: the card, the chart and the stats strip may each apply it.
    expect(displayData(shown)).toEqual(shown);
  });

  it('nečinnou linku nechá v KB/s a nulu nevymyslí', () => {
    const data = chartOf({ series: [series('net', 'KB/s', [1, null, 2], 'network')] });
    expect(displayData(data)).toBe(data);
  });

  it('dvě měřené řady téhož odstínu rozliší, předpověď si odstín ponechá (C-2)', () => {
    const wan = series('net', 'Mbit/s', [1, 2], 'network');
    const lte = series('net_lte', 'Mbit/s', [0, 1], 'network');
    const forecast = { ...series('net_f', 'Mbit/s', [2, 3], 'network'), predicted: true };
    const tones = displayData(chartOf({ series: [wan, lte, forecast] })).series.map((s) => s.tone);
    expect(tones[0]).toBe('network');
    expect(tones[1]).not.toBe('network');
    expect(tones[2]).toBe('network');
  });
});
