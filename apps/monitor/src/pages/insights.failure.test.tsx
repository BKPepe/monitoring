// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { InsightsPage } from './insights';

/**
 * The common check of set A covers /app/insights too: its cards carry static
 * all-clear copy, so a failed monitor list must not reach them.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const WEB_ALL_OK = 'Všechny webové stránky a HTTP endpointy odpovídají v pořádku.';
const FAILED = 'Přehled se nepodařilo načíst. Stav serverů a webů teď není známý.';

const MONITORS = [{ id: 1, name: 'E-shop', type: 'web', status: 'up', target: 'https://example.test' }];

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

describe('Insights: selhání není „vše v pořádku“ (W1-A)', () => {
  it('seznam monitorů vrací 500: chyba s opakováním, žádné karty ani „v pořádku“; opakování načte karty', async () => {
    let up = false;
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) =>
        Promise.resolve(
          String(input).includes('action=monitors') && up
            ? json({ monitors: MONITORS })
            : json({ error: 'database_unavailable' }, 500)
        )
      )
    );
    renderPage();

    const error = await screen.findByText(FAILED);
    expect(screen.queryByText(WEB_ALL_OK)).toBeNull();

    up = true;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect(
      await screen.findByText('Žádný sledovaný web není mimo provoz a žádný přečtený certifikát nevypršel.')
    ).toBeTruthy();
    expect(screen.queryByText(FAILED)).toBeNull();
  });
});
