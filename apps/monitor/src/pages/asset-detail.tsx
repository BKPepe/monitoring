import * as React from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import {
  ArrowLeft,
  Clock,
  Cpu,
  Globe,
  HardDrive,
  Mic,
  Pencil,
  Server,
  Settings2,
  ShieldCheck,
  Archive,
  ArchiveRestore,
  Compass,
  House,
  Lock,
  Plug,
  RadioTower,
  Shield,
  Split,
  Wifi,
  Wrench,
  OctagonAlert,
  TriangleAlert,
  Activity,
  ClipboardList,
  Info,
  type LucideIcon,
} from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { Pill, type PillTone } from '@/components/ui/pill';
import { IconTile } from '@/components/ui/icon-tile';
import { KeyValueList } from '@/components/ui/key-value';
import { usePageChrome } from '@/components/layout/shell-context';
import type { ScoredHealth } from '@/components/health-deductions';
import { Badge, StatusDot } from '@/components/ui/badge';
import { SignalReading } from '@/components/signal-reading';
import { AvailabilityWindows } from '@/components/availability-windows';
import { NotificationLog } from '@/components/notification-log';
import { MaintenanceToggle, useMaintenanceToggle } from '@/components/maintenance-toggle';
import { OverflowMenu, type OverflowMenuItem } from '@/components/ui/overflow-menu';
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
import { RangePills } from '@/components/charts/range-pills';
import { lteBackupState } from '@/lib/lte-backup';
import { wanLinkState } from '@/lib/wan-link';
import { StatBlock, breachTone } from '@/components/stat-block';
import { metricSeverity, thresholdFor } from '@/lib/attention';
import { trendDelta, type TrendDelta } from '@/lib/trend';
import type { ChartData, FindingSource, LinkTrafficResponse, MetricPoint, RecommendationArea } from '@/api/types';
import { resolveSource } from '@/api/source';
import { CollapsedTimeline } from '@/components/timeline';
import type { TimelineEvent } from '@/data/model';
import { useAssetCharts } from '@/api/use-asset-charts';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { useLanguage } from '@/context/language-context';
import { cn, formatMs, formatPercent, formatUptime } from '@/lib/utils';
import { DiscordCard } from '@/components/discord-card';
import { MinecraftCard } from '@/components/minecraft-card';
import { CheckPipeline } from '@/components/check-pipeline';
import { TeamspeakCard } from '@/components/teamspeak-card';
import { HeartbeatCard } from '@/components/heartbeat-card';
import { StorageCard } from '@/components/storage-card';
import { SpeedtestCard } from '@/components/speedtest-card';
import { useWanBottleneck, WanBottleneckCard } from '@/components/wan-bottleneck-card';
import { RatioBar } from '@/components/meter';
import { EmptyState, ErrorState, LoadingState, Skeleton } from '@/components/ui/states';
import { RouterRecommendations, useRouterRecommendations } from '@/components/router-recommendations';
import { RouterPortPanel } from '@/components/router-ports/port-panel';
import { WifiRadioList } from '@/components/wifi-radio-list';
import { LogErrorLines } from '@/components/log-error-lines';
import { logWindow, readLogLines } from '@/lib/log-lines';
import { seriesForTile } from '@/lib/tile-series';
import { timelineSeverity, timelineTitle } from '@/lib/timeline-events';
import { countAttention } from '@/lib/timeline-collapse';
import { groupMetrics, latestValue, subsystemTitle } from '@/lib/metric-groups';
import { formatDuration, formatMetricValue, formatNumber, localeFor } from '@/lib/metric-format';
import { monitorStatusKey, statusLabel, statusMeta, type StatusKey } from '@/lib/status';
import { planOverview, valueRange } from '@/lib/asset-charts';
import { MetricHelpIcon } from '@/components/metric-help-icon';
import {
  AssetHeaderPanel,
  ClientsPanel,
  LatencyPanel,
  PerformancePanel,
  StorageSummaryPanel,
  WanPanel,
  WifiSummaryPanel,
  pickPerformance,
} from './asset-detail-panels';
import { FindingsList } from '@/components/findings-list';
import { MetricGroup } from '@/components/metric-group';
import { isTeamSpeakMonitor, monitorTypeLabel, monitorTypeProfile, type MonitorTypeProfile } from '@/lib/monitor-type';
import { parseMonitorId } from '@/lib/monitor-route';
import { NotFoundPage } from '@/pages/not-found';
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
  series?: { points: MetricPoint[]; unit: string; window?: { from: number; to: number } };
  /** Against the same window one period earlier (lib/trend.ts); absent when the server sent no comparison. */
  delta?: TrendDelta;
  /** The value crossed the monitor's own limit (C-1: colour only on a breach). */
  breach?: 'down' | 'warning' | null;
}

interface AssetDetail {
  id: number;
  name: string;
  kind: string;
  subtitle: string;
  status: MonitorStatus;
  /** The C-11 key: splits "unknown" into a new monitor and a silent agent. */
  statusKey: StatusKey;
  breadcrumb: string[];
  health: HealthMetric[];
  summaryChips: { label: string; variant: 'up' | 'warning' | 'info' | 'down' }[];
  /** `hint` shows under the value - the answer to "what changed and why"; `prose` = words, not a figure. */
  info: { label: string; value: string; hint?: string; prose?: boolean }[];
  /** The last check's response in ms; null = not measured. */
  responseMs: number | null;
  /** The latency limit set on the monitor (admin view); null = none or not visible. */
  latencyLimitMs: number | null;
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
  related: { name: string; kind: string; statusKey: StatusKey; detail: string }[];
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
  /** The state sentence alone (C-11); null = an older server, `summary` stands in. */
  statusSentence: string | null;
  /**
   * The health score 0-100 (SCORE, formulaVersion 1) with its components and
   * named deductions. null = the server could not compute it (said in the
   * header); undefined = an older server that does not send it (no ring).
   * The TS3-only `healthScore` of the same answer is not read any more.
   */
  health: ScoredHealth | null | undefined;
  /** Knowledge tips (critical/warn). Trends and anomalies are in the findings feed (C-12) instead. */
  tips: { severity: 'critical' | 'warn' | string; text: string }[];
  /** System-level timeline (status changes, remote actions, SSL warnings, ...)
   *  from monitor_events/agent_actions - a different granularity than the
   *  per-check rows in `events`, so it's rendered as its own section. */
  timeline: { type: string; description: string | null; at: string; relative: string }[];
}

