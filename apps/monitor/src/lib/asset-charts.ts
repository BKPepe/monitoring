import type { ChartData } from '@/api/types';
import { normalizeMonitorType } from '@/lib/monitor-type';

/**
 * Which of a device's metrics get a chart card on its overview (W2-2).
 *
 * The router overview was a wall: thirteen curated cards, every one drawn
 * even when the line never moved, and sixty more rows below (charts-08,
 * 4710 px on a desktop). Now the type decides at most six cards - the six a
 * person opens the page for - a card whose line stayed flat folds into one
 * sentence, and everything else waits in the subsystem groups.
 */
export const MAX_FEATURED = 6;

// In the order they are read. 'net' stands for the WAN chart, which becomes
// the combined WAN + LTE chart when the backup carried traffic.
const ROUTER = ['cpu', 'ram', 'net', 'temperature_c', 'response_time', 'hdd'];
const SERVER = ['cpu', 'ram', 'hdd', 'net', 'response_time', 'load1'];
const FEATURED_BY_TYPE: Record<string, readonly string[]> = {
  openwrt: ROUTER,
  router: ROUTER,
  vps: SERVER,
  cpanel: SERVER,
  agent_service: ['cpu', 'ram', 'response_time'],
  teamspeak: ['response_time', 'ts_clients', 'cpu', 'ram'],
  minecraft: ['response_time', 'mc_players'],
  discord: ['response_time', 'discord_presence'],
};
// A type this build does not know keeps the usual suspects.
const DEFAULT_FEATURED = ['response_time', 'cpu', 'ram', 'hdd', 'net', 'temperature_c'];

/**
 * Metrics that only restate another one and leave the list (they stay one
 * click away on the metric page): used/free/available/total memory in MB say
 * what the RAM % chart says, and load5/load15 are smoothed load1.
 */
const RESTATED: ReadonlySet<string> = new Set([
  'ram_used_mb',
  'ram_free_mb',
  'ram_available_mb',
  'ram_total_mb',
  'load5',
  'load15',
]);

export function featuredIds(type: string | null | undefined): readonly string[] {
  return (FEATURED_BY_TYPE[normalizeMonitorType(type)] ?? DEFAULT_FEATURED).slice(0, MAX_FEATURED);
}

/** Measured values of the first series; a gap marker is not a value. */
function values(chart: ChartData): number[] {
  return (chart.series[0]?.points ?? [])
    .map((p) => p.v)
    .filter((v): v is number => typeof v === 'number' && Number.isFinite(v));
}

/**
 * The line did not move enough to be worth a chart. A percentage within one
 * point, anything else within 2 % of its size: "Čekání na I/O 0,3-0,6 %"
 * drawn across a 0-100 axis was a straight line in a 300 px card.
 */
export function isFlat(chart: ChartData): boolean {
  const v = values(chart);
  if (v.length < 2) return false;
  const min = Math.min(...v);
  const max = Math.max(...v);
  const spread = max - min;
  if (spread === 0) return true;
  if (chart.series[0]?.unit === '%') return spread <= 1;
  return spread <= Math.max(Math.abs(max), Math.abs(min)) * 0.02;
}

/**
 * A value inside one of the chart's threshold bands (the monitor's own alert
 * limits). Such a chart stays a card even when flat: a disk at a steady 97 %
 * folded into "97 %, beze změny" lost the red band that says it is full.
 */
export function inBand(chart: ChartData): boolean {
  const v = values(chart);
  if (v.length === 0) return false;
  const max = Math.max(...v);
  return (chart.bands ?? []).some((b) => max >= b.from);
}

/** The lowest and highest measured value, for the one-line row. */
export function valueRange(chart: ChartData): { min: number; max: number } | null {
  const v = values(chart);
  return v.length === 0 ? null : { min: Math.min(...v), max: Math.max(...v) };
}

export interface OverviewPlan {
  /** Cards, in the type's order, the WAN slot already swapped for the combined chart. */
  cards: ChartData[];
  /** Chosen for a card but flat in this window: one line each. */
  flat: ChartData[];
  /** Everything else, for the subsystem groups. */
  others: ChartData[];
}

/**
 * Splits the device's charts into cards, flat lines and the rest.
 *
 * @param combined The stacked WAN + LTE chart when the backup carried traffic;
 *   it takes the WAN slot, and the separate WAN and LTE charts are dropped
 *   everywhere, because they only repeat its two bands.
 */
export function planOverview(
  charts: readonly ChartData[],
  type: string | null | undefined,
  combined: ChartData | null = null
): OverviewPlan {
  const byId = new Map(charts.map((c) => [c.id, c]));
  const chosen = featuredIds(type);
  const cards: ChartData[] = [];
  const flat: ChartData[] = [];
  const used = new Set<string>();

  for (const id of chosen) {
    const chart = id === 'net' && combined ? combined : byId.get(id);
    if (!chart) continue;
    used.add(id);
    if (isFlat(chart) && !inBand(chart)) flat.push(chart);
    else cards.push(chart);
  }
  if (combined) {
    used.add('net');
    used.add('net_lte');
  }

  const others = charts.filter((c) => !used.has(c.id) && !RESTATED.has(c.id));
  return { cards, flat, others };
}
