import { cn } from '@/lib/utils';

/**
 * The two bars every card kept re-drawing by hand (C-9).
 *
 * Storage, the WAN bottleneck and the process list filled their bars with
 * the brand red - the colour the app otherwise keeps for "act now" - and the
 * CPU breakdown borrowed the status hues as categories, so "packet
 * processing" looked like a warning. Here:
 * - RangeMeter puts one value on a scale: a neutral fill (or a marker) with
 *   the limit as a tick, and colour only once the limit is crossed. A scale
 *   with zones (signal quality) tints the zones faintly instead.
 * - RatioBar splits one whole into parts with categorical hues that are
 *   never status colours, and names every part in its legend.
 */
const pctOf = (value: number, min: number, max: number) =>
  max === min ? 0 : Math.min(100, Math.max(0, ((value - min) / (max - min)) * 100));

const ZONE: Record<'good' | 'fair' | 'poor', string> = {
  good: 'bg-up/15',
  fair: 'bg-warning/20',
  poor: 'bg-down/15',
};

export interface MeterZone {
  from: number;
  to: number;
  level: 'good' | 'fair' | 'poor';
}

export function RangeMeter({
  min,
  max,
  value,
  range,
  mode = 'fill',
  zones,
  ticks,
  tone,
  label,
  valueText,
  className,
}: {
  min: number;
  max: number;
  /** The reading. null = not measured: the scale is drawn empty, never at zero. */
  value?: number | null;
  /** A span instead of a point, e.g. from the weakest to the typical client. */
  range?: readonly [number, number] | null;
  /** 'fill' grows from the start of the scale (usage); 'marker' places a tick (a reading on a scale). */
  mode?: 'fill' | 'marker';
  zones?: readonly MeterZone[];
  /** Limits worth seeing on the scale, e.g. the alert threshold. */
  ticks?: readonly { at: number; label: string }[];
  /** Set only when the value is past a limit: the fill takes the status colour. */
  tone?: 'warning' | 'down' | null;
  label: string;
  /** The reading as a person says it, for a screen reader ("-84 dBm"). */
  valueText?: string;
  className?: string;
}) {
  const v = typeof value === 'number' && Number.isFinite(value) ? value : null;
  return (
    <div
      // A meter needs one current value (aria-valuenow). A span from the
      // weakest to the typical client, or nothing measured, is not one: it is
      // a picture with its reading in the name instead (PA-4).
      {...(v !== null
        ? {
            role: 'meter',
            'aria-label': label,
            'aria-valuemin': min,
            'aria-valuemax': max,
            'aria-valuenow': v,
            'aria-valuetext': valueText,
          }
        : { role: 'img', 'aria-label': `${label}: ${valueText ?? '—'}` })}
      // The track is a tint of the text colour, not --muted: in the light
      // theme --muted equals the --inset well these meters sit in, and the
      // bar lost its scale there (V-02).
      className={cn('bg-foreground/10 relative h-1.5 w-full rounded-full', className)}
    >
      {zones?.map((z) => {
        const a = pctOf(Math.min(z.from, z.to), min, max);
        const b = pctOf(Math.max(z.from, z.to), min, max);
        return (
          <span
            key={`${z.level}-${z.from}`}
            className={cn('absolute inset-y-0', ZONE[z.level])}
            style={{ left: `${a}%`, width: `${b - a}%` }}
          />
        );
      })}
      {range && (
        <span
          data-slot="meter-range"
          className="bg-muted-foreground absolute inset-y-0 rounded-full"
          style={{
            left: `${pctOf(Math.min(...range), min, max)}%`,
            width: `${Math.max(1, pctOf(Math.max(...range), min, max) - pctOf(Math.min(...range), min, max))}%`,
          }}
        />
      )}
      {v !== null && mode === 'fill' && (
        <span
          data-slot="meter-fill"
          className={cn(
            'absolute inset-y-0 left-0 rounded-full',
            tone === 'down' ? 'bg-down' : tone === 'warning' ? 'bg-warning' : 'bg-muted-foreground'
          )}
          style={{ width: `${pctOf(v, min, max)}%` }}
        />
      )}
      {v !== null && mode === 'marker' && (
        <span
          data-slot="meter-marker"
          className="bg-foreground ring-background absolute -top-0.5 h-2.5 w-1 -translate-x-1/2 rounded-full ring-1"
          style={{ left: `${pctOf(v, min, max)}%` }}
        />
      )}
      {ticks?.map((tick) => (
        <span
          key={tick.at}
          title={tick.label}
          data-slot="meter-tick"
          className="bg-foreground absolute -top-0.5 h-2.5 w-px"
          style={{ left: `${pctOf(tick.at, min, max)}%` }}
        />
      ))}
    </div>
  );
}

// Categorical hues that are not status colours (decision 5.9): memory blue,
// network teal, disk violet, then neutral grey for "the rest".
const PART_FILL = ['bg-chart-memory', 'bg-chart-network', 'bg-chart-disk', 'bg-muted-foreground'];

export interface RatioPart {
  key: string;
  label: string;
  /** null = not measured: named in the legend with a dash, left out of the bar. */
  value: number | null;
}

export function RatioBar({
  parts,
  label,
  max,
  format = (v) => String(v),
  className,
}: {
  parts: readonly RatioPart[];
  label: string;
  /** The whole the parts are a share of; default their sum (a bar that is always full). */
  max?: number;
  format?: (value: number) => string;
  className?: string;
}) {
  const sum = parts.reduce((acc, p) => acc + (p.value ?? 0), 0);
  const whole = max ?? sum;
  const legend = parts.map((p) => `${p.label} ${p.value == null ? '—' : format(p.value)}`).join(', ');
  return (
    <div className={cn('space-y-1', className)}>
      <div
        role="img"
        aria-label={`${label}: ${legend}`}
        className="bg-foreground/10 flex h-2 w-full overflow-hidden rounded-full"
      >
        {whole > 0 &&
          parts.map((p, i) =>
            p.value == null || p.value <= 0 ? null : (
              <span
                key={p.key}
                data-part={p.key}
                title={`${p.label}: ${format(p.value)}`}
                className={cn('h-full', PART_FILL[Math.min(i, PART_FILL.length - 1)])}
                style={{ width: `${Math.min(100, (p.value / whole) * 100)}%` }}
              />
            )
          )}
      </div>
      <ul className="text-muted-foreground flex flex-wrap gap-x-3 gap-y-0.5 text-2xs">
        {parts.map((p, i) => (
          <li key={p.key} className="flex items-center gap-1 tabular-nums">
            <span
              aria-hidden="true"
              className={cn('size-2 shrink-0 rounded-sm', PART_FILL[Math.min(i, PART_FILL.length - 1)])}
            />
            {p.label} {p.value == null ? '—' : format(p.value)}
          </li>
        ))}
      </ul>
    </div>
  );
}
