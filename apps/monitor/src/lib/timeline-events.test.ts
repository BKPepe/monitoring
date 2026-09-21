import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { timelineSeverity, timelineTitle } from './timeline-events';

const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) =>
  typeof params === 'string' ? params : (fallback ?? key);

/** The ten statuses release 0.1.7 adds (contract X14 / alert sheet 2.2). */
const RELEASE_TYPES = [
  'wan_link_degraded',
  'wan_link_restored',
  'conntrack_full',
  'conntrack_normal',
  'firewall_disabled',
  'firewall_restored',
  'dns_resolver_failed',
  'dns_resolver_restored',
  'router_rebooted',
  'oom_kill',
];

describe('timelineTitle', () => {
  it('reads every new status of the release', () => {
    for (const type of RELEASE_TYPES) {
      // A type missing from the map falls back to its raw key - which is
      // exactly what the user used to see for half the server's event types.
      expect(timelineTitle(type, t), type).not.toBe(type);
    }
  });

  it('words the three timeline-only types, which reach the user nowhere else', () => {
    expect(timelineTitle('router_rebooted', t)).toBe('Router se restartoval');
    expect(timelineTitle('conntrack_normal', t)).toBe('Tabulka spojení má zase místo');
    expect(timelineTitle('oom_kill', t)).toBe('Došla paměť, systém ukončil proces');
  });

  it('keeps the older types it inherited from the page', () => {
    expect(timelineTitle('wan_lost', t)).toBe('Výpadek primárního připojení (WAN)');
    expect(timelineTitle('service_discovered', t)).toBe('Objevena běžící služba');
  });

  it('shows the raw key of a type nobody mapped, instead of an empty row', () => {
    expect(timelineTitle('something_new', t)).toBe('something_new');
  });

  it('has a cs/en pair in the dictionary for each of them', () => {
    const dictionary = readFileSync(join(__dirname, '../context/language-context.tsx'), 'utf8');
    for (const type of RELEASE_TYPES) {
      expect(dictionary.includes(`'asset.tl_${type}':`), type).toBe(true);
    }
  });
});

describe('timelineSeverity', () => {
  it('tones the four notifying failures as warnings', () => {
    // Alert sheet 2.2: their colour class is warn, not down - the monitor
    // itself keeps answering while the router loses a guard.
    for (const type of ['wan_link_degraded', 'conntrack_full', 'firewall_disabled', 'dns_resolver_failed']) {
      expect(timelineSeverity(type), type).toBe('warning');
    }
  });

  it('tones every recovery as up', () => {
    for (const type of ['wan_link_restored', 'conntrack_normal', 'firewall_restored', 'dns_resolver_restored']) {
      expect(timelineSeverity(type), type).toBe('up');
    }
  });

  it('keeps a reboot informational and an OOM kill a warning', () => {
    // A router that loses power every few days would paint the timeline red.
    expect(timelineSeverity('router_rebooted')).toBe('info');
    expect(timelineSeverity('oom_kill')).toBe('warning');
  });

  it('still judges the older types as the page did', () => {
    expect(timelineSeverity('wan_lost')).toBe('down');
    expect(timelineSeverity('status_changed_up')).toBe('up');
    expect(timelineSeverity('monitor_added')).toBe('info');
  });
});
