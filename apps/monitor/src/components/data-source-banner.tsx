import { AlertTriangle } from 'lucide-react';
import { useSource, usePublicStatus } from '@/api/use-asset-charts';

/**
 * Proužek s původem dat.
 *
 * Existuje proto, aby nikdy nemohlo nastat, že se někdo dívá na vymyšlená
 * čísla a myslí si, že jsou naměřená. U monitoringu je to zásadní: dashboard
 * ukazující falešné „vše v pořádku" je horší než rozbitý dashboard.
 *
 * Proto se text odvozuje ze skutečného `action=public_status`, ne z toho,
 * jestli je API jen technicky dosažitelné - to o stavu monitorů nic neříká.
 */
export function DataSourceBanner() {
  const state = useSource();
  const { data: status, error, loading } = usePublicStatus();
  if (!state) return null;

  if (!state.isMock) {
    if (loading) {
      return (
        <div className="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-slate-900 border border-border text-slate-300 text-xs font-semibold">
          <span className="size-2 rounded-full bg-slate-500 animate-pulse" />
          Načítám stav infrastruktury…
        </div>
      );
    }

    if (error || !status) {
      return (
        <div role="status" className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/12 text-warning px-3 py-2 text-xs">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          <div>
            <p className="font-medium">Stav infrastruktury se nepodařilo načíst</p>
            {error && <p className="opacity-80">{error.message}</p>}
          </div>
        </div>
      );
    }

    const isHealthy = status.status === 'healthy' && status.downMonitors === 0;

    return (
      <div
        className={
          'flex items-center justify-between flex-wrap gap-2 px-4 py-2.5 rounded-lg border text-xs font-bold shadow-sm ' +
          (isHealthy ? 'bg-slate-900 border-emerald-500/60 text-white' : 'bg-rose-950/60 border-rose-500/60 text-white')
        }
      >
        <div className="flex items-center gap-2">
          <span className={`size-2 rounded-full animate-pulse ${isHealthy ? 'bg-emerald-400' : 'bg-rose-400'}`} />
          <span className="font-semibold">
            {isHealthy
              ? 'Všechny monitorované uzly a systémoví agenti fungují bez závad'
              : `${status.downMonitors} z ${status.totalMonitors} monitorů hlásí výpadek`}
          </span>
        </div>
        <span className={`font-mono text-[11px] font-semibold ${isHealthy ? 'text-emerald-400' : 'text-rose-300'}`}>
          Živá data z /status API
        </span>
      </div>
    );
  }

  return (
    <div
      role="status"
      className="border-warning/30 bg-warning/12 text-warning flex items-start gap-2 rounded-lg border px-3 py-2 text-xs"
    >
      <AlertTriangle className="mt-0.5 size-4 shrink-0" />
      <div>
        <p className="font-medium">Ukázková data — nejde o naměřené hodnoty</p>
        {state.fallbackReason && <p className="opacity-80">{state.fallbackReason}</p>}
      </div>
    </div>
  );
}
