import * as React from 'react';
import { Link } from 'react-router';
import type { UptimeHistoryRow } from '@/data/model';
import { useLanguage } from '@/context/language-context';
import { DayStrip, DayStripLegend } from '@/components/day-strip';

/**
 * The dashboard's 30-day history: one DayStrip per monitor (C-7), so a day
 * reads the same here as on the public page and in the SLA report.
 */
export function UptimeHeatmap({ rows }: { rows: UptimeHistoryRow[] }) {
  const { t } = useLanguage();
  const dayCount = rows[0]?.days.length ?? 0;
  const caption = t('heatmap.caption', { days: dayCount }, `Denní dostupnost monitorů za posledních ${dayCount} dní`);
  // Opens at the newest day. On a phone the box starts scrolled to the left,
  // so the recent red and amber days - the reason each row is listed - sat
  // off-screen and every strip looked green (V-08).
  const box = React.useRef<HTMLDivElement>(null);
  React.useLayoutEffect(() => {
    const el = box.current;
    if (el && el.scrollWidth > el.clientWidth) el.scrollLeft = el.scrollWidth;
  }, [rows]);

  return (
    <div className="flex flex-col gap-4">
      {/* The strip is thirty cells wide and does not fit a phone; scrolling it
          inside its own box keeps the page from moving sideways, and the box
          takes focus so a keyboard can move it too. */}
      <div
        ref={box}
        className="focus-visible:ring-ring overflow-x-auto overflow-y-visible py-2 focus-visible:ring-2 focus-visible:outline-none"
        tabIndex={0}
        role="region"
        aria-label={caption}
      >
        <table className="w-full border-separate border-spacing-y-2 text-sm">
          <caption className="sr-only">{caption}</caption>
          <tbody>
            {rows.map((row, rowIdx) => (
              <tr key={row.monitorId}>
                {/* The name stays pinned while the strip scrolls under it: the box
                    opens at the newest day, which pushed the names off a phone
                    and left anonymous strips (V-05). */}
                <th
                  scope="row"
                  className="bg-card sticky left-0 z-10 w-28 max-w-28 truncate pr-3 text-left text-xs font-normal whitespace-nowrap sm:w-48 sm:max-w-48"
                >
                  <Link
                    to={`/infrastructure/${row.monitorId}`}
                    title={row.name}
                    className="text-foreground font-semibold hover:underline"
                  >
                    {row.name}
                  </Link>
                </th>
                <td>
                  <DayStrip
                    days={row.days}
                    label={`${row.name} · ${caption}`}
                    href={`/infrastructure/${row.monitorId}`}
                    // The top rows open downwards, or the card edge cuts the detail off.
                    popoverSide={rowIdx <= 1 ? 'bottom' : 'top'}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <DayStripLegend className="sm:pl-48" />
    </div>
  );
}
