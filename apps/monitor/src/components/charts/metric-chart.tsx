import * as React from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { Chart, echarts } from './chart';
import { useChartTheme, usePrefersReducedMotion } from './use-chart-theme';
import type { ChartData, ChartEvent, MetricSeries } from '@/api/types';

/**
 * Graf jedné metriky v čase (jedna nebo víc sérií ve společných osách).
 *
 * Barvy sérií jdou z tokenů `--chart-*`, takže CPU je zelené i tady i na
 * sparkline v health kartě. To je záměr: uživatel má poznat metriku podle
 * barvy, ne podle legendy.
 */
export function MetricChart({ data, height = 200 }: { data: ChartData; height?: number }) {
  const theme = useChartTheme();
  const reducedMotion = usePrefersReducedMotion();

  const option = React.useMemo<EChartsCoreOption>(() => {
    const unit = data.series[0]?.unit ?? '';

    return {
      animation: !reducedMotion,
      animationDuration: 300,
      grid: { top: 16, right: 12, bottom: 24, left: 44 },
      tooltip: {
        trigger: 'axis',
        backgroundColor: theme.tooltipBg,
        borderColor: theme.tooltipBorder,
        borderWidth: 1,
        textStyle: { color: theme.text, fontSize: 12 },
        axisPointer: { type: 'line', lineStyle: { color: theme.grid } },
        valueFormatter: (value: unknown) =>
          value == null ? '—' : `${value} ${unit}`,
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
        buildSeries(s, theme.series[s.tone], data.series.length, i === 0 ? data.events : undefined, theme.textMuted)
      ),
    };
  }, [data, theme, reducedMotion]);

  return (
    <Chart
      // Přepnutí motivu vyžaduje nové barvy v canvasu — remount je
      // nejspolehlivější způsob, jak to udělat bez zbytků starého tématu.
      key={theme.key}
      option={option}
      height={height}
      animate={!reducedMotion}
      ariaLabel={`${data.title} v čase`}
      summary={describe(data)}
    />
  );
}

function buildSeries(
  s: MetricSeries,
  color: string,
  seriesCount: number,
  events: ChartEvent[] | undefined,
  eventColor: string
) {
  return {
    name: s.label,
    type: 'line' as const,
    // [timestamp, hodnota] — null zůstává null, ať se díra v datech
    // vykreslí jako přerušení, ne jako pád na nulu.
    data: s.points.map((p) => [p.t, p.v]),
    showSymbol: false,
    smooth: 0.25,
    // Predikce se kreslí přerušovaně — nesmí být k nerozeznání od měření.
    lineStyle: { width: 1.6, color, type: s.predicted ? ('dashed' as const) : ('solid' as const) },
    itemStyle: { color },
    // Plocha jen u samostatné série; u dvou by se překrývaly a nešly číst.
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
    // Události (výpadek, restart, změna konfigurace) jako svislé čáry.
    markLine: events?.length
      ? {
          symbol: 'none',
          silent: true,
          lineStyle: { color: eventColor, type: 'dotted' as const, width: 1 },
          label: { show: false },
          data: events.map((e) => ({ xAxis: e.t })),
        }
      : undefined,
  };
}

/** Přidá alfa kanál k hex barvě z tokenu. */
function withAlpha(color: string, alpha: number): string {
  if (!color.startsWith('#')) return color;
  const hex = color.length === 4
    ? color.slice(1).split('').map((c) => c + c).join('')
    : color.slice(1);
  const r = parseInt(hex.slice(0, 2), 16);
  const g = parseInt(hex.slice(2, 4), 16);
  const b = parseInt(hex.slice(4, 6), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

/**
 * Textové shrnutí pro čtečky.
 *
 * Canvas je pro asistivní technologie prázdná plocha — bez tohohle popisu
 * by graf pro nevidomého uživatele neexistoval. Min/max/průměr je to
 * nejmenší, co dává smysl sdělit.
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
