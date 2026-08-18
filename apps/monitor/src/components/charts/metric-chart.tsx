import * as React from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { Chart, echarts } from './chart';
import { withAlpha } from './color';
import { useChartTheme, usePrefersReducedMotion } from './use-chart-theme';
import type { ChartData, ChartEvent, MetricSeries } from '@/api/types';
import type { ChartTheme } from './use-chart-theme';

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
      animation: !reducedMotion,
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
          dataZoom: { yAxisIndex: 'none', title: { zoom: 'Zoom výběrem', back: 'Zpět' } },
          restore: { title: 'Obnovit' },
          saveAsImage: { title: 'Uložit PNG', name: data.id, backgroundColor: theme.tooltipBg },
          myCsv: {
            show: true,
            title: 'Export CSV',
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
        axisPointer: { type: 'line', lineStyle: { color: theme.grid } },
        valueFormatter: (value: unknown) => (value == null ? '—' : `${value} ${unit}`),
      },
      legend:
        data.series.length > 1
          ? {
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
        min: 0,
        max: data.yMax ?? undefined,
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: {
          color: theme.textMuted,
          fontSize: 11,
          formatter: (value: number) => `${value}${unit === '%' ? ' %' : ''}`,
        },
        splitLine: { lineStyle: { color: theme.grid } },
      },
      series: data.series.map((s, i) =>
        buildSeries(
          s,
          theme.series[s.tone],
          data.series.length,
          i === 0 ? data.events : undefined,
          theme.textMuted,
          // Bands belong to the first series only - drawn twice they darken.
          i === 0 ? data.bands : undefined,
          theme,
          i === 0 ? data.annotations : undefined
        )
      ),
    };
  }, [data, theme, reducedMotion, exportCsv, minimap]);

  return (
    <Chart
      // A theme switch needs new canvas colours — a remount is the most
      // reliable way without leftovers of the old theme.
      key={theme.key}
      option={option}
      height={height}
      animate={!reducedMotion}
      group={group}
      ariaLabel={`${data.title} v čase`}
      summary={describe(data)}
      onPickTime={onPickTime}
    />
  );
}

function buildSeries(
  s: MetricSeries,
  color: string,
  seriesCount: number,
  events: ChartEvent[] | undefined,
  eventColor: string,
  bands: ChartData['bands'],
  theme: ChartTheme,
  annotations?: ChartEvent[]
) {
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
      name: `${new Date(e.t).toLocaleString('cs-CZ')} — ${e.label}`,
    })),
    ...(annotations ?? []).map((a) => ({
      xAxis: a.t,
      name: `${new Date(a.t).toLocaleString('cs-CZ')} — ${a.label}`,
      lineStyle: { color: theme.annotation, type: 'solid' as const, width: 1.4 },
      emphasis: { lineStyle: { color: theme.annotation, width: 2.2 } },
    })),
  ];
  return {
    // Threshold bands as horizontal areas. A single line at the critical limit
    // does not say whether the current value sits just below it or far away.
    markArea: bands?.length
      ? {
          silent: true,
          itemStyle: { opacity: 1 },
          label: {
            show: true,
            position: 'insideTopLeft' as const,
            color: theme.textMuted,
            fontSize: 10,
          },
          data: bands.map((b) => [
            { yAxis: b.from, itemStyle: { color: theme.band[b.tone] }, name: b.label },
            { yAxis: b.to },
          ]),
        }
      : undefined,
    name: s.label,
    type: 'line' as const,
    // [timestamp, value] — null stays null, so a data gap draws as a
    // break, not a drop to zero.
    data: s.points.map((p) => [p.t, p.v]),
    showSymbol: false,
    smooth: 0.25,
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
            formatter: (params: { name?: string }) => params.name ?? '',
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
function describe(data: ChartData): string {
  const parts = data.series.map((s) => {
    const values = s.points.map((p) => p.v).filter((v): v is number => v != null);
    if (values.length === 0) return `${s.label}: žádná data`;

    const min = Math.min(...values);
    const max = Math.max(...values);
    const avg = values.reduce((sum, v) => sum + v, 0) / values.length;

    return `${s.label}: minimum ${min} ${s.unit}, maximum ${max} ${s.unit}, průměr ${avg.toFixed(1)} ${s.unit}`;
  });

  return `${data.title}. ${parts.join('. ')}.`;
}
