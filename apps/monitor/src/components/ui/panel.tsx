import * as React from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { SectionTitle } from './section-title';

// Literal class names: Tailwind only generates the utilities it finds spelled out.
const TONE_CLASS = { up: 'tone-up', warning: 'tone-warning', down: 'tone-down', info: 'tone-info' } as const;

/**
 * The card every page section sits in (NetPulse look): the card surface with
 * its top sheen and soft shadow, a heading row (icon tile, title, count, state
 * chip, actions, "Zobrazit vše →") and the body.
 *
 * A `tone` draws the edge of a panel that is in a state - the router in
 * warning among healthy ones - as a 1 px ring in the status colour with a halo
 * in the dark theme. The state itself must still be written inside (a pill, a
 * sentence): the edge only makes the card findable in a grid.
 *
 * With a title the panel is a labelled <section>, so a screen reader lists it
 * as a region by its heading; without one it is a plain box.
 */
export function Panel({
  title,
  icon,
  count,
  chip,
  hint,
  action,
  viewAll,
  tone,
  sheen = true,
  padding = 'md',
  headingLevel = 2,
  className,
  bodyClassName,
  children,
  ...props
}: Omit<React.HTMLAttributes<HTMLElement>, 'title'> & {
  title?: React.ReactNode;
  icon?: LucideIcon;
  count?: React.ReactNode;
  chip?: React.ReactNode;
  hint?: React.ReactNode;
  action?: React.ReactNode;
  viewAll?: { to: string; label?: string };
  /** A panel in a state; see the component comment. */
  tone?: 'up' | 'warning' | 'down' | 'info' | null;
  /** The light edge along the top; off for a panel nested in another. */
  sheen?: boolean;
  /** 'none' when the body is a list or a chart that runs to the edges. */
  padding?: 'none' | 'sm' | 'md';
  headingLevel?: 2 | 3;
  bodyClassName?: string;
}) {
  const headingId = React.useId();
  const hasHeader = title != null;
  const Root = hasHeader ? 'section' : 'div';
  return (
    <Root
      data-slot="panel"
      data-tone={tone ?? undefined}
      aria-labelledby={hasHeader ? headingId : undefined}
      className={cn(
        'bg-card text-card-foreground shadow-card flex min-w-0 flex-col rounded-xl border border-border',
        sheen && 'panel-sheen',
        tone && ['tone-edge', TONE_CLASS[tone]],
        className
      )}
      {...props}
    >
      {hasHeader && (
        <div className={cn('flex items-start gap-3', padding === 'sm' ? 'px-4 pt-3 pb-2' : 'px-5 pt-4 pb-3')}>
          <SectionTitle
            icon={icon}
            title={title}
            count={count}
            chip={chip}
            hint={hint}
            action={action}
            viewAll={viewAll}
            as={headingLevel === 3 ? 'h3' : 'h2'}
            headingId={headingId}
          />
        </div>
      )}
      <div
        className={cn(
          'min-w-0 flex-1',
          padding === 'md' && (hasHeader ? 'px-5 pb-5' : 'p-5'),
          padding === 'sm' && (hasHeader ? 'px-4 pb-4' : 'p-4'),
          bodyClassName
        )}
      >
        {children}
      </div>
    </Root>
  );
}
