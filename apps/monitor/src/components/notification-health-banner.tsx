import { Link } from 'react-router';
import { BellOff } from 'lucide-react';
import type { NotificationHealth } from '@/api/types';
import { useAdminProbe } from '@/api/use-admin-probe';
import { useSession } from '@/api/use-session';
import { ProbeFailedNotice } from '@/components/probe-failed-notice';
import { useLanguage } from '@/context/language-context';
import { groupNotificationProblems } from '@/lib/notification-health';
import { channelLabel } from '@/lib/outgoing-message';
import { cn } from '@/lib/utils';

/** Recipients named on one line before the rest become "+N". */
const NAMED = 3;

function isNotificationHealth(data: unknown): data is NotificationHealth {
  return typeof data === 'object' && data !== null && Array.isArray((data as NotificationHealth).problems);
}

/**
 * Says out loud when a channel has stopped reaching somebody.
 *
 * The owner's WhatsApp quota ran out and his e-mail went through a mail()
 * nobody confirmed. Every row in the log said "sent", nothing arrived, and
 * nothing in the app said a word - an outage he would have heard about only
 * by chance. Red when a channel refuses, amber when nobody confirms.
 *
 * Admin only, like the log it reads: even masked, it says who is not being
 * reached. Silent while every channel delivers. When its own request fails
 * (5xx, network, not JSON) it says so: silence there looked like "all is well".
 */
export function NotificationHealthBanner() {
  const { t, lang } = useLanguage();
  const { isAdmin } = useSession();
  const probe = useAdminProbe('/status/api.php?action=notification_health', isAdmin, isNotificationHealth, 5 * 60_000);

  if (!probe) return null;
  if (probe.state === 'failed') {
    return (
      <ProbeFailedNotice
        title={t('notifhealth.probe_failed', 'Stav doručování zpráv nelze zjistit')}
        failure={probe.failure}
      />
    );
  }
  const health = probe.data;
  const lines = groupNotificationProblems(health.problems);
  if (lines.length === 0) return null;

  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const when = (iso: string) => new Date(iso).toLocaleString(locale);
  const anyFailed = lines.some((l) => l.state === 'failed');

  return (
    <div
      role="alert"
      className={cn(
        'space-y-2 rounded-lg border-2 p-4',
        anyFailed ? 'border-down/60 bg-down/10' : 'border-warning/60 bg-warning/10'
      )}
    >
      <p className={cn('flex items-center gap-2 text-sm font-bold', anyFailed ? 'text-down' : 'text-warning')}>
        <BellOff aria-hidden="true" className="size-5 shrink-0" />
        {anyFailed
          ? t('notifhealth.title_failed', 'Upozornění někomu nedoráží')
          : t('notifhealth.title_unknown', 'Doručení upozornění nikdo nepotvrdil')}
      </p>
      <ul className="space-y-2 text-xs">
        {lines.map((line) => {
          const more = line.who.length - NAMED;
          return (
            <li key={`${line.state}-${line.channel}`}>
              <span className={cn('font-semibold', line.state === 'failed' ? 'text-down' : 'text-warning')}>
                {channelLabel(line.channel, t)}:{' '}
                {line.state === 'failed'
                  ? t('outgoing.result_failed', 'Neodesláno')
                  : t('outgoing.result_unknown', 'Nepotvrzeno')}
              </span>
              {line.who.length > 0 && (
                <span>
                  {' · '}
                  {line.who.slice(0, NAMED).join(', ')}
                  {more > 0 ? ` +${more}` : ''}
                </span>
              )}
              <span className="text-muted-foreground">
                {' · '}
                {t(
                  'notifhealth.since',
                  { since: when(line.sinceIso), n: line.count },
                  `od ${when(line.sinceIso)}, ${line.count}× za sebou`
                )}
              </span>
              {line.lastReason && (
                <span className="text-muted-foreground block break-words">
                  {t('notifhealth.last_reason', 'Poslední důvod')}: {line.lastReason}
                </span>
              )}
              <span className="text-muted-foreground block">
                {line.lastSentAtIso
                  ? `${t('notifhealth.last_sent', 'Naposledy potvrzeno')}: ${when(line.lastSentAtIso)}`
                  : t('notifhealth.never_sent', 'Za posledních 7 dní nic nepotvrzeno.')}
                {line.legacy
                  ? ` ${t('notifhealth.legacy', '(podle starších záznamů bez odpovědi poskytovatele)')}`
                  : ''}
              </span>
            </li>
          );
        })}
      </ul>
      <p className="text-2xs">
        {t(
          'notifhealth.hint',
          'Výpadek, o kterém tímhle kanálem nepřijde zpráva, se tihle příjemci nemusí vůbec dozvědět.'
        )}{' '}
        <Link to="/outgoing-messages" className="text-link underline underline-offset-2">
          {t('notifhealth.link', 'Otevřít odchozí zprávy')}
        </Link>
        {health.truncated ? ` ${t('notifhealth.truncated', 'Přečteno jen posledních 5000 zpráv.')}` : ''}
      </p>
    </div>
  );
}
