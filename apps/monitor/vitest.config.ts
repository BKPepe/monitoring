import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';

/**
 * Unit testy logiky SPA (vitest, node prostředí - žádný jsdom není potřeba,
 * testuje se čistá logika: zanořování monitorů, výpočty trendů).
 * Samostatný config, aby testy nezáviselo na React/Tailwind pluginech
 * z vite.config.ts, které v testovacím běhu nemají co dělat.
 */
export default defineConfig({
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
    environment: 'node',
  },
});
