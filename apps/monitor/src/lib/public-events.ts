import type { TimelineEvent } from '@/data/model';

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** What `action=events` says about one check. */
export interface OutageFacts {
  isDown: boolean;
  /**
   * The first passing check of the same monitor after this failure, as the
   * server found it in the log; null = none recorded (yet).
   */
  outageEnd: string | null;
  /** From this failure to `outageEnd`; null whenever the end is. */
  outageDurationSec: number | null;
}

/**
 * Whether the outage a failed check belongs to is over.
 *
 * Only data decides. A recorded end means it ended. Without one, the monitor's
 * status right now settles it: down = it may still be running, up = it ended
 * at a time nobody recorded (an answer older than the fix, or the two requests
 * straddling a recovery). Anything else - degraded, or a list that has not
 * loaded - claims nothing. Every failed check used to be "Open", which put a
 * week of long-recovered single failures under "all systems are online".
 */
export function outageResolution(e: OutageFacts, currentStatus: string | null): TimelineEvent['resolution'] {
  if (!e.isDown) return 'Info';
  if (e.outageEnd) return 'Resolved';
  if (currentStatus === 'down') return 'Open';
  if (currentStatus === 'up') return 'Resolved';
  return undefined;
}

/**
 * " (trvání 5 min)", only for an outage whose end was recorded: a duration
 * without an end is a number from nowhere. Under a minute reads "< 1 min" -
 * rounding it printed "0 min", a failure rounded away.
 */
export function outageDurationText(e: OutageFacts, t: TranslateFn): string {
  const sec = e.outageDurationSec;
  if (!e.outageEnd || typeof sec !== 'number' || !Number.isFinite(sec) || sec <= 0) return '';
  const min = sec < 60 ? '< 1' : Math.round(sec / 60);
  return t('public.event_duration', { min }, ` (trvání ${min} min)`);
}
