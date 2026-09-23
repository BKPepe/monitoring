import { normalizeMonitorType } from '@/lib/monitor-type';

/**
 * What the Insights page lists about websites (W1-B6): a site that is down
 * and a certificate inside the alert window. Both read measured state only -
 * the monitor's current status and the certificate days the last check read.
 *
 * The page used to draw a green certificate-and-TLS card for every site
 * whatever the checks said. There is no "all fine" verdict here: an empty
 * list is only an empty list, and the page decides what it may say about it.
 */

interface MonitorLike {
  id: number;
  name: string;
  type?: string | null;
  target?: string | null;
  status?: string | null;
  sinceStatusChangeSeconds?: number | null;
  archivedAt?: string | null;
  details?: Record<string, unknown> | null;
}

export type WebsiteFinding =
  | { kind: 'down'; monitorId: number; name: string; sinceSeconds: number | null }
  | { kind: 'ssl_expired' | 'ssl_expiring'; monitorId: number; name: string; days: number; validTo: string | null };

export interface WebsiteFindings {
  findings: WebsiteFinding[];
  /** Websites that were looked at. */
  websites: number;
  /** HTTPS websites whose certificate the checks have not read yet. */
  sslUnread: number;
}

const WEB_TYPES = new Set(['http', 'https', 'web', 'website']);
const AGENT_TYPES = new Set(['agent', 'vps', 'node', 'openwrt', 'agent_service', 'probe']);

/** The same rule as the Websites page: an HTTP(S) check, never an agent that happens to have a URL. */
export function isWebsiteMonitor(m: Pick<MonitorLike, 'type' | 'target'>): boolean {
  const type = normalizeMonitorType(m.type);
  if (AGENT_TYPES.has(type)) return false;
  const target = (m.target ?? '').toLowerCase();
  return WEB_TYPES.has(type) || target.startsWith('http://') || target.startsWith('https://');
}

function sslDays(details: MonitorLike['details']): number | null {
  const days = details?.ssl_days_remaining;
  return typeof days === 'number' && Number.isFinite(days) ? Math.trunc(days) : null;
}

/**
 * @param alertDays the server's ssl_alert_days (cron's own limit for the
 *   "certificate expires" alert). null = unknown: then only a certificate that
 *   has expired or expires today is listed, because any other cut-off would be
 *   a guess.
 */
export function websiteFindings(monitors: MonitorLike[], alertDays: number | null): WebsiteFindings {
  const sites = monitors.filter((m) => !m.archivedAt && isWebsiteMonitor(m));
  const down: WebsiteFinding[] = [];
  const certs: Extract<WebsiteFinding, { days: number }>[] = [];
  let sslUnread = 0;

  for (const m of sites) {
    if (m.status === 'down') {
      down.push({ kind: 'down', monitorId: m.id, name: m.name, sinceSeconds: m.sinceStatusChangeSeconds ?? null });
    }
    const days = sslDays(m.details);
    if (days === null) {
      // A plain-HTTP site has no certificate to read; only an HTTPS one is missing it.
      if ((m.target ?? '').toLowerCase().startsWith('https://')) sslUnread++;
      continue;
    }
    const validTo = typeof m.details?.ssl_valid_to === 'string' ? m.details.ssl_valid_to : null;
    // The server floors the days left, so 0 is "expires today" and only a
    // negative count has expired - the same cut as cron's bk_ssl_alert_due().
    // Day 0 is inside any alert window, so it is listed even without one.
    if (days < 0) {
      certs.push({ kind: 'ssl_expired', monitorId: m.id, name: m.name, days, validTo });
    } else if (alertDays !== null ? days <= alertDays : days === 0) {
      certs.push({ kind: 'ssl_expiring', monitorId: m.id, name: m.name, days, validTo });
    }
  }

  certs.sort((a, b) => a.days - b.days || a.name.localeCompare(b.name));
  return { findings: [...down, ...certs], websites: sites.length, sslUnread };
}

/** The routers whose weekly recommendations belong on the page. */
export function routerMonitors<T extends MonitorLike>(monitors: T[]): T[] {
  return monitors.filter((m) => !m.archivedAt && normalizeMonitorType(m.type) === 'openwrt');
}
