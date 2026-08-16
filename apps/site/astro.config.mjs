// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

const API_ORIGIN = process.env.PUBLIC_API_ORIGIN ?? 'https://api.bloodkings.eu';

// https://astro.build/config
export default defineConfig({
  site: 'https://monitoring.bloodkings.eu',
  integrations: [sitemap()],

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
