import { convertRate, isRateMetric, suggestRateUnit } from './rate-units';

/**
 * Metric values as people read them (charts-23).
 *
 * The same quantity came out three ways: "1204300 s" for how long the WAN
 * link had been up, "2500 Mbit/s" next to a speed test in "Mb/s", and raw
 * floats with no digit grouping. Every chart title, tooltip and tile goes
 * through here, in the UI language.
 */
type Lang = 'cs' | 'en' | string;

export function localeFor(lang: Lang): string {
  return lang === 'en' ? 'en-GB' : 'cs-CZ';
}

/** Digit grouping and at most `digits` decimals, without trailing zeros. */
export function formatNumber(value: number, lang: Lang, digits = 2): string {
  if (!Number.isFinite(value)) return '—';
  return new Intl.NumberFormat(localeFor(lang), { maximumFractionDigits: digits }).format(value);
}

const UNITS: readonly (readonly [number, string])[] = [
  [86_400, 'd'],
  [3_600, 'h'],
  [60, 'min'],
  [1, 's'],
];

/**
 * A duration in seconds as its two largest units: "13 d 22 h", "5 h 3 min",
 * "42 s". Nobody reads a link's uptime to the second, and "13 d 22 h 4 min
 * 11 s" is a number to decode rather than a glance.
 */
export function formatDuration(seconds: number, lang: Lang): string {
  if (!Number.isFinite(seconds)) return '—';
  let rest = Math.max(0, Math.round(Math.abs(seconds)));
  if (rest === 0) return '0 s';
  const parts: string[] = [];
  for (const [size, label] of UNITS) {
    if (parts.length === 2) break;
    const n = Math.floor(rest / size);
    // Once the largest unit is found the next one is shown even when it is
    // zero ("2 h 0 min" would be noise, so a zero second part is dropped).
    if (n > 0) {
      parts.push(`${formatNumber(n, lang, 0)} ${label}`);
      rest -= n * size;
    } else if (parts.length === 1) {
      break;
    }
  }
  return (seconds < 0 ? '−' : '') + parts.join(' ');
}

/**
 * One value with its unit, or a dash when nothing was measured.
 *
 * Throughput (the agents' KB/s) is shown in the unit that suits its size -
 * a busy line in Mbit/s, an idle one in KB/s - and a duration in days and
 * hours. Everything else keeps its own unit with locale digit grouping.
 */
export function formatMetricValue(
  value: number | null | undefined,
  unit: string,
  lang: Lang,
  /** false inside a chart that already picked one rate unit for its axis. */
  convertRates = true
): string {
  const { number, unit: shown } = formatMetricParts(value, unit, lang, convertRates);
  return shown ? `${number} ${shown}` : number;
}

/** The same, split, for a headline that prints the unit smaller than the number. */
export function formatMetricParts(
  value: number | null | undefined,
  unit: string,
  lang: Lang,
  convertRates = true
): { number: string; unit: string } {
  if (value == null || !Number.isFinite(value)) return { number: '—', unit: '' };
  if (unit === 's') return { number: formatDuration(value, lang), unit: '' };
  if (convertRates && isRateMetric(unit)) {
    const rateUnit = suggestRateUnit(value);
    const converted = convertRate(value, rateUnit);
    return converted == null ? { number: '—', unit: '' } : { number: formatNumber(converted, lang), unit: rateUnit };
  }
  if (unit === '%') return { number: formatNumber(value, lang, 1), unit: '%' };
  // Small values keep a decimal, large ones lose them: 3.4 ms, 1 205 ms.
  const digits = Math.abs(value) >= 100 ? 0 : Math.abs(value) >= 10 ? 1 : 2;
  return { number: formatNumber(value, lang, digits), unit };
}
