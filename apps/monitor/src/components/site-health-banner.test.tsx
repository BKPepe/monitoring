// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import type { SelfCheck } from '@/api/types';
import { SiteHealthBanner } from './site-health-banner';

const mocks = vi.hoisted(() => ({ isAdmin: true }));
vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true }, loading: false, isAdmin: mocks.isAdmin }),
}));

const selfCheck = (over: Partial<SelfCheck> = {}): SelfCheck => ({
  state: 'failed',
  url: 'https://example.com/status/api.php?action=public_status',
  checkedAt: '2026-09-27T18:10:00+02:00',
  failures: 3,
  since: '2026-09-27T18:00:00+02:00',
  reason: '2 bytes before the JSON: "Ah"',
  lastOkAt: '2026-09-27T17:55:00+02:00',
  alertAttemptAt: '2026-09-27T18:05:00+02:00',
  alertResult: 'failed',
  ...over,
});

let urls: string[] = [];
function serve(body: unknown, status = 200) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      urls.push(String(input));
      return Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) } as Response);
    })
  );
}

function renderBanner() {
  return render(
    <LanguageProvider>
      <SiteHealthBanner />
    </LanguageProvider>
  );
}

beforeEach(() => {
  urls = [];
  mocks.isAdmin = true;
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('SiteHealthBanner', () => {
  it('řekne, kolik bajtů config.php vypisuje, kde a jak začínají', async () => {
    // 27 Sep 2026: "Ah" before <?php, and nothing in /app said why nothing loaded.
    serve({ configOutput: { bytes: 2, where: ['before_open_tag'], excerpt: 'Ah' } });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-down');
    expect(banner.textContent).toContain('config.php vypisuje 2 B (před <?php): opravte soubor');
    expect(banner.textContent).toContain('Začíná takhle: Ah');
    expect(urls).toEqual(['/status/api.php?action=site_health']);
  });

  it('pojmenuje i text za ?> a výstup z kódu souboru', async () => {
    serve({ configOutput: { bytes: 40, where: ['after_close_tag', 'inside'], excerpt: '\\nWarning: Const…' } });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.textContent).toContain('(za ?>, z kódu souboru)');
    expect(banner.textContent).toContain('\\nWarning: Const…');
  });

  it('čistý config.php a procházející kontrola API: mlčí', async () => {
    serve({ configOutput: null, selfCheck: selfCheck({ state: 'ok', failures: 0, since: null, reason: null }) });
    renderBanner();
    await vi.waitFor(() => expect(urls.length).toBe(1));
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('selhávající kontrola vlastního API: červeně, proč, od kdy a jestli upozornění někam došlo', async () => {
    serve({ configOutput: null, selfCheck: selfCheck() });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-down');
    expect(banner.textContent).toContain('Samokontrola webu selhala');
    expect(banner.textContent).toContain('2 bytes before the JSON: "Ah"');
    expect(banner.textContent).toContain('3× za sebou');
    expect(banner.textContent).toContain('Upozornění žádný kanál nepřevzal');
    expect(banner.textContent).toContain('https://example.com/status/api.php?action=public_status');
  });

  it('první selhání: oranžově, zatím nepotvrzené, a upozornění teprve odejde', async () => {
    serve({ configOutput: null, selfCheck: selfCheck({ failures: 1, alertResult: null, alertAttemptAt: null }) });
    renderBanner();
    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-warning');
    expect(banner.className).not.toContain('border-down');
    expect(banner.textContent).toContain('Upozornění odejde po druhé neúspěšné kontrole');
  });

  it('návrat selhání po ohlášeném: červeně, i když je za sebou teprve jedno', async () => {
    serve({ configOutput: null, selfCheck: selfCheck({ failures: 1, alertResult: 'sent' }) });
    renderBanner();
    expect((await screen.findByRole('alert')).className).toContain('border-down');
  });

  it('zotavuje se po ohlášeném selhání: mlčí, poslední kontrola prošla', async () => {
    serve({ configOutput: null, selfCheck: selfCheck({ state: 'recovering', failures: 0, alertResult: 'sent' }) });
    renderBanner();
    await vi.waitFor(() => expect(urls.length).toBe(1));
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('bez adresy webu kontrola neběží, a to se řekne', async () => {
    serve({ configOutput: null, selfCheck: selfCheck({ state: 'unconfigured', url: null, failures: 0 }) });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-warning');
    expect(banner.textContent).toContain('Kontrola vlastního API neběží');
    expect(banner.textContent).toContain('site_url');
  });

  it('nepovedená kontrola se ohlásí, nemlčí', async () => {
    serve({ error: 'x' }, 502);
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-warning');
    expect(banner.textContent).toContain('Kontrola serveru se nepovedla');
    expect(banner.textContent).toContain('HTTP 502');
  });

  it('účet bez role administrátora se ani neptá', () => {
    mocks.isAdmin = false;
    serve({ configOutput: { bytes: 2, where: ['before_open_tag'], excerpt: 'Ah' } });
    renderBanner();
    expect(urls).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
  });
});
