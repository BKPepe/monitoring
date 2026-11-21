// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { InfrastructurePage } from './infrastructure';

/**
 * Editing a heartbeat monitor saves its stored interval and grace back. The
 * form never loaded them and saved its own 60/5 minutes, so renaming a daily
 * backup's monitor turned it into an hourly one that went down every night.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

function heartbeat(extra: Record<string, unknown>) {
  return {
    id: 6,
    name: 'Noční záloha',
    type: 'heartbeat',
    target: '',
    status: 'up',
    category: null,
    assetId: 6,
    assetName: 'Noční záloha',
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

function renderPage() {
  render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter>
          <InfrastructurePage />
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

async function openEditor() {
  renderPage();
  const interval = (await screen.findByPlaceholderText('60', {}, { timeout: 3000 })) as HTMLInputElement;
  const grace = screen.getByPlaceholderText('5') as HTMLInputElement;
  return { interval, grace };
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

describe('Heartbeat: úprava monitoru zachová uložený interval a toleranci', () => {
  it('denní úloha se ukáže v minutách a uloží se zpět beze změny', async () => {
    const saves = stubApi(heartbeat({ heartbeatIntervalSecs: 86400, heartbeatGraceSecs: 600 }));
    const { interval, grace } = await openEditor();
    expect(interval.value).toBe('1440');
    expect(grace.value).toBe('10');

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].heartbeat_interval).toBe(86400);
    expect(saves[0].heartbeat_grace).toBe(600);
  });

  it('nenastavená tolerance zůstane null, ne nula', async () => {
    const saves = stubApi(heartbeat({ heartbeatIntervalSecs: 3600, heartbeatGraceSecs: null }));
    const { grace } = await openEditor();
    expect(grace.value).toBe('');

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].heartbeat_interval).toBe(3600);
    expect(saves[0].heartbeat_grace).toBeNull();
  });

  it('sekundy mimo celou minutu se zaokrouhlí nahoru, lhůta se nezkrátí', async () => {
    const saves = stubApi(heartbeat({ heartbeatIntervalSecs: 89, heartbeatGraceSecs: 89 }));
    const { interval, grace } = await openEditor();
    expect(interval.value).toBe('2');
    expect(grace.value).toBe('2');

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].heartbeat_interval).toBe(120);
    expect(saves[0].heartbeat_grace).toBe(120);
  });

  it('přidání po úpravě jiného monitoru začne na 60/5, ne s prázdnou tolerancí', async () => {
    // A web monitor has no heartbeat stored, so its edit empties both fields.
    const saves = stubApi(
      heartbeat({ type: 'web', target: 'https://example.com', heartbeatIntervalSecs: null, heartbeatGraceSecs: null })
    );
    renderPage();
    await screen.findByText('Úprava monitoru #6', {}, { timeout: 3000 });
    fireEvent.click(screen.getByRole('button', { name: 'Zrušit' }));
    fireEvent.click(screen.getByRole('button', { name: /Přidat nový monitor/ }));
    fireEvent.click(screen.getByRole('button', { name: /Heartbeat/ }));
    const interval = screen.getByPlaceholderText('60') as HTMLInputElement;
    const grace = screen.getByPlaceholderText('5') as HTMLInputElement;
    expect(interval.value).toBe('60');
    expect(grace.value).toBe('5');

    fireEvent.change(screen.getByPlaceholderText(/^Např\./), { target: { value: 'Nová záloha' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].heartbeat_interval).toBe(3600);
    expect(saves[0].heartbeat_grace).toBe(300);
  });

  it('upravený monitor přepnutý na Heartbeat začne na 60/5, tolerance pod šedou 5 není prázdná', async () => {
    const saves = stubApi(
      heartbeat({ type: 'web', target: 'https://example.com', heartbeatIntervalSecs: null, heartbeatGraceSecs: null })
    );
    renderPage();
    await screen.findByText('Úprava monitoru #6', {}, { timeout: 3000 });
    fireEvent.click(screen.getByRole('button', { name: /Heartbeat/ }));
    const interval = screen.getByPlaceholderText('60') as HTMLInputElement;
    const grace = screen.getByPlaceholderText('5') as HTMLInputElement;
    expect(interval.value).toBe('60');
    expect(grace.value).toBe('5');

    fireEvent.change(interval, { target: { value: '1440' } });
    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].heartbeat_interval).toBe(86400);
    expect(saves[0].heartbeat_grace).toBe(300);
  });

  it('znovu kliknutý Heartbeat u heartbeatu bez tolerance žádnou nevymyslí', async () => {
    const saves = stubApi(heartbeat({ heartbeatIntervalSecs: 3600, heartbeatGraceSecs: null }));
    const { grace } = await openEditor();
    fireEvent.click(screen.getByRole('button', { name: /Heartbeat/ }));
    expect(grace.value).toBe('');

    fireEvent.click(screen.getByRole('button', { name: 'Uložit monitor a nastavení' }));
    await waitFor(() => expect(saves).toHaveLength(1), SAVE_WAIT);
    expect(saves[0].heartbeat_grace).toBeNull();
  });
});
