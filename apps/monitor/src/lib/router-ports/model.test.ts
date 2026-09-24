import { describe, expect, it } from 'vitest';
import omnia from '@/api/omnia-router.fixture';
import type { LanPorts } from '@/api/types';
import { STALE_AFTER_SECS, buildPortPanel, rateLabel, rateShort, type PortPanel, type PortSocket } from './model';

const NOW = 1_789_890_000;
const fresh = { reportedAt: NOW - 40, now: NOW };

/**
 * The owner's Omnia as a 0.1.8+ agent reports it: the fixture's 0.1.7 keys,
 * its switch, and the uplink keys the fixture does not carry (PPPoE up with
 * internet, the HiLink LTE backup on eth3, one USB disk besides the modem).
 */
const omniaDetails: Record<string, unknown> = {
  ...omnia.details,
  version: '0.1.9',
  agent_version: '0.1.9',
  board_name: 'cznic,turris-omnia',
  wan_proto: 'pppoe',
  wan_up: true,
  wan_internet: true,
  wan_rx_errors: 0,
  wan_tx_errors: 2,
  wan_rx_dropped: 5,
  wan_tx_dropped: null,
  lte_up: true,
  lte_device: 'eth3',
  lte_connected: true,
  lte_sim_state: 'ready',
  net_lte: 1.5,
  usb_devices: 2,
  lan_ports: omnia.lanPortsCounted,
};

function socket(panel: PortPanel, id: string): PortSocket {
  const s = panel.groups.flatMap((g) => g.sockets).find((x) => x.id === id || x.label === id);
  if (!s) throw new Error(`no socket ${id}`);
  return s;
}

