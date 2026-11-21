import { betterDirection } from './metric-direction';

/**
 * How a metric moved against the same window one period earlier (C-3).
 *
 * Its predecessor compared the last quarter of the window with the first one,
 * which on a 24 h chart is evening against night: a router at 12 % CPU showed
 * a red "↑ 94 %" every day. It also divided by the signed earlier level, so a
 * dBm signal that got better was drawn as getting worse. This compares like
 * with like (the API answers `previous=1` with the window one period back),
 * speaks in percentage points for a percentage, and colours a change only
 * when it carried the value across one of the monitor's own limits - any
 * other change is information, not an alarm.
 */
export interface TrendDelta {
  /** Size of the change, always positive; `direction` carries the sign. */
  value: number;
  /** 'pp' = percentage points (the metric is itself a percentage), 'pct' = percent of the earlier level. */
  unit: 'pp' | 'pct';
  direction: 'up' | 'down';
  /** 'bad'/'good' only when a limit was crossed; everything else is drawn muted. */
  tone: 'good' | 'bad' | 'neutral';
}

type Samples = readonly (number | null | undefined)[];

/** Fewer measured samples than this and a mean is one lucky reading. */
const MIN_SAMPLES = 3;

/** Mean of the measured samples; null when too few were measured to mean anything. */
export function measuredMean(values: Samples): number | null {
  let sum = 0;
  let n = 0;
  for (const v of values) {
    if (typeof v === 'number' && Number.isFinite(v)) {
      sum += v;
      n++;
    }
  }
  return n >= MIN_SAMPLES ? sum / n : null;
}

export interface TrendInput {
  metricKey: string;
  unit: string;
  current: Samples;
  /** The previous window's samples, or its mean when the server already computed it. */
  previous: Samples | number | null | undefined;
  /** The monitor's own limits; without them no change is ever coloured. */
  thresholds?: { warning: number | null; critical: number | null };
}

export function trendDelta({ metricKey, unit, current, previous, thresholds }: TrendInput): TrendDelta | null {
  const after = measuredMean(current);
  const before =
    typeof previous === 'number' ? (Number.isFinite(previous) ? previous : null) : measuredMean(previous ?? []);
  if (after == null || before == null) return null;

  let value: number;
  let kind: TrendDelta['unit'];
  if (unit === '%') {
    kind = 'pp';
    const diff = Math.abs(after - before);
    value = diff < 10 ? Math.round(diff * 10) / 10 : Math.round(diff);
  } else {
    // Growth from about zero has no meaningful percentage ("+9000 %"), the
    // same rule as trend_pct in functions.php.
    if (Math.abs(before) < 0.01) return null;
    kind = 'pct';
    // The absolute earlier level: -98 dBm -> -90 dBm is a rise of 8 %, not a fall.
    value = Math.round((Math.abs(after - before) / Math.abs(before)) * 100);
  }
  // A change that rounds to nothing is no change; "↑ 0 %" would be noise.
  if (value === 0) return null;

  const direction: TrendDelta['direction'] = after > before ? 'up' : 'down';
  return { value, unit: kind, direction, tone: crossing(metricKey, before, after, thresholds) };
}

/** 0 = inside the limits, 1 = past the warning limit, 2 = past the critical one. */
function level(v: number, better: 'higher' | 'lower', warning: number | null, critical: number | null): number {
  if (better === 'lower') {
    if (critical != null && v >= critical) return 2;
    if (warning != null && v >= warning) return 1;
    return 0;
  }
  if (critical != null && v <= critical) return 2;
  if (warning != null && v <= warning) return 1;
  return 0;
}

function crossing(
  metricKey: string,
  before: number,
  after: number,
  thresholds: TrendInput['thresholds']
): TrendDelta['tone'] {
  const better = betterDirection(metricKey);
  // Traffic and headcounts have no bad end; a limit on them still does not
  // make "more" a fault.
  if (better === 'neutral' || !thresholds) return 'neutral';
  const { warning, critical } = thresholds;
  if (warning == null && critical == null) return 'neutral';
  const from = level(before, better, warning, critical);
  const to = level(after, better, warning, critical);
  if (to > from) return 'bad';
  if (to < from) return 'good';
  return 'neutral';
}
