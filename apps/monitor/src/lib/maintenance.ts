/**
 * Where a monitor's maintenance stands: none, announced for later, or on now.
 * The flag alone said "Údržba" for a window that had not begun, and the
 * button to end it deleted the plan (R291).
 */
export type MaintenanceState = 'none' | 'scheduled' | 'active';

export function maintenanceState(
  m: { maintenance?: boolean; maintenanceStart?: string | null } | null | undefined,
  now: number
): MaintenanceState {
  if (m?.maintenance !== true) return 'none';
  const v = m.maintenanceStart ?? '';
  const start = v === '' ? NaN : new Date(v.includes('T') ? v : v.replace(' ', 'T')).getTime();
  return Number.isFinite(start) && start > now ? 'scheduled' : 'active';
}
