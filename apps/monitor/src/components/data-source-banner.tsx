import { usePublicStatus } from '@/api/use-asset-charts';
import { ErrorState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';

/**
 * Speaks only when the dashboard's status summary did not load: the reason
 * the request gave, and a retry. Liveness is the dashboard's FreshnessPill,
 * computed from timestamps; the verdict strip that once stood here repeated
 * the KPI row and claimed liveness from a fixed string.
 *
 * It used to wait for a separate 12 s probe of the same public_status and
 * kept one failed probe for the page's life as "API not responding", while
 * the data on the page was live (R183).
 */
export function DataSourceBanner() {
  const { t } = useLanguage();
  const { error, reload } = usePublicStatus();
  if (!error) return null;
  return (
    <ErrorState
      tone="warning"
      onRetry={reload}
      message={
        <>
          <p>{t('banner.load_failed', 'Stav infrastruktury se nepodařilo načíst')}</p>
          <p className="font-normal opacity-80">{error.message}</p>
        </>
      }
    />
  );
}
