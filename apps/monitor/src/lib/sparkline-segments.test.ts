import { describe, expect, it } from 'vitest';
import { defaultMinRange, sparklineGeometry, type SparkSample } from './sparkline-segments';

const W = 100;
const H = 28;
const minute = 60_000;
/** One sample per minute, `null` where nothing was measured. */
const perMinute = (values: (number | null)[]): SparkSample[] => values.map((v, i) => ({ t: i * minute, v }));

describe('sparklineGeometry', () => {
  it('nakreslí jednu čáru, když bylo změřeno všechno', () => {
    const { segments } = sparklineGeometry(perMinute([1, 2, 3, 4]), W, H);
    expect(segments).toHaveLength(1);
    expect(segments[0]).toHaveLength(4);
  });

  it('přeruší čáru v díře místo spojení přes ni', () => {
    const { segments } = sparklineGeometry(perMinute([1, 2, null, null, 5, 6]), W, H);
    expect(segments).toHaveLength(2);
  });

  it('dlouhý krok mezi vzorky je díra, i když v datech žádná null není', () => {
    // The endpoints return only rows that have a value: a two-hour outage
    // arrives as two neighbouring samples.
    const samples = [...perMinute([1, 2, 3, 4]), { t: 120 * minute, v: 5 }, { t: 121 * minute, v: 6 }];
    const { segments } = sparklineGeometry(samples, W, H);
    expect(segments).toHaveLength(2);
    // ...and the hole is as wide as the outage: the second run sits at the right edge.
    expect(segments[1][0].x).toBeCloseTo((120 / 121) * W);
  });

  it('osa x je čas okna - ticho na konci okna zůstane prázdné', () => {
    const window = { from: 0, to: 60 * minute };
    const { segments } = sparklineGeometry(perMinute([1, 2, 3, 4]), W, H, { window });
    const lastX = segments[0][segments[0].length - 1].x;
    expect(lastX).toBeCloseTo((3 / 60) * W);
  });

  it('vzorky mimo okno nekreslí', () => {
    const window = { from: 2 * minute, to: 3 * minute };
    const { segments, first, last } = sparklineGeometry(perMinute([9, 9, 1, 2, 9]), W, H, { window });
    expect(segments[0]).toHaveLength(2);
    expect([first, last]).toEqual([1, 2]);
  });

  it('šum kolem stálé hodnoty zůstane plochý', () => {
    // 12.4-12.6 % used to fill the whole height.
    const { segments } = sparklineGeometry(perMinute([12.4, 12.6, 12.4, 12.6]), W, H, { minRange: 10 });
    const ys = segments[0].map((p) => p.y);
    expect(Math.max(...ys) - Math.min(...ys)).toBeLessThan(H * 0.05);
  });

  it('skutečnou změnu nakreslí přes celou výšku', () => {
    const { segments } = sparklineGeometry(perMinute([0, 100]), W, H);
    expect(segments[0][1].y).toBeCloseTo(2);
    expect(segments[0][0].y).toBeCloseTo(H - 2);
  });

  it('konstantní řada zůstane uvnitř rámečku', () => {
    const { segments } = sparklineGeometry(perMinute([0, 0, 0]), W, H);
    for (const point of segments[0]) {
      expect(point.y).toBeGreaterThanOrEqual(0);
      expect(point.y).toBeLessThanOrEqual(H);
    }
  });

  it('osamělé měření mezi dírami není čára', () => {
    expect(sparklineGeometry(perMinute([1, 2, null, 7, null, 3, 4]), W, H).segments).toHaveLength(2);
  });

  it('z méně než dvou měření nic nekreslí a neuvádí hodnoty', () => {
    expect(sparklineGeometry(perMinute([null, 5, null]), W, H)).toEqual({ segments: [], first: null, last: null });
  });

  it('NaN a Infinity nejsou hodnota', () => {
    expect(sparklineGeometry(perMinute([1, 2, Number.NaN, 4, 5]), W, H).segments).toHaveLength(2);
  });

  it('vrátí první a poslední naměřenou hodnotu pro popisky', () => {
    const { first, last } = sparklineGeometry(perMinute([null, 3, 4, 8, null]), W, H);
    expect([first, last]).toEqual([3, 8]);
  });
});

describe('defaultMinRange', () => {
  it('je pětina úrovně řady, u nuly jednotka', () => {
    expect(defaultMinRange([-100, -98])).toBe(20);
    expect(defaultMinRange([0, 0])).toBe(1);
  });
});
