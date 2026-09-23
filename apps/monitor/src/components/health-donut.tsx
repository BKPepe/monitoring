import * as React from 'react';
import { Link, useNavigate } from 'react-router';
import { cn } from '@/lib/utils';
import { statusVariant, type BadgeProps } from '@/components/ui/badge';

/**
 * Ring chart of the status distribution.
 *
 * Pure SVG, no chart library — ECharts (Sprint 4) suits time series with
 * zoom and brushing, not a circle with three segments. No point dragging
 * a megabyte of JS onto the dashboard for this.
 *
 * There is deliberately no tooltip: the legend beside the ring already shows
 * every label, count and share at all times, so a hover box would only repeat
 * what is on screen. Hovering instead links the two halves (arc <-> legend
 * row), and `hrefFor` turns each status into a way out - the ring used to say
 * "two devices are offline" and leave you to find them yourself.
 */
export interface HealthSegment {
  label: string;
  value: number;
  variant: 'up' | 'warning' | 'down' | 'paused' | 'maintenance' | 'unknown';
}

// The ring takes its colours from the shared status decision in badge.tsx.
// It kept a private copy that painted a silent agent grey, a few centimetres
// from a monitor table that - by that decision - paints it amber.
type Tone = NonNullable<BadgeProps['variant']>;
const strokeByTone: Record<Tone, string> = {
  up: 'stroke-up',
  down: 'stroke-down',
  warning: 'stroke-warning',
  info: 'stroke-info',
  paused: 'stroke-paused',
  neutral: 'stroke-muted-foreground',
  primary: 'stroke-primary',
};
const dotByTone: Record<Tone, string> = {
  up: 'bg-up',
  down: 'bg-down',
  warning: 'bg-warning',
  info: 'bg-info',
  paused: 'bg-paused',
  neutral: 'bg-muted-foreground',
  primary: 'bg-primary',
};
const strokeClass = Object.fromEntries(
  Object.entries(statusVariant).map(([state, tone]) => [state, strokeByTone[tone]])
) as Record<HealthSegment['variant'], string>;
const dotClass = Object.fromEntries(
  Object.entries(statusVariant).map(([state, tone]) => [state, dotByTone[tone]])
) as Record<HealthSegment['variant'], string>;

const RADIUS = 54;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

export function HealthDonut({
  segments,
  centerLabel,
  className,
  hrefFor,
}: {
  segments: HealthSegment[];
  /** Label in the ring's centre — typically the healthy share. */
  centerLabel: { value: string; caption: string };
  className?: string;
  /**
   * Target for a segment, e.g. the device list filtered to that status.
   * Without it the ring stays a plain read-only figure.
   */
  hrefFor?: (segment: HealthSegment) => string;
}) {
  const total = segments.reduce((sum, s) => sum + s.value, 0);
  const [hovered, setHovered] = React.useState<string | null>(null);
  const navigate = useNavigate();

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

  // An empty status has nothing to show on the other side, so it never becomes
  // a link - a row promising "0 offline devices" would lead to a blank list.
  const linkFor = (arc: HealthSegment) => (hrefFor && arc.value > 0 ? hrefFor(arc) : null);

  // A small installation used to read "Warning 0, Paused 0, Maintenance 0,
  // Unknown 0" under the ring - four lines saying nothing. Online and offline
  // stay even at zero, because "0 offline" is the reassurance the ring is
  // for; the other states appear once there is one to count.
  const legend = arcs.filter((arc) => arc.value > 0 || arc.variant === 'up' || arc.variant === 'down');

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
              // The hovered arc thickens instead of changing colour: the colour
              // IS the status here and must not shift meaning on hover.
              strokeWidth={hovered === arc.label ? 18 : 14}
              strokeLinecap="butt"
              strokeDasharray={`${arc.dash} ${CIRCUMFERENCE - arc.dash}`}
              strokeDashoffset={arc.offset}
              className={cn(
                strokeClass[arc.variant],
                'transition-[stroke-width,opacity] duration-150',
                hovered !== null && hovered !== arc.label && 'opacity-40',
                linkFor(arc) && 'cursor-pointer'
              )}
              onMouseEnter={() => setHovered(arc.label)}
              onMouseLeave={() => setHovered(null)}
              onClick={() => {
                const href = linkFor(arc);
                if (href) navigate(href);
              }}
            />
          ))}
        </svg>

        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
          <div>
            <p className="tabular-nums text-2xl font-semibold">{centerLabel.value}</p>
            <p className="text-muted-foreground text-xs">{centerLabel.caption}</p>
          </div>
        </div>
      </div>

      {/* The legend carries the same numbers as the chart — it doubles as the
          text alternative for screen readers, hence the chart itself has role="presentation". */}
      <ul className="flex min-w-40 flex-col gap-2 text-sm">
        {legend.map((arc) => {
          const href = linkFor(arc);
          const row = (
            <>
              <span className={cn('size-2.5 shrink-0 rounded-sm', dotClass[arc.variant])} />
              <span className={cn(href ? 'text-foreground' : 'text-muted-foreground')}>{arc.label}</span>
              {/* Nothing counted = nothing known: "0 offline, 0.0 %" read as
                  reassurance about a fleet nobody had measured (W1-A4). */}
              <span className="tabular-nums ml-auto font-medium">{total === 0 ? '—' : arc.value}</span>
              {/* w-14: "100.0 %" wrapped onto two lines in the w-12 column. */}
              <span className="tabular-nums text-muted-foreground w-14 shrink-0 text-right text-xs whitespace-nowrap">
                {total === 0 ? '—' : `${(arc.fraction * 100).toFixed(1)} %`}
              </span>
            </>
          );

          return (
            <li key={arc.label}>
              {href ? (
                <Link
                  to={href}
                  onMouseEnter={() => setHovered(arc.label)}
                  onMouseLeave={() => setHovered(null)}
                  onFocus={() => setHovered(arc.label)}
                  onBlur={() => setHovered(null)}
                  className={cn(
                    'focus-visible:ring-ring -mx-1.5 flex items-center gap-2 rounded-md px-1.5 py-0.5 transition-colors focus-visible:ring-2 focus-visible:outline-none',
                    hovered === arc.label && 'bg-secondary/60'
                  )}
                >
                  {row}
                </Link>
              ) : (
                <div className="-mx-1.5 flex items-center gap-2 px-1.5 py-0.5">{row}</div>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}
