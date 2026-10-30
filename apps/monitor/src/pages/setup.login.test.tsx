// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { SetupPage } from './setup';

/**
 * W1-H2, app half: no known default login.
 *
 * The repo is public, so a login form that arrives with "admin" filled in is
 * half of the published default. And the forgotten-password form reported
 * success on a 500 or with the server down, so nobody knew to try again.
 */
const json = (status: number, body: unknown) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

function serve(forgot: () => Promise<Response>) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=forgot_password')) return forgot();
      if (url.includes('action=session')) return Promise.resolve(json(200, { installed: true, authenticated: false }));
      return Promise.resolve(json(200, {}));
    })
  );
}

function renderLogin() {
  // The page applies the stored or the system theme; jsdom has neither
  // matchMedia nor, under this Node, a working localStorage.
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
  render(
    <LanguageProvider>
      <SetupPage />
    </LanguageProvider>
  );
}

async function askForReset() {
  // The form appears once install_status has answered (an older server's {} = installed).
  fireEvent.click(await screen.findByText('Zapomenuté heslo?'));
  fireEvent.change(screen.getByPlaceholderText('Váš e-mail'), { target: { value: 'nekdo@example.test' } });
  fireEvent.click(screen.getByRole('button', { name: 'Odeslat' }));
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Přihlášení bez známého výchozího účtu (W1-H2)', () => {
  it('pole uživatelského jména začíná prázdné, ne „admin“', async () => {
    serve(() => Promise.resolve(json(200, { success: true })));
    renderLogin();
    const name = (await screen.findByPlaceholderText('Např. admin')) as HTMLInputElement;
    expect(name.value).toBe('');
  });

  it('obnova hesla po 2xx ukáže obecné potvrzení, které neprozradí, zda účet existuje', async () => {
    serve(() => Promise.resolve(json(200, { success: true })));
    renderLogin();
    await askForReset();
    expect(await screen.findByText(/byla zpracována\. Pokud účet existuje/)).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('obnova hesla po chybě serveru hlásí chybu, ne úspěch', async () => {
    serve(() => Promise.resolve(json(500, { error: 'boom' })));
    renderLogin();
    await askForReset();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('HTTP 500');
    expect(screen.queryByText(/byla zpracována/)).toBeNull();
    // The form stays, so the reader can try again.
    expect(screen.getByRole('button', { name: 'Odeslat' })).toBeTruthy();
  });

  it('obnova hesla bez spojení se serverem hlásí chybu, ne „instrukce odeslány“', async () => {
    serve(() => Promise.reject(new TypeError('Failed to fetch')));
    renderLogin();
    await askForReset();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('server je nedostupný');
    expect(screen.queryByText(/odeslány instrukce|byla zpracována/)).toBeNull();
  });
});

/**
 * M2: OAuth starts in api.php and ends in /app. An account with 2FA comes
 * back to this page with the session waiting for its code (pending2fa), and
 * a round trip that did not sign in names its reason in the query.
 */
function serveSession(session: Record<string, unknown>, login2fa?: (body: unknown) => Promise<Response>) {
  const calls: { url: string; body: unknown }[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : null });
      if (url.includes('action=session'))
        return Promise.resolve(json(200, { installed: true, authenticated: false, ...session }));
      if (url.includes('action=login_2fa') && login2fa) return login2fa(calls[calls.length - 1].body);
      return Promise.resolve(json(200, {}));
    })
  );
  return calls;
}

describe('OAuth a čekající 2FA na přihlašovací stránce', () => {
  afterEach(() => window.history.pushState({}, '', '/'));

  it('tlačítka poskytovatelů vedou na api.php, ne na admin.php', async () => {
    serveSession({ oauthProviders: ['github', 'gitlab'] });
    renderLogin();
    const github = (await screen.findByText('Přihlásit se přes GitHub')).closest('a') as HTMLAnchorElement;
    const gitlab = screen.getByText('Přihlásit se přes GitLab').closest('a') as HTMLAnchorElement;
    expect(github.getAttribute('href')).toBe('/status/api.php?action=oauth_start&provider=github');
    expect(gitlab.getAttribute('href')).toBe('/status/api.php?action=oauth_start&provider=gitlab');
  });

  it('čekající přihlášení chce jen kód a pošle ho na login_2fa', async () => {
    const calls = serveSession({ pending2fa: true, oauthProviders: [] }, () =>
      Promise.resolve(json(200, { success: true, authenticated: true, recoveryCodesRemaining: null }))
    );
    renderLogin();
    expect(await screen.findByText(/Přihlášení čeká na kód dvoufázového ověření/)).toBeTruthy();
    expect(screen.queryByPlaceholderText('Zadejte heslo')).toBeNull();
    const code = screen.getByPlaceholderText('123456') as HTMLInputElement;
    // A recovery code has letters: no numeric keypad.
    expect(code.getAttribute('inputmode')).toBeNull();
    fireEvent.change(code, { target: { value: 'abcde-12345' } });
    fireEvent.click(screen.getByRole('button', { name: 'Potvrdit 2FA kód a přihlásit se' }));
    expect(await screen.findByText(/Přihlášení úspěšné/)).toBeTruthy();
    const sent = calls.find((c) => c.url.includes('action=login_2fa'));
    expect(sent?.body).toEqual({ totp_code: 'abcde-12345' });
  });

  it('špatný kód nechá krok s kódem a řekne, co server odpověděl', async () => {
    serveSession({ pending2fa: true }, () =>
      Promise.resolve(json(401, { success: false, message: 'Invalid 2FA code.' }))
    );
    renderLogin();
    fireEvent.change(await screen.findByPlaceholderText('123456'), { target: { value: '000000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Potvrdit 2FA kód a přihlásit se' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Invalid 2FA code.');
    expect(screen.queryByText(/Přihlášení úspěšné/)).toBeNull();
    expect(screen.getByPlaceholderText('123456')).toBeTruthy();
  });

  it('„Přihlásit se jinak“ vrátí formulář se jménem a heslem', async () => {
    serveSession({ pending2fa: true });
    renderLogin();
    fireEvent.click(await screen.findByText('Přihlásit se jinak'));
    expect(screen.getByPlaceholderText('Zadejte heslo')).toBeTruthy();
  });

  it('OAuth, které nepřihlásilo, řekne proč', async () => {
    window.history.pushState({}, '', '/app/setup?oauth_error=not_linked&provider=github');
    serveSession({ oauthProviders: ['github'] });
    renderLogin();
    expect((await screen.findByRole('alert')).textContent).toContain(
      'Tenhle účet GitHub není propojený s žádným uživatelem'
    );
  });
});
