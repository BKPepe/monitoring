import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Plus, CheckCircle2, AlertTriangle, ArrowRight, Radio, History } from 'lucide-react';
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
  const [historyLimit, setHistoryLimit] = useState(5);
  const [dbIncidents, setDbIncidents] = useState<any[]>([]);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const { data: publicData } = usePublicStatus();
  // Rozbalený incident (timeline + akce) a rozepsané texty poznámek.
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [noteText, setNoteText] = useState('');
  const [postmortemText, setPostmortemText] = useState('');
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionBusy, setActionBusy] = useState(false);

  const incidentAction = async (id: number, op: string, extra: Record<string, string> = {}) => {
    setActionBusy(true);
    setActionError(null);
    try {
      const res = await fetch('/status/api.php?action=incident_action', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, op, ...extra }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      loadIncidents();
      return true;
    } catch (e) {
      setActionError(e instanceof Error ? e.message : t('incidents.action_failed', 'Akce se nezdařila.'));
      return false;
    } finally {
      setActionBusy(false);
    }
  };

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

    appApi
      .getMonitors()
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

    return () => {
      active = false;
    };
  }, [publicData]);

  const activeTargetOutages = targetMonitors.filter((m) => m.status === 'down' || m.status === 'warning');

  // A resolved incident does not belong under "Ongoing outages".
  //
  // The card counted only monitors that are down right now in its heading, but
  // listed every incident including closed ones. The result was a header saying
  // "Ongoing outages (0) - all systems healthy" with an outage from last month
  // marked "resolved" underneath it. You could not tell whether something is on
  // fire now or whether you are reading history.
  const ongoingIncidents = manualIncidents.filter((inc) => inc.status !== 'resolved');
  const resolvedIncidents = manualIncidents.filter((inc) => inc.status === 'resolved');

  // The count in the heading must match what is listed below it.
  const ongoingCount = activeTargetOutages.length + dbIncidents.length + ongoingIncidents.length;

  const handleCreateIncident = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAuthenticated || !incidentTitle) return;

    const affectedName =
      affectedScope === 'all'
        ? t('incidents.all_scope', 'Všechny služby (Globální incident)')
        : targetMonitors.find((m) => String(m.id) === affectedScope)?.name ||
          t('incidents.selected_monitor', 'Vybraný monitor');

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
          <p className="text-muted-foreground text-sm">
            {t('incidents.subtitle', 'Oddělený přehled výpadků cílových služeb a stavu měřících agentů/lokací.')}
          </p>
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
            <Plus className="size-4" /> {t('incidents.create', 'Nahlásit nový incident')} (
            {t('common.login_required', 'Vyžaduje přihlášení')})
          </button>
        )}
      </div>

      {!isAuthenticated && (
        <Card className="p-4 bg-amber-500/10 border-amber-500/30 flex items-center justify-between">
          <p className="text-xs text-amber-800 dark:text-amber-300 font-medium">
            {t(
              'incidents.public_notice',
              'Prohlížení incidentů je veřejné. Pro ruční zakládání a úpravu incidentů se přihlaste.'
            )}
          </p>
          <Link to="/setup" className="text-xs font-semibold text-primary hover:underline">
            {t('btn.login', 'Přihlásit se')} →
          </Link>
        </Card>
      )}

      {showNewIncidentModal && isAuthenticated && (
        <Card className="p-6 border-primary/50 bg-secondary/40">
          <h3 className="font-bold text-base mb-3">
            {t('incidents.create_modal_title', 'Nahlásit nový incident / Plánovanou údržbu')}
          </h3>
          <form onSubmit={handleCreateIncident} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">
                  {t('incidents.name_label', 'Název incidentu')}
                </label>
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
                <label className="block text-xs font-medium text-muted-foreground mb-1">
                  {t('incidents.scope_label', 'Zasažená služba / Rozsah')}
                </label>
                <select
                  value={affectedScope}
                  onChange={(e) => setAffectedScope(e.target.value)}
                  className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm cursor-pointer"
                >
                  <option value="all">🌐 {t('incidents.scope_all_option', 'Všechny služby (Globální výpadek)')}</option>
                  {targetMonitors.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.type} - {m.target})
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="block text-xs font-medium text-muted-foreground mb-1">
                {t('incidents.detail_label', 'Detailní popis')}
              </label>
              <textarea
                placeholder={t('incidents.detail_placeholder', 'Popis problému, předpokládaná doba vyřešení...')}
                value={incidentDetail}
                onChange={(e) => setIncidentDetail(e.target.value)}
                rows={3}
                className="w-full rounded-md bg-background border border-border px-3 py-2 text-sm"
              />
            </div>

            {createError && <p className="text-xs font-semibold text-destructive">{createError}</p>}

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
                <h3 className="font-bold text-base">
                  {t('incidents.active_outages', 'Probíhající výpadky cílových služeb')} ({ongoingCount})
                </h3>
              </div>
              <Badge variant={ongoingCount > 0 ? 'down' : 'up'}>
                {ongoingCount > 0
                  ? `${ongoingCount} ${t('incidents.active_badge', 'Aktivní výpadek')}`
                  : t('status.healthy', 'Všechny služby OK')}
              </Badge>
            </div>

            {ongoingCount === 0 ? (
              <div className="p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 flex items-center gap-3">
                <CheckCircle2 className="size-5 text-emerald-400 shrink-0" />
                <p className="text-xs text-emerald-800 dark:text-emerald-300 font-medium">
                  {t(
                    'incidents.all_ok',
                    'Všechny sledované cílové monitory a servery (weby, Minecraft, TeamSpeak, routery) běží v pořádku bez výpadků.'
                  )}
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {dbIncidents.map((inc) => (
                  <div
                    key={inc.id}
                    className="p-4 rounded-lg bg-rose-500/10 border border-rose-500/30 flex items-start justify-between gap-4"
                  >
                    <div className="space-y-1.5">
                      <div className="flex items-center gap-2">
                        <span
                          className={`size-2.5 rounded-full ${inc.status === 'open' ? 'bg-rose-500 animate-pulse' : 'bg-amber-400'}`}
                        />
                        <h4 className="font-bold text-sm text-foreground">{inc.monitor_name}</h4>
                        <Badge variant={inc.severity === 'down' ? 'down' : 'warning'}>{inc.type}</Badge>
                      </div>
                      <p className="text-xs font-mono text-muted-foreground">
                        {t('common.target', 'Cíl')}: {inc.target}
                      </p>
                      <p className="text-xs text-down font-medium">{inc.reason}</p>
                      <div className="flex items-center gap-3 pt-1 text-[11px] font-mono text-muted-foreground flex-wrap">
                        <span>
                          {t('incidents.outage_start', 'Začátek výpadku')}:{' '}
                          <strong className="text-foreground">{inc.started_at}</strong>
                        </span>
                        {inc.resolved_at && (
                          <span>
                            {t('incidents.outage_end', 'Konec')}:{' '}
                            <strong className="text-emerald-400">{inc.resolved_at}</strong>
                          </span>
                        )}
                        <span className="px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-amber-300 font-bold">
                          {t('incidents.duration', 'Doba trvání')}: {inc.duration_text}
                        </span>
                      </div>
                    </div>

                    <div className="shrink-0 flex flex-col items-end gap-1.5">
                      <Link
                        to={`/infrastructure/${inc.monitor_id}`}
                        className="inline-flex items-center gap-1.5 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 transition-colors"
                      >
                        {t('incidents.view_outage', 'Detail výpadku')} <ArrowRight className="size-3" />
                      </Link>
                      {inc.acknowledgedBy ? (
                        <span className="text-[11px] text-muted-foreground">
                          {t('incidents.ack_by', { user: inc.acknowledgedBy }, `Převzal: ${inc.acknowledgedBy}`)}
                        </span>
                      ) : (
                        isAuthenticated &&
                        inc.incidentId != null && (
                          <button
                            type="button"
                            disabled={actionBusy}
                            onClick={() => incidentAction(inc.incidentId, 'ack')}
                            className="inline-flex items-center gap-1 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500 disabled:opacity-50"
                          >
                            {t('incidents.ack_btn', 'Převzít incident')}
                          </button>
                        )
                      )}
                    </div>
                  </div>
                ))}

                {ongoingIncidents.map((inc) => {
                  const expanded = expandedId === inc.id;
                  const open = inc.status !== 'resolved';
                  return (
                    <div
                      key={inc.id}
                      className={`p-4 rounded-lg border ${open ? 'bg-amber-500/10 border-amber-500/30' : 'bg-secondary/30 border-border'}`}
                    >
                      <div className="flex items-start justify-between gap-4">
                        <div className="space-y-1 min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span
                              className={`size-2.5 rounded-full ${inc.status === 'resolved' ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse'}`}
                            />
                            <h4 className="font-bold text-sm text-foreground">{inc.title}</h4>
                            {inc.monitorId == null && (
                              <Badge variant="warning">{t('incidents.manual_badge', 'Ručně nahlášeno')}</Badge>
                            )}
                            <Badge variant={inc.status === 'resolved' ? 'up' : 'warning'}>{inc.status}</Badge>
                            {inc.acknowledgedBy && (
                              <span className="text-[11px] text-muted-foreground">
                                {t('incidents.ack_by', { user: inc.acknowledgedBy }, `Převzal: ${inc.acknowledgedBy}`)}
                              </span>
                            )}
                          </div>
                          {!expanded && inc.updates?.length > 0 && (
                            <p className="text-xs text-muted-foreground truncate">
                              {inc.updates[inc.updates.length - 1].message}
                            </p>
                          )}
                          <div className="flex items-center gap-3 pt-1 text-[11px] font-mono text-warning flex-wrap">
                            <span>
                              {t('incidents.created_label', 'Vytvořeno')}: {inc.createdAt}
                            </span>
                            {inc.resolvedAt && (
                              <span>
                                {t('incidents.resolved_label', 'Vyřešeno')}: {inc.resolvedAt}
                              </span>
                            )}
                            <span className="px-2 py-0.5 rounded bg-slate-900 border border-slate-700 text-amber-300 font-bold">
                              {t('incidents.duration', 'Doba trvání')}: {inc.durationText}
                            </span>
                          </div>
                        </div>
                        <button
                          type="button"
                          onClick={() => {
                            setExpandedId(expanded ? null : inc.id);
                            setNoteText('');
                            setPostmortemText(inc.postmortem ?? '');
                            setActionError(null);
                          }}
                          className="shrink-0 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80"
                        >
                          {expanded ? t('incidents.collapse', 'Sbalit') : t('incidents.detail_btn', 'Timeline & akce')}
                        </button>
                      </div>

                      {expanded && (
                        <div className="mt-3 space-y-3 border-t border-border pt-3">
                          {/* Timeline všech kroků - automatických i ručních. */}
                          <ol className="space-y-1.5">
                            {(inc.updates ?? []).map((u: any, i: number) => (
                              <li key={i} className="flex items-start gap-2 text-xs">
                                <span
                                  className={`mt-1 size-1.5 shrink-0 rounded-full ${u.status === 'resolved' ? 'bg-emerald-500' : 'bg-amber-400'}`}
                                />
                                <span className="text-muted-foreground font-mono shrink-0">{u.at}</span>
                                <span className="text-muted-foreground shrink-0">[{u.status}]</span>
                                <span className="min-w-0">{u.message}</span>
                              </li>
                            ))}
                          </ol>

                          {inc.postmortem && (
                            <div className="rounded-md bg-secondary/40 border border-border p-3">
                              <p className="text-xs font-bold mb-1">{t('incidents.postmortem', 'Postmortem')}</p>
                              <p className="text-xs whitespace-pre-wrap">{inc.postmortem}</p>
                            </div>
                          )}

                          {actionError && <p className="text-xs font-semibold text-down">{actionError}</p>}

                          {isAuthenticated && (
                            <div className="space-y-2">
                              {open && (
                                <div className="flex flex-wrap items-center gap-2">
                                  {!inc.acknowledgedBy && (
                                    <button
                                      type="button"
                                      disabled={actionBusy}
                                      onClick={() => incidentAction(inc.id, 'ack')}
                                      className="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-500 disabled:opacity-50"
                                    >
                                      {t('incidents.ack_btn', 'Převzít incident')}
                                    </button>
                                  )}
                                  <input
                                    value={noteText}
                                    onChange={(e) => setNoteText(e.target.value)}
                                    placeholder={t('incidents.note_placeholder', 'Poznámka do timeline…')}
                                    className="min-w-40 flex-1 rounded-md border border-border bg-background px-2.5 py-1.5 text-xs"
                                  />
                                  <button
                                    type="button"
                                    disabled={actionBusy || !noteText.trim()}
                                    onClick={async () => {
                                      if (await incidentAction(inc.id, 'note', { message: noteText })) setNoteText('');
                                    }}
                                    className="rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 disabled:opacity-50"
                                  >
                                    {t('incidents.note_btn', 'Přidat poznámku')}
                                  </button>
                                  <button
                                    type="button"
                                    disabled={actionBusy}
                                    onClick={() => incidentAction(inc.id, 'resolve', { note: noteText })}
                                    className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
                                  >
                                    {t('incidents.resolve_btn', 'Uzavřít incident')}
                                  </button>
                                </div>
                              )}

                              {/* Postmortem dává smysl hlavně po vyřešení, ale psát ho jde kdykoli. */}
                              <div className="flex flex-col gap-1.5">
                                <textarea
                                  value={postmortemText}
                                  onChange={(e) => setPostmortemText(e.target.value)}
                                  placeholder={t(
                                    'incidents.postmortem_placeholder',
                                    'Postmortem: co se stalo, proč, a co uděláme jinak…'
                                  )}
                                  rows={3}
                                  className="w-full rounded-md border border-border bg-background px-2.5 py-1.5 text-xs"
                                />
                                <button
                                  type="button"
                                  disabled={actionBusy || postmortemText === (inc.postmortem ?? '')}
                                  onClick={() => incidentAction(inc.id, 'postmortem', { postmortem: postmortemText })}
                                  className="self-end rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 disabled:opacity-50"
                                >
                                  {t('incidents.postmortem_save', 'Uložit postmortem')}
                                </button>
                              </div>
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </Card>

          {/* Section 1b: outage history - resolved incidents, kept apart from
              the ongoing ones so the heading and the list can never disagree. */}
          {resolvedIncidents.length > 0 && (
            <Card className="space-y-4 p-6">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <div className="flex items-center gap-2.5">
                  <History className="size-5 text-muted-foreground" />
                  <h3 className="text-base font-bold">
                    {t('incidents.history_title', 'Historie výpadků')} ({resolvedIncidents.length})
                  </h3>
                </div>
                <Badge variant="up">{t('incidents.history_badge', 'Vyřešeno')}</Badge>
              </div>

              <div className="space-y-2">
                {resolvedIncidents.slice(0, historyLimit).map((inc) => (
                  <div key={inc.id} className="rounded-lg border border-border bg-secondary/30 p-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="size-2 rounded-full bg-emerald-500" />
                      <h4 className="text-sm font-semibold">{inc.title}</h4>
                      {inc.monitorId == null && (
                        <Badge variant="warning">{t('incidents.manual_badge', 'Ručně nahlášeno')}</Badge>
                      )}
                    </div>
                    <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-3 font-mono text-[11px]">
                      <span>
                        {t('incidents.created_label', 'Vytvořeno')}: {inc.createdAt}
                      </span>
                      {inc.resolvedAt && (
                        <span>
                          {t('incidents.resolved_label', 'Vyřešeno')}: {inc.resolvedAt}
                        </span>
                      )}
                      <span className="text-foreground font-semibold">
                        {t('incidents.duration', 'Doba trvání')}: {inc.durationText}
                      </span>
                    </div>
                  </div>
                ))}
              </div>

              {resolvedIncidents.length > historyLimit && (
                <button
                  type="button"
                  onClick={() => setHistoryLimit((n) => n + 10)}
                  className="text-muted-foreground hover:text-foreground text-xs font-semibold"
                >
                  {t(
                    'incidents.history_more',
                    { count: resolvedIncidents.length - historyLimit },
                    `Zobrazit dalších ${resolvedIncidents.length - historyLimit}`
                  )}
                </button>
              )}
            </Card>
          )}

          {/* Section 2: Probing nodes and running agents */}
          <Card className="p-6 space-y-4">
            <div className="flex items-center gap-2.5 border-b border-border pb-3">
              <Radio className="size-5 text-primary" />
              <div>
                <h3 className="font-bold text-base">
                  {t('incidents.probing_nodes', 'Stav měřících uzlů a agentů (Probing Infrastructure)')}
                </h3>
                <p className="text-xs text-muted-foreground">
                  {t(
                    'incidents.probing_hint',
                    'Tyto uzly pouze provádějí měření z různých geografických lokací a NEJSOU cílovými službami.'
                  )}
                </p>
              </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {probingNodes.length === 0 ? (
                <div className="col-span-full p-4 text-xs text-muted-foreground text-center">
                  {t('incidents.probes_ok', 'Všechny testovací sondy pracují bez výpadků.')}
                </div>
              ) : (
                probingNodes.map((node) => (
                  <div
                    key={node.id}
                    className="p-3.5 rounded-lg bg-secondary/40 border border-border flex items-center justify-between"
                  >
                    <div>
                      <p className="font-semibold text-xs">{node.name}</p>
                      <p className="text-[11px] text-muted-foreground font-mono">
                        {t('incidents.probe_latency', 'Latence sondy')}: {node.latencyMs ?? 12} ms
                      </p>
                    </div>
                    <Badge variant={node.status === 'up' ? 'up' : 'down'}>
                      {node.status === 'up'
                        ? t('incidents.probe_ok', 'Sonda OK')
                        : t('incidents.probe_offline', 'Sonda OFFLINE')}
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
