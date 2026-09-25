/**
 * The router's socket panel as data: which sockets exist, in which group, and
 * what is known about each one. Pure - no React, no clock of its own, no
 * translation - so every honesty rule of the panel lives here and is tested
 * here, and the components only draw what this returns.
 *
 * Built only from what agent 0.1.8+ already reports in `last_details`:
 *
 *   - the uplink: wan_up / wan_internet (through wanLinkState, the same
 *     verdict the server alerts on), wan_link_dev, wan_link_mbit, the
 *     cumulative wan_* error/drop counters, wan_carrier_down_count and the
 *     per-minute wan_rx_mbps / wan_tx_mbps;
 *   - the LTE backup: lte_up, lte_device and the modem's own verdict
 *     (through lteBackupState), net_lte;
 *   - the switch: lan_ports, exactly as the agent listed it;
 *   - USB: usb_devices and the disks whose transport is usb.
 *
 * The rules, each of which a test pins down:
 *
 *   - Unmeasured stays null. A socket nobody could read is "unknown", never
 *     "free"; a port whose devices were not counted is "uncounted", never 0.
 *   - An empty socket is never red, and a slow link is never a warning: the
 *     speed is printed, never coloured, and a port below its capability gets
 *     an explanation, not a colour.
 *   - The activity LED exists only where a rate was measured. LAN ports have
 *     no rate yet, so they have no activity LED at all - not an unlit one.
 *   - Stale data does not look live: every live value is dropped and every
 *     tone turns to "paused", while the last known picture stays readable.
 *   - Nothing is inferred. The WAN medium (SFP or RJ45) is not reported, so
 *     the WAN socket gets a neutral glyph; the WAN duplex is not reported, so
 *     it is null; an LTE modem's usbnet "speed" is a USB descriptor, not a
 *     line rate, so it is never read.
 */

import type { LanPort, LanPorts, StorageDisk } from '@/api/types';
import { wanLinkState } from '@/lib/wan-link';
import { lteBackupState, type LteBackupReason } from '@/lib/lte-backup';

/**
 * What a socket is doing. LAN and uplink states are kept apart on purpose:
 * "free" is a fine state for a LAN socket and would be wrong for the WAN,
 * whose lost link is a real fault.
 */
export type PortState =
  // LAN: cable in, devices behind it.
  | 'in_use'
  // LAN: cable in, nothing learnt behind it (a measured 0).
  | 'idle'
  // LAN: cable in, the bridge table could not be read.
  | 'uncounted'
  // LAN: no cable (or the other end is off).
  | 'free'
  // Any socket: the router could not tell.
  | 'unknown'
  // Uplink: carries traffic.
  | 'online'
  // WAN: interface up, the echo through it failed.
  | 'no_internet'
  // Uplink: lost (WAN interface down, LTE backup not usable).
  | 'offline'
  // LTE: the interface is up but the modem said nothing that proves it works.
  | 'unverified';

/**
 * The token a socket's verdict is written in. `foreground` and `muted` are
 * chrome, the rest are the status tokens of theme.css; the hardware itself
 * (jack bodies, contacts, LEDs) takes the --port-* tokens.
 */
export type PortTone = 'up' | 'info' | 'warning' | 'down' | 'foreground' | 'muted' | 'paused';

export type PortRole = 'wan' | 'lte' | 'lan';

/** The glyph drawn for a socket: an RJ45 jack, a neutral uplink port of unreported medium, or a modem. */
export type PortGlyph = 'rj45' | 'uplink' | 'modem';

