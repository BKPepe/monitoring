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
      return { kind: 'threshold_critical', tone: 'down', against: thresholds.critical };
    }
    if (thresholds.warning != null && current >= thresholds.warning) {
      return { kind: 'threshold_warning', tone: 'warning', against: thresholds.warning };
    }
    if (thresholds.critical != null || thresholds.warning != null) {
      return {
        kind: 'threshold_ok',
        tone: 'up',
        against: thresholds.warning ?? thresholds.critical,
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
  if (tail == null) return { kind: 'none', tone: 'neutral', against: null };
  const worseThanUsual = direction === 'higher' ? current <= tail : current >= tail;
  return worseThanUsual
    ? { kind: 'unusual', tone: 'warning', against: tail }
    : { kind: 'usual', tone: 'up', against: percentile(measured, 50) };
}
