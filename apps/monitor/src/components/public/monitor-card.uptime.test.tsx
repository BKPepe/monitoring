// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicMonitorCard, type PublicMonitor } from './monitor-card';

/**
 * The 30-day figure on the public card. The server sends three decimals and
 * never 100 for a period with an outage (99.999 for 5 s in 30 days); the card
 * printed two, rounded half-up, and showed "100.00 %" all the same.
 */
const MONITOR: PublicMonitor = {
  id: 2,
  name: 'E-shop',
  type: 'web',
  status: 'up',
  category: null,
  responseMs: 120,
  lastCheck: null,
  lastStatusChange: null,
  details: null,
  assetId: null,
  cpu: null,
  ram: null,
  hdd: null,
};

function renderCard(uptimePct: number | null) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ authenticated: false }) } as Response)
    )
  );
  render(
    <LanguageProvider>
      <MemoryRouter>
        <ul>
          <PublicMonitorCard monitor={MONITOR} uptime={[]} uptimePct={uptimePct} />
        </ul>
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Veřejná karta: dostupnost za 30 dní', () => {
  it('měsíc s pětisekundovým výpadkem (99,999 %) ukáže 99.99 %, ne 100.00 %', () => {
    renderCard(99.999);
    expect(screen.getByText('99.99 %')).toBeTruthy();
    expect(screen.queryByText('100.00 %')).toBeNull();
  });

  it('měsíc bez výpadku 100.00 %, neměřený pomlčka', () => {
    renderCard(100);
    expect(screen.getByText('100.00 %')).toBeTruthy();
    cleanup();
    renderCard(null);
    expect(screen.getByText('—')).toBeTruthy();
  });
});
