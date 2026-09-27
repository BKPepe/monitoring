// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, type RouteObject } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';
import { routes } from '@/routes';

/**
 * W2-9: the device list without its detail pane. A row opens the device page,
 * Online is a dot (every other state keeps its word), the time in state says
 * what it is, and `?type=` narrows the list - the removed Služby page
 * (owner decision 5.8) redirects there.
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
  monitor(1, 'E-shop', 'web', 'up', { sinceStatusChangeSeconds: 40 * 86400 }),
  monitor(2, 'Databáze', 'agent_service', 'down', { sinceStatusChangeSeconds: 600 }),
  monitor(3, 'Nový server', 'vps', 'unknown', { lastCheck: null }),
];

// The session is a module-level cache, so every test in this file is the same
// admin; the viewer case lives in infrastructure.add-viewer.test.tsx.
function stubApi() {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
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
      if (url.includes('action=discovered_services')) return Promise.resolve(json({ services: [] }));
      return Promise.resolve(json({}));
    })
  );
}

function renderAt(path: string) {
  // The page reads its one-shot deep links (?add=, ?edit=) from window.location.
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

describe('Infrastruktura: seznam bez postranního panelu (W2-9)', () => {
  it('řádek vede na stránku zařízení; Online je tečka, jiný stav má slovo a doba ve stavu popisek', async () => {
    stubApi();
    renderAt('/infrastructure');
    const shop = await screen.findByRole('link', { name: /E-shop/ }, { timeout: 3000 });
    expect(shop.getAttribute('href')).toBe('/infrastructure/1');
    // Online: a dot with an accessible name, no word in the row text.
    expect(within(shop).getByRole('img', { name: 'Online' })).toBeTruthy();
    expect(shop.textContent).not.toContain('Online');
    expect(shop.textContent).toContain('ve stavu 40 d');

    const db = screen.getByRole('link', { name: /Databáze/ });
    expect(db.textContent).toContain('Výpadek');
    expect(db.textContent).toContain('ve stavu 10 min');

    // Never reported = waiting for data, not a silent agent (5.10).
    const fresh = screen.getByRole('link', { name: /Nový server/ });
    expect(fresh.textContent).toContain('Čeká na první data');
    expect(fresh.textContent).not.toContain('Agent mlčí');
  });

  it('?type=agent_service zúží seznam a filtr jde jedním klikem zrušit', async () => {
    stubApi();
    renderAt('/infrastructure?type=agent_service');
    await screen.findByRole('link', { name: /Databáze/ }, { timeout: 3000 });
    expect(screen.queryByRole('link', { name: /E-shop/ })).toBeNull();
    // The filter is visible twice over: its type pill is pressed, and a chip
    // names it with the way to remove it.
    const types = screen.getByRole('group', { name: 'Typ zařízení' });
    expect(within(types).getByRole('button', { pressed: true }).textContent).toContain('Služba pod agentem');
    expect(screen.getByRole('button', { name: 'Zrušit filtr' }).parentElement?.textContent).toContain(
      'Služba pod agentem'
    );

    fireEvent.click(screen.getByRole('button', { name: 'Zrušit filtr' }));
    expect(await screen.findByRole('link', { name: /E-shop/ })).toBeTruthy();
  });

  it('neznámý ?type= nefiltruje - prázdný seznam by lhal', async () => {
    stubApi();
    renderAt('/infrastructure?type=sluzby');
    expect(await screen.findByRole('link', { name: /E-shop/ }, { timeout: 3000 })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Zrušit filtr' })).toBeNull();
  });

  it('?add=1 z prvního spuštění otevře správci prázdný formulář nového monitoru', async () => {
    stubApi();
    renderAt('/infrastructure?add=1');
    expect(await screen.findByRole('dialog', {}, { timeout: 3000 })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Archivovat' })).toBeNull();
  });
});

describe('Zrušená stránka Služby (rozhodnutí 5.8)', () => {
  it('/services přesměruje na seznam zúžený na služby pod agentem', () => {
    const children = routes.flatMap((r: RouteObject) => r.children ?? []);
    const services = children.find((r) => r.path === 'services');
    const element = services?.element as { props: { to: string; replace: boolean } } | undefined;
    expect(element?.props.to).toBe('/infrastructure?type=agent_service');
    expect(element?.props.replace).toBe(true);
  });
});
