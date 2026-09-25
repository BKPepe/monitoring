import { cn } from '@/lib/utils';

/**
 * The time-range control above a page's charts: one segmented row of pills,
 * the same on the device detail and the metric detail. The two pages used to
 * draw it differently (outlined chips on one, a sunken track on the other),
 * so the same control looked like two different ones.
 *
 * It scopes everything below it - the page's charts, tiles and tables all
 * re-render against the chosen window - so it lives in the page header,
 * never inside a chart card.
 */
export function RangePills<T extends string>({
  value,
  options,
  onChange,
  label,
  titles,
}: {
  value: T;
  options: readonly T[];
  onChange: (value: T) => void;
  label: string;
  /** A spelled-out name per option ("Last 7 days") for the tooltip. */
  titles?: Partial<Record<T, string>>;
}) {
  return (
    <div
      role="group"
      aria-label={label}
      className="bg-secondary/60 border-border inline-flex flex-wrap items-center gap-0.5 rounded-lg border p-0.5"
    >
      {options.map((option) => (
        <button
          key={option}
          type="button"
          onClick={() => onChange(option)}
          aria-pressed={value === option}
          title={titles?.[option]}
          className={cn(
            'focus-visible:ring-ring rounded-md px-2.5 py-1 font-mono text-xs transition-colors focus-visible:ring-2 focus-visible:outline-none',
            value === option
              ? 'bg-card text-foreground font-semibold shadow-sm'
              : 'text-muted-foreground hover:text-foreground'
          )}
        >
          {option}
        </button>
      ))}
    </div>
  );
}
