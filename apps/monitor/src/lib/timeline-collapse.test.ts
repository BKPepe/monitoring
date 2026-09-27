import { describe, expect, it } from 'vitest';
import type { TimelineEvent } from '@/data/model';
import { collapseRuns, countAttention, isRoutine, repeatsTitle, runSpan } from './timeline-collapse';

let id = 0;
const ev = (title: string, severity: TimelineEvent['severity'], extra: Partial<TimelineEvent> = {}): TimelineEvent => ({
  id: ++id,
  title,
  detail: '',
  at: '',
  severity,
  ...extra,
});

describe('collapseRuns (C-10)', () => {
  it('sousední stejné události složí do jednoho běhu', () => {
    const items = collapseRuns([ev('OK', 'info'), ev('OK', 'info'), ev('OK', 'info'), ev('Výpadek', 'down')]);
    expect(items).toHaveLength(2);
    expect(items[0].kind).toBe('run');
    expect(items[0].kind === 'run' && items[0].events).toHaveLength(3);
    expect(items[1].kind).toBe('event');
  });

  it('obnovení mezi dvěma výpadky je drží od sebe', () => {
    const items = collapseRuns([ev('Výpadek', 'down'), ev('Obnoveno', 'up'), ev('Výpadek', 'down')]);
    expect(items.map((i) => i.kind)).toEqual(['event', 'event', 'event']);
  });

  it('dva výpadky téže služby tři dny od sebe zůstanou dva řádky (V-01)', () => {
    const items = collapseRuns([
      ev('shop.example.com', 'down', { atIso: '2026-09-24T08:21:00+02:00' }),
      ev('shop.example.com', 'down', { atIso: '2026-09-24T08:20:00+02:00' }),
      ev('shop.example.com', 'down', { atIso: '2026-09-21T07:43:00+02:00' }),
      ev('shop.example.com', 'down', { atIso: '2026-09-21T07:42:00+02:00' }),
    ]);
    expect(items.map((i) => (i.kind === 'run' ? i.events.length : 1))).toEqual([2, 2]);
  });

  it('jiný výpadek (episode) se neslučuje ani těsně po sobě, stejný ano i s mezerou', () => {
    const at = (m: number) => `2026-09-24T08:${String(m).padStart(2, '0')}:00+02:00`;
    const split = collapseRuns([
      ev('E-shop', 'down', { atIso: at(2), episode: '2:2026-09-24 08:03:00' }),
      ev('E-shop', 'down', { atIso: at(1), episode: '2:2026-09-24 08:01:30' }),
    ]);
    expect(split.map((i) => i.kind)).toEqual(['event', 'event']);
    const joined = collapseRuns([
      ev('E-shop', 'down', { atIso: at(40), episode: '2:open' }),
      ev('E-shop', 'down', { atIso: at(10), episode: '2:open' }),
    ]);
    expect(joined.map((i) => i.kind)).toEqual(['run']);
  });

  it('nečitelný čas mezeru nedokazuje - sousedé se sloučí', () => {
    const items = collapseRuns([ev('E-shop', 'down', { at: 'včera' }), ev('E-shop', 'down', { at: 'předevčírem' })]);
    expect(items.map((i) => i.kind)).toEqual(['run']);
  });

  it('stejný titulek s jinou závažností se neslučuje', () => {
    const items = collapseRuns([ev('Kontrola', 'info'), ev('Kontrola', 'warning')]);
    expect(items.every((i) => i.kind === 'event')).toBe(true);
  });
});

describe('isRoutine a countAttention', () => {
  it('rutinní je jen úspěšná kontrola z protokolu, ne informační změna', () => {
    expect(isRoutine(ev('OK', 'info', { kind: 'check' }))).toBe(true);
    expect(isRoutine(ev('Monitor upraven', 'info'))).toBe(false);
    expect(isRoutine(ev('Výpadek', 'down', { kind: 'check' }))).toBe(false);
  });

  it('odznak počítá jen výpadky a varování', () => {
    expect(countAttention([ev('a', 'info'), ev('b', 'down'), ev('c', 'warning'), ev('d', 'up')])).toBe(2);
  });
});

describe('repeatsTitle', () => {
  it('tělo shodné s titulkem (až na tečku a velikost písmen) se zahodí', () => {
    expect(repeatsTitle({ title: 'Kontrola proběhla v pořádku', detail: 'Kontrola proběhla v pořádku.' })).toBe(true);
    expect(repeatsTitle({ title: 'Výpadek služby', detail: 'HTTP 503' })).toBe(false);
    expect(repeatsTitle({ title: 'Výpadek', detail: '' })).toBe(true);
  });
});

describe('runSpan', () => {
  it('v rámci jednoho dne ukáže jen hodiny od–do', () => {
    const span = runSpan(
      [ev('x', 'down', { atIso: '2026-09-22T19:22:00' }), ev('x', 'down', { atIso: '2026-09-22T19:03:00' })],
      'cs'
    );
    expect(span).toMatch(/19:03–19:22$/);
  });

  it('nečitelné časy nahradí texty ze serveru, nic si nevymýšlí', () => {
    expect(runSpan([ev('x', 'down', { at: 'včera 19:22' }), ev('x', 'down', { at: 'včera 19:03' })], 'cs')).toBe(
      'včera 19:03 – včera 19:22'
    );
  });
});
