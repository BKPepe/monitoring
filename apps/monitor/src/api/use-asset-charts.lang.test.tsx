// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { LanguageProvider, useLanguage } from '@/context/language-context';
import { useAssetCharts } from './use-asset-charts';

/**
 * R228: the device charts came with their titles in the language of the first
 * load, and switching the language kept them; the request carries ?lang=.
 */
function Probe() {
  const { setLang } = useLanguage();
  useAssetCharts(7, '24h');
  return (
    <button type="button" onClick={() => setLang('en')}>
      en
    </button>
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  document.documentElement.lang = 'cs';
  try {
    localStorage.removeItem('bk_lang');
  } catch {
    // storage blocked
  }
});

describe('Grafy zařízení po přepnutí jazyka (R228)', () => {
  it('načtou se znovu, v novém jazyce', async () => {
    const urls: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn((input: RequestInfo | URL) => {
        urls.push(String(input));
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ series: {} }) } as Response);
      })
    );
    render(
      <LanguageProvider>
        <Probe />
      </LanguageProvider>
    );
    await waitFor(() => expect(urls.some((u) => u.includes('lang=cs'))).toBe(true));
    fireEvent.click(screen.getByRole('button', { name: 'en' }));
    await waitFor(() =>
      expect(urls.some((u) => u.includes('metric_series_batch') && u.includes('lang=en'))).toBe(true)
    );
  });
});
