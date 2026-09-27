import * as React from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { Chart, type ChartHandle } from './chart';
import { ChartMenu, type MetricChartActions } from './chart-menu';
import { escapeHtml, withAlpha } from './color';
import {
  bandCaption,
  crosshair,
  displayData,
  formatChartTime,
  formatChartValue,
  isFresh,
  isIntegerCount,
  isZeroBased,
  lineSeries,
  measuredCount,
  NO_VALUE,
  silentTail,
  summarizeSeries,
  SYMBOL_BELOW,
  timeAxes,
  tooltipBase,
  tooltipHeader,
  tooltipRow,
  type ChartTheme,
} from './chart-style';
import { useChartTheme, useNow, usePrefersReducedMotion } from './use-chart-theme';
import type { ChartData, ChartEvent } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { insertGaps, medianStep } from '@/lib/series-gaps';
import { cn } from '@/lib/utils';

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/**
 * Chart of one metric over time (one or more series sharing ONE axis).
 *
 * Series colours come from the `--chart-*` tokens, so CPU is the same green
 * here, in its sparkline and in its heatmap: the user recognises a metric by
 * its colour, not by reading the legend. Everything that makes the look -
 * line, wash, glow, axes, tooltip card - comes from chart-style.ts.
 *
 * What it says is held to the data: the time axis is pinned to the window,
 * with the silent end shaded "bez dat od HH:MM"; a sparse series gets a dot
 * per sample, a count or a state steps; the wash only sits on an axis that
 * starts at zero; throughput is in Mbit/s; numbers are in the page's language.
 *
 * There is no canvas toolbox. PNG, CSV and reset sit in a "⋯" menu: in the
 * card header when a ChartCard passes `actionsRef`, otherwise in the chart's
 * own top-right corner.
 */
