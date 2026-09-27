import * as React from 'react';
import { Link } from 'react-router';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import type { MetricTone } from '@/api/types';
import { Sparkline } from '@/components/sparkline';
import type { SparkSample } from '@/lib/sparkline-segments';
import { cn } from '@/lib/utils';
import { IconTile, type IconTileTone } from './icon-tile';
import { NoValue } from './key-value';

/**
 * One row of a NetPulse list - the most active devices, the alerts card, the
 * bell: an icon tile, a title with a subtitle, and on the right a sparkline, a
 * mono figure and a chevron when the row leads somewhere.
 *
 * `highlight` marks a row that wants attention (an unread warning): a left
 * edge in the status colour and the lifted ground. The row still says what is
 * wrong in its words; the edge only makes it findable.
 *
 * The whole row is one link or one button, so it is one tab stop and its name
 * is what it says - title, subtitle, figure.
 */
export type RowHighlight = 'up' | 'warning' | 'down' | 'info';

const EDGE: Record<RowHighlight, string> = {
  up: 'border-l-up',
  warning: 'border-l-warning',
  down: 'border-l-down',
  info: 'border-l-info',
};

export function ListRow({
  icon,
  iconTone = 'neutral',
  title,
  subtitle,
  badge,
  meta,
  value,
  spark,
  trailing,
  highlight,
  to,
  onClick,
  className,
}: {
  icon?: LucideIcon;
  iconTone?: IconTileTone;
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  /** A pill right after the title (NEW, URGENT). */
  badge?: React.ReactNode;
  /** A quiet mono line top-right - a relative time ("12 min"). */
  meta?: React.ReactNode;
  /**
   * The row's figure, mono. `null` = not measured and draws a dash; leave the
   * prop out for a row that has no figure.
   */
  value?: React.ReactNode;
  /** The figure's own recent series (components/sparkline.tsx, the chart look). */
  spark?: {
    points: readonly SparkSample[];
    tone: MetricTone;
    unit?: string;
    window?: { from: number; to: number } | null;
  };
  /** Anything else on the right (an icon, a button when the row is not a link). */
  trailing?: React.ReactNode;
  highlight?: RowHighlight | null;
  /** In-app route; the row becomes a link. */
  to?: string;
  /** The row becomes a button. Ignored when `to` is set. */
  onClick?: () => void;
  className?: string;
}) {
  const interactive = to != null || onClick != null;
  const body = (
    <>
      {icon && <IconTile icon={icon} tone={iconTone} />}
      <span className="min-w-0 flex-1">
        <span className="flex min-w-0 items-center gap-2">
          <span className="truncate text-sm font-medium">{title}</span>
          {badge}
        </span>
        {subtitle && <span className="text-muted-foreground block truncate text-xs">{subtitle}</span>}
      </span>
      {spark && (
        <span className="w-14 shrink-0 sm:w-20">
          <Sparkline points={spark.points} tone={spark.tone} unit={spark.unit} window={spark.window} />
        </span>
      )}
      {(value !== undefined || meta != null) && (
        <span className="flex shrink-0 flex-col items-end gap-0.5 text-right">
          {meta != null && <span className="text-muted-foreground figure text-2xs">{meta}</span>}
          {value !== undefined && (
            <span className="figure text-sm font-medium">{value === null || value === '' ? <NoValue /> : value}</span>
          )}
        </span>
      )}
      {trailing}
      {interactive && <ChevronRight aria-hidden="true" className="text-muted-foreground size-4 shrink-0" />}
    </>
  );
  const rowClass = cn(
    'flex min-w-0 items-center gap-3 rounded-lg px-3 py-2.5 text-left',
    highlight ? ['border-l-2 bg-raised', EDGE[highlight]] : 'border-l-2 border-l-transparent',
    interactive &&
      'hover:bg-raised focus-visible:ring-ring w-full transition-colors focus-visible:ring-2 focus-visible:outline-none',
    className
  );

  if (to != null) {
    return (
      <Link to={to} data-slot="list-row" className={rowClass}>
        {body}
      </Link>
    );
  }
  if (onClick) {
    return (
      <button type="button" data-slot="list-row" onClick={onClick} className={rowClass}>
        {body}
      </button>
    );
  }
  return (
    <div data-slot="list-row" className={rowClass}>
      {body}
    </div>
  );
}

/** The list around ListRows: a real list for a screen reader ("list, 7 items"). */
export function ListRows({
  children,
  label,
  className,
}: {
  children: React.ReactNode;
  /** Names the list when the panel heading does not sit right above it. */
  label?: string;
  className?: string;
}) {
  return (
    <ul role="list" aria-label={label} className={cn('flex flex-col gap-1', className)}>
      {React.Children.map(children, (child) => (child == null || child === false ? null : <li>{child}</li>))}
    </ul>
  );
}
