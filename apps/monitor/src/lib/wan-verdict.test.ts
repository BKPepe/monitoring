import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import omnia from '@/api/omnia-router.fixture';
import type { WanPath } from '@/api/types';
import {
  confidenceKey,
  coreHiddenByAverage,
  planRate,
  rateMarks,
  sqmRate,
  verdictClassKey,
  verdictFlag,
  verdictNumber,
  verdictReasonKey,
  verdictTone,
} from './wan-verdict';

/** Every reason WAN 3.4 can answer with - rules 1-6, the five gates, rule 7 and the aggregation. */
const ALL_REASONS = [
  'plan_reached',
  'sqm_shaper',
  'wan_port',
  'packet_path',
  'test_client',
  'mixed',
  'below_plan',
  'upstream_loss',
  'no_result',
  'path_unverified',
  'background_traffic',
  'minute_run_overlap',
  'cpu_not_measured',
  'cpu_borderline',
  'no_plan_known',
  'not_enough_tests',
  'tests_disagree',
  'single_server',
  'server_limited',
  'server_capacity_unproven',
  'port_plateau',
];

describe('wan-verdict', () => {
  it('colours a reached plan green and a shaper as information, not as a fault', () => {
    expect(verdictTone('none')).toBe('up');
    expect(verdictTone('link_limited')).toBe('info');
    expect(verdictTone('cpu_limited')).toBe('warning');
    expect(verdictTone('line_limited')).toBe('warning');
    expect(verdictTone('inconclusive')).toBe('neutral');
  });

  it('a class this build does not know stays neutral and never prints its wire value', () => {
    expect(verdictTone('wan_on_fire')).toBe('neutral');
    expect(verdictClassKey('wan_on_fire')).toBe('wan.class_inconclusive');
    expect(verdictClassKey(undefined)).toBe('wan.class_inconclusive');
  });

  it('every reason of WAN 3.4 has a sentence in the dictionary', () => {
    const dictionary = readFileSync(join(__dirname, '../context/language-context.tsx'), 'utf8');
    for (const reason of ALL_REASONS) {
      const key = verdictReasonKey(reason);
      expect(key, reason).toBe(`wan.verdict.${reason}`);
      // The key is handed to t() as a variable, so the i18n test cannot see it.
      // (A boolean, so a failure names the key instead of printing the dictionary.)
      expect(dictionary.includes(`'${key}':`), String(key)).toBe(true);
    }
  });

  it('an unknown reason answers null, so the card falls back to the class label', () => {
    expect(verdictReasonKey('moon_phase')).toBeNull();
    expect(verdictReasonKey(null)).toBeNull();
  });

  it('confidence is said only when the server sent one', () => {
    expect(confidenceKey('high')).toBe('wan.confidence_high');
    expect(confidenceKey('medium')).toBe('wan.confidence_medium');
    expect(confidenceKey('low')).toBe('wan.confidence_low');
    expect(confidenceKey(null)).toBeNull();
  });

  it('reads a rate and a flag out of the verdict numbers, and nothing else', () => {
    const verdict = omnia.wanBottleneck.verdict.dl;
    expect(verdictNumber(verdict, 'speed_mbps')).toBe(1350.12);
    expect(verdictNumber(verdict, 'not_there')).toBeNull();
    expect(verdictNumber({ numbers: { speed_mbps: 'fast' } }, 'speed_mbps')).toBeNull();
    expect(verdictFlag({ numbers: { no_cpu_headroom: true } }, 'no_cpu_headroom')).toBe(true);
    expect(verdictFlag({ numbers: { no_cpu_headroom: false } }, 'no_cpu_headroom')).toBe(false);
    expect(verdictFlag(null, 'no_cpu_headroom')).toBe(false);
  });

  it('the rate bar carries only the rates that are known', () => {
    const marks = rateMarks({ measured: 1350.12, plan: 2000, port: 2500, sqm: null });
    expect(marks.map((m) => m.key)).toEqual(['wan.bar_measured', 'wan.bar_plan', 'wan.bar_port']);
    // The scale is the largest known rate, so the marks are comparable.
    expect(marks[2].pct).toBe(100);
    expect(marks[1].pct).toBe(80);
    expect(marks[0].pct).toBe(54);
  });

  it('an unknown plan is absent from the bar, never a zero mark', () => {
    const marks = rateMarks({ measured: 902.4, plan: null, port: 2500 });
    expect(marks.map((m) => m.key)).toEqual(['wan.bar_measured', 'wan.bar_port']);
    expect(marks.some((m) => m.mbit === 0)).toBe(false);
    expect(rateMarks({ measured: null, plan: null, port: null, sqm: null })).toEqual([]);
  });

  it('the plan of a direction comes from the response, and null stays null', () => {
    expect(planRate(omnia.wanBottleneck.plan, 'dl')).toBe(2000);
    expect(planRate(omnia.wanBottleneck.plan, 'ul')).toBe(1000);
    expect(planRate(omnia.wanBottleneckNoPlan.plan, 'dl')).toBeNull();
  });

  it('SQM: an empty list and a missing list both mean "nothing shapes this direction"', () => {
    // The Omnia has sqm: [] - checked, no queue on the WAN device.
    expect(sqmRate(omnia.wanPath, 'dl')).toBeNull();
    expect(sqmRate({ sqm: null }, 'dl')).toBeNull();
    const shaped: WanPath = { sqm: [{ iface: 'eth2', download_kbps: 90000, upload_kbps: 0 }] };
    expect(sqmRate(shaped, 'dl')).toBe(90);
    // A stored 0 is "not shaped", exactly as the classifier reads it.
    expect(sqmRate(shaped, 'ul')).toBeNull();
  });

  it('says which average would have hidden the busy core, but only when the gap is wide', () => {
    expect(coreHiddenByAverage({ core_busy_pct: 100, all_cores_avg_pct: 72.5 })).toBe(73);
    // 24 points is not worth a sentence; 25 is.
    expect(coreHiddenByAverage({ core_busy_pct: 90, all_cores_avg_pct: 66 })).toBeNull();
    expect(coreHiddenByAverage({ core_busy_pct: 91, all_cores_avg_pct: 66 })).toBe(66);
    expect(coreHiddenByAverage({ core_busy_pct: 100 })).toBeNull();
    expect(coreHiddenByAverage(null)).toBeNull();
  });
});
