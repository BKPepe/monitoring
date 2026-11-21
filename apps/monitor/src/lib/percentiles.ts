/**
 * Percentiles over measured values.
 *
 * An average plus a maximum is the least useful pair for latency: one 8-second
 * timeout owns the maximum and the average hides the tail. p50/p95/p99 answer
 * "what does this normally feel like, and how bad is the bad end".
 *
 * Nearest-rank on the sorted sample (no interpolation), so every number
 * returned is a value that was actually measured.
 */
export function percentile(values: number[], p: number): number | null {
  const measured = values.filter((v) => typeof v === 'number' && Number.isFinite(v));
  if (measured.length === 0) return null;
  if (p <= 0) return Math.min(...measured);
  if (p >= 100) return Math.max(...measured);
  const sorted = [...measured].sort((a, b) => a - b);
  const rank = Math.ceil((p / 100) * sorted.length);
  return sorted[Math.min(sorted.length, Math.max(1, rank)) - 1];
}
