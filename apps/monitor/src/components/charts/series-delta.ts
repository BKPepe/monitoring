import type { MetricSeries, MetricTone } from '@/api/types';

/**
 * Metric trend in the shown window - comparing the average of the last quarter
 * of points with the average of the first (dashboard mockup: "23 % ↓ 5 %").
 *
 * Quarter averages instead of first/last values: a single noisy point would
 * manufacture false trends otherwise. Computed only from actually measured points;
 * with too little data it returns null and the UI just omits the delta - no invented
 * "stable 0 %".
 */
export function computeSeriesDelta(series: MetricSeries | undefined): {
  pct: number;
  direction: 'up' | 'down';
} | null {
  const values = (series?.points ?? []).map((p) => p.v).filter((v): v is number => v != null);
  if (values.length < 8) return null;

  const quarter = Math.max(2, Math.floor(values.length / 4));
  const head = values.slice(0, quarter);
  const tail = values.slice(-quarter);
  const avg = (xs: number[]) => xs.reduce((s, v) => s + v, 0) / xs.length;
  const before = avg(head);
  const after = avg(tail);

  // Growth from ~zero has no meaningful percentage (same reasoning as trend_pct
  // in functions.php) - better no delta than "+9000 %".
  if (Math.abs(before) < 0.01) return null;

  const pct = Math.round(((after - before) / before) * 100);
  if (pct === 0) return null;
  return { pct: Math.abs(pct), direction: pct > 0 ? 'up' : 'down' };
}

/**
 * For which metrics a drop is good news. Network and client counts are
 * neutral (more traffic is not bad by itself) - null = the delta renders
 * without judgmental colouring.
 */
export function goodDirectionFor(tone: MetricTone | undefined): 'up' | 'down' | null {
  switch (tone) {
    case 'cpu':
    case 'memory':
    case 'disk':
    case 'latency':
    case 'temperature':
      return 'down';
    default:
      return null;
  }
}
