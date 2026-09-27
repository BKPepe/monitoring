import * as React from 'react';
import { KeyRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { GithubIcon, GoogleIcon } from '@/components/ui/brand-icons';
import { useLanguage } from '@/context/language-context';
import { SetupField, SetupNotice } from './setup-ui';

const OAUTH_LABELS: Record<string, string> = {
  github: 'GitHub',
  google: 'Google',
  discord: 'Discord',
  gitlab: 'GitLab',
};

/**
 * The providers the login offers. The server lists the configured ones in
 * `action=session` (`oauthProviders`); a button for a provider without a
 * client ID only led to an error page (W2-12). A server that does not send
 * the list yet keeps the one button it always had.
 */
function useOauthProviders(): string[] {
  const [providers, setProviders] = React.useState<string[]>([]);
  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=session', { credentials: 'include' })
      .then((res) => res.json().catch(() => ({})))
      .then((data) => {
        if (!active) return;
        const list = (data as { oauthProviders?: unknown }).oauthProviders;
        if (Array.isArray(list))
          setProviders(list.filter((p): p is string => typeof p === 'string' && p in OAUTH_LABELS));
        else setProviders(['github']);
      })
      // No answer, no promise of a working provider.
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);
  return providers;
}

/** The sign-in form with 2FA, the forgotten-password request and the configured OAuth providers. */
export function LoginForm() {
  const { t } = useLanguage();
  // Empty, not 'admin': the repo is public and a prefilled name is half of a
  // known default login (W1-H2).
  const [username, setUsername] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [totpCode, setTotpCode] = React.useState('');
  const [require2FA, setRequire2FA] = React.useState(false);
  const [loading, setLoading] = React.useState(false);
  const [error, setError] = React.useState('');
  const [success, setSuccess] = React.useState('');
  const [showForgot, setShowForgot] = React.useState(false);
  const providers = useOauthProviders();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    setSuccess('');
    try {
      const res = await fetch('/status/api.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ username, password, totp_code: totpCode || '' }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.requires2fa) {
        setRequire2FA(true);
        setError(
          t(
            'setup.totp_required_error',
            'Účet má aktivní 2FA. Zadejte 6-místný kód z autentikační aplikace (Google/Microsoft Authenticator) a klikněte na Přihlásit znovu.'
          )
        );
        return;
      }
      if (!res.ok || !data.success) {
        throw new Error(
          data.message || t('setup.login_failed', 'Přihlášení selhalo. Zkontrolujte své přihlašovací údaje.')
        );
      }
      setSuccess(t('setup.login_success', 'Přihlášení úspěšné! Přesměrovávám na dashboard...'));
      setTimeout(() => {
        window.location.href = bk_after_login_target();
      }, 800);
    } catch (err) {
      setError(
        err instanceof Error && err.message
          ? err.message
          : t('setup.login_failed', 'Přihlášení selhalo. Zkontrolujte své přihlašovací údaje.')
      );
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-sm">
        {t('setup.login_subtitle', 'Přihlášení správce do monitorovacího systému')}
      </p>
      {error && <SetupNotice tone="error">{error}</SetupNotice>}
      {success && <SetupNotice tone="success">{success}</SetupNotice>}

      <form onSubmit={handleSubmit} className="space-y-4">
        <SetupField
          label={t('users.field_username', 'Uživatelské jméno')}
          required
          autoComplete="username"
          value={username}
          onChange={(e) => setUsername(e.target.value)}
          placeholder={t('setup.username_placeholder', 'Např. admin')}
        />
        <SetupField
          label={t('users.field_password', 'Heslo')}
          type="password"
          required
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          placeholder={t('setup.password_placeholder', 'Zadejte heslo')}
          aside={
            <button
              type="button"
              onClick={() => setShowForgot(true)}
              className="text-link text-xs underline-offset-4 hover:underline"
            >
              {t('setup.forgot_password', 'Zapomenuté heslo?')}
            </button>
          }
        />
        {(require2FA || totpCode) && (
          <SetupField
            label={t('setup.totp_code_label', 'Ověřovací kód 2FA')}
            autoFocus
            inputMode="numeric"
            autoComplete="one-time-code"
            value={totpCode}
            onChange={(e) => setTotpCode(e.target.value.replace(/\s+/g, ''))}
            placeholder="123456"
            maxLength={11}
            className="[&_input]:text-center [&_input]:text-lg [&_input]:font-bold [&_input]:tracking-[0.2em]"
            hint={t('setup.totp_hint', 'Zadejte aktuální 6místný kód vygenerovaný v mobilní aplikaci.')}
          />
        )}
        <Button type="submit" variant="primary" size="lg" disabled={loading} className="w-full">
          {loading
            ? t('setup.verifying', 'Ověřuji údaje...')
            : require2FA
              ? t('setup.confirm_2fa', 'Potvrdit 2FA kód a přihlásit se')
              : t('btn.login', 'Přihlásit se')}
        </Button>
      </form>

      {providers.length > 0 && (
        <div className="border-border space-y-2 border-t pt-4">
          <p className="text-muted-foreground text-center text-xs">
            {t('setup.oauth_or', 'Nebo se přihlaste jedním kliknutím:')}
          </p>
          {providers.map((key) => {
            const Icon = key === 'github' ? GithubIcon : key === 'google' ? GoogleIcon : KeyRound;
            return (
              <Button key={key} asChild variant="outline" className="w-full">
                <a href={`/status/admin.php?login_oauth=${key}`}>
                  <Icon className="size-4" />
                  {t('setup.oauth_with', { provider: OAUTH_LABELS[key] }, `Přihlásit se přes ${OAUTH_LABELS[key]}`)}
                </a>
              </Button>
            );
          })}
        </div>
      )}

      {showForgot && <ForgotPassword />}
    </div>
  );
}

