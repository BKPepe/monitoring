import { Hono } from 'hono';
import { cors } from 'hono/cors';

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
    throw new Error(`GitHub API error: ${res.status} ${res.statusText}`);
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

// 2. GET /api/versions - Versions of Monitoring and Agents
app.get('/api/versions', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    const versions = await withCache('github-versions', 1800, async () => {
      let latestTag: string | null = null;
      let publishedAt: string | null = null;

      try {
        const latestRelease = await fetchGitHub('/repos/BKPepe/monitoring/releases/latest', token);
        latestTag = latestRelease.tag_name ?? null;
        publishedAt = latestRelease.published_at
          ? new Date(latestRelease.published_at).toISOString().split('T')[0]
          : null;
      } catch {
        // Fallback to tags if no official release yet
        try {
          const tags = await fetchGitHub('/repos/BKPepe/monitoring/tags', token);
          if (tags && tags.length > 0) {
            latestTag = tags[0].name;
          }
        } catch (tagErr) {
          console.error('Error fetching tags:', tagErr);
        }
      }

      // Without a real tag there is nothing truthful to serve - fail the
      // request instead of inventing a version string and today's date.
      if (!latestTag) {
        throw new Error('No release or tag found on GitHub');
      }

      // We clean the tag version prefix "v" for comparisons
      const cleanVersion = latestTag.replace(/^v/, '');

      return {
        monitoring: latestTag,
        agents: {
          windows: cleanVersion,
          linux: cleanVersion,
          docker: cleanVersion,
          macos: cleanVersion,
          raspberrypi: cleanVersion,
        },
        latestReleaseDate: publishedAt,
      };
    });

    return c.json(versions);
  } catch (err: any) {
    // No invented version numbers: the download page keeps its build-time
    // values and the caller sees an honest failure.
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
    // The site has its own static fallback card for this case; serving a
    // fabricated release list from here would just hide the outage.
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
    statusText: statusText || (success ? 'OK' : 'Failed'),
    latencyMs,
    error: errorMessage,
    timestamp: new Date().toISOString(),
  });
});

