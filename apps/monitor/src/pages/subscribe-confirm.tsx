import * as React from 'react';
import { Link, useSearchParams } from 'react-router';
import { BellRing, CheckCircle2 } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';

/**
 * Confirming a public e-mail subscription (the link from the opt-in mail).
 *
 * Deliberately an explicit button, not auto-confirm on load: corporate mail
 * scanners follow every link in a mail, and auto-confirming would subscribe
 * addresses whose owners never clicked anything.
 */
export function SubscribeConfirmPage() {
  const { t } = useLanguage();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const [state, setState] = React.useState<'idle' | 'busy' | 'done' | 'error'>('idle');
  const [error, setError] = React.useState<string | null>(null);

  const confirm = async () => {
    setState('busy');
    setError(null);
    try {
      const res = await fetch('/status/api.php?action=public_subscribe_confirm', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
      setState('done');
    } catch (err) {
      setState('error');
      setError(err instanceof Error ? err.message : t('pubsub.failed', 'Potvrzení se nepodařilo.'));
    }
  };

  return (
    <div className="grid min-h-screen place-items-center p-4">
      <Card className="w-full max-w-sm space-y-4 p-6">
        <div className="flex items-center gap-2.5">
          <BellRing className="size-5 text-primary" />
          <h1 className="text-lg font-bold">{t('pubsub.confirm_title', 'Potvrzení odběru')}</h1>
        </div>

        {!token ? (
          <p className="text-muted-foreground text-sm">
            {t('pubsub.no_token', 'Odkazu chybí token. Použijte celý odkaz z e-mailu.')}
          </p>
        ) : state === 'done' ? (
          <div className="space-y-4">
            <p className="text-up flex items-center gap-2 text-sm font-medium">
              <CheckCircle2 className="size-4" />
              {t('pubsub.confirmed', 'Odběr je potvrzený. Při výpadku a obnovení služeb vám přijde e-mail.')}
            </p>
            <Button asChild className="w-full">
              <Link to="/public">{t('pubsub.go_status', 'Přejít na stav služeb')}</Link>
            </Button>
          </div>
        ) : (
          <div className="space-y-3">
            <p className="text-muted-foreground text-sm">
              {t('pubsub.confirm_hint', 'Kliknutím potvrdíte odběr upozornění na výpadky pro tuto e-mailovou adresu.')}
            </p>
            {error && <p className="text-destructive text-xs font-semibold">{error}</p>}
            <Button onClick={confirm} disabled={state === 'busy'} className="w-full">
              {state === 'busy' ? t('pubsub.working', 'Potvrzuji…') : t('pubsub.confirm_btn', 'Potvrdit odběr')}
            </Button>
          </div>
        )}
      </Card>
    </div>
  );
}
