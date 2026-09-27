import * as React from 'react';
import { Clock, MapPin, Globe } from 'lucide-react';
import { Badge, StatusDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { TimelineEvent } from '@/data/model';
import { useLanguage } from '@/context/language-context';
import { collapseRuns, isRoutine, repeatsTitle, runSpan, type TimelineItem } from '@/lib/timeline-collapse';
import { cn } from '@/lib/utils';

type TranslateFn = ReturnType<typeof useLanguage>['t'];

const resolutionVariant = {
  Resolved: 'up',
  Info: 'info',
  Open: 'warning',
} as const;

/**
 * The resolution a severity implies anyway: an outage is open, a recovery
 * resolved, a note is information. A chip that only repeats the dot next to
 * it is noise; it shows when it says something the dot does not.
 */
const IMPLIED: Record<TimelineEvent['severity'], TimelineEvent['resolution'][]> = {
  down: ['Open'],
  warning: ['Info', 'Open'],
  up: ['Resolved'],
  info: ['Info'],
};

// The resolution is a code, not a caption: printed raw it put English words
// on the Czech page. Existing keys carry the same meaning in both languages.
function resolutionLabel(resolution: NonNullable<TimelineEvent['resolution']>, t: TranslateFn): string {
  if (resolution === 'Resolved') return t('incidents.resolved_label', 'Vyřešeno');
  if (resolution === 'Open') return t('public.incident_open', 'Probíhá');
  return t('timeline.sev_info', 'Informace');
}

function EventRow({ event, last, inRun = false }: { event: TimelineEvent; last: boolean; inRun?: boolean }) {
  const { t } = useLanguage();
  // A proven running outage says so even though its dot is red: "down" alone
  // does not tell a running outage from one whose end nobody recorded. Inside
  // a run the summary line carries that chip, once.
  const chip = event.ongoing
    ? inRun
      ? null
      : 'Open'
    : event.resolution && !IMPLIED[event.severity].includes(event.resolution)
      ? event.resolution
      : null;
  return (
    <li className="flex gap-3">
      {/* The vertical line connects timeline dots; not drawn for the last one. */}
      <div className="flex flex-col items-center pt-1.5">
        <StatusDot variant={event.severity} />
        {!last && <span className="bg-border mt-1 w-px flex-1" />}
      </div>
      <div className="min-w-0 flex-1 space-y-1 pb-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-foreground text-sm font-semibold">{event.title}</p>
            {event.at && (
              <span className="text-muted-foreground inline-flex items-center gap-1 text-2xs tabular-nums">
                <Clock aria-hidden="true" className="size-3 shrink-0" />
                {event.at}
              </span>
            )}
          </div>
          {chip && <Badge variant={resolutionVariant[chip]}>{resolutionLabel(chip, t)}</Badge>}
        </div>
        {!repeatsTitle(event) && <p className="text-muted-foreground text-xs leading-relaxed">{event.detail}</p>}
        {(event.location || event.method) && (
          <p className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-0.5 text-2xs">
            {event.method && (
              <span className="inline-flex items-center gap-1">
                <Globe aria-hidden="true" className="size-3 shrink-0" />
                {t('timeline.method_label', 'Metoda / Test:')} <span className="font-mono">{event.method}</span>
              </span>
            )}
            {event.location && (
              <span className="inline-flex items-center gap-1">
                <MapPin aria-hidden="true" className="size-3 shrink-0" />
                {t('timeline.node_label', 'Uzel:')} <span className="font-mono">{event.location}</span>
              </span>
            )}
          </p>
        )}
      </div>
    </li>
  );
}

/** A run of identical events: one line with the count and the span, the rows behind a disclosure. */
/** The kind of failure a run is, in words: the dot's colour alone does not tell an outage from a slowdown. */
function runTypeLabel(severity: TimelineEvent['severity'], t: TranslateFn): string | null {
  if (severity === 'down') return t('timeline.run_down', 'Výpadek');
  if (severity === 'warning') return t('timeline.run_warning', 'Zhoršení');
  return null;
}

function RunRow({ events, last }: { events: TimelineEvent[]; last: boolean }) {
  const { t, lang } = useLanguage();
  const head = events[0];
  const typeLabel = runTypeLabel(head.severity, t);
  return (
    <li className="flex gap-3" data-run={events.length}>
      <div className="flex flex-col items-center pt-1.5">
        <StatusDot variant={head.severity} label={typeLabel ?? undefined} />
        {!last && <span className="bg-border mt-1 w-px flex-1" />}
      </div>
      <details className="min-w-0 flex-1 pb-4">
        <summary className="hover:text-foreground cursor-pointer text-sm">
          <span className="font-semibold">{head.title}</span>
          {typeLabel && <span className="text-foreground">{` · ${typeLabel}`}</span>}
          <span className="text-muted-foreground tabular-nums">
            {' · '}
            {t('timeline.run_count', { n: events.length }, `${events.length}× za sebou`)}
            {' · '}
            {runSpan(events, lang)}
          </span>
          {events.some((e) => e.ongoing) && (
            <Badge variant={resolutionVariant.Open} className="ml-2 align-middle">
              {resolutionLabel('Open', t)}
            </Badge>
          )}
        </summary>
        <ol className="mt-3">
          {events.map((event, i) => (
            <EventRow key={event.id} event={event} last={i === events.length - 1} inRun />
          ))}
        </ol>
      </details>
    </li>
  );
}

function ItemList({ items }: { items: TimelineItem[] }) {
  return (
    <ol className="flex flex-col">
      {items.map((item, i) =>
        item.kind === 'run' ? (
          <RunRow key={item.key} events={item.events} last={i === items.length - 1} />
        ) : (
          <EventRow key={item.event.id} event={item.event} last={i === items.length - 1} />
        )
      )}
    </ol>
  );
}

type Filter = 'changes' | 'all' | 'down' | 'warning' | 'up' | 'info';

/**
 * The one event timeline (C-10): consecutive repeats collapse into runs, a
 * body that repeats its title is dropped, and a chip shows only when it adds
 * something. With `filters` it also carries the severity chips, the order and
 * the paging the device page needs, and opens on the changes: routine passes
 * of the check log wait behind "Vše".
 */
export function CollapsedTimeline({ events, filters = false }: { events: TimelineEvent[]; filters?: boolean }) {
  const { t } = useLanguage();
  const hasRoutine = events.some(isRoutine);
  // The default follows the data: the log arrives after the first render,
  // and the changes view is the default only once there is routine to hide.
  const [picked, setFilter] = React.useState<Filter | null>(null);
  const filter: Filter = picked ?? (hasRoutine ? 'changes' : 'all');
  const [page, setPage] = React.useState(0);
  const [pageSize, setPageSize] = React.useState(10);
  const [newestFirst, setNewestFirst] = React.useState(true);

  // Sorted by time; an unparsable date keeps its original position (the
  // server sends newest first) instead of sinking to the bottom.
  const sorted = React.useMemo(() => {
    if (!filters) return events;
    const withTime = events.map((e, i) => ({ e, i, ts: Date.parse(String(e.atIso ?? e.at).replace(' ', 'T')) }));
    withTime.sort((a, b) => {
      if (Number.isNaN(a.ts) || Number.isNaN(b.ts)) return a.i - b.i;
      return newestFirst ? b.ts - a.ts : a.ts - b.ts;
    });
    return withTime.map((x) => x.e);
  }, [events, filters, newestFirst]);

  const shown = React.useMemo(() => {
    if (!filters || filter === 'all') return sorted;
    if (filter === 'changes') return sorted.filter((e) => !isRoutine(e));
    return sorted.filter((e) => e.severity === filter);
  }, [sorted, filters, filter]);
  const items = React.useMemo(() => collapseRuns(shown), [shown]);

  if (events.length === 0) {
    return (
      <p className="text-muted-foreground py-6 text-center text-sm">{t('timeline.no_events', 'Žádné události.')}</p>
    );
  }
  if (!filters) return <ItemList items={items} />;

  const count = (f: Filter) =>
    f === 'all'
      ? events.length
      : f === 'changes'
        ? events.filter((e) => !isRoutine(e)).length
        : events.filter((e) => e.severity === f).length;
  const labels: Record<Filter, string> = {
    changes: t('timeline.changes', 'Změny'),
    all: t('common.all', 'Vše'),
    down: t('timeline.sev_down', 'Výpadky'),
    warning: t('timeline.sev_warning', 'Varování'),
    up: t('timeline.sev_up', 'Obnovení'),
    info: t('timeline.sev_info', 'Informace'),
  };
  const choices: Filter[] = hasRoutine
    ? ['changes', 'all', 'down', 'warning', 'up', 'info']
    : ['all', 'down', 'warning', 'up', 'info'];

  const pageCount = Math.max(1, Math.ceil(items.length / pageSize));
  const current = Math.min(page, pageCount - 1);
  const visible = items.slice(current * pageSize, current * pageSize + pageSize);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-1.5">
        {choices.map((f) =>
          count(f) === 0 && f !== 'all' && f !== 'changes' ? null : (
            <button
              key={f}
              type="button"
              aria-pressed={filter === f}
              onClick={() => {
                setFilter(f);
                setPage(0);
              }}
              className={cn(
                'rounded-md px-2.5 py-1 text-2xs font-semibold transition-colors',
                filter === f
                  ? 'bg-primary text-primary-foreground'
                  : 'bg-secondary text-muted-foreground hover:text-foreground'
              )}
            >
              {labels[f]} <span className="opacity-70">({count(f)})</span>
            </button>
          )
        )}
        <div className="ml-auto flex items-center gap-2">
          <button
            type="button"
            onClick={() => {
              setNewestFirst((v) => !v);
              setPage(0);
            }}
            className="bg-secondary text-muted-foreground hover:text-foreground rounded-md px-2.5 py-1 text-2xs font-semibold transition-colors"
          >
            {newestFirst
              ? t('timeline.newest_first', 'Nejnovější první')
              : t('timeline.oldest_first', 'Nejstarší první')}
          </button>
          <select
            value={pageSize}
            onChange={(e) => {
              setPageSize(Number(e.target.value));
              setPage(0);
            }}
            aria-label={t('timeline.page_size', 'Počet na stránku')}
            className="border-border bg-background rounded-md border px-1.5 py-1 text-2xs"
          >
            {[10, 25, 50, 100].map((n) => (
              <option key={n} value={n}>
                {n} / {t('timeline.page_unit', 'stránku')}
              </option>
            ))}
          </select>
        </div>
      </div>

      {items.length === 0 ? (
        <p className="text-muted-foreground py-4 text-center text-sm">
          {t('timeline.no_changes', 'Žádné změny - jen běžné kontroly. Najdete je pod „Vše“.')}
        </p>
      ) : (
        <ItemList items={visible} />
      )}

      {pageCount > 1 && (
        <div className="flex items-center justify-between gap-2 pt-1">
          <span className="text-muted-foreground text-2xs">
            {t(
              'timeline.page_info',
              { from: current * pageSize + 1, to: current * pageSize + visible.length, total: items.length },
              `${current * pageSize + 1}–${current * pageSize + visible.length} z ${items.length}`
            )}
          </span>
          <div className="flex items-center gap-1.5">
            <Button variant="outline" size="sm" disabled={current === 0} onClick={() => setPage(current - 1)}>
              ← {t('common.previous', 'Předchozí')}
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={current >= pageCount - 1}
              onClick={() => setPage(current + 1)}
            >
              {t('common.next', 'Další')} →
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

// The plain list the public page drew before it moved to CollapsedTimeline.
// It stays only until that page's own commit, so the page keeps working.
const LEGACY_VARIANT = {
  Resolved: 'up',
  Info: 'info',
  Open: 'warning',
} as const;

const LEGACY_LABEL = {
  Resolved: ['incidents.resolved_label', 'Vyřešeno'],
  Info: ['timeline.sev_info', 'Informace'],
  Open: ['public.incident_open', 'Probíhá'],
} as const;

/**
 * Event timeline for a device.
 *
 * Rendered as an <ol> - it's an ordered list in time, which a screen reader
 * announces ("item 2 of 4") and a keyboard user can step through.
 */
export function Timeline({ events }: { events: TimelineEvent[] }) {
  const { t } = useLanguage();
  if (events.length === 0) {
    return (
      <p className="text-muted-foreground py-6 text-center text-sm">{t('timeline.no_events', 'Žádné události.')}</p>
    );
  }

  return (
    <ol className="flex flex-col">
      {events.map((event, index) => (
        <li key={event.id} className="flex gap-3">
          {/* The vertical line connects timeline dots; not drawn for the last one. */}
          <div className="flex flex-col items-center pt-1.5">
            <StatusDot variant={event.severity} />
            {index < events.length - 1 && <span className="bg-border mt-1 w-px flex-1" />}
          </div>

          <div className="min-w-0 flex-1 pb-4 space-y-1.5">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-semibold text-foreground">{event.title}</p>
                {event.at && (
                  <span className="inline-flex items-center gap-1 text-2xs font-mono text-muted-foreground bg-secondary/60 px-2 py-0.5 rounded border border-border shadow-xs">
                    <Clock className="size-3 text-muted-foreground shrink-0" />
                    <span>{event.at}</span>
                  </span>
                )}
              </div>
              {event.resolution && (
                <Badge variant={LEGACY_VARIANT[event.resolution]}>
                  {t(LEGACY_LABEL[event.resolution][0], LEGACY_LABEL[event.resolution][1])}
                </Badge>
              )}
            </div>

            <p className="text-muted-foreground text-xs leading-relaxed">{event.detail}</p>

            {(event.location || event.method) && (
              <div className="flex flex-wrap items-center gap-2 pt-0.5 text-2xs">
                {event.method && (
                  <span className="inline-flex items-center gap-1.5 font-mono text-muted-foreground bg-secondary/60 px-2 py-0.5 rounded border border-border">
                    <Globe className="size-3 text-muted-foreground shrink-0" />
                    <span>
                      {t('timeline.method_label', 'Metoda / Test:')}{' '}
                      <strong className="text-foreground">{event.method}</strong>
                    </span>
                  </span>
                )}
                {event.location && (
                  <span className="inline-flex items-center gap-1.5 font-mono text-muted-foreground bg-secondary/60 px-2 py-0.5 rounded border border-border">
                    <MapPin className="size-3 text-muted-foreground shrink-0" />
                    <span>
                      {t('timeline.node_label', 'Uzel:')} <strong className="text-foreground">{event.location}</strong>
                    </span>
                  </span>
                )}
              </div>
            )}
          </div>
        </li>
      ))}
    </ol>
  );
}
