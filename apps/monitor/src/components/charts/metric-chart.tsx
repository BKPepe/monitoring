import * as React from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { Chart, echarts } from './chart';
import { escapeHtml, withAlpha } from './color';
import { useChartTheme, usePrefersReducedMotion } from './use-chart-theme';
import type { ChartData, ChartEvent, MetricSeries } from '@/api/types';
import type { ChartTheme } from './use-chart-theme';
import { useLanguage } from '@/context/language-context';
import { medianStep } from '@/lib/series-gaps';

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/**
 * Chart of one metric over time (one or more series sharing axes).
 *
 * Series colours come from the `--chart-*` tokens, so CPU is green both here
 * and in the health-card sparkline. That is deliberate: the user should
 * recognise a metric by its colour, not by reading the legend.
 */
export function MetricChart({
  data,
  height = 200,
  group,
  onPickTime,
  minimap = false,
}: {
  data: ChartData;
  height?: number;
  group?: string;
  /** A click into the chart returns the time in ms - see Chart.onPickTime. */
  onPickTime?: (timestampMs: number) => void;
  /**
   * A slider strip under the chart with the whole series drawn small - drag
   * to narrow the view. The inside zoom (drag / ctrl+wheel) works either way;
   * the slider adds a visible "where am I within the period" anchor, which
   * matters on 30d+ views where a spike is three pixels wide.
   */
  minimap?: boolean;
}) {
  const theme = useChartTheme();
  const { t, lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
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
  const exportCsv = React.useCallback(() => {
    const rows: string[] = ['time,' + data.series.map((s) => `"${s.label} (${s.unit})"`).join(',')];
    const times = data.series[0]?.points.map((p) => p.t) ?? [];
    times.forEach((tms, i) => {
      const cells = data.series.map((s) => {
        const v = s.points[i]?.v;
        return v == null ? '' : String(v);
      });
      rows.push(new Date(tms).toISOString() + ',' + cells.join(','));
    });
    const blob = new Blob(['\ufeff' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${data.id}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }, [data]);

  const option = React.useMemo<EChartsCoreOption>(() => {
    const unit = data.series[0]?.unit ?? '';
    const seriesColor = theme.series[data.series[0]?.tone ?? 'latency'];

    return {
      // A 30-day chart is tens of thousands of points and up to thirteen of
      // them are drawn at once on the overview: the bezier and the entry
      // animation are what makes that crawl, not the samples themselves.
      animation: !reducedMotion && totalPoints < 2000,
      animationDuration: 300,
      grid: { top: 28, right: 12, bottom: minimap ? 58 : 24, left: 44 },
      // Zoom: dragging inside the chart (inside) and area selection (toolbox lens).
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
                textStyle: { color: theme.textMuted, fontSize: 10 },
              },
            ]
          : []),
      ],
      toolbox: {
        show: true,
        top: 0,
        right: 0,
        itemSize: 13,
        iconStyle: { borderColor: theme.textMuted },
        emphasis: { iconStyle: { borderColor: theme.text } },
        feature: {
          // ECharts draws these tooltips itself, so they need the translated
          // text handed in - they were the last hardcoded Czech in the app and
          // showed up untranslated in the English UI.
          dataZoom: {
            yAxisIndex: 'none',
            title: { zoom: t('chart.tool_zoom', 'Zoom výběrem'), back: t('chart.tool_zoom_back', 'Zpět') },
          },
          restore: { title: t('chart.tool_restore', 'Obnovit') },
          saveAsImage: { title: t('chart.tool_png', 'Uložit PNG'), name: data.id, backgroundColor: theme.tooltipBg },
          myCsv: {
            show: true,
            title: t('chart.tool_csv', 'Export CSV'),
            // A document-with-arrow icon (a simple SVG path, so no icon
            // package needs dragging into the canvas).
            icon: 'path://M4 2h10l6 6v14H4V2z M14 2v6h6 M9 13h6 M12 10v6',
            onclick: exportCsv,
          },
        },
      },
      tooltip: {
        trigger: 'axis',
        backgroundColor: theme.tooltipBg,
        borderColor: theme.tooltipBorder,
        borderWidth: 1,
        textStyle: { color: theme.text, fontSize: 12 },
        axisPointer: {
          type: 'line',
          lineStyle: { color: theme.grid },
          label: {
            formatter: (p: { value: number | string }) => formatTime(Number(p.value), locale),
            backgroundColor: theme.tooltipBg,
            color: theme.text,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
          },
        },
        // The tooltip is the only place a chart can say what happened at this
        // moment: the value alone left the outage and note markers readable
        // only by hitting a sub-pixel vertical line with the mouse.
        appendToBody: true,
        formatter: (params: unknown) => {
          const all = Array.isArray(params) ? params : [params];
          const rows = all.filter((r) => !BAND_SERIES.has(String((r as { seriesName?: string }).seriesName ?? '')));
          const first = rows[0] as { axisValue?: number } | undefined;
          const at = Number(first?.axisValue ?? NaN);
          const header = Number.isFinite(at) ? formatTime(at, locale) : '';
          const lines = rows.map((row) => {
            const r = row as { seriesName?: string; value?: [number, number | null]; color?: string };
            const raw = Array.isArray(r.value) ? r.value[1] : null;
            const shown = raw == null ? '—' : `${formatValue(raw)} ${unit}`.trim();
            const dot = `<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:${r.color ?? theme.textMuted};margin-right:6px"></span>`;
            return `<div>${dot}${escapeHtml(String(r.seriesName ?? ''))}: <b>${escapeHtml(shown)}</b></div>`;
          });
          // Everything that HAPPENED within half a sample of this moment.
          const near = [
            ...(data.events ?? []).map((e) => ({ ...e, kind: 'event' as const })),
            ...(data.annotations ?? []).map((a) => ({ ...a, kind: 'note' as const })),
          ].filter((e) => Number.isFinite(at) && Math.abs(e.t - at) <= tooltipWindow);
          const marks = near.map((e) => {
            const color = e.kind === 'note' ? theme.annotation : theme.textMuted;
            return `<div style="color:${color};margin-top:2px">• ${escapeHtml(e.label)}</div>`;
          });
          const spread = rangeAt(data.range, at, tooltipWindow);
          const spreadLine =
            spread && (spread.min != null || spread.max != null)
              ? `<div style="color:${theme.textMuted};margin-top:2px">${escapeHtml(
                  t('chart.tooltip_range', 'Rozsah dne')
                )}: ${spread.min == null ? '—' : formatValue(spread.min)}–${
                  spread.max == null ? '—' : formatValue(spread.max)
                } ${escapeHtml(unit)}${
                  spread.samples != null
                    ? ` · ${escapeHtml(t('chart.tooltip_samples', { n: spread.samples }, `${spread.samples} měření`))}`
                    : ''
                }</div>`
              : '';
          return [
            `<div style="color:${theme.textMuted};margin-bottom:2px">${escapeHtml(header)}</div>`,
            ...lines,
            spreadLine,
            ...marks,
          ]
            .filter(Boolean)
            .join('');
        },
      },
      legend:
        data.series.length > 1
          ? {
              data: data.series.map((s) => s.label),
              top: 0,
              right: 0,
              icon: 'roundRect',
              itemWidth: 8,
              itemHeight: 8,
              textStyle: { color: theme.textMuted, fontSize: 11 },
            }
          : undefined,
      xAxis: {
        type: 'time',
        axisLine: { lineStyle: { color: theme.grid } },
        axisTick: { show: false },
        axisLabel: { color: theme.textMuted, fontSize: 11, hideOverlap: true },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        min: data.yMin === undefined ? 0 : (data.yMin ?? undefined),
        max: data.yMax ?? undefined,
        // Without an explicit floor let ECharts pick a range that shows the
        // variation instead of an axis that starts at zero by decree.
        scale: data.yMin === null,
        axisLine: { show: false },
        axisTick: { show: false },
        // The unit belongs on the axis, and 12 000 belongs there as 12 k. A
        // net chart used to read 0 / 2000 / 4000 with no unit anywhere.
        name: unit && unit !== '%' ? unit : undefined,
        nameLocation: 'end' as const,
        nameGap: 8,
        nameTextStyle: { color: theme.textMuted, fontSize: 10, align: 'left' as const },
        axisLabel: {
          color: theme.textMuted,
          fontSize: 11,
          formatter: (value: number) => `${compact(value, locale)}${unit === '%' ? ' %' : ''}`,
        },
        splitLine: { lineStyle: { color: theme.grid } },
      },
      series: [
        ...buildRangeBand(data.range, theme.series[data.series[0]?.tone ?? 'latency']),
        ...data.series.map((s, i) =>
          buildSeries(
            s,
            theme.series[s.tone],
            data.series.length,
            i === 0 ? data.events : undefined,
            theme.textMuted,
            // Bands belong to the first series only - drawn twice they darken.
            i === 0 ? data.bands : undefined,
            theme,
            i === 0 ? data.annotations : undefined,
            i === 0 ? data.periods : undefined,
            locale
          )
        ),
      ],
    };
  }, [data, theme, reducedMotion, exportCsv, minimap, t, locale, tooltipWindow, totalPoints]);

  return (
    <Chart
      // A theme switch needs new canvas colours — a remount is the most
      // reliable way without leftovers of the old theme.
      key={theme.key}
      option={option}
      height={height}
      group={group}
      ariaLabel={t('chart.aria_over_time', { title: data.title }, `${data.title} v čase`)}
      summary={describe(data, t)}
      onPickTime={onPickTime}
    />
  );
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
  return [
    {
      name: '__range_floor',
      type: 'line' as const,
      stack: 'bk-range',
      silent: true,
      symbol: 'none' as const,
      lineStyle: { opacity: 0 },
      areaStyle: { opacity: 0 },
      z: 1,
      data: usable.map((r) => [r.t, r.min as number]),
    },
    {
      name: '__range_span',
      type: 'line' as const,
      stack: 'bk-range',
      silent: true,
      symbol: 'none' as const,
      lineStyle: { opacity: 0 },
      areaStyle: { color: withAlpha(color, 0.16) },
      z: 1,
      data: usable.map((r) => [r.t, (r.max as number) - (r.min as number)]),
    },
  ];
}