export function MetricChart({
  data: raw,
  height = 200,
  group,
  onPickTime,
  onZoom,
  minimap = false,
  legend = true,
  bars = false,
  actionsRef,
}: {
  data: ChartData;
  height?: number;
  group?: string;
  /** A click into the chart returns the time in ms - see Chart.onPickTime. */
  onPickTime?: (timestampMs: number) => void;
  /** The window the user zoomed to - see Chart.onZoom. */
  onZoom?: (window: { from: number; to: number } | null) => void;
  /**
   * A slider strip under the chart with the whole series drawn small - drag
   * to narrow the view. The inside zoom (drag / ctrl+wheel) works either way;
   * the slider adds a visible "where am I within the period" anchor, which
   * matters on 30d+ views where a spike is three pixels wide.
   */
  minimap?: boolean;
  /**
   * The legend row above the plot for two or more series. Off when the
   * caller already names every series beside its numbers (ChartCard).
   */
  legend?: boolean;
  /**
   * Columns instead of lines, for per-day totals: a day's total is one
   * quantity, and a curve between two totals would claim the hours in
   * between. A day without a row simply has no column.
   */
  bars?: boolean;
  /**
   * Set by a card that draws the menu and the band caption in its own header;
   * the chart then draws neither itself.
   */
  actionsRef?: React.Ref<MetricChartActions>;
}) {
  const theme = useChartTheme();
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const chartRef = React.useRef<ChartHandle>(null);
  // Throughput in Mbit/s rather than "125 000 KB/s", and no two measured
  // series in one hue - for the axis, the tooltip and the export alike.
  const data = React.useMemo(() => displayData(raw), [raw]);
  const now = useNow();
  // The newest sample of the first series decides the end dot; it is a
  // boolean in the option's dependencies, so the 30-second clock only
  // rebuilds the chart when freshness actually flips.
  const fresh = !bars && isFresh(data.series[0]?.points ?? [], now);
  // Half a sample step: what the tooltip counts as "at this moment". Without
  // it an event three hours away would be attached to the hovered point.
  const tooltipWindow = React.useMemo(() => {
    const step = medianStep(data.series[0]?.points ?? []);
    return step == null ? 60_000 : Math.max(step / 2, 30_000);
  }, [data]);
  const totalPoints = React.useMemo(() => data.series.reduce((sum, s) => sum + s.points.length, 0), [data]);
  const reducedMotion = usePrefersReducedMotion();

  // CSV export: exactly the points the chart draws (including null as an
  // empty cell - a gap in measurement stays a gap in the export).
  // Keyed by timestamp, never by array index: gaps are marked per series, so
  // two series over the same period can differ in length and an index-paired
  // export silently attributes one series' value to another's moment.
  const exportCsv = React.useCallback(() => {
    const rows: string[] = ['time,' + data.series.map((s) => `"${s.label} (${s.unit})"`).join(',')];
    const byTime = data.series.map((s) => new Map(s.points.map((p) => [p.t, p.v])));
    const times = [...new Set(data.series.flatMap((s) => s.points.map((p) => p.t)))].sort((a, b) => a - b);
    times.forEach((tms) => {
      const cells = byTime.map((m) => {
        const v = m.get(tms);
        return v == null ? '' : String(v);
      });
      // A row where every series is empty is a gap marker, not a measurement.
      if (cells.every((c) => c === '')) return;
      rows.push(new Date(tms).toISOString() + ',' + cells.join(','));
    });
    const blob = new Blob(['﻿' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${data.id}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }, [data]);

  const actions = React.useMemo<MetricChartActions>(
    () => ({
      exportPng: () => chartRef.current?.exportPng(data.id, theme.surface),
      exportCsv,
      resetZoom: () => chartRef.current?.resetZoom(),
    }),
    [data.id, theme.surface, exportCsv]
  );
  React.useImperativeHandle(actionsRef, () => actions, [actions]);

  const option = React.useMemo<EChartsCoreOption>(() => {
    const unit = data.series[0]?.unit ?? '';
    const seriesColor = theme.series[data.series[0]?.tone ?? 'latency'];
    const axes = timeAxes(theme, { unit, yMin: data.yMin, yMax: data.yMax, locale, window: data.window });
    const zeroBased = isZeroBased(data);
    // Daily averages (90 d / 1 y carry a range) are never a step line - they are not counts.
    const daily = (data.range?.length ?? 0) > 0;
    const tail = silentTail(data);
    const clock = (ms: number) => new Date(ms).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });

    return {
      // The bezier and the entry animation are what makes thirteen 30-day
      // charts crawl, not the samples themselves.
      animation: !reducedMotion && totalPoints < 2000,
      animationDuration: 300,
      grid: { top: 28, right: 12, bottom: minimap ? 58 : 24, left: 44 },
      // Zoom: dragging inside the chart (inside); the "⋯" menu resets it.
      // Charts in a group zoom together (echarts.connect).
      dataZoom: [
        { type: 'inside', throttle: 50, zoomOnMouseWheel: 'ctrl', moveOnMouseWheel: false },
        ...(minimap
          ? [
              {
                type: 'slider' as const,
                height: 22,
                bottom: 6,
                borderColor: theme.grid,
                backgroundColor: 'transparent',
                fillerColor: withAlpha(seriesColor, 0.1),
                dataBackground: {
                  lineStyle: { color: withAlpha(seriesColor, 0.5), width: 1 },
                  areaStyle: { color: withAlpha(seriesColor, 0.12) },
                },
                selectedDataBackground: {
                  lineStyle: { color: seriesColor, width: 1 },
                  areaStyle: { color: withAlpha(seriesColor, 0.2) },
                },
                handleStyle: { color: theme.tooltipBg, borderColor: theme.textMuted },
                // The move handle is a full-width bar; at full strength it reads
                // louder than the data it sits above.
                moveHandleStyle: { color: theme.grid },
                emphasis: { moveHandleStyle: { color: theme.textMuted } },
                textStyle: { color: theme.textMuted, fontSize: 10, fontFamily: theme.fontMono },
              },
            ]
          : []),
      ],
      tooltip: {
        ...tooltipBase(theme),
        // Columns: the hovered column is the target. Lines: a crosshair that
        // snaps to the nearest sample and lists every series at that moment.
        trigger: bars ? 'item' : 'axis',
        axisPointer: bars ? undefined : crosshair(theme, locale),
        formatter: (params: unknown) =>
          tooltipHtml(params, { data, theme, locale, lang, unit, window: tooltipWindow, t, bars }),
      },
      xAxis: axes.xAxis,
      // A short chart gets fewer value labels: at 110 px the default five
      // steps stacked "9,5 / 9 / 8,5 / 8 …" on top of each other (V-03).
      yAxis: height < 160 ? { ...axes.yAxis, splitNumber: 2 } : axes.yAxis,
      series: bars
        ? data.series.map((s) => barSeries(theme, s.label, theme.series[s.tone], s.points))
        : [
            ...buildRangeBand(data.range, seriesColor),
            ...data.series.map((s, i) => ({
              ...lineSeries(theme, {
                name: s.label,
                color: theme.series[s.tone],
                points: s.points,
                // A wash per series is mud when two lines overlap - except when
                // they are stacked, where each band is its own share of the total.
                // And only from zero: a wash down to a floor taken from the data
                // turns jitter into a wall (charts-13).
                area: (data.series.length === 1 || data.stacked === true) && zeroBased,
                dashed: s.predicted,
                past: s.past,
                stack: data.stacked ? 'bk-total' : undefined,
                endDot: i === 0 && fresh && !s.predicted && !s.past,
                step: s.step === true || (!daily && isIntegerCount(s)),
                symbols: measuredCount(s.points) < SYMBOL_BELOW,
              }),
              // Bands, periods, events, notes and the silent tail belong to the
              // first series only - drawn twice they darken.
              ...(i === 0
                ? decorations(
                    data,
                    theme,
                    locale,
                    tail
                      ? {
                          ...tail,
                          label: t('chart.no_data_since', { time: clock(tail.from) }, `bez dat od ${clock(tail.from)}`),
                        }
                      : null
                  )
                : {}),
            })),
          ],
    };
  }, [data, theme, reducedMotion, minimap, t, locale, lang, tooltipWindow, totalPoints, fresh, bars, height]);

  const showLegend = legend && data.series.length > 1;
  const caption = actionsRef ? null : bandCaption(data, lang, t);

  return (
    <div className="relative">
      {!actionsRef && (
        <div className="absolute top-0 right-0 z-10">
          <ChartMenu actions={actions} />
        </div>
      )}
      {showLegend && (
        // An HTML row, not the canvas legend: it wraps on a phone instead of
        // running into the menu, and a screen reader can read it.
        <ul className={cn('text-muted-foreground mb-1 flex flex-wrap gap-x-4 gap-y-1 text-2xs', !actionsRef && 'pr-9')}>
          {data.series.map((s) => (
            <li key={s.key} className="flex items-center gap-1.5">
              <span
                aria-hidden="true"
                className="h-0.5 w-3 rounded-full"
                style={{ backgroundColor: theme.series[s.tone], opacity: s.past ? 0.55 : 1 }}
              />
              {s.label}
            </li>
          ))}
        </ul>
      )}
      <Chart
        // A theme switch needs new canvas colours — a remount is the most
        // reliable way without leftovers of the old theme.
        key={theme.key}
        handleRef={chartRef}
        option={option}
        height={height}
        group={group}
        ariaLabel={t('chart.aria_over_time', { title: data.title }, `${data.title} v čase`)}
        summary={describe(data, t, lang)}
        table={bars ? tableOf(data, locale, lang) : undefined}
        onPickTime={onPickTime}
        onZoom={onZoom}
      />
      {caption && <p className="text-muted-foreground mt-1 text-2xs">{caption}</p>}
    </div>
  );
}

