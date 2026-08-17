import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

/**
 * Status badge.
 *
 * Colour never carries meaning alone — every variant has a text label and
 * an optional dot. A colour-blind user still reads "Offline".
 */
const badgeVariants = cva(
  'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
  {
    variants: {
      variant: {
        up: 'border-up/30 bg-up/12 text-up',
        down: 'border-down/30 bg-down/12 text-down',
        warning: 'border-warning/30 bg-warning/12 text-warning',
        info: 'border-info/30 bg-info/12 text-info',
        paused: 'border-paused/30 bg-paused/12 text-paused',
        neutral: 'border-border bg-muted text-muted-foreground',
        primary: 'border-primary/30 bg-primary/12 text-primary',
      },
    },
    defaultVariants: {
      variant: 'neutral',
    },
  }
);

export interface BadgeProps extends React.ComponentProps<'span'>, VariantProps<typeof badgeVariants> {
  /** Shows a dot before the text — used for live monitor states. */
  dot?: boolean;
  /** The dot pulses. Only for states that genuinely change in real time. */
  pulse?: boolean;
}

export function Badge({ className, variant, dot, pulse, children, ...props }: BadgeProps) {
  return (
    <span className={cn(badgeVariants({ variant }), className)} {...props}>
      {dot && (
        <span
          aria-hidden="true"
          className={cn('size-1.5 shrink-0 rounded-full bg-current', pulse && 'animate-pulse')}
        />
      )}
      {children}
    </span>
  );
}

/** A standalone status dot for places where a badge does not fit (table rows). */
export function StatusDot({
  variant = 'neutral',
  className,
}: {
  variant?: NonNullable<BadgeProps['variant']>;
  className?: string;
}) {
  const color: Record<string, string> = {
    up: 'bg-up',
    down: 'bg-down',
    warning: 'bg-warning',
    info: 'bg-info',
    paused: 'bg-paused',
    primary: 'bg-primary',
    neutral: 'bg-muted-foreground',
  };
  return <span aria-hidden="true" className={cn('size-2 shrink-0 rounded-full', color[variant], className)} />;
}

export { badgeVariants };
