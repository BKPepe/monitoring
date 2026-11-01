import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  WATCHDOG_DEFAULT_TARGET,
  probeWebhook,
  runWatchdogCheck,
  sendWatchdogAlert,
  timingSafeEqual,
  watchdogAlertToSend,
  watchdogMemoryAfter,
  watchdogTick,
  type WatchdogCache,
  type WatchdogDeps,
} from './watchdog';

// The watchdog is the one alert left when cron on the hosting dies. Every
// branch runs here on a fake fetch and a fake cache, never the network.
const WEBHOOK = 'https://discord.com/api/webhooks/1/test';
const STATUS_OK = { stale: false, lastRunAt: '2026-11-01T10:00:00+01:00', ageSecs: 42, maxAgeSecs: 900 };

/** The Cache API as a Map: what was put is matched until deleted. */
class FakeCache implements WatchdogCache {
  readonly entries = new Map<string, Response>();
  async match(key: string) {
    return this.entries.get(key)?.clone();
  }
  async put(key: string, response: Response) {
    this.entries.set(key, response.clone());
  }
  async delete(key: string) {
    return this.entries.delete(key);
  }
}

type Answer = Response | Error | (() => Response);

/**
 * Answers the status URL with `status` and the webhook with `discord`; records
 * every call. An Error is thrown as a network failure would be.
 */
function fakeFetch(status: Answer, discord: Answer = new Response(null, { status: 204 })) {
  const answer = (a: Answer) => {
    if (a instanceof Error) throw a;
    return typeof a === 'function' ? a() : a.clone();
  };
  return vi.fn(async (input: string, _init?: RequestInit) => answer(input === WEBHOOK ? discord : status));
}

function deps(fetch: WatchdogDeps['fetch'], options: { webhook?: string | null; cache?: WatchdogCache | null } = {}) {
  const webhook = options.webhook === undefined ? WEBHOOK : options.webhook;
  return {
    env: webhook === null ? {} : { WATCHDOG_DISCORD_WEBHOOK: webhook },
    fetch,
    cache: options.cache === undefined ? new FakeCache() : options.cache,
  } satisfies WatchdogDeps;
}

const discordPosts = (fetch: ReturnType<typeof fakeFetch>) =>
  fetch.mock.calls
    .filter(([url, init]) => url === WEBHOOK && init?.method === 'POST')
    .map(([, init]) => JSON.parse(String(init?.body)).content as string);

afterEach(() => {
  vi.restoreAllMocks();
});

describe('kontrola sběru dat', () => {
  it('běžící cron je zdravý s časem posledního běhu a odezvou měřenou odsud', async () => {
    const fetch = fakeFetch(Response.json(STATUS_OK));
    const result = await runWatchdogCheck(deps(fetch));
    expect(result).toMatchObject({ healthy: true, reason: null, lastRunAt: STATUS_OK.lastRunAt, ageSecs: 42 });
    expect(result.latencyMs).toBeGreaterThanOrEqual(0);
    expect(fetch).toHaveBeenCalledWith(
      WATCHDOG_DEFAULT_TARGET,
      expect.objectContaining({ headers: { 'User-Agent': 'BloodKings-Monitoring-Watchdog' } })
    );
  });

  it('WATCHDOG_TARGET_URL přepíše sledovanou adresu', async () => {
    const fetch = fakeFetch(Response.json(STATUS_OK));
    await runWatchdogCheck({ ...deps(fetch), env: { WATCHDOG_TARGET_URL: 'https://test.example/status' } });
    expect(fetch.mock.calls[0][0]).toBe('https://test.example/status');
  });

  it('zastavený cron řekne, jak dávno běžel a jaký je limit', async () => {
    const fetch = fakeFetch(
      Response.json({ stale: true, lastRunAt: STATUS_OK.lastRunAt, ageSecs: 1260, maxAgeSecs: 900 })
    );
    expect(await runWatchdogCheck(deps(fetch))).toMatchObject({
      healthy: false,
      reason: 'Sběr dat stojí - poslední běh před 21 min (limit 15 min)',
      ageSecs: 1260,
    });
  });

  it('cron, který nikdy neběžel, se odliší od zastaveného', async () => {
    const fetch = fakeFetch(Response.json({ stale: true, lastRunAt: null, ageSecs: null, maxAgeSecs: 900 }));
    expect((await runWatchdogCheck(deps(fetch))).reason).toBe('Sběr dat stojí - cron zatím neproběhl ani jednou');
  });

  it('HTTP chyba a nečitelná odpověď nejsou zdravé a nic si nedomýšlejí', async () => {
    const http = await runWatchdogCheck(deps(fakeFetch(new Response('oops', { status: 500 }))));
    expect(http).toMatchObject({
      healthy: false,
      reason: 'Status server vrátil HTTP 500',
      lastRunAt: null,
      ageSecs: null,
    });
    const html = await runWatchdogCheck(deps(fakeFetch(new Response('<html>', { status: 200 }))));
    expect(html).toMatchObject({ healthy: false, reason: 'Odpověď status serveru nejde přečíst jako JSON' });
  });

  it('nedosažitelný server nemá odezvu, jen důvod', async () => {
    const result = await runWatchdogCheck(deps(fakeFetch(new Error('connect ECONNREFUSED'))));
    expect(result).toMatchObject({
      healthy: false,
      reason: 'Status server neodpovídá: connect ECONNREFUSED',
      latencyMs: null,
    });
  });
});

