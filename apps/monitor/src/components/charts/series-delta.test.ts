import { describe, expect, it } from 'vitest';
import { computeSeriesDelta, goodDirectionFor } from './series-delta';
import type { MetricSeries } from '@/api/types';

/**
 * Trend metriky: průměr poslední čtvrtiny vs první čtvrtiny bodů.
 * Pravidla poctivosti: málo dat → null, růst z ~nuly → null (žádné
 * „+9000 %"), nulová změna → null.
 */

const series = (values: (number | null)[]): MetricSeries =>
  ({ points: values.map((v, i) => ({ t: i, v })) }) as unknown as MetricSeries;

describe('computeSeriesDelta', () => {
  it('méně než 8 naměřených bodů → null', () => {
    expect(computeSeriesDelta(series([1, 2, 3, 4, 5, 6, 7]))).toBeNull();
  });

  it('null body se nepočítají mezi měření', () => {
    expect(computeSeriesDelta(series([1, 2, 3, null, null, null, 4, 5, 6, 7]))).toBeNull();
  });

  it('rostoucí řada hlásí růst v procentech', () => {
    const d = computeSeriesDelta(series([10, 10, 10, 10, 20, 20, 20, 20]));
    expect(d).toEqual({ pct: 100, direction: 'up' });
  });

  it('klesající řada hlásí pokles', () => {
    const d = computeSeriesDelta(series([40, 40, 40, 40, 30, 30, 30, 30]));
    expect(d).toEqual({ pct: 25, direction: 'down' });
  });

  it('základ blízko nule → null místo absurdního procenta', () => {
    expect(computeSeriesDelta(series([0, 0, 0, 0, 50, 50, 50, 50]))).toBeNull();
  });

  it('beze změny → null (UI deltu prostě nevypíše)', () => {
    expect(computeSeriesDelta(series([5, 5, 5, 5, 5, 5, 5, 5]))).toBeNull();
  });

  it('chybějící série → null', () => {
    expect(computeSeriesDelta(undefined)).toBeNull();
  });
});

describe('goodDirectionFor', () => {
  it('u zátěžových metrik je pokles dobrá zpráva', () => {
    for (const tone of ['cpu', 'memory', 'disk', 'latency', 'temperature'] as const) {
      expect(goodDirectionFor(tone)).toBe('down');
    }
  });

  it('síť a neznámé metriky jsou neutrální', () => {
    expect(goodDirectionFor(undefined)).toBeNull();
  });
});
