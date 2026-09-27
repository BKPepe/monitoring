import { Link } from 'react-router';
import { useLanguage } from '@/context/language-context';
import type { HealthScore } from '@/lib/health';
import { formatNumber } from '@/lib/metric-format';
import { cn } from '@/lib/utils';

/**
 * One named deduction of the health score (SCORE, formulaVersion 1): what
 * took points off and how many. The server words `label` in the request's
 * language and already names the value and the limit ("Teplota CPU 71 °C,
 * nad 65 °C"); the app only draws it. On the network score `points` is what
 * the item costs the NETWORK (its device's points divided by the devices
 * scored), and `monitorId`/`monitorName` say whose it is.
 */
export interface HealthDeduction {
  component: string;
  kind: string;
  label?: string;
  points: number;
  value?: number | null;
  limit?: number | null;
  unit?: string | null;
  monitorId?: number;
  monitorName?: string | null;
}

/**
 * The score answer as the server sends it now: lib/health's shape plus the
 * named deductions and the paused flag (requests.md "From SCORE"). Local to
 * the pages until the typed API carries it (APP-CORE request).
 */
export type ScoredHealth = HealthScore & {
  deductions?: HealthDeduction[];
  paused?: true;
  measuredComponents?: number;
};

/**
 * The kinds that name an outage or a broken part rather than a value past a
 * limit. Their bar takes the "down" colour; the words already say which.
 */
const OUTAGE_KINDS = new Set([
  'availability_low',
  'status_down',
  'unreachable',
  'incident_open',
  'wan_lost',
  'lte_backup_lost',
  'ssl_expired',
  'smart_failed',
  'firewall_disabled',
  'dns_resolver_failed',
]);

/**
 * "−4", or "−0,4" below one point: rounding a 0.4-point deduction to a whole
 * number would print "−0", a cost that reads as none.
 */
export function deductionPoints(points: number, lang: 'cs' | 'en'): string {
  const shown = points < 1 ? formatNumber(points, lang, 1) : String(Math.round(points));
  return `−${shown}`;
}

/**
 * The NetPulse "Patio temp. −4" list under a ring: each deduction by name,
 * a bar as long as its cost and the points it took. Biggest first (the
 * server's order). A bar's scale is at least ten points, so a 0.5-point item
 * does not fill the row just because it is the only one.
 *
 * Nothing renders for a score without deductions (a full 100, or no score at
 * all - "—" has nothing to take points from).
 */
export function HealthDeductions({
  deductions,
  limit = 5,
  linkDevices = false,
  className,
}: {
  deductions: readonly HealthDeduction[] | null | undefined;
  limit?: number;
  /** Network list: each row names its device and links to it. */
  linkDevices?: boolean;
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const rows = (deductions ?? []).filter((d) => Number.isFinite(d.points) && d.points > 0).slice(0, limit);
  if (rows.length === 0) return null;
  const scale = Math.max(10, ...rows.map((d) => d.points));

  return (
    <ul aria-label={t('health.deductions_title', 'Co ubírá body')} className={cn('flex flex-col gap-1.5', className)}>
      {rows.map((d, i) => {
        const cost = deductionPoints(d.points, lang);
        const text = d.label ?? d.kind;
        const device = linkDevices && d.monitorName ? d.monitorName : null;
        const words = (
          <>
            {device && <span className="text-foreground font-medium">{device} · </span>}
            {text}
          </>
        );
        return (
          <li
            key={`${d.monitorId ?? ''}-${d.kind}-${i}`}
            className="grid grid-cols-[minmax(0,1fr)_3rem_2.25rem] items-center gap-3 sm:grid-cols-[minmax(0,1fr)_6rem_2.25rem]"
          >
            <span className="text-muted-foreground min-w-0 text-xs leading-snug">
              {device && d.monitorId != null ? (
                <Link
                  to={`/infrastructure/${d.monitorId}`}
                  className="focus-visible:ring-ring hover:text-foreground rounded-sm hover:underline focus-visible:ring-2 focus-visible:outline-none"
                >
                  {words}
                </Link>
              ) : (
                words
              )}
            </span>
            <span aria-hidden="true" className="bg-inset h-1.5 overflow-hidden rounded-full">
              <span
                className={cn('block h-full rounded-full', OUTAGE_KINDS.has(d.kind) ? 'bg-down' : 'bg-warning')}
                style={{ width: `${Math.max(4, (d.points / scale) * 100)}%` }}
              />
            </span>
            <span className="figure text-warning text-right text-xs">
              <span aria-hidden="true">{cost}</span>
              <span className="sr-only">
                {t('health.deduction_sr', { points: cost.slice(1) }, `ubírá ${cost.slice(1)} b.`)}
              </span>
            </span>
          </li>
        );
      })}
    </ul>
  );
}
