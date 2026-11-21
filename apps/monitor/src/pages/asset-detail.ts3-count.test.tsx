// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';

// jsdom has no canvas 2D context and the real chart throws inside setOption.
vi.mock('@/components/charts/metric-chart', () => ({ MetricChart: () => null }));

import { ts3ClientCounts } from './asset-detail';

/**
 * R134: the tile read ts3_clients / ts3_max, which nothing writes, and else
 * the first of the agent's servers, whichever voice port it had.
 */
describe('Klienti TS3 v detailu (R134)', () => {
  it('počet z kontroly ServerQuery', () => {
    expect(ts3ClientCounts({ clients_online: 4, clients_max: 32 })).toEqual({ online: 4, max: 32 });
  });

  it('bez kontroly počet, který agent spároval podle hlasového portu', () => {
    expect(
      ts3ClientCounts({
        ts3_clients_online: 2,
        ts3_clients_max: 10,
        teamspeak_servers: [{ clients_online: 9, clients_max: 99 }],
      })
    ).toEqual({ online: 2, max: 10 });
  });

  it('první server agenta není tenhle monitor; nic neznámého se nevymyslí', () => {
    expect(ts3ClientCounts({ teamspeak_servers: [{ clients_online: 9, clients_max: 99 }] })).toBeNull();
    expect(ts3ClientCounts({ clients_online: 4 })).toBeNull();
    expect(ts3ClientCounts(undefined)).toBeNull();
  });
});
