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

const now = Date.now();
const ago = (seconds: number) => new Date(now - seconds * 1000).toISOString();

export const monitors: MonitorRow[] = [
  {
    id: 1,
    name: 'BloodKings.eu',
    kind: 'WEB',
    target: 'https://bloodkings.eu',
    status: 'up',
    responseMs: 14,
    cpu: 10.45,
    ram: 4.59,
    hdd: 2.66,
    uptimeSeconds: 120 * 86400,
    lastCheck: ago(15),
    group: 'Production',
  },
  {
    id: 2,
    name: 'BloodKings.eu discord',
    kind: 'DISCORD',
    target: 'Guild ID: 3412270785...',
    status: 'up',
    responseMs: 18,
    cpu: null,
    ram: null,
    hdd: null,
    uptimeSeconds: 120 * 86400,
    lastCheck: ago(25),
    group: 'Production',
  },
  {
    id: 3,
    name: 'Donald',
    kind: 'TEAMSPEAK',
    target: 'donald.bloodkings.eu:8200',
    status: 'up',
    responseMs: 1035,
    cpu: 0.4,
    ram: 35.9,
    hdd: 36.0,
    uptimeSeconds: 12 * 86400 + 6 * 3600 + 40 * 60,
    lastCheck: ago(5),
    group: 'Production',
  },
  {
    id: 4,
    name: 'Minecraft',
    kind: 'MINECRAFT',
    target: 'khaki-viper-48887.zap.cloud:25565',
    status: 'down',
    responseMs: null,
    cpu: null,
    ram: null,
    hdd: null,
    uptimeSeconds: 0,
    lastCheck: ago(10),
    group: 'Production',
  },
  {
    id: 5,
    name: 'Router - Praha',
    kind: 'ROUTER',
    target: 'Turris - domov (cznic,turris1x)',
    status: 'up',
    responseMs: 8,
    cpu: 24.0,
    ram: 48.0,
    hdd: 3.0,
    uptimeSeconds: 59 * 86400 + 7 * 3600 + 31 * 60,
    lastCheck: ago(30),
    group: 'Production',
  },
  {
    id: 6,
    name: 'Schlehofer.eu',
    kind: 'WEB',
    target: 'https://schlehofer.eu',
    status: 'up',
    responseMs: 12,
    cpu: 10.45,
    ram: 4.59,
    hdd: 2.66,
    uptimeSeconds: 90 * 86400,
    lastCheck: ago(45),
    group: 'Production',
  },
];

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
  totalMonitors: 6,
  healthyCount: 5,
  warningCount: 0,
  downCount: 1,
  avgUptimePct: 98.27,
  uptime30d: 98.27,
  avgLatencyMs: 12,
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

export const insights: Insight[] = [
  {
    id: 1,
    kind: 'anomaly',
    title: 'Výpadek služby Minecraft',
    body: 'Minecraft server khaki-viper-48887.zap.cloud:25565 je podle API vypnutý.',
    highlight: 'OFFLINE',
    source: 'Minecraft',
    at: ago(60),
  },
  {
    id: 2,
    kind: 'prediction',
    title: 'Kapacita disků v pořádku',
    body: 'Všechny agenty vykazují bezpečnou rezervu diskového prostoru (využití do 36 %).',
    highlight: 'Bez rizika',
    source: 'Donald / Router - Praha',
    at: ago(120),
  },
  {
    id: 3,
    kind: 'security',
    title: 'Stav systémových agentů',
    body: 'TurrisOS 9.1.0 i Debian 12 (bookworm) mají nainstalované nejnovější verze agenta.',
    highlight: 'Aktuální',
    source: 'Router - Praha',
    at: ago(180),
  },
  {
    id: 4,
    kind: 'trend',
    title: 'Stabilní latence v síti',
    body: 'Průměrná odezva webů a rozhraní se pohybuje okolo',
    highlight: '12 ms',
    source: 'BloodKings.eu',
    at: ago(240),
  },
];

export interface DayUptime {
  date: string;
  status: DayStatus;
  uptimePct: number;
}

export interface UptimeHistoryRow {
  monitorId: number;
  name: string;
  days: DayUptime[];
}

export const uptimeHistory: UptimeHistoryRow[] = monitors.map((m) => {
  const days: DayUptime[] = [];
  for (let i = 29; i >= 0; i--) {
    const d = new Date(now - i * 86400 * 1000);
    const dateStr = d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' });
    const isTodayDown = m.status === 'down' && i === 0;
    days.push({
      date: dateStr,
      status: isTodayDown ? 'down' : 'up',
      uptimePct: isTodayDown ? 92.21 : 100.0,
    });
  }
  return { monitorId: m.id, name: m.name, days };
});

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

export const incidentHistory: TimelineEvent[] = [
  {
    id: 1,
    title: 'Minecraft server vypnut',
    detail: 'Minecraft server na khaki-viper-48887.zap.cloud:25565 neodpovídá na portu 25565.',
    at: 'Dnes 20:13',
    severity: 'down',
    resolution: 'Open',
  },
  {
    id: 2,
    title: 'Kontrola Router - Praha',
    detail: 'OpenWrt router Turris - domov hlásí stav 200 OK.',
    at: 'Dnes 20:10',
    severity: 'info',
    resolution: 'Info',
  },
  {
    id: 3,
    title: 'Kontrola Donald (TeamSpeak)',
    detail: 'TS3 query test 1035 ms vyhodnocen v pořádku.',
    at: 'Dnes 20:05',
    severity: 'info',
    resolution: 'Info',
  },
];

export const searchIndex: SearchResult[] = [
  { id: 'm-1', label: 'BloodKings.eu', group: 'Monitory', hint: 'https://bloodkings.eu' },
  { id: 'm-2', label: 'BloodKings.eu discord', group: 'Monitory', hint: 'Discord bot' },
  { id: 'm-3', label: 'Donald', group: 'Monitory', hint: 'donald.bloodkings.eu:8200' },
  { id: 'm-4', label: 'Minecraft', group: 'Monitory', hint: 'khaki-viper-48887.zap.cloud:25565' },
  { id: 'm-5', label: 'Router - Praha', group: 'Monitory', hint: 'Turris - domov' },
  { id: 'm-6', label: 'Schlehofer.eu', group: 'Monitory', hint: 'https://schlehofer.eu' },
  { id: 'p-incidents', label: 'Incidenty a výpadky', group: 'Stránky', hint: '/incidents' },
  { id: 'p-infrastructure', label: 'Infrastruktura', group: 'Stránky', hint: '/infrastructure' },
  { id: 'p-reports', label: 'Výkazy a SLA', group: 'Stránky', hint: '/reports' },
  { id: 'p-settings', label: 'Nastavení', group: 'Stránky', hint: '/settings' },
];
