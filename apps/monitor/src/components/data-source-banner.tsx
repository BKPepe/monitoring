import { AlertTriangle } from 'lucide-react';
import { useSource, usePublicStatus } from '@/api/use-asset-charts';
import { useLanguage } from '@/context/language-context';

export function DataSourceBanner() {
  const { t } = useLanguage();
  const state = useSource();
  const { data: status, error, loading } = usePublicStatus();
  if (!state) return null;

  if (!state.isMock) {
    if (loading) {
      return (
        <div className="flex items-center gap-2 px-4 py-2.5 rounded-lg bg-slate-900 border border-border text-slate-300 text-xs font-semibold">
          <span className="size-2 rounded-full bg-slate-500 animate-pulse" />
          {t('banner.loading_status', 'Načítám stav infrastruktury…')}
        </div>
      );
    }

    if (error || !status) {
      return (
        <div
          role="status"
          className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/12 text-warning px-3 py-2 text-xs"
        >
          <AlertTriangle className="mt-0.5 size-4 shrink-0" />
          <div>
            <p className="font-medium">{t('banner.load_failed', 'Stav infrastruktury se nepodařilo načíst')}</p>
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
              ? t('banner.all_healthy', 'Všechny monitorované uzly a systémoví agenti fungují bez závad')
              : `${status.downMonitors} / ${status.totalMonitors} ${t('banner.monitors_reporting_outage', 'monitorů hlásí výpadek')}`}
          </span>
        </div>
        <span className={`font-mono text-[11px] font-semibold ${isHealthy ? 'text-emerald-400' : 'text-rose-300'}`}>
          {t('banner.live_data_status_api', 'Živá data z /status API')}
        </span>
      </div>
    );
  }

  // Aplikace nemá žádná ukázková data - když API neodpoví, prostě data
  // nejsou. Dřívější text "Ukázková data" tedy lhal a hlavně neříkal,
  // co má admin dělat (hlášeno uživatelem).
  return (
    <div
      role="alert"
      className="border-down/40 bg-down/10 text-down flex items-start gap-2 rounded-lg border px-3 py-2.5 text-xs"
    >
      <AlertTriangle className="mt-0.5 size-4 shrink-0" />
      <div className="space-y-1">
        <p className="font-bold">
          {t('banner.api_down_title', 'Monitorovací API neodpovídá — zobrazená data mohou chybět nebo být zastaralá')}
        </p>
        {state.fallbackReason && <p className="font-mono opacity-80">{state.fallbackReason}</p>}
        <p className="text-foreground/80">
          {t(
            'banner.api_down_help',
            'Co dělat: obnovte stránku (server mohl být chvíli přetížený). Pokud potíž trvá, zkontrolujte dostupnost /status na hostingu, běh cronu a chybový log PHP.'
          )}
        </p>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="mt-0.5 inline-flex items-center gap-1.5 rounded-md bg-down px-2.5 py-1 text-[11px] font-semibold text-white hover:opacity-90 transition-opacity"
        >
          {t('banner.api_down_retry', 'Zkusit znovu')}
        </button>
      </div>
    </div>
  );
}
