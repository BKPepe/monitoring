// CLI behind .github/workflows/deploy-root.yml and web-health.yml. Each command
// does its job or exits non-zero with the reason: uploading a guessed file into
// the game portal's root would be worse than a failed run.
//
//   node root/deploy.mjs build <dir>                 files to upload, one by one
//   node root/deploy.mjs merge-htaccess <live> <out> prints inserted|updated|unchanged
//   node root/deploy.mjs statuses <file>             probe statuses before a change
//   node root/deploy.mjs same-statuses <file>        fails when a probe changed
//   node root/deploy.mjs check-root [<tag>]          live robots/sitemap/error pages
//   node root/deploy.mjs check-site                  monitoring.bloodkings.eu 404s
//   node root/deploy.mjs check-security              security.txt on both hosts
//   node root/deploy.mjs check-cloudflare            the four settings made in Cloudflare
/* global process, fetch, console, AbortSignal, URL */
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  errorPageProblems,
  mergeHtaccess,
  MARK_BEGIN,
  MARK_END,
  notProxiedProblems,
  publicStatusPageUrls,
  redirectProblems,
  ROOT_DIRS,
  robotsProblems,
  securityTxt,
  securityTxtProblems,
  serverHeaderProblems,
  sitemapLocs,
  sitemapXml,
} from './lib.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
// PUBLIC is what the files say (sitemap URLs, Canonical). ORIGIN is where the
// checks and the status_pages read go; BK_ROOT_ORIGIN points them at a local
// server, so the workflow's steps can be rehearsed without touching the portal.
const PUBLIC = 'https://bloodkings.eu';
const ORIGIN = process.env.BK_ROOT_ORIGIN || PUBLIC;
const SITE = 'https://monitoring.bloodkings.eu';
const WWW = 'https://www.bloodkings.eu';
// The Cloudflare Pages project behind SITE (deploy.yml --project-name).
const PAGES_DEV = 'https://bloodkings-monitoring-web.pages.dev';
const ROOT_SECURITY_TXT = `${PUBLIC}/.well-known/security.txt`;
const SITE_SECURITY_TXT = `${SITE}/.well-known/security.txt`;
const ERROR_CODES = ['403', '404', '410', '500', '503'];
// The root .htaccess sits above /app/ and /status/ too: a line the server
// rejects turns all three into 500, so all three are compared around a change.
const PROBES = ['/', '/app/public', '/status/'];

/** @param {string} url */
async function get(url) {
  const res = await fetch(url, {
    redirect: 'manual',
    headers: { 'User-Agent': 'BloodKings-RootDeploy' },
    signal: AbortSignal.timeout(20_000),
  });
  return {
    status: res.status,
    type: res.headers.get('content-type'),
    location: res.headers.get('location'),
    headers: Object.fromEntries(res.headers),
    body: await res.text(),
  };
}

/** Appends a cache-busting tag, so a check right after an upload reads the origin, not Cloudflare's copy. */
function bust(url, tag) {
  return tag ? `${url}${url.includes('?') ? '&' : '?'}bk-root-deploy=${encodeURIComponent(tag)}` : url;
}

/**
 * The portal's home page, the public status page and every public custom
 * status page, read as an anonymous visitor: exactly what a crawler may open.
 * A failed answer throws (publicStatusPageUrls too), so no sitemap is guessed.
 */
async function sitemapUrls() {
  const r = await get(`${ORIGIN}/status/api.php?action=status_pages`);
  if (r.status !== 200) throw new Error(`status_pages answered HTTP ${r.status}, sitemap not built`);
  return [`${PUBLIC}/`, `${PUBLIC}/app/public`, ...publicStatusPageUrls(JSON.parse(r.body))];
}

async function build(out) {
  for (const dir of ROOT_DIRS) mkdirSync(join(out, dir), { recursive: true });
  for (const code of ERROR_CODES) {
    copyFileSync(join(HERE, 'errors', `${code}.html`), join(out, 'errors', `${code}.html`));
  }
  copyFileSync(join(HERE, 'robots.txt'), join(out, 'robots.txt'));
  const withPolicy = existsSync(join(HERE, '..', 'SECURITY.md'));
  const sec = securityTxt({ canonical: ROOT_SECURITY_TXT, now: new Date(), withPolicy });
  writeFileSync(join(out, '.well-known', 'security.txt'), sec);

  const urls = await sitemapUrls();
  writeFileSync(join(out, 'sitemap.xml'), sitemapXml(urls));
  console.log(`built ${out}: errors/{${ERROR_CODES.join(',')}}.html robots.txt sitemap.xml (${urls.length} URLs)`);
  console.log(
    `security.txt ${withPolicy ? 'with' : 'without'} Policy (SECURITY.md ${withPolicy ? 'found' : 'missing'})`
  );
}

