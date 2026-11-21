import { useLanguage } from '@/context/language-context';
import { localeFor } from '@/lib/metric-format';

/**
 * Says that the figures next to it are old. A check that fails stores nothing
 * new, so a card over the stored details keeps showing them during an outage.
 * Quiet while the monitor is up or only degraded, when they are current.
 */
export function LastKnownNote({ status, at }: { status?: string; at?: string | null }) {
  const { t, lang } = useLanguage();
  if (status === undefined || status === 'up' || status === 'warning') return null;
  const when = at ? new Date(at.replace(' ', 'T')).toLocaleString(localeFor(lang)) : null;
  return (
    <p role="note" className="text-warning text-xs font-medium">
      {when
        ? t('last_known.at', { when }, `Poslední známé údaje, nejpozději z ${when}.`)
        : t('last_known.plain', 'Poslední známé údaje, teď se neměří.')}
    </p>
  );
}
