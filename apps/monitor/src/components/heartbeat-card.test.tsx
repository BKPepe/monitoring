// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { HeartbeatCard } from './heartbeat-card';

/**
 * A new token stops the address every job calls, so it goes out as a POST (the
 * server refuses a GET) and only after the confirmation. When it fails, the
 * address on screen still works and has to stay there.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const info = (token: string) => ({
  monitorId: 6,
  name: 'Noční záloha',
  token,
  url: `https://example.test/status/heartbeat.php?token=${token}`,
  intervalSecs: 3600,
  graceSecs: 300,
  lastSignalAt: null,
  lastResult: null,
  lastMessage: null,
  state: 'up',
  stateReason: null,
  ageSecs: null,
});

function stubFetch(answer: () => Promise<Response>) {
  const calls: { url: string; method: string }[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      calls.push({ url, method: init?.method ?? 'GET' });
      return url.includes('regenerate=1') ? answer() : Promise.resolve(json(info('stary')));
    })
  );
  return calls;
}

async function renderCard() {
  render(
    <LanguageProvider>
      <HeartbeatCard monitorId={6} />
    </LanguageProvider>
  );
  return screen.findByRole('button', { name: 'Nový token' });
}

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('HeartbeatCard: nový token', () => {
  it('jde přes POST a ukáže novou adresu', async () => {
    vi.stubGlobal('confirm', () => true);
    const calls = stubFetch(() => Promise.resolve(json(info('novy'))));
    const button = await renderCard();
    expect(calls).toEqual([{ url: '/status/api.php?action=heartbeat_info&monitor_id=6', method: 'GET' }]);

    fireEvent.click(button);
    await screen.findAllByText(/token=novy/);
    expect(calls[1]).toEqual({
      url: '/status/api.php?action=heartbeat_info&monitor_id=6&regenerate=1',
      method: 'POST',
    });
    expect(screen.queryByText(/token=stary/)).toBeNull();
  });

  it('bez potvrzení se nic neodešle', async () => {
    vi.stubGlobal('confirm', () => false);
    const calls = stubFetch(() => Promise.resolve(json(info('novy'))));
    fireEvent.click(await renderCard());
    await waitFor(() => expect(calls).toHaveLength(1));
    expect(screen.getAllByText(/token=stary/).length).toBeGreaterThan(0);
  });

  it.each([
    ['server odmítne', () => Promise.resolve(json({ error: 'Vyžadován POST.' }, 403))],
    ['síť selže', () => Promise.reject(new Error('offline'))],
  ])('když %s, stará adresa zůstane a řekne se to', async (_name, answer) => {
    vi.stubGlobal('confirm', () => true);
    stubFetch(answer);
    fireEvent.click(await renderCard());
    await screen.findByText(/Nový token se nepodařilo vytvořit/);
    expect(screen.getAllByText(/token=stary/).length).toBeGreaterThan(0);
    expect((screen.getByRole('button', { name: 'Nový token' }) as HTMLButtonElement).disabled).toBe(false);
  });
});
