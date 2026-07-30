import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { MetricChart } from './metric-chart';
import type { ChartData } from '@/api/types';

/** Karta s grafem — jednotné záhlaví, poslední hodnota a stav načítání. */
export function ChartCard({ data }: { data: ChartData }) {
  const primary = data.series[0];
  const latest = [...(primary?.points ?? [])].reverse().find((p) => p.v != null);
  const hasData = (primary?.points.length ?? 0) > 0;

  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>{data.title}</CardTitle>
          {latest && (
            <p className="tabular mt-0.5 text-lg font-semibold">
              {latest.v} {primary.unit}
            </p>
          )}
        </div>
        {/* Predikci počítá server (lineární regrese nad 7 dny), ne UI. */}
        {data.daysToFull != null && (
          <Badge variant={data.daysToFull < 14 ? 'warning' : 'info'}>
            Plno za {data.daysToFull} dní
          </Badge>
        )}
      </CardHeader>
      <CardContent className="pb-3">
        {hasData ? (
          <MetricChart data={data} />
        ) : (
          // Prázdný graf je legitimní odpověď — monitor nemusí tuhle
          // metriku vůbec hlásit. Falešná křivka by lhala.
          <div className="text-muted-foreground grid h-[200px] place-items-center text-center text-xs">
            Pro tuto metriku nejsou data
          </div>
        )}
      </CardContent>
    </Card>
  );
}

/** Kostra karty během načítání — drží výšku, aby obsah pod ní nepodskočil. */
export function ChartCardSkeleton({ title }: { title: string }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="pb-3">
        <div
          className="bg-muted/40 h-[200px] w-full animate-pulse rounded-md"
          aria-hidden="true"
        />
        <span className="sr-only">Načítám {title}</span>
      </CardContent>
    </Card>
  );
}
