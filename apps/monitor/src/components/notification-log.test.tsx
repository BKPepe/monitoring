// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { NotificationLog } from './notification-log';

/**
 * The card on a monitor's detail - the view the owner read when "storage
 * recovered" was listed as an outage alert, green, while nothing arrived.
 */
vi.mock('@/api/use-session', () => ({
  useSession: () => ({ session: { authenticated: true }, loading: false, isAdmin: true }),
}));

const entry = (over: Record<string, unknown>) => ({
  id: 1,
  kind: 'alert',
  alertTone: 'bad',
  status: 'down',
  channel: 'email',
  recipient: 'admin@example.com',
  ok: true,
  delivery: 'sent',
  error: null,
  atIso: new Date().toISOString(),
  ...over,
});

function serve(entries: unknown[]) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ entries }) } as Response))
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('NotificationLog', () => {
  it('obnovení je obnovení a nepotvrzený WhatsApp to řekne i s důvodem', async () => {
    serve([
      entry({
        id: 3,
        channel: 'whatsapp',
        alertTone: 'good',
        status: 'storage_recovered',
        delivery: 'unknown',
        error: 'CallMeBot 210: queued to be sent later',
      }),
      entry({ id: 2, channel: 'email', alertTone: 'good', status: 'storage_recovered', delivery: 'sent' }),
      entry({ id: 1, channel: 'whatsapp', delivery: 'failed', ok: false, error: 'CallMeBot 208: Account paused' }),
    ]);
    render(
      <LanguageProvider>
        <NotificationLog monitorId={2} />
      </LanguageProvider>
    );

    expect(await screen.findByText('Nepotvrzeno: CallMeBot 210: queued to be sent later')).toBeTruthy();
    expect(screen.getAllByText('Obnovení')).toHaveLength(2);
    expect(screen.getAllByText('Výstraha výpadku')).toHaveLength(1);
    expect(screen.getByText('CallMeBot 208: Account paused')).toBeTruthy();
    // Both counts are in the header: a failure and an unconfirmed message.
    expect(screen.getByText('1 neodesláno')).toBeTruthy();
    expect(screen.getByText('1 nepotvrzeno')).toBeTruthy();
  });

  it('řádek ze staršího serveru bez výsledku se nebarví jako odeslaný', async () => {
    serve([
      {
        id: 5,
        kind: 'alert',
        status: 'down',
        channel: 'whatsapp',
        recipient: null,
        ok: true,
        error: null,
        atIso: new Date().toISOString(),
      },
    ]);
    render(
      <LanguageProvider>
        <NotificationLog monitorId={2} />
      </LanguageProvider>
    );

    expect(await screen.findByText('Nepotvrzeno')).toBeTruthy();
    expect(screen.getByText('1 nepotvrzeno')).toBeTruthy();
  });
});
