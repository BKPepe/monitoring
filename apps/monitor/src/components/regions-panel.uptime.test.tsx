// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { RegionsPanel } from './regions-panel';

/**
 * One failed check among 20 001 is 99.995 %; toFixed(2) printed the place's
 * success rate as "100.00 %" beside "1 selhání".
 */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Měřicí místa: úspěšnost s jediným selháním', () => {
  it('jedna selhaná kontrola z 20 001 je 99.99 %, ne 100.00 %', async () => {
    const region = {
      location: 'Praha',
      checks: 20001,
      upChecks: 20000,
      downChecks: 1,
      successRate: (20000 / 20001) * 100,
      avgResponseMs: 120,
      monitors: 1,
      lastSeen: null,
    };
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ regions: [region] }) } as Response)
      )
    );
    render(
      <LanguageProvider>
        <RegionsPanel />
      </LanguageProvider>
    );

    expect(await screen.findByText('99.99 %')).toBeTruthy();
    expect(screen.queryByText('100.00 %')).toBeNull();
  });
});
