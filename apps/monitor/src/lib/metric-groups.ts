import type { ChartData } from '@/api/types';

/**
 * The device's other metrics by subsystem (C-13).
 *
 * "Další měřené metriky (63)" was one flat grid in the order the server sent
 * them: sixty-three rows, most of them flat, the Wi-Fi ones twice. Grouped by
 * the part of the device they describe, each group can say how many it holds
 * and which of them moved most, and the metrics that did not move at all fold
 * into one line.
 */
export type MetricSubsystem =
  'system' | 'memory_disk' | 'wan' | 'lte' | 'wifi' | 'dns' | 'firewall_vpn' | 'agent' | 'other';

const ORDER: readonly MetricSubsystem[] = [
  'system',
  'memory_disk',
  'wan',
  'lte',
  'wifi',
  'dns',
  'firewall_vpn',
  'agent',
  'other',
];

// Checked in order: the first prefix that matches wins, so the narrower
// net_lte is tested before the wider net.
const PREFIXES: readonly [MetricSubsystem, readonly string[]][] = [
  ['lte', ['lte_', 'net_lte']],
  ['wifi', ['wifi_', 'wifi']],
  ['dns', ['dns_']],
  ['firewall_vpn', ['fw_', 'firewall', 'conntrack', 'wg_', 'wireguard', 'tailscale', 'zerotier', 'openvpn', 'vpn_']],
  ['agent', ['agent_', 'log_']],
  ['memory_disk', ['ram', 'mem', 'swap', 'disk', 'hdd', 'fs_', 'io_']],
  ['wan', ['wan_', 'net', 'ping', 'sqm_', 'iface_', 'if_']],
  ['system', ['cpu', 'load', 'iowait', 'steal', 'temp', 'uptime', 'entropy', 'proc', 'ctx', 'intr']],
];

export function metricSubsystem(key: string): MetricSubsystem {
  const k = key.toLowerCase();
  for (const [subsystem, prefixes] of PREFIXES) {
    if (prefixes.some((p) => k.startsWith(p))) return subsystem;
  }
  return 'other';
}

/** Measured values of a chart's first series; a gap marker is not a value. */
function values(chart: ChartData): number[] {
  return (chart.series[0]?.points ?? [])
    .map((p) => p.v)
    .filter((v): v is number => typeof v === 'number' && Number.isFinite(v));
}

/** Did not move at all in the window. One sample cannot say, so it is not "unchanged". */
export function isUnchanged(chart: ChartData): boolean {
  const v = values(chart);
  return v.length >= 2 && Math.max(...v) === Math.min(...v);
}

/** How far a metric moved relative to its size - the group's most notable member moved most. */
function movement(chart: ChartData): number {
  const v = values(chart);
  if (v.length < 2) return 0;
  const max = Math.max(...v);
  const min = Math.min(...v);
  const scale = Math.max(Math.abs(max), Math.abs(min));
  return scale === 0 ? 0 : (max - min) / scale;
}

export interface MetricGroupData {
  subsystem: MetricSubsystem;
  /** The metrics that moved, in the server's order. */
  changed: ChartData[];
  unchanged: ChartData[];
  /** The metric that moved most, relative to its size; null when nothing moved. */
  notable: ChartData | null;
}

export function groupMetrics(charts: readonly ChartData[]): MetricGroupData[] {
  const bySubsystem = new Map<MetricSubsystem, ChartData[]>();
  for (const chart of charts) {
    const s = metricSubsystem(chart.id);
    bySubsystem.set(s, [...(bySubsystem.get(s) ?? []), chart]);
  }
  return ORDER.filter((s) => bySubsystem.has(s)).map((subsystem) => {
    const list = bySubsystem.get(subsystem) ?? [];
    const changed = list.filter((c) => !isUnchanged(c));
    const unchanged = list.filter(isUnchanged);
    const notable = changed.reduce<ChartData | null>(
      (best, c) => (movement(c) > (best ? movement(best) : 0) ? c : best),
      null
    );
    return { subsystem, changed, unchanged, notable };
  });
}

/** The last measured value - a gap marker at the end must not read as the current reading. */
export function latestValue(chart: ChartData): number | null {
  const points = chart.series[0]?.points ?? [];
  for (let i = points.length - 1; i >= 0; i--) {
    const v = points[i].v;
    if (typeof v === 'number' && Number.isFinite(v)) return v;
  }
  return null;
}

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** Spelled out key by key, so the dictionary lint sees every one. */
export function subsystemTitle(subsystem: MetricSubsystem, t: TranslateFn): string {
  switch (subsystem) {
    case 'system':
      return t('metric_group.system', 'Systém');
    case 'memory_disk':
      return t('metric_group.memory_disk', 'Paměť a disk');
    case 'wan':
      return t('metric_group.wan', 'WAN');
    case 'lte':
      return t('metric_group.lte', 'LTE');
    case 'wifi':
      return t('metric_group.wifi', 'Wi-Fi');
    case 'dns':
      return t('metric_group.dns', 'DNS');
    case 'firewall_vpn':
      return t('metric_group.firewall_vpn', 'Firewall a VPN');
    case 'agent':
      return t('metric_group.agent', 'Agent');
    case 'other':
      return t('metric_group.other', 'Ostatní');
  }
}
