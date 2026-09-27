import * as React from 'react';
import { Link } from 'react-router';
import { Clock, Cpu, HardDrive, MemoryStick, Router as RouterIcon, Server, Thermometer, Users } from 'lucide-react';
import type { ApiMonitor } from '@/api/app-api';
import { HealthRing } from '@/components/health-ring';
import type { ScoredHealth } from '@/components/health-deductions';
import { Sparkline } from '@/components/sparkline';
import { breachTone, StatBlock } from '@/components/stat-block';
import { IconTile } from '@/components/ui/icon-tile';
import { Panel } from '@/components/ui/panel';
import { Pill, type PillTone } from '@/components/ui/pill';
import { useLanguage } from '@/context/language-context';
import { metricSeverity, thresholdFor } from '@/lib/attention';
import { windowFor } from '@/lib/chart-window';
import { formatDuration, formatNumber } from '@/lib/metric-format';
import { monitorStatusKey, statusLabel, statusMeta } from '@/lib/status';
import { monitorTypeLabel, normalizeMonitorType } from '@/lib/monitor-type';
import { socTemperatureC } from '@/lib/router-overview';
import type { SparkSample } from '@/lib/sparkline-segments';

/** The kinds that are a machine with an agent: they get a card with their own readings. */
const DEVICE_TYPES = ['openwrt', 'vps', 'cpanel'];

export function isDeviceMonitor(m: ApiMonitor): boolean {
  return DEVICE_TYPES.includes(normalizeMonitorType(m.type));
}

/** The card's edge follows the state the pill names; a healthy card has none. */
const EDGE = { down: 'down', warning: 'warning', info: 'info' } as const;

const PILL: Record<ReturnType<typeof statusMeta>['variant'], PillTone> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  info: 'info',
  paused: 'paused',
  neutral: 'neutral',
};

/** A positive count from the agent's report, or null: an absent field is never a zero. */
function countOf(v: unknown): number | null {
  return typeof v === 'number' && Number.isFinite(v) && v >= 0 ? v : null;
}

/**
 * "Routery a servery" on the dashboard: one card per router and server,
 * with its health ring, the four readings that say how it is doing, a 24-hour
 * CPU trace, how long it has been up and its state in words.
 *
 * Counts of clients only - never who they are (the agents deliberately send
 * no client names or addresses). A reading the device did not report is a
 * dash, and the ring is the server's score or "—", never a guess.
 */
export function DeviceCards({
  devices,
  health,
}: {
  devices: ApiMonitor[];
  /** action=health assets by monitor id; null while loading or after a failure. */
  health: Map<number, ScoredHealth> | null;
}) {
  const traces = useCpuTraces(devices);
  return (
    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label={undefined}>
      {devices.map((m) => (
        <li key={m.id} className="min-w-0">
          <DeviceCard
            monitor={m}
            health={health?.get(m.id) ?? null}
            trace={traces.points[m.id]}
            window={traces.window}
          />
        </li>
      ))}
    </ul>
  );
}

