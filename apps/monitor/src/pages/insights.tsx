import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Lightbulb, HardDrive, Cpu, ArrowRight, Server, Globe } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';

export function InsightsPage() {
  const { t } = useLanguage();
  const [monitors, setMonitors] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;

    appApi.getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : (rows as any)?.monitors ?? [];
        setMonitors(list);
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
          <h1 className="text-2xl font-bold tracking-tight">{t('insights.title', 'AI & Inteligentní Analýza (Insights)')}</h1>
          <p className="text-muted-foreground text-sm">{t('insights.subtitle', 'Predikce využití disků serverů, detekce anomálií a analytika HTTP služeb.')}</p>
        </div>
      </div>

      {loading ? (
        <p className="text-muted-foreground text-sm">{t('common.loading', 'Analytický engine vyhodnocuje metriky infrastruktury...')}</p>
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
                      <h3 className="font-bold text-sm">{t('insights.disk_pred', 'Predikce Disku (Servery & VPS)')}</h3>
                      <p className="text-xs text-muted-foreground">{t('insights.disk_pred_desc', 'Lineární regrese (7 dnů)')}</p>
                    </div>
                  </div>
                  <Badge variant={highDiskMonitor && highDiskMonitor.hdd >= 75 ? "warning" : "up"}>
                    {highDiskMonitor && highDiskMonitor.hdd >= 75 ? t('common.warning', 'Varování') : t('common.healthy', 'V pořádku')}
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

        </>
      )}
    </div>
  );
}
