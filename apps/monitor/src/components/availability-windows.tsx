import * as React from 'react';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { coverageStart, formatCoverageDay } from '@/lib/window-coverage';
import { formatPercent } from '@/lib/utils';

interface Windows {
  d1: number | null;
  d7: number | null;
  d30: number | null;
  d90: number | null;
  /** The first day with data in the 90-day window (server-local "Y-m-d"). */
  since?: string | null;
}

/** The first calendar day of each window, as the server counted it. */
type WindowStarts = Partial<Record<'d7' | 'd30' | 'd90', string>>;

/**
 * Availability over four windows for one monitor.
 *
 * The server computes 24 h / 7 d / 30 d / 90 d in a single request and the
 * only page that ever asked was the public status page - the operator's own
 * detail page, where the question "how good has this actually been?" belongs,
 * had no answer at all. A window with no measurements stays null and renders
 * as a dash; a fabricated 100 % is the worst possible lie here.
 */
export function AvailabilityWindows({ monitorId }: { monitorId: number }) {
  const { t, lang } = useLanguage();
  const [windows, setWindows] = React.useState<Windows | null>(null);
  const [starts, setStarts] = React.useState<WindowStarts>({});
  // A failed request renders as an error. The panel used to vanish, which read
  // as "this monitor has no availability history" when the server never answered.
  const [failed, setFailed] = React.useState(false);
  const [attempt, setAttempt] = React.useState(0);

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=uptime_windows', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((data) => {
        if (!active) return;
        if (!data || typeof data.windows !== 'object' || data.windows == null) {
          setFailed(true);
          return;
        }
        // No row for this monitor = nothing measured yet: the panel stays away.
        const row = (data.windows as Record<string, Windows>)[String(monitorId)];
        setWindows(row ?? null);
        setStarts(data.windowStart && typeof data.windowStart === 'object' ? data.windowStart : {});
        setFailed(false);
      })
      .catch(() => {
        if (active) setFailed(true);
      });
    return () => {
      active = false;
    };
  }, [monitorId, attempt]);

  if (failed) {
    return (
      <Card className="space-y-2 p-5">
        <h3 className="text-sm font-semibold">{t('avail.title', 'Dostupnost')}</h3>
        <ErrorState
          message={t('avail.load_failed', 'Dostupnost se nepodařilo načíst.')}
          onRetry={() => {
            setFailed(false);
            setAttempt((n) => n + 1);
          }}
        />
      </Card>
    );
  }

  if (!windows) return null;

  // A window that starts before the monitor's first data says so: "90 dní"
  // over six weeks of history is six weeks, and the label must not hide it.
  const since = (key: keyof WindowStarts) => {
    const day = coverageStart(windows.since, starts[key]);
    return day ? formatCoverageDay(day, lang) : null;
  };
  const cells: { label: string; value: number | null; since: string | null }[] = [
    { label: t('avail.d1', '24 hodin'), value: windows.d1, since: null },
    { label: t('avail.d7', '7 dní'), value: windows.d7, since: since('d7') },
    { label: t('avail.d30', '30 dní'), value: windows.d30, since: since('d30') },
    { label: t('avail.d90', '90 dní'), value: windows.d90, since: since('d90') },
  ];
  if (cells.every((c) => c.value == null)) return null;

  return (
    <Card className="space-y-2 p-5">
      <h3 className="text-sm font-semibold">{t('avail.title', 'Dostupnost')}</h3>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {cells.map((cell) => (
          <div key={cell.label}>
            <p className="text-muted-foreground text-2xs font-medium">
              {cell.label}
              {cell.since && (
                <span className="font-normal">
                  {' '}
                  {t('avail.since', { date: cell.since }, `(data od ${cell.since})`)}
                </span>
              )}
            </p>
            <p
              className={
                cell.value == null
                  ? 'text-muted-foreground tabular-nums text-lg font-bold'
                  : cell.value >= 99.9
                    ? 'text-up tabular-nums text-lg font-bold'
                    : cell.value >= 99
                      ? 'text-warning tabular-nums text-lg font-bold'
                      : 'text-down tabular-nums text-lg font-bold'
              }
            >
              {formatPercent(cell.value, 2)}
            </p>
          </div>
        ))}
      </div>
      <p className="text-muted-foreground text-2xs leading-relaxed">
        {t(
          'avail.note',
          'Podíl času, kdy služba běžela, v každém okně. Čas, kdy se neměřilo, se do podílu nepočítá, mlčící agent se počítá jako výpadek. Prázdné okno znamená, že se v něm neměřilo - ne stoprocentní dostupnost.'
        )}
      </p>
    </Card>
  );
}
