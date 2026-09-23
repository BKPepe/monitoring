import { describe, expect, it } from 'vitest';
import { isWebsiteMonitor, routerMonitors, websiteFindings } from './insight-findings';

const site = (id: number, over: Record<string, unknown> = {}) => ({
  id,
  name: `web-${id}`,
  type: 'https',
  status: 'up',
  target: `https://w${id}.example.test`,
  ...over,
});

describe('Zjištění o webech (W1-B6)', () => {
  it('web je HTTP(S) kontrola, ne agent s adresou', () => {
    expect(isWebsiteMonitor({ type: 'web', target: 'shop.example.test' })).toBe(true);
    expect(isWebsiteMonitor({ type: 'port', target: 'https://x.example.test' })).toBe(true);
    expect(isWebsiteMonitor({ type: 'vps', target: 'https://x.example.test' })).toBe(false);
    expect(isWebsiteMonitor({ type: 'openwrt', target: 'https://x.example.test' })).toBe(false);
    expect(isWebsiteMonitor({ type: 'dns', target: 'example.test' })).toBe(false);
  });

  it('hranice je serverová: 14 dní bere 9 i 14, 15 ne; den 0 vyprší dnes, záporný vypršel', () => {
    const { findings } = websiteFindings(
      [
        site(1, { details: { ssl_days_remaining: 9 } }),
        site(2, { details: { ssl_days_remaining: 14 } }),
        site(3, { details: { ssl_days_remaining: 15 } }),
        site(4, { details: { ssl_days_remaining: 0 } }),
        site(5, { details: { ssl_days_remaining: -2 } }),
      ],
      14
    );
    expect(findings.map((f) => [f.kind, f.monitorId])).toEqual([
      ['ssl_expired', 5],
      ['ssl_expiring', 4],
      ['ssl_expiring', 1],
      ['ssl_expiring', 2],
    ]);
  });

  it('bez hranice jen vypršelé a dnešní certifikáty, nic se nedomýšlí', () => {
    const { findings } = websiteFindings(
      [
        site(1, { details: { ssl_days_remaining: 3 } }),
        site(2, { details: { ssl_days_remaining: 0 } }),
        site(3, { details: { ssl_days_remaining: -1 } }),
      ],
      null
    );
    expect(findings.map((f) => f.monitorId)).toEqual([3, 2]);
  });

  it('výpadky jdou první; archivovaný web ani pozastavený výpadek nejsou zjištění', () => {
    const { findings, websites } = websiteFindings(
      [
        site(1, { details: { ssl_days_remaining: 2 } }),
        site(2, { status: 'down', sinceStatusChangeSeconds: 60 }),
        site(3, { status: 'down', archivedAt: '2026-09-01T00:00:00Z' }),
        site(4, { status: 'paused' }),
      ],
      14
    );
    expect(findings.map((f) => [f.kind, f.monitorId])).toEqual([
      ['down', 2],
      ['ssl_expiring', 1],
    ]);
    expect(websites).toBe(3);
  });

  it('nepřečtený certifikát se počítá jen u HTTPS webu', () => {
    const { sslUnread } = websiteFindings([site(1), site(2, { target: 'http://plain.example.test' })], 14);
    expect(sslUnread).toBe(1);
  });

  it('routery jsou openwrt monitory mimo archiv', () => {
    const list = [
      { id: 1, name: 'a', type: 'openwrt' },
      { id: 2, name: 'b', type: 'OpenWrt ', archivedAt: '2026-01-01' },
      { id: 3, name: 'c', type: 'vps' },
    ];
    expect(routerMonitors(list).map((m) => m.id)).toEqual([1]);
  });
});
