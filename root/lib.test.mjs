// node --test root/*.test.mjs - no dependencies, so CI runs it before `npm ci`.
/* global URL */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  addMonthsUtc,
  errorPageProblems,
  mergeHtaccess,
  MARK_BEGIN,
  MARK_END,
  parseSecurityTxt,
  publicStatusPageUrls,
  ROOT_DIRS,
  robotsProblems,
  securityTxt,
  securityTxtProblems,
  sitemapLocs,
  sitemapXml,
} from './lib.mjs';

const NOW = new Date('2026-09-23T10:20:30.456Z');
const CANON = 'https://bloodkings.eu/.well-known/security.txt';

test('addMonthsUtc: +11 měsíců, neexistující den se zkrátí na konec měsíce', () => {
  assert.equal(addMonthsUtc(NOW, 11).toISOString(), '2027-08-23T10:20:30.456Z');
  assert.equal(addMonthsUtc(new Date('2026-03-31T00:00:00Z'), 11).toISOString(), '2027-02-28T00:00:00.000Z');
  assert.equal(addMonthsUtc(new Date('2027-03-31T00:00:00Z'), 11).toISOString(), '2028-02-29T00:00:00.000Z');
});

test('securityTxt: pole podle RFC 9116, Expires za 11 měsíců bez milisekund', () => {
  const body = securityTxt({ canonical: CANON, now: NOW, withPolicy: true });
  const f = parseSecurityTxt(body);
  assert.deepEqual(f.contact, ['mailto:security@bloodkings.eu']);
  assert.deepEqual(f.expires, ['2027-08-23T10:20:30Z']);
  assert.deepEqual(f['preferred-languages'], ['cs, en']);
  assert.deepEqual(f.canonical, [CANON]);
  assert.deepEqual(f.policy, ['https://github.com/BKPepe/monitoring/blob/main/SECURITY.md']);
  assert.ok(body.endsWith('\n'));
  assert.match(body, /^[\x20-\x7e\n]*$/, 'jen ASCII, ať nezáleží na charsetu');
});

test('securityTxt: bez SECURITY.md v checkoutu žádný Policy odkaz do prázdna', () => {
  const f = parseSecurityTxt(securityTxt({ canonical: CANON, now: NOW, withPolicy: false }));
  assert.equal(f.policy, undefined);
  assert.deepEqual(f.contact, ['mailto:security@bloodkings.eu']);
});

test('securityTxtProblems: čerstvý soubor projde', () => {
  const body = securityTxt({ canonical: CANON, now: NOW, withPolicy: true });
  const problems = securityTxtProblems({
    body,
    contentType: 'text/plain; charset=utf-8',
    canonical: CANON,
    now: NOW,
    minDaysLeft: 30,
  });
  assert.deepEqual(problems, []);
});

test('securityTxtProblems: Expires za méně než 30 dní, špatný typ a cizí Canonical jsou chyby', () => {
  const body = securityTxt({
    canonical: 'https://monitoring.bloodkings.eu/.well-known/security.txt',
    now: NOW,
    withPolicy: false,
  });
  const later = new Date('2027-08-01T00:00:00Z');
  const problems = securityTxtProblems({
    body,
    contentType: 'text/html',
    canonical: CANON,
    now: later,
    minDaysLeft: 30,
  });
  assert.equal(problems.length, 3);
  assert.match(problems.join('\n'), /Content-Type is text\/html/);
  assert.match(problems.join('\n'), /Canonical is not/);
  assert.match(problems.join('\n'), /22 days away \(minimum 30\)/);
});

test('securityTxtProblems: HTML stránka místo souboru (SPA fallback) neprojde', () => {
  const problems = securityTxtProblems({
    body: '<!doctype html><html><body>Blood Kings</body></html>',
    contentType: 'text/html; charset=utf-8',
    canonical: CANON,
    now: NOW,
    minDaysLeft: 30,
  });
  assert.ok(problems.some((p) => p.startsWith('Contact is not')));
  assert.ok(problems.some((p) => p.startsWith('expected exactly one Expires')));
});

