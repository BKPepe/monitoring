import { describe, expect, it } from 'vitest';
import { maintenanceState } from './maintenance';

/** R291: a scheduled window read as maintenance in progress. */
describe('maintenanceState (R291)', () => {
  const now = Date.parse('2026-11-11T12:00:00+01:00');

  it('okno, které teprve začne, je naplánované', () => {
    expect(maintenanceState({ maintenance: true, maintenanceStart: '2026-11-12T08:00:00+01:00' }, now)).toBe(
      'scheduled'
    );
  });

  it('okno, které už začalo, nebo bez začátku, běží', () => {
    expect(maintenanceState({ maintenance: true, maintenanceStart: '2026-11-11T08:00:00+01:00' }, now)).toBe('active');
    expect(maintenanceState({ maintenance: true, maintenanceStart: null }, now)).toBe('active');
  });

  it('bez příznaku žádná údržba, ať je v začátku cokoli', () => {
    expect(maintenanceState({ maintenance: false, maintenanceStart: '2026-11-12T08:00:00+01:00' }, now)).toBe('none');
    expect(maintenanceState(null, now)).toBe('none');
  });
});
