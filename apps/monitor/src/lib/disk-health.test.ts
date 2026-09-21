import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import omnia from '@/api/omnia-router.fixture';
import type { SmartState, StorageDisk } from '@/api/types';
import {
  diskVerdict,
  emmcRange,
  installCommand,
  tempClassLimit,
  tempLabel,
  tempTone,
  uncleanShare,
  verdictSentence,
  wearLabel,
  worstDisk,
  writtenPerDay,
} from './disk-health';

/** Fills {placeholders} from params, so the test sees the numbers that reach the user. */
const t = (key: string, params?: Record<string, string | number> | string, fallback?: string) => {
  const text = typeof params === 'string' ? params : (fallback ?? key);
  if (!params || typeof params === 'string') return text;
  return text.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? ''));
};

const disk = omnia.diskSda;
const withSmart = (smart: Partial<NonNullable<StorageDisk['smart']>>): StorageDisk => ({
  ...disk,
  smart: { ...disk.smart!, ...smart },
});

const ALL_STATES: SmartState[] = [
  'ok',
  'failing',
  'standby',
  'idle_skipped',
  'pending',
  'not_installed',
  'unsupported',
  'error',
  'stuck',
  'not_applicable',
];

describe('diskVerdict', () => {
  it('has a tone and a sentence for every SMART state, and only "ok" is green', () => {
    const dictionary = readFileSync(join(__dirname, '../context/language-context.tsx'), 'utf8');
    for (const state of ALL_STATES) {
      for (const checked_at of [1789553203, null]) {
        const verdict = diskVerdict(withSmart({ state, checked_at }));
        expect(['up', 'warning', 'down', 'muted'], state).toContain(verdict.tone);
        expect(verdict.tone === 'up', state).toBe(state === 'ok');
        // The key is handed to t() as a variable, which the dictionary test
        // cannot see - so its existence is checked here.
        // (A boolean, so a failure names the key instead of printing the dictionary.)
        expect(dictionary.includes(`'${verdict.key}':`), verdict.key).toBe(true);
      }
    }
  });

  it('reads the Omnia disk as healthy and a failing one as down', () => {
    expect(diskVerdict(disk)).toEqual({ tone: 'up', key: 'storage.smart_ok' });
    expect(diskVerdict(withSmart({ state: 'failing', passed: false }))).toEqual({
      tone: 'down',
      key: 'storage.smart_failing',
    });
  });

  it('does not promise values "from {ago}" when there never was a reading', () => {
    expect(diskVerdict(withSmart({ state: 'standby' })).key).toBe('storage.smart_standby');
    expect(diskVerdict(withSmart({ state: 'standby', checked_at: null })).key).toBe('storage.smart_standby_never');
    expect(diskVerdict(withSmart({ state: 'error' }))).toEqual({ tone: 'warning', key: 'storage.smart_error' });
    expect(diskVerdict(withSmart({ state: 'error', checked_at: null })).key).toBe('storage.smart_error_never');
  });

  it('keeps a disk down when it fell asleep after a failed reading', () => {
    expect(diskVerdict(withSmart({ state: 'standby', passed: false })).tone).toBe('down');
    expect(diskVerdict(withSmart({ state: 'stuck', passed: false })).tone).toBe('down');
  });

  it('is muted, not green, for a disk without any SMART object or with a state it does not know', () => {
    expect(diskVerdict({ name: 'sdb' })).toEqual({ tone: 'muted', key: 'storage.not_measured' });
    expect(diskVerdict({ name: 'sdb', smart: null }).tone).toBe('muted');
    expect(diskVerdict(withSmart({ state: 'brand_new' as SmartState })).tone).toBe('muted');
    expect(diskVerdict(omnia.diskSdaPending)).toEqual({ tone: 'muted', key: 'storage.smart_pending' });
  });
});

