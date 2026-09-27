import { Link } from 'react-router';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import type { ApiMonitor } from '@/api/app-api';
import { HealthRing } from '@/components/health-ring';
import { RangeMeter } from '@/components/meter';
import { StatusDot } from '@/components/ui/badge';
import { IconTile } from '@/components/ui/icon-tile';
import { Pill } from '@/components/ui/pill';
import { Skeleton } from '@/components/ui/states';
import { useLanguage } from '@/context/language-context';
import { formatDuration, formatNumber } from '@/lib/metric-format';
import { statusLabel, statusMeta, type StatusKey } from '@/lib/status';
import { cn } from '@/lib/utils';
import type { FleetHealth } from './use-fleet-health';

/**
 * One device of the infrastructure list (NetPulse "Devices" card): the kind
 * icon, the name with its type and address, the state and how long it has
 * been in it, the health ring, and - from sm up - the load it reported.
 *
 * The whole card is ONE link to the device, so it is one tab stop and its
 * name is what it says. On a phone the same element is a row: the load
 * block is hidden and one figure stays next to the name; there is no second
 * copy of the device for another screen size.
 *
 * Honest data: a figure nobody measured is not drawn at all (a web check has
 * no CPU), a measured one that is missing now prints a dash, a bar is
 * coloured only past the monitor's own limit, and the ring is the server's
 * score or nothing - this card never computes one.
 */
export interface DeviceCardAsset {
  id: number;
  monitorId: number;
  name: string;
  type: string;
  hostname: string | null;
  statusKey: StatusKey;
}

type Figure = {
  key: 'response' | 'cpu' | 'ram' | 'hdd';
  label: string;
  value: number;
  text: string;
  /** The monitor's own limit (%), for the tick and the breach colour. */
  limit?: number | null;
};

function figuresOf(monitor: ApiMonitor | undefined, lang: string, responseLabel: string): Figure[] {
  if (!monitor) return [];
  const out: Figure[] = [];
  const num = (v: unknown) => (typeof v === 'number' && Number.isFinite(v) ? v : null);
  const response = num(monitor.responseMs);
  if (response !== null) {
    out.push({ key: 'response', label: responseLabel, value: response, text: `${formatNumber(response, lang, 0)} ms` });
  }
  const limits = monitor.effectiveThresholds;
  const load: [Figure['key'], string, number | null, number | null | undefined][] = [
    ['cpu', 'CPU', num(monitor.cpu), limits?.cpu],
    ['ram', 'RAM', num(monitor.ram), limits?.ram],
    ['hdd', 'Disk', num(monitor.hdd), limits?.hdd],
  ];
  for (const [key, label, value, limit] of load) {
    if (value === null) continue;
    out.push({ key, label, value, text: `${formatNumber(value, lang, 0)} %`, limit: limit ?? null });
  }
  return out;
}

const PILL_TONE = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  info: 'info',
  paused: 'paused',
  neutral: 'neutral',
} as const;

export function DeviceCard({
  asset,
  icon,
  typeLabel,
  since,
  monitor,
  health,
}: {
  asset: DeviceCardAsset;
  icon: LucideIcon;
  typeLabel: string;
  /** Seconds in the current state; null when the server does not know. */
  since: number | null;
  /** The monitor row the figures come from. */
  monitor: ApiMonitor | undefined;
  health: FleetHealth;
}) {
  const { t, lang } = useLanguage();
  const meta = statusMeta(asset.statusKey);
  const label = statusLabel(asset.statusKey, t);
  const edge = meta.variant === 'down' ? 'tone-down' : meta.variant === 'warning' ? 'tone-warning' : null;
  const figures = figuresOf(monitor, lang, t('common.response', 'Odezva'));
  const phoneFigure = figures[0] ?? null;
  const entry = health.status === 'ready' ? health.byMonitor.get(asset.monitorId) : undefined;

  return (
    <Link
      to={`/infrastructure/${asset.monitorId}`}
      data-slot="device-card"
      data-status={asset.statusKey}
      className={cn(
        'bg-card text-card-foreground shadow-card panel-sheen flex min-w-0 flex-col gap-3 rounded-xl border border-border p-3 transition-colors sm:p-4',
        'hover:border-border-strong focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none',
        edge && ['tone-edge', edge]
      )}
    >
      <div className="flex min-w-0 items-start gap-3">
        <IconTile icon={icon} className="sm:size-11 sm:rounded-xl sm:[&>svg]:size-5" />
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold">{asset.name}</p>
          <p className="text-muted-foreground truncate text-xs">
            {typeLabel}
            {asset.hostname ? ` · ${asset.hostname}` : ''}
          </p>
          <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
            {/* Online is the normal case, so a dot says it; every other state is
                worth reading and gets its word. */}
            {asset.statusKey === 'up' ? (
              <StatusDot variant="up" label={label} />
            ) : (
              <Pill tone={PILL_TONE[meta.variant]} dot size="sm" className={cn(meta.dashed && 'border-dashed')}>
                {label}
              </Pill>
            )}
            {/* How long it has been like this: the outage of a minute and the
                one of a week must not look alike. */}
            {since != null && (
              <span className="text-muted-foreground figure text-2xs whitespace-nowrap">
                {t('infra.in_state', { time: formatDuration(since, lang) }, `ve stavu ${formatDuration(since, lang)}`)}
              </span>
            )}
          </div>
        </div>
        {phoneFigure && (
          <span className="mt-0.5 flex shrink-0 flex-col items-end gap-0.5 sm:hidden">
            <span className="micro-label">{phoneFigure.label}</span>
            <span className="figure text-xs font-medium">{phoneFigure.text}</span>
          </span>
        )}
        {health.status === 'loading' ? (
          <Skeleton className="size-11 shrink-0 rounded-full" />
        ) : (
          entry &&
          !entry.paused && (
            <HealthRing
              size="sm"
              score={entry.score}
              grade={entry.grade}
              caption={t('infra.health_caption', { name: asset.name }, `Zdraví: ${asset.name}`)}
              className="shrink-0"
            />
          )
        )}
        <ChevronRight aria-hidden="true" className="text-muted-foreground mt-1 size-4 shrink-0 sm:hidden" />
      </div>

      {figures.length > 0 && (
        <div className="bg-inset hidden gap-2 rounded-lg border border-border px-3 py-2.5 sm:grid">
          {figures.map((f) => {
            const breached = f.limit != null && f.limit > 0 && f.value >= f.limit;
            return (
              <div key={f.key} className="grid grid-cols-[4.5rem_minmax(0,1fr)_4.5rem] items-center gap-3">
                <span className="micro-label">{f.label}</span>
                {f.key === 'response' ? (
                  <span />
                ) : (
                  <RangeMeter
                    min={0}
                    max={100}
                    value={f.value}
                    tone={breached ? 'warning' : null}
                    ticks={
                      f.limit != null && f.limit > 0
                        ? [{ at: f.limit, label: t('infra.limit_tick', { value: f.limit }, `limit ${f.limit} %`) }]
                        : undefined
                    }
                    label={f.label}
                    valueText={f.text}
                  />
                )}
                <span className={cn('figure text-right text-xs font-medium', breached && 'text-warning')}>
                  {f.text}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </Link>
  );
}
