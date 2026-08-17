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

/** Above this value the metric counts as critical. */
export const METRIC_ATTENTION_THRESHOLD = 90;
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

    for (const [metric, value] of [
      ['CPU', m.cpu],
      ['RAM', m.ram],
      ['Disk', m.hdd],
    ] as const) {
      // An unmeasured metric (null) never creates an alert.
      if (typeof value === 'number' && value >= METRIC_ATTENTION_THRESHOLD) {
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
