import { Radio } from 'lucide-react';
import { Panel } from '@/components/ui/panel';
import { useLanguage } from '@/context/language-context';
import { splitLocationLabel } from '@/lib/public-location';
import { cn, formatPercent } from '@/lib/utils';
import { CountryFlag } from './country-flag';

/** One place the checks run from, as the public `regions` projection sends it. */
export interface ProbeRegion {
  location: string | null;
  /** ISO 3166-1 alpha-2 from the server; null = no country known, no flag. */
  country?: string | null;
  successRate: number | null;
}

/**
 * Where the checks come FROM - it answers "is the service down, or can one
 * vantage point just not see it". Each place gets its own flag drawn from the
 * server's country code (components/public/country-flag.tsx), the city, the
 * network in a quieter line and the share of its checks that succeeded.
 */
export function ProbeLocations({ regions }: { regions: readonly ProbeRegion[] }) {
  const { t, lang } = useLanguage();
  const shown = regions.slice(0, 9);
  return (
    <Panel icon={Radio} title={t('public.regions', 'Místa měření')} count={regions.length}>
      <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {shown.map((r, i) => {
          const { name, network } = splitLocationLabel(r.location);
          const rate = r.successRate;
          return (
            <li
              key={r.location ?? `none-${i}`}
              className="bg-inset flex min-w-0 items-center gap-3 rounded-lg border border-border px-3 py-2.5"
            >
              {/* No country known: no flag, only the space one takes, so the
                  names still start in one column. */}
              {r.country ? (
                <CountryFlag code={r.country} />
              ) : (
                <span aria-hidden="true" className="h-3.5 w-5 shrink-0" />
              )}
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium" title={r.location ?? undefined}>
                  {name ?? t('public.location_unknown', 'Místo neuvedeno')}
                </span>
                {network && <span className="text-muted-foreground block truncate text-2xs">{network}</span>}
              </span>
              {/* Through the floor-safe formatter: a place that lost one check
                  in 20 000 is 99,99 %, never 100 (HF-1). */}
              <span
                title={t('public.region_rate', 'Úspěšné kontroly za 30 dní')}
                className={cn(
                  'figure shrink-0 text-xs font-semibold',
                  rate === null ? 'text-muted-foreground' : rate >= 99 ? '' : rate >= 95 ? 'text-warning' : 'text-down'
                )}
              >
                <span className="sr-only">{t('public.region_rate', 'Úspěšné kontroly za 30 dní')}: </span>
                {formatPercent(rate, 2, lang)}
              </span>
            </li>
          );
        })}
      </ul>
    </Panel>
  );
}
