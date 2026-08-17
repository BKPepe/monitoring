import { describe, expect, it } from 'vitest';
import { toLocalInput, fromLocalInput } from '@/pages/infrastructure';

describe('okno údržby: převod času', () => {
  it('MySQL datetime -> hodnota pro datetime-local', () => {
    expect(toLocalInput('2026-08-12 02:00:00')).toBe('2026-08-12T02:00');
    expect(toLocalInput('2026-08-12T02:00:00')).toBe('2026-08-12T02:00');
  });

  it('prázdná/neplatná hodnota nedělá nesmysly', () => {
    expect(toLocalInput(null)).toBe('');
    expect(toLocalInput('')).toBe('');
    expect(toLocalInput('nesmysl')).toBe('');
  });

  it('zpět do MySQL formátu, prázdné = null', () => {
    expect(fromLocalInput('2026-08-12T02:00')).toBe('2026-08-12 02:00:00');
    expect(fromLocalInput('')).toBeNull();
  });

  it('čas se posunem zóny nemění (žádný Date parsing)', () => {
    // With new Date() this string would be read as local time or UTC
    // depending on the browser, shifting maintenance by hours.
    expect(fromLocalInput(toLocalInput('2026-01-15 23:30:00'))).toBe('2026-01-15 23:30:00');
  });
});
