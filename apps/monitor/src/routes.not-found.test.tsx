// @vitest-environment jsdom
import * as React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';

/**
 * W1-F5: an unknown address is "not found" for anyone, never the login form.
 * W1-F4: the error boundary tells a missing file of an old build (reload once)
 * from any other error (say what broke), and lets go of it on the next page.
 *
 * The session store is module state, so each test imports a fresh app.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const SIGNED_OUT = { authenticated: false, user: null, csrfToken: null, loginUrl: '/app/setup' };

/** One tab's sessionStorage; the reload guard of this build is already used. */
function storageWithReloadUsed() {
  const data = new Map<string, string>([['bk-stale-build-reload:0.0.0-test', '1']]);
  return {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
    removeItem: (k: string) => void data.delete(k),
  };
}

beforeEach(() => {
  vi.resetModules();
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal('sessionStorage', storageWithReloadUsed());
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=session')) return Promise.resolve(json(SIGNED_OUT));
      return Promise.resolve(json({}));
    })
  );
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

async function renderApp(path: string) {
  const { createMemoryRouter, RouterProvider } = await import('react-router');
  const { LanguageProvider } = await import('@/context/language-context');
  const { routes } = await import('./routes');
  const router = createMemoryRouter(routes, { initialEntries: [path] });
  render(
    <LanguageProvider>
      <RouterProvider router={router} />
    </LanguageProvider>
  );
  return router;
}

describe('Neznámá adresa bez přihlášení (W1-F5)', () => {
  it('/public/neco: „Stránka nenalezena“ s odkazem na stav služeb, žádné přihlášení', async () => {
    const router = await renderApp('/public/neexistuje');

    expect(await screen.findByRole('heading', { name: 'Stránka nenalezena' })).toBeTruthy();
    expect(screen.getByRole('link', { name: /Stav služeb/ }).getAttribute('href')).toBe('/public');
    expect(router.state.location.pathname).toBe('/public/neexistuje');
    // No login form and no prefilled "admin" anywhere.
    expect(screen.queryByLabelText(/Uživatelské jméno|Username/)).toBeNull();
    expect(document.body.innerHTML).not.toContain('admin');
    // Route-like addresses answer 200, so the page itself says "do not index".
    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe('noindex');
  });

  it('neznámá adresa v aplikaci: nepřihlášený vidí „nenalezeno“, ne přihlašovací formulář', async () => {
    const router = await renderApp('/tohle-neexistuje');

    expect(await screen.findByRole('heading', { name: 'Stránka nenalezena' })).toBeTruthy();
    expect(router.state.location.pathname).toBe('/tohle-neexistuje');
  });

  it('známá neveřejná stránka dál vede nepřihlášeného na přihlášení', async () => {
    const router = await renderApp('/infrastructure');

    await vi.waitFor(() => expect(router.state.location.pathname).toBe('/setup'));
    expect(router.state.location.search).toBe('?next=%2Finfrastructure');
  });
});

describe('Chybová obrazovka aplikace (W1-F4)', () => {
  async function renderBoundary(throwWith: () => Error) {
    const { MemoryRouter, Route, Routes, useNavigate } = await import('react-router');
    const { LanguageProvider } = await import('@/context/language-context');
    const { GlobalErrorBoundary } = await import('./routes');
    let go: (to: string) => void = () => {};
    function Nav() {
      go = useNavigate();
      return null;
    }
    function Broken(): React.ReactElement {
      throw throwWith();
    }
    render(
      <LanguageProvider>
        <MemoryRouter initialEntries={['/rozbita']}>
          <Nav />
          <GlobalErrorBoundary>
            <Routes>
              <Route path="/rozbita" element={<Broken />} />
              <Route path="/jina" element={<p>JINÁ STRÁNKA</p>} />
            </Routes>
          </GlobalErrorBoundary>
        </MemoryRouter>
      </LanguageProvider>
    );
    return (to: string) => act(() => go(to));
  }

  it('jiná chyba ukáže, co se stalo - ne „Platforma byla aktualizována“', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    await renderBoundary(() => new TypeError("Cannot read properties of undefined (reading 'map')"));

    expect(await screen.findByText('Stránku se nepodařilo zobrazit')).toBeTruthy();
    expect(screen.getByText("Cannot read properties of undefined (reading 'map')")).toBeTruthy();
    expect(screen.queryByText('Byla zjištěna aktualizace aplikace')).toBeNull();
  });

  it('chyba nezůstane viset: další stránka se zobrazí normálně', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    const navigate = await renderBoundary(() => new Error('rozbitá stránka'));
    await screen.findByText('Stránku se nepodařilo zobrazit');

    await navigate('/jina');
    expect(await screen.findByText('JINÁ STRÁNKA')).toBeTruthy();
    expect(screen.queryByText('Stránku se nepodařilo zobrazit')).toBeNull();
  });

  it('chybějící soubor starého buildu (Firefox i Safari): obrazovka aktualizace, když už se jednou obnovilo', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    await renderBoundary(() => new TypeError('error loading dynamically imported module: /app/assets/x-1.js'));
    expect(await screen.findByText('Byla zjištěna aktualizace aplikace')).toBeTruthy();
    cleanup();

    await renderBoundary(() => new TypeError('Importing a module script failed.'));
    expect(await screen.findByText('Byla zjištěna aktualizace aplikace')).toBeTruthy();
  });
});
