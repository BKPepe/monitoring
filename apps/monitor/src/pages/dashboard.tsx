import * as React from 'react';
import { Link } from 'react-router';
import {
  Activity,
  AlertTriangle,
  CalendarClock,
  Gamepad2,
  Globe,
  Lightbulb,
  MessageSquare,
  Mic,
  Radar,
  Router as RouterIcon,
  Search,
  Server,
  ShieldCheck,
  Signal,
  TrendingUp,
  Wifi,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { StatusDot, statusVariant } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { MetricTile } from '@/components/metric-tile';
import { HealthDonut } from '@/components/health-donut';
import { UptimeHeatmap } from '@/components/uptime-heatmap';
import { Sparkline } from '@/components/sparkline';
import { DashboardLayoutEditor, type DashboardTile } from '@/components/dashboard-layout-editor';
import { RegionsPanel } from '@/components/regions-panel';
import { LayoutGrid } from 'lucide-react';
import type { UptimeHistoryRow } from '@/data/model';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { DataSourceBanner } from '@/components/data-source-banner';
import { CollectorHealthBanner } from '@/components/collector-health-banner';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { usePublicStatus } from '@/api/use-asset-charts';
import { cn, formatMs, formatPercent, formatRelative, formatUptime } from '@/lib/utils';
import { nestUnderAgents, processUsage } from '@/lib/monitor-grouping';
import { buildNeedsAttention, metricSeverity, thresholdFor } from '@/lib/attention';
import { isProbeMonitor } from '@/lib/monitor-type';
import { LoadingState, EmptyState, ErrorState } from '@/components/ui/states';

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

  React.useEffect(() => {
    let active = true;

    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        const userTargets = list.filter((m: ApiMonitor) => {
          // By type, like api.php - never by name (W1-D2).
          return !isProbeMonitor(m.type);
        });

        setMonitors(userTargets.length > 0 ? userTargets : list);
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

  const totalMonitors = monitors.length > 0 ? monitors.length : (live?.totalMonitors ?? 0);
  const downMonitors = monitors.filter((m) => m.status === 'down').length;
  // Healthy = actually UP. "Total minus down" counted warnings, paused
  // checks and silent agents as healthy, so the tile said 100 % with a
  // degraded service on the list.
  const healthyCount = monitors.filter((m) => m.status === 'up').length;
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
      severity: 'down' | 'warning' | 'up';
      /** When the state changed; null when the server never recorded a change - never "now". */
      at: string | null;
    }[] = [];
    monitors.forEach((m) => {
      if (m.status === 'down') {
        alertsList.push({
          id: m.id,
          title: `🔴 ${t('dashboard.outage_title', 'Výpadek služby')}: ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target}`,
          severity: 'down',
          at: m.lastStatusChange ?? null,
        });
      } else if (m.status === 'warning') {
        alertsList.push({
          id: m.id,
          title: `⚡ ${t('dashboard.high_latency', 'Zvýšená latence')}: ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target}`,
          severity: 'warning',
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
  const [latencySeries, setLatencySeries] = React.useState<Record<number, (number | null)[]>>({});
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
                // outage as a smooth line.
                Array.isArray(data?.points)
                  ? data.points.map((p: [number, number]) => (typeof p[1] === 'number' ? p[1] : null))
                  : [],
              ] as const
          )
          .catch(() => [m.id, []] as const)
      )
    ).then((entries) => {
      if (!active) return;
      const map: Record<number, (number | null)[]> = {};
      for (const [id, vals] of entries) map[id] = vals;
      setLatencySeries(map);
    });
    return () => {
      active = false;
    };
  }, [sparklineKey]);

  const [dailyUptimeRows, setDailyUptimeRows] = React.useState<
    Record<number, { date: string; status: 'up' | 'down' | 'warning' | 'paused'; uptimePct: number }[]>
  >({});
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

  // The "System Insights" row per the mockup - the server aggregates forecast/
  // anomalies/network insights across all monitors. Empty = the row does not
  // render, no decorative "all OK" cards.
  const [systemInsights, setSystemInsights] = React.useState<
    { monitorId: number; monitorName: string; kind: string; text: string; detail: string }[]
  >([]);
  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=dashboard_insights&limit=4&lang=${lang}`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (active && Array.isArray(data?.insights)) setSystemInsights(data.insights);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, [lang]);

  const liveUptimeHistory = React.useMemo<UptimeHistoryRow[]>(() => {
    if (monitors.length === 0) return [];
    return monitors.slice(0, 6).map((m) => {
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
        const dateStr = d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });
        // Never measured is not the same as switched off - the legend used to
        // label these days "Pozastaveno".
        days.push({ date: dateStr, status: 'nodata' as const, uptimePct: null });
      }
      return { monitorId: m.id, name: m.name, days };
    });
  }, [monitors, dailyUptimeRows]);

  // The "Needs attention" section - logic and thresholds in lib/attention.ts, so it
  // otestovat bez renderu (viz attention.test.ts).
  const needsAttention = React.useMemo(
    () =>
      buildNeedsAttention(monitors, {
        down: t('attention.down', 'Služba je nedostupná'),
        warning: t('attention.warning', 'Monitor hlásí varování'),
        unreachable: t('attention.unreachable', 'Cíl je trvale nedosažitelný — zvažte kontrolu agentem'),
        sslExpired: t('attention.ssl_expired', 'SSL certifikát vypršel!'),
        sslExpiring: (days) => t('attention.ssl_expiring', { days }, `SSL certifikát vyprší za ${days} dní`),
        agentUpdate: (version) =>
          t('attention.agent_update', { version }, `Agent je zastaralý — k dispozici je verze ${version}`),
        metricHigh: (metric, value) => t('attention.metric_high', { metric, value }, `${metric} na ${value} %`),
      }),
    [monitors, t]
  );

  // --- Dashboard sections as named blocks ------------------------------
  // The stored layout drives their order and visibility; without a stored
  // layout the default mockup renders (monitors left, alerts+health in the
  // right column).
  const attentionSection = (
    <Card key="attention">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <AlertTriangle className={`size-4 ${needsAttention.length > 0 ? 'text-warning' : 'text-up'}`} />
          {t('attention.title', 'Vyžaduje pozornost')}
          {needsAttention.length > 0 && (
            <span className="bg-warning/15 text-warning rounded-full px-2 py-0.5 text-xs font-bold">
              {needsAttention.length}
            </span>
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-1 px-2 pb-3">
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
          needsAttention.map((item) => (
            <Link
              key={item.key}
              to={`/infrastructure/${item.monitorId}`}
              className="hover:bg-muted/40 flex items-center gap-3 rounded-md px-3 py-2 transition-colors"
            >
              <StatusDot variant={item.severity} />
              <span className="min-w-0 flex-1 truncate text-sm font-medium">{item.name}</span>
              <span className={`shrink-0 text-xs ${item.severity === 'down' ? 'text-down' : 'text-warning'}`}>
                {item.text}
              </span>
            </Link>
          ))
        )}
      </CardContent>
    </Card>
  );

  const monitorsSection = (wide: boolean) => (
    <Card className={wide ? undefined : 'xl:col-span-2'}>
      <CardHeader className="flex-wrap">
        <CardTitle>{t('dashboard.monitors_card_title', 'Sledované Monitory & Služby')}</CardTitle>
        <div className="relative w-full max-w-56">
          <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
          <Input
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('dashboard.search_placeholder', 'Hledat monitory…')}
            aria-label={t('dashboard.search_placeholder', 'Hledat monitory…')}
            className="h-8 pl-8 text-xs"
          />
        </div>
      </CardHeader>

      <CardContent className="px-0 pb-0">
        <Tabs value={filter} onValueChange={(v) => setFilter(v as StatusFilter)}>
          {/* Six tabs are wider than a phone. The strip scrolls on its own
              instead of stretching the card, which pushed each card's status
              past the screen edge (W1-D3). The wrapper scrolls, not the list,
              so the active tab's underline is not clipped at its border. */}
          <div className="mx-5 overflow-x-auto">
            <TabsList className="mb-0 whitespace-nowrap">
              <TabsTrigger value="all">
                {t('common.all', 'Vše')} ({monitors.length})
              </TabsTrigger>
              <TabsTrigger value="up">
                {t('common.online', 'Online')} ({monitors.filter((m) => m.status === 'up').length})
              </TabsTrigger>
              <TabsTrigger value="warning">
                {t('common.warning', 'Varování')} ({monitors.filter((m) => m.status === 'warning').length})
              </TabsTrigger>
              <TabsTrigger value="down">
                {t('common.offline', 'Offline')} ({monitors.filter((m) => m.status === 'down').length})
              </TabsTrigger>
              <TabsTrigger value="paused">
                {t('common.paused', 'Pozastaveno')} ({monitors.filter((m) => m.status === 'paused').length})
              </TabsTrigger>
              {/* Silent agents had no tab - they were invisible in every filter but "all". */}
              {(monitors.some((m) => m.status === 'unknown') || filter === 'unknown') && (
                <TabsTrigger value="unknown">
                  {t('status.unknown', 'Neznámý')} ({monitors.filter((m) => m.status === 'unknown').length})
                </TabsTrigger>
              )}
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
                  <p className="px-5 pt-3 text-xs text-warning">
                    ⚠ {t('dashboard.refresh_failed', 'Obnovení selhalo, data mohou být zastaralá')} — {monitorsError}
                  </p>
                )}
                <MonitorTable rows={visibleMonitors} latencySeries={latencySeries} />
              </>
            )}
          </TabsContent>
        </Tabs>
      </CardContent>

      <div className="text-muted-foreground flex items-center justify-between border-t border-border px-5 py-3 text-xs">
        <span>{t('dashboard.showing', { shown: visibleMonitors.length, total: monitors.length })}</span>
        <Button variant="outline" size="sm" asChild>
          <Link to="/infrastructure">{t('common.open_details', 'Zobrazit vše')}</Link>
        </Button>
      </div>
    </Card>
  );

  const alertsSection = (
    <Card>
      <CardHeader>
        {/* Named for what it holds: monitors that are down or degraded RIGHT
            NOW, newest change first. It never contained history - a service
            that failed and recovered an hour ago was never in it. */}
        <CardTitle>{t('dashboard.active_alerts', 'Aktivní výstrahy')}</CardTitle>
        <Button variant="ghost" size="sm" asChild>
          <Link to="/incidents">{t('common.open_details', 'Zobrazit vše')}</Link>
        </Button>
      </CardHeader>
      <CardContent className="flex flex-col gap-1 px-2">
        {realAlerts.length === 0 && (
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
        )}
        {realAlerts.map((alert) => (
          <Link
            key={alert.id}
            to={`/infrastructure/${alert.id}`}
            className="hover:bg-muted/40 flex items-start gap-3 rounded-md px-3 py-2.5 transition-colors cursor-pointer"
          >
            <StatusDot variant={alert.severity} className="mt-1.5" />
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-medium">{alert.title}</p>
              <p className="text-muted-foreground truncate text-xs">{alert.source}</p>
            </div>
            <span className="text-muted-foreground shrink-0 text-xs">{alert.at ? formatRelative(alert.at) : '—'}</span>
          </Link>
        ))}
      </CardContent>
    </Card>
  );

  const healthSection = (
    <Card>
      <CardHeader>
        <CardTitle>{t('dashboard.infra_health', 'Zdraví infrastruktury')}</CardTitle>
      </CardHeader>
      <CardContent>
        {/* No ring before the list arrived or after it failed: an empty ring
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
              value: healthyPct == null ? '—' : formatPercent(healthyPct),
              caption: t('dashboard.healthy_pct', 'Zdravých'),
            }}
            // Each status leads to the device list narrowed to it - the ring
            // named a problem and offered no way to reach it.
            hrefFor={(segment) => `/infrastructure?status=${segment.variant}`}
            segments={[
              {
                label: t('common.online', 'Online'),
                value: monitors.filter((m) => m.status === 'up').length,
                variant: 'up',
              },
              {
                label: t('common.warning', 'Varování'),
                value: monitors.filter((m) => m.status === 'warning').length,
                variant: 'warning',
              },
              {
                label: t('common.offline', 'Offline'),
                value: monitors.filter((m) => m.status === 'down').length,
                variant: 'down',
              },
              {
                label: t('common.paused', 'Pozastaveno'),
                value: monitors.filter((m) => m.status === 'paused').length,
                variant: 'paused',
              },
              {
                label: t('common.maintenance', 'Údržba'),
                value: monitors.filter((m) => m.status === 'maintenance').length,
                variant: 'maintenance',
              },
              {
                label: t('status.unknown', 'Neznámý'),
                value: monitors.filter((m) => m.status === 'unknown').length,
                variant: 'unknown',
              },
            ]}
          />
        )}
      </CardContent>
    </Card>
  );

  const insightsSection =
    systemInsights.length > 0 ? (
      <div className="space-y-3">
        <div>
          <h2 className="text-sm font-semibold tracking-tight flex items-center gap-2">
            <Lightbulb className="size-4 text-primary" /> {t('dashboard.insights_title', 'System Insights')}
          </h2>
          <p className="text-muted-foreground text-xs">
            {t('dashboard.insights_subtitle', 'Automatická analýza trendů a anomálií napříč infrastrukturou.')}
          </p>
        </div>
        <div className="grid gap-4 *:min-w-0 sm:grid-cols-2 xl:grid-cols-4">
          {systemInsights.map((ins, idx) => {
            const InsIcon =
              ins.kind === 'network'
                ? Wifi
                : ins.kind === 'anomaly'
                  ? AlertTriangle
                  : ins.kind === 'forecast'
                    ? CalendarClock
                    : TrendingUp;
            const iconCls = ins.kind === 'network' || ins.kind === 'anomaly' ? 'text-warning' : 'text-primary';
            return (
              <Card key={`${ins.monitorId}-${idx}`} className="p-4 flex flex-col gap-2">
                <div className="flex items-center gap-2">
                  <span className="bg-muted grid size-7 shrink-0 place-items-center rounded-lg">
                    <InsIcon className={`size-3.5 ${iconCls}`} />
                  </span>
                  <p className="text-xs font-semibold truncate">{ins.monitorName}</p>
                </div>
                <p className="text-xs leading-relaxed">{ins.text}</p>
                {ins.detail && <p className="text-muted-foreground text-2xs">{ins.detail}</p>}
                <Link
                  to={`/infrastructure/${ins.monitorId}`}
                  className="text-primary mt-auto text-xs font-semibold hover:underline"
                >
                  {t('common.open_details', 'Zobrazit vše')}
                </Link>
              </Card>
            );
          })}
        </div>
      </div>
    ) : null;

  const uptimeSection = (
    <Card className="overflow-visible relative z-20">
      <CardHeader className="flex-row items-center justify-between">
        <div>
          <CardTitle>{t('dashboard.availability_history', 'Historie dostupnosti sledovaných služeb')}</CardTitle>
          <CardDescription>
            {t('dashboard.availability_30d', 'Sledovaná dostupnost v čase (posledních 30 dní)')}
          </CardDescription>
        </div>
        <Button variant="outline" size="sm" asChild>
          <Link to="/reports">{t('dashboard.full_report', 'Celý report')}</Link>
        </Button>
      </CardHeader>
      <CardContent className="overflow-visible">
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
          <UptimeHeatmap rows={liveUptimeHistory} />
        )}
      </CardContent>
    </Card>
  );

  // Aggregated metric tiles (catalogue: metric_cpu/ram/hdd) - the highest
  // value across agents; without a single measurement the tile does not render.
  const metricTile = (key: string) => {
    // Klic je bud agregovany ("metric_cpu" = nejvyssi hodnota napric stroji),
    // nebo vazany na konkretni monitor ("metric_cpu_12").
    const parts = key.split('_');
    const field = parts[1] === 'cpu' ? 'cpu' : parts[1] === 'ram' ? 'ram' : 'hdd';
    const label = parts[1] === 'cpu' ? 'CPU' : parts[1] === 'ram' ? 'RAM' : 'Disk';
    const monitorId = parts.length > 2 ? Number(parts[2]) : null;

    const pool = monitorId != null ? monitors.filter((m) => m.id === monitorId) : monitors;
    const reporting = pool.filter((m) => typeof m[field] === 'number');
    // Bez jedineho mereni se dlazdice nevykresli - lepsi nez prazdna karta.
    if (reporting.length === 0) return null;

    const worst = reporting.reduce((a, b) => ((a[field] ?? 0) >= (b[field] ?? 0) ? a : b));
    const value = worst[field] as number;
    return (
      <MetricTile
        label={
          monitorId != null ? `${label} — ${worst.name}` : t('dashboard.metric_worst', { label }, `Nejvyšší ${label}`)
        }
        value={formatPercent(value)}
        icon={Activity}
        tone={
          metricSeverity(value, thresholdFor(worst, field === 'cpu' ? 'cpu' : field === 'ram' ? 'ram' : 'hdd')) ??
          undefined
        }
        hint={monitorId != null ? undefined : worst.name}
      />
    );
  };

  // Rendered in the stored order; adjacent alerts+health pair into two
  // columns, adjacent metrics into one tile row.
  // Vykresleni podle ulozeneho rozlozeni: dvousloupcovy grid, kde sirka
  // dlazdice z editoru urcuje, jestli zabere jeden sloupec ("bezna") nebo
  // oba ("siroka"). Drive se sirka ukladala, ale nikdo ji necetl - prepinac
  // v editoru nedelal nic.
  const orderedLayout = () => {
    const sectionFor = (key: string, wide: boolean): React.ReactNode => {
      if (key === 'attention') return attentionSection;
      if (key === 'monitors') return monitorsSection(wide);
      if (key === 'alerts') return alertsSection;
      if (key === 'health') return healthSection;
      if (key === 'insights') return insightsSection;
      if (key === 'uptime_history') return uptimeSection;
      if (key === 'regions') return <RegionsPanel />;
      if (key.startsWith('metric_')) return metricTile(key);
      return null;
    };

    const rendered = tiles
      .filter((tl) => tl.visible)
      .map((tl) => {
        const wide = tl.size === 'wide';
        const node = sectionFor(tl.key, wide);
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
      <PageHeader
        title={t('dashboard.title', 'Status Overview')}
        subtitle={t(
          'dashboard.subtitle',
          'Přehled všech vašich monitorovaných služeb, domén a serverů v reálném čase.'
        )}
        actions={
          <Button variant="outline" size="sm" onClick={() => setLayoutOpen(true)} className="gap-2 font-semibold">
            <LayoutGrid className="size-4" /> {t('dashboard.customize', 'Upravit rozložení')}
          </Button>
        }
      >
        {loadedAt && (
          <p className="text-muted-foreground mt-0.5 text-xs tabular-nums">
            {t(
              'dashboard.data_as_of',
              { time: loadedAt.toLocaleTimeString(lang === 'en' ? 'en-GB' : 'cs-CZ') },
              `Data z ${loadedAt.toLocaleTimeString('cs-CZ')}, obnovují se každou minutu`
            )}
          </p>
        )}
      </PageHeader>

      <DashboardLayoutEditor
        open={layoutOpen}
        onClose={() => setLayoutOpen(false)}
        onSaved={(next) => setTiles(next)}
      />

      <DataSourceBanner />

      {/* Louder than any single monitor: when cron stops, every number below is
          stale and the page would otherwise look calm. */}
      <CollectorHealthBanner />

      <CollectionIssuesBanner monitors={monitors} />

      {/* Two tiles per row already on a phone: four full-width tiles pushed
          the monitors a whole screen down (W1-D3). min-w-0 on every grid
          child: a grid item is never narrower than its content by default,
          so one wide child made the whole page scroll sideways. */}
      {kpiFailed && (
        <ErrorState
          message={t(
            'dashboard.kpi_failed',
            'Souhrnná čísla nejsou k dispozici - seznam monitorů se nepodařilo načíst.'
          )}
          onRetry={() => setRefreshTick((n) => n + 1)}
        />
      )}
      <div className="grid grid-cols-2 gap-4 *:min-w-0 xl:grid-cols-4">
        <MetricTile
          label={t('dashboard.total_monitors', 'Monitorů celkem')}
          value={kpiFailed ? '—' : totalMonitors}
          loading={kpiLoading}
          icon={Signal}
          hint={kpiFailed ? undefined : t('dashboard.monitors_hint', { healthy: healthyCount, down: downMonitors })}
        />
        {/* Both tones below are derived from the value. They used to be green
            by construction: 40 % healthy and a 91 % uptime looked exactly as
            reassuring as 100 % and 99.99 %. */}
        <MetricTile
          label={t('dashboard.healthy_pct', 'Zdravých')}
          value={kpiFailed || healthyPct == null ? '—' : formatPercent(healthyPct)}
          loading={kpiLoading}
          icon={ShieldCheck}
          tone={
            kpiFailed || healthyPct == null
              ? undefined
              : downMonitors > 0
                ? 'down'
                : healthyPct >= 100
                  ? 'up'
                  : 'warning'
          }
          hint={
            kpiFailed ? undefined : t('dashboard.healthy_of_total', { healthy: healthyCount, total: monitors.length })
          }
        />
        <MetricTile
          label={t('dashboard.outages', 'Výpadky')}
          value={kpiFailed ? '—' : downMonitors}
          loading={kpiLoading}
          icon={AlertTriangle}
          tone={kpiFailed ? undefined : downMonitors > 0 ? 'down' : 'up'}
          hint={
            kpiFailed
              ? t('dashboard.kpi_unknown', 'Stav nelze zjistit')
              : downMonitors > 0
                ? t('dashboard.ongoing_outage', 'Probíhající výpadek')
                : t('dashboard.no_outages', 'Všechny systémy bez výpadku')
          }
        />
        <MetricTile
          label={t('dashboard.uptime_30d', 'Uptime (30 d)')}
          value={uptimeKnown ? uptime.toFixed(2) : '—'}
          unit={uptimeKnown ? '%' : undefined}
          loading={uptimeLoading}
          icon={Activity}
          tone={!uptimeKnown ? undefined : uptime >= 99.9 ? 'up' : uptime >= 99 ? 'warning' : 'down'}
          hint={
            live === null && liveError !== null
              ? t('dashboard.kpi_unknown', 'Stav nelze zjistit')
              : !uptimeKnown
                ? t('dashboard.uptime_pending', 'Zatím žádná data za 30 dní')
                : live && live.avgLatencyMs != null
                  ? `${t('dashboard.avg_response', 'Průměrná odezva')} ${live.avgLatencyMs} ms`
                  : t('dashboard.whole_infra', 'Celá infrastruktura')
          }
        />
      </div>

      {tiles.length === 0 ? (
        <>
          {attentionSection}
          <div className="grid gap-4 *:min-w-0 xl:grid-cols-3">
            {monitorsSection(false)}
            <div className="flex flex-col gap-4">
              {alertsSection}
              {healthSection}
            </div>
          </div>
          {insightsSection}
          {uptimeSection}
        </>
      ) : (
        orderedLayout()
      )}
    </div>
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

// Icon colour tuning by type (mockup: each service kind has its own shade).
const typeTint: Record<string, string> = {
  // The kind of a monitor is a category, not a verdict: one neutral chip,
  // the word does the telling. Ten hues used to carry nothing but decoration.
  web: 'bg-secondary text-secondary-foreground',
  http: 'bg-secondary text-secondary-foreground',
  https: 'bg-secondary text-secondary-foreground',
  teamspeak: 'bg-secondary text-secondary-foreground',
  minecraft: 'bg-secondary text-secondary-foreground',
  discord: 'bg-secondary text-secondary-foreground',
  openwrt: 'bg-secondary text-secondary-foreground',
  vps: 'bg-secondary text-secondary-foreground',
  cpanel: 'bg-secondary text-secondary-foreground',
  agent_service: 'bg-secondary text-secondary-foreground',
};

function MonitorTable({
  rows,
  latencySeries,
}: {
  rows: ApiMonitor[];
  latencySeries: Record<number, (number | null)[]>;
}) {
  const { t } = useLanguage();

  const statusText: Record<MonitorStatus, string> = {
    up: t('common.online', 'Online'),
    down: t('common.offline', 'Offline'),
    warning: t('common.warning', 'Varování'),
    paused: t('common.paused', 'Pozastaveno'),
    maintenance: t('common.maintenance', 'Údržba'),
    unknown: t('status.unknown', 'Neznámý'),
  };

  if (rows.length === 0) {
    return <EmptyState title={t('dashboard.no_monitors', 'Žádný monitor neodpovídá filtru.')} />;
  }

  return (
    <>
      {/* Mobile: cards instead of the table - 8 columns cannot be used on a 390px
          be read even with a horizontal scroll. */}
      <div className="flex flex-col gap-2 px-4 pb-4 md:hidden">
        {nestUnderAgents(rows).map(({ row: monitor, child }) => {
          const usage = processUsage(monitor, rows);
          return (
            <Link
              key={monitor.id}
              to={`/infrastructure/${monitor.id}`}
              className={cn(
                'rounded-lg border border-border bg-card p-3 transition-colors hover:border-primary/40',
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
                <span className="flex shrink-0 items-center gap-1.5 text-xs font-semibold">
                  <StatusDot variant={statusVariant[monitor.status]} />
                  <span
                    className={
                      monitor.status === 'up'
                        ? 'text-up'
                        : monitor.status === 'down'
                          ? 'text-down'
                          : 'text-muted-foreground'
                    }
                  >
                    {statusText[monitor.status]}
                  </span>
                </span>
                <span className="min-w-0 truncate text-sm font-semibold">{monitor.name}</span>
              </div>
              <div className="text-muted-foreground mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs">
                <span>{monitor.responseMs != null ? formatMs(monitor.responseMs) : '—'}</span>
                {usage.cpu != null && <span>CPU {formatPercent(usage.cpu)}</span>}
                {usage.ram != null && (
                  <span>
                    RAM{' '}
                    {(monitor.type || '').toLowerCase() === 'agent_service'
                      ? `${usage.ram} MB`
                      : formatPercent(usage.ram)}
                  </span>
                )}
                {monitor.hdd != null && <span>HDD {formatPercent(monitor.hdd)}</span>}
                {monitor.lastCheck && <span>{formatRelative(monitor.lastCheck)}</span>}
              </div>
            </Link>
          );
        })}
      </div>

      {/* No overflow-x-auto here and none on the CardContent above: the Table
          primitive brings its own scroll box, focusable and labelled, and
          three boxes inside one another meant the outer two never knew the
          table was wider than the card - only the innermost clipped it. The
          eight columns fit a 1440 card again since the monitor column stopped
          taking its full content width (a long "type · target" pushed the
          last column past the card edge, so "Poslední kontrola" read "Posl"). */}
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
              <TableHead className="px-2">{t('common.uptime', 'Uptime')}</TableHead>
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
                        <span
                          className={`grid size-8 shrink-0 place-items-center rounded-lg ${typeTint[tkey] ?? 'bg-muted text-muted-foreground'}`}
                        >
                          <Icon className="size-4" />
                        </span>
                      );
                    })()}
                    <div className="leading-tight min-w-0 max-w-[10rem]">
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
                  {/* Mockup: status as coloured text with a dot, not a pill badge. */}
                  <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <StatusDot variant={statusVariant[monitor.status]} />
                    <span
                      className={
                        monitor.status === 'up'
                          ? 'text-up'
                          : monitor.status === 'down'
                            ? 'text-down'
                            : monitor.status === 'warning'
                              ? 'text-warning'
                              : 'text-muted-foreground'
                      }
                    >
                      {statusText[monitor.status]}
                    </span>
                  </span>
                </TableCell>
                <TableCell className="tabular-nums px-2">
                  <div className="flex items-center gap-1.5">
                    <span>{formatMs(monitor.responseMs)}</span>
                    {(latencySeries[monitor.id]?.length ?? 0) >= 2 && (
                      <Sparkline data={latencySeries[monitor.id]} tone="latency" className="h-4 w-10 shrink-0" />
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
                      <TableCell className="tabular-nums px-2">
                        <ThresholdValue value={usage.cpu} limit={thresholdFor(monitor, 'cpu')} />
                      </TableCell>
                      <TableCell className="tabular-nums px-2">
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
                <TableCell className="tabular-nums px-2">
                  <ThresholdValue value={monitor.hdd} limit={thresholdFor(monitor, 'hdd')} />
                </TableCell>
                <TableCell
                  className={cn('tabular-nums px-2', monitor.status === 'down' ? 'text-down' : 'text-muted-foreground')}
                >
                  {/* A monitor that is down has no uptime - it has an outage duration. */}
                  {monitor.status === 'down' && monitor.sinceStatusChangeSeconds != null
                    ? `${t('dashboard.down_for', 'Výpadek')} ${formatUptime(monitor.sinceStatusChangeSeconds)}`
                    : monitor.uptimeSeconds == null
                      ? '—'
                      : formatUptime(monitor.uptimeSeconds)}
                </TableCell>
                <TableCell className="text-muted-foreground pr-5 pl-2 text-xs whitespace-nowrap">
                  {monitor.lastCheck ? formatRelative(monitor.lastCheck) : '—'}
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
  if (value == null) return <span className="text-muted-foreground">—</span>;
  // Coloured against the limit configured on this monitor, not a number
  // invented in this file.
  const severity = metricSeverity(value, limit);
  return (
    <span className={severity === 'down' ? 'text-down font-medium' : severity === 'warning' ? 'text-warning' : ''}>
      {formatPercent(value)}
    </span>
  );
}
