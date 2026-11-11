// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest';
import type { TimelineEvent } from '@/data/model';

// jsdom has no canvas 2D context and the real chart throws inside setOption.
vi.mock('@/components/charts/metric-chart', () => ({ MetricChart: () => null }));

import { eventMarkers } from './asset-detail';

/**
 * R117: the outage markers in the charts parsed `at`, the API's "d.m.Y H:i:s",
 * which is NaN, so none was ever drawn. From the ISO time, and only the
 * changes: a long outage is one marker where it began and one where it ended.
 */
const ev = (
  id: number,
  iso: string,
  severity: TimelineEvent['severity'],
  over: Partial<TimelineEvent> = {}
): TimelineEvent => ({
  id,
  title: `e${id}`,
  detail: '',
  at: '05.11.2026 08:00:00',
  atIso: iso,
  severity,
  resolution: severity === 'down' ? 'Open' : severity === 'up' ? 'Resolved' : 'Info',
  ...over,
});

describe('Značky výpadků v grafech (R117)', () => {
  it('výpadek o třech kontrolách je jedna značka na začátku a jedna na konci', () => {
    const events = [
      ev(6, '2026-11-05T08:05:00+01:00', 'info'),
      ev(5, '2026-11-05T08:04:00+01:00', 'up'),
      ev(4, '2026-11-05T08:03:00+01:00', 'down'),
      ev(3, '2026-11-05T08:02:00+01:00', 'down'),
      ev(2, '2026-11-05T08:01:00+01:00', 'down'),
      ev(1, '2026-11-05T08:00:00+01:00', 'info'),
    ];
    expect(eventMarkers(events)).toEqual([
      { t: Date.parse('2026-11-05T08:01:00+01:00'), label: 'e2', severity: 'alert' },
      { t: Date.parse('2026-11-05T08:04:00+01:00'), label: 'e5', severity: 'info' },
    ]);
  });

  it('řádek bez strojového času a řádek vzdálené sondy značku nedají', () => {
    expect(
      eventMarkers([ev(2, '2026-11-05T08:01:00+01:00', 'down', { probe: true }), ev(1, '', 'down', { atIso: null })])
    ).toEqual([]);
  });
});
