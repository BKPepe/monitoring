import { describe, expect, it, vi } from 'vitest';
import { installStaleBuildRecovery, isChunkLoadError, reloadOncePerBuild } from './stale-build';

/** A sessionStorage stand-in: one tab's memory. */
function memoryStorage() {
  const data = new Map<string, string>();
  return {
    getItem: (k: string) => data.get(k) ?? null,
    setItem: (k: string, v: string) => void data.set(k, v),
  };
}

describe('Nový build během otevřené aplikace (W1-F4)', () => {
  it('chybějící soubor pozná podle hlášky Chromia, Firefoxu i Safari', () => {
    // The exact wording each engine throws for a failed dynamic import.
    expect(
      isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: https://x.test/app/assets/a-1.js'))
    ).toBe(true);
    expect(
      isChunkLoadError(new TypeError('error loading dynamically imported module: https://x.test/app/assets/a-1.js'))
    ).toBe(true);
    expect(isChunkLoadError(new TypeError('Importing a module script failed.'))).toBe(true);
    expect(isChunkLoadError(new Error('Unable to preload CSS for /app/assets/a-1.css'))).toBe(true);
  });

  it('jinou chybu za aktualizaci nevydává', () => {
    expect(isChunkLoadError(new TypeError("Cannot read properties of undefined (reading 'map')"))).toBe(false);
    expect(isChunkLoadError(null)).toBe(false);
    expect(isChunkLoadError({ message: 'Failed to fetch dynamically imported module' })).toBe(false);
  });

  it('jeden build se obnoví jen jednou, další build zase jednou - žádná smyčka', () => {
    const storage = memoryStorage();
    const reload = vi.fn();
    expect(reloadOncePerBuild('0.0.1 (aaa)', storage, reload)).toBe(true);
    expect(reloadOncePerBuild('0.0.1 (aaa)', storage, reload)).toBe(false);
    expect(reload).toHaveBeenCalledTimes(1);
    expect(reloadOncePerBuild('0.0.1 (bbb)', storage, reload)).toBe(true);
    expect(reload).toHaveBeenCalledTimes(2);
  });

  it('zablokované úložiště: bez pojistky se neobnovuje vůbec', () => {
    const reload = vi.fn();
    const blocked = {
      getItem: () => {
        throw new Error('SecurityError');
      },
      setItem: () => {},
    };
    expect(reloadOncePerBuild('b', blocked, reload)).toBe(false);
    expect(reloadOncePerBuild('b', null, reload)).toBe(false);
    expect(reload).not.toHaveBeenCalled();
  });

  it('vite:preloadError obnoví stránku jednou, podruhé chybu nechá doběhnout k obrazovce', () => {
    const target = new EventTarget();
    const reload = vi.fn();
    const cleanup = installStaleBuildRecovery('b', target as unknown as Window, memoryStorage(), reload);

    const first = new Event('vite:preloadError', { cancelable: true });
    target.dispatchEvent(first);
    expect(reload).toHaveBeenCalledTimes(1);
    // Handled: Vite does not throw the error on.
    expect(first.defaultPrevented).toBe(true);

    const second = new Event('vite:preloadError', { cancelable: true });
    target.dispatchEvent(second);
    expect(reload).toHaveBeenCalledTimes(1);
    // Not handled: the import rejects and the error boundary shows its screen.
    expect(second.defaultPrevented).toBe(false);
    cleanup();
  });
});
