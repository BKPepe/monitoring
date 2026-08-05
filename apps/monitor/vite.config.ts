import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';
import { execSync } from 'node:child_process';
import pkg from './package.json';

/**
 * Skutečná identita buildu pro patičku: verze z package.json + krátký git
 * hash. Nahrazuje dřívější vymyšlenou konstantu '0.1.0-stable' z mock.ts.
 */
let gitHash = '';
try {
  gitHash = execSync('git rev-parse --short HEAD', { stdio: ['ignore', 'pipe', 'ignore'] }).toString().trim();
} catch {
  // Build mimo git checkout (např. z tarballu) - hash prostě nebude.
}
const appVersion = gitHash ? `${pkg.version} (${gitHash})` : pkg.version;

/**
 * Cíl proxy pro vývoj. Přepíše se přes STATUS_ORIGIN:
 *   STATUS_ORIGIN=http://localhost:8080 npm run dev:monitor
 */
const STATUS_ORIGIN = process.env.STATUS_ORIGIN ?? 'https://bloodkings.eu';

export default defineConfig({
  base: '/app/',
  define: {
    __APP_VERSION__: JSON.stringify(appVersion),
  },
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5273,
    proxy: {
      /**
       * PHP backend se během vývoje tuneluje přes stejný origin.
       *
       * Bez proxy by SPA na localhost:5273 volala cizí doménu a přihlašovací
       * cookie by se neposlala — session cookie má `SameSite=Lax`. Alternativa
       * (`SameSite=None; Secure` + CORS s credentials) by kvůli pohodlí při
       * vývoji oslabila ochranu proti CSRF na produkci. Proxy je bezpečnější:
       * prohlížeč vidí same-origin a CORS se vůbec neuplatní.
       */
      '/status': {
        target: STATUS_ORIGIN,
        changeOrigin: true,
        secure: true,
        // Cookie je nastavená na produkční doménu; aby ji prohlížeč přijal
        // pro localhost, musí se doména z hlavičky odstranit.
        cookieDomainRewrite: '',
      },
    },
  },
});
