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
  render(
    <LanguageProvider>
      <SetupPage />
    </LanguageProvider>
  );
}

async function askForReset() {
  fireEvent.click(screen.getByText('Zapomenuté heslo?'));
  fireEvent.change(screen.getByPlaceholderText('Váš e-mail'), { target: { value: 'nekdo@example.test' } });
  fireEvent.click(screen.getByRole('button', { name: 'Odeslat' }));
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Přihlášení bez známého výchozího účtu (W1-H2)', () => {
  it('pole uživatelského jména začíná prázdné, ne „admin“', () => {
    serve(() => Promise.resolve(json(200, { success: true })));
    renderLogin();
    const name = screen.getByPlaceholderText('Např. admin') as HTMLInputElement;
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
