import * as React from 'react';
import type { MetricTone } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { formatMetricValue } from '@/lib/metric-format';
import { sparklineGeometry, type SparkSample } from '@/lib/sparkline-segments';
import { cn } from '@/lib/utils';

/**
 * A miniature trace with no axes (C-4) - the one sparkline in the app.
 *
 * Plain SVG, not ECharts - a sparkline has no zoom, tooltip, or legend, and
 * it sits on the dashboard's critical path, where a charting library would
 * only add weight. The geometry (time on x, gaps, the y floor) lives in
 * lib/sparkline-segments.ts.
 *
 * It wears the same look as the big charts: the metric's token as the stroke,
 * a wash fading to nothing under it and, in the dark theme, a soft glow. All
 * three hang off one `text-chart-*` class through currentColor, and the wash
 * and glow strengths are the theme's --chart-fill and --chart-glow, so the
 * light theme stays flat. No end dot: a sparkline is not told how fresh its
 * newest sample is, and a dot would claim "now".
 */
const toneClass: Record<MetricTone, string> = {
  cpu: 'text-chart-cpu',
  memory: 'text-chart-memory',
  network: 'text-chart-network',
  temperature: 'text-chart-temperature',
  disk: 'text-chart-disk',
  latency: 'text-chart-latency',
};

export function Sparkline({
  points,
  window,
  tone = 'latency',
  unit = '',
  minRange,
  labels = false,
  className,
}: {
  /** Samples with timestamps in ms; `null` where nothing was measured. */
  points: readonly SparkSample[];
  /** The period the trace stands for, so silence at its end stays visible. */
  window?: { from: number; to: number } | null;
  tone?: MetricTone;
  /** The metric's unit: sets the default y floor and formats the edge labels. */
  unit?: string;
  /** Smallest y span drawn; see lib/sparkline-segments.ts. */
  minRange?: number;
  /**
   * First and last value beside the trace. For a sparkline that stands alone,
   * without a tile number next to it, the shape has no scale otherwise; it also
   * says "málo dat" instead of vanishing when there is nothing to draw.
   */
  labels?: boolean;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  // One gradient per instance; the id must be a valid URL fragment.
  const id = 'spk' + React.useId().replace(/[^\w]/g, '');
  const width = 100;
  const height = 28;
  // A percentage moves in points: a 10-point floor keeps 12.4-12.6 % flat.
  const floor = minRange ?? (unit === '%' ? 10 : undefined);
  const { segments, first, last } = sparklineGeometry(points, width, height, { window, minRange: floor });

  if (segments.length === 0) {
    return labels ? (
      <p className="text-muted-foreground text-2xs">{t('sparkline.too_few', 'Málo dat na průběh')}</p>
    ) : null;
  }

  const svg = (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-7 w-full overflow-visible', toneClass[tone], labels ? 'min-w-0 flex-1' : className)}
      // The trace decorates the adjacent number - it conveys nothing on its own.
      aria-hidden="true"
    >
      <defs>
        <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="currentColor" style={{ stopOpacity: 'var(--chart-fill)' }} />
          <stop offset="1" stopColor="currentColor" stopOpacity="0" />
        </linearGradient>
      </defs>
      {segments.map((segment, i) => {
        const path = segment.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ');
        return (
          <g key={i}>
            {/* The wash closes to the baseline under its own segment only, so a
                gap is empty rather than shaded. */}
            <polygon
              points={`${segment[0].x.toFixed(2)},${height} ${path} ${segment[segment.length - 1].x.toFixed(2)},${height}`}
              fill={`url(#${id})`}
            />
            <polyline
              points={path}
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinejoin="round"
              strokeLinecap="round"
              vectorEffect="non-scaling-stroke"
              className="chart-glow"
            />
          </g>
        );
      })}
    </svg>
  );
  if (!labels) return svg;

  return (
    // The edge values are measurements: mono, tabular (apps/site DESIGN.md).
    <div className={cn('text-muted-foreground flex items-center gap-1.5 font-mono text-2xs tabular-nums', className)}>
      <span className="shrink-0">{formatMetricValue(first, unit, lang)}</span>
      {svg}
      <span className="shrink-0">{formatMetricValue(last, unit, lang)}</span>
    </div>
  );
}
