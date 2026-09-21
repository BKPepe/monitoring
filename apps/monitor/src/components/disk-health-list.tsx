import * as React from 'react';
import { HardDrive } from 'lucide-react';
import { Sparkline, type SparklineTone } from '@/components/sparkline';
import { StatBlock } from '@/components/stat-block';
import { EmptyState, ErrorState, LoadingState } from '@/components/ui/states';
import { appApi } from '@/api/app-api';
import type {
  AgentTools,
  StorageDisk,
  StorageDiskSmart,
  StorageHistoryDay,
  StorageHistoryDisk,
  StorageHistoryResponse,
} from '@/api/types';
import { useLanguage } from '@/context/language-context';
import {
  emmcRange,
  tempClassLimit,
  tempLabel,
  tempTone,
  uncleanShare,
  verdictSentence,
  wearLabel,
  writtenPerDay,
  type HealthTone,
} from '@/lib/disk-health';
import { cn } from '@/lib/utils';

/**
 * The physical disks of a router, one block each: what SMART says, how warm
 * and how worn the disk is, what has been written to it and what it carries.
 *
 * Every sentence about a missing value names its reason. A disk that is
 * asleep, that has no SMART, or whose first reading is still running is not
 * an error and is not drawn as one - but it is never drawn as healthy either,
 * because nobody checked.
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

const TONE_TEXT: Record<HealthTone, string> = {
  up: 'text-up',
  warning: 'text-warning',
  down: 'text-down',
  muted: 'text-muted-foreground',
};

const TONE_PILL: Record<HealthTone, string> = {
  up: 'border-up/30 bg-up/10 text-up',
  warning: 'border-warning/30 bg-warning/10 text-warning',
  down: 'border-down/30 bg-down/10 text-down',
  muted: 'border-border bg-muted text-muted-foreground',
};

const num = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/** Bytes as a readable unit; null stays a dash, never a zero. */
function human(bytes: number | null): string {
  if (bytes === null) return '—';
  const units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value.toFixed(value >= 100 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

/** "3 d", "5 h", "12 min" - how long ago the reading the values come from was taken. */
function ago(checkedAt: number | null, nowSecs: number, t: TranslateFn): string | null {
  if (checkedAt === null || checkedAt <= 0) return null;
  const secs = Math.max(0, nowSecs - checkedAt);
  if (secs < 120) return t('net.just_now', 'před chvílí');
  if (secs < 7200) return `${Math.round(secs / 60)} min`;
  if (secs < 172800) return `${Math.round(secs / 3600)} h`;
  return `${Math.round(secs / 86400)} d`;
}

type HistoryState =
  { status: 'idle' } | { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: StorageHistoryResponse };

/**
 * The 90-day history is only fetched when a reader opens one of the disk
 * disclosures: it is a second request per router and most visits never look
 * at it. One answer serves every disk, because the response carries them all.
 */
function useStorageHistory(monitorId: number | null): { state: HistoryState; load: () => void } {
  const [state, setState] = React.useState<HistoryState>({ status: 'idle' });
  const asked = React.useRef(false);

  const load = React.useCallback(() => {
    if (monitorId == null || asked.current) return;
    asked.current = true;
    setState({ status: 'loading' });
    appApi
      .getStorageHistory(monitorId, 90)
      .then((data) => {
        // A 200 that is not the documented shape is a failure, not an empty history.
        setState(data && Array.isArray(data.disks) ? { status: 'ready', data } : { status: 'error' });
      })
      .catch(() => setState({ status: 'error' }));
  }, [monitorId]);

  return { state, load };
}

/** Days of this disk, newest first, as `storage_history` returns them. */
function historyOf(state: HistoryState, disk: StorageDisk): StorageHistoryDisk | null {
  if (state.status !== 'ready' || !disk.key) return null;
  return state.data.disks.find((d) => d.key === disk.key) ?? null;
}

/**
 * The disk's days, newest first. The API does not promise an order, and
 * reading a counter's "latest" value off the wrong end would invent history.
 */
function sortedDays(history: StorageHistoryDisk | null): StorageHistoryDay[] {
  return [...(history?.daily ?? [])].sort((a, b) => b.day.localeCompare(a.day));
}

/**
 * For how many whole days the counter has held this value. Null while the
 * history is not loaded, and null for a counter that did move - "unchanged
 * for 0 days" would be a strange way of saying "it grew today".
 */
function unchangedDays(days: StorageHistoryDay[], pick: (d: StorageHistoryDay) => number | null): number | null {
  if (days.length === 0) return null;
  const latest = pick(days[0]);
  if (latest === null) return null;
  let held = 0;
  for (const day of days) {
    if (pick(day) !== latest) break;
    held++;
  }
  return held > 1 ? held : null;
}

/**
 * The error counters, in the order they matter. Each one is shown only when
 * the disk reported it: an ATA disk has no media errors, an NVMe no
 * reallocated sectors, and a dash in a row of zeroes reads as a zero.
 */
const COUNTERS: {
  key: string;
  fallback: string;
  pick: (smart: StorageDiskSmart) => number | null;
  /** Same counter in the daily history, for "unchanged for {n} days". */
  history?: (day: StorageHistoryDay) => number | null;
  /** A percentage left, not a count: less is worse and zero is not "clean". */
  percent?: boolean;
}[] = [
  {
    key: 'storage.reallocated',
    fallback: 'Přemapované sektory',
    pick: (s) => num(s.reallocated_sectors),
    history: (d) => d.reallocated,
  },
  {
    key: 'storage.pending',
    fallback: 'Čekající sektory',
    pick: (s) => num(s.pending_sectors),
    history: (d) => d.pending,
  },
  {
    key: 'storage.uncorrectable',
    fallback: 'Neopravitelné sektory',
    pick: (s) => num(s.offline_uncorrectable),
    history: (d) => d.offlineUncorrectable,
  },
  {
    key: 'storage.reported_uncorrect',
    fallback: 'Neopravitelné chyby čtení',
    pick: (s) => num(s.reported_uncorrect),
    history: (d) => d.reportedUncorrect,
  },
  {
    key: 'storage.crc_errors',
    fallback: 'Chyby přenosu (CRC)',
    pick: (s) => num(s.crc_errors),
    history: (d) => d.crcErrors,
  },
  {
    key: 'storage.runtime_bad_blocks',
    fallback: 'Vadné bloky za běhu',
    pick: (s) => num(s.runtime_bad_blocks),
    history: (d) => d.runtimeBadBlocks,
  },
  {
    key: 'storage.error_log',
    fallback: 'Záznamy v protokolu chyb',
    pick: (s) => num(s.error_log_count),
    history: (d) => d.errorLogCount,
  },
  {
    key: 'storage.media_errors',
    fallback: 'Chyby média',
    pick: (s) => num(s.media_errors),
    history: (d) => d.mediaErrors,
  },
  { key: 'storage.spare', fallback: 'Rezervní kapacita', pick: (s) => num(s.available_spare_pct), percent: true },
];

const TRANSPORT_KEY: Record<string, { key: string; fallback: string }> = {
  sata: { key: 'storage.transport_sata', fallback: 'SATA/mSATA' },
  usb: { key: 'storage.transport_usb', fallback: 'USB' },
  nvme: { key: 'storage.transport_nvme', fallback: 'NVMe' },
  emmc: { key: 'storage.transport_emmc', fallback: 'eMMC' },
  sd: { key: 'storage.transport_sd', fallback: 'SD karta' },
  virtio: { key: 'storage.transport_virtio', fallback: 'Virtuální' },
  other: { key: 'storage.transport_other', fallback: 'Jiné' },
};

/** Header line: what the disk is, and the one-sentence verdict about it. */
function DiskHeading({ disk, tools }: { disk: StorageDisk; tools: AgentTools | null }) {
  const { t } = useLanguage();
  const [nowSecs] = React.useState(() => Math.floor(Date.now() / 1000));
  const { verdict, text } = verdictSentence(disk, tools?.pkg_manager ?? null, nowSecs, t);
  const smart = disk.smart ?? null;

  const transport = disk.transport ? TRANSPORT_KEY[disk.transport] : undefined;
  const spinning = num(smart?.rotation_rpm) !== null ? num(smart?.rotation_rpm)! > 0 : disk.rotational;
  const size = num(disk.size_bytes);

  return (
    <div className="space-y-1">
      <div className="flex flex-wrap items-center gap-2">
        <HardDrive aria-hidden="true" className="text-muted-foreground size-4" />
        <span className="text-sm font-semibold">{smart?.model || disk.model || disk.name}</span>
        <span className="text-muted-foreground font-mono text-2xs">{disk.name}</span>
        {transport && (
          <span className="border-border bg-muted text-muted-foreground rounded border px-1.5 py-0.5 text-3xs">
            {t(transport.key, transport.fallback)}
          </span>
        )}
        {size !== null && <span className="text-muted-foreground text-2xs">{human(size)}</span>}
        {typeof spinning === 'boolean' && (
          <span className="text-muted-foreground text-2xs">
            {spinning ? t('storage.kind_hdd', 'HDD (rotační)') : t('storage.kind_ssd', 'SSD')}
          </span>
        )}
      </div>
      <p className={cn('inline-block rounded border px-2 py-0.5 text-2xs', TONE_PILL[verdict.tone])}>{text}</p>
    </div>
  );
}

/** Temperature, wear, how long it has run and how often it was switched off. */
function DiskStats({ disk }: { disk: StorageDisk }) {
  const { t } = useLanguage();
  const smart = disk.smart ?? null;
  const hours = num(smart?.power_on_hours);
  const cycles = num(smart?.power_cycles);
  const unclean = num(smart?.unsafe_shutdowns);
  const share = uncleanShare(disk);

  return (
    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
      <StatBlock
        label={t('storage.temperature', 'Teplota')}
        value={<span className={TONE_TEXT[tempTone(disk)]}>{tempLabel(disk, t)}</span>}
        hint={t('storage.temp_limit', { limit: tempClassLimit(disk) }, `limit ${tempClassLimit(disk)} °C`)}
      />
      <StatBlock
        label={t('storage.wear', 'Opotřebení')}
        value={<span className="text-xl">{wearLabel(disk, t)}</span>}
      />
      <StatBlock
        label={t('storage.power_on', 'Doba provozu')}
        value={
          hours === null ? null : (
            <span className="text-xl">
              {t(
                'storage.power_on_value',
                { days: Math.floor(hours / 24), hours },
                `${Math.floor(hours / 24)} dní (${hours} h)`
              )}
            </span>
          )
        }
      />
      <StatBlock
        label={t('storage.power_cycles', 'Zapnutí')}
        value={cycles}
        hint={
          unclean === null ? undefined : (
            // One unclean power-off in four is not a disk fault, but it is the
            // reason the wear and the error counters look the way they do.
            <span className={share !== null && share >= 0.2 ? 'text-warning' : undefined}>
              {t('storage.unclean_of', { n: unclean }, `z toho ${unclean} nečistých vypnutí`)}
            </span>
          )
        }
      />
    </div>
  );
}

/** The error counters the disk reports, with how long each has stood still. */
function DiskCounters({ disk, days }: { disk: StorageDisk; days: StorageHistoryDay[] }) {
  const { t } = useLanguage();
  const smart = disk.smart;
  if (!smart) return null;

  const rows = COUNTERS.map((counter) => ({ counter, value: counter.pick(smart) })).filter((row) => row.value !== null);
  if (rows.length === 0) return null;

  return (
    <ul className="grid gap-x-4 text-xs sm:grid-cols-2">
      {rows.map(({ counter, value }) => {
        const held = counter.history ? unchangedDays(days, counter.history) : null;
        // A count that is not zero is worth a colour; a spare capacity is the
        // other way round and is left alone unless the drive is running out.
        const bad = counter.percent ? (value as number) < 20 : (value as number) > 0;
        return (
          <li key={counter.key} className="border-border/40 flex items-baseline justify-between gap-3 border-b py-1">
            <span className="text-muted-foreground">{t(counter.key, counter.fallback)}</span>
            <span className="text-right">
              <span className={cn('font-mono font-medium tabular-nums', bad && 'text-warning')}>
                {counter.percent ? `${value} %` : value}
              </span>
              {held !== null && value !== 0 && (
                <span className="text-muted-foreground ml-2 text-2xs">
                  {t('storage.unchanged_days', { n: held }, `beze změny ${held} dní`)}
                </span>
              )}
            </span>
          </li>
        );
      })}
    </ul>
  );
}

/** Bytes written per day over the last week; null while the history is not loaded. */
function weeklyWrite(days: StorageHistoryDay[]): { perDay: number; partial: boolean } | null {
  const week = days.slice(0, 7).filter((d) => d.hostWrittenBytes !== null);
  if (week.length === 0) return null;
  const total = week.reduce((sum, d) => sum + (d.hostWrittenBytes ?? 0), 0);
  return { perDay: total / week.length, partial: week.some((d) => d.hostWrittenPartial) };
}

/** What has been written to the disk: over its life, per day, this week, right now. */
function DiskWrites({
  disk,
  days,
  writeKbps,
}: {
  disk: StorageDisk;
  days: StorageHistoryDay[];
  /** Current write rate of the same device from `disk_devices`; null = not measured. */
  writeKbps: number | null;
}) {
  const { t } = useLanguage();
  const written = num(disk.smart?.written_bytes);
  const perDay = writtenPerDay(disk);
  const week = weeklyWrite(days);
  if (written === null && perDay === null && week === null && writeKbps === null) return null;

  return (
    <div className="space-y-0.5 text-xs">
      {written !== null && (
        <p>
          <span className="text-muted-foreground">{t('storage.written_total', 'Zapsáno za život disku')}: </span>
          <span className="font-mono font-medium">{human(written)}</span>
          {disk.smart?.written_source === 'attr241' && (
            <span className="text-muted-foreground ml-1 text-2xs">
              {t('storage.written_source_attr', '(hlásí disk, atribut 241)')}
            </span>
          )}
        </p>
      )}
      {perDay !== null && (
        <p className="text-muted-foreground">
          {t('storage.written_per_day', { x: human(perDay) }, `průměrně ${human(perDay)} denně za celou dobu provozu`)}
        </p>
      )}
      {week && (
        <p className="text-muted-foreground">
          {t('storage.written_7d', { x: human(week.perDay) }, `za 7 dní průměrně ${human(week.perDay)} denně`)}
          {/* A router restart loses the day's counter, so that day is an
              understatement - said out loud instead of quietly averaged in. */}
          {week.partial && <span className="ml-1">· {t('storage.partial_day', 'den neúplný (restart routeru)')}</span>}
        </p>
      )}
      {writeKbps !== null && (
        <p className="text-muted-foreground">
          {t('storage.written_now', 'Zápis teď')}: <span className="font-mono">{human(writeKbps * 1024)}/s</span>
        </p>
      )}
    </div>
  );
}

/** The partitions of this disk, with how full the mounted ones are. */
function DiskPartitions({ disk }: { disk: StorageDisk }) {
  const { t } = useLanguage();
  const partitions = Array.isArray(disk.partitions) ? disk.partitions : [];
  if (partitions.length === 0) return null;

  return (
    <ul className="space-y-1.5">
      {partitions.map((part) => {
        const used = num(part.used_pct);
        return (
          <li key={part.name} className="space-y-1">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 text-xs">
              <span className="font-mono font-semibold">{part.mount || part.name}</span>
              <span className="text-muted-foreground">
                {human(num(part.size_bytes))}
                {part.fstype ? ` · ${part.fstype}` : ''}
                {used !== null ? (
                  <span className="ml-2 tabular-nums">{used} %</span>
                ) : (
                  <span className="ml-2">{t('storage.partition_unmounted', 'nepřipojený')}</span>
                )}
              </span>
            </div>
            {used !== null && (
              <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
                <div
                  className={cn(
                    'h-full rounded-full',
                    used >= 90 ? 'bg-down' : used >= 75 ? 'bg-warning' : 'bg-primary'
                  )}
                  style={{ width: `${Math.min(100, Math.max(0, used))}%` }}
                />
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

/** The two JEDEC life codes and the reserve-block estimate of an eMMC. */
function EmmcLine({ disk }: { disk: StorageDisk }) {
  const { t } = useLanguage();
  const emmc = disk.emmc;
  if (!emmc) return null;

  const preEol = num(emmc.pre_eol);
  const eol =
    preEol === 1
      ? t('storage.emmc_pre_eol_1', 'v normě')
      : preEol === 2
        ? t('storage.emmc_pre_eol_2', 'z 80 % spotřebované')
        : preEol === 3
          ? t('storage.emmc_pre_eol_3', 'téměř vyčerpané')
          : t('storage.not_measured', 'neměřeno');

  const a = emmcRange(emmc.life_a, t);
  const b = emmcRange(emmc.life_b, t);
  return (
    <p className={cn('text-xs', preEol !== null && preEol >= 2 ? 'text-warning' : 'text-muted-foreground')}>
      {t('storage.emmc_wear', { a, b, eol }, `Opotřebení eMMC: ${a} (typ A), ${b} (typ B) · rezervní bloky ${eol}`)}
    </p>
  );
}

/** Sparklines of the three numbers that only mean something over months. */
function DiskHistory({ days }: { days: StorageHistoryDay[] }) {
  const { t } = useLanguage();
  // Oldest first: a sparkline reads left to right like every other chart here.
  const oldestFirst = [...days].reverse();
  const all: { label: string; data: (number | null)[]; tone: SparklineTone }[] = [
    { label: t('storage.temperature', 'Teplota'), data: oldestFirst.map((d) => d.tempMax), tone: 'temperature' },
    {
      label: t('storage.written_total', 'Zapsáno za život disku'),
      data: oldestFirst.map((d) => (d.hostWrittenBytes === null ? null : d.hostWrittenBytes / 1073741824)),
      tone: 'disk',
    },
    { label: t('storage.wear', 'Opotřebení'), data: oldestFirst.map((d) => d.wearPct), tone: 'disk' },
  ];
  // A series that measured nothing at all is left out; an empty chart would
  // read as a flat line at zero.
  const series = all.filter((s) => s.data.some((v) => v !== null));

  if (series.length === 0) return null;
  return (
    <div className="grid gap-3 sm:grid-cols-3">
      {series.map((s) => (
        <div key={s.label}>
          <p className="text-muted-foreground text-2xs">{s.label}</p>
          <Sparkline data={s.data} tone={s.tone} />
        </div>
      ))}
    </div>
  );
}

/** When SMART was last read and whether the disk has ever tested itself. */
function DiskFooter({ disk }: { disk: StorageDisk }) {
  const { t } = useLanguage();
  const [nowSecs] = React.useState(() => Math.floor(Date.now() / 1000));
  const readAgo = ago(num(disk.smart?.checked_at), nowSecs, t);
  const selftests = num(disk.smart?.selftest_count);

  return (
    <div className="text-muted-foreground space-y-0.5 text-2xs">
      {readAgo && <p>{t('storage.smart_age', { ago: readAgo }, `SMART změřen ${readAgo}`)}</p>}
      {selftests !== null && (
        <p>
          {selftests === 0
            ? t('storage.selftest_never', 'Vlastní test disku se nikdy nespustil')
            : t('storage.selftest_count', { n: selftests }, `Vlastní testy v protokolu: ${selftests}`)}
        </p>
      )}
    </div>
  );
}

/**
 * @param disks `storage_disks` exactly as `last_details` carries it: an array,
 *   null when the router could not read the list, undefined when the agent is
 *   too old to look. The three mean different things and are said differently.
 */
export function DiskHealthList({
  disks,
  tools,
  writeRates,
  monitorId,
}: {
  disks: StorageDisk[] | null | undefined;
  tools?: AgentTools | null;
  /** Current write rate per device name, from `disk_devices`. */
  writeRates?: Record<string, number | null>;
  /** null = the history is not offered (not a router, or no id to ask with). */
  monitorId?: number | null;
}) {
  const { t } = useLanguage();
  const { state, load } = useStorageHistory(monitorId ?? null);

  if (disks === undefined) {
    return (
      <EmptyState size="inline" title={t('storage.agent_outdated', 'Zdraví disků posílá agent 0.1.7 a novější.')} />
    );
  }
  if (disks === null) {
    return (
      <ErrorState
        size="inline"
        tone="warning"
        message={t('storage.disks_unreadable', 'Seznam disků se na routeru nepodařilo přečíst.')}
      />
    );
  }
  if (disks.length === 0) {
    return <EmptyState size="inline" title={t('storage.no_disks', 'Router nehlásí žádný disk.')} />;
  }

  return (
    <div className="space-y-4">
      <p className="text-muted-foreground text-xs font-medium">{t('storage.disks', 'Disky')}</p>
      {disks.map((disk) => {
        const history = historyOf(state, disk);
        const days = sortedDays(history);
        return (
          <div key={disk.key || disk.name} className="border-border space-y-3 rounded-lg border p-3">
            <DiskHeading disk={disk} tools={tools ?? null} />
            <DiskStats disk={disk} />
            <EmmcLine disk={disk} />
            <DiskCounters disk={disk} days={days} />
            <DiskWrites disk={disk} days={days} writeKbps={writeRates?.[disk.name] ?? null} />
            <DiskPartitions disk={disk} />
            <DiskFooter disk={disk} />
            {monitorId != null && (
              <details onToggle={load}>
                <summary className="text-muted-foreground hover:text-foreground cursor-pointer text-xs font-medium">
                  {t('storage.history', 'Historie disku')}
                </summary>
                <div className="mt-2">
                  {state.status === 'loading' && (
                    <LoadingState size="inline" label={t('net.link_loading', 'Načítám…')} />
                  )}
                  {state.status === 'error' && (
                    <ErrorState
                      size="inline"
                      message={t('storage.history_error', 'Historii disku se nepodařilo načíst.')}
                    />
                  )}
                  {state.status === 'ready' && <DiskHistory days={days} />}
                </div>
              </details>
            )}
          </div>
        );
      })}
    </div>
  );
}
