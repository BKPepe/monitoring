import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * The state chip of the NetPulse look: ONLINE, WARNING, LIVE, PRIMARY, AP.
 *
 * Uppercase and letter-spaced so it reads as a label, not as a sentence, on a
 * tint of its own colour (12 %, inside the 15 % the contrast test measures).
 * The words carry the state; the colour and the dot only repeat it. A short
 * word that means little on its own ("AP", "PRIMARY") gets `srLabel`, the
 * sentence a screen reader says instead ("Role: přístupový bod").
 *
 * Badge (ui/badge.tsx) stays for sentence-case chips inside running text.
 */
export type PillTone = 'up' | 'down' | 'warning' | 'info' | 'paused' | 'neutral' | 'primary';

const TONE: Record<PillTone, string> = {
  up: 'border-up/25 bg-up/12 text-up',
  down: 'border-down/25 bg-down/12 text-down',
  warning: 'border-warning/25 bg-warning/12 text-warning',
  info: 'border-info/25 bg-info/12 text-info',
  paused: 'border-paused/25 bg-paused/12 text-paused',
  neutral: 'border-border bg-inset text-muted-foreground',
  primary: 'border-primary/30 bg-primary/12 text-link',
};

export function Pill({
  tone = 'neutral',
  dot = false,
  pulse = false,
  srLabel,
  size = 'md',
  className,
  children,
  ...props
}: React.ComponentProps<'span'> & {
  tone?: PillTone;
  /** A dot before the word - for a live state. */
  dot?: boolean;
  /** The dot breathes; only for a state that is being measured right now (LIVE). */
  pulse?: boolean;
  /** What a screen reader says instead of the visible word. */
  srLabel?: string;
  size?: 'sm' | 'md';
}) {
  return (
    <span
      data-slot="pill"
      data-tone={tone}
      className={cn(
        'inline-flex shrink-0 items-center gap-1.5 rounded-full border font-semibold tracking-[0.08em] whitespace-nowrap uppercase',
        size === 'sm' ? 'px-1.5 py-px text-3xs' : 'px-2 py-0.5 text-2xs',
        TONE[tone],
        className
      )}
      {...props}
    >
      {dot && (
        <span
          aria-hidden="true"
          className={cn(
            'size-1.5 shrink-0 rounded-full bg-current',
            pulse && 'animate-pulse motion-reduce:animate-none'
          )}
        />
      )}
      {srLabel ? (
        <>
          <span aria-hidden="true">{children}</span>
          <span className="sr-only">{srLabel}</span>
        </>
      ) : (
        children
      )}
    </span>
  );
}

/**
 * The small count on a nav item, a tab or the bell. Nothing is drawn for 0 or
 * for an unknown count - a "0" would be a claim, and an unknown count is said
 * by the caller (the bell's error dot), not by a made-up number. `label` is
 * the sentence behind the number: "3 open incidents", not "3".
 */
export function CountBadge({
  count,
  label,
  tone = 'warning',
  max = 99,
  className,
}: {
  count: number | null | undefined;
  label: string;
  tone?: 'warning' | 'down' | 'neutral';
  max?: number;
  className?: string;
}) {
  if (count == null || !Number.isFinite(count) || count <= 0) return null;
  return (
    <span
      data-slot="count-badge"
      data-tone={tone}
      title={label}
      className={cn(
        'figure inline-grid h-4.5 min-w-4.5 shrink-0 place-items-center rounded-full px-1 text-3xs leading-none font-bold',
        tone === 'down' && 'bg-down text-down-foreground',
        tone === 'warning' && 'bg-warning text-warning-foreground',
        tone === 'neutral' && 'bg-secondary text-secondary-foreground',
        className
      )}
    >
      <span aria-hidden="true">{count > max ? `${max}+` : count}</span>
      <span className="sr-only">{label}</span>
    </span>
  );
}