export interface PortSocket {
  /** Stable React key: 'wan', 'lte' or 'lan:<netdev>:<index>'. */
  id: string;
  role: PortRole;
  /** The printed name: the netdev for LAN ports, 'WAN' / 'LTE' for the uplinks. */
  label: string;
  /** The kernel netdev behind the socket, when reported. */
  netdev: string | null;
  glyph: PortGlyph;
  /** Cable / carrier: true, false, or null when nobody measured it. */
  link: boolean | null;
  state: PortState;
  tone: PortTone;
  /** Negotiated rate; null without a link or without a reading. */
  speedMbit: number | null;
  /** 1 = up to 100 Mbit, 2 = up to 1 Gbit, 3 = 2.5 Gbit and more, 0 = unknown. */
  speedTier: 0 | 1 | 2 | 3;
  /** null also where the port type cannot report it (WAN today, the LTE modem always). */
  duplex: 'full' | 'half' | null;
  /** Whether a duplex row exists for this socket at all - never for a usbnet modem. */
  duplexApplies: boolean;
  /** Whether a link rate exists for this socket at all - never for a usbnet modem. */
  speedApplies: boolean;
  maxMbit: number | null;
  partnerMaxMbit: number | null;
  /** Set when the link runs below what the port can do: explained, never flagged. */
  slower: { speedMbit: number; capMbit: number; partnerKnown: boolean } | null;
  /** Devices learnt behind a LAN port; null = not counted. Uplinks: always null. */
  clients: number | null;
  /** Mbit/s over the last report interval; null = not measured (or stale). */
  rxMbps: number | null;
  txMbps: number | null;
  /**
   * The activity LED: true = measured traffic, false = measured silence,
   * null = no rate exists, so no LED is drawn at all.
   */
  activity: boolean | null;
  /** Carrier losses since the router booted (WAN only). */
  carrierDrops: number | null;
  /** rx+tx errors since boot; null unless at least one side was read (WAN only). */
  errors: number | null;
  /** rx+tx dropped since boot, same rule. */
  drops: number | null;
  /** WAN protocol as netifd names it (pppoe, dhcp, ...). */
  proto: string | null;
  /** Why the LTE backup cannot carry traffic, when the modem said. */
  lteReason: LteBackupReason | null;
}

export type PortGroupId = 'internet' | 'lan';

export interface PortGroup {
  id: PortGroupId;
  sockets: PortSocket[];
}

/** Why the switch has no sockets to draw. Kept from the old port map, sentence for sentence. */
export type LanEmptyReason = 'agent_outdated' | 'no_switch';

export interface PortPanel {
  groups: PortGroup[];
  /** Set when the LAN group is missing; says which of the two absences it is. */
  lanEmpty: LanEmptyReason | null;
  /** The link(s) to the CPU every wired device shares. A fact, drawn as one. */
  conduit: { devs: string; rateMbit: number | null } | null;
  /** USB: the device count and the USB disks by name. null = nothing to draw. */
  usb: { devices: number | null; disks: string[] } | null;
  /**
   * "N of M in use", over the Ethernet sockets only (WAN and LAN; the LTE
   * modem is not a socket on the box). `known` counts sockets whose link state
   * was measured; the unknown ones are counted apart and never as free.
   */
  summary: { inUse: number; known: number; unknown: number };
  /** The agent's own sum of devices on the switch; null when it could not count. */
  clientsTotal: number | null;
  /** The states drawn on this router, in legend order. Empty when stale. */
  legend: PortState[];
  stale: boolean;
  /** Seconds since the report; null when the report time is unknown. */
  reportAgeSecs: number | null;
  /** The agent version that explains an absence, as reported. */
  agentVersion: string | null;
}

export interface PortPanelMeta {
  /** When the report arrived, epoch seconds; null = unknown. */
  reportedAt: number | null;
  /** The clock the age is measured against, epoch seconds. */
  now: number;
}

/**
 * The OpenWrt agent reports once a minute. Three missed reports is no longer
 * "a bit late": the sockets then show the last known picture, not the box.
 */
export const STALE_AFTER_SECS = 180;

/** The release that started reporting the switch; before it there is nothing to draw. */
const SINCE = [0, 1, 8];

