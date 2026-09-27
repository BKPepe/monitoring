import { Link } from 'react-router';
import { ArrowRight, CircleCheck, HelpCircle, History } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useLanguage } from '@/context/language-context';
import { formatDuration, formatNumber } from '@/lib/metric-format';
import { monitorTypeLabel } from '@/lib/monitor-type';
import { statusKeyOf, statusLabel, statusMeta } from '@/lib/status';

export interface OutageDetail {
  start: string;
  end: string | null;
  durationSec: number;
  reason: string;
  resolved: boolean;
}

/** One row of action=sla_report. */
export interface MonitorSla {
  id: number;
  name: string;
  target: string;
  type: string;
  currentStatus: string;
  /** null = no measured check in the window (nobody measured the SLA). */
  uptimePercent: number | null;
  outageMinutes: number;
  totalChecks: number;
  /** Measured time in the window; null = unknown. */
  measuredMinutes?: number | null;
  /** The goal's allowance over the measured time ("20 min z 43 min"); null = unknown. */
  budgetMinutes?: number | null;
  /** Incidents opened in the window; null = the count could not be read. */
  incidentCount?: number | null;
  lastOutage: OutageDetail | null;
  mttrSec: number | null;
  p50Ms?: number | null;
  p95Ms?: number | null;
  p99Ms?: number | null;
  lastStatusChange: string | null;
}

/**
 * What a row of the SLA table opens to: the numbers behind the percentage.
 * On screen it is one click away; in print it is always there, because a
 * printed report has nothing to click.
 */
export function SlaDetail({ item, days }: { item: MonitorSla; days: number }) {
  const { t, lang } = useLanguage();
  const key = statusKeyOf(item.currentStatus, true);
  const ms = (v: number | null | undefined) => (v != null ? `${formatNumber(v, lang, 0)} ms` : '—');
  const cell = 'rounded-md border border-border/60 bg-background/50 p-2.5';

  return (
    <div className="space-y-3 text-xs">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div className={cell}>
          <p className="text-muted-foreground text-3xs">{t('common.type', 'Typ')}</p>
          <p className="font-semibold">{monitorTypeLabel(item.type, t)}</p>
        </div>
        <div className={cell}>
          <p className="text-muted-foreground text-3xs">
            {t('reports.total_checks_30d', { days }, `Celkem kontrol (${days} d)`)}
          </p>
          <p className="font-semibold tabular-nums">{formatNumber(item.totalChecks, lang, 0)}</p>
        </div>
        <div className={cell}>
          <p className="text-muted-foreground text-3xs">{t('reports.mttr_label', 'MTTR (doba obnovení)')}</p>
          <p className="font-semibold tabular-nums">
            {item.mttrSec !== null ? formatDuration(item.mttrSec, lang) : t('reports.no_outage', 'Bez výpadku')}
          </p>
        </div>
        <div className={cell}>
          <p className="text-muted-foreground text-3xs">{t('reports.current_status', 'Aktuální stav')}</p>
          <Badge variant={statusMeta(key).variant}>{statusLabel(key, t)}</Badge>
        </div>
      </div>

      <div className={`${cell} space-y-1`}>
        <div className="flex items-center gap-1.5">
          <p className="text-muted-foreground text-2xs font-semibold">
            {t('reports.percentile_title', 'Percentilové Rozložení Latence (p50 / p95 / p99)')}
          </p>
          <Tooltip>
            <TooltipTrigger asChild className="print:hidden">
              <button
                type="button"
                className="text-muted-foreground hover:text-foreground cursor-help print:hidden"
                aria-label={t('reports.percentile_aria', 'Co znamenají percentily odezvy')}
              >
                <HelpCircle className="size-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent>
              <p className="mb-1 font-semibold">{t('reports.percentile_tooltip_title', 'Co percentily znamenají')}</p>
              <p>
                <strong className="text-foreground">{t('reports.p50_label', 'p50 (medián):')}</strong>{' '}
                {t(
                  'reports.p50_desc',
                  'polovina kontrol byla rychlejší, polovina pomalejší — nejlépe vystihuje typickou odezvu.'
                )}
              </p>
              <p className="mt-1">
                <strong className="text-foreground">{t('reports.p95_label', 'p95:')}</strong>{' '}
                {t(
                  'reports.p95_desc',
                  '95 % kontrol bylo rychlejších; zbylých 5 % jsou špičky (dočasné zpomalení, zátěž).'
                )}
              </p>
              <p className="mt-1">
                <strong className="text-foreground">{t('reports.p99_label', 'p99:')}</strong>{' '}
                {t(
                  'reports.p99_desc',
                  'jen 1 % kontrol bylo pomalejších — ojedinělé extrémní špičky, často síťový problém nebo přetížený server.'
                )}
              </p>
              <p className="text-muted-foreground mt-1.5 border-t border-border/60 pt-1.5">
                {t(
                  'reports.percentile_hint',
                  'Vysoké p95/p99 při nízkém p50 = nekonzistentní výkon. Hledejte příčinu v době těch špiček (log serveru, zátěž), ne v průměru.'
                )}
              </p>
            </TooltipContent>
          </Tooltip>
        </div>
        <p className="flex flex-wrap gap-x-4 gap-y-1 tabular-nums">
          <span>
            {t('reports.p50_value_label', 'p50 (Medián):')} <strong>{ms(item.p50Ms)}</strong>
          </span>
          <span>
            {t('reports.p95_value_label', 'p95 (Špičky):')} <strong>{ms(item.p95Ms)}</strong>
          </span>
          <span>
            {t('reports.p99_value_label', 'p99 (Kritické špičky):')} <strong>{ms(item.p99Ms)}</strong>
          </span>
        </p>
      </div>

      {item.lastOutage ? (
        <div className={`${cell} space-y-1`}>
          <p className="flex items-center gap-1.5 text-2xs font-semibold">
            <History aria-hidden="true" className="text-muted-foreground size-3.5" />
            {t('reports.last_outage_title', 'Poslední výpadek')}
            <Badge variant={item.lastOutage.resolved ? 'up' : 'down'}>
              {item.lastOutage.resolved
                ? t('reports.resolved_badge', 'Vyřešeno')
                : t('reports.ongoing_badge', 'Probíhá')}
            </Badge>
          </p>
          <p className="tabular-nums">
            {item.lastOutage.start} → {item.lastOutage.end ?? t('reports.outage_ongoing', 'dosud')} (
            {formatDuration(item.lastOutage.durationSec, lang)})
          </p>
          <p>
            <span className="text-muted-foreground">{t('reports.reason_label', 'Důvod:')}</span>{' '}
            {item.lastOutage.reason}
          </p>
        </div>
      ) : (
        <p className="text-muted-foreground flex items-center gap-1.5">
          <CircleCheck aria-hidden="true" className="size-3.5" />
          {t('reports.no_outage_30d', { days }, `Žádný výpadek za posledních ${days} dní.`)}
        </p>
      )}

      <Link
        to={`/infrastructure/${item.id}`}
        className="text-link inline-flex items-center gap-1 font-semibold hover:underline print:hidden"
      >
        {t('reports.view_detail', 'Otevřít detail')} <ArrowRight className="size-3" />
      </Link>
    </div>
  );
}