// 6. GET /api/agents - Current agent versions (for download page)
app.get('/api/agents', async (c) => {
  const token = c.env?.GITHUB_TOKEN;

  try {
    const agents = await withCache('github-agents', 3600, async () => {
      // Fetch agent files from GitHub to extract version numbers
      const files: Record<string, string> = {
        bash: 'apps/status/agent.sh',
        python: 'apps/status/agent.py',
        powershell: 'apps/status/agent.ps1',
        openwrt: 'apps/status/agent_openwrt.sh',
      };

      const versions: Record<string, string> = {};

      for (const [key, path] of Object.entries(files)) {
        try {
          const res = await fetch(`https://raw.githubusercontent.com/BKPepe/monitoring/main/${path}`, {
            headers: token ? { Authorization: `token ${token}` } : {},
          });
          if (res.ok) {
            const content = await res.text();
            const match = content.match(/\$?AGENT_VERSION\s*=\s*["']([0-9][0-9A-Za-z.-]*)["']/);
            versions[key] = match ? match[1] : 'unknown';
          } else {
            versions[key] = 'unknown';
          }
        } catch {
          versions[key] = 'unknown';
        }
      }

      return {
        ...versions,
        updatedAt: new Date().toISOString(),
      };
    });

    return c.json(agents);
  } catch (err: any) {
    return c.json({
      bash: '1.7.0',
      python: '1.7.0',
      powershell: '1.7.0',
      openwrt: '1.3.0',
      updatedAt: new Date().toISOString(),
      error: err.message,
    });
  }
});

// ---------------------------------------------------------------------------
// Hlídač sběru dat (dead man's switch)
// ---------------------------------------------------------------------------
//
// Celý monitoring běží z jednoho cronu na cPanelu. Když ten cron umře,
// aplikace nespadne - bude dál ukazovat poslední známé stavy a vypadat
// zdravě. Nikdo se nic nedozví, protože ten, kdo měl hlásit problémy,
// je právě ten, kdo je mrtvý.
//
// Proto hlídač běží tady: jiný stroj, jiná síť, jiný poskytovatel. Ptá se
// zvenku, jestli sběr dat ještě běží, a když ne, ozve se sám. Zároveň je to
// druhé místo měření - měří dostupnost webu odjinud než z jeho vlastního
// hostingu, takže jde rozlišit "služba je mrtvá" od "náš server na ni nevidí".

const WATCHDOG_DEFAULT_TARGET = 'https://bloodkings.eu/status/api.php?action=collection_health';

/** Jak dlouho se drží informace "už jsem hlásil", aby alert nechodil každých 5 minut. */
const WATCHDOG_ALERT_TTL_SECONDS = 3600;

interface WatchdogResult {
  healthy: boolean;
  /** Proč je zle; null, když je dobře. */
  reason: string | null;
  /** Doba odpovědi serveru měřená odsud - druhé místo měření. */
  latencyMs: number | null;
  lastRunAt: string | null;
  ageSecs: number | null;
  checkedAt: string;
}

async function runWatchdogCheck(env: Bindings): Promise<WatchdogResult> {
  const target = env.WATCHDOG_TARGET_URL ?? WATCHDOG_DEFAULT_TARGET;
  const startedAt = Date.now();
  const checkedAt = new Date().toISOString();

  let response: Response;
  try {
    response = await fetch(target, {
      headers: { 'User-Agent': 'BloodKings-Monitoring-Watchdog' },
      signal: AbortSignal.timeout(10_000),
    });
  } catch (err: any) {
    // Nedosažitelný server je ta nejzávažnější varianta: nefunguje ani
    // stránka, ne jenom sběr dat.
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
    // Rozlišujeme "nikdy neběžel" od "přestal běžet" - první znamená špatně
    // nastavený cron, druhý spadlý server. Rada je pokaždé jiná.
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

/**
 * Paměť mezi běhy přes Cache API.
 *
 * Worker je bezstavový a KV by kvůli jednomu příznaku znamenalo další binding,
 * který musí někdo založit. Cache je per-datacentrum, takže při přesunu běhu
 * jinam se může alert zopakovat. U hlídače je to ta správná strana chyby:
 * horší než alert navíc je alert, který nedorazí.
 */
const WATCHDOG_STATE_KEY = 'https://cache.local/watchdog-alerted';

async function wasAlreadyAlerted(): Promise<boolean> {
  try {
    return (await caches.default.match(WATCHDOG_STATE_KEY)) !== undefined;
  } catch {
    return false;
  }
}

async function rememberAlerted(alerted: boolean): Promise<void> {
  try {
    if (alerted) {
      await caches.default.put(
        WATCHDOG_STATE_KEY,
        new Response('1', { headers: { 'Cache-Control': `max-age=${WATCHDOG_ALERT_TTL_SECONDS}` } })
      );
    } else {
      await caches.default.delete(WATCHDOG_STATE_KEY);
    }
  } catch {
    // Bez paměti hlídač pořád funguje, jen se může zopakovat.
  }
}

/** Výsledek odeslání - `detail` nese důvod selhání, ne jen "nepovedlo se". */
interface AlertSendResult {
  ok: boolean;
  detail: string | null;
}

async function sendWatchdogAlert(env: Bindings, text: string): Promise<AlertSendResult> {
  const webhook = env.WATCHDOG_DISCORD_WEBHOOK;
  if (!webhook) {
    // Bez nastaveného kanálu hlídač mlčí - proto to hlásí do logu a přiznává
    // to i na /api/watchdog. Tichý hlídač je horší než žádný: vypadá, že hlídá.
    console.error('[watchdog] Není nastaven WATCHDOG_DISCORD_WEBHOOK, alert nemá kam odejít:', text);
    return { ok: false, detail: 'WATCHDOG_DISCORD_WEBHOOK není nastaven' };
  }

  try {
    const res = await fetch(webhook, {
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
 * Ověří, že webhook pořád existuje - bez odeslání zprávy.
 *
 * `alertChannelConfigured` říká jen tolik, že secret je neprázdný. Smazaný
 * kanál, přegenerovaný token nebo překlep v URL vypadají úplně stejně, takže
 * hlídač může měsíce hlásit "kanál nastaven" a přitom nemít kam alert poslat.
 * Discord na GET stejné adresy vrátí objekt webhooku, takže platnost jde
 * ověřit bez jediné zprávy do kanálu.
 *
 * Výsledek je `null`, když se to nedá zjistit (jiná služba než Discord,
 * výpadek sítě) - to je jiná informace než "neplatný" a nesmí se slít v jedno.
 */
const WATCHDOG_PROBE_KEY = 'https://cache.local/watchdog-webhook-probe';
const WATCHDOG_PROBE_TTL_SECONDS = 3600;

async function probeWebhook(env: Bindings): Promise<{ valid: boolean | null; detail: string | null }> {
  const webhook = env.WATCHDOG_DISCORD_WEBHOOK;
  if (!webhook) return { valid: null, detail: null };

  // Cache drží výsledek hodinu: bez ní by stačilo tlouct na /api/watchdog
  // a worker by tím tloukl na Discord.
  try {
    const cached = await caches.default.match(WATCHDOG_PROBE_KEY);
    if (cached) return await cached.json();
  } catch {
    // Cache je optimalizace, ne podmínka.
  }

  let result: { valid: boolean | null; detail: string | null };
  try {
    const res = await fetch(webhook, { method: 'GET', signal: AbortSignal.timeout(10_000) });
    if (res.ok) {
      result = { valid: true, detail: null };
    } else if (res.status === 401 || res.status === 403 || res.status === 404) {
      // Tyhle tři Discord vrací na smazaný nebo přepsaný webhook - to je
      // tvrdá odpověď "sem alert nedorazí", ne dočasný výpadek.
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
    await caches.default.put(
      WATCHDOG_PROBE_KEY,
      new Response(JSON.stringify(result), {
        headers: { 'Content-Type': 'application/json', 'Cache-Control': `max-age=${WATCHDOG_PROBE_TTL_SECONDS}` },
      })
    );
  } catch {
    // Bez cache se jen ověřuje častěji.
  }
  return result;
}

async function watchdogTick(env: Bindings): Promise<WatchdogResult & { alertSent: boolean }> {
  const result = await runWatchdogCheck(env);
  const alreadyAlerted = await wasAlreadyAlerted();
  let alertSent = false;

  if (!result.healthy && !alreadyAlerted) {
    alertSent = (
      await sendWatchdogAlert(
        env,
        `🔴 **Monitoring nesbírá data**\n${result.reason}\n\nAplikace přitom dál zobrazuje poslední známé stavy, takže na první pohled vypadá v pořádku. Zkontrolujte cron úlohu na cPanelu.`
      )
    ).ok;
    await rememberAlerted(true);
  } else if (result.healthy && alreadyAlerted) {
    alertSent = (await sendWatchdogAlert(env, '🟢 **Sběr dat zase běží** - cron se ozval v očekávaném intervalu.')).ok;
    await rememberAlerted(false);
  }

  return { ...result, alertSent };
}

// 7. GET /api/watchdog - stav hlídače na vyžádání.
// Existuje proto, aby šlo ověřit, že hlídač funguje, bez čekání na výpadek.
app.get('/api/watchdog', async (c) => {
  const [result, probe] = await Promise.all([runWatchdogCheck(c.env), probeWebhook(c.env)]);
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

  const sent = await sendWatchdogAlert(
    c.env,
    '🧪 **Zkouška hlídače** - tahle zpráva prošla stejnou cestou jako skutečný alert. Když ji vidíte, výpadek sběru dat se sem dostane taky.'
  );

  return c.json(
    {
      delivered: sent.ok,
      detail: sent.detail,
      checkedAt: new Date().toISOString(),
    },
    sent.ok ? 200 : 502
  );
});

/**
 * Porovnání v konstantním čase.
 *
 * Naivní `===` skončí na prvním rozdílném znaku, takže délka odpovědi
 * prozrazuje, kolik znaků tokenu sedí, a token jde uhodnout znak po znaku.
 */
function timingSafeEqual(a: string, b: string): boolean {
  const bytesA = new TextEncoder().encode(a);
  const bytesB = new TextEncoder().encode(b);
  // Délky se porovnávají zvlášť; rozdílná délka je stejně vidět, ale samotné
  // porovnání pak nesmí skončit dřív.
  let diff = bytesA.length ^ bytesB.length;
  for (let i = 0; i < Math.max(bytesA.length, bytesB.length); i++) {
    diff |= (bytesA[i] ?? 0) ^ (bytesB[i] ?? 0);
  }
  return diff === 0;
}

export default {
  fetch: app.fetch,
  /** Cron trigger z wrangler.jsonc - jediné místo, které hlídače pravidelně spouští. */
  scheduled: async (_event: ScheduledController, env: Bindings, ctx: ExecutionContext) => {
    ctx.waitUntil(
      watchdogTick(env).then((result) => {
        console.log(
          `[watchdog] healthy=${result.healthy} latencyMs=${result.latencyMs ?? '-'} alertSent=${result.alertSent}` +
            (result.reason ? ` reason=${result.reason}` : '')
        );
      })
    );
  },
};