function DeviceCard({
  monitor: m,
  health,
  trace,
  window,
}: {
  monitor: ApiMonitor;
  health: ScoredHealth | null;
  trace: SparkSample[] | undefined;
  window: { from: number; to: number } | null;
}) {
  const { t, lang } = useLanguage();
  const d = m.details ?? {};
  const router = normalizeMonitorType(m.type) === 'openwrt';
  const key = monitorStatusKey(m);
  const meta = statusMeta(key);
  const edge = meta.tone in EDGE ? EDGE[meta.tone as keyof typeof EDGE] : null;
  const temp = socTemperatureC(d);
  // The Wi-Fi clients the router counts - a count, no identities. No fallback
  // to the DHCP leases: a lease count is a different fact under this label.
  const clients = countOf(d.wifi_clients_count);
  const uptime = typeof d.uptime === 'number' && d.uptime > 0 ? d.uptime : null;
  const model = typeof d.model === 'string' && d.model.trim() !== '' ? d.model.trim() : monitorTypeLabel(m.type, t);
  const pct = (v: number | null) => (v == null ? null : formatNumber(v, lang, 0));

  return (
    <Panel tone={edge} padding="sm" className="relative h-full" bodyClassName="flex h-full flex-col gap-3">
      <div className="flex items-start gap-3">
        <IconTile icon={router ? RouterIcon : Server} size="lg" />
        <div className="min-w-0 flex-1">
          {/* One link for the whole card: its ::after covers the card, so the
              card is one tab stop named by the device. */}
          <Link
            to={`/infrastructure/${m.id}`}
            className="focus-visible:ring-ring block truncate rounded-sm font-semibold after:absolute after:inset-0 after:rounded-xl after:content-[''] focus-visible:ring-2 focus-visible:outline-none"
          >
            {m.name}
          </Link>
          <p className="text-muted-foreground truncate text-xs">{model}</p>
        </div>
        <HealthRing
          score={health?.score ?? null}
          grade={health?.grade ?? null}
          size="sm"
          caption={t('dashboard.device_health', { name: m.name }, `Zdraví: ${m.name}`)}
        />
      </div>
      <div className="grid grid-cols-2 gap-2">
        <StatBlock
          size="xs"
          icon={Cpu}
          label="CPU"
          value={pct(m.cpu)}
          secondary="%"
          tone={breachTone(metricSeverity(m.cpu, thresholdFor(m, 'cpu')))}
        />
        <StatBlock
          size="xs"
          icon={MemoryStick}
          label="RAM"
          value={pct(m.ram)}
          secondary="%"
          tone={breachTone(metricSeverity(m.ram, thresholdFor(m, 'ram')))}
        />
        {router ? (
          <>
            <StatBlock
              size="xs"
              icon={Thermometer}
              label={t('dashboard.device_temp', 'Teplota')}
              value={temp == null ? null : formatNumber(temp, lang, 0)}
              secondary="°C"
            />
            <StatBlock
              size="xs"
              icon={Users}
              label={t('dashboard.device_clients', 'Wi-Fi klienti')}
              value={clients == null ? null : String(clients)}
            />
          </>
        ) : (
          <>
            <StatBlock
              size="xs"
              icon={HardDrive}
              label={t('dashboard.device_disk', 'Disk')}
              value={pct(m.hdd)}
              secondary="%"
              tone={breachTone(metricSeverity(m.hdd, thresholdFor(m, 'hdd')))}
            />
            <StatBlock
              size="xs"
              icon={Thermometer}
              label={t('dashboard.device_temp', 'Teplota')}
              value={temp == null ? null : formatNumber(temp, lang, 0)}
              secondary="°C"
            />
          </>
        )}
      </div>
      <div className="border-border mt-auto flex items-center gap-3 border-t pt-3">
        <div className="min-w-0 flex-1">
          {trace && trace.length >= 2 ? (
            <Sparkline points={trace} window={window} tone="cpu" unit="%" />
          ) : (
            <span className="text-muted-foreground text-2xs">{t('sparkline.too_few', 'Málo dat na průběh')}</span>
          )}
        </div>
        {uptime != null && (
          <span
            className="text-muted-foreground figure flex shrink-0 items-center gap-1 text-2xs"
            title={t('asset.device_uptime', 'Uptime zařízení')}
          >
            <Clock aria-hidden="true" className="size-3" />
            <span className="sr-only">{t('asset.device_uptime', 'Uptime zařízení')}: </span>
            {formatDuration(uptime, lang)}
          </span>
        )}
        <Pill tone={PILL[meta.variant]} dot size="sm">
          {statusLabel(key, t)}
        </Pill>
      </div>
    </Panel>
  );
}

/**
 * 24-hour CPU traces for the cards, one light request per device. Keyed on
 * the id list, not on the array: the dashboard's minute refresh builds a new
 * array every time and must not re-fire eight requests for a day-long trace.
 */
function useCpuTraces(devices: ApiMonitor[]) {
  const [traces, setTraces] = React.useState<{
    points: Record<number, SparkSample[]>;
    window: { from: number; to: number } | null;
  }>({ points: {}, window: null });
  const key = devices.map((m) => m.id).join(',');
  React.useEffect(() => {
    if (key === '') return;
    let active = true;
    const ids = key.split(',').map(Number);
    Promise.all(
      ids.map((id) =>
        fetch(`/status/api.php?action=metric_series&monitor_id=${id}&metric=cpu&period=24h`, { credentials: 'include' })
          .then((r) => (r.ok ? r.json() : null))
          .then(
            (data) =>
              [
                id,
                // Nulls kept: an hour the agent was silent is a gap in the trace.
                Array.isArray(data?.points)
                  ? (data.points as [number, number | null][]).map((p) => ({
                      t: p[0] * 1000,
                      v: typeof p[1] === 'number' ? p[1] : null,
                    }))
                  : [],
              ] as const
          )
          .catch(() => [id, [] as SparkSample[]] as const)
      )
    ).then((entries) => {
      if (!active) return;
      const points: Record<number, SparkSample[]> = {};
      for (const [id, vals] of entries) points[id] = vals;
      setTraces({ points, window: windowFor('24h', Date.now()) });
    });
    return () => {
      active = false;
    };
  }, [key]);
  return traces;
}
