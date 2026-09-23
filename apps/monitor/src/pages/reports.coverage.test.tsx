// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ReportsPage } from './reports';

/**
 * W1-B2: "Rok" used to be 30 days of raw logs under a one-year label. The
 * server now reads the daily rollup and says where its data starts; the page
 * states it next to the period.
 */
const json = (body: unknown) =>
  ({ ok: true, status: 200, json: () => Promise.resolve(body), text: () => Promise.resolve('') }) as Response;

const ROW = {
  id: 2,
  name: 'E-shop',
  target: 'https://shop.example.test',
  type: 'web',
  currentStatus: 'up',
  uptimePercent: 99.2,
  outageMinutes: 40,
  totalChecks: 900,
  lastOutage: null,
  mttrSec: null,
  lastStatusChange: null,
};

function stubApi() {
  const fetchMock = vi.fn((input: RequestInfo | URL) => {
    const url = new URL(String(input), 'http://localhost');
    if (url.searchParams.get('action') === 'sla_report') {
      const days = Number(url.searchParams.get('days'));
      const windowStart = days === 365 ? '2025-09-24' : days === 90 ? '2026-06-26' : '2026-08-25';
      return Promise.resolve(
        json({ monitors: [ROW], slaGoal: 99.9, overallUptime: 99.2, since: '2026-08-12', windowStart })
      );
    }
    return Promise.resolve(json({}));
  });
  vi.stubGlobal('fetch', fetchMock);
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 8, 23, 12, 0));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Výkazy: rok a kvartál říkají, od kdy mají data (W1-B2)', () => {
  it('30 dní je pokrytých bez poznámky; Rok ukáže „Rok (data od 12. 8.)“', async () => {
    stubApi();
    render(
      <LanguageProvider>
        <TooltipProvider>
          <MemoryRouter>
            <ReportsPage />
          </MemoryRouter>
        </TooltipProvider>
      </LanguageProvider>
    );

    expect((await screen.findAllByText('E-shop')).length).toBeGreaterThan(0);
    expect(screen.queryByTestId('reports-coverage')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Rok' }));
    const note = await screen.findByTestId('reports-coverage');
    expect(note.textContent).toContain('Rok (data od 12. 8.)');
  });
});
