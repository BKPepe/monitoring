// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { SettingsPage } from './settings';

/**
 * A key config.php defines wins over the database. Only the text fields knew:
 * a switch, a select or the links textarea took a change, the save said it
 * worked, and the reload brought the old value back (R132).
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

function serve(envLocked: string[]) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) =>
      Promise.resolve(
        String(input).includes('action=get_settings')
          ? json({
              settings: { escalation_enabled: '1', daily_reminder_enabled: '1', sms_gateway_type: '' },
              envLocked,
            })
          : json({})
      )
    )
  );
  render(
    <LanguageProvider>
      <MemoryRouter>
        <SettingsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
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

describe('Nastavení: zámek z config.php platí i mimo textová pole (R132)', () => {
  it('zamčený přepínač je neaktivní a nese ENV, nezamčený ne', async () => {
    serve(['escalation_enabled']);
    fireEvent.click(await screen.findByRole('button', { name: /Notifikace/ }));
    const escalation = (await screen.findByLabelText(/Zapnout eskalaci/)) as HTMLInputElement;
    expect(escalation.disabled).toBe(true);
    expect(escalation.closest('label')?.textContent).toContain('ENV');
    const reminder = screen.getByLabelText(/Posílat denní připomínku/) as HTMLInputElement;
    expect(reminder.disabled).toBe(false);
    expect(reminder.closest('label')?.textContent).not.toContain('ENV');
  });

  it('bez zámku jde přepínač měnit', async () => {
    serve([]);
    fireEvent.click(await screen.findByRole('button', { name: /Notifikace/ }));
    expect(((await screen.findByLabelText(/Zapnout eskalaci/)) as HTMLInputElement).disabled).toBe(false);
  });
});
