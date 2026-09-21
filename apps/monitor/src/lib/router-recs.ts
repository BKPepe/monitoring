import type { RecommendationSeverity, RouterRecommendation } from '@/api/types';

/**
 * Presentation of the router recommendations (`router_recommendations`).
 *
 * The server owns the rules, the texts AND the order (severity, area, the
 * rule's rank): the live page has to show what the Monday e-mail says. So
 * nothing here sorts - the items are only dealt into the two groups the card
 * draws, each keeping the order it arrived in.
 */
export interface RecommendationGroups {
  /** Critical and warning: always visible. */
  urgent: RouterRecommendation[];
  /** For information: behind a disclosure, so a healthy router does not look like a to-do list. */
  info: RouterRecommendation[];
}

export function groupRecommendations(items: readonly RouterRecommendation[] | null | undefined): RecommendationGroups {
  const groups: RecommendationGroups = { urgent: [], info: [] };
  for (const item of items ?? []) {
    // A severity this build does not know yet is shown, not hidden: a new
    // level is more likely to be serious than to be a fourth kind of "info".
    (item.severity === 'info' ? groups.info : groups.urgent).push(item);
  }
  return groups;
}

/** Badge variant of a severity; the label next to it carries the meaning, the colour only repeats it. */
export function severityTone(severity: RecommendationSeverity | string): 'down' | 'warning' | 'info' {
  if (severity === 'critical') return 'down';
  if (severity === 'info') return 'info';
  return 'warning';
}
