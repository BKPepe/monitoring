import { describe, expect, it } from 'vitest';
import { dash, normaliseStatus, STALE_AFTER_MS } from './live-status';

const NOW = Date.parse('2026-09-23T10:00:00Z');
const FRESH = '2026-09-23T11:55:00+02:00'; // 5 minutes before NOW

function answer(overrides: Record<string, unknown> = {}) {
  return {
    status: 'healthy',
    uptimePercent: 98.187,
    totalMonitors: 6,
    downMonitors: 0,
    agentsOnline: 6,
    agentsTotal: 6,
    avgLatencyMs: 209,
    lastUpdated: FRESH,
    nodes: [{ name: 'Web', status: 'online', latencyMs: 58 }],
    ...overrides,
  };
}

describe('živý stav na webu', () => {
  it('zdravý verdikt s čerstvými daty je vše v pořádku', () => {
    const status = normaliseStatus(answer(), NOW);
    expect(status?.verdict).toBe('healthy');
    expect(status?.fresh).toBe(true);
    expect(status?.agentsOnline).toBe(6);
    expect(status?.avgLatencyMs).toBe(209);
  });

  it('degradovaný verdikt serveru web nikdy nevylepší', () => {
    expect(normaliseStatus(answer({ status: 'degraded' }), NOW)?.verdict).toBe('degraded');
    expect(normaliseStatus(answer({ status: 'down' }), NOW)?.verdict).toBe('down');
    expect(normaliseStatus(answer({ status: 'maintenance' }), NOW)?.verdict).toBe('maintenance');
  });

  it('neznámé slovo od serveru je neznámý stav, ne zdravý', () => {
    expect(normaliseStatus(answer({ status: 'operational' }), NOW)?.verdict).toBe('unknown');
    expect(normaliseStatus(answer({ status: 'unknown' }), NOW)?.verdict).toBe('unknown');
  });

  it('bez času posledního měření není „vše v pořádku"', () => {
    const status = normaliseStatus(answer({ lastUpdated: null }), NOW);
    expect(status?.verdict).toBe('unknown');
    expect(status?.fresh).toBe(false);
    expect(status?.lastUpdated).toBeNull();
  });

  it('stará data nejsou živá a nejsou „vše v pořádku"', () => {
    const old = new Date(NOW - STALE_AFTER_MS - 60_000).toISOString();
    const status = normaliseStatus(answer({ lastUpdated: old }), NOW);
    expect(status?.fresh).toBe(false);
    expect(status?.verdict).toBe('unknown');
  });

  it('nula monitorů není „vše v pořádku"', () => {
    expect(normaliseStatus(answer({ totalMonitors: 0, nodes: [] }), NOW)?.verdict).toBe('unknown');
  });

  it('chyba API nebo odpověď workeru o nedostupnosti nevrací žádná data', () => {
    expect(normaliseStatus({ error: 'Nepodařilo se zjistit stav infrastruktury.' }, NOW)).toBeNull();
    expect(normaliseStatus({ available: false, error: 'Status data temporarily unavailable' }, NOW)).toBeNull();
    expect(normaliseStatus(null, NOW)).toBeNull();
    expect(normaliseStatus('<html>', NOW)).toBeNull();
  });

  it('údržba a neznámý stav uzlu si drží vlastní stav, nejsou offline', () => {
    const status = normaliseStatus(
      answer({
        nodes: [
          { name: 'A', status: 'maintenance', latencyMs: null },
          { name: 'B', status: 'unknown', latencyMs: null },
          { name: 'C', status: 'something-new', latencyMs: 3 },
          { name: 'D', status: 'offline', latencyMs: null },
        ],
      }),
      NOW
    );
    expect(status?.nodes.map((n) => n.status)).toEqual(['maintenance', 'unknown', 'unknown', 'offline']);
  });

  it('nenaměřená čísla zůstanou null a vykreslí se jako pomlčka', () => {
    const status = normaliseStatus(answer({ uptimePercent: null, avgLatencyMs: null, agentsTotal: 'x' }), NOW);
    expect(status?.uptimePercent).toBeNull();
    expect(status?.agentsTotal).toBeNull();
    expect(dash(status?.avgLatencyMs, ' ms')).toBe('—');
    expect(dash(0, ' ms')).toBe('0 ms');
  });
});
