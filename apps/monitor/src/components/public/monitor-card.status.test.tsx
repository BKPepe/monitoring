// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { PublicMonitorCard, type PublicMonitor } from './monitor-card';

/**
 * W2-5 / C-11 on the public card: the status dot speaks the shared status
 * vocabulary - maintenance blue, "waiting for the first data" a dashed ring
 * rather than the amber of an agent gone silent. The state is a word in a
 * chip, the colour only repeats it.
 */
const base: PublicMonitor = {
  id: 2,
  name: 'E-shop',
  type: 'web',
  status: 'up',
  category: null,
  responseMs: 120,
  lastCheck: '2026-09-23T10:00:00+02:00',
  lastStatusChange: null,
  details: null,
  assetId: null,
  cpu: null,
  ram: null,
  hdd: null,
};

function renderCard(monitor: Partial<PublicMonitor>) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ authenticated: false }) } as Response)
    )
  );
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <ul>
          <PublicMonitorCard monitor={{ ...base, ...monitor }} uptime={[]} uptimePct={100} windows={null} />
        </ul>
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

/** The state chip of the row: the word, its tone and its outline. */
function statePill(word: string): HTMLElement {
  const pill = screen.getByText(word, { selector: '[data-slot="pill"]' });
  return pill;
}

describe('Veřejná karta: štítek stavu mluví sdíleným slovníkem (PUB-11)', () => {
  it('údržba modře jedním štítkem, výpadek červeně', () => {
    renderCard({ status: 'maintenance', statusKey: 'maintenance' });
    expect(statePill('Údržba').getAttribute('data-tone')).toBe('info');
    expect(statePill('Údržba').className).toContain('text-info');
    // One fact once: no second maintenance badge beside the chip.
    expect(screen.getAllByText('Údržba')).toHaveLength(1);
    cleanup();

    renderCard({ status: 'down', statusKey: 'down' });
    expect(statePill('Výpadek').getAttribute('data-tone')).toBe('down');
    expect(statePill('Výpadek').className).toContain('bg-down');
  });

  it('první data čekají: šedý přerušovaný štítek, žádná žlutá', () => {
    renderCard({ status: 'unknown', lastCheck: null, statusKey: 'unknown_new' });
    const waiting = statePill('Čeká na první data');
    expect(waiting.className).toContain('border-dashed');
    expect(waiting.getAttribute('data-tone')).toBe('neutral');
    expect(waiting.className).not.toContain('bg-warning');
  });

  it('agent mlčí: žlutě, i když poslední hlášení bylo „online“; starší server bez klíče se dopočítá', () => {
    renderCard({ status: 'up', statusKey: 'up', agentSilent: true });
    expect(statePill('Agent mlčí').getAttribute('data-tone')).toBe('warning');
    expect(screen.queryByText('Online')).toBeNull();
    cleanup();

    // No statusKey (older server) and never checked: waiting, not silent.
    renderCard({ status: 'unknown', lastCheck: null });
    expect(statePill('Čeká na první data')).toBeTruthy();
  });
});
