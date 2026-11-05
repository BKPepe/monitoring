// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { appApi } from '@/api/app-api';
import { SettingsPage } from './settings';

/**
 * "Zjistit lokalitu znovu" sits inside the settings form. Without a type it was
 * a submit button: asking for the location also saved everything typed so far.
 */
const mocks = vi.hoisted(() => ({
  session: {
    session: { authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' },
    loading: false,
    isAdmin: true,
  },
}));

vi.mock('@/api/use-session', () => ({
  useSession: () => mocks.session,
  useLogout: () => ({ logout: () => {}, pending: false, failed: false }),
}));

const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

beforeEach(() => {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
      onchange: null,
    }))
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('Nastavení: zjištění lokality', () => {
  it('jen zjistí lokalitu a formulář neuloží', async () => {
    const calls: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        calls.push(url);
        if (url.includes('action=get_settings')) return Promise.resolve(json({ settings: {}, envLocked: [] }));
        return Promise.resolve(json({ success: true }));
      })
    );
    const redetect = vi.spyOn(appApi, 'redetectLocation').mockResolvedValue('Praha, CZ');
    render(
      <LanguageProvider>
        <MemoryRouter>
          <SettingsPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    fireEvent.click(await screen.findByRole('button', { name: /Zjistit lokalitu znovu/ }));
    await waitFor(() => expect(redetect).toHaveBeenCalled());
    await screen.findByText('Praha, CZ');
    expect(calls.filter((u) => u.includes('save_settings'))).toEqual([]);
  });
});