test('publicStatusPageUrls: jen veřejné stránky, slug se escapuje', () => {
  const urls = publicStatusPageUrls({
    pages: [
      { slug: 'hry', isPublic: true },
      { slug: 'koncept', isPublic: false },
      { slug: 'a b&c', isPublic: true },
    ],
  });
  assert.deepEqual(urls, [
    'https://bloodkings.eu/app/public?page=hry',
    'https://bloodkings.eu/app/public?page=a%20b%26c',
  ]);
  assert.deepEqual(publicStatusPageUrls({ pages: [] }), []);
});

test('publicStatusPageUrls: chybová odpověď API shodí krok, žádná tichá prázdná mapa', () => {
  assert.throws(() => publicStatusPageUrls({ error: 'Status stránky se nepodařilo načíst.' }), /no "pages" array/);
  assert.throws(() => publicStatusPageUrls(null), /no "pages" array/);
  assert.throws(() => publicStatusPageUrls({ pages: [{ isPublic: true }] }), /without a slug/);
});

test('sitemapXml: platné XML, escapované &, bez duplicit a bez vymyšleného lastmod', () => {
  const xml = sitemapXml([
    'https://bloodkings.eu/',
    'https://bloodkings.eu/app/public',
    'https://bloodkings.eu/app/public?page=a%20b&x=1',
    'https://bloodkings.eu/',
  ]);
  assert.ok(
    xml.startsWith(
      '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    )
  );
  assert.ok(xml.includes('<loc>https://bloodkings.eu/app/public?page=a%20b&amp;x=1</loc>'));
  assert.ok(!/lastmod|changefreq|priority/.test(xml));
  assert.deepEqual(sitemapLocs(xml), [
    'https://bloodkings.eu/',
    'https://bloodkings.eu/app/public',
    'https://bloodkings.eu/app/public?page=a%20b&x=1',
  ]);
});

const BLOCK = readFileSync(new URL('./htaccess.block', import.meta.url), 'utf8');
const PORTAL = 'RewriteEngine On\nRewriteRule ^hra/(.*)$ index.php?p=$1 [L,QSA]\n';

test('mergeHtaccess: chybějící blok se připojí za portál, portál zůstane bajt po bajtu', () => {
  const { content, action } = mergeHtaccess(PORTAL, BLOCK);
  assert.equal(action, 'inserted');
  assert.ok(content.startsWith(PORTAL));
  assert.ok(content.includes('ErrorDocument 404 /errors/404.html'));
  assert.equal(content.split(MARK_BEGIN).length, 2);
  assert.ok(content.endsWith(`${MARK_END}\n`));
});

test('mergeHtaccess: druhé nasazení nic nemění, nový blok nahradí jen řádky mezi značkami', () => {
  const once = mergeHtaccess(PORTAL, BLOCK).content;
  assert.deepEqual(mergeHtaccess(once, BLOCK), { content: once, action: 'unchanged' });

  const edited = `# hand edit above\n${once}# portal tail\nRewriteRule ^x$ y [L]\n`;
  const newBlock = `${MARK_BEGIN}\nErrorDocument 404 /errors/404.html\n${MARK_END}\n`;
  const { content, action } = mergeHtaccess(edited, newBlock);
  assert.equal(action, 'updated');
  assert.ok(content.startsWith(`# hand edit above\n${PORTAL}`));
  assert.ok(content.endsWith(`${MARK_END}\n# portal tail\nRewriteRule ^x$ y [L]\n`));
  assert.ok(!content.includes('ErrorDocument 503'));
});

test('mergeHtaccess: CRLF soubor zůstane CRLF a prázdný soubor dostane jen blok', () => {
  const crlf = PORTAL.replace(/\n/g, '\r\n');
  const { content } = mergeHtaccess(crlf, BLOCK);
  assert.ok(content.startsWith(crlf));
  assert.ok(!/[^\r]\n/.test(content), 'žádný osamocený LF');
  assert.ok(mergeHtaccess('', BLOCK).content.startsWith(MARK_BEGIN));
});

test('mergeHtaccess: zdvojené, nepárové nebo prohozené značky shodí nasazení', () => {
  const once = mergeHtaccess(PORTAL, BLOCK).content;
  assert.throws(() => mergeHtaccess(once + once, BLOCK), /more than one managed block/);
  assert.throws(() => mergeHtaccess(`${PORTAL}${MARK_BEGIN}\nErrorDocument 404 /x\n`, BLOCK), /unbalanced/);
  assert.throws(() => mergeHtaccess(`${MARK_END}\n${PORTAL}${MARK_BEGIN}\n`, BLOCK), /END marker comes before/);
  assert.throws(() => mergeHtaccess(PORTAL, 'ErrorDocument 404 /x\n'), /must start with the BEGIN marker/);
});

// The RewriteRule lines of the block that answer 404, as JS regexps: the
// patterns are plain PCRE that JS reads the same way.
const closingRules = () =>
  [...BLOCK.matchAll(/^\s*RewriteRule\s+(\S+)\s+-\s+\[R=404\b[^\]]*\]\s*$/gm)].map((m) => new RegExp(m[1]));
