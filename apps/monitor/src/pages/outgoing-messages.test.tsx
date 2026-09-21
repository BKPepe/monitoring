// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import type { OutgoingMessage, OutgoingMessagePage } from '@/api/types';
import { OutgoingMessagesPage } from './outgoing-messages';

/**
 * The page that answers "did that e-mail actually go out?".
 *
 * The session is mocked rather than served over fetch: useSession keeps one
 * shared promise per module, so a second test could never see a different
 * account - and the admin gate is exactly what has to be tested here.
 */
const mocks = vi.hoisted(() => ({
  session: {
    session: { authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' },
    loading: false,
    isAdmin: true,
  },
}));

vi.mock('@/api/use-session', () => ({
  useSession: () => mocks.session,
  useLogout: () => ({ logout: () => {}, pending: false, failed: false }),
}));

const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

const row = (over: Partial<OutgoingMessage> = {}): OutgoingMessage => ({
  id: 1,
  monitorId: null,
  monitorName: null,
  kind: 'alert',
  status: 'down',
  channel: 'email',
  recipient: 'admin@example.com',
  subject: 'Výpadek: Router - Praha',
  method: 'smtp',
  ok: true,
  error: null,
  atIso: new Date().toISOString(),
  ...over,
});

const page = (over: Partial<OutgoingMessagePage> = {}): OutgoingMessagePage => ({
  entries: [row()],
  nextCursor: null,
  kinds: ['alert', 'daily_reminder'],
  channels: ['email', 'discord'],
  summary: {
    last24h: { total: 1, failed: 0, byChannel: [{ channel: 'email', total: 1, failed: 0 }] },
    last7d: { total: 9, failed: 0, byChannel: [{ channel: 'email', total: 9, failed: 0 }] },
  },
  ...over,
});

/** Every request the page makes, so a filter can be checked on the wire. */
let urls: string[] = [];

function serve(answer: (url: string) => Response | Promise<Response>) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      urls.push(url);
      return Promise.resolve(answer(url));
    })
  );
}

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <OutgoingMessagesPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