function mergeCmd(livePath, outPath) {
  const live = readFileSync(livePath, 'utf8');
  const { content, action } = mergeHtaccess(live, readFileSync(join(HERE, 'htaccess.block'), 'utf8'));
  // Our ErrorDocument lines come later and win. Say so, in case the portal
  // meant its own pages; nothing outside the block is ever edited.
  let inside = false;
  for (const line of live.split(/\r?\n/)) {
    if (line.trim() === MARK_BEGIN) inside = true;
    if (!inside && /^\s*ErrorDocument\s+(403|404|410|500|503)\b/i.test(line)) {
      console.error(`::warning::the portal's own "${line.trim()}" is overridden by the managed block`);
    }
    if (line.trim() === MARK_END) inside = false;
  }
  writeFileSync(outPath, content);
  console.log(action);
}

async function probe(tag) {
  /** @type {Record<string, number>} */
  const out = {};
  for (const p of PROBES) out[p] = (await get(bust(`${ORIGIN}${p}`, tag))).status;
  return out;
}

async function checkRoot(tag) {
  const problems = [];
  const robots = await get(bust(`${ORIGIN}/robots.txt`, tag));
  if (robots.status !== 200) problems.push(`robots.txt answered HTTP ${robots.status}`);
  else problems.push(...robotsProblems(robots.body).map((p) => `robots.txt: ${p}`));

  const sm = await get(bust(`${ORIGIN}/sitemap.xml`, tag));
  const locs = sm.status === 200 ? sitemapLocs(sm.body) : [];
  if (sm.status !== 200) problems.push(`sitemap.xml answered HTTP ${sm.status}`);
  else {
    // The list is built at deploy time. A page made public or hidden since
    // then shows up here; re-running deploy-root.yml brings it up to date.
    const want = await sitemapUrls();
    for (const u of want) {
      if (!locs.includes(u)) problems.push(`sitemap.xml misses ${u}; re-run deploy-root.yml`);
    }
    for (const u of locs) {
      if (!want.includes(u)) problems.push(`sitemap.xml lists ${u}, which is not a live public page`);
    }
  }
  for (const loc of locs) {
    const s = (await get(loc)).status;
    if (s !== 200) problems.push(`sitemap URL ${loc} answered HTTP ${s}`);
  }

  const cases = [
    { path: `/bk-root-check-${Date.now().toString(36)}-missing`, code: '404' },
    { path: '/.env', code: '403' },
    // The bare directories the deploy creates must not list their files.
    ...ROOT_DIRS.map((dir) => ({ path: `/${dir}/`, code: '404' })),
  ];
  for (const { path, code } of cases) {
    problems.push(...errorPageProblems(path, code, await get(bust(`${ORIGIN}${path}`, tag))));
  }

  if (tag) {
    // Right after a deploy the file must be the one just uploaded (~11 months left).
    const sec = await get(bust(`${ORIGIN}/.well-known/security.txt`, tag));
    const opts = { canonical: ROOT_SECURITY_TXT, now: new Date(), minDaysLeft: 300 };
    if (sec.status !== 200) problems.push(`security.txt answered HTTP ${sec.status}`);
    else problems.push(...securityTxtProblems({ body: sec.body, contentType: sec.type, ...opts }));
  }
  return problems;
}

async function checkSite() {
  const problems = [];
  for (const path of ['/bk-site-check-missing', '/install.sh', '/cs/bk-site-check-missing']) {
    const r = await get(`${SITE}${path}`);
    if (r.status !== 404) problems.push(`${SITE}${path} answered HTTP ${r.status}, expected 404`);
  }
  const home = await get(`${SITE}/`);
  if (home.status !== 200) problems.push(`${SITE}/ answered HTTP ${home.status}`);
  return problems;
}

async function checkSecurity() {
  const problems = [];
  for (const url of [ROOT_SECURITY_TXT, SITE_SECURITY_TXT]) {
    const r = await get(url);
    if (r.status !== 200) {
      problems.push(`${url} answered HTTP ${r.status}`);
      continue;
    }
    const found = securityTxtProblems({
      body: r.body,
      contentType: r.type,
      canonical: url,
      now: new Date(),
      minDaysLeft: 30,
    });
    problems.push(...found.map((p) => `${url}: ${p}`));
  }
  return problems;
}

/**
 * The four settings only Cloudflare's dashboard can make (owner's list from
 * 2026-09-23). Every problem names the item whose setting is missing or wrong,
 * so a red run lists exactly what is still open. Always the real hosts: none
 * of this exists on a local origin.
 */
