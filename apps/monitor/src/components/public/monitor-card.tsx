import * as React from 'react';
import {
  Activity,
  Wrench,
  ChevronDown,
  Gamepad2,
  Globe,
  Headphones,
  HeartPulse,
  LineChart,
  MessageCircle,
  Mic,
  Plug,
  Router,
  Server,
} from 'lucide-react';
import { Link } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { UptimeStrip, type UptimeDay } from './uptime-strip';
import { useLanguage } from '@/context/language-context';
import { cn } from '@/lib/utils';

export interface PublicMonitor {
  id: number;
  name: string;
  type: string;
  status: string;
  category: string | null;
  responseMs: number | null;
  lastCheck: string | null;
  lastStatusChange: string | null;
  details: Record<string, unknown> | null;
  assetId: number | null;
  /** null = the agent does not report CPU; this tells whether charts exist. */
  cpu: number | null;
  ram: number | null;
  hdd: number | null;
  /** Announced-maintenance flag - future windows too (status stays 'up' then). */
  maintenance?: boolean;
  maintenanceDescription?: string | null;
  maintenanceStart?: string | null;
  maintenanceEnd?: string | null;
}

/**
 * One service on the public page: status dot, name, uptime strip, and an
 * expandable detail with the fields that make sense for its type.
 *
 * The per-type fields mirror what the legacy page showed - Minecraft has a
 * version and MOTD, TeamSpeak its server process, the router its model and
 * radios. Only fields the API actually returned are rendered; a missing
 * value produces no row rather than a dash-filled skeleton, because on a
 * public page an empty grid reads as broken.
 */
/** Availability per window from action=uptime_windows; null = a window without measurements. */
export interface UptimeWindows {
  d1: number | null;
  d7: number | null;
  d30: number | null;
  d90: number | null;
}