describe('rozhodnutí o alertu', () => {
  it('výpadek jednou, obnova jen po ohlášeném výpadku', () => {
    expect(watchdogAlertToSend(false, false)).toBe('outage');
    expect(watchdogAlertToSend(false, true)).toBeNull();
    expect(watchdogAlertToSend(true, true)).toBe('recovery');
    expect(watchdogAlertToSend(true, false)).toBeNull();
  });

  it('paměť změní jen zpráva, která do Discordu došla (R184)', () => {
    expect(watchdogMemoryAfter('outage', true)).toBe(true);
    expect(watchdogMemoryAfter('recovery', true)).toBe(false);
    expect(watchdogMemoryAfter('outage', false)).toBeNull();
    expect(watchdogMemoryAfter('recovery', false)).toBeNull();
  });
});

describe('běh hlídače', () => {
  it('ohlášený výpadek se nezopakuje, obnova se ohlásí jednou', async () => {
    let status = Response.json({ ...STATUS_OK, stale: true, ageSecs: 1800 });
    const fetch = fakeFetch(() => status.clone());
    const d = deps(fetch);
    expect((await watchdogTick(d)).alertSent).toBe(true);
    expect((await watchdogTick(d)).alertSent).toBe(false);
    status = Response.json(STATUS_OK);
    expect((await watchdogTick(d)).alertSent).toBe(true);
    expect((await watchdogTick(d)).alertSent).toBe(false);
    const posts = discordPosts(fetch);
    expect(posts).toHaveLength(2);
    expect(posts[0]).toContain('**Monitoring nesbírá data**\nSběr dat stojí - poslední běh před 30 min (limit 15 min)');
    expect(posts[1]).toBe('🟢 **Sběr dat zase běží** - cron se ozval v očekávaném intervalu.');
  });

  it('výpadek, který Discord nepřevzal, se další běh pošle znovu (R184)', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    let discord = new Response('rate limited', { status: 429 });
    const fetch = fakeFetch(Response.json({ ...STATUS_OK, stale: true }), () => discord.clone());
    const d = deps(fetch);
    expect((await watchdogTick(d)).alertSent).toBe(false);
    discord = new Response(null, { status: 204 });
    expect((await watchdogTick(d)).alertSent).toBe(true);
    expect((await watchdogTick(d)).alertSent).toBe(false);
    expect(discordPosts(fetch)).toHaveLength(2);
  });

  it('nedoručená obnova se zkusí znovu, ohlášený výpadek zatím trvá', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const cache = new FakeCache();
    await watchdogTick(deps(fakeFetch(Response.json({ ...STATUS_OK, stale: true })), { cache }));
    let discord: Response | Error = new Error('network down');
    const fetch = fakeFetch(Response.json(STATUS_OK), () => {
      if (discord instanceof Error) throw discord;
      return discord.clone();
    });
    expect((await watchdogTick(deps(fetch, { cache }))).alertSent).toBe(false);
    discord = new Response(null, { status: 204 });
    expect((await watchdogTick(deps(fetch, { cache }))).alertSent).toBe(true);
    expect(cache.entries.size).toBe(0);
  });

  it('bez webhooku nic neodejde, zaloguje se to a výpadek se nepovažuje za ohlášený', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {});
    const cache = new FakeCache();
    const fetch = fakeFetch(Response.json({ ...STATUS_OK, stale: true }));
    const result = await watchdogTick(deps(fetch, { webhook: null, cache }));
    expect(result.alertSent).toBe(false);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(cache.entries.size).toBe(0);
    expect(error).toHaveBeenCalledWith(
      '[watchdog] Není nastaven WATCHDOG_DISCORD_WEBHOOK, alert nemá kam odejít:',
      expect.stringContaining('Monitoring nesbírá data')
    );
  });

  it('zdravý sběr bez ohlášeného výpadku nic neposílá', async () => {
    const fetch = fakeFetch(Response.json(STATUS_OK));
    expect((await watchdogTick(deps(fetch))).alertSent).toBe(false);
    expect(discordPosts(fetch)).toEqual([]);
  });

  it('bez Cache API hlídač nic nepamatuje a výpadek hlásí každý běh', async () => {
    const fetch = fakeFetch(Response.json({ ...STATUS_OK, stale: true }));
    const d = deps(fetch, { cache: null });
    await watchdogTick(d);
    await watchdogTick(d);
    expect(discordPosts(fetch)).toHaveLength(2);
  });
});

