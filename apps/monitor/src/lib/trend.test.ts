import { describe, expect, it } from 'vitest';
import { measuredMean, trendDelta } from './trend';

const flat = (v: number, n = 10) => Array.from({ length: n }, () => v);
const limits = { warning: 80, critical: 90 };

describe('measuredMean', () => {
  it('počítá jen naměřené hodnoty', () => {
    expect(measuredMean([1, null, 3, undefined, 5])).toBe(3);
  });

  it('ze dvou měření průměr nedělá', () => {
    expect(measuredMean([1, 2])).toBeNull();
    expect(measuredMean([])).toBeNull();
  });
});

describe('trendDelta', () => {
  it('u procentní metriky mluví v procentních bodech', () => {
    const d = trendDelta({ metricKey: 'cpu', unit: '%', current: flat(14), previous: flat(12) });
    expect(d).toEqual({ value: 2, unit: 'pp', direction: 'up', tone: 'neutral' });
  });

  it('noční a denní hodnoty nemíchá - porovnává s předchozím obdobím', () => {
    // Evening load against the same evening a day earlier: no change at all.
    const today = [5, 5, 5, 20, 20, 20];
    const yesterday = [5, 5, 5, 20, 20, 20];
    expect(trendDelta({ metricKey: 'cpu', unit: '%', current: today, previous: yesterday })).toBeNull();
  });

  it('u záporných dBm počítá z absolutní hodnoty - zlepšení je růst', () => {
    const d = trendDelta({ metricKey: 'lte_rsrp', unit: 'dBm', current: flat(-90), previous: flat(-100) });
    expect(d).toEqual({ value: 10, unit: 'pct', direction: 'up', tone: 'neutral' });
  });

  it('bez překročené meze zůstává změna neutrální, i když je velká', () => {
    const d = trendDelta({ metricKey: 'cpu', unit: '%', current: flat(40), previous: flat(10), thresholds: limits });
    expect(d?.tone).toBe('neutral');
  });

  it('překročení meze je špatná zpráva, návrat pod ni dobrá', () => {
    const worse = trendDelta({
      metricKey: 'cpu',
      unit: '%',
      current: flat(85),
      previous: flat(70),
      thresholds: limits,
    });
    expect(worse?.tone).toBe('bad');
    const better = trendDelta({
      metricKey: 'cpu',
      unit: '%',
      current: flat(70),
      previous: flat(95),
      thresholds: limits,
    });
    expect(better?.tone).toBe('good');
  });

  it('u metriky, kde je víc lépe, jsou meze dolní', () => {
    const d = trendDelta({
      metricKey: 'lte_rsrp',
      unit: 'dBm',
      current: flat(-112),
      previous: flat(-95),
      thresholds: { warning: -105, critical: -115 },
    });
    expect(d?.direction).toBe('down');
    expect(d?.tone).toBe('bad');
  });

  it('provoz nesoudí ani s mezí', () => {
    const d = trendDelta({
      metricKey: 'net',
      unit: 'KB/s',
      current: flat(900),
      previous: flat(100),
      thresholds: limits,
    });
    expect(d?.tone).toBe('neutral');
  });

  it('bez předchozího období neukáže nic - žádná vymyšlená změna', () => {
    expect(trendDelta({ metricKey: 'cpu', unit: '%', current: flat(12), previous: null })).toBeNull();
    expect(trendDelta({ metricKey: 'cpu', unit: '%', current: flat(12), previous: [1, null] })).toBeNull();
  });

  it('přijme průměr předchozího okna spočítaný serverem', () => {
    const d = trendDelta({ metricKey: 'ram', unit: '%', current: flat(50), previous: 45.5 });
    expect(d).toEqual({ value: 4.5, unit: 'pp', direction: 'up', tone: 'neutral' });
  });

  it('růst z nuly procentem nevyjadřuje', () => {
    expect(trendDelta({ metricKey: 'load1', unit: '', current: flat(2), previous: flat(0) })).toBeNull();
  });
});
