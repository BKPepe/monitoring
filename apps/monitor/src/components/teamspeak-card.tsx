import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Mic, Plug, IdCard, Users, Check, X } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

interface Ts3Stages {
  query?: { ok?: boolean; time_ms?: number | null; authenticated?: boolean; steps?: Record<string, boolean> };
  service?: {
    clients_online?: number | null;
    clients_max?: number | null;
    slot_usage_pct?: number | null;
    channel_count?: number | null;
    active_channel_count?: number | null;
    query_client_count?: number | null;
    server_group_count?: number | null;
    voice_activity?: Record<string, number> | null;
  };
  ports?: Record<string, { ok?: boolean; port?: number }>;
  license?: string | null;
  version?: string | null;
}

/**
 * Detail TeamSpeak serveru ze ServerQuery.
 *
 * Kontrola sbírá mnohem víc, než se dosud zobrazovalo: obsazenost slotů,
 * kanály, aktivitu v hlasu, dostupnost všech tří portů, licenci a verzi.
 * Do `details` se z toho ukládaly jen počty klientů - zbytek končil
 * v monitor_logs.check_stages, odkud ho nic nečetlo.
 *
 * Část údajů vyžaduje přihlášení k ServerQuery. Bez něj zůstávají prázdné
 * a karta řekne proč.
 */
export function TeamspeakCard({ monitorId }: { monitorId: number }) {
  const { t } = useLanguage();
  const [stages, setStages] = React.useState<Ts3Stages | null | undefined>(undefined);

  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=check_stages&monitor_id=${monitorId}`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => {
        if (active) setStages(d?.stages ?? null);
      })
      .catch(() => {
        if (active) setStages(null);
      });
    return () => {
      active = false;
    };
  }, [monitorId]);

  if (stages === undefined) {
    return (
      <Card className="p-6">
        <p className="text-muted-foreground text-sm">{t('ts3.loading', 'Načítám detail serveru…')}</p>
      </Card>
    );
  }

  if (!stages) {
    return (
      <Card className="p-6">
        <p className="text-foreground text-sm font-semibold">
          {t('ts3.none_title', 'Detail ze ServerQuery zatím není k dispozici')}
        </p>
        <p className="text-muted-foreground mt-1 text-xs">
          {t('ts3.none_desc', 'Uloží se při nejbližší kontrole tohoto serveru.')}
        </p>
      </Card>
    );
  }

  const svc = stages.service ?? {};
  const ports = stages.ports ?? {};
  const authenticated = stages.query?.authenticated === true;

  const portLabels: Record<string, string> = {
    voice: t('ts3.port_voice', 'Hlasový port'),
    query: t('ts3.port_query', 'ServerQuery'),
    filetransfer: t('ts3.port_filetransfer', 'Přenos souborů'),
  };

  const activity = svc.voice_activity ?? null;

  // Popisky se skládají explicitně, ne přes `t(\`ts3.activity_${key}\`)`:
  // dynamický klíč nejde staticky ověřit, takže chybějící překlad by prošel
  // testem i kódovou revizí a projevil se až anglickému uživateli.
  // Server posílá právě tyhle čtyři stavy (functions.php: voice_activity).
  const activityLabels: Record<string, string> = {
    talking: t('ts3.activity_talking', 'Mluví'),
    away: t('ts3.activity_away', 'Pryč'),
    muted: t('ts3.activity_muted', 'Ztlumeno'),
    recording: t('ts3.activity_recording', 'Nahrává'),
  };

  return (
    <Card className="space-y-4 p-6">
      <div className="flex flex-wrap items-center gap-3 border-b border-border pb-3">
        <Mic className="size-5 text-primary" />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{t('ts3.title', 'TeamSpeak server')}</h3>
          <p className="text-muted-foreground text-xs">
            {stages.version
              ? t('ts3.version', { version: stages.version }, `Verze ${stages.version}`)
              : t('ts3.subtitle', 'Stav ze ServerQuery')}
          </p>
        </div>
        {stages.license && (
          <Badge variant="neutral" className="ml-auto">
            <IdCard className="size-3" /> {stages.license}
          </Badge>
        )}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        {/* Sloty a kanály ------------------------------------------------ */}
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <Users className="size-3.5" /> {t('ts3.slots', 'Obsazenost')}
          </div>
          <p className="mt-1 text-2xl font-bold tracking-tight">
            {svc.clients_online ?? '—'}
            {svc.clients_max != null && (
              <span className="text-muted-foreground text-sm font-medium"> / {svc.clients_max}</span>
            )}
          </p>
          {svc.slot_usage_pct != null && (
            <div className="bg-muted mt-2 h-1.5 w-full overflow-hidden rounded-full">
              <div
                className={cn('h-full rounded-full', svc.slot_usage_pct >= 90 ? 'bg-down' : 'bg-primary')}
                style={{ width: `${Math.min(100, svc.slot_usage_pct)}%` }}
              />
            </div>
          )}
          <div className="text-muted-foreground mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]">
            {svc.channel_count != null && (
              <span>{t('ts3.channels', { count: svc.channel_count }, `${svc.channel_count} kanálů`)}</span>
            )}
            {svc.active_channel_count != null && (
              <span>
                {t('ts3.active_channels', { count: svc.active_channel_count }, `${svc.active_channel_count} aktivních`)}
              </span>
            )}
            {/* Query klienti nejsou lidé - proto se od počtu odečítají. */}
            {svc.query_client_count != null && svc.query_client_count > 0 && (
              <span>
                {t('ts3.query_clients', { count: svc.query_client_count }, `${svc.query_client_count} query klientů`)}
              </span>
            )}
          </div>
        </div>

        {/* Porty --------------------------------------------------------- */}
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <Plug className="size-3.5" /> {t('ts3.ports', 'Dostupnost portů')}
          </div>
          {Object.keys(ports).length === 0 ? (
            <p className="text-muted-foreground mt-1 text-sm">—</p>
          ) : (
            <ul className="mt-1.5 space-y-1">
              {Object.entries(ports).map(([key, info]) => (
                <li key={key} className="flex items-center gap-2 text-xs">
                  <span
                    className={cn(
                      'grid size-4 shrink-0 place-items-center rounded-full',
                      info?.ok ? 'bg-up/15 text-up' : 'bg-down/15 text-down'
                    )}
                  >
                    {info?.ok ? <Check className="size-2.5" /> : <X className="size-2.5" />}
                  </span>
                  <span>{portLabels[key] ?? key}</span>
                  {info?.port != null && <span className="text-muted-foreground ml-auto font-mono">{info.port}</span>}
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {/* Aktivita v hlasu ------------------------------------------------- */}
      {activity && Object.keys(activity).length > 0 && (
        <div className="rounded-lg border border-border p-3">
          <p className="text-muted-foreground mb-1.5 text-xs font-medium">
            {t('ts3.voice_activity', 'Aktivita v hlasu')}
          </p>
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
            {Object.entries(activity).map(([key, value]) => (
              <span key={key}>
                {activityLabels[key] ?? key}: <strong>{value}</strong>
              </span>
            ))}
          </div>
        </div>
      )}

      {!authenticated && (
        <p className="text-muted-foreground text-[11px] leading-relaxed">
          {t(
            'ts3.no_auth',
            'Bez přihlášení k ServerQuery vrací server jen základní údaje. Doplňte jméno a heslo v nastavení monitoru pro detail kanálů a aktivity.'
          )}
        </p>
      )}
    </Card>
  );
}
