// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { MetricsTokenActions } from './metrics-token-actions';

/**
 * W2-7: the Prometheus token moved to Settings → Integrace. A working token
 * is replaced only after a confirmation, and the new address is shown once.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function renderActions(configured: boolean, onGenerated = vi.fn()) {
  render(
    <LanguageProvider>
      <MetricsTokenActions configured={configured} onGenerated={onGenerated} />
    </LanguageProvider>
  );
  return onGenerated;
}

describe('Prometheus token v Nastavení (W2-7)', () => {
  it('nastavený token se nahradí až po potvrzení; nová adresa je vidět jednou', async () => {
    const fetchMock = vi.fn(async () => json({ success: true, metricsToken: 'abcd1234' }));
    vi.stubGlobal('fetch', fetchMock);
    const onGenerated = renderActions(true);

    fireEvent.click(screen.getByRole('button', { name: 'Vygenerovat nový token' }));
    expect(fetchMock).not.toHaveBeenCalled();
    expect(screen.getByText(/Starý token přestane platit hned/)).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Vygenerovat nový' }));
    expect(await screen.findByText(/status\/metrics\.php\?token=abcd1234$/)).toBeTruthy();
    expect(String((fetchMock.mock.calls[0] as unknown[])[0])).toContain('action=generate_metrics_token');
    expect(onGenerated).toHaveBeenCalledWith('abcd1234');
  });

  it('bez tokenu se aktivuje jedním klikem; chyba serveru se řekne', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json({ error: 'Přístup odepřen — vyžadována role administrátora.' }, 403))
    );
    const onGenerated = renderActions(false);

    fireEvent.click(screen.getByRole('button', { name: 'Aktivovat Prometheus token (1-klik)' }));
    expect(await screen.findByText('Přístup odepřen — vyžadována role administrátora.')).toBeTruthy();
    expect(onGenerated).not.toHaveBeenCalled();
  });
});
