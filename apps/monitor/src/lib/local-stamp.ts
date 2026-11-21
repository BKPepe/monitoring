/**
 * A server time as "2026-11-12 10:00" in the visitor's own zone. The server
 * sends a maintenance window with its offset ("2026-11-12T10:00:00+01:00"),
 * so the same moment reads right anywhere; a bare value is read as local, as
 * it always was, and anything unreadable is shown as sent, minus seconds.
 */
export function localStamp(v: string): string {
  const at = new Date(v.includes('T') ? v : v.replace(' ', 'T'));
  if (Number.isNaN(at.getTime())) return v.replace('T', ' ').slice(0, 16);
  const p = (n: number) => String(n).padStart(2, '0');
  return `${at.getFullYear()}-${p(at.getMonth() + 1)}-${p(at.getDate())} ${p(at.getHours())}:${p(at.getMinutes())}`;
}
