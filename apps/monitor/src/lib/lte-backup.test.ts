import { describe, expect, it } from 'vitest';
import { lteBackupState } from './lte-backup';

describe('lteBackupState', () => {
  it('is working only when the modem itself reports registration', () => {
    expect(lteBackupState({ lte_up: true, lte_connected: true, lte_sim_state: 'ready' })).toEqual({
      ok: true,
      reason: null,
    });
    expect(lteBackupState({ lte_up: true, lte_connected: true, lte_sim_state: null })).toEqual({
      ok: true,
      reason: null,
    });
  });

  // The bug: a HiLink interface is "up" with no SIM. Up alone must not be green.
  it('does not turn an interface flag into a working backup', () => {
    expect(lteBackupState({ lte_up: true })).toEqual({ ok: null, reason: null });
    expect(lteBackupState({ lte_up: true, lte_uptime: 778603, lte_rssi: -51 })).toEqual({ ok: null, reason: null });
  });

  it('reports every blocking SIM state as not working, even with connected=true', () => {
    for (const sim of ['no_sim', 'pin_required', 'puk_required', 'invalid'] as const) {
      expect(lteBackupState({ lte_up: true, lte_connected: true, lte_sim_state: sim })).toEqual({
        ok: false,
        reason: sim,
      });
    }
  });

  it('treats a disconnected modem and a downed interface as not working', () => {
    expect(lteBackupState({ lte_up: true, lte_connected: false, lte_sim_state: 'ready' })).toEqual({
      ok: false,
      reason: 'not_connected',
    });
    expect(lteBackupState({ lte_up: false })).toEqual({ ok: false, reason: 'interface_down' });
  });

  it('has no verdict for a router without LTE', () => {
    expect(lteBackupState({})).toEqual({ ok: null, reason: null });
    expect(lteBackupState({ wan_up: true })).toEqual({ ok: null, reason: null });
  });
});
