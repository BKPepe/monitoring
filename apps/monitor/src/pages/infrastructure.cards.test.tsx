// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';

/**
 * The device list as NetPulse cards: the states as filter pills with counts,
 * the server's health ring on each card (never a client-side score, never a
 * dash ring for a failed request) and the load bars only for what the device
 * measured.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

function monitor(id: number, name: string, type: string, status: string, extra: Record<string, unknown> = {}) {
  return {
    id,
    name,
    type,
    target: '',
    status,
    category: 'Servery',
    assetId: id,
    assetName: name,
    lastCheck: '2026-09-23 10:00:00',
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

const MONITORS = [
  monitor(1, 'E-shop', 'web', 'up', { responseMs: 184 }),
  monitor(2, 'Databáze', 'agent_service', 'down'),
  monitor(3, 'Záloha', 'vps', 'paused'),
  monitor(4, 'VPS', 'vps', 'up', { cpu: 95, ram: 40, effectiveThresholds: { cpu: 90, ram: 95, hdd: 90 } }),
];

const HEALTH = {
  network: { score: 80, grade: 'fair', formulaVersion: 1, components: [] },
  assets: [
    { monitorId: 1, score: 92, grade: 'good' },
    { monitorId: 2, score: null, grade: null },
    { monitorId: 3, score: null, grade: null, paused: true },
    { monitorId: 4, score: 71, grade: 'fair' },
  ],
  formulaVersion: 1,
  generatedAt: '2026-09-23T10:00:00+02:00',
};

function stubApi(health: { body: unknown; status?: number } = { body: HEALTH }) {
  const fetchMock = vi.fn((input: RequestInfo | URL) => {
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
    if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: MONITORS }));
    if (url.includes('action=health')) return Promise.resolve(json(health.body, health.status ?? 200));
    if (url.includes('action=discovered_services')) return Promise.resolve(json({ services: [] }));
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
  return { urls: () => fetchMock.mock.calls.map(([u]) => String(u)) };
}

function renderAt(path: string) {
  window.history.pushState({}, '', path);
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={[path]}>
          <InfrastructurePage />
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

beforeEach(() => {
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

describe('Infrastruktura: karty zařízení ve vzhledu NetPulse', () => {
  it('stavy jsou tlačítka s počty a klik na „Výpadek“ nechá jen zařízení mimo provoz', async () => {
    stubApi();
    renderAt('/infrastructure');
    await screen.findByRole('link', { name: /E-shop/ }, { timeout: 3000 });

    const states = screen.getByRole('group', { name: 'Stav' });
    const all = within(states).getByRole('button', { name: /Vše/ });
    expect(all.getAttribute('aria-pressed')).toBe('true');
    expect(all.textContent).toContain('4');
    const down = within(states).getByRole('button', { name: /Výpadek/ });
    expect(down.textContent).toContain('1');

    fireEvent.click(down);
    expect(await screen.findByRole('link', { name: /Databáze/ })).toBeTruthy();
    expect(screen.queryByRole('link', { name: /E-shop/ })).toBeNull();
    expect(
      within(states)
        .getByRole('button', { name: /Výpadek/ })
        .getAttribute('aria-pressed')
    ).toBe('true');
    // The filter is also said as a removable chip.
    fireEvent.click(screen.getByRole('button', { name: 'Zrušit filtr' }));
    expect(await screen.findByRole('link', { name: /E-shop/ })).toBeTruthy();
  });

  it('prstenec je skóre ze serveru; bez dat „—“, pozastavené zařízení žádný', async () => {
    const { urls } = stubApi();
    renderAt('/infrastructure');
    const shop = await screen.findByRole('link', { name: /E-shop/ }, { timeout: 3000 });
    expect(await within(shop).findByRole('img', { name: 'Zdraví: E-shop: 92 ze 100, Dobré' })).toBeTruthy();

    const db = screen.getByRole('link', { name: /Databáze/ });
    expect(within(db).getByRole('img', { name: 'Zdraví: Databáze: nedostatek dat' })).toBeTruthy();

    const paused = screen.getByRole('link', { name: /Záloha/ });
    expect(within(paused).queryByRole('img', { name: /Zdraví/ })).toBeNull();
    expect(urls().filter((u) => u.includes('action=health'))).toHaveLength(1);
  });

  it('selhané skóre je hlasité varování a karty jsou bez prstence, ne s pomlčkou', async () => {
    stubApi({ body: { error: 'health_unavailable' }, status: 500 });
    renderAt('/infrastructure');
    await screen.findByRole('link', { name: /E-shop/ }, { timeout: 3000 });

    expect(await screen.findByText('Skóre zdraví se nepodařilo načíst - karty jsou bez něj.')).toBeTruthy();
    expect(screen.queryAllByRole('img', { name: /Zdraví/ })).toHaveLength(0);
  });

  it('pruh zátěže jen pro změřené hodnoty; přes limit monitoru je varovný', async () => {
    stubApi();
    renderAt('/infrastructure');
    const vps = await screen.findByRole('link', { name: /VPS/ }, { timeout: 3000 });

    const cpu = within(vps).getByRole('meter', { name: 'CPU' });
    expect(cpu.getAttribute('aria-valuenow')).toBe('95');
    expect(cpu.querySelector('[data-slot="meter-fill"]')?.className).toContain('bg-warning');
    expect(within(vps).getByRole('meter', { name: 'RAM' }).getAttribute('aria-valuenow')).toBe('40');
    // The disk was not measured: no bar at 0, no row at all.
    expect(within(vps).queryByRole('meter', { name: 'Disk' })).toBeNull();

    // A web check has a response time and no load bars.
    const shop = screen.getByRole('link', { name: /E-shop/ });
    expect(within(shop).queryAllByRole('meter')).toHaveLength(0);
    expect(shop.textContent).toContain('184 ms');
  });
});
