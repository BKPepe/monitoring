import { getCsrfToken, setCsrfToken } from './app-api';

/**
 * Global fetch wrapper: every POST to api.php carries the session's CSRF
 * token in X-CSRF-Token.
 *
 * The server enforces the token on all state-changing actions (write guard
 * in api.php). appApi.mutate() always sent the header, but 26 call sites
 * across the app talk to api.php with a raw fetch() - patching each one and
 * hoping no future call site forgets the header is exactly the kind of
 * convention that silently rots. One wrapper covers them all, including code
 * that does not exist yet.
 *
 * Requests that predate a token (login, setup, set-password from an e-mail
 * link) are CSRF-exempt server-side, so a missing token here is not an
 * error - the wrapper just sends what it has.
 */
export function installCsrfFetch(): void {
  const origFetch = window.fetch.bind(window);

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const method = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();

    if (method === 'POST' && url.includes('/api.php')) {
      let token = getCsrfToken();
      if (!token && !/action=(login|setup|set_password|forgot_password)/.test(url)) {
        // First write before any session fetch (deep link straight to a
        // form): prime the token once from the session endpoint.
        try {
          const res = await origFetch('/status/api.php?action=session', { credentials: 'include' });
          const data = (await res.json()) as { csrfToken?: string | null };
          token = data.csrfToken ?? null;
          setCsrfToken(token);
        } catch {
          // The request goes out even without a token - the server answers with an
          // intelligible 403, which is better diagnostics than a silent fail here.
        }
      }
      if (token) {
        const headers = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
        headers.set('X-CSRF-Token', token);
        init = { ...init, headers };
      }
    }

    return origFetch(input as RequestInfo, init);
  };
}
