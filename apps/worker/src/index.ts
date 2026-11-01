import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { AGENT_SOURCE_REPO, AgentVersionsIncomplete, NO_AGENT_VERSIONS, readAgentVersions } from './agent-versions';
import {
  WATCHDOG_TEST_TEXT,
  probeWebhook,
  runWatchdogCheck,
  sendWatchdogAlert,
  timingSafeEqual,
  watchdogTick,
  type WatchdogDeps,
} from './watchdog';

type Bindings = {
  GITHUB_TOKEN?: string;
  /** Discord webhook, kam hlídač hlásí, že sběr dat stojí. Nastavuje se přes `wrangler secret put`. */
  WATCHDOG_DISCORD_WEBHOOK?: string;
  /** Přepíše sledovanou adresu (jiné prostředí, testovací instance). */
  WATCHDOG_TARGET_URL?: string;
  /**
   * Heslo pro `POST /api/watchdog/test`, které skutečně pošle zprávu do Discordu.
   * Bez něj endpoint neexistuje - jinak by kdokoli mohl zaplavit kanál.
   */
  WATCHDOG_TEST_TOKEN?: string;
};

const app = new Hono<{ Bindings: Bindings }>();

/**
 * CORS allowlist místo `cors()` bez parametrů.
 *
 * Původní konfigurace posílala `Access-Control-Allow-Origin: *`, takže
 * kdokoli mohl API vestavět do svého webu a čerpat naši GitHub kvótu i
 * kapacitu `/api/test`. Povolené jsou jen vlastní doména, náhledy z Pages
 * a lokální vývoj.
 */
const ALLOWED_ORIGIN_PATTERNS = [
  /^https:\/\/monitoring\.bloodkings\.eu$/,
  /^https:\/\/([a-z0-9-]+\.)?bloodkings\.eu$/,
  /^https:\/\/[a-z0-9-]+\.bloodkings-monitoring-web\.pages\.dev$/,
  /^https:\/\/bloodkings-monitoring-web\.pages\.dev$/,
  /^http:\/\/localhost:\d+$/,
  /^http:\/\/127\.0\.0\.1:\d+$/,
];

app.use(
  '*',
  cors({
    origin: (origin) => (ALLOWED_ORIGIN_PATTERNS.some((pattern) => pattern.test(origin)) ? origin : null),
    allowMethods: ['GET', 'OPTIONS'],
    maxAge: 86400,
  })
);

// Helper for caching GET responses with Cloudflare Cache API
async function withCache(cacheKey: string, ttlSeconds: number, fetcher: () => Promise<any>): Promise<any> {
  try {
    const cache = caches.default;
    const cacheUrl = new URL(`https://cache.local/${cacheKey}`);
    const cachedResponse = await cache.match(cacheUrl);

    if (cachedResponse) {
      return await cachedResponse.json();
    }

    const data = await fetcher();

    // Store in cache
    const response = new Response(JSON.stringify(data), {
      headers: {
        'Content-Type': 'application/json',
        'Cache-Control': `public, max-age=${ttlSeconds}`,
      },
    });
    await cache.put(cacheUrl, response.clone());
    return data;
  } catch {
    // If cache API is not available (e.g. in local development) or fails, fallback to direct fetcher
    return await fetcher();
  }
}

/** Like fetchGitHub, but a 404 is an answer (null: "there is none"), not an error. */
async function fetchGitHubOrNull(path: string, token?: string): Promise<any> {
  try {
    return await fetchGitHub(path, token);
  } catch (err) {
    if (err instanceof GitHubError && err.status === 404) return null;
    throw err;
  }
}

class GitHubError extends Error {
  constructor(
    readonly status: number,
    statusText: string
  ) {
    super(`GitHub API error: ${status} ${statusText}`);
  }
}

