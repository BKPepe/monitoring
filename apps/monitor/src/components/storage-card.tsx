import { Card } from '@/components/ui/card';
import { HardDrive, ArrowDownToLine, ArrowUpFromLine, Pencil, Info } from 'lucide-react';
import { useLanguage } from '@/context/language-context';
import { MetricHelpIcon } from '@/components/metric-help-icon';
import { cn } from '@/lib/utils';

interface Filesystem {
  mount: string;
  device: string;
  fstype: string;
  total_kb: number;
  used_kb: number;
  avail_kb: number;
  used_pct: number;
}

interface DiskDevice {
  device: string;
  read_kbps: number | null;
  write_kbps: number | null;
  read_sectors_total: number;
  write_sectors_total: number;
}

interface IoProcess {
  pid: number;
  name: string;
  write_bytes: number;
}

/** Bytes to a readable unit. NULL stays a dash, not a zero. */
function human(bytes: number | null | undefined): string {
  if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) return '—';
  const units = ['B', 'kB', 'MB', 'GB', 'TB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value.toFixed(value >= 100 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function rate(kbps: number | null | undefined): string {
  if (kbps === null || kbps === undefined || !Number.isFinite(kbps)) return '—';
  return `${human(kbps * 1024)}/s`;
}

/**
 * Device storage: all filesystems, per-disk traffic and the processes
 * writing the most.
 *
 * A single number per router used to be shown - the / or /overlay usage.
 * A plugged-in USB disk or a second partition was invisible.
 */
export function StorageCard({ d }: { d: Record<string, unknown> }) {
  const { t } = useLanguage();

  const filesystems = Array.isArray(d.filesystems) ? (d.filesystems as Filesystem[]) : [];
  const devices = Array.isArray(d.disk_devices) ? (d.disk_devices as DiskDevice[]) : [];
  const writers = Array.isArray(d.top_io_processes) ? (d.top_io_processes as IoProcess[]) : [];

  // `io_accounting: false` means "the kernel cannot do this" - different
  // information from "nothing arrived yet", and the user deserves to know
  // which one they are looking at.
  const ioAccounting = d.io_accounting;
  const ioUnsupported = ioAccounting === false;

  if (filesystems.length === 0 && devices.length === 0 && writers.length === 0 && !ioUnsupported) {
    return null;
  }

  const barColor = (pct: number) => (pct >= 90 ? 'bg-down' : pct >= 75 ? 'bg-warning' : 'bg-primary');

  return (
    <Card className="space-y-5 p-6">
      <div className="flex flex-wrap items-center gap-2 border-b border-border pb-3">
        <HardDrive className="size-5 text-primary" />
        <h3 className="text-base font-bold">{t('storage.title', 'Úložiště')}</h3>
        <MetricHelpIcon metric="hdd" />
      </div>

      {filesystems.length > 0 && (
        <div className="space-y-2.5">
          <p className="text-muted-foreground text-xs font-medium">{t('storage.filesystems', 'Připojené oddíly')}</p>
          {filesystems.map((fs) => (
            <div key={fs.mount} className="space-y-1">
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 text-xs">
                <span className="font-mono font-semibold">{fs.mount}</span>
                <span className="text-muted-foreground">
                  {human(fs.used_kb * 1024)} / {human(fs.total_kb * 1024)}
                  <span className="ml-2 tabular-nums">{fs.used_pct} %</span>
                </span>
              </div>
              <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                <div
                  className={cn('h-full rounded-full', barColor(fs.used_pct))}
                  style={{ width: `${Math.min(100, Math.max(0, fs.used_pct))}%` }}
                />
              </div>
              <p className="text-muted-foreground font-mono text-[10px]">
                {fs.device}
                {fs.fstype ? ` · ${fs.fstype}` : ''}
              </p>
            </div>
          ))}
        </div>
      )}

      {devices.length > 0 && (
        <div className="space-y-2">
          <p className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium">
            {t('storage.devices', 'Provoz po discích')}
            <MetricHelpIcon metric="disk_io_write" />
          </p>
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="text-muted-foreground border-b border-border">
                <tr>
                  <th className="py-1.5 pr-3 font-medium">{t('storage.device', 'Zařízení')}</th>
                  <th className="py-1.5 pr-3 font-medium">
                    <ArrowDownToLine className="mr-1 inline size-3" />
                    {t('storage.read', 'Čtení')}
                  </th>
                  <th className="py-1.5 font-medium">
                    <ArrowUpFromLine className="mr-1 inline size-3" />
                    {t('storage.write', 'Zápis')}
                  </th>
                </tr>
              </thead>
              <tbody>
                {devices.map((dev) => (
                  <tr key={dev.device} className="border-b border-border/50 last:border-0">
                    <td className="py-1.5 pr-3 font-mono">{dev.device}</td>
                    {/* A dash means "first measurement after boot or after a device
                        restart" - a rate cannot be computed from a single reading. */}
                    <td className="py-1.5 pr-3 tabular-nums">{rate(dev.read_kbps)}</td>
                    <td className="py-1.5 tabular-nums">{rate(dev.write_kbps)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {writers.length === 0 && ioUnsupported && (
        <div className="space-y-1.5">
          <p className="text-muted-foreground text-xs font-medium">
            {t('storage.top_writers', 'Nejvíc zapisující procesy')}
          </p>
          {/* The missing-data explanation used to be small grey text indistinguishable
              from the labels around it, so it was overlooked and the section looked
              empty. As an info notice it is clear nothing is missing here -
              this device simply cannot do it. */}
          <p className="flex items-start gap-2 rounded-lg border border-info/30 bg-info/10 p-2.5 text-[11px] leading-relaxed text-info">
            <Info className="mt-px size-3.5 shrink-0" />
            <span>
              {t(
                'storage.io_unsupported',
                'Jádro tohoto zařízení neúčtuje zápisy po procesech (chybí CONFIG_TASK_IO_ACCOUNTING), takže tenhle údaj nejde získat. Celkový zápis na disky výše měřit lze.'
              )}
            </span>
          </p>
        </div>
      )}

      {writers.length > 0 && (
        <div className="space-y-2">
          <p className="text-muted-foreground text-xs font-medium">
            {t('storage.top_writers', 'Nejvíc zapisující procesy')}
          </p>
          <ul className="space-y-1">
            {writers.map((p) => (
              <li key={p.pid} className="flex items-baseline justify-between gap-3 text-xs">
                <span className="truncate">
                  <Pencil className="text-muted-foreground mr-1.5 inline size-3" />
                  <span className="font-medium">{p.name}</span>
                  <span className="text-muted-foreground ml-1.5 font-mono text-[10px]">#{p.pid}</span>
                </span>
                <span className="tabular-nums">{human(p.write_bytes)}</span>
              </li>
            ))}
          </ul>
          <p className="text-muted-foreground text-[11px]">
            {t('storage.writers_note', 'Součet od spuštění procesu, ne aktuální rychlost.')}
          </p>
        </div>
      )}
    </Card>
  );
}
