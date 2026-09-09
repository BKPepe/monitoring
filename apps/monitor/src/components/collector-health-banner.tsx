import * as React from 'react';
import { AlertOctagon } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

interface Health {
  lastRunAt: string | null;
  ageSecs: number | null;
  maxAgeSecs: number;
  stale: boolean;
  lastDurationMs: number | null;
  monitorsChecked: number | null;
}

/**
 * Whether the thing that produces all the other numbers is still running.
 *
 * Every panel in this app renders what cron last wrote. When cron stops, the
 * pages keep showing the last known state and look calm - the one failure the
 * whole product must not present quietly. The server has exposed
 * collection_health (last run, its age, how long it took, how many monitors it
 * checked) for a long time and nothing in the app ever asked.
 *
 * Silent while collection is healthy: a banner that is always there is not
 * read when it matters.
 */
export function CollectorHealthBanner() {
  const { t, lang } = useLanguage();
  const [health, setHealth] = React.useState<Health | null>(null);

  React.useEffect(() => {
    let active = true;
    const load = () =>
      fetch('/status/api.php?action=collection_health', { credentials: 'include' })
        .then((r) => (r.ok ? r.json() : null))
        .then((data) => {
          if (active && data && typeof data.stale === 'boolean') setHealth(data as Health);
        })
        .catch(() => {
          // A failed probe is not evidence that cron died - it could be the
          // network or the session. Saying nothing beats crying wolf.
        });
    load();
    const id = window.setInterval(load, 60_000);
    return () => {
      active = false;
      window.clearInterval(id);
    };
  }, []);

  if (!health || !health.stale) return null;

  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  const minutes = health.ageSecs != null ? Math.round(health.ageSecs / 60) : null;
  const limit = Math.round(health.maxAgeSecs / 60);

  return (
    <div role="alert" className="border-down/60 bg-down/10 space-y-1 rounded-lg border-2 p-4">
      <p className="text-down flex items-center gap-2 text-sm font-bold">
        <AlertOctagon className="size-5 shrink-0" />
        {t('collector.heading', 'Sběr dat neběží')}
      </p>
      <p className="text-xs">
        {minutes == null
          ? t('collector.never', 'Cron se nikdy nepřihlásil, takže žádná hodnota na téhle stránce není čerstvá.')
          : t(
              'collector.stale',
              { minutes, limit },
              `Poslední běh cronu byl před ${minutes} min, limit je ${limit} min. Čísla níž jsou z té doby, ne z teď.`
            )}
      </p>
      {health.lastRunAt && (
        <p className="text-muted-foreground font-mono text-[11px]">
          {t('collector.last_run', 'Poslední běh')}: {new Date(health.lastRunAt).toLocaleString(locale)}
          {health.monitorsChecked != null
            ? ` · ${t('collector.checked', { n: health.monitorsChecked }, `zkontrolováno ${health.monitorsChecked} monitorů`)}`
            : ''}
        </p>
      )}
      <p className="text-muted-foreground text-[11px] leading-relaxed">
        {t(
          'collector.hint',
          'Nejčastější příčina: cron úloha na hostingu se přestala spouštět, nebo běh spadl na chybě. Zkontrolujte plánovač a log cronu.'
        )}
      </p>
    </div>
  );
}
