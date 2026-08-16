import { useState, useEffect, useCallback } from 'react';
import { Card } from '@/components/ui/card';
import { Gauge, ArrowDown, ArrowUp } from 'lucide-react';
import { useLanguage } from '@/context/language-context';

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
 * Rychlost linky měřená přímo routerem (librespeed-cli).
 *
 * Router si výsledky ukládá do /tmp, což je na OpenWrt ramdisk - po restartu
 * jsou pryč. Agent je posílá na server, takže tahle karta je jediné místo,
 * kde ta historie přežije.
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

  // Dokud se nenačte, nebo když měření nejsou, karta se nevykreslí vůbec -
  // prázdný rámeček "zatím nic" jen zabírá místo na stránce plné jiných dat.
  if (!data || !data.measurements || data.measurements.length === 0) {
    return null;
  }

  const latest = data.measurements[0];
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
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <ArrowDown className="size-3.5" /> {t('speed.download', 'Stahování')}
          </div>
          <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums">{fmt(latest.downloadMbps, 'Mb/s')}</p>
        </div>
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            <ArrowUp className="size-3.5" /> {t('speed.upload', 'Odesílání')}
          </div>
          <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums">{fmt(latest.uploadMbps, 'Mb/s')}</p>
        </div>
        <div className="rounded-lg border border-border p-3">
          <div className="text-muted-foreground text-xs font-medium">{t('speed.ping', 'Odezva')}</div>
          <p className="mt-1 text-2xl font-bold tracking-tight tabular-nums">{fmt(latest.pingMs, 'ms')}</p>
          {latest.jitterMs !== null && (
            <p className="text-muted-foreground mt-0.5 text-[11px]">
              {t('speed.jitter', { v: latest.jitterMs }, `rozptyl ${latest.jitterMs} ms`)}
            </p>
          )}
        </div>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-xs">
          <thead className="text-muted-foreground border-b border-border">
            <tr>
              <th className="py-1.5 pr-3 font-medium">{t('speed.period', 'Období')}</th>
              <th className="py-1.5 pr-3 font-medium">{t('speed.avg_down', 'Průměr ↓')}</th>
              <th className="py-1.5 pr-3 font-medium">{t('speed.avg_up', 'Průměr ↑')}</th>
              <th className="py-1.5 pr-3 font-medium">{t('speed.range', 'Rozsah ↓')}</th>
              <th className="py-1.5 font-medium">{t('speed.samples', 'Měření')}</th>
            </tr>
          </thead>
          <tbody>
            {periods.map(({ key, label }) => {
              const a = data.averages?.[key];
              if (!a) return null;
              return (
                <tr key={key} className="border-b border-border/50 last:border-0">
                  <td className="py-1.5 pr-3 font-medium">{label}</td>
                  <td className="py-1.5 pr-3 tabular-nums">{fmt(a.downloadMbps, 'Mb/s')}</td>
                  <td className="py-1.5 pr-3 tabular-nums">{fmt(a.uploadMbps, 'Mb/s')}</td>
                  <td className="text-muted-foreground py-1.5 pr-3 tabular-nums">
                    {a.downloadMinMbps === null || a.downloadMaxMbps === null
                      ? '—'
                      : `${a.downloadMinMbps} – ${a.downloadMaxMbps}`}
                  </td>
                  {/* Počet měření je podstatný: průměr z jednoho měření a
                      průměr z třiceti vypadají v tabulce stejně. */}
                  <td className="text-muted-foreground py-1.5 tabular-nums">{a.samples}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <p className="text-muted-foreground text-[11px]">
        {t('speed.last', { at: latest.measuredAt }, `Poslední měření: ${latest.measuredAt}`)}
        {latest.server ? ` · ${latest.server}` : ''}
      </p>
    </Card>
  );
}
