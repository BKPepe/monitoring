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

  const [asset, setAsset] = React.useState<AssetDetail | null>(null);
  const [range, setRange] = React.useState<TimeRange>('24h');
  const [loading, setLoading] = React.useState(true);

  React.useEffect(() => {
    let active = true;
    setLoading(true);

    // Žádný "safety timer", co po 3.5 s tiše nahradí načítání vymyšleným
    // profilem zařízení - pomalá odpověď má nechat zobrazený loading stav,
    // ne fiktivní SSL/SMART/proces data, která vypadají jako reálná.
    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
        // Odkazy v appce teď vždy používají monitors.id - assetId se zkouší
        // jen jako záložní shoda pro staré odkazy, a teprve když přesná shoda
        // podle id neexistuje (jinak by dva monitory se stejným assetId mohly
        // ukázat na ten nesprávný).
        const match =
          list.find((m: ApiMonitor) => Number(m.id) === idNum) ??
          list.find((m: ApiMonitor) => Number(m.assetId) === idNum);
        setAsset(match ? buildDynamicAsset(match) : null);
      })
      .catch(() => {
        if (active) setAsset(null);
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
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

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Link to="/infrastructure" className="hover:text-foreground font-semibold flex items-center gap-1 transition-colors">
          <ArrowLeft className="size-3.5" /> Infrastruktura
        </Link>
        {asset.breadcrumb.filter(c => c !== 'Infrastructure' && c !== 'Infrastruktura').map((crumb) => (
          <React.Fragment key={crumb}>
            <span>/</span>
            <span>{crumb}</span>
          </React.Fragment>
        ))}
        <span>/</span>
        <span className="text-foreground font-medium">{asset.name}</span>
      </div>

      <Hero asset={asset} />

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

            {(() => {
              const isNoSsl = ['ROUTER', 'VOICE', 'MINECRAFT', 'GAME', 'AGENT', 'VPS', 'NODE', 'ICMP', 'TCP', 'TEAMSPEAK'].includes(upperKind);
              return (
                <div className="grid gap-4 md:grid-cols-2">
                  <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2">
                    <p className="font-semibold text-sm">TLS/SSL Certifikát</p>
                    <p className="text-xs text-muted-foreground font-medium">
                      {isNoSsl ? 'N/A' : 'Kontrola certifikátu zatím není napojená'}
                    </p>
                    <p className="text-[11px] text-muted-foreground font-mono">
                      {isNoSsl
                        ? (upperKind === 'ROUTER'
                            ? 'OpenWrt Router telemetrie (ubus / Linux agent bez TLS)'
                            : upperKind === 'MINECRAFT'
                            ? 'Minecraft Java socket (port 25565 bez TLS vrstvy)'
                            : upperKind === 'TEAMSPEAK' || upperKind === 'VOICE'
                            ? 'TeamSpeak 3 UDP Voice socket bez TLS vrstvy'
                            : 'Protokol nepoužívá SSL/TLS vrstvu')
                        : 'Datum expirace certifikátu se zatím z monitoru nečte.'}
                    </p>
                  </div>
              <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2">
                <p className="font-semibold text-sm">Stav Služby</p>
                <p className={cn("text-xs font-medium", asset.status === 'down' ? 'text-rose-400' : 'text-emerald-400')}>
                  {asset.status === 'down' ? 'OFFLINE' : 'Aktivní'}
                </p>
                <p className="text-[11px] text-muted-foreground font-mono">Protokol: {asset.kind}</p>
              </div>
              <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2 md:col-span-2">
                <p className="font-semibold text-sm">SMART SSD Health & NVMe Opotřebení Disku</p>
                <p className={cn("text-xs font-medium font-mono", asset.smartStatus ? 'text-emerald-400' : 'text-muted-foreground')}>
                  {asset.smartStatus ?? 'Nejsou dostupná data (agent SMART nehlásí).'}
                </p>
                <p className="text-[11px] text-muted-foreground font-mono">
                  Sledování opotřebení NVMe buněk, zaoceánovaných chyb a reallocated sektorů z rozhraní smartctl.
                </p>
              </div>
            </div>
              );
            })()}
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

function Hero({ asset }: { asset: AssetDetail }) {
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
          onClick={() => {
            window.location.href = `/app/infrastructure`;
          }}
          title="Správa akcí a přehled"
        >
          <Settings2 className="size-4" /> Akce
        </Button>
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            window.location.href = `/app/infrastructure?edit=${asset.id}`;
          }}
          title="Upravit nastavení monitoru"
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

  const parsedProcesses: { name: string; cpu: number; memory: number }[] = [];

  if (Array.isArray(m.details?.top_cpu_processes)) {
    for (const p of m.details.top_cpu_processes) {
      if (p && (p.name || p.command)) {
        parsedProcesses.push({
          name: String(p.name || p.command || 'proc'),
          cpu: parseFloat(p.cpu ?? p.cpu_pct ?? 0),
          memory: parseFloat(p.memory ?? p.ram_mb ?? p.memory_pct ?? 0),
        });
      }
    }
  }

  if (Array.isArray(m.details?.top_ram_processes)) {
    for (const p of m.details.top_ram_processes) {
      const name = String(p.name || p.command || 'proc');
      if (p && !parsedProcesses.some(existing => existing.name === name)) {
        parsedProcesses.push({
          name,
          cpu: parseFloat(p.cpu ?? p.cpu_pct ?? 0),
          memory: parseFloat(p.memory ?? p.ram_mb ?? p.memory_pct ?? 0),
        });
      }
    }
  }

  const isTS3 = m.type.toLowerCase().includes('teamspeak') || m.name.toLowerCase().includes('donald') || m.name.toLowerCase().includes('teamspeak');
  const ts3Servers = Array.isArray(m.details?.teamspeak_servers) ? m.details.teamspeak_servers[0] : null;
  const ts3Clients: number | null = m.details?.ts3_clients ?? ts3Servers?.clients_online ?? null;
  const ts3Max: number | null = m.details?.ts3_max ?? ts3Servers?.clients_max ?? null;
  const hasTs3Counts = ts3Clients != null && ts3Max != null;

  return {
    id: m.id,
    name: m.name,
    kind: m.type.toUpperCase(),
    subtitle: `${m.target} · ${m.category ?? 'Monitory'}`,
    status,
    breadcrumb: [m.category ?? 'Monitory'],
    health: [
      { key: 'status', label: 'Stav', value: status === 'up' ? 'Online' : 'Offline' },
      { key: 'latency', label: 'Odezva', value: m.responseMs != null ? `${m.responseMs} ms` : (status === 'down' ? '—' : '—'), tone: 'latency' },
      ...(isTS3 && hasTs3Counts ? [{ key: 'ts3_clients', label: 'Připojení klienti TS3', value: `${ts3Clients} / ${ts3Max} uživatelů`, tone: 'latency' as const }] : []),
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
      ...(isTS3 && hasTs3Counts ? [{ label: 'TeamSpeak 3 ServerQuery', value: `${ts3Clients} / ${ts3Max} uživatelů online (Port 9987/8200)` }] : []),
      ...(m.details?.net != null ? [{ label: 'Síťový průtok (Rx/Tx)', value: `${Number(m.details.net).toFixed(1)} KB/s` }] : []),
      ...(m.details?.disk_read_kb != null ? [{ label: 'Čtení z disku', value: `${Number(m.details.disk_read_kb).toFixed(1)} KB/s` }] : []),
      ...(m.details?.disk_write_kb != null ? [{ label: 'Zápis na disk', value: `${Number(m.details.disk_write_kb).toFixed(1)} KB/s` }] : []),
      ...(m.details?.inode_usage != null ? [{ label: 'Využití Inodů (fs)', value: `${Number(m.details.inode_usage).toFixed(1)} %` }] : []),
      ...(m.details?.swap != null ? [{ label: 'Využití Swapu', value: `${Number(m.details.swap).toFixed(1)} %` }] : []),
      ...(m.details?.tcp_retrans != null ? [{ label: 'TCP Retransmissions (/proc/net/snmp)', value: `${m.details.tcp_retrans}` }] : []),
      ...(m.details?.conntrack_count != null ? [{ label: 'Conntrack Spojení (Sockets)', value: `${m.details.conntrack_count}` }] : []),
    ],
    smartStatus: m.details?.smart ?? null,
    events: [
      { id: 1, title: status === 'down' ? 'Výpadek služby' : 'Automatický test', detail: status === 'down' ? 'Cílový port neodpovídá' : 'Odezva vyhodnocena v pořádku.', at: lastCheckDisplay, severity: status === 'down' ? 'down' : 'info', resolution: status === 'down' ? 'Open' : 'Info' }
    ],
    processes: parsedProcesses,
    related: [],
  };
}
