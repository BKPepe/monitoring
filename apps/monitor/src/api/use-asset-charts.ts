import * as React from 'react';
import { resolveSource, type SourceState } from './source';
import type { ChartData, PublicStatus, TimeRange } from './types';

/**
 * Stav zdroje dat pro celou aplikaci — reálné `api.php`, nebo mock.
 *
 * Vrací i `isMock`, aby UI mohlo uživatele upozornit, že čísla na obrazovce
 * nejsou naměřená.
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

/** Podklady pro grafy zařízení. */
export function useAssetCharts(monitorId: number, range: TimeRange) {
  const [data, setData] = React.useState<ChartData[] | null>(null);
  const [error, setError] = React.useState<Error | null>(null);

  React.useEffect(() => {
    // Odpověď na starý požadavek nesmí přepsat novější — při rychlém
    // přepínání rozsahu by jinak zůstal na obrazovce ten pomalejší.
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
 * `action=public_status` se renderuje na jedné stránce nezávisle z několika
 * komponent najednou (AppShell, DataSourceBanner, Dashboard) - bez sdílení by
 * to byly 3 samostatné požadavky na server a DB pro tatáž data. Krátká TTL
 * cache na úrovni modulu je sdílí, aniž by se muselo tahat přes React Context.
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
    // Chybu necachujeme - další volání ať to zkusí znovu, ne ať opakuje stejné selhání.
    publicStatusCache = null;
  });
  return promise;
}

/** Souhrnný stav pro dashboard (`action=public_status`). */
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
