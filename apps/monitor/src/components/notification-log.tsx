import * as React from 'react';
import { Send } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { Pill } from '@/components/ui/pill';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';
import type { AlertTone, Delivery } from '@/api/types';
import { deliveryLabel, deliveryOf, kindLabel } from '@/lib/outgoing-message';

/** Pill colour per result. Unconfirmed is amber: nobody knows it arrived, and green would say it did. */
const DELIVERY_TONE: Record<Delivery, 'up' | 'warning' | 'down' | 'neutral'> = {
  sent: 'up',
  unknown: 'warning',
  failed: 'down',
  skipped: 'neutral',
};

interface Entry {
  id: number;
  /** Added with the outgoing message log; an old row from before the migration has none. */
  kind?: string;
  /** Recovery, warning or outage, for an alert row; a server that predates it sends none. */
  alertTone?: AlertTone | null;
  status: string;
  channel: string;
  recipient: string | null;
  ok: boolean;
  /** Missing from an older server; `ok` is then read as unknown or failed, never as sent. */
  delivery?: Delivery;
  error: string | null;
  atIso: string;
}

/**
 * What was actually sent about this monitor.
 *
 * The question after every outage - "did the alert reach me?" - had no answer
 * anywhere: nothing recorded a delivery, so a channel failing for weeks looked
 * exactly like a channel with nothing to report. Failures are kept and shown,
 * because they are the half worth reading.
 *
 * Admin only, both here and on the server: the rows name recipients.
 */
export function NotificationLog({ monitorId }: { monitorId: number }) {
  const { t, lang } = useLanguage();
  const { isAdmin } = useSession();
  const [entries, setEntries] = React.useState<Entry[] | null>(null);

  React.useEffect(() => {
    if (!isAdmin) return;
    let active = true;
    fetch(`/status/api.php?action=notification_log&monitor_id=${monitorId}&limit=50`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (active && data && Array.isArray(data.entries)) setEntries(data.entries);
      })
      .catch(() => {
        // A failed read is not evidence that nothing was sent, so the panel
        // stays away rather than claiming an empty history.
      });
    return () => {
      active = false;
    };
  }, [isAdmin, monitorId]);

  if (!isAdmin || entries === null) return null;

  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const failed = entries.filter((e) => deliveryOf(e) === 'failed').length;
  const unconfirmed = entries.filter((e) => deliveryOf(e) === 'unknown').length;

  return (
    <Panel
      icon={Send}
      title={t('notif.title', 'Odeslané notifikace')}
      hint={t('notif.desc', 'Co o tomhle monitoru odešlo, kterým kanálem a jestli to kanál přijal.')}
      chip={
        failed > 0 || unconfirmed > 0 ? (
          <span className="flex flex-wrap gap-1.5">
            {failed > 0 && (
              <Pill tone="down" dot>
                {t('notif.failed', { n: failed }, `${failed} neodesláno`)}
              </Pill>
            )}
            {unconfirmed > 0 && (
              <Pill tone="warning" dot>
                {t('notif.unconfirmed', { n: unconfirmed }, `${unconfirmed} nepotvrzeno`)}
              </Pill>
            )}
          </span>
        ) : undefined
      }
      bodyClassName="space-y-3"
    >
      {entries.length === 0 ? (
        <p className="text-muted-foreground text-xs">
          {t('notif.empty', 'Za posledních 90 dní o tomhle monitoru nic neodešlo.')}
        </p>
      ) : (
        <ul className="flex flex-col">
          {entries.map((e) => {
            const d = deliveryOf(e);
            return (
              <li
                key={e.id}
                className="border-border flex flex-wrap items-center gap-x-3 gap-y-1 border-b py-2 text-xs last:border-0"
              >
                <span className="text-muted-foreground figure w-36 shrink-0 text-2xs">
                  {new Date(e.atIso).toLocaleString(locale)}
                </span>
                {/* The colour repeats the result; the words are in the pill's label. */}
                <Pill tone={DELIVERY_TONE[d]} size="sm" srLabel={`${e.channel}: ${deliveryLabel(d, t)}`}>
                  {e.channel}
                </Pill>
                {/* What kind of message it was. Alerts are no longer the only
                  thing logged, so "down" alone stopped being the whole story;
                  a row written before the kind existed simply has none. */}
                {e.kind && (
                  <span className="text-muted-foreground shrink-0 text-2xs">
                    {kindLabel(e.kind, t, e.alertTone, e.status)}
                  </span>
                )}
                <span className="min-w-0 flex-1 truncate">
                  {e.status}
                  {e.recipient ? <span className="text-muted-foreground font-mono"> · {e.recipient}</span> : null}
                </span>
                {d === 'failed' && (
                  <span className="text-down text-2xs">
                    {e.error ?? t('notif.not_delivered', 'kanál zprávu nepřijal')}
                  </span>
                )}
                {/* A 2xx from CallMeBot or a mail() hand-off used to show green here
                  while nothing arrived. Unconfirmed says so, and why. */}
                {d === 'unknown' && (
                  <span className="text-warning text-2xs">
                    {deliveryLabel(d, t)}
                    {e.error ? `: ${e.error}` : ''}
                  </span>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </Panel>
  );
}
