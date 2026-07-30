import { AlertTriangle, Database } from 'lucide-react';
import { useSource } from '@/api/use-asset-charts';
import { STATUS_API } from '@/api/http-source';

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
      <div className="text-muted-foreground flex items-center gap-2 text-xs">
        <Database className="size-3.5 shrink-0" />
        <span>
          Živá data z <code className="font-mono">{STATUS_API}</code>
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
