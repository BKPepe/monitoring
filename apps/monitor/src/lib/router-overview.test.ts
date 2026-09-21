import { describe, expect, it } from 'vitest';
import { busiestCoreHint, socTemperatureC } from './router-overview';

const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) => {
  const text = typeof params === 'string' ? params : (fallback ?? key);
  if (!params || typeof params === 'string') return text;
  return text.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''));
};

describe('socTemperatureC (G22)', () => {
  it('reads the payload key the agent sends', () => {
    // The tile read `temperature_c` (the vps_metrics column) and stayed empty
    // on every router for years - this is the whole gap item.
    expect(socTemperatureC({ temperature: 67.5 })).toBe(67.5);
  });

  it('still accepts a stored detail blob that carries the column name', () => {
    expect(socTemperatureC({ temperature_c: 54 })).toBe(54);
  });

  it('has no temperature when the router reported none', () => {
    expect(socTemperatureC({})).toBeNull();
    expect(socTemperatureC({ temperature: null })).toBeNull();
  });
});

describe('busiestCoreHint', () => {
  it('names the core and its load once the average hides it', () => {
    expect(busiestCoreHint({ cpu_core_max_pct: 97.4, cpu_core_max_index: 1 }, 52, t)).toBe(
      'nejvytíženější jádro 1: 97 %'
    );
  });

  it('stays away while the average tells the same story', () => {
    expect(busiestCoreHint({ cpu_core_max_pct: 60, cpu_core_max_index: 0 }, 50, t)).toBeNull();
  });

  it('drops the index when the router did not send one', () => {
    expect(busiestCoreHint({ cpu_core_max_pct: 91 }, 40, t)).toBe('nejvytíženější jádro: 91 %');
  });

  it('says nothing without a core reading or without the average', () => {
    expect(busiestCoreHint({ cpu_core_max_index: 1 }, 40, t)).toBeNull();
    expect(busiestCoreHint({ cpu_core_max_pct: 91 }, null, t)).toBeNull();
  });
});
