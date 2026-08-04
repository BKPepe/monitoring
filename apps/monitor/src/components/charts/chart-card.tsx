import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { MetricChart } from './metric-chart';
import type { ChartData } from '@/api/types';
import { useLanguage } from '@/context/language-context';

/** Chart card - unified header, latest value, and loading state. */
export function ChartCard({ data }: { data: ChartData }) {
  const { t } = useLanguage();
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
        {/* The forecast is computed by the server (linear regression over 7 days), not the UI. */}
        {data.daysToFull != null && (
          <Badge variant={data.daysToFull < 14 ? 'warning' : 'info'}>
            {t('chart_card.days_to_full', { days: data.daysToFull }, `Plno za ${data.daysToFull} dní`)}
          </Badge>
        )}
      </CardHeader>
      <CardContent className="pb-3">
        {hasData ? (
          <MetricChart data={data} />
        ) : (
          // An empty chart is a legitimate response - a monitor may not report
          // this metric at all. A fabricated curve would be a lie.
          <div className="text-muted-foreground grid h-[200px] place-items-center text-center text-xs">
            {t('chart_card.no_data', 'Pro tuto metriku nejsou data')}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

/** Card skeleton during loading - holds the height so content below doesn't jump. */
export function ChartCardSkeleton({ title }: { title: string }) {
  const { t } = useLanguage();
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
        <span className="sr-only">{t('chart_card.loading', { title }, `Načítám ${title}`)}</span>
      </CardContent>
    </Card>
  );
}