// GitHub API Fetcher wrapper with headers and authentication
async function fetchGitHub(path: string, token?: string): Promise<any> {
  const headers: HeadersInit = {
    'User-Agent': 'BloodKings-Monitoring-Website-Worker',
    Accept: 'application/vnd.github.v3+json',
  };
  if (token) {
    headers['Authorization'] = `token ${token}`;
  }

  const res = await fetch(`https://api.github.com${path}`, { headers });
  if (!res.ok) {
    throw new GitHubError(res.status, res.statusText);
  }
  return await res.json();
}

// 1. GET /api/stats - Aggregated stats from GitHub
app.get('/api/stats', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    const stats = await withCache('github-stats', 3600, async () => {
      const repo = await fetchGitHub('/repos/BKPepe/monitoring', token);

      // Let's try fetching contributors. GitHub contributors endpoint can be paginated,
      // but a simple length check on the first page of contributors is sufficient.
      // Unknown stays null - the site renders a dash, never an invented count.
      let contributorsCount: number | null = null;
      try {
        const contributors = await fetchGitHub('/repos/BKPepe/monitoring/contributors?per_page=100', token);
        if (Array.isArray(contributors)) {
          contributorsCount = contributors.length;
        }
      } catch (e) {
        console.error('Error fetching contributors:', e);
      }

      return {
        stars: repo.stargazers_count ?? null,
        forks: repo.forks_count ?? null,
        openIssues: repo.open_issues_count ?? null,
        contributors: contributorsCount,
        watchers: repo.subscribers_count ?? null,
      };
    });

    return c.json(stats);
  } catch (err: any) {
    // No fabricated fallback numbers: report the failure and let the site
    // show dashes. Invented star counts are marketing lies, not stats.
    return c.json({ error: err.message }, 503);
  }
});

// 2. GET /api/versions - Latest release of the monitoring server (agents: /api/agents)
//
// The site's live panel ("Latest server release") and the install guide's
// release line read this. "Latest" is GitHub's releases/latest, the same
// release that releases/latest/download/bloodkings-monitoring.zip serves, so
// the site never names a version the download button cannot fetch. A bare tag without a
// release used to count too; it has no ZIP, so it no longer does.
//   200 { monitoring: "v0.3.0-alpha", latestReleaseDate: "2026-09-24" }
//   200 { monitoring: null, latestReleaseDate: null }  - no release published yet
//   503 { error }                                        - GitHub did not answer
app.get('/api/versions', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    const versions = await withCache('github-versions-v2', 1800, async () => {
      const latest = await fetchGitHubOrNull('/repos/BKPepe/monitoring/releases/latest', token);
      if (!latest) return { monitoring: null, latestReleaseDate: null };
      // No agents map here: the agents are versioned on their own (0.1.x in
      // BKPepe/monitoring-agent), and /api/agents reads the real ones.
      return {
        monitoring: typeof latest.tag_name === 'string' ? latest.tag_name : null,
        latestReleaseDate: latest.published_at ? new Date(latest.published_at).toISOString().split('T')[0] : null,
      };
    });

    return c.json(versions);
  } catch (err: any) {
    // No invented version numbers: the caller shows a dash and says the
    // version could not be read.
    return c.json({ error: err.message }, 503);
  }
});

// 3. GET /api/changelog - Release logs
app.get('/api/changelog', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    const changelog = await withCache('github-changelog', 1800, async () => {
      const releases = await fetchGitHub('/repos/BKPepe/monitoring/releases', token);
      if (!Array.isArray(releases)) return [];

      return releases.map((release: any) => ({
        tag: release.tag_name,
        title: release.name || release.tag_name,
        date: new Date(release.published_at).toLocaleDateString('cs-CZ', {
          year: 'numeric',
          month: 'long',
          day: 'numeric',
        }),
        body: release.body,
        url: release.html_url,
        author: {
          name: release.author?.login || 'BKPepe',
          avatar: release.author?.avatar_url,
          url: release.author?.html_url,
        },
      }));
    });

    return c.json(changelog);
  } catch (err: any) {
    // The changelog page says the list could not be loaded and links GitHub;
    // serving a fabricated release list from here would just hide the outage.
    return c.json({ error: err.message }, 503);
  }
});

