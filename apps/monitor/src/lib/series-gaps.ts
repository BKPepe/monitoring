import type { MetricPoint } from '@/api/types';

/**
 * Makes a hole in the data visible as a hole.
 *
 * Both metric endpoints select only rows that HAVE a value (`AND <col> IS NOT
 * NULL`), so a three-hour agent outage arrives as two neighbouring points and
 * the chart draws one straight line across it - a measurement nobody made.
 * This inserts an explicit `null` sample into every suspicious hole, which is
 * what `connectNulls: false` needs in order to break the line.
 *
 * The expected cadence is taken from the data itself (the median step), so it
 * works for a per-minute check and for a daily rollup alike, and a chart is
 * never torn by ordinary scheduler jitter.
 */
export interface GapOptions {
  /** How many median steps a hole must exceed to count as a gap. */
  factor?: number;
  /** No gap is ever declared below this many milliseconds. */
  floorMs?: number;
  /** How many steps on each side define the local cadence. */
  localWindow?: number;
}

const DEFAULTS: Required<GapOptions> = { factor: 2.5, floorMs: 90_000, localWindow: 5 };

/** Median step between consecutive samples; null when there is nothing to measure. */
export function medianStep(points: MetricPoint[]): number | null {
  if (points.length < 2) return null;
  const steps: number[] = [];
  for (let i = 1; i < points.length; i++) {
    const step = points[i].t - points[i - 1].t;
    if (step > 0) steps.push(step);
  }
  return medianOf(steps);
}

/**
 * @returns The same points with a `{ v: null }` marker inserted inside every
 *   hole longer than `factor` median steps. The original samples are never
 *   moved, dropped or altered - the only thing added says "nothing here".
 */
export function insertGaps(points: MetricPoint[], options: GapOptions = {}): MetricPoint[] {
  const { factor, floorMs, localWindow } = { ...DEFAULTS, ...options };
  const steps: number[] = [];
  for (let i = 1; i < points.length; i++) {
    steps.push(points[i].t - points[i - 1].t);
  }
  const global = medianStep(points);
  if (global == null) return points;

  const out: MetricPoint[] = [];
  for (let i = 0; i < points.length; i++) {
    const point = points[i];
    const prev = points[i - 1];
    if (prev && prev.v != null && point.v != null) {
      // The cadence is measured LOCALLY: when the schedule changes inside the
      // window - the cron was retimed, the agent's interval edited - a single
      // global median makes every ordinary step of the slower era look like an
      // outage and shreds the chart into hundreds of false breaks.
      const local = medianOf(neighbourSteps(steps, i - 1, localWindow)) ?? global;
      const threshold = Math.max(local * factor, floorMs);
      if (point.t - prev.t > threshold) {
        out.push({ t: prev.t + local, v: null });
      }
    }
    out.push(point);
  }
  return out;
}

/** Steps around index `at`, used to judge one hole against its own era. */
function neighbourSteps(steps: number[], at: number, window: number): number[] {
  const from = Math.max(0, at - window);
  const to = Math.min(steps.length, at + window + 1);
  return steps.slice(from, to).filter((s) => s > 0);
}

function medianOf(values: number[]): number | null {
  if (values.length === 0) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 1 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}
