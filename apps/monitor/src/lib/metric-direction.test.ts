import { describe, expect, it } from 'vitest';
import { betterDirection } from './metric-direction';

describe('betterDirection', () => {
  // The case this module was written for: on a dBm scale the tail that hurts
  // is the low one, so p95 is the good end, not "the worse end".
  it('knows a stronger signal is better', () => {
    expect(betterDirection('lte_rsrp')).toBe('higher');
    expect(betterDirection('lte_rsrq')).toBe('higher');
    expect(betterDirection('lte_sinr')).toBe('higher');
  });

  it('knows free memory and a charged battery are better higher', () => {
    expect(betterDirection('ram_free_mb')).toBe('higher');
    expect(betterDirection('ups_battery_pct')).toBe('higher');
    expect(betterDirection('entropy')).toBe('higher');
  });

  it('knows load, latency and saturation are better lower', () => {
    expect(betterDirection('response_time')).toBe('lower');
    expect(betterDirection('cpu')).toBe('lower');
    expect(betterDirection('ram')).toBe('lower');
    expect(betterDirection('temperature_c')).toBe('lower');
    expect(betterDirection('conntrack')).toBe('lower');
  });

  // Used memory and free memory are the same fact read from two ends; they
  // must not both be "lower is better".
  it('does not contradict itself between a used and a free measure', () => {
    expect(betterDirection('ram')).toBe('lower');
    expect(betterDirection('ram_free_mb')).toBe('higher');
  });

  it('refuses to judge traffic and headcounts', () => {
    expect(betterDirection('net')).toBe('neutral');
    expect(betterDirection('net_lte')).toBe('neutral');
    expect(betterDirection('mc_players')).toBe('neutral');
    expect(betterDirection('wifi_clients')).toBe('neutral');
  });

  it('treats an unknown metric as neutral rather than guessing', () => {
    expect(betterDirection('something_new')).toBe('neutral');
    expect(betterDirection('')).toBe('neutral');
  });
});