/**
 * A column series for per-day totals: at most 12 px per column so two
 * series stay within the 24 px mark cap, a 4 px rounded data end and a
 * square foot on the baseline, the wash running top to bottom.
 */
function barSeries(theme: ChartTheme, name: string, color: string, points: ChartData['series'][number]['points']) {
  return {
    name,
    type: 'bar' as const,
    data: points.map((p) => [p.t, p.v]),
    barMaxWidth: 12,
    barGap: '15%',
    itemStyle: {
      borderRadius: [4, 4, 0, 0],
      color: {
        type: 'linear' as const,
        x: 0,
        y: 0,
        x2: 0,
        y2: 1,
        colorStops: [
          { offset: 0, color },
          { offset: 1, color: withAlpha(color, 0.45) },
        ],
      },
      shadowBlur: theme.glow,
      shadowColor: theme.glow ? withAlpha(color, 0.35) : 'transparent',
    },
    // The hovered column answers by lifting to full strength.
    emphasis: { itemStyle: { color } },
  };
}

/** One tooltip for lines and columns alike: the moment, every series, then what happened there. */
function tooltipHtml(
  params: unknown,
  ctx: {
    data: ChartData;
    theme: ChartTheme;
    locale: string;
    lang: string;
    unit: string;
    window: number;
    t: TranslateFn;
    bars: boolean;
  }
): string {
  const { data, theme, locale, lang, unit, window, t } = ctx;
  const all = (Array.isArray(params) ? params : [params]) as {
    seriesName?: string;
    value?: [number, number | null];
    color?: unknown;
    axisValue?: number;
  }[];
  const rows = all.filter((r) => !BAND_SERIES.has(String(r.seriesName ?? '')));
  const first = rows[0];
  const at = Number(first?.axisValue ?? (Array.isArray(first?.value) ? first.value[0] : NaN));
  // A per-day column is a day, not a minute; a column that stands for one
  // moment (a single speed test) keeps its time.
  const midnight = Number.isFinite(at) && new Date(at).getHours() === 0 && new Date(at).getMinutes() === 0;
  const header = Number.isFinite(at)
    ? ctx.bars && midnight
      ? new Date(at).toLocaleDateString(locale, { dateStyle: 'medium' })
      : formatChartTime(at, locale)
    : '';
  const colorOf = (name: string) =>
    theme.series[data.series.find((s) => s.label === name)?.tone ?? 'latency'] ?? theme.textMuted;
  const lines = rows.map((r) => {
    const raw = Array.isArray(r.value) ? r.value[1] : null;
    const name = String(r.seriesName ?? '');
    return tooltipRow(theme, colorOf(name), name, formatChartValue(raw, unit, lang));
  });
  // Everything that HAPPENED within half a sample of this moment.
  const near = [
    ...(data.events ?? []).map((e) => ({ ...e, kind: 'event' as const })),
    ...(data.annotations ?? []).map((a) => ({ ...a, kind: 'note' as const })),
  ].filter((e) => Number.isFinite(at) && Math.abs(e.t - at) <= window);
  const marks = near.map((e) => {
    const color = e.kind === 'note' ? theme.annotation : theme.textMuted;
    return `<div style="color:${color};margin-top:2px">• ${escapeHtml(e.label)}</div>`;
  });
  const spread = rangeAt(data.range, at, window);
  const spreadLine =
    spread && (spread.min != null || spread.max != null)
      ? `<div style="color:${theme.textMuted};margin-top:2px">${escapeHtml(t('chart.tooltip_range', 'Rozsah dne'))}: ` +
        `<span style="font-family:${theme.fontMono}">${escapeHtml(formatChartValue(spread.min, '', lang))}–${escapeHtml(formatChartValue(spread.max, unit, lang))}</span>` +
        (spread.samples != null
          ? ` · ${escapeHtml(t('chart.tooltip_samples', { n: spread.samples }, `${spread.samples} měření`))}`
          : '') +
        '</div>'
      : '';
  return [tooltipHeader(theme, header), ...lines, spreadLine, ...marks].filter(Boolean).join('');
}

