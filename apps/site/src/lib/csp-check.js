// Checks of the built site against the <meta> CSP that Astro writes into each
// page. astro.config.mjs runs them after every build and fails it, so a
// policy that would block the site's own code (or log a console error on
// every load) never reaches Cloudflare Pages. No I/O here: the unit tests
// feed the functions strings.
import { createHash } from 'node:crypto';

/**
 * The CSP source for `text`, as a script-src or style-src entry takes it.
 * @param {string} text
 * @returns {`sha256-${string}`}
 */
export function sha256Source(text) {
  return `sha256-${createHash('sha256').update(text, 'utf8').digest('base64')}`;
}

// A browser ignores these in a <meta> policy (and Chrome logs an error for
// frame-ancestors), so they only work as a real header from public/_headers.
const META_IGNORED = ['frame-ancestors', 'report-uri', 'report-to', 'sandbox'];

/**
 * The directives of the page's <meta http-equiv="content-security-policy">,
 * or null when the page has none.
 * @param {string} html
 * @returns {Map<string, string[]> | null}
 */
export function metaCsp(html) {
  const m = /<meta\s+http-equiv="content-security-policy"\s+content="([^"]*)"/i.exec(html);
  if (!m) return null;
  /** @type {Map<string, string[]>} */
  const directives = new Map();
  for (const part of m[1].replace(/&#39;/g, "'").split(';')) {
    const [name, ...sources] = part.trim().split(/\s+/);
    if (name) directives.set(name.toLowerCase(), sources);
  }
  return directives;
}

/** @param {Map<string, string[]>} csp @param {string[]} names */
function sourcesFor(csp, names) {
  for (const name of [...names, 'default-src']) {
    const sources = csp.get(name);
    if (sources) return sources;
  }
  return [];
}

// Only these <script> types run; a data block (application/ld+json) needs no hash.
const RUNS = /^(|text\/javascript|application\/javascript|module)$/i;

/**
 * Everything in one built page that its own meta CSP gets wrong: a directive
 * the browser ignores there, and an inline script or <style> whose hash the
 * policy does not allow (the browser would refuse to run or apply it).
 * @param {string} html
 * @returns {string[]}
 */
export function pageCspProblems(html) {
  const csp = metaCsp(html);
  if (!csp) return ['no <meta> content-security-policy'];
  const problems = [];
  for (const name of META_IGNORED) {
    if (csp.has(name)) problems.push(`${name} is ignored in a <meta> CSP; send it from public/_headers`);
  }
  const checks = [
    { tag: 'script', allowed: sourcesFor(csp, ['script-src-elem', 'script-src']) },
    { tag: 'style', allowed: sourcesFor(csp, ['style-src-elem', 'style-src']) },
  ];
  for (const { tag, allowed } of checks) {
    if (allowed.includes("'unsafe-inline'")) continue;
    const re = new RegExp(`<${tag}\\b([^>]*)>([\\s\\S]*?)</${tag}>`, 'gi');
    for (const [, attrs, body] of html.matchAll(re)) {
      if (/\bsrc\s*=/i.test(attrs)) continue;
      const type = /\btype\s*=\s*"([^"]*)"/i.exec(attrs)?.[1] ?? '';
      if (tag === 'script' && !RUNS.test(type)) continue;
      const hash = sha256Source(body);
      if (!allowed.includes(`'${hash}'`)) {
        const start = body.trim().slice(0, 40).replace(/\s+/g, ' ');
        problems.push(`inline <${tag}> "${start}..." (${hash}) is not allowed by the CSP`);
      }
    }
  }
  return problems;
}

/**
 * A font Vite inlined into a stylesheet as a data: URL is blocked when
 * font-src does not allow data:, which Chrome reports on every page load.
 * @param {string} css
 * @param {string[]} fontSrc the page's font-src sources
 * @returns {string[]}
 */
export function inlinedFontProblems(css, fontSrc) {
  if (fontSrc.includes('data:')) return [];
  const count = [...css.matchAll(/url\(\s*["']?data:(font\/|application\/(x-)?font)/gi)].length;
  return count > 0 ? [`${count} font(s) inlined as data: URLs, which font-src blocks`] : [];
}
