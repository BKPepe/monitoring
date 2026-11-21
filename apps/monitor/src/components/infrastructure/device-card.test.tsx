// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { Server } from 'lucide-react';
import type { FleetHealthState } from '@/api/use-fleet-health';
import { LanguageProvider } from '@/context/language-context';
import { parseFleetHealth } from '@/lib/health';
import { DeviceCard } from './device-card';

/**
 * The device list's view of the shared fleet-health state: a ring only from
 * a fresh answer. After a failed refresh the hook still holds the last
 * answer (the dashboard keeps its ring), but the list says once that the
 * scores are missing, so a card must not draw an older ring next to that.
 */
const FLEET = parseFleetHealth({
  network: { score: 80, grade: 'fair', formulaVersion: 1, components: [] },
  assets: [{ monitorId: 1, score: 92, grade: 'good', formulaVersion: 1, components: [] }],
});

function renderCard(health: FleetHealthState) {
  return render(
    <LanguageProvider>
      <MemoryRouter>
        <DeviceCard
          asset={{ id: 1, monitorId: 1, name: 'E-shop', type: 'web', hostname: null, statusKey: 'up' }}
          icon={Server}
          typeLabel="Web"
          since={null}
          monitor={undefined}
          health={health}
        />
      </MemoryRouter>
    </LanguageProvider>
  );
}

beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Karta zařízení: prstenec jen z čerstvé odpovědi', () => {
  it('čerstvá odpověď: prstenec se skóre ze serveru', () => {
    renderCard({ status: 'ok', data: FLEET });
    expect(screen.getByRole('img', { name: 'Zdraví: E-shop: 92 ze 100, Dobré' })).toBeTruthy();
  });

  it('selhané obnovení se starou odpovědí: žádný prstenec, ani starý, ani pomlčka', () => {
    renderCard({ status: 'failed', data: FLEET });
    expect(screen.queryByRole('img', { name: /Zdraví/ })).toBeNull();
  });
});
