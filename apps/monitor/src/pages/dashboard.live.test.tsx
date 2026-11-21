// @vitest-environment jsdom
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';
import { Header } from '@/components/layout/header';
import { ShellProvider } from '@/components/layout/shell-context';

/**
 * The dashboard's live cue is judged from the newest measurement in the
 * list and from whether the last refresh worked: a failed minute refresh
 * keeps the last good list on screen and turns the pill into a warning,
 * never a pulsing "Živě" over data nobody could refresh.
 *
 * The pill lives in the shell's header now (NetPulse shell): the page hands
 * it its freshness through usePageChrome, so the test renders the header
 * with the page, as the app does.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const NOW = Date.UTC(2026, 8, 25, 10, 0, 0);

let monitorsOk = true;
function api(input: RequestInfo | URL): Promise<Response> {
  const url = String(input);
  if (url.includes('action=monitors'))
    return Promise.resolve(
      monitorsOk
        ? json({
            monitors: [
              {
                id: 1,
                name: 'BloodKings.eu',
                type: 'web',
                status: 'up',
                target: 'https://bloodkings.eu',
                // The newest check wins: 40 s old, the older one does not decide.
                lastCheck: new Date(NOW - 40_000).toISOString(),
              },
              {
                id: 2,
                name: 'Donald',
                type: 'vps',
                status: 'up',
                target: 'donald',
                lastCheck: new Date(NOW - 900_000).toISOString(),
              },
            ],
          })
        : json({ error: 'database_unavailable' }, 500)
    );
  if (url.includes('action=dashboard_layout')) return Promise.resolve(json({ catalog: [], tiles: [] }));
  if (url.includes('action=public_status'))
    return Promise.resolve(
      json({ totalMonitors: 2, downMonitors: 0, uptimePercent: 100, avgLatencyMs: 50, nodes: [] })
    );
  if (url.includes('action=daily_uptime')) return Promise.resolve(json({ series: {} }));
  return Promise.resolve(json({}));
}

// The dashboard loads four panels lazily (UX wave 2). Their first import is a
// module transform, and inside the timed test on a busy machine it pushed the
// run past the 5 s limit; warming them here keeps the test about the page.
beforeAll(async () => {
  await Promise.all([
    import('@/components/findings-list'),
    import('@/components/uptime-heatmap'),
    import('@/components/regions-panel'),
    import('@/components/dashboard-layout-editor'),
    // The traffic card's chunk brings the chart library.
    import('./dashboard-traffic'),
  ]);
}, 30_000);

beforeEach(() => {
  monitorsOk = true;
  vi.useFakeTimers({ shouldAdvanceTime: true });
  vi.setSystemTime(NOW);
  vi.stubGlobal('fetch', vi.fn(api));
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
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Dashboard: pilulka čerstvosti (živě jen z časů měření)', () => {
  it('čerstvý seznam „Živě“ podle nejnovější kontroly; selhané obnovení = varování a seznam zůstane', async () => {
    const { container } = render(
      <LanguageProvider>
        <MemoryRouter>
          <ShellProvider>
            <Header />
            <DashboardPage />
          </ShellProvider>
        </MemoryRouter>
      </LanguageProvider>
    );
    const pill = () => container.querySelector('[data-slot="pill"][data-state]');
    await waitFor(() => expect(pill()?.getAttribute('data-state')).toBe('fresh'));
    expect(pill()?.textContent).toMatch(/Živě· 4\d s/);

    monitorsOk = false;
    await act(async () => {
      await vi.advanceTimersByTimeAsync(60_000);
    });
    await waitFor(() => expect(pill()?.getAttribute('data-state')).toBe('failed'));
    expect(pill()?.textContent).toContain('Obnovení selhalo');
    // The last good list is still there, and says it is stale.
    expect(screen.getAllByText('Donald').length).toBeGreaterThan(0);
    expect(screen.getAllByText(/Obnovení selhalo, data mohou být zastaralá/).length).toBeGreaterThan(0);
  });
});
