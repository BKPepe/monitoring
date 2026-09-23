/**
 * Measurement locations (`action=regions`): where the checks come FROM.
 *
 * The incidents page used to list monitors of type node under "Probing
 * Infrastructure" and filled a missing latency with a made-up 12 ms. The real
 * places live in monitor_logs.checked_from, which is what `regions` groups by.
 */

/** One row of `action=regions`. The public projection sends only location and successRate. */
export interface ProbeRegion {
  location: string | null;
  checks?: number;
  successRate: number | null;
  avgResponseMs?: number | null;
  monitors?: number;
  firstSeen?: string | null;
  lastSeen?: string | null;
}

/**
 * Every scheduled location runs every five minutes: cron.php (1-5 min), the
 * Cloudflare worker (*\/5) and the GitHub workflow (*\/5).
 */
const PROBE_INTERVAL_MS = 5 * 60_000;

/** Silent for longer than two intervals = one missed run is noise, two are a pattern. */
export const PROBE_STALE_AFTER_MS = 2 * PROBE_INTERVAL_MS;

/**
 * A server datetime as epoch ms.
 *
 * MySQL sends `lastSeen` as 'YYYY-MM-DD HH:MM:SS' in the server's zone with no
 * offset. `cachedAt` is PHP's date('c') and carries that offset, so it is
 * borrowed; without one the browser's own zone is the only guess left.
 */
export function serverTimeMs(value: string | null | undefined, offsetSource?: string | null): number | null {
  if (!value) return null;
  const iso = value.trim().replace(' ', 'T');
  const hasZone = /(?:Z|[+-]\d{2}:?\d{2})$/.test(iso);
  const offset = hasZone ? '' : (offsetSource?.match(/(Z|[+-]\d{2}:\d{2})$/)?.[1] ?? '');
  const ms = Date.parse(iso + offset);
  return Number.isNaN(ms) ? null : ms;
}

export interface LocationFreshness {
  /** Minutes since the last result, as of when the answer arrived; null = unknown. */
  ageMin: number | null;
  /** Silent past PROBE_STALE_AFTER_MS at the moment the server built the answer; null = unknown. */
  stale: boolean | null;
}

/**
 * How fresh a location's last result is.
 *
 * Staleness is judged against the moment the server built the answer (the
 * regions answer is cached for up to ten minutes), so a cached answer cannot
 * make a location that was fine at the time look silent.
 */
export function locationFreshness(
  region: ProbeRegion,
  cachedAt: string | null,
  receivedAtMs: number
): LocationFreshness {
  const last = serverTimeMs(region.lastSeen, cachedAt);
  if (last === null) return { ageMin: null, stale: null };
  const snapshot = serverTimeMs(cachedAt) ?? receivedAtMs;
  return {
    ageMin: Math.max(0, Math.round((receivedAtMs - last) / 60_000)),
    stale: snapshot - last > PROBE_STALE_AFTER_MS,
  };
}
