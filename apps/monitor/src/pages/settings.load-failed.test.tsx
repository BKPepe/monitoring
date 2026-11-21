// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { SettingsPage } from './settings';

/**
 * R220: after a failed load the form showed its defaults, and a save wrote
 * them over every switch the server held, the alert switches included.
 */
vi.mock('@/api/use-session', () => ({
  useSession: () => ({
    session: { authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' },
    loading: false,
    isAdmin: true,
  }),
  useLogout: () => ({ logout: () => {}, pending: false, failed: false }),
}));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Nastavení se nenačetlo (R220)', () => {
  it('chyba s opakováním místo formuláře, který by uložil výchozí hodnoty', async () => {
    let fail = true;
    const fetchMock = vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=get_settings'))
        return Promise.resolve(
          fail
            ? ({ ok: false, status: 500, json: () => Promise.resolve({ error: 'DB' }) } as Response)
            : ({ ok: true, status: 200, json: () => Promise.resolve({ settings: {}, envLocked: [] }) } as Response)
        );
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) } as Response);
    });
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal(
      'matchMedia',
      vi.fn(() => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }))
    );
    render(
      <LanguageProvider>
        <MemoryRouter>
          <SettingsPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    expect(await screen.findByText('Nepodařilo se načíst nastavení z API.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Uložit/ })).toBeNull();
    fail = false;
    fireEvent.click(screen.getByRole('button', { name: 'Zkusit znovu' }));
    expect(await screen.findByRole('button', { name: /Notifikace/ })).toBeTruthy();
    expect(fetchMock.mock.calls.some(([u]) => String(u).includes('action=save_settings'))).toBe(false);
  });
});
