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
  alertTone: 'bad',
  status: 'down',
  channel: 'email',
  recipient: 'admin@example.com',
  subject: 'Výpadek: Router - Praha',
  method: 'smtp',
  ok: true,
  delivery: 'sent',
  deliveryRecorded: true,
  providerReply: '250 queued as 4ABC123',
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
    last24h: { total: 1, sent: 1, unknown: 0, failed: 0, byChannel: [{ channel: 'email', total: 1, failed: 0 }] },
    last7d: { total: 9, sent: 9, unknown: 0, failed: 0, byChannel: [{ channel: 'email', total: 9, failed: 0 }] },
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
      // The delivery banner on this page asks on its own and says so loudly
      // when its answer is wrong; here every channel delivers.
      if (url.includes('action=notification_health')) {
        return Promise.resolve(json({ problems: [], windowDays: 7, truncated: false }));
      }
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

  it('obnovení se nejmenuje výstraha výpadku, ani v detailu', async () => {
    // The owner's report: a recovered disk and a restored LTE backup were
    // listed as "Výstraha výpadku", because every status change is kind 'alert'.
    serve(() =>
      json(
        page({
          entries: [row({ id: 5, alertTone: 'good', status: 'storage_recovered', subject: 'Úložiště v pořádku' })],
        })
      )
    );
    renderPage();

    const table = within(await screen.findByRole('table'));
    expect(table.getByText('Obnovení')).toBeTruthy();
    expect(table.queryByText('Výstraha výpadku')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Detail' }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain('Obnovení');
    expect(dialog.textContent).not.toContain('Výstraha výpadku');
    // The filter offers the kind without a tone, so it names what is certain.
    expect(within(screen.getByLabelText('Druh zprávy')).getByText('Změna stavu')).toBeTruthy();
  });

  it('neodeslaná zpráva je vidět na řádku i v pruhu nad tabulkou', async () => {
    serve(() =>
      json(
        page({
          entries: [row({ id: 2, ok: false, delivery: 'failed', error: 'SMTP connect() failed', channel: 'email' })],
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
    serve(() =>
      json(page({ entries: [row({ id: 3, ok: false, delivery: 'failed', error: 'timeout' })], summary: null }))
    );
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

    // Unconfirmed belongs with failed: nobody knows that message arrived either.
    fireEvent.click(screen.getByRole('button', { name: 'Jen neodeslané a nepotvrzené' }));
    await waitFor(() => expect(urls.some((u) => u.includes('delivery=failed%2Cunknown'))).toBe(true));

    // The narrowed request carries all three at once - a filter must not
    // silently drop the previous one.
    const last = urls[urls.length - 1];
    expect(last).toContain('kind=daily_reminder');
    expect(last).toContain('channel=discord');
    expect(last).toContain('delivery=failed%2Cunknown');
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
    serve(() =>
      json(page({ entries: [row({ id: 4, ok: false, delivery: 'failed', error: longError, method: 'fallback' })] }))
    );
    renderPage();
    await screen.findByText('Neodesláno');

    fireEvent.click(screen.getByRole('button', { name: 'Detail' }));

    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain(longError);
    expect(dialog.textContent).toContain('místní doručovatel');
    expect(dialog.textContent).toContain('Obsah zprávy se neukládá');
  });
  it('nepotvrzená zpráva není odeslaná: jantarový štítek a důvod', async () => {
    // The owner's case: mail() took the message and CallMeBot answered 2xx, the
    // log said "Odesláno", and nothing arrived.
    const why = "Handed to the hosting's mail(); nothing confirmed delivery. SMTP not configured: missing smtp_host.";
    serve(() =>
      json(
        page({
          entries: [row({ id: 8, delivery: 'unknown', method: 'fallback', error: why, providerReply: null })],
          summary: {
            last24h: { total: 1, sent: 0, unknown: 1, failed: 0, byChannel: [] },
            last7d: { total: 1, sent: 0, unknown: 1, failed: 0, byChannel: [] },
          },
        })
      )
    );
    renderPage();

    const table = within(await screen.findByRole('table'));
    expect(table.getByText('Nepotvrzeno')).toBeTruthy();
    expect(table.queryByText('Odesláno')).toBeNull();
    expect(table.getByText(why)).toBeTruthy();
    expect(screen.getByText('Nepotvrzeno za 24 h')).toBeTruthy();
  });

  it('řádek ze staršího serveru bez výsledku doručení se nikdy netváří jako odeslaný', async () => {
    const legacy = row({ id: 9, ok: true, error: null });
    delete legacy.delivery;
    delete legacy.deliveryRecorded;
    delete legacy.providerReply;
    serve(() => json(page({ entries: [legacy] })));
    renderPage();

    const table = within(await screen.findByRole('table'));
    expect(table.getByText('Nepotvrzeno')).toBeTruthy();
    expect(table.queryByText('Odesláno')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Detail' }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain('odvozený');
  });

  it('starý řádek odvozený jako odeslaný si v detailu neprotiřečí', async () => {
    // An old SMTP row reads as sent (a 250 was needed for ok=1); the note
    // under the green badge used to say its confirmation was missing.
    serve(() =>
      json(
        page({
          entries: [row({ id: 10, delivery: 'sent', deliveryRecorded: false, method: 'smtp', providerReply: null })],
        })
      )
    );
    renderPage();
    await screen.findByText('admin@example.com');

    fireEvent.click(screen.getByRole('button', { name: 'Detail' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Odesláno')).toBeTruthy();
    expect(dialog.textContent).toContain('ze způsobu odeslání');
    expect(dialog.textContent).not.toContain('potvrzení u něj chybí');
  });

  it('detail ukáže odpověď poskytovatele a řekne, co „Odesláno“ znamená', async () => {
    serve(() => json(page()));
    renderPage();
    await screen.findByText('admin@example.com');

    fireEvent.click(screen.getByRole('button', { name: 'Detail' }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain('ODPOVĚĎ POSKYTOVATELE');
    expect(dialog.textContent).toContain('250 queued as 4ABC123');
    expect(dialog.textContent).toContain('doručení do telefonu či schránky nepotvrzuje');
    // A recorded row is not the legacy one.
    expect(dialog.textContent).not.toContain('odvozený');
  });
});
