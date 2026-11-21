import * as React from 'react';
import { ApiError, appApi, type SessionInfo } from './app-api';
import { onSessionRecheck, requestSessionRecheck } from './session-recheck';
import { announceSignOut } from '@/lib/session-guard';

/**
 * One session state for the whole app. `useSession()` is called from AppShell
 * and every page (websites, infrastructure, users, ...); without sharing, each
 * would fire its own `action=session` request on a single page load.
 *
 * A failed call is not a logout (W1-A7). Any error used to become
 * `authenticated: false`, and the rejected promise stayed cached, so one
 * network blip or a database restart sent a signed-in user to the login form
 * until a full reload. Now only the server's own answer - `authenticated:
 * false`, or a 401 - signs anyone out; everything else is `error`, and a
 * failure is never cached.
 */
interface SessionState {
  session: SessionInfo | null;
  /** The latest call failed (network, 5xx, not the API's answer). Cleared by the next success. */
  error: Error | null;
  /** No answer to show yet: true only while `session` is null and a call is on its way. */
  loading: boolean;
}

const SIGNED_OUT: SessionInfo = { authenticated: false, user: null, csrfToken: null, loginUrl: '/app/setup' };

let state: SessionState = { session: null, error: null, loading: true };
let inflight: Promise<void> | null = null;
const subscribers = new Set<() => void>();

function setState(next: Partial<SessionState>) {
  state = { ...state, ...next };
  for (const notify of subscribers) notify();
}

function subscribe(notify: () => void) {
  subscribers.add(notify);
  return () => {
    subscribers.delete(notify);
  };
}

function getSnapshot(): SessionState {
  return state;
}

function loadSession(force: boolean): Promise<void> {
  if (inflight) return inflight;
  if (!force && state.session !== null) return Promise.resolve();
  // `loading` means "no answer to show yet". A background re-check of a known
  // session leaves it alone, or every focus would flash the pages that wait on
  // it (profile, users, outgoing messages) back to a spinner.
  if (state.session === null) setState({ loading: true, error: null });
  inflight = appApi
    .getSession()
    .then((s) => {
      // A 200 that is not the API's answer (a hosting challenge page, an
      // empty body) carries no verdict - it must not read as "signed out".
      if (typeof s?.authenticated !== 'boolean') throw new Error('invalid session answer');
      setState({ session: s, error: null, loading: false });
    })
    .catch((err: unknown) => {
      if (err instanceof ApiError && err.status === 401) {
        setState({ session: SIGNED_OUT, error: null, loading: false });
        return;
      }
      // The last known session stays: a failed re-check of a signed-in tab
      // keeps it signed in, and the page's own requests report their errors.
      setState({ error: err instanceof Error ? err : new Error(String(err)), loading: false });
    })
    .finally(() => {
      inflight = null;
    });
  return inflight;
}

const refetchSession = () => {
  void loadSession(true);
};

let watchersInstalled = false;

/**
 * Re-checks on focus and whenever requestSessionRecheck() is raised. Deleting
 * the session cookie in an open tab then leads to the login on the next focus,
 * not on the next full reload.
 */
function installWatchers() {
  if (watchersInstalled || typeof window === 'undefined') return;
  watchersInstalled = true;
  onSessionRecheck(() => {
    // Nothing to re-check before the first answer; that load is on its way.
    if (state.session !== null || state.error !== null) void loadSession(true);
  });
  window.addEventListener('focus', () => requestSessionRecheck());
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') requestSessionRecheck();
  });
}

/**
 * Signing out. The page reloads to the login only after the server confirmed
 * it: on a shared computer a sign-out that silently failed would leave the
 * account open behind a login screen.
 */
export function useLogout() {
  const [pending, setPending] = React.useState(false);
  const [failed, setFailed] = React.useState(false);

  const logout = React.useCallback(async () => {
    setPending(true);
    setFailed(false);
    try {
      await appApi.logout();
    } catch {
      setFailed(true);
      setPending(false);
      return;
    }
    setState({ session: null, error: null });
    announceSignOut();
    // A full load, not a route change: every answer cached in memory belonged
    // to the account that just signed out. replace(), so Back does not lead to
    // the signed-in page; a copy the browser still restores reloads itself
    // (installSessionGuards).
    window.location.replace('/app/setup');
  }, []);

  return { logout, pending, failed };
}

export function useSession() {
  const snap = React.useSyncExternalStore(subscribe, getSnapshot, getSnapshot);

  React.useEffect(() => {
    installWatchers();
    void loadSession(false);
  }, []);

  return {
    session: snap.session,
    loading: snap.loading,
    /** Set when the latest session call failed; `session` then holds the last known answer, or null. */
    error: snap.error,
    isAdmin: snap.session?.user?.role === 'admin',
    refetchSession,
  };
}
