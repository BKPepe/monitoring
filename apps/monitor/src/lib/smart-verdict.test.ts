import { describe, expect, it } from 'vitest';
import { smartToolMissing, smartVerdictTone } from './smart-verdict';

describe('smartVerdictTone', () => {
  it('selhávající disk je červený', () => {
    expect(smartVerdictTone('WARNING (Disk /dev/sdb selhal v SMART)')).toBe('down');
  });
  it('zdravé disky jsou zelené', () => {
    expect(smartVerdictTone('OK (2 disky v pořádku)')).toBe('up');
  });
  it('žádný disk ani chybějící nástroj nejsou zdraví', () => {
    expect(smartVerdictTone('OK (Nebyly detekovány fyzické disky)')).toBe('warning');
    expect(smartVerdictTone('N/A (smartctl chybí)')).toBe('warning');
    expect(smartVerdictTone(null)).toBe('warning');
  });
  it('rada k instalaci jen u chybějícího nástroje', () => {
    expect(smartToolMissing('N/A (smartctl chybí)')).toBe(true);
    expect(smartToolMissing('WARNING (Disk /dev/sdb selhal v SMART)')).toBe(false);
  });
});
