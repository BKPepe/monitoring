import * as React from 'react';
import { useParams, Link } from 'react-router';
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
import { Sparkline } from '@/components/sparkline';
import { computeSeriesDelta, goodDirectionFor } from '@/components/charts/series-delta';
import type { ChartData, MetricSeries } from '@/api/types';
import { Timeline } from '@/components/timeline';
import type { TimelineEvent } from '@/data/model';
import { useAssetCharts } from '@/api/use-asset-charts';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import { cn, formatPercent, formatUptime } from '@/lib/utils';
import { RouterServices } from '@/components/router-services';

type MonitorStatus = ApiMonitor['status'];

type TimeRange = '24h' | '7d' | '30d';

interface HealthMetric {
  key: string;
  label: string;
  value: string;
  tone?: 'latency' | 'cpu' | 'memory' | 'disk' | 'temperature';
  /** Mini průběh za zvolené období (z už načtených dat grafů - žádný extra fetch). */
  series?: number[];
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
  info: { label: string; value: string }[];
  smartStatus?: string | null;
  cpanelStats?: Record<string, { formatted?: string }> | null;
  cpanelStatsError?: { error?: string; hint?: string | null; since?: string } | null;
  collectionIssues: { type: string; message: string; hint?: string | null; since: string | null }[];
  /** Syrová details z posledního reportu - Network tab z nich čte OpenWrt telemetrii. */
  rawDetails: Record<string, any>;
  /** Remote Actions - jen pro admin session (API ta pole jinak neposílá). */
  remoteActionsEnabled: boolean;
  allowedActions: string[];
  monitoredProcesses: string | null;
  sslCert?: { days_remaining?: number | null; issuer?: string | null; valid_to?: string | null } | null;
  events: TimelineEvent[];
  /** Sloučené top-CPU + top-RAM procesy z agenta; null = agent tu dimenzi u procesu nehlásí. */
  processes: { name: string; cpu: number | null; memory: number | null }[];
  related: { name: string; kind: string; status: MonitorStatus; detail: string }[];
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
  const [range, setRange] = React.useState<TimeRange>('24h');
  const [loading, setLoading] = React.useState(true);
  const [events, setEvents] = React.useState<TimelineEvent[]>([]);
  const [serverInsights, setServerInsights] = React.useState<ServerInsights | null>(null);

  React.useEffect(() => {
    let active = true;
    setLoading(true);

    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        const match =
          list.find((m: ApiMonitor) => Number(m.id) === idNum) ??
          list.find((m: ApiMonitor) => Number(m.assetId) === idNum);
        setAsset(match ? buildDynamicAsset(match, t) : null);
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
  }, [idNum, t]);

  // Efekty níže se váží na ID assetu, ne na objekt - refresh objektu se
  // stejným ID nemá znovu stahovat události ani insights.
  const loadedAssetId = asset?.id;

