// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { DigestCard } from './digest-card';

/**
 * The digest card on Reports: the preview comes from api.php (Q5), so the app
 * no longer opens the legacy admin.php for it; a send shows the server's own
 * answer, a failure included.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function renderCard() {
  render(
    <LanguageProvider>
      <DigestCard />
    </LanguageProvider>
  );
}

describe('Souhrn na stránce Reporty', () => {
  it('náhled týdenního i měsíčního souhrnu otevírá api.php, ne admin.php', () => {
    renderCard();
    const links = screen.getAllByRole('link', { name: 'Náhled' });
    expect(links.map((a) => a.getAttribute('href'))).toEqual([
      '/status/api.php?action=digest_preview&period=weekly',
      '/status/api.php?action=digest_preview&period=monthly',
    ]);
    for (const a of links) {
      expect(a.getAttribute('target')).toBe('_blank');
    }
  });

  it('odeslání ukáže odpověď serveru', async () => {
    const fetchMock = vi.fn(async () => json({ success: true, message: 'Měsíční digest byl úspěšně odeslán.' }));
    vi.stubGlobal('fetch', fetchMock);
    renderCard();

    fireEvent.click(screen.getByRole('button', { name: 'Odeslat Měsíční Digest' }));
    expect((await screen.findByRole('status')).textContent).toBe('Měsíční digest byl úspěšně odeslán.');
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/status/api.php?action=send_digest');
    expect(init.method).toBe('POST');
    expect(JSON.parse(String(init.body))).toEqual({ period: 'monthly' });
  });

  it('neúspěch řekne slovy serveru, ne jako úspěch', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => json({ error: 'Přístup odepřen — vyžadována role administrátora.' }, 403))
    );
    renderCard();

    fireEvent.click(screen.getByRole('button', { name: 'Odeslat Týdenní Digest' }));
    expect((await screen.findByRole('alert')).textContent).toBe('Přístup odepřen — vyžadována role administrátora.');
    expect(screen.queryByRole('status')).toBeNull();
  });
});
