/**
 * The collection watchdog (dead man's switch).
 *
 * All of monitoring runs from one cron on cPanel. When that cron dies, the app
 * does not fall over: it keeps showing the last known states and looks
 * healthy. Nobody hears about it, because the one that should report problems
 * is the one that is dead.
 *
 * So the watchdog runs here: another machine, another network, another
 * provider. It asks from outside whether collection still runs, and when it
 * does not, it speaks up itself. It is also a second place of measurement: it
 * measures the site from somewhere other than its own hosting, so "the
 * service is dead" can be told from "our server cannot see it".
 *
 * index.ts wires it to the routes and the cron trigger. Everything here takes
 * fetch and the cache as arguments, so the one alert path left when cron dies
 * has tests (watchdog.test.ts).
 */

/** The bindings the watchdog reads (see Bindings in index.ts). */
export interface WatchdogEnv {
  /** The Discord webhook the watchdog reports to; set with `wrangler secret put`. */
  WATCHDOG_DISCORD_WEBHOOK?: string;
  /** Overrides the address checked (another environment, a test instance). */
  WATCHDOG_TARGET_URL?: string;
}

/** The part of the Cache API the watchdog uses; caches.default in the Worker. */
export interface WatchdogCache {
  match(key: string): Promise<Response | undefined>;
  put(key: string, response: Response): Promise<void>;
  delete(key: string): Promise<boolean>;
}

export interface WatchdogDeps {
  env: WatchdogEnv;
  fetch: (input: string, init?: RequestInit) => Promise<Response>;
  /** null where the runtime has no Cache API (local development): nothing is kept between runs. */
  cache: WatchdogCache | null;
}

export const WATCHDOG_DEFAULT_TARGET = 'https://bloodkings.eu/status/api.php?action=collection_health';

/** How long "already reported" is kept, so the alert does not go out every 5 minutes. */
const WATCHDOG_ALERT_TTL_SECONDS = 3600;

export interface WatchdogResult {
  healthy: boolean;
  /** Why it is wrong; null when it is fine. */
  reason: string | null;
  /** The server's response time measured from here - the second place of measurement. */
  latencyMs: number | null;
  lastRunAt: string | null;
  ageSecs: number | null;
  checkedAt: string;
}

export async function runWatchdogCheck(deps: WatchdogDeps): Promise<WatchdogResult> {
  const target = deps.env.WATCHDOG_TARGET_URL ?? WATCHDOG_DEFAULT_TARGET;
  const startedAt = Date.now();
  const checkedAt = new Date().toISOString();

  let response: Response;
  try {
    response = await deps.fetch(target, {
      headers: { 'User-Agent': 'BloodKings-Monitoring-Watchdog' },
      signal: AbortSignal.timeout(10_000),
    });
  } catch (err: any) {
    // An unreachable server is the worst case: the page does not work
    // either, not just the collection.
    return {
      healthy: false,
      reason: `Status server neodpovídá: ${err?.message ?? 'neznámá chyba'}`,
      latencyMs: null,
      lastRunAt: null,
      ageSecs: null,
      checkedAt,
    };
  }

  const latencyMs = Date.now() - startedAt;

  if (!response.ok) {
    return {
      healthy: false,
      reason: `Status server vrátil HTTP ${response.status}`,
      latencyMs,
      lastRunAt: null,
      ageSecs: null,
      checkedAt,
    };
  }

  let payload: any;
  try {
    payload = await response.json();
  } catch {
    return {
      healthy: false,
      reason: 'Odpověď status serveru nejde přečíst jako JSON',
      latencyMs,
      lastRunAt: null,
      ageSecs: null,
      checkedAt,
    };
  }

  const lastRunAt = typeof payload?.lastRunAt === 'string' ? payload.lastRunAt : null;
  const ageSecs = typeof payload?.ageSecs === 'number' ? payload.ageSecs : null;

  if (payload?.stale === true) {
    // "Never ran" and "stopped running" are told apart: the first is a cron
    // set up wrong, the second a server that went down. The advice differs.
    const detail =
      ageSecs === null
        ? 'cron zatím neproběhl ani jednou'
        : `poslední běh před ${Math.round(ageSecs / 60)} min (limit ${Math.round((payload?.maxAgeSecs ?? 0) / 60)} min)`;
    return {
      healthy: false,
      reason: `Sběr dat stojí - ${detail}`,
      latencyMs,
      lastRunAt,
      ageSecs,
      checkedAt,
    };
  }

  return { healthy: true, reason: null, latencyMs, lastRunAt, ageSecs, checkedAt };
}

