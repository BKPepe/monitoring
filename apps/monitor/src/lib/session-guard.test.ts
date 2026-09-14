// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { announceSignOut, installSessionGuards } from './session-guard';

/** Node 26 hides jsdom's storage behind a flag; browsers always have one. */
function memoryStorage(): Storage {
  const data = new Map<string, string>();
  return {
    get length() {
      return data.size;
    },
    clear: () => data.clear(),
    getItem: (key: string) => data.get(key) ?? null,
    key: (index: number) => [...data.keys()][index] ?? null,
    removeItem: (key: string) => {
      data.delete(key);
    },
    setItem: (key: string, value: string) => {
      data.set(key, String(value));
    },
  };
}

function pageShow(persisted: boolean): Event {
  return Object.assign(new Event('pageshow'), { persisted });
}

describe('session guards', () => {
  let cleanup: (() => void) | null = null;

  beforeEach(() => {
    vi.stubGlobal('localStorage', memoryStorage());
  });

  afterEach(() => {
    cleanup?.();
    cleanup = null;
    vi.unstubAllGlobals();
  });

  it('reloads a page the browser restored from its back/forward cache', () => {
    const reload = vi.fn();
    cleanup = installSessionGuards(window, reload);
    window.dispatchEvent(pageShow(false));
    expect(reload).not.toHaveBeenCalled();
    window.dispatchEvent(pageShow(true));
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('reloads when another tab announces a sign-out, and only then', () => {
    const reload = vi.fn();
    cleanup = installSessionGuards(window, reload);
    window.dispatchEvent(new StorageEvent('storage', { key: 'bk-theme', newValue: 'dark' }));
    expect(reload).not.toHaveBeenCalled();
    window.dispatchEvent(new StorageEvent('storage', { key: 'bk-signed-out', newValue: '1' }));
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('stops listening after cleanup', () => {
    const reload = vi.fn();
    installSessionGuards(window, reload)();
    window.dispatchEvent(pageShow(true));
    window.dispatchEvent(new StorageEvent('storage', { key: 'bk-signed-out', newValue: '1' }));
    expect(reload).not.toHaveBeenCalled();
  });

  it('writes the signal other tabs listen for', () => {
    announceSignOut();
    expect(window.localStorage.getItem('bk-signed-out')).toMatch(/^\d+$/);
  });

  it('does not throw when storage is blocked', () => {
    const blocked = {
      setItem: () => {
        throw new Error('blocked');
      },
    };
    expect(() => announceSignOut(blocked)).not.toThrow();
  });
});
