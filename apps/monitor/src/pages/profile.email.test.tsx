// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ProfilePage } from './profile';

/**
 * A password reset goes to the profile's e-mail, so the server wants the
 * current password to change it. The form says so before the save, instead of
 * leaving the person with a 400.
 */
const json = (body: unknown) =>
  Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  } as Response);

function stubApi() {
  const saves: Record<string, unknown>[] = [];
  let email = 'stary@example.com';
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes('action=session')) {
        return json({
          authenticated: true,
          user: { id: 1, username: 'admin', email, role: 'admin' },
          csrfToken: 't',
          loginUrl: '',
        });
      }
      if (url.includes('action=my_profile')) {
        return json({
          username: 'admin',
          email,
          phone: null,
          whatsappApikeySet: false,
          smsNotifications: false,
          whatsappNotifications: false,
          emailLang: null,
          totpEnabled: false,
          oauthProvider: null,
        });
      }
      if (url.includes('action=update_profile')) {
        const body = JSON.parse(String(init?.body));
        saves.push(body);
        email = body.email;
        return json({ success: true });
      }
      if (url.includes('action=get_subscriptions')) return json({ subscriptions: [] });
      return json({});
    })
  );
  return saves;
}

async function openForm() {
  render(
    <LanguageProvider>
      <MemoryRouter>
        <ProfilePage />
      </MemoryRouter>
    </LanguageProvider>
  );
  const email = (await screen.findByDisplayValue('stary@example.com', {}, { timeout: 3000 })) as HTMLInputElement;
  return { email, save: screen.getByRole('button', { name: 'Uložit profil' }) };
}

/** The field exists only while a change needs it. */
const currentPassword = () =>
  document.querySelector('input[autocomplete="current-password"]') as HTMLInputElement | null;

const HINT = 'Uložení nové adresy bude chtít stávající heslo.';

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('ProfilePage: změna e-mailu', () => {
  it('beze změny heslo nechce a profil se uloží', async () => {
    const saves = stubApi();
    const { save } = await openForm();
    expect(currentPassword()).toBeNull();
    expect(screen.queryByText(HINT)).toBeNull();

    fireEvent.click(save);
    await screen.findByText('Profil uložen.');
    expect(saves[0].email).toBe('stary@example.com');
  });

  it('se změnou řekne proč, bez hesla neodešle, s heslem ho pošle a pak ho zapomene', async () => {
    const saves = stubApi();
    const { email, save } = await openForm();
    fireEvent.change(email, { target: { value: 'novy@example.com' } });
    expect(screen.getByText(HINT)).toBeTruthy();
    const current = currentPassword() as HTMLInputElement;
    expect(current.required).toBe(true);

    fireEvent.click(save);
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(saves).toEqual([]);

    fireEvent.change(current, { target: { value: 'moje-heslo' } });
    fireEvent.click(save);
    await screen.findByText('Profil uložen.');
    expect(saves[0]).toMatchObject({ email: 'novy@example.com', oldPassword: 'moje-heslo' });
    await waitFor(() => expect(screen.queryByText(HINT)).toBeNull());
    expect(currentPassword()).toBeNull();
  });

  it('vrácení původní adresy heslo zase nechce a rozepsané zapomene', async () => {
    const saves = stubApi();
    const { email, save } = await openForm();
    fireEvent.change(email, { target: { value: 'novy@example.com' } });
    fireEvent.change(currentPassword() as HTMLInputElement, { target: { value: 'moje-heslo' } });
    fireEvent.change(email, { target: { value: 'stary@example.com' } });
    expect(currentPassword()).toBeNull();
    expect(screen.queryByText(HINT)).toBeNull();

    fireEvent.click(save);
    await screen.findByText('Profil uložen.');
    expect(saves[0].oldPassword).toBe('');
  });

  it('nové heslo si stávající řekne taky', async () => {
    const saves = stubApi();
    const { save } = await openForm();
    fireEvent.change(document.querySelector('input[autocomplete="new-password"]') as HTMLInputElement, {
      target: { value: 'nove-heslo-123' },
    });
    expect((currentPassword() as HTMLInputElement).required).toBe(true);
    fireEvent.click(save);
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(saves).toEqual([]);
  });
});
