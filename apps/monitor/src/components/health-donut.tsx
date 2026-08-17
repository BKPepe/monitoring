import { cn } from '@/lib/utils';

/**
 * Ring chart of the status distribution.
 *
 * Pure SVG, no chart library — ECharts (Sprint 4) suits time series with
 * zoom and brushing, not a circle with three segments. No point dragging
 * a megabyte of JS onto the dashboard for this.
 */
export interface HealthSegment {
  label: string;
  value: number;
  variant: 'up' | 'warning' | 'down' | 'paused';
}

const strokeClass: Record<HealthSegment['variant'], string> = {
  up: 'stroke-up',
  warning: 'stroke-warning',
  down: 'stroke-down',
  paused: 'stroke-paused',
};

const dotClass: Record<HealthSegment['variant'], string> = {
  up: 'bg-up',
  warning: 'bg-warning',
  down: 'bg-down',
  paused: 'bg-paused',
};

const RADIUS = 54;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

export function HealthDonut({
  segments,
  centerLabel,
  className,
}: {
  segments: HealthSegment[];
  /** Label in the ring's centre — typically the healthy share. */
  centerLabel: { value: string; caption: string };
  className?: string;
}) {
  const total = segments.reduce((sum, s) => sum + s.value, 0);

  // Segment offsets accumulate; each starts where the previous ended.
  // The running total is computed ahead of time instead of mutating a variable
  // during render - render should be a pure function of its inputs.
  const offsets = segments.reduce<number[]>((acc, _segment, i) => {
    const prevFraction = i === 0 ? 0 : total === 0 ? 0 : segments[i - 1].value / total;
    acc.push((acc[i - 1] ?? 0) + prevFraction);
    return acc;
  }, []);

  const arcs = segments.map((segment, i) => {
    const fraction = total === 0 ? 0 : segment.value / total;
    return {
      ...segment,
      fraction,
      dash: fraction * CIRCUMFERENCE,
      offset: -offsets[i] * CIRCUMFERENCE,
    };
  });

  return (
    <div className={cn('flex flex-wrap items-center justify-center gap-6', className)}>
      <div className="relative shrink-0">
        <svg viewBox="0 0 140 140" className="size-36 -rotate-90" role="presentation">
          <circle cx="70" cy="70" r={RADIUS} fill="none" strokeWidth="14" className="stroke-muted" />
          {arcs.map((arc) => (
            <circle
              key={arc.label}
              cx="70"
              cy="70"
              r={RADIUS}
              fill="none"
              strokeWidth="14"
              strokeLinecap="butt"
              strokeDasharray={`${arc.dash} ${CIRCUMFERENCE - arc.dash}`}
              strokeDashoffset={arc.offset}
              className={strokeClass[arc.variant]}
            />
          ))}
        </svg>

        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
          <div>
            <p className="tabular text-2xl font-semibold">{centerLabel.value}</p>
            <p className="text-muted-foreground text-xs">{centerLabel.caption}</p>
          </div>
        </div>
      </div>

      {/* The legend carries the same numbers as the chart — it doubles as the
          text alternative for screen readers, hence the chart itself has role="presentation". */}
      <ul className="flex min-w-40 flex-col gap-2 text-sm">
        {arcs.map((arc) => (
          <li key={arc.label} className="flex items-center gap-2">
            <span className={cn('size-2.5 shrink-0 rounded-sm', dotClass[arc.variant])} />
            <span className="text-muted-foreground">{arc.label}</span>
            <span className="tabular ml-auto font-medium">{arc.value}</span>
            <span className="tabular text-muted-foreground w-12 text-right text-xs">
              {(arc.fraction * 100).toFixed(1)} %
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
