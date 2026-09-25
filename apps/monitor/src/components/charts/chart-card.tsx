import * as React from 'react';
import { Clock } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { EmptyState } from '@/components/ui/states';
import { MetricChart } from './metric-chart';
import { ChartStats } from './chart-stats';
import { formatChartValue, isFresh, summarizeSeries } from './chart-style';
import { useNow } from './use-chart-theme';
import { computeSeriesDelta, goodDirectionFor } from './series-delta';
import type { ChartData } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';
import { Link } from 'react-router';

/**
 * Chart card: a small title, the current value large, the delta over the
 * shown window, the chart, and the peak / average / minimum strip under it.
 *
 * "Current" is earned, not assumed: the value is the newest MEASURED sample,
 * and the card calls it current (and the chart puts a dot on it) only while
 * that sample is fresh. Otherwise the value is muted and the card says how
 * old it is - an agent that stopped reporting three hours ago must not look
 * live on an open tab.
 *
 * @param to When given, the TITLE links to the metric detail. The card body
 *   deliberately does not: the plot area belongs to the chart, whose toolbar
 *   (zoom, PNG, CSV) and drag-to-pan are canvas handlers whose DOM clicks
 *   bubble - wrapping the whole card in a link meant every export also
 *   navigated away, and a pan ended on another page.
 */
export function ChartCard({ data, group, to }: { data: ChartData; group?: string; to?: string }) {
  const { t, lang } = useLanguage();
  const now = useNow();
  // Stats describe the window on screen: zooming into one hour must not
  // leave the numbers talking about the other twenty-three.
  const [zoom, setZoom] = React.useState<{ from: number; to: number } | null>(null);
  const primary = data.series[0];
  const latest = summarizeSeries(primary?.points ?? []).last;
  const fresh = isFresh(primary?.points ?? [], now);
  const hasData = data.series.some((s) => s.points.some((p) => p.v != null));

  const delta = computeSeriesDelta(primary);
  const goodDir = goodDirectionFor(primary?.tone);
  // Neutral metrics (network) carry the delta without judgmental colouring.
  const deltaGood = delta && goodDir ? delta.direction === goodDir : null;

  return (
    <Card>
      <CardHeader>
        <div className="min-w-0">
          <CardTitle className="text-muted-foreground text-xs font-medium">
            {to ? (
              <Link
                to={to}
                className="focus-visible:ring-ring hover:text-foreground rounded transition-colors focus-visible:ring-2 focus-visible:outline-none"
              >
                {data.title}
              </Link>
            ) : (
              data.title
            )}
          </CardTitle>
          {latest && (
            <div className="mt-0.5 flex items-baseline gap-2">
              <span className={cn('text-2xl font-bold tracking-tight tabular-nums', !fresh && 'text-muted-foreground')}>
                {formatChartValue(latest.v)}
                <span className="text-muted-foreground ml-1 text-sm font-medium">{primary.unit}</span>
              </span>
              {delta && (
                <span
                  className={cn(
                    'font-mono text-xs font-semibold tabular-nums',
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
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          {latest &&
            (fresh ? (
              <span className="bg-up/10 text-up inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-3xs font-semibold tracking-wide uppercase">
                <span aria-hidden="true" className="bg-up size-1.5 rounded-full motion-safe:animate-pulse" />
                {t('metric.current', 'Aktuální')}
              </span>
            ) : (
              <span
                className="text-muted-foreground inline-flex items-center gap-1 font-mono text-3xs"
                title={new Date(latest.t).toLocaleString(lang === 'en' ? 'en-GB' : 'cs-CZ')}
              >
                <Clock aria-hidden="true" className="size-3" />
                {age(now - latest.t)}
              </span>
            ))}
          {/* The forecast is computed by the server (linear regression over 7 days), not the UI. */}
          {data.daysToFull != null && (
            <Badge variant={data.daysToFull < 14 ? 'warning' : 'info'}>
              {t('chart_card.days_to_full', { days: data.daysToFull }, `Plno za ${data.daysToFull} dní`)}
            </Badge>
          )}
        </div>
      </CardHeader>
      <CardContent className="pb-4">
        {hasData ? (
          <>
            <MetricChart data={data} group={group} legend={false} onZoom={setZoom} />
            <ChartStats series={data.series} window={zoom} />
          </>
        ) : (
          // An empty chart is a legitimate response - a monitor may not report
          // this metric at all. A fabricated curve would be a lie.
          <EmptyState
            title={t('chart_card.no_data', 'Pro tuto metriku nejsou data')}
            className="grid h-[200px] place-items-center"
          />
        )}
      </CardContent>
    </Card>
  );
}

/** "7 min", "3 h", "2 d" - the same in Czech and English, so no dictionary entry. */
function age(ms: number): string {
  const min = Math.max(0, Math.round(ms / 60_000));
  if (min < 60) return `${min} min`;
  return min < 1440 ? `${Math.floor(min / 60)} h` : `${Math.floor(min / 1440)} d`;
}
