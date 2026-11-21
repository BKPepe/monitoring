import * as React from 'react';

type Theme = 'dark' | 'light';
const STORAGE_KEY = 'bk-theme';

function readInitialTheme(): Theme {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'dark' || stored === 'light') return stored;
  } catch {
    // Storage blocked (private window, a sandbox): fall through to the system.
  }
  // The app is designed dark-first, but the system preference wins.
  try {
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  } catch {
    return 'dark';
  }
}

/*
 * One theme for the whole page. Each useTheme() used to keep its own copy in
 * React state; with the switch in the sidebar and the sidebar drawn twice
 * (the desktop rail and the phone drawer), a toggle in one left the other
 * showing the old icon and flipping back to the old theme on its next click.
 * The value lives here once and every caller subscribes to it.
 */
let current: Theme | null = null;
const listeners = new Set<() => void>();

function getTheme(): Theme {
  current ??= readInitialTheme();
  return current;
}

function setTheme(next: Theme) {
  current = next;
  for (const listener of listeners) listener();
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function useTheme() {
  const theme = React.useSyncExternalStore(subscribe, getTheme, () => 'dark' as Theme);

  React.useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark');
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch {
      // Not remembered across visits; the page itself still switches.
    }
  }, [theme]);

  const toggle = React.useCallback(() => setTheme(getTheme() === 'dark' ? 'light' : 'dark'), []);

  return { theme, setTheme, toggle };
}
