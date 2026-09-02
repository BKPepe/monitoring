/**
 * Whether a router's primary link (WAN) carries traffic.
 *
 * Mirrors bk_wan_link_state() in apps/status/functions.php - the two must
 * agree, or the tile would show green while the server sends an alert.
 *
 * Two signals: the interface state (`wan_up`, every agent version) and one
 * ICMP echo bound to the WAN device (`wan_internet`, agent 0.1.1+). Either
 * one false means the primary link is dead - while the router itself may
 * still report through the LTE backup, which is exactly when nobody would
 * notice otherwise. An older agent that does not measure reachability is
 * judged on the interface alone; no signal at all is no verdict.
 */
export type WanLinkReason = 'interface_down' | 'no_internet';

export interface WanLinkState {
  /** true = carries traffic, false = does not, null = nothing to judge by. */
  ok: boolean | null;
  reason: WanLinkReason | null;
}

export function wanLinkState(d: Record<string, unknown>): WanLinkState {
  const up = typeof d.wan_up === 'boolean' ? d.wan_up : null;
  const internet = typeof d.wan_internet === 'boolean' ? d.wan_internet : null;
  const proto = typeof d.wan_proto === 'string' && d.wan_proto !== '' ? d.wan_proto : null;

  if (up === null && internet === null) return { ok: null, reason: null };
  // Agents before 0.1.1 sent wan_up=false for "no netifd interface called
  // wan at all" (access points, uplink on wwan). No protocol and no echo
  // alongside it means nothing was measured - no verdict.
  if (up === false && internet === null && proto === null) return { ok: null, reason: null };
  if (up === false) return { ok: false, reason: 'interface_down' };
  if (internet === false) return { ok: false, reason: 'no_internet' };
  return { ok: true, reason: null };
}
