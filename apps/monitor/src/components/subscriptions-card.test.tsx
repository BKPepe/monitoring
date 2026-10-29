// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { SubscriptionsCard } from './subscriptions-card';

const json = (body: unknown, ok = true, status = 200) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as Response;

type Row = { id: number; name: string; email: number; sms: number; whatsapp: number };

/**
 * Serves the loaded rows (`reloaded` from the second load on, null = HTTP 500)
 * and records every save POST.
 */
function stubApi(rows: Row[], saveOk = true, reloaded: Row[] | null = rows) {
  let loads = 0;
  const posts: { subscriptions: (Row & { shown?: Pick<Row, 'email' | 'sms' | 'whatsapp'> })[] }[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes('action=save_subscriptions')) {
        posts.push(JSON.parse(String(init?.body)));
        return Promise.resolve(
          saveOk ? json({ success: true }) : json({ error: 'Odběry se nepodařilo uložit.' }, false, 500)
        );
      }
      if (url.includes('action=get_subscriptions')) {
        if (loads++ > 0 && reloaded === null) return Promise.resolve(json({}, false, 500));
        return Promise.resolve(json({ subscriptions: loads === 1 ? rows : reloaded }));
      }
      return Promise.resolve(json({}));
    })
  );
  return posts;
}

/** Renders the card; the returned function re-renders it after a profile save. */
function renderCard(profileSwitches?: string) {
  const card = (switches?: string) => (
    <LanguageProvider>
      <SubscriptionsCard profileSwitches={switches} />
    </LanguageProvider>
  );
  const view = render(card(profileSwitches));
  return (switches: string) => view.rerender(card(switches));
}

const loads = () =>
  vi.mocked(fetch).mock.calls.filter(([input]) => String(input).includes('action=get_subscriptions')).length;

const ROWS: Row[] = [
  { id: 1, name: 'Web', email: 1, sms: 0, whatsapp: 1 },
  { id: 2, name: 'Router', email: 1, sms: 0, whatsapp: 1 },
];

/** The checkbox of one channel in one monitor's row (columns: e-mail, SMS, WhatsApp). */
async function box(name: string, column: number) {
  const row = (await screen.findByText(name)).parentElement as HTMLElement;
  return row.querySelectorAll('input[type="checkbox"]')[column] as HTMLInputElement;
}

