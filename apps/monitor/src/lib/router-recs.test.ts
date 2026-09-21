import { describe, expect, it } from 'vitest';
import type { RecommendationSeverity, RouterRecommendation } from '@/api/types';
import { groupRecommendations, severityTone } from './router-recs';

const item = (key: string, severity: RecommendationSeverity): RouterRecommendation => ({
  key,
  id: key.split(':')[0],
  area: 'storage',
  severity,
  title: key,
  measured: null,
  action: null,
  subject: { kind: 'router' },
  params: {},
  command: null,
  openSince: null,
});

describe('groupRecommendations', () => {
  it('puts critical and warning items up front and information behind the disclosure', () => {
    const groups = groupRecommendations([
      item('firewall_off', 'critical'),
      item('disk_temp_warm:d:b8791c9c63a644ef', 'warning'),
      item('wifi_6ghz_unserved', 'info'),
    ]);
    expect(groups.urgent.map((i) => i.key)).toEqual(['firewall_off', 'disk_temp_warm:d:b8791c9c63a644ef']);
    expect(groups.info.map((i) => i.key)).toEqual(['wifi_6ghz_unserved']);
  });

  // The server orders by severity, area and the rule's rank, and the Monday
  // e-mail uses the same order. Alphabetically disk_selftest_never would jump
  // ahead of disk_unclean_shutdowns and the page would disagree with the e-mail.
  it('keeps the order the server sent inside each group', () => {
    const sent = [
      item('disk_unclean_shutdowns:d:b8791c9c63a644ef', 'warning'),
      item('wan_cpu_packet_path:dl', 'info'),
      item('disk_selftest_never:d:b8791c9c63a644ef', 'warning'),
      item('lan_wired_ceiling', 'info'),
      item('clock_skew', 'warning'),
      item('pkg_librespeed_cli', 'info'),
    ];
    const groups = groupRecommendations(sent);
    expect(groups.urgent.map((i) => i.id)).toEqual(['disk_unclean_shutdowns', 'disk_selftest_never', 'clock_skew']);
    expect(groups.info.map((i) => i.id)).toEqual(['wan_cpu_packet_path', 'lan_wired_ceiling', 'pkg_librespeed_cli']);
    // The input is not reordered or emptied on the way.
    expect(sent.map((i) => i.id)[0]).toBe('disk_unclean_shutdowns');
    expect(sent).toHaveLength(6);
  });

  it('gives two empty groups for nothing, null and undefined', () => {
    expect(groupRecommendations([])).toEqual({ urgent: [], info: [] });
    expect(groupRecommendations(null)).toEqual({ urgent: [], info: [] });
    expect(groupRecommendations(undefined)).toEqual({ urgent: [], info: [] });
  });

  it('shows an item of an unknown severity instead of hiding it', () => {
    const groups = groupRecommendations([item('new_rule', 'fatal' as RecommendationSeverity)]);
    expect(groups.urgent).toHaveLength(1);
    expect(groups.info).toHaveLength(0);
  });
});

describe('severityTone', () => {
  it('maps each severity to a badge tone', () => {
    expect(severityTone('critical')).toBe('down');
    expect(severityTone('warning')).toBe('warning');
    expect(severityTone('info')).toBe('info');
  });

  it('never paints an unknown severity as harmless', () => {
    expect(severityTone('fatal')).toBe('warning');
  });
});
