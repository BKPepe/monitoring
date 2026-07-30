/**
 * Tvar dat, který grafy očekávají.
 *
 * Jediné místo, kde je definované rozhraní mezi UI a zdrojem dat.
 * Implementace jsou dvě: `http-source.ts` (dnešní PHP `api.php`) a
 * `mock-source.ts` (vymyšlená data pro vývoj bez serveru).
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
   * Kolik dní zbývá do zaplnění (100 %).
   *
   * Počítá to `api.php` lineární regresí nad 7 dny, pro `hdd`, `ram`
   * a `inode_usage`. Nepočítá se to tady.
   */
  daysToFull?: number;
}

/** Periody, které `api.php` přijímá v parametru `period`. */
export type TimeRange = '15m' | '1h' | '6h' | '24h' | '7d' | '30d';

export const timeRangeLabels: Record<TimeRange, string> = {
  '15m': 'Posledních 15 minut',
  '1h': 'Poslední hodina',
  '6h': 'Posledních 6 hodin',
  '24h': 'Posledních 24 hodin',
  '7d': 'Posledních 7 dní',
  '30d': 'Posledních 30 dní',
};

/** Odpověď `api.php?action=public_status`. */
export interface PublicStatus {
  status: 'healthy' | 'degraded';
  uptimePercent: number;
  totalMonitors: number;
  downMonitors: number;
  agentsOnline: number;
  agentsTotal: number;
  avgLatencyMs: number;
  lastUpdated: string | null;
  nodes: { name: string; status: 'online' | 'warning' | 'offline'; latencyMs: number | null }[];
}

export interface MetricsSource {
  /** Jméno zdroje pro UI — uživatel má vidět, jestli kouká na reálná data. */
  readonly name: 'api.php' | 'mock';
  getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]>;
  getPublicStatus(): Promise<PublicStatus>;
}
