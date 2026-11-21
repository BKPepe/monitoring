import { insertGaps } from './series-gaps';

/**
 * Geometry of a sparkline (C-4).
 *
 * Three rules, each one a bug the earlier index-based version had:
 * - x is TIME, not the sample index. Six hours with a two-hour outage in the
 *   middle drew as a line with a one-sample notch; now the hole is as wide as
 *   the outage, and a window that ends in silence ends in empty space.
 * - A missing measurement ends the line. Joining across it draws a smooth
 *   change nobody measured (project rule: a gap must look like a gap). Long
 *   steps between samples count as gaps too, by the same cadence rule the big
 *   charts use (lib/series-gaps.ts).
 * - The y range has a floor. Scaling every row to its own min and max turned
 *   12.4-12.6 % CPU into a full-height barcode; a series that barely moves now
 *   draws as a line that barely moves.
 *
 * Pure on purpose: components/sparkline.tsx only formats what this returns.
 */
export interface SparkPoint {
  x: number;
  y: number;
}

export interface SparkSample {
  /** Timestamp in ms. */
  t: number;
  v: number | null | undefined;
}

export interface SparkOptions {
  /** The period the sparkline stands for; defaults to the first..last sample. */
  window?: { from: number; to: number } | null;
  /**
   * The smallest y span drawn, in the metric's own unit. Defaults to a fifth
   * of the series' magnitude, so noise stays small against the level.
   */
  minRange?: number;
  /** Vertical breathing room so the extremes do not touch the edges. */
  pad?: number;
}

export interface SparkGeometry {
  /** One polyline per run of measured samples (a run needs two points). */
  segments: SparkPoint[][];
  /** First and last measured value inside the window, for the edge labels. */
  first: number | null;
  last: number | null;
}

function isMeasured(v: number | null | undefined): v is number {
  return typeof v === 'number' && Number.isFinite(v);
}

/** Default y floor: 20 % of the level, never zero (a flat zero series still needs a scale). */
export function defaultMinRange(values: readonly number[]): number {
  if (values.length === 0) return 1;
  const magnitude = Math.max(...values.map(Math.abs));
  return magnitude > 0 ? magnitude * 0.2 : 1;
}

export function sparklineGeometry(
  samples: readonly SparkSample[],
  width: number,
  height: number,
  options: SparkOptions = {}
): SparkGeometry {
  const empty: SparkGeometry = { segments: [], first: null, last: null };
  const { window, pad = 2 } = options;

  const inWindow = samples
    .filter((s) => Number.isFinite(s.t) && (!window || (s.t >= window.from && s.t <= window.to)))
    .map((s) => ({ t: s.t, v: isMeasured(s.v) ? s.v : null }))
    .sort((a, b) => a.t - b.t);
  const measured = inWindow.filter((s) => s.v != null).map((s) => s.v as number);
  if (measured.length < 2) return empty;

  const from = window ? window.from : inWindow[0].t;
  const to = window ? window.to : inWindow[inWindow.length - 1].t;
  const span = to - from;
  if (!(span > 0)) return empty;

  let min = Math.min(...measured);
  let max = Math.max(...measured);
  const floor = options.minRange ?? defaultMinRange(measured);
  if (max - min < floor) {
    const mid = (min + max) / 2;
    min = mid - floor / 2;
    max = mid + floor / 2;
  }
  const usable = Math.max(0, height - 2 * pad);
  const x = (t: number) => ((t - from) / span) * width;
  const y = (v: number) => height - pad - ((v - min) / (max - min)) * usable;

  const segments: SparkPoint[][] = [];
  let current: SparkPoint[] = [];
  for (const s of insertGaps(inWindow)) {
    if (s.v == null) {
      if (current.length > 1) segments.push(current);
      current = [];
      continue;
    }
    current.push({ x: x(s.t), y: y(s.v) });
  }
  if (current.length > 1) segments.push(current);

  return { segments, first: measured[0], last: measured[measured.length - 1] };
}
