import * as React from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import {
  ArrowLeft,
  Activity,
  BarChart3,
  Crosshair,
  LayoutGrid,
  Network,
  GitCompareArrows,
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
import { CorrelationPanel } from '@/components/charts/correlation-panel';
import { computeSeriesDelta, goodDirectionFor } from '@/components/charts/series-delta';
import { MetricHelpIcon } from '@/components/metric-help-icon';
import { ProcessCulprits } from '@/components/process-culprits';
import { resolveSource } from '@/api/source';
import { appApi, type ChartAnnotation } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import type {
  ChartData,
  MetricCorrelationsResponse,
  MetricDetail,
  MetricHeatmapResponse,
  MetricRange,
  MetricSeriesResponse,
  MetricTone,
} from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { convertRate, formatRate, isRateMetric, suggestRateUnit, RATE_UNITS, type RateUnit } from '@/lib/rate-units';
import { insertGaps, medianStep } from '@/lib/series-gaps';
import { percentile } from '@/lib/percentiles';
import { metricHelp } from '@/lib/metric-help';
import { betterDirection } from '@/lib/metric-direction';
import { metricVerdict } from '@/lib/metric-verdict';
import { rateSignalMetric, signalTone } from '@/lib/signal-quality';
import { signalAdvice, signalLevelLabel } from '@/lib/signal-texts';
import { Badge } from '@/components/ui/badge';
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

  /**
   * The period lives in the address, not in component state.
   *
   * A reload, the browser's back button or a link sent to a colleague used to
   * land on the default 24 hours - so "look at this, the spike on the 30-day
   * chart" could not be shared at all, and drilling from one metric to another
   * silently reset the window the operator had chosen.
   */
  const [searchParams, setSearchParams] = useSearchParams();
  const rangeParam = searchParams.get('range');
  const range: MetricRange = isKnownRange(rangeParam) ? rangeParam : '24h';
  const setRange = React.useCallback(
    (next: MetricRange) => {
      const params = new URLSearchParams(searchParams);
      params.set('range', next);
      // replace: switching a period is not a place in history to go back to.
      setSearchParams(params, { replace: true });
    },
    [searchParams, setSearchParams]
  );
  // The moment the user clicked in the chart - the answer to "what caused it".
  const [pickedAt, setPickedAt] = React.useState<number | null>(null);
  /**
   * Which metric+period the user expanded the correlation list for. Storing the
   * question rather than a boolean means switching metric or period is back to
   * the short list on its own, with no effect resetting state after a render.
   */
  const [corrAllFor, setCorrAllFor] = React.useState<string | null>(null);
  const corrQuestion = `${monId}|${metric}|${range}`;
  const corrAll = corrAllFor === corrQuestion;
  const [detail, setDetail] = React.useState<MetricDetail | null>(null);
  const [series, setSeries] = React.useState<MetricSeriesResponse | null>(null);
  /** When the series was received, so "it stops early" is judged against a fixed moment. */
  const [seriesAt, setSeriesAt] = React.useState<number | null>(null);
  const [heatmap, setHeatmap] = React.useState<MetricHeatmapResponse | null>(null);
  const [heatmapFailed, setHeatmapFailed] = React.useState(false);
  const [corr, setCorr] = React.useState<MetricCorrelationsResponse | null>(null);
  // `null` while loading, `false` once we know this metric cannot have any
  // (response_time is not stored alongside the agent's metrics).
  const [corrAvailable, setCorrAvailable] = React.useState<boolean | null>(null);
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
        if (!active) return;
        setSeries(s);
        // When the data arrived. Used to tell "the series stops early" from
        // "the window simply ends here" without reading the clock during a
        // render, which is not allowed and would not be stable anyway.
        setSeriesAt(Date.now());
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

  // Correlations follow the range picker: "what moved with this" over the last
  // hour and over the last month are different questions.
  React.useEffect(() => {
    let active = true;
    setCorr(null);
    setCorrAvailable(null);
    resolveSource()
      .then(({ source }) => source.getMetricCorrelations(monId, metric, range, corrAll))
      .then((c) => {
        if (!active) return;
        setCorr(c);
        setCorrAvailable(true);
      })
      .catch(() => {
        // Either the metric is out of scope or the request failed; both mean
        // the panel has nothing truthful to show, so it stays hidden.
        if (active) setCorrAvailable(false);
      });
    return () => {
      active = false;
    };
  }, [monId, metric, range, corrAll]);

  // Periods the primary link was down, shaded on the two link charts. Bytes
  // drawn inside them could not have gone over the primary line; whether the
  // backup carried them is what net_lte shows. Only routers have them.
  const [linkPeriods, setLinkPeriods] = React.useState<{ from: number; to: number }[] | undefined>(undefined);
  const monitorType = detail?.monitor.type ?? null;
  React.useEffect(() => {
    let active = true;
    setLinkPeriods(undefined);
    if (monitorType !== 'openwrt' || (metric !== 'net' && metric !== 'net_lte')) return;
    resolveSource()
      .then(({ source }) => source.getLinkTraffic(monId, 30))
      .then((lt) => {
        if (!active) return;
        const now = Date.now();
        const windowStart = now - lt.days * 86400 * 1000;
        setLinkPeriods(
          lt.wan_down_periods.map((p) => ({
            from: p.from != null ? p.from * 1000 : windowStart,
            to: p.to != null ? p.to * 1000 : now,
          }))
        );
      })
      .catch(() => {
        // A failed request is not "never on the backup" - the shading simply stays off.
        if (active) setLinkPeriods(undefined);
      });
    return () => {
      active = false;
    };
  }, [monId, metric, monitorType]);

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

  // insertGaps here for the same reason as in http-source: the endpoint only
  // returns rows that were measured, so a silent agent looks like a straight
  // line between the last sample before it and the first one after.
  const rawPoints = React.useMemo(
    () => insertGaps((series?.points ?? []).map(([ts, v]) => ({ t: ts * 1000, v }))),
    [series]
  );
  /**
   * Breaks in the window: the marked holes, plus one when the series simply
   * stops before the end. `null` when there is not enough data to say - a
   * window nobody measured used to print "0", an affirmative claim that
   * measurement was never interrupted.
   */
  const gapCount = React.useMemo(() => {
    const measured = rawPoints.filter((p) => p.v != null);
    if (measured.length < 2) return null;
    const marked = rawPoints.filter((p) => p.v == null).length;
    const step = medianStep(measured);
    const last = measured[measured.length - 1];
    const stopsEarly = step != null && seriesAt != null && seriesAt - last.t > Math.max(step * 2.5, 90_000);
    return marked + (stopsEarly ? 1 : 0);
  }, [rawPoints, seriesAt]);
  /**
   * When the window peaked - the moment worth asking "what was running" about.
   * Only on raw samples: on the 90d/1y rollup a point is a whole day stamped
   * at midnight, and opening the process list there would attribute whatever
   * ran at 00:00 to a peak that happened at some unknown hour.
   */
  const peakAt = React.useMemo(() => {
    if (series?.dailyRange && series.dailyRange.length > 0) return null;
    let best: { t: number; v: number } | null = null;
    for (const p of rawPoints) {
      if (p.v == null) continue;
      if (!best || p.v > best.v) best = { t: p.t, v: p.v };
    }
    return best ? Math.round(best.t / 1000) : null;
  }, [rawPoints, series]);

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

  /**
   * The same metric one period earlier, laid over the current window.
   *
   * "Is this normal for a Tuesday?" had no answer in the app: there was no way
   * to put a second series on a chart at all. The comparison is its own
   * request and its timestamps are shifted forward by exactly one window, so
   * the two curves line up hour for hour. Drawn dotted and dimmed, because it
   * is another day's measurement and must never be read as this window's.
   */
  const [compare, setCompare] = React.useState(false);
  /**
   * The comparison is keyed by the question it answers. A plain state slot
   * would keep last week's curve on screen for a moment after the period or
   * the metric changes, shifted by the wrong offset - a wrong picture, not a
   * late one.
   */
  const compareKey = `${monId}|${metric}|${range}`;
  const [compareState, setCompareState] = React.useState<{
    key: string;
    series: MetricSeriesResponse;
  } | null>(null);
  React.useEffect(() => {
    if (!compare) return;
    let active = true;
    const key = compareKey;
    resolveSource()
      .then(({ source }) => source.getMetricSeries(monId, metric, range, true))
      .then((s) => {
        if (active) setCompareState({ key, series: s });
      })
      .catch(() => {
        // No comparable window is a legitimate answer - a monitor added
        // yesterday has no last week - and the overlay simply stays away.
      });
    return () => {
      active = false;
    };
  }, [compare, compareKey, monId, metric, range]);

  const comparisonSeries = React.useMemo(() => {
    const points = compareState?.key === compareKey ? compareState.series.points : null;
    if (!compare || !points?.length) return null;
    const shift = periodLengthMs(range);
    if (shift == null) return null;
    return {
      key: `${metric}-previous`,
      label: t('metric.compare_label', 'Předchozí období'),
      unit,
      tone,
      past: true,
      points: insertGaps(
        points.map(([ts, v]) => ({
          t: ts * 1000 + shift,
          v: isRate ? convertRate(v, activeUnit) : v,
        }))
      ),
    };
  }, [compare, compareState, compareKey, range, metric, unit, tone, isRate, activeUnit, t]);

  /**
   * The capacity forecast as a line: from the last measurement to the day the
   * metric would reach 100 %. The number has been on the card badge since the
   * server started returning it, but the chart - the place where a trend is
   * read - showed nothing.
   */
  const forecastSeries = React.useMemo(() => {
    const days = series?.daysToFull;
    const last = [...points].reverse().find((p) => p.v != null);
    if (typeof days !== 'number' || days <= 0 || !last || last.v == null) return null;
    return {
      key: `${metric}-forecast`,
      label: t('metric.forecast_label', { days }, `Odhad zaplnění (za ${days} dní)`),
      unit,
      tone,
      predicted: true,
      points: [
        { t: last.t, v: last.v },
        { t: last.t + days * 86_400_000, v: 100 },
      ],
    };
  }, [series, points, metric, unit, tone, t]);

  // Memoised on its inputs: a fresh object on every render meant a fresh
  // ECharts option and setOption(notMerge), which threw away the zoom the
  // moment the user clicked the chart or typed a note.
  const chartData: ChartData | null = React.useMemo(
    () =>
      detail
        ? {
            id: `${monId}-${metric}`,
            title: detail.metric.label,
            yMax: sourceUnit === '%' ? 100 : null,
            // Percentages are read against their full scale; everything else
            // (latency, temperature, load, negative dBm) gets a derived range.
            yMin: sourceUnit === '%' ? 0 : null,
            series: [
              { key: metric, label: detail.metric.label, unit, tone, points },
              // Where this is heading, drawn as a dashed line so it can never
              // be mistaken for a measurement. Only for a metric the server
              // actually projects, and only while the projection is inside the
              // chart's own horizon.
              ...(comparisonSeries ? [comparisonSeries] : []),
              ...(forecastSeries ? [forecastSeries] : []),
            ],
            events: detail.events.map((e) => ({
              t: e.t,
              label: e.label,
              // From the recorded type, not from the words: the same event
              // reads differently in another language.
              severity: [
                'status_changed_down',
                'threshold_exceeded',
                'ssl_warning',
                'wan_lost',
                'lte_backup_lost',
                'agent_disconnected',
                'latency_degraded',
                'dns_lost',
              ].includes(e.type)
                ? ('alert' as const)
                : ('info' as const),
            })),
            annotations: (anns ?? []).map((a) => ({
              t: a.ts * 1000,
              // The author belongs in the tooltip: a note is a claim and a claim has a claimant.
              label: a.author ? `${a.note} (${a.author})` : a.note,
            })),
            bands: buildBands(detail, sourceUnit, isRate ? activeUnit : null, t),
            // The label is attached here, not in the effect - `t` changes with the
            // language and would refetch the periods and blank the shading.
            periods: linkPeriods?.map((p) => ({
              ...p,
              label: t('net.link_period_label', 'Primární linka mimo provoz'),
            })),
            // On a 90d/1y chart a point is a whole day's average. The server
            // has always sent that day's real minimum and maximum; without the
            // band a day that peaked at 100 % was drawn at its 40 % average.
            range: series?.dailyRange?.map((r) => ({
              t: r.ts * 1000,
              min: isRate ? convertRate(r.min, activeUnit) : r.min,
              max: isRate ? convertRate(r.max, activeUnit) : r.max,
              samples: r.samples,
            })),
          }
        : null,
    [
      detail,
      monId,
      metric,
      sourceUnit,
      unit,
      tone,
      points,
      anns,
      activeUnit,
      isRate,
      linkPeriods,
      series,
      forecastSeries,
      comparisonSeries,
      t,
    ]
  );

  const help = metricHelp(metric, t);
  /**
   * The window the chart is zoomed to, or null for the whole period.
   *
   * The tiles and the histogram described the whole selected period no matter
   * what the chart showed, so zooming into one hour left them talking about
   * the other twenty-three - the chart said one thing and the numbers beside
   * it another.
   */
  const [zoomWindow, setZoomWindow] = React.useState<{ from: number; to: number } | null>(null);
  const shownPoints = React.useMemo(
    () => (zoomWindow ? points.filter((p) => p.t >= zoomWindow.from && p.t <= zoomWindow.to) : points),
    [points, zoomWindow]
  );
  const stats = computeStats(shownPoints);
  const direction = betterDirection(metric);
  // Which tail is the bad one depends on the metric: for latency the high end,
  // for signal strength the low one. Labelling both "the worse end" was wrong
  // on every dBm chart in the app.
  const worseTail = direction === 'higher' ? stats.p5 : stats.p95;
  const worstValue = direction === 'higher' ? stats.min : stats.max;
  const signalRating = rateSignalMetric(metric, stats.current);
  /**
   * How many measurements the window stands on. On the 90d/1y rollup a chart
   * point is a whole day, so counting points would report a year of per-minute
   * reporting as "365 measurements"; the daily rows carry the real count.
   */
  const sampleCount = React.useMemo(() => {
    const daily = series?.dailyRange;
    if (daily && daily.length > 0) {
      return daily.reduce((sum, d) => sum + (Number.isFinite(d.samples) ? d.samples : 0), 0);
    }
    return points.filter((p) => p.v != null).length;
  }, [points, series]);
  const verdict = metricVerdict({
    metricKey: metric,
    current: stats.current,
    values: points.map((p) => p.v).filter((v): v is number => v != null),
    thresholds: detail?.thresholds ?? { warning: null, critical: null },
  });
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
          {/* What is measured, on what, and from where - visible, not hidden in
              a tooltip. "6 ms" says nothing until the page names the target and
              the vantage point, and "disk usage" until it names the partition. */}
          {help && (
            <p className="text-muted-foreground mt-1 max-w-2xl text-[11px] leading-relaxed">
              {help.what} <span className="text-foreground/80">{help.how}</span>{' '}
              {detail?.monitor.target && (
                <>
                  {t('metric.on_target', 'Cíl')}:{' '}
                  <span className="font-mono">
                    {detail.monitor.target}
                    {detail.monitor.port ? `:${detail.monitor.port}` : ''}
                  </span>
                  .{' '}
                </>
              )}
              {detail?.monitor.checkedFrom
                ? t(
                    'metric.measured_from',
                    { place: detail.monitor.checkedFrom },
                    `Měřeno z: ${detail.monitor.checkedFrom}.`
                  )
                : help.source}
              {help.caveat && <span className="text-foreground/80"> {help.caveat}</span>}
            </p>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {isRate && <UnitPicker value={activeUnit} onChange={setRateUnit} />}
          {/* Two curves on one chart: this window and the one before it, laid
              on top of each other so "is this normal for a Tuesday?" can be
              answered by looking. Only for the periods whose length is fixed -
              a rollup window is not a fixed number of days. */}
          {periodLengthMs(range) != null && (
            <Button
              size="sm"
              variant={compare ? 'primary' : 'outline'}
              aria-pressed={compare}
              onClick={() => setCompare((v) => !v)}
              className="text-xs"
            >
              {t('metric.compare_toggle', 'Porovnat s předchozím obdobím')}
            </Button>
          )}
          <RangePicker value={range} onChange={setRange} />
        </div>
      </div>

      {zoomWindow && (
        <p className="text-muted-foreground text-[11px]">
          {t(
            'metric.zoom_window',
            {
              from: new Date(zoomWindow.from).toLocaleString(locale),
              to: new Date(zoomWindow.to).toLocaleString(locale),
            },
            `Čísla níž popisují přiblížený výsek: ${new Date(zoomWindow.from).toLocaleString(locale)} až ${new Date(zoomWindow.to).toLocaleString(locale)}.`
          )}
        </p>
      )}

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
        <StatTile label={t('metric.typical', 'Obvykle (medián)')} value={stats.p50} unit={unit} />
        {/* Which tail is the bad one follows the metric. On a dBm scale p95 is
            the BEST five percent, so calling it "the worse end" - as this page
            did - was backwards. */}
        {/* Traffic and headcounts have no bad end - the direction module exists
            to refuse that judgement, so the labels must not sneak it back in. */}
        <StatTile
          label={
            direction === 'neutral'
              ? t('metric.p95_neutral', 'Horní pásmo (p95)')
              : direction === 'higher'
                ? t('metric.worse_end_low', 'Horší konec (p5)')
                : t('metric.worse_end_high', 'Horší konec (p95)')
          }
          value={direction === 'higher' ? worseTail : stats.p95}
          unit={unit}
        />
        <StatTile
          label={
            direction === 'neutral'
              ? t('metric.peak_neutral', 'Špička')
              : direction === 'higher'
                ? t('metric.worst_low', 'Nejhorší')
                : t('metric.worst_high', 'Nejhorší (špička)')
          }
          value={worstValue}
          unit={unit}
        />
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatTile label={t('metric.average', 'Průměr')} value={stats.avg} unit={unit} />
        <StatTile
          label={direction === 'neutral' ? t('metric.min_neutral', 'Minimum') : t('metric.best_high', 'Nejlepší')}
          value={direction === 'higher' ? stats.max : stats.min}
          unit={unit}
        />
        {/* A window with holes must say so - the chart shows breaks, the number
            says how many, and both come from the same points. */}
        <StatTile label={t('metric.gaps', 'Přerušení měření')} value={gapCount} unit={t('metric.gaps_unit', 'x')} />
        {/* Zero samples is "nothing was measured", which StatTile already
            renders as a dash - it must not read as a measured zero. */}
        <StatTile label={t('metric.samples', 'Měření v období')} value={sampleCount > 0 ? sampleCount : null} unit="" />
      </div>

      {/* Is that good? Answered against a threshold somebody set, against the
          metric's own physical scale, or against this window - and it says
          which of the three, because they are not the same claim. */}
      {(signalRating || (verdict && verdict.kind !== 'none')) && (
        <Card className="flex flex-wrap items-center gap-x-3 gap-y-1.5 p-4 text-xs">
          <span className="font-semibold">{t('metric.is_it_good', 'Je to v pořádku?')}</span>
          {signalRating ? (
            <>
              <Badge variant={signalTone(signalRating.level)}>{signalLevelLabel(t, signalRating.level)}</Badge>
              <span className="text-muted-foreground">
                {signalAdvice(t, signalRating.advice) || t('signal.nothing_to_do', 'Není co zlepšovat.')}
              </span>
            </>
          ) : (
            verdict && (
              <>
                <Badge variant={verdict.tone === 'neutral' ? 'info' : verdict.tone}>
                  {
                    {
                      threshold_ok: t('metric.verdict_threshold_ok', 'pod nastaveným prahem'),
                      threshold_warning: t('metric.verdict_threshold_warning', 'nad varovným prahem'),
                      threshold_critical: t('metric.verdict_threshold_critical', 'nad kritickým prahem'),
                      usual: t('metric.verdict_usual', 'v obvyklém rozmezí'),
                      unusual: t('metric.verdict_unusual', 'na horším konci období'),
                      none: t('metric.verdict_none', 'bez měřítka'),
                    }[verdict.kind]
                  }
                </Badge>
                <span className="text-muted-foreground">
                  {/* Each wording names the number it actually compared with. The
                      warning band is derived from the configured limit, and the
                      "unusual" verdict compares with the tail, not the median -
                      calling either "the usual value" stated a figure that was
                      by construction not that. */}
                  {verdict.against == null
                    ? t('metric.verdict_no_yardstick', 'Pro tuhle metriku není nastavený práh ani pevná stupnice.')
                    : verdict.kind === 'threshold_critical'
                      ? t(
                          'metric.verdict_against_limit',
                          { value: `${verdict.against} ${unit}`.trim() },
                          `Porovnáno s limitem nastaveným u monitoru (${verdict.against} ${unit}).`
                        )
                      : verdict.kind.startsWith('threshold')
                        ? t(
                            'metric.verdict_against_band',
                            {
                              band: `${verdict.against} ${unit}`.trim(),
                              limit: `${verdict.configured ?? verdict.against} ${unit}`.trim(),
                            },
                            'Porovnáno s varovným pásmem, které leží pod nastaveným limitem.'
                          )
                        : verdict.kind === 'unusual'
                          ? t(
                              'metric.verdict_against_tail',
                              { value: `${verdict.against} ${unit}`.trim() },
                              `Horší konec období je ${verdict.against} ${unit} a aktuální hodnota je za ním.`
                            )
                          : t(
                              'metric.verdict_against_window',
                              { value: `${verdict.against} ${unit}`.trim() },
                              `Porovnáno se zvoleným obdobím, kde obvyklá hodnota je ${verdict.against} ${unit}.`
                            )}
                </span>
              </>
            )
          )}
        </Card>
      )}

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
              onZoom={setZoomWindow}
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

      {/* What else moved with this metric. Hidden entirely when the metric is
          out of scope (response_time is not stored with the agent's rows) -
          an empty card would suggest "nothing correlates", which is a claim
          nobody measured. */}
      {corrAvailable !== false && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <GitCompareArrows className="size-4 text-primary" />
            {t('corr.title', 'Co se hýbalo spolu s touto metrikou')}
          </h2>
          {corr ? (
            <CorrelationPanel
              data={corr}
              assetId={assetId ?? detail?.monitor.assetId ?? undefined}
              monitorId={monId}
              showingAll={corrAll}
              onShowAll={() => setCorrAllFor(corrQuestion)}
            />
          ) : (
            <p className="text-muted-foreground text-xs">{t('metric.loading', 'Načítám měření…')}</p>
          )}
        </Card>
      )}

      {/* Value distribution - the average of a bimodal load lies, the histogram does not. */}
      <Card className="space-y-3 p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <BarChart3 className="size-4 text-primary" />
          {t('metric.hist_title', 'Rozložení hodnot')}
        </h2>
        <HistogramPanel points={shownPoints} unit={unit} tone={tone} />
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
          <p className="text-muted-foreground text-[11px]">
            {pickedAt
              ? t(
                  'culprits.at_picked_when',
                  { when: new Date(pickedAt * 1000).toLocaleString(locale) },
                  `Okamžik vybraný v grafu: ${new Date(pickedAt * 1000).toLocaleString(locale)}. Klikněte jinam pro jiný.`
                )
              : peakAt
                ? t(
                    'culprits.at_peak_when',
                    { when: new Date(peakAt * 1000).toLocaleString(locale) },
                    `Špička zvoleného období: ${new Date(peakAt * 1000).toLocaleString(locale)}. Kliknutím do grafu se podíváte jinam.`
                  )
                : t('culprits.pick_moment', 'Klikněte do grafu na okamžik, který vás zajímá.')}
          </p>
          {/* The question is "what caused that peak", so the peak is where this
              starts. It used to render nothing until the user discovered that
              the chart is clickable. */}
          <ProcessCulprits monitorId={monId} kind={metric === 'ram' ? 'ram' : 'cpu'} at={pickedAt ?? peakAt} />
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
 * How long a period lasts, in milliseconds - the offset that lays the previous
 * window over the current one. The long ranges are daily rollups whose windows
 * are not a fixed number of days, so they get no comparison rather than a
 * misaligned one.
 */
function periodLengthMs(range: MetricRange): number | null {
  const minutes: Record<string, number> = { '15m': 15, '1h': 60, '6h': 360, '24h': 1440, '7d': 10080, '30d': 43200 };
  const m = minutes[range];
  return m == null ? null : m * 60_000;
}

/** A period from the address is user input: anything unknown falls back. */
function isKnownRange(value: string | null): value is MetricRange {
  return value !== null && (RANGES as string[]).includes(value);
}

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
  p50: number | null;
  p95: number | null;
  p99: number | null;
  p5: number | null;
} {
  const values = points.map((p) => p.v).filter((v): v is number => v != null);
  if (values.length === 0) {
    return { current: null, avg: null, min: null, max: null, p50: null, p95: null, p99: null, p5: null };
  }
  const round = (n: number) => Math.round(n * 100) / 100;
  const pct = (p: number) => {
    const v = percentile(values, p);
    return v == null ? null : round(v);
  };
  return {
    current: round(values[values.length - 1]),
    avg: round(values.reduce((s, v) => s + v, 0) / values.length),
    min: round(Math.min(...values)),
    max: round(Math.max(...values)),
    // Percentiles from the SAME array as the tiles above, so a chart and its
    // numbers can never disagree. Average plus peak was the least informative
    // pair available for latency: one timeout owns the peak, the average hides
    // the tail.
    p50: pct(50),
    p95: pct(95),
    p99: pct(99),
    // The bad tail of a metric where MORE is better (signal strength, free
    // memory): there p95 is the good end and p5 is the one that hurts.
    p5: pct(5),
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
