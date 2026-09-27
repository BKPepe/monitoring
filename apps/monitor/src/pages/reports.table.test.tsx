// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ReportsPage } from './reports';

/**
 * W2-7: the SLA report is one table, worst first, with downtime against the
 * goal's allowance and the last 30 days per row; a failed period is an error,
 * never the "check that cron runs" hint over the previous period's numbers.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const row = (id: number, name: string, uptimePercent: number | null, extra: Record<string, unknown> = {}) => ({
  id,
  name,
  target: `https://${name.toLowerCase()}.example.test`,
  type: 'WEB',
  currentStatus: 'up',
  uptimePercent,
  outageMinutes: 0,
  totalChecks: 900,
  measuredMinutes: uptimePercent === null ? null : 43_200,
  budgetMinutes: uptimePercent === null ? null : 43,
  incidentCount: 0,
  lastOutage: null,
  mttrSec: null,
  lastStatusChange: null,
  ...extra,
});

const REPORT = {
  slaGoal: 99.9,
  overallUptime: 98.7,
  totalOutageMinutes: 1100,
  overallMttrSec: 600,
  since: null,
  windowStart: '2026-08-25',
  monitors: [
    row(1, 'Blog', 99.99, { outageMinutes: 4 }),
    row(2, 'Nový', null),
    row(3, 'Shop', 97.5, { outageMinutes: 1080, incidentCount: 2 }),
    row(4, 'Wiki', 99.95, { outageMinutes: 20 }),
  ],
};

const STRIPS = {
  series: {
    3: [
      { date: '22.9.', day: '2026-09-22', status: 'down', uptimePct: 80, downMin: 290 },
      { date: '23.9.', day: '2026-09-23', status: 'partial', uptimePct: 100, coveragePct: 58 },
    ],
  },
};

function stubApi(sla: (days: number) => Response, strips: () => Response = () => json(STRIPS)) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input), 'http://localhost');
      const action = url.searchParams.get('action');
      if (action === 'sla_report') return sla(Number(url.searchParams.get('days')));
      if (action === 'daily_uptime') return strips();
      return json({ authenticated: false });
    })
  );
}

function renderPage() {
  return render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter>
          <ReportsPage />
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const rowOf = (id: number) => document.querySelector(`[data-sla-row="${id}"]`) as HTMLElement;

describe('SLA výkaz jako jedna tabulka (W2-7)', () => {
  it('nejhorší nahoře, výpadek proti rozpočtu, barva jen pod cílem, 30 dní v řádku', async () => {
    stubApi(() => json(REPORT));
    renderPage();

    await screen.findByRole('button', { name: 'Shop' });
    const order = [...document.querySelectorAll('[data-sla-row]')].map((r) => r.getAttribute('data-sla-row'));
    // 97.5 < 99.95 < 99.99, and the unmeasured one last - unknown is not best.
    expect(order).toEqual(['3', '4', '1', '2']);

    const shop = rowOf(3);
    expect(shop.textContent).toContain('97,5 %');
    expect(within(shop).getByText('97,5 %').className).toContain('text-down');
    // 1080 minutes against an allowance of 43: over budget, and said in hours.
    expect(within(shop).getByText('18 h').className).toContain('text-down');
    expect(shop.textContent).toContain('z 43 min');
    expect(shop.textContent).toContain('2');
    expect(shop.querySelectorAll('[data-day-status]')).toHaveLength(2);
    expect(shop.querySelector('[data-day-status="partial"]')).not.toBeNull();

    const wiki = rowOf(4);
    expect(within(wiki).getByText('99,95 %').className).not.toContain('text-down');
    expect(within(wiki).getByText('20 min').className).not.toContain('text-down');
    expect(rowOf(2).textContent).toContain('Bez měření');

    // The header carries the scale once; the old per-row "90 % … 100 %" is gone.
    expect(screen.getByRole('columnheader', { name: 'SLA (cíl 99,9 %)' })).toBeTruthy();
    expect(screen.queryByText('90 %')).toBeNull();
    // The figures are neutral unless they miss the goal.
    expect(screen.getByText('Splňuje SLA')).toBeTruthy();
  });

  it('název otevře podrobnosti řádku', async () => {
    stubApi(() => json(REPORT));
    renderPage();

    const toggle = await screen.findByRole('button', { name: 'Shop' });
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    const detail = document.getElementById('sla-detail-3') as HTMLElement;
    expect(detail.className).not.toContain('hidden');
    expect(
      within(detail)
        .getByRole('link', { name: /Otevřít detail/ })
        .getAttribute('href')
    ).toBe('/infrastructure/3');
  });

  it('selhané přepnutí období je chyba s opakováním, ne rada o cronu nad starými čísly', async () => {
    let fail = true;
    stubApi((days) =>
      days === 90 && fail
        ? json({ error: 'sla_report_unavailable', message: 'Nepodařilo se sestavit SLA report.' }, 500)
        : json(REPORT)
    );
    renderPage();
    await screen.findByRole('button', { name: 'Shop' });

    fireEvent.click(screen.getByRole('button', { name: 'Kvartál' }));
    const error = await screen.findByText(/SLA výkaz za období Kvartál se nepodařilo sestavit/);
    expect(error.textContent).toContain('Nepodařilo se sestavit SLA report.');
    // Nothing of the 30-day report stays on screen under the error.
    expect(screen.queryByText('Celkové plnění SLA')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Shop' })).toBeNull();
    expect(screen.queryByText(/cron/)).toBeNull();

    fail = false;
    fireEvent.click(
      within(error.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Zkusit znovu' })
    );
    expect(await screen.findByRole('button', { name: 'Shop' })).toBeTruthy();
  });

  it('denní pásy selhaly: tabulka platí, chybějící rozpad po dnech je řečený nahlas', async () => {
    stubApi(
      () => json(REPORT),
      () => json({ error: 'daily_uptime_unavailable' }, 500)
    );
    renderPage();

    expect(await screen.findByText(/Denní pásy se nepodařilo načíst/)).toBeTruthy();
    expect(rowOf(3).textContent).toContain('97,5 %');
    expect(rowOf(3).querySelectorAll('[data-day-status]')).toHaveLength(0);
  });
});
