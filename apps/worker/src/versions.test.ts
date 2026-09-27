import { afterEach, describe, expect, it, vi } from 'vitest';
import worker from './index';

// /api/versions feeds the site's hero badge and download button. It names the
// release that releases/latest/download/bloodkings-monitoring.zip serves, and
// "no release yet" is an answer (null), not an error.
async function versions(answer: Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => answer.clone())
  );
  const res = await worker.fetch(new Request('https://api.bloodkings.eu/api/versions'), {}, undefined);
  return { status: res.status, body: (await res.json()) as Record<string, unknown> };
}

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('/api/versions', () => {
  it('vrátí tag a datum posledního releasu', async () => {
    const { status, body } = await versions(
      Response.json({ tag_name: 'v0.3.0-alpha', published_at: '2026-09-24T10:00:00Z' })
    );
    expect(status).toBe(200);
    expect(body).toEqual({ monitoring: 'v0.3.0-alpha', latestReleaseDate: '2026-09-24' });
  });

  it('bez releasu odpoví null, ne chybou ani vymyšlenou verzí', async () => {
    const { status, body } = await versions(new Response('{"message":"Not Found"}', { status: 404 }));
    expect(status).toBe(200);
    expect(body).toEqual({ monitoring: null, latestReleaseDate: null });
  });

  it('když GitHub neodpoví, vrátí 503', async () => {
    const { status, body } = await versions(new Response('oops', { status: 502, statusText: 'Bad Gateway' }));
    expect(status).toBe(503);
    expect(body.error).toMatch(/502/);
  });
});
