// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import type { ApiMonitor } from '@/api/app-api';
import type { ScoredHealth } from '@/components/health-deductions';
import { DeviceCards } from './dashboard-devices';

/**
 * CORR-1: a device card's ring is the server's score. "—" means the server
 * had too little data, so it must never stand for "still loading", "the
 * request failed" or "paused".
 */
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const router = {
  id: 5,
  name: 'Router',
  type: 'openwrt',
  target: '',
  status: 'up',
  statusKey: 'up',
  category: 'Síť',
  lastCheck: '2026-09-27 06:00:00',
  lastStatusChange: null,
  responseMs: null,
  cpu: 12,
  ram: 40,
  hdd: null,
  details: {},
} as unknown as ApiMonitor;

const scored = (over: Partial<ScoredHealth>): ScoredHealth =>
  ({
    score: 91,
    grade: 'good',
    formulaVersion: 1,
    measuredWeight: 60,
    measuredComponents: 4,
    components: [],
    deductions: [],
    ...over,
  }) as ScoredHealth;

function renderCards(health: Map<number, ScoredHealth> | null, healthLoading = false) {
  // The cards fetch a CPU trace each; it never answers here.
  vi.stubGlobal(
    'fetch',
    vi.fn(() => new Promise(() => {}))
  );
  return render(
    <MemoryRouter>
      <LanguageProvider>
        <DeviceCards devices={[router]} health={health} healthLoading={healthLoading} />
      </LanguageProvider>
    </MemoryRouter>
  );
}

describe('Routery a servery: prstenec zdraví (CORR-1)', () => {
  it('při načítání je místo prstence zástupný tvar, ne „—“', () => {
    const { container } = renderCards(null, true);
    expect(screen.queryByRole('img', { name: /Zdraví: Router/ })).toBeNull();
    expect(container.querySelector('[data-slot="skeleton"], .animate-pulse')).toBeTruthy();
  });

  it('po chybě dotazu karta prstenec nekreslí vůbec', () => {
    renderCards(null, false);
    expect(screen.queryByRole('img', { name: /Zdraví: Router/ })).toBeNull();
    expect(screen.queryByText(/nedostatek dat/)).toBeNull();
  });

  it('pozastavený monitor nemá prstenec', () => {
    renderCards(new Map([[5, scored({ paused: true, score: null, grade: null })]]));
    expect(screen.queryByRole('img', { name: /Zdraví: Router/ })).toBeNull();
  });

  it('skóre ze serveru se vykreslí, „—“ jen když ho server nespočítal', () => {
    renderCards(new Map([[5, scored({})]]));
    expect(screen.getByRole('img', { name: /Zdraví: Router/ }).textContent).toContain('91');
    cleanup();
    renderCards(new Map([[5, scored({ score: null, grade: null })]]));
    expect(screen.getByRole('img', { name: /Zdraví: Router/ }).getAttribute('aria-label')).toContain('nedostatek dat');
  });
});
