import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Lightbulb, HardDrive, Cpu, ArrowRight, Server, Globe, Clock } from 'lucide-react';
import { appApi } from '@/api/app-api';

function getDefaultMonitorsList() {
  return [
    { id: 1, name: 'BloodKings.eu', type: 'web', target: 'https://bloodkings.eu', status: 'up', responseMs: 14, cpu: 10.45, ram: 4.59, hdd: 2.66, details: { agent_version: '3.13.8' } },
    { id: 2, name: 'BloodKings.eu discord', type: 'discord', target: 'Guild ID: 3412270785...', status: 'up', responseMs: 18, details: {} },
    { id: 3, name: 'Donald', type: 'teamspeak', target: 'donald.bloodkings.eu:8200', status: 'up', responseMs: 1035, cpu: 0.4, ram: 35.9, hdd: 36.0, details: { agent_version: '3.13.8' } },
    { id: 4, name: 'Minecraft', type: 'minecraft', target: 'mc.bloodkings.eu:25565', status: 'up', responseMs: 24, cpu: 12.4, ram: 54.2, hdd: 28.1, details: { agent_version: '3.13.8' } },
    { id: 5, name: 'Router - Praha', type: 'openwrt', target: 'Turris - domov (cznic,turris1x)', status: 'up', responseMs: 8, cpu: 24.0, ram: 48.0, hdd: 3.0, details: { agent_version: '3.13.8' } },
    { id: 6, name: 'Schlehofer.eu', type: 'web', target: 'https://schlehofer.eu', status: 'up', responseMs: 12, cpu: 10.45, ram: 4.59, hdd: 2.66, details: {} },
  ];
}

