import { AlertTriangle } from 'lucide-react';
import { useSource, usePublicStatus } from '@/api/use-asset-charts';
import { useLanguage } from '@/context/language-context';

export function DataSourceBanner() {
  const { t } = useLanguage();
  const state = useSource();
  const { error } = usePublicStatus();
  if (!state) return null;

  if (!state.isMock) {
    // The verdict strip that used to follow ("n / m monitorů hlásí výpadek"
    // beside an always-pulsing "Živá data z /status API") repeated the KPI
    // row and claimed liveness from a fixed string. Liveness is now the
    // dashboard's FreshnessPill, computed from timestamps; this banner speaks
    // only when the source itself is in doubt.
    if (!error) return null;
    return (
      <div
        role="status"
        className="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/12 text-warning px-3 py-2 text-xs"
      >
        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
        <div>
          <p className="font-medium">{t('banner.load_failed', 'Stav infrastruktury se nepodařilo načíst')}</p>
          <p className="opacity-80">{error.message}</p>
        </div>
      </div>
    );
  }

  // The app has no demo data - when the API does not answer, there simply is
  // no data. The earlier "Demo data" text therefore lied and above all did not
  // say what the admin should do (reported by the user).
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
          className="mt-0.5 inline-flex items-center gap-1.5 rounded-md bg-down px-2.5 py-1 text-2xs font-semibold text-down-foreground hover:opacity-90 transition-opacity"
        >
          {t('banner.api_down_retry', 'Zkusit znovu')}
        </button>
      </div>
    </div>
  );
}
