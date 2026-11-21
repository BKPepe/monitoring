// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import type { NotificationProblem } from '@/api/types';
import { NotificationHealthBanner } from './notification-health-banner';

const mocks = vi.hoisted(() => ({ isAdmin: true }));
vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true }, loading: false, isAdmin: mocks.isAdmin }),
}));

const problem = (over: Partial<NotificationProblem> = {}): NotificationProblem => ({
  channel: 'whatsapp',
  recipient: '•••456',
  username: 'admin',
  state: 'failed',
  sinceIso: '2026-09-27T06:15:07+02:00',
  lastAtIso: '2026-09-27T09:25:07+02:00',
  count: 2,
  lastReason: 'CallMeBot 209: Quota exceeded or banned',
  lastSentAtIso: '2026-09-26T08:00:00+02:00',
  legacy: false,
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
      <MemoryRouter>
        <NotificationHealthBanner />
      </MemoryRouter>
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

describe('NotificationHealthBanner', () => {
  it('řekne, kterým kanálem a komu zprávy nedoráží, od kdy a proč', async () => {
    // The owner's day: WhatsApp refused, the e-mail went through an
    // unconfirmed mail(), and /app said nothing.
    serve({
      problems: [
        problem(),
        problem({
          channel: 'email',
          recipient: 'p…@example.com',
          state: 'unknown',
          count: 5,
          lastReason: "Handed to the hosting's mail(); nothing confirmed delivery.",
          lastSentAtIso: null,
        }),
      ],
      windowDays: 7,
      truncated: false,
    });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-down');
    expect(banner.textContent).toContain('Upozornění někomu nedoráží');
    expect(banner.textContent).toContain('whatsapp: Neodesláno');
    expect(banner.textContent).toContain('admin (•••456)');
    expect(banner.textContent).toContain('2× za sebou');
    expect(banner.textContent).toContain('CallMeBot 209: Quota exceeded or banned');
    expect(banner.textContent).toContain('Naposledy potvrzeno');
    expect(banner.textContent).toContain('E-mail: Nepotvrzeno');
    expect(banner.textContent).toContain('Za posledních 7 dní nic nepotvrzeno.');
    expect(screen.getByRole('link', { name: 'Otevřít odchozí zprávy' }).getAttribute('href')).toBe(
      '/outgoing-messages'
    );
  });

  it('jen nepotvrzené je jantarové, ne červené', async () => {
    serve({ problems: [problem({ state: 'unknown' })], windowDays: 7, truncated: false });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-warning');
    expect(banner.textContent).toContain('Doručení upozornění nikdo nepotvrdil');
  });

  it('víc než tři příjemce shrne do +N', async () => {
    serve({
      problems: ['•••111', '•••222', '•••333', '•••444', '•••555'].map((r) =>
        problem({ channel: 'sms', recipient: r, username: null })
      ),
      windowDays: 7,
      truncated: false,
    });
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.textContent).toContain('•••111, •••222, •••333 +2');
    expect(banner.textContent).not.toContain('•••444');
    // One line for the channel, not one per recipient.
    expect(banner.querySelectorAll('li')).toHaveLength(1);
  });

  it('když všechno doráží, mlčí', async () => {
    serve({ problems: [], windowDays: 7, truncated: false });
    renderBanner();
    await vi.waitFor(() => expect(urls.length).toBe(1));
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('nepovedený dotaz řekne nahlas, že se kontrola nepovedla', async () => {
    // Silence on a 500 looked exactly like "every channel delivers".
    serve({ error: 'DB' }, 500);
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.className).toContain('border-warning');
    expect(banner.textContent).toContain('Stav doručování zpráv nelze zjistit');
    expect(banner.textContent).toContain('HTTP 500');
  });

  it('odpověď, která není JSON, je taky nepovedená kontrola', async () => {
    // 27 Sep 2026: every PHP answer started with "Ah" from config.php.
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        urls.push(String(input));
        return Promise.resolve({
          ok: true,
          status: 200,
          json: () => Promise.reject(new SyntaxError('Unexpected token A')),
        } as Response);
      })
    );
    renderBanner();

    const banner = await screen.findByRole('alert');
    expect(banner.textContent).toContain('Stav doručování zpráv nelze zjistit');
    expect(banner.textContent).toContain('neodpověděl platným JSONem');
  });

  it('i výpadek sítě a odpověď jiného tvaru', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.reject(new TypeError('Failed to fetch')))
    );
    renderBanner();
    expect((await screen.findByRole('alert')).textContent).toContain('síť nebo server neodpovídá');
    cleanup();

    serve({ unexpected: true });
    renderBanner();
    expect((await screen.findByRole('alert')).textContent).toContain('něčím jiným, než kontrola čeká');
  });

  it('odhlášení nebo odebraná role mlčí: to řeší přihlášení, ne tahle kontrola', async () => {
    for (const status of [401, 403]) {
      urls = [];
      serve({ error: 'Přístup odepřen.' }, status);
      renderBanner();
      await vi.waitFor(() => expect(urls.length).toBe(1));
      expect(screen.queryByRole('alert')).toBeNull();
      cleanup();
    }
  });

  it('účet bez role administrátora se ani neptá', () => {
    mocks.isAdmin = false;
    serve({ problems: [problem()], windowDays: 7, truncated: false });
    renderBanner();
    expect(urls).toHaveLength(0);
    expect(screen.queryByRole('alert')).toBeNull();
  });
});
