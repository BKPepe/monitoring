import { StatusDot } from '@/components/ui/badge';
import { usePublicStatus } from '@/api/use-asset-charts';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

/**
 * The status card at the foot of the sidebar: does the monitoring server
 * answer right now. It reads the same `public_status` call the dashboard
 * shares (fetchPublicStatusShared), refreshed every minute, and says only
 * what that call proves - an answer, or none. It used to be a hardcoded green
 * "Operational" in the page footer that kept glowing while the API was down.
 */
export function ServerStatusCard({ version, collapsed = false }: { version: string; collapsed?: boolean }) {
  const { t } = useLanguage();
  const { data, error, loading } = usePublicStatus(60_000);
  const state: 'checking' | 'ok' | 'down' = loading ? 'checking' : error || !data ? 'down' : 'ok';
  const word =
    state === 'checking'
      ? t('footer.api_checking', 'Zjišťuji…')
      : state === 'ok'
        ? t('shell.api_ok', 'Odpovídá')
        : t('footer.api_unreachable', 'Nedostupné');
  const title = t('shell.server_status', 'Server monitoringu');
  const variant = state === 'ok' ? 'up' : state === 'down' ? 'down' : 'neutral';

  if (collapsed) {
    return (
      <div className="grid place-items-center py-2">
        <StatusDot variant={variant} label={`${title}: ${word}`} />
      </div>
    );
  }
  return (
    <div
      data-slot="server-status"
      data-state={state}
      className="bg-inset flex items-center gap-2.5 rounded-lg border border-border px-3 py-2"
    >
      <StatusDot variant={variant} className={cn(state === 'ok' && 'shadow-[0_0_6px_currentColor] text-up')} />
      <div className="min-w-0 leading-tight">
        <p className="truncate text-xs font-medium">{title}</p>
        <p className="text-muted-foreground figure truncate text-2xs">
          <span className={cn(state === 'down' && 'text-down font-semibold')}>{word}</span> · v{version}
        </p>
      </div>
    </div>
  );
}
