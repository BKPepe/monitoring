// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { UptimeHeatmap } from './uptime-heatmap';

/**
 * A red day with 30 s of outage is 99.965 %; toFixed(1) captioned it
 * "100.0 % Uptime" in the tooltip and in the cell's accessible name.
 */
afterEach(cleanup);

describe('Pás dostupnosti: den s výpadkem nikdy 100 %', () => {
  it('den s třicetisekundovým výpadkem 99.9 %, den bez výpadku 100.0 %', () => {
    render(
      <LanguageProvider>
        <MemoryRouter>
          <UptimeHeatmap
            rows={[
              {
                monitorId: 3,
                name: 'E-shop',
                days: [
                  { date: '2026-09-20', status: 'up', uptimePct: 100 },
                  { date: '2026-09-21', status: 'down', uptimePct: 99.965 },
                ],
              },
            ]}
          />
        </MemoryRouter>
      </LanguageProvider>
    );

    const outage = screen.getByRole('link', { name: /2026-09-21/ });
    expect(outage.getAttribute('aria-label')).toMatch(/ · 99\.9 %$/);
    expect(outage.getAttribute('title')).toMatch(/ · 99\.9 %$/);
    expect(screen.getByText('99.9 % Uptime')).toBeTruthy();
    expect(screen.getByText('100.0 % Uptime')).toBeTruthy();
    expect(screen.getAllByText(/ Uptime$/)).toHaveLength(2);
  });
});
