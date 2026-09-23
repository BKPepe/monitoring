import * as React from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import {
  ArrowLeft,
  Clock,
  Cpu,
  Globe,
  MessageSquare,
  Mic,
  Pencil,
  Router as RouterIcon,
  Server,
  Settings2,
  ShieldCheck,
  Gamepad2,
  Archive,
  ArchiveRestore,
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge, StatusDot, statusVariant } from '@/components/ui/badge';
import { SignalReading } from '@/components/signal-reading';
import { AvailabilityWindows } from '@/components/availability-windows';
import { NotificationLog } from '@/components/notification-log';
import { MaintenanceToggle } from '@/components/maintenance-toggle';
import { useSession } from '@/api/use-session';
import { InterfaceTrafficDaily } from '@/components/interface-traffic-daily';
import { ProcessTop } from '@/components/process-top';
import { lteVerdict, rateRsrp, rateRsrq, rateSinr, signalTone } from '@/lib/signal-quality';
import { signalAdvice, signalLevelLabel } from '@/lib/signal-texts';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ChartCard } from '@/components/charts/chart-card';
import { Sparkline } from '@/components/sparkline';
import { lteBackupState } from '@/lib/lte-backup';
import { wanLinkState } from '@/lib/wan-link';
import { computeSeriesDelta, goodDirectionFor } from '@/components/charts/series-delta';
import type { ChartData, LinkTrafficResponse, RecommendationArea } from '@/api/types';
import { resolveSource } from '@/api/source';
import { Timeline } from '@/components/timeline';
import type { TimelineEvent } from '@/data/model';
import { useAssetCharts } from '@/api/use-asset-charts';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { useLanguage } from '@/context/language-context';
import { cn, formatMs, formatPercent, formatUptime } from '@/lib/utils';
import { RouterServices } from '@/components/router-services';
import { DiscordCard } from '@/components/discord-card';
import { MinecraftCard } from '@/components/minecraft-card';
import { CheckPipeline } from '@/components/check-pipeline';
import { TeamspeakCard } from '@/components/teamspeak-card';
import { HeartbeatCard } from '@/components/heartbeat-card';
import { StorageCard } from '@/components/storage-card';
import { SpeedtestCard } from '@/components/speedtest-card';
import { WanBottleneckCard } from '@/components/wan-bottleneck-card';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { RouterRecommendations, useRouterRecommendations } from '@/components/router-recommendations';
import { LanPortMap } from '@/components/lan-port-map';
import { WifiRadioList } from '@/components/wifi-radio-list';
import { LogErrorLines } from '@/components/log-error-lines';
import { logWindow, readLogLines } from '@/lib/log-lines';
import { seriesForTile } from '@/lib/tile-series';
import { timelineSeverity, timelineTitle } from '@/lib/timeline-events';
import { monitorTypeLabel, monitorTypeProfile, type MonitorTypeProfile } from '@/lib/monitor-type';
import { processUsage } from '@/lib/monitor-grouping';
import {
  agentRunText,
  agentSkippedText,
  busiestCoreHint,
  dnsResolverText,
  reportsReceivedText,
  socTemperatureC,
} from '@/lib/router-overview';

type MonitorStatus = ApiMonitor['status'];

type TimeRange = '24h' | '7d' | '30d';

interface HealthMetric {
  key: string;
  label: string;
  value: string;
  tone?: 'latency' | 'cpu' | 'memory' | 'disk' | 'temperature';
  /** One quiet line under the value: what the number does not say by itself. */
  hint?: string;
  /** Mini trend for the chosen period (from already-loaded chart data - no extra fetch). */
  series?: (number | null)[];
  delta?: { pct: number; direction: 'up' | 'down'; good: boolean | null };
}

interface AssetDetail {
  id: number;
  name: string;
  kind: string;
  subtitle: string;
  status: MonitorStatus;
  breadcrumb: string[];
  health: HealthMetric[];
  summary: string;
  summaryChips: { label: string; variant: 'up' | 'warning' | 'info' | 'down' }[];
  /** `hint` shows under the value - the answer to "what changed and why". */
  info: { label: string; value: string; hint?: string }[];
  smartStatus?: string | null;
  cpanelStats?: Record<string, { formatted?: string }> | null;
  cpanelStatsError?: { error?: string; hint?: string | null; since?: string } | null;
  /** ISO time of the last check/report - every tab shows data from this moment. */
  lastCheck: string | null;
  /** The monitor's effective limits, so its charts show the line its alerts use. */
  thresholds?: { cpu: number | null; ram: number | null; hdd: number | null };
  /** Raw details from the last report - the Network tab reads OpenWrt telemetry from them. */
  rawDetails: Record<string, any>;
  /** Remote Actions - admin session only (the API omits the fields otherwise). */
  remoteActionsEnabled: boolean;
  allowedActions: string[];
  /** Archived: read-only history, out of every live list. */
  archived: boolean;
  monitoredProcesses: string | null;
  sslCert?: { days_remaining?: number | null; issuer?: string | null; valid_to?: string | null } | null;
  events: TimelineEvent[];
  /** Merged top-CPU + top-RAM processes from the agent; null = the agent does not report that dimension. */
  processes: { name: string; cpu: number | null; memory: number | null }[];
  related: { name: string; kind: string; status: MonitorStatus; detail: string }[];
  /** What this monitor TYPE can ever report - decides which tiles and cards exist at all. */
  typeProfile: MonitorTypeProfile;
  /** The agent monitor this one runs under; null when it has none (or none is loaded). */
  parent: { id: number; name: string } | null;
}

/**
 * Response of api.php?action=monitor_insights - the same server-side logic
 * (Executive Summary, knowledge tips, forecast/anomaly/network insights)
 * the public status page has been rendering for a while. Before this, the
 * React app fabricated a generic template sentence on the client instead.
 */
interface ServerInsights {
  summary: string;
  healthScore: { score: number } | null;
  tips: { severity: 'critical' | 'warn' | string; text: string }[];
  insights: { text: string }[];
  /** System-level timeline (status changes, remote actions, SSL warnings, ...)
   *  from monitor_events/agent_actions - a different granularity than the
   *  per-check rows in `events`, so it's rendered as its own section. */
  timeline: { type: string; description: string | null; at: string; relative: string }[];
}

