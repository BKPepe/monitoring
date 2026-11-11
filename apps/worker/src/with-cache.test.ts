import { afterEach, describe, expect, it, vi } from 'vitest';
import { withCache } from './index';

/** R232: a failing upstream was asked twice, the second time from the catch. */
describe('withCache', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('volá upstream jen jednou, i když selže', async () => {
    vi.stubGlobal('caches', { default: { match: async () => undefined, put: async () => undefined } });
    const fetcher = vi.fn(async () => {
      throw new Error('GitHub 502');
    });
    await expect(withCache('k', 60, fetcher)).rejects.toThrow('GitHub 502');
    expect(fetcher).toHaveBeenCalledTimes(1);
  });

  it('bez Cache API odpoví z upstreamu, jednou', async () => {
    vi.stubGlobal('caches', undefined);
    const fetcher = vi.fn(async () => ({ ok: true }));
    await expect(withCache('k', 60, fetcher)).resolves.toEqual({ ok: true });
    expect(fetcher).toHaveBeenCalledTimes(1);
  });
});
