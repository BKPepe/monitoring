import * as React from 'react';
import { Badge } from '@/components/ui/badge';
import { useLanguage } from '@/context/language-context';

export type Freshness = 'fresh' | 'late' | 'stale' | 'failed';

/**
 * How old the data is, judged against the cadence it is expected to arrive
 * at: up to two intervals is live, up to three is late (with a 5-minute cron
 * that is the server's own 15-minute collection limit), anything older is
 * stale. A failed refresh wins over all of them - the numbers on screen are
 * then the last good ones, not the present.
 */
export function freshnessOf(ageSecs: number, intervalSecs: number, failed: boolean): Freshness {
  if (failed) return 'failed';
  if (ageSecs <= 2 * intervalSecs) return 'fresh';
  return ageSecs <= 3 * intervalSecs ? 'late' : 'stale';
}

/** "34 s", "7 min", "2 h", "3 d" - the same units in Czech and English, so no dictionary entry. */
export function ageText(secs: number): string {
  const s = Math.max(0, Math.round(secs));
  if (s < 60) return `${s} s`;
  if (s < 3600) return `${Math.floor(s / 60)} min`;
  return s < 86400 ? `${Math.floor(s / 3600)} h` : `${Math.floor(s / 86400)} d`;
}

const VARIANT = { fresh: 'up', late: 'warning', stale: 'down', failed: 'warning' } as const;

/**
 * The honest version of a "LIVE" light: computed from the timestamp of the
 * newest measurement and from whether the last refresh succeeded, never from
 * a fixed string. It pulses only while the data is fresh, and says "late" or
 * "stale" with the age when it is not, so an open tab cannot look live after
 * the collector or the API stopped answering.
 *
 * @param at Epoch ms of the newest measurement; null = none yet, and the pill stays away.
 * @param okAt Epoch ms of the last successful fetch - named when a refresh failed.
 */
export function FreshnessPill({
  at,
  intervalSecs,
  failed = false,
  okAt = null,
}: {
  at: number | null;
  intervalSecs: number;
  failed?: boolean;
  okAt?: number | null;
}) {
  const { t, lang } = useLanguage();
  // Only the age text moves with this clock; the data itself is the caller's.
  const [now, setNow] = React.useState(() => Date.now());
  React.useEffect(() => {
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, []);

  if (at === null && !failed) return null;
  const age = at === null ? Infinity : (now - at) / 1000;
  const state = freshnessOf(age, intervalSecs, failed);
  const locale = lang === 'en' ? 'en-GB' : 'cs-CZ';
  const label = {
    fresh: t('fresh.live', 'Živě'),
    late: t('fresh.late', 'Zpožděno'),
    stale: t('fresh.stale', 'Zastaralé'),
    failed: t('fresh.failed', 'Obnovení selhalo'),
  }[state];
  // A failed refresh names the time of the data still on screen; the other
  // states name the age of the newest measurement.
  const detail =
    state === 'failed'
      ? okAt === null
        ? null
        : new Date(okAt).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })
      : ageText(age);

  return (
    <Badge
      variant={VARIANT[state]}
      dot
      pulse={state === 'fresh'}
      data-state={state}
      title={at === null ? undefined : new Date(at).toLocaleString(locale)}
    >
      {label}
      {detail && <span className="font-mono tabular-nums">· {detail}</span>}
    </Badge>
  );
}
