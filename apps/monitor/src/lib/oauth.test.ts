import { afterEach, describe, expect, it, vi } from 'vitest';
import { oauthLabel, oauthProblemText, oauthStartUrl, readOauthOutcome } from './oauth';

// The translation function as the app's fallback path runs it: the inline text
// with its {params} filled in.
const t = (key: string, params?: Record<string, string | number> | string, fallback?: string): string => {
  if (typeof params === 'string') return params;
  let text = fallback ?? key;
  for (const [k, v] of Object.entries(params ?? {})) text = text.replace(`{${k}}`, String(v));
  return text;
};

describe('oauthStartUrl', () => {
  it('starts in api.php, not in the legacy admin.php', () => {
    expect(oauthStartUrl('github')).toBe('/status/api.php?action=oauth_start&provider=github');
  });

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  // An install under a subdirectory builds the app with its own API path.
  it('starts at the API the build points at', async () => {
    vi.stubEnv('VITE_STATUS_API', '/monitoring/status');
    vi.resetModules();
    const { oauthStartUrl: startUrl } = await import('./oauth');
    expect(startUrl('github')).toBe('/monitoring/status/api.php?action=oauth_start&provider=github');
  });
});

describe('readOauthOutcome', () => {
  it('is null on a page that is not the end of a round trip', () => {
    expect(readOauthOutcome('')).toBeNull();
    expect(readOauthOutcome('?next=/profile')).toBeNull();
  });

  it('reads the reason and the provider the server sent', () => {
    expect(readOauthOutcome('?oauth_error=not_linked&provider=github')).toEqual({
      problem: 'not_linked',
      linked: false,
      provider: 'GitHub',
    });
    expect(readOauthOutcome('?oauth=linked&provider=google')).toEqual({
      problem: null,
      linked: true,
      provider: 'Google',
    });
  });

  // A reason this build does not know is still a failure, worded without a cause.
  it('keeps an unknown reason a problem and drops an unknown provider', () => {
    expect(readOauthOutcome('?oauth_error=novy_duvod&provider=toString')).toEqual({
      problem: 'provider',
      linked: false,
      provider: null,
    });
  });
});

describe('oauthProblemText', () => {
  it('names the provider the query named', () => {
    expect(oauthProblemText('denied', 'GitHub', t)).toContain('GitHub');
  });

  // No provider it knows: no invented name and no cause, only that it did not finish.
  it('without a provider says only that the sign-in did not finish', () => {
    expect(oauthProblemText('unavailable', null, t)).toBe(
      'Přihlášení přes externí účet se nepodařilo dokončit. Zkuste to znovu.'
    );
    expect(oauthProblemText('state', null, t)).toContain('neplatný nebo starý stav');
  });

  it('words every reason the server sends', () => {
    const texts = (['state', 'unavailable', 'denied', 'provider', 'not_linked', 'link_session'] as const).map((p) =>
      oauthProblemText(p, 'GitLab', t)
    );
    expect(new Set(texts).size).toBe(6);
    expect(texts.every((x) => x.length > 20)).toBe(true);
  });

  // A link the signed-in account did not start: the server wrote nothing.
  it('reads link_session as its own reason, not as a provider failure', () => {
    expect(readOauthOutcome('?oauth_error=link_session&provider=github')?.problem).toBe('link_session');
    expect(oauthProblemText('link_session', null, t)).toContain('Nic se nepropojilo');
  });
});

describe('oauthLabel', () => {
  it('knows the four providers of the server and nothing on the prototype', () => {
    expect(['github', 'google', 'discord', 'gitlab'].map(oauthLabel)).toEqual([
      'GitHub',
      'Google',
      'Discord',
      'GitLab',
    ]);
    expect(oauthLabel('constructor')).toBeNull();
    expect(oauthLabel(null)).toBeNull();
  });
});