// 4. GET /api/status - Live aggregate statistics from the real bloodkings.eu status app.
// No mock fallback: if the real endpoint is unreachable, we say so explicitly rather
// than presenting fabricated numbers as if they were real.
app.get('/api/status', async (c) => {
  try {
    const data = await withCache('system-status', 300, async () => {
      const statusRes = await fetch('https://bloodkings.eu/status/api.php?action=public_status', {
        headers: { 'User-Agent': 'BloodKings-Monitoring-Website-Worker' },
      });
      if (!statusRes.ok) {
        throw new Error(`Upstream status API returned ${statusRes.status}`);
      }
      return await statusRes.json();
    });
    return c.json(data);
  } catch {
    return c.json({ available: false, error: 'Status data temporarily unavailable' }, 503);
  }
});

/**
 * Cíle, na které `/api/test` nesmí sáhnout.
 *
 * Bez téhle kontroly je endpoint otevřená proxy: útočník přes něj skenuje
 * interní sítě i cloud metadata (169.254.169.254) a v logách cíle je vidět
 * Cloudflare, ne on. Blokujeme loopback, privátní rozsahy, link-local
 * a interní TLD.
 */
const BLOCKED_HOSTNAME_PATTERNS: RegExp[] = [
  /^localhost$/i,
  /\.local$/i,
  /\.localhost$/i,
  /\.internal$/i,
  /\.home\.arpa$/i,
  /^127\./,
  /^0\./,
  /^10\./,
  /^169\.254\./, // link-local + cloud metadata
  /^192\.168\./,
  /^172\.(1[6-9]|2\d|3[01])\./,
  /^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./, // CGNAT
  /^\[?::1\]?$/,
  /^\[?f[cd][0-9a-f]{2}:/i, // unique local IPv6
  /^\[?fe80:/i, // link-local IPv6
];

function isBlockedTarget(url: URL): string | null {
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    return 'Only http and https URLs can be tested.';
  }
  const host = url.hostname.toLowerCase();
  if (BLOCKED_HOSTNAME_PATTERNS.some((pattern) => pattern.test(host))) {
    return 'Testing private, loopback or link-local addresses is not allowed.';
  }
  return null;
}

/**
 * Jednoduchý rate limit v paměti izolátu. Není to distribuovaná ochrana
 * (každý izolát má vlastní mapu), ale zastaví triviální smyčku z jedné IP.
 * Na tvrdší limit je potřeba Durable Object nebo Cloudflare Rate Limiting.
 */
const testRateLimit = new Map<string, { count: number; resetAt: number }>();
const TEST_LIMIT_PER_MINUTE = 12;

function exceedsTestRateLimit(ip: string): boolean {
  const now = Date.now();
  const entry = testRateLimit.get(ip);

  if (!entry || now > entry.resetAt) {
    testRateLimit.set(ip, { count: 1, resetAt: now + 60_000 });
    return false;
  }

  entry.count += 1;
  return entry.count > TEST_LIMIT_PER_MINUTE;
}

// 4b. GET /api/contributors - Contributor list for the About page.
// Existuje proto, aby stránka nevolala api.github.com přímo z prohlížeče
// návštěvníka (limit 60 req/h na IP + únik IP třetí straně).
app.get('/api/contributors', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    const contributors = await withCache('github-contributors', 3600, async () => {
      const list = await fetchGitHub('/repos/BKPepe/monitoring/contributors?per_page=100', token);
      if (!Array.isArray(list)) return [];

      // Vracíme jen to, co stránka opravdu vykresluje — žádné další metadata.
      return list.map((user: any) => ({
        login: user.login,
        contributions: user.contributions ?? 0,
        url: user.html_url,
      }));
    });

    return c.json(contributors);
  } catch (err: any) {
    // About page shows its own "couldn't load" state; a hardcoded one-person
    // list would misrepresent who actually contributed.
    return c.json({ error: err.message }, 503);
  }
});

