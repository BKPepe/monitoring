import { betterDirection, type BetterDirection } from './metric-direction';
import { percentile } from './percentiles';

/**
 * Answering "is this number good?" without inventing a standard.
 *
 * There are only three honest yardsticks, in this order:
 *   1. A threshold somebody configured for this monitor. Named, so the reader
 *      can check it.
 *   2. The metric's own physical scale, where one exists (radio signal).
 *      Handled by lib/signal-quality.ts, not here.
 *   3. The window itself: is the current value where this metric usually sits,
 *      or out at the bad end? That is a comparison, not a verdict about the
 *      world, and it is phrased as one.
 *
 * When none applies, the answer is "no yardstick" - not a green tick.
 */
export type VerdictKind = 'threshold_ok' | 'threshold_warning' | 'threshold_critical' | 'usual' | 'unusual' | 'none';

export interface MetricVerdict {
  kind: VerdictKind;
  tone: 'up' | 'warning' | 'down' | 'neutral';
  /** The number the verdict was made against, when there is one. */
  against: number | null;
  /**
   * The limit an administrator actually configured, when a threshold decided
   * it. The warning band is derived from it (15 points below), so a sentence
   * naming "the configured threshold" must not print the derived number.
   */
  configured?: number | null;
}

export interface VerdictInput {
  metricKey: string;
  current: number | null;
  values: number[];
  thresholds: { warning: number | null; critical: number | null };
}

/**
 * @returns null when there is nothing to compare against at all (no current
 *   value). Everything else returns a verdict, including the explicit "no
 *   yardstick" one.
 */
export function metricVerdict({ metricKey, current, values, thresholds }: VerdictInput): MetricVerdict | null {
  if (current == null || !Number.isFinite(current)) return null;
  const direction: BetterDirection = betterDirection(metricKey);

  // A configured threshold always wins: somebody decided what matters here.
  // Thresholds are set as ceilings, so they only apply where more is worse.
  if (direction !== 'higher') {
    if (thresholds.critical != null && current >= thresholds.critical) {
      return {
        kind: 'threshold_critical',
        tone: 'down',
        against: thresholds.critical,
        configured: thresholds.critical,
      };
    }
    if (thresholds.warning != null && current >= thresholds.warning) {
      return {
        kind: 'threshold_warning',
        tone: 'warning',
        against: thresholds.warning,
        configured: thresholds.critical,
      };
    }
    if (thresholds.critical != null || thresholds.warning != null) {
      return {
        kind: 'threshold_ok',
        tone: 'up',
        against: thresholds.warning ?? thresholds.critical,
        configured: thresholds.critical,
      };
    }
  }

  const measured = values.filter((v) => Number.isFinite(v));
  if (measured.length < 8 || direction === 'neutral') {
    return { kind: 'none', tone: 'neutral', against: null };
  }

  // Out at the bad end of its own window - which end that is depends on the
  // metric: for latency the high tail, for signal strength the low one.
  const tail = direction === 'higher' ? percentile(measured, 5) : percentile(measured, 95);
  const other = direction === 'higher' ? percentile(measured, 95) : percentile(measured, 5);
  if (tail == null) return { kind: 'none', tone: 'neutral', against: null };
  // A window that never moved has no "worse end" to be at. Comparing against
  // it made the healthiest possible reading - swap at 0, no zombies, no steal
  // - a permanent amber warning, because a nearest-rank percentile of a
  // constant series is that same constant.
  if (other === tail) return { kind: 'none', tone: 'neutral', against: null };
  // Strictly past the tail: equalling it is being AT the edge of normal, not
  // beyond it.
  const worseThanUsual = direction === 'higher' ? current < tail : current > tail;
  return worseThanUsual
    ? { kind: 'unusual', tone: 'warning', against: tail }
    : { kind: 'usual', tone: 'up', against: percentile(measured, 50) };
}
