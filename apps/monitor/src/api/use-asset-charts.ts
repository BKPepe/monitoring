import * as React from 'react';
import { resolveSource, type SourceState } from './source';
import type { ChartData, PublicStatus, TimeRange, PublicStatusScope } from './types';

/**
 * State of the data source for the whole app — the real `api.php`, or the mock.
 *
 * Also returns `isMock`, so the UI can warn the user that the numbers on
 * screen are not measured.
 */
export function useSource(): SourceState | null {
  const [state, setState] = React.useState<SourceState | null>(null);

  React.useEffect(() => {
    let active = true;
    resolveSource().then((s) => {
      if (active) setState(s);
    });
    return () => {
      active = false;
    };
  }, []);

  return state;
}

/** Inputs for the device charts. */
export function useAssetCharts(monitorId: number, range: TimeRange) {
  const [data, setData] = React.useState<ChartData[] | null>(null);
  const [error, setError] = React.useState<Error | null>(null);
  // Bumped by reload(), so the error state's retry refetches without a range change.
  const [attempt, setAttempt] = React.useState(0);

  React.useEffect(() => {
    // A response to an old request must not overwrite a newer one — with fast
    // range switching the slower one would otherwise stay on screen.
    let active = true;
    setData(null);
    setError(null);

    resolveSource()
      .then(({ source }) => source.getAssetCharts(monitorId, range))
      .then((result) => {
        if (active) setData(result);
      })
      .catch((err: unknown) => {
        if (active) setError(err instanceof Error ? err : new Error('Načtení selhalo'));
      });

    return () => {
      active = false;
    };
  }, [monitorId, range, attempt]);

  const reload = React.useCallback(() => setAttempt((n) => n + 1), []);
  return { data, error, loading: data === null && error === null, reload };
}

/**
 * `action=public_status` renders on one page independently from several
 * components at once (AppShell, DataSourceBanner, Dashboard) - without sharing
 * that would be 3 separate requests to the server and DB for the same data.
 * A short module-level TTL cache shares them without dragging React Context in.
 */
// Keyed by scope: the status page's fleet-wide summary and the app's own one
// are different answers and must not be served for each other.
const publicStatusCache = new Map<PublicStatusScope, { promise: Promise<PublicStatus>; timestamp: number }>();
const PUBLIC_STATUS_CACHE_MS = 10000;

function fetchPublicStatusShared(scope: PublicStatusScope): Promise<PublicStatus> {
  const hit = publicStatusCache.get(scope);
  if (hit && Date.now() - hit.timestamp < PUBLIC_STATUS_CACHE_MS) {
    return hit.promise;
  }
  const promise = resolveSource().then(({ source }) => source.getPublicStatus(scope));
  publicStatusCache.set(scope, { promise, timestamp: Date.now() });
  promise.catch(() => {
    // Errors are not cached - let the next call retry rather than replay the same failure.
    if (publicStatusCache.get(scope)?.promise === promise) publicStatusCache.delete(scope);
  });
  return promise;
}

/** Summary state for the dashboard (`action=public_status`). */
export function usePublicStatus(refreshMs?: number, scope: PublicStatusScope = 'app') {
  const [data, setData] = React.useState<PublicStatus | null>(null);
  const [error, setError] = React.useState<Error | null>(null);
  // Bumped by reload(): a "try again" button must not wait for the next tick.
  const [attempt, setAttempt] = React.useState(0);

  React.useEffect(() => {
    let active = true;

    const load = () => {
      fetchPublicStatusShared(scope)
        .then((result) => {
          if (!active) return;
          setData(result);
          // A recovered refresh clears the stale error - otherwise the page
          // would keep apologising long after the data came back.
          setError(null);
        })
        .catch((err: unknown) => {
          // Deliberately does NOT clear `data`: one failed background refresh
          // must not blank a page that still holds valid last-known state.
          if (active) setError(err instanceof Error ? err : new Error('Načtení selhalo'));
        });
    };

    load();
    if (!refreshMs) {
      return () => {
        active = false;
      };
    }
    const id = window.setInterval(load, refreshMs);
    return () => {
      active = false;
      window.clearInterval(id);
    };
  }, [refreshMs, scope, attempt]);

  const reload = React.useCallback(() => setAttempt((n) => n + 1), []);
  return { data, error, loading: data === null && error === null, reload };
}
