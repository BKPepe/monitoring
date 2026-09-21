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

/**
 * G41: whether the router's own resolver answered the last lookup.
 *
 * Before 0.1.7 a dead resolver was charted as an excellent latency, so the
 * row says what was measured and nothing more. The key is missing on
 * everything that is not a router - then there is no row at all - while null
 * means the router could not measure it (no `nslookup`), which is an em dash.
 */
export function dnsResolverText(d: Record<string, unknown>, t: TranslateFn): string | null {
  if (!('dns_resolver_ok' in d)) return null;
  if (d.dns_resolver_ok === true) return t('net.dns_resolver_answers', 'Odpovídá');
  if (d.dns_resolver_ok === false) return t('net.dns_resolver_silent', 'Neodpovídá');
  return null;
}

/** Milliseconds as the reader would say them: `870 ms`, `9,5 s`. */
export function runDuration(ms: unknown, t: TranslateFn): string | null {
  const n = num(ms);
  if (n === null || n < 0) return null;
  if (n < 1000) return t('net.agent_run_ms_unit', { ms: Math.round(n) }, `${Math.round(n)} ms`);
  return t('net.agent_run_s_unit', { s: (n / 1000).toFixed(1) }, `${(n / 1000).toFixed(1)} s`);
}

/**
 * G42: how long the last minute run took, and how long the one before it took
 * INCLUDING its POST - the send is what usually pushes a run past the minute.
 */
export function agentRunText(d: Record<string, unknown>, t: TranslateFn): string | null {
  const run = runDuration(d.agent_run_ms, t);
  if (run === null) return null;
  const prev = runDuration(d.agent_prev_total_ms, t);
  return prev === null ? run : `${run} (${t('net.agent_prev_total', { prev }, `předchozí běh i s odesláním ${prev}`)})`;
}

/**
 * G42: runs the agent skipped, by reason. Zero is a measurement and is shown
 * as one; only a router that reports neither counter has no row.
 */
export function agentSkippedText(d: Record<string, unknown>, t: TranslateFn): string | null {
  const lock = whole(d.runs_skipped_lock);
  const post = whole(d.runs_skipped_post);
  if (lock === null && post === null) return null;
  const parts: string[] = [];
  if (lock !== null) parts.push(t('net.agent_skipped_lock', { n: lock }, `${lock}× předchozí běh ještě běžel`));
  if (post !== null) parts.push(t('net.agent_skipped_post', { n: post }, `${post}× se nepodařilo odeslat`));
  return parts.join(' · ');
}

/**
 * G42: reports that arrived in the last 24 h against the minutes the router
 * was up. The server counts it hourly; a share below 90 % is also a collection
 * issue, so this row is the quiet version of the same number.
 */
export function reportsReceivedText(d: Record<string, unknown>, t: TranslateFn): string | null {
  const raw = d.reports_24h;
  if (raw === null || typeof raw !== 'object') return null;
  const box = raw as Record<string, unknown>;
  const received = whole(box.received);
  const expected = whole(box.expected);
  if (received === null || expected === null || expected === 0) return null;
  const pct = Math.round((received / expected) * 100);
  return t('net.agent_reports_value', { received, expected, pct }, `${received} z ${expected} minut (${pct} %)`);
}
