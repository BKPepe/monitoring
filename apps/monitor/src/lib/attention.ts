import type { ApiMonitor } from '@/api/app-api';

export interface AttentionItem {
  key: string;
  assetId: number;
  name: string;
  severity: 'down' | 'warning';
  text: string;
}

/** Message translation - the dashboard passes t(), tests a simple stand-in. */
export interface AttentionLabels {
  down: string;
  warning: string;
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
/** How many days before certificate expiry alerts start. */
export const SSL_ATTENTION_DAYS = 14;

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
export function buildNeedsAttention(monitors: ApiMonitor[], labels: AttentionLabels): AttentionItem[] {
  const items: AttentionItem[] = [];

  for (const m of monitors) {
    if (m.status === 'down') {
      items.push({ key: `down-${m.id}`, assetId: m.id, name: m.name, severity: 'down', text: labels.down });
    } else if (m.status === 'warning') {
      items.push({ key: `warn-${m.id}`, assetId: m.id, name: m.name, severity: 'warning', text: labels.warning });
    }

    if (m.unreachableTarget) {
      items.push({
        key: `unreach-${m.id}`,
        assetId: m.id,
        name: m.name,
        severity: 'warning',
        text: labels.unreachable,
      });
    }

    const sslDays = m.details?.ssl_days_remaining;
    if (typeof sslDays === 'number' && sslDays <= SSL_ATTENTION_DAYS) {
      items.push({
        key: `ssl-${m.id}`,
        assetId: m.id,
        name: m.name,
        severity: sslDays <= 0 ? 'down' : 'warning',
        text: sslDays <= 0 ? labels.sslExpired : labels.sslExpiring(sslDays),
      });
    }

    if (m.agentUpdateAvailable) {
      items.push({
        key: `agent-${m.id}`,
        assetId: m.id,
        name: m.name,
        severity: 'warning',
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
          assetId: m.id,
          name: m.name,
          severity: 'warning',
          text: labels.metricHigh(metric, Math.round(value)),
        });
      }
    }
  }

  // Outages first, then warnings; within a severity, by name.
  return items.sort((a, b) =>
    a.severity === b.severity ? a.name.localeCompare(b.name) : a.severity === 'down' ? -1 : 1
  );
}
