import { describe, expect, it } from 'vitest';
import { outagesOf } from './outage-groups';

describe('outagesOf', () => {
  it('řádky jednoho výpadku jsou jeden výpadek, s nejstarším řádkem', () => {
    const rows = [30, 29, 28].map((id) => ({ id, monitorId: 1, outageEnd: null, time: String(id) }));
    expect(outagesOf(rows)).toEqual([{ id: 28, monitorId: 1, outageEnd: null, time: '28', latestId: 30 }]);
  });

  it('dva výpadky téhož monitoru se liší koncem', () => {
    const rows = [
      { id: 9, monitorId: 1, outageEnd: null },
      { id: 5, monitorId: 1, outageEnd: '2026-11-05 10:00:00' },
      { id: 4, monitorId: 1, outageEnd: '2026-11-05 10:00:00' },
    ];
    expect(outagesOf(rows).map((o) => [o.id, o.latestId])).toEqual([
      [9, 9],
      [4, 5],
    ]);
  });

  it('dva monitory jsou dva výpadky i se stejným koncem', () => {
    const rows = [
      { id: 2, monitorId: 2, outageEnd: null },
      { id: 1, monitorId: 1, outageEnd: null },
    ];
    expect(outagesOf(rows)).toHaveLength(2);
  });
});
