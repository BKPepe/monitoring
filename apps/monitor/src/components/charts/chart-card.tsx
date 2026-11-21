import * as React from 'react';
import { Clock } from 'lucide-react';
import { CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { EmptyState } from '@/components/ui/states';
import { DeltaChip } from '@/components/stat-block';
import { ChartMenu, type MetricChartActions } from './chart-menu';
import { MetricChart } from './metric-chart';
import { ChartStats } from './chart-stats';
import { bandCaption, displayData, formatChartValue, isFresh, summarizeSeries } from './chart-style';
import { useNow } from './use-chart-theme';
import type { ChartData } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { trendDelta } from '@/lib/trend';
import { cn } from '@/lib/utils';
import { Link } from 'react-router';

/**
 * Chart card: a small title, the current value large, its trend against the
 * same window one period earlier, the chart, and the peak / average /
 * minimum strip under it. The header also carries what used to be drawn onto
 * the canvas: the band names (they sat on the line, charts-20) and the
 * PNG / CSV / reset actions in a "⋯" menu (the toolbox covered the legend,
 * charts-11).
 *
 * "Current" is earned, not assumed: the value is the newest MEASURED sample,
 * and the card calls it current (and the chart puts a dot on it) only while
 * that sample is fresh. Otherwise the value is muted and the card says how
 * old it is - an agent that stopped reporting three hours ago must not look
 * live on an open tab.
 *
 * @param to When given, the TITLE links to the metric detail. The card body
 *   deliberately does not: the plot area belongs to the chart, whose
 *   drag-to-pan is a canvas handler whose DOM clicks bubble - wrapping the
 *   whole card in a link meant a pan ended on another page.
 */
export function ChartCard({ data: raw, group, to }: { data: ChartData; group?: string; to?: string }) {
  const { t, lang } = useLanguage();
  const now = useNow();
  const actionsRef = React.useRef<MetricChartActions>(null);
  // The headline, the stats and the chart speak one unit (Mbit/s, not KB/s).
  const data = React.useMemo(() => displayData(raw), [raw]);
  // Stats describe the window on screen: zooming into one hour must not
  // leave the numbers talking about the other twenty-three.
  const [zoom, setZoom] = React.useState<{ from: number; to: number } | null>(null);
  const primary = data.series[0];
  const latest = summarizeSeries(primary?.points ?? []).last;
  const fresh = isFresh(primary?.points ?? [], now);
  const hasData = data.series.some((s) => s.points.some((p) => p.v != null));

  // Against the same window one period earlier (the server's previousAvg),
  // coloured only when the change crossed one of the monitor's limits. The
  // end-of-window-vs-start comparison it replaces read evening against night.
  const bands = data.bands ?? [];
  const delta = primary
    ? trendDelta({
        metricKey: primary.key,
        unit: primary.unit,
        current: primary.points.map((p) => p.v),
        previous: primary.previousAvg,
        thresholds: {
          warning: bands.find((b) => b.tone === 'warning')?.from ?? null,
          critical: bands.find((b) => b.tone === 'critical')?.from ?? null,
        },
      })
    : null;
  const caption = bandCaption(data, lang, t);

  return (
    // The kit's panel surface (NetPulse look): card ground, top sheen, soft shadow.
    <Panel padding="none">
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
              <span className={cn('figure text-2xl font-semibold', !fresh && 'text-muted-foreground')}>
                {formatChartValue(latest.v, primary.unit === 's' ? 's' : '', lang)}
                {primary.unit !== 's' && (
                  <span className="text-muted-foreground ml-1 font-sans text-sm font-medium">{primary.unit}</span>
                )}
              </span>
              {delta && <DeltaChip delta={delta} />}
            </div>
          )}
          {caption && <p className="text-muted-foreground mt-0.5 text-2xs">{caption}</p>}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          {latest &&
            (fresh ? (
              <Pill tone="up" dot pulse size="sm">
                {t('metric.current', 'Aktuální')}
              </Pill>
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
            <Pill tone={data.daysToFull < 14 ? 'warning' : 'info'} size="sm">
              {t('chart_card.days_to_full', { days: data.daysToFull }, `Plno za ${data.daysToFull} dní`)}
            </Pill>
          )}
        </div>
        {hasData && <ChartMenu actions={actionsRef} />}
      </CardHeader>
      <CardContent className="pb-4">
        {hasData ? (
          <>
            <MetricChart data={data} group={group} legend={false} onZoom={setZoom} actionsRef={actionsRef} />
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
    </Panel>
  );
}

/** "7 min", "3 h", "2 d" - the same in Czech and English, so no dictionary entry. */
function age(ms: number): string {
  const min = Math.max(0, Math.round(ms / 60_000));
  if (min < 60) return `${min} min`;
  return min < 1440 ? `${Math.floor(min / 60)} h` : `${Math.floor(min / 1440)} d`;
}
