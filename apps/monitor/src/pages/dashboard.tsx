import * as React from 'react';
import { Link } from 'react-router';
import { Activity, AlertTriangle, Search, ShieldCheck, Signal } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { MetricTile } from '@/components/metric-tile';
import { HealthDonut } from '@/components/health-donut';
import { UptimeHeatmap } from '@/components/uptime-heatmap';
import { overview, type UptimeHistoryRow } from '@/data/mock';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { DataSourceBanner } from '@/components/data-source-banner';
import { usePublicStatus } from '@/api/use-asset-charts';
import { formatMs, formatPercent, formatRelative, formatUptime } from '@/lib/utils';

type MonitorStatus = ApiMonitor['status'];

const badgeVariant: Record<MonitorStatus, 'up' | 'down' | 'warning' | 'paused' | 'info'> = {
  up: 'up',
  down: 'down',
  warning: 'warning',
  paused: 'paused',
  maintenance: 'info',
};

type StatusFilter = 'all' | MonitorStatus;

export function DashboardPage() {
  const { t } = useLanguage();
  const [query, setQuery] = React.useState('');
  const [filter, setFilter] = React.useState<StatusFilter>('all');
  const { data: live } = usePublicStatus();
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
        const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
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

    return () => { active = false; };
  }, [session, live, t]);

  const totalMonitors = monitors.length > 0 ? monitors.length : (live?.totalMonitors ?? overview.totalMonitors);
  const downMonitors = monitors.filter(m => m.status === 'down').length;
  const healthyCount = Math.max(0, totalMonitors - downMonitors);
  // null means the backend genuinely has no 30-day history yet (new install,
  // dead cron) - falling back to the mock's 100.0 would fabricate a perfect
  // SLA nobody measured, so that state is shown as "no data", not a number.
  const uptimeKnown = live ? live.uptimePercent != null : true;
  const uptime = live?.uptimePercent ?? overview.uptime30d;

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
    const alertsList: { id: number; assetId: number; title: string; source: string; severity: 'down' | 'warning' | 'up'; at: string }[] = [];
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

    if (alertsList.length === 0) {
      alertsList.push({
        id: 99,
        assetId: 1,
        title: t('dashboard.all_healthy_title', 'Všechny sledované služby fungují 100% v pořádku'),
        source: t('dashboard.all_healthy_desc', 'Všechny systémy a domény OK'),
        severity: 'up',
        at: new Date().toISOString(),
      });
    }

    return alertsList;
  }, [monitors, t]);

  const [dailyUptimeRows, setDailyUptimeRows] = React.useState<Record<number, { date: string; status: 'up' | 'down' | 'warning' | 'paused'; uptimePct: number }[]>>({});
  const [dailyUptimeError, setDailyUptimeError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=daily_uptime&days=30', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!active || !data?.series) return;
        setDailyUptimeRows(data.series);
        setDailyUptimeError(null);
      })
      .catch(() => {
        if (active) setDailyUptimeError(t('dashboard.uptime_load_error', 'Chyba při načítání denní dostupnosti.'));
      });
    return () => { active = false; };
  }, [t]);

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
        days.push({ date: dateStr, status: 'paused' as const, uptimePct: 0 });
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
      </div>

      <DataSourceBanner />

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
          tone={downMonitors > 0 ? "down" : "up"}
          hint={downMonitors > 0 ? t('dashboard.ongoing_outage', 'Probíhající výpadek') : t('dashboard.no_outages', 'Všechny systémy bez výpadku')}
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
                <TabsTrigger value="all">{t('common.all', 'Vše')} ({monitors.length})</TabsTrigger>
                <TabsTrigger value="up">{t('common.online', 'Online')} ({monitors.filter(m => m.status === 'up').length})</TabsTrigger>
                <TabsTrigger value="warning">{t('common.warning', 'Varování')} ({monitors.filter(m => m.status === 'warning').length})</TabsTrigger>
                <TabsTrigger value="down">{t('common.offline', 'Offline')} ({monitors.filter(m => m.status === 'down').length})</TabsTrigger>
                <TabsTrigger value="paused">{t('common.paused', 'Paused')} ({monitors.filter(m => m.status === 'paused').length})</TabsTrigger>
              </TabsList>

              <TabsContent value={filter} className="mt-0">
                {monitorsError ? (
                  <p className="text-down px-5 py-10 text-center text-sm">{monitorsError}</p>
                ) : monitorsLoading ? (
                  <p className="text-muted-foreground px-5 py-10 text-center text-sm">{t('dashboard.loading_monitors', 'Načítám monitory…')}</p>
                ) : (
                  <MonitorTable rows={visibleMonitors} />
                )}
              </TabsContent>
            </Tabs>
          </CardContent>

          <div className="text-muted-foreground flex items-center justify-between border-t border-border px-5 py-3 text-xs">
            <span>
              {t('dashboard.showing', { shown: visibleMonitors.length, total: monitors.length })}
            </span>
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
                  <span className="text-muted-foreground shrink-0 text-xs">
                    {formatRelative(alert.at)}
                  </span>
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
                  { label: t('common.online', 'Online'), value: monitors.filter(m => m.status === 'up').length, variant: 'up' },
                  { label: t('common.warning', 'Varování'), value: monitors.filter(m => m.status === 'warning').length, variant: 'warning' },
                  { label: t('common.offline', 'Offline'), value: monitors.filter(m => m.status === 'down').length, variant: 'down' },
                  { label: 'Paused', value: monitors.filter(m => m.status === 'paused').length, variant: 'paused' },
                ]}
              />
            </CardContent>
          </Card>
        </div>
      </div>

      <Card className="overflow-visible relative z-20">
        <CardHeader className="flex-row items-center justify-between">
          <div>
            <CardTitle>{t('dashboard.availability_history', 'Historie dostupnosti sledovaných služeb')}</CardTitle>
            <CardDescription>{t('dashboard.availability_30d', 'Sledovaná dostupnost v čase (posledních 30 dní)')}</CardDescription>
          </div>
          <Button variant="outline" size="sm" asChild>
            <Link to="/reports">{t('dashboard.full_report', 'Celý report')}</Link>
          </Button>
        </CardHeader>
        <CardContent className="overflow-visible">
          {dailyUptimeError ? (
            <p className="text-muted-foreground py-8 text-center text-sm">{dailyUptimeError}</p>
          ) : liveUptimeHistory.length === 0 ? (
            <p className="text-muted-foreground py-8 text-center text-sm">{t('dashboard.loading_uptime', 'Načítám historii dostupnosti…')}</p>
          ) : (
            <UptimeHeatmap rows={liveUptimeHistory} />
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function MonitorTable({ rows }: { rows: ApiMonitor[] }) {
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
          {rows.map((monitor) => (
            <TableRow key={monitor.id}>
              <TableCell className="pl-5">
                <div className="leading-tight">
                  <Link to={`/infrastructure/${monitor.id}`} className="font-medium hover:underline text-foreground">
                    {monitor.name}
                  </Link>
                  <p className="text-muted-foreground text-xs">
                    {monitor.type} · {monitor.target}
                  </p>
                </div>
              </TableCell>
              <TableCell>
                <Badge variant={badgeVariant[monitor.status]} dot pulse={monitor.status === 'up'}>
                  {statusText[monitor.status]}
                </Badge>
              </TableCell>
              <TableCell className="tabular">{formatMs(monitor.responseMs)}</TableCell>
              <TableCell className="tabular">
                <ThresholdValue value={monitor.cpu} />
              </TableCell>
              <TableCell className="tabular">
                <ThresholdValue value={monitor.ram} />
              </TableCell>
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
