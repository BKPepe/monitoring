import { cn } from '@/lib/utils';

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
  data: number[];
  tone?: SparklineTone;
  className?: string;
}) {
  if (data.length < 2) return null;

  const width = 100;
  const height = 28;
  const min = Math.min(...data);
  const max = Math.max(...data);
  // A constant series would otherwise divide by zero and vanish at the edge.
  const range = max - min || 1;

  const points = data.map((value, i) => {
    const x = (i / (data.length - 1)) * width;
    const y = height - ((value - min) / range) * (height - 4) - 2;
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  });

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-7 w-full', className)}
      // The sparkline decorates the adjacent number - it conveys nothing on its own.
      aria-hidden="true"
    >
      <polygon
        points={`0,${height} ${points.join(' ')} ${width},${height}`}
        className={fillClass[tone]}
        stroke="none"
      />
      <polyline
        points={points.join(' ')}
        fill="none"
        strokeWidth="1.5"
        strokeLinejoin="round"
        strokeLinecap="round"
        vectorEffect="non-scaling-stroke"
        className={strokeClass[tone]}
      />
    </svg>
  );
}
