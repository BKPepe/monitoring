import * as React from 'react';
import { Link } from 'react-router';
import {
  Activity,
  BellOff,
  Bot,
  Cpu,
  Info,
  Lightbulb,
  ListChecks,
  OctagonAlert,
  Router,
  ShieldCheck,
  TrendingUp,
  TriangleAlert,
  type LucideIcon,
} from 'lucide-react';
import { PageHeader } from '@/components/layout/page-header';
import { usePageChrome } from '@/components/layout/shell-context';
import { FilterPills, type FilterOption, type FilterTone } from '@/components/filter-pills';
import { useFindings } from '@/components/findings-list';
import { CommandBlock, MuteDialog, MutedRow } from '@/components/router-recommendations';
import { Button } from '@/components/ui/button';
import { IconTile, type IconTileTone } from '@/components/ui/icon-tile';
import { Pill } from '@/components/ui/pill';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import type { Finding, FindingsResponse, RouterRecommendation } from '@/api/types';
import { appApi } from '@/api/app-api';
import { useLanguage } from '@/context/language-context';
import { devicesWithoutFindings, groupByDevice } from '@/lib/findings';
import { localeFor } from '@/lib/metric-format';
import { cn, formatRelative } from '@/lib/utils';

/**
 * Alerts (W2-8, NetPulse "Alerts & activity"): the server's one findings feed
 * (C-12) as a feed - three tiles with the counts, the severity and source
 * filters, then every device with its items, worst first, each item on a
 * rail in its severity colour with how long it has been open.
 *
 * The page used to assemble three lists of its own - websites and
 * certificates from the monitor list, trends from dashboard_insights and one
 * recommendations card per router - and each ranked, worded and failed on its
 * own. The server now owns the list, its order and its words (action=findings),
 * so a device reads the same here, on the dashboard and on its own page, and a
 * source that failed is named instead of silently missing. The filters only
 * narrow that one answer: there is still exactly one request.
 */
type Severity = Finding['severity'];

const SEVERITY: Record<Severity, { icon: LucideIcon; tone: IconTileTone; edge: string }> = {
  critical: { icon: OctagonAlert, tone: 'down', edge: 'border-l-down' },
  warning: { icon: TriangleAlert, tone: 'warning', edge: 'border-l-warning' },
  info: { icon: Info, tone: 'info', edge: 'border-l-info' },
};

const SEVERITY_TONE: Record<Severity, FilterTone> = { critical: 'down', warning: 'warning', info: 'info' };

/** The sources the server names (action=findings), with an icon for their chip. 'tip' is sent by newer servers. */
const SOURCE_ICON: Record<string, LucideIcon> = {
  status: Activity,
  certificate: ShieldCheck,
  check: ListChecks,
  router: Router,
  metric: Cpu,
  tip: Lightbulb,
  insight: TrendingUp,
  agent: Bot,
};

type TranslateFn = ReturnType<typeof useLanguage>['t'];

function severityLabel(severity: Severity, t: TranslateFn): string {
  if (severity === 'critical') return t('rec.severity_critical', 'Kritické');
  if (severity === 'info') return t('rec.severity_info', 'Pro informaci');
  return t('rec.severity_warning', 'Varování');
}

/** Spelled out source by source: a composed key could not be checked by the dictionary test. */
function sourceLabel(source: string, t: TranslateFn): string {
  switch (source) {
    case 'status':
      return t('insights.src_status', 'Stav');
    case 'certificate':
      return t('insights.src_certificate', 'Certifikáty');
    case 'check':
      return t('insights.src_check', 'Kontroly');
    case 'router':
      return t('insights.src_router', 'Routery');
    case 'metric':
      return t('insights.src_metric', 'Metriky');
    case 'tip':
      return t('insights.src_tip', 'Tipy');
    case 'insight':
      return t('insights.src_insight', 'Trendy');
    case 'agent':
      return t('insights.src_agent', 'Agenti');
    default:
      return source;
  }
}

/**
 * "před 12 min" for a moment, the day for a calendar date (a certificate's
 * expiry day has no hour to count from); the exact value stays in the tooltip.
 */
