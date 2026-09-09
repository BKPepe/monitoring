import * as React from 'react';
import { Wrench } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';
import { appApi } from '@/api/app-api';

/**
 * Maintenance on or off in one click.
 *
 * Putting a machine into maintenance meant opening the edit form, ticking a
 * box and saving the whole monitor - so during an actual window, when speed
 * matters, the operator was editing forms. The description is optional and
 * kept short on purpose: it is what the public banner shows.
 */
export function MaintenanceToggle({
  monitorId,
  active,
  onChanged,
}: {
  monitorId: number;
  active: boolean;
  /** Called after a successful change so the page can reload its state. */
  onChanged?: () => void;
}) {
  const { t } = useLanguage();
  const { isAdmin } = useSession();
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

  if (!isAdmin) return null;

  const toggle = async () => {
    setBusy(true);
    setError(null);
    try {
      const description = active
        ? ''
        : (window.prompt(t('maint.prompt', 'Popis údržby (nepovinné, uvidí ho i veřejná stránka):')) ?? '');
      await appApi.toggleMaintenance([monitorId], !active, description);
      onChanged?.();
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="inline-flex flex-col items-end gap-1">
      <Button size="sm" variant={active ? 'primary' : 'outline'} disabled={busy} onClick={toggle} className="gap-1.5">
        <Wrench className="size-3.5" />
        {active ? t('maint.end', 'Ukončit údržbu') : t('maint.start', 'Zapnout údržbu')}
      </Button>
      {error && <span className="text-down text-[11px]">{error}</span>}
    </span>
  );
}
