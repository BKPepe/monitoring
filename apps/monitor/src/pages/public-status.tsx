import * as React from 'react';
import { useSearchParams } from 'react-router';
import { Activity, CheckCircle2, Moon, Radio, Rss, Sun, Wrench } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { usePublicStatus } from '@/api/use-asset-charts';
import { PublicMonitorCard, type PublicMonitor } from '@/components/public/monitor-card';
import { Timeline } from '@/components/timeline';
import type { TimelineEvent } from '@/data/model';
import type { UptimeDay } from '@/components/public/uptime-strip';
import { useLanguage } from '@/context/language-context';
import { useTheme } from '@/lib/use-theme';
import { cn } from '@/lib/utils';

interface Region {
  location: string;
  checks: number;
  successRate: number | null;
  avgResponseMs: number | null;
}

interface PublicEvent {
  time: string;
  monitorName: string;
  isDown: boolean;
  rawStatus: string;
  errorMsg: string | null;
  location: string | null;
  type: string | null;
  responseTime: number | null;
  outageDurationSec: number | null;
}

interface Incident {
  id: number;
  title: string;
  /** 'open' | 'investigating' | 'resolved' - rozhoduje stav, ne domněnka. */
  status: string;
  impact: string | null;
  createdAt: string;
  resolvedAt: string | null;
  durationText: string | null;
}

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
/** How often the page re-fetches everything it shows. The header says so. */
const REFRESH_MS = 60_000;

