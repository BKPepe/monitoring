// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';

// jsdom has no canvas 2D context and the real chart throws inside setOption.
vi.mock('@/components/charts/metric-chart', () => ({ MetricChart: () => null }));

import { wifiClientTotal } from './asset-detail';

/** R63: a router that counted no Wi-Fi client read "Wi-Fi (0 klientů)". */
describe('Počet Wi-Fi klientů (R63)', () => {
  it('celkový počet routeru má přednost, i nula', () => {
    expect(wifiClientTotal(3, [{ clients: 9 }])).toBe(3);
    expect(wifiClientTotal(0, [])).toBe(0);
  });

  it('bez celkového počtu sečte jen rádia, která počet hlásí', () => {
    expect(wifiClientTotal(null, [{ clients: 2 }, { clients: null }, { clients: '1' }])).toBe(3);
  });

  it('nikdo nepočítal: počet není, ne nula', () => {
    expect(wifiClientTotal(undefined, [{ clients: null }, {}])).toBeNull();
    expect(wifiClientTotal(null, [])).toBeNull();
  });
});
