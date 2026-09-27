import * as React from 'react';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { useLanguage } from '@/context/language-context';
import type { DayStatus, DayUptime } from '@/data/model';
import { formatDuration, formatNumber } from '@/lib/metric-format';
import { cn, formatPercentValue } from '@/lib/utils';

/**
 * One day-cell mapping for every availability strip (C-7): the dashboard
 * history, the public page and the SLA table draw a day the same way.
 *
 * Three strips used to decide on their own. The public one painted warning
 * and maintenance days grey like a day nobody measured, the dashboard called
 * an unmeasured day "Pozastaveno", and the tooltip coloured its badge by the
 * percentage, so a warning day read "100 % Uptime" in green over an amber
 * cell. Now the cell and the badge both follow the day's status, and two
 * honest states exist that a green/red strip cannot say:
 * - partial: nothing went wrong, but less than 90 % of the day was measured
 *   ("14 h z 24 h") - hatched, because a green cell would vouch for hours
 *   nobody watched;
 * - nodata: not one measurement - a dashed outline, not a colour.
 */
type TranslateFn = ReturnType<typeof useLanguage>['t'];

const CELL: Record<DayStatus, string> = {
  // Muted: a month of healthy days is the background, the problems are the
  // figure. 70 %, not 60: in light the cell must still reach 3:1 against the
  // card (2.8:1 at 60 %, PA-8).
  up: 'bg-up/70',
  warning: 'bg-warning',
  down: 'bg-down',
  maintenance: 'bg-info/70',
  partial: 'hatch text-up border border-up/40',
  nodata: 'border border-dashed border-border bg-muted/40',
};

const BADGE: Record<DayStatus, 'up' | 'warning' | 'down' | 'info' | 'neutral'> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  maintenance: 'info',
  partial: 'neutral',
  nodata: 'neutral',
};

const DAY_STATUSES: readonly DayStatus[] = ['up', 'warning', 'down', 'maintenance', 'partial', 'nodata'];

/**
 * A status from the server as a cell. Anything this build does not know -
 * the 'paused' an older server sent for an unmeasured day included - is
 * drawn as unmeasured, never as a healthy day.
 */
export function dayCellStatus(status: string | null | undefined): DayStatus {
  return (DAY_STATUSES as readonly string[]).includes(status ?? '') ? (status as DayStatus) : 'nodata';
}

function dayLabel(status: DayStatus, t: TranslateFn): string {
  switch (status) {
    case 'up':
      return t('day.up', 'Bez výpadku');
    case 'warning':
      return t('day.warning', 'Zhoršená odezva');
    case 'down':
      return t('day.down', 'Výpadek');
    case 'maintenance':
      return t('day.maintenance', 'Údržba');
    case 'partial':
      return t('day.partial', 'Měřeno jen zčásti');
    case 'nodata':
      return t('day.nodata', 'Bez měření');
  }
}

/** The minutes behind a day, only those that happened: "výpadek 12 min · zhoršeno 30 min". */
function dayMinutes(day: DayUptime, t: TranslateFn, lang: string): string | null {
  const parts: string[] = [];
  const minutes = (n: number) => formatDuration(n * 60, lang);
  if (day.downMin) parts.push(t('day.down_min', { time: minutes(day.downMin) }, `výpadek ${minutes(day.downMin)}`));
  if (day.degradedMin) {
    parts.push(t('day.degraded_min', { time: minutes(day.degradedMin) }, `zhoršeno ${minutes(day.degradedMin)}`));
  }
  if (day.maintenanceMin) {
    parts.push(
      t('day.maintenance_min', { time: minutes(day.maintenanceMin) }, `údržba ${minutes(day.maintenanceMin)}`)
    );
  }
  return parts.length > 0 ? parts.join(' · ') : null;
}

/** "měřeno 14 h z 24 h" for a day measured under 90 % of its time; null otherwise. */
function coverageText(day: DayUptime, t: TranslateFn, lang: string): string | null {
  if (day.coveragePct == null || day.coveragePct >= 90 || !day.expectedSecs || day.measuredSecs == null) return null;
  // In hours, as the server words a partial day ("14 h z 24 h"), not "14 h z 1 d".
  const hours = (secs: number) =>
    secs >= 3600 ? `${formatNumber(Math.round(secs / 3600), lang, 0)} h` : formatDuration(secs, lang);
  const measured = hours(day.measuredSecs);
  const expected = hours(day.expectedSecs);
  return t('day.coverage', { measured, expected }, `měřeno ${measured} z ${expected}`);
}