export function PublicStatusPage() {
  const { t, lang, setLang } = useLanguage();
  const { theme, toggle: toggleTheme } = useTheme();
  const [params] = useSearchParams();
  // A status page left open on a wall monitor has to stay true without F5.
  const { data: status, error } = usePublicStatus(REFRESH_MS);

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
            // Server posílá volby vždy kompletní; tohle je jen pojistka pro
            // starší nasazení bez endpointu s volbami.
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
  const [uptime, setUptime] = React.useState<Record<string, UptimeDay[]>>({});
  const [incidents, setIncidents] = React.useState<Incident[] | null>(null);
  const [regions, setRegions] = React.useState<Region[] | null>(null);
  const [branding, setBranding] = React.useState<{
    siteTitle: string;
    customLogoUrl: string;
    portalUrl: string;
    customNavLinks: { name: string; url: string }[];
  } | null>(null);
  const [uptimeById, setUptimeById] = React.useState<Record<number, number | null>>({});
  const [events, setEvents] = React.useState<PublicEvent[] | null>(null);

  // Auto-refresh for everything the page shows, not just the headline stats:
  // the tick re-runs the whole fetch effect. Failed refreshes keep the last
  // known data on screen - a blink to an empty page would claim an outage of
  // the STATUS PAGE as an outage of the services.
  const [refreshTick, setRefreshTick] = React.useState(0);
  React.useEffect(() => {
    const id = window.setInterval(() => setRefreshTick((n) => n + 1), REFRESH_MS);
    return () => window.clearInterval(id);
  }, []);

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=monitors')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setMonitors(Array.isArray(d.monitors) ? d.monitors : []);
      })
      .catch(() => {
        if (active) setMonitors((prev) => prev ?? []);
      });
    // The 30-day strips - one request for every monitor at once, keyed by id.
    fetch('/status/api.php?action=daily_uptime&days=30')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active && d.series && typeof d.series === 'object') setUptime(d.series);
      })
      .catch(() => {});
    // Per-monitor 30-day availability - the number next to the strip, same as
    // the legacy card's "Uptime (30 dní)". Computed server-side by sla_report;
    // null stays null and renders as a dash, never as 100 %.
    fetch('/status/api.php?action=sla_report&days=30')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (!active || !Array.isArray(d.monitors)) return;
        const map: Record<number, number | null> = {};
        for (const m of d.monitors) map[m.id] = typeof m.uptimePercent === 'number' ? m.uptimePercent : null;
        setUptimeById(map);
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
    fetch('/status/api.php?action=regions&days=30')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setRegions(Array.isArray(d.regions) ? d.regions : []);
      })
      .catch(() => {
        if (active) setRegions((prev) => prev ?? []);
      });
    // Recent events - the "what happened lately" strip the legacy page had.
    fetch('/status/api.php?action=events&limit=200')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setEvents(Array.isArray(d.events) ? d.events : []);
      })
      .catch(() => {
        if (active) setEvents((prev) => prev ?? []);
      });
    // Incidents arrive as JSON and paginate client-side. The legacy page
    // shipped all 200 rows as styled HTML - a third of its 1.1 MB.
    fetch('/status/api.php?action=incidents')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setIncidents(Array.isArray(d.manualIncidents) ? d.manualIncidents : []);
      })
      .catch(() => {
        if (active) setIncidents((prev) => prev ?? []);
      });
    return () => {
      active = false;
    };
  }, [refreshTick]);

  // Grouped by category, in the order the categories first appear - the legacy
  // page did the same, so an existing page keeps its familiar layout.
  const categories = React.useMemo(() => {
    const groups = new Map<string, PublicMonitor[]>();
    const allowed = pageMeta && pageMeta.monitorIds.length > 0 ? new Set(pageMeta.monitorIds) : null;
    for (const m of monitors ?? []) {
      if (allowed && !allowed.has(m.id)) continue;
      const key = m.category?.trim() || t('public.uncategorised', 'Ostatní');
      groups.set(key, [...(groups.get(key) ?? []), m]);
    }
    return [...groups.entries()];
  }, [monitors, t, pageMeta]);

  // On a filtered page the verdict and counts must describe the SELECTION,
  // not the whole fleet - "all systems online" about services the page does
  // not even show would be a lie told by an accurate number.
  const visibleMonitors = React.useMemo(() => {
    const allowed = pageMeta && pageMeta.monitorIds.length > 0 ? new Set(pageMeta.monitorIds) : null;
    if (!allowed) return monitors;
    return (monitors ?? []).filter((m) => allowed.has(m.id));
  }, [monitors, pageMeta]);

  const filtered = pageMeta !== null && pageMeta.monitorIds.length > 0;
  const opts = pageMeta?.displayOptions ?? {
    showRegions: true,
    showEvents: true,
    showIncidents: true,
    showUptime: true,
    detailLevel: 'full' as const,
  };
  // Stránkování po deseti. Endpoint vrací až 200 posledních kontrol; po
  // odfiltrování na výpadky a zhoršení jich může zbýt od nuly po desítky -
  // ukazovat všechny najednou by na klidné flotile nevadilo, po hektickém
  // týdnu by to byla zeď.
  const [eventsShown, setEventsShown] = React.useState(10);
  const allFailureEvents = React.useMemo(
    () => (events ?? []).filter((e) => e.isDown || e.rawStatus === 'warning'),
    [events]
  );
  const publicTimeline = React.useMemo<TimelineEvent[]>(() => {
    return allFailureEvents.slice(0, eventsShown).map((e, i) => ({
      id: i,
      title: e.monitorName,
      detail:
        (e.errorMsg || (e.isDown ? t('public.event_down', 'Výpadek') : t('public.event_warn', 'Zhoršení'))) +
        (e.outageDurationSec
          ? t(
              'public.event_duration',
              { min: Math.round(e.outageDurationSec / 60) },
              ` (trvání ${Math.round(e.outageDurationSec / 60)} min)`
            )
          : ''),
      at: e.time,
      severity: e.isDown ? ('down' as const) : ('warning' as const),
      resolution: e.isDown ? ('Open' as const) : ('Info' as const),
      location: e.location ?? undefined,
      method: e.type ?? undefined,
      responseMs: typeof e.responseTime === 'number' ? e.responseTime : null,
    }));
  }, [allFailureEvents, eventsShown, t]);

  const down = filtered
    ? (visibleMonitors ?? []).filter((m) => m.status === 'down').length
    : (status?.downMonitors ?? 0);
  // Announced maintenance is still unavailability. A verdict of "all systems
  // online" next to a service that is down for maintenance would be false -
  // the visitor gets an amber verdict naming the maintenance instead.
  const inMaintenance = (visibleMonitors ?? []).filter((m) => m.status === 'maintenance').length;
  const online = visibleMonitors === null ? null : visibleMonitors.filter((m) => m.status === 'up').length;
  const allGood = visibleMonitors !== null && (filtered || status != null) && down === 0 && inMaintenance === 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-3">
          {branding?.customLogoUrl && (
            <img src={branding.customLogoUrl} alt="" className="size-10 shrink-0 rounded-md object-contain" />
          )}
          <div className="min-w-0 space-y-1">
            <h1 className="truncate text-2xl font-bold tracking-tight">
              {pageMeta?.title || branding?.siteTitle || t('public.title', 'Stav služeb')}
            </h1>
            {status?.lastUpdated && (
              <p className="text-muted-foreground text-xs">
                {t('public.updated', { at: status.lastUpdated }, `Aktualizováno ${status.lastUpdated}`)}
                {' · '}
                {t('public.auto_refresh', 'obnovuje se každou minutu')}
              </p>
            )}
          </div>
        </div>
        {/* Language and theme toggles - the two things an anonymous visitor
            may actually need from a header. */}
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setLang(lang === 'cs' ? 'en' : 'cs')}
            className="text-muted-foreground hover:text-foreground rounded-md border border-border px-2.5 py-1 text-xs font-medium transition-colors"
          >
            {lang === 'cs' ? 'EN' : 'CS'}
          </button>
          <button
            type="button"
            onClick={toggleTheme}
            aria-label={t('public.theme_toggle', 'Přepnout motiv')}
            className="text-muted-foreground hover:text-foreground rounded-md border border-border p-1.5 transition-colors"
          >
            {theme === 'dark' ? <Sun className="size-3.5" /> : <Moon className="size-3.5" />}
          </button>
        </div>
      </header>

      {/* An unknown or hidden page is indistinguishable from a missing one -
          the server already made that decision; here it just gets a face. */}
      {pageError && (
        <Card className="p-6 text-center">
          <p className="text-sm font-semibold">{t('public.page_not_found', 'Stránka nenalezena')}</p>
          <p className="text-muted-foreground mt-1 text-xs">
            {t('public.page_not_found_desc', 'Tato status stránka neexistuje nebo není veřejná.')}
          </p>
        </Card>
      )}

      {/* The headline verdict. `null` while loading is not "everything is fine" -
          an unknown state must not read as a green light. */}
      <Card
        className={cn(
          'flex flex-wrap items-center gap-3 p-5',
          allGood
            ? 'border-up/30 bg-up/5'
            : down > 0
              ? 'border-down/30 bg-down/5'
              : inMaintenance > 0
                ? 'border-warning/30 bg-warning/5'
                : ''
        )}
      >
        {allGood ? (
          <CheckCircle2 className="text-up size-6 shrink-0" />
        ) : down === 0 && inMaintenance > 0 ? (
          <Wrench className="text-warning size-6 shrink-0" />
        ) : (
          <Activity className="text-muted-foreground size-6 shrink-0" />
        )}
        <div className="min-w-0">
          <p className="text-base font-bold">
            {status == null && monitors == null
              ? t('public.loading', 'Zjišťuji stav…')
              : down > 0
                ? t('public.degraded', { count: down }, `${down} služeb mimo provoz`)
                : inMaintenance > 0
                  ? t('public.in_maintenance', { count: inMaintenance }, `${inMaintenance} služeb v plánované údržbě`)
                  : t('public.all_ok', 'Všechny systémy jsou online')}
          </p>
          {error && (
            <p className="text-muted-foreground text-xs">{t('public.load_error', 'Data se nepodařilo načíst.')}</p>
          )}
        </div>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {/* "Online 6" vedle "Agenti online 6/6" četlo jako totéž dvakrát a
            "agent" je interní žargon - čtvrtá dlaždice teď říká, z kolika
            MÍST se měří, což návštěvníkovi skutečně něco sděluje. */}
        <Stat label={t('public.stat_online', 'Online')} value={online} tone="up" />
        <Stat
          label={t('public.stat_down', 'Mimo provoz')}
          value={filtered ? down : status ? down : null}
          tone={down > 0 ? 'down' : undefined}
        />
        <Stat label={t('public.stat_uptime', 'Dostupnost 30 dní')} value={status?.uptimePercent ?? null} suffix=" %" />
        <Stat label={t('public.stat_regions', 'Míst měření')} value={regions === null ? null : regions.length} />
      </div>

      {/* The measurement locations - where the checks come FROM. This answers
          "is the service down, or can one vantage point just not see it". */}
      {opts.showRegions && regions !== null && regions.length > 0 && !filtered && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Radio className="size-4 text-primary" />
            {t('public.regions', 'Místa měření')}
          </h2>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {regions.slice(0, 9).map((r) => (
              <div
                key={r.location}
                className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-xs"
              >
                <span className="truncate font-medium" title={r.location}>
                  {r.location}
                </span>
                <span className="text-muted-foreground shrink-0 tabular-nums">
                  {r.successRate === null ? '—' : `${r.successRate} %`}
                </span>
              </div>
            ))}
          </div>
        </Card>
      )}

      {monitors === null ? (
        <p className="text-muted-foreground text-sm">{t('public.loading_services', 'Načítám služby…')}</p>
      ) : (
        categories.map(([category, items]) => (
          <Card key={category} className="space-y-1 p-5">
            <h2 className="text-sm font-semibold">{category}</h2>
            <ul>
              {items.map((m) => (
                <PublicMonitorCard
                  key={m.id}
                  monitor={m}
                  uptime={opts.showUptime ? (uptime[String(m.id)] ?? []) : []}
                  uptimePct={uptimeById[m.id] ?? null}
                  statusOnly={opts.detailLevel === 'status'}
                />
              ))}
            </ul>
          </Card>
        ))
      )}

      {/* Recent events - the same Timeline the device detail uses (day groups,
          severity dots, location), not a bare text list. Only failures and
          degradations: a wall of "check passed" rows tells a visitor nothing. */}
      {opts.showEvents && publicTimeline.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="text-sm font-semibold">{t('public.recent_events', 'Poslední události')}</h2>
          <Timeline events={publicTimeline} />
          {allFailureEvents.length > eventsShown && (
            <button
              type="button"
              onClick={() => setEventsShown((n) => n + 10)}
              className="text-muted-foreground hover:text-foreground w-full rounded-md border border-border py-1.5 text-xs font-medium transition-colors"
            >
              {t(
                'public.show_more_events',
                { n: allFailureEvents.length - eventsShown },
                `Zobrazit další (${allFailureEvents.length - eventsShown})`
              )}
            </button>
          )}
        </Card>
      )}

      {opts.showIncidents && incidents !== null && incidents.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="text-sm font-semibold">{t('public.incidents', 'Incidenty')}</h2>
          <ul className="space-y-2">
            {incidents.slice(0, 10).map((inc) => (
              // "Probíhá" se odvozuje ze STAVU, ne z chybějícího pole.
              // První verze četla resolved_at (snake_case), API posílá
              // resolvedAt - vyřešený incident z 8. 8. se tak ukazoval jako
              // probíhající. Ověřeno proti skutečné odpovědi.
              <li key={inc.id} className="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                {/* Odznak se slovem místo tečky: zelené kolečko vedle titulku
                    "Výpadek: ..." četlo jako protimluv - teď tam stojí přímo
                    "Vyřešeno", resp. "Probíhá". */}
                <span className="flex items-center gap-2 font-medium">
                  <Badge variant={inc.status === 'resolved' ? 'up' : 'down'}>
                    {inc.status === 'resolved'
                      ? t('public.incident_resolved', 'Vyřešeno')
                      : t('public.incident_open', 'Probíhá')}
                  </Badge>
                  {inc.title}
                </span>
                {/* Bez sekund: s nimi se rozsah na úzkém displeji lámal
                    uprostřed času. Minutová přesnost tu stačí - trvání
                    říká durationText. */}
                <span className="text-muted-foreground tabular-nums">
                  {noSeconds(inc.createdAt)}
                  {inc.status === 'resolved' && inc.resolvedAt
                    ? ` → ${noSeconds(inc.resolvedAt)}${inc.durationText ? ` (${inc.durationText})` : ''}`
                    : ''}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {/* The same footer the legacy page had: © + portal link, custom links
          from the admin settings, RSS, and who runs the monitoring. */}
      <footer className="text-muted-foreground space-y-2 border-t border-border pt-4 text-xs">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
          <a
            href={pageSlug ? `/status/rss.php?page=${encodeURIComponent(pageSlug)}` : '/status/rss.php'}
            className="hover:text-foreground inline-flex items-center gap-1.5 transition-colors"
          >
            <Rss className="size-3.5" /> {t('public.rss', 'RSS kanál výpadků')}
          </a>
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
        <p>
          © {new Date().getFullYear()}{' '}
          {branding?.portalUrl ? (
            <a href={branding.portalUrl} className="hover:text-foreground transition-colors">
              {branding.siteTitle || 'Blood Kings'}
            </a>
          ) : (
            (branding?.siteTitle ?? '')
          )}
          . {t('public.footer_rights', 'Všechna práva vyhrazena.')} · {t('public.powered_by', 'Poháněno')}{' '}
          <a
            href="https://monitoring.bloodkings.eu"
            target="_blank"
            rel="noopener noreferrer"
            className="underline transition-colors hover:text-foreground"
          >
            Blood Kings Monitoring
          </a>
        </p>
      </footer>
    </div>
  );
}

/** "08.08.2026 00:21:11" -> "08.08.2026 00:21" - seconds add wrap, not meaning. */
function noSeconds(v: string): string {
  return v.replace(/(\d{1,2}:\d{2}):\d{2}/, '$1');
}

function Stat({
  label,
  value,
  suffix = '',
  tone,
}: {
  label: string;
  value: number | null;
  suffix?: string;
  tone?: 'up' | 'down';
}) {
  return (
    <Card className="p-4">
      <p className="text-muted-foreground text-[11px] font-medium">{label}</p>
      {/* Unknown renders as a dash. A zero here would claim a measurement. */}
      <p
        className={cn(
          'mt-1 text-2xl font-bold tracking-tight tabular-nums',
          tone === 'up' ? 'text-up' : tone === 'down' ? 'text-down' : ''
        )}
      >
        {value === null ? '—' : `${value}${suffix}`}
      </p>
    </Card>
  );
}
