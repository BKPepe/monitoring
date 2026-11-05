type FailedCheck = { id?: unknown; monitorId?: unknown; outageEnd?: unknown };

/**
 * One entry per outage, from a list that holds one row per failed check. Rows
 * of a monitor that end at the same moment, or have not ended yet, are one
 * outage. An entry is the outage's oldest row in the list, plus the id of its
 * newest (latestId), in the order the outages first appear.
 */
export function outagesOf<T extends FailedCheck>(rows: readonly T[]): (T & { latestId: number })[] {
  const groups = new Map<string, T & { latestId: number }>();
  for (const row of rows) {
    const key = JSON.stringify([row.monitorId, row.outageEnd ?? null]);
    const id = typeof row.id === 'number' ? row.id : 0;
    const known = groups.get(key);
    if (!known) {
      groups.set(key, { ...row, latestId: id });
    } else {
      const latestId = Math.max(known.latestId, id);
      const oldest = typeof known.id === 'number' && known.id <= id ? known : row;
      groups.set(key, { ...oldest, latestId });
    }
  }
  return [...groups.values()];
}
