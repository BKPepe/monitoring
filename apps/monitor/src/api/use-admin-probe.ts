import * as React from 'react';

/** Why a diagnostic request gave no usable answer. */
export type ProbeFailure =
  { kind: 'http'; status: number } | { kind: 'network' } | { kind: 'not_json' } | { kind: 'shape' };

/** `null` = nothing to show yet, or the account may not ask. */
export type ProbeResult<T> = { state: 'ok'; data: T } | { state: 'failed'; failure: ProbeFailure } | null;

/**
 * Polls one admin-only diagnostic action and says when the check itself failed.
 *
 * A banner that went quiet on a 5xx looked exactly like "all is well" while
 * the query behind it was dead; on 27 Sep 2026 every PHP answer started with
 * stray bytes from config.php and nothing in /app said a word. So only 401
 * and 403 stay silent (the session or the role, which the app shell handles);
 * any other status, a network error, a body that is not JSON or JSON of the
 * wrong shape is a failed check the caller shows. The last good answer is
 * dropped on a failure: it is no longer known to be true.
 */
export function useAdminProbe<T>(
  url: string,
  enabled: boolean,
  /** A module-level guard: a new function on every render would restart the polling. */
  accept: (data: unknown) => data is T,
  intervalMs: number
): ProbeResult<T> {
  const [result, setResult] = React.useState<ProbeResult<T>>(null);

  React.useEffect(() => {
    if (!enabled) return;
    let active = true;
    const settle = (next: ProbeResult<T>) => {
      if (active) setResult(next);
    };
    const load = async () => {
      let response: Response;
      try {
        response = await fetch(url, { credentials: 'include' });
      } catch {
        settle({ state: 'failed', failure: { kind: 'network' } });
        return;
      }
      if (response.status === 401 || response.status === 403) {
        settle(null);
        return;
      }
      if (!response.ok) {
        settle({ state: 'failed', failure: { kind: 'http', status: response.status } });
        return;
      }
      let data: unknown;
      try {
        data = await response.json();
      } catch {
        settle({ state: 'failed', failure: { kind: 'not_json' } });
        return;
      }
      settle(accept(data) ? { state: 'ok', data } : { state: 'failed', failure: { kind: 'shape' } });
    };
    void load();
    const id = window.setInterval(() => void load(), intervalMs);
    return () => {
      active = false;
      window.clearInterval(id);
    };
  }, [url, enabled, accept, intervalMs]);

  return enabled ? result : null;
}
