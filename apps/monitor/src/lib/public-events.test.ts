import { describe, expect, it } from 'vitest';
import { outageEpisode, outageResolutions, type MonitorOutageFacts } from './public-events';

const down = (monitorId: number, outageEnd: string | null = null): MonitorOutageFacts => ({
  monitorId,
  isDown: true,
  outageEnd,
  outageDurationSec: outageEnd ? 120 : null,
});
const warn = (monitorId: number): MonitorOutageFacts => ({
  monitorId,
  isDown: false,
  outageEnd: null,
  outageDurationSec: null,
});

describe('outageEpisode: která kontrola patří ke kterému výpadku', () => {
  it('stejná služba a stejný konec = jeden výpadek; zhoršení žádný výpadek nemá', () => {
    expect(outageEpisode(down(1))).toBe('1:open');
    expect(outageEpisode(down(1, '20.09.2026 03:20:00'))).toBe('1:20.09.2026 03:20:00');
    expect(outageEpisode(warn(1))).toBeNull();
  });

  it('dva výpadky jedné služby tři dny od sebe mají různý klíč', () => {
    expect(outageEpisode(down(1, '17.09.2026 10:05:00'))).not.toBe(outageEpisode(down(1)));
  });
});

describe('outageResolutions: „Probíhá“ jednou za běžící výpadek (PUB-07)', () => {
  const status = (map: Record<number, string>) => (id: number) => map[id] ?? null;

  it('tři kontroly jednoho běžícího výpadku: čip jen u nejnovější, starší nic netvrdí', () => {
    expect(outageResolutions([down(1), down(1), down(1)], status({ 1: 'down' }))).toEqual([
      'Open',
      undefined,
      undefined,
    ]);
  });

  it('dva běžící výpadky různých služeb: každý jednou', () => {
    expect(outageResolutions([down(1), down(2), down(1), down(2)], status({ 1: 'down', 2: 'down' }))).toEqual([
      'Open',
      'Open',
      undefined,
      undefined,
    ]);
  });

  it('skončený výpadek zůstává „Vyřešeno“ u každé kontroly, zhoršení je „Info“', () => {
    const ended = '20.09.2026 03:20:00';
    expect(outageResolutions([down(1, ended), down(1, ended), warn(1)], status({ 1: 'down' }))).toEqual([
      'Resolved',
      'Resolved',
      'Info',
    ]);
  });

  it('bez známého stavu služby a bez konce nic netvrdí', () => {
    expect(outageResolutions([down(9)], status({}))).toEqual([undefined]);
  });
});
