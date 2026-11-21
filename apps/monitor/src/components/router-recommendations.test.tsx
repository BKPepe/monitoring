// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { setCsrfToken } from '@/api/app-api';
import type { RecommendationArea, RouterRecommendationsResponse } from '@/api/types';
import omnia from '@/api/omnia-router.fixture';
import { RouterRecommendations, useRouterRecommendations } from './router-recommendations';

const json = (body: unknown, ok = true, status = 200) =>
  ({ ok, status, json: () => Promise.resolve(body) }) as Response;

/** The page's wiring in small: one request, handed to the card. */
function Harness({ area, compact, version }: { area?: RecommendationArea; compact?: boolean; version?: string }) {
  const source = useRouterRecommendations(6);
  return <RouterRecommendations monitorId={6} source={source} area={area} compact={compact} agentVersion={version} />;
}

function renderCard(props: { area?: RecommendationArea; compact?: boolean; version?: string } = {}) {
  return render(
    <LanguageProvider>
      <Harness {...props} />
    </LanguageProvider>
  );
}

/** Serves `answer` for the list and records every mute POST. */
function stubApi(answer: () => RouterRecommendationsResponse | Record<string, never>, muteOk = true) {
  const posts: Record<string, unknown>[] = [];
  const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input);
    if (url.includes('action=router_recommendation_mute')) {
      posts.push(JSON.parse(String(init?.body)));
      return Promise.resolve(muteOk ? json({ ok: true, key: 'k', muted: true, mute: null }) : json({}, false, 500));
    }
    if (url.includes('action=router_recommendations')) return Promise.resolve(json(answer()));
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
  const listCalls = () => fetchMock.mock.calls.filter(([u]) => String(u).includes('action=router_recommendations&'));
  return { posts, listCalls };
}

const base = omnia.recommendations;
const warmDisk = base.items[0];

