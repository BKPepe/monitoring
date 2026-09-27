import * as React from 'react';
import { Link } from 'react-router';
import { ChevronRight, Cpu, Gauge, Globe, HardDrive, Users, Wifi } from 'lucide-react';
import type { ChartData, MetricPoint, StorageDisk, WifiRadio } from '@/api/types';
import { MetricChart } from '@/components/charts/metric-chart';
import { displayData, formatChartTime, summarizeSeries } from '@/components/charts/chart-style';
import { HealthBreakdown, HealthRing } from '@/components/health-ring';
import { HealthDeductions, type ScoredHealth } from '@/components/health-deductions';
import { RangeMeter } from '@/components/meter';
import { MonitorTypeIcon } from '@/components/monitor-type-icon';
import { StatBlock, StatRow } from '@/components/stat-block';
import { Panel } from '@/components/ui/panel';
import { Pill, type PillTone } from '@/components/ui/pill';
import { ErrorState, Skeleton } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { diskVerdict, tempLabel, tempTone } from '@/lib/disk-health';
import { formatDuration, formatNumber } from '@/lib/metric-format';
import { rateChannelBusy } from '@/lib/signal-quality';
import { signalLevelLabel } from '@/lib/signal-texts';
import { statusLabel, statusMeta, type StatusKey } from '@/lib/status';
import { wanLinkState } from '@/lib/wan-link';
import { bandLabel, radioGenerationLabel } from '@/lib/wifi-profile';
import { cn } from '@/lib/utils';

/**
 * The NetPulse "Device detail" pieces of the asset page: the header card, the
 * performance, latency and WAN panels, the Wi-Fi radios, the clients summary
 * and the storage summary. Each reads what the page already loaded (the
 * monitor row, its last report, the chart batch, monitor_insights) - nothing
 * here fetches, and nothing guesses: a value the device did not report is a
 * dash or the panel stays away.
 */

type T = ReturnType<typeof useLanguage>['t'];

const PILL: Record<ReturnType<typeof statusMeta>['variant'], PillTone> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  info: 'info',
  paused: 'paused',
  neutral: 'neutral',
};

const num = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/** monitor_insights' health: loading, failed (the request), or the answer (whose `health` may be null). */
export type AssetHealthState =
  { status: 'loading' } | { status: 'failed' } | { status: 'ok'; health: ScoredHealth | null | undefined };

// ------------------------------------------------------------------ header

/**
 * The header card (NetPulse device header): where the device sits, what it
 * is, its state in words, its actions, and the health ring with what the
 * score is made of and what took points off.
 *
 * The card itself is never toned: the state is the pill's job, and a toned
 * card would colour every figure inside it (C-1: colour only on a breach).
 */
export function AssetHeaderPanel({
  name,
  kind,
  subtitle,
  breadcrumb,
  statusKey,
  maintenance,
  archived,
  health,
  actions,
}: {
  name: string;
  kind: string;
  subtitle: string;
  breadcrumb: string[];
  statusKey: StatusKey;
  maintenance: boolean;
  archived: boolean;
  health: AssetHealthState;
  /** The desktop buttons and the phone's overflow menu. */
  actions: React.ReactNode;
}) {
  const { t } = useLanguage();
  const meta = statusMeta(statusKey);
  const scored = health.status === 'ok' ? health.health : null;
  return (
    <Panel bodyClassName="flex flex-col gap-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <nav aria-label={t('asset.breadcrumb', 'Umístění')} className="text-muted-foreground min-w-0 text-2xs">
          <ol className="flex flex-wrap items-center gap-1.5">
            <li>
              <Link
                to="/infrastructure"
                className="focus-visible:ring-ring hover:text-foreground rounded-sm font-medium focus-visible:ring-2 focus-visible:outline-none"
              >
                {t('nav.infrastructure', 'Infrastruktura')}
              </Link>
            </li>
            {breadcrumb
              .filter((c) => c !== 'Infrastructure' && c !== 'Infrastruktura')
              .map((crumb) => (
                <li key={crumb} className="flex items-center gap-1.5">
                  <span aria-hidden="true">/</span>
                  {crumb}
                </li>
              ))}
            <li className="text-foreground flex items-center gap-1.5 font-medium" aria-current="page">
              <span aria-hidden="true" className="text-muted-foreground font-normal">
                /
              </span>
              {name}
            </li>
          </ol>
        </nav>
        {actions}
      </div>

      <div className="flex flex-wrap items-center gap-4 sm:flex-nowrap">
        {/* By type, from the one shared map (V-17). */}
        <span className="bg-inset grid size-14 shrink-0 place-items-center rounded-xl border border-border">
          <MonitorTypeIcon type={kind} className="text-foreground size-6" />
        </span>
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold tracking-tight break-words">{name}</h1>
          <p className="text-muted-foreground mt-0.5 text-sm break-words">{subtitle}</p>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            {/* One status vocabulary (C-11): "Agent mlčí" and "Čeká na první data" are two states. */}
            <Pill tone={PILL[meta.variant]} dot pulse={statusKey === 'up'}>
              {statusLabel(statusKey, t)}
            </Pill>
            {maintenance && <Pill tone="info">{t('common.maintenance', 'Údržba')}</Pill>}
            {archived && <Pill tone="neutral">{t('asset.archived_pill', 'Archivováno')}</Pill>}
          </div>
        </div>
        <div className="flex w-full justify-center sm:w-auto">
          <HeaderRing health={health} name={name} />
        </div>
      </div>

      {/* What the score is made of and what took points off, under the ring's
          row (owner decision); only for a score the server could give. */}
      {scored && scored.score != null && (
        <div className="border-border grid gap-4 border-t pt-4 lg:grid-cols-2">
          <HealthBreakdown components={scored.components} />
          <HealthDeductions deductions={scored.deductions} />
        </div>
      )}
    </Panel>
  );
}

