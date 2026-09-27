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
