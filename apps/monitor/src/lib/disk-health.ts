import type { SmartState, StorageDisk } from '@/api/types';

/**
 * How one disk of `storage_disks[]` reads on the page (agent 0.1.7).
 *
 * Two rules run through every function here:
 *   - null is "not measured" and is said in words. It never becomes 0: a disk
 *     at "0 °C" or "0 % worn" is a claim, and nobody made it.
 *   - the limits mirror the server (bk_storage_alert_eval, rule
 *     disk_temp_warm). If the page coloured 67 °C differently from the Monday
 *     e-mail, one of them would be lying.
 */
type TranslateFn = (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;

export type HealthTone = 'up' | 'warning' | 'down' | 'muted';

export interface DiskVerdict {
  tone: HealthTone;
  /** Dictionary key of the sentence; `storage.smart_*` take `{ago}` or `{cmd}` where they name one. */
  key: string;
}

const num = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/**
 * Every state except `ok` and `failing` means the router could NOT read the
 * disk now, so none of them may ever be green. Muted = nothing is wrong with
 * the collection (asleep, first read pending, no SMART on this storage);
 * warning = the collection itself needs attention.
 */
const STATE_VERDICT: Record<SmartState, DiskVerdict> = {
  ok: { tone: 'up', key: 'storage.smart_ok' },
  failing: { tone: 'down', key: 'storage.smart_failing' },
  standby: { tone: 'muted', key: 'storage.smart_standby' },
  idle_skipped: { tone: 'muted', key: 'storage.smart_idle_skipped' },
  pending: { tone: 'muted', key: 'storage.smart_pending' },
  not_installed: { tone: 'warning', key: 'storage.smart_not_installed' },
  unsupported: { tone: 'muted', key: 'storage.smart_unsupported' },
  error: { tone: 'warning', key: 'storage.smart_error' },
  stuck: { tone: 'warning', key: 'storage.smart_stuck' },
  not_applicable: { tone: 'muted', key: 'storage.smart_not_applicable' },
};

/** States whose values are those of the PREVIOUS reading. */
const KEEPS_LAST_READING: ReadonlySet<SmartState> = new Set(['standby', 'error', 'stuck']);

export function diskVerdict(disk: StorageDisk): DiskVerdict {
  const smart = disk.smart;
  const verdict = smart ? STATE_VERDICT[smart.state] : undefined;
  if (!smart || !verdict) return { tone: 'muted', key: 'storage.not_measured' };

  if (KEEPS_LAST_READING.has(smart.state)) {
    // A disk that failed its last reading has not recovered by falling asleep.
    if (smart.passed === false) return STATE_VERDICT.failing;
    // "Values are from {ago}" would point at a reading that never happened.
    if (smart.checked_at == null) {
      if (smart.state === 'standby') return { tone: 'muted', key: 'storage.smart_standby_never' };
      if (smart.state === 'error') return { tone: 'warning', key: 'storage.smart_error_never' };
    }
  }
  return verdict;
}

/** true = spinning, false = SSD or flash, null = the router could not tell. Never guessed from the model. */
function isSpinning(disk: StorageDisk): boolean | null {
  const rpm = num(disk.smart?.rotation_rpm);
  if (rpm !== null) return rpm > 0;
  return typeof disk.rotational === 'boolean' ? disk.rotational : null;
}

/**
 * Temperature limit of the disk's class: 60 °C spinning, 80 °C NVMe, 70 °C
 * everything else - the same three numbers as the server's disk_temp_critical.
 */
export function tempClassLimit(disk: StorageDisk): 60 | 70 | 80 {
  if (isSpinning(disk) === true) return 60;
  if (disk.transport === 'nvme' || disk.smart?.protocol === 'NVMe') return 80;
  return 70;
}

/**
 * Down at the limit, warning within 10 °C of it (where the weekly rule
 * disk_temp_warm starts), muted when the disk did not say.
 */
export function tempTone(disk: StorageDisk): HealthTone {
  const temp = num(disk.smart?.temperature_c);
  if (temp === null) return 'muted';
  const limit = tempClassLimit(disk);
  // Bit 1 of an NVMe critical warning: the drive itself says it is over its
  // threshold. The bit is tested only on a warning the drive actually sent.
  const criticalWarning = num(disk.smart?.critical_warning);
  const driveSaysHot = criticalWarning !== null && (criticalWarning & 0x02) !== 0;
  if (temp >= limit || driveSaysHot) return 'down';
  return temp >= limit - 10 ? 'warning' : 'up';
}

/** `67 °C`, or "not measured" - never "0 °C" for a disk that reported nothing. */
export function tempLabel(disk: StorageDisk, t: TranslateFn): string {
  const temp = num(disk.smart?.temperature_c);
  return temp === null ? t('storage.not_measured', 'neměřeno') : `${temp} °C`;
}

/**
 * Wear as percent of the rated life used. A vendor attribute is an estimate
 * and says so; a missing value names the reason when the reason is known.
 */
export function wearLabel(disk: StorageDisk, t: TranslateFn): string {
  const smart = disk.smart;
  const wear = num(smart?.wear_pct);
  if (wear === null) {
    // Without the drive database smartctl cannot name the vendor attributes,
    // so the wear is unreadable - which is fixable, and different from a disk
    // that has no such attribute.
    return smart?.in_drivedb === false
      ? t('storage.wear_unknown_nodb', 'neznámé – chybí smartmontools-drivedb')
      : t('storage.wear_unknown', 'neznámé');
  }
  const fromVendorAttribute = typeof smart?.wear_source === 'string' && smart.wear_source.startsWith('attr');
  return fromVendorAttribute ? `${wear} % (${t('storage.wear_estimate', 'odhad z atributu výrobce')})` : `${wear} %`;
}

/**
 * A JEDEC eMMC life code as the range it stands for: 1 = 0–10 %, 2 = 10–20 %,
 * ... 10 = 90–100 %, 11 = beyond the rated life. The card reports tenths, so a
 * single number would claim a precision it does not have.
 */
export function emmcRange(code: number | null | undefined, t: TranslateFn): string {
  const value = num(code);
  if (value === null || !Number.isInteger(value) || value < 1 || value > 11) {
    return t('storage.not_measured', 'neměřeno');
  }
  if (value === 11) return '> 100 %';
  return `${(value - 1) * 10}–${value * 10} %`;
}

/**
 * Share of the power cycles that ended without a clean shutdown (0-1).
 * Null when either counter is missing, and when they contradict each other:
 * more unclean shutdowns than power cycles is not a share of anything.
 */
export function uncleanShare(disk: StorageDisk): number | null {
  const cycles = num(disk.smart?.power_cycles);
  const unclean = num(disk.smart?.unsafe_shutdowns);
  if (cycles === null || unclean === null || cycles <= 0 || unclean < 0 || unclean > cycles) return null;
  return unclean / cycles;
}

/** Lifetime bytes written per day of operation; null when either figure is missing. */
export function writtenPerDay(disk: StorageDisk): number | null {
  const written = num(disk.smart?.written_bytes);
  const hours = num(disk.smart?.power_on_hours);
  if (written === null || hours === null || written < 0 || hours <= 0) return null;
  return (written / hours) * 24;
}

/**
 * What to type on the router to get a missing package. Without a known
 * package manager there is no command to copy, only the package's name.
 */
export function installCommand(
  pkgManager: 'opkg' | 'apk' | string | null | undefined,
  pkg: string,
  t: TranslateFn
): string {
  if (pkgManager === 'opkg') return `opkg update && opkg install ${pkg}`;
  if (pkgManager === 'apk') return `apk add ${pkg}`;
  return t('storage.install_package', { pkg }, `balíček ${pkg}`);
}

/** How long ago the reading the values come from was taken; null when there is none. */
function readingAge(disk: StorageDisk, nowSecs: number, t: TranslateFn): string | null {
  const checkedAt = num(disk.smart?.checked_at);
  if (checkedAt === null || checkedAt <= 0) return null;
  const secs = Math.max(0, nowSecs - checkedAt);
  if (secs < 120) return t('net.just_now', 'před chvílí');
  if (secs < 7200) return `${Math.round(secs / 60)} min`;
  if (secs < 172800) return `${Math.round(secs / 3600)} h`;
  return `${Math.round(secs / 86400)} d`;
}

/**
 * `diskVerdict`'s sentence with the values it asks for filled in: `{ago}` of
 * the reading the numbers come from, `{cmd}` for the one sentence that names
 * an install command. The same sentence on the Storage card and on the
 * Services tile, so the two can never disagree.
 */
export function verdictSentence(
  disk: StorageDisk,
  pkgManager: 'opkg' | 'apk' | string | null | undefined,
  nowSecs: number,
  t: TranslateFn
): { verdict: DiskVerdict; text: string } {
  const verdict = diskVerdict(disk);
  const params: Record<string, string | number> = {};
  const age = readingAge(disk, nowSecs, t);
  if (age) params.ago = age;
  if (verdict.key === 'storage.smart_not_installed') {
    params.cmd = installCommand(pkgManager, 'smartmontools', t);
  }
  return { verdict, text: t(verdict.key, params, verdict.key) };
}

/**
 * The disk that needs attention first. A failure outranks a warning, a state
 * nobody could read outranks a healthy one - a router with one unreadable
 * disk is not "all good".
 */
const TONE_RANK: Record<HealthTone, number> = { down: 0, warning: 1, muted: 2, up: 3 };

export function worstDisk(disks: StorageDisk[]): StorageDisk | null {
  let worst: { disk: StorageDisk; rank: number } | null = null;
  for (const disk of disks) {
    const rank = TONE_RANK[diskVerdict(disk).tone];
    if (!worst || rank < worst.rank) worst = { disk, rank };
  }
  return worst ? worst.disk : null;
}
