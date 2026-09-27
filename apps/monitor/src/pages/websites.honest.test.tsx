// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { WebsitesPage } from './websites';

/**
 * W2-10 (honest-14): the card prints the HTTP code the latest check recorded,
 * "—" when it recorded none, and its own label for every state. It used to
 * print "200 OK" for anything that was not down.
 */
const json = (body: unknown, status = 200) =>
  ({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
  }) as Response;

const web = (id: number, name: string, status: string, extra: Record<string, unknown> = {}) => ({
  id,
  name,
  type: 'web',
  target: `https://${name.toLowerCase()}.example.test`,
  status,
  lastCheck: '2026-09-23 10:00:00',
  responseMs: 120,
  details: {},
  ...extra,
});

function stubApi(overview: Response) {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=session'))
        return Promise.resolve(
          json({ authenticated: true, user: { id: 1, username: 'a', role: 'admin' }, csrfToken: 't' })
        );
      if (url.includes('action=monitors'))
        return Promise.resolve(
          json({
            monitors: [
              web(1, 'Eshop', 'up'),
              web(2, 'Blog', 'maintenance'),
              web(3, 'Forum', 'down', { responseMs: null }),
              web(4, 'Novy', 'unknown', { lastCheck: null, responseMs: null }),
            ],
          })
        );
      if (url.includes('action=websites_overview')) return Promise.resolve(overview);
      return Promise.resolve(json({}));
    })
  );
}

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <WebsitesPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

const card = (name: string) => screen.getByRole('heading', { name }).closest('.bg-card') as HTMLElement;

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Weby: HTTP kód a stav bez výmyslů (W2-10)', () => {
  it('ukáže zaznamenaný kód, pomlčku bez kódu a vlastní popisek každého stavu', async () => {
    stubApi(
      json({
        slaGoal: 99.9,
        sslAlertDays: 14,
        monitors: {
          1: { sla7: 100, sla30: 100, sla365: null, measuredSince: null, httpStatusCode: 200, httpCheckedAt: null },
          2: { sla7: null, sla30: null, sla365: null, measuredSince: null, httpStatusCode: null, httpCheckedAt: null },
          3: { sla7: 90, sla30: 95, sla365: null, measuredSince: null, httpStatusCode: 503, httpCheckedAt: null },
        },
      })
    );
    renderPage();
    await screen.findByRole('heading', { name: 'Eshop' }, { timeout: 3000 });

    const shop = card('Eshop');
    expect(within(shop).getByText('Online')).toBeTruthy();
    expect(await within(shop).findByText('200')).toBeTruthy();

    // Maintenance is maintenance - not "Online", not "200 OK".
    const blog = card('Blog');
    expect(within(blog).getByText('Údržba')).toBeTruthy();
    expect(blog.textContent).not.toContain('200 OK');
    expect(within(blog).getByTitle('Poslední kontrola žádný kód nezaznamenala').textContent).toBe('—');

    const forum = card('Forum');
    expect(within(forum).getByText('Výpadek')).toBeTruthy();
    expect(within(forum).getByText('503').className).toContain('text-down');

    expect(within(card('Novy')).getByText('Čeká na první data')).toBeTruthy();
    expect(document.body.textContent).not.toContain('OFFLINE');
  });

  it('podnadpis neslibuje cPanel statistiky a dostupnost počítá jen změřené weby', async () => {
    stubApi(json({ slaGoal: 99.9, monitors: {} }));
    renderPage();
    await screen.findByRole('heading', { name: 'Eshop' }, { timeout: 3000 });
    expect(screen.getByRole('heading', { level: 1 }).textContent).not.toContain('cPanel');
    expect(document.body.textContent).not.toContain('cPanel statistik');
    // Eshop up, Forum down; Blog (maintenance) and Novy (no data) are not a verdict.
    expect(screen.getByText('1 z 2 dostupných právě teď')).toBeTruthy();
  });

  it('když selže sám seznam webů, dlaždice ukážou pomlčky, ne vymyšlené nuly (V-06)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes('action=session'))
          return Promise.resolve(json({ authenticated: true, user: { id: 1, username: 'a', role: 'admin' } }));
        if (url.includes('action=monitors')) return Promise.resolve(json({ error: 'db' }, 500));
        return Promise.resolve(json({ error: 'db', message: 'Databáze neodpovídá' }, 500));
      })
    );
    renderPage();
    expect(await screen.findByText('Seznam webů se nepodařilo načíst.', {}, { timeout: 3000 })).toBeTruthy();
    const text = document.body.textContent ?? '';
    expect(text).not.toContain('0 z 0 dostupných');
    expect(text).not.toContain('Z 0 odpovídajících');
    expect(text).not.toContain('Stav webů níže je aktuální');
    const tiles = Array.from(document.querySelectorAll('[data-slot="stat-block"]'));
    expect(tiles.length).toBe(4);
    for (const tile of tiles) expect(tile.textContent).toContain('—');
  });

  it('selhání přehledu je nahlas vidět, ne tichá prázdná SLA', async () => {
    stubApi(json({ error: 'db', message: 'Databáze neodpovídá' }, 500));
    renderPage();
    expect(
      await screen.findByText(/SLA a HTTP kódy se nepodařilo načíst \(Databáze neodpovídá\)/, {}, { timeout: 3000 })
    ).toBeTruthy();
  });
});