/**
 * Names of the two helper series that draw the spread. They carry no readable
 * information of their own - the tooltip prints the range as one line instead
 * of listing "min" and "span" as if they were measurements.
 */
const BAND_SERIES = new Set(['__range_floor', '__range_span']);

/**
 * The day's min-max spread as a soft band under the average line.
 *
 * ECharts draws a band as two stacked series: an invisible floor at the
 * minimum and a filled one the height of (max - min). Both are silent, out of
 * the legend and out of the tooltip.
 */
function buildRangeBand(range: ChartData['range'], color: string) {
  const usable = (range ?? []).filter((r) => r.min != null && r.max != null);
  if (usable.length === 0) return [];

  // Days nobody measured have no row at all, so the band would be drawn
  // straight across them - the exact fabrication the line was fixed for. The
  // same cadence check marks the holes, and connectNulls:false splits the area.
  const floor = insertGaps(usable.map((r) => ({ t: r.t, v: r.min as number })));
  const spanByTime = new Map(usable.map((r) => [r.t, (r.max as number) - (r.min as number)]));

  const common = {
    type: 'line' as const,
    stack: 'bk-range',
    // ECharts' default 'samesign' adds the value beneath only when it has the
    // same sign. A day's minimum is negative on every dBm and dB metric, so
    // the floor was dropped and the band floated up from zero while the line
    // sat at -100. 'all' always adds it.
    stackStrategy: 'all' as const,
    silent: true,
    symbol: 'none' as const,
    connectNulls: false,
    z: 1,
  };

  return [
    {
      ...common,
      name: '__range_floor',
      lineStyle: { opacity: 0 },
      areaStyle: { opacity: 0 },
      data: floor.map((p) => [p.t, p.v]),
    },
    {
      ...common,
      name: '__range_span',
      lineStyle: { opacity: 0 },
      areaStyle: { color: withAlpha(color, 0.14) },
      data: floor.map((p) => [p.t, p.v == null ? null : (spanByTime.get(p.t) ?? null)]),
    },
  ];
}

