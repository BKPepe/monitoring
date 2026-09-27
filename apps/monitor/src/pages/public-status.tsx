import * as React from 'react';
import { useSearchParams } from 'react-router';
import { Activity, CheckCircle2, CloudOff, History, Siren, TriangleAlert, Wrench } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { usePublicStatus } from '@/api/use-asset-charts';
import { PublicMonitorCard, typeIcon, type PublicMonitor, type UptimeWindows } from '@/components/public/monitor-card';
import { PublicHeader } from '@/components/public/public-header';
import { PublicHealthScore } from '@/components/public/public-health';
import { IncidentList, type PublicIncident as Incident } from '@/components/public/incident-list';
import { ProbeLocations, type ProbeRegion as Region } from '@/components/public/probe-locations';
import { SubscribePanel } from '@/components/public/subscribe-panel';
import { Panel } from '@/components/ui/panel';
import { IconTile, type IconTileTone } from '@/components/ui/icon-tile';
import { SectionTitle } from '@/components/ui/section-title';
import { StatBlock, StatRow } from '@/components/stat-block';
import { RangePills } from '@/components/charts/range-pills';
import { FreshnessPill } from '@/components/freshness-pill';
import { DayStripLegend } from '@/components/day-strip';
import { CollapsedTimeline } from '@/components/timeline';
import type { TimelineEvent } from '@/data/model';
import { collapseRuns } from '@/lib/timeline-collapse';
import type { UptimeDay } from '@/components/public/uptime-strip';
import { useLanguage } from '@/context/language-context';
import { versionCommitUrl } from '@/lib/version';
import { formatPercentValue } from '@/lib/utils';
import { LoadingState, ErrorState } from '@/components/ui/states';
import { pluralForm } from '@/lib/plural';
import { syncPublicCanonical } from '@/lib/public-head';
import { parsePublicHealth, type PublicHealth } from '@/lib/public-health';
import { outageDurationText, outageEpisode, outageResolutions, type MonitorOutageFacts } from '@/lib/public-events';

interface PublicEvent extends MonitorOutageFacts {
  time: string;
  /** The same moment machine-readable: a collapsed run's span and the gap that ends it. */
  timeIso?: string | null;
  monitorName: string;
  rawStatus: string;
  errorMsg: string | null;
  location: string | null;
  type: string | null;
  responseTime: number | null;
}

/** The periods the day strips can show; 30 is the light default, 90 is asked for. */
const HISTORY_RANGES = ['30d', '90d'] as const;
type HistoryRange = (typeof HISTORY_RANGES)[number];

/**
 * The public status page - what a visitor without an account sees.
 *
 * Replaces the legacy index.php, which server-rendered the whole thing into
 * 1116 kB of HTML: 224 kB of that was 3195 inline style attributes, and the
 * incident table shipped all 200 rows even though JavaScript then paginated
 * them. The same information as JSON is 36 kB, and the styling arrives once as
 * a cached stylesheet instead of being repeated on every element.
 *
 * Deliberately readable without logging in - the API strips network identity
 * (addresses, SSIDs, hostnames) from anonymous responses, so what arrives here
 * is already safe to show.
 */
/** States the verdict has a word for; anything else (unknown, pending) is not "online". */
const NAMED_STATES = new Set(['up', 'down', 'warning', 'maintenance']);

/** How often the page re-fetches everything it shows. The header says so. */
const REFRESH_MS = 60_000;

