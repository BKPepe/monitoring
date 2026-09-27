import { windowFor } from '@/lib/chart-window';
import { metricTone } from '@/lib/metric-tone';
import { insertGaps } from '@/lib/series-gaps';
import type {
  ChartData,
  MetricDetail,
  MetricCorrelationsResponse,
  LinkTrafficResponse,
  MetricHeatmapResponse,
  MetricKey,
  MetricPoint,
  MetricRange,
  MetricSeriesResponse,
  MetricsSource,
  PublicStatus,
  TimeRange,
  PublicStatusScope,
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

// The series hue comes from lib/metric-tone.ts - one map for every place a
// metric is drawn (C-2); this list only says which metrics get a card.
const CHART_METRICS: {
  key: MetricKey;
  title: string;
  yMax: number | null;
  /** 0 = measured from zero; null = the chart derives the range from the data. */
  yMin: number | null;
}[] = [
  // The one metric even an agentless monitor (web/port/discord/...) has -
  // response_time is measured on every availability check (monitor_logs), not
  // just for agents. Without this entry an agentless monitor never had any
  // chart on the "Overview & Performance" tab even though its latency
  // history really exists (the SLA report and the events table use the same data).
  { key: 'response_time', title: 'Doba odezvy (Latency)', yMax: null, yMin: null },
  { key: 'cpu', title: 'Využití CPU', yMax: 100, yMin: 0 },
  { key: 'ram', title: 'Využití paměti', yMax: 100, yMin: 0 },
  { key: 'hdd', title: 'Zaplnění disku', yMax: 100, yMin: 0 },
  { key: 'net', title: 'Síťový provoz (KB/s)', yMax: null, yMin: 0 },
  // The backup link carries traffic too - and traffic over it is usually
  // metered. It was measured every minute and had no card of its own.
  { key: 'net_lte', title: 'Provoz na LTE záloze (KB/s)', yMax: null, yMin: 0 },
  { key: 'iowait', title: 'Čekání na I/O', yMax: 100, yMin: 0 },
  { key: 'swap', title: 'Využití swapu', yMax: 100, yMin: 0 },
  { key: 'load1', title: 'Load Average (1 min)', yMax: null, yMin: null },
  { key: 'ts_clients', title: 'TeamSpeak Klienti', yMax: null, yMin: 0 },
  // Discord: people online. The data was collected every minute but never
  // stored into history, so Discord had no chart except latency.
  { key: 'discord_presence', title: 'Online na Discordu', yMax: null, yMin: 0 },
  { key: 'mc_players', title: 'Hráči online', yMax: null, yMin: 0 },
  // RSRP is in negative dBm, so no yMax - the chart derives the range from the data.
  { key: 'lte_rsrp', title: 'Síla LTE signálu (RSRP)', yMax: null, yMin: null },
  // A CPU lives between 40 and 70 degrees; on a fixed 0-120 axis that is a flat
  // line at the bottom, so both bounds come from the data.
  { key: 'temperature_c', title: 'Teplota CPU (°C)', yMax: null, yMin: null },
];

/**
 * The UI language for the server's metric labels (charts-22: an English page
 * titled its charts "Využití CPU"). LanguageProvider keeps <html lang> in step
 * with the chosen language, so this module needs no React context.
 */
function uiLang(): 'cs' | 'en' {
  return typeof document !== 'undefined' && document.documentElement.lang === 'en' ? 'en' : 'cs';
}

/** Response of `action=metric_series_batch` - all device charts in one request. */
interface MetricSeriesBatchResponse {
  series: Record<
    string,
    {
      points: [number, number, number?][];
      unit: string;
      label: string;
      /** Days until the metric reaches 100 %; absent when there is no projection. */
      daysToFull?: number;
      /**
       * Mean over the same-length window one period earlier, for the card's
       * trend (lib/trend.ts). Absent on a server that does not send it yet -
       * the card then shows no trend rather than a made-up one.
       */
      previousAvg?: number | null;
    }
  >;
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
    return await readOk<T>(await fetch(url, { signal }), path);
  } catch (err) {
    // Only the single-series call has a Go twin. The batch path used to fall
    // in here too, build a nonsense URL and, when anything answered it, return
    // that answer unchecked - an error body then became an empty chart.
    if (!isGoBackend && path.includes('action=metric_series&')) {
      const fallbackUrl = `/api/v1/metrics/series?${path.replace('api.php?action=metric_series&', '')}`;
      const res = await fetch(fallbackUrl, { signal }).catch(() => null);
      if (res && res.ok) return readOk<T>(res, path);
    }
    throw err;
  }
}

/**
 * The body of a successful answer, or a thrown error.
 *
 * Older api.php answered a failed query with 200, an empty list and an `error`
 * string. Read as data, that empty list became "no data" in the charts, so a
 * named error is a failure whatever the status code says (W1-A5).
 */
async function readOk<T>(res: Response, path: string): Promise<T> {
  if (!res.ok) throw new Error(`${path} → HTTP ${res.status}`);
  const body = (await res.json()) as T;
  const named = (body as { error?: unknown } | null)?.error;
  if (typeof named === 'string' && named !== '') throw new Error(named);
  return body;
}

/**
 * Seconds → milliseconds. `api.php` sends UNIX_TIMESTAMP(), i.e. seconds.
 *
 * Both metric endpoints select only rows that HAVE a value, so a stretch when
 * nothing was measured arrives as two neighbouring samples. insertGaps marks
 * those holes, which is what turns them into breaks in the line instead of a
 * straight segment across an outage nobody measured.
 */
