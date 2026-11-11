import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * The site may only claim what the product does. i18n.test.ts guards the home
 * page's dictionary; this guards every page and component too. Each word
 * below once marked a claim the code never backed. A new occurrence has to
 * come with the feature, and then the word leaves this list.
 */
const UNBACKED = [
  /\bmacOS\b/i, // no macOS agent
  /\bSQLite\b/i, // the server stores in MySQL/MariaDB
  /\bEditor\b|\bRead-only role/i, // two roles: admin and user
  /dual-stack/i,
  /\bLTS\b|\b2 years\b|\b2 roky\b|dva roky/i, // no long-term support promise exists
  /100 ?% open[- ]source/i, // R317: the bundled PHPMailer is LGPL-2.1, not MIT
  /isolated cach|čtou z cache/i, // R312: the public status answer is not served from a cache of its own
  /target, code, and response latency/i, // R312: an alert carries name, state, time and error
];

/**
 * Traces of the old install paths: a server image that was never published,
 * timings nobody measured, a systemd unit no agent installs, an invented
 * first release, and commands that download from or register against the
 * author's own server. The one server guide is InstallServer.astro, the
 * agent steps come from the app's agent-install.ts.
 */
const OLD_INSTALL = [
  /bkpepe\/monitoring:latest/i,
  /60 seconds|60 sekund|under two minutes|in seconds/i,
  /journalctl|systemd/,
  /v0\.1\.0-alpha[^"]*(released|vydán)|Initial Alpha Release/i,
  /bloodkings\.eu\/status/,
];

/** ICMP and UDP only where the site says it has none. */
const PROBES = /\bICMP\b|\bUDP\b/;
const NEGATED = /\bno\b|\bnot\b|žádný|ani|neumí/i;

const SRC = fileURLToPath(new URL('..', import.meta.url));
const REPO = fileURLToPath(new URL('../../../../', import.meta.url));

function sources(dir: string): string[] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) return sources(path);
    return /\.(astro|ts|js)$/.test(entry.name) && !entry.name.includes('.test.') ? [path] : [];
  });
}

/** Lines of code and copy; comments explain what was removed, so they may name it. */
function lines(text: string): { n: number; line: string }[] {
  return text
    .split('\n')
    .map((line, i) => ({ n: i + 1, line }))
    .filter(({ line }) => !/^\s*(\/\/|\*|\/\*|<!--)/.test(line));
}

describe('web tvrdí jen to, co produkt umí', () => {
  const files = sources(SRC).map((path) => ({ path: path.slice(SRC.length), text: readFileSync(path, 'utf8') }));
  const hits = (patterns: RegExp[]) =>
    files.flatMap((f) =>
      lines(f.text).flatMap(({ n, line }) =>
        patterns.some((re) => re.test(line)) ? [`${f.path}:${n}: ${line.trim()}`] : []
      )
    );

  it('prohledá stránky, komponenty i slovníky', () => {
    expect(files.some((f) => f.path.startsWith('pages/cs/'))).toBe(true);
    expect(files.some((f) => f.path.startsWith('components/'))).toBe(true);
    expect(files.some((f) => f.path.startsWith('i18n/'))).toBe(true);
  });

  it('žádné nepodložené schopnosti', () => {
    expect(hits(UNBACKED)).toEqual([]);
  });

  it('jediný návod k instalaci, bez stop starých cest', () => {
    expect(hits(OLD_INSTALL)).toEqual([]);
  });

  it('ICMP a UDP jen tam, kde web říká, že je nemá', () => {
    const probes = files.flatMap((f) =>
      lines(f.text).flatMap(({ n, line }) =>
        PROBES.test(line) && !NEGATED.test(line) ? [`${f.path}:${n}: ${line.trim()}`] : []
      )
    );
    expect(probes).toEqual([]);
  });

  it('licence MIT, kterou web uvádí, v repozitáři opravdu je', () => {
    expect(files.some((f) => /\bMIT\b/.test(f.text))).toBe(true);
    const license = join(REPO, 'LICENSE');
    expect(existsSync(license)).toBe(true);
    expect(readFileSync(license, 'utf8')).toMatch(/^MIT License/);
  });

  // R314: only agent.sh and the OpenWrt agent register with a token and run
  // from cron; Windows runs a scheduled task and Docker a container.
  it('registraci tokenem slibuje jen Linuxu a OpenWrt', () => {
    const claims = files
      .filter((f) => f.path.endsWith('features.astro'))
      .flatMap((f) => lines(f.text).filter(({ line }) => /token[^.;]*(Windows|Docker)/i.test(line)));
    expect(claims).toEqual([]);
  });

  // R317: the pages called it all open source under MIT while the bundled
  // PHPMailer is LGPL-2.1. Where the download page names the licence, it
  // names the third-party notices too.
  it('stránka ke stažení zmíní licence třetích stran', () => {
    for (const page of ['pages/download.astro', 'pages/cs/download.astro']) {
      const text = files.find((f) => f.path === page)?.text ?? '';
      expect(text).toContain('THIRD_PARTY_NOTICES.md');
      expect(text).toContain('LGPL-2.1');
    }
    expect(existsSync(join(REPO, 'THIRD_PARTY_NOTICES.md'))).toBe(true);
  });
});
