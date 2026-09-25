import type { MetricPoint, MetricTone } from '@/api/types';
import { insertGaps, medianStep } from '@/lib/series-gaps';
import { escapeHtml, withAlpha } from './color';

/**
 * The shared chart foundation: every ECharts option in the app is assembled
 * from these pieces, so a line, an axis or a tooltip looks and behaves the
 * same on every page.
 *
 * Pure on purpose - no React, no DOM. The theme arrives as plain values (read
 * from the CSS tokens by use-chart-theme.ts), which keeps the honesty rules
 * here unit-testable: a null stays a gap, one y-axis only, colours come from
 * tokens, a missing value prints as a dash.
 *
 * The look follows NetPulse (the owner's reference): a 2 px line with a soft
 * glow, a vertical wash fading to nothing, almost no grid, small mono axis
 * labels, a rounded tooltip card. All of that is decoration and is never
 * allowed to carry meaning - the glow is 0 in the light theme, and the
 * smoothing is monotone, so the curve never overshoots a measured value.
 */
export interface ChartTheme {
  text: string;
  textMuted: string;
  /** Hairline for the grid and the x-axis rule, one step off the surface. */
  grid: string;
  /** The card the chart sits on - the ring around a hovered point and the end dot. */
  surface: string;
  tooltipBg: string;
  tooltipBorder: string;
  series: Record<MetricTone, string>;
  /** Fill of the threshold bands. Weak enough not to overpower the data line. */
  band: Record<'warning' | 'critical', string>;
  /**
   * An outage or a crossed limit on the timeline. It is a verdict, so it
   * wears the status token - never a series hue that could pass for data.
   */
  alert: string;
  /** Colour of user-written chart notes - a human's claim, not a measurement. */
  annotation: string;
  /** Blur in px behind a line; 0 = no glow (light theme, paper). */
  glow: number;
  /** Alpha at the top of the area wash. */
  fill: number;
  fontMono: string;
  fontSans: string;
  /** Key for the `key` prop — forces a chart re-render after a theme change. */
  key: string;
}

/** What a missing measurement prints as, everywhere a number would be. */
export const NO_VALUE = '—';

/**
 * Past this many points a chart drops the smoothing and the glow: a 30-day
 * chart is tens of thousands of samples, up to thirteen of them are drawn at
 * once on the overview, and a canvas shadow is re-rendered per stroke.
 */
const DENSE = 2000;

/** Two decimals at most, no trailing zeros; null and NaN print as a dash, never as 0. */
export function formatChartValue(v: number | null | undefined, unit = ''): string {
  if (v == null || !Number.isFinite(v)) return NO_VALUE;
  const n = String(Math.round(v * 100) / 100);
  return unit ? `${n} ${unit}` : n;
}

/** Locale-formatted timestamp, the same shape every other time in the app uses. */
export function formatChartTime(ms: number, locale: string): string {
  return new Date(ms).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' });
}