/** True once the reported agent version reaches 0.1.8. An unreadable version is not a claim. */
function reportsPorts(version: string | null): boolean {
  const parts = String(version ?? '')
    .split('.')
    .map((p) => parseInt(p, 10));
  if (parts.length < 3 || parts.some((p) => !Number.isFinite(p))) return false;
  for (let i = 0; i < SINCE.length; i++) {
    if (parts[i] !== SINCE[i]) return parts[i] > SINCE[i];
  }
  return true;
}

/** 1000 and above reads as "1 Gbit/s"; the rest keeps its megabits. */
export function rateLabel(mbit: number | null): string | null {
  if (mbit === null) return null;
  return mbit >= 1000 ? `${Number((mbit / 1000).toFixed(1))} Gbit/s` : `${mbit} Mbit/s`;
}

function speedTier(mbit: number | null): 0 | 1 | 2 | 3 {
  if (mbit === null) return 0;
  if (mbit <= 100) return 1;
  if (mbit < 2500) return 2;
  return 3;
}

/** A finite number, or null. Strings and NaN from an older payload are not a reading. */
function num(v: unknown): number | null {
  return typeof v === 'number' && Number.isFinite(v) ? v : null;
}

function str(v: unknown): string | null {
  return typeof v === 'string' && v !== '' ? v : null;
}

/** The sum of two counters, null only when neither was read - a missing side is not a 0. */
function sumKnown(a: number | null, b: number | null): number | null {
  return a === null && b === null ? null : (a ?? 0) + (b ?? 0);
}

/** The one place a state picks its token; the legend reads it too, so the two can never disagree. */
export const STATE_TONE: Record<PortState, PortTone> = {
  in_use: 'up',
  idle: 'info',
  // Linked but never counted: the socket is live and nothing is claimed about
  // what hangs off it - neither "busy" nor "idle" would be true.
  uncounted: 'foreground',
  // An empty socket is a normal thing on a home switch. Never red.
  free: 'muted',
  unknown: 'paused',
  online: 'up',
  // The line is up and the internet is not: a warning, not "down" - the
  // router is still reachable and the cause may sit upstream.
  no_internet: 'warning',
  offline: 'down',
  unverified: 'foreground',
};

function lanSocket(port: LanPort, index: number): PortSocket {
  const link = port.link;
  // The server already nulls the negotiated values of a dead port; this is the
  // same rule again, so a hand-built or older payload cannot draw a rate on a
  // socket without a cable.
  const speed = link === true ? port.speed_mbit : null;
  const clients = link === true ? port.clients : null;
  const state: PortState =
    link === null
      ? 'unknown'
      : link === false
        ? 'free'
        : clients === null
          ? 'uncounted'
          : clients > 0
            ? 'in_use'
            : 'idle';
  const slower =
    link === true && speed !== null && port.max_mbit !== null && speed < port.max_mbit
      ? { speedMbit: speed, capMbit: port.max_mbit, partnerKnown: port.partner_max_mbit !== null }
      : null;
  return {
    id: `lan:${port.name}:${index}`,
    role: 'lan',
    label: port.name,
    netdev: port.name,
    glyph: 'rj45',
    link,
    state,
    tone: STATE_TONE[state],
    speedMbit: speed,
    speedTier: speedTier(speed),
    duplex: link === true ? port.duplex : null,
    // lan_ports carries DSA switch ports only (the agent drops radios and
    // usbnet bridge members), and a DSA port does report its duplex.
    duplexApplies: true,
    speedApplies: true,
    maxMbit: port.max_mbit,
    partnerMaxMbit: link === true ? port.partner_max_mbit : null,
    slower,
    clients,
    // No per-port counters exist yet (P1b). Not zero - absent.
    rxMbps: null,
    txMbps: null,
    activity: null,
    carrierDrops: null,
    errors: null,
    drops: null,
    proto: null,
    lteReason: null,
  };
}

/** The modem protocols whose "link speed" is a USB descriptor (the agent skips them too). */
const MODEM_PROTOS: ReadonlySet<string> = new Set(['qmi', 'mbim', 'ncm', 'modemmanager', '3g']);

