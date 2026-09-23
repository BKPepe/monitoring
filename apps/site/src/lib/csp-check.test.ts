import { experimental_AstroContainer as AstroContainer } from 'astro/container';
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import config from '../../astro.config.mjs';
import ThemeScript from '../components/ThemeScript.astro';
import { inlinedFontProblems, metaCsp, pageCspProblems, sha256Source } from './csp-check.js';

const csp = config.security?.csp;
const policy = typeof csp === 'object' ? csp : {};
const hashes = (policy.scriptDirective?.hashes ?? []).map((h) => (typeof h === 'string' ? h : h.hash));

async function renderedThemeScript() {
  const container = await AstroContainer.create();
  const html = await container.renderToString(ThemeScript);
  const m = /<script\b([^>]*)>([\s\S]*?)<\/script>/.exec(html);
  if (!m) throw new Error(`ThemeScript rendered no <script>: ${html}`);
  return { attrs: m[1], body: m[2] };
}

describe('CSP webu', () => {
  it('hash skriptu, který nastavuje tmavý režim, je v CSP (jinak je pod Rocket Loaderem vše světlé)', async () => {
    const { body } = await renderedThemeScript();
    expect(body).toContain("classList.add('dark')");
    expect(hashes).toContain(sha256Source(body));
  });

  it('Rocket Loader skript tématu nechá být a ten poběží hned v <head>', async () => {
    const { attrs } = await renderedThemeScript();
    expect(attrs).toMatch(/\bdata-cfasync="false"/);
  });

  it('frame-ancestors neleží v <meta> CSP, posílá ho hlavička z public/_headers', () => {
    const directives = (policy.directives ?? []).map((d) => d.split(/\s+/)[0]);
    expect(directives).not.toContain('frame-ancestors');
    const headers = readFileSync(new URL('../../public/_headers', import.meta.url), 'utf8');
    const all = /^\/\*\n((?: {2}.+\n)+)/m.exec(headers)?.[1] ?? '';
    expect(all).toContain("  Content-Security-Policy: frame-ancestors 'none'\n");
  });

  it('fonty Vite nevkládá do CSS jako data: URL, font-src je nepustí', () => {
    const limit = config.vite?.build?.assetsInlineLimit;
    expect(typeof limit).toBe('function');
    if (typeof limit !== 'function') return;
    const bytes = Buffer.alloc(2028);
    expect(limit('/x/jetbrains-mono-cyrillic-ext-wght-normal.woff2', bytes)).toBe(false);
    expect(limit('/x/icon.svg', bytes)).toBeUndefined();
  });
});

const PAGE = (policyText: string, body: string) =>
  `<html><head><meta http-equiv="content-security-policy" content="${policyText}"></head><body>${body}</body></html>`;

describe('kontrola sestaveného webu proti CSP', () => {
  it('inline skript bez hashe v CSP je chyba, s hashem projde', () => {
    const js = 'document.documentElement.classList.add("dark")';
    const without = PAGE("default-src 'none'; script-src 'self'", `<script>${js}</script>`);
    expect(pageCspProblems(without)).toHaveLength(1);
    expect(pageCspProblems(without)[0]).toMatch(/inline <script> .* is not allowed by the CSP/);
    const withHash = PAGE(`default-src 'none'; script-src 'self' '${sha256Source(js)}'`, `<script>${js}</script>`);
    expect(pageCspProblems(withHash)).toEqual([]);
  });

  it('skript se src a datový blok JSON hash nepotřebují, script-src chybí = platí default-src', () => {
    const html = PAGE(
      "default-src 'none'",
      '<script type="module" src="/_astro/a.js"></script><script type="application/ld+json">{"a":1}</script>'
    );
    expect(pageCspProblems(html)).toEqual([]);
    expect(pageCspProblems(PAGE("default-src 'none'", '<script>x()</script>'))).toHaveLength(1);
  });

  it('inline <style> bez hashe ve style-src je chyba', () => {
    const html = PAGE("default-src 'none'; style-src 'self'", '<style>p{color:red}</style>');
    expect(pageCspProblems(html)).toEqual([expect.stringMatching(/inline <style>/)]);
  });

  it('frame-ancestors, report-uri a sandbox v <meta> jsou chyba, stránka bez CSP taky', () => {
    const html = PAGE("default-src 'none'; frame-ancestors 'none'; sandbox; report-uri /r", '');
    expect(pageCspProblems(html)).toHaveLength(3);
    expect(pageCspProblems('<html><head></head></html>')).toEqual(['no <meta> content-security-policy']);
    expect(metaCsp(html)?.get('frame-ancestors')).toEqual(["'none'"]);
  });

  it('font vložený jako data: URL je chyba, dokud font-src nepovolí data:', () => {
    const css = '@font-face{src:url(data:font/woff2;base64,AAAA) format("woff2")}';
    expect(inlinedFontProblems(css, ["'self'"])).toEqual(['1 font(s) inlined as data: URLs, which font-src blocks']);
    expect(inlinedFontProblems(css, ["'self'", 'data:'])).toEqual([]);
    expect(inlinedFontProblems('@font-face{src:url(/_astro/a.woff2)}', ["'self'"])).toEqual([]);
  });
});
