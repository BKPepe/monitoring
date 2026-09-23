// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { readdir, readFile, rename, rmdir } from 'node:fs/promises';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { inlinedFontProblems, metaCsp, pageCspProblems, sha256Source } from './src/lib/csp-check.js';
import { THEME_SCRIPT } from './src/lib/theme-script.js';

const API_ORIGIN = process.env.PUBLIC_API_ORIGIN ?? 'https://api.bloodkings.eu';

// Cloudflare Pages answers a missing path with the nearest 404.html up the
// tree (status 404), so /cs/<typo> should find cs/404.html. Astro writes only
// src/pages/404.astro as a flat 404.html; cs/404.astro comes out as
// cs/404/index.html, which Pages would never pick. This moves it into place.
/** @type {import('astro').AstroIntegration} */
const nestedNotFound = {
  name: 'nested-404',
  hooks: {
    'astro:build:done': async ({ dir }) => {
      const cs = fileURLToPath(new URL('cs/', dir));
      await rename(`${cs}404/index.html`, `${cs}404.html`);
      await rmdir(`${cs}404`);
    },
  },
};

// Fails the build when a built page carries a meta CSP that would block the
// site's own inline code or put a font it cannot load in the CSS. Both only
// show up as a browser console error on the live site otherwise: the theme
// script's missing hash left every page light under Rocket Loader, and an
// inlined data: font was refused on every load.
/** @type {import('astro').AstroIntegration} */
const cspCheck = {
  name: 'csp-check',
  hooks: {
    'astro:build:done': async ({ dir }) => {
      const root = fileURLToPath(dir);
      const files = (await readdir(root, { recursive: true })).map(String);
      const problems = [];
      let fontSrc = /** @type {string[]} */ ([]);
      for (const file of files.filter((f) => f.endsWith('.html'))) {
        const html = await readFile(join(root, file), 'utf8');
        const csp = metaCsp(html);
        if (csp) fontSrc = csp.get('font-src') ?? csp.get('default-src') ?? [];
        problems.push(...pageCspProblems(html).map((p) => `${file}: ${p}`));
      }
      for (const file of files.filter((f) => f.endsWith('.css'))) {
        const css = await readFile(join(root, file), 'utf8');
        problems.push(...inlinedFontProblems(css, fontSrc).map((p) => `${file}: ${p}`));
      }
      if (problems.length > 0) throw new Error(`CSP check failed:\n${problems.join('\n')}`);
    },
  },
};

// https://astro.build/config
export default defineConfig({
  site: 'https://monitoring.bloodkings.eu',
  // Error pages are not content: the sitemap lists live pages only.
  integrations: [sitemap({ filter: (page) => !/\/404\/?$/.test(new URL(page).pathname) }), nestedNotFound, cspCheck],

  security: {
    /**
     * Astro při buildu spočítá SHA-256 hashe všech inline skriptů a stylů
     * a vloží je do <meta http-equiv="content-security-policy">. Díky tomu
     * politika nepotřebuje 'unsafe-inline' a hashe se neudržují ručně.
     *
     * Direktivy, které prohlížeč v <meta> ignoruje (frame-ancestors, HSTS...),
     * dodává public/_headers na úrovni Cloudflare Pages.
     */
    csp: {
      // Astro does not hash an is:inline script; this is the theme script's
      // hash, computed from the very string ThemeScript.astro renders.
      scriptDirective: { hashes: [sha256Source(THEME_SCRIPT)] },
      directives: [
        "default-src 'none'",
        "img-src 'self' data:",
        "font-src 'self'",
        // Jediná povolená XHR destinace je vlastní worker API.
        `connect-src 'self' ${API_ORIGIN}`,
        "form-action 'none'",
        "base-uri 'none'",
        // frame-ancestors is ignored in <meta>; public/_headers sends it.
        "manifest-src 'self'",
        "object-src 'none'",
      ],
    },
  },

  vite: {
    build: {
      // Vite inlines assets under 4 kB as data: URLs, which put one small
      // font subset into the CSS where font-src 'self' refuses it. Fonts
      // always stay files; everything else keeps Vite's default.
      assetsInlineLimit: (file) => (/\.(woff2?|ttf|otf|eot)$/i.test(file) ? false : undefined),
    },
  },

  build: {
    // Styly jdou do souborů místo <style> tagů — menší HTML, lepší cachování
    // a méně hashů v CSP.
    inlineStylesheets: 'never',
  },
});
