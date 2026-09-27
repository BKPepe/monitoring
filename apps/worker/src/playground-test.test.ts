import { afterEach, describe, expect, it, vi } from 'vitest';
import worker from './index';

// /api/test backs the site's playground. It measures one GET and may report
// only that: the status, the time and, for a 3xx, where it points.
let clientIp = 0;

async function check(target: string, answer: Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => answer)
  );
  // A fresh client address per call keeps the per-IP rate limit out of the way.
  clientIp += 1;
  const res = await worker.fetch(
    new Request(`https://api.bloodkings.eu/api/test?url=${encodeURIComponent(target)}`, {
      headers: { 'CF-Connecting-IP': `198.51.100.${clientIp}` },
    }),
    {},
    undefined
  );
  return { status: res.status, body: (await res.json()) as Record<string, unknown> };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('playground /api/test', () => {
  it('přesměrování vrátí absolutní cíl a nenásleduje ho', async () => {
    const { body } = await check(
      'http://example.com/old',
      new Response(null, { status: 301, headers: { location: '/new' } })
    );
    expect(body.status).toBe(301);
    expect(body.redirectTo).toBe('http://example.com/new');
  });

  it('bez přesměrování je redirectTo null', async () => {
    const { body } = await check('https://example.com/', new Response('x', { status: 200 }));
    expect(body.redirectTo).toBeNull();
  });

  it('prázdný reason phrase se nedomýšlí - 404 nedostane "Failed"', async () => {
    const { body } = await check('https://example.com/missing', new Response(null, { status: 404, statusText: '' }));
    expect(body.status).toBe(404);
    expect(body.statusText).toBe('');
  });

  it('žádná odpověď má status 0 a chybu, ne vymyšlený kód', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('Network connection lost.');
      })
    );
    clientIp += 1;
    const res = await worker.fetch(
      new Request('https://api.bloodkings.eu/api/test?url=https%3A%2F%2Fexample.com', {
        headers: { 'CF-Connecting-IP': `198.51.100.${clientIp}` },
      }),
      {},
      undefined
    );
    const body = (await res.json()) as Record<string, unknown>;
    expect(body.status).toBe(0);
    expect(body.statusText).toBe('Failed');
    expect(body.error).toBe('Network connection lost.');
    expect(body.redirectTo).toBeNull();
  });
});