/** 12 000 -> 12 k. Keeps a long axis label from eating the plot area. */
function compact(value: number, locale: string): string {
  if (!Number.isFinite(value)) return NO_VALUE;
  if (Math.abs(value) < 1000) return String(Math.round(value * 100) / 100);
  return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

export interface SeriesSummary {
  /** Measured points only - a null is not a sample. */
  count: number;
  min: number | null;
  max: number | null;
  avg: number | null;
  /** Breaks in measurement: runs of nulls, however long each one is. */
  gaps: number;
  /** The newest MEASURED point, or null when nothing was measured. */
  last: MetricPoint | null;
}

/**
 * Min / max / average over the measured points and nothing else.
 *
 * A null counted as zero would drag the average down and invent a minimum of
 * 0 for a metric that never read 0; a window with no measurement at all
 * returns nulls, so the caller says "no data" instead of printing "0".
 */
export function summarizeSeries(points: MetricPoint[]): SeriesSummary {
  let count = 0;
  let sum = 0;
  let min: number | null = null;
  let max: number | null = null;
  let gaps = 0;
  let last: MetricPoint | null = null;
  let inGap = false;
  for (const p of points) {
    if (p.v == null || !Number.isFinite(p.v)) {
      if (!inGap) gaps += 1;
      inGap = true;
      continue;
    }
    inGap = false;
    count += 1;
    sum += p.v;
    min = min === null || p.v < min ? p.v : min;
    max = max === null || p.v > max ? p.v : max;
    last = p;
  }
  return { count, min, max, avg: count ? sum / count : null, gaps, last };
}

/**
 * Whether the newest sample is recent enough for the chart to present it as
 * "now" - the end dot and the live chip depend on this and on nothing else.
 *
 * Fresh = the series ends in a measurement (not in a gap) no older than
 * 2.5 sample steps, with a 3-minute floor for scheduler jitter and a
 * 15-minute ceiling: a daily rollup's newest point is a day's average, and
 * that is never "live".
 */
export function isFresh(points: MetricPoint[], now: number): boolean {
  const tail = points[points.length - 1];
  if (!tail || tail.v == null) return false;
  const step = medianStep(points) ?? 60_000;
  const limit = Math.min(Math.max(step * 2.5, 180_000), 900_000);
  return now - tail.t <= limit && tail.t <= now + 60_000;
}

/** A vertical wash in the series hue, fading to transparent at the baseline. */
export function areaGradient(color: string, alpha: number) {
  return {
    type: 'linear' as const,
    x: 0,
    y: 0,
    x2: 0,
    y2: 1,
    colorStops: [
      { offset: 0, color: withAlpha(color, alpha) },
      { offset: 1, color: withAlpha(color, 0) },
    ],
  };
}

export interface LineInput {
  name: string;
  color: string;
  points: MetricPoint[];
  /** The area wash under the line - for a single series or a stack only. */
  area?: boolean;
  /** Predictions draw dashed: they must not pass for measurements. */
  dashed?: boolean;
  /** A past window draws dotted and dimmed: measured, but not of this window. */
  past?: boolean;
  /** Stack id; stacked series always add the value beneath (dBm floors are negative). */
  stack?: string;
  /** Mark the newest point - only when the caller verified it is fresh. */
  endDot?: boolean;
}

/**
 * One line series in the house style.
 *
 * Gaps are inserted here, not trusted to the caller: a daily table that has
 * no row for a day it never heard from arrives as two neighbouring points,
 * and a line drawn across them claims a measurement nobody made.
 * `connectNulls: false` then breaks the line AND the area at every null, and
 * monotone smoothing (smoothMonotone 'x') keeps every curve inside the
 * range of the two samples it joins.
 */
export function lineSeries(theme: ChartTheme, s: LineInput) {
  const points = insertGaps(s.points);
  const dense = points.length > DENSE;
  const glow = dense || s.past ? 0 : theme.glow;
  const last = s.endDot ? summarizeSeries(points).last : null;
  const tail = points[points.length - 1];
  return {
    name: s.name,
    type: 'line' as const,
    // [timestamp, value]; null stays null, so a gap is a break, not a drop to zero.
    data: points.map((p) => [p.t, p.v]),
    connectNulls: false,
    showSymbol: false,
    symbol: 'circle',
    symbolSize: 8,
    smooth: dense ? false : 0.3,
    smoothMonotone: 'x' as const,
    // LTTB keeps the extremes; 'average' would smooth away exactly the spike
    // the operator opened the chart for.
    sampling: 'lttb' as const,
    ...(s.stack ? { stack: s.stack, stackStrategy: 'all' as const } : {}),
    lineStyle: {
      width: 2,
      color: s.color,
      cap: 'round' as const,
      join: 'round' as const,
      opacity: s.past ? 0.55 : 1,
      type: s.past ? ('dotted' as const) : s.dashed ? ('dashed' as const) : ('solid' as const),
      shadowBlur: glow,
      shadowColor: glow ? withAlpha(s.color, 0.55) : 'transparent',
    },
    // The hovered point: series colour inside a 2 px ring of the card colour,
    // so it stays legible where it crosses another line.
    itemStyle: { color: s.color, borderColor: theme.surface, borderWidth: 2 },
    areaStyle: s.area && !s.dashed && !s.past ? { color: areaGradient(s.color, theme.fill) } : undefined,
    markPoint:
      last && tail === last && !s.stack
        ? {
            silent: true,
            animation: false,
            symbol: 'circle',
            symbolSize: 9,
            label: { show: false },
            itemStyle: {
              color: s.color,
              borderColor: theme.surface,
              borderWidth: 2,
              shadowBlur: theme.glow * 1.5,
              shadowColor: theme.glow ? s.color : 'transparent',
            },
            data: [{ coord: [last.t, last.v] }],
          }
        : undefined,
  };
}

const axisText = (theme: ChartTheme) => ({ color: theme.textMuted, fontSize: 10, fontFamily: theme.fontMono });

/**
 * The time axis and THE value axis. There is exactly one y-axis on every
 * chart in the app: two scales on one plot invent a correlation out of where
 * the two ranges happen to line up.
 */
export function timeAxes(
  theme: ChartTheme,
  { unit, yMin, yMax, locale }: { unit: string; yMin?: number | null; yMax?: number | null; locale: string }
) {
  return {
    xAxis: {
      type: 'time' as const,
      axisLine: { lineStyle: { color: theme.grid } },
      axisTick: { show: false },
      axisLabel: { ...axisText(theme), hideOverlap: true },
      splitLine: { show: false },
    },
    yAxis: valueAxis(theme, { unit, yMin, yMax, locale }),
  };
}

export function valueAxis(
  theme: ChartTheme,
  { unit, yMin, yMax, locale }: { unit: string; yMin?: number | null; yMax?: number | null; locale: string }
) {
  return {
    type: 'value' as const,
    // undefined = measured from zero (a share, a rate, a count); null lets the
    // range follow the data, which is the only way to draw negative dBm or a
    // temperature between 45 and 52 degrees as more than a flat line.
    min: yMin === undefined ? 0 : (yMin ?? undefined),
    max: yMax ?? undefined,
    scale: yMin === null,
    axisLine: { show: false },
    axisTick: { show: false },
    // The unit belongs on the axis; a percentage carries it on every tick.
    name: unit && unit !== '%' ? unit : undefined,
    nameLocation: 'end' as const,
    nameGap: 8,
    nameTextStyle: { ...axisText(theme), align: 'left' as const },
    axisLabel: {
      ...axisText(theme),
      formatter: (value: number) => `${compact(value, locale)}${unit === '%' ? ' %' : ''}`,
    },
    splitLine: { lineStyle: { color: theme.grid, width: 1, type: 'solid' as const } },
  };
}

/** The tooltip card: the same rounded box on every chart, values in mono. */
export function tooltipBase(theme: ChartTheme) {
  return {
    backgroundColor: theme.tooltipBg,
    borderColor: theme.tooltipBorder,
    borderWidth: 1,
    padding: [8, 12],
    textStyle: { color: theme.text, fontSize: 12, fontFamily: theme.fontSans },
    extraCssText: 'border-radius:10px;',
    // Rendered into <body> so a card's overflow never clips it.
    appendToBody: true,
  };
}

/** The crosshair: a vertical hairline that snaps to the nearest sample. */
export function crosshair(theme: ChartTheme, locale: string) {
  return {
    type: 'line' as const,
    // Solid: ECharts dashes it by default, and a dashed rule reads as a threshold.
    lineStyle: { color: theme.textMuted, width: 1, opacity: 0.5, type: 'solid' as const },
    label: {
      formatter: (p: { value: number | string }) => formatChartTime(Number(p.value), locale),
      backgroundColor: theme.tooltipBg,
      color: theme.text,
      borderColor: theme.tooltipBorder,
      borderWidth: 1,
      fontFamily: theme.fontMono,
      fontSize: 10,
    },
  };
}

/** The muted first line of a tooltip - usually the hovered moment. */
export function tooltipHeader(theme: ChartTheme, text: string): string {
  return `<div style="color:${theme.textMuted};font-family:${theme.fontMono};font-size:11px;margin-bottom:4px">${escapeHtml(text)}</div>`;
}

/**
 * One series in a tooltip: a short line key in the series colour, the value
 * (strong, mono) and then the name. The value leads because the reader
 * already knows the series and wants the number. Names are escaped - they
 * come from the API and a formatter's return value is inserted as HTML.
 */
export function tooltipRow(theme: ChartTheme, color: string, name: string, value: string): string {
  return (
    `<div style="display:flex;align-items:center;gap:8px;line-height:20px">` +
    `<span style="display:inline-block;width:10px;height:2px;border-radius:1px;background:${color}"></span>` +
    `<b style="font-family:${theme.fontMono};font-variant-numeric:tabular-nums;font-weight:600">${escapeHtml(value)}</b>` +
    `<span style="color:${theme.textMuted}">${escapeHtml(name)}</span></div>`
  );
}
