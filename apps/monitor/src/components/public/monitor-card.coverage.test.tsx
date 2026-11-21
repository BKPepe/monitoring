// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicMonitorCard, type PublicMonitor } from './monitor-card';

/**
 * W1-B2 on the public page: a monitor younger than the 90-day window gets
 * one line saying from when its data runs, so "90 dní 100 %" over six weeks
 * does not read as ninety days.
 */
const MONITOR: PublicMonitor = {
  id: 2,
  name: 'E-shop',
  type: 'web',
  status: 'up',
  category: null,
  responseMs: 120,
  lastCheck: null,
  lastStatusChange: null,
  details: null,
  assetId: null,
  cpu: null,
  ram: null,
  hdd: null,
};

function renderCard(since: string | null, windowStart90: string | null) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ authenticated: false }) } as Response)
    )
  );
  render(
    <LanguageProvider>
      <MemoryRouter>
        <ul>
          <PublicMonitorCard
            monitor={MONITOR}
            uptime={[]}
            uptimePct={100}
            windows={{ d1: 100, d7: 100, d30: 100, d90: 100, since }}
            windowStart90={windowStart90}
          />
        </ul>
      </MemoryRouter>
    </LanguageProvider>
  );
  fireEvent.click(screen.getByRole('button', { name: 'Rozbalit detail' }));
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 8, 23, 12, 0));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Veřejná karta: od kdy jsou data (W1-B2)', () => {
  it('data od 12. 8. v okně od 26. 6.: řádek „Data od 12. 8.“', () => {
    renderCard('2026-08-12', '2026-06-26');
    expect(screen.getByText('Data od 12. 8., delší okna pokrývají jen tuto dobu.')).toBeTruthy();
  });

  it('pokryté okno nebo starší server bez windowStart: žádný řádek', () => {
    renderCard('2026-06-26', '2026-06-26');
    expect(screen.queryByText(/Data od/)).toBeNull();
    cleanup();
    renderCard('2026-08-12', null);
    expect(screen.queryByText(/Data od/)).toBeNull();
  });
});
