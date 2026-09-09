import { describe, expect, it } from 'vitest';
import { metricVerdict } from './metric-verdict';

const none = { warning: null, critical: null };
const values = Array.from({ length: 100 }, (_, i) => i + 1); // 1..100

describe('metricVerdict', () => {
  it('has nothing to say without a current value', () => {
    expect(metricVerdict({ metricKey: 'cpu', current: null, values, thresholds: none })).toBeNull();
  });

  it('prefers a configured threshold over anything else', () => {
    const t = { warning: 70, critical: 90 };
    expect(metricVerdict({ metricKey: 'cpu', current: 95, values, thresholds: t })?.kind).toBe('threshold_critical');
    expect(metricVerdict({ metricKey: 'cpu', current: 75, values, thresholds: t })?.kind).toBe('threshold_warning');
    expect(metricVerdict({ metricKey: 'cpu', current: 20, values, thresholds: t })?.kind).toBe('threshold_ok');
  });

  it('names the number it judged against', () => {
    const v = metricVerdict({ metricKey: 'cpu', current: 95, values, thresholds: { warning: 70, critical: 90 } });
    expect(v?.against).toBe(90);
  });

  // A ceiling makes no sense for a metric where more is better - a signal
  // strength above a threshold is good news.
  it('ignores ceilings on a metric where more is better', () => {
    const v = metricVerdict({
      metricKey: 'lte_rsrp',
      current: -70,
      values: Array.from({ length: 50 }, () => -90),
      thresholds: { warning: -100, critical: -110 },
    });
    expect(v?.kind).not.toBe('threshold_critical');
  });

  it('falls back to comparing against the window', () => {
    expect(metricVerdict({ metricKey: 'response_time', current: 99, values, thresholds: none })?.kind).toBe('unusual');
    expect(metricVerdict({ metricKey: 'response_time', current: 40, values, thresholds: none })?.kind).toBe('usual');
  });

  // The whole point of the direction table: for signal the bad tail is the low one.
  it('compares against the correct tail for a signal metric', () => {
    const rsrp = Array.from({ length: 100 }, (_, i) => -120 + i); // -120..-21
    expect(metricVerdict({ metricKey: 'lte_rsrp', current: -118, values: rsrp, thresholds: none })?.kind).toBe(
      'unusual'
    );
    expect(metricVerdict({ metricKey: 'lte_rsrp', current: -40, values: rsrp, thresholds: none })?.kind).toBe('usual');
  });

  it('refuses to judge a metric with no good direction', () => {
    expect(metricVerdict({ metricKey: 'net', current: 900, values, thresholds: none })?.kind).toBe('none');
    expect(metricVerdict({ metricKey: 'mc_players', current: 40, values, thresholds: none })?.kind).toBe('none');
  });

  // The healthiest possible reading: swap at 0 all day, no zombies, no steal.
  // A nearest-rank percentile of a constant series is that constant, so the
  // comparison used to make "perfect" a permanent amber warning.
  it('has no verdict on a window that never moved', () => {
    const flat = Array.from({ length: 50 }, () => 0);
    expect(metricVerdict({ metricKey: 'swap', current: 0, values: flat, thresholds: none })?.kind).toBe('none');
    const pinned = Array.from({ length: 50 }, () => 256);
    expect(metricVerdict({ metricKey: 'entropy', current: 256, values: pinned, thresholds: none })?.kind).toBe('none');
  });

  it('being exactly at the tail is not being past it', () => {
    expect(metricVerdict({ metricKey: 'response_time', current: 95, values, thresholds: none })?.kind).toBe('usual');
    expect(metricVerdict({ metricKey: 'response_time', current: 96, values, thresholds: none })?.kind).toBe('unusual');
  });

  // The sentence on the page names the configured limit, so the verdict has to
  // carry it - the warning band is derived from it, not typed by anyone.
  it('carries the configured limit alongside the band it compared with', () => {
    const v = metricVerdict({ metricKey: 'cpu', current: 75, values, thresholds: { warning: 70, critical: 90 } });
    expect(v?.against).toBe(70);
    expect(v?.configured).toBe(90);
  });

  it('refuses to judge on too few samples', () => {
    expect(metricVerdict({ metricKey: 'cpu', current: 50, values: [1, 2, 3], thresholds: none })?.kind).toBe('none');
  });
});