export function AssetDetailPage() {
  const { t, lang } = useLanguage();
  const { assetId } = useParams<{ assetId: string }>();
  const idNum = Number(assetId) || 1;

  const [asset, setAsset] = React.useState<AssetDetail | null>(null);
  /** Bumped after an action that changes the monitor, to refetch it. */
  const [reloadToken, setReloadToken] = React.useState(0);

  // The raw row too: the shared collection-issues banner takes ApiMonitor,

  // so the detail page reuses it instead of keeping its own copy.

  const [rawMonitor, setRawMonitor] = React.useState<ApiMonitor | null>(null);
  /** Same as the metric detail: the period belongs in the address, so a
   *  reload or a shared link keeps the window the operator chose. */
  const [searchParams, setSearchParams] = useSearchParams();
  const rangeParam = searchParams.get('range');
  const range: TimeRange = isKnownTimeRange(rangeParam) ? rangeParam : '24h';
  const setRange = React.useCallback(
    (next: TimeRange) => {
      const params = new URLSearchParams(searchParams);
      params.set('range', next);
      setSearchParams(params, { replace: true });
    },
    [searchParams, setSearchParams]
  );
  const [loading, setLoading] = React.useState(true);
  // The monitor list did not load. It used to fall through to "Zařízení
  // nenalezeno ... v databázi", a failure dressed up as an answer (W1-A5).
  const [loadFailed, setLoadFailed] = React.useState(false);
  const [events, setEvents] = React.useState<TimelineEvent[]>([]);
  /** The check that recorded the last status change, straight from the server. */
  const [statusChange, setStatusChange] = React.useState<{
    changedAtIso: string;
    status: string;
    fromStatus: string | null;
    errorMsg: string | null;
  } | null>(null);
  const [serverInsights, setServerInsights] = React.useState<ServerInsights | null>(null);

  React.useEffect(() => {
    let active = true;
    setLoading(true);

    appApi
      .getMonitors()
      .then(async (rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        let match: ApiMonitor | undefined =
          list.find((m: ApiMonitor) => Number(m.id) === idNum) ??
          list.find((m: ApiMonitor) => Number(m.assetId) === idNum);
        if (!match) {
          // An archived monitor is out of the live list; its history stays readable here.
          const archivedList = await appApi.getArchivedMonitors().catch(() => [] as ApiMonitor[]);
          if (!active) return;
          match = archivedList.find((m) => Number(m.id) === idNum);
        }
        // Monitors sharing the asset - the agent's own checks. The card that
        // lists them had a dead main branch: `related` was hardcoded to an
        // empty array, so it always fell through to the ports fallback.
        const siblings = match
          ? list.filter((m: ApiMonitor) => m.id !== match.id && m.assetId != null && m.assetId === match.assetId)
          : [];
        setAsset(match ? buildDynamicAsset(match, t, siblings) : null);
        setRawMonitor(match ?? null);
        setLoadFailed(false);
      })
      .catch(() => {
        if (active) {
          setAsset(null);
          setRawMonitor(null);
          setLoadFailed(true);
        }
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [idNum, t, reloadToken]);

  // The effects below key on the asset ID, not the object - refreshing an object
  // with the same ID must not refetch events or insights.
  const loadedAssetId = asset?.id;

  /**
   * What the last status change actually was.
   *
   * The row used to say only WHEN the state changed - and for anything older
   * than a day it printed the same timestamp twice, because timeAgo() fell back
   * to the absolute date that the caller then repeated in brackets. The reason
   * lives in the event log we already load, so there is no need to go hunting
   * for it in the timeline below.
   */
  /**
   * What the last status change actually was.
   *
   * Not guessed from the event list: that list is the newest rows plus the
   * newest failures, so the transition itself is often not in it at all, and
   * every routine passing check looks alike - the old filter picked the newest
   * one, the single row that by definition changed nothing. The server answers
   * the question directly, pinned to monitors.last_status_change, and returns
   * null when it cannot find the row. Null renders nothing rather than
   * borrowing another event's text.
   */
  const statusChangeHint = React.useMemo(() => {
    if (!statusChange) return undefined;
    const label =
      statusChange.status === 'down'
        ? t('asset.event_outage', 'Výpadek služby')
        : statusChange.status === 'warning'
          ? t('asset.event_degraded', 'Zhoršená odezva')
          : statusChange.status === 'unknown'
            ? t('asset.event_unknown', 'Stav neznámý (agent nehlásí)')
            : statusChange.status === 'maintenance'
              ? t('common.maintenance', 'Údržba')
              : statusChange.fromStatus && statusChange.fromStatus !== 'up'
                ? t('asset.event_recovered', 'Služba obnovena')
                : t('asset.event_ok', 'Kontrola proběhla v pořádku');
    const detail = (statusChange.errorMsg ?? '').trim();
    return detail ? `${label} — ${detail}` : label;
  }, [statusChange, t]);

  React.useEffect(() => {
    if (!loadedAssetId) return;
    let active = true;

    // 200 is what the API allows; 30 rows of a per-minute check covered half an
    // hour and the page-size picker offered pages that could never fill.
    fetch(`/status/api.php?action=events&monitor_id=${loadedAssetId}&limit=200`, { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (!active || !data || !Array.isArray(data.events)) return;
        // isRecovery comes from the server, which knows which rows are real
        // neighbours - the list mixes older outages in, so deciding it here
        // marked whichever OK row happened to sit above one of them.
        setStatusChange(data.statusChange && typeof data.statusChange.status === 'string' ? data.statusChange : null);
        const rows: any[] = data.events;
        setEvents(
          rows.map((e: any) => ({
            id: e.id,
            title: e.isDown
              ? t('asset.event_outage', 'Výpadek služby')
              : e.rawStatus === 'warning'
                ? t('asset.event_degraded', 'Zhoršená odezva')
                : e.rawStatus === 'unknown'
                  ? t('asset.event_unknown', 'Stav neznámý (agent nehlásí)')
                  : e.isRecovery
                    ? t('asset.event_recovered', 'Služba obnovena')
                    : t('asset.event_ok', 'Kontrola proběhla v pořádku'),
            detail:
              e.errorMsg +
              (e.outageDurationSec
                ? t(
                    'asset.event_duration',
                    { min: Math.round(e.outageDurationSec / 60) },
                    ` (trvání ${Math.round(e.outageDurationSec / 60)} min)`
                  )
                : ''),
            at: e.time,
            atIso: typeof e.timeIso === 'string' ? e.timeIso : null,
            severity: e.isDown ? 'down' : e.rawStatus === 'warning' ? 'warning' : e.isRecovery ? 'up' : 'info',
            resolution: e.isDown ? 'Open' : e.isRecovery ? 'Resolved' : 'Info',
            location: e.location,
            method: e.type,
            responseMs: typeof e.responseTime === 'number' ? e.responseTime : null,
          }))
        );
      })
      .catch(() => {});

    return () => {
      active = false;
    };
  }, [loadedAssetId, t]);

  React.useEffect(() => {
    if (!loadedAssetId) return;
    let active = true;

    fetch(`/status/api.php?action=monitor_insights&monitor_id=${loadedAssetId}&lang=${lang}`, {
      credentials: 'include',
    })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (!active || !data || typeof data.summary !== 'string') return;
        setServerInsights({
          summary: data.summary,
          healthScore:
            data.healthScore && typeof data.healthScore.score === 'number' ? { score: data.healthScore.score } : null,
          tips: Array.isArray(data.tips) ? data.tips : [],
          insights: Array.isArray(data.insights) ? data.insights : [],
          timeline: Array.isArray(data.timeline) ? data.timeline : [],
        });
      })
      .catch(() => {});

    return () => {
      active = false;
    };
  }, [loadedAssetId, lang]);

  // Asked once per page and only for a router; every copy of the card below
  // reads this one answer. A hook, so it sits above the early returns.
  const routerMonitorId = asset && isRouterKind(asset.kind) ? Number(asset.id) : null;
  const recommendations = useRouterRecommendations(routerMonitorId);
  /** Controlled, because a compact recommendation on another tab links to the full list on the overview. */
  const [tab, setTab] = React.useState('overview');
  const showAllRecommendations = React.useCallback(() => {
    setTab('overview');
    // The card exists only once the overview tab has rendered.
    window.requestAnimationFrame(() => {
      document.getElementById('router-recommendations')?.scrollIntoView({ block: 'start' });
    });
  }, []);
  /** The compact copy that sits on top of the card its area is about (X21). */
  const compactRecommendations = React.useCallback(
    (area: RecommendationArea) =>
      routerMonitorId == null ? null : (
        <RouterRecommendations
          monitorId={routerMonitorId}
          source={recommendations}
          area={area}
          compact
          onShowAll={showAllRecommendations}
        />
      ),
    [routerMonitorId, recommendations, showAllRecommendations]
  );

  if (loading) {
    return <LoadingState size="page" label={t('asset.loading', 'Načítám detail zařízení a diagnostické metriky…')} />;
  }

  if (!asset && loadFailed) {
    return (
      <ErrorState
        message={t('asset.load_failed', 'Detail zařízení se nepodařilo načíst. Server neodpověděl, zkuste to znovu.')}
        onRetry={() => setReloadToken((n) => n + 1)}
      />
    );
  }

  if (!asset) {
    return (
      <Card className="grid place-items-center gap-4 p-12 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-base">{t('asset.not_found', 'Zařízení nenašeno')}</p>
          <p className="text-muted-foreground text-sm">
            {t(
              'asset.not_found_desc',
              { id: assetId ?? '' },
              'Zařízení s ID {id} nebylo v monitorovací databázi nalezeno.'
            )}
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <Link to="/infrastructure" className="gap-2 font-semibold">
            <ArrowLeft className="size-4" /> {t('asset.back', 'Zpět na přehled infrastruktury')}
          </Link>
        </Button>
      </Card>
    );
  }

  const upperKind = (asset.kind || '').toUpperCase();
  // The router has its own Services section - a TLS certificate makes no sense for it.
  const isRouter = isRouterKind(asset.kind);
  const isDiscord = upperKind === 'DISCORD';
  const isMinecraft = upperKind === 'MINECRAFT';
  // Rozpad kontroly dava smysl jen u HTTP cilu (DNS -> TCP -> TLS -> HTTP).
  const isWeb = ['WEB', 'HTTP', 'HTTPS'].includes(upperKind);
  const isTeamspeak = ['TEAMSPEAK', 'VOICE'].includes(upperKind);
  const isHeartbeat = upperKind === 'HEARTBEAT';

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Link
          to="/infrastructure"
          className="hover:text-foreground font-semibold flex items-center gap-1 transition-colors"
        >
          <ArrowLeft className="size-3.5" /> {t('nav.infrastructure', 'Infrastruktura')}
        </Link>
        {asset.breadcrumb
          .filter((c) => c !== 'Infrastructure' && c !== 'Infrastruktura')
          .map((crumb) => (
            <React.Fragment key={crumb}>
              <span>/</span>
              <span>{crumb}</span>
            </React.Fragment>
          ))}
        <span>/</span>
        <span className="text-foreground font-medium">{asset.name}</span>
      </div>

      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <Hero asset={asset} />
        </div>
        {/* One click, not "open the form, tick a box, save the whole monitor" -
            which is what a maintenance window used to cost at the moment speed
            matters most. */}
        {!asset.archived && (
          <MaintenanceToggle
            monitorId={Number(asset.id)}
            active={rawMonitor?.maintenance === true}
            onChanged={() => setReloadToken((n) => n + 1)}
          />
        )}
      </div>

      {asset.archived && (
        <ArchivedNotice
          monitorId={Number(asset.id)}
          archivedAt={rawMonitor?.archivedAt ?? null}
          onRestored={() => setReloadToken((n) => n + 1)}
        />
      )}

      <CollectionIssuesBanner monitors={rawMonitor && !asset.archived ? [rawMonitor] : []} />

      <Tabs value={tab} onValueChange={setTab} className="space-y-6">
        {/* Sticky under the header (h-16): on a long detail the tabs and
            the range switcher stay at hand without scrolling back up. */}
        {/* Two things about this bar. On a phone five triggers do not fit: the
            strip used to overflow and drag the whole page sideways, so the last
            tabs were unreachable - it scrolls on its own now. And the offset is
            top-0, not top-16: the header lives OUTSIDE the scrolling main, so
            sticking 4rem below the top of that container left a 64px band the
            content slid through in the open. */}
        <div className="bg-background/95 sticky top-0 z-20 -mx-1 flex flex-wrap items-center justify-between gap-4 border-b border-border px-1 pb-3 pt-1 backdrop-blur-sm">
          <TabsList className="bg-secondary/40 max-w-full flex-nowrap overflow-x-auto p-1">
            <TabsTrigger value="overview">{t('asset.tab_overview', 'Přehled & Výkon')}</TabsTrigger>
            <TabsTrigger value="processes">
              {t('asset.tab_processes_short', 'Procesy')} ({asset.processes.length})
            </TabsTrigger>
            {hasNetworkData(asset.rawDetails) && (
              <TabsTrigger value="network">{t('asset.tab_network', 'Síť')}</TabsTrigger>
            )}
            <TabsTrigger value="services">{t('asset.tab_services', 'Služby & Certifikáty')}</TabsTrigger>
            <TabsTrigger value="events">
              {t('asset.tab_events', 'Události')} ({events.length})
            </TabsTrigger>
          </TabsList>
          <RangePicker value={range} onChange={setRange} />
          {/* Every tab shows data from this moment - the Network, Processes and
              Services tabs used to present the last report as "now". */}
          {asset.lastCheck && (
            <p className="text-muted-foreground basis-full text-2xs">
              {t('asset.data_as_of', 'Data z posledního hlášení')}: {timeAgo(asset.lastCheck, t)} (
              {new Date(asset.lastCheck).toLocaleString('cs-CZ')})
            </p>
          )}
        </div>

        <TabsContent value="overview">
          <OverviewTab
            asset={asset}
            range={range}
            events={events}
            serverInsights={serverInsights}
            assetId={assetId}
            statusChangeHint={statusChangeHint}
            recommendations={
              isRouter ? (
                <RouterRecommendations
                  monitorId={Number(asset.id)}
                  source={recommendations}
                  agentVersion={typeof asset.rawDetails?.version === 'string' ? asset.rawDetails.version : null}
                />
              ) : null
            }
          />
        </TabsContent>

        {hasNetworkData(asset.rawDetails) && (
          <TabsContent value="network">
            <NetworkTab
              d={asset.rawDetails}
              monitorId={Number(asset.id)}
              assetId={assetId ?? asset.id}
              recommendations={isRouter ? compactRecommendations : undefined}
              // Where the line speed ends - only a router has a WAN to judge (X21).
              wanBottleneck={isRouter ? <WanBottleneckCard monitorId={asset.id} /> : undefined}
              // A router's link speed belongs to its network, not to its services (X21).
              speedtest={isRouter ? <SpeedtestCard monitorId={asset.id} /> : undefined}
            />
          </TabsContent>
        )}

        <TabsContent value="processes">
          {/* The snapshot below answers "right now"; this answers "all day",
              which is the question a spiky process only shows up in. */}
          <div className="mb-4">
            <ProcessTop monitorId={Number(asset.id)} />
          </div>
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-3 border-b border-border pb-3">
              <Cpu className="size-5 text-primary" />
              <div>
                <h3 className="font-bold text-base">
                  {t('asset.process_load', { name: asset.name }, `Zátěž procesů serveru (${asset.name})`)}
                </h3>
                <p className="text-xs text-muted-foreground">
                  {t('asset.process_load_desc', 'Aktuálně spotřebovávaná paměť RAM a zátěž procesoru.')}
                </p>
              </div>
            </div>
            {asset.rawDetails?.ts3_process && (
              <div className="p-3 rounded-lg bg-up/10 border border-up/25 text-xs space-y-1">
                <p className="font-bold text-up">🎙 {t('asset.ts3_process_title', 'Proces ts3server')}</p>
                <p className="font-mono text-muted-foreground">
                  PID {asset.rawDetails.ts3_process.pid ?? '—'}
                  {asset.rawDetails.ts3_process.cpu != null && ` · CPU ${asset.rawDetails.ts3_process.cpu} %`}
                  {asset.rawDetails.ts3_process.ram_mb != null && ` · RAM ${asset.rawDetails.ts3_process.ram_mb} MB`}
                  {asset.rawDetails.ts3_process.threads != null && ` · ${asset.rawDetails.ts3_process.threads} vláken`}
                  {asset.rawDetails.ts3_process.uptime_sec != null &&
                    ` · uptime ${Math.round(asset.rawDetails.ts3_process.uptime_sec / 3600)} h`}
                </p>
              </div>
            )}
            {asset.processes.length === 0 ? (
              asset.cpanelStats ? (
                <div className="space-y-2">
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'asset.cpanel_no_agent',
                      'Tenhle monitor nemá VPS agenta pro výpis jednotlivých procesů - hostuje se na cPanelu, kde je k dispozici jen souhrnné využití zdrojů:'
                    )}
                  </p>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                    {Object.entries(asset.cpanelStats).map(([key, val]) => (
                      <div key={key} className="p-2.5 rounded-lg bg-secondary/40 border border-border">
                        <p className="text-muted-foreground capitalize">{key}</p>
                        <p className="font-mono font-semibold">{val?.formatted ?? '—'}</p>
                      </div>
                    ))}
                  </div>
                </div>
              ) : asset.cpanelStatsError ? (
                // cPanel collection is configured but failing - say so loudly
                // instead of pretending there's simply nothing to show.
                <div role="alert" className="rounded-lg border-2 border-down/60 bg-down/10 p-4 space-y-1 text-xs">
                  <p className="font-bold text-down">
                    ⛔ {t('asset.cpanel_error_title', 'Sběr cPanel statistik selhává')}
                  </p>
                  <p className="text-down font-mono">{asset.cpanelStatsError.error}</p>
                  {asset.cpanelStatsError.since && (
                    <p className="text-muted-foreground">
                      {t('collection.since', 'od')} {new Date(asset.cpanelStatsError.since).toLocaleString('cs-CZ')}
                    </p>
                  )}
                  <p className="text-muted-foreground">
                    💡{' '}
                    {asset.cpanelStatsError.hint ??
                      t(
                        'asset.cpanel_error_hint',
                        'Zkontrolujte STATS_KEY v cpanel_config.php vedle exporteru a klíč v cpanel_stats_url monitoru.'
                      )}
                  </p>
                </div>
              ) : (
                <p className="text-xs text-muted-foreground py-6 text-center">
                  {t(
                    'asset.no_processes_db',
                    'Pro tento uzel nejsou v databázi evidovány žádné samostatné podprocesy.'
                  )}
                </p>
              )
            ) : (
              <>
                {/* Mobile: one process per row, values under the name. */}
                <div className="flex flex-col gap-1.5 md:hidden">
                  {asset.processes.map((proc) => (
                    <div key={proc.name} className="rounded-lg border border-border px-3 py-2">
                      <p className="truncate font-mono text-xs font-semibold">{proc.name}</p>
                      <div className="text-muted-foreground mt-0.5 flex gap-4 font-mono text-2xs">
                        <span>CPU {formatPercent(proc.cpu, 1)}</span>
                        {/* Unmeasured memory = a dash, not a bare "MB". */}
                        <span>RAM {proc.memory == null ? '—' : `${proc.memory} MB`}</span>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="hidden md:block">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>{t('asset.proc_name', 'Název procesů')}</TableHead>
                        <TableHead className="text-right">{t('common.cpu', 'Využití CPU')}</TableHead>
                        <TableHead className="text-right">{t('asset.mem_usage', 'Spotřeba RAM')}</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {asset.processes.map((proc) => (
                        <TableRow key={proc.name}>
                          <TableCell className="font-mono text-xs font-semibold">{proc.name}</TableCell>
                          <TableCell className="text-right font-mono">{formatPercent(proc.cpu, 1)}</TableCell>
                          <TableCell className="text-right font-mono">
                            {proc.memory == null ? '—' : `${proc.memory} MB`}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="services">
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-3 border-b border-border pb-3">
              <ShieldCheck className="size-5 text-up" />
              <div>
                <h3 className="font-bold text-base">
                  {isRouter
                    ? t('asset.router_services_title', 'Síťové služby routeru')
                    : isDiscord
                      ? t('asset.discord_services_title', 'Discord server')
                      : isMinecraft
                        ? t('asset.mc_services_title', 'Minecraft server')
                        : t('asset.services_title', 'Stav Služeb & Šifrovací Certifikáty')}
                </h3>
                <p className="text-xs text-muted-foreground">
                  {isRouter
                    ? t('asset.router_services_desc', 'Konektivita, DNS, firewall, Wi-Fi a VPN podle dat od agenta.')
                    : isDiscord
                      ? t(
                          'asset.discord_services_desc',
                          'Kdo je online, hlasové kanály a členové ze serverového widgetu.'
                        )
                      : isMinecraft
                        ? t('asset.mc_services_desc', 'MOTD, hráči a výkon serveru z dotazu na herní port.')
                        : t('asset.services_desc', 'Stav protokolů a šifrovacích certifikátů.')}
                </p>
              </div>
            </div>

            {/* A router certifies no website - the TLS certificate card
                only took space. What the router does have is shown instead. */}
            {isRouter && <RouterServices d={asset.rawDetails ?? {}} />}
            {isDiscord && <DiscordCard d={asset.rawDetails ?? {}} />}
            {isMinecraft && <MinecraftCard d={asset.rawDetails ?? {}} />}
            {isWeb && <CheckPipeline monitorId={asset.id} />}
            {isTeamspeak && <TeamspeakCard monitorId={asset.id} />}
            {isHeartbeat && <HeartbeatCard monitorId={asset.id} />}
            {/* Storage renders itself only where the agent reports partitions
                sent them - a website or Discord shows nothing. */}
            <StorageCard
              d={asset.rawDetails ?? {}}
              monitorId={isRouter ? Number(asset.id) : undefined}
              recommendations={isRouter ? compactRecommendations('storage') : undefined}
            />
            {/* Link speed shows only where measurements arrived; a router shows it on its Network tab. */}
            {!isRouter && <SpeedtestCard monitorId={asset.id} />}

            {/* A heartbeat has no target to connect to - a certificate
                nor "the protocol uses no TLS" makes sense for it. */}
            {!isRouter &&
              !isDiscord &&
              !isMinecraft &&
              !isHeartbeat &&
              (() => {
                const isNoSsl = [
                  'ROUTER',
                  'VOICE',
                  'MINECRAFT',
                  'GAME',
                  'AGENT',
                  'VPS',
                  'NODE',
                  'ICMP',
                  'TCP',
                  'TEAMSPEAK',
                ].includes(upperKind);
                return (
                  <div className="grid gap-4 md:grid-cols-2">
                    <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2">
                      <p className="font-semibold text-sm">{t('asset.ssl_cert', 'TLS/SSL Certifikát')}</p>
                      {isNoSsl ? (
                        <>
                          <p className="text-xs text-muted-foreground font-medium">N/A</p>
                          <p className="text-2xs text-muted-foreground font-mono">
                            {upperKind === 'ROUTER'
                              ? t('asset.proto_router', 'OpenWrt Router telemetrie (ubus / Linux agent bez TLS)')
                              : upperKind === 'MINECRAFT'
                                ? t('asset.proto_minecraft', 'Minecraft Java socket (port 25565 bez TLS vrstvy)')
                                : upperKind === 'TEAMSPEAK' || upperKind === 'VOICE'
                                  ? t('asset.proto_teamspeak', 'TeamSpeak 3 UDP Voice socket bez TLS vrstvy')
                                  : t('asset.proto_none', 'Protokol nepoužívá SSL/TLS vrstvu')}
                          </p>
                        </>
                      ) : asset.sslCert ? (
                        <>
                          <p
                            className={cn(
                              'text-xs font-semibold flex items-center gap-1.5',
                              (asset.sslCert.days_remaining ?? 99) <= 14
                                ? 'text-warning'
                                : (asset.sslCert.days_remaining ?? 99) <= 0
                                  ? 'text-down'
                                  : 'text-up'
                            )}
                          >
                            <ShieldCheck className="size-4 shrink-0" />
                            {asset.sslCert.days_remaining != null
                              ? asset.sslCert.days_remaining <= 0
                                ? t('asset.ssl_expired', '🔴 SSL Certifikát VYPRŠEL!')
                                : t(
                                    'asset.ssl_valid_expiry',
                                    { days: asset.sslCert.days_remaining },
                                    `🟢 Platný (Vyprší za ${asset.sslCert.days_remaining} dní)`
                                  )
                              : t('asset.ssl_valid', '🟢 Platný SSL/TLS Certifikát')}
                          </p>
                          <div className="text-2xs text-muted-foreground font-mono space-y-0.5 pt-1 border-t border-border/40">
                            {asset.sslCert.issuer && (
                              <p>
                                {t('asset.ssl_issuer', 'Vydavatel:')} {asset.sslCert.issuer}
                              </p>
                            )}
                            {asset.sslCert.valid_to && (
                              <p>
                                {t('asset.ssl_valid_until', 'Platnost do:')}{' '}
                                {new Date(asset.sslCert.valid_to).toLocaleDateString('cs-CZ')}
                              </p>
                            )}
                          </div>
                        </>
                      ) : (
                        <>
                          {/* Without certificate data nothing is claimed - this used to
                            "TLS 1.3 verified" even for a monitor with no
                            certificate at all (reported on the OpenWrt router). */}
                          <p className="text-xs font-medium text-muted-foreground">
                            {t('asset.ssl_unknown', 'Certifikát zatím nebyl načten')}
                          </p>
                          <p className="text-2xs text-muted-foreground font-mono">
                            {t(
                              'asset.ssl_unknown_desc',
                              'Kontrola certifikátu proběhne při příštím HTTPS testu tohoto cíle.'
                            )}
                          </p>
                        </>
                      )}
                    </div>
                    <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2">
                      <p className="font-semibold text-sm">{t('asset.service_status', 'Stav Služby')}</p>
                      <p className={cn('text-xs font-medium', asset.status === 'down' ? 'text-down' : 'text-up')}>
                        {asset.status === 'down' ? t('common.offline', 'Offline') : t('infra.active_since', 'Aktivní')}
                      </p>
                      <p className="text-2xs text-muted-foreground font-mono">
                        {t('common.protocol', 'Protokol')}: {asset.kind}
                      </p>
                    </div>
                    {/* The one-line SMART verdict of the old agent. From 0.1.7 the
                        Storage card carries a block per disk, so this would only
                        repeat a worse version of it. */}
                    {!Array.isArray(asset.rawDetails?.storage_disks) && (
                      <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2 md:col-span-2">
                        <p className="font-semibold text-sm">
                          {t('asset.smart_status', 'SMART SSD Health & NVMe Opotřebení Disku')}
                        </p>
                        {(() => {
                          // "N/A (smartctl missing)" is not a healthy state - it is a missing
                          // tool and the admin should know what to install.
                          const raw = asset.smartStatus ?? null;
                          const missingTool = !raw || /n\/a|chyb|not available|unavailable|missing/i.test(raw);
                          return (
                            <>
                              <p
                                className={cn(
                                  'text-xs font-medium font-mono',
                                  missingTool ? 'text-warning' : 'text-up'
                                )}
                              >
                                {raw ?? t('asset.smart_no_data', 'Nejsou dostupná data (agent SMART nehlásí).')}
                              </p>
                              <p className="text-2xs text-muted-foreground font-mono">
                                {missingTool
                                  ? t(
                                      'asset.smart_install_hint',
                                      'Pro sledování zdraví disku nainstalujte na cílovém stroji smartmontools (Debian/Ubuntu: apt install smartmontools, OpenWrt: opkg install smartmontools) a agent hodnoty začne hlásit sám.'
                                    )
                                  : t(
                                      'asset.smart_desc',
                                      'Sledování opotřebení NVMe buněk, chyb a realokovaných sektorů z rozhraní smartctl.'
                                    )}
                              </p>
                            </>
                          );
                        })()}
                      </div>
                    )}
                  </div>
                );
              })()}
          </Card>
        </TabsContent>

        <TabsContent value="events">
          <div className="space-y-4">
            {/* What went out about this monitor - the question after every
                outage, which nothing could answer until the delivery log. */}
            <NotificationLog monitorId={Number(asset.id)} />
            {serverInsights && serverInsights.timeline.length > 0 && (
              <Card className="p-6 space-y-4">
                <div className="flex items-center gap-3 border-b border-border pb-3">
                  <Settings2 className="size-5 text-primary" />
                  <div>
                    <h3 className="font-bold text-base">{t('asset.system_timeline', 'Systémové události (30 dní)')}</h3>
                    <p className="text-xs text-muted-foreground">
                      {t(
                        'asset.system_timeline_desc',
                        'Změny stavu, vzdálené akce, SSL varování a překročené limity z monitor_events.'
                      )}
                    </p>
                  </div>
                </div>
                <FilterableTimeline events={mapInsightsTimeline(serverInsights.timeline, t)} />
              </Card>
            )}

            <Card className="p-6 space-y-4">
              <div className="flex items-center gap-3 border-b border-border pb-3">
                <Clock className="size-5 text-primary" />
                <div>
                  <h3 className="font-bold text-base">
                    {t(
                      'asset.events_history',
                      { name: asset.name },
                      `Historie událostí & Protokol měření (${asset.name})`
                    )}
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    {t('asset.events_history_desc', 'Záznamy kontrol, detekovaných služeb a změny stavu v čase.')}
                  </p>
                </div>
              </div>
              <FilterableTimeline events={events} />
            </Card>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}

function Hero({ asset }: { asset: AssetDetail }) {
  const { t } = useLanguage();
  const { isAdmin } = useSession();
  const upperKind = (asset.kind || '').toUpperCase();
  const Icon =
    upperKind === 'ROUTER' || asset.id === 5
      ? RouterIcon
      : upperKind === 'MINECRAFT' || asset.id === 4
        ? Gamepad2
        : upperKind === 'VOICE' || upperKind === 'TEAMSPEAK' || asset.id === 3
          ? Mic
          : upperKind === 'DISCORD' || asset.id === 2
            ? MessageSquare
            : upperKind === 'HTTPS' || upperKind === 'HTTP' || upperKind === 'WEB'
              ? Globe
              : Server;

  const statusText: Record<MonitorStatus, string> = {
    up: t('common.online', 'Online'),
    down: t('common.offline', 'Offline'),
    warning: t('common.warning', 'Varování'),
    paused: t('common.paused', 'Pozastaveno'),
    maintenance: t('common.maintenance', 'Údržba'),
    unknown: t('status.unknown', 'Neznámý'),
  };

  return (
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div className="flex items-center gap-3">
        <span className="bg-muted grid size-11 shrink-0 place-items-center rounded-xl">
          <Icon className="size-5" />
        </span>
        <div className="leading-tight">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold tracking-tight">{asset.name}</h1>
            <Badge variant={statusVariant[asset.status]} dot pulse={asset.status === 'up'}>
              {statusText[asset.status]}
            </Badge>
          </div>
          <p className="text-muted-foreground mt-0.5 text-sm">{asset.subtitle}</p>
        </div>
      </div>

      <div className="flex items-center gap-2">
        {!asset.archived && asset.remoteActionsEnabled && asset.allowedActions.length > 0 && (
          <ActionsMenu asset={asset} />
        )}
        {/* Remote Actions are switched on in the monitor's settings. Without this
            the detail simply had no Actions button and gave no hint why. */}
        {!asset.archived &&
          isAdmin &&
          (upperKind === 'ROUTER' || upperKind === 'OPENWRT') &&
          !asset.remoteActionsEnabled && (
            <Button variant="outline" size="sm" asChild>
              <a
                href={`/app/infrastructure?edit=${asset.id}&tab=advanced`}
                title={t(
                  'asset.ra_setup_title',
                  'Vzdálené akce jsou pro tento router vypnuté. Zapnete je v nastavení monitoru, záložka Rozšíření & Agent.'
                )}
              >
                <Settings2 className="size-4" /> {t('asset.ra_setup', 'Nastavit vzdálené akce')}
              </a>
            </Button>
          )}
        {!asset.archived && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              window.location.href = `/app/infrastructure?edit=${asset.id}`;
            }}
            title={t('asset.edit_monitor_title', 'Upravit nastavení monitoru')}
          >
            <Pencil className="size-4" /> {t('asset.edit_monitor', 'Upravit monitor')}
          </Button>
        )}
      </div>
    </div>
  );
}

function RangePicker({ value, onChange }: { value: TimeRange; onChange: (range: TimeRange) => void }) {
  const { t } = useLanguage();
  const timeRangeLabels: Record<TimeRange, string> = {
    '24h': t('asset.range_24h', 'Posledních 24 hodin'),
    '7d': t('asset.range_7d', 'Posledních 7 dní'),
    '30d': t('asset.range_30d', 'Posledních 30 dní'),
  };

  return (
    <div
      role="group"
      aria-label={t('asset.time_range', 'Časový rozsah')}
      className="bg-secondary/60 flex items-center rounded-md border border-input p-0.5"
    >
      {(Object.keys(timeRangeLabels) as TimeRange[]).map((range) => (
        <button
          key={range}
          type="button"
          onClick={() => onChange(range)}
          aria-pressed={value === range}
          title={timeRangeLabels[range]}
          className={cn(
            'rounded px-2.5 py-1 text-xs font-medium transition-colors',
            value === range ? 'bg-background text-foreground' : 'text-muted-foreground hover:text-foreground'
          )}
        >
          {range}
        </button>
      ))}
    </div>
  );
}

function OverviewTab({
  asset,
  range,
  events,
  serverInsights,
  assetId,
  statusChangeHint,
  recommendations,
}: {
  asset: AssetDetail;
  range: TimeRange;
  events: TimelineEvent[];
  serverInsights: ServerInsights | null;
  /** The router's full recommendation card; null for everything that is not a router. */
  recommendations?: React.ReactNode;
  /** What happened at the last status change and why; undefined = unknown yet. */
  statusChangeHint?: string;
  /** The URL segment - carried further down the path so that navigating to a
      metric and back does not land on a different ID than the user is on. */
  assetId: string | undefined;
}) {
  const { t } = useLanguage();
  // One chart fetch for the whole tab: the same data feeds the big charts below
  // and the KPI-tile sparklines above (mockup: value + delta + trend).
  const charts = useAssetCharts(asset.id, range);

  const healthWithTrends = React.useMemo<HealthMetric[]>(() => {
    return asset.health.map((m) => {
      // Its own metric only (W1-B5) - never the first chart of the same colour.
      const s = seriesForTile(m.key, charts.data);
      if (!s) return m;
      // Nulls kept on purpose - an unmeasured point is a gap in the trace.
      const values = s.points.map((p) => p.v);
      const delta = computeSeriesDelta(s);
      const goodDir = goodDirectionFor(s.tone);
      return {
        ...m,
        series: values.length >= 2 ? values : undefined,
        delta: delta ? { ...delta, good: goodDir ? delta.direction === goodDir : null } : undefined,
      };
    });
  }, [asset.health, charts.data]);

  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
      <div className="grid gap-3 xl:col-span-12 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        {serverInsights?.healthScore && <HealthScoreTile score={serverInsights.healthScore.score} />}
        {healthWithTrends.map((metric) => (
          <HealthCard key={metric.key} metric={metric} />
        ))}
      </div>

      {/*
        Density: this card took half a screen for two sentences.
        The "Live measurement state from the database" subtitle conveyed nothing -
        that the data is live shows in the numbers - and the spacing was built
        for content that is only here occasionally.
      */}
      {/* self-start: grid items stretch to the row, so two sentences were pulled
          to the height of the parameter list next to them - 200 px of card for
          40 px of text. The row still grows with whichever card is taller. */}
      <Card className="self-start xl:col-span-8">
        <CardHeader className="pb-2">
          <CardTitle>{t('asset.summary_title', 'Executive Summary')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2.5">
          {/* The server summary (bk_build_executive_summary) knows about health
              score, threshold breaches, and insights - the client-built
              asset.summary is only a generic template sentence, kept as a
              fallback until the endpoint responds. Not muted: this is the
              main content of the card, not a caption under it. */}
          <p className="text-sm leading-relaxed">{serverInsights?.summary || asset.summary}</p>

          {serverInsights && serverInsights.tips.length > 0 && (
            <div className="flex flex-col gap-1.5">
              {serverInsights.tips.map((tip, i) => (
                <div
                  key={`${tip.severity}-${i}`}
                  className={cn(
                    'flex items-start gap-2 rounded-md border px-2.5 py-1.5 text-xs',
                    tip.severity === 'critical'
                      ? 'border-down/30 bg-down/10 text-down'
                      : 'border-warning/30 bg-warning/10 text-warning'
                  )}
                >
                  <span className="font-bold shrink-0">{tip.severity === 'critical' ? '⛔' : '⚠️'}</span>
                  <span>{tip.text}</span>
                </div>
              ))}
            </div>
          )}

          {serverInsights && serverInsights.insights.length > 0 && (
            <ul className="flex flex-col gap-1 text-xs text-muted-foreground border-t border-border pt-2.5">
              {serverInsights.insights.map((ins, i) => (
                <li key={i} className="flex items-start gap-2">
                  <span className="shrink-0">💡</span>
                  <span>{ins.text}</span>
                </li>
              ))}
            </ul>
          )}

          <div className="flex flex-wrap gap-2">
            {asset.summaryChips.map((chip) => (
              <Badge key={chip.label} variant={chip.variant} dot>
                {chip.label}
              </Badge>
            ))}
          </div>
        </CardContent>
      </Card>

      <Card className="xl:col-span-4">
        <CardHeader>
          <CardTitle>{t('asset.params_title', 'Parametry monitoru / serveru')}</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="flex flex-col gap-2.5 text-sm">
            {asset.info.map((rawRow) => {
              const row =
                rawRow.label === t('common.last_change', 'Poslední změna stavu') && statusChangeHint
                  ? { ...rawRow, hint: statusChangeHint }
                  : rawRow;
              return (
                <div key={row.label} className="flex items-baseline justify-between gap-3">
                  <dt className="text-muted-foreground text-xs">{row.label}</dt>
                  <dd className="min-w-0 text-right font-medium">
                    {/* Wrapping, not truncating: a date cut in half ("12. 8. 2026 0:…")
                        is worse than a value on two lines, and `truncate` hid the end
                        of every long row here - kernel, model, board. */}
                    <span className="block [overflow-wrap:anywhere]" title={row.hint ?? undefined}>
                      {row.value}
                    </span>
                    {/* Without this the row only said WHEN the status changed. What
                      changed and why had to be dug out of the timeline below. */}
                    {row.hint && <span className="text-muted-foreground block text-2xs font-normal">{row.hint}</span>}
                  </dd>
                </div>
              );
            })}
          </dl>
        </CardContent>
      </Card>

      {/* Right under the summary: it is the to-do list the summary only hints at. */}
      {recommendations && <div className="xl:col-span-12">{recommendations}</div>}

      {/* How good this monitor has actually been - the server has computed it
          in one request all along and only the public page ever asked. */}
      <div className="xl:col-span-12">
        <AvailabilityWindows monitorId={asset.id} />
      </div>

      <div className="xl:col-span-12">
        <PerformanceCharts
          data={charts.data}
          error={charts.error}
          loading={charts.loading}
          onRetry={charts.reload}
          range={range}
          events={events}
          assetId={assetId ?? asset.id}
          monitorId={asset.id}
          thresholds={asset.thresholds}
          hasTimeSeries={asset.typeProfile.timeSeries}
        />
      </div>

      <Card className="xl:col-span-5">
        <CardHeader>
          <CardTitle>{t('asset.last_measurements', 'Posledních 5 měření')}</CardTitle>
          <CardDescription>
            {t('asset.last_measurements_desc', 'Čerstvé kontroly včetně odezvy a místa měření.')}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-1.5">
          {events.length === 0 ? (
            <p className="text-muted-foreground py-4 text-center text-sm">
              {t('asset.no_measurements', 'Zatím neproběhla žádná kontrola.')}
            </p>
          ) : (
            events.slice(0, 5).map((e) => (
              <div key={e.id} className="flex items-center gap-2.5 rounded-md border border-border px-3 py-2">
                <StatusDot variant={e.severity === 'info' ? 'paused' : e.severity} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-xs font-medium">{e.title}</p>
                  <div className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-2xs">
                    <span className="font-mono">{e.at}</span>
                    {/* The vantage point may be unrecorded - then nothing is printed. */}
                    {e.location && <span className="truncate">· {e.location}</span>}
                  </div>
                </div>
                <span className="shrink-0 font-mono text-xs font-semibold">
                  {e.responseMs == null ? '—' : formatMs(e.responseMs)}
                </span>
              </div>
            ))
          )}
        </CardContent>
      </Card>

      {/* A type that never has a process ranking gets no card. One that has it
          somewhere else (a service watched by its server's agent) gets the
          pointer instead of "no agent is connected" - the agent IS connected,
          just one level up. */}
      {asset.typeProfile.processes !== 'none' && (
        <Card className="xl:col-span-3">
          <CardHeader>
            <CardTitle>{t('asset.tab_processes', 'Nejvytíženější procesy')}</CardTitle>
          </CardHeader>
          <CardContent className="px-0">
            {asset.processes.length > 0 && (
              <p className="text-2xs text-muted-foreground px-5 pb-2">
                {t(
                  'asset.processes_top_hint',
                  'Agent hlásí 5 nejnáročnějších procesů podle CPU a 5 podle RAM z posledního reportu — není to kompletní výpis všeho, co na stroji běží.'
                )}
              </p>
            )}
            {asset.processes.length === 0 ? (
              <div className="text-xs text-muted-foreground px-5 py-6 text-center">
                <p>
                  {asset.typeProfile.processes === 'parent'
                    ? t('asset.processes_on_parent', 'Procesy sbírá agent na serveru, pod kterým tato služba běží.')
                    : asset.cpanelStats
                      ? t(
                          'asset.no_agent_cpanel_hint',
                          'Bez VPS agenta - podrobnosti o zdrojích cPanelu jsou na záložce Procesy.'
                        )
                      : t('asset.no_agent_processes', 'Zatím není připojen agent pro výpis procesů.')}
                </p>
                {asset.typeProfile.processes === 'parent' && asset.parent && (
                  <Link
                    to={`/infrastructure/${asset.parent.id}`}
                    className="text-primary mt-1.5 inline-block font-medium hover:underline"
                  >
                    {t('asset.processes_open_parent', { name: asset.parent.name }, `Otevřít ${asset.parent.name}`)}
                  </Link>
                )}
              </div>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="pl-5">{t('asset.process', 'Proces')}</TableHead>
                    <TableHead className="text-right">CPU</TableHead>
                    <TableHead className="pr-5 text-right">RAM</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {asset.processes.map((proc) => (
                    <TableRow key={proc.name}>
                      <TableCell className="pl-5 font-mono text-xs">{proc.name}</TableCell>
                      <TableCell className="tabular-nums text-right">
                        {proc.cpu != null ? formatPercent(proc.cpu, 1) : '—'}
                      </TableCell>
                      <TableCell className="tabular-nums pr-5 text-right">
                        {proc.memory != null ? `${proc.memory} MB` : '—'}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      )}

      <Card className="xl:col-span-4">
        <CardHeader>
          <CardTitle>{t('asset.detected_services', 'Detekované Služby / Porty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-1 px-2">
          {/* Besides the linked monitors we also show what the agent really reports:
              listening ports and discovered (not-yet-monitored) services -
              this data used to sit unused in the report. */}
          {asset.related.length === 0 &&
            (() => {
              const ports: number[] = Array.isArray(asset.rawDetails?.ports) ? asset.rawDetails.ports : [];
              const found: any[] = Array.isArray(asset.rawDetails?.discovered_services)
                ? asset.rawDetails.discovered_services
                : [];
              if (ports.length === 0 && found.length === 0) {
                return (
                  <p className="text-xs text-muted-foreground px-3 py-6 text-center">
                    {t('asset.no_related_services', 'Žádné navázané podslužby.')}
                  </p>
                );
              }
              return (
                <div className="space-y-3 px-3 py-2">
                  {found.length > 0 && (
                    <div className="space-y-1.5">
                      <p className="text-2xs font-semibold text-muted-foreground uppercase tracking-wide">
                        {t('asset.agent_found_services', 'Agent objevil běžící služby')}
                      </p>
                      {found.map((s, i) => (
                        <div
                          key={i}
                          className="border-border/40 flex items-center justify-between gap-2 border-b py-1.5 text-xs last:border-0"
                        >
                          <span className="font-medium truncate">
                            {s.name}
                            {s.port ? <span className="text-muted-foreground font-mono">:{s.port}</span> : null}
                          </span>
                          <span className="text-muted-foreground font-mono shrink-0">
                            {s.confidence != null ? `${s.confidence} %` : ''}
                          </span>
                        </div>
                      ))}
                      <p className="text-3xs text-muted-foreground">
                        {t('asset.agent_found_hint', 'Sledovat je můžete jedním kliknutím v přehledu Infrastruktura.')}
                      </p>
                    </div>
                  )}
                  {ports.length > 0 && (
                    <div className="space-y-1.5">
                      <p className="text-2xs font-semibold text-muted-foreground uppercase tracking-wide">
                        {t('asset.listening_ports', 'Naslouchající porty')}
                      </p>
                      <div className="flex flex-wrap gap-1.5">
                        {ports.map((p) => (
                          <span key={p} className="rounded-md bg-secondary px-2 py-0.5 font-mono text-2xs">
                            {p}
                          </span>
                        ))}
                      </div>
                    </div>
                  )}
                </div>
              );
            })()}
          {asset.related.length > 0 &&
            asset.related.map((service) => {
              const relatedStatusLabel: Record<MonitorStatus, string> = {
                up: t('common.online', 'Online'),
                down: t('common.offline', 'Offline'),
                warning: t('common.warning', 'Varování'),
                paused: t('common.paused', 'Pozastaveno'),
                maintenance: t('common.maintenance', 'Údržba'),
                unknown: t('status.unknown', 'Neznámý'),
              };
              return (
                <div
                  key={service.name}
                  className="hover:bg-muted/40 border-border/40 flex items-center gap-3 border-b px-3 py-2 transition-colors last:border-0"
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{service.name}</p>
                    <p className="text-muted-foreground truncate text-xs">
                      {service.kind} · {service.detail}
                    </p>
                  </div>
                  <Badge variant={statusVariant[service.status]} dot>
                    {relatedStatusLabel[service.status]}
                  </Badge>
                </div>
              );
            })}
        </CardContent>
      </Card>
    </div>
  );
}

/** Does the monitor carry network telemetry worth a Network tab? */
/** The router has its own cards and its own recommendations. */
function isRouterKind(kind: string | null | undefined): boolean {
  const upper = (kind || '').toUpperCase();
  return upper === 'ROUTER' || upper === 'OPENWRT';
}

function hasNetworkData(d: Record<string, any>): boolean {
  return (
    d.wan_proto != null ||
    d.lan_subnet != null ||
    (Array.isArray(d.wifi_radios) && d.wifi_radios.length > 0) ||
    (Array.isArray(d.interfaces) && d.interfaces.length > 0) ||
    d.dns_engine != null ||
    d.fw_accepted != null
  );
}

/**
 * Network telemetry from the OpenWrt/VPS agent - the agent has long collected
 * this, but the React app never displayed it (only the old status page did).
 * Each section renders only with real data; sensitive items (WAN addresses,
 * SSIDs, WG endpoints...) are sent by the API to admin sessions only.
 */
/**
 * A row and a section of the network detail.
 *
 * At module level on purpose: a component defined inside another component is
 * recreated on every render, React treats it as a different type and unmounts
 * the whole subtree. Here it would merely re-render needlessly, but it is the
 * same root cause that made inputs lose focus in the settings.
 */
/**
 * @param to When the value has a stored history, the row links to it. Most of
 *   this tab is a snapshot of the last report, but a good half of these
 *   numbers are recorded every minute and were readable only as "now".
 */
function Row({
  label,
  value,
  to,
  dash,
}: {
  label: string;
  value: React.ReactNode;
  to?: string;
  /**
   * G24: this value used to be a claim the agent made up when it did not
   * know (a resolver called "Dnsmasq", "unencrypted DNS", zero reconnects).
   * The claims are gone, so the row prints an em dash - "nobody measured
   * this" - instead of vanishing, which reads as "there is nothing here".
   */
  dash?: boolean;
}) {
  const missing = value == null || value === '';
  if (missing && !dash) return null;
  const shown = <span className="text-right font-mono font-medium">{missing ? '—' : value}</span>;
  return (
    <div className="border-border/40 flex items-center justify-between gap-3 border-b py-1.5 text-xs last:border-0">
      <span className="text-muted-foreground">{label}</span>
      {to ? (
        <Link
          to={to}
          className="hover:text-primary focus-visible:ring-ring rounded transition-colors focus-visible:ring-2 focus-visible:outline-none"
          title={label}
        >
          {shown}
        </Link>
      ) : (
        shown
      )}
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card className="p-4">
      <h4 className="font-bold text-sm mb-2">{title}</h4>
      {children}
    </Card>
  );
}

/** Bytes the way the reader would say them. */
function formatBytesShort(n: number | null | undefined): string | null {
  if (n == null || !Number.isFinite(n) || n < 0) return null;
  if (n >= 1073741824) return `${(n / 1073741824).toFixed(2)} GB`;
  if (n >= 1048576) return `${(n / 1048576).toFixed(1)} MB`;
  return `${Math.round(n / 1024)} kB`;
}

/**
 * Which bytes went over the primary line and which over the LTE backup, and
 * how long the router spent on the backup. The daily totals per interface
 * existed for years under raw device names; the roles come from what the
 * agent reports (wan_l3_device / lte_device), never guessed from a name.
 */
function LinkTrafficSection({ monitorId }: { monitorId: number }) {
  const { t, lang } = useLanguage();
  // undefined = loading, null = the request failed
  const [data, setData] = React.useState<LinkTrafficResponse | null | undefined>(undefined);
  React.useEffect(() => {
    let active = true;
    setData(undefined);
    resolveSource()
      .then(({ source }) => source.getLinkTraffic(monitorId, 30))
      .then((r) => {
        if (active) setData(r);
      })
      .catch(() => {
        if (active) setData(null);
      });
    return () => {
      active = false;
    };
  }, [monitorId]);

  const title = `🔀 ${t('net.link_traffic_title', 'Provoz podle linky')}`;
  if (data === undefined) {
    return (
      <Section title={title}>
        <LoadingState label={t('net.link_loading', 'Načítám…')} size="inline" />
      </Section>
    );
  }
  if (data === null) {
    return (
      <Section title={title}>
        <p className="text-xs text-muted-foreground">
          {t('net.link_failed', 'Provoz podle linky se nepodařilo načíst.')}
        </p>
      </Section>
    );
  }

  const windows: { key: 'today' | '7d' | '30d'; label: string }[] = [
    { key: 'today', label: t('net.link_today', 'Dnes') },
    { key: '7d', label: t('net.link_7d', '7 dní') },
    { key: '30d', label: t('net.link_30d', '30 dní') },
  ];
  const cell = (side: LinkTrafficResponse['primary'], key: 'today' | '7d' | '30d') => {
    if (!side) return '—';
    const w = side[key];
    if (!w) return t('net.link_no_data', 'zatím bez dat');
    return `↓${formatBytesShort(w.rx_bytes) ?? '—'} ↑${formatBytesShort(w.tx_bytes) ?? '—'}`;
  };
  const fmtDuration = (s: number) => {
    if (s < 60) return `${s} s`;
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    const m = Math.floor((s % 3600) / 60);
    return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}min` : `${m} min`;
  };
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const fmtTs = (ts: number | null, fallback: string) =>
    ts == null ? fallback : new Date(ts * 1000).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' });
  const wanEverDown = data.wan_down_seconds > 0 || data.wan_down_periods.length > 0;

  return (
    <Section title={title}>
      {/* Rows are separated the same way every other table on this page is -
          three columns of a grid need the border on each cell, so it is applied
          per cell rather than to a row element that does not exist here. */}
      <div className="grid grid-cols-[auto_1fr_1fr] gap-x-3 text-xs">
        <span className="border-border border-b pb-1" />
        <span className="border-border border-b pb-1 font-medium">
          {t('net.link_primary', 'Primární (WAN)')}
          {data.primary ? <span className="text-muted-foreground font-mono"> · {data.primary.iface}</span> : null}
        </span>
        <span className="border-border border-b pb-1 font-medium">
          {t('net.link_backup', 'Záloha (LTE)')}
          {data.backup ? <span className="text-muted-foreground font-mono"> · {data.backup.iface}</span> : null}
        </span>
        {windows.map((w, i) => {
          const line = i === windows.length - 1 ? 'py-1.5' : 'border-border/40 border-b py-1.5';
          return (
            <React.Fragment key={w.key}>
              <span className={`text-muted-foreground ${line}`}>{w.label}</span>
              <span className={`font-mono ${line}`}>{cell(data.primary, w.key)}</span>
              <span className={`font-mono ${line}`}>{cell(data.backup, w.key)}</span>
            </React.Fragment>
          );
        })}
      </div>
      {!data.primary && (
        <p className="text-xs text-muted-foreground mt-2">
          {t(
            'net.link_unknown_primary',
            'Agent nehlásí WAN zařízení - buď je starší než 0.1.3, nebo router nemá rozhraní jménem wan. Primární strana je neznámá.'
          )}
        </p>
      )}
      {!data.backup && (
        <p className="text-xs text-muted-foreground mt-1">{t('net.link_no_backup', 'Bez LTE zařízení.')}</p>
      )}
      {/* What is measured is the primary link being down (wan_lost/wan_restored).
          Whether the backup carried the traffic meanwhile is what its byte
          counts above say - a router with no LTE has outages too. */}
      <Row
        label={t('net.link_time_on_backup', 'Výpadky primární linky (30 dní)')}
        value={wanEverDown ? fmtDuration(data.wan_down_seconds) : t('net.link_never', 'žádný')}
      />
      {data.wan_down_now && (
        <p className="text-xs text-down mt-1">{t('net.link_on_backup_now', 'Primární linka je teď mimo provoz.')}</p>
      )}
      {data.wan_down_periods.length > 0 && (
        <div className="mt-2 text-xs">
          <div className="text-muted-foreground mb-1">{t('net.link_periods', 'Období bez primární linky')}</div>
          {data.wan_down_periods
            .slice(-5)
            .reverse()
            .map((p, i) => (
              <div key={i} className="font-mono flex justify-between gap-2">
                <span>
                  {fmtTs(p.from, t('net.link_since_before', 'před začátkem okna'))} →{' '}
                  {fmtTs(p.to, t('net.link_still', 'dosud'))}
                </span>
                <span className="text-muted-foreground">{fmtDuration(p.seconds)}</span>
              </div>
            ))}
        </div>
      )}
    </Section>
  );
}

function NetworkTab({
  d,
  monitorId,
  assetId,
  recommendations,
  wanBottleneck,
  speedtest,
}: {
  d: Record<string, any>;
  monitorId: number;
  /** For linking a row to the history of that metric. */
  assetId: string | number;
  /** The compact recommendations of one area, placed next to the card they are about (routers only). */
  recommendations?: (area: 'wifi' | 'wan') => React.ReactNode;
  /** The WAN verdict card; it explains the speed tests below it, so it comes first. */
  wanBottleneck?: React.ReactNode;
  /** The router's speed tests belong with its network, not with its services. */
  speedtest?: React.ReactNode;
}) {
  // Rows whose number is also a stored metric: measured every minute, kept for
  // months, and until now readable only as its latest value.
  const history = (key: string) => `/infrastructure/${assetId}/metric/${monitorId}/${key}`;
  const { t } = useLanguage();

  // "x minutes ago" labels need the clock, which is impure by definition.
  // It is read once on mount into state - within a single render all the
  // fields are therefore computed against the same moment.
  const [nowSecs] = React.useState(() => Math.floor(Date.now() / 1000));

  const fmtAgo = (ts: unknown) => {
    const n = typeof ts === 'number' ? ts : parseInt(String(ts ?? ''), 10);
    if (!Number.isFinite(n) || n <= 0) return null;
    const secs = Math.max(0, nowSecs - n);
    if (secs < 120) return t('net.just_now', 'před chvílí');
    if (secs < 7200) return `${Math.round(secs / 60)} min`;
    if (secs < 172800) return `${Math.round(secs / 3600)} h`;
    return `${Math.round(secs / 86400)} d`;
  };
  const fmtDur = (s: unknown) => {
    const n = typeof s === 'number' ? s : parseInt(String(s ?? ''), 10);
    if (!Number.isFinite(n) || n <= 0) return null;
    const dPart = Math.floor(n / 86400),
      h = Math.floor((n % 86400) / 3600),
      m = Math.floor((n % 3600) / 60);
    return dPart > 0 ? `${dPart}d ${h}h` : h > 0 ? `${h}h ${m}min` : `${m} min`;
  };
  const fmtBytes = (b: unknown) => {
    const n = typeof b === 'number' ? b : parseFloat(String(b ?? ''));
    if (!Number.isFinite(n) || n < 0) return null;
    if (n >= 1073741824) return `${(n / 1073741824).toFixed(2)} GB`;
    if (n >= 1048576) return `${(n / 1048576).toFixed(1)} MB`;
    return `${Math.round(n / 1024)} kB`;
  };

  const wifi: any[] = Array.isArray(d.wifi_radios) ? d.wifi_radios : [];
  const lteOverall = lteVerdict(d.lte_rsrp, d.lte_rsrq, d.lte_sinr);
  const logSpan = logWindow(d.log_window_secs);
  const wg: any[] = Array.isArray(d.wireguard_peers) ? d.wireguard_peers : [];
  const ifaces: any[] = Array.isArray(d.interfaces) ? d.interfaces : [];
  const restarts =
    d.service_restarts && typeof d.service_restarts === 'object'
      ? Object.entries(d.service_restarts).filter(([, v]) => Number(v) > 0)
      : [];
  const dnsTotal = (Number(d.dns_cache_hits) || 0) + (Number(d.dns_cache_misses) || 0);
  // The same verdict the Services tile shows and the server alerts on
  // (wan_lost) - this row used to say "Online" from wan_up alone while the
  // tile next to it was red.
  const wan = wanLinkState(d);

  return (
    <div className="space-y-4">
      {/* Wi-Fi first and full width: with the radio profile, the client lines
          and five readings per radio it no longer fits a grid cell. Its
          recommendations sit at its top, so the finding and the numbers it
          came from are on one screen. */}
      {wifi.length > 0 && (
        <Card className="space-y-3 p-4">
          <h4 className="text-sm font-bold">
            📶 Wi-Fi ({d.wifi_clients_count ?? wifi.reduce((s, r) => s + (Number(r.clients) || 0), 0)}{' '}
            {t('net.clients', 'klientů')})
          </h4>
          {recommendations?.('wifi')}
          <WifiRadioList radios={wifi} history={history} />
        </Card>
      )}
      {recommendations?.('wan')}
      {wanBottleneck}
      {speedtest}
      {/* The wiring of the household itself. Only the OpenWrt agent reports a
          switch, so nothing else gets a card that could only say "no data". */}
      {d.agent_type === 'openwrt' && (
        <LanPortMap lanPorts={d.lan_ports} agentVersion={typeof d.version === 'string' ? d.version : null} />
      )}

      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {(d.wan_proto != null || d.wan_up != null || d.wan_internet != null) && (
          <Section title={`🌐 ${t('net.wan_title', 'WAN připojení')}`}>
            <Row
              label={t('common.status', 'Stav')}
              value={
                wan.ok === null
                  ? null
                  : wan.reason === 'no_internet'
                    ? t('rsvc.wan_no_internet', 'Nahoře, ale bez internetu')
                    : wan.ok
                      ? t('common.online', 'Online')
                      : t('common.offline', 'Offline')
              }
            />
            <Row label={t('net.proto', 'Protokol')} value={d.wan_proto} />
            <Row label="IPv4" value={d.wan_ipv4} />
            <Row label="IPv6" value={d.wan_ipv6} />
            <Row label={t('net.gateway', 'Brána')} value={d.wan_gateway} />
            <Row label="DNS" value={d.wan_dns} />
            <Row label={t('net.wan_uptime', 'WAN uptime')} value={fmtDur(d.wan_uptime)} to={history('wan_uptime')} />
            {/* Until 0.1.7 this was 0 whenever the router could not count
                them, which read as a perfectly stable line (G24). */}
            <Row label={t('net.reconnects', 'Reconnecty (od startu)')} value={d.wan_reconnect_count} dash />
            <Row label={t('net.last_reconnect', 'Poslední reconnect')} value={fmtAgo(d.wan_last_reconnect)} />
            {d.mwan3_active_gw != null && <Row label="mwan3" value={String(d.mwan3_active_gw)} />}
          </Section>
        )}

        {(d.lan_subnet != null || d.dhcp_leases_count != null) && (
          <Section title={`🏠 ${t('net.lan_title', 'LAN & DHCP')}`}>
            <Row label={t('net.subnet', 'Subnet')} value={d.lan_subnet} />
            <Row
              label={t('net.dhcp_leases', 'Aktivní DHCP lease')}
              value={d.dhcp_leases_count}
              to={history('dhcp_leases_count')}
            />
            <Row
              label={t('net.dhcp_reservations', 'Rezervace')}
              value={d.dhcp_reservations_count}
              to={history('dhcp_reservations_count')}
            />
          </Section>
        )}

        {/* The section used to hang on `dns_engine`, which the agent always
            filled with "Dnsmasq" whether it knew or not (G24). With the claim
            gone the section has to survive an unknown engine - and a resolver
            that answers nothing is exactly when it must be on the page. */}
        {(d.dns_engine != null || d.dns_resolver_ok != null || d.dns_queries != null || d.dns_latency_ms != null) && (
          <Section title="🧭 DNS">
            <Row label={t('net.dns_engine', 'Resolver')} value={d.dns_engine} dash />
            {'dns_resolver_ok' in d && (
              <Row label={t('net.dns_resolver', 'DNS resolver')} value={dnsResolverText(d, t)} dash />
            )}
            <Row label={t('net.dns_encryption', 'Šifrování')} value={d.dns_encryption} dash />
            <Row label={t('net.dns_servers', 'Servery')} value={d.dns_servers} dash />
            <Row label={t('net.dns_queries', 'Dotazy')} value={d.dns_queries} to={history('dns_queries')} />
            <Row
              label={t('net.dns_cache', 'Cache hit rate')}
              value={dnsTotal > 0 ? `${Math.round((Number(d.dns_cache_hits) / dnsTotal) * 100)} %` : null}
            />
            <Row
              label={t('net.dns_latency', 'Latence dotazu')}
              value={d.dns_latency_ms != null ? `${Math.round(d.dns_latency_ms)} ms` : null}
            />
          </Section>
        )}

        {(d.fw_accepted != null || d.conntrack_pct != null) && (
          <Section title={`🛡 ${t('net.fw_title', 'Firewall & Conntrack')}`}>
            <Row label={t('net.fw_accepted', 'Přijato paketů')} value={d.fw_accepted} to={history('fw_accepted')} />
            <Row label={t('net.fw_dropped', 'Zahozeno')} value={d.fw_dropped} to={history('fw_dropped')} />
            <Row label={t('net.fw_rejected', 'Odmítnuto')} value={d.fw_rejected} to={history('fw_rejected')} />
            <Row
              label="Conntrack"
              value={
                d.conntrack_pct != null
                  ? `${d.conntrack_pct} %${d.conntrack_count != null ? ` (${d.conntrack_count})` : ''}`
                  : null
              }
            />
          </Section>
        )}

        {wg.length > 0 && (
          <Section title={`🔒 WireGuard (${wg.length})`}>
            {wg.map((p, i) => (
              <div key={i} className="py-1.5 border-b border-border/40 last:border-0 text-xs">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-mono">{p.public_key ?? p.interface}</span>
                  <span className="text-muted-foreground">
                    {fmtAgo(p.latest_handshake)
                      ? `${t('net.handshake', 'handshake před')} ${fmtAgo(p.latest_handshake)}`
                      : t('net.no_handshake', 'bez handshake')}
                  </span>
                </div>
                {(p.rx_bytes != null || p.tx_bytes != null) && (
                  <p className="text-muted-foreground mt-0.5">
                    ↓ {fmtBytes(p.rx_bytes) ?? '—'} · ↑ {fmtBytes(p.tx_bytes) ?? '—'}
                    {p.endpoint ? ` · ${p.endpoint}` : ''}
                  </p>
                )}
              </div>
            ))}
          </Section>
        )}

        {ifaces.length > 0 && (
          <Section title={`🔌 ${t('net.ifaces_title', 'Rozhraní')} (${ifaces.length})`}>
            {ifaces.map((it, i) => (
              <div
                key={i}
                className="py-1 border-b border-border/40 last:border-0 text-xs flex items-center justify-between gap-2"
              >
                <span className="font-mono font-medium">{it.name ?? it.iface}</span>
                <span className="text-muted-foreground font-mono">
                  {it.up != null && <span className={it.up ? 'text-up' : 'text-down'}>{it.up ? '●' : '○'} </span>}
                  {fmtBytes(it.rx_bytes) != null ? `↓${fmtBytes(it.rx_bytes)}` : ''}{' '}
                  {fmtBytes(it.tx_bytes) != null ? `↑${fmtBytes(it.tx_bytes)}` : ''}
                  {/* The agent reports rx_errors and tx_errors; the renderer used to
                    read `errors`, which nobody sends, so interface errors were
                    collected every minute and never shown. */}
                  {(Number(it.rx_errors) || 0) + (Number(it.tx_errors) || 0) + (Number(it.errors) || 0) > 0
                    ? ` · ⚠ ${(Number(it.rx_errors) || 0) + (Number(it.tx_errors) || 0) + (Number(it.errors) || 0)} err`
                    : ''}
                </span>
              </div>
            ))}
          </Section>
        )}

        {(d.lte_device != null || d.wan_l3_device != null || d.lte_up != null) && (
          <LinkTrafficSection monitorId={monitorId} />
        )}

        {/* The daily rows behind those totals - kept for a long time, summed by
          the only reader, and never drawn. */}
        <InterfaceTrafficDaily monitorId={monitorId} />

        {(d.sqm_enabled != null || d.lte_rsrp != null || d.lte_up != null) && (
          <Section title={`⚙️ ${t('net.link_title', 'SQM & LTE')}`}>
            {d.sqm_enabled != null && (
              <Row
                label="SQM"
                value={
                  d.sqm_enabled
                    ? `${t('common.online', 'Online')}${d.sqm_download_kbps ? ` · ↓${Math.round(d.sqm_download_kbps / 1000)} Mb/s` : ''}${d.sqm_upload_kbps ? ` ↑${Math.round(d.sqm_upload_kbps / 1000)} Mb/s` : ''}`
                    : t('net.sqm_off', 'Vypnuto')
                }
              />
            )}
            <Row label={t('net.sqm_dropped', 'SQM zahozeno')} value={d.sqm_dropped} />
            <Row label="SQM ECN" value={d.sqm_ecn != null ? (d.sqm_ecn ? 'ECN' : 'noECN') : null} />
            {/* The connection is detectable even without ModemManager (ubus
              the signal does not - hence reported separately, and missing metrics
              stay empty instead of an excuse. */}
            {/* Interface state and backup verdict are two different things: the
              interface is up with no SIM in a HiLink modem. Both are shown. */}
            <Row
              label={t('net.lte_state', 'LTE spojení')}
              value={
                d.lte_up == null
                  ? null
                  : d.lte_up
                    ? `${t('net.lte_iface_up', 'Rozhraní běží')}${d.lte_device ? ` · ${d.lte_device}` : ''}${
                        d.lte_uptime != null ? ` · ${formatUptime(d.lte_uptime)}` : ''
                      }`
                    : t('common.offline', 'Offline')
              }
            />
            <Row
              label={t('net.lte_backup', 'LTE záloha')}
              value={(() => {
                const b = lteBackupState(d);
                if (b.ok === true) return t('net.lte_backup_ok', 'Funkční - modem přihlášen, SIM připravená');
                if (b.ok === false)
                  return {
                    no_sim: t('net.lte_backup_no_sim', 'NEFUNKČNÍ - SIM karta nenalezena'),
                    pin_required: t('net.lte_backup_pin', 'NEFUNKČNÍ - SIM čeká na PIN'),
                    puk_required: t('net.lte_backup_puk', 'NEFUNKČNÍ - SIM zablokovaná (PUK)'),
                    invalid: t('net.lte_backup_invalid', 'NEFUNKČNÍ - SIM neplatná'),
                    not_connected: t('net.lte_backup_not_connected', 'NEFUNKČNÍ - modem není přihlášen do sítě'),
                    interface_down: t('net.lte_backup_iface_down', 'NEFUNKČNÍ - rozhraní vypnuté'),
                  }[b.reason ?? 'not_connected'];
                return d.lte_up === true ? t('net.lte_backup_unverified', 'Neověřeno - modem nehlásí stav SIM') : null;
              })()}
            />
            <Row label={t('net.lte_ip', 'LTE adresa')} value={d.lte_ipv4} />
            {/* Three raw numbers used to sit here with no scale and no verdict.
              Read together they also say WHICH problem it is: a weak signal is
              distance and antenna, good signal with bad quality is
              interference, which moving the antenna does not fix. */}
            {lteOverall && (
              <Row
                label={t('net.lte_quality', 'Kvalita LTE signálu')}
                value={
                  <span className="inline-flex items-center gap-2">
                    <Badge variant={signalTone(lteOverall.level)} className="text-3xs">
                      {signalLevelLabel(t, lteOverall.level)}
                    </Badge>
                  </span>
                }
              />
            )}
            {/* The verdict already knew WHAT helps (antenna higher, or: that is
              interference, moving it will not help) and only printed a word.
              The sentence was hidden in three tooltips, one per number. */}
            {lteOverall && lteOverall.advice !== 'none' && (
              <p data-testid="lte-advice" className="text-foreground/90 border-border/40 border-b py-1.5 text-xs">
                <span className="font-semibold">{t('signal.what_to_do', 'Co s tím:')}</span>{' '}
                {signalAdvice(t, lteOverall.advice)}
              </p>
            )}
            <SignalReading
              label="LTE RSRP"
              value={d.lte_rsrp != null ? `${d.lte_rsrp} dBm` : null}
              rating={rateRsrp(d.lte_rsrp)}
              helpKey="rsrp"
            />
            <SignalReading
              label="LTE RSRQ"
              value={d.lte_rsrq != null ? `${d.lte_rsrq} dB` : null}
              rating={rateRsrq(d.lte_rsrq)}
              helpKey="rsrq"
            />
            <SignalReading
              label="LTE SINR"
              value={d.lte_sinr != null ? `${d.lte_sinr} dB` : null}
              rating={rateSinr(d.lte_sinr)}
              helpKey="sinr"
            />
            <Row
              label={t('net.lte_band', 'Pásmo / operátor')}
              value={[d.lte_band, d.lte_carrier].filter(Boolean).join(' · ') || null}
            />
            {d.lte_up === true && d.lte_rsrp == null && (
              <p className="text-muted-foreground col-span-full text-2xs leading-relaxed">
                {t(
                  'net.lte_no_signal_data',
                  'Spojení běží, ale sílu signálu router nehlásí — modem není dostupný přes ModemManager. Doinstalováním balíčku umodem-manager (nebo uqmi) začne agent hlásit i RSRP, RSRQ a pásmo.'
                )}
              </p>
            )}
            <Row
              label="Tailscale"
              value={
                d.tailscale_up != null
                  ? `${d.tailscale_up ? t('common.online', 'Online') : t('common.offline', 'Offline')}${d.tailscale_peers != null ? ` · ${d.tailscale_peers} peerů` : ''}`
                  : null
              }
            />
            <Row
              label="ZeroTier"
              value={d.zerotier_networks != null && d.zerotier_networks > 0 ? `${d.zerotier_networks}× síť` : null}
            />
            <Row
              label="UPS"
              value={
                d.ups_status != null
                  ? `${d.ups_status}${d.ups_battery_pct != null ? ` · baterie ${d.ups_battery_pct} %` : ''}`
                  : null
              }
            />
          </Section>
        )}

        {(d.installed_packages != null ||
          d.log_errors_24h != null ||
          restarts.length > 0 ||
          d.entropy != null ||
          d.agent_run_ms != null) && (
          <Section title={`🧰 ${t('net.sys_title', 'Systém & Služby')}`}>
            <Row
              label={t('net.packages', 'Balíčky (instalované / aktualizace)')}
              value={
                d.installed_packages != null
                  ? `${d.installed_packages}${d.upgradable_packages != null ? ` / ${d.upgradable_packages}` : ''}`
                  : null
              }
            />
            {/* The agent counts the last 500 lines of logread, which is twenty
              minutes on a chatty router and a week on a quiet one - "24 h" was
              never true. Agent 0.1.8 says how far back the lines reach. */}
            <Row
              label={
                logSpan
                  ? t(
                      logSpan.unit === 'h' ? 'net.log_errors_window_h' : 'net.log_errors_window_min',
                      { n: logSpan.value },
                      `Chyby v logu za posledních ${logSpan.value} ${logSpan.unit}`
                    )
                  : t('net.log_errors_500', 'Chyby v posledních 500 řádcích logu')
              }
              value={d.log_errors_24h}
              to={history('log_errors_24h')}
            />
            <LogErrorLines view={readLogLines(d)} />
            <Row
              label={
                logSpan
                  ? t(
                      logSpan.unit === 'h' ? 'net.log_warnings_window_h' : 'net.log_warnings_window_min',
                      { n: logSpan.value },
                      `Varování v logu za posledních ${logSpan.value} ${logSpan.unit}`
                    )
                  : t('net.log_warnings_500', 'Varování v posledních 500 řádcích logu')
              }
              value={d.log_warnings_24h}
              to={history('log_warnings_24h')}
            />
            <Row label={t('net.entropy', 'Entropie')} value={d.entropy} to={history('entropy')} />
            {/* G42: what the minute run itself costs. Until 0.1.7 a run that
                found the previous one still going, or failed to send, left no
                trace at all - the minute was simply missing from the charts. */}
            <Row
              label={t('net.agent_run', 'Doba běhu agenta')}
              value={agentRunText(d, t)}
              to={history('agent_run_ms')}
            />
            <Row label={t('net.agent_skipped', 'Vynechané běhy')} value={agentSkippedText(d, t)} />
            <Row label={t('net.agent_reports', 'Hlášení za 24 h')} value={reportsReceivedText(d, t)} />
            <Row
              label={t('net.oom_kills', 'OOM kills (od startu)')}
              value={d.oom_kills != null && d.oom_kills > 0 ? d.oom_kills : d.oom_kills === 0 ? '0' : null}
            />
            <Row
              label={t('net.boot_time', 'Systém běží od')}
              value={
                d.boot_time != null && d.boot_time > 0 ? new Date(d.boot_time * 1000).toLocaleString('cs-CZ') : null
              }
            />
            <Row
              label="OpenVPN"
              value={d.openvpn_tunnels != null && d.openvpn_tunnels > 0 ? `${d.openvpn_tunnels}× tunel` : null}
            />
            <Row
              label={t('net.usb_devices', 'USB zařízení')}
              value={d.usb_devices != null && d.usb_devices > 0 ? d.usb_devices : null}
            />
            <Row label="Btrfs errors" value={d.btrfs_errors != null && d.btrfs_errors > 0 ? d.btrfs_errors : null} />
            {restarts.length > 0 && (
              <div className="pt-1.5 text-xs">
                <p className="text-muted-foreground mb-1">
                  {t('net.service_restarts', 'Restarty služeb (od startu agenta):')}
                </p>
                {restarts.map(([name, cnt]) => (
                  <p key={name} className="font-mono">
                    {name}: {String(cnt)}×
                  </p>
                ))}
              </div>
            )}
          </Section>
        )}
      </div>
    </div>
  );
}

/**
 * The "Actions" dropdown from the mockup - real Remote Actions. The server
 * signs the action with the monitor's HMAC key and the agent runs it on its
 * next report (within ~1 min); the result then appears in the system timeline.
 * restart_service asks for the service name (suggested from the monitor's watched processes).
 */
function ActionsMenu({ asset }: { asset: AssetDetail }) {
  const { t } = useLanguage();
  const [open, setOpen] = React.useState(false);
  const [busy, setBusy] = React.useState(false);
  const [result, setResult] = React.useState<{ ok: boolean; text: string } | null>(null);
  const wrapRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    const onDown = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

  const labels: Record<string, string> = {
    restart_wan: t('asset.ra_restart_wan', 'Restart WAN'),
    restart_wireguard: t('asset.ra_restart_wireguard', 'Restart WireGuard'),
    reboot_router: t('asset.ra_reboot_router', 'Restartovat router'),
    renew_dhcp: t('asset.ra_renew_dhcp', 'Obnovit DHCP'),
    restart_service: t('asset.ra_restart_service', 'Restartovat službu…'),
    reconnect_pppoe: t('asset.ra_reconnect_pppoe', 'Reconnect PPPoE'),
  };

  const trigger = async (action: string) => {
    setOpen(false);
    let serviceName: string | undefined;
    if (action === 'restart_service') {
      const suggestion = (asset.monitoredProcesses ?? '').split(',')[0]?.trim() || '';
      const input = window.prompt(
        t('asset.ra_service_prompt', 'Název služby k restartu (např. kresd, nginx):'),
        suggestion
      );
      if (input == null) return;
      serviceName = input.trim();
      if (!/^[A-Za-z0-9_.@-]{1,64}$/.test(serviceName)) {
        setResult({
          ok: false,
          text: t('asset.ra_service_invalid', 'Neplatný název služby (povolené znaky: písmena, číslice, _.@-).'),
        });
        return;
      }
    }
    if (
      action === 'reboot_router' &&
      !window.confirm(t('asset.ra_reboot_confirm', 'Opravdu restartovat celý router? Bude chvíli nedostupný.'))
    ) {
      return;
    }
    setBusy(true);
    setResult(null);
    try {
      const res = await fetch('/status/api.php?action=trigger_remote_action', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ monitorId: asset.id, action, serviceName }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setResult({
        ok: true,
        text: t(
          'asset.ra_queued',
          'Akce zařazena do fronty — agent ji provede při příštím reportu (do ~1 min). Výsledek uvidíte v systémové časové ose.'
        ),
      });
    } catch (e) {
      setResult({
        ok: false,
        text: e instanceof Error ? e.message : t('asset.ra_failed', 'Akci se nepodařilo zařadit.'),
      });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="relative" ref={wrapRef}>
      <Button variant="outline" size="sm" disabled={busy} onClick={() => setOpen((o) => !o)}>
        <Settings2 className="size-4" /> {busy ? t('asset.ra_working', 'Zařazuji…') : t('common.actions', 'Akce')} ▾
      </Button>
      {open && (
        <div className="absolute right-0 z-40 mt-2 w-56 rounded-xl border border-border bg-card p-1.5 shadow-2xl animate-in fade-in-50 zoom-in-95">
          {asset.allowedActions.map((a) => (
            <button
              key={a}
              type="button"
              onClick={() => trigger(a)}
              className="hover:bg-secondary flex w-full items-center rounded-lg px-3 py-2 text-left text-xs font-medium transition-colors"
            >
              {labels[a] ?? a}
            </button>
          ))}
        </div>
      )}
      {result && (
        <p
          className={cn(
            'absolute right-0 top-full z-30 mt-1 w-72 rounded-lg border p-2 text-2xs font-medium shadow-lg',
            result.ok ? 'border-up/30 bg-card text-up' : 'border-destructive/40 bg-card text-destructive'
          )}
        >
          {result.text}
        </p>
      )}
    </div>
  );
}

function HealthScoreTile({ score }: { score: number }) {
  const { t } = useLanguage();
  const label =
    score >= 90
      ? t('asset.score_excellent', 'Výborné')
      : score >= 70
        ? t('asset.score_good', 'Dobré')
        : t('asset.score_poor', 'Vyžaduje pozornost');
  const toneCls = score >= 90 ? 'text-up' : score >= 70 ? 'text-warning' : 'text-down';
  return (
    <Card className="p-3.5 flex flex-col gap-1">
      <p className="text-xs text-muted-foreground font-medium flex items-center gap-1.5">
        <ShieldCheck className={cn('size-3.5', toneCls)} /> {t('asset.health_score_label', 'Health Score')}
      </p>
      <div className="flex items-baseline gap-1">
        <span className={cn('tabular-nums text-xl font-bold tracking-tight', toneCls)}>{score}</span>
        <span className="text-muted-foreground text-xs font-medium">/ 100</span>
      </div>
      <p className={cn('text-2xs font-semibold', toneCls)}>{label}</p>
    </Card>
  );
}

/** The same tokens the sparkline strokes with, so a tile reads as one thing. */
const TONE_TEXT: Record<NonNullable<HealthMetric['tone']>, string> = {
  latency: 'text-chart-latency',
  cpu: 'text-chart-cpu',
  memory: 'text-chart-memory',
  disk: 'text-chart-disk',
  temperature: 'text-chart-temperature',
};

function HealthCard({ metric }: { metric: HealthMetric }) {
  return (
    <Card className="p-3.5 flex flex-col gap-1">
      <p className="text-xs text-muted-foreground font-medium">{metric.label}</p>
      <div className="flex items-baseline gap-1.5">
        {/* One metric, one colour. The number used to be picked from a
            hand-written hue ladder (CPU amber) while the trace right below it
            used the chart token (CPU green), so the tile contradicted itself. */}
        <p
          className={cn('text-base font-bold', metric.tone ? `${TONE_TEXT[metric.tone]} font-mono` : 'text-foreground')}
        >
          {metric.value}
        </p>
        {metric.delta && (
          <span
            className={cn(
              'tabular-nums text-2xs font-semibold',
              metric.delta.good === null ? 'text-muted-foreground' : metric.delta.good ? 'text-up' : 'text-down'
            )}
          >
            {metric.delta.direction === 'up' ? '↑' : '↓'} {metric.delta.pct} %
          </span>
        )}
      </div>
      {/* An average that hides one saturated core is the most misread number
          on a router page - the line says so right under it. */}
      {metric.hint && <p className="text-muted-foreground text-2xs">{metric.hint}</p>}
      {metric.series && metric.tone && (
        <div className="mt-auto pt-0.5">
          <Sparkline data={metric.series} tone={metric.tone} className="h-7 w-full" />
        </div>
      )}
    </Card>
  );
}

/**
 * Paints the monitor's own limits onto a chart that has one.
 *
 * The chart component has drawn threshold bands since the metric detail was
 * built, and the overview never passed any: the same CPU chart showed the
 * limit on one page and not on the other, so "is 78 % close to the line?"
 * could only be answered by clicking through.
 */
function withBands(
  chart: ChartData,
  thresholds: { cpu: number | null; ram: number | null; hdd: number | null } | undefined,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): ChartData {
  const limit =
    chart.id === 'cpu'
      ? thresholds?.cpu
      : chart.id === 'ram'
        ? thresholds?.ram
        : chart.id === 'hdd'
          ? thresholds?.hdd
          : null;
  if (limit == null || limit <= 0 || chart.bands) return chart;
  // The warning band is the same fifteen points below the limit the server
  // derives for its own alerts, so the chart and the alert agree.
  return {
    ...chart,
    bands: [
      { from: Math.max(0, limit - 15), to: limit, tone: 'warning', label: t('metric.band_warning', 'Varování') },
      { from: limit, to: 100, tone: 'critical', label: t('metric.band_critical', 'Kritické') },
    ],
  };
}

function PerformanceCharts({
  data: rawData,
  error,
  loading,
  range,
  events = [],
  assetId,
  monitorId,
  thresholds,
  hasTimeSeries = true,
  onRetry,
}: {
  data: ChartData[] | null;
  error: Error | null;
  loading: boolean;
  /** Refetches after a failure; the error state offers it as "try again". */
  onRetry?: () => void;
  range: TimeRange;
  events?: TimelineEvent[];
  /** For linking through to the metric detail (Level 3). */
  assetId: string | number;
  monitorId: number;
  /** The monitor's effective limits, so the charts show the same line the alerts use. */
  thresholds?: { cpu: number | null; ram: number | null; hdd: number | null };
  /** False = this monitor TYPE stores no metric history, so "no data" is not news. */
  hasTimeSeries?: boolean;
}) {
  const { t } = useLanguage();

  // Monitor events as vertical markers in ALL charts - an outage or restart is
  // visible right where the metric jumped. MySQL datetimes are parsed via the
  // 'T' variant (Safari cannot handle a bare 'YYYY-MM-DD HH:MM').
  const chartEvents = React.useMemo(() => {
    return events
      .map((e) => {
        const ms = Date.parse(String(e.at).replace(' ', 'T'));
        return Number.isNaN(ms)
          ? null
          : {
              t: ms,
              label: e.title,
              // The severity the event list already carries, so an outage
              // marker stands out from a routine note.
              severity: e.severity === 'down' || e.severity === 'warning' ? ('alert' as const) : ('info' as const),
            };
      })
      .filter((e): e is { t: number; label: string; severity: 'alert' | 'info' } => e != null);
  }, [events]);

  const data = React.useMemo(() => {
    if (rawData && rawData.length > 0 && rawData.some((c) => c.series.some((s) => s.points.length > 0))) {
      return rawData;
    }
    return null;
  }, [rawData]);

  // A failed request is never "no data": the empty copy below is reserved for
  // a server that answered and had nothing measured.
  if (error) {
    return (
      <ErrorState
        onRetry={onRetry}
        message={
          <>
            <p>{t('asset.charts_load_error', 'Grafy se nepodařilo načíst')}</p>
            <p className="mt-0.5 font-normal opacity-80">{error.message}</p>
          </>
        }
      />
    );
  }

  if (loading) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {['cpu', 'ram', 'hdd', 'latency'].map((key) => (
          <div key={key} className="p-6 rounded-xl bg-card border border-border h-48 animate-pulse" />
        ))}
      </div>
    );
  }

  if (!data || data.length === 0) {
    // A type that never stores a time series (an agent-side service check, a
    // heartbeat) used to get a full-width box announcing an empty database.
    // Nothing is missing there, so one line says it and the page moves on.
    if (!hasTimeSeries) {
      return (
        <p className="text-muted-foreground text-xs">
          {t('asset.no_series_type', 'Tento typ monitoru neukládá časové řady - sleduje se jen dostupnost.')}
        </p>
      );
    }
    return (
      <div className="p-8 rounded-lg bg-secondary/30 border border-border text-center text-xs text-muted-foreground space-y-1">
        <p className="font-semibold text-foreground text-sm">
          {t('asset.no_chart_data', 'Data pro tento monitor nejsou v databázi k dispozici')}
        </p>
        <p>
          {t(
            'asset.no_chart_data_desc',
            { range },
            `Nebyla nalezena žádná naměřená historie časových řad pro zadaný rozsah ${range}.`
          )}
        </p>
      </div>
    );
  }

  // Cards for the curated set, a compact list for everything else the device
  // reports. `featured` undefined means an older source that only ever
  // returned the curated set - it keeps its cards.
  const featured = data.filter((c) => c.featured !== false);
  const others = data.filter((c) => c.featured === false);

  // Both links on one chart, stacked. "Did the backup carry the traffic while
  // the primary was down?" needed two cards and a mental overlay; stacked, the
  // height is the total and each band is one link's share.
  const wan = featured.find((c) => c.id === 'net');
  const lte = featured.find((c) => c.id === 'net_lte');
  const combined: ChartData | null =
    wan && lte && lte.series[0]?.points.some((p) => p.v != null && p.v > 0)
      ? {
          id: 'net-combined',
          title: t('asset.traffic_combined', 'Provoz po linkách (WAN + LTE)'),
          yMax: null,
          yMin: 0,
          stacked: true,
          series: [
            { ...wan.series[0], label: t('net.link_primary', 'Primární (WAN)') },
            { ...lte.series[0], label: t('net.link_backup', 'Záloha (LTE)') },
          ],
        }
      : null;

  return (
    <div className="flex flex-col gap-4">
      {combined && <ChartCard data={combined} group="asset-performance" />}

      <div className="grid gap-4 lg:grid-cols-2">
        {featured.map((chart) => (
          // Link through to Level 3. The legacy page had a metric detail too, but
          // there was no way to reach it from here - and what cannot be reached
          // does not exist.
          <ChartCard
            key={chart.id}
            data={withBands(
              chartEvents.length > 0 ? { ...chart, events: [...(chart.events ?? []), ...chartEvents] } : chart,
              thresholds,
              t
            )}
            group="asset-performance"
            to={`/infrastructure/${assetId}/metric/${monitorId}/${chart.id}`}
          />
        ))}
      </div>

      {others.length > 0 && (
        <Card className="space-y-3 p-5">
          <div>
            <h3 className="text-sm font-semibold">
              {t('asset.more_metrics', { count: others.length }, `Další měřené metriky (${others.length})`)}
            </h3>
            <p className="text-muted-foreground text-2xs leading-relaxed">
              {t(
                'asset.more_metrics_hint',
                'Tohle zařízení je hlásí každou minutu a historie se ukládá. Klikněte na kteroukoli pro graf, rozložení hodnot a souvislosti.'
              )}
            </p>
          </div>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {others.map((chart) => (
              <MetricRow
                key={chart.id}
                chart={chart}
                to={`/infrastructure/${assetId}/metric/${monitorId}/${chart.id}`}
              />
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}

/**
 * One measured-but-not-featured metric: its name, the last measured value and
 * the shape of the window, linking to the full detail. The value is the last
 * NON-NULL sample - a gap marker must not read as the current reading.
 */
function MetricRow({ chart, to }: { chart: ChartData; to: string }) {
  const series = chart.series[0];
  const points = series?.points ?? [];
  const latest = [...points].reverse().find((p) => p.v != null);
  const values = points.map((p) => p.v);

  return (
    <Link
      to={to}
      className="hover:bg-muted/40 focus-visible:ring-ring border-border/40 flex items-center gap-3 rounded-md border px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
    >
      <span className="min-w-0 flex-1">
        <span className="block truncate text-xs font-medium">{chart.title}</span>
        <span className="text-muted-foreground tabular-nums block text-2xs">
          {latest?.v == null ? '—' : `${latest.v} ${series?.unit ?? ''}`.trim()}
        </span>
      </span>
      {values.length >= 2 && <Sparkline data={values} tone="latency" className="h-6 w-16 shrink-0" />}
    </Link>
  );
}

/**
 * Maps monitor_insights timeline entries (monitor_events / agent_actions /
 * status changes) onto the shared Timeline component's event shape. The
 * server's `at` is a MySQL datetime string - displayed as-is next to the
 * server-computed relative label instead of being re-parsed client-side
 * (Safari can't reliably parse that format via new Date()).
 */
/**
 * Timeline with a severity filter and pagination - 60 events in one endless
 * column were unreadable (reported by the user: recovery and service
 * discovered mixed together).
 */
function FilterableTimeline({ events }: { events: TimelineEvent[] }) {
  const { t } = useLanguage();
  const [severity, setSeverity] = React.useState<'all' | 'down' | 'warning' | 'up' | 'info'>('all');
  const [page, setPage] = React.useState(0);
  const [pageSize, setPageSize] = React.useState(10);
  const [newestFirst, setNewestFirst] = React.useState(true);

  const counts = {
    all: events.length,
    down: events.filter((e) => e.severity === 'down').length,
    warning: events.filter((e) => e.severity === 'warning').length,
    up: events.filter((e) => e.severity === 'up').length,
    info: events.filter((e) => e.severity === 'info').length,
  };
  const bySeverity = severity === 'all' ? events : events.filter((e) => e.severity === severity);
  // Sorted by time; an unparsable date keeps its original position (the
  // server sends newest first) instead of sinking to the bottom.
  const filtered = React.useMemo(() => {
    const withTime = bySeverity.map((e, i) => ({ e, i, ts: Date.parse(String(e.at).replace(' ', 'T')) }));
    withTime.sort((a, b) => {
      if (Number.isNaN(a.ts) || Number.isNaN(b.ts)) return a.i - b.i;
      return newestFirst ? b.ts - a.ts : a.ts - b.ts;
    });
    return withTime.map((x) => x.e);
  }, [bySeverity, newestFirst]);
  const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
  const current = Math.min(page, pageCount - 1);
  const visible = filtered.slice(current * pageSize, current * pageSize + pageSize);

  const labels: Record<typeof severity, string> = {
    all: t('common.all', 'Vše'),
    down: t('timeline.sev_down', 'Výpadky'),
    warning: t('timeline.sev_warning', 'Varování'),
    up: t('timeline.sev_up', 'Obnovení'),
    info: t('timeline.sev_info', 'Informace'),
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-1.5">
        {(['all', 'down', 'warning', 'up', 'info'] as const).map((s) =>
          counts[s] === 0 && s !== 'all' ? null : (
            <button
              key={s}
              type="button"
              onClick={() => {
                setSeverity(s);
                setPage(0);
              }}
              className={`rounded-md px-2.5 py-1 text-2xs font-semibold transition-colors ${
                severity === s
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-secondary text-muted-foreground hover:text-foreground'
              }`}
            >
              {labels[s]} <span className="opacity-70">({counts[s]})</span>
            </button>
          )
        )}

        <div className="ml-auto flex items-center gap-2">
          <button
            type="button"
            onClick={() => {
              setNewestFirst((v) => !v);
              setPage(0);
            }}
            className="bg-secondary text-muted-foreground hover:text-foreground rounded-md px-2.5 py-1 text-2xs font-semibold transition-colors"
          >
            {newestFirst
              ? t('timeline.newest_first', 'Nejnovější první')
              : t('timeline.oldest_first', 'Nejstarší první')}
          </button>
          <select
            value={pageSize}
            onChange={(e) => {
              setPageSize(Number(e.target.value));
              setPage(0);
            }}
            aria-label={t('timeline.page_size', 'Počet na stránku')}
            className="border-border bg-background rounded-md border px-1.5 py-1 text-2xs"
          >
            {[10, 25, 50, 100].map((n) => (
              <option key={n} value={n}>
                {n} / {t('timeline.page_unit', 'stránku')}
              </option>
            ))}
          </select>
        </div>
      </div>

      <Timeline events={visible} />

      {pageCount > 1 && (
        <div className="flex items-center justify-between gap-2 pt-1">
          <span className="text-2xs text-muted-foreground">
            {t(
              'timeline.page_info',
              { from: current * pageSize + 1, to: current * pageSize + visible.length, total: filtered.length },
              `${current * pageSize + 1}–${current * pageSize + visible.length} z ${filtered.length}`
            )}
          </span>
          <div className="flex items-center gap-1.5">
            <Button variant="outline" size="sm" disabled={current === 0} onClick={() => setPage(current - 1)}>
              ← {t('common.previous', 'Předchozí')}
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={current >= pageCount - 1}
              onClick={() => setPage(current + 1)}
            >
              {t('common.next', 'Další')} →
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function mapInsightsTimeline(
  timeline: ServerInsights['timeline'],
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): TimelineEvent[] {
  return timeline.map((e, i) => ({
    // Negative synthetic ids so they can never collide with real
    // monitor_logs ids used by the per-check timeline below.
    id: -(i + 1),
    title: timelineTitle(e.type, t),
    detail: e.description ?? '',
    at: e.relative ? `${e.relative} · ${e.at}` : e.at,
    severity: timelineSeverity(e.type),
  }));
}

/** A period from the address is user input: anything unknown falls back. */
function isKnownTimeRange(value: string | null): value is TimeRange {
  return value !== null && ['15m', '1h', '6h', '24h', '7d', '30d'].includes(value);
}

function timeAgo(
  isoOrDate: string | null,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): string {
  if (!isoOrDate) return t('common.unknown', 'Neznámo');
  const d = new Date(isoOrDate);
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 60) return t('asset.ago_seconds', { s: diff }, `Před ${diff} s`);
  if (diff < 3600) return t('asset.ago_minutes', { m: Math.floor(diff / 60) }, `Před ${Math.floor(diff / 60)} min`);
  if (diff < 86400)
    return t(
      'asset.ago_hours',
      { h: Math.floor(diff / 3600), m: Math.floor((diff % 3600) / 60) },
      `Před ${Math.floor(diff / 3600)}h ${Math.floor((diff % 3600) / 60)}min`
    );
  // Anything older used to fall back to the absolute timestamp - and the
  // callers render "<timeAgo> (<absolute>)", so a month-old status change
  // printed the same date twice: "17. 7. 2026 12:10:03 (17. 7. 2026 12:10:03)".
  const days = Math.floor(diff / 86400);
  if (days < 31) return t('asset.ago_days', { d: days }, `Před ${days} dny`);
  const months = Math.floor(days / 30);
  return t('asset.ago_months', { m: months }, `Před ${months} měsíci`);
}

function buildDynamicAsset(
  m: ApiMonitor,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string,
  siblings: ApiMonitor[] = []
): AssetDetail {
  // Every status the API can report. 'maintenance' and 'unknown' used to be
  // folded into 'paused', so a silent agent read as "Paused" in the header.
  const status: MonitorStatus =
    m.status === 'up'
      ? 'up'
      : m.status === 'down'
        ? 'down'
        : m.status === 'warning'
          ? 'warning'
          : m.status === 'maintenance'
            ? 'maintenance'
            : m.status === 'unknown'
              ? 'unknown'
              : 'paused';
  // "Před 1 měsíci (12. 8. 2026 0:27:52)" did not fit the narrow parameter
  // column and was cut mid-date. The relative part already answers "when", so
  // the absolute one keeps the minute and drops the seconds.
  const stamp = (iso: string) =>
    new Date(iso).toLocaleString('cs-CZ', {
      day: 'numeric',
      month: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  const lastCheckDisplay = m.lastCheck
    ? `${timeAgo(m.lastCheck, t)} (${stamp(m.lastCheck)})`
    : t('asset.moment_ago', 'Před chvílí');
  const lastChangeDisplay = m.lastStatusChange
    ? `${timeAgo(m.lastStatusChange, t)} (${stamp(m.lastStatusChange)})`
    : '—';

  // The agent sends two TOP rankings (5 by CPU, 5 by RAM) - not a complete
  // process list. Each ranking carries only its own dimension; the other stays
  // null and the table shows a dash, not an invented zero.
  const parsedProcesses: { name: string; cpu: number | null; memory: number | null }[] = [];
  const numOrNull = (...vals: unknown[]): number | null => {
    for (const v of vals) {
      if (v != null && v !== '') {
        const n = parseFloat(String(v));
        if (!Number.isNaN(n)) return n;
      }
    }
    return null;
  };

  if (Array.isArray(m.details?.top_cpu_processes)) {
    for (const p of m.details.top_cpu_processes) {
      if (p && (p.name || p.command)) {
        parsedProcesses.push({
          name: String(p.name || p.command || 'proc'),
          cpu: numOrNull(p.cpu, p.cpu_pct),
          memory: numOrNull(p.memory, p.ram_mb),
        });
      }
    }
  }

  if (Array.isArray(m.details?.top_ram_processes)) {
    for (const p of m.details.top_ram_processes) {
      const name = String(p.name || p.command || 'proc');
      const existing = p ? parsedProcesses.find((e) => e.name === name) : undefined;
      if (existing) {
        // The process is in both rankings - fill its RAM from the RAM ranking.
        if (existing.memory == null) existing.memory = numOrNull(p.memory, p.ram_mb);
      } else if (p) {
        parsedProcesses.push({
          name,
          cpu: numOrNull(p.cpu, p.cpu_pct),
          memory: numOrNull(p.memory, p.ram_mb),
        });
      }
    }
  }

  const isTS3 =
    m.type.toLowerCase().includes('teamspeak') ||
    m.name.toLowerCase().includes('donald') ||
    m.name.toLowerCase().includes('teamspeak');
  const ts3Servers = Array.isArray(m.details?.teamspeak_servers) ? m.details.teamspeak_servers[0] : null;
  const ts3Clients: number | null = m.details?.ts3_clients ?? ts3Servers?.clients_online ?? null;
  const ts3Max: number | null = m.details?.ts3_max ?? ts3Servers?.clients_max ?? null;
  const hasTs3Counts = ts3Clients != null && ts3Max != null;
  // The SoC temperature is read by its payload key (G22) and the CPU tile says
  // which core was busy while the all-core average stayed calm.
  const os = (m.os ?? '').trim();
  const osLabel = os !== '' && os !== 'web' && os !== m.type ? os : null;
  const socTemp = socTemperatureC(m.details ?? {});
  const coreHint = busiestCoreHint(m.details ?? {}, m.cpu ?? null, t);

  // Per-type page shape. A tile exists when the TYPE can report the value (an
  // honest "—" until it does) or when a value is actually here; a value this
  // type never measures gets no tile - the page used to show five tiles of
  // which four said "—" on every agent_service.
  const profile = monitorTypeProfile(m.type);
  // CPU and memory of a watched process live in its parent agent's rankings -
  // the dashboard has read them from there all along, the detail page did not.
  const usage = profile.processes === 'parent' ? processUsage(m, [m, ...siblings]) : { cpu: m.cpu, ram: m.ram };
  const parentMonitor = siblings.find((sib) => ['vps', 'openwrt'].includes((sib.type ?? '').toLowerCase())) ?? null;
  const typeLabel = monitorTypeLabel(m.type, t);

  return {
    id: m.id,
    name: m.name,
    kind: m.type.toUpperCase(),
    // Mockup: "OpenWrt 23.05.3 · 192.168.1.1 · Prague, CZ" - the OS first
    // when the agent reports it (for websites m.os merely echoes the type, skip it).
    // `m.os` arrived as a single space from agents that could not read the
    // system name (G24): trimmed away, or the subtitle started with " · ".
    subtitle: [osLabel, m.target, m.category ?? 'Monitory'].filter(Boolean).join(' · '),
    status,
    breadcrumb: [m.category ?? 'Monitory'],
    // The KPI row per the mockup: uptime, latency, CPU, RAM, disk, temperature.
    // No status here - the hero badge already shows it; the Health Score tile
    // is added by OverviewTab from server insights.
    health: [
      // Down has no uptime - it has an outage duration (the tile used to vanish).
      ...(m.status === 'down' && m.sinceStatusChangeSeconds != null
        ? [
            {
              key: 'uptime',
              label: t('asset.down_for', 'Výpadek trvá'),
              value: formatUptime(m.sinceStatusChangeSeconds),
            },
          ]
        : m.uptimeSeconds != null
          ? [{ key: 'uptime', label: 'Uptime', value: formatUptime(m.uptimeSeconds) }]
          : []),
      ...(profile.latency || m.responseMs != null
        ? [
            {
              key: 'latency',
              label: t('common.response', 'Odezva'),
              value: m.responseMs != null ? `${m.responseMs} ms` : '—',
              tone: 'latency' as const,
            },
          ]
        : []),
      ...(isTS3 && hasTs3Counts
        ? [
            {
              key: 'ts3_clients',
              label: t('asset.ts3_clients', 'Připojení klienti TS3'),
              value: t(
                'asset.ts3_clients_value',
                { online: ts3Clients, max: ts3Max },
                `${ts3Clients} / ${ts3Max} uživatelů`
              ),
              tone: 'latency' as const,
            },
          ]
        : []),
      ...(profile.cpu || usage.cpu != null
        ? [
            {
              key: 'cpu',
              label: t('common.cpu', 'Využití CPU'),
              value: usage.cpu != null ? `${usage.cpu.toFixed(1)} %` : '—',
              tone: 'cpu' as const,
              ...(coreHint ? { hint: coreHint } : {}),
            },
          ]
        : []),
      ...(profile.ram !== false || usage.ram != null
        ? [
            {
              key: 'ram',
              label: t('common.ram', 'Využití RAM'),
              // A watched process reports resident megabytes, a machine a share
              // of its memory - the unit follows the type, not the number.
              value: usage.ram == null ? '—' : profile.ram === 'mb' ? `${usage.ram} MB` : `${usage.ram.toFixed(1)} %`,
              tone: 'memory' as const,
            },
          ]
        : []),
      ...(profile.disk || m.hdd != null
        ? [
            {
              key: 'hdd',
              label: t('common.hdd', 'Využití disku'),
              value: m.hdd != null ? `${m.hdd.toFixed(1)} %` : '—',
              tone: 'disk' as const,
            },
          ]
        : []),
      ...(socTemp != null
        ? [
            {
              key: 'temp',
              label: t('asset.temperature', 'Teplota'),
              value: `${socTemp.toFixed(0)} °C`,
              tone: 'temperature' as const,
            },
          ]
        : []),
    ],
    summary: t(
      'asset.summary_text',
      { name: m.name, type: m.type, target: m.target },
      `Monitor ${m.name} (${m.type}) běží na cíli ${m.target}. Metriky se pravidelně ukládají a vyhodnocují v databázi.`
    ),
    summaryChips: [
      {
        label:
          status === 'up'
            ? t('asset.all_tests_ok', 'Všechny testy OK')
            : t('asset.outage_detected', 'Detekován výpadek'),
        variant: status === 'up' ? 'up' : 'warning',
      },
      // The badge printed the stored enum ("Typ: AGENT_SERVICE"); it says a word now.
      { label: `${t('common.type', 'Typ')}: ${typeLabel}`, variant: 'info' },
      // Stored for years, never displayed: a server awaiting restart and watched
      // processes that are not running - both belong at first sight.
      ...(m.details?.reboot_required
        ? [
            {
              label: t('asset.reboot_required', '⚠ Server čeká na restart (aktualizace jádra)'),
              variant: 'warning' as const,
            },
          ]
        : []),
      ...(Array.isArray(m.details?.missing_processes) && m.details.missing_processes.length > 0
        ? [
            {
              label: `${t('asset.missing_processes', 'Neběží hlídané procesy')}: ${m.details.missing_processes.join(', ')}`,
              variant: 'down' as const,
            },
          ]
        : []),
    ],
    info: [
      { label: t('common.last_check', 'Poslední kontrola'), value: lastCheckDisplay },
      { label: t('common.last_change', 'Poslední změna stavu'), value: lastChangeDisplay },
      ...(profile.latency || m.responseMs != null
        ? [{ label: t('common.response', 'Odezva'), value: m.responseMs != null ? `${m.responseMs} ms` : '—' }]
        : []),
      // `os` echoes the type for monitors that report no system name - the row
      // then said "Operační systém: agent_service" next to "Typ protokolu".
      ...(osLabel ? [{ label: t('infra.os', 'Operační systém'), value: osLabel }] : []),
      ...(m.details?.model ? [{ label: t('asset.model', 'Model'), value: String(m.details.model) }] : []),
      ...(m.details?.board_name ? [{ label: t('asset.board', 'Board'), value: String(m.details.board_name) }] : []),
      ...(m.details?.kernel ? [{ label: t('asset.kernel', 'Kernel'), value: String(m.details.kernel) }] : []),
      ...(m.details?.virtualization
        ? [{ label: t('asset.virtualization', 'Virtualizace'), value: String(m.details.virtualization) }]
        : []),
      ...(m.details?.cloud_provider
        ? [{ label: t('asset.cloud_provider', 'Cloud'), value: String(m.details.cloud_provider) }]
        : []),
      ...(m.details?.timezone
        ? [{ label: t('asset.timezone', 'Časová zóna'), value: String(m.details.timezone) }]
        : []),
      { label: t('asset.protocol_type', 'Typ protokolu'), value: typeLabel },
      ...(isTS3 && hasTs3Counts
        ? [
            {
              label: t('asset.ts3_serverquery', 'TeamSpeak 3 ServerQuery'),
              value: t(
                'asset.ts3_serverquery_value',
                { online: ts3Clients, max: ts3Max },
                `${ts3Clients} / ${ts3Max} uživatelů online`
              ),
            },
          ]
        : []),
      ...(m.details?.net != null
        ? [
            {
              label: t('asset.net_throughput', 'Síťový průtok (Rx/Tx)'),
              value: `${Number(m.details.net).toFixed(1)} KB/s`,
            },
          ]
        : []),
      ...(m.details?.disk_read_kb != null
        ? [{ label: t('asset.disk_read', 'Čtení z disku'), value: `${Number(m.details.disk_read_kb).toFixed(1)} KB/s` }]
        : []),
      ...(m.details?.disk_write_kb != null
        ? [
            {
              label: t('asset.disk_write', 'Zápis na disk'),
              value: `${Number(m.details.disk_write_kb).toFixed(1)} KB/s`,
            },
          ]
        : []),
      ...(m.details?.inode_usage != null
        ? [
            {
              label: t('asset.inode_usage', 'Využití Inodů (fs)'),
              value: `${Number(m.details.inode_usage).toFixed(1)} %`,
            },
          ]
        : []),
      ...((m.details?.swap ?? m.details?.swap_pct) != null
        ? [
            {
              label: t('asset.swap_usage', 'Využití Swapu'),
              value: `${Number(m.details?.swap ?? m.details?.swap_pct).toFixed(1)} %`,
            },
          ]
        : []),
      ...(m.details?.tcp_retrans != null
        ? [{ label: t('asset.tcp_retrans', 'TCP Retransmissions (/proc/net/snmp)'), value: `${m.details.tcp_retrans}` }]
        : []),
      ...(m.details?.conntrack_count != null
        ? [{ label: t('asset.conntrack', 'Conntrack Spojení (Sockets)'), value: `${m.details.conntrack_count}` }]
        : []),
    ],
    smartStatus: m.details?.smart ?? null,
    cpanelStats: m.details?.cpanel_stats ?? null,
    cpanelStatsError: m.details?.cpanel_stats_error ?? null,
    lastCheck: m.lastCheck ?? null,
    thresholds: m.effectiveThresholds,
    rawDetails: m.details && typeof m.details === 'object' ? m.details : {},
    remoteActionsEnabled: Boolean(m.remoteActionsEnabled),
    archived: Boolean(m.archivedAt),
    allowedActions: Array.isArray(m.allowedActions) ? m.allowedActions : [],
    monitoredProcesses: m.monitoredProcesses ?? null,
    sslCert: (() => {
      const rawCert = m.details?.check_stages?.tls?.cert;
      const sslDaysRem = m.details?.ssl_days_remaining ?? rawCert?.days_remaining ?? null;
      const sslIssuer = m.details?.ssl_issuer ?? rawCert?.issuer ?? null;
      const sslValidTo = m.details?.ssl_valid_to ?? rawCert?.valid_to ?? null;

      if (sslDaysRem != null || sslIssuer != null || sslValidTo != null) {
        return { days_remaining: sslDaysRem, issuer: sslIssuer, valid_to: sslValidTo };
      }
      return null;
    })(),
    // Real history is fetched separately (see the `events` state and useEffect
    // in AssetDetailPage) from action=events, instead of a synthetic entry.
    events: [],
    processes: parsedProcesses,
    // Monitors on the same asset: the TeamSpeak server running on the VPS the
    // agent reports about, the web check on the same machine. Their own status
    // is shown, not the parent's.
    typeProfile: profile,
    parent: parentMonitor ? { id: parentMonitor.id, name: parentMonitor.name } : null,
    related: siblings.map((s) => ({
      name: s.name,
      // The card next to it used to read "AGENT_SERVICE · nginx:443".
      kind: monitorTypeLabel(s.type, t),
      status:
        s.status === 'up'
          ? 'up'
          : s.status === 'down'
            ? 'down'
            : s.status === 'warning'
              ? 'warning'
              : s.status === 'maintenance'
                ? 'maintenance'
                : s.status === 'unknown'
                  ? 'unknown'
                  : 'paused',
      detail: [s.target, s.port ? `:${s.port}` : null].filter(Boolean).join('') || (s.hostname ?? ''),
    })),
  };
}

/** The detail of an archived monitor: history only, with the way back for an administrator. */
function ArchivedNotice({
  monitorId,
  archivedAt,
  onRestored,
}: {
  monitorId: number;
  archivedAt: string | null;
  onRestored: () => void;
}) {
  const { t, lang } = useLanguage();
  const { isAdmin } = useSession();
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  const restore = async () => {
    setBusy(true);
    setError(null);
    try {
      await appApi.unarchiveMonitor(monitorId);
      onRestored();
    } catch (err) {
      setError(
        err instanceof Error ? err.message : t('asset.archived_restore_failed', 'Obnovení z archivu se nepodařilo.')
      );
    } finally {
      setBusy(false);
    }
  };

  const when = archivedAt ? new Date(archivedAt).toLocaleString(lang === 'cs' ? 'cs-CZ' : 'en-GB') : null;

  return (
    <div
      role="status"
      className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-secondary/40 p-3 text-xs"
    >
      <Archive className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <p className="min-w-0 flex-1">
        <strong>
          {when
            ? t('asset.archived_title_when', { when }, `Archivováno ${when}.`)
            : t('asset.archived_title', 'Archivovaný monitor.')}
        </strong>{' '}
        {t(
          'asset.archived_desc',
          'Nekontroluje se, neposílá upozornění a hlášení jeho agenta se odmítají. Historie zůstává k nahlédnutí.'
        )}
      </p>
      {isAdmin && (
        <Button size="sm" variant="outline" disabled={busy} onClick={() => void restore()} className="gap-1.5">
          <ArchiveRestore className="size-4" aria-hidden="true" />
          {busy ? t('asset.archived_restoring', 'Obnovuji…') : t('asset.archived_restore', 'Obnovit z archivu')}
        </Button>
      )}
      {error && (
        <p role="alert" className="basis-full text-down">
          {error}
        </p>
      )}
    </div>
  );
}
