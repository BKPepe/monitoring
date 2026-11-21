/**
 * How to colour the one-line SMART verdict an older agent sends. Only a
 * plain "OK" about real disks is healthy: "WARNING (Disk /dev/sdb selhal v
 * SMART)" was drawn green, and so was "OK (Nebyly detekovány fyzické disky)",
 * which says nothing about any disk.
 */
export function smartVerdictTone(raw: string | null | undefined): 'up' | 'warning' | 'down' {
  if (!raw) return 'warning';
  if (/fail|selhal|warning|critical/i.test(raw)) return 'down';
  if (/^\s*ok\b/i.test(raw) && !/nebyly detekov|no (physical )?disks/i.test(raw)) return 'up';
  return 'warning';
}

/** The verdict says the tool is missing or did not answer: worth an install hint. */
export function smartToolMissing(raw: string | null | undefined): boolean {
  return !raw || /n\/a|chybí|not available|unavailable|missing/i.test(raw);
}