  React.useEffect(() => {
    if (!loadedAssetId) return;
    let active = true;

    fetch(`/status/api.php?action=events&monitor_id=${loadedAssetId}&limit=30`, { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (!active || !data || !Array.isArray(data.events)) return;
        setEvents(
          data.events.map((e: any) => ({
            id: e.id,
            title: e.isDown
              ? t('asset.event_outage', 'Výpadek služby')
              : e.rawStatus === 'warning'
                ? t('asset.event_degraded', 'Zhoršená odezva')
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
            severity: e.isDown ? 'down' : e.rawStatus === 'warning' ? 'warning' : 'info',
            resolution: e.isDown ? 'Open' : 'Info',
            location: e.location,
            method: e.type,
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

  if (loading) {
    return (
      <div className="text-muted-foreground py-20 text-center text-sm" role="status">
        {t('asset.loading', 'Načítám detail zařízení a diagnostické metriky…')}
      </div>
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
  // Router má vlastní podobu sekce Služby - TLS certifikát u něj nedává smysl.
  const isRouter = upperKind === 'ROUTER' || upperKind === 'OPENWRT';

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

      <Hero asset={asset} />

      <Tabs defaultValue="overview" className="space-y-6">
        {/* Sticky pod headerem (h-16): u dlouhého detailu jsou záložky a
            přepínač rozsahu pořád po ruce, bez scrollování zpět nahoru. */}
        <div className="bg-background/95 sticky top-16 z-20 -mx-1 flex flex-wrap items-center justify-between gap-4 border-b border-border px-1 pb-3 pt-1 backdrop-blur-sm">
          <TabsList className="bg-secondary/40 p-1">
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
        </div>

        <TabsContent value="overview">
          <OverviewTab asset={asset} range={range} events={events} serverInsights={serverInsights} />
        </TabsContent>

        {hasNetworkData(asset.rawDetails) && (
          <TabsContent value="network">
            <NetworkTab d={asset.rawDetails} />
          </TabsContent>
        )}

        <TabsContent value="processes">
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
              <div className="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/25 text-xs space-y-1">
                <p className="font-bold text-emerald-800 dark:text-emerald-300">
                  🎙 {t('asset.ts3_process_title', 'Proces ts3server')}
                </p>
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
                {/* Mobil: proces na řádek s hodnotami pod názvem. */}
                <div className="flex flex-col gap-1.5 md:hidden">
                  {asset.processes.map((proc) => (
                    <div key={proc.name} className="rounded-lg border border-border px-3 py-2">
                      <p className="truncate font-mono text-xs font-semibold">{proc.name}</p>
                      <div className="text-muted-foreground mt-0.5 flex gap-4 font-mono text-[11px]">
                        <span>CPU {formatPercent(proc.cpu, 1)}</span>
                        {/* Nezměřená paměť = pomlčka, ne holé "MB". */}
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
              <ShieldCheck className="size-5 text-emerald-400" />
              <div>
                <h3 className="font-bold text-base">
                  {isRouter
                    ? t('asset.router_services_title', 'Síťové služby routeru')
                    : t('asset.services_title', 'Stav Služeb & Šifrovací Certifikáty')}
                </h3>
                <p className="text-xs text-muted-foreground">
                  {isRouter
                    ? t('asset.router_services_desc', 'Konektivita, DNS, firewall, Wi-Fi a VPN podle dat od agenta.')
                    : t('asset.services_desc', 'Stav protokolů a šifrovacích certifikátů.')}
                </p>
              </div>
            </div>

            {/* Router žádný web necertifikuje - karta s TLS certifikátem tu
                jen zabírala místo. Místo toho se ukazuje, co router má. */}
            {isRouter && <RouterServices d={asset.rawDetails ?? {}} />}

            {!isRouter &&
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
                          <p className="text-[11px] text-muted-foreground font-mono">
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
                                ? 'text-amber-400'
                                : (asset.sslCert.days_remaining ?? 99) <= 0
                                  ? 'text-rose-400'
                                  : 'text-emerald-400'
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
                          <div className="text-[11px] text-muted-foreground font-mono space-y-0.5 pt-1 border-t border-border/40">
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
                          {/* Bez dat certifikátu se nic netvrdí - dřív tu svítilo
                            "TLS 1.3 ověřeno" i u monitoru, kde žádný certifikát
                            neexistuje (hlášeno u OpenWrt routeru). */}
                          <p className="text-xs font-medium text-muted-foreground">
                            {t('asset.ssl_unknown', 'Certifikát zatím nebyl načten')}
                          </p>
                          <p className="text-[11px] text-muted-foreground font-mono">
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
                      <p
                        className={cn(
                          'text-xs font-medium',
                          asset.status === 'down' ? 'text-rose-400' : 'text-emerald-400'
                        )}
                      >
                        {asset.status === 'down' ? t('common.offline', 'Offline') : t('infra.active_since', 'Aktivní')}
                      </p>
                      <p className="text-[11px] text-muted-foreground font-mono">
                        {t('common.protocol', 'Protokol')}: {asset.kind}
                      </p>
                    </div>
                    <div className="p-4 rounded-lg bg-secondary/40 border border-border space-y-2 md:col-span-2">
                      <p className="font-semibold text-sm">
                        {t('asset.smart_status', 'SMART SSD Health & NVMe Opotřebení Disku')}
                      </p>
                      {(() => {
                        // "N/A (smartctl chybí)" není zdravý stav - je to chybějící
                        // nástroj a admin má vědět, co doinstalovat.
                        const raw = asset.smartStatus ?? null;
                        const missingTool = !raw || /n\/a|chyb|not available|unavailable|missing/i.test(raw);
                        return (
                          <>
                            <p
                              className={cn(
                                'text-xs font-medium font-mono',
                                missingTool
                                  ? 'text-amber-700 dark:text-amber-400'
                                  : 'text-emerald-600 dark:text-emerald-400'
                              )}
                            >
                              {raw ?? t('asset.smart_no_data', 'Nejsou dostupná data (agent SMART nehlásí).')}
                            </p>
                            <p className="text-[11px] text-muted-foreground font-mono">
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
                  </div>
                );
              })()}
          </Card>
        </TabsContent>

        <TabsContent value="events">
          <div className="space-y-4">
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
    paused: t('common.paused', 'Paused'),
    maintenance: t('common.maintenance', 'Údržba'),
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
            <Badge variant={asset.status === 'maintenance' ? 'info' : asset.status} dot pulse={asset.status === 'up'}>
              {statusText[asset.status]}
            </Badge>
          </div>
          <p className="text-muted-foreground mt-0.5 text-sm">{asset.subtitle}</p>
        </div>
      </div>

      <div className="flex items-center gap-2">
        {asset.remoteActionsEnabled && asset.allowedActions.length > 0 && <ActionsMenu asset={asset} />}
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
}: {
  asset: AssetDetail;
  range: TimeRange;
  events: TimelineEvent[];
  serverInsights: ServerInsights | null;
}) {
  const { t } = useLanguage();
  // Jeden fetch grafů pro celý tab: stejná data živí velké grafy dole
  // i sparkliny v KPI dlaždicích nahoře (mockup: hodnota + delta + průběh).
  const charts = useAssetCharts(asset.id, range);

  const healthWithTrends = React.useMemo<HealthMetric[]>(() => {
    const byTone = new Map<string, MetricSeries>();
    for (const chart of charts.data ?? []) {
      const s = chart.series[0];
      if (s && !byTone.has(s.tone)) byTone.set(s.tone, s);
    }
    return asset.health.map((m) => {
      const s = m.tone ? byTone.get(m.tone) : undefined;
      if (!s) return m;
      const values = s.points.map((p) => p.v).filter((v): v is number => v != null);
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
      {/* Data-collection outages must be the first thing on the page - the
          charts below silently missing data without this context is exactly
          the failure mode this project forbids. */}
      {asset.collectionIssues.length > 0 && (
        <div role="alert" className="xl:col-span-12 rounded-lg border-2 border-down/60 bg-down/10 p-4 space-y-1.5">
          <p className="font-bold text-sm text-down">⛔ {t('collection.heading', 'Výpadek sběru dat')}</p>
          {asset.collectionIssues.map((issue, i) => (
            <div key={`${issue.type}-${i}`} className="text-xs space-y-0.5">
              <p>
                <span className="text-down font-medium">{issue.message}</span>
                {issue.since && (
                  <span className="text-muted-foreground font-mono ml-2">
                    ({t('collection.since', 'od')} {new Date(issue.since).toLocaleString('cs-CZ')})
                  </span>
                )}
              </p>
              {issue.hint && <p className="text-muted-foreground">💡 {issue.hint}</p>}
            </div>
          ))}
        </div>
      )}

      <div className="grid gap-3 xl:col-span-12 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
        {serverInsights?.healthScore && <HealthScoreTile score={serverInsights.healthScore.score} />}
        {healthWithTrends.map((metric) => (
          <HealthCard key={metric.key} metric={metric} />
        ))}
      </div>

      <Card className="xl:col-span-8">
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2 w-full">
            <div>
              <CardTitle>{t('asset.summary_title', 'Executive Summary')}</CardTitle>
              <CardDescription>{t('asset.summary_desc', 'Živý stav měření z databáze')}</CardDescription>
            </div>
            {/* Health Score má vlastní dlaždici hned nad kartou - badge tu
                byl duplicitní. */}
          </div>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          {/* The server summary (bk_build_executive_summary) knows about health
              score, threshold breaches, and insights - the client-built
              asset.summary is only a generic template sentence, kept as a
              fallback until the endpoint responds. */}
          <p className="text-muted-foreground text-sm leading-relaxed">{serverInsights?.summary || asset.summary}</p>

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
            <ul className="flex flex-col gap-1 text-xs text-muted-foreground border-t border-border pt-3">
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
        <PerformanceCharts
          data={charts.data}
          error={charts.error}
          loading={charts.loading}
          range={range}
          events={events}
        />
      </div>

      <Card className="xl:col-span-5">
        <CardHeader>
          <CardTitle>{t('asset.recent_events', 'Poslední události')}</CardTitle>
        </CardHeader>
        <CardContent>
          <Timeline events={events.slice(0, 5)} />
        </CardContent>
      </Card>

      <Card className="xl:col-span-3">
        <CardHeader>
          <CardTitle>{t('asset.tab_processes', 'Nejvytíženější procesy')}</CardTitle>
        </CardHeader>
        <CardContent className="px-0">
          {asset.processes.length > 0 && (
            <p className="text-[11px] text-muted-foreground px-5 pb-2">
              {t(
                'asset.processes_top_hint',
                'Agent hlásí 5 nejnáročnějších procesů podle CPU a 5 podle RAM z posledního reportu — není to kompletní výpis všeho, co na stroji běží.'
              )}
            </p>
          )}
          {asset.processes.length === 0 ? (
            <p className="text-xs text-muted-foreground px-5 py-6 text-center">
              {asset.cpanelStats
                ? t(
                    'asset.no_agent_cpanel_hint',
                    'Bez VPS agenta - podrobnosti o zdrojích cPanelu jsou na záložce Procesy.'
                  )
                : t('asset.no_agent_processes', 'Zatím není připojen agent pro výpis procesů.')}
            </p>
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
                    <TableCell className="tabular text-right">
                      {proc.cpu != null ? formatPercent(proc.cpu, 1) : '—'}
                    </TableCell>
                    <TableCell className="tabular pr-5 text-right">
                      {proc.memory != null ? `${proc.memory} MB` : '—'}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      <Card className="xl:col-span-4">
        <CardHeader>
          <CardTitle>{t('asset.detected_services', 'Detekované Služby / Porty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-1 px-2">
          {/* Kromě navázaných monitorů ukazujeme i to, co agent reálně hlásí:
              naslouchající porty a objevené (zatím nesledované) služby -
              dosud tahle data ležela v reportu bez využití. */}
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
                      <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                        {t('asset.agent_found_services', 'Agent objevil běžící služby')}
                      </p>
                      {found.map((s, i) => (
                        <div key={i} className="flex items-center justify-between gap-2 text-xs">
                          <span className="font-medium truncate">
                            {s.name}
                            {s.port ? <span className="text-muted-foreground font-mono">:{s.port}</span> : null}
                          </span>
                          <span className="text-muted-foreground font-mono shrink-0">
                            {s.confidence != null ? `${s.confidence} %` : ''}
                          </span>
                        </div>
                      ))}
                      <p className="text-[10px] text-muted-foreground">
                        {t('asset.agent_found_hint', 'Sledovat je můžete jedním kliknutím v přehledu Infrastruktura.')}
                      </p>
                    </div>
                  )}
                  {ports.length > 0 && (
                    <div className="space-y-1.5">
                      <p className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wide">
                        {t('asset.listening_ports', 'Naslouchající porty')}
                      </p>
                      <div className="flex flex-wrap gap-1.5">
                        {ports.map((p) => (
                          <span key={p} className="rounded-md bg-secondary px-2 py-0.5 font-mono text-[11px]">
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
                paused: t('common.paused', 'Paused'),
                maintenance: t('common.maintenance', 'Údržba'),
              };
              return (
                <div
                  key={service.name}
                  className="hover:bg-muted/40 flex items-center gap-3 rounded-md px-3 py-2 transition-colors"
                >
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{service.name}</p>
                    <p className="text-muted-foreground truncate text-xs">
                      {service.kind} · {service.detail}
                    </p>
                  </div>
                  <Badge variant={service.status === 'maintenance' ? 'info' : service.status} dot>
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

/** Má monitor síťovou telemetrii, kvůli které stojí za to ukázat Síť tab? */
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
 * Síťová telemetrie z OpenWrt/VPS agenta - agent tahle data sbírá dlouho,
 * ale React app je nikdy nezobrazila (viděla je jen stará status stránka).
 * Každá sekce se vykreslí jen když má skutečná data; citlivé položky (WAN
 * adresy, SSID, WG endpointy...) API posílá pouze admin session.
 */
function NetworkTab({ d }: { d: Record<string, any> }) {
  const { t } = useLanguage();

  const Row = ({ label, value }: { label: string; value: React.ReactNode }) =>
    value == null || value === '' ? null : (
      <div className="flex items-center justify-between gap-3 py-1.5 border-b border-border/40 last:border-0 text-xs">
        <span className="text-muted-foreground">{label}</span>
        <span className="font-medium text-right font-mono">{value}</span>
      </div>
    );

  const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
    <Card className="p-4">
      <h4 className="font-bold text-sm mb-2">{title}</h4>
      {children}
    </Card>
  );

  const fmtAgo = (ts: unknown) => {
    const n = typeof ts === 'number' ? ts : parseInt(String(ts ?? ''), 10);
    if (!Number.isFinite(n) || n <= 0) return null;
    const secs = Math.max(0, Math.floor(Date.now() / 1000) - n);
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
  const wg: any[] = Array.isArray(d.wireguard_peers) ? d.wireguard_peers : [];
  const ifaces: any[] = Array.isArray(d.interfaces) ? d.interfaces : [];
  const restarts =
    d.service_restarts && typeof d.service_restarts === 'object'
      ? Object.entries(d.service_restarts).filter(([, v]) => Number(v) > 0)
      : [];
  const dnsTotal = (Number(d.dns_cache_hits) || 0) + (Number(d.dns_cache_misses) || 0);

  return (
    <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
      {(d.wan_proto != null || d.wan_up != null) && (
        <Section title={`🌐 ${t('net.wan_title', 'WAN připojení')}`}>
          <Row
            label={t('common.status', 'Stav')}
            value={d.wan_up == null ? null : d.wan_up ? t('common.online', 'Online') : t('common.offline', 'Offline')}
          />
          <Row label={t('net.proto', 'Protokol')} value={d.wan_proto} />
          <Row label="IPv4" value={d.wan_ipv4} />
          <Row label="IPv6" value={d.wan_ipv6} />
          <Row label={t('net.gateway', 'Brána')} value={d.wan_gateway} />
          <Row label={t('net.public_ip', 'Veřejná IP (pohled serveru)')} value={d.public_ip} />
          <Row label="ASN / ISP" value={[d.asn, d.asn_name].filter(Boolean).join(' · ') || null} />
          <Row label="DNS" value={d.wan_dns} />
          <Row label={t('net.wan_uptime', 'WAN uptime')} value={fmtDur(d.wan_uptime)} />
          <Row label={t('net.reconnects', 'Reconnecty (od startu)')} value={d.wan_reconnect_count} />
          <Row label={t('net.last_reconnect', 'Poslední reconnect')} value={fmtAgo(d.wan_last_reconnect)} />
          {d.mwan3_active_gw != null && <Row label="mwan3" value={String(d.mwan3_active_gw)} />}
        </Section>
      )}

      {wifi.length > 0 && (
        <Section
          title={`📶 Wi-Fi (${d.wifi_clients_count ?? wifi.reduce((s, r) => s + (Number(r.clients) || 0), 0)} ${t('net.clients', 'klientů')})`}
        >
          {wifi.map((r, i) => (
            <div
              key={i}
              className="py-1.5 border-b border-border/40 last:border-0 text-xs flex items-center justify-between gap-2 flex-wrap"
            >
              <span className="font-medium">
                {r.ssid}{' '}
                <span className="text-muted-foreground">
                  ({r.band}
                  {r.channel ? `, ch ${r.channel}` : ''})
                </span>
              </span>
              <span className="text-muted-foreground font-mono">
                {Number(r.clients) || 0} {t('net.clients_short', 'kl.')}
                {r.busy_pct != null ? ` · ${t('net.busy', 'vytížení')} ${r.busy_pct} %` : ''}
                {r.noise ? ` · šum ${r.noise} dBm` : ''}
                {r.tx_power ? ` · ${r.tx_power} dBm TX` : ''}
              </span>
            </div>
          ))}
        </Section>
      )}

      {(d.lan_subnet != null || d.dhcp_leases_count != null) && (
        <Section title={`🏠 ${t('net.lan_title', 'LAN & DHCP')}`}>
          <Row label={t('net.subnet', 'Subnet')} value={d.lan_subnet} />
          <Row label={t('net.dhcp_leases', 'Aktivní DHCP lease')} value={d.dhcp_leases_count} />
          <Row label={t('net.dhcp_reservations', 'Rezervace')} value={d.dhcp_reservations_count} />
        </Section>
      )}

      {d.dns_engine != null && (
        <Section title={`🧭 DNS (${d.dns_engine})`}>
          <Row label={t('net.dns_encryption', 'Šifrování')} value={d.dns_encryption} />
          <Row label={t('net.dns_servers', 'Servery')} value={d.dns_servers} />
          <Row label={t('net.dns_queries', 'Dotazy')} value={d.dns_queries} />
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
          <Row label={t('net.fw_accepted', 'Přijato paketů')} value={d.fw_accepted} />
          <Row label={t('net.fw_dropped', 'Zahozeno')} value={d.fw_dropped} />
          <Row label={t('net.fw_rejected', 'Odmítnuto')} value={d.fw_rejected} />
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
                {Number(it.errors) > 0 ? ` · ⚠ ${it.errors} err` : ''}
              </span>
            </div>
          ))}
        </Section>
      )}

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
          {/* Spojení se pozná i bez ModemManageru (ubus interface), síla
              signálu ne - proto se hlásí zvlášť a chybějící metriky
              zůstávají prázdné místo výmluvy. */}
          <Row
            label={t('net.lte_state', 'LTE spojení')}
            value={
              d.lte_up == null
                ? null
                : d.lte_up
                  ? `${t('common.online', 'Online')}${d.lte_device ? ` · ${d.lte_device}` : ''}${
                      d.lte_uptime != null ? ` · ${formatUptime(d.lte_uptime)}` : ''
                    }`
                  : t('common.offline', 'Offline')
            }
          />
          <Row label={t('net.lte_ip', 'LTE adresa')} value={d.lte_ipv4} />
          <Row label="LTE RSRP" value={d.lte_rsrp != null ? `${d.lte_rsrp} dBm` : null} />
          <Row label="LTE RSRQ" value={d.lte_rsrq != null ? `${d.lte_rsrq} dB` : null} />
          <Row label="LTE SINR" value={d.lte_sinr != null ? `${d.lte_sinr} dB` : null} />
          <Row
            label={t('net.lte_band', 'Pásmo / operátor')}
            value={[d.lte_band, d.lte_carrier].filter(Boolean).join(' · ') || null}
          />
          {d.lte_up === true && d.lte_rsrp == null && (
            <p className="text-muted-foreground col-span-full text-[11px] leading-relaxed">
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

      {(d.installed_packages != null || d.log_errors_24h != null || restarts.length > 0 || d.entropy != null) && (
        <Section title={`🧰 ${t('net.sys_title', 'Systém & Služby')}`}>
          <Row
            label={t('net.packages', 'Balíčky (instalované / aktualizace)')}
            value={
              d.installed_packages != null
                ? `${d.installed_packages}${d.upgradable_packages != null ? ` / ${d.upgradable_packages}` : ''}`
                : null
            }
          />
          <Row label={t('net.log_errors', 'Chyby v logu (24 h)')} value={d.log_errors_24h} />
          <Row label={t('net.log_warnings', 'Varování v logu (24 h)')} value={d.log_warnings_24h} />
          <Row label={t('net.entropy', 'Entropie')} value={d.entropy} />
          <Row
            label={t('net.oom_kills', 'OOM kills (od startu)')}
            value={d.oom_kills != null && d.oom_kills > 0 ? d.oom_kills : d.oom_kills === 0 ? '0' : null}
          />
          <Row
            label={t('net.boot_time', 'Systém běží od')}
            value={d.boot_time != null && d.boot_time > 0 ? new Date(d.boot_time * 1000).toLocaleString('cs-CZ') : null}
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
  );
}

/**
 * "Actions" dropdown z mockupu - reálné Remote Actions. Server akci podepíše
 * HMAC klíčem monitoru a agent ji provede při příštím reportu (do ~1 min);
 * výsledek se pak objeví v systémové časové ose. restart_service se ptá na
 * název služby (návrh z hlídaných procesů monitoru).
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
            'absolute right-0 top-full z-30 mt-1 w-72 rounded-lg border p-2 text-[11px] font-medium shadow-lg',
            result.ok
              ? 'border-emerald-500/30 bg-card text-emerald-700 dark:text-emerald-300'
              : 'border-destructive/40 bg-card text-destructive'
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
        <span className={cn('tabular text-xl font-bold tracking-tight', toneCls)}>{score}</span>
        <span className="text-muted-foreground text-xs font-medium">/ 100</span>
      </div>
      <p className={cn('text-[11px] font-semibold', toneCls)}>{label}</p>
    </Card>
  );
}

function HealthCard({ metric }: { metric: HealthMetric }) {
  return (
    <Card className="p-3.5 flex flex-col gap-1">
      <p className="text-xs text-muted-foreground font-medium">{metric.label}</p>
      <div className="flex items-baseline gap-1.5">
        <p
          className={cn(
            'text-base font-bold',
            metric.tone === 'latency'
              ? 'text-sky-600 dark:text-sky-400 font-mono'
              : metric.tone === 'cpu'
                ? 'text-amber-600 dark:text-amber-400 font-mono'
                : metric.tone === 'memory'
                  ? 'text-emerald-600 dark:text-emerald-400 font-mono'
                  : metric.tone === 'disk'
                    ? 'text-purple-600 dark:text-purple-400 font-mono'
                    : metric.tone === 'temperature'
                      ? 'text-orange-600 dark:text-orange-400 font-mono'
                      : 'text-foreground'
          )}
        >
          {metric.value}
        </p>
        {metric.delta && (
          <span
            className={cn(
              'tabular text-[11px] font-semibold',
              metric.delta.good === null ? 'text-muted-foreground' : metric.delta.good ? 'text-up' : 'text-down'
            )}
          >
            {metric.delta.direction === 'up' ? '↑' : '↓'} {metric.delta.pct} %
          </span>
        )}
      </div>
      {metric.series && metric.tone && (
        <div className="mt-auto pt-0.5">
          <Sparkline data={metric.series} tone={metric.tone} className="h-7 w-full" />
        </div>
      )}
    </Card>
  );
}

function PerformanceCharts({
  data: rawData,
  error,
  loading,
  range,
  events = [],
}: {
  data: ChartData[] | null;
  error: Error | null;
  loading: boolean;
  range: TimeRange;
  events?: TimelineEvent[];
}) {
  const { t } = useLanguage();

  // Události monitoru jako svislé značky ve VŠECH grafech - výpadek nebo
  // restart je vidět přímo v místě, kde metrika uskočila. MySQL datetime se
  // parsuje přes 'T' variantu (Safari čisté 'YYYY-MM-DD HH:MM' neumí).
  const chartEvents = React.useMemo(() => {
    return events
      .map((e) => {
        const ms = Date.parse(String(e.at).replace(' ', 'T'));
        return Number.isNaN(ms) ? null : { t: ms, label: e.title };
      })
      .filter((e): e is { t: number; label: string } => e != null);
  }, [events]);

  const data = React.useMemo(() => {
    if (rawData && rawData.length > 0 && rawData.some((c) => c.series.some((s) => s.points.length > 0))) {
      return rawData;
    }
    return null;
  }, [rawData]);

  if (error) {
    return (
      <Card className="grid place-items-center gap-1 p-10 text-center">
        <p className="text-sm font-medium">{t('asset.charts_load_error', 'Grafy se nepodařilo načíst')}</p>
        <p className="text-muted-foreground text-sm">{error.message}</p>
      </Card>
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

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {data.map((chart) => (
        <ChartCard
          key={chart.id}
          data={chartEvents.length > 0 ? { ...chart, events: [...(chart.events ?? []), ...chartEvents] } : chart}
          group="asset-performance"
        />
      ))}
    </div>
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
 * Časová osa s filtrem závažnosti a stránkováním - 60 událostí v jednom
 * nekonečném sloupci se nedalo číst (hlášeno uživatelem: obnovení provozu
 * a service discovered pomíchané dohromady).
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
  // Řazení podle času; při neparsovatelném datu se zachová původní pořadí
  // (server je posílá od nejnovějších), místo aby položka propadla dolů.
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
              className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors ${
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
            className="bg-secondary text-muted-foreground hover:text-foreground rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors"
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
            className="border-border bg-background rounded-md border px-1.5 py-1 text-[11px]"
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
          <span className="text-[11px] text-muted-foreground">
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
  const titleByType: Record<string, string> = {
    status_changed_down: t('asset.tl_down', 'Výpadek služby'),
    status_changed_up: t('asset.tl_up', 'Obnovení provozu'),
    status_changed_warning: t('asset.tl_warning', 'Zhoršená odezva'),
    status_changed_maintenance: t('asset.tl_maintenance', 'Plánovaná údržba'),
    remote_action: t('asset.tl_remote_action', 'Vzdálená akce'),
    ssl_warning: t('asset.tl_ssl_warning', 'SSL varování'),
    threshold_exceeded: t('asset.tl_threshold', 'Překročen limit'),
    monitor_added: t('asset.tl_monitor_added', 'Monitor přidán'),
    monitor_updated: t('asset.tl_monitor_updated', 'Monitor upraven'),
  };
  const severityFor = (type: string): TimelineEvent['severity'] => {
    if (type === 'status_changed_down') return 'down';
    if (type === 'status_changed_warning' || type === 'ssl_warning' || type === 'threshold_exceeded') return 'warning';
    if (type === 'status_changed_up') return 'up';
    return 'info';
  };

  return timeline.map((e, i) => ({
    // Negative synthetic ids so they can never collide with real
    // monitor_logs ids used by the per-check timeline below.
    id: -(i + 1),
    title: titleByType[e.type] ?? e.type,
    detail: e.description ?? '',
    at: e.relative ? `${e.relative} · ${e.at}` : e.at,
    severity: severityFor(e.type),
  }));
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
  return d.toLocaleString('cs-CZ');
}

function buildDynamicAsset(
  m: ApiMonitor,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): AssetDetail {
  const status: MonitorStatus =
    m.status === 'up' ? 'up' : m.status === 'down' ? 'down' : m.status === 'warning' ? 'warning' : 'paused';
  const lastCheckDisplay = m.lastCheck
    ? `${timeAgo(m.lastCheck, t)} (${new Date(m.lastCheck).toLocaleString('cs-CZ')})`
    : t('asset.moment_ago', 'Před chvílí');
  const lastChangeDisplay = m.lastStatusChange
    ? `${timeAgo(m.lastStatusChange, t)} (${new Date(m.lastStatusChange).toLocaleString('cs-CZ')})`
    : '—';

  // Agent posílá dva TOP žebříčky (5 podle CPU, 5 podle RAM) - ne kompletní
  // výpis procesů. Každý žebříček nese jen svou dimenzi; ta druhá zůstává
  // null a tabulka ukáže pomlčku, ne vymyšlenou nulu.
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
        // Proces je v obou žebříčcích - doplníme mu RAM z RAM žebříčku.
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

  return {
    id: m.id,
    name: m.name,
    kind: m.type.toUpperCase(),
    // Mockup: "OpenWrt 23.05.3 · 192.168.1.1 · Prague, CZ" - OS na prvním místě,
    // pokud ho agent hlásí (u webů je m.os jen echo typu, to nevypisujeme).
    subtitle: [m.os && m.os !== 'web' && m.os !== m.type ? m.os : null, m.target, m.category ?? 'Monitory']
      .filter(Boolean)
      .join(' · '),
    status,
    breadcrumb: [m.category ?? 'Monitory'],
    // KPI řada podle mockupu: Uptime, odezva, CPU, RAM, disk, teplota.
    // Stav tu není - hero badge ho už ukazuje; Health Score dlaždici
    // přidává OverviewTab ze server insights.
    health: [
      ...(m.uptimeSeconds != null ? [{ key: 'uptime', label: 'Uptime', value: formatUptime(m.uptimeSeconds) }] : []),
      {
        key: 'latency',
        label: t('common.response', 'Odezva'),
        value: m.responseMs != null ? `${m.responseMs} ms` : '—',
        tone: 'latency' as const,
      },
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
      {
        key: 'cpu',
        label: t('common.cpu', 'Využití CPU'),
        value: m.cpu != null ? `${m.cpu.toFixed(1)} %` : '—',
        tone: 'cpu' as const,
      },
      {
        key: 'ram',
        label: t('common.ram', 'Využití RAM'),
        value: m.ram != null ? `${m.ram.toFixed(1)} %` : '—',
        tone: 'memory' as const,
      },
      {
        key: 'hdd',
        label: t('common.hdd', 'Využití disku'),
        value: m.hdd != null ? `${m.hdd.toFixed(1)} %` : '—',
        tone: 'disk' as const,
      },
      ...(m.details?.temperature_c != null
        ? [
            {
              key: 'temp',
              label: t('asset.temperature', 'Teplota'),
              value: `${Number(m.details.temperature_c).toFixed(0)} °C`,
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
      { label: `${t('common.type', 'Typ')}: ${m.type.toUpperCase()}`, variant: 'info' },
      // Roky ukládané, nikdy nezobrazené: server čekající na restart a hlídané
      // procesy, které neběží - obojí patří na první pohled.
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
      { label: t('common.response', 'Odezva'), value: m.responseMs != null ? `${m.responseMs} ms` : '—' },
      { label: t('infra.os', 'Operační systém'), value: m.os ?? '—' },
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
      { label: t('asset.protocol_type', 'Typ protokolu'), value: m.type.toUpperCase() },
      ...(isTS3 && hasTs3Counts
        ? [
            {
              label: t('asset.ts3_serverquery', 'TeamSpeak 3 ServerQuery'),
              value: t(
                'asset.ts3_serverquery_value',
                { online: ts3Clients, max: ts3Max },
                `${ts3Clients} / ${ts3Max} uživatelů online (Port 9987/8200)`
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
    collectionIssues: m.collectionIssues ?? [],
    rawDetails: m.details && typeof m.details === 'object' ? m.details : {},
    remoteActionsEnabled: Boolean(m.remoteActionsEnabled),
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
    related: [],
  };
}
