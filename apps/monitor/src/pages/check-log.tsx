import * as React from 'react';
import { Link, useSearchParams } from 'react-router';
import { RefreshCw } from 'lucide-react';
import { appApi, type ApiMonitor } from '@/api/app-api';
import { PageHeader } from '@/components/layout/page-header';
import { CollapsedTimeline } from '@/components/timeline';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/ui/panel';
import { usePageChrome } from '@/components/layout/shell-context';
import { ErrorState, LoadingState } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import type { TimelineEvent } from '@/data/model';

/** One row of action=events, as the server sends it. */
interface CheckRow {
  id: number;
  time: string;
  timeIso?: string | null;
  monitorId?: number | null;
  monitorName: string;
  type?: string | null;
  location?: string | null;
  rawStatus?: string;
  statusKey?: string;
  errorMsg?: string | null;
  isDown: boolean;
  isRecovery?: boolean;
  responseTime?: number | null;
  outageDurationSec?: number | null;
}

type LoadState = { status: 'loading' } | { status: 'error'; message: string } | { status: 'ready'; rows: CheckRow[] };

type TranslateFn = ReturnType<typeof useLanguage>['t'];

/** What one check found, in words: the title of its row. */
function checkLabel(row: CheckRow, t: TranslateFn): string {
  if (row.isDown) return t('checklog.down', 'Výpadek');
  const raw = row.statusKey ?? row.rawStatus;
  if (raw === 'warning') return t('checklog.warning', 'Zhoršená odezva');
  if (raw === 'maintenance') return t('checklog.maintenance', 'Údržba');
  if (raw === 'unknown' || raw === 'unknown_stale' || raw === 'unknown_new') {
    return t('checklog.unknown', 'Stav neznámý (agent nehlásí)');
  }
  if (row.isRecovery) return t('checklog.recovered', 'Obnoveno');
  return t('checklog.ok', 'Kontrola v pořádku');
}

function severityOf(row: CheckRow): TimelineEvent['severity'] {
  if (row.isDown) return 'down';
  const raw = row.statusKey ?? row.rawStatus;
  // A silent agent is a warning, as everywhere else (decision 5.10); a check
  // that passed, or one skipped for announced maintenance, is routine.
  if (raw === 'warning' || raw === 'unknown' || raw === 'unknown_stale') return 'warning';
  return row.isRecovery ? 'up' : 'info';
}

/**
 * The check log (W2-6): every monitor's check results in one place.
 *
 * The same monitor_logs rows used to appear on four pages - a 200-row table
 * under Incidents, an "audit log" on Users, the asset's Events tab and the
 * public page - each with its own filters, and the Incidents copy showed an
 * empty table when the request failed. Now there is one view. It opens on the
 * changes (outages, degradations, recoveries); the passing checks, which are
 * most of the rows, wait behind "Vše". `?monitor=` narrows it to one device,
 * which is how the device page links here.
 */
