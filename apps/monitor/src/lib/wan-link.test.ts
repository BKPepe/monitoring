import { describe, expect, it } from 'vitest';
import { wanLinkState } from './wan-link';

describe('wanLinkState', () => {
  it('has no verdict without any signal', () => {
    expect(wanLinkState({})).toEqual({ ok: null, reason: null });
    expect(wanLinkState({ wan_up: null, wan_internet: null })).toEqual({ ok: null, reason: null });
    // Strings are not booleans - an old "true" string is not evidence.
    expect(wanLinkState({ wan_up: 'true' })).toEqual({ ok: null, reason: null });
  });

  it('an old agent on an access point (wan_up=false, no protocol, no echo) has no verdict', () => {
    expect(wanLinkState({ wan_up: false })).toEqual({ ok: null, reason: null });
    expect(wanLinkState({ wan_up: false, wan_proto: '' })).toEqual({ ok: null, reason: null });
  });

  it('an interface that is down is the primary reason, whatever the echo said', () => {
    expect(wanLinkState({ wan_up: false, wan_proto: 'dhcp' })).toEqual({ ok: false, reason: 'interface_down' });
    expect(wanLinkState({ wan_up: false, wan_internet: false })).toEqual({ ok: false, reason: 'interface_down' });
    expect(wanLinkState({ wan_up: false, wan_internet: true })).toEqual({ ok: false, reason: 'interface_down' });
  });

  it('up but no echo gets out = no internet', () => {
    expect(wanLinkState({ wan_up: true, wan_internet: false })).toEqual({ ok: false, reason: 'no_internet' });
    expect(wanLinkState({ wan_internet: false })).toEqual({ ok: false, reason: 'no_internet' });
  });

  it('is fine when both agree, and trusts the interface alone for an old agent', () => {
    expect(wanLinkState({ wan_up: true, wan_internet: true })).toEqual({ ok: true, reason: null });
    expect(wanLinkState({ wan_up: true })).toEqual({ ok: true, reason: null });
  });
});