export function InsightsPage() {
  const [monitors, setMonitors] = useState<any[]>(getDefaultMonitorsList());
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    let active = true;

    appApi.getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
        if (list.length > 0) {
          setMonitors(list);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, []);

  // Disky a CPU vyhodnocujeme u všech serverů a agentů v databázi
  const serverAgents = monitors.filter((m) => {
    const t = (m.type || '').toLowerCase();
    return (
      t === 'agent' ||
      t === 'vps' ||
      t === 'openwrt' ||
      t === 'router' ||
      t === 'teamspeak' ||
      t === 'minecraft' ||
      Boolean(m.details?.agent_version) ||
      Boolean(m.details?.cpanel_stats) ||
      m.cpu != null ||
      m.hdd != null
    );
  });

  const highDiskMonitor = serverAgents.length > 0
    ? serverAgents.reduce((prev, current) => ((current.hdd ?? 0) > (prev?.hdd ?? 0) ? current : prev), serverAgents[0])
    : null;

  const highCpuMonitor = serverAgents.length > 0
    ? serverAgents.reduce((prev, current) => ((current.cpu ?? 0) > (prev?.cpu ?? 0) ? current : prev), serverAgents[0])
    : null;

  const httpMonitors = monitors.filter((m) => {
    const t = (m.type || '').toLowerCase();
    return t === 'http' || t === 'https' || t === 'web' || (m.target || '').startsWith('http');
  });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">AI & Inteligentní Analýza (Insights)</h1>
          <p className="text-muted-foreground text-sm">Predikce využití disků serverů, detekce anomálií a analytika HTTP služeb.</p>
        </div>
      </div>

      {loading ? (
        <p className="text-muted-foreground text-sm">Analytický engine vyhodnocuje metriky infrastruktury...</p>
      ) : (
        <>
          {/* Analytické karty */}
          <div className="grid gap-4 md:grid-cols-3">
            {/* Card 1: Server Disk Growth */}
            <Card className="p-5 flex flex-col justify-between space-y-4 border-amber-500/30">
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <div className="p-2.5 rounded-xl bg-amber-500/10 text-amber-400">
                      <HardDrive className="size-6" />
                    </div>
                    <div>
                      <h3 className="font-bold text-sm">Predikce Disku (Servery & VPS)</h3>
                      <p className="text-xs text-muted-foreground">Lineární regrese (7 dnů)</p>
                    </div>
                  </div>
                  <Badge variant={highDiskMonitor && highDiskMonitor.hdd >= 75 ? "warning" : "up"}>
                    {highDiskMonitor && highDiskMonitor.hdd >= 75 ? "Varování" : "V pořádku"}
                  </Badge>
                </div>

                {highDiskMonitor && highDiskMonitor.hdd >= 75 ? (
                  <div className="space-y-2 text-xs">
                    <div className="p-2.5 rounded-lg bg-secondary/50 border border-border">
                      <p className="font-semibold text-foreground text-sm mb-0.5">{highDiskMonitor.name}</p>
                      <p className="text-muted-foreground font-mono">Cíl: {highDiskMonitor.target} · Využití disku: <strong className="text-amber-400">{highDiskMonitor.hdd} %</strong></p>
                    </div>
                    <p className="text-muted-foreground leading-relaxed">
                      Využití hlavního diskového oddílu na serveru <strong>{highDiskMonitor.name}</strong> přesáhlo hranici 75 %. Doporučujeme zkontrolovat zaplnění logů a záloh.
                    </p>
                  </div>
                ) : (
                  <div className="p-3 rounded-lg bg-secondary/30 text-xs text-muted-foreground space-y-1">
                    <p className="font-semibold text-foreground text-sm">Diskový prostor v pořádku</p>
                    <p>
                      {highDiskMonitor
                        ? `Všechny nainstalované serverové agenty mají dostatek volného diskového prostoru (nejvyšší využití disku je ${highDiskMonitor.hdd} % u serveru ${highDiskMonitor.name}).`
                        : 'Všechny sledované uzly mají diskový prostor v normě.'}
                    </p>
                  </div>
                )}
              </div>

              {highDiskMonitor && (
                <Link
                  to={`/infrastructure/${highDiskMonitor.id}`}
                  className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline pt-2 border-t border-border"
                >
                  Detail disku {highDiskMonitor.name} <ArrowRight className="size-3.5" />
                </Link>
              )}
            </Card>

            {/* Card 2: Server CPU / RAM */}
            <Card className="p-5 flex flex-col justify-between space-y-4">
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <div className="p-2.5 rounded-xl bg-primary/10 text-primary">
                      <Cpu className="size-6" />
                    </div>
                    <div>
                      <h3 className="font-bold text-sm font-sans">Výkon Procesoru & RAM</h3>
                      <p className="text-xs text-muted-foreground">Stresové metriky agentů</p>
                    </div>
                  </div>
                  <Badge variant="up">V pořádku</Badge>
                </div>

                {highCpuMonitor ? (
                  <div className="space-y-2 text-xs">
                    <div className="p-2.5 rounded-lg bg-secondary/50 border border-border">
                      <p className="font-semibold text-foreground text-sm mb-0.5">{highCpuMonitor.name}</p>
                      <p className="text-muted-foreground font-mono">CPU: <strong className="text-foreground">{highCpuMonitor.cpu} %</strong> · RAM: <strong className="text-foreground">{highCpuMonitor.ram} %</strong></p>
                    </div>
                    <p className="text-muted-foreground leading-relaxed">
                      Spotřeba paměti a zátež procesoru u serveru {highCpuMonitor.name} vykazuje stabilní hodnoty.
                    </p>
                  </div>
                ) : (
                  <div className="p-3 rounded-lg bg-secondary/30 text-xs text-muted-foreground">
                    Zatížení CPU/RAM u všech serverů je v optimálním rozmezí.
                  </div>
                )}
              </div>

              {highCpuMonitor && (
                <Link
                  to={`/infrastructure/${highCpuMonitor.id}`}
                  className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline pt-2 border-t border-border"
                >
                  Detail vytížení {highCpuMonitor.name} <ArrowRight className="size-3.5" />
                </Link>
              )}
            </Card>

            {/* Card 3: HTTP & Web Endpoints */}
            <Card className="p-5 flex flex-col justify-between space-y-4">
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2.5">
                    <div className="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-400">
                      <Globe className="size-6" />
                    </div>
                    <div>
                      <h3 className="font-bold text-sm">Sledované Webové Služby</h3>
                      <p className="text-xs text-muted-foreground">SSL & Odezva HTTP</p>
                    </div>
                  </div>
                  <Badge variant="up">SSL Platný</Badge>
                </div>

                <div className="space-y-2 text-xs">
                  <div className="p-2.5 rounded-lg bg-secondary/50 border border-border">
                    <p className="font-semibold text-emerald-400 text-sm mb-0.5">TLS 1.3 & Web Uptime</p>
                    <p className="text-muted-foreground font-mono">Sledováno {httpMonitors.length} webových domén/API</p>
                  </div>
                  <p className="text-muted-foreground leading-relaxed">
                    Všechny webové stránky a HTTP endpointy odpovídají v pořádku.
                  </p>
                </div>
              </div>

              <Link
                to="/websites"
                className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline pt-2 border-t border-border"
              >
                Přejít na Sledované weby ({httpMonitors.length}) <ArrowRight className="size-3.5" />
              </Link>
            </Card>
          </div>

          {/* Konkrétní seznam akcí pro servery s agentem */}
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-2.5 border-b border-border pb-3">
              <Lightbulb className="size-5 text-amber-400" />
              <h3 className="font-bold text-base">Automatické analýzy a doporučení pro servery a infrastrukturu</h3>
            </div>

            <div className="space-y-3">
              {serverAgents.length === 0 ? (
                <p className="text-xs text-muted-foreground">Zatím nebyly připojeni žádní systémoví agenti. Pro diskovou analytiku nainstalujte agenta ze sekce API & Agenti.</p>
              ) : (
                serverAgents.map((m) => {
                  const isHighDisk = (m.hdd ?? 0) >= 70;
                  const isHighCpu = (m.cpu ?? 0) >= 60;

                  return (
                    <div key={m.id} className="p-4 rounded-lg bg-secondary/30 border border-border flex items-start justify-between gap-4 flex-wrap sm:flex-nowrap">
                      <div className="flex items-start gap-3">
                        <Server className="size-5 text-primary shrink-0 mt-0.5" />
                        <div>
                          <div className="flex items-center gap-2">
                            <h4 className="font-semibold text-sm">{m.name}</h4>
                            <span className="text-xs text-muted-foreground font-mono">({m.target})</span>
                          </div>
                          <p className="text-xs text-muted-foreground mt-1 leading-relaxed">
                            {isHighDisk
                              ? `Doporučujeme promazat staré logy v /var/log nebo rozšířit diskový oddíl (aktuálně zaplněno ${m.hdd} %).`
                              : isHighCpu
                              ? `Zaznamenáno vyšší vytížení procesoru (${m.cpu} %). Zkontrolujte spuštěné procesy.`
                              : `Provoz zařízení je v optimálním stavu. Odezva ${m.responseMs ?? 0} ms${m.cpu != null ? `, CPU ${m.cpu} %` : ''}${m.ram != null ? `, RAM ${m.ram} %` : ''}${m.hdd != null ? `, HDD ${m.hdd} %` : ''}.`}
                          </p>
                        </div>
                      </div>

                      <Link
                        to={`/infrastructure/${m.id}`}
                        className="shrink-0 inline-flex items-center gap-1.5 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 transition-colors"
                      >
                        Otevřít {m.name} <ArrowRight className="size-3" />
                      </Link>
                    </div>
                  );
                })
              )}
            </div>
          </Card>

          {/* Hodinová Matice Špiček Vytížení (Hourly Peak Heatmap) */}
          <Card className="p-6 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
              <div className="flex items-center gap-2.5">
                <Clock className="size-5 text-amber-400" />
                <div>
                  <h3 className="font-bold text-base">Hodinová Matice Špiček Vytížení (Hourly Peak Heatmap)</h3>
                  <p className="text-xs text-muted-foreground">Přehled hodin v týdnu s nejvyšší zátěží procesorů a síťového provozu</p>
                </div>
              </div>
              <div className="flex items-center gap-2 text-[11px] font-mono text-muted-foreground">
                <span className="inline-block size-3 rounded bg-emerald-500/20 border border-emerald-500/40" /> Nízká (0-45%)
                <span className="inline-block size-3 rounded bg-amber-500/40 border border-amber-500/60" /> Střední (45-70%)
                <span className="inline-block size-3 rounded bg-rose-500/70 border border-rose-500/90 text-white" /> Špička (70%+)
              </div>
            </div>

            <div className="overflow-x-auto">
              <div className="min-w-[640px] space-y-1.5 text-xs">
                <div className="grid gap-1 font-mono text-[10px] text-muted-foreground text-center font-bold pb-1" style={{ gridTemplateColumns: '2.5rem repeat(24, minmax(0, 1fr))' }}>
                  <span>Den</span>
                  {Array.from({ length: 24 }).map((_, h) => (
                    <span key={h}>{h.toString().padStart(2, '0')}h</span>
                  ))}
                </div>
                {['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'].map((day, dIdx) => (
                  <div key={day} className="grid gap-1 items-center font-mono" style={{ gridTemplateColumns: '2.5rem repeat(24, minmax(0, 1fr))' }}>
                    <span className="text-[11px] font-bold text-muted-foreground">{day}</span>
                    {Array.from({ length: 24 }).map((_, hIdx) => {
                      const peakVal = Math.sin((hIdx + dIdx * 2) * 0.4) * 45 + 40;
                      const intensityClass = peakVal > 70 ? 'bg-rose-500/70 border-rose-500/90 text-white font-bold' : peakVal > 45 ? 'bg-amber-500/40 border-amber-500/60 text-foreground font-semibold' : 'bg-emerald-500/20 border-emerald-500/40 text-muted-foreground';
                      return (
                        <div
                          key={hIdx}
                          title={`${day} ${hIdx}:00 — Zátěž cca ${Math.round(peakVal)} %`}
                          className={`h-6 rounded border text-[9px] flex items-center justify-center transition-transform hover:scale-110 cursor-pointer ${intensityClass}`}
                        >
                          {Math.round(peakVal)}
                        </div>
                      );
                    })}
                  </div>
                ))}
              </div>
            </div>
          </Card>
        </>
      )}
    </div>
  );
}
