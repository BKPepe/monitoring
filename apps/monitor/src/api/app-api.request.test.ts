import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, appApi } from './app-api';

const answer = (body: string, status: number) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve().then(() => JSON.parse(body)),
  }) as unknown as Response;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('request(): odpověď, která není JSON', () => {
  it('stránka hostingu s kódem 200 je chyba, ne prázdná odpověď', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => answer('<html>Hosting</html>', 200))
    );
    await expect(appApi.getSession()).rejects.toBeInstanceOf(ApiError);
    await expect(appApi.getSession()).rejects.toThrow('Server neposlal čitelnou odpověď (HTTP 200).');
  });

  it('chyba bez JSON těla nese kód', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => answer('Bad gateway', 502))
    );
    await expect(appApi.getSession()).rejects.toThrow('HTTP 502');
  });

  it('čitelná odpověď projde', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => answer('{"authenticated":false,"csrfToken":"t"}', 200))
    );
    await expect(appApi.getSession()).resolves.toMatchObject({ authenticated: false });
  });
});
