import { describe, expect, it } from 'vitest';
import { publicIpv4Label, publicIpv6Label } from './wan-address';

const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) =>
  typeof params === 'string' ? params : (fallback ?? key);

describe('public address labels', () => {
  it('says why an IPv4 is not public', () => {
    expect(['public', 'cgnat', 'private', 'special'].map((s) => publicIpv4Label(s, t))).toEqual([
      'ano',
      'ne - za CGNAT poskytovatele',
      'ne - před routerem je další NAT',
      'ne - nesměrovatelná adresa',
    ]);
  });

  it('tells a global IPv6 from a local one', () => {
    expect([publicIpv6Label(true, t), publicIpv6Label(false, t)]).toEqual(['ano', 'ne - jen lokální adresa']);
  });

  it('shows no row for what nobody reported', () => {
    expect([publicIpv4Label(null, t), publicIpv4Label(undefined, t), publicIpv4Label('bogus', t)]).toEqual([
      null,
      null,
      null,
    ]);
    expect([publicIpv6Label(null, t), publicIpv6Label(undefined, t)]).toEqual([null, null]);
  });
});
