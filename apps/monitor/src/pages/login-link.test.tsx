// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ProfilePage } from './profile';
import { UsersPage } from './users';

/**
 * D1: the sign-in is /app/setup. A session answer without loginUrl sent
 * Profile and Users to the legacy admin.php instead.
 */
const json = (body: unknown) =>
  Promise.resolve({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  } as Response);

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Přihlášení vede do /app, ne do admin.php', () => {
  it.each([
    ['Můj účet', <ProfilePage key="profile" />],
    ['Uživatelé', <UsersPage key="users" />],
  ])('%s bez loginUrl od serveru', async (_name, page) => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => json({ authenticated: false, user: null, csrfToken: null }))
    );
    render(
      <LanguageProvider>
        <MemoryRouter>{page}</MemoryRouter>
      </LanguageProvider>
    );
    const link = (await screen.findByText('Přejít na přihlášení')).closest('a') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('/app/setup');
  });
});
