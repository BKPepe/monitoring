/**
 * Whether a router's LTE backup can actually carry traffic.
 *
 * Mirrors bk_lte_backup_state() in apps/status/functions.php - the two must
 * agree, or the tile would show green while the server sends an alert.
 *
 * `lte_up` alone is not evidence: on a HiLink modem the OpenWrt interface is a
 * DHCP lease from the modem's LAN side, handed out with no SIM in the slot.
 * The verdict rests on what the modem reports about registration and the SIM;
 * when it reports nothing, the answer is "unknown" - never "working".
 */
export type LteBackupReason =
  'no_sim' | 'pin_required' | 'puk_required' | 'invalid' | 'not_connected' | 'interface_down';

export interface LteBackupState {
  /** true = can carry traffic, false = cannot, null = nothing to judge by. */
  ok: boolean | null;
  reason: LteBackupReason | null;
}

const BAD_SIM: ReadonlySet<string> = new Set(['no_sim', 'pin_required', 'puk_required', 'invalid']);

export function lteBackupState(d: Record<string, unknown>): LteBackupState {
  const up = typeof d.lte_up === 'boolean' ? d.lte_up : null;
  const connected = typeof d.lte_connected === 'boolean' ? d.lte_connected : null;
  const sim = typeof d.lte_sim_state === 'string' ? d.lte_sim_state : null;

  if (up === null && connected === null && sim === null) return { ok: null, reason: null };
  if (up === false) return { ok: false, reason: 'interface_down' };
  if (sim !== null && BAD_SIM.has(sim)) return { ok: false, reason: sim as LteBackupReason };
  if (connected === false) return { ok: false, reason: 'not_connected' };
  if (connected === true) return { ok: true, reason: null };
  return { ok: null, reason: null };
}