function HeaderRing({ health, name }: { health: AssetHealthState; name: string }) {
  const { t } = useLanguage();
  if (health.status === 'loading') {
    return (
      <div aria-busy="true" className="flex flex-col items-center gap-2">
        <Skeleton className="size-28 rounded-full" />
        <span className="sr-only">{t('dashboard.health_loading', 'Počítám skóre zdraví…')}</span>
      </div>
    );
  }
  // An older server that does not send the score: no ring, nothing claimed.
  if (health.status === 'ok' && health.health === undefined) return null;
  // A failed request, or a server that could not compute it: said, never a "—" ring.
  if (health.status === 'failed' || !health.health) {
    return (
      <ErrorState
        size="inline"
        className="max-w-40 text-center"
        message={t('asset.health_failed', 'Skóre zdraví se nepodařilo zjistit.')}
      />
    );
  }
  return (
    <HealthRing
      score={health.health.score}
      grade={health.health.grade}
      size="md"
      caption={t('asset.health_caption', { name }, `Zdraví: ${name}`)}
    />
  );
}

// ------------------------------------------------------------- performance

/** Peak, when it was, the average and the jitter of the measured points - nulls are not samples. */
export function seriesFacts(points: readonly MetricPoint[]) {
  const s = summarizeSeries([...points]);
  let peakAt: number | null = null;
  let prev: number | null = null;
  let jumps = 0;
  let jumpSum = 0;
  for (const p of points) {
    if (p.v == null || !Number.isFinite(p.v)) {
      // A gap breaks the chain: the step across it is not one measurement to the next.
      prev = null;
      continue;
    }
    if (s.max != null && p.v === s.max && peakAt === null) peakAt = p.t;
    if (prev != null) {
      jumps += 1;
      jumpSum += Math.abs(p.v - prev);
    }
    prev = p.v;
  }
  return { ...s, peakAt, jitter: jumps > 0 ? jumpSum / jumps : null };
}

/** The performance rows (NetPulse "Performance"): the chart, and the peak and the average of the window. */
const PERF_IDS = ['cpu', 'ram', 'temperature_c'] as const;

export function pickPerformance(cards: readonly ChartData[]): ChartData[] {
  return PERF_IDS.map((id) => cards.find((c) => c.id === id)).filter((c): c is ChartData => c != null);
}

