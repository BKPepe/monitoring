import * as React from 'react';
import { Link, useNavigate } from 'react-router';
import { cn } from '@/lib/utils';
import { statusVariant, type BadgeProps } from '@/components/ui/badge';

/**
 * The status distribution of the fleet: the healthy share as the headline
 * figure, one horizontal bar split by status, and the list of statuses.
 *
 * It was a ring. A ring asks the eye to compare angles, and the question on
 * this card is "how many are not fine, and which": a straight bar reads the
 * small slices (1 offline out of 28) that an arc hides, and the share the
 * ring printed in its hole is simply the number, stated first. The name
 * stays so the dashboard does not have to change with it.
 *
 * The colours ARE the state here, so they are the status tokens - the one
 * chart where that is right. There is no tooltip: the list beside the bar
 * shows every label, count and share at all times, and hovering links the
 * two halves (segment <-> row), with `hrefFor` turning each status into a way
 * out to the device list narrowed to it.
 */
export interface HealthSegment {
  label: string;
  value: number;
  variant: 'up' | 'warning' | 'down' | 'paused' | 'maintenance' | 'unknown';
}

// The bar takes its colours from the shared status decision in badge.tsx,
// the same one the monitor table uses - a silent agent is one colour on both.
type Tone = NonNullable<BadgeProps['variant']>;
const dotByTone: Record<Tone, string> = {
  up: 'bg-up',
  down: 'bg-down',
  warning: 'bg-warning',
  info: 'bg-info',
  paused: 'bg-paused',
  neutral: 'bg-muted-foreground',
  primary: 'bg-primary',
};
const dotClass = Object.fromEntries(
  Object.entries(statusVariant).map(([state, tone]) => [state, dotByTone[tone]])
) as Record<HealthSegment['variant'], string>;

export function HealthDonut({
  segments,
  centerLabel,
  className,
  hrefFor,
}: {
  segments: HealthSegment[];
  /** The headline figure above the bar — typically the healthy share. */
  centerLabel: { value: string; caption: string };
  className?: string;
  /**
   * Target for a segment, e.g. the device list filtered to that status.
   * Without it the bar stays a plain read-only figure.
   */
  hrefFor?: (segment: HealthSegment) => string;
}) {
  const total = segments.reduce((sum, s) => sum + s.value, 0);
  const [hovered, setHovered] = React.useState<string | null>(null);
  const navigate = useNavigate();

  const arcs = segments.map((segment) => ({ ...segment, fraction: total === 0 ? 0 : segment.value / total }));

  // An empty status has nothing to show on the other side, so it never becomes
  // a link - a row promising "0 offline devices" would lead to a blank list.
  const linkFor = (arc: HealthSegment) => (hrefFor && arc.value > 0 ? hrefFor(arc) : null);

  // A small installation used to read "Warning 0, Paused 0, Maintenance 0,
  // Unknown 0" under the chart - four lines saying nothing. Online and offline
  // stay even at zero, because "0 offline" is the reassurance the card is
  // for; the other states appear once there is one to count.
  const legend = arcs.filter((arc) => arc.value > 0 || arc.variant === 'up' || arc.variant === 'down');

  return (
    <div className={cn('flex flex-col gap-4', className)}>
      <div>
        <p className="text-3xl font-semibold tracking-tight tabular-nums">{centerLabel.value}</p>
        <p className="text-muted-foreground text-xs">{centerLabel.caption}</p>
      </div>

      {/* Nothing counted draws an empty track, never a bar: an all-green bar
          from zero monitors would be an all-clear drawn from no data. The
          2 px gap between segments is the card showing through, not a border. */}
      <div
        role="presentation"
        className={cn('flex h-2.5 gap-0.5 overflow-hidden rounded-full', total === 0 && 'bg-muted')}
      >
        {arcs
          .filter((arc) => arc.value > 0)
          .map((arc) => (
            <span
              key={arc.label}
              style={{ flex: `${arc.value} 1 0` }}
              className={cn(
                'min-w-1 transition-opacity duration-150',
                dotClass[arc.variant],
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
      </div>

      {/* The legend carries the same numbers as the bar — it doubles as the
          text alternative for screen readers, hence the bar itself has role="presentation". */}
      <ul className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
        {legend.map((arc) => {
          const href = linkFor(arc);
          const row = (
            <>
              <span className={cn('size-2.5 shrink-0 rounded-[3px]', dotClass[arc.variant])} />
              <span className={cn(href ? 'text-foreground' : 'text-muted-foreground')}>{arc.label}</span>
              {/* Nothing counted = nothing known: "0 offline, 0.0 %" read as
                  reassurance about a fleet nobody had measured (W1-A4). */}
              <span className="ml-auto font-mono font-medium tabular-nums">{total === 0 ? '—' : arc.value}</span>
              {/* w-14: "100.0 %" wrapped onto two lines in the w-12 column. */}
              <span className="text-muted-foreground w-14 shrink-0 text-right font-mono text-xs whitespace-nowrap tabular-nums">
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
