/** The statuses a device can be in, as `api.php` reports them. */
export type AssetStatus = 'up' | 'down' | 'warning' | 'paused' | 'maintenance';

const KNOWN_STATUSES: AssetStatus[] = ['up', 'down', 'warning', 'paused', 'maintenance'];

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