export function PublicMonitorCard({
  monitor,
  uptime,
  uptimePct,
  windows,
  statusOnly = false,
}: {
  monitor: PublicMonitor;
  uptime: UptimeDay[];
  /** 30-day availability; null = unmeasured yet -> a dash. */
  uptimePct: number | null;
  windows?: UptimeWindows | null;
  /** A page with detailLevel 'status': no expanding, just state and numbers. */
  statusOnly?: boolean;
}) {
  const { t } = useLanguage();
  const [open, setOpen] = React.useState(false);
  const d = (monitor.details ?? {}) as Record<string, any>;

  const rows = buildRows(monitor, d, t);

  return (
    <li className="border-b border-border/50 py-3 last:border-0">
      {/* Hlavicka je DIV, ne button: jmeno je odkaz na plny detail a <a>
          uvnitr <button> je stejne neplatne HTML jako ty vnorene buttony,
          ktere odhalil prohlizec u pasu dostupnosti. Rozbaleni ma vlastni
          tlacitko - sipku. */}
      <div className="flex w-full flex-wrap items-center justify-between gap-3">
        <span className="flex min-w-0 items-center gap-2.5">
          <span
            className={cn(
              'size-2.5 shrink-0 rounded-full',
              monitor.status === 'up' ? 'bg-up' : monitor.status === 'down' ? 'bg-down' : 'bg-warning'
            )}
          />
          <TypeIcon type={monitor.type} />
          {monitor.assetId !== null ? (
            <Link
              to={`/infrastructure/${monitor.assetId}`}
              className="truncate text-sm font-medium hover:underline"
              title={t('public.open_detail', 'Otevřít detail služby')}
            >
              {monitor.name}
            </Link>
          ) : (
            <span className="truncate text-sm font-medium">{monitor.name}</span>
          )}
          {liveBadge(monitor, d, t)}
          {/* Maintenance is unavailability too - just an announced one. The visitor should see
              THAT it is down, WHY, and until when - not just an orange dot with no explanation. */}
          {monitor.status === 'maintenance' && (
            <Badge variant="warning">
              <Wrench className="mr-1 size-3" />
              {t('public.maintenance', 'Údržba')}
            </Badge>
          )}
        </span>
        <span className="flex items-center gap-3">
          {uptime.length > 0 && <UptimeStrip days={uptime} />}
          {/* Colour by level: below 99 % already deserves attention, below 95 % is
              a problem - the same thresholds as the legacy uptime-pct classes. */}
          <span
            className={cn(
              'w-16 text-right text-xs font-semibold tabular-nums',
              uptimePct === null
                ? 'text-muted-foreground'
                : uptimePct >= 99
                  ? 'text-up'
                  : uptimePct >= 95
                    ? 'text-warning'
                    : 'text-down'
            )}
          >
            {uptimePct === null ? '—' : `${uptimePct.toFixed(2)} %`}
          </span>
          <span className="text-muted-foreground w-14 text-right text-xs tabular-nums">
            {monitor.responseMs === null ? '—' : `${monitor.responseMs} ms`}
          </span>
          {!statusOnly && (
            <button
              type="button"
              onClick={() => setOpen((o) => !o)}
              aria-expanded={open}
              aria-label={t('public.toggle_detail', 'Rozbalit detail')}
              className="text-muted-foreground hover:text-foreground -m-1 p-1 transition-colors"
            >
              <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} />
            </button>
          )}
        </span>
      </div>

      {monitor.status === 'maintenance' && (monitor.maintenanceDescription || monitor.maintenanceEnd) && (
        <p className="text-warning mt-1.5 pl-5 text-xs">
          {monitor.maintenanceDescription || t('public.maintenance', 'Údržba')}
          {monitor.maintenanceEnd
            ? ' ' +
              t(
                'public.maintenance_until',
                { until: fmtWindowTime(monitor.maintenanceEnd) },
                `(do ${fmtWindowTime(monitor.maintenanceEnd)})`
              )
            : ''}
        </p>
      )}

      {open && !statusOnly && (
        <div className="mt-3 space-y-2.5 pl-5">
          {/* Availability over several windows - like HetrixTools. An unmeasured window is
              a dash: a fresh monitor has no "100 % over 90 days". */}
          {windows && (
            <div className="grid max-w-md grid-cols-4 gap-2">
              {(
                [
                  ['d1', t('public.win_24h', '24 h')],
                  ['d7', t('public.win_7d', '7 dní')],
                  ['d30', t('public.win_30d', '30 dní')],
                  ['d90', t('public.win_90d', '90 dní')],
                ] as const
              ).map(([key, label]) => (
                <div key={key} className="rounded-md border border-border/60 px-2 py-1.5 text-center">
                  <p className="text-muted-foreground text-[10px] font-medium">{label}</p>
                  <p
                    className={cn(
                      'text-xs font-semibold tabular-nums',
                      windows[key] === null
                        ? 'text-muted-foreground'
                        : windows[key]! >= 99
                          ? 'text-up'
                          : windows[key]! >= 95
                            ? 'text-warning'
                            : 'text-down'
                    )}
                  >
                    {windows[key] === null ? '—' : `${windows[key]} %`}
                  </p>
                </div>
              ))}
            </div>
          )}
          <LatencySparkline days={uptime} t={t} />
          {/* Server load - only where an agent measures. */}
          {(monitor.cpu !== null || monitor.ram !== null || monitor.hdd !== null) && (
            <div className="space-y-1.5">
              <UsageBar label="CPU" percent={monitor.cpu} />
              <UsageBar label="RAM" percent={monitor.ram} />
              <UsageBar label={t('public.disk', 'Disk')} percent={monitor.hdd} />
            </div>
          )}
          {/* Hosting limits usage (cPanel) - with the formatted value
              ("1.45 GB / 50 GB"), because with limits the percentage alone does not
              say how much room actually remains. */}
          {(() => {
            const cp = d.cpanel_stats;
            if (!cp || typeof cp !== 'object') return null;
            const bars = CPANEL_KEYS.filter(([k]) => cp[k] && typeof cp[k].percent === 'number');
            if (bars.length === 0) return null;
            return (
              <div className="space-y-1.5">
                <p className="text-muted-foreground text-[11px] font-medium">
                  {t('public.hosting_limits', 'Čerpání limitů hostingu')}
                </p>
                {bars.map(([k, label]) => (
                  <UsageBar key={k} label={label} percent={cp[k].percent} detail={cp[k].formatted} />
                ))}
              </div>
            );
          })()}
          {rows.length > 0 && (
            <dl className="grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
              {rows.map(([label, value]) => (
                <div key={label} className="flex items-baseline justify-between gap-3 text-xs">
                  <dt className="text-muted-foreground shrink-0">{label}</dt>
                  <dd className="truncate text-right font-medium">{value}</dd>
                </div>
              ))}
            </dl>
          )}
          {/* Charts live in the app's metric detail; the link only appears
              where an agent actually reports metrics (cpu !== null), so it
              never leads into an empty page. Verified: monitors 4 and 5 have
              no agent and get no link. */}
          {monitor.cpu !== null && monitor.assetId !== null && (
            <Link
              to={`/infrastructure/${monitor.assetId}/metric/${monitor.id}/cpu`}
              className="text-primary inline-flex items-center gap-1.5 text-xs font-medium hover:underline"
            >
              <LineChart className="size-3.5" />
              {t('public.view_charts', 'Zobrazit grafy metrik')}
            </Link>
          )}
        </div>
      )}
    </li>
  );
}

