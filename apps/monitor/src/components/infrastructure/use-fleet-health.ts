import * as React from 'react';
import type { HealthGrade } from '@/lib/health';

/**
 * The server's health score of every device the viewer may see
 * (`action=health`, app view: `assets[]`, docs/api.md "Health score"), keyed
 * by monitor id - for the ring on each card of the device list.
 *
 * The app never computes a score. A paused monitor has none (no ring); a
 * monitor with too little measured data has `score: null` (a "—" ring). A
 * failed request is its own state: the list then says the scores are
 * missing, instead of drawing a dash ring that would read "not enough data".
 *
 * Page-local until the typed API (api/app-api.ts) carries a getHealth() -
 * requested in impl/w2m/requests.md; the shape below is the documented one.
 */
export interface DeviceHealth {
  score: number | null;
  grade: HealthGrade | null;
  paused: boolean;
}

export type FleetHealth =
  { status: 'loading' } | { status: 'error' } | { status: 'ready'; byMonitor: ReadonlyMap<number, DeviceHealth> };

interface HealthAnswer {
  assets?: { monitorId?: unknown; score?: unknown; grade?: unknown; paused?: unknown }[];
}

const GRADES: readonly HealthGrade[] = ['good', 'fair', 'poor'];

/** Reads the answer; anything that is not the documented shape is a failure, not an empty fleet. */
export function readFleetHealth(data: unknown): ReadonlyMap<number, DeviceHealth> | null {
  const assets = (data as HealthAnswer | null)?.assets;
  if (!Array.isArray(assets)) return null;
  const byMonitor = new Map<number, DeviceHealth>();
  for (const a of assets) {
    const id = Number(a?.monitorId);
    if (!Number.isInteger(id) || id <= 0) continue;
    const score = typeof a.score === 'number' && Number.isFinite(a.score) ? a.score : null;
    const grade = GRADES.includes(a.grade as HealthGrade) ? (a.grade as HealthGrade) : null;
    byMonitor.set(id, { score, grade, paused: a.paused === true });
  }
  return byMonitor;
}

/** `reloadKey` refetches (the list reloads every minute; the scores follow it). */
export function useFleetHealth(enabled: boolean, reloadKey: unknown): FleetHealth {
  const [state, setState] = React.useState<FleetHealth>({ status: 'loading' });
  React.useEffect(() => {
    if (!enabled) return;
    let active = true;
    fetch('/status/api.php?action=health', { credentials: 'include' })
      .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status}`))))
      .then((data: unknown) => {
        if (!active) return;
        const byMonitor = readFleetHealth(data);
        setState(byMonitor ? { status: 'ready', byMonitor } : { status: 'error' });
      })
      .catch(() => {
        if (active) setState({ status: 'error' });
      });
    return () => {
      active = false;
    };
  }, [enabled, reloadKey]);
  return state;
}
