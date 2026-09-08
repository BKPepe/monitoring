import * as React from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { Chart } from './chart';
import { withAlpha } from './color';
import { useChartTheme, usePrefersReducedMotion } from './use-chart-theme';
import type { MetricPoint, MetricTone } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { percentile } from '@/lib/percentiles';

/**
 * Value histogram of the currently selected period.
 *
 * The line chart's average hides bimodality - a CPU that alternates between
 * 5 % idle and 95 % load averages to a calm-looking 50 %. The distribution
 * shows both humps. Computed client-side from the very points the line chart
 * draws (after unit conversion), so the two can never describe different data.
 *
 * Nulls are excluded, not counted as zero: an unmeasured moment has no value
 * to have a frequency.
 */
export function HistogramPanel({ points, unit, tone }: { points: MetricPoint[]; unit: string; tone: MetricTone }) {
  const theme = useChartTheme();
  const reducedMotion = usePrefersReducedMotion();
  const { t } = useLanguage();

  const histogram = React.useMemo(() => {
    const values = points.map((p) => p.v).filter((v): v is number => v != null);
    return values.length >= 2 ? buildHistogram(values) : null;
  }, [points]);

  // p95/p99 marked on the bars themselves: the shape shows where the mass is,
  // the markers show where the tail starts. Both come from the same values, so
  // they cannot disagree with the tiles above the chart.
  const marks = React.useMemo(() => {
    if (!histogram) return [];
    const values = points.map((p) => p.v).filter((v): v is number => v != null);
    return ([95, 99] as const)
      .map((p) => ({ p, value: percentile(values, p) }))
      .filter((m): m is { p: 95 | 99; value: number } => m.value != null)
      .map((m) => ({ p: m.p, value: m.value, bin: histogram.binOf(m.value) }));
  }, [histogram, points]);

  const option = React.useMemo<EChartsCoreOption | null>(() => {
    if (!histogram) return null;
    const color = theme.series[tone];
    const total = histogram.counts.reduce((s, c) => s + c, 0);

    return {
      animation: !reducedMotion,
      animationDuration: 300,
      grid: { top: 12, right: 12, bottom: 24, left: 44 },
      tooltip: {
        trigger: 'item',
        backgroundColor: theme.tooltipBg,
        borderColor: theme.tooltipBorder,
        borderWidth: 1,
        textStyle: { color: theme.text, fontSize: 12 },
        formatter: (params: { dataIndex: number }) => {
          const count = histogram.counts[params.dataIndex];
          const pct = total > 0 ? Math.round((count / total) * 1000) / 10 : 0;
          return `${histogram.labels[params.dataIndex]} ${unit}: <b>${count}</b> ${t('metric.hist_measurements', 'měření')} (${pct} %)`;
        },
      },
      xAxis: {
        type: 'category',
        data: histogram.labels,
        axisLine: { lineStyle: { color: theme.grid } },
        axisTick: { show: false },
        axisLabel: { color: theme.textMuted, fontSize: 10, hideOverlap: true },
      },
      yAxis: {
        type: 'value',
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: theme.textMuted, fontSize: 11 },
        splitLine: { lineStyle: { color: theme.grid } },
      },
      series: [
        {
          type: 'bar' as const,
          data: histogram.counts,
          barCategoryGap: '15%',
          itemStyle: { color: withAlpha(color, 0.75), borderRadius: [3, 3, 0, 0] },
          emphasis: { itemStyle: { color } },
          markLine: marks.length
            ? {
                symbol: 'none' as const,
                silent: true,
                lineStyle: { color: theme.textMuted, type: 'dashed' as const, width: 1 },
                label: {
                  show: true,
                  formatter: '{b}',
                  color: theme.textMuted,
                  fontSize: 10,
                  position: 'insideEndTop' as const,
                },
                data: marks.map((m) => ({ xAxis: m.bin, name: `p${m.p}` })),
              }
            : undefined,
        },
      ],
    };
  }, [histogram, marks, theme, reducedMotion, unit, t, tone]);

  if (!histogram || !option) {
    return (
      <p className="text-muted-foreground grid h-32 place-items-center text-xs">
        {t('metric.hist_empty', 'Pro histogram je ve zvoleném období příliš málo měření')}
      </p>
    );
  }

  return (
    <Chart
      key={theme.key}
      option={option}
      height={180}
      ariaLabel={t('metric.hist_aria', 'Histogram naměřených hodnot')}
      summary={describeHistogram(histogram, unit, marks, t)}
    />
  );
}

interface Histogram {
  labels: string[];
  counts: number[];
  /** Index of the bar a value falls into - used to place the percentile marks. */
  binOf: (value: number) => number;
}

/**
 * Bins with a "nice" width (1/2/5 × 10^k), edges aligned to it. Rounded bin
 * counts read better than mathematically optimal ones - "40–45" beats
 * "41.3–46.7" on an axis.
 */
function buildHistogram(values: number[]): Histogram {
  const min = Math.min(...values);
  const max = Math.max(...values);

  if (min === max) {
    // Every measurement identical - one bar says so honestly.
    return { labels: [formatEdge(min)], counts: [values.length], binOf: () => 0 };
  }

  const targetBins = Math.max(6, Math.min(24, Math.ceil(Math.sqrt(values.length))));
  const rawStep = (max - min) / targetBins;
  const mag = Math.pow(10, Math.floor(Math.log10(rawStep)));
  const step = [1, 2, 5, 10].map((m) => m * mag).find((s) => s >= rawStep) ?? 10 * mag;

  const start = Math.floor(min / step) * step;
  const binCount = Math.max(1, Math.ceil((max - start) / step));

  const counts = new Array<number>(binCount).fill(0);
  for (const v of values) {
    const idx = Math.min(binCount - 1, Math.floor((v - start) / step));
    counts[idx] += 1;
  }

  const labels = Array.from({ length: binCount }, (_, i) => {
    const a = start + i * step;
    return `${formatEdge(a)}–${formatEdge(a + step)}`;
  });

  return {
    labels,
    counts,
    binOf: (value: number) => Math.min(binCount - 1, Math.max(0, Math.floor((value - start) / step))),
  };
}

function formatEdge(v: number): string {
  const rounded = Math.round(v * 100) / 100;
  return String(Math.abs(rounded % 1) < 1e-9 ? Math.round(rounded) : rounded);
}

/** Text alternative - the canvas is invisible to a screen reader. */
function describeHistogram(
  h: Histogram,
  unit: string,
  marks: { p: number; value: number }[],
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): string {
  const top = h.counts.indexOf(Math.max(...h.counts));
  const main = t(
    'metric.hist_summary',
    { band: h.labels[top], unit, count: h.counts[top] },
    `Histogram: nejčastější pásmo ${h.labels[top]} ${unit} (${h.counts[top]}×).`
  );
  const tail = marks.map((m) => `p${m.p} ${m.value} ${unit}`).join(', ');
  return tail ? `${main} ${tail}.` : main;
}
