// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';

/**
 * Five seconds of outage in 30 days: public_status sends 99.999 and the
 * "Uptime (30 dní)" tile printed it as toFixed(2), "100.00".
 */
const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

function api(input: RequestInfo | URL): Promise<Response> {
  const url = String(input);
  if (url.includes('action=session'))
    return Promise.resolve(json({ authenticated: true, user: { id: 1, username: 'admin', role: 'admin' } }));
  if (url.includes('action=monitors'))
    return Promise.resolve(
      json({ monitors: [{ id: 1, name: 'E-shop', type: 'web', status: 'up', target: 'https://example.test' }] })
    );
  if (url.includes('action=dashboard_layout')) return Promise.resolve(json({ catalog: [], tiles: [] }));
  if (url.includes('action=public_status'))
    return Promise.resolve(
      json({ totalMonitors: 1, downMonitors: 0, uptimePercent: 99.999, avgLatencyMs: 90, nodes: [] })
    );
  if (url.includes('action=daily_uptime')) return Promise.resolve(json({ series: {} }));
  return Promise.resolve(json({}));
}

beforeEach(() => {
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
  vi.stubGlobal('fetch', vi.fn(api));
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Dashboard: uptime za 30 dní s výpadkem', () => {
  it('99,999 % (pět sekund výpadku) ukáže 99.99, ne 100.00', async () => {
    render(
      <LanguageProvider>
        <MemoryRouter>
          <DashboardPage />
        </MemoryRouter>
      </LanguageProvider>
    );

    const label = await screen.findByText('Uptime (30 dní)', { selector: 'p' });
    const card = label.parentElement?.parentElement as HTMLElement;
    await waitFor(() => expect(card.querySelector('span.text-2xl')?.textContent).toBe('99.99'));
  });
});
