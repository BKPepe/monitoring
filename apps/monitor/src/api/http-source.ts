import type {
  ChartData,
  MetricKey,
  MetricPoint,
  MetricsSource,
  MetricTone,
  PublicStatus,
  TimeRange,
} from './types';

/**
 * Napojení na PHP backend (`apps/status/api.php`).
 */
export const STATUS_API: string = import.meta.env.VITE_STATUS_API ?? '/status';

export function resolveUrl(path: string | null | undefined): string {
  if (!path) return '/app/setup';
  if (path.startsWith('/') || path.startsWith('http://') || path.startsWith('https://')) {
    return path;
  }
  return `${STATUS_API}/${path}`;
}

/** Odpověď `action=metric_series`, jak ji `api.php` skutečně posílá. */
interface MetricSeriesResponse {
  points: [number, number, number?][];
  events?: { ts: number; label: string }[];
  unit: string;
  label: string;
  prediction?: [number, number][];
  days_to_full?: number;
  error?: string;
}

const CHART_METRICS: {
  key: MetricKey;
  title: string;
  tone: MetricTone;
  yMax: number | null;
}[] = [
  { key: 'cpu', title: 'Využití CPU', tone: 'cpu', yMax: 100 },
  { key: 'ram', title: 'Využití paměti', tone: 'memory', yMax: 100 },
  { key: 'hdd', title: 'Zaplnění disku', tone: 'disk', yMax: 100 },
  { key: 'net', title: 'Síťový provoz (KB/s)', tone: 'network', yMax: null },
  { key: 'iowait', title: 'Čekání na I/O', tone: 'latency', yMax: 100 },
  { key: 'swap', title: 'Využití swapu', tone: 'temperature', yMax: 100 },
  { key: 'load1', title: 'Load Average (1 min)', tone: 'cpu', yMax: null },
  { key: 'ts_clients', title: 'TeamSpeak Klienti', tone: 'memory', yMax: null },
  { key: 'temperature_c', title: 'Teplota CPU (°C)', tone: 'temperature', yMax: 120 },
];

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

/** Sekundy → milisekundy. `api.php` posílá UNIX_TIMESTAMP(), tedy sekundy. */
function toPoints(raw: [number, number, number?][]): MetricPoint[] {
  if (!Array.isArray(raw)) return [];
  return raw.map(([ts, value]) => ({ t: ts * 1000, v: value }));
}

export const httpMetricsSource: MetricsSource = {
  name: 'api.php',

  async getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]> {
    // Normalizace ID monitoru (104 -> 4, 105 -> 5, 106 -> 6 pro MySQL tabulku monitors)
    const normalizedId = monitorId > 100 ? monitorId - 100 : monitorId;

    const responses = await Promise.all(
      CHART_METRICS.map(async (metric) => {
        try {
          const data = await getJson<MetricSeriesResponse>(
            `api.php?action=metric_series&monitor_id=${normalizedId}` +
              `&metric=${metric.key}&period=${range}`
          );
          return { metric, data };
        } catch {
          return { metric, data: null };
        }
      })
    );

    const validCharts: ChartData[] = [];

    for (const { metric, data } of responses) {
      if (data && !data.error && Array.isArray(data.points) && data.points.length > 0) {
        const series: ChartData['series'] = [
          {
            key: metric.key,
            label: data.label || metric.title,
            unit: data.unit ?? '',
            tone: metric.tone,
            points: toPoints(data.points),
          },
        ];

        if (data.prediction?.length) {
          series.push({
            key: `${metric.key}_prediction`,
            label: `${data.label || metric.title} (Predikce)`,
            unit: data.unit ?? '',
            tone: metric.tone,
            predicted: true,
            points: data.prediction.map(([t, v]) => ({ t: t * 1000, v })),
          });
        }

        validCharts.push({
          id: metric.key,
          title: data.label || metric.title,
          yMax: metric.yMax,
          series,
        });
      }
    }

    return validCharts;
  },

  async getPublicStatus(): Promise<PublicStatus> {
    const raw = await getJson<any>('api.php?action=public_status');
    return {
      status: raw.status ?? 'healthy',
      uptimePercent: raw.uptime30d ?? raw.uptimePercent ?? 100,
      totalMonitors: raw.totalMonitors ?? 0,
      downMonitors: raw.downMonitors ?? 0,
      agentsOnline: raw.agentsOnline ?? 1,
      agentsTotal: raw.agentsTotal ?? 1,
      avgLatencyMs: raw.avgLatencyMs ?? 0,
      lastUpdated: raw.lastUpdated ?? new Date().toISOString(),
      nodes: Array.isArray(raw.nodes) ? raw.nodes : [],
    };
  },
};
