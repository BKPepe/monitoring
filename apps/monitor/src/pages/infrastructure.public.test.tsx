// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';

/**
 * W1-G3 (owner decision 5.3): the public status page is indexed, so the monitor
 * dialog says whether a monitor is on it. The switch shows what the server
 * answered (the owner's choice or the type's default); only a click is saved,
 * so a monitor nobody chose for keeps following its type's default.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const LABEL = 'Zobrazit na veřejné stavové stránce';

function monitor(type: string, extra: Record<string, unknown>) {
  return {
    id: 6,
    name: 'Turris',
    type,
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

async function openGeneralTab() {
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
  window.history.pushState({}, '', '/?edit=6');
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

describe('Monitor: přepínač veřejné stránky (W1-G3)', () => {
  it('veřejný web: přepínač zapnutý a vypnutí se odešle jako is_public 0', async () => {
    const saves = stubApi(monitor('web', { name: 'E-shop', target: 'https://example.test', isPublic: true }));
    const box = await openGeneralTab();
    expect(box.checked).toBe(true);

    fireEvent.click(box);
    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].is_public).toBe(0);
  });

  it('server hodnotu neposlal: router ukazuje výchozí skrytý a uložení klíč vynechá', async () => {
    const saves = stubApi(monitor('openwrt', {}));
    const box = await openGeneralTab();
    expect(box.checked).toBe(false);

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect('is_public' in saves[0]).toBe(false);
  });

  it('router zapnutý vlastníkem: přepínač to ukáže a bez kliknutí se nic nepřepíše', async () => {
    const saves = stubApi(monitor('openwrt', { isPublic: true }));
    const box = await openGeneralTab();
    expect(box.checked).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect('is_public' in saves[0]).toBe(false);
  });
});