describe('odeslání a ověření webhooku', () => {
  it('alert jde jako JSON POST s textem v content', async () => {
    const fetch = fakeFetch(Response.json(STATUS_OK));
    expect(await sendWatchdogAlert(deps(fetch), 'zkouška')).toEqual({ ok: true, detail: null });
    expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', headers: { 'Content-Type': 'application/json' } });
    expect(discordPosts(fetch)).toEqual(['zkouška']);
  });

  it('odmítnutí Discordem nese kód v důvodu', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const fetch = fakeFetch(Response.json(STATUS_OK), new Response('nope', { status: 404 }));
    expect(await sendWatchdogAlert(deps(fetch), 'x')).toEqual({
      ok: false,
      detail: 'Discord odmítl webhook: HTTP 404',
    });
  });

  it('platný webhook se ověří jednou za hodinu, ne při každém dotazu', async () => {
    const fetch = fakeFetch(Response.json(STATUS_OK), Response.json({ id: '1' }));
    const d = deps(fetch);
    expect(await probeWebhook(d)).toEqual({ valid: true, detail: null });
    expect(await probeWebhook(d)).toEqual({ valid: true, detail: null });
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'GET' });
  });

  it('smazaný webhook je neplatný, výpadek ověření je neznámo, ne neplatný', async () => {
    const gone = await probeWebhook(deps(fakeFetch(Response.json(STATUS_OK), new Response(null, { status: 404 }))));
    expect(gone.valid).toBe(false);
    expect(gone.detail).toContain('HTTP 404');
    const busy = await probeWebhook(deps(fakeFetch(Response.json(STATUS_OK), new Response(null, { status: 503 }))));
    expect(busy).toEqual({ valid: null, detail: 'Ověření nevyšlo (HTTP 503), platnost webhooku neznámá' });
    const down = await probeWebhook(deps(fakeFetch(Response.json(STATUS_OK), new Error('timeout'))));
    expect(down).toEqual({ valid: null, detail: 'Ověření nevyšlo: timeout' });
  });

  it('bez webhooku se nic neověřuje', async () => {
    const fetch = fakeFetch(Response.json(STATUS_OK));
    expect(await probeWebhook(deps(fetch, { webhook: null }))).toEqual({ valid: null, detail: null });
    expect(fetch).not.toHaveBeenCalled();
  });
});

describe('porovnání tokenu', () => {
  it('shodný projde, jiný ani jinak dlouhý ne', () => {
    expect(timingSafeEqual('tajne-heslo', 'tajne-heslo')).toBe(true);
    expect(timingSafeEqual('tajne-heslo', 'tajne-hesla')).toBe(false);
    expect(timingSafeEqual('tajne', 'tajne-heslo')).toBe(false);
    expect(timingSafeEqual('', 'x')).toBe(false);
  });
});
