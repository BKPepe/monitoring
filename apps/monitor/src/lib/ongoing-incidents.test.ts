import { describe, expect, it } from 'vitest';
import { ongoingIncidentCount } from './ongoing-incidents';

describe('ongoingIncidentCount', () => {
  it('nic neběží, uzavřený záznam se nepočítá', () => {
    expect(ongoingIncidentCount([], [])).toBe(0);
    expect(ongoingIncidentCount([], [{ id: 1, status: 'resolved' }])).toBe(0);
  });

  it('ohlášený incident bez výpadku se počítá', () => {
    expect(ongoingIncidentCount([], [{ id: 1, status: 'investigating' }])).toBe(1);
  });

  it('výpadek a jeho incident jsou jedna věc', () => {
    expect(ongoingIncidentCount([{ incidentId: 1 }], [{ id: 1, status: 'investigating' }])).toBe(1);
  });

  it('výpadek bez záznamu a cizí záznam jsou dvě', () => {
    expect(ongoingIncidentCount([{ incidentId: null }], [{ id: 2, status: 'monitoring' }])).toBe(2);
  });
});
