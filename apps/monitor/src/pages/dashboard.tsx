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
import { StatusDot } from '@/components/ui/badge';
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

export function DashboardPage() {
  const { t, lang } = useLanguage();
  const [query, setQuery] = React.useState('');
  const [filter, setFilter] = React.useState<StatusFilter>('all');
  // Refreshes every minute; the monitors list below reloads whenever `live`
  // changes, so the whole page follows. It used to load once per visit and
  // show the morning's state all day.
  const { data: live } = usePublicStatus(60_000);
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
  const [monitors, setMonitors] = React.useState<ApiMonitor[]>([]);
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
          const t = (m.type || '').toLowerCase();
          const n = (m.name || '').toLowerCase();
          return t !== 'node' && t !== 'probe' && !n.includes('as13335') && !n.includes('as8075');
        });

        setMonitors(userTargets.length > 0 ? userTargets : list);
        setMonitorsError(null);
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
  }, [session, live, t]);

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
      id: number;
      assetId: number;
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
          assetId: m.id,
          title: `🔴 ${t('dashboard.outage_title', 'Výpadek služby')}: ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target}`,
          severity: 'down',
          at: m.lastStatusChange ?? null,
        });
      } else if (m.status === 'warning') {
        alertsList.push({
          id: m.id,
          assetId: m.id,
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

  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=daily_uptime&days=30&lang=${lang}`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!active || !data?.series) return;
        setDailyUptimeRows(data.series);
        setDailyUptimeError(null);
      })
      .catch(() => {
        if (active) setDailyUptimeError(t('dashboard.uptime_load_error', 'Chyba při načítání denní dostupnosti.'));
      });
    return () => {
      active = false;
    };
  }, [t, lang]);

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
          <p className="text-muted-foreground px-3 py-3 text-sm">
            {t('dashboard.loading_monitors', 'Načítám monitory…')}
          </p>
        ) : monitorsError && monitors.length === 0 ? (
          <p className="text-muted-foreground px-3 py-3 text-sm">
            {t('attention.unknown', 'Stav nelze zjistit — seznam monitorů se nenačetl.')}
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
              to={`/infrastructure/${item.assetId}`}
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

      <CardContent className="px-0 pb-0 overflow-x-auto">
        <Tabs value={filter} onValueChange={(v) => setFilter(v as StatusFilter)}>
          <TabsList className="mx-5 mb-0">
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
              {t('common.paused', 'Paused')} ({monitors.filter((m) => m.status === 'paused').length})
            </TabsTrigger>
            {/* Silent agents had no tab - they were invisible in every filter but "all". */}
            {(monitors.some((m) => m.status === 'unknown') || filter === 'unknown') && (
              <TabsTrigger value="unknown">
                {t('status.unknown', 'Neznámý (agent mlčí)')} ({monitors.filter((m) => m.status === 'unknown').length})
              </TabsTrigger>
            )}
          </TabsList>

          <TabsContent value={filter} className="mt-0">
            {monitorsError && monitors.length === 0 ? (
              <p className="text-down px-5 py-10 text-center text-sm">{monitorsError}</p>
            ) : monitorsLoading ? (
              <p className="text-muted-foreground px-5 py-10 text-center text-sm">
                {t('dashboard.loading_monitors', 'Načítám monitory…')}
              </p>
            ) : (
              <>
                {/* A failed minute refresh keeps the last good list on screen and
                    says it is stale - it used to replace the whole table. */}
                {monitorsError && (
                  <p className="px-5 pt-3 text-xs text-amber-700 dark:text-amber-400">
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
            ) : monitorsError && monitors.length === 0 ? (
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
            to={`/infrastructure/${alert.assetId}`}
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
            { label: 'Paused', value: monitors.filter((m) => m.status === 'paused').length, variant: 'paused' },
            {
              label: t('common.maintenance', 'Údržba'),
              value: monitors.filter((m) => m.status === 'maintenance').length,
              variant: 'maintenance',
            },
            {
              label: t('status.unknown', 'Neznámý (agent mlčí)'),
              value: monitors.filter((m) => m.status === 'unknown').length,
              variant: 'unknown',
            },
          ]}
        />
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
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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
                {ins.detail && <p className="text-muted-foreground text-[11px]">{ins.detail}</p>}
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
          <p className="text-muted-foreground py-8 text-center text-sm">{dailyUptimeError}</p>
        ) : liveUptimeHistory.length === 0 ? (
          <p className="text-muted-foreground py-8 text-center text-sm">
            {t('dashboard.loading_uptime', 'Načítám historii dostupnosti…')}
          </p>
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
          <div key={tl.key} className={wide ? 'md:col-span-2' : undefined}>
            {node}
          </div>
        );
      })
      .filter(Boolean);

    return <div className="grid gap-4 md:grid-cols-2">{rendered}</div>;
  };

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">{t('dashboard.title', 'Status Overview')}</h1>
          <p className="text-muted-foreground text-sm">
            {t('dashboard.subtitle', 'Přehled všech vašich monitorovaných služeb, domén a serverů v reálném čase.')}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => setLayoutOpen(true)} className="gap-2 font-semibold">
          <LayoutGrid className="size-4" /> {t('dashboard.customize', 'Upravit rozložení')}
        </Button>
      </div>

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

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricTile
          label={t('dashboard.total_monitors', 'Monitorů celkem')}
          value={totalMonitors}
          icon={Signal}
          hint={t('dashboard.monitors_hint', { healthy: healthyCount, down: downMonitors })}
        />
        {/* Both tones below are derived from the value. They used to be green
            by construction: 40 % healthy and a 91 % uptime looked exactly as
            reassuring as 100 % and 99.99 %. */}
        <MetricTile
          label={t('dashboard.healthy_pct', 'Zdravých')}
          value={healthyPct == null ? '—' : formatPercent(healthyPct)}
          icon={ShieldCheck}
          tone={healthyPct == null ? undefined : downMonitors > 0 ? 'down' : healthyPct >= 100 ? 'up' : 'warning'}
          hint={t('dashboard.healthy_of_total', { healthy: healthyCount, total: monitors.length })}
        />
        <MetricTile
          label={t('dashboard.outages', 'Výpadky')}
          value={downMonitors}
          icon={AlertTriangle}
          tone={downMonitors > 0 ? 'down' : 'up'}
          hint={
            downMonitors > 0
              ? t('dashboard.ongoing_outage', 'Probíhající výpadek')
              : t('dashboard.no_outages', 'Všechny systémy bez výpadku')
          }
        />
        <MetricTile
          label={t('dashboard.uptime_30d', 'Uptime (30 d)')}
          value={uptimeKnown ? uptime.toFixed(2) : '—'}
          unit={uptimeKnown ? '%' : undefined}
          icon={Activity}
          tone={!uptimeKnown ? undefined : uptime >= 99.9 ? 'up' : uptime >= 99 ? 'warning' : 'down'}
          hint={
            !uptimeKnown
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
          <div className="grid gap-4 xl:grid-cols-3">
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
  web: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
  http: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
  https: 'bg-sky-500/15 text-sky-600 dark:text-sky-400',
  teamspeak: 'bg-indigo-500/15 text-indigo-600 dark:text-indigo-400',
  minecraft: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
  discord: 'bg-violet-500/15 text-violet-600 dark:text-violet-400',
  openwrt: 'bg-cyan-500/15 text-cyan-600 dark:text-cyan-400',
  vps: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
  cpanel: 'bg-orange-500/15 text-orange-600 dark:text-orange-400',
  agent_service: 'bg-teal-500/15 text-teal-600 dark:text-teal-400',
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
    paused: t('common.paused', 'Paused'),
    maintenance: t('common.maintenance', 'Údržba'),
    unknown: t('status.unknown', 'Neznámý (agent mlčí)'),
  };

  if (rows.length === 0) {
    return (
      <p className="text-muted-foreground px-5 py-10 text-center text-sm">
        {t('dashboard.no_monitors', 'Žádný monitor neodpovídá filtru.')}
      </p>
    );
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
              <div className="flex items-center justify-between gap-2">
                <span className="min-w-0 truncate text-sm font-semibold">
                  {child && <span className="text-muted-foreground/60 mr-1 font-mono text-xs">└</span>}
                  {monitor.name}
                </span>
                <span className="flex shrink-0 items-center gap-1.5 text-xs font-semibold">
                  <StatusDot
                    variant={
                      monitor.status === 'maintenance'
                        ? 'paused'
                        : monitor.status === 'unknown'
                          ? 'neutral'
                          : monitor.status
                    }
                  />
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

      <div className="hidden overflow-x-auto md:block">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-5">{t('dashboard.col_monitor_name', 'Monitor')}</TableHead>
              <TableHead>{t('common.status', 'Stav')}</TableHead>
              <TableHead>{t('common.response', 'Odezva')}</TableHead>
              <TableHead>CPU</TableHead>
              <TableHead>RAM</TableHead>
              <TableHead>HDD</TableHead>
              <TableHead>{t('common.uptime', 'Uptime')}</TableHead>
              <TableHead className="pr-5">{t('common.last_check', 'Poslední kontrola')}</TableHead>
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
                    <div className="leading-tight min-w-0">
                      <Link
                        to={`/infrastructure/${monitor.id}`}
                        className="font-medium hover:underline text-foreground"
                      >
                        {monitor.name}
                      </Link>
                      <p className="text-muted-foreground text-xs truncate">
                        {monitor.type} · {monitor.target}
                      </p>
                    </div>
                  </div>
                </TableCell>
                <TableCell>
                  {/* Mockup: status as coloured text with a dot, not a pill badge. */}
                  <span className="flex items-center gap-1.5 text-xs font-semibold">
                    <StatusDot
                      variant={
                        monitor.status === 'maintenance'
                          ? 'info'
                          : monitor.status === 'unknown'
                            ? 'neutral'
                            : monitor.status
                      }
                    />
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
                <TableCell className="tabular">
                  <div className="flex items-center gap-2">
                    <span>{formatMs(monitor.responseMs)}</span>
                    {(latencySeries[monitor.id]?.length ?? 0) >= 2 && (
                      <Sparkline data={latencySeries[monitor.id]} tone="latency" className="h-4 w-14 shrink-0" />
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
                      <TableCell className="tabular">
                        <ThresholdValue value={usage.cpu} limit={thresholdFor(monitor, 'cpu')} />
                      </TableCell>
                      <TableCell className="tabular">
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
                <TableCell className="tabular">
                  <ThresholdValue value={monitor.hdd} limit={thresholdFor(monitor, 'hdd')} />
                </TableCell>
                <TableCell
                  className={monitor.status === 'down' ? 'tabular text-down' : 'tabular text-muted-foreground'}
                >
                  {/* A monitor that is down has no uptime - it has an outage duration. */}
                  {monitor.status === 'down' && monitor.sinceStatusChangeSeconds != null
                    ? `${t('dashboard.down_for', 'Výpadek')} ${formatUptime(monitor.sinceStatusChangeSeconds)}`
                    : monitor.uptimeSeconds == null
                      ? '—'
                      : formatUptime(monitor.uptimeSeconds)}
                </TableCell>
                <TableCell className="text-muted-foreground pr-5 text-xs">
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
