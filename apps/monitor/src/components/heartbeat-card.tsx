import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { HeartPulse, Copy, Check, RefreshCw, AlertTriangle } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

interface HeartbeatInfo {
  monitorId: number;
  name: string;
  token: string;
  url: string | null;
  intervalSecs: number | null;
  graceSecs: number | null;
  lastSignalAt: string | null;
  lastResult: string | null;
  lastMessage: string | null;
  state: string;
  stateReason: string | null;
  ageSecs: number | null;
}

/**
 * Nastavení heartbeat monitoru.
 *
 * Ukazuje adresu, na kterou se má úloha ozvat, a hotový příkaz k vložení do
 * cronu. Bez toho by si administrátor musel URL skládat ručně z tokenu, což
 * je přesně ten druh kroku, u kterého se udělá překlep a monitor pak tiše
 * hlásí výpadek, který se nestal.
 */
export function HeartbeatCard({ monitorId }: { monitorId: number }) {
  const { t } = useLanguage();
  const [info, setInfo] = React.useState<HeartbeatInfo | null | undefined>(undefined);
  const [copied, setCopied] = React.useState<string | null>(null);
  const [regenerating, setRegenerating] = React.useState(false);

  const load = React.useCallback(
    (regenerate = false) => {
      const url = `/status/api.php?action=heartbeat_info&monitor_id=${monitorId}${regenerate ? '&regenerate=1' : ''}`;
      return fetch(url, { credentials: 'include' })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
        .then((d) => setInfo(d))
        .catch(() => setInfo(null));
    },
    [monitorId]
  );

  React.useEffect(() => {
    load();
  }, [load]);

  const copy = (value: string, key: string) => {
    navigator.clipboard.writeText(value).then(
      () => {
        setCopied(key);
        setTimeout(() => setCopied(null), 2000);
      },
      () => setCopied(null)
    );
  };

  if (info === undefined) {
    return (
      <Card className="p-6">
        <p className="text-muted-foreground text-sm">{t('hb.loading', 'Načítám nastavení heartbeatu…')}</p>
      </Card>
    );
  }

  if (!info) {
    return (
      <Card className="p-6">
        <p className="text-foreground text-sm font-semibold">
          {t('hb.unavailable_title', 'Nastavení heartbeatu se nepodařilo načíst')}
        </p>
        <p className="text-muted-foreground mt-1 text-xs">
          {t('hb.unavailable_desc', 'Adresu vidí jen přihlášený administrátor.')}
        </p>
      </Card>
    );
  }

  const minutes = (secs: number | null) => (secs != null ? Math.round(secs / 60) : null);
  const intervalMins = minutes(info.intervalSecs);
  const graceMins = minutes(info.graceSecs);

  const curl = info.url ? `curl -fsS -m 10 "${info.url}"` : null;

  const stateVariant = info.state === 'up' ? 'up' : info.state === 'down' ? 'down' : 'neutral';
  const stateLabel =
    info.state === 'up'
      ? t('hb.state_up', 'Hlásí se včas')
      : info.state === 'down'
        ? t('hb.state_down', 'Neozvala se')
        : t('hb.state_unknown', 'Zatím bez signálu');

  return (
    <Card className="space-y-4 p-6">
      <div className="flex flex-wrap items-center gap-3 border-b border-border pb-3">
        <HeartPulse className="size-5 text-primary" />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{t('hb.title', 'Heartbeat')}</h3>
          <p className="text-muted-foreground text-xs">
            {intervalMins != null
              ? t(
                  'hb.subtitle',
                  { interval: intervalMins, grace: graceMins ?? 0 },
                  `Očekáváno každých ${intervalMins} min (tolerance ${graceMins ?? 0} min)`
                )
              : t('hb.subtitle_no_interval', 'Interval není nastavený')}
          </p>
        </div>
        <Badge variant={stateVariant} className="ml-auto">
          {stateLabel}
        </Badge>
      </div>

      {/* Stav vlastními slovy - "unknown" bez vysvětlení vypadá jako chyba,
          přitom znamená jen, že úloha ještě neběžela. */}
      {info.stateReason && <p className="text-muted-foreground text-xs">{info.stateReason}</p>}

      {info.lastResult === 'fail' && (
        <div className="border-down/40 bg-down/10 flex gap-2 rounded-lg border p-3">
          <AlertTriangle className="text-down mt-0.5 size-4 shrink-0" />
          <div className="text-xs">
            <p className="text-foreground font-semibold">{t('hb.reported_failure', 'Úloha ohlásila selhání')}</p>
            {info.lastMessage && <p className="text-muted-foreground mt-0.5">{info.lastMessage}</p>}
          </div>
        </div>
      )}

      <div>
        <p className="text-muted-foreground mb-1 text-xs font-medium">
          {t('hb.url_label', 'Adresa, na kterou se úloha hlásí')}
        </p>
        {info.url ? (
          <div className="flex items-center gap-2">
            <code className="bg-muted min-w-0 flex-1 overflow-x-auto rounded-md px-2 py-1.5 font-mono text-[11px] whitespace-nowrap">
              {info.url}
            </code>
            <Button variant="outline" size="sm" onClick={() => copy(info.url as string, 'url')} className="shrink-0">
              {copied === 'url' ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
            </Button>
          </div>
        ) : (
          <p className="text-muted-foreground text-sm">—</p>
        )}
      </div>

      {curl && (
        <div>
          <p className="text-muted-foreground mb-1 text-xs font-medium">
            {t('hb.cron_label', 'Na konec úlohy (cron, skript zálohy)')}
          </p>
          <div className="flex items-center gap-2">
            <code className="bg-muted min-w-0 flex-1 overflow-x-auto rounded-md px-2 py-1.5 font-mono text-[11px] whitespace-nowrap">
              {curl}
            </code>
            <Button variant="outline" size="sm" onClick={() => copy(curl, 'curl')} className="shrink-0">
              {copied === 'curl' ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
            </Button>
          </div>
          <p className="text-muted-foreground mt-1.5 text-[11px]">
            {t(
              'hb.fail_hint',
              'Když úloha selže, přidejte &status=fail&msg=popis - monitor pak spadne hned, ne až po vypršení intervalu.'
            )}
          </p>
        </div>
      )}

      <div className="flex items-center justify-between border-t border-border pt-3">
        <p className="text-muted-foreground text-[11px]">
          {info.lastSignalAt
            ? t('hb.last_signal', { at: info.lastSignalAt }, `Poslední signál: ${info.lastSignalAt}`)
            : t('hb.never', 'Zatím nepřišel žádný signál.')}
        </p>
        <Button
          variant="ghost"
          size="sm"
          disabled={regenerating}
          onClick={() => {
            if (
              !window.confirm(
                t(
                  'hb.regenerate_confirm',
                  'Vygenerovat nový token? Stará adresa přestane fungovat a je nutné ji přepsat všude, kde je zadaná.'
                )
              )
            ) {
              return;
            }
            setRegenerating(true);
            load(true).finally(() => setRegenerating(false));
          }}
          className="gap-1.5"
        >
          <RefreshCw className="size-3.5" /> {t('hb.regenerate', 'Nový token')}
        </Button>
      </div>
    </Card>
  );
}
