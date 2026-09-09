/**
 * Reading a radio measurement as a verdict.
 *
 * "-84 dBm" is not an answer to the only question an operator has: is this
 * fine, and if not, what do I do about it. The thresholds below are the
 * conventional LTE/Wi-Fi bands; each rating carries an advice key so the UI
 * can say what actually helps - which differs by WHICH number is bad. A weak
 * RSRP is a distance and antenna problem; a good RSRP with a bad SINR is
 * interference or a congested cell, and moving the antenna will not fix it.
 */
export type SignalLevel = 'excellent' | 'good' | 'fair' | 'poor';

export interface SignalRating {
  level: SignalLevel;
  /** Suffix of the i18n key with the advice: `signal.advice_<key>`. */
  advice: string;
  /** The band this reading falls into, for the tooltip's scale. */
  scale: string;
}

/** up = nothing to do, warning = worth improving, down = acts up under load. */
export function signalTone(level: SignalLevel): 'up' | 'warning' | 'down' {
  if (level === 'excellent' || level === 'good') return 'up';
  return level === 'fair' ? 'warning' : 'down';
}

function rate(value: number, bands: [number, SignalLevel][], advice: string, scale: string): SignalRating {
  for (const [threshold, level] of bands) {
    if (value >= threshold)
      return { level, advice: level === 'excellent' || level === 'good' ? 'none' : advice, scale };
  }
  return { level: 'poor', advice, scale };
}

/**
 * Reference Signal Received Power: how strong the cell's signal is here.
 * Distance, walls and antenna placement move this number.
 */
export function rateRsrp(dbm: number | null | undefined): SignalRating | null {
  if (typeof dbm !== 'number' || !Number.isFinite(dbm)) return null;
  return rate(
    dbm,
    [
      [-80, 'excellent'],
      [-90, 'good'],
      [-100, 'fair'],
      [-110, 'poor'],
    ],
    'rsrp',
    '≥ -80 / -90 / -100 / -110 dBm'
  );
}

/**
 * Reference Signal Received Quality: how much of what arrives is the signal
 * rather than everything else on the same frequency.
 */
export function rateRsrq(db: number | null | undefined): SignalRating | null {
  if (typeof db !== 'number' || !Number.isFinite(db)) return null;
  return rate(
    db,
    [
      [-10, 'excellent'],
      [-15, 'good'],
      [-20, 'fair'],
    ],
    'rsrq',
    '≥ -10 / -15 / -20 dB'
  );
}

/** Signal-to-noise ratio: how much throughput the link can actually carry. */
export function rateSinr(db: number | null | undefined): SignalRating | null {
  if (typeof db !== 'number' || !Number.isFinite(db)) return null;
  return rate(
    db,
    [
      [20, 'excellent'],
      [13, 'good'],
      [0, 'fair'],
    ],
    'sinr',
    '≥ 20 / 13 / 0 dB'
  );
}

/**
 * Wi-Fi noise floor. A radio hears everything on its channel; the higher
 * (less negative) this is, the less room the useful signal has.
 */
export function rateWifiNoise(dbm: number | null | undefined): SignalRating | null {
  if (typeof dbm !== 'number' || !Number.isFinite(dbm)) return null;
  return rate(
    -dbm,
    [
      [92, 'excellent'],
      [85, 'good'],
      [80, 'fair'],
    ],
    'noise',
    '≤ -92 / -85 / -80 dBm'
  );
}

/** Share of airtime the channel is busy. Above half, clients start waiting. */
export function rateChannelBusy(pct: number | null | undefined): SignalRating | null {
  if (typeof pct !== 'number' || !Number.isFinite(pct)) return null;
  return rate(
    -pct,
    [
      [-20, 'excellent'],
      [-40, 'good'],
      [-60, 'fair'],
    ],
    'busy',
    '≤ 20 / 40 / 60 %'
  );
}

/** The rating for a metric key, when that metric has a physical scale at all. */
export function rateSignalMetric(metricKey: string, value: number | null | undefined): SignalRating | null {
  if (metricKey === 'lte_rsrp') return rateRsrp(value);
  if (metricKey === 'lte_rsrq') return rateRsrq(value);
  if (metricKey === 'lte_sinr') return rateSinr(value);
  return null;
}

/**
 * The one thing worth doing about an LTE link, given all three numbers.
 *
 * Read together they say something none of them says alone: strong signal with
 * poor quality is a congested or interfered cell, and no amount of moving the
 * antenna closer to a window fixes that.
 */
export function lteVerdict(
  rsrp: number | null | undefined,
  rsrq: number | null | undefined,
  sinr: number | null | undefined
): { level: SignalLevel; advice: string } | null {
  const power = rateRsrp(rsrp);
  const quality = rateRsrq(rsrq);
  const noise = rateSinr(sinr);
  const rated = [power, quality, noise].filter((r): r is SignalRating => r !== null);
  if (rated.length === 0) return null;

  const order: SignalLevel[] = ['poor', 'fair', 'good', 'excellent'];
  const level = rated.reduce(
    (worst, r) => (order.indexOf(r.level) < order.indexOf(worst) ? r.level : worst),
    'excellent' as SignalLevel
  );

  const powerFine = power != null && (power.level === 'excellent' || power.level === 'good');
  const qualityBad =
    (quality != null && (quality.level === 'fair' || quality.level === 'poor')) ||
    (noise != null && (noise.level === 'fair' || noise.level === 'poor'));
  if (powerFine && qualityBad) return { level, advice: 'interference' };
  if (power != null && (power.level === 'fair' || power.level === 'poor')) return { level, advice: 'rsrp' };
  return { level, advice: level === 'excellent' || level === 'good' ? 'none' : 'rsrq' };
}
