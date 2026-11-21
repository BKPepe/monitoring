import type { WanConfidence, WanPath, WanPlan, WanVerdict, WanVerdictClass } from '@/api/types';

/**
 * Presentation of the WAN bottleneck verdict (`wan_bottleneck`).
 *
 * The classifier is PHP and only PHP (WAN 3.4): the page renders the class and
 * the reason it was given and never re-derives one, so the card and the Monday
 * e-mail cannot tell the owner two different stories. Everything here is
 * therefore a lookup - class to colour, reason to sentence - plus the little
 * arithmetic the rate bar needs.
 *
 * Unknown values are absent, never zero: a bar without the plan draws three
 * marks, it does not draw a plan of 0 Mbit/s.
 */

/** Badge variant of a verdict class; the label next to it carries the meaning, the colour repeats it. */
export function verdictTone(cls: WanVerdictClass | string | undefined | null): 'up' | 'warning' | 'info' | 'neutral' {
  if (cls === 'none') return 'up';
  // An intended limit (shaper) or the port's own rate is not a fault to fix.
  if (cls === 'link_limited') return 'info';
  if (cls === 'cpu_limited' || cls === 'line_limited') return 'warning';
  return 'neutral';
}

/**
 * Short label of the class. Spelled out key by key: a composed key could not
 * be checked by the dictionary test, and a class this build does not know must
 * not print its raw wire value on the page.
 */
export function verdictClassKey(cls: WanVerdictClass | string | undefined | null): string {
  if (cls === 'none') return 'wan.class_none';
  if (cls === 'link_limited') return 'wan.class_link_limited';
  if (cls === 'cpu_limited') return 'wan.class_cpu_limited';
  if (cls === 'line_limited') return 'wan.class_line_limited';
  return 'wan.class_inconclusive';
}

/** Every reason WAN 3.4 can answer with. null = a reason this build does not know; the card then says the class alone. */
export function verdictReasonKey(reason: string | undefined | null): string | null {
  switch (reason) {
    // Rules 1-6.
    case 'plan_reached':
      return 'wan.verdict.plan_reached';
    case 'sqm_shaper':
      return 'wan.verdict.sqm_shaper';
    case 'wan_port':
      return 'wan.verdict.wan_port';
    case 'packet_path':
      return 'wan.verdict.packet_path';
    case 'test_client':
      return 'wan.verdict.test_client';
    case 'mixed':
      return 'wan.verdict.mixed';
    case 'below_plan':
      return 'wan.verdict.below_plan';
    case 'upstream_loss':
      return 'wan.verdict.upstream_loss';
    // Gates.
    case 'no_result':
      return 'wan.verdict.no_result';
    case 'path_unverified':
      return 'wan.verdict.path_unverified';
    case 'background_traffic':
      return 'wan.verdict.background_traffic';
    case 'minute_run_overlap':
      return 'wan.verdict.minute_run_overlap';
    case 'cpu_not_measured':
      return 'wan.verdict.cpu_not_measured';
    // Rule 7 and the aggregation.
    case 'cpu_borderline':
      return 'wan.verdict.cpu_borderline';
    case 'no_plan_known':
      return 'wan.verdict.no_plan_known';
    case 'not_enough_tests':
      return 'wan.verdict.not_enough_tests';
    case 'tests_disagree':
      return 'wan.verdict.tests_disagree';
    case 'single_server':
      return 'wan.verdict.single_server';
    case 'server_limited':
      return 'wan.verdict.server_limited';
    case 'server_capacity_unproven':
      return 'wan.verdict.server_capacity_unproven';
    case 'port_plateau':
      return 'wan.verdict.port_plateau';
    default:
      return null;
  }
}

/** How much the verdict is worth. null = the server did not say, and then nothing is claimed. */
export function confidenceKey(confidence: WanConfidence | string | undefined | null): string | null {
  if (confidence === 'high') return 'wan.confidence_high';
  if (confidence === 'medium') return 'wan.confidence_medium';
  if (confidence === 'low') return 'wan.confidence_low';
  return null;
}

const num = (value: unknown): number | null => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/** A number of the verdict's `numbers` bag; anything else there (a flag, a list) is not a rate. */
export function verdictNumber(verdict: WanVerdict | null | undefined, key: string): number | null {
  return num(verdict?.numbers?.[key]);
}

/** A flag of the `numbers` bag, e.g. `no_cpu_headroom` (rule 1) or `negotiated_below_port_max` (rule 3). */
export function verdictFlag(verdict: WanVerdict | null | undefined, key: string): boolean {
  return verdict?.numbers?.[key] === true;
}

export type WanDirection = 'dl' | 'ul';

/**
 * The SQM rate for one direction, in Mbit/s.
 *
 * `sqm: []` means "checked, nothing shapes this device" and `sqm: null` means
 * "could not check" - both answer null here. A stored rate of 0 is the same
 * "not shaped" the classifier reads it as (WAN 3.4, symbol Q).
 */
export function sqmRate(path: WanPath | null | undefined, direction: WanDirection): number | null {
  for (const queue of path?.sqm ?? []) {
    const kbps = num(direction === 'dl' ? queue.download_kbps : queue.upload_kbps);
    if (kbps !== null && kbps > 0) return kbps / 1000;
  }
  return null;
}

/** One mark of the rate bar. Only rates that are known get one. */
export interface WanRateMark {
  /** Dictionary key of the label. */
  key: 'wan.bar_measured' | 'wan.bar_plan' | 'wan.bar_port' | 'wan.bar_sqm';
  mbit: number;
  /** Share of the bar's scale, 0-100. */
  pct: number;
}

/**
 * Measured against plan, port link rate and shaper, on one scale.
 *
 * The scale is the largest known rate, so the bar is comparable within the
 * direction and never pretends to know a maximum nobody measured.
 */
export function rateMarks(input: {
  measured?: number | null;
  plan?: number | null;
  port?: number | null;
  sqm?: number | null;
}): WanRateMark[] {
  const known: WanRateMark[] = [];
  const add = (key: WanRateMark['key'], value: number | null | undefined) => {
    const mbit = num(value);
    if (mbit !== null && mbit > 0) known.push({ key, mbit, pct: 0 });
  };
  add('wan.bar_measured', input.measured);
  add('wan.bar_plan', input.plan);
  add('wan.bar_port', input.port);
  add('wan.bar_sqm', input.sqm);

  const scale = known.reduce((max, mark) => Math.max(max, mark.mbit), 0);
  return known.map((mark) => ({ ...mark, pct: Math.round((mark.mbit / scale) * 100) }));
}

/** The plan rate of one direction, straight from the response. */
export function planRate(plan: WanPlan | null | undefined, direction: WanDirection): number | null {
  return num(direction === 'dl' ? plan?.downMbit : plan?.upMbit);
}

/**
 * How far the all-core average sits below the core that was actually busy.
 *
 * The guard this serves (WAN 3.4, "one saturated core hidden by the average"):
 * on a two-core router an average of 52 % and a core at 98 % are the same
 * minute. The line is only worth printing when the gap is wide, so anything
 * under 25 points answers null.
 */
export function coreHiddenByAverage(phase: Record<string, unknown> | null | undefined): number | null {
  const busy = num(phase?.core_busy_pct);
  const avg = num(phase?.all_cores_avg_pct);
  if (busy === null || avg === null) return null;
  const gap = busy - avg;
  return gap >= 25 ? Math.round(avg) : null;
}