function dayCoverage(day: DayUptime, t: TranslateFn, lang: string): string | null {
  const status = dayCellStatus(day.status);
  // A partial day's server sentence already says it; a day without data has none.
  if (status === 'partial' || status === 'nodata') return null;
  return coverageText(day, t, lang);
}

/**
 * A day's share at one decimal, never rounded up to 100: 30 s of outage is
 * 99.965 %, which half-up rounding printed as "100 %" under a red cell.
 */
function dayPct(pct: number, lang: string): string {
  return `${formatNumber(Number(formatPercentValue(pct, 1)), lang, 1)} %`;
}

function cellName(day: DayUptime, t: TranslateFn, lang: string): string {
  const pct = day.uptimePct != null ? ` · ${dayPct(day.uptimePct, lang)}` : '';
  // A partly measured day says how much was measured in its name too: "100 %"
  // alone read as a full day to anyone who never saw the hover detail.
  const coverage = dayCellStatus(day.status) === 'partial' ? coverageText(day, t, lang) : null;
  return `${day.date} · ${dayLabel(dayCellStatus(day.status), t)}${pct}${coverage ? ` · ${coverage}` : ''}`;
}

/** Everything known about one day: the state, the share, the minutes and the server's own sentence. */
function DayDetail({ day }: { day: DayUptime }) {
  const { t, lang } = useLanguage();
  const status = dayCellStatus(day.status);
  const minutes = dayMinutes(day, t, lang);
  const coverage = dayCoverage(day, t, lang);
  return (
    <>
      <div className="border-border flex items-center justify-between gap-2 border-b pb-1.5">
        <span className="font-mono text-xs font-semibold">{day.date}</span>
        {/* Coloured by the day's status, never by its percentage: a warning
            day is available time, but it is still an amber day (charts-26). */}
        <Badge variant={BADGE[status]}>{dayLabel(status, t)}</Badge>
      </div>
      {day.uptimePct != null && (
        <p className="font-mono tabular-nums">
          {t('day.uptime', { pct: dayPct(day.uptimePct, lang) }, `Dostupnost ${dayPct(day.uptimePct, lang)}`)}
        </p>
      )}
      {minutes && <p className="text-muted-foreground">{minutes}</p>}
      {coverage && <p className="text-muted-foreground">{coverage}</p>}
      {day.detail && <p className="text-muted-foreground leading-relaxed">{day.detail}</p>}
    </>
  );
}

