import { describe, expect, it } from 'vitest';
import { healthGradeOf, healthTone, parseFleetHealth, splitHealthComponents, type HealthComponent } from './health';

describe('skóre zdraví: pásma a čtení odpovědi serveru', () => {
  it('pásma odpovídají serveru: dobré od 90, ucházející od 70, jinak slabé', () => {
    expect(healthGradeOf(100)).toBe('good');
    expect(healthGradeOf(90)).toBe('good');
    expect(healthGradeOf(89)).toBe('fair');
    expect(healthGradeOf(70)).toBe('fair');
    expect(healthGradeOf(69)).toBe('poor');
    expect(healthGradeOf(0)).toBe('poor');
  });

  it('bez skóre není žádné pásmo a barva je neutrální, nikdy zelená', () => {
    expect(healthGradeOf(null)).toBeNull();
    expect(healthGradeOf(undefined)).toBeNull();
    expect(healthGradeOf(Number.NaN)).toBeNull();
    expect(healthTone(null)).toBe('neutral');
    expect(healthTone('good')).toBe('up');
    expect(healthTone('fair')).toBe('warning');
    expect(healthTone('poor')).toBe('down');
  });

  it('neměřené složky se oddělí, měřené jdou od nejhorší', () => {
    const c: HealthComponent[] = [
      { key: 'availability', weight: 30, points: 98 },
      { key: 'temperature', weight: 10, points: null },
      { key: 'latency', weight: 10, points: 60 },
      { key: 'alerts', weight: 15, points: 60 },
    ];
    const { measured, unmeasured } = splitHealthComponents(c);
    expect(measured.map((x) => x.key)).toEqual(['alerts', 'latency', 'availability']);
    expect(unmeasured.map((x) => x.key)).toEqual(['temperature']);
    // Pure: the caller's array keeps its order.
    expect(c[0].key).toBe('availability');
  });
});

/** `action=health` in the app view, as api.php sends it. */
const ANSWER = {
  network: {
    score: 80,
    grade: 'fair',
    formulaVersion: 1,
    assetsScored: 2,
    assetsTotal: 3,
    components: [{ key: 'availability', weight: 30, points: 98, assets: 2 }],
    deductions: [{ component: 'cpu_ram', kind: 'cpu_high', label: 'CPU 95 %', points: 4, monitorId: 4 }],
  },
  assets: [
    { monitorId: 1, score: 92, grade: 'good', formulaVersion: 1, components: [], deductions: [] },
    { monitorId: 2, score: null, grade: null, formulaVersion: 1, components: [] },
    { monitorId: 3, score: null, grade: null, formulaVersion: 1, components: [], paused: true },
  ],
  formulaVersion: 1,
  generatedAt: '2026-09-23T10:00:00+02:00',
};

describe('action=health v aplikaci: jeden parser pro přehled i seznam zařízení', () => {
  it('síť i skóre po monitorech se čtou tak, jak je server poslal', () => {
    const h = parseFleetHealth(ANSWER);
    expect(h.network.score).toBe(80);
    expect(h.network.grade).toBe('fair');
    expect(h.network.assetsScored).toBe(2);
    expect(h.network.components).toEqual(ANSWER.network.components);
    expect(h.network.deductions).toEqual(ANSWER.network.deductions);
    expect([...h.assets.keys()]).toEqual([1, 2, 3]);
    expect(h.assets.get(1)).toMatchObject({ score: 92, grade: 'good' });
    // Too little data is the server's null, kept as null ("—"), never 0.
    expect(h.assets.get(2)).toMatchObject({ score: null, grade: null });
    expect(h.assets.get(2)?.paused).toBeUndefined();
    expect(h.assets.get(3)?.paused).toBe(true);
  });

  it('odpověď bez skóre sítě nebo bez seznamu monitorů je selhání, ne prázdná flotila', () => {
    expect(() => parseFleetHealth({})).toThrow();
    expect(() => parseFleetHealth(null)).toThrow();
    expect(() => parseFleetHealth({ error: 'health_unavailable' })).toThrow();
    expect(() => parseFleetHealth({ assets: ANSWER.assets })).toThrow('no network score');
    expect(() => parseFleetHealth({ network: { score: 80, grade: 'fair' }, assets: [] })).toThrow('no network score');
    // The public view sends no monitor scores: not a fleet of unscored devices.
    expect(() => parseFleetHealth({ network: ANSWER.network })).toThrow('no monitor scores');
  });

  it('neplatné skóre je null, neznámé pásmo null, paused jen jako true', () => {
    const h = parseFleetHealth({
      network: { ...ANSWER.network, score: 'osmdesát', grade: 'excellent' },
      assets: [
        { monitorId: 5, score: Number.NaN, grade: 'great', paused: 'yes', components: [] },
        { monitorId: 6, score: 70, grade: 'fair' },
      ],
    });
    expect(h.network.score).toBeNull();
    expect(h.network.grade).toBeNull();
    expect(h.assets.get(5)).toMatchObject({ score: null, grade: null });
    expect(h.assets.get(5)?.paused).toBeUndefined();
    // An asset without its breakdown still has a list to draw from.
    expect(h.assets.get(6)?.components).toEqual([]);
  });

  it('položka bez platného id monitoru se přeskočí, ostatní zůstanou', () => {
    const h = parseFleetHealth({
      network: ANSWER.network,
      assets: [
        null,
        'x',
        { score: 50 },
        { monitorId: 0, score: 1 },
        { monitorId: '7', score: 1 },
        { monitorId: 8, score: 60 },
      ],
    });
    expect([...h.assets.keys()]).toEqual([8]);
  });
});
