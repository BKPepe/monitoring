// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { TeamspeakCard } from './teamspeak-card';

const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

/** The stored check_stages of a TeamSpeak check; only the ServerQuery part varies. */
function stubStages(query: Record<string, unknown>) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve(
        json({
          stages: {
            query: { ok: true, time_ms: 12, ...query },
            service: { clients_online: 3, clients_max: 32, slot_usage_pct: 9.4, channel_count: 3 },
            ports: {},
            version: '3.13.7',
          },
        })
      )
    )
  );
}

function renderCard() {
  return render(
    <LanguageProvider>
      <TeamspeakCard monitorId={4} />
    </LanguageProvider>
  );
}

const NO_AUTH = /Doplňte jméno a heslo/;
const REJECTED = /ServerQuery odmítl jméno a heslo/;
const FAILED = /Přihlášení k ServerQuery se nezdařilo/;

describe('TeamspeakCard', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('without credentials asks for them', async () => {
    stubStages({ authenticated: false, steps: { serverinfo: true, channellist: true, clientlist: true } });
    renderCard();
    expect(await screen.findByText(NO_AUTH)).toBeTruthy();
    expect(screen.queryByText(REJECTED)).toBeNull();
  });

  it('with rejected credentials says so instead of asking to add them', async () => {
    // The check records a login step only when credentials are configured;
    // error id 520 is ServerQuery's "invalid loginname or password".
    stubStages({
      authenticated: false,
      login_error: 520,
      steps: { serverinfo: true, login: false, channellist: true, clientlist: true },
    });
    renderCard();
    expect(await screen.findByText(REJECTED)).toBeTruthy();
    expect(screen.queryByText(NO_AUTH)).toBeNull();
    expect(screen.queryByText(FAILED)).toBeNull();
  });

  it.each([
    ['another error id', { login_error: 2568, steps: { serverinfo: true, login: false } }],
    ['no answer', { login_error: null, steps: { serverinfo: true, login: null, channellist: null, clientlist: null } }],
    ['an older check without the id', { steps: { serverinfo: true, login: false, channellist: true } }],
  ])('a failed login with %s names no cause', async (_case, query) => {
    stubStages({ authenticated: false, ...query });
    renderCard();
    expect(await screen.findByText(FAILED)).toBeTruthy();
    expect(screen.queryByText(REJECTED)).toBeNull();
    expect(screen.queryByText(NO_AUTH)).toBeNull();
  });

  it('after a login shows neither note', async () => {
    stubStages({
      authenticated: true,
      steps: {
        serverinfo: true,
        login: true,
        channellist: true,
        clientlist: true,
        servergrouplist: true,
        logout: true,
      },
    });
    renderCard();
    expect(await screen.findByText('Verze 3.13.7')).toBeTruthy();
    expect(screen.queryByText(NO_AUTH)).toBeNull();
    expect(screen.queryByText(REJECTED)).toBeNull();
    expect(screen.queryByText(FAILED)).toBeNull();
  });
});
