// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { UserMenu } from './user-menu';

/** Node 26 hides jsdom's storage behind a flag; browsers always have one. */
function memoryStorage(): Storage {
  const data = new Map<string, string>();
  return {
    get length() {
      return data.size;
    },
    clear: () => data.clear(),
    getItem: (key: string) => data.get(key) ?? null,
    key: (index: number) => [...data.keys()][index] ?? null,
    removeItem: (key: string) => {
      data.delete(key);
    },
    setItem: (key: string, value: string) => {
      data.set(key, String(value));
    },
  };
}

function renderMenu(collapsed = false) {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <UserMenu name="pepe" role="admin" collapsed={collapsed} isLoggedOut={false} />
      </MemoryRouter>
    </LanguageProvider>
  );
}

const signOutButton = (name: RegExp = /odhlásit se|sign out/i) =>
  screen.getByRole('button', { name }) as HTMLButtonElement;

const respond = (ok: boolean, status: number, body: unknown) =>
  vi.fn().mockResolvedValue({ ok, status, json: () => Promise.resolve(body) });

describe('UserMenu sign-out', () => {
  const originalLocation = window.location;
  const replace = vi.fn();
  const assign = vi.fn();

  beforeEach(() => {
    replace.mockReset();
    assign.mockReset();
    vi.stubGlobal('localStorage', memoryStorage());
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, replace, assign },
    });
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
  });

  it('ends the session on the server, tells other tabs, and replaces the page with the login', async () => {
    const fetchMock = respond(true, 200, { success: true });
    vi.stubGlobal('fetch', fetchMock);

    renderMenu();
    fireEvent.click(signOutButton());

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/app/setup'));
    expect(assign).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledWith(
      '/status/api.php?action=logout',
      expect.objectContaining({ method: 'POST', credentials: 'include' })
    );
    expect(window.localStorage.getItem('bk-signed-out')).toMatch(/^\d+$/);
  });

  it('stays on the page and says so when the server did not sign out', async () => {
    vi.stubGlobal('fetch', respond(false, 500, {}));

    renderMenu();
    fireEvent.click(signOutButton());

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toMatch(/nepodařilo|failed/i);
    expect(replace).not.toHaveBeenCalled();
    expect(window.localStorage.getItem('bk-signed-out')).toBeNull();
    expect(signOutButton().disabled).toBe(false);
  });

  it('does not take a 200 without the API confirmation as a sign-out', async () => {
    vi.stubGlobal('fetch', respond(true, 200, {}));

    renderMenu();
    fireEvent.click(signOutButton());

    await screen.findByRole('alert');
    expect(replace).not.toHaveBeenCalled();
  });

  it('is disabled and says it is signing out while the request runs', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => {}))
    );

    renderMenu();
    fireEvent.click(signOutButton());

    const busy = await waitFor(() => signOutButton(/odhlašuji|signing out/i));
    expect(busy.disabled).toBe(true);
  });

  it('in the collapsed rail a failure changes the icon and is tied to the button', async () => {
    vi.stubGlobal('fetch', respond(false, 500, {}));

    renderMenu(true);
    expect(signOutButton().querySelector('[data-icon="logout"]')).not.toBeNull();
    fireEvent.click(signOutButton());

    const alert = await screen.findByRole('alert');
    const button = signOutButton();
    expect(alert.textContent).toMatch(/nepodařilo|failed/i);
    expect(button.getAttribute('aria-describedby')).toBe(alert.id);
    expect(button.querySelector('[data-icon="logout-failed"]')).not.toBeNull();
    expect(button.querySelector('[data-icon="logout"]')).toBeNull();
  });

  it('keeps the profile link and the sign-out as two separate controls', () => {
    renderMenu();
    const link = screen.getByRole('link');
    expect(link.getAttribute('href')).toBe('/profile');
    expect(link.contains(signOutButton())).toBe(false);
  });
});