export function AssetDetailPage() {
  const { t, lang } = useLanguage();
  // The segment is a monitors.id and nothing else (W1-D2); null = not an id.
  const { id: routeId } = useParams<{ id: string }>();
  const idNum = parseMonitorId(routeId);

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
  const [insightsFailed, setInsightsFailed] = React.useState(false);

  // Which device the page last loaded: a reload of the same one (after a
  // maintenance toggle, a restore) keeps the page on screen instead of
  // replacing it with a full-page spinner.
  const loadedIdRef = React.useRef<number | null>(null);

  React.useEffect(() => {
    let active = true;
    if (idNum === null) {
      // Nothing to look up: the page renders NotFound below.
      setLoading(false);
      return;
    }
    if (loadedIdRef.current !== idNum) setLoading(true);

    appApi
      .getMonitors()
      .then(async (rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        // By monitors.id only. The asset_id fallback opened ANOTHER device as
        // soon as one monitor's asset_id equalled a different monitor's id.
        let match: ApiMonitor | undefined = list.find((m: ApiMonitor) => Number(m.id) === idNum);
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
        setAsset(match ? buildDynamicAsset(match, t, siblings, lang) : null);
        setRawMonitor(match ?? null);
        setLoadFailed(false);
        loadedIdRef.current = idNum;
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
  }, [idNum, t, lang, reloadToken]);

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
  // The Události tab's own list: status changes, remote actions, limits.
  const systemEvents = React.useMemo(
    () => (serverInsights ? mapInsightsTimeline(serverInsights.timeline, t) : []),
    [serverInsights, t]
  );
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
            // One row of the check log: a routine pass hides behind "Vše" (C-10).
            kind: 'check',
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
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      })
      .then((data) => {
        if (!active) return;
        if (!data || typeof data.summary !== 'string') throw new Error('invalid monitor_insights');
        setServerInsights({
          summary: data.summary,
          // The state alone, without the top concern the findings list names
          // anyway; absent on a server that does not send it yet.
          statusSentence: typeof data.statusSentence === 'string' && data.statusSentence ? data.statusSentence : null,
          health:
            data.health === undefined
              ? undefined
              : data.health && typeof data.health === 'object' && Array.isArray(data.health.components)
                ? (data.health as ScoredHealth)
                : null,
          tips: Array.isArray(data.tips) ? data.tips : [],
          timeline: Array.isArray(data.timeline) ? data.timeline : [],
        });
        setInsightsFailed(false);
      })
      // The summary used to vanish without a word when this failed; a missing
      // verdict must say it is missing (W1-A5).
      .catch(() => {
        if (active) setInsightsFailed(true);
      });

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

  // The header names the device and judges its data by the last report
  // (cron cadence, like the dashboard: live up to 10 min).
  usePageChrome({
    title: asset?.name,
    freshness:
      asset && !asset.archived ? { at: asset.lastCheck ? Date.parse(asset.lastCheck) : null, intervalSecs: 300 } : null,
  });

  const openTab = React.useCallback((next: string, anchor?: string) => {
    setTab(next);
    if (!anchor) return;
    // The section exists only once its tab has rendered.
    window.requestAnimationFrame(() => {
      const el = document.getElementById(anchor);
      el?.scrollIntoView({ block: 'start' });
      el?.focus({ preventScroll: true });
    });
  }, []);

  if (idNum === null) return <NotFoundPage />;

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
      <Panel bodyClassName="grid place-items-center gap-4 p-12 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-base">{t('asset.not_found', 'Zařízení nenalezeno')}</p>
          <p className="text-muted-foreground text-sm">
            {t(
              'asset.not_found_desc',
              { id: routeId ?? '' },
              'Zařízení s ID {id} nebylo v monitorovací databázi nalezeno.'
            )}
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <Link to="/infrastructure" className="gap-2 font-semibold">
            <ArrowLeft className="size-4" /> {t('asset.back', 'Zpět na přehled infrastruktury')}
          </Link>
        </Button>
      </Panel>
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
  // A router's and a server's third tab is about their disks (W2-3); its
  // header and content follow the tab's name, not the web target's (V-14).
  const isStorage = isRouter || isServerKind(asset.kind);

  return (
    <div className="space-y-6">
      <AssetHeaderPanel
        name={asset.name}
        kind={asset.kind}
        subtitle={asset.subtitle}
        breadcrumb={asset.breadcrumb}
        statusKey={asset.statusKey}
        maintenance={rawMonitor?.maintenance === true}
        archived={asset.archived}
        health={
          serverInsights
            ? { status: 'ok', health: serverInsights.health }
            : insightsFailed
              ? { status: 'failed' }
              : { status: 'loading' }
        }
        actions={
          <div className="flex items-center gap-2">
            <HeroActions asset={asset} />
            {/* One click, not "open the form, tick a box, save the whole monitor" -
                which is what a maintenance window used to cost at the moment
                speed matters most. */}
            {!asset.archived && (
              <div className="hidden md:block">
                <MaintenanceToggle
                  monitorId={Number(asset.id)}
                  active={rawMonitor?.maintenance === true}
                  onChanged={() => setReloadToken((n) => n + 1)}
                />
              </div>
            )}
            {/* Phone: Akce, Upravit and Údržba in one menu - three buttons wrapped
                under the title and pushed the tabs a screen down (W2-2). */}
            <PhoneActions
              asset={asset}
              maintenanceActive={rawMonitor?.maintenance === true}
              onChanged={() => setReloadToken((n) => n + 1)}
            />
          </div>
        }
      />

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
          {/* Short labels that never wrap (clutter-27): on a phone "Přehled &
              Výkon" broke over three lines and "Události (204)" was cut. */}
          <TabsList className="bg-secondary/40 max-w-full flex-nowrap overflow-x-auto p-1 *:whitespace-nowrap">
            <TabsTrigger value="overview">{t('asset.tab_overview', 'Přehled')}</TabsTrigger>
            <TabsTrigger value="processes">
              {t('asset.tab_processes_short', 'Procesy')}
              <TabCount n={asset.processes.length} />
            </TabsTrigger>
            {hasNetworkData(asset.rawDetails) && (
              <TabsTrigger value="network">{t('asset.tab_network', 'Síť')}</TabsTrigger>
            )}
            <TabsTrigger value="services">
              {/* A router's and a server's tab holds their disks; certificates
                  and protocol checks are what a web target has. */}
              {isStorage ? t('asset.tab_storage', 'Úložiště') : t('asset.tab_services', 'Služby & Certifikáty')}
            </TabsTrigger>
            <TabsTrigger value="events">
              {/* Counts what needs a look, not two hundred passed checks (C-10). */}
              {t('asset.tab_events', 'Události')}
              {/* What the tab itself lists (V-15): the check log moved to its
                  own page, so its failures made "Události 5" over "Vše (4)". */}
              <TabCount n={countAttention(systemEvents)} />
            </TabsTrigger>
          </TabsList>
          <RangePicker value={range} onChange={setRange} />
          {/* Every tab shows data from this moment - the Network, Processes and
              Services tabs used to present the last report as "now". */}
          {asset.lastCheck && (
            <p className="text-muted-foreground basis-full text-2xs">
              {t('asset.data_as_of', 'Data z posledního hlášení')}: {timeAgo(asset.lastCheck, t)} (
              {new Date(asset.lastCheck).toLocaleString(localeFor(lang))})
            </p>
          )}
        </div>

        <TabsContent value="overview">
          <OverviewTab
            asset={asset}
            range={range}
            events={events}
            serverInsights={serverInsights}
            insightsFailed={insightsFailed}
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
            onOpenTab={openTab}
          />
        </TabsContent>

        {hasNetworkData(asset.rawDetails) && (
          <TabsContent value="network">
            <NetworkTab
              d={asset.rawDetails}
              monitorId={Number(asset.id)}
              recommendations={isRouter ? compactRecommendations : undefined}
              // Where the line speed ends and the tests behind it - only a
              // router has a WAN to judge (X21), and its speed belongs to its network.
              router={isRouter}
            />
          </TabsContent>
        )}

        <TabsContent value="processes">
          {/* The snapshot below answers "right now"; this answers "all day",
              which is the question a spiky process only shows up in. */}
          <div className="mb-4">
            <ProcessTop monitorId={Number(asset.id)} />
          </div>
          <Panel
            icon={Cpu}
            title={t('asset.process_load', { name: asset.name }, `Zátěž procesů serveru (${asset.name})`)}
            hint={t('asset.process_load_desc', 'Aktuálně spotřebovávaná paměť RAM a zátěž procesoru.')}
            bodyClassName="space-y-4"
          >
            {asset.rawDetails?.ts3_process && (
              <div className="bg-inset space-y-1 rounded-lg border border-border p-3 text-xs">
                <p className="flex items-center gap-1.5 font-bold">
                  <Mic aria-hidden="true" className="size-3.5 shrink-0" />
                  {t('asset.ts3_process_title', 'Proces ts3server')}
                </p>
                <p className="font-mono tabular-nums text-muted-foreground">
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
                      <div key={key} className="bg-inset rounded-lg border border-border p-2.5">
                        <p className="text-muted-foreground capitalize">{key}</p>
                        <p className="font-mono tabular-nums font-semibold">{val?.formatted ?? '—'}</p>
                      </div>
                    ))}
                  </div>
                </div>
              ) : asset.cpanelStatsError ? (
                // cPanel collection is configured but failing - say so loudly
                // instead of pretending there's simply nothing to show.
                <div role="alert" className="rounded-lg border-2 border-down/60 bg-down/10 p-4 space-y-1 text-xs">
                  <p className="flex items-center gap-1.5 font-bold text-down">
                    <OctagonAlert aria-hidden="true" className="size-3.5 shrink-0" />
                    {t('asset.cpanel_error_title', 'Sběr cPanel statistik selhává')}
                  </p>
                  <p className="text-down font-mono">{asset.cpanelStatsError.error}</p>
                  {asset.cpanelStatsError.since && (
                    <p className="text-muted-foreground">
                      {t('collection.since', 'od')}{' '}
                      {new Date(asset.cpanelStatsError.since).toLocaleString(localeFor(lang))}
                    </p>
                  )}
                  <p className="text-muted-foreground">
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
                    <div key={proc.name} className="bg-inset rounded-lg border border-border px-3 py-2">
                      <p className="truncate font-mono text-xs font-semibold">{proc.name}</p>
                      <div className="text-muted-foreground mt-0.5 flex gap-4 font-mono tabular-nums text-2xs">
                        <span>CPU {formatPercent(proc.cpu, 1, lang)}</span>
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
                          <TableCell className="text-right font-mono tabular-nums">
                            {formatPercent(proc.cpu, 1, lang)}
                          </TableCell>
                          <TableCell className="text-right font-mono tabular-nums">
                            {proc.memory == null ? '—' : `${proc.memory} MB`}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </>
            )}
          </Panel>
        </TabsContent>

        <TabsContent value="services">
          <Panel
            icon={isStorage ? HardDrive : ShieldCheck}
            title={
              isStorage ? (
                <>
                  {t('asset.tab_storage', 'Úložiště')}
                  <MetricHelpIcon metric="hdd" />
                </>
              ) : isDiscord ? (
                t('asset.discord_services_title', 'Discord server')
              ) : isMinecraft ? (
                t('asset.mc_services_title', 'Minecraft server')
              ) : (
                t('asset.services_title', 'Stav Služeb & Šifrovací Certifikáty')
              )
            }
            hint={
              isStorage
                ? t('asset.router_storage_desc', 'Disky, oddíly a jejich zdraví podle dat od agenta.')
                : isDiscord
                  ? t('asset.discord_services_desc', 'Kdo je online, hlasové kanály a členové ze serverového widgetu.')
                  : isMinecraft
                    ? t('asset.mc_services_desc', 'MOTD, hráči a výkon serveru z dotazu na herní port.')
                    : t('asset.services_desc', 'Stav protokolů a šifrovacích certifikátů.')
            }
            bodyClassName="space-y-4"
          >
            {/* A router certifies no website, and what its service chips said
                (WAN, LTE, VPN, SQM, firewall) are rows of the Network tab's
                sections now (W2-3): this tab holds its disks. */}
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
            {/* A cPanel account can still carry its site's certificate; an
                agent machine's card only ever said "N/A". */}
            {!isRouter &&
              upperKind !== 'VPS' &&
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
                    <div className="bg-inset space-y-2 rounded-lg border border-border p-4">
                      <p className="font-semibold text-sm">{t('asset.ssl_cert', 'TLS/SSL Certifikát')}</p>
                      {isNoSsl ? (
                        <>
                          <p className="text-xs text-muted-foreground font-medium">N/A</p>
                          <p className="text-2xs text-muted-foreground">
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
                              // Expired first: "<= 14" also matched a dead certificate,
                              // so an expired one was painted as merely expiring.
                              (asset.sslCert.days_remaining ?? 99) <= 0
                                ? 'text-down'
                                : (asset.sslCert.days_remaining ?? 99) <= 14
                                  ? 'text-warning'
                                  : 'text-up'
                            )}
                          >
                            <ShieldCheck className="size-4 shrink-0" />
                            {asset.sslCert.days_remaining != null
                              ? asset.sslCert.days_remaining <= 0
                                ? t('asset.ssl_expired', 'SSL certifikát vypršel!')
                                : t(
                                    'asset.ssl_valid_expiry',
                                    { days: asset.sslCert.days_remaining },
                                    `Platný (vyprší za ${asset.sslCert.days_remaining} dní)`
                                  )
                              : t('asset.ssl_valid', 'Platný SSL/TLS certifikát')}
                          </p>
                          <div className="text-2xs text-muted-foreground space-y-0.5 pt-1 border-t border-border/40">
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
                          <p className="text-2xs text-muted-foreground">
                            {t(
                              'asset.ssl_unknown_desc',
                              'Kontrola certifikátu proběhne při příštím HTTPS testu tohoto cíle.'
                            )}
                          </p>
                        </>
                      )}
                    </div>
                    <div className="bg-inset space-y-2 rounded-lg border border-border p-4">
                      <p className="font-semibold text-sm">{t('asset.service_status', 'Stav Služby')}</p>
                      <p className={cn('text-xs font-medium', asset.status === 'down' ? 'text-down' : 'text-up')}>
                        {asset.status === 'down' ? t('common.offline', 'Offline') : t('infra.active_since', 'Aktivní')}
                      </p>
                      <p className="text-2xs text-muted-foreground">
                        {t('common.protocol', 'Protokol')}: {asset.kind}
                      </p>
                    </div>
                    {/* The one-line SMART verdict of the old agent. From 0.1.7 the
                        Storage card carries a block per disk, so this would only
                        repeat a worse version of it. */}
                    {!Array.isArray(asset.rawDetails?.storage_disks) && (
                      <div className="bg-inset space-y-2 rounded-lg border border-border p-4 md:col-span-2">
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
                              <p className="text-2xs text-muted-foreground">
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
          </Panel>
        </TabsContent>

        <TabsContent value="events">
          <div className="space-y-4">
            {/* What went out about this monitor - the question after every
                outage, which nothing could answer until the delivery log. */}
            <NotificationLog monitorId={Number(asset.id)} />
            {serverInsights && serverInsights.timeline.length > 0 && (
              <Panel
                icon={Settings2}
                title={t('asset.system_timeline', 'Systémové události (30 dní)')}
                hint={t('asset.system_timeline_desc', 'Změny stavu, vzdálené akce, SSL varování a překročené limity.')}
              >
                <CollapsedTimeline filters events={systemEvents} />
              </Panel>
            )}

            {/* The check log lives in one place (W2-6, PAGES-B request): the
                fleet's "Protokol kontrol" narrowed to this device. The rows still
                load here - they count the tab's badge and date the Hero's state. */}
            <Panel padding="sm" bodyClassName="flex flex-wrap items-center justify-between gap-3">
              <span className="flex items-center gap-3 text-sm font-semibold">
                <IconTile icon={Clock} />
                {t('incidents.check_log', 'Protokol kontrol')}
              </span>
              <Link
                to={`/incidents/checks?monitor=${asset.id}`}
                className="text-link focus-visible:ring-ring rounded text-xs font-semibold hover:underline focus-visible:ring-2 focus-visible:outline-none"
              >
                {t('asset.check_log_open', 'Otevřít protokol kontrol →')}
              </Link>
            </Panel>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}

/** The header's desktop actions: remote actions, the way to set them up, and the edit form. */
function HeroActions({ asset }: { asset: AssetDetail }) {
  const { t } = useLanguage();
  const { isAdmin } = useSession();

  return (
    <>
      <div className="hidden items-center gap-2 md:flex">
        {!asset.archived && asset.remoteActionsEnabled && asset.allowedActions.length > 0 && (
          <ActionsMenu asset={asset} />
        )}
        {/* Remote Actions are switched on in the monitor's settings. Without this
            the detail simply had no Actions button and gave no hint why. */}
        {!asset.archived && isAdmin && isRouterKind(asset.kind) && !asset.remoteActionsEnabled && (
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
    </>
  );
}

/** The page's actions below `md`: one "⋯" menu instead of three buttons. */
function PhoneActions({
  asset,
  maintenanceActive,
  onChanged,
}: {
  asset: AssetDetail;
  maintenanceActive: boolean;
  onChanged: () => void;
}) {
  const { t } = useLanguage();
  const { isAdmin } = useSession();
  const remote = useRemoteActions(asset);
  const maintenance = useMaintenanceToggle({ monitorId: Number(asset.id), active: maintenanceActive, onChanged });
  if (asset.archived) return null;

  const items: OverflowMenuItem[] = [];
  if (asset.remoteActionsEnabled) {
    for (const action of asset.allowedActions) {
      items.push({
        label: remote.labels[action] ?? action,
        icon: Settings2,
        disabled: remote.busy,
        onSelect: () => void remote.trigger(action),
      });
    }
  } else if (isAdmin && isRouterKind(asset.kind)) {
    items.push({
      label: t('asset.ra_setup', 'Nastavit vzdálené akce'),
      icon: Settings2,
      onSelect: () => {
        window.location.href = `/app/infrastructure?edit=${asset.id}&tab=advanced`;
      },
    });
  }
  if (maintenance.allowed) {
    items.push({
      label: maintenance.label,
      icon: Wrench,
      disabled: maintenance.busy,
      onSelect: () => void maintenance.toggle(),
    });
  }
  items.push({
    label: t('asset.edit_monitor', 'Upravit monitor'),
    icon: Pencil,
    onSelect: () => {
      window.location.href = `/app/infrastructure?edit=${asset.id}`;
    },
  });

  const message = remote.result ?? (maintenance.error ? { ok: false, text: maintenance.error } : null);
  return (
    <div className="flex flex-col items-end gap-1 md:hidden">
      <OverflowMenu label={t('asset.actions_menu', 'Akce zařízení')} items={items} />
      {message && (
        <p role="status" className={cn('max-w-64 text-right text-2xs', message.ok ? 'text-up' : 'text-down')}>
          {message.text}
        </p>
      )}
    </div>
  );
}

/** The page's range control: the shared pills, with each window spelled out on hover. */
function RangePicker({ value, onChange }: { value: TimeRange; onChange: (range: TimeRange) => void }) {
  const { t } = useLanguage();
  return (
    <RangePills
      value={value}
      options={TIME_RANGES}
      onChange={onChange}
      label={t('asset.time_range', 'Časový rozsah')}
      titles={{
        '24h': t('asset.range_24h', 'Posledních 24 hodin'),
        '7d': t('asset.range_7d', 'Posledních 7 dní'),
        '30d': t('asset.range_30d', 'Posledních 30 dní'),
      }}
    />
  );
}

const TIME_RANGES = ['24h', '7d', '30d'] as const;

/**
 * The findings the asset page lists (C-12). Not the status one - the hero and
 * the summary sentence already say it - and not a router's recommendations,
 * which have their own card with the mute right under the summary.
 */
const DEVICE_FINDINGS: readonly FindingSource[] = ['certificate', 'check', 'metric', 'agent', 'insight', 'router'];
const ROUTER_FINDINGS: readonly FindingSource[] = ['certificate', 'check', 'metric', 'agent', 'insight'];

function OverviewTab({
  asset,
  range,
  events,
  serverInsights,
  insightsFailed = false,
  statusChangeHint,
  recommendations,
  onOpenTab,
}: {
  asset: AssetDetail;
  range: TimeRange;
  events: TimelineEvent[];
  serverInsights: ServerInsights | null;
  /** monitor_insights failed: the summary says so instead of disappearing. */
  insightsFailed?: boolean;
  /** The router's full recommendation card; null for everything that is not a router. */
  recommendations?: React.ReactNode;
  /** What happened at the last status change and why; undefined = unknown yet. */
  statusChangeHint?: string;
  /** Switches the page to another tab, optionally to a section of it. */
  onOpenTab: (tab: string, anchor?: string) => void;
}) {
  const { t, lang } = useLanguage();
  // One chart fetch for the whole tab: the same data feeds the panels, the
  // charts below and the KPI-tile sparklines above (value + delta + trend).
  const charts = useAssetCharts(asset.id, range);
  const router = isRouterKind(asset.kind);
  const d = asset.rawDetails;

  const healthWithTrends = React.useMemo<HealthMetric[]>(() => {
    return asset.health.map((m) => {
      // Its own metric only (W1-B5) - never the first chart of the same colour.
      const s = seriesForTile(m.key, charts.data);
      if (!s) return m;
      // Nulls kept on purpose - an unmeasured point is a gap in the trace, and
      // the trace spans the chart's window, so silence at its end shows.
      const delta = trendDelta({
        metricKey: s.key,
        unit: s.unit,
        current: s.points.map((p) => p.v),
        previous: s.previousAvg,
      });
      const chartWindow = charts.data?.find((c) => c.series.includes(s))?.window;
      return {
        ...m,
        series: s.points.length >= 2 ? { points: s.points, unit: s.unit, window: chartWindow } : undefined,
        delta: delta ?? undefined,
      };
    });
  }, [asset.health, charts.data]);

  // The chart plan, shared out: CPU, RAM and temperature into "Výkon", the
  // line's traffic into "WAN" (a router), the response time into the latency
  // panel, and the rest of the featured cards into the grid below. Each chart
  // is drawn once (clutter-03).
  const plan = React.useMemo(() => overviewPlan(charts.data, asset.kind, t), [charts.data, asset.kind, t]);
  const chartEvents = React.useMemo(() => eventMarkers(events), [events]);
  const decorate = React.useCallback(
    (chart: ChartData) =>
      withBands(
        chartEvents.length > 0 ? { ...chart, events: [...(chart.events ?? []), ...chartEvents] } : chart,
        asset.thresholds,
        t
      ),
    [chartEvents, asset.thresholds, t]
  );
  const perf = plan ? pickPerformance(plan.cards).map(decorate) : [];
  const wanChart = router && plan ? (plan.cards.find((c) => c.id === 'net-combined' || c.id === 'net') ?? null) : null;
  const latencyChart = plan?.cards.find((c) => c.id === 'response_time') ?? null;
  const shown = new Set([...perf.map((c) => c.id), wanChart?.id, latencyChart?.id]);
  const restCards = plan ? plan.cards.filter((c) => !shown.has(c.id)) : [];
  const metricHref = (id: string) => `/infrastructure/${asset.id}/metric/${asset.id}/${id}`;
  const hasLatency = asset.typeProfile.latency || latencyChart != null;

  const perfPanel = perf.length > 0 && <PerformancePanel charts={perf} metricHref={metricHref} className="h-full" />;
  const latencyPanel = hasLatency && (
    <LatencyPanel
      chart={latencyChart ? decorate(latencyChart) : null}
      current={asset.responseMs}
      limitMs={asset.latencyLimitMs}
      router={router}
      metricHref={metricHref}
      className="h-full"
    />
  );
  // The line's facts or its traffic: a router whose agent reports either gets the panel.
  const wanPanel = router && (hasWanData(d) || wanChart != null) && (
    <WanPanel d={d} chart={wanChart ? decorate(wanChart) : null} metricHref={metricHref} className="h-full" />
  );
  const wifiPanel = router && <WifiSummaryPanel d={d} onOpen={() => onOpenTab('network', NET_ANCHORS.wifi)} />;
  const clientsPanel = router && <ClientsPanel d={d} />;
  // Decided here, not by the panel rendering null: an element that renders
  // nothing still takes its half of a row in pair().
  const storagePanel = Array.isArray(d.storage_disks) && d.storage_disks.length > 0 && (
    <StorageSummaryPanel d={d} onOpen={() => onOpenTab('services')} className="h-full" />
  );
  const availability = <AvailabilityWindows monitorId={asset.id} />;
  // A type without CPU/RAM readings (a website, a game server) pairs its
  // latency with the availability instead of leaving it a full-width row.
  const latencyWithAvailability = !perfPanel && latencyPanel;
  const hasProcesses = asset.typeProfile.processes !== 'none';

  return (
    <div className="grid grid-cols-1 gap-6 *:min-w-0 xl:grid-cols-12">
      <div className="grid gap-3 xl:col-span-12 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        {healthWithTrends.map((metric) => (
          <StatBlock
            key={metric.key}
            variant="card"
            size="sm"
            label={metric.label}
            value={metric.value}
            hint={metric.hint}
            tone={metric.breach ?? null}
            delta={metric.delta}
            sparkline={
              metric.series && metric.tone
                ? {
                    points: metric.series.points,
                    tone: metric.tone,
                    unit: metric.series.unit,
                    window: metric.series.window,
                  }
                : undefined
            }
          />
        ))}
      </div>

      {/* Left: the state and what to do about it, stacked. Side by side with
          the information list, a two-sentence summary left a 300 px hole under
          itself (clutter-17). */}
      <div className="flex min-w-0 flex-col gap-6 xl:col-span-8">
        <Panel
          title={t('asset.summary_title', 'Souhrn stavu')}
          icon={ClipboardList}
          bodyClassName="flex flex-col gap-3"
        >
          {/* One status sentence from the server (C-11) - the client's
              template sentence ("Monitor X běží na cíli Y...") said nothing
              and is gone. Not muted: it is what the card is for. */}
          {serverInsights ? (
            <p className="text-sm leading-relaxed">{serverInsights.statusSentence ?? serverInsights.summary}</p>
          ) : insightsFailed ? (
            <ErrorState size="inline" message={t('asset.summary_failed', 'Souhrn stavu se nepodařilo načíst.')} />
          ) : (
            <LoadingState size="inline" label={t('asset.summary_loading', 'Načítám souhrn stavu…')} />
          )}

          {serverInsights && serverInsights.tips.length > 0 && (
            <ul className="flex flex-col gap-1.5">
              {serverInsights.tips.map((tip, i) => {
                const critical = tip.severity === 'critical';
                const Icon = critical ? OctagonAlert : TriangleAlert;
                return (
                  <li key={`${tip.severity}-${i}`} className="flex items-start gap-2 text-xs leading-relaxed">
                    <Icon
                      role="img"
                      aria-label={
                        critical ? t('rec.severity_critical', 'Kritické') : t('rec.severity_warning', 'Varování')
                      }
                      className={cn('mt-0.5 size-3.5 shrink-0', critical ? 'text-down' : 'text-warning')}
                    />
                    <span>{tip.text}</span>
                  </li>
                );
              })}
            </ul>
          )}

          {asset.summaryChips.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {asset.summaryChips.map((chip) => (
                <Badge key={chip.label} variant={chip.variant} dot>
                  {chip.label}
                </Badge>
              ))}
            </div>
          )}

          {/* The device's findings, once (C-12): trends, anomalies,
              certificates and limits from the one feed. */}
          <div className="border-border border-t pt-1">
            <FindingsList
              density="device"
              monitorId={Number(asset.id)}
              sources={router ? ROUTER_FINDINGS : DEVICE_FINDINGS}
            />
          </div>
        </Panel>

        {/* Right under the summary: it is the to-do list the summary only hints at. */}
        {recommendations}
      </div>

      {/* Identity only (clutter-03): what the device IS and when it last spoke. */}
      <Panel title={t('asset.info_title', 'Informace')} icon={Info} className="self-start xl:col-span-4">
        <KeyValueList
          rows={asset.info.map((row) => ({
            id: row.label,
            label: row.label,
            value: row.value,
            mono: !row.prose,
            // What changed and why, under the time it changed - it used to be
            // dug out of the timeline below.
            hint:
              row.label === t('common.last_change', 'Poslední změna stavu') && statusChangeHint
                ? statusChangeHint
                : row.hint,
          }))}
        />
      </Panel>

      {latencyWithAvailability
        ? pair(latencyPanel, availability, 'xl:col-span-5', 'xl:col-span-7', true)
        : pair(perfPanel, latencyPanel, 'xl:col-span-7', 'xl:col-span-5')}
      {pair(
        wanPanel,
        (wifiPanel || clientsPanel) && (
          <div className="flex min-w-0 flex-col gap-6">
            {wifiPanel}
            {clientsPanel}
          </div>
        ),
        'xl:col-span-7',
        'xl:col-span-5'
      )}

      {/* The wiring of the household itself. Only the OpenWrt agent reports a
          switch, so nothing else gets a card that could only say "no data". */}
      {d.agent_type === 'openwrt' && (
        <div className="xl:col-span-12">
          <RouterPortPanel
            details={d}
            reportedAt={asset.lastCheck ? Math.floor(Date.parse(asset.lastCheck) / 1000) || null : null}
          />
        </div>
      )}

      {/* How good this monitor has actually been - the server has computed it
          in one request all along and only the public page ever asked. */}
      {latencyWithAvailability
        ? storagePanel && <div className="min-w-0 xl:col-span-12">{storagePanel}</div>
        : pair(storagePanel, availability, 'xl:col-span-5', 'xl:col-span-7', true)}

      {/* Only when it has something to say: an empty cell in the grid is a
          double gap on the page. */}
      {(charts.error ||
        charts.loading ||
        !plan ||
        restCards.length > 0 ||
        plan.flat.length > 0 ||
        plan.others.length > 0) && (
        <div className="xl:col-span-12">
          <PerformanceCharts
            plan={plan}
            cards={restCards.map(decorate)}
            error={charts.error}
            loading={charts.loading}
            onRetry={charts.reload}
            range={range}
            monitorId={asset.id}
            hasTimeSeries={asset.typeProfile.timeSeries}
          />
        </div>
      )}

      <Panel
        title={t('asset.last_measurements', 'Posledních 5 měření')}
        hint={t('asset.last_measurements_desc', 'Čerstvé kontroly včetně odezvy a místa měření.')}
        icon={Clock}
        className="xl:col-span-5"
        padding="sm"
      >
        {events.length === 0 ? (
          <p className="text-muted-foreground py-4 text-center text-sm">
            {t('asset.no_measurements', 'Zatím neproběhla žádná kontrola.')}
          </p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {events.slice(0, 5).map((e) => (
              <li key={e.id} className="bg-inset flex items-center gap-2.5 rounded-lg border border-border px-3 py-2">
                <StatusDot variant={e.severity === 'info' ? 'paused' : e.severity} />
                <div className="min-w-0 flex-1">
                  <p className="truncate text-xs font-medium">{e.title}</p>
                  <div className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-2xs">
                    <span className="figure">{e.at}</span>
                    {/* The vantage point may be unrecorded - then nothing is printed. */}
                    {e.location && <span className="truncate">· {e.location}</span>}
                  </div>
                </div>
                <span className="figure shrink-0 text-xs font-semibold">
                  {e.responseMs == null ? '—' : formatMs(e.responseMs)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Panel>

      {/* A type that never has a process ranking gets no card. One that has it
          somewhere else (a service watched by its server's agent) gets the
          pointer instead of "no agent is connected" - the agent IS connected,
          just one level up. */}
      {hasProcesses && (
        <Panel
          title={t('asset.tab_processes', 'Nejvytíženější procesy')}
          icon={Cpu}
          className="xl:col-span-3"
          padding="none"
        >
          {asset.processes.length > 0 && (
            <p className="text-2xs text-muted-foreground px-5 pb-2">
              {t(
                'asset.processes_top_hint',
                'Agent hlásí 5 nejnáročnějších procesů podle CPU a 5 podle RAM z posledního reportu — není to kompletní výpis všeho, co na stroji běží.'
              )}
            </p>
          )}
          {asset.processes.length === 0 ? (
            <div className="text-xs text-muted-foreground px-5 pt-2 pb-6 text-center">
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
                  className="text-link mt-1.5 inline-block font-medium hover:underline"
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
                    <TableCell className="font-mono tabular-nums text-right">
                      {proc.cpu != null ? formatPercent(proc.cpu, 1, lang) : '—'}
                    </TableCell>
                    <TableCell className="font-mono tabular-nums pr-5 text-right">
                      {proc.memory != null ? `${proc.memory} MB` : '—'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </Panel>
      )}

      <Panel
        title={t('asset.detected_services', 'Detekované Služby / Porty')}
        icon={Plug}
        // The processes card's third of the row goes to it when the type has none.
        className={hasProcesses ? 'xl:col-span-4' : 'xl:col-span-7'}
        padding="sm"
      >
        {/* Besides the linked monitors we also show what the agent really reports:
            listening ports and discovered (not-yet-monitored) services -
            this data used to sit unused in the report. */}
        {asset.related.length === 0 &&
          (() => {
            const ports: number[] = Array.isArray(d?.ports) ? d.ports : [];
            const found: any[] = Array.isArray(d?.discovered_services) ? d.discovered_services : [];
            if (ports.length === 0 && found.length === 0) {
              return (
                <p className="text-xs text-muted-foreground px-3 py-6 text-center">
                  {t('asset.no_related_services', 'Žádné navázané podslužby.')}
                </p>
              );
            }
            return (
              <div className="space-y-3 px-1 py-1">
                {found.length > 0 && (
                  <div className="space-y-1.5">
                    <p className="micro-label">{t('asset.agent_found_services', 'Agent objevil běžící služby')}</p>
                    {found.map((s, i) => (
                      <div
                        key={i}
                        className="border-border/60 flex items-center justify-between gap-2 border-b py-1.5 text-xs last:border-0"
                      >
                        <span className="font-medium truncate">
                          {s.name}
                          {s.port ? <span className="text-muted-foreground font-mono">:{s.port}</span> : null}
                        </span>
                        <span className="text-muted-foreground figure shrink-0">
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
                    <p className="micro-label">{t('asset.listening_ports', 'Naslouchající porty')}</p>
                    <div className="flex flex-wrap gap-1.5">
                      {ports.map((p) => (
                        <span
                          key={p}
                          className="bg-inset rounded-md border border-border px-2 py-0.5 font-mono text-2xs"
                        >
                          {p}
                        </span>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            );
          })()}
        {asset.related.length > 0 && (
          <ul className="flex flex-col gap-1.5">
            {asset.related.map((service) => (
              <li
                key={service.name}
                className="bg-inset flex items-center gap-3 rounded-lg border border-border px-3 py-2"
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{service.name}</p>
                  <p className="text-muted-foreground truncate text-xs">
                    {service.kind} · {service.detail}
                  </p>
                </div>
                <Pill tone={PILL_TONE[statusMeta(service.statusKey).variant]} dot size="sm">
                  {statusLabel(service.statusKey, t)}
                </Pill>
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </div>
  );
}

const PILL_TONE: Record<ReturnType<typeof statusMeta>['variant'], PillTone> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  info: 'info',
  paused: 'paused',
  neutral: 'neutral',
};

/**
 * Two panels side by side on a wide screen, each full width when the other
 * is missing - a lone half-width card left a hole beside it. `stretch` lets
 * both keep their own height (items-start) instead of the taller one's.
 */
function pair(a: React.ReactNode, b: React.ReactNode, aSpan: string, bSpan: string, stretch = false): React.ReactNode {
  const has = (n: React.ReactNode) => n !== false && n !== null && n !== undefined;
  if (!has(a) && !has(b)) return null;
  if (!has(a)) return <div className="min-w-0 xl:col-span-12">{b}</div>;
  if (!has(b)) return <div className="min-w-0 xl:col-span-12">{a}</div>;
  return (
    <>
      <div className={cn('min-w-0', aSpan, stretch && 'self-start')}>{a}</div>
      <div className={cn('min-w-0', bSpan, stretch && 'self-start')}>{b}</div>
    </>
  );
}

/** A router reports its line: protocol, state, echo, speed or uptime. */
function hasWanData(d: Record<string, any>): boolean {
  return d.wan_proto != null || d.wan_up != null || d.wan_internet != null || d.wan_link_mbit != null;
}

/**
 * The overview's chart plan (charts-08): at most six featured cards by type,
 * a flat one as one line, the rest by subsystem - with the WAN and LTE
 * traffic stacked into one chart where the backup carried anything. null
 * while the charts load, fail or come back empty.
 */
function overviewPlan(
  rawData: ChartData[] | null,
  kind: string,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
) {
  if (!rawData || rawData.length === 0 || !rawData.some((c) => c.series.some((s) => s.points.length > 0))) return null;
  // Both links on one chart, stacked. "Did the backup carry the traffic while
  // the primary was down?" needed two cards and a mental overlay; stacked, the
  // height is the total and each band is one link's share.
  const wan = rawData.find((c) => c.id === 'net');
  const lte = rawData.find((c) => c.id === 'net_lte');
  const combined: ChartData | null =
    wan && lte && lte.series[0]?.points.some((p) => p.v != null && p.v > 0)
      ? {
          id: 'net-combined',
          title: t('asset.traffic_combined', 'Provoz po linkách (WAN + LTE)'),
          window: wan.window,
          yMax: null,
          yMin: 0,
          stacked: true,
          series: [
            { ...wan.series[0], label: t('net.link_primary', 'Primární (WAN)') },
            { ...lte.series[0], label: t('net.link_backup', 'Záloha (LTE)') },
          ],
        }
      : null;
  return planOverview(rawData, kind, combined);
}

/**
 * Monitor events as vertical markers in the charts - an outage or restart is
 * visible right where the metric jumped. MySQL datetimes are parsed via the
 * 'T' variant (Safari cannot handle a bare 'YYYY-MM-DD HH:MM').
 */
function eventMarkers(events: TimelineEvent[]): { t: number; label: string; severity: 'alert' | 'info' }[] {
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
}

/** The router has its own cards and its own recommendations. */
function isRouterKind(kind: string | null | undefined): boolean {
  const upper = (kind || '').toUpperCase();
  return upper === 'ROUTER' || upper === 'OPENWRT';
}

/** A machine with an agent or a hosting account: its services tab is about storage. */
function isServerKind(kind: string | null | undefined): boolean {
  const upper = (kind || '').toUpperCase();
  return upper === 'VPS' || upper === 'CPANEL';
}

/** A count on a tab, quieter than its name; nothing at zero. */
function TabCount({ n }: { n: number }) {
  if (n <= 0) return null;
  return <span className="text-muted-foreground ml-1.5 text-2xs font-mono tabular-nums">{n}</span>;
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
  const shown = (
    // Mono with tabular digits, like every figure in the app (apps/site DESIGN.md).
    <span className="figure text-right">{missing ? '—' : value}</span>
  );
  return (
    <div className="border-border flex items-center justify-between gap-3 border-b py-2 text-xs last:border-0">
      <span className="text-muted-foreground">{label}</span>
      {to ? (
        <Link
          to={to}
          className="hover:text-link focus-visible:ring-ring rounded transition-colors focus-visible:ring-2 focus-visible:outline-none"
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

/** The Network tab's section anchors, so a link or a later summary can jump to one. */
const NET_ANCHORS = { wan: 'net-wan', lte: 'net-lte', wifi: 'net-wifi', speed: 'net-speed' } as const;

/**
 * A titled block of the Network tab, a kit Panel (a labelled region). `id`
 * makes it a jump target (W2-3): focusable without joining the tab order,
 * and clear of the sticky tab bar.
 */
function Section({
  title,
  icon,
  id,
  children,
}: {
  title: string;
  icon?: LucideIcon;
  id?: string;
  children: React.ReactNode;
}) {
  return (
    <Panel
      id={id}
      tabIndex={id ? -1 : undefined}
      title={title}
      icon={icon}
      padding="sm"
      className="scroll-mt-20 outline-none"
    >
      {children}
    </Panel>
  );
}

/** The modem's SIM state in words; a state this build does not know is printed as sent. */
function simStateText(state: string, t: ReturnType<typeof useLanguage>['t']): string {
  if (state === 'ready') return t('rsvc.sim_ready', 'připravená');
  if (state === 'no_sim') return t('rsvc.lte_reason_no_sim', 'SIM karta nenalezena');
  if (state === 'pin_required') return t('rsvc.lte_reason_pin', 'SIM čeká na PIN');
  if (state === 'puk_required') return t('rsvc.lte_reason_puk', 'SIM zablokovaná (PUK)');
  if (state === 'invalid') return t('rsvc.lte_reason_invalid', 'SIM odmítnuta sítí');
  return state;
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

  const title = t('net.link_traffic_title', 'Provoz podle linky');
  if (data === undefined) {
    return (
      <Section icon={Split} title={title}>
        <LoadingState label={t('net.link_loading', 'Načítám…')} size="inline" />
      </Section>
    );
  }
  if (data === null) {
    return (
      <Section icon={Split} title={title}>
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
  // Both directions of one link over the period; null = that side is not
  // known or has no rows yet - named with a dash, never drawn as zero.
  const total = (side: LinkTrafficResponse['primary'], key: 'today' | '7d' | '30d') => {
    const w = side?.[key];
    // Both directions or no total: one missing side would pass for "nothing that way".
    if (!w || typeof w.rx_bytes !== 'number' || typeof w.tx_bytes !== 'number') return null;
    return w.rx_bytes + w.tx_bytes;
  };
  const fmtDuration = (secs: number) => formatDuration(secs, lang);
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const fmtTs = (ts: number | null, fallback: string) =>
    ts == null ? fallback : new Date(ts * 1000).toLocaleString(locale, { dateStyle: 'short', timeStyle: 'short' });
  const wanEverDown = data.wan_down_seconds > 0 || data.wan_down_periods.length > 0;

  return (
    <Section title={title}>
      {/* One WAN-vs-LTE bar per period (W2-3): which line carried the bytes is
          a share of one whole, which three rows of "↓12 GB ↑1 GB" per side
          made the reader work out. The interfaces are named once, in the legend. */}
      <div className="space-y-2.5">
        {windows.map((w) => (
          <div key={w.key} className="space-y-1">
            <p className="text-muted-foreground text-2xs">{w.label}</p>
            <RatioBar
              label={`${title} · ${w.label}`}
              format={(v) => formatBytesShort(v) ?? '—'}
              parts={[
                {
                  key: 'primary',
                  label: data.primary
                    ? `${t('net.link_primary', 'Primární (WAN)')} ${data.primary.iface}`
                    : t('net.link_primary', 'Primární (WAN)'),
                  value: total(data.primary, w.key),
                },
                {
                  key: 'backup',
                  label: data.backup
                    ? `${t('net.link_backup', 'Záloha (LTE)')} ${data.backup.iface}`
                    : t('net.link_backup', 'Záloha (LTE)'),
                  value: total(data.backup, w.key),
                },
              ]}
            />
          </div>
        ))}
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
              <div key={i} className="flex justify-between gap-2 font-mono tabular-nums">
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
  recommendations,
  router,
}: {
  d: Record<string, any>;
  /** This page's monitor: both the page to come back to and the owner of the metric. */
  monitorId: number;
  /** The compact recommendations of one area, placed next to the card they are about (routers only). */
  recommendations?: (area: 'wifi' | 'wan') => React.ReactNode;
  /** A router: it gets the line speed card, fed by one bottleneck request. */
  router?: boolean;
}) {
  // Rows whose number is also a stored metric: measured every minute, kept for
  // months, and until now readable only as its latest value.
  const history = (key: string) => `/infrastructure/${monitorId}/metric/${monitorId}/${key}`;
  const { t } = useLanguage();
  // One request for the line speed verdict, made only for a router.
  const bottleneck = useWanBottleneck(router ? monitorId : null);

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
  const mwan3: any[] = Array.isArray(d.mwan3_policies) ? d.mwan3_policies : [];
  const hasLte = d.lte_up != null || d.lte_rsrp != null || d.lte_rssi != null;
  const hasVpn =
    wg.length > 0 ||
    d.tailscale_up != null ||
    (d.zerotier_networks != null && d.zerotier_networks > 0) ||
    (d.openvpn_tunnels != null && d.openvpn_tunnels > 0);
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
      {/* Wi-Fi full width: with the radio profile, the client lines and five
          readings per radio it does not fit a grid cell. Its recommendations
          sit at its top, so the finding and the numbers it came from are on
          one screen. */}
      {wifi.length > 0 && (
        <Panel
          id={NET_ANCHORS.wifi}
          tabIndex={-1}
          icon={Wifi}
          title={`Wi-Fi (${d.wifi_clients_count ?? wifi.reduce((s, r) => s + (Number(r.clients) || 0), 0)} ${t('net.clients', 'klientů')})`}
          className="scroll-mt-20 outline-none"
          bodyClassName="space-y-3"
        >
          {recommendations?.('wifi')}
          <WifiRadioList radios={wifi} history={history} />
        </Panel>
      )}
      {recommendations?.('wan')}
      {router && <WanBottleneckCard monitorId={monitorId} source={bottleneck} id={NET_ANCHORS.speed} />}
      {/* The port front panel moved to the overview (NetPulse device page): the
          wiring is part of what the device IS, not a network detail. */}

      {/* items-start: a short card keeps its height instead of stretching to
          the tallest one in its row (W2-3). */}
      <div className="grid items-start gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {(d.wan_proto != null || d.wan_up != null || d.wan_internet != null) && (
          <Section id={NET_ANCHORS.wan} icon={Globe} title={t('net.wan_title', 'WAN připojení')}>
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
            {/* The echo bound to the WAN device - what tells "up" from "up and on the internet". */}
            <Row
              label={t('net.wan_internet', 'Ping ven přes WAN')}
              value={
                d.wan_internet == null
                  ? null
                  : d.wan_internet
                    ? t('net.wan_internet_ok', 'odpovídá')
                    : t('net.wan_internet_fail', 'neodpovídá')
              }
            />
            {/* The negotiated port speed - a gigabit port dropped to 100 Mbit shows here. */}
            <Row
              label={t('net.port_speed', 'Rychlost portu')}
              value={d.wan_link_mbit != null ? `${d.wan_link_mbit} Mbit/s` : null}
            />
            <Row
              label={t('rsvc.wan_latency', 'Odezva k bráně')}
              value={d.wan_latency_ms != null ? `${d.wan_latency_ms} ms` : null}
            />
            {d.mwan3_active_gw != null && <Row label="mwan3" value={String(d.mwan3_active_gw)} />}
            {mwan3.length > 0 && (
              <Row
                label={t('rsvc.mwan3', 'Multi-WAN (mwan3)')}
                value={mwan3
                  .filter((p: any) => p?.interface)
                  .map((p: any) => `${p.interface}: ${p.status ?? '—'}`)
                  .join(' · ')}
              />
            )}
            {/* The shaper sits on the WAN, so its rows do too (W2-3 split "SQM & LTE"). */}
            {d.sqm_enabled != null && (
              <Row
                label="SQM"
                value={
                  d.sqm_enabled
                    ? `${t('common.online', 'Online')}${d.sqm_download_kbps ? ` · ↓${Math.round(d.sqm_download_kbps / 1000)} Mbit/s` : ''}${d.sqm_upload_kbps ? ` ↑${Math.round(d.sqm_upload_kbps / 1000)} Mbit/s` : ''}`
                    : t('net.sqm_off', 'Vypnuto')
                }
              />
            )}
            <Row label={t('net.sqm_dropped', 'SQM zahozeno')} value={d.sqm_dropped} />
            <Row label="SQM ECN" value={d.sqm_ecn != null ? (d.sqm_ecn ? 'ECN' : 'noECN') : null} />
            {d.sqm_enabled === false && (
              <p className="text-muted-foreground pt-1 text-2xs leading-relaxed">
                {t('rsvc.sqm_off_hint', 'Bez SQM se při plném vytížení linky zhoršuje odezva (bufferbloat).')}
              </p>
            )}
          </Section>
        )}

        {(d.lan_subnet != null || d.dhcp_leases_count != null) && (
          <Section icon={House} title={t('net.lan_title', 'LAN & DHCP')}>
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
          <Section icon={Compass} title="DNS">
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

        {(d.fw_accepted != null || d.conntrack_pct != null || d.firewall_enabled != null) && (
          <Section icon={Shield} title={t('net.fw_title', 'Firewall & Conntrack')}>
            {/* Whether the firewall runs at all - the old service tile said it, the counters below only imply it. */}
            <Row
              label={t('common.status', 'Stav')}
              value={
                d.firewall_enabled == null
                  ? null
                  : d.firewall_enabled
                    ? t('rsvc.active', 'Aktivní')
                    : t('rsvc.inactive', 'Vypnutý')
              }
            />
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

        {/* Every tunnel in one card (W2-3): WireGuard used to have its own,
            Tailscale and ZeroTier sat under "SQM & LTE", OpenVPN under "Systém". */}
        {hasVpn && (
          <Section icon={Lock} title={t('net.vpn_title', 'VPN')}>
            {wg.length > 0 && (
              <p className="text-muted-foreground pt-0.5 text-2xs font-semibold">WireGuard ({wg.length})</p>
            )}
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
              label="OpenVPN"
              value={d.openvpn_tunnels != null && d.openvpn_tunnels > 0 ? `${d.openvpn_tunnels}× tunel` : null}
            />
          </Section>
        )}

        {ifaces.length > 0 && (
          <Section icon={Plug} title={`${t('net.ifaces_title', 'Rozhraní')} (${ifaces.length})`}>
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

        {hasLte && (
          <Section id={NET_ANCHORS.lte} icon={RadioTower} title={t('net.lte_backup', 'LTE záloha')}>
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
              label={t('net.lte_backup_state', 'Stav zálohy')}
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
            {/* Only when the modem said nothing about the signal at all: RSSI
                without RSRP is a modem that answers, not a missing package. */}
            {d.lte_up === true &&
              d.lte_rsrp == null &&
              d.lte_rssi == null &&
              d.lte_rsrq == null &&
              d.lte_sinr == null && (
                <p className="text-muted-foreground col-span-full text-2xs leading-relaxed">
                  {t(
                    'net.lte_no_signal_data',
                    'Spojení běží, ale sílu signálu router nehlásí — modem není dostupný přes ModemManager. Doinstalováním balíčku umodem-manager (nebo uqmi) začne agent hlásit i RSRP, RSRQ a pásmo.'
                  )}
                </p>
              )}
            {/* What the modem says about the SIM and the network - the service
                tile of the old "Služby" tab carried these (W2-3). */}
            <Row
              label="SIM"
              value={
                typeof d.lte_sim_state === 'string'
                  ? `${simStateText(d.lte_sim_state, t)}${
                      d.lte_sim_state === 'pin_required' && d.lte_sim_pin_left != null
                        ? ` (${t('rsvc.pin_attempts', { count: d.lte_sim_pin_left }, `zbývá pokusů: ${d.lte_sim_pin_left}`)})`
                        : ''
                    }`
                  : null
              }
            />
            <Row
              label={t('rsvc.registration', 'Registrace v síti')}
              value={
                d.lte_connected == null
                  ? null
                  : `${d.lte_connected ? t('rsvc.registered', 'přihlášen') : t('rsvc.not_registered', 'nepřihlášen')}${
                      d.lte_conn_code != null ? ` (${d.lte_conn_code})` : ''
                    }`
              }
            />
            <Row label="RSSI" value={d.lte_rssi != null ? `${d.lte_rssi} dBm` : null} />
            <Row label={t('rsvc.bandwidth', 'Šířka pásma')} value={d.lte_bandwidth || null} />
            <Row
              label={t('rsvc.cell', 'Buňka')}
              value={d.lte_cell_id != null ? `${d.lte_cell_id}${d.lte_pci != null ? ` · PCI ${d.lte_pci}` : ''}` : null}
            />
            <Row label="PLMN" value={d.lte_plmn ?? null} />
            {/* Up while the modem says nothing about the SIM is the exact shape of
                the HiLink bug: unverified, never OK. RSSI without RSRP is a modem
                that fills one tag and not the other - not a missing package. */}
            {lteBackupState(d).ok === null && d.lte_up === true ? (
              <p className="text-muted-foreground pt-1 text-2xs leading-relaxed">
                {t(
                  'rsvc.lte_unverified_note',
                  'Rozhraní k modemu běží, ale modem nehlásí stav SIM ani registraci - zálohu nelze potvrdit. Bez SIM nebo se špatným PINem vypadá rozhraní úplně stejně.'
                )}
              </p>
            ) : d.lte_up === true && d.lte_rsrp == null && d.lte_rssi != null ? (
              <p className="text-muted-foreground pt-1 text-2xs leading-relaxed">
                {t(
                  'rsvc.lte_rssi_only',
                  'Modem hlásí RSSI, ale ne RSRP — tuhle hodnotu prostě nevyplňuje. Pro sílu signálu se řiďte RSSI.'
                )}
              </p>
            ) : null}
          </Section>
        )}

        {(d.installed_packages != null ||
          d.log_errors_24h != null ||
          restarts.length > 0 ||
          d.entropy != null ||
          d.agent_run_ms != null) && (
          <Section icon={Server} title={t('net.sys_title', 'Systém & Služby')}>
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
              label="UPS"
              value={
                d.ups_status != null
                  ? `${d.ups_status}${d.ups_battery_pct != null ? ` · baterie ${d.ups_battery_pct} %` : ''}`
                  : null
              }
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
/**
 * Remote actions as labels and one trigger, shared by the desktop "Akce" menu
 * and the phone's overflow menu (W2-2): the prompt, the confirmation for a
 * reboot and the queued/failed message are the same wherever it is clicked.
 */
function useRemoteActions(asset: AssetDetail) {
  const { t } = useLanguage();
  const [busy, setBusy] = React.useState(false);
  const [result, setResult] = React.useState<{ ok: boolean; text: string } | null>(null);

  const labels: Record<string, string> = {
    restart_wan: t('asset.ra_restart_wan', 'Restart WAN'),
    restart_wireguard: t('asset.ra_restart_wireguard', 'Restart WireGuard'),
    reboot_router: t('asset.ra_reboot_router', 'Restartovat router'),
    renew_dhcp: t('asset.ra_renew_dhcp', 'Obnovit DHCP'),
    restart_service: t('asset.ra_restart_service', 'Restartovat službu…'),
    reconnect_pppoe: t('asset.ra_reconnect_pppoe', 'Reconnect PPPoE'),
  };

  const trigger = async (action: string) => {
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

  return { labels, trigger, busy, result };
}

function ActionsMenu({ asset }: { asset: AssetDetail }) {
  const { t } = useLanguage();
  const [open, setOpen] = React.useState(false);
  const { labels, trigger: run, busy, result } = useRemoteActions(asset);
  const wrapRef = React.useRef<HTMLDivElement>(null);
  const trigger = (action: string) => {
    setOpen(false);
    void run(action);
  };

  React.useEffect(() => {
    const onDown = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, []);

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
  plan,
  cards,
  error,
  loading,
  range,
  monitorId,
  hasTimeSeries = true,
  onRetry,
}: {
  /** The page's chart plan; null = nothing measured (or still loading, or failed). */
  plan: ReturnType<typeof overviewPlan>;
  /** The featured cards the panels above did not take, decorated with bands and events. */
  cards: ChartData[];
  error: Error | null;
  loading: boolean;
  /** Refetches after a failure; the error state offers it as "try again". */
  onRetry?: () => void;
  range: TimeRange;
  /** This page's monitor; the metric detail (Level 3) links back to it. */
  monitorId: number;
  /** False = this monitor TYPE stores no metric history, so "no data" is not news. */
  hasTimeSeries?: boolean;
}) {
  const { t, lang } = useLanguage();

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
      <div aria-busy="true" className="grid gap-4 lg:grid-cols-2">
        {['cpu', 'ram'].map((key) => (
          <Skeleton key={key} className="h-48 w-full rounded-xl" />
        ))}
        <span className="sr-only">{t('metric.loading', 'Načítám měření…')}</span>
      </div>
    );
  }

  if (!plan) {
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
      <EmptyState
        boxed
        title={
          <span className="text-foreground">
            {t('asset.no_chart_data', 'Data pro tento monitor nejsou v databázi k dispozici')}
          </span>
        }
        hint={t(
          'asset.no_chart_data_desc',
          { range },
          `Nebyla nalezena žádná naměřená historie časových řad pro zadaný rozsah ${range}.`
        )}
      />
    );
  }

  const { flat, others } = plan;
  const metricHref = (id: string) => `/infrastructure/${monitorId}/metric/${monitorId}/${id}`;

  return (
    <div className="flex flex-col gap-4">
      {cards.length > 0 && (
        <div className="grid gap-4 lg:grid-cols-2">
          {cards.map((chart) => (
            // Link through to Level 3; the combined chart has no single metric behind it.
            <ChartCard
              key={chart.id}
              data={chart}
              group="asset-performance"
              to={chart.id === 'net-combined' ? undefined : metricHref(chart.id)}
            />
          ))}
        </div>
      )}

      {flat.length > 0 && (
        <Panel padding="sm">
          <ul
            className="divide-border divide-y text-xs"
            aria-label={t('asset.flat_title', 'Beze změny v tomto období')}
          >
            {flat.map((chart) => {
              const r = valueRange(chart);
              const unit = chart.series[0]?.unit ?? '';
              const shownValue =
                r == null
                  ? '—'
                  : r.min === r.max
                    ? formatMetricValue(r.min, unit, lang)
                    : `${formatMetricValue(r.min, unit, lang)} – ${formatMetricValue(r.max, unit, lang)}`;
              return (
                <li key={chart.id}>
                  <Link
                    to={chart.id === 'net-combined' ? metricHref('net') : metricHref(chart.id)}
                    className="hover:bg-raised focus-visible:ring-ring -mx-2 flex items-baseline justify-between gap-3 rounded-md px-2 py-1.5 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                  >
                    <span className="font-medium">{chart.title}</span>
                    <span className="text-muted-foreground figure">
                      {t('asset.flat_value', { value: shownValue }, `${shownValue}, beze změny`)}
                    </span>
                  </Link>
                </li>
              );
            })}
          </ul>
        </Panel>
      )}

      {others.length > 0 && (
        <Panel
          title={t('asset.more_metrics', { count: others.length }, `Další měřené metriky (${others.length})`)}
          hint={t(
            'asset.more_metrics_hint',
            'Tohle zařízení je hlásí každou minutu a historie se ukládá. Klikněte na kteroukoli pro graf, rozložení hodnot a souvislosti.'
          )}
          icon={Activity}
        >
          {/* By subsystem (C-13): a closed group still names its count and the
              metric that moved most, and the flat ones fold into one line. */}
          <div className="space-y-2">
            {groupMetrics(others).map((group) => {
              // grid-cols-1 (minmax(0, 1fr)) on a phone: the implicit column
              // grew to the longest metric name and pushed the rows past the
              // card (V-12); truncation needs a column that may shrink.
              const rows = (list: ChartData[]) => (
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {list.map((chart) => (
                    <MetricRow key={chart.id} chart={chart} to={metricHref(chart.id)} />
                  ))}
                </div>
              );
              const notable = group.notable;
              return (
                <MetricGroup
                  key={group.subsystem}
                  title={subsystemTitle(group.subsystem, t)}
                  count={group.changed.length + group.unchanged.length}
                  notable={
                    notable
                      ? `${notable.title}: ${formatMetricValue(latestValue(notable), notable.series[0]?.unit ?? '', lang)}`
                      : undefined
                  }
                  unchangedCount={group.unchanged.length}
                  unchanged={rows(group.unchanged)}
                >
                  {group.changed.length > 0 && rows(group.changed)}
                </MetricGroup>
              );
            })}
          </div>
        </Panel>
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
  const { lang } = useLanguage();
  const series = chart.series[0];
  const points = series?.points ?? [];
  const latest = latestValue(chart);

  return (
    <Link
      to={to}
      className="hover:bg-muted/40 focus-visible:ring-ring border-border/40 flex items-center gap-3 rounded-md border px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
    >
      <span className="min-w-0 flex-1">
        <span className="block truncate text-xs font-medium">{chart.title}</span>
        <span className="text-muted-foreground font-mono tabular-nums block text-2xs">
          {formatMetricValue(latest, series?.unit ?? '', lang)}
        </span>
      </span>
      {points.length >= 2 && (
        <Sparkline
          points={points}
          window={chart.window}
          tone={series?.tone ?? 'latency'}
          unit={series?.unit}
          className="h-6 w-16 shrink-0"
        />
      )}
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
    // The machine-readable moment, so a collapsed run spans real times
    // ("21. 9. 10:00–12:40") instead of repeating the relative label at
    // both ends (V-15). timeOf() reads it with 'T' in place of the space.
    atIso: e.at,
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
  siblings: ApiMonitor[] = [],
  lang: string = 'cs'
): AssetDetail {
  const numLang = lang === 'en' ? 'en' : 'cs';
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

  const stateLabel = statusLabel(monitorStatusKey(m), t);
  const inStateSecs = m.sinceStatusChangeSeconds ?? m.uptimeSeconds ?? null;
  // Seconds since boot; the agents send it as `uptime`. Only a positive count
  // is a reading - a missing /proc/uptime arrives as null, never as 0 s.
  const deviceUptime =
    [m.details?.uptime, m.details?.uptime_sec].find(
      (v): v is number => typeof v === 'number' && Number.isFinite(v) && v > 0
    ) ?? null;

  const isTS3 = isTeamSpeakMonitor(m);
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
    statusKey: monitorStatusKey(m),
    breadcrumb: [m.category ?? 'Monitory'],
    // The KPI row per the mockup: time in state, device uptime, latency, CPU,
    // RAM, disk, temperature. No status here - the hero badge already shows
    // it; the Health Score tile is added by OverviewTab from server insights.
    health: [
      // Time since the last status change, named for what it is (honest-21):
      // the tile said "Uptime 40 d" for a router that had rebooted twice in
      // that time - it had only stayed "online" to the checks.
      ...(inStateSecs != null
        ? [
            {
              key: 'uptime',
              label:
                status === 'up'
                  ? t('asset.online_for', 'Online nepřetržitě')
                  : status === 'down'
                    ? t('asset.down_for', 'Výpadek trvá')
                    : t('asset.state_lasts', { state: stateLabel }, `${stateLabel} trvá`),
              value: formatDuration(inStateSecs, lang),
            },
          ]
        : []),
      // The machine's own uptime from the agent (/proc/uptime): how long since it booted.
      ...(deviceUptime != null
        ? [
            {
              key: 'device_uptime',
              label: t('asset.device_uptime', 'Uptime zařízení'),
              value: formatDuration(deviceUptime, lang),
            },
          ]
        : []),
      ...(profile.latency || m.responseMs != null
        ? [
            {
              key: 'latency',
              label: t('common.response', 'Odezva'),
              value: m.responseMs != null ? `${formatNumber(m.responseMs, numLang, 0)} ms` : '—',
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
              value: usage.cpu != null ? `${formatNumber(usage.cpu, numLang, 1)} %` : '—',
              breach: breachTone(metricSeverity(usage.cpu, thresholdFor(m, 'cpu'))),
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
              value:
                usage.ram == null
                  ? '—'
                  : profile.ram === 'mb'
                    ? `${formatNumber(usage.ram, numLang, 0)} MB`
                    : `${formatNumber(usage.ram, numLang, 1)} %`,
              // Megabytes of one process have no limit to cross.
              breach: profile.ram === 'mb' ? null : breachTone(metricSeverity(usage.ram, thresholdFor(m, 'ram'))),
              tone: 'memory' as const,
            },
          ]
        : []),
      ...(profile.disk || m.hdd != null
        ? [
            {
              key: 'hdd',
              label: t('common.hdd', 'Využití disku'),
              value: m.hdd != null ? `${formatNumber(m.hdd, numLang, 1)} %` : '—',
              breach: breachTone(metricSeverity(m.hdd, thresholdFor(m, 'hdd'))),
              tone: 'disk' as const,
            },
          ]
        : []),
      ...(socTemp != null
        ? [
            {
              key: 'temp',
              label: t('asset.temperature', 'Teplota'),
              value: `${formatNumber(socTemp, numLang, 0)} °C`,
              tone: 'temperature' as const,
            },
          ]
        : []),
    ],
    // Only what is said nowhere else on the page (clutter-17): "Všechny testy
    // OK" repeated the status badge and "Typ" the parameter list.
    summaryChips: [
      // Stored for years, never displayed: a server awaiting restart and watched
      // processes that are not running - both belong at first sight.
      ...(m.details?.reboot_required
        ? [
            {
              label: t('asset.reboot_required', 'Server čeká na restart (aktualizace jádra)'),
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
    // Identity only (clutter-03): what the device IS and when it last spoke.
    // Response time, throughput, disk I/O, inodes, swap, retransmissions and
    // conntrack were rows here as well as tiles or charts - each fact is on
    // the page once now, in the place that can show how it moves.
    info: [
      { label: t('common.last_check', 'Poslední kontrola'), value: lastCheckDisplay },
      { label: t('common.last_change', 'Poslední změna stavu'), value: lastChangeDisplay },
      // `os` echoes the type for monitors that report no system name - the row
      // then said "Operační systém: agent_service" next to "Typ protokolu".
      ...(osLabel ? [{ label: t('infra.os', 'Operační systém'), value: osLabel, prose: true }] : []),
      ...(m.details?.model ? [{ label: t('asset.model', 'Model'), value: String(m.details.model) }] : []),
      ...(m.details?.board_name ? [{ label: t('asset.board', 'Board'), value: String(m.details.board_name) }] : []),
      ...(m.details?.kernel ? [{ label: t('asset.kernel', 'Kernel'), value: String(m.details.kernel) }] : []),
      ...(m.details?.virtualization
        ? [{ label: t('asset.virtualization', 'Virtualizace'), value: String(m.details.virtualization), prose: true }]
        : []),
      ...(m.details?.cloud_provider
        ? [{ label: t('asset.cloud_provider', 'Cloud'), value: String(m.details.cloud_provider), prose: true }]
        : []),
      ...(m.details?.timezone
        ? [{ label: t('asset.timezone', 'Časová zóna'), value: String(m.details.timezone) }]
        : []),
      { label: t('asset.protocol_type', 'Typ protokolu'), value: typeLabel, prose: true },
    ],
    responseMs: typeof m.responseMs === 'number' && Number.isFinite(m.responseMs) ? m.responseMs : null,
    latencyLimitMs: typeof m.latencyThresholdMs === 'number' && m.latencyThresholdMs > 0 ? m.latencyThresholdMs : null,
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
      statusKey: monitorStatusKey(s),
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
      className="bg-inset flex flex-wrap items-center gap-3 rounded-lg border border-border p-3 text-xs"
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
