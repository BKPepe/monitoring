import * as React from 'react';
import { Card } from '@/components/ui/card';
import { useLanguage } from '@/context/language-context';

interface Windows {
  d1: number | null;
  d7: number | null;
  d30: number | null;
  d90: number | null;
}

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
  const { t } = useLanguage();
  const [windows, setWindows] = React.useState<Windows | null>(null);

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=uptime_windows', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!active || !data || typeof data.windows !== 'object' || data.windows == null) return;
        const row = (data.windows as Record<string, Windows>)[String(monitorId)];
        setWindows(row ?? null);
      })
      .catch(() => {
        // No availability is not zero availability - the panel simply stays away.
      });
    return () => {
      active = false;
    };
  }, [monitorId]);

  if (!windows) return null;

  const cells: { label: string; value: number | null }[] = [
    { label: t('avail.d1', '24 hodin'), value: windows.d1 },
    { label: t('avail.d7', '7 dní'), value: windows.d7 },
    { label: t('avail.d30', '30 dní'), value: windows.d30 },
    { label: t('avail.d90', '90 dní'), value: windows.d90 },
  ];
  if (cells.every((c) => c.value == null)) return null;

  return (
    <Card className="space-y-2 p-5">
      <h3 className="text-sm font-semibold">{t('avail.title', 'Dostupnost')}</h3>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {cells.map((cell) => (
          <div key={cell.label}>
            <p className="text-muted-foreground text-[11px] font-medium">{cell.label}</p>
            <p
              className={
                cell.value == null
                  ? 'text-muted-foreground tabular text-lg font-bold'
                  : cell.value >= 99.9
                    ? 'text-up tabular text-lg font-bold'
                    : cell.value >= 99
                      ? 'text-warning tabular text-lg font-bold'
                      : 'text-down tabular text-lg font-bold'
              }
            >
              {cell.value == null ? '—' : `${cell.value.toFixed(2)} %`}
            </p>
          </div>
        ))}
      </div>
      <p className="text-muted-foreground text-[11px] leading-relaxed">
        {t(
          'avail.note',
          'Podíl kontrol, které dopadly dobře, v každém okně. Prázdné okno znamená, že se v něm neměřilo - ne stoprocentní dostupnost.'
        )}
      </p>
    </Card>
  );
}
