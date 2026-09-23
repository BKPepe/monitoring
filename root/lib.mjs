// Pure helpers behind the files this repository serves at the bloodkings.eu
// root (and the security.txt of monitoring.bloodkings.eu). No I/O here, so
// root/lib.test.mjs covers every branch without a network or a server.

export const SECURITY_CONTACT = 'mailto:security@bloodkings.eu';
export const SECURITY_POLICY = 'https://github.com/BKPepe/monitoring/blob/main/SECURITY.md';
export const MARK_BEGIN = '# BEGIN bloodkings-monitoring';
export const MARK_END = '# END bloodkings-monitoring';

/**
 * `now` plus `months` calendar months, in UTC. A day that does not exist in
 * the target month clamps to its last day, so the result never runs past the
 * one-year ceiling RFC 9116 recommends for Expires.
 * @param {Date} now
 * @param {number} months
 * @returns {Date}
 */
export function addMonthsUtc(now, months) {
  const d = new Date(now.getTime());
  const day = d.getUTCDate();
  d.setUTCDate(1);
  d.setUTCMonth(d.getUTCMonth() + months);
  const lastDay = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate();
  d.setUTCDate(Math.min(day, lastDay));
  return d;
}

/**
 * RFC 9116 security.txt. Expires is computed from the build or deploy time
 * (+11 months), so the file is regenerated instead of going stale in git.
 * Policy is listed only when the caller has seen SECURITY.md in the checkout:
 * a Policy link that answers 404 would tell a reporter there is none.
 * @param {{ canonical: string, now: Date, withPolicy: boolean }} opts
 * @returns {string}
 */
