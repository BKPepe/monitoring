import * as React from 'react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * The heading of a card or a page section: a lucide icon in a neutral chip,
 * the title, an optional count and a right-hand slot for actions.
 *
 * Section headings were written by hand per card - some with an icon, some
 * with an emoji, some bare, each with its own gap - so two cards side by side
 * never started at the same height. The chip stays neutral on purpose: on a
 * status-tinted card a tinted chip would be tint on tint, and the state is
 * carried by the words and the badges, not by the heading.
 */
export function SectionTitle({
  icon: Icon,
  title,
  count,
  hint,
  action,
  className,
}: {
  icon: LucideIcon;
  title: React.ReactNode;
  /** How many items the section holds; left out, nothing is drawn (never a guessed 0). */
  count?: React.ReactNode;
  /** One quiet line under the title. */
  hint?: React.ReactNode;
  /** Buttons, links or a freshness pill, aligned right. */
  action?: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex min-w-0 flex-1 flex-wrap items-center gap-3', className)}>
      <span className="bg-muted text-muted-foreground grid size-8 shrink-0 place-items-center rounded-lg">
        <Icon aria-hidden="true" className="size-4" />
      </span>
      <div className="min-w-0 flex-1">
        <h2 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
          {title}
          {count != null && (
            <span className="bg-muted text-muted-foreground rounded-full px-2 font-mono text-xs tabular-nums">
              {count}
            </span>
          )}
        </h2>
        {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
      </div>
      {action}
    </div>
  );
}
