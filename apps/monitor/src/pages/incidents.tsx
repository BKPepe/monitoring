import * as React from 'react';
import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import {
  Plus,
  CheckCircle2,
  ArrowRight,
  Radio,
  History,
  ListChecks,
  CircleX,
  TriangleAlert,
  Megaphone,
  Siren,
  Hand,
  type LucideIcon,
} from 'lucide-react';
import { PageHeader } from '@/components/layout/page-header';
import { usePageChrome } from '@/components/layout/shell-context';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Input } from '@/components/ui/input';
import { KeyValueList } from '@/components/ui/key-value';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { appApi } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { LoadingState, ErrorState, EmptyState } from '@/components/ui/states';
import { pluralForm } from '@/lib/plural';
import { locationFreshness, PROBE_STALE_AFTER_MS, type ProbeRegion } from '@/lib/probe-locations';
import { cn } from '@/lib/utils';

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

  // The header's refresh reloads both lists in place.
  usePageChrome({
    onRefresh: () => {
      loadIncidents();
      setRegionsAttempt((n) => n + 1);
    },
  });

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
    one: t('incidents.active_badge_one', { count: ongoingCount }, `${ongoingCount} probíhající`),
    few: t('incidents.active_badge_few', { count: ongoingCount }, `${ongoingCount} probíhající`),
    other: t('incidents.active_badge_other', { count: ongoingCount }, `${ongoingCount} probíhajících`),
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
        <ol className="space-y-2">
          {(inc.updates ?? []).map((u: any, i: number) => (
            <li key={i} className="flex items-start gap-2.5 text-xs">
              <span
                aria-hidden="true"
                className={cn(
                  'mt-1.5 size-1.5 shrink-0 rounded-full',
                  u.status === 'resolved' ? 'bg-up' : 'bg-warning'
                )}
              />
              <span className="text-muted-foreground figure shrink-0 text-2xs">{u.at}</span>
              <Pill size="sm" tone={u.status === 'resolved' ? 'up' : 'warning'}>
                {u.status}
              </Pill>
              <span className="min-w-0">{u.message}</span>
            </li>
          ))}
        </ol>

        {inc.postmortem && (
          <div className="bg-inset rounded-lg border border-border p-3">
            <p className="micro-label mb-1">{t('incidents.postmortem', 'Postmortem')}</p>
            <p className="text-xs whitespace-pre-wrap">{inc.postmortem}</p>
          </div>
        )}

        {actionError && <ErrorState size="inline" message={actionError} />}

        {isAdmin && (
          <div className="space-y-2">
            {open && (
              <div className="flex flex-wrap items-center gap-2">
                {!inc.acknowledgedBy && (
                  <Button
                    size="sm"
                    disabled={actionBusy}
                    onClick={() => incidentAction(inc.id, 'ack')}
                    className="bg-warning text-warning-foreground hover:bg-warning/90"
                  >
                    {t('incidents.ack_btn', 'Převzít incident')}
                  </Button>
                )}
                <Input
                  value={noteText}
                  onChange={(e) => setNoteText(e.target.value)}
                  placeholder={t('incidents.note_placeholder', 'Poznámka do timeline…')}
                  aria-label={t('incidents.note_placeholder', 'Poznámka do timeline…')}
                  className="h-8 min-w-40 flex-1 text-xs"
                />
                <Button
                  size="sm"
                  disabled={actionBusy || !noteText.trim()}
                  onClick={async () => {
                    if (await incidentAction(inc.id, 'note', { message: noteText })) setNoteText('');
                  }}
                >
                  {t('incidents.note_btn', 'Přidat poznámku')}
                </Button>
                <Button
                  size="sm"
                  disabled={actionBusy}
                  onClick={() => incidentAction(inc.id, 'resolve', { note: noteText })}
                  className="bg-up text-up-foreground hover:bg-up/90"
                >
                  {t('incidents.resolve_btn', 'Uzavřít incident')}
                </Button>
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
                aria-label={t('incidents.postmortem', 'Postmortem')}
                rows={3}
                className="bg-secondary/60 border-input hover:border-border-strong focus-visible:border-ring w-full rounded-md border px-3 py-2 text-xs"
              />
              <Button
                size="sm"
                variant="outline"
                disabled={actionBusy || postmortemText === (inc.postmortem ?? '')}
                onClick={() => incidentAction(inc.id, 'postmortem', { postmortem: postmortemText })}
                className="self-end"
              >
                {t('incidents.postmortem_save', 'Uložit postmortem')}
              </Button>
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

  // Open items nobody took yet: what an on-call person looks for first.
  const unacknowledged =
    liveOutages.filter((inc) => !inc.acknowledgedBy).length +
    standaloneIncidents.filter((inc) => !inc.acknowledgedBy).length;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('incidents.title', 'Incidenty')}
        subtitle={t('incidents.subtitle', 'Co je rozbité teď, co bylo a odkud se měří.')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {/* The check results live in one place now (W2-6); this page keeps
                its own job - what is broken, what was, and a new incident. */}
            <Button variant="outline" asChild className="gap-2">
              <Link to="/incidents/checks">
                <ListChecks aria-hidden="true" /> {t('incidents.check_log', 'Protokol kontrol')}
              </Link>
            </Button>
            {isAdmin && (
              <Button variant="primary" onClick={() => setShowNewIncidentModal(true)} className="gap-2">
                <Plus aria-hidden="true" /> {t('incidents.create', 'Nahlásit nový incident')}
              </Button>
            )}
          </div>
        }
      />

      {!isAuthenticated && (
        <div className="bg-warning/10 border-warning/30 flex flex-wrap items-center justify-between gap-2 rounded-lg border px-4 py-3">
          <p className="text-warning text-xs font-medium">
            {t(
              'incidents.public_notice',
              'Prohlížení incidentů je veřejné. Pro ruční zakládání a úpravu incidentů se přihlaste.'
            )}
          </p>
          <Link to="/setup" className="text-link text-xs font-semibold hover:underline">
            {t('btn.login', 'Přihlásit se')} →
          </Link>
        </div>
      )}

      {showNewIncidentModal && isAdmin && (
        <Panel icon={Megaphone} title={t('incidents.create_modal_title', 'Nahlásit nový incident / Plánovanou údržbu')}>
          <form onSubmit={handleCreateIncident} className="space-y-4">
            <div className="grid gap-4 md:grid-cols-2">
              <label className="block space-y-1">
                <span className="micro-label">{t('incidents.name_label', 'Název incidentu')}</span>
                <Input
                  type="text"
                  placeholder={t('incidents.name_placeholder', 'např. Neplánovaná údržba databáze')}
                  value={incidentTitle}
                  onChange={(e) => setIncidentTitle(e.target.value)}
                  required
                />
              </label>

              <label className="block space-y-1">
                <span className="micro-label">{t('incidents.scope_label', 'Zasažená služba / Rozsah')}</span>
                <select
                  value={affectedScope}
                  onChange={(e) => setAffectedScope(e.target.value)}
                  className="bg-secondary/60 border-input hover:border-border-strong focus-visible:border-ring h-9 w-full cursor-pointer rounded-md border px-3 text-sm"
                >
                  <option value="all">{t('incidents.scope_all_option', 'Všechny služby (Globální výpadek)')}</option>
                  {targetMonitors.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name} ({m.type} - {m.target})
                    </option>
                  ))}
                </select>
              </label>
            </div>

            <label className="block space-y-1">
              <span className="micro-label">{t('incidents.detail_label', 'Detailní popis')}</span>
              <textarea
                placeholder={t('incidents.detail_placeholder', 'Popis problému, předpokládaná doba vyřešení...')}
                value={incidentDetail}
                onChange={(e) => setIncidentDetail(e.target.value)}
                rows={3}
                className="bg-secondary/60 border-input hover:border-border-strong focus-visible:border-ring w-full rounded-md border px-3 py-2 text-sm"
              />
            </label>

            {createError && <ErrorState size="inline" message={createError} />}

            <div className="flex items-center justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setShowNewIncidentModal(false)}>
                {t('common.cancel', 'Zrušit')}
              </Button>
              <Button type="submit" variant="primary" disabled={creating}>
                {creating ? t('common.saving', 'Ukládám…') : t('incidents.save_btn', 'Uložit incident')}
              </Button>
            </div>
          </form>
        </Panel>
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

          {/* The three numbers of the page, counted from the lists below them. */}
          <div className="grid grid-cols-3 gap-2 sm:gap-3">
            <CountTile
              icon={Siren}
              tone={ongoingCount > 0 ? 'down' : 'neutral'}
              count={ongoingCount}
              label={t('incidents.tile_ongoing', 'Probíhá')}
              hint={t('incidents.tile_ongoing_hint', 'výpadky a otevřené incidenty')}
            />
            <CountTile
              icon={Hand}
              tone={unacknowledged > 0 ? 'warning' : 'neutral'}
              count={unacknowledged}
              label={t('incidents.tile_unacked', 'Nepřevzato')}
              hint={t('incidents.tile_unacked_hint', 'nikdo se jich zatím neujal')}
            />
            <CountTile
              icon={History}
              tone="neutral"
              count={resolvedIncidents.length}
              label={t('incidents.tile_resolved', 'Vyřešeno')}
              hint={t('incidents.tile_resolved_hint', 'uzavřené v historii')}
            />
          </div>

          {/* Section 1: what is broken right now - outages and open incidents, one feed. */}
          <section aria-labelledby="incidents-ongoing" className="space-y-3">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <h2 id="incidents-ongoing" className="text-base font-semibold tracking-tight">
                {t('incidents.active_outages', 'Probíhající výpadky a incidenty')}{' '}
                <span className="text-muted-foreground figure text-xs font-normal">({ongoingCount})</span>
              </h2>
              {ongoingCount > 0 ? (
                <Pill tone="down" dot>
                  {activeBadge}
                </Pill>
              ) : (
                !incidentsError && (
                  <Pill tone="up" dot>
                    {t('status.healthy', 'Všechny služby OK')}
                  </Pill>
                )
              )}
            </div>

            {ongoingCount === 0 ? (
              // The all-clear comes only from a fresh, successful answer.
              !incidentsError && (
                <div className="bg-card flex items-center gap-3 rounded-lg border-l-2 border-l-up px-3 py-3">
                  <IconTile icon={CheckCircle2} tone="up" />
                  <p className="text-sm">
                    {t(
                      'incidents.all_ok',
                      'Všechny sledované cílové monitory a servery (weby, Minecraft, TeamSpeak, routery) běží v pořádku bez výpadků.'
                    )}
                  </p>
                </div>
              )
            ) : (
              <ul>
                {liveOutages.map((inc, i) => {
                  const linked = inc.incidentId != null ? incidentById.get(inc.incidentId) : undefined;
                  const expanded = linked != null && expandedId === linked.id;
                  const last = i === liveOutages.length - 1 && standaloneIncidents.length === 0;
                  return (
                    <FeedRow
                      key={`o${inc.id}`}
                      icon={inc.severity === 'down' ? CircleX : TriangleAlert}
                      tone={inc.severity === 'down' ? 'down' : 'warning'}
                      last={last}
                      highlight
                    >
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1 space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-sm font-semibold">{inc.monitor_name}</h3>
                            <Pill size="sm" tone={inc.severity === 'down' ? 'down' : 'warning'}>
                              {inc.type}
                            </Pill>
                          </div>
                          <p className="text-muted-foreground figure text-2xs">
                            {t('common.target', 'Cíl')}: {inc.target}
                          </p>
                          <p className="text-down text-xs font-medium">{inc.reason}</p>
                          <p className="text-muted-foreground figure flex flex-wrap gap-x-3 gap-y-0.5 text-2xs">
                            <span>
                              {t('incidents.outage_start', 'Začátek výpadku')}:{' '}
                              <span className="text-foreground">{inc.started_at}</span>
                            </span>
                            {inc.resolved_at && (
                              <span>
                                {t('incidents.outage_end', 'Konec')}:{' '}
                                <span className="text-foreground">{inc.resolved_at}</span>
                              </span>
                            )}
                            <span>
                              {t('incidents.duration', 'Doba trvání')}:{' '}
                              <span className="text-warning font-semibold">{inc.duration_text}</span>
                            </span>
                          </p>
                          {/* The newest note of the incident this outage opened - the
                              card says what is being done, not only that it is down. */}
                          {linked && !expanded && linked.updates?.length > 0 && (
                            <p className="text-muted-foreground truncate text-xs">
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
                        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:shrink-0 sm:flex-col sm:items-end">
                          <Button size="sm" variant="outline" asChild className="gap-1.5">
                            <Link to={`/infrastructure/${inc.monitor_id}`}>
                              {t('incidents.view_outage', 'Detail výpadku')} <ArrowRight aria-hidden="true" />
                            </Link>
                          </Button>
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
                            <span className="text-muted-foreground text-2xs">
                              {t('incidents.ack_by', { user: inc.acknowledgedBy }, `Převzal: ${inc.acknowledgedBy}`)}
                            </span>
                          ) : (
                            isAdmin &&
                            inc.incidentId != null && (
                              <Button
                                size="sm"
                                disabled={actionBusy}
                                onClick={() => incidentAction(inc.incidentId, 'ack')}
                                className="bg-warning text-warning-foreground hover:bg-warning/90"
                              >
                                {t('incidents.ack_btn', 'Převzít incident')}
                              </Button>
                            )
                          )}
                          {linked && (
                            <Button size="sm" onClick={() => toggleIncident(linked)} aria-expanded={expanded}>
                              {expanded
                                ? t('incidents.collapse', 'Sbalit')
                                : t('incidents.detail_btn', 'Poznámky a akce')}
                            </Button>
                          )}
                          {!linked && isAdmin && (
                            <Button
                              size="sm"
                              disabled={actionBusy}
                              onClick={() => void openIncidentFor(inc)}
                              className="bg-warning text-warning-foreground hover:bg-warning/90"
                            >
                              {t('incidents.reopen_btn', 'Otevřít incident')}
                            </Button>
                          )}
                        </div>
                      </div>
                      {!linked && actionError && <ErrorState size="inline" message={actionError} />}
                      {expanded && linked && renderIncidentDetail(linked)}
                    </FeedRow>
                  );
                })}

                {standaloneIncidents.map((inc, i) => {
                  const expanded = expandedId === inc.id;
                  return (
                    <FeedRow
                      key={`i${inc.id}`}
                      icon={Megaphone}
                      tone="warning"
                      last={i === standaloneIncidents.length - 1}
                    >
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1 space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-sm font-semibold">{inc.title}</h3>
                            {inc.monitorId == null && (
                              <Pill size="sm" tone="warning">
                                {t('incidents.manual_badge', 'Ručně nahlášeno')}
                              </Pill>
                            )}
                            <Pill size="sm" tone="warning">
                              {inc.status}
                            </Pill>
                            {inc.acknowledgedBy && (
                              <span className="text-muted-foreground text-2xs">
                                {t('incidents.ack_by', { user: inc.acknowledgedBy }, `Převzal: ${inc.acknowledgedBy}`)}
                              </span>
                            )}
                          </div>
                          {!expanded && inc.updates?.length > 0 && (
                            <p className="text-muted-foreground truncate text-xs">
                              {inc.updates[inc.updates.length - 1].message}
                            </p>
                          )}
                          <p className="text-muted-foreground figure flex flex-wrap gap-x-3 gap-y-0.5 text-2xs">
                            <span>
                              {t('incidents.created_label', 'Vytvořeno')}:{' '}
                              <span className="text-foreground">{inc.createdAt}</span>
                            </span>
                            {inc.resolvedAt && (
                              <span>
                                {t('incidents.resolved_label', 'Vyřešeno')}:{' '}
                                <span className="text-foreground">{inc.resolvedAt}</span>
                              </span>
                            )}
                            <span>
                              {t('incidents.duration', 'Doba trvání')}:{' '}
                              <span className="text-warning font-semibold">{inc.durationText}</span>
                            </span>
                          </p>
                        </div>
                        <Button size="sm" onClick={() => toggleIncident(inc)} aria-expanded={expanded}>
                          {expanded ? t('incidents.collapse', 'Sbalit') : t('incidents.detail_btn', 'Timeline & akce')}
                        </Button>
                      </div>

                      {expanded && renderIncidentDetail(inc)}
                    </FeedRow>
                  );
                })}
              </ul>
            )}
          </section>

          {/* Section 1b: outage history - resolved incidents, kept apart from
              the ongoing ones so the heading and the list can never disagree. */}
          {resolvedIncidents.length > 0 && (
            <section aria-labelledby="incidents-history" className="space-y-3">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 id="incidents-history" className="text-base font-semibold tracking-tight">
                  {t('incidents.history_title', 'Historie výpadků')}{' '}
                  <span className="text-muted-foreground figure text-xs font-normal">({resolvedIncidents.length})</span>
                </h2>
                <Pill tone="up">{t('incidents.history_badge', 'Vyřešeno')}</Pill>
              </div>

              <ul>
                {resolvedIncidents.slice(0, historyLimit).map((inc, i, shown) => {
                  const stillDown = inc.monitorId != null && downMonitorIds.has(inc.monitorId);
                  return (
                    <FeedRow
                      key={inc.id}
                      icon={stillDown ? CircleX : CheckCircle2}
                      tone={stillDown ? 'down' : 'up'}
                      last={i === shown.length - 1}
                    >
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="text-sm font-semibold">{inc.title}</h3>
                        {inc.monitorId == null && (
                          <Pill size="sm" tone="warning">
                            {t('incidents.manual_badge', 'Ručně nahlášeno')}
                          </Pill>
                        )}
                        {stillDown && (
                          <Pill size="sm" tone="down">
                            {t('incidents.still_down_badge', 'Monitor je stále nedostupný')}
                          </Pill>
                        )}
                      </div>
                      <p className="text-muted-foreground figure mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-2xs">
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
                      </p>
                      {inc.postmortem && (
                        <p className="text-muted-foreground mt-1 line-clamp-2 text-xs">
                          <span className="font-semibold">{t('incidents.postmortem', 'Postmortem')}:</span>{' '}
                          {inc.postmortem}
                        </p>
                      )}
                    </FeedRow>
                  );
                })}
              </ul>

              {resolvedIncidents.length > historyLimit && (
                <Button variant="ghost" size="sm" onClick={() => setHistoryLimit((n) => n + 10)}>
                  {t(
                    'incidents.history_more',
                    { count: resolvedIncidents.length - historyLimit },
                    `Zobrazit dalších ${resolvedIncidents.length - historyLimit}`
                  )}
                </Button>
              )}
            </section>
          )}
        </div>
      )}

      {/* Where the checks come FROM (W1-A3). One row per place from
          monitor_logs.checked_from, not the monitors of type node, and no
          latency where nothing was measured. */}
      <Panel
        icon={Radio}
        title={t('incidents.locations_title', 'Místa měření')}
        hint={t(
          'incidents.locations_hint',
          { days: REGION_DAYS, min: PROBE_STALE_AFTER_MS / 60_000 },
          `Odkud kontroly běží, za posledních ${REGION_DAYS} dní. Místo bez výsledku déle než ${PROBE_STALE_AFTER_MS / 60_000} min (dva intervaly kontrol) je označené jako odmlčené.`
        )}
      >
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
          <EmptyState
            boxed
            size="inline"
            title={t(
              'incidents.locations_empty',
              { days: REGION_DAYS },
              `Za posledních ${REGION_DAYS} dní nepřišel výsledek z žádného místa měření.`
            )}
          />
        ) : (
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {regions.map((r) => {
              const fresh = locationFreshness(r, regionsCachedAt, regionsAt);
              return (
                <li key={r.location ?? '—'} className="bg-inset space-y-2 rounded-lg border border-border p-3.5">
                  <div className="flex items-start justify-between gap-2">
                    <p className="min-w-0 truncate text-sm font-semibold" title={r.location ?? undefined}>
                      {r.location ?? t('incidents.location_unnamed', 'Místo neuvedeno')}
                    </p>
                    {fresh.stale !== null && (
                      <Pill size="sm" dot tone={fresh.stale ? 'warning' : 'up'}>
                        {fresh.stale
                          ? t('incidents.location_stale', 'Odmlčelo se')
                          : t('incidents.location_active', 'Měří')}
                      </Pill>
                    )}
                  </div>
                  <KeyValueList
                    dense
                    rows={[
                      {
                        id: 'last',
                        label: t('incidents.location_last', 'Poslední výsledek'),
                        value: fresh.ageMin === null ? null : agoLabel(fresh.ageMin, t),
                      },
                      {
                        id: 'success',
                        label: t('incidents.location_success', 'Úspěšnost'),
                        value: r.successRate == null ? null : `${r.successRate} %`,
                      },
                      {
                        id: 'avg',
                        label: t('incidents.location_avg', 'Průměrná odezva'),
                        value: r.avgResponseMs == null ? null : `${r.avgResponseMs} ms`,
                      },
                    ]}
                  />
                </li>
              );
            })}
          </ul>
        )}
      </Panel>
    </div>
  );
}

