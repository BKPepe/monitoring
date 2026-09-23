import { describe, expect, it } from 'vitest';
import { locationFreshness, PROBE_STALE_AFTER_MS, serverTimeMs } from './probe-locations';

describe('Místa měření: čas a odmlčení (W1-A3)', () => {
  it('čas serveru bez posunu si posun půjčí z cachedAt', () => {
    expect(serverTimeMs('2026-09-23 09:59:00', '2026-09-23T10:00:00+02:00')).toBe(
      Date.parse('2026-09-23T09:59:00+02:00')
    );
  });

  it('čas s vlastním posunem se nemění a nesmysl je null', () => {
    expect(serverTimeMs('2026-09-23T07:59:00Z', '2026-09-23T10:00:00+02:00')).toBe(Date.parse('2026-09-23T07:59:00Z'));
    expect(serverTimeMs('není čas', null)).toBeNull();
    expect(serverTimeMs(null, null)).toBeNull();
  });

  it('odmlčené = déle než dva intervaly v okamžiku, kdy server odpověď sestavil', () => {
    const cachedAt = '2026-09-23T10:00:00+02:00';
    const received = Date.parse('2026-09-23T10:09:00+02:00');
    // Nine minutes old at the snapshot: fine, even though the cached answer arrived later.
    expect(
      locationFreshness({ location: 'a', successRate: 100, lastSeen: '2026-09-23 09:51:00' }, cachedAt, received)
    ).toEqual({
      ageMin: 18,
      stale: false,
    });
    expect(
      locationFreshness({ location: 'b', successRate: 100, lastSeen: '2026-09-23 09:49:00' }, cachedAt, received)
    ).toEqual({
      ageMin: 20,
      stale: true,
    });
    expect(PROBE_STALE_AFTER_MS).toBe(10 * 60_000);
  });

  it('veřejná projekce bez lastSeen: nic se netvrdí', () => {
    expect(locationFreshness({ location: 'prague', successRate: 99 }, null, Date.now())).toEqual({
      ageMin: null,
      stale: null,
    });
  });
});
