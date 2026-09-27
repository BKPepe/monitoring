import type { DayUptime, UptimeHistoryRow } from '@/data/model';
import { pluralForm } from '@/lib/plural';
import { monitorStatusKey, type StatusKey } from '@/lib/status';

/**
 * The dashboard's first screen as data (W2-1): how many monitors are in each
 * state, the one sentence that says it above the health ring, and which of
 * the 30-day availability rows are worth a look (charts-16).
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

export type StatusCounts = Partial<Record<StatusKey, number>>;

export function statusCounts(
  monitors: readonly {
    status: string;
    statusKey?: string | null;
    lastCheck?: string | null;
    agentLastSeen?: number | null;
  }[]
): StatusCounts {
  return monitors.reduce<StatusCounts>((acc, m) => {
    const key = monitorStatusKey(m);
    acc[key] = (acc[key] ?? 0) + 1;
    return acc;
  }, {});
}

/** The states the sentence names, worst first; the others are not news. */
const NAMED: readonly StatusKey[] = ['down', 'unknown_stale', 'warning', 'maintenance'];

/** The worst state the sentence names, for its icon and colour; null = nothing to name. */
export function worstNamedState(counts: StatusCounts): StatusKey | null {
  return NAMED.find((key) => (counts[key] ?? 0) > 0) ?? null;
}

/**
 * "1 výpadek, 2 varování" - the problems only, worst first, each through the
 * plural rules. Healthy, paused and brand-new monitors are left out: listing
 * them would bury the one word that matters.
 *
 * Without a problem the sentence says exactly that and no more - not "all
 * systems operational" while a paused monitor sits in the list below.
 */
export function verdictSentence(counts: StatusCounts, lang: string, t: TranslateFn): string {
  const parts: string[] = [];
  const down = counts.down ?? 0;
  const stale = counts.unknown_stale ?? 0;
  const warning = counts.warning ?? 0;
  const maintenance = counts.maintenance ?? 0;

  if (down > 0) {
    const form = pluralForm(lang, down);
    parts.push(
      form === 'one'
        ? t('dashboard.verdict_down_one', { n: down }, `${down} výpadek`)
        : form === 'few'
          ? t('dashboard.verdict_down_few', { n: down }, `${down} výpadky`)
          : t('dashboard.verdict_down_other', { n: down }, `${down} výpadků`)
    );
  }
  if (stale > 0) {
    const form = pluralForm(lang, stale);
    parts.push(
      form === 'one'
        ? t('dashboard.verdict_stale_one', { n: stale }, `${stale} agent mlčí`)
        : form === 'few'
          ? t('dashboard.verdict_stale_few', { n: stale }, `${stale} agenti mlčí`)
          : t('dashboard.verdict_stale_other', { n: stale }, `${stale} agentů mlčí`)
    );
  }
  if (warning > 0) {
    const form = pluralForm(lang, warning);
    parts.push(
      form === 'one'
        ? t('dashboard.verdict_warning_one', { n: warning }, `${warning} varování`)
        : t('dashboard.verdict_warning_other', { n: warning }, `${warning} varování`)
    );
  }
  if (maintenance > 0) {
    parts.push(t('dashboard.verdict_maintenance', { n: maintenance }, `${maintenance} v údržbě`));
  }

  return parts.length > 0 ? parts.join(', ') : t('dashboard.verdict_clear', 'Žádný výpadek ani varování');
}

/**
 * How bad a 30-day row is: outage days first, then degraded days, then days
 * nobody measured. Anything that is not a known good or bad day counts as
 * unmeasured - the same rule DayStrip draws by, so an older server's 'paused'
 * never passes for an up day. Not imported from the strip: that would pull
 * the whole strip onto the dashboard's first load for one comparison.
 */
function rowWeight(days: readonly DayUptime[]) {
  let down = 0;
  let warning = 0;
  let unmeasured = 0;
  let lowest = 100;
  for (const day of days) {
    if (day.status === 'down') down++;
    else if (day.status === 'warning') warning++;
    else if (day.status !== 'up' && day.status !== 'maintenance') unmeasured++;
    if (day.uptimePct != null && day.uptimePct < lowest) lowest = day.uptimePct;
  }
  return { down, warning, unmeasured, lowest };
}

/**
 * A row with nothing to say: every day up (or in planned maintenance). Only
 * such a row may be folded into "N dalších: 30 dní bez výpadku" - a day
 * nobody measured is not a day without an outage, so a new or silent monitor
 * stays on the list.
 */
export function isCleanRow(row: UptimeHistoryRow): boolean {
  return row.days.length > 0 && row.days.every((d) => d.status === 'up' || d.status === 'maintenance');
}

export interface HistorySplit {
  /** The rows with a day worth a look, worst first. */
  shown: UptimeHistoryRow[];
  /** Rows with such a day that did not fit; they are in the SLA report. */
  moreProblems: number;
  /** Rows where every day was up - one line instead of a row each. */
  clean: number;
}

/**
 * The history card showed the first six monitors by id (charts-16): a clean
 * website could fill it while the device that failed yesterday was row nine.
 * Now only rows with a bad or unmeasured day are drawn, the worst first.
 */
export function splitHistory(rows: readonly UptimeHistoryRow[], limit = 10): HistorySplit {
  const problems = rows
    .filter((row) => !isCleanRow(row))
    .map((row) => ({ row, w: rowWeight(row.days) }))
    .sort(
      (a, b) =>
        b.w.down - a.w.down ||
        b.w.warning - a.w.warning ||
        a.w.lowest - b.w.lowest ||
        b.w.unmeasured - a.w.unmeasured ||
        a.row.name.localeCompare(b.row.name)
    )
    .map(({ row }) => row);
  return {
    shown: problems.slice(0, limit),
    moreProblems: Math.max(0, problems.length - limit),
    clean: rows.length - problems.length,
  };
}
