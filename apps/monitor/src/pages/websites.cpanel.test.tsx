// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { WebsitesPage } from './websites';

/**
 * R46: the cPanel block said "UAPI OK" whatever the exporter sent: figures of
 * the whole server from its fallbacks, or none at all.
 */ const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const web = (id: number, name: string, status: string, extra: Record<string, unknown> = {}) => ({
  id,
  name,
  type: 'web',
  target: `https://${name.toLowerCase()}.example.test`,
  status,
  lastCheck: '2026-09-23 10:00:00',
  responseMs: 120,
  details: {},
  ...extra,
});

function show(cpanel: Record<string, unknown>) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=session'))
        return Promise.resolve(
          json({ authenticated: true, user: { id: 1, username: 'a', role: 'admin' }, csrfToken: 't' })
        );
      if (url.includes('action=monitors'))
        return Promise.resolve(json({ monitors: [web(1, 'Eshop', 'up', { details: { cpanel_stats: cpanel } })] }));
      return Promise.resolve(json({}));
    })
  );
  render(
    <LanguageProvider>
      <MemoryRouter>
        <WebsitesPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Weby: zdroj hodnot z cPanelu', () => {
  it('hodnoty z UAPI jsou UAPI OK', async () => {
    show({ disk: { percent: 40, formatted: '4 GB / 10 GB' } });
    expect(await screen.findByText('UAPI OK')).toBeTruthy();
  });

  it('hodnoty za celý server to řeknou', async () => {
    show({ disk: { percent: 42, formatted: '850 GB / 2 TB (celý disk serveru)', source: 'filesystem' } });
    expect(await screen.findByText('Část hodnot za celý server')).toBeTruthy();
    expect(screen.queryByText('UAPI OK')).toBeNull();
  });

  it('bez jediné hodnoty to není UAPI OK', async () => {
    show({ disk: null, memory: null });
    expect(await screen.findByText('Bez hodnot')).toBeTruthy();
    expect(screen.queryByText('UAPI OK')).toBeNull();
  });
});
