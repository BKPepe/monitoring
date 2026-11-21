import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { formatPercentValue } from './percent';

describe('formatPercentValue: výpadek se nezaokrouhlí na 100', () => {
  it('bez locale je to holé číslo pro CSV a atributy', () => {
    expect(formatPercentValue(99.999, 2)).toBe('99.99');
    expect(formatPercentValue(99.96, 1)).toBe('99.9');
    expect(formatPercentValue(99.5)).toBe('99');
    expect(formatPercentValue(99.9, 2)).toBe('99.90');
    expect(formatPercentValue(100, 2)).toBe('100.00');
  });

  it('s locale ho píše tak, jak čísla píše ten jazyk', () => {
    expect(formatPercentValue(99.999, 2, 'cs-CZ')).toBe('99,99');
    expect(formatPercentValue(99.995, 2, 'en-GB')).toBe('99.99');
    expect(formatPercentValue(97.66, 1, 'cs-CZ')).toBe('97,7');
    expect(formatPercentValue(98.187, 2, 'en-GB')).toBe('98.19');
    expect(formatPercentValue(100, 1, 'cs-CZ')).toBe('100,0');
  });

  it('jazyk aplikace a locale webu píšou číslo stejně', () => {
    for (const value of [0, 12.4, 57.25, 97.66, 99.994, 99.999, 100]) {
      for (const digits of [0, 1, 2]) {
        expect(formatPercentValue(value, digits, 'cs')).toBe(formatPercentValue(value, digits, 'cs-CZ'));
        expect(formatPercentValue(value, digits, 'en')).toBe(formatPercentValue(value, digits, 'en-GB'));
      }
    }
  });

  it('nic neimportuje, web ho bere přímo ze zdroje aplikace', () => {
    const source = readFileSync(join(__dirname, 'percent.ts'), 'utf8');
    expect(source).not.toMatch(/^\s*(import|export .* from)\b/m);
  });
});
