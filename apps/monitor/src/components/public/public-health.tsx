import { HealthBreakdown, HealthRing } from '@/components/health-ring';
import { ErrorState, Skeleton } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { formatNumber } from '@/lib/metric-format';
import type { PublicHealth } from '@/lib/public-health';

/**
 * The public services score beside the verdict (owner decision: a health
 * ring with its point breakdown, NetPulse look): the server's number, the
 * components it was built from and the named items that cost points.
 *
 * It is the score of the PUBLIC SET - availability, latency, alerts and data
 * freshness of the services on this page, no hardware - and the caption says
 * so. "—" is the server's "not enough data"; a request that failed is said
 * as a failure, never drawn as an empty ring.
 */
export function PublicHealthScore({ health, failed }: { health: PublicHealth | null; failed: boolean }) {
  const { t, lang } = useLanguage();
  const caption = t('public.health_caption', 'Skóre veřejných služeb');

  if (health === null) {
    return failed ? (
      <div className="flex w-full max-w-72 flex-col items-center gap-3 text-center">
        <p className="micro-label">{caption}</p>
        <ErrorState
          tone="warning"
          message={t('public.health_failed', 'Skóre se nepodařilo načíst.')}
          className="w-full"
        />
      </div>
    ) : (
      // Roughly the answer's own height (ring, grade, caption, breakdown,
      // three deductions), so the score landing does not push the page.
      <div aria-busy="true" className="flex w-full max-w-80 flex-col items-center gap-3">
        <Skeleton className="size-44 rounded-full" />
        <Skeleton className="h-5 w-24 rounded-full" />
        <Skeleton className="h-3 w-36" />
        <Skeleton className="h-8 w-56" />
        <Skeleton className="h-3 w-28" />
        <Skeleton className="h-16 w-full" />
      </div>
    );
  }

  const top = health.deductions.slice(0, 3);
  const scale = Math.max(10, ...top.map((d) => d.points));
  return (
    <div className="flex w-full max-w-80 flex-col items-center gap-3" data-slot="public-health">
      <HealthRing score={health.score} grade={health.grade} size="lg" caption={caption} />
      {health.components.length > 0 && <HealthBreakdown components={health.components} variant="compact" />}
      {health.assetsTotal != null && health.assetsScored != null && (
        <p className="text-muted-foreground text-2xs">
          {t(
            'public.health_scored',
            { scored: health.assetsScored, total: health.assetsTotal },
            `Hodnoceno služeb: ${health.assetsScored} z ${health.assetsTotal}`
          )}
        </p>
      )}
      {/* What cost the points, worst first, the NetPulse deduction rows. The
          sentence is the server's, in the page language; the bar only ranks. */}
      {top.length > 0 && (
        <ul aria-label={t('public.health_deductions', 'Co ubírá body')} className="flex w-full flex-col gap-1.5">
          {top.map((d, i) => {
            const points = formatNumber(d.points, lang, 1);
            return (
              <li
                key={`${d.monitorId ?? 'n'}-${d.component}-${i}`}
                className="grid grid-cols-[minmax(0,1fr)_3.5rem_2.5rem] items-center gap-2.5"
              >
                <span
                  className="text-muted-foreground truncate text-right text-xs"
                  title={d.monitorName ? `${d.monitorName} · ${d.label}` : d.label}
                >
                  {d.monitorName && <span className="text-foreground">{d.monitorName} · </span>}
                  {d.label}
                </span>
                <span aria-hidden="true" className="bg-inset h-1.5 overflow-hidden rounded-full">
                  <span
                    className="bg-warning block h-full rounded-full"
                    style={{ width: `${Math.min(100, (d.points / scale) * 100)}%` }}
                  />
                </span>
                <span className="figure text-warning text-right text-xs">−{points}</span>
              </li>
            );
          })}
        </ul>
      )}
      {failed && (
        <p className="text-warning text-2xs font-medium" role="status">
          {t('public.health_stale', 'Obnovení skóre selhalo, platí poslední známé.')}
        </p>
      )}
    </div>
  );
}
