// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ProfilePage } from './profile';

/**
 * Linking an OAuth account starts in api.php with the password, and the server
 * sends the browser back to the profile with the outcome in the query.
 */
const json = (body: unknown) =>
  Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  } as Response);

function stubApi(oauthProvider: string | null, oauthProviders: string[] = ['github', 'google']) {
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
          oauthProviders,
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
  const LINK_URL = 'https://github.com/login/oauth/authorize?state=abc';

  /** Opens the password step of the GitHub link; assign stands in for leaving the page. */
  async function openLink() {
    const assign = vi.fn();
    vi.stubGlobal('location', {
      search: '',
      pathname: '/app/profile',
      href: 'https://example.test/app/profile',
      assign,
    });
    renderProfile();
    fireEvent.click(await screen.findByRole('button', { name: /Propojit GitHub/ }));
    const password = (await screen.findByText('Propojení vyžaduje aktuální heslo.')).parentElement!.querySelector(
      'input[type="password"]'
    ) as HTMLInputElement;
    const submit = screen.getByRole('button', { name: /Propojit GitHub/ }) as HTMLButtonElement;
    return { assign, password, submit };
  }

  /** The API's fetch stub, with oauth_link_start answering `answer` and the calls recorded. */
  function stubLink(answer: { status: number; body: unknown }) {
    stubApi(null);
    const api = vi.mocked(fetch);
    const plain = api.getMockImplementation()!;
    const calls: { url: string; body: unknown }[] = [];
    api.mockImplementation((input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).includes('action=oauth_link_start')) {
        calls.push({ url: String(input), body: JSON.parse(String(init?.body)) });
        return Promise.resolve({
          ok: answer.status === 200,
          status: answer.status,
          json: () => Promise.resolve(answer.body),
        } as Response);
      }
      return plain(input, init);
    });
    return calls;
  }

  it('asks for the password and goes where the server says', async () => {
    const calls = stubLink({ status: 200, body: { url: LINK_URL } });
    const { assign, password, submit } = await openLink();
    expect(calls).toEqual([]);
    expect(submit.disabled).toBe(true);

    fireEvent.change(password, { target: { value: 'moje-heslo' } });
    fireEvent.click(submit);
    await waitFor(() => expect(assign).toHaveBeenCalledWith(LINK_URL));
    expect(calls).toEqual([
      {
        url: '/status/api.php?action=oauth_link_start',
        body: { provider: 'github', password: 'moje-heslo' },
      },
    ]);
  });

  it('stays on the page with the reason when the password is wrong', async () => {
    stubLink({ status: 400, body: { error: 'Nesprávné heslo - účet se nepropojí.' } });
    const { assign, password, submit } = await openLink();
    fireEvent.change(password, { target: { value: 'spatne' } });
    fireEvent.click(submit);
    await screen.findByText('Nesprávné heslo - účet se nepropojí.');
    expect(assign).not.toHaveBeenCalled();
    expect(submit.disabled).toBe(false);
  });

  it('forgets the password on cancel and offers both providers again', async () => {
    stubLink({ status: 200, body: { url: LINK_URL } });
    const { password } = await openLink();
    fireEvent.change(password, { target: { value: 'moje-heslo' } });
    fireEvent.click(screen.getByRole('button', { name: 'Zrušit' }));
    expect(screen.getByRole('button', { name: /Propojit Google/ })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /Propojit Google/ }));
    const again = (await screen.findByText('Propojení vyžaduje aktuální heslo.')).parentElement!.querySelector(
      'input[type="password"]'
    ) as HTMLInputElement;
    expect(again.value).toBe('');
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