function ForgotPassword() {
  const { t } = useLanguage();
  const [email, setEmail] = React.useState('');
  const [msg, setMsg] = React.useState('');
  const [error, setError] = React.useState('');
  const [loading, setLoading] = React.useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email) return;
    setLoading(true);
    setMsg('');
    setError('');
    // Only a 2xx means the server took the request. It used to report success
    // on a 500 and even on a dead network, so nobody knew to try again. The
    // success text stays generic: the server never says whether the account
    // exists, and this page must not either.
    const res = await fetch('/status/api.php?action=forgot_password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email }),
    }).catch(() => null);
    if (res && res.ok) {
      setMsg(
        t(
          'setup.forgot_processed',
          { email },
          `Žádost o reset hesla pro ${email} byla zpracována. Pokud účet existuje, obdržíte e-mail s instrukcemi.`
        )
      );
    } else {
      setError(
        res
          ? t(
              'setup.forgot_failed_http',
              { status: res.status },
              `Žádost o obnovu hesla se nepodařilo odeslat (HTTP ${res.status}). Zkuste to prosím znovu.`
            )
          : t(
              'setup.forgot_failed_network',
              'Žádost o obnovu hesla se nepodařilo odeslat - server je nedostupný. Zkuste to prosím znovu.'
            )
      );
    }
    setLoading(false);
  };

  return (
    <div className="border-border space-y-2 border-t pt-4">
      <h2 className="text-sm font-semibold">{t('setup.forgot_title', 'Obnovení zapomenutého hesla')}</h2>
      {error && <SetupNotice tone="error">{error}</SetupNotice>}
      {msg ? (
        <p className="text-muted-foreground text-sm">{msg}</p>
      ) : (
        <form onSubmit={submit} className="flex gap-2">
          <input
            type="email"
            required
            aria-label={t('setup.forgot_email_placeholder', 'Váš e-mail')}
            placeholder={t('setup.forgot_email_placeholder', 'Váš e-mail')}
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            className="bg-secondary/60 border-input h-9 min-w-0 flex-1 rounded-md border px-3 text-sm"
          />
          <Button type="submit" disabled={loading}>
            {loading ? '…' : t('setup.forgot_submit', 'Odeslat')}
          </Button>
        </form>
      )}
    </div>
  );
}

/**
 * Where a successful login goes: the app page the visitor was sent here from
 * (the shell's login guard adds ?next=), otherwise the dashboard. Only a path
 * inside the app is followed - an absolute or protocol-relative URL in the
 * query would turn the login page into an open redirect.
 */
function bk_after_login_target(): string {
  const next = new URLSearchParams(window.location.search).get('next') ?? '';
  if (/^\/(?!\/)[^\\]*$/.test(next) && !next.includes('://')) {
    return '/app' + next;
  }
  return '/app/';
}
