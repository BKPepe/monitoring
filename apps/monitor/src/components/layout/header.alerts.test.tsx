// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { Header } from './header';

/**
 * The bell's popover drew a green "every node works" box over an empty list,
 * and a failed events call left the list empty. Set A: a failure is never an
 * all-clear.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const ALL_NODES_OK = 'Všechny monitorované uzly fungují bez závad.';
const FAILED = 'Upozornění se nepodařilo načíst. Stav uzlů teď není známý.';

function serve(events: () => Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) =>
      Promise.resolve(String(input).includes('action=events') ? events() : json({ readUpToId: 0 }))
    )
  );
}

function openBell(alertCountKnown = true) {
  render(
    <LanguageProvider>
      <MemoryRouter>
        <Header alertCount={0} alertCountKnown={alertCountKnown} />
      </MemoryRouter>
    </LanguageProvider>
  );
  fireEvent.click(screen.getByRole('button', { name: 'Upozornění' }));
}

beforeEach(() => {
  // useTheme reads the stored theme; this Node build has no localStorage.
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

  it('úspěšná prázdná odpověď a známý počet incidentů: zelený box a „Vše OK“', async () => {
    serve(() => json({ events: [] }));
    openBell();

    expect(await screen.findByText(ALL_NODES_OK)).toBeTruthy();
    expect(screen.getByText('Vše OK')).toBeTruthy();
  });

  it('počet incidentů se nenačetl: ani prázdné události nejsou „vše OK“', async () => {
    serve(() => json({ events: [] }));
    openBell(false);

    expect(await screen.findByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(ALL_NODES_OK)).toBeNull();
  });
});