async function checkCloudflare() {
  const TURBO = 'x-turbo-charged-by';
  const PROXIED = 'www Proxied';
  const REDIRECT = 'www -> apex 301';
  const PAGES = 'pages.dev Bulk Redirect';
  const problems = [];
  const item = (name, found) => problems.push(...found.map((p) => `[${name}] ${p}`));
  // A request that fails (DNS, TLS, timeout) is a problem of its item, not the
  // end of the run: a DNS-only www with an origin certificate that does not
  // cover it must not hide the state of the other items.
  const tryGet = async (name, url) => {
    try {
      return await get(url);
    } catch (e) {
      item(name, [`${url} failed: ${e.cause?.code ?? e.name}: ${e.cause?.message ?? e.message}`]);
      return null;
    }
  };
  const mitigated = (r) => (r.headers['cf-mitigated'] ? ` (cf-mitigated: ${r.headers['cf-mitigated']})` : '');

  // 1. A response header rule removes the origin's x-turbo-charged-by. Only
  // an answer from the origin can show that: one Cloudflare makes itself (a
  // challenge, a 52x) carries no origin header whether the rule exists or not.
  const missing = `/bk-edge-check-${Date.now().toString(36)}-missing`;
  let apexHome = null;
  for (const [path, want] of [
    ['/', 200],
    ['/app/public', 200],
    ['/status/api.php?action=public_status', 200],
    [missing, 404],
  ]) {
    const url = `${PUBLIC}${path}`;
    const r = await tryGet(TURBO, url);
    if (!r) continue;
    if (path === '/') apexHome = r;
    if (r.status !== want || 'cf-mitigated' in r.headers) {
      item(TURBO, [`${url} could not be judged: HTTP ${r.status}${mitigated(r)}, expected ${want} from the origin`]);
      continue;
    }
    item(TURBO, [...notProxiedProblems(url, r.headers), ...serverHeaderProblems(url, r.headers)]);
  }

  // 2. www is proxied: its answers carry cf-ray like the apex's. Once they
  // do, the header rule has to cover www too, and nothing may challenge or
  // block the machine endpoints under its /status.
  const wwwStatus = `${WWW}/status/api.php?action=public_status`;
  const ws = await tryGet(PROXIED, wwwStatus);
  const wwwProxied = ws !== null && 'cf-ray' in ws.headers;
  if (ws) item(PROXIED, notProxiedProblems(wwwStatus, ws.headers));
  if (wwwProxied) {
    const leaks = serverHeaderProblems(wwwStatus, ws.headers);
    item(
      TURBO,
      leaks.map((p) => `${p} - the rule must match www.bloodkings.eu as well`)
    );
    if (ws.status !== 200 && (ws.status < 300 || ws.status > 399)) {
      item(PROXIED, [`${wwwStatus} answered HTTP ${ws.status}${mitigated(ws)}, expected 200 - agents POST there`]);
    }
  }

  // 3. Everything else on www is a 301 to the apex, path and query kept. Its
  // /status is served, never redirected: agents, cron jobs and heartbeats set
  // up from a www session POST there, and curl without -L drops the body.
  const wwwPage = `${WWW}/bk-edge-check?x=1`;
  const wp = await tryGet(REDIRECT, wwwPage);
  if (wp) item(REDIRECT, redirectProblems(wwwPage, wp, `${PUBLIC}/bk-edge-check?x=1`));
  if (wwwProxied && ws.status >= 300 && ws.status <= 399) {
    item(REDIRECT, [`${wwwStatus} redirects to ${ws.location ?? 'nowhere'} - the rule must leave /status alone`]);
  }
  const home = apexHome?.location ? new URL(apexHome.location, PUBLIC) : null;
  if (home && home.host === new URL(WWW).host) {
    item(REDIRECT, [`${PUBLIC}/ redirects to ${apexHome.location}, which loops with the www rule`]);
  }

  // 4. A Bulk Redirect sends the Pages project's own hostname to the site.
  const pagesDev = `${PAGES_DEV}/bk-site-check?x=1`;
  const pd = await tryGet(PAGES, pagesDev);
  if (pd) item(PAGES, redirectProblems(pagesDev, pd, `${SITE}/bk-site-check?x=1`));
  return problems;
}

function report(problems, what) {
  for (const p of problems) console.error(`::error::${p}`);
  if (problems.length > 0) process.exit(1);
  console.log(`${what}: OK`);
}

const [cmd, a, b] = process.argv.slice(2);
if (cmd === 'build' && a) await build(a);
else if (cmd === 'merge-htaccess' && a && b) mergeCmd(a, b);
else if (cmd === 'statuses' && a) writeFileSync(a, JSON.stringify(await probe(`pre-${Date.now()}`)));
else if (cmd === 'same-statuses' && a) {
  const before = JSON.parse(readFileSync(a, 'utf8'));
  const after = await probe(`post-${Date.now()}`);
  const diff = PROBES.filter((p) => before[p] !== after[p]).map(
    (p) => `${p}: HTTP ${before[p]} before, ${after[p]} after`
  );
  report(diff, `probes unchanged ${JSON.stringify(after)}`);
} else if (cmd === 'check-root') report(await checkRoot(a), 'bloodkings.eu root files');
else if (cmd === 'check-site') report(await checkSite(), 'monitoring.bloodkings.eu 404s');
else if (cmd === 'check-security') report(await checkSecurity(), 'security.txt on both hosts');
else if (cmd === 'check-cloudflare') report(await checkCloudflare(), 'Cloudflare settings');
else {
  console.error('usage: see the header of root/deploy.mjs');
  process.exit(2);
}