function wanSocket(d: Record<string, unknown>): PortSocket | null {
  const dev = str(d.wan_link_dev) ?? str(d.wan_l3_device);
  const proto = str(d.wan_proto);
  if (dev === null && proto === null && typeof d.wan_up !== 'boolean' && typeof d.wan_internet !== 'boolean') {
    return null;
  }
  const verdict = wanLinkState(d);
  const state: PortState =
    verdict.ok === true
      ? 'online'
      : verdict.ok === false
        ? verdict.reason === 'no_internet'
          ? 'no_internet'
          : 'offline'
        : 'unknown';
  // The agent sends no carrier flag for the WAN port (0.1.10 will). An
  // interface that is up rides on a live carrier; an interface that is down
  // says nothing about the cable (PPPoE can fail with a perfect link), so
  // the LED stays "not measured" rather than dark.
  const link = d.wan_up === true ? true : null;
  const isModem = proto !== null && MODEM_PROTOS.has(proto);
  const mbit = isModem ? null : num(d.wan_link_mbit);
  const speed = mbit !== null && mbit > 0 ? mbit : null;
  const rx = num(d.wan_rx_mbps);
  const tx = num(d.wan_tx_mbps);
  const measured = rx !== null || tx !== null;
  return {
    id: 'wan',
    role: 'wan',
    label: 'WAN',
    netdev: dev,
    // Whether eth2 is the SFP cage or the RJ45 jack is not in the data. The
    // uplink glyph is a neutral port; drawing a jack would invent the medium.
    glyph: isModem ? 'modem' : 'uplink',
    link,
    state,
    tone: STATE_TONE[state],
    speedMbit: speed,
    speedTier: speedTier(speed),
    duplex: null,
    duplexApplies: !isModem,
    speedApplies: !isModem,
    maxMbit: null,
    partnerMaxMbit: null,
    slower: null,
    clients: null,
    rxMbps: rx,
    txMbps: tx,
    // Measured on wan_l3_device over the last report interval; below 0.01
    // Mbit/s is silence, not traffic.
    activity: measured ? (rx ?? 0) + (tx ?? 0) >= 0.01 : null,
    carrierDrops: num(d.wan_carrier_down_count),
    errors: sumKnown(num(d.wan_rx_errors), num(d.wan_tx_errors)),
    drops: sumKnown(num(d.wan_rx_dropped), num(d.wan_tx_dropped)),
    proto,
    lteReason: null,
  };
}

function lteSocket(d: Record<string, unknown>): PortSocket | null {
  const dev = str(d.lte_device);
  if (dev === null && typeof d.lte_up !== 'boolean') return null;
  const backup = lteBackupState(d);
  const up = typeof d.lte_up === 'boolean' ? d.lte_up : null;
  const state: PortState =
    backup.ok === true ? 'online' : backup.ok === false ? 'offline' : up === true ? 'unverified' : 'unknown';
  // net_lte is KB/s (binary kilobytes, like every agent rate): to Mbit/s.
  const kbps = num(d.net_lte);
  const mbps = kbps === null ? null : Math.round(((kbps * 1024 * 8) / 1e6) * 100) / 100;
  return {
    id: 'lte',
    role: 'lte',
    label: 'LTE',
    netdev: dev,
    glyph: 'modem',
    link: up,
    state,
    tone: STATE_TONE[state],
    // A HiLink modem is a usbnet device: its "150H" is the USB descriptor,
    // not a line rate, and it has no duplex worth the name.
    speedMbit: null,
    speedTier: 0,
    duplex: null,
    duplexApplies: false,
    speedApplies: false,
    maxMbit: null,
    partnerMaxMbit: null,
    slower: null,
    clients: null,
    // One direction-less total; no LED in phase 1 (only the WAN has one).
    rxMbps: mbps,
    txMbps: null,
    activity: null,
    carrierDrops: null,
    errors: null,
    drops: null,
    proto: null,
    lteReason: backup.reason,
  };
}

