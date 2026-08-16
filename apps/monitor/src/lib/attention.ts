import type { ApiMonitor } from '@/api/app-api';

export interface AttentionItem {
  key: string;
  assetId: number;
  name: string;
  severity: 'down' | 'warning';
  text: string;
}

/** Překlad hlášek - dashboard předává t(), testy jednoduchou náhradu. */
export interface AttentionLabels {
  down: string;
  warning: string;
  unreachable: string;
  sslExpired: string;
  sslExpiring: (days: number) => string;
  agentUpdate: (version: string) => string;
  metricHigh: (metric: string, value: number) => string;
}

/** Nad touhle hodnotou se metrika považuje za kritickou. */
export const METRIC_ATTENTION_THRESHOLD = 90;
/** Kolik dní před vypršením certifikátu se začne upozorňovat. */
export const SSL_ATTENTION_DAYS = 14;

/**
 * Sestaví seznam pro sekci „Vyžaduje pozornost".
 *
 * Vrací JEN skutečné, aktuálně platné problémy odvozené z naměřených dat -
 * žádné vycpávky. Prázdný seznam je dobrá zpráva, ne chyba.
 *
 * Jeden monitor může přispět víc položkami (běží, ale dochází disk a
 * zároveň mu vyprší certifikát) - to je záměr, každý problém potřebuje
 * vlastní řádek.
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
      // Nezměřená metrika (null) nikdy nezakládá upozornění.
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

  // Výpadky první, pak varování; v rámci závažnosti podle jména.
  return items.sort((a, b) =>
    a.severity === b.severity ? a.name.localeCompare(b.name) : a.severity === 'down' ? -1 : 1
  );
}
