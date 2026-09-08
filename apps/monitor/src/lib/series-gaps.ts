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
}

const DEFAULTS: Required<GapOptions> = { factor: 2.5, floorMs: 90_000 };

/** Median step between consecutive samples; null when there is nothing to measure. */
export function medianStep(points: MetricPoint[]): number | null {
  if (points.length < 2) return null;
  const steps: number[] = [];
  for (let i = 1; i < points.length; i++) {
    const step = points[i].t - points[i - 1].t;
    if (step > 0) steps.push(step);
  }
  if (steps.length === 0) return null;
  steps.sort((a, b) => a - b);
  const mid = Math.floor(steps.length / 2);
  return steps.length % 2 === 1 ? steps[mid] : (steps[mid - 1] + steps[mid]) / 2;
}

/**
 * @returns The same points with a `{ v: null }` marker inserted inside every
 *   hole longer than `factor` median steps. The original samples are never
 *   moved, dropped or altered - the only thing added says "nothing here".
 */
export function insertGaps(points: MetricPoint[], options: GapOptions = {}): MetricPoint[] {
  const { factor, floorMs } = { ...DEFAULTS, ...options };
  const step = medianStep(points);
  if (step == null) return points;

  const threshold = Math.max(step * factor, floorMs);
  const out: MetricPoint[] = [];
  for (let i = 0; i < points.length; i++) {
    const point = points[i];
    const prev = points[i - 1];
    if (prev && point.t - prev.t > threshold && prev.v != null && point.v != null) {
      // One step past the last real sample: the line ends where the data ends,
      // not halfway across the hole.
      out.push({ t: prev.t + step, v: null });
    }
    out.push(point);
  }
  return out;
}
