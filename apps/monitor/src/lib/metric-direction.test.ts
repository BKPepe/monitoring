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

  it('knows a noisy or busy Wi-Fi channel is the bad end, on every band', () => {
    for (const band of ['24g', '5g', '6g']) {
      // Noise is negative dBm: -95 is better than -80, so lower is better and
      // p95 is the end that hurts - the opposite of an LTE signal.
      expect(betterDirection(`wifi_noise_${band}`)).toBe('lower');
      expect(betterDirection(`wifi_busy_${band}`)).toBe('lower');
      expect(betterDirection(`wifi_busy_other_${band}`)).toBe('lower');
    }
    expect(betterDirection('wifi_weak_clients')).toBe('lower');
    expect(betterDirection('wifi_wpa2_clients')).toBe('lower');
    expect(betterDirection('wifi_6e_unserved')).toBe('lower');
  });

  // A 2.4 GHz client that could use 5 GHz is a fact about the client, not a
  // fault: more of them is neither good nor bad.
  it('does not judge how many 2.4 GHz clients could use 5 GHz', () => {
    expect(betterDirection('wifi_5g_capable_24g')).toBe('neutral');
  });

  it('knows a saturated core, port errors, drops and link flaps are better lower', () => {
    expect(betterDirection('cpu_core_max')).toBe('lower');
    expect(betterDirection('cpu_core_max_softirq')).toBe('lower');
    expect(betterDirection('wan_errors')).toBe('lower');
    expect(betterDirection('wan_drops')).toBe('lower');
    expect(betterDirection('wan_ring_drops')).toBe('lower');
    expect(betterDirection('conntrack_drops')).toBe('lower');
    expect(betterDirection('wan_link_flaps')).toBe('lower');
  });

  it('knows a slow agent run and a clock that is off are better lower', () => {
    expect(betterDirection('agent_run_ms')).toBe('lower');
    expect(betterDirection('clock_skew_s')).toBe('lower');
  });

  it('CPU předchozího běhu agenta je lepší nižší', () => {
    expect(betterDirection('agent_prev_cpu_ms')).toBe('lower');
  });

  // A busy line is what the line is for; colouring 900 Mbit/s as a fault
  // would punish the router for working.
  it('refuses to judge the WAN rate in either direction', () => {
    expect(betterDirection('wan_rx_mbps')).toBe('neutral');
    expect(betterDirection('wan_tx_mbps')).toBe('neutral');
  });

  it('treats an unknown metric as neutral rather than guessing', () => {
    expect(betterDirection('something_new')).toBe('neutral');
    expect(betterDirection('')).toBe('neutral');
  });
});
