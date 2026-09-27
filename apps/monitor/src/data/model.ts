/**
 * Shared UI types and the static search index.
 *
 * The file used to be called mock.ts and carried development data - that is gone
 * (the app runs exclusively on the live API); what remains are the types shared
 * by several components, and the page list for search.
 */

export type MonitorStatus = 'up' | 'down' | 'warning' | 'paused' | 'maintenance';
/**
 * One day of `action=daily_uptime` (C-7). 'nodata' = not one second measured;
 * 'partial' = nothing worse, but less than 90 % of the day was measured. The
 * server no longer sends 'paused' for an unmeasured day - that word belongs
 * to a monitor the owner switched off.
 */
export type DayStatus = 'up' | 'down' | 'warning' | 'maintenance' | 'partial' | 'nodata';

export interface DayUptime {
  date: string;
  /** The calendar day, 'Y-m-d'. */
  day?: string;
  status: DayStatus;
  /** (up + warning) / measured; null = nothing was measured (no invented 0 %). */
  uptimePct: number | null;
  /** The day's average response in ms; null until something answered. */
  avgMs?: number | null;
  /** Share of the day that was measured or in maintenance; null = unknown (a day rebuilt from check counts). */
  coveragePct?: number | null;
  measuredSecs?: number;
  expectedSecs?: number | null;
  downMin?: number;
  degradedMin?: number;
  maintenanceMin?: number;
  /** The server's sentence about the day, in the request's language. */
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
  /** Human-readable time, as the API formatted it. */
  at: string;
  /** The same moment machine-readable; null when the source did not send one. */
  atIso?: string | null;
  severity: 'up' | 'down' | 'warning' | 'info';
  resolution?: 'Resolved' | 'Info' | 'Open';
  /**
   * 'check' = one row of the check log (a routine pass is noise next to a
   * change); absent = a change, a note or an action. The collapsed timeline
   * (C-10) hides routine passes by default.
   */
  kind?: 'check';
  /** Which outage a check belongs to; a collapsed run never crosses it. */
  episode?: string | null;
  /**
   * The outage is running right now, as the data proves it (public page:
   * no recorded end and the monitor down now). Set on one row per outage, so
   * the "Probíhá" chip - the one thing a red dot cannot say - shows once.
   */
  ongoing?: boolean;
  location?: string;
  method?: string;
  /** The measured latency of that particular check; null = unmeasured. */
  responseMs?: number | null;
}
