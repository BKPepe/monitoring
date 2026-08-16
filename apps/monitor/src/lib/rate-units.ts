/**
 * Throughput units.
 *
 * Agents report network rates in KB/s, which is the wrong unit to read a link
 * in: a gigabit line peaks around 125 000 KB/s, and nobody recognises their
 * connection in that number. Line speeds are quoted in bits per second, so the
 * chart offers Mb/s and Gb/s as well - and keeps the raw KB/s available for
 * anyone comparing against the agent payload.
 *
 * Bit units are decimal on purpose (1 Mb/s = 1 000 000 bit/s), because that is
 * how every ISP and NIC vendor counts them. Byte units stay binary (1 MB =
 * 1024 KB), because that is how the kernel counters are read. Mixing the two
 * conventions silently is where "my 100 Mb/s line only does 11" comes from.
 */
export type RateUnit = 'KB/s' | 'MB/s' | 'Mb/s' | 'Gb/s';

export const RATE_UNITS: RateUnit[] = ['KB/s', 'MB/s', 'Mb/s', 'Gb/s'];

/** Multiplier applied to a value in KB/s to reach the target unit. */
const FROM_KBPS: Record<RateUnit, number> = {
  'KB/s': 1,
  'MB/s': 1 / 1024,
  // KB/s -> bits: * 1024 * 8, then / 1e6 for mega.
  'Mb/s': (1024 * 8) / 1e6,
  'Gb/s': (1024 * 8) / 1e9,
};

/** How many decimals make sense for the unit - Gb/s needs more than KB/s. */
const DECIMALS: Record<RateUnit, number> = {
  'KB/s': 1,
  'MB/s': 2,
  'Mb/s': 2,
  'Gb/s': 3,
};

/** Converts a KB/s reading. `null` stays `null` - never silently becomes 0. */
export function convertRate(kbps: number | null | undefined, unit: RateUnit): number | null {
  if (kbps === null || kbps === undefined || !Number.isFinite(kbps)) return null;
  const value = kbps * FROM_KBPS[unit];
  const factor = 10 ** DECIMALS[unit];
  return Math.round(value * factor) / factor;
}

/** Formatted value with its unit; unmeasured renders as a dash. */
export function formatRate(kbps: number | null | undefined, unit: RateUnit): string {
  const value = convertRate(kbps, unit);
  return value === null ? '—' : `${value} ${unit}`;
}

/**
 * The unit that shows the value most readably.
 *
 * Used as the initial choice so a router pushing 3 MB/s does not open on
 * "3000 KB/s", while a mostly idle link does not open on "0.002 Gb/s".
 */
export function suggestRateUnit(kbps: number | null | undefined): RateUnit {
  if (kbps === null || kbps === undefined || !Number.isFinite(kbps) || kbps <= 0) return 'KB/s';
  const mbits = kbps * FROM_KBPS['Mb/s'];
  if (mbits >= 1000) return 'Gb/s';
  if (mbits >= 1) return 'Mb/s';
  return 'KB/s';
}

/** Is this metric a data rate that the unit switch applies to? */
export function isRateMetric(unit: string | null | undefined): boolean {
  return unit === 'KB/s';
}
