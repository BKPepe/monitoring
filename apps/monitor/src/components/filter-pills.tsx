import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The filter row of a list page (NetPulse look): "Vše · Online 9 · Výpadek 1"
 * as one segmented track, or the device types as a row of chips.
 *
 * Every option is a toggle button (aria-pressed) inside a named group, so a
 * screen reader hears "Stav, skupina: Výpadek 1, stisknuto". The count after
 * a word is what the list holds in that state right now; an option with no
 * count prints none (never a guessed 0). The state colour of a dot only
 * repeats the word next to it.
 *
 * RangePills (charts/range-pills.tsx) stays the time-range control: its
 * options are short mono codes ("24h"), these are words with counts.
 */
export type FilterTone = 'up' | 'down' | 'warning' | 'info' | 'paused' | 'neutral';

export interface FilterOption<T extends string> {
  value: T;
  label: string;
  /** How many items are in this state; null/undefined = no count shown. */
  count?: number | null;
  /** A dot before the word in the state's colour. */
  tone?: FilterTone;
  icon?: LucideIcon;
}

const DOT: Record<FilterTone, string> = {
  up: 'bg-up',
  down: 'bg-down',
  warning: 'bg-warning',
  info: 'bg-info',
  paused: 'bg-paused',
  neutral: 'bg-muted-foreground',
};

export function FilterPills<T extends string>({
  label,
  value,
  options,
  onChange,
  variant = 'segmented',
  className,
}: {
  /** The group's name ("Stav", "Typ zařízení"). */
  label: string;
  value: T;
  options: readonly FilterOption<T>[];
  onChange: (value: T) => void;
  /** 'segmented' = one track (states); 'chips' = separate rounded chips (types, sources). */
  variant?: 'segmented' | 'chips';
  className?: string;
}) {
  const segmented = variant === 'segmented';
  return (
    <div
      role="group"
      aria-label={label}
      className={cn(
        // A phone keeps each group on one line that scrolls sideways inside
        // itself (the page never does); from sm the options wrap.
        'flex min-w-0 max-w-full items-center gap-1 overflow-x-auto sm:flex-wrap sm:overflow-visible',
        segmented && 'bg-inset w-fit rounded-lg border border-border p-0.5',
        !segmented && 'gap-1.5',
        className
      )}
    >
      {options.map((option) => {
        const active = option.value === value;
        const Icon = option.icon;
        return (
          <button
            key={option.value}
            type="button"
            aria-pressed={active}
            onClick={() => onChange(option.value)}
            className={cn(
              'focus-visible:ring-ring inline-flex shrink-0 items-center gap-1.5 text-xs font-medium whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:outline-none focus-visible:ring-inset',
              segmented ? 'rounded-md px-2.5 py-1' : 'rounded-full border px-3 py-1',
              // The pressed segment looks like the pressed range pill (one
              // look for one kind of control); a pressed chip takes the tint
              // of the selected nav item.
              segmented
                ? active
                  ? 'bg-card text-foreground font-semibold shadow-sm'
                  : 'text-muted-foreground hover:text-foreground'
                : active
                  ? 'border-primary/40 bg-primary/12 text-link'
                  : 'border-border text-muted-foreground hover:text-foreground hover:bg-raised'
            )}
          >
            {option.tone && (
              <span aria-hidden="true" className={cn('size-1.5 shrink-0 rounded-full', DOT[option.tone])} />
            )}
            {Icon && <Icon aria-hidden="true" className="size-3.5 shrink-0" />}
            {option.label}
            {option.count != null && (
              <span className={cn('figure text-2xs', active && !segmented ? 'text-link' : 'text-muted-foreground')}>
                {option.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
