import { describe, expect, it } from 'vitest';
import { convertRate, formatRate, suggestRateUnit } from './rate-units';

describe('rate units', () => {
  it('converts KB/s to bit units the way links are quoted', () => {
    // 125 000 KB/s is a saturated gigabit line: 125000 * 1024 * 8 = 1.024 Gb/s.
    expect(convertRate(125000, 'Gb/s')).toBe(1.024);
    expect(convertRate(125000, 'Mb/s')).toBe(1024);
    // 1024 KB/s = 1 MB/s = 8.389 Mb/s
    expect(convertRate(1024, 'MB/s')).toBe(1);
    expect(convertRate(1024, 'Mb/s')).toBe(8.39);
  });

  it('keeps KB/s untouched', () => {
    expect(convertRate(1499.7, 'KB/s')).toBe(1499.7);
  });

  it('never turns an unmeasured value into a zero', () => {
    // The whole point: a missing reading has to stay missing through the
    // conversion, otherwise a dead agent looks like an idle link.
    expect(convertRate(null, 'Mb/s')).toBeNull();
    expect(convertRate(undefined, 'Mb/s')).toBeNull();
    expect(convertRate(Number.NaN, 'Mb/s')).toBeNull();
    expect(formatRate(null, 'Mb/s')).toBe('—');
  });

  it('suggests the unit that reads best', () => {
    expect(suggestRateUnit(0)).toBe('KB/s');
    expect(suggestRateUnit(50)).toBe('KB/s');
    expect(suggestRateUnit(1499.7)).toBe('Mb/s');
    expect(suggestRateUnit(200000)).toBe('Gb/s');
    // No data means no basis for a guess - fall back to the source unit.
    expect(suggestRateUnit(null)).toBe('KB/s');
  });
});
