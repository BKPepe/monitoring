// @vitest-environment jsdom
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { DashboardPage } from './dashboard';
import { Sidebar } from '@/components/layout/sidebar';
import { Header } from '@/components/layout/header';

/**
 * W1-D3: on a 390 px phone the dashboard card's status was cut off.
 *
 * jsdom lays nothing out, so the width itself is measured by the Playwright
 * run; this file pins the structure that makes it fit: the status comes
 * before the name, the tab strip scrolls on its own, grid children may
 * shrink, and the header keeps the bell by shrinking the search to an icon.
 */
const json = (body: unknown) =>
  ({ ok: true, status: 200, json: () => Promise.resolve(body), text: () => Promise.resolve('') }) as Response;

const monitor = (over: Record<string, unknown>) => ({
  status: 'up',
  category: 'Weby',
  target: 'https://example.test',
  type: 'web',
  responseMs: 120,
  cpu: null,
  ram: null,
  hdd: null,
  lastCheck: '2026-09-22T10:00:00Z',
  lastStatusChange: '2026-09-22T09:00:00Z',
  assetId: null,
  details: {},
  ...over,
});

const MONITORS = [
  monitor({ id: 2, name: 'E-shop s dlouhým názvem, který se na telefon nevejde celý', status: 'down' }),
  monitor({ id: 6, name: 'Turris Omnia', type: 'openwrt', target: 'router.example.test' }),
  // A vantage point, told apart by type (W1-D2) - never listed as a monitor.
  monitor({ id: 30, name: 'Cloudflare Edge Frankfurt', type: 'node' }),
];

function api(url: string): Response {
  if (url.includes('action=monitors')) return json({ monitors: MONITORS });
  if (url.includes('action=dashboard_layout')) return json({ catalog: [], tiles: [] });
  if (url.includes('action=public_status'))
    return json({ totalMonitors: 2, uptimePercent: null, avgLatencyMs: null, nodes: [] });
  if (url.includes('action=events')) return json({ events: [] });
  if (url.includes('action=daily_uptime')) return json({ rows: [] });
  if (url.includes('action=ui_config')) return json({ customNavLinks: [] });
  return json({});
}

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => Promise.resolve(api(String(input))))
  );
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
      onchange: null,
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
  vi.unstubAllGlobals();
});

function renderWithShell(node: ReactNode) {
  return render(
    <LanguageProvider>
      <MemoryRouter>{node}</MemoryRouter>
    </LanguageProvider>
  );
}

describe('Dashboard na telefonu (W1-D3)', () => {
  it('karta na mobilu ukáže stav dřív než jméno', async () => {
    const { container } = renderWithShell(<DashboardPage />);
    await screen.findAllByText(/E-shop s dlouhým názvem/);

    const mobileList = container.querySelector('div.md\\:hidden');
    expect(mobileList).not.toBeNull();
    const card = mobileList!.querySelector('a[href="/infrastructure/2"]') as HTMLElement;
    const text = card.textContent ?? '';
    expect(text.indexOf('Offline')).toBeGreaterThanOrEqual(0);
    expect(text.indexOf('Offline')).toBeLessThan(text.indexOf('E-shop'));
  });

  it('sonda (typ node) se mezi monitory neukáže', async () => {
    const { container } = renderWithShell(<DashboardPage />);
    await screen.findAllByText(/E-shop s dlouhým názvem/);
    expect(container.querySelector('a[href="/infrastructure/30"]')).toBeNull();
  });

  it('pruh záložek se posouvá sám, nerozšíří kartu', async () => {
    renderWithShell(<DashboardPage />);
    const tablist = await screen.findByRole('tablist');
    expect(tablist.className).toContain('whitespace-nowrap');
    expect(tablist.parentElement?.className).toContain('overflow-x-auto');
  });

  it('dlaždice KPI jsou už na telefonu ve dvou sloupcích a smí se zúžit', async () => {
    renderWithShell(<DashboardPage />);
    const tile = (await screen.findByText('Monitorů celkem')).closest('div.grid') as HTMLElement;
    expect(tile.className).toMatch(/(^|\s)grid-cols-2(\s|$)/);
    expect(tile.className).toContain('*:min-w-0');
  });
});

describe('Navigace na telefonu (W1-D3)', () => {
  it('postranní menu má položku Odchozí zprávy', () => {
    renderWithShell(<Sidebar collapsed={false} onToggle={() => {}} />);
    const link = screen.getByRole('link', { name: /Odchozí zprávy/ });
    expect(link.getAttribute('href')).toBe('/outgoing-messages');
  });

  it('hledání se pod sm zúží na ikonu se jménem, zvonek zůstane', () => {
    // useTheme reads the stored theme; this Node build has no localStorage.
    vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
    renderWithShell(<Header />);
    const search = screen.getByRole('button', { name: 'Hledat monitory, stránky…' });
    // The visible text is hidden below sm; the name must not depend on it.
    const label = within(search).getByText('Hledat monitory, stránky…');
    expect(label.className).toMatch(/(^|\s)hidden(\s|$)/);
    expect(label.className).toContain('sm:inline');
    expect(screen.getByRole('button', { name: 'Upozornění' })).toBeTruthy();
  });
});
