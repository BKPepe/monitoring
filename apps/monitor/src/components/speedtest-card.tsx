import { useState, useEffect, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { StatBlock } from '@/components/stat-block';
import { Gauge, ArrowDown, ArrowUp } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { MetricChart } from '@/components/charts/metric-chart';
import { ErrorState } from '@/components/ui/states';
import type { ChartData, SpeedtestMeasurement } from '@/api/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { serverTimeMs } from '@/lib/probe-locations';

export interface SpeedAverage {
  days: number;
  samples: number;
  downloadMbps: number | null;
  uploadMbps: number | null;
  pingMs: number | null;
  downloadMinMbps: number | null;
  downloadMaxMbps: number | null;
  measuredSince: string | null;
}

export interface SpeedtestData {
  measurements: SpeedtestMeasurement[];
  /** The WAN line: LTE and mixed tests never enter it. */
  averages: Record<string, SpeedAverage>;
  /** The LTE backup's own averages (servers before this field omit it). */
  backupAverages?: Record<string, SpeedAverage>;
}

/** undefined = loading, null = the request failed - never "no measurements". */
export type SpeedtestAnswer = SpeedtestData | null | undefined;

/** How many tests the card lists before "Zobrazit vše": the newest three answer "how is it now". */
const RECENT_ROWS = 3;

const fmt = (v: number | null | undefined, unit: string) => (v === null || v === undefined ? '—' : `${v} ${unit}`);

type TranslateFn = ReturnType<typeof useLanguage>['t'];

/**
 * Who started the test. Spelled out key by key, and an unknown value stays a
 * dash: rows stored before agent 0.1.7 do not say, and guessing "Turris OS"
 * would invent the answer.
 */
function startedByLabel(startedBy: SpeedtestMeasurement['startedBy'], t: TranslateFn): string {
  if (startedBy === 'turris') return t('speed.started_turris', 'Turris OS');
  if (startedBy === 'agent') return t('speed.started_agent', 'Monitoring');
  return '—';
}

/**
 * The test server, or why there is none. An agent before 0.1.7 sent neither
 * the server nor the tool, so "Server —" on those rows read as a failure to
 * find one. It was never recorded, and nothing is backfilled or guessed.
 */
function serverLabel(m: SpeedtestMeasurement, t: TranslateFn): { text: string; muted: boolean } {
  if (m.server) return { text: m.server, muted: false };
  if (m.tool == null)
    return { text: t('speed.server_not_recorded', 'nezaznamenáno (agent 0.1.6 a starší)'), muted: true };
  return { text: '—', muted: false };
}

/**
 * A test that ran over the LTE backup, in whole or in part, says nothing about
 * the WAN line. The Turris runs its nightly test unbound, and during a WAN
 * outage that was the LTE: 47 Mbit/s shown as the speed of a 400 Mbit/s fibre.
 */
function offWan(m: SpeedtestMeasurement): boolean {
  return m.uplink === 'backup' || m.uplink === 'mixed';
}

/**
 * Which line carried a test. A server guess from an outage is marked with a
 * question mark and says why; an unknown line stays a dash, never "WAN".
 */
function lineLabel(m: SpeedtestMeasurement, t: TranslateFn): { text: string; title?: string } {
  if (m.uplink === 'wan') return { text: 'WAN' };
  if (m.uplink === 'mixed') return { text: t('speed.line_mixed', 'WAN i LTE') };
  if (m.uplink === 'backup') {
    return m.uplinkSource === 'outage'
      ? { text: 'LTE?', title: t('speed.line_guess', 'Proběhl během výpadku WAN – odhad, ne měření.') }
      : { text: t('speed.line_backup', 'LTE záloha') };
  }
  return { text: '—' };
}

/** A date in the chosen language; measured_at is MySQL's space form, which WebKit cannot parse on its own. */
function useWhenLabel() {
  const { lang } = useLanguage();
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  return (value: string) => {
    const ms = serverTimeMs(value);
    return ms === null
      ? value
      : new Date(ms).toLocaleString(locale, {
          day: 'numeric',
          month: 'numeric',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        });
  };
}

/**
 * The router's speed tests (`speedtest_history`, the last 30). Shared by the
 * router's merged line card and by the plain card other devices get, so both
 * read the same answer the same way.
 */
export function useSpeedtestHistory(monitorId: number): { data: SpeedtestAnswer; reload: () => void } {
  const [data, setData] = useState<SpeedtestAnswer>(undefined);
  const load = useCallback(() => {
    return fetch(`/status/api.php?action=speedtest_history&monitor_id=${monitorId}&limit=30`, {
      credentials: 'include',
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
      .then((d) => setData(d))
      .catch(() => setData(null));
  }, [monitorId]);

  useEffect(() => {
    load();
  }, [load]);

  return { data, reload: () => void load() };
}

/**
 * Every test as its own bar, the newest three, and the rest on request.
 *
 * A test is a fact of its own: the line the card used to draw between two
 * tests a week apart invented every speed in between (charts-12), so each
 * test is a bar and a week without tests is an empty stretch of axis. The
 * table used to list ten rows and a period table under the chart; the newest
 * three answer "how is it now", "Zobrazit vše" opens the rest.
 */
export function SpeedHistory({ data, monitorId }: { data: SpeedtestData; monitorId: number }) {
  const { t } = useLanguage();
  const whenLabel = useWhenLabel();
  const [expanded, setExpanded] = useState(false);

  // The card fetches thirty measurements; this is the only place that history
  // survives at all - the router keeps its results in a ramdisk. A failed test
  // stays null and has no bar, never a bar of zero throughput. The chart is the
  // WAN line: an LTE bar would read as the fibre collapsing for a night.
  const timed = data.measurements
    .filter((m) => !offWan(m))
    .reverse()
    .flatMap((m) => {
      const ms = serverTimeMs(m.measuredAt);
      return ms === null ? [] : [{ m, ms }];
    });
  // The newest LTE test gets its own line: the router is the case where the
  // nightly test ran over the backup, and its card shows only this history.
  const latestBackup = data.measurements.find((m) => m.uplink === 'backup') ?? null;
  const speedChart: ChartData | null =
    timed.length >= 2
      ? {
          id: `speedtest-${monitorId}`,
          title: t('speed.history_title', 'Naměřená rychlost v čase'),
          yMax: null,
          yMin: 0,
          series: [
            {
              key: 'download',
              label: t('speed.download', 'Stahování'),
              unit: 'Mbit/s',
              tone: 'network',
              points: timed.map(({ m, ms }) => ({ t: ms, v: m.downloadMbps })),
            },
            {
              key: 'upload',
              label: t('speed.upload', 'Odesílání'),
              unit: 'Mbit/s',
              tone: 'memory',
              points: timed.map(({ m, ms }) => ({ t: ms, v: m.uploadMbps })),
            },
          ],
        }
      : null;

  const periods: { key: string; label: string }[] = [
    { key: 'week', label: t('speed.week', 'Týden') },
    { key: 'month', label: t('speed.month', 'Měsíc') },
    { key: 'year', label: t('speed.year', 'Rok') },
  ];
  const averages = [
    ...periods.flatMap((p) => {
      const a = data.averages?.[p.key];
      return a ? [{ key: p.key, label: p.label, a }] : [];
    }),
    // The LTE backup's own rows, only where it was measured at all.
    ...periods.flatMap((p) => {
      const a = data.backupAverages?.[p.key];
      return a?.samples
        ? [{ key: `backup-${p.key}`, label: `${t('speed.line_backup', 'LTE záloha')} · ${p.label}`, a }]
        : [];
    }),
  ];
  const rows = expanded ? data.measurements : data.measurements.slice(0, RECENT_ROWS);
  const more = data.measurements.length > RECENT_ROWS || averages.length > 0;

  return (
    <div className="space-y-3">
      {/* A bar per test (charts-12): each is a fact of its own, and a line
          between two tests invents the speeds in between. */}
      {/* overflow-x-clip: the bar chart's screen-reader table (chart.tsx) is an
          absolutely placed table wider than a phone; unclipped it scrolled the
          whole page sideways at 390 px. Tooltips go to <body>, so nothing
          visible is cut. */}
      {speedChart && (
        <div className="overflow-x-clip">
          <MetricChart data={speedChart} height={170} bars />
        </div>
      )}

      {latestBackup && (
        <p className="text-muted-foreground text-xs">
          {t(
            'speed.last_backup',
            {
              down: fmt(latestBackup.downloadMbps, 'Mbit/s'),
              up: fmt(latestBackup.uploadMbps, 'Mbit/s'),
              at: whenLabel(latestBackup.measuredAt),
            },
            `Naposledy přes LTE zálohu: ↓ ${fmt(latestBackup.downloadMbps, 'Mbit/s')} · ↑ ${fmt(latestBackup.uploadMbps, 'Mbit/s')} (${whenLabel(latestBackup.measuredAt)})`
          )}
        </p>
      )}

      <div>
        <h4 className="mb-1.5 text-xs font-semibold">
          {t('speed.recent_title', { n: rows.length }, `Last ${rows.length} measurements`)}
        </h4>
        <Table dense>
          <TableHeader>
            <TableRow>
              <TableHead>{t('speed.when', 'Kdy')}</TableHead>
              <TableHead>{t('speed.download', 'Stahování')}</TableHead>
              <TableHead>{t('speed.upload', 'Odesílání')}</TableHead>
              <TableHead>{t('speed.line', 'Linka')}</TableHead>
              <TableHead>{t('speed.server', 'Server')}</TableHead>
              <TableHead>{t('speed.started_by', 'Spustil')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {rows.map((m) => (
              <TableRow key={m.measuredAt}>
                <TableCell className="whitespace-nowrap tabular-nums">{whenLabel(m.measuredAt)}</TableCell>
                {/* A damaged or failed measurement has no speed: a dash, never 0 Mbit/s. */}
                <TableCell className="tabular-nums">{fmt(m.downloadMbps, 'Mbit/s')}</TableCell>
                <TableCell className="tabular-nums">{fmt(m.uploadMbps, 'Mbit/s')}</TableCell>
                <TableCell className="whitespace-nowrap" title={lineLabel(m, t).title}>
                  {lineLabel(m, t).text}
                </TableCell>
                <TableCell
                  className={
                    serverLabel(m, t).muted ? 'text-muted-foreground text-2xs italic' : 'text-muted-foreground'
                  }
                >
                  {serverLabel(m, t).text}
                  {/* Whether the test was encrypted: TLS runs on the router's
                      CPU and can bound the result on its own. */}
                  {m.proto ? ` · ${m.proto.toUpperCase()}` : ''}
                </TableCell>
                <TableCell className="text-muted-foreground">{startedByLabel(m.startedBy, t)}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {expanded && averages.length > 0 && (
        <Table dense>
          <TableHeader>
            <TableRow>
              <TableHead>{t('speed.period', 'Období')}</TableHead>
              <TableHead>{t('speed.avg_down', 'Průměr ↓')}</TableHead>
              <TableHead>{t('speed.avg_up', 'Průměr ↑')}</TableHead>
              <TableHead>{t('speed.range', 'Rozsah ↓')}</TableHead>
              <TableHead>{t('speed.samples', 'Měření')}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {averages.map(({ key, label, a }) => {
              return (
                <TableRow key={key}>
                  <TableCell>{label}</TableCell>
                  <TableCell className="tabular-nums">{fmt(a.downloadMbps, 'Mbit/s')}</TableCell>
                  <TableCell className="tabular-nums">{fmt(a.uploadMbps, 'Mbit/s')}</TableCell>
                  <TableCell className="text-muted-foreground tabular-nums">
                    {a.downloadMinMbps === null || a.downloadMaxMbps === null
                      ? '—'
                      : `${a.downloadMinMbps} – ${a.downloadMaxMbps}`}
                  </TableCell>
                  {/* The sample count matters: an average of one measurement and
                      an average of thirty look identical in the table. */}
                  <TableCell className="text-muted-foreground tabular-nums">{a.samples}</TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      {more && (
        <Button size="sm" variant="outline" className="text-xs" onClick={() => setExpanded((v) => !v)}>
          {expanded
            ? t('speed.show_less', 'Zobrazit méně')
            : t('speed.show_all', { n: data.measurements.length }, `Zobrazit vše (${data.measurements.length})`)}
        </Button>
      )}
    </div>
  );
}

/**
 * Link speed measured by the device itself (librespeed-cli), for a device
 * that is not a router - a router reads its tests inside the line card of its
 * Network tab, next to the verdict they feed.
 *
 * The router stores results in /tmp, a ramdisk on OpenWrt - gone after a
 * reboot. The agent sends them to the server, so this card is the only place
 * where that history survives.
 */
export function SpeedtestCard({ monitorId }: { monitorId: number }) {
  const { t } = useLanguage();
  const { data, reload } = useSpeedtestHistory(monitorId);
  const whenLabel = useWhenLabel();

  // A failed request is not "no measurements". Silence here would read as a
  // line that was never tested, so the failure gets its own card (WAN 3.6).
  if (data === null) {
    return (
      <div data-slot="well" className="bg-inset rounded-xl border border-border space-y-3 p-5">
        <div className="flex flex-wrap items-center gap-2">
          <Gauge className="text-muted-foreground size-5" aria-hidden="true" />
          <h3 className="text-sm font-semibold">{t('speed.title', 'Rychlost linky')}</h3>
        </div>
        <ErrorState message={t('speed.error', 'Naměřené rychlosti se nepodařilo načíst.')} onRetry={reload} />
      </div>
    );
  }

  // While it loads, and when the device has no measurement at all, the card
  // does not render - an empty "nothing yet" frame just takes space on a page
  // full of other data.
  if (!data || !data.measurements || data.measurements.length === 0) {
    return null;
  }

  // The headline is the WAN line: the newest test that did not run over the
  // backup. The newest LTE test has its own line in the history below.
  const latest = data.measurements.find((m) => !offWan(m)) ?? null;

  return (
    <div data-slot="well" className="bg-inset rounded-xl border border-border space-y-4 p-5">
      <div className="flex flex-wrap items-center gap-2 border-b border-border pb-3">
        <Gauge className="text-muted-foreground size-5" aria-hidden="true" />
        <div className="min-w-0">
          <h3 className="text-sm font-semibold">{t('speed.title', 'Rychlost linky')}</h3>
          <p className="text-muted-foreground text-xs">
            {t('speed.subtitle', 'Měří router pomocí librespeed-cli. Historii drží monitoring, router jen dočasně.')}
          </p>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <StatBlock
          icon={ArrowDown}
          label={t('speed.download', 'Stahování')}
          value={fmt(latest?.downloadMbps, 'Mbit/s')}
        />
        <StatBlock icon={ArrowUp} label={t('speed.upload', 'Odesílání')} value={fmt(latest?.uploadMbps, 'Mbit/s')} />
        <StatBlock
          label={t('speed.ping', 'Odezva')}
          value={fmt(latest?.pingMs, 'ms')}
          hint={
            latest && latest.jitterMs !== null
              ? t('speed.jitter', { v: latest.jitterMs }, `rozptyl ${latest.jitterMs} ms`)
              : undefined
          }
        />
      </div>

      <SpeedHistory data={data} monitorId={monitorId} />

      {latest && (
        <p className="text-muted-foreground text-2xs">
          {t('speed.last', { at: whenLabel(latest.measuredAt) }, `Poslední měření: ${whenLabel(latest.measuredAt)}`)}
          {latest.server ? ` · ${latest.server}` : ''}
        </p>
      )}
    </div>
  );
}
