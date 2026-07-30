import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Skládá třídy a řeší konflikty Tailwind utilit — pozdější `p-4` přebije
 * dřívější `p-2`, což samotné clsx neumí. Konvence shadcn/ui.
 */
export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** Odezva v ms. `—` pro chybějící hodnotu, ať tabulka nemá prázdné buňky. */
export function formatMs(value: number | null | undefined): string {
  return value == null ? '—' : `${value} ms`;
}

/** Procenta na jedno desetinné místo, bez zbytečné nuly u celých čísel. */
export function formatPercent(value: number | null | undefined, digits = 0): string {
  if (value == null) return '—';
  return `${value.toFixed(digits)} %`;
}

/**
 * Relativní čas. Záměrně hrubá granularita — u "před 3 s" nikoho nezajímá,
 * jestli to bylo 3,4 s, a jemnější údaj jen nutí oči číst přesněji.
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

/** Uptime ve formátu 42d 7h 23m — vynechává nulové jednotky zleva. */
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
