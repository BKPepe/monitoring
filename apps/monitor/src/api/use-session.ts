import * as React from 'react';
import { appApi, type SessionInfo } from './app-api';

/**
 * `useSession()` se volá nezávisle z AppShell i z každé stránky (websites,
 * infrastructure, users, ...) - bez sdílení by to byl samostatný
 * `action=session` požadavek za každou z nich na jednom načtení stránky.
 */
let sessionCache: Promise<SessionInfo> | null = null;

function fetchSessionShared(force: boolean): Promise<SessionInfo> {
  if (!force && sessionCache) return sessionCache;
  sessionCache = appApi.getSession();
  return sessionCache;
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
