import * as React from 'react';
import { ShieldCheck, ShieldOff } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useLanguage } from '@/context/language-context';

/**
 * Two-factor enrollment for the logged-in user.
 *
 * This existed only in the legacy admin - React showed a "2FA" badge but had
 * no way to actually set it up, so anyone wanting two-factor had to go back
 * to admin.php. Same two-step flow as there: the secret lives only in the
 * server session until the user proves with a code that the QR really
 * scanned; nothing touches the database before that, so an account cannot be
 * locked by an unverified secret.
 *
 * The QR is drawn client-side (the `qrcode` package, lazy-loaded) - the
 * secret never leaves for any third-party service.
 */
export function TotpSection({ enabled, onChanged }: { enabled: boolean | null; onChanged: () => void }) {
  const { t } = useLanguage();
  const [phase, setPhase] = React.useState<'idle' | 'setup' | 'disable'>('idle');
  const [qrDataUrl, setQrDataUrl] = React.useState<string | null>(null);
  const [secret, setSecret] = React.useState('');
  const [code, setCode] = React.useState('');
  const [password, setPassword] = React.useState('');
  const [error, setError] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);

  const post = async (action: string, body: Record<string, string>) => {
    const res = await fetch(`/status/api.php?action=${action}`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
    return data;
  };

  const startSetup = async () => {
    setBusy(true);
    setError(null);
    try {
      const data = await post('totp_setup', {});
      setSecret(data.secret ?? '');
      // Lazy import: the QR library loads only when someone actually enrolls.
      const QRCode = await import('qrcode');
      setQrDataUrl(await QRCode.toDataURL(data.otpauthUri, { width: 192, margin: 1 }));
      setPhase('setup');
    } catch (e) {
      setError(e instanceof Error ? e.message : t('totp.failed', 'Operace se nepodařila.'));
    } finally {
      setBusy(false);
    }
  };

  const confirm = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await post('totp_confirm', { code });
      setPhase('idle');
      setCode('');
      setQrDataUrl(null);
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('totp.failed', 'Operace se nepodařila.'));
    } finally {
      setBusy(false);
    }
  };

  const disable = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await post('totp_disable', { password });
      setPhase('idle');
      setPassword('');
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('totp.failed', 'Operace se nepodařila.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card className="space-y-3 p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="flex items-center gap-2 text-sm font-semibold">
          {enabled ? (
            <ShieldCheck className="text-up size-4" />
          ) : (
            <ShieldOff className="text-muted-foreground size-4" />
          )}
          {t('totp.title', 'Dvoufázové ověření (2FA)')}
        </h3>
        {enabled !== null && (
          <Badge variant={enabled ? 'up' : 'neutral'}>
            {enabled ? t('totp.on', 'Zapnuto') : t('totp.off', 'Vypnuto')}
          </Badge>
        )}
      </div>

      {error && <p className="text-destructive text-xs font-semibold">{error}</p>}

      {phase === 'idle' && enabled === false && (
        <div className="space-y-2">
          <p className="text-muted-foreground text-xs">
            {t('totp.hint', 'Přihlášení bude kromě hesla chtít i 6místný kód z autentikační aplikace.')}
          </p>
          <Button size="sm" onClick={startSetup} disabled={busy}>
            {busy ? t('totp.working', 'Pracuji…') : t('totp.enable', 'Zapnout 2FA')}
          </Button>
        </div>
      )}

      {phase === 'idle' && enabled === true && (
        <Button size="sm" variant="outline" onClick={() => setPhase('disable')}>
          {t('totp.disable', 'Vypnout 2FA')}
        </Button>
      )}

      {phase === 'setup' && (
        <form onSubmit={confirm} className="space-y-3">
          <p className="text-muted-foreground text-xs">
            {t('totp.scan', 'Naskenujte kód v aplikaci (Google/Microsoft Authenticator, Aegis…) a potvrďte kódem.')}
          </p>
          {qrDataUrl && (
            <img
              src={qrDataUrl}
              alt={t('totp.qr_alt', 'QR kód pro autentikační aplikaci')}
              className="mx-auto rounded-md bg-white p-2"
            />
          )}
          {/* The secret as text for anyone who cannot scan - same as legacy. */}
          <p className="text-muted-foreground text-center font-mono text-xs tracking-wider select-all">{secret}</p>
          <Input
            value={code}
            onChange={(e) => setCode(e.target.value)}
            placeholder="123 456"
            inputMode="numeric"
            autoComplete="one-time-code"
            className="text-center font-mono"
          />
          <div className="flex gap-2">
            <Button type="submit" size="sm" disabled={busy || code.trim().length < 6}>
              {t('totp.confirm', 'Potvrdit a zapnout')}
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setPhase('idle')}>
              {t('common.cancel', 'Zrušit')}
            </Button>
          </div>
        </form>
      )}

      {phase === 'disable' && (
        <form onSubmit={disable} className="space-y-3">
          {/* Password required on purpose: a stolen session must not be able
              to switch 2FA off quietly. */}
          <p className="text-muted-foreground text-xs">{t('totp.disable_hint', 'Vypnutí vyžaduje aktuální heslo.')}</p>
          <Input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            autoComplete="current-password"
          />
          <div className="flex gap-2">
            <Button type="submit" size="sm" variant="destructive" disabled={busy || !password}>
              {t('totp.disable', 'Vypnout 2FA')}
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={() => setPhase('idle')}>
              {t('common.cancel', 'Zrušit')}
            </Button>
          </div>
        </form>
      )}
    </Card>
  );
}
