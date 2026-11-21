import type { HealthComponent, HealthGrade, HealthScore } from '@/lib/health';

/**
 * The public services score (`action=health&scope=public`).
 *
 * The public answer is only the network part, computed from the public set
 * with availability, latency, alerts and data freshness - no hardware, no
 * router internals (docs/api.md "Health score"). It names what cost points
 * as `deductions`, each with the public monitor it belongs to. It is the
 * whole public set, never a custom page's selection, so a filtered page does
 * not show it.
 *
 * Reading only: nothing here computes or repairs a score. An answer that is
 * not the documented shape is a failure, said as one - never "—", which
 * means "not enough data" and is the server's verdict to give.
 */
export interface PublicHealthDeduction {
  monitorId: number | null;
  monitorName: string | null;
  component: string;
  /** The server's sentence in the request's language ("Dostupnost 97,2 %"). */
  label: string;
  /** What this item costs the network score, in points. */
  points: number;
}

export interface PublicHealth extends HealthScore {
  deductions: PublicHealthDeduction[];
}

const GRADES: readonly HealthGrade[] = ['good', 'fair', 'poor'];

const num = (v: unknown): number | null => (typeof v === 'number' && Number.isFinite(v) ? v : null);

function component(raw: unknown): HealthComponent | null {
  if (!raw || typeof raw !== 'object') return null;
  const c = raw as Record<string, unknown>;
  if (typeof c.key !== 'string' || num(c.weight) === null) return null;
  return {
    key: c.key,
    label: typeof c.label === 'string' ? c.label : undefined,
    weight: num(c.weight) as number,
    points: num(c.points),
    assets: num(c.assets) ?? undefined,
  };
}

function deduction(raw: unknown): PublicHealthDeduction | null {
  if (!raw || typeof raw !== 'object') return null;
  const d = raw as Record<string, unknown>;
  const points = num(d.points);
  if (points === null || points <= 0 || typeof d.label !== 'string' || d.label === '') return null;
  return {
    monitorId: num(d.monitorId),
    monitorName: typeof d.monitorName === 'string' && d.monitorName !== '' ? d.monitorName : null,
    component: typeof d.component === 'string' ? d.component : '',
    label: d.label,
    points,
  };
}

/** The network score of the answer; throws when the answer is not one. */
export function parsePublicHealth(body: unknown): PublicHealth {
  const network = (body as { network?: unknown } | null)?.network;
  if (!network || typeof network !== 'object') throw new Error('health: no network score');
  const n = network as Record<string, unknown>;
  const score = n.score === null ? null : num(n.score);
  if (score === null && n.score !== null) throw new Error('health: score is neither a number nor null');
  if (!Array.isArray(n.components)) throw new Error('health: no components');
  const grade = GRADES.includes(n.grade as HealthGrade) ? (n.grade as HealthGrade) : null;
  return {
    score,
    grade: score === null ? null : grade,
    formulaVersion: num(n.formulaVersion) ?? num((body as { formulaVersion?: unknown }).formulaVersion) ?? 0,
    assetsScored: num(n.assetsScored) ?? undefined,
    assetsTotal: num(n.assetsTotal) ?? undefined,
    components: n.components.map(component).filter((c): c is HealthComponent => c !== null),
    deductions: (Array.isArray(n.deductions) ? n.deductions : [])
      .map(deduction)
      .filter((d): d is PublicHealthDeduction => d !== null),
  };
}
