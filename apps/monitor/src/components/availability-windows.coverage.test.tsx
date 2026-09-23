// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { AvailabilityWindows } from './availability-windows';

/**
 * W1-B2: the 90-day window reads the daily rollup, and on a monitor whose
 * data starts later the label says from when - "90 dní (data od 12. 8.)" -
 * instead of passing six weeks off as ninety days.
 */
const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

const WINDOW_START = { d7: '2026-09-17', d30: '2026-08-25', d90: '2026-06-26' };

function stub(body: unknown) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve(json(body)))
  );
}

beforeEach(() => {
  // Only the clock the date label reads; the fetch promises keep running.
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 8, 23, 12, 0));
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Dostupnost: pokrytí dlouhých oken (W1-B2)', () => {
  it('data od 12. 8.: 90 dní to říká, 30 a 7 dní jsou pokryté a poznámku nemají', async () => {
    stub({
      windows: { '6': { d1: 100, d7: 99.5, d30: 98.2, d90: 97.1, since: '2026-08-12' } },
      windowStart: WINDOW_START,
    });
    render(
      <LanguageProvider>
        <AvailabilityWindows monitorId={6} />
      </LanguageProvider>
    );

    expect(await screen.findByText('(data od 12. 8.)')).toBeTruthy();
    expect(screen.getAllByText(/data od/)).toHaveLength(1);
    expect(screen.getByText('90 dní', { exact: false }).textContent).toBe('90 dní (data od 12. 8.)');
    expect(screen.getByText('97.10 %')).toBeTruthy();
    expect(screen.getByText('98.20 %')).toBeTruthy();
    expect(screen.getByText(/Podíl času, kdy služba běžela/)).toBeTruthy();
  });

  it('starší server bez since/windowStart: čísla bez poznámky, nic domyšleného', async () => {
    stub({ windows: { '6': { d1: 100, d7: 100, d30: 100, d90: 100 } } });
    render(
      <LanguageProvider>
        <AvailabilityWindows monitorId={6} />
      </LanguageProvider>
    );

    expect(await screen.findByText('90 dní')).toBeTruthy();
    expect(screen.queryByText(/data od/)).toBeNull();
  });
});