describe('RouterRecommendations', () => {
  beforeEach(() => setCsrfToken('t'));

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    setCsrfToken(null);
  });

  it('a failed request shows the error, never the "no recommendations" text', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.resolve(json({ error: 'boom' }, false, 500)))
    );
    renderCard();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Doporučení se nepodařilo načíst.');
    expect(screen.queryByText(/Žádné doporučení/)).toBeNull();
  });

  it('a 200 that is not the documented shape is an error too, not an empty list', async () => {
    stubApi(() => ({}));
    renderCard();
    expect((await screen.findByRole('alert')).textContent).toContain('Doporučení se nepodařilo načíst.');
    expect(screen.queryByText(/Žádné doporučení/)).toBeNull();
  });

  it('says "no recommendations" only for an applicable router with an empty list', async () => {
    stubApi(() => ({ ...base, items: [], muted: [] }));
    renderCard();
    expect(await screen.findByText(/Žádné doporučení – vše, co se měří, je v pořádku/)).toBeTruthy();
  });

  it('draws the row: severity, title, what was found, what to do and since when', async () => {
    stubApi(() => base);
    renderCard();
    const title = await screen.findByText('Disk sda je trvale teplý');
    const row = title.closest('li')!;
    expect(within(row).getByText('Varování')).toBeTruthy();
    expect(row.textContent).toContain(
      'Zjištěno: Disk KINGSTON SUV500MS120G (sda) měl za posledních 7 dní průměrně 67 °C'
    );
    expect(row.textContent).toContain('Co udělat: Zlepšete chlazení routeru');
    expect(row.textContent).toContain('trvá od 21. 9. 2026');
    // The window of the weekly engine, as calendar days.
    expect(screen.getByText(/14\. 9\. 2026–20\. 9\. 2026/)).toBeTruthy();
  });

  it('keeps info items behind their own disclosure, counted', async () => {
    stubApi(() => base);
    renderCard();
    const summary = await screen.findByText('Pro informaci (1)');
    const group = summary.closest('details')!;
    expect(group.open).toBe(false);
    expect(within(group).getByText('Měření z routeru brzdí jeho procesor')).toBeTruthy();
  });

  it('lists muted items with the reason, who muted them and when', async () => {
    stubApi(() => base);
    renderCard();
    const group = (await screen.findByText('Ztlumená (1)')).closest('details')!;
    expect(group.open).toBe(false);
    const row = within(group).getByText('Klienti s podporou 6 GHz nemají 6GHz síť').closest('li')!;
    expect(row.textContent).toContain('Ztlumil(a) pepe dne 22. 9. 2026');
    expect(row.textContent).toContain('Důvod: 6 GHz obsluhuje jiný přístupový bod');
    expect(within(row).getByRole('button', { name: 'Zrušit ztlumení' })).toBeTruthy();
  });

  it('a muted rule that does not fire now keeps its key, the note and the Unmute button', async () => {
    stubApi(() => ({
      ...base,
      muted: [{ ...base.muted[0], title: null, measured: null, action: null, active: false }],
    }));
    renderCard();
    const row = (await screen.findByText('wifi_6ghz_unserved')).closest('li')!;
    expect(row.textContent).toContain('Toto doporučení teď neplatí; ztlumení zůstává uložené.');
    expect(within(row).getByRole('button', { name: 'Zrušit ztlumení' })).toBeTruthy();
  });

  it('offers Mute and Unmute only to a viewer who may mute', async () => {
    stubApi(() => ({ ...base, canMute: false }));
    renderCard();
    await screen.findByText('Disk sda je trvale teplý');
    expect(screen.queryByRole('button', { name: 'Ztlumit' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Zrušit ztlumení' })).toBeNull();
    // The muted item itself stays visible to everybody.
    expect(screen.getByText('Klienti s podporou 6 GHz nemají 6GHz síť')).toBeTruthy();
  });

  it('the mute dialog posts the key and the reason, then asks for the list again', async () => {
    const api = stubApi(() => base);
    renderCard();
    const row = (await screen.findByText('Disk sda je trvale teplý')).closest('li')!;
    expect(api.listCalls()).toHaveLength(1);

    fireEvent.click(within(row).getByRole('button', { name: 'Ztlumit' }));
    const dialog = await screen.findByRole('dialog');
    expect(dialog.textContent).toContain('Ztlumit doporučení pro tento router');
    expect(dialog.textContent).toContain('přestane chodit v pondělním e-mailu');
    fireEvent.change(within(dialog).getByRole('textbox'), { target: { value: '  disk je u zdroje, vím o tom  ' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ztlumit' }));

    await waitFor(() => expect(api.listCalls()).toHaveLength(2));
    expect(api.posts).toEqual([
      { monitor_id: 6, key: warmDisk.key, muted: true, reason: 'disk je u zdroje, vím o tom' },
    ]);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('a mute the server refused stays in the dialog with an error and asks for nothing again', async () => {
    const api = stubApi(() => base, false);
    renderCard();
    const row = (await screen.findByText('Disk sda je trvale teplý')).closest('li')!;
    fireEvent.click(within(row).getByRole('button', { name: 'Ztlumit' }));
    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ztlumit' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain('Ztlumení se nepodařilo uložit.');
    expect(api.listCalls()).toHaveLength(1);
  });

  it('Unmute posts muted=false for that key and refetches', async () => {
    const api = stubApi(() => base);
    renderCard();
    fireEvent.click(await screen.findByRole('button', { name: 'Zrušit ztlumení' }));
    await waitFor(() => expect(api.listCalls()).toHaveLength(2));
    expect(api.posts).toEqual([{ monitor_id: 6, key: 'wifi_6ghz_unserved', muted: false, reason: '' }]);
  });

  it('marks an item that came back because its severity rose', async () => {
    stubApi(() => ({ ...base, items: [{ ...warmDisk, severity: 'critical', wasMuted: true }] }));
    renderCard();
    const row = (await screen.findByText('Disk sda je trvale teplý')).closest('li')!;
    expect(within(row).getByText('Kritické')).toBeTruthy();
    expect(row.textContent).toContain('Bylo ztlumené, ale závažnost vzrostla.');
  });

  it('renders a command in a code block with a copy button', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText } });
    const command = 'opkg update && opkg install hostapd-utils';
    stubApi(() => ({ ...base, items: [{ ...warmDisk, key: 'pkg_hostapd_utils', area: 'packages', command }] }));
    renderCard();
    const code = await screen.findByText(command);
    expect(code.tagName).toBe('CODE');
    fireEvent.click(screen.getByRole('button', { name: 'Kopírovat' }));
    await screen.findByRole('button', { name: 'Zkopírováno' });
    expect(writeText).toHaveBeenCalledWith(command);
  });

  it('names the agent version when the agent is too old, and says so when the router is silent', async () => {
    stubApi(() => ({ ...base, applicable: false, reason: 'agent_old', window: null, items: [], muted: [] }));
    renderCard({ version: '0.1.6' });
    expect(await screen.findByText(/agent 0\.1\.7 a novější \(router hlásí 0\.1\.6\)/)).toBeTruthy();
    expect(screen.queryByText(/Žádné doporučení/)).toBeNull();
    cleanup();

    stubApi(() => ({ ...base, applicable: false, reason: 'silent', window: null, items: [], muted: [] }));
    renderCard();
    expect(await screen.findByText(/Router se delší dobu neozval/)).toBeTruthy();
  });

  it('says when the week has too few days of data', async () => {
    stubApi(() => ({ ...base, window: { ...base.window!, daysWithData: 2 } }));
    renderCard();
    expect(await screen.findByText(/aspoň 4 dny měření – zatím jsou 2/)).toBeTruthy();
  });

  it('compact: only the urgent items of its area, no mute, and nothing at all when the area is fine', async () => {
    stubApi(() => base);
    // Wi-Fi has only a muted item: not urgent. It is mounted first, so its
    // answer has arrived by the time the storage copy shows its row.
    render(
      <LanguageProvider>
        <div data-testid="wifi">
          <Harness area="wifi" compact />
        </div>
        <div data-testid="storage">
          <Harness area="storage" compact />
        </div>
      </LanguageProvider>
    );
    const storage = screen.getByTestId('storage');
    await within(storage).findByText('Disk sda je trvale teplý');
    expect(within(storage).queryByRole('button', { name: 'Ztlumit' })).toBeNull();
    expect(storage.textContent).not.toContain('Ztlumená');
    expect(storage.textContent).not.toContain('Měření z routeru brzdí jeho procesor');
    expect(screen.getByTestId('wifi').textContent).toBe('');
  });

  it('compact: a failed request is still said', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => Promise.reject(new Error('network down')))
    );
    renderCard({ area: 'wifi', compact: true });
    expect((await screen.findByRole('alert')).textContent).toContain('Doporučení se nepodařilo načíst.');
  });
});