const closed = (path) => closingRules().some((re) => re.test(path));

test('htaccess.block: holé adresáře, které nasazení vytváří, nevypisují obsah (404)', () => {
  assert.match(BLOCK, /<IfModule mod_rewrite\.c>\s*\n\s*RewriteEngine On\s*\n\s*RewriteRule /);
  for (const dir of ROOT_DIRS) {
    // mod_rewrite in .htaccess sees the path without the leading slash.
    assert.ok(closed(`${dir}/`), `/${dir}/ zůstává otevřený výpis`);
    assert.ok(closed(dir), `/${dir} zůstává otevřený výpis`);
  }
});

test('htaccess.block: soubory v adresářích a ErrorDocument podpožadavky zůstanou dostupné', () => {
  for (const path of [
    'errors/404.html',
    'errors/403.html',
    '.well-known/security.txt',
    '.well-known/acme-challenge/token',
    '.well-known/pki-validation/',
    'errorsx/',
    'app/errors/',
    '',
  ]) {
    assert.ok(!closed(path), `/${path} se nesmí zavřít`);
  }
});

test('errorPageProblems: výpis adresáře se jménem serveru místo značkové 404 je chyba', () => {
  const listing =
    '<html><head><title>Index of /errors/</title></head><body><h1>Index of /errors/</h1>' +
    '<address>Proudly Served by LiteSpeed Web Server</address></body></html>';
  assert.deepEqual(errorPageProblems('/errors/', '404', { status: 200, body: listing }), [
    '/errors/ answered HTTP 200, expected 404',
    '/errors/ is not the branded 404 page',
    '/errors/ is a directory listing',
    '/errors/ names the server software',
  ]);
  const branded = readFileSync(new URL('./errors/404.html', import.meta.url), 'utf8');
  assert.deepEqual(errorPageProblems('/errors/', '404', { status: 404, body: branded }), []);
});

const CF_MANAGED = `# As a condition of accessing this website, you agree to abide by the following
# content signals:

User-agent: *
Content-Signal: search=yes,ai-train=no
Allow: /

User-agent: GPTBot
Disallow: /
`;
const ROBOTS = readFileSync(new URL('./robots.txt', import.meta.url), 'utf8');

test('robots.txt: naše pravidla projdou samostatně i pod blokem od Cloudflare', () => {
  assert.deepEqual(robotsProblems(ROBOTS), []);
  assert.deepEqual(robotsProblems(`${CF_MANAGED}\n${ROBOTS}`), []);
});

test('robots.txt: herní portál nesmí nic zakázat, jen /app/ a strojové koncové body /status/', () => {
  const rules = ROBOTS.split('\n')
    .map((l) => l.replace(/#.*$/, '').trim())
    .filter((l) => /^disallow:/i.test(l))
    .map((l) => l.replace(/^disallow:\s*/i, ''));
  assert.ok(rules.length > 0);
  for (const path of rules) assert.match(path, /^\/(app\/$|status\/[a-z_]+\.php$)/, path);
});

test('robots.txt: plošný zákaz pro všechny roboty nebo chybějící Sitemap se hlásí', () => {
  assert.ok(
    robotsProblems(`${ROBOTS}\nUser-agent: *\nDisallow: /\n`).includes('a blanket "Disallow: /" for every crawler')
  );
  assert.deepEqual(robotsProblems(`User-agent: GPTBot\nDisallow: /\n${ROBOTS}`), []);
  assert.ok(
    robotsProblems(ROBOTS.replace(/^Sitemap:.*$/m, '')).includes('missing "Sitemap: https://bloodkings.eu/sitemap.xml"')
  );
});
