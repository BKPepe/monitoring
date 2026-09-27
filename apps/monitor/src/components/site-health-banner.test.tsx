// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { SiteHealthBanner } from './site-health-banner';

const mocks = vi.hoisted(() => ({ isAdmin: true }));
vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true }, loading: false, isAdmin: mocks.isAdmin }),
}));

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

  it('čistý config.php: mlčí', async () => {
    serve({ configOutput: null });
    renderBanner();
    await vi.waitFor(() => expect(urls.length).toBe(1));
    expect(screen.queryByRole('alert')).toBeNull();
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
