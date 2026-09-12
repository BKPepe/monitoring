import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { StatBlock } from '@/components/stat-block';
import { Gauge, ArrowDown, ArrowUp } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { MetricChart } from '@/components/charts/metric-chart';
import { insertGaps } from '@/lib/series-gaps';
import type { ChartData } from '@/api/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface Measurement {
  measuredAt: string;
  downloadMbps: number | null;
  uploadMbps: number | null;
  pingMs: number | null;
  jitterMs: number | null;
  server: string | null;
}

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
  measurements: Measurement[];
  averages: Record<string, Average>;
}

const fmt = (v: number | null | undefined, unit: string) => (v === null || v === undefined ? '—' : `${v} ${unit}`);

/**
 * Link speed measured by the router itself (librespeed-cli).
 *
 * The router stores results in /tmp, a ramdisk on OpenWrt - gone after a
 * reboot. The agent sends them to the server, so this card is the only place
 * where that history survives.
 */
export function SpeedtestCard({ monitorId }: { monitorId: number }) {
  const { t } = useLanguage();
  const [data, setData] = useState<SpeedtestData | null | undefined>(undefined);

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

  // Until it loads, or when there are no measurements, the card does not render
  // at all - an empty "nothing yet" frame just takes space on a page full of other data.
  if (!data || !data.measurements || data.measurements.length === 0) {
    return null;
  }

  const latest = data.measurements[0];
  // The card fetches thirty measurements and used to show one. This is the
  // only place that history survives at all - the router keeps its results in
  // a ramdisk - so it gets drawn. A failed test stays null and reads as a gap,
  // never as zero throughput.
  const ascending = [...data.measurements].reverse();
  const speedChart: ChartData | null =
    ascending.length >= 2
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
              points: insertGaps(ascending.map((m) => ({ t: Date.parse(m.measuredAt), v: m.downloadMbps }))),
            },
            {
              key: 'upload',
              label: t('speed.upload', 'Odesílání'),
              unit: 'Mb/s',
              tone: 'memory',
              points: insertGaps(ascending.map((m) => ({ t: Date.parse(m.measuredAt), v: m.uploadMbps }))),
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
        <StatBlock icon={ArrowDown} label={t('speed.download', 'Stahování')} value={fmt(latest.downloadMbps, 'Mb/s')} />
        <StatBlock icon={ArrowUp} label={t('speed.upload', 'Odesílání')} value={fmt(latest.uploadMbps, 'Mb/s')} />
        <StatBlock
          label={t('speed.ping', 'Odezva')}
          value={fmt(latest.pingMs, 'ms')}
          hint={
            latest.jitterMs !== null
              ? t('speed.jitter', { v: latest.jitterMs }, `rozptyl ${latest.jitterMs} ms`)
              : undefined
          }
        />
      </div>

      {speedChart && <MetricChart data={speedChart} height={170} />}

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
          {periods.map(({ key, label }) => {
            const a = data.averages?.[key];
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

      <p className="text-muted-foreground text-2xs">
        {t('speed.last', { at: latest.measuredAt }, `Poslední měření: ${latest.measuredAt}`)}
        {latest.server ? ` · ${latest.server}` : ''}
      </p>
    </Card>
  );
}
