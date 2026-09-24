// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ReportsPage } from './reports';

/**
 * sla_report sends three decimals and never 100 for a period with an outage
 * (99.999 for 5 s in 30 days); the page printed two, "100.00 %", both in the
 * overall figure and on the monitor's badge.
 */
const json = (body: unknown) =>
  ({ ok: true, status: 200, json: () => Promise.resolve(body), text: () => Promise.resolve('') }) as Response;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Výkazy: SLA s pětisekundovým výpadkem', () => {
  it('celkem i u monitoru 99.99 %, ne 100.00 %', async () => {
    const row = {
      id: 2,
      name: 'E-shop',
      target: 'https://shop.example.test',
      type: 'web',
      currentStatus: 'up',
      uptimePercent: 99.999,
      outageMinutes: 0,
      totalChecks: 43200,
      lastOutage: null,
      mttrSec: 5,
      lastStatusChange: null,
    };
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) =>
        Promise.resolve(
          String(input).includes('action=sla_report')
            ? json({ monitors: [row], slaGoal: 99.9, overallUptime: 99.999 })
            : json({})
        )
      )
    );
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
    expect(screen.getAllByText('99.99 %')).toHaveLength(2);
    expect(screen.queryByText('100.00 %')).toBeNull();
  });
});
