// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { ApiAgentsPage } from './api-agents';

/**
 * The list of agents says who reports through an agent. A failed request is not
 * an empty fleet, and a monitor's type or name does not make it an agent.
 */
vi.mock('@/api/use-session', () => ({
  useSession: () => ({
    session: { authenticated: true, user: { id: 1, username: 'admin', role: 'admin' }, csrfToken: 't' },
    loading: false,
    isAdmin: true,
  }),
}));

const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

function show(monitors: () => Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) =>
      Promise.resolve(String(input).includes('action=monitors') ? monitors() : json({}))
    )
  );
  render(
    <LanguageProvider>
      <MemoryRouter>
        <ApiAgentsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

const monitor = (id: number, name: string, type: string, extra: Record<string, unknown> = {}) => ({
  id,
  name,
  type,
  status: 'up',
  ...extra,
});

describe('API a agenti: seznam agentů', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('selhané načtení není „žádní agenti“', async () => {
    show(() => json({ error: 'database_unavailable' }, 500));
    expect(await screen.findByText('Seznam agentů se nepodařilo načíst.')).toBeTruthy();
    expect(screen.queryByText(/Žádní registrovaní agenti/)).toBeNull();
  });

  it('„Aktuální verze“ jen tehdy, když je s čím porovnat', async () => {
    show(() =>
      json({
        monitors: [
          monitor(1, 'Aktuální', 'openwrt', { details: { agent_version: '0.1.13' }, agentLatestVersion: '0.1.13' }),
          monitor(2, 'Starý', 'openwrt', {
            details: { agent_version: '0.1.12' },
            agentLatestVersion: '0.1.13',
            agentUpdateAvailable: '0.1.13',
          }),
          monitor(3, 'Neznámo', 'openwrt', { details: { agent_version: '0.1.11' } }),
        ],
      })
    );
    expect(await screen.findByText(/v0\.1\.13 \(Aktuální verze\)/)).toBeTruthy();
    expect(screen.getByText(/v0\.1\.12 \(Neaktuální/)).toBeTruthy();
    expect(screen.getByText('v0.1.11')).toBeTruthy();
  });

  it('agent je, kdo se hlásí; typ TS/MC ani jméno z něj agenta nedělá', async () => {
    show(() =>
      json({
        monitors: [
          monitor(1, 'Router doma', 'openwrt'),
          monitor(2, 'TeamSpeak s agentem', 'teamspeak', { agentLastSeen: '2026-11-05 10:00:00' }),
          monitor(3, 'TeamSpeak bez agenta', 'teamspeak'),
          monitor(4, 'Minecraft', 'minecraft'),
          monitor(5, 'donald.example', 'web'),
          monitor(6, 'router-status', 'web'),
        ],
      })
    );
    expect(await screen.findByText('Router doma')).toBeTruthy();
    expect(screen.getByText('TeamSpeak s agentem')).toBeTruthy();
    for (const name of ['TeamSpeak bez agenta', 'Minecraft', 'donald.example', 'router-status']) {
      expect(screen.queryByText(name)).toBeNull();
    }
  });
});
