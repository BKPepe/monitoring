// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';

/**
 * W1-C3 (owner decision 5.7): the router's masked error lines are on by
 * default and can be switched off per router in the monitor dialog. A value
 * the server did not send is never saved back as the default - that would
 * switch the lines on again behind the owner's back.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const LABEL = 'Posílat chybové řádky z logu routeru';

function router(extra: Record<string, unknown>) {
  return {
    id: 6,
    name: 'Turris',
    type: 'openwrt',
    target: '',
    status: 'up',
    category: null,
    assetId: 6,
    assetName: 'Turris',
    lastCheck: null,
    lastStatusChange: null,
    responseMs: null,
    cpu: null,
    ram: null,
    hdd: null,
    uptimeSeconds: null,
    agentLastSeen: null,
    hostname: null,
    os: null,
    ...extra,
  };
}

function stubApi(monitor: Record<string, unknown>) {
  const saves: Record<string, unknown>[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes('action=session')) {
        return Promise.resolve(
          json({
            authenticated: true,
            user: { id: 1, username: 'a', email: 'a@x.test', role: 'admin' },
            csrfToken: 't',
            loginUrl: '',
          })
        );
      }
      if (url.includes('action=save_monitor')) {
        saves.push(JSON.parse(String(init?.body)));
        return Promise.resolve(json({ success: true, id: 6 }));
      }
      if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: [monitor] }));
      return Promise.resolve(json({}));
    })
  );
  return saves;
}

// The save goes through the page's async submit; under a loaded, fully
// parallel run it took longer than waitFor's 1 s default.
const SAVE_WAIT = { timeout: 3000 };

async function openAgentTab() {
  render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter>
          <InfrastructurePage />
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
  return (await screen.findByLabelText(LABEL, {}, { timeout: 3000 })) as HTMLInputElement;
}

beforeEach(() => {
  // The page opens the editor from ?edit=, read from window.location.
  window.history.pushState({}, '', '/?edit=6&tab=advanced');
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    }
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  window.history.pushState({}, '', '/');
});

describe('Router: chybové řádky z logu jdou vypnout u monitoru (W1-C3)', () => {
  it('uložené „vypnuto“ se ukáže a zapnutí se odešle jako log_lines_enabled 1', async () => {
    const saves = stubApi(router({ logLinesEnabled: false }));
    const box = await openAgentTab();
    expect(box.checked).toBe(false);

    fireEvent.click(box);
    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].log_lines_enabled).toBe(1);
  });

  it('server hodnotu neposlal: přepínač ukazuje výchozí zapnuto a uložení klíč vynechá', async () => {
    const saves = stubApi(router({}));
    const box = await openAgentTab();
    expect(box.checked).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect('log_lines_enabled' in saves[0]).toBe(false);
  });
});
