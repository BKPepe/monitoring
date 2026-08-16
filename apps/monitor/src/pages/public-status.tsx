import * as React from 'react';
import { useSearchParams } from 'react-router';
import { Activity, CheckCircle2, Radio, Rss } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { usePublicStatus } from '@/api/use-asset-charts';
import { PublicMonitorCard, type PublicMonitor } from '@/components/public/monitor-card';
import type { UptimeDay } from '@/components/public/uptime-strip';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

interface Incident {
  id: number;
  title: string;
  status: string;
  impact: string | null;
  created_at: string;
  resolved_at: string | null;
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
export function PublicStatusPage() {
  const { t, lang, setLang } = useLanguage();
  const [params] = useSearchParams();
  const { data: status, error } = usePublicStatus();

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
  const [pageMeta, setPageMeta] = React.useState<{ title: string; monitorIds: number[] } | null>(null);
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
        if (active) setPageMeta({ title: d.title ?? '', monitorIds: d.monitorIds ?? [] });
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

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=monitors')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setMonitors(Array.isArray(d.monitors) ? d.monitors : []);
      })
      .catch(() => {
        if (active) setMonitors([]);
      });
    // The 30-day strips - one request for every monitor at once, keyed by id.
    fetch('/status/api.php?action=daily_uptime&days=30')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active && d.series && typeof d.series === 'object') setUptime(d.series);
      })
      .catch(() => {});
    // Incidents arrive as JSON and paginate client-side. The legacy page
    // shipped all 200 rows as styled HTML - a third of its 1.1 MB.
    fetch('/status/api.php?action=incidents')
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => {
        if (active) setIncidents(Array.isArray(d.manualIncidents) ? d.manualIncidents : []);
      })
      .catch(() => {
        if (active) setIncidents([]);
      });
    return () => {
      active = false;
    };
  }, []);

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
  const down = filtered
    ? (visibleMonitors ?? []).filter((m) => m.status === 'down').length
    : (status?.downMonitors ?? 0);
  const allGood = filtered ? visibleMonitors !== null && down === 0 : status != null && down === 0;

  return (
    <div className="mx-auto max-w-5xl space-y-6 px-4 py-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="space-y-1">
          <h1 className="text-2xl font-bold tracking-tight">{pageMeta?.title || t('public.title', 'Stav služeb')}</h1>
          {status?.lastUpdated && (
            <p className="text-muted-foreground text-xs">
              {t('public.updated', { at: status.lastUpdated }, `Aktualizováno ${status.lastUpdated}`)}
            </p>
          )}
        </div>
        {/* Language toggle: the first thing an anonymous visitor may need. */}
        <button
          type="button"
          onClick={() => setLang(lang === 'cs' ? 'en' : 'cs')}
          className="text-muted-foreground hover:text-foreground rounded-md border border-border px-2.5 py-1 text-xs font-medium transition-colors"
        >
          {lang === 'cs' ? 'EN' : 'CS'}
        </button>
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
          allGood ? 'border-up/30 bg-up/5' : down > 0 ? 'border-down/30 bg-down/5' : ''
        )}
      >
        {allGood ? (
          <CheckCircle2 className="text-up size-6 shrink-0" />
        ) : (
          <Activity className="text-muted-foreground size-6 shrink-0" />
        )}
        <div className="min-w-0">
          <p className="text-base font-bold">
            {status == null
              ? t('public.loading', 'Zjišťuji stav…')
              : down > 0
                ? t('public.degraded', { count: down }, `${down} služeb mimo provoz`)
                : t('public.all_ok', 'Všechny systémy jsou online')}
          </p>
          {error && (
            <p className="text-muted-foreground text-xs">{t('public.load_error', 'Data se nepodařilo načíst.')}</p>
          )}
        </div>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat
          label={t('public.stat_online', 'Online')}
          value={filtered ? (visibleMonitors ?? []).filter((m) => m.status === 'up').length : statOnline(status)}
          tone="up"
        />
        <Stat
          label={t('public.stat_down', 'Mimo provoz')}
          value={filtered ? down : status ? down : null}
          tone={down > 0 ? 'down' : undefined}
        />
        <Stat label={t('public.stat_uptime', 'Dostupnost 30 dní')} value={status?.uptimePercent ?? null} suffix=" %" />
        <Stat
          label={t('public.stat_agents', 'Agenti online')}
          value={status ? status.agentsOnline : null}
          suffix={status ? ` / ${status.agentsTotal}` : ''}
        />
      </div>

      {/* Measurement locations - the answer to "is the service down, or can our
          server just not see it". */}
      {status && status.nodes.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="flex items-center gap-2 text-sm font-semibold">
            <Radio className="size-4 text-primary" />
            {t('public.regions', 'Místa měření')}
          </h2>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {status.nodes.map((n) => (
              <div
                key={n.name}
                className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-xs"
              >
                <span className="truncate font-medium">{n.name}</span>
                <span className="text-muted-foreground tabular-nums">
                  {/* A dash, not a zero: no measurement is not zero latency. */}
                  {n.latencyMs === null ? '—' : `${n.latencyMs} ms`}
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
                <PublicMonitorCard key={m.id} monitor={m} uptime={uptime[String(m.id)] ?? []} />
              ))}
            </ul>
          </Card>
        ))
      )}

      {incidents !== null && incidents.length > 0 && (
        <Card className="space-y-3 p-5">
          <h2 className="text-sm font-semibold">{t('public.incidents', 'Incidenty')}</h2>
          <ul className="space-y-2">
            {incidents.slice(0, 10).map((inc) => (
              <li key={inc.id} className="flex flex-wrap items-baseline justify-between gap-2 text-xs">
                <span className="font-medium">{inc.title}</span>
                <span className="text-muted-foreground tabular-nums">
                  {inc.created_at}
                  {inc.resolved_at ? ` → ${inc.resolved_at}` : ` · ${t('public.incident_open', 'probíhá')}`}
                </span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <footer className="text-muted-foreground flex flex-wrap items-center gap-3 pt-2 text-xs">
        <a
          href={pageSlug ? `/status/rss.php?page=${encodeURIComponent(pageSlug)}` : '/status/rss.php'}
          className="hover:text-foreground inline-flex items-center gap-1.5 transition-colors"
        >
          <Rss className="size-3.5" /> {t('public.rss', 'RSS kanál výpadků')}
        </a>
      </footer>
    </div>
  );
}

function statOnline(status: { totalMonitors: number; downMonitors: number } | null): number | null {
  if (status == null) return null;
  return status.totalMonitors - status.downMonitors;
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
