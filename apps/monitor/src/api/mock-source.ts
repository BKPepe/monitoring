import type {
  ChartData,
  MetricPoint,
  MetricsSource,
  MetricSeries,
  PublicStatus,
  TimeRange,
} from './types';

/**
 * Záložní implementace `MetricsSource` nad vymyšlenými daty.
 *
 * VŠECHNA ČÍSLA JSOU SMYŠLENÁ. Používá se jen pro vývoj bez běžícího
 * backendu a jako fallback, když je `api.php` nedostupné — UI pak v hlavičce
 * zobrazí varování, že nejde o reálná data.
 */

/** Počet bodů podle rozsahu — hodinový pohled nemá mít 30 vzorků. */
const pointsForRange: Record<TimeRange, number> = {
  '15m': 15,
  '1h': 60,
  '6h': 72,
  '24h': 96,
  '7d': 168,
  '30d': 120,
};

const spanMs: Record<TimeRange, number> = {
  '15m': 900_000,
  '1h': 3600_000,
  '6h': 6 * 3600_000,
  '24h': 86_400_000,
  '7d': 7 * 86_400_000,
  '30d': 30 * 86_400_000,
};

/**
 * Deterministický průběh — stejný vstup dá vždy stejnou křivku.
 *
 * Kdyby se použil `Math.random()`, graf by se překreslil jinak při každém
 * rendru i po přepnutí motivu a nešlo by na něm nic ukázat ani reprodukovat.
 */
function buildPoints(
  seed: number,
  base: number,
  amplitude: number,
  range: TimeRange,
  opts: { min?: number; max?: number; gapAt?: number } = {}
): MetricPoint[] {
  const count = pointsForRange[range];
  const span = spanMs[range];
  const end = Date.now();
  const { min = 0, max = 100, gapAt } = opts;

  return Array.from({ length: count }, (_, i) => {
    const t = end - span + (i / (count - 1)) * span;

    // Výpadek měření se modeluje jako null, ne jako nula — nula by
    // v grafu vypadala jako naměřený pokles na dno.
    if (gapAt != null && i > gapAt && i < gapAt + 4) {
      return { t, v: null };
    }

    const wave =
      Math.sin((i + seed) / 9) * amplitude +
      Math.sin((i + seed * 2) / 3.3) * amplitude * 0.35 +
      Math.sin((i + seed * 5) / 21) * amplitude * 0.5;

    const value = Math.min(max, Math.max(min, base + wave));
    return { t, v: Math.round(value * 10) / 10 };
  });
}

function series(
  key: MetricSeries['key'],
  label: string,
  unit: string,
  tone: MetricSeries['tone'],
  points: MetricPoint[]
): MetricSeries {
  return { key, label, unit, tone, points };
}

export const mockMetricsSource: MetricsSource = {
  name: 'mock',

  async getAssetCharts(assetId: number, range: TimeRange): Promise<ChartData[]> {
    // Umělé zpoždění, ať se stav načítání testuje už teď a ne až s API.
    await new Promise((resolve) => setTimeout(resolve, 180));

    const seed = assetId * 7;

    return [
      {
        id: 'cpu',
        title: 'Využití CPU',
        yMax: 100,
        series: [
          series('cpu', 'CPU', '%', 'cpu', buildPoints(seed + 1, 26, 11, range, { max: 100 })),
        ],
      },
      {
        id: 'memory',
        title: 'Využití paměti',
        yMax: 100,
        series: [
          series(
            'memory',
            'RAM',
            '%',
            'memory',
            buildPoints(seed + 4, 47, 6, range, { max: 100 })
          ),
        ],
      },
      {
        id: 'network',
        title: 'Síťový provoz',
        // Síť nemá pevný strop — mez se dopočítá z dat.
        yMax: null,
        series: [
          series(
            'network_rx',
            'Příchozí',
            'Mb/s',
            'network',
            buildPoints(seed + 8, 12, 7, range, { max: 100 })
          ),
          series(
            'network_tx',
            'Odchozí',
            'Mb/s',
            'latency',
            buildPoints(seed + 12, 4, 3, range, { max: 100 })
          ),
        ],
      },
      {
        id: 'temperature',
        title: 'Teplota',
        yMax: 100,
        series: [
          series(
            'temperature',
            'Teplota',
            '°C',
            'temperature',
            buildPoints(seed + 2, 46, 4, range, { min: 20, max: 90 })
          ),
        ],
      },
      {
        id: 'disk',
        title: 'Zaplnění úložiště',
        yMax: 100,
        series: [
          series('disk', 'Flash', '%', 'disk', buildPoints(seed + 6, 60, 2, range, { max: 100 })),
        ],
      },
      {
        id: 'latency',
        title: 'Odezva',
        yMax: null,
        series: [
          series(
            'latency',
            'Odezva',
            'ms',
            'latency',
            // Díra v datech ukazuje, že graf umí chybějící měření.
            buildPoints(seed + 3, 14, 6, range, { min: 1, max: 400, gapAt: 40 })
          ),
        ],
      },
    ];
  },

  async getPublicStatus(): Promise<PublicStatus> {
    await new Promise((resolve) => setTimeout(resolve, 120));
    return {
      status: 'degraded',
      uptimePercent: 99.78,
      totalMonitors: 24,
      downMonitors: 1,
      agentsOnline: 12,
      agentsTotal: 12,
      avgLatencyMs: 23,
      lastUpdated: new Date().toISOString(),
      nodes: [
        { name: 'Praha, CZ', status: 'online', latencyMs: 12 },
        { name: 'Frankfurt, DE', status: 'online', latencyMs: 18 },
        { name: 'New York, US', status: 'warning', latencyMs: 96 },
      ],
    };
  },
};
