/** The statuses a device can be in, as `api.php` reports them. */
export type AssetStatus = 'up' | 'down' | 'warning' | 'paused' | 'maintenance' | 'unknown';

// 'unknown' = an agent-side check whose agent went silent (cron writes it).
const KNOWN_STATUSES: AssetStatus[] = ['up', 'down', 'warning', 'paused', 'maintenance', 'unknown'];

/**
 * Reads the `?status=` filter coming from the health ring on the dashboard.
 *
 * Anything unrecognised becomes `null`, i.e. no filtering. Treating a typo as
 * a real status would match no device and present an empty inventory as the
 * truth - the one outcome a monitoring tool must never show by accident.
 */
export function parseStatusFilter(raw: string | null | undefined): AssetStatus | null {
  return KNOWN_STATUSES.includes(raw as AssetStatus) ? (raw as AssetStatus) : null;
}

/**
 * Narrows the device list by free text and/or status.
 *
 * `null` means "no filter is active" - the caller then renders the full
 * grouped tree instead of a flat list.
 */
export function filterAssets<T extends { name: string; hostname?: string | null; status: AssetStatus }>(
  assets: T[],
  { query, status }: { query?: string; status?: AssetStatus | null }
): T[] | null {
  const q = (query ?? '').trim().toLowerCase();
  if (!q && !status) return null;

  return assets.filter(
    (a) =>
      (!status || a.status === status) &&
      (!q || a.name.toLowerCase().includes(q) || (a.hostname ?? '').toLowerCase().includes(q))
  );
}

/**
 * Orders a filtered device list by when each device last changed state,
 * the most recent change first.
 *
 * The "offline" list arrived in whatever order the API sent it, and without
 * a time on the rows it had no order a reader could see. Newest first puts
 * what just broke at the top, which is what the operator opened the list
 * for; a device with no known time goes last, not to an arbitrary place.
 */
export function orderByStatusChange<T>(assets: T[], sinceSeconds: (asset: T) => number | null | undefined): T[] {
  return [...assets].sort((a, b) => {
    const sa = sinceSeconds(a);
    const sb = sinceSeconds(b);
    if (sa == null && sb == null) return 0;
    if (sa == null) return 1;
    if (sb == null) return -1;
    return sa - sb;
  });
}
