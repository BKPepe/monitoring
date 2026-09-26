import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { StatBlock } from '@/components/stat-block';
import { Gauge, ArrowDown, ArrowUp } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { MetricChart } from '@/components/charts/metric-chart';
import { insertGaps } from '@/lib/series-gaps';
import { ErrorState } from '@/components/ui/states';
import type { ChartData, SpeedtestMeasurement } from '@/api/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { serverTimeMs } from '@/lib/probe-locations';

interface Average {
  days: number;
  samples: number;
  downloadMbps: number | null;
  uploadMbps: number | null;
  pingMs: number | null;
  downloadMinMbps: number | null;
  downloadMaxMbps: number | null;
  measuredSince: string | null;
}

interface SpeedtestData {
  measurements: SpeedtestMeasurement[];
  /** The WAN line: LTE and mixed tests never enter it. */
  averages: Record<string, Average>;
  /** The LTE backup's own averages (servers before this field omit it). */
  backupAverages?: Record<string, Average>;
}

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

/**
 * Link speed measured by the router itself (librespeed-cli).
 *
 * The router stores results in /tmp, a ramdisk on OpenWrt - gone after a
 * reboot. The agent sends them to the server, so this card is the only place
 * where that history survives.
 */
export function SpeedtestCard({ monitorId }: { monitorId: number }) {
  const { t, lang } = useLanguage();
  const [data, setData] = useState<SpeedtestData | null | undefined>(undefined);
  const locale = lang === 'cs' ? 'cs-CZ' : 'en-GB';
  // measured_at is MySQL's 'YYYY-MM-DD HH:MM:SS': WebKit's Date.parse gives NaN
  // for the space form, which drew an empty chart in Safari. The table printed
  // the raw string; it now reads as a date in the chosen language.
  const at = (value: string) => serverTimeMs(value);
  const whenLabel = (value: string) => {
    const ms = at(value);
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

  // A failed request is not "no measurements". Silence here would read as a
  // line that was never tested, so the failure gets its own card (WAN 3.6).
  if (data === null) {
    return (
      <Card className="space-y-3 p-6">
        <div className="flex flex-wrap items-center gap-2">
          <Gauge className="text-primary size-5" />
          <h3 className="text-base font-bold">{t('speed.title', 'Rychlost linky')}</h3>
        </div>
        <ErrorState
          message={t('speed.error', 'Naměřené rychlosti se nepodařilo načíst.')}
          onRetry={() => void load()}
        />
      </Card>
    );
  }

  // While it loads, and when the router has no measurement at all, the card
  // does not render - an empty "nothing yet" frame just takes space on a page
  // full of other data.
  if (!data || !data.measurements || data.measurements.length === 0) {
    return null;
  }

  // The headline is the WAN line: the newest test that did not run over the
  // backup. The newest LTE test gets its own line below it.
  const latest = data.measurements.find((m) => !offWan(m)) ?? null;
  const latestBackup = data.measurements.find((m) => m.uplink === 'backup') ?? null;
  // The card fetches thirty measurements and used to show one. This is the
  // only place that history survives at all - the router keeps its results in
  // a ramdisk - so it gets drawn. A failed test stays null and reads as a gap,
  // never as zero throughput.
  // The chart is the WAN line too: an LTE point would read as the fibre
  // collapsing for a night.
  const ascending = data.measurements.filter((m) => !offWan(m)).reverse();
  // A row whose time cannot be read has no place on a time axis.
  const timed = ascending.flatMap((m) => {
    const ms = at(m.measuredAt);
    return ms === null ? [] : [{ m, ms }];
  });
  // The table shows the newest ten; the chart above carries the whole answer.
  const recent = data.measurements.slice(0, 10);
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
              unit: 'Mb/s',
              tone: 'network',
              points: insertGaps(timed.map(({ m, ms }) => ({ t: ms, v: m.downloadMbps }))),
            },
            {
              key: 'upload',
              label: t('speed.upload', 'Odesílání'),
              unit: 'Mb/s',
              tone: 'memory',
              points: insertGaps(timed.map(({ m, ms }) => ({ t: ms, v: m.uploadMbps }))),
            },
          ],
        }
      : null;
  const periods: { key: string; label: string }[] = [
    { key: 'week', label: t('speed.week', 'Týden') },
    { key: 'month', label: t('speed.month', 'Měsíc') },
    { key: 'year', label: t('speed.year', 'Rok') },
  ];

  return (
    <Card className="space-y-4 p-6">
      <div className="flex flex-wrap items-center gap-2 border-b border-border pb-3">
        <Gauge className="size-5 text-primary" />
        <div className="min-w-0">
          <h3 className="text-base font-bold">{t('speed.title', 'Rychlost linky')}</h3>
          <p className="text-muted-foreground text-xs">
            {t('speed.subtitle', 'Měří router pomocí librespeed-cli. Historii drží monitoring, router jen dočasně.')}
          </p>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <StatBlock
          icon={ArrowDown}
          label={t('speed.download', 'Stahování')}
          value={fmt(latest?.downloadMbps, 'Mb/s')}
        />
        <StatBlock icon={ArrowUp} label={t('speed.upload', 'Odesílání')} value={fmt(latest?.uploadMbps, 'Mb/s')} />
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

      {latestBackup && (
        <p className="text-muted-foreground text-xs">
          {t(
            'speed.last_backup',
            {
              down: fmt(latestBackup.downloadMbps, 'Mb/s'),
              up: fmt(latestBackup.uploadMbps, 'Mb/s'),
              at: whenLabel(latestBackup.measuredAt),
            },
            `Naposledy přes LTE zálohu: ↓ ${fmt(latestBackup.downloadMbps, 'Mb/s')} · ↑ ${fmt(latestBackup.uploadMbps, 'Mb/s')} (${whenLabel(latestBackup.measuredAt)})`
          )}
        </p>
      )}

      {speedChart && <MetricChart data={speedChart} height={170} />}

      <div>
        <h4 className="mb-1.5 text-xs font-semibold">
          {t('speed.recent_title', { n: recent.length }, `Last ${recent.length} measurements`)}
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
            {recent.map((m) => (
              <TableRow key={m.measuredAt}>
                <TableCell className="whitespace-nowrap tabular-nums">{whenLabel(m.measuredAt)}</TableCell>
                {/* A damaged or failed measurement has no speed: a dash, never 0 Mb/s. */}
                <TableCell className="tabular-nums">{fmt(m.downloadMbps, 'Mb/s')}</TableCell>
                <TableCell className="tabular-nums">{fmt(m.uploadMbps, 'Mb/s')}</TableCell>
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
          {[
            ...periods.map((p) => ({ ...p, a: data.averages?.[p.key] })),
            // The LTE backup's own rows, only where it was measured at all.
            ...periods.map((p) => ({
              key: `backup-${p.key}`,
              label: `${t('speed.line_backup', 'LTE záloha')} · ${p.label}`,
              a: data.backupAverages?.[p.key]?.samples ? data.backupAverages[p.key] : undefined,
            })),
          ].map(({ key, label, a }) => {
            if (!a) return null;
            return (
              <TableRow key={key}>
                <TableCell>{label}</TableCell>
                <TableCell className="tabular-nums">{fmt(a.downloadMbps, 'Mb/s')}</TableCell>
                <TableCell className="tabular-nums">{fmt(a.uploadMbps, 'Mb/s')}</TableCell>
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

      {latest && (
        <p className="text-muted-foreground text-2xs">
          {t('speed.last', { at: whenLabel(latest.measuredAt) }, `Poslední měření: ${whenLabel(latest.measuredAt)}`)}
          {latest.server ? ` · ${latest.server}` : ''}
        </p>
      )}
    </Card>
  );
}
