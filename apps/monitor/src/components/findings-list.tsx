import * as React from 'react';
import { Link } from 'react-router';
import { BellOff, Info, ListChecks, OctagonAlert, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { CommandBlock, MuteDialog, MutedRow } from '@/components/router-recommendations';
import { appApi } from '@/api/app-api';
import type { Finding, FindingSource, FindingsResponse, RouterRecommendation } from '@/api/types';
import { useLanguage } from '@/context/language-context';
import { devicesWithoutFindings, findingsFrom, groupByDevice } from '@/lib/findings';
import { cn } from '@/lib/utils';

/**
 * The findings list (C-12) in three densities over one server feed:
 * - 'top': the few worst on the dashboard, with the way to all of them;
 * - 'all': the Insights page, grouped by device, with the mute;
 * - 'device': one device's findings on its own page.
 *
 * The router recommendations card is the model: a severity icon and word,
 * what was found, what to do, since when, and a mute that leaves the item
 * visible. Severity is an icon plus text, never colour alone and never emoji.
 */
type FindingsState = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: FindingsResponse };

export function useFindings(opts: { monitorId?: number; limit?: number } = {}) {
  const { lang } = useLanguage();
  const { monitorId, limit } = opts;
  const [state, setState] = React.useState<FindingsState>({ status: 'loading' });
  const [token, setToken] = React.useState(0);

  React.useEffect(() => {
    let active = true;
    appApi
      .getFindings(lang, { monitorId, limit })
      .then((data) => {
        if (!active) return;
        // A 200 that is not the documented shape is a failure, not an empty list.
        setState(Array.isArray(data?.findings) ? { status: 'ready', data } : { status: 'error' });
      })
      .catch(() => {
        if (active) setState({ status: 'error' });
      });
    return () => {
      active = false;
    };
  }, [lang, monitorId, limit, token]);

  const reload = React.useCallback(() => setToken((n) => n + 1), []);
  return { state, reload };
}

const SEVERITY = {
  critical: { icon: OctagonAlert, text: 'text-down' },
  warning: { icon: TriangleAlert, text: 'text-warning' },
  info: { icon: Info, text: 'text-info' },
} as const;

type TranslateFn = ReturnType<typeof useLanguage>['t'];

function severityLabel(severity: Finding['severity'], t: TranslateFn): string {
  if (severity === 'critical') return t('rec.severity_critical', 'Kritické');
  if (severity === 'info') return t('rec.severity_info', 'Pro informaci');
  return t('rec.severity_warning', 'Varování');
}

