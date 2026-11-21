// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';

/**
 * The network's health ring reads the shared appApi.getHealth(): the
 * server's score, and a failed first answer is an error with a retry,
 * never a "—" ring (which would say the server had too little data).
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const MONITORS = [
  { id: 1, name: 'E-shop', type: 'web', status: 'up', target: 'https://example.test', category: 'Weby' },
];

const HEALTH = {
  network: { score: 80, grade: 'fair', formulaVersion: 1, components: [], deductions: [] },
  assets: [{ monitorId: 1, score: 92, grade: 'good', formulaVersion: 1, components: [] }],
};

function stubApi(health: () => Response) {
  const fetchMock = vi.fn((input: RequestInfo | URL): Promise<Response> => {
    const url = String(input);
    if (url.includes('action=health')) return Promise.resolve(health());
    if (url.includes('action=session'))
      return Promise.resolve(json({ authenticated: true, user: { id: 1, username: 'admin', role: 'admin' } }));
    if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: MONITORS }));
    if (url.includes('action=dashboard_layout')) return Promise.resolve(json({ catalog: [], tiles: [] }));
    if (url.includes('action=public_status'))
      return Promise.resolve(json({ totalMonitors: 1, downMonitors: 0, uptimePercent: 99.5, nodes: [] }));
    if (url.includes('action=daily_uptime')) return Promise.resolve(json({ series: {} }));
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
  return () => fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('action=health'));
}

let clock = Date.UTC(2026, 8, 23, 8, 0, 0);
beforeEach(() => {
  // usePublicStatus shares answers for ten seconds at module level.
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
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
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    }
  );
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

describe('Dashboard: zdraví sítě ze sdíleného appApi.getHealth()', () => {
  it('prstenec sítě je skóre ze serveru, jedna žádost s jazykem stránky', async () => {
    const healthUrls = stubApi(() => json(HEALTH));
    renderPage();

    expect(await screen.findByRole('img', { name: 'Zdraví sítě: 80 ze 100, Ucházející' })).toBeTruthy();
    expect(healthUrls()).toEqual(['/status/api.php?action=health&lang=cs']);
  });

  it('selhaná první odpověď je chyba s opakováním, ne prstenec s pomlčkou', async () => {
    let healthy = false;
    stubApi(() => (healthy ? json(HEALTH) : json({ error: 'health_unavailable' }, 500)));
    renderPage();

    const message = await screen.findByText('Skóre zdraví se nepodařilo načíst.');
    expect(screen.queryByRole('img', { name: /Zdraví sítě/ })).toBeNull();

    healthy = true;
    const alert = message.closest('[role="alert"]') as HTMLElement;
    fireEvent.click(within(alert).getByRole('button', { name: 'Zkusit znovu' }));
    expect(await screen.findByRole('img', { name: 'Zdraví sítě: 80 ze 100, Ucházející' })).toBeTruthy();
  });
});
