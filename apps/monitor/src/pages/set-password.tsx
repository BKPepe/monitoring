import * as React from 'react';
import { Link, useSearchParams } from 'react-router';
import { CheckCircle2, KeyRound } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';

/**
 * Setting a password from an invite or reset link.
 *
 * Invite e-mails used to point at admin.php?action=set_password, so a new
 * user's very first step was the legacy admin. New e-mails point here; the
 * legacy page keeps working for links that were already sent.
 *
 * The token is consumed server-side on first success, and an invalid token
 * gets the same answer as an expired one - whether it ever existed cannot be
 * probed from here.
 */
export function SetPasswordPage() {
  const { t } = useLanguage();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';

  const [password, setPassword] = React.useState('');
  const [confirm, setConfirm] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const [done, setDone] = React.useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password.length < 8) {
      setError(t('setpw.too_short', 'Heslo musí mít alespoň 8 znaků.'));
      return;
    }
    if (password !== confirm) {
      setError(t('setpw.mismatch', 'Hesla se neshodují.'));
      return;
    }
    setBusy(true);
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=set_password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, password }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setDone(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : t('setpw.failed', 'Heslo se nepodařilo nastavit.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="grid min-h-screen place-items-center p-4">
      <Card className="w-full max-w-sm space-y-4 p-6">
        <div className="flex items-center gap-2.5">
          <KeyRound className="size-5 text-primary" />
          <h1 className="text-lg font-bold">{t('setpw.title', 'Nastavení hesla')}</h1>
        </div>

        {done ? (
          <div className="space-y-4">
            <p className="text-up flex items-center gap-2 text-sm font-medium">
              <CheckCircle2 className="size-4" />
              {t('setpw.done', 'Heslo bylo nastaveno.')}
            </p>
            <Button asChild className="w-full">
              <Link to="/setup">{t('setpw.go_login', 'Přihlásit se')}</Link>
            </Button>
          </div>
        ) : !token ? (
          <p className="text-muted-foreground text-sm">
            {t('setpw.no_token', 'Odkazu chybí token. Použijte celý odkaz z e-mailu.')}
          </p>
        ) : (
          <form onSubmit={submit} className="space-y-3">
            {error && <p className="text-destructive text-xs font-semibold">{error}</p>}
            <label className="block">
              <span className="text-muted-foreground mb-1 block text-xs font-medium">
                {t('setpw.password', 'Nové heslo')}
              </span>
              <Input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="new-password"
                autoFocus
              />
            </label>
            <label className="block">
              <span className="text-muted-foreground mb-1 block text-xs font-medium">
                {t('setpw.confirm', 'Heslo znovu')}
              </span>
              <Input
                type="password"
                value={confirm}
                onChange={(e) => setConfirm(e.target.value)}
                autoComplete="new-password"
              />
            </label>
            <Button type="submit" disabled={busy} className="w-full">
              {busy ? t('setpw.saving', 'Nastavuji…') : t('setpw.submit', 'Nastavit heslo')}
            </Button>
          </form>
        )}
      </Card>
    </div>
  );
}
