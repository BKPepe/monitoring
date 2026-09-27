import * as React from 'react';

/**
 * What a page tells the shell's header: a title more specific than the
 * route's, how fresh its data is, and how to refresh it.
 *
 * The header cannot know a page's data, and it must not pretend to: without a
 * page's freshness there is no LIVE pill at all (a fixed "live" light was the
 * dishonest thing main removed), and without a page's refresh the button
 * remounts the page, which refetches everything it shows.
 */
export interface PageFreshness {
  /** Epoch ms of the newest measurement on the page; null = none yet. */
  at: number | null;
  /** The cadence the data is expected at, in seconds. */
  intervalSecs: number;
  /** The last refresh failed: the page shows the last good data. */
  failed?: boolean;
  /** Epoch ms of the last successful fetch. */
  okAt?: number | null;
}

export interface PageChrome {
  title?: string;
  freshness?: PageFreshness | null;
  /** Refetches the page's data; a returned promise keeps the button spinning until it settles. */
  onRefresh?: () => unknown;
}

interface ShellState {
  chrome: PageChrome;
  setChrome: (chrome: PageChrome) => void;
}

const ShellContext = React.createContext<ShellState | null>(null);

export function ShellProvider({ children }: { children: React.ReactNode }) {
  const [chrome, setChrome] = React.useState<PageChrome>({});
  const value = React.useMemo(() => ({ chrome, setChrome }), [chrome]);
  return <ShellContext.Provider value={value}>{children}</ShellContext.Provider>;
}

/** The header's side: what the current page registered. Empty outside the shell. */
export function useShellChrome(): PageChrome {
  return React.useContext(ShellContext)?.chrome ?? {};
}

/**
 * The page's side. Call it on every render with the current values; they are
 * cleared when the page unmounts, so the next page never inherits a LIVE pill
 * or a refresh handler that is not its own. Outside the shell (tests, the
 * public page) it does nothing.
 */
export function usePageChrome({ title, freshness, onRefresh }: PageChrome) {
  const setChrome = React.useContext(ShellContext)?.setChrome;
  // The handler changes identity on every render of most pages; a ref keeps
  // the registration stable while the header still calls the newest one.
  const refreshRef = React.useRef(onRefresh);
  React.useEffect(() => {
    refreshRef.current = onRefresh;
  });
  const hasRefresh = onRefresh != null;
  const at = freshness?.at ?? null;
  const intervalSecs = freshness?.intervalSecs;
  const failed = freshness?.failed ?? false;
  const okAt = freshness?.okAt ?? null;
  const hasFreshness = freshness != null;

  React.useEffect(() => {
    if (!setChrome) return;
    setChrome({
      title,
      freshness: hasFreshness ? { at, intervalSecs: intervalSecs ?? 60, failed, okAt } : null,
      onRefresh: hasRefresh ? () => refreshRef.current?.() : undefined,
    });
  }, [setChrome, title, hasFreshness, at, intervalSecs, failed, okAt, hasRefresh]);

  React.useEffect(() => {
    if (!setChrome) return;
    return () => setChrome({});
  }, [setChrome]);
}
