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
import { useSession } from '@/api/use-session';
import { Badge } from '@/components/ui/badge';
import { IconTile } from '@/components/ui/icon-tile';
import { KeyValueList, type KeyValueRow } from '@/components/ui/key-value';
import { Pill } from '@/components/ui/pill';
import { Sparkline } from '@/components/sparkline';
import { StatBlock, StatRow } from '@/components/stat-block';
import { UptimeStrip, type UptimeDay } from './uptime-strip';
import { useLanguage } from '@/context/language-context';
import { formatNumber } from '@/lib/metric-format';
import type { SparkSample } from '@/lib/sparkline-segments';
import { monitorStatusKey, statusLabel, statusMeta, type StatusKey } from '@/lib/status';
import { cn, formatPercent } from '@/lib/utils';
import { coverageStart, formatCoverageDay } from '@/lib/window-coverage';

export interface PublicMonitor {
  id: number;
  name: string;
  type: string;
  status: string;
  /** The shared status key (C-11); an older server does not send it and it is derived. */
  statusKey?: string | null;
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
  /** true = the agent has been silent past agent_offline_timeout; null = no verdict. */
  agentSilent?: boolean | null;
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
  /** The first day with data in the 90-day window (server-local "Y-m-d", W1-B2). */
  since?: string | null;
}

