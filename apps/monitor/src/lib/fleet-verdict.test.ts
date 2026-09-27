import { describe, expect, it } from 'vitest';
import type { DayStatus, UptimeHistoryRow } from '@/data/model';
import { isCleanRow, splitHistory, statusCounts, verdictSentence, worstNamedState } from './fleet-verdict';

/** A stand-in for t(): the fallback with its {n} filled in, like the provider does. */
const t = (_key: string, params?: Record<string, string | number> | string, fallback?: string) =>
  typeof params === 'string' ? params : String(fallback);

const row = (id: number, name: string, statuses: DayStatus[]): UptimeHistoryRow => ({
  monitorId: id,
  name,
  days: statuses.map((status, i) => ({
    date: `${i + 1}.9.`,
    status,
    uptimePct: status === 'nodata' ? null : status === 'down' ? 90 : 100,
  })),
});

describe('statusCounts (W2-1)', () => {
  it('počítá podle klíče C-11, nový monitor zvlášť od mlčícího agenta', () => {
    expect(
      statusCounts([
        { status: 'up' },
        { status: 'down' },
        { status: 'down' },
        { status: 'unknown', lastCheck: null, agentLastSeen: null },
        { status: 'unknown', lastCheck: '2026-09-22T10:00:00Z' },
      ])
    ).toEqual({ up: 1, down: 2, unknown_new: 1, unknown_stale: 1 });
  });
});

describe('verdictSentence (W2-1, V-18)', () => {
  it('jmenuje jen problémy, nejhorší první a se správným tvarem', () => {
    expect(verdictSentence({ down: 1, warning: 2, up: 12 }, 'cs', t)).toBe('1 výpadek, 2 varování');
    expect(verdictSentence({ down: 3 }, 'cs', t)).toBe('3 výpadky');
    expect(verdictSentence({ down: 5, unknown_stale: 2, maintenance: 1 }, 'cs', t)).toBe(
      '5 výpadků, 2 agenti mlčí, 1 v údržbě'
    );
  });

  it('bez problému neslibuje víc, než ví - pozastavený a nový monitor nejsou „vše v provozu“', () => {
    expect(verdictSentence({ up: 4, paused: 1, unknown_new: 1 }, 'cs', t)).toBe('Žádný výpadek ani varování');
  });

  it('ikona a barva věty patří nejhoršímu jmenovanému stavu', () => {
    expect(worstNamedState({ warning: 1, down: 1, up: 3 })).toBe('down');
    expect(worstNamedState({ warning: 2, unknown_stale: 1 })).toBe('unknown_stale');
    expect(worstNamedState({ maintenance: 1, up: 2 })).toBe('maintenance');
    expect(worstNamedState({ up: 4, paused: 1, unknown_new: 1 })).toBeNull();
  });
});

describe('splitHistory (charts-16)', () => {
  const clean = row(1, 'Alfa', ['up', 'up', 'maintenance']);
  const warned = row(2, 'Beta', ['up', 'warning', 'up']);
  const failed = row(3, 'Gama', ['down', 'up', 'up']);
  const fresh = row(4, 'Delta', ['nodata', 'nodata', 'up']);

  it('čistý řádek je jen ten, kde byl každý den v provozu nebo v údržbě', () => {
    expect(isCleanRow(clean)).toBe(true);
    expect(isCleanRow(fresh)).toBe(false);
    expect(isCleanRow(row(5, 'Prázdný', []))).toBe(false);
  });

  it('ukáže řádky s problémem od nejhoršího, čisté jen spočítá', () => {
    const split = splitHistory([clean, fresh, warned, failed]);
    expect(split.shown.map((r) => r.name)).toEqual(['Gama', 'Beta', 'Delta']);
    expect(split.clean).toBe(1);
    expect(split.moreProblems).toBe(0);
  });

  it('nad limit řekne, kolik problémových řádků se nevešlo', () => {
    const split = splitHistory([failed, warned, fresh], 2);
    expect(split.shown.map((r) => r.name)).toEqual(['Gama', 'Beta']);
    expect(split.moreProblems).toBe(1);
  });
});
