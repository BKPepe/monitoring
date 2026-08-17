import * as React from 'react';
import { Link, useParams } from 'react-router';
import { ArrowLeft, Activity, Crosshair, Network, TrendingDown, TrendingUp } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { MetricChart } from '@/components/charts/metric-chart';
import { computeSeriesDelta, goodDirectionFor } from '@/components/charts/series-delta';
import { MetricHelpIcon } from '@/components/metric-help-icon';
import { ProcessCulprits } from '@/components/process-culprits';
import { resolveSource } from '@/api/source';
import type { ChartData, MetricDetail, MetricRange, MetricSeriesResponse, MetricTone } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { convertRate, formatRate, isRateMetric, suggestRateUnit, RATE_UNITS, type RateUnit } from '@/lib/rate-units';
import { cn } from '@/lib/utils';

/**
 * Level 3 - detail of a single metric.
 *
 * Until now this existed only in the legacy page (`index.php?view=metric`),
 * with no link to it from this app - the feature that got the most design
 * thought was unreachable from /app.
 *
 * It has three layers, deliberately in this order:
 *   1. What is happening - numbers before the chart. Most people do not read a
 *      chart, they read a value.
 *   2. Why - the chart with events and threshold bands.
 *   3. What to do - related metrics of the same device, so you need not go back up.
 */
