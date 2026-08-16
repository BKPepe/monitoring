import * as React from 'react';
import { Card } from '@/components/ui/card';
import { Activity, Check, X, ChevronDown } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

interface Stage {
  ok?: boolean | null;
  time_ms?: number | null;
  error?: string | null;
  [key: string]: unknown;
}

interface StagesResponse {
  stages: Record<string, Stage> | null;
  checkedAt?: string;
  responseMs?: number | null;
  status?: string;
}

/**
 * Rozpad poslední kontroly webu na fáze: DNS → TCP → TLS → HTTP.
 *
 * Odpovídá na otázku „kde se to zdrželo / kde to spadlo", kterou samotná
 * celková odezva nezodpoví. Data se sbírala od začátku do monitor_logs,
 * ale žádné API je nevydávalo, takže je aplikace neuměla ukázat.
 */
export function CheckPipeline({ monitorId }: { monitorId: number }) {
  const { t } = useLanguage();
  const [data, setData] = React.useState<StagesResponse | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [openStage, setOpenStage] = React.useState<string | null>(null);

  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=check_stages&monitor_id=${monitorId}`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((d) => {
        if (active) setData(d);
      })
      .catch(() => {
        if (active) setError(t('pipeline.load_error', 'Rozpad kontroly se nepodařilo načíst.'));
      });
    return () => {
      active = false;
    };
  }, [monitorId, t]);

  if (error) {
    return (
      <Card className="p-6">
        <p className="text-muted-foreground text-sm">{error}</p>
      </Card>
    );
  }

  if (data === null) {
    return (
      <Card className="p-6">
        <p className="text-muted-foreground text-sm">{t('pipeline.loading', 'Načítám rozpad kontroly…')}</p>
      </Card>
    );
  }

  if (!data.stages) {
    return (
      <Card className="p-6">
        <p className="text-foreground text-sm font-semibold">
          {t('pipeline.none_title', 'Rozpad kontroly zatím není k dispozici')}
        </p>
        <p className="text-muted-foreground mt-1 text-xs">
          {t('pipeline.none_desc', 'Uloží se při nejbližší HTTP kontrole tohoto cíle.')}
        </p>
      </Card>
    );
  }

  // Pořadí je pevné - fáze na sebe navazují a přeskládat je podle klíčů
  // v JSONu by rozbilo čitelnost („TLS před TCP").
  const order = ['dns', 'tcp', 'tls', 'http', 'body'];
  const labels: Record<string, string> = {
    dns: t('pipeline.dns', 'DNS překlad'),
    tcp: t('pipeline.tcp', 'TCP spojení'),
    tls: t('pipeline.tls', 'TLS handshake'),
    http: t('pipeline.http', 'HTTP odpověď'),
    body: t('pipeline.body', 'Obsah stránky'),
  };

  const present = order.filter((k) => data.stages && k in data.stages);
  const total = present.reduce((sum, k) => {
    const ms = data.stages?.[k]?.time_ms;
    return typeof ms === 'number' ? sum + ms : sum;
  }, 0);

  return (
    <Card className="space-y-4 p-6">
      <div className="flex flex-wrap items-center gap-3 border-b border-border pb-3">
        <Activity className="size-5 text-primary" />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{t('pipeline.title', 'Rozpad kontroly')}</h3>
          <p className="text-muted-foreground text-xs">
            {t('pipeline.subtitle', 'Kolik času zabrala která fáze poslední kontroly.')}
          </p>
        </div>
        {data.checkedAt && (
          <span className="text-muted-foreground ml-auto font-mono text-[11px]">{data.checkedAt}</span>
        )}
      </div>

      <div className="flex flex-col gap-2">
        {present.map((key) => {
          const stage = data.stages![key];
          const ms = typeof stage.time_ms === 'number' ? stage.time_ms : null;
          const ok = stage.ok !== false;
          // Podíl na celkovém čase; bez změřeného času se pruh nekreslí.
          const share = ms != null && total > 0 ? (ms / total) * 100 : null;
          const expanded = openStage === key;
          const extras = Object.entries(stage).filter(
            ([k, v]) => !['ok', 'time_ms', 'error'].includes(k) && v != null && typeof v !== 'object'
          );
          const headerEntries =
            stage.headers && typeof stage.headers === 'object'
              ? Object.entries(stage.headers as Record<string, unknown>).filter(([, v]) => v != null && v !== '')
              : [];

          return (
            <div key={key} className="rounded-lg border border-border p-3">
              <button
                type="button"
                onClick={() => setOpenStage(expanded ? null : key)}
                className="flex w-full items-center gap-2 text-left"
              >
                <span
                  className={cn(
                    'grid size-5 shrink-0 place-items-center rounded-full',
                    ok ? 'bg-up/15 text-up' : 'bg-down/15 text-down'
                  )}
                >
                  {ok ? <Check className="size-3" /> : <X className="size-3" />}
                </span>
                <span className="text-sm font-medium">{labels[key] ?? key}</span>
                <span className="ml-auto font-mono text-sm font-semibold">{ms == null ? '—' : `${ms} ms`}</span>
                {(extras.length > 0 || headerEntries.length > 0 || stage.error) && (
                  <ChevronDown className={cn('size-3.5 transition-transform', expanded && 'rotate-180')} />
                )}
              </button>

              {share != null && (
                <div className="bg-muted mt-2 h-1 w-full overflow-hidden rounded-full">
                  <div
                    className={cn('h-full rounded-full', ok ? 'bg-primary' : 'bg-down')}
                    style={{ width: `${share}%` }}
                  />
                </div>
              )}

              {stage.error && <p className="text-down mt-1.5 text-xs">{String(stage.error)}</p>}

              {expanded && (
                <>
                  {extras.length > 0 && (
                    <dl className="text-muted-foreground mt-2 grid gap-x-4 gap-y-0.5 text-[11px] sm:grid-cols-2">
                      {extras.map(([k, v]) => (
                        <div key={k} className="flex justify-between gap-2">
                          <dt>{k}</dt>
                          <dd className="font-mono">{String(v)}</dd>
                        </div>
                      ))}
                    </dl>
                  )}

                  {/* HTTP hlavičky jsou vnořený objekt, takže je výpis
                      skalárních hodnot výše nezachytí. Nezaslané hlavičky se
                      nevypisují vůbec - "—" u pěti řádků nic neříká. */}
                  {headerEntries.length > 0 && (
                    <div className="mt-2">
                      <p className="text-muted-foreground mb-1 text-[11px] font-medium">
                        {t('pipeline.headers', 'HTTP hlavičky')}
                      </p>
                      <dl className="text-muted-foreground grid gap-x-4 gap-y-0.5 text-[11px]">
                        {headerEntries.map(([k, v]) => (
                          <div key={k} className="flex justify-between gap-3">
                            <dt className="shrink-0">{k.replace(/_/g, '-')}</dt>
                            <dd className="min-w-0 truncate font-mono">{String(v)}</dd>
                          </div>
                        ))}
                      </dl>
                    </div>
                  )}
                </>
              )}
            </div>
          );
        })}
      </div>

      {total > 0 && (
        <p className="text-muted-foreground text-[11px]">
          {t('pipeline.total', { ms: total }, `Součet fází: ${total} ms`)}
          {data.responseMs != null &&
            ` · ${t('pipeline.measured', { ms: data.responseMs }, `naměřená odezva ${data.responseMs} ms`)}`}
        </p>
      )}
    </Card>
  );
}
