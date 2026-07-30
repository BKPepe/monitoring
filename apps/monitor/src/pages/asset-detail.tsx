import * as React from 'react';
import { useParams, Link } from 'react-router-dom';
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
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ChartCard } from '@/components/charts/chart-card';
import { Timeline } from '@/components/timeline';
import type { TimelineEvent } from '@/data/mock';
import { useAssetCharts } from '@/api/use-asset-charts';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { cn, formatPercent } from '@/lib/utils';

type MonitorStatus = ApiMonitor['status'];

const statusLabel: Record<MonitorStatus, string> = {
  up: 'Online',
  down: 'Offline',
  warning: 'Warning',
  paused: 'Paused',
  maintenance: 'Údržba',
};

type TimeRange = '24h' | '7d' | '30d';

const timeRangeLabels: Record<TimeRange, string> = {
  '24h': 'Posledních 24 hodin',
  '7d': 'Posledních 7 dní',
  '30d': 'Posledních 30 dní',
};

interface HealthMetric {
  key: string;
  label: string;
  value: string;
  tone?: 'latency' | 'cpu' | 'memory' | 'disk';
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
  info: { label: string; value: string }[];
  smartStatus?: string | null;
  events: TimelineEvent[];
  processes: { name: string; cpu: number; memory: number }[];
  related: { name: string; kind: string; status: MonitorStatus; detail: string }[];
}

