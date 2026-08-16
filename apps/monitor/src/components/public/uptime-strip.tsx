import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

export interface UptimeDay {
  date: string;
  status: 'up' | 'down' | 'nodata' | string;
  uptimePct: number | null;
  detail: string;
}

/**
 * The 30-day availability strip - one cell per day, tooltip with the detail.
 *
 * Same information as the legacy history-bar, but the legacy page emitted
 * thirty <div>s with inline styles and a data-tooltip attribute per monitor;
 * here the styling is three classes. "No data" days render in a neutral
 * colour, never as green - a day nobody measured is not a day without
 * outages.
 */
export function UptimeStrip({ days }: { days: UptimeDay[] }) {
  const { t } = useLanguage();

  if (days.length === 0) {
    return (
      <p className="text-muted-foreground text-[11px]">{t('public.no_history', 'Historie zatím není k dispozici')}</p>
    );
  }

  return (
    <div
      className="flex items-end gap-[3px]"
      role="img"
      aria-label={t('public.uptime_strip_aria', 'Dostupnost po dnech, posledních 30 dní')}
    >
      {days.map((d, i) => (
        <span
          key={`${d.date}-${i}`}
          title={`${d.date} ${d.detail}`}
          className={cn(
            'h-6 w-[7px] rounded-[2px]',
            d.status === 'up' ? 'bg-up/80' : d.status === 'down' ? 'bg-down' : 'bg-muted'
          )}
        />
      ))}
    </div>
  );
}
