import { cn } from '@/lib/utils';

/**
 * Prstencový graf rozložení stavů.
 *
 * Čisté SVG, žádná grafová knihovna — ECharts (Sprint 4) se hodí na časové
 * řady se zoomem a brushingem, ne na kruh se třemi segmenty. Nemá smysl
 * kvůli tomuhle tahat na dashboard megabajt JS.
 */
export interface HealthSegment {
  label: string;
  value: number;
  variant: 'up' | 'warning' | 'down' | 'paused';
}

const strokeClass: Record<HealthSegment['variant'], string> = {
  up: 'stroke-up',
  warning: 'stroke-warning',
  down: 'stroke-down',
  paused: 'stroke-paused',
};

const dotClass: Record<HealthSegment['variant'], string> = {
  up: 'bg-up',
  warning: 'bg-warning',
  down: 'bg-down',
  paused: 'bg-paused',
};

const RADIUS = 54;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

export function HealthDonut({
  segments,
  centerLabel,
  className,
}: {
  segments: HealthSegment[];
  /** Popisek uprostřed prstence — typicky podíl zdravých. */
  centerLabel: { value: string; caption: string };
  className?: string;
}) {
  const total = segments.reduce((sum, s) => sum + s.value, 0);

  // Offsety segmentů se skládají postupně; každý začíná tam, kde skončil
  // předchozí. Průběžný součet se počítá dopředu místo mutace proměnné
  // během renderu - render má být čistá funkce vstupů.
  const offsets = segments.reduce<number[]>((acc, _segment, i) => {
    const prevFraction = i === 0 ? 0 : total === 0 ? 0 : segments[i - 1].value / total;
    acc.push((acc[i - 1] ?? 0) + prevFraction);
    return acc;
  }, []);

  const arcs = segments.map((segment, i) => {
    const fraction = total === 0 ? 0 : segment.value / total;
    return {
      ...segment,
      fraction,
      dash: fraction * CIRCUMFERENCE,
      offset: -offsets[i] * CIRCUMFERENCE,
    };
  });

  return (
    <div className={cn('flex flex-wrap items-center justify-center gap-6', className)}>
      <div className="relative shrink-0">
        <svg viewBox="0 0 140 140" className="size-36 -rotate-90" role="presentation">
          <circle cx="70" cy="70" r={RADIUS} fill="none" strokeWidth="14" className="stroke-muted" />
          {arcs.map((arc) => (
            <circle
              key={arc.label}
              cx="70"
              cy="70"
              r={RADIUS}
              fill="none"
              strokeWidth="14"
              strokeLinecap="butt"
              strokeDasharray={`${arc.dash} ${CIRCUMFERENCE - arc.dash}`}
              strokeDashoffset={arc.offset}
              className={strokeClass[arc.variant]}
            />
          ))}
        </svg>

        <div className="pointer-events-none absolute inset-0 grid place-items-center text-center">
          <div>
            <p className="tabular text-2xl font-semibold">{centerLabel.value}</p>
            <p className="text-muted-foreground text-xs">{centerLabel.caption}</p>
          </div>
        </div>
      </div>

      {/* Legenda nese stejná čísla jako graf — je zároveň textovou
          alternativou pro čtečky, proto graf sám má role="presentation". */}
      <ul className="flex min-w-40 flex-col gap-2 text-sm">
        {arcs.map((arc) => (
          <li key={arc.label} className="flex items-center gap-2">
            <span className={cn('size-2.5 shrink-0 rounded-sm', dotClass[arc.variant])} />
            <span className="text-muted-foreground">{arc.label}</span>
            <span className="tabular ml-auto font-medium">{arc.value}</span>
            <span className="tabular text-muted-foreground w-12 text-right text-xs">
              {(arc.fraction * 100).toFixed(1)} %
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
