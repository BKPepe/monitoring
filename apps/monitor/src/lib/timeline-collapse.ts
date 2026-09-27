import type { TimelineEvent } from '@/data/model';

/**
 * Turning an event log into something a person reads (C-10).
 *
 * The check log listed two hundred identical "Kontrola proběhla v pořádku"
 * rows, each 90 px tall and each repeating its title as its body, and an
 * outage appeared as twenty separate failures. Consecutive events that say
 * the same thing - same title, same severity - become one run ("20× za
 * sebou, 19:03–19:22"), and a routine pass is hidden until asked for.
 */
export type TimelineItem =
  { kind: 'event'; event: TimelineEvent } | { kind: 'run'; key: string; events: TimelineEvent[] };

/** A routine pass of the check log: the thing a timeline of changes leaves out. */
export function isRoutine(event: TimelineEvent): boolean {
  return event.kind === 'check' && event.severity === 'info';
}

/** Events that need a look - the number a tab badge shows (an OK check is not news). */
export function countAttention(events: readonly TimelineEvent[]): number {
  return events.filter((e) => e.severity === 'down' || e.severity === 'warning').length;
}

const normalise = (text: string) =>
  text
    .trim()
    .replace(/[.!…\s]+$/u, '')
    .toLocaleLowerCase();

/** The body says nothing the title did not: "Kontrola proběhla v pořádku." under the same title. */
export function repeatsTitle(event: Pick<TimelineEvent, 'title' | 'detail'>): boolean {
  return !event.detail || normalise(event.detail) === normalise(event.title);
}

const timeOf = (e: TimelineEvent): number => {
  const ms = Date.parse(String(e.atIso ?? e.at ?? '').replace(' ', 'T'));
  return Number.isFinite(ms) ? ms : NaN;
};

/**
 * Longest pause between two rows of one continuing failure. Checks run every
 * minute (from several locations at once), so a longer silence between two
 * failures means passing checks were there and the list left them out - the
 * public page lists failures only.
 */
const MAX_RUN_GAP_MS = 5 * 60_000;

/**
 * Whether `next` continues the failure `prev` is part of. Compared with the
 * previous row, not the first one, so a long outage stays one run.
 */
function continuesRun(prev: TimelineEvent, next: TimelineEvent): boolean {
  if (next.title !== prev.title || next.severity !== prev.severity) return false;
  const a = prev.episode ?? null;
  const b = next.episode ?? null;
  // The server's own grouping wins when both rows carry it: one outage is
  // one run however slowly it was checked, two outages are two runs.
  if (a !== null && b !== null) return a === b;
  if (a !== b) return false;
  const ta = timeOf(prev);
  const tb = timeOf(next);
  // An unreadable time cannot prove a gap - the neighbours still merge.
  return Number.isNaN(ta) || Number.isNaN(tb) || Math.abs(ta - tb) <= MAX_RUN_GAP_MS;
}

/**
 * Consecutive events of one continuing failure folded into runs of at least
 * `minRun`: same title and severity, the same outage (`episode`) when the
 * rows name one, and otherwise no longer pause than MAX_RUN_GAP_MS between
 * neighbours. A list of failures only never shows the recovery between two
 * outages, so the pause and the episode are what keep them apart. The order
 * is the caller's - newest first or oldest first - and it is kept.
 */
export function collapseRuns(events: readonly TimelineEvent[], minRun = 2): TimelineItem[] {
  const items: TimelineItem[] = [];
  let i = 0;
  while (i < events.length) {
    let j = i + 1;
    while (j < events.length && continuesRun(events[j - 1], events[j])) {
      j++;
    }
    if (j - i >= minRun) {
      items.push({ kind: 'run', key: `run-${events[i].id}-${events[j - 1].id}`, events: events.slice(i, j) });
    } else {
      for (let k = i; k < j; k++) items.push({ kind: 'event', event: events[k] });
    }
    i = j;
  }
  return items;
}

/**
 * When a run happened, as briefly as it can be said: "19:03–19:22" within
 * one day, the dates too across midnight, and the server's own texts when
 * the times cannot be read.
 */
export function runSpan(events: readonly TimelineEvent[], lang: string): string {
  if (events.length === 0) return '';
  const times = events.map(timeOf);
  if (times.some((t) => Number.isNaN(t))) {
    const first = events[0].at;
    const last = events[events.length - 1].at;
    return first === last ? first : `${last} – ${first}`;
  }
  const from = new Date(Math.min(...times));
  const to = new Date(Math.max(...times));
  const locale = lang === 'en' ? 'en-GB' : 'cs-CZ';
  const clock = (d: Date) => d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
  const date = (d: Date) => d.toLocaleDateString(locale, { day: 'numeric', month: 'numeric' });
  if (from.toDateString() === to.toDateString()) {
    return from.getTime() === to.getTime()
      ? `${date(from)} ${clock(from)}`
      : `${date(from)} ${clock(from)}–${clock(to)}`;
  }
  return `${date(from)} ${clock(from)} – ${date(to)} ${clock(to)}`;
}
