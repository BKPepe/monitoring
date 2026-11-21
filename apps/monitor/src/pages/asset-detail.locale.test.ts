import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * R293: the asset page wrote its dates in cs-CZ and "vláken", "peerů",
 * "baterie" into the English page. Dates follow the page's language through
 * localeFor(), words through t().
 */
const source = readFileSync(join(__dirname, 'asset-detail.tsx'), 'utf8');

describe('Detail zařízení mluví jazykem stránky (R293)', () => {
  it('žádné datum natvrdo v češtině', () => {
    expect(source.match(/toLocale(?:Date|Time)?String\('cs-CZ'/g) ?? []).toEqual([]);
  });

  it('slova u počtů jdou přes t()', () => {
    for (const word of ['vláken`', 'peerů`', 'baterie ${']) {
      const at = source.indexOf(word);
      expect(at, word).toBeGreaterThan(-1);
      // Each remaining occurrence is a t() fallback, inside its call.
      expect(source.slice(Math.max(0, at - 160), at)).toMatch(/t\('(?:asset|net)\.[a-z0-9_]+'/);
    }
  });
});
