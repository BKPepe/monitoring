/**
 * Tvar dat, který grafy očekávají.
 *
 * Jediné místo, kde je definované rozhraní mezi UI a zdrojem dat.
 * Implementace jsou dvě: `http-source.ts` (dnešní PHP `api.php`) a
 * `mock-source.ts` (dnes jen deleguje na http-source; vymyšlená data
 * negeneruje - UI přes `isMock` jen hlásí, že API bylo nedostupné).
 *
 * Typy odpovídají tomu, co `apps/status/api.php` reálně vrací —
 * viz `apps/server/API-CONTRACT.md`.
 */

/**
 * Klíče metrik z `bk_get_metric_registry()` ve `functions.php`.
 * Registr je zdroj pravdy; tenhle výčet ho musí kopírovat přesně,
 * jinak API vrátí `{"error":"Unknown metric"}`.
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

/** Barevný tón série. Musí odpovídat tokenům `--chart-*`. */
export type MetricTone = 'cpu' | 'memory' | 'network' | 'temperature' | 'disk' | 'latency';

export interface MetricPoint {
  /**
   * Unix timestamp v **milisekundách**.
   *
   * `api.php` vrací sekundy — převod na ms dělá `http-source.ts`, aby si
   * s tím nemusela poradit každá komponenta zvlášť.
   */
  t: number;
  /** Hodnota v jednotce série; `null` = chybějící měření, ne nula. */
  v: number | null;
}

export interface MetricSeries {
  key: string;
  label: string;
  unit: string;
  tone: MetricTone;
  points: MetricPoint[];
  /** Série je predikce, ne měření — vykresluje se přerušovaně. */
  predicted?: boolean;
}

/** Událost na časové ose grafu (výpadek, restart, změna konfigurace). */
export interface ChartEvent {
  t: number;
  label: string;
}

/** Podklad pro jeden graf — jedna nebo víc sérií ve společných osách. */
export interface ChartData {
  id: string;
  title: string;
  /** Horní mez osy Y. `null` = dopočítat z dat. */
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
   * Kolik dní zbývá do zaplnění (100 %).
   *
   * Počítá to `api.php` lineární regresí nad 7 dny, pro `hdd`, `ram`
   * a `inode_usage`. Nepočítá se to tady.
   */
  daysToFull?: number;
}

/** Periody, které `api.php` přijímá v parametru `period`. */
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

/** Odpověď `api.php?action=public_status`. */
export interface PublicStatus {
  status: 'healthy' | 'degraded';
  /** null = zatím žádná kontrola za posledních 30 dní (nová instalace / mrtvý cron), ne 100 %. */
  uptimePercent: number | null;
  totalMonitors: number;
  downMonitors: number;
  agentsOnline: number;
  agentsTotal: number;
  /** null = zatím žádné měření odezvy za poslední hodinu. */
  avgLatencyMs: number | null;
  lastUpdated: string | null;
  nodes: { name: string; status: 'online' | 'warning' | 'offline'; latencyMs: number | null }[];
}

export interface MetricsSource {
  /** Jméno zdroje pro UI — uživatel má vidět, jestli kouká na reálná data. */
  readonly name: 'api.php' | 'mock';
  getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]>;
  getPublicStatus(): Promise<PublicStatus>;
  getMetricDetail(monitorId: number, metric: string): Promise<MetricDetail>;
  getMetricSeries(monitorId: number, metric: string, range: MetricRange): Promise<MetricSeriesResponse>;
}
