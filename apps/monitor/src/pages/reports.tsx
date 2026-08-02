import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipTrigger, TooltipContent } from '@/components/ui/tooltip';
import {
  BarChart3, Download, FileText, CheckCircle2, ShieldCheck,
  Clock, TrendingUp, ArrowRight, Server, ChevronDown, ChevronUp, AlertTriangle, RefreshCw, HelpCircle,
  ExternalLink, Copy, Check, Key, Settings
} from 'lucide-react';
import { useLanguage } from '@/context/language-context';

const API_BASE = '/status/api.php';

interface OutageDetail {
  start: string;
  end: string | null;
  durationSec: number;
  reason: string;
  resolved: boolean;
}

interface MonitorSLA {
  id: number;
  name: string;
  target: string;
  type: string;
  currentStatus: string;
  uptimePercent: number;
  outageMinutes: number;
  totalChecks: number;
  lastOutage: OutageDetail | null;
  mttrSec: number | null;
  p50Ms?: number | null;
  p95Ms?: number | null;
  p99Ms?: number | null;
  lastStatusChange: string | null;
}

function formatDuration(seconds: number): string {
  if (seconds < 60) return `${seconds} s`;
  const m = Math.floor(seconds / 60);
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60);
  const rm = m % 60;
  if (h < 24) return `${h}h ${rm}min`;
  const d = Math.floor(h / 24);
  const rh = h % 24;
  return `${d}d ${rh}h ${rm}min`;
}

function Crown({ className = "size-5" }: { className?: string }) {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" className={className} aria-hidden="true">
      <path d="M3 6.5 6.2 11 12 3.5 17.8 11 21 6.5V19a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6.5Z" />
    </svg>
  );
}

