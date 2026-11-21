import { describe, expect, it } from 'vitest';
import { chartCoverageStart, coverageStart, formatCoverageDay } from './window-coverage';

describe('Pokrytí dlouhých oken (W1-B2)', () => {
  it('okno začíná před prvními daty: vrátí den, od kdy data jsou', () => {
    expect(coverageStart('2026-08-12', '2026-06-26')).toBe('2026-08-12');
  });

  it('pokryté okno nebo chybějící údaj ze serveru: žádná poznámka, nic se nedomýšlí', () => {
    expect(coverageStart('2026-06-26', '2026-06-26')).toBeNull();
    expect(coverageStart('2026-05-01', '2026-06-26')).toBeNull();
    expect(coverageStart(null, '2026-06-26')).toBeNull();
    expect(coverageStart('2026-08-12', undefined)).toBeNull();
    expect(coverageStart('12. 8. 2026', '2026-06-26')).toBeNull();
  });

  it('datum česky „12. 8.“, anglicky „12 Aug“, rok jen když není letošní', () => {
    const now = new Date(2026, 8, 23);
    expect(formatCoverageDay('2026-08-12', 'cs', now)).toBe('12. 8.');
    expect(formatCoverageDay('2026-08-12', 'en', now)).toBe('12 Aug');
    expect(formatCoverageDay('2025-11-03', 'cs', now)).toBe('3. 11. 2025');
  });

  it('graf: první denní bod 40 dní zpět v okně 90 dní je poznámka, bod na začátku okna ne', () => {
    const now = new Date(2026, 8, 23, 14, 0).getTime();
    const day = (daysBack: number) => new Date(2026, 8, 23 - daysBack).getTime() / 1000;
    expect(chartCoverageStart(day(40), 90, now)?.getDate()).toBe(14);
    expect(chartCoverageStart(day(89), 90, now)).toBeNull();
    // A day of slack for a server midnight in another time zone.
    expect(chartCoverageStart(day(88), 90, now)).toBeNull();
    expect(chartCoverageStart(null, 90, now)).toBeNull();
  });
});
