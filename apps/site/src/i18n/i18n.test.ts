import { describe, expect, it } from 'vitest';
import { czTypography } from './cs';
import { alternates, dicts, langFromPath, withSlash } from './index';

/** Every key path of a dictionary, arrays by index. */
function keys(value: unknown, prefix = ''): string[] {
  if (Array.isArray(value)) return value.flatMap((v, i) => keys(v, `${prefix}[${i}]`));
  if (value && typeof value === 'object') {
    return Object.entries(value).flatMap(([k, v]) => keys(v, prefix ? `${prefix}.${k}` : k));
  }
  return [prefix];
}

describe('jazyky webu', () => {
  it('čeština a angličtina mají přesně stejné klíče, i v polích', () => {
    expect(keys(dicts.cs)).toEqual(keys(dicts.en));
  });

  it('jazyk plyne z cesty, /cs a /cs/ jsou česky, /csv ne', () => {
    expect(langFromPath('/')).toBe('en');
    expect(langFromPath('/cs')).toBe('cs');
    expect(langFromPath('/cs/docs/')).toBe('cs');
    expect(langFromPath('/csv/')).toBe('en');
  });

  it('alternativy stránky pro hreflang a přepínač jazyka', () => {
    expect(alternates('/')).toEqual({ en: '/', cs: '/cs/' });
    expect(alternates('/cs/')).toEqual({ en: '/', cs: '/cs/' });
    expect(alternates('/cs/docs')).toEqual({ en: '/docs/', cs: '/cs/docs/' });
    expect(alternates('/features/')).toEqual({ en: '/features/', cs: '/cs/features/' });
    expect(withSlash('/docs')).toBe('/docs/');
  });

  it('za jednopísmennou předložkou je nezlomitelná mezera, v odkazu a kódu ne', () => {
    const out = czTypography({ text: 'Weby a servery v jednom a v síti', href: 'a b', code: 'cd a b' });
    expect(out.text).toBe('Weby a servery v jednom a v síti');
    expect(out.href).toBe('a b');
    expect(out.code).toBe('cd a b');
  });

  it('úvodní stránka netvrdí, co kód neumí', () => {
    for (const dict of Object.values(dicts)) {
      const text = JSON.stringify(dict.home);
      // Checks are HTTP(S), TCP and agents; there is no ICMP or UDP probe and
      // no third role; the server has no Docker image.
      expect(text).not.toMatch(/Editor|Read-only|containeri[sz]ation|admin\.php\/setup|v0\.1\.0/);
      expect(dict.home.limits.items.join(' ')).toMatch(/ICMP/);
    }
  });
});