export function MetricDetailPage() {
  const { assetId, monitorId, metricKey } = useParams();
  const { t } = useLanguage();

  const monId = Number(monitorId);
  const metric = String(metricKey ?? '');

  const [range, setRange] = React.useState<MetricRange>('24h');
  // The moment the user clicked in the chart - the answer to "what caused it".
  const [pickedAt, setPickedAt] = React.useState<number | null>(null);
  const [detail, setDetail] = React.useState<MetricDetail | null>(null);
  const [series, setSeries] = React.useState<MetricSeriesResponse | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  // The context (metric description, thresholds, events) loads once - it does
  // not depend on the period. The series loads separately so switching the
  // range does not repaint the whole page.
  React.useEffect(() => {
    let active = true;
    setDetail(null);
    setError(null);
    resolveSource()
      .then(({ source }) => source.getMetricDetail(monId, metric))
      .then((d) => {
        if (active) setDetail(d);
      })
      .catch((e: unknown) => {
        if (active) setError(e instanceof Error ? e.message : String(e));
      });
    return () => {
      active = false;
    };
  }, [monId, metric]);

  React.useEffect(() => {
    let active = true;
    setSeries(null);
    resolveSource()
      .then(({ source }) => source.getMetricSeries(monId, metric, range))
      .then((s) => {
        if (active) setSeries(s);
      })
      .catch((e: unknown) => {
        // A failing series does not take the page down - the context is still useful.
        if (active) setError(e instanceof Error ? e.message : String(e));
      });
    return () => {
      active = false;
    };
  }, [monId, metric, range]);

  const rawPoints = React.useMemo(() => (series?.points ?? []).map(([ts, v]) => ({ t: ts * 1000, v })), [series]);

  const tone = toneFor(metric);
  const sourceUnit = detail?.metric.unit ?? series?.unit ?? '';
  const isRate = isRateMetric(sourceUnit);

  // Agents report throughput in KB/s, which is not how anyone reads a link.
  // The initial unit follows the data (an idle link stays in KB/s, a busy one
  // opens in Mb/s); `null` from the picker means the user chose deliberately
  // and their choice must survive a period switch.
  const [rateUnit, setRateUnit] = React.useState<RateUnit | null>(null);
  const suggestedUnit = React.useMemo(() => {
    const values = rawPoints.map((p) => p.v).filter((v): v is number => v != null);
    if (values.length === 0) return 'KB/s' as RateUnit;
    return suggestRateUnit(values.reduce((sum, v) => sum + v, 0) / values.length);
  }, [rawPoints]);
  const activeUnit: RateUnit = rateUnit ?? suggestedUnit;

  const unit = isRate ? activeUnit : sourceUnit;
  const points = React.useMemo(
    () => (isRate ? rawPoints.map((p) => ({ t: p.t, v: convertRate(p.v, activeUnit) })) : rawPoints),
    [rawPoints, isRate, activeUnit]
  );

  const chartData: ChartData | null = detail
    ? {
        id: `${monId}-${metric}`,
        title: detail.metric.label,
        yMax: sourceUnit === '%' ? 100 : null,
        series: [{ key: metric, label: detail.metric.label, unit, tone, points }],
        events: detail.events.map((e) => ({ t: e.t, label: e.label })),
        bands: buildBands(detail, sourceUnit, isRate ? activeUnit : null, t),
      }
    : null;

  const stats = computeStats(points);
  const delta = computeSeriesDelta(chartData?.series[0]);
  const goodDir = goodDirectionFor(tone);
  const deltaGood = delta && goodDir ? delta.direction === goodDir : null;

  const backTo = assetId ? `/infrastructure/${assetId}` : '/infrastructure';

  const protocolSplit = (detail?.related ?? []).filter((r) => r.key === 'net_ipv4' || r.key === 'net_ipv6');

  if (error && !detail) {
    return (
      <div className="space-y-4">
        <Link
          to={backTo}
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1.5 text-xs"
        >
          <ArrowLeft className="size-3.5" /> {t('metric.back', 'Zpět na zařízení')}
        </Link>
        <Card className="p-8 text-center">
          <p className="text-sm font-semibold">{t('metric.load_failed', 'Metriku se nepodařilo načíst')}</p>
          <p className="text-muted-foreground mt-1 text-xs">{error}</p>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <Breadcrumb
        items={[
          { label: t('nav.infrastructure', 'Infrastruktura'), to: '/infrastructure' },
          { label: detail?.monitor.name ?? '…', to: backTo },
          { label: detail?.metric.label ?? metric },
        ]}
      />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
            {detail?.metric.label ?? metric}
            <MetricHelpIcon metric={metric} className="size-4" />
          </h1>
          <p className="text-muted-foreground text-xs">{detail?.monitor.name}</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {isRate && <UnitPicker value={activeUnit} onChange={setRateUnit} />}
          <RangePicker value={range} onChange={setRange} />
        </div>
      </div>

      {/* Layer 1 - what is happening. Numbers before the chart, not after it. */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label={t('metric.current', 'Aktuální')} value={stats.current} unit={unit}>
          {delta && (
            <span
              className={cn(
                'inline-flex items-center gap-1 text-xs font-semibold',
                deltaGood === null ? 'text-muted-foreground' : deltaGood ? 'text-up' : 'text-down'
              )}
            >
              {delta.direction === 'up' ? <TrendingUp className="size-3.5" /> : <TrendingDown className="size-3.5" />}
              {delta.pct} %
            </span>
          )}
        </StatTile>
        <StatTile label={t('metric.average', 'Průměr')} value={stats.avg} unit={unit} />
        <StatTile label={t('metric.peak', 'Špička')} value={stats.max} unit={unit} />
        <StatTile label={t('metric.min', 'Minimum')} value={stats.min} unit={unit} />
      </div>

      {/* Layer 2 - why. */}
      <Card className="space-y-3 p-5">
        {chartData && points.length > 0 ? (
          <MetricChart data={chartData} height={340} onPickTime={(ms) => setPickedAt(Math.round(ms / 1000))} />
        ) : (
          <div className="text-muted-foreground grid h-[340px] place-items-center text-center text-xs">
            {series === null
              ? t('metric.loading', 'Načítám měření…')
              : t('metric.no_data', 'Pro tuto metriku a období nejsou naměřená data')}
          </div>
        )}

        <div className="text-muted-foreground space-y-1 text-[11px]">
          {detail?.metric.counter && (
            <p>
              {t(
                'metric.counter_note',
                'Jde o počítadlo - graf ukazuje přírůstek mezi měřeními, ne celkovou hodnotu. Po restartu zařízení se bod přeskočí, aby nevznikla špička, která se nestala.'
              )}
            </p>
          )}
          {series?.resolution === 'daily' && (
            <p>
              {t(
                'metric.daily_note',
                'Pro období delší než 30 dní je jeden bod denní průměr - syrová měření se po 30 dnech mažou.'
              )}
            </p>
          )}
          {detail && detail.events.length > 0 && (
            <p>
              {t(
                'metric.events_note',
                { count: detail.events.length },
                `Svislé čáry v grafu jsou události (${detail.events.length} za 30 dní); najetím se zobrazí která.`
              )}
            </p>
          )}
        </div>
      </Card>

      {/* The "why" layer proper: a chart shows that CPU hit 90 % at 19:40 and
          nothing about what caused it. Only offered for metrics where a
          process can be the culprit. */}
      {(metric === 'cpu' || metric === 'ram') && detail && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Crosshair className="size-4 text-primary" />
            {t('culprits.title', 'Co v tu chvíli běželo')}
          </h2>
          <ProcessCulprits monitorId={monId} kind={metric === 'ram' ? 'ram' : 'cpu'} at={pickedAt} />
        </Card>
      )}

      {/* Protocol split. Deliberately its own block with a warning: `net` is
          measured on the WAN interface only, while the IPv4/IPv6 counters come
          from /proc/net/netstat and cover every interface including the LAN.
          Presenting them as parts of one number would show a sum that does not
          add up. */}
      {metric === 'net' && detail && protocolSplit.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Network className="size-4 text-primary" />
            {t('metric.protocol_split', 'Podle protokolu')}
          </h2>
          <div className="flex flex-wrap gap-2">
            {protocolSplit.map((r) => (
              <Link
                key={r.key}
                to={`/infrastructure/${assetId ?? detail.monitor.assetId ?? ''}/metric/${monId}/${r.key}`}
                className="hover:border-primary/60 hover:bg-secondary/50 rounded-lg border border-border px-3 py-2 text-xs transition-colors"
              >
                <span className="font-medium">{r.key === 'net_ipv4' ? 'IPv4' : 'IPv6'}</span>
                <span className="text-muted-foreground ml-2 tabular-nums">{formatRate(r.latest, activeUnit)}</span>
              </Link>
            ))}
          </div>
          <p className="text-muted-foreground text-[11px] leading-relaxed">
            {t(
              'metric.protocol_split_note',
              'Tenhle graf měří provoz na WAN rozhraní, zatímco počty podle protokolu jdou přes všechna rozhraní včetně LAN. Součet IPv4 a IPv6 proto bývá vyšší a není to chyba měření.'
            )}
          </p>
        </Card>
      )}

      {/* Layer 3 - what to do. Only metrics this device actually reports. */}
      {detail && detail.related.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Activity className="size-4 text-primary" />
            {t('metric.related', 'Další metriky tohoto zařízení')}
          </h2>
          <div className="flex flex-wrap gap-2">
            {detail.related.map((r) => (
              <Link
                key={r.key}
                to={`/infrastructure/${assetId ?? detail.monitor.assetId ?? ''}/metric/${monId}/${r.key}`}
                className="hover:border-primary/60 hover:bg-secondary/50 rounded-lg border border-border px-3 py-2 text-xs transition-colors"
              >
                <span className="font-medium">{r.label}</span>
                <span className="text-muted-foreground ml-2 tabular-nums">
                  {r.latest} {r.unit}
                </span>
              </Link>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}

function StatTile({
  label,
  value,
  unit,
  children,
}: {
  label: string;
  value: number | null;
  unit: string;
  children?: React.ReactNode;
}) {
  return (
    <Card className="p-4">
      <p className="text-muted-foreground text-[11px] font-medium">{label}</p>
      <div className="mt-1 flex items-baseline gap-2">
        {/* Unmeasured is a dash, never a zero. */}
        <span className="text-2xl font-bold tracking-tight tabular-nums">
          {value === null ? '—' : value}
          {value !== null && unit ? (
            <span className="text-muted-foreground ml-1 text-sm font-medium">{unit}</span>
          ) : null}
        </span>
        {children}
      </div>
    </Card>
  );
}

function UnitPicker({ value, onChange }: { value: RateUnit; onChange: (u: RateUnit) => void }) {
  const { t } = useLanguage();
  return (
    <label className="text-muted-foreground flex items-center gap-1.5 text-xs">
      {t('metric.unit', 'Jednotka')}
      <select
        value={value}
        onChange={(e) => onChange(e.target.value as RateUnit)}
        className="rounded-md border border-border bg-background px-2 py-1 text-xs"
      >
        {RATE_UNITS.map((u) => (
          <option key={u} value={u}>
            {u}
          </option>
        ))}
      </select>
    </label>
  );
}

const RANGES: MetricRange[] = ['15m', '1h', '6h', '24h', '7d', '30d', '90d', '1y'];

function RangePicker({ value, onChange }: { value: MetricRange; onChange: (r: MetricRange) => void }) {
  return (
    <div className="flex flex-wrap gap-1" role="group">
      {RANGES.map((r) => (
        <button
          key={r}
          type="button"
          onClick={() => onChange(r)}
          aria-pressed={value === r}
          className={cn(
            'rounded-md border px-2.5 py-1 text-xs font-medium transition-colors',
            value === r
              ? 'border-primary bg-primary/10 text-primary'
              : 'text-muted-foreground hover:text-foreground border-border'
          )}
        >
          {r}
        </button>
      ))}
    </div>
  );
}

/** Stats are computed from the very points the chart draws - never another window. */
function computeStats(points: { t: number; v: number | null }[]): {
  current: number | null;
  avg: number | null;
  min: number | null;
  max: number | null;
} {
  const values = points.map((p) => p.v).filter((v): v is number => v != null);
  if (values.length === 0) {
    return { current: null, avg: null, min: null, max: null };
  }
  const round = (n: number) => Math.round(n * 100) / 100;
  return {
    current: round(values[values.length - 1]),
    avg: round(values.reduce((s, v) => s + v, 0) / values.length),
    min: round(Math.min(...values)),
    max: round(Math.max(...values)),
  };
}

/**
 * Threshold bands. Drawn only when a threshold is actually set - otherwise a
 * coloured zone would pretend a limit nobody ever defined.
 */
function buildBands(
  detail: MetricDetail,
  sourceUnit: string,
  displayUnit: RateUnit | null,
  t: (key: string, fallback?: string) => string
): ChartData['bands'] {
  const { warning, critical } = detail.thresholds;
  if (critical === null) return undefined;

  // Thresholds are stored in the metric's own unit. When the chart is drawn in
  // a converted unit, the bands have to move with it - otherwise a 90 KB/s
  // limit would be painted at 90 Mb/s, marking a healthy link as critical.
  const scale = (v: number) => (displayUnit ? (convertRate(v, displayUnit) ?? v) : v);
  const crit = scale(critical);
  const top = sourceUnit === '%' ? 100 : crit * 2;
  const bands: NonNullable<ChartData['bands']> = [
    { from: crit, to: top, tone: 'critical', label: t('metric.band_critical', 'Kritické') },
  ];
  if (warning !== null && warning < critical) {
    bands.unshift({ from: scale(warning), to: crit, tone: 'warning', label: t('metric.band_warning', 'Varování') });
  }
  return bands;
}

/** Series colour by metric - the same as on the device cards. */
function toneFor(metric: string): MetricTone {
  if (metric.startsWith('cpu') || metric.startsWith('load')) return 'cpu';
  if (metric.startsWith('ram') || metric.startsWith('swap')) return 'memory';
  if (metric.startsWith('hdd') || metric.startsWith('disk') || metric.startsWith('inode')) return 'disk';
  if (metric.startsWith('net') || metric.startsWith('tcp') || metric.startsWith('dns')) return 'network';
  if (metric.startsWith('temp')) return 'temperature';
  return 'latency';
}
