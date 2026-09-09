/**
 * Which end of a metric is the bad end.
 *
 * Percentiles only mean something once this is known. For latency the tail
 * that matters is p95 - the slow end. For signal strength in dBm it is the
 * exact opposite: p95 is the BEST five percent and the number that hurts is
 * p5. Labelling both "the worse end" turns a useful statistic into a lie, and
 * that is what the LTE signal detail did.
 */
export type BetterDirection = 'higher' | 'lower' | 'neutral';

/** More is better: signal, free memory, battery, available randomness. */
const HIGHER_IS_BETTER = new Set([
  'lte_rsrp',
  'lte_rsrq',
  'lte_sinr',
  'lte_rssi',
  'lte_uptime',
  'entropy',
  'ups_battery_pct',
  'ram_free_mb',
]);

/** Less is better: load, latency, saturation, errors. */
const LOWER_IS_BETTER = new Set([
  'response_time',
  'cpu',
  'cpu_steal',
  'ram',
  'swap',
  'hdd',
  'inode_usage',
  'iowait',
  'load1',
  'load5',
  'load15',
  'temperature_c',
  'conntrack',
  'conntrack_count',
  'tcp_retrans',
  'net_errors',
  'wan_latency_ms',
  'dns_latency_ms',
  'fw_dropped',
  'zombie_count',
  'ts_process_cpu',
  'ts_process_ram',
]);

/**
 * @returns 'neutral' for everything whose direction is a matter of context -
 *   traffic, connected clients, players. More of those is not by itself good
 *   or bad, and pretending otherwise would colour a busy evening as a fault.
 */
export function betterDirection(metricKey: string): BetterDirection {
  if (HIGHER_IS_BETTER.has(metricKey)) return 'higher';
  if (LOWER_IS_BETTER.has(metricKey)) return 'lower';
  return 'neutral';
}
