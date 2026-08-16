import * as React from 'react';
import { ChevronDown, LineChart } from 'lucide-react';
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
  /** null = agent CPU nehlásí; podle toho se pozná, jestli existují grafy. */
  cpu: number | null;
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
export function PublicMonitorCard({ monitor, uptime }: { monitor: PublicMonitor; uptime: UptimeDay[] }) {
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
        </span>
        <span className="flex items-center gap-3">
          <UptimeStrip days={uptime} />
          <span className="text-muted-foreground w-14 text-right text-xs tabular-nums">
            {monitor.responseMs === null ? '—' : `${monitor.responseMs} ms`}
          </span>
          <button
            type="button"
            onClick={() => setOpen((o) => !o)}
            aria-expanded={open}
            aria-label={t('public.toggle_detail', 'Rozbalit detail')}
            className="text-muted-foreground hover:text-foreground -m-1 p-1 transition-colors"
          >
            <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} />
          </button>
        </span>
      </div>

      {open && (
        <div className="mt-3 space-y-2.5 pl-5">
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
  return null;
}

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
