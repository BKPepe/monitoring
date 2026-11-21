/**
 * The id in /infrastructure/:id is always a monitors.id.
 *
 * Link builders used to mix monitors.id and monitors.asset_id in the same
 * URL segment, and the detail page accepted either - so once one device's
 * asset_id equalled another monitor's id, a card opened the wrong device.
 * Anything that is not a plain positive integer is not an id at all: it used
 * to fall back to monitor 1, which showed some device instead of "not found".
 */
export function parseMonitorId(segment: string | null | undefined): number | null {
  if (segment == null || !/^[1-9]\d*$/.test(segment)) return null;
  const id = Number(segment);
  return Number.isSafeInteger(id) ? id : null;
}
