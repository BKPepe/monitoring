// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { SettingsPage } from './settings';

/**
 * W1-D1: saving Settings must not switch the router/agent alerts off.
 *
 * get_settings answers '' for every key that was never saved. The form used
 * to show that as an unticked box and post the '' back, and the server reads
 * a stored '' as "off" - so one click on "Save" silenced WAN, LTE, disk,
 * firewall and DNS alerts, and sent agent alerts to every user.
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

// Exactly what a fresh install's get_settings sends for the four switches.
const NEVER_SAVED = {
  agent_notifications_enabled: '',
  agent_notify_admin_only: '',
  daily_reminder_enabled: '',
  escalation_enabled: '',
};

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

const agentBox = () => screen.findByLabelText(/Upozornění z agentů: WAN, LTE/) as Promise<HTMLInputElement>;
const adminOnlyBox = () => screen.getByLabelText(/doručovat pouze administrátorům/) as HTMLInputElement;

async function save() {
  fireEvent.click(screen.getByRole('button', { name: /Uložit všechna nastavení/ }));
  await waitFor(() => expect(saved).not.toBeNull());
  return saved!;
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

describe('Nastavení: upozornění z agentů (W1-D1)', () => {
  it('štítek říká, co všechno přepínač vypíná, nejen limity CPU/RAM/HDD', async () => {
    serveSettings({});
    await openNotifications();

    expect(
      await screen.findByText('Upozornění z agentů: WAN, LTE, disky, firewall, DNS a limity CPU/RAM/HDD')
    ).toBeTruthy();
    expect(screen.queryByText('Upozorňovat na překročení limitů CPU/RAM/HDD')).toBeNull();
  });

  it('nikdy neuložené přepínače ukazují výchozí stav serveru', async () => {
    serveSettings(NEVER_SAVED);
    await openNotifications();

    expect((await agentBox()).checked).toBe(true);
    expect(adminOnlyBox().checked).toBe(true);
    expect((screen.getByLabelText(/Posílat denní připomínku/) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText(/Zapnout eskalaci/) as HTMLInputElement).checked).toBe(false);
  });

  it('čerstvá instalace + jedno uložení nechá každý přepínač upozornění beze změny', async () => {
    serveSettings(NEVER_SAVED);
    await openNotifications();
    await agentBox();

    const body = await save();
    // Never '' - the server reads a stored '' as "off".
    expect(body.agent_notifications_enabled).toBe('1');
    expect(body.agent_notify_admin_only).toBe('1');
    expect(body.daily_reminder_enabled).toBe('1');
    expect(body.escalation_enabled).toBe('0');
  });

  it('vypnuté přepínače zůstanou vypnuté a uloží se jako 0', async () => {
    serveSettings({ ...NEVER_SAVED, agent_notifications_enabled: '0', agent_notify_admin_only: '0' });
    await openNotifications();

    expect((await agentBox()).checked).toBe(false);
    expect(adminOnlyBox().checked).toBe(false);

    const body = await save();
    expect(body.agent_notifications_enabled).toBe('0');
    expect(body.agent_notify_admin_only).toBe('0');
  });

  it('odškrtnutí zapnutého přepínače se uloží jako 0', async () => {
    serveSettings({ agent_notifications_enabled: '1' });
    await openNotifications();

    fireEvent.click(await agentBox());
    const body = await save();
    expect(body.agent_notifications_enabled).toBe('0');
  });
});