/** The spread recorded for the day the hovered point belongs to. */
function rangeAt(range: ChartData['range'], at: number, window: number) {
  if (!range || !Number.isFinite(at)) return null;
  return range.find((r) => Math.abs(r.t - at) <= window) ?? null;
}

/**
 * Threshold bands, shaded periods, events and notes - everything drawn on top
 * of the first series that is not the measurement itself.
 */
function decorations(
  data: ChartData,
  theme: ChartTheme,
  locale: string,
  tail: { from: number; to: number; label: string } | null
) {
  const { events, annotations, bands, periods } = data;
  // Events (measured facts) and notes (human claims) share one markLine -
  // ECharts allows a single markLine per series, so the styling rides on each
  // item instead.
  //
  // The two are told apart by colour AND line style: muted dotted for an
  // event, the annotation hue solid for a note (the pair clears CVD ΔE 12.5,
  // so the style is a second channel rather than the only one). Against the
  // data itself the separator is form - a vertical rule versus a curve.
  // An outage or a crossed limit is drawn in the status colour for a verdict,
  // because that is what it is.
  const markLineData = [
    ...(events ?? []).map((e: ChartEvent) => ({
      xAxis: e.t,
      name: `${formatChartTime(e.t, locale)} — ${e.label}`,
      ...(e.severity === 'alert'
        ? {
            lineStyle: { color: theme.alert, type: 'solid' as const, width: 1.5 },
            emphasis: { lineStyle: { width: 2.4 } },
          }
        : {}),
    })),
    ...(annotations ?? []).map((a) => ({
      xAxis: a.t,
      name: `${formatChartTime(a.t, locale)} — ${a.label}`,
      lineStyle: { color: theme.annotation, type: 'solid' as const, width: 1.4 },
      emphasis: { lineStyle: { color: theme.annotation, width: 2.2 } },
    })),
  ];
  return {
    // Threshold bands as horizontal areas - a single line at the critical
    // limit does not say whether the value sits just below it or far away.
    // Time ranges (the router on its LTE backup) ride the same markArea as
    // vertical shading: a band spans values, a period spans time.
    markArea:
      bands?.length || periods?.length || tail
        ? {
            silent: true,
            itemStyle: { opacity: 1 },
            label: {
              show: true,
              position: 'insideTopLeft' as const,
              color: theme.textMuted,
              fontSize: 10,
            },
            data: [
              // The band names are in the caption beside the chart (bandCaption),
              // not on the plot, where both labels sat on the line (charts-20).
              ...(bands ?? []).map((b) => [
                { yAxis: b.from, itemStyle: { color: theme.band[b.tone] }, name: b.label, label: { show: false } },
                { yAxis: b.to },
              ]),
              // The caption sits on the first period only: with several short
              // outages one caption per band piled up unreadably.
              ...(periods ?? []).map((p, idx) => [
                {
                  xAxis: p.from,
                  itemStyle: { color: withAlpha(theme.textMuted, 0.18) },
                  name: p.label,
                  label: { show: idx === 0 },
                },
                { xAxis: p.to },
              ]),
              // The silent end of the window: shaded and captioned, so an agent
              // that stopped reporting never looks like a quiet, healthy line.
              ...(tail
                ? [
                    [
                      {
                        xAxis: tail.from,
                        itemStyle: { color: withAlpha(theme.textMuted, 0.12) },
                        name: tail.label,
                        label: { show: true, position: 'insideTop' as const },
                      },
                      { xAxis: tail.to },
                    ],
                  ]
                : []),
            ],
          }
        : undefined,
    // silent: false - hovering the line shows WHAT happened at that moment.
    markLine: markLineData.length
      ? {
          // Symbols off for every line: ECharts ignores a per-item `symbol` on
          // an xAxis marker, so leaving this out draws its default arrows.
          symbol: 'none',
          silent: false,
          lineStyle: { color: theme.textMuted, type: 'dotted' as const, width: 1.2 },
          label: { show: false },
          emphasis: { lineStyle: { width: 2 } },
          tooltip: {
            // ECharts inserts this via innerHTML - the name carries a
            // user-written annotation note and MUST be escaped, or a note like
            // `<img src=x onerror=…>` is stored XSS for every chart viewer.
            formatter: (params: { name?: string }) => escapeHtml(params.name ?? ''),
          },
          data: markLineData,
        }
      : undefined,
  };
}

