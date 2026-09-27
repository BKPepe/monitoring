import { describe, expect, it } from 'vitest';
import type { Finding, FindingsResponse } from '@/api/types';
import { devicesWithoutFindings, findingsFrom, groupByDevice } from './findings';

const f = (key: string, monitorId: number, severity: Finding['severity'], source: Finding['source']): Finding => ({
  key,
  source,
  kind: 'k',
  severity,
  monitorId,
  monitorName: `Zařízení ${monitorId}`,
  monitorType: 'vps',
  title: key,
  detail: null,
  action: null,
  since: null,
});

const response = (findings: Finding[], extra: Partial<FindingsResponse> = {}): FindingsResponse => ({
  findings,
  total: findings.length,
  offset: 0,
  counts: { critical: 0, warning: 0, info: 0 },
  devices: [],
  monitorsChecked: 5,
  muted: [],
  canMute: false,
  sourceErrors: [],
  insightsCachedAt: null,
  generatedAt: '2026-09-23T10:00:00+02:00',
  ...extra,
});

const device = (monitorId: number, worst: Finding['severity']) => ({
  monitorId,
  monitorName: `Zařízení ${monitorId}`,
  monitorType: 'vps',
  worst,
  critical: 0,
  warning: 0,
  info: 0,
  total: 1,
});

describe('findingsFrom', () => {
  it('filtr zdrojů zachová pořadí ze serveru', () => {
    const data = response([
      f('a', 1, 'critical', 'status'),
      f('b', 2, 'warning', 'router'),
      f('c', 1, 'info', 'insight'),
    ]);
    expect(findingsFrom(data, ['insight', 'router']).map((x) => x.key)).toEqual(['b', 'c']);
  });

  it('bez odpovědi vrátí prázdný seznam, ne chybu', () => {
    expect(findingsFrom(null)).toEqual([]);
  });
});

describe('groupByDevice', () => {
  it('seskupí podle zařízení v pořadí od nejhoršího, jak ho poslal server', () => {
    const data = response(
      [f('a', 2, 'critical', 'status'), f('b', 1, 'warning', 'metric'), f('c', 2, 'info', 'insight')],
      {
        devices: [device(2, 'critical'), device(1, 'warning')],
      }
    );
    const groups = groupByDevice(data);
    expect(groups.map((g) => g.device.monitorId)).toEqual([2, 1]);
    expect(groups[0].findings.map((x) => x.key)).toEqual(['a', 'c']);
  });

  it('zařízení bez nálezu na této stránce vynechá, nález bez zařízení v seznamu ukáže', () => {
    const data = response([f('a', 3, 'warning', 'router')], { devices: [device(1, 'critical')] });
    const groups = groupByDevice(data);
    expect(groups).toHaveLength(1);
    expect(groups[0].device.monitorId).toBe(3);
    expect(groups[0].device.warning).toBe(1);
  });
});

describe('devicesWithoutFindings', () => {
  it('počítá zkontrolovaná zařízení, která nic nehlásí', () => {
    expect(devicesWithoutFindings(response([], { monitorsChecked: 12, devices: [device(1, 'info')] }))).toBe(11);
  });

  it('nikdy nejde pod nulu', () => {
    expect(devicesWithoutFindings(response([], { monitorsChecked: 0, devices: [device(1, 'info')] }))).toBe(0);
    expect(devicesWithoutFindings(null)).toBe(0);
  });
});
