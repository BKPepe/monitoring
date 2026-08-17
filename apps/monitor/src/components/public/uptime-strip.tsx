import * as React from 'react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

export interface UptimeDay {
  date: string;
  status: 'up' | 'down' | 'nodata' | string;
  uptimePct: number | null;
  /** Průměrná odezva dne v ms; null = ten den nic neodpovědělo. */
  avgMs?: number | null;
  detail: string;
}

/**
 * The 30-day availability strip - one cell per day.
 *
 * The first version hung the detail on a title= attribute. That tooltip does
 * not exist on touch devices at all and appears after a second's delay on
 * desktop - reported as "tooltips don't work". Instead of 180 tooltip
 * portals (30 days x 6 services), one shared caption under the strip shows
 * the hovered or tapped day; on touch, tapping a cell is the same gesture.
 *
 * A no-data day renders neutral, never green: a day nobody measured is not a
 * day without outages.
 */
export function UptimeStrip({ days }: { days: UptimeDay[] }) {
  const { t } = useLanguage();
  const [picked, setPicked] = React.useState<UptimeDay | null>(null);

  if (days.length === 0) {
    return (
      <p className="text-muted-foreground text-[11px]">{t('public.no_history', 'Historie zatím není k dispozici')}</p>
    );
  }

  return (
    <div className="min-w-0">
      <div
        className="flex items-end gap-[3px]"
        role="img"
        aria-label={t('public.uptime_strip_aria', 'Dostupnost po dnech, posledních 30 dní')}
        onMouseLeave={() => setPicked(null)}
      >
        {days.map((d, i) => (
          // Span, ne button: pas je soucasti rozbalovaciho tlacitka karty a
          // <button> v <button> je neplatne HTML - React na to za behu
          // upozornil (vite log), prohlizec smi takovy strom preskladat.
          // Klepnuti obslouzi onClick na spanu; rozbaleni karty tim neutrpi.
          <span
            key={`${d.date}-${i}`}
            aria-label={`${d.date} ${d.detail}`}
            onMouseEnter={() => setPicked(d)}
            onClick={(e) => {
              e.stopPropagation();
              setPicked(d);
            }}
            className={cn(
              'h-6 w-[7px] rounded-[2px] transition-transform hover:scale-y-110',
              d.status === 'up' ? 'bg-up/80' : d.status === 'down' ? 'bg-down' : 'bg-muted'
            )}
          />
        ))}
      </div>
      {/* The caption keeps its height even when empty so the row does not
          jump the first time a day is hovered. */}
      <p className="text-muted-foreground mt-0.5 h-4 truncate text-right text-[10px] tabular-nums">
        {picked ? `${picked.date} ${picked.detail}` : '\u00A0'}
      </p>
    </div>
  );
}
