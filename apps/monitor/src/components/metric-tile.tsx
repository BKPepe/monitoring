import type { LucideIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * A tile with a single key value (KPI).
 *
 * The tone is always told in words too (the hint), never by colour alone.
 * The caller derives it from the value; the tile never guesses one.
 */
export function MetricTile({
  label,
  value,
  unit,
  hint,
  icon: Icon,
  tone,
  loading = false,
}: {
  label: string;
  value: string | number;
  unit?: string;
  hint?: string;
  icon?: LucideIcon;
  tone?: 'up' | 'down' | 'warning' | 'info';
  /**
   * The value is on its way: a placeholder bar instead of a number. A 0 drawn
   * before the first answer read as "no outages" on every page load (W1-A4).
   */
  loading?: boolean;
}) {
  return (
    <Card className="p-4" aria-busy={loading || undefined}>
      <div className="flex items-start justify-between gap-2">
        {/* A micro-label, not a heading: the number is what the tile is for. */}
        <p className="text-muted-foreground text-2xs font-semibold tracking-wider uppercase">{label}</p>
        {Icon && (
          <Icon
            aria-hidden="true"
            className={cn(
              'size-4 shrink-0',
              tone === 'up' && 'text-up',
              tone === 'down' && 'text-down',
              tone === 'warning' && 'text-warning',
              tone === 'info' && 'text-info',
              !tone && 'text-muted-foreground'
            )}
          />
        )}
      </div>

      {loading ? (
        <div className="mt-2 flex h-8 items-center" data-testid="metric-tile-skeleton">
          <span className="bg-muted h-6 w-16 animate-pulse rounded-md motion-reduce:animate-none" />
        </div>
      ) : (
        // Mono, tabular digits (apps/site DESIGN.md §3): a refreshed value
        // does not shift sideways, and figures read as measurements.
        <div className="mt-2 flex items-baseline gap-1.5">
          <span className="font-mono text-2xl font-semibold tracking-tight tabular-nums">{value}</span>
          {unit && <span className="text-muted-foreground text-sm">{unit}</span>}
        </div>
      )}

      {hint && !loading && <p className="text-muted-foreground mt-1 text-xs">{hint}</p>}
    </Card>
  );
}
