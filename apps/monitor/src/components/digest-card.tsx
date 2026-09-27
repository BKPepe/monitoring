import * as React from 'react';
import { BarChart3, CalendarDays, CircleAlert, CircleCheck, ExternalLink, FileBarChart, Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { IconTile } from '@/components/ui/icon-tile';
import { Panel } from '@/components/ui/panel';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

type Period = 'weekly' | 'monthly';

/**
 * The weekly and monthly digest: send one now, or preview it (admin only).
 *
 * It sat in Settings → Notifikace, between the channel configuration, though
 * it is a report about SLA, incidents and latency - the same numbers as this
 * page (W2-7). The result is the server's own answer, including a failure.
 */
export function DigestCard() {
  const { t } = useLanguage();
  const [sending, setSending] = React.useState<Period | null>(null);
  const [result, setResult] = React.useState<{ ok: boolean; msg: string } | null>(null);

  const send = async (period: Period) => {
    setSending(period);
    setResult(null);
    try {
      const res = await fetch('/status/api.php?action=send_digest', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ period }),
      });
      const data = await res.json().catch(() => ({}));
      setResult({
        ok: res.ok && !!data.success,
        msg: data.message || data.error || t('settings.digest_unknown_result', 'Neznámý výsledek.'),
      });
    } catch {
      setResult({ ok: false, msg: t('settings.digest_comm_error', 'Chyba při komunikaci se serverem.') });
    } finally {
      setSending(null);
    }
  };

  const periods: {
    period: Period;
    icon: typeof CalendarDays;
    title: string;
    desc: string;
    send: string;
    preview: string;
  }[] = [
    {
      period: 'weekly',
      icon: CalendarDays,
      title: t('settings.weekly_digest_title', 'Týdenní Souhrn (Weekly Digest)'),
      desc: t(
        'settings.weekly_digest_desc',
        'Souhrnný e-mail se statistikami SLA, incidenty a průměrnou latencí za posledních 7 dnů.'
      ),
      send: t('settings.send_weekly_digest', 'Odeslat Týdenní Digest'),
      preview: '/status/admin.php?action=preview_weekly_digest',
    },
    {
      period: 'monthly',
      icon: BarChart3,
      title: t('settings.monthly_digest_title', 'Měsíční Souhrn (Monthly Digest)'),
      desc: t(
        'settings.monthly_digest_desc',
        'Kompletní měsíční auditní zpráva pro vedení se všemi výpadky, MTTR a plněním SLA.'
      ),
      send: t('settings.send_monthly_digest', 'Odeslat Měsíční Digest'),
      preview: '/status/admin.php?action=preview_monthly_digest',
    },
  ];

  return (
    <Panel
      icon={FileBarChart}
      title={t('settings.digest_title', 'Týdenní & Měsíční Digest Report')}
      hint={t(
        'settings.digest_desc',
        'Digest se odesílá automaticky cronem (vždy v pondělí / 1. den v měsíci). Zde můžete odeslat ruční e-mailový digest všem administrátorům.'
      )}
      className="print:hidden"
      bodyClassName="space-y-4"
    >
      {result && (
        <p
          role={result.ok ? 'status' : 'alert'}
          className={cn(
            'flex items-center gap-2 rounded-lg border p-3 text-xs font-semibold',
            result.ok ? 'border-up/30 bg-up/10 text-up' : 'border-down/30 bg-down/10 text-down'
          )}
        >
          {result.ok ? (
            <CircleCheck aria-hidden="true" className="size-4 shrink-0" />
          ) : (
            <CircleAlert aria-hidden="true" className="size-4 shrink-0" />
          )}
          {result.msg}
        </p>
      )}

      <div className="grid gap-3 sm:grid-cols-2">
        {periods.map(({ period, icon: Icon, title, desc, send: label, preview }) => (
          <div key={period} className="bg-inset space-y-3 rounded-lg border border-border p-4">
            <h3 className="flex items-center gap-2.5 text-sm font-semibold">
              <IconTile icon={Icon} size="sm" />
              {title}
            </h3>
            <p className="text-muted-foreground text-2xs">{desc}</p>
            <div className="flex flex-wrap items-center gap-2 pt-1">
              <Button size="sm" variant="primary" onClick={() => void send(period)} disabled={sending !== null}>
                <Send aria-hidden="true" />
                {sending === period ? t('settings.sending', 'Odesílám…') : label}
              </Button>
              <Button size="sm" variant="outline" asChild>
                <a href={preview} target="_blank" rel="noreferrer">
                  <ExternalLink aria-hidden="true" /> {t('settings.preview', 'Náhled')}
                </a>
              </Button>
            </div>
          </div>
        ))}
      </div>
    </Panel>
  );
}
