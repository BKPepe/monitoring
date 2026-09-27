import * as React from 'react';
import type { HealthDeduction, ScoredHealth } from '@/components/health-deductions';
import type { Language } from '@/context/language-context';
import type { HealthComponent, HealthGrade } from '@/lib/health';

/**
 * `action=health` in the app view: the network score with its top named
 * deductions, and every monitor's own score (SCORE, formulaVersion 1). The
 * app never computes a score - it reads this answer and draws it.
 */
export interface NetworkHealth {
  score: number | null;
  grade: HealthGrade | null;
  formulaVersion: number;
  assetsScored?: number;
  assetsTotal?: number;
  components: HealthComponent[];
  deductions?: HealthDeduction[];
}

export interface FleetHealth {
  network: NetworkHealth;
  /** By monitor id; a paused monitor carries `paused` and no score. */
  assets: Map<number, ScoredHealth>;
}

/**
 * Loading, failed or the answer. A failure is its own state, never a "—"
 * ring: "—" means the server had too little data, a failure means nobody
 * knows (kit rule 4).
 */
export type FleetHealthState =
  | { status: 'loading'; data: null }
  | { status: 'failed'; data: FleetHealth | null }
  | { status: 'ok'; data: FleetHealth };

function parse(raw: unknown): FleetHealth {
  const body = raw as { network?: NetworkHealth; assets?: ({ monitorId: number } & ScoredHealth)[] } | null;
  if (!body || typeof body !== 'object' || !body.network || !Array.isArray(body.network.components)) {
    throw new Error('invalid action=health');
  }
  const assets = new Map<number, ScoredHealth>();
  for (const a of Array.isArray(body.assets) ? body.assets : []) {
    if (a && typeof a.monitorId === 'number') assets.set(a.monitorId, a);
  }
  return { network: body.network, assets };
}

/**
 * The fleet's health, refetched with the page's minute clock (`tick`) and in
 * the page's language (the deductions are worded by the server). A failed
 * refresh keeps the last answer and reports `failed`, so the ring can stay
 * while the page says the refresh did not work.
 */
export function useFleetHealth(lang: Language, tick: number): FleetHealthState & { retry: () => void } {
  const [state, setState] = React.useState<FleetHealthState>({ status: 'loading', data: null });
  const [attempt, setAttempt] = React.useState(0);

  React.useEffect(() => {
    let active = true;
    fetch(`/status/api.php?action=health&lang=${lang}`, { credentials: 'include' })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
      .then((body) => {
        if (active) setState({ status: 'ok', data: parse(body) });
      })
      .catch(() => {
        if (active) setState((prev) => ({ status: 'failed', data: prev.data }));
      });
    return () => {
      active = false;
    };
  }, [lang, tick, attempt]);

  const retry = React.useCallback(() => setAttempt((n) => n + 1), []);
  return { ...state, retry };
}
