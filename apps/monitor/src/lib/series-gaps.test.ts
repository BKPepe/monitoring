import { describe, expect, it } from 'vitest';
import { insertGaps, medianStep } from './series-gaps';

/** How many gap markers insertGaps added. */
const countGaps = (points: Parameters<typeof insertGaps>[0]) => insertGaps(points).length - points.length;

const minute = 60_000;
const at = (minutes: number, v: number | null = 1) => ({ t: minutes * minute, v });

describe('medianStep', () => {
  it('reads the cadence from the data', () => {
    expect(medianStep([at(0), at(1), at(2), at(3)])).toBe(minute);
  });

  it('has no cadence to report for a single point', () => {
    expect(medianStep([at(0)])).toBeNull();
    expect(medianStep([])).toBeNull();
  });

  it('ignores duplicate timestamps rather than dividing by a zero step', () => {
    expect(medianStep([at(0), at(0), at(1), at(2)])).toBe(minute);
  });
});

describe('insertGaps', () => {
  it('leaves an evenly sampled series untouched', () => {
    const points = [at(0), at(1), at(2), at(3)];
    expect(insertGaps(points)).toHaveLength(4);
  });

  // The rule this whole module exists for: a hole must not be drawn as a line.
  it('marks a hole with an explicit null', () => {
    const points = [at(0), at(1), at(2), at(180), at(181)];
    const out = insertGaps(points);
    expect(out).toHaveLength(6);
    const marker = out[3];
    expect(marker.v).toBeNull();
    expect(marker.t).toBe(3 * minute);
  });

  it('never moves, drops or rewrites a measured sample', () => {
    const points = [at(0, 10), at(1, 11), at(240, 12)];
    const measured = insertGaps(points).filter((p) => p.v != null);
    expect(measured).toEqual(points);
  });

  // A per-minute check that arrives a few seconds late is not an outage.
  it('does not tear on scheduler jitter', () => {
    const points = [
      { t: 0, v: 1 },
      { t: 61_000, v: 1 },
      { t: 118_000, v: 1 },
      { t: 185_000, v: 1 },
    ];
    expect(countGaps(points)).toBe(0);
  });

  // A daily rollup steps by 24 hours; that must not read as a gap.
  it('scales to a daily series', () => {
    const day = 86_400_000;
    const daily = [0, 1, 2, 3, 4].map((d) => ({ t: d * day, v: 5 }));
    expect(countGaps(daily)).toBe(0);
    const withHole = [...daily, { t: 10 * day, v: 5 }];
    expect(countGaps(withHole)).toBe(1);
  });

  it('does not mark a hole that already ends in a null', () => {
    const points = [at(0), at(1), { t: 300 * minute, v: null }, at(301)];
    expect(countGaps(points)).toBe(0);
  });

  it('has nothing to do without a cadence', () => {
    expect(insertGaps([at(0)])).toHaveLength(1);
    expect(insertGaps([])).toHaveLength(0);
  });
});
