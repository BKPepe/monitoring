import { describe, expect, it } from 'vitest';
import { parseMonitorId } from './monitor-route';

describe('parseMonitorId', () => {
  it('přijme jen kladné celé číslo', () => {
    expect(parseMonitorId('6')).toBe(6);
    expect(parseMonitorId('1234')).toBe(1234);
  });

  it('nečíselné nebo prázdné id není id - dřív se z něj stal monitor 1', () => {
    for (const bad of ['abc', '', '0', '-3', '1.5', '6abc', ' 6', '1e3', '007', '99999999999999999999']) {
      expect(parseMonitorId(bad)).toBeNull();
    }
    expect(parseMonitorId(undefined)).toBeNull();
    expect(parseMonitorId(null)).toBeNull();
  });
});