/**
 * Text summary for screen readers.
 *
 * A canvas is a blank area for assistive tech — without this description the
 * chart would not exist for a blind user. Min/max/avg over the MEASURED
 * points, and the number of breaks, because the holes in the line are
 * invisible to a screen reader and a chart full of gaps otherwise reads as a
 * full one.
 */
function describe(data: ChartData, t: TranslateFn, lang: string): string {
  const parts = data.series.map((s) => {
    const sum = summarizeSeries(s.points);
    if (sum.count === 0) {
      return t('chart.summary_no_data', { label: s.label }, `${s.label}: žádná data`);
    }
    const min = formatChartValue(sum.min, '', lang);
    const max = formatChartValue(sum.max, '', lang);
    const avg = formatChartValue(sum.avg, '', lang);
    const stats = t(
      'chart.summary_stats',
      { label: s.label, min, max, avg, unit: s.unit },
      `${s.label}: minimum ${min} ${s.unit}, maximum ${max} ${s.unit}, průměr ${avg} ${s.unit}`
    );
    return sum.gaps > 0
      ? `${stats}. ${t('chart.summary_gaps', { n: sum.gaps }, `${sum.gaps} přerušení měření`)}`
      : stats;
  });

  return `${data.title}. ${parts.join('. ')}.`;
}

/** Per-day totals as rows, one column per series - a missing day reads as a dash. */
function tableOf(data: ChartData, locale: string, lang: string) {
  const times = [...new Set(data.series.flatMap((s) => s.points.map((p) => p.t)))].sort((a, b) => a - b);
  const byTime = data.series.map((s) => new Map(s.points.map((p) => [p.t, p.v])));
  return {
    columns: ['', ...data.series.map((s) => `${s.label} (${s.unit})`)],
    rows: times.map((tm) => [
      new Date(tm).getHours() === 0 && new Date(tm).getMinutes() === 0
        ? new Date(tm).toLocaleDateString(locale)
        : formatChartTime(tm, locale),
      ...byTime.map((m) => {
        const v = m.get(tm);
        return v == null ? NO_VALUE : formatChartValue(v, '', lang);
      }),
    ]),
  };
}
