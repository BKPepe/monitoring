// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { CollectorHealthBanner } from './collector-health-banner';

function serve(body: unknown, status = 200) {
  vi.stubGlobal(
    'fetch',
    vi.fn(() => Promise.resolve({ ok: status < 400, status, json: () => Promise.resolve(body) } as Response))
  );
}

const renderBanner = () =>
  render(
    <LanguageProvider>
      <CollectorHealthBanner />
    </LanguageProvider>
  );

const HEALTH = { lastRunAt: null, ageSecs: 120, maxAgeSecs: 900, lastDurationMs: 800, monitorsChecked: 6 };

describe('CollectorHealthBanner', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('a failed check of the collector says so instead of going quiet (R223)', async () => {
    serve({ error: 'DB' }, 500);
    renderBanner();
    expect((await screen.findByRole('alert')).textContent).toContain('Kontrola sběru dat se nepovedla');
    expect(screen.getByText('Kontrola skončila chybou HTTP 500.')).toBeTruthy();
  });

  it('a stopped collector is the red banner', async () => {
    serve({ ...HEALTH, ageSecs: 3600, stale: true });
    renderBanner();
    expect((await screen.findByRole('alert')).textContent).toContain('Sběr dat neběží');
  });

  it('a running collector shows nothing, and neither does a signed-out session', async () => {
    serve({ ...HEALTH, stale: false });
    const { container } = renderBanner();
    await new Promise((r) => setTimeout(r, 0));
    expect(container.textContent).toBe('');
    cleanup();
    serve({ error: 'Unauthorized' }, 401);
    const second = renderBanner();
    await new Promise((r) => setTimeout(r, 0));
    expect(second.container.textContent).toBe('');
  });
});
