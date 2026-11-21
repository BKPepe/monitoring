import * as React from 'react';
import type { FleetHealth } from '@/lib/health';
import { appApi } from './app-api';

/**
 * Loading, failed or the answer. A failure is its own state, never a "—"
 * ring: "—" means the server had too little data, a failure means nobody
 * knows (kit rule 4). A failed refresh keeps the last answer in `data`.
 */
export type FleetHealthState =
  | { status: 'loading'; data: null }
  | { status: 'failed'; data: FleetHealth | null }
  | { status: 'ok'; data: FleetHealth };

/**
 * The fleet's health (`action=health`, app view) for the dashboard and the
 * device list: refetched whenever `reloadKey` changes (the page's minute
 * clock, the list's reload) and in the page's language, because the server
 * words the deductions. `enabled` false holds the request back - the device
 * list asks once its monitors are in. `retry` asks again.
 *
 * The app never computes a score; each page only decides what a failed
 * refresh shows (the dashboard keeps the last ring and says so, the device
 * list drops its rings and says so once).
 */
export function useFleetHealth(
  lang: string,
  reloadKey: unknown,
  enabled = true
): FleetHealthState & { retry: () => void } {
  const [state, setState] = React.useState<FleetHealthState>({ status: 'loading', data: null });
  const [attempt, setAttempt] = React.useState(0);

  React.useEffect(() => {
    if (!enabled) return;
    let active = true;
    appApi
      .getHealth(lang)
      .then((data) => {
        if (active) setState({ status: 'ok', data });
      })
      .catch(() => {
        if (active) setState((prev) => ({ status: 'failed', data: prev.data }));
      });
    return () => {
      active = false;
    };
  }, [lang, reloadKey, attempt, enabled]);

  const retry = React.useCallback(() => setAttempt((n) => n + 1), []);
  return { ...state, retry };
}
