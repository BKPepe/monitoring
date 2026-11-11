import { describe, expect, it } from 'vitest';
import { localStamp } from './local-stamp';

/** R221: a maintenance window was read in the visitor's zone, not the server's. */
describe('localStamp (R221)', () => {
  const back = (s: string) => new Date(s.replace(' ', 'T')).getTime();

  it('stejné hodiny se dvěma posuny jsou dva okamžiky, dvě hodiny od sebe', () => {
    const prague = localStamp('2026-11-12T10:00:00+01:00');
    const moscow = localStamp('2026-11-12T10:00:00+03:00');
    expect(back(prague) - back(moscow)).toBe(2 * 3600_000);
  });

  it('údaj bez posunu zůstane, jak přišel, bez sekund', () => {
    expect(localStamp('2026-11-12 10:00:00')).toBe('2026-11-12 10:00');
  });

  it('nečitelný údaj se ukáže, jak přišel', () => {
    expect(localStamp('zítra ráno')).toBe('zítra ráno');
  });
});
