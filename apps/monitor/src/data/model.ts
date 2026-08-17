/**
 * Shared UI types and the static search index.
 *
 * The file used to be called mock.ts and carried development data - that is gone
 * (the app runs exclusively on the live API); what remains are the types shared
 * by several components, and the page list for search.
 */

export type MonitorStatus = 'up' | 'down' | 'warning' | 'paused' | 'maintenance';
export type DayStatus = 'up' | 'down' | 'warning' | 'paused' | 'maintenance';

export interface HealthMetric {
  key: string;
  label: string;
  value: string;
  tone?: 'latency' | 'cpu' | 'memory' | 'disk';
  caption?: string;
  delta?: { direction: 'up' | 'down'; value: string };
  goodDirection?: 'up' | 'down';
  series?: number[];
}

export interface DayUptime {
  date: string;
  status: DayStatus;
  /** null = no check ran that day (no invented 0 %). */
  uptimePct: number | null;
  /** The day's real description from monitor_logs (failed check count, outage duration). */
  detail?: string;
}

export interface UptimeHistoryRow {
  monitorId: number;
  name: string;
  days: DayUptime[];
}

export interface TimelineEvent {
  id: number;
  title: string;
  detail: string;
  at: string;
  severity: 'up' | 'down' | 'warning' | 'info';
  resolution?: 'Resolved' | 'Info' | 'Open';
  location?: string;
  method?: string;
  /** The measured latency of that particular check; null = unmeasured. */
  responseMs?: number | null;
}
