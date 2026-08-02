import type { SearchResult } from '@/components/ui/search-command';

export const appVersion = '0.1.0-stable';

export const currentUser = {
  name: 'Administrátor',
  role: 'Administrator',
};

export type MonitorStatus = 'up' | 'down' | 'warning' | 'paused' | 'maintenance';
export type InsightKind = 'anomaly' | 'prediction' | 'security' | 'trend';
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

export interface MonitorRow {
  id: number;
  name: string;
  kind: string;
  target: string;
  status: MonitorStatus;
  responseMs: number | null;
  cpu: number | null;
  ram: number | null;
  hdd?: number | null;
  uptimeSeconds: number | null;
  lastCheck: string;
  group: 'Production' | 'Development';
}

export const monitors: MonitorRow[] = [];

export interface OverviewMetrics {
  totalMonitors: number;
  healthyCount: number;
  warningCount: number;
  downCount: number;
  avgUptimePct: number;
  uptime30d: number;
  avgLatencyMs: number;
  lastUpdated: string;
}

export const overview: OverviewMetrics = {
  totalMonitors: 0,
  healthyCount: 0,
  warningCount: 0,
  downCount: 0,
  avgUptimePct: 100.0,
  uptime30d: 100.0,
  avgLatencyMs: 0,
  lastUpdated: new Date().toISOString(),
};

export interface Insight {
  id: number;
  kind: InsightKind;
  title: string;
  body: string;
  highlight: string;
  source: string;
  at: string;
}

export const insights: Insight[] = [];

export interface DayUptime {
  date: string;
  status: DayStatus;
  uptimePct: number;
  /** Skutečný popis dne z monitor_logs (počet selhaných kontrol, doba výpadku). */
  detail?: string;
}

export interface UptimeHistoryRow {
  monitorId: number;
  name: string;
  days: DayUptime[];
}

export const uptimeHistory: UptimeHistoryRow[] = [];

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

export const incidentHistory: TimelineEvent[] = [];

export const searchIndex: SearchResult[] = [
  { id: 'p-incidents', label: 'Incidenty a výpadky', group: 'Stránky', hint: '/incidents' },
  { id: 'p-infrastructure', label: 'Infrastruktura', group: 'Stránky', hint: '/infrastructure' },
  { id: 'p-reports', label: 'Výkazy a SLA', group: 'Stránky', hint: '/reports' },
  { id: 'p-settings', label: 'Nastavení', group: 'Stránky', hint: '/settings' },
];
