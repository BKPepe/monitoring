/**
 * Geometry of a sparkline that can express a gap.
 *
 * A missing measurement is `null`, and a null ENDS the current line. Joining
 * the ends across it would draw a straight line through a collection outage,
 * which reads as "the value moved smoothly" - the one thing that certainly did
 * not happen (project rule: a gap in the data must look like a gap).
 *
 * Pure on purpose: the SVG in components/sparkline.tsx only formats what this
 * returns, so the rule itself is unit-tested.
 */
export interface SparkPoint {
  x: number;
  y: number;
}

function isMeasured(v: number | null | undefined): v is number {
  return typeof v === 'number' && Number.isFinite(v);
}

/**
 * @param data One entry per sample; null (or NaN/Infinity) = not measured.
 * @param width Viewport width the x coordinates are spread over.
 * @param height Viewport height; y is inverted (0 = top) as SVG expects.
 * @param pad Vertical breathing room so the extremes do not touch the edges.
 * @returns One array of points per run of measured samples. Runs shorter than
 *   two points are dropped - a single point is not a line, and drawing it as a
 *   dot would give one lucky sample the weight of a trend.
 */
export function sparklineSegments(
  data: (number | null | undefined)[],
  width: number,
  height: number,
  pad = 2
): SparkPoint[][] {
  const measured = data.filter(isMeasured);
  if (measured.length < 2) return [];

  const min = Math.min(...measured);
  const max = Math.max(...measured);
  // A constant series would otherwise divide by zero and vanish at an edge.
  const range = max - min || 1;
  const usable = Math.max(0, height - 2 * pad);
  const lastIndex = Math.max(1, data.length - 1);

  const x = (i: number) => (i / lastIndex) * width;
  const y = (v: number) => height - ((v - min) / range) * usable - pad;

  const segments: SparkPoint[][] = [];
  let current: SparkPoint[] = [];
  data.forEach((value, i) => {
    if (!isMeasured(value)) {
      if (current.length > 1) segments.push(current);
      current = [];
      return;
    }
    current.push({ x: x(i), y: y(value) });
  });
  if (current.length > 1) segments.push(current);
  return segments;
}
