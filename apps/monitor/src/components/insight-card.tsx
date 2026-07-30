import { Activity, ShieldCheck, TrendingUp, Zap } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { formatRelative } from '@/lib/utils';
import type { Insight, InsightKind } from '@/data/mock';
import { cn } from '@/lib/utils';

const kindMeta: Record<InsightKind, { icon: LucideIcon; tone: string; ring: string }> = {
  anomaly: { icon: Zap, tone: 'text-warning', ring: 'bg-warning/12 border-warning/25' },
  prediction: { icon: Activity, tone: 'text-down', ring: 'bg-down/12 border-down/25' },
  security: { icon: ShieldCheck, tone: 'text-info', ring: 'bg-info/12 border-info/25' },
  trend: { icon: TrendingUp, tone: 'text-up', ring: 'bg-up/12 border-up/25' },
};

export function InsightCard({ insight }: { insight: Insight }) {
  const { icon: Icon, tone, ring } = kindMeta[insight.kind];

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex items-center gap-2.5">
        <span className={cn('grid size-8 shrink-0 place-items-center rounded-lg border', ring)}>
          <Icon className={cn('size-4', tone)} />
        </span>
        <p className="truncate text-sm font-medium">{insight.title}</p>
      </div>

      <p className="text-muted-foreground text-xs leading-relaxed">
        {insight.body}{' '}
        {insight.highlight && (
          <strong className={cn('font-semibold', tone)}>{insight.highlight}</strong>
        )}
      </p>

      <div className="text-muted-foreground mt-auto flex items-center justify-between text-xs">
        <span>{formatRelative(insight.at)}</span>
        <button type="button" className="hover:text-foreground transition-colors">
          Detail
        </button>
      </div>
    </Card>
  );
}
