import type { ApiMonitor } from '@/api/app-api';
import { monitorStatusKey } from '@/lib/status';

export interface AttentionItem {
  key: string;
  /** monitors.id - the /infrastructure/:id segment, never an asset_id (W1-D2). */
  monitorId: number;
  name: string;
  /**
   * 'info' = worth knowing, nothing is wrong (an agent update) - the same
   * scale the server's findings feed uses (bk_attention_reasons), so the
   * dashboard list and the feed never disagree on how bad a row is (CR-5).
   */
  severity: 'down' | 'warning' | 'info';
  text: string;
}

/** Message translation - the dashboard passes t(), tests a simple stand-in. */
export interface AttentionLabels {
  down: string;
  warning: string;
  /** An agent that reported and went quiet (unknown_stale, decision 5.10). */
  silent: string;
  unreachable: string;
  sslExpired: string;
  sslExpiring: (days: number) => string;
  agentUpdate: (version: string) => string;
  metricHigh: (metric: string, value: number) => string;
}

/**
 * Fallback used only where a monitor carries no configured limit - an
 * anonymous session does not receive them, and a monitor may never have had
 * one set. It is a STATED default, not a rule: where the admin configured a
 * limit, that limit decides.
 */
export const METRIC_ATTENTION_THRESHOLD = 90;

/**
 * Where a value sits against its limit: at or above it is critical, within the
 * warning band below it is a warning. The band is fifteen points, the same one
 * the server derives for its own thresholds, so the app and the alert agree.
 *
 * Three different ladders lived in the UI (90/75 on one tile, 80/60 in the
 * table, a flat 90 in this file) and none of them read the limit the admin set.
 */
export function metricSeverity(value: number | null | undefined, limit: number): 'up' | 'warning' | 'down' | null {
  if (typeof value !== 'number' || !Number.isFinite(value)) return null;
  if (value >= limit) return 'down';
  return value >= limit - 15 ? 'warning' : 'up';
}

/**
 * The limit a metric is judged against: the effective one the server resolved
 * (preset first, then the monitor's own), and the stated fallback only where
 * nothing was configured or the caller is anonymous.
 *
 * Deliberately NOT the raw cpuThreshold/ramThreshold/hddThreshold fields: the
 * server fills those with a default and they ignore a preset, so a monitor
 * whose preset says "warn at 70" was coloured against 90.
 */
export function thresholdFor(m: ApiMonitor, metric: 'cpu' | 'ram' | 'hdd'): number {
  const effective = m.effectiveThresholds?.[metric];
  return typeof effective === 'number' && effective > 0 ? effective : METRIC_ATTENTION_THRESHOLD;
}
/**
 * How many days before certificate expiry alerts start when the caller does
 * not know the server's `ssl_alert_days` - the server's own default.
 */
export const SSL_ATTENTION_DAYS = 14;

export interface AttentionOptions {
  /**
   * The server's ssl_alert_days (websites_overview.sslAlertDays). A fixed 14
   * here while cron alerted at 30 meant a mail about a certificate the
   * dashboard still called fine (CR-5).
   */
  sslAlertDays?: number | null;
}

const SEVERITY_RANK: Record<AttentionItem['severity'], number> = { down: 0, warning: 1, info: 2 };

/**
 * Builds the list for the "Needs attention" section.
 *
 * Returns ONLY real, currently valid problems derived from measured data -
 * no padding. An empty list is good news, not an error.
 *
 * One monitor can contribute several items (it runs, but the disk is filling
 * and its certificate expires too) - by design, every problem needs
 * its own row.
 */
export function buildNeedsAttention(
  monitors: ApiMonitor[],
  labels: AttentionLabels,
  options: AttentionOptions = {}
): AttentionItem[] {
  const items: AttentionItem[] = [];
  const sslLimit =
    typeof options.sslAlertDays === 'number' && options.sslAlertDays > 0 ? options.sslAlertDays : SSL_ATTENTION_DAYS;

  for (const m of monitors) {
    if (m.status === 'down') {
      items.push({
        key: `down-${m.id}`,
        monitorId: m.id,
        name: m.name,
        severity: 'down',
        text: labels.down,
      });
    } else if (m.status === 'warning') {
      items.push({
        key: `warn-${m.id}`,
        monitorId: m.id,
        name: m.name,
        severity: 'warning',
        text: labels.warning,
      });
    } else if (monitorStatusKey(m) === 'unknown_stale') {
      // The verdict above counts a silent agent as a problem; the list that
      // names the problems must name it too, or the first screen says
      // "1 agent mlčí" over "Nic nevyžaduje pozornost".
      items.push({
        key: `silent-${m.id}`,
        monitorId: m.id,
        name: m.name,
        severity: 'warning',
        text: labels.silent,
      });
    }

    if (m.unreachableTarget) {
      items.push({
        key: `unreach-${m.id}`,
        monitorId: m.id,
        name: m.name,
        severity: 'warning',
        text: labels.unreachable,
      });
    }

    const sslDays = m.details?.ssl_days_remaining;
    if (typeof sslDays === 'number' && sslDays <= sslLimit) {
      items.push({
        key: `ssl-${m.id}`,
        monitorId: m.id,
        name: m.name,
        severity: sslDays <= 0 ? 'down' : 'warning',
        text: sslDays <= 0 ? labels.sslExpired : labels.sslExpiring(sslDays),
      });
    }

    if (m.agentUpdateAvailable) {
      // An older agent still measures correctly: worth knowing, not a problem.
      items.push({
        key: `agent-${m.id}`,
        monitorId: m.id,
        name: m.name,
        severity: 'info',
        text: labels.agentUpdate(m.agentUpdateAvailable),
      });
    }

    // The limit the admin set on THIS monitor decides. A single hardcoded 90
    // meant a disk configured to warn at 70 stayed silent until 90, and a
    // monitor deliberately allowed to sit at 95 nagged every minute.
    for (const [metric, value, key] of [
      ['CPU', m.cpu, 'cpu'],
      ['RAM', m.ram, 'ram'],
      ['Disk', m.hdd, 'hdd'],
    ] as const) {
      // An unmeasured metric (null) never creates an alert.
      if (typeof value === 'number' && value >= thresholdFor(m, key)) {
        items.push({
          key: `${metric}-${m.id}`,
          monitorId: m.id,
          name: m.name,
          severity: 'warning',
          text: labels.metricHigh(metric, Math.round(value)),
        });
      }
    }
  }

  // Outages first, then warnings, then information; within a severity, by name.
  return items.sort((a, b) =>
    a.severity === b.severity ? a.name.localeCompare(b.name) : SEVERITY_RANK[a.severity] - SEVERITY_RANK[b.severity]
  );
}