/** A date-only value is a calendar day; a timestamp gets its time too. */
function formatSince(value: string, lang: string): string | null {
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value);
  const date = new Date(dateOnly ? `${value}T00:00:00` : value);
  if (Number.isNaN(date.getTime())) return null;
  const locale = lang === 'en' ? 'en-GB' : 'cs-CZ';
  return dateOnly
    ? date.toLocaleDateString(locale)
    : date.toLocaleString(locale, { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function SeverityIcon({ severity }: { severity: Finding['severity'] }) {
  const { t } = useLanguage();
  const meta = SEVERITY[severity] ?? SEVERITY.warning;
  const Icon = meta.icon;
  return (
    <Icon role="img" aria-label={severityLabel(severity, t)} className={cn('mt-0.5 size-4 shrink-0', meta.text)} />
  );
}

function FindingRow({
  finding,
  compact,
  showDevice,
  onMute,
}: {
  finding: Finding;
  compact: boolean;
  showDevice: boolean;
  onMute?: (finding: Finding) => void;
}) {
  const { t, lang } = useLanguage();
  const since = finding.since ? formatSince(finding.since, lang) : null;
  return (
    <li className="flex gap-2.5 py-2.5 text-xs leading-relaxed" data-severity={finding.severity}>
      <SeverityIcon severity={finding.severity} />
      <div className="min-w-0 flex-1 space-y-0.5">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <p className="min-w-0 text-sm">
            {showDevice && (
              <>
                <Link to={`/infrastructure/${finding.monitorId}`} className="text-link font-semibold hover:underline">
                  {finding.monitorName}
                </Link>
                {': '}
              </>
            )}
            <span className="font-semibold">{finding.title}</span>
          </p>
          {onMute && (
            <Button size="sm" variant="outline" onClick={() => onMute(finding)} className="shrink-0 gap-1.5">
              <BellOff aria-hidden="true" />
              {t('rec.mute', 'Ztlumit')}
            </Button>
          )}
        </div>
        {finding.detail && <p className={cn('text-muted-foreground', compact && 'line-clamp-1')}>{finding.detail}</p>}
        {!compact && finding.action && (
          <p>
            <span className="text-muted-foreground">{t('rec.action', 'Co udělat')}:</span> {finding.action}
          </p>
        )}
        {!compact && finding.rec?.command && <CommandBlock command={finding.rec.command} />}
        {!compact && since && (
          <p className="text-muted-foreground text-2xs">{t('rec.open_since', { date: since }, `trvá od ${since}`)}</p>
        )}
      </div>
    </li>
  );
}

/** Says out loud which source failed: the list is then incomplete, never "clean". */
function SourceErrors({ data }: { data: FindingsResponse }) {
  const { t } = useLanguage();
  const failed = Array.isArray(data.sourceErrors) ? data.sourceErrors : [];
  if (failed.length === 0) return null;
  const names = [...new Set(failed.map((e) => e.source))].map((source) =>
    source === 'router'
      ? t('findings.source_router', 'doporučení pro routery')
      : source === 'insight'
        ? t('findings.source_insight', 'trendy a odchylky')
        : t('findings.source_attention', 'stav zařízení')
  );
  return (
    <ErrorState
      size="inline"
      tone="warning"
      message={t(
        'findings.incomplete',
        { sources: names.join(', ') },
        `Seznam není úplný: ${names.join(', ')} se nepodařilo načíst.`
      )}
    />
  );
}

export function FindingsList({
  density,
  monitorId,
  sources,
  limit = 3,
}: {
  density: 'top' | 'all' | 'device';
  /** 'device' only: whose findings. */
  monitorId?: number;
  /** Only these sources - e.g. the dashboard leaves out what its attention list already says. */
  sources?: readonly FindingSource[];
  /** 'top' only: how many rows. */
  limit?: number;
}) {
  const { t, lang } = useLanguage();
  const { state, reload } = useFindings(
    density === 'device' ? { monitorId, limit: 100 } : { limit: density === 'all' ? 500 : 50 }
  );
  const [muting, setMuting] = React.useState<Finding | null>(null);
  const [busyKey, setBusyKey] = React.useState<string | null>(null);
  const [failedKey, setFailedKey] = React.useState<string | null>(null);

  if (state.status === 'loading') {
    return density === 'top' ? null : <LoadingState label={t('findings.loading', 'Načítám zjištění…')} />;
  }
  if (state.status === 'error') {
    return (
      <ErrorState
        size={density === 'top' ? 'inline' : 'block'}
        message={t('findings.error', 'Zjištění se nepodařilo načíst. Nevíme, jestli je vše v pořádku.')}
        onRetry={reload}
      />
    );
  }

  const data = state.data;
  const findings = findingsFrom(data, sources);
  const canMute = data.canMute === true && density !== 'top';
  const muteFor = (f: Finding) => (canMute && f.source === 'router' && f.rec ? setMuting : undefined);

  const unmute = async (f: Finding, rec: RouterRecommendation) => {
    setBusyKey(f.key);
    setFailedKey(null);
    try {
      const res = await appApi.muteRouterRecommendation(f.monitorId, rec.key, false);
      if (!res || res.ok !== true) throw new Error('not saved');
      reload();
    } catch {
      setFailedKey(f.key);
    } finally {
      setBusyKey(null);
    }
  };

  if (density === 'top') {
    // Nothing to report takes no room on the dashboard; a failed source still speaks.
    if (findings.length === 0 && (data.sourceErrors ?? []).length === 0) return null;
    return (
      <Card className="space-y-2 p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <ListChecks aria-hidden="true" className="text-muted-foreground size-4" />
          {t('findings.title', 'Upozornění')}
        </h2>
        <SourceErrors data={data} />
        <ul className="divide-border divide-y">
          {findings.slice(0, limit).map((f) => (
            <FindingRow key={f.key} finding={f} compact showDevice />
          ))}
        </ul>
        <Link to="/insights" className="text-link inline-block text-xs font-semibold hover:underline">
          {t('findings.all_link', { n: data.total }, `Všechna upozornění (${data.total}) →`)}
        </Link>
      </Card>
    );
  }

  const locale = lang === 'en' ? 'en-GB' : 'cs-CZ';
  const muted = (Array.isArray(data.muted) ? data.muted : []).filter((f) => f.rec);
  const others = devicesWithoutFindings(data);

  return (
    <div className="space-y-4">
      <SourceErrors data={data} />
      {findings.length === 0 && (data.sourceErrors ?? []).length > 0 ? null : findings.length === 0 ? (
        // An all-clear only when every source answered: next to "the list is
        // not complete" it would be a verdict nobody measured (CR-12).
        <EmptyState
          size={density === 'device' ? 'inline' : 'block'}
          title={t('findings.none', 'Nic k řešení - žádné zjištění.')}
        />
      ) : density === 'device' ? (
        <ul className="divide-border divide-y">
          {findings.map((f) => (
            <FindingRow key={f.key} finding={f} compact={false} showDevice={false} onMute={muteFor(f)} />
          ))}
        </ul>
      ) : (
        groupByDevice({ ...data, findings }).map(({ device, findings: list }) => (
          <section key={device.monitorId} aria-label={device.monitorName} className="space-y-1">
            <h3 className="flex flex-wrap items-center gap-2 text-sm font-semibold">
              <Link to={`/infrastructure/${device.monitorId}`} className="text-link hover:underline">
                {device.monitorName}
              </Link>
              <span className="text-muted-foreground text-xs font-normal tabular-nums">({list.length})</span>
            </h3>
            <ul className="divide-border divide-y">
              {list.map((f) => (
                <FindingRow key={f.key} finding={f} compact={false} showDevice={false} onMute={muteFor(f)} />
              ))}
            </ul>
          </section>
        ))
      )}
      {density === 'all' && others > 0 && (
        <p className="text-muted-foreground text-xs">
          {t('findings.others_clean', { n: others }, `Ostatní zařízení bez nálezů (${others})`)}
        </p>
      )}
      {muted.length > 0 && (
        <details>
          <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs font-medium">
            {t('rec.muted_group', { n: muted.length }, `Ztlumená (${muted.length})`)}
          </summary>
          <ul className="mt-2 space-y-2">
            {muted.map((f) => (
              <MutedRow
                key={f.key}
                item={f.rec as RouterRecommendation}
                locale={locale}
                busy={busyKey === f.key}
                failed={failedKey === f.key}
                onUnmute={data.canMute ? (rec) => void unmute(f, rec) : undefined}
              />
            ))}
          </ul>
        </details>
      )}
      {muting?.rec && (
        <MuteDialog
          monitorId={muting.monitorId}
          item={muting.rec}
          onClose={() => setMuting(null)}
          onSaved={() => {
            setMuting(null);
            reload();
          }}
        />
      )}
    </div>
  );
}
