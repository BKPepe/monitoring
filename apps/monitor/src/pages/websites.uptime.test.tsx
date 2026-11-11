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
    expect(screen.getAllByText('99,99 %')).toHaveLength(2);
    expect(screen.queryByText('100,00 %')).toBeNull();
  });

  it('cíl 99,95 % se ukáže celý, ne jako 99,9 % (R294)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=session')) return Promise.resolve(json({ authenticated: false }));
        if (url.includes('action=websites_overview'))
          return Promise.resolve(
            json({ slaGoal: 99.95, monitors: { 4: { sla7: 99.99, sla30: 99.9, sla365: null, measuredSince: null } } })
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
    expect(await screen.findByText(/cíl 99,95/)).toBeTruthy();
  });

  it.each([
    [1, 'dnes'],
    [3, '3 dny'],
    [47, '47 dní'],
  ])('dlouhé okno pokryté %i dny se jmenuje „%s“, ne „rok“ (R324)', async (days, label) => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=session')) return Promise.resolve(json({ authenticated: false }));
        if (url.includes('action=websites_overview'))
          return Promise.resolve(
            json({
              slaGoal: 99.9,
              monitors: { 4: { sla7: 100, sla30: 100, sla365: 100, measuredSince: null, longTermDays: days } },
            })
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
    expect(await screen.findByText(label)).toBeTruthy();
    expect(screen.queryByText('rok')).toBeNull();
  });
});

describe('Weby: limit pro certifikáty je serverový (R68)', () => {
  it('certifikát za 20 dní při limitu 14 není „brzy vyprší“, ani když přehled selže', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=session')) return Promise.resolve(json({ authenticated: false }));
        if (url.includes('action=websites_overview'))
          return Promise.resolve({ ok: false, status: 500, json: () => Promise.resolve({ error: 'DB' }) } as Response);
        if (url.includes('action=monitors'))
          return Promise.resolve(
            json({
              sslAlertDays: 14,
              monitors: [
                {
                  id: 4,
                  name: 'Blog',
                  type: 'web',
                  status: 'up',
                  target: 'https://blog.example.test',
                  details: { ssl_days_remaining: 20 },
                },
              ],
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
    expect(await screen.findByText(/Nejbližší expirace za 20 dní/)).toBeTruthy();
    expect(screen.queryByText(/vyprší do/)).toBeNull();
  });
});
