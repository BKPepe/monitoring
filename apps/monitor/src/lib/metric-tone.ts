import type { MetricTone } from '@/api/types';

/**
 * The one metric -> series hue map (C-2).
 *
 * The overview, the metric detail, the sparklines and the public page each
 * picked a hue on their own: swap was orange on the overview and blue on the
 * detail, LTE traffic was "temperature" orange, and sixty other series were
 * all latency brown. A metric has to look the same wherever it is drawn, so
 * every caller asks here.
 *
 * The hues themselves live in styles/theme.css, where theme-contrast.test
 * pins that no series hue reuses a status colour - a line in a series hue
 * must never read as a verdict. An alert marker still uses --status-down.
 */
const EXACT: Readonly<Record<string, MetricTone>> = {
  response_time: 'latency',
  wan_latency_ms: 'latency',
  dns_latency_ms: 'latency',
  agent_run_ms: 'latency',
  // People and devices online: one hue for every headcount.
  ts_clients: 'memory',
  discord_presence: 'memory',
  mc_players: 'memory',
  // The backup link carries the same kind of traffic as the primary one; a
  // chart that shows both tells them apart with distinctTones().
  net_lte: 'network',
};

/** First match wins, so the longer prefixes that must not fall through sit first. */
const PREFIXES: readonly (readonly [string, MetricTone])[] = [
  ['wifi_clients', 'memory'],
  ['cpu', 'cpu'],
  ['load', 'cpu'],
  ['iowait', 'cpu'],
  ['fork_rate', 'cpu'],
  ['zombie', 'cpu'],
  ['ts_process_cpu', 'cpu'],
  ['ram', 'memory'],
  ['swap', 'memory'],
  ['ts_process_ram', 'memory'],
  ['oom', 'memory'],
  ['hdd', 'disk'],
  ['disk', 'disk'],
  ['inode', 'disk'],
  ['btrfs', 'disk'],
  ['temp', 'temperature'],
  ['net', 'network'],
  ['wan_rx', 'network'],
  ['wan_tx', 'network'],
  ['tcp', 'network'],
  ['dns', 'network'],
  ['fw_', 'network'],
  ['conntrack', 'network'],
  ['sqm', 'network'],
];

/** The series hue of a metric key; signal, Wi-Fi radio and anything unknown share the latency hue. */
export function metricTone(metricKey: string): MetricTone {
  const exact = EXACT[metricKey];
  if (exact) return exact;
  for (const [prefix, tone] of PREFIXES) {
    if (metricKey.startsWith(prefix)) return tone;
  }
  return 'latency';
}

/** Fallback order when two series of one chart would share a hue. */
const ORDER: readonly MetricTone[] = ['network', 'memory', 'disk', 'temperature', 'cpu', 'latency'];

/**
 * Hues for the series of ONE chart: each keeps its own hue unless an earlier
 * series already took it, in which case it gets the first free one.
 *
 * WAN and LTE traffic are both "network"; stacked in one chart they would be
 * two bands of the same teal, which is one band to the eye.
 */
export function distinctTones(tones: readonly MetricTone[]): MetricTone[] {
  const used = new Set<MetricTone>();
  return tones.map((tone) => {
    const pick = used.has(tone) ? (ORDER.find((t) => !used.has(t)) ?? tone) : tone;
    used.add(pick);
    return pick;
  });
}
