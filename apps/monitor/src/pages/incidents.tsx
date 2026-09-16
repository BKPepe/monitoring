import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Plus, CheckCircle2, AlertTriangle, ArrowRight, Radio, History } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { usePublicStatus } from '@/api/use-asset-charts';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { EventsHistoryTable } from '@/components/events-history-table';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { pluralForm } from '@/lib/plural';

export function IncidentsPage() {
  const { t, lang } = useLanguage();
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);
  // Creating, acknowledging and resolving incidents is an admin task. A signed-in
  // user sees the incidents of their own monitors and changes nothing.
  const isAdmin = session?.user?.role === 'admin';
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
  // The expanded incident (timeline + actions) and draft note texts.
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

  // A resolved incident does not belong under "Ongoing outages".
  //
  // The card counted only monitors that are down right now in its heading, but
  // listed every incident including closed ones. The result was a header saying
  // "Ongoing outages (0) - all systems healthy" with an outage from last month
  // marked "resolved" underneath it. You could not tell whether something is on
  // fire now or whether you are reading history.
  const ongoingIncidents = manualIncidents.filter((inc) => inc.status !== 'resolved');
  const resolvedIncidents = manualIncidents.filter((inc) => inc.status === 'resolved');

  // A live outage and the incident the lifecycle opened for it are one story.
  // Listed twice they looked like two outages, the count said 3 for one router
  // (the down monitor, its outage row and its incident), and the notes and
  // actions sat on the second card while the first had only "acknowledge".
  const incidentById = new Map<number, any>(manualIncidents.map((inc) => [inc.id, inc]));
  const linkedIncidentIds = new Set(dbIncidents.map((inc) => inc.incidentId).filter((id) => id != null));
  // Monitors that are down at this moment. A resolved incident of one of them is
  // history for the record only - the outage is still running above.
  const downMonitorIds = new Set(dbIncidents.map((inc) => inc.monitor_id));
  const standaloneIncidents = ongoingIncidents.filter((inc) => !linkedIncidentIds.has(inc.id));

  // The count in the heading must match what is listed below it.
  const ongoingCount = dbIncidents.length + standaloneIncidents.length;
  const activeBadge = {
    one: t('incidents.active_badge_one', { count: ongoingCount }, `${ongoingCount} aktivní výpadek`),
    few: t('incidents.active_badge_few', { count: ongoingCount }, `${ongoingCount} aktivní výpadky`),
    other: t('incidents.active_badge_other', { count: ongoingCount }, `${ongoingCount} aktivních výpadků`),
  }[pluralForm(lang, ongoingCount)];

  // Resolving an incident closes the RECORD, not the outage. The monitor stays
  // down, its card stays on this page - and because every action hangs off the
  // open incident, the card was left with nothing: no notes, no acknowledge, and
  // an outage the escalation no longer knew about. This opens a record again.
  const openIncidentFor = async (inc: any) => {
    setActionBusy(true);
    setActionError(null);
    try {
      const res = await fetch('/status/api.php?action=create_incident', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          title: t('incidents.reopen_title', { name: inc.monitor_name }, `Výpadek: ${inc.monitor_name}`),
          impact: 'major',
          monitorId: inc.monitor_id,
          message: t('incidents.reopen_message', 'Incident znovu otevřen, monitor je stále nedostupný.'),
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      loadIncidents();
    } catch (e) {
      setActionError(e instanceof Error ? e.message : t('incidents.action_failed', 'Akce se nezdařila.'));
    } finally {
      setActionBusy(false);
    }
  };

  const toggleIncident = (inc: any) => {
    setExpandedId(expandedId === inc.id ? null : inc.id);
    setNoteText('');
    setPostmortemText(inc.postmortem ?? '');
    setActionError(null);
  };

  const renderIncidentDetail = (inc: any) => {
    const open = inc.status !== 'resolved';
    return (
      <div className="mt-3 space-y-3 border-t border-border pt-3">
        {/* Timeline of all steps - automatic and manual alike. */}
        <ol className="space-y-1.5">
          {(inc.updates ?? []).map((u: any, i: number) => (
            <li key={i} className="flex items-start gap-2 text-xs">
              <span
                className={`mt-1 size-1.5 shrink-0 rounded-full ${u.status === 'resolved' ? 'bg-up' : 'bg-warning'}`}
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

        {actionError && <ErrorState size="inline" message={actionError} />}

        {isAdmin && (
          <div className="space-y-2">
            {open && (
              <div className="flex flex-wrap items-center gap-2">
                {!inc.acknowledgedBy && (
                  <button
                    type="button"
                    disabled={actionBusy}
                    onClick={() => incidentAction(inc.id, 'ack')}
                    className="rounded-md bg-warning text-warning-foreground px-3 py-1.5 text-xs font-semibold hover:bg-warning/90 disabled:opacity-50"
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
                  className="rounded-md bg-up text-up-foreground px-3 py-1.5 text-xs font-semibold hover:bg-up/90 disabled:opacity-50"
                >
                  {t('incidents.resolve_btn', 'Uzavřít incident')}
                </button>
              </div>
            )}

            {/* A postmortem makes sense mostly after resolution, but can be written anytime. */}
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
    );
  };

  const handleCreateIncident = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!isAdmin || !incidentTitle) return;

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
      <PageHeader
        title={t('incidents.title', 'Správa Incidentů a Výpadků')}
        subtitle={t('incidents.subtitle', 'Oddělený přehled výpadků cílových služeb a stavu měřících agentů/lokací.')}
        actions={
          isAdmin ? (
            <button
              type="button"
              onClick={() => setShowNewIncidentModal(true)}
              className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow hover:bg-primary/90 transition-colors cursor-pointer"
            >
              <Plus className="size-4" /> {t('incidents.create', 'Nahlásit nový incident')}
            </button>
          ) : null
        }
      />

      {!isAuthenticated && (
        <Card className="p-4 bg-warning/10 border-warning/30 flex items-center justify-between">
          <p className="text-xs text-warning font-medium">
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

      {showNewIncidentModal && isAdmin && (
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

            {createError && <ErrorState size="inline" message={createError} />}

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
        <LoadingState label={t('incidents.loading', 'Načítám stav incidentů...')} />
      ) : (
        <div className="space-y-6">
          {/* Section 1: Active target service outages */}
          <Card className="p-6 space-y-4 border-down/40">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <div className="flex items-center gap-2.5">
                <AlertTriangle className="size-5 text-down" />
                <h3 className="font-bold text-base">
                  {t('incidents.active_outages', 'Probíhající výpadky cílových služeb')} ({ongoingCount})
                </h3>
              </div>
              <Badge variant={ongoingCount > 0 ? 'down' : 'up'}>
                {ongoingCount > 0 ? activeBadge : t('status.healthy', 'Všechny služby OK')}
              </Badge>
            </div>

            {ongoingCount === 0 ? (
              <div className="p-4 rounded-lg bg-up/10 border border-up/30 flex items-center gap-3">
                <CheckCircle2 className="size-5 text-up shrink-0" />
                <p className="text-xs text-up font-medium">
                  {t(
                    'incidents.all_ok',
                    'Všechny sledované cílové monitory a servery (weby, Minecraft, TeamSpeak, routery) běží v pořádku bez výpadků.'
                  )}
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {dbIncidents.map((inc) => {
                  const linked = inc.incidentId != null ? incidentById.get(inc.incidentId) : undefined;
                  const expanded = linked != null && expandedId === linked.id;
                  return (
                    <div key={inc.id} className="p-4 rounded-lg bg-down/10 border border-down/30">
                      <div className="flex items-start justify-between gap-4">
                        <div className="min-w-0 space-y-1.5">
                          <div className="flex items-center gap-2">
                            <span
                              className={`size-2.5 rounded-full ${inc.status === 'open' ? 'bg-down animate-pulse' : 'bg-warning'}`}
                            />
                            <h4 className="font-bold text-sm text-foreground">{inc.monitor_name}</h4>
                            <Badge variant={inc.severity === 'down' ? 'down' : 'warning'}>{inc.type}</Badge>
                          </div>
                          <p className="text-xs font-mono text-muted-foreground">
                            {t('common.target', 'Cíl')}: {inc.target}
                          </p>
                          <p className="text-xs text-down font-medium">{inc.reason}</p>
                          <div className="flex items-center gap-3 pt-1 text-2xs font-mono text-muted-foreground flex-wrap">
                            <span>
                              {t('incidents.outage_start', 'Začátek výpadku')}:{' '}
                              <strong className="text-foreground">{inc.started_at}</strong>
                            </span>
                            {inc.resolved_at && (
                              <span>
                                {t('incidents.outage_end', 'Konec')}:{' '}
                                <strong className="text-up">{inc.resolved_at}</strong>
                              </span>
                            )}
                            <span className="px-2 py-0.5 rounded bg-muted border border-border text-warning font-bold">
                              {t('incidents.duration', 'Doba trvání')}: {inc.duration_text}
                            </span>
                          </div>
                          {/* The newest note of the incident this outage opened - the
                              card says what is being done, not only that it is down. */}
                          {linked && !expanded && linked.updates?.length > 0 && (
                            <p className="truncate text-xs text-muted-foreground">
                              {linked.updates[linked.updates.length - 1].message}
                            </p>
                          )}
                          {!linked && (
                            <p className="text-warning text-2xs font-semibold">
                              {t(
                                'incidents.no_open_incident',
                                'Incident je uzavřený, ale monitor je stále nedostupný - výpadek trvá.'
                              )}
                            </p>
                          )}
                        </div>
                        <div className="shrink-0 flex flex-col items-end gap-1.5">
                          <Link
                            to={`/infrastructure/${inc.monitor_id}`}
                            className="inline-flex items-center gap-1.5 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80 transition-colors"
                          >
                            {t('incidents.view_outage', 'Detail výpadku')} <ArrowRight className="size-3" />
                          </Link>
                          {/* Escalation used to happen silently: cron stamps it and
                              nothing showed that the outage had already gone past
                              whoever was meant to pick it up. */}
                          {inc.escalatedAt && (
                            <span className="text-down text-2xs font-semibold">
                              {t(
                                'incidents.escalated_at',
                                { when: new Date(inc.escalatedAt).toLocaleString(lang === 'cs' ? 'cs-CZ' : 'en-GB') },
                                `Eskalováno ${new Date(inc.escalatedAt).toLocaleString('cs-CZ')}`
                              )}
                            </span>
                          )}
                          {inc.acknowledgedBy ? (
                            <span className="text-2xs text-muted-foreground">
                              {t('incidents.ack_by', { user: inc.acknowledgedBy }, `Převzal: ${inc.acknowledgedBy}`)}
                            </span>
                          ) : (
                            isAdmin &&
                            inc.incidentId != null && (
                              <button
                                type="button"
                                disabled={actionBusy}
                                onClick={() => incidentAction(inc.incidentId, 'ack')}
                                className="inline-flex items-center gap-1 rounded-md bg-warning text-warning-foreground px-3 py-1.5 text-xs font-semibold hover:bg-warning/90 disabled:opacity-50"
                              >
                                {t('incidents.ack_btn', 'Převzít incident')}
                              </button>
                            )
                          )}
                          {linked && (
                            <button
                              type="button"
                              onClick={() => toggleIncident(linked)}
                              aria-expanded={expanded}
                              className="rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80"
                            >
                              {expanded
                                ? t('incidents.collapse', 'Sbalit')
                                : t('incidents.detail_btn', 'Poznámky a akce')}
                            </button>
                          )}
                          {!linked && isAdmin && (
                            <button
                              type="button"
                              disabled={actionBusy}
                              onClick={() => void openIncidentFor(inc)}
                              className="rounded-md bg-warning text-warning-foreground px-3 py-1.5 text-xs font-semibold hover:bg-warning/90 disabled:opacity-50"
                            >
                              {t('incidents.reopen_btn', 'Otevřít incident')}
                            </button>
                          )}
                        </div>
                      </div>
                      {!linked && actionError && <ErrorState size="inline" message={actionError} />}
                      {expanded && linked && renderIncidentDetail(linked)}
                    </div>
                  );
                })}

                {standaloneIncidents.map((inc) => {
                  const expanded = expandedId === inc.id;
                  const open = inc.status !== 'resolved';
                  return (
                    <div
                      key={inc.id}
                      className={`p-4 rounded-lg border ${open ? 'bg-warning/10 border-warning/30' : 'bg-secondary/30 border-border'}`}
                    >
                      <div className="flex items-start justify-between gap-4">
                        <div className="space-y-1 min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span
                              className={`size-2.5 rounded-full ${inc.status === 'resolved' ? 'bg-up' : 'bg-warning animate-pulse'}`}
                            />
                            <h4 className="font-bold text-sm text-foreground">{inc.title}</h4>
                            {inc.monitorId == null && (
                              <Badge variant="warning">{t('incidents.manual_badge', 'Ručně nahlášeno')}</Badge>
                            )}
                            <Badge variant={inc.status === 'resolved' ? 'up' : 'warning'}>{inc.status}</Badge>
                            {inc.acknowledgedBy && (
                              <span className="text-2xs text-muted-foreground">
                                {t('incidents.ack_by', { user: inc.acknowledgedBy }, `Převzal: ${inc.acknowledgedBy}`)}
                              </span>
                            )}
                          </div>
                          {!expanded && inc.updates?.length > 0 && (
                            <p className="text-xs text-muted-foreground truncate">
                              {inc.updates[inc.updates.length - 1].message}
                            </p>
                          )}
                          <div className="flex items-center gap-3 pt-1 text-2xs font-mono text-warning flex-wrap">
                            <span>
                              {t('incidents.created_label', 'Vytvořeno')}: {inc.createdAt}
                            </span>
                            {inc.resolvedAt && (
                              <span>
                                {t('incidents.resolved_label', 'Vyřešeno')}: {inc.resolvedAt}
                              </span>
                            )}
                            <span className="px-2 py-0.5 rounded bg-muted border border-border text-warning font-bold">
                              {t('incidents.duration', 'Doba trvání')}: {inc.durationText}
                            </span>
                          </div>
                        </div>
                        <button
                          type="button"
                          onClick={() => toggleIncident(inc)}
                          aria-expanded={expanded}
                          className="shrink-0 rounded-md bg-secondary px-3 py-1.5 text-xs font-semibold hover:bg-secondary/80"
                        >
                          {expanded ? t('incidents.collapse', 'Sbalit') : t('incidents.detail_btn', 'Timeline & akce')}
                        </button>
                      </div>

                      {expanded && renderIncidentDetail(inc)}
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
                      <span className="size-2 rounded-full bg-up" />
                      <h4 className="text-sm font-semibold">{inc.title}</h4>
                      {inc.monitorId == null && (
                        <Badge variant="warning">{t('incidents.manual_badge', 'Ručně nahlášeno')}</Badge>
                      )}
                      {inc.monitorId != null && downMonitorIds.has(inc.monitorId) && (
                        <Badge variant="down">{t('incidents.still_down_badge', 'Monitor je stále nedostupný')}</Badge>
                      )}
                    </div>
                    <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-3 font-mono text-2xs">
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
                      <p className="text-2xs text-muted-foreground font-mono">
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
