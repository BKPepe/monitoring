import { useLanguage } from '@/context/language-context';
import { healthGradeOf, healthTone, splitHealthComponents, type HealthComponent, type HealthGrade } from '@/lib/health';
import { formatNumber } from '@/lib/metric-format';
import { cn } from '@/lib/utils';
import { Pill } from '@/components/ui/pill';

/**
 * The health score 0-100 as a ring (owner decision, NetPulse look): the
 * server's number in the middle, the arc as long as the score, coloured by the
 * grade band (good from 90 = up, fair from 70 = warning, below = down).
 *
 * Nothing here computes a score. `score` null - the server had too little
 * measured data - draws the empty track and a dash, never a guessed number
 * and never a green ring. The arc and its glow are decoration; the figure,
 * the grade word and the aria sentence carry the meaning.
 */
const TEXT: Record<ReturnType<typeof healthTone>, string> = {
  up: 'text-up',
  warning: 'text-warning',
  down: 'text-down',
  neutral: 'text-muted-foreground',
};
const BAR: Record<ReturnType<typeof healthTone>, string> = {
  up: 'bg-up',
  warning: 'bg-warning',
  down: 'bg-down',
  neutral: 'bg-muted-foreground',
};
const PILL: Record<ReturnType<typeof healthTone>, 'up' | 'warning' | 'down' | 'neutral'> = {
  up: 'up',
  warning: 'warning',
  down: 'down',
  neutral: 'neutral',
};

const SIZES = {
  // px of the square, stroke width, class of the figure
  sm: { px: 44, stroke: 5, figure: 'text-xs' },
  md: { px: 112, stroke: 9, figure: 'text-3xl' },
  lg: { px: 176, stroke: 12, figure: 'text-5xl' },
} as const;

export function useGradeLabel() {
  const { t } = useLanguage();
  return (grade: HealthGrade | null) =>
    grade === 'good'
      ? t('health.grade_good', 'Dobré')
      : grade === 'fair'
        ? t('health.grade_fair', 'Ucházející')
        : grade === 'poor'
          ? t('health.grade_poor', 'Slabé')
          : t('health.no_data', 'Nedostatek dat');
}

export function HealthRing({
  score,
  grade,
  size = 'md',
  caption,
  showGrade = size !== 'sm',
  className,
}: {
  /** The server's score; null = not enough data. */
  score: number | null | undefined;
  /** The server's grade; derived from the score by the same bands when left out. */
  grade?: HealthGrade | null;
  size?: 'sm' | 'md' | 'lg';
  /** What was scored - "Zdraví sítě", "Zdraví routeru". Also the start of the aria sentence. */
  caption?: string;
  /** The grade pill under the ring (off for 'sm'). */
  showGrade?: boolean;
  className?: string;
}) {
  const { t } = useLanguage();
  const gradeLabel = useGradeLabel();
  const known = score != null && Number.isFinite(score);
  const value = known ? Math.max(0, Math.min(100, Math.round(score as number))) : null;
  const g = known ? (grade ?? healthGradeOf(value)) : null;
  const tone = healthTone(g);
  const { px, stroke, figure } = SIZES[size];
  const r = (100 - stroke) / 2;
  const length = 2 * Math.PI * r;

  // One sentence for the whole ring: "Zdraví sítě: 92 ze 100, dobré".
  const name = caption ?? t('health.score', 'Skóre zdraví');
  const aria =
    value === null
      ? t('health.aria_none', { name }, `${name}: nedostatek dat`)
      : t('health.aria', { name, score: value, grade: gradeLabel(g) }, `${name}: ${value} ze 100, ${gradeLabel(g)}`);

  return (
    <div
      data-slot="health-ring"
      data-grade={g ?? 'none'}
      className={cn('inline-flex flex-col items-center gap-2', className)}
    >
      <div role="img" aria-label={aria} className="relative" style={{ width: px, height: px }}>
        <svg viewBox="0 0 100 100" className="size-full -rotate-90" aria-hidden="true">
          <circle cx="50" cy="50" r={r} fill="none" strokeWidth={stroke} className="stroke-border-strong" />
          {value !== null && value > 0 && (
            <circle
              cx="50"
              cy="50"
              r={r}
              fill="none"
              stroke="currentColor"
              strokeWidth={stroke}
              strokeLinecap="round"
              strokeDasharray={length}
              strokeDashoffset={length * (1 - value / 100)}
              className={cn('chart-glow transition-[stroke-dashoffset] duration-700 ease-out', TEXT[tone])}
            />
          )}
        </svg>
        <div aria-hidden="true" className="absolute inset-0 grid place-items-center">
          <div className="flex flex-col items-center leading-none">
            <span className={cn('figure font-semibold', figure, value === null && 'text-muted-foreground')}>
              {value === null ? '—' : value}
            </span>
            {size !== 'sm' && value !== null && (
              <span className="text-muted-foreground figure mt-1 text-2xs">/100</span>
            )}
          </div>
        </div>
      </div>
      {showGrade && (
        <Pill tone={PILL[tone]} dot={value !== null}>
          {gradeLabel(g)}
        </Pill>
      )}
      {caption && size !== 'sm' && <p className="text-muted-foreground text-xs">{caption}</p>}
    </div>
  );
}

