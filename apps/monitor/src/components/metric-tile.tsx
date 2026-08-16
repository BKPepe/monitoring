import type { LucideIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * A tile with a single key value (KPI).
 *
 * The trend is always described in words too, not just color and arrow -
 * and importantly: "lower is better" differs between latency and uptime,
 * so the caller controls the evaluation direction via `goodDirection`.
 */
export function MetricTile({
  label,
  value,
  unit,
  hint,
  delta,
  goodDirection = 'up',
  icon: Icon,
  tone,
}: {
  label: string;
  value: string | number;
  unit?: string;
  hint?: string;
  delta?: { value: string; direction: 'up' | 'down' };
  goodDirection?: 'up' | 'down';
  icon?: LucideIcon;
  tone?: 'up' | 'down' | 'warning' | 'info';
}) {
  const isGood = delta ? delta.direction === goodDirection : undefined;

  return (
    <Card className="p-4">
      <div className="flex items-start justify-between gap-2">
        <p className="text-muted-foreground text-xs font-medium">{label}</p>
        {Icon && (
          <Icon
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

      <div className="mt-2 flex items-baseline gap-1.5">
        <span className="tabular text-2xl font-semibold tracking-tight">{value}</span>
        {unit && <span className="text-muted-foreground text-sm">{unit}</span>}
      </div>

      <div className="mt-1 flex items-center gap-2">
        {delta && (
          <span className={cn('tabular text-xs font-medium', isGood ? 'text-up' : 'text-down')}>
            {delta.direction === 'up' ? '▲' : '▼'} {delta.value}
          </span>
        )}
        {hint && <span className="text-muted-foreground text-xs">{hint}</span>}
      </div>
    </Card>
  );
}