/** What one tick sends. */
export type WatchdogAlert = 'outage' | 'recovery';

/**
 * The alert decision: an outage once, and the recovery once after an outage
 * that was announced. null = nothing to send.
 */
export function watchdogAlertToSend(healthy: boolean, alreadyAlerted: boolean): WatchdogAlert | null {
  if (!healthy && !alreadyAlerted) return 'outage';
  if (healthy && alreadyAlerted) return 'recovery';
  return null;
}

/**
 * What the memory holds after a send: true = an outage was announced,
 * false = it is over, null = leave it as it is. Only a message that reached
 * Discord changes it. The memory used to say "announced" after a failed
 * send too, so the outage was not told for an hour (R184); now the next
 * tick, 5 minutes later, tries again, the recovery as well.
 */
export function watchdogMemoryAfter(alert: WatchdogAlert, delivered: boolean): boolean | null {
  if (!delivered) return null;
  return alert === 'outage';
}

/**
 * Memory between runs, through the Cache API.
 *
 * The Worker keeps no state, and KV would mean one more binding someone has
 * to create for a single flag. The cache is per data centre, so when a run
 * moves elsewhere the alert can repeat. For a watchdog that is the right side
 * to err on: worse than one alert too many is an alert that never arrives.
 */
const WATCHDOG_STATE_KEY = 'https://cache.local/watchdog-alerted';

async function wasAlreadyAlerted(cache: WatchdogCache | null): Promise<boolean> {
  try {
    return cache !== null && (await cache.match(WATCHDOG_STATE_KEY)) !== undefined;
  } catch {
    return false;
  }
}

async function rememberAlerted(cache: WatchdogCache | null, alerted: boolean): Promise<void> {
  if (cache === null) return;
  try {
    if (alerted) {
      await cache.put(
        WATCHDOG_STATE_KEY,
        new Response('1', { headers: { 'Cache-Control': `max-age=${WATCHDOG_ALERT_TTL_SECONDS}` } })
      );
    } else {
      await cache.delete(WATCHDOG_STATE_KEY);
    }
  } catch {
    // Without the memory the watchdog still works; it may only repeat itself.
  }
}

/** The result of a send - `detail` carries why it failed, not just "it did not work". */
export interface AlertSendResult {
  ok: boolean;
  detail: string | null;
}

export async function sendWatchdogAlert(deps: WatchdogDeps, text: string): Promise<AlertSendResult> {
  const webhook = deps.env.WATCHDOG_DISCORD_WEBHOOK;
  if (!webhook) {
    // Without a channel the watchdog stays silent, so it says so in the log
    // and on /api/watchdog. A silent watchdog is worse than none: it looks
    // as if it were watching.
    console.error('[watchdog] Není nastaven WATCHDOG_DISCORD_WEBHOOK, alert nemá kam odejít:', text);
    return { ok: false, detail: 'WATCHDOG_DISCORD_WEBHOOK není nastaven' };
  }

  try {
    const res = await deps.fetch(webhook, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ content: text }),
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) {
      console.error(`[watchdog] Discord odmítl webhook: HTTP ${res.status}`);
      return { ok: false, detail: `Discord odmítl webhook: HTTP ${res.status}` };
    }
    return { ok: true, detail: null };
  } catch (err: any) {
    const message = err?.message ?? String(err);
    console.error('[watchdog] Odeslání alertu selhalo:', message);
    return { ok: false, detail: `Odeslání selhalo: ${message}` };
  }
}

/**
 * Checks that the webhook still exists - without sending a message.
 *
 * `alertChannelConfigured` says only that the secret is not empty. A deleted
 * channel, a regenerated token or a typo in the URL look exactly the same, so
 * the watchdog could report "channel set" for months with nowhere to send an
 * alert. Discord answers a GET of the same address with the webhook object,
 * so it can be checked without a single message in the channel.
 *
 * `valid` is null when it cannot be told (a service other than Discord, a
 * network failure) - another answer than "invalid", never merged into it.
 */
