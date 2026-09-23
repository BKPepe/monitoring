import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Composes classes and resolves Tailwind utility conflicts — a later `p-4`
 * beats an earlier `p-2`, which clsx alone cannot do. The shadcn/ui convention.
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** Latency in ms. `—` for a missing value, so the table has no empty cells. */
export function formatMs(value: number | null | undefined): string {
  return value == null ? '—' : `${value} ms`;
}

/**
 * A percentage at `digits` decimals. A value below 100 never prints as 100:
 * half-up rounding turned 99.999 % uptime - a month with an outage in it -
 * into "100.00 %", so it stops one step short ("99.99 %") instead.
 */
export function formatPercent(value: number | null | undefined, digits = 0): string {
  if (value == null) return '—';
  const shown = value < 100 && Number(value.toFixed(digits)) >= 100 ? 100 - 10 ** -digits : value;
  return `${shown.toFixed(digits)} %`;
}

/**
 * Relative time. Deliberately coarse — with "3 s ago" nobody cares whether
 * it was 3.4 s, and a finer value only makes eyes read harder.
 */
export function formatRelative(iso: string, now = Date.now()): string {
  const seconds = Math.max(0, Math.round((now - new Date(iso).getTime()) / 1000));

  if (seconds < 60) return `před ${seconds} s`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `před ${minutes} min`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `před ${hours} h`;
  return `před ${Math.round(hours / 24)} d`;
}

/** Uptime as 42d 7h 23m — omits zero units from the left. */
export function formatUptime(totalSeconds: number): string {
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);

  const parts: string[] = [];
  if (days) parts.push(`${days}d`);
  if (days || hours) parts.push(`${hours}h`);
  parts.push(`${minutes}m`);
  return parts.join(' ');
}
