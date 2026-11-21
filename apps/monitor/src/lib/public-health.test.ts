import { describe, expect, it } from 'vitest';
import { parsePublicHealth } from './public-health';

const answer = (network: unknown) => ({ network, formulaVersion: 1, generatedAt: '2026-09-27T05:00:00+02:00' });

describe('parsePublicHealth: veřejné skóre jen čte odpověď serveru', () => {
  it('skóre, známka, složky a jmenované srážky projdou beze změny', () => {
    const h = parsePublicHealth(
      answer({
        score: 87,
        grade: 'fair',
        formulaVersion: 1,
        assetsScored: 5,
        assetsTotal: 6,
        components: [
          { key: 'availability', label: 'Dostupnost', weight: 30, points: 95, assets: 5 },
          { key: 'latency', label: 'Odezva', weight: 10, points: null, assets: 0 },
        ],
        deductions: [
          {
            monitorId: 3,
            monitorName: 'E-shop',
            component: 'availability',
            kind: 'availability_low',
            label: 'Dostupnost 97,2 % za 7 dní',
            points: 3.5,
          },
        ],
      })
    );
    expect(h.score).toBe(87);
    expect(h.grade).toBe('fair');
    expect(h.assetsScored).toBe(5);
    expect(h.components.map((c) => [c.key, c.points])).toEqual([
      ['availability', 95],
      ['latency', null],
    ]);
    expect(h.deductions).toEqual([
      {
        monitorId: 3,
        monitorName: 'E-shop',
        component: 'availability',
        label: 'Dostupnost 97,2 % za 7 dní',
        points: 3.5,
      },
    ]);
  });

  it('score null je „nedostatek dat“ i bez známky; nic se nedopočítá', () => {
    const h = parsePublicHealth(answer({ score: null, grade: 'good', components: [], deductions: [] }));
    expect(h.score).toBeNull();
    expect(h.grade).toBeNull();
  });

  it('odpověď jiného tvaru je selhání, ne pomlčka: chybí síť, skóre je text, chybí složky', () => {
    expect(() => parsePublicHealth({})).toThrow();
    expect(() => parsePublicHealth(answer({ score: '87', components: [] }))).toThrow();
    expect(() => parsePublicHealth(answer({ score: 87 }))).toThrow();
  });

  it('srážka bez bodů nebo bez popisu se nevykreslí', () => {
    const h = parsePublicHealth(
      answer({
        score: 99,
        grade: 'good',
        components: [],
        deductions: [
          { monitorId: 1, monitorName: 'Web', component: 'latency', label: '', points: 1 },
          { monitorId: 1, monitorName: 'Web', component: 'latency', label: 'Odezva 900 ms', points: 0 },
        ],
      })
    );
    expect(h.deductions).toEqual([]);
  });
});
