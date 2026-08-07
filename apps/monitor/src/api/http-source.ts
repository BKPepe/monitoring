import type { ChartData, MetricKey, MetricPoint, MetricsSource, MetricTone, PublicStatus, TimeRange } from './types';

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

const CHART_METRICS: {
  key: MetricKey;
  title: string;
  tone: MetricTone;
  yMax: number | null;
}[] = [
  // Jediná metrika, kterou má i monitor bez agenta (web/port/discord/...) -
  // response_time se měří při každé kontrole dostupnosti (monitor_logs), ne
  // jen u agentů. Bez tohohle záznamu neměl monitor bez agenta na záložce
  // "Přehled & Výkon" nikdy žádný graf, i když jeho historie odezvy reálně
  // existuje (stejná data používá i SLA report a tabulka událostí).
  { key: 'response_time', title: 'Doba odezvy (Latency)', tone: 'latency', yMax: null },
  { key: 'cpu', title: 'Využití CPU', tone: 'cpu', yMax: 100 },
  { key: 'ram', title: 'Využití paměti', tone: 'memory', yMax: 100 },
  { key: 'hdd', title: 'Zaplnění disku', tone: 'disk', yMax: 100 },
  { key: 'net', title: 'Síťový provoz (KB/s)', tone: 'network', yMax: null },
  { key: 'iowait', title: 'Čekání na I/O', tone: 'latency', yMax: 100 },
  { key: 'swap', title: 'Využití swapu', tone: 'temperature', yMax: 100 },
  { key: 'load1', title: 'Load Average (1 min)', tone: 'cpu', yMax: null },
  { key: 'ts_clients', title: 'TeamSpeak Klienti', tone: 'memory', yMax: null },
  // Discord: počet lidí online. Data se sbírala každou minutu, ale do
  // historie se neukládala, takže Discord neměl žádný graf kromě odezvy.
  { key: 'discord_presence', title: 'Online na Discordu', tone: 'memory', yMax: null },
  { key: 'mc_players', title: 'Hráči online', tone: 'memory', yMax: null },
  { key: 'temperature_c', title: 'Teplota CPU (°C)', tone: 'temperature', yMax: 120 },
];

/** Odpověď `action=metric_series_batch` - všechny grafy zařízení v jednom požadavku. */
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

/** Sekundy → milisekundy. `api.php` posílá UNIX_TIMESTAMP(), tedy sekundy. */
function toPoints(raw: [number, number, number?][]): MetricPoint[] {
  if (!Array.isArray(raw)) return [];
  return raw.map(([ts, value]) => ({ t: ts * 1000, v: value }));
}

export const httpMetricsSource: MetricsSource = {
  name: 'api.php',

  async getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]> {
    // monitorId je vždy skutečné monitors.id - api.php ho navíc přijímá i jako
    // asset_id (WHERE id = ? OR asset_id = ?), takže žádná přepočítávací
    // normalizace ID tady není potřeba.
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

    // Žádná fabrikace: chybí-li reálná data, vrací se prázdné pole a komponenta,
    // co grafy vykresluje, na to reaguje stavem "data nejsou k dispozici" - ne
    // vymyšlenými hodnotami, které vypadají jako měření.
    return validCharts;
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
