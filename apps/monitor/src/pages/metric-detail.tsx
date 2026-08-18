import * as React from 'react';
import { Link, useParams } from 'react-router';
import {
  ArrowLeft,
  Activity,
  BarChart3,
  Crosshair,
  LayoutGrid,
  Network,
  PenLine,
  StickyNote,
  Trash2,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { MetricChart } from '@/components/charts/metric-chart';
import { HeatmapPanel } from '@/components/charts/heatmap-panel';
import { HistogramPanel } from '@/components/charts/histogram-panel';
import { computeSeriesDelta, goodDirectionFor } from '@/components/charts/series-delta';
import { MetricHelpIcon } from '@/components/metric-help-icon';
import { ProcessCulprits } from '@/components/process-culprits';
import { resolveSource } from '@/api/source';
import { appApi, type ChartAnnotation } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import type {
  ChartData,
  MetricDetail,
  MetricHeatmapResponse,
  MetricRange,
  MetricSeriesResponse,
  MetricTone,
} from '@/api/types';
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
  const { t, lang } = useLanguage();
  const { isAdmin } = useSession();

  const monId = Number(monitorId);
  const metric = String(metricKey ?? '');
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';

  const [range, setRange] = React.useState<MetricRange>('24h');
  // The moment the user clicked in the chart - the answer to "what caused it".
  const [pickedAt, setPickedAt] = React.useState<number | null>(null);
  const [detail, setDetail] = React.useState<MetricDetail | null>(null);
  const [series, setSeries] = React.useState<MetricSeriesResponse | null>(null);
  const [heatmap, setHeatmap] = React.useState<MetricHeatmapResponse | null>(null);
  const [heatmapFailed, setHeatmapFailed] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  // Chart notes. `null` = not loaded (the list stays hidden), [] = none exist.
  const [anns, setAnns] = React.useState<ChartAnnotation[] | null>(null);
  const [annMode, setAnnMode] = React.useState(false);
  // The clicked moment a note is being written for (unix seconds).
  const [annDraftTs, setAnnDraftTs] = React.useState<number | null>(null);
  const [annText, setAnnText] = React.useState('');
  const [annBusy, setAnnBusy] = React.useState(false);
  const [annError, setAnnError] = React.useState<string | null>(null);

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

  // The heatmap has its own fixed window (30 days of raw samples) - switching
  // the range picker must not repaint it, so it loads independently.
  React.useEffect(() => {
    let active = true;
    setHeatmap(null);
    setHeatmapFailed(false);
    resolveSource()
      .then(({ source }) => source.getMetricHeatmap(monId, metric, 30))
      .then((h) => {
        if (active) setHeatmap(h);
      })
      .catch(() => {
        // Its own failure flag: rendering a loading state forever would claim
        // the data is on its way when it is not.
        if (active) setHeatmapFailed(true);
      });
    return () => {
      active = false;
    };
  }, [monId, metric]);

  const loadAnnotations = React.useCallback(() => {
    appApi
      .getAnnotations(monId, metric, RANGE_HOURS[range])
      .then(setAnns)
      // `null` keeps the list hidden - an empty list would claim "no notes"
      // about a window nobody could read.
      .catch(() => setAnns(null));
  }, [monId, metric, range]);

  React.useEffect(() => {
    setAnns(null);
    loadAnnotations();
  }, [loadAnnotations]);

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
        annotations: (anns ?? []).map((a) => ({
          t: a.ts * 1000,
          // The author belongs in the tooltip: a note is a claim and a claim has a claimant.
          label: a.author ? `${a.note} (${a.author})` : a.note,
        })),
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
        {isAdmin && points.length > 0 && (
          <div className="flex items-center justify-end gap-2">
            {annMode && !annDraftTs && (
              <span className="text-muted-foreground text-[11px]">
                {t('ann.mode_hint', 'Klikněte do grafu na okamžik, ke kterému poznámka patří')}
              </span>
            )}
            <Button
              size="sm"
              variant={annMode ? 'primary' : 'outline'}
              aria-pressed={annMode}
              onClick={() => {
                setAnnMode((m) => !m);
                setAnnDraftTs(null);
                setAnnError(null);
              }}
              className="gap-1.5 text-xs"
            >
              <PenLine className="size-3.5" />
              {annMode ? t('ann.mode_cancel', 'Zrušit režim poznámky') : t('ann.add', 'Přidat poznámku')}
            </Button>
          </div>
        )}

        {chartData && points.length > 0 ? (
          <div className={cn(annMode && 'cursor-crosshair')}>
            <MetricChart
              data={chartData}
              height={340}
              minimap={!['15m', '1h', '6h'].includes(range)}
              onPickTime={(ms) => {
                if (annMode) {
                  setAnnDraftTs(Math.round(ms / 1000));
                  setAnnError(null);
                } else {
                  setPickedAt(Math.round(ms / 1000));
                }
              }}
            />
          </div>
        ) : (
          <div className="text-muted-foreground grid h-[340px] place-items-center text-center text-xs">
            {series === null
              ? t('metric.loading', 'Načítám měření…')
              : t('metric.no_data', 'Pro tuto metriku a období nejsou naměřená data')}
          </div>
        )}

        {annDraftTs !== null && (
          <form
            className="space-y-2 rounded-lg border border-border p-3"
            onSubmit={(e) => {
              e.preventDefault();
              const note = annText.trim();
              if (!note || annBusy) return;
              setAnnBusy(true);
              setAnnError(null);
              appApi
                .saveAnnotation({
                  monitor_id: monId,
                  metric_key: metric,
                  timestamp: new Date(annDraftTs * 1000).toISOString(),
                  note,
                })
                .then(() => {
                  setAnnDraftTs(null);
                  setAnnText('');
                  setAnnMode(false);
                  loadAnnotations();
                })
                .catch((err: unknown) => {
                  setAnnError(
                    err instanceof Error ? err.message : t('ann.save_failed', 'Poznámku se nepodařilo uložit.')
                  );
                })
                .finally(() => setAnnBusy(false));
            }}
          >
            <p className="text-xs">
              {t('ann.at', 'Poznámka k okamžiku')}{' '}
              <span className="font-semibold tabular-nums">{new Date(annDraftTs * 1000).toLocaleString(locale)}</span>
            </p>
            <input
              autoFocus
              value={annText}
              onChange={(e) => setAnnText(e.target.value)}
              maxLength={500}
              placeholder={t('ann.placeholder', 'Co se v tu chvíli stalo (deploy, výměna disku, změna konfigurace…)')}
              className="w-full rounded-md border border-border bg-background px-2.5 py-1.5 text-xs"
            />
            {annError && <p className="text-destructive text-xs font-semibold">{annError}</p>}
            <div className="flex gap-2">
              <Button type="submit" size="sm" disabled={!annText.trim() || annBusy} className="text-xs">
                {annBusy ? t('ann.saving', 'Ukládám…') : t('ann.save', 'Uložit poznámku')}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                className="text-xs"
                onClick={() => {
                  setAnnDraftTs(null);
                  setAnnError(null);
                }}
              >
                {t('common.cancel', 'Zrušit')}
              </Button>
            </div>
          </form>
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

      {/* Chart notes. Only rendered once the list actually loaded - `anns`
          staying null means the window could not be read, and "no notes"
          must not be claimed about it. */}
      {anns !== null && anns.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <StickyNote className="size-4 text-primary" />
            {t('ann.list_title', 'Poznámky v grafu')}
          </h2>
          <ul className="space-y-2">
            {anns.map((a) => (
              <li key={a.id} className="flex items-start gap-3 rounded-lg border border-border px-3 py-2 text-xs">
                <span className="text-muted-foreground shrink-0 tabular-nums">
                  {new Date(a.ts * 1000).toLocaleString(locale)}
                </span>
                <span className="min-w-0 flex-1 break-words">{a.note}</span>
                {a.author && <span className="text-muted-foreground shrink-0">{a.author}</span>}
                {isAdmin && (
                  <button
                    type="button"
                    aria-label={t('ann.delete', 'Smazat poznámku')}
                    title={t('ann.delete', 'Smazat poznámku')}
                    className="text-muted-foreground hover:text-destructive shrink-0 transition-colors"
                    onClick={() => {
                      appApi
                        .deleteAnnotation(a.id)
                        .then(loadAnnotations)
                        .catch(() => loadAnnotations());
                    }}
                  >
                    <Trash2 className="size-3.5" />
                  </button>
                )}
              </li>
            ))}
          </ul>
          <p className="text-muted-foreground text-[11px]">
            {t(
              'ann.legend_note',
              'Poznámky se v grafu kreslí jako plné svislé čáry vlastní barvou; tečkované čáry jsou naměřené události (výpadky, restarty).'
            )}
          </p>
        </Card>
      )}

      {/* Daily rhythm - its own 30-day window on purpose, see the effect above. */}
      <Card className="space-y-3 p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <LayoutGrid className="size-4 text-primary" />
          {t('metric.heatmap_title', 'Denní rytmus (30 dní)')}
        </h2>
        {heatmap !== null ? (
          <HeatmapPanel
            data={heatmap}
            tone={tone}
            unit={unit}
            convert={isRate ? (v) => convertRate(v, activeUnit) : undefined}
          />
        ) : (
          <div className="text-muted-foreground grid h-32 place-items-center text-xs">
            {heatmapFailed
              ? t('metric.heatmap_failed', 'Heatmapu se nepodařilo načíst')
              : t('metric.loading', 'Načítám měření…')}
          </div>
        )}
        <p className="text-muted-foreground text-[11px] leading-relaxed">
          {t(
            'metric.heatmap_note',
            'Jedno pole je průměr jedné hodiny (u počítadel přírůstek za hodinu). Barevná škála jde od nejnižší po nejvyšší naměřenou hodnotu (viz čísla u legendy), ne od nuly - jinak by se u metriky kolísající v úzkém pásmu žádný rytmus neukázal. Okno je vždy posledních 30 dní bez ohledu na zvolené období grafu - starší syrová měření se mažou.'
          )}
        </p>
      </Card>

      {/* Value distribution - the average of a bimodal load lies, the histogram does not. */}
      <Card className="space-y-3 p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <BarChart3 className="size-4 text-primary" />
          {t('metric.hist_title', 'Rozložení hodnot')}
        </h2>
        <HistogramPanel points={points} unit={unit} tone={tone} />
        <p className="text-muted-foreground text-[11px] leading-relaxed">
          {t(
            'metric.hist_note',
            'Kolik měření zvoleného období padlo do jednotlivých pásem hodnot. Dva vrcholy znamenají střídání dvou režimů - to průměr v grafu nahoře neukáže.'
          )}
        </p>
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

/**
 * How far back to ask for chart notes so every note visible in the chart's
 * window is also in the list. Sub-day ranges still ask for a full day -
 * a note from this morning is worth seeing next to a 15-minute view.
 */
const RANGE_HOURS: Record<MetricRange, number> = {
  '15m': 24,
  '1h': 24,
  '6h': 24,
  '24h': 24,
  '7d': 7 * 24,
  '30d': 30 * 24,
  '90d': 90 * 24,
  '1y': 365 * 24,
};

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
