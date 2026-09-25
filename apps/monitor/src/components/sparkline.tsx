import * as React from 'react';
import { cn } from '@/lib/utils';
import { sparklineSegments } from '@/lib/sparkline-segments';

/**
 * A miniature trace with no axes or labels.
 *
 * Plain SVG, not ECharts - a sparkline has no zoom, tooltip, or legend, and
 * it sits on the dashboard's critical path, where a charting library would
 * only add weight.
 *
 * It wears the same look as the big charts: the metric's token as the stroke,
 * a wash fading to nothing under it and, in the dark theme, a soft glow. All
 * three hang off one `text-chart-*` class through currentColor, and the wash
 * and glow strengths are the theme's --chart-fill and --chart-glow, so the
 * light theme stays flat. No end dot: a sparkline is not told how fresh its
 * newest sample is, and a dot would claim "now".
 */
const toneClass = {
  cpu: 'text-chart-cpu',
  memory: 'text-chart-memory',
  network: 'text-chart-network',
  temperature: 'text-chart-temperature',
  disk: 'text-chart-disk',
  latency: 'text-chart-latency',
} as const;

export type SparklineTone = keyof typeof toneClass;

export function Sparkline({
  data,
  tone = 'cpu',
  className,
}: {
  /**
   * One point per sample; `null` where nothing was measured. A gap stays a
   * gap - see lib/sparkline-segments.ts for the rule and its tests.
   */
  data: (number | null)[];
  tone?: SparklineTone;
  className?: string;
}) {
  // One gradient per instance; the id must be a valid URL fragment.
  const id = 'spk' + React.useId().replace(/[^\w]/g, '');
  const width = 100;
  const height = 28;
  const segments = sparklineSegments(data, width, height);
  if (segments.length === 0) return null;

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-7 w-full overflow-visible', toneClass[tone], className)}
      // The sparkline decorates the adjacent number - it conveys nothing on its own.
      aria-hidden="true"
    >
      <defs>
        <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="currentColor" style={{ stopOpacity: 'var(--chart-fill)' }} />
          <stop offset="1" stopColor="currentColor" stopOpacity="0" />
        </linearGradient>
      </defs>
      {segments.map((points, i) => {
        const path = points.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ');
        return (
          <g key={i}>
            {/* The wash closes to the baseline under its own segment only, so a
                gap is empty rather than shaded. */}
            <polygon
              points={`${points[0].x.toFixed(2)},${height} ${path} ${points[points.length - 1].x.toFixed(2)},${height}`}
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
}
