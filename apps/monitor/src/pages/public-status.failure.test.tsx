// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicStatusPage } from './public-status';

/**
 * W1-A1: a failed request is never an all-clear on the public page.
 *
 * usePublicStatus shares answers for ten seconds at module level, so every
 * test moves the clock a minute on - otherwise a success cached by one test
 * would answer the next one.
 */
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const failAll = () => json({ error: 'database_unavailable' }, 500);

const monitor = (id: number, name: string, status: string, extra: Record<string, unknown> = {}) => ({
  id,
  name,
  type: 'web',
  status,
  category: 'Weby',
  responseMs: 120,
  lastCheck: '2026-09-23T10:00:00+02:00',
  lastStatusChange: null,
  details: null,
  assetId: id,
  cpu: null,
  ram: null,
  hdd: null,
  ...extra,
});

function answering(monitors: unknown[]) {
  return (url: string): Response => {
    if (url.includes('action=monitors')) return json({ monitors });
    if (url.includes('action=public_status'))
      return json({
        totalMonitors: monitors.length,
        downMonitors: (monitors as { status: string }[]).filter((m) => m.status === 'down').length,
        uptimePercent: 99.9,
        avgLatencyMs: 100,
        nodes: [],
      });
    if (url.includes('action=regions')) return json({ regions: [{ location: 'prague', successRate: 100 }] });
    if (url.includes('action=events')) return json({ events: [] });
    if (url.includes('action=incidents')) return json({ incidents: [], manualIncidents: [] });
    if (url.includes('action=daily_uptime')) return json({ series: {} });
    if (url.includes('action=uptime_windows')) return json({ windows: {} });
    if (url.includes('action=ui_config')) return json({ siteTitle: '', customNavLinks: [] });
    return json({});
  };
}

let clock = Date.UTC(2026, 8, 23, 8, 0, 0);
beforeEach(() => {
  clock += 60 * 60_000;
  vi.spyOn(Date, 'now').mockImplementation(() => clock);
  // Vite defines the build version; the footer prints it.
  vi.stubGlobal('__APP_VERSION__', '0.0.0-test');
  // Node hides jsdom's storage behind a flag, and jsdom has no matchMedia;
  // the theme toggle reads both.
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }))
  );
});
afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function renderPage() {
  return render(
    <LanguageProvider>
      <MemoryRouter initialEntries={['/public']}>
        <PublicStatusPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

/** The tile whose label is `label` - its value sits in the same card. */
function tileValue(label: string): string {
  const labelEl = screen.getByText(label, { selector: 'p' });
  return labelEl.parentElement?.querySelectorAll('p')[1]?.textContent ?? '';
}

describe('Veřejná stránka: selhání není „vše v pořádku“ (W1-A1)', () => {
  it('všechna API vrací 500: nadpis „Stav se nepodařilo zjistit“, dlaždice s pomlčkou, žádné „online“', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => failAll())
    );
    renderPage();

    expect(await screen.findByText('Stav se nepodařilo zjistit')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
    expect(tileValue('Online')).toBe('—');
    // A green dash would still read as "fine".
    const onlineValue = screen.getByText('Online', { selector: 'p' }).parentElement?.querySelectorAll('p')[1];
    expect(onlineValue?.className).not.toContain('text-up');
    expect(tileValue('Míst měření')).toBe('—');
    expect(tileValue('Mimo provoz')).toBe('—');
    expect(screen.getByText('Seznam služeb se nepodařilo načíst.')).toBeTruthy();
    // No endless spinner either: the list says it failed.
    expect(screen.queryByText('Načítám služby…')).toBeNull();
  });

  it('„Zkusit znovu“ po obnovení API ukáže skutečný stav', async () => {
    let healthy = false;
    const ok = answering([monitor(1, 'E-shop', 'up')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => (healthy ? ok(String(input)) : failAll()))
    );
    renderPage();
    await screen.findByText('Stav se nepodařilo zjistit');
    healthy = true;
    fireEvent.click(screen.getByRole('button', { name: 'Zkusit znovu' }));

    expect(await screen.findByText('Všechny systémy jsou online')).toBeTruthy();
    expect(screen.queryByText('Stav se nepodařilo zjistit')).toBeNull();
    await waitFor(() => expect(tileValue('Online')).toBe('1'));
  });

  it('zhoršená nebo mlčící služba: „částečně omezen“, nikdy „všechny online“', async () => {
    const api = answering([
      monitor(1, 'E-shop', 'up'),
      monitor(2, 'Web', 'up', { agentSilent: true }),
      monitor(3, 'Discord', 'warning'),
    ]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    expect(await screen.findByText('Provoz je částečně omezen')).toBeTruthy();
    expect(screen.getByText('2 služby hlásí zhoršení nebo neznámý stav')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
  });

  it('výpadek má přednost před zhoršením', async () => {
    const api = answering([monitor(1, 'E-shop', 'down'), monitor(3, 'Discord', 'warning')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    expect(await screen.findByText('1 služba mimo provoz')).toBeTruthy();
    expect(screen.queryByText('Provoz je částečně omezen')).toBeNull();
  });

  it('jen neznámý stav (nikdy nehlášeno) také není „všechny online“', async () => {
    const api = answering([monitor(1, 'E-shop', 'up'), monitor(2, 'Nový web', 'unknown')]);
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => api(String(input)))
    );
    renderPage();

    expect(await screen.findByText('Provoz je částečně omezen')).toBeTruthy();
    expect(screen.queryByText('Všechny systémy jsou online')).toBeNull();
  });
});
