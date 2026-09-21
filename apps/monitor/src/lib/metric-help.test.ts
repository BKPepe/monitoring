import { describe, expect, it } from 'vitest';
import { metricHelp } from './metric-help';

/** The Czech fallback stands in for the dictionary, as in the other lib tests. */
const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) =>
  typeof params === 'string' ? params : (fallback ?? key);

const WIFI_KEYS = [
  'wifi_noise_24g',
  'wifi_noise_5g',
  'wifi_noise_6g',
  'wifi_busy_24g',
  'wifi_busy_5g',
  'wifi_busy_6g',
  'wifi_busy_other_24g',
  'wifi_busy_other_5g',
  'wifi_busy_other_6g',
  'wifi_weak_clients',
  'wifi_wpa2_clients',
  'wifi_6e_unserved',
  'wifi_5g_capable_24g',
];

const STEP_KEYS = ['wan_errors', 'wan_drops', 'wan_ring_drops', 'conntrack_drops', 'wan_link_flaps'];

const WAN_KEYS = [
  'cpu_core_max',
  'cpu_core_max_softirq',
  'wan_rx_mbps',
  'wan_tx_mbps',
  ...STEP_KEYS,
  'agent_run_ms',
  'clock_skew_s',
];

describe('metricHelp', () => {
  it('explains every metric of agent 0.1.7: what, how and from where', () => {
    const keys = [...WIFI_KEYS, ...WAN_KEYS];
    expect(keys).toHaveLength(24);
    for (const key of keys) {
      const help = metricHelp(key, t);
      expect(help, key).not.toBeNull();
      expect(help?.what, key).toBeTruthy();
      expect(help?.how, key).toBeTruthy();
      expect(help?.source, key).toBeTruthy();
    }
  });

  // All 24 come from the agent's report, the derived ones too; "a check from
  // the monitoring server, every cron run" would be false for them.
  it('names the agent as the source, also for the two values the server derives', () => {
    const agent = metricHelp('cpu', t)?.source;
    expect(agent).toBeTruthy();
    expect(metricHelp('wifi_6e_unserved', t)?.source).toBe(agent);
    expect(metricHelp('clock_skew_s', t)?.source).toBe(agent);
  });

  it('gives the three bands of one measurement the same explainer', () => {
    expect(metricHelp('wifi_noise_6g', t)).toEqual(metricHelp('wifi_noise_24g', t));
    expect(metricHelp('wifi_busy_5g', t)).toEqual(metricHelp('wifi_busy_24g', t));
    expect(metricHelp('wifi_busy_other_6g', t)).toEqual(metricHelp('wifi_busy_other_5g', t));
    expect(metricHelp('wifi_busy_other_5g', t)?.what).not.toBe(metricHelp('wifi_busy_5g', t)?.what);
  });

  it('warns on every step metric that a restart loses growth and that long ranges are sums', () => {
    for (const key of STEP_KEYS) {
      const caveat = metricHelp(key, t)?.caveat ?? '';
      expect(caveat, key).toContain('dolní odhad');
      expect(caveat, key).toContain('součet, ne průměr');
    }
    // A rate is not a step: a sum of Mbit/s over a day would mean nothing.
    expect(metricHelp('wan_rx_mbps', t)?.caveat).not.toContain('součet');
  });

  it('does not let harmless WAN drops or conntrack races read as overload', () => {
    expect(metricHelp('wan_drops', t)?.caveat).toContain('ne o přetížení');
    expect(metricHelp('conntrack_drops', t)?.what).not.toMatch(/odmítn/);
    expect(metricHelp('conntrack_drops', t)?.caveat).toContain('plná tabulka spojení');
  });

  it('tells download from upload', () => {
    expect(metricHelp('wan_rx_mbps', t)?.what).toContain('stahování');
    expect(metricHelp('wan_tx_mbps', t)?.what).toContain('odesílání');
  });

  it('has no explainer for a key it does not know', () => {
    expect(metricHelp('something_new', t)).toBeNull();
  });
});
