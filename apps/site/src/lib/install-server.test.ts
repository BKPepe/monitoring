import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { experimental_AstroContainer as AstroContainer } from 'astro/container';
import { describe, expect, it } from 'vitest';
import InstallServer from '../components/InstallServer.astro';
import { MEASURED_INSTALL_MINUTES, RELEASE_SUMS_URL, RELEASE_ZIP_URL } from '../config';
import { dicts } from '../i18n';

/**
 * One install guide for the server. Home, /download/ and the docs used to
 * give three different installs, none of them complete. These tests pin the
 * one guide's steps and commands, keep the pages from growing a copy of their
 * own, and tie the facts the guide states to the PHP and to the app.
 */

const SITE = fileURLToPath(new URL('../', import.meta.url));
const STATUS = fileURLToPath(new URL('../../../status/', import.meta.url));
const MONITOR = fileURLToPath(new URL('../../../monitor/src/', import.meta.url));
const read = (path: string) => readFileSync(path, 'utf8');

async function render(lang: 'en' | 'cs', variant: 'full' | 'summary') {
  const container = await AstroContainer.create();
  return container.renderToString(InstallServer, { props: { lang, variant } });
}

/** Astro escapes & < > " ' in text; the page shows the unescaped text. */
const unescape = (html: string) =>
  html
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&');

const headings = (html: string) =>
  [...html.matchAll(/<h[34][^>]*>([\s\S]*?)<\/h[34]>/g)].map((m) => unescape(m[1].trim()));
const codeBlocks = (html: string) => [...html.matchAll(/<code[^>]*>([\s\S]*?)<\/code>/g)].map((m) => unescape(m[1]));

const CRON = 'php -q /home/USER/public_html/status/cron.php >/dev/null 2>&1';

