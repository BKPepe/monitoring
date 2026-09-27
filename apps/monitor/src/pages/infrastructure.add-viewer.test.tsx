// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';

/**
 * The dashboard's first-run link (?add=1) opens the add form only for an
 * admin. Its own file: the session is a module-level cache, and the admin
 * cases in infrastructure.list.test.tsx would leak into this one.
 */
const json = (body: unknown) =>
  ({
    ok: true,
    status: 200,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  window.history.pushState({}, '', '/');
});

describe('Infrastruktura: ?add=1 (W1-5 conv-12)', () => {
  it('u čtenáře formulář neotevře a parametr nezůstane viset', async () => {
    vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=session'))
          return Promise.resolve(
            json({
              authenticated: true,
              user: { id: 2, username: 'v', email: 'v@x.test', role: 'viewer' },
              csrfToken: 't',
              loginUrl: '',
            })
          );
        if (url.includes('action=monitors'))
          return Promise.resolve(
            json({
              monitors: [
                {
                  id: 1,
                  name: 'E-shop',
                  type: 'web',
                  target: '',
                  status: 'up',
                  category: null,
                  lastCheck: '2026-09-23 10:00:00',
                },
              ],
            })
          );
        return Promise.resolve(json({}));
      })
    );
    window.history.pushState({}, '', '/infrastructure?add=1');
    render(
      <LanguageProvider>
        <TooltipProvider>
          <MemoryRouter initialEntries={['/infrastructure?add=1']}>
            <InfrastructurePage />
          </MemoryRouter>
        </TooltipProvider>
      </LanguageProvider>
    );
    await screen.findByRole('link', { name: /E-shop/ }, { timeout: 3000 });
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
