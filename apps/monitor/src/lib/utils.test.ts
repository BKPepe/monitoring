import { describe, expect, it } from 'vitest';
import { formatPercent } from './utils';

describe('formatPercent: výpadek se nezaokrouhlí na 100 %', () => {
  it('99,999 % na dvě místa je 99,99 %, ne 100,00 %', () => {
    expect(formatPercent(99.999, 2)).toBe('99.99 %');
    expect(formatPercent(99.96, 1)).toBe('99.9 %');
    expect(formatPercent(99.5)).toBe('99 %');
  });

  it('čistých 100 % zůstane 100 a jinak se zaokrouhluje normálně', () => {
    expect(formatPercent(100, 2)).toBe('100.00 %');
    expect(formatPercent(99.994, 2)).toBe('99.99 %');
    expect(formatPercent(57.25, 1)).toBe('57.3 %');
    expect(formatPercent(12.4)).toBe('12 %');
  });

  it('neměřeno je pomlčka', () => {
    expect(formatPercent(null, 2)).toBe('—');
    expect(formatPercent(undefined)).toBe('—');
  });
});
