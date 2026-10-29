import { afterEach, describe, expect, it, vi } from 'vitest';
import { appApi } from './app-api';
import { httpMetricsSource } from './http-source';

/**
 * One URL scheme: every read goes to api.php and a failure is reported as
 * one. A failed chart used to send a second request to /api/v1/..., a route
 * of the Go server that was never deployed, and session/logout were pinned
 * to /status/api.php whatever VITE_STATUS_API said. Those two cases guard a
 * removal; the signed-in client's app_api.php fallback could never fire, so
 * its case only pins the one URL request() builds.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  }) as Response;

function stubFetch(answer: Response) {
  const fetchMock = vi.fn((_input: RequestInfo | URL, _init?: RequestInit) => Promise.resolve(answer));
  vi.stubGlobal('fetch', fetchMock);
  return () => fetchMock.mock.calls.map(([u]) => String(u));
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
  vi.resetModules();
});

describe('Datová vrstva: jedno schéma URL bez záloh na Go', () => {
  it('selhaný metric_series je jedna žádost na api.php a chyba, žádný druhý pokus', async () => {
    const urls = stubFetch(json({ error: 'database_unavailable' }, 500));

    await expect(httpMetricsSource.getMetricSeries(7, 'cpu', '24h')).rejects.toThrow('HTTP 500');

    expect(urls()).toHaveLength(1);
    expect(urls()[0]).toMatch(/^\/status\/api\.php\?action=metric_series&monitor_id=7&metric=cpu&period=24h/);
    expect(urls().some((u) => u.includes('/api/v1/'))).toBe(false);
  });

  it('úspěšný metric_series čte jen api.php', async () => {
    const urls = stubFetch(json({ points: [[1, 2]], unit: '%', label: 'CPU' }));

    const res = await httpMetricsSource.getMetricSeries(7, 'cpu', '24h');

    expect(res.points).toHaveLength(1);
    expect(urls()).toEqual([expect.stringMatching(/^\/status\/api\.php\?action=metric_series&/)]);
  });

  it('selhané volání přihlášené části je jedna žádost na api.php a ApiError s textem serveru', async () => {
    const urls = stubFetch(json({ error: 'forbidden', message: 'Jen pro správce.' }, 403));

    await expect(appApi.getUsers()).rejects.toMatchObject({ status: 403, message: 'Jen pro správce.' });

    expect(urls()).toEqual(['/status/api.php?action=users']);
  });

  it('přihlášení a odhlášení jdou na stejné api.php jako data, i mimo /status', async () => {
    // STATUS_API is read once at import, so the module is loaded again
    // after the variable changes.
    vi.stubEnv('VITE_STATUS_API', '/jinde');
    vi.resetModules();
    const { appApi: api } = await import('./app-api');
    const urls = stubFetch(json({ authenticated: false, user: null, csrfToken: null, loginUrl: '', success: true }));

    await api.getSession();
    await api.logout();

    expect(urls()).toEqual(['/jinde/api.php?action=session', '/jinde/api.php?action=logout']);
  });
});
