// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { IncidentsPage } from './incidents';

/**
 * W1-A3: the incidents page never reads a failure as "no outages", and the
 * measuring section lists places from action=regions, not monitors.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const ALL_OK = /běží v pořádku bez výpadků/;

/** The server built the answer at 10:00 +02:00; prague reported a minute before. */
const REGIONS = {
  days: 7,
  cachedAt: '2026-09-23T10:00:00+02:00',
  regions: [
    {
      location: 'prague',
      checks: 4000,
      successRate: 99.5,
      avgResponseMs: 84,
      monitors: 6,
      firstSeen: '2026-09-16 10:00:00',
      lastSeen: '2026-09-23 09:59:00',
    },
    {
      location: 'frankfurt',
      checks: 900,
      successRate: 100,
      avgResponseMs: null,
      monitors: 3,
      firstSeen: '2026-09-16 10:00:00',
      lastSeen: '2026-09-23 09:20:00',
    },
    {
      location: '🇩🇪 Frankfurt, DE',
      checks: 300,
      successRate: 98.1,
      avgResponseMs: 41,
      monitors: 2,
      firstSeen: '2026-09-16 10:00:00',
      lastSeen: '2026-09-23 09:57:00',
    },
  ],
};

function api(overrides: Record<string, () => Response> = {}) {
  return (input: RequestInfo | URL): Promise<Response> => {
    const url = String(input);
    for (const [action, respond] of Object.entries(overrides)) {
      if (url.includes(`action=${action}`)) return Promise.resolve(respond());
    }
    if (url.includes('action=session')) {
      return Promise.resolve(
        json({ authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' })
      );
    }
    if (url.includes('action=incidents')) return Promise.resolve(json({ incidents: [], manualIncidents: [] }));
    if (url.includes('action=monitors')) {
      return Promise.resolve(
        json({ monitors: [{ id: 9, name: 'Sonda Donald', type: 'node', status: 'up', target: 'x' }] })
      );
    }
    if (url.includes('action=regions')) return Promise.resolve(json(REGIONS));
    if (url.includes('action=events')) return Promise.resolve(json({ events: [] }));
    return Promise.resolve(json({}));
  };
}

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <IncidentsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Incidenty: selhání není „bez výpadků“ (W1-A3)', () => {
  it('incidenty vrací 500: chyba s tlačítkem, žádný zelený box ani „Všechny služby OK“', async () => {
    vi.stubGlobal('fetch', vi.fn(api({ incidents: () => json({ error: 'database_unavailable' }, 500) })));
    renderPage();

    expect(await screen.findByText(/Incidenty se nepodařilo načíst/)).toBeTruthy();
    expect(screen.queryByText(ALL_OK)).toBeNull();
    expect(screen.queryByText('Všechny služby OK')).toBeNull();
    expect(screen.getAllByRole('button', { name: 'Zkusit znovu' }).length).toBeGreaterThan(0);
  });

  it('starý server (200 + prázdný seznam + error) je také selhání', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(api({ incidents: () => json({ incidents: [], manualIncidents: [], error: 'Chyba DB' }) }))
    );
    renderPage();

    expect(await screen.findByText(/Incidenty se nepodařilo načíst/)).toBeTruthy();
    expect(screen.queryByText(ALL_OK)).toBeNull();
  });

  it('úspěšná prázdná odpověď smí ukázat zelený box', async () => {
    vi.stubGlobal('fetch', vi.fn(api()));
    renderPage();

    expect(await screen.findByText(ALL_OK)).toBeTruthy();
  });

  it('místa měření: řádek na lokalitu z regions, ne monitory typu node, a bez vymyšlené latence', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-23T10:02:00+02:00'));
    vi.stubGlobal('fetch', vi.fn(api()));
    renderPage();

    const heading = await screen.findByRole('heading', { name: 'Místa měření' });
    const section = heading.closest('[data-slot="card"], .p-6') as HTMLElement;
    const rows = await within(section).findAllByRole('listitem');
    expect(rows.map((r) => r.querySelector('p')?.textContent)).toEqual(['prague', 'frankfurt', '🇩🇪 Frankfurt, DE']);
    // A monitor of type node is not a place.
    expect(within(section).queryByText('Sonda Donald')).toBeNull();

    const [prague, frankfurt] = rows;
    expect(within(prague).getByText('Měří')).toBeTruthy();
    expect(within(prague).getByText('před 3 min')).toBeTruthy();
    expect(within(prague).getByText('99.5 %')).toBeTruthy();
    expect(within(prague).getByText('84 ms')).toBeTruthy();

    // Silent for 40 minutes when the answer was built: past two 5-minute intervals.
    expect(within(frankfurt).getByText('Odmlčelo se')).toBeTruthy();
    expect(within(frankfurt).getByText('před 42 min')).toBeTruthy();
    // No measured latency = a dash, never the old made-up 12 ms.
    expect(within(frankfurt).getByText('—')).toBeTruthy();
    expect(screen.queryByText(/12 ms/)).toBeNull();
  });

  it('místa měření vrací 500: chyba s opakováním, ne „sondy bez výpadků“', async () => {
    let regionsUp = false;
    vi.stubGlobal('fetch', vi.fn(api({ regions: () => (regionsUp ? json(REGIONS) : json({ error: 'x' }, 500)) })));
    renderPage();

    const error = await screen.findByText('Místa měření se nepodařilo načíst.');
    expect(screen.queryByText(/sondy pracují bez výpadků/)).toBeNull();
    regionsUp = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect(await screen.findByText('prague')).toBeTruthy();
  });
});