/**
 * One item of the incident feed (NetPulse alerts): the icon on the rail, a
 * line down to the next item, and the card with an edge in the state's colour.
 * The words inside say the state; the colour only makes it findable.
 */
function FeedRow({
  icon,
  tone,
  last,
  highlight = false,
  children,
}: {
  icon: LucideIcon;
  tone: 'down' | 'warning' | 'up';
  last: boolean;
  /** Lifted ground: an item that wants attention now. */
  highlight?: boolean;
  children: React.ReactNode;
}) {
  return (
    <li className="flex gap-3">
      <div aria-hidden="true" className="flex w-9 shrink-0 flex-col items-center">
        <IconTile icon={icon} tone={tone} />
        {!last && <span className="bg-border mt-1 w-px flex-1" />}
      </div>
      <div
        data-slot="incident-card"
        className={cn(
          'mb-2 min-w-0 flex-1 rounded-lg border-l-2 px-3 py-2.5',
          tone === 'down' ? 'border-l-down' : tone === 'warning' ? 'border-l-warning' : 'border-l-up',
          highlight ? 'bg-raised' : 'bg-card'
        )}
      >
        {children}
      </div>
    </li>
  );
}

/** One of the three count tiles at the top; a zero keeps a neutral icon. */
function CountTile({
  icon,
  tone,
  count,
  label,
  hint,
}: {
  icon: LucideIcon;
  tone: 'down' | 'warning' | 'neutral';
  count: number;
  label: string;
  hint: string;
}) {
  return (
    <div className="bg-card text-card-foreground shadow-card panel-sheen flex min-w-0 flex-col items-start gap-2 rounded-xl border border-border p-3 sm:flex-row sm:items-center sm:gap-3 sm:p-4">
      <IconTile icon={icon} tone={tone} className="sm:size-11 sm:rounded-xl sm:[&>svg]:size-5" />
      <div className="min-w-0">
        <p className="figure text-2xl leading-none font-semibold">{count}</p>
        <p className="micro-label mt-1.5 break-words">{label}</p>
        <p className="text-muted-foreground mt-0.5 hidden truncate text-2xs sm:block">{hint}</p>
      </div>
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
