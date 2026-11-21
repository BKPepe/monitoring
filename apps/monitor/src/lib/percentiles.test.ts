import { describe, expect, it } from 'vitest';
import { percentile } from './percentiles';

describe('percentile', () => {
  const values = Array.from({ length: 100 }, (_, i) => i + 1); // 1..100

  it('returns a value that was actually measured', () => {
    expect(percentile(values, 50)).toBe(50);
    expect(percentile(values, 95)).toBe(95);
    expect(percentile(values, 99)).toBe(99);
  });

  it('keeps the extremes at the edges', () => {
    expect(percentile(values, 0)).toBe(1);
    expect(percentile(values, 100)).toBe(100);
  });

  it('has no answer without measurements', () => {
    expect(percentile([], 95)).toBeNull();
    expect(percentile([NaN, Infinity], 95)).toBeNull();
  });

  it('ignores what was never measured instead of counting it as zero', () => {
    expect(percentile([10, NaN, 20, Infinity, 30], 50)).toBe(20);
  });

  it('does not care about input order', () => {
    expect(percentile([5, 1, 4, 2, 3], 60)).toBe(3);
  });

  // The tail is the point: one timeout must not drag p95 to itself.
  it('keeps a single outlier out of p95', () => {
    const latency = [...Array.from({ length: 99 }, () => 40), 8000];
    expect(percentile(latency, 95)).toBe(40);
    expect(percentile(latency, 100)).toBe(8000);
  });

  it('works on a single sample', () => {
    expect(percentile([7], 95)).toBe(7);
  });
});
