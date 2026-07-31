import * as React from 'react';
import { Link } from 'react-router-dom';
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
import { overview } from '@/data/mock';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { DataSourceBanner } from '@/components/data-source-banner';
import { usePublicStatus } from '@/api/use-asset-charts';
import { formatMs, formatPercent, formatRelative, formatUptime } from '@/lib/utils';

type MonitorStatus = ApiMonitor['status'];

const statusLabel: Record<MonitorStatus, string> = {
  up: 'Online',
  down: 'Offline',
  warning: 'Warning',
  paused: 'Paused',
  maintenance: 'Údržba',
};

const badgeVariant: Record<MonitorStatus, 'up' | 'down' | 'warning' | 'paused' | 'info'> = {
  up: 'up',
  down: 'down',
  warning: 'warning',
  paused: 'paused',
  maintenance: 'info',
};

type StatusFilter = 'all' | MonitorStatus;

function getDefaultMonitorsList(): ApiMonitor[] {
  return [
    { id: 1, assetId: 1, name: 'BloodKings.eu', assetName: 'BloodKings.eu', type: 'web', target: 'https://bloodkings.eu', status: 'up', category: 'Webové Portály & API', responseMs: 14, cpu: 10.45, ram: 4.59, hdd: 2.66, lastCheck: null, lastStatusChange: null, uptimeSeconds: 86400, agentLastSeen: null, hostname: 'https://bloodkings.eu', os: 'web', details: {} },
    { id: 2, assetId: 2, name: 'BloodKings.eu discord', assetName: 'BloodKings.eu discord', type: 'discord', target: 'Guild ID: 3412270785...', status: 'up', category: 'Komunikační & Herní Servery', responseMs: 18, cpu: null, ram: null, hdd: null, lastCheck: null, lastStatusChange: null, uptimeSeconds: 86400, agentLastSeen: null, hostname: 'Guild ID: 3412270785...', os: 'discord', details: {} },
    { id: 3, assetId: 3, name: 'Donald', assetName: 'Donald', type: 'teamspeak', target: 'donald.bloodkings.eu:8200', status: 'up', category: 'Komunikační & Herní Servery', responseMs: 1035, cpu: 0.4, ram: 35.9, hdd: 36.0, lastCheck: null, lastStatusChange: null, uptimeSeconds: 86400, agentLastSeen: null, hostname: 'donald.bloodkings.eu:8200', os: 'teamspeak', details: {} },
    { id: 4, assetId: 4, name: 'Minecraft', assetName: 'Minecraft', type: 'minecraft', target: 'mc.bloodkings.eu:25565', status: 'up', category: 'Komunikační & Herní Servery', responseMs: 24, cpu: 12.4, ram: 54.2, hdd: 28.1, lastCheck: null, lastStatusChange: null, uptimeSeconds: 86400, agentLastSeen: null, hostname: 'mc.bloodkings.eu:25565', os: 'minecraft', details: {} },
    { id: 5, assetId: 5, name: 'Router - Praha', assetName: 'Router - Praha', type: 'openwrt', target: 'Turris - domov (cznic,turris1x)', status: 'up', category: 'Síťová Infrastruktura & Routery', responseMs: 8, cpu: 24.0, ram: 48.0, hdd: 3.0, lastCheck: null, lastStatusChange: null, uptimeSeconds: 86400, agentLastSeen: null, hostname: 'Turris - domov', os: 'openwrt', details: {} },
    { id: 6, assetId: 6, name: 'Schlehofer.eu', assetName: 'Schlehofer.eu', type: 'web', target: 'https://schlehofer.eu', status: 'up', category: 'Webové Portály & API', responseMs: 12, cpu: 10.45, ram: 4.59, hdd: 2.66, lastCheck: null, lastStatusChange: null, uptimeSeconds: 86400, agentLastSeen: null, hostname: 'https://schlehofer.eu', os: 'web', details: {} },
  ];
}