export function DayStrip({
  days,
  label,
  href,
  detail = 'popover',
  size = 'md',
  popoverSide = 'top',
  nested = false,
}: {
  days: DayUptime[];
  /** What the strip is, for a screen reader. */
  label: string;
  /** Every cell leads here (the device page); without it the cells are plain marks. */
  href?: string;
  /**
   * 'popover' floats the day's detail over the strip. 'caption' writes it on
   * one line under the strip - for a dense row (the SLA table, the public
   * card), where a floating card would cover the rows and touch has no hover.
   */
  detail?: 'popover' | 'caption';
  size?: 'sm' | 'md';
  /** Where the popover opens; the top rows of a table open downwards. */
  popoverSide?: 'top' | 'bottom';
  /**
   * The strip sits inside another control (a button): it must not take focus
   * itself, because a focusable element inside a button is invalid HTML.
   */
  nested?: boolean;
}) {
  const { t, lang } = useLanguage();
  const [picked, setPicked] = React.useState<number | null>(null);
  // Cells that are links take focus one by one. Plain cells (a caption strip,
  // or a strip without a target) cannot, so the strip itself takes focus and
  // the arrow keys walk the days - otherwise the day detail was mouse-only
  // (PA-7: the SLA table's strips).
  const walkable = !(href && detail === 'popover') && !nested;
  const onKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
    if (days.length === 0) return;
    const last = days.length - 1;
    const from = picked ?? last;
    const next =
      e.key === 'ArrowLeft'
        ? Math.max(0, from - 1)
        : e.key === 'ArrowRight'
          ? Math.min(last, from + 1)
          : e.key === 'Home'
            ? 0
            : e.key === 'End'
              ? last
              : null;
    if (next === null) return;
    e.preventDefault();
    setPicked(next);
  };

  const cellClass = (day: DayUptime) =>
    cn(
      'block shrink-0 transition-transform',
      size === 'md'
        ? 'h-8 min-w-3.5 flex-1 rounded-[4px] hover:scale-110'
        : 'h-6 w-[7px] rounded-[2px] hover:scale-y-110',
      CELL[dayCellStatus(day.status)]
    );

  return (
    <div className="min-w-0">
      <div
        role="group"
        aria-label={walkable ? `${label} (${t('day.keys_hint', 'šipkami vlevo a vpravo po dnech')})` : label}
        tabIndex={walkable ? 0 : undefined}
        // The newest day first: it is the one a visitor comes to check.
        onFocus={walkable ? (e) => e.target === e.currentTarget && setPicked((p) => p ?? days.length - 1) : undefined}
        onBlur={walkable ? (e) => e.target === e.currentTarget && setPicked(null) : undefined}
        onKeyDown={walkable ? onKeyDown : undefined}
        className={cn(
          'flex items-end rounded-[4px]',
          size === 'md' ? 'gap-1' : 'gap-[3px]',
          walkable &&
            'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none'
        )}
        onMouseLeave={() => setPicked(null)}
      >
        {days.map((day, i) => {
          const name = cellName(day, t, lang);
          const pick = () => setPicked(i);
          const common = {
            onMouseEnter: pick,
            onFocus: pick,
            onBlur: () => setPicked(null),
            'data-day-status': dayCellStatus(day.status),
          };
          const cell =
            href && detail === 'popover' ? (
              <Link
                to={href}
                aria-label={name}
                className={cn(
                  cellClass(day),
                  'focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none'
                )}
                {...common}
              />
            ) : (
              // A span, not a button: a strip may sit inside another control
              // (`nested`), and a button in a button is invalid HTML.
              <span
                aria-label={name}
                role="img"
                data-picked={picked === i || undefined}
                className={cn(cellClass(day), picked === i && walkable && 'ring-foreground/70 ring-2')}
                onClick={(e) => {
                  if (detail === 'caption') e.stopPropagation();
                  pick();
                }}
                {...common}
              />
            );
          return (
            <div key={day.day ?? `${day.date}-${i}`} className={cn('relative', size === 'md' && 'flex-1')}>
              {cell}
              {detail === 'popover' && picked === i && (
                // The same card as every chart tooltip (charts/chart-style.ts).
                <div
                  role="tooltip"
                  className={cn(
                    'bg-popover text-popover-foreground border-border-strong pointer-events-none absolute z-50 flex w-64 flex-col gap-1.5 rounded-[10px] border p-3 text-xs',
                    popoverSide === 'bottom' ? 'top-full mt-2' : 'bottom-full mb-2',
                    i > days.length - 5 ? 'right-0' : i < 4 ? 'left-0' : 'left-1/2 -translate-x-1/2'
                  )}
                >
                  <DayDetail day={day} />
                </div>
              )}
            </div>
          );
        })}
      </div>
      {detail === 'caption' && (
        // Keeps its height while empty so the row does not jump on the first hover.
        // aria-live: the arrow keys change it, and a screen reader hears the day.
        <p aria-live="polite" className="text-muted-foreground mt-0.5 h-4 truncate text-right text-3xs tabular-nums">
          {picked != null && days[picked]
            ? `${cellName(days[picked], t, lang)}${days[picked].detail ? ` · ${days[picked].detail}` : ''}`
            : ' '}
        </p>
      )}
    </div>
  );
}

/** One line naming every cell kind, in the strip's own colours. */
export function DayStripLegend({ className }: { className?: string }) {
  const { t } = useLanguage();
  return (
    <ul className={cn('text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs', className)}>
      {DAY_STATUSES.map((status) => (
        <li key={status} className="flex items-center gap-1.5">
          <span aria-hidden="true" className={cn('size-3 shrink-0 rounded-[3px]', CELL[status])} />
          {dayLabel(status, t)}
        </li>
      ))}
    </ul>
  );
}
