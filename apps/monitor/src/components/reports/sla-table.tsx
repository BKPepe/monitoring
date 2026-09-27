import * as React from 'react';
import { ChevronDown } from 'lucide-react';
import { DayStrip, DayStripLegend } from '@/components/day-strip';
import { MonitorTypeIcon } from '@/components/monitor-type-icon';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ErrorState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import type { DayUptime } from '@/data/model';
import { formatDuration, formatNumber } from '@/lib/metric-format';
import { monitorTypeLabel } from '@/lib/monitor-type';
import { cn, formatPercentValue } from '@/lib/utils';
import { SlaDetail, type MonitorSla } from './sla-detail';

/** The 30-day strips of every row, fetched once for the table. */
export type StripsState =
  { status: 'loading' } | { status: 'error' } | { status: 'ready'; series: Record<string, DayUptime[]> };

/**
 * Worst first: the lowest availability leads, a tie goes to the longer
 * downtime, and a monitor nobody measured sits at the end - it is not the
 * best, it is unknown.
 */
function worstFirst(a: MonitorSla, b: MonitorSla): number {
  if (a.uptimePercent == null || b.uptimePercent == null) {
    return Number(a.uptimePercent == null) - Number(b.uptimePercent == null);
  }
  return a.uptimePercent - b.uptimePercent || b.outageMinutes - a.outageMinutes;
}

/**
 * The SLA report as one table (W2-7): type, name, SLA, downtime against the
 * goal's allowance, incidents and the last 30 days, worst first.
 *
 * Fifteen bordered cards used to say this, each with its own 90-100 % bar
 * whose scale was printed fifteen times, and the bar ranked nothing: every
 * monitor under 90 % drew the same full red bar (charts-19). "20 min z 43 min"
 * says how much of the allowance is used, the scale is in the header once,
 * and colour marks only a value that misses the goal.
 */
export function SlaTable({
  rows,
  slaGoal,
  days,
  strips,
}: {
  rows: MonitorSla[];
  slaGoal: number;
  days: number;
  strips: StripsState;
}) {
  const { t, lang } = useLanguage();
  const [open, setOpen] = React.useState<number | null>(null);
  const sorted = React.useMemo(() => [...rows].sort(worstFirst), [rows]);
  const minutes = (n: number) => formatDuration(n * 60, lang);
  const goal = `${formatNumber(slaGoal, lang, 3)} %`;
  const columns = 6;

  return (
    <div className="space-y-3">
      {strips.status === 'error' && (
        <ErrorState
          tone="warning"
          message={t(
            'reports.strips_failed',
            'Denní pásy se nepodařilo načíst. Procenta a výpadky v tabulce platí, chybí jen rozpad po dnech.'
          )}
        />
      )}
      <Table aria-label={t('reports.table_label', 'Plnění SLA po monitorech')}>
        <TableHeader>
          <TableRow>
            <TableHead className="hidden w-6 sm:table-cell">
              <span className="sr-only">{t('common.type', 'Typ')}</span>
            </TableHead>
            <TableHead>{t('common.name', 'Název')}</TableHead>
            {/* Headers may wrap and names truncate sooner on a phone, so the
                SLA and the budget - the point of the table - fit on a 390 px
                screen instead of behind a sideways scroll (V-19). */}
            <TableHead className="text-right whitespace-normal">
              {t('reports.col_sla', { goal }, `SLA (cíl ${goal})`)}
            </TableHead>
            <TableHead className="text-right whitespace-normal">
              {t('reports.col_budget', 'Výpadek z rozpočtu')}
            </TableHead>
            <TableHead className="hidden text-right sm:table-cell">{t('reports.col_incidents', 'Incidenty')}</TableHead>
            <TableHead className="hidden lg:table-cell">{t('reports.col_days', 'Posledních 30 dní')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {sorted.map((item) => {
            const measured = item.uptimePercent != null;
            const missed = measured && (item.uptimePercent as number) < slaGoal;
            const budget = item.budgetMinutes;
            const overBudget = budget != null && item.outageMinutes > budget;
            const expanded = open === item.id;
            const days30 = strips.status === 'ready' ? strips.series[String(item.id)] : undefined;
            const detailId = `sla-detail-${item.id}`;
            return (
              <React.Fragment key={item.id}>
                <TableRow data-sla-row={item.id} className="print:break-inside-avoid">
                  {/* The type icon gives way on a phone: SLA and budget come first. */}
                  <TableCell className="hidden sm:table-cell">
                    <MonitorTypeIcon type={item.type} label={monitorTypeLabel(item.type, t)} />
                  </TableCell>
                  {/* On a phone the name wraps instead of truncating: it is the one
                      thing a row must show ("Router t…" named nothing, V-04). */}
                  <TableCell className="min-w-[7rem] sm:max-w-[16rem]">
                    <button
                      type="button"
                      aria-expanded={expanded}
                      aria-controls={detailId}
                      onClick={() => setOpen(expanded ? null : item.id)}
                      className="hover:text-primary flex max-w-full items-center gap-1.5 text-left font-semibold"
                    >
                      <span className="min-w-0 break-words sm:truncate">{item.name}</span>
                      <ChevronDown
                        aria-hidden="true"
                        className={cn(
                          'text-muted-foreground size-3.5 shrink-0 transition-transform print:hidden',
                          expanded && 'rotate-180'
                        )}
                      />
                    </button>
                  </TableCell>
                  <TableCell
                    className={cn('text-right font-semibold whitespace-nowrap tabular-nums', missed && 'text-down')}
                  >
                    {/* Never rounded up to 100: 5 s in 30 days is 99.999, not "100 %". */}
                    {measured
                      ? `${formatNumber(Number(formatPercentValue(item.uptimePercent as number, 2)), lang, 2)} %`
                      : '—'}
                  </TableCell>
                  <TableCell className="text-right tabular-nums sm:whitespace-nowrap">
                    {!measured ? (
                      <span className="text-muted-foreground">{t('reports.not_measured', 'Bez měření')}</span>
                    ) : (
                      <>
                        <span className={cn(overBudget && 'text-down font-semibold')}>
                          {minutes(item.outageMinutes)}
                        </span>
                        <span className="text-muted-foreground">
                          {' '}
                          {t('reports.budget_of', { budget: budget != null ? minutes(budget) : '—' }, 'z {budget}')}
                        </span>
                      </>
                    )}
                  </TableCell>
                  <TableCell className="hidden text-right tabular-nums sm:table-cell">
                    {item.incidentCount == null ? '—' : formatNumber(item.incidentCount, lang, 0)}
                  </TableCell>
                  <TableCell className="hidden lg:table-cell">
                    {days30 && days30.length > 0 ? (
                      <DayStrip
                        days={days30}
                        size="sm"
                        detail="caption"
                        label={t('reports.strip_label', { name: item.name }, `Dostupnost ${item.name} po dnech`)}
                      />
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </TableCell>
                </TableRow>
                <TableRow
                  id={detailId}
                  className={cn('hover:bg-transparent', expanded ? 'table-row' : 'hidden print:table-row')}
                >
                  <TableCell colSpan={columns} className="bg-secondary/20">
                    <SlaDetail item={item} days={days} />
                  </TableCell>
                </TableRow>
              </React.Fragment>
            );
          })}
        </TableBody>
      </Table>
      {strips.status === 'ready' && <DayStripLegend className="hidden lg:flex print:hidden" />}
    </div>
  );
}
