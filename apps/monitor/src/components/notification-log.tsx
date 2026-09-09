import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';

interface Entry {
  id: number;
  status: string;
  channel: string;
  recipient: string | null;
  ok: boolean;
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
  const failed = entries.filter((e) => !e.ok).length;

  return (
    <Card className="space-y-3 p-6">
      <div className="border-border flex flex-wrap items-center justify-between gap-2 border-b pb-3">
        <div>
          <h3 className="text-base font-bold">{t('notif.title', 'Odeslané notifikace')}</h3>
          <p className="text-muted-foreground text-xs">
            {t('notif.desc', 'Co o tomhle monitoru odešlo, kterým kanálem a jestli to kanál přijal.')}
          </p>
        </div>
        {failed > 0 && <Badge variant="down">{t('notif.failed', { n: failed }, `${failed} neodesláno`)}</Badge>}
      </div>

      {entries.length === 0 ? (
        <p className="text-muted-foreground text-xs">
          {t('notif.empty', 'Za posledních 90 dní o tomhle monitoru nic neodešlo.')}
        </p>
      ) : (
        <ul className="flex flex-col">
          {entries.map((e) => (
            <li
              key={e.id}
              className="border-border/40 flex flex-wrap items-center gap-x-3 gap-y-1 border-b py-1.5 text-xs last:border-0"
            >
              <span className="text-muted-foreground w-36 shrink-0 font-mono text-[11px]">
                {new Date(e.atIso).toLocaleString(locale)}
              </span>
              <Badge variant={e.ok ? 'up' : 'down'} className="text-[10px]">
                {e.channel}
              </Badge>
              <span className="min-w-0 flex-1 truncate">
                {e.status}
                {e.recipient ? <span className="text-muted-foreground font-mono"> · {e.recipient}</span> : null}
              </span>
              {!e.ok && (
                <span className="text-down text-[11px]">
                  {e.error ?? t('notif.not_delivered', 'kanál zprávu nepřijal')}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
