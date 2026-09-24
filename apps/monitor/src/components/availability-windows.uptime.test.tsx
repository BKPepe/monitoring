// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { AvailabilityWindows } from './availability-windows';

/**
 * Five seconds of outage in 30 days is 99.9998 %; the server sends 99.999,
 * and toFixed(2) printed "100.00 %" next to the outage.
 */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Dostupnost: okno s výpadkem nikdy neukáže 100 %', () => {
  it('7 a 30 dní s pětisekundovým výpadkem 99.99 %, den bez výpadku 100.00 %', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve({
          ok: true,
          status: 200,
          json: () => Promise.resolve({ windows: { '6': { d1: 100, d7: 99.999, d30: 99.999, d90: null } } }),
        } as Response)
      )
    );
    render(
      <LanguageProvider>
        <AvailabilityWindows monitorId={6} />
      </LanguageProvider>
    );

    expect(await screen.findByText('100.00 %')).toBeTruthy();
    expect(screen.getAllByText('99.99 %')).toHaveLength(2);
  });
});
