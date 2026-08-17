import * as React from 'react';
import { resolveSource, type SourceState } from './source';
import type { ChartData, PublicStatus, TimeRange } from './types';

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
  }, [monitorId, range]);

  return { data, error, loading: data === null && error === null };
}

/**
 * `action=public_status` renders on one page independently from several
 * components at once (AppShell, DataSourceBanner, Dashboard) - without sharing
 * that would be 3 separate requests to the server and DB for the same data.
 * A short module-level TTL cache shares them without dragging React Context in.
 */
let publicStatusCache: { promise: Promise<PublicStatus>; timestamp: number } | null = null;
const PUBLIC_STATUS_CACHE_MS = 10000;

function fetchPublicStatusShared(): Promise<PublicStatus> {
  if (publicStatusCache && Date.now() - publicStatusCache.timestamp < PUBLIC_STATUS_CACHE_MS) {
    return publicStatusCache.promise;
  }
  const promise = resolveSource().then(({ source }) => source.getPublicStatus());
  publicStatusCache = { promise, timestamp: Date.now() };
  promise.catch(() => {
    // Errors are not cached - let the next call retry rather than replay the same failure.
    publicStatusCache = null;
  });
  return promise;
}

/** Summary state for the dashboard (`action=public_status`). */
export function usePublicStatus(refreshMs?: number) {
  const [data, setData] = React.useState<PublicStatus | null>(null);
  const [error, setError] = React.useState<Error | null>(null);

  React.useEffect(() => {
    let active = true;

    const load = () => {
      fetchPublicStatusShared()
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
  }, [refreshMs]);

  return { data, error, loading: data === null && error === null };
}
