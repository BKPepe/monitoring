import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, CheckCircle2, AlertTriangle, ArrowRight, Radio } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { usePublicStatus } from '@/api/use-asset-charts';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { EventsHistoryTable } from '@/components/events-history-table';

export function IncidentsPage() {
  const { t } = useLanguage();
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);
  const [targetMonitors, setTargetMonitors] = useState<any[]>([]);
  const [probingNodes, setProbingNodes] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNewIncidentModal, setShowNewIncidentModal] = useState(false);
  const [incidentTitle, setIncidentTitle] = useState('');
  const [incidentDetail, setIncidentDetail] = useState('');
  const [affectedScope, setAffectedScope] = useState<string>('all');
  const [manualIncidents, setManualIncidents] = useState<any[]>([]);
  const [dbIncidents, setDbIncidents] = useState<any[]>([]);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const { data: publicData } = usePublicStatus();

  const loadIncidents = () => {
    fetch('/status/api.php?action=incidents', { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (data && Array.isArray(data.incidents)) setDbIncidents(data.incidents);
        if (data && Array.isArray(data.manualIncidents)) setManualIncidents(data.manualIncidents);
      })
      .catch(() => {});
  };

  useEffect(() => {
    let active = true;

    loadIncidents();

    appApi.getMonitors()
      .then((rows) => {
        if (!active) return;
        if (Array.isArray(rows) && rows.length > 0) {
          const targets = rows.filter((m: any) => {
            const type = (m.type || '').toLowerCase();
            return type !== 'node' && type !== 'probe';
          });
          const probes = rows.filter((m: any) => {
            const type = (m.type || '').toLowerCase();
            return type === 'node' || type === 'probe';
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

  const activeTargetOutages = targetMonitors.filter((m) => m.status === 'down' || m.status === 'warning');

  const handleCreateIncident = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAuthenticated || !incidentTitle) return;

    const affectedName = affectedScope === 'all'
      ? t('incidents.all_scope', 'Všechny služby (Globální incident)')
      : targetMonitors.find(m => String(m.id) === affectedScope)?.name || t('incidents.selected_monitor', 'Vybraný monitor');

    setCreating(true);
    setCreateError(null);
    try {
      const res = await fetch('/status/api.php?action=create_incident', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          title: incidentTitle,
          message: `${incidentDetail || t('incidents.default_detail', 'Ručně nahlášený incident.')} [${t('incidents.scope_prefix', 'Rozsah')}: ${affectedName}]`,
          impact: 'minor',
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);

      loadIncidents();
      setIncidentTitle('');
      setIncidentDetail('');
      setAffectedScope('all');
      setShowNewIncidentModal(false);
    } catch (err) {
      setCreateError(err instanceof Error ? err.message : t('incidents.save_error', 'Incident se nepodařilo uložit.'));
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">{t('incidents.title', 'Správa Incidentů a Výpadků')}</h1>
          <p className="text-muted-foreground text-sm">{t('incidents.subtitle', 'Oddělený přehled výpadků cílových služeb a stavu měřících agentů/lokací.')}</p>
        </div>
        {isAuthenticated ? (
          <button
            type="button"
            onClick={() => setShowNewIncidentModal(true)}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors cursor-pointer"
          >
            <Plus className="size-4" /> {t('incidents.create', 'Nahlásit nový incident')}
          </button>
        ) : (
          <button
            type="button"
            disabled
            title={t('incidents.login_required_hint', 'Pro zakládání incidentů se musíte přihlásit jako administrátor')}
            className="inline-flex items-center gap-2 rounded-md bg-secondary text-muted-foreground px-4 py-2 text-sm font-semibold cursor-not-allowed opacity-60"
          >
            <Plus className="size-4" /> {t('incidents.create', 'Nahlásit nový incident')} ({t('common.login_required', 'Vyžaduje přihlášení')})
          </button>
        )}
      </div>

      {!isAuthenticated && (
        <Card className="p-4 bg-amber-500/10 border-amber-500/30 flex items-center justify-between">
          <p className="text-xs text-amber-800 dark:text-amber-300 font-medium">
            {t('incidents.public_notice', 'Prohlížení incidentů je veřejné. Pro ruční zakládání a úpravu incidentů se přihlaste.')}
          </p>
          <Link to="/setup" className="text-xs font-semibold text-primary hover:underline">
            {t('btn.login', 'Přihlásit se')} →
          </Link>
        </Card>
      )}

      {showNewIncidentModal && isAuthenticated && (
        <Card className="p-6 border-primary/50 bg-secondary/40">
          <h3 className="font-bold text-base mb-3">{t('incidents.create_modal_title', 'Nahlásit nový incident / Plánovanou údržbu')}</h3>
          <form onSubmit={handleCreateIncident} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">{t('incidents.name_label', 'Název incidentu')}</label>
                <input
                  type="text"
                  placeholder={t('incidents.name_placeholder', 'např. Neplánovaná údržba databáze')}
                  value={incidentTitle}
                  onChange={(e) => setIncidentTitle(e.target.value)}
                  required
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">{t('incidents.scope_label', 'Zasažená služba / Rozsah')}</label>
                <select
                  value={affectedScope}
                  onChange={(e) => setAffectedScope(e.target.value)}
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm cursor-pointer"
                >
                  <option value="all">🌐 {t('incidents.scope_all_option', 'Všechny služby (Globální výpadek)')}</option>
                  {targetMonitors.map(m => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.type} - {m.target})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-muted-foreground mb-1">{t('incidents.detail_label', 'Detailní popis')}</label>
              <textarea
                placeholder={t('incidents.detail_placeholder', 'Popis problému, předpokládaná doba vyřešení...')}
                value={incidentDetail}
                onChange={(e) => setIncidentDetail(e.target.value)}
                rows={3}
                className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
              />
            </div>

            {createError && (
              <p className="text-xs font-semibold text-destructive">{createError}</p>
            )}

            <div className="flex items-center justify-end gap-2">
              <button
                type="button"
                onClick={() => setShowNewIncidentModal(false)}
                className="px-4 py-2 rounded-md bg-secondary text-sm font-medium hover:bg-secondary/80 cursor-pointer"
              >
                {t('common.cancel', 'Zrušit')}
              </button>
              <button
                type="submit"
                disabled={creating}
                className="px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90 cursor-pointer disabled:opacity-50"
              >
                {creating ? t('common.saving', 'Ukládám…') : t('incidents.save_btn', 'Uložit incident')}
              </button>
            </div>
          </form>
        </Card>
      )}

      {loading ? (
        <p className="text-muted-foreground text-sm">{t('incidents.loading', 'Načítám stav incidentů...')}</p>
      ) : (
        <div className="space-y-6">
          {/* Section 1: Active target service outages */}
          <Card className="p-6 space-y-4 border-rose-500/40">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <div className="flex items-center gap-2.5">
                <AlertTriangle className="size-5 text-rose-500" />
                <h3 className="font-bold text-base">{t('incidents.active_outages', 'Probíhající výpadky cílových služeb')} ({activeTargetOutages.length})</h3>
              </div>
              <Badge variant={activeTargetOutages.length > 0 ? 'down' : 'up'}>
                {activeTargetOutages.length > 0 ? `${activeTargetOutages.length} ${t('incidents.active_badge', 'Aktivní výpadek')}` : t('status.healthy', 'Všechny služby OK')}
              </Badge>
            </div>

            {activeTargetOutages.length === 0 && manualIncidents.length === 0 && dbIncidents.length === 0 ? (
              <div className="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center gap-3">
                <CheckCircle2 className="size-5 text-emerald-400 shrink-0" />
                <p className="text-xs text-emerald-800 dark:text-emerald-300 font-medium">
                  {t('incidents.all_ok', 'Všechny sledované cílové monitory a servery (weby, Minecraft, TeamSpeak, routery) běží v pořádku bez výpadků.')}
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
                      <p className="text-xs font-mono text-muted-foreground">{t('common.target', 'Cíl')}: {inc.target}</p>
                      <p className="text-xs text-down font-medium">{inc.reason}</p>
                      <div className="flex items-center gap-3 pt-1 text-[11px] font-mono text-muted-foreground flex-wrap">
                        <span>{t('incidents.outage_start', 'Začátek výpadku')}: <strong className="text-foreground">{inc.started_at}</strong></span>
                        {inc.resolved_at && <span>{t('incidents.outage_end', 'Konec')}: <strong className="text-emerald-400">{inc.resolved_at}</strong></span>}
                        <span className="px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-amber-300 font-bold">
                          {t('incidents.duration', 'Doba trvání')}: {inc.duration_text}
                        </span>
                      </div>
                    </div>

                    <Link
                      to={`/infrastructure/${inc.monitor_id}`}
                      className="shrink-0 inline-flex items-center gap-1.5 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 transition-colors"
                    >
                      {t('incidents.view_outage', 'Detail výpadku')} <ArrowRight className="size-3" />
                    </Link>
                  </div>
                ))}

                {manualIncidents.map((inc) => (
                  <div key={inc.id} className="p-4 rounded-lg bg-amber-500/10 border border-amber-500/30 flex items-start justify-between gap-4">
                    <div className="space-y-1">
                      <div className="flex items-center gap-2">
                        <span className={`size-2.5 rounded-full ${inc.status === 'resolved' ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse'}`} />
                        <h4 className="font-bold text-sm text-foreground">{inc.title}</h4>
                        <Badge variant="warning">{t('incidents.manual_badge', 'Ručně nahlášeno')}</Badge>
                        <Badge variant={inc.status === 'resolved' ? 'up' : 'warning'}>{inc.status}</Badge>
                      </div>
                      {inc.updates?.[0]?.message && (
                        <p className="text-xs text-muted-foreground">{inc.updates[0].message}</p>
                      )}
                      <div className="flex items-center gap-3 pt-1 text-[11px] font-mono text-warning flex-wrap">
                        <span>{t('incidents.created_label', 'Vytvořeno')}: {inc.createdAt}</span>
                        {inc.resolvedAt && <span>{t('incidents.resolved_label', 'Vyřešeno')}: {inc.resolvedAt}</span>}
                        <span className="px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-amber-300 font-bold">
                          {t('incidents.duration', 'Doba trvání')}: {inc.durationText}
                        </span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Card>

          {/* Section 2: Probing nodes and running agents */}
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-2.5 border-b border-border pb-3">
              <Radio className="size-5 text-primary" />
              <div>
                <h3 className="font-bold text-base">{t('incidents.probing_nodes', 'Stav měřících uzlů a agentů (Probing Infrastructure)')}</h3>
                <p className="text-xs text-muted-foreground">{t('incidents.probing_hint', 'Tyto uzly pouze provádějí měření z různých geografických lokací a NEJSOU cílovými službami.')}</p>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {probingNodes.length === 0 ? (
                <div className="col-span-full p-4 text-xs text-muted-foreground text-center">
                  {t('incidents.probes_ok', 'Všechny testovací sondy pracují bez výpadků.')}
                </div>
              ) : (
                probingNodes.map((node) => (
                  <div key={node.id} className="p-3.5 rounded-lg bg-secondary/40 border border-border flex items-center justify-between">
                    <div>
                      <p className="font-semibold text-xs">{node.name}</p>
                      <p className="text-[11px] text-muted-foreground font-mono">{t('incidents.probe_latency', 'Latence sondy')}: {node.latencyMs ?? 12} ms</p>
                    </div>
                    <Badge variant={node.status === 'up' ? 'up' : 'down'}>
                      {node.status === 'up' ? t('incidents.probe_ok', 'Sonda OK') : t('incidents.probe_offline', 'Sonda OFFLINE')}
                    </Badge>
                  </div>
                ))
              )}
            </div>
          </Card>
        </div>
      )}
      <EventsHistoryTable />
    </div>
  );
}
