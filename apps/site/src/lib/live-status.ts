/**
 * One live status answer per page, shared by every component that shows it.
 *
 * The hero, the architecture diagram, the dashboard preview and the agent map
 * used to carry their own numbers, two of them hard-coded ("12 agents",
 * "24 ms"). They now render the same `/api/status` answer from the same
 * moment, and all of them fall back to a dash when it fails.
 *
 * The overall verdict comes from the server (`bk_overall_verdict()` behind
 * `public_status`); the site never upgrades it. "All systems operational"
 * needs a healthy verdict, at least one monitor and a fresh timestamp.
 */
import { API_ORIGIN } from '../config';

export type Verdict = 'healthy' | 'degraded' | 'down' | 'maintenance' | 'unknown';
export type NodeState = 'online' | 'warning' | 'offline' | 'maintenance' | 'unknown';

export interface PublicNode {
  name: string;
  status: NodeState;
  latencyMs: number | null;
}

export interface PublicStatus {
  verdict: Verdict;
  /** false once the newest check is older than STALE_AFTER_MS, or when there is none. */
  fresh: boolean;
  uptimePercent: number | null;
  totalMonitors: number | null;
  downMonitors: number | null;
  agentsOnline: number | null;
  agentsTotal: number | null;
  avgLatencyMs: number | null;
  lastUpdated: Date | null;
  nodes: PublicNode[];
}

export type LiveStatus = { ok: true; data: PublicStatus } | { ok: false };

/**
 * The status app flags a collector silent for 15 minutes, and the worker
 * caches the answer for 5 more. Anything older is not "live" any more.
 */
export const STALE_AFTER_MS = 20 * 60_000;
const POLL_MS = 60_000;

// Only the server's own word for healthy counts as healthy.
const VERDICTS: Record<string, Verdict> = {
  healthy: 'healthy',
  degraded: 'degraded',
  warning: 'degraded',
  down: 'down',
  outage: 'down',
  maintenance: 'maintenance',
};

const NODE_STATES: Record<string, NodeState> = {
  online: 'online',
  up: 'online',
  warning: 'warning',
  offline: 'offline',
  down: 'offline',
  maintenance: 'maintenance',
  unknown: 'unknown',
};

function num(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function parseDate(value: unknown): Date | null {
  if (typeof value !== 'string' || value === '') return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Turns the raw API answer into what the page may show. Anything the site
 * does not recognise becomes "unknown" - never "healthy".
 */
export function normaliseStatus(raw: unknown, now: number = Date.now()): PublicStatus | null {
  if (!raw || typeof raw !== 'object') return null;
  const r = raw as Record<string, unknown>;
  if (r.available === false || typeof r.status !== 'string') return null;

  const lastUpdated = parseDate(r.lastUpdated);
  const fresh = lastUpdated !== null && now - lastUpdated.getTime() < STALE_AFTER_MS;
  const totalMonitors = num(r.totalMonitors);

  let verdict: Verdict = VERDICTS[r.status] ?? 'unknown';
  // A healthy verdict over nothing, or over old data, is not an all-clear.
  if (verdict === 'healthy' && (!fresh || !totalMonitors)) verdict = 'unknown';

  const nodes: PublicNode[] = Array.isArray(r.nodes)
    ? r.nodes
        .filter((n): n is Record<string, unknown> => !!n && typeof n === 'object')
        .map((n) => ({
          name: String(n.name ?? ''),
          status: NODE_STATES[String(n.status)] ?? 'unknown',
          latencyMs: num(n.latencyMs),
        }))
    : [];

  return {
    verdict,
    fresh,
    uptimePercent: num(r.uptimePercent),
    totalMonitors,
    downMonitors: num(r.downMonitors),
    agentsOnline: num(r.agentsOnline),
    agentsTotal: num(r.agentsTotal),
    avgLatencyMs: num(r.avgLatencyMs),
    lastUpdated,
    nodes,
  };
}

type Listener = (status: LiveStatus) => void;

const listeners: Listener[] = [];
let latest: LiveStatus | null = null;
let started = false;

async function poll(): Promise<void> {
  let next: LiveStatus;
  try {
    const res = await fetch(`${API_ORIGIN}/api/status`);
    const data = res.ok ? normaliseStatus(await res.json()) : null;
    next = data ? { ok: true, data } : { ok: false };
  } catch {
    next = { ok: false };
  }
  latest = next;
  for (const listener of listeners) listener(next);
}

/**
 * Subscribes to the shared answer. The first subscriber starts the fetch and
 * the one-minute refresh; later subscribers get the latest answer at once.
 */
export function onLiveStatus(listener: Listener): void {
  listeners.push(listener);
  if (latest) listener(latest);
  if (!started) {
    started = true;
    void poll();
    setInterval(() => void poll(), POLL_MS);
  }
}

/** "—" for anything unmeasured; a number is never invented to fill the gap. */
export function dash(value: number | null | undefined, suffix = ''): string {
  return typeof value === 'number' && Number.isFinite(value) ? `${value}${suffix}` : '—';
}
