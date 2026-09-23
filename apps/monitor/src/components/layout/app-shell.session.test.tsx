// @vitest-environment jsdom
import type * as React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';

/**
 * W1-A7: a session call that fails is not a logout.
 *
 * The session store is module state shared by every useSession(), so each
 * test imports a fresh copy of the app (vi.resetModules) - otherwise the
 * answer one test got would be the answer the next one starts from.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const SIGNED_IN = { authenticated: true, user: { id: 1, username: 'pepe', role: 'admin' }, csrfToken: 't' };
const SIGNED_OUT = { authenticated: false, user: null, csrfToken: null, loginUrl: '/app/setup' };

let sessionAnswer: () => Promise<Response>;

// Every test imports a fresh app (vi.resetModules), so every first render is
// cold. Under the full parallel suite that took longer than findBy's 1 s
// default while the answer was right; the slow case is load, not a bug.
const FIRST_RENDER = { timeout: 5000 };

beforeEach(() => {
  vi.resetModules();
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
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
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=session')) return sessionAnswer();
      if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: [] }));
      if (url.includes('action=incidents')) return Promise.resolve(json({ incidents: [], manualIncidents: [] }));
      if (url.includes('action=public_status'))
        return Promise.resolve(json({ totalMonitors: 0, uptimePercent: null, avgLatencyMs: null, nodes: [] }));
      return Promise.resolve(json({}));
    })
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

async function renderShell() {
  const { createMemoryRouter, RouterProvider } = await import('react-router');
  const { LanguageProvider } = await import('@/context/language-context');
  const { AppShell } = await import('./app-shell');
  // A data router, as in production (routes.tsx createBrowserRouter): the
  // shell reads the route handles (useMatches) to tell an unknown address
  // from a page that needs a login (W1-F5).
  const router = createMemoryRouter(
    [
      { path: '/', element: <AppShell />, children: [{ index: true, element: <p>OBSAH APLIKACE</p> }] },
      { path: '/setup', element: <p>PŘIHLAŠOVACÍ FORMULÁŘ</p> },
    ],
    { initialEntries: ['/'] }
  );
  const tree: React.ReactElement = (
    <LanguageProvider>
      <RouterProvider router={router} />
    </LanguageProvider>
  );
  return render(tree);
}

describe('Selhané ověření relace není odhlášení (W1-A7)', () => {
  it('session vrací 503: „Služba je dočasně nedostupná“, žádný přihlašovací formulář', async () => {
    sessionAnswer = () => Promise.resolve(json({ error: 'database_unavailable' }, 503));
    await renderShell();

    expect(await screen.findByText('Služba je dočasně nedostupná', {}, FIRST_RENDER)).toBeTruthy();
    expect(screen.queryByText('PŘIHLAŠOVACÍ FORMULÁŘ')).toBeNull();
  });

  it('výpadek sítě i odpověď, která není JSON API: také nedostupnost, ne odhlášení', async () => {
    sessionAnswer = () => Promise.reject(new TypeError('Failed to fetch'));
    await renderShell();
    expect(await screen.findByText('Služba je dočasně nedostupná', {}, FIRST_RENDER)).toBeTruthy();
    cleanup();

    vi.resetModules();
    sessionAnswer = () =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.reject(new SyntaxError('<html>')) } as Response);
    await renderShell();
    expect(await screen.findByText('Služba je dočasně nedostupná', {}, FIRST_RENDER)).toBeTruthy();
    expect(screen.queryByText('PŘIHLAŠOVACÍ FORMULÁŘ')).toBeNull();
  });

  it('selhání se neukládá: „Zkusit znovu“ se zeptá znovu a pustí dovnitř', async () => {
    sessionAnswer = () => Promise.resolve(json({ error: 'x' }, 500));
    await renderShell();
    await screen.findByText('Služba je dočasně nedostupná', {}, FIRST_RENDER);

    sessionAnswer = () => Promise.resolve(json(SIGNED_IN));
    fireEvent.click(screen.getByRole('button', { name: 'Zkusit znovu' }));
    expect(await screen.findByText('OBSAH APLIKACE', {}, FIRST_RENDER)).toBeTruthy();
  });

  it('jen skutečné authenticated:false vede na přihlášení', async () => {
    sessionAnswer = () => Promise.resolve(json(SIGNED_OUT));
    await renderShell();
    expect(await screen.findByText('PŘIHLAŠOVACÍ FORMULÁŘ', {}, FIRST_RENDER)).toBeTruthy();
  });

  it('401 z action=session je také odpověď „nepřihlášen“', async () => {
    sessionAnswer = () => Promise.resolve(json({ error: 'Unauthorized' }, 401));
    await renderShell();
    expect(await screen.findByText('PŘIHLAŠOVACÍ FORMULÁŘ', {}, FIRST_RENDER)).toBeTruthy();
  });

  it('smazaná relace v otevřené záložce: při dalším zaměření okna přihlášení', async () => {
    sessionAnswer = () => Promise.resolve(json(SIGNED_IN));
    await renderShell();
    await screen.findByText('OBSAH APLIKACE', {}, FIRST_RENDER);

    sessionAnswer = () => Promise.resolve(json(SIGNED_OUT));
    await act(async () => {
      window.dispatchEvent(new Event('focus'));
    });
    expect(await screen.findByText('PŘIHLAŠOVACÍ FORMULÁŘ', {}, FIRST_RENDER)).toBeTruthy();
  });

  it('zaměření okna při výpadku API nechá přihlášeného uvnitř', async () => {
    sessionAnswer = () => Promise.resolve(json(SIGNED_IN));
    await renderShell();
    await screen.findByText('OBSAH APLIKACE', {}, FIRST_RENDER);

    sessionAnswer = () => Promise.resolve(json({ error: 'database_unavailable' }, 503));
    await act(async () => {
      window.dispatchEvent(new Event('focus'));
    });
    expect(screen.getByText('OBSAH APLIKACE')).toBeTruthy();
    expect(screen.queryByText('PŘIHLAŠOVACÍ FORMULÁŘ')).toBeNull();
  });
});

describe('Kdy se relace ověřuje znovu (W1-A7)', () => {
  it('401 z libovolného volání api.php požádá o nové ověření, 401 z přihlášení ne', async () => {
    const { installCsrfFetch } = await import('@/api/csrf-fetch');
    const { onSessionRecheck } = await import('@/api/session-recheck');
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json({ error: 'Unauthorized' }, 401)))
    );
    installCsrfFetch();
    const heard = vi.fn();
    onSessionRecheck(heard);

    await fetch('/status/api.php?action=login', { method: 'GET' });
    expect(heard).not.toHaveBeenCalled();
    await fetch('/status/api.php?action=monitors');
    expect(heard).toHaveBeenCalledTimes(1);
  });

  it('seznam monitorů bez jediného cíle (veřejný pohled) v přihlášené aplikaci požádá o nové ověření', async () => {
    const { appApi } = await import('@/api/app-api');
    const { onSessionRecheck } = await import('@/api/session-recheck');
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json({ monitors: [{ id: 1, name: 'E-shop', target: null }] })))
    );
    const heard = vi.fn();
    onSessionRecheck(heard);

    await appApi.getMonitors();
    expect(heard).toHaveBeenCalledTimes(1);
  });
});