/** Drops everything that describes "now" and greys the rest. */
function staled(s: PortSocket): PortSocket {
  return { ...s, tone: 'paused', rxMbps: null, txMbps: null, activity: null };
}

/** The legend lists what is drawn, in this order - never a state this router does not show. */
const LEGEND_ORDER: PortState[] = [
  'online',
  'no_internet',
  'offline',
  'unverified',
  'in_use',
  'idle',
  'uncounted',
  'free',
  'unknown',
];

/**
 * @param details `last_details` of an OpenWrt router.
 * @param meta when the report arrived and the clock to judge its age by.
 */
export function buildPortPanel(details: Record<string, unknown>, meta: PortPanelMeta): PortPanel {
  const lanPorts = details.lan_ports as LanPorts | null | undefined;
  const version = str(details.agent_version) ?? str(details.version);
  const reportAgeSecs = meta.reportedAt === null ? null : Math.max(0, meta.now - meta.reportedAt);
  // An unknown age is not proof of staleness; the header then shows no age.
  const stale = reportAgeSecs !== null && reportAgeSecs > STALE_AFTER_SECS;

  const uplinks = [wanSocket(details), lteSocket(details)].filter((s): s is PortSocket => s !== null);
  const ports = lanPorts?.ports ?? [];
  const lan = ports.map(lanSocket);

  const groups: PortGroup[] = [];
  if (uplinks.length > 0) groups.push({ id: 'internet', sockets: stale ? uplinks.map(staled) : uplinks });
  if (lan.length > 0) groups.push({ id: 'lan', sockets: stale ? lan.map(staled) : lan });

  // Data first: a section that arrived is drawn whatever the version string
  // says. The version only ever explains an ABSENCE - either the agent is too
  // old to look (or has not reported since the update), or it looked and
  // found no switch.
  const lanEmpty: LanEmptyReason | null =
    lan.length > 0 ? null : lanPorts === undefined || !reportsPorts(version) ? 'agent_outdated' : 'no_switch';

  let conduit: PortPanel['conduit'] = null;
  const linkedConduits = (lanPorts?.conduits ?? []).filter((c) => c.link !== false);
  if (lan.length > 0 && linkedConduits.length > 0) {
    // The slowest of them is what the household really shares.
    const rates = linkedConduits.map((c) => c.speed_mbit).filter((m): m is number => m !== null);
    conduit = {
      devs: linkedConduits.map((c) => c.dev).join(', '),
      rateMbit: rates.length > 0 ? Math.min(...rates) : null,
    };
  }

  const usbCount = num(details.usb_devices);
  const disks = Array.isArray(details.storage_disks) ? (details.storage_disks as StorageDisk[]) : [];
  const usbDisks = disks
    .filter((disk) => disk?.transport === 'usb' && typeof disk.name === 'string')
    .map((disk) => disk.name as string);
  // A measured 0 has nothing to draw; the group appears once something is plugged in.
  const usb =
    (usbCount !== null && usbCount > 0) || usbDisks.length > 0 ? { devices: usbCount, disks: usbDisks } : null;

  const ethernet = [...uplinks.filter((s) => s.role === 'wan'), ...lan];
  const summary = {
    inUse: ethernet.filter((s) => s.link === true).length,
    known: ethernet.filter((s) => s.link !== null).length,
    unknown: ethernet.filter((s) => s.link === null).length,
  };

  const present = new Set([...uplinks, ...lan].map((s) => s.state));
  // A stale picture is labelled as such; a legend of live meanings would contradict it.
  const legend = stale ? [] : LEGEND_ORDER.filter((s) => present.has(s));

  return {
    groups,
    lanEmpty,
    conduit,
    usb,
    summary,
    clientsTotal: lanPorts?.clients_total ?? null,
    legend,
    stale,
    reportAgeSecs,
    agentVersion: version,
  };
}
