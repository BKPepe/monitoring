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

/** Souhrnný stav pro dashboard (`action=public_status`). */
export function usePublicStatus() {
  const [data, setData] = React.useState<PublicStatus | null>(null);
  const [error, setError] = React.useState<Error | null>(null);

  React.useEffect(() => {
    let active = true;

    resolveSource()
      .then(({ source }) => source.getPublicStatus())
      .then((result) => {
        if (active) setData(result);
      })
      .catch((err: unknown) => {
        if (active) setError(err instanceof Error ? err : new Error('Načtení selhalo'));
      });

    return () => {
      active = false;
    };
  }, []);

  return { data, error, loading: data === null && error === null };
}
