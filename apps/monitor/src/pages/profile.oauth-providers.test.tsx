// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';

/**
 * R118: the profile offered GitHub and Google on every install, and a link to
 * one without a client ended on an error; Discord and GitLab were never
 * offered. It now offers what the session says the server has.
 */
const mocks = vi.hoisted(() => ({ providers: [] as string[] }));
vi.mock('@/api/use-session', () => ({
  useSession: () => ({
    session: {
      authenticated: true,
      user: { id: 1, username: 'admin', email: 'admin@example.com', role: 'admin' },
      csrfToken: 't',
      loginUrl: '',
      oauthProviders: mocks.providers,
    },
    loading: false,
    error: null,
    isAdmin: true,
    refetchSession: () => {},
  }),
  useLogout: () => ({ logout: () => {}, pending: false, failed: false }),
}));

import { ProfilePage } from './profile';

const json = (body: unknown) =>
  Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) } as Response);

function renderProfile(providers: string[]) {
  mocks.providers = providers;
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=my_profile'))
        return json({
          username: 'admin',
          email: 'admin@example.com',
          phone: null,
          whatsappApikeySet: false,
          smsNotifications: false,
          whatsappNotifications: false,
          emailLang: null,
          totpEnabled: false,
          oauthProvider: null,
        });
      if (url.includes('action=get_subscriptions')) return json({ subscriptions: [] });
      return json({});
    })
  );
  render(
    <LanguageProvider>
      <MemoryRouter>
        <ProfilePage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Profil: propojení jen s nastavenými poskytovateli (R118)', () => {
  it('nabídne jen poskytovatele, pro které má server klienta', async () => {
    renderProfile(['discord', 'gitlab']);
    expect(await screen.findByRole('button', { name: /Propojit Discord/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Propojit GitLab/ })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Propojit GitHub/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Propojit Google/ })).toBeNull();
  });

  it('bez nastaveného poskytovatele to řekne a tlačítko nenabídne', async () => {
    renderProfile([]);
    expect(await screen.findByText(/není přihlášení přes externí účet nastavené/)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Propojit/ })).toBeNull();
  });
});