beforeEach(() => {
  urls = [];
  mocks.session.isAdmin = true;
  mocks.session.session.user.role = 'admin';
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Odchozí zprávy', () => {
  it('vypíše řádek se všemi údaji, které se o odeslání vědí', async () => {
    serve(() => json(page()));
    renderPage();

    expect(await screen.findByText('admin@example.com')).toBeTruthy();
    // Scoped to the table: the same words are also the filter's options.
    const table = within(screen.getByRole('table'));
    expect(table.getByText('Výpadek: Router - Praha')).toBeTruthy();
    // The kind is a readable name, not the server's key.
    expect(table.getByText('Výstraha výpadku')).toBeTruthy();
    expect(table.getByText('E-mail')).toBeTruthy();
    expect(table.getByText('Odesláno')).toBeTruthy();
  });

  it('neodeslaná zpráva je vidět na řádku i v pruhu nad tabulkou', async () => {
    serve(() =>
      json(
        page({
          entries: [row({ id: 2, ok: false, error: 'SMTP connect() failed', channel: 'email' })],
          summary: {
            last24h: { total: 3, failed: 1, byChannel: [{ channel: 'email', total: 3, failed: 1 }] },
            last7d: { total: 20, failed: 1, byChannel: [{ channel: 'email', total: 20, failed: 1 }] },
          },
        })
      )
    );
    renderPage();

    expect(await screen.findByText('Neodesláno')).toBeTruthy();
    expect(screen.getByText('SMTP connect() failed')).toBeTruthy();
    const banner = screen.getByRole('alert');
    expect(banner.textContent).toContain('1');
    expect(banner.textContent).toContain('24 hodin');
  });

  it('bez souhrnu ze serveru pruh přizná, že je to jen dolní odhad', async () => {
    // An older deploy without the summary: the page may count only the rows it
    // holds, so it must not present that number as the whole truth.
    serve(() => json(page({ entries: [row({ id: 3, ok: false, error: 'timeout' })], summary: null })));
    renderPage();

    const banner = await screen.findByRole('alert');
    expect(banner.textContent).toContain('nejméně');
    // Nothing was measured, so the tiles show a dash instead of a zero.
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
  });

  it('prázdný protokol řekne, že zatím nic neodešlo', async () => {
    serve(() =>
      json(
        page({
          entries: [],
          summary: { last24h: { total: 0, failed: 0, byChannel: [] }, last7d: { total: 0, failed: 0, byChannel: [] } },
        })
      )
    );
    renderPage();

    expect(await screen.findByText('Zatím neodešla žádná zpráva.')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('filtr druhu, kanálu a jen neodeslaných se promítne do dotazu na server', async () => {
    serve(() => json(page()));
    renderPage();
    await screen.findByText('admin@example.com');

    fireEvent.change(screen.getByLabelText('Druh zprávy'), { target: { value: 'daily_reminder' } });
    await waitFor(() => expect(urls.some((u) => u.includes('kind=daily_reminder'))).toBe(true));

    fireEvent.change(screen.getByLabelText('Kanál'), { target: { value: 'discord' } });
    await waitFor(() => expect(urls.some((u) => u.includes('channel=discord'))).toBe(true));

    fireEvent.click(screen.getByRole('button', { name: 'Jen neodeslané' }));
    await waitFor(() => expect(urls.some((u) => u.includes('ok=0'))).toBe(true));

    // The narrowed request carries all three at once - a filter must not
    // silently drop the previous one.
    const last = urls[urls.length - 1];
    expect(last).toContain('kind=daily_reminder');
    expect(last).toContain('channel=discord');
    expect(last).toContain('ok=0');
  });

  it('filtr bez výsledku neříká, že se nikdy nic neodeslalo', async () => {
    serve((url) => json(url.includes('kind=') ? page({ entries: [] }) : page()));
    renderPage();
    await screen.findByText('admin@example.com');

    fireEvent.change(screen.getByLabelText('Druh zprávy'), { target: { value: 'daily_reminder' } });

    expect(await screen.findByText('Tomuto filtru neodpovídá žádná zpráva.')).toBeTruthy();
    expect(screen.queryByText('Zatím neodešla žádná zpráva.')).toBeNull();
  });

  it('starší stránka se připojí a pošle kurzor', async () => {
    serve((url) =>
      json(
        url.includes('before_id=1')
          ? page({ entries: [row({ id: 0, recipient: 'druhy@example.com' })], nextCursor: null })
          : page({ nextCursor: 1 })
      )
    );
    renderPage();
    await screen.findByText('admin@example.com');

    fireEvent.click(screen.getByRole('button', { name: 'Načíst starší' }));

    expect(await screen.findByText('druhy@example.com')).toBeTruthy();
    expect(screen.getByText('admin@example.com')).toBeTruthy();
  });

  it('selhání načtení se ukáže a tabulka nepředstírá prázdný protokol', async () => {
    serve(() => ({ ok: false, status: 500, json: () => Promise.resolve({ error: 'DB je pryč' }) }) as Response);
    renderPage();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText(/DB je pryč/)).toBeTruthy();
    expect(screen.queryByText('Zatím neodešla žádná zpráva.')).toBeNull();
  });

  it('účet bez role administrátora protokol nevidí a server se ani neptá', async () => {
    // The rows name recipients - personal data, not a matter of tidiness.
    mocks.session.isAdmin = false;
    mocks.session.session.user.role = 'user';
    serve(() => json(page()));
    renderPage();

    expect(await screen.findByText('Jen pro administrátora')).toBeTruthy();
    expect(urls.some((u) => u.includes('action=notification_log'))).toBe(false);
  });

  it('tichý den připomínky se netváří jako odeslaná zpráva', async () => {
    // The daily reminder writes a `skipped` row every quiet day so the silence
    // is provable. Painted green as "Odesláno" it would claim a message that
    // nobody ever received.
    serve(() =>
      json(
        page({
          entries: [
            row({
              id: 7,
              kind: 'daily_reminder',
              status: 'skipped',
              channel: 'none',
              recipient: null,
              subject: null,
              method: null,
            }),
          ],
        })
      )
    );
    renderPage();

    expect(await screen.findByText('Neodesláno, nebylo co hlásit')).toBeTruthy();
    expect(screen.queryByText('Odesláno')).toBeNull();
    expect(screen.getByText('žádný kanál')).toBeTruthy();
  });

  it('detail ukáže celou chybu a připomene, že obsah zprávy se neukládá', async () => {
    const longError = 'SMTP 550 5.7.1 Message rejected by the receiving server for policy reasons';
    serve(() => json(page({ entries: [row({ id: 4, ok: false, error: longError, method: 'fallback' })] })));
    renderPage();
    await screen.findByText('Neodesláno');

    fireEvent.click(screen.getByRole('button', { name: 'Detail' }));

    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain(longError);
    expect(dialog.textContent).toContain('místní doručovatel');
    expect(dialog.textContent).toContain('Obsah zprávy se neukládá');
  });
});
