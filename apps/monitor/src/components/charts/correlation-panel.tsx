import { Link } from 'react-router';
import type { MetricCorrelationsResponse } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

/**
 * Which other metrics of the same device moved together with this one.
 *
 * The chart says CPU peaked at 19:40 and "what was running" answers with
 * processes; this answers with the device's other measurements - the peak came
 * with iowait, or with nothing at all.
 *
 * Two deliberate choices about honesty:
 *
 *   - A metric whose coefficient is undefined (it never changed, or too few
 *     overlapping samples) shows a dash and the reason, never a bar at zero.
 *     Zero would assert "these two are unrelated", which is a claim the data
 *     did not make.
 *   - The bar is diverging in blue/orange, not the status green/red. A
 *     negative correlation is not bad news; the colour carries direction only.
 */
export function CorrelationPanel({
  data,
  assetId,
  monitorId,
}: {
  data: MetricCorrelationsResponse;
  assetId: string | number | undefined;
  monitorId: number;
}) {
  const { t } = useLanguage();

  if (data.correlations.length === 0) {
    return (
      <p className="text-muted-foreground text-xs">
        {data.samples === 0
          ? t('corr.no_samples', 'Za zvolené období nejsou od agenta žádná měření, ze kterých by šlo počítat.')
          : t('corr.no_others', 'Toto zařízení nehlásí žádnou další metriku, se kterou by šlo porovnávat.')}
      </p>
    );
  }

  return (
    <div className="space-y-3">
      <ul className="space-y-1">
        {data.correlations.map((c) => {
          const row = (
            <>
              <span className="min-w-0 flex-1 truncate">{c.label}</span>
              <CorrelationBar r={c.r} />
              <span
                className={cn('w-12 shrink-0 text-right text-xs tabular-nums', c.r === null && 'text-muted-foreground')}
              >
                {c.r === null ? '—' : formatR(c.r)}
              </span>
            </>
          );

          return (
            <li key={c.key}>
              {assetId !== undefined ? (
                <Link
                  to={`/infrastructure/${assetId}/metric/${monitorId}/${c.key}`}
                  className="hover:bg-secondary/50 focus-visible:ring-ring -mx-2 flex items-center gap-3 rounded-md px-2 py-1.5 text-xs transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                  {row}
                </Link>
              ) : (
                <div className="-mx-2 flex items-center gap-3 px-2 py-1.5 text-xs">{row}</div>
              )}
              {c.reason && (
                <p className="text-muted-foreground -mt-0.5 px-2 pb-1 text-[10px]">
                  {c.reason === 'constant'
                    ? t('corr.reason_constant', 'hodnota se za celé období nezměnila, vztah nelze spočítat')
                    : t(
                        'corr.reason_few',
                        { pairs: c.pairs },
                        `měření se překrývají jen v ${c.pairs} bodech, na výpočet je to málo`
                      )}
                </p>
              )}
            </li>
          );
        })}
      </ul>

      <p className="text-muted-foreground text-[11px] leading-relaxed">
        {t(
          'corr.note',
          { samples: data.samples },
          `Spočítáno z ${data.samples} měření, která agent poslal za zvolené období. Číslo je míra souběhu od -1 do +1: kladné znamená, že metriky rostly společně, záporné, že šly proti sobě. Souběh ale neříká nic o příčině - obojí může mít společného původce, nebo jít o náhodu.`
        )}
      </p>
      {data.total > data.correlations.length && (
        <p className="text-muted-foreground text-[11px]">
          {t(
            'corr.truncated',
            { shown: data.correlations.length, total: data.total },
            `Zobrazeno ${data.correlations.length} nejsilnějších z ${data.total} porovnávaných metrik.`
          )}
        </p>
      )}
    </div>
  );
}

/**
 * Diverging bar: zero in the middle, positive to the right, negative to the
 * left. An undefined coefficient draws no bar at all - a zero-length bar at
 * the centre is indistinguishable from a measured zero.
 */
function CorrelationBar({ r }: { r: number | null }) {
  const { t } = useLanguage();

  if (r === null) {
    return (
      <span className="relative h-3 w-28 shrink-0" aria-hidden="true">
        <span className="bg-border absolute inset-y-0 left-1/2 w-px" />
      </span>
    );
  }

  const width = Math.abs(r) * 50; // percent of the track, half per direction
  const positive = r >= 0;

  return (
    <span
      className="relative h-3 w-28 shrink-0"
      title={t('corr.bar_title', { value: formatR(r) }, `Míra souběhu ${formatR(r)}`)}
    >
      <span className="bg-secondary absolute inset-0 rounded-sm" />
      <span
        className="absolute inset-y-0.5 rounded-sm"
        style={{
          left: positive ? '50%' : `${50 - width}%`,
          width: `${width}%`,
          backgroundColor: positive ? 'var(--chart-corr-positive)' : 'var(--chart-corr-negative)',
        }}
      />
      {/* The zero line stays on top of the bar so the direction is readable
          even when the bar nearly fills its half. */}
      <span className="bg-border absolute inset-y-0 left-1/2 w-px" />
    </span>
  );
}

/** Always signed: "+0.87" reads as a direction, "0.87" only as a size. */
function formatR(r: number): string {
  return `${r > 0 ? '+' : r < 0 ? '−' : ''}${Math.abs(r).toFixed(2)}`;
}
