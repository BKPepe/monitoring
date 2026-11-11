// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { LanguageProvider } from '@/context/language-context';
import { InsightsPage } from './insights';

/**
 * A source of findings that failed leaves the list incomplete. The tiles said
 * "nic takového teď není" and the footer "Ostatní zařízení bez nálezů" over
 * it, an all-clear nobody measured (R64).
 */
const json = (body: unknown) => ({ ok: true, status: 200, json: () => Promise.resolve(body) }) as Response;

function renderWith(sourceErrors: unknown[]) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve(
        json({
          findings: [],
          total: 0,
          offset: 0,
          counts: { critical: 0, warning: 0, info: 0 },
          devices: [],
          monitorsChecked: 3,
          muted: [],
          canMute: false,
          sourceErrors,
          insightsCachedAt: null,
          generatedAt: '2026-11-11T10:00:00+01:00',
        })
      )
    )
  );
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <InsightsPage />
      </MemoryRouter>
    </LanguageProvider>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Zjištění: selhaný zdroj není „nic takového“ (R64)', () => {
  it('se selhaným zdrojem dlaždice ani patička netvrdí, že nic není', async () => {
    renderWith([{ source: 'router', monitorId: null, error: 'x' }]);
    expect(await screen.findByText(/Seznam není úplný/)).toBeTruthy();
    expect(screen.queryByText('nic takového teď není')).toBeNull();
    expect(screen.getAllByText('seznam není úplný')).toHaveLength(3);
    expect(screen.queryByText(/Ostatní zařízení bez nálezů/)).toBeNull();
  });

  it('když odpověděly všechny zdroje, říkají to jako dřív', async () => {
    renderWith([]);
    expect(await screen.findByText('Nic k řešení - žádné zjištění.')).toBeTruthy();
    expect(screen.getAllByText('nic takového teď není')).toHaveLength(3);
    expect(screen.getByText('Ostatní zařízení bez nálezů (3)')).toBeTruthy();
  });
});
