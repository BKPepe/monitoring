// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ProfilePage } from './profile';

/**
 * M2: linking an OAuth account starts in api.php (oauth_start&link=1), and the
 * server sends the browser back to the profile with the outcome in the query.
 * It used to start in the legacy admin.php and end there.
 */
const json = (body: unknown) =>
  Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  } as Response);

function stubApi(oauthProvider: string | null) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=session')) {
        return json({
          authenticated: true,
          user: { id: 1, username: 'admin', email: 'admin@example.com', role: 'admin' },
          csrfToken: 't',
          loginUrl: '',
        });
      }
      if (url.includes('action=my_profile')) {
        return json({
          username: 'admin',
          email: 'admin@example.com',
          phone: null,
          whatsappApikeySet: false,
          smsNotifications: false,
          whatsappNotifications: false,
          emailLang: null,
          totpEnabled: false,
          oauthProvider,
        });
      }
      if (url.includes('action=get_subscriptions')) return json({ subscriptions: [] });
      return json({});
    })
  );
}

function renderProfile() {
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
  window.history.pushState({}, '', '/');
});

describe('ProfilePage OAuth linking', () => {
  it('starts a link in api.php with link=1', async () => {
    stubApi(null);
    renderProfile();
    const github = (await screen.findByText(/Propojit GitHub/)).closest('a') as HTMLAnchorElement;
    const google = screen.getByText(/Propojit Google/).closest('a') as HTMLAnchorElement;
    expect(github.getAttribute('href')).toBe('/status/api.php?action=oauth_start&provider=github&link=1');
    expect(google.getAttribute('href')).toBe('/status/api.php?action=oauth_start&provider=google&link=1');
  });

  it('says the link worked when the server came back with oauth=linked', async () => {
    window.history.pushState({}, '', '/app/profile?oauth=linked&provider=github');
    stubApi('github');
    renderProfile();
    expect(await screen.findByText('Účet GitHub je propojený.')).toBeTruthy();
  });

  it('names why a link did not finish', async () => {
    window.history.pushState({}, '', '/app/profile?oauth_error=denied&provider=google');
    stubApi(null);
    renderProfile();
    expect(await screen.findByText('Přihlášení přes Google bylo u poskytovatele zrušeno.')).toBeTruthy();
  });
});
