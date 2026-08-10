import { Card } from '@/components/ui/card';
import { Sparkline } from '@/components/sparkline';
import type { HealthMetric } from '@/data/model';
import { cn } from '@/lib/utils';

/**
 * A tile in the "Health Cards" row on the device detail page.
 *
 * The trend is evaluated against `goodDirection`: for CPU a decrease is
 * good, for uptime it's the opposite. Without that, a green down arrow on
 * latency would mean something different every time.
 */
export function HealthCard({ metric }: { metric: HealthMetric }) {
  const { label, value, caption, delta, goodDirection = 'up', series, tone } = metric;
  const isGood = delta ? delta.direction === goodDirection : undefined;

  return (
    <Card className="flex flex-col gap-2 p-4">
      <p className="text-muted-foreground text-xs font-medium">{label}</p>

      <div className="flex items-baseline gap-2">
        <span className="tabular text-xl font-semibold tracking-tight">{value}</span>
        {delta && (
          <span className={cn('tabular text-xs font-medium', isGood ? 'text-up' : 'text-down')}>
            {delta.direction === 'up' ? '▲' : '▼'} {delta.value}
          </span>
        )}
      </div>

      {caption && <p className="text-muted-foreground text-xs">{caption}</p>}

      {series && tone && (
        <div className="mt-auto pt-1">
          <Sparkline data={series} tone={tone} />
        </div>
      )}
    </Card>
  );
}
