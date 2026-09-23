import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Plus, CheckCircle2, AlertTriangle, ArrowRight, Radio, History } from 'lucide-react';
import { appApi } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { EventsHistoryTable } from '@/components/events-history-table';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { pluralForm } from '@/lib/plural';
import { locationFreshness, PROBE_STALE_AFTER_MS, type ProbeRegion } from '@/lib/probe-locations';

/** The window the measurement locations and their success rate cover. */
const REGION_DAYS = 7;

export function IncidentsPage() {
  const { t, lang } = useLanguage();
  const { session } = useSession();
  const isAuthenticated = Boolean(session?.authenticated);
  // Creating, acknowledging and resolving incidents is an admin task. A signed-in
  // user sees the incidents of their own monitors and changes nothing.
  const isAdmin = session?.user?.role === 'admin';
  const [targetMonitors, setTargetMonitors] = useState<any[]>([]);
  const [showNewIncidentModal, setShowNewIncidentModal] = useState(false);
  const [incidentTitle, setIncidentTitle] = useState('');
  const [incidentDetail, setIncidentDetail] = useState('');
  const [affectedScope, setAffectedScope] = useState<string>('all');
  const [manualIncidents, setManualIncidents] = useState<any[] | null>(null);
  const [historyLimit, setHistoryLimit] = useState(5);
  // null = not loaded yet. The lists stay null after a failed first load, so
  // "no outages" can only come from an answer that said so.
  const [dbIncidents, setDbIncidents] = useState<any[] | null>(null);
  const [incidentsError, setIncidentsError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
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

  // Measurement locations for the section at the bottom (W1-A3). null = not
  // loaded yet; a failure is its own state, never an empty "all fine" list.
  const [regions, setRegions] = useState<ProbeRegion[] | null>(null);
  const [regionsCachedAt, setRegionsCachedAt] = useState<string | null>(null);
  const [regionsError, setRegionsError] = useState(false);
  const [regionsAttempt, setRegionsAttempt] = useState(0);
  // "Now" for the "N min ago" labels, taken when the answer arrives: reading
  // the clock during render is impure.
  const [regionsAt, setRegionsAt] = useState(0);

  const loadIncidents = () => {
    fetch('/status/api.php?action=incidents', { credentials: 'include' })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
      .then((data) => {
        // Both lists or nothing: an answer without them (or one naming an
        // error) is a failure, not "no outages".
        if (
          !data ||
          !Array.isArray(data.incidents) ||
          !Array.isArray(data.manualIncidents) ||
          (typeof data.error === 'string' && data.error !== '')
        ) {
          throw new Error(typeof data?.error === 'string' && data.error ? data.error : 'invalid response');
        }
        setDbIncidents(data.incidents);
        setManualIncidents(data.manualIncidents);
        setIncidentsError(null);
      })
      .catch((e: unknown) => {
        setIncidentsError(e instanceof Error ? e.message : String(e));
      });
  };

  useEffect(() => {
    let active = true;

    loadIncidents();

    // The monitors feed only the "affected service" picker of the new-incident form.
    appApi
      .getMonitors()
      .then((rows) => {
        if (!active || !Array.isArray(rows)) return;
        setTargetMonitors(
          rows.filter((m: any) => {
            const type = (m.type || '').toLowerCase();
            return type !== 'node' && type !== 'probe';
          })
        );
      })
      .catch(() => {});

    return () => {
      active = false;
    };
  }, []);

  useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=regions&days=${REGION_DAYS}`, { credentials: 'include' })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
      .then((data) => {
        if (!active) return;
        if (!data || !Array.isArray(data.regions)) {
          setRegionsError(true);
          return;
        }
        setRegions(data.regions);
        setRegionsError(false);
        setRegionsCachedAt(typeof data.cachedAt === 'string' ? data.cachedAt : null);
        setRegionsAt(Date.now());
      })
      .catch(() => {
        if (active) setRegionsError(true);
      });
    return () => {
      active = false;
    };
  }, [regionsAttempt]);

  // A resolved incident does not belong under "Ongoing outages".
  //
  // The card counted only monitors that are down right now in its heading, but
  // listed every incident including closed ones. The result was a header saying
  // "Ongoing outages (0) - all systems healthy" with an outage from last month
  // marked "resolved" underneath it. You could not tell whether something is on
  // fire now or whether you are reading history.
  const incidentsLoaded = dbIncidents !== null && manualIncidents !== null;
  const manualList = manualIncidents ?? [];
  const liveOutages = dbIncidents ?? [];
  const ongoingIncidents = manualList.filter((inc) => inc.status !== 'resolved');
  const resolvedIncidents = manualList.filter((inc) => inc.status === 'resolved');

  // A live outage and the incident the lifecycle opened for it are one story.
  // Listed twice they looked like two outages, the count said 3 for one router
  // (the down monitor, its outage row and its incident), and the notes and
  // actions sat on the second card while the first had only "acknowledge".
  const incidentById = new Map<number, any>(manualList.map((inc) => [inc.id, inc]));
  const linkedIncidentIds = new Set(liveOutages.map((inc) => inc.incidentId).filter((id) => id != null));
  // Monitors that are down at this moment. A resolved incident of one of them is
  // history for the record only - the outage is still running above.
  const downMonitorIds = new Set(liveOutages.map((inc) => inc.monitor_id));
  const standaloneIncidents = ongoingIncidents.filter((inc) => !linkedIncidentIds.has(inc.id));

  // The count in the heading must match what is listed below it.
  const ongoingCount = liveOutages.length + standaloneIncidents.length;
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

      {!incidentsLoaded ? (
        incidentsError ? (
          <ErrorState
            message={t('incidents.load_failed', 'Incidenty se nepodařilo načíst. Stav výpadků teď není známý.')}
            onRetry={loadIncidents}
          />
        ) : (
          <LoadingState label={t('incidents.loading', 'Načítám stav incidentů...')} />
        )
      ) : (
        <div className="space-y-6">
          {/* A failed refresh keeps the last answer on screen, but says so and
              withholds the all-clear below: it may be out of date. */}
          {incidentsError && (
            <ErrorState
              tone="warning"
              message={t('incidents.refresh_failed', 'Incidenty se nepodařilo obnovit. Níže je poslední načtený stav.')}
              onRetry={loadIncidents}
            />
          )}
          {/* Section 1: Active target service outages */}
          <Card className="p-6 space-y-4 border-down/40">
            <div className="flex items-center justify-between border-b border-border pb-3">
              <div className="flex items-center gap-2.5">
                <AlertTriangle className="size-5 text-down" />
                <h3 className="font-bold text-base">
                  {t('incidents.active_outages', 'Probíhající výpadky cílových služeb')} ({ongoingCount})
                </h3>
              </div>
              {ongoingCount > 0 ? (
                <Badge variant="down">{activeBadge}</Badge>
              ) : (
                !incidentsError && <Badge variant="up">{t('status.healthy', 'Všechny služby OK')}</Badge>
              )}
            </div>

            {ongoingCount === 0 ? (
              // The all-clear comes only from a fresh, successful answer.
              !incidentsError && (
                <div className="p-4 rounded-lg bg-up/10 border border-up/30 flex items-center gap-3">
                  <CheckCircle2 className="size-5 text-up shrink-0" />
                  <p className="text-xs text-up font-medium">
                    {t(
                      'incidents.all_ok',
                      'Všechny sledované cílové monitory a servery (weby, Minecraft, TeamSpeak, routery) běží v pořádku bez výpadků.'
                    )}
                  </p>
                </div>
              )
            ) : (
              <div className="space-y-3">
                {liveOutages.map((inc) => {
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
        </div>
      )}

      {/* Where the checks come FROM (W1-A3). One row per place from
          monitor_logs.checked_from, not the monitors of type node, and no
          latency where nothing was measured. */}
      <Card className="p-6 space-y-4">
        <div className="flex items-center gap-2.5 border-b border-border pb-3">
          <Radio className="size-5 text-primary" />
          <div>
            <h3 className="font-bold text-base">{t('incidents.locations_title', 'Místa měření')}</h3>
            <p className="text-xs text-muted-foreground">
              {t(
                'incidents.locations_hint',
                { days: REGION_DAYS, min: PROBE_STALE_AFTER_MS / 60_000 },
                `Odkud kontroly běží, za posledních ${REGION_DAYS} dní. Místo bez výsledku déle než ${PROBE_STALE_AFTER_MS / 60_000} min (dva intervaly kontrol) je označené jako odmlčené.`
              )}
            </p>
          </div>
        </div>

        {regions === null ? (
          regionsError ? (
            <ErrorState
              message={t('incidents.locations_failed', 'Místa měření se nepodařilo načíst.')}
              onRetry={() => {
                setRegionsError(false);
                setRegionsAttempt((n) => n + 1);
              }}
            />
          ) : (
            <LoadingState size="inline" label={t('incidents.locations_loading', 'Načítám místa měření…')} />
          )
        ) : regions.length === 0 ? (
          <p className="p-4 text-center text-xs text-muted-foreground">
            {t(
              'incidents.locations_empty',
              { days: REGION_DAYS },
              `Za posledních ${REGION_DAYS} dní nepřišel výsledek z žádného místa měření.`
            )}
          </p>
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {regions.map((r) => {
              const fresh = locationFreshness(r, regionsCachedAt, regionsAt);
              return (
                <li
                  key={r.location ?? '—'}
                  className="space-y-1.5 rounded-lg border border-border bg-secondary/40 p-3.5 text-xs"
                >
                  <div className="flex items-start justify-between gap-2">
                    <p className="min-w-0 truncate font-semibold" title={r.location ?? undefined}>
                      {r.location ?? t('incidents.location_unnamed', 'Místo neuvedeno')}
                    </p>
                    {fresh.stale !== null && (
                      <Badge variant={fresh.stale ? 'warning' : 'up'}>
                        {fresh.stale
                          ? t('incidents.location_stale', 'Odmlčelo se')
                          : t('incidents.location_active', 'Měří')}
                      </Badge>
                    )}
                  </div>
                  <p className="text-muted-foreground">
                    {t('incidents.location_last', 'Poslední výsledek')}:{' '}
                    <span className="text-foreground tabular-nums">
                      {fresh.ageMin === null ? '—' : agoLabel(fresh.ageMin, t)}
                    </span>
                  </p>
                  <p className="text-muted-foreground">
                    {t('incidents.location_success', 'Úspěšnost')}:{' '}
                    <span className="text-foreground tabular-nums">
                      {r.successRate == null ? '—' : `${r.successRate} %`}
                    </span>
                    {' · '}
                    {t('incidents.location_avg', 'Průměrná odezva')}:{' '}
                    <span className="text-foreground tabular-nums">
                      {r.avgResponseMs == null ? '—' : `${r.avgResponseMs} ms`}
                    </span>
                  </p>
                </li>
              );
            })}
          </ul>
        )}
      </Card>
      <EventsHistoryTable />
    </div>
  );
}

/** "3 min" up to two hours, then hours, then days: minute precision stops meaning anything. */
function agoLabel(
  min: number,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
) {
  if (min < 120) return t('incidents.ago_min', { n: min }, `před ${min} min`);
  const h = Math.round(min / 60);
  if (h < 48) return t('incidents.ago_h', { n: h }, `před ${h} h`);
  const d = Math.round(h / 24);
  return t('incidents.ago_d', { n: d }, `před ${d} d`);
}
