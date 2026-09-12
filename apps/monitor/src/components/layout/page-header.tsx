import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * The block at the top of every page of the app: the title, one line under
 * it, and the page's actions on the right.
 *
 * Before this existed, each page wrote its own. Nine pages said the title in
 * 24 px bold and six in 20 px semibold, so navigating from the dashboard to
 * the infrastructure resized the heading - which reads as landing in a
 * different application. One component means the next page cannot drift.
 * The denser of the two sizes won: it matches the weight of card titles and
 * gives the fleet, not the heading, the room above the fold.
 */
export function PageHeader({
  title,
  subtitle,
  icon,
  actions,
  className,
  children,
}: {
  title: React.ReactNode;
  subtitle?: React.ReactNode;
  /** A small icon before the title - decorative, the title carries the meaning. */
  icon?: React.ReactNode;
  /** Buttons and controls that act on the whole page. */
  actions?: React.ReactNode;
  className?: string;
  /** Anything more the page needs under the subtitle. */
  children?: React.ReactNode;
}) {
  return (
    <div className={cn('flex flex-wrap items-end justify-between gap-3', className)}>
      <div className="min-w-0">
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          {icon}
          {title}
        </h1>
        {subtitle && <p className="text-muted-foreground text-sm">{subtitle}</p>}
        {children}
      </div>
      {actions}
    </div>
  );
}
