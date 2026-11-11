import { readFileSync } from 'node:fs';
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

  // R276: the count is of the public nodes - mostly game servers probed as
  // hosts; the agents themselves are private. "Agentů online" named them wrong.
  it('živý panel počítá uzly, ne agenty', () => {
    expect([dicts.cs.home.panel.agents, dicts.en.home.panel.agents]).toEqual(['Uzlů online', 'Nodes online']);
  });

  // R316: the list left out OpenSSL, which the installer requires.
  it('požadavky na hosting jmenují každé rozšíření, které instalátor kontroluje', () => {
    const installer = readFileSync(new URL('../../../status/installer.php', import.meta.url), 'utf8');
    const exts = [...installer.matchAll(/'([a-z_]+)' => extension_loaded\('\1'\)/g)].map((m) => m[1]);
    const names: Record<string, string> = {
      pdo_mysql: 'PDO MySQL',
      curl: 'cURL',
      mbstring: 'mbstring',
      openssl: 'OpenSSL',
    };
    expect(exts).toContain('openssl');
    for (const lang of ['cs', 'en'] as const) {
      const php = dicts[lang].server.req.items[0];
      for (const ext of exts) expect(php).toContain(names[ext] ?? ext);
    }
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
