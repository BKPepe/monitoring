/**
 * How much of a long window actually has data (W1-B2).
 *
 * The 90-day and one-year numbers are read from the daily rollup, and on a
 * monitor added six weeks ago "90 dní" covers six weeks. The server says
 * where the data starts (`since`) and where the window starts
 * (`windowStart`), both as server-local calendar days "Y-m-d"; the label then
 * reads "90 dní (data od 12. 8.)". Comparing the day strings needs no time
 * zone, so a browser in another zone cannot move the cut by a day.
 */

const DAY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * The first day with data when it falls after the window's start, else null
 * (the window is covered, or the server did not say - an older server gets
 * no note rather than a guessed one).
 */
export function coverageStart(since: unknown, windowStart: unknown): string | null {
  if (typeof since !== 'string' || typeof windowStart !== 'string') return null;
  if (!DAY.test(since) || !DAY.test(windowStart)) return null;
  return since > windowStart ? since : null;
}

/** "12. 8." in Czech, "12 Aug" in English; the year only when it is not this one. */
export function formatCoverageDay(day: string, lang: string, now = new Date()): string {
  const [y, m, d] = day.split('-').map(Number);
  const date = new Date(y, m - 1, d);
  return date.toLocaleDateString(lang === 'en' ? 'en-GB' : 'cs-CZ', {
    day: 'numeric',
    month: lang === 'en' ? 'short' : 'numeric',
    ...(y !== now.getFullYear() ? { year: 'numeric' } : {}),
  });
}

/**
 * The same cut for a chart: the first point's day when it is later than the
 * window's first day. A daily point is stamped at the server's midnight, so a
 * day of slack keeps a browser in another time zone from reporting a gap that
 * is not there.
 */
export function chartCoverageStart(
  firstPointSec: number | null | undefined,
  rangeDays: number,
  nowMs = Date.now()
): Date | null {
  if (firstPointSec == null || !Number.isFinite(firstPointSec)) return null;
  const start = new Date(nowMs);
  start.setHours(0, 0, 0, 0);
  start.setDate(start.getDate() - (rangeDays - 1));
  const first = new Date(firstPointSec * 1000);
  first.setHours(0, 0, 0, 0);
  return first.getTime() > start.getTime() + 86_400_000 ? first : null;
}
