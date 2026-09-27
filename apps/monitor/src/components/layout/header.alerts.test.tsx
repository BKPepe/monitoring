// @vitest-environment jsdom
import type * as React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { Header } from './header';
import { ShellProvider, usePageChrome } from './shell-context';
import type { CountState, FindingCounts } from './use-shell-counts';

/**
 * The bell's popover drew a green "every node works" box over an empty list,
 * and a failed events call left the list empty. Set A: a failure is never an
 * all-clear. With the NetPulse shell the bell also reads the findings
 * summary; a failed or incomplete summary is not an all-clear either.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const ALL_NODES_OK = 'Všechny monitorované uzly fungují bez závad.';
const FAILED = 'Upozornění se nepodařilo načíst. Stav uzlů teď není známý.';
const NONE: FindingCounts = { critical: 0, warning: 0, info: 0 };

function serve(events: () => Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) =>
      Promise.resolve(String(input).includes('action=events') ? events() : json({ readUpToId: 0 }))
    )
  );
}

function openBell({
  findings = NONE,
  findingsState = 'ok',
  incidentsState = 'ok',
  openIncidents = 0,
}: {
  findings?: FindingCounts | null;
  findingsState?: CountState;
  incidentsState?: CountState;
  openIncidents?: number | null;
} = {}) {
  render(
    <LanguageProvider>
      <MemoryRouter>
        <Header
          findings={findings}
          findingsState={findingsState}
          incidentsState={incidentsState}
          openIncidents={openIncidents}
        />
      </MemoryRouter>
    </LanguageProvider>
  );
  fireEvent.click(screen.getByRole('button', { name: 'Upozornění' }));
}

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Zvonek: selhání není „vše OK“ (W1-A)', () => {
  it('události vrací 500: chyba, žádný zelený box ani „Vše OK“', async () => {
    serve(() => json({ error: 'database_unavailable' }, 500));
    openBell();

    expect(await screen.findByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(ALL_NODES_OK)).toBeNull();
    expect(screen.queryByText('Vše OK')).toBeNull();
  });

  it('úspěšné prázdné odpovědi ze všech zdrojů: zelený box a „Vše OK“', async () => {
    serve(() => json({ events: [] }));
    openBell();

    expect(await screen.findByText(ALL_NODES_OK)).toBeTruthy();
    expect(screen.getByText('Vše OK')).toBeTruthy();
  });

  it('počet incidentů se nenačetl: ani prázdné události nejsou „vše OK“', async () => {
    serve(() => json({ events: [] }));
    openBell({ incidentsState: 'failed', openIncidents: null });

    expect(await screen.findByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(ALL_NODES_OK)).toBeNull();
  });

  it('souhrn upozornění selhal: žádné „vše OK“ a zvonek to řekne čtečce', async () => {
    serve(() => json({ events: [] }));
    openBell({ findings: null, findingsState: 'failed' });

    expect(await screen.findByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(ALL_NODES_OK)).toBeNull();
    const alerts = screen.getAllByRole('alert').map((a) => a.textContent);
    expect(alerts).toContain('Počet upozornění se nepodařilo zjistit');
  });

  it('souhrn selhal, ale incident je otevřený: číslo na zvonku platí jen jako spodní mez a řekne se to', () => {
    serve(() => json({ events: [] }));
    openBell({ findings: null, findingsState: 'failed', openIncidents: 2 });
    expect(
      screen.getByText('Upozornění k řešení: 2. Počet upozornění se nepodařilo zjistit', { selector: '.sr-only' })
    ).toBeTruthy();
    expect(screen.getAllByRole('alert').map((a) => a.textContent)).toContain('Počet upozornění se nepodařilo zjistit');
  });

  it('neúplný souhrn (zdroj neodpověděl): varování, ne zelený box', async () => {
    serve(() => json({ events: [] }));
    openBell({ findingsState: 'incomplete' });

    expect(await screen.findByText(/Některý zdroj upozornění neodpověděl/)).toBeTruthy();
    expect(screen.queryByText(ALL_NODES_OK)).toBeNull();
    expect(screen.queryByText('Vše OK')).toBeNull();
  });

  it('kritická a varovná zjištění: počet na zvonku, rozpis podle závažnosti a cesta na Upozornění', async () => {
    serve(() => json({ events: [] }));
    openBell({ findings: { critical: 1, warning: 2, info: 4 } });

    expect(screen.getAllByText('Upozornění k řešení: 3').length).toBeGreaterThan(0);
    expect(screen.getByText(/1\s+Kritické/)).toBeTruthy();
    expect(screen.getByText(/2\s+Varování/)).toBeTruthy();
    const link = screen.getByRole('link', { name: /Kritické/ });
    expect(link.getAttribute('href')).toBe('/insights');
    expect(screen.queryByText(ALL_NODES_OK)).toBeNull();
  });

  it('překryv se nesčítá: jeden otevřený incident a jedno kritické zjištění = 1', () => {
    serve(() => json({ events: [] }));
    openBell({ findings: { critical: 1, warning: 0, info: 0 }, openIncidents: 1 });
    expect(screen.getAllByText('Upozornění k řešení: 1').length).toBeGreaterThan(0);
  });
});

describe('Hlavička: název stránky, zpět a živost dat', () => {
  function Page({ at }: { at: number | null }) {
    usePageChrome({ freshness: { at, intervalSecs: 60 } });
    return null;
  }

  function renderAt(path: string, page?: React.ReactNode) {
    serve(() => json({ events: [] }));
    return render(
      <LanguageProvider>
        <MemoryRouter initialEntries={[path]}>
          <ShellProvider>
            <Header />
            {page}
          </ShellProvider>
        </MemoryRouter>
      </LanguageProvider>
    );
  }

  it('stránka první úrovně má název z navigace a žádnou šipku zpět', () => {
    const { container } = renderAt('/insights');
    expect(container.querySelector('[data-slot="page-title"]')?.textContent).toBe('Upozornění');
    expect(screen.queryByRole('link', { name: 'Zpět' })).toBeNull();
  });

  it('detail zařízení vede zpět na seznam, ne do historie prohlížeče', () => {
    const { container } = renderAt('/infrastructure/12');
    expect(container.querySelector('[data-slot="page-title"]')?.textContent).toBe('Detail zařízení');
    expect(screen.getByRole('link', { name: 'Zpět' }).getAttribute('href')).toBe('/infrastructure');
  });

  it('bez časové značky stránky žádná pilulka „Živě“ - hlavička data nezná', () => {
    renderAt('/');
    expect(screen.queryByText('Živě')).toBeNull();
  });

  it('stránka ohlásí čerstvá data: pilulka „Živě“ se objeví', async () => {
    renderAt('/', <Page at={Date.now() - 5_000} />);
    expect(await screen.findByText('Živě')).toBeTruthy();
  });
});
