/**
 * Numbers of the router overview that are NOT mapped metrics: the SoC
 * temperature tile, the busiest-core hint on the CPU tile, the DNS resolver
 * row and the agent's own run time (gap items G22, G41, G42).
 *
 * They live in `last_details`, so the page reads them by key. Each function
 * answers null when the router did not report the value - the rows print an
 * em dash for that, never a zero and never a claim (G24).
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

const num = (value: unknown): number | null => {
  const n = typeof value === 'number' ? value : typeof value === 'string' ? Number(value) : NaN;
  return Number.isFinite(n) ? n : null;
};

const whole = (value: unknown): number | null => {
  const n = num(value);
  return n !== null && Number.isInteger(n) && n >= 0 ? n : null;
};

/**
 * G22: the SoC temperature of the KPI tile.
 *
 * `last_details` stores it under `temperature` (agent_api.php), while
 * `temperature_c` is the name of the `vps_metrics` COLUMN. The tile read the
 * column name for years, so it never rendered although the value was
 * collected, stored and charted. Both are accepted here, the payload key
 * first, so an older stored detail blob cannot bring the tile back to silence.
 */
export function socTemperatureC(d: Record<string, unknown>): number | null {
  return num(d.temperature) ?? num(d.temperature_c);
}

/**
 * The hint under the CPU tile: which core was busy while the average looked calm.
 *
 * The same 25-point rule as `coreHiddenByAverage()` in lib/wan-verdict.ts - on
 * a two-core router an average of 52 % and a core at 98 % are the same minute,
 * and only the wide gap is worth a line. Without the average there is nothing
 * to correct, so the hint stays away.
 */
export function busiestCoreHint(d: Record<string, unknown>, cpu: number | null, t: TranslateFn): string | null {
  const core = num(d.cpu_core_max_pct);
  if (core === null || cpu === null) return null;
  if (core - cpu < 25) return null;
  const pct = Math.round(core);
  const index = whole(d.cpu_core_max_index);
  return index === null
    ? t('asset.cpu_core_hint_anon', { pct }, `nejvytíženější jádro: ${pct} %`)
    : t('asset.cpu_core_hint', { core: index, pct }, `nejvytíženější jádro ${index}: ${pct} %`);
}