export function PublicMonitorCard({
  monitor,
  uptime,
  uptimePct,
  windows,
  windowStart90 = null,
  days = 30,
  statusOnly = false,
}: {
  monitor: PublicMonitor;
  /** The day strip; null = its answer is still on the way (the row is held for it). */
  uptime: UptimeDay[] | null;
  /** 30-day availability; null = unmeasured yet -> a dash. */
  uptimePct: number | null;
  windows?: UptimeWindows | null;
  /** The first calendar day of the 90-day window, as the server counted it. */
  windowStart90?: string | null;
  /** The period the page shows (the strip and the row's figure). */
  days?: 30 | 90;
  /** A page with detailLevel 'status': no expanding, just state and numbers. */
  statusOnly?: boolean;
}) {
  const { t, lang } = useLanguage();
  const { session } = useSession();
  const signedIn = !!session?.authenticated;
  const [open, setOpen] = React.useState(false);
  const detailId = React.useId();
  const d = (monitor.details ?? {}) as Record<string, any>;

  const rows = buildRows(monitor, d, t, lang);
  const statusKey = publicStatusKey(monitor);
  const meta = statusMeta(statusKey);
  // The row's figure follows the period on screen: "99,98 %" beside a 90-day
  // strip must be the 90-day share, not the 30-day one.
  const pct = days === 90 ? (windows?.d90 ?? null) : uptimePct;
  const pctLabel =
    days === 90 ? t('public.uptime_90d', 'Dostupnost 90 dní') : t('public.stat_uptime', 'Dostupnost 30 dní');
  const samples = React.useMemo(() => responseSamples(uptime ?? []), [uptime]);

  return (
    <li className="px-4 py-3.5 sm:px-5" data-status={statusKey}>
      {/* Hlavicka je DIV, ne button: jmeno je odkaz na plny detail a <a>
          uvnitr <button> je stejne neplatne HTML jako ty vnorene buttony,
          ktere odhalil prohlizec u pasu dostupnosti. Rozbaleni ma vlastni
          tlacitko - sipku. */}
      {/* One line per service on a desktop - name, day strip, figures - like
          the NetPulse lists; below lg the strip takes a full second line, so
          30 or 90 cells never squeeze the name or push the figures off a
          390 px screen. */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2.5 lg:flex-nowrap lg:gap-x-5">
        <div className="flex min-w-0 flex-1 items-center gap-3 lg:w-72 lg:flex-none">
          <IconTile icon={typeIcon(monitor.type)} size="sm" />
          <div className="min-w-0 flex-1">
            <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
              {/* The detail lives in the app, which needs a login and shows a user only
                  the monitors assigned to them - an anonymous visitor gets the name alone. */}
              {signedIn ? (
                <Link
                  to={`/infrastructure/${monitor.id}`}
                  className="text-link focus-visible:ring-ring min-w-0 truncate rounded-sm text-sm font-medium hover:underline focus-visible:ring-2 focus-visible:outline-none"
                  title={t('public.open_detail', 'Otevřít detail služby')}
                >
                  {monitor.name}
                </Link>
              ) : (
                <span className="min-w-0 truncate text-sm font-medium">{monitor.name}</span>
              )}
              {/* The one status vocabulary (C-11) as a word, not a lone dot.
                  Maintenance is blue like its day cells; "waiting for the
                  first data" is a dashed grey chip, not the amber of an agent
                  gone silent - one amber for both read as a problem the
                  visitor could not name. */}
              <Pill
                size="sm"
                dot
                tone={meta.variant}
                data-status={statusKey}
                className={cn(meta.dashed && 'border-dashed')}
              >
                {statusLabel(statusKey, t)}
              </Pill>
              {liveBadge(monitor, d, t)}
            </div>
            {/* Maintenance is unavailability too - just an announced one. The visitor should see
                WHY and until when, not only the blue chip. */}
            {monitor.status === 'maintenance' && (monitor.maintenanceDescription || monitor.maintenanceEnd) && (
              <p className="text-muted-foreground mt-0.5 flex items-center gap-1.5 text-xs">
                <Wrench aria-hidden="true" className="text-info size-3 shrink-0" />
                <span className="min-w-0">
                  {monitor.maintenanceDescription || t('public.maintenance', 'Údržba')}
                  {monitor.maintenanceEnd
                    ? ' ' +
                      t(
                        'public.maintenance_until',
                        { until: fmtWindowTime(monitor.maintenanceEnd) },
                        `(do ${fmtWindowTime(monitor.maintenanceEnd)})`
                      )
                    : ''}
                </span>
              </p>
            )}
          </div>
        </div>
        {uptime === null || uptime.length > 0 ? (
          <div className="order-last w-full min-w-0 lg:order-none lg:w-auto lg:flex-1">
            {uptime === null ? (
              // The strip's row is held while daily_uptime is on the way: every
              // card grew by a strip when it answered, and the whole indexed
              // page jumped under the visitor's eyes (PA-10, layout shift).
              <div aria-hidden="true" data-strip-pending="">
                <span className="bg-muted/60 block h-7 rounded-[2px] motion-safe:animate-pulse" />
                <span className="mt-1 block h-4" />
              </div>
            ) : (
              <UptimeStrip days={uptime} />
            )}
          </div>
        ) : (
          // No history (a new monitor, or a page without strips): the figures
          // stay in their column on a desktop.
          <span aria-hidden="true" className="hidden lg:block lg:flex-1" />
        )}
        {/* The daily response as a small glowing curve (the chart look of
            components/sparkline.tsx); decoration beside the figure, so a
            phone, where it would squeeze the name, leaves it out. */}
        <span className="hidden w-24 shrink-0 md:block" aria-hidden="true">
          <Sparkline points={samples.points} window={samples.window} tone="latency" unit="ms" />
        </span>
        <span className="flex shrink-0 items-center gap-3 text-right">
          <span
            className="figure text-muted-foreground hidden w-16 text-xs sm:inline"
            title={t('public.response', 'Odezva')}
          >
            <span className="sr-only">{t('public.response', 'Odezva')}: </span>
            {monitor.responseMs === null ? '—' : `${formatNumber(monitor.responseMs, lang, 0)} ms`}
          </span>
          {/* Colour only below a limit: 99 % already deserves attention, below 95 %
              is a problem - the thresholds of the legacy uptime-pct classes. */}
          <span
            title={pctLabel}
            className={cn('figure w-16 text-xs font-semibold', pct === null ? 'text-muted-foreground' : pctClass(pct))}
          >
            <span className="sr-only">{pctLabel}: </span>
            {formatPercent(pct, 2, lang)}
          </span>
          {!statusOnly && (
            <button
              type="button"
              onClick={() => setOpen((o) => !o)}
              aria-expanded={open}
              aria-controls={open ? detailId : undefined}
              aria-label={t('public.toggle_detail', 'Rozbalit detail')}
              className="text-muted-foreground hover:text-foreground hover:bg-raised focus-visible:ring-ring grid size-7 shrink-0 place-items-center rounded-md border border-border transition-colors focus-visible:ring-2 focus-visible:outline-none"
            >
              <ChevronDown
                aria-hidden="true"
                className={cn('size-4 transition-transform motion-reduce:transition-none', open && 'rotate-180')}
              />
            </button>
          )}
        </span>
      </div>

      {open && !statusOnly && (
        <div id={detailId} className="bg-inset mt-3 space-y-4 rounded-lg border border-border p-4">
          {/* Availability over several windows - like HetrixTools. An unmeasured window is
              a dash: a fresh monitor has no "100 % over 90 days". */}
          {windows && (
            <div className="space-y-1.5">
              <StatRow cols={4}>
                {(
                  [
                    ['d1', t('public.win_24h', '24 h')],
                    ['d7', t('public.win_7d', '7 dní')],
                    ['d30', t('public.win_30d', '30 dní')],
                    ['d90', t('public.win_90d', '90 dní')],
                  ] as const
                ).map(([key, label]) => (
                  <StatBlock
                    key={key}
                    variant="plain"
                    size="xs"
                    label={label}
                    // Through the floor-safe formatter in the page language: printed
                    // raw it read "99.983 %" on a Czech page, and a window with
                    // a failure in it could round up to 100.
                    value={windows[key] === null ? null : formatPercent(windows[key], 2, lang)}
                    tone={windows[key] === null ? null : pctTone(windows[key] as number)}
                  />
                ))}
              </StatRow>
              {coverageStart(windows.since, windowStart90) && (
                // A monitor younger than 90 days: the long windows cover only its history.
                <p className="text-muted-foreground text-2xs">
                  {t(
                    'public.win_since',
                    { date: formatCoverageDay(coverageStart(windows.since, windowStart90)!, lang) },
                    `Data od ${formatCoverageDay(coverageStart(windows.since, windowStart90)!, lang)}, delší okna pokrývají jen tuto dobu.`
                  )}
                </p>
              )}
            </div>
          )}
          <ResponseCurve samples={samples} days={days} />
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
                <p className="micro-label">{t('public.hosting_limits', 'Čerpání limitů hostingu')}</p>
                {bars.map(([k, label]) => (
                  <UsageBar key={k} label={label} percent={cp[k].percent} detail={cp[k].formatted} />
                ))}
              </div>
            );
          })()}
          {rows.length > 0 && <KeyValueList dense rows={rows} />}
          {/* Charts live in the app's metric detail; the link only appears
              where an agent actually reports metrics (cpu !== null), so it
              never leads into an empty page. Verified: monitors 4 and 5 have
              no agent and get no link. */}
          {monitor.cpu !== null && signedIn && (
            <Link
              to={`/infrastructure/${monitor.id}/metric/${monitor.id}/cpu`}
              className="text-link inline-flex items-center gap-1.5 text-xs font-medium hover:underline"
            >
              <LineChart aria-hidden="true" className="size-3.5" />
              {t('public.view_charts', 'Zobrazit grafy metrik')}
            </Link>
          )}
        </div>
      )}
    </li>
  );
}

