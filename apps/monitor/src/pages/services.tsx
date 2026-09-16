import * as React from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { StatusDot, statusVariant } from '@/components/ui/badge';
import { Radar, Server, ArrowRight } from 'lucide-react';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { processUsage } from '@/lib/monitor-grouping';
import { formatPercent, formatRelative } from '@/lib/utils';
import { LoadingState, ErrorState } from '@/components/ui/states';

/**
 * Services: all agent-side checks (agent_service) across machines, grouped
 * by the agent they run on. A companion to the dashboard, where services
 * nest under agents - here the view is "what runs where" in one place,
 * including process consumption from the agents' rankings.
 */
export function ServicesPage() {
  const { t } = useLanguage();
  // One word per state, so the colour is never the only carrier.
  const statusLabel: Record<string, string> = {
    up: t('common.online', 'Online'),
    down: t('common.offline', 'Offline'),
    warning: t('common.warning', 'Varování'),
    paused: t('common.paused', 'Pozastaveno'),
    maintenance: t('common.maintenance', 'Údržba'),
    unknown: t('status.unknown', 'Neznámý'),
  };
  const { session } = useSession();
  const [monitors, setMonitors] = React.useState<ApiMonitor[]>([]);
  const [loading, setLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    let active = true;
    appApi
      .getMonitors()
      .then((rows) => {
        if (!active) return;
        const list = Array.isArray(rows) ? rows : ((rows as any)?.monitors ?? []);
        setMonitors(list);
        setError(null);
      })
      .catch(() => {
        if (active) setError(t('services.load_error', 'Seznam služeb se nepodařilo načíst.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [session, t]);

  const services = monitors.filter((m) => (m.type || '').toLowerCase() === 'agent_service');
  const agents = monitors.filter((m) => ['openwrt', 'vps'].includes((m.type || '').toLowerCase()));

  // Groups by agent (shared assetId); services without a known agent fall
  // into their own group, so they do not get lost.
  const groups = agents
    .map((agent) => ({
      agent,
      services: services.filter((s) => s.assetId != null && s.assetId === agent.assetId),
    }))
    .filter((g) => g.services.length > 0);
  const orphaned = services.filter((s) => !agents.some((a) => a.assetId != null && a.assetId === s.assetId));

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        icon={<Radar className="size-5 text-primary" />}
        title={t('services.title', 'Služby')}
        subtitle={t('services.subtitle', 'Procesy a služby hlídané agenty napříč všemi stroji.')}
      />

      {error && <ErrorState message={error} />}

      {loading ? (
        <LoadingState label={t('services.loading', 'Načítám služby…')} />
      ) : services.length === 0 ? (
        <Card className="p-8 text-center text-sm text-muted-foreground space-y-2">
          <p className="text-foreground font-semibold">{t('services.empty_title', 'Žádné služby hlídané agentem')}</p>
          <p className="text-xs">
            {t(
              'services.empty_hint',
              'Služby vznikají importem z objevených služeb (Infrastruktura → Objevené služby) nebo převodem monitoru na kontrolu agentem.'
            )}
          </p>
        </Card>
      ) : (
        <div className="flex flex-col gap-4">
          {groups.map(({ agent, services: svcs }) => (
            <Card key={agent.id} className="p-4">
              <Link
                to={`/infrastructure/${agent.id}`}
                className="flex items-center gap-2 border-b border-border pb-2.5 hover:underline"
              >
                <Server className="size-4 text-primary" />
                <span className="text-sm font-bold">{agent.name}</span>
                <span className="text-muted-foreground text-xs">({svcs.length})</span>
                <ArrowRight className="text-muted-foreground ml-auto size-3.5" />
              </Link>
              <div className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {svcs.map((svc) => {
                  const usage = processUsage(svc, monitors);
                  return (
                    <Link
                      key={svc.id}
                      to={`/infrastructure/${svc.id}`}
                      className="rounded-lg border border-border p-3 transition-colors hover:border-primary/40"
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="min-w-0 truncate text-sm font-semibold">{svc.name}</span>
                        {/* The dot was the only carrier of the state on this
                            page: no text, no label, and on a narrow screen the
                            warning amber reads as grey. It says what it means
                            now, and the word is printed beside it. */}
                        <span className="flex shrink-0 items-center gap-1.5">
                          <StatusDot variant={statusVariant[svc.status]} label={statusLabel[svc.status]} />
                          <span className="text-muted-foreground text-2xs">{statusLabel[svc.status]}</span>
                        </span>
                      </div>
                      <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-2xs">
                        <span className="font-mono">{svc.target}</span>
                        {usage.cpu != null && <span>CPU {formatPercent(usage.cpu)}</span>}
                        {usage.ram != null && <span>RAM {usage.ram} MB</span>}
                        {svc.lastCheck && <span>{formatRelative(svc.lastCheck)}</span>}
                      </div>
                    </Link>
                  );
                })}
              </div>
            </Card>
          ))}

          {orphaned.length > 0 && (
            <Card className="p-4">
              <p className="border-b border-border pb-2.5 text-sm font-bold">
                {t('services.orphaned', 'Bez přiřazeného agenta')}
              </p>
              <div className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {orphaned.map((svc) => (
                  <Link
                    key={svc.id}
                    to={`/infrastructure/${svc.id}`}
                    className="rounded-lg border border-border p-3 transition-colors hover:border-primary/40"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="min-w-0 truncate text-sm font-semibold">{svc.name}</span>
                      <StatusDot variant={statusVariant[svc.status]} />
                    </div>
                    <p className="text-muted-foreground mt-1 font-mono text-2xs">{svc.target}</p>
                  </Link>
                ))}
              </div>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
