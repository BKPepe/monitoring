// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { CheckLogPage } from './check-log';

/**
 * W2-6: the check log lives in one place. It opens on the changes, folds a
 * run of failures into one row, narrows to a device with ?monitor=, and a
 * failed request is an error - never an empty log.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const row = (
  id: number,
  monitorId: number,
  monitorName: string,
  minute: number,
  status: 'up' | 'down' | 'warning'
) => ({
  id,
  time: `23.09.2026 09:${String(minute).padStart(2, '0')}:00`,
  timeIso: `2026-09-23T09:${String(minute).padStart(2, '0')}:00+02:00`,
  monitorId,
  monitorName,
  target: 'https://example.test',
  type: 'WEB',
  location: null,
  status: status === 'up' ? 'OK' : 'VÝPADEK',
  rawStatus: status,
  statusKey: status,
  errorMsg: status === 'up' ? 'Kontrola proběhla v pořádku.' : 'HTTP status kód: 502',
  isDown: status === 'down',
  isRecovery: false,
  responseTime: 120,
  outageDurationSec: null,
});

const EVENTS = [
  row(10, 1, 'E-shop', 30, 'up'),
  row(9, 2, 'Wiki', 29, 'up'),
  row(8, 1, 'E-shop', 25, 'down'),
  row(7, 1, 'E-shop', 24, 'down'),
  row(6, 1, 'E-shop', 23, 'down'),
  row(5, 2, 'Wiki', 22, 'up'),
];

function stub(answer: (url: string) => Response) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL) => answer(String(input)));
  vi.stubGlobal('fetch', fetchMock);
  return () => fetchMock.mock.calls.map(([u]) => String(u)).filter((u) => u.includes('action=events'));
}

function renderPage(path = '/incidents/checks') {
  return render(
    <LanguageProvider>
      <MemoryRouter initialEntries={[path]}>
        <CheckLogPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('Protokol kontrol (W2-6)', () => {
  it('otevírá se na změnách: tři výpadky E-shopu jsou jeden řádek, úspěšné kontroly čekají pod „Vše“', async () => {
    const eventsCalls = stub((url) =>
      url.includes('action=events') ? json({ events: EVENTS, statusChange: null }) : json({ monitors: [] })
    );
    const { container } = renderPage();

    expect((await screen.findAllByText('E-shop: Výpadek')).length).toBeGreaterThan(0);
    const run = container.querySelector('[data-run]') as HTMLElement;
    expect(run.getAttribute('data-run')).toBe('3');
    expect(screen.queryByText('Wiki: Kontrola v pořádku')).toBeNull();
    expect(screen.getByRole('button', { name: /Změny/ }).getAttribute('aria-pressed')).toBe('true');

    fireEvent.click(screen.getByRole('button', { name: /^Vše/ }));
    expect(screen.getAllByText('Wiki: Kontrola v pořádku').length).toBeGreaterThan(0);
    expect(eventsCalls()[0]).toContain('limit=200');
    expect(eventsCalls()[0]).not.toContain('monitor_id');
  });

  it('?monitor= zúží protokol na zařízení a odkáže na jeho detail', async () => {
    const eventsCalls = stub((url) =>
      url.includes('action=events')
        ? json({ events: EVENTS.filter((e) => e.monitorId === 1), statusChange: null })
        : json({ monitors: [{ id: 1, name: 'E-shop', type: 'web', status: 'down', target: 'x' }] })
    );
    renderPage('/incidents/checks?monitor=1');

    // One device: the title is the finding alone, without the device name.
    expect((await screen.findAllByText('Výpadek')).length).toBeGreaterThan(0);
    expect(eventsCalls()[0]).toContain('monitor_id=1');
    expect(screen.getByRole('link', { name: 'Detail zařízení →' }).getAttribute('href')).toBe('/infrastructure/1');
  });

  it('selhání je chyba s opakováním, nikdy prázdný protokol', async () => {
    let up = false;
    stub((url) =>
      url.includes('action=events')
        ? up
          ? json({ events: EVENTS, statusChange: null })
          : json({ error: 'events_unavailable', message: 'Události se nepodařilo načíst.' }, 500)
        : json({ monitors: [] })
    );
    renderPage();

    const error = await screen.findByText(/Protokol kontrol se nepodařilo načíst \(Události se nepodařilo načíst\.\)/);
    expect(screen.queryByText('Žádné události.')).toBeNull();

    up = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect((await screen.findAllByText('E-shop: Výpadek')).length).toBeGreaterThan(0);
  });
});
