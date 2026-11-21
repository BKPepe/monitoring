// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { DataSourceBanner } from './data-source-banner';

const summary = {
  status: 'healthy',
  uptimePercent: 99.9,
  totalMonitors: 3,
  downMonitors: 0,
  agentsOnline: 1,
  agentsTotal: 1,
  avgLatencyMs: 42,
  lastUpdated: '2026-11-01T10:00:00+01:00',
  nodes: [],
};

let urls: string[] = [];
let status = 500;
beforeEach(() => {
  urls = [];
  status = 500;
  vi.stubGlobal(
    'fetch',
    vi.fn((input: RequestInfo | URL) => {
      urls.push(String(input));
      return Promise.resolve({
        ok: status < 400,
        status,
        json: () => Promise.resolve(status < 400 ? summary : { error: 'x' }),
      } as Response);
    })
  );
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('DataSourceBanner', () => {
  it('selhání souhrnu řekne důvod z požadavku a dá zkusit znovu; po úspěchu zmlkne', async () => {
    render(
      <LanguageProvider>
        <DataSourceBanner />
      </LanguageProvider>
    );

    const banner = await screen.findByRole('alert');
    expect(banner.textContent).toContain('Stav infrastruktury se nepodařilo načíst');
    expect(banner.textContent).toContain('HTTP 500');
    // One request: the separate 12 s probe of the same endpoint is gone (R183).
    expect(urls).toEqual(['/status/api.php?action=public_status']);

    status = 200;
    fireEvent.click(screen.getByRole('button', { name: 'Zkusit znovu' }));
    await vi.waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    expect(urls).toHaveLength(2);
  });
});
