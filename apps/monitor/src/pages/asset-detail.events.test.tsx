// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { TooltipProvider } from '@/components/ui/tooltip';

vi.mock('@/components/charts/metric-chart', () => ({ MetricChart: () => <div data-testid="chart" /> }));

import { AssetDetailPage } from './asset-detail';

/**
 * A failed action=events left the list empty, and the card then claimed that no
 * check had ever run. That is what an empty answer means, not a failed one.
 */
const SLOW = { timeout: 15_000 };
const json = (body: unknown, status = 200) =>
  ({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) }) as Response;

const web = {
  id: 6,
  name: 'Web',
  type: 'web',
  target: 'https://example.test',
  status: 'up',
  category: 'Weby',
  assetId: 6,
  lastCheck: '2026-09-23T11:59:00Z',
  lastStatusChange: '2026-09-22T12:00:00Z',
  details: {},
};

let events: () => Response = () => json({ events: [] });
let monitor: Record<string, unknown> = web;

beforeEach(() => {
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      const url = String(input);
      if (url.includes('action=monitors')) return Promise.resolve(json({ monitors: [monitor] }));
      if (url.includes('action=events')) return Promise.resolve(events());
      return Promise.resolve(json({}));
    })
  );
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: false,
      media: query,
      addEventListener: () => {},
      removeEventListener: () => {},
    }))
  );
  vi.stubGlobal(
    'ResizeObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    }
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  monitor = web;
});

function show() {
  render(
    <LanguageProvider>
      <TooltipProvider>
        <MemoryRouter initialEntries={['/infrastructure/6']}>
          <Routes>
            <Route path="/infrastructure/:id" element={<AssetDetailPage />} />
          </Routes>
        </MemoryRouter>
      </TooltipProvider>
    </LanguageProvider>
  );
}

describe('Detail: poslední kontroly', () => {
  it.each([
    ['refused', () => json({ error: 'database_unavailable' }, 500)],
    ['answered without a list', () => json({})],
  ])(
    'požadavek %s: řekne, že historie chybí',
    async (_name, answer) => {
      events = answer;
      show();
      expect(await screen.findByText('Historii kontrol se nepodařilo načíst.', undefined, SLOW)).toBeTruthy();
      expect(screen.queryByText('Zatím neproběhla žádná kontrola.')).toBeNull();
    },
    20_000
  );

  it('prázdná odpověď opravdu znamená, že kontrola neproběhla', async () => {
    events = () => json({ events: [] });
    show();
    expect(await screen.findByText('Zatím neproběhla žádná kontrola.', undefined, SLOW)).toBeTruthy();
    expect(screen.queryByText('Historii kontrol se nepodařilo načíst.')).toBeNull();
  }, 20_000);
});

describe('Detail: stav a poslední kontrola', () => {
  /** Opens the tab with the service box and returns the box's status line. */
  const statusBox = async () => {
    show();
    fireEvent.mouseDown(await screen.findByRole('tab', { name: /Služby/ }, SLOW));
    const title = await screen.findByText('Stav Služby');
    return within(title.parentElement as HTMLElement);
  };

  it.each([
    ['up', 'Aktivní', 'text-up'],
    ['down', 'Offline', 'text-down'],
    ['warning', 'Varování', 'text-warning'],
    ['maintenance', 'Údržba', 'text-muted-foreground'],
    ['unknown', 'Neznámý', 'text-muted-foreground'],
  ])(
    'stav %s je „%s“',
    async (status, label, tone) => {
      monitor = { ...web, status };
      const box = await statusBox();
      expect(box.getByText(label).className).toContain(tone);
    },
    20_000
  );

  it('monitor bez jediné kontroly netvrdí „Před chvílí“', async () => {
    monitor = { ...web, lastCheck: null };
    show();
    expect(await screen.findByText('Zatím nebyl zkontrolován', undefined, SLOW)).toBeTruthy();
    expect(screen.queryByText('Před chvílí')).toBeNull();
  }, 20_000);
});
