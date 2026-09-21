// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { SettingsPage } from './settings';

/**
 * The two fields of the daily reminder.
 *
 * The session is mocked: useSession shares one promise per module, and the
 * settings form renders at all only for an administrator.
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

let saved: Record<string, string> | null = null;

function serveSettings(settings: Record<string, string>) {
  saved = null;
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input);
      if (url.includes('action=save_settings')) {
        saved = JSON.parse(String(init?.body ?? '{}')).settings;
        return Promise.resolve(json({ success: true }));
      }
      if (url.includes('action=get_settings')) return Promise.resolve(json({ settings, envLocked: [] }));
      return Promise.resolve(json({}));
    })
  );
}

async function openNotifications() {
  render(
    <LanguageProvider>
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
  fireEvent.click(await screen.findByRole('button', { name: /Notifikace/ }));
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

beforeEach(() => {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
      addListener: () => {},
      removeListener: () => {},
      dispatchEvent: () => false,
      onchange: null,
    }))
  );
});

describe('Nastavení: denní připomínka', () => {
  it('obě pole mají pod sebou vysvětlení, proč existují', async () => {
    serveSettings({ daily_reminder_enabled: '1', daily_reminder_hour: '8' });
    await openNotifications();

    expect(await screen.findByText('Posílat denní připomínku')).toBeTruthy();
    expect(screen.getByText(/Výstraha odejde jen při změně stavu/)).toBeTruthy();
    expect(screen.getByText(/Když je všechno v pořádku, neodešle se nic/)).toBeTruthy();

    // SettingsField renders every non-secret field as a text input (it only
    // tells secrets apart), so the hour is checked by its value and its hint,
    // exactly like the escalation minutes next to it.
    expect(screen.getByDisplayValue('8')).toBeTruthy();
    expect(screen.getByText('Hodina odeslání (0–23)')).toBeTruthy();
    expect(screen.getByText(/nejvýš jednou denně/)).toBeTruthy();
  });

  it('nikdy neuložená připomínka se ukáže jako zapnutá, protože server ji posílá', async () => {
    // The server default is 1. Showing the checkbox as off would be a lie
    // about what the cron is doing tonight.
    serveSettings({});
    await openNotifications();

    const box = (await screen.findByLabelText(/Posílat denní připomínku/)) as HTMLInputElement;
    expect(box.checked).toBe(true);
  });

  it('vypnutá připomínka se ukáže jako vypnutá a uloží se jako 0', async () => {
    serveSettings({ daily_reminder_enabled: '0', daily_reminder_hour: '7' });
    await openNotifications();

    const box = (await screen.findByLabelText(/Posílat denní připomínku/)) as HTMLInputElement;
    expect(box.checked).toBe(false);

    fireEvent.click(box);
    fireEvent.change(screen.getByDisplayValue('7'), { target: { value: '6' } });
    fireEvent.click(screen.getByRole('button', { name: /Uložit všechna nastavení/ }));

    await waitFor(() => expect(saved).not.toBeNull());
    expect(saved!.daily_reminder_enabled).toBe('1');
    expect(saved!.daily_reminder_hour).toBe('6');
  });

  it('odkaz na protokol odchozích zpráv je hned u těch nastavení', async () => {
    serveSettings({});
    await openNotifications();

    const link = (await screen.findByRole('link', { name: /Otevřít protokol/ })) as HTMLAnchorElement;
    expect(link.getAttribute('href')).toContain('/outgoing-messages');
  });
});
