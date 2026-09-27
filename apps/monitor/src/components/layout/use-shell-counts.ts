import * as React from 'react';

/**
 * The counts the shell shows on every page: open incidents (the Incidenty
 * badge) and the findings that need attention (the bell, the Upozornění badge
 * and its phone tab).
 *
 * Both keep the last known number through a failed refresh - a badge that
 * vanishes on a network blip reads as "all fixed" - but they are marked not
 * known, and the bell may only say "all OK" after fresh, complete answers
 * (W1-A). The findings come from `action=findings&summary=1`: counts only,
 * kept 60 s per viewer on the server, so polling it from every page costs one
 * cached read (N-3).
 */
export interface FindingCounts {
  critical: number;
  warning: number;
  info: number;
}

/**
 * Where a count stands: not answered yet, answered, answered with a failed
 * source (the count is a floor, not the truth), or the call failed.
 */
export type CountState = 'loading' | 'ok' | 'incomplete' | 'failed';

export interface ShellCounts {
  /** Open incident records; null until the first answer. */
  openIncidents: number | null;
  incidentsState: CountState;
  findings: FindingCounts | null;
  findingsState: CountState;
  refresh: () => Promise<void>;
}

const POLL_MS = 60_000;

/** A summary we can trust: a number total and three number counts. Anything else is "unknown". */
export function readFindingsSummary(data: unknown): { counts: FindingCounts; incomplete: boolean } | null {
  if (typeof data !== 'object' || data === null) return null;
  const d = data as { total?: unknown; counts?: unknown; sourceErrors?: unknown };
  if (typeof d.total !== 'number' || typeof d.counts !== 'object' || d.counts === null) return null;
  const c = d.counts as Record<string, unknown>;
  // After a valid total, a severity the server left out had no finding: 0.
  const n = (v: unknown) => (typeof v === 'number' && Number.isFinite(v) && v > 0 ? v : 0);
  return {
    counts: { critical: n(c.critical), warning: n(c.warning), info: n(c.info) },
    incomplete: Array.isArray(d.sourceErrors) && d.sourceErrors.length > 0,
  };
}

async function getJson(url: string): Promise<unknown> {
  const r = await fetch(url, { credentials: 'include' });
  if (!r.ok) throw new Error(String(r.status));
  return r.json();
}

export function useShellCounts(): ShellCounts {
  const [openIncidents, setOpenIncidents] = React.useState<number | null>(null);
  const [incidentsState, setIncidentsState] = React.useState<CountState>('loading');
  const [findings, setFindings] = React.useState<FindingCounts | null>(null);
  const [findingsState, setFindingsState] = React.useState<CountState>('loading');
  const alive = React.useRef(true);

  const refresh = React.useCallback(async () => {
    await Promise.all([
      // The Incidents badge counts THE SAME thing the incidents page shows:
      // open records, freshly fallen monitors included by the endpoint itself.
      getJson('/status/api.php?action=incidents')
        .then((data) => {
          if (!alive.current) return;
          const list = (data as { incidents?: unknown })?.incidents;
          if (Array.isArray(list)) {
            setOpenIncidents(
              list.filter((i: { status?: string }) => (i?.status ?? 'investigating') !== 'resolved').length
            );
            setIncidentsState('ok');
          } else {
            setIncidentsState('failed');
          }
        })
        .catch(() => {
          if (alive.current) setIncidentsState('failed');
        }),
      getJson('/status/api.php?action=findings&summary=1')
        .then((data) => {
          if (!alive.current) return;
          const summary = readFindingsSummary(data);
          if (summary) {
            setFindings(summary.counts);
            setFindingsState(summary.incomplete ? 'incomplete' : 'ok');
          } else {
            setFindingsState('failed');
          }
        })
        .catch(() => {
          if (alive.current) setFindingsState('failed');
        }),
    ]);
  }, []);

  React.useEffect(() => {
    alive.current = true;
    void refresh();
    const timer = setInterval(() => void refresh(), POLL_MS);
    return () => {
      alive.current = false;
      clearInterval(timer);
    };
  }, [refresh]);

  return { openIncidents, incidentsState, findings, findingsState, refresh };
}

/** The findings that ask for action: critical and warning. Info is not an alert. */
export function attentionCount(findings: FindingCounts | null): number | null {
  return findings ? findings.critical + findings.warning : null;
}
