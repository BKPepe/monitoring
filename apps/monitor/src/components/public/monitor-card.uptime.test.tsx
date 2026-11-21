// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicMonitorCard, type PublicMonitor, type UptimeWindows } from './monitor-card';

/**
 * The 30-day figure on the public card. The server sends three decimals and
 * never 100 for a period with an outage (99.999 for 5 s in 30 days); the card
 * printed two, rounded half-up, and showed "100.00 %" all the same. Every
 * figure is written the way the page language writes numbers (CR-13).
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

function renderCard(uptimePct: number | null, windows: UptimeWindows | null = null, lang: 'cs' | 'en' = 'cs') {
  vi.stubGlobal('localStorage', {
    getItem: (key: string) => (key === 'bk_lang' ? lang : null),
    setItem: () => {},
    removeItem: () => {},
  });
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
          <PublicMonitorCard monitor={MONITOR} uptime={[]} uptimePct={uptimePct} windows={windows} />
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
  it('měsíc s pětisekundovým výpadkem (99,999 %) ukáže 99,99 %, ne 100,00 %', () => {
    renderCard(99.999);
    expect(screen.getByText('99,99 %')).toBeTruthy();
    expect(screen.queryByText('100,00 %')).toBeNull();
  });

  it('měsíc bez výpadku 100,00 %, neměřený pomlčka', () => {
    renderCard(100);
    expect(screen.getByText('100,00 %')).toBeTruthy();
    cleanup();
    renderCard(null);
    expect(screen.getByText('—')).toBeTruthy();
  });

  it('anglicky desetinná tečka', () => {
    renderCard(99.999, null, 'en');
    expect(screen.getByText('99.99 %')).toBeTruthy();
  });
});

describe('Veřejná karta: okna 24 h / 7 dní / 30 dní / 90 dní (PUB-14)', () => {
  it('česky s desetinnou čárkou, výpadek se nezaokrouhlí na 100, neměřené okno pomlčka', () => {
    renderCard(99.983, { d1: 100, d7: 99.999, d30: 99.983, d90: null });
    fireEvent.click(screen.getByRole('button', { name: 'Rozbalit detail' }));
    expect(screen.getByText('100,00 %')).toBeTruthy();
    expect(screen.getAllByText('99,98 %')).toHaveLength(2);
    expect(screen.getByText('99,99 %')).toBeTruthy();
    expect(screen.queryByText(/99\.98/)).toBeNull();
    expect(screen.getByText('—')).toBeTruthy();
  });
});