/**
 * What the score is made of (owner decision: the point breakdown next to the
 * ring). `compact` is the one-line "Dostupnost 98 · Odezva 60" under a ring;
 * `bars` is one row per component, worst first, with a bar of its points and,
 * in the tooltip, its weight and what it took off the score. Components
 * nothing measured are named in one quiet line - they were left out of the
 * score, and saying so is the honest part.
 */
export function HealthBreakdown({
  components,
  variant = 'bars',
  className,
}: {
  components: readonly HealthComponent[];
  variant?: 'bars' | 'compact';
  className?: string;
}) {
  const { t, lang } = useLanguage();
  const { measured, unmeasured } = splitHealthComponents(components);
  const labelOf = (c: HealthComponent) => c.label ?? componentLabel(c.key, t);
  const unmeasuredLine = unmeasured.length > 0 && (
    <p className="text-muted-foreground text-2xs">
      {t(
        'health.unmeasured',
        { list: unmeasured.map(labelOf).join(', ') },
        `Neměřeno, do skóre nepočítáno: ${unmeasured.map(labelOf).join(', ')}`
      )}
    </p>
  );

  if (variant === 'compact') {
    return (
      <div className={cn('flex flex-col items-center gap-1', className)}>
        <ul
          aria-label={t('health.breakdown_label', 'Složky skóre')}
          className="flex flex-wrap justify-center gap-x-4 gap-y-1"
        >
          {measured.map((c) => {
            const tone = healthTone(healthGradeOf(c.points));
            return (
              <li key={c.key} className="flex items-center gap-1.5">
                <span className="micro-label">{labelOf(c)}</span>
                <span aria-hidden="true" className={cn('size-1.5 rounded-full', BAR[tone])} />
                <span className="figure text-xs">{c.points}</span>
              </li>
            );
          })}
        </ul>
        {unmeasuredLine}
      </div>
    );
  }

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <ul aria-label={t('health.breakdown_label', 'Složky skóre')} className="flex flex-col gap-1.5">
        {measured.map((c) => {
          const points = c.points as number;
          const tone = healthTone(healthGradeOf(points));
          const deduction = c.deduction != null && c.deduction > 0 ? formatNumber(c.deduction, lang, 1) : null;
          const title =
            deduction !== null
              ? t(
                  'health.row_title',
                  { weight: c.weight, deduction },
                  `Váha ${c.weight} ze 100 · ubírá ${deduction} b.`
                )
              : t('health.row_title_plain', { weight: c.weight }, `Váha ${c.weight} ze 100`);
          return (
            <li key={c.key} title={title} className="grid grid-cols-[minmax(0,7.5rem)_1fr_2.5rem] items-center gap-3">
              <span className="text-muted-foreground truncate text-right text-xs">{labelOf(c)}</span>
              <span aria-hidden="true" className="bg-inset h-1.5 overflow-hidden rounded-full">
                <span className={cn('block h-full rounded-full', BAR[tone])} style={{ width: `${points}%` }} />
              </span>
              <span className="figure text-right text-xs">
                {points}
                <span className="sr-only"> / 100. {title}</span>
              </span>
            </li>
          );
        })}
      </ul>
      {unmeasuredLine}
    </div>
  );
}

/** The dictionary name of a component, for an answer without the server's label. */
function componentLabel(key: string, t: ReturnType<typeof useLanguage>['t']): string {
  switch (key) {
    case 'availability':
      return t('health.c_availability', 'Dostupnost');
    case 'latency':
      return t('health.c_latency', 'Odezva');
    case 'alerts':
      return t('health.c_alerts', 'Upozornění');
    case 'freshness':
      return t('health.c_freshness', 'Čerstvost dat');
    case 'cpu_ram':
      return t('health.c_cpu_ram', 'CPU a RAM');
    case 'disk':
      return t('health.c_disk', 'Disky');
    case 'temperature':
      return t('health.c_temperature', 'Teploty');
    default:
      return key;
  }
}
