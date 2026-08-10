import { Hono } from 'hono';
import { cors } from 'hono/cors';

type Bindings = {
  GITHUB_TOKEN?: string;
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

export default app;
