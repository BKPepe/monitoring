import * as React from 'react';
import { appApi, type SessionInfo } from './app-api';
import { announceSignOut } from '@/lib/session-guard';

/**
 * `useSession()` is called independently from AppShell and every page
 * (websites, infrastructure, users, ...) - without sharing, each would fire
 * its own `action=session` request on a single page load.
 */
let sessionCache: Promise<SessionInfo> | null = null;

function fetchSessionShared(force: boolean): Promise<SessionInfo> {
  if (!force && sessionCache) return sessionCache;
  sessionCache = appApi.getSession();
  return sessionCache;
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
    sessionCache = null;
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
  const [session, setSession] = React.useState<SessionInfo | null>(null);
  const [loading, setLoading] = React.useState(true);

  const fetchSession = React.useCallback((force = false) => {
    setLoading(true);
    fetchSessionShared(force)
      .then((s) => setSession(s))
      .catch(() =>
        setSession({
          authenticated: false,
          user: null,
          csrfToken: null,
          loginUrl: '/app/setup',
        })
      )
      .finally(() => setLoading(false));
  }, []);

  React.useEffect(() => {
    fetchSession(false);
  }, [fetchSession]);

  return {
    session,
    loading,
    isAdmin: session?.user?.role === 'admin',
    refetchSession: () => fetchSession(true),
  };
}