export function securityTxt({ canonical, now, withPolicy }) {
  const expires = addMonthsUtc(now, 11)
    .toISOString()
    .replace(/\.\d{3}Z$/, 'Z');
  const host = canonical.replace(/^https?:\/\//, '').split('/')[0];
  return [
    `# Security contact for ${host}. Please report vulnerabilities privately`,
    '# by e-mail. Czech or English, whichever you prefer.',
    `Contact: ${SECURITY_CONTACT}`,
    `Expires: ${expires}`,
    'Preferred-Languages: cs, en',
    `Canonical: ${canonical}`,
    ...(withPolicy ? [`Policy: ${SECURITY_POLICY}`] : []),
    '',
  ].join('\n');
}

/**
 * Reads the fields a checker needs from a security.txt body.
 * @param {string} body
 * @returns {Record<string, string[]>} lower-cased field name -> values
 */
export function parseSecurityTxt(body) {
  /** @type {Record<string, string[]>} */
  const fields = {};
  for (const raw of body.split(/\r?\n/)) {
    const line = raw.trim();
    if (line === '' || line.startsWith('#')) continue;
    const idx = line.indexOf(':');
    if (idx <= 0) continue;
    const name = line.slice(0, idx).trim().toLowerCase();
    (fields[name] ??= []).push(line.slice(idx + 1).trim());
  }
  return fields;
}

/**
 * Everything wrong with a fetched security.txt, as readable lines; empty when
 * the file is fine. `minDaysLeft` is how close Expires may get before it is
 * an error (the scheduled check uses 30 days).
 * @param {{ body: string, contentType: string | null, canonical: string, now: Date, minDaysLeft: number }} opts
 * @returns {string[]}
 */
export function securityTxtProblems({ body, contentType, canonical, now, minDaysLeft }) {
  const problems = [];
  const f = parseSecurityTxt(body);
  if (!(contentType ?? '').toLowerCase().startsWith('text/plain')) {
    problems.push(`Content-Type is ${contentType ?? 'missing'}, expected text/plain`);
  }
  if (!(f.contact ?? []).includes(SECURITY_CONTACT)) problems.push(`Contact is not ${SECURITY_CONTACT}`);
  if (!(f.canonical ?? []).includes(canonical)) problems.push(`Canonical is not ${canonical}`);
  if ((f['preferred-languages'] ?? [])[0] !== 'cs, en') problems.push('Preferred-Languages is not "cs, en"');
  const expires = f.expires ?? [];
  if (expires.length !== 1) {
    problems.push(`expected exactly one Expires, found ${expires.length}`);
  } else {
    const at = Date.parse(expires[0]);
    if (Number.isNaN(at)) {
      problems.push(`Expires "${expires[0]}" is not a date`);
    } else {
      const daysLeft = (at - now.getTime()) / 86_400_000;
      if (daysLeft < minDaysLeft) {
        problems.push(
          `Expires ${expires[0]} is ${Math.floor(daysLeft)} days away (minimum ${minDaysLeft}); redeploy to renew it`
        );
      }
    }
  }
  return problems;
}

/**
 * The public custom status pages from an anonymous `status_pages` answer, as
 * absolute URLs of the public page. A malformed answer throws: a sitemap
 * built from a guess would be worse than a failed deploy step.
 * @param {unknown} answer
 * @returns {string[]}
 */
export function publicStatusPageUrls(answer) {
  const pages = /** @type {{ pages?: unknown }} */ (answer)?.pages;
  if (!Array.isArray(pages)) throw new Error('status_pages answer has no "pages" array');
  const urls = [];
  for (const p of pages) {
    if (!p || typeof p !== 'object') throw new Error('status_pages item is not an object');
    const { slug, isPublic } = /** @type {{ slug?: unknown, isPublic?: unknown }} */ (p);
    if (isPublic !== true) continue;
    if (typeof slug !== 'string' || slug === '') throw new Error('public status page without a slug');
    urls.push(`https://bloodkings.eu/app/public?page=${encodeURIComponent(slug)}`);
  }
  return urls;
}

/** @param {string} s */
function xmlEscape(s) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

/**
 * sitemap.xml for the given absolute URLs. No lastmod: neither the game
 * portal's home page nor a live status page changes at deploy time, and an
 * invented date is worse than none (the protocol makes it optional).
 * @param {string[]} urls
 * @returns {string}
 */
export function sitemapXml(urls) {
  const unique = [...new Set(urls)];
  const body = unique.map((u) => `  <url><loc>${xmlEscape(u)}</loc></url>`).join('\n');
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${body}\n</urlset>\n`;
}

/**
 * Extracts the <loc> URLs from a sitemap body.
 * @param {string} xml
 * @returns {string[]}
 */
export function sitemapLocs(xml) {
  return [...xml.matchAll(/<loc>([^<]*)<\/loc>/g)].map((m) =>
    m[1]
      .replace(/&apos;/g, "'")
      .replace(/&quot;/g, '"')
      .replace(/&gt;/g, '>')
      .replace(/&lt;/g, '<')
      .replace(/&amp;/g, '&')
  );
}

/**
 * Puts `block` (which starts with MARK_BEGIN and ends with MARK_END) into the
 * live root .htaccess of the game portal. Only the lines between the markers
 * are ever ours: an existing block is replaced in place, a missing one is
 * appended, and every other line stays byte for byte. Unbalanced or doubled
 * markers throw, because guessing where our lines end could cut the portal's.
 * @param {string} live
 * @param {string} block
 * @returns {{ content: string, action: 'inserted' | 'updated' | 'unchanged' }}
 */
export function mergeHtaccess(live, block) {
  const eol = live.includes('\r\n') ? '\r\n' : '\n';
  const blockLines = block.replace(/\r\n/g, '\n').replace(/\n+$/, '').split('\n');
  if (blockLines[0] !== MARK_BEGIN || blockLines[blockLines.length - 1] !== MARK_END) {
    throw new Error('block must start with the BEGIN marker and end with the END marker');
  }
  const lines = live === '' ? [] : live.split(/\r?\n/);
  const begins = lines.flatMap((l, i) => (l.trim() === MARK_BEGIN ? [i] : []));
  const ends = lines.flatMap((l, i) => (l.trim() === MARK_END ? [i] : []));
  if (begins.length > 1 || ends.length > 1) throw new Error('the live .htaccess has more than one managed block');
  if (begins.length !== ends.length) throw new Error('the live .htaccess has an unbalanced managed block');

  if (begins.length === 0) {
    // Keep the file's own last line intact and put the block after it.
    const trimmed = lines.length > 0 && lines[lines.length - 1] === '' ? lines.slice(0, -1) : lines;
    const out = [...trimmed, ...(trimmed.length > 0 ? [''] : []), ...blockLines, ''];
    return { content: out.join(eol), action: 'inserted' };
  }
  const [b, e] = [begins[0], ends[0]];
  if (e < b) throw new Error('the END marker comes before the BEGIN marker');
  const out = [...lines.slice(0, b), ...blockLines, ...lines.slice(e + 1)];
  const content = out.join(eol);
  return { content, action: content === live ? 'unchanged' : 'updated' };
}

/**
 * Problems with the served robots.txt (Cloudflare's managed block followed by
 * root/robots.txt). A blanket `Disallow: /` anywhere for `*` would hide the
 * game portal, so it is an error even when our own rules are present.
 * @param {string} body
 * @returns {string[]}
 */
export function robotsProblems(body) {
  const problems = [];
  const lines = body
    .split(/\r?\n/)
    .map((l) => l.replace(/#.*$/, '').trim())
    .filter(Boolean);
  for (const need of [
    'Allow: /app/public',
    'Allow: /app/assets/',
    'Disallow: /app/',
    'Disallow: /status/api.php',
    'Sitemap: https://bloodkings.eu/sitemap.xml',
  ]) {
    if (!lines.includes(need)) problems.push(`missing "${need}"`);
  }
  let agent = null;
  for (const l of lines) {
    const m = /^user-agent:\s*(.+)$/i.exec(l);
    if (m) {
      agent = m[1].trim();
      continue;
    }
    if (agent === '*' && /^disallow:\s*\/\s*$/i.test(l)) problems.push('a blanket "Disallow: /" for every crawler');
  }
  return problems;
}
