import * as React from 'react';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { MapPin } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { formatMs } from '@/lib/utils';

interface Region {
  /** null = the node does not report its location. No guessing. */
  location: string | null;
  checks: number;
  upChecks: number;
  downChecks: number;
  successRate: number | null;
  avgResponseMs: number | null;
  monitors: number;
  lastSeen: string | null;
}

/**
 * Where the checks really ran from.
 *
 * Deliberately NOT a world map with dots: checks today run mostly from one
 * place (cron on the hosting) and lit-up dots across continents would claim
 * something untrue. Only what is in `checked_from` is listed -
 * including an honest "location not given" for rows without one.
 */
export function RegionsPanel() {
  const { t } = useLanguage();
  const [regions, setRegions] = React.useState<Region[] | null>(null);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let active = true;
    fetch('/status/api.php?action=regions&days=7', { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((d) => {
        if (active && Array.isArray(d?.regions)) setRegions(d.regions);
      })
      .catch(() => {
        if (active) setError(t('regions.load_error', 'Přehled měřicích míst se nepodařilo načíst.'));
      });
    return () => {
      active = false;
    };
  }, [t]);

  const total = regions?.reduce((sum, r) => sum + r.checks, 0) ?? 0;

  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle className="flex items-center gap-2">
            <MapPin className="size-4 text-primary" /> {t('regions.title', 'Měřicí místa')}
          </CardTitle>
          <CardDescription>
            {t('regions.subtitle', 'Odkud se kontroly za posledních 7 dní skutečně prováděly.')}
          </CardDescription>
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {error ? (
          <p className="text-muted-foreground py-4 text-center text-sm">{error}</p>
        ) : regions === null ? (
          <p className="text-muted-foreground py-4 text-center text-sm">{t('regions.loading', 'Načítám…')}</p>
        ) : regions.length === 0 ? (
          <p className="text-muted-foreground py-4 text-center text-sm">
            {t('regions.empty', 'Za posledních 7 dní neproběhla žádná kontrola.')}
          </p>
        ) : (
          regions.map((r, i) => {
            const share = total > 0 ? (r.checks / total) * 100 : 0;
            return (
              <div key={r.location ?? `unknown-${i}`} className="rounded-lg border border-border p-3">
                <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                  <span className="text-sm font-semibold">
                    {r.location ?? (
                      <span className="text-muted-foreground italic">{t('regions.unknown', 'Místo neuvedeno')}</span>
                    )}
                  </span>
                  <span className="text-muted-foreground text-xs">
                    {t('regions.checks', { count: r.checks }, `${r.checks} kontrol`)}
                    {' · '}
                    {t('regions.monitors', { count: r.monitors }, `${r.monitors} monitorů`)}
                  </span>
                </div>

                {/* Share of the total check count - a proportion, not a map. */}
                <div className="bg-muted mt-2 h-1.5 w-full overflow-hidden rounded-full">
                  <div className="bg-primary h-full rounded-full" style={{ width: `${share}%` }} />
                </div>

                <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                  <span>
                    {t('regions.success', 'Úspěšnost')}:{' '}
                    <strong
                      className={
                        r.successRate == null
                          ? 'text-muted-foreground'
                          : r.successRate >= 99
                            ? 'text-up'
                            : r.successRate >= 95
                              ? 'text-warning'
                              : 'text-down'
                      }
                    >
                      {r.successRate == null ? '—' : `${r.successRate.toFixed(2)} %`}
                    </strong>
                  </span>
                  <span>
                    {t('regions.avg_latency', 'Průměrná odezva')}:{' '}
                    <strong className="text-foreground">
                      {r.avgResponseMs == null ? '—' : formatMs(r.avgResponseMs)}
                    </strong>
                  </span>
                  {r.downChecks > 0 && (
                    <span className="text-down">
                      {t('regions.failures', { count: r.downChecks }, `${r.downChecks} selhání`)}
                    </span>
                  )}
                </div>
              </div>
            );
          })
        )}

        {regions !== null && regions.length === 1 && (
          <p className="text-muted-foreground text-[11px] leading-relaxed">
            {t(
              'regions.single_hint',
              'Kontroly běží z jednoho místa. Další měřicí uzel se přidá instalací node agenta — teprve pak má srovnání regionů smysl.'
            )}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
