import * as React from 'react';
import { dayCellStatus } from '@/components/day-strip';
import { useLanguage } from '@/context/language-context';
import type { DayStatus, DayUptime } from '@/data/model';
import { cn } from '@/lib/utils';

/** One day of `action=daily_uptime` (C-7), as the public card receives it. */
export type UptimeDay = DayUptime;

/**
 * The paint of each day status - the same classes as DayStrip's cells
 * (components/day-strip.tsx), so the one DayStripLegend under the services
 * names these cells too. The public strip keeps its own cells only for their
 * width: 30 of them share whatever a 390 px phone row leaves next to the
 * figures, where DayStrip's fixed 7 px cells would push them off the screen.
 */
const CELL: Record<DayStatus, string> = {
  up: 'bg-up/70',
  warning: 'bg-warning',
  down: 'bg-down',
  maintenance: 'bg-info/70',
  partial: 'hatch text-up border border-up/40',
  nodata: 'border border-dashed border-border bg-muted/40',
};

/**
 * The 30-day availability strip - one cell per day.
 *
 * The first version hung the detail on a title= attribute. That tooltip does
 * not exist on touch devices at all and appears after a second's delay on
 * desktop - reported as "tooltips don't work". Instead of 180 tooltip
 * portals (30 days x 6 services), one shared caption under the strip shows
 * the hovered, tapped or keyboard-picked day.
 *
 * Every status the server words gets its own paint (C-7): a warning or
 * maintenance day was grey like a day nobody measured. An unknown status -
 * the 'paused' an older server sent for an unmeasured day included - is
 * drawn as unmeasured, never green: a day nobody measured is not a day
 * without outages.
 */
export function UptimeStrip({ days }: { days: UptimeDay[] }) {
  const { t } = useLanguage();
  // Ninety cells share a phone row too; the gap shrinks with them so the
  // cells, not the gaps, carry the width (NetPulse-thin bars).
  const dense = days.length > 45;
  const [picked, setPicked] = React.useState<number | null>(null);

  if (days.length === 0) {
    return (
      <p className="text-muted-foreground text-2xs">{t('public.no_history', 'Historie zatím není k dispozici')}</p>
    );
  }

  // The cells are marks, not controls, so the strip takes focus as a whole
  // and the arrow keys walk the days: the day detail was mouse- and
  // touch-only (PA-7).
  const last = days.length - 1;
  const onKeyDown = (e: React.KeyboardEvent<HTMLDivElement>) => {
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
  const shown = picked != null ? days[picked] : null;

  return (
    // The full width of the card at every size: a fixed 300 px strip pushed
    // the figures off a 390 px screen, and 90 days need the room.
    <div className="w-full min-w-0">
      <div
        role="group"
        tabIndex={0}
        aria-label={`${t('public.uptime_strip_aria', { days: days.length }, `Dostupnost po dnech, posledních ${days.length} dní`)} (${t('day.keys_hint', 'šipkami vlevo a vpravo po dnech')})`}
        // The newest day first: it is the one a visitor comes to check.
        onFocus={(e) => e.target === e.currentTarget && setPicked((p) => p ?? last)}
        onBlur={(e) => e.target === e.currentTarget && setPicked(null)}
        onKeyDown={onKeyDown}
        onMouseLeave={() => setPicked(null)}
        className={cn(
          'focus-visible:ring-ring flex items-end rounded-[3px] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
          dense ? 'gap-px' : 'gap-[2px] sm:gap-[3px]'
        )}
      >
        {days.map((d, i) => (
          // Span, not button: a cell is a mark to read, and 30 tab stops per
          // service would bury the rest of the page.
          <span
            key={d.day ?? `${d.date}-${i}`}
            role="img"
            aria-label={`${d.date} ${d.detail ?? ''}`.trim()}
            data-day-status={dayCellStatus(d.status)}
            onMouseEnter={() => setPicked(i)}
            onClick={(e) => {
              e.stopPropagation();
              setPicked(i);
            }}
            className={cn(
              // Cells share the width: 30 of them fit a phone as well as a desktop.
              'h-7 min-w-0 flex-1 transition-transform hover:scale-y-110 motion-reduce:transition-none motion-reduce:hover:scale-y-100',
              dense ? 'rounded-[1px]' : 'rounded-[2px]',
              CELL[dayCellStatus(d.status)],
              picked === i && 'ring-foreground/70 ring-2'
            )}
          />
        ))}
      </div>
      {/* The caption keeps its height even when empty so the row does not
          jump the first time a day is picked; aria-live reads the day the
          arrow keys land on. */}
      {/* Without a picked day the line names the strip's two ends, so the
          visitor knows which side is today without hovering. */}
      <p aria-live="polite" className="text-muted-foreground mt-1 flex h-4 justify-between gap-3 text-3xs tabular-nums">
        {shown ? (
          <span className="min-w-0 flex-1 truncate text-right">{`${shown.date} ${shown.detail ?? ''}`.trim()}</span>
        ) : (
          <>
            <span aria-hidden="true">{days[0].date}</span>
            <span aria-hidden="true">{days[last].date}</span>
          </>
        )}
      </p>
    </div>
  );
}