describe('buildPortPanel', () => {
  it('draws the Omnia as Internet (WAN, LTE) then the switch in the reported order', () => {
    const panel = buildPortPanel(omniaDetails, fresh);
    expect(panel.groups.map((g) => g.id)).toEqual(['internet', 'lan']);
    expect(panel.groups[0].sockets.map((s) => s.label)).toEqual(['WAN', 'LTE']);
    expect(panel.groups[1].sockets.map((s) => s.label)).toEqual(['lan0', 'lan1', 'lan2', 'lan3', 'lan4']);
    expect(panel.lanEmpty).toBeNull();
  });

  it('LAN states: devices, measured 0, not counted, free - never mixed up', () => {
    const counted = buildPortPanel(omniaDetails, fresh);
    expect(socket(counted, 'lan0')).toMatchObject({ state: 'in_use', clients: 3, tone: 'up' });
    expect(socket(counted, 'lan1')).toMatchObject({ state: 'idle', clients: 0, tone: 'info' });
    expect(socket(counted, 'lan2')).toMatchObject({ state: 'free', link: false, tone: 'muted' });

    const uncounted = buildPortPanel({ ...omniaDetails, lan_ports: omnia.lanPorts }, fresh);
    // null stays null: not 0, and not the colour of a busy port.
    expect(socket(uncounted, 'lan0')).toMatchObject({ state: 'uncounted', clients: null, tone: 'foreground' });
    expect(socket(uncounted, 'lan1').clients).toBe(0);
  });

  it('an empty socket is never red and never carries a rate', () => {
    const panel = buildPortPanel(
      {
        lan_ports: {
          ...omnia.lanPorts,
          // A hand-built or older payload that still carries a speed on a dead port.
          ports: [{ ...omnia.lanPorts.ports[2], speed_mbit: 1000, duplex: 'full', partner_max_mbit: 1000 }],
        },
      },
      fresh
    );
    const s = socket(panel, 'lan2');
    expect(s.tone).not.toBe('down');
    expect(s).toMatchObject({ speedMbit: null, duplex: null, partnerMaxMbit: null, clients: null, speedTier: 0 });
  });

  it('a socket the router could not read is unknown, never free', () => {
    const lanPorts: LanPorts = {
      bridge: 'br-lan',
      ports: [
        {
          name: 'lan0',
          link: null,
          speed_mbit: null,
          duplex: null,
          max_mbit: null,
          partner_max_mbit: null,
          clients: null,
        },
      ],
      conduits: [],
      clients_total: null,
    };
    const panel = buildPortPanel({ lan_ports: lanPorts, version: '0.1.8' }, fresh);
    expect(socket(panel, 'lan0')).toMatchObject({ state: 'unknown', tone: 'paused' });
    expect(panel.summary).toEqual({ inUse: 0, known: 0, unknown: 1 });
  });

  it('100 Mbit is explained as the partner choice, not flagged', () => {
    const s = socket(buildPortPanel(omniaDetails, fresh), 'lan1');
    expect(s.slower).toEqual({ speedMbit: 100, capMbit: 1000, partnerKnown: true });
    expect(s.tone).not.toBe('warning');
    expect(s.speedTier).toBe(1);
    expect(socket(buildPortPanel(omniaDetails, fresh), 'lan0').slower).toBeNull();
  });

  it('speed tiers: 100 -> 1, 1000 -> 2, 2500 -> 3', () => {
    const panel = buildPortPanel(omniaDetails, fresh);
    expect(socket(panel, 'lan1').speedTier).toBe(1);
    expect(socket(panel, 'lan0').speedTier).toBe(2);
    expect(socket(panel, 'WAN').speedTier).toBe(3);
    expect(rateLabel(2500)).toBe('2.5 Gbit/s');
    expect(rateShort(2500)).toBe('2.5G');
    expect(rateShort(100)).toBe('100M');
    expect(rateShort(null)).toBeNull();
  });

  it('WAN: online, measured rate lights the activity LED, counters since boot', () => {
    const wan = socket(buildPortPanel(omniaDetails, fresh), 'WAN');
    expect(wan).toMatchObject({
      state: 'online',
      tone: 'up',
      link: true,
      netdev: 'eth2',
      speedMbit: 2500,
      rxMbps: 412.7,
      txMbps: 18.3,
      activity: true,
      carrierDrops: 3,
      errors: 2,
      // One side read (5), the other null: the sum of what was read, not a 0 for the rest.
      drops: 5,
      // Not reported for the WAN port until agent 0.1.10.
      duplex: null,
      // eth2 is the SFP/RJ45 combo on an Omnia, but the data does not say which: no medium is drawn.
      glyph: 'uplink',
    });
  });

  it('WAN: measured silence is an unlit LED, no rate is no LED', () => {
    const quiet = socket(buildPortPanel({ ...omniaDetails, wan_rx_mbps: 0, wan_tx_mbps: 0 }, fresh), 'WAN');
    expect(quiet.activity).toBe(false);
    const unmeasured = socket(buildPortPanel({ ...omniaDetails, wan_rx_mbps: null, wan_tx_mbps: null }, fresh), 'WAN');
    expect(unmeasured.activity).toBeNull();
    expect(unmeasured.errors).toBe(2);
    const noCounters = socket(
      buildPortPanel({ ...omniaDetails, wan_rx_errors: null, wan_tx_errors: null }, fresh),
      'WAN'
    );
    expect(noCounters.errors).toBeNull();
  });

  it('wan_internet=false is a warning, never down; a lost interface is down with an unknown link', () => {
    const noNet = socket(buildPortPanel({ ...omniaDetails, wan_internet: false }, fresh), 'WAN');
    expect(noNet).toMatchObject({ state: 'no_internet', tone: 'warning', link: true });
    const lost = socket(buildPortPanel({ ...omniaDetails, wan_up: false, wan_link_mbit: null }, fresh), 'WAN');
    // PPPoE can fail on a perfect cable: the carrier is not claimed either way.
    expect(lost).toMatchObject({ state: 'offline', tone: 'down', link: null, speedMbit: null });
  });

  it('a modem WAN never shows its usbnet speed as a line rate', () => {
    const wan = socket(
      buildPortPanel({ wan_proto: 'qmi', wan_up: true, wan_link_mbit: 150, wan_link_dev: 'wwan0' }, fresh),
      'WAN'
    );
    expect(wan).toMatchObject({ speedMbit: null, speedApplies: false, duplexApplies: false, glyph: 'modem' });
  });

  it('LTE backup: the modem verdict decides, its speed and duplex are never read', () => {
    const lte = socket(buildPortPanel(omniaDetails, fresh), 'LTE');
    expect(lte).toMatchObject({
      state: 'online',
      netdev: 'eth3',
      speedMbit: null,
      speedApplies: false,
      duplexApplies: false,
      activity: null,
    });
    // 1.5 KB/s = 0.01 Mbit/s.
    expect(lte.rxMbps).toBe(0.01);
    const noSim = socket(buildPortPanel({ ...omniaDetails, lte_sim_state: 'no_sim' }, fresh), 'LTE');
    expect(noSim).toMatchObject({ state: 'offline', lteReason: 'no_sim', tone: 'down' });
    const unverified = socket(buildPortPanel({ lte_up: true, lte_device: 'eth3' }, fresh), 'LTE');
    expect(unverified).toMatchObject({ state: 'unverified', link: true });
    expect(buildPortPanel({ lan_ports: omnia.lanPorts }, fresh).groups.map((g) => g.id)).toEqual(['lan']);
  });

  it('"N of M in use" counts Ethernet sockets with a known link only', () => {
    expect(buildPortPanel(omniaDetails, fresh).summary).toEqual({ inUse: 4, known: 6, unknown: 0 });
    // WAN down: its carrier is unknown, so it leaves the count instead of reading as free.
    expect(buildPortPanel({ ...omniaDetails, wan_up: false }, fresh).summary).toEqual({
      inUse: 3,
      known: 5,
      unknown: 1,
    });
  });

  it('the legend lists only the states drawn, in a fixed order', () => {
    expect(buildPortPanel(omniaDetails, fresh).legend).toEqual(['online', 'in_use', 'idle', 'free']);
    expect(buildPortPanel({ lan_ports: omnia.lanPorts }, fresh).legend).toEqual(['idle', 'uncounted', 'free']);
  });

  it('the conduit attaches to the switch; its rate is the slowest linked one', () => {
    expect(buildPortPanel(omniaDetails, fresh).conduit).toEqual({ devs: 'eth1', rateMbit: 1000 });
    const unknownRate = buildPortPanel(
      { lan_ports: { ...omnia.lanPorts, conduits: [{ dev: 'eth1', link: true, speed_mbit: null, duplex: null }] } },
      fresh
    );
    expect(unknownRate.conduit).toEqual({ devs: 'eth1', rateMbit: null });
    const dead = buildPortPanel(
      { lan_ports: { ...omnia.lanPorts, conduits: [{ dev: 'eth1', link: false, speed_mbit: null, duplex: null }] } },
      fresh
    );
    expect(dead.conduit).toBeNull();
  });

  it('USB: the count and the USB disks; a SATA disk is not a USB chip, a measured 0 draws nothing', () => {
    expect(buildPortPanel(omniaDetails, fresh).usb).toEqual({ devices: 2, disks: [] });
    const withStick = buildPortPanel(
      { ...omniaDetails, storage_disks: [omnia.diskSda, { ...omnia.diskSda, name: 'sdb', transport: 'usb' }] },
      fresh
    );
    expect(withStick.usb).toEqual({ devices: 2, disks: ['sdb'] });
    expect(buildPortPanel({ ...omniaDetails, usb_devices: 0 }, fresh).usb).toBeNull();
    expect(buildPortPanel({ ...omniaDetails, usb_devices: null }, fresh).usb).toBeNull();
  });

  it('a stale report keeps the picture but drops everything live and greys every tone', () => {
    const panel = buildPortPanel(omniaDetails, { reportedAt: NOW - STALE_AFTER_SECS - 1, now: NOW });
    expect(panel.stale).toBe(true);
    const all = panel.groups.flatMap((g) => g.sockets);
    expect(all.every((s) => s.tone === 'paused')).toBe(true);
    expect(all.every((s) => s.activity === null && s.rxMbps === null && s.txMbps === null)).toBe(true);
    expect(socket(panel, 'lan0').link).toBe(true);
    expect(panel.legend).toEqual([]);
    // Right at the limit it is still the live picture.
    expect(buildPortPanel(omniaDetails, { reportedAt: NOW - STALE_AFTER_SECS, now: NOW }).stale).toBe(false);
    // An unknown report time is not evidence of staleness.
    expect(buildPortPanel(omniaDetails, { reportedAt: null, now: NOW })).toMatchObject({
      stale: false,
      reportAgeSecs: null,
    });
  });

  it('absences: old agent, no report since the update, no switch', () => {
    expect(buildPortPanel({ version: '0.1.7' }, fresh).lanEmpty).toBe('agent_outdated');
    expect(buildPortPanel({ version: '0.1.9' }, fresh).lanEmpty).toBe('agent_outdated');
    expect(buildPortPanel({ version: '0.1.8', lan_ports: null }, fresh).lanEmpty).toBe('no_switch');
    expect(
      buildPortPanel(
        { version: '0.1.8', lan_ports: { bridge: 'br-lan', ports: [], conduits: [], clients_total: null } },
        fresh
      ).lanEmpty
    ).toBe('no_switch');
    // An unreadable version explains nothing: it is not a claim that the agent is new enough.
    expect(buildPortPanel({ version: 'dev', lan_ports: null }, fresh).lanEmpty).toBe('agent_outdated');
    // Data wins over the version string.
    expect(buildPortPanel({ version: '0.1.7', lan_ports: omnia.lanPorts }, fresh).lanEmpty).toBeNull();
    // An old agent still has a WAN to draw.
    const old = buildPortPanel({ ...omnia.details }, fresh);
    expect(old.groups.map((g) => g.id)).toEqual(['internet']);
    expect(old.lanEmpty).toBe('agent_outdated');
  });

  it('agent_version wins over version, which a service check may overwrite', () => {
    expect(buildPortPanel({ version: '3.13.0', agent_version: '0.1.7', lan_ports: null }, fresh).agentVersion).toBe(
      '0.1.7'
    );
  });
});
