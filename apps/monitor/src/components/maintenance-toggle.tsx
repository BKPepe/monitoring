import * as React from 'react';
import { Wrench } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/context/language-context';
import { useSession } from '@/api/use-session';
import { appApi } from '@/api/app-api';

/**
 * The maintenance switch as state and one action, so the phone's overflow
 * menu (W2-2) and the desktop button run the same code: the prompt, the
 * request, the error.
 */
export function useMaintenanceToggle({
  monitorId,
  active,
  onChanged,
}: {
  monitorId: number;
  active: boolean;
  onChanged?: () => void;
}) {
  const { t } = useLanguage();
  const { isAdmin } = useSession();
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);

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

  return {
    /** Only an administrator may switch it; everyone else gets no control at all. */
    allowed: isAdmin,
    busy,
    error,
    toggle,
    label: active ? t('maint.end', 'Ukončit údržbu') : t('maint.start', 'Zapnout údržbu'),
  };
}

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
  const maintenance = useMaintenanceToggle({ monitorId, active, onChanged });

  if (!maintenance.allowed) return null;

  return (
    <span className="inline-flex flex-col items-end gap-1">
      <Button
        size="sm"
        variant={active ? 'primary' : 'outline'}
        disabled={maintenance.busy}
        onClick={() => void maintenance.toggle()}
        className="gap-1.5"
      >
        <Wrench className="size-3.5" />
        {maintenance.label}
      </Button>
      {maintenance.error && <span className="text-down text-2xs">{maintenance.error}</span>}
    </span>
  );
}