describe('jeden návod k instalaci serveru', () => {
  for (const lang of ['en', 'cs'] as const) {
    it(`celý návod má sedm kroků v pořadí (${lang})`, async () => {
      const t = dicts[lang].server;
      expect(headings(await render(lang, 'full'))).toEqual([
        t.req.title,
        t.upload.title,
        t.db.title,
        t.config.title,
        t.cron.title,
        t.admin.title,
        t.check.title,
      ]);
    });
  }

  it('příkazy jsou v obou jazycích stejné a kompletní', async () => {
    const enCode = codeBlocks(await render('en', 'full'));
    expect(codeBlocks(await render('cs', 'full'))).toEqual(enCode);
    expect(enCode).toContain('mysql -u YOUR_DB_USER -p YOUR_DB_NAME < schema.sql');
    // cPanel's Command field gets the command alone, crontab -e the whole line.
    expect(enCode).toContain(CRON);
    expect(enCode).toContain(`* * * * * ${CRON}`);
    expect(enCode).toContain('curl -s "https://YOUR-DOMAIN/status/api.php?action=collection_health"');
    // --recursive needs the https submodule URL: an SSH one fails for anyone without a key.
    expect(enCode.some((c) => c.startsWith('git clone --recursive https://github.com/BKPepe/monitoring.git'))).toBe(
      true
    );
    const config = enCode.find((c) => c.includes('DB_HOST')) ?? '';
    expect([...config.matchAll(/define\('([A-Z_]+)'/g)].map((m) => m[1])).toEqual([
      'DB_HOST',
      'DB_PORT',
      'DB_NAME',
      'DB_USER',
      'DB_PASS',
    ]);
  });

  it('tlačítko stahuje pevný ZIP posledního vydání a vedle něj SHA256SUMS', async () => {
    expect(RELEASE_ZIP_URL).toBe(
      'https://github.com/BKPepe/monitoring/releases/latest/download/bloodkings-monitoring.zip'
    );
    const html = await render('en', 'full');
    expect(html).toContain(`href="${RELEASE_ZIP_URL}"`);
    expect(html).toContain(`href="${RELEASE_SUMS_URL}"`);
    // Until the page asks the worker, the version is a dash, never a number.
    expect(html).toMatch(/data-release="line"[^>]*>\s*Latest release: —\s*</);
  });

  it('nezměřenou dobu instalace ukáže jako pomlčku', async () => {
    const shown = MEASURED_INSTALL_MINUTES === null ? '—' : `${MEASURED_INSTALL_MINUTES} min`;
    for (const variant of ['full', 'summary'] as const) {
      const strong = /<strong[^>]*>([^<]*)<\/strong>/.exec(await render('en', variant))?.[1];
      expect(strong, variant).toBe(shown);
    }
  });

  it('shrnutí na úvodní stránce má čtyři kroky a odkaz na celý návod', async () => {
    const html = await render('cs', 'summary');
    expect(headings(html)).toEqual(dicts.cs.server.summary.map((s) => s.title));
    expect(html).toContain('href="/cs/download/#server"');
    expect(await render('en', 'summary')).toContain('href="/download/#server"');
  });

  it('stránky návod vkládají a vlastní instalační příkazy serveru nemají', () => {
    for (const page of [
      'components/home/Home.astro',
      'pages/download.astro',
      'pages/docs.astro',
      'pages/cs/download.astro',
      'pages/cs/docs.astro',
    ]) {
      const text = read(`${SITE}${page}`);
      expect(text, page).toMatch(/<InstallServer\b/);
      expect(text, page).not.toMatch(/php -q|mysql -u|cp config\.sample\.php|define\('DB_(HOST|NAME|USER|PASS)'/);
    }
    // The home page's words (i18n) carry no second copy of the commands either.
    for (const dict of Object.values(dicts)) {
      expect(JSON.stringify(dict.home), dict.lang).not.toMatch(/php -q|mysql -u|config\.sample\.php|git clone/);
    }
  });

  it('co návod tvrdí o serveru, v kódu serveru opravdu je', () => {
    const sample = read(`${STATUS}config.sample.php`);
    for (const key of ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS']) {
      expect(sample).toContain(`define('${key}'`);
    }
    // The optional keys the guide names, read upper-cased by get_setting().
    expect(read(`${STATUS}db.php`)).toMatch(/\$const_name = strtoupper\(\$key\)/);
    const php = ['db.php', 'api.php', 'cron.php', 'agent_api.php', 'metrics.php'].map((f) => read(`${STATUS}${f}`));
    for (const key of ['cron_key', 'agent_registration_token', 'metrics_token']) {
      expect(
        php.some((src) => src.includes(`get_setting('${key}'`)),
        key
      ).toBe(true);
    }
    // No seeded account: the first one is created at /app/setup.
    expect(read(`${STATUS}schema.sql`)).not.toMatch(/INSERT INTO `?users`?/i);
    // The installer the guide sends people to: every step it names exists,
    // it locks once an account exists, and it gives a new install a cron key.
    const installer = read(`${STATUS}installer.php`) + read(`${STATUS}api.php`);
    for (const action of [
      'install_status',
      'install_test_db',
      'install_write_config',
      'install_import_schema',
      'install_cron',
    ]) {
      expect(installer, action).toContain(`'${action}'`);
    }
    expect(installer).toContain('installer_locked');
    expect(installer).toMatch(/function bk_install_cron_key/);
    // The check step reads these two fields.
    const api = read(`${STATUS}api.php`);
    expect(api).toContain("$action === 'collection_health'");
    expect(api).toMatch(/'lastRunAt' =>/);
    expect(api).toMatch(/'stale' =>/);
  });

  it('co návod tvrdí o instalátoru v aplikaci, aplikace opravdu umí', () => {
    // "It shows the cron line" and "it waits for the first collector run and
    // turns green" are the app's /app/setup, and MEASURED_INSTALL_MINUTES was
    // timed on that flow. Without them the guide would overstate.
    const pages = `${MONITOR}pages/`;
    const setup = readdirSync(pages)
      .filter((name) => /^setup.*\.tsx?$/.test(name) && !name.includes('.test.'))
      .map((name) => read(`${pages}${name}`))
      .join('\n');
    expect(existsSync(`${pages}setup.tsx`)).toBe(true);
    expect(setup).toMatch(/install_cron/);
    expect(setup).toMatch(/collection_health/);
    expect(setup).toMatch(/install_write_config/);
  });
});