const WATCHDOG_PROBE_KEY = 'https://cache.local/watchdog-webhook-probe';
const WATCHDOG_PROBE_TTL_SECONDS = 3600;

export async function probeWebhook(deps: WatchdogDeps): Promise<{ valid: boolean | null; detail: string | null }> {
  const webhook = deps.env.WATCHDOG_DISCORD_WEBHOOK;
  if (!webhook) return { valid: null, detail: null };

  // The answer is kept for an hour: without that, hammering /api/watchdog
  // would make the worker hammer Discord.
  try {
    const cached = deps.cache === null ? undefined : await deps.cache.match(WATCHDOG_PROBE_KEY);
    if (cached) return await cached.json();
  } catch {
    // The cache saves work; nothing depends on it.
  }

  let result: { valid: boolean | null; detail: string | null };
  try {
    const res = await deps.fetch(webhook, { method: 'GET', signal: AbortSignal.timeout(10_000) });
    if (res.ok) {
      result = { valid: true, detail: null };
    } else if (res.status === 401 || res.status === 403 || res.status === 404) {
      // What Discord answers for a deleted or overwritten webhook: a firm
      // "no alert arrives here", not a passing outage.
      result = {
        valid: false,
        detail: `Discord webhook neplatí (HTTP ${res.status}) - byl nejspíš smazán nebo přegenerován`,
      };
    } else {
      result = { valid: null, detail: `Ověření nevyšlo (HTTP ${res.status}), platnost webhooku neznámá` };
    }
  } catch (err: any) {
    result = { valid: null, detail: `Ověření nevyšlo: ${err?.message ?? err}` };
  }

  try {
    await deps.cache?.put(
      WATCHDOG_PROBE_KEY,
      new Response(JSON.stringify(result), {
        headers: { 'Content-Type': 'application/json', 'Cache-Control': `max-age=${WATCHDOG_PROBE_TTL_SECONDS}` },
      })
    );
  } catch {
    // Without the cache it is only checked more often.
  }
  return result;
}

/** One run of the cron trigger: check, decide, send, remember what went out. */
export async function watchdogTick(deps: WatchdogDeps): Promise<WatchdogResult & { alertSent: boolean }> {
  const result = await runWatchdogCheck(deps);
  const alert = watchdogAlertToSend(result.healthy, await wasAlreadyAlerted(deps.cache));
  if (alert === null) {
    return { ...result, alertSent: false };
  }

  const sent = await sendWatchdogAlert(
    deps,
    alert === 'outage'
      ? `🔴 **Monitoring nesbírá data**\n${result.reason}\n\nAplikace přitom dál zobrazuje poslední známé stavy, takže na první pohled vypadá v pořádku. Zkontrolujte cron úlohu na cPanelu.`
      : '🟢 **Sběr dat zase běží** - cron se ozval v očekávaném intervalu.'
  );
  const memory = watchdogMemoryAfter(alert, sent.ok);
  if (memory !== null) {
    await rememberAlerted(deps.cache, memory);
  }
  return { ...result, alertSent: sent.ok };
}

/** The message POST /api/watchdog/test sends along the path a real alert takes. */
export const WATCHDOG_TEST_TEXT =
  '🧪 **Zkouška hlídače** - tahle zpráva prošla stejnou cestou jako skutečný alert. Když ji vidíte, výpadek sběru dat se sem dostane taky.';

/**
 * Comparison in constant time.
 *
 * A plain `===` stops at the first character that differs, so the time of
 * the answer tells how many characters of the token match, and the token can
 * be guessed one character at a time.
 */
export function timingSafeEqual(a: string, b: string): boolean {
  const bytesA = new TextEncoder().encode(a);
  const bytesB = new TextEncoder().encode(b);
  // The lengths are compared on their own; a different length shows anyway,
  // but the comparison itself must not end early.
  let diff = bytesA.length ^ bytesB.length;
  for (let i = 0; i < Math.max(bytesA.length, bytesB.length); i++) {
    diff |= (bytesA[i] ?? 0) ^ (bytesB[i] ?? 0);
  }
  return diff === 0;
}
