// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { Footer } from './footer';

/**
 * The docs dialog names the public endpoints the install really serves. It
 * used to point at /api/v1/public_status, a route of the Go server that was
 * never deployed, so the first URL a reader copied answered 404.
 */
beforeEach(() => {
  vi.stubGlobal('localStorage', { getItem: () => null, setItem: () => {}, removeItem: () => {} });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('Patička: dokumentace API', () => {
  it('veřejné API je api.php?action=public_status, ne /api/v1', async () => {
    render(
      <LanguageProvider>
        <Footer version="0.0.1+abc1234" />
      </LanguageProvider>
    );
    fireEvent.click(screen.getByRole('button', { name: 'Dokumentace' }));
    const dialog = await screen.findByRole('dialog');

    // R306: this install's own address, not the author's domain.
    expect(dialog.textContent).toContain(`GET ${window.location.origin}/status/api.php?action=public_status`);
    expect(dialog.textContent).toContain(`GET ${window.location.origin}/status/metrics.php`);
    expect(dialog.textContent).not.toContain('bloodkings.eu');
    expect(dialog.textContent).not.toContain('/api/v1/');
  });
});
