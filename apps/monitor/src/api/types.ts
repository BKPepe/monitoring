/**
 * The shape of data the charts expect.
 *
 * The single place defining the interface between the UI and the data source.
 * There are two implementations: `http-source.ts` (today's PHP `api.php`) and
 * `mock-source.ts` (which now just delegates to http-source; it generates no
 * invented data - via `isMock` the UI only reports the API was unreachable).
 *
 * The types match what `apps/status/api.php` really returns —
 * viz `apps/server/API-CONTRACT.md`.
 */

/**
 * Metric keys from `bk_get_metric_registry()` in `functions.php`.
 * The registry is the source of truth; this enum must copy it exactly,
 * otherwise the API returns `{"error":"Unknown metric"}`.
 */
export type MetricKey =
  | 'response_time'
  | 'discord_presence'
  | 'mc_players'
  | 'lte_rsrp'
  | 'cpu'
  | 'ram'
  | 'hdd'
  | 'net'
  | 'load1'
  | 'load5'
  | 'load15'
  | 'cpu_steal'
  | 'swap'
  | 'disk_io_read'
  | 'disk_io_write'
  | 'net_errors'
  | 'iowait'
  | 'inode_usage'
  | 'ts_clients'
  | 'ts_process_cpu'
  | 'ts_process_ram'
  | 'net_ipv4'
  | 'net_ipv6'
  | 'temperature_c';

/** Colour tone of a series. Must match the `--chart-*` tokens. */
export type MetricTone = 'cpu' | 'memory' | 'network' | 'temperature' | 'disk' | 'latency';

export interface MetricPoint {
  /**
   * Unix timestamp in **milliseconds**.
   *
   * `api.php` returns seconds — `http-source.ts` converts to ms so every
   * component does not have to deal with it separately.
   */
  t: number;
  /** Value in the series' unit; `null` = a missing measurement, not zero. */
  v: number | null;
}

export interface MetricSeries {
  key: string;
  label: string;
  unit: string;
  tone: MetricTone;
  points: MetricPoint[];
  /** The series is a prediction, not a measurement — drawn dashed. */
  predicted?: boolean;
}

/** An event on the chart's timeline (outage, restart, config change). */
export interface ChartEvent {
  t: number;
  label: string;
}

/** Input for one chart — one or more series sharing axes. */
export interface ChartData {
  id: string;
  title: string;
  /** Upper Y-axis bound. `null` = derive from the data. */
  yMax: number | null;
  series: MetricSeries[];
  events?: ChartEvent[];
  /**
   * Threshold bands (warning, critical) as horizontal areas.
   *
   * A single line at the critical limit does not tell you whether 78 % is
   * still fine or already on the edge. A band shows it without reading numbers.
   */
  bands?: { from: number; to: number; tone: 'warning' | 'critical'; label: string }[];
  /**
   * Days remaining until full (100 %).
   *
   * Computed by `api.php` with linear regression over 7 days, for `hdd`, `ram`
   * and `inode_usage`. Not computed here.
   */
  daysToFull?: number;
}

/** The periods `api.php` accepts in the `period` parameter. */
export type TimeRange = '15m' | '1h' | '6h' | '24h' | '7d' | '30d';

/**
 * Periods longer than the raw-data retention (30 days). They are read from the
 * `metrics_daily` rollup, so a point is a daily average rather than a single
 * measurement - the server admits this with `resolution: 'daily'`.
 */
export type LongTimeRange = '90d' | '1y';
export type MetricRange = TimeRange | LongTimeRange;

export const timeRangeLabels: Record<TimeRange, string> = {
  '15m': 'Posledních 15 minut',
  '1h': 'Poslední hodina',
  '6h': 'Posledních 6 hodin',
  '24h': 'Posledních 24 hodin',
  '7d': 'Posledních 7 dní',
  '30d': 'Posledních 30 dní',
};

/** Response of `api.php?action=metric_series`. */
export interface MetricSeriesResponse {
  label: string;
  unit: string;
  /** [unix seconds, value] */
  points: [number, number][];
  /** `daily` = points are daily averages, not individual measurements. */
  resolution?: 'daily';
  error?: string;
}

/** Context for the metric detail page (`api.php?action=metric_detail`). */
export interface MetricDetail {
  monitor: { id: number; name: string; type: string; assetId: number | null };
  metric: { key: string; label: string; unit: string; counter: boolean };
  /** `null` = no threshold is set; no band is drawn in the chart. */
  thresholds: { warning: number | null; critical: number | null };
  /** Only metrics this monitor actually reported in its latest measurement. */
  related: { key: string; label: string; unit: string; latest: number }[];
  events: { t: number; type: string; label: string }[];
}

/** Response of `api.php?action=public_status`. */
export interface PublicStatus {
  status: 'healthy' | 'degraded';
  /** null = no check in the last 30 days yet (fresh install / dead cron), not 100 %. */
  uptimePercent: number | null;
  totalMonitors: number;
  downMonitors: number;
  agentsOnline: number;
  agentsTotal: number;
  /** null = no latency measurement in the last hour yet. */
  avgLatencyMs: number | null;
  lastUpdated: string | null;
  nodes: { name: string; status: 'online' | 'warning' | 'offline'; latencyMs: number | null }[];
}

export interface MetricsSource {
  /** Source name for the UI — the user should see whether the data is real. */
  readonly name: 'api.php' | 'mock';
  getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]>;
  getPublicStatus(): Promise<PublicStatus>;
  getMetricDetail(monitorId: number, metric: string): Promise<MetricDetail>;
  getMetricSeries(monitorId: number, metric: string, range: MetricRange): Promise<MetricSeriesResponse>;
}
