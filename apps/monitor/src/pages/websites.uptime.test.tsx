// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { WebsitesPage } from './websites';

/**
 * The SLA windows on a web card: 99.999 (5 s of outage in 30 days) printed
 * "100.00 %". A perfect window stays "100 %".
 */
const json = (body: unknown) =>
  ({ ok: true, status: 200, json: () => Promise.resolve(body), text: () => Promise.resolve('') }) as Response;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Weby: SLA okna s pětisekundovým výpadkem', () => {
  it('7 a 30 dní 99.99 %, rok bez výpadku 100 %', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=session')) return Promise.resolve(json({ authenticated: false }));
        if (url.includes('action=websites_overview'))
          return Promise.resolve(
            json({ slaGoal: 99.9, monitors: { 4: { sla7: 99.999, sla30: 99.999, sla365: 100, measuredSince: null } } })
          );
        if (url.includes('action=monitors'))
          return Promise.resolve(
            json({
              monitors: [{ id: 4, name: 'Blog', type: 'web', status: 'up', target: 'https://blog.example.test' }],
            })
          );
        return Promise.resolve(json({}));
      })
    );
    render(
      <LanguageProvider>
        <MemoryRouter>
          <WebsitesPage />
        </MemoryRouter>
      </LanguageProvider>
    );

    expect(await screen.findByText('100 %')).toBeTruthy();
    expect(screen.getAllByText('99.99 %')).toHaveLength(2);
    expect(screen.queryByText('100.00 %')).toBeNull();
  });
});