export function CheckLogPage() {
  const { t } = useLanguage();
  const [params, setParams] = useSearchParams();
  const monitorParam = Number(params.get('monitor'));
  const monitorId = Number.isInteger(monitorParam) && monitorParam > 0 ? monitorParam : null;
  const [state, setState] = React.useState<LoadState>({ status: 'loading' });
  const [attempt, setAttempt] = React.useState(0);
  const [monitors, setMonitors] = React.useState<ApiMonitor[]>([]);

  React.useEffect(() => {
    let active = true;
    const query = monitorId ? `&monitor_id=${monitorId}` : '';
    fetch(`/status/api.php?action=events&limit=200${query}`, { credentials: 'include' })
      .then(async (res) => {
        const data = await res.json().catch(() => null);
        // A failure - or a 200 without the list - is said, never drawn as an empty log.
        if (!res.ok || !data || !Array.isArray(data.events)) {
          const message = data && typeof data.message === 'string' ? data.message : `HTTP ${res.status}`;
          throw new Error(message);
        }
        if (active) setState({ status: 'ready', rows: data.events });
      })
      .catch((e: unknown) => {
        if (active) setState({ status: 'error', message: e instanceof Error ? e.message : String(e) });
      });
    return () => {
      active = false;
    };
  }, [monitorId, attempt]);

  // The picker only; without it the log still works for the whole fleet.
  React.useEffect(() => {
    let active = true;
    appApi
      .getMonitors()
      .then((rows) => {
        if (active && Array.isArray(rows)) setMonitors(rows);
      })
      .catch(() => {});
    return () => {
      active = false;
    };
  }, []);

  const reload = () => {
    setState({ status: 'loading' });
    setAttempt((n) => n + 1);
  };

  const events = React.useMemo<TimelineEvent[]>(() => {
    if (state.status !== 'ready') return [];
    return state.rows.map((row) => {
      const label = checkLabel(row, t);
      const minutes = row.outageDurationSec ? Math.round(row.outageDurationSec / 60) : null;
      return {
        id: row.id,
        // The whole fleet needs the device in the title; one device does not.
        title: monitorId ? label : `${row.monitorName}: ${label}`,
        detail:
          (row.errorMsg ?? '') +
          (minutes !== null ? t('checklog.duration', { min: minutes }, ` (trvání ${minutes} min)`) : ''),
        at: row.time,
        atIso: row.timeIso ?? null,
        severity: severityOf(row),
        kind: 'check' as const,
        location: row.location ?? undefined,
        method: row.type ?? undefined,
        responseMs: typeof row.responseTime === 'number' ? row.responseTime : null,
      };
    });
  }, [state, monitorId, t]);

  const current = monitors.find((m) => m.id === monitorId);
  usePageChrome({ onRefresh: reload });

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('checklog.title', 'Protokol kontrol')}
        subtitle={t(
          'checklog.subtitle',
          'Výsledky jednotlivých kontrol, nejnovější první. Otevírá se na změnách - výpadcích, zhoršeních a obnoveních; běžné úspěšné kontroly jsou pod „Vše“.'
        )}
        actions={
          // The way back to Incidenty is the header's back arrow (layout/nav-config).
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" onClick={reload} disabled={state.status === 'loading'}>
              <RefreshCw aria-hidden="true" className={state.status === 'loading' ? 'animate-spin' : undefined} />
              {t('checklog.refresh', 'Obnovit')}
            </Button>
          </div>
        }
      />

      <Panel bodyClassName="space-y-4">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <label htmlFor="checklog-monitor" className="micro-label">
            {t('checklog.monitor_label', 'Monitor')}
          </label>
          <select
            id="checklog-monitor"
            value={monitorId ?? ''}
            onChange={(e) => {
              const next = new URLSearchParams(params);
              if (e.target.value) next.set('monitor', e.target.value);
              else next.delete('monitor');
              setParams(next);
              setState({ status: 'loading' });
            }}
            className="bg-secondary/60 border-input hover:border-border-strong focus-visible:border-ring h-8 max-w-full rounded-md border px-2 text-xs"
          >
            <option value="">{t('checklog.all_monitors', 'Všechny monitory')}</option>
            {/* The selected monitor stays listed even before (or without) the picker's list. */}
            {monitorId && !current && <option value={monitorId}>#{monitorId}</option>}
            {monitors.map((m) => (
              <option key={m.id} value={m.id}>
                {m.name}
              </option>
            ))}
          </select>
          {monitorId && (
            <Link to={`/infrastructure/${monitorId}`} className="text-link text-xs font-semibold hover:underline">
              {t('checklog.open_device', 'Detail zařízení')} →
            </Link>
          )}
        </div>

        {state.status === 'loading' ? (
          <LoadingState size="inline" label={t('checklog.loading', 'Načítám protokol kontrol…')} />
        ) : state.status === 'error' ? (
          <ErrorState
            message={t(
              'checklog.load_failed',
              { error: state.message },
              `Protokol kontrol se nepodařilo načíst (${state.message}). Co kontroly zjistily, teď nevíme.`
            )}
            onRetry={reload}
          />
        ) : (
          <>
            <CollapsedTimeline events={events} filters />
            {/* Honest about the window: the API returns the newest rows, not the whole history. */}
            <p className="text-muted-foreground text-2xs">
              {t(
                'checklog.window',
                { n: events.length },
                `Zobrazeno ${events.length} nejnovějších záznamů. Dostupnost za delší období je v SLA výkazu.`
              )}{' '}
              <Link to="/reports" className="text-link font-semibold hover:underline">
                {t('checklog.to_sla', 'SLA výkaz')} →
              </Link>
            </p>
          </>
        )}
      </Panel>
    </div>
  );
}
