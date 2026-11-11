// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { httpMetricsSource } from './http-source';
import { uiText } from './ui-text';

/** R309: the data layer's own errors were Czech on the English page. */
afterEach(() => {
  vi.unstubAllGlobals();
  document.documentElement.lang = 'cs';
});

describe('Hlášky datové vrstvy v jazyce stránky (R309)', () => {
  it('uiText jde za <html lang>', () => {
    document.documentElement.lang = 'en';
    expect(uiText('Načtení selhalo', 'Loading failed')).toBe('Loading failed');
    document.documentElement.lang = 'cs';
    expect(uiText('Načtení selhalo', 'Loading failed')).toBe('Načtení selhalo');
  });

  it('neplatná odpověď grafu je na anglické stránce anglicky', async () => {
    document.documentElement.lang = 'en';
    vi.stubGlobal('fetch', () =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) } as Response)
    );
    await expect(httpMetricsSource.getMetricSeries(7, 'cpu', '24h')).rejects.toThrow('Invalid metric_series answer.');
  });
});