export function ReportsPage() {
  const { t } = useLanguage();
  const [monitors, setMonitors] = useState<MonitorSLA[]>([]);
  const [loading, setLoading] = useState(true);
  const [slaGoal, setSlaGoal] = useState(99.95);
  const [overallUptime, setOverallUptime] = useState(100);
  const [totalOutage, setTotalOutage] = useState(0);
  const [overallMttr, setOverallMttr] = useState<number | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [metricsToken, setMetricsToken] = useState<string>('');
  const [siteTitle, setSiteTitle] = useState<string>('Blood Kings Monitoring');
  const [customLogoUrl, setCustomLogoUrl] = useState<string>('');
  const [generatingToken, setGeneratingToken] = useState<boolean>(false);
  const [copiedUrl, setCopiedUrl] = useState<boolean>(false);

  useEffect(() => {
    let active = true;

    fetch(`${API_BASE}?action=sla_report&days=30`, { credentials: 'include' })
      .then(res => res.json())
      .then(data => {
        if (!active) return;
        if (data.monitors && data.monitors.length > 0) {
          setMonitors(data.monitors);
          setSlaGoal(data.slaGoal ?? 99.95);
          setOverallUptime(data.overallUptime ?? 100);
          setTotalOutage(data.totalOutageMinutes ?? 0);
          setOverallMttr(data.overallMttrSec ?? null);
          setMetricsToken(data.metricsToken ?? '');
          setSiteTitle(data.siteTitle || 'Blood Kings Monitoring');
          setCustomLogoUrl(data.customLogoUrl || '');
        } else {
          setError(t('reports.no_api_data', 'Žádná data z API. Zkontrolujte, že cron.php běží a monitor_logs obsahuje záznamy.'));
        }
      })
      .catch(() => {
        if (active) setError(t('reports.load_error', 'Nepodařilo se načíst SLA data.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [t]);

  const handleGenerateMetricsToken = async () => {
    setGeneratingToken(true);
    try {
      const res = await fetch(`${API_BASE}?action=generate_metrics_token`, {
        method: 'POST',
        credentials: 'include',
      });
      const data = await res.json();
      if (data.metricsToken) {
        setMetricsToken(data.metricsToken);
      }
    } catch {
    } finally {
      setGeneratingToken(false);
    }
  };

  const handleCopyMetricsUrl = () => {
    const fullUrl = `${window.location.origin}/status/metrics.php?token=${metricsToken}`;
    navigator.clipboard.writeText(fullUrl);
    setCopiedUrl(true);
    setTimeout(() => setCopiedUrl(false), 3000);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20 gap-3">
        <RefreshCw className="size-5 animate-spin text-primary" />
        <span className="text-sm text-muted-foreground">{t('reports.loading', 'Načítám SLA metriky z databáze…')}</span>
      </div>
    );
  }

  const handleExportCSV = () => {
    const headers = [
      'ID', t('common.name', 'Název'), t('common.target', 'Cíl'), t('common.type', 'Typ'), t('common.status', 'Stav'),
      t('reports.csv_uptime', 'Uptime SLA (%)'), t('reports.csv_outage_min', 'Celkový výpadek (min)'),
      t('reports.csv_total_checks', 'Celkem kontrol'), t('reports.csv_mttr', 'MTTR (s)'),
      'p50 (ms)', 'p95 (ms)', 'p99 (ms)',
    ];
    const rows = monitors.map((m) => [
      m.id,
      `"${m.name.replace(/"/g, '""')}"`,
      `"${m.target.replace(/"/g, '""')}"`,
      m.type,
      m.currentStatus,
      m.uptimePercent.toFixed(3),
      m.outageMinutes,
      m.totalChecks,
      m.mttrSec ?? 0,
      m.p50Ms ?? 0,
      m.p95Ms ?? 0,
      m.p99Ms ?? 0,
    ]);

    const csvContent = 'data:text/csv;charset=utf-8,\uFEFF' + [headers.join(','), ...rows.map((r) => r.join(','))].join('\n');
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', `SLA_Report_BloodKings_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  const handlePrintPDF = () => {
    window.print();
  };

  return (
    <div className="space-y-6 print:space-y-4">
      {/* Oficiální Hlavička pro PDF export a Tisk (Zobrazuje se pouze při tisku) */}
      <div className="hidden print:flex items-center justify-between border-b-2 border-primary pb-4 mb-4">
        <div className="flex items-center gap-3">
          {customLogoUrl ? (
            <img src={customLogoUrl} alt={siteTitle} className="h-10 max-w-[200px] object-contain" />
          ) : (
            <div className="bg-primary text-primary-foreground p-3 rounded-xl flex items-center justify-center shadow-sm">
              <Crown className="size-7 text-white" />
            </div>
          )}
          <div>
            <div className="flex items-center gap-2">
              <span className="font-extrabold text-2xl tracking-tight text-foreground">{siteTitle}</span>
              {!customLogoUrl && (
                <span className="text-xs font-bold tracking-widest uppercase text-primary bg-primary/10 px-2.5 py-0.5 rounded-md border border-primary/20">
                  MONITORING
                </span>
              )}
            </div>
            <p className="text-xs text-muted-foreground font-medium mt-0.5">
              Oficiální Garance Uptime, Výpadky & SLA Auditní Výkaz
            </p>
          </div>
        </div>

        <div className="text-right text-xs text-muted-foreground space-y-1">
          <p className="font-semibold text-foreground text-sm">SLA Audit Report</p>
          <p className="font-mono text-[11px]">Vygenerováno: {new Date().toLocaleString('cs-CZ')}</p>
          <p className="text-[10px] text-muted-foreground">Zdroj: bloodkings.eu / status API</p>
        </div>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('reports.title', 'SLA Výkaz & Statistika Dle Serverů')}</h1>
          <p className="text-muted-foreground text-sm">{t('reports.subtitle', 'Reálná data z monitorovací databáze — uptime, výpadky, doba obnovení (MTTR) a důvody výpadků.')}</p>
        </div>

        <div className="flex items-center gap-2 print:hidden">
          <Button variant="outline" size="sm" onClick={handleExportCSV} className="gap-2 font-semibold">
            <Download className="size-4 text-emerald-400" /> {t('reports.export_csv', 'Exportovat CSV')}
          </Button>
          <Button variant="outline" size="sm" onClick={handlePrintPDF} className="gap-2 font-semibold">
            <FileText className="size-4 text-sky-400" /> {t('reports.export_pdf', 'Tisknout / PDF')}
          </Button>
        </div>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-semibold flex items-center gap-2">
          <AlertTriangle className="size-4 shrink-0" />
          {error}
        </div>
      )}

      {/* KPI cards */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-emerald-500/10 text-emerald-400">
            <ShieldCheck className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">{t('reports.overall_sla', 'Celkové plnění SLA')}</p>
            <p className={`font-bold text-xl ${overallUptime >= slaGoal ? 'text-emerald-400' : 'text-rose-400'}`}>
              {overallUptime.toFixed(2)} %
            </p>
            <p className="text-[10px] text-muted-foreground">{t('reports.sla_target_value', { goal: slaGoal }, `SLA Cíl: ${slaGoal} %`)}</p>
          </div>
        </Card>

        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-primary/10 text-primary">
            <TrendingUp className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">{t('reports.monitored_count', 'Sledované servery/weby')}</p>
            <p className="font-bold text-xl">{t('reports.total_count', { count: monitors.length }, `${monitors.length} celkem`)}</p>
            <p className={`text-[10px] font-medium ${monitors.every(m => m.uptimePercent >= slaGoal) ? 'text-emerald-400' : 'text-amber-400'}`}>
              {t('reports.sla_compliant_count', { ok: monitors.filter(m => m.uptimePercent >= slaGoal).length, total: monitors.length }, `${monitors.filter(m => m.uptimePercent >= slaGoal).length} / ${monitors.length} splňuje SLA`)}
            </p>
          </div>
        </Card>

        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-amber-500/10 text-amber-400">
            <Clock className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">{t('reports.total_outage_30d', 'Celkový výpadek (30 d)')}</p>
            <p className="font-bold text-xl">{totalOutage} min</p>
            <p className="text-[10px] text-muted-foreground">{t('reports.outage_sum_hint', 'Suma výpadků všech cílů')}</p>
          </div>
        </Card>

        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-emerald-500/10 text-emerald-400">
            <CheckCircle2 className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">{t('reports.mttr', 'Průměrná doba obnovení (MTTR)')}</p>
            <p className="font-bold text-xl">
              {overallMttr !== null ? formatDuration(overallMttr) : '—'}
            </p>
            <p className="text-[10px] text-muted-foreground">{t('reports.mttr_hint', 'Automatické obnovení (down→up)')}</p>
          </div>
        </Card>
      </div>

      {/* SLA per monitor */}
      <Card className="p-6 space-y-5">
        <div className="flex items-center justify-between border-b border-border pb-3">
          <div>
            <h3 className="font-bold text-base">{t('reports.sla_per_monitor_title', 'Plnění SLA garancí po jednotlivých serverech a webech')}</h3>
            <p className="text-xs text-muted-foreground">{t('reports.sla_per_monitor_desc', 'Reálná dostupnost z databáze za posledních 30 dní. Klikněte na řádek pro detail výpadku.')}</p>
          </div>
          <Badge variant="up" className="px-3 py-1">{t('reports.sla_target_value', { goal: slaGoal }, `SLA Cíl: ${slaGoal} %`)}</Badge>
        </div>

        {monitors.length === 0 ? (
          <p className="text-xs text-muted-foreground">{t('reports.no_monitors', 'Žádné monitory k zobrazení.')}</p>
        ) : (
          <div className="space-y-3 pt-2">
            {monitors.map((item) => {
              const isOk = item.uptimePercent >= slaGoal;
              const fillPct = Math.max(5, Math.min(100, (item.uptimePercent - 90.0) * 10));
              const isExpanded = expandedId === item.id;

              return (
                <div key={item.id} className="rounded-lg bg-secondary/30 border border-border overflow-hidden">
                  {/* Main row */}
                  <button
                    type="button"
                    onClick={() => setExpandedId(isExpanded ? null : item.id)}
                    className="w-full p-3.5 text-left flex items-center justify-between gap-2 hover:bg-secondary/50 transition-colors cursor-pointer"
                  >
                    <div className="flex items-center gap-2 min-w-0">
                      <Server className="size-4 text-primary shrink-0" />
                      <span className="font-semibold text-sm text-foreground truncate">{item.name}</span>
                      <span className="text-[10px] text-muted-foreground font-mono truncate hidden sm:inline">({item.target})</span>
                    </div>

                    <div className="flex items-center gap-3 shrink-0">
                      {item.outageMinutes > 0 && (
                        <span className="text-[10px] text-muted-foreground font-mono">{t('reports.outage_minutes_inline', { min: item.outageMinutes }, `Výpadek: ${item.outageMinutes} min`)}</span>
                      )}
                      <Badge variant={isOk ? 'up' : 'down'} className="text-[10px]">
                        {item.uptimePercent.toFixed(2)} %
                      </Badge>
                      {isExpanded ? <ChevronUp className="size-3.5 text-muted-foreground print:hidden" /> : <ChevronDown className="size-3.5 text-muted-foreground print:hidden" />}
                    </div>
                  </button>

                  {/* Progress bar */}
                  <div className="px-3.5 pb-2">
                    <div className="h-1.5 w-full bg-secondary/80 rounded-full overflow-hidden">
                      <div
                        className={`h-full transition-all rounded-full ${isOk ? 'bg-emerald-500' : 'bg-rose-500'}`}
                        style={{ width: `${fillPct}%` }}
                      />
                    </div>
                  </div>

                  {/* Expanded detail (Visible on click on screen, always printed in PDF) */}
                  <div className={`px-3.5 pb-3.5 pt-1 border-t border-border/50 space-y-3 animate-in fade-in-50 slide-in-from-top-1 duration-200 ${isExpanded ? 'block' : 'hidden print:block'}`}>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                      <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                        <p className="text-muted-foreground text-[10px]">{t('common.type', 'Typ')}</p>
                        <p className="font-semibold">{item.type}</p>
                      </div>
                      <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                        <p className="text-muted-foreground text-[10px]">{t('reports.total_checks_30d', 'Celkem kontrol (30d)')}</p>
                        <p className="font-semibold">{item.totalChecks.toLocaleString('cs-CZ')}</p>
                      </div>
                      <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                        <p className="text-muted-foreground text-[10px]">{t('reports.mttr_label', 'MTTR (doba obnovení)')}</p>
                        <p className="font-semibold">{item.mttrSec !== null ? formatDuration(item.mttrSec) : t('reports.no_outage', 'Bez výpadku')}</p>
                      </div>
                      <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                        <p className="text-muted-foreground text-[10px]">{t('reports.current_status', 'Aktuální stav')}</p>
                        <p className={`font-semibold ${item.currentStatus === 'up' ? 'text-emerald-400' : item.currentStatus === 'down' ? 'text-rose-400' : 'text-amber-400'}`}>
                          {item.currentStatus === 'up' ? `🟢 ${t('common.online', 'Online')}` : item.currentStatus === 'down' ? `🔴 ${t('common.offline', 'Offline')}` : '⚠️ ' + item.currentStatus}
                        </p>
                      </div>
                    </div>

                    {/* Response latency percentiles (p50 / p95 / p99) */}
                    <div className="p-3 rounded-md bg-background/60 border border-border/60 space-y-1">
                      <div className="flex items-center gap-1.5">
                        <p className="text-muted-foreground text-[11px] font-semibold">{t('reports.percentile_title', 'Percentilové Rozložení Latence (p50 / p95 / p99)')}</p>
                        <Tooltip>
                          <TooltipTrigger asChild className="print:hidden">
                            <button type="button" className="text-muted-foreground hover:text-foreground cursor-help print:hidden" aria-label={t('reports.percentile_aria', 'Co znamenají percentily odezvy')}>
                              <HelpCircle className="size-3.5" />
                            </button>
                          </TooltipTrigger>
                          <TooltipContent>
                            <p className="font-semibold mb-1">{t('reports.percentile_tooltip_title', 'Co percentily znamenají')}</p>
                            <p><strong className="text-emerald-400">{t('reports.p50_label', 'p50 (medián):')}</strong> {t('reports.p50_desc', 'polovina kontrol byla rychlejší, polovina pomalejší — nejlépe vystihuje typickou odezvu.')}</p>
                            <p className="mt-1"><strong className="text-amber-400">{t('reports.p95_label', 'p95:')}</strong> {t('reports.p95_desc', '95 % kontrol bylo rychlejších; zbylých 5 % jsou špičky (dočasné zpomalení, zátěž).')}</p>
                            <p className="mt-1"><strong className="text-rose-400">{t('reports.p99_label', 'p99:')}</strong> {t('reports.p99_desc', 'jen 1 % kontrol bylo pomalejších — ojedinělé extrémní špičky, často síťový problém nebo přetížený server.')}</p>
                            <p className="mt-1.5 pt-1.5 border-t border-border/60 text-muted-foreground">{t('reports.percentile_hint', 'Vysoké p95/p99 při nízkém p50 = nekonzistentní výkon. Hledejte příčinu v době těch špiček (log serveru, zátěž), ne v průměru.')}</p>
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <div className="flex flex-wrap items-center gap-4 font-mono text-xs pt-0.5">
                        <span className="bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded">{t('reports.p50_value_label', 'p50 (Medián):')} <strong className="text-emerald-400">{item.p50Ms != null ? `${item.p50Ms} ms` : '—'}</strong></span>
                        <span className="bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 rounded">{t('reports.p95_value_label', 'p95 (Špičky):')} <strong className="text-amber-400">{item.p95Ms != null ? `${item.p95Ms} ms` : '—'}</strong></span>
                        <span className="bg-rose-500/10 border border-rose-500/30 px-2 py-0.5 rounded">{t('reports.p99_value_label', 'p99 (Kritické špičky):')} <strong className="text-rose-400">{item.p99Ms != null ? `${item.p99Ms} ms` : '—'}</strong></span>
                      </div>
                    </div>

                    {/* Last outage detail */}
                    {item.lastOutage ? (
                      <div className="p-3 rounded-lg bg-rose-500/5 border border-rose-500/20 space-y-1.5 text-xs">
                        <p className="font-bold text-destructive text-[11px]">📋 {t('reports.last_outage_title', 'Poslední výpadek')}</p>
                        <div className="grid gap-1 sm:grid-cols-2">
                          <p><span className="text-muted-foreground">{t('reports.outage_field_start', 'Začátek:')}</span> <span className="font-mono">{item.lastOutage.start}</span></p>
                          <p><span className="text-muted-foreground">{t('reports.outage_field_end', 'Konec:')}</span> <span className="font-mono">{item.lastOutage.end ?? t('reports.outage_ongoing', 'Stále probíhá ⚠️')}</span></p>
                          <p><span className="text-muted-foreground">{t('reports.outage_field_duration', 'Trvání:')}</span> <span className="font-bold">{formatDuration(item.lastOutage.durationSec)}</span></p>
                          <p><span className="text-muted-foreground">{t('reports.outage_field_status', 'Stav:')}</span> <Badge variant={item.lastOutage.resolved ? 'up' : 'down'} className="text-[9px] ml-1">{item.lastOutage.resolved ? t('reports.resolved_badge', 'Vyřešeno') : t('reports.ongoing_badge', 'Probíhá')}</Badge></p>
                        </div>
                        <p className="pt-1 border-t border-rose-500/10">
                          <span className="text-muted-foreground">{t('reports.reason_label', 'Důvod:')}</span>{' '}
                          <span className="font-mono text-destructive">{item.lastOutage.reason}</span>
                        </p>
                      </div>
                    ) : (
                      <div className="p-3 rounded-lg bg-emerald-500/5 border border-emerald-500/20 text-xs text-emerald-400">
                        ✅ {t('reports.no_outage_30d', 'Žádný výpadek za posledních 30 dní.')}
                      </div>
                    )}

                    <div className="flex justify-end print:hidden">
                      <Link
                        to={`/infrastructure/${item.id}`}
                        className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline print:hidden"
                      >
                        {t('reports.view_detail', 'Otevřít detail')} <ArrowRight className="size-3" />
                      </Link>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {/* Exports */}
      <div className="grid gap-4 md:grid-cols-2 print:hidden">
        <Card className="p-6 space-y-4">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
              <FileText className="size-6" />
            </div>
            <div>
              <h3 className="font-semibold text-base">{t('reports.csv_export_title', 'Měsíční SLA Výkaz (CSV Export)')}</h3>
              <p className="text-xs text-muted-foreground font-mono">/status/report.php?format=csv</p>
            </div>
          </div>
          <p className="text-sm text-muted-foreground">
            {t('reports.csv_export_desc', 'Stáhněte si kompletní CSV soubor s detailním výpočtem Uptime %, počty výpadků a latencí.')}
          </p>
          <a href="/status/report.php?format=csv" target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <Download className="size-4" /> {t('reports.csv_export_btn', 'Stáhnout CSV report')}
          </a>
        </Card>

        {metricsToken ? (
          <Card className="p-6 space-y-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400">
                  <BarChart3 className="size-6" />
                </div>
                <div>
                  <div className="flex items-center gap-2">
                    <h3 className="font-semibold text-base">{t('reports.prometheus_title', 'Prometheus Exportér Metrik')}</h3>
                    <Badge variant="up" className="text-[10px]">🟢 {t('reports.prometheus_active', 'Aktivní')}</Badge>
                  </div>
                  <p className="text-xs text-muted-foreground font-mono truncate max-w-[260px] sm:max-w-none">
                    /status/metrics.php?token={metricsToken.slice(0, 6)}••••
                  </p>
                </div>
              </div>
            </div>
            <p className="text-sm text-muted-foreground">
              {t('reports.prometheus_desc', 'Integrační rozhraní pro napojení externích systémů, Grafany nebo Prometheus serveru s vaším přístupovým tokenem.')}
            </p>
            <div className="flex flex-wrap items-center gap-2 pt-1">
              <a
                href={`/status/metrics.php?token=${encodeURIComponent(metricsToken)}`}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 transition-colors shadow-sm"
              >
                <ExternalLink className="size-4" /> {t('reports.prometheus_btn', 'Otevřít Prometheus výstup')}
              </a>
              <Button
                variant="outline"
                size="sm"
                onClick={handleCopyMetricsUrl}
                className="gap-2 text-xs"
              >
                {copiedUrl ? <Check className="size-3.5 text-emerald-400" /> : <Copy className="size-3.5" />}
                {copiedUrl ? t('common.copied', 'Zkopírováno!') : t('reports.copy_url', 'Kopírovat URL metrik')}
              </Button>
            </div>
          </Card>
        ) : (
          <Card className="p-6 space-y-4 border-amber-500/30 bg-amber-500/5">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="p-2.5 rounded-xl bg-amber-500/10 text-amber-400">
                  <BarChart3 className="size-6" />
                </div>
                <div>
                  <div className="flex items-center gap-2">
                    <h3 className="font-semibold text-base">{t('reports.prometheus_title', 'Prometheus Exportér Metrik')}</h3>
                    <Badge variant="down" className="text-[10px] bg-amber-500/20 text-amber-400 border-amber-500/30">⚠️ {t('reports.prometheus_inactive', 'Vyžaduje token')}</Badge>
                  </div>
                  <p className="text-xs text-muted-foreground font-mono">/status/metrics.php</p>
                </div>
              </div>
            </div>
            <p className="text-sm text-amber-300/90">
              {t('reports.prometheus_inactive_desc', 'Metriky jsou chráněny proti neautorizovanému přístupu. Vygenerujte přístupový token pro aktivaci endpointu.')}
            </p>
            <div className="flex flex-wrap items-center gap-2 pt-1">
              <Button
                onClick={handleGenerateMetricsToken}
                disabled={generatingToken}
                className="gap-2 font-semibold bg-amber-600 hover:bg-amber-500 text-white shadow-sm"
              >
                {generatingToken ? <RefreshCw className="size-4 animate-spin" /> : <Key className="size-4" />}
                {t('reports.generate_token_btn', 'Aktivovat Prometheus token (1-klik)')}
              </Button>
              <Link
                to="/settings"
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-secondary/80 px-3 py-2 text-xs font-semibold text-foreground hover:bg-secondary transition-colors"
              >
                <Settings className="size-3.5" />
                {t('reports.manage_in_settings', 'Spravovat v Nastavení')}
              </Link>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