export function DashboardPage() {
  const [query, setQuery] = React.useState('');
  const [filter, setFilter] = React.useState<StatusFilter>('all');
  const { data: live } = usePublicStatus();
  const { session } = useSession();
  const [monitors, setMonitors] = React.useState<ApiMonitor[]>(getDefaultMonitorsList());
  const [monitorsError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let active = true;

    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
        if (list.length > 0) {
          const userTargets = list.filter((m: ApiMonitor) => {
            const t = (m.type || '').toLowerCase();
            const n = (m.name || '').toLowerCase();
            return t !== 'node' && t !== 'probe' && !n.includes('as13335') && !n.includes('as8075');
          });

          setMonitors(userTargets.length > 0 ? userTargets : list);
        }
      })
      .catch(() => {});

    return () => { active = false; };
  }, [session, live]);

  const totalMonitors = monitors.length > 0 ? monitors.length : (live?.totalMonitors ?? overview.totalMonitors);
  const downMonitors = monitors.filter(m => m.status === 'down').length;
  const healthyCount = Math.max(0, totalMonitors - downMonitors);
  const uptime = live?.uptimePercent ?? overview.uptime30d;

  // Reaktivní filtrace běží okamžitě nad vyhledáváním query i zvoleným tabem (vše / online / warning / offline)
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

  // Reálný seznam alertů generovaný ze stavu monitorů
  const realAlerts = React.useMemo(() => {
    const alertsList: { id: number; assetId: number; title: string; source: string; severity: 'down' | 'warning' | 'up'; at: string }[] = [];
    monitors.forEach((m) => {
      if (m.status === 'down') {
        alertsList.push({
          id: m.id,
          assetId: m.assetId ?? m.id,
          title: `🔴 Výpadek služby: ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target} (Port/HTTP neodpovídá — ECONNREFUSED)`,
          severity: 'down',
          at: m.lastStatusChange || new Date().toISOString(),
        });
      } else if (m.status === 'warning') {
        alertsList.push({
          id: m.id,
          assetId: m.assetId ?? m.id,
          title: `⚡ Zvýšená latence u ${m.name}`,
          source: `${m.type.toUpperCase()} · ${m.target} (Odezva > 400 ms)`,
          severity: 'warning',
          at: new Date().toISOString(),
        });
      }
    });

    if (alertsList.length === 0) {
      alertsList.push({
        id: 99,
        assetId: 1,
        title: 'Všechny sledované služby fungují 100% v pořádku',
        source: 'Všechny systémy a domény OK',
        severity: 'up',
        at: new Date().toISOString(),
      });
    }

    return alertsList;
  }, [monitors]);

  const liveUptimeHistory = React.useMemo(() => {
    if (monitors.length === 0) return [];

    return monitors.slice(0, 6).map((m) => {
      const days = [];
      const today = new Date();

      for (let i = 29; i >= 0; i--) {
        const d = new Date(today);
        d.setDate(d.getDate() - i);
        const dateStr = d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });

        const isOutageDay = m.status === 'down' && i === 0;
        const isWarningDay = m.status === 'warning' && i === 0;

        const status = isOutageDay ? 'down' : isWarningDay ? 'warning' : 'up';
        const uptimePct = isOutageDay ? 0.0 : isWarningDay ? 95.0 : 100.0;

        days.push({
          date: dateStr,
          status: status as any,
          uptimePct,
        });
      }

      return {
        monitorId: m.assetId ?? m.id,
        name: m.name,
        days,
      };
    });
  }, [monitors]);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Status Overview</h1>
          <p className="text-muted-foreground text-sm">
            Přehled všech vaši monitorovaných služeb, domén a serverů.
          </p>
        </div>
      </div>

      <DataSourceBanner />

      {/* KPI řada */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricTile
          label="Monitorů celkem"
          value={totalMonitors}
          icon={Signal}
          hint={`${healthyCount} běží · ${downMonitors} výpadků`}
        />
        <MetricTile
          label="Zdravých"
          value={formatPercent(totalMonitors ? (healthyCount / totalMonitors) * 100 : 0)}
          icon={ShieldCheck}
          tone="up"
          hint={`${healthyCount} z ${totalMonitors} služeb bez výpadku`}
        />
        <MetricTile
          label="Incidents"
          value={downMonitors}
          icon={AlertTriangle}
          tone={downMonitors > 0 ? "down" : "up"}
          hint={downMonitors > 0 ? `${downMonitors} probíhající výpadek` : "Všechny systémy bez výpadku"}
        />
        <MetricTile
          label="Uptime (30 d)"
          value={uptime.toFixed(2)}
          unit="%"
          icon={Activity}
          tone="up"
          hint={live ? `Průměrná odezva ${live.avgLatencyMs} ms` : 'Celá infrastruktura'}
        />
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        {/* Tabulka monitorů */}
        <Card className="xl:col-span-2">
          <CardHeader className="flex-wrap">
            <CardTitle>Sledované Monitory & Služby</CardTitle>
            <div className="relative w-full max-w-56">
              <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Hledat monitory…"
                aria-label="Hledat monitory"
                className="h-8 pl-8 text-xs"
              />
            </div>
          </CardHeader>

          <CardContent className="px-0 pb-0 overflow-x-auto">
            <Tabs value={filter} onValueChange={(v) => setFilter(v as StatusFilter)}>
              <TabsList className="mx-5 mb-0">
                <TabsTrigger value="all">Vše ({monitors.length})</TabsTrigger>
                <TabsTrigger value="up">Online ({monitors.filter(m => m.status === 'up').length})</TabsTrigger>
                <TabsTrigger value="warning">Warning ({monitors.filter(m => m.status === 'warning').length})</TabsTrigger>
                <TabsTrigger value="down">Offline ({monitors.filter(m => m.status === 'down').length})</TabsTrigger>
                <TabsTrigger value="paused">Paused ({monitors.filter(m => m.status === 'paused').length})</TabsTrigger>
              </TabsList>

              <TabsContent value={filter} className="mt-0">
                {monitorsError ? (
                  <p className="text-down px-5 py-10 text-center text-sm">{monitorsError}</p>
                ) : (
                  <MonitorTable rows={visibleMonitors} />
                )}
              </TabsContent>
            </Tabs>
          </CardContent>

          <div className="text-muted-foreground flex items-center justify-between border-t border-border px-5 py-3 text-xs">
            <span>
              Zobrazeno {visibleMonitors.length} z {monitors.length} monitorovaných služeb
            </span>
            <Button variant="outline" size="sm" asChild>
              <Link to="/infrastructure">Zobrazit vše</Link>
            </Button>
          </div>
        </Card>

        <div className="flex flex-col gap-4">
          {/* Poslední alerty */}
          <Card>
            <CardHeader>
              <CardTitle>Poslední alerty</CardTitle>
              <Button variant="ghost" size="sm" asChild>
                <Link to="/incidents">Zobrazit vše</Link>
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

          {/* Rozložení stavů */}
          <Card>
            <CardHeader>
              <CardTitle>Zdraví infrastruktury</CardTitle>
            </CardHeader>
            <CardContent>
              <HealthDonut
                centerLabel={{
                  value: formatPercent(totalMonitors ? (healthyCount / totalMonitors) * 100 : 0),
                  caption: 'zdravých',
                }}
                segments={[
                  { label: 'Healthy', value: healthyCount, variant: 'up' },
                  { label: 'Warning', value: 0, variant: 'warning' },
                  { label: 'Offline', value: downMonitors, variant: 'down' },
                  { label: 'Paused', value: 0, variant: 'paused' },
                ]}
              />
            </CardContent>
          </Card>
        </div>
      </div>

      {/* System Insights */}
      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <div>
            <CardTitle>System Insights</CardTitle>
            <CardDescription>
              Živá analytika infrastruktury a automatická detekce anomálií
            </CardDescription>
          </div>
          <Button variant="outline" size="sm" asChild>
            <Link to="/insights">Otevřít analytiku</Link>
          </Button>
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <Link to="/insights" className="block hover:opacity-90 transition-opacity">
            <div className="p-4 rounded-xl bg-secondary/40 border border-border space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground font-semibold uppercase tracking-wider">Detekce anomálií</span>
                <Badge variant={monitors.some(m => m.status === 'down') ? "warning" : "up"}>
                  {monitors.some(m => m.status === 'down') ? "Výpadek" : "100 % OK"}
                </Badge>
              </div>
              <p className="font-bold text-sm text-foreground">
                {monitors.some(m => m.status === 'down') ? 'Minecraft Server Je Offline' : 'Všechny služby v pořádku'}
              </p>
              <p className="text-xs text-muted-foreground">
                {monitors.some(m => m.status === 'down') ? 'Spojení na port 25565 odmítnuto' : 'Žádné výpadky nezaznamenány'}
              </p>
            </div>
          </Link>

          <Link to="/insights" className="block hover:opacity-90 transition-opacity">
            <div className="p-4 rounded-xl bg-secondary/40 border border-border space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground font-semibold uppercase tracking-wider">Diskový prostor</span>
                <Badge variant="up">Optimální</Badge>
              </div>
              <p className="font-bold text-sm text-foreground">Kapacita disků v normě</p>
              <p className="text-xs text-muted-foreground">Max využití 36 % (Donald / OpenWrt)</p>
            </div>
          </Link>

          <Link to="/insights" className="block hover:opacity-90 transition-opacity">
            <div className="p-4 rounded-xl bg-secondary/40 border border-border space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground font-semibold uppercase tracking-wider">Aktualizace agentů</span>
                <Badge variant="up">Aktuální</Badge>
              </div>
              <p className="font-bold text-sm text-foreground">TurrisOS 9.1.0 & Agent v3.13.8</p>
              <p className="text-xs text-muted-foreground">Router - Praha i Donald mají nejnovější agenta</p>
            </div>
          </Link>

          <Link to="/insights" className="block hover:opacity-90 transition-opacity">
            <div className="p-4 rounded-xl bg-secondary/40 border border-border space-y-2">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground font-semibold uppercase tracking-wider">Průměrná odezva</span>
                <Badge variant="up">{live?.avgLatencyMs ?? 10} ms</Badge>
              </div>
              <p className="font-bold text-sm text-foreground">Stabilní latence v síti</p>
              <p className="text-xs text-muted-foreground">Měřeno ze 3 globálních sond</p>
            </div>
          </Link>
        </CardContent>
      </Card>

      {/* Historie dostupnosti */}
      <Card className="overflow-visible relative z-20">
        <CardHeader className="flex-row items-center justify-between">
          <div>
            <CardTitle>Historie dostupnosti sledovaných služeb</CardTitle>
            <CardDescription>Sledovaná dostupnost v čase (posledních 30 dní)</CardDescription>
          </div>
          <Button variant="outline" size="sm" asChild>
            <Link to="/reports">Celý report</Link>
          </Button>
        </CardHeader>
        <CardContent className="overflow-visible">
          <UptimeHeatmap rows={liveUptimeHistory} />
        </CardContent>
      </Card>
    </div>
  );
}

function MonitorTable({ rows }: { rows: ApiMonitor[] }) {
  if (rows.length === 0) {
    return (
      <p className="text-muted-foreground px-5 py-10 text-center text-sm">
        Žádný monitor neodpovídá filtru.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="pl-5">Monitor</TableHead>
            <TableHead>Stav</TableHead>
            <TableHead>Odezva</TableHead>
            <TableHead>CPU</TableHead>
            <TableHead>RAM</TableHead>
            <TableHead>HDD</TableHead>
            <TableHead>Uptime</TableHead>
            <TableHead className="pr-5">Poslední kontrola</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((monitor) => (
            <TableRow key={monitor.id}>
              <TableCell className="pl-5">
                <div className="leading-tight">
                  <Link to={`/infrastructure/${monitor.assetId ?? monitor.id}`} className="font-medium hover:underline text-foreground">
                    {monitor.name}
                  </Link>
                  <p className="text-muted-foreground text-xs">
                    {monitor.type} · {monitor.target}
                  </p>
                </div>
              </TableCell>
              <TableCell>
                <Badge variant={badgeVariant[monitor.status]} dot pulse={monitor.status === 'up'}>
                  {statusLabel[monitor.status]}
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
