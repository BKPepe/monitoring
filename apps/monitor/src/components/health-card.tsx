import { Card } from '@/components/ui/card';
import { Sparkline } from '@/components/sparkline';
import type { HealthMetric } from '@/data/mock';
import { cn } from '@/lib/utils';

/**
 * Dlaždice v řadě "Health Cards" na detailu zařízení.
 *
 * Trend se hodnotí podle `goodDirection`: u CPU je pokles dobře, u dostupnosti
 * naopak. Bez toho by zelená šipka dolů u odezvy znamenala pokaždé něco jiného.
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
