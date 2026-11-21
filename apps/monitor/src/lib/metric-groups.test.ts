import { describe, expect, it } from 'vitest';
import type { ChartData } from '@/api/types';
import { groupMetrics, isUnchanged, latestValue, metricSubsystem, subsystemTitle } from './metric-groups';

const chart = (id: string, values: (number | null)[]): ChartData => ({
  id,
  title: id,
  yMax: null,
  series: [{ key: id, label: id, unit: '%', tone: 'cpu', points: values.map((v, i) => ({ t: i * 60_000, v })) }],
});

describe('metricSubsystem (C-13)', () => {
  it('rozřadí metriky podle části zařízení', () => {
    expect(metricSubsystem('load5')).toBe('system');
    expect(metricSubsystem('ram_free_mb')).toBe('memory_disk');
    expect(metricSubsystem('disk_io_write')).toBe('memory_disk');
    expect(metricSubsystem('net_lte')).toBe('lte');
    expect(metricSubsystem('net_errors')).toBe('wan');
    expect(metricSubsystem('wan_uptime')).toBe('wan');
    expect(metricSubsystem('wifi_noise_5g')).toBe('wifi');
    expect(metricSubsystem('dns_latency_ms')).toBe('dns');
    expect(metricSubsystem('conntrack_count')).toBe('firewall_vpn');
    expect(metricSubsystem('agent_run_ms')).toBe('agent');
    expect(metricSubsystem('ups_battery_pct')).toBe('other');
  });
});

describe('groupMetrics', () => {
  it('skupiny jdou v pevném pořadí a nehybné metriky oddělí', () => {
    const groups = groupMetrics([
      chart('wifi_busy_5g', [10, 30, 20]),
      chart('load5', [1, 1, 1]),
      chart('load15', [0.5, 0.7, 0.6]),
    ]);
    expect(groups.map((g) => g.subsystem)).toEqual(['system', 'wifi']);
    expect(groups[0].unchanged.map((c) => c.id)).toEqual(['load5']);
    expect(groups[0].changed.map((c) => c.id)).toEqual(['load15']);
  });

  it('nejvýraznější je metrika, která se nejvíc pohnula vůči své velikosti', () => {
    const [group] = groupMetrics([chart('cpu_steal', [0.1, 0.2]), chart('iowait', [1, 5])]);
    expect(group.notable?.id).toBe('iowait');
  });

  it('když se nic nepohnulo, nejvýraznější metrika není', () => {
    const [group] = groupMetrics([chart('load1', [2, 2])]);
    expect(group.notable).toBeNull();
  });
});

describe('isUnchanged a latestValue', () => {
  it('jediné měření neříká „beze změny“', () => {
    expect(isUnchanged(chart('x', [5]))).toBe(false);
    expect(isUnchanged(chart('x', [5, null, 5]))).toBe(true);
  });

  it('poslední hodnota přeskočí mezeru na konci', () => {
    expect(latestValue(chart('x', [1, 2, null]))).toBe(2);
    expect(latestValue(chart('x', [null]))).toBeNull();
  });

  it('názvy skupin jdou přes slovník', () => {
    const t = (_key: string, fallback?: unknown) => String(fallback);
    expect(subsystemTitle('memory_disk', t)).toBe('Paměť a disk');
  });
});
