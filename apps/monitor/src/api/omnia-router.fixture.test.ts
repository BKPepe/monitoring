import { describe, expect, it } from 'vitest';
import omnia from './omnia-router.fixture';

/**
 * The router's payload carries no identifier of a device or a person, by
 * design. Test data is where one would slip back in unnoticed - pasted from a
 * real capture - so the fixture is held to the same rule as the payload.
 */
const walk = (value: unknown, path: string, visit: (path: string, key: string, leaf: unknown) => void): void => {
  if (Array.isArray(value)) {
    value.forEach((item, i) => walk(item, `${path}[${i}]`, visit));
  } else if (value && typeof value === 'object') {
    for (const [key, child] of Object.entries(value)) {
      visit(path, key, child);
      walk(child, `${path}.${key}`, visit);
    }
  }
};

describe('Omnia fixture', () => {
  it('holds no MAC, IP address or serial-like key', () => {
    const found: string[] = [];
    walk(omnia, 'omnia', (path, key, leaf) => {
      // The server drops exactly these keys from storage_disks (bk_sanitize_storage_disks).
      if (/serial|wwn|eui|guid|cid|bssid|^mac$/i.test(key)) found.push(`${path}.${key} (key)`);
      if (typeof leaf !== 'string') return;
      if (/\b[0-9a-f]{2}(:[0-9a-f]{2}){5}\b/i.test(leaf)) found.push(`${path}.${key} (MAC)`);
      if (/\b\d{1,3}(\.\d{1,3}){3}\b/.test(leaf)) found.push(`${path}.${key} (IPv4)`);
    });
    expect(found).toEqual([]);
  });

  it('describes one router consistently: the disk key is the same everywhere', () => {
    const key = omnia.diskSda.key;
    expect(key).toMatch(/^[0-9a-f]{16}$/);
    expect(omnia.storageHistory.disks[0].key).toBe(key);
    expect(omnia.recommendations.items[0].key).toBe(`disk_temp_warm:d:${key}`);
    expect(omnia.wanBottleneck.linkMbit).toBe(omnia.details.wan_link_mbit);
  });

  // Wave 1 ships without the router's own probe: a mock that showed probe
  // data would let a card be built and tested against something the server never sends.
  it('contains no probe result, only tests started by Turris OS', () => {
    expect(omnia.wanBottleneck.probe.enabledServer).toBe(false);
    expect(omnia.wanBottleneck.tests.every((test) => test.startedBy === 'turris')).toBe(true);
    expect(omnia.speedtestHistory.every((row) => row.startedBy === 'turris')).toBe(true);
  });

  // Capture C5 (ubus call network.device status) made the switch ports
  // knowable; without `ethtool` the ring counter stays unknown on this router.
  it('carries the wired ceiling the agent really measures, and no invented ring drops', () => {
    expect(omnia.details.wan_path.lan_port_cap_mbit).toBe(1000);
    expect(omnia.details.wan_path.lan_port_max_mbit).toBe(1000);
    expect(omnia.details.wan_path.lan_conduits).toEqual([{ dev: 'eth1', mbit: 1000 }]);
    expect(omnia.details.wan_path.wan_rx_ring_drops).toBeNull();
    expect(omnia.agentTools.ethtool).toBe(false);
  });
});