/** Locale-formatted timestamp, the same shape every other time in the app uses. */
function formatTime(ms: number, locale: string): string {
  return new Date(ms).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' });
}

/** Two decimals at most, and no trailing zeros - measurements, not accounting. */
function formatValue(v: number): string {
  return String(Math.round(v * 100) / 100);
}

/** 12 000 -> 12 k. Keeps a long axis label from eating the plot area. */
function compact(value: number, locale: string): string {
  if (!Number.isFinite(value)) return '—';
  if (Math.abs(value) < 1000) return String(Math.round(value * 100) / 100);
  return new Intl.NumberFormat(locale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

/** The spread recorded for the day the hovered point belongs to. */
function rangeAt(range: ChartData['range'], at: number, window: number) {
  if (!range || !Number.isFinite(at)) return null;
  return range.find((r) => Math.abs(r.t - at) <= window) ?? null;
}

function buildSeries(
  s: MetricSeries,
  color: string,
  seriesCount: number,
  events: ChartEvent[] | undefined,
  eventColor: string,
  bands: ChartData['bands'],
  theme: ChartTheme,
  annotations?: ChartEvent[],
  periods?: ChartData['periods'],
  locale = 'cs-CZ'
) {
  const seriesPointCount = s.points.length;
  // Events (measured facts) and notes (human claims) share one markLine -
  // ECharts allows a single markLine per series, so the styling rides on each
  // item instead.
  //
  // The two are told apart by colour AND line style: muted dotted for an
  // event, the annotation hue solid for a note (the pair clears CVD ΔE 12.5,
  // so the style is a second channel rather than the only one). Against the
  // data itself the separator is form - a vertical rule versus a curve; no hue
  // could do that job alone, because the six series colours cover nearly the
  // whole wheel.
  const markLineData = [
    ...(events ?? []).map((e) => ({
      xAxis: e.t,
      name: `${formatTime(e.t, locale)} — ${e.label}`,
    })),
    ...(annotations ?? []).map((a) => ({
      xAxis: a.t,
      name: `${formatTime(a.t, locale)} — ${a.label}`,
      lineStyle: { color: theme.annotation, type: 'solid' as const, width: 1.4 },
      emphasis: { lineStyle: { color: theme.annotation, width: 2.2 } },
    })),
  ];
  return {
    // Threshold bands as horizontal areas. A single line at the critical limit
    // does not say whether the current value sits just below it or far away.
    // ...and time ranges (the router on its LTE backup) as vertical shading in
    // the same markArea - ECharts allows one per series, and the two kinds do
    // not collide: a band spans values, a period spans time.
    markArea:
      bands?.length || periods?.length
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
              ...(bands ?? []).map((b) => [
                { yAxis: b.from, itemStyle: { color: theme.band[b.tone] }, name: b.label },
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
            ],
          }
        : undefined,
    name: s.label,
    type: 'line' as const,
    // [timestamp, value] — null stays null, so a data gap draws as a
    // break, not a drop to zero.
    data: s.points.map((p) => [p.t, p.v]),
    showSymbol: false,
    smooth: seriesPointCount > 2000 ? false : 0.25,
    // Predictions draw dashed — they must not be mistakable for measurements.
    lineStyle: { width: 1.6, color, type: s.predicted ? ('dashed' as const) : ('solid' as const) },
    itemStyle: { color },
    // Area fill only for a single series; two would overlap and become unreadable.
    areaStyle:
      seriesCount === 1 && !s.predicted
        ? {
            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
              { offset: 0, color: withAlpha(color, 0.28) },
              { offset: 1, color: withAlpha(color, 0.02) },
            ]),
          }
        : undefined,
    connectNulls: false,
    // LTTB keeps the extremes; 'average' would smooth away exactly the spike
    // the operator opened the chart for.
    sampling: 'lttb' as const,
    // Events (outage, restart, config change) and notes as vertical lines.
    // silent: false - hovering the line shows WHAT happened at that moment.
    markLine: markLineData.length
      ? {
          // Symbols off for every line: ECharts ignores a per-item `symbol` on
          // an xAxis marker, so leaving this out draws its default arrows and
          // squares on events too.
          symbol: 'none',
          silent: false,
          lineStyle: { color: eventColor, type: 'dotted' as const, width: 1.2 },
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
 * chart would not exist for a blind user. Min/max/avg is the least worth saying.

 */
function describe(data: ChartData, t: TranslateFn): string {
  const parts = data.series.map((s) => {
    const values = s.points.map((p) => p.v).filter((v): v is number => v != null);
    if (values.length === 0) {
      return t('chart.summary_no_data', { label: s.label }, `${s.label}: žádná data`);
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    const avg = values.reduce((sum, v) => sum + v, 0) / values.length;
    // Breaks belong in the summary: for a screen reader the holes in the line
    // are invisible, and a chart full of gaps otherwise reads as a full one.
    const gaps = s.points.filter((p) => p.v == null).length;

    const stats = t(
      'chart.summary_stats',
      { label: s.label, min: String(min), max: String(max), avg: avg.toFixed(1), unit: s.unit },
      `${s.label}: minimum ${min} ${s.unit}, maximum ${max} ${s.unit}, průměr ${avg.toFixed(1)} ${s.unit}`
    );
    return gaps > 0 ? `${stats}. ${t('chart.summary_gaps', { n: gaps }, `${gaps} přerušení měření`)}` : stats;
  });

  return `${data.title}. ${parts.join('. ')}.`;
}
