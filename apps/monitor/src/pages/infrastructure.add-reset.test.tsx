// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';

/**
 * R173: Add after an edit starts from an empty form. It used to reset most
 * fields by hand and missed some, so a new web inherited the router it
 * followed: its preset, latency limit, maintenance window, category and type.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const ROUTER = {
  id: 6,
  name: 'Turris',
  type: 'openwrt',
  target: '',
  status: 'up',
  category: 'Síť',
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
  presetId: 3,
  latencyThresholdMs: 250,
  latencyThresholdMins: 10,
  maintenance: true,
  maintenanceStart: '2026-08-12 02:00:00',
  maintenanceEnd: '2026-08-12 04:30:00',
};

function stubApi() {
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
        return Promise.resolve(json({ success: true, id: 7 }));
      }
      if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: [ROUTER] }));
      return Promise.resolve(json({}));
    })
  );
  return saves;
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

describe('Přidání monitoru po úpravě začne prázdným formulářem (R173)', () => {
  it('nový web nezdědí preset, práh zpomalení, okno údržby, kategorii ani typ routeru', async () => {
    const saves = stubApi();
    render(
      <LanguageProvider>
        <TooltipProvider>
          <MemoryRouter>
            <InfrastructurePage />
          </MemoryRouter>
        </TooltipProvider>
      </LanguageProvider>
    );
    await screen.findByText('Úprava monitoru #6', {}, { timeout: 3000 });
    fireEvent.click(screen.getByRole('button', { name: 'Zrušit' }));
    fireEvent.click(screen.getByRole('button', { name: /Přidat nový monitor/ }));

    fireEvent.change(screen.getByPlaceholderText(/^Např\./), { target: { value: 'E-shop' } });
    fireEvent.change(screen.getByPlaceholderText('https://bloodkings.eu'), {
      target: { value: 'https://example.test' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), { timeout: 3000 });
    expect(saves[0]).toMatchObject({
      id: 0,
      type: 'web',
      category: 'Webové Portály & API',
      preset_id: null,
      latency_threshold_ms: null,
      latency_threshold_mins: '5',
      maintenance: 0,
      maintenance_start: null,
      maintenance_end: null,
    });
  });
});