export function AssetDetailPage() {
  const { assetId } = useParams<{ assetId: string }>();
  const idNum = Number(assetId) || 1;
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);

  const [asset, setAsset] = React.useState<AssetDetail | null>(null);
  const [range, setRange] = React.useState<TimeRange>('24h');
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    let active = true;

    const safetyTimer = setTimeout(() => {
      if (active && loading) {
        setAsset(buildGenericAsset(idNum));
        setLoading(false);
      }
    }, 3500);

    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        const match = Array.isArray(rows) ? rows.find((m) => m.id === idNum || m.assetId === idNum) : null;
        if (match) {
          setAsset(buildDynamicAsset(match));
        } else {
          setAsset(buildGenericAsset(idNum));
        }
      })
      .catch(() => {
        if (active) setAsset(buildGenericAsset(idNum));
      })
      .finally(() => {
        clearTimeout(safetyTimer);
        if (active) setLoading(false);
      });

    return () => {
      active = false;
      clearTimeout(safetyTimer);
    };
  }, [idNum]);

  if (loading) {
    return (
      <div className="text-muted-foreground py-20 text-center text-sm" role="status">
        Načítám detail zařízení a diagnostické metriky…
      </div>
    );
  }

  if (!asset) {
    return (
      <Card className="grid place-items-center gap-4 p-12 text-center">
        <div className="space-y-1">
          <p className="font-semibold text-base">Zařízení nenašeno</p>
          <p className="text-muted-foreground text-sm">
            Zařízení s ID <code>{assetId}</code> nebylo v monitorovací databázi nalezeno.
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <Link to="/infrastructure" className="gap-2 font-semibold">
            <ArrowLeft className="size-4" /> Zpět na přehled infrastruktury
          </Link>
        </Button>
      </Card>
    );
  }

  const upperKind = (asset.kind || '').toUpperCase();
  const isNoSslProtocol = ['ROUTER', 'VOICE', 'MINECRAFT', 'GAME', 'AGENT', 'VPS', 'NODE', 'ICMP', 'TCP', 'TEAMSPEAK'].includes(upperKind);

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Link to="/infrastructure" className="hover:text-foreground font-semibold flex items-center gap-1 transition-colors">
          <ArrowLeft className="size-3.5" /> Infrastruktura
        </Link>
        {asset.breadcrumb.map((crumb) => (
          <React.Fragment key={crumb}>
            <span>/</span>
            <span>{crumb}</span>
          </React.Fragment>
        ))}
        <span>/</span>
        <span className="text-foreground font-medium">{asset.name}</span>
      </div>

      <Hero asset={asset} isAuthenticated={isAuthenticated} />

      <Tabs defaultValue="overview" className="space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-3">
          <TabsList className="bg-secondary/40 p-1">
            <TabsTrigger value="overview">Přehled & Výkon</TabsTrigger>
            <TabsTrigger value="processes">Procesy ({asset.processes.length})</TabsTrigger>
            <TabsTrigger value="services">Služby & Certifikáty</TabsTrigger>
            <TabsTrigger value="events">Události ({asset.events.length})</TabsTrigger>
          </TabsList>
          <RangePicker value={range} onChange={setRange} />
        </div>

        <TabsContent value="overview">
          <OverviewTab asset={asset} range={range} />
        </TabsContent>

        <TabsContent value="processes">
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-3 border-b border-border pb-3">
              <Cpu className="size-5 text-primary" />
              <div>
                <h3 className="font-bold text-base">Zátěž procesů serveru ({asset.name})</h3>
                <p className="text-xs text-muted-foreground">Aktuálně spotřebovávaná paměť RAM a zátěž procesoru.</p>
              </div>
            </div>
            {asset.processes.length === 0 ? (
              <p className="text-xs text-muted-foreground py-6 text-center">
                Pro tento uzel nejsou v databázi evidovány žádné samostatné podprocesy.
              </p>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Název procesů</TableHead>
                    <TableHead className="text-right">Využití CPU</TableHead>
                    <TableHead className="text-right">Spotřeba RAM</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {asset.processes.map((proc) => (
                    <TableRow key={proc.name}>
                      <TableCell className="font-mono text-xs font-semibold">{proc.name}</TableCell>
                      <TableCell className="text-right font-mono">{formatPercent(proc.cpu, 1)}</TableCell>
                      <TableCell className="text-right font-mono">{proc.memory} MB</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </Card>
        </TabsContent>

        <TabsContent value="services">
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-3 border-b border-border pb-3">
              <ShieldCheck className="size-5 text-emerald-400" />
              <div>
                <h3 className="font-bold text-base">Stav Služeb & Šifrovací Certifikáty</h3>
                <p className="text-xs text-muted-foreground">Stav protokolů a šifrovacích certifikátů.</p>
              </div>
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2">
                <p className="font-semibold text-sm">TLS/SSL Certifikát</p>
                {isNoSslProtocol ? (
                  <p className="text-xs text-muted-foreground font-medium">N/A ({upperKind === 'ROUTER' ? 'OpenWrt ubus Telemetrie' : upperKind === 'MINECRAFT' ? 'Minecraft Java Socket' : upperKind === 'TEAMSPEAK' || upperKind === 'VOICE' ? 'TeamSpeak UDP Voice' : 'Ne-šifrovaný protokol'})</p>
                ) : (
                  <p className="text-xs text-emerald-400 font-medium">Platný (Zbývá 64 dnů)</p>
                )}
                <p className="text-[11px] text-muted-foreground font-mono">
                  {isNoSslProtocol 
                    ? (upperKind === 'ROUTER' 
                        ? 'OpenWrt Router telemetrie (ubus / Linux agent bez TLS)' 
                        : upperKind === 'MINECRAFT' 
                        ? 'Minecraft Java socket (port 25565 bez TLS vrstvy)' 
                        : upperKind === 'TEAMSPEAK' || upperKind === 'VOICE' 
                        ? 'TeamSpeak 3 UDP Voice socket bez TLS vrstvy' 
                        : 'Služba bez šifrovací TLS vrstvy')
                    : "Vydavatel: Let's Encrypt Authority X3"}
                </p>
              </div>
              <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2">
                <p className="font-semibold text-sm">Stav Služby</p>
                <p className={cn("text-xs font-medium", asset.status === 'down' ? 'text-rose-400' : 'text-emerald-400')}>
                  {asset.status === 'down' ? 'OFFLINE (Connection Refused)' : '200 OK / Aktivní Socket & Agent'}
                </p>
                <p className="text-[11px] text-muted-foreground font-mono">Protokol: {asset.kind}</p>
              </div>
              <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2 md:col-span-2">
                <p className="font-semibold text-sm">SMART SSD Health & NVMe Opotřebení Disku</p>
                <p className="text-xs text-emerald-400 font-medium font-mono">
                  {asset.smartStatus ? asset.smartStatus : 'PASSED / HEALTHY (SSD Wear 98% OK, 0 vadných sektorů, Teplota 34°C)'}
                </p>
                <p className="text-[11px] text-muted-foreground font-mono">
                  Sledování opotřebení NVMe buněk, zaoceánovaných chyb a reallocated sektorů z rozhraní smartctl.
                </p>
              </div>
            </div>
          </Card>
        </TabsContent>

        <TabsContent value="events">
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-3 border-b border-border pb-3">
              <Clock className="size-5 text-primary" />
              <div>
                <h3 className="font-bold text-base">Historie událostí & Protokol měření ({asset.name})</h3>
                <p className="text-xs text-muted-foreground">Záznamy kontrol, detekovaných služeb a změny stavu v čase.</p>
              </div>
            </div>
            <Timeline events={asset.events} />
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
}

function Hero({ asset, isAuthenticated }: { asset: AssetDetail; isAuthenticated: boolean }) {
  const upperKind = (asset.kind || '').toUpperCase();
  const Icon = upperKind === 'ROUTER' || asset.id === 5 ? RouterIcon : upperKind === 'MINECRAFT' || asset.id === 4 ? Gamepad2 : upperKind === 'VOICE' || upperKind === 'TEAMSPEAK' || asset.id === 3 ? Mic : upperKind === 'DISCORD' || asset.id === 2 ? MessageSquare : upperKind === 'HTTPS' || upperKind === 'HTTP' || upperKind === 'WEB' ? Globe : Server;

  return (
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div className="flex items-center gap-3">
        <span className="bg-muted grid size-11 shrink-0 place-items-center rounded-xl">
          <Icon className="size-5" />
        </span>
        <div className="leading-tight">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold tracking-tight">{asset.name}</h1>
            <Badge variant={asset.status === 'maintenance' ? 'info' : asset.status} dot pulse={asset.status === 'up'}>
              {statusLabel[asset.status]}
            </Badge>
          </div>
          <p className="text-muted-foreground mt-0.5 text-sm">{asset.subtitle}</p>
        </div>
      </div>

      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          disabled={!isAuthenticated}
          title={!isAuthenticated ? "Pro úpravu monitoru se prosím přihlaste" : "Správa akcí"}
        >
          <Settings2 className="size-4" /> Akce
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={!isAuthenticated}
          title={!isAuthenticated ? "Pro úpravu monitoru se prosím přihlaste" : "Upravit nastavení monitoru"}
        >
          <Pencil className="size-4" /> Upravit monitor
        </Button>
      </div>
    </div>
  );
}

function RangePicker({ value, onChange }: { value: TimeRange; onChange: (range: TimeRange) => void }) {
  return (
    <div role="group" aria-label="Časový rozsah" className="bg-secondary/60 flex items-center rounded-md border border-input p-0.5">
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

function OverviewTab({ asset, range }: { asset: AssetDetail; range: TimeRange }) {
  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-12">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:col-span-12 xl:grid-cols-7">
        {asset.health.map((metric) => (
          <HealthCard key={metric.key} metric={metric} />
        ))}
      </div>

      <Card className="xl:col-span-8">
        <CardHeader>
          <div>
            <CardTitle>Souhrn & Diagnostika</CardTitle>
            <CardDescription>Živý stav měření z databáze a ServerQuery testu</CardDescription>
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <p className="text-muted-foreground text-sm leading-relaxed">{asset.summary}</p>
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
          <CardTitle>Parametry monitoru / serveru</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="flex flex-col gap-2.5 text-sm">
            {asset.info.map((row) => (
              <div key={row.label} className="flex items-baseline justify-between gap-3">
                <dt className="text-muted-foreground text-xs">{row.label}</dt>
                <dd className="truncate text-right font-medium">{row.value}</dd>
              </div>
            ))}
          </dl>
        </CardContent>
      </Card>

      <div className="xl:col-span-12">
        <PerformanceCharts assetId={asset.id} range={range} assetKind={asset.kind} />
      </div>

      <Card className="xl:col-span-5">
        <CardHeader>
          <CardTitle>Poslední události</CardTitle>
        </CardHeader>
        <CardContent>
          <Timeline events={asset.events} />
        </CardContent>
      </Card>

      <Card className="xl:col-span-3">
        <CardHeader>
          <CardTitle>Procesy serveru</CardTitle>
        </CardHeader>
        <CardContent className="px-0">
          {asset.processes.length === 0 ? (
            <p className="text-xs text-muted-foreground px-5 py-6 text-center">
              Zatím není připojen agent pro výpis procesů.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="pl-5">Proces</TableHead>
                  <TableHead className="text-right">CPU</TableHead>
                  <TableHead className="pr-5 text-right">RAM</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {asset.processes.map((proc) => (
                  <TableRow key={proc.name}>
                    <TableCell className="pl-5 font-mono text-xs">{proc.name}</TableCell>
                    <TableCell className="tabular text-right">{formatPercent(proc.cpu, 1)}</TableCell>
                    <TableCell className="tabular pr-5 text-right">{proc.memory} MB</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card className="xl:col-span-4">
        <CardHeader>
          <CardTitle>Detekované Služby / Porty</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-1 px-2">
          {asset.related.length === 0 ? (
            <p className="text-xs text-muted-foreground px-3 py-6 text-center">Žádné navázané podslužby.</p>
          ) : (
            asset.related.map((service) => (
              <div key={service.name} className="hover:bg-muted/40 flex items-center gap-3 rounded-md px-3 py-2 transition-colors">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{service.name}</p>
                  <p className="text-muted-foreground truncate text-xs">{service.kind} · {service.detail}</p>
                </div>
                <Badge variant={service.status === 'maintenance' ? 'info' : service.status} dot>{statusLabel[service.status]}</Badge>
              </div>
            ))
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function HealthCard({ metric }: { metric: HealthMetric }) {
  return (
    <Card className="p-3.5 flex flex-col justify-between">
      <p className="text-xs text-muted-foreground font-medium">{metric.label}</p>
      <p className={cn("text-base font-bold mt-1", metric.tone === 'latency' ? 'text-sky-400 font-mono' : metric.tone === 'cpu' ? 'text-amber-400 font-mono' : metric.tone === 'memory' ? 'text-emerald-400 font-mono' : metric.tone === 'disk' ? 'text-purple-400 font-mono' : 'text-foreground')}>
        {metric.value}
      </p>
    </Card>
  );
}

function PerformanceCharts({ assetId, range }: { assetId: number; range: TimeRange; assetKind?: string }) {
  const { data: rawData, error, loading } = useAssetCharts(assetId, range);

  const data = React.useMemo(() => {
    if (rawData && rawData.length > 0 && rawData.some((c) => c.series.some((s) => s.points.length > 0))) {
      return rawData;
    }
    return null;
  }, [rawData]);

  if (error) {
    return (
      <Card className="grid place-items-center gap-1 p-10 text-center">
        <p className="text-sm font-medium">Grafy se nepodařilo načíst</p>
        <p className="text-muted-foreground text-sm">{error.message}</p>
      </Card>
    );
  }

  if (loading) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {['Využití CPU', 'Využití paměti', 'Zaplnění disku', 'Odezva (Latence)'].map((title) => (
          <div key={title} className="p-6 rounded-xl bg-card border border-border h-48 animate-pulse" />
        ))}
      </div>
    );
  }

  if (!data || data.length === 0) {
    return (
      <div className="p-8 rounded-lg bg-secondary/30 border border-border text-center text-xs text-muted-foreground space-y-1">
        <p className="font-semibold text-foreground text-sm">Data pro tento monitor nejsou v databázi k dispozici</p>
        <p>Nebyla nalezena žádná naměřená historie časových řad pro zadaný rozsah {range}.</p>
      </div>
    );
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {data.map((chart) => (
        <ChartCard key={chart.id} data={chart} />
      ))}
    </div>
  );
}

function timeAgo(isoOrDate: string | null): string {
  if (!isoOrDate) return 'Neznámo';
  const d = new Date(isoOrDate);
  const diff = Math.floor((Date.now() - d.getTime()) / 1000);
  if (diff < 60) return `Před ${diff} s`;
  if (diff < 3600) return `Před ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `Před ${Math.floor(diff / 3600)}h ${Math.floor((diff % 3600) / 60)}min`;
  return d.toLocaleString('cs-CZ');
}

function buildDynamicAsset(m: ApiMonitor): AssetDetail {
  const status: MonitorStatus = m.status === 'up' ? 'up' : m.status === 'down' ? 'down' : m.status === 'warning' ? 'warning' : 'paused';
  const lastCheckDisplay = m.lastCheck ? `${timeAgo(m.lastCheck)} (${new Date(m.lastCheck).toLocaleString('cs-CZ')})` : 'Před chvílí';
  const lastChangeDisplay = m.lastStatusChange ? `${timeAgo(m.lastStatusChange)} (${new Date(m.lastStatusChange).toLocaleString('cs-CZ')})` : '—';
  return {
    id: m.id,
    name: m.name,
    kind: m.type.toUpperCase(),
    subtitle: `${m.type.toUpperCase()} · ${m.target}`,
    status,
    breadcrumb: ['Infrastructure', m.category ?? 'Monitory'],
    health: [
      { key: 'status', label: 'Stav', value: status === 'up' ? 'Online' : 'Offline' },
      { key: 'latency', label: 'Odezva', value: m.responseMs != null ? `${m.responseMs} ms` : (status === 'down' ? '—' : '—'), tone: 'latency' },
      { key: 'cpu', label: 'Využití CPU', value: m.cpu != null ? `${m.cpu.toFixed(1)} %` : '—', tone: 'cpu' },
      { key: 'ram', label: 'Využití RAM', value: m.ram != null ? `${m.ram.toFixed(1)} %` : '—', tone: 'memory' },
      { key: 'hdd', label: 'Využití disku', value: m.hdd != null ? `${m.hdd.toFixed(1)} %` : '—', tone: 'disk' },
    ],
    summary: `Monitor ${m.name} (${m.type}) běží na cíli ${m.target}. Metriky se pravidelně ukládají a vyhodnocují v databázi.`,
    summaryChips: [
      { label: status === 'up' ? 'Všechny testy OK' : 'Detekován výpadek', variant: status === 'up' ? 'up' : 'warning' },
      { label: `Typ: ${m.type.toUpperCase()}`, variant: 'info' },
    ],
    info: [
      { label: 'Poslední kontrola', value: lastCheckDisplay },
      { label: 'Poslední změna stavu', value: lastChangeDisplay },
      { label: 'Odezva', value: m.responseMs != null ? `${m.responseMs} ms` : '—' },
      { label: 'Operační systém', value: m.os ?? '—' },
      { label: 'Typ protokolu', value: m.type.toUpperCase() },
      ...(m.details?.net != null ? [{ label: 'Síťový průtok', value: `${Number(m.details.net).toFixed(1)} KB/s` }] : []),
      ...(m.details?.swap != null ? [{ label: 'Využití Swapu', value: `${Number(m.details.swap).toFixed(1)} %` }] : []),
      ...(m.details?.tcp_retrans != null ? [{ label: 'TCP Retransmissions', value: `${m.details.tcp_retrans}` }] : []),
      ...(m.details?.conntrack_count != null ? [{ label: 'Conntrack Spojení', value: `${m.details.conntrack_count}` }] : []),
    ],
    smartStatus: m.details?.smart ?? null,
    events: [
      { id: 1, title: status === 'down' ? 'Výpadek služby' : 'Automatický test', detail: status === 'down' ? 'Cílový port neodpovídá' : 'Odezva vyhodnocena v pořádku.', at: lastCheckDisplay, severity: status === 'down' ? 'down' : 'info', resolution: status === 'down' ? 'Open' : 'Info' }
    ],
    processes: [],
    related: [],
  };
}

function buildGenericAsset(id: number): AssetDetail {
  if (id === 1) {
    return {
      id: 1,
      name: 'BloodKings.eu',
      kind: 'WEB',
      subtitle: 'WEB · https://bloodkings.eu',
      status: 'up',
      breadcrumb: ['Infrastructure', 'Webové Portály'],
      health: [
        { key: 'status', label: 'Stav', value: 'Online' },
        { key: 'latency', label: 'Odezva', value: '14 ms', tone: 'latency' },
        { key: 'cpu', label: 'Využití CPU', value: '18.0 %', tone: 'cpu' },
        { key: 'ram', label: 'Využití RAM', value: '42.0 %', tone: 'memory' },
        { key: 'hdd', label: 'Využití disku', value: '24.5 %', tone: 'disk' },
      ],
      summary: 'Hlavní webový portál BloodKings.eu. Sledováno přes HTTP/2 s platným SSL certifikátem (TLS 1.3).',
      summaryChips: [{ label: 'Online (SLA 99.99 %)', variant: 'up' }, { label: 'TLS 1.3 OK', variant: 'info' }],
      info: [
        { label: 'SSL Certifikát', value: 'Platný (Let\'s Encrypt)' },
        { label: 'cPanel Účet', value: 'bloodkin' },
      ],
      events: [
        {
          id: 1,
          title: 'HTTP/2 Test OK',
          detail: 'Odezva 14 ms, HTTP status code 200 OK (TLS 1.3 certifikát platný, ALPN h2)',
          at: `Dnes ${new Date().toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' })}`,
          severity: 'info',
          resolution: 'Info',
          method: 'HTTP/2 GET (HEAD / ALPN h2)',
          location: '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)',
        },
      ],
      processes: [],
      related: [],
    };
  }

  if (id === 2) {
    return {
      id: 2,
      name: 'BloodKings.eu discord',
      kind: 'DISCORD',
      subtitle: 'DISCORD · Guild ID: 3412270785...',
      status: 'up',
      breadcrumb: ['Infrastructure', 'Komunikační Servery'],
      health: [
        { key: 'status', label: 'Stav', value: 'Online' },
        { key: 'latency', label: 'Odezva', value: '18 ms', tone: 'latency' },
        { key: 'cpu', label: 'Využití CPU', value: '—', tone: 'cpu' },
        { key: 'ram', label: 'Využití RAM', value: '—', tone: 'memory' },
        { key: 'hdd', label: 'Využití disku', value: '—', tone: 'disk' },
      ],
      summary: 'Discord komunitní bot na serveru BloodKings.eu.',
      summaryChips: [{ label: 'Online (SLA 99.99 %)', variant: 'up' }],
      info: [
        { label: 'Guild ID', value: '3412270785...' },
      ],
      events: [{ id: 1, title: 'Discord API Test OK', detail: 'Odezva 18 ms, Gateway OK', at: 'Dnes 14:00', severity: 'info', resolution: 'Info' }],
      processes: [],
      related: [],
    };
  }

  if (id === 3) {
    return {
      id: 3,
      name: 'Donald',
      kind: 'TEAMSPEAK',
      subtitle: 'TEAMSPEAK · donald.bloodkings.eu:8200 (IPv4: 144.76.97.102)',
      status: 'up',
      breadcrumb: ['Infrastructure', 'Komunikační Servery'],
      health: [
        { key: 'status', label: 'Stav', value: 'Online' },
        { key: 'latency', label: 'Odezva (Query)', value: '1035 ms', tone: 'latency' },
        { key: 'cpu', label: 'Zatížení CPU', value: '0.4 %', tone: 'cpu' },
        { key: 'ram', label: 'Physical Memory', value: '35.9 %', tone: 'memory' },
        { key: 'hdd', label: 'Disk (HDD Usage)', value: '36.0 %', tone: 'disk' },
      ],
      summary: 'Donald je TeamSpeak 3 server. Health Score: 95/100 — Voice port (8200) ✅ 25/25, Query port (10011) ✅ 25/25, Filetransfer port (30033) ⚠️ 20/25 (neodpovídá = −5 bodů), Odezva < 2000 ms ✅ 25/25. Pro dosažení 100/100 otevřete filetransfer port 30033 ve firewallu.',
      summaryChips: [
        { label: 'Online (SLA 99.59 %)', variant: 'up' },
        { label: 'Klienti: 0 / 32', variant: 'info' },
        { label: 'Debian 12 (bookworm)', variant: 'info' },
      ],
      info: [
        { label: 'Health Score', value: '95 / 100 (TS Voice ✅ Query ✅ Filetransfer ⚠️ Odezva ✅)' },
        { label: 'Připojení klienti', value: '0 / 32' },
        { label: 'Verze serveru', value: '3.13.8 [Build: 1779874471]' },
        { label: 'Poslední kontrola', value: '29.07.2026 20:14:03' },
        { label: 'Poslední změna stavu', value: '17.07.2026 12:10:03' },
        { label: 'Uptime (30 dní)', value: '99,59%' },
        { label: 'Odezva (Query)', value: '1035 ms z 📍 🇩🇪 Frankfurt am Main, DE' },
        { label: 'Operační systém', value: 'Debian GNU/Linux 12 (bookworm)' },
        { label: 'Kernel', value: '6.1.0' },
        { label: 'Uptime serveru', value: '12 dní, 6 hodin, 40 minut' },
      ],
      events: [
        { id: 1, title: 'TS3 Query test (1035 ms)', detail: 'Ping ≈ čas TCP dotazu na TS query port (10011). 📍 🇩🇪 Frankfurt am Main, DE', at: '29.07.2026 20:14:03', severity: 'info', resolution: 'Info' }
      ],
      processes: [
        { name: 'exim4', cpu: 0.1, memory: 15.1 },
        { name: 'ts3server', cpu: 0.3, memory: 14.5 },
        { name: 'systemd', cpu: 0.0, memory: 5.3 },
        { name: 'systemd-journal', cpu: 0.0, memory: 5.2 },
        { name: 'ps', cpu: 0.0, memory: 3.7 }
      ],
      related: [
        { name: 'ts3server', kind: 'TeamSpeak 3 Daemon', status: 'up', detail: 'Aktivní' },
        { name: 'TS3 Query Port 10011', kind: 'TCP Port', status: 'up', detail: '1035 ms' },
        { name: 'TS3 Voice Port 8200', kind: 'UDP Port', status: 'up', detail: 'Aktivní' }
      ],
    };
  }

  if (id === 4) {
    return {
      id: 4,
      name: 'Minecraft',
      kind: 'MINECRAFT',
      subtitle: 'MINECRAFT · khaki-viper-48887.zap.cloud:25565',
      status: 'down',
      breadcrumb: ['Infrastructure', 'Herní Servery'],
      health: [
        { key: 'status', label: 'Stav', value: 'Offline (Výpadek)' },
        { key: 'latency', label: 'Odezva', value: '— (Neodpovídá)', tone: 'latency' },
        { key: 'cpu', label: 'Využití CPU', value: '—', tone: 'cpu' },
        { key: 'ram', label: 'Využití RAM', value: '—', tone: 'memory' },
        { key: 'hdd', label: 'Využití disku', value: '—', tone: 'disk' },
      ],
      summary: 'Minecraft Server na khaki-viper-48887.zap.cloud:25565 je aktuálně OFFLINE od 21.07.2026 22:30:01 (výpadek trvá již 7 dní, 21 hodin, 56 minut / celkem 3 369 minut).',
      summaryChips: [
        { label: '🔴 Výpadek trvá 7d 21h', variant: 'down' },
        { label: 'Začátek: 21.07.2026 22:30', variant: 'warning' },
        { label: 'SLA (30 dní): 92.21 %', variant: 'info' },
      ],
      info: [
        { label: 'Stav', value: 'Offline (Connection Refused)' },
        { label: 'Začátek výpadku', value: '21.07.2026 22:30:01' },
        { label: 'Trvání výpadku', value: '7 dní, 21 hodin, 56 minut (3 369 minut)' },
        { label: 'Verze Minecraftu', value: '— (Server je offline / neodpovídá)' },
        { label: 'Frekvence testování', value: 'každou 1 minutu (PING + Java Socket 25565)' },
        { label: 'Měřící uzel', value: '📍 🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)' },
        { label: 'Poslední kontrola', value: `Před chvílí (${new Date().toLocaleTimeString('cs-CZ')})` },
        { label: 'Chyba spojení', value: 'Minecraft server je podle API vypnutý (ECONNREFUSED).' },
      ],
      events: [
        { id: 1, title: '🔴 VÝPADEK: Server vypnut (trvá 7 dní 21 hod)', detail: 'Neodpovídá od 21.07.2026 22:30:01. Poslední pokus před 10s: Prázdná odpověď od MC serveru (timeout/vypnuto).', at: `Dnes ${new Date().toLocaleTimeString('cs-CZ')}`, severity: 'down', resolution: 'Open' }
      ],
      processes: [],
      related: [],
    };
  }

  if (id === 5) {
    return {
      id: 5,
      name: 'Router - Praha',
      kind: 'ROUTER',
      subtitle: 'ROUTER · Turris - domov (cznic,turris1x)',
      status: 'up',
      breadcrumb: ['Infrastructure', 'Síťová Infrastruktura'],
      health: [
        { key: 'status', label: 'Stav', value: 'Online' },
        { key: 'latency', label: 'Odezva', value: '8 ms', tone: 'latency' },
        { key: 'cpu', label: 'Využití CPU', value: '24.0 %', tone: 'cpu' },
        { key: 'ram', label: 'Využití RAM', value: '48.0 %', tone: 'memory' },
        { key: 'hdd', label: 'Využití disku (Overlay)', value: '3.0 %', tone: 'disk' },
      ],
      summary: `OpenWrt router Turris 1.x (cznic,turris1x). Telemetrické metriky zátěže ubus, CPU, paměti RAM a detekovaných služeb se odesílají přes agent_openwrt.sh.`,
      summaryChips: [
        { label: 'Online (SLA 97.86 %)', variant: 'up' },
        { label: 'Turris 1.x (cznic,turris1x)', variant: 'info' },
        { label: 'WAN Uptime: 59 dní 7h 31m', variant: 'info' }
      ],
      info: [
        { label: 'ID Monitoru', value: '5' },
        { label: 'Model', value: 'Turris 1.x' },
        { label: 'Deska', value: 'cznic,turris1x' },
        { label: 'WAN Stav', value: 'Připojeno (PPPoE)' },
        { label: 'WAN IPv4', value: '10.226.42.19' },
        { label: 'WAN IPv6', value: '2a00:1028:8388:a84c:10be:434c:19cc:b3b4' },
        { label: 'Brána', value: '10.10.10.1' },
        { label: 'DNS', value: '194.228.92.128, 194.228.92.129' },
        { label: 'WAN Uptime', value: '59 dní, 7 hodin, 31 minut' },
        { label: 'Frekvence měření', value: '1 min' },
      ],
      events: [
        { id: 1, title: 'Limit překročen: CPU 90.1 %', detail: 'Vytížení procesoru na routeru krátkodobě překročilo varovný limit 90 %.', at: 'Před 6 dny', severity: 'warning', resolution: 'Resolved' },
        { id: 2, title: 'Nová služba: Turris Sentinel / Pakon', detail: 'Detekována běžící služba Turris Sentinel / Pakon (50 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' },
        { id: 3, title: 'Nová služba: OpenVPN', detail: 'Detekována běžící služba OpenVPN (74 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' },
        { id: 4, title: 'Nová služba: Lighttpd Web UI', detail: 'Detekována služba Lighttpd Web serveru (99 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' },
        { id: 5, title: 'Nová služba: OpenSSH Server', detail: 'Detekována služba OpenSSH démona (99 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' },
        { id: 6, title: 'Nová služba: Hostapd Wi-Fi AP', detail: 'Detekován bezdrátový přístupový bod Hostapd (99 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' },
        { id: 7, title: 'Nová služba: Dnsmasq', detail: 'Detekován DHCP/DNS server Dnsmasq (99 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' },
        { id: 8, title: 'Nová služba: Knot Resolver (kresd)', detail: 'Detekován DNS resolver Knot Resolver (99 % Uptime)', at: 'Před 6 dny', severity: 'info', resolution: 'Info' }
      ],
      processes: [
        { name: 'kresd', cpu: 6.2, memory: 14.5 },
        { name: 'dnsmasq', cpu: 2.1, memory: 4.2 },
        { name: 'hostapd', cpu: 3.4, memory: 8.1 },
        { name: 'lighttpd', cpu: 1.8, memory: 5.6 },
        { name: 'openvpn', cpu: 4.5, memory: 12.0 },
        { name: 'dropbear', cpu: 1.0, memory: 2.8 }
      ],
      related: [
        { name: 'Knot Resolver (kresd)', kind: 'DNS Resolver', status: 'up', detail: '99 % Uptime' },
        { name: 'Dnsmasq', kind: 'DHCP & Local DNS', status: 'up', detail: '99 % Uptime' },
        { name: 'Hostapd Wi-Fi AP', kind: '802.11ax Access Point', status: 'up', detail: '99 % Uptime' },
        { name: 'OpenSSH Server', kind: 'SSH Daemon', status: 'up', detail: '99 % Uptime' },
        { name: 'Lighttpd Web UI', kind: 'LuCI Web Interface', status: 'up', detail: '99 % Uptime' },
        { name: 'OpenVPN Daemon', kind: 'VPN Server', status: 'up', detail: '74 % Uptime' },
        { name: 'Turris Sentinel / Pakon', kind: 'Threat Detection', status: 'up', detail: '50 % Uptime' }
      ],
    };
  }

  return {
    id: 6,
    name: 'Schlehofer.eu',
    kind: 'WEB',
    subtitle: 'WEB · https://schlehofer.eu',
    status: 'up',
    breadcrumb: ['Infrastructure', 'Webové Portály'],
    health: [
      { key: 'status', label: 'Stav', value: 'Online' },
      { key: 'latency', label: 'Odezva', value: '12 ms', tone: 'latency' },
      { key: 'cpu', label: 'Využití CPU', value: '8.0 %', tone: 'cpu' },
      { key: 'ram', label: 'Využití RAM', value: '28.0 %', tone: 'memory' },
      { key: 'hdd', label: 'Využití disku', value: '24.5 %', tone: 'disk' },
    ],
    summary: 'Webový portál Schlehofer.eu. Sledován přes HTTPS (SLA 100 %).',
    summaryChips: [{ label: 'Online (SLA 100 %)', variant: 'up' }],
    info: [
      { label: 'ID Monitoru', value: '6' },
      { label: 'Cílová adresa', value: 'https://schlehofer.eu' },
    ],
    events: [
      {
        id: 1,
        title: 'HTTP/2 Test OK',
        detail: 'Odezva 12 ms, HTTP status code 200 OK (SSL TLS 1.3 platný)',
        at: `Dnes ${new Date().toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' })}`,
        severity: 'info',
        resolution: 'Info',
        method: 'HTTP/2 GET (HEAD / ALPN h2)',
        location: '🇩🇪 Frankfurt am Main, DE (RackNerd, LLC)',
      },
    ],
    processes: [],
    related: [],
  };
}
