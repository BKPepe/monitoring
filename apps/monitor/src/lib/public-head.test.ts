import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { PUBLIC_CANONICAL_URL, publicCanonicalUrl, syncPublicCanonical } from './public-head';

/**
 * W1-G4 (the public status page is indexed) and W1-F4/F5 (files under /app get
 * a real, branded 404). The server half lives in files the browser never runs
 * through React - public.html, index.html, public/.htaccess, public/404.html -
 * so their contract is read from the files themselves.
 */
const read = (rel: string) => readFileSync(new URL(`../../${rel}`, import.meta.url), 'utf8');

/** The directives of .htaccess in order, without comments and blank lines. */
const htaccessLines = () =>
  read('public/.htaccess')
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l !== '' && !l.startsWith('#'));

describe('Veřejná stránka je indexovatelná, zbytek aplikace ne (W1-G4)', () => {
  it('kanonická adresa: hlavní stránka bez parametrů, vlastní stránka se svým slugem', () => {
    expect(publicCanonicalUrl(null)).toBe('https://bloodkings.eu/app/public');
    expect(publicCanonicalUrl('')).toBe(PUBLIC_CANONICAL_URL);
    // The same URL root/lib.mjs puts into the sitemap.
    expect(publicCanonicalUrl('herní servery')).toBe('https://bloodkings.eu/app/public?page=hern%C3%AD%20servery');
  });

  it('stránka přepíše kanonický odkaz z public.html, žádný druhý nepřidá', () => {
    const attrs: Record<string, Record<string, string>> = {
      'link[rel="canonical"]': { href: PUBLIC_CANONICAL_URL },
      'meta[property="og:url"]': { content: PUBLIC_CANONICAL_URL },
    };
    const doc = {
      querySelector: (sel: string) =>
        attrs[sel] ? { setAttribute: (name: string, value: string) => void (attrs[sel][name] = value) } : null,
    } as unknown as Document;

    syncPublicCanonical(doc, 'hry');
    expect(attrs['link[rel="canonical"]'].href).toBe('https://bloodkings.eu/app/public?page=hry');
    expect(attrs['meta[property="og:url"]'].content).toBe('https://bloodkings.eu/app/public?page=hry');
    syncPublicCanonical(doc, null);
    expect(attrs['link[rel="canonical"]'].href).toBe(PUBLIC_CANONICAL_URL);
    // Opened from inside the app (index.html): nothing to update, nothing added.
    expect(() => syncPublicCanonical({ querySelector: () => null } as unknown as Document, 'x')).not.toThrow();
  });

  it('public.html: skutečný titulek, popis, kanonická adresa, og a RSS - a žádné noindex', () => {
    const html = read('public.html');
    expect(html).not.toMatch(/<meta\s+name="robots"/);
    expect(html).toContain('<title>Stav služeb | Blood Kings</title>');
    expect(html).toMatch(/<meta\s+name="description"\s+content="[^"]{50,}"/);
    expect(html).toContain('<link rel="canonical" href="https://bloodkings.eu/app/public" />');
    for (const prop of ['og:title', 'og:description', 'og:url', 'og:image']) {
      expect(html).toContain(`property="${prop}"`);
    }
    expect(html).toMatch(/type="application\/rss\+xml"[\s\S]*?href="https:\/\/bloodkings\.eu\/status\/rss\.php"/);
    // The same app as index.html, not a second one.
    expect(html).toContain('<script type="module" src="/src/main.tsx"></script>');
  });

  it('index.html (vše za přihlášením) si noindex nechává a build zná obě stránky', () => {
    expect(read('index.html')).toContain('<meta name="robots" content="noindex, nofollow" />');
    expect(read('vite.config.ts')).toMatch(/public:\s*fileURLToPath\(new URL\('\.\/public\.html'/);
  });

  it('.htaccess: /app/public dostane public.html, index.html nese X-Robots-Tag', () => {
    const lines = htaccessLines();
    const publicRule = lines.indexOf('RewriteRule ^public/?$ public.html [L]');
    const fallback = lines.indexOf('RewriteRule . index.html [L]');
    expect(publicRule).toBeGreaterThan(-1);
    expect(publicRule).toBeLessThan(fallback);
    const htaccess = read('public/.htaccess');
    expect(htaccess).toMatch(
      /<FilesMatch "\^index\\\.html\$">[\s\S]*?Header set X-Robots-Tag "noindex, nofollow"[\s\S]*?<\/FilesMatch>/
    );
    // public.html must not be caught by a noindex header.
    const publicBlock = htaccess.match(/<FilesMatch "\^public\\\.html\$">[\s\S]*?<\/FilesMatch>/)?.[0] ?? '';
    expect(publicBlock).not.toContain('X-Robots-Tag');
  });
});

describe('Soubor, který v /app není, je skutečná 404 (W1-F4, W1-F5)', () => {
  it('.htaccess: assets/ a cesty s příponou obejdou záložní index.html', () => {
    const lines = htaccessLines();
    const assets = lines.indexOf('RewriteRule ^assets/ - [L]');
    const extension = lines.indexOf('RewriteCond %{REQUEST_URI} !\\.[A-Za-z0-9]{1,8}$');
    const fallback = lines.indexOf('RewriteRule . index.html [L]');
    expect(assets).toBeGreaterThan(-1);
    expect(assets).toBeLessThan(fallback);
    // The extension condition belongs to the fallback rule (the line right before it, among its conditions).
    expect(extension).toBe(fallback - 1);
    expect(lines).toContain('ErrorDocument 404 /app/404.html');
    // The pattern: a file name with an extension is excluded, a route is not.
    const fileLike = /\.[A-Za-z0-9]{1,8}$/;
    expect(fileLike.test('/app/assets/index-abc123.js')).toBe(true);
    expect(fileLike.test('/app/robots.txt')).toBe(true);
    expect(fileLike.test('/app/infrastructure/6/metric/6/temperature_c')).toBe(false);
    expect(fileLike.test('/app/public/neexistuje')).toBe(false);
  });

  it('404.html: značka Blood Kings, obě řeči, noindex a nic odjinud', () => {
    const html = read('public/404.html');
    expect(html).toContain('--brand: #a30d18');
    expect(html).not.toContain('#0284c7');
    expect(html).toContain('Stránka nenalezena');
    expect(html).toContain('Page not found');
    expect(html).toContain('<meta name="robots" content="noindex">');
    // Self-contained: no stylesheet, script, font or image is fetched.
    expect(html).not.toMatch(/<script|<link rel="stylesheet"|<img|@import|url\(/);
    const links = [...html.matchAll(/href="([^"]+)"/g)].map((m) => m[1]);
    expect(links).toEqual(['data:,', 'https://bloodkings.eu/app/public', 'https://bloodkings.eu/app/']);
  });
});
