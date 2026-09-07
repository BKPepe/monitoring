import { cn } from '@/lib/utils';
import { sparklineSegments } from '@/lib/sparkline-segments';

/**
 * A miniature trace with no axes or labels.
 *
 * Plain SVG again, not ECharts - a sparkline has no zoom, tooltip, or
 * legend, so a charting library would only add weight. Larger charts with
 * axes are built in Sprint 4, and those will use ECharts.
 */
const strokeClass = {
  cpu: 'stroke-chart-cpu',
  memory: 'stroke-chart-memory',
  network: 'stroke-chart-network',
  temperature: 'stroke-chart-temperature',
  disk: 'stroke-chart-disk',
  latency: 'stroke-chart-latency',
} as const;

const fillClass = {
  cpu: 'fill-chart-cpu/15',
  memory: 'fill-chart-memory/15',
  network: 'fill-chart-network/15',
  temperature: 'fill-chart-temperature/15',
  disk: 'fill-chart-disk/15',
  latency: 'fill-chart-latency/15',
} as const;

export type SparklineTone = keyof typeof strokeClass;

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
  const width = 100;
  const height = 28;
  const segments = sparklineSegments(data, width, height);
  if (segments.length === 0) return null;

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-7 w-full', className)}
      // The sparkline decorates the adjacent number - it conveys nothing on its own.
      aria-hidden="true"
    >
      {segments.map((points, i) => {
        const path = points.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ');
        return (
          <g key={i}>
            {/* The fill closes to the baseline under its own segment only, so a
                gap is empty rather than shaded. */}
            <polygon
              points={`${points[0].x.toFixed(2)},${height} ${path} ${points[points.length - 1].x.toFixed(2)},${height}`}
              className={fillClass[tone]}
              stroke="none"
            />
            <polyline
              points={path}
              fill="none"
              strokeWidth="1.5"
              strokeLinejoin="round"
              strokeLinecap="round"
              vectorEffect="non-scaling-stroke"
              className={strokeClass[tone]}
            />
          </g>
        );
      })}
    </svg>
  );
}