function toPoints(raw: [number, number, number?][]): MetricPoint[] {
  if (!Array.isArray(raw)) return [];
  return insertGaps(raw.map(([ts, value]) => ({ t: ts * 1000, v: value })));
}

export const httpMetricsSource: MetricsSource = {
  name: 'api.php',

  async getAssetCharts(monitorId: number, range: TimeRange): Promise<ChartData[]> {
    // monitorId is always the real monitors.id - api.php additionally accepts it
    // as asset_id too (WHERE id = ? OR asset_id = ?), so no ID re-mapping
    // normalisation is needed here.
    // A failure propagates. It used to be swallowed into [], which the page
    // showed as "no data in the database" for a server that never answered.
    const batch = await getJson<MetricSeriesBatchResponse>(
      `api.php?action=metric_series_batch&monitor_id=${monitorId}&period=${range}&lang=${uiLang()}`
    );
    // PHP encodes an empty map as [], so an array is a real empty answer; a
    // missing or scalar `series` is a broken one.
    if (batch == null || batch.series == null || typeof batch.series !== 'object') {
      throw new Error('Neplatná odpověď metric_series_batch.');
    }

    const validCharts: ChartData[] = [];
    // The window the answer stands for, ending now: the charts pin their time
    // axis to it so an agent that fell silent leaves visible empty space
    // (charts-02) instead of an axis that quietly ends at its last report.
    const chartWindow = windowFor(range, Date.now());
    const previousAvg = (data: { previousAvg?: number | null }) =>
      typeof data.previousAvg === 'number' && Number.isFinite(data.previousAvg) ? data.previousAvg : undefined;

    for (const metric of CHART_METRICS) {
      const data = batch.series[metric.key];
      if (data && Array.isArray(data.points) && data.points.length > 0) {
        validCharts.push({
          id: metric.key,
          title: data.label || metric.title,
          featured: true,
          window: chartWindow,
          yMax: metric.yMax,
          yMin: metric.yMin,
          // The "full in X days" badge finally has a number. Undefined stays
          // undefined - the badge renders only for a real projection.
          daysToFull: typeof data.daysToFull === 'number' ? data.daysToFull : undefined,
          series: [
            {
              key: metric.key,
              label: data.label || metric.title,
              unit: data.unit ?? '',
              tone: metricTone(metric.key),
              points: toPoints(data.points),
              previousAvg: previousAvg(data),
            },
          ],
        });
      }
    }

    // Everything else the device actually reports. The batch response has
    // always carried some sixty series and the app kept thirteen, so more than
    // forty measured metrics - router signal quality, load averages, steal
    // time, firewall counters, interface errors - were collected every minute
    // and reachable from nowhere. They are listed rather than drawn as cards:
    // a wall of sixty charts is its own kind of hidden.
    const featuredKeys = new Set(CHART_METRICS.map((m) => m.key as string));
    for (const [key, data] of Object.entries(batch.series)) {
      if (featuredKeys.has(key)) continue;
      if (!data || !Array.isArray(data.points) || data.points.length === 0) continue;
      validCharts.push({
        id: key,
        title: data.label || key,
        featured: false,
        window: chartWindow,
        yMax: null,
        // An unknown scale derives its range from the data: pinning a zero
        // floor would flatten every negative and every narrow series.
        yMin: null,
        series: [
          {
            key,
            label: data.label || key,
            unit: data.unit ?? '',
            tone: metricTone(key),
            points: toPoints(data.points),
            previousAvg: previousAvg(data),
          },
        ],
      });
    }

    // No fabrication: when real data is missing, an empty array comes back and the component,
    // co grafy vykresluje, na to reaguje stavem "data nejsou k dispozici" - ne
    // with invented values that look like measurements.
    return validCharts;
  },

  async getMetricDetail(monitorId: number, metric: string): Promise<MetricDetail> {
    return getJson<MetricDetail>(
      `api.php?action=metric_detail&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&lang=${uiLang()}`
    );
  },

  async getMetricSeries(
    monitorId: number,
    metric: string,
    range: MetricRange,
    previous = false
  ): Promise<MetricSeriesResponse> {
    const res = await getJson<MetricSeriesResponse>(
      `api.php?action=metric_series&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&period=${range}${previous ? '&previous=1' : ''}&lang=${uiLang()}`
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
      `api.php?action=metric_heatmap&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&days=${days}&lang=${uiLang()}`
    );
    if (!Array.isArray(res?.days)) {
      throw new Error(res?.error ?? 'Neplatná odpověď metric_heatmap.');
    }
    return res;
  },

  async getMetricCorrelations(
    monitorId: number,
    metric: string,
    range: MetricRange,
    all = false
  ): Promise<MetricCorrelationsResponse> {
    const res = await getJson<MetricCorrelationsResponse>(
      `api.php?action=metric_correlations&monitor_id=${monitorId}&metric=${encodeURIComponent(metric)}&period=${range}${all ? '&all=1' : ''}&lang=${uiLang()}`
    );
    if (!Array.isArray(res?.correlations)) {
      throw new Error(res?.error ?? 'Neplatná odpověď metric_correlations.');
    }
    return res;
  },

  async getLinkTraffic(monitorId: number, days = 30): Promise<LinkTrafficResponse> {
    const res = await getJson<LinkTrafficResponse>(`api.php?action=link_traffic&monitor_id=${monitorId}&days=${days}`);
    if (!Array.isArray(res?.wan_down_periods)) {
      throw new Error(res?.error ?? 'Neplatná odpověď link_traffic.');
    }
    return res;
  },

  async getPublicStatus(scope: PublicStatusScope = 'app'): Promise<PublicStatus> {
    const raw = await getJson<any>(`api.php?action=public_status${scope === 'public' ? '&scope=public' : ''}`);
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