describe('disk temperature', () => {
  it('warns about 67 °C on a SATA SSD, whose limit is 70', () => {
    expect(tempClassLimit(disk)).toBe(70);
    expect(tempTone(disk)).toBe('warning');
    expect(tempLabel(disk, t)).toBe('67 °C');
  });

  it('uses 60 °C for a spinning disk and 80 °C for NVMe, like the server', () => {
    expect(tempClassLimit(withSmart({ rotation_rpm: 5400 }))).toBe(60);
    // Without an rpm from SMART the kernel's flag decides.
    expect(tempClassLimit({ ...disk, rotational: true, smart: { state: 'not_installed' } })).toBe(60);
    // On USB the enclosure often claims "rotational"; the disk's own rpm 0 wins.
    expect(tempClassLimit({ ...withSmart({ rotation_rpm: 0 }), rotational: true })).toBe(70);
    expect(tempClassLimit({ ...disk, transport: 'nvme', smart: { state: 'ok', protocol: 'NVMe' } })).toBe(80);
  });

  it('is down at the limit, fine well below it', () => {
    expect(tempTone(withSmart({ temperature_c: 70 }))).toBe('down');
    expect(tempTone(withSmart({ temperature_c: 60 }))).toBe('warning');
    expect(tempTone(withSmart({ temperature_c: 59 }))).toBe('up');
    expect(tempTone(withSmart({ temperature_c: 55, rotation_rpm: 7200 }))).toBe('warning');
  });

  it('believes an NVMe drive that says it is too hot', () => {
    const nvme: StorageDisk = {
      ...disk,
      transport: 'nvme',
      smart: { state: 'ok', protocol: 'NVMe', temperature_c: 61 },
    };
    expect(tempTone(nvme)).toBe('up');
    expect(tempTone({ ...nvme, smart: { ...nvme.smart!, critical_warning: 0x02 } })).toBe('down');
  });

  it('says "not measured" for a null temperature, never "0 °C"', () => {
    expect(tempLabel(omnia.diskSdaPending, t)).toBe('neměřeno');
    expect(tempLabel({ name: 'sdb' }, t)).toBe('neměřeno');
    expect(tempTone(omnia.diskSdaPending)).toBe('muted');
  });
});

describe('wearLabel', () => {
  it('marks a vendor attribute as an estimate and a standard figure as a fact', () => {
    expect(wearLabel(disk, t)).toBe('0 % (odhad z atributu výrobce)');
    expect(wearLabel(withSmart({ wear_pct: 12, wear_source: 'devstat' }), t)).toBe('12 %');
    expect(wearLabel(withSmart({ wear_pct: 3, wear_source: 'nvme' }), t)).toBe('3 %');
  });

  it('names the missing drive database when that is why the wear is unknown', () => {
    expect(wearLabel(withSmart({ wear_pct: null, wear_source: null, in_drivedb: false }), t)).toBe(
      'neznámé – chybí smartmontools-drivedb'
    );
    expect(wearLabel(withSmart({ wear_pct: null, wear_source: null }), t)).toBe('neznámé');
    expect(wearLabel(omnia.diskSdaPending, t)).toBe('neznámé');
  });
});

describe('emmcRange', () => {
  it('shows a JEDEC life code as the range it stands for', () => {
    expect(emmcRange(1, t)).toBe('0–10 %');
    expect(emmcRange(2, t)).toBe('10–20 %');
    expect(emmcRange(10, t)).toBe('90–100 %');
    expect(emmcRange(11, t)).toBe('> 100 %');
  });

  it('does not turn a missing or impossible code into a range', () => {
    expect(emmcRange(null, t)).toBe('neměřeno');
    expect(emmcRange(undefined, t)).toBe('neměřeno');
    expect(emmcRange(0, t)).toBe('neměřeno');
    expect(emmcRange(12, t)).toBe('neměřeno');
    expect(emmcRange(2.5, t)).toBe('neměřeno');
  });
});

describe('uncleanShare', () => {
  it('is 227 of 230 on the Omnia', () => {
    expect(uncleanShare(disk)).toBeCloseTo(227 / 230, 6);
  });

  it('is null when a counter is missing, zero or contradicts the other', () => {
    expect(uncleanShare(withSmart({ unsafe_shutdowns: null }))).toBeNull();
    expect(uncleanShare(withSmart({ power_cycles: null }))).toBeNull();
    expect(uncleanShare(withSmart({ power_cycles: 0, unsafe_shutdowns: 0 }))).toBeNull();
    expect(uncleanShare(withSmart({ power_cycles: 10, unsafe_shutdowns: 11 }))).toBeNull();
    expect(uncleanShare(withSmart({ power_cycles: 10, unsafe_shutdowns: 0 }))).toBe(0);
  });
});

