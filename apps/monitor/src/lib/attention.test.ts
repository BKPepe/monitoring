import { describe, expect, it } from 'vitest';
import { buildNeedsAttention, type AttentionLabels } from './attention';
import type { ApiMonitor } from '@/api/app-api';

/**
 * Sekce „Vyžaduje pozornost" rozhoduje, na co se admin podívá první.
 * Testuje se hlavně to, co tenhle projekt opakovaně pálilo: nezměřená
 * hodnota (null) nesmí nikdy založit upozornění, a prázdno musí zůstat
 * prázdnem místo vycpávky.
 */

const labels: AttentionLabels = {
  down: 'DOWN',
  warning: 'WARN',
  unreachable: 'UNREACHABLE',
  sslExpired: 'SSL_EXPIRED',
  sslExpiring: (d) => `SSL_${d}`,
  agentUpdate: (v) => `AGENT_${v}`,
  metricHigh: (m, v) => `${m}_${v}`,
};

const mon = (over: Partial<ApiMonitor>): ApiMonitor =>
  ({ id: 1, name: 'X', target: '', type: 'web', status: 'up', cpu: null, ram: null, hdd: null, ...over }) as ApiMonitor;

describe('buildNeedsAttention', () => {
  it('zdravá infrastruktura nevyrobí ani jednu položku', () => {
    const rows = [mon({ id: 1, status: 'up' }), mon({ id: 2, status: 'paused' })];
    expect(buildNeedsAttention(rows, labels)).toEqual([]);
  });

  it('nezměřené metriky (null) nikdy nezakládají upozornění', () => {
    const rows = [mon({ id: 1, cpu: null, ram: null, hdd: null })];
    expect(buildNeedsAttention(rows, labels)).toEqual([]);
  });

  it('metrika hlásí až od prahu 90 %', () => {
    expect(buildNeedsAttention([mon({ cpu: 89.4 })], labels)).toEqual([]);
    const at90 = buildNeedsAttention([mon({ cpu: 90 })], labels);
    expect(at90).toHaveLength(1);
    expect(at90[0].text).toBe('CPU_90');
  });

  it('zaokrouhluje procenta, nevypisuje desetinná místa', () => {
    expect(buildNeedsAttention([mon({ ram: 93.7 })], labels)[0].text).toBe('RAM_94');
  });

  it('výpadek má přednost před varováním, jinak se řadí podle jména', () => {
    const rows = [
      mon({ id: 1, name: 'Zeta', status: 'warning' }),
      mon({ id: 2, name: 'Beta', status: 'down' }),
      mon({ id: 3, name: 'Alfa', status: 'warning' }),
    ];
    const out = buildNeedsAttention(rows, labels);
    expect(out.map((i) => i.name)).toEqual(['Beta', 'Alfa', 'Zeta']);
    expect(out[0].severity).toBe('down');
  });

  it('vypršelý certifikát je závažnost down, blížící se expirace warning', () => {
    const expired = buildNeedsAttention([mon({ details: { ssl_days_remaining: 0 } })], labels);
    expect(expired[0]).toMatchObject({ severity: 'down', text: 'SSL_EXPIRED' });

    const soon = buildNeedsAttention([mon({ details: { ssl_days_remaining: 5 } })], labels);
    expect(soon[0]).toMatchObject({ severity: 'warning', text: 'SSL_5' });
  });

  it('certifikát platný déle než 14 dní se nehlásí', () => {
    expect(buildNeedsAttention([mon({ details: { ssl_days_remaining: 15 } })], labels)).toEqual([]);
  });

  it('jeden monitor může mít víc problémů zároveň a každý dostane vlastní řádek', () => {
    const rows = [
      mon({
        id: 7,
        name: 'Router',
        status: 'up',
        hdd: 95,
        unreachableTarget: true,
        agentUpdateAvailable: '1.8.0',
      }),
    ];
    const out = buildNeedsAttention(rows, labels);
    expect(out.map((i) => i.text).sort()).toEqual(['AGENT_1.8.0', 'Disk_95', 'UNREACHABLE']);
    // Klíče musí být unikátní, jinak React vypíše jen jeden řádek.
    expect(new Set(out.map((i) => i.key)).size).toBe(out.length);
  });

  it('každá položka odkazuje na svůj monitor', () => {
    const out = buildNeedsAttention([mon({ id: 42, status: 'down' })], labels);
    expect(out[0].assetId).toBe(42);
  });
});