/** The availability colour by the legacy thresholds; healthy stays the foreground. */
function pctClass(pct: number): string {
  return pct >= 99 ? '' : pct >= 95 ? 'text-warning' : 'text-down';
}

function pctTone(pct: number): 'warning' | 'down' | null {
  return pct >= 99 ? null : pct >= 95 ? 'warning' : 'down';
}

interface ResponseSamples {
  points: SparkSample[];
  window: { from: number; to: number } | null;
  measured: number[];
}

/**
 * The strip's days as a time series of their average response. x is the
 * middle of each day and the window runs to the end of the last one (today),
 * so a day nobody measured - today before its first check included - is a
 * gap, never a line drawn across it. An older server without the day key
 * gets the days spaced by their position.
 */
function responseSamples(days: readonly UptimeDay[]): ResponseSamples {
  const DAY = 86_400_000;
  const at = (day: UptimeDay, i: number) => {
    const t = day.day ? Date.parse(`${day.day}T12:00:00`) : NaN;
    return Number.isFinite(t) ? t : i * DAY;
  };
  const points = days.map((day, i) => ({
    t: at(day, i),
    v: typeof day.avgMs === 'number' && Number.isFinite(day.avgMs) ? day.avgMs : null,
  }));
  const measured = points.map((p) => p.v).filter((v): v is number => v !== null);
  const window = points.length > 0 ? { from: points[0].t - DAY / 2, to: points[points.length - 1].t + DAY / 2 } : null;
  return { points, window, measured };
}

/**
 * The daily response over the strip's period, larger in the expanded detail:
 * the curve, the best-worst day beside it and a visible caption, because a
 * tooltip does not exist on touch and an unexplained squiggle says nothing.
 * Fewer than two measured days draw nothing - a single point is no trend.
 */
