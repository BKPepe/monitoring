// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen } from '@testing-library/react';
import { LanguageProvider } from '@/context/language-context';
import { FreshnessPill, ageText, freshnessOf } from './freshness-pill';

/**
 * The pill is the page's only "live" cue, so each state must follow from
 * the timestamps it is given - never from a fixed string - and it must move
 * on its own while the page stays open.
 */
const NOW = Date.UTC(2026, 8, 25, 10, 0, 0);

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(NOW);
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

function pill(props: Parameters<typeof FreshnessPill>[0]) {
  return render(
    <LanguageProvider>
      <FreshnessPill {...props} />
    </LanguageProvider>
  );
}

const state = (c: HTMLElement) => c.querySelector('[data-state]')?.getAttribute('data-state');

describe('freshnessOf', () => {
  it('živé do dvou intervalů, zpožděné do tří, pak zastaralé; selhání má přednost', () => {
    expect(freshnessOf(0, 300, false)).toBe('fresh');
    expect(freshnessOf(600, 300, false)).toBe('fresh');
    expect(freshnessOf(601, 300, false)).toBe('late');
    expect(freshnessOf(900, 300, false)).toBe('late');
    expect(freshnessOf(901, 300, false)).toBe('stale');
    expect(freshnessOf(5, 300, true)).toBe('failed');
  });
});

describe('ageText', () => {
  it('sekundy, minuty, hodiny, dny - a nikdy záporně', () => {
    expect(ageText(34)).toBe('34 s');
    expect(ageText(-3)).toBe('0 s');
    expect(ageText(420)).toBe('7 min');
    expect(ageText(7300)).toBe('2 h');
    expect(ageText(3 * 86400 + 5)).toBe('3 d');
  });
});

describe('FreshnessPill', () => {
  it('čerstvá data: „Živě“ s věkem a pulzující tečkou', () => {
    const { container } = pill({ at: NOW - 34_000, intervalSecs: 300 });
    expect(state(container)).toBe('fresh');
    expect(container.textContent).toContain('Živě');
    expect(container.textContent).toContain('34 s');
    expect(container.querySelector('.animate-pulse')).not.toBeNull();
  });

  it('věk běží s hodinami a stav se sám překlopí na zpožděno a zastaralé', () => {
    const { container } = pill({ at: NOW - 590_000, intervalSecs: 300 });
    expect(state(container)).toBe('fresh');
    act(() => vi.advanceTimersByTime(20_000));
    expect(state(container)).toBe('late');
    expect(container.textContent).toContain('Zpožděno');
    expect(container.textContent).toContain('10 min');
    // Only the live state pulses: a late or stale pill must not look live.
    expect(container.querySelector('.animate-pulse')).toBeNull();
    act(() => vi.advanceTimersByTime(5 * 60_000));
    expect(state(container)).toBe('stale');
    expect(container.textContent).toContain('Zastaralé');
  });

  it('selhané obnovení: varování s časem dat, která zůstala na obrazovce', () => {
    const okAt = new Date(2026, 8, 25, 12, 4).getTime();
    const { container } = pill({ at: NOW - 30_000, intervalSecs: 300, failed: true, okAt });
    expect(state(container)).toBe('failed');
    expect(container.textContent).toContain('Obnovení selhalo');
    expect(container.textContent).toContain('12:04');
    expect(container.querySelector('.animate-pulse')).toBeNull();
  });

  it('selhání bez jediné úspěšné odpovědi neuvádí žádný čas', () => {
    const { container } = pill({ at: null, intervalSecs: 300, failed: true });
    expect(state(container)).toBe('failed');
    expect(container.textContent).toBe('Obnovení selhalo');
  });

  it('bez měření a bez selhání se nevykreslí nic - žádné „Živě“ z ničeho', () => {
    const { container } = pill({ at: null, intervalSecs: 300 });
    expect(container.textContent).toBe('');
  });

  it('anglicky', () => {
    // Node hides jsdom's storage behind a flag; the provider reads the language from it.
    vi.stubGlobal('localStorage', { getItem: () => 'en', setItem: () => {}, removeItem: () => {} });
    try {
      pill({ at: NOW - 5_000, intervalSecs: 300 });
      expect(screen.getByText('Live')).toBeTruthy();
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
