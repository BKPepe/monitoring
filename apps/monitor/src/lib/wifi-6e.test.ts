import { describe, expect, it } from 'vitest';
import { describeWifi6e } from './wifi-6e';

/** Fills {placeholders} from params, so the test sees the numbers that reach the user. */
const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) => {
  const text = typeof params === 'string' ? params : (fallback ?? key);
  if (!params || typeof params === 'string') return text;
  return text.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''));
};

describe('describeWifi6e', () => {
  it('counts the capable clients among those that told', () => {
    expect(describeWifi6e({ band: '2.4GHz', clients: 6, clients_6ghz_capable: 2, clients_caps_known: 6 }, t)).toBe(
      'Podpora Wi-Fi 6E: 2 z 6 klientů, kteří ji uvedli'
    );
  });

  it('says how many clients did not tell', () => {
    expect(describeWifi6e({ band: '5GHz', clients: 5, clients_6ghz_capable: 1, clients_caps_known: 3 }, t)).toBe(
      'Podpora Wi-Fi 6E: 1 z 3 klientů, kteří ji uvedli, u 2 neznámá'
    );
  });

  it('calls missing data unknown, never zero', () => {
    expect(
      describeWifi6e({ band: '2.4GHz', clients: 4, clients_6ghz_capable: null, clients_caps_known: null }, t)
    ).toMatch(/neznámá/);
    expect(describeWifi6e({ band: '2.4GHz', clients: 4 }, t)).toMatch(/neznámá/);
    expect(describeWifi6e({ band: '2.4GHz', clients: 4, clients_6ghz_capable: 5, clients_caps_known: 2 }, t)).toMatch(
      /neznámá/
    );
  });

  it('says nothing for a 6 GHz radio or a radio without clients', () => {
    expect(describeWifi6e({ band: '6GHz', clients: 3, clients_6ghz_capable: 3, clients_caps_known: 3 }, t)).toBeNull();
    expect(
      describeWifi6e({ band: '2.4GHz', clients: 0, clients_6ghz_capable: 0, clients_caps_known: 0 }, t)
    ).toBeNull();
    expect(describeWifi6e({ band: '2.4GHz', clients: null }, t)).toBeNull();
  });
});