function ResponseCurve({ samples, days }: { samples: ResponseSamples; days: 30 | 90 }) {
  const { t, lang } = useLanguage();
  if (samples.measured.length < 2) return null;
  const min = Math.min(...samples.measured);
  const max = Math.max(...samples.measured);
  const range =
    min === max ? `${formatNumber(max, lang, 0)} ms` : `${formatNumber(min, lang, 0)}–${formatNumber(max, lang, 0)} ms`;
  const label = days === 90 ? t('public.latency_90d', 'Odezva 90 dní') : t('public.latency_30d', 'Odezva 30 dní');
  return (
    <div className="space-y-1">
      <div className="flex items-baseline justify-between gap-3">
        <p className="micro-label">{label}</p>
        <p className="figure text-muted-foreground text-xs">{range}</p>
      </div>
      <Sparkline points={samples.points} window={samples.window} tone="latency" unit="ms" className="h-12" />
      <p className="text-muted-foreground text-2xs">
        {t(
          'public.latency_hint',
          'Každý bod je denní průměr odezvy; rozsah vpravo je nejlepší–nejhorší den. Mezera = den bez měření.'
        )}
      </p>
    </div>
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

/** The icon of a monitor type - also what a category heading on the public page shows. */
export function typeIcon(type: string): typeof Globe {
  return TYPE_ICONS[type] ?? Activity;
}

/**
 * The card's status key. An agent past its offline timeout keeps the last
 * status it reported, which is no longer known: the verdict above already
 * counts it as "unknown state", and a green dot next to that verdict
 * contradicted it. A worse status (down, maintenance) stays - it says more.
 */
function publicStatusKey(monitor: PublicMonitor): StatusKey {
  const key = monitorStatusKey(monitor);
  return monitor.agentSilent === true && (key === 'up' || key === 'warning') ? 'unknown_stale' : key;
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
  const { lang } = useLanguage();
  if (percent === null || percent === undefined || !Number.isFinite(percent)) return null;
  const clamped = Math.min(100, Math.max(0, percent));
  return (
    <div className="flex items-center gap-3 text-xs">
      <span className="micro-label w-20 shrink-0">{label}</span>
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
      <span className="figure shrink-0 text-right whitespace-nowrap" title={detail}>
        {detail ?? `${formatNumber(percent, lang, 1)} %`}
      </span>
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

function buildRows(
  monitor: PublicMonitor,
  d: Record<string, any>,
  t: (key: string, params?: Record<string, string | number> | string, fallback?: string) => string,
  lang: string
): KeyValueRow[] {
  const rows: KeyValueRow[] = [];
  const add = (label: string, value: unknown, mono = true) => {
    if (value === null || value === undefined || value === '') return;
    // Jen skalary. `members` z Discordu je POLE OBJEKTU a String() z nej
    // udela "[object Object],[object Object]" - presne to se ukazalo na
    // produkci. Objekt, ktery neumime zobrazit, radek proste nevytvori.
    if (typeof value === 'object') return;
    rows.push({ label, value: String(value), mono });
  };

  // Type-specific fields first - they are why someone expands the card.
  switch (monitor.type) {
    case 'minecraft':
      add(t('public.f_version', 'Verze'), d.version);
      add(t('public.f_motd', 'Popis (MOTD)'), d.motd, false);
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
      add(t('public.f_os', 'Systém'), d.os, false);
      // Radios: the agent reports an empty array on a router without wireless
      // hardware - that is an answer, not a gap, so no row appears.
      if (Array.isArray(d.wifi_radios) && d.wifi_radios.length > 0) {
        add('Wi-Fi', t('public.f_radios', { n: d.wifi_radios.length }, `${d.wifi_radios.length} rádia`));
      }
      break;
  }

  add(t('public.f_last_check', 'Poslední kontrola'), fmtStamp(monitor.lastCheck, lang));
  add(t('public.f_last_change', 'Poslední změna stavu'), fmtStamp(monitor.lastStatusChange, lang));
  return rows;
}

/**
 * A server timestamp ("2026-09-23 10:00:00" or ISO) in the page language and
 * the visitor's time zone, like the update time above; an unparseable value
 * is shown as sent rather than as "Invalid Date".
 */
function fmtStamp(v: string | null, lang: string): string | null {
  if (!v) return null;
  const at = new Date(v.includes('T') ? v : v.replace(' ', 'T'));
  return Number.isNaN(at.getTime())
    ? v
    : at.toLocaleString(lang === 'en' ? 'en-GB' : 'cs-CZ', { dateStyle: 'medium', timeStyle: 'short' });
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
