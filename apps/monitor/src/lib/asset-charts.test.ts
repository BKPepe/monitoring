import { describe, expect, it } from 'vitest';
import type { ChartData } from '@/api/types';
import { featuredIds, isFlat, MAX_FEATURED, planOverview, valueRange } from './asset-charts';

const chart = (id: string, values: (number | null)[], unit = '%'): ChartData => ({
  id,
  title: id,
  yMax: null,
  series: [
    {
      key: id,
      label: id,
      unit,
      tone: 'latency',
      points: values.map((v, i) => ({ t: i * 60_000, v })),
    },
  ],
});

describe('featuredIds (W2-2)', () => {
  it('router dostane CPU, RAM, WAN, teplotu, odezvu a disk - nejvýš šest', () => {
    expect(featuredIds('openwrt')).toEqual(['cpu', 'ram', 'net', 'temperature_c', 'response_time', 'hdd']);
    expect(featuredIds('VPS').length).toBeLessThanOrEqual(MAX_FEATURED);
  });

  it('neznámý typ dostane obvyklou šestici, ne nic', () => {
    expect(featuredIds('neco-noveho')).toHaveLength(MAX_FEATURED);
  });
});

describe('isFlat (W2-2)', () => {
  it('procenta v rozmezí jednoho bodu jsou rovná čára', () => {
    expect(isFlat(chart('iowait', [0.3, 0.6, 0.4]))).toBe(true);
    expect(isFlat(chart('cpu', [10, 40]))).toBe(false);
  });

  it('jiné jednotky: rovná do 2 % velikosti, nula je rovná vždy', () => {
    expect(isFlat(chart('temperature_c', [60, 61], '°C'))).toBe(true);
    expect(isFlat(chart('response_time', [3, 5], 'ms'))).toBe(false);
    expect(isFlat(chart('net', [0, 0, null, 0], 'KB/s'))).toBe(true);
  });

  it('jediné měření o průběhu nic neříká - není „beze změny“', () => {
    expect(isFlat(chart('cpu', [12, null]))).toBe(false);
  });

  it('rozsah hodnot bez mezer', () => {
    expect(valueRange(chart('iowait', [0.6, null, 0.3]))).toEqual({ min: 0.3, max: 0.6 });
    expect(valueRange(chart('iowait', [null]))).toBeNull();
  });
});

describe('planOverview (charts-08)', () => {
  const data = [
    chart('response_time', [3, 9], 'ms'),
    chart('cpu', [5, 60]),
    chart('ram', [40, 41]),
    chart('net', [100, 900], 'KB/s'),
    chart('net_lte', [0, 300], 'KB/s'),
    chart('iowait', [0.1, 3]),
    chart('ram_used_mb', [200, 260], 'MB'),
    chart('load5', [0.1, 0.9], ''),
    chart('wifi_clients', [3, 9], ''),
  ];

  it('karty podle typu, rovná čára zvlášť, zbytek do skupin bez přepočtů RAM a load5/15', () => {
    const plan = planOverview(data, 'openwrt');
    expect(plan.cards.map((c) => c.id)).toEqual(['cpu', 'net', 'response_time']);
    expect(plan.flat.map((c) => c.id)).toEqual(['ram']);
    expect(plan.others.map((c) => c.id)).toEqual(['net_lte', 'iowait', 'wifi_clients']);
  });

  it('rovný graf nad vlastním limitem zůstane kartou s pásmem (CR-8)', () => {
    const full = {
      ...chart('hdd', [97, 97.2]),
      bands: [{ from: 90, to: 100, tone: 'critical' as const, label: 'Kritické' }],
    };
    const calm = {
      ...chart('ram', [40, 41]),
      bands: [{ from: 90, to: 100, tone: 'critical' as const, label: 'Kritické' }],
    };
    const plan = planOverview([full, calm], 'openwrt');
    expect(plan.cards.map((c) => c.id)).toEqual(['hdd']);
    expect(plan.flat.map((c) => c.id)).toEqual(['ram']);
  });

  it('se společným grafem WAN + LTE zmizí samostatné WAN i LTE', () => {
    const combined = { ...chart('net-combined', [100, 1200], 'KB/s'), stacked: true };
    const plan = planOverview(data, 'openwrt', combined);
    expect(plan.cards.map((c) => c.id)).toEqual(['cpu', 'net-combined', 'response_time']);
    expect(plan.others.map((c) => c.id)).toEqual(['iowait', 'wifi_clients']);
  });
});