// 5. GET /api/test - Real-time ping testing tool (Live Playground backend)
app.get('/api/test', async (c) => {
  const clientIp = c.req.header('CF-Connecting-IP') ?? 'unknown';
  if (exceedsTestRateLimit(clientIp)) {
    return c.json({ error: 'Too many test requests. Try again in a minute.' }, 429);
  }

  let urlParam = c.req.query('url');
  if (!urlParam) {
    return c.json({ error: 'Parameter "url" is required.' }, 400);
  }

  // Dlouhé vstupy nemají žádné legitimní využití a jen zvětšují útočnou plochu.
  if (urlParam.length > 2048) {
    return c.json({ error: 'URL is too long.' }, 400);
  }

  // Basic normalization
  if (!/^https?:\/\//i.test(urlParam)) {
    urlParam = `https://${urlParam}`;
  }

  let parsedUrl: URL;
  try {
    parsedUrl = new URL(urlParam);
  } catch {
    return c.json({ error: 'Invalid URL format.' }, 400);
  }

  const blockedReason = isBlockedTarget(parsedUrl);
  if (blockedReason) {
    return c.json({ error: blockedReason }, 400);
  }

  const startTime = performance.now();
  let status = 0;
  let statusText = '';
  // Where a 3xx points. The check does not follow redirects (see below), so
  // this is the one "final URL" fact the playground can show without guessing.
  let redirectTo: string | null = null;
  // Bez pocatecni hodnoty: prirazuje se v try i v catch, takze inicializace
  // na false byla mrtva (a lint na ni upozornil).
  let success: boolean;
  let errorMessage = '';

  try {
    // Perform a HEAD/GET request with timeout
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 6000);

    const response = await fetch(parsedUrl.toString(), {
      method: 'GET',
      headers: {
        'User-Agent': 'BloodKings-Monitoring-Playground/1.0',
        // Stačí ověřit dostupnost — celé tělo odpovědi tahat nemusíme.
        Range: 'bytes=0-0',
      },
      // Bez 'manual' by šlo kontrolu cíle obejít přesměrováním z veřejné
      // adresy na interní. Redirect proto vracíme jako výsledek, nenásledujeme ho.
      redirect: 'manual',
      signal: controller.signal,
    });

    clearTimeout(timeoutId);
    status = response.status;
    statusText = response.statusText;
    const location = response.status >= 300 && response.status < 400 ? response.headers.get('location') : null;
    if (location) {
      try {
        redirectTo = new URL(location, parsedUrl).toString();
      } catch {
        redirectTo = null;
      }
    }
    success = response.status >= 200 && response.status < 400;
  } catch (err: any) {
    success = false;
    errorMessage = err.name === 'AbortError' ? 'Connection timed out (6s).' : err.message;
  }

  const endTime = performance.now();
  const latencyMs = Math.round(endTime - startTime);

  // Only the total round-trip is actually measured. The old "breakdown"
  // (dns/tcp/tls/http) was fabricated as fixed percentages of the total -
  // Workers fetch cannot observe those stages, so we don't pretend it can.
  return c.json({
    url: parsedUrl.toString(),
    host: parsedUrl.hostname,
    success,
    status,
    // HTTP/2 answers carry no reason phrase. An empty one stays empty: a
    // made-up "Failed" next to a 404 read as an outage in the playground.
    statusText: statusText || (status ? '' : 'Failed'),
    latencyMs,
    redirectTo,
    error: errorMessage,
    timestamp: new Date().toISOString(),
  });
});

