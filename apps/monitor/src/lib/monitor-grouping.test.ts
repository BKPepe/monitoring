import { describe, expect, it } from 'vitest';
import { nestUnderAgents, processUsage } from './monitor-grouping';
import type { ApiMonitor } from '@/api/app-api';

/**
 * Tests for nesting services under agents and reading process consumption.
 *
 * Both functions came from real complaints: services showed as standalone
 * rows instead of the agent's subcategory, and process consumption was
 * being invented (20 % / 0 MB for everything).
 */

const mon = (over: Partial<ApiMonitor>): ApiMonitor =>
  ({
    id: 0,
    name: 'x',
    target: '',
    type: 'web',
    status: 'up',
    assetId: undefined,
    cpu: null,
    ram: null,
    hdd: null,
    ...over,
  }) as ApiMonitor;

describe('nestUnderAgents', () => {
  it('řadí agent-side kontroly hned za jejich agenta a značí je jako děti', () => {
    const rows = [
      mon({ id: 1, name: 'Web', type: 'web' }),
      mon({ id: 2, name: 'Router', type: 'openwrt', assetId: 10 }),
      mon({ id: 3, name: 'kresd', type: 'agent_service', assetId: 10 }),
      mon({ id: 4, name: 'Jiný web', type: 'web' }),
    ];
    const out = nestUnderAgents(rows);
    expect(out.map((o) => o.row.id)).toEqual([1, 2, 3, 4]);
    expect(out.map((o) => o.child)).toEqual([false, false, true, false]);
  });

  it('dítě sdílející asset se nevypisuje dvakrát, ani když je v seznamu před agentem', () => {
    const rows = [
      mon({ id: 3, name: 'kresd', type: 'agent_service', assetId: 10 }),
      mon({ id: 2, name: 'Router', type: 'openwrt', assetId: 10 }),
    ];
    const out = nestUnderAgents(rows);
    expect(out.map((o) => o.row.id)).toEqual([2, 3]);
    expect(out[1].child).toBe(true);
  });

  it('monitor bez assetId zůstává samostatný', () => {
    const rows = [mon({ id: 1, type: 'agent_service' }), mon({ id: 2, type: 'vps', assetId: 5 })];
    const out = nestUnderAgents(rows);
    expect(out).toHaveLength(2);
    expect(out.every((o) => !o.child)).toBe(true);
  });
});

describe('processUsage', () => {
  const parent = mon({
    id: 2,
    type: 'vps',
    assetId: 10,
    details: {
      top_cpu_processes: [{ name: 'kresd', cpu: 3.5, ram_mb: 42 }],
      top_ram_processes: [{ name: 'mysqld', cpu: 1.0, ram_mb: 512 }],
    },
  });

  it('bere CPU i RAM z žebříčků agenta podle názvu procesu', () => {
    const svc = mon({ id: 3, type: 'agent_service', assetId: 10, target: 'kresd' });
    expect(processUsage(svc, [parent, svc])).toEqual({ cpu: 3.5, ram: 42 });
  });

  it('proces mimo žebříčky = null, žádné vymyšlené nuly', () => {
    const svc = mon({ id: 3, type: 'agent_service', assetId: 10, target: 'neznamy-proces' });
    expect(processUsage(svc, [parent, svc])).toEqual({ cpu: null, ram: null });
  });

  it('bez rodičovského agenta = null', () => {
    const svc = mon({ id: 3, type: 'agent_service', assetId: 99, target: 'kresd' });
    expect(processUsage(svc, [svc])).toEqual({ cpu: null, ram: null });
  });

  it('běžný monitor vrací vlastní naměřené hodnoty beze změny', () => {
    const vps = mon({ id: 5, type: 'vps', cpu: 12.5, ram: 60 });
    expect(processUsage(vps, [vps])).toEqual({ cpu: 12.5, ram: 60 });
  });
});
