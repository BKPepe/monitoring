import { describe, expect, it } from 'vitest';
import { healthGradeOf, healthTone, splitHealthComponents, type HealthComponent } from './health';

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
