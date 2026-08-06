/**
 * Sdílené UI typy a statický vyhledávací index.
 *
 * Soubor se dřív jmenoval mock.ts a nesl vývojová data - ta jsou pryč
 * (aplikace jede výhradně na živém API), zůstaly jen typy, které sdílí
 * víc komponent, a seznam stránek pro vyhledávání.
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
  /** null = pro ten den neproběhla žádná kontrola (žádné vymyšlené 0 %). */
  uptimePct: number | null;
  /** Skutečný popis dne z monitor_logs (počet selhaných kontrol, doba výpadku). */
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
}
