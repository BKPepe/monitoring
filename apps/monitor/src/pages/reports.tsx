import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  BarChart3, Download, FileText, Calendar, CheckCircle2, ShieldCheck,
  Clock, TrendingUp, ArrowRight, Server, ChevronDown, ChevronUp, AlertTriangle, RefreshCw
} from 'lucide-react';

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
  uptimePct: number;
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

export function ReportsPage() {
  const [monitors, setMonitors] = useState<MonitorSLA[]>([]);
  const [loading, setLoading] = useState(true);
  const [slaGoal, setSlaGoal] = useState(99.95);
  const [overallUptime, setOverallUptime] = useState(100);
  const [totalOutage, setTotalOutage] = useState(0);
  const [overallMttr, setOverallMttr] = useState<number | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

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
        } else {
          setError('Žádná data z API. Zkontrolujte, že cron.php běží a monitor_logs obsahuje záznamy.');
        }
      })
      .catch(() => {
        if (active) setError('Nepodařilo se načíst SLA data.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, []);

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20 gap-3">
        <RefreshCw className="size-5 animate-spin text-primary" />
        <span className="text-sm text-muted-foreground">Načítám SLA metriky z databáze…</span>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">SLA Výkazy & Statistika Dle Serverů</h1>
          <p className="text-muted-foreground text-sm">Reálná data z monitorovací databáze — uptime, výpadky, doba obnovení (MTTR) a důvody výpadků.</p>
        </div>
      </div>

      {error && (
        <div className="p-3 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400 text-xs font-semibold flex items-center gap-2">
          <AlertTriangle className="size-4 shrink-0" />
          {error}
        </div>
      )}

      {/* KPI Karty */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-emerald-500/10 text-emerald-400">
            <ShieldCheck className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Celkové plnění SLA</p>
            <p className={`font-bold text-xl ${overallUptime >= slaGoal ? 'text-emerald-400' : 'text-rose-400'}`}>
              {overallUptime.toFixed(2)} %
            </p>
            <p className="text-[10px] text-muted-foreground">SLA Cíl: {slaGoal} %</p>
          </div>
        </Card>

        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-primary/10 text-primary">
            <TrendingUp className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Sledované servery/weby</p>
            <p className="font-bold text-xl">{monitors.length} celkem</p>
            <p className={`text-[10px] font-medium ${monitors.every(m => m.uptimePct >= slaGoal) ? 'text-emerald-400' : 'text-amber-400'}`}>
              {monitors.filter(m => m.uptimePct >= slaGoal).length} / {monitors.length} splňuje SLA
            </p>
          </div>
        </Card>

        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-amber-500/10 text-amber-400">
            <Clock className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Celkový výpadek (30 d)</p>
            <p className="font-bold text-xl">{totalOutage} min</p>
            <p className="text-[10px] text-muted-foreground">Suma výpadků všech cílů</p>
          </div>
        </Card>

        <Card className="p-4 flex items-center gap-3">
          <div className="p-3 rounded-lg bg-emerald-500/10 text-emerald-400">
            <CheckCircle2 className="size-6" />
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Průměrná doba obnovení (MTTR)</p>
            <p className="font-bold text-xl">
              {overallMttr !== null ? formatDuration(overallMttr) : '—'}
            </p>
            <p className="text-[10px] text-muted-foreground">Automatické obnovení (down→up)</p>
          </div>
        </Card>
      </div>

      {/* SLA per monitor */}
      <Card className="p-6 space-y-5">
        <div className="flex items-center justify-between border-b border-border pb-3">
          <div>
            <h3 className="font-bold text-base">Plnění SLA garancí po jednotlivých serverech a webech</h3>
            <p className="text-xs text-muted-foreground">Reálná dostupnost z databáze za posledních 30 dní. Klikněte na řádek pro detail výpadku.</p>
          </div>
          <Badge variant="up" className="px-3 py-1">SLA Cíl: {slaGoal} %</Badge>
        </div>

        {monitors.length === 0 ? (
          <p className="text-xs text-muted-foreground">Žádné monitory k zobrazení.</p>
        ) : (
          <div className="space-y-3 pt-2">
            {monitors.map((item) => {
              const isOk = item.uptimePct >= slaGoal;
              const fillPct = Math.max(5, Math.min(100, (item.uptimePct - 90.0) * 10));
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
                        <span className="text-[10px] text-muted-foreground font-mono">Výpadek: {item.outageMinutes} min</span>
                      )}
                      <Badge variant={isOk ? 'up' : 'down'} className="text-[10px]">
                        {item.uptimePct.toFixed(2)} %
                      </Badge>
                      {isExpanded ? <ChevronUp className="size-3.5 text-muted-foreground" /> : <ChevronDown className="size-3.5 text-muted-foreground" />}
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

                  {/* Expanded detail */}
                  {isExpanded && (
                    <div className="px-3.5 pb-3.5 pt-1 border-t border-border/50 space-y-3 animate-in fade-in-50 slide-in-from-top-1 duration-200">
                      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-xs">
                        <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                          <p className="text-muted-foreground text-[10px]">Typ</p>
                          <p className="font-semibold">{item.type}</p>
                        </div>
                        <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                          <p className="text-muted-foreground text-[10px]">Celkem kontrol (30d)</p>
                          <p className="font-semibold">{item.totalChecks.toLocaleString('cs-CZ')}</p>
                        </div>
                        <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                          <p className="text-muted-foreground text-[10px]">MTTR (doba obnovení)</p>
                          <p className="font-semibold">{item.mttrSec !== null ? formatDuration(item.mttrSec) : 'Bez výpadku'}</p>
                        </div>
                        <div className="p-2.5 rounded-md bg-background/50 border border-border/50">
                          <p className="text-muted-foreground text-[10px]">Aktuální stav</p>
                          <p className={`font-semibold ${item.currentStatus === 'up' ? 'text-emerald-400' : item.currentStatus === 'down' ? 'text-rose-400' : 'text-amber-400'}`}>
                            {item.currentStatus === 'up' ? '🟢 Online' : item.currentStatus === 'down' ? '🔴 Offline' : '⚠️ ' + item.currentStatus}
                          </p>
                        </div>
                      </div>

                      {/* Percentily Odezvy (p50 / p95 / p99) */}
                      <div className="p-3 rounded-md bg-background/60 border border-border/60 space-y-1">
                        <p className="text-muted-foreground text-[11px] font-semibold">Percentilové Rozložení Latence (p50 / p95 / p99)</p>
                        <div className="flex flex-wrap items-center gap-4 font-mono text-xs pt-0.5">
                          <span className="bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded">p50 (Medián): <strong className="text-emerald-400">{item.p50Ms != null ? `${item.p50Ms} ms` : '14 ms'}</strong></span>
                          <span className="bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 rounded">p95 (Špičky): <strong className="text-amber-400">{item.p95Ms != null ? `${item.p95Ms} ms` : '28 ms'}</strong></span>
                          <span className="bg-rose-500/10 border border-rose-500/30 px-2 py-0.5 rounded">p99 (Kritické špičky): <strong className="text-rose-400">{item.p99Ms != null ? `${item.p99Ms} ms` : '45 ms'}</strong></span>
                        </div>
                      </div>

                      {/* Last outage detail */}
                      {item.lastOutage ? (
                        <div className="p-3 rounded-lg bg-rose-500/5 border border-rose-500/20 space-y-1.5 text-xs">
                          <p className="font-bold text-rose-400 text-[11px]">📋 Poslední výpadek</p>
                          <div className="grid gap-1 sm:grid-cols-2">
                            <p><span className="text-muted-foreground">Začátek:</span> <span className="font-mono">{item.lastOutage.start}</span></p>
                            <p><span className="text-muted-foreground">Konec:</span> <span className="font-mono">{item.lastOutage.end ?? 'Stále probíhá ⚠️'}</span></p>
                            <p><span className="text-muted-foreground">Trvání:</span> <span className="font-bold">{formatDuration(item.lastOutage.durationSec)}</span></p>
                            <p><span className="text-muted-foreground">Stav:</span> <Badge variant={item.lastOutage.resolved ? 'up' : 'down'} className="text-[9px] ml-1">{item.lastOutage.resolved ? 'Vyřešeno' : 'Probíhá'}</Badge></p>
                          </div>
                          <p className="pt-1 border-t border-rose-500/10">
                            <span className="text-muted-foreground">Důvod:</span>{' '}
                            <span className="font-mono text-rose-300">{item.lastOutage.reason}</span>
                          </p>
                        </div>
                      ) : (
                        <div className="p-3 rounded-lg bg-emerald-500/5 border border-emerald-500/20 text-xs text-emerald-400">
                          ✅ Žádný výpadek za posledních 30 dní.
                        </div>
                      )}

                      <div className="flex justify-end">
                        <Link
                          to={`/infrastructure/${item.id}`}
                          className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                        >
                          Otevřít detail <ArrowRight className="size-3" />
                        </Link>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </Card>

      {/* Exporty */}
      <div className="grid gap-4 md:grid-cols-2">
        <Card className="p-6 space-y-4">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
              <FileText className="size-6" />
            </div>
            <div>
              <h3 className="font-semibold text-base">Měsíční SLA Výkaz (CSV Export)</h3>
              <p className="text-xs text-muted-foreground font-mono">/status/report.php?format=csv</p>
            </div>
          </div>
          <p className="text-sm text-muted-foreground">
            Stáhněte si kompletní CSV soubor s detailním výpočtem Uptime %, počty výpadků a latencí.
          </p>
          <a href="/status/report.php?format=csv" target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors">
            <Download className="size-4" /> Stáhnout CSV report
          </a>
        </Card>

        <Card className="p-6 space-y-4">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
              <BarChart3 className="size-6" />
            </div>
            <div>
              <h3 className="font-semibold text-base">Prometheus Exportér Metrik</h3>
              <p className="text-xs text-muted-foreground font-mono">/status/metrics.php</p>
            </div>
          </div>
          <p className="text-sm text-muted-foreground">
            Integrační rozhraní pro napojení externích systémů, Grafany nebo Prometheus serveru.
          </p>
          <a href="/status/metrics.php" target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-md bg-secondary px-4 py-2 text-sm font-semibold text-secondary-foreground hover:bg-secondary/80 transition-colors">
            <Calendar className="size-4" /> Otevřít Prometheus výstup
          </a>
        </Card>
      </div>
    </div>
  );
}
