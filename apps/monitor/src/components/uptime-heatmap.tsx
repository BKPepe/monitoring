import { Link } from 'react-router';
import { cn } from '@/lib/utils';
import type { DayStatus, UptimeHistoryRow } from '@/data/model';
import { useLanguage } from '@/context/language-context';

const cellClass: Record<DayStatus, string> = {
  up: 'bg-up hover:ring-2 hover:ring-emerald-400/80',
  warning: 'bg-warning hover:ring-2 hover:ring-amber-400/80',
  down: 'bg-down hover:ring-2 hover:ring-rose-500/80',
  paused: 'bg-muted hover:ring-2 hover:ring-muted-foreground/80',
  maintenance: 'bg-info/70 hover:ring-2 hover:ring-sky-400/80',
};

export function UptimeHeatmap({ rows }: { rows: UptimeHistoryRow[] }) {
  const { t } = useLanguage();
  const dayCount = rows[0]?.days.length ?? 0;

  const statusLabel: Record<DayStatus, string> = {
    up: t('heatmap.status_up', 'Dostupné (100 %)'),
    warning: t('heatmap.status_warning', 'Zhoršená latence'),
    down: t('heatmap.status_down', 'Výpadek služby (Offline)'),
    paused: t('heatmap.status_paused', 'Pozastaveno'),
    maintenance: t('heatmap.status_maintenance', 'Plánovaná údržba'),
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="overflow-visible py-2">
        <table className="w-full border-separate border-spacing-y-2 text-sm overflow-visible">
          <caption className="sr-only">
            {t('heatmap.caption', { days: dayCount }, `Denní dostupnost monitorů za posledních ${dayCount} dní`)}
          </caption>
          <tbody>
            {rows.map((row, rowIdx) => {
              const isTopRow = rowIdx <= 1;

              return (
                <tr key={row.monitorId} className="overflow-visible">
                  <th
                    scope="row"
                    className="text-muted-foreground w-48 pr-3 text-left text-xs font-normal whitespace-nowrap"
                  >
                    <Link
                      to={`/infrastructure/${row.monitorId}`}
                      className="hover:underline text-foreground font-semibold text-xs"
                    >
                      {row.name}
                    </Link>
                  </th>
                  <td className="overflow-visible">
                    <div className="flex gap-[4px] overflow-visible">
                      {row.days.map((day, idx) => {
                        const isNearRight = idx > row.days.length - 5;
                        const isNearLeft = idx < 4;

                        return (
                          <div key={day.date} className="group relative flex-1">
                            <Link
                              to={`/infrastructure/${row.monitorId}`}
                              className={cn(
                                'block h-8 min-w-[14px] rounded-[4px] transition-all hover:scale-125 hover:z-30 cursor-pointer shadow-sm',
                                cellClass[day.status]
                              )}
                            />

                            {/* Large, clear tooltip with smart up/down direction */}
                            <div
                              className={cn(
                                'absolute hidden group-hover:flex flex-col gap-1.5 w-64 p-3.5 rounded-xl bg-slate-950/98 border border-slate-700 text-slate-100 text-xs shadow-2xl z-50 pointer-events-none backdrop-blur-xl ring-1 ring-white/10',
                                isTopRow ? 'top-full mt-2.5' : 'bottom-full mb-2.5',
                                isNearRight ? 'right-0' : isNearLeft ? 'left-0' : 'left-1/2 -translate-x-1/2'
                              )}
                            >
                              <div className="flex items-center justify-between border-b border-slate-800 pb-1.5">
                                <span className="font-bold text-xs text-slate-200">{day.date}</span>
                                {day.uptimePct != null ? (
                                  <span
                                    className={cn(
                                      'font-extrabold text-xs px-2 py-0.5 rounded-md',
                                      day.uptimePct >= 99.5
                                        ? 'bg-emerald-500/20 text-emerald-300'
                                        : day.uptimePct >= 95
                                          ? 'bg-amber-500/20 text-amber-300'
                                          : 'bg-rose-500/20 text-rose-300'
                                    )}
                                  >
                                    {day.uptimePct.toFixed(1)} % Uptime
                                  </span>
                                ) : (
                                  <span className="font-extrabold text-xs px-2 py-0.5 rounded-md bg-slate-700/50 text-slate-300">
                                    {t('heatmap.no_data_badge', 'Bez dat')}
                                  </span>
                                )}
                              </div>

                              <div className="flex items-center justify-between text-xs pt-0.5">
                                <span className="text-slate-400">{t('heatmap.monitor_status', 'Stav monitoru:')}</span>
                                <span
                                  className={cn(
                                    'font-bold',
                                    day.status === 'down'
                                      ? 'text-rose-400'
                                      : day.status === 'warning'
                                        ? 'text-amber-400'
                                        : 'text-emerald-400'
                                  )}
                                >
                                  {statusLabel[day.status]}
                                </span>
                              </div>

                              <div className="text-xs text-slate-300 pt-1 border-t border-slate-800/80 leading-relaxed font-sans">
                                {day.detail ??
                                  (day.status === 'down'
                                    ? t('heatmap.detail_down', '🔴 Detekován výpadek.')
                                    : day.status === 'warning'
                                      ? t('heatmap.detail_warning', '⚡ Zhoršená odezva zaznamenána.')
                                      : day.status === 'maintenance'
                                        ? t('heatmap.detail_maintenance', '🔧 Plánovaná údržba.')
                                        : day.status === 'paused'
                                          ? t('heatmap.detail_paused', '⏸️ Bez naměřených dat pro tento den.')
                                          : t('heatmap.detail_up', '🟢 Všechny testy dostupnosti proběhly bez chyb.'))}
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center gap-6 pl-48 text-xs pt-1">
        {(Object.keys(statusLabel) as DayStatus[]).map((status) => (
          <span key={status} className="text-muted-foreground flex items-center gap-2 font-medium text-xs">
            <span className={cn('size-3.5 rounded-[4px]', cellClass[status])} />
            {statusLabel[status]}
          </span>
        ))}
      </div>
    </div>
  );
}