/**
 * Type icon - the legacy page had one per service (font-awesome) and it made
 * the list scannable. Same idea with the app's icon set; unknown types fall
 * back to a generic activity mark rather than nothing, so a new monitor type
 * never renders as a bare dot.
 */
const TYPE_ICONS: Record<string, typeof Globe> = {
  web: Globe,
  minecraft: Gamepad2,
  teamspeak: Mic,
  discord: MessageCircle,
  openwrt: Router,
  vps: Server,
  port: Plug,
  heartbeat: HeartPulse,
  agent_service: Activity,
};

function TypeIcon({ type }: { type: string }) {
  const Icon = TYPE_ICONS[type] ?? Activity;
  return <Icon className="text-muted-foreground size-4 shrink-0" aria-hidden="true" />;
}

/** The one live number worth showing collapsed - players online, people in voice. */
function liveBadge(
  monitor: PublicMonitor,
  d: Record<string, any>,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): React.ReactNode {
  if (monitor.type === 'minecraft' && d.players_online != null && d.players_max != null) {
    return (
      <Badge variant="info">
        {d.players_online} / {d.players_max}
      </Badge>
    );
  }
  if (monitor.type === 'discord' && d.presence_count != null) {
    return (
      <Badge variant="info">{t('public.discord_online', { n: d.presence_count }, `${d.presence_count} online`)}</Badge>
    );
  }
  // TeamSpeak: connected clients, same headset + "3 / 32" the legacy page had.
  if (monitor.type === 'teamspeak' && d.clients_online != null) {
    return (
      <Badge variant="info" title={t('public.ts_clients', 'Připojení klienti')}>
        <Headphones className="mr-1 size-3" />
        {d.clients_online}
        {d.clients_max != null ? ` / ${d.clients_max}` : ''}
      </Badge>
    );
  }
  return null;
}

/**
 * The legacy page's "mini chart": a horizontal usage bar with the value.
 * Colour by pressure - green under 70, amber under 90, red above - matching
 * the legacy chart-bar-fill classes. null renders nothing, never an empty bar
 * pretending to be a measured zero.
 */
function UsageBar({ label, percent, detail }: { label: string; percent: number | null; detail?: string }) {
  if (percent === null || percent === undefined || !Number.isFinite(percent)) return null;
  const clamped = Math.min(100, Math.max(0, percent));
  return (
    <div className="flex items-center gap-2 text-xs">
      <span className="text-muted-foreground w-20 shrink-0">{label}</span>
      <span className="bg-muted h-1.5 min-w-0 flex-1 overflow-hidden rounded-full">
        <span
          className={cn(
            'block h-full rounded-full',
            clamped >= 90 ? 'bg-down' : clamped >= 70 ? 'bg-warning' : 'bg-up'
          )}
          style={{ width: `${clamped}%` }}
        />
      </span>
      {/* nowrap: "39.01 GB / 124.4 GB" did not fit the fixed width and wrapped
          onto two lines - the value takes what it needs and the bar shrinks,
          not the legibility. */}
      <span className="shrink-0 text-right whitespace-nowrap tabular-nums" title={detail}>
        {detail ?? `${percent} %`}
      </span>
    </div>
  );
}

/**
 * Daily latency averages over 30 days as a tiny line - netdata-style charts belong
 * to the metric detail, this is just the shape of the trend. Unmeasured days tear
 * the line into segments; a continuous line across a gap would claim measurements that do not exist.
 */