export function PerformancePanel({
  charts,
  metricHref,
  className,
}: {
  charts: ChartData[];
  metricHref: (id: string) => string;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const locale = lang === 'en' ? 'en-GB' : 'cs-CZ';
  if (charts.length === 0) return null;
  return (
    <Panel title={t('asset.perf_title', 'Výkon')} icon={Cpu} className={className} bodyClassName="flex flex-col gap-5">
      {charts.map((raw) => {
        const chart = displayData(raw);
        const s = chart.series[0];
        const facts = seriesFacts(s?.points ?? []);
        const unit = s?.unit ?? '';
        return (
          <div
            key={chart.id}
            className="grid grid-cols-[minmax(0,1fr)_5.5rem] items-center gap-x-4 sm:grid-cols-[minmax(0,1fr)_7rem]"
          >
            <div className="min-w-0">
              {/* The row's name leads to the metric detail; the plot itself
                  belongs to the chart's own drag and tooltip. */}
              <Link
                to={metricHref(chart.id)}
                className="micro-label focus-visible:ring-ring hover:text-foreground rounded-sm focus-visible:ring-2 focus-visible:outline-none"
              >
                {chart.title}
              </Link>
              <MetricChart data={chart} height={120} legend={false} group="asset-performance" />
            </div>
            <div className="text-right">
              <p className="micro-label">{t('metric.peak_neutral', 'Špička')}</p>
              <p className="figure text-2xl font-semibold">
                {/* One decimal: "29,17 %" claimed a precision a minute average does not have. */}
                {facts.max == null ? '—' : formatNumber(facts.max, lang, 1)}
                {facts.max != null && unit && <span className="text-muted-foreground font-sans text-xs"> {unit}</span>}
              </p>
              {facts.peakAt != null && (
                <p className="text-muted-foreground text-2xs">{formatChartTime(facts.peakAt, locale)}</p>
              )}
              <p className="text-muted-foreground mt-1 text-2xs">
                {t('metric.average', 'Průměr')}{' '}
                <span className="figure text-foreground">
                  {facts.avg == null ? '—' : `${formatNumber(facts.avg, lang, 1)}${unit ? ` ${unit}` : ''}`}
                </span>
              </p>
            </div>
          </div>
        );
      })}
    </Panel>
  );
}

// ------------------------------------------------------------------ latency

/**
 * The latency panel (NetPulse "Latency"): the last measured value in a dial,
 * the trace of the window, and its average, jitter and peak.
 *
 * The dial's arc is the value against the monitor's own latency limit, and
 * only when one is set - without a limit there is no scale, so only the
 * track is drawn. Packet loss is not shown: no agent measures it (SCORE).
 */
export function LatencyPanel({
  chart,
  current,
  limitMs,
  router,
  metricHref,
  className,
}: {
  chart: ChartData | null;
  /** The last check's value in ms; null = not measured. */
  current: number | null;
  /** The latency limit configured on the monitor; null = none. */
  limitMs: number | null;
  /** A router measures the echo to its gateway, not a check from outside. */
  router: boolean;
  metricHref: (id: string) => string;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const points = chart?.series[0]?.points ?? [];
  const facts = seriesFacts(points);
  const title = router ? t('rsvc.wan_latency', 'Odezva k bráně') : t('common.response', 'Odezva');
  const share = current != null && limitMs != null && limitMs > 0 ? Math.min(1, current / limitMs) : null;
  const breach = current != null && limitMs != null && limitMs > 0 && current >= limitMs;
  const r = 44;
  const length = 2 * Math.PI * r;
  return (
    <Panel title={title} icon={Gauge} className={className} bodyClassName="flex flex-col gap-4">
      <div className="flex flex-col items-center gap-1">
        <div
          role="img"
          aria-label={
            current == null
              ? t('asset.latency_aria_none', { name: title }, `${title}: neměřeno`)
              : t(
                  'asset.latency_aria',
                  { name: title, value: formatNumber(current, lang, 0) },
                  `${title}: ${current} ms`
                )
          }
          className="relative size-32"
        >
          <svg viewBox="0 0 100 100" className="size-full -rotate-90" aria-hidden="true">
            <circle cx="50" cy="50" r={r} fill="none" strokeWidth="7" className="stroke-border-strong" />
            {share != null && share > 0 && (
              <circle
                cx="50"
                cy="50"
                r={r}
                fill="none"
                stroke="currentColor"
                strokeWidth="7"
                strokeLinecap="round"
                strokeDasharray={length}
                strokeDashoffset={length * (1 - share)}
                className={cn('chart-glow', breach ? 'text-down' : 'text-chart-latency')}
              />
            )}
          </svg>
          <div aria-hidden="true" className="absolute inset-0 grid place-items-center">
            <div className="flex flex-col items-center leading-none">
              <span className={cn('figure text-3xl font-semibold', current == null && 'text-muted-foreground')}>
                {current == null ? '—' : formatNumber(current, lang, 0)}
              </span>
              <span className="text-muted-foreground mt-1 text-2xs">ms</span>
            </div>
          </div>
        </div>
        <p className="text-muted-foreground text-2xs">
          {limitMs != null && limitMs > 0
            ? t('asset.latency_limit', { limit: formatNumber(limitMs, lang, 0) }, `limit ${limitMs} ms`)
            : t('asset.latency_last', 'poslední měření')}
        </p>
      </div>
      {chart && points.some((p) => p.v != null) && (
        <div className="min-w-0">
          <Link
            to={metricHref(chart.id)}
            className="micro-label focus-visible:ring-ring hover:text-foreground rounded-sm focus-visible:ring-2 focus-visible:outline-none"
          >
            {chart.title}
          </Link>
          <MetricChart data={chart} height={110} legend={false} group="asset-performance" />
        </div>
      )}
      <StatRow cols={3} className="border-border border-t pt-3">
        <StatBlock
          variant="plain"
          size="xs"
          label={t('metric.average', 'Průměr')}
          value={facts.avg == null ? null : formatNumber(facts.avg, lang, 0)}
          secondary="ms"
        />
        {/* Jitter: the average change from one measurement to the next, gaps excluded. */}
        <StatBlock
          variant="plain"
          size="xs"
          label={
            <span title={t('asset.jitter_title', 'Průměrná změna mezi dvěma po sobě jdoucími měřeními')}>
              {t('asset.jitter', 'Jitter')}
            </span>
          }
          value={facts.jitter == null ? null : formatNumber(facts.jitter, lang, 1)}
          secondary="ms"
        />
        <StatBlock
          variant="plain"
          size="xs"
          label={t('metric.peak_neutral', 'Špička')}
          value={facts.max == null ? null : formatNumber(facts.max, lang, 0)}
          secondary="ms"
        />
      </StatRow>
    </Panel>
  );
}

// ---------------------------------------------------------------------- WAN

/**
 * The router's WAN (NetPulse "WAN network"): the traffic chart of the line
 * (both links stacked when there is an LTE backup) and the facts of the link.
 * No public address or provider: the agent's WAN addresses reach admins only,
 * on the Síť tab, and a provider is nothing the router reports.
 */
export function WanPanel({
  d,
  chart,
  metricHref,
  className,
}: {
  d: Record<string, any>;
  chart: ChartData | null;
  metricHref: (id: string) => string;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const wan = wanLinkState(d);
  const chip =
    wan.ok === null ? undefined : wan.ok ? (
      <Pill tone="up" dot>
        {t('common.online', 'Online')}
      </Pill>
    ) : wan.reason === 'no_internet' ? (
      <Pill tone="warning" dot>
        {t('asset.wan_no_internet_short', 'Bez internetu')}
      </Pill>
    ) : (
      <Pill tone="down" dot>
        {t('common.offline', 'Offline')}
      </Pill>
    );
  const uptime = num(d.wan_uptime);
  const link = num(d.wan_link_mbit);
  const reconnects = num(d.wan_reconnect_count);
  return (
    <Panel
      title={t('asset.wan_title', 'WAN')}
      icon={Globe}
      chip={chip}
      className={className}
      bodyClassName="flex flex-col gap-4"
    >
      {chart && (
        <div className="min-w-0">
          <Link
            to={metricHref(chart.id === 'net-combined' ? 'net' : chart.id)}
            className="micro-label focus-visible:ring-ring hover:text-foreground rounded-sm focus-visible:ring-2 focus-visible:outline-none"
          >
            {chart.title}
          </Link>
          <MetricChart data={chart} height={190} group="asset-performance" />
        </div>
      )}
      <StatRow cols={4} className={cn(chart && 'border-border border-t pt-3')}>
        <StatBlock
          variant="plain"
          size="xs"
          label={t('net.proto', 'Protokol')}
          value={typeof d.wan_proto === 'string' && d.wan_proto ? d.wan_proto : null}
        />
        <StatBlock
          variant="plain"
          size="xs"
          label={t('net.port_speed', 'Rychlost portu')}
          value={link == null ? null : formatNumber(link, lang, 0)}
          secondary="Mbit/s"
        />
        <StatBlock
          variant="plain"
          size="xs"
          label={t('net.wan_uptime', 'WAN uptime')}
          value={uptime == null || uptime <= 0 ? null : formatDuration(uptime, lang)}
        />
        {/* Until 0.1.7 an uncounted reconnect arrived as 0 (G24): unknown stays a dash. */}
        <StatBlock
          variant="plain"
          size="xs"
          label={t('net.reconnects', 'Reconnecty (od startu)')}
          value={reconnects == null ? null : String(reconnects)}
        />
      </StatRow>
    </Panel>
  );
}

// ---------------------------------------------------------------- Wi-Fi + clients

const radios = (d: Record<string, any>): WifiRadio[] => (Array.isArray(d.wifi_radios) ? d.wifi_radios : []);

/**
 * The radios at a glance (NetPulse "WiFi radios"): band, channel and width,
 * generation, clients and how busy the channel is. No network names - the
 * full list with every reading is on the Síť tab, one click away.
 */
export function WifiSummaryPanel({
  d,
  onOpen,
  className,
}: {
  d: Record<string, any>;
  onOpen: () => void;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const list = radios(d).filter((r) => r.band);
  if (list.length === 0) return null;
  return (
    <Panel
      title={t('asset.wifi_title', 'Wi-Fi rádia')}
      icon={Wifi}
      count={list.length}
      padding="sm"
      className={className}
      action={
        <button
          type="button"
          onClick={onOpen}
          className="text-link focus-visible:ring-ring inline-flex items-center gap-1 rounded-sm text-xs font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
        >
          {t('asset.wifi_details', 'Podrobnosti')}
          <ChevronRight aria-hidden="true" className="size-3.5" />
        </button>
      }
    >
      <ul className="flex flex-col gap-2">
        {list.map((r, i) => {
          const busy = num(r.busy_pct);
          const rating = rateChannelBusy(busy);
          const clients = num(r.clients);
          return (
            <li key={`${r.radio ?? r.band}-${i}`} className="bg-inset rounded-lg border border-border p-3">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Pill tone="info">{bandLabel(r.band, lang)}</Pill>
                <span className="figure text-muted-foreground text-xs">
                  {[
                    num(r.channel) != null
                      ? t('asset.wifi_channel', { n: r.channel as number }, `kanál ${r.channel}`)
                      : null,
                    num(r.width_mhz) != null ? `${r.width_mhz} MHz` : null,
                    radioGenerationLabel(r, t),
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </span>
                <span className="ml-auto flex items-center gap-1.5 text-xs">
                  <Users aria-hidden="true" className="text-muted-foreground size-3.5" />
                  <span className="figure">{clients == null ? '—' : clients}</span>
                  <span className="sr-only">{t('dashboard.device_clients', 'Wi-Fi klienti')}</span>
                </span>
              </div>
              {busy != null && (
                <div className="mt-2 flex items-center gap-3">
                  <span className="text-muted-foreground w-24 shrink-0 text-2xs">
                    {t('asset.wifi_busy', 'Obsazení kanálu')}
                  </span>
                  <RangeMeter
                    min={0}
                    max={100}
                    value={busy}
                    tone={rating && (rating.level === 'poor' || rating.level === 'fair') ? 'warning' : null}
                    label={t('asset.wifi_busy', 'Obsazení kanálu')}
                    valueText={`${formatNumber(busy, lang, 0)} %`}
                    className="flex-1"
                  />
                  <span className="figure w-20 shrink-0 text-right text-2xs">
                    {formatNumber(busy, lang, 0)} %{rating ? ` · ${signalLevelLabel(t, rating.level)}` : ''}
                  </span>
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </Panel>
  );
}

/**
 * How many devices the router serves (NetPulse "Connected clients"), as
 * counts only: the agents deliberately send no client names or addresses,
 * and this panel says so instead of leaving a list-shaped hole.
 */
export function ClientsPanel({ d, className }: { d: Record<string, any>; className?: string }) {
  const { t, lang } = useLanguage();
  const list = radios(d);
  const byBand = (band: string) => {
    const on = list.filter((r) => r.band === band && num(r.clients) != null);
    return on.length === 0 ? null : on.reduce((sum, r) => sum + (r.clients as number), 0);
  };
  const wifi = num(d.wifi_clients_count);
  const leases = num(d.dhcp_leases_count);
  const bands = (['2.4GHz', '5GHz', '6GHz'] as const)
    .map((band) => ({ band, n: byBand(band) }))
    .filter((b) => b.n != null);
  if (wifi == null && leases == null && bands.length === 0) return null;
  return (
    <Panel
      title={t('asset.clients_title', 'Klienti')}
      icon={Users}
      className={className}
      bodyClassName="flex flex-col gap-3"
    >
      <StatRow cols={bands.length + 2 > 4 ? 4 : ((bands.length + 2) as 2 | 3 | 4)}>
        <StatBlock
          variant="plain"
          size="sm"
          label={t('asset.clients_wifi', 'Wi-Fi celkem')}
          value={wifi == null ? null : String(wifi)}
        />
        {bands.map((b) => (
          <StatBlock key={b.band} variant="plain" size="sm" label={bandLabel(b.band, lang)} value={String(b.n)} />
        ))}
        <StatBlock
          variant="plain"
          size="sm"
          label={t('asset.clients_dhcp', 'DHCP výpůjčky')}
          value={leases == null ? null : String(leases)}
        />
      </StatRow>
      <p className="text-muted-foreground text-2xs">
        {t('asset.clients_privacy', 'Jen počty: agent neposílá jména ani adresy zařízení.')}
      </p>
    </Panel>
  );
}

// ------------------------------------------------------------------ storage

const TONE_PILL: Record<'up' | 'warning' | 'down' | 'muted', PillTone> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  muted: 'neutral',
};

/**
 * The disks at a glance (NetPulse "Storage"): each disk's fullest partition,
 * its temperature and its SMART verdict in one word. The SMART details, the
 * write history and the partitions live on the Úložiště tab.
 */
export function StorageSummaryPanel({
  d,
  onOpen,
  className,
}: {
  d: Record<string, any>;
  onOpen: () => void;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const disks: StorageDisk[] = Array.isArray(d.storage_disks) ? d.storage_disks : [];
  if (disks.length === 0) return null;
  return (
    <Panel
      title={t('asset.tab_storage', 'Úložiště')}
      icon={HardDrive}
      count={disks.length}
      padding="sm"
      className={className}
      action={
        <button
          type="button"
          onClick={onOpen}
          className="text-link focus-visible:ring-ring inline-flex items-center gap-1 rounded-sm text-xs font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
        >
          {t('asset.wifi_details', 'Podrobnosti')}
          <ChevronRight aria-hidden="true" className="size-3.5" />
        </button>
      }
    >
      <ul className="flex flex-col gap-2">
        {disks.map((disk) => {
          const used = (disk.partitions ?? []).map((p) => num(p.used_pct)).filter((v): v is number => v != null);
          const fullest = used.length > 0 ? Math.max(...used) : null;
          const verdict = diskVerdict(disk);
          const temp = tempTone(disk);
          return (
            <li key={disk.key ?? disk.name} className="bg-inset rounded-lg border border-border p-3">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="figure text-sm font-medium">{disk.name}</span>
                <span className="text-muted-foreground min-w-0 flex-1 truncate text-xs">{disk.model ?? ''}</span>
                <Pill tone={TONE_PILL[verdict.tone]} size="sm">
                  {t('asset.smart_word', 'SMART')} {verdictWord(verdict.tone, t)}
                </Pill>
              </div>
              <div className="mt-2 flex items-center gap-3">
                <RangeMeter
                  min={0}
                  max={100}
                  value={fullest}
                  tone={fullest == null ? null : fullest >= 90 ? 'down' : fullest >= 75 ? 'warning' : null}
                  label={t('asset.disk_used', 'Zaplnění')}
                  valueText={fullest == null ? '—' : `${formatNumber(fullest, lang, 0)} %`}
                  className="flex-1"
                />
                <span className="figure w-12 shrink-0 text-right text-xs">
                  {fullest == null ? '—' : `${formatNumber(fullest, lang, 0)} %`}
                </span>
                <span
                  className={cn(
                    'figure w-16 shrink-0 text-right text-xs',
                    temp === 'down' ? 'text-down' : temp === 'warning' ? 'text-warning' : 'text-muted-foreground'
                  )}
                >
                  {tempLabel(disk, t)}
                </span>
              </div>
            </li>
          );
        })}
      </ul>
    </Panel>
  );
}

function verdictWord(tone: 'up' | 'warning' | 'down' | 'muted', t: T): string {
  if (tone === 'up') return t('asset.smart_ok_word', 'v pořádku');
  if (tone === 'down') return t('asset.smart_failing_word', 'selhává');
  if (tone === 'warning') return t('asset.smart_check_word', 'ke kontrole');
  return t('asset.smart_unknown_word', 'neměřeno');
}
