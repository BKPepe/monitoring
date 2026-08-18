import type {
  ChartData,
  MetricDetail,
  MetricHeatmapResponse,
  MetricKey,
  MetricPoint,
  MetricRange,
  MetricSeriesResponse,
  MetricsSource,
  MetricTone,
  PublicStatus,
  TimeRange,
} from './types';

/**
 * Binding to the PHP backend (`apps/status/api.php`).
 */
export const STATUS_API: string = import.meta.env.VITE_STATUS_API ?? '/status';

export function resolveUrl(path: string | null | undefined): string {
  if (!path) return '/app/setup';
  if (path.startsWith('/') || path.startsWith('http://') || path.startsWith('https://')) {
    return path;
  }
  return `${STATUS_API}/${path}`;
}

const CHART_METRICS: {
  key: MetricKey;
  title: string;
  tone: MetricTone;
  yMax: number | null;
}[] = [
  // The one metric even an agentless monitor (web/port/discord/...) has -
  // response_time is measured on every availability check (monitor_logs), not
  // just for agents. Without this entry an agentless monitor never had any
  // chart on the "Overview & Performance" tab even though its latency
  // history really exists (the SLA report and the events table use the same data).
  { key: 'response_time', title: 'Doba odezvy (Latency)', tone: 'latency', yMax: null },
  { key: 'cpu', title: 'Využití CPU', tone: 'cpu', yMax: 100 },
  { key: 'ram', title: 'Využití paměti', tone: 'memory', yMax: 100 },
  { key: 'hdd', title: 'Zaplnění disku', tone: 'disk', yMax: 100 },
  { key: 'net', title: 'Síťový provoz (KB/s)', tone: 'network', yMax: null },
  { key: 'iowait', title: 'Čekání na I/O', tone: 'latency', yMax: 100 },
  { key: 'swap', title: 'Využití swapu', tone: 'temperature', yMax: 100 },
  { key: 'load1', title: 'Load Average (1 min)', tone: 'cpu', yMax: null },
  { key: 'ts_clients', title: 'TeamSpeak Klienti', tone: 'memory', yMax: null },
  // Discord: people online. The data was collected every minute but never
  // stored into history, so Discord had no chart except latency.
  { key: 'discord_presence', title: 'Online na Discordu', tone: 'memory', yMax: null },
  { key: 'mc_players', title: 'Hráči online', tone: 'memory', yMax: null },
  // RSRP is in negative dBm, so no yMax - the chart derives the range from the data.
  { key: 'lte_rsrp', title: 'Síla LTE signálu (RSRP)', tone: 'latency', yMax: null },
  { key: 'temperature_c', title: 'Teplota CPU (°C)', tone: 'temperature', yMax: 120 },
];

/** Response of `action=metric_series_batch` - all device charts in one request. */
interface MetricSeriesBatchResponse {
  series: Record<string, { points: [number, number, number?][]; unit: string; label: string }>;
  error?: string;
}

async function getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
  const isGoBackend = STATUS_API.includes('/api/v1');
  let url = `${STATUS_API}/${path}`;
  if (isGoBackend) {
    if (path.includes('action=metric_series')) {
      url = `${STATUS_API}/metrics/series?${path.replace('api.php?action=metric_series&', '')}`;
    } else if (path.includes('action=public_status')) {
      url = `${STATUS_API}/public_status`;
    }
  }

  try {
    const res = await fetch(url, { signal });
    if (!res.ok) throw new Error(`${path} → HTTP ${res.status}`);
    return (await res.json()) as T;
  } catch (err) {
    if (!isGoBackend && path.includes('action=metric_series')) {
      const fallbackUrl = `/api/v1/metrics/series?${path.replace('api.php?action=metric_series&', '')}`;
      const res = await fetch(fallbackUrl, { signal }).catch(() => null);
      if (res && res.ok) return (await res.json()) as T;
    }
    throw err;
  }
}

