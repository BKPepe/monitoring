import { describe, expect, it } from 'vitest';
import { lteVerdict, rateChannelBusy, rateRsrp, rateRsrq, rateSinr, rateWifiNoise, signalTone } from './signal-quality';

describe('rateRsrp', () => {
  it('reads the usual LTE bands', () => {
    expect(rateRsrp(-70)?.level).toBe('excellent');
    // The number that prompted this: -84 dBm is a good link, not a problem.
    expect(rateRsrp(-84)?.level).toBe('good');
    expect(rateRsrp(-95)?.level).toBe('fair');
    expect(rateRsrp(-105)?.level).toBe('poor');
    expect(rateRsrp(-120)?.level).toBe('poor');
  });

  it('puts every boundary in the better band', () => {
    expect(rateRsrp(-80)?.level).toBe('excellent');
    expect(rateRsrp(-90)?.level).toBe('good');
    expect(rateRsrp(-100)?.level).toBe('fair');
  });

  it('has no verdict without a measurement', () => {
    expect(rateRsrp(null)).toBeNull();
    expect(rateRsrp(undefined)).toBeNull();
    expect(rateRsrp(NaN)).toBeNull();
  });

  it('offers advice only where there is something to fix', () => {
    expect(rateRsrp(-70)?.advice).toBe('none');
    expect(rateRsrp(-105)?.advice).toBe('rsrp');
  });
});

describe('rateRsrq and rateSinr', () => {
  it('rates quality on its own scale', () => {
    expect(rateRsrq(-8)?.level).toBe('excellent');
    expect(rateRsrq(-12)?.level).toBe('good');
    expect(rateRsrq(-17)?.level).toBe('fair');
    expect(rateRsrq(-25)?.level).toBe('poor');
  });

  it('rates signal-to-noise on its own scale', () => {
    expect(rateSinr(25)?.level).toBe('excellent');
    expect(rateSinr(15)?.level).toBe('good');
    expect(rateSinr(5)?.level).toBe('fair');
    expect(rateSinr(-3)?.level).toBe('poor');
  });
});

describe('Wi-Fi', () => {
  it('rates a noise floor, where less is better', () => {
    expect(rateWifiNoise(-95)?.level).toBe('excellent');
    expect(rateWifiNoise(-88)?.level).toBe('good');
    expect(rateWifiNoise(-82)?.level).toBe('fair');
    expect(rateWifiNoise(-70)?.level).toBe('poor');
  });

  it('rates channel airtime, where less is better', () => {
    expect(rateChannelBusy(10)?.level).toBe('excellent');
    expect(rateChannelBusy(35)?.level).toBe('good');
    expect(rateChannelBusy(55)?.level).toBe('fair');
    expect(rateChannelBusy(80)?.level).toBe('poor');
  });
});

describe('lteVerdict', () => {
  it('says nothing without a single measurement', () => {
    expect(lteVerdict(null, null, null)).toBeNull();
  });

  it('takes the worst of the three as the verdict', () => {
    expect(lteVerdict(-70, -8, -5)?.level).toBe('poor');
    expect(lteVerdict(-84, -12, 18)?.level).toBe('good');
  });

  // The distinction the whole helper exists for: a strong signal with bad
  // quality is interference, and moving the antenna will not fix it.
  it('separates a weak signal from a noisy cell', () => {
    expect(lteVerdict(-105, -9, 20)?.advice).toBe('rsrp');
    expect(lteVerdict(-75, -19, 3)?.advice).toBe('interference');
  });

  it('has no advice when everything is fine', () => {
    expect(lteVerdict(-70, -8, 25)?.advice).toBe('none');
  });

  it('works from a single measurement', () => {
    expect(lteVerdict(-84, null, null)).toEqual({ level: 'good', advice: 'none' });
  });
});

describe('signalTone', () => {
  it('maps a rating onto the status colours the app already uses', () => {
    expect(signalTone('excellent')).toBe('up');
    expect(signalTone('good')).toBe('up');
    expect(signalTone('fair')).toBe('warning');
    expect(signalTone('poor')).toBe('down');
  });
});
