import { STATUS_API } from '@/api/http-source';

/**
 * OAuth sign-in and account linking, as the app sees them.
 *
 * Both are browser navigations: a link to api.php's oauth_start sends the
 * browser to the provider, the provider answers at admin.php (the callback
 * URL registered with it), and the server sends the browser back here with
 * the outcome in the query - the login page (/app/setup) for a sign-in, the
 * profile for a link. These helpers build the start link and read that query.
 */

type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

/** The providers the server knows (bk_oauth_providers() in functions.php). */
export const OAUTH_LABELS: Record<string, string> = {
  github: 'GitHub',
  google: 'Google',
  discord: 'Discord',
  gitlab: 'GitLab',
};

/**
 * Where a sign-in starts: the API the build points at (VITE_STATUS_API), like
 * every other call.
 */
export function oauthStartUrl(provider: string): string {
  return `${STATUS_API}/api.php?action=oauth_start&provider=${encodeURIComponent(provider)}`;
}

/**
 * A link to the signed-in account starts with its password. A linked provider
 * signs in without one, so a stolen session must not be able to add its own.
 * Answers where to send the browser; throws the server's reason when it refuses.
 */
export async function oauthLinkUrl(provider: string, password: string): Promise<string> {
  const res = await fetch(`${STATUS_API}/api.php?action=oauth_link_start`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ provider, password }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || typeof data.url !== 'string') throw new Error(data.error || `HTTP ${res.status}`);
  return data.url;
}

/** Why a round trip did not sign in or link, as the server names it. */
export type OauthProblem = 'state' | 'unavailable' | 'denied' | 'provider' | 'not_linked' | 'link_session';

const PROBLEMS: ReadonlySet<string> = new Set([
  'state',
  'unavailable',
  'denied',
  'provider',
  'not_linked',
  'link_session',
]);

export interface OauthOutcome {
  problem: OauthProblem | null;
  linked: boolean;
  /** The provider's label, or null when the query names none the app knows. */
  provider: string | null;
}

/**
 * The outcome the server put in this page's query, or null when the page was
 * not the end of a round trip. An unknown `oauth_error` is still a problem
 * ('provider' words it without claiming a cause the app does not know).
 */
export function readOauthOutcome(search: string): OauthOutcome | null {
  const query = new URLSearchParams(search);
  const error = query.get('oauth_error');
  const linked = query.get('oauth') === 'linked';
  if (error === null && !linked) return null;
  return {
    problem: error === null ? null : PROBLEMS.has(error) ? (error as OauthProblem) : 'provider',
    linked: error === null && linked,
    provider: oauthLabel(query.get('provider')),
  };
}

/** A provider's label, or null for a key the server does not offer. */
export function oauthLabel(key: string | null): string | null {
  return key !== null && Object.hasOwn(OAUTH_LABELS, key) ? OAUTH_LABELS[key] : null;
}

/**
 * The sentence for a problem, in the reader's language. Without a provider
 * the app knows (a query from a newer or a broken link) it says only that the
 * sign-in did not finish: it cannot name whose account, nor why.
 */
export function oauthProblemText(problem: OauthProblem, provider: string | null, t: TranslateFn): string {
  if (problem === 'state') {
    return t(
      'oauth.error_state',
      'Odpověď poskytovatele nepatří k přihlášení, které tento prohlížeč začal (neplatný nebo starý stav). Zkuste to znovu.'
    );
  }
  if (problem === 'link_session') {
    return t(
      'oauth.error_link_session',
      'Propojení začal účet, který tu už není přihlášený. Nic se nepropojilo; přihlaste se a začněte znovu v Profilu.'
    );
  }
  if (provider === null) {
    return t('oauth.error_generic', 'Přihlášení přes externí účet se nepodařilo dokončit. Zkuste to znovu.');
  }
  switch (problem) {
    case 'unavailable':
      return t('oauth.error_unavailable', { provider }, `Přihlášení přes ${provider} není na tomto serveru nastavené.`);
    case 'denied':
      return t('oauth.error_denied', { provider }, `Přihlášení přes ${provider} bylo u poskytovatele zrušeno.`);
    case 'not_linked':
      return t(
        'oauth.error_not_linked',
        { provider },
        `Tenhle účet ${provider} není propojený s žádným uživatelem. Přihlaste se heslem a propojte ho v Profilu.`
      );
    case 'provider':
      return t(
        'oauth.error_provider',
        { provider },
        `Poskytovatel ${provider} účet nepotvrdil: výměna kódu za token nebo načtení identity selhala. Zkuste to znovu.`
      );
  }
}