describe('SubscriptionsCard', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('posts what each box showed at load next to the choice', async () => {
    const posts = stubApi(ROWS);
    renderCard();
    fireEvent.click(await box('Web', 1));
    fireEvent.click(screen.getByRole('button', { name: 'Uložit odběry' }));
    await waitFor(() => expect(posts).toHaveLength(1));
    const [web, router] = posts[0].subscriptions;
    expect(web).toMatchObject({ id: 1, email: 1, sms: 1, whatsapp: 1, shown: { email: 1, sms: 0, whatsapp: 1 } });
    // Untouched: the server keeps what it has instead of pinning these values.
    expect(router).toMatchObject({ id: 2, email: 1, sms: 0, whatsapp: 1, shown: { email: 1, sms: 0, whatsapp: 1 } });
  });

  it('takes a saved choice as the new baseline, so unticking it again is a change', async () => {
    const posts = stubApi(ROWS);
    renderCard();
    fireEvent.click(await box('Web', 1));
    fireEvent.click(screen.getByRole('button', { name: 'Uložit odběry' }));
    await waitFor(() => expect(posts).toHaveLength(1));
    await screen.findByRole('button', { name: 'Uloženo!' });
    fireEvent.click(await box('Web', 1));
    fireEvent.click(screen.getByRole('button', { name: 'Uloženo!' }));
    await waitFor(() => expect(posts).toHaveLength(2));
    expect(posts[1].subscriptions[0]).toMatchObject({ sms: 0, shown: { sms: 1 } });
  });

  it('keeps the load-time baseline after a failed save', async () => {
    const posts = stubApi(ROWS, false);
    renderCard();
    fireEvent.click(await box('Web', 1));
    fireEvent.click(screen.getByRole('button', { name: 'Uložit odběry' }));
    expect((await screen.findByRole('alert')).textContent).toContain('Odběry se nepodařilo uložit.');
    fireEvent.click(screen.getByRole('button', { name: 'Uložit odběry' }));
    await waitFor(() => expect(posts).toHaveLength(2));
    expect(posts[1].subscriptions[0]).toMatchObject({ sms: 1, shown: { sms: 0 } });
  });

  it('reloads after the profile changed its switches when nothing is unsaved', async () => {
    // The profile switched WhatsApp off: monitors without a row follow it.
    const posts = stubApi(
      ROWS,
      true,
      ROWS.map((r) => ({ ...r, whatsapp: 0 }))
    );
    const profileSaved = renderCard('sms:0 whatsapp:1');
    expect((await box('Web', 2)).checked).toBe(true);
    profileSaved('sms:0 whatsapp:0');
    await waitFor(() => expect(loads()).toBe(2));
    await waitFor(async () => expect((await box('Web', 2)).checked).toBe(false));
    // The reloaded values are the new baseline of the next save.
    fireEvent.click(screen.getByRole('button', { name: 'Uložit odběry' }));
    await waitFor(() => expect(posts).toHaveLength(1));
    expect(posts[0].subscriptions[0]).toMatchObject({ whatsapp: 0, shown: { whatsapp: 0 } });
  });

  it('keeps unsaved boxes when the profile changes, says so and reloads after the save', async () => {
    const posts = stubApi(ROWS, true, [
      { id: 1, name: 'Web', email: 1, sms: 1, whatsapp: 0 },
      { id: 2, name: 'Router', email: 1, sms: 0, whatsapp: 0 },
    ]);
    const profileSaved = renderCard('sms:0 whatsapp:1');
    fireEvent.click(await box('Web', 1));
    profileSaved('sms:0 whatsapp:0');
    expect(await screen.findByText(/Profil změnil přepínač SMS nebo WhatsApp/)).toBeTruthy();
    expect(loads()).toBe(1);
    expect((await box('Web', 1)).checked).toBe(true);
    expect((await box('Router', 2)).checked).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Uložit odběry' }));
    await waitFor(() => expect(posts).toHaveLength(1));
    expect(posts[0].subscriptions[0]).toMatchObject({ sms: 1, shown: { sms: 0 } });
    await waitFor(() => expect(loads()).toBe(2));
    await waitFor(async () => expect((await box('Router', 2)).checked).toBe(false));
    expect(screen.queryByText(/Profil změnil přepínač SMS nebo WhatsApp/)).toBeNull();
  });

  it('says a failed reload failed and keeps the list instead of showing no monitors', async () => {
    stubApi(ROWS, true, null);
    const profileSaved = renderCard('sms:0 whatsapp:1');
    expect((await box('Web', 2)).checked).toBe(true);
    profileSaved('sms:0 whatsapp:0');
    expect((await screen.findByRole('alert')).textContent).toContain('nepodařilo znovu načíst');
    expect((await box('Web', 2)).checked).toBe(true);
    expect(screen.queryByText('Žádné monitory k odběru.')).toBeNull();
  });

  it.each([
    ['the profile changes its switches again', (profileSaved: (s: string) => void) => profileSaved('sms:1 whatsapp:0')],
    [
      'a box is ticked and unticked again',
      async () => {
        fireEvent.click(await box('Web', 1));
        fireEvent.click(await box('Web', 1));
      },
    ],
  ])('drops the reload error once a later load succeeds (%s)', async (_, loadAgain) => {
    stubApi(ROWS, true, null);
    const profileSaved = renderCard('sms:0 whatsapp:1');
    expect((await box('Web', 2)).checked).toBe(true);
    profileSaved('sms:0 whatsapp:0');
    expect((await screen.findByRole('alert')).textContent).toContain('nepodařilo znovu načíst');
    // The API answers again.
    stubApi(ROWS.map((r) => ({ ...r, whatsapp: 0 })));
    await loadAgain(profileSaved);
    await waitFor(async () => expect((await box('Web', 2)).checked).toBe(false));
    expect(screen.queryAllByRole('alert')).toEqual([]);
  });
});
