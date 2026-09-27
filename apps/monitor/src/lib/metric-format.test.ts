import { describe, expect, it } from 'vitest';
import { formatDuration, formatMetricValue, formatNumber } from './metric-format';

// Intl groups Czech digits with a no-break space; the tests compare plain text.
const plain = (s: string) => s.replace(/[\u00a0\u202f]/g, ' ');

describe('formatNumber', () => {
  it('seskupuje číslice podle jazyka', () => {
    expect(plain(formatNumber(1204300, 'cs'))).toBe('1 204 300');
    expect(formatNumber(1204300, 'en')).toBe('1,204,300');
  });

  it('píše desetinnou čárku v češtině a tečku v angličtině', () => {
    expect(formatNumber(12.5, 'cs')).toBe('12,5');
    expect(formatNumber(12.5, 'en')).toBe('12.5');
  });
});

describe('formatDuration', () => {
  it('ukáže dvě největší jednotky místo sekund', () => {
    expect(formatDuration(1204300, 'cs')).toBe('13 d 22 h');
    expect(formatDuration(5 * 3600 + 3 * 60 + 9, 'cs')).toBe('5 h 3 min');
    expect(formatDuration(42, 'en')).toBe('42 s');
  });

  it('nulovou druhou jednotku vynechá', () => {
    expect(formatDuration(2 * 3600, 'cs')).toBe('2 h');
    expect(formatDuration(0, 'cs')).toBe('0 s');
  });
});

describe('formatMetricValue', () => {
  it('neměřené je pomlčka, ne nula', () => {
    expect(formatMetricValue(null, '%', 'cs')).toBe('—');
    expect(formatMetricValue(undefined, 'ms', 'cs')).toBe('—');
    expect(formatMetricValue(Number.NaN, '', 'cs')).toBe('—');
  });

  it('dobu spojení ukáže jako dny a hodiny', () => {
    expect(formatMetricValue(1204300, 's', 'cs')).toBe('13 d 22 h');
  });

  it('propustnost v KB/s převede na Mbit/s, když je linka vytížená', () => {
    expect(plain(formatMetricValue(125000, 'KB/s', 'cs'))).toBe('1,02 Gbit/s');
    expect(formatMetricValue(1840.5, 'KB/s', 'en')).toBe('15.08 Mbit/s');
    expect(formatMetricValue(0.4, 'KB/s', 'en')).toBe('0.4 KB/s');
  });

  it('procenta na jedno desetinné místo, ostatní podle velikosti', () => {
    expect(formatMetricValue(12.44, '%', 'cs')).toBe('12,4 %');
    expect(plain(formatMetricValue(1205.4, 'ms', 'cs'))).toBe('1 205 ms');
    expect(formatMetricValue(3.456, 'ms', 'en')).toBe('3.46 ms');
    expect(formatMetricValue(27, '', 'cs')).toBe('27');
  });
});
