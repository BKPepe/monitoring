import * as React from 'react';
import { appApi, type SessionInfo } from './app-api';

export function useSession() {
  const [session, setSession] = React.useState<SessionInfo | null>(null);
  const [loading, setLoading] = React.useState(true);

  const fetchSession = React.useCallback(() => {
    setLoading(true);
    appApi
      .getSession()
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
    fetchSession();
  }, [fetchSession]);

  return {
    session,
    loading,
    isAdmin: session?.user?.role === 'admin',
    refetchSession: fetchSession,
  };
}
