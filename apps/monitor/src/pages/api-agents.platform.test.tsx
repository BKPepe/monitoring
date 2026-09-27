// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ApiAgentsPage } from './api-agents';

/**
 * Site W1-5 conv-12: the dashboard's first-run "Připojit router" links to
 * /api-agents?platform=openwrt, and the page opens on that card.
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

function renderAt(path: string, monitors: unknown[] = []) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(json({ monitors, settings: {} })))
  );
  render(
    <LanguageProvider>
      <MemoryRouter initialEntries={[path]}>
        <ApiAgentsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

// Text, not role queries: the OpenWrt card renders the whole install guide,
// and role lookups over that tree took seconds under a loaded suite.
// The name also heads the selected platform's guide; the card is the one in a button.
const card = (name: string) =>
  screen
    .getAllByText(name)
    .map((el) => el.closest('button'))
    .find((b): b is HTMLButtonElement => b !== null)!;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('API & Agenti: ?platform= (W1-5 conv-12)', () => {
  it('?platform=openwrt otevře kartu OpenWrt', async () => {
    renderAt('/api-agents?platform=openwrt');
    await screen.findAllByText('OpenWrt Router');
    expect(card('OpenWrt Router').getAttribute('aria-pressed')).toBe('true');
    expect(card('Linux / VPS Server').getAttribute('aria-pressed')).toBe('false');
  });

  it('neznámá platforma nechá výchozí Linux', async () => {
    renderAt('/api-agents?platform=amiga');
    await screen.findAllByText('Linux / VPS Server');
    expect(card('Linux / VPS Server').getAttribute('aria-pressed')).toBe('true');
  });
});

describe('API & Agenti: souhrn verzí jen z nahlášených verzí', () => {
  it('agent bez nahlášené verze není „aktuální“ - souhrn to řekne počtem', async () => {
    renderAt('/api-agents', [
      { id: 1, name: 'Router A', type: 'openwrt', status: 'up', target: '', details: { agent_version: '0.1.10' } },
      { id: 2, name: 'Server B', type: 'vps', status: 'up', target: '', details: {} },
    ]);
    expect(await screen.findByText('Verze nehlášena: 1 z 2')).toBeTruthy();
    expect(screen.queryByText(/Všichni agenti aktuální/)).toBeNull();
  });
});
