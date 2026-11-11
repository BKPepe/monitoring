// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { StatusPagesPage } from './status-pages';

/**
 * R222: the subscribers panel vanished when its list failed to load, and a
 * delete ignored the server's answer, so a refused one looked done.
 */
vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true, user: { role: 'admin' } }, loading: false, error: null }),
}));

const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const SUB = { id: 5, email: 'navstevnik@example.com', lang: 'cs', confirmed: true, createdAt: '2026-11-01' };

function serve(list: () => Response, del: () => Response = () => json({ success: true })) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=status_pages')) return json({ pages: [] });
      if (url.includes('action=public_subscribers')) return list();
      if (url.includes('action=delete_public_subscriber')) return del();
      if (url.includes('action=monitors')) return json({ monitors: [] });
      return json({});
    })
  );
}

const renderPage = () =>
  render(
    <LanguageProvider>
      <MemoryRouter>
        <StatusPagesPage />
      </MemoryRouter>
    </LanguageProvider>
  );

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Veřejní odběratelé: chyba se řekne (R222)', () => {
  it('nenačtený seznam: panel zůstane s chybou a tlačítkem znovu, které ho načte', async () => {
    let fail = true;
    serve(() => (fail ? json({ error: 'DB' }, 500) : json({ subscribers: [SUB] })));
    renderPage();
    expect((await screen.findByRole('alert')).textContent).toContain('Seznam odběratelů se nepodařilo načíst.');
    fail = false;
    fireEvent.click(screen.getByRole('button', { name: 'Zkusit znovu' }));
    expect(await screen.findByText('navstevnik@example.com')).toBeTruthy();
    expect(screen.queryByText('Seznam odběratelů se nepodařilo načíst.')).toBeNull();
  });

  it('odmítnuté smazání: řekne se, a adresa v seznamu zůstane', async () => {
    serve(
      () => json({ subscribers: [SUB] }),
      () => json({ error: 'Nemáte oprávnění.' }, 403)
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Smazat odběratele' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Odběratele se nepodařilo smazat.');
    expect(screen.getByText('navstevnik@example.com')).toBeTruthy();
  });
});