/** Seconds → milliseconds. `api.php` sends UNIX_TIMESTAMP(), i.e. seconds. */
function toPoints(raw: [number, number, number?][]): MetricPoint[] {
  if (!Array.isArray(raw)) return [];
  return raw.map(([ts, value]) => ({ t: ts * 1000, v: value }));
}

export const httpMetricsSource: MetricsSource = {
  name: 'api.php',

  async getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]> {
    // monitorId is always the real monitors.id - api.php additionally accepts it
    // as asset_id too (WHERE id = ? OR asset_id = ?), so no ID re-mapping
    // normalisation is needed here.
    const batch = await getJson<MetricSeriesBatchResponse>(
      `api.php?action=metric_series_batch&monitor_id=${monitorId}&period=${range}`
    ).catch(() => null);

    if (!batch || !batch.series) return [];

    const validCharts: ChartData[] = [];

    for (const metric of CHART_METRICS) {
      const data = batch.series[metric.key];
      if (data && Array.isArray(data.points) && data.points.length > 0) {
        validCharts.push({
          id: metric.key,
          title: data.label || metric.title,
          yMax: metric.yMax,
          series: [
            {
              key: metric.key,
              label: data.label || metric.title,
              unit: data.unit ?? '',
              tone: metric.tone,
              points: toPoints(data.points),
            },
          ],
        });
      }
    }

    // No fabrication: when real data is missing, an empty array comes back and the component,
    // co grafy vykresluje, na to reaguje stavem "data nejsou k dispozici" - ne
    // with invented values that look like measurements.
    return validCharts;
  },

  async getMetricDetail(monitorId: number, metric: string): Promise<MetricDetail> {
    return getJson<MetricDetail>(
      `api.php?action=metric_detail&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}`
    );
  },

  async getMetricSeries(monitorId: number, metric: string, range: MetricRange): Promise<MetricSeriesResponse> {
    const res = await getJson<MetricSeriesResponse>(
      `api.php?action=metric_series&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&period=${range}`
    );
    // An empty series is a legitimate answer (the agent does not report this
    // metric), a broken shape is not - it would surface in the chart as "no
    // data" and hide the actual error.
    if (!Array.isArray(res?.points)) {
      throw new Error(res?.error ?? 'Neplatná odpověď metric_series.');
    }
    return res;
  },

  async getMetricHeatmap(monitorId: number, metric: string, days: number): Promise<MetricHeatmapResponse> {
    const res = await getJson<MetricHeatmapResponse>(
      `api.php?action=metric_heatmap&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&days=${days}`
    );
    if (!Array.isArray(res?.days)) {
      throw new Error(res?.error ?? 'Neplatná odpověď metric_heatmap.');
    }
    return res;
  },

  async getPublicStatus(): Promise<PublicStatus> {
    const raw = await getJson<any>('api.php?action=public_status');
    // totalMonitors is always a real COUNT(*) - if that's missing, the
    // response itself is broken. uptimePercent/avgLatencyMs are legitimately
    // null when there's no data yet (new install, dead cron), so they're not
    // required here - defaulting them to a number would fabricate a reading.
    if (typeof raw?.totalMonitors !== 'number') {
      throw new Error('Neplatná odpověď z /status API (chybí povinná pole).');
    }
    return {
      status: raw.status === 'degraded' ? 'degraded' : 'healthy',
      uptimePercent: typeof raw.uptimePercent === 'number' ? raw.uptimePercent : null,
      totalMonitors: raw.totalMonitors,
      downMonitors: raw.downMonitors ?? 0,
      agentsOnline: raw.agentsOnline ?? 0,
      agentsTotal: raw.agentsTotal ?? 0,
      avgLatencyMs: typeof raw.avgLatencyMs === 'number' ? raw.avgLatencyMs : null,
      lastUpdated: raw.lastUpdated ?? null,
      nodes: Array.isArray(raw.nodes) ? raw.nodes : [],
    };
  },
};
