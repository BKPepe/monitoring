import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { MetricChart } from './metric-chart';
import { computeSeriesDelta, goodDirectionFor } from './series-delta';
import type { ChartData } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

/**
 * Karta grafu: malý titulek, velká aktuální hodnota a delta za zobrazené
 * okno (průměr poslední vs. první čtvrtiny bodů) - vizuální jazyk podle
 * mockupu dashboardu ("CPU Usage (%) / 23 % ↓ 5 %").
 */
export function ChartCard({ data, group }: { data: ChartData; group?: string }) {
  const { t } = useLanguage();
  const primary = data.series[0];
  const latest = [...(primary?.points ?? [])].reverse().find((p) => p.v != null);
  const hasData = (primary?.points.length ?? 0) > 0;

  const delta = computeSeriesDelta(primary);
  const goodDir = goodDirectionFor(primary?.tone);
  // Neutrální metriky (síť) nesou deltu bez hodnotícího zabarvení.
  const deltaGood = delta && goodDir ? delta.direction === goodDir : null;

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardTitle className="text-muted-foreground text-xs font-medium">{data.title}</CardTitle>
          {latest && (
            <div className="mt-0.5 flex items-baseline gap-2">
              <span className="tabular text-2xl font-bold tracking-tight">
                {latest.v}
                <span className="text-muted-foreground ml-1 text-sm font-medium">{primary.unit}</span>
              </span>
              {delta && (
                <span
                  className={cn(
                    'tabular text-xs font-semibold',
                    deltaGood === null ? 'text-muted-foreground' : deltaGood ? 'text-up' : 'text-down'
                  )}
                  title={t('chart_card.delta_title', 'Změna průměru za zobrazené období (konec vs. začátek)')}
                >
                  {delta.direction === 'up' ? '↑' : '↓'} {delta.pct} %
                </span>
              )}
            </div>
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
          <MetricChart data={data} group={group} />
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