describe('writtenPerDay', () => {
  it('is about 0.41 GiB a day for 420 GiB over 24 750 hours', () => {
    const perDay = writtenPerDay(disk);
    expect(perDay).not.toBeNull();
    expect(perDay! / 1024 ** 3).toBeCloseTo(0.41, 2);
  });

  it('is null when either figure is missing, and never divides by zero hours', () => {
    expect(writtenPerDay(withSmart({ written_bytes: null }))).toBeNull();
    expect(writtenPerDay(withSmart({ power_on_hours: null }))).toBeNull();
    expect(writtenPerDay(withSmart({ power_on_hours: 0 }))).toBeNull();
    expect(writtenPerDay(omnia.diskSdaPending)).toBeNull();
  });
});

describe('installCommand', () => {
  it('writes the command of the package manager the router has', () => {
    expect(installCommand('opkg', 'smartmontools', t)).toBe('opkg update && opkg install smartmontools');
    expect(installCommand('apk', 'smartmontools', t)).toBe('apk add smartmontools');
  });

  it('names the package when the manager is unknown, instead of guessing a command', () => {
    expect(installCommand(null, 'hostapd-utils', t)).toBe('balíček hostapd-utils');
    expect(installCommand(undefined, 'iw', t)).toBe('balíček iw');
    expect(installCommand('dnf', 'iw', t)).toBe('balíček iw');
  });
});

describe('verdictSentence', () => {
  const now = 1789553203 + 3 * 86400;

  /** Records what the sentence asked the dictionary for - key and filled values. */
  const spy = () => {
    const calls: { key: string; params?: Record<string, string | number> | string }[] = [];
    const record = (key: string, params?: Record<string, string | number> | string, fallback?: string) => {
      calls.push({ key, params });
      return t(key, params, fallback);
    };
    return { calls, record };
  };

  it('fills the age of the reading the shown values come from', () => {
    const { calls, record } = spy();
    const { verdict } = verdictSentence(withSmart({ state: 'standby' }), 'opkg', now, record);
    expect(verdict.key).toBe('storage.smart_standby');
    expect(calls.at(-1)?.params).toEqual({ ago: '3 d' });
  });

  it('fills the install command only for the sentence that asks for one', () => {
    const missing: StorageDisk = { ...disk, smart: { state: 'not_installed' } };
    const first = spy();
    verdictSentence(missing, 'opkg', now, first.record);
    expect(first.calls.at(-1)?.params).toEqual({ cmd: 'opkg update && opkg install smartmontools' });

    // A healthy disk names no command; only the age of its reading.
    const second = spy();
    const healthy = verdictSentence(disk, 'opkg', now, second.record);
    expect(healthy.verdict.key).toBe('storage.smart_ok');
    expect(second.calls.at(-1)?.params).toEqual({ ago: '3 d' });
  });

  it('asks for no value at all for a state that never had a reading', () => {
    const never: StorageDisk = { ...disk, smart: { state: 'standby', checked_at: null } };
    const { calls, record } = spy();
    const { verdict } = verdictSentence(never, null, now, record);
    expect(verdict.key).toBe('storage.smart_standby_never');
    expect(calls.at(-1)?.params).toEqual({});
  });
});

describe('worstDisk', () => {
  it('picks the failing disk over the warm one, and a warning over a healthy disk', () => {
    const failing: StorageDisk = { ...disk, name: 'sdb', smart: { ...disk.smart!, state: 'failing' } };
    const missing: StorageDisk = { ...disk, name: 'sdc', smart: { state: 'not_installed' } };
    expect(worstDisk([disk, failing, missing])?.name).toBe('sdb');
    expect(worstDisk([disk, missing])?.name).toBe('sdc');
  });

  it('an unreadable disk outranks a healthy one: a router with one is not all good', () => {
    const asleep: StorageDisk = { ...disk, name: 'sdb', smart: { state: 'standby', checked_at: null } };
    expect(worstDisk([disk, asleep])?.name).toBe('sdb');
  });

  it('an empty list has no worst disk', () => {
    expect(worstDisk([])).toBeNull();
  });
});
