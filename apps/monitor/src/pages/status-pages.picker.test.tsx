// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { StatusPagesPage } from './status-pages';

/**
 * R4: the public page lists the public set only (scope=public). The editor
 * offered every monitor, so a page of two VPS (private by default) showed no
 * service and read "all online" while both machines were down.
 */
vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true, user: { role: 'admin' } }, loading: false, error: null }),
}));

const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const monitor = (id: number, name: string, type: string, isPublic: boolean | undefined) => ({
  id,
  name,
  type,
  target: 'example.com',
  status: 'up',
  isPublic,
});

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=status_pages'))
        return json({
          pages: [{ id: 1, title: 'Servery', slug: 'servery', description: null, isPublic: true, monitorIds: [3] }],
        });
      if (url.includes('action=public_subscribers')) return json({ subscribers: [] });
      if (url.includes('action=monitors'))
        return json({
          monitors: [
            monitor(1, 'E-shop', 'web', true),
            monitor(2, 'VPS Praha', 'vps', false),
            monitor(3, 'VPS Brno', 'vps', false),
            monitor(4, 'Starý server', 'web', undefined),
          ],
        });
      return json({});
    })
  );
});
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Editor status stránky nabízí jen to, co veřejná stránka ukáže (R4)', () => {
  it('neveřejný monitor nejde vybrat a je označený; už vybraný jde odškrtnout; nápověda říká „veřejné“', async () => {
    render(
      <LanguageProvider>
        <MemoryRouter>
          <StatusPagesPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Upravit' }));

    const pick = (name: RegExp) => screen.getByRole('checkbox', { name }) as HTMLInputElement;
    await screen.findByRole('checkbox', { name: /VPS Praha/ });
    expect(pick(/E-shop/).disabled).toBe(false);
    expect(pick(/VPS Praha/).disabled).toBe(true);
    // Already on the page: ticked and still changeable, so it can be removed.
    expect(pick(/VPS Brno/).checked).toBe(true);
    expect(pick(/VPS Brno/).disabled).toBe(false);
    // An older server without isPublic: nothing is known, nothing is refused.
    expect(pick(/Starý server/).disabled).toBe(false);
    expect(screen.getAllByText('neveřejný · vps')).toHaveLength(2);
    expect(screen.getByText(/stránka ukáže všechny veřejné monitory/)).toBeTruthy();

    fireEvent.click(pick(/VPS Brno/));
    expect(pick(/VPS Brno/).checked).toBe(false);
    expect(pick(/VPS Brno/).disabled).toBe(true);
  });
});