export function PublicStatusPage() {
  const { t, lang, setLang } = useLanguage();
  const [params] = useSearchParams();
  // A status page left open on a wall monitor has to stay true without F5.
  const { data: status, error, reload: reloadStatus } = usePublicStatus(REFRESH_MS, 'public');

  // ?lang=en in the URL wins over the stored preference - existing links to
  // the legacy page carry it and they have to keep meaning the same thing.
  const urlLang = params.get('lang');
  React.useEffect(() => {
    if (urlLang === 'en' || urlLang === 'cs') setLang(urlLang);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [urlLang]);

  // Custom status page (?page=slug): narrows the monitor list and swaps the
  // title. A hidden page 404s for anonymous visitors exactly like a
  // nonexistent slug - that behaviour lives on the server, not here.
  const pageSlug = params.get('page');
  const [pageMeta, setPageMeta] = React.useState<{
    title: string;
    monitorIds: number[];
    displayOptions: {
      showRegions: boolean;
      showEvents: boolean;
      showIncidents: boolean;
      showUptime: boolean;
      detailLevel: 'full' | 'status';
    };
  } | null>(null);
  const [pageError, setPageError] = React.useState(false);
  // The indexed head (public.html) names the main page; a custom page is its
  // own canonical address (W1-G4).
  React.useEffect(() => {
    syncPublicCanonical(document, pageSlug);
  }, [pageSlug]);
  React.useEffect(() => {
    if (!pageSlug) {
      setPageMeta(null);
      setPageError(false);
      return;
    }
    let active = true;
    fetch(`/status/api.php?action=status_page&slug=${encodeURIComponent(pageSlug)}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active)
          setPageMeta({
            title: d.title ?? '',
            monitorIds: d.monitorIds ?? [],
            // The server always sends the options complete; this is just a
            // safety net for older deployments without the options endpoint.
            displayOptions: d.displayOptions ?? {
              showRegions: true,
              showEvents: true,
              showIncidents: true,
              showUptime: true,
              detailLevel: 'full',
            },
          });
      })
      .catch(() => {
        if (active) setPageError(true);
      });
    return () => {
      active = false;
    };
  }, [pageSlug]);
  const [monitors, setMonitors] = React.useState<PublicMonitor[] | null>(null);
  // The LATEST monitors request failed. `monitors` may still hold the last
  // known list, but the verdict can no longer vouch for the present.
  const [monitorsError, setMonitorsError] = React.useState(false);
  // When the list last arrived intact - what a failed refresh names as the
  // time of the data still on screen.
  const [okAt, setOkAt] = React.useState<number | null>(null);
  // null = the strips' first answer has not come yet; the cards hold their row.
  const [uptime, setUptime] = React.useState<Record<string, UptimeDay[]> | null>(null);
  // The latest strips request failed. The last known strips stay; without
  // any, the page says the history is missing instead of silently dropping it.
  const [uptimeFailed, setUptimeFailed] = React.useState(false);
  // The period of the strips ON SCREEN. It lags the switch while the longer
  // answer is on the way (or when it failed), and the row figures follow it,
  // so a 90-day share never sits beside a 30-day strip.
  const [uptimeDays, setUptimeDays] = React.useState<30 | 90 | null>(null);
  const [incidents, setIncidents] = React.useState<Incident[] | null>(null);
  // The first incidents answer (or its failure) is in. The service list waits
  // for it: an ongoing incident pinned above services already on screen
  // pushed the whole list down under the visitor's eyes (layout shift).
  const [incidentsSettled, setIncidentsSettled] = React.useState(false);
  const haveMonitors = monitors !== null;
  // ...but not for ever: an incidents request that hangs (an overloaded
  // database, a proxy timeout - just when visitors come) must not keep the
  // services off the page. After a moment the list comes without it, and the
  // incident, if it arrives, shifts the page as it did before.
  React.useEffect(() => {
    if (incidentsSettled || !haveMonitors) return;
    const id = window.setTimeout(() => setIncidentsSettled(true), 2500);
    return () => window.clearTimeout(id);
  }, [incidentsSettled, haveMonitors]);
  const [regions, setRegions] = React.useState<Region[] | null>(null);
  const [branding, setBranding] = React.useState<{
    siteTitle: string;
    customLogoUrl: string;
    portalUrl: string;
    customNavLinks: { name: string; url: string }[];
  } | null>(null);
  const [windowsById, setWindowsById] = React.useState<Record<number, UptimeWindows>>({});
  /** First day of the 90-day window, for the "data od" line of a younger monitor (W1-B2). */
  const [windowStart90, setWindowStart90] = React.useState<string | null>(null);
  const [events, setEvents] = React.useState<PublicEvent[] | null>(null);
  // The strips' period. 90 days is asked for only when the visitor picks it:
  // three times the rows on every anonymous page load for a view few open.
  const [range, setRange] = React.useState<HistoryRange>('30d');
  const days = range === '90d' ? 90 : 30;
  // The public services score; null = not answered yet (or failed before any answer).
  const [health, setHealth] = React.useState<PublicHealth | null>(null);
  const [healthFailed, setHealthFailed] = React.useState(false);

  // Auto-refresh for everything the page shows, not just the headline stats:
  // the tick re-runs the whole fetch effect. Failed refreshes keep the last
  // known data on screen - a blink to an empty page would claim an outage of
  // the STATUS PAGE as an outage of the services.
  const [refreshTick, setRefreshTick] = React.useState(0);
  // "Now" for comparing maintenance windows lives in state - Date.now() right
  // in render is impure (and the lint rightly refuses it); minute granularity
  // is plenty here, maintenance windows are not announced to the second.
  const [nowTs, setNowTs] = React.useState(() => Date.now());
  React.useEffect(() => {
    const id = window.setInterval(() => {
      setRefreshTick((n) => n + 1);
      setNowTs(Date.now());
    }, REFRESH_MS);
    return () => window.clearInterval(id);
  }, []);

  React.useEffect(() => {
    let active = true;
    // scope=public: the status of every public monitor, the same for everyone.
    // Without it a signed-in user would see only the monitors assigned to them
    // here too, and host internals would depend on who happens to be looking.
    // A failure keeps `monitors` as it was (null before the first answer) and
    // raises monitorsError. Turning it into [] made "no services, nothing
    // down" out of "the API did not answer", and the verdict said all online.
    fetch('/status/api.php?action=monitors&scope=public')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (!active) return;
        // An answer without the list (or one naming an error) is not an
        // empty fleet either - older servers sent 200 + [] from a failed query.
        if (!Array.isArray(d.monitors) || (typeof d.error === 'string' && d.error !== '')) {
          setMonitorsError(true);
          return;
        }
        setMonitors(d.monitors);
        setMonitorsError(false);
        setOkAt(Date.now());
      })
      .catch(() => {
        if (active) setMonitorsError(true);
      });
    // Per-monitor availability for 24 h / 7 d / 30 d / 90 d in one request -
    // the 30 d value sits next to the strip (same as the legacy card), the
    // rest fills the expanded detail. null stays null and renders as a dash,
    // never as 100 %.
    fetch('/status/api.php?action=uptime_windows&scope=public')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (!active || d.windows == null || typeof d.windows !== 'object') return;
        setWindowsById(d.windows);
        setWindowStart90(typeof d.windowStart?.d90 === 'string' ? d.windowStart.d90 : null);
      })
      .catch(() => {});
    // Branding from the admin settings - the same title and logo the legacy
    // page shows. An empty customLogoUrl means no logo, not a broken image.
    fetch('/status/api.php?action=ui_config')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active)
          setBranding({
            siteTitle: d.siteTitle ?? '',
            customLogoUrl: d.customLogoUrl ?? '',
            portalUrl: d.portalUrl ?? '',
            customNavLinks: Array.isArray(d.customNavLinks) ? d.customNavLinks : [],
          });
      })
      .catch(() => {});
    // The real measurement locations - regions, not nodes. The first version
    // labelled the `nodes` list "measurement locations", but nodes are the
    // MONITORED SERVERS; verified against production, the locations live in
    // action=regions (Frankfurt, Cloudflare POPs, GitHub runners).
    fetch('/status/api.php?action=regions&days=30&scope=public')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active && Array.isArray(d.regions)) setRegions(d.regions);
      })
      // Failed: the tile keeps its dash (null) or the last known count - a 0
      // would claim that nothing measures the services.
      .catch(() => {});
    // Recent events - the "what happened lately" strip the legacy page had.
    fetch('/status/api.php?action=events&limit=200&scope=public')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active && Array.isArray(d.events)) setEvents(d.events);
      })
      .catch(() => {});
    // Incidents arrive as JSON and paginate client-side. The legacy page
    // shipped all 200 rows as styled HTML - a third of its 1.1 MB.
    fetch('/status/api.php?action=incidents&scope=public')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active && Array.isArray(d.manualIncidents)) setIncidents(d.manualIncidents);
      })
      .catch(() => {})
      .finally(() => {
        if (active) setIncidentsSettled(true);
      });
    return () => {
      active = false;
    };
  }, [refreshTick]);

  // The day strips - one request for every monitor at once, keyed by id.
  // Its own effect because it follows the language too: the server words
  // each day's sentence, and without ?lang an English visitor who never set
  // the language cookie read those sentences in Czech. A switch to 90 days
  // keeps the 30-day strips on screen until the longer answer lands: back to
  // "pending" would blank every row and shift the page twice.
  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=daily_uptime&days=${days}&scope=public&lang=${lang}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (!active) return;
        if (d.series && typeof d.series === 'object') {
          setUptime(d.series);
          setUptimeDays(days);
          setUptimeFailed(false);
        } else setUptimeFailed(true);
      })
      .catch(() => {
        if (active) setUptimeFailed(true);
      });
    return () => {
      active = false;
    };
  }, [refreshTick, lang, days]);

  // The public services score (action=health). The whole public set, never a
  // custom page's selection, so a ?page= page does not ask for it at all. A
  // failed refresh keeps the last score and says so; a failed first answer
  // is a failure in the ring's place, never an empty "—" ring.
  React.useEffect(() => {
    if (pageSlug) return;
    let active = true;
    fetch(`/status/api.php?action=health&scope=public&lang=${lang}`)
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (!active) return;
        setHealth(parsePublicHealth(d));
        setHealthFailed(false);
      })
      .catch(() => {
        if (active) setHealthFailed(true);
      });
    return () => {
      active = false;
    };
  }, [refreshTick, lang, pageSlug]);

  // A custom page's selection; null = the whole public set.
  const allowed = React.useMemo(
    () => (pageMeta && pageMeta.monitorIds.length > 0 ? new Set(pageMeta.monitorIds) : null),
    [pageMeta]
  );

  // Grouped by category, in the order the categories first appear - the legacy
  // page did the same, so an existing page keeps its familiar layout.
  const categories = React.useMemo(() => {
    const groups = new Map<string, PublicMonitor[]>();
    for (const m of monitors ?? []) {
      if (allowed && !allowed.has(m.id)) continue;
      const key = m.category?.trim() || t('public.uncategorised', 'Ostatní');
      groups.set(key, [...(groups.get(key) ?? []), m]);
    }
    return [...groups.entries()];
  }, [monitors, t, allowed]);

  // On a filtered page the verdict and counts must describe the SELECTION,
  // not the whole fleet - "all systems online" about services the page does
  // not even show would be a lie told by an accurate number.
  const visibleMonitors = React.useMemo(() => {
    if (!allowed) return monitors;
    return (monitors ?? []).filter((m) => allowed.has(m.id));
  }, [monitors, allowed]);

  const filtered = pageMeta !== null && pageMeta.monitorIds.length > 0;
  const opts = pageMeta?.displayOptions ?? {
    showRegions: true,
    showEvents: true,
    showIncidents: true,
    showUptime: true,
    detailLevel: 'full' as const,
  };
  // The cards hold the strip's row until the first answer; a failed first
  // answer releases it and says so once below the services.
  const stripsPending = opts.showUptime && uptime === null && !uptimeFailed;
  const stripsMissing = opts.showUptime && uptime === null && uptimeFailed;
  // Pagination by ten. The endpoint returns up to 200 recent checks; after
  // filtering to outages and degradations anywhere from zero to dozens may
  // remain - showing all at once would be fine on a calm fleet and a wall
  // after a hectic week.
  const [eventsShown, setEventsShown] = React.useState(10);
  // The incidents of this page: a custom page shows those of its selection
  // plus the ones announced for no one monitor, which concern everybody.
  // Another service's incident under a verdict about the selection made the
  // page contradict itself.
  const showIncidents = opts.showIncidents;
  const scopedIncidents = React.useMemo(
    () =>
      showIncidents
        ? (incidents ?? []).filter((inc) => allowed === null || inc.monitorId == null || allowed.has(inc.monitorId))
        : [],
    [incidents, allowed, showIncidents]
  );
  // "Ongoing" derives from the STATE, not from a missing field. The first
  // version read resolved_at (snake_case) while the API sends resolvedAt - a
  // resolved incident from 8 Aug thus showed as ongoing. Open and resolved
  // split BEFORE the cap of ten: the API orders by id, so an incident still
  // open behind ten newer resolved ones fell off the page entirely. Only the
  // resolved history is capped.
  const openIncidents = React.useMemo(() => scopedIncidents.filter((i) => i.status !== 'resolved'), [scopedIncidents]);
  const pastIncidents = React.useMemo(
    () => scopedIncidents.filter((i) => i.status === 'resolved').slice(0, 10),
    [scopedIncidents]
  );
  // Monitors an open incident on this page speaks for.
  const coveredIds = React.useMemo(
    () => new Set(openIncidents.map((i) => i.monitorId).filter((id): id is number => typeof id === 'number')),
    [openIncidents]
  );

  const allFailureEvents = React.useMemo(
    () =>
      (events ?? []).filter(
        (e) =>
          (e.isDown || e.rawStatus === 'warning') &&
          // A custom page lists the failures of its own selection only.
          (allowed === null || (e.monitorId != null && allowed.has(e.monitorId))) &&
          // The running outage of a monitor an open incident covers is that
          // incident's story, pinned under the verdict; its failed checks
          // told it a second time, each with its own "Probíhá". Ended
          // outages of the same monitor are other facts and stay.
          !(e.isDown && !e.outageEnd && e.monitorId != null && coveredIds.has(e.monitorId))
      ),
    [events, allowed, coveredIds]
  );
  const timelineEvents = React.useMemo<TimelineEvent[]>(() => {
    // The monitor's status right now, for failures whose end is not recorded.
    // The whole public list, not the page's filtered view: an event names
    // its monitor whichever page shows it.
    const statusById = new Map((monitors ?? []).map((m) => [m.id, m.status]));
    // Over the whole list, not the shown page of it: "Probíhá" belongs to
    // the newest check of a running outage whichever page of ten shows it.
    const resolutions = outageResolutions(allFailureEvents, (id) => statusById.get(id) ?? null);
    return allFailureEvents.map((e, i) => ({
      id: i,
      title: e.monitorName,
      detail:
        (e.errorMsg || (e.isDown ? t('public.event_down', 'Výpadek') : t('public.event_warn', 'Zhoršení'))) +
        outageDurationText(e, t),
      at: e.time,
      atIso: e.timeIso ?? null,
      severity: e.isDown ? ('down' as const) : ('warning' as const),
      resolution: resolutions[i],
      // The outage a check belongs to: a collapsed run never crosses it, so
      // two outages of one service days apart stay two rows (V-01).
      episode: outageEpisode(e),
      // Proven running (no recorded end, the monitor down now), on the
      // newest check of the outage only.
      ongoing: resolutions[i] === 'Open',
      location: e.location ?? undefined,
      method: e.type ?? undefined,
      responseMs: typeof e.responseTime === 'number' ? e.responseTime : null,
    }));
  }, [allFailureEvents, monitors, t]);
  // Paged by what the visitor sees: a run of twenty failed checks is one row
  // (C-10), so "show more" never adds ten checks that fold into nothing.
  const eventItems = React.useMemo(() => collapseRuns(timelineEvents), [timelineEvents]);
  const publicTimeline = React.useMemo(
    () => eventItems.slice(0, eventsShown).flatMap((item) => (item.kind === 'run' ? item.events : [item.event])),
    [eventItems, eventsShown]
  );

  // null = not known yet or the request failed. `?? 0` here once turned "the
  // API did not answer" into "nothing is down".
  const down: number | null = filtered
    ? visibleMonitors === null
      ? null
      : visibleMonitors.filter((m) => m.status === 'down').length
    : status
      ? status.downMonitors
      : null;
  // Announced maintenance is still unavailability. A verdict of "all systems
  // online" next to a service that is down for maintenance would be false -
  // the visitor gets an amber verdict naming the maintenance instead.
  const inMaintenance = (visibleMonitors ?? []).filter((m) => m.status === 'maintenance').length;
  // Degraded, an agent gone silent, or a state the page has no word for (never
  // reported yet): not an outage, but not "all online" either.
  const partial = (visibleMonitors ?? []).filter(
    (m) => m.status === 'warning' || m.agentSilent === true || !NAMED_STATES.has(m.status)
  ).length;
  const online = visibleMonitors === null ? null : visibleMonitors.filter((m) => m.status === 'up').length;
  // The latest answer failed: whatever the last known list says, the page
  // cannot vouch for the present. The fleet summary counts only on the
  // unfiltered page, where the down count comes from it.
  const failed = monitorsError || (!filtered && error !== null);
  const verdict: 'error' | 'loading' | 'down' | 'partial' | 'maintenance' | 'ok' = failed
    ? 'error'
    : visibleMonitors === null || down === null
      ? 'loading'
      : down > 0
        ? 'down'
        : partial > 0
          ? 'partial'
          : inMaintenance > 0
            ? 'maintenance'
            : 'ok';

  // The tab and search-result title: a running outage comes first, so a tab
  // left open says so without being looked at (W1-G4). Only a verdict built
  // from a fresh answer counts - a failed load never claims or denies one.
  const pageName = pageMeta?.title || t('public.title', 'Stav služeb');
  const outageCount = verdict === 'down' && down !== null ? down : 0;
  const outagePrefix =
    outageCount > 0
      ? {
          one: t('public.doc_title_down_one', { count: outageCount }, `(${outageCount}) výpadek`),
          few: t('public.doc_title_down_few', { count: outageCount }, `(${outageCount}) výpadky`),
          other: t('public.doc_title_down_other', { count: outageCount }, `(${outageCount}) výpadků`),
        }[pluralForm(lang, outageCount)] + ' · '
      : '';
  const docTitle = `${outagePrefix}${pageName} | Blood Kings`;

  // The count with its noun in the form the count takes, as in the tab title
  // above: one form for every count printed "1 služeb mimo provoz".
  const services = (n: number) =>
    ({
      one: t('public.services_one', { count: n }, `${n} služba`),
      few: t('public.services_few', { count: n }, `${n} služby`),
      other: t('public.services_other', { count: n }, `${n} služeb`),
    })[pluralForm(lang, n)];
  // One service down: the headline names it ("E-shop mimo provoz"), so the
  // visitor learns WHAT is broken without scrolling. The name only when the
  // page's own list holds exactly that one: mid-refresh the fleet count and
  // the list can disagree, and then the count is what is known. "Řešíme"
  // only when an open incident covers every service that is down - the page
  // does not promise work nobody has announced.
  const downList = (visibleMonitors ?? []).filter((m) => m.status === 'down');
  const handled = downList.length > 0 && downList.length === down && downList.every((m) => coveredIds.has(m.id));
  const downText = (() => {
    if (down === 1 && downList.length === 1) {
      const name = downList[0].name;
      return handled
        ? t('public.down_named_handled', { name }, `${name} mimo provoz - řešíme`)
        : t('public.down_named', { name }, `${name} mimo provoz`);
    }
    const counted = t('public.degraded', { services: services(down ?? 0) }, `${services(down ?? 0)} mimo provoz`);
    return handled ? t('public.handled_suffix', { text: counted }, `${counted} - řešíme`) : counted;
  })();
  const partialText = t(
    'public.partial_desc',
    { services: services(partial) },
    `${services(partial)} hlásí zhoršení nebo neznámý stav`
  );
  const maintenanceText = t(
    'public.in_maintenance',
    { services: services(inMaintenance) },
    `${services(inMaintenance)} v plánované údržbě`
  );

  // The API sends ISO 8601, right for machines; a visitor reads it in the page
  // language and in their own time zone. An unparseable value is shown as
  // sent rather than as "Invalid Date".
  const lastUpdated = status?.lastUpdated ?? null;
  const updatedDate = lastUpdated ? new Date(lastUpdated) : null;
  const updatedAt =
    lastUpdated && updatedDate && !Number.isNaN(updatedDate.getTime())
      ? updatedDate.toLocaleString(lang === 'cs' ? 'cs-CZ' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' })
      : lastUpdated;

  // Announced FUTURE maintenance - the window is yet to come, the service still runs.
  const upcoming = (visibleMonitors ?? []).filter(
    (m) =>
      m.maintenance === true &&
      m.status !== 'maintenance' &&
      m.maintenanceStart != null &&
      new Date(m.maintenanceStart.replace(' ', 'T')).getTime() > nowTs
  );
  const updatedMs = updatedDate && !Number.isNaN(updatedDate.getTime()) ? updatedDate.getTime() : null;

  // The score describes the whole public set: a custom page, which shows a
  // selection, leaves it out rather than put a number beside services it
  // does not list.
  const showHealth = !pageSlug;
  // Everything that stacks above and in the service list arrives in one
  // paint: the list, the pinned incidents and the maintenance ahead.
  const listReady = monitors !== null && incidentsSettled;
  // The list failed: say so at once, with whatever incidents are known.
  const listFailed = monitors === null && monitorsError;
  // The asked period failed and an older one is still drawn: said, not
  // silently left at the other period under a pressed "90d".
  const rangeMissing = opts.showUptime && uptimeFailed && uptime !== null && uptimeDays !== null && uptimeDays !== days;
  const verdictText =
    verdict === 'error'
      ? t('public.state_unknown', 'Stav se nepodařilo zjistit')
      : verdict === 'loading'
        ? t('public.loading', 'Zjišťuji stav…')
        : verdict === 'down'
          ? downText
          : verdict === 'partial'
            ? t('public.partial', 'Provoz je částečně omezen')
            : verdict === 'maintenance'
              ? maintenanceText
              : t('public.all_ok', 'Všechny systémy jsou online');
  const verdictLook: { icon: typeof Activity; tone: IconTileTone } =
    verdict === 'ok'
      ? { icon: CheckCircle2, tone: 'up' }
      : verdict === 'down'
        ? { icon: Activity, tone: 'down' }
        : verdict === 'partial'
          ? { icon: TriangleAlert, tone: 'warning' }
          : verdict === 'maintenance'
            ? { icon: Wrench, tone: 'info' }
            : verdict === 'error'
              ? { icon: CloudOff, tone: 'neutral' }
              : { icon: Activity, tone: 'neutral' };
  const rssHref = pageSlug ? `/status/rss.php?page=${encodeURIComponent(pageSlug)}` : '/status/rss.php';

  return (
    <div className="bg-background text-foreground min-h-dvh">
      <title>{docTitle}</title>
      {/* A custom page that does not exist (or is not public) must not be
          indexed as an empty status page; it answers 200 like every route. */}
      {pageError && <meta name="robots" content="noindex" />}
      <PublicHeader
        siteTitle={branding === null ? null : branding.siteTitle || 'Blood Kings'}
        logoUrl={branding?.customLogoUrl ?? ''}
        portalUrl={branding?.portalUrl ?? ''}
      />

      <main className="mx-auto max-w-6xl space-y-5 px-4 py-5 sm:space-y-6 sm:px-6 sm:py-8">
        {/* An unknown or hidden page is indistinguishable from a missing one -
            the server already made that decision; here it just gets a face. */}
        {pageError && (
          <Panel className="text-center">
            <p className="text-sm font-semibold">{t('public.page_not_found', 'Stránka nenalezena')}</p>
            <p className="text-muted-foreground mt-1 text-xs">
              {t('public.page_not_found_desc', 'Tato status stránka neexistuje nebo není veřejná.')}
            </p>
          </Panel>
        )}

        {/* The headline verdict, in strict precedence: a failed request, then an
            outage, then a partial problem, then maintenance, and only then all
            online. An unknown state must never read as a green light. The
            panel itself stays untinted: the icon, the sentence and the
            freshness pill carry the state (no tint on tint). */}
        <Panel padding="none" className="overflow-hidden">
          <div
            className={
              showHealth
                ? 'grid gap-8 p-5 sm:p-7 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-center'
                : 'grid gap-8 p-5 sm:p-7'
            }
          >
            <div className="min-w-0 space-y-5">
              <h1 className="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{pageName}</h1>
              <div className="flex items-start gap-4">
                <IconTile icon={verdictLook.icon} tone={verdictLook.tone} size="lg" />
                <div className="min-w-0 flex-1 space-y-1 pt-1">
                  <p
                    className="text-lg font-semibold tracking-tight sm:text-xl"
                    role={verdict === 'error' ? 'alert' : undefined}
                  >
                    {verdictText}
                  </p>
                  {verdict === 'partial' && <p className="text-muted-foreground text-sm">{partialText}</p>}
                  {verdict === 'error' && (
                    <p className="text-muted-foreground text-sm">
                      {monitors !== null
                        ? t(
                            'public.state_unknown_stale',
                            'Níže je poslední známý stav. Další pokus proběhne za minutu.'
                          )
                        : t('public.load_error', 'Data se nepodařilo načíst.')}
                    </p>
                  )}
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                {/* How old the verdict is, from the newest check the server holds -
                    a wall display left open must not look live after cron or the
                    API stopped. Cron writes every 1-5 minutes, hence 300 s. */}
                <FreshnessPill at={updatedMs} intervalSecs={300} failed={failed} okAt={okAt} />
                {updatedAt && (
                  <p className="text-muted-foreground text-xs">
                    {t('public.updated', { at: updatedAt }, `Aktualizováno ${updatedAt}`)}
                    {' · '}
                    {t('public.auto_refresh', 'obnovuje se každou minutu')}
                  </p>
                )}
                {verdict === 'error' && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setRefreshTick((n) => n + 1);
                      reloadStatus();
                    }}
                  >
                    {t('common.retry', 'Zkusit znovu')}
                  </Button>
                )}
              </div>
              {/* The figures under the verdict (NetPulse KPI strip). Unknown is a
                  dash, never a 0, and only a count of services down takes a
                  colour: a green "Online 6" read as a second verdict. */}
              <StatRow cols={4} className="border-t border-border pt-5">
                <StatBlock variant="plain" size="xs" label={t('public.stat_online', 'Online')} value={online} />
                <StatBlock
                  variant="plain"
                  size="xs"
                  label={t('public.stat_down', 'Mimo provoz')}
                  value={down}
                  tone={down !== null && down > 0 ? 'down' : null}
                />
                <StatBlock
                  variant="plain"
                  size="xs"
                  label={t('public.stat_uptime', 'Dostupnost 30 dní')}
                  value={status?.uptimePercent != null ? formatPercentValue(status.uptimePercent, 2, lang) : null}
                  secondary="%"
                />
                <StatBlock
                  variant="plain"
                  size="xs"
                  label={t('public.stat_regions', 'Míst měření')}
                  value={regions === null ? null : regions.length}
                />
              </StatRow>
            </div>
            {showHealth && (
              <div className="flex justify-center border-t border-border pt-6 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-8">
                <PublicHealthScore health={health} failed={healthFailed} />
              </div>
            )}
          </div>
        </Panel>

        {listReady || listFailed ? (
          <>
            {/* What is being done about it comes right under the verdict: an
              ongoing incident sat below every service card and the whole event
              log, a long scroll away from the visitor who came because of it. */}
            {openIncidents.length > 0 && (
              <Panel icon={Siren} title={t('public.incidents', 'Incidenty')} count={openIncidents.length}>
                <IncidentList incidents={openIncidents} />
              </Panel>
            )}

            {/* Announced future maintenance: the visitor should learn about a
              planned window ahead of time, not when the service disappears.
              Running maintenance is in the verdict and on the card. */}
            {upcoming.length > 0 && (
              <Panel icon={Wrench} title={t('public.upcoming_maintenance', 'Plánovaná údržba')} count={upcoming.length}>
                <ul className="flex flex-col gap-2">
                  {upcoming.map((m) => (
                    <li key={m.id} className="bg-raised border-l-info flex gap-3 rounded-lg border-l-2 px-3 py-2.5">
                      <IconTile icon={Wrench} tone="info" size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">
                          {m.name}
                          {m.maintenanceDescription ? (
                            <span className="text-muted-foreground font-normal"> — {m.maintenanceDescription}</span>
                          ) : null}
                        </p>
                        <p className="figure text-muted-foreground text-xs">
                          {fmtWindow(m.maintenanceStart, m.maintenanceEnd, t)}
                        </p>
                      </div>
                    </li>
                  ))}
                </ul>
              </Panel>
            )}

            {/* The services, one card per category, under one heading that holds
              the period switch for every strip on the page. */}
            <section className="space-y-3" aria-labelledby="public-services-heading">
              <SectionTitle
                headingId="public-services-heading"
                title={t('public.services', 'Služby')}
                count={visibleMonitors === null ? undefined : visibleMonitors.length}
                action={
                  opts.showUptime ? (
                    <RangePills
                      value={range}
                      options={HISTORY_RANGES}
                      onChange={setRange}
                      label={t('public.history_range', 'Období historie')}
                      titles={{
                        '30d': t('public.range_30d', 'Posledních 30 dní'),
                        '90d': t('public.range_90d', 'Posledních 90 dní'),
                      }}
                    />
                  ) : undefined
                }
              />
              {monitors === null ? (
                <Panel>
                  <ErrorState message={t('public.services_failed', 'Seznam služeb se nepodařilo načíst.')} />
                </Panel>
              ) : (
                categories.map(([category, items]) => (
                  // The category names are the admin's free text; the icon is that
                  // of the first service in it, so "Herní servery" gets a gamepad
                  // without the page guessing from the name.
                  <Panel
                    key={category}
                    icon={typeIcon(items[0].type)}
                    title={category}
                    count={items.length}
                    headingLevel={3}
                    padding="none"
                  >
                    <ul className="divide-y divide-border border-t border-border">
                      {items.map((m) => (
                        <PublicMonitorCard
                          key={m.id}
                          monitor={m}
                          uptime={!opts.showUptime ? [] : stripsPending ? null : (uptime?.[String(m.id)] ?? [])}
                          uptimePct={windowsById[m.id]?.d30 ?? null}
                          windows={windowsById[m.id] ?? null}
                          windowStart90={windowStart90}
                          days={uptimeDays ?? days}
                          statusOnly={opts.detailLevel === 'status'}
                        />
                      ))}
                    </ul>
                  </Panel>
                ))
              )}
              {/* One legend for every strip on the page (C-7): amber, blue, hatched
                and dashed days mean something, and a phone cannot hover. It comes
                with the service list, whose held strip rows it explains, not after
                the strips answer - a second late arrival would shift the page again. */}
              {monitors !== null &&
                opts.showUptime &&
                !stripsMissing &&
                (uptime === null || Object.keys(uptime).length > 0) && <DayStripLegend className="px-1" />}
              {monitors !== null && stripsMissing && (
                <ErrorState
                  size="inline"
                  tone="warning"
                  className="px-1"
                  message={t('public.history_failed', 'Denní historii dostupnosti se nepodařilo načíst.')}
                />
              )}
              {monitors !== null && rangeMissing && (
                <ErrorState
                  size="inline"
                  tone="warning"
                  className="px-1"
                  message={t(
                    'public.history_range_failed',
                    { days, shown: uptimeDays ?? 30 },
                    `Historii za ${days} dní se nepodařilo načíst, zobrazeno posledních ${uptimeDays ?? 30} dní.`
                  )}
                />
              )}
            </section>
          </>
        ) : (
          // One placeholder for everything the first answers fill in, as tall
          // as a screen: the pinned incident, the maintenance and the list then
          // arrive in one paint, and what sits below is off screen before and
          // after - nothing already visible jumps (layout shift on the indexed page).
          <Panel className="min-h-[70vh]">
            <LoadingState label={t('public.loading_services', 'Načítám služby…')} />
          </Panel>
        )}

        {/* Recent events - the same timeline the device detail uses (severity
            dots, location, repeats folded into one run), not a bare text list.
            Only failures and degradations: a wall of "check passed" rows tells
            a visitor nothing. */}
        {opts.showEvents && publicTimeline.length > 0 && (
          <Panel icon={History} title={t('public.recent_events', 'Poslední události')}>
            <div className="space-y-4">
              <CollapsedTimeline events={publicTimeline} />
              {eventItems.length > eventsShown && (
                <button
                  type="button"
                  onClick={() => setEventsShown((n) => n + 10)}
                  className="text-muted-foreground hover:text-foreground hover:bg-raised focus-visible:ring-ring w-full rounded-lg border border-border py-2 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                  {t(
                    'public.show_more_events',
                    { n: eventItems.length - eventsShown },
                    `Zobrazit další (${eventItems.length - eventsShown})`
                  )}
                </button>
              )}
            </div>
          </Panel>
        )}

        {pastIncidents.length > 0 && (
          <Panel icon={Siren} title={t('public.past_incidents', 'Historie incidentů')}>
            <IncidentList incidents={pastIncidents} />
          </Panel>
        )}

        {opts.showRegions && regions !== null && regions.length > 0 && !filtered && (
          <ProbeLocations regions={regions} />
        )}

        <SubscribePanel rssHref={rssHref} />
      </main>

      {/* The same footer the legacy page had: © + portal link, custom links
          from the admin settings, and who runs the monitoring. */}
      <footer className="text-muted-foreground mx-auto max-w-6xl space-y-2 px-4 pt-2 pb-8 text-xs sm:px-6">
        <div className="space-y-2 border-t border-border pt-4">
          {(branding?.customNavLinks ?? []).length > 0 && (
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
              {(branding?.customNavLinks ?? []).map((l) => (
                <a
                  key={l.url}
                  href={l.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="hover:text-foreground transition-colors"
                >
                  {l.name}
                </a>
              ))}
            </div>
          )}
          <p>
            © {new Date().getFullYear()}{' '}
            {branding?.portalUrl ? (
              <a href={branding.portalUrl} className="hover:text-foreground transition-colors">
                {branding.siteTitle || 'Blood Kings'}
              </a>
            ) : (
              (branding?.siteTitle ?? '')
            )}
            . {t('public.footer_rights', 'Všechna práva vyhrazena.')}{' '}
            {/* Product attribution only where the instance's own title does not
                already name the product - "© Blood Kings | Status Monitoring ...
                Poháněno Blood Kings Monitoring" read the same name twice in one
                line (reported by the user). Foreign deployments keep the credit. */}
            {!(branding?.siteTitle ?? '').toLowerCase().includes('blood kings') && (
              <>
                · {t('public.powered_by', 'Poháněno')}{' '}
                <a
                  href="https://monitoring.bloodkings.eu"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="underline transition-colors hover:text-foreground"
                >
                  Blood Kings Monitoring
                </a>
              </>
            )}{' '}
            ·{' '}
            {/* The exact deployed version, linking to its commit - the same
                transparency the app footer has had; the public page gets it too
                (open-source project, the repo is public anyway). */}
            <a
              href={versionCommitUrl(__APP_VERSION__)}
              target="_blank"
              rel="noopener noreferrer"
              className="figure decoration-dotted underline-offset-2 transition-colors hover:text-foreground"
              title={t('footer.version_link_title', 'Otevřít zdrojový kód této verze na GitHubu')}
            >
              v{__APP_VERSION__}
            </a>
          </p>
        </div>
      </footer>
    </div>
  );
}

/** The maintenance window "from – to" without seconds; a missing end = an open interval. */
function fmtWindow(
  start: string | null | undefined,
  end: string | null | undefined,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): string {
  const f = (v: string) => v.replace('T', ' ').slice(0, 16);
  if (start && end) return `${f(start)} – ${f(end)}`;
  if (start) return t('public.maintenance_from', { from: f(start) }, `od ${f(start)}`);
  return '';
}
