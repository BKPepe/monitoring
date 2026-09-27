import type { MetricRange } from '@/api/types';

/**
 * The time window a chart stands for (C-5, charts-02).
 *
 * A time axis that fits the data hides the one thing an operator opens a
 * chart to see: an agent that went silent at five in the afternoon drew a
 * "24 h" chart ending at five, indistinguishable from a healthy one, and two
 * cards side by side covered ten minutes and a day under the same zoom. The
 * axis is pinned to the selected period instead, ending at the moment the data
 * was fetched.
 */
const PERIOD_MS: Record<MetricRange, number> = {
  '15m': 15 * 60_000,
  '1h': 3_600_000,
  '6h': 6 * 3_600_000,
  '24h': 86_400_000,
  '7d': 7 * 86_400_000,
  '30d': 30 * 86_400_000,
  '90d': 90 * 86_400_000,
  '1y': 365 * 86_400_000,
};

/** The window ending at `now` (the fetch time), as long as the period. */
export function windowFor(range: MetricRange, now: number): { from: number; to: number } {
  return { from: now - PERIOD_MS[range], to: now };
}
