// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { SettingsPage } from './settings';

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

async function openTab(name: RegExp) {
  render(
    <LanguageProvider>
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
  fireEvent.click(await screen.findByRole('button', { name }));
}

const row = (name: string) => screen.getByRole('button', { name: new RegExp(`^${name}`) }).closest('li') as HTMLElement;

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

describe('Nastavení: jazyk odchozích e-mailů (R101)', () => {
  it('jde vybrat, i když je SMTP pevně v config.php', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) =>
        Promise.resolve(
          String(input).includes('action=get_settings')
            ? json({ settings: { email_lang: 'cs' }, envLocked: ['smtp_host', 'smtp_user', 'smtp_pass'] })
            : json({})
        )
      )
    );
    await openTab(/Notifikace/);
    fireEvent.click(within(row('E-mail \\(SMTP\\)')).getAllByRole('button')[0]);
    expect(await screen.findByText(/SMTP je nastaveno pevně/)).toBeTruthy();
    expect(screen.getByLabelText(/Výchozí jazyk odchozích e-mailů/)).toBeTruthy();
  });
});
