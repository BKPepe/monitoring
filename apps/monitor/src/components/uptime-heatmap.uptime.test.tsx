// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { UptimeHeatmap } from './uptime-heatmap';

/**
 * A red day with 30 s of outage is 99.965 %; rounding to one decimal
 * captioned it "100 %" in the day's detail and in the cell's accessible name.
 */
afterEach(cleanup);

describe('Pás dostupnosti: den s výpadkem nikdy 100 %', () => {
  it('den s třicetisekundovým výpadkem 99,9 %, den bez výpadku 100 %', () => {
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
    expect(outage.getAttribute('aria-label')).toMatch(/ · 99,9 %$/);
    const clean = screen.getByRole('link', { name: /2026-09-20/ });
    expect(clean.getAttribute('aria-label')).toMatch(/ · 100 %$/);

    fireEvent.mouseEnter(outage);
    expect(screen.getByRole('tooltip').textContent).toContain('Dostupnost 99,9 %');
    fireEvent.mouseEnter(clean);
    expect(screen.getByRole('tooltip').textContent).toContain('Dostupnost 100 %');
  });
});

describe('Pás dostupnosti na telefonu (V-08)', () => {
  it('úzký box otevře na nejnovějším dni, ne na nejstarším', () => {
    const proto = HTMLElement.prototype;
    const sw = Object.getOwnPropertyDescriptor(proto, 'scrollWidth');
    const cw = Object.getOwnPropertyDescriptor(proto, 'clientWidth');
    Object.defineProperty(proto, 'scrollWidth', { configurable: true, get: () => 600 });
    Object.defineProperty(proto, 'clientWidth', { configurable: true, get: () => 300 });
    try {
      render(
        <LanguageProvider>
          <MemoryRouter>
            <UptimeHeatmap
              rows={[{ monitorId: 3, name: 'E-shop', days: [{ date: '2026-09-21', status: 'down', uptimePct: 90 }] }]}
            />
          </MemoryRouter>
        </LanguageProvider>
      );
      expect((screen.getByRole('region') as HTMLElement).scrollLeft).toBe(600);
    } finally {
      if (sw) Object.defineProperty(proto, 'scrollWidth', sw);
      else delete (proto as unknown as Record<string, unknown>).scrollWidth;
      if (cw) Object.defineProperty(proto, 'clientWidth', cw);
      else delete (proto as unknown as Record<string, unknown>).clientWidth;
    }
  });
});
