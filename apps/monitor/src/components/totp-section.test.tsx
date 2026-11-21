// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { TotpSection } from './totp-section';

/**
 * Enabling 2FA asks for the password as well as the code: a stolen session
 * must not be able to put its own authenticator on the account.
 */
vi.mock('qrcode', () => ({ toDataURL: () => Promise.resolve('data:image/png;base64,AA==') }));

const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

function stubFetch(confirm: () => Response) {
  const confirms: Record<string, string>[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
      if (String(input).includes('action=totp_setup')) {
        return Promise.resolve(json({ secret: 'ABC', otpauthUri: 'otpauth://totp/x?secret=ABC' }));
      }
      confirms.push(JSON.parse(String(init?.body)));
      return Promise.resolve(confirm());
    })
  );
  return confirms;
}

async function openSetup(onChanged = () => {}) {
  render(
    <LanguageProvider>
      <TotpSection enabled={false} onChanged={onChanged} />
    </LanguageProvider>
  );
  fireEvent.click(screen.getByRole('button', { name: 'Zapnout 2FA' }));
  const code = await screen.findByPlaceholderText('123 456');
  const password = document.querySelector('input[type="password"]') as HTMLInputElement;
  const submit = screen.getByRole('button', { name: 'Potvrdit a zapnout' }) as HTMLButtonElement;
  return { code, password, submit };
}

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('TotpSection: zapnutí 2FA', () => {
  it('bez hesla nejde potvrdit, s heslem jde kód i heslo na server', async () => {
    const confirms = stubFetch(() => json({ success: true, recoveryCodes: ['aaaaa-bbbbb'] }));
    const onChanged = vi.fn();
    const { code, password, submit } = await openSetup(onChanged);

    fireEvent.change(code, { target: { value: '123456' } });
    expect(submit.disabled).toBe(true);
    fireEvent.change(password, { target: { value: 'moje-heslo' } });
    expect(submit.disabled).toBe(false);

    fireEvent.click(submit);
    await screen.findByText('aaaaa-bbbbb');
    expect(confirms).toEqual([{ code: '123456', password: 'moje-heslo' }]);
    expect(onChanged).toHaveBeenCalledOnce();
  });

  it('když server heslo odmítne, řekne to a nastavení zůstane otevřené', async () => {
    stubFetch(() => json({ error: 'Nesprávné heslo - 2FA zůstává vypnuté.' }, 400));
    const onChanged = vi.fn();
    const { code, password, submit } = await openSetup(onChanged);
    fireEvent.change(code, { target: { value: '123456' } });
    fireEvent.change(password, { target: { value: 'spatne' } });
    fireEvent.click(submit);

    await screen.findByText('Nesprávné heslo - 2FA zůstává vypnuté.');
    await waitFor(() => expect(submit.disabled).toBe(false));
    expect(screen.getByPlaceholderText('123 456')).toBeTruthy();
    expect(onChanged).not.toHaveBeenCalled();
  });

  it('zrušení heslo zapomene', async () => {
    stubFetch(() => json({}));
    const { password } = await openSetup();
    fireEvent.change(password, { target: { value: 'moje-heslo' } });
    fireEvent.click(screen.getByRole('button', { name: 'Zrušit' }));

    fireEvent.click(screen.getByRole('button', { name: 'Zapnout 2FA' }));
    await screen.findByPlaceholderText('123 456');
    expect((document.querySelector('input[type="password"]') as HTMLInputElement).value).toBe('');
  });
});
