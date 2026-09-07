import { describe, expect, it } from 'vitest';
import { sparklineSegments } from './sparkline-segments';

const W = 100;
const H = 28;

describe('sparklineSegments', () => {
  it('draws one line when everything was measured', () => {
    const segments = sparklineSegments([1, 2, 3, 4], W, H);
    expect(segments).toHaveLength(1);
    expect(segments[0]).toHaveLength(4);
  });

  // The whole point: a collection outage must not be drawn as a smooth line
  // between the last measurement before it and the first one after.
  it('splits the line at a gap instead of joining across it', () => {
    const segments = sparklineSegments([1, 2, null, null, 5, 6], W, H);
    expect(segments).toHaveLength(2);
    expect(segments[0]).toHaveLength(2);
    expect(segments[1]).toHaveLength(2);
  });

  it('keeps the x position of a point after a gap, so time stays linear', () => {
    const segments = sparklineSegments([0, null, null, 10], W, H);
    // The last sample is the fourth of four: it belongs at the right edge,
    // not at the second position it would take after dropping the nulls.
    expect(segments).toHaveLength(0); // ...and a lone point on each side is no line
    const withPairs = sparklineSegments([0, 1, null, null, 9, 10], W, H);
    expect(withPairs[1][1].x).toBeCloseTo(W);
    expect(withPairs[0][0].x).toBeCloseTo(0);
  });

  it('drops a lone measurement between gaps rather than drawing a dot', () => {
    expect(sparklineSegments([1, 2, null, 7, null, 3, 4], W, H)).toHaveLength(2);
  });

  it('has nothing to draw when fewer than two samples were measured', () => {
    expect(sparklineSegments([null, null, null], W, H)).toEqual([]);
    expect(sparklineSegments([5], W, H)).toEqual([]);
    expect(sparklineSegments([5, null], W, H)).toEqual([]);
  });

  it('treats NaN and Infinity as not measured, never as a value', () => {
    const segments = sparklineSegments([1, 2, NaN, 4, 5], W, H);
    expect(segments).toHaveLength(2);
    expect(sparklineSegments([1, 2, Infinity, 4, 5], W, H)).toHaveLength(2);
  });

  it('keeps a constant series inside the box instead of dividing by zero', () => {
    const [segment] = sparklineSegments([3, 3, 3], W, H);
    for (const point of segment) {
      expect(Number.isFinite(point.y)).toBe(true);
      expect(point.y).toBeGreaterThanOrEqual(0);
      expect(point.y).toBeLessThanOrEqual(H);
    }
  });

  it('puts the maximum above the minimum, inside the padded box', () => {
    const [segment] = sparklineSegments([0, 100], W, H);
    expect(segment[1].y).toBeLessThan(segment[0].y);
    expect(segment[1].y).toBeCloseTo(2);
    expect(segment[0].y).toBeCloseTo(H - 2);
  });
});
