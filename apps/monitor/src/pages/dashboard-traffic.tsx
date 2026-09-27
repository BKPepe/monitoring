import * as React from 'react';
import { Activity, Gauge } from 'lucide-react';
import { resolveSource } from '@/api/source';
import type { ChartData, MetricPoint } from '@/api/types';
import { MetricChart } from '@/components/charts/metric-chart';
import { RangePills } from '@/components/charts/range-pills';
import { displayData, isFresh, summarizeSeries } from '@/components/charts/chart-style';
import { formatNumber } from '@/lib/metric-format';
import { useNow } from '@/components/charts/use-chart-theme';
import { StatBlock, StatRow } from '@/components/stat-block';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { EmptyState, ErrorState, Skeleton } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { windowFor } from '@/lib/chart-window';
import { insertGaps } from '@/lib/series-gaps';
import { metricTone } from '@/lib/metric-tone';

/**
 * The dashboard's traffic card, in its own chunk: the chart library is not on
 * the dashboard's critical path, which has a byte budget (check:bundle) - the
 * first screen is the ring and the lists.
 *
 * One device's series, and the card says which: the WAN throughput of the
 * first router, titled with the server's own name for that metric, or the
 * response time of one monitor when there is no router. A traffic figure with
 * no device named under it would pass one router's line off as the whole
 * network's.
 */
export type TrafficSource = { id: number; name: string; metric: 'net' | 'response_time' };

const RANGES = ['1h', '24h', '7d', '30d'] as const;
type Range = (typeof RANGES)[number];

/** An answer and the question it answers: a stale answer reads as "loading" for the new question. */
type Answer = { key: string } & ({ status: 'failed' } | { status: 'ok'; chart: ChartData });
type State = { status: 'loading' } | { status: 'failed' } | { status: 'ok'; chart: ChartData };

export default function DashboardTraffic({
  source,
  gatewayLatencyMs,
}: {
  source: TrafficSource;
  /** The router's latest echo to its gateway (ms), from its last report; null = not reported. */
  gatewayLatencyMs: number | null;
}) {
  const { t, lang } = useLanguage();
  const now = useNow();
  const [range, setRange] = React.useState<Range>('24h');
  const [answer, setAnswer] = React.useState<Answer | null>(null);
  const [attempt, setAttempt] = React.useState(0);
  const traffic = source.metric === 'net';
  const title = traffic ? t('dashboard.traffic_title', 'Síťový provoz na WAN') : t('dashboard.latency_title', 'Odezva');
  const key = `${source.id}|${source.metric}|${range}|${attempt}`;
  const state: State = answer && answer.key === key ? answer : { status: 'loading' };

  React.useEffect(() => {
    let active = true;
    resolveSource()
      .then(({ source: api }) => api.getMetricSeries(source.id, source.metric, range))
      .then((res) => {
        if (!active) return;
        // Timestamps in ms and nulls kept: a silent hour is a gap, not a line.
        const points: MetricPoint[] = insertGaps(res.points.map(([ts, v]) => ({ t: ts * 1000, v })));
        setAnswer({
          key,
          status: 'ok',
          chart: {
            id: source.metric,
            title,
            window: windowFor(range, Date.now()),
            yMax: null,
            yMin: 0,
            series: [
              {
                key: source.metric,
                label: res.label || title,
                unit: res.unit ?? '',
                tone: metricTone(source.metric),
                points,
              },
            ],
          },
        });
      })
      .catch(() => {
        if (active) setAnswer({ key, status: 'failed' });
      });
    return () => {
      active = false;
    };
  }, [key, source.id, source.metric, range, title]);

  const shown = state.status === 'ok' ? displayData(state.chart) : null;
  const primary = shown?.series[0];
  const summary = primary ? summarizeSeries(primary.points) : null;
  const fresh = primary ? isFresh(primary.points, now) : false;
  const unit = primary?.unit ?? '';
  // One decimal: a minute average of a line does not have two.
  const fmt = (v: number | null | undefined) => (v == null ? null : formatNumber(v, lang, v >= 100 ? 0 : 1));

  return (
    <Panel
      title={title}
      icon={traffic ? Activity : Gauge}
      // The source device, always: see the component comment.
      hint={source.name}
      chip={
        fresh ? (
          <Pill tone="up" dot pulse>
            {t('fresh.live', 'Živě')}
          </Pill>
        ) : undefined
      }
      // Beside the title from sm; on a phone the pills squeezed the title into
      // three lines, so there they open the body instead (one of the two is
      // display:none at any width, so a screen reader meets one control).
      action={
        <div className="hidden sm:block">
          <RangePills
            value={range}
            options={RANGES}
            onChange={setRange}
            label={t('asset.time_range', 'Časový rozsah')}
          />
        </div>
      }
      className="h-full"
    >
      <div className="mb-3 sm:hidden">
        <RangePills value={range} options={RANGES} onChange={setRange} label={t('asset.time_range', 'Časový rozsah')} />
      </div>
      {state.status === 'loading' ? (
        <div aria-busy="true" className="flex flex-col gap-4">
          <Skeleton className="h-72 w-full" />
          <span className="sr-only">{t('metric.loading', 'Načítám měření…')}</span>
        </div>
      ) : state.status === 'failed' ? (
        <ErrorState
          message={t('dashboard.traffic_failed', 'Průběh se nepodařilo načíst.')}
          onRetry={() => setAttempt((n) => n + 1)}
        />
      ) : summary && summary.count > 0 && shown ? (
        <>
          {/* Tall enough to sit level with the health card beside it. */}
          <MetricChart data={shown} height={300} legend={false} />
          <StatRow cols={4} className="border-border mt-4 border-t pt-4">
            <StatBlock
              variant="plain"
              size="xs"
              label={t('metric.peak_neutral', 'Špička')}
              value={fmt(summary.max)}
              secondary={unit}
            />
            <StatBlock
              variant="plain"
              size="xs"
              label={t('metric.average', 'Průměr')}
              value={fmt(summary.avg)}
              secondary={unit}
            />
            {/* "Now" only while the newest sample is fresh: a router that went
                quiet an hour ago has no current throughput. */}
            <StatBlock
              variant="plain"
              size="xs"
              label={t('metric.current', 'Aktuální')}
              value={fresh ? fmt(summary.last?.v) : null}
              secondary={unit}
              hint={fresh ? undefined : t('dashboard.no_fresh_sample', 'bez čerstvého měření')}
            />
            {traffic ? (
              <StatBlock
                variant="plain"
                size="xs"
                label={t('rsvc.wan_latency', 'Odezva k bráně')}
                value={gatewayLatencyMs == null ? null : formatNumber(gatewayLatencyMs, lang, 0)}
                secondary="ms"
              />
            ) : (
              <StatBlock
                variant="plain"
                size="xs"
                label={t('metric.min_neutral', 'Minimum')}
                value={fmt(summary.min)}
                secondary={unit}
              />
            )}
          </StatRow>
        </>
      ) : (
        <EmptyState boxed size="inline" title={t('metric.no_data', 'Pro tuto metriku a období nejsou naměřená data')} />
      )}
    </Panel>
  );
}
