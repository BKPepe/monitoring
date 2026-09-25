// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * What a visitor who came because something broke sees first: an ongoing
 * incident sits right under the verdict, above the services; resolved ones
 * stay in the history further down. The verdict carries a freshness pill
 * computed from the server's newest check.
 */
const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

const incident = (id: number, title: string, status: string) => ({
  id,
  title,
  status,
  impact: 'major',
  createdAt: '25.09.2026 09:40:00',
  resolvedAt: status === 'resolved' ? '25.09.2026 09:55:00' : null,
  durationText: status === 'resolved' ? '15 min' : null,
  updates: [],
});

function api(url: string): Response {
  if (url.includes('action=monitors'))
    return json({
      monitors: [
        {
          id: 1,
          name: 'Schlehofer.eu',
          type: 'web',
          status: 'down',
          category: 'Weby',
          responseMs: null,
          lastCheck: null,
          lastStatusChange: null,
          details: null,
          assetId: 1,
          cpu: null,
          ram: null,
          hdd: null,
        },
      ],
    });
  if (url.includes('action=public_status'))
    return json({
      totalMonitors: 1,
      downMonitors: 1,
      uptimePercent: 99,
      lastUpdated: new Date(Date.now() - 30_000).toISOString(),
      nodes: [],
    });
  if (url.includes('action=incidents'))
    return json({
      manualIncidents: [incident(1, 'Web neodpovídá', 'investigating'), incident(2, 'Starý výpadek', 'resolved')],
    });
  return json({});
}

let clock = Date.UTC(2026, 8, 25, 8, 0, 0);
beforeEach(() => {
  // usePublicStatus shares answers for ten seconds at module level.
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }))
  );
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => api(String(input)))
  );
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('Veřejná stránka: probíhající incident nahoře', () => {
  it('probíhající incident je před službami, vyřešený až za nimi; verdikt má pilulku čerstvosti', async () => {
    const { container } = render(
      <LanguageProvider>
        <MemoryRouter initialEntries={['/public']}>
          <PublicStatusPage />
        </MemoryRouter>
      </LanguageProvider>
    );
    const open = await screen.findByText('Web neodpovídá');
    const resolved = await screen.findByText('Starý výpadek');
    const service = await screen.findByText('Schlehofer.eu');
    const before = (a: Node, b: Node) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) !== 0;
    expect(before(open, service)).toBe(true);
    expect(before(service, resolved)).toBe(true);
    expect(container.querySelector('[data-state="fresh"]')).not.toBeNull();
  });
});
