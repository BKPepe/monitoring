/**
 * The health score 0-100 as the server computes it (N-1, bk_health_score in
 * apps/status/functions.php, docs/api.md "Health score"). The app never
 * computes a score: it draws the server's number, its grade and the points of
 * each component. What lives here is only the reading of that answer, so the
 * ring, the breakdown and the pages agree on it.
 */
export type HealthGrade = 'good' | 'fair' | 'poor';

/**
 * One component of the score. `points` is null when nothing measured it - the
 * server then leaves it out and renormalises the rest, so it is shown as
 * "not measured", never as full marks.
 */
export interface HealthComponent {
  key: string;
  /** The server's name in the request's language. */
  label?: string;
  weight: number;
  points: number | null;
  /** What it took off the final score (monitor scores only). */
  deduction?: number | null;
  value?: number | null;
  unit?: string | null;
  /** Network score only: how many devices measured this component. */
  assets?: number;
}

/**
 * `monitor_insights.health` of one device, or `action=health`'s `network`.
 * `score` null = not enough data: the app prints "—".
 */
export interface HealthScore {
  score: number | null;
  grade: HealthGrade | null;
  formulaVersion: number;
  measuredWeight?: number;
  components: HealthComponent[];
  assetsScored?: number;
  assetsTotal?: number;
}

/**
 * One named deduction of the health score (SCORE, formulaVersion 1): what
 * took points off and how many. The server words `label` in the request's
 * language and already names the value and the limit ("Teplota CPU 71 °C,
 * nad 65 °C"); the app only draws it. On the network score `points` is what
 * the item costs the NETWORK (its device's points divided by the devices
 * scored), and `monitorId`/`monitorName` say whose it is.
 */
export interface HealthDeduction {
  component: string;
  kind: string;
  label?: string;
  points: number;
  value?: number | null;
  limit?: number | null;
  unit?: string | null;
  monitorId?: number;
  monitorName?: string | null;
}

/**
 * The score answer as the server sends it now: the shape above plus the
 * named deductions and the paused flag (requests.md "From SCORE").
 */
export type ScoredHealth = HealthScore & {
  deductions?: HealthDeduction[];
  paused?: true;
  measuredComponents?: number;
};

/**
 * `action=health` in the app view: the network score with its named
 * deductions, and every monitor's own score by monitor id (a paused monitor
 * carries `paused` and no score). The dashboard and the device list read it
 * through appApi.getHealth(); the public page has its own reading
 * (lib/public-health.ts), because its answer has no monitor scores.
 */
export interface FleetHealth {
  network: ScoredHealth;
  assets: ReadonlyMap<number, ScoredHealth>;
}

const GRADES: readonly HealthGrade[] = ['good', 'fair', 'poor'];

/**
 * One score as documented: `score` a finite number or null, `grade` one of
 * the three bands or null, `paused` only when it is true, `components` a
 * list (empty when none came). Everything else (the components themselves,
 * deductions, labels) is drawn as the server sent it.
 */
function readScored(raw: Record<string, unknown>): ScoredHealth {
  const { paused, ...rest } = raw;
  const health = {
    ...rest,
    score: typeof raw.score === 'number' && Number.isFinite(raw.score) ? raw.score : null,
    grade: GRADES.includes(raw.grade as HealthGrade) ? (raw.grade as HealthGrade) : null,
    components: Array.isArray(raw.components) ? raw.components : [],
  } as ScoredHealth;
  if (paused === true) health.paused = true;
  return health;
}

/**
 * Reads the app view of `action=health`; throws when the answer is not that
 * shape. A missing network score or a missing list of monitor scores is a
 * failure the page says out loud - never an empty fleet, never a "—" ring,
 * which is the server's verdict "too little data".
 */
export function parseFleetHealth(body: unknown): FleetHealth {
  const b = body as { network?: unknown; assets?: unknown } | null;
  const network = b?.network;
  if (!network || typeof network !== 'object' || !Array.isArray((network as { components?: unknown }).components)) {
    throw new Error('health: no network score');
  }
  if (!Array.isArray(b?.assets)) throw new Error('health: no monitor scores');
  const assets = new Map<number, ScoredHealth>();
  for (const a of b.assets as unknown[]) {
    if (!a || typeof a !== 'object') continue;
    const id = (a as { monitorId?: unknown }).monitorId;
    if (typeof id !== 'number' || !Number.isInteger(id) || id <= 0) continue;
    assets.set(id, readScored(a as Record<string, unknown>));
  }
  return { network: readScored(network as Record<string, unknown>), assets };
}

/**
 * The grade of a score by the server's bands (good from 90, fair from 70).
 * Used only when an answer carries a score without its grade; the bands are
 * pinned by lib/health.test.ts against the documented formula.
 */
export function healthGradeOf(score: number | null | undefined): HealthGrade | null {
  if (score == null || !Number.isFinite(score)) return null;
  if (score >= 90) return 'good';
  return score >= 70 ? 'fair' : 'poor';
}

/** The status tone a grade is drawn in; no grade is neutral, never green. */
export function healthTone(grade: HealthGrade | null): 'up' | 'warning' | 'down' | 'neutral' {
  if (grade === 'good') return 'up';
  if (grade === 'fair') return 'warning';
  return grade === 'poor' ? 'down' : 'neutral';
}

/**
 * The components split into the ones that count and the ones nothing
 * measured. The measured ones come worst first, so the first bar under the
 * ring is the reason the score is not 100.
 */
export function splitHealthComponents(components: readonly HealthComponent[]): {
  measured: HealthComponent[];
  unmeasured: HealthComponent[];
} {
  const measured = components.filter((c) => c.points !== null && Number.isFinite(c.points));
  const unmeasured = components.filter((c) => c.points === null || !Number.isFinite(c.points));
  measured.sort((a, b) => (a.points as number) - (b.points as number) || b.weight - a.weight);
  return { measured, unmeasured };
}
