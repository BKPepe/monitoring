import * as React from 'react';
import { Link } from 'react-router';
import { Card } from '@/components/ui/card';
import { StatusDot } from '@/components/ui/badge';
import { Radar, Server, ArrowRight } from 'lucide-react';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { useSession } from '@/api/use-session';
import { useLanguage } from '@/context/language-context';
import { processUsage } from '@/lib/monitor-grouping';
import { formatPercent, formatRelative } from '@/lib/utils';

/**
 * Služby: všechny agent-side kontroly (agent_service) napříč stroji,
 * seskupené podle agenta, na kterém běží. Doplněk k dashboardu, kde jsou
 * služby zanořené pod agenty - tady je pohled "co všechno mi kde běží"
 * na jednom místě, včetně spotřeby procesů z žebříčků agentů.
 */
export function ServicesPage() {
  const { t } = useLanguage();
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

  // Skupiny podle agenta (sdílený assetId); služby bez známého agenta
  // spadnou do vlastní skupiny, ať se neztratí.
  const groups = agents
    .map((agent) => ({
      agent,
      services: services.filter((s) => s.assetId != null && s.assetId === agent.assetId),
    }))
    .filter((g) => g.services.length > 0);
  const orphaned = services.filter((s) => !agents.some((a) => a.assetId != null && a.assetId === s.assetId));

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          <Radar className="size-5 text-primary" /> {t('services.title', 'Služby')}
        </h1>
        <p className="text-muted-foreground text-sm">
          {t('services.subtitle', 'Procesy a služby hlídané agenty napříč všemi stroji.')}
        </p>
      </div>

      {error && (
        <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-xs font-semibold text-destructive">
          {error}
        </div>
      )}

      {loading ? (
        <p className="text-muted-foreground text-sm">{t('services.loading', 'Načítám služby…')}</p>
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
                        <StatusDot variant={svc.status === 'maintenance' ? 'paused' : svc.status} />
                      </div>
                      <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]">
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
                      <StatusDot variant={svc.status === 'maintenance' ? 'paused' : svc.status} />
                    </div>
                    <p className="text-muted-foreground mt-1 font-mono text-[11px]">{svc.target}</p>
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
