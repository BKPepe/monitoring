import { describe, expect, it, vi } from 'vitest';

// The panel draws through ECharts; only the binning is tested here.
vi.mock('./chart', () => ({ Chart: () => null }));

import { buildHistogram } from './histogram-panel';

/** R304: the bin edges had no locale, "0.5–1" on a Czech page. */
describe('buildHistogram', () => {
  const values = [0.2, 0.4, 0.6, 0.9, 1.3, 1.7, 2.2];

  it('writes the bin edges in the page number format', () => {
    expect(buildHistogram(values, 'cs-CZ').labels).toContain('0,5–1');
    expect(buildHistogram(values, 'en-GB').labels).toContain('0.5–1');
  });

  it('a whole number stays whole', () => {
    expect(buildHistogram([3, 3], 'cs-CZ').labels).toEqual(['3']);
  });
});