function LatencySparkline({
  days,
  t,
}: {
  days: UptimeDay[];
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string;
}) {
  const vals = days.map((d) => (typeof d.avgMs === 'number' ? d.avgMs : null));
  const measured = vals.filter((v): v is number => v !== null);
  if (measured.length < 2) return null;
  const max = Math.max(...measured);
  const min = Math.min(...measured);
  const W = 160;
  const H = 28;
  const PAD = 2;
  const x = (i: number) => PAD + (i / Math.max(1, vals.length - 1)) * (W - 2 * PAD);
  const y = (v: number) => (max === min ? H / 2 : PAD + (1 - (v - min) / (max - min)) * (H - 2 * PAD));

  const segments: string[] = [];
  let current: string[] = [];
  vals.forEach((v, i) => {
    if (v === null) {
      if (current.length > 1) segments.push(current.join(' '));
      current = [];
      return;
    }
    current.push(`${x(i).toFixed(1)},${y(v).toFixed(1)}`);
  });
  if (current.length > 1) segments.push(current.join(' '));
  if (segments.length === 0) return null;

  return (
    <div className="space-y-0.5">
      <div className="flex items-center gap-2 text-xs">
        <span className="text-muted-foreground w-20 shrink-0">{t('public.latency_30d', 'Odezva 30 dní')}</span>
        <svg width={W} height={H} className="shrink-0" role="img" aria-label={t('public.latency_30d', 'Odezva 30 dní')}>
          {segments.map((pts, i) => (
            <polyline key={i} points={pts} fill="none" stroke="currentColor" strokeWidth="1.5" className="text-info" />
          ))}
        </svg>
        <span className="text-muted-foreground shrink-0 whitespace-nowrap tabular-nums">
          {min === max ? `${max} ms` : `${min}–${max} ms`}
        </span>
      </div>
      {/* A visible caption, not a title= tooltip - tooltips do not exist on touch
          (the same lesson as the availability strip). Without an explanation
          it is an anonymous squiggle: what is the axis, what does a gap mean? */}
      <p className="text-muted-foreground/70 pl-[5.5rem] text-[10px]">
        {t(
          'public.latency_hint',
          'Každý bod je denní průměr odezvy; rozsah vpravo je nejlepší–nejhorší den. Mezera = den bez měření.'
        )}
      </p>
    </div>
  );
}

/** cpanel_stats keys in the order the legacy page shows them. */
const CPANEL_KEYS: [string, string][] = [
  ['disk', 'Disk'],
  ['memory', 'RAM'],
  ['database', 'DB'],
  ['bandwidth', 'Přenos'],
  ['inodes', 'Inody'],
  ['processes', 'Procesy'],
  ['cpu', 'CPU'],
];

type Row = [string, string];

function buildRows(
  monitor: PublicMonitor,
  d: Record<string, any>,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): Row[] {
  const rows: Row[] = [];
  const add = (label: string, value: unknown) => {
    if (value === null || value === undefined || value === '') return;
    // Jen skalary. `members` z Discordu je POLE OBJEKTU a String() z nej
    // udela "[object Object],[object Object]" - presne to se ukazalo na
    // produkci. Objekt, ktery neumime zobrazit, radek proste nevytvori.
    if (typeof value === 'object') return;
    rows.push([label, String(value)]);
  };

  // Type-specific fields first - they are why someone expands the card.
  switch (monitor.type) {
    case 'minecraft':
      add(t('public.f_version', 'Verze'), d.version);
      add(t('public.f_motd', 'Popis (MOTD)'), d.motd);
      break;
    case 'teamspeak': {
      add(t('public.f_version', 'Verze'), d.version);
      const proc = d.ts3_process;
      if (proc && typeof proc === 'object') {
        if (proc.uptime_sec != null) {
          add(t('public.f_process_uptime', 'Uptime procesu'), formatDuration(Number(proc.uptime_sec), t));
        }
        if (proc.ram_mb != null) add('RAM', `${proc.ram_mb} MB`);
      }
      break;
    }
    case 'discord':
      // members je seznam online lidi (jmeno + stav), ne cislo.
      if (Array.isArray(d.members)) {
        add(t('public.f_members_online', 'Online členů'), d.members.length);
      }
      if (Array.isArray(d.voice_channels) && d.voice_channels.length > 0) {
        add(t('public.f_voice_channels', 'Hlasových kanálů'), d.voice_channels.length);
      }
      break;
    case 'openwrt':
      add(t('public.f_model', 'Model'), d.model);
      add(t('public.f_os', 'Systém'), d.os);
      // Radios: the agent reports an empty array on a router without wireless
      // hardware - that is an answer, not a gap, so no row appears.
      if (Array.isArray(d.wifi_radios) && d.wifi_radios.length > 0) {
        add('Wi-Fi', t('public.f_radios', { n: d.wifi_radios.length }, `${d.wifi_radios.length} rádia`));
      }
      break;
  }

  add(t('public.f_last_check', 'Poslední kontrola'), monitor.lastCheck);
  add(t('public.f_last_change', 'Poslední změna stavu'), monitor.lastStatusChange);
  return rows;
}

/** "2026-08-17 12:00:00" / ISO -> "2026-08-17 12:00" - drop seconds, keep both source formats. */
function fmtWindowTime(v: string): string {
  return v.replace('T', ' ').slice(0, 16);
}

function formatDuration(
  secs: number,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string
): string {
  const days = Math.floor(secs / 86400);
  const hours = Math.floor((secs % 86400) / 3600);
  if (days > 0) return t('public.duration_dh', { d: days, h: hours }, `${days} d ${hours} h`);
  const mins = Math.floor((secs % 3600) / 60);
  return t('public.duration_hm', { h: hours, m: mins }, `${hours} h ${mins} min`);
}
