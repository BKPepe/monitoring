// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { InsightsPage } from './insights';

/**
 * The common check of set A covers /app/insights too: a failed feed is loud,
 * with a retry, and never reads as "nothing to act on" (W1-A, W2-8).
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const FAILED = 'Zjištění se nepodařilo načíst. Nevíme, jestli je vše v pořádku.';

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <InsightsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('Zjištění: selhání není „vše v pořádku“ (W1-A, W2-8)', () => {
  it('findings vrací 500: chyba s opakováním, žádné „nic k řešení“; opakování načte seznam', async () => {
    let up = false;
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) =>
        Promise.resolve(
          String(input).includes('action=findings') && up
            ? json({
                findings: [],
                total: 0,
                offset: 0,
                counts: { critical: 0, warning: 0, info: 0 },
                devices: [],
                monitorsChecked: 3,
                muted: [],
                canMute: false,
                sourceErrors: [],
                insightsCachedAt: null,
                generatedAt: '2026-09-23T10:00:00+02:00',
              })
            : json({ error: 'findings_unavailable', message: 'x' }, 500)
        )
      )
    );
    renderPage();

    const error = await screen.findByText(FAILED);
    expect(screen.queryByText(/Nic k řešení/)).toBeNull();

    up = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect(await screen.findByText('Nic k řešení - žádné zjištění.')).toBeTruthy();
    expect(screen.queryByText(FAILED)).toBeNull();
  });
});
