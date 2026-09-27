import * as React from 'react';
import { Link } from 'react-router';
import {
  CalendarDays,
  CheckCircle2,
  CircleX,
  CloudOff,
  Gamepad2,
  Globe,
  Info,
  LayoutGrid,
  LayoutList,
  MessageSquare,
  Mic,
  PieChart,
  Plus,
  Radar,
  Router as RouterIcon,
  Search,
  Server,
  TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { SectionTitle } from '@/components/ui/section-title';
import { ageText } from '@/components/freshness-pill';
import { StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { breachTone, StatBlock, StatRow } from '@/components/stat-block';
import { HealthDonut } from '@/components/health-donut';
import { HealthBreakdown, HealthRing } from '@/components/health-ring';
import { HealthDeductions } from '@/components/health-deductions';
import { Sparkline } from '@/components/sparkline';
import { Panel } from '@/components/ui/panel';
import { Pill, type PillTone } from '@/components/ui/pill';
import { ListRow, ListRows } from '@/components/ui/list-row';
import type { DashboardTile } from '@/components/dashboard-layout-editor';
import type { DayUptime, UptimeHistoryRow } from '@/data/model';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { DataSourceBanner } from '@/components/data-source-banner';
import { CollectorHealthBanner } from '@/components/collector-health-banner';
import { NotificationHealthBanner } from '@/components/notification-health-banner';
import { SiteHealthBanner } from '@/components/site-health-banner';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { usePageChrome } from '@/components/layout/shell-context';
import { usePublicStatus } from '@/api/use-asset-charts';
import { cn, formatMs, formatPercent, formatPercentValue, formatRelative } from '@/lib/utils';
import { windowFor } from '@/lib/chart-window';
import { formatDuration, localeFor } from '@/lib/metric-format';
import { nestUnderAgents, processUsage } from '@/lib/monitor-grouping';
import { buildNeedsAttention, metricSeverity, thresholdFor } from '@/lib/attention';
import { monitorStatusKey, statusLabel, statusMeta, type StatusKey } from '@/lib/status';
import { splitHistory, statusCounts, verdictSentence, worstNamedState } from '@/lib/fleet-verdict';
import { pluralForm } from '@/lib/plural';
import { isProbeMonitor, normalizeMonitorType } from '@/lib/monitor-type';
import { LoadingState, EmptyState, ErrorState, Skeleton } from '@/components/ui/states';
import { useFleetHealth } from './dashboard-health';
import { DeviceCards, isDeviceMonitor } from './dashboard-devices';
import type { TrafficSource } from './dashboard-traffic';

type MonitorStatus = ApiMonitor['status'];

type StatusFilter = 'all' | MonitorStatus;

/**
 * Order the fleet is read in: what is broken first, what is fine last. The
 * table used to be in API order, so a dead service could sit below thirty
 * green rows and only the Offline tab brought it up - which then hid the
 * warnings. Children of an agent are placed by nestUnderAgents afterwards,
 * so this only moves the parents.
 */
const SEVERITY_RANK: Record<MonitorStatus, number> = {
  down: 0,
  unknown: 1,
  warning: 2,
  maintenance: 3,
  paused: 4,
  up: 5,
};

/** One stable empty list for "not loaded yet", so memo dependencies do not churn. */
const NO_MONITORS: ApiMonitor[] = [];

// Loaded on demand, not with the first screen: the layout editor opens on a
// click, the regions panel only when someone put it on their dashboard, the
// findings and the history sit below the fold, and the traffic card brings
// the chart library. The critical path has a byte budget and the dashboard is
// on it (UX wave 2, check:bundle).
const DashboardLayoutEditor = React.lazy(() =>
  import('@/components/dashboard-layout-editor').then((m) => ({ default: m.DashboardLayoutEditor }))
);
const RegionsPanel = React.lazy(() => import('@/components/regions-panel').then((m) => ({ default: m.RegionsPanel })));
const FindingsList = React.lazy(() => import('@/components/findings-list').then((m) => ({ default: m.FindingsList })));
const UptimeHeatmap = React.lazy(() =>
  import('@/components/uptime-heatmap').then((m) => ({ default: m.UptimeHeatmap }))
);
const DashboardTraffic = React.lazy(() => import('./dashboard-traffic'));

/** At most this many device cards; the rest are one "Zobrazit vše" away. */
const DEVICE_CARDS = 8;

export function DashboardPage() {
  const { t, lang } = useLanguage();
  const [query, setQuery] = React.useState('');
  const [filter, setFilter] = React.useState<StatusFilter>('all');
  // The public status refreshes every minute. The monitor list keeps its own
  // minute clock instead of following `live`: a failed refresh deliberately
  // keeps the old `live` object, so a list tied to it silently stopped
  // reloading while the caption kept promising a refresh every minute.
  const { data: live, error: liveError } = usePublicStatus(60_000);
  const [refreshTick, setRefreshTick] = React.useState(0);
  React.useEffect(() => {
    const id = window.setInterval(() => setRefreshTick((n) => n + 1), 60_000);
    return () => window.clearInterval(id);
  }, []);
  // When the monitor list was last loaded. The page refreshes every minute
  // and said nothing about it, so a reader could not tell whether the
  // numbers were from now or from before the last outage.
  const [loadedAt, setLoadedAt] = React.useState<Date | null>(null);
  // The user's dashboard layout (panel visibility + order). An empty array
  // = the default layout is kept, so nothing is lost until the user
  // configures something.
  const [layoutOpen, setLayoutOpen] = React.useState(false);
  const [tiles, setTiles] = React.useState<DashboardTile[]>([]);
  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=dashboard_layout', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (active && Array.isArray(d?.tiles) && d.tiles.length > 0) setTiles(d.tiles);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);

  const { session } = useSession();
  // null until the first answer: an empty list before it arrived drew
  // "Výpadky 0 - Všechny systémy bez výpadku" on every page load (W1-A4).
  const [monitorsData, setMonitors] = React.useState<ApiMonitor[] | null>(null);
  const monitors = monitorsData ?? NO_MONITORS;
  const [monitorsLoading, setMonitorsLoading] = React.useState(true);
  const [monitorsError, setMonitorsError] = React.useState<string | null>(null);
  // The server's ssl_alert_days; null (older server) falls back to lib/attention's 14.
  const [sslAlertDays, setSslAlertDays] = React.useState<number | null>(null);

  React.useEffect(() => {
    let active = true;

    appApi
      .getMonitorList()
      .then(({ monitors: rows, sslAlertDays: sslDays }) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : [];
        setSslAlertDays(sslDays);
        const userTargets = list.filter((m: ApiMonitor) => {
          // By type, like api.php - never by name (W1-D2).
          return !isProbeMonitor(m.type);
        });

        // Only the user's own monitors. Falling back to the whole list put the
        // vantage points on the dashboard of a fresh install, so a fleet with
        // nothing to watch looked like one with nodes to watch (conv-12).
        setMonitors(userTargets);
        setMonitorsError(null);
        setLoadedAt(new Date());
      })
      .catch(() => {
        if (active) setMonitorsError(t('dashboard.monitors_load_error', 'Seznam monitorů se nepodařilo načíst.'));
      })
      .finally(() => {
        if (active) setMonitorsLoading(false);
      });

    return () => {
      active = false;
    };
  }, [session, refreshTick, t]);

  // The network score and every device's own, on the same minute clock.
  const fleetHealth = useFleetHealth(lang, refreshTick);

  // Every count on the page comes from this one pass: the tabs, the tiles
  // and the ring used to filter the list again each, six times per render.
  const counts = React.useMemo(() => {
    const c: Record<MonitorStatus, number> = { up: 0, down: 0, warning: 0, paused: 0, maintenance: 0, unknown: 0 };
    for (const m of monitors) c[m.status]++;
    return c;
  }, [monitors]);
  // The newest measurement in the list - what the freshness pill judges.
  // The fetch time alone would call data "live" that cron wrote an hour ago.
  const newestCheck = monitors.reduce<number | null>((max, m) => {
    const at = m.lastCheck ? Date.parse(m.lastCheck) : NaN;
    return at > (max ?? -Infinity) ? at : max;
  }, null);

  // The header's LIVE pill and refresh button speak for this page's data.
  // Cron writes every 1-5 minutes, so 300 s is the cadence the age is judged
  // by: live up to 10 min, late up to the server's 15-minute collection
  // limit, stale after.
  usePageChrome({
    freshness: {
      at: newestCheck,
      intervalSecs: 300,
      failed: monitorsError !== null,
      okAt: loadedAt?.getTime() ?? null,
    },
    onRefresh: () => setRefreshTick((n) => n + 1),
  });

  const totalMonitors = monitors.length > 0 ? monitors.length : (live?.totalMonitors ?? 0);
  const downMonitors = counts.down;
  // Healthy = actually UP. "Total minus down" counted warnings, paused
  // checks and silent agents as healthy, so the tile said 100 % with a
  // degraded service on the list.
  const healthyCount = counts.up;
  const healthyPct = monitors.length > 0 ? (healthyCount / monitors.length) * 100 : null;
  // null/missing means nobody measured a 30-day uptime (new install, dead
  // cron, unreachable API) - that state renders as "no data". Falling back
  // to a number here would fabricate an SLA, which already happened once
  // with the mock's 100.0 default.
  const uptimeKnown = live?.uptimePercent != null;
  const uptime = live?.uptimePercent ?? 0;
  // The KPI row: a placeholder until the first answer, a dash once the latest
  // request failed. A stale 0 under "Výpadky" is an all-clear nobody measured.
  const kpiLoading = monitorsData === null && monitorsError === null;
  const kpiFailed = monitorsError !== null;
  const uptimeLoading = live === null && liveError === null;
  // Counts only for a list that arrived: "Vše (0)" before the first answer,
  // or after a failed one, was a zero nobody measured (V-18).
  const listKnown = !monitorsLoading && !(monitorsError && monitors.length === 0);
  const count = (n: number): number | string => (listKnown ? n : '—');
  // A fleet that answered with nothing in it: the first run, not an all-clear (conv-12).
  const emptyFleet = monitorsData !== null && monitorsData.length === 0 && monitorsError === null;

  const visibleMonitors = React.useMemo(() => {
    const needle = query.trim().toLowerCase();
    const matched = monitors.filter((m) => {
      const matchesStatus = filter === 'all' || m.status === filter;
      const matchesQuery =
        !needle ||
        m.name.toLowerCase().includes(needle) ||
        (m.target ?? '').toLowerCase().includes(needle) ||
        (m.type ?? '').toLowerCase().includes(needle);
      return matchesStatus && matchesQuery;
    });
    return [...matched].sort((a, b) => {
      const rank = SEVERITY_RANK[a.status] - SEVERITY_RANK[b.status];
      if (rank !== 0) return rank;
      return (b.sinceStatusChangeSeconds ?? 0) - (a.sinceStatusChangeSeconds ?? 0);
    });
  }, [query, filter, monitors]);

  const realAlerts = React.useMemo(() => {
    const alertsList: {
      /** monitors.id, also the /infrastructure/:id link. */
      id: number;
      title: string;
      source: string;
      severity: 'down' | 'warning';
      /** When the state changed; null when the server never recorded a change - never "now". */
      at: string | null;
    }[] = [];
    monitors.forEach((m) => {
      // The state in words, not an emoji: a warning used to be titled
      // "Zvýšená latence" whatever raised it - a disk or a certificate too.
      if (m.status === 'down' || m.status === 'warning') {
        alertsList.push({
          id: m.id,
          title: m.name,
          source: `${m.status === 'down' ? t('common.offline', 'Offline') : t('common.warning', 'Varování')} · ${m.type} · ${m.target}`,
          severity: m.status,
          at: m.lastStatusChange ?? null,
        });
      }
    });

    // Newest change first, and a row whose change time was never recorded goes
    // last rather than to a random place. Unsorted, the timestamps in the
    // right-hand column jumped around and the card read as random.
    return alertsList.sort((a, b) => {
      const ta = a.at ? Date.parse(a.at) : NaN;
      const tb = b.at ? Date.parse(b.at) : NaN;
      if (Number.isNaN(ta) && Number.isNaN(tb)) return 0;
      if (Number.isNaN(ta)) return 1;
      if (Number.isNaN(tb)) return -1;
      return tb - ta;
    });
  }, [monitors, t]);

  // Mini latency trends for the table (mockup: a trend next to the value). One
  // light request per monitor after the list loads; without data there is simply no sparkline.
  const [latencySeries, setLatencySeries] = React.useState<LatencySeries>({ points: {}, window: null });
  // Keyed on the id list, not on the array: with the minute refresh a fresh
  // array would re-fire twelve requests every minute for a six-hour trend.
  const sparklineKey = React.useMemo(
    () =>
      monitors
        .slice(0, 12)
        .map((m) => m.id)
        .join(','),
    [monitors]
  );
  React.useEffect(() => {
    if (sparklineKey === '') return;
    let active = true;
    const targets = sparklineKey.split(',').map((id) => ({ id: Number(id) }));
    Promise.all(
      targets.map((m) =>
        fetch(`/status/api.php?action=metric_series&monitor_id=${m.id}&metric=response_time&period=6h`, {
          credentials: 'include',
        })
          .then((r) => (r.ok ? r.json() : null))
          .then(
            (data) =>
              [
                m.id,
                // Nulls are kept: the sparkline draws a gap where nothing was
                // measured. Filtering them out closed the gap and drew an
                // outage as a smooth line. Timestamps are kept too: the
                // sparkline's x is time, so an outage is as wide as it lasted (C-4).
                Array.isArray(data?.points)
                  ? data.points.map((p: [number, number]) => ({
                      t: p[0] * 1000,
                      v: typeof p[1] === 'number' ? p[1] : null,
                    }))
                  : [],
              ] as const
          )
          .catch(() => [m.id, []] as const)
      )
    ).then((entries) => {
      if (!active) return;
      const map: LatencySeries['points'] = {};
      for (const [id, vals] of entries) map[id] = vals;
      setLatencySeries({ points: map, window: windowFor('6h', Date.now()) });
    });
    return () => {
      active = false;
    };
  }, [sparklineKey]);

  const [dailyUptimeRows, setDailyUptimeRows] = React.useState<Record<number, DayUptime[]>>({});
  const [dailyUptimeError, setDailyUptimeError] = React.useState<string | null>(null);
  // Whether an answer has arrived. A non-2xx answer used to return quietly, so
  // "Načítám historii" spun for ever on a server that had already said no.
  const [dailyUptimeLoaded, setDailyUptimeLoaded] = React.useState(false);
  const [dailyUptimeAttempt, setDailyUptimeAttempt] = React.useState(0);

  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=daily_uptime&days=30&lang=${lang}`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data) => {
        if (!active) return;
        if (
          data?.series == null ||
          typeof data.series !== 'object' ||
          (typeof data.error === 'string' && data.error !== '')
        ) {
          throw new Error('invalid response');
        }
        setDailyUptimeRows(data.series);
        setDailyUptimeError(null);
        setDailyUptimeLoaded(true);
      })
      .catch(() => {
        if (active) setDailyUptimeError(t('dashboard.uptime_load_error', 'Chyba při načítání denní dostupnosti.'));
      });
    return () => {
      active = false;
    };
  }, [t, lang, dailyUptimeAttempt]);

  const liveUptimeHistory = React.useMemo<UptimeHistoryRow[]>(() => {
    if (monitors.length === 0) return [];
    // Every monitor, not the first six by id: splitHistory picks the rows
    // worth drawing, worst first (charts-16).
    return monitors.map((m) => {
      const dbDays = dailyUptimeRows[m.id];
      if (dbDays && dbDays.length > 0) {
        return { monitorId: m.id, name: m.name, days: dbDays };
      }
      // No history from the API yet for this monitor (e.g. it was added after
      // the last fetch) - show real "no data" days instead of fabricating an
      // all-up history, which would misrepresent actual availability.
      const days = [];
      const today = new Date();
      for (let i = 29; i >= 0; i--) {
        const d = new Date(today);
        d.setDate(d.getDate() - i);
        // In the page's language: an English page printed Czech dates here.
        const dateStr = d.toLocaleDateString(localeFor(lang), { day: 'numeric', month: 'numeric' });
        // Never measured is not the same as switched off - the legend used to
        // label these days "Pozastaveno".
        days.push({ date: dateStr, status: 'nodata' as const, uptimePct: null });
      }
      return { monitorId: m.id, name: m.name, days };
    });
  }, [monitors, dailyUptimeRows, lang]);
  const history = React.useMemo(() => splitHistory(liveUptimeHistory), [liveUptimeHistory]);

  // The "Needs attention" section - logic and thresholds in lib/attention.ts, so it
  // can be tested without a render (see attention.test.ts).
  const needsAttention = React.useMemo(
    () =>
      buildNeedsAttention(
        monitors,
        {
          down: t('attention.down', 'Služba je nedostupná'),
          warning: t('attention.warning', 'Monitor hlásí varování'),
          silent: t('attention.silent', 'Agent přestal hlásit data'),
          unreachable: t('attention.unreachable', 'Cíl je trvale nedosažitelný — zvažte kontrolu agentem'),
          sslExpired: t('attention.ssl_expired', 'SSL certifikát vypršel!'),
          sslExpiring: (days) => t('attention.ssl_expiring', { days }, `SSL certifikát vyprší za ${days} dní`),
          agentUpdate: (version) =>
            t('attention.agent_update', { version }, `Agent je zastaralý — k dispozici je verze ${version}`),
          metricHigh: (metric, value) => t('attention.metric_high', { metric, value }, `${metric} na ${value} %`),
        },
        { sslAlertDays }
      ),
    [monitors, sslAlertDays, t]
  );
  // What the panel counts: problems only, like the bell and the tab bar. An
  // agent update is listed, but it is not a fault (CORR-4).
  const problemCount = needsAttention.filter((item) => item.severity !== 'info').length;
  // The fleet's states in one sentence over the ring ("1 výpadek, 2 varování"),
  // in the shared status vocabulary; the attention list beside it names the
  // devices and every other finding.
  const verdict = React.useMemo(() => {
    const byState = statusCounts(monitors);
    return { text: verdictSentence(byState, lang, t), worst: worstNamedState(byState) };
  }, [monitors, lang, t]);

  // Routers and servers get their own cards, the worst state first so a
  // failing device is never the ninth card.
  const devices = React.useMemo(
    () =>
      monitors.filter(isDeviceMonitor).sort((a, b) => SEVERITY_RANK[a.status] - SEVERITY_RANK[b.status] || a.id - b.id),
    [monitors]
  );

  // Whose series the traffic card draws: the first router's WAN throughput,
  // else the response time of the first monitor that measures one. The lowest
  // id, so the card does not jump to another device on the minute refresh.
  const trafficSource = React.useMemo<(TrafficSource & { gatewayLatencyMs: number | null }) | null>(() => {
    const byId = [...monitors].sort((a, b) => a.id - b.id);
    const router = byId.find((m) => normalizeMonitorType(m.type) === 'openwrt');
    if (router) {
      const gw = router.details?.wan_latency_ms;
      return {
        id: router.id,
        name: router.name,
        metric: 'net',
        gatewayLatencyMs: typeof gw === 'number' && Number.isFinite(gw) ? gw : null,
      };
    }
    const probe = byId.find((m) => m.responseMs != null);
    return probe ? { id: probe.id, name: probe.name, metric: 'response_time', gatewayLatencyMs: null } : null;
  }, [monitors]);

  // --- Dashboard sections as named blocks ------------------------------
  // The stored layout drives their order and visibility; without a stored
  // layout the default (NetPulse overview) order renders.

  const heroSection = (
    // The answer to "how is my network", first: the monitors' states in one
    // sentence, the server's health score with what it is made of and what
    // took points off, and the four fleet figures.
    <Panel key="hero" className="relative h-full" bodyClassName="flex flex-col items-center gap-4 text-center">
      <Button
        variant="ghost"
        size="icon"
        onClick={() => setLayoutOpen(true)}
        aria-label={t('dashboard.customize', 'Upravit rozložení')}
        title={t('dashboard.customize', 'Upravit rozložení')}
        className="absolute top-3 right-3"
      >
        <LayoutGrid className="size-4" aria-hidden="true" />
      </Button>
      <FleetVerdict
        text={verdict.text}
        worst={verdict.worst}
        loading={monitorsLoading}
        // A failed refresh leaves an old list: a sentence from it would be a
        // verdict nobody measured, so it says the state is unknown.
        failed={monitorsError !== null}
      />
      <NetworkHealth state={fleetHealth} />
      <StatRow cols={4} className="border-border w-full border-t pt-4 text-left">
        <StatBlock
          variant="plain"
          size="sm"
          label={t('dashboard.total_monitors', 'Monitorů celkem')}
          value={kpiFailed ? null : totalMonitors}
          loading={kpiLoading}
          hint={kpiFailed ? undefined : t('dashboard.monitors_hint', { healthy: healthyCount, down: downMonitors })}
        />
        <StatBlock
          variant="plain"
          size="sm"
          label={t('dashboard.healthy_pct', 'Zdravých')}
          value={kpiFailed || healthyPct == null ? null : formatPercent(healthyPct, 0, lang)}
          loading={kpiLoading}
          // One tile kind (C-1): the figure takes a status colour only past a
          // limit - 40 % healthy used to look as reassuring as 100 %.
          tone={kpiFailed || healthyPct == null || healthyPct >= 100 ? null : downMonitors > 0 ? 'down' : 'warning'}
          hint={
            kpiFailed ? undefined : t('dashboard.healthy_of_total', { healthy: healthyCount, total: monitors.length })
          }
        />
        <StatBlock
          variant="plain"
          size="sm"
          label={t('dashboard.outages', 'Výpadky')}
          value={kpiFailed ? null : downMonitors}
          loading={kpiLoading}
          tone={!kpiFailed && downMonitors > 0 ? 'down' : null}
          hint={
            kpiFailed
              ? t('dashboard.kpi_unknown', 'Stav nelze zjistit')
              : downMonitors > 0
                ? t('dashboard.ongoing_outage', 'Probíhající výpadek')
                : t('dashboard.no_outages', 'Všechny systémy bez výpadku')
          }
        />
        <StatBlock
          variant="plain"
          size="sm"
          label={t('dashboard.uptime_30d', 'Uptime (30 d)')}
          // In the page's language (CR-13): a Czech page printed "99.99".
          value={uptimeKnown ? formatPercentValue(uptime, 2, lang) : null}
          secondary="%"
          loading={uptimeLoading}
          tone={!uptimeKnown || uptime >= 99.9 ? null : uptime >= 99 ? 'warning' : 'down'}
          hint={
            live === null && liveError !== null
              ? t('dashboard.kpi_unknown', 'Stav nelze zjistit')
              : !uptimeKnown
                ? t('dashboard.uptime_pending', 'Zatím žádná data za 30 dní')
                : live && live.avgLatencyMs != null
                  ? `${t('dashboard.avg_response', 'Průměrná odezva')} ${formatMs(live.avgLatencyMs)}`
                  : t('dashboard.whole_infra', 'Celá infrastruktura')
          }
        />
      </StatRow>
    </Panel>
  );

  const trafficSection = trafficSource ? (
    <React.Suspense
      key="traffic"
      fallback={
        <Panel className="h-full" aria-busy="true">
          <Skeleton className="h-64 w-full" />
        </Panel>
      }
    >
      <DashboardTraffic source={trafficSource} gatewayLatencyMs={trafficSource.gatewayLatencyMs} />
    </React.Suspense>
  ) : monitorsLoading ? (
    // Hold the slot until the list says whether there is a series to draw.
    <Panel key="traffic" className="h-full" aria-busy="true">
      <Skeleton className="h-64 w-full" />
    </Panel>
  ) : null;

  const attentionSection = (
    // The answer to "is anything wrong right now": every outage, warning,
    // expiring certificate and silent agent, each with how long it has been
    // so where the server measured it.
    <Panel
      key="attention"
      // The check mark only for a measured all-clear, not while loading (V-18).
      icon={!monitorsLoading && !monitorsError && problemCount === 0 ? CheckCircle2 : TriangleAlert}
      title={t('attention.title', 'Vyžaduje pozornost')}
      // A failed refresh leaves an old list: its count would be an all-clear nobody measured.
      count={monitorsLoading || monitorsError ? undefined : problemCount}
      viewAll={{ to: '/incidents', label: t('nav.incidents', 'Incidenty') }}
      padding="sm"
    >
      {monitorsLoading ? (
        <LoadingState size="inline" label={t('dashboard.loading_monitors', 'Načítám monitory…')} />
      ) : monitorsError && monitors.length === 0 ? (
        <p className="text-muted-foreground px-3 py-3 text-sm">
          {t('attention.unknown', 'Stav nelze zjistit — seznam monitorů se nenačetl.')}
        </p>
      ) : needsAttention.length === 0 && monitorsError ? (
        // Stale list after a failed refresh: no problems in it is not "all clear".
        <p className="text-muted-foreground px-3 py-3 text-sm">
          {t('dashboard.refresh_failed', 'Obnovení selhalo, data mohou být zastaralá')}
        </p>
      ) : needsAttention.length === 0 ? (
        <p className="text-muted-foreground flex items-center gap-2 px-3 py-3 text-sm">
          <StatusDot variant="up" />
          {t('attention.all_clear', 'Nic nevyžaduje pozornost — vše v normálu.')}
        </p>
      ) : (
        <ListRows>
          {needsAttention.map((item) => {
            // Only a state change has a start the server measured (a silent
            // agent's too: its change to unknown); an expiring certificate or
            // a full disk gets no invented "since".
            const since = /^(down|warn|silent)-/.test(item.key)
              ? monitors.find((m) => m.id === item.monitorId)?.sinceStatusChangeSeconds
              : null;
            return (
              <ListRow
                key={item.key}
                to={`/infrastructure/${item.monitorId}`}
                icon={item.severity === 'down' ? CircleX : item.severity === 'warning' ? TriangleAlert : Info}
                iconTone={item.severity}
                // The edge only for a problem; an agent update is worth knowing, not a fault.
                highlight={item.severity === 'info' ? null : item.severity}
                title={item.name}
                subtitle={item.text}
                meta={since == null ? undefined : ageText(since)}
              />
            );
          })}
        </ListRows>
      )}
    </Panel>
  );

  const monitorsSection = (
    <Panel
      key="monitors"
      icon={LayoutList}
      title={t('dashboard.monitors_card_title', 'Sledované Monitory & Služby')}
      viewAll={{ to: '/infrastructure', label: t('nav.infrastructure', 'Infrastruktura') }}
      action={
        <div className="relative w-full sm:w-56">
          <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('dashboard.search_placeholder', 'Hledat monitory…')}
            aria-label={t('dashboard.search_placeholder', 'Hledat monitory…')}
            className="h-8 pl-8 text-xs"
          />
        </div>
      }
      padding="none"
    >
      <Tabs value={filter} onValueChange={(v) => setFilter(v as StatusFilter)}>
        {/* Six tabs are wider than a phone. The strip scrolls on its own
            instead of stretching the card, which pushed each card's status
            past the screen edge (W1-D3). The wrapper scrolls, not the list,
            so the active tab's underline is not clipped at its border. */}
        <div className="mx-5 overflow-x-auto">
          <TabsList className="mb-0 whitespace-nowrap">
            {(
              [
                ['all', t('common.all', 'Vše'), monitors.length],
                // The same words as the status column (C-11): a tab "Offline"
                // over rows reading "Výpadek" named one state twice.
                ['up', statusLabel('up', t), counts.up],
                ['warning', statusLabel('warning', t), counts.warning],
                ['down', statusLabel('down', t), counts.down],
                ['paused', statusLabel('paused', t), counts.paused],
                // Silent agents had no tab - they were invisible in every filter but "all".
                ['unknown', t('status.unknown', 'Neznámý'), counts.unknown],
              ] as const
            )
              .filter(([key, , n]) => key !== 'unknown' || n > 0 || filter === 'unknown')
              .map(([key, label, n]) => (
                <TabsTrigger key={key} value={key}>
                  {/* "Vše (0)" before the list arrived is a zero nobody measured (V-18). */}
                  {label} ({count(n)})
                </TabsTrigger>
              ))}
          </TabsList>
        </div>

        <TabsContent value={filter} className="mt-0">
          {monitorsError && monitors.length === 0 ? (
            <ErrorState message={monitorsError} className="m-4" />
          ) : monitorsLoading ? (
            <LoadingState label={t('dashboard.loading_monitors', 'Načítám monitory…')} />
          ) : (
            <>
              {/* A failed minute refresh keeps the last good list on screen and
                  says it is stale - it used to replace the whole table. */}
              {monitorsError && (
                <ErrorState
                  tone="warning"
                  message={`${t('dashboard.refresh_failed', 'Obnovení selhalo, data mohou být zastaralá')} — ${monitorsError}`}
                  className="mx-5 mt-3"
                />
              )}
              <MonitorTable rows={visibleMonitors} latencySeries={latencySeries} />
            </>
          )}
        </TabsContent>
      </Tabs>

      {/* No "Zobrazeno 0 z 0" for a list that never arrived (V-18). */}
      {listKnown && (
        <p className="text-muted-foreground border-border border-t px-5 py-3 text-xs">
          {t('dashboard.showing', { shown: visibleMonitors.length, total: monitors.length })}
        </p>
      )}
    </Panel>
  );

  const alertsSection = (
    // Named for what it holds: monitors that are down or degraded RIGHT NOW,
    // newest change first. It never contained history - a service that failed
    // and recovered an hour ago was never in it.
    <Panel
      key="alerts"
      icon={TriangleAlert}
      title={t('dashboard.active_alerts', 'Aktivní výstrahy')}
      count={monitorsLoading ? undefined : realAlerts.length}
      viewAll={{ to: '/incidents', label: t('nav.incidents', 'Incidenty') }}
      padding="sm"
    >
      {realAlerts.length === 0 ? (
        <p className="text-muted-foreground flex items-center gap-2 px-3 py-4 text-sm">
          {monitorsLoading ? (
            t('dashboard.loading_monitors', 'Načítám monitory…')
          ) : monitorsError ? (
            t('dashboard.alerts_unknown', 'Stav výstrah nelze zjistit — seznam monitorů se nenačetl.')
          ) : (
            <>
              <StatusDot variant="up" />
              {t('dashboard.no_active_alerts', 'Žádné aktivní výstrahy — všechny sledované služby jsou online.')}
            </>
          )}
        </p>
      ) : (
        <ListRows>
          {realAlerts.map((alert) => (
            <ListRow
              key={alert.id}
              to={`/infrastructure/${alert.id}`}
              icon={alert.severity === 'down' ? CircleX : TriangleAlert}
              iconTone={alert.severity}
              highlight={alert.severity}
              title={alert.title}
              subtitle={alert.source}
              meta={alert.at ? formatRelative(alert.at, lang) : '—'}
            />
          ))}
        </ListRows>
      )}
    </Panel>
  );

  const healthSection = (
    // The shape of the fleet by state. Titled for that ("Stavy monitorů"):
    // the health score is the ring above, and two "health" cards with
    // different numbers would contradict each other.
    <Panel key="health" icon={PieChart} title={t('dashboard.infra_health', 'Stavy monitorů')} className="self-start">
      {/* No bar before the list arrived or after it failed: an empty bar
          with "Offline 0" is an all-clear drawn from no data. */}
      {monitorsData === null ? (
        monitorsError !== null ? (
          <ErrorState message={monitorsError} onRetry={() => setRefreshTick((n) => n + 1)} />
        ) : (
          <LoadingState size="inline" label={t('dashboard.loading_monitors', 'Načítám monitory…')} />
        )
      ) : (
        <HealthDonut
          centerLabel={{
            // No monitors = nothing measured. It used to print "0 %".
            value: healthyPct == null ? '—' : formatPercent(healthyPct, 0, lang),
            caption: t('dashboard.healthy_pct', 'Zdravých'),
          }}
          // Each status leads to the device list narrowed to it - the ring
          // named a problem and offered no way to reach it.
          hrefFor={(segment) => `/infrastructure?status=${segment.variant}`}
          segments={[
            { label: t('common.online', 'Online'), value: counts.up, variant: 'up' },
            { label: t('common.warning', 'Varování'), value: counts.warning, variant: 'warning' },
            { label: t('common.offline', 'Offline'), value: counts.down, variant: 'down' },
            { label: t('common.paused', 'Pozastaveno'), value: counts.paused, variant: 'paused' },
            { label: t('common.maintenance', 'Údržba'), value: counts.maintenance, variant: 'maintenance' },
            { label: t('status.unknown', 'Neznámý'), value: counts.unknown, variant: 'unknown' },
          ]}
        />
      )}
    </Panel>
  );

  // The few worst findings (C-12) from the one server feed. The attention
  // list above already names outages, certificates, metrics and agents, so
  // this row keeps to what only the feed knows - trends and router advice -
  // and takes no room when there is none. Lazy: most visits never scroll here.
  const insightsSection = (
    <React.Suspense key="insights" fallback={null}>
      <FindingsList density="top" sources={['insight', 'router']} />
    </React.Suspense>
  );

  const devicesSection =
    devices.length > 0 ? (
      <DevicesSection
        key="devices"
        devices={devices.slice(0, DEVICE_CARDS)}
        total={devices.length}
        health={fleetHealth.data?.assets ?? null}
        healthLoading={fleetHealth.status === 'loading'}
      />
    ) : null;

  const uptimeSection = (
    <Panel
      key="uptime_history"
      icon={CalendarDays}
      title={t('dashboard.availability_history', 'Historie dostupnosti sledovaných služeb')}
      hint={t('dashboard.availability_30d', 'Sledovaná dostupnost v čase (posledních 30 dní)')}
      viewAll={{ to: '/reports', label: t('dashboard.full_report', 'Celý report') }}
      // The day cells open a popover over the next section.
      className="relative z-20 overflow-visible"
      bodyClassName="overflow-visible"
    >
      {dailyUptimeError ? (
        <ErrorState
          message={dailyUptimeError}
          onRetry={() => {
            setDailyUptimeError(null);
            setDailyUptimeAttempt((n) => n + 1);
          }}
        />
      ) : monitorsData === null && monitorsError !== null ? (
        <ErrorState message={monitorsError} onRetry={() => setRefreshTick((n) => n + 1)} />
      ) : monitorsData === null || !dailyUptimeLoaded ? (
        <LoadingState label={t('dashboard.loading_uptime', 'Načítám historii dostupnosti…')} />
      ) : liveUptimeHistory.length === 0 ? (
        <EmptyState title={t('dashboard.uptime_no_monitors', 'Zatím nesledujete žádnou službu.')} />
      ) : (
        <div className="space-y-3">
          {history.shown.length > 0 && (
            <React.Suspense
              fallback={<LoadingState label={t('dashboard.loading_uptime', 'Načítám historii dostupnosti…')} />}
            >
              <UptimeHeatmap rows={history.shown} />
            </React.Suspense>
          )}
          <HistoryFootnote
            clean={history.clean}
            moreProblems={history.moreProblems}
            days={liveUptimeHistory[0]?.days.length ?? 30}
            allClean={history.shown.length === 0}
          />
        </div>
      )}
    </Panel>
  );

  // Aggregated metric tiles (catalogue: metric_cpu/ram/hdd) - the highest
  // value across agents; without a single measurement the tile does not render.
  const metricTile = (key: string) => {
    // The key is either aggregated ("metric_cpu" = the highest value across
    // machines) or bound to one monitor ("metric_cpu_12").
    const parts = key.split('_');
    const field = parts[1] === 'cpu' ? 'cpu' : parts[1] === 'ram' ? 'ram' : 'hdd';
    const label = parts[1] === 'cpu' ? 'CPU' : parts[1] === 'ram' ? 'RAM' : 'Disk';
    const monitorId = parts.length > 2 ? Number(parts[2]) : null;

    const pool = monitorId != null ? monitors.filter((m) => m.id === monitorId) : monitors;
    const reporting = pool.filter((m) => typeof m[field] === 'number');
    // Without a single measurement the tile is not drawn - better than an empty card.
    if (reporting.length === 0) return null;

    const worst = reporting.reduce((a, b) => ((a[field] ?? 0) >= (b[field] ?? 0) ? a : b));
    const value = worst[field] as number;
    return (
      <StatBlock
        variant="card"
        label={
          monitorId != null ? `${label} — ${worst.name}` : t('dashboard.metric_worst', { label }, `Nejvyšší ${label}`)
        }
        value={formatPercent(value, 0, lang)}
        // Coloured only past the monitor's own limit (C-1, CR-8).
        tone={breachTone(
          metricSeverity(value, thresholdFor(worst, field === 'cpu' ? 'cpu' : field === 'ram' ? 'ram' : 'hdd'))
        )}
        hint={monitorId != null ? undefined : worst.name}
      />
    );
  };

  // Rendered in the stored order in a two-column grid, where the width from
  // the editor decides whether a tile takes one column ("normal") or both
  // ("wide"). The width used to be stored and read by nobody - the switch in
  // the editor did nothing.
  const orderedLayout = () => {
    const sectionFor = (key: string): React.ReactNode => {
      if (key === 'attention') return attentionSection;
      if (key === 'monitors') return monitorsSection;
      if (key === 'alerts') return alertsSection;
      if (key === 'health') return healthSection;
      if (key === 'insights') return insightsSection;
      if (key === 'uptime_history') return uptimeSection;
      if (key === 'regions')
        return (
          <React.Suspense fallback={null}>
            <RegionsPanel />
          </React.Suspense>
        );
      if (key.startsWith('metric_')) return metricTile(key);
      return null;
    };

    const rendered = tiles
      .filter((tl) => tl.visible)
      .map((tl) => {
        const wide = tl.size === 'wide';
        const node = sectionFor(tl.key);
        if (!node) return null;
        return (
          <div key={tl.key} className={cn('min-w-0', wide && 'md:col-span-2')}>
            {node}
          </div>
        );
      })
      .filter(Boolean);

    return <div className="grid gap-4 md:grid-cols-2">{rendered}</div>;
  };

  return (
    <div className="flex flex-col gap-6">
      {/* The page's one heading. The shell's header already shows the page
          name, so it is not drawn twice - but a screen reader's heading list
          still starts here (kit rule 1). */}
      <h1 className="sr-only">{t('dashboard.title', 'Přehled stavu')}</h1>

      {layoutOpen && (
        <React.Suspense fallback={null}>
          <DashboardLayoutEditor open onClose={() => setLayoutOpen(false)} onSaved={(next) => setTiles(next)} />
        </React.Suspense>
      )}

      <DataSourceBanner />

      {/* Louder than any single monitor: when cron stops, every number below is
          stale and the page would otherwise look calm. */}
      <CollectorHealthBanner />

      {/* The site's own faults nothing else reports, e.g. a hand-edited
          config.php that prints bytes. Admin only. */}
      <SiteHealthBanner />

      {/* Just as loud: an alert that no longer reaches anybody is an outage
          nobody hears about. Admin only. */}
      <NotificationHealthBanner />

      <CollectionIssuesBanner monitors={monitors} />

      {emptyFleet ? (
        <FirstRunCard />
      ) : (
        <>
          {kpiFailed && (
            <ErrorState
              message={t(
                'dashboard.kpi_failed',
                'Souhrnná čísla nejsou k dispozici - seznam monitorů se nepodařilo načíst.'
              )}
              onRetry={() => setRefreshTick((n) => n + 1)}
            />
          )}
          {/* The hero row stays in every layout, where the KPI row used to be.
              min-w-0 on every grid child: a grid item is never narrower than
              its content by default, so one wide child made the page scroll
              sideways. */}
          <div className={cn('grid gap-6 *:min-w-0', trafficSection && 'xl:grid-cols-2')}>
            {heroSection}
            {trafficSection}
          </div>

          {tiles.length === 0 ? (
            <>
              {/* What is wrong now, beside the shape of the whole fleet; then
                  the devices, the full list and the history. The separate
                  "Aktivní výstrahy" card is left to custom layouts: it listed
                  the same outages as this one, a screen apart. */}
              <div className="grid gap-6 *:min-w-0 xl:grid-cols-3">
                <div className="flex flex-col gap-6 xl:col-span-2">
                  {attentionSection}
                  {insightsSection}
                </div>
                {healthSection}
              </div>
              {devicesSection}
              {monitorsSection}
              {uptimeSection}
            </>
          ) : (
            orderedLayout()
          )}
        </>
      )}
    </div>
  );
}

/**
 * The hero's headline: the icon of the worst state it names and the sentence
 * from lib/fleet-verdict. One line of the same height while loading, so the
 * ring does not move when the list arrives (PA-R1).
 */
function FleetVerdict({
  text,
  worst,
  loading,
  failed,
}: {
  text: string;
  worst: StatusKey | null;
  loading: boolean;
  failed: boolean;
}) {
  const { t } = useLanguage();
  const line = 'flex items-center justify-center gap-2 text-lg font-semibold tracking-tight sm:text-xl';
  if (loading) {
    return (
      <div
        data-testid="fleet-verdict-sentence"
        data-state="loading"
        aria-hidden="true"
        className="flex h-7 items-center px-8"
      >
        <Skeleton className="h-6 w-56 max-w-full" />
      </div>
    );
  }
  if (failed) {
    return (
      <p data-testid="fleet-verdict-sentence" data-state="unknown" className={cn(line, 'text-muted-foreground px-8')}>
        <CloudOff aria-hidden="true" className="size-5 shrink-0" />
        {t('dashboard.kpi_unknown', 'Stav nelze zjistit')}
      </p>
    );
  }
  const meta = statusMeta(worst ?? 'up');
  const Icon = meta.icon;
  return (
    <p data-testid="fleet-verdict-sentence" data-state={worst ?? 'clear'} className={cn(line, 'px-8')}>
      <Icon aria-hidden="true" className={cn('size-5 shrink-0', STATUS_TEXT[meta.variant])} />
      {text}
    </p>
  );
}

/**
 * The network's health ring (owner decision): the server's score, the points
 * of each component, and the named deductions. A failed request is an error
 * with a retry, never a "—" ring - "—" is the server saying it had too little
 * measured data.
 */
function NetworkHealth({ state }: { state: ReturnType<typeof useFleetHealth> }) {
  const { t } = useLanguage();
  if (state.status === 'loading') {
    return (
      // The shape of an answer with a score: ring, grade pill, caption and the
      // one-line breakdown, so the answer does not push the page down.
      <div aria-busy="true" className="flex w-full flex-col items-center gap-4">
        <div className="flex flex-col items-center gap-2">
          <Skeleton className="size-44 rounded-full" />
          <Skeleton className="h-6 w-20 rounded-full" />
          <Skeleton className="h-4 w-24" />
        </div>
        <Skeleton className="h-9 w-full max-w-sm" />
        <span className="sr-only">{t('dashboard.health_loading', 'Počítám skóre zdraví…')}</span>
      </div>
    );
  }
  if (state.data === null) {
    return (
      <ErrorState
        className="w-full text-left"
        message={t('dashboard.health_failed', 'Skóre zdraví se nepodařilo načíst.')}
        onRetry={state.retry}
      />
    );
  }
  const net = state.data.network;
  const scored = net.assetsScored ?? null;
  const total = net.assetsTotal ?? null;
  return (
    <div className="flex w-full flex-col items-center gap-4">
      <HealthRing
        score={net.score}
        grade={net.grade}
        size="lg"
        caption={t('dashboard.network_health', 'Zdraví sítě')}
      />
      {net.score != null && <HealthBreakdown components={net.components} variant="compact" />}
      {/* How many devices the number stands for, when not all of them: a
          score of three out of five is not the network's whole story. */}
      {scored != null && total != null && scored < total && (
        <p className="text-muted-foreground text-2xs">
          {t('dashboard.health_scored', { scored, total }, `Hodnoceno ${scored} z ${total} zařízení`)}
        </p>
      )}
      {net.score != null && (
        <HealthDeductions deductions={net.deductions} linkDevices className="w-full max-w-lg text-left" />
      )}
      {state.status === 'failed' && (
        <ErrorState
          tone="warning"
          className="w-full text-left"
          message={t('dashboard.health_refresh_failed', 'Obnovení skóre selhalo, ukazuje se poslední známé.')}
          onRetry={state.retry}
        />
      )}
    </div>
  );
}

const PILL: Record<ReturnType<typeof statusMeta>['variant'], PillTone> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  info: 'info',
  paused: 'paused',
  neutral: 'neutral',
};

/** How bad a state is, for the order of the section's state chips: the worst one first. */
const CHIP_RANK: Record<StatusKey, number> = {
  down: 0,
  unknown_stale: 1,
  warning: 2,
  maintenance: 3,
  paused: 4,
  unknown_new: 5,
  up: 6,
};

/**
 * "Routery a servery": the routers and servers as cards under a heading whose
 * chips count them by state - the state's icon and the number, worst first,
 * the state's name for a screen reader and on hover.
 */
function DevicesSection({
  devices,
  total,
  health,
  healthLoading,
}: {
  devices: ApiMonitor[];
  total: number;
  health: Parameters<typeof DeviceCards>[0]['health'];
  healthLoading: boolean;
}) {
  const { t } = useLanguage();
  const headingId = React.useId();
  const byKey = new Map<StatusKey, number>();
  for (const m of devices) {
    const key = monitorStatusKey(m);
    byKey.set(key, (byKey.get(key) ?? 0) + 1);
  }
  const keys = [...byKey.keys()].sort((a, b) => CHIP_RANK[a] - CHIP_RANK[b]);
  return (
    <section aria-labelledby={headingId} className="flex flex-col gap-3">
      <SectionTitle
        headingId={headingId}
        title={t('dashboard.devices_title', 'Routery a servery')}
        count={total}
        chip={
          keys.length > 0 ? (
            <span data-testid="devices-state-counts" className="flex flex-wrap items-center gap-1.5">
              {keys.map((k) => {
                const meta = statusMeta(k);
                const Icon = meta.icon;
                const label = `${statusLabel(k, t)}: ${byKey.get(k)}`;
                return (
                  <Pill key={k} tone={PILL[meta.variant]} title={label} className={cn(meta.dashed && 'border-dashed')}>
                    <Icon aria-hidden="true" className="size-3 shrink-0" />
                    <span aria-hidden="true">{byKey.get(k)}</span>
                    <span className="sr-only">{label}</span>
                  </Pill>
                );
              })}
            </span>
          ) : undefined
        }
        viewAll={{ to: '/infrastructure' }}
      />
      <DeviceCards devices={devices} health={health} healthLoading={healthLoading} />
    </section>
  );
}

const typeIcon: Record<string, LucideIcon> = {
  web: Globe,
  http: Globe,
  https: Globe,
  teamspeak: Mic,
  minecraft: Gamepad2,
  discord: MessageSquare,
  openwrt: RouterIcon,
  vps: Server,
  cpanel: Server,
  port: Server,
  dns: Server,
  agent_service: Radar,
};

/** Six-hour response-time traces for the table, and the window they cover. */
interface LatencySeries {
  points: Record<number, { t: number; v: number | null }[]>;
  window: { from: number; to: number } | null;
}

/**
 * The status word's colour, shared by the phone card and the table row: the
 * two had their own ternaries and a warning was amber in one, grey in the
 * other. Only a problem is coloured; a pause or a new monitor stays grey.
 */
const STATUS_TEXT: Record<ReturnType<typeof statusMeta>['variant'], string> = {
  up: 'text-up',
  warning: 'text-warning',
  down: 'text-down',
  info: 'text-info',
  paused: 'text-muted-foreground',
  neutral: 'text-muted-foreground',
};

/**
 * Dot and word from the shared status vocabulary (C-11): a silent agent reads
 * "Agent mlčí" in the warning tone and a monitor still waiting for its first
 * check reads as such, where both used to be one grey "Neznámý".
 */
function StatusWord({ monitor }: { monitor: ApiMonitor }) {
  const { t } = useLanguage();
  const key = monitorStatusKey(monitor);
  const meta = statusMeta(key);
  return (
    <span className="flex shrink-0 items-center gap-1.5 text-xs font-semibold whitespace-nowrap">
      <StatusDot variant={meta.variant} />
      <span className={STATUS_TEXT[meta.variant]}>{statusLabel(key, t)}</span>
    </span>
  );
}

/** Figures in the table: mono, tabular, and never broken over two lines ("48 / ms"). */
const NUM = 'px-2 font-mono text-xs tabular-nums whitespace-nowrap';

function MonitorTable({ rows, latencySeries }: { rows: ApiMonitor[]; latencySeries: LatencySeries }) {
  const { t, lang } = useLanguage();

  if (rows.length === 0) {
    return <EmptyState title={t('dashboard.no_monitors', 'Žádný monitor neodpovídá filtru.')} />;
  }

  return (
    <>
      {/* Mobile: cards instead of the table - 8 columns cannot be read on a
          390 px screen even with a horizontal scroll. */}
      <div className="flex flex-col gap-2 px-4 pt-3 pb-4 md:hidden">
        {nestUnderAgents(rows).map(({ row: monitor, child }) => {
          const usage = processUsage(monitor, rows);
          return (
            <Link
              key={monitor.id}
              to={`/infrastructure/${monitor.id}`}
              className={cn(
                'focus-visible:ring-ring bg-inset rounded-lg border border-border p-3 transition-colors hover:border-border-strong focus-visible:ring-2 focus-visible:outline-none',
                child && 'ml-5'
              )}
            >
              {/* The status comes first: it is what the card is for, and a
                  long name used to truncate it off a 390 px screen (W1-D3). */}
              <div className="flex min-w-0 items-center gap-2">
                {child && (
                  <span aria-hidden="true" className="text-muted-foreground/60 shrink-0 font-mono text-xs">
                    └
                  </span>
                )}
                <StatusWord monitor={monitor} />
                <span className="min-w-0 truncate text-sm font-semibold">{monitor.name}</span>
              </div>
              <div className="text-muted-foreground mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 font-mono text-xs tabular-nums">
                <span>{monitor.responseMs != null ? formatMs(monitor.responseMs) : '—'}</span>
                {usage.cpu != null && <span>CPU {formatPercent(usage.cpu, 0, lang)}</span>}
                {usage.ram != null && (
                  <span>
                    RAM{' '}
                    {(monitor.type || '').toLowerCase() === 'agent_service'
                      ? `${usage.ram} MB`
                      : formatPercent(usage.ram, 0, lang)}
                  </span>
                )}
                {monitor.hdd != null && <span>HDD {formatPercent(monitor.hdd, 0, lang)}</span>}
                {monitor.lastCheck && <span>{formatRelative(monitor.lastCheck, lang)}</span>}
              </div>
            </Link>
          );
        })}
      </div>

      {/* No overflow-x-auto here and none on the panel body above: the Table
          primitive brings its own scroll box, focusable and labelled, and
          three boxes inside one another meant the outer two never knew the
          table was wider than the card - only the innermost clipped it. The
          eight columns fit a 1440 card since the monitor column stopped
          taking its full content width. */}
      <div className="hidden md:block">
        <Table aria-label={t('dashboard.monitors_card_title', 'Sledované Monitory & Služby')}>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-5">{t('dashboard.col_monitor_name', 'Monitor')}</TableHead>
              <TableHead className="px-2">{t('common.status', 'Stav')}</TableHead>
              <TableHead className="px-2">{t('common.response', 'Odezva')}</TableHead>
              <TableHead className="px-2">CPU</TableHead>
              <TableHead className="px-2">RAM</TableHead>
              <TableHead className="px-2">HDD</TableHead>
              {/* Time in the current state - for a down monitor that is the
                  outage, so "Uptime" was the wrong word for half the rows. */}
              <TableHead className="px-2">{t('dashboard.col_in_state', 'Ve stavu')}</TableHead>
              {/* The one header allowed to wrap: two words on two lines cost 40 px
                  of column, which is what pushed the values off the card edge. */}
              <TableHead className="pr-5 pl-2 whitespace-normal">
                {t('common.last_check', 'Poslední kontrola')}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {nestUnderAgents(rows).map(({ row: monitor, child }) => (
              <TableRow key={monitor.id}>
                <TableCell className={child ? 'pl-10' : 'pl-5'}>
                  <div className="flex items-center gap-2.5">
                    {child && <span className="text-muted-foreground/60 -ml-4 shrink-0 font-mono text-xs">└</span>}
                    {(() => {
                      const tkey = (monitor.type || '').toLowerCase();
                      const Icon = typeIcon[tkey] ?? Server;
                      return (
                        // The kind of a monitor is a category, not a verdict: one
                        // neutral tile for all of them, the word does the telling.
                        <span className="bg-inset text-muted-foreground grid size-8 shrink-0 place-items-center rounded-lg border border-border">
                          <Icon className="size-4" aria-hidden="true" />
                        </span>
                      );
                    })()}
                    <div className="leading-tight min-w-0 max-w-64">
                      <Link
                        to={`/infrastructure/${monitor.id}`}
                        className="block truncate font-medium hover:underline text-foreground"
                        title={monitor.name}
                      >
                        {monitor.name}
                      </Link>
                      <p
                        className="text-muted-foreground text-xs truncate"
                        title={`${monitor.type} · ${monitor.target}`}
                      >
                        {monitor.type} · {monitor.target}
                      </p>
                    </div>
                  </div>
                </TableCell>
                <TableCell className="px-2">
                  {/* Status as coloured text with a dot, not a pill badge. */}
                  <StatusWord monitor={monitor} />
                </TableCell>
                <TableCell className={NUM}>
                  <div className="flex items-center gap-1.5">
                    <span>{formatMs(monitor.responseMs)}</span>
                    {(latencySeries.points[monitor.id]?.length ?? 0) >= 2 && (
                      <Sparkline
                        points={latencySeries.points[monitor.id]}
                        window={latencySeries.window}
                        tone="latency"
                        unit="ms"
                        className="h-4 w-10 shrink-0"
                      />
                    )}
                  </div>
                </TableCell>
                {(() => {
                  // An agent-side check has no machine CPU/RAM of its own - show
                  // ITS process's consumption from the agent rankings (user request).
                  const usage = processUsage(monitor, rows);
                  const isProc = (monitor.type || '').toLowerCase() === 'agent_service';
                  return (
                    <>
                      <TableCell className={NUM}>
                        <ThresholdValue value={usage.cpu} limit={thresholdFor(monitor, 'cpu')} />
                      </TableCell>
                      <TableCell className={NUM}>
                        {isProc ? (
                          usage.ram != null ? (
                            <span className="text-muted-foreground">{usage.ram} MB</span>
                          ) : (
                            <span className="text-muted-foreground">—</span>
                          )
                        ) : (
                          <ThresholdValue value={usage.ram} limit={thresholdFor(monitor, 'ram')} />
                        )}
                      </TableCell>
                    </>
                  );
                })()}
                <TableCell className={NUM}>
                  <ThresholdValue value={monitor.hdd} limit={thresholdFor(monitor, 'hdd')} />
                </TableCell>
                <TableCell className={cn(NUM, monitor.status === 'down' ? 'text-down' : 'text-muted-foreground')}>
                  {/* Since the last recorded change; the status column says which
                      state, so a down row reads as the outage's length. */}
                  {monitor.sinceStatusChangeSeconds == null
                    ? '—'
                    : formatDuration(monitor.sinceStatusChangeSeconds, lang)}
                </TableCell>
                <TableCell className="text-muted-foreground pr-5 pl-2 font-mono text-xs whitespace-nowrap tabular-nums">
                  {monitor.lastCheck ? formatRelative(monitor.lastCheck, lang) : '—'}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </>
  );
}

function ThresholdValue({ value, limit }: { value: number | null; limit: number }) {
  const { lang } = useLanguage();
  if (value == null) return <span className="text-muted-foreground">—</span>;
  // Coloured against the limit configured on this monitor, not a number
  // invented in this file.
  const severity = metricSeverity(value, limit);
  return (
    <span className={severity === 'down' ? 'text-down font-medium' : severity === 'warning' ? 'text-warning' : ''}>
      {formatPercent(value, 0, lang)}
    </span>
  );
}

/**
 * The footnote under the history: what the list leaves out, and where it is.
 * "N dalších: 30 dní bez výpadku" is only said of rows that really were up on
 * every day - a monitor with unmeasured days stays on the list above.
 */
function HistoryFootnote({
  clean,
  moreProblems,
  days,
  allClean,
}: {
  clean: number;
  moreProblems: number;
  days: number;
  allClean: boolean;
}) {
  const { t, lang } = useLanguage();
  if (clean === 0 && moreProblems === 0) return null;
  const form = pluralForm(lang, clean);
  const cleanText = allClean
    ? t('dashboard.history_all_clean', { n: clean, days }, `Všech ${clean}: ${days} dní bez výpadku`)
    : form === 'one'
      ? t('dashboard.history_more_one', { n: clean, days }, `${clean} další: ${days} dní bez výpadku`)
      : form === 'few'
        ? t('dashboard.history_more_few', { n: clean, days }, `${clean} další: ${days} dní bez výpadku`)
        : t('dashboard.history_more_other', { n: clean, days }, `${clean} dalších: ${days} dní bez výpadku`);
  return (
    <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
      {clean > 0 && (
        <Link to="/reports" className="text-muted-foreground hover:text-foreground hover:underline">
          {cleanText} →
        </Link>
      )}
      {moreProblems > 0 && (
        <Link to="/reports" className="text-warning hover:underline">
          {t('dashboard.history_more_problems', { n: moreProblems }, `Další se zhoršenými dny: ${moreProblems}`)} →
        </Link>
      )}
    </p>
  );
}

/**
 * A fresh install (conv-12). With nothing to watch, the dashboard used to
 * say "Nic nevyžaduje pozornost" in green - an all-clear about nothing. The
 * first screen now says what to do next.
 */
function FirstRunCard() {
  const { t } = useLanguage();
  return (
    <Panel data-testid="dashboard-first-run" bodyClassName="py-10">
      <EmptyState
        icon={<Radar />}
        title={t('dashboard.first_run_title', 'Zatím nic nesledujete')}
        hint={t(
          'dashboard.first_run_hint',
          'Přidejte web, server nebo herní službu, nebo připojte router. Přehled se naplní po první kontrole.'
        )}
        action={
          <div className="flex flex-wrap justify-center gap-2">
            <Button asChild size="sm">
              <Link to="/infrastructure?add=1">
                <Plus className="size-4" aria-hidden="true" /> {t('dashboard.first_run_add', 'Přidat první monitor')}
              </Link>
            </Button>
            <Button asChild size="sm" variant="outline">
              <Link to="/api-agents?platform=openwrt">
                <RouterIcon className="size-4" aria-hidden="true" />{' '}
                {t('dashboard.first_run_router', 'Připojit router')}
              </Link>
            </Button>
          </div>
        }
      />
    </Panel>
  );
}
