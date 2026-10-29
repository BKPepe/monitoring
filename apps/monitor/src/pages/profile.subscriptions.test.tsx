// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ProfilePage } from './profile';

/**
 * A monitor without a stored subscription follows the profile's WhatsApp
 * switch. The subscription card on the same page loaded once, so after a
 * profile save it kept showing WhatsApp alerts the account no longer got.
 */
const json = (body: unknown) =>
  Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  } as Response);

/** The server: the card's WhatsApp value follows the stored profile switch. */
function stubApi() {
  let whatsapp = true;
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
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
          whatsappNotifications: whatsapp,
          emailLang: null,
          totpEnabled: false,
          oauthProvider: null,
        });
      }
      if (url.includes('action=update_profile')) {
        whatsapp = JSON.parse(String(init?.body)).whatsappNotifications;
        return json({ success: true });
      }
      if (url.includes('action=get_subscriptions')) {
        return json({ subscriptions: [{ id: 1, name: 'Web', email: 1, sms: 0, whatsapp: whatsapp ? 1 : 0 }] });
      }
      return json({});
    })
  );
}

/** The WhatsApp box of the card's only monitor. */
async function whatsappBox() {
  const row = (await screen.findByText('Web', {}, { timeout: 3000 })).parentElement as HTMLElement;
  return row.querySelectorAll('input[type="checkbox"]')[2] as HTMLInputElement;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('ProfilePage subscriptions', () => {
  it('shows the WhatsApp boxes of the switch the profile save stored', async () => {
    stubApi();
    render(
      <LanguageProvider>
        <MemoryRouter>
          <ProfilePage />
        </MemoryRouter>
      </LanguageProvider>
    );
    expect((await whatsappBox()).checked).toBe(true);

    fireEvent.click(screen.getByLabelText('Posílat WhatsApp notifikace'));
    fireEvent.click(screen.getByRole('button', { name: 'Uložit profil' }));
    await screen.findByText('Profil uložen.');
    await waitFor(async () => expect((await whatsappBox()).checked).toBe(false));
  });
});
