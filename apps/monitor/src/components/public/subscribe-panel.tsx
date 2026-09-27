import * as React from 'react';
import { BellRing, Rss } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { ErrorState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';

/**
 * How a visitor without an account hears about the next outage: e-mail with
 * double opt-in, or the RSS feed of the same events.
 */
export function SubscribePanel({ rssHref }: { rssHref: string }) {
  const { t, lang } = useLanguage();
  const [email, setEmail] = React.useState('');
  const [state, setState] = React.useState<'idle' | 'busy' | 'done'>('idle');
  const [error, setError] = React.useState<string | null>(null);
  const hintId = React.useId();

  return (
    <Panel
      icon={BellRing}
      title={t('pubsub.box_title', 'Upozornění na výpadky e-mailem')}
      action={
        <a
          href={rssHref}
          className="text-muted-foreground hover:text-foreground hover:bg-raised focus-visible:ring-ring inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border border-border px-2.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
        >
          <Rss aria-hidden="true" className="size-3.5" />
          {t('public.rss', 'RSS kanál výpadků')}
        </a>
      }
    >
      <div className="space-y-2.5">
        {state === 'done' ? (
          // One message for every outcome. The server deliberately no longer
          // reports whether a mail went out: only a not-yet-subscribed address
          // triggers a send, so any delivery signal would tell an anonymous
          // caller who is already subscribed. This wording stays true whether
          // the address is new, already confirmed, or within the resend
          // cooldown - it promises nothing that did not happen.
          <p className="text-up text-xs font-medium" role="status">
            {t(
              'pubsub.box_check_inbox',
              'Hotovo. Pokud adresa ještě odběr nemá, přišel na ni potvrzovací e-mail - odběr začne až po kliknutí na odkaz v něm.'
            )}
          </p>
        ) : (
          <form
            className="flex flex-wrap gap-2"
            onSubmit={async (e) => {
              e.preventDefault();
              setState('busy');
              setError(null);
              try {
                const res = await fetch('/status/api.php?action=public_subscribe', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ email, lang }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
                setState('done');
              } catch (err) {
                setState('idle');
                setError(err instanceof Error ? err.message : t('pubsub.failed', 'Odběr se nepodařilo založit.'));
              }
            }}
          >
            <input
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={t('pubsub.box_placeholder', 'vas@email.cz')}
              aria-label={t('pubsub.box_title', 'Upozornění na výpadky e-mailem')}
              aria-describedby={hintId}
              className="bg-inset focus-visible:ring-ring h-9 min-w-0 flex-1 rounded-lg border border-input px-3 text-sm focus-visible:ring-2 focus-visible:outline-none"
            />
            <button
              type="submit"
              disabled={state === 'busy'}
              className="bg-primary text-primary-foreground hover:bg-primary/90 focus-visible:ring-ring h-9 shrink-0 rounded-lg px-4 text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
            >
              {state === 'busy' ? t('pubsub.box_sending', 'Odesílám…') : t('pubsub.box_subscribe', 'Odebírat')}
            </button>
          </form>
        )}
        {error && <ErrorState size="inline" message={error} />}
        <p id={hintId} className="text-muted-foreground text-2xs">
          {t('pubsub.box_hint', 'Pošleme jen výpadky a jejich obnovení. Odhlášení jedním klikem v každém e-mailu.')}
        </p>
      </div>
    </Panel>
  );
}
