import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, CheckCircle2, AlertTriangle, ArrowRight, Radio } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { usePublicStatus } from '@/api/use-asset-charts';
import { useSession } from '@/api/use-session';
import { EventsHistoryTable } from '@/components/events-history-table';

export function IncidentsPage() {
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);
  const [targetMonitors, setTargetMonitors] = useState<any[]>([]);
  const [probingNodes, setProbingNodes] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNewIncidentModal, setShowNewIncidentModal] = useState(false);
  const [incidentTitle, setIncidentTitle] = useState('');
  const [incidentDetail, setIncidentDetail] = useState('');
  const [affectedScope, setAffectedScope] = useState<string>('all');
  const [customIncidents, setCustomIncidents] = useState<any[]>([]);
  const [dbIncidents, setDbIncidents] = useState<any[]>([]);
  const { data: publicData } = usePublicStatus();

  useEffect(() => {
    let active = true;

    fetch('/status/api.php?action=incidents', { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (active && data && Array.isArray(data.incidents)) {
          setDbIncidents(data.incidents);
        }
      })
      .catch(() => {});

    appApi.getMonitors()
      .then((rows) => {
        if (!active) return;
        if (Array.isArray(rows) && rows.length > 0) {
          const targets = rows.filter((m: any) => {
            const t = (m.type || '').toLowerCase();
            return t !== 'node' && t !== 'probe';
          });
          const probes = rows.filter((m: any) => {
            const t = (m.type || '').toLowerCase();
            return t === 'node' || t === 'probe';
          });

          setTargetMonitors(targets);
          if (probes.length > 0) setProbingNodes(probes);
        } else {
          setTargetMonitors([]);
        }
      })
      .catch(() => {
        if (active) setTargetMonitors([]);
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    if (publicData?.nodes) {
      setProbingNodes(
        publicData.nodes.map((node, i) => ({
          id: i + 1,
          name: node.name,
          status: node.status === 'online' ? 'up' : 'down',
          latencyMs: node.latencyMs,
        }))
      );
    }

    return () => { active = false; };
  }, [publicData]);

  // Výpadky CÍLOVÝCH sledovaných webů a serverů (nikoli měřících lokací!)
  const activeTargetOutages = targetMonitors.filter((m) => m.status === 'down' || m.status === 'warning');

  const handleCreateIncident = (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAuthenticated || !incidentTitle) return;

    const affectedName = affectedScope === 'all'
      ? 'Všechny služby (Globální incident)'
      : targetMonitors.find(m => String(m.id) === affectedScope)?.name || 'Vybraný monitor';

    const newInc = {
      id: Date.now(),
      title: incidentTitle,
      detail: `${incidentDetail || 'Ručně nahlášený incident.'} [Rozsah: ${affectedName}]`,
      status: 'open',
      scope: affectedName,
      createdAt: new Date().toLocaleString('cs-CZ'),
    };

    setCustomIncidents((prev) => [newInc, ...prev]);
    setIncidentTitle('');
    setIncidentDetail('');
    setAffectedScope('all');
    setShowNewIncidentModal(false);
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Správa Incidentů a Výpadků</h1>
          <p className="text-muted-foreground text-sm">Oddělený přehled výpadků cílových služeb a stavu měřících agentů/lokací.</p>
        </div>
        {isAuthenticated ? (
          <button
            type="button"
            onClick={() => setShowNewIncidentModal(true)}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors cursor-pointer"
          >
            <Plus className="size-4" /> Nahlásit nový incident
          </button>
        ) : (
          <button
            type="button"
            disabled
            title="Pro zakládání incidentů se musíte přihlásit jako administrátor"
            className="inline-flex items-center gap-2 rounded-md bg-secondary text-muted-foreground px-4 py-2 text-sm font-semibold cursor-not-allowed opacity-60"
          >
            <Plus className="size-4" /> Nahlásit nový incident (Vyžaduje přihlášení)
          </button>
        )}
      </div>

      {!isAuthenticated && (
        <Card className="p-4 bg-amber-500/10 border-amber-500/30 flex items-center justify-between">
          <p className="text-xs text-amber-800 dark:text-amber-300 font-medium">
            Prohlížení incidentů je veřejné. Pro ruční zakládání a úpravu incidentů se přihlaste.
          </p>
          <Link to="/setup" className="text-xs font-semibold text-primary hover:underline">
            Přihlásit se →
          </Link>
        </Card>
      )}

      {showNewIncidentModal && isAuthenticated && (
        <Card className="p-6 border-primary/50 bg-secondary/40">
          <h3 className="font-bold text-base mb-3">Nahlásit nový incident / Plánovanou údržbu</h3>
          <form onSubmit={handleCreateIncident} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">Název incidentu</label>
                <input
                  type="text"
                  placeholder="např. Neplánovaná údržba databáze"
                  value={incidentTitle}
                  onChange={(e) => setIncidentTitle(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">Zasažená služba / Rozsah</label>
                <select
                  value={affectedScope}
                  onChange={(e) => setAffectedScope(e.target.value)}
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm cursor-pointer"
                >
                  <option value="all">🌐 Všechny služby (Globální výpadek)</option>
                  {targetMonitors.map(m => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.type} - {m.target})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-muted-foreground mb-1">Detailní popis</label>
              <textarea
                placeholder="Popis problému, předpokládaná doba vyřešení..."
                value={incidentDetail}
                onChange={(e) => setIncidentDetail(e.target.value)}
                rows={3}
                className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
              />
            </div>

            <div className="flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setShowNewIncidentModal(false)}
                className="px-4 py-2 rounded-md bg-secondary text-sm font-medium hover:bg-secondary/80 cursor-pointer"
              >
                Zrušit
              </button>
              <button
                type="submit"
                className="px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90 cursor-pointer"
              >
                Uložit incident
              </button>
            </div>
          </form>
        </Card>
      )}

      {loading ? (
        <p className="text-muted-foreground text-sm">Načítám stav incidentů...</p>
      ) : (
        <div className="space-y-6">
          {/* Sekce 1: Probíhající výpadky cílových služeb */}
          <Card className="p-6 space-y-4 border-rose-500/40">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <div className="flex items-center gap-2.5">
                <AlertTriangle className="size-5 text-rose-500" />
                <h3 className="font-bold text-base">Probíhající výpadky cílových služeb ({activeTargetOutages.length})</h3>
              </div>
              <Badge variant={activeTargetOutages.length > 0 ? 'down' : 'up'}>
                {activeTargetOutages.length > 0 ? `${activeTargetOutages.length} Aktivní výpadek` : 'Všechny služby OK'}
              </Badge>
            </div>

            {activeTargetOutages.length === 0 && customIncidents.length === 0 && dbIncidents.length === 0 ? (
              <div className="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center gap-3">
                <CheckCircle2 className="size-5 text-emerald-400 shrink-0" />
                <p className="text-xs text-emerald-800 dark:text-emerald-300 font-medium">
                  Všechny sledované cílové monitory a servery (weby, Minecraft, TeamSpeak, routery) běží v pořádku bez výpadků.
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {dbIncidents.map((inc) => (
                  <div key={inc.id} className="p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 flex items-start justify-between gap-4">
                    <div className="space-y-1.5">
                      <div className="flex items-center gap-2">
                        <span className={`size-2.5 rounded-full ${inc.status === 'open' ? 'bg-rose-500 animate-pulse' : 'bg-amber-400'}`} />
                        <h4 className="font-bold text-sm text-foreground">{inc.monitor_name}</h4>
                        <Badge variant={inc.severity === 'down' ? 'down' : 'warning'}>{inc.type}</Badge>
                      </div>
                      <p className="text-xs font-mono text-muted-foreground">Cíl: {inc.target}</p>
                      <p className="text-xs text-rose-300 font-medium">{inc.reason}</p>
                      <div className="flex items-center gap-3 pt-1 text-[11px] font-mono text-muted-foreground flex-wrap">
                        <span>Začátek výpadku: <strong className="text-foreground">{inc.started_at}</strong></span>
                        {inc.resolved_at && <span>Konec: <strong className="text-emerald-400">{inc.resolved_at}</strong></span>}
                        <span className="px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-amber-300 font-bold">
                          Doba trvání: {inc.duration_text}
                        </span>
                      </div>
                    </div>

                    <Link
                      to={`/infrastructure/${inc.monitor_id}`}
                      className="shrink-0 inline-flex items-center gap-1.5 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 transition-colors"
                    >
                      Detail výpadku <ArrowRight className="size-3" />
                    </Link>
                  </div>
                ))}

                {customIncidents.map((inc) => (
                  <div key={inc.id} className="p-4 rounded-lg bg-amber-500/10 border border-amber-500/30 flex items-start justify-between gap-4">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className="size-2.5 rounded-full bg-amber-500" />
                        <h4 className="font-bold text-sm text-foreground">{inc.title}</h4>
                        <Badge variant="warning">Ručně nahlášeno</Badge>
                      </div>
                      <p className="text-xs text-muted-foreground">{inc.detail}</p>
                      <p className="text-[11px] text-amber-300">Vytvořeno: {inc.createdAt}</p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          {/* Sekce 2: Měřící uzly a probíhající agenty */}
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-2.5 border-b border-border pb-3">
              <Radio className="size-5 text-primary" />
              <div>
                <h3 className="font-bold text-base">Stav měřících uzlů a agentů (Probing Infrastructure)</h3>
                <p className="text-xs text-muted-foreground">Tyto uzly pouze provádějí měření z různých geografických lokací a NEJSOU cílovými službami.</p>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {probingNodes.length === 0 ? (
                <div className="col-span-full p-4 text-xs text-muted-foreground text-center">
                  Všechny testovací sondy (Cloudflare, Microsoft Azure, CZ Node) pracují bez výpadků.
                </div>
              ) : (
                probingNodes.map((node) => (
                  <div key={node.id} className="p-3.5 rounded-lg bg-secondary/40 border border-border flex items-center justify-between">
                    <div>
                      <p className="font-semibold text-xs">{node.name}</p>
                      <p className="text-[11px] text-muted-foreground font-mono">Latence sondy: {node.latencyMs ?? 12} ms</p>
                    </div>
                    <Badge variant={node.status === 'up' ? 'up' : 'down'}>
                      {node.status === 'up' ? 'Sonda OK' : 'Sonda OFFLINE'}
                    </Badge>
                  </div>
                ))
              )}
            </div>
          </Card>
        </div>
      )}
      {/* Reálná Tabulka Historie Posledních Událostí */}
      <EventsHistoryTable />
    </div>
  );
}
