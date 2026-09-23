// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { rename, rmdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';

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

// https://astro.build/config
export default defineConfig({
  site: 'https://monitoring.bloodkings.eu',
  // Error pages are not content: the sitemap lists live pages only.
  integrations: [sitemap({ filter: (page) => !/\/404\/?$/.test(new URL(page).pathname) }), nestedNotFound],

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
      directives: [
        "default-src 'none'",
        "img-src 'self' data:",
        "font-src 'self'",
        // Jediná povolená XHR destinace je vlastní worker API.
        `connect-src 'self' ${API_ORIGIN}`,
        "form-action 'none'",
        "base-uri 'none'",
        "frame-ancestors 'none'",
        "manifest-src 'self'",
        "object-src 'none'",
      ],
    },
  },

  build: {
    // Styly jdou do souborů místo <style> tagů — menší HTML, lepší cachování
    // a méně hashů v CSP.
    inlineStylesheets: 'never',
  },
});