// 6. GET /api/agents - Current agent versions (for the download page).
// Read from the monitoring-agent repository, see agent-versions.ts.
app.get('/api/agents', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    // New cache key: the old one may still hold the "unknown" answers from
    // the dead apps/status paths for up to an hour after a deploy.
    const agents = await withCache('agent-versions', 3600, async () => {
      const { versions, missing } = await readAgentVersions((input, init) => fetch(input, init), token);
      // Throwing keeps an incomplete answer out of the cache: one failed
      // fetch must not blank a version for the next hour.
      if (missing.length > 0) {
        throw new AgentVersionsIncomplete(versions, missing);
      }
      return { ...versions, source: AGENT_SOURCE_REPO, updatedAt: new Date().toISOString() };
    });

    return c.json(agents);
  } catch (err: any) {
    // No invented fallback: a version nobody read is null, the site shows a
    // dash, and the status code says the answer is incomplete - as /api/stats does.
    const partial = err instanceof AgentVersionsIncomplete ? err.versions : NO_AGENT_VERSIONS;
    return c.json({ ...partial, source: AGENT_SOURCE_REPO, updatedAt: null, error: err.message }, 503);
  }
});

// ---------------------------------------------------------------------------
// The collection watchdog (dead man's switch): watchdog.ts
// ---------------------------------------------------------------------------

/** The watchdog's fetch and cache: the Worker's own (watchdog.ts takes them as arguments). */
function watchdogDeps(env: Bindings | undefined): WatchdogDeps {
  return {
    env: env ?? {},
    fetch: (input, init) => fetch(input, init),
    cache: typeof caches === 'undefined' ? null : caches.default,
  };
}

// 7. GET /api/watchdog - stav hlídače na vyžádání.
// Existuje proto, aby šlo ověřit, že hlídač funguje, bez čekání na výpadek.
app.get('/api/watchdog', async (c) => {
  const deps = watchdogDeps(c.env);
  const [result, probe] = await Promise.all([runWatchdogCheck(deps), probeWebhook(deps)]);
  return c.json(
    {
      ...result,
      // Přiznaná mezera: bez tohohle by se dalo věřit, že alerty chodí, i když
      // není kam.
      alertChannelConfigured: Boolean(c.env?.WATCHDOG_DISCORD_WEBHOOK),
      // Nastavený a funkční nejsou totéž. `null` = nedalo se ověřit.
      alertChannelValid: probe.valid,
      alertChannelDetail: probe.detail,
    },
    result.healthy ? 200 : 503
  );
});

/**
 * 7b. POST /api/watchdog/test - pošle zkušební zprávu do Discordu.
 *
 * Ověření přes GET pozná smazaný webhook, ale ne to, jestli zpráva opravdu
 * dorazí do kanálu (chybějící oprávnění, blokace obsahu). Tohle projde celou
 * cestu, kterou by šel skutečný alert.
 *
 * Bez nastaveného `WATCHDOG_TEST_TOKEN` endpoint odpovídá 404, jako by
 * neexistoval - jinak by kdokoli mohl kanál zaplavit.
 */
app.post('/api/watchdog/test', async (c) => {
  const expected = c.env?.WATCHDOG_TEST_TOKEN;
  const provided = (c.req.header('Authorization') ?? '').replace(/^Bearer\s+/i, '');

  if (!expected || !timingSafeEqual(provided, expected)) {
    return c.json({ error: 'Nenalezeno.' }, 404);
  }

  const sent = await sendWatchdogAlert(watchdogDeps(c.env), WATCHDOG_TEST_TEXT);

  return c.json(
    {
      delivered: sent.ok,
      detail: sent.detail,
      checkedAt: new Date().toISOString(),
    },
    sent.ok ? 200 : 502
  );
});

export default {
  fetch: app.fetch,
  /** Cron trigger z wrangler.jsonc - jediné místo, které hlídače pravidelně spouští. */
  scheduled: async (_event: ScheduledController, env: Bindings, ctx: ExecutionContext) => {
    ctx.waitUntil(
      watchdogTick(watchdogDeps(env)).then((result) => {
        console.log(
          `[watchdog] healthy=${result.healthy} latencyMs=${result.latencyMs ?? '-'} alertSent=${result.alertSent}` +
            (result.reason ? ` reason=${result.reason}` : '')
        );
      })
    );
  },
};