function sinceText(value: string, lang: 'cs' | 'en'): { label: string; exact: string } | null {
  const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value);
  const date = new Date(dateOnly ? `${value}T00:00:00` : value);
  if (Number.isNaN(date.getTime())) return null;
  const locale = localeFor(lang);
  const exact = dateOnly
    ? date.toLocaleDateString(locale)
    : date.toLocaleString(locale, { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' });
  return { label: dateOnly ? exact : formatRelative(date.toISOString(), lang), exact };
}

function FeedItem({ finding, last, onMute }: { finding: Finding; last: boolean; onMute?: (f: Finding) => void }) {
  const { t, lang } = useLanguage();
  const meta = SEVERITY[finding.severity] ?? SEVERITY.warning;
  const since = finding.since ? sinceText(finding.since, lang) : null;
  return (
    <li className="flex gap-3" data-severity={finding.severity}>
      {/* The rail: the severity icon, and a line down to the next item of the same device. */}
      <div aria-hidden="true" className="flex w-9 shrink-0 flex-col items-center">
        <IconTile icon={meta.icon} tone={meta.tone} />
        {!last && <span className="bg-border mt-1 w-px flex-1" />}
      </div>
      <div
        className={cn(
          'mb-2 min-w-0 flex-1 rounded-lg border-l-2 px-3 py-2.5 text-xs leading-relaxed',
          meta.edge,
          finding.severity === 'critical' ? 'bg-raised' : 'bg-card'
        )}
      >
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1">
            <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
              {/* The icon is decoration; the severity is said in words. */}
              <span className="sr-only">{severityLabel(finding.severity, t)}: </span>
              <span className="font-semibold">{finding.title}</span>
              {finding.severity === 'critical' && (
                <Pill tone="down" size="sm" aria-hidden="true">
                  {severityLabel('critical', t)}
                </Pill>
              )}
            </p>
            {finding.detail && <p className="text-muted-foreground">{finding.detail}</p>}
          </div>
          {since && (
            <time
              dateTime={finding.since ?? undefined}
              title={t('rec.open_since', { date: since.exact }, `trvá od ${since.exact}`)}
              className="text-muted-foreground figure mt-0.5 shrink-0 text-2xs whitespace-nowrap"
            >
              {since.label}
            </time>
          )}
          {onMute && (
            <Button size="sm" variant="outline" onClick={() => onMute(finding)} className="-my-0.5 shrink-0 gap-1.5">
              <BellOff aria-hidden="true" />
              {t('rec.mute', 'Ztlumit')}
            </Button>
          )}
        </div>
        {finding.action && (
          <p className="mt-1">
            <span className="text-muted-foreground">{t('rec.action', 'Co udělat')}:</span> {finding.action}
          </p>
        )}
        {finding.rec?.command && <CommandBlock command={finding.rec.command} />}
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
      tone="warning"
      message={t(
        'findings.incomplete',
        { sources: names.join(', ') },
        `Seznam není úplný: ${names.join(', ')} se nepodařilo načíst.`
      )}
    />
  );
}

/** One of the three count tiles; a zero keeps a neutral icon - nothing to look at. */
function CountTile({
  severity,
  count,
  devices,
}: {
  severity: Severity;
  count: number;
  /** On how many devices. */
  devices: number;
}) {
  const { t } = useLanguage();
  const meta = SEVERITY[severity];
  return (
    <div className="bg-card text-card-foreground shadow-card panel-sheen flex min-w-0 flex-col items-start gap-2 rounded-xl border border-border p-3 sm:flex-row sm:items-center sm:gap-3 sm:p-4">
      <IconTile
        icon={meta.icon}
        tone={count > 0 ? meta.tone : 'neutral'}
        className="sm:size-11 sm:rounded-xl sm:[&>svg]:size-5"
      />
      <div className="min-w-0">
        <p className="figure text-2xl leading-none font-semibold">{count}</p>
        <p className="micro-label mt-1.5 break-words">{severityLabel(severity, t)}</p>
        <p className="text-muted-foreground mt-0.5 hidden truncate text-2xs sm:block">
          {count > 0
            ? t('insights.tile_devices', { n: devices }, `zařízení: ${devices}`)
            : t('insights.tile_none', 'nic takového teď není')}
        </p>
      </div>
    </div>
  );
}

export function InsightsPage() {
  const { t, lang } = useLanguage();
  const { state, reload } = useFindings({ limit: 500 });
  const [severity, setSeverity] = React.useState<Severity | 'all'>('all');
  const [source, setSource] = React.useState<string>('all');
  const [muting, setMuting] = React.useState<Finding | null>(null);
  const [busyKey, setBusyKey] = React.useState<string | null>(null);
  const [failedKey, setFailedKey] = React.useState<string | null>(null);

  usePageChrome({ onRefresh: reload });

  const header = (
    <PageHeader
      title={t('insights.title', 'Upozornění')}
      subtitle={t(
        'insights.subtitle',
        'Co vyplývá z naměřených dat: weby mimo provoz, končící certifikáty, trendy a odchylky a doporučení pro routery.'
      )}
    />
  );

  if (state.status === 'loading') {
    return (
      <div className="space-y-6">
        {header}
        <LoadingState label={t('findings.loading', 'Načítám zjištění…')} />
      </div>
    );
  }
  if (state.status === 'error') {
    return (
      <div className="space-y-6">
        {header}
        <ErrorState
          message={t('findings.error', 'Zjištění se nepodařilo načíst. Nevíme, jestli je vše v pořádku.')}
          onRetry={reload}
        />
      </div>
    );
  }

  const data = state.data;
  const all = Array.isArray(data.findings) ? data.findings : [];
  const shown = all.filter(
    (f) => (severity === 'all' || f.severity === severity) && (source === 'all' || f.source === source)
  );
  const canMute = data.canMute === true;
  const muteFor = (f: Finding) => (canMute && f.source === 'router' && f.rec ? setMuting : undefined);
  const muted = (Array.isArray(data.muted) ? data.muted : []).filter((f) => f.rec);
  const others = devicesWithoutFindings(data);
  const failedSources = (data.sourceErrors ?? []).length > 0;
  const devices = Array.isArray(data.devices) ? data.devices : [];
  const onDevices = (key: Severity) => devices.filter((d) => d[key] > 0).length;

  const severityOptions: FilterOption<Severity | 'all'>[] = [
    { value: 'all', label: t('infra.filter_all', 'Vše'), count: all.length },
    ...(['critical', 'warning', 'info'] as const).map((s) => ({
      value: s,
      label: severityLabel(s, t),
      count: all.filter((f) => f.severity === s).length,
      tone: SEVERITY_TONE[s],
    })),
  ];
  const sources = [...new Set(all.map((f) => f.source as string))];
  const sourceOptions: FilterOption<string>[] = [
    { value: 'all', label: t('insights.src_all', 'Všechny zdroje') },
    ...sources.map((s) => ({
      value: s,
      label: sourceLabel(s, t),
      count: all.filter((f) => f.source === s).length,
      icon: SOURCE_ICON[s],
    })),
  ];

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

  const groups = groupByDevice({ ...data, findings: shown });

  return (
    <div className="space-y-6">
      {header}

      <div className="grid grid-cols-3 gap-2 sm:gap-3">
        <CountTile severity="critical" count={data.counts?.critical ?? 0} devices={onDevices('critical')} />
        <CountTile severity="warning" count={data.counts?.warning ?? 0} devices={onDevices('warning')} />
        <CountTile severity="info" count={data.counts?.info ?? 0} devices={onDevices('info')} />
      </div>

      <SourceErrors data={data} />

      {all.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <FilterPills
            label={t('insights.filter_severity', 'Závažnost')}
            value={severity}
            options={severityOptions}
            onChange={setSeverity}
          />
          {sources.length > 1 && (
            <FilterPills
              variant="chips"
              label={t('insights.filter_source', 'Zdroj')}
              value={source}
              options={sourceOptions}
              onChange={setSource}
            />
          )}
          <span className="text-muted-foreground figure ml-auto text-2xs">
            {t('insights.shown_count', { n: shown.length }, `upozornění: ${shown.length}`)}
          </span>
        </div>
      )}

      {all.length === 0 ? (
        // An all-clear only when every source answered: next to "the list is
        // not complete" it would be a verdict nobody measured (CR-12).
        failedSources ? null : (
          <EmptyState boxed title={t('findings.none', 'Nic k řešení - žádné zjištění.')} />
        )
      ) : shown.length === 0 ? (
        <EmptyState boxed title={t('insights.filter_empty', 'Filtru neodpovídá žádné upozornění.')} />
      ) : (
        <div className="space-y-6">
          {groups.map(({ device, findings: list }) => (
            <section key={device.monitorId} aria-label={device.monitorName} className="space-y-2.5">
              <h2 className="flex flex-wrap items-baseline gap-x-2 text-base font-semibold tracking-tight">
                <Link
                  to={`/infrastructure/${device.monitorId}`}
                  className="focus-visible:ring-ring rounded-sm hover:underline focus-visible:ring-2 focus-visible:outline-none"
                >
                  {device.monitorName}
                </Link>
                <span className="text-muted-foreground figure text-2xs font-normal">
                  {t('insights.group_count', { n: list.length }, `upozornění: ${list.length}`)}
                </span>
              </h2>
              <ul>
                {list.map((f, i) => (
                  <FeedItem key={f.key} finding={f} last={i === list.length - 1} onMute={muteFor(f)} />
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}

      {others > 0 && (
        <p className="text-muted-foreground figure text-center text-xs">
          {t('findings.others_clean', { n: others }, `Ostatní zařízení bez nálezů (${others})`)}
        </p>
      )}

      {muted.length > 0 && (
        <details className="bg-card rounded-xl border border-border p-4">
          <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs font-medium">
            {t('rec.muted_group', { n: muted.length }, `Ztlumená (${muted.length})`)}
          </summary>
          <ul className="mt-3 space-y-2">
            {muted.map((f) => (
              <MutedRow
                key={f.key}
                item={f.rec as RouterRecommendation}
                locale={localeFor(lang)}
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
