import type { ChartData, MetricSeries } from '@/api/types';

/**
 * Which stored series draws the sparkline and delta of each KPI tile.
 *
 * The tiles used to borrow the first chart that shared their colour tone, so
 * the router's temperature tile drew LTE traffic (both "temperature" tone) and
 * a server's "Odezva —" tile showed "↑ 81 %" from I/O wait. A tile draws its
 * own metric or nothing: a missing trend is honest, a borrowed one is not.
 */
export const TILE_SERIES_KEY: Readonly<Record<string, string>> = {
  latency: 'response_time',
  cpu: 'cpu',
  ram: 'ram',
  hdd: 'hdd',
  temp: 'temperature_c',
  ts3_clients: 'ts_clients',
};

/** The tile's own series from the loaded charts, or undefined when it has none. */
export function seriesForTile(
  tileKey: string,
  charts: readonly ChartData[] | null | undefined
): MetricSeries | undefined {
  const seriesKey = TILE_SERIES_KEY[tileKey];
  if (!seriesKey || !charts) return undefined;
  for (const chart of charts) {
    const own = chart.series.find((s) => s.key === seriesKey && !s.predicted && !s.past);
    if (own) return own;
  }
  return undefined;
}
