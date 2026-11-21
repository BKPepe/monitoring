import * as React from 'react';
import type { LucideIcon } from 'lucide-react';
import type { MetricTone } from '@/api/types';
import { Sparkline } from '@/components/sparkline';
import { useLanguage } from '@/context/language-context';
import { formatNumber } from '@/lib/metric-format';
import type { SparkSample } from '@/lib/sparkline-segments';
import type { TrendDelta } from '@/lib/trend';
import { cn } from '@/lib/utils';

/**
 * The one statistic tile (C-1): a label, the value, and whatever belongs
 * under it - a caption, a trend against the previous period, a sparkline.
 *
 * It replaced five tiles that had drifted apart (MetricTile, the asset
 * HealthCard, the metric StatTile, the public Stat and the service cards'
 * hand-built blocks): one printed the value in the series hue, one coloured
 * every delta red or green, and a green "Online 6" looked like a verdict. The
 * rules live here now:
 * - the value is in the foreground colour, mono with tabular digits
 *   (apps/site DESIGN.md: figures read as measurements and a refreshed value
 *   does not shift sideways), under an uppercase micro-label;
 * - colour only when a limit is breached (`tone`), never for decoration;
 * - `null` is "not measured" and draws as a dash;
 * - a trend is muted unless it crossed one of the monitor's limits.
 */
export type StatTone = 'down' | 'warning';

/** A status verdict as a tile tone: only a breach colours the number, "up" stays foreground. */
export function breachTone(severity: 'up' | 'warning' | 'down' | null | undefined): StatTone | null {
  return severity === 'down' || severity === 'warning' ? severity : null;
}

export function StatBlock({
  icon: Icon,
  label,
  value,
  secondary,
  hint,
  tone,
  delta,
  sparkline,
  loading = false,
  variant = 'inset',
  size = 'md',
  className,
  children,
}: {
  icon?: LucideIcon;
  label: React.ReactNode;
  /**
   * The headline value. `null` is "not measured" and draws as a dash. Leave
   * the prop out for a block that has no headline, only children.
   */
  value?: React.ReactNode;
  /** A quieter suffix after a measured value - a unit, a maximum. */
  secondary?: React.ReactNode;
  /** One short line under the value. */
  hint?: React.ReactNode;
  /** Set only when the value is past a limit: the value takes the status colour. */
  tone?: StatTone | null;
  /** Change against the same window one period earlier (lib/trend.ts). */
  delta?: TrendDelta | null;
  /** The value's own series; drawn under it, stroked in the metric's hue. */
  sparkline?: {
    points: readonly SparkSample[];
    tone: MetricTone;
    unit?: string;
    window?: { from: number; to: number } | null;
  };
  /**
   * The value is on its way: a placeholder bar instead of a number. A 0 drawn
   * before the first answer read as "no outages" on every page load (W1-A4).
   */
  loading?: boolean;
  /**
   * 'card' stands on the page on its own; 'inset' sits inside another card as
   * a well (the NetPulse KPI box); 'plain' is a bare cell of a StatRow - the
   * strip of figures under a chart or a ring.
   */
  variant?: 'card' | 'inset' | 'plain';
  /** 'sm' for a dense row of six or more tiles; 'xs' for a StatRow strip. */
  size?: 'xs' | 'sm' | 'md';
  className?: string;
  children?: React.ReactNode;
}) {
  return (
    <div
      data-slot="stat-block"
      aria-busy={loading || undefined}
      className={cn(
        'flex min-w-0 flex-col',
        variant === 'card' && 'bg-card text-card-foreground shadow-card rounded-xl border border-border p-4',
        variant === 'inset' && 'bg-inset rounded-lg border border-border p-3',
        className
      )}
    >
      {/* A micro-label, not a heading: the number is what the tile is for. */}
      <div className="micro-label flex items-center gap-1.5">
        {Icon && <Icon aria-hidden="true" className="size-3.5 shrink-0" />}
        <span className="min-w-0">{label}</span>
      </div>
      {loading ? (
        <div className="mt-1 flex h-8 items-center" data-testid="stat-block-skeleton">
          <span className="bg-muted h-6 w-16 animate-pulse rounded-md motion-reduce:animate-none" />
        </div>
      ) : (
        value !== undefined && (
          <p className="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <span
              data-tone={tone ?? undefined}
              className={cn(
                'font-mono font-semibold tracking-tight tabular-nums',
                size === 'xs' ? 'text-base' : size === 'sm' ? 'text-lg' : 'text-2xl',
                tone === 'down' && 'text-down',
                tone === 'warning' && 'text-warning'
              )}
            >
              {value == null ? '—' : value}
              {value != null && secondary != null && (
                <span className="text-muted-foreground font-sans text-sm font-medium"> {secondary}</span>
              )}
            </span>
            {value != null && delta && <DeltaChip delta={delta} />}
          </p>
        )
      )}
      {hint && !loading && <p className="text-muted-foreground mt-0.5 text-2xs">{hint}</p>}
      {sparkline && !loading && (
        <div className="mt-auto pt-1">
          <Sparkline points={sparkline.points} tone={sparkline.tone} unit={sparkline.unit} window={sparkline.window} />
        </div>
      )}
      {children}
    </div>
  );
}

/** "↑ 2 p. b." - muted unless the change crossed a limit. */
export function DeltaChip({ delta }: { delta: TrendDelta }) {
  const { t, lang } = useLanguage();
  const amount = formatNumber(delta.value, lang, 1);
  const unit = delta.unit === 'pp' ? t('stat.delta_pp', 'p. b.') : '%';
  return (
    <span
      title={t('chart_card.delta_title', 'Změna průměru proti stejně dlouhému předchozímu období')}
      data-tone={delta.tone}
      className={cn(
        'text-xs font-semibold tabular-nums whitespace-nowrap',
        delta.tone === 'bad' ? 'text-down' : delta.tone === 'good' ? 'text-up' : 'text-muted-foreground'
      )}
    >
      {delta.direction === 'up' ? '↑' : '↓'} {amount} {unit}
    </span>
  );
}

/**
 * A strip of StatBlocks (variant="plain") - the "PEAK / AVERAGE / TOTAL /
 * LOSS" row under a chart, the "LATENCY / CLIENTS" pair under a ring. Two
 * columns on a phone, up to `cols` from sm; the cells may shrink, so a long
 * figure wraps inside its cell instead of widening the page.
 */
export function StatRow({
  cols = 4,
  className,
  children,
}: {
  cols?: 2 | 3 | 4 | 5 | 6;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div
      data-slot="stat-row"
      className={cn(
        'grid grid-cols-2 gap-x-6 gap-y-3 *:min-w-0',
        cols === 3 && 'sm:grid-cols-3',
        cols === 4 && 'sm:grid-cols-4',
        cols === 5 && 'sm:grid-cols-3 lg:grid-cols-5',
        cols === 6 && 'sm:grid-cols-3 lg:grid-cols-6',
        className
      )}
    >
      {children}
    </div>
  );
}
