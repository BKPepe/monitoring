// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { appApi } from '@/api/app-api';
import { SettingsPage } from './settings';

/**
 * W2-11 Settings → Notifikace: one row per channel (set up / not set up,
 * Test) that opens on a click; agent limits and the registration token live
 * under Obecné. The session is mocked, as in the other settings tests.
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

function serveSettings(settings: Record<string, string>) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=get_settings')) return Promise.resolve(json({ settings, envLocked: [] }));
      return Promise.resolve(json({}));
    })
  );
}

async function openTab(name: RegExp) {
  render(
    <LanguageProvider>
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
  fireEvent.click(await screen.findByRole('button', { name }));
}

const row = (name: string) => screen.getByRole('button', { name: new RegExp(`^${name}`) }).closest('li') as HTMLElement;

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

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('Nastavení → Notifikace: kanály po řádcích (W2-11)', () => {
  it('řádek říká, jestli je kanál nastavený, a pole ukáže až po kliknutí', async () => {
    serveSettings({ discord_webhook_url: 'https://discord.example.test/api/webhooks/1', smtp_host: '' });
    await openTab(/Notifikace/);

    expect(within(row('Discord Webhook')).getByText('Nastaveno')).toBeTruthy();
    expect(within(row('Slack Webhook')).getByText('Nenastaveno')).toBeTruthy();
    // No SMTP host is not "off": the server sends through PHP mail().
    expect(within(row('E-mail \\(SMTP\\)')).getByText('Výchozí PHP mail()')).toBeTruthy();

    expect(screen.queryByLabelText('Discord Webhook URL')).toBeNull();
    const toggle = within(row('Discord Webhook')).getAllByRole('button')[0];
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(screen.getByDisplayValue('https://discord.example.test/api/webhooks/1')).toBeTruthy();
  });

  it('čerstvá instalace: typ SMS brány bez přihlašovacích údajů není „Nastaveno“ (V-04)', async () => {
    serveSettings({ sms_gateway_type: 'twilio', twilio_sid: '', twilio_token: '', twilio_from: '', smtp_host: '' });
    await openTab(/Notifikace/);
    expect(within(row('SMS Gateway Notifikace')).getByText('Nenastaveno')).toBeTruthy();
    expect(within(row('E-mail \\(SMTP\\)')).queryByText('Nastaveno')).toBeNull();
  });

  it('SMTP host bez hesla je výchozí mail(), ne „Nastaveno“ (R297)', async () => {
    serveSettings({ smtp_host: 'smtp.example.test', smtp_user: 'monitor@example.test', smtp_pass: '' });
    await openTab(/Notifikace/);
    expect(within(row('E-mail \\(SMTP\\)')).getByText('Výchozí PHP mail()')).toBeTruthy();
    cleanup();
    serveSettings({ smtp_host: 'smtp.example.test', smtp_user: 'monitor@example.test', smtp_pass: '••••••1234' });
    await openTab(/Notifikace/);
    expect(within(row('E-mail \\(SMTP\\)')).getByText('Nastaveno')).toBeTruthy();
  });

  it('pole SMTP účtu říká, že je i přihlášením, nejen odesílatelem (R298)', async () => {
    serveSettings({ smtp_host: 'smtp.example.test' });
    await openTab(/Notifikace/);
    fireEvent.click(within(row('E-mail \\(SMTP\\)')).getAllByRole('button')[0]);
    expect(screen.getByText('SMTP účet a odesílatel (e-mail)')).toBeTruthy();
    expect(screen.getByText(/Přihlašuje se jím k SMTP serveru/)).toBeTruthy();
    expect(screen.queryByText('Odesílatel zpráv (From E-mail)')).toBeNull();
  });

  it('brána „Žádná“ se zbylými údaji Twilia není „Nastaveno“ (R296)', async () => {
    serveSettings({ sms_gateway_type: '', twilio_sid: 'AC1', twilio_token: 'tok', twilio_from: '+420700000000' });
    await openTab(/Notifikace/);
    expect(within(row('SMS Gateway Notifikace')).getByText('Nenastaveno')).toBeTruthy();
  });

  it('Test je jen u nastaveného kanálu a výsledek se ukáže na jeho řádku', async () => {
    const test = vi.spyOn(appApi, 'testNotification').mockResolvedValue({ ok: false, message: 'HTTP 404' });
    serveSettings({ discord_webhook_url: 'https://discord.example.test/api/webhooks/1' });
    await openTab(/Notifikace/);

    expect(within(row('Slack Webhook')).queryByRole('button', { name: 'Test' })).toBeNull();
    fireEvent.click(within(row('Discord Webhook')).getByRole('button', { name: 'Test' }));
    await waitFor(() => expect(test).toHaveBeenCalledWith('discord'));
    const status = await within(row('Discord Webhook')).findByRole('status');
    expect(status.textContent).toContain('Test selhal.');
    expect(status.textContent).toContain('HTTP 404');
  });

  it('limit agenta a registrační token jsou v Obecné, ne v Notifikacích', async () => {
    serveSettings({});
    await openTab(/Notifikace/);
    expect(await screen.findByText('Kanály upozornění')).toBeTruthy();
    expect(screen.queryByText('Token pro auto-registraci agentů')).toBeNull();
    expect(screen.queryByText(/Časový limit pro označení agenta za offline/)).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /Obecné/ }));
    expect(screen.getByText('Token pro auto-registraci agentů')).toBeTruthy();
    expect(screen.getByText(/Časový limit pro označení agenta za offline/)).toBeTruthy();
  });
});
