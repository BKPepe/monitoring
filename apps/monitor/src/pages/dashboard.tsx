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
import { LayoutGrid } from 'lucide-react';
import type { UptimeHistoryRow } from '@/data/model';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { DataSourceBanner } from '@/components/data-source-banner';
import { CollectionIssuesBanner } from '@/components/collection-issues-banner';
import { usePublicStatus } from '@/api/use-asset-charts';
import { formatMs, formatPercent, formatRelative, formatUptime } from '@/lib/utils';

type MonitorStatus = ApiMonitor['status'];

type StatusFilter = 'all' | MonitorStatus;

export function DashboardPage() {
  const { t, lang } = useLanguage();
  const [query, setQuery] = React.useState('');
  const [filter, setFilter] = React.useState<StatusFilter>('all');
  const { data: live } = usePublicStatus();
  // Uživatelské rozložení dashboardu (viditelnost + pořadí panelů). Prázdné
  // pole = uchovává se výchozí rozložení, aby se nic neztratilo, dokud si
  // uživatel nic nenastaví.
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

  const panelVisible = React.useCallback(
    (key: string) => {
      if (tiles.length === 0) return true;
      const found = tiles.find((tl) => tl.key === key);
      return found ? found.visible : true;
    },
    [tiles]
  );

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
  const healthyCount = Math.max(0, totalMonitors - downMonitors);
  // null/missing means nobody measured a 30-day uptime (new install, dead
  // cron, unreachable API) - that state renders as "no data". Falling back
  // to a number here would fabricate an SLA, which already happened once
  // with the mock's 100.0 default.
  const uptimeKnown = live?.uptimePercent != null;
  const uptime = live?.uptimePercent ?? 0;

  const visibleMonitors = React.useMemo(() => {
    const needle = query.trim().toLowerCase();
    return monitors.filter((m) => {
      const matchesStatus = filter === 'all' || m.status === filter;
      const matchesQuery =
        !needle ||
        m.name.toLowerCase().includes(needle) ||
        (m.target ?? '').toLowerCase().includes(needle) ||
        (m.type ?? '').toLowerCase().includes(needle);
      return matchesStatus && matchesQuery;
    });
  }, [query, filter, monitors]);

  const realAlerts = React.useMemo(() => {
    const alertsList: {
      id: number;
      assetId: number;
      title: string;
      source: string;
      severity: 'down' | 'warning' | 'up';
      at: string;
    }[] = [];
    monitors.forEach((m) => {
      if (m.status === 'down') {
        alertsList.push({
          id: m.id,
          assetId: m.id,
          title: `🔴 ${t('dashboard.outage_title', 'Výpadek služby')}: ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target}`,
          severity: 'down',
          at: m.lastStatusChange || new Date().toISOString(),
        });
      } else if (m.status === 'warning') {
        alertsList.push({
          id: m.id,
          assetId: m.id,
          title: `⚡ ${t('dashboard.high_latency', 'Zvýšená latence')}: ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target}`,
          severity: 'warning',
          at: m.lastStatusChange || new Date().toISOString(),
        });
      }
    });

    return alertsList;
  }, [monitors, t]);

  // Mini průběhy odezvy pro tabulku (mockup: trend vedle hodnoty). Jeden
  // lehký request na monitor po načtení seznamu; bez dat sparkline prostě není.
  const [latencySeries, setLatencySeries] = React.useState<Record<number, number[]>>({});
  React.useEffect(() => {
    if (monitors.length === 0) return;
    let active = true;
    const targets = monitors.slice(0, 12);
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
                Array.isArray(data?.points)
                  ? data.points
                      .map((p: [number, number]) => p[1])
                      .filter((v: unknown): v is number => typeof v === 'number')
                  : [],
              ] as const
          )
          .catch(() => [m.id, []] as const)
      )
    ).then((entries) => {
      if (!active) return;
      const map: Record<number, number[]> = {};
      for (const [id, vals] of entries) map[id] = vals;
      setLatencySeries(map);
    });
    return () => {
      active = false;
    };
  }, [monitors]);

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

  // "System Insights" řada podle mockupu - server agreguje forecast/anomálie/
  // síťové postřehy přes všechny monitory. Prázdno = řada se nevykreslí,
  // žádné dekorativní "vše OK" karty.
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
        days.push({ date: dateStr, status: 'paused' as const, uptimePct: null });
      }
      return { monitorId: m.id, name: m.name, days };
    });
  }, [monitors, dailyUptimeRows]);

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

      <CollectionIssuesBanner monitors={monitors} />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricTile
          label={t('dashboard.total_monitors', 'Monitorů celkem')}
          value={totalMonitors}
          icon={Signal}
          hint={t('dashboard.monitors_hint', { healthy: healthyCount, down: downMonitors })}
        />
        <MetricTile
          label={t('dashboard.healthy_pct', 'Zdravých')}
          value={formatPercent(totalMonitors ? (healthyCount / totalMonitors) * 100 : 0)}
          icon={ShieldCheck}
          tone="up"
          hint={t('dashboard.healthy_hint', 'Měřící uzly v pořádku')}
        />
        <MetricTile
          label={t('nav.incidents', 'Incidenty')}
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
          tone={uptimeKnown ? 'up' : undefined}
          hint={
            !uptimeKnown
              ? t('dashboard.uptime_pending', 'Zatím žádná data za 30 dní')
              : live && live.avgLatencyMs != null
                ? `${t('dashboard.avg_response', 'Průměrná odezva')} ${live.avgLatencyMs} ms`
                : t('dashboard.whole_infra', 'Celá infrastruktura')
          }
        />
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
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
              </TabsList>

              <TabsContent value={filter} className="mt-0">
                {monitorsError ? (
                  <p className="text-down px-5 py-10 text-center text-sm">{monitorsError}</p>
                ) : monitorsLoading ? (
                  <p className="text-muted-foreground px-5 py-10 text-center text-sm">
                    {t('dashboard.loading_monitors', 'Načítám monitory…')}
                  </p>
                ) : (
                  <MonitorTable rows={visibleMonitors} latencySeries={latencySeries} />
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

        <div className="flex flex-col gap-4">
          <Card>
            <CardHeader>
              <CardTitle>{t('dashboard.recent_alerts', 'Poslední alerty')}</CardTitle>
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
                      {t(
                        'dashboard.no_active_alerts',
                        'Žádné aktivní výstrahy — všechny sledované služby jsou online.'
                      )}
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
                  <span className="text-muted-foreground shrink-0 text-xs">{formatRelative(alert.at)}</span>
                </Link>
              ))}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('dashboard.infra_health', 'Zdraví infrastruktury')}</CardTitle>
            </CardHeader>
            <CardContent>
              <HealthDonut
                centerLabel={{
                  value: formatPercent(totalMonitors ? (healthyCount / totalMonitors) * 100 : 0),
                  caption: t('dashboard.healthy_pct', 'Zdravých'),
                }}
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
                ]}
              />
            </CardContent>
          </Card>
        </div>
      </div>

      {panelVisible('insights') && systemInsights.length > 0 && (
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
      )}

      {panelVisible('uptime_history') && (
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

// Barevné ladění ikon podle typu (mockup: každý druh služby má svůj odstín).
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

/**
 * Seřadí monitory tak, aby služby běžící pod agentem stály hned za ním
 * (a byly odsazené) - uživatel je čekal jako podkategorii agenta, ne jako
 * samostatné řádky na stejné úrovni.
 */
function nestUnderAgents(rows: ApiMonitor[]): { row: ApiMonitor; child: boolean }[] {
  const agents = rows.filter((m) => ['openwrt', 'vps'].includes((m.type || '').toLowerCase()));
  const agentAssetIds = new Set(agents.map((a) => a.assetId).filter((v): v is number => v != null));
  const out: { row: ApiMonitor; child: boolean }[] = [];
  const used = new Set<number>();

  for (const m of rows) {
    if (used.has(m.id)) continue;
    const isAgent = agents.some((a) => a.id === m.id);
    // Dítě = jiný monitor sdílející asset s agentem (agent-side kontroly).
    const isChildOfAgent = !isAgent && m.assetId != null && agentAssetIds.has(m.assetId);
    if (isChildOfAgent) continue; // vypíše se u svého agenta

    out.push({ row: m, child: false });
    used.add(m.id);
    if (isAgent && m.assetId != null) {
      for (const c of rows) {
        if (c.id !== m.id && c.assetId === m.assetId && !used.has(c.id)) {
          out.push({ row: c, child: true });
          used.add(c.id);
        }
      }
    }
  }
  return out;
}

/** CPU/RAM procesu agent-side kontroly z žebříčků jejího agenta. */
function processUsage(row: ApiMonitor, rows: ApiMonitor[]): { cpu: number | null; ram: number | null } {
  if ((row.type || '').toLowerCase() !== 'agent_service') return { cpu: row.cpu, ram: row.ram };
  const parent = rows.find(
    (m) => m.assetId === row.assetId && ['openwrt', 'vps'].includes((m.type || '').toLowerCase())
  );
  const proc = (row.target || '').toLowerCase();
  if (!parent || !proc) return { cpu: null, ram: null };
  const findIn = (list: unknown): any =>
    Array.isArray(list) ? list.find((p: any) => String(p?.name ?? '').toLowerCase() === proc) : undefined;
  const cpuHit = findIn(parent.details?.top_cpu_processes);
  const ramHit = findIn(parent.details?.top_ram_processes) ?? cpuHit;
  return {
    cpu: cpuHit && cpuHit.cpu != null ? Number(cpuHit.cpu) : null,
    ram: ramHit && ramHit.ram_mb != null ? Number(ramHit.ram_mb) : null,
  };
}

function MonitorTable({ rows, latencySeries }: { rows: ApiMonitor[]; latencySeries: Record<number, number[]> }) {
  const { t } = useLanguage();

  const statusText: Record<MonitorStatus, string> = {
    up: t('common.online', 'Online'),
    down: t('common.offline', 'Offline'),
    warning: t('common.warning', 'Varování'),
    paused: t('common.paused', 'Paused'),
    maintenance: t('common.maintenance', 'Údržba'),
  };

  if (rows.length === 0) {
    return (
      <p className="text-muted-foreground px-5 py-10 text-center text-sm">
        {t('dashboard.no_monitors', 'Žádný monitor neodpovídá filtru.')}
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
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
                    <Link to={`/infrastructure/${monitor.id}`} className="font-medium hover:underline text-foreground">
                      {monitor.name}
                    </Link>
                    <p className="text-muted-foreground text-xs truncate">
                      {monitor.type} · {monitor.target}
                    </p>
                  </div>
                </div>
              </TableCell>
              <TableCell>
                {/* Mockup: stav jako barevný text s tečkou, ne pill badge. */}
                <span className="flex items-center gap-1.5 text-xs font-semibold">
                  <StatusDot variant={monitor.status === 'maintenance' ? 'info' : monitor.status} />
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
                // Agent-side kontrola nemá vlastní CPU/RAM stroje - ukážeme
                // spotřebu JEJÍHO procesu z žebříčků agenta (uživatelský podnět).
                const usage = processUsage(monitor, rows);
                const isProc = (monitor.type || '').toLowerCase() === 'agent_service';
                return (
                  <>
                    <TableCell className="tabular">
                      <ThresholdValue value={usage.cpu} />
                    </TableCell>
                    <TableCell className="tabular">
                      {isProc ? (
                        usage.ram != null ? (
                          <span className="text-muted-foreground">{usage.ram} MB</span>
                        ) : (
                          <span className="text-muted-foreground">—</span>
                        )
                      ) : (
                        <ThresholdValue value={usage.ram} />
                      )}
                    </TableCell>
                  </>
                );
              })()}
              <TableCell className="tabular">
                <ThresholdValue value={monitor.hdd} />
              </TableCell>
              <TableCell className="tabular text-muted-foreground">
                {monitor.uptimeSeconds == null ? '—' : formatUptime(monitor.uptimeSeconds)}
              </TableCell>
              <TableCell className="text-muted-foreground pr-5 text-xs">
                {monitor.lastCheck ? formatRelative(monitor.lastCheck) : '—'}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

function ThresholdValue({ value }: { value: number | null }) {
  if (value == null) return <span className="text-muted-foreground">—</span>;
  return (
    <span className={value >= 80 ? 'text-down font-medium' : value >= 60 ? 'text-warning' : ''}>
      {formatPercent(value)}
    </span>
  );
}
