import { AlertTriangle } from 'lucide-react';
import { useSource } from '@/api/use-asset-charts';

/**
 * Proužek s původem dat.
 *
 * Existuje proto, aby nikdy nemohlo nastat, že se někdo dívá na vymyšlená
 * čísla a myslí si, že jsou naměřená. U monitoringu je to zásadní: dashboard
 * ukazující falešné „vše v pořádku" je horší než rozbitý dashboard.
 */
export function DataSourceBanner() {
  const state = useSource();
  if (!state) return null;

  if (!state.isMock) {
    return (
      <div className="flex items-center justify-between flex-wrap gap-2 px-4 py-2.5 rounded-lg bg-slate-900 border border-emerald-500/60 text-white text-xs font-bold shadow-sm">
        <div className="flex items-center gap-2">
          <span className="size-2 rounded-full bg-emerald-400 animate-pulse" />
          <span className="text-slate-100 font-semibold">Všechny monitorované uzly a systémoví agenti fungují bez závad</span>
        </div>
        <span className="font-mono text-[11px] text-emerald-400 font-semibold">Živá data z /status API</span>
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
